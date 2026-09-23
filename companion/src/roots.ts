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
  return {
    localOnly: l.filter((r) => !s.includes(r)),
    serverOnly: s.filter((r) => !l.includes(r)),
  };
}

export function describeRootsMismatch(c: RootsComparison): string[] {
  const out: string[] = [];
  if (c.localOnly.length) {
    out.push(`Root in wpdev.json ma non scrivibili sul server: ${c.localOnly.join(', ')}`);
  }
  if (c.serverOnly.length) {
    out.push(`Root scrivibili sul server ma assenti da wpdev.json: ${c.serverOnly.join(', ')}`);
  }
  return out;
}
