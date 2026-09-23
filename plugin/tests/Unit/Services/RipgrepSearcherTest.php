<?php
/**
 * Optional ripgrep engine (M3). Integration tests run only when DEVBRIDGE_TEST_RG points to rg.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Services;

use Lab591\DevBridge\Security\PathGuard;
use Lab591\DevBridge\Security\PathPolicy;
use Lab591\DevBridge\Services\GrepService;
use Lab591\DevBridge\Services\RipgrepSearcher;
use Lab591\DevBridge\Tests\Support\FsFixture;
use PHPUnit\Framework\TestCase;

final class RipgrepSearcherTest extends TestCase {

	private FsFixture $fx;
	private PathGuard $guard;
	private string $tmp;

	protected function setUp(): void {
		$this->fx = FsFixture::wordpress();
		$this->fx->write( 'wp-content/themes/parent/functions.php', "<?php\nadd_action( 'init', 'parent_init' );\nfunction parent_init() {\n\treturn 'Hello World';\n}\n" );
		$this->fx->write( 'wp-content/themes/parent/inc/more.php', "<?php\n// parent_init is referenced here\n" );
		$this->fx->write( 'wp-content/themes/parent/node_modules/lib/index.js', "parent_init();\n" );
		$this->fx->write( 'wp-content/themes/parent/.env', "parent_init=1\n" );
		$this->fx->write( 'wp-content/themes/parent/.hidden.txt', "parent_init hidden but allowed\n" );
		file_put_contents( $this->fx->abs( 'wp-config.php' ), "<?php // parent_init secret\n" );
		$this->guard = new PathGuard( new PathPolicy( $this->fx->abspath, [ '' ], [] ) );
		$this->tmp   = $this->fx->base . DIRECTORY_SEPARATOR . 'tmp';
		mkdir( $this->tmp );
	}

	protected function tearDown(): void {
		$this->fx->cleanup();
	}

	private function binary(): string {
		$rg = (string) getenv( 'DEVBRIDGE_TEST_RG' );
		if ( '' === $rg || ! is_file( $rg ) ) {
			$this->markTestSkipped( 'Set DEVBRIDGE_TEST_RG to a ripgrep binary to run this test.' );
		}
		return $rg;
	}

	private function service( bool $withRg ): GrepService {
		$rg = null;
		if ( $withRg ) {
			$binary = $this->binary();
			$rg     = new RipgrepSearcher( $this->guard, $binary, 1048576, [ 'node_modules', 'vendor', '.git' ], RipgrepSearcher::detectPcre2( $binary, $this->tmp ), $this->tmp );
		}
		return new GrepService( $this->guard, 200, 5000, 1048576, [ 'node_modules', 'vendor', '.git' ], $rg );
	}

	/**
	 * Result without engine-specific fields, sorted, for comparisons.
	 */
	private static function normalized( array $result ): array {
		$matches = $result['matches'];
		usort( $matches, static fn ( $a, $b ) => [ $a['p'], $a['l'] ] <=> [ $b['p'], $b['l'] ] );
		return $matches;
	}

	public function test_file_matches_builds_context(): void {
		$lines = [
			1 => 'a',
			2 => 'b',
			3 => 'hit',
			4 => 'c',
		];
		$this->assertSame(
			[
				[
					'p'      => 'x.php',
					'l'      => 3,
					'text'   => 'hit',
					'before' => [ 'a', 'b' ],
					'after'  => [ 'c' ],
				],
			],
			RipgrepSearcher::fileMatches( 'x.php', $lines, [ 3 ], 2 )
		);
	}

	public function test_binary_validation(): void {
		$this->assertTrue( RipgrepSearcher::isValidBinary( 'rg' ) );
		$this->assertFalse( RipgrepSearcher::isValidBinary( 'C:/Windows/System32/cmd.exe' ) );
		$this->assertFalse( RipgrepSearcher::isValidBinary( '/bin/sh' ) );
		$this->assertFalse( RipgrepSearcher::isValidBinary( 'rg; rm -rf /' ) );
	}

	public function test_rg_matches_php_engine_and_respects_deny_list(): void {
		$args = [
			'pattern' => 'parent_init',
			'path'    => '.',
			'context' => 1,
		];
		$php  = $this->service( false )->grep( $args );
		$rg   = $this->service( true )->grep( $args );

		$this->assertSame( 'rg', $rg['engine'] );
		$this->assertSame( 'php', $php['engine'] );
		$this->assertSame( self::normalized( $php ), self::normalized( $rg ) );

		$files = array_unique( array_column( $rg['matches'], 'p' ) );
		$this->assertNotContains( 'wp-config.php', $files );
		$this->assertNotContains( 'wp-content/themes/parent/.env', $files );
		$this->assertNotContains( 'wp-content/themes/parent/node_modules/lib/index.js', $files );
		$this->assertContains( 'wp-content/themes/parent/.hidden.txt', $files );
	}

	public function test_rg_regex_case_and_glob(): void {
		$service = $this->service( true );
		$regex   = $service->grep(
			[
				'pattern' => 'add_action\(\s*\'init\'',
				'regex'   => true,
				'path'    => 'wp-content',
			]
		);
		$this->assertCount( 1, $regex['matches'] );

		$case = $service->grep(
			[
				'pattern'        => 'hello world',
				'case_sensitive' => true,
				'path'           => 'wp-content',
			]
		);
		$this->assertSame( [], $case['matches'] );

		$glob = $service->grep(
			[
				'pattern' => 'parent_init',
				'glob'    => '*.txt',
				'path'    => 'wp-content',
			]
		);
		$this->assertSame( [ 'wp-content/themes/parent/.hidden.txt' ], array_values( array_unique( array_column( $glob['matches'], 'p' ) ) ) );
	}

	public function test_rg_stops_at_max_results(): void {
		$this->fx->write( 'wp-content/themes/parent/many.txt', str_repeat( "needle\n", 50 ) );
		$out = ( new GrepService( $this->guard, 5, 5000, 1048576, [], new RipgrepSearcher( $this->guard, $this->binary(), 1048576, [], false, $this->tmp ) ) )->grep(
			[
				'pattern'     => 'needle',
				'path'        => 'wp-content',
				'max_results' => 5,
			]
		);
		$this->assertCount( 5, $out['matches'] );
		$this->assertTrue( $out['truncated'] );
		$this->assertSame( 'max_results', $out['reason'] );
		$this->assertSame( [], glob( $this->tmp . '/rg-*' ), 'Temporary output removed' );
	}

	public function test_invalid_binary_falls_back_to_php(): void {
		$fake = $this->fx->base . DIRECTORY_SEPARATOR . 'rg.exe';
		file_put_contents( $fake, 'not a program' );
		$service = new GrepService( $this->guard, 200, 5000, 1048576, [], new RipgrepSearcher( $this->guard, $fake, 1048576, [], false, $this->tmp ) );
		$out     = $service->grep(
			[
				'pattern' => 'parent_init',
				'path'    => 'wp-content/themes/parent/functions.php',
			]
		);
		$this->assertSame( 'php', $out['engine'] );
		$this->assertCount( 2, $out['matches'] );
	}
}
