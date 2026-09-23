import type { Context } from '../context.js';
import type { ManifestFile } from '../http.js';
import { EXIT_ERROR, EXIT_OK, type Output } from '../output.js';
import { isInside, normalizeRel } from '../paths.js';
import { scanTree, type LocalFile } from '../scan.js';
import { State } from '../state.js';
import { deleteLocalFile, downloadFiles, fetchRemote, localHasher } from '../sync.js';
import { NO_WRITABLE_MESSAGE } from '../writable.js';

export interface RestoreResult {
  restored: string[];
  removed: string[];
  unchanged: number;
}

/**
 * Brings local files (or whole folders) of the writable roots back to the server version,
 * discarding local changes: modified files are downloaded again, files that exist only locally
 * are removed. Nothing is sent to the server.
 */
export async function restorePaths(ctx: Context, inputs: readonly string[]): Promise<RestoreResult> {
  const { config, client } = ctx;
  if (config.writable.length === 0) throw new Error(NO_WRITABLE_MESSAGE);
  const targets = inputs.map((i) => normalizeRel(i));
  for (const t of targets) {
    if (!config.writable.some((r) => isInside(t, r, false) || t.toLowerCase() === r.toLowerCase())) {
      throw new Error(`${t} non è dentro una cartella scrivibile (${config.writable.join(', ')})`);
    }
  }
  const selected = (p: string): boolean => targets.some((t) => p.toLowerCase() === t.toLowerCase() || isInside(p, t, false));
  const roots = config.writable.filter((r) => targets.some((t) => isInside(t, r, false) || t.toLowerCase() === r.toLowerCase()));

  const base = config.projectRoot;
  const state = await State.load(base);
  const remote = await fetchRemote(client, roots, config.exclude);
  const local = new Map<string, LocalFile>();
  for (const root of roots) {
    for (const [p, f] of await scanTree(base, root, config.exclude)) local.set(p, f);
  }
  const hash = localHasher(base, local, state);

  const toDownload: ManifestFile[] = [];
  let unchanged = 0;
  for (const [p, f] of remote) {
    if (!selected(p)) continue;
    if (local.has(p) && (await hash(p)) === f.h) {
      unchanged++;
      continue;
    }
    toDownload.push(f);
  }
  const toRemove = [...local.keys()].filter((p) => selected(p) && !remote.has(p));

  const written = await downloadFiles(client, base, toDownload);
  for (const w of written) state.set({ p: w.p, h_base: w.h, s: w.s, m: w.m });
  for (const p of toRemove) {
    await deleteLocalFile(base, p, roots.find((r) => isInside(p, r, false)) ?? '.');
    state.delete(p);
  }
  await state.save();
  return { restored: written.map((w) => w.p), removed: toRemove, unchanged };
}

export async function restoreCommand(ctx: Context, out: Output, inputs: readonly string[]): Promise<number> {
  try {
    const r = await restorePaths(ctx, inputs);
    r.restored.forEach((p) => out.info(`  ripristinato  ${p}`));
    r.removed.forEach((p) => out.info(`  rimosso       ${p} (esisteva solo in locale)`));
    out.info(`Ripristinati ${r.restored.length} file dalla versione del server, rimossi ${r.removed.length}, già uguali ${r.unchanged}.`);
    return EXIT_OK;
  } catch (e) {
    out.warn((e as Error).message);
    return EXIT_ERROR;
  }
}
