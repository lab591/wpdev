import { existsSync } from 'node:fs';
import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { unzipSync } from 'fflate';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { deployCommand, hookDeploy, readHookInput, reportDeploy } from '../src/commands/deploy.js';
import { pullCommand } from '../src/commands/pull.js';
import { healthCommand, rollbackCommand } from '../src/commands/rollback.js';
import { parseConfig, type Config } from '../src/config.js';
import type { Context } from '../src/context.js';
import { computeChanges, runDeploy } from '../src/deploy.js';
import { xxh128 } from '../src/hash.js';
import { ApiClient } from '../src/http.js';
import { execRunner, lintPhp, lintWithParser, parseLintOutput, type Runner } from '../src/lint.js';
import { formatDeployOutcome } from '../src/mcp/format.js';
import { buildTools } from '../src/mcp/tools.js';
import { memoryOutput } from '../src/output.js';
import { loadRescue } from '../src/rescue.js';
import { State } from '../src/state.js';
import { startMockServer, text, type MockServer } from './helpers/mockServer.js';

const ROOT = 'wp-content/themes/child';
let mock: MockServer;
let dir: string;
let ctx: Context;

function local(rel: string): string {
  return path.join(dir, ...rel.split('/'));
}

async function writeLocal(rel: string, content: string): Promise<void> {
  await mkdir(path.dirname(local(rel)), { recursive: true });
  await writeFile(local(rel), content);
}

function makeContext(overrides: Record<string, unknown> = {}): Context {
  const config: Config = parseConfig({ site: mock.url, user: mock.site.user, writable: [ROOT], ...overrides }, dir, { insecureLocal: true });
  return { config, client: new ApiClient({ siteUrl: config.siteUrl, user: mock.site.user, password: mock.site.password }) };
}

/** A runner that fakes `php -l`: files containing "SYNTAX" fail. */
const fakePhp: Runner = async (_cmd, args) => {
  const content = await readFile(args[1] as string, 'utf8');
  return content.includes('SYNTAX')
    ? { missing: false, code: 255, output: `PHP Parse error:  syntax error, unexpected end of file in ${args[1]} on line 3\nErrors parsing ${args[1]}` }
    : { missing: false, code: 0, output: `No syntax errors detected in ${args[1]}` };
};
const noPhp: Runner = async () => ({ missing: true });

beforeEach(async () => {
  mock = await startMockServer({
    mode: 'write',
    files: new Map([
      [`${ROOT}/style.css`, text('body{}\n')],
      [`${ROOT}/functions.php`, text('<?php\r\n// crlf kept\r\n')],
      [`${ROOT}/inc/a.php`, text('<?php // a\n')],
    ]),
  });
  dir = await mkdtemp(path.join(tmpdir(), 'wpdev-deploy-'));
  ctx = makeContext();
  expect(await pullCommand(ctx, memoryOutput())).toBe(0);
});

afterEach(async () => {
  await mock.close();
  await rm(dir, { recursive: true, force: true });
});

describe('computeChanges', () => {
  it('detects new, modified and deleted files and ignores excluded ones', async () => {
    await writeLocal(`${ROOT}/style.css`, 'body{color:red}\n');
    await writeLocal(`${ROOT}/new dir/nuovo è.php`, '<?php // new\n');
    await writeLocal(`${ROOT}/node_modules/x.js`, 'ignored');
    await rm(local(`${ROOT}/inc/a.php`));
    const changes = await computeChanges(ctx.config, await State.load(dir));
    expect(changes.map((c) => [c.status, c.p])).toEqual([
      ['deleted', `${ROOT}/inc/a.php`],
      ['new', `${ROOT}/new dir/nuovo è.php`],
      ['modified', `${ROOT}/style.css`],
    ]);
    expect(changes.find((c) => c.status === 'new')?.base_h).toBeNull();
    expect(changes.find((c) => c.status === 'modified')?.base_h).toBe(await xxh128(text('body{}\n')));
  });

  it('does not delete when allowDelete is false', async () => {
    await rm(local(`${ROOT}/inc/a.php`));
    const noDelete = makeContext({ deploy: { allowDelete: false, lintPhp: true } });
    expect(await computeChanges(noDelete.config, await State.load(dir))).toEqual([]);
  });

  it('exits without network when nothing changed', async () => {
    const before = mock.site.requests.length;
    const out = memoryOutput();
    expect(await deployCommand(ctx, out, {}, { runner: fakePhp })).toBe(0);
    expect(out.lines).toEqual(['Nessuna modifica da pubblicare.']);
    expect(mock.site.requests.length).toBe(before);
  });
});

describe('lint', () => {
  it('blocks the deploy on syntax errors before any upload', async () => {
    await writeLocal(`${ROOT}/inc/a.php`, '<?php\nfunction x( {\nSYNTAX\n');
    const before = mock.site.requests.length;
    const out = memoryOutput();
    expect(await deployCommand(ctx, out, {}, { runner: fakePhp })).toBe(1);
    expect(out.warnings.join('\n')).toContain(`${ROOT}/inc/a.php:3  Parse error:  syntax error, unexpected end of file`);
    expect(out.warnings.join('\n')).not.toContain(dir);
    expect(mock.site.requests.length).toBe(before);
  });

  it('without PHP it checks with the built-in parser: valid code is deployed', async () => {
    await writeLocal(`${ROOT}/inc/a.php`, '<?php // changed\n');
    const out = memoryOutput();
    expect(await deployCommand(ctx, out, {}, { runner: noPhp })).toBe(0);
    expect(out.warnings.join('\n')).not.toContain('lint saltato');
    expect(mock.site.lastDeploy).toBeDefined();
  });

  it('without PHP a syntax error is still blocked before any upload', async () => {
    await writeLocal(`${ROOT}/inc/a.php`, '<?php\nfunction x( {\n');
    const before = mock.site.requests.length;
    const out = memoryOutput();
    expect(await deployCommand(ctx, out, {}, { runner: noPhp })).toBe(1);
    const text = out.warnings.join('\n');
    expect(text).toMatch(new RegExp(`${ROOT}/inc/a\\.php:\\d+ `));
    expect(text).toContain('parser PHP integrato');
    expect(text).not.toContain(dir);
    expect(mock.site.requests.length).toBe(before);
  });

  it('the built-in parser accepts modern PHP (8.1-8.4) and rejects broken code', async () => {
    await writeLocal(
      `${ROOT}/modern.php`,
      [
        '<?php',
        'declare(strict_types=1);',
        'namespace A\\B;',
        'enum Suit: string { case Hearts = "H"; public function label(): string { return ucfirst($this->name); } }',
        'final readonly class Point { public function __construct(public int $x = 0, public ?int $y = null) {} }',
        '#[\\Attribute] class Tag {}',
        '$f = strlen(...);',
        '$v = match (true) { $f("a") > 0 => "yes", default => "no" };',
        '$n = $obj?->prop?->method(named: 1, other: [1, 2, ...$rest]);',
        'function f(int|string $a, (A&B)|null $b = null): never { throw new \\Exception(); }',
        '',
      ].join('\n')
    );
    await writeLocal(`${ROOT}/broken.php`, '<?php\n$a = [1, 2;\n');
    const res = await lintWithParser([
      [`${ROOT}/modern.php`, local(`${ROOT}/modern.php`)],
      [`${ROOT}/broken.php`, local(`${ROOT}/broken.php`)],
    ]);
    expect(res.engine).toBe('parser');
    expect(res.checked).toBe(2);
    expect(res.errors.map((e) => e.p)).toEqual([`${ROOT}/broken.php`]);
    expect(res.errors[0]?.line).toBe(2);
  });

  it('parses php -l output with Windows paths and spaces', () => {
    const abs = 'C:\\Users\\me\\My Site\\wp-content\\themes\\child\\a b.php';
    const e = parseLintOutput('wp-content/themes/child/a b.php', abs, `PHP Parse error:  syntax error, unexpected '}' in ${abs} on line 12\nErrors parsing ${abs}`);
    expect(e).toEqual({ p: 'wp-content/themes/child/a b.php', line: 12, message: "Parse error:  syntax error, unexpected '}'" });
  });

  const php = (() => {
    try {
      execFileSync('php', ['-v'], { stdio: 'ignore' });
      return true;
    } catch {
      return false;
    }
  })();
  it.skipIf(!php)('uses the real php -l when available', async () => {
    await writeLocal(`${ROOT}/bad.php`, '<?php\nfunction (\n');
    await writeLocal(`${ROOT}/good.php`, '<?php\necho 1;\n');
    const res = await lintPhp('php', [
      [`${ROOT}/bad.php`, local(`${ROOT}/bad.php`)],
      [`${ROOT}/good.php`, local(`${ROOT}/good.php`)],
    ], execRunner);
    expect(res.checked).toBe(2);
    expect(res.errors.map((e) => e.p)).toEqual([`${ROOT}/bad.php`]);
  });
});

describe('deploy', () => {
  it('sends manifest and zip with exact bytes, updates state and saves the rescue token', async () => {
    await writeLocal(`${ROOT}/style.css`, 'body{color:red}\r\n');
    await writeLocal(`${ROOT}/new/x.js`, 'console.log(1)\n');
    await rm(local(`${ROOT}/inc/a.php`));
    const out = memoryOutput();
    expect(await deployCommand(ctx, out, {}, { runner: fakePhp })).toBe(0);

    const sent = mock.site.lastDeploy!;
    expect(sent.manifest.force).toBe(false);
    expect(sent.manifest.files).toEqual([
      { p: `${ROOT}/inc/a.php`, action: 'delete', base_h: await xxh128(text('<?php // a\n')) },
      { p: `${ROOT}/new/x.js`, action: 'write', h: await xxh128(text('console.log(1)\n')), base_h: null },
      { p: `${ROOT}/style.css`, action: 'write', h: await xxh128(text('body{color:red}\r\n')), base_h: await xxh128(text('body{}\n')) },
    ]);
    const entries = unzipSync(sent.bundle!);
    expect(Object.keys(entries).sort()).toEqual([`${ROOT}/new/x.js`, `${ROOT}/style.css`]);
    expect(Buffer.from(entries[`${ROOT}/style.css`]!).toString('utf8')).toBe('body{color:red}\r\n');

    const state = await State.load(dir);
    expect(state.get(`${ROOT}/style.css`)?.h_base).toBe(await xxh128(text('body{color:red}\r\n')));
    expect(state.get(`${ROOT}/inc/a.php`)).toBeUndefined();
    const rescue = await loadRescue(dir);
    expect(rescue).toMatchObject({ release_id: '20260923-101500-abc123', token: 'a'.repeat(64) });
    expect([...out.lines, ...out.warnings].join('\n')).not.toContain('a'.repeat(64));
    expect(out.lines[0]).toBe('Release 20260923-101500-abc123: 2 file scritti, 1 cancellati.');

    // Nothing left to deploy afterwards.
    expect(await computeChanges(ctx.config, await State.load(dir))).toEqual([]);
  });

  it('no release (content already on the server): state aligned, previous rescue token kept', async () => {
    await writeLocal(`${ROOT}/style.css`, 'body{color:red}\n');
    expect(await deployCommand(ctx, memoryOutput(), {}, { runner: fakePhp })).toBe(0);
    const token = await loadRescue(dir);
    expect(token).toBeDefined();

    mock.site.deployResult = { release_id: null, status: 'ok', written: 0, deleted: 0, health: { status: 'skipped', checks: [] } };
    await writeLocal(`${ROOT}/style.css`, 'body{color:blue}\n');
    const out = memoryOutput();
    expect(await deployCommand(ctx, out, {}, { runner: fakePhp })).toBe(0);
    expect(out.lines.join('\n')).toContain('nessuna release creata');
    expect(await loadRescue(dir)).toEqual(token);
    expect(await computeChanges(ctx.config, await State.load(dir))).toEqual([]);
  });

  it('omits the bundle when only deleting', async () => {
    await rm(local(`${ROOT}/style.css`));
    expect(await deployCommand(ctx, memoryOutput(), {}, { runner: fakePhp })).toBe(0);
    expect(mock.site.lastDeploy?.bundle).toBeUndefined();
  });

  it('dry run uploads nothing', async () => {
    await writeLocal(`${ROOT}/style.css`, 'x');
    const out = memoryOutput();
    expect(await deployCommand(ctx, out, { dryRun: true }, { runner: fakePhp })).toBe(0);
    expect(out.lines.join('\n')).toContain(`modificato ${ROOT}/style.css`);
    expect(mock.site.lastDeploy).toBeUndefined();
  });

  it('rolled_back: exit 2, errors shown, state untouched', async () => {
    mock.site.deployResult = {
      release_id: 'r1', status: 'rolled_back', written: 1, deleted: 0,
      health: { status: 'fail', checks: [{ url: 'http://x/', code: 500, ms: 9 }] },
      errors: ['[23-Sep-2026 10:15:01 UTC] PHP Fatal error: Uncaught Error in functions.php:3'],
    };
    await writeLocal(`${ROOT}/style.css`, 'broken');
    const before = (await State.load(dir)).get(`${ROOT}/style.css`);
    const out = memoryOutput();
    expect(await deployCommand(ctx, out, {}, { runner: fakePhp })).toBe(2);
    expect(out.warnings.join('\n')).toContain('rollback automatico');
    expect(out.warnings.join('\n')).toContain('PHP Fatal error: Uncaught Error');
    expect((await State.load(dir)).get(`${ROOT}/style.css`)).toEqual(before);
    expect(await loadRescue(dir)).toBeUndefined();
  });

  it('health_unknown: warns explicitly but succeeds', async () => {
    mock.site.deployResult = {
      release_id: 'r2', status: 'health_unknown', written: 1, deleted: 0,
      health: { status: 'unknown', checks: [{ url: 'http://x/', error: 'cURL error 7' }], message: 'loopback unreachable' },
      rescue_token: 'b'.repeat(64),
    };
    await writeLocal(`${ROOT}/style.css`, 'x');
    const out = memoryOutput();
    expect(await deployCommand(ctx, out, {}, { runner: fakePhp })).toBe(0);
    expect(out.warnings.join('\n')).toContain('ATTENZIONE: health check non eseguibile');
  });

  it('409 conflict lists the files and suggests pull or --force', async () => {
    mock.site.files.set(`${ROOT}/style.css`, text('changed on server'));
    await writeLocal(`${ROOT}/style.css`, 'changed locally');
    const out = memoryOutput();
    expect(await deployCommand(ctx, out, {}, { runner: fakePhp })).toBe(1);
    const w = out.warnings.join('\n');
    expect(w).toContain(`${ROOT}/style.css (modified)`);
    expect(w).toContain('wpdev pull');
    expect(await deployCommand(ctx, memoryOutput(), { force: true }, { runner: fakePhp })).toBe(0);
    expect(mock.site.lastDeploy?.manifest.force).toBe(true);
  });

  it('403 when write mode is not active', async () => {
    mock.site.mode = 'read';
    await writeLocal(`${ROOT}/style.css`, 'x');
    const out = memoryOutput();
    expect(await deployCommand(ctx, out, {}, { runner: fakePhp })).toBe(1);
    expect(out.warnings.join('\n')).toContain('wp devbridge enable --mode=write --hours=N');
  });

  it('warns before the first deploy when the rescue mu-plugin is missing', async () => {
    mock.site.rescueStatus = 'missing';
    await writeLocal(`${ROOT}/style.css`, 'x');
    const out = memoryOutput();
    expect(await deployCommand(ctx, out, {}, { runner: fakePhp })).toBe(0);
    expect(out.warnings.join('\n')).toContain('mu-plugin rescue non installato');
  });
});

describe('deploy --hook', () => {
  function streams(): { out: string[]; err: string[]; s: { stdout: (l: string) => void; stderr: (l: string) => void } } {
    const out: string[] = [];
    const err: string[] = [];
    return { out, err, s: { stdout: (l) => out.push(l), stderr: (l) => err.push(l) } };
  }

  it('is silent when there are no changes', async () => {
    const st = streams();
    expect(await hookDeploy(() => ctx, { hook_event_name: 'Stop' }, st.s, { runner: fakePhp })).toBe(0);
    expect(st.out).toEqual([]);
    expect(st.err).toEqual([]);
  });

  it('prints one line on success', async () => {
    await writeLocal(`${ROOT}/style.css`, 'x');
    const st = streams();
    expect(await hookDeploy(() => ctx, {}, st.s, { runner: fakePhp })).toBe(0);
    expect(st.out).toHaveLength(1);
    expect(st.out[0]).toContain('Release 20260923-101500-abc123');
  });

  it('exits 2 with a summary on stderr when the deploy fails', async () => {
    await writeLocal(`${ROOT}/inc/a.php`, 'SYNTAX');
    const st = streams();
    expect(await hookDeploy(() => ctx, { stop_hook_active: false }, st.s, { runner: fakePhp })).toBe(2);
    expect(st.err.join('\n')).toContain(`${ROOT}/inc/a.php:3`);
    expect(st.err.join('\n')).toContain('NON sono online');
  });

  it('exits 0 when stop_hook_active and it fails again (no loops)', async () => {
    await writeLocal(`${ROOT}/inc/a.php`, 'SYNTAX');
    const st = streams();
    expect(await hookDeploy(() => ctx, { stop_hook_active: true }, st.s, { runner: fakePhp })).toBe(0);
    expect(st.err.join('\n')).toContain('evitare un ciclo');
  });

  it('exits 2 on configuration errors too', async () => {
    const st = streams();
    const code = await hookDeploy(() => {
      throw new Error('wpdev.json non trovato');
    }, {}, st.s);
    expect(code).toBe(2);
    expect(st.err.join('\n')).toContain('wpdev.json non trovato');
  });

  it('reads the hook JSON from a non-TTY stream and never hangs', async () => {
    const { PassThrough } = await import('node:stream');
    const s1 = new PassThrough();
    const p1 = readHookInput(s1 as unknown as NodeJS.ReadStream, 1000);
    s1.end('{"hook_event_name":"Stop","stop_hook_active":true,"cwd":"C:\\\\proj"}');
    expect(await p1).toMatchObject({ stop_hook_active: true, cwd: 'C:\\proj' });

    const s2 = new PassThrough(); // never ends
    const started = Date.now();
    expect(await readHookInput(s2 as unknown as NodeJS.ReadStream, 100)).toEqual({});
    expect(Date.now() - started).toBeLessThan(1000);
  });
});

describe('rollback', () => {
  it('normal rollback updates the state and drops the rescue token', async () => {
    await writeLocal(`${ROOT}/style.css`, 'v2');
    expect(await deployCommand(ctx, memoryOutput(), {}, { runner: fakePhp })).toBe(0);
    mock.site.rollbackFiles = [{ p: `${ROOT}/style.css`, h: await xxh128(text('body{}\n')) }, { p: `${ROOT}/gone.php`, h: null }];
    const out = memoryOutput();
    expect(await rollbackCommand(ctx, out, {})).toBe(0);
    const state = await State.load(dir);
    expect(state.get(`${ROOT}/style.css`)?.h_base).toBe(await xxh128(text('body{}\n')));
    expect(await loadRescue(dir)).toBeUndefined();
    // Local file still has v2: it shows up as a local modification to redeploy.
    expect((await computeChanges(ctx.config, state)).map((c) => c.p)).toEqual([`${ROOT}/style.css`]);
  });

  it('suggests the rescue when the server answers 5xx', async () => {
    mock.site.failWith = { endpoint: 'rollback', status: 500, body: '<html>fatal</html>' };
    const out = memoryOutput();
    expect(await rollbackCommand(ctx, out, {})).toBe(1);
    expect(out.warnings.join('\n')).toContain('wpdev rollback --rescue');

    // In a TTY the user can accept the rescue right away.
    await writeLocal(`${ROOT}/style.css`, 'v2');
    mock.site.failWith = undefined;
    expect(await deployCommand(ctx, memoryOutput(), {}, { runner: fakePhp })).toBe(0);
    mock.site.failWith = { endpoint: 'rollback', status: 500, body: '<html>fatal</html>' };
    mock.site.rescueToken = 'a'.repeat(64);
    const questions: string[] = [];
    expect(await rollbackCommand(ctx, memoryOutput(), {}, async (q) => (questions.push(q), true))).toBe(0);
    expect(questions).toHaveLength(1);
  });

  it('--rescue uses the token header on the site root', async () => {
    await writeLocal(`${ROOT}/style.css`, 'v2');
    expect(await deployCommand(ctx, memoryOutput(), {}, { runner: fakePhp })).toBe(0);
    mock.site.rescueToken = 'a'.repeat(64);
    mock.site.rescueFiles = [{ p: `${ROOT}/style.css`, h: await xxh128(text('body{}\n')) }];
    const out = memoryOutput();
    expect(await rollbackCommand(ctx, out, { rescue: true })).toBe(0);
    expect(out.lines[0]).toContain('Rescue eseguito');
    expect(await loadRescue(dir)).toBeUndefined();
    expect((await State.load(dir)).get(`${ROOT}/style.css`)?.h_base).toBe(await xxh128(text('body{}\n')));
  });

  it('--rescue reports non-JSON answers as unavailable and wrong tokens as errors', async () => {
    await writeLocal(`${ROOT}/style.css`, 'v2');
    expect(await deployCommand(ctx, memoryOutput(), {}, { runner: fakePhp })).toBe(0);
    const out = memoryOutput();
    expect(await rollbackCommand(ctx, out, { rescue: true })).toBe(1);
    expect(out.warnings.join('\n')).toContain('Rescue non disponibile');
    mock.site.rescueToken = 'c'.repeat(64);
    const out2 = memoryOutput();
    expect(await rollbackCommand(ctx, out2, { rescue: true })).toBe(1);
    expect(out2.warnings.join('\n')).toContain('rescue_denied');
    expect(await loadRescue(dir)).toBeDefined();
  });

  it('--rescue without a saved token', async () => {
    const out = memoryOutput();
    expect(await rollbackCommand(ctx, out, { rescue: true })).toBe(1);
    expect(out.warnings.join('\n')).toContain('Nessun token di rescue');
  });

  it('sends health.paths from wpdev.json with the deploy and the on-demand check', async () => {
    const withPaths = makeContext({ health: { paths: ['/shop/', '/contatti/'] } });
    await writeLocal(`${ROOT}/style.css`, 'body{color:green}\n');
    expect(await deployCommand(withPaths, memoryOutput(), {}, { runner: fakePhp })).toBe(0);
    expect(mock.site.lastDeploy?.manifest.health_paths).toEqual(['/shop/', '/contatti/']);

    await healthCommand(withPaths, memoryOutput());
    expect(mock.site.lastHealthBody).toEqual({ paths: ['/shop/', '/contatti/'] });
  });

  it('omits health_paths when none are configured', async () => {
    await writeLocal(`${ROOT}/style.css`, 'body{color:green}\n');
    expect(await deployCommand(ctx, memoryOutput(), {}, { runner: fakePhp })).toBe(0);
    expect(mock.site.lastDeploy?.manifest.health_paths).toBeUndefined();
  });

  it('health prints checks and recent errors', async () => {
    const out = memoryOutput();
    expect(await healthCommand(ctx, out)).toBe(1);
    expect(out.lines.join('\n')).toContain('http://localhost/  500');
    expect(out.warnings.join('\n')).toContain('PHP Fatal error: boom');
  });
});

describe('MCP deploy tools', () => {
  it('exposes the M2 tools and never takes file contents', () => {
    const tools = buildTools(() => ctx, { runner: fakePhp });
    expect(tools.map((t) => t.name)).toEqual(
      expect.arrayContaining(['deploy', 'rollback', 'health', 'cache_flush']),
    );
    const deploy = tools.find((t) => t.name === 'deploy')!;
    expect(Object.keys(deploy.inputSchema)).toEqual(['dry_run']);
  });

  it('deploy / cache_flush / rollback produce compact text', async () => {
    const tools = Object.fromEntries(buildTools(() => ctx, { runner: fakePhp }).map((t) => [t.name, t]));
    expect((await tools.deploy!.handler({})).text).toContain('nothing to deploy');
    await writeLocal(`${ROOT}/style.css`, 'x');
    const dry = await tools.deploy!.handler({ dry_run: true });
    expect(dry.text).toBe(`dry run: 0 new, 1 modified, 0 deleted\n  modified ${ROOT}/style.css`);
    const res = await tools.deploy!.handler({});
    expect(res.isError).toBeUndefined();
    expect(res.text).toContain('release 20260923-101500-abc123: 1 written, 0 deleted');
    expect(res.text).toContain('health: ok');
    expect((await tools.cache_flush!.handler({ targets: ['opcache'] })).text).toBe('cache flush: opcache ok');
    mock.site.rollbackFiles = [{ p: `${ROOT}/style.css`, h: await xxh128(text('body{}\n')) }];
    expect((await tools.rollback!.handler({})).text).toContain('1 file(s) restored');
  });

  it('formats failures as errors', async () => {
    const lint = formatDeployOutcome({ kind: 'lint_failed', changes: [], lint: { errors: [{ p: 'a.php', line: 2, message: 'syntax error' }], checked: 1 } });
    expect(lint).toEqual({ text: 'deploy blocked: PHP syntax errors (nothing uploaded):\n  a.php:2 syntax error', isError: true });
    mock.site.mode = 'read';
    await writeLocal(`${ROOT}/style.css`, 'x');
    const outcome = await runDeploy(ctx, {}, { runner: fakePhp });
    const f = formatDeployOutcome(outcome);
    expect(f.isError).toBe(true);
    expect(f.text).toContain('write mode is not active');
    expect(reportDeploy(outcome).code).toBe(1);
  });
});

it('state file is only written inside .wpdev', () => {
  expect(existsSync(path.join(dir, '.wpdev', 'state.json'))).toBe(true);
});
