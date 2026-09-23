#!/usr/bin/env node
import { Command, InvalidArgumentError } from 'commander';
import { diffCommand } from './commands/diff.js';
import { initCommand, terminalAsk } from './commands/init.js';
import { logCommand } from './commands/log.js';
import { pullCommand } from './commands/pull.js';
import { statusCommand } from './commands/status.js';
import { createContext, type GlobalOptions } from './context.js';
import { startMcpServer } from './mcp/server.js';
import { describeError } from './messages.js';
import { consoleOutput, EXIT_ERROR } from './output.js';
import { VERSION } from './version.js';

const program = new Command();

program
  .name('wpdev')
  .description('Dev Bridge companion: lavora in locale su un sito WordPress remoto')
  .version(VERSION)
  .option('--insecure-local', 'accetta http:// solo per localhost, *.local, *.test')
  .showHelpAfterError();

function globals(): GlobalOptions {
  const opts = program.opts<{ insecureLocal?: boolean }>();
  return { insecureLocal: opts.insecureLocal === true };
}

async function runWithContext(fn: (ctx: ReturnType<typeof createContext>) => Promise<number>): Promise<void> {
  process.exitCode = await fn(createContext(globals()));
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
  .description('crea wpdev.json, aggiorna .gitignore e prova la connessione')
  .option('--site <url>', 'URL del sito')
  .option('--user <user>', 'utente WordPress')
  .option('--password-env <name>', 'variabile d\'ambiente con la Application Password')
  .option('--writable <path>', 'cartella scrivibile (ripetibile)', collect)
  .option('--force', 'sovrascrive wpdev.json esistente')
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
  .description('modalità e scadenza sul server, coerenza delle root')
  .action(() => runWithContext((ctx) => statusCommand(ctx, consoleOutput)));

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
  .command('log')
  .description('ultime righe di debug.log')
  .option('-n, --lines <n>', 'numero di righe (max 1000)', positiveInt, 200)
  .action((opts: { lines: number }) => runWithContext((ctx) => logCommand(ctx, consoleOutput, opts.lines)));

program
  .command('mcp')
  .description('avvia il server MCP (stdio)')
  .action(async () => {
    await startMcpServer(globals(), VERSION);
  });

program.parseAsync(process.argv).catch((e: unknown) => {
  process.stderr.write(`errore: ${describeError(e)}\n`);
  process.exitCode = EXIT_ERROR;
});
