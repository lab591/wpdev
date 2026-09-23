import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { createContext, type Context, type GlobalOptions } from '../context.js';
import { resolveWritable } from '../writable.js';
import { buildTools } from './tools.js';

export const SERVER_NAME = 'wpdev';

/** How often the writable folders are re-read from the site during a session. */
const WRITABLE_REFRESH_MS = 60_000;

/** Starts the MCP server on stdio. Nothing but protocol messages may go to stdout. */
export async function startMcpServer(options: GlobalOptions, version: string): Promise<void> {
  let ctx: Context | undefined;
  let checkedAt = 0;
  const getContext = async (): Promise<Context> => {
    ctx ??= createContext(options);
    if (Date.now() - checkedAt > WRITABLE_REFRESH_MS) {
      await resolveWritable(ctx);
      checkedAt = Date.now();
    }
    return ctx;
  };
  const server = new McpServer({ name: SERVER_NAME, version });
  for (const tool of buildTools(getContext)) {
    server.registerTool(
      tool.name,
      { description: tool.description, inputSchema: tool.inputSchema },
      async (args: Record<string, unknown>) => {
        const res = await tool.handler(args ?? {});
        return { content: [{ type: 'text' as const, text: res.text }], ...(res.isError ? { isError: true } : {}) };
      },
    );
  }
  await server.connect(new StdioServerTransport());
}
