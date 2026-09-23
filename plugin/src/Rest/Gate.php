<?php
/**
 * Permission checks shared by every endpoint (SPEC 2.2, 2.3).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Rest;

use Lab591\DevBridge\Mode;
use Lab591\DevBridge\Plugin;
use Lab591\DevBridge\Security\ClientIp;
use Lab591\DevBridge\Security\IpMatcher;
use Lab591\DevBridge\Security\RateLimiter;

final class Gate {

	public const READ_PER_MINUTE  = 120;
	public const WRITE_PER_MINUTE = 10;

	public function __construct( private readonly Plugin $plugin ) {
	}

	public function clientIp(): string {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- validated as IP by ClientIp/IpMatcher.
		$remote    = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) : '';
		// phpcs:enable
		return ClientIp::resolve( $remote, $forwarded, (array) $this->plugin->settings()->get( 'trusted_proxies' ) );
	}

	/**
	 * @param string $required Mode::READ or Mode::WRITE.
	 * @param string $bucket   Rate limit bucket: 'read' or 'write'.
	 * @return true|\WP_Error
	 */
	public function check( string $required, string $bucket ): bool|\WP_Error {
		$mode = $this->plugin->mode();
		if ( Mode::OFF === $mode->current() ) {
			return self::error( 'mode_off', 'Development mode is off', 403 );
		}
		if ( ! $mode->allows( $required ) ) {
			return self::error( 'mode_insufficient', sprintf( 'This endpoint requires "%s" mode', $required ), 403 );
		}
		if ( ! is_ssl() && 'local' !== wp_get_environment_type() ) {
			return self::error( 'https_required', 'HTTPS is required', 403 );
		}

		$settings  = $this->plugin->settings();
		$allowlist = (array) $settings->get( 'ip_allowlist' );
		if ( [] !== $allowlist && ! IpMatcher::matchesAny( $this->clientIp(), $allowlist ) ) {
			return self::error( 'forbidden_ip', 'IP address not allowed', 403 );
		}

		// Only Application Passwords: cookie sessions (CSRF-prone) are refused.
		$userId = get_current_user_id();
		if ( $userId <= 0 || null === rest_get_authenticated_app_password() ) {
			return self::error( 'app_password_required', 'Authenticate with an Application Password', 401 );
		}
		if ( ! user_can( $userId, 'manage_options' ) || ! in_array( $userId, $settings->allowedUserIds(), true ) ) {
			return self::error( 'forbidden_user', 'User not allowed', 403 );
		}

		$limit = 'write' === $bucket ? self::WRITE_PER_MINUTE : self::READ_PER_MINUTE;
		$retry = ( new RateLimiter() )->hit( $userId, $bucket, $limit );
		if ( null !== $retry ) {
			return new \WP_Error(
				'rate_limited',
				'Too many requests',
				[
					'status'      => 429,
					'retry_after' => $retry,
				]
			);
		}
		return true;
	}

	public static function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
