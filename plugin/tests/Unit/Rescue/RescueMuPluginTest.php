<?php
/**
 * Out-of-band rescue mu-plugin (SPEC 2.9).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Rescue;

use Lab591\DevBridge\Deploy\Deployer;
use Lab591\DevBridge\Deploy\Manifest;
use Lab591\DevBridge\Deploy\ReleaseStore;
use Lab591\DevBridge\Security\PathGuard;
use Lab591\DevBridge\Security\PathPolicy;
use Lab591\DevBridge\Storage\Storage;
use Lab591\DevBridge\Tests\Support\FakeHealth;
use Lab591\DevBridge\Tests\Support\FsFixture;
use Lab591\DevBridge\Tests\Support\ZipBuilder;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/mu-plugin/devbridge-rescue.php';

final class RescueMuPluginTest extends TestCase {

	private FsFixture $fx;
	private Storage $storage;

	protected function setUp(): void {
		$this->fx      = FsFixture::wordpress();
		$this->storage = new Storage( $this->fx->base . DIRECTORY_SEPARATOR . 'storage' );
	}

	protected function tearDown(): void {
		$this->fx->cleanup();
	}

	/**
	 * Deploys a broken functions.php and a new file; returns the rescue token.
	 */
	private function deploy( array $rescueContext = [] ): string {
		$guard    = new PathGuard( new PathPolicy( $this->fx->abspath, [ '' ], [ 'wp-content/themes/child' ], protectedPaths: [ $this->storage->dir() ] ) );
		$broken   = "<?php fatal(\n";
		$manifest = json_encode(
			[
				'files' => [
					[
						'p'      => 'wp-content/themes/child/functions.php',
						'action' => 'write',
						'h'      => hash( 'xxh128', $broken ),
						'base_h' => hash_file( 'xxh128', $this->fx->abs( 'wp-content/themes/child/functions.php' ) ),
					],
					[
						'p'      => 'wp-content/themes/child/extra/new.css',
						'action' => 'write',
						'h'      => hash( 'xxh128', 'x' ),
					],
				],
			]
		);
		$zip      = ZipBuilder::build(
			$this->fx->base . '/r.zip',
			[
				'wp-content/themes/child/functions.php' => $broken,
				'wp-content/themes/child/extra/new.css' => 'x',
			]
		);
		$deployer = new Deployer(
			$guard,
			$this->storage,
			new FakeHealth(),
			[
				'deploy_zip_bytes'  => 1048576,
				'deploy_files'      => 10,
				'deploy_file_bytes' => 65536,
			],
			10,
			$rescueContext + [
				'ip_allowlist'    => [],
				'trusted_proxies' => [],
				'allow_http'      => false,
			]
		);
		return (string) $deployer->deploy( Manifest::parse( (string) $manifest, 10 ), $zip, 1 )['rescue_token'];
	}

	private function call( string $token, array $server = [] ): ?array {
		return devbridge_rescue_handle(
			$server + [
				'HTTP_X_DEVBRIDGE_RESCUE' => $token,
				'HTTPS'                   => 'on',
				'REMOTE_ADDR'             => '203.0.113.9',
			],
			$this->fx->abspath . DIRECTORY_SEPARATOR,
			$this->fx->abs( 'wp-content' ),
			$this->fx->abs( 'wp-content/plugins' ),
			$this->storage->dir()
		);
	}

	public function test_valid_token_restores_release_once(): void {
		$original = (string) file_get_contents( $this->fx->abs( 'wp-content/themes/child/functions.php' ) );
		$token    = $this->deploy();
		$this->assertSame( "<?php fatal(\n", file_get_contents( $this->fx->abs( 'wp-content/themes/child/functions.php' ) ) );

		$result = $this->call( $token );
		$this->assertSame( 200, $result[0] );
		$this->assertSame( 'ok', $result[1]['status'] );
		$files = array_column( $result[1]['files'], 'h', 'p' );
		$this->assertSame( hash( 'xxh128', $original ), $files['wp-content/themes/child/functions.php'] );
		$this->assertNull( $files['wp-content/themes/child/extra/new.css'] );

		$this->assertSame( $original, file_get_contents( $this->fx->abs( 'wp-content/themes/child/functions.php' ) ) );
		$this->assertFileDoesNotExist( $this->fx->abs( 'wp-content/themes/child/extra/new.css' ) );
		$this->assertFileDoesNotExist( $this->storage->rescueFile() );

		$release = ( new ReleaseStore( $this->storage->releasesDir() ) )->all()[0];
		$this->assertSame( 'rescued', $release['status'] );

		$this->assertNull( $this->call( $token ), 'Token is single use: afterwards the plugin is inert' );
	}

	public function test_wrong_token_is_denied_and_changes_nothing(): void {
		$this->deploy();
		$result = $this->call( str_repeat( 'a', 64 ) );
		$this->assertSame( 403, $result[0] );
		$this->assertSame( 'rescue_denied', $result[1]['error']['code'] );
		$this->assertFileExists( $this->storage->rescueFile() );
		$this->assertSame( 403, $this->call( 'not-hex' )[0] );
	}

	public function test_http_is_denied_unless_allowed(): void {
		$token = $this->deploy();
		$this->assertSame( 403, $this->call( $token, [ 'HTTPS' => 'off' ] )[0] );

		$this->fx->cleanup();
		$this->setUp();
		$token = $this->deploy( [ 'allow_http' => true ] );
		$this->assertSame( 200, $this->call( $token, [ 'HTTPS' => '' ] )[0] );
	}

	public function test_ip_allowlist(): void {
		$token = $this->deploy( [ 'ip_allowlist' => [ '198.51.100.0/24' ] ] );
		$this->assertSame( 403, $this->call( $token )[0] );
		$this->assertSame( 200, $this->call( $token, [ 'REMOTE_ADDR' => '198.51.100.20' ] )[0] );
	}

	public function test_expired_token_is_inert(): void {
		$token              = $this->deploy();
		$data               = json_decode( (string) file_get_contents( $this->storage->rescueFile() ), true );
		$data['expires_at'] = time() - 1;
		file_put_contents( $this->storage->rescueFile(), (string) json_encode( $data ) );
		$this->assertNull( $this->call( $token ) );
	}

	public function test_orphaned_mu_plugin_is_inert(): void {
		$token = $this->deploy();
		unlink( $this->fx->abs( 'wp-content/plugins/lab591-dev-bridge/lab591-dev-bridge.php' ) );
		$this->assertNull( $this->call( $token ) );
	}

	public function test_no_header_is_inert(): void {
		$this->deploy();
		$this->assertNull( $this->call( '' ) );
	}

	public function test_tampered_release_path_is_refused(): void {
		$token            = $this->deploy();
		$store            = new ReleaseStore( $this->storage->releasesDir() );
		$release          = $store->all()[0];
		$release['ops'][] = [
			'p'       => '../outside/secret.txt',
			'action'  => 'write',
			'existed' => false,
		];
		$store->save( $release );
		$result = $this->call( $token );
		$this->assertSame( 500, $result[0] );
		$this->assertSame( 'rescue_failed', $result[1]['error']['code'] );
		$this->assertFileExists( $this->fx->outside . DIRECTORY_SEPARATOR . 'secret.txt' );
	}

	public function test_fallback_storage_discovery(): void {
		$dir = $this->fx->abs( 'wp-content/devbridge-0123456789abcdef' );
		mkdir( $dir );
		file_put_contents( $dir . '/rescue.json', '{}' );
		$this->assertSame( realpath( $dir ), realpath( (string) devbridge_rescue_storage( $this->fx->abs( 'wp-content' ), '' ) ) );
		$this->assertNull( devbridge_rescue_storage( $this->fx->base . '/nothing', '' ) );
	}

	public function test_ip_helpers(): void {
		$this->assertTrue( devbridge_rescue_ip_in( '10.1.2.3', [ '10.0.0.0/8' ] ) );
		$this->assertFalse( devbridge_rescue_ip_in( '11.1.2.3', [ '10.0.0.0/8' ] ) );
		$this->assertTrue( devbridge_rescue_ip_in( '2001:db8::5', [ '2001:db8::/32' ] ) );
		$this->assertSame( '1.1.1.1', devbridge_rescue_client_ip( '10.0.0.1', '1.1.1.1', [ '10.0.0.0/8' ] ) );
		$this->assertSame( '10.0.0.1', devbridge_rescue_client_ip( '10.0.0.1', '1.1.1.1', [] ) );
	}
}
