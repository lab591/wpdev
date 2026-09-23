import { execFile } from 'node:child_process';

/** Returns true when git is configured with `core.autocrlf=true` for `cwd`. */
export function autocrlfEnabled(cwd: string): Promise<boolean> {
  return new Promise((resolve) => {
    execFile('git', ['config', '--get', 'core.autocrlf'], { cwd, windowsHide: true, timeout: 5000 }, (err, stdout) => {
      resolve(!err && stdout.trim().toLowerCase() === 'true');
    });
  });
}

export const AUTOCRLF_WARNING =
  'git ha core.autocrlf=true: senza `.gitattributes` con `* -text` i checkout convertono i fine riga e i file risultano modificati rispetto al server.';
