/**
 * Glob matching shared with the plugin (see docs/protocol.md):
 * - `*` any sequence without `/`, `?` one char other than `/`;
 * - `**` + `/` zero or more directories; trailing `/**` everything inside;
 * - a pattern without `/` is matched against the last path segment only.
 */

const cache = new Map<string, RegExp>();

function escapeChar(ch: string): string {
  return /[.+^${}()|[\]\\]/.test(ch) ? `\\${ch}` : ch;
}

export function globToRegExp(pattern: string, caseInsensitive = false): RegExp {
  const key = `${caseInsensitive ? 'i' : 's'}:${pattern}`;
  const cached = cache.get(key);
  if (cached) {
    return cached;
  }
  let src = '';
  let i = 0;
  while (i < pattern.length) {
    const atSegmentStart = i === 0 || pattern[i - 1] === '/';
    if (atSegmentStart && pattern.startsWith('**/', i)) {
      src += '(?:.*/)?';
      i += 3;
      continue;
    }
    if (pattern.startsWith('/**', i) && i + 3 === pattern.length) {
      src += '(?:/.*)?';
      i += 3;
      continue;
    }
    if (pattern.startsWith('**', i)) {
      src += '.*';
      i += 2;
      continue;
    }
    const ch = pattern[i] as string;
    if (ch === '*') {
      src += '[^/]*';
    } else if (ch === '?') {
      src += '[^/]';
    } else {
      src += escapeChar(ch);
    }
    i += 1;
  }
  const re = new RegExp(`^${src}$`, caseInsensitive ? 'i' : '');
  cache.set(key, re);
  return re;
}

export function matchGlob(pattern: string, rel: string, caseInsensitive = false): boolean {
  const subject = pattern.includes('/') ? rel : rel.slice(rel.lastIndexOf('/') + 1);
  return globToRegExp(pattern, caseInsensitive).test(subject);
}

export function matchAny(patterns: readonly string[], rel: string, caseInsensitive = false): boolean {
  return patterns.some((p) => matchGlob(p, rel, caseInsensitive));
}
