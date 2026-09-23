import { mkdir, readFile, rename, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { STATE_DIR, type Config } from './config.js';
import type { ApiClient, StatusResponse } from './http.js';
import { normalizeRel } from './paths.js';

/**
 * Writable folders come from the site (`/status` → `writable_roots`), which is the only authority:
 * `writable` in wpdev.json is optional and can only restrict the project to some of them.
 * The last list received is cached in `.wpdev/` so that offline commands and the no-change
 * deploy of the Stop hook (which must not touch the network) keep working.
 */

export const ROOTS_CACHE_FILE = 'writable-roots.json';

const CONTAINERS = ['wp-content/themes/', 'wp-content/plugins/', 'wp-content/mu-plugins/'];

/**
 * Keeps only well-formed roots below a themes/plugins container. The list comes from the
 * network, so it is validated like any other external path before touching the disk.
 */
export function sanitizeServerRoots(list: unknown): string[] {
  if (!Array.isArray(list)) return [];
  const out: string[] = [];
  for (const item of list) {
    if (typeof item !== 'string') continue;
    let root: string;
    try {
      root = normalizeRel(item);
    } catch {
      continue;
    }
    const lower = root.toLowerCase();
    if (CONTAINERS.some((c) => lower.startsWith(c) && lower.length > c.length) && !out.some((r) => r.toLowerCase() === lower)) {
      out.push(root);
    }
  }
  return out;
}

export async function loadRootsCache(projectRoot: string): Promise<string[] | undefined> {
  try {
    const data = JSON.parse(await readFile(path.join(projectRoot, STATE_DIR, ROOTS_CACHE_FILE), 'utf8')) as { roots?: unknown };
    return sanitizeServerRoots(data.roots);
  } catch {
    return undefined;
  }
}

export async function saveRootsCache(projectRoot: string, roots: readonly string[]): Promise<void> {
  const dir = path.join(projectRoot, STATE_DIR);
  await mkdir(dir, { recursive: true });
  const file = path.join(dir, ROOTS_CACHE_FILE);
  await writeFile(`${file}.tmp`, `${JSON.stringify({ roots, at: new Date().toISOString() }, null, 2)}\n`, 'utf8');
  await rename(`${file}.tmp`, file);
}

export interface WritableTarget {
  config: Config;
  client: ApiClient;
}

/**
 * Fills `config.writable` with the site's list when wpdev.json does not restrict it.
 * - `status`: a /status response already at hand (no extra request);
 * - `offline`: use only the cached list (fetch only if there is no cache yet).
 * Never throws: on errors the cached list (or nothing) is used.
 */
export async function resolveWritable(ctx: WritableTarget, options: { status?: StatusResponse; offline?: boolean } = {}): Promise<void> {
  const { config } = ctx;
  if (!config.writableFromSite) return;
  if (options.offline && !options.status) {
    const cached = await loadRootsCache(config.projectRoot);
    if (cached) {
      config.writable = cached;
      return;
    }
  }
  try {
    const st = options.status ?? (await ctx.client.status());
    if (st.mode !== 'off') {
      config.writable = sanitizeServerRoots(st.writable_roots);
      await saveRootsCache(config.projectRoot, config.writable);
      return;
    }
  } catch {
    // Unreachable site: fall back to the last known list.
  }
  config.writable = (await loadRootsCache(config.projectRoot)) ?? [];
}

/** Message when there is nothing to work on (a restricting wpdev.json is never empty). */
export const NO_WRITABLE_MESSAGE =
  'Nessuna cartella scrivibile abilitata sul sito: aggiungile nel pannello Dev Bridge (Impostazioni → Cartelle scrivibili), es. wp-content/themes/mio-tema';
