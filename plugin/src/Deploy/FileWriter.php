<?php
/**
 * Atomic file operations on already-validated targets (SPEC 2.7 step 6).
 *
 * Callers must pass absolute paths obtained from PathGuard (or from the release backup,
 * which only contains files backed up after PathGuard validation).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Deploy;

final class FileWriter {

	/**
	 * Copies $source over $target via a temporary file in the same folder and rename().
	 * Missing parent folders are created (0755, umask permitting).
	 *
	 * @throws \RuntimeException On any filesystem failure.
	 */
	public static function writeAtomic( string $source, string $target ): void {
		$dir = dirname( $target );
		self::ensureDir( $dir );
		$tmp = $dir . DIRECTORY_SEPARATOR . '.devbridge-' . bin2hex( random_bytes( 6 ) ) . '.tmp';
		if ( ! copy( $source, $tmp ) ) {
			throw new \RuntimeException( 'copy to temporary file failed' );
		}
		chmod( $tmp, 0644 & ~umask() );
		if ( ! rename( $tmp, $target ) ) {
			if ( is_file( $tmp ) ) {
				unlink( $tmp );
			}
			throw new \RuntimeException( 'rename failed' );
		}
		self::invalidate( $target );
	}

	/**
	 * Deletes a file and the folders left empty, up to (excluding) $stopDir.
	 *
	 * @throws \RuntimeException When the file cannot be deleted.
	 */
	public static function delete( string $target, string $stopDir ): void {
		if ( is_file( $target ) || is_link( $target ) ) {
			self::invalidate( $target );
			if ( ! unlink( $target ) ) {
				throw new \RuntimeException( 'unlink failed' );
			}
		}
		self::pruneEmptyDirs( dirname( $target ), $stopDir );
	}

	public static function pruneEmptyDirs( string $dir, string $stopDir ): void {
		$stop   = rtrim( $stopDir, '/\\' );
		$prefix = strtolower( $stop . DIRECTORY_SEPARATOR );
		while ( str_starts_with( strtolower( $dir . DIRECTORY_SEPARATOR ), $prefix ) && strtolower( $dir ) !== strtolower( $stop ) ) {
			$entries = scandir( $dir );
			if ( false === $entries || count( $entries ) > 2 || is_link( $dir ) || ! rmdir( $dir ) ) {
				return;
			}
			$dir = dirname( $dir );
		}
	}

	private static function ensureDir( string $dir ): void {
		if ( is_dir( $dir ) ) {
			return;
		}
		self::ensureDir( dirname( $dir ) );
		if ( ! mkdir( $dir, 0755 & ~umask() ) && ! is_dir( $dir ) ) {
			throw new \RuntimeException( 'mkdir failed' );
		}
	}

	private static function invalidate( string $file ): void {
		if ( function_exists( 'opcache_invalidate' ) && str_ends_with( strtolower( $file ), '.php' ) ) {
			opcache_invalidate( $file, true );
		}
	}
}
