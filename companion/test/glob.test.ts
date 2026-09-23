import { describe, expect, it } from 'vitest';
import { matchAny, matchGlob } from '../src/glob.js';

describe('glob', () => {
  it('pattern without slash matches the basename at any level', () => {
    expect(matchGlob('wp-config.php', 'wp-config.php')).toBe(true);
    expect(matchGlob('wp-config.php', 'sub/wp-config.php')).toBe(true);
    expect(matchGlob('wp-config-*.php', 'wp-config-sample.php')).toBe(true);
    expect(matchGlob('*.php', 'a/b/c.php')).toBe(true);
    expect(matchGlob('*.php', 'a/b/c.php.txt')).toBe(false);
  });

  it('**/ matches zero or more directories', () => {
    expect(matchGlob('**/.env*', '.env')).toBe(true);
    expect(matchGlob('**/.env*', 'a/b/.env.local')).toBe(true);
    expect(matchGlob('**/*.sql', 'dump.sql')).toBe(true);
    expect(matchGlob('**/*.sql', 'a/dump.sql')).toBe(true);
  });

  it('trailing /** matches everything inside (and the folder itself)', () => {
    expect(matchGlob('wp-content/uploads/**', 'wp-content/uploads/2024/a.jpg')).toBe(true);
    expect(matchGlob('wp-content/uploads/**', 'wp-content/uploads')).toBe(true);
    expect(matchGlob('wp-content/uploads/**', 'wp-content/uploads2/a.jpg')).toBe(false);
    expect(matchGlob('**/.git/**', 'a/.git/HEAD')).toBe(true);
    expect(matchGlob('**/node_modules/**', 'wp-content/themes/x/node_modules')).toBe(true);
    expect(matchGlob('wp-content/devbridge-*/**', 'wp-content/devbridge-a1b2/releases/x')).toBe(true);
  });

  it('* and ? do not cross directory separators', () => {
    expect(matchGlob('wp-content/*.php', 'wp-content/a/b.php')).toBe(false);
    expect(matchGlob('a/?.php', 'a/b.php')).toBe(true);
    expect(matchGlob('a/?.php', 'a/bc.php')).toBe(false);
  });

  it('escapes regex metacharacters', () => {
    expect(matchGlob('a+b(1).php', 'a+b(1).php')).toBe(true);
    expect(matchGlob('a.php', 'aXphp')).toBe(false);
  });

  it('supports case-insensitive matching', () => {
    expect(matchGlob('wp-config.php', 'WP-Config.PHP')).toBe(false);
    expect(matchGlob('wp-config.php', 'WP-Config.PHP', true)).toBe(true);
    expect(matchAny(['*.log', '*.map'], 'x/app.js.map')).toBe(true);
  });
});
