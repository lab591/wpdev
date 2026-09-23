<?php
/**
 * Deploy validation tests (SPEC 2.7 steps 2–4): everything is checked before any write.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Deploy;

use Lab591\DevBridge\Deploy\DeployValidator;
use Lab591\DevBridge\Deploy\Manifest;
use Lab591\DevBridge\Security\PathGuard;
use Lab591\DevBridge\Security\PathPolicy;
use Lab591\DevBridge\Support\ApiException;
use Lab591\DevBridge\Tests\Support\FsFixture;
use Lab591\DevBridge\Tests\Support\ZipBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeployValidatorTest extends TestCase {

	private FsFixture $fx;
	private string $staging;

	protected function setUp(): void {
		$this->fx      = FsFixture::wordpress();
		$this->staging = $this->fx->base . DIRECTORY_SEPARATOR . 'staging';
		mkdir( $this->staging );
	}

	protected function tearDown(): void {
		$this->fx->cleanup();
	}

	private function guard(): PathGuard {
		return new PathGuard(
			new PathPolicy(
				abspath: $this->fx->abspath,
				readRoots: [ '' ],
				writableRoots: [ 'wp-content/themes/child', 'wp-content/plugins/myplug' ],
				protectedPaths: [ $this->fx->abs( 'wp-content/plugins/lab591-dev-bridge' ) ],
			)
		);
	}

	private function validator( array $limits = [] ): DeployValidator {
		return new DeployValidator(
			$this->guard(),
			$limits + [
				'deploy_zip_bytes'  => 1048576,
				'deploy_files'      => 10,
				'deploy_file_bytes' => 65536,
			],
			$this->staging
		);
	}

	private static function h( string $content ): string {
		return hash( 'xxh128', $content );
	}

	private function currentHash( string $rel ): string {
		return (string) hash_file( 'xxh128', $this->fx->abs( $rel ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $files
	 */
	private static function manifest( array $files, bool $force = false ): string {
		return (string) json_encode(
			[
				'files' => $files,
				'force' => $force,
			]
		);
	}

	private function zip( array $entries ): string {
		return ZipBuilder::build( $this->fx->base . DIRECTORY_SEPARATOR . 'bundle-' . bin2hex( random_bytes( 4 ) ) . '.zip', $entries );
	}

	private function assertRejected( string $code, string $manifest, ?string $zip, array $limits = [] ): ApiException {
		$before = $this->snapshot();
		try {
			$this->validator( $limits )->validate( Manifest::parse( $manifest, $limits['deploy_files'] ?? 10 ), $zip );
		} catch ( ApiException $e ) {
			$this->assertSame( $code, $e->errorCode(), $e->getMessage() );
			$this->assertSame( $before, $this->snapshot(), 'Site files changed during a rejected validation' );
			$this->assertStringNotContainsString( $this->fx->base, $e->getMessage() );
			return $e;
		}
		$this->fail( 'Deploy accepted, expected ' . $code );
	}

	/**
	 * Hash of every file below ABSPATH, to prove validation never writes.
	 *
	 * @return array<string, string>
	 */
	private function snapshot(): array {
		$out = [];
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->fx->abspath, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$out[ $file->getPathname() ] = (string) hash_file( 'xxh128', $file->getPathname() );
		}
		ksort( $out );
		return $out;
	}

	// ------------------------------------------------------------------ manifest

	public static function badManifests(): array {
		return [
			'not json'         => [ '{nope' ],
			'not an object'    => [ '[1,2]' ],
			'files missing'    => [ '{"force":false}' ],
			'files empty'      => [ '{"files":[]}' ],
			'entry not object' => [ '{"files":["a"]}' ],
			'bad action'       => [ '{"files":[{"p":"wp-content/themes/child/a.css","action":"chmod","h":"' . str_repeat( 'a', 32 ) . '"}]}' ],
			'missing p'        => [ '{"files":[{"action":"write","h":"' . str_repeat( 'a', 32 ) . '"}]}' ],
			'p not string'     => [ '{"files":[{"p":5,"action":"write","h":"' . str_repeat( 'a', 32 ) . '"}]}' ],
			'write without h'  => [ '{"files":[{"p":"wp-content/themes/child/a.css","action":"write"}]}' ],
			'bad h'            => [ '{"files":[{"p":"wp-content/themes/child/a.css","action":"write","h":"XYZ"}]}' ],
			'uppercase h'      => [ '{"files":[{"p":"wp-content/themes/child/a.css","action":"write","h":"' . str_repeat( 'A', 32 ) . '"}]}' ],
			'bad base_h'       => [ '{"files":[{"p":"wp-content/themes/child/a.css","action":"write","h":"' . str_repeat( 'a', 32 ) . '","base_h":"zz"}]}' ],
			'force not bool'   => [ '{"files":[{"p":"wp-content/themes/child/a.css","action":"delete"}],"force":"yes"}' ],
			'duplicate path'   => [ '{"files":[{"p":"wp-content/themes/child/a.css","action":"delete"},{"p":"wp-content/themes/child/a.css","action":"delete"}]}' ],
			'too many files'   => [ '{"files":[' . implode( ',', array_fill( 0, 11, '{"p":"wp-content/themes/child/a.css","action":"delete"}' ) ) . ']}' ],
		];
	}

	#[DataProvider( 'badManifests' )]
	public function test_invalid_manifest( string $json ): void {
		try {
			Manifest::parse( $json, 10 );
			$this->fail( 'Manifest accepted' );
		} catch ( ApiException $e ) {
			$this->assertContains( $e->errorCode(), [ 'invalid_manifest', 'too_many_files' ] );
		}
	}

	public function test_valid_manifest(): void {
		$m = Manifest::parse(
			self::manifest(
				[
					[
						'p'      => 'wp-content/themes/child/a.css',
						'action' => 'write',
						'h'      => self::h( 'x' ),
						'base_h' => null,
					],
				],
				true
			),
			10
		);
		$this->assertTrue( $m->force );
		$this->assertCount( 1, $m->entries );
		$this->assertSame( 'write', $m->entries[0]->action );
		$this->assertNull( $m->entries[0]->baseH );
	}

	// ------------------------------------------------------------------ happy path

	public function test_valid_deploy_produces_a_plan_and_stages_content(): void {
		$css      = "body{color:red}\n";
		$php      = "<?php\n// new\n";
		$manifest = self::manifest(
			[
				[
					'p'      => 'wp-content/themes/child/style.css',
					'action' => 'write',
					'h'      => self::h( $css ),
					'base_h' => $this->currentHash( 'wp-content/themes/child/style.css' ),
				],
				[
					'p'      => 'wp-content/themes/child/inc/new dir/nuovo è.php',
					'action' => 'write',
					'h'      => self::h( $php ),
				],
				[
					'p'      => 'wp-content/themes/child/inc/helpers.php',
					'action' => 'delete',
					'base_h' => $this->currentHash( 'wp-content/themes/child/inc/helpers.php' ),
				],
			]
		);
		$zip      = $this->zip(
			[
				'wp-content/themes/child/style.css' => $css,
				'wp-content/themes/child/inc/new dir/nuovo è.php' => $php,
			]
		);
		$before   = $this->snapshot();
		$plan     = $this->validator()->validate( Manifest::parse( $manifest, 10 ), $zip );
		$this->assertSame( $before, $this->snapshot() );

		$this->assertCount( 3, $plan->ops );
		[ $a, $b, $c ] = $plan->ops;
		$this->assertSame( 'write', $a->action );
		$this->assertTrue( $a->existed );
		$this->assertSame( $css, file_get_contents( (string) $a->staged ) );
		$this->assertFalse( $b->existed );
		$this->assertSame( 'wp-content/themes/child/inc/new dir/nuovo è.php', $b->target->relative );
		$this->assertSame( 'delete', $c->action );
		$this->assertNull( $c->staged );
		$this->assertStringStartsWith( realpath( $this->staging ), (string) $a->staged );
	}

	public function test_delete_only_deploy_needs_no_bundle(): void {
		$manifest = self::manifest(
			[
				[
					'p'      => 'wp-content/themes/child/functions.php',
					'action' => 'delete',
					'base_h' => $this->currentHash( 'wp-content/themes/child/functions.php' ),
				],
			]
		);
		$plan     = $this->validator()->validate( Manifest::parse( $manifest, 10 ), null );
		$this->assertCount( 1, $plan->ops );
	}

	// ------------------------------------------------------------------ paths

	public static function forbiddenTargets(): array {
		return [
			'outside writable'  => [ 'wp-content/themes/parent/style.css', 'path_denied' ],
			'core'              => [ 'wp-includes/x.php', 'path_denied' ],
			'wp-config'         => [ 'wp-config.php', 'path_denied' ],
			'dev bridge itself' => [ 'wp-content/plugins/lab591-dev-bridge/x.php', 'path_denied' ],
			'htaccess'          => [ 'wp-content/themes/child/.htaccess', 'extension_denied' ],
			'user ini'          => [ 'wp-content/themes/child/.user.ini', 'extension_denied' ],
			'phtml'             => [ 'wp-content/themes/child/x.phtml', 'extension_denied' ],
			'double ext'        => [ 'wp-content/themes/child/x.php.jpg', 'extension_denied' ],
			'traversal'         => [ 'wp-content/themes/child/../parent/x.css', 'path_invalid' ],
			'absolute'          => [ '/etc/x.css', 'path_invalid' ],
			'env'               => [ 'wp-content/themes/child/.env', 'path_denied' ],
		];
	}

	#[DataProvider( 'forbiddenTargets' )]
	public function test_forbidden_target_rejects_whole_deploy( string $path, string $code ): void {
		$ok       = 'wp-content/themes/child/ok.css';
		$manifest = self::manifest(
			[
				[
					'p'      => $ok,
					'action' => 'write',
					'h'      => self::h( 'ok' ),
				],
				[
					'p'      => $path,
					'action' => 'write',
					'h'      => self::h( 'bad' ),
				],
			]
		);
		$e        = $this->assertRejected(
			$code,
			$manifest,
			$this->zip(
				[
					$ok   => 'ok',
					$path => 'bad',
				]
			)
		);
		$this->assertFileDoesNotExist( $this->fx->abs( $ok ) );
		$this->assertArrayHasKey( 'path', $e->extra() );
	}

	public function test_delete_outside_writable_roots_is_denied(): void {
		$this->assertRejected(
			'path_denied',
			self::manifest(
				[
					[
						'p'      => 'index.php',
						'action' => 'delete',
						'base_h' => $this->currentHash( 'index.php' ),
					],
				]
			),
			null
		);
	}

	// ------------------------------------------------------------------ bundle

	public function test_zip_entry_not_in_manifest(): void {
		$manifest = self::manifest(
			[
				[
					'p'      => 'wp-content/themes/child/a.css',
					'action' => 'write',
					'h'      => self::h( 'a' ),
				],
			]
		);
		$this->assertRejected(
			'invalid_bundle',
			$manifest,
			$this->zip(
				[
					'wp-content/themes/child/a.css' => 'a',
					'wp-content/themes/child/b.css' => 'b',
				]
			)
		);
	}

	public function test_manifest_write_missing_from_zip(): void {
		$manifest = self::manifest(
			[
				[
					'p'      => 'wp-content/themes/child/a.css',
					'action' => 'write',
					'h'      => self::h( 'a' ),
				],
				[
					'p'      => 'wp-content/themes/child/b.css',
					'action' => 'write',
					'h'      => self::h( 'b' ),
				],
			]
		);
		$this->assertRejected( 'invalid_bundle', $manifest, $this->zip( [ 'wp-content/themes/child/a.css' => 'a' ] ) );
		$this->assertRejected( 'invalid_bundle', $manifest, null );
	}

	public function test_deleted_path_present_in_zip(): void {
		$manifest = self::manifest(
			[
				[
					'p'      => 'wp-content/themes/child/style.css',
					'action' => 'delete',
					'base_h' => $this->currentHash( 'wp-content/themes/child/style.css' ),
				],
			]
		);
		$this->assertRejected( 'invalid_bundle', $manifest, $this->zip( [ 'wp-content/themes/child/style.css' => 'x' ] ) );
	}

	public function test_hash_mismatch(): void {
		$manifest = self::manifest(
			[
				[
					'p'      => 'wp-content/themes/child/a.css',
					'action' => 'write',
					'h'      => self::h( 'expected' ),
				],
			]
		);
		$this->assertRejected( 'invalid_bundle', $manifest, $this->zip( [ 'wp-content/themes/child/a.css' => 'tampered' ] ) );
	}

	public static function evilEntryNames(): array {
		return [
			'traversal'     => [ '../../evil.php' ],
			'nested dotdot' => [ 'wp-content/themes/child/../../../evil.php' ],
			'absolute unix' => [ '/tmp/evil.php' ],
			'absolute win'  => [ 'C:/evil.php' ],
			'backslash'     => [ 'wp-content\\themes\\child\\a.css' ],
			'directory'     => [ 'wp-content/themes/child/dir/' ],
		];
	}

	#[DataProvider( 'evilEntryNames' )]
	public function test_evil_zip_entry_rejects_whole_deploy( string $name ): void {
		$manifest = self::manifest(
			[
				[
					'p'      => 'wp-content/themes/child/a.css',
					'action' => 'write',
					'h'      => self::h( 'a' ),
				],
			]
		);
		$this->assertRejected(
			'invalid_bundle',
			$manifest,
			$this->zip(
				[
					'wp-content/themes/child/a.css' => 'a',
					$name                           => 'evil',
				]
			)
		);
	}

	public function test_symlink_entry_rejects_whole_deploy(): void {
		$manifest = self::manifest(
			[
				[
					'p'      => 'wp-content/themes/child/a.css',
					'action' => 'write',
					'h'      => self::h( '../../wp-config.php' ),
				],
			]
		);
		$zip      = ZipBuilder::build(
			$this->fx->base . DIRECTORY_SEPARATOR . 'link.zip',
			[ 'wp-content/themes/child/a.css' => '../../wp-config.php' ],
			[ 'wp-content/themes/child/a.css' ]
		);
		$this->assertRejected( 'invalid_bundle', $manifest, $zip );
	}

	public function test_duplicate_zip_entries(): void {
		$manifest = self::manifest(
			[
				[
					'p'      => 'wp-content/themes/child/a.css',
					'action' => 'write',
					'h'      => self::h( 'a' ),
				],
			]
		);
		$zip      = ZipBuilder::buildRaw(
			$this->fx->base . DIRECTORY_SEPARATOR . 'dup.zip',
			[
				[ 'wp-content/themes/child/a.css', 'a' ],
				[ 'wp-content/themes/child/a.css', 'evil' ],
			]
		);
		$this->assertRejected( 'invalid_bundle', $manifest, $zip );
	}

	public function test_corrupt_zip(): void {
		$bad = $this->fx->base . DIRECTORY_SEPARATOR . 'bad.zip';
		file_put_contents( $bad, 'this is not a zip' );
		$manifest = self::manifest(
			[
				[
					'p'      => 'wp-content/themes/child/a.css',
					'action' => 'write',
					'h'      => self::h( 'a' ),
				],
			]
		);
		$this->assertRejected( 'invalid_bundle', $manifest, $bad );
	}

	// ------------------------------------------------------------------ limits

	public function test_zip_size_limit(): void {
		$manifest = self::manifest(
			[
				[
					'p'      => 'wp-content/themes/child/a.txt',
					'action' => 'write',
					'h'      => self::h( random_bytes( 10 ) ),
				],
			]
		);
		$this->assertRejected( 'too_large', $manifest, $this->zip( [ 'wp-content/themes/child/a.txt' => random_bytes( 5000 ) ] ), [ 'deploy_zip_bytes' => 1000 ] );
	}

	public function test_file_size_limit_uses_real_extracted_size(): void {
		$big      = str_repeat( 'a', 70000 );
		$manifest = self::manifest(
			[
				[
					'p'      => 'wp-content/themes/child/big.txt',
					'action' => 'write',
					'h'      => self::h( $big ),
				],
			]
		);
		$this->assertRejected( 'too_large', $manifest, $this->zip( [ 'wp-content/themes/child/big.txt' => $big ] ) );
	}

	public function test_file_count_limit(): void {
		$files   = [];
		$entries = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$files[]                                      = [
				'p'      => "wp-content/themes/child/f$i.css",
				'action' => 'write',
				'h'      => self::h( "$i" ),
			];
			$entries[ "wp-content/themes/child/f$i.css" ] = "$i";
		}
		$this->assertRejected( 'too_many_files', self::manifest( $files ), $this->zip( $entries ), [ 'deploy_files' => 2 ] );
	}

	// ------------------------------------------------------------------ conflicts

	public function test_conflict_when_server_file_changed(): void {
		$manifest = self::manifest(
			[
				[
					'p'      => 'wp-content/themes/child/style.css',
					'action' => 'write',
					'h'      => self::h( 'new' ),
					'base_h' => self::h( 'stale base' ),
				],
			]
		);
		$e        = $this->assertRejected( 'conflict', $manifest, $this->zip( [ 'wp-content/themes/child/style.css' => 'new' ] ) );
		$this->assertSame( 409, $e->status() );
		$this->assertSame(
			[
				[
					'p'      => 'wp-content/themes/child/style.css',
					'reason' => 'modified',
				],
			],
			$e->extra()['conflicts']
		);
	}

	public function test_conflict_when_new_file_already_exists_on_server(): void {
		$manifest = self::manifest(
			[
				[
					'p'      => 'wp-content/themes/child/style.css',
					'action' => 'write',
					'h'      => self::h( 'new' ),
				],
			]
		);
		$e        = $this->assertRejected( 'conflict', $manifest, $this->zip( [ 'wp-content/themes/child/style.css' => 'new' ] ) );
		$this->assertSame( 'exists', $e->extra()['conflicts'][0]['reason'] );
	}

	public function test_conflict_when_file_deleted_on_server(): void {
		$manifest = self::manifest(
			[
				[
					'p'      => 'wp-content/themes/child/gone.css',
					'action' => 'write',
					'h'      => self::h( 'x' ),
					'base_h' => self::h( 'old' ),
				],
				[
					'p'      => 'wp-content/themes/child/gone2.css',
					'action' => 'delete',
					'base_h' => self::h( 'old' ),
				],
			]
		);
		$e        = $this->assertRejected( 'conflict', $manifest, $this->zip( [ 'wp-content/themes/child/gone.css' => 'x' ] ) );
		$this->assertSame( [ 'deleted', 'deleted' ], array_column( $e->extra()['conflicts'], 'reason' ) );
	}

	public function test_force_overrides_conflicts(): void {
		$manifest = self::manifest(
			[
				[
					'p'      => 'wp-content/themes/child/style.css',
					'action' => 'write',
					'h'      => self::h( 'new' ),
					'base_h' => self::h( 'stale' ),
				],
				[
					'p'      => 'wp-content/themes/child/gone.css',
					'action' => 'delete',
					'base_h' => self::h( 'old' ),
				],
			],
			true
		);
		$plan     = $this->validator()->validate( Manifest::parse( $manifest, 10 ), $this->zip( [ 'wp-content/themes/child/style.css' => 'new' ] ) );
		$this->assertCount( 1, $plan->ops, 'Deleting a file that is already gone is a no-op' );
	}

	public function test_identical_content_is_not_a_conflict(): void {
		$content  = (string) file_get_contents( $this->fx->abs( 'wp-content/themes/child/style.css' ) );
		$manifest = self::manifest(
			[
				[
					'p'      => 'wp-content/themes/child/style.css',
					'action' => 'write',
					'h'      => self::h( $content ),
				],
			]
		);
		$plan     = $this->validator()->validate( Manifest::parse( $manifest, 10 ), $this->zip( [ 'wp-content/themes/child/style.css' => $content ] ) );
		$this->assertCount( 0, $plan->ops, 'Identical content is neither a conflict nor a write' );
	}
}
