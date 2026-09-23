import { execFile } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import { Engine } from 'php-parser';

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
  /** Set when linting was skipped. */
  skipped?: string;
  /** `php` (php -l) or `parser` (built-in JavaScript parser, used when PHP is not available). */
  engine?: 'php' | 'parser';
  errors: LintError[];
  checked: number;
}

/**
 * Syntax check of each file. `files` are [relative path, native absolute path].
 * Uses `php -l` when PHP is available (exact), otherwise the built-in PHP parser, so that
 * syntax errors are caught before the deploy even on machines without PHP.
 */
export async function lintPhp(php: string, files: readonly [string, string][], runner: Runner = execRunner): Promise<LintResult> {
  const result: LintResult = { engine: 'php', errors: [], checked: 0 };
  for (const [p, abs] of files) {
    const res = await runner(php, ['-l', abs]);
    if (res.missing) {
      return lintWithParser(files);
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

let engine: Engine | undefined;

/**
 * Syntax check with the php-parser package (pure JavaScript). Very close to `php -l` on current
 * code; a few legacy constructs (e.g. `clone( $x )` with parentheses) are reported as errors.
 */
export async function lintWithParser(files: readonly [string, string][]): Promise<LintResult> {
  engine ??= new Engine({ parser: { php8: true, suppressErrors: false, version: '8.4', extractDoc: false }, ast: { withPositions: false } });
  const result: LintResult = { engine: 'parser', errors: [], checked: 0 };
  for (const [p, abs] of files) {
    let code: string;
    try {
      code = await readFile(abs, 'utf8');
    } catch {
      continue; // deleted meanwhile: nothing to check
    }
    result.checked++;
    try {
      engine.parseCode(code, p);
    } catch (e) {
      const raw = e instanceof Error ? e.message : String(e);
      const line = /on line (\d+)/.exec(raw);
      const message = raw.replace(/^Parse Error\s*:\s*/i, '').replace(/\s+on line \d+\s*$/, '');
      result.errors.push(line ? { p, line: Number(line[1]), message } : { p, message });
    }
  }
  return result;
}
