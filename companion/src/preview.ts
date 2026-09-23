import { stat } from 'node:fs/promises';
import type { Context } from './context.js';
import { countChanges } from './deploy.js';
import { commitPaths, type CommitResult } from './git.js';
import { xxh128File } from './hash.js';
import type { PreviewPublishResponse } from './http.js';
import { toNative } from './paths.js';
import { deleteRescue, saveRescue } from './rescue.js';
import { State } from './state.js';

/**
 * Publishes the preview on the site (a normal deploy there) and aligns the local state with
 * what went live: local files equal to the published ones become the new base.
 */
export async function publishPreview(ctx: Context): Promise<{ response: PreviewPublishResponse; git?: CommitResult }> {
  const { config, client } = ctx;
  const response = await client.previewPublish();
  if (response.status !== 'ok' && response.status !== 'health_unknown') {
    return { response };
  }
  const state = await State.load(config.stateDir);
  for (const f of response.files) {
    if (f.action === 'delete') {
      state.delete(f.p);
      continue;
    }
    const abs = toNative(config.projectRoot, f.p);
    try {
      const st = await stat(abs);
      if (f.h && (await xxh128File(abs)) === f.h) {
        state.set({ p: f.p, h_base: f.h, s: st.size, m: Math.round(st.mtimeMs) });
      }
    } catch {
      // Local file gone meanwhile: the next pull will align it.
    }
  }
  await state.save();
  if (response.release_id !== null) {
    if (response.rescue_token) await saveRescue(config.stateDir, response.release_id, response.rescue_token);
    else await deleteRescue(config.stateDir);
  }
  let git: CommitResult | undefined;
  if (config.deploy.gitCommit && response.release_id) {
    const counts = countChanges(response.files.map((f) => ({ p: f.p, status: f.action === 'delete' ? ('deleted' as const) : ('modified' as const), base_h: f.base_h })));
    git = await commitPaths(
      config.projectRoot,
      response.files.map((f) => ({ p: f.p, deleted: f.action === 'delete' })),
      `wpdev deploy ${response.release_id} (anteprima pubblicata)\n\n${config.siteUrl}: ${counts.modified} scritti, ${counts.deleted} cancellati\n`,
    );
  }
  return git ? { response, git } : { response };
}
