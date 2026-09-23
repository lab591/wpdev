import { existsSync } from 'node:fs';
import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { createInterface } from 'node:readline/promises';
import { CONFIG_FILE, ENV_LOCAL_FILE, parseConfig, resolvePassword, STATE_DIR, validateSiteUrl, type WpdevJson } from '../config.js';
import { AUTOCRLF_WARNING, autocrlfEnabled } from '../git.js';
import { ApiClient } from '../http.js';
import { describeError, formatExpiry } from '../messages.js';
import { EXIT_ERROR, EXIT_OK, type Output } from '../output.js';
import { normalizeRel } from '../paths.js';
import { compareRoots, describeRootsMismatch } from '../roots.js';

export interface InitOptions {
  site?: string;
  user?: string;
  passwordEnv?: string;
  writable?: string[];
  force?: boolean;
  insecureLocal?: boolean;
  /** Non-interactive: never prompt, use defaults for missing optional values. */
  yes?: boolean;
}

export type Ask = (question: string, def?: string) => Promise<string>;

export function terminalAsk(): { ask: Ask; close: () => void } {
  const rl = createInterface({ input: process.stdin, output: process.stdout });
  return {
    ask: async (q, def) => {
      const answer = (await rl.question(def ? `${q} [${def}]: ` : `${q}: `)).trim();
      return answer || def || '';
    },
    close: () => rl.close(),
  };
}

const GITIGNORE_ENTRIES = [`${STATE_DIR}/`, ENV_LOCAL_FILE];

export async function updateGitignore(dir: string): Promise<string[]> {
  const file = path.join(dir, '.gitignore');
  const current = existsSync(file) ? await readFile(file, 'utf8') : '';
  const lines = new Set(current.split(/\r?\n/).map((l) => l.trim()));
  const missing = GITIGNORE_ENTRIES.filter((e) => !lines.has(e) && !lines.has(`/${e}`));
  if (missing.length) {
    const sep = current === '' || current.endsWith('\n') ? '' : '\n';
    await writeFile(file, `${current}${sep}${missing.join('\n')}\n`, 'utf8');
  }
  return missing;
}

export async function initCommand(dir: string, out: Output, options: InitOptions, ask?: Ask): Promise<number> {
  const target = path.join(dir, CONFIG_FILE);
  if (existsSync(target) && !options.force) {
    out.warn(`${CONFIG_FILE} esiste già (usa --force per ricrearlo)`);
    return EXIT_ERROR;
  }
  const prompt: Ask = async (q, def) => {
    if (options.yes || !ask) return def ?? '';
    return ask(q, def);
  };

  const site = options.site ?? (await prompt('URL del sito (https://...)'));
  const siteUrl = validateSiteUrl(site, options.insecureLocal ?? false);
  const user = options.user ?? (await prompt('Utente WordPress'));
  if (!user) {
    out.warn('Utente mancante');
    return EXIT_ERROR;
  }
  const passwordEnv = options.passwordEnv ?? (await prompt('Variabile d\'ambiente con la Application Password', 'WPDEV_APP_PASSWORD'));
  let writable = options.writable;
  if (!writable) {
    const answer = await prompt('Cartelle scrivibili (separate da virgola, es. wp-content/themes/mio-child)');
    writable = answer.split(',').map((s) => s.trim()).filter(Boolean);
  }
  const json: WpdevJson = {
    site: siteUrl,
    user,
    passwordEnv: passwordEnv || 'WPDEV_APP_PASSWORD',
    writable: writable.map((w) => normalizeRel(w)),
    exclude: ['**/node_modules/**', '**/.git/**', '**/*.map'],
    php: 'php',
    cache: { enabled: true, trustWindowSec: 60 },
    deploy: { allowDelete: true, lintPhp: true },
  };
  const config = parseConfig(json, dir, { insecureLocal: options.insecureLocal ?? false });
  await writeFile(target, `${JSON.stringify(json, null, 2)}\n`, 'utf8');
  out.info(`Creato ${CONFIG_FILE}`);

  const added = await updateGitignore(dir);
  if (added.length) out.info(`Aggiornato .gitignore (${added.join(', ')})`);
  if (await autocrlfEnabled(dir)) out.warn(AUTOCRLF_WARNING);

  const password = resolvePassword(config);
  if (!password) {
    out.info(`Imposta la Application Password in ${config.passwordEnv} (o in ${ENV_LOCAL_FILE}) e poi esegui "wpdev status".`);
    return EXIT_OK;
  }
  try {
    const client = new ApiClient({ siteUrl: config.siteUrl, user: config.user, password });
    const st = await client.status();
    if (st.mode === 'off') {
      out.info('Server raggiungibile, modalità sviluppo off: le credenziali si verificano dopo averla attivata dal pannello Dev Bridge.');
    } else {
      out.info(`Connessione riuscita: modalità ${st.mode}, ${formatExpiry(st.expires_at)}`);
      describeRootsMismatch(compareRoots(config.writable, st.writable_roots)).forEach((m) => out.warn(m));
    }
  } catch (e) {
    out.warn(`Test di /status fallito: ${describeError(e)}`);
  }
  return EXIT_OK;
}
