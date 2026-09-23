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

const configSchema = z.object({
  site: z.string().min(1),
  user: z.string().min(1),
  passwordEnv: z.string().regex(/^[A-Za-z_][A-Za-z0-9_]*$/).default('WPDEV_APP_PASSWORD'),
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
    })
    .default({ allowDelete: true, lintPhp: true }),
});

export type WpdevJson = z.infer<typeof configSchema>;

export interface Config extends WpdevJson {
  /** Absolute native path of the local project (folder containing wpdev.json). */
  projectRoot: string;
  /** Normalized site URL without trailing slash. */
  siteUrl: string;
}

export interface LoadOptions {
  insecureLocal?: boolean;
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
  const siteUrl = validateSiteUrl(data.site, options.insecureLocal ?? false);
  const seen = new Set<string>();
  for (const root of data.writable) {
    const key = root.toLowerCase();
    if (seen.has(key)) {
      throw new ConfigError(`Root scrivibile duplicata: ${root}`);
    }
    seen.add(key);
  }
  return { ...data, projectRoot, siteUrl };
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
