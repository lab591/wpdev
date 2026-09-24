<?php
/**
 * Description: Dev Bridge Preview — shows the preview copies of themes and plugins only to requests with a valid preview cookie. Inert otherwise.
 * Version:     0.6.0
 * Author:      Lab591
 * Dev-Bridge-Preview: lab591
 *
 * Installed (copied) by the Lab591 Dev Bridge plugin; removed when the plugin is deactivated.
 * No "Plugin Name" header on purpose (see devbridge-rescue.php).
 *
 * Flow: `/?devbridge_preview=<token>` sets the cookie and redirects to the clean URL; requests with
 * the cookie get the `<folder>--devbridge-preview` copies instead of the live theme/plugins, are
 * never cached and carry a visible "Preview" label. The token is checked against the sha256 in
 * preview.json (private storage) with hash_equals; an expired or missing preview disables it.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

// Cheap exit for every normal request.
if ( ! defined( 'ABSPATH' ) || ( empty( $_COOKIE['wordpress_devbridge_preview'] ) && empty( $_GET['devbridge_preview'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token-authenticated, read-only switch.
	return;
}

devbridge_preview_boot();

/**
 * Validates the token and installs the switch for this request.
 */
function devbridge_preview_boot(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- the token itself is the credential, compared as a hash.
	$from_link = isset( $_GET['devbridge_preview'] ) && is_string( $_GET['devbridge_preview'] );
	$token     = $from_link ? (string) $_GET['devbridge_preview'] : (string) ( $_COOKIE['wordpress_devbridge_preview'] ?? '' );
	// phpcs:enable
	if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $token ) ) {
		return;
	}
	$preview = devbridge_preview_load();
	if ( null === $preview || ! hash_equals( (string) $preview['token_sha256'], hash( 'sha256', $token ) ) ) {
		if ( ! $from_link ) {
			setcookie( 'wordpress_devbridge_preview', '', time() - 3600, '/' ); // Stale cookie: drop it.
		}
		return;
	}

	if ( $from_link ) {
		setcookie(
			'wordpress_devbridge_preview', // "wordpress_" prefix: many server/CDN caches bypass it.
			$token,
			array(
				'expires'  => (int) $preview['expires_at'],
				'path'     => '/',
				'secure'   => ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'],
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$uri = rtrim( (string) preg_replace( '/([?&])devbridge_preview=[0-9a-f]{64}&?/', '$1', sanitize_text_field( wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) ) ) ), '?&' );
		// Same-site path only: "//host" or "/\host" would be an open redirect.
		if ( '' === $uri || '/' !== $uri[0] || ( isset( $uri[1] ) && ( '/' === $uri[1] || '\\' === $uri[1] ) ) ) {
			$uri = '/';
		}
		header( 'Location: ' . $uri, true, 302 );
		header( 'Cache-Control: no-store' );
		exit;
	}

	$themes  = array();
	$plugins = array();
	foreach ( (array) $preview['units'] as $live => $copy ) {
		$live = (string) $live;
		$copy = (string) $copy;
		if ( str_starts_with( $live, 'wp-content/themes/' ) ) {
			$themes[ substr( $live, 18 ) ] = substr( $copy, 18 );
		} elseif ( str_starts_with( $live, 'wp-content/plugins/' ) ) {
			$plugins[ substr( $live, 19 ) ] = substr( $copy, 19 );
		}
	}

	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true ); // Page caches must never store a preview.
	}
	header( 'Cache-Control: no-store, private' );
	header( 'X-Robots-Tag: noindex' );
	header( 'X-DevBridge-Preview: 1' );

	$map_plugins = static function ( $items ) use ( $plugins ) {
		if ( ! is_array( $items ) ) {
			return $items;
		}
		$out = array();
		foreach ( $items as $key => $value ) {
			// active_plugins: list of "folder/file.php"; active_sitewide_plugins: "folder/file.php" => time.
			$file   = is_string( $value ) ? $value : (string) $key;
			$folder = explode( '/', $file, 2 )[0];
			$mapped = isset( $plugins[ $folder ] ) ? $plugins[ $folder ] . substr( $file, strlen( $folder ) ) : $file;
			if ( is_string( $value ) ) {
				$out[] = $mapped;
			} else {
				$out[ $mapped ] = $value;
			}
		}
		return $out;
	};
	$map_theme   = static function ( $slug ) use ( $themes ) {
		return is_string( $slug ) && isset( $themes[ $slug ] ) ? $themes[ $slug ] : $slug;
	};
	if ( array() !== $plugins ) {
		add_filter( 'option_active_plugins', $map_plugins, 1 );
		add_filter( 'site_option_active_sitewide_plugins', $map_plugins, 1 );
	}
	if ( array() !== $themes ) {
		foreach ( array( 'option_stylesheet', 'option_template', 'stylesheet', 'template' ) as $hook ) {
			add_filter( $hook, $map_theme, 1 );
		}
	}
	$label = static function (): void {
		echo '<div style="position:fixed;bottom:12px;left:12px;z-index:2147483647;background:#1e1e1e;color:#fff;font:600 12px/1 sans-serif;padding:8px 12px;border-radius:999px;box-shadow:0 2px 8px rgba(0,0,0,.3)">Dev Bridge · Anteprima</div>';
	};
	add_action( 'wp_footer', $label, 9999 );
	add_action( 'admin_footer', $label, 9999 );
}

/**
 * Reads preview.json from the private storage (same lookup as the rescue mu-plugin), or null.
 *
 * @return array<string, mixed>|null
 */
function devbridge_preview_load(): ?array {
	$content = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
	$dirs    = defined( 'DEVBRIDGE_STORAGE_DIR' ) && '' !== (string) DEVBRIDGE_STORAGE_DIR
		? array( rtrim( (string) DEVBRIDGE_STORAGE_DIR, '/\\' ) )
		: array_filter( (array) glob( rtrim( $content, '/\\' ) . '/devbridge-*', GLOB_ONLYDIR ), static fn ( $d ): bool => 1 === preg_match( '/devbridge-[0-9a-f]{16}$/', (string) $d ) );
	foreach ( $dirs as $dir ) {
		$file = $dir . '/preview.json';
		if ( ! is_file( $file ) || filesize( $file ) > 1048576 ) {
			continue;
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		if ( is_array( $data ) && isset( $data['token_sha256'], $data['expires_at'], $data['units'] ) && (int) $data['expires_at'] >= time() ) {
			return $data;
		}
	}
	return null;
}
