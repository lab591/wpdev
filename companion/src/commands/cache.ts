import { clearCache } from '../cache.js';
import { formatSize } from '../messages.js';
import { EXIT_OK, type Output } from '../output.js';

/** `wpdev cache clear`: removes `.wpdev/cache/` (no network, no password needed). */
export async function cacheClearCommand(stateDir: string, out: Output): Promise<number> {
  const { entries, bytes } = await clearCache(stateDir);
  out.info(entries === 0 ? 'Cache già vuota.' : `Cache svuotata: ${entries} file rimossi (${formatSize(bytes)}).`);
  return EXIT_OK;
}
