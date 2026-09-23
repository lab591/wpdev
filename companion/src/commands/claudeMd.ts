import type { Context } from '../context.js';
import { describeError } from '../messages.js';
import { EXIT_ERROR, EXIT_OK, type Output } from '../output.js';
import { describeClaudeMdResult, writeClaudeMd } from '../scaffold.js';
import { resolveWritable } from '../writable.js';

/**
 * `wpdev claude-md [--force]`: regenerates the wpdev section of CLAUDE.md with the current
 * site data (name, writable folders, multisite network), leaving the rest of the file alone.
 */
export async function claudeMdCommand(ctx: Context, out: Output, force = false): Promise<number> {
  let name = new URL(ctx.config.siteUrl).host;
  let network: { mainSite: string; sites: number } | undefined;
  try {
    const st = await ctx.client.status();
    await resolveWritable(ctx, { status: st });
    if (st.mode !== 'off') {
      if (st.name) name = st.name;
      if (st.network) network = { mainSite: st.network.main_site, sites: st.network.sites };
    } else {
      out.warn('Modalità sviluppo off: uso le ultime cartelle scrivibili note (attivala per dati aggiornati).');
    }
  } catch (e) {
    out.warn(`Sito non raggiungibile (${describeError(e)}): uso le ultime cartelle scrivibili note.`);
  }
  const result = describeClaudeMdResult(
    await writeClaudeMd(
      ctx.config.projectRoot,
      { name, url: ctx.config.siteUrl, writable: ctx.config.writable, writableFromSite: ctx.config.writableFromSite, previewTarget: ctx.config.deploy.target === 'preview', ...(network ? { network } : {}) },
      force,
    ),
  );
  if (result.warn) {
    out.warn(result.text);
    return EXIT_ERROR;
  }
  out.info(result.text);
  return EXIT_OK;
}
