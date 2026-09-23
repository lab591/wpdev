import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { fromNative, isAncestor, isInside, normalizeRel, normalizeRelOrRoot, parentRel, PathError, relativeTo, toNative } from '../src/paths.js';

describe('normalizeRel', () => {
  it('accepts plain relative paths', () => {
    expect(normalizeRel('wp-content/themes/child/style.css')).toBe('wp-content/themes/child/style.css');
  });

  it('converts Windows separators and strips trailing separators', () => {
    expect(normalizeRel('wp-content\\themes\\child\\')).toBe('wp-content/themes/child');
  });

  it('keeps spaces and unicode', () => {
    expect(normalizeRel('wp-content/themes/città nuova/file è.php')).toBe('wp-content/themes/città nuova/file è.php');
  });

  it.each([
    [''],
    ['/etc/passwd'],
    ['\\\\server\\share\\x'],
    ['\\wp-config.php'],
    ['C:\\wamp64\\www\\x.php'],
    ['c:/x'],
    ['D:relative'],
    ['../wp-config.php'],
    ['wp-content/../wp-config.php'],
    ['wp-content\\..\\wp-config.php'],
    ['./wp-config.php'],
    ['wp-content//themes'],
    ['a/./b'],
    ['file://etc/passwd'],
    ['phar://x.phar/a'],
    ['a\u0000b'],
    ['a\nb'],
    ['a/b:stream'],
    ['x'.repeat(1025)],
  ])('rejects %j', (input) => {
    expect(() => normalizeRel(input)).toThrow(PathError);
  });

  it('treats encoded traversal literally (no decoding)', () => {
    expect(normalizeRel('%2e%2e/wp-config.php')).toBe('%2e%2e/wp-config.php');
  });

  it('accepts the root marker only in normalizeRelOrRoot', () => {
    expect(normalizeRelOrRoot('.')).toBe('.');
    expect(normalizeRelOrRoot('')).toBe('.');
    expect(normalizeRelOrRoot('./wp-content')).toBe('wp-content');
    expect(() => normalizeRel('.')).toThrow(PathError);
  });
});

describe('native conversion', () => {
  it('converts to Windows native paths under the root', () => {
    expect(toNative('C:\\proj', 'wp-content/themes/child/a.php', path.win32)).toBe('C:\\proj\\wp-content\\themes\\child\\a.php');
  });

  it('converts to POSIX native paths under the root', () => {
    expect(toNative('/home/u/proj', 'wp-content/a.php', path.posix)).toBe('/home/u/proj/wp-content/a.php');
  });

  it('refuses escaping paths', () => {
    expect(() => toNative('C:\\proj', '../x', path.win32)).toThrow(PathError);
  });

  it('converts Windows native paths back to internal form', () => {
    expect(fromNative('C:\\proj', 'C:\\proj\\wp-content\\themes\\x.php', path.win32)).toBe('wp-content/themes/x.php');
    expect(fromNative('C:\\Proj', 'c:\\proj\\wp-content\\x.php', path.win32)).toBe('wp-content/x.php');
  });

  it('refuses native paths outside the root or on another drive', () => {
    expect(() => fromNative('C:\\proj', 'C:\\other\\x.php', path.win32)).toThrow(PathError);
    expect(() => fromNative('C:\\proj', 'D:\\proj\\x.php', path.win32)).toThrow(PathError);
    expect(() => fromNative('C:\\proj', 'C:\\proj', path.win32)).toThrow(PathError);
  });
});

describe('helpers', () => {
  it('isInside is case-insensitive by default and segment-aware', () => {
    expect(isInside('wp-content/themes/Child/a.php', 'wp-content/themes/child')).toBe(true);
    expect(isInside('wp-content/themes/child', 'wp-content/themes/child')).toBe(true);
    expect(isInside('wp-content/themes/child2/a.php', 'wp-content/themes/child')).toBe(false);
    expect(isInside('wp-content/themes/Child/a.php', 'wp-content/themes/child', false)).toBe(false);
    expect(isInside('anything', '.')).toBe(true);
  });

  it('isAncestor', () => {
    expect(isAncestor('wp-content', 'wp-content/themes/child')).toBe(true);
    expect(isAncestor('wp-content/themes/child', 'wp-content/themes/child')).toBe(false);
    expect(isAncestor('.', 'wp-content')).toBe(true);
  });

  it('parentRel and relativeTo', () => {
    expect(parentRel('a/b/c.php')).toBe('a/b');
    expect(parentRel('c.php')).toBe('.');
    expect(relativeTo('a/b/c.php', 'a')).toBe('b/c.php');
    expect(relativeTo('a/b', '.')).toBe('a/b');
  });
});
