<?php
/**
 * Installs, verifies and removes the rescue mu-plugin (SPEC 2.9, lifecycle).
 *
 * The file is copied (never generated from strings), only with direct filesystem access.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Rescue;

final class RescueInstaller {

	public const FILE_NAME    = 'devbridge-rescue.php';
	public const MARKER       = 'Dev-Bridge-Rescue: lab591';
	public const CHECK_OPTION = 'devbridge_rescue_check';

	public const INSTALLED = 'installed';
	public const MISSING   = 'missing';
	public const OUTDATED  = 'outdated';

	public function __construct(
		private readonly string $source,
		private readonly string $muDir,
		private readonly string $version,
	) {
	}

	public function target(): string {
		return rtrim( $this->muDir, '/\\' ) . DIRECTORY_SEPARATOR . self::FILE_NAME;
	}

	public function state(): string {
		$target = $this->target();
		if ( ! is_file( $target ) ) {
			return self::MISSING;
		}
		return hash_file( 'sha256', $target ) === hash_file( 'sha256', $this->source ) ? self::INSTALLED : self::OUTDATED;
	}

	/**
	 * Whether the mu-plugins folder can be written without FTP credentials.
	 */
	public function canWriteDirectly(): bool {
		if ( defined( 'FS_METHOD' ) && 'direct' !== FS_METHOD ) {
			return false;
		}
		$dir = rtrim( $this->muDir, '/\\' );
		if ( is_dir( $dir ) ) {
			return is_writable( $dir ) && ( ! is_file( $this->target() ) || is_writable( $this->target() ) );
		}
		return is_dir( dirname( $dir ) ) && is_writable( dirname( $dir ) );
	}

	/**
	 * Copies the rescue file and verifies its hash. Returns the resulting state.
	 */
	public function install(): string {
		if ( self::INSTALLED === $this->state() ) {
			return self::INSTALLED;
		}
		if ( ! is_file( $this->source ) || ! $this->canWriteDirectly() ) {
			return $this->state();
		}
		$target = $this->target();
		if ( is_file( $target ) && ! $this->isOurs( $target ) ) {
			return self::MISSING; // Never overwrite a third-party file with the same name.
		}
		$dir = dirname( $target );
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755 ) && ! is_dir( $dir ) ) {
			return self::MISSING;
		}
		$tmp = $target . '.tmp';
		if ( ! copy( $this->source, $tmp ) ) {
			return $this->state();
		}
		chmod( $tmp, 0644 & ~umask() );
		if ( hash_file( 'sha256', $tmp ) !== hash_file( 'sha256', $this->source ) || ! rename( $tmp, $target ) ) {
			if ( is_file( $tmp ) ) {
				unlink( $tmp );
			}
			return $this->state();
		}
		return $this->state();
	}

	/**
	 * Lightweight self-repair for admin_init: re-verifies only when version, size or mtime changed.
	 */
	public function maybeRepair(): void {
		$target = $this->target();
		clearstatcache( true, $target );
		$fingerprint = [
			'version' => $this->version,
			'size'    => is_file( $target ) ? (int) filesize( $target ) : -1,
			'mtime'   => is_file( $target ) ? (int) filemtime( $target ) : -1,
		];
		if ( get_option( self::CHECK_OPTION ) === $fingerprint ) {
			return;
		}
		$this->install();
		clearstatcache( true, $target );
		$fingerprint['size']  = is_file( $target ) ? (int) filesize( $target ) : -1;
		$fingerprint['mtime'] = is_file( $target ) ? (int) filemtime( $target ) : -1;
		if ( self::INSTALLED === $this->state() ) {
			update_option( self::CHECK_OPTION, $fingerprint, true );
		} else {
			delete_option( self::CHECK_OPTION );
		}
	}

	/**
	 * Removes the installed file, only if its header identifies our mu-plugin.
	 */
	public function remove(): void {
		$target = $this->target();
		if ( is_file( $target ) && $this->isOurs( $target ) ) {
			unlink( $target );
		}
		delete_option( self::CHECK_OPTION );
	}

	private function isOurs( string $file ): bool {
		$fh = fopen( $file, 'rb' );
		if ( false === $fh ) {
			return false;
		}
		$head = (string) fread( $fh, 2048 );
		fclose( $fh );
		return str_contains( $head, self::MARKER );
	}
}
