<?php
/**
 * JSON actions of the admin interface (admin-ajax, cookie + nonce + capability).
 *
 * These are deliberately NOT REST routes: REST requests can be authenticated with Application
 * Passwords (the credentials used by the companion), and no API usable with those credentials may
 * change the mode, the settings or the roots (security invariant 2). Admin-ajax only accepts the
 * logged-in admin cookie, and every action checks capability and nonce.
 *
 * Validation stays in the existing classes (SettingsForm, WritableRootValidator, FolderCreator,
 * Mode): this class only translates between them and the interface.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Admin;

use Lab591\DevBridge\Deploy\ReleaseStore;
use Lab591\DevBridge\Mode;
use Lab591\DevBridge\Plugin;
use Lab591\DevBridge\Rescue\RescueInstaller;
use Lab591\DevBridge\Security\PathException;
use Lab591\DevBridge\Security\WritableRootValidator;
use Lab591\DevBridge\Settings;
use Lab591\DevBridge\Support\Options;
use const Lab591\DevBridge\PLUGIN_FILE;

final class AdminController {

	public const NONCE = 'devbridge_admin';

	/** Audit endpoints offered in the filter. */
	public const AUDIT_ENDPOINTS = [ '/status', '/list', '/read', '/grep', '/manifest', '/archive', '/log', '/deploy', '/rollback', '/releases', '/health', '/cache-flush' ];

	public const AUDIT_PER_PAGE = 50;

	/** Action => [handler, HTTP method]. */
	private const ACTIONS = [
		'devbridge_status'   => [ 'status', 'GET' ],
		'devbridge_mode'     => [ 'mode', 'POST' ],
		'devbridge_settings' => [ 'settings', 'GET' ],
		'devbridge_save'     => [ 'save', 'POST' ],
		'devbridge_folders'  => [ 'folders', 'GET' ],
		'devbridge_mkdir'    => [ 'mkdir', 'POST' ],
		'devbridge_audit'    => [ 'audit', 'GET' ],
		'devbridge_notify'   => [ 'notifyTest', 'POST' ],
		'devbridge_preview'  => [ 'previewAction', 'POST' ],
	];

	public function __construct( private readonly Plugin $plugin ) {
	}

	public function register(): void {
		foreach ( self::ACTIONS as $action => [ $handler, $method ] ) {
			add_action(
				'wp_ajax_' . $action,
				function () use ( $handler, $method ): void {
					$this->authorize( $method );
					wp_send_json_success( $this->{$handler}() );
				}
			);
		}
	}

	/**
	 * Data embedded in the page for the interface bootstrap.
	 *
	 * @return array<string, mixed>
	 */
	public function bootstrap(): array {
		$user = wp_get_current_user();
		return [
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( self::NONCE ),
			'version'  => \Lab591\DevBridge\VERSION,
			'network'  => Options::network(),
			'siteUrl'  => home_url(),
			'restUrl'  => rest_url( 'devbridge/v1/' ),
			'user'     => $user->user_login,
			'locale'   => get_user_locale(),
			'profile'  => admin_url( 'profile.php#application-passwords-section' ),
			'endpoint' => self::AUDIT_ENDPOINTS,
		];
	}

	private function authorize( string $method ): void {
		if ( ! current_user_can( Options::capability() ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to manage Dev Bridge.', 'lab591-dev-bridge' ) ], 403 );
		}
		if ( false === check_ajax_referer( self::NONCE, '_ajax_nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'This page has expired: reload it and try again.', 'lab591-dev-bridge' ) ], 403 );
		}
		$actual = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( $method !== $actual ) {
			wp_send_json_error( [ 'message' => 'Method not allowed' ], 405 );
		}
	}

	/**
	 * JSON body of POST actions (`payload` field). Never unserialized, depth-limited.
	 *
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in authorize(); JSON validated field by field.
		$raw  = isset( $_POST['payload'] ) ? (string) wp_unslash( $_POST['payload'] ) : '';
		$data = json_decode( $raw, true, 16 );
		return is_array( $data ) ? $data : [];
	}

	private static function query( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked in authorize().
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	// ------------------------------------------------------------------ status

	/**
	 * @return array<string, mixed>
	 */
	public function status(): array {
		if ( '1' === self::query( 'refresh' ) ) {
			// "Check again": bypass the cached results (ripgrep detection) and retry the rescue install.
			$this->plugin->ripgrepStatus( true );
			$installer = $this->plugin->rescueInstaller();
			if ( RescueInstaller::INSTALLED !== $installer->state() ) {
				$installer->install();
			}
		}
		$mode     = $this->plugin->mode();
		$state    = $mode->state();
		$releases = array_slice( ( new ReleaseStore( $this->plugin->storage()->releasesDir() ) )->all(), 0, 20 );
		$logins   = [];
		foreach ( $releases as $release ) {
			$id = (int) $release['user_id'];
			if ( ! isset( $logins[ $id ] ) ) {
				$user          = get_userdata( $id );
				$logins[ $id ] = $user ? $user->user_login : '#' . $id;
			}
		}
		return [
			'mode'          => [
				'mode'      => $state['mode'],
				'expiresAt' => $state['expires_at'],
				'since'     => $state['since'],
				'maxHours'  => [
					'read'  => $mode->maxHours( Mode::READ ),
					'write' => $mode->maxHours( Mode::WRITE ),
				],
			],
			'writableRoots' => $this->plugin->validWritableRoots(),
			'checks'        => $this->checks(),
			'releases'      => array_map(
				static fn ( array $r ): array => [
					'id'        => (string) $r['id'],
					'createdAt' => (int) $r['created_at'],
					'user'      => $logins[ (int) $r['user_id'] ] ?? '',
					'written'   => (int) $r['written'],
					'deleted'   => (int) $r['deleted'],
					'status'    => (string) $r['status'],
				],
				$releases
			),
			'network'       => Options::network() ? [ 'sites' => count( $this->plugin->networkSites() ) ] : null,
			'preview'       => $this->plugin->previewService()->status(),
		];
	}

	/**
	 * Preview from the admin: a fresh link for the current admin, publish or discard.
	 *
	 * @return array<string, mixed>
	 */
	public function previewAction(): array {
		$data    = $this->payload();
		$service = $this->plugin->previewService();
		switch ( (string) ( $data['op'] ?? '' ) ) {
			case 'publish':
				try {
					$out = $service->publish( $this->plugin->deployer(), get_current_user_id() );
				} catch ( \Lab591\DevBridge\Support\ApiException $e ) {
					wp_send_json_error( [ 'message' => $e->getMessage() ], 400 );
				}
				$message = 'rolled_back' === ( $out['status'] ?? '' )
					? __( 'Publishing failed the health check and was rolled back: the live site is unchanged, the preview is kept.', 'lab591-dev-bridge' )
					/* translators: %s: release id. */
					: sprintf( __( 'Preview published (release %s).', 'lab591-dev-bridge' ), (string) $out['release_id'] );
				break;
			case 'discard':
				$service->discard();
				$message = __( 'Preview discarded.', 'lab591-dev-bridge' );
				break;
			default:
				wp_send_json_error( [ 'message' => 'Unknown operation' ], 400 );
		}
		return [ 'message' => $message ] + $this->status();
	}

	/**
	 * Setup checks shown on the status tab.
	 *
	 * @return list<array{id: string, status: string, title: string, detail: string, code: string}>
	 */
	public function checks(): array {
		$settings = $this->plugin->settings();
		$checks   = [];
		$add      = static function ( string $id, string $status, string $title, string $detail = '', string $code = '' ) use ( &$checks ): void {
			$checks[] = [
				'id'     => $id,
				'status' => $status,
				'title'  => $title,
				'detail' => $detail,
				'code'   => $code,
			];
		};

		$users = count( $settings->allowedUserIds() );
		if ( $users > 0 ) {
			/* translators: %d: number of authorized users. */
			$add( 'users', 'ok', sprintf( _n( '%d authorized user', '%d authorized users', $users, 'lab591-dev-bridge' ), $users ) );
		} else {
			$add( 'users', 'error', __( 'No authorized user', 'lab591-dev-bridge' ), __( 'Nobody can connect until you select at least one user in Settings → Access.', 'lab591-dev-bridge' ) );
		}

		$roots = count( $this->plugin->validWritableRoots() );
		if ( $roots > 0 ) {
			/* translators: %d: number of writable folders. */
			$add( 'roots', 'ok', sprintf( _n( '%d writable folder', '%d writable folders', $roots, 'lab591-dev-bridge' ), $roots ) );
		} else {
			$add( 'roots', 'warning', __( 'No writable folder', 'lab591-dev-bridge' ), __( 'Claude can read the site but not deploy: choose a theme or plugin in Settings → Writable folders.', 'lab591-dev-bridge' ) );
		}

		if ( is_ssl() ) {
			$add( 'https', 'ok', __( 'HTTPS active', 'lab591-dev-bridge' ) );
		} elseif ( 'local' === wp_get_environment_type() ) {
			$add( 'https', 'info', __( 'Local environment: HTTP allowed', 'lab591-dev-bridge' ), __( 'On a real site HTTPS is mandatory.', 'lab591-dev-bridge' ) );
		} else {
			$add( 'https', 'error', __( 'The site is not on HTTPS', 'lab591-dev-bridge' ), __( 'Requests will be rejected until the site uses HTTPS.', 'lab591-dev-bridge' ) );
		}

		if ( wp_is_application_passwords_available() ) {
			$add( 'app-passwords', 'ok', __( 'Application Passwords available', 'lab591-dev-bridge' ) );
		} else {
			$add( 'app-passwords', 'error', __( 'Application Passwords are disabled', 'lab591-dev-bridge' ), __( 'They are required to connect: check plugins or code that disable them.', 'lab591-dev-bridge' ) );
		}

		$installer = $this->plugin->rescueInstaller();
		if ( RescueInstaller::INSTALLED === $installer->state() ) {
			$add( 'rescue', 'ok', __( 'Out-of-band rescue installed', 'lab591-dev-bridge' ) );
		} elseif ( $installer->canWriteDirectly() ) {
			$add( 'rescue', 'warning', __( 'Out-of-band rescue not installed yet', 'lab591-dev-bridge' ), __( 'It will be installed automatically on the next admin page load.', 'lab591-dev-bridge' ) );
		} else {
			$add(
				'rescue',
				'warning',
				__( 'Out-of-band rescue not installed', 'lab591-dev-bridge' ),
				__( 'The mu-plugins folder is not directly writable. Copy the file below into wp-content/mu-plugins/ (permissions 0644).', 'lab591-dev-bridge' ),
				'wp-content/plugins/' . dirname( plugin_basename( PLUGIN_FILE ) ) . '/mu-plugin/' . RescueInstaller::FILE_NAME
			);
		}

		$storage = $this->plugin->storage();
		if ( $storage->isFallback() ) {
			$add(
				'storage',
				'info',
				__( 'Private storage inside wp-content', 'lab591-dev-bridge' ),
				__( 'It has a random name and is protected by .htaccess. Recommended: move it outside the web root in wp-config.php; on Nginx add the rule below.', 'lab591-dev-bridge' ),
				"define( 'DEVBRIDGE_STORAGE_DIR', '/path/outside/webroot/devbridge' );\nlocation ~ ^/wp-content/" . basename( $storage->dir() ) . '/ { deny all; return 404; }'
			);
		} else {
			$add( 'storage', 'ok', __( 'Private storage outside the web root', 'lab591-dev-bridge' ) );
		}

		$rg = $this->plugin->ripgrepStatus();
		if ( 'ok' === $rg['state'] ) {
			$add( 'ripgrep', 'ok', $rg['version'], $rg['pcre2'] ? __( 'Fast search active, regular expressions included (PCRE2).', 'lab591-dev-bridge' ) : __( 'Fast search active for text; regular expressions use the PHP search (no PCRE2).', 'lab591-dev-bridge' ) );
		} elseif ( 'error' === $rg['state'] ) {
			$add( 'ripgrep', 'warning', __( 'ripgrep configured but not executable', 'lab591-dev-bridge' ), __( 'Search keeps working with the PHP engine. Check the path in Settings → Search.', 'lab591-dev-bridge' ) );
		} else {
			$add( 'ripgrep', 'info', __( 'ripgrep not configured', 'lab591-dev-bridge' ), __( 'Optional: search uses the PHP engine.', 'lab591-dev-bridge' ) );
		}
		return $checks;
	}

	// ------------------------------------------------------------------ mode

	/**
	 * @return array<string, mixed>
	 */
	public function mode(): array {
		$data = $this->payload();
		$mode = $this->plugin->mode();
		if ( 'disable' === ( $data['op'] ?? '' ) ) {
			$mode->disable();
			$message = __( 'Development mode disabled.', 'lab591-dev-bridge' );
		} else {
			$requested = (string) ( $data['mode'] ?? '' );
			$hours     = (int) ( $data['hours'] ?? 0 );
			if ( ! in_array( $requested, [ Mode::READ, Mode::WRITE ], true ) ) {
				wp_send_json_error( [ 'message' => __( 'Choose read or write.', 'lab591-dev-bridge' ) ], 400 );
			}
			$max = $mode->maxHours( $requested );
			if ( $hours < 1 || $hours > $max ) {
				/* translators: %d: maximum number of hours. */
				wp_send_json_error( [ 'message' => sprintf( __( 'Duration must be between 1 and %d hours.', 'lab591-dev-bridge' ), $max ) ], 400 );
			}
			$mode->enable( $requested, $hours, get_current_user_id() );
			$message = Mode::WRITE === $requested
				/* translators: %d: hours. */
				? sprintf( _n( 'Read and write enabled for %d hour.', 'Read and write enabled for %d hours.', $hours, 'lab591-dev-bridge' ), $hours )
				/* translators: %d: hours. */
				: sprintf( _n( 'Read-only access enabled for %d hour.', 'Read-only access enabled for %d hours.', $hours, 'lab591-dev-bridge' ), $hours );
		}
		return [ 'message' => $message ] + $this->status();
	}

	// ------------------------------------------------------------------ settings

	/**
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		return [
			'settings'   => $this->uiSettings(),
			'users'      => $this->eligibleUsers(),
			'containers' => $this->plugin->rootValidator()->containers(),
			'limits'     => Settings::DEFAULT_LIMITS,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function save(): array {
		$data = $this->payload();
		if ( ! is_array( $data['settings'] ?? null ) || [] === $data['settings'] ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request: nothing was saved.', 'lab591-dev-bridge' ) ], 400 );
		}
		// Fields missing from the request keep their current value (never cleared by omission).
		$input = array_merge( $this->uiSettings(), array_intersect_key( $data['settings'], Settings::defaults() ) );
		$form  = $this->form();
		$clean = $form->sanitize( $input, $this->plugin->settings()->all() );

		$clean['allowed_user_ids'] = array_values( array_filter( $clean['allowed_user_ids'], static fn ( int $id ): bool => user_can( $id, Options::capability() ) ) );
		$this->plugin->settings()->save( $clean );
		$this->plugin->reset();
		return [
			'errors'  => $form->errors(),
			'message' => [] === $form->errors() ? __( 'Settings saved.', 'lab591-dev-bridge' ) : __( 'Settings saved, some entries were discarded:', 'lab591-dev-bridge' ),
		] + $this->settings();
	}

	public function form(): SettingsForm {
		$plugin = $this->plugin;
		return new SettingsForm(
			static fn ( bool $mu ) => new WritableRootValidator( ABSPATH, $plugin->protectedPaths(), $mu, $plugin->denyPatterns() ),
			ABSPATH,
			$this->allowedHosts()
		);
	}

	/**
	 * Settings in the shape used by the interface (lists as arrays, site root as ".").
	 *
	 * @return array<string, mixed>
	 */
	public function uiSettings(): array {
		$s                     = $this->plugin->settings()->all();
		$s['read_roots']       = array_map( static fn ( $r ): string => '' === $r ? '.' : (string) $r, (array) $s['read_roots'] );
		$s['allowed_user_ids'] = array_map( 'intval', (array) $s['allowed_user_ids'] );
		foreach ( [ 'writable_roots', 'deny_patterns', 'write_extensions', 'ip_allowlist', 'trusted_proxies', 'grep_skip_dirs', 'health_urls', 'notify_emails', 'notify_events' ] as $key ) {
			$s[ $key ] = array_values( array_map( 'strval', (array) $s[ $key ] ) );
		}
		$s['invalid_roots'] = array_values( array_diff( $s['writable_roots'], $this->plugin->validWritableRoots() ) );
		return $s;
	}

	/**
	 * Hosts accepted in health URLs: this site, or every site of the network.
	 *
	 * @return string[]
	 */
	private function allowedHosts(): array {
		$hosts = [ (string) wp_parse_url( home_url(), PHP_URL_HOST ) ];
		foreach ( $this->plugin->networkSites() as $site ) {
			$hosts[] = $site['host'];
		}
		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Users that may be authorized: administrators, or super admins on a network.
	 *
	 * @return list<array{id: int, login: string, name: string}>
	 */
	private function eligibleUsers(): array {
		$users = [];
		if ( Options::network() ) {
			foreach ( get_super_admins() as $login ) {
				$user = get_user_by( 'login', $login );
				if ( $user ) {
					$users[] = $user;
				}
			}
		} else {
			$users = get_users( [ 'capability' => 'manage_options' ] );
		}
		return array_values(
			array_map(
				static fn ( $u ): array => [
					'id'    => (int) $u->ID,
					'login' => (string) $u->user_login,
					'name'  => (string) $u->display_name,
				],
				$users
			)
		);
	}

	// ------------------------------------------------------------------ folders

	private function folderPicker(): FolderPicker {
		return new FolderPicker( $this->plugin->rootValidator(), [ 'wp-content/plugins/' . dirname( plugin_basename( PLUGIN_FILE ) ) ] );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function folders(): array {
		try {
			$list = $this->folderPicker()->children( self::query( 'path' ) );
		} catch ( PathException ) {
			wp_send_json_error( [ 'message' => __( 'This folder cannot be listed.', 'lab591-dev-bridge' ) ], 400 );
		}
		$labels = $this->folderLabels();
		foreach ( $list['items'] as $i => $item ) {
			$list['items'][ $i ]['label'] = $labels[ $item['path'] ] ?? '';
		}
		return $list;
	}

	/**
	 * Creates an empty folder and adds it to the saved writable folders.
	 *
	 * @return array<string, mixed>
	 */
	public function mkdir(): array {
		$data = $this->payload();
		try {
			$created = ( new FolderCreator( $this->plugin->rootValidator() ) )->create( (string) ( $data['container'] ?? '' ), (string) ( $data['path'] ?? '' ) );
		} catch ( PathException $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], 400 );
		}
		$settings = $this->plugin->settings();
		$all      = $settings->all();
		$roots    = SettingsForm::withoutNested( array_values( array_unique( [ ...array_map( 'strval', (array) $all['writable_roots'] ), $created ] ) ) );
		$settings->save( [ 'writable_roots' => $roots ] + $all );
		$this->plugin->reset();
		return [
			'created' => $created,
			/* translators: %s: folder path. */
			'message' => sprintf( __( 'Folder %s created and made writable. Run "wpdev pull" in the local project.', 'lab591-dev-bridge' ), $created ),
		] + $this->settings();
	}

	/**
	 * Theme and plugin names by folder; the active theme is marked on single sites.
	 *
	 * @return array<string, string>
	 */
	private function folderLabels(): array {
		$labels = [];
		foreach ( wp_get_themes() as $slug => $theme ) {
			$labels[ 'wp-content/themes/' . $slug ] = (string) $theme->get( 'Name' );
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $data ) {
			if ( str_contains( $file, '/' ) ) {
				$labels[ 'wp-content/plugins/' . dirname( $file ) ] = (string) $data['Name'];
			}
		}
		if ( ! Options::network() ) {
			foreach ( array_unique( [ get_stylesheet(), get_template() ] ) as $active ) {
				$key            = 'wp-content/themes/' . $active;
				$labels[ $key ] = trim( ( $labels[ $key ] ?? '' ) . ' · ' . __( 'active theme', 'lab591-dev-bridge' ), ' ·' );
			}
		}
		return $labels;
	}

	// ------------------------------------------------------------------ notifications

	/**
	 * Sends a test notification with the saved settings.
	 *
	 * @return array<string, mixed>
	 */
	public function notifyTest(): array {
		$result = ( new \Lab591\DevBridge\Services\Notifier( $this->plugin->settings() ) )->test( get_current_user_id() );
		return $result + [
			/* translators: 1: email result, 2: webhook result. */
			'message' => sprintf( __( 'Test sent. Email: %1$s. Webhook: %2$s.', 'lab591-dev-bridge' ), $result['email'], $result['webhook'] ),
		];
	}

	// ------------------------------------------------------------------ audit

	/**
	 * @return array<string, mixed>
	 */
	public function audit(): array {
		$network = Options::network();
		$filters = [
			'endpoint' => in_array( self::query( 'endpoint' ), self::AUDIT_ENDPOINTS, true ) ? self::query( 'endpoint' ) : '',
			'user_id'  => absint( self::query( 'user_id' ) ),
			'blog_id'  => $network ? absint( self::query( 'blog_id' ) ) : 0,
			'status'   => in_array( self::query( 'status' ), [ 'ok', 'errors' ], true ) ? self::query( 'status' ) : '',
			'from'     => 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', self::query( 'from' ) ) ? self::query( 'from' ) : '',
			'to'       => 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', self::query( 'to' ) ) ? self::query( 'to' ) : '',
		];
		$page    = max( 1, absint( self::query( 'page' ) ) );
		$result  = $this->plugin->audit()->query( $filters, $page, self::AUDIT_PER_PAGE );

		$sites = [];
		if ( $network ) {
			foreach ( get_sites( [ 'number' => 1000 ] ) as $site ) {
				$sites[ (string) $site->blog_id ] = $site->domain . $site->path;
			}
		}
		$logins = [];
		$rows   = [];
		foreach ( $result['rows'] as $row ) {
			$uid = (int) $row->user_id;
			if ( ! isset( $logins[ $uid ] ) ) {
				$user           = $uid > 0 ? get_userdata( $uid ) : false;
				$logins[ $uid ] = $user ? $user->user_login : ( $uid > 0 ? '#' . $uid : '' );
			}
			$paths  = json_decode( (string) $row->paths, true );
			$rows[] = [
				'id'         => (int) $row->id,
				'ts'         => (string) $row->ts,
				'site'       => $network ? ( $sites[ (string) ( $row->blog_id ?? 1 ) ] ?? '#' . (int) ( $row->blog_id ?? 1 ) ) : '',
				'user'       => $logins[ $uid ],
				'ip'         => (string) $row->ip,
				'endpoint'   => (string) $row->endpoint,
				'mode'       => (string) $row->mode,
				'paths'      => is_array( $paths ) ? array_values( array_map( 'strval', $paths ) ) : [],
				'bytes'      => (int) $row->bytes,
				'status'     => (int) $row->status,
				'durationMs' => (int) $row->duration_ms,
				'releaseId'  => (string) $row->release_id,
			];
		}
		return [
			'rows'  => $rows,
			'total' => $result['total'],
			'pages' => max( 1, (int) ceil( $result['total'] / self::AUDIT_PER_PAGE ) ),
			'page'  => $page,
			'sites' => $network ? $sites : null,
		];
	}
}
