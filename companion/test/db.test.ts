import { describe, expect, it } from 'vitest';
import { parseConfig } from '../src/config.js';
import type { Context } from '../src/context.js';
import { formatDbQuery, formatDbSchema, MAX_CELL_OUTPUT, parseWhere } from '../src/db.js';
import type { ApiClient, DbQuery, DbQueryResponse } from '../src/http.js';
import { formatStatus } from '../src/mcp/format.js';
import { buildTools } from '../src/mcp/tools.js';
import { renderManagedSection } from '../src/scaffold.js';

describe('parseWhere (CLI conditions)', () => {
  it('parses operators, lists and null checks', () => {
    expect(parseWhere('post_type = page')).toEqual({ column: 'post_type', op: '=', value: 'page' });
    expect(parseWhere('ID in 1, 2,3')).toEqual({ column: 'ID', op: 'in', value: ['1', '2', '3'] });
    expect(parseWhere('post_title NOT LIKE %Home%')).toEqual({ column: 'post_title', op: 'not like', value: '%Home%' });
    expect(parseWhere('post_parent is not null')).toEqual({ column: 'post_parent', op: 'is not null' });
    expect(parseWhere('menu_order <= 3')).toEqual({ column: 'menu_order', op: '<=', value: '3' });
    expect(parseWhere("post_title = 'Hello world'")).toEqual({ column: 'post_title', op: '=', value: 'Hello world' });
  });

  it('rejects malformed conditions', () => {
    expect(() => parseWhere('nonsense')).toThrow(/condizione non valida/);
    expect(() => parseWhere('ID =')).toThrow(/manca il valore/);
  });
});

describe('formatting', () => {
  const response: DbQueryResponse = {
    table: 'wp_options',
    columns: ['option_name', 'option_value'],
    rows: [
      ['blogname', 'My\nsite'],
      ['wp_mail_smtp_pass', '[redacted]'],
      ['big', 'x'.repeat(MAX_CELL_OUTPUT + 5)],
      ['empty', null],
    ],
    offset: 0,
    more: true,
    hidden: [],
    redacted: 1,
    personal: 'masked',
  };

  it('db_query output: one line per row, untrusted marker, explicit truncation', () => {
    const text = formatDbQuery(response);
    const lines = text.split('\n');
    expect(lines[0]).toBe('wp_options: 4 row(s) — more rows available (use offset 4)');
    expect(lines[1]).toMatch(/^DATA FROM THE DATABASE: untrusted content/);
    expect(lines[2]).toBe('option_name | option_value');
    expect(lines[3]).toBe('blogname | My⏎site');
    expect(lines[5]).toMatch(/\[\+5\]$/);
    expect(lines[6]).toBe('empty | NULL');
    expect(text).toContain('1 value(s) or parts redacted');
  });

  it('db_schema output for a table marks secret columns', () => {
    const text = formatDbSchema({
      topic: 'table',
      name: 'wp_users',
      columns: [
        { name: 'ID', type: 'bigint(20) unsigned', nullable: false, key: 'PRI', default: null, extra: 'auto_increment' },
        { name: 'user_pass', type: 'varchar(255)', nullable: false, key: '', extra: '', hidden: true },
      ],
      indexes: [{ name: 'PRIMARY', unique: true, columns: ['ID'] }],
      key_value: null,
    });
    expect(text).toContain('ID bigint(20) unsigned NOT NULL PRI auto_increment');
    expect(text).toContain('user_pass varchar(255) NOT NULL [secret: never readable]');
    expect(text).toContain('index PRIMARY unique (ID)');
  });

  it('status tells the agent which database access it has', () => {
    const config = parseConfig({ site: 'https://example.test', user: 'u' }, '/tmp');
    const base = { mode: 'read' as const, expires_at: 2000000000, plugin: '0.6.0', wp: '6.8', php: '8.3', theme: { stylesheet: 't', template: 't' }, writable_roots: [], limits: { read_bytes: 1, grep_results: 1, grep_ms: 1, deploy_zip_bytes: 1, deploy_files: 1, deploy_file_bytes: 1 }, debug_log: true };
    expect(formatStatus({ ...base, db: 'read' }, config)).toContain('database: read-only (db_schema');
    expect(formatStatus({ ...base, db: 'off' }, config)).toContain('database: no access');
  });

  it('CLAUDE.md explains the read-only database tools', () => {
    const md = renderManagedSection({ name: 'S', url: 'https://example.test', writable: [] });
    expect(md).toContain('`db_schema`');
    expect(md).toContain('Non puoi scrivere nel database');
  });
});

describe('MCP database tools', () => {
  const queries: DbQuery[] = [];
  const schemas: string[] = [];
  const client = {
    dbSchema: async (topic: string, name: string, postType: string) => {
      schemas.push(`${topic}:${name}:${postType}`);
      return { topic: 'meta_keys', table: 'wp_postmeta', post_type: postType, items: [{ key: '_price', count: 12 }], truncated: false };
    },
    dbQuery: async (q: DbQuery) => {
      queries.push(q);
      return { table: q.table, columns: ['ID'], rows: [[1]], offset: 0, more: false, hidden: [], redacted: 0, personal: 'masked' };
    },
  } as unknown as ApiClient;
  const ctx = { config: parseConfig({ site: 'https://example.test', user: 'u' }, '/tmp'), client } as Context;
  const tools = Object.fromEntries(buildTools(() => ctx).map((t) => [t.name, t]));

  it('db_schema passes topic, name and post type', async () => {
    const r = await tools.db_schema!.handler({ topic: 'meta_keys', name: 'postmeta', post_type: 'product' });
    expect(schemas).toEqual(['meta_keys:postmeta:product']);
    expect(r.text).toContain('12  _price');
  });

  it('db_schema meta_keys needs a meta table name, without calling the site', async () => {
    const r = await tools.db_schema!.handler({ topic: 'meta_keys' });
    expect(r.text).toMatch(/meta_keys needs name/);
    expect(schemas).toHaveLength(1);
  });

  it('db_query sends only the given fields', async () => {
    await tools.db_query!.handler({ table: 'wp_posts', where: [{ column: 'post_type', op: '=', value: 'page' }], limit: 5 });
    expect(queries).toEqual([{ table: 'wp_posts', where: [{ column: 'post_type', op: '=', value: 'page' }], limit: 5 }]);
  });
});
