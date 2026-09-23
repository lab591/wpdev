import { mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { initCommand, updateGitignore } from '../src/commands/init.js';
import { memoryOutput } from '../src/output.js';
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
