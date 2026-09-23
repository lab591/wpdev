import { mkdir, readFile, rename, rm, stat, writeFile, readdir } from 'node:fs/promises';
import path from 'node:path';
import { STATE_DIR } from './config.js';
import { ApiError, type ApiClient, type ReadResponse } from './http.js';
import { isInside, normalizeRel, toNative } from './paths.js';

/**
 * Read-only cache of remote files for `site_read` (SPEC 3.8).
 *
 * Layout: `.wpdev/cache/index.json` (metadata) and `.wpdev/cache/files/<relative path>` (content).
 * Only whole files are cached; ranges are served locally with the same semantics as the server
 * (lines split after "\n", original line endings kept). Never used by pull/diff/deploy.
 *
 * Version invalidation: every path under `wp-content/plugins/<slug>` or `wp-content/themes/<slug>`
 * belongs to a component whose main file header "Version:" is remembered; when it changes, the whole
 * component cache is dropped. Other paths (core, root files) rely on conditional validation only.
 */

export const CACHE_DIR = 'cache';

export interface CacheEntry {
  s: number;
  m: number;
  h: string;
  /** Unix seconds of the last validation against the server. */
  validated_at: number;
}

interface ComponentInfo {
  main: string | null;
  version: string | null;
  checked_at: number;
}

interface CacheIndex {
  v: 1;
  files: Record<string, CacheEntry>;
  components: Record<string, ComponentInfo>;
}

export interface CacheOptions {
  enabled: boolean;
  trustWindowSec: number;
  /** Writable roots: never cached (they are local files). */
  writable?: readonly string[];
  /** Clock in seconds (tests). */
  now?: () => number;
}

/** Component root of a path (`wp-content/plugins/<slug>` or `wp-content/themes/<slug>`), or null. */
export function componentOf(rel: string): string | null {
  const m = /^(wp-content\/(?:plugins|themes)\/[^/]+)\/./i.exec(rel);
  return m ? m[1]! : null;
}

/** Value of the "Version:" header in the first 8 KB of a plugin/theme main file. */
export function headerValue(content: string, name: string): string | null {
  const head = content.slice(0, 8192);
  const re = new RegExp(`^[ \\t/*#@]*${name}:(.*)$`, 'mi');
  const m = re.exec(head);
  if (!m) return null;
  const value = m[1]!.replace(/\s*(?:\*\/|\?>).*$/, '').trim();
  return value === '' ? null : value;
}

/** Splits like PHP fgets(): each line keeps its terminator, a trailing fragment is a line. */
export function splitLines(content: string): string[] {
  return content.match(/[^\n]*\n|[^\n]+$/g) ?? [];
}

/** Builds the `/read` response the server would give for a range of a whole cached file. */
export function sliceRead(content: string, meta: { s: number; m: number; h: string }, from?: number, to?: number): ReadResponse {
  const lines = splitLines(content);
  const total = lines.length;
  const start = from ?? 1;
  if (start < 1) throw new ApiError(400, 'invalid_param', 'from must be >= 1');
  if (to !== undefined && to < start) throw new ApiError(400, 'invalid_param', 'to must be >= from');
  if (total > 0 && start > total) throw new ApiError(400, 'invalid_param', `from exceeds total_lines (${total})`);
  const end = Math.min(to ?? total, total);
  return {
    status: 'ok',
    s: meta.s,
    m: meta.m,
    h: meta.h,
    total_lines: total,
    from: start,
    to: Math.max(end, start - 1),
    content: lines.slice(start - 1, end).join(''),
    truncated: false,
  };
}

export class ReadCache {
  private readonly dir: string;
  private readonly now: () => number;
  private index: CacheIndex | undefined;

  constructor(
    private readonly projectRoot: string,
    private readonly client: ApiClient,
    private readonly options: CacheOptions,
  ) {
    this.dir = path.join(projectRoot, STATE_DIR, CACHE_DIR);
    this.now = options.now ?? (() => Math.floor(Date.now() / 1000));
  }

  /** `site_read` entry point: same result as `client.read(path, from, to)`, served from cache when valid. */
  async read(rel: string, from?: number, to?: number): Promise<ReadResponse & { cached?: boolean }> {
    const p = normalizeRel(rel);
    if (!this.options.enabled || (this.options.writable ?? []).some((w) => isInside(p, w))) {
      return this.client.read(p, from, to);
    }
    await this.checkComponent(p);
    const whole = await this.fetchWhole(p);
    if (whole.kind === 'uncacheable') {
      return from === undefined && to === undefined ? whole.response : this.client.read(p, from, to);
    }
    return { ...sliceRead(whole.content, whole.entry, from, to), cached: whole.fromCache };
  }

  /** Returns the whole file, from cache (validated) or from the server, updating the cache. */
  private async fetchWhole(
    p: string,
  ): Promise<{ kind: 'whole'; content: string; entry: CacheEntry; fromCache: boolean } | { kind: 'uncacheable'; response: ReadResponse }> {
    const index = await this.load();
    const entry = index.files[p];
    const content = entry ? await this.readContent(p) : undefined;
    const now = this.now();

    if (entry && content !== undefined) {
      if (now - entry.validated_at < this.options.trustWindowSec) {
        return { kind: 'whole', content, entry, fromCache: true };
      }
      const res = await this.client.readKnown(p, { s: entry.s, m: entry.m, h: entry.h });
      if (res.status === 'unchanged') {
        const updated: CacheEntry = {
          s: typeof res.s === 'number' ? res.s : entry.s,
          m: typeof res.m === 'number' ? res.m : entry.m,
          h: entry.h,
          validated_at: now,
        };
        index.files[p] = updated;
        await this.saveIndex();
        return { kind: 'whole', content, entry: updated, fromCache: true };
      }
      return this.store(p, res as ReadResponse);
    }
    if (entry) {
      delete index.files[p]; // Metadata without content: stale.
    }
    return this.store(p, await this.client.read(p));
  }

  private async store(
    p: string,
    res: ReadResponse,
  ): Promise<{ kind: 'whole'; content: string; entry: CacheEntry; fromCache: boolean } | { kind: 'uncacheable'; response: ReadResponse }> {
    const index = await this.load();
    if (res.status !== 'ok' || res.truncated || res.from !== 1 || res.to < res.total_lines) {
      if (index.files[p]) {
        delete index.files[p];
        await this.saveIndex();
      }
      return { kind: 'uncacheable', response: res };
    }
    const entry: CacheEntry = { s: res.s, m: res.m, h: res.h, validated_at: this.now() };
    await this.writeAtomic(toNative(path.join(this.dir, 'files'), p), res.content);
    index.files[p] = entry;
    await this.saveIndex();
    return { kind: 'whole', content: res.content, entry, fromCache: false };
  }

  /** Drops the component cache when its main file "Version:" changed. Never throws. */
  private async checkComponent(p: string): Promise<void> {
    const comp = componentOf(p);
    if (!comp) return;
    const index = await this.load();
    const info = index.components[comp];
    const now = this.now();
    if (info && now - info.checked_at < this.options.trustWindowSec) return;
    try {
      const main = info?.main ?? (await this.findMain(comp));
      let version: string | null = null;
      if (main) {
        const whole = await this.fetchWhole(main);
        version = whole.kind === 'whole' ? headerValue(whole.content, 'Version') : null;
      }
      if (info && info.version !== null && version !== null && info.version !== version) {
        for (const key of Object.keys(index.files)) {
          if (key !== main && key.startsWith(`${comp}/`)) {
            delete index.files[key];
            await rm(toNative(path.join(this.dir, 'files'), key), { force: true });
          }
        }
      }
      index.components[comp] = { main, version, checked_at: now };
    } catch {
      // Version detection is best effort: remember the attempt, keep serving reads.
      index.components[comp] = { main: info?.main ?? null, version: info?.version ?? null, checked_at: now };
    }
    await this.saveIndex();
  }

  /** Main file of a plugin/theme: style.css for themes; for plugins <slug>.php or the top-level file with "Plugin Name:". */
  private async findMain(comp: string): Promise<string | null> {
    const slug = comp.slice(comp.lastIndexOf('/') + 1);
    if (/^wp-content\/themes\//i.test(comp)) {
      return `${comp}/style.css`;
    }
    const guess = `${comp}/${slug}.php`;
    const tryFile = async (file: string): Promise<boolean> => {
      try {
        const whole = await this.fetchWhole(file);
        return whole.kind === 'whole' && headerValue(whole.content, 'Plugin Name') !== null;
      } catch {
        return false;
      }
    };
    if (await tryFile(guess)) return guess;
    const listing = await this.client.list(comp, 1, 500);
    for (const e of listing.entries) {
      if (e.t === 'f' && e.p !== guess && /\.php$/i.test(e.p) && (await tryFile(e.p))) return e.p;
    }
    return null;
  }

  private async load(): Promise<CacheIndex> {
    if (this.index) return this.index;
    try {
      const data = JSON.parse(await readFile(path.join(this.dir, 'index.json'), 'utf8')) as Partial<CacheIndex>;
      this.index = {
        v: 1,
        files: data.files && typeof data.files === 'object' ? data.files : {},
        components: data.components && typeof data.components === 'object' ? data.components : {},
      };
    } catch {
      this.index = { v: 1, files: {}, components: {} };
    }
    return this.index;
  }

  private async saveIndex(): Promise<void> {
    await this.writeAtomic(path.join(this.dir, 'index.json'), JSON.stringify(this.index));
  }

  private async readContent(p: string): Promise<string | undefined> {
    try {
      return await readFile(toNative(path.join(this.dir, 'files'), p), 'utf8');
    } catch {
      return undefined;
    }
  }

  private async writeAtomic(file: string, data: string): Promise<void> {
    await mkdir(path.dirname(file), { recursive: true });
    const tmp = `${file}.${process.pid}.tmp`;
    await writeFile(tmp, data, 'utf8');
    await rename(tmp, file);
  }
}

/** Removes `.wpdev/cache/`; returns what was removed. */
export async function clearCache(projectRoot: string): Promise<{ entries: number; bytes: number }> {
  const dir = path.join(projectRoot, STATE_DIR, CACHE_DIR);
  let entries = 0;
  let bytes = 0;
  const walk = async (d: string): Promise<void> => {
    let items;
    try {
      items = await readdir(d, { withFileTypes: true });
    } catch {
      return;
    }
    for (const it of items) {
      const full = path.join(d, it.name);
      if (it.isDirectory()) {
        await walk(full);
      } else if (it.isFile()) {
        entries++;
        bytes += (await stat(full)).size;
      }
    }
  };
  await walk(path.join(dir, 'files'));
  await rm(dir, { recursive: true, force: true });
  return { entries, bytes };
}
