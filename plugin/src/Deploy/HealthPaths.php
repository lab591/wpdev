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
	 * @param list<array{host: string, path: string}> $networkSites Sites of a multisite network: full
	 *        http(s) URLs are accepted only when host and leading path match one of them.
	 * @return list<string>
	 * @throws ApiException `invalid_param` when the value is not an acceptable list of paths.
	 */
	public static function parse( mixed $value, string $code = 'invalid_param', array $networkSites = [] ): array {
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
			if ( ! is_string( $path ) || ! ( self::isValid( $path ) || self::isNetworkUrl( $path, $networkSites ) ) ) {
				throw self::invalid(
					$code,
					[] === $networkSites
						? 'health paths must be site-relative, e.g. "/shop/" (no full URLs, "..", "#", "@" or backslashes)'
						: 'health paths must be site-relative ("/shop/") or URLs of a site of this network'
				);
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

	/**
	 * Full http(s) URL of a site of the network: same host, path below the site path, and the
	 * remaining path valid as a site-relative path. No credentials, ports or fragments.
	 *
	 * @param list<array{host: string, path: string}> $networkSites
	 */
	public static function isNetworkUrl( string $url, array $networkSites ): bool {
		if ( [] === $networkSites || strlen( $url ) > self::MAX_LENGTH + 100 || 1 !== preg_match( '#^https?://#i', $url ) ) {
			return false;
		}
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- also used without WordPress.
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}
		$host = strtolower( (string) $parts['host'] );
		$rest = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		foreach ( $networkSites as $site ) {
			$sitePath = '/' . trim( $site['path'], '/' ) . '/';
			$sitePath = '//' === $sitePath ? '/' : $sitePath;
			if ( strtolower( $site['host'] ) === $host && str_starts_with( $rest . ( str_ends_with( $rest, '/' ) ? '' : '/' ), $sitePath ) ) {
				return self::isValid( '/' . ltrim( substr( $rest, strlen( $sitePath ) - 1 ), '/' ) );
			}
		}
		return false;
	}

	private static function invalid( string $code, string $message ): ApiException {
		return new ApiException( $code, ( 'invalid_manifest' === $code ? 'Invalid manifest: ' : '' ) . $message, 400 );
	}
}
