import type { Config } from '../config.js';
import { countChanges, type DeployOutcome } from '../deploy.js';
import { ApiError, type CacheFlushResponse, type GrepResponse, type HealthResult, type IntrospectResponse, type ListResponse, type LogResponse, type ReadResponse, type RollbackResponse, type StatusResponse } from '../http.js';
import { describeError, formatSize } from '../messages.js';
import { isInside, relativeTo } from '../paths.js';
import { compareRoots } from '../roots.js';

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

export function formatStatus(st: StatusResponse, config: Pick<Config, 'writable' | 'siteUrl' | 'writableFromSite'>): string {
  if (st.mode === 'off') {
    return `site ${config.siteUrl}\nmode: off — dev mode is disabled on the server; ask the user to enable it (admin page or \`wp devbridge enable\`).`;
  }
  const expires = new Date(st.expires_at * 1000).toISOString().replace(/\.\d+Z$/, 'Z');
  const lines = [
    `site ${config.siteUrl}`,
    `mode: ${st.mode} (expires ${expires})`,
    `WordPress ${st.wp}, PHP ${st.php}, Dev Bridge ${st.plugin}`,
    ...(st.network
      ? [`multisite network: ${st.network.sites} sites, main ${st.network.main_site}; themes/plugins are shared by all sites, health.paths may list full URLs of network sites`]
      : []),
    `theme: ${st.theme.stylesheet}${st.theme.template && st.theme.template !== st.theme.stylesheet ? ` (parent ${st.theme.template})` : ''}`,
    config.writable.length
      ? `writable (edit locally): ${config.writable.join(', ')}${config.writableFromSite ? '' : ' (restricted by wpdev.json)'}`
      : 'writable: none — ask the site administrator to add the specific folder (e.g. wp-content/themes/<theme>) in Dev Bridge settings; never the whole themes/ or plugins/ folder',
    `debug.log: ${st.debug_log ? 'enabled' : 'disabled'}`,
  ];
  if (!config.writableFromSite) {
    const cmp = compareRoots(config.writable, st.writable_roots);
    if (cmp.localOnly.length) {
      lines.push(`warning: in wpdev.json but not writable on the site (deploy will be refused): ${cmp.localOnly.join(', ')}`);
    }
  }
  if (st.rescue !== undefined && st.rescue !== 'installed') lines.push(`warning: rescue mu-plugin ${st.rescue} (out-of-band rollback may be unavailable)`);
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
  out.push(
    res.engine === 'rg'
      ? `${kept.length} matches in ${files} files (ripgrep)`
      : `${kept.length} matches in ${files} files (${res.files_scanned} files scanned)`,
  );
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

// ---------------------------------------------------------------- M2: deploy & co.

function healthText(h: HealthResult): string {
  const checks = h.checks.map((c) => `${c.url}${c.source === 'agent' ? ' (agent)' : c.source === 'backend' ? ' (backend)' : ''} ${c.code ?? c.error ?? '?'}`).join(', ');
  return `health: ${h.status}${checks ? ` (${checks})` : ''}${h.message ? ` — ${h.message}` : ''}`;
}

function apiErrorText(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.code === 'conflict') {
      const conflicts = Array.isArray(error.details.conflicts) ? (error.details.conflicts as { p: string; reason: string }[]) : [];
      return [
        `deploy refused: ${conflicts.length} file(s) changed on the server since the last sync:`,
        ...conflicts.slice(0, 20).map((c) => `  ${c.p} (${c.reason})`),
        'Nothing was written. Ask the user whether to run `wpdev pull` (merge server changes) or `wpdev deploy --force`.',
      ].join('\n');
    }
    if (error.code === 'mode_off' || error.code === 'mode_insufficient') {
      return 'deploy refused: write mode is not active on the server. Ask the user to enable it (Dev Bridge admin page or `wp devbridge enable --mode=write --hours=N`).';
    }
    const where = typeof error.details.path === 'string' ? ` (${error.details.path})` : '';
    return `error: ${error.message}${where} [${error.code}${error.status ? ` ${error.status}` : ''}]`;
  }
  return `error: ${describeError(error)}`;
}

/** Compact text for the MCP `deploy` tool. */
export function formatDeployOutcome(o: DeployOutcome): { text: string; isError: boolean } {
  if (o.kind === 'no_changes') {
    return { text: 'no local changes in the writable folders: nothing to deploy', isError: false };
  }
  const c = countChanges(o.changes);
  const counts = `${c.new} new, ${c.modified} modified, ${c.deleted} deleted`;
  const notes: string[] = [];
  if (o.lint?.skipped) notes.push('note: PHP not available locally, lint skipped');
  if ('rescueWarning' in o && o.rescueWarning) notes.push('warning: rescue mu-plugin not installed on the server');
  switch (o.kind) {
    case 'lint_failed':
      return {
        text: [
          'deploy blocked: PHP syntax errors (nothing uploaded):',
          ...o.lint.errors.map((e) => `  ${e.p}${e.line ? `:${e.line}` : ''} ${e.message}`),
          ...(o.lint.engine === 'parser' ? ['note: checked with the built-in PHP parser (PHP is not installed locally); exact on modern code, may flag very old syntax like "clone( $x )"'] : []),
        ].join('\n'),
        isError: true,
      };
    case 'dry_run':
      return {
        text: [`dry run: ${counts}`, ...o.changes.slice(0, 100).map((x) => `  ${x.status} ${x.p}`), ...(o.changes.length > 100 ? [`  [+${o.changes.length - 100} more]`] : []), ...notes].join('\n'),
        isError: false,
      };
    case 'failed':
      return { text: [apiErrorText(o.error), ...notes].join('\n'), isError: true };
    case 'done': {
      const r = o.response;
      if (r.status === 'rolled_back') {
        return {
          text: [
            `release ${r.release_id} ROLLED BACK automatically: the site is back to the previous version`,
            healthText(r.health),
            ...(r.errors?.length ? ['errors:', ...r.errors.slice(0, 20).map((e) => `  ${e}`)] : []),
            'Fix the local files and deploy again; use site_log for details.',
          ].join('\n'),
          isError: true,
        };
      }
      if (r.release_id === null) {
        return { text: ['server already had this content: no release created, local state updated', ...notes].join('\n'), isError: false };
      }
      const lines = [`release ${r.release_id}: ${r.written} written, ${r.deleted} deleted (${counts})`, healthText(r.health)];
      if (r.status === 'health_unknown') lines.push('warning: health check could not run (loopback unreachable), no automatic rollback: verify the site in the browser');
      if (r.errors?.length) lines.push('fatal lines in debug.log:', ...r.errors.slice(0, 20).map((e) => `  ${e}`));
      if (r.health.warnings?.length) {
        lines.push('new PHP warnings in the deployed files (deploy kept online; fix them):', ...r.health.warnings.slice(0, 20).map((w) => `  ${w}`));
      }
      return { text: [...lines, ...notes].join('\n'), isError: false };
    }
  }
}

export function formatRollback(r: RollbackResponse): string {
  const restored = r.files.filter((f) => f.h !== null).length;
  return `rolled back: ${r.rolled_back.join(', ') || '(none)'}; ${restored} file(s) restored, ${r.files.length - restored} removed. Local files still contain the rolled back changes: fix them and deploy again.`;
}

export function formatHealth(h: HealthResult): string {
  const lines = [healthText(h)];
  if (h.errors?.length) lines.push('recent fatal errors:', ...h.errors.slice(0, 20).map((e) => `  ${e}`));
  return lines.join('\n');
}

export function formatCacheFlush(r: CacheFlushResponse): string {
  return `cache flush: ${Object.entries(r.results).map(([k, v]) => `${k} ${v}`).join(', ') || '(nothing)'}`;
}

/** Compact text of an introspection result: one line per item. */
export function formatIntrospect(res: IntrospectResponse): string {
  const loc = (i: Record<string, unknown>): string => (typeof i.file === 'string' ? `  ${i.file}:${String(i.line ?? '')}` : '');
  const str = (v: unknown): string => (Array.isArray(v) ? v.join(',') : typeof v === 'boolean' ? (v ? 'yes' : 'no') : String(v ?? ''));
  const line = (i: Record<string, unknown>): string => {
    switch (res.topic) {
      case 'overview':
        return `${str(i.key)}: ${str(i.value)}${i.name ? ` (${str(i.name)}${i.version ? ` ${str(i.version)}` : ''})` : ''}${i.parent ? ` parent ${str(i.parent)}` : ''}${i.network ? ' [network]' : ''}`;
      case 'post_types':
        return `${str(i.name)} "${str(i.label)}" public=${str(i.public)} hierarchical=${str(i.hierarchical)} rest=${str(i.show_in_rest)}${i.rewrite ? ` slug=${str(i.rewrite)}` : ''} supports=${str(i.supports)}`;
      case 'taxonomies':
        return `${str(i.name)} "${str(i.label)}" for=${str(i.object_type)} hierarchical=${str(i.hierarchical)} public=${str(i.public)}`;
      case 'shortcodes':
        return `[${str(i.tag)}] ${str(i.callback)}${loc(i)}`;
      case 'hook':
        return `${str(i.priority)} ${str(i.callback)} (args ${str(i.args)})${loc(i)}`;
      case 'rest_routes':
        return `${str(i.methods)} ${str(i.route)}`;
      case 'cron':
        return `${str(i.next)} ${str(i.hook)} (${str(i.schedule)})`;
      case 'blocks':
        return `${str(i.name)}${i.title ? ` "${str(i.title)}"` : ''}${i.callback ? ` render ${str(i.callback)}` : ''}${loc(i)}`;
      default:
        return JSON.stringify(i);
    }
  };
  const out = [`${res.topic}: ${res.items.length} item(s)`, ...res.items.map((i) => clip(line(i)))];
  if (res.items.length === 0) out.push('(none)');
  if (res.truncated) out.push('[truncated: pass a more specific name/prefix]');
  if (res.note) out.push(`note: ${res.note}`);
  return out.join('\n');
}
