import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { ApiClient, ApiError } from '../src/http.js';
import { describeError } from '../src/messages.js';
import { startMockServer, text, type MockServer } from './helpers/mockServer.js';

let mock: MockServer;
let client: ApiClient;

beforeAll(async () => {
  mock = await startMockServer({ files: new Map([['wp-content/plugins/x/a.php', text('<?php\necho 1;\n')]]) });
  client = new ApiClient({ siteUrl: mock.url, user: mock.site.user, password: mock.site.password, timeoutMs: 500 });
});

afterAll(async () => {
  await mock.close();
});

async function catchApi(p: Promise<unknown>): Promise<ApiError> {
  try {
    await p;
  } catch (e) {
    expect(e).toBeInstanceOf(ApiError);
    return e as ApiError;
  }
  throw new Error('expected an ApiError');
}

describe('ApiClient on sites with plain permalinks', () => {
  it('falls back to ?rest_route= when /wp-json/ is an ordinary 404 page, then keeps using it', async () => {
    const urls: string[] = [];
    const fetchImpl = (async (url: string) => {
      urls.push(url);
      if (url.includes('/wp-json/')) return new Response('<html>Not found</html>', { status: 404, headers: { 'content-type': 'text/html' } });
      return new Response(JSON.stringify({ lines: [], truncated: false }), { status: 200, headers: { 'content-type': 'application/json' } });
    }) as typeof fetch;
    const c = new ApiClient({ siteUrl: 'https://plain.example/', user: 'u', password: 'p', fetchImpl });
    await c.log(5);
    await c.log(7);
    expect(urls).toEqual([
      'https://plain.example/wp-json/devbridge/v1/log?lines=5',
      'https://plain.example/?rest_route=%2Fdevbridge%2Fv1%2Flog&lines=5',
      'https://plain.example/?rest_route=%2Fdevbridge%2Fv1%2Flog&lines=7',
    ]);
  });

  it('a JSON 404 (e.g. rest_no_route) is a real API answer: no fallback', async () => {
    const urls: string[] = [];
    const fetchImpl = (async (url: string) => {
      urls.push(url);
      return new Response(JSON.stringify({ code: 'rest_no_route', message: 'No route' }), { status: 404, headers: { 'content-type': 'application/json' } });
    }) as typeof fetch;
    const c = new ApiClient({ siteUrl: 'https://x.example', user: 'u', password: 'p', fetchImpl });
    await expect(c.status()).rejects.toBeInstanceOf(ApiError);
    expect(urls).toHaveLength(1);
  });
});

describe('ApiClient', () => {
  it('sends Basic auth and parses JSON', async () => {
    const st = await client.status();
    expect(st.mode).toBe('read');
    const req = mock.site.requests.at(-1);
    expect(req?.path).toBe('status');
  });

  it('posts JSON bodies', async () => {
    const res = await client.read('wp-content/plugins/x/a.php', 2, 2);
    expect(res.content).toBe('echo 1;\n');
    expect(mock.site.requests.at(-1)?.body).toEqual({ path: 'wp-content/plugins/x/a.php', from: 2, to: 2 });
  });

  it('maps Dev Bridge errors to ApiError with code and status', async () => {
    const e = await catchApi(client.read('missing.php'));
    expect(e.status).toBe(404);
    expect(e.code).toBe('not_found');
  });

  it('exposes retry_after on rate limiting', async () => {
    const e = await catchApi(client.json('GET', 'rate'));
    expect(e.status).toBe(429);
    expect(e.retryAfter).toBe(42);
    expect(describeError(e)).toContain('42');
  });

  it('maps WordPress core errors', async () => {
    const e = await catchApi(client.json('GET', 'nope'));
    expect(e.code).toBe('rest_no_route');
  });

  it('rejects wrong credentials', async () => {
    const bad = new ApiClient({ siteUrl: mock.url, user: 'claudio', password: 'wrong' });
    const e = await catchApi(bad.status());
    expect(e.status).toBe(401);
    expect(describeError(e)).toMatch(/Autenticazione/);
  });

  it('refuses redirects', async () => {
    const e = await catchApi(client.json('GET', 'redirect'));
    expect(e.code).toBe('network_error');
  });

  it('times out', async () => {
    const e = await catchApi(client.json('GET', 'slow'));
    expect(e.code).toBe('timeout');
  });

  it('reports network errors', async () => {
    const dead = new ApiClient({ siteUrl: 'http://localhost:1', user: 'u', password: 'p' });
    const e = await catchApi(dead.status());
    expect(e.code).toBe('network_error');
  });

  it('downloads zip archives as bytes', async () => {
    const bytes = await client.archive(['wp-content/plugins/x/a.php']);
    expect(bytes[0]).toBe(0x50); // "PK"
    expect(bytes[1]).toBe(0x4b);
  });

  it('returns {mode:"off"} when dev mode is off', async () => {
    mock.site.mode = 'off';
    try {
      expect(await client.status()).toEqual({ mode: 'off' });
      const e = await catchApi(client.list('.'));
      expect(e.code).toBe('mode_off');
    } finally {
      mock.site.mode = 'read';
    }
  });
});
