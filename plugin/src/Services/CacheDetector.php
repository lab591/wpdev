<?php
/**
 * Detection of the caches that can make Claude see stale code or pages (0.6.0).
 *
 * Detection only, no purging: purge APIs differ for every cache plugin and host, so the plugin
 * reports what it finds (site_info, deploy response, admin status) and leaves purging to the
 * user (who can hook `devbridge_deployed`). Signals are generic first — WP_CACHE with an
 * advanced-cache.php drop-in, an object-cache.php drop-in, OPcache settings — and a short list
 * of well-known plugins and managed hosts only adds names.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Services;

final class CacheDetector {

	/**
	 * Well-known plugins by folder: [name, kind]. "page": caches pages only when its
	 * advanced-cache.php drop-in is installed; "server": server/proxy cache (active whenever the
	 * plugin is); "assets": combines/minifies CSS and JS; "object": object cache backend.
	 */
	public const KNOWN_PLUGINS = [
		'wp-rocket'               => [ 'WP Rocket', 'page' ],
		'w3-total-cache'          => [ 'W3 Total Cache', 'page' ],
		'wp-super-cache'          => [ 'WP Super Cache', 'page' ],
		'wp-fastest-cache'        => [ 'WP Fastest Cache', 'page' ],
		'cache-enabler'           => [ 'Cache Enabler', 'page' ],
		'comet-cache'             => [ 'Comet Cache', 'page' ],
		'hummingbird-performance' => [ 'Hummingbird', 'page' ],
		'wp-optimize'             => [ 'WP-Optimize', 'page' ],
		'flying-press'            => [ 'FlyingPress', 'page' ],
		'swift-performance-lite'  => [ 'Swift Performance', 'page' ],
		'powered-cache'           => [ 'Powered Cache', 'page' ],
		'litespeed-cache'         => [ 'LiteSpeed Cache', 'server' ],
		'sg-cachepress'           => [ 'SiteGround Speed Optimizer', 'server' ],
		'breeze'                  => [ 'Breeze', 'server' ],
		'nitropack'               => [ 'NitroPack', 'server' ],
		'nginx-helper'            => [ 'Nginx Helper', 'server' ],
		'varnish-http-purge'      => [ 'Proxy Cache Purge', 'server' ],
		'autoptimize'             => [ 'Autoptimize', 'assets' ],
		'perfmatters'             => [ 'Perfmatters', 'assets' ],
		'redis-cache'             => [ 'Redis Object Cache', 'object' ],
	];

	/**
	 * Report from explicit facts (pure: unit-tested without WordPress).
	 *
	 * Facts: wp_cache (bool), advanced_cache / object_cache (drop-in name, "" when unnamed, null when
	 * absent or not in use), active_plugins (list of plugin files), hosts (list of names), opcache
	 * {enabled, validate_timestamps, revalidate_freq, restrict_api}, self_path, development_mode.
	 *
	 * @param array<string, mixed> $facts See above.
	 * @return array{
	 *     page: list<string>,
	 *     object: ?string,
	 *     assets: list<string>,
	 *     opcache: string,
	 *     opcache_stale_s: ?int,
	 *     development_mode: string
	 * } `opcache`: off | ok | restricted; `opcache_stale_s`: how long PHP changes may stay invisible
	 *   when invalidation is not allowed (-1: until PHP restarts), null when not an issue.
	 */
	public static function analyze( array $facts ): array {
		$page    = [];
		$assets  = [];
		$known   = null;
		$names   = [];
		$folders = [];
		foreach ( $facts['active_plugins'] as $file ) {
			$folders[ explode( '/', str_replace( '\\', '/', $file ), 2 )[0] ] = true;
		}
		$pageDropin = $facts['wp_cache'] && null !== $facts['advanced_cache'];
		foreach ( self::KNOWN_PLUGINS as $folder => [ $name, $kind ] ) {
			if ( ! isset( $folders[ $folder ] ) ) {
				continue;
			}
			if ( 'server' === $kind || ( 'page' === $kind && $pageDropin ) ) {
				$page[]         = $name;
				$names[ $name ] = true;
			} elseif ( 'assets' === $kind ) {
				$assets[] = $name;
			} elseif ( 'object' === $kind ) {
				$known = $name;
			}
		}
		if ( $pageDropin && [] === $page ) {
			// Unknown plugin: the drop-in itself is the evidence.
			$page[] = '' !== (string) $facts['advanced_cache'] ? $facts['advanced_cache'] . ' (advanced-cache.php)' : 'advanced-cache.php';
		}
		foreach ( $facts['hosts'] as $host ) {
			if ( ! isset( $names[ $host ] ) ) {
				$page[] = $host;
			}
		}
		// An object cache plugin counts only when its drop-in is actually in use.
		$object = null === $facts['object_cache'] ? null : ( '' !== $facts['object_cache'] ? $facts['object_cache'] : ( $known ?? 'object-cache.php' ) );

		$op      = $facts['opcache'];
		$opcache = 'off';
		$stale   = null;
		if ( $op['enabled'] ) {
			$restricted = '' !== $op['restrict_api'] && ! str_starts_with( $facts['self_path'], $op['restrict_api'] );
			$opcache    = $restricted ? 'restricted' : 'ok';
			if ( $restricted && ( ! $op['validate_timestamps'] || $op['revalidate_freq'] > 0 ) ) {
				$stale = $op['validate_timestamps'] ? $op['revalidate_freq'] : -1;
			}
		}

		return [
			'page'             => $page,
			'object'           => $object,
			'assets'           => $assets,
			'opcache'          => $opcache,
			'opcache_stale_s'  => $stale,
			'development_mode' => $facts['development_mode'],
		];
	}

	/**
	 * What the agent must know right after a deploy, or null when nothing can hide the change.
	 *
	 * @param array<string, mixed> $report From analyze().
	 * @return array{page: list<string>, assets: list<string>, opcache_stale_s: ?int}|null
	 */
	public static function notice( array $report ): ?array {
		$page   = array_values( (array) ( $report['page'] ?? [] ) );
		$assets = array_values( (array) ( $report['assets'] ?? [] ) );
		$stale  = $report['opcache_stale_s'] ?? null;
		if ( [] === $page && [] === $assets && null === $stale ) {
			return null;
		}
		return [
			'page'            => $page,
			'assets'          => $assets,
			'opcache_stale_s' => null === $stale ? null : (int) $stale,
		];
	}

	/**
	 * Report of the running site.
	 *
	 * @return array<string, mixed> See analyze().
	 */
	public function report(): array {
		if ( ! function_exists( 'get_dropins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$dropins = get_dropins();
		$network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) : [];
		$active  = array_values( array_unique( array_merge( array_map( 'strval', (array) get_option( 'active_plugins', [] ) ), array_map( 'strval', $network ) ) ) );

		return self::analyze(
			[
				'wp_cache'         => defined( 'WP_CACHE' ) && (bool) WP_CACHE,
				'advanced_cache'   => isset( $dropins['advanced-cache.php'] ) ? (string) ( $dropins['advanced-cache.php']['Name'] ?? '' ) : null,
				'object_cache'     => wp_using_ext_object_cache() ? ( isset( $dropins['object-cache.php'] ) ? (string) ( $dropins['object-cache.php']['Name'] ?? '' ) : '' ) : null,
				'active_plugins'   => $active,
				'hosts'            => self::hosts(),
				'opcache'          => [
					'enabled'             => function_exists( 'opcache_invalidate' ) && filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN ),
					'validate_timestamps' => filter_var( ini_get( 'opcache.validate_timestamps' ), FILTER_VALIDATE_BOOLEAN ),
					'revalidate_freq'     => (int) ini_get( 'opcache.revalidate_freq' ),
					'restrict_api'        => (string) ini_get( 'opcache.restrict_api' ),
				],
				'self_path'        => __FILE__,
				'development_mode' => function_exists( 'wp_get_development_mode' ) ? wp_get_development_mode() : '',
			]
		);
	}

	/**
	 * Managed hosts with a built-in page cache, recognized by the constants/classes of their mu-plugins.
	 *
	 * @return list<string>
	 */
	private static function hosts(): array {
		$hosts = [];
		if ( class_exists( 'WpeCommon', false ) ) {
			$hosts[] = 'WP Engine';
		}
		if ( defined( 'KINSTAMU_VERSION' ) ) {
			$hosts[] = 'Kinsta';
		}
		if ( defined( 'PANTHEON_ENVIRONMENT' ) ) {
			$hosts[] = 'Pantheon';
		}
		if ( class_exists( '\WPaaS\Plugin', false ) ) {
			$hosts[] = 'GoDaddy';
		}
		if ( defined( 'IS_PRESSABLE' ) ) {
			$hosts[] = 'Pressable';
		}
		if ( defined( 'WPCOMSH_VERSION' ) ) {
			$hosts[] = 'WordPress.com';
		}
		return $hosts;
	}

	/**
	 * Evidence that an HTTP response came from a cache (proxy, CDN or server cache), or null.
	 * Plugin caches served by advanced-cache.php send no standard header: the random query
	 * argument of the health check already bypasses them.
	 *
	 * @param iterable<string, string|string[]> $headers Response headers (any case).
	 */
	public static function hitEvidence( iterable $headers ): ?string {
		$hitHeaders = [ 'x-litespeed-cache', 'x-cache', 'x-cache-status', 'x-proxy-cache', 'x-fastcgi-cache', 'x-nginx-cache', 'x-srcache-fetch-status', 'x-sucuri-cache', 'x-kinsta-cache', 'x-varnish-cache', 'x-wpe-cached' ];
		foreach ( $headers as $name => $value ) {
			$name  = strtolower( (string) $name );
			$value = trim( is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value );
			$hit   = match ( true ) {
				in_array( $name, $hitHeaders, true ) => 1 === preg_match( '/\bhit\b/i', $value ),
				'cf-cache-status' === $name          => 1 === preg_match( '/^(hit|stale|updating|revalidated)$/i', $value ),
				'x-varnish' === $name                => 1 === preg_match( '/^\d+\s+\d+$/', $value ),
				'age' === $name                      => ctype_digit( $value ) && (int) $value > 0,
				default                              => false,
			};
			if ( $hit ) {
				return $name . ': ' . substr( $value, 0, 60 );
			}
		}
		return null;
	}
}
