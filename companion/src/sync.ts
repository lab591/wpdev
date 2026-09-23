import { mkdir, readdir, rename, rmdir, stat, unlink, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { unzipSync } from 'fflate';
import { matchAny } from './glob.js';
import { xxh128, xxh128File } from './hash.js';
import type { ApiClient, ManifestFile } from './http.js';
import { isInside, normalizeRel, toNative } from './paths.js';
import type { LocalFile } from './scan.js';
import type { State } from './state.js';

export type SideStatus = 'unchanged' | 'absent' | 'added' | 'modified' | 'deleted';

export interface Conflict {
  p: string;
  local: SideStatus;
  server: SideStatus;
  remote?: ManifestFile;
}

export interface SyncPlan {
  /** Changed on the server only: to download. */
  download: ManifestFile[];
  /** Deleted on the server only: to delete locally. */
  deleteLocal: string[];
  /** Changed locally only (new, modified or deleted). */
  localModified: { p: string; status: SideStatus }[];
  /** Changed on both sides with different results. */
  conflicts: Conflict[];
  /** Changed on both sides to identical content: only the state must be updated. */
  converged: { p: string; h: string }[];
  /** Deleted on both sides: drop from state. */
  forget: string[];
  /** Unchanged files whose local stat changed (same content): refresh s/m in state. */
  touched: string[];
}

export interface PlanInput {
  local: Map<string, LocalFile>;
  remote: Map<string, ManifestFile>;
  state: State;
  /** Roots the plan covers; state entries outside them are ignored. */
  roots: readonly string[];
  hashLocal: (p: string) => Promise<string>;
}

/** Hash of a local file, using the state fast path when size and mtime are unchanged. */
export function localHasher(baseDir: string, local: Map<string, LocalFile>, state: State): (p: string) => Promise<string> {
  return async (p) => {
    const entry = state.get(p);
    const file = local.get(p);
    if (entry && file && entry.s === file.s && entry.m === file.m) {
      return entry.h_base;
    }
    return xxh128File(toNative(baseDir, p));
  };
}

export async function buildPlan(input: PlanInput): Promise<SyncPlan> {
  const { local, remote, state, roots } = input;
  const plan: SyncPlan = { download: [], deleteLocal: [], localModified: [], conflicts: [], converged: [], forget: [], touched: [] };
  const all = new Set<string>([...local.keys(), ...remote.keys()]);
  for (const root of roots) {
    for (const entry of state.entriesUnder(root)) {
      all.add(entry.p);
    }
  }
  for (const p of [...all].sort()) {
    const base = state.get(p)?.h_base;
    const l = local.get(p);
    const r = remote.get(p);
    const lh = l ? await input.hashLocal(p) : undefined;

    const localStatus: SideStatus =
      base === undefined ? (l ? 'added' : 'absent') : !l ? 'deleted' : lh === base ? 'unchanged' : 'modified';
    const serverStatus: SideStatus =
      base === undefined ? (r ? 'added' : 'absent') : !r ? 'deleted' : r.h === base ? 'unchanged' : 'modified';
    const localChanged = localStatus !== 'unchanged' && localStatus !== 'absent';
    const serverChanged = serverStatus !== 'unchanged' && serverStatus !== 'absent';

    if (!localChanged && !serverChanged) {
      const entry = state.get(p);
      if (l && entry && (entry.s !== l.s || entry.m !== l.m)) {
        plan.touched.push(p);
      }
      continue;
    }
    if (localChanged && !serverChanged) {
      plan.localModified.push({ p, status: localStatus });
      continue;
    }
    if (!localChanged && serverChanged) {
      if (r) {
        plan.download.push(r);
      } else if (l) {
        plan.deleteLocal.push(p);
      } else {
        plan.forget.push(p);
      }
      continue;
    }
    // Changed on both sides.
    if (l && r && lh === r.h) {
      plan.converged.push({ p, h: r.h });
    } else if (!l && !r) {
      plan.forget.push(p);
    } else {
      plan.conflicts.push(r ? { p, local: localStatus, server: serverStatus, remote: r } : { p, local: localStatus, server: serverStatus });
    }
  }
  return plan;
}

/** Fetches the manifest of each root, filtered by `exclude`. Missing roots yield no files. */
export async function fetchRemote(
  client: ApiClient,
  roots: readonly string[],
  exclude: readonly string[],
  warn: (msg: string) => void = () => undefined,
): Promise<Map<string, ManifestFile>> {
  const out = new Map<string, ManifestFile>();
  for (const root of roots) {
    let res;
    try {
      res = await client.manifest(root, [...exclude]);
    } catch (e) {
      if ((e as { code?: string }).code === 'not_found') {
        warn(`La cartella ${root} non esiste sul server`);
        continue;
      }
      throw e;
    }
    if (res.truncated) {
      warn(`Manifest di ${root} troncato dal server: alcuni file non sono stati considerati`);
    }
    for (const f of res.files) {
      const p = normalizeRel(f.p);
      if (!isInside(p, root, false) || matchAny(exclude, p)) {
        continue;
      }
      out.set(p, { ...f, p });
    }
  }
  return out;
}

const ARCHIVE_BATCH = 500;

export interface DownloadedFile {
  p: string;
  h: string;
  s: number;
  m: number;
}

/**
 * Downloads `files` through `/archive` (batches of 500) and writes them under
 * `baseDir` atomically. Zip entries are validated: they must be exactly the
 * requested paths. Returns hash and local stat of every written file.
 */
export async function downloadFiles(client: ApiClient, baseDir: string, files: readonly ManifestFile[]): Promise<DownloadedFile[]> {
  const written: DownloadedFile[] = [];
  for (let i = 0; i < files.length; i += ARCHIVE_BATCH) {
    const batch = files.slice(i, i + ARCHIVE_BATCH);
    const wanted = new Set(batch.map((f) => f.p));
    const zip = await client.archive([...wanted]);
    let entries: Record<string, Uint8Array>;
    try {
      entries = unzipSync(zip);
    } catch (e) {
      throw new Error(`Archivio non valido ricevuto dal server: ${(e as Error).message}`);
    }
    for (const [name, data] of Object.entries(entries)) {
      if (name.endsWith('/')) {
        continue; // directory entry
      }
      const p = normalizeRel(name);
      if (!wanted.has(p)) {
        throw new Error(`L'archivio contiene un file non richiesto: ${p}`);
      }
      wanted.delete(p);
      const abs = toNative(baseDir, p);
      await writeAtomic(abs, data);
      const st = await stat(abs);
      written.push({ p, h: await xxh128(data), s: st.size, m: Math.trunc(st.mtimeMs) });
    }
    if (wanted.size > 0) {
      throw new Error(`File mancanti nell'archivio: ${[...wanted].slice(0, 5).join(', ')}`);
    }
  }
  return written;
}

export async function writeAtomic(abs: string, data: Uint8Array): Promise<void> {
  await mkdir(path.dirname(abs), { recursive: true });
  const tmp = path.join(path.dirname(abs), `.${path.basename(abs)}.wpdev-${process.pid}.tmp`);
  await writeFile(tmp, data);
  try {
    await rename(tmp, abs);
  } catch (e) {
    await unlink(tmp).catch(() => undefined);
    throw e;
  }
}

/** Deletes a local file and then its parent folders that became empty, up to `stopRel`. */
export async function deleteLocalFile(baseDir: string, p: string, stopRel: string): Promise<void> {
  const abs = toNative(baseDir, p);
  try {
    await unlink(abs);
  } catch (e) {
    if ((e as NodeJS.ErrnoException).code !== 'ENOENT') throw e;
  }
  const stopAbs = stopRel === '.' ? path.resolve(baseDir) : toNative(baseDir, stopRel);
  let dir = path.dirname(abs);
  while (dir.length > stopAbs.length && dir.startsWith(stopAbs)) {
    try {
      if ((await readdir(dir)).length > 0) break;
      await rmdir(dir);
    } catch {
      break;
    }
    dir = path.dirname(dir);
  }
}
