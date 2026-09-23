import { describe, expect, it } from 'vitest';
import { parseConfig } from '../src/config.js';
import type { Context } from '../src/context.js';
import type { ApiClient, GrepResponse, ReadResponse } from '../src/http.js';
import { formatGrep, formatList, formatLog, formatRead, formatStatus, MAX_READ_LINES } from '../src/mcp/format.js';
import { buildTools } from '../src/mcp/tools.js';

const config = parseConfig({ site: 'https://example.com', user: 'u', writable: ['wp-content/themes/child'], cache: { enabled: false, trustWindowSec: 60 } }, '/p');

function read(partial: Partial<ReadResponse>): ReadResponse {
  return { status: 'ok', s: 100, m: 1, h: 'x', total_lines: 3, from: 1, to: 3, content: 'a\nb\nc\n', truncated: false, ...partial };
}

describe('formatRead', () => {
  it('numbers lines with aligned width', () => {
    const t = formatRead(read({ from: 9, to: 11, total_lines: 11, content: 'x\ny\nz' }), 'a.php');
    expect(t.split('\n')).toEqual(['a.php (lines 9-11 of 11, 100B)', ' 9| x', '10| y', '11| z']);
  });

  it('explains how to continue when truncated', () => {
    const t = formatRead(read({ from: 1, to: 2, total_lines: 50, content: 'a\nb', truncated: true }), 'a.php');
    expect(t).toContain('from=3');
  });

  it('caps very long outputs', () => {
    const content = Array.from({ length: MAX_READ_LINES + 10 }, (_, i) => `l${i}`).join('\n');
    const t = formatRead(read({ to: MAX_READ_LINES + 10, total_lines: MAX_READ_LINES + 10, content }), 'big.js');
    expect(t.split('\n').length).toBe(MAX_READ_LINES + 2);
    expect(t).toContain(`from=${MAX_READ_LINES + 1}`);
  });
});

describe('formatGrep', () => {
  const res: GrepResponse = {
    matches: [
      { p: 'wp-content/plugins/a/a.php', l: 10, text: 'add_action("init")', before: ['// b'], after: ['}'] },
      { p: 'wp-content/plugins/a/a.php', l: 20, text: 'add_action("x")', before: [], after: [] },
      { p: 'wp-content/themes/child/functions.php', l: 3, text: 'add_action("y")', before: [], after: [] },
    ],
    files_scanned: 42,
    truncated: true,
    reason: 'max_results',
  };

  it('groups by file, omits writable matches and explains truncation', () => {
    const t = formatGrep(res, config, 3);
    expect(t).toContain('wp-content/plugins/a/a.php\n  9- // b\n  10: add_action("init")\n  11- }\n  20: add_action("x")');
    expect(t).not.toContain('functions.php');
    expect(t).toContain('2 matches in 1 files (42 files scanned)');
    expect(t).toContain('1 matches in writable folders omitted');
    expect(t).toContain('max_results=3 reached');
  });
});

describe('other formatters', () => {
  it('formatList marks writable folders and truncation', () => {
    const t = formatList(
      { path: 'wp-content/themes', entries: [{ p: 'wp-content/themes/child', t: 'd', s: 0, m: 0 }, { p: 'wp-content/themes/index.php', t: 'f', s: 2048, m: 0 }], truncated: true },
      config,
      500,
    );
    expect(t).toContain('d child/  [writable: use local copy]');
    expect(t).toContain('f index.php 2.0K');
    expect(t).toContain('[truncated at 500 entries');
  });

  it('formatStatus handles mode off and root mismatch', () => {
    expect(formatStatus({ mode: 'off' }, config)).toContain('mode: off');
    const t = formatStatus(
      {
        mode: 'read', expires_at: 1790000000, plugin: '0.1.0', wp: '6.8', php: '8.3',
        theme: { stylesheet: 'child', template: 'parent' }, writable_roots: ['wp-content/plugins/x'],
        limits: { read_bytes: 1, grep_results: 1, grep_ms: 1, deploy_zip_bytes: 1, deploy_files: 1, deploy_file_bytes: 1 }, debug_log: false,
      },
      config,
    );
    expect(t).toContain('theme: child (parent parent)');
    expect(t).toContain('warning');
  });

  it('formatLog', () => {
    expect(formatLog({ lines: [], truncated: false })).toContain('empty');
    expect(formatLog({ lines: ['a'], truncated: true })).toContain('last 1 lines');
  });
});

describe('tools', () => {
  const calls: string[] = [];
  const client = {
    read: async (p: string) => {
      calls.push(`read:${p}`);
      return read({});
    },
    list: async (p: string) => {
      calls.push(`list:${p}`);
      return { path: p, entries: [], truncated: false };
    },
    grep: async () => ({ matches: [], files_scanned: 0, truncated: false }),
    introspect: async (topic: string, name: string) => {
      calls.push(`introspect:${topic}:${name}`);
      return {
        topic,
        items: [
          { priority: 10, args: 1, callback: 'wp_enqueue_scripts_x', file: 'wp-content/themes/child/functions.php', line: 12 },
          { priority: 20, args: 2, callback: '{closure}' },
        ],
        truncated: false,
        note: 'Callbacks registered while serving an API request.',
      };
    },
  } as unknown as ApiClient;
  const ctx: Context = { config, client };
  const tools = Object.fromEntries(buildTools(() => ctx).map((t) => [t.name, t]));

  it('exposes the tools with stable names', () => {
    expect(Object.keys(tools).sort()).toEqual(['cache_flush', 'deploy', 'health', 'preview', 'preview_discard', 'preview_publish', 'restore_local', 'rollback', 'site_grep', 'site_info', 'site_list', 'site_log', 'site_read', 'site_status']);
  });

  it('site_info returns compact lines with file:line', async () => {
    const r = await tools.site_info!.handler({ topic: 'hook', name: 'wp_enqueue_scripts' });
    expect(r.isError).toBeUndefined();
    expect(calls).toContain('introspect:hook:wp_enqueue_scripts');
    expect(r.text).toBe(
      [
        'hook: 2 item(s)',
        '10 wp_enqueue_scripts_x (args 1)  wp-content/themes/child/functions.php:12',
        '20 {closure} (args 2)',
        'note: Callbacks registered while serving an API request.',
      ].join('\n'),
    );
    calls.length = 0;
  });

  it('refuses reads inside writable folders (case-insensitive, Windows separators)', async () => {
    const r = await tools.site_read!.handler({ path: 'wp-content\\Themes\\child\\functions.php' });
    expect(r.isError).toBe(true);
    expect(r.text).toContain('local files');
    expect(calls).toEqual([]);
    expect((await tools.site_grep!.handler({ pattern: 'x', path: 'wp-content/themes/child' })).isError).toBe(true);
    expect((await tools.site_list!.handler({ path: 'wp-content/themes/child/inc' })).isError).toBe(true);
  });

  it('allows reads elsewhere and lists the site root', async () => {
    const r = await tools.site_read!.handler({ path: 'wp-content/plugins/a/a.php' });
    expect(r.isError).toBeUndefined();
    expect(calls).toContain('read:wp-content/plugins/a/a.php');
    await tools.site_list!.handler({ path: '.' });
    expect(calls).toContain('list:.');
  });

  it('reports invalid paths as errors', async () => {
    const r = await tools.site_read!.handler({ path: '../wp-config.php' });
    expect(r.isError).toBe(true);
  });
});
