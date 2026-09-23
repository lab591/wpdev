<?php
/**
 * Minimal glob matcher shared by deny list, grep and manifest filters.
 *
 * Syntax (see docs/protocol.md): `*` any run without `/`, `?` one char except `/`,
 * `**` + `/` zero or more directories, trailing `/**` the directory and all its content.
 * A pattern without `/` is matched against the last path segment only.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Security;

final class Glob {

	/** @var array<string, string> */
	private static array $cache = [];

	public static function toRegex( string $pattern, bool $caseInsensitive = true ): string {
		$key = ( $caseInsensitive ? 'i' : 's' ) . $pattern;
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}
		$pattern = ltrim( str_replace( '\\', '/', $pattern ), '/' );
		$out     = '';
		$len     = strlen( $pattern );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $pattern[ $i ];
			if ( '*' === $c ) {
				if ( $i + 1 < $len && '*' === $pattern[ $i + 1 ] ) {
					$atStart = ( 0 === $i || '/' === $pattern[ $i - 1 ] );
					if ( $atStart && $i + 2 < $len && '/' === $pattern[ $i + 2 ] ) {
						$out .= '(?:.*/)?';
						$i   += 2;
						continue;
					}
					if ( $i + 2 === $len && $i > 0 && '/' === $pattern[ $i - 1 ] ) {
						// Trailing "/**": also match the directory itself.
						$out = substr( $out, 0, -1 ) . '(?:/.*)?';
						++$i;
						continue;
					}
					$out .= '.*';
					++$i;
					continue;
				}
				$out .= '[^/]*';
				continue;
			}
			if ( '?' === $c ) {
				$out .= '[^/]';
				continue;
			}
			$out .= preg_quote( $c, '#' );
		}
		$regex               = '#^' . $out . '$#' . ( $caseInsensitive ? 'i' : '' ) . 'u';
		self::$cache[ $key ] = $regex;
		return $regex;
	}

	/**
	 * Matches a relative path (with `/`) against a single pattern.
	 */
	public static function match( string $pattern, string $path, bool $caseInsensitive = true ): bool {
		$subject = $path;
		if ( ! str_contains( trim( str_replace( '\\', '/', $pattern ), '/' ), '/' ) ) {
			$pos     = strrpos( $path, '/' );
			$subject = false === $pos ? $path : substr( $path, $pos + 1 );
		}
		return 1 === preg_match( self::toRegex( $pattern, $caseInsensitive ), $subject );
	}

	/**
	 * True when the path or any of its ancestors matches one of the patterns.
	 *
	 * @param string[] $patterns
	 */
	public static function matchesAnyWithAncestors( array $patterns, string $path, bool $caseInsensitive = true ): bool {
		if ( '' === $path || [] === $patterns ) {
			return false;
		}
		$segments = explode( '/', $path );
		$prefix   = '';
		foreach ( $segments as $segment ) {
			$prefix = '' === $prefix ? $segment : $prefix . '/' . $segment;
			foreach ( $patterns as $pattern ) {
				if ( self::match( $pattern, $prefix, $caseInsensitive ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @param string[] $patterns
	 */
	public static function matchesAny( array $patterns, string $path, bool $caseInsensitive = true ): bool {
		foreach ( $patterns as $pattern ) {
			if ( self::match( $pattern, $path, $caseInsensitive ) ) {
				return true;
			}
		}
		return false;
	}
}
