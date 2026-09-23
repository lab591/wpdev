import { mkdtemp, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { xxh128, xxh128File } from '../src/hash.js';

// Reference values produced by PHP 8.3: hash('xxh128', ...)
const PHP = {
  abc: '06b05ab6733a618578af5f94892f3950',
  empty: '99aa06d3014798d86001c324468d497f',
  ab5000: '38cd9b860c3ded0e9543313ee06988c8',
};

describe('xxh128', () => {
  it('matches PHP hash("xxh128")', async () => {
    expect(await xxh128('abc')).toBe(PHP.abc);
    expect(await xxh128('')).toBe(PHP.empty);
    expect(await xxh128('ab'.repeat(5000))).toBe(PHP.ab5000);
  });

  it('hashes files by streaming, identical to in-memory hashing', async () => {
    const dir = await mkdtemp(path.join(tmpdir(), 'wpdev-hash-'));
    const file = path.join(dir, 'a.txt');
    await writeFile(file, 'ab'.repeat(5000));
    expect(await xxh128File(file)).toBe(PHP.ab5000);
  });

  it('is safe under concurrent calls', async () => {
    const results = await Promise.all([xxh128('abc'), xxh128(''), xxh128('abc')]);
    expect(results).toEqual([PHP.abc, PHP.empty, PHP.abc]);
  });
});
