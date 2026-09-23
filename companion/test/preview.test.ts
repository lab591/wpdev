import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { hookDeploy } from '../src/commands/deploy.js';
import { previewCommand } from '../src/commands/preview.js';
import { pullCommand } from '../src/commands/pull.js';
import { parseConfig } from '../src/config.js';
import type { Context } from '../src/context.js';
import { computeChanges } from '../src/deploy.js';
import { ApiClient } from '../src/http.js';
import { buildTools } from '../src/mcp/tools.js';
import { memoryOutput } from '../src/output.js';
import { State } from '../src/state.js';
import { startMockServer, text, type MockServer } from './helpers/mockServer.js';

const ROOT = 'wp-content/themes/child';
const noPhp = { runner: async () => ({ missing: true as const }) };
let mock: MockServer;
let dir: string;

function ctxFor(extra: Record<string, unknown> = {}): Context {
  const config = parseConfig({ site: mock.url, user: mock.site.user, writable: [ROOT], ...extra }, dir, { insecureLocal: true });
  return { config, client: new ApiClient({ siteUrl: config.siteUrl, user: mock.site.user, password: mock.site.password }) };
}
async function writeLocal(rel: string, content: string): Promise<void> {
  await mkdir(path.dirname(path.join(dir, rel)), { recursive: true });
  await writeFile(path.join(dir, rel), content);
}
const requests = (endpoint: string): number => mock.site.requests.filter((r) => r.path === endpoint).length;

beforeEach(async () => {
  mock = await startMockServer({ mode: 'write', files: new Map([[`${ROOT}/style.css`, text('body{}\n')]]) });
  dir = await mkdtemp(path.join(tmpdir(), 'wpdev-preview-'));
  expect(await pullCommand(ctxFor(), memoryOutput())).toBe(0);
});
afterEach(async () => {
  await mock.close();
  await rm(dir, { recursive: true, force: true });
});

describe('wpdev preview', () => {
  it('sends the changes to the preview only: live site and local state untouched', async () => {
    await writeLocal(`${ROOT}/style.css`, 'body{color:red}\n');
    const out = memoryOutput();
    expect(await previewCommand(ctxFor(), out, undefined, noPhp)).toBe(0);
    expect(out.lines.join('\n')).toMatch(/Apri: http:\/\/[^/]+\/\?devbridge_preview=b{64}/);
    expect(mock.site.lastDeploy).toBeUndefined();
    expect(new TextDecoder().decode(mock.site.files.get(`${ROOT}/style.css`))).toBe('body{}\n');
    const ctx = ctxFor();
    expect(await computeChanges(ctx.config, await State.load(ctx.config.stateDir))).toHaveLength(1);
  });

  it('publish puts the preview live and aligns the local state', async () => {
    await writeLocal(`${ROOT}/style.css`, 'body{color:red}\n');
    await previewCommand(ctxFor(), memoryOutput(), undefined, noPhp);
    const out = memoryOutput();
    expect(await previewCommand(ctxFor(), out, 'publish')).toBe(0);
    expect(out.lines[0]).toContain('release 20260923-111500-pub123');
    expect(new TextDecoder().decode(mock.site.files.get(`${ROOT}/style.css`))).toBe('body{color:red}\n');
    const ctx = ctxFor();
    expect(await computeChanges(ctx.config, await State.load(ctx.config.stateDir))).toEqual([]);
  });

  it('discard and status', async () => {
    await writeLocal(`${ROOT}/style.css`, 'x');
    await previewCommand(ctxFor(), memoryOutput(), undefined, noPhp);
    const st = memoryOutput();
    await previewCommand(ctxFor(), st, 'status');
    expect(st.lines.join('\n')).toContain(`write ${ROOT}/style.css`);
    expect(await previewCommand(ctxFor(), memoryOutput(), 'discard')).toBe(0);
    const after = memoryOutput();
    await previewCommand(ctxFor(), after, 'status');
    expect(after.lines[0]).toBe('Nessuna anteprima attiva.');
  });

  it('with deploy.target "preview" the Stop hook previews, and only when the changes differ', async () => {
    const ctx = () => ctxFor({ deploy: { target: 'preview' } });
    const lines: string[] = [];
    const streams = { stdout: (l: string) => lines.push(l), stderr: (l: string) => lines.push(l) };
    await writeLocal(`${ROOT}/style.css`, 'v1');
    expect(await hookDeploy(ctx, {}, streams, noPhp)).toBe(0);
    expect(requests('preview')).toBe(1);
    expect(lines.join('\n')).toContain('Anteprima pronta');
    expect(mock.site.lastDeploy).toBeUndefined();

    expect(await hookDeploy(ctx, {}, streams, noPhp)).toBe(0);
    expect(requests('preview')).toBe(1); // same changes: nothing sent again

    await writeLocal(`${ROOT}/style.css`, 'v2');
    expect(await hookDeploy(ctx, {}, streams, noPhp)).toBe(0);
    expect(requests('preview')).toBe(2);
  });

  it('MCP tools: preview returns the link, publish is refused on protected environments', async () => {
    await writeLocal(`${ROOT}/style.css`, 'x');
    const tools = Object.fromEntries(buildTools(() => ctxFor(), noPhp).map((t) => [t.name, t]));
    const r = await tools.preview!.handler({});
    expect(r.isError).toBeUndefined();
    expect(r.text).toContain('the live site is unchanged');
    expect(r.text).toMatch(/devbridge_preview=b{64}/);

    const protectedCtx = (): Context => {
      const config = parseConfig(
        { environments: { production: { site: mock.url, user: mock.site.user, autoDeploy: false } } },
        dir,
        { insecureLocal: true },
      );
      config.writable = [ROOT];
      return { config, client: new ApiClient({ siteUrl: config.siteUrl, user: mock.site.user, password: mock.site.password }) };
    };
    const prod = Object.fromEntries(buildTools(protectedCtx).map((t) => [t.name, t]));
    const refused = await prod.preview_publish!.handler({});
    expect(refused.isError).toBe(true);
    expect(refused.text).toContain('protected environment');
  });
});
