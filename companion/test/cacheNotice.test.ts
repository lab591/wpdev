import { describe, expect, it } from 'vitest';
import { reportDeploy } from '../src/commands/deploy.js';
import type { Change, DeployOutcome } from '../src/deploy.js';
import type { DeployResponse, PreviewResponse } from '../src/http.js';
import { formatDeployOutcome } from '../src/mcp/format.js';
import { renderManagedSection } from '../src/scaffold.js';

const changes: Change[] = [{ p: 'wp-content/themes/child/style.css', status: 'modified', h: 'a', base_h: 'b' }];

function done(response: Partial<DeployResponse>): DeployOutcome {
  return {
    kind: 'done',
    changes,
    lint: undefined,
    response: {
      release_id: '20260924-120000-abcd',
      status: 'ok',
      written: 1,
      deleted: 0,
      health: { status: 'ok', checks: [{ url: 'https://example.test/', source: 'admin', code: 200 }] },
      ...response,
    },
  };
}

function preview(health: PreviewResponse['health']): DeployOutcome {
  return {
    kind: 'preview',
    changes,
    lint: undefined,
    response: { link: 'https://example.test/?devbridge_preview=' + 'b'.repeat(64), expires_at: 2000000000, units: ['wp-content/themes/child'], files: 1, health },
  };
}

describe('cache notices (0.6.0)', () => {
  it('no cache: no extra lines', () => {
    const r = reportDeploy(done({}));
    expect(r.stderr).toEqual([]);
    expect(formatDeployOutcome(done({})).text).not.toMatch(/cache/i);
  });

  it('page cache, asset optimization and stale OPcache are reported to user and agent', () => {
    const outcome = done({ cache: { page: ['WP Super Cache'], assets: ['Autoptimize'], opcache_stale_s: -1 } });
    const cli = reportDeploy(outcome).stderr.join('\n');
    expect(cli).toContain('Cache delle pagine attiva sul sito (WP Super Cache)');
    expect(cli).toContain('Ottimizzazione CSS/JS attiva (Autoptimize)');
    expect(cli).toContain('solo al riavvio di PHP');

    const mcp = formatDeployOutcome(outcome);
    expect(mcp.isError).toBe(false);
    expect(mcp.text).toContain('page cache active on the site (WP Super Cache)');
    expect(mcp.text).toMatch(/Ask the user whether the cache can be disabled/);
    expect(mcp.text).toMatch(/on a production site do not ask that/);
    expect(mcp.text).toContain('until PHP restarts');
  });

  it('a check served from a proxy cache makes the health check inconclusive, and says why', () => {
    const outcome = done({
      status: 'health_unknown',
      health: {
        status: 'unknown',
        checks: [{ url: 'https://example.test/', source: 'admin', code: 200, cached: 'cf-cache-status: HIT' }],
        message: 'Some pages were served from a cache',
      },
    });
    const cli = reportDeploy(outcome).stderr.join('\n');
    expect(cli).toContain('arrivate da una cache');
    expect(cli).not.toContain('loopback non raggiungibile');
    expect(reportDeploy(outcome).stdout.join('\n')).toContain('[dalla cache: cf-cache-status: HIT]');

    const mcp = formatDeployOutcome(outcome).text;
    expect(mcp).toContain('[served from cache: cf-cache-status: HIT]');
    expect(mcp).toContain('some pages were served from a cache');
    expect(mcp).not.toContain('loopback unreachable');
  });

  it('a preview hidden by a cache in front of PHP is flagged', () => {
    const hidden = preview({ status: 'ok', code: 200, visible: false, cached: 'x-litespeed-cache: hit' });
    expect(reportDeploy(hidden).stderr.join('\n')).toContain('wordpress_devbridge_preview');
    expect(formatDeployOutcome(hidden).text).toMatch(/got the live page \(x-litespeed-cache: hit\)/);

    const visible = preview({ status: 'ok', code: 200, visible: true });
    expect(reportDeploy(visible).stderr).toEqual([]);
    expect(formatDeployOutcome(visible).text).not.toContain('live page');
  });

  it('CLAUDE.md tells the agent how to deal with page caches', () => {
    const md = renderManagedSection({ name: 'Site', url: 'https://example.test', writable: [] });
    expect(md).toContain('cache delle pagine');
    expect(md).toContain('?v=123');
  });
});
