import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { z } from 'zod';
import { normalizeRel, PathError } from './paths.js';

export const CONFIG_FILE = 'wpdev.json';
export const ENV_LOCAL_FILE = '.env.local';
export const STATE_DIR = '.wpdev';

export class ConfigError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'ConfigError';
  }
}

const relPath = z.string().transform((value, ctx) => {
  try {
    return normalizeRel(value);
  } catch (e) {
    ctx.addIssue({ code: 'custom', message: e instanceof PathError ? e.message : 'invalid path' });
    return z.NEVER;
  }
});

/**
 * Health check entry: a site-relative path (e.g. "/shop/", same rules as the plugin: no "//", "..",
 * "#", "@", backslashes or whitespace; max 200 chars) or, for multisite networks, a full http(s) URL
 * of a site of the network. The server accepts full URLs only for its own network's sites.
 */
export function isValidHealthPath(path: string): boolean {
  const full = /^https?:\/\/([^/?#]*)(.*)$/i.exec(path);
  if (full) {
    // Validate the raw text: URL parsing would silently normalize "..".
    const host = full[1] ?? '';
    if (host === '' || host.includes('@') || host.includes(':') || path.length > 300) return false;
    const rest = full[2] ?? '';
    return isValidHealthPath(rest.startsWith('/') ? rest : `/${rest}`);
  }
  if (path.length === 0 || path.length > 200 || !path.startsWith('/') || path.startsWith('//')) return false;
  // eslint-disable-next-line no-control-regex -- rejecting control characters is the point.
  if (/[\u0000-\u0020\u007f\\#@]/.test(path)) return false;
  let route: string;
  try {
    route = decodeURIComponent(path.split('?')[0] ?? '');
  } catch {
    return false;
  }
  if (route.includes('://')) return false;
  return !route.split('/').some((seg) => seg === '..' || seg === '.');
}

const healthPath = z.string().refine(isValidHealthPath, {
  message: 'percorso di health check non valido: usa percorsi del sito come "/shop/" (niente URL completi, "..", "#", "@")',
});

const envVar = z.string().regex(/^[A-Za-z_][A-Za-z0-9_]*$/);

/** One target site (0.5.0): staging, production... */
const environmentSchema = z.object({
  site: z.string().min(1),
  user: z.string().min(1),
  passwordEnv: envVar.optional(),
  /** false = protected: never deployed by the Stop hook or the MCP tool, only by an explicit, confirmed CLI deploy. */
  autoDeploy: z.boolean().default(true),
});

export const ENV_NAME = /^[A-Za-z0-9_-]{1,32}$/;

const configSchema = z.object({
  /** Single-site configuration (ignored when `environments` is present). */
  site: z.string().min(1).optional(),
  user: z.string().min(1).optional(),
  passwordEnv: envVar.optional(),
  environments: z.record(z.string().regex(ENV_NAME, 'nome di ambiente non valido (lettere, numeri, "-", "_")'), environmentSchema).optional(),
  /** Environment used when --env / WPDEV_ENV are not given (default: the first one). */
  defaultEnv: z.string().optional(),
  writable: z.array(relPath).default([]),
  exclude: z.array(z.string().min(1)).default(['**/node_modules/**', '**/.git/**', '**/*.map']),
  php: z.string().min(1).default('php'),
  cache: z
    .object({
      enabled: z.boolean().default(true),
      trustWindowSec: z.number().int().min(0).max(3600).default(60),
    })
    .default({ enabled: true, trustWindowSec: 60 }),
  deploy: z
    .object({
      allowDelete: z.boolean().default(true),
      lintPhp: z.boolean().default(true),
      /** Commit the published files to the local git repository after each successful deploy. */
      gitCommit: z.boolean().default(true),
      /** "preview": the Stop hook publishes to a preview (seen only with the preview link) instead of live. */
      target: z.enum(['live', 'preview']).default('live'),
    })
    .default({ allowDelete: true, lintPhp: true, gitCommit: true, target: 'live' }),
  health: z
    .object({
      /** Pages the agent wants checked after each deploy, on top of the admin-configured URLs. */
      paths: z.array(healthPath).max(10).default([]),
    })
    .default({ paths: [] }),
});

export type WpdevJson = z.infer<typeof configSchema>;

export interface Config extends Omit<WpdevJson, 'site' | 'user' | 'passwordEnv' | 'environments' | 'defaultEnv'> {
  site: string;
  user: string;
  passwordEnv: string;
  /** Active environment name, or null for a single-site configuration. */
  env: string | null;
  /** Names of all environments (empty for a single-site configuration). */
  envs: string[];
  /** false for protected environments (see environmentSchema). */
  autoDeploy: boolean;
  /** Absolute folder of the local state of the active target: `.wpdev/` or `.wpdev/env/<name>/`. */
  stateDir: string;
  /** Absolute native path of the local project (folder containing wpdev.json). */
  projectRoot: string;
  /** Normalized site URL without trailing slash. */
  siteUrl: string;
  /**
   * True when wpdev.json does not list writable folders: `writable` is then filled with the
   * site's list (see writable.ts). Otherwise wpdev.json restricts the project to its folders.
   */
  writableFromSite: boolean;
}

export interface LoadOptions {
  insecureLocal?: boolean;
  /** Environment to use (overrides WPDEV_ENV and defaultEnv). */
  env?: string;
}

const LOCAL_HOST = /^(localhost|(?:[a-z0-9-]+\.)+(?:local|test))$/i;

/**
 * HTTPS is mandatory. Plain http is accepted only with `--insecure-local` and
 * only for localhost / *.local / *.test. TLS verification is never disabled.
 */
export function validateSiteUrl(site: string, insecureLocal = false): string {
  let url: URL;
  try {
    url = new URL(site);
  } catch {
    throw new ConfigError(`URL del sito non valido: ${site}`);
  }
  if (url.username || url.password) {
    throw new ConfigError("L'URL del sito non deve contenere credenziali");
  }
  if (url.search || url.hash) {
    throw new ConfigError("L'URL del sito non deve contenere query o frammenti");
  }
  if (url.protocol === 'http:') {
    if (!insecureLocal) {
      throw new ConfigError('HTTPS obbligatorio (http:// solo con --insecure-local su host locali)');
    }
    if (!LOCAL_HOST.test(url.hostname)) {
      throw new ConfigError('--insecure-local è ammesso solo per localhost, *.local e *.test');
    }
  } else if (url.protocol !== 'https:') {
    throw new ConfigError(`Protocollo non supportato: ${url.protocol}`);
  }
  return url.toString().replace(/\/+$/, '');
}

export function findProjectRoot(start: string = process.cwd()): string | undefined {
  let dir = path.resolve(start);
  for (;;) {
    if (existsSync(path.join(dir, CONFIG_FILE))) {
      return dir;
    }
    const parent = path.dirname(dir);
    if (parent === dir) {
      return undefined;
    }
    dir = parent;
  }
}

export function parseConfig(raw: unknown, projectRoot: string, options: LoadOptions = {}): Config {
  const parsed = configSchema.safeParse(raw);
  if (!parsed.success) {
    const issue = parsed.error.issues[0];
    const where = issue?.path.join('.') || '(root)';
    throw new ConfigError(`${CONFIG_FILE} non valido: ${where}: ${issue?.message ?? 'errore'}`);
  }
  const data = parsed.data;
  const { environments, defaultEnv, ...rest } = data;
  let target: { site: string; user: string; passwordEnv: string; autoDeploy: boolean };
  let env: string | null = null;
  const envs = Object.keys(environments ?? {});
  if (envs.length) {
    const wanted = options.env ?? process.env.WPDEV_ENV ?? defaultEnv ?? envs[0];
    const chosen = wanted === undefined ? undefined : environments?.[wanted];
    if (!chosen || wanted === undefined) {
      throw new ConfigError(`Ambiente "${wanted}" non definito in ${CONFIG_FILE} (disponibili: ${envs.join(', ')})`);
    }
    env = wanted;
    target = { site: chosen.site, user: chosen.user, passwordEnv: chosen.passwordEnv ?? data.passwordEnv ?? 'WPDEV_APP_PASSWORD', autoDeploy: chosen.autoDeploy };
  } else {
    if (options.env) throw new ConfigError(`--env "${options.env}": ${CONFIG_FILE} non definisce "environments"`);
    if (!data.site || !data.user) throw new ConfigError(`${CONFIG_FILE} non valido: servono "site" e "user" (oppure "environments")`);
    target = { site: data.site, user: data.user, passwordEnv: data.passwordEnv ?? 'WPDEV_APP_PASSWORD', autoDeploy: true };
  }
  const siteUrl = validateSiteUrl(target.site, options.insecureLocal ?? false);
  const seen = new Set<string>();
  for (const root of data.writable) {
    const key = root.toLowerCase();
    if (seen.has(key)) {
      throw new ConfigError(`Root scrivibile duplicata: ${root}`);
    }
    seen.add(key);
  }
  return {
    ...rest,
    ...target,
    env,
    envs,
    stateDir: env === null ? path.join(projectRoot, STATE_DIR) : path.join(projectRoot, STATE_DIR, 'env', env),
    projectRoot,
    siteUrl,
    writableFromSite: data.writable.length === 0,
  };
}

export function loadConfig(options: LoadOptions & { cwd?: string } = {}): Config {
  const root = findProjectRoot(options.cwd);
  if (!root) {
    throw new ConfigError(`${CONFIG_FILE} non trovato (esegui "wpdev init")`);
  }
  const file = path.join(root, CONFIG_FILE);
  let raw: unknown;
  try {
    raw = JSON.parse(readFileSync(file, 'utf8'));
  } catch (e) {
    throw new ConfigError(`${CONFIG_FILE} illeggibile: ${(e as Error).message}`);
  }
  return parseConfig(raw, root, options);
}

/** Minimal dotenv parser: KEY=VALUE, optional quotes, `#` comments, `export` prefix. */
export function parseEnvFile(text: string): Record<string, string> {
  const out: Record<string, string> = {};
  for (const rawLine of text.split(/\r?\n/)) {
    const line = rawLine.trim();
    if (line === '' || line.startsWith('#')) {
      continue;
    }
    const m = /^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/.exec(line);
    if (!m) {
      continue;
    }
    const key = m[1] as string;
    let value = (m[2] as string).trim();
    const quote = value[0];
    if ((quote === '"' || quote === "'") && value.endsWith(quote) && value.length >= 2) {
      value = value.slice(1, -1);
    } else {
      value = value.replace(/\s+#.*$/, '');
    }
    out[key] = value;
  }
  return out;
}

/** Reads the application password from the environment, then from `.env.local`. */
export function resolvePassword(config: Pick<Config, 'passwordEnv' | 'projectRoot'>, env: NodeJS.ProcessEnv = process.env): string | undefined {
  const fromEnv = env[config.passwordEnv];
  if (fromEnv) {
    return fromEnv;
  }
  const file = path.join(config.projectRoot, ENV_LOCAL_FILE);
  if (!existsSync(file)) {
    return undefined;
  }
  const value = parseEnvFile(readFileSync(file, 'utf8'))[config.passwordEnv];
  return value || undefined;
}

export function requirePassword(config: Pick<Config, 'passwordEnv' | 'projectRoot'>): string {
  const password = resolvePassword(config);
  if (!password) {
    throw new ConfigError(
      `Password applicativa mancante: imposta la variabile ${config.passwordEnv} o aggiungila a ${ENV_LOCAL_FILE}`,
    );
  }
  return password;
}
