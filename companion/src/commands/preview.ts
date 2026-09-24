import type { Context } from '../context.js';
import { runDeploy, type DeployDeps } from '../deploy.js';
import { describeError } from '../messages.js';
import { EXIT_DEPLOY_FAILED, EXIT_ERROR, EXIT_OK, type Output } from '../output.js';
import { publishPreview } from '../preview.js';
import { cacheLines, healthLine, reportDeploy, unknownHealthLine } from './deploy.js';

/** `wpdev preview [publish|discard|status]` (0.5.0). */
export async function previewCommand(ctx: Context, out: Output, action: string | undefined, deps: DeployDeps = {}): Promise<number> {
  try {
    switch (action ?? 'create') {
      case 'create': {
        const report = reportDeploy(await runDeploy(ctx, { target: 'preview' }, deps));
        report.stdout.forEach((l) => out.info(l));
        report.stderr.forEach((l) => out.warn(l));
        return report.code;
      }
      case 'status': {
        const st = await ctx.client.previewStatus();
        if (!st.active) {
          out.info('Nessuna anteprima attiva.');
          return EXIT_OK;
        }
        out.info(`Anteprima di ${st.units.join(', ')} — ${st.files.length} file, ${st.expired ? 'link scaduto (ricreala con "wpdev preview")' : `link valido fino a ${new Date(st.expires_at * 1000).toLocaleString('it-IT')}`}.`);
        st.files.slice(0, 50).forEach((f) => out.info(`  ${f}`));
        return EXIT_OK;
      }
      case 'publish': {
        const { response, git } = await publishPreview(ctx);
        if (response.status === 'rolled_back') {
          out.warn(`Pubblicazione annullata automaticamente: health check fallito (${healthLine(response.health)}). Il sito live è tornato com'era; l'anteprima resta disponibile.`);
          (response.errors ?? []).slice(0, 20).forEach((e) => out.warn(`  ${e}`));
          return EXIT_DEPLOY_FAILED;
        }
        out.info(`Anteprima pubblicata: release ${response.release_id}, ${response.written} file scritti, ${response.deleted} cancellati.`);
        out.info(healthLine(response.health));
        if (git?.hash) out.info(`Commit git ${git.hash}.`);
        if (response.status === 'health_unknown') out.warn(unknownHealthLine(response));
        cacheLines(response.cache).forEach((l) => out.warn(l));
        return EXIT_OK;
      }
      case 'discard':
        await ctx.client.previewDiscard();
        out.info('Anteprima scartata: il sito live non è cambiato.');
        return EXIT_OK;
      default:
        out.warn('Uso: wpdev preview [publish|discard|status]');
        return EXIT_ERROR;
    }
  } catch (e) {
    out.warn(describeError(e));
    return EXIT_ERROR;
  }
}
