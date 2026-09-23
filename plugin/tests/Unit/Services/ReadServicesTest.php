<?php
/**
 * Read-side services: list, read, grep, manifest, archive, log.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Services;

use Lab591\DevBridge\Security\PathException;
use Lab591\DevBridge\Security\PathGuard;
use Lab591\DevBridge\Security\PathPolicy;
use Lab591\DevBridge\Services\ArchiveService;
use Lab591\DevBridge\Services\GrepService;
use Lab591\DevBridge\Services\HashCache;
use Lab591\DevBridge\Services\ListService;
use Lab591\DevBridge\Services\LogService;
use Lab591\DevBridge\Services\ManifestService;
use Lab591\DevBridge\Services\ReadService;
use Lab591\DevBridge\Support\ApiException;
use Lab591\DevBridge\Tests\Support\FsFixture;
use PHPUnit\Framework\TestCase;

final class ReadServicesTest extends TestCase {

	private FsFixture $fx;
	private PathGuard $guard;

	protected function setUp(): void {
		$this->fx = FsFixture::wordpress();
		$this->fx->write( 'wp-content/themes/parent/functions.php', "<?php\nadd_action( 'init', 'parent_init' );\nfunction parent_init() {\n\treturn 'Hello World';\n}\n" );
		$this->fx->write( 'wp-content/themes/parent/lines.txt', implode( "\n", array_map( static fn ( $i ) => "line $i", range( 1, 10 ) ) ) . "\n" );
		$this->fx->write( 'wp-content/themes/parent/crlf.txt', "a\r\nb\r\nc" );
		$this->fx->write( 'wp-content/themes/parent/image.png', "\x89PNG\r\n\x1a\n\0\0\0" );
		$this->fx->write( 'wp-content/themes/parent/node_modules/lib/index.js', "parent_init();\n" );
		$this->guard = new PathGuard(
			new PathPolicy(
				abspath: $this->fx->abspath,
				readRoots: [ '' ],
				writableRoots: [ 'wp-content/themes/child' ],
				protectedPaths: [ $this->fx->abs( 'wp-content/plugins/lab591-dev-bridge' ) ],
			)
		);
	}

	protected function tearDown(): void {
		$this->fx->cleanup();
	}

	// ------------------------------------------------------------------ list

	public function test_list_depth_one_hides_denied_entries(): void {
		$out   = ( new ListService( $this->guard ) )->list( '.' );
		$paths = array_column( $out['entries'], 'p' );
		$this->assertContains( 'index.php', $paths );
		$this->assertContains( 'wp-content', $paths );
		$this->assertNotContains( 'wp-config.php', $paths );
		$this->assertNotContains( '.env', $paths );
		$this->assertNotContains( '.git', $paths );
		$this->assertNotContains( 'dump.sql', $paths );
		$this->assertFalse( $out['truncated'] );
		$this->assertSame( '', $out['path'] );
	}

	public function test_list_recursive_and_truncated(): void {
		$out   = ( new ListService( $this->guard ) )->list( 'wp-content', 3 );
		$paths = array_column( $out['entries'], 'p' );
		$this->assertContains( 'wp-content/themes/child/style.css', $paths );
		$this->assertNotContains( 'wp-content/uploads', $paths );
		$this->assertNotContains( 'wp-content/devbridge-abc123', $paths );
		$this->assertNotContains( 'wp-content/plugins/lab591-dev-bridge', $paths );
		$this->assertNotContains( 'wp-content/debug.log', $paths );
		$this->assertNotContains( 'wp-content/themes/child/inc/helpers.php', $paths, 'depth 3 stops at wp-content/themes/child/*' );

		$entry = $out['entries'][ array_search( 'wp-content/themes/child/style.css', $paths, true ) ];
		$this->assertSame( 'f', $entry['t'] );
		$this->assertGreaterThan( 0, $entry['s'] );
		$this->assertGreaterThan( 0, $entry['m'] );

		$small = ( new ListService( $this->guard ) )->list( 'wp-content', 3, 2 );
		$this->assertCount( 2, $small['entries'] );
		$this->assertTrue( $small['truncated'] );
	}

	public function test_list_rejects_file(): void {
		$this->expectException( PathException::class );
		( new ListService( $this->guard ) )->list( 'index.php' );
	}

	// ------------------------------------------------------------------ read

	public function test_read_whole_file(): void {
		$out = ( new ReadService( $this->guard, 524288 ) )->read( 'wp-content/themes/parent/lines.txt' );
		$this->assertSame( 'ok', $out['status'] );
		$this->assertSame( 10, $out['total_lines'] );
		$this->assertSame( 1, $out['from'] );
		$this->assertSame( 10, $out['to'] );
		$this->assertFalse( $out['truncated'] );
		$this->assertSame( hash( 'xxh128', (string) file_get_contents( $this->fx->abs( 'wp-content/themes/parent/lines.txt' ) ) ), $out['h'] );
	}

	public function test_read_range_preserves_line_endings(): void {
		$svc = new ReadService( $this->guard, 524288 );
		$out = $svc->read( 'wp-content/themes/parent/lines.txt', 3, 4 );
		$this->assertSame( "line 3\nline 4\n", $out['content'] );
		$this->assertSame( 3, $out['from'] );
		$this->assertSame( 4, $out['to'] );
		$this->assertFalse( $out['truncated'] );

		$crlf = $svc->read( 'wp-content/themes/parent/crlf.txt', 2 );
		$this->assertSame( "b\r\nc", $crlf['content'] );
		$this->assertSame( 3, $crlf['total_lines'] );
	}

	public function test_read_over_limit_truncates(): void {
		$out = ( new ReadService( $this->guard, 21 ) )->read( 'wp-content/themes/parent/lines.txt' );
		$this->assertSame( "line 1\nline 2\nline 3\n", $out['content'] );
		$this->assertSame( 3, $out['to'] );
		$this->assertTrue( $out['truncated'] );
		$this->assertSame( 10, $out['total_lines'] );
	}

	public function test_read_single_huge_line_returns_head(): void {
		$this->fx->write( 'wp-content/themes/parent/min.js', str_repeat( 'x', 100 ) );
		$out = ( new ReadService( $this->guard, 10 ) )->read( 'wp-content/themes/parent/min.js' );
		$this->assertSame( str_repeat( 'x', 10 ), $out['content'] );
		$this->assertTrue( $out['truncated'] );
	}

	public function test_read_rejects_binary_and_bad_ranges(): void {
		$svc = new ReadService( $this->guard, 524288 );
		foreach (
			[
				[ 'wp-content/themes/parent/image.png', null, null, 'binary_file' ],
				[ 'wp-content/themes/parent/lines.txt', 0, null, 'invalid_param' ],
				[ 'wp-content/themes/parent/lines.txt', 5, 4, 'invalid_param' ],
				[ 'wp-content/themes/parent/lines.txt', 11, null, 'invalid_param' ],
				[ 'wp-content/themes', null, null, 'not_a_file' ],
				[ 'wp-config.php', null, null, 'path_denied' ],
			] as [ $path, $from, $to, $code ]
		) {
			try {
				$svc->read( $path, $from, $to );
				$this->fail( "Accepted $path" );
			} catch ( ApiException $e ) {
				$this->assertSame( $code, $e->errorCode(), $path );
			}
		}
	}

	public function test_read_with_known_metadata(): void {
		$rel  = 'wp-content/themes/parent/lines.txt';
		$abs  = $this->fx->abs( $rel );
		$svc  = new ReadService( $this->guard, 524288 );
		$full = $svc->read( $rel );

		$same = $svc->read(
			$rel,
			null,
			null,
			[
				's' => $full['s'],
				'm' => $full['m'],
				'h' => $full['h'],
			]
		);
		$this->assertSame( [ 'status' => 'unchanged' ], $same );

		touch( $abs, $full['m'] + 100 );
		clearstatcache();
		$touched = $svc->read(
			$rel,
			null,
			null,
			[
				's' => $full['s'],
				'm' => $full['m'],
				'h' => $full['h'],
			]
		);
		$this->assertSame(
			[
				'status' => 'unchanged',
				's'      => $full['s'],
				'm'      => $full['m'] + 100,
			],
			$touched
		);

		file_put_contents( $abs, "changed\n" );
		touch( $abs, $full['m'] + 200 );
		clearstatcache();
		$changed = $svc->read(
			$rel,
			1,
			1,
			[
				's' => $full['s'],
				'm' => $full['m'] + 100,
				'h' => $full['h'],
			]
		);
		$this->assertSame( 'ok', $changed['status'] );
		$this->assertSame( "changed\n", $changed['content'] );
	}

	public function test_known_never_bypasses_the_guard(): void {
		$this->expectException( PathException::class );
		( new ReadService( $this->guard, 524288 ) )->read(
			'wp-config.php',
			null,
			null,
			[
				's' => 0,
				'm' => 0,
				'h' => str_repeat( '0', 32 ),
			]
		);
	}

	public function test_hash_cache_persists_and_skips_fresh_files(): void {
		$file  = $this->fx->base . DIRECTORY_SEPARATOR . 'hash-cache.json';
		$rel   = 'wp-content/themes/parent/lines.txt';
		$abs   = $this->fx->abs( $rel );
		$mtime = time() - 100;
		touch( $abs, $mtime );
		clearstatcache();
		$size = (int) filesize( $abs );

		$cache = new HashCache( $file );
		$this->assertSame( hash_file( 'xxh128', $abs ), $cache->hash( $abs, $rel, $size, $mtime ) );
		$cache->save();
		$this->assertFileExists( $file );

		// A stale cached hash is returned as long as path|size|mtime match: proves the cache is used.
		file_put_contents( $file, (string) json_encode( [ "$rel|$size|$mtime" => str_repeat( 'a', 32 ) ] ) );
		$this->assertSame( str_repeat( 'a', 32 ), ( new HashCache( $file ) )->hash( $abs, $rel, $size, $mtime ) );

		// Files modified just now are never cached.
		$fresh = new HashCache( $file . '.2' );
		$fresh->hash( $abs, $rel, $size, time() );
		$fresh->save();
		$this->assertFileDoesNotExist( $file . '.2' );

		$manifest = ( new ManifestService( $this->guard, 1000, new HashCache( $file ) ) )->manifest( 'wp-content/themes/parent' );
		$this->assertSame( str_repeat( 'a', 32 ), array_column( $manifest['files'], 'h', 'p' )[ $rel ] );
	}

	// ------------------------------------------------------------------ grep

	private function grep( array $args, int $max = 200 ): array {
		return ( new GrepService( $this->guard, $max, 5000, 1048576, [ 'node_modules', 'vendor', '.git' ] ) )->grep( $args + [ 'path' => 'wp-content/themes' ] );
	}

	public function test_grep_literal_with_context(): void {
		$out = $this->grep(
			[
				'pattern' => 'parent_init',
				'context' => 1,
			]
		);
		$this->assertCount( 2, $out['matches'] );
		$first = $out['matches'][0];
		$this->assertSame( 'wp-content/themes/parent/functions.php', $first['p'] );
		$this->assertSame( 2, $first['l'] );
		$this->assertSame( [ '<?php' ], $first['before'] );
		$this->assertSame( [ 'function parent_init() {' ], $first['after'] );
		$this->assertFalse( $out['truncated'] );
		$this->assertGreaterThan( 0, $out['files_scanned'] );
	}

	public function test_grep_skips_node_modules_binary_and_denied(): void {
		$this->fx->write( 'wp-content/themes/parent/.env', 'parent_init' );
		$out   = $this->grep( [ 'pattern' => 'parent_init' ] );
		$files = array_unique( array_column( $out['matches'], 'p' ) );
		$this->assertSame( [ 'wp-content/themes/parent/functions.php' ], array_values( $files ) );

		$root = $this->grep(
			[
				'pattern' => 'secrets',
				'path'    => '.',
			]
		);
		$this->assertSame( [], $root['matches'], 'wp-config.php must never be searched' );
	}

	public function test_grep_case_and_regex(): void {
		$this->assertCount( 1, $this->grep( [ 'pattern' => 'hello world' ] )['matches'] );
		$this->assertCount(
			0,
			$this->grep(
				[
					'pattern'        => 'hello world',
					'case_sensitive' => true,
				]
			)['matches']
		);
		$out = $this->grep(
			[
				'pattern' => 'add_action\(\s*\'init\'',
				'regex'   => true,
			]
		);
		$this->assertCount( 1, $out['matches'] );
		$slash = $this->grep(
			[
				'pattern' => 'a/b',
				'regex'   => true,
			]
		);
		$this->assertSame( [], $slash['matches'] );
	}

	public function test_grep_invalid_regex(): void {
		try {
			$this->grep(
				[
					'pattern' => '(unclosed',
					'regex'   => true,
				]
			);
			$this->fail( 'Invalid regex accepted' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'invalid_regex', $e->errorCode() );
			$this->assertSame( 422, $e->status() );
		}
	}

	public function test_grep_glob_and_max_results(): void {
		$out = $this->grep(
			[
				'pattern' => 'line',
				'glob'    => '*.txt',
			],
			3
		);
		$this->assertCount( 3, $out['matches'] );
		$this->assertTrue( $out['truncated'] );
		$this->assertSame( 'max_results', $out['reason'] );

		$none = $this->grep(
			[
				'pattern' => 'line',
				'glob'    => '*.php',
			]
		);
		$this->assertSame( [], $none['matches'] );
	}

	public function test_grep_catastrophic_regex_is_bounded(): void {
		$this->fx->write( 'wp-content/themes/parent/redos.txt', str_repeat( 'a', 5000 ) . '!' );
		$start = microtime( true );
		$out   = $this->grep(
			[
				'pattern' => '(a+)+$',
				'regex'   => true,
				'path'    => 'wp-content/themes/parent/redos.txt',
			]
		);
		$this->assertLessThan( 5, microtime( true ) - $start );
		$this->assertSame( [], $out['matches'] );
	}

	public function test_grep_clips_long_lines(): void {
		$this->fx->write( 'wp-content/themes/parent/long.txt', 'needle' . str_repeat( 'x', 1000 ) );
		$out = $this->grep( [ 'pattern' => 'needle' ] );
		$this->assertSame( 300, strlen( $out['matches'][0]['text'] ) );
	}

	// ------------------------------------------------------------------ manifest

	public function test_manifest_lists_hashes(): void {
		$out   = ( new ManifestService( $this->guard, 1000 ) )->manifest( 'wp-content/themes/child' );
		$paths = array_column( $out['files'], 'p' );
		$this->assertContains( 'wp-content/themes/child/style.css', $paths );
		$this->assertContains( 'wp-content/themes/child/inc/helpers.php', $paths );
		$file = $out['files'][ array_search( 'wp-content/themes/child/style.css', $paths, true ) ];
		$this->assertSame( hash_file( 'xxh128', $this->fx->abs( 'wp-content/themes/child/style.css' ) ), $file['h'] );
	}

	public function test_manifest_exclude_and_limit(): void {
		$out = ( new ManifestService( $this->guard, 1000 ) )->manifest( 'wp-content/themes/child', [ '**/inc/**' ] );
		$this->assertNotContains( 'wp-content/themes/child/inc/helpers.php', array_column( $out['files'], 'p' ) );

		$this->expectException( ApiException::class );
		( new ManifestService( $this->guard, 1 ) )->manifest( 'wp-content/themes/child' );
	}

	public function test_manifest_never_includes_denied_files(): void {
		$this->fx->write( 'wp-content/themes/child/.env', 'x' );
		$this->fx->write( 'wp-content/themes/child/db.sql', 'x' );
		$paths = array_column( ( new ManifestService( $this->guard, 1000 ) )->manifest( 'wp-content/themes/child' )['files'], 'p' );
		$this->assertNotContains( 'wp-content/themes/child/.env', $paths );
		$this->assertNotContains( 'wp-content/themes/child/db.sql', $paths );
	}

	// ------------------------------------------------------------------ archive

	private function archive(): ArchiveService {
		return new ArchiveService( $this->guard, 500, 1000, 10485760, $this->fx->base );
	}

	public function test_archive_paths(): void {
		$out = $this->archive()->build( [ 'wp-content/themes/child/style.css', 'wp-content/themes/child/functions.php' ], null );
		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $out['file'] ) );
		$this->assertSame( 2, $zip->numFiles );
		$this->assertSame( "/* Theme Name: Child */\n", $zip->getFromName( 'wp-content/themes/child/style.css' ) );
		$zip->close();
		unlink( $out['file'] );
	}

	public function test_archive_root_skips_denied(): void {
		$this->fx->write( 'wp-content/themes/child/.env', 'x' );
		$out   = $this->archive()->build( null, 'wp-content/themes/child' );
		$zip   = new \ZipArchive();
		$names = [];
		$zip->open( $out['file'] );
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$names[] = $zip->getNameIndex( $i );
		}
		$zip->close();
		unlink( $out['file'] );
		$this->assertContains( 'wp-content/themes/child/inc/helpers.php', $names );
		$this->assertNotContains( 'wp-content/themes/child/.env', $names );
	}

	public function test_archive_rejects_denied_path_and_bad_input(): void {
		foreach (
			[
				[ [ 'wp-config.php' ], null, 'path_denied' ],
				[ [ 'wp-content/themes/child' ], null, 'not_a_file' ],
				[ [], null, 'invalid_param' ],
				[ null, null, 'invalid_param' ],
				[ [ 'index.php' ], 'wp-content', 'invalid_param' ],
			] as [ $paths, $root, $code ]
		) {
			try {
				$this->archive()->build( $paths, $root );
				$this->fail( 'Accepted archive request' );
			} catch ( ApiException $e ) {
				$this->assertSame( $code, $e->errorCode() );
			}
		}
		try {
			( new ArchiveService( $this->guard, 1, 1000, 10485760, $this->fx->base ) )->build( [ 'index.php', 'wp-content/themes/child/style.css' ], null );
			$this->fail( 'Accepted too many paths' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'too_many_files', $e->errorCode() );
		}
	}

	public function test_empty_archive_is_valid_zip(): void {
		$this->fx->write( 'wp-content/themes/child/empty/.env', 'x' );
		$out = $this->archive()->build( null, 'wp-content/themes/child/empty' );
		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $out['file'] ) );
		$this->assertSame( 0, $zip->numFiles );
		$zip->close();
		unlink( $out['file'] );
	}

	// ------------------------------------------------------------------ log

	public function test_log_locate(): void {
		$this->assertNull( LogService::locate( false, '/x' ) );
		$this->assertNull( LogService::locate( null, '/x' ) );
		$this->assertSame( '/x' . DIRECTORY_SEPARATOR . 'debug.log', LogService::locate( true, '/x' ) );
		$this->assertSame( '/var/log/wp.log', LogService::locate( '/var/log/wp.log', '/x' ) );
	}

	public function test_log_tail_and_since(): void {
		$lines = [];
		for ( $i = 0; $i < 30; $i++ ) {
			$lines[] = sprintf( '[%s UTC] PHP Warning: w%d', gmdate( 'd-M-Y H:i:s', 1_700_000_000 + $i * 60 ), $i );
			$lines[] = '  #0 stack frame ' . $i;
		}
		$log = $this->fx->write( 'wp-content/debug.log', implode( "\n", $lines ) . "\n" );
		$svc = new LogService( $log );

		$tail = $svc->tail( 4 );
		$this->assertSame( array_slice( $lines, -4 ), $tail['lines'] );
		$this->assertTrue( $tail['truncated'] );

		$since = $svc->tail( 1000, 1_700_000_000 + 28 * 60 );
		$this->assertSame( array_slice( $lines, -4 ), $since['lines'] );
		$this->assertFalse( $since['truncated'] );
	}

	public function test_log_lines_hide_the_absolute_site_root(): void {
		$line = '[14-Nov-2023 22:13:20 UTC] PHP Fatal error: x in C:\\site\\wp-content\\themes\\a\\functions.php:8';
		$this->assertSame( '[14-Nov-2023 22:13:20 UTC] PHP Fatal error: x in wp-content\\themes\\a\\functions.php:8', LogService::relativize( $line, 'C:/site/' ) );
		$this->assertSame( 'in wp-content/x.php', LogService::relativize( 'in /var/www/html/wp-content/x.php', '/var/www/html/' ) );
		$this->assertSame( 'unchanged', LogService::relativize( 'unchanged', null ) );
	}

	public function test_log_missing(): void {
		$this->expectException( ApiException::class );
		( new LogService( null ) )->tail();
	}

	public function test_log_timestamp_parsing(): void {
		$this->assertSame( 1_700_000_000, LogService::timestamp( '[14-Nov-2023 22:13:20 UTC] PHP Fatal error: x' ) );
		$this->assertSame( 1_700_000_000, LogService::timestamp( '[14-Nov-2023 23:13:20 Europe/Rome] x' ) );
		$this->assertNull( LogService::timestamp( 'Stack trace:' ) );
	}
}
