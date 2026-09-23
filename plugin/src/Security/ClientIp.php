<?php
/**
 * Client IP resolution: REMOTE_ADDR, or X-Forwarded-For only behind trusted proxies.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Security;

final class ClientIp {

	/**
	 * @param string   $remoteAddr     The REMOTE_ADDR value.
	 * @param string   $forwardedFor   The X-Forwarded-For header ('' if absent).
	 * @param string[] $trustedProxies IPs/CIDRs of trusted reverse proxies.
	 */
	public static function resolve( string $remoteAddr, string $forwardedFor, array $trustedProxies ): string {
		$ip = trim( $remoteAddr );
		if ( '' === $forwardedFor || [] === $trustedProxies || ! IpMatcher::matchesAny( $ip, $trustedProxies ) ) {
			return $ip;
		}
		// Walk the chain from the right, skipping trusted proxies: the first untrusted hop is the client.
		$hops = array_reverse( array_map( 'trim', explode( ',', $forwardedFor ) ) );
		foreach ( $hops as $hop ) {
			if ( false === filter_var( $hop, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
			$ip = $hop;
			if ( ! IpMatcher::matchesAny( $hop, $trustedProxies ) ) {
				return $hop;
			}
		}
		return $ip;
	}
}
