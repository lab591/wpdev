import { z } from 'zod';
import { applyRestored } from '../commands/rollback.js';
import { ReadCache } from '../cache.js';
import { isValidHealthPath } from '../config.js';
import type { Context } from '../context.js';
import { runDeploy, type DeployDeps } from '../deploy.js';
import { ApiError, INTROSPECT_TOPICS, type CacheTarget } from '../http.js';
import { describeError } from '../messages.js';
import { normalizeRel, normalizeRelOrRoot } from '../paths.js';
import { restorePaths } from '../commands/restore.js';
import { publishPreview } from '../preview.js';
import { deleteRescue } from '../rescue.js';
import { resolveWritable } from '../writable.js';
import {
  cacheNoticeText,
  unknownHealthText,
  formatCacheFlush,
  formatDeployOutcome,
  formatGrep,
  formatHealth,
  formatIntrospect,
  formatList,
  formatLog,
  formatRead,
  formatRollback,
  formatStatus,
  writableRefusal,
} from './format.js';

export interface ToolResult {
  text: string;
  isError?: boolean;
}

export interface ToolDef {
  name: string;
  description: string;
  inputSchema: z.ZodRawShape;
  handler: (args: Record<string, unknown>) => Promise<ToolResult>;
}

const pathArg = z.string().describe('Path relative to the WordPress root, "/" separators (e.g. wp-content/plugins/woocommerce/includes)');

function refuse(text: string): ToolResult {
  return { text, isError: true };
}

/** Tool definitions. `getContext` is lazy so config errors surface per call. */
export function buildTools(getContext: () => Context | Promise<Context>, deps: DeployDeps = {}): ToolDef[] {
  const run = async (fn: (ctx: Context) => Promise<string>): Promise<ToolResult> => {
    try {
      return { text: await fn(await getContext()) };
    } catch (e) {
      return refuse(`error: ${describeError(e)}`);
    }
  };

  return [
    {
      name: 'site_status',
      description: 'Dev mode, expiry, WordPress/PHP versions, active theme and writable roots of the remote site.',
      inputSchema: {},
      handler: () =>
        run(async (ctx) => {
          const st = await ctx.client.status();
          await resolveWritable(ctx, { status: st });
          return formatStatus(st, ctx.config);
        }),
    },
    {
      name: 'site_list',
      description: 'List a folder of the remote site (not the writable folders: those are local). Use "." for the site root.',
      inputSchema: {
        path: pathArg,
        depth: z.number().int().min(1).max(3).optional().describe('Recursion depth, 1-3 (default 1)'),
      },
      handler: (args) =>
        run(async (ctx) => {
          const path = normalizeRelOrRoot(String(args.path));
          const refusal = path === '.' ? undefined : writableRefusal(ctx.config, path);
          if (refusal) throw new Error(refusal);
          const depth = typeof args.depth === 'number' ? args.depth : 1;
          return formatList(await ctx.client.list(path, depth, 500), ctx.config, 500);
        }),
    },
    {
      name: 'site_read',
      description: 'Read a text file of the remote site with line numbers. Prefer reading only the needed range (from/to) after site_grep.',
      inputSchema: {
        path: pathArg,
        from: z.number().int().min(1).optional().describe('First line (1-based)'),
        to: z.number().int().min(1).optional().describe('Last line (inclusive)'),
      },
      handler: (args) =>
        run(async (ctx) => {
          const path = normalizeRel(String(args.path));
          const refusal = writableRefusal(ctx.config, path);
          if (refusal) throw new Error(refusal);
          const from = typeof args.from === 'number' ? args.from : undefined;
          const to = typeof args.to === 'number' ? args.to : undefined;
          const cache = new ReadCache(ctx.config.stateDir, ctx.client, { ...ctx.config.cache, writable: ctx.config.writable });
          return formatRead(await cache.read(path, from, to), path);
        }),
    },
    {
      name: 'site_grep',
      description: 'Search text in remote site files (server-side, limited). Best way to find hooks, filters, classes and functions.',
      inputSchema: {
        pattern: z.string().min(1).describe('Literal text, or PCRE pattern when regex=true'),
        path: pathArg,
        glob: z.string().optional().describe('Filter files, e.g. "*.php" or "includes/**/*.php"'),
        regex: z.boolean().optional(),
        case_sensitive: z.boolean().optional(),
        max_results: z.number().int().min(1).max(200).optional().describe('Default 100'),
        context: z.number().int().min(0).max(3).optional().describe('Context lines, default 1'),
      },
      handler: (args) =>
        run(async (ctx) => {
          const path = normalizeRelOrRoot(String(args.path));
          const refusal = path === '.' ? undefined : writableRefusal(ctx.config, path);
          if (refusal) throw new Error(refusal);
          const maxResults = typeof args.max_results === 'number' ? args.max_results : 100;
          const res = await ctx.client.grep({
            pattern: String(args.pattern),
            path,
            ...(typeof args.glob === 'string' ? { glob: args.glob } : {}),
            regex: args.regex === true,
            case_sensitive: args.case_sensitive === true,
            max_results: maxResults,
            context: typeof args.context === 'number' ? args.context : 1,
          });
          return formatGrep(res, ctx.config, maxResults);
        }),
    },
    {
      name: 'site_info',
      description:
        'Understand the site without grepping: overview (versions, active theme and plugins, debug constants), post_types, taxonomies, shortcodes (with callback file:line), hook (callbacks of a hook with priority and file:line; name required, e.g. "init" or "woocommerce_before_cart"), rest_routes (name = route prefix, e.g. "wc/v3"), cron, blocks (name = prefix, e.g. "acf/").',
      inputSchema: {
        topic: z.enum(INTROSPECT_TOPICS),
        name: z.string().max(200).optional().describe('Hook name (topic "hook") or prefix filter (rest_routes, blocks)'),
      },
      handler: (args) =>
        run(async (ctx) =>
          formatIntrospect(await ctx.client.introspect(args.topic as (typeof INTROSPECT_TOPICS)[number], typeof args.name === 'string' ? args.name : '')),
        ),
    },
    {
      name: 'preview',
      description:
        'Publish the local changes to a PREVIEW only: the live site does not change; the returned link shows the preview in the browser (sets a cookie). Use it to check visual changes before publishing; then preview_publish or preview_discard.',
      inputSchema: {},
      handler: async () => {
        try {
          const res = formatDeployOutcome(await runDeploy(await getContext(), { target: 'preview' }, deps));
          return res.isError ? refuse(res.text) : { text: res.text };
        } catch (e) {
          return refuse(`error: ${describeError(e)}`);
        }
      },
    },
    {
      name: 'preview_publish',
      description: 'Publish the current preview to the live site (a normal deploy: backup, health check, automatic rollback).',
      inputSchema: {},
      handler: async () => {
        try {
          const ctx = await getContext();
          if (!ctx.config.autoDeploy) {
            return refuse(`refused: "${ctx.config.env}" is a protected environment; only the user can publish there, from the terminal: wpdev --env ${ctx.config.env} preview publish`);
          }
          const { response, git } = await publishPreview(ctx);
          if (response.status === 'rolled_back') {
            return refuse([`publish ROLLED BACK automatically: the live site is back to the previous version; the preview is kept`, ...(response.errors ?? []).slice(0, 20).map((e) => `  ${e}`)].join('\n'));
          }
          return {
            text: [
              `preview published: release ${response.release_id}, ${response.written} written, ${response.deleted} deleted${git?.hash ? `, git commit ${git.hash}` : ''}`,
              ...(response.status === 'health_unknown' ? [unknownHealthText(response.health)] : []),
              ...cacheNoticeText(response.cache),
            ].join('\n'),
          };
        } catch (e) {
          return refuse(`error: ${describeError(e)}`);
        }
      },
    },
    {
      name: 'preview_discard',
      description: 'Discard the current preview (the live site is not affected).',
      inputSchema: {},
      handler: () =>
        run(async (ctx) => {
          await ctx.client.previewDiscard();
          return 'preview discarded';
        }),
    },
    {
      name: 'restore_local',
      description:
        'Discard local changes: bring local files or folders of the writable roots back to the version on the site (modified files are downloaded again, files that exist only locally are removed). Nothing is sent to the site.',
      inputSchema: {
        paths: z.array(pathArg).min(1).max(50),
      },
      handler: (args) =>
        run(async (ctx) => {
          const r = await restorePaths(ctx, (args.paths as string[]) ?? []);
          return [
            `restored ${r.restored.length}, removed ${r.removed.length}, already equal ${r.unchanged}`,
            ...r.restored.map((p) => `  restored ${p}`),
            ...r.removed.map((p) => `  removed ${p} (local only)`),
          ].join('\n');
        }),
    },
    {
      name: 'site_log',
      description: 'Last lines of the remote debug.log (only when WP_DEBUG_LOG is enabled).',
      inputSchema: {
        lines: z.number().int().min(1).max(1000).optional().describe('Default 200'),
      },
      handler: (args) =>
        run(async (ctx) => formatLog(await ctx.client.log(typeof args.lines === 'number' ? args.lines : 200))),
    },
    {
      name: 'deploy',
      description:
        'Publish local changes of the writable folders to the site (read from disk: never pass file contents). Lints PHP, checks conflicts, runs a health check with automatic rollback. Normally done by the Stop hook.',
      inputSchema: {
        dry_run: z.boolean().optional().describe('Only list the changes and lint, upload nothing'),
      },
      handler: async (args) => {
        try {
          const ctx = await getContext();
          if (!ctx.config.autoDeploy && args.dry_run !== true) {
            return refuse(
              `deploy refused: "${ctx.config.env}" is a protected environment (autoDeploy: false). Only the user can publish there, from the terminal: wpdev --env ${ctx.config.env} deploy`,
            );
          }
          const outcome = await runDeploy(ctx, { dryRun: args.dry_run === true }, deps);
          const res = formatDeployOutcome(outcome);
          return res.isError ? refuse(res.text) : { text: res.text };
        } catch (e) {
          return refuse(`error: ${describeError(e)}`);
        }
      },
    },
    {
      name: 'rollback',
      description: 'Undo the last deploy (or the given release and all later ones) restoring the backup on the server.',
      inputSchema: {
        release_id: z.string().min(1).max(64).optional().describe('Release to undo (default: the last active one)'),
      },
      handler: async (args) => {
        try {
          const ctx = await getContext();
          const res = await ctx.client.rollback(typeof args.release_id === 'string' ? args.release_id : undefined);
          await applyRestored(ctx.config, res.files);
          await deleteRescue(ctx.config.stateDir);
          return { text: formatRollback(res) };
        } catch (e) {
          if (e instanceof ApiError && (e.status >= 500 || e.status === 0)) {
            return refuse(`error: ${describeError(e)}. The site may be broken by a fatal error: ask the user to run \`wpdev rollback --rescue\` (out-of-band rollback).`);
          }
          return refuse(`error: ${describeError(e)}`);
        }
      },
    },
    {
      name: 'health',
      description:
        'Run the site health check now: admin health URLs + the pages in wpdev.json health.paths + optional extra site paths (e.g. "/shop/"), and recent fatal errors in debug.log. To have pages checked after every deploy, add them to health.paths in wpdev.json.',
      inputSchema: {
        paths: z
          .array(z.string().refine(isValidHealthPath, 'site-relative path like "/shop/"'))
          .max(10)
          .optional()
          .describe('extra site-relative paths to check this time only'),
      },
      handler: (args: { paths?: string[] }) =>
        run(async (ctx) => formatHealth(await ctx.client.health([...new Set([...ctx.config.health.paths, ...(args.paths ?? [])])].slice(0, 10)))),
    },
    {
      name: 'cache_flush',
      description: 'Flush server caches: PHP opcache, WordPress object cache, Elementor CSS cache (default: all).',
      inputSchema: {
        targets: z.array(z.enum(['opcache', 'object', 'elementor'])).optional(),
      },
      handler: (args) =>
        run(async (ctx) => formatCacheFlush(await ctx.client.cacheFlush(Array.isArray(args.targets) ? (args.targets as CacheTarget[]) : undefined))),
    },
  ];
}
