import { createServer, type IncomingMessage, type Server, type ServerResponse } from 'node:http';
import type { AddressInfo } from 'node:net';
import { zipSync } from 'fflate';
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
    ...init,
  };

  const server: Server = createServer((req, res) => {
    void (async () => {
      const url = new URL(req.url ?? '/', 'http://localhost');
      const prefix = '/wp-json/devbridge/v1/';
      if (!url.pathname.startsWith(prefix)) {
        send(res, 404, { code: 'rest_no_route', message: 'No route', data: { status: 404 } });
        return;
      }
      const endpoint = url.pathname.slice(prefix.length);
      const body = req.method === 'POST' ? await readBody(req) : undefined;
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
      switch (endpoint) {
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
          const content = Buffer.from(data).toString('utf8');
          const lines = content.split('\n');
          const from = typeof b.from === 'number' ? b.from : 1;
          const to = typeof b.to === 'number' ? Math.min(b.to, lines.length) : lines.length;
          send(res, 200, {
            status: 'ok', s: data.length, m: 1700000000, h: await xxh128(data), total_lines: lines.length,
            from, to, content: lines.slice(from - 1, to).join('\n'), truncated: false,
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
