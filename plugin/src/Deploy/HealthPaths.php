<?php
/**
 * Health check paths declared by the agent for a single deploy or check.
 *
 * They are never stored on the server (settings are changed only by an administrator) and are
 * checked in addition to the configured health URLs. Only same-site paths are accepted: the
 * server builds the URL with home_url(), so a path can never point to another host.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Deploy;

use Lab591\DevBridge\Support\ApiException;

final class HealthPaths {

	public const MAX_PATHS  = 10;
	public const MAX_LENGTH = 200;

	/**
	 * @return list<string>
	 * @throws ApiException `invalid_param` when the value is not an acceptable list of paths.
	 */
	public static function parse( mixed $value, string $code = 'invalid_param' ): array {
		if ( null === $value ) {
			return [];
		}
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			throw self::invalid( $code, 'health paths must be a list' );
		}
		if ( count( $value ) > self::MAX_PATHS ) {
			throw self::invalid( $code, sprintf( 'at most %d health paths', self::MAX_PATHS ) );
		}
		$out = [];
		foreach ( $value as $path ) {
			if ( ! is_string( $path ) || ! self::isValid( $path ) ) {
				throw self::invalid( $code, 'health paths must be site-relative, e.g. "/shop/" (no full URLs, "..", "#", "@" or backslashes)' );
			}
			$out[ $path ] = true;
		}
		return array_keys( $out );
	}

	private static function isValid( string $path ): bool {
		if ( '' === $path || strlen( $path ) > self::MAX_LENGTH || '/' !== $path[0] || str_starts_with( $path, '//' ) ) {
			return false;
		}
		if ( 1 === preg_match( '/[\x00-\x20\x7F\\\\#@]/', $path ) || 1 !== preg_match( '//u', $path ) ) {
			return false;
		}
		$route = rawurldecode( (string) strtok( $path, '?' ) );
		foreach ( explode( '/', $route ) as $segment ) {
			if ( '..' === $segment || '.' === $segment ) {
				return false;
			}
		}
		return ! str_contains( $route, '://' );
	}

	private static function invalid( string $code, string $message ): ApiException {
		return new ApiException( $code, ( 'invalid_manifest' === $code ? 'Invalid manifest: ' : '' ) . $message, 400 );
	}
}
