<?php
/**
 * Preview before publishing (SPEC 2.15): copies, isolation from the live site, publish, discard.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Deploy;

use Lab591\DevBridge\Deploy\Deployer;
use Lab591\DevBridge\Deploy\Manifest;
use Lab591\DevBridge\Deploy\PreviewService;
use Lab591\DevBridge\Security\PathGuard;
use Lab591\DevBridge\Security\PathPolicy;
use Lab591\DevBridge\Storage\Storage;
use Lab591\DevBridge\Support\ApiException;
use Lab591\DevBridge\Tests\Support\FakeHealth;
use Lab591\DevBridge\Tests\Support\FsFixture;
use Lab591\DevBridge\Tests\Support\ZipBuilder;
use PHPUnit\Framework\TestCase;

final class PreviewServiceTest extends TestCase {

	private const LIMITS = [
		'deploy_zip_bytes'  => 1048576,
		'deploy_files'      => 50,
		'deploy_file_bytes' => 65536,
	];

	private FsFixture $fx;
	private Storage $storage;
	/** @var list<string> Tokens received by the health probe. */
	private array $probed = [];

	protected function setUp(): void {
		$this->fx      = FsFixture::wordpress();
		$this->storage = new Storage( $this->fx->base . DIRECTORY_SEPARATOR . 'storage' );
		$this->fx->write( 'wp-content/themes/child/.env', 'SECRET=1' );
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

	private function service(): PreviewService {
		return new PreviewService(
			$this->guard(),
			$this->storage,
			self::LIMITS,
			function ( string $token ): array {
				$this->probed[] = $token;
				return [
					'status' => 'ok',
					'code'   => 200,
				];
			}
		);
	}

	private function h( string $rel ): string {
		return (string) hash_file( 'xxh128', $this->fx->abs( $rel ) );
	}

	/**
	 * @return array{0: Manifest, 1: string}
	 */
	private function changes( string $css = "body{color:red}\n" ): array {
		$manifest = Manifest::parse(
			(string) json_encode(
				[
					'files' => [
						[
							'p'      => 'wp-content/themes/child/style.css',
							'action' => 'write',
							'h'      => hash( 'xxh128', $css ),
							'base_h' => $this->h( 'wp-content/themes/child/style.css' ),
						],
						[
							'p'      => 'wp-content/themes/child/inc/helpers.php',
							'action' => 'delete',
							'base_h' => $this->h( 'wp-content/themes/child/inc/helpers.php' ),
						],
					],
				]
			),
			50
		);
		$zip      = ZipBuilder::build( $this->fx->base . DIRECTORY_SEPARATOR . 'p.zip', [ 'wp-content/themes/child/style.css' => $css ] );
		return [ $manifest, $zip ];
	}

	public function test_preview_changes_only_the_copy(): void {
		$liveCss            = (string) file_get_contents( $this->fx->abs( 'wp-content/themes/child/style.css' ) );
		[ $manifest, $zip ] = $this->changes();
		$out                = $this->service()->create( $manifest, $zip, 1 );

		$this->assertSame( [ 'wp-content/themes/child' ], $out['units'] );
		$this->assertMatchesRegularExpression( '#/\?devbridge_preview=[0-9a-f]{64}$#', $out['link'] );
		$this->assertCount( 1, $this->probed, 'The copy is checked with the preview cookie' );
		// Live untouched.
		$this->assertSame( $liveCss, (string) file_get_contents( $this->fx->abs( 'wp-content/themes/child/style.css' ) ) );
		$this->assertFileExists( $this->fx->abs( 'wp-content/themes/child/inc/helpers.php' ) );
		// Copy = live + changes, without denied files.
		$copy = 'wp-content/themes/child' . PreviewService::SUFFIX;
		$this->assertSame( "body{color:red}\n", (string) file_get_contents( $this->fx->abs( $copy . '/style.css' ) ) );
		$this->assertFileDoesNotExist( $this->fx->abs( $copy . '/inc/helpers.php' ) );
		$this->assertFileExists( $this->fx->abs( $copy . '/functions.php' ) );
		$this->assertFileDoesNotExist( $this->fx->abs( $copy . '/.env' ), 'Denied files are not copied' );
		// Only the token hash is stored.
		$stored = (string) file_get_contents( $this->storage->dir() . '/preview.json' );
		$this->assertStringNotContainsString( $this->probed[0], $stored );
		$this->assertStringContainsString( hash( 'sha256', $this->probed[0] ), $stored );
	}

	public function test_each_preview_starts_from_the_live_version(): void {
		[ $manifest, $zip ] = $this->changes();
		$this->service()->create( $manifest, $zip, 1 );
		// Second preview without the deletion: helpers.php is back in the copy.
		$css    = "body{color:blue}\n";
		$second = Manifest::parse(
			(string) json_encode(
				[
					'files' => [
						[
							'p'      => 'wp-content/themes/child/style.css',
							'action' => 'write',
							'h'      => hash( 'xxh128', $css ),
							'base_h' => $this->h( 'wp-content/themes/child/style.css' ),
						],
					],
				]
			),
			50
		);
		$this->service()->create( $second, ZipBuilder::build( $this->fx->base . DIRECTORY_SEPARATOR . 'p2.zip', [ 'wp-content/themes/child/style.css' => $css ] ), 1 );
		$copy = 'wp-content/themes/child' . PreviewService::SUFFIX;
		$this->assertFileExists( $this->fx->abs( $copy . '/inc/helpers.php' ) );
		$this->assertSame( $css, (string) file_get_contents( $this->fx->abs( $copy . '/style.css' ) ) );
		$this->assertNotSame( $this->probed[0], $this->probed[1], 'A new token for every preview' );
	}

	public function test_publish_deploys_the_preview_and_removes_it(): void {
		[ $manifest, $zip ] = $this->changes();
		$service            = $this->service();
		$service->create( $manifest, $zip, 1 );
		$out = $service->publish( $this->deployer(), 1 );

		$this->assertSame( 'ok', $out['status'] );
		$this->assertSame( "body{color:red}\n", (string) file_get_contents( $this->fx->abs( 'wp-content/themes/child/style.css' ) ) );
		$this->assertFileDoesNotExist( $this->fx->abs( 'wp-content/themes/child/inc/helpers.php' ) );
		$this->assertDirectoryDoesNotExist( $this->fx->abs( 'wp-content/themes/child' . PreviewService::SUFFIX ) );
		$this->assertFalse( $service->status()['active'] );
		$this->assertNotEmpty( $out['release_id'], 'A normal release with backup' );
	}

	public function test_publish_refuses_a_tampered_copy_and_a_changed_live_site(): void {
		[ $manifest, $zip ] = $this->changes();
		$service            = $this->service();
		$service->create( $manifest, $zip, 1 );
		file_put_contents( $this->fx->abs( 'wp-content/themes/child' . PreviewService::SUFFIX . '/style.css' ), 'tampered' );
		try {
			$service->publish( $this->deployer(), 1 );
			$this->fail( 'Expected preview_changed' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'preview_changed', $e->errorCode() );
		}

		$service->create( $manifest, ZipBuilder::build( $this->fx->base . DIRECTORY_SEPARATOR . 'p3.zip', [ 'wp-content/themes/child/style.css' => "body{color:red}\n" ] ), 1 );
		file_put_contents( $this->fx->abs( 'wp-content/themes/child/style.css' ), 'edited on the server meanwhile' );
		try {
			$service->publish( $this->deployer(), 1 );
			$this->fail( 'Expected a conflict' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'conflict', $e->errorCode() );
		}
		$this->assertSame( 'edited on the server meanwhile', (string) file_get_contents( $this->fx->abs( 'wp-content/themes/child/style.css' ) ) );
	}

	public function test_discard_removes_copies_and_record(): void {
		[ $manifest, $zip ] = $this->changes();
		$service            = $this->service();
		$service->create( $manifest, $zip, 1 );
		$service->discard();
		$this->assertDirectoryDoesNotExist( $this->fx->abs( 'wp-content/themes/child' . PreviewService::SUFFIX ) );
		$this->assertFileDoesNotExist( $this->storage->dir() . '/preview.json' );
		$this->assertSame( [ 'active' => false ], $service->status() );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function unsupported(): array {
		return [
			'mu-plugin'         => [ 'wp-content/mu-plugins/tools/x.php' ],
			'file in container' => [ 'wp-content/themes/index.php' ],
			'uploads'           => [ 'wp-content/uploads/a.jpg' ],
			'inside a copy'     => [ 'wp-content/themes/child--devbridge-preview/x.php' ],
		];
	}

	/**
	 * @dataProvider unsupported
	 */
	public function test_unit_is_a_theme_or_plugin_folder( string $path ): void {
		$this->expectException( ApiException::class );
		PreviewService::unitOf( $path );
	}

	public function test_preview_paths(): void {
		$this->assertSame( 'wp-content/plugins/myplug', PreviewService::unitOf( 'wp-content/plugins/myplug/inc/a.php' ) );
		$this->assertSame( 'wp-content/plugins/myplug--devbridge-preview/inc/a.php', PreviewService::previewPath( 'wp-content/plugins/myplug/inc/a.php' ) );
	}

	public function test_visibility_of_the_preview_through_caches(): void {
		$this->assertSame( [ 'visible' => true ], PreviewService::visibility( [ 'X-DevBridge-Preview' => '1' ] ) );
		$this->assertSame( [ 'visible' => false ], PreviewService::visibility( [ 'Content-Type' => 'text/html' ] ) );
		$this->assertSame(
			[
				'visible' => false,
				'cached'  => 'x-litespeed-cache: hit',
			],
			PreviewService::visibility( [ 'X-LiteSpeed-Cache' => 'hit' ] )
		);
		$this->assertStringStartsWith( 'wordpress_', PreviewService::COOKIE, 'Prefix bypassed by common server caches' );
	}

	private function deployer(): Deployer {
		return new Deployer(
			$this->guard(),
			$this->storage,
			new FakeHealth(),
			self::LIMITS,
			10,
			[
				'ip_allowlist'    => [],
				'trusted_proxies' => [],
				'allow_http'      => true,
			]
		);
	}
}
