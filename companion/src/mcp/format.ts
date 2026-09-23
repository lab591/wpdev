import type { Config } from '../config.js';
import type { GrepResponse, ListResponse, LogResponse, ReadResponse, StatusResponse } from '../http.js';
import { formatSize } from '../messages.js';
import { isInside, relativeTo } from '../paths.js';
import { compareRoots, describeRootsMismatch } from '../roots.js';

/** Compact, model-facing text formatting for MCP tool results. */

export const MAX_LINE_CHARS = 500;
export const MAX_READ_LINES = 2000;

function clip(line: string, max = MAX_LINE_CHARS): string {
  return line.length > max ? `${line.slice(0, max)}…[+${line.length - max} chars]` : line;
}

/** Returns an error message when `path` is inside a writable root (read it locally instead). */
export function writableRefusal(config: Pick<Config, 'writable'>, path: string): string | undefined {
  const root = config.writable.find((r) => isInside(path, r));
  if (!root) return undefined;
  return `${path} is inside the writable folder ${root}: use the local files (Read/Grep/Glob tools) instead of site_* tools, they are the source of truth for deploys.`;
}

export function formatStatus(st: StatusResponse, config: Pick<Config, 'writable' | 'siteUrl'>): string {
  if (st.mode === 'off') {
    return `site ${config.siteUrl}\nmode: off — dev mode is disabled on the server; ask the user to enable it (admin page or \`wp devbridge enable\`).`;
  }
  const expires = new Date(st.expires_at * 1000).toISOString().replace(/\.\d+Z$/, 'Z');
  const lines = [
    `site ${config.siteUrl}`,
    `mode: ${st.mode} (expires ${expires})`,
    `WordPress ${st.wp}, PHP ${st.php}, Dev Bridge ${st.plugin}`,
    `theme: ${st.theme.stylesheet}${st.theme.template && st.theme.template !== st.theme.stylesheet ? ` (parent ${st.theme.template})` : ''}`,
    `writable (edit locally): ${st.writable_roots.join(', ') || '(none)'}`,
    `debug.log: ${st.debug_log ? 'enabled' : 'disabled'}`,
  ];
  const mismatch = describeRootsMismatch(compareRoots(config.writable, st.writable_roots));
  if (mismatch.length) lines.push(`warning: wpdev.json and server writable roots differ (${mismatch.join('; ')})`);
  return lines.join('\n');
}

export function formatList(res: ListResponse, config: Pick<Config, 'writable'>, maxEntries: number): string {
  const base = res.path;
  const lines = [`${base === '.' ? '(site root)' : base}/ — ${res.entries.length} entries (t name size)`];
  for (const e of res.entries) {
    const name = relativeTo(e.p, base);
    const local = e.t === 'd' && config.writable.some((r) => isInside(e.p, r)) ? '  [writable: use local copy]' : '';
    lines.push(e.t === 'd' ? `d ${name}/${local}` : `f ${name} ${formatSize(e.s)}`);
  }
  if (res.truncated) {
    lines.push(`[truncated at ${maxEntries} entries: list a subfolder or reduce depth]`);
  }
  return lines.join('\n');
}

export function formatRead(res: ReadResponse, path: string): string {
  let content = res.content;
  if (content.endsWith('\n')) content = content.slice(0, -1);
  let lines = content === '' ? [] : content.split(/\r?\n/);
  let to = res.to;
  let clipped = false;
  if (lines.length > MAX_READ_LINES) {
    lines = lines.slice(0, MAX_READ_LINES);
    to = res.from + MAX_READ_LINES - 1;
    clipped = true;
  }
  const width = String(Math.max(to, 1)).length;
  const header = `${path} (lines ${lines.length ? `${res.from}-${to}` : 'none'} of ${res.total_lines}, ${formatSize(res.s)})`;
  const body = lines.map((l, i) => `${String(res.from + i).padStart(width)}| ${clip(l, 2000)}`);
  const out = [header, ...body];
  if (clipped || res.truncated || to < res.total_lines) {
    out.push(`[truncated: showing lines ${res.from}-${to} of ${res.total_lines}; call site_read with from=${to + 1} (and to=) to continue, or site_grep to locate what you need]`);
  }
  return out.join('\n');
}

export function formatGrep(res: GrepResponse, config: Pick<Config, 'writable'>, maxResults: number): string {
  const kept = res.matches.filter((m) => !config.writable.some((r) => isInside(m.p, r)));
  const omitted = res.matches.length - kept.length;
  const out: string[] = [];
  let current = '';
  for (const m of kept) {
    if (m.p !== current) {
      current = m.p;
      out.push(current);
    }
    m.before.forEach((t, i) => out.push(`  ${m.l - m.before.length + i}- ${clip(t)}`));
    out.push(`  ${m.l}: ${clip(m.text)}`);
    m.after.forEach((t, i) => out.push(`  ${m.l + 1 + i}- ${clip(t)}`));
  }
  const files = new Set(kept.map((m) => m.p)).size;
  out.push(`${kept.length} matches in ${files} files (${res.files_scanned} files scanned)`);
  if (omitted > 0) {
    out.push(`[${omitted} matches in writable folders omitted: search the local files for those]`);
  }
  if (res.truncated) {
    const why = res.reason === 'time_budget' ? 'time budget exhausted' : `max_results=${maxResults} reached`;
    out.push(`[truncated: ${why}; narrow path, add glob (e.g. "*.php") or make the pattern more specific]`);
  }
  return out.join('\n');
}

export function formatLog(res: LogResponse): string {
  if (res.lines.length === 0) return '(debug.log is empty)';
  const out = res.lines.map((l) => clip(l, 1000));
  if (res.truncated) out.push(`[showing last ${res.lines.length} lines only]`);
  return out.join('\n');
}
