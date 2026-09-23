#!/usr/bin/env node
import { Command, InvalidArgumentError } from 'commander';
import { cacheClearCommand } from './commands/cache.js';
import { claudeMdCommand } from './commands/claudeMd.js';
import { previewCommand } from './commands/preview.js';
import { restoreCommand } from './commands/restore.js';
import { deployCommand, hookDeploy, readHookInput } from './commands/deploy.js';
import { diffCommand } from './commands/diff.js';
import { initCommand, terminalAsk } from './commands/init.js';
import { logCommand } from './commands/log.js';
import { pullCommand } from './commands/pull.js';
import { healthCommand, rollbackCommand, type Confirm } from './commands/rollback.js';
import { statusCommand } from './commands/status.js';
import { loadConfig } from './config.js';
import { createReadyContext, type Context, type GlobalOptions } from './context.js';
import { startMcpServer } from './mcp/server.js';
import { describeError } from './messages.js';
import { INTROSPECT_TOPICS } from './http.js';
import { formatIntrospect } from './mcp/format.js';
import { consoleOutput, EXIT_DEPLOY_FAILED, EXIT_ERROR, EXIT_OK } from './output.js';
import { VERSION } from './version.js';

// Output piped into a command that exits early (e.g. "wpdev status | head -1"): stop quietly.
for (const stream of [process.stdout, process.stderr]) {
  stream.on('error', (e: NodeJS.ErrnoException) => {
    if (e.code === 'EPIPE') process.exit(process.exitCode ?? 0);
    throw e;
  });
}

const program = new Command();

program
  .name('wpdev')
  .description('Dev Bridge companion: lavora in locale su un sito WordPress remoto')
  .version(VERSION)
  .option('--insecure-local', 'accetta http:// solo per localhost, *.local, *.test')
  .option('--env <nome>', 'ambiente di wpdev.json da usare (es. staging, production); default: WPDEV_ENV o defaultEnv')
  .showHelpAfterError();

function globals(): GlobalOptions {
  const opts = program.opts<{ insecureLocal?: boolean; env?: string }>();
  return { insecureLocal: opts.insecureLocal === true, ...(opts.env ? { env: opts.env } : {}) };
}

async function runWithContext(fn: (ctx: Context) => Promise<number>, offline = false): Promise<void> {
  process.exitCode = await fn(await createReadyContext(globals(), offline));
}

function positiveInt(value: string): number {
  const n = Number(value);
  if (!Number.isInteger(n) || n < 1) throw new InvalidArgumentError('serve un intero positivo');
  return n;
}

function collect(value: string, previous: string[] = []): string[] {
  return [...previous, value];
}

program
  .command('init')
  .description('crea (o riusa) wpdev.json, prepara il progetto per Claude Code e prova la connessione')
  .option('--site <url>', 'URL del sito')
  .option('--user <user>', 'utente WordPress')
  .option('--password-env <name>', 'variabile d\'ambiente con la Application Password')
  .option('--writable <path>', 'limita il progetto a questa cartella scrivibile del sito (ripetibile; di default tutte)', collect)
  .option('--force', 'ricrea wpdev.json anche se esiste già')
  .option('-y, --yes', 'non interattivo')
  .action(async (opts: { site?: string; user?: string; passwordEnv?: string; writable?: string[]; force?: boolean; yes?: boolean }) => {
    const interactive = !opts.yes && process.stdin.isTTY === true;
    const term = interactive ? terminalAsk() : undefined;
    try {
      process.exitCode = await initCommand(
        process.cwd(),
        consoleOutput,
        { ...opts, insecureLocal: globals().insecureLocal ?? false },
        term?.ask,
      );
    } finally {
      term?.close();
    }
  });

program
  .command('status')
  .description('modalità e scadenza sul server, cartelle scrivibili')
  .action(() => runWithContext((ctx) => statusCommand(ctx, consoleOutput), true)); // refreshes the list itself

program
  .command('pull')
  .description('scarica le cartelle scrivibili (incrementale) o, con --path, una cartella in sola lettura')
  .option('--path <path>', 'cartella di sola lettura da scaricare in .wpdev/readonly/')
  .option('--force', 'in caso di conflitto prende la versione del server')
  .action((opts: { path?: string; force?: boolean }) => runWithContext((ctx) => pullCommand(ctx, consoleOutput, opts)));

program
  .command('diff')
  .description('file modificati in locale, sul server e in conflitto')
  .action(() => runWithContext((ctx) => diffCommand(ctx, consoleOutput)));

program
  .command('preview [action]')
  .description("anteprima: senza argomenti pubblica le modifiche locali solo per chi ha il link; poi 'publish', 'discard' o 'status'")
  .action((action: string | undefined) =>
    runWithContext((ctx) => previewCommand(ctx, consoleOutput, action), action === undefined || action === 'create'),
  );

program
  .command('restore <paths...>')
  .description('riporta file o cartelle locali alla versione del server (scarta le modifiche locali)')
  .action((paths: string[]) => runWithContext((ctx) => restoreCommand(ctx, consoleOutput, paths)));

program
  .command('claude-md')
  .description('aggiorna la sezione wpdev di CLAUDE.md con i dati attuali del sito (il resto del file non viene toccato)')
  .option('--force', 'rigenera un CLAUDE.md creato da una versione precedente (senza marcatori)')
  .action((opts: { force?: boolean }) => runWithContext((ctx) => claudeMdCommand(ctx, consoleOutput, opts.force === true), true));

program
  .command('info <topic> [name]')
  .description(`informazioni sul sito: ${INTROSPECT_TOPICS.join(', ')} (name: hook o prefisso)`)
  .action((topic: string, name: string | undefined) =>
    runWithContext(async (ctx) => {
      if (!(INTROSPECT_TOPICS as readonly string[]).includes(topic)) {
        consoleOutput.warn(`Argomento non valido: usa ${INTROSPECT_TOPICS.join(', ')}`);
        return EXIT_ERROR;
      }
      consoleOutput.info(formatIntrospect(await ctx.client.introspect(topic as (typeof INTROSPECT_TOPICS)[number], name ?? '')));
      return EXIT_OK;
    }, true),
  );

program
  .command('log')
  .description('ultime righe di debug.log')
  .option('-n, --lines <n>', 'numero di righe (max 1000)', positiveInt, 200)
  .action((opts: { lines: number }) => runWithContext((ctx) => logCommand(ctx, consoleOutput, opts.lines), true));

program
  .command('deploy')
  .description('pubblica le modifiche locali delle cartelle scrivibili (lint, conflitti, health check, rollback)')
  .option('--dry-run', 'mostra le modifiche ed esegue il lint senza inviare nulla')
  .option('--force', 'sovrascrive i file modificati sul server (ignora i conflitti)')
  .option('--hook', 'modalità hook Stop di Claude Code: non interattiva, exit 2 se il deploy fallisce')
  .option('-y, --yes', 'conferma il deploy su un ambiente protetto (autoDeploy: false) senza chiedere')
  .action(async (opts: { dryRun?: boolean; force?: boolean; hook?: boolean; yes?: boolean }) => {
    if (opts.hook) {
      const input = await readHookInput();
      process.exitCode = await hookDeploy(
        () => createReadyContext({ ...globals(), ...(input.cwd ? { cwd: input.cwd } : {}) }, true),
        input,
        { stdout: (l) => process.stdout.write(`${l}\n`), stderr: (l) => process.stderr.write(`${l}\n`) },
      );
      return;
    }
    await runWithContext(async (ctx) => {
      if (!ctx.config.autoDeploy && !opts.dryRun && !opts.yes) {
        const confirm = terminalConfirm();
        const question = `Pubblicare su "${ctx.config.env}" (${ctx.config.siteUrl}), ambiente protetto? [s/N] `;
        if (!confirm || !(await confirm(question))) {
          consoleOutput.warn(`Ambiente protetto "${ctx.config.env}": deploy annullato (conferma interattiva o --yes).`);
          return EXIT_ERROR;
        }
      }
      return deployCommand(ctx, consoleOutput, { dryRun: opts.dryRun === true, force: opts.force === true });
    }, true);
  });

program
  .command('rollback [releaseId]')
  .description("annulla l'ultimo deploy (o la release indicata e le successive); --rescue usa il mu-plugin fuori banda")
  .option('--rescue', "rollback fuori banda con il token dell'ultimo deploy (quando WordPress è rotto)")
  .option('--force', 'ripristina anche se i file sono stati modificati sul server dopo la release')
  .action((releaseId: string | undefined, opts: { rescue?: boolean; force?: boolean }) =>
    runWithContext((ctx) =>
      rollbackCommand(ctx, consoleOutput, { ...(releaseId ? { releaseId } : {}), rescue: opts.rescue === true, force: opts.force === true }, terminalConfirm()),
      true, // the site may be broken: no extra request before the rollback
    ),
  );

program
  .command('health')
  .description('health check del sito su richiesta')
  .action(() => runWithContext((ctx) => healthCommand(ctx, consoleOutput), true));

program
  .command('cache')
  .description('gestione della cache di lettura locale')
  .command('clear')
  .description('svuota .wpdev/cache/')
  .action(async () => {
    try {
      process.exitCode = await cacheClearCommand(loadConfig(globals()).stateDir, consoleOutput);
    } catch (e) {
      consoleOutput.warn(describeError(e));
      process.exitCode = EXIT_ERROR;
    }
  });

program
  .command('mcp')
  .description('avvia il server MCP (stdio)')
  .action(async () => {
    await startMcpServer(globals(), VERSION);
  });

/** y/N question on the terminal; undefined (no prompt) when stdin is not a TTY. */
function terminalConfirm(): Confirm | undefined {
  if (process.stdin.isTTY !== true) return undefined;
  return async (question) => {
    const term = terminalAsk();
    try {
      return /^(s|si|sì|y|yes)$/i.test((await term.ask(question)).trim());
    } finally {
      term.close();
    }
  };
}

program.parseAsync(process.argv).catch((e: unknown) => {
  process.stderr.write(`errore: ${describeError(e)}\n`);
  // Every failure of the Stop hook must be visible to Claude.
  process.exitCode = process.argv.includes('--hook') ? EXIT_DEPLOY_FAILED : EXIT_ERROR;
});
