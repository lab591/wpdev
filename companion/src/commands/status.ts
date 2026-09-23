import type { Context } from '../context.js';
import { formatExpiry } from '../messages.js';
import { EXIT_OK, type Output } from '../output.js';
import { compareRoots, describeRootsMismatch } from '../roots.js';

export async function statusCommand(ctx: Context, out: Output): Promise<number> {
  const st = await ctx.client.status();
  out.info(`Sito: ${ctx.config.siteUrl}`);
  if (st.mode === 'off') {
    out.info('Modalità: off — attivala dal pannello Dev Bridge o con `wp devbridge enable --mode=read --hours=N`');
    return EXIT_OK;
  }
  out.info(`Modalità: ${st.mode}, ${formatExpiry(st.expires_at)}`);
  const theme = st.theme.template && st.theme.template !== st.theme.stylesheet ? `${st.theme.stylesheet} (padre ${st.theme.template})` : st.theme.stylesheet;
  out.info(`WordPress ${st.wp} · PHP ${st.php} · Dev Bridge ${st.plugin} · tema ${theme}`);
  const cmp = compareRoots(ctx.config.writable, st.writable_roots);
  const mismatch = describeRootsMismatch(cmp);
  if (mismatch.length === 0) {
    out.info(`Root scrivibili: ${st.writable_roots.length ? st.writable_roots.join(', ') : '(nessuna)'} — coerenti con wpdev.json`);
  } else {
    mismatch.forEach((m) => out.warn(m));
  }
  if (st.rescue !== undefined && st.rescue !== 'installed') {
    out.warn(`mu-plugin rescue ${st.rescue === 'outdated' ? 'non aggiornato' : 'non installato'} sul server: il rollback fuori banda (wpdev rollback --rescue) potrebbe non essere disponibile`);
  }
  return EXIT_OK;
}
