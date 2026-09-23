<?php
/**
 * PathGuard: the single entry point for every externally supplied path (SPEC 2.5).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Security;

final class PathGuard {

	public const MAX_LENGTH = 1024;

	private string $base;
	/** @var string[]|null */
	private ?array $readRootsAbs = null;
	/** @var string[]|null */
	private ?array $writableRootsAbs = null;
	/** @var string[]|null */
	private ?array $protectedAbs = null;

	public function __construct( private readonly PathPolicy $policy ) {
		$base = realpath( $policy->abspath );
		if ( false === $base || ! is_dir( $base ) ) {
			throw new \RuntimeException( 'ABSPATH is not a directory' );
		}
		$this->base = rtrim( $base, '/\\' );
	}

	public function policy(): PathPolicy {
		return $this->policy;
	}

	/**
	 * Validates and resolves a path relative to ABSPATH.
	 *
	 * @throws PathException When the path is malformed, denied or missing.
	 */
	public function resolve( string $relPath, Access $access ): ResolvedPath {
		$segments  = $this->parse( $relPath, $access );
		$requested = implode( '/', $segments );

		// Early deny check on the requested form: avoids existence oracles on denied files.
		if ( Glob::matchesAnyWithAncestors( $this->policy->denyPatterns, $requested ) ) {
			throw PathException::denied();
		}

		[ $absolute, $exists ] = $this->locate( $segments, $access );
		$relative              = $this->relativeToBase( $absolute );
		$root                  = $this->matchRoot( $absolute, $access );

		foreach ( $this->protectedAbs() as $protected ) {
			if ( $this->samePath( $absolute, $protected ) || $this->isInside( $absolute, $protected ) ) {
				throw PathException::denied();
			}
		}

		if ( Glob::matchesAnyWithAncestors( $this->policy->denyPatterns, $relative ) ) {
			throw PathException::denied();
		}

		$isDir = $exists && is_dir( $absolute );
		if ( $access->isWrite() ) {
			if ( $isDir ) {
				throw PathException::notAFile();
			}
			$this->checkWriteName( substr( $absolute, strlen( $root ) + 1 ) );
		}

		return new ResolvedPath( $absolute, $relative, $exists, $isDir );
	}

	/**
	 * Converts a native absolute path already known to be safe (e.g. a child found while
	 * iterating a resolved directory) into a ResolvedPath, re-running every check.
	 */
	public function resolveChild( ResolvedPath $dir, string $name, Access $access = Access::Read ): ResolvedPath {
		$rel = '' === $dir->relative ? $name : $dir->relative . '/' . $name;
		return $this->resolve( $rel, $access );
	}

	/**
	 * True when the relative path (canonical, `/`) is matched by the deny list.
	 */
	public function isDenied( string $relative ): bool {
		return Glob::matchesAnyWithAncestors( $this->policy->denyPatterns, $relative );
	}

	/**
	 * Absolute paths of the configured roots that currently exist and are valid.
	 *
	 * @return string[]
	 */
	public function writableRootsAbsolute(): array {
		if ( null === $this->writableRootsAbs ) {
			$this->writableRootsAbs = $this->resolveRoots( $this->policy->writableRoots, false );
		}
		return $this->writableRootsAbs;
	}

	/**
	 * True when $name inside the (already resolved) $parentAbsolute is a symlink, a Windows junction
	 * or anything else whose real path is not literally parent/name. is_link() alone misses junctions.
	 */
	public function isLinkLike( string $parentAbsolute, string $name ): bool {
		$path = rtrim( $parentAbsolute, '/\\' ) . DIRECTORY_SEPARATOR . $name;
		if ( is_link( $path ) ) {
			return true;
		}
		$real = realpath( $path );
		return false === $real || ! $this->samePath( $real, $path );
	}

	/**
	 * Relative form (with `/`) of an absolute path reported by an external tool, or null when it is
	 * outside ABSPATH. The result is NOT validated: callers must pass it to resolve().
	 */
	public function relativize( string $absolute ): ?string {
		try {
			$relative = $this->relativeToBase( rtrim( $absolute, '/\\' ) );
		} catch ( PathException ) {
			return null;
		}
		return '' === $relative ? null : $relative;
	}

	/**
	 * The writable root containing an absolute path already resolved by this guard, or null.
	 */
	public function writableRootFor( string $absolute ): ?string {
		foreach ( $this->writableRootsAbsolute() as $root ) {
			if ( $this->isInside( $absolute, $root ) ) {
				return $root;
			}
		}
		return null;
	}

	// ------------------------------------------------------------------ internals

	/**
	 * Steps 1–2: syntactic validation and strict normalization.
	 *
	 * @return string[] Path segments.
	 */
	private function parse( string $path, Access $access ): array {
		if ( '' === $path ) {
			throw PathException::invalid( 'empty path' );
		}
		if ( strlen( $path ) > self::MAX_LENGTH ) {
			throw PathException::invalid( 'path too long' );
		}
		if ( 1 !== preg_match( '//u', $path ) ) {
			throw PathException::invalid( 'path is not valid UTF-8' );
		}
		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $path ) ) {
			throw PathException::invalid( 'control characters are not allowed' );
		}
		if ( str_contains( $path, '://' ) ) {
			throw PathException::invalid( 'stream wrappers are not allowed' );
		}
		if ( '/' === $path[0] || '\\' === $path[0] || 1 === preg_match( '/^[A-Za-z]:/', $path ) ) {
			throw PathException::invalid( 'absolute paths are not allowed' );
		}
		if ( str_contains( $path, ':' ) ) {
			throw PathException::invalid( 'colons are not allowed' );
		}
		if ( '.' === $path ) {
			if ( $access->isWrite() ) {
				throw PathException::invalid( 'the site root is not a file' );
			}
			return [];
		}

		$path = str_replace( '\\', '/', $path );
		if ( str_ends_with( $path, '/' ) ) {
			$path = substr( $path, 0, -1 );
		}
		$segments = explode( '/', $path );
		foreach ( $segments as $segment ) {
			if ( '' === $segment ) {
				throw PathException::invalid( 'empty path segment' );
			}
			if ( '.' === $segment || '..' === $segment ) {
				throw PathException::invalid( 'relative segments are not allowed' );
			}
			// Windows silently strips trailing dots and spaces ("x.php." === "x.php", "...." === "").
			if ( str_ends_with( $segment, '.' ) || str_ends_with( $segment, ' ' ) ) {
				throw PathException::invalid( 'segments ending with a dot or a space are not allowed' );
			}
			// DOS 8.3 short names (e.g. "WP-CON~1.PHP") could alias protected files.
			if ( 1 === preg_match( '/~\d/', $segment ) ) {
				throw PathException::invalid( 'short file names are not allowed' );
			}
		}
		return $segments;
	}

	/**
	 * Step 3 and 5: filesystem resolution with realpath().
	 *
	 * @param string[] $segments
	 * @return array{0: string, 1: bool} Absolute path and existence flag.
	 */
	private function locate( array $segments, Access $access ): array {
		if ( [] === $segments ) {
			return [ $this->base, true ];
		}
		$joined = $this->base . DIRECTORY_SEPARATOR . implode( DIRECTORY_SEPARATOR, $segments );

		if ( $access->isWrite() && is_link( $joined ) ) {
			throw PathException::denied( 'symbolic links cannot be written' );
		}

		$real = realpath( $joined );
		if ( false !== $real ) {
			if ( $access->isWrite() && is_link( $real ) ) {
				throw PathException::denied( 'symbolic links cannot be written' );
			}
			return [ $real, true ];
		}

		if ( Access::WriteNew !== $access ) {
			throw PathException::notFound();
		}

		// Nearest existing ancestor, then re-append the (already validated) missing segments.
		for ( $k = count( $segments ) - 1; $k >= 0; $k-- ) {
			$prefix = $this->base . ( $k > 0 ? DIRECTORY_SEPARATOR . implode( DIRECTORY_SEPARATOR, array_slice( $segments, 0, $k ) ) : '' );
			if ( is_link( $prefix ) && ! file_exists( $prefix ) ) {
				throw PathException::denied( 'dangling symbolic link' );
			}
			$realPrefix = realpath( $prefix );
			if ( false === $realPrefix ) {
				continue;
			}
			if ( ! is_dir( $realPrefix ) ) {
				throw PathException::notADirectory();
			}
			$missing = array_slice( $segments, $k );
			return [ rtrim( $realPrefix, '/\\' ) . DIRECTORY_SEPARATOR . implode( DIRECTORY_SEPARATOR, $missing ), false ];
		}
		throw PathException::notFound();
	}

	private function relativeToBase( string $absolute ): string {
		if ( $this->samePath( $absolute, $this->base ) ) {
			return '';
		}
		if ( ! $this->isInside( $absolute, $this->base ) ) {
			throw PathException::denied( 'outside the site' );
		}
		return str_replace( DIRECTORY_SEPARATOR, '/', substr( $absolute, strlen( $this->base ) + 1 ) );
	}

	/**
	 * Step 4: the resolved path must be inside an allowed root. Returns the matching root.
	 */
	private function matchRoot( string $absolute, Access $access ): string {
		if ( $access->isWrite() ) {
			foreach ( $this->writableRootsAbsolute() as $root ) {
				if ( $this->isInside( $absolute, $root ) ) {
					return $root;
				}
			}
			throw PathException::denied( 'outside the writable folders' );
		}
		if ( null === $this->readRootsAbs ) {
			$this->readRootsAbs = $this->resolveRoots( $this->policy->readRoots, true );
		}
		foreach ( $this->readRootsAbs as $root ) {
			if ( $this->samePath( $absolute, $root ) || $this->isInside( $absolute, $root ) ) {
				return $root;
			}
		}
		throw PathException::denied( 'outside the readable folders' );
	}

	/**
	 * @param string[] $roots
	 * @return string[]
	 */
	private function resolveRoots( array $roots, bool $allowBase ): array {
		$out = [];
		foreach ( $roots as $root ) {
			$root = trim( str_replace( '\\', '/', $root ), '/' );
			if ( '' === $root || '.' === $root ) {
				if ( $allowBase ) {
					$out[] = $this->base;
				}
				continue;
			}
			try {
				$segments = $this->parse( $root, Access::Read );
			} catch ( PathException ) {
				continue;
			}
			$real = realpath( $this->base . DIRECTORY_SEPARATOR . implode( DIRECTORY_SEPARATOR, $segments ) );
			if ( false === $real || ! is_dir( $real ) || ! $this->isInside( $real, $this->base ) ) {
				continue;
			}
			if ( ! $allowBase ) {
				$clash = false;
				foreach ( $this->protectedAbs() as $protected ) {
					if ( $this->samePath( $real, $protected ) || $this->isInside( $real, $protected ) || $this->isInside( $protected, $real ) ) {
						$clash = true;
						break;
					}
				}
				if ( $clash ) {
					continue;
				}
			}
			$out[] = rtrim( $real, '/\\' );
		}
		return $out;
	}

	/**
	 * @return string[]
	 */
	private function protectedAbs(): array {
		if ( null === $this->protectedAbs ) {
			$this->protectedAbs = [];
			foreach ( $this->policy->protectedPaths as $path ) {
				if ( '' === $path ) {
					continue;
				}
				$real                 = realpath( $path );
				$this->protectedAbs[] = rtrim( false === $real ? $path : $real, '/\\' );
			}
		}
		return $this->protectedAbs;
	}

	/**
	 * Step 6 (write part): name and extension rules, applied to every segment below the root.
	 */
	private function checkWriteName( string $withinRoot ): void {
		$segments = explode( '/', str_replace( DIRECTORY_SEPARATOR, '/', $withinRoot ) );
		$name     = (string) array_pop( $segments );
		foreach ( $segments as $dir ) {
			if ( str_starts_with( $dir, '.' ) ) {
				throw PathException::extension( 'hidden folders are not allowed' );
			}
		}

		$lower = strtolower( $name );
		if ( '.gitkeep' === $lower ) {
			return;
		}
		if ( in_array( $lower, PathPolicy::FORBIDDEN_NAMES, true ) ) {
			throw PathException::extension( 'server configuration files are not allowed' );
		}
		if ( str_starts_with( $name, '.' ) ) {
			throw PathException::extension( 'hidden files are not allowed' );
		}
		$parts = explode( '.', $lower );
		if ( count( $parts ) < 2 ) {
			throw PathException::extension( 'files without extension are not allowed' );
		}
		$extension = (string) array_pop( $parts );
		array_shift( $parts );
		foreach ( array_merge( $parts, [ $extension ] ) as $part ) {
			if ( in_array( $part, PathPolicy::EXECUTABLE_EXTENSIONS, true ) ) {
				throw PathException::extension( 'executable extension .' . $part );
			}
		}
		if ( in_array( 'php', $parts, true ) ) {
			throw PathException::extension( 'double extension with .php' );
		}
		if ( ! in_array( $extension, $this->policy->writeExtensions, true ) ) {
			throw PathException::extension( 'extension .' . $extension . ' is not in the allowlist' );
		}
	}

	private function isInside( string $path, string $root ): bool {
		$prefix = rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR;
		if ( $this->policy->caseInsensitive ) {
			return str_starts_with( self::lower( $path ), self::lower( $prefix ) );
		}
		return str_starts_with( $path, $prefix );
	}

	private function samePath( string $a, string $b ): bool {
		$a = rtrim( $a, '/\\' );
		$b = rtrim( $b, '/\\' );
		return $this->policy->caseInsensitive ? self::lower( $a ) === self::lower( $b ) : $a === $b;
	}

	private static function lower( string $s ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}
}
