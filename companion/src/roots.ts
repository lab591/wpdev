import { normalizeRel } from './paths.js';

export interface RootsComparison {
  localOnly: string[];
  serverOnly: string[];
}

/** Compares `writable` in wpdev.json with the server `writable_roots`. */
export function compareRoots(local: readonly string[], server: readonly string[]): RootsComparison {
  const norm = (list: readonly string[]): string[] =>
    list.map((r) => {
      try {
        return normalizeRel(r);
      } catch {
        return r;
      }
    });
  const l = norm(local);
  const s = norm(server);
  const has = (list: string[], r: string): boolean => list.some((x) => x.toLowerCase() === r.toLowerCase());
  return {
    localOnly: l.filter((r) => !has(s, r)),
    serverOnly: s.filter((r) => !has(l, r)),
  };
}

/**
 * Messages for a wpdev.json that restricts the writable folders. The site is the authority:
 * a folder listed only locally will be refused; a folder enabled only on the site is simply
 * not used by this project (not an error).
 */
export function describeRootsMismatch(c: RootsComparison): { warnings: string[]; notes: string[] } {
  return {
    warnings: c.localOnly.length
      ? [
          `In wpdev.json ma non abilitate sul sito: ${c.localOnly.join(', ')} — il server rifiuterà il deploy: abilitale nel pannello Dev Bridge oppure toglile da wpdev.json (senza "writable" si usano quelle del sito)`,
        ]
      : [],
    notes: c.serverOnly.length ? [`Abilitate sul sito ma escluse da questo progetto (wpdev.json → writable): ${c.serverOnly.join(', ')}`] : [],
  };
}
