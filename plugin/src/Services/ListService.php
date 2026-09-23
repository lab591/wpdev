<?php
/**
 * Directory listing (`POST /list`).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Services;

use Lab591\DevBridge\Security\Access;
use Lab591\DevBridge\Security\PathException;
use Lab591\DevBridge\Security\PathGuard;
use Lab591\DevBridge\Security\ResolvedPath;

final class ListService {

	public const MAX_DEPTH   = 3;
	public const MAX_ENTRIES = 500;

	private int $count      = 0;
	private bool $truncated = false;

	public function __construct( private readonly PathGuard $guard ) {
	}

	/**
	 * @return array{path: string, entries: list<array{p: string, t: string, s: int, m: int}>, truncated: bool}
	 */
	public function list( string $path, int $depth = 1, int $maxEntries = self::MAX_ENTRIES ): array {
		$depth      = max( 1, min( self::MAX_DEPTH, $depth ) );
		$maxEntries = max( 1, min( self::MAX_ENTRIES, $maxEntries ) );
		$dir        = $this->guard->resolve( $path, Access::Read );
		if ( ! $dir->isDir ) {
			throw PathException::notADirectory();
		}
		$this->count     = 0;
		$this->truncated = false;
		$entries         = [];
		$this->collect( $dir, $depth, $maxEntries, $entries );
		return [
			'path'      => $dir->relative,
			'entries'   => $entries,
			'truncated' => $this->truncated,
		];
	}

	/**
	 * @param list<array{p: string, t: string, s: int, m: int}> $entries
	 */
	private function collect( ResolvedPath $dir, int $depth, int $max, array &$entries ): void {
		$names = scandir( $dir->absolute );
		if ( false === $names ) {
			return;
		}
		sort( $names, SORT_STRING );
		foreach ( $names as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			try {
				$child = $this->guard->resolveChild( $dir, $name );
			} catch ( PathException ) {
				continue;
			}
			if ( $this->count >= $max ) {
				$this->truncated = true;
				return;
			}
			$stat      = stat( $child->absolute );
			$entries[] = [
				'p' => $child->relative,
				't' => $child->isDir ? 'd' : 'f',
				's' => $child->isDir || false === $stat ? 0 : (int) $stat['size'],
				'm' => false === $stat ? 0 : (int) $stat['mtime'],
			];
			++$this->count;
			// Symlinked folders are listed but never entered.
			if ( $child->isDir && $depth > 1 && ! is_link( $dir->absolute . DIRECTORY_SEPARATOR . $name ) ) {
				$this->collect( $child, $depth - 1, $max, $entries );
				if ( $this->truncated ) {
					return;
				}
			}
		}
	}
}
