import { existsSync } from 'node:fs';
import { mkdir, mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { diffCommand } from '../src/commands/diff.js';
import { pullCommand } from '../src/commands/pull.js';
import { statusCommand } from '../src/commands/status.js';
import { parseConfig } from '../src/config.js';
import type { Context } from '../src/context.js';
import { ApiClient } from '../src/http.js';
import { memoryOutput } from '../src/output.js';
import { State } from '../src/state.js';
import { startMockServer, text, type MockServer } from './helpers/mockServer.js';

const ROOT = 'wp-content/themes/child';
let mock: MockServer;
let dir: string;
let ctx: Context;

function local(rel: string): string {
  return path.join(dir, ...rel.split('/'));
}

async function writeLocal(rel: string, content: string): Promise<void> {
  await mkdir(path.dirname(local(rel)), { recursive: true });
  await writeFile(local(rel), content);
}

beforeEach(async () => {
  mock = await startMockServer({
    files: new Map([
      [`${ROOT}/style.css`, text('body{}\n')],
      [`${ROOT}/functions.php`, text('<?php\r\n// crlf kept\r\n')],
      [`${ROOT}/inc/a.php`, text('<?php // a\n')],
      [`${ROOT}/node_modules/x/index.js`, text('ignored')],
      ['wp-content/plugins/woo/woo.php', text('<?php // woo\n')],
      ['wp-content/plugins/woo/inc/b.php', text('<?php // b\n')],
    ]),
  });
  dir = await mkdtemp(path.join(tmpdir(), 'wpdev-sync-'));
  const config = parseConfig({ site: mock.url, user: mock.site.user, writable: [ROOT] }, dir, { insecureLocal: true });
  ctx = { config, client: new ApiClient({ siteUrl: config.siteUrl, user: mock.site.user, password: mock.site.password }) };
});

afterEach(async () => {
  await mock.close();
});

describe('pull', () => {
  it('downloads writable roots byte for byte and records the state', async () => {
    const out = memoryOutput();
    expect(await pullCommand(ctx, out)).toBe(0);
    expect(await readFile(local(`${ROOT}/functions.php`), 'utf8')).toBe('<?php\r\n// crlf kept\r\n');
    expect(existsSync(local(`${ROOT}/inc/a.php`))).toBe(true);
    expect(existsSync(local(`${ROOT}/node_modules/x/index.js`))).toBe(false);
    const state = await State.load(dir);
    expect(state.get(`${ROOT}/style.css`)?.h_base).toMatch(/^[0-9a-f]{32}$/);
    expect(out.lines[0]).toContain('Scaricati 3 file');
  });

  it('is incremental: a second pull downloads nothing', async () => {
    await pullCommand(ctx, memoryOutput());
    const before = mock.site.requests.filter((r) => r.path === 'archive').length;
    const out = memoryOutput();
    await pullCommand(ctx, out);
    expect(mock.site.requests.filter((r) => r.path === 'archive').length).toBe(before);
    expect(out.lines[0]).toContain('Scaricati 0 file');
  });

  it('downloads only files changed on the server and removes files deleted there', async () => {
    await pullCommand(ctx, memoryOutput());
    mock.site.files.set(`${ROOT}/style.css`, text('body{color:red}\n'));
    mock.site.files.delete(`${ROOT}/inc/a.php`);
    await pullCommand(ctx, memoryOutput());
    const last = mock.site.requests.filter((r) => r.path === 'archive').at(-1);
    expect(last?.body).toEqual({ paths: [`${ROOT}/style.css`] });
    expect(await readFile(local(`${ROOT}/style.css`), 'utf8')).toBe('body{color:red}\n');
    expect(existsSync(local(`${ROOT}/inc/a.php`))).toBe(false);
    expect(existsSync(local(`${ROOT}/inc`))).toBe(false);
  });

  it('keeps local-only changes and reports conflicts without overwriting', async () => {
    await pullCommand(ctx, memoryOutput());
    await writeLocal(`${ROOT}/style.css`, 'local edit\n');
    await writeLocal(`${ROOT}/inc/a.php`, 'local only\n');
    mock.site.files.set(`${ROOT}/style.css`, text('server edit\n'));

    const out = memoryOutput();
    expect(await pullCommand(ctx, out)).toBe(1);
    expect(await readFile(local(`${ROOT}/style.css`), 'utf8')).toBe('local edit\n');
    expect(await readFile(local(`${ROOT}/inc/a.php`), 'utf8')).toBe('local only\n');
    expect(out.warnings.join('\n')).toContain(`${ROOT}/style.css`);

    const forced = memoryOutput();
    expect(await pullCommand(ctx, forced, { force: true })).toBe(0);
    expect(await readFile(local(`${ROOT}/style.css`), 'utf8')).toBe('server edit\n');
    expect(await readFile(local(`${ROOT}/inc/a.php`), 'utf8')).toBe('local only\n');
  });

  it('does not delete a locally modified file removed on the server (conflict)', async () => {
    await pullCommand(ctx, memoryOutput());
    await writeLocal(`${ROOT}/inc/a.php`, 'mine\n');
    mock.site.files.delete(`${ROOT}/inc/a.php`);
    expect(await pullCommand(ctx, memoryOutput())).toBe(1);
    expect(existsSync(local(`${ROOT}/inc/a.php`))).toBe(true);
  });

  it('treats identical content on both sides as converged', async () => {
    await writeLocal(`${ROOT}/style.css`, 'body{}\n');
    const out = memoryOutput();
    expect(await pullCommand(ctx, out)).toBe(0);
    const archived = mock.site.requests.filter((r) => r.path === 'archive').at(-1)?.body as { paths: string[] };
    expect(archived.paths).not.toContain(`${ROOT}/style.css`);
    expect((await State.load(dir)).get(`${ROOT}/style.css`)).toBeDefined();
  });

  it('pulls a read-only folder into .wpdev/readonly', async () => {
    const out = memoryOutput();
    expect(await pullCommand(ctx, out, { path: 'wp-content/plugins/woo' })).toBe(0);
    const ro = path.join(dir, '.wpdev', 'readonly', 'wp-content', 'plugins', 'woo', 'inc', 'b.php');
    expect(await readFile(ro, 'utf8')).toBe('<?php // b\n');
    expect((await State.load(dir)).get('wp-content/plugins/woo/woo.php')).toBeUndefined();
    mock.site.files.delete('wp-content/plugins/woo/inc/b.php');
    await pullCommand(ctx, memoryOutput(), { path: 'wp-content\\plugins\\woo' });
    expect(existsSync(ro)).toBe(false);
  });

  it('refuses --path on writable folders', async () => {
    const out = memoryOutput();
    expect(await pullCommand(ctx, out, { path: `${ROOT}/inc` })).toBe(1);
    expect(await pullCommand(ctx, out, { path: 'wp-content/themes' })).toBe(1);
  });
});

describe('diff', () => {
  it('lists local, server and conflicting changes', async () => {
    await pullCommand(ctx, memoryOutput());
    await writeLocal(`${ROOT}/new.php`, '<?php\n');
    await writeLocal(`${ROOT}/style.css`, 'mine\n');
    mock.site.files.set(`${ROOT}/style.css`, text('theirs\n'));
    mock.site.files.set(`${ROOT}/inc/a.php`, text('<?php // changed\n'));
    const out = memoryOutput();
    expect(await diffCommand(ctx, out)).toBe(0);
    const all = out.lines.join('\n');
    expect(all).toContain('Modificati in locale (1)');
    expect(all).toContain(`nuovo  ${ROOT}/new.php`);
    expect(all).toContain('Modificati sul server (1)');
    expect(all).toContain(`${ROOT}/inc/a.php`);
    expect(all).toContain('In conflitto (1)');
    expect(all).toContain(`${ROOT}/style.css`);
  });
});

describe('status', () => {
  it('reports mode and the folders restricted by wpdev.json', async () => {
    const out = memoryOutput();
    await statusCommand(ctx, out);
    expect(out.lines.join('\n')).toContain(`Cartelle scrivibili (limitate da wpdev.json): ${ROOT}`);
    expect(out.warnings).toEqual([]);
    mock.site.writableRoots = ['wp-content/plugins/other'];
    const out2 = memoryOutput();
    await statusCommand(ctx, out2);
    expect(out2.warnings.join('\n')).toContain(`non abilitate sul sito: ${ROOT}`);
    expect(out2.lines.join('\n')).toContain('escluse da questo progetto (wpdev.json → writable): wp-content/plugins/other');
  });

  it('uses the folders of the site when wpdev.json does not list them', async () => {
    const config = parseConfig({ site: mock.url, user: mock.site.user }, dir, { insecureLocal: true });
    const siteCtx: Context = { config, client: ctx.client };
    expect(config.writableFromSite).toBe(true);
    const out = memoryOutput();
    await statusCommand(siteCtx, out);
    expect(out.lines.join('\n')).toContain(`Cartelle scrivibili (dal sito): ${ROOT}`);
    mock.site.writableRoots = [];
    const out2 = memoryOutput();
    await statusCommand(siteCtx, out2);
    expect(out2.warnings.join('\n')).toContain('Nessuna cartella scrivibile abilitata sul sito');
  });

  it('reports mode off', async () => {
    mock.site.mode = 'off';
    const out = memoryOutput();
    await statusCommand(ctx, out);
    expect(out.lines.join('\n')).toContain('Modalità: off');
  });
});
