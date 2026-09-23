import { existsSync } from 'node:fs';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { clearCache, componentOf, headerValue, ReadCache, sliceRead, splitLines } from '../src/cache.js';
import { cacheClearCommand } from '../src/commands/cache.js';
import { parseConfig, type Config } from '../src/config.js';
import type { Context } from '../src/context.js';
import { xxh128 } from '../src/hash.js';
import { ApiClient } from '../src/http.js';
import { formatRead } from '../src/mcp/format.js';
import { buildTools } from '../src/mcp/tools.js';
import { memoryOutput } from '../src/output.js';
import { startMockServer, text, type MockServer } from './helpers/mockServer.js';

const PLUGIN = 'wp-content/plugins/shop';
const MAIN = `${PLUGIN}/shop.php`;
const FILE = `${PLUGIN}/includes/cart.php`;
const THEME_CSS = 'wp-content/themes/parent/style.css';
const THEME_FILE = 'wp-content/themes/parent/functions.php';
const CORE = 'wp-includes/load.php';

let mock: MockServer;
let dir: string;
let clock: number;
let client: ApiClient;

function mainFile(version: string): Uint8Array {
  return text(`<?php\n/**\n * Plugin Name: Shop\n * Version: ${version}\n */\n`);
}

function cache(overrides: Partial<{ enabled: boolean; trustWindowSec: number }> = {}): ReadCache {
  return new ReadCache(dir, client, { enabled: true, trustWindowSec: 60, writable: ['wp-content/themes/child'], now: () => clock, ...overrides });
}

/** Requests to /read (and /list) since the last reset. */
function reads(): { path: string; body: unknown }[] {
  return mock.site.requests.filter((r) => r.path === 'read' || r.path === 'list');
}

function resetRequests(): void {
  mock.site.requests.length = 0;
}

beforeEach(async () => {
  mock = await startMockServer({
    files: new Map([
      [MAIN, mainFile('1.0.0')],
      [FILE, text('<?php\nfunction cart() {\n\treturn 1;\n}\n')],
      [THEME_CSS, text('/*\nTheme Name: Parent\nVersion: 2.1\n*/\n')],
      [THEME_FILE, text('<?php\r\n// crlf\r\necho 1;')],
      [CORE, text('<?php\n// core\n')],
      ['wp-content/themes/child/functions.php', text('<?php // writable\n')],
    ]),
  });
  dir = await mkdtemp(path.join(tmpdir(), 'wpdev-cache-'));
  clock = 1_000_000;
  client = new ApiClient({ siteUrl: mock.url, user: mock.site.user, password: mock.site.password });
});

afterEach(async () => {
  await mock.close();
  await rm(dir, { recursive: true, force: true });
});

describe('helpers', () => {
  it('splits lines like PHP fgets and slices ranges like the server', () => {
    expect(splitLines('a\r\nb\nc')).toEqual(['a\r\n', 'b\n', 'c']);
    expect(splitLines('')).toEqual([]);
    const meta = { s: 1, m: 2, h: 'x' };
    expect(sliceRead('a\nb\nc\n', meta, 2, 3)).toMatchObject({ from: 2, to: 3, total_lines: 3, content: 'b\nc\n', truncated: false });
    expect(sliceRead('a\nb\n', meta, 1, 99)).toMatchObject({ to: 2, content: 'a\nb\n' });
    expect(sliceRead('', meta)).toMatchObject({ from: 1, to: 0, total_lines: 0, content: '' });
    expect(() => sliceRead('a\n', meta, 5)).toThrow(/total_lines/);
  });

  it('finds components and header values', () => {
    expect(componentOf(`${PLUGIN}/a/b.php`)).toBe(PLUGIN);
    expect(componentOf('wp-content/themes/x/style.css')).toBe('wp-content/themes/x');
    expect(componentOf(PLUGIN)).toBeNull();
    expect(componentOf(CORE)).toBeNull();
    expect(headerValue(' * Version: 3.4.1 */', 'Version')).toBe('3.4.1');
    expect(headerValue('<?php\n/* Plugin Name: X */', 'Plugin Name')).toBe('X');
    expect(headerValue('no header', 'Version')).toBeNull();
  });
});

describe('ReadCache', () => {
  it('serves hits inside the trust window without any request', async () => {
    const c = cache();
    const first = await c.read(FILE);
    expect(first.content).toBe('<?php\nfunction cart() {\n\treturn 1;\n}\n');
    resetRequests();

    clock += 30;
    const again = await cache().read(FILE, 2, 3);
    expect(again).toMatchObject({ from: 2, to: 3, total_lines: 4, content: 'function cart() {\n\treturn 1;\n', cached: true });
    expect(reads()).toEqual([]);
  });

  it('first access reads the plugin main file for the version, then the whole file', async () => {
    await cache().read(FILE, 2, 2);
    expect(reads().map((r) => r.body)).toEqual([{ path: MAIN }, { path: FILE }]);
  });

  it('after the trust window validates with known and handles "unchanged"', async () => {
    await cache().read(CORE);
    const h = await xxh128(text('<?php\n// core\n'));
    resetRequests();
    clock += 61;
    const res = await cache().read(CORE);
    expect(res.content).toBe('<?php\n// core\n');
    expect(reads().map((r) => r.body)).toEqual([{ path: CORE, known: { s: 14, m: 1700000000, h } }]);

    resetRequests();
    clock += 10;
    await cache().read(CORE);
    expect(reads()).toEqual([]); // validated_at was refreshed
  });

  it('"unchanged" with new s/m updates the metadata', async () => {
    await cache().read(CORE);
    mock.site.mtimes.set(CORE, 1800000000);
    clock += 61;
    const res = await cache().read(CORE);
    expect(res.m).toBe(1800000000);
    resetRequests();
    clock += 61;
    await cache().read(CORE);
    expect((reads()[0]?.body as { known: { m: number } }).known.m).toBe(1800000000);
  });

  it('replaces the entry when the file changed', async () => {
    await cache().read(CORE);
    mock.site.files.set(CORE, text('<?php\n// changed\nnew line\n'));
    mock.site.mtimes.set(CORE, 1700000500);
    clock += 61;
    const res = await cache().read(CORE, 3, 3);
    expect(res).toMatchObject({ content: 'new line\n', total_lines: 3 });
    resetRequests();
    clock += 1;
    expect((await cache().read(CORE)).content).toBe('<?php\n// changed\nnew line\n');
    expect(reads()).toEqual([]);
  });

  it('does not cache truncated files and falls back to ranged reads', async () => {
    mock.site.readLimitLines = 1;
    const whole = await cache().read(CORE);
    expect(whole.truncated).toBe(true);
    const ranged = await cache().read(CORE, 2, 2);
    expect(ranged.content).toBe('// core\n');
    resetRequests();
    await cache().read(CORE);
    expect(reads().length).toBeGreaterThan(0);
    expect(existsSync(path.join(dir, '.wpdev', 'cache', 'files', 'wp-includes', 'load.php'))).toBe(false);
  });

  it('ranges from cache format exactly like server ranges', async () => {
    const direct = await client.read(THEME_FILE, 2, 3);
    await cache().read(THEME_FILE);
    const cached = await cache().read(THEME_FILE, 2, 3);
    expect(formatRead(cached, THEME_FILE)).toBe(formatRead(direct, THEME_FILE));
    const wholeDirect = await client.read(THEME_FILE);
    expect(formatRead(await cache().read(THEME_FILE), THEME_FILE)).toBe(formatRead(wholeDirect, THEME_FILE));
  });

  it('drops the whole component cache when the version changes', async () => {
    await cache().read(FILE);
    mock.site.files.set(FILE, text('<?php\n// v2 cart\n'));
    mock.site.files.set(MAIN, mainFile('2.0.0'));
    mock.site.mtimes.set(MAIN, 1700000999);
    clock += 61;
    resetRequests();
    const res = await cache().read(FILE);
    expect(res.content).toBe('<?php\n// v2 cart\n');
    // Main file validated with known, then the dropped file is fetched fresh (no known).
    expect(reads().map((r) => Object.keys(r.body as object).includes('known'))).toEqual([true, false]);
  });

  it('keeps the component cache when the version is the same', async () => {
    await cache().read(FILE);
    clock += 61;
    resetRequests();
    await cache().read(FILE);
    expect(reads().every((r) => Object.keys(r.body as object).includes('known'))).toBe(true);
  });

  it('theme component uses style.css', async () => {
    await cache().read(THEME_FILE);
    expect(reads().map((r) => (r.body as { path: string }).path)).toEqual([THEME_CSS, THEME_FILE]);
  });

  it('finds the plugin main file by header when <slug>.php is not it', async () => {
    mock.site.files.delete(MAIN);
    mock.site.files.set(`${PLUGIN}/bootstrap.php`, mainFile('1.0.0'));
    await cache().read(FILE);
    expect(reads().map((r) => r.path)).toContain('list');
    const idx = JSON.parse(await (await import('node:fs/promises')).readFile(path.join(dir, '.wpdev', 'cache', 'index.json'), 'utf8')) as {
      components: Record<string, { main: string; version: string }>;
    };
    expect(idx.components[PLUGIN]).toMatchObject({ main: `${PLUGIN}/bootstrap.php`, version: '1.0.0' });
  });

  it('never blocks a read when version detection fails', async () => {
    mock.site.files.delete(MAIN);
    const res = await cache().read(FILE);
    expect(res.content).toContain('cart');
  });

  it('bypasses the cache when disabled and for writable paths', async () => {
    await cache({ enabled: false }).read(CORE);
    await cache({ enabled: false }).read(CORE);
    expect(reads().length).toBe(2);
    expect(existsSync(path.join(dir, '.wpdev', 'cache'))).toBe(false);
    await cache().read('wp-content/themes/child/functions.php');
    expect(existsSync(path.join(dir, '.wpdev', 'cache', 'files', 'wp-content', 'themes', 'child'))).toBe(false);
  });

  it('accepts Windows-style paths and stores them under native paths', async () => {
    await cache().read('wp-includes\\load.php');
    expect(existsSync(path.join(dir, '.wpdev', 'cache', 'files', 'wp-includes', 'load.php'))).toBe(true);
    resetRequests();
    await cache().read('wp-includes/load.php');
    expect(reads()).toEqual([]);
  });

  it('does not cache errors', async () => {
    await expect(cache().read('wp-includes/missing.php')).rejects.toMatchObject({ code: 'not_found' });
    expect(existsSync(path.join(dir, '.wpdev', 'cache', 'files', 'wp-includes', 'missing.php'))).toBe(false);
  });
});

describe('cache clear', () => {
  it('removes the cache and reports what was removed', async () => {
    await cache().read(CORE);
    await cache().read(FILE);
    const out = memoryOutput();
    expect(await cacheClearCommand(dir, out)).toBe(0);
    expect(out.lines[0]).toMatch(/^Cache svuotata: 3 file rimossi/);
    expect(existsSync(path.join(dir, '.wpdev', 'cache'))).toBe(false);
    expect(await clearCache(dir)).toEqual({ entries: 0, bytes: 0 });
    const again = memoryOutput();
    await cacheClearCommand(dir, again);
    expect(again.lines[0]).toBe('Cache già vuota.');
  });
});

describe('site_read with cache', () => {
  it('uses the cache through the MCP tool', async () => {
    const config: Config = parseConfig({ site: mock.url, user: mock.site.user, writable: ['wp-content/themes/child'] }, dir, { insecureLocal: true });
    const ctx: Context = { config, client };
    const tools = Object.fromEntries(buildTools(() => ctx).map((t) => [t.name, t]));
    const first = await tools.site_read!.handler({ path: CORE });
    resetRequests();
    const second = await tools.site_read!.handler({ path: CORE });
    expect(second.text).toBe(first.text);
    expect(reads()).toEqual([]);
  });
});
