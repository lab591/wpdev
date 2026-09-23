<?php
/**
 * Deploy execution, automatic rollback and manual rollback (SPEC 2.7, 2.8).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Deploy;

use Lab591\DevBridge\Deploy\Deployer;
use Lab591\DevBridge\Deploy\HealthChecker;
use Lab591\DevBridge\Deploy\Lock;
use Lab591\DevBridge\Deploy\Manifest;
use Lab591\DevBridge\Deploy\ReleaseStore;
use Lab591\DevBridge\Deploy\RollbackService;
use Lab591\DevBridge\Security\PathGuard;
use Lab591\DevBridge\Security\PathPolicy;
use Lab591\DevBridge\Storage\Storage;
use Lab591\DevBridge\Support\ApiException;
use Lab591\DevBridge\Tests\Support\FakeHealth;
use Lab591\DevBridge\Tests\Support\FsFixture;
use Lab591\DevBridge\Tests\Support\ZipBuilder;
use PHPUnit\Framework\TestCase;

final class DeployerTest extends TestCase {

	private FsFixture $fx;
	private Storage $storage;
	private FakeHealth $health;

	protected function setUp(): void {
		$this->fx      = FsFixture::wordpress();
		$this->storage = new Storage( $this->fx->base . DIRECTORY_SEPARATOR . 'storage' );
		$this->health  = new FakeHealth();
	}

	protected function tearDown(): void {
		$this->fx->cleanup();
	}

	private function guard(): PathGuard {
		return new PathGuard(
			new PathPolicy(
				abspath: $this->fx->abspath,
				readRoots: [ '' ],
				writableRoots: [ 'wp-content/themes/child' ],
				protectedPaths: [ $this->storage->dir() ],
			)
		);
	}

	private function deployer( int $retention = 10 ): Deployer {
		return new Deployer(
			$this->guard(),
			$this->storage,
			$this->health,
			[
				'deploy_zip_bytes'  => 1048576,
				'deploy_files'      => 50,
				'deploy_file_bytes' => 65536,
			],
			$retention,
			[
				'ip_allowlist'    => [],
				'trusted_proxies' => [],
				'allow_http'      => true,
			]
		);
	}

	private function read( string $rel ): string {
		return (string) file_get_contents( $this->fx->abs( $rel ) );
	}

	private function h( string $rel ): string {
		return (string) hash_file( 'xxh128', $this->fx->abs( $rel ) );
	}

	/**
	 * Deploys: overwrite style.css, create inc/new/n.php, delete inc/helpers.php.
	 *
	 * @return array<string, mixed>
	 */
	private function standardDeploy(): array {
		$css      = "body{}\n";
		$php      = "<?php\n// new\n";
		$manifest = json_encode(
			[
				'files' => [
					[
						'p'      => 'wp-content/themes/child/style.css',
						'action' => 'write',
						'h'      => hash( 'xxh128', $css ),
						'base_h' => $this->h( 'wp-content/themes/child/style.css' ),
					],
					[
						'p'      => 'wp-content/themes/child/inc/new/n.php',
						'action' => 'write',
						'h'      => hash( 'xxh128', $php ),
					],
					[
						'p'      => 'wp-content/themes/child/inc/helpers.php',
						'action' => 'delete',
						'base_h' => $this->h( 'wp-content/themes/child/inc/helpers.php' ),
					],
				],
			]
		);
		$zip      = ZipBuilder::build(
			$this->fx->base . DIRECTORY_SEPARATOR . 'b.zip',
			[
				'wp-content/themes/child/style.css'     => $css,
				'wp-content/themes/child/inc/new/n.php' => $php,
			]
		);
		return $this->deployer()->deploy( Manifest::parse( (string) $manifest, 50 ), $zip, 1 );
	}

	public function test_successful_deploy_writes_backs_up_and_issues_token(): void {
		$originalCss = $this->read( 'wp-content/themes/child/style.css' );
		$out         = $this->standardDeploy();

		$this->assertSame( 'ok', $out['status'] );
		$this->assertSame( 2, $out['written'] );
		$this->assertSame( 1, $out['deleted'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $out['rescue_token'] );
		$this->assertSame( "body{}\n", $this->read( 'wp-content/themes/child/style.css' ) );
		$this->assertSame( "<?php\n// new\n", $this->read( 'wp-content/themes/child/inc/new/n.php' ) );
		$this->assertFileDoesNotExist( $this->fx->abs( 'wp-content/themes/child/inc/helpers.php' ) );

		$store   = new ReleaseStore( $this->storage->releasesDir() );
		$release = $store->load( $out['release_id'] );
		$this->assertSame( 'ok', $release['status'] );
		$this->assertSame( $originalCss, file_get_contents( $store->backupPath( $out['release_id'], 'wp-content/themes/child/style.css' ) ) );

		$rescue = json_decode( (string) file_get_contents( $this->storage->rescueFile() ), true );
		$this->assertSame( hash( 'sha256', $out['rescue_token'] ), $rescue['token_sha256'] );
		$this->assertSame( $out['release_id'], $rescue['release_id'] );
		$this->assertStringNotContainsString( $out['rescue_token'], (string) file_get_contents( $this->storage->rescueFile() ) );

		$this->assertSame( [], glob( $this->storage->tmpDir() . '/*' ), 'Staging must be cleaned up' );
		$this->assertFileExists( $this->storage->dir() . '/.htaccess' );
	}

	public function test_failed_health_check_rolls_back_automatically(): void {
		$before               = [
			'wp-content/themes/child/style.css'       => $this->read( 'wp-content/themes/child/style.css' ),
			'wp-content/themes/child/inc/helpers.php' => $this->read( 'wp-content/themes/child/inc/helpers.php' ),
		];
		$this->health->result = [
			'status' => HealthChecker::FAIL,
			'checks' => [
				[
					'url'  => 'https://example.test/',
					'code' => 500,
				],
			],
			'errors' => [ '[23-Sep-2026 10:00:00 UTC] PHP Fatal error: boom' ],
		];
		$out                  = $this->standardDeploy();

		$this->assertSame( 'rolled_back', $out['status'] );
		$this->assertArrayNotHasKey( 'rescue_token', $out );
		$this->assertSame( [ '[23-Sep-2026 10:00:00 UTC] PHP Fatal error: boom' ], $out['errors'] );
		foreach ( $before as $rel => $content ) {
			$this->assertSame( $content, $this->read( $rel ) );
		}
		$this->assertFileDoesNotExist( $this->fx->abs( 'wp-content/themes/child/inc/new/n.php' ) );
		$this->assertDirectoryDoesNotExist( $this->fx->abs( 'wp-content/themes/child/inc/new' ), 'Empty folders created by the deploy are removed' );
		$this->assertFileDoesNotExist( $this->storage->rescueFile() );
		$this->assertSame( 'rolled_back', ( new ReleaseStore( $this->storage->releasesDir() ) )->load( $out['release_id'] )['status'] );
	}

	public function test_unknown_health_keeps_changes(): void {
		$this->health->result = [
			'status'  => HealthChecker::UNKNOWN,
			'checks'  => [],
			'errors'  => [],
			'message' => 'loopback unreachable',
		];
		$out                  = $this->standardDeploy();
		$this->assertSame( 'health_unknown', $out['status'] );
		$this->assertSame( 'loopback unreachable', $out['health']['message'] );
		$this->assertArrayHasKey( 'rescue_token', $out );
		$this->assertSame( "body{}\n", $this->read( 'wp-content/themes/child/style.css' ) );
	}

	public function test_lock_contention(): void {
		$this->storage->ensure();
		$held = new Lock( $this->storage->lockFile() );
		$held->acquire();
		try {
			$this->standardDeploy();
			$this->fail( 'Deploy ran while locked' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'deploy_locked', $e->errorCode() );
			$this->assertSame( 409, $e->status() );
		} finally {
			$held->release();
		}
	}

	public function test_manual_rollback_restores_and_reports_hashes(): void {
		$originalCssHash = $this->h( 'wp-content/themes/child/style.css' );
		$helpersHash     = $this->h( 'wp-content/themes/child/inc/helpers.php' );
		$out             = $this->standardDeploy();

		$rb = ( new RollbackService( $this->guard(), $this->storage ) )->rollback( null, false );
		$this->assertSame( [ $out['release_id'] ], $rb['rolled_back'] );
		$files = array_column( $rb['files'], 'h', 'p' );
		$this->assertSame( $originalCssHash, $files['wp-content/themes/child/style.css'] );
		$this->assertSame( $helpersHash, $files['wp-content/themes/child/inc/helpers.php'] );
		$this->assertNull( $files['wp-content/themes/child/inc/new/n.php'] );
		$this->assertSame( $originalCssHash, $this->h( 'wp-content/themes/child/style.css' ) );
		$this->assertFileExists( $this->fx->abs( 'wp-content/themes/child/inc/helpers.php' ) );
		$this->assertFileDoesNotExist( $this->storage->rescueFile(), 'Rescue token is invalidated' );

		try {
			( new RollbackService( $this->guard(), $this->storage ) )->rollback( null, false );
			$this->fail( 'Second rollback found a release' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'no_release', $e->errorCode() );
		}
	}

	public function test_rollback_refuses_when_server_changed_unless_forced(): void {
		$this->standardDeploy();
		file_put_contents( $this->fx->abs( 'wp-content/themes/child/style.css' ), 'edited on server' );
		try {
			( new RollbackService( $this->guard(), $this->storage ) )->rollback( null, false );
			$this->fail( 'Rollback overwrote a server edit' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'conflict', $e->errorCode() );
			$this->assertSame( 'wp-content/themes/child/style.css', $e->extra()['conflicts'][0]['p'] );
		}
		$this->assertSame( 'edited on server', $this->read( 'wp-content/themes/child/style.css' ) );
		( new RollbackService( $this->guard(), $this->storage ) )->rollback( null, true );
		$this->assertStringContainsString( 'Theme Name: Child', $this->read( 'wp-content/themes/child/style.css' ) );
	}

	public function test_rollback_to_older_release_undoes_newer_ones(): void {
		$original = $this->read( 'wp-content/themes/child/functions.php' );
		$ids      = [];
		foreach ( [ 'one', 'two' ] as $version ) {
			$content  = "<?php // $version\n";
			$manifest = json_encode(
				[
					'files' => [
						[
							'p'      => 'wp-content/themes/child/functions.php',
							'action' => 'write',
							'h'      => hash( 'xxh128', $content ),
							'base_h' => $this->h( 'wp-content/themes/child/functions.php' ),
						],
					],
				]
			);
			$zip      = ZipBuilder::build( $this->fx->base . "/$version.zip", [ 'wp-content/themes/child/functions.php' => $content ] );
			$ids[]    = $this->deployer()->deploy( Manifest::parse( (string) $manifest, 50 ), $zip, 1 )['release_id'];
			sleep( 1 ); // Release ids are ordered by time.
		}
		$rb = ( new RollbackService( $this->guard(), $this->storage ) )->rollback( $ids[0], false );
		$this->assertSame( array_reverse( $ids ), $rb['rolled_back'] );
		$this->assertSame( $original, $this->read( 'wp-content/themes/child/functions.php' ) );
	}

	public function test_release_rotation(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$content  = "a$i";
			$manifest = json_encode(
				[
					'files' => [
						[
							'p'      => "wp-content/themes/child/f$i.txt",
							'action' => 'write',
							'h'      => hash( 'xxh128', $content ),
						],
					],
				]
			);
			$zip      = ZipBuilder::build( $this->fx->base . "/r$i.zip", [ "wp-content/themes/child/f$i.txt" => $content ] );
			$this->deployer( 2 )->deploy( Manifest::parse( (string) $manifest, 50 ), $zip, 1 );
			sleep( 1 );
		}
		$this->assertCount( 2, ( new ReleaseStore( $this->storage->releasesDir() ) )->all() );
	}

	public function test_agent_health_paths_reach_the_health_check(): void {
		$manifest = json_encode(
			[
				'files'        => [
					[
						'p'      => 'wp-content/themes/child/h.txt',
						'action' => 'write',
						'h'      => hash( 'xxh128', 'h' ),
					],
				],
				'health_paths' => [ '/shop/', '/contatti/' ],
			]
		);
		$zip      = ZipBuilder::build( $this->fx->base . '/h.zip', [ 'wp-content/themes/child/h.txt' => 'h' ] );
		$this->deployer()->deploy( Manifest::parse( (string) $manifest, 50 ), $zip, 1 );
		$this->assertSame( [ '/shop/', '/contatti/' ], $this->health->lastPaths );
	}

	public function test_validation_error_leaves_no_release(): void {
		$manifest = json_encode(
			[
				'files' => [
					[
						'p'      => 'wp-config.php',
						'action' => 'write',
						'h'      => hash( 'xxh128', 'x' ),
					],
				],
			]
		);
		$zip      = ZipBuilder::build( $this->fx->base . '/bad.zip', [ 'wp-config.php' => 'x' ] );
		try {
			$this->deployer()->deploy( Manifest::parse( (string) $manifest, 50 ), $zip, 1 );
			$this->fail( 'Deploy accepted' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'path_denied', $e->errorCode() );
		}
		$this->assertSame( [], ( new ReleaseStore( $this->storage->releasesDir() ) )->all() );
		$this->assertFileDoesNotExist( $this->storage->rescueFile() );
	}
}
