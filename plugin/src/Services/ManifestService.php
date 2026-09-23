<?php
/**
 * File manifest with xxh128 hashes (`POST /manifest`).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Services;

use Lab591\DevBridge\Security\Access;
use Lab591\DevBridge\Security\PathException;
use Lab591\DevBridge\Security\PathGuard;
use Lab591\DevBridge\Support\ApiException;

final class ManifestService {

	public function __construct(
		private readonly PathGuard $guard,
		private readonly int $maxFiles,
		private readonly ?HashCache $hashes = null,
	) {
	}

	/**
	 * @param string[] $exclude Glob patterns relative to ABSPATH.
	 * @return array{root: string, files: list<array{p: string, s: int, m: int, h: string}>}
	 */
	public function manifest( string $root, array $exclude = [] ): array {
		$dir = $this->guard->resolve( $root, Access::Read );
		if ( ! $dir->isDir ) {
			throw PathException::notADirectory();
		}
		$files  = [];
		$walker = new TreeWalker( $this->guard, [], $exclude );
		foreach ( $walker->files( $dir ) as $file ) {
			if ( count( $files ) >= $this->maxFiles ) {
				throw new ApiException(
					'too_many_files',
					sprintf( 'More than %d files: narrow the root or use exclude', $this->maxFiles ),
					413
				);
			}
			clearstatcache( true, $file->absolute );
			$size    = (int) filesize( $file->absolute );
			$mtime   = (int) filemtime( $file->absolute );
			$files[] = [
				'p' => $file->relative,
				's' => $size,
				'm' => $mtime,
				'h' => null === $this->hashes ? (string) hash_file( 'xxh128', $file->absolute ) : $this->hashes->hash( $file->absolute, $file->relative, $size, $mtime ),
			];
		}
		return [
			'root'  => $dir->relative,
			'files' => $files,
		];
	}
}
