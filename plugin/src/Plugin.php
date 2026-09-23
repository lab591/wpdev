<?php
/**
 * Plugin bootstrap and service wiring.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge;

use Lab591\DevBridge\Admin\AdminPage;
use Lab591\DevBridge\Audit\AuditLog;
use Lab591\DevBridge\Cli\Command;
use Lab591\DevBridge\Rest\Api;
use Lab591\DevBridge\Security\PathException;
use Lab591\DevBridge\Security\PathGuard;
use Lab591\DevBridge\Security\PathPolicy;
use Lab591\DevBridge\Security\WritableRootValidator;

final class Plugin {

	public const CRON_AUDIT = 'devbridge_audit_cleanup';

	private static ?self $instance = null;

	private Settings $settings;
	private Mode $mode;
	private AuditLog $audit;
	private ?PathGuard $guard = null;

	private function __construct() {
		$this->settings = new Settings();
		$this->mode     = new Mode( $this->settings );
		$this->audit    = new AuditLog();
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		add_action( 'rest_api_init', [ new Api( $this ), 'register' ] );
		add_action( self::CRON_AUDIT, [ $this, 'cleanupAudit' ] );
		add_action( 'plugins_loaded', [ $this->audit, 'maybeUpgrade' ] );
		if ( is_admin() ) {
			( new AdminPage( $this ) )->register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'devbridge', new Command( $this ) );
		}
	}

	public static function activate(): void {
		$plugin = self::instance();
		$plugin->audit->install();
		if ( ! wp_next_scheduled( self::CRON_AUDIT ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_AUDIT );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_AUDIT );
		self::instance()->mode->disable();
	}

	public function cleanupAudit(): void {
		$this->audit->cleanup( max( 1, (int) $this->settings->get( 'audit_retention_days' ) ) );
	}

	public function settings(): Settings {
		return $this->settings;
	}

	public function mode(): Mode {
		return $this->mode;
	}

	public function audit(): AuditLog {
		return $this->audit;
	}

	public function pluginDir(): string {
		return dirname( PLUGIN_FILE );
	}

	/**
	 * Absolute paths that are never readable nor writable through the API.
	 *
	 * @return string[]
	 */
	public function protectedPaths(): array {
		return [ $this->pluginDir() ];
	}

	public function rootValidator(): WritableRootValidator {
		return new WritableRootValidator(
			ABSPATH,
			$this->protectedPaths(),
			(bool) $this->settings->get( 'allow_mu_plugins' ),
			$this->denyPatterns()
		);
	}

	/**
	 * Writable roots that are valid right now (re-validated at every request).
	 *
	 * @return string[]
	 */
	public function validWritableRoots(): array {
		$validator = $this->rootValidator();
		$valid     = [];
		foreach ( $this->settings->writableRoots() as $root ) {
			try {
				$valid[] = $validator->validate( $root );
			} catch ( PathException ) {
				continue;
			}
		}
		return array_values( array_unique( $valid ) );
	}

	/**
	 * Configured deny list plus the non-removable core entries.
	 *
	 * @return string[]
	 */
	public function denyPatterns(): array {
		return array_values( array_unique( array_merge( PathPolicy::MANDATORY_DENY_PATTERNS, (array) $this->settings->get( 'deny_patterns' ) ) ) );
	}

	public function guard(): PathGuard {
		if ( null === $this->guard ) {
			$this->guard = new PathGuard(
				new PathPolicy(
					abspath: ABSPATH,
					readRoots: (array) $this->settings->get( 'read_roots' ),
					writableRoots: $this->validWritableRoots(),
					denyPatterns: $this->denyPatterns(),
					writeExtensions: (array) $this->settings->get( 'write_extensions' ),
					protectedPaths: $this->protectedPaths(),
				)
			);
		}
		return $this->guard;
	}

	/**
	 * Drops cached services after settings changes.
	 */
	public function reset(): void {
		$this->settings->flush();
		$this->guard = null;
	}
}
