import { execFile } from 'node:child_process';
import { readFile } from 'node:fs/promises';
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
