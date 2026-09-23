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
		/** @var array<string, mixed> Network (site) options and transients. */
		public static array $siteOptions = [];
		public static bool $multisite    = false;

		public static function reset(): void {
			self::$options     = [];
			self::$transients  = [];
			self::$siteOptions = [];
			self::$multisite   = false;
		}
	}
}

namespace {

	use Lab591\DevBridge\Tests\Support\WpStubs;

	if ( ! function_exists( '__' ) ) {
		// phpcs:disable WordPress.WP.I18n -- stand-ins of the i18n functions (tests use the English source strings).
		function __( string $text, string $domain = 'default' ): string {
			return $text;
		}
		function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
			return 1 === $number ? $single : $plural;
		}
		function esc_html__( string $text, string $domain = 'default' ): string {
			return htmlspecialchars( $text, ENT_QUOTES );
		}
		// phpcs:enable
	}

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
		function is_multisite(): bool {
			return WpStubs::$multisite;
		}
		function get_site_option( string $name, mixed $default_value = false ): mixed {
			return WpStubs::$siteOptions[ $name ] ?? $default_value;
		}
		function update_site_option( string $name, mixed $value ): bool {
			WpStubs::$siteOptions[ $name ] = $value;
			return true;
		}
		function delete_site_option( string $name ): bool {
			unset( WpStubs::$siteOptions[ $name ] );
			return true;
		}
		function get_site_transient( string $name ): mixed {
			return WpStubs::$siteOptions[ '_t_' . $name ] ?? false;
		}
		function set_site_transient( string $name, mixed $value, int $expiration = 0 ): bool {
			WpStubs::$siteOptions[ '_t_' . $name ] = $value;
			return true;
		}
		function delete_site_transient( string $name ): bool {
			unset( WpStubs::$siteOptions[ '_t_' . $name ] );
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
