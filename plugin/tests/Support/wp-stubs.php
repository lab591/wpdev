<?php
/**
 * In-memory stand-ins for the few WordPress functions used by unit-tested services.
 * Loaded only by the PHPUnit bootstrap; never shipped with the plugin at runtime.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Support {

	final class WpStubs {
		/** @var array<string, mixed> */
		public static array $options = [];
		/** @var array<string, mixed> */
		public static array $transients = [];

		public static function reset(): void {
			self::$options    = [];
			self::$transients = [];
		}
	}
}

namespace {

	use Lab591\DevBridge\Tests\Support\WpStubs;

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( string $name, mixed $default_value = false ): mixed {
			return WpStubs::$options[ $name ] ?? $default_value;
		}
		function update_option( string $name, mixed $value, mixed $autoload = null ): bool {
			WpStubs::$options[ $name ] = $value;
			return true;
		}
		function delete_option( string $name ): bool {
			unset( WpStubs::$options[ $name ] );
			return true;
		}
		function get_transient( string $name ): mixed {
			return WpStubs::$transients[ $name ] ?? false;
		}
		function set_transient( string $name, mixed $value, int $expiration = 0 ): bool {
			WpStubs::$transients[ $name ] = $value;
			return true;
		}
		function delete_transient( string $name ): bool {
			unset( WpStubs::$transients[ $name ] );
			return true;
		}
		function home_url( string $path = '' ): string {
			return 'https://example.test' . $path;
		}
		function wp_json_encode( mixed $data, int $flags = 0 ): string|false {
			return json_encode( $data, $flags );
		}
	}
}
