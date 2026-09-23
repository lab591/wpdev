import { loadConfig, requirePassword, type Config, type LoadOptions } from './config.js';
import { ApiClient } from './http.js';
import { resolveWritable } from './writable.js';

export interface Context {
  config: Config;
  client: ApiClient;
}

export interface GlobalOptions extends LoadOptions {
  cwd?: string;
}

export function createContext(options: GlobalOptions = {}): Context {
  const config = loadConfig(options);
  const client = new ApiClient({ siteUrl: config.siteUrl, user: config.user, password: requirePassword(config) });
  return { config, client };
}

/**
 * Context with the writable folders resolved (from the site unless wpdev.json restricts them).
 * `offline`: use the cached list, for the deploy (no network call when nothing changed).
 */
export async function createReadyContext(options: GlobalOptions = {}, offline = false): Promise<Context> {
  const ctx = createContext(options);
  await resolveWritable(ctx, { offline });
  return ctx;
}
