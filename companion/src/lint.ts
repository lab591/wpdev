import { execFile } from 'node:child_process';

/** Result of running a process: `missing` when the executable does not exist. */
export type RunResult = { missing: true } | { missing: false; code: number; output: string };

/** Runs an executable with an argument array (never through a shell). */
export type Runner = (cmd: string, args: string[]) => Promise<RunResult>;

export const execRunner: Runner = (cmd, args) =>
  new Promise((resolve) => {
    execFile(cmd, args, { windowsHide: true, timeout: 30_000, maxBuffer: 1 << 20 }, (err, stdout, stderr) => {
      const e = err as (NodeJS.ErrnoException & { code?: number | string }) | null;
      if (e && (e.code === 'ENOENT' || e.code === 'EACCES')) {
        resolve({ missing: true });
        return;
      }
      const code = e ? (typeof e.code === 'number' ? e.code : 1) : 0;
      resolve({ missing: false, code, output: `${stdout}\n${stderr}` });
    });
  });

export interface LintError {
  p: string;
  line?: number;
  message: string;
}

export interface LintResult {
  /** Set when linting was skipped (PHP not available). */
  skipped?: string;
  errors: LintError[];
  checked: number;
}

/**
 * `php -l` on each file. `files` are [relative path, native absolute path].
 * A missing PHP binary skips linting with a warning instead of blocking the deploy.
 */
export async function lintPhp(php: string, files: readonly [string, string][], runner: Runner = execRunner): Promise<LintResult> {
  const result: LintResult = { errors: [], checked: 0 };
  for (const [p, abs] of files) {
    const res = await runner(php, ['-l', abs]);
    if (res.missing) {
      return { skipped: `PHP non trovato ("${php}"): lint saltato`, errors: [], checked: 0 };
    }
    result.checked++;
    if (res.code !== 0) {
      result.errors.push(parseLintOutput(p, abs, res.output));
    }
  }
  return result;
}

/** Extracts message and line from `php -l` output, hiding the local absolute path. */
export function parseLintOutput(p: string, abs: string, output: string): LintError {
  const lines = output.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  const relevant = lines.find((l) => /(Parse|Fatal) error|syntax error/i.test(l)) ?? lines.find((l) => !/^Errors parsing|^Warning: Module/i.test(l)) ?? 'errore di sintassi';
  const lineMatch = /on line (\d+)/.exec(relevant);
  const message = relevant
    .split(abs)
    .join(p)
    .replace(/^PHP\s+/, '')
    .replace(/\s+in\s+.+?\s+on line \d+\s*$/, '')
    .replace(/\s+on line \d+\s*$/, '');
  return lineMatch ? { p, line: Number(lineMatch[1]), message } : { p, message };
}
