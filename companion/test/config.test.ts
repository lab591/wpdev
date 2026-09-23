import { mkdir, mkdtemp, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { ConfigError, findProjectRoot, isValidHealthPath, loadConfig, parseConfig, parseEnvFile, resolvePassword, validateSiteUrl } from '../src/config.js';

const base = { site: 'https://example.com/', user: 'claudio', writable: ['wp-content\\themes\\child'] };

describe('validateSiteUrl', () => {
  it('accepts https and strips the trailing slash', () => {
    expect(validateSiteUrl('https://example.com/')).toBe('https://example.com');
    expect(validateSiteUrl('https://example.com/sub/')).toBe('https://example.com/sub');
  });

  it('rejects http without --insecure-local', () => {
    expect(() => validateSiteUrl('http://localhost')).toThrow(ConfigError);
  });

  it('accepts http only for local hosts with --insecure-local', () => {
    expect(validateSiteUrl('http://localhost/site', true)).toBe('http://localhost/site');
    expect(validateSiteUrl('http://mysite.local', true)).toBe('http://mysite.local');
    expect(validateSiteUrl('http://mysite.test:8080', true)).toBe('http://mysite.test:8080');
    expect(() => validateSiteUrl('http://example.com', true)).toThrow(ConfigError);
    expect(() => validateSiteUrl('http://localhost.evil.com', true)).toThrow(ConfigError);
    expect(() => validateSiteUrl('http://192.168.1.2', true)).toThrow(ConfigError);
  });

  it('rejects credentials, queries and other protocols', () => {
    expect(() => validateSiteUrl('https://u:p@example.com')).toThrow(ConfigError);
    expect(() => validateSiteUrl('https://example.com/?x=1')).toThrow(ConfigError);
    expect(() => validateSiteUrl('ftp://example.com')).toThrow(ConfigError);
    expect(() => validateSiteUrl('not a url')).toThrow(ConfigError);
  });
});

describe('parseConfig', () => {
  it('applies defaults and normalizes writable paths', () => {
    const c = parseConfig(base, 'C:\\proj');
    expect(c.siteUrl).toBe('https://example.com');
    expect(c.writable).toEqual(['wp-content/themes/child']);
    expect(c.passwordEnv).toBe('WPDEV_APP_PASSWORD');
    expect(c.exclude).toContain('**/node_modules/**');
    expect(c.deploy).toEqual({ allowDelete: true, lintPhp: true, gitCommit: true, target: 'live' });
    expect(c.cache.trustWindowSec).toBe(60);
  });

  it('rejects invalid writable paths and duplicates', () => {
    expect(() => parseConfig({ ...base, writable: ['../x'] }, '/p')).toThrow(ConfigError);
    expect(() => parseConfig({ ...base, writable: ['C:\\x'] }, '/p')).toThrow(ConfigError);
    expect(() => parseConfig({ ...base, writable: ['a/b', 'A/B'] }, '/p')).toThrow(ConfigError);
  });

  it('rejects a missing user', () => {
    expect(() => parseConfig({ site: 'https://x.com' }, '/p')).toThrow(ConfigError);
  });
});

describe('password', () => {
  it('parses .env files', () => {
    expect(parseEnvFile('# c\nA=1\nexport B="x y"\nC=\'z\'\nD=v # comment\r\n')).toEqual({ A: '1', B: 'x y', C: 'z', D: 'v' });
  });

  it('prefers the environment, falls back to .env.local', async () => {
    const dir = await mkdtemp(path.join(tmpdir(), 'wpdev-cfg-'));
    await writeFile(path.join(dir, '.env.local'), 'WPDEV_APP_PASSWORD="aaaa bbbb cccc"\n');
    const cfg = { passwordEnv: 'WPDEV_APP_PASSWORD', projectRoot: dir };
    expect(resolvePassword(cfg, {})).toBe('aaaa bbbb cccc');
    expect(resolvePassword(cfg, { WPDEV_APP_PASSWORD: 'env' })).toBe('env');
    expect(resolvePassword({ passwordEnv: 'OTHER', projectRoot: dir }, {})).toBeUndefined();
  });
});

describe('loadConfig', () => {
  it('finds wpdev.json in a parent folder', async () => {
    const dir = await mkdtemp(path.join(tmpdir(), 'wpdev-cfg-'));
    await writeFile(path.join(dir, 'wpdev.json'), JSON.stringify(base));
    const sub = path.join(dir, 'wp-content', 'themes');
    await mkdir(sub, { recursive: true });
    expect(findProjectRoot(sub)).toBe(dir);
    expect(loadConfig({ cwd: sub }).projectRoot).toBe(dir);
  });

  it('fails clearly when wpdev.json is missing or invalid', async () => {
    const dir = await mkdtemp(path.join(tmpdir(), 'wpdev-cfg-'));
    expect(() => loadConfig({ cwd: dir })).toThrow(/wpdev init/);
    await writeFile(path.join(dir, 'wpdev.json'), '{ nope');
    expect(() => loadConfig({ cwd: dir })).toThrow(ConfigError);
  });
});

describe('health paths', () => {
  it('accepts site-relative paths only', () => {
    for (const ok of ['/', '/shop/', '/contatti/?utm=1', '/città/']) expect(isValidHealthPath(ok)).toBe(true);
    for (const bad of ['shop/', '//evil.test/', '/a/../b', '/a/%2e%2e/b', '/a#b', '/@evil', '/a b', '/a\\b', '']) {
      expect(isValidHealthPath(bad)).toBe(false);
    }
  });

  it('accepts full http(s) URLs (network sites, validated by the server) with safe paths', () => {
    for (const ok of ['https://example.com/negozio/', 'http://example.com/?p=1']) expect(isValidHealthPath(ok)).toBe(true);
    for (const bad of ['https://u:p@example.com/', 'https://example.com:8080/', 'https://example.com/a#b', 'https://example.com/a/../b', 'ftp://example.com/']) {
      expect(isValidHealthPath(bad)).toBe(false);
    }
  });

  it('is an optional wpdev.json section with at most 10 valid paths', () => {
    const base = { site: 'https://example.com', user: 'u' };
    expect(parseConfig(base, '/p').health.paths).toEqual([]);
    expect(parseConfig({ ...base, health: { paths: ['/shop/'] } }, '/p').health.paths).toEqual(['/shop/']);
    expect(() => parseConfig({ ...base, health: { paths: ['https://u:p@evil.test/'] } }, '/p')).toThrow(ConfigError);
    expect(() => parseConfig({ ...base, health: { paths: Array.from({ length: 11 }, (_, i) => `/p${i}/`) } }, '/p')).toThrow(ConfigError);
  });
});
