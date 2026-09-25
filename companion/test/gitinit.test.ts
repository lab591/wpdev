import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { initCommand } from '../src/commands/init.js';
import { pullCommand } from '../src/commands/pull.js';
import { parseConfig } from '../src/config.js';
import { ApiClient } from '../src/http.js';
import { memoryOutput } from '../src/output.js';
import { renderManagedSection } from '../src/scaffold.js';
import { resolveWritable } from '../src/writable.js';
import { startMockServer, text, type MockServer } from './helpers/mockServer.js';

const hasGit = (() => {
  try {
    execFileSync('git', ['--version'], { stdio: 'ignore' });
    return true;
  } catch {
    return false;
  }
})();

const ROOT = 'wp-content/themes/child';

describe.skipIf(!hasGit)('local git repository (init, pull)', () => {
  let mock: MockServer;
  let dir: string;
  const git = (...args: string[]): string => execFileSync('git', args, { cwd: dir, encoding: 'utf8' }).trim();

  beforeEach(async () => {
    mock = await startMockServer({ files: new Map([[`${ROOT}/style.css`, text('body{}\n')]]) });
    dir = await mkdtemp(path.join(tmpdir(), 'wpdev-gitinit-'));
    await writeFile(path.join(dir, '.env.local'), `WPDEV_APP_PASSWORD="${mock.site.password}"\n`);
  });

  afterEach(async () => {
    await mock.close();
    await rm(dir, { recursive: true, force: true });
  });

  it('init creates the repository with a first commit, without secrets, and tells Claude to commit', async () => {
    const out = memoryOutput();
    expect(await initCommand(dir, out, { site: mock.url, user: mock.site.user, insecureLocal: true, yes: true })).toBe(0);
    expect(existsSync(path.join(dir, '.git'))).toBe(true);
    expect(out.lines.join('\n')).toContain('Creato un repository git locale');
    expect(git('log', '--format=%s')).toBe('wpdev init: configurazione del progetto');
    const files = git('ls-files').split('\n');
    expect(files).toEqual(expect.arrayContaining(['wpdev.json', '.gitignore', 'CLAUDE.md', '.mcp.json', '.claude/settings.json']));
    expect(files).not.toContain('.env.local');
    expect(git('status', '--porcelain')).toBe('');
    expect(await readFile(path.join(dir, 'CLAUDE.md'), 'utf8')).toContain('## Versioni (git)');
  });

  it('pull commits the version of the site', async () => {
    await initCommand(dir, memoryOutput(), { site: mock.url, user: mock.site.user, insecureLocal: true, yes: true });
    const config = parseConfig(JSON.parse(await readFile(path.join(dir, 'wpdev.json'), 'utf8')), dir, { insecureLocal: true });
    const client = new ApiClient({ siteUrl: config.siteUrl, user: mock.site.user, password: mock.site.password });
    await resolveWritable({ config, client }); // writable folders come from the site
    const out = memoryOutput();
    expect(await pullCommand({ config, client }, out)).toBe(0);
    expect(out.lines.join('\n')).toMatch(/Commit git [0-9a-f]+ con la versione del sito/);
    expect(git('log', '-1', '--format=%s')).toBe('wpdev pull: versione del sito (1 file scaricati, 0 rimossi)');
    expect(git('ls-files', ROOT)).toBe(`${ROOT}/style.css`);

    // Nothing new on the site: no empty commit.
    const again = memoryOutput();
    expect(await pullCommand({ config, client }, again)).toBe(0);
    expect(git('rev-list', '--count', 'HEAD')).toBe('2');
  });

  it('--no-git and a "no" answer leave the project without a repository', async () => {
    await initCommand(dir, memoryOutput(), { site: mock.url, user: mock.site.user, insecureLocal: true, yes: true, git: false });
    expect(existsSync(path.join(dir, '.git'))).toBe(false);
    expect(await readFile(path.join(dir, 'CLAUDE.md'), 'utf8')).not.toContain('## Versioni (git)');

    const other = await mkdtemp(path.join(tmpdir(), 'wpdev-gitinit-'));
    const asked: string[] = [];
    const ask = async (q: string, def?: string): Promise<string> => {
      asked.push(q);
      return q.includes('repository git') ? 'n' : (def ?? '');
    };
    try {
      await initCommand(other, memoryOutput(), { site: mock.url, user: mock.site.user, insecureLocal: true }, ask);
      expect(asked.some((q) => q.includes('repository git'))).toBe(true);
      expect(existsSync(path.join(other, '.git'))).toBe(false);
    } finally {
      await rm(other, { recursive: true, force: true });
    }
  });

  it('an existing repository is used as is', async () => {
    git('init', '-q');
    const out = memoryOutput();
    await initCommand(dir, out, { site: mock.url, user: mock.site.user, insecureLocal: true, yes: true });
    expect(out.lines.join('\n')).not.toContain('Creato un repository git');
    expect(() => git('log')).toThrow(); // no commit made on the user's repository
    expect(await readFile(path.join(dir, 'CLAUDE.md'), 'utf8')).toContain('## Versioni (git)');
  });
});

describe('CLAUDE.md git section', () => {
  it('is present only for repositories and forbids rewriting history', () => {
    const base = { name: 'S', url: 'https://example.test', writable: [] };
    expect(renderManagedSection(base)).not.toContain('## Versioni (git)');
    const md = renderManagedSection({ ...base, gitRepo: true });
    expect(md).toContain('fai un commit');
    expect(md).toContain('Non riscrivere la storia');
    expect(md).toContain('non fare\n  `push`');
  });
});
