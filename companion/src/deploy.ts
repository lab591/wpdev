import { readFile, stat } from 'node:fs/promises';
import { zipSync, type Zippable } from 'fflate';
import type { Config } from './config.js';
import type { Context } from './context.js';
import { matchAny } from './glob.js';
import { xxh128 } from './hash.js';
import type { DeployManifest, DeployResponse } from './http.js';
import { lintPhp, type LintResult, type Runner } from './lint.js';
import { toNative } from './paths.js';
import { deleteRescue, loadRescue, saveRescue } from './rescue.js';
import { scanTree } from './scan.js';
import { State } from './state.js';
import { localHasher } from './sync.js';

/** A local change relative to `.wpdev/state.json`. */
export interface Change {
  p: string;
  status: 'new' | 'modified' | 'deleted';
  /** Hash of the local content (writes only). */
  h?: string;
  /** Hash of the server file at the last sync (null: new file). */
  base_h: string | null;
}

/** Local files in `writable` compared with the state: new, modified and (if allowed) deleted. */
export async function computeChanges(config: Config, state: State): Promise<Change[]> {
  const changes: Change[] = [];
  for (const root of config.writable) {
    const local = await scanTree(config.projectRoot, root, config.exclude);
    const hash = localHasher(config.projectRoot, local, state);
    for (const p of local.keys()) {
      const entry = state.get(p);
      const h = await hash(p);
      if (!entry) {
        changes.push({ p, status: 'new', h, base_h: null });
      } else if (h !== entry.h_base) {
        changes.push({ p, status: 'modified', h, base_h: entry.h_base });
      }
    }
    if (config.deploy.allowDelete) {
      for (const entry of state.entriesUnder(root)) {
        if (!local.has(entry.p) && !matchAny(config.exclude, entry.p)) {
          changes.push({ p: entry.p, status: 'deleted', base_h: entry.h_base });
        }
      }
    }
  }
  return changes.sort((a, b) => (a.p < b.p ? -1 : a.p > b.p ? 1 : 0));
}

export interface Bundle {
  manifest: DeployManifest;
  zip: Uint8Array | undefined;
  /** Local stat of each written file at read time (for the state update). */
  stats: Map<string, { h: string; s: number; m: number }>;
}

/** Reads the changed files from disk (exact bytes) and builds manifest and zip. */
export async function buildBundle(config: Config, changes: readonly Change[], force: boolean): Promise<Bundle> {
  const entries: Zippable = {};
  const stats = new Map<string, { h: string; s: number; m: number }>();
  const files: DeployManifest['files'] = [];
  let writes = 0;
  for (const c of changes) {
    if (c.status === 'deleted') {
      files.push({ p: c.p, action: 'delete', base_h: c.base_h });
      continue;
    }
    const abs = toNative(config.projectRoot, c.p);
    const st = await stat(abs);
    const data = new Uint8Array(await readFile(abs));
    const h = await xxh128(data); // hash of the exact bytes sent, even if the file changed meanwhile
    entries[c.p] = data;
    stats.set(c.p, { h, s: st.size, m: Math.trunc(st.mtimeMs) });
    files.push({ p: c.p, action: 'write', h, base_h: c.base_h });
    writes++;
  }
  return { manifest: { files, force }, zip: writes > 0 ? zipSync(entries, { level: 6 }) : undefined, stats };
}

export type DeployOutcome =
  | { kind: 'no_changes' }
  | { kind: 'lint_failed'; changes: Change[]; lint: LintResult }
  | { kind: 'dry_run'; changes: Change[]; lint: LintResult | undefined }
  | { kind: 'done'; changes: Change[]; lint: LintResult | undefined; response: DeployResponse; rescueWarning?: string }
  | { kind: 'failed'; changes: Change[]; lint: LintResult | undefined; error: unknown; rescueWarning?: string };

export interface DeployOptions {
  dryRun?: boolean;
  force?: boolean;
}

export interface DeployDeps {
  runner?: Runner;
}

export const RESCUE_MISSING_WARNING = 'mu-plugin rescue non installato sul server: se un errore fatale sfugge all\'health check il rollback fuori banda non sarà disponibile';

/**
 * The whole companion-side deploy (SPEC 3.5). Never throws for server errors:
 * they are returned as `failed` so CLI, hook and MCP can format them.
 */
export async function runDeploy(ctx: Context, options: DeployOptions = {}, deps: DeployDeps = {}): Promise<DeployOutcome> {
  const { config, client } = ctx;
  const state = await State.load(config.projectRoot);
  const changes = await computeChanges(config, state);
  if (changes.length === 0) {
    return { kind: 'no_changes' };
  }

  let lint: LintResult | undefined;
  if (config.deploy.lintPhp) {
    const php = changes.filter((c) => c.status !== 'deleted' && c.p.toLowerCase().endsWith('.php'));
    if (php.length) {
      lint = await lintPhp(config.php, php.map((c) => [c.p, toNative(config.projectRoot, c.p)] as [string, string]), deps.runner);
      if (lint.errors.length) {
        return { kind: 'lint_failed', changes, lint };
      }
    }
  }
  if (options.dryRun) {
    return { kind: 'dry_run', changes, lint };
  }

  let rescueWarning: string | undefined;
  try {
    if (!(await loadRescue(config.projectRoot))) {
      // First deploy from this workspace: make sure the out-of-band safety net exists.
      const st = await client.status();
      if (st.mode !== 'off' && st.rescue !== undefined && st.rescue !== 'installed') {
        rescueWarning = RESCUE_MISSING_WARNING;
      }
    }
    const bundle = await buildBundle(config, changes, options.force === true);
    const response = await client.deploy(bundle.manifest, bundle.zip);
    if (response.status === 'ok' || response.status === 'health_unknown') {
      for (const c of changes) {
        if (c.status === 'deleted') {
          state.delete(c.p);
        } else {
          const s = bundle.stats.get(c.p);
          if (s) state.set({ p: c.p, h_base: s.h, s: s.s, m: s.m });
        }
      }
      await state.save();
      // No release (content already on the server): the previous token is still the valid one.
      if (response.release_id !== null) {
        if (response.rescue_token) {
          await saveRescue(config.projectRoot, response.release_id, response.rescue_token);
        } else {
          await deleteRescue(config.projectRoot); // any previous token is no longer valid
        }
      }
    }
    return rescueWarning ? { kind: 'done', changes, lint, response, rescueWarning } : { kind: 'done', changes, lint, response };
  } catch (error) {
    return rescueWarning ? { kind: 'failed', changes, lint, error, rescueWarning } : { kind: 'failed', changes, lint, error };
  }
}

/** Short change counts: "2 nuovi, 1 modificati, 0 cancellati". */
export function countChanges(changes: readonly Change[]): { new: number; modified: number; deleted: number } {
  return {
    new: changes.filter((c) => c.status === 'new').length,
    modified: changes.filter((c) => c.status === 'modified').length,
    deleted: changes.filter((c) => c.status === 'deleted').length,
  };
}
