import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { hasNoTextAttribute } from '../src/git.js';

describe('hasNoTextAttribute', () => {
  let dir: string;
  beforeEach(async () => {
    dir = await mkdtemp(path.join(tmpdir(), 'wpdev-git-'));
  });
  afterEach(async () => {
    await rm(dir, { recursive: true, force: true });
  });

  it('is false without .gitattributes', async () => {
    expect(await hasNoTextAttribute(dir)).toBe(false);
  });

  it('detects "* -text" among other lines (CRLF too)', async () => {
    await writeFile(path.join(dir, '.gitattributes'), '# comment\r\n*.png binary\r\n* -text\r\n');
    expect(await hasNoTextAttribute(dir)).toBe(true);
  });

  it('ignores narrower patterns', async () => {
    await writeFile(path.join(dir, '.gitattributes'), '*.php -text\n* text=auto\n');
    expect(await hasNoTextAttribute(dir)).toBe(false);
  });
});
