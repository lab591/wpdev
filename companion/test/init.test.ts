import { mkdir, mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { initCommand, updateGitignore } from '../src/commands/init.js';
import { memoryOutput } from '../src/output.js';
import { ensureGitattributes, ensureMcpServer, ensureStopHook, renderClaudeMd, writeClaudeMd } from '../src/scaffold.js';
import { startMockServer, type MockServer } from './helpers/mockServer.js';

let mock: MockServer;

beforeAll(async () => {
  mock = await startMockServer();
});

afterAll(async () => {
  await mock.close();
});

describe('init', () => {
  it('creates wpdev.json, updates .gitignore and tests /status', async () => {
    const dir = await mkdtemp(path.join(tmpdir(), 'wpdev-init-'));
    await writeFile(path.join(dir, '.env.local'), `WPDEV_APP_PASSWORD="${mock.site.password}"\n`);
    const out = memoryOutput();
    const code = await initCommand(dir, out, {
      site: mock.url,
      user: mock.site.user,
      writable: ['wp-content\\themes\\child'],
      insecureLocal: true,
      yes: true,
    });
    expect(code).toBe(0);
    const json = JSON.parse(await readFile(path.join(dir, 'wpdev.json'), 'utf8')) as Record<string, unknown>;
    expect(json.writable).toEqual(['wp-content/themes/child']);
    expect(json).not.toHaveProperty('password');
    expect(await readFile(path.join(dir, '.gitignore'), 'utf8')).toBe('.wpdev/\n.env.local\n');
    expect(out.lines.join('\n')).toContain('Connessione riuscita: modalità read');

    expect(await initCommand(dir, memoryOutput(), { site: mock.url, user: 'x', yes: true, insecureLocal: true })).toBe(1);
  });

  it('asks interactively for missing values', async () => {
    const dir = await mkdtemp(path.join(tmpdir(), 'wpdev-init-'));
    const answers: Record<string, string> = { URL: 'https://example.com', Utente: 'claudio', Cartelle: 'wp-content/themes/a, wp-content/plugins/b' };
    const ask = async (q: string, def?: string): Promise<string> => {
      const key = Object.keys(answers).find((k) => q.startsWith(k));
      return key ? (answers[key] as string) : (def ?? '');
    };
    const out = memoryOutput();
    expect(await initCommand(dir, out, {}, ask)).toBe(0);
    const json = JSON.parse(await readFile(path.join(dir, 'wpdev.json'), 'utf8')) as { writable: string[]; passwordEnv: string };
    expect(json.writable).toEqual(['wp-content/themes/a', 'wp-content/plugins/b']);
    expect(json.passwordEnv).toBe('WPDEV_APP_PASSWORD');
  });

  it('updates .gitignore idempotently', async () => {
    const dir = await mkdtemp(path.join(tmpdir(), 'wpdev-init-'));
    await writeFile(path.join(dir, '.gitignore'), 'node_modules\n/.wpdev/');
    expect(await updateGitignore(dir)).toEqual(['.env.local']);
    expect(await updateGitignore(dir)).toEqual([]);
    expect(await readFile(path.join(dir, '.gitignore'), 'utf8')).toBe('node_modules\n/.wpdev/\n.env.local\n');
  });
});

describe('init scaffolding for Claude Code', () => {
  it('creates .gitattributes, Stop hook, .mcp.json and CLAUDE.md', async () => {
    const dir = await mkdtemp(path.join(tmpdir(), 'wpdev-init-'));
    const out = memoryOutput();
    expect(
      await initCommand(dir, out, {
        site: mock.url,
        user: mock.site.user,
        writable: ['wp-content/themes/mio-child', 'wp-content/plugins/mio-widgets'],
        insecureLocal: true,
        yes: true,
      }),
    ).toBe(0);
    expect(await readFile(path.join(dir, '.gitattributes'), 'utf8')).toContain('* -text');
    const settings = JSON.parse(await readFile(path.join(dir, '.claude', 'settings.json'), 'utf8')) as {
      hooks: { Stop: { hooks: { type: string; command: string; timeout: number }[] }[] };
    };
    expect(settings.hooks.Stop).toEqual([{ hooks: [{ type: 'command', command: 'wpdev --insecure-local deploy --hook', timeout: 300 }] }]);
    const mcp = JSON.parse(await readFile(path.join(dir, '.mcp.json'), 'utf8')) as { mcpServers: Record<string, unknown> };
    expect(mcp.mcpServers.wpdev).toEqual({ command: 'wpdev', args: ['--insecure-local', 'mcp'] });
    const md = await readFile(path.join(dir, 'CLAUDE.md'), 'utf8');
    expect(md).toContain(`# Sito: ${new URL(mock.url).host} — ${mock.url}`);
    expect(md).toContain('`wp-content/themes/mio-child`, `wp-content/plugins/mio-widgets`');
    expect(md).toContain('Child theme: mio-child. Plugin custom: mio-widgets, prefisso funzioni `<prefisso>_`.');
  });

  it('merges with existing files and never overwrites CLAUDE.md', async () => {
    const dir = await mkdtemp(path.join(tmpdir(), 'wpdev-init-'));
    await writeFile(path.join(dir, '.gitattributes'), '*.png binary');
    await mkdir(path.join(dir, '.claude'), { recursive: true });
    await writeFile(path.join(dir, '.claude', 'settings.json'), JSON.stringify({ permissions: { allow: ['Bash(ls)'] }, hooks: { Stop: [{ hooks: [{ type: 'command', command: 'echo hi' }] }] } }));
    await writeFile(path.join(dir, '.mcp.json'), JSON.stringify({ mcpServers: { other: { command: 'x' } } }));
    await writeFile(path.join(dir, 'CLAUDE.md'), '# mine\n');

    expect(await ensureGitattributes(dir)).toBe(true);
    expect(await ensureGitattributes(dir)).toBe(false);
    expect(await readFile(path.join(dir, '.gitattributes'), 'utf8')).toMatch(/^\*\.png binary\n# wpdev.*\n\* -text\n$/);

    expect(await ensureStopHook(dir, false)).toBe(true);
    expect(await ensureStopHook(dir, false)).toBe(false);
    const settings = JSON.parse(await readFile(path.join(dir, '.claude', 'settings.json'), 'utf8')) as { permissions: unknown; hooks: { Stop: unknown[] } };
    expect(settings.permissions).toEqual({ allow: ['Bash(ls)'] });
    expect(settings.hooks.Stop).toHaveLength(2);

    expect(await ensureMcpServer(dir, false)).toBe(true);
    expect(await ensureMcpServer(dir, false)).toBe(false);
    const mcp = JSON.parse(await readFile(path.join(dir, '.mcp.json'), 'utf8')) as { mcpServers: Record<string, unknown> };
    expect(Object.keys(mcp.mcpServers)).toEqual(['other', 'wpdev']);
    expect(mcp.mcpServers.wpdev).toEqual({ command: 'wpdev', args: ['mcp'] });

    expect(await writeClaudeMd(dir, { name: 'x', url: 'https://x', writable: [] })).toBe('CLAUDE.wpdev.md');
    expect(await readFile(path.join(dir, 'CLAUDE.md'), 'utf8')).toBe('# mine\n');
    expect(renderClaudeMd({ name: 'x', url: 'https://x', writable: [] })).toContain('Child theme: <nome>');
  });
});
