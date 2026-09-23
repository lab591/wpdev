import { z } from 'zod';
import type { Context } from '../context.js';
import { describeError } from '../messages.js';
import { normalizeRel, normalizeRelOrRoot } from '../paths.js';
import { formatGrep, formatList, formatLog, formatRead, formatStatus, writableRefusal } from './format.js';

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

/** Tool definitions (M1: read-only). `getContext` is lazy so config errors surface per call. */
export function buildTools(getContext: () => Context): ToolDef[] {
  const run = async (fn: (ctx: Context) => Promise<string>): Promise<ToolResult> => {
    try {
      return { text: await fn(getContext()) };
    } catch (e) {
      return refuse(`error: ${describeError(e)}`);
    }
  };

  return [
    {
      name: 'site_status',
      description: 'Dev mode, expiry, WordPress/PHP versions, active theme and writable roots of the remote site.',
      inputSchema: {},
      handler: () => run(async (ctx) => formatStatus(await ctx.client.status(), ctx.config)),
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
          return formatRead(await ctx.client.read(path, from, to), path);
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
      name: 'site_log',
      description: 'Last lines of the remote debug.log (only when WP_DEBUG_LOG is enabled).',
      inputSchema: {
        lines: z.number().int().min(1).max(1000).optional().describe('Default 200'),
      },
      handler: (args) =>
        run(async (ctx) => formatLog(await ctx.client.log(typeof args.lines === 'number' ? args.lines : 200))),
    },
  ];
}
