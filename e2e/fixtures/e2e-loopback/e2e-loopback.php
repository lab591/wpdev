<?php
/**
 * Plugin Name: E2E loopback (test only)
 * Description: In wp-env the site answers on localhost:8888 from the host, but inside the container
 *              Apache listens on port 80. Loopback requests (Dev Bridge health checks) are rewritten
 *              to port 80 keeping the Host header, as on a normal server. Never use outside tests.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

add_filter(
	'pre_http_request',
	static function ( $pre, array $args, string $url ) {
		static $busy = false;
		$parts = wp_parse_url( $url );
		if ( $busy || 'localhost' !== ( $parts['host'] ?? '' ) || 8888 !== (int) ( $parts['port'] ?? 80 ) ) {
			return $pre;
		}
		$busy                     = true;
		$args['headers']          = (array) ( $args['headers'] ?? [] );
		$args['headers']['Host']  = 'localhost:8888';
		$args['reject_unsafe_urls'] = false;
		$response                 = wp_remote_request( str_replace( '//localhost:8888', '//localhost:80', $url ), $args );
		$busy                     = false;
		return $response;
	},
	10,
	3
);
