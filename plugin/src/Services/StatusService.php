<?php
/**
 * Site status (`GET /status`).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Services;

use Lab591\DevBridge\Plugin;
use Lab591\DevBridge\Support\Options;
use const Lab591\DevBridge\VERSION;

final class StatusService {

	public function __construct( private readonly Plugin $plugin ) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function status(): array {
		global $wp_version;
		$state    = $this->plugin->mode()->state();
		$settings = $this->plugin->settings();
		$theme    = wp_get_theme();
		$limits   = [];
		foreach ( [ 'read_bytes', 'grep_results', 'grep_ms' ] as $key ) {
			$limits[ $key ] = $settings->limit( $key );
		}
		$limits += $this->plugin->deployLimits();
		$status  = [
			'mode'           => $state['mode'],
			'expires_at'     => $state['expires_at'],
			'name'           => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'plugin'         => VERSION,
			'wp'             => (string) $wp_version,
			'php'            => PHP_VERSION,
			'theme'          => [
				'stylesheet' => (string) $theme->get_stylesheet(),
				'template'   => (string) $theme->get_template(),
				'version'    => (string) $theme->get( 'Version' ),
			],
			'writable_roots' => $this->plugin->validWritableRoots(),
			'limits'         => $limits,
			'debug_log'      => null !== self::debugLogFile(),
			'rescue'         => $this->plugin->rescueInstaller()->state(),
			'site_url'       => home_url( '/' ),
			'db'             => $settings->dbAccess(),
		];
		if ( Options::network() ) {
			$status['network'] = [
				'main_site' => get_home_url( get_main_site_id(), '/' ),
				'sites'     => count( $this->plugin->networkSites() ),
			];
		}
		return $status;
	}

	public static function debugLogFile(): ?string {
		$file = self::debugLogPath();
		return null !== $file && is_file( $file ) ? $file : null;
	}

	/**
	 * Configured debug.log location, even if the file does not exist yet (the health check must
	 * see the first fatal error ever written, which creates the file).
	 */
	public static function debugLogPath(): ?string {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return null;
		}
		return LogService::locate( WP_DEBUG_LOG, WP_CONTENT_DIR );
	}
}
