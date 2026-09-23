<?php
/**
 * Preview before publishing (SPEC 2.15, 0.5.0).
 *
 * The changed themes/plugins ("units") are copied next to the originals as
 * `<folder>--devbridge-preview` and the changes are applied to the copies only. Requests carrying
 * the preview cookie get the copies (mu-plugin devbridge-preview.php); everyone else sees the live
 * site. Publishing turns the preview into a normal deploy (backup, health check, rollback).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Deploy;

use Lab591\DevBridge\Security\Access;
use Lab591\DevBridge\Security\PathException;
use Lab591\DevBridge\Security\PathGuard;
use Lab591\DevBridge\Storage\Storage;
use Lab591\DevBridge\Support\ApiException;

final class PreviewService {

	public const SUFFIX     = '--devbridge-preview';
	public const FILE       = 'preview.json';
	public const TTL        = 8 * 3600;
	public const MAX_FILES  = 5000;
	public const MAX_BYTES  = 52428800;
	public const CONTAINERS = [ 'wp-content/themes', 'wp-content/plugins' ];

	/**
	 * @param array{deploy_zip_bytes: int, deploy_files: int, deploy_file_bytes: int} $limits
	 * @param \Closure(string): array<string, mixed>                                  $probe Health request with the preview cookie token.
	 */
	public function __construct(
		private readonly PathGuard $guard,
		private readonly Storage $storage,
		private readonly array $limits,
		private readonly \Closure $probe,
	) {
	}

	private function file(): string {
		return $this->storage->dir() . DIRECTORY_SEPARATOR . self::FILE;
	}

	/**
	 * Container and unit of a path: "wp-content/themes/child/inc/a.php" → "wp-content/themes/child".
	 *
	 * @throws ApiException When the path is not inside a theme or plugin folder.
	 */
	public static function unitOf( string $relative ): string {
		foreach ( self::CONTAINERS as $container ) {
			if ( 0 === strncasecmp( $relative, $container . '/', strlen( $container ) + 1 ) ) {
				$rest = substr( $relative, strlen( $container ) + 1 );
				$unit = explode( '/', $rest, 2 )[0];
				if ( '' !== $unit && str_contains( $rest, '/' ) && ! str_ends_with( $unit, self::SUFFIX ) ) {
					return $container . '/' . $unit;
				}
			}
		}
		throw new ApiException( 'preview_unsupported', 'Preview is available only for files inside a theme or plugin folder', 400, [ 'path' => $relative ] );
	}

	/**
	 * Path of a live file inside the preview copy of its unit.
	 */
	public static function previewPath( string $relative ): string {
		$unit = self::unitOf( $relative );
		return $unit . self::SUFFIX . substr( $relative, strlen( $unit ) );
	}

	/**
	 * Creates (or recreates from scratch) the preview with the given changes.
	 *
	 * @return array<string, mixed>
	 * @throws ApiException On validation errors (nothing is written) or copy failures.
	 */
	public function create( Manifest $manifest, ?string $zipPath, int $userId ): array {
		$this->storage->ensure();
		$lock = new Lock( $this->storage->lockFile() );
		$lock->acquire();
		$plan = null;
		try {
			$plan  = ( new DeployValidator( $this->guard, $this->limits, $this->storage->tmpDir() ) )->validate( $manifest, $zipPath );
			$units = [];
			foreach ( $plan->ops as $op ) {
				$units[ self::unitOf( $op->target->relative ) ] = true;
			}
			if ( [] === $units ) {
				throw new ApiException( 'invalid_manifest', 'Nothing to preview', 400 );
			}
			$this->removeCopies();
			$copies = [];
			try {
				foreach ( array_keys( $units ) as $unit ) {
					$copies[ $unit ] = $this->copyUnit( $unit );
				}
				foreach ( $plan->ops as $op ) {
					$unit = self::unitOf( $op->target->relative );
					$dest = $copies[ $unit ] . str_replace( '/', DIRECTORY_SEPARATOR, substr( $op->target->relative, strlen( $unit ) ) );
					if ( 'write' === $op->action ) {
						FileWriter::writeAtomic( (string) $op->staged, $dest );
					} else {
						FileWriter::delete( $dest, $copies[ $unit ] );
					}
				}
			} catch ( ApiException $e ) {
				$this->removeCopies();
				throw $e;
			} catch ( \Throwable $e ) {
				$this->removeCopies();
				throw new ApiException( 'preview_failed', 'The preview could not be created', 500 );
			}

			$token = bin2hex( random_bytes( 32 ) );
			$data  = [
				'token_sha256' => hash( 'sha256', $token ),
				'expires_at'   => time() + self::TTL,
				'created_at'   => time(),
				'created_by'   => $userId,
				'units'        => array_combine( array_keys( $copies ), array_map( static fn ( string $u ): string => $u . self::SUFFIX, array_keys( $copies ) ) ),
				'files'        => array_map(
					static fn ( ManifestEntry $e ): array => [
						'p'      => $e->path,
						'action' => $e->action,
						'h'      => $e->h,
						'base_h' => $e->baseH,
					],
					$manifest->entries
				),
			];
			$this->save( $data );
			return [
				'link'       => home_url( '/?devbridge_preview=' . $token ),
				'expires_at' => $data['expires_at'],
				'units'      => array_keys( $copies ),
				'files'      => count( $data['files'] ),
				'health'     => ( $this->probe )( $token ),
			];
		} finally {
			if ( null !== $plan ) {
				DeployValidator::removeDir( $plan->stagingDir );
			}
			$lock->release();
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function status(): array {
		$data = $this->load();
		if ( null === $data ) {
			return [ 'active' => false ];
		}
		return [
			'active'     => true,
			'expires_at' => (int) $data['expires_at'],
			'expired'    => (int) $data['expires_at'] < time(),
			'units'      => array_keys( (array) $data['units'] ),
			'files'      => array_map( static fn ( array $f ): string => $f['action'] . ' ' . $f['p'], (array) $data['files'] ),
		];
	}

	/**
	 * Publishes the preview as a normal deploy; the preview is removed when the deploy succeeds.
	 *
	 * @return array<string, mixed> Deploy response.
	 * @throws ApiException `no_preview`, `preview_changed` or any deploy error.
	 */
	public function publish( Deployer $deployer, int $userId ): array {
		$data = $this->load();
		if ( null === $data ) {
			throw new ApiException( 'no_preview', 'There is no preview to publish', 404 );
		}
		$this->storage->ensure();
		$zipPath = $this->storage->tmpDir() . DIRECTORY_SEPARATOR . 'preview-' . bin2hex( random_bytes( 6 ) ) . '.zip';
		$zip     = new \ZipArchive();
		if ( true !== $zip->open( $zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			throw new ApiException( 'internal_error', 'Temporary archive could not be created', 500 );
		}
		$hasFiles = false;
		try {
			foreach ( (array) $data['files'] as $file ) {
				if ( 'write' !== $file['action'] ) {
					continue;
				}
				$source = $this->guard->resolve( self::previewPath( (string) $file['p'] ), Access::Read );
				if ( ! is_file( $source->absolute ) || hash_file( 'xxh128', $source->absolute ) !== $file['h'] ) {
					throw new ApiException( 'preview_changed', 'A file of the preview changed after it was created: create the preview again', 409, [ 'path' => $file['p'] ] );
				}
				$zip->addFile( $source->absolute, (string) $file['p'] );
				$hasFiles = true;
			}
			$zip->close();
			$manifest = Manifest::parse(
				(string) wp_json_encode(
					[
						'files' => array_values( (array) $data['files'] ),
						'force' => false,
					]
				),
				max( 1, count( (array) $data['files'] ) )
			);
			$out      = $deployer->deploy( $manifest, $hasFiles ? $zipPath : null, $userId );
		} finally {
			if ( is_file( $zipPath ) ) {
				unlink( $zipPath );
			}
		}
		if ( in_array( $out['status'] ?? '', [ ReleaseStore::STATUS_OK, ReleaseStore::STATUS_UNKNOWN ], true ) ) {
			$this->discard();
		}
		return $out + [ 'files' => array_values( (array) $data['files'] ) ];
	}

	/**
	 * Removes the copies and the preview record.
	 */
	public function discard(): void {
		$this->removeCopies();
		if ( is_file( $this->file() ) ) {
			unlink( $this->file() );
		}
	}

	// ------------------------------------------------------------------ internals

	/**
	 * Copies a unit next to itself (no symlinks, no denied files, within the size limits).
	 *
	 * @throws ApiException When the unit cannot be read or is too large.
	 */
	private function copyUnit( string $unit ): string {
		try {
			$source = $this->guard->resolve( $unit, Access::Read );
		} catch ( PathException $e ) {
			throw new ApiException( 'preview_unsupported', 'This theme or plugin folder cannot be previewed', 400, [ 'path' => $unit ] );
		}
		if ( ! $source->isDir ) {
			throw new ApiException( 'preview_unsupported', 'This theme or plugin folder cannot be previewed', 400, [ 'path' => $unit ] );
		}
		$dest = dirname( $source->absolute ) . DIRECTORY_SEPARATOR . basename( $source->absolute ) . self::SUFFIX;
		if ( file_exists( $dest ) || is_link( $dest ) ) {
			throw new ApiException( 'preview_failed', 'A preview folder already exists and is not ours', 409, [ 'path' => $unit . self::SUFFIX ] );
		}
		mkdir( $dest, 0755 );
		$files = 0;
		$bytes = 0;
		$it    = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source->absolute, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $it as $item ) {
			/** @var \SplFileInfo $item */
			if ( $item->isLink() ) {
				continue;
			}
			$inner = str_replace( '\\', '/', substr( $item->getPathname(), strlen( $source->absolute ) + 1 ) );
			if ( $this->guard->isDenied( $unit . '/' . $inner ) ) {
				continue;
			}
			$target = $dest . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $inner );
			if ( $item->isDir() ) {
				if ( ! is_dir( $target ) ) {
					mkdir( $target, 0755, true );
				}
				continue;
			}
			++$files;
			$bytes += (int) $item->getSize();
			if ( $files > self::MAX_FILES || $bytes > self::MAX_BYTES ) {
				throw new ApiException( 'too_large', sprintf( 'Too large to preview (max %d files, %d MB per theme or plugin)', self::MAX_FILES, (int) ( self::MAX_BYTES / 1048576 ) ), 413, [ 'path' => $unit ] );
			}
			if ( ! is_dir( dirname( $target ) ) ) {
				mkdir( dirname( $target ), 0755, true );
			}
			copy( $item->getPathname(), $target );
		}
		return $dest;
	}

	/**
	 * Removes every `*--devbridge-preview` folder of the containers (recorded or left over).
	 */
	private function removeCopies(): void {
		$abspath = rtrim( $this->guard->policy()->abspath, '/\\' );
		foreach ( self::CONTAINERS as $container ) {
			$dir = $abspath . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $container );
			foreach ( (array) glob( $dir . DIRECTORY_SEPARATOR . '*' . self::SUFFIX, GLOB_ONLYDIR ) as $copy ) {
				$copy = (string) $copy;
				if ( '' !== $copy && ! is_link( $copy ) && str_ends_with( $copy, self::SUFFIX ) ) {
					Storage::removeTree( $copy );
				}
			}
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function load(): ?array {
		$file = $this->file();
		if ( ! is_file( $file ) || filesize( $file ) > 1048576 ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $data ) && isset( $data['units'], $data['files'] ) ? $data : null;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function save( array $data ): void {
		$tmp = $this->file() . '.tmp';
		file_put_contents( $tmp, (string) wp_json_encode( $data ) );
		chmod( $tmp, 0600 );
		rename( $tmp, $this->file() );
	}
}
