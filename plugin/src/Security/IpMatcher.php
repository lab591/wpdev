<?php
/**
 * IPv4/IPv6 address and CIDR matching.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Security;

final class IpMatcher {

	/**
	 * True if the entry is a valid IP or CIDR block.
	 */
	public static function isValidEntry( string $entry ): bool {
		return null !== self::parse( $entry );
	}

	/**
	 * @param string[] $entries IPs or CIDR blocks.
	 */
	public static function matchesAny( string $ip, array $entries ): bool {
		foreach ( $entries as $entry ) {
			if ( self::matches( $ip, (string) $entry ) ) {
				return true;
			}
		}
		return false;
	}

	public static function matches( string $ip, string $entry ): bool {
		$addr   = self::packed( $ip );
		$parsed = self::parse( $entry );
		if ( null === $addr || null === $parsed ) {
			return false;
		}
		[ $net, $bits ] = $parsed;
		if ( strlen( $addr ) !== strlen( $net ) ) {
			return false;
		}
		$bytes = intdiv( $bits, 8 );
		if ( substr( $addr, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		return ( ord( $addr[ $bytes ] ) & $mask ) === ( ord( $net[ $bytes ] ) & $mask );
	}

	/**
	 * @return array{0: string, 1: int}|null Packed network address and prefix length.
	 */
	private static function parse( string $entry ): ?array {
		$entry = trim( $entry );
		$bits  = null;
		if ( str_contains( $entry, '/' ) ) {
			[ $entry, $prefix ] = explode( '/', $entry, 2 );
			if ( 1 !== preg_match( '/^\d{1,3}$/', $prefix ) ) {
				return null;
			}
			$bits = (int) $prefix;
		}
		$packed = self::packed( $entry );
		if ( null === $packed ) {
			return null;
		}
		$max  = strlen( $packed ) * 8;
		$bits = $bits ?? $max;
		if ( $bits > $max ) {
			return null;
		}
		return [ $packed, $bits ];
	}

	private static function packed( string $ip ): ?string {
		$ip = trim( $ip );
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}
		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return null;
		}
		// Normalize IPv4-mapped IPv6 (::ffff:1.2.3.4) to IPv4.
		if ( 16 === strlen( $packed ) && str_starts_with( $packed, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
			return substr( $packed, 12 );
		}
		return $packed;
	}
}
