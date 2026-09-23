/**
 * End-to-end scenarios: a real WordPress (wp-env, Docker) and the real companion CLI.
 * Prerequisites: `npm run env:start` in this folder and `npm run build` in ../companion.
 * Run: `npm test`. Every scenario prints ok/FAIL; the exit code is the number of failures.
 */
import { execFileSync, spawnSync } from 'node:child_process';
import { appendFileSync, existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = fileURLToPath( new URL( '.', import.meta.url ) );
const CLI = join( HERE, '..', 'companion', 'dist', 'cli.js' );
const SITE = 'http://localhost:8888';
const THEME = 'wp-content/themes/e2e-child';
const isWin = process.platform === 'win32';

/** Runs a shell command inside the wp-env CLI container. */
function wp( command ) {
	return execFileSync( isWin ? 'npx.cmd' : 'npx', [ 'wp-env', 'run', 'cli', '--', 'sh', '-c', `"${ command.replace( /"/g, '\\"' ) }"` ], {
		cwd: HERE,
		encoding: 'utf8',
		shell: true,
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} );
}

/** Runs the companion CLI in the test project. */
function wpdev( project, args, input = '' ) {
	const r = spawnSync( process.execPath, [ CLI, '--insecure-local', ...args ], { cwd: project, encoding: 'utf8', input } );
	return { code: r.status, out: `${ r.stdout }\n${ r.stderr }` };
}

async function get( path ) {
	// A request right after a rollback may hit a connection being closed: retry once.
	for ( let attempt = 0; ; attempt++ ) {
		try {
			const res = await fetch( `${ SITE }${ path }`, { redirect: 'manual' } );
			return { status: res.status, body: await res.text() };
		} catch ( e ) {
			if ( attempt > 0 ) throw e;
			await new Promise( ( r ) => setTimeout( r, 1000 ) );
		}
	}
}

/** Front end and REST API answer (plain permalinks: the REST index is ?rest_route=/). */
async function healthy() {
	return ( await get( '/' ) ).status === 200 && ( await get( '/?rest_route=/' ) ).status === 200;
}

let failures = 0;
async function scenario( name, fn ) {
	try {
		await fn();
		console.log( `ok    ${ name }` );
	} catch ( e ) {
		failures++;
		console.log( `FAIL  ${ name }\n        ${ String( e.message ).split( '\n' ).join( '\n        ' ) }` );
	}
}
function expect( cond, message, detail = '' ) {
	if ( ! cond ) throw new Error( detail ? `${ message }\n${ detail }` : message );
}

// ------------------------------------------------------------------ setup

wp( 'wp plugin activate lab591-dev-bridge e2e-loopback' );
const password = /E2E_PASSWORD=(\S+)/.exec( wp( 'wp eval-file wp-content/e2e-fixtures/setup.php' ) )?.[ 1 ];
if ( ! password ) {
	console.log( 'FAIL  setup: no Application Password' );
	process.exit( 1 );
}
const project = mkdtempSync( join( tmpdir(), 'wpdev-e2e-' ) );
writeFileSync( join( project, '.env.local' ), `WPDEV_APP_PASSWORD="${ password }"\n` );
const local = ( rel ) => join( project, ...rel.split( '/' ) );
const original = readFileSync( join( HERE, 'fixtures', 'e2e-child', 'functions.php' ), 'utf8' );

// ------------------------------------------------------------------ scenarios

await scenario( 'init: connects and takes the writable folders from the site', async () => {
	const r = wpdev( project, [ 'init', '--site', SITE, '--user', 'admin', '-y' ] );
	expect( r.code === 0, 'init failed', r.out );
	expect( r.out.includes( `Cartelle scrivibili (dal sito): ${ THEME }` ), 'writable folders not read from the site', r.out );
	expect( existsSync( join( project, 'CLAUDE.md' ) ), 'CLAUDE.md missing' );
} );

await scenario( 'pull: downloads the writable folder', async () => {
	const r = wpdev( project, [ 'pull' ] );
	expect( r.code === 0, 'pull failed', r.out );
	expect( readFileSync( local( `${ THEME }/functions.php` ), 'utf8' ) === original, 'content differs from the server' );
} );

await scenario( 'info: introspection sees the theme and its shortcode', async () => {
	const overview = wpdev( project, [ 'info', 'overview' ] );
	expect( overview.out.includes( 'theme: e2e-child' ), 'theme missing', overview.out );
	const shortcodes = wpdev( project, [ 'info', 'shortcodes' ] );
	expect( shortcodes.out.includes( `[e2e_hello] {closure}  ${ THEME }/functions.php:8` ), 'shortcode location missing', shortcodes.out );
} );

await scenario( 'deploy: a change goes online, health check ok (front end and back end)', async () => {
	appendFileSync( local( `${ THEME }/style.css` ), '\n.e2e-deployed { color: red; }\n' );
	const r = wpdev( project, [ 'deploy' ] );
	expect( r.code === 0, 'deploy failed', r.out );
	expect( /Health check: ok/.test( r.out ) && r.out.includes( '[backend]' ), 'health check not ok or backend not checked', r.out );
	expect( ( await get( `/wp-content/themes/e2e-child/style.css?${ Date.now() }` ) ).body.includes( 'e2e-deployed' ), 'change not online' );
} );

await scenario( 'lint: a syntax error blocks the deploy before any upload', async () => {
	writeFileSync( local( `${ THEME }/broken.php` ), '<?php\nfunction (\n' );
	const r = wpdev( project, [ 'deploy' ] );
	expect( r.code === 1, 'deploy was not blocked', r.out );
	expect( r.out.includes( `${ THEME }/broken.php:` ), 'file and line not reported', r.out );
	expect( ( await get( '/wp-content/themes/e2e-child/broken.php' ) ).status === 404, 'file reached the server' );
	rmSync( local( `${ THEME }/broken.php` ) );
} );

for ( const [ name, code ] of [
	[ 'front end', "add_action( 'wp', function () { e2e_missing_front(); } );" ],
	[ 'admin only', "add_action( 'admin_init', function () { e2e_missing_admin(); } );" ],
	[ 'REST only', "add_action( 'rest_api_init', function () { e2e_missing_rest(); } );" ],
] ) {
	await scenario( `automatic rollback: fatal error in the ${ name }`, async () => {
		writeFileSync( local( `${ THEME }/functions.php` ), `${ original }\n${ code }\n` );
		const r = wpdev( project, [ 'deploy' ] );
		expect( r.code === 2, 'no automatic rollback', r.out );
		expect( r.out.includes( 'PHP Fatal error' ), 'fatal line not reported', r.out );
		expect( await healthy(), 'site not healthy after rollback' );
		writeFileSync( local( `${ THEME }/functions.php` ), original );
	} );
}

await scenario( 'warnings: new warnings in the published file are reported, deploy stays online', async () => {
	writeFileSync( local( `${ THEME }/functions.php` ), `${ original }\n$e2e_value = $e2e_undefined . '';\n` );
	const r = wpdev( project, [ 'deploy' ] );
	expect( r.code === 0, 'deploy failed', r.out );
	expect( r.out.includes( 'Undefined variable $e2e_undefined' ), 'warning not reported', r.out );
	writeFileSync( local( `${ THEME }/functions.php` ), original );
	expect( wpdev( project, [ 'deploy' ] ).code === 0, 'fix not deployed' );
} );

await scenario( 'rescue: out-of-band rollback of the last release', async () => {
	appendFileSync( local( `${ THEME }/style.css` ), '\n.e2e-rescue {}\n' );
	expect( wpdev( project, [ 'deploy' ] ).code === 0, 'deploy failed' );
	const r = wpdev( project, [ 'rollback', '--rescue' ] );
	expect( r.code === 0 && r.out.includes( 'Rescue eseguito' ), 'rescue failed', r.out );
	const css = ( await get( `/wp-content/themes/e2e-child/style.css?${ Date.now() }` ) ).body;
	expect( ! css.includes( 'e2e-rescue' ), 'release not undone' );
} );

await scenario( 'restore: local changes are discarded, back to the server version', async () => {
	const r = wpdev( project, [ 'restore', THEME ] );
	expect( r.code === 0, 'restore failed', r.out );
	const diff = wpdev( project, [ 'diff' ] );
	expect( diff.out.includes( 'Modificati in locale (0)' ), 'local changes left', diff.out );
} );

await scenario( 'preview: visible only with the preview cookie, then published', async () => {
	writeFileSync( local( `${ THEME }/functions.php` ), `${ original }\nadd_action( 'wp_footer', function () { echo 'E2E-PREVIEW-MARK'; } );\n` );
	const r = wpdev( project, [ 'preview' ] );
	expect( r.code === 0, 'preview failed', r.out );
	const token = /devbridge_preview=([0-9a-f]{64})/.exec( r.out )?.[ 1 ];
	expect( token, 'no preview link', r.out );
	expect( ! ( await get( '/' ) ).body.includes( 'E2E-PREVIEW-MARK' ), 'visitors see the preview' );
	const withCookie = await fetch( `${ SITE }/`, { headers: { Cookie: `devbridge_preview=${ token }` } } );
	expect( ( await withCookie.text() ).includes( 'E2E-PREVIEW-MARK' ), 'preview not shown with the cookie' );
	expect( withCookie.headers.get( 'x-devbridge-preview' ) === '1', 'preview header missing' );
	const pub = wpdev( project, [ 'preview', 'publish' ] );
	expect( pub.code === 0, 'publish failed', pub.out );
	expect( ( await get( '/' ) ).body.includes( 'E2E-PREVIEW-MARK' ), 'not live after publish' );
	writeFileSync( local( `${ THEME }/functions.php` ), original );
	expect( wpdev( project, [ 'deploy' ] ).code === 0, 'cleanup deploy failed' );
} );

await scenario( 'mode off: deploys are refused', async () => {
	wp( 'wp devbridge disable' );
	appendFileSync( local( `${ THEME }/style.css` ), '\n.e2e-off {}\n' );
	const r = wpdev( project, [ 'deploy' ] );
	expect( r.code === 1 && /write non attiva|write mode/i.test( r.out ), 'deploy not refused', r.out );
	wp( 'wp devbridge enable --mode=write --hours=1 --user=admin' );
} );

rmSync( project, { recursive: true, force: true } );
console.log( failures ? `\n${ failures } scenario(s) failed` : '\nall scenarios passed' );
process.exit( failures );
