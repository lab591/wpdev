import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import type { Context } from '../context.js';
import { xxh128File } from '../hash.js';
import type { ManifestFile } from '../http.js';
import { EXIT_ERROR, EXIT_OK, type Output } from '../output.js';
import { isAncestor, isInside, normalizeRel, toNative } from '../paths.js';
import { scanTree, type LocalFile } from '../scan.js';
import { State } from '../state.js';
import { buildPlan, deleteLocalFile, downloadFiles, fetchRemote, localHasher } from '../sync.js';
import { NO_WRITABLE_MESSAGE } from '../writable.js';
import { LABEL } from './diff.js';

export interface PullOptions {
  path?: string;
  force?: boolean;
}

export const READONLY_DIR = 'readonly';

export async function pullCommand(ctx: Context, out: Output, options: PullOptions = {}): Promise<number> {
  if (options.path !== undefined) {
    return pullReadonly(ctx, out, normalizeRel(options.path));
  }
  const { config, client } = ctx;
  if (config.writable.length === 0) {
    out.warn(NO_WRITABLE_MESSAGE);
    return EXIT_ERROR;
  }
  const base = config.projectRoot;
  const state = await State.load(config.stateDir);
  const remote = await fetchRemote(client, config.writable, config.exclude, out.warn);
  const local = new Map<string, LocalFile>();
  for (const root of config.writable) {
    for (const [p, f] of await scanTree(base, root, config.exclude)) {
      local.set(p, f);
    }
  }
  const plan = await buildPlan({ local, remote, state, roots: config.writable, hashLocal: localHasher(base, local, state) });

  const toDownload: ManifestFile[] = [...plan.download];
  const toDelete: string[] = [...plan.deleteLocal];
  if (options.force) {
    for (const c of plan.conflicts) {
      if (c.remote) toDownload.push(c.remote);
      else toDelete.push(c.p);
    }
  }

  const written = await downloadFiles(client, base, toDownload);
  for (const w of written) {
    state.set({ p: w.p, h_base: w.h, s: w.s, m: w.m });
  }
  for (const p of toDelete) {
    const root = config.writable.find((r) => isInside(p, r, false)) ?? '.';
    await deleteLocalFile(base, p, root);
    state.delete(p);
  }
  for (const { p, h } of plan.converged) {
    const f = local.get(p) as LocalFile;
    state.set({ p, h_base: h, s: f.s, m: f.m });
  }
  for (const p of plan.touched) {
    const entry = state.get(p);
    const f = local.get(p);
    if (entry && f) state.set({ ...entry, s: f.s, m: f.m });
  }
  plan.forget.forEach((p) => state.delete(p));
  await state.save();
  // Writable folders that are still empty on the site (e.g. a new plugin): create them locally too.
  for (const root of config.writable) {
    await mkdir(toNative(base, root), { recursive: true });
  }

  out.info(`Scaricati ${written.length} file, rimossi ${toDelete.length} in locale.`);
  if (plan.localModified.length) {
    out.info(`${plan.localModified.length} file modificati solo in locale (non toccati): usa "wpdev diff" per l'elenco.`);
  }
  if (plan.conflicts.length && !options.force) {
    out.warn(`${plan.conflicts.length} conflitti (modificati sia in locale sia sul server), non sovrascritti:`);
    plan.conflicts.slice(0, 20).forEach((c) => out.warn(`  ${c.p} (locale: ${LABEL[c.local]}, server: ${LABEL[c.server]})`));
    if (plan.conflicts.length > 20) out.warn(`  … e altri ${plan.conflicts.length - 20}`);
    out.warn('Risolvi a mano oppure usa "wpdev pull --force" per prendere la versione del server.');
    return EXIT_ERROR;
  }
  return EXIT_OK;
}

/** Mirrors a read-only folder of the site into `.wpdev/readonly/` (never deployed). */
async function pullReadonly(ctx: Context, out: Output, rel: string): Promise<number> {
  const { config, client } = ctx;
  if (config.writable.some((r) => isInside(rel, r) || isAncestor(rel, r))) {
    out.warn(`${rel} contiene o è dentro una cartella scrivibile: usa "wpdev pull" senza --path`);
    return EXIT_ERROR;
  }
  const base = path.join(config.stateDir, READONLY_DIR);
  const remote = await fetchRemote(client, [rel], config.exclude, out.warn);
  const local = await scanTree(base, rel, config.exclude);
  const toDownload: ManifestFile[] = [];
  for (const [p, r] of remote) {
    const l = local.get(p);
    if (!l || l.s !== r.s || (await xxh128File(toNative(base, p))) !== r.h) {
      toDownload.push(r);
    }
  }
  const written = await downloadFiles(client, base, toDownload);
  let removed = 0;
  for (const p of local.keys()) {
    if (!remote.has(p)) {
      await deleteLocalFile(base, p, rel);
      removed += 1;
    }
  }
  out.info(`${rel}: ${remote.size} file sul server, scaricati ${written.length}, rimossi ${removed} (in ${path.relative(config.projectRoot, base).split(path.sep).join('/')}/, sola lettura).`);
  return EXIT_OK;
}
