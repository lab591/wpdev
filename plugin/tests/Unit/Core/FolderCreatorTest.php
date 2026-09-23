<?php
/**
 * Creation of empty writable folders from the admin page.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Core;

use Lab591\DevBridge\Admin\FolderCreator;
use Lab591\DevBridge\Security\PathException;
use Lab591\DevBridge\Security\PathPolicy;
use Lab591\DevBridge\Security\WritableRootValidator;
use Lab591\DevBridge\Tests\Support\FsFixture;
use PHPUnit\Framework\TestCase;

final class FolderCreatorTest extends TestCase {

	private FsFixture $fx;

	protected function setUp(): void {
		$this->fx = FsFixture::wordpress();
	}

	protected function tearDown(): void {
		$this->fx->cleanup();
	}

	private function creator( bool $mu = false ): FolderCreator {
		return new FolderCreator(
			new WritableRootValidator(
				$this->fx->abspath,
				[ $this->fx->abs( 'wp-content/plugins/lab591-dev-bridge' ), $this->fx->abs( 'wp-content/devbridge-abc123' ) ],
				$mu,
				array_merge( PathPolicy::DEFAULT_DENY_PATTERNS, [ 'wp-content/plugins/secret-*/**', 'wp-content/plugins/secret-*' ] )
			)
		);
	}

	public function test_creates_the_folder_of_a_new_plugin(): void {
		$this->assertSame( 'wp-content/plugins/mio-plugin', $this->creator()->create( 'wp-content/plugins', 'mio-plugin' ) );
		$this->assertDirectoryExists( $this->fx->abs( 'wp-content/plugins/mio-plugin' ) );
	}

	public function test_creates_nested_folders_and_accepts_windows_separators(): void {
		$this->assertSame( 'wp-content/themes/nuovo-child/blocks', $this->creator()->create( 'wp-content/themes', 'nuovo-child\\blocks' ) );
		$this->assertDirectoryExists( $this->fx->abs( 'wp-content/themes/nuovo-child/blocks' ) );
	}

	public function test_subfolder_of_an_existing_theme_and_existing_folder_is_returned(): void {
		$this->assertSame( 'wp-content/themes/child/blocks', $this->creator()->create( 'wp-content/themes', 'child/blocks' ) );
		$this->assertSame( 'wp-content/themes/child', $this->creator()->create( 'wp-content/themes', 'child/' ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function refused(): array {
		return [
			'traversal'          => [ 'wp-content/plugins', '../uploads/x' ],
			'dot segment'        => [ 'wp-content/plugins', 'a/./b' ],
			'hidden'             => [ 'wp-content/plugins', '.hidden' ],
			'trailing dot'       => [ 'wp-content/plugins', 'name.' ],
			'space'              => [ 'wp-content/plugins', 'con spazio' ],
			'colon'              => [ 'wp-content/plugins', 'c:x' ],
			'empty'              => [ 'wp-content/plugins', '  ' ],
			'too deep'           => [ 'wp-content/plugins', 'a/b/c/d/e' ],
			'uploads container'  => [ 'wp-content/uploads', 'x' ],
			'wp-content'         => [ 'wp-content', 'x' ],
			'mu-plugins off'     => [ 'wp-content/mu-plugins', 'x' ],
			'inside dev bridge'  => [ 'wp-content/plugins', 'lab591-dev-bridge/x' ],
			'deny list'          => [ 'wp-content/plugins', 'secret-stuff' ],
			'deny list (nested)' => [ 'wp-content/plugins', 'secret-stuff/inner' ],
		];
	}

	/**
	 * @dataProvider refused
	 */
	public function test_refuses_and_leaves_nothing_behind( string $container, string $path ): void {
		$before = self::tree( $this->fx->abspath );
		try {
			$this->creator()->create( $container, $path );
			$this->fail( 'Expected a PathException' );
		} catch ( PathException $e ) {
			$this->assertStringNotContainsString( $this->fx->abspath, $e->getMessage() );
		}
		$this->assertSame( $before, self::tree( $this->fx->abspath ) );
	}

	public function test_mu_plugins_when_enabled(): void {
		$this->fx->write( 'wp-content/mu-plugins/index.php', "<?php\n" );
		$this->assertSame( 'wp-content/mu-plugins/tools', $this->creator( true )->create( 'wp-content/mu-plugins', 'tools' ) );
	}

	/**
	 * @return string[]
	 */
	private static function tree( string $dir ): array {
		$out = [];
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $it as $file ) {
			$out[] = substr( (string) $file, strlen( $dir ) );
		}
		sort( $out );
		return $out;
	}
}
