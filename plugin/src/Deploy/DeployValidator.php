<?php
/**
 * Full deploy validation before any write (SPEC 2.7 steps 2–4).
 *
 * Every path goes through PathGuard; every zip entry must match a manifest line and vice
 * versa; extracted content must match the declared hash; conflicts are detected against
 * `base_h`. The site is never touched here: content is staged in the private storage.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Deploy;

use Lab591\DevBridge\Security\Access;
use Lab591\DevBridge\Security\PathException;
use Lab591\DevBridge\Security\PathGuard;
use Lab591\DevBridge\Security\ResolvedPath;
use Lab591\DevBridge\Support\ApiException;

final class DeployValidator {

	private const CHUNK      = 65536;
	private const S_IFMT     = 0170000;
	private const S_IFREG    = 0100000;
	private const TOTAL_MULT = 4;

	/**
	 * @param array{deploy_zip_bytes: int, deploy_files: int, deploy_file_bytes: int} $limits
	 * @param string                                                                  $stagingRoot Private folder for extracted files.
	 */
	public function __construct(
		private readonly PathGuard $guard,
		private readonly array $limits,
		private readonly string $stagingRoot,
	) {
	}

	/**
	 * @throws ApiException On any validation failure or conflict (nothing is written to the site).
	 */
	public function validate( Manifest $manifest, ?string $zipPath ): DeployPlan {
		if ( count( $manifest->entries ) > $this->limits['deploy_files'] ) {
			throw new ApiException( 'too_many_files', sprintf( 'At most %d files per deploy', $this->limits['deploy_files'] ), 413 );
		}

		// 1. Paths.
		$targets = [];
		$missing = [];
		$seen    = [];
		foreach ( $manifest->entries as $i => $entry ) {
			[ $targets[ $i ], $missing[ $i ] ] = $this->resolveTarget( $entry );
			$key                               = $this->guard->policy()->caseInsensitive ? mb_strtolower( $targets[ $i ]->relative ) : $targets[ $i ]->relative;
			if ( isset( $seen[ $key ] ) ) {
				throw new ApiException( 'invalid_manifest', 'Invalid manifest: duplicate path', 400, [ 'path' => $targets[ $i ]->relative ] );
			}
			$seen[ $key ] = true;
		}

		// 2. Bundle structure, then extraction with hash verification.
		$writes = [];
		foreach ( $manifest->entries as $i => $entry ) {
			if ( 'write' === $entry->action ) {
				$writes[ $entry->path ] = $i;
			}
		}
		$staging = $this->stagingRoot . DIRECTORY_SEPARATOR . 'st-' . bin2hex( random_bytes( 8 ) );
		try {
			$staged = [] === $writes && null === $zipPath ? [] : $this->extract( $zipPath, $writes, $manifest, $staging );

			// 3. Conflicts.
			$ops       = [];
			$conflicts = [];
			foreach ( $manifest->entries as $i => $entry ) {
				$target   = $targets[ $i ];
				$exists   = ! $missing[ $i ] && $target->exists;
				$current  = $exists ? (string) hash_file( 'xxh128', $target->absolute ) : null;
				$conflict = self::conflict( $entry, $current );
				if ( null !== $conflict && ! $manifest->force ) {
					$conflicts[] = [
						'p'      => $target->relative,
						'reason' => $conflict,
					];
					continue;
				}
				if ( 'delete' === $entry->action && ! $exists ) {
					continue; // Already gone (forced): nothing to do.
				}
				if ( 'write' === $entry->action && null !== $current && $current === $entry->h ) {
					continue; // Same content already on the server.
				}
				$ops[] = new PlannedOp( $entry->action, $target, $staged[ $i ] ?? null, $entry->h, $exists, $current );
			}
			if ( [] !== $conflicts ) {
				throw new ApiException( 'conflict', 'Files changed on the server since the last sync', 409, [ 'conflicts' => $conflicts ] );
			}
		} catch ( \Throwable $e ) {
			self::removeDir( $staging );
			throw $e;
		}

		return new DeployPlan( $ops, $staging );
	}

	/**
	 * Conflict reason for an entry given the current server hash (null: file absent).
	 */
	private static function conflict( ManifestEntry $entry, ?string $current ): ?string {
		if ( 'write' === $entry->action ) {
			if ( null === $current ) {
				return null === $entry->baseH ? null : 'deleted';
			}
			if ( $current === $entry->h ) {
				return null; // Same content already on the server.
			}
			if ( null === $entry->baseH ) {
				return 'exists';
			}
			return $current === $entry->baseH ? null : 'modified';
		}
		if ( null === $current ) {
			return 'deleted';
		}
		return $current === $entry->baseH ? null : 'modified';
	}

	/**
	 * @return array{0: ResolvedPath, 1: bool} Target and "missing" flag (delete of an absent file).
	 */
	private function resolveTarget( ManifestEntry $entry ): array {
		try {
			if ( 'write' === $entry->action ) {
				return [ $this->guard->resolve( $entry->path, Access::WriteNew ), false ];
			}
			try {
				return [ $this->guard->resolve( $entry->path, Access::Write ), false ];
			} catch ( PathException $e ) {
				if ( 'not_found' !== $e->errorCode() ) {
					throw $e;
				}
				return [ $this->guard->resolve( $entry->path, Access::WriteNew ), true ];
			}
		} catch ( PathException $e ) {
			throw new ApiException( $e->errorCode(), $e->getMessage(), $e->status(), [ 'path' => Manifest::safePath( $entry->path ) ] );
		}
	}

	/**
	 * Validates the zip against the manifest and extracts write entries into $staging.
	 *
	 * @param array<string, int> $writes Manifest path => manifest index, for write entries.
	 * @return array<int, string> Manifest index => staged file.
	 */
	private function extract( ?string $zipPath, array $writes, Manifest $manifest, string $staging ): array {
		if ( null === $zipPath ) {
			throw self::bundle( 'bundle missing: the manifest contains writes' );
		}
		$size = filesize( $zipPath );
		if ( false === $size ) {
			throw self::bundle( 'bundle unreadable' );
		}
		if ( $size > $this->limits['deploy_zip_bytes'] ) {
			throw new ApiException( 'too_large', sprintf( 'Bundle exceeds %d bytes', $this->limits['deploy_zip_bytes'] ), 413 );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zipPath, \ZipArchive::RDONLY ) ) {
			throw self::bundle( 'bundle is not a valid zip archive' );
		}
		try {
			if ( $zip->numFiles > $this->limits['deploy_files'] ) {
				throw new ApiException( 'too_many_files', sprintf( 'At most %d files per deploy', $this->limits['deploy_files'] ), 413 );
			}

			// Structure first: no extraction until every entry is known to be acceptable.
			$indexes = [];
			$total   = 0;
			for ( $n = 0; $n < $zip->numFiles; $n++ ) {
				$stat = $zip->statIndex( $n, \ZipArchive::FL_UNCHANGED );
				if ( false === $stat ) {
					throw self::bundle( 'unreadable zip entry' );
				}
				$name = (string) $stat['name'];
				self::checkEntryName( $name );
				if ( ! empty( $stat['encryption_method'] ) ) {
					throw self::bundle( 'encrypted entries are not allowed', $name );
				}
				$opsys = 0;
				$attr  = 0;
				if ( $zip->getExternalAttributesIndex( $n, $opsys, $attr ) && \ZipArchive::OPSYS_UNIX === $opsys ) {
					$type = ( $attr >> 16 ) & self::S_IFMT;
					if ( 0 !== $type && self::S_IFREG !== $type ) {
						throw self::bundle( 'only regular files are allowed (no symlinks or devices)', $name );
					}
				}
				if ( ! isset( $writes[ $name ] ) ) {
					throw self::bundle( 'entry not listed as "write" in the manifest', $name );
				}
				if ( isset( $indexes[ $name ] ) ) {
					throw self::bundle( 'duplicate entry', $name );
				}
				if ( (int) $stat['size'] > $this->limits['deploy_file_bytes'] ) {
					throw new ApiException( 'too_large', sprintf( 'File exceeds %d bytes', $this->limits['deploy_file_bytes'] ), 413, [ 'path' => $name ] );
				}
				$total += (int) $stat['size'];
				if ( $total > $this->limits['deploy_zip_bytes'] * self::TOTAL_MULT ) {
					throw new ApiException( 'too_large', 'Uncompressed bundle is too large', 413 );
				}
				$indexes[ $name ] = $n;
			}
			foreach ( $writes as $path => $unused ) {
				if ( ! isset( $indexes[ $path ] ) ) {
					throw self::bundle( 'file listed in the manifest is missing from the bundle', $path );
				}
			}

			if ( ! is_dir( $staging ) && ! mkdir( $staging, 0700, true ) ) {
				throw new ApiException( 'internal_error', 'Staging folder could not be created', 500 );
			}
			$staged = [];
			$seq    = 0;
			foreach ( $indexes as $name => $n ) {
				$i            = $writes[ $name ];
				$file         = $staging . DIRECTORY_SEPARATOR . sprintf( '%05d.bin', ++$seq );
				$hash         = $this->extractEntry( $zip, $n, $name, $file );
				$staged[ $i ] = $file;
				if ( $hash !== $manifest->entries[ $i ]->h ) {
					throw self::bundle( 'content hash does not match "h"', $name );
				}
			}
			return $staged;
		} finally {
			$zip->close();
		}
	}

	/**
	 * Streams one entry to $file, enforcing the real (not declared) size limit. Returns its xxh128.
	 */
	private function extractEntry( \ZipArchive $zip, int $index, string $name, string $file ): string {
		$in = method_exists( $zip, 'getStreamIndex' ) ? $zip->getStreamIndex( $index ) : $zip->getStream( $name );
		if ( false === $in ) {
			throw self::bundle( 'entry could not be read', $name );
		}
		$out = fopen( $file, 'xb' );
		if ( false === $out ) {
			fclose( $in );
			throw new ApiException( 'internal_error', 'Staging file could not be created', 500 );
		}
		$ctx   = hash_init( 'xxh128' );
		$bytes = 0;
		try {
			while ( ! feof( $in ) ) {
				$chunk = fread( $in, self::CHUNK );
				if ( false === $chunk ) {
					throw self::bundle( 'entry could not be read', $name );
				}
				$bytes += strlen( $chunk );
				if ( $bytes > $this->limits['deploy_file_bytes'] ) {
					throw new ApiException( 'too_large', sprintf( 'File exceeds %d bytes', $this->limits['deploy_file_bytes'] ), 413, [ 'path' => $name ] );
				}
				hash_update( $ctx, $chunk );
				fwrite( $out, $chunk );
			}
		} finally {
			fclose( $in );
			fclose( $out );
		}
		return hash_final( $ctx );
	}

	/**
	 * Rejects absolute paths, `..`/`.` segments, backslashes, drive letters, directories and control characters.
	 */
	private static function checkEntryName( string $name ): void {
		$bad = '' === $name
			|| str_ends_with( $name, '/' )
			|| str_starts_with( $name, '/' )
			|| str_contains( $name, '\\' )
			|| str_contains( $name, ':' )
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $name )
			|| 1 !== preg_match( '//u', $name );
		if ( ! $bad ) {
			foreach ( explode( '/', $name ) as $segment ) {
				if ( '' === $segment || '.' === $segment || '..' === $segment ) {
					$bad = true;
					break;
				}
			}
		}
		if ( $bad ) {
			throw self::bundle( 'unsafe entry name (absolute, "..", backslash or directory)', $name );
		}
	}

	private static function bundle( string $message, ?string $path = null ): ApiException {
		return new ApiException( 'invalid_bundle', 'Invalid bundle: ' . $message, 422, null === $path ? [] : [ 'path' => Manifest::safePath( $path ) ] );
	}

	/**
	 * Removes a staging folder created by this class (flat: only files).
	 */
	public static function removeDir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry && is_file( $dir . DIRECTORY_SEPARATOR . $entry ) ) {
				unlink( $dir . DIRECTORY_SEPARATOR . $entry );
			}
		}
		rmdir( $dir );
	}
}
