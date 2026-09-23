<?php
/**
 * Private storage (SPEC 2.10): releases, rescue.json, deploy.lock, staging.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Storage;

use Lab591\DevBridge\Support\ApiException;

final class Storage {

	public const SUFFIX_OPTION = 'devbridge_storage_suffix';

	public function __construct(
		private readonly string $dir,
		private readonly bool $fallback = false,
	) {
	}

	/**
	 * Storage from the DEVBRIDGE_STORAGE_DIR constant, or the random-suffix fallback in wp-content.
	 */
	public static function fromWordPress(): self {
		if ( defined( 'DEVBRIDGE_STORAGE_DIR' ) && is_string( DEVBRIDGE_STORAGE_DIR ) && '' !== DEVBRIDGE_STORAGE_DIR ) {
			return new self( rtrim( DEVBRIDGE_STORAGE_DIR, '/\\' ) );
		}
		$suffix = get_option( self::SUFFIX_OPTION );
		if ( ! is_string( $suffix ) || 1 !== preg_match( '/^[0-9a-f]{16}$/', $suffix ) ) {
			$suffix = bin2hex( random_bytes( 8 ) );
			update_option( self::SUFFIX_OPTION, $suffix, false );
		}
		return new self( rtrim( WP_CONTENT_DIR, '/\\' ) . DIRECTORY_SEPARATOR . 'devbridge-' . $suffix, true );
	}

	public function dir(): string {
		return $this->dir;
	}

	public function isFallback(): bool {
		return $this->fallback;
	}

	public function releasesDir(): string {
		return $this->dir . DIRECTORY_SEPARATOR . 'releases';
	}

	public function tmpDir(): string {
		return $this->dir . DIRECTORY_SEPARATOR . 'tmp';
	}

	public function lockFile(): string {
		return $this->dir . DIRECTORY_SEPARATOR . 'deploy.lock';
	}

	public function rescueFile(): string {
		return $this->dir . DIRECTORY_SEPARATOR . 'rescue.json';
	}

	/**
	 * Creates the folders and the web-access protections. Idempotent.
	 *
	 * @throws ApiException When the storage cannot be created.
	 */
	public function ensure(): void {
		foreach ( [ $this->dir, $this->releasesDir(), $this->tmpDir() ] as $dir ) {
			if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
				throw new ApiException( 'internal_error', 'Storage folder could not be created', 500 );
			}
		}
		$guards = [
			'.htaccess'  => "# Dev Bridge private storage\nRequire all denied\nDeny from all\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		];
		foreach ( $guards as $name => $content ) {
			$file = $this->dir . DIRECTORY_SEPARATOR . $name;
			if ( ! is_file( $file ) ) {
				file_put_contents( $file, $content );
			}
		}
	}

	public function exists(): bool {
		return is_dir( $this->dir );
	}

	/**
	 * Deletes what the plugin created in the storage (uninstall only). The folder itself is
	 * removed only when left empty, so a DEVBRIDGE_STORAGE_DIR shared with other files is safe.
	 */
	public function destroy(): void {
		if ( '' === $this->dir || ! is_dir( $this->dir ) || is_link( $this->dir ) ) {
			return;
		}
		foreach ( [ $this->releasesDir(), $this->tmpDir() ] as $dir ) {
			if ( is_dir( $dir ) && ! is_link( $dir ) ) {
				self::removeTree( $dir );
			}
		}
		foreach ( [ 'rescue.json', 'rescue.json.tmp', 'deploy.lock', '.htaccess', 'index.php', 'web.config' ] as $name ) {
			$file = $this->dir . DIRECTORY_SEPARATOR . $name;
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		$left = scandir( $this->dir );
		if ( false !== $left && count( $left ) <= 2 ) {
			rmdir( $this->dir );
		}
	}

	/**
	 * Recursive delete that never follows symlinks.
	 */
	public static function removeTree( string $dir ): void {
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::removeTree( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}
}
