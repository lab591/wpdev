<?php
/**
 * Zip archive of selected files or of a whole folder (`POST /archive`).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Services;

use Lab591\DevBridge\Security\Access;
use Lab591\DevBridge\Security\PathException;
use Lab591\DevBridge\Security\PathGuard;
use Lab591\DevBridge\Security\ResolvedPath;
use Lab591\DevBridge\Support\ApiException;

final class ArchiveService {

	public function __construct(
		private readonly PathGuard $guard,
		private readonly int $maxPaths,
		private readonly int $maxRootFiles,
		private readonly int $maxBytes,
		private readonly string $tempDir,
	) {
	}

	/**
	 * Builds the zip in a temporary file. The caller streams it and deletes it.
	 *
	 * @param string[]|null $paths   Explicit file list (mutually exclusive with $root).
	 * @param string[]      $exclude Glob patterns, only with $root.
	 * @return array{file: string, count: int, bytes: int}
	 */
	public function build( ?array $paths, ?string $root, array $exclude = [] ): array {
		if ( ( null === $paths ) === ( null === $root ) ) {
			throw new ApiException( 'invalid_param', 'Provide either paths or root', 400 );
		}

		/** @var ResolvedPath[] $files */
		$files = [];
		if ( null !== $paths ) {
			if ( [] === $paths ) {
				throw new ApiException( 'invalid_param', 'paths must not be empty', 400 );
			}
			if ( count( $paths ) > $this->maxPaths ) {
				throw new ApiException( 'too_many_files', sprintf( 'At most %d paths per archive', $this->maxPaths ), 413 );
			}
			$seen = [];
			foreach ( $paths as $path ) {
				$file = $this->guard->resolve( (string) $path, Access::Read );
				if ( $file->isDir ) {
					throw PathException::notAFile();
				}
				if ( ! isset( $seen[ $file->relative ] ) ) {
					$seen[ $file->relative ] = true;
					$files[]                 = $file;
				}
			}
		} else {
			$dir = $this->guard->resolve( (string) $root, Access::Read );
			if ( ! $dir->isDir ) {
				throw PathException::notADirectory();
			}
			foreach ( ( new TreeWalker( $this->guard, [], $exclude ) )->files( $dir ) as $file ) {
				if ( count( $files ) >= $this->maxRootFiles ) {
					throw new ApiException( 'too_many_files', sprintf( 'More than %d files: narrow the root or use exclude', $this->maxRootFiles ), 413 );
				}
				$files[] = $file;
			}
		}

		$total = 0;
		foreach ( $files as $file ) {
			$total += (int) filesize( $file->absolute );
			if ( $total > $this->maxBytes ) {
				throw new ApiException( 'too_large', sprintf( 'Archive would exceed %d bytes', $this->maxBytes ), 413 );
			}
		}

		$tmp = tempnam( $this->tempDir, 'dbz' );
		if ( false === $tmp ) {
			throw new ApiException( 'internal_error', 'Temporary file could not be created', 500 );
		}
		if ( [] === $files ) {
			// ZipArchive does not write empty archives: emit a bare end-of-central-directory record.
			file_put_contents( $tmp, "PK\x05\x06" . str_repeat( "\0", 18 ) );
		} else {
			$zip = new \ZipArchive();
			$ok  = true === $zip->open( $tmp, \ZipArchive::OVERWRITE );
			if ( $ok ) {
				foreach ( $files as $file ) {
					$zip->addFile( $file->absolute, $file->relative );
				}
				$ok = $zip->close();
			}
			if ( ! $ok ) {
				unlink( $tmp );
				throw new ApiException( 'internal_error', 'Archive could not be written', 500 );
			}
		}

		return [
			'file'  => $tmp,
			'count' => count( $files ),
			'bytes' => (int) filesize( $tmp ),
		];
	}
}
