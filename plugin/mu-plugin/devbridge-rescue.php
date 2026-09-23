<?php
/**
 * Description: Dev Bridge Rescue — out-of-band rollback for Lab591 Dev Bridge. Inert unless a request carries a valid X-DevBridge-Rescue header.
 * Version:     0.1.0
 * Author:      Lab591
 * Dev-Bridge-Rescue: lab591
 *
 * Installed (copied) by the Lab591 Dev Bridge plugin; removed when the plugin is deactivated.
 * No "Plugin Name" header on purpose: inside the plugin package it would be detected as a second
 * plugin, and after a zip upload WordPress would point the "Activate" link at this file.
 * Pure PHP: no WordPress function is used, so it works even when plugins or themes are broken.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

// Cheap exit for every normal request.
if ( ! defined( 'ABSPATH' ) || empty( $_SERVER['HTTP_X_DEVBRIDGE_RESCUE'] ) ) {
	return;
}

$devbridge_rescue_result = devbridge_rescue_handle(
	$_SERVER,
	ABSPATH,
	defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content',
	defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content' ) . '/plugins',
	defined( 'DEVBRIDGE_STORAGE_DIR' ) ? (string) DEVBRIDGE_STORAGE_DIR : ''
);
if ( null === $devbridge_rescue_result ) {
	unset( $devbridge_rescue_result );
	return;
}
http_response_code( $devbridge_rescue_result[0] );
header( 'Content-Type: application/json; charset=utf-8' );
header( 'Cache-Control: no-store' );
echo json_encode( $devbridge_rescue_result[1], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
exit;

/**
 * Handles a rescue request. Returns [status, payload], or null to stay inert.
 *
 * @param array<string, mixed> $server     $_SERVER.
 * @param string               $abspath    WordPress ABSPATH.
 * @param string               $contentDir WP_CONTENT_DIR.
 * @param string               $pluginDir  WP_PLUGIN_DIR.
 * @param string               $storageDir DEVBRIDGE_STORAGE_DIR ('' if not defined).
 * @return array{0: int, 1: array<string, mixed>}|null
 */
function devbridge_rescue_handle( array $server, string $abspath, string $contentDir, string $pluginDir, string $storageDir ): ?array {
	$token = trim( (string) ( $server['HTTP_X_DEVBRIDGE_RESCUE'] ?? '' ) );
	if ( '' === $token ) {
		return null;
	}

	// Inert if orphaned: plugin folder removed without uninstall.
	if ( ! is_file( rtrim( $pluginDir, '/\\' ) . '/lab591-dev-bridge/lab591-dev-bridge.php' ) ) {
		return null;
	}
	$storage = devbridge_rescue_storage( $contentDir, $storageDir );
	if ( null === $storage ) {
		return null;
	}
	$file = $storage . DIRECTORY_SEPARATOR . 'rescue.json';
	if ( ! is_file( $file ) || filesize( $file ) > 65536 ) {
		return null;
	}
	$data = json_decode( (string) file_get_contents( $file ), true );
	if ( ! is_array( $data ) || (int) ( $data['expires_at'] ?? 0 ) < time() ) {
		return null;
	}

	$deny = array(
		403,
		array(
			'error' => array(
				'code'    => 'rescue_denied',
				'message' => 'Rescue denied',
			),
		),
	);
	if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $token ) ) {
		return $deny;
	}
	$https = ( ! empty( $server['HTTPS'] ) && 'off' !== strtolower( (string) $server['HTTPS'] ) ) || '443' === (string) ( $server['SERVER_PORT'] ?? '' );
	if ( ! $https && empty( $data['allow_http'] ) ) {
		return $deny;
	}
	$allow = is_array( $data['ip_allowlist'] ?? null ) ? $data['ip_allowlist'] : array();
	if ( array() !== $allow ) {
		$proxies = is_array( $data['trusted_proxies'] ?? null ) ? $data['trusted_proxies'] : array();
		$ip      = devbridge_rescue_client_ip( (string) ( $server['REMOTE_ADDR'] ?? '' ), (string) ( $server['HTTP_X_FORWARDED_FOR'] ?? '' ), $proxies );
		if ( ! devbridge_rescue_ip_in( $ip, $allow ) ) {
			return $deny;
		}
	}
	if ( ! is_string( $data['token_sha256'] ?? null ) || ! hash_equals( $data['token_sha256'], hash( 'sha256', $token ) ) ) {
		return $deny;
	}

	// Single use: invalidate before touching any file.
	unlink( $file );

	$releaseId = (string) ( $data['release_id'] ?? '' );
	if ( 1 !== preg_match( '/^\d{8}-\d{6}-[0-9a-f]{6}$/', $releaseId ) ) {
		return array(
			500,
			array(
				'error' => array(
					'code'    => 'rescue_failed',
					'message' => 'Invalid release',
				),
			),
		);
	}
	$releaseDir  = $storage . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . $releaseId;
	$releaseFile = $releaseDir . DIRECTORY_SEPARATOR . 'release.json';
	$release     = is_file( $releaseFile ) ? json_decode( (string) file_get_contents( $releaseFile ), true ) : null;
	if ( ! is_array( $release ) || ( $release['id'] ?? null ) !== $releaseId || ! is_array( $release['ops'] ?? null ) ) {
		return array(
			500,
			array(
				'error' => array(
					'code'    => 'rescue_failed',
					'message' => 'Release not found',
				),
			),
		);
	}

	$base = realpath( $abspath );
	if ( false === $base ) {
		return array(
			500,
			array(
				'error' => array(
					'code'    => 'rescue_failed',
					'message' => 'Invalid site root',
				),
			),
		);
	}
	$files = array();
	foreach ( array_reverse( $release['ops'] ) as $op ) {
		$rel = is_array( $op ) ? (string) ( $op['p'] ?? '' ) : '';
		$ok  = devbridge_rescue_safe_relative( $rel );
		if ( $ok ) {
			$target = $base . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $rel );
			if ( ! empty( $op['existed'] ) ) {
				$backup = $releaseDir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $rel );
				$ok     = devbridge_rescue_inside( $target, $base ) && is_file( $backup ) && devbridge_rescue_copy( $backup, $target );
				$hash   = isset( $op['prev_h'] ) && is_string( $op['prev_h'] ) ? $op['prev_h'] : null;
			} else {
				$ok   = devbridge_rescue_inside( $target, $base ) && ( ! file_exists( $target ) || ( is_file( $target ) && ! is_link( $target ) && unlink( $target ) ) );
				$hash = null;
			}
		}
		if ( ! $ok ) {
			return array(
				500,
				array(
					'error' => array(
						'code'    => 'rescue_failed',
						'message' => 'Restore failed',
						'files'   => array_values( $files ),
					),
				),
			);
		}
		$files[ $rel ] = array(
			'p' => $rel,
			'h' => $hash,
		);
	}

	$release['status'] = 'rescued';
	file_put_contents( $releaseFile, json_encode( $release, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

	return array(
		200,
		array(
			'status'     => 'ok',
			'release_id' => $releaseId,
			'files'      => array_values( $files ),
		),
	);
}

/**
 * Storage folder: DEVBRIDGE_STORAGE_DIR, else the newest wp-content/devbridge-* holding a rescue.json.
 */
function devbridge_rescue_storage( string $contentDir, string $storageDir ): ?string {
	if ( '' !== $storageDir ) {
		return is_dir( $storageDir ) ? rtrim( $storageDir, '/\\' ) : null;
	}
	$best  = null;
	$mtime = -1;
	foreach ( (array) glob( rtrim( $contentDir, '/\\' ) . '/devbridge-*/rescue.json' ) as $candidate ) {
		$dir = dirname( (string) $candidate );
		if ( 1 === preg_match( '/devbridge-[0-9a-f]{16}$/', $dir ) && filemtime( (string) $candidate ) > $mtime ) {
			$best  = $dir;
			$mtime = (int) filemtime( (string) $candidate );
		}
	}
	return $best;
}

/**
 * Relative path from release.json: no absolute paths, no "..", no backslashes, colons or control characters.
 */
function devbridge_rescue_safe_relative( string $rel ): bool {
	if ( '' === $rel || strlen( $rel ) > 1024 || '/' === $rel[0] || str_contains( $rel, '\\' ) || str_contains( $rel, ':' ) || 1 === preg_match( '/[\x00-\x1F\x7F]/', $rel ) ) {
		return false;
	}
	foreach ( explode( '/', $rel ) as $segment ) {
		if ( '' === $segment || '.' === $segment || '..' === $segment ) {
			return false;
		}
	}
	return true;
}

/**
 * True when the nearest existing ancestor of $path resolves inside $base, and $path is not a symlink.
 */
function devbridge_rescue_inside( string $path, string $base ): bool {
	if ( is_link( $path ) ) {
		return false;
	}
	$dir = dirname( $path );
	while ( ! file_exists( $dir ) && dirname( $dir ) !== $dir ) {
		$dir = dirname( $dir );
	}
	$real = realpath( $dir );
	if ( false === $real ) {
		return false;
	}
	$prefix = rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR;
	return str_starts_with( strtolower( $real . DIRECTORY_SEPARATOR ), strtolower( $prefix ) );
}

/**
 * Atomic copy (temporary file + rename), creating missing folders; invalidates OPcache.
 */
function devbridge_rescue_copy( string $source, string $target ): bool {
	$dir = dirname( $target );
	if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
		return false;
	}
	$tmp = $dir . DIRECTORY_SEPARATOR . '.devbridge-' . bin2hex( random_bytes( 6 ) ) . '.tmp';
	if ( ! copy( $source, $tmp ) ) {
		return false;
	}
	chmod( $tmp, 0644 & ~umask() );
	if ( ! rename( $tmp, $target ) ) {
		unlink( $tmp );
		return false;
	}
	if ( function_exists( 'opcache_invalidate' ) && str_ends_with( strtolower( $target ), '.php' ) ) {
		opcache_invalidate( $target, true );
	}
	return true;
}

/**
 * REMOTE_ADDR, or the first untrusted hop of X-Forwarded-For when the peer is a trusted proxy.
 *
 * @param string[] $proxies
 */
function devbridge_rescue_client_ip( string $remote, string $forwarded, array $proxies ): string {
	$ip = trim( $remote );
	if ( '' === $forwarded || array() === $proxies || ! devbridge_rescue_ip_in( $ip, $proxies ) ) {
		return $ip;
	}
	foreach ( array_reverse( array_map( 'trim', explode( ',', $forwarded ) ) ) as $hop ) {
		if ( false === filter_var( $hop, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}
		$ip = $hop;
		if ( ! devbridge_rescue_ip_in( $hop, $proxies ) ) {
			return $hop;
		}
	}
	return $ip;
}

/**
 * IPv4/IPv6 match against a list of addresses or CIDR blocks.
 *
 * @param string[] $entries
 */
function devbridge_rescue_ip_in( string $ip, array $entries ): bool {
	$addr = filter_var( $ip, FILTER_VALIDATE_IP ) ? inet_pton( $ip ) : false;
	if ( false === $addr ) {
		return false;
	}
	foreach ( $entries as $entry ) {
		$parts = explode( '/', trim( (string) $entry ), 2 );
		$net   = filter_var( $parts[0], FILTER_VALIDATE_IP ) ? inet_pton( $parts[0] ) : false;
		if ( false === $net || strlen( $net ) !== strlen( $addr ) ) {
			continue;
		}
		$bits = isset( $parts[1] ) && ctype_digit( $parts[1] ) ? (int) $parts[1] : strlen( $net ) * 8;
		if ( $bits > strlen( $net ) * 8 ) {
			continue;
		}
		$bytes = intdiv( $bits, 8 );
		$rest  = $bits % 8;
		if ( substr( $addr, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
			continue;
		}
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		if ( ( ord( $addr[ $bytes ] ) & $mask ) === ( ord( $net[ $bytes ] ) & $mask ) ) {
			return true;
		}
	}
	return false;
}
