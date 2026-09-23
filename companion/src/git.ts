import { execFile, spawn } from 'node:child_process';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';

/** Returns true when git is configured with `core.autocrlf=true` for `cwd`. */
export function autocrlfEnabled(cwd: string): Promise<boolean> {
  return new Promise((resolve) => {
    execFile('git', ['config', '--get', 'core.autocrlf'], { cwd, windowsHide: true, timeout: 5000 }, (err, stdout) => {
      resolve(!err && stdout.trim().toLowerCase() === 'true');
    });
  });
}

/** True when `.gitattributes` in `cwd` disables EOL conversion for every file (`* -text`). */
export async function hasNoTextAttribute(cwd: string): Promise<boolean> {
  try {
    const content = await readFile(path.join(cwd, '.gitattributes'), 'utf8');
    return /^\*\s+-text\s*$/m.test(content);
  } catch {
    return false;
  }
}

/** `core.autocrlf=true` without the `* -text` protection: checkouts would rewrite line endings. */
export async function autocrlfRisk(cwd: string): Promise<boolean> {
  return (await autocrlfEnabled(cwd)) && !(await hasNoTextAttribute(cwd));
}

export const AUTOCRLF_WARNING =
  'git ha core.autocrlf=true: senza `.gitattributes` con `* -text` i checkout convertono i fine riga e i file risultano modificati rispetto al server.';

/** Result of a git invocation; `missing` when git is not installed. */
type GitResult = { missing: true } | { missing: false; code: number; stdout: string; stderr: string };

/** Runs git with an argument array (never a shell), optionally feeding stdin. */
export function runGit(cwd: string, args: string[], input?: string): Promise<GitResult> {
  return new Promise((resolve) => {
    let child;
    try {
      child = spawn('git', args, { cwd, windowsHide: true });
    } catch {
      resolve({ missing: true });
      return;
    }
    let stdout = '';
    let stderr = '';
    const timer = setTimeout(() => child.kill(), 30_000);
    child.stdout.on('data', (d: Buffer) => (stdout += d.toString('utf8')));
    child.stderr.on('data', (d: Buffer) => (stderr += d.toString('utf8')));
    child.on('error', (e: NodeJS.ErrnoException) => {
      clearTimeout(timer);
      resolve(e.code === 'ENOENT' ? { missing: true } : { missing: false, code: 1, stdout, stderr: e.message });
    });
    child.on('close', (code: number | null) => {
      clearTimeout(timer);
      resolve({ missing: false, code: code ?? 1, stdout, stderr });
    });
    child.stdin.end(input ?? '');
  });
}

export interface CommitResult {
  /** Short hash of the new commit. */
  hash?: string;
  /** Why nothing was committed (not a repository, nothing to commit...). */
  skipped?: string;
  error?: string;
}

const nul = (paths: readonly string[]): string => paths.join('\0');

/**
 * Commits exactly `paths` (relative to `cwd`, "/" separators) with `message`: other changes, even
 * staged ones, are left alone. Deleted files are included only if git tracks them; ignored files are skipped.
 */
export async function commitPaths(cwd: string, paths: { p: string; deleted: boolean }[], message: string): Promise<CommitResult> {
  const inside = await runGit(cwd, ['rev-parse', '--is-inside-work-tree']);
  if (inside.missing) return { skipped: 'git non installato' };
  if (inside.code !== 0 || inside.stdout.trim() !== 'true') return { skipped: 'il progetto non è un repository git' };

  const present = paths.filter((x) => !x.deleted).map((x) => x.p);
  const deleted = paths.filter((x) => x.deleted).map((x) => x.p);
  let tracked: string[] = [];
  if (deleted.length) {
    // ls-files has no --pathspec-from-file: list the index and filter here.
    const ls = await runGit(cwd, ['-c', 'core.quotepath=false', 'ls-files', '-z']);
    const index = new Set(ls.missing ? [] : ls.stdout.split('\0'));
    tracked = deleted.filter((p) => index.has(p));
  }
  let candidates = [...present, ...deleted.filter((p) => tracked.includes(p))];
  if (candidates.length) {
    const ignored = await runGit(cwd, ['check-ignore', '-z', '--stdin'], nul(candidates));
    const skip = new Set(ignored.missing ? [] : ignored.stdout.split('\0').filter(Boolean));
    candidates = candidates.filter((p) => !skip.has(p));
  }
  if (candidates.length === 0) return { skipped: 'nessun file da includere nel commit' };

  const add = await runGit(cwd, ['add', '-A', '--pathspec-from-file=-', '--pathspec-file-nul'], nul(candidates));
  if (add.missing || add.code !== 0) return { error: add.missing ? 'git non installato' : add.stderr.trim().split('\n')[0] ?? 'git add fallito' };
  const dir = await mkdtemp(path.join(tmpdir(), 'wpdev-commit-'));
  const msgFile = path.join(dir, 'message.txt');
  try {
    await writeFile(msgFile, message, 'utf8');
    const commit = await runGit(cwd, ['commit', '-q', '-F', msgFile, '--pathspec-from-file=-', '--pathspec-file-nul'], nul(candidates));
    if (commit.missing) return { error: 'git non installato' };
    if (commit.code !== 0) {
      const text = `${commit.stdout}\n${commit.stderr}`;
      if (/nothing to commit|no changes added|nothing added/i.test(text)) return { skipped: 'nessuna differenza rispetto all\'ultimo commit' };
      return { error: text.trim().split('\n')[0] ?? 'git commit fallito' };
    }
  } finally {
    await rm(dir, { recursive: true, force: true });
  }
  const head = await runGit(cwd, ['rev-parse', '--short', 'HEAD']);
  return head.missing || head.code !== 0 ? { hash: '?' } : { hash: head.stdout.trim() };
}
