import { execFileSync } from 'node:child_process';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { commitPaths } from '../src/git.js';

const hasGit = (() => {
  try {
    execFileSync('git', ['--version'], { stdio: 'ignore' });
    return true;
  } catch {
    return false;
  }
})();

describe.skipIf(!hasGit)('commitPaths', () => {
  let dir: string;
  const git = (...args: string[]): string => execFileSync('git', args, { cwd: dir, encoding: 'utf8' }).trim();
  const write = async (rel: string, content: string): Promise<void> => {
    await mkdir(path.dirname(path.join(dir, rel)), { recursive: true });
    await writeFile(path.join(dir, rel), content);
  };

  beforeEach(async () => {
    dir = await mkdtemp(path.join(tmpdir(), 'wpdev-commit-'));
    git('init', '-q');
    git('config', 'user.name', 'Test');
    git('config', 'user.email', 'test@example.test');
    git('config', 'core.autocrlf', 'false');
    await write('.gitignore', '*.map\n');
    await write('wp-content/themes/child/a.php', 'a1');
    await write('wp-content/themes/child/c.php', 'c1');
    await write('other.txt', 'o1');
    git('add', '-A');
    git('commit', '-q', '-m', 'initial');
  });
  afterEach(async () => {
    await rm(dir, { recursive: true, force: true });
  });

  it('commits exactly the deployed files and leaves the rest alone', async () => {
    await write('wp-content/themes/child/a.php', 'a2'); // modified
    await write('wp-content/themes/child/nuovo è.php', 'b'); // new, non-ASCII
    await rm(path.join(dir, 'wp-content/themes/child/c.php')); // deleted, tracked
    await write('wp-content/themes/child/x.js.map', 'm'); // ignored
    await write('other.txt', 'o2');
    git('add', 'other.txt'); // user's staged change, not part of the deploy

    const res = await commitPaths(
      dir,
      [
        { p: 'wp-content/themes/child/a.php', deleted: false },
        { p: 'wp-content/themes/child/nuovo è.php', deleted: false },
        { p: 'wp-content/themes/child/c.php', deleted: true },
        { p: 'wp-content/themes/child/never.php', deleted: true }, // deleted but never tracked
        { p: 'wp-content/themes/child/x.js.map', deleted: false },
      ],
      'wpdev deploy 20260923-101500-abc123\n\nbody\n',
    );
    expect(res.error).toBeUndefined();
    expect(res.hash).toMatch(/^[0-9a-f]{4,}$/);
    expect(git('log', '-1', '--format=%s')).toBe('wpdev deploy 20260923-101500-abc123');
    const files = git('-c', 'core.quotepath=false', 'show', '--name-status', '--format=', 'HEAD').split('\n').sort();
    expect(files).toEqual(['A\twp-content/themes/child/nuovo è.php', 'D\twp-content/themes/child/c.php', 'M\twp-content/themes/child/a.php']);
    expect(git('diff', '--cached', '--name-only')).toBe('other.txt'); // still staged, not committed
  });

  it('skips when nothing differs from the last commit', async () => {
    const res = await commitPaths(dir, [{ p: 'wp-content/themes/child/a.php', deleted: false }], 'm');
    expect(res.hash).toBeUndefined();
    expect(res.skipped).toBeDefined();
  });

  it('skips outside a git repository', async () => {
    const plain = await mkdtemp(path.join(tmpdir(), 'wpdev-nogit-'));
    const res = await commitPaths(plain, [{ p: 'a.php', deleted: false }], 'm');
    expect(res.skipped).toContain('repository');
    await rm(plain, { recursive: true, force: true });
  });
});
