import type { Config } from '../config.js';
import type { Context } from '../context.js';
import { ApiError, type RestoredFile } from '../http.js';
import { describeError } from '../messages.js';
import { EXIT_ERROR, EXIT_OK, type Output } from '../output.js';
import { deleteRescue, loadRescue } from '../rescue.js';
import { State } from '../state.js';
import { toNative } from '../paths.js';
import { stat } from 'node:fs/promises';
import { xxh128File } from '../hash.js';

export interface RollbackOptions {
  releaseId?: string;
  rescue?: boolean;
  force?: boolean;
}

/** Asks a yes/no question; undefined when not interactive. */
export type Confirm = (question: string) => Promise<boolean>;

/**
 * After a rollback the server files are back to their previous content: `h_base` follows
 * the server, so the local copies (still containing the rolled back changes) show up as
 * local modifications ready to be fixed and deployed again.
 */
export async function applyRestored(config: Config, files: readonly RestoredFile[]): Promise<void> {
  const state = await State.load(config.stateDir);
  for (const f of files) {
    if (f.h === null) {
      state.delete(f.p);
      continue;
    }
    const abs = toNative(config.projectRoot, f.p);
    try {
      const st = await stat(abs);
      // Keep the fast path only if the local file already equals the restored content.
      const same = (await xxh128File(abs)) === f.h;
      state.set({ p: f.p, h_base: f.h, s: same ? st.size : -1, m: same ? Math.trunc(st.mtimeMs) : -1 });
    } catch {
      state.set({ p: f.p, h_base: f.h, s: -1, m: -1 });
    }
  }
  await state.save();
}

function isServerDown(e: unknown): boolean {
  return e instanceof ApiError && (e.status >= 500 || e.status === 0 || e.code === 'invalid_response');
}

export async function rescueRollback(ctx: Context, out: Output): Promise<number> {
  const info = await loadRescue(ctx.config.stateDir);
  if (!info) {
    out.warn('Nessun token di rescue salvato in .wpdev/rescue.json: il rescue è disponibile solo per l\'ultimo deploy riuscito da questo progetto.');
    return EXIT_ERROR;
  }
  try {
    const res = await ctx.client.rescue(info.token);
    await applyRestored(ctx.config, res.files);
    await deleteRescue(ctx.config.stateDir);
    out.info(`Rescue eseguito: release ${res.release_id} annullata, ${res.files.length} file ripristinati.`);
    return EXIT_OK;
  } catch (e) {
    if (e instanceof ApiError && e.code === 'rescue_unavailable') {
      out.warn('Rescue non disponibile (token assente/scaduto o plugin rimosso): ripristina i file via FTP/SSH dal backup in storage/releases/.');
    } else {
      out.warn(`Rescue fallito: ${describeError(e)}`);
    }
    return EXIT_ERROR;
  }
}

export async function rollbackCommand(ctx: Context, out: Output, options: RollbackOptions, confirm?: Confirm): Promise<number> {
  if (options.rescue) {
    return rescueRollback(ctx, out);
  }
  try {
    const res = await ctx.client.rollback(options.releaseId, options.force === true);
    await applyRestored(ctx.config, res.files);
    await deleteRescue(ctx.config.stateDir);
    out.info(`Rollback eseguito: ${res.rolled_back.join(', ') || '(nessuna release)'} — ${res.files.length} file ripristinati.`);
    return EXIT_OK;
  } catch (e) {
    if (e instanceof ApiError && e.code === 'conflict') {
      const conflicts = Array.isArray(e.details.conflicts) ? (e.details.conflicts as { p: string; reason: string }[]) : [];
      out.warn('Rollback rifiutato: file modificati sul server dopo la release:');
      conflicts.slice(0, 20).forEach((c) => out.warn(`  ${c.p} (${c.reason})`));
      out.warn('Usa `wpdev rollback --force` per ripristinare comunque.');
      return EXIT_ERROR;
    }
    if (isServerDown(e)) {
      out.warn(`Il server non risponde correttamente (${describeError(e)}): probabilmente un errore fatale blocca WordPress.`);
      if (confirm && (await confirm('Usare il rollback fuori banda (mu-plugin rescue)? [s/N]'))) {
        return rescueRollback(ctx, out);
      }
      out.warn('Prova con `wpdev rollback --rescue`.');
      return EXIT_ERROR;
    }
    out.warn(`Rollback fallito: ${describeError(e)}`);
    return EXIT_ERROR;
  }
}

export async function healthCommand(ctx: Context, out: Output): Promise<number> {
  const h = await ctx.client.health(ctx.config.health.paths);
  const label = h.status === 'ok' ? 'ok' : h.status === 'fail' ? 'FALLITO' : 'sconosciuto';
  out.info(`Health check: ${label}${h.message ? ` — ${h.message}` : ''}`);
  h.checks.forEach((c) =>
    out.info(`  ${c.url}${c.source === 'agent' ? ' [wpdev.json]' : ''}  ${c.code ?? c.error ?? '?'}${c.ms !== undefined ? ` (${c.ms} ms)` : ''}`),
  );
  if (h.errors?.length) {
    out.warn('Errori fatali recenti nel debug.log:');
    h.errors.slice(0, 20).forEach((l) => out.warn(`  ${l}`));
  }
  return h.status === 'fail' ? EXIT_ERROR : EXIT_OK;
}
