import { existsSync } from 'node:fs';
import { mkdtemp } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { pullCommand } from '../src/commands/pull.js';
import { parseConfig } from '../src/config.js';
import type { Context } from '../src/context.js';
import { ApiClient } from '../src/http.js';
import { memoryOutput } from '../src/output.js';
import { loadRootsCache, resolveWritable, sanitizeServerRoots, saveRootsCache } from '../src/writable.js';
import { startMockServer, text, type MockServer } from './helpers/mockServer.js';

describe('sanitizeServerRoots', () => {
  it('keeps only well-formed folders below themes/plugins/mu-plugins', () => {
    expect(
      sanitizeServerRoots([
        'wp-content/themes/child',
        'wp-content\\plugins\\mine',
        'wp-content/themes/CHILD',
        'wp-content/themes',
        'wp-content/uploads/x',
        '../etc',
        'wp-content/themes/../../x',
        '/abs/wp-content/themes/x',
        'C:/wp-content/themes/x',
        42,
      ]),
    ).toEqual(['wp-content/themes/child', 'wp-content/plugins/mine']);
    expect(sanitizeServerRoots(undefined)).toEqual([]);
  });
});

describe('resolveWritable', () => {
  let mock: MockServer;
  let dir: string;

  beforeEach(async () => {
    mock = await startMockServer({ files: new Map([['wp-content/themes/child/style.css', text('body{}\n')]]) });
    dir = await mkdtemp(path.join(tmpdir(), 'wpdev-writable-'));
  });
  afterEach(async () => {
    await mock.close();
  });

  const ctxFor = (json: Record<string, unknown>): Context => {
    const config = parseConfig({ site: mock.url, user: mock.site.user, ...json }, dir, { insecureLocal: true });
    return { config, client: new ApiClient({ siteUrl: config.siteUrl, user: mock.site.user, password: mock.site.password }) };
  };
  const statusCalls = (): number => mock.site.requests.filter((r) => r.path === 'status').length;

  it('an empty or missing writable list means "the folders of the site"', async () => {
    expect(ctxFor({}).config.writableFromSite).toBe(true);
    expect(ctxFor({ writable: [] }).config.writableFromSite).toBe(true);
    const ctx = ctxFor({});
    await resolveWritable(ctx);
    expect(ctx.config.writable).toEqual(['wp-content/themes/child']);
    expect(await loadRootsCache(dir)).toEqual(['wp-content/themes/child']);
  });

  it('a list in wpdev.json restricts the project and is never replaced', async () => {
    const ctx = ctxFor({ writable: ['wp-content/plugins/mine'] });
    await resolveWritable(ctx);
    expect(ctx.config.writable).toEqual(['wp-content/plugins/mine']);
    expect(statusCalls()).toBe(0);
  });

  it('offline mode uses the cache without network calls (Stop hook without changes)', async () => {
    await saveRootsCache(dir, ['wp-content/themes/cached']);
    const ctx = ctxFor({});
    await resolveWritable(ctx, { offline: true });
    expect(ctx.config.writable).toEqual(['wp-content/themes/cached']);
    expect(statusCalls()).toBe(0);
  });

  it('falls back to the cache when the site is off or unreachable', async () => {
    await saveRootsCache(dir, ['wp-content/themes/cached']);
    mock.site.mode = 'off';
    const ctx = ctxFor({});
    await resolveWritable(ctx);
    expect(ctx.config.writable).toEqual(['wp-content/themes/cached']);
    const dead = parseConfig({ site: 'http://localhost:1', user: 'u' }, dir, { insecureLocal: true });
    const deadCtx: Context = { config: dead, client: new ApiClient({ siteUrl: dead.siteUrl, user: 'u', password: 'p' }) };
    await resolveWritable(deadCtx);
    expect(deadCtx.config.writable).toEqual(['wp-content/themes/cached']);
  });

  it('pull creates locally the folders still empty on the site (e.g. a new plugin)', async () => {
    mock.site.writableRoots = ['wp-content/themes/child', 'wp-content/plugins/nuovo'];
    const ctx = ctxFor({});
    await resolveWritable(ctx);
    expect(await pullCommand(ctx, memoryOutput())).toBe(0);
    expect(existsSync(path.join(dir, 'wp-content', 'plugins', 'nuovo'))).toBe(true);
  });

  it('pull downloads the folders enabled on the site', async () => {
    const ctx = ctxFor({});
    await resolveWritable(ctx);
    const out = memoryOutput();
    expect(await pullCommand(ctx, out)).toBe(0);
    expect(out.lines[0]).toContain('Scaricati 1 file');
    mock.site.writableRoots = [];
    await resolveWritable(ctx);
    const out2 = memoryOutput();
    expect(await pullCommand(ctx, out2)).toBe(1);
    expect(out2.warnings[0]).toContain('pannello Dev Bridge');
  });
});
