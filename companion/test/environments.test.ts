import { existsSync } from 'node:fs';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { hookDeploy } from '../src/commands/deploy.js';
import { pullCommand } from '../src/commands/pull.js';
import { parseConfig } from '../src/config.js';
import type { Context } from '../src/context.js';
import { computeChanges, runDeploy } from '../src/deploy.js';
import { ApiClient } from '../src/http.js';
import { buildTools } from '../src/mcp/tools.js';
import { memoryOutput } from '../src/output.js';
import { State } from '../src/state.js';
import { startMockServer, text, type MockServer } from './helpers/mockServer.js';

const ENVS = {
  environments: {
    staging: { site: 'https://staging.example.com', user: 'dev', passwordEnv: 'WPDEV_STAGING' },
    production: { site: 'https://www.example.com', user: 'dev', autoDeploy: false },
  },
};

describe('environments in wpdev.json', () => {
  const root = path.join(tmpdir(), 'wpdev-envs');
  afterEach(() => {
    delete process.env.WPDEV_ENV;
  });

  it('uses the first environment by default, with its own state folder', () => {
    const c = parseConfig(ENVS, root);
    expect(c.env).toBe('staging');
    expect(c.envs).toEqual(['staging', 'production']);
    expect(c.siteUrl).toBe('https://staging.example.com');
    expect(c.passwordEnv).toBe('WPDEV_STAGING');
    expect(c.autoDeploy).toBe(true);
    expect(c.stateDir).toBe(path.join(root, '.wpdev', 'env', 'staging'));
  });

  it('honours defaultEnv, WPDEV_ENV and --env (in this order of priority, lowest first)', () => {
    expect(parseConfig({ ...ENVS, defaultEnv: 'production' }, root).env).toBe('production');
    process.env.WPDEV_ENV = 'production';
    expect(parseConfig(ENVS, root).env).toBe('production');
    expect(parseConfig(ENVS, root, { env: 'staging' }).env).toBe('staging');
  });

  it('marks protected environments and falls back to the default password variable', () => {
    const c = parseConfig(ENVS, root, { env: 'production' });
    expect(c.autoDeploy).toBe(false);
    expect(c.passwordEnv).toBe('WPDEV_APP_PASSWORD');
  });

  it('rejects unknown environments, bad names and --env without environments', () => {
    expect(() => parseConfig(ENVS, root, { env: 'qa' })).toThrow('disponibili: staging, production');
    expect(() => parseConfig({ environments: { 'bad name': { site: 'https://x.com', user: 'u' } } }, root)).toThrow();
    expect(() => parseConfig({ site: 'https://x.com', user: 'u' }, root, { env: 'staging' })).toThrow('non definisce "environments"');
    expect(() => parseConfig({ user: 'u' }, root)).toThrow('servono "site" e "user"');
  });

  it('keeps the single-site layout unchanged', () => {
    const c = parseConfig({ site: 'https://x.com', user: 'u' }, root);
    expect(c.env).toBeNull();
    expect(c.stateDir).toBe(path.join(root, '.wpdev'));
  });
});

describe('deploying with environments', () => {
  const ROOT = 'wp-content/themes/child';
  let mock: MockServer;
  let dir: string;

  const ctxFor = (env: 'staging' | 'production'): Context => {
    const json = {
      environments: {
        staging: { site: mock.url, user: mock.site.user },
        production: { site: mock.url, user: mock.site.user, autoDeploy: false },
      },
    };
    const config = parseConfig(json, dir, { insecureLocal: true, env });
    config.writable = [ROOT];
    return { config, client: new ApiClient({ siteUrl: config.siteUrl, user: mock.site.user, password: mock.site.password }) };
  };
  const writeLocal = async (rel: string, content: string): Promise<void> => {
    await mkdir(path.dirname(path.join(dir, rel)), { recursive: true });
    await writeFile(path.join(dir, rel), content);
  };

  beforeEach(async () => {
    mock = await startMockServer({
      mode: 'write',
      files: new Map([
        [`${ROOT}/style.css`, text('body{}\n')],
        [`${ROOT}/functions.php`, text('<?php // f\n')],
        [`${ROOT}/only-on-server.php`, text('<?php // s\n')],
      ]),
    });
    dir = await mkdtemp(path.join(tmpdir(), 'wpdev-envdeploy-'));
  });
  afterEach(async () => {
    await mock.close();
    await rm(dir, { recursive: true, force: true });
  });

  it('keeps a separate state per environment', async () => {
    expect(await pullCommand(ctxFor('staging'), memoryOutput())).toBe(0);
    expect(existsSync(path.join(dir, '.wpdev', 'env', 'staging', 'state.json'))).toBe(true);
    expect(existsSync(path.join(dir, '.wpdev', 'env', 'production', 'state.json'))).toBe(false);
    expect(existsSync(path.join(dir, '.wpdev', 'state.json'))).toBe(false);
  });

  it('first deploy to a never-synced target: server hashes become the base, nothing server-only is deleted', async () => {
    // Work happened on staging: local files exist, production was never pulled.
    await writeLocal(`${ROOT}/style.css`, 'body{}\n'); // same as the server
    await writeLocal(`${ROOT}/functions.php`, '<?php // changed on staging\n');
    const prod = ctxFor('production');
    const state = await State.load(prod.config.stateDir);
    expect(state.isEmpty()).toBe(true);

    const out = await runDeploy(prod, { dryRun: true }, { runner: async () => ({ missing: true }) });
    expect(out.kind).toBe('dry_run');
    if (out.kind === 'dry_run') {
      expect(out.changes.map((c) => [c.status, c.p])).toEqual([['modified', `${ROOT}/functions.php`]]);
    }
    const seeded = await State.load(prod.config.stateDir);
    expect(seeded.get(`${ROOT}/only-on-server.php`)).toBeUndefined();
    expect(await computeChanges(prod.config, seeded)).toHaveLength(1);
  });

  it('the Stop hook never deploys to a protected environment', async () => {
    await writeLocal(`${ROOT}/style.css`, 'changed');
    const before = mock.site.requests.length;
    const lines: string[] = [];
    const code = await hookDeploy(() => ctxFor('production'), {}, { stdout: (l) => lines.push(l), stderr: (l) => lines.push(l) });
    expect(code).toBe(0);
    expect(lines).toEqual([]);
    expect(mock.site.requests.length).toBe(before);
    expect(mock.site.lastDeploy).toBeUndefined();
  });

  it('the MCP deploy tool refuses protected environments (dry run allowed)', async () => {
    const tools = Object.fromEntries(buildTools(() => ctxFor('production')).map((t) => [t.name, t]));
    const r = await tools.deploy!.handler({});
    expect(r.isError).toBe(true);
    expect(r.text).toContain('protected environment');
    expect(r.text).toContain('wpdev --env production deploy');
    expect(mock.site.lastDeploy).toBeUndefined();
  });
});
