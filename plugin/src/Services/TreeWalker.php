<?php
/**
 * Recursive file iteration below a resolved directory, re-validating every entry with PathGuard.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Services;

use Lab591\DevBridge\Security\Access;
use Lab591\DevBridge\Security\Glob;
use Lab591\DevBridge\Security\PathException;
use Lab591\DevBridge\Security\PathGuard;
use Lab591\DevBridge\Security\ResolvedPath;

final class TreeWalker {

	/**
	 * @param string[] $skipDirNames Directory names never entered (e.g. node_modules).
	 * @param string[] $exclude      Glob patterns (relative to ABSPATH) for files and folders to skip.
	 */
	public function __construct(
		private readonly PathGuard $guard,
		private readonly array $skipDirNames = [],
		private readonly array $exclude = [],
	) {
	}

	/**
	 * Yields every readable file below $dir (or $dir itself if it is a file), sorted by path.
	 * Symlinked folders are not entered; symlinked files are yielded only if they resolve inside the roots.
	 *
	 * @return \Generator<int, ResolvedPath>
	 */
	public function files( ResolvedPath $dir ): \Generator {
		if ( ! $dir->isDir ) {
			yield $dir;
			return;
		}
		yield from $this->walk( $dir->absolute, $dir->relative );
	}

	/**
	 * @return \Generator<int, ResolvedPath>
	 */
	private function walk( string $absDir, string $relDir ): \Generator {
		$names = scandir( $absDir );
		if ( false === $names ) {
			return;
		}
		sort( $names, SORT_STRING );
		$dirs = [];
		foreach ( $names as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$rel = '' === $relDir ? $name : $relDir . '/' . $name;
			$abs = $absDir . DIRECTORY_SEPARATOR . $name;
			if ( [] !== $this->exclude && Glob::matchesAny( $this->exclude, $rel ) ) {
				continue;
			}
			// Linked folders (symlinks, junctions) are never entered: no loops, no escapes.
			if ( is_dir( $abs ) && ! $this->guard->isLinkLike( $absDir, $name ) ) {
				if ( in_array( strtolower( $name ), $this->skipDirNames, true ) || $this->guard->isDenied( $rel ) ) {
					continue;
				}
				$dirs[] = [ $abs, $rel ];
				continue;
			}
			try {
				$resolved = $this->guard->resolve( $rel, Access::Read );
			} catch ( PathException ) {
				continue;
			}
			if ( $resolved->isDir ) {
				continue;
			}
			yield $resolved;
		}
		// Files of a folder first, then sub-folders: keeps output grouped and deterministic.
		foreach ( $dirs as [ $abs, $rel ] ) {
			yield from $this->walk( $abs, $rel );
		}
	}
}
