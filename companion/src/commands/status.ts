import type { Context } from '../context.js';
import { formatExpiry } from '../messages.js';
import { EXIT_OK, type Output } from '../output.js';
import { compareRoots, describeRootsMismatch } from '../roots.js';
import { NO_WRITABLE_MESSAGE, resolveWritable, sanitizeServerRoots } from '../writable.js';

export async function statusCommand(ctx: Context, out: Output): Promise<number> {
  const st = await ctx.client.status();
  await resolveWritable(ctx, { status: st });
  out.info(
    ctx.config.env === null
      ? `Sito: ${ctx.config.siteUrl}`
      : `Ambiente: ${ctx.config.env}${ctx.config.autoDeploy ? '' : ' (protetto: niente deploy automatici)'} — ${ctx.config.siteUrl} (altri: ${ctx.config.envs.filter((e) => e !== ctx.config.env).join(', ') || 'nessuno'})`,
  );
  if (st.mode === 'off') {
    out.info('Modalità: off — attivala dal pannello Dev Bridge o con `wp devbridge enable --mode=read --hours=N`');
    return EXIT_OK;
  }
  out.info(`Modalità: ${st.mode}, ${formatExpiry(st.expires_at)}`);
  const theme = st.theme.template && st.theme.template !== st.theme.stylesheet ? `${st.theme.stylesheet} (padre ${st.theme.template})` : st.theme.stylesheet;
  out.info(`WordPress ${st.wp} · PHP ${st.php} · Dev Bridge ${st.plugin} · tema ${theme}`);
  if (st.network) {
    out.info(`Rete multisite: ${st.network.sites} siti, principale ${st.network.main_site} (impostazioni e deploy valgono per tutta la rete)`);
  }
  if (ctx.config.writableFromSite) {
    if (ctx.config.writable.length) {
      out.info(`Cartelle scrivibili (dal sito): ${ctx.config.writable.join(', ')}`);
    } else {
      out.warn(NO_WRITABLE_MESSAGE);
    }
  } else {
    out.info(`Cartelle scrivibili (limitate da wpdev.json): ${ctx.config.writable.join(', ')}`);
    const { warnings, notes } = describeRootsMismatch(compareRoots(ctx.config.writable, sanitizeServerRoots(st.writable_roots)));
    warnings.forEach((m) => out.warn(m));
    notes.forEach((m) => out.info(m));
  }
  if (st.rescue !== undefined && st.rescue !== 'installed') {
    out.warn(`mu-plugin rescue ${st.rescue === 'outdated' ? 'non aggiornato' : 'non installato'} sul server: il rollback fuori banda (wpdev rollback --rescue) potrebbe non essere disponibile`);
  }
  return EXIT_OK;
}
