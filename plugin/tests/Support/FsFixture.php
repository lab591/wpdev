<?php
/**
 * Temporary filesystem fixture that mimics a WordPress installation.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Support;

final class FsFixture {

	public readonly string $base;
	public readonly string $abspath;
	public readonly string $outside;

	public function __construct() {
		$this->base    = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'devbridge-test-' . bin2hex( random_bytes( 6 ) );
		$this->abspath = $this->base . DIRECTORY_SEPARATOR . 'site';
		$this->outside = $this->base . DIRECTORY_SEPARATOR . 'outside';
		mkdir( $this->abspath, 0777, true );
		mkdir( $this->outside, 0777, true );
	}

	/**
	 * Creates the standard WordPress-like tree used by most tests.
	 */
	public static function wordpress(): self {
		$fx = new self();
		$fx->write( 'wp-config.php', "<?php // secrets\n" );
		$fx->write( 'wp-config-sample.php', "<?php\n" );
		$fx->write( 'index.php', "<?php\n" );
		$fx->write( '.env', "SECRET=1\n" );
		$fx->write( '.git/config', "[core]\n" );
		$fx->write( 'dump.sql', "DROP TABLE x;\n" );
		$fx->write( 'wp-content/debug.log', "log\n" );
		$fx->write( 'wp-content/themes/child/style.css', "/* Theme Name: Child */\n" );
		$fx->write( 'wp-content/themes/child/functions.php', "<?php\n" );
		$fx->write( 'wp-content/themes/child/inc/helpers.php', "<?php\n" );
		$fx->write( 'wp-content/themes/child/fonts/caffè latte.txt', "unicode\n" );
		$fx->write( 'wp-content/themes/parent/style.css', "/* Theme Name: Parent */\n" );
		$fx->write( 'wp-content/plugins/myplug/myplug.php', "<?php\n" );
		$fx->write( 'wp-content/plugins/lab591-dev-bridge/lab591-dev-bridge.php', "<?php\n" );
		$fx->write( 'wp-content/uploads/2024/a.jpg', 'jpg' );
		$fx->write( 'wp-content/devbridge-abc123/rescue.json', '{}' );
		file_put_contents( $fx->outside . DIRECTORY_SEPARATOR . 'secret.txt', 'outside' );
		return $fx;
	}

	public function write( string $rel, string $content ): string {
		$abs = $this->abs( $rel );
		$dir = dirname( $abs );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $abs, $content );
		return $abs;
	}

	public function abs( string $rel ): string {
		return $this->abspath . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $rel );
	}

	/**
	 * Tries to create a symlink; returns false when the platform does not allow it
	 * (e.g. Windows without developer mode).
	 */
	public function symlink( string $target, string $rel ): bool {
		$link = $this->abs( $rel );
		$dir  = dirname( $link );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- capability probe.
		return @symlink( $target, $link ) && is_link( $link );
	}

	public function cleanup(): void {
		self::rmrf( $this->base );
	}

	private static function rmrf( string $path ): void {
		if ( is_link( $path ) ) {
			// On Windows directory links must be removed with rmdir.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! @unlink( $path ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@rmdir( $path );
			}
			return;
		}
		if ( is_dir( $path ) ) {
			foreach ( (array) scandir( $path ) as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				self::rmrf( $path . DIRECTORY_SEPARATOR . $entry );
			}
			rmdir( $path );
			return;
		}
		if ( file_exists( $path ) ) {
			unlink( $path );
		}
	}
}
