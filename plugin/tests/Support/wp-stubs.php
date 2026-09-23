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
		/** @var list<array{string, list<mixed>}> Actions fired with do_action(). */
		public static array $actions = [];
		/** @var (callable(string): array{code: int}|null)|null Answers wp_remote_get() by URL (null = network error). */
		public static $http = null;

		public static function reset(): void {
			self::$options     = [];
			self::$transients  = [];
			self::$siteOptions = [];
			self::$multisite   = false;
			self::$http        = null;
			self::$actions     = [];
		}
	}
}

namespace {

	use Lab591\DevBridge\Tests\Support\WpStubs;

	if ( ! class_exists( 'WP_Error' ) ) {
		// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- minimal stand-in.
		final class WP_Error {
			public function __construct( private string $code = '', private string $message = '' ) {
			}
			public function get_error_message(): string {
				return $this->message;
			}
			public function get_error_code(): string {
				return $this->code;
			}
		}
	}

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
		function do_action( string $hook, mixed ...$args ): void {
			WpStubs::$actions[] = [ $hook, $args ];
		}
		function wp_parse_url( string $url, int $component = -1 ): mixed {
			return parse_url( $url, $component );
		}
		function wp_remote_get( string $url, array $args = [] ): mixed {
			$answer = null === WpStubs::$http ? [ 'code' => 200 ] : ( WpStubs::$http )( $url );
			return null === $answer ? new \WP_Error( 'http_request_failed', 'Connection refused' ) : [ 'response' => [ 'code' => $answer['code'] ] ];
		}
		function wp_remote_retrieve_response_code( mixed $response ): int {
			return (int) ( $response['response']['code'] ?? 0 );
		}
		function is_wp_error( mixed $thing ): bool {
			return $thing instanceof \WP_Error;
		}
		function add_query_arg( string $key, string $value, string $url ): string {
			return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $key . '=' . $value;
		}
		function home_url( string $path = '' ): string {
			return 'https://example.test' . $path;
		}
		function wp_json_encode( mixed $data, int $flags = 0 ): string|false {
			return json_encode( $data, $flags );
		}
	}
}
