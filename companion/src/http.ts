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
      name?: string;
      plugin: string;
      wp: string;
      php: string;
      theme: { stylesheet: string; template: string };
      writable_roots: string[];
      limits: Limits;
      debug_log: boolean;
      rescue?: string;
      /** URL of the site that answered (multisite: any site of the network). */
      site_url?: string;
      /** Present on multisite networks. */
      network?: { main_site: string; sites: number };
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

/** Conditional read answer: the client copy (`known`) is still current; s/m present when only they changed. */
export interface ReadUnchanged {
  status: 'unchanged';
  s?: number;
  m?: number;
}

export interface ReadResponse {
  status: 'ok';
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
  /** Search engine used by the server (M3): "rg" counts only files with matches. */
  engine?: 'php' | 'rg';
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

export interface DeployManifestEntry {
  p: string;
  action: 'write' | 'delete';
  h?: string;
  base_h: string | null;
}

export interface DeployManifest {
  files: DeployManifestEntry[];
  force: boolean;
  /** Extra same-site paths checked after this deploy (never stored on the server). */
  health_paths?: string[];
}

export const INTROSPECT_TOPICS = ['overview', 'post_types', 'taxonomies', 'shortcodes', 'hook', 'rest_routes', 'cron', 'blocks'] as const;
export type IntrospectTopic = (typeof INTROSPECT_TOPICS)[number];

export interface IntrospectResponse {
  topic: IntrospectTopic;
  items: Record<string, unknown>[];
  truncated: boolean;
  note?: string;
}

export interface HealthCheck {
  url: string;
  /** "admin": configured on the server; "agent": declared in wpdev.json health.paths. */
  source?: 'admin' | 'agent' | 'backend';
  code?: number;
  ms?: number;
  error?: string;
  /** Header proving the page came from a proxy/CDN cache (the check proves nothing then). */
  cached?: string;
}

export interface HealthResult {
  status: 'ok' | 'fail' | 'unknown';
  checks: HealthCheck[];
  message?: string;
  errors?: string[];
  /** New PHP warnings/notices/deprecations raised by the deployed files (never a failure). */
  warnings?: string[];
  /** New warnings raised by other code (third-party noise), counted only. */
  other_warnings?: number;
}

export interface DeployResponse {
  /** null when every file already had the requested content on the server (no release created). */
  release_id: string | null;
  status: 'ok' | 'rolled_back' | 'health_unknown';
  written: number;
  deleted: number;
  health: HealthResult;
  errors?: string[];
  rescue_token?: string;
  /** Caches that may hide the change just published (0.6.0). */
  cache?: CacheNotice;
}

export interface CacheNotice {
  /** Page caches (plugins, server caches, managed hosts). */
  page: string[];
  /** Plugins that combine/minify CSS and JS. */
  assets: string[];
  /** OPcache cannot be invalidated: seconds before PHP changes are visible (-1: until PHP restarts). */
  opcache_stale_s: number | null;
}

export interface PreviewResponse {
  /** One-time link that sets the preview cookie in the browser. */
  link: string;
  expires_at: number;
  units: string[];
  files: number;
  health: {
    status: 'ok' | 'fail' | 'unknown';
    code: number | null;
    ms?: number;
    errors?: string[];
    /** false: a plain browser request with the cookie got the live page (a cache in front of PHP). */
    visible?: boolean;
    cached?: string;
  };
}

export type PreviewStatus =
  | { active: false }
  | { active: true; expires_at: number; expired: boolean; units: string[]; files: string[] };

export interface PreviewPublishResponse extends DeployResponse {
  files: { p: string; action: 'write' | 'delete'; h: string | null; base_h: string | null }[];
}

export interface RestoredFile {
  p: string;
  h: string | null;
}

export interface RollbackResponse {
  status: 'ok';
  rolled_back: string[];
  files: RestoredFile[];
}

export interface RescueResponse {
  status: 'ok';
  release_id: string;
  files: RestoredFile[];
}

export interface Release {
  id: string;
  created_at: number;
  user_id: number;
  written: number;
  deleted: number;
  status: string;
}

export interface CacheFlushResponse {
  results: Record<string, string>;
}

export type CacheTarget = 'opcache' | 'object' | 'elementor';

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
  private readonly site: string;
  private readonly base: string;
  /**
   * `pretty`: /wp-json/devbridge/v1/…; `query`: /?rest_route=/devbridge/v1/… for sites with plain
   * permalinks, where /wp-json/ does not exist. Detected on the first 404 that is not a REST answer.
   */
  private routing: 'pretty' | 'query' = 'pretty';
  private readonly auth: string;
  private readonly timeoutMs: number;
  private readonly fetchImpl: typeof fetch;

  constructor(options: ClientOptions) {
    this.site = options.siteUrl.replace(/\/+$/, '');
    this.base = `${this.site}/wp-json/devbridge/v1/`;
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

  /** Conditional read of the whole file (M3 cache validation). */
  readKnown(path: string, known: { s: number; m: number; h: string }): Promise<ReadResponse | ReadUnchanged> {
    return this.json<ReadResponse | ReadUnchanged>('POST', 'read', { path, known });
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

  /** Multipart deploy: the bundle is read from disk by the caller, never from tool arguments. */
  deploy(manifest: DeployManifest, bundle: Uint8Array | undefined): Promise<DeployResponse> {
    const form = new FormData();
    form.append('manifest', JSON.stringify(manifest));
    if (bundle) {
      form.append('bundle', new Blob([bundle], { type: 'application/zip' }), 'bundle.zip');
    }
    return this.json<DeployResponse>('POST', 'deploy', form, DEPLOY_TIMEOUT_MS);
  }

  /** Preview before publishing (0.5.0): same payload as deploy, applied to preview copies only. */
  preview(manifest: DeployManifest, bundle: Uint8Array | undefined): Promise<PreviewResponse> {
    const form = new FormData();
    form.append('manifest', JSON.stringify(manifest));
    if (bundle) {
      form.append('bundle', new Blob([bundle], { type: 'application/zip' }), 'bundle.zip');
    }
    return this.json<PreviewResponse>('POST', 'preview', form, DEPLOY_TIMEOUT_MS);
  }

  previewStatus(): Promise<PreviewStatus> {
    return this.json<PreviewStatus>('GET', 'preview');
  }

  previewPublish(): Promise<PreviewPublishResponse> {
    return this.json<PreviewPublishResponse>('POST', 'preview/publish', {}, DEPLOY_TIMEOUT_MS);
  }

  previewDiscard(): Promise<{ status: 'ok' }> {
    return this.json<{ status: 'ok' }>('POST', 'preview/discard', {});
  }

  rollback(releaseId?: string, force = false): Promise<RollbackResponse> {
    const body: Json = {};
    if (releaseId) body.release_id = releaseId;
    if (force) body.force = true;
    return this.json<RollbackResponse>('POST', 'rollback', body, DEPLOY_TIMEOUT_MS);
  }

  releases(): Promise<{ releases: Release[] }> {
    return this.json<{ releases: Release[] }>('GET', 'releases');
  }

  /** Read-only introspection of the running site (0.5.0). */
  async introspect(topic: IntrospectTopic, name = ''): Promise<IntrospectResponse> {
    const q = new URLSearchParams({ topic });
    if (name) q.set('name', name);
    return this.json<IntrospectResponse>('GET', `introspect?${q.toString()}`);
  }

  health(paths: readonly string[] = []): Promise<HealthResult> {
    return this.json<HealthResult>('POST', 'health', paths.length ? { paths } : {}, DEPLOY_TIMEOUT_MS);
  }

  cacheFlush(targets?: CacheTarget[]): Promise<CacheFlushResponse> {
    return this.json<CacheFlushResponse>('POST', 'cache-flush', targets && targets.length ? { targets } : {});
  }

  /**
   * Out-of-band rescue through the mu-plugin: plain GET on the site with the token header,
   * no Authorization (the token is the credential). A non-JSON answer means that the
   * mu-plugin did not handle the request (no active token, plugin removed).
   */
  async rescue(token: string): Promise<RescueResponse> {
    let res: Response;
    try {
      res = await this.fetchImpl(`${this.site}/?devbridge_rescue=1`, {
        method: 'GET',
        headers: { 'X-DevBridge-Rescue': token, Accept: 'application/json', 'Cache-Control': 'no-cache' },
        redirect: 'error',
        signal: AbortSignal.timeout(DEPLOY_TIMEOUT_MS),
      });
    } catch (e) {
      throw networkError(e, 'rescue', DEPLOY_TIMEOUT_MS);
    }
    const textBody = await res.text();
    let data: unknown;
    try {
      data = JSON.parse(textBody);
    } catch {
      throw new ApiError(res.status, 'rescue_unavailable', 'Rescue non disponibile (token assente/scaduto o plugin rimosso)');
    }
    const obj = (data ?? {}) as Json;
    if (obj.error && typeof obj.error === 'object') {
      const { code, message, ...rest } = obj.error as Json;
      throw new ApiError(res.status, String(code ?? 'error'), String(message ?? `HTTP ${res.status}`), rest);
    }
    if (!res.ok || obj.status !== 'ok') {
      throw new ApiError(res.status, 'rescue_unavailable', 'Rescue non disponibile (risposta inattesa)');
    }
    return data as RescueResponse;
  }

  async json<T>(method: 'GET' | 'POST', endpoint: string, body?: Json | FormData, timeoutMs?: number): Promise<T> {
    const res = await this.request(method, endpoint, body, timeoutMs);
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

  private async request(method: 'GET' | 'POST', endpoint: string, body?: Json | FormData, timeoutMs = this.timeoutMs): Promise<Response> {
    const headers: Record<string, string> = {
      Authorization: this.auth,
      Accept: 'application/json, application/zip',
    };
    const init: RequestInit = {
      method,
      headers,
      redirect: 'error',
      signal: AbortSignal.timeout(timeoutMs),
    };
    if (body instanceof FormData) {
      init.body = body; // fetch sets the multipart boundary
    } else if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }
    let res: Response;
    try {
      res = await this.fetchImpl(this.url(endpoint), init);
      if (res.status === 404 && this.routing === 'pretty' && !isJson(res)) {
        // Plain permalinks: /wp-json/ is an ordinary 404 page. Switch to ?rest_route= once.
        this.routing = 'query';
        res = await this.fetchImpl(this.url(endpoint), { ...init, signal: AbortSignal.timeout(timeoutMs) });
      }
    } catch (e) {
      throw networkError(e, endpoint, timeoutMs);
    }
    if (!res.ok) {
      throw await toApiError(res);
    }
    return res;
  }

  /** Full URL of an endpoint ("read", "log?lines=5") in the current routing form. */
  private url(endpoint: string): string {
    if (this.routing === 'pretty') return this.base + endpoint;
    const [route, query] = endpoint.split('?', 2);
    return `${this.site}/?rest_route=${encodeURIComponent(`/devbridge/v1/${route}`)}${query ? `&${query}` : ''}`;
  }
}

const DEPLOY_TIMEOUT_MS = 180_000;

function isJson(res: Response): boolean {
  return (res.headers.get('content-type') ?? '').toLowerCase().includes('json');
}

function networkError(e: unknown, endpoint: string, timeoutMs: number): ApiError {
  const err = e as Error & { cause?: { code?: string; message?: string } };
  if (err.name === 'TimeoutError' || err.name === 'AbortError') {
    return new ApiError(0, 'timeout', `Timeout dopo ${Math.round(timeoutMs / 1000)} s (${endpoint})`);
  }
  const cause = err.cause?.code ?? err.cause?.message ?? err.message;
  return new ApiError(0, 'network_error', `Errore di rete verso il sito: ${cause}`);
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
