import type { Context } from '../context.js';
import { countChanges, runDeploy, type Change, type DeployDeps, type DeployOutcome } from '../deploy.js';
import { ApiError, type HealthResult } from '../http.js';
import type { LintError } from '../lint.js';
import { describeError } from '../messages.js';
import { EXIT_DEPLOY_FAILED, EXIT_ERROR, EXIT_OK, type Output } from '../output.js';

export interface DeployCommandOptions {
  dryRun?: boolean;
  force?: boolean;
}

const STATUS_LABEL: Record<Change['status'], string> = { new: 'nuovo', modified: 'modificato', deleted: 'cancellato' };
const MAX_LISTED = 20;

export const MODE_WRITE_HINT = 'attiva la modalità write dal pannello o con `wp devbridge enable --mode=write --hours=N`';

function summary(changes: readonly Change[]): string {
  const c = countChanges(changes);
  return `${c.new} nuovi, ${c.modified} modificati, ${c.deleted} cancellati`;
}

function lintLines(errors: readonly LintError[]): string[] {
  return errors.map((e) => `  ${e.p}${e.line ? `:${e.line}` : ''}  ${e.message}`);
}

/** New PHP warnings of the deployed files (the deploy stays online: they are to be fixed, not rolled back). */
export function warningLines(h: HealthResult): string[] {
  if (!h.warnings?.length) return [];
  return [
    `Avvisi PHP nuovi nei file appena pubblicati (${h.warnings.length}): il deploy resta online, ma conviene correggerli:`,
    ...h.warnings.slice(0, MAX_LISTED).map((w) => `  ${w}`),
  ];
}

export function healthLine(h: HealthResult): string {
  const checks = h.checks
    .map((c) => `${c.url}${c.source === 'agent' ? ' [wpdev.json]' : c.source === 'backend' ? ' [backend]' : ''} ${c.code ?? c.error ?? '?'}${c.ms !== undefined ? ` (${c.ms} ms)` : ''}`)
    .join(', ');
  const label = h.status === 'ok' ? 'ok' : h.status === 'fail' ? 'FALLITO' : 'sconosciuto';
  return `Health check: ${label}${checks ? ` — ${checks}` : ''}${h.message ? ` — ${h.message}` : ''}`;
}

/** Human description of a failed deploy request (Italian), with what to do next. */
export function describeDeployError(error: unknown): string[] {
  if (error instanceof ApiError) {
    switch (error.code) {
      case 'conflict': {
        const conflicts = Array.isArray(error.details.conflicts) ? (error.details.conflicts as { p: string; reason: string }[]) : [];
        return [
          `Conflitto: ${conflicts.length || 'alcuni'} file modificati sul server dopo l'ultima sincronizzazione:`,
          ...conflicts.slice(0, MAX_LISTED).map((c) => `  ${c.p} (${c.reason})`),
          'Esegui `wpdev pull` per allinearti (o `wpdev deploy --force` per sovrascrivere il server).',
        ];
      }
      case 'deploy_locked':
        return ['Un altro deploy o rollback è in corso sul server: riprova tra poco.'];
      case 'mode_off':
      case 'mode_insufficient':
        return [`Modalità write non attiva sul server: ${MODE_WRITE_HINT}.`];
      default: {
        const where = typeof error.details.path === 'string' ? ` (${error.details.path})` : '';
        return [`Deploy rifiutato: ${describeError(error)}${where}`];
      }
    }
  }
  return [`Deploy non riuscito: ${describeError(error)}`];
}

export interface Report {
  code: number;
  stdout: string[];
  stderr: string[];
}

export const PARSER_NOTE =
  'Controllo eseguito con il parser PHP integrato (PHP non è installato su questo computer). Se il codice è corretto ma usa sintassi molto vecchia (es. "clone( $x )"), installa PHP o imposta "deploy": { "lintPhp": false } in wpdev.json.';

/** Builds the CLI report of a deploy outcome. */
export function reportDeploy(outcome: DeployOutcome): Report {
  const r: Report = { code: EXIT_OK, stdout: [], stderr: [] };
  if (outcome.kind === 'no_changes') {
    r.stdout.push('Nessuna modifica da pubblicare.');
    return r;
  }
  if (outcome.lint?.skipped) r.stderr.push(outcome.lint.skipped);
  if ('rescueWarning' in outcome && outcome.rescueWarning) r.stderr.push(outcome.rescueWarning);

  switch (outcome.kind) {
    case 'lint_failed':
      r.code = EXIT_ERROR;
      r.stderr.push(`Errori di sintassi PHP: deploy bloccato prima dell'upload (nessun file pubblicato).`, ...lintLines(outcome.lint.errors));
      if (outcome.lint.engine === 'parser') r.stderr.push(PARSER_NOTE);
      return r;
    case 'dry_run':
      r.stdout.push(`Modifiche da pubblicare (${summary(outcome.changes)}):`);
      outcome.changes.slice(0, 200).forEach((c) => r.stdout.push(`  ${STATUS_LABEL[c.status].padEnd(10)} ${c.p}`));
      if (outcome.changes.length > 200) r.stdout.push(`  ... e altri ${outcome.changes.length - 200}`);
      if (outcome.lint && !outcome.lint.skipped) r.stdout.push(`Lint PHP: ${outcome.lint.checked} file ok${outcome.lint.engine === 'parser' ? ' (parser integrato, PHP non installato)' : ''}.`);
      r.stdout.push('Dry run: nessun file inviato.');
      return r;
    case 'failed':
      r.code = EXIT_ERROR;
      r.stderr.push(...describeDeployError(outcome.error));
      return r;
    case 'done': {
      const res = outcome.response;
      if (res.status === 'rolled_back') {
        r.code = EXIT_DEPLOY_FAILED;
        r.stderr.push(`Release ${res.release_id}: health check fallito, rollback automatico eseguito (il sito è tornato alla versione precedente).`);
        r.stderr.push(healthLine(res.health));
        if (res.errors?.length) {
          r.stderr.push('Errori dal debug.log:', ...res.errors.slice(0, MAX_LISTED).map((e) => `  ${e}`));
        }
        r.stderr.push('Correggi i file locali e ripubblica (vedi anche `wpdev log`).');
        return r;
      }
      if (res.release_id === null) {
        r.stdout.push('Il server aveva già questi contenuti: nessuna release creata (stato locale allineato).');
        return r;
      }
      r.stdout.push(`Release ${res.release_id}: ${res.written} file scritti, ${res.deleted} cancellati.`);
      r.stdout.push(healthLine(res.health));
      if (outcome.git?.hash) r.stdout.push(`Commit git ${outcome.git.hash} (solo i file pubblicati).`);
      if (outcome.git?.error) r.stderr.push(`Commit git non riuscito: ${outcome.git.error} (il deploy è comunque online).`);
      if (res.status === 'health_unknown') {
        r.stderr.push('ATTENZIONE: health check non eseguibile (loopback non raggiungibile): nessun rollback automatico. Verifica il sito a mano; in caso di problemi `wpdev rollback`.');
      }
      if (res.errors?.length) {
        r.stderr.push('Righe fatali nel debug.log:', ...res.errors.slice(0, MAX_LISTED).map((e) => `  ${e}`));
      }
      r.stderr.push(...warningLines(res.health));
      return r;
    }
  }
}

export async function deployCommand(ctx: Context, out: Output, options: DeployCommandOptions, deps: DeployDeps = {}): Promise<number> {
  const outcome = await runDeploy(ctx, options, deps);
  const report = reportDeploy(outcome);
  report.stdout.forEach((l) => out.info(l));
  report.stderr.forEach((l) => out.warn(l));
  return report.code;
}

// ---------------------------------------------------------------- Stop hook

/** Subset of the JSON that Claude Code sends to Stop hooks on stdin. */
export interface HookInput {
  session_id?: string;
  cwd?: string;
  hook_event_name?: string;
  stop_hook_active?: boolean;
}

/** Reads the hook JSON from stdin without ever blocking: TTY → nothing, otherwise up to `timeoutMs`. */
export function readHookInput(stream: NodeJS.ReadStream = process.stdin, timeoutMs = 2000): Promise<HookInput> {
  if (stream.isTTY) return Promise.resolve({});
  return new Promise((resolve) => {
    const chunks: Buffer[] = [];
    let done = false;
    const finish = (): void => {
      if (done) return;
      done = true;
      clearTimeout(timer);
      stream.removeListener('data', onData);
      stream.removeListener('end', finish);
      stream.removeListener('error', finish);
      stream.pause();
      try {
        const data = JSON.parse(Buffer.concat(chunks).toString('utf8')) as unknown;
        resolve(data && typeof data === 'object' ? (data as HookInput) : {});
      } catch {
        resolve({});
      }
    };
    const onData = (c: Buffer | string): void => {
      chunks.push(typeof c === 'string' ? Buffer.from(c) : c);
    };
    const timer = setTimeout(finish, timeoutMs);
    stream.on('data', onData);
    stream.once('end', finish);
    stream.once('error', finish);
    stream.resume();
  });
}

export interface HookStreams {
  stdout: (line: string) => void;
  stderr: (line: string) => void;
}

/**
 * `wpdev deploy --hook`: silent without changes, one line on success; on failure a
 * compact summary on stderr and exit 2 so that Claude sees it and can fix the code.
 * If the hook already blocked once (`stop_hook_active`), a new failure exits 0 to avoid loops.
 */
export async function hookDeploy(
  getContext: () => Context | Promise<Context>,
  input: HookInput,
  streams: HookStreams,
  deps: DeployDeps = {},
): Promise<number> {
  let report: Report;
  let outcome: DeployOutcome | undefined;
  try {
    outcome = await runDeploy(await getContext(), {}, deps);
    report = reportDeploy(outcome);
  } catch (e) {
    report = { code: EXIT_ERROR, stdout: [], stderr: [`Deploy non eseguito: ${describeError(e)}`] };
  }
  if (outcome?.kind === 'no_changes') {
    return EXIT_OK;
  }
  const warnings = outcome?.kind === 'done' ? warningLines(outcome.response.health) : [];
  if (report.code === EXIT_OK && warnings.length && !input.stop_hook_active) {
    // Deploy online but the new code raises warnings: tell Claude once (exit 2), no rollback.
    [`wpdev: ${report.stdout[0] ?? 'deploy completato'}`, ...warnings, 'Correggi gli avvisi nei file locali: verranno ripubblicati a fine turno.'].forEach((l) => streams.stderr(l));
    return EXIT_DEPLOY_FAILED;
  }
  if (report.code === EXIT_OK) {
    streams.stdout(`wpdev: ${report.stdout[0] ?? 'deploy completato'} ${report.stdout[1] ?? ''}`.trim());
    // Warnings (health unknown, rescue missing) must reach the user but do not block.
    report.stderr.forEach((l) => streams.stderr(`wpdev: ${l}`));
    return EXIT_OK;
  }
  const lines = ['wpdev deploy (hook Stop) non riuscito:', ...report.stderr, ...report.stdout];
  if (input.stop_hook_active) {
    lines.push('Il deploy è fallito di nuovo dopo un tentativo di correzione: mi fermo per evitare un ciclo. Informa l\'utente.');
    lines.forEach((l) => streams.stderr(l));
    return EXIT_OK;
  }
  lines.push('Le modifiche NON sono online. Correggi e termina il turno per ripubblicare automaticamente.');
  lines.forEach((l) => streams.stderr(l));
  return EXIT_DEPLOY_FAILED;
}
