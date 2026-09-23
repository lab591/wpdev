import { createServer, type IncomingMessage, type Server, type ServerResponse } from 'node:http';
import type { AddressInfo } from 'node:net';
import { unzipSync, zipSync } from 'fflate';
import { matchAny } from '../../src/glob.js';
import { xxh128 } from '../../src/hash.js';

export interface MockSite {
  files: Map<string, Uint8Array>;
  mode: 'off' | 'read' | 'write';
  writableRoots: string[];
  user: string;
  password: string;
  requests: { method: string; path: string; body: unknown }[];
  /** Force an error response for an endpoint. */
  failWith?: { endpoint: string; status: number; body: unknown };
  logLines: string[];
  /** Overrides the /deploy response body (status 200). */
  deployResult?: Record<string, unknown>;
  /** Last deploy received: parsed manifest and zip bytes. */
  lastDeploy?: { manifest: { files: { p: string; action: string; h?: string; base_h: string | null }[]; force: boolean; health_paths?: string[] }; bundle?: Uint8Array };
  lastHealthBody?: unknown;
  /** Token accepted by the simulated rescue mu-plugin (undefined: mu-plugin inert). */
  rescueToken?: string;
  rescueFiles: { p: string; h: string | null }[];
  rescueStatus?: string;
  rollbackFiles: { p: string; h: string | null }[];
  /** mtime reported by /read per path (default 1700000000). */
  mtimes: Map<string, number>;
  /** Simulates the server read limit: at most N lines per /read, then truncated:true. */
  readLimitLines?: number;
}

export interface MockServer {
  site: MockSite;
  url: string;
  close: () => Promise<void>;
}

const enc = new TextEncoder();

export function text(s: string): Uint8Array {
  return enc.encode(s);
}

function send(res: ServerResponse, status: number, body: unknown): void {
  res.writeHead(status, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify(body));
}

function error(res: ServerResponse, status: number, code: string, message = code, extra: Record<string, unknown> = {}): void {
  send(res, status, { error: { code, message, ...extra } });
}

async function readRaw(req: IncomingMessage): Promise<Buffer> {
  const chunks: Buffer[] = [];
  for await (const c of req) chunks.push(c as Buffer);
  return Buffer.concat(chunks);
}

async function readBody(req: IncomingMessage): Promise<unknown> {
  const chunks: Buffer[] = [];
  for await (const c of req) chunks.push(c as Buffer);
  const raw = Buffer.concat(chunks).toString('utf8');
  return raw ? JSON.parse(raw) : undefined;
}

function under(p: string, root: string): boolean {
  return root === '.' || p === root || p.startsWith(`${root}/`);
}

export async function startMockServer(init: Partial<MockSite> = {}): Promise<MockServer> {
  const site: MockSite = {
    files: new Map(),
    mode: 'read',
    writableRoots: ['wp-content/themes/child'],
    user: 'claudio',
    password: 'abcd efgh ijkl',
    requests: [],
    logLines: [],
    rescueFiles: [],
    rollbackFiles: [],
    mtimes: new Map(),
    ...init,
  };

  const server: Server = createServer((req, res) => {
    void (async () => {
      const url = new URL(req.url ?? '/', 'http://localhost');
      const prefix = '/wp-json/devbridge/v1/';
      if (url.searchParams.has('devbridge_rescue')) {
        site.requests.push({ method: req.method ?? '', path: 'rescue', body: undefined });
        const token = req.headers['x-devbridge-rescue'];
        if (site.rescueToken === undefined) {
          res.writeHead(200, { 'Content-Type': 'text/html' });
          res.end('<html>site</html>');
        } else if (token !== site.rescueToken) {
          error(res, 403, 'rescue_denied', 'Invalid token');
        } else {
          site.rescueToken = undefined;
          send(res, 200, { status: 'ok', release_id: '20260923-101500-abc123', files: site.rescueFiles });
        }
        return;
      }
      if (!url.pathname.startsWith(prefix)) {
        send(res, 404, { code: 'rest_no_route', message: 'No route', data: { status: 404 } });
        return;
      }
      const endpoint = url.pathname.slice(prefix.length);
      const multipart = String(req.headers['content-type'] ?? '').startsWith('multipart/form-data');
      const raw = req.method === 'POST' && multipart ? await readRaw(req) : undefined;
      const body = req.method === 'POST' && !multipart ? await readBody(req) : undefined;
      site.requests.push({ method: req.method ?? '', path: endpoint, body });

      if (site.failWith && site.failWith.endpoint === endpoint) {
        send(res, site.failWith.status, site.failWith.body);
        return;
      }
      if (site.mode === 'off') {
        if (endpoint === 'status') send(res, 200, { mode: 'off' });
        else error(res, 403, 'mode_off');
        return;
      }
      const expected = `Basic ${Buffer.from(`${site.user}:${site.password}`).toString('base64')}`;
      if (req.headers.authorization !== expected) {
        error(res, 401, 'app_password_required');
        return;
      }
      const b = (body ?? {}) as Record<string, unknown>;
      if (['deploy', 'rollback', 'releases', 'cache-flush'].includes(endpoint) && site.mode !== 'write') {
        error(res, 403, 'mode_insufficient', 'This endpoint requires "write" mode');
        return;
      }
      switch (endpoint) {
        case 'deploy': {
          const form = await new Response(raw, { headers: { 'content-type': String(req.headers['content-type']) } }).formData();
          const manifest = JSON.parse(String(form.get('manifest'))) as NonNullable<MockSite['lastDeploy']>['manifest'];
          const file = form.get('bundle');
          const bundle = file && typeof file !== 'string' ? new Uint8Array(await file.arrayBuffer()) : undefined;
          site.lastDeploy = bundle ? { manifest, bundle } : { manifest };
          if (site.deployResult) {
            send(res, 200, site.deployResult);
            return;
          }
          const conflicts = [];
          for (const f of manifest.files) {
            const cur = site.files.get(f.p);
            const h = cur ? await xxh128(cur) : null;
            if (h !== null && f.base_h === null && h !== f.h) conflicts.push({ p: f.p, reason: 'exists' });
            else if (h !== null && f.base_h !== null && h !== f.base_h && h !== f.h) conflicts.push({ p: f.p, reason: 'modified' });
            else if (h === null && f.base_h !== null) conflicts.push({ p: f.p, reason: 'deleted' });
          }
          if (conflicts.length && !manifest.force) {
            error(res, 409, 'conflict', 'Files changed on the server since the last sync', { conflicts });
            return;
          }
          const entries = bundle ? unzipSync(bundle) : {};
          let written = 0;
          let deleted = 0;
          for (const f of manifest.files) {
            if (f.action === 'write') {
              site.files.set(f.p, entries[f.p] as Uint8Array);
              written++;
            } else {
              site.files.delete(f.p);
              deleted++;
            }
          }
          send(res, 200, {
            release_id: '20260923-101500-abc123', status: 'ok', written, deleted,
            health: { status: 'ok', checks: [{ url: 'http://localhost/', code: 200, ms: 12 }] },
            rescue_token: 'a'.repeat(64),
          });
          return;
        }
        case 'rollback':
          send(res, 200, { status: 'ok', rolled_back: ['20260923-101500-abc123'], files: site.rollbackFiles });
          return;
        case 'health':
          site.lastHealthBody = body;
          send(res, 200, { status: 'fail', checks: [{ url: 'http://localhost/', code: 500, ms: 30 }], errors: ['PHP Fatal error: boom'] });
          return;
        case 'cache-flush':
          send(res, 200, { results: Object.fromEntries(((b.targets as string[] | undefined) ?? ['opcache', 'object', 'elementor']).map((t) => [t, 'ok'])) });
          return;
        case 'status':
          send(res, 200, {
            mode: site.mode,
            expires_at: Math.floor(Date.now() / 1000) + 3600,
            plugin: '0.1.0',
            wp: '6.8.2',
            php: '8.3.19',
            theme: { stylesheet: 'child', template: 'parent' },
            writable_roots: site.writableRoots,
            limits: { read_bytes: 524288, grep_results: 200, grep_ms: 5000, deploy_zip_bytes: 1, deploy_files: 1, deploy_file_bytes: 1 },
            debug_log: true,
            rescue: site.rescueStatus ?? 'installed',
          });
          return;
        case 'manifest': {
          const root = String(b.root);
          const exclude = Array.isArray(b.exclude) ? (b.exclude as string[]) : [];
          const hasAny = [...site.files.keys()].some((p) => under(p, root));
          if (!hasAny) {
            error(res, 404, 'not_found');
            return;
          }
          const files = [];
          for (const [p, data] of site.files) {
            if (!under(p, root) || matchAny(exclude, p)) continue;
            files.push({ p, s: data.length, m: 1700000000, h: await xxh128(data) });
          }
          send(res, 200, { root, files, truncated: false });
          return;
        }
        case 'archive': {
          const paths = b.paths as string[];
          const entries: Record<string, Uint8Array> = {};
          for (const p of paths) {
            const data = site.files.get(p);
            if (!data) {
              error(res, 404, 'not_found');
              return;
            }
            entries[p] = data;
          }
          const zip = zipSync(entries);
          res.writeHead(200, { 'Content-Type': 'application/zip' });
          res.end(Buffer.from(zip));
          return;
        }
        case 'read': {
          const data = site.files.get(String(b.path));
          if (!data) {
            error(res, 404, 'not_found');
            return;
          }
          const s = data.length;
          const m = site.mtimes.get(String(b.path)) ?? 1700000000;
          const h = await xxh128(data);
          const known = b.known as { s: number; m: number; h: string } | undefined;
          if (known) {
            if (known.s === s && known.m === m) {
              send(res, 200, { status: 'unchanged' });
              return;
            }
            if (known.h === h) {
              send(res, 200, { status: 'unchanged', s, m });
              return;
            }
          }
          // Same line semantics as the plugin (PHP fgets): each line keeps its "\n".
          const lines = Buffer.from(data).toString('utf8').match(/[^\n]*\n|[^\n]+$/g) ?? [];
          const from = typeof b.from === 'number' ? b.from : 1;
          let to = typeof b.to === 'number' ? Math.min(b.to, lines.length) : lines.length;
          let truncated = false;
          if (site.readLimitLines !== undefined && to - from + 1 > site.readLimitLines) {
            to = from + site.readLimitLines - 1;
            truncated = true;
          }
          send(res, 200, {
            status: 'ok', s, m, h, total_lines: lines.length,
            from, to, content: lines.slice(from - 1, to).join(''), truncated,
          });
          return;
        }
        case 'list':
          send(res, 200, {
            path: b.path,
            entries: [...site.files.keys()].filter((p) => under(p, String(b.path))).map((p) => ({ p, t: 'f', s: site.files.get(p)?.length ?? 0, m: 1 })),
            truncated: false,
          });
          return;
        case 'grep':
          send(res, 200, { matches: [], files_scanned: site.files.size, truncated: false });
          return;
        default:
          if (endpoint.startsWith('log')) {
            const n = Number(url.searchParams.get('lines') ?? 200);
            send(res, 200, { lines: site.logLines.slice(-n), truncated: site.logLines.length > n });
            return;
          }
          if (endpoint === 'rate') {
            error(res, 429, 'rate_limited', 'Too many requests', { retry_after: 42 });
            return;
          }
          if (endpoint === 'redirect') {
            res.writeHead(302, { Location: 'http://example.com/' });
            res.end();
            return;
          }
          if (endpoint === 'slow') {
            setTimeout(() => send(res, 200, {}), 2000);
            return;
          }
          send(res, 404, { code: 'rest_no_route', message: 'No route', data: { status: 404 } });
      }
    })();
  });

  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
  const port = (server.address() as AddressInfo).port;
  return {
    site,
    url: `http://localhost:${port}`,
    close: () => new Promise((resolve) => server.close(() => resolve())),
  };
}
