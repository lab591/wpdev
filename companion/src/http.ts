/**
 * HTTP client for the Dev Bridge REST API (`/wp-json/devbridge/v1/`).
 * Uses the built-in fetch: TLS verification is always on and redirects are
 * refused (they could forward the Authorization header to another origin).
 */

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly details: Record<string, unknown> = {},
  ) {
    super(message);
    this.name = 'ApiError';
  }

  get retryAfter(): number | undefined {
    const v = this.details.retry_after;
    return typeof v === 'number' ? v : undefined;
  }
}

export interface Limits {
  read_bytes: number;
  grep_results: number;
  grep_ms: number;
  deploy_zip_bytes: number;
  deploy_files: number;
  deploy_file_bytes: number;
}

export type StatusResponse =
  | { mode: 'off' }
  | {
      mode: 'read' | 'write';
      expires_at: number;
      plugin: string;
      wp: string;
      php: string;
      theme: { stylesheet: string; template: string };
      writable_roots: string[];
      limits: Limits;
      debug_log: boolean;
      rescue?: string;
    };

export interface ListEntry {
  p: string;
  t: 'f' | 'd';
  s: number;
  m: number;
}
export interface ListResponse {
  path: string;
  entries: ListEntry[];
  truncated: boolean;
}

export interface ReadResponse {
  status: 'ok' | 'unchanged';
  s: number;
  m: number;
  h: string;
  total_lines: number;
  from: number;
  to: number;
  content: string;
  truncated: boolean;
}

export interface GrepMatch {
  p: string;
  l: number;
  text: string;
  before: string[];
  after: string[];
}
export interface GrepResponse {
  matches: GrepMatch[];
  files_scanned: number;
  truncated: boolean;
  reason?: 'max_results' | 'time_budget';
}

export interface ManifestFile {
  p: string;
  s: number;
  m: number;
  h: string;
}
export interface ManifestResponse {
  root: string;
  files: ManifestFile[];
  truncated: boolean;
}

export interface LogResponse {
  lines: string[];
  truncated: boolean;
}

export interface GrepRequest {
  pattern: string;
  path: string;
  regex?: boolean;
  case_sensitive?: boolean;
  glob?: string;
  max_results?: number;
  context?: number;
  time_budget_ms?: number;
}

export interface ClientOptions {
  siteUrl: string;
  user: string;
  password: string;
  timeoutMs?: number;
  fetchImpl?: typeof fetch;
}

type Json = Record<string, unknown>;

export class ApiClient {
  private readonly base: string;
  private readonly auth: string;
  private readonly timeoutMs: number;
  private readonly fetchImpl: typeof fetch;

  constructor(options: ClientOptions) {
    this.base = `${options.siteUrl.replace(/\/+$/, '')}/wp-json/devbridge/v1/`;
    this.auth = `Basic ${Buffer.from(`${options.user}:${options.password}`, 'utf8').toString('base64')}`;
    this.timeoutMs = options.timeoutMs ?? 60_000;
    this.fetchImpl = options.fetchImpl ?? fetch;
  }

  status(): Promise<StatusResponse> {
    return this.json<StatusResponse>('GET', 'status');
  }

  list(path: string, depth = 1, maxEntries = 500): Promise<ListResponse> {
    return this.json<ListResponse>('POST', 'list', { path, depth, max_entries: maxEntries });
  }

  read(path: string, from?: number, to?: number): Promise<ReadResponse> {
    const body: Json = { path };
    if (from !== undefined) body.from = from;
    if (to !== undefined) body.to = to;
    return this.json<ReadResponse>('POST', 'read', body);
  }

  grep(req: GrepRequest): Promise<GrepResponse> {
    return this.json<GrepResponse>('POST', 'grep', { ...req });
  }

  manifest(root: string, exclude: string[] = []): Promise<ManifestResponse> {
    return this.json<ManifestResponse>('POST', 'manifest', exclude.length ? { root, exclude } : { root });
  }

  archive(paths: string[]): Promise<Uint8Array> {
    return this.bytes('POST', 'archive', { paths });
  }

  log(lines = 200, since?: number): Promise<LogResponse> {
    const q = new URLSearchParams({ lines: String(lines) });
    if (since !== undefined) q.set('since', String(since));
    return this.json<LogResponse>('GET', `log?${q.toString()}`);
  }

  async json<T>(method: 'GET' | 'POST', endpoint: string, body?: Json): Promise<T> {
    const res = await this.request(method, endpoint, body);
    const text = await res.text();
    let data: unknown;
    try {
      data = JSON.parse(text);
    } catch {
      throw new ApiError(res.status, 'invalid_response', `Risposta non JSON da ${endpoint} (HTTP ${res.status})`);
    }
    return data as T;
  }

  async bytes(method: 'GET' | 'POST', endpoint: string, body?: Json): Promise<Uint8Array> {
    const res = await this.request(method, endpoint, body);
    return new Uint8Array(await res.arrayBuffer());
  }

  private async request(method: 'GET' | 'POST', endpoint: string, body?: Json): Promise<Response> {
    const headers: Record<string, string> = {
      Authorization: this.auth,
      Accept: 'application/json, application/zip',
    };
    const init: RequestInit = {
      method,
      headers,
      redirect: 'error',
      signal: AbortSignal.timeout(this.timeoutMs),
    };
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }
    let res: Response;
    try {
      res = await this.fetchImpl(this.base + endpoint, init);
    } catch (e) {
      const err = e as Error & { cause?: { code?: string; message?: string } };
      if (err.name === 'TimeoutError' || err.name === 'AbortError') {
        throw new ApiError(0, 'timeout', `Timeout dopo ${Math.round(this.timeoutMs / 1000)} s (${endpoint})`);
      }
      const cause = err.cause?.code ?? err.cause?.message ?? err.message;
      throw new ApiError(0, 'network_error', `Errore di rete verso il sito: ${cause}`);
    }
    if (!res.ok) {
      throw await toApiError(res);
    }
    return res;
  }
}

async function toApiError(res: Response): Promise<ApiError> {
  let data: unknown;
  try {
    data = await res.json();
  } catch {
    data = undefined;
  }
  if (data && typeof data === 'object') {
    const obj = data as Json;
    // Dev Bridge format: { error: { code, message, ...extra } }
    if (obj.error && typeof obj.error === 'object') {
      const { code, message, ...rest } = obj.error as Json;
      return new ApiError(res.status, String(code ?? 'error'), String(message ?? `HTTP ${res.status}`), rest);
    }
    // WordPress core format: { code, message, data: { status } }
    if (typeof obj.code === 'string') {
      return new ApiError(res.status, obj.code, typeof obj.message === 'string' ? stripTags(obj.message) : `HTTP ${res.status}`);
    }
  }
  const retry = res.headers.get('retry-after');
  const details: Json = retry && /^\d+$/.test(retry) ? { retry_after: Number(retry) } : {};
  return new ApiError(res.status, `http_${res.status}`, `HTTP ${res.status} ${res.statusText}`.trim(), details);
}

function stripTags(s: string): string {
  return s.replace(/<[^>]*>/g, '');
}
