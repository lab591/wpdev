import path from 'node:path';

/**
 * Internal paths are always relative to the site root (ABSPATH), use `/` as
 * separator and never start with `/`. Conversion to native paths happens only
 * at the edges (disk read/write) through {@link toNative} / {@link fromNative}.
 */

export class PathError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'PathError';
  }
}

const MAX_LENGTH = 1024;
// eslint-disable-next-line no-control-regex
const CONTROL_CHARS = /[\u0000-\u001f\u007f]/;

/**
 * Validates and normalizes a relative path coming from the user or the server.
 * Accepts `\` as separator (Windows input) and a trailing separator; rejects
 * absolute paths, drive letters, wrappers, `.`/`..` segments and control chars.
 */
export function normalizeRel(input: string): string {
  if (input.length === 0) {
    throw new PathError('empty path');
  }
  if (input.length > MAX_LENGTH) {
    throw new PathError('path too long');
  }
  if (CONTROL_CHARS.test(input)) {
    throw new PathError('path contains control characters');
  }
  if (input.includes('://')) {
    throw new PathError('stream wrappers are not allowed');
  }
  const slashed = input.replace(/\\/g, '/');
  if (slashed.startsWith('/') || /^[a-zA-Z]:/.test(slashed)) {
    throw new PathError(`absolute path not allowed: ${input}`);
  }
  // A colon inside a segment is a drive-relative path or an NTFS stream on Windows.
  if (slashed.includes(':')) {
    throw new PathError(`colon not allowed in path: ${input}`);
  }
  const trimmed = slashed.replace(/\/+$/, '');
  const segments = trimmed.split('/');
  for (const segment of segments) {
    if (segment === '' || segment === '.' || segment === '..') {
      throw new PathError(`invalid path segment in: ${input}`);
    }
  }
  return segments.join('/');
}

/** Like {@link normalizeRel} but also accepts the site root, expressed as `.` (or empty/`/`). */
export function normalizeRelOrRoot(input: string): string {
  const t = input.trim();
  if (t === '' || t === '.' || t === './' || t === '/') {
    return '.';
  }
  return normalizeRel(t.replace(/^\.\//, ''));
}

/** True when `rel` equals `dir` or is inside it. Case-insensitive when requested. */
export function isInside(rel: string, dir: string, caseInsensitive = true): boolean {
  if (dir === '.') {
    return true;
  }
  const a = caseInsensitive ? rel.toLowerCase() : rel;
  const b = caseInsensitive ? dir.toLowerCase() : dir;
  return a === b || a.startsWith(`${b}/`);
}

/** True when `dir` is strictly inside `rel` (i.e. `rel` is an ancestor of `dir`). */
export function isAncestor(rel: string, dir: string, caseInsensitive = true): boolean {
  if (rel === '.') {
    return dir !== '.';
  }
  const a = caseInsensitive ? rel.toLowerCase() : rel;
  const b = caseInsensitive ? dir.toLowerCase() : dir;
  return b.startsWith(`${a}/`);
}

/** Converts an internal relative path to a native absolute path under `root`. */
export function toNative(root: string, rel: string, p: path.PlatformPath = path): string {
  const normalized = normalizeRel(rel);
  const target = p.resolve(root, ...normalized.split('/'));
  const base = p.resolve(root);
  const rootWithSep = base.endsWith(p.sep) ? base : base + p.sep;
  if (!target.startsWith(rootWithSep)) {
    throw new PathError(`path escapes the project root: ${rel}`);
  }
  return target;
}

/** Converts a native absolute path under `root` to an internal relative path. */
export function fromNative(root: string, abs: string, p: path.PlatformPath = path): string {
  const rel = p.relative(p.resolve(root), p.resolve(abs));
  if (rel === '' || rel.startsWith('..') || p.isAbsolute(rel)) {
    throw new PathError('path is outside the project root');
  }
  return normalizeRel(rel.split(p.sep).join('/'));
}

/** Parent directory of an internal relative path (`.` for top-level entries). */
export function parentRel(rel: string): string {
  const idx = rel.lastIndexOf('/');
  return idx === -1 ? '.' : rel.slice(0, idx);
}

/** Path of `rel` expressed relative to `base` (both internal). */
export function relativeTo(rel: string, base: string): string {
  if (base === '.') {
    return rel;
  }
  if (rel === base) {
    return '.';
  }
  return rel.startsWith(`${base}/`) ? rel.slice(base.length + 1) : rel;
}
