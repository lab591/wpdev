import type { Context } from '../context.js';
import { AUTOCRLF_WARNING, autocrlfRisk } from '../git.js';
import { EXIT_OK, type Output } from '../output.js';
import { scanTree, type LocalFile } from '../scan.js';
import { State } from '../state.js';
import { buildPlan, fetchRemote, localHasher, type SideStatus, type SyncPlan } from '../sync.js';

export const LABEL: Record<SideStatus, string> = {
  added: 'nuovo',
  modified: 'modificato',
  deleted: 'cancellato',
  unchanged: 'invariato',
  absent: 'assente',
};

export async function computeDiff(ctx: Context, warn: (m: string) => void): Promise<SyncPlan> {
  const { config, client } = ctx;
  const base = config.projectRoot;
  const state = await State.load(base);
  const local = new Map<string, LocalFile>();
  for (const root of config.writable) {
    for (const [p, f] of await scanTree(base, root, config.exclude)) local.set(p, f);
  }
  const remote = await fetchRemote(client, config.writable, config.exclude, warn);
  return buildPlan({ local, remote, state, roots: config.writable, hashLocal: localHasher(base, local, state) });
}

export async function diffCommand(ctx: Context, out: Output): Promise<number> {
  if (await autocrlfRisk(ctx.config.projectRoot)) {
    out.warn(AUTOCRLF_WARNING);
  }
  const plan = await computeDiff(ctx, out.warn);
  const serverSide = [
    ...plan.download.map((f) => ({ p: f.p, label: 'modificato/nuovo' })),
    ...plan.deleteLocal.map((p) => ({ p, label: 'cancellato' })),
  ].sort((a, b) => a.p.localeCompare(b.p));

  out.info(`Modificati in locale (${plan.localModified.length}):`);
  plan.localModified.forEach((f) => out.info(`  ${LABEL[f.status]}  ${f.p}`));
  out.info(`Modificati sul server (${serverSide.length}):`);
  serverSide.forEach((f) => out.info(`  ${f.label}  ${f.p}`));
  out.info(`In conflitto (${plan.conflicts.length}):`);
  plan.conflicts.forEach((c) => out.info(`  ${c.p}  (locale: ${LABEL[c.local]}, server: ${LABEL[c.server]})`));
  return EXIT_OK;
}
