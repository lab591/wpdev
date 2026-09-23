<?php
/**
 * Option and transient storage: per site on single installs, network-wide on multisite (SPEC 2.14).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Support;

final class Options {

	public static function network(): bool {
		return function_exists( 'is_multisite' ) && is_multisite();
	}

	public static function get( string $name, mixed $fallback = false ): mixed {
		return self::network() ? get_site_option( $name, $fallback ) : get_option( $name, $fallback );
	}

	public static function update( string $name, mixed $value, bool $autoload = false ): void {
		if ( self::network() ) {
			update_site_option( $name, $value );
			return;
		}
		update_option( $name, $value, $autoload );
	}

	public static function delete( string $name ): void {
		if ( self::network() ) {
			delete_site_option( $name );
			return;
		}
		delete_option( $name );
	}

	public static function getTransient( string $name ): mixed {
		return self::network() ? get_site_transient( $name ) : get_transient( $name );
	}

	public static function setTransient( string $name, mixed $value, int $expiration ): void {
		if ( self::network() ) {
			set_site_transient( $name, $value, $expiration );
			return;
		}
		set_transient( $name, $value, $expiration );
	}

	public static function deleteTransient( string $name ): void {
		if ( self::network() ) {
			delete_site_transient( $name );
			return;
		}
		delete_transient( $name );
	}

	/**
	 * Capability required to manage and use Dev Bridge: super admins only on multisite.
	 */
	public static function capability(): string {
		return self::network() ? 'manage_network_options' : 'manage_options';
	}
}
