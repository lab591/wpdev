import type { Context } from '../context.js';
import { EXIT_OK, type Output } from '../output.js';

export async function logCommand(ctx: Context, out: Output, lines = 200): Promise<number> {
  const n = Math.min(1000, Math.max(1, Math.trunc(lines)));
  const res = await ctx.client.log(n);
  if (res.lines.length === 0) {
    out.info('(debug.log vuoto)');
  }
  res.lines.forEach((l) => out.info(l));
  if (res.truncated) {
    out.warn(`Mostrate solo le ultime ${res.lines.length} righe`);
  }
  return EXIT_OK;
}
