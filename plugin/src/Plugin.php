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
use Lab591\DevBridge\Deploy\Deployer;
use Lab591\DevBridge\Deploy\RollbackService;
use Lab591\DevBridge\Rescue\RescueInstaller;
use Lab591\DevBridge\Rest\Api;
use Lab591\DevBridge\Security\PathException;
use Lab591\DevBridge\Security\PathGuard;
use Lab591\DevBridge\Security\PathPolicy;
use Lab591\DevBridge\Security\WritableRootValidator;
use Lab591\DevBridge\Services\GrepService;
use Lab591\DevBridge\Services\HashCache;
use Lab591\DevBridge\Services\HealthService;
use Lab591\DevBridge\Services\RipgrepSearcher;
use Lab591\DevBridge\Services\StatusService;
use Lab591\DevBridge\Storage\Storage;
use Lab591\DevBridge\Support\Options;

final class Plugin {

	public const CRON_AUDIT = 'devbridge_audit_cleanup';

	private static ?self $instance = null;

	private Settings $settings;
	private Mode $mode;
	private AuditLog $audit;
	private ?PathGuard $guard     = null;
	private ?Storage $storage     = null;
	private ?HashCache $hashCache = null;

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
		if ( Options::network() && ! self::isNetworkActive() ) {
			// Active on a single site of a network (e.g. activated before the multisite conversion):
			// never expose the API or the settings there (SPEC 2.14).
			add_action( 'admin_notices', [ self::class, 'singleSiteNotice' ] );
			return;
		}
		add_action( 'init', [ self::class, 'loadTextdomain' ] );
		add_action( 'rest_api_init', [ new Api( $this ), 'register' ] );
		add_action( self::CRON_AUDIT, [ $this, 'cleanupAudit' ] );
		add_action( 'plugins_loaded', [ $this->audit, 'maybeUpgrade' ] );
		if ( is_admin() ) {
			( new AdminPage( $this ) )->register();
			add_action( 'admin_init', [ $this->rescueInstaller(), 'maybeRepair' ] );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'devbridge', new Command( $this ) );
		}
	}

	/**
	 * @param bool $networkWide True for a network activation (multisite).
	 */
	public static function activate( $networkWide = false ): void {
		if ( Options::network() && ! $networkWide ) {
			wp_die(
				esc_html__( 'On a multisite network Dev Bridge can only be network-activated (Network Admin → Plugins → Network Activate), because themes and plugins are shared by all sites.', 'lab591-dev-bridge' ),
				esc_html__( 'Activation not allowed', 'lab591-dev-bridge' ),
				[ 'back_link' => true ]
			);
		}
		$plugin = self::instance();
		$plugin->audit->install();
		$plugin->rescueInstaller()->install();
		// On a network the cleanup runs once, on the main site.
		$switched = Options::network() && ! is_main_site() && switch_to_blog( get_main_site_id() );
		if ( ! wp_next_scheduled( self::CRON_AUDIT ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_AUDIT );
		}
		if ( $switched ) {
			restore_current_blog();
		}
	}

	/**
	 * Whether the plugin is network-activated (multisite).
	 */
	public static function isNetworkActive(): bool {
		$active = get_site_option( 'active_sitewide_plugins', [] );
		return is_array( $active ) && isset( $active[ plugin_basename( PLUGIN_FILE ) ] );
	}

	public static function loadTextdomain(): void {
		load_plugin_textdomain( 'lab591-dev-bridge', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );
	}

	public static function singleSiteNotice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Dev Bridge is active on this site only, but this is a multisite network: deactivate it and network-activate it from Network Admin. Until then it does nothing.', 'lab591-dev-bridge' ) . '</p></div>';
	}

	/**
	 * Sites of the network as host + path (empty on single sites), for health URLs.
	 *
	 * @return list<array{host: string, path: string}>
	 */
	public function networkSites(): array {
		if ( ! Options::network() ) {
			return [];
		}
		$sites = [];
		foreach ( get_sites(
			[
				'number'   => 1000,
				'archived' => 0,
				'deleted'  => 0,
				'spam'     => 0,
			]
		) as $site ) {
			$sites[] = [
				'host' => (string) $site->domain,
				'path' => (string) $site->path,
			];
		}
		return $sites;
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_AUDIT );
		self::instance()->mode->disable();
		// Disabling the plugin must also switch off the out-of-band rescue.
		self::instance()->rescueInstaller()->remove();
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
		return [ $this->pluginDir(), $this->storage()->dir(), $this->rescueInstaller()->target() ];
	}

	public function storage(): Storage {
		if ( null === $this->storage ) {
			$this->storage = Storage::fromWordPress();
		}
		return $this->storage;
	}

	public function rescueInstaller(): RescueInstaller {
		return new RescueInstaller(
			$this->pluginDir() . '/mu-plugin/' . RescueInstaller::FILE_NAME,
			defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins',
			VERSION
		);
	}

	public function health(): HealthService {
		return new HealthService( $this->settings->healthUrls(), StatusService::debugLogPath(), ABSPATH );
	}

	/**
	 * Deploy limits, capped by what PHP accepts for uploads.
	 *
	 * @return array{deploy_zip_bytes: int, deploy_files: int, deploy_file_bytes: int}
	 */
	public function deployLimits(): array {
		$upload = min( self::iniBytes( 'upload_max_filesize' ), self::iniBytes( 'post_max_size' ) );
		return [
			'deploy_zip_bytes'  => min( $this->settings->limit( 'deploy_zip_bytes' ), $upload > 0 ? $upload : PHP_INT_MAX ),
			'deploy_files'      => $this->settings->limit( 'deploy_files' ),
			'deploy_file_bytes' => $this->settings->limit( 'deploy_file_bytes' ),
		];
	}

	private static function iniBytes( string $key ): int {
		$value = trim( (string) ini_get( $key ) );
		if ( '' === $value ) {
			return 0;
		}
		$unit   = strtolower( substr( $value, -1 ) );
		$number = (int) $value;
		return match ( $unit ) {
			'g'     => $number * 1073741824,
			'm'     => $number * 1048576,
			'k'     => $number * 1024,
			default => $number,
		};
	}

	public function deployer(): Deployer {
		return new Deployer(
			$this->guard(),
			$this->storage(),
			$this->health(),
			$this->deployLimits(),
			max( 1, (int) $this->settings->get( 'retention_releases' ) ),
			[
				'ip_allowlist'    => (array) $this->settings->get( 'ip_allowlist' ),
				'trusted_proxies' => (array) $this->settings->get( 'trusted_proxies' ),
				'allow_http'      => 'local' === wp_get_environment_type(),
			]
		);
	}

	/**
	 * Hash cache shared by the read services of the current request (M3).
	 */
	public function hashCache(): HashCache {
		if ( null === $this->hashCache ) {
			$storage = $this->storage();
			$file    = null;
			try {
				$storage->ensure();
				$file = $storage->hashCacheFile();
			} catch ( \Throwable ) {
				$file = null; // No storage: hashes are computed every time.
			}
			$this->hashCache = new HashCache( $file );
		}
		return $this->hashCache;
	}

	public function grepService(): GrepService {
		$settings = $this->settings;
		$skip     = (array) $settings->get( 'grep_skip_dirs' );
		$rg       = null;
		$binary   = trim( (string) $settings->get( 'grep_rg' ) );
		if ( '' !== $binary && RipgrepSearcher::isValidBinary( $binary ) && function_exists( 'proc_open' ) ) {
			try {
				$this->storage()->ensure();
				$tmp   = $this->storage()->tmpDir();
				$key   = 'devbridge_rg_pcre2_' . md5( $binary );
				$pcre2 = Options::getTransient( $key );
				if ( false === $pcre2 ) {
					$pcre2 = RipgrepSearcher::detectPcre2( $binary, $tmp ) ? 'yes' : 'no';
					Options::setTransient( $key, $pcre2, DAY_IN_SECONDS );
				}
				$rg = new RipgrepSearcher( $this->guard(), $binary, $settings->limit( 'grep_file_bytes' ), array_map( 'strtolower', $skip ), 'yes' === $pcre2, $tmp );
			} catch ( \Throwable ) {
				$rg = null;
			}
		}
		return new GrepService( $this->guard(), $settings->limit( 'grep_results' ), $settings->limit( 'grep_ms' ), $settings->limit( 'grep_file_bytes' ), $skip, $rg );
	}

	/**
	 * Optional ripgrep acceleration, for the admin status: "off" (not configured), "ok" (runs;
	 * version and PCRE2 support) or "error" (configured but not executable). Cached for a day.
	 *
	 * @return array{state: string, version: string, pcre2: bool}
	 */
	public function ripgrepStatus( bool $refresh = false ): array {
		$binary = trim( (string) $this->settings->get( 'grep_rg' ) );
		$result = [
			'state'   => 'off',
			'version' => '',
			'pcre2'   => false,
		];
		if ( '' === $binary ) {
			return $result;
		}
		$key    = 'devbridge_rg_status_' . md5( $binary );
		$cached = $refresh ? false : Options::getTransient( $key );
		if ( is_array( $cached ) && isset( $cached['state'], $cached['version'], $cached['pcre2'] ) ) {
			return $cached;
		}
		$result['state'] = 'error';
		if ( RipgrepSearcher::isValidBinary( $binary ) && function_exists( 'proc_open' ) ) {
			try {
				$this->storage()->ensure();
				$version = RipgrepSearcher::version( $binary, $this->storage()->tmpDir() );
				if ( null !== $version ) {
					$result = [
						'state'   => 'ok',
						'version' => $version,
						'pcre2'   => RipgrepSearcher::detectPcre2( $binary, $this->storage()->tmpDir() ),
					];
				}
			} catch ( \Throwable ) {
				$result['state'] = 'error';
			}
		}
		Options::setTransient( $key, $result, DAY_IN_SECONDS );
		return $result;
	}

	public function rollbackService(): RollbackService {
		return new RollbackService( $this->guard(), $this->storage() );
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
