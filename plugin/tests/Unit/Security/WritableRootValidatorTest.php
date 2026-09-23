<?php
/**
 * Writable roots validation tests (SPEC 2.4).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Security;

use Lab591\DevBridge\Security\PathException;
use Lab591\DevBridge\Security\WritableRootValidator;
use Lab591\DevBridge\Tests\Support\FsFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WritableRootValidatorTest extends TestCase {

	private FsFixture $fx;

	protected function setUp(): void {
		$this->fx = FsFixture::wordpress();
		$this->fx->write( 'wp-content/mu-plugins/tools/tools.php', "<?php\n" );
	}

	protected function tearDown(): void {
		$this->fx->cleanup();
	}

	private function validator( bool $mu = false ): WritableRootValidator {
		return new WritableRootValidator(
			$this->fx->abspath,
			[
				$this->fx->abs( 'wp-content/plugins/lab591-dev-bridge' ),
				$this->fx->abs( 'wp-content/devbridge-abc123' ),
			],
			$mu
		);
	}

	public function test_accepts_theme_and_plugin_folders(): void {
		$this->assertSame( 'wp-content/themes/child', $this->validator()->validate( 'wp-content/themes/child' ) );
		$this->assertSame( 'wp-content/plugins/myplug', $this->validator()->validate( '/wp-content\\plugins\\myplug/' ) );
		$this->assertSame( 'wp-content/themes/child/inc', $this->validator()->validate( 'wp-content/themes/child/inc' ) );
	}

	public static function rejected(): array {
		return [
			'empty'             => [ '' ],
			'themes root'       => [ 'wp-content/themes' ],
			'plugins root'      => [ 'wp-content/plugins/' ],
			'wp-content'        => [ 'wp-content' ],
			'site root'         => [ '.' ],
			'uploads'           => [ 'wp-content/uploads' ],
			'core'              => [ 'wp-includes' ],
			'traversal'         => [ 'wp-content/themes/../../wp-admin' ],
			'dev bridge plugin' => [ 'wp-content/plugins/lab591-dev-bridge' ],
			'missing'           => [ 'wp-content/themes/missing' ],
			'file'              => [ 'wp-content/themes/child/style.css' ],
			'mu not enabled'    => [ 'wp-content/mu-plugins/tools' ],
			'absolute'          => [ 'C:/site/wp-content/themes/child' ],
			'prefix trick'      => [ 'wp-content/themes-evil/x' ],
		];
	}

	#[DataProvider( 'rejected' )]
	public function test_rejects( string $root ): void {
		$this->expectException( PathException::class );
		$this->validator()->validate( $root );
	}

	public function test_mu_plugins_when_enabled(): void {
		$this->assertSame( 'wp-content/mu-plugins/tools', $this->validator( true )->validate( 'wp-content/mu-plugins/tools' ) );
		$this->expectException( PathException::class );
		$this->validator( true )->validate( 'wp-content/mu-plugins' );
	}

	public function test_root_containing_protected_folder_is_rejected(): void {
		$validator = new WritableRootValidator(
			$this->fx->abspath,
			[ $this->fx->abs( 'wp-content/themes/child/inc' ) ]
		);
		$this->expectException( PathException::class );
		$validator->validate( 'wp-content/themes/child' );
	}

	public function test_symlinked_theme_pointing_outside_is_rejected(): void {
		if ( ! $this->fx->symlink( $this->fx->outside, 'wp-content/themes/linked' ) ) {
			$this->markTestSkipped( 'Symlinks are not supported on this system.' );
		}
		$this->expectException( PathException::class );
		$this->validator()->validate( 'wp-content/themes/linked' );
	}
}
