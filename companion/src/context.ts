import { loadConfig, requirePassword, type Config, type LoadOptions } from './config.js';
import { ApiClient } from './http.js';

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
