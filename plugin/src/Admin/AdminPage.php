<?php
/**
 * Admin page "Settings > Dev Bridge" (Network Admin on multisite): loads the admin app
 * (build/, React + @wordpress/components); its data comes from AdminController.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Admin;

use Lab591\DevBridge\Deploy\ReleaseStore;
use Lab591\DevBridge\Plugin;
use Lab591\DevBridge\Rescue\RescueInstaller;
use Lab591\DevBridge\Support\Options;
use const Lab591\DevBridge\PLUGIN_FILE;

final class AdminPage {

	public const SLUG = 'devbridge';

	public const SCRIPT = 'devbridge-admin';

	private string $hook = '';

	private AdminController $controller;

	public function __construct( private readonly Plugin $plugin ) {
		$this->controller = new AdminController( $plugin );
	}

	public function register(): void {
		add_action( Options::network() ? 'network_admin_menu' : 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( Options::network() ? 'network_admin_notices' : 'admin_notices', [ $this, 'pluginsScreenNotices' ] );
		add_filter( ( Options::network() ? 'network_admin_plugin_action_links_' : 'plugin_action_links_' ) . plugin_basename( PLUGIN_FILE ), [ $this, 'actionLinks' ] );
		$this->controller->register();
	}

	/**
	 * Settings page base URL: Network Admin → Settings on multisite.
	 */
	public static function baseUrl(): string {
		return Options::network() ? network_admin_url( 'settings.php' ) : admin_url( 'options-general.php' );
	}

	public static function pageUrl( string $tab = '' ): string {
		$args = [ 'page' => self::SLUG ];
		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}
		return add_query_arg( $args, self::baseUrl() );
	}

	public function menu(): void {
		$this->hook = Options::network()
			? (string) add_submenu_page( 'settings.php', 'Dev Bridge', 'Dev Bridge', Options::capability(), self::SLUG, [ $this, 'render' ] )
			: (string) add_options_page( 'Dev Bridge', 'Dev Bridge', Options::capability(), self::SLUG, [ $this, 'render' ] );
	}

	public function enqueue( string $hook ): void {
		if ( '' === $this->hook || $hook !== $this->hook ) {
			return;
		}
		$dir  = dirname( PLUGIN_FILE );
		$meta = self::assetMeta();
		if ( null === $meta ) {
			return; // Not built: render() explains it.
		}
		$url = plugins_url( 'build/', PLUGIN_FILE );
		wp_enqueue_script( self::SCRIPT, $url . 'index.js', (array) $meta['dependencies'], (string) $meta['version'], true );
		wp_add_inline_script( self::SCRIPT, 'window.devbridgeAdmin = ' . wp_json_encode( $this->controller->bootstrap() ) . ';', 'before' );
		wp_set_script_translations( self::SCRIPT, 'lab591-dev-bridge', $dir . '/languages' );
		wp_enqueue_style( 'wp-components' );
		if ( is_file( $dir . '/build/index.css' ) ) {
			wp_enqueue_style( self::SCRIPT, $url . 'index.css', [ 'wp-components' ], (string) $meta['version'] );
		}
	}

	/**
	 * Dependencies and version of the build, from the JSON written by wp-scripts (never included as PHP).
	 *
	 * @return array{dependencies: string[], version: string}|null
	 */
	private static function assetMeta(): ?array {
		$file = dirname( PLUGIN_FILE ) . '/build/index.asset.json';
		$data = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		if ( ! is_array( $data ) || ! is_array( $data['dependencies'] ?? null ) ) {
			return null;
		}
		return [
			'dependencies' => array_values( array_filter( $data['dependencies'], 'is_string' ) ),
			'version'      => (string) ( $data['version'] ?? \Lab591\DevBridge\VERSION ),
		];
	}

	public function render(): void {
		if ( ! current_user_can( Options::capability() ) ) {
			return;
		}
		echo '<div class="wrap devbridge-wrap"><div id="devbridge-admin-root"></div>';
		if ( null === self::assetMeta() ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'The admin interface has not been built: run "npm install && npm run build" in the plugin folder, or install the release zip.', 'lab591-dev-bridge' )
			);
		}
		printf( '<noscript><div class="notice notice-error"><p>%s</p></div></noscript></div>', esc_html__( 'Dev Bridge settings need JavaScript.', 'lab591-dev-bridge' ) );
	}

	/**
	 * "Settings" link and, when release backups exist, a confirmation before deactivating.
	 *
	 * @param array<string, string> $links
	 * @return array<string, string>
	 */
	public function actionLinks( array $links ): array {
		$links = [ 'settings' => sprintf( '<a href="%s">%s</a>', esc_url( self::pageUrl() ), esc_html__( 'Settings', 'lab591-dev-bridge' ) ) ] + $links;
		$count = count( ( new ReleaseStore( $this->plugin->storage()->releasesDir() ) )->all() );
		if ( $count > 0 && isset( $links['deactivate'] ) ) {
			$message = sprintf(
				/* translators: %d: number of release backups. */
				_n(
					'There is %d Dev Bridge release backup. Deactivating also switches off the rescue; deleting the plugin deletes the backups. Continue?',
					'There are %d Dev Bridge release backups. Deactivating also switches off the rescue; deleting the plugin deletes the backups. Continue?',
					$count,
					'lab591-dev-bridge'
				),
				$count
			);
			$links['deactivate'] = str_replace( '<a ', '<a onclick="return confirm(\'' . esc_js( $message ) . '\');" ', $links['deactivate'] );
		}
		return $links;
	}

	/**
	 * Rescue warning on the Plugins screen (the Dev Bridge page shows full details).
	 */
	public function pluginsScreenNotices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || ! in_array( $screen->id, [ 'plugins', 'plugins-network' ], true ) || ! current_user_can( Options::capability() ) ) {
			return;
		}
		if ( RescueInstaller::INSTALLED !== $this->plugin->rescueInstaller()->state() ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Dev Bridge: the rescue mu-plugin is not installed, out-of-band rollback is not available.', 'lab591-dev-bridge' ),
				esc_url( self::pageUrl() ),
				esc_html__( 'Details', 'lab591-dev-bridge' )
			);
		}
	}
}
