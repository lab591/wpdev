import { existsSync } from 'node:fs';
import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { createInterface } from 'node:readline/promises';
import { CONFIG_FILE, ENV_LOCAL_FILE, parseConfig, resolvePassword, STATE_DIR, validateSiteUrl, type Config, type WpdevJson } from '../config.js';
import { AUTOCRLF_WARNING, autocrlfRisk, commitAll, gitState, initRepo } from '../git.js';
import { ApiClient } from '../http.js';
import { describeError, formatExpiry } from '../messages.js';
import { EXIT_ERROR, EXIT_OK, type Output } from '../output.js';
import { normalizeRel } from '../paths.js';
import { compareRoots, describeRootsMismatch } from '../roots.js';
import { describeClaudeMdResult, ensureGitattributes, ensureMcpServer, ensureStopHook, writeClaudeMd } from '../scaffold.js';
import { NO_WRITABLE_MESSAGE, resolveWritable, sanitizeServerRoots } from '../writable.js';

export interface InitOptions {
  site?: string;
  user?: string;
  passwordEnv?: string;
  writable?: string[];
  force?: boolean;
  insecureLocal?: boolean;
  /** Non-interactive: never prompt, use defaults for missing optional values. */
  yes?: boolean;
  /** false (`--no-git`): never create a git repository. */
  git?: boolean;
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
  let config: Config;
  if (existsSync(target) && !options.force) {
    // Reuse the existing configuration and (re)create everything else.
    try {
      config = parseConfig(JSON.parse(await readFile(target, 'utf8')), dir, { insecureLocal: options.insecureLocal ?? false });
    } catch (e) {
      out.warn(`${CONFIG_FILE} esistente ma non valido: ${(e as Error).message} (correggilo o usa --force per ricrearlo)`);
      return EXIT_ERROR;
    }
    out.info(`${CONFIG_FILE} esistente: lo uso (--force per ricrearlo da zero)`);
  } else {
    const created = await createConfig(dir, target, out, options, ask);
    if (!created) return EXIT_ERROR;
    config = created;
  }
  return scaffoldProject(dir, config, out, options, ask);
}

async function createConfig(dir: string, target: string, out: Output, options: InitOptions, ask?: Ask): Promise<Config | undefined> {
  const prompt: Ask = async (q, def) => {
    if (options.yes || !ask) return def ?? '';
    return ask(q, def);
  };

  const site = options.site ?? (await prompt('URL del sito (https://...)'));
  const siteUrl = validateSiteUrl(site, options.insecureLocal ?? false);
  const user = options.user ?? (await prompt('Utente WordPress'));
  if (!user) {
    out.warn('Utente mancante');
    return undefined;
  }
  const passwordEnv = options.passwordEnv ?? (await prompt('Variabile d\'ambiente con la Application Password', 'WPDEV_APP_PASSWORD'));
  // Writable folders are decided on the site; --writable only restricts this project to some of them.
  const writable = (options.writable ?? []).map((w) => normalizeRel(w));
  const json: Omit<WpdevJson, 'writable'> & { writable?: string[] } = {
    site: siteUrl,
    user,
    passwordEnv: passwordEnv || 'WPDEV_APP_PASSWORD',
    ...(writable.length ? { writable } : {}),
    exclude: ['**/node_modules/**', '**/.git/**', '**/*.map'],
    php: 'php',
    cache: { enabled: true, trustWindowSec: 60 },
    deploy: { allowDelete: true, lintPhp: true, gitCommit: true, target: 'live' },
    health: { paths: [] },
  };
  const config = parseConfig(json, dir, { insecureLocal: options.insecureLocal ?? false });
  await writeFile(target, `${JSON.stringify(json, null, 2)}\n`, 'utf8');
  out.info(`Creato ${CONFIG_FILE}`);
  return config;
}

/**
 * Offers a local git repository when the project has none, so that every version can be restored
 * (commits after pull and deploy, and by Claude). Returns whether the project is now a repository.
 */
async function offerGit(dir: string, out: Output, options: InitOptions, ask?: Ask): Promise<{ repo: boolean; created: boolean }> {
  const state = await gitState(dir);
  if (state === 'repo') return { repo: true, created: false };
  if (state === 'missing') {
    out.info('git non è installato: installalo (https://git-scm.com) per poter tornare alle versioni precedenti dei file, poi riesegui "wpdev init".');
    return { repo: false, created: false };
  }
  if (options.git === false) return { repo: false, created: false };
  if (!options.yes && ask) {
    const answer = (await ask('Il progetto non è un repository git. Lo creo, per poter tornare alle versioni precedenti? (S/n)', 'S')).toLowerCase();
    if (answer.startsWith('n')) {
      out.info('Nessun repository git: potrai crearlo in seguito con "git init" (e "wpdev claude-md" per aggiornare le istruzioni di Claude).');
      return { repo: false, created: false };
    }
  }
  const result = await initRepo(dir);
  if (!result.ok) {
    out.warn(`Repository git non creato: ${result.error ?? 'errore sconosciuto'}`);
    return { repo: false, created: false };
  }
  out.info('Creato un repository git locale: wpdev fa un commit dopo ogni pull e ogni deploy, e Claude dopo ogni modifica.');
  if (result.identity) out.info(`git non aveva nome ed email: per questo progetto uso ${result.identity} (cambiali con "git config user.name/user.email").`);
  return { repo: true, created: true };
}

async function scaffoldProject(dir: string, config: Config, out: Output, options: InitOptions, ask?: Ask): Promise<number> {
  const git = await offerGit(dir, out, options, ask);
  const added = await updateGitignore(dir);
  if (added.length) out.info(`Aggiornato .gitignore (${added.join(', ')})`);
  if (await ensureGitattributes(dir)) out.info('Creato .gitattributes (* -text: file identici byte per byte al server)');
  if (await autocrlfRisk(dir)) out.warn(AUTOCRLF_WARNING);
  const insecure = options.insecureLocal ?? false;
  try {
    if (await ensureStopHook(dir, insecure)) out.info("Aggiunto l'hook Stop in .claude/settings.json (deploy automatico a fine turno)");
    if (await ensureMcpServer(dir, insecure)) out.info('Registrato il server MCP "wpdev" in .mcp.json');
  } catch (e) {
    out.warn(`Configurazione di Claude Code non aggiornata: ${(e as Error).message}`);
  }
  let siteName = new URL(config.siteUrl).host;
  let network: { mainSite: string; sites: number } | undefined;
  const password = resolvePassword(config);
  if (!password) {
    out.info(`Imposta la Application Password in ${config.passwordEnv} (o in ${ENV_LOCAL_FILE}) e poi esegui "wpdev status".`);
  } else {
    try {
      const client = new ApiClient({ siteUrl: config.siteUrl, user: config.user, password });
      const st = await client.status();
      if (st.mode === 'off') {
        out.info('Server raggiungibile, modalità sviluppo off: le credenziali si verificano dopo averla attivata dal pannello Dev Bridge.');
      } else {
        out.info(`Connessione riuscita: modalità ${st.mode}, ${formatExpiry(st.expires_at)}`);
        await resolveWritable({ config, client }, { status: st });
        if (config.writableFromSite) {
          if (config.writable.length) out.info(`Cartelle scrivibili (dal sito): ${config.writable.join(', ')}`);
          else out.warn(NO_WRITABLE_MESSAGE);
        } else {
          const { warnings, notes } = describeRootsMismatch(compareRoots(config.writable, sanitizeServerRoots(st.writable_roots)));
          warnings.forEach((m) => out.warn(m));
          notes.forEach((m) => out.info(m));
        }
        if (st.name) siteName = st.name;
        if (st.network) network = { mainSite: st.network.main_site, sites: st.network.sites };
      }
    } catch (e) {
      out.warn(`Test di /status fallito: ${describeError(e)}`);
    }
  }

  const claudeMd = describeClaudeMdResult(
    await writeClaudeMd(dir, {
      name: siteName,
      url: config.siteUrl,
      writable: config.writable,
      writableFromSite: config.writableFromSite,
      previewTarget: config.deploy.target === 'preview',
      gitRepo: git.repo,
      ...(network ? { network } : {}),
    }),
  );
  if (claudeMd.warn) out.warn(claudeMd.text);
  else out.info(claudeMd.text);
  if (git.created) {
    // First commit: everything already in the project (configuration and, in a project started earlier,
    // the files being worked on), so there is a version to go back to from the start.
    const first = await commitAll(dir, 'wpdev init: stato iniziale del progetto');
    if (first.hash) out.info(`Primo commit ${first.hash}: stato attuale del progetto.`);
    if (first.error) out.warn(`Primo commit non riuscito: ${first.error}`);
  }
  return EXIT_OK;
}
