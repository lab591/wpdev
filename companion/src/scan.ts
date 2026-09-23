import type { Dirent } from 'node:fs';
import { readdir, stat } from 'node:fs/promises';
import path from 'node:path';
import { matchAny } from './glob.js';
import { normalizeRel, toNative } from './paths.js';

export interface LocalFile {
  p: string;
  s: number;
  m: number;
}

/**
 * Lists regular files under `rootRel` (internal path) inside `baseDir`.
 * Symlinks are never followed; paths matching `exclude` are skipped
 * (directories are pruned when the directory itself matches).
 * Returned `p` are relative to the site root, i.e. `rootRel/...`.
 */
export async function scanTree(baseDir: string, rootRel: string, exclude: readonly string[]): Promise<Map<string, LocalFile>> {
  const out = new Map<string, LocalFile>();
  const start = toNative(baseDir, rootRel);

  async function walk(dirAbs: string, dirRel: string): Promise<void> {
    let entries: Dirent[];
    try {
      entries = await readdir(dirAbs, { withFileTypes: true });
    } catch (e) {
      if ((e as NodeJS.ErrnoException).code === 'ENOENT') {
        return;
      }
      throw e;
    }
    for (const entry of entries) {
      let rel: string;
      try {
        rel = normalizeRel(`${dirRel}/${entry.name}`);
      } catch {
        continue; // names we cannot represent in the protocol are ignored
      }
      if (matchAny(exclude, rel)) {
        continue;
      }
      const abs = path.join(dirAbs, entry.name);
      if (entry.isDirectory()) {
        await walk(abs, rel);
      } else if (entry.isFile()) {
        const st = await stat(abs);
        out.set(rel, { p: rel, s: st.size, m: Math.trunc(st.mtimeMs) });
      }
    }
  }

  await walk(start, rootRel);
  return out;
}
