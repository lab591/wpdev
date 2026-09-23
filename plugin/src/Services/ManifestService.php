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
			$files[] = [
				'p' => $file->relative,
				's' => (int) filesize( $file->absolute ),
				'm' => (int) filemtime( $file->absolute ),
				'h' => (string) hash_file( 'xxh128', $file->absolute ),
			];
		}
		return [
			'root'  => $dir->relative,
			'files' => $files,
		];
	}
}
