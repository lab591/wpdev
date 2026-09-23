<?php
/**
 * Admin page "Impostazioni > Dev Bridge": mode, settings, audit log.
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
use Lab591\DevBridge\Settings;
use Lab591\DevBridge\Support\Options;
use const Lab591\DevBridge\PLUGIN_FILE;

final class AdminPage {

	public const SLUG = 'devbridge';

	private const NOTICE_KEY = 'devbridge_notice_';

	public function __construct( private readonly Plugin $plugin ) {
	}

	public function register(): void {
		add_action( Options::network() ? 'network_admin_menu' : 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_post_devbridge_mode', [ $this, 'handleMode' ] );
		add_action( 'admin_post_devbridge_settings', [ $this, 'handleSettings' ] );
		add_action( 'wp_ajax_devbridge_folders', [ $this, 'ajaxFolders' ] );
		add_action( Options::network() ? 'network_admin_notices' : 'admin_notices', [ $this, 'pluginsScreenNotices' ] );
		add_filter( ( Options::network() ? 'network_admin_plugin_action_links_' : 'plugin_action_links_' ) . plugin_basename( PLUGIN_FILE ), [ $this, 'confirmDeactivation' ] );
	}

	/**
	 * Asks for confirmation before deactivating when release backups exist.
	 *
	 * @param array<string, string> $links
	 * @return array<string, string>
	 */
	public function confirmDeactivation( array $links ): array {
		$count = count( ( new ReleaseStore( $this->plugin->storage()->releasesDir() ) )->all() );
		if ( $count > 0 && isset( $links['deactivate'] ) ) {
			$message             = sprintf( 'Esistono %d release di backup di Dev Bridge. Disattivando si spegne anche il rescue; eliminando il plugin i backup verranno cancellati. Continuare?', $count );
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
				esc_html( 'Dev Bridge: il mu-plugin di rescue non è installato, il rollback fuori banda non è disponibile.' ),
				esc_url( self::pageUrl() ),
				esc_html( 'Dettagli' )
			);
		}
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
		if ( Options::network() ) {
			add_submenu_page( 'settings.php', 'Dev Bridge', 'Dev Bridge', Options::capability(), self::SLUG, [ $this, 'render' ] );
			return;
		}
		add_options_page( 'Dev Bridge', 'Dev Bridge', Options::capability(), self::SLUG, [ $this, 'render' ] );
	}

	// ------------------------------------------------------------------ actions

	public function handleMode(): void {
		if ( ! current_user_can( Options::capability() ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'lab591-dev-bridge' ), 403 );
		}
		check_admin_referer( 'devbridge_mode' );
		$mode   = $this->plugin->mode();
		$action = isset( $_POST['devbridge_action'] ) ? sanitize_key( wp_unslash( $_POST['devbridge_action'] ) ) : '';
		if ( 'disable' === $action ) {
			$mode->disable();
			$this->notice( 'success', 'Modalità sviluppo disattivata.' );
		} else {
			$requested = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
			$hours     = isset( $_POST['hours'] ) ? absint( wp_unslash( $_POST['hours'] ) ) : 0;
			try {
				$state = $mode->enable( $requested, $hours, get_current_user_id() );
				$this->notice( 'success', sprintf( 'Modalità "%s" attiva fino a %s.', $state['mode'], wp_date( 'd/m/Y H:i', $state['expires_at'] ) ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->notice( 'error', $e->getMessage() );
			}
		}
		$this->redirect( 'status' );
	}

	public function handleSettings(): void {
		if ( ! current_user_can( Options::capability() ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'lab591-dev-bridge' ), 403 );
		}
		check_admin_referer( 'devbridge_settings' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated field by field in SettingsForm.
		$input                     = isset( $_POST['devbridge'] ) && is_array( $_POST['devbridge'] ) ? wp_unslash( $_POST['devbridge'] ) : [];
		$form                      = $this->form();
		$clean                     = $form->sanitize( $input, $this->plugin->settings()->all() );
		$clean['allowed_user_ids'] = array_values( array_filter( $clean['allowed_user_ids'], static fn ( int $id ): bool => user_can( $id, Options::capability() ) ) );
		$errors                    = $form->errors();

		// Optional new empty folder, created and selected together with the save.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated segment by segment in FolderCreator.
		$new     = isset( $_POST['devbridge_new_folder'] ) && is_array( $_POST['devbridge_new_folder'] ) ? wp_unslash( $_POST['devbridge_new_folder'] ) : [];
		$created = '';
		if ( '' !== trim( (string) ( $new['path'] ?? '' ) ) ) {
			try {
				$created                 = ( new FolderCreator( $this->plugin->rootValidator() ) )->create( (string) ( $new['container'] ?? '' ), (string) $new['path'] );
				$clean['writable_roots'] = SettingsForm::withoutNested( array_values( array_unique( [ ...$clean['writable_roots'], $created ] ) ) );
			} catch ( PathException $e ) {
				$errors[] = sprintf( 'Nuova cartella non creata: %s', $e->getMessage() );
			}
		}

		$this->plugin->settings()->save( $clean );
		$this->plugin->reset();
		$message = [] !== $errors ? "Impostazioni salvate con alcune voci scartate:\n" . implode( "\n", $errors ) : 'Impostazioni salvate.';
		if ( '' !== $created ) {
			$message .= "\n" . sprintf( 'Cartella %s pronta e aggiunta alle cartelle scrivibili: esegui "wpdev pull" nel progetto locale.', $created );
		}
		$this->notice( [] !== $errors ? 'warning' : 'success', $message );
		$this->redirect( 'settings' );
	}

	public function form(): SettingsForm {
		$plugin = $this->plugin;
		return new SettingsForm(
			static fn ( bool $mu ) => new \Lab591\DevBridge\Security\WritableRootValidator( ABSPATH, $plugin->protectedPaths(), $mu, $plugin->denyPatterns() ),
			ABSPATH,
			$this->allowedHosts()
		);
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

	private function notice( string $type, string $message ): void {
		Options::setTransient( self::NOTICE_KEY . get_current_user_id(), [ $type, $message ], 60 );
	}

	private function redirect( string $tab ): void {
		wp_safe_redirect(
			add_query_arg(
				[
					'page' => self::SLUG,
					'tab'  => $tab,
				],
				self::baseUrl()
			)
		);
		exit;
	}

	// ------------------------------------------------------------------ rendering

	public function render(): void {
		if ( ! current_user_can( Options::capability() ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'status';
		$tabs = [
			'status'   => 'Stato',
			'settings' => 'Impostazioni',
			'audit'    => 'Audit log',
		];
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'status';
		}
		echo '<div class="wrap"><h1>Dev Bridge</h1>';
		$this->renderNotice();
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url(
					add_query_arg(
						[
							'page' => self::SLUG,
							'tab'  => $key,
						],
						self::baseUrl()
					)
				),
				$key === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
		match ( $tab ) {
			'settings' => $this->renderSettings(),
			'audit'    => $this->renderAudit(),
			default    => $this->renderStatus(),
		};
		echo '</div>';
	}

	private function renderNotice(): void {
		$key    = self::NOTICE_KEY . get_current_user_id();
		$notice = Options::getTransient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		Options::deleteTransient( $key );
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( (string) $notice[0] ),
			nl2br( esc_html( (string) $notice[1] ) )
		);
	}

	private function renderStatus(): void {
		$mode     = $this->plugin->mode();
		$state    = $mode->state();
		$settings = $this->plugin->settings();
		$roots    = $this->plugin->validWritableRoots();

		echo '<h2>Modalità sviluppo</h2><table class="form-table" role="presentation"><tbody>';
		printf( '<tr><th>Stato attuale</th><td><strong>%s</strong>', esc_html( strtoupper( $state['mode'] ) ) );
		if ( Mode::OFF !== $state['mode'] ) {
			printf( ' &mdash; scade il %s', esc_html( wp_date( 'd/m/Y H:i', $state['expires_at'] ) ) );
		}
		echo '</td></tr>';
		printf( '<tr><th>Endpoint REST</th><td><code>%s</code></td></tr>', esc_html( rest_url( 'devbridge/v1/' ) ) );
		printf(
			'<tr><th>Cartelle scrivibili valide</th><td>%s</td></tr>',
			[] === $roots ? '<em>nessuna</em>' : '<code>' . implode( '</code><br><code>', array_map( 'esc_html', $roots ) ) . '</code>'
		);
		echo '</tbody></table>';

		$warnings = [];
		if ( [] === $settings->allowedUserIds() ) {
			$warnings[] = 'Nessun utente autorizzato: configura "Utenti autorizzati" nelle impostazioni.';
		}
		if ( ! is_ssl() && 'local' !== wp_get_environment_type() ) {
			$warnings[] = 'Il sito non è in HTTPS: le richieste verranno rifiutate.';
		}
		if ( ! wp_is_application_passwords_available() ) {
			$warnings[] = 'Le Application Password non sono disponibili su questo sito.';
		}
		foreach ( $warnings as $warning ) {
			printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html( $warning ) );
		}
		$this->renderRescueAndStorage();

		echo '<h2>Attiva</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'devbridge_mode' );
		echo '<input type="hidden" name="action" value="devbridge_mode"><input type="hidden" name="devbridge_action" value="enable">';
		printf(
			'<p><label>Modalità <select name="mode"><option value="read">read (max %1$d h)</option><option value="write">write (max %2$d h)</option></select></label> ',
			(int) $mode->maxHours( Mode::READ ),
			(int) $mode->maxHours( Mode::WRITE )
		);
		printf( '<label>per <input type="number" name="hours" min="1" max="%d" value="4" class="small-text"> ore</label> ', (int) $mode->maxHours( Mode::READ ) );
		submit_button( 'Attiva', 'primary', 'submit', false );
		echo '</p></form>';

		if ( Mode::OFF !== $state['mode'] ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'devbridge_mode' );
			echo '<input type="hidden" name="action" value="devbridge_mode"><input type="hidden" name="devbridge_action" value="disable">';
			submit_button( 'Disattiva ora', 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '<p class="description">La modalità si può cambiare solo da qui o con <code>wp devbridge enable|disable</code>: nessun endpoint REST può modificarla.</p>';

		$this->renderReleases();
	}

	private function renderRescueAndStorage(): void {
		$installer = $this->plugin->rescueInstaller();
		$state     = $installer->state();
		if ( RescueInstaller::INSTALLED !== $state ) {
			echo '<div class="notice notice-warning inline"><p><strong>Rescue fuori banda non installato.</strong> ';
			if ( $installer->canWriteDirectly() ) {
				echo esc_html( 'Verrà installato automaticamente al prossimo caricamento di una pagina di amministrazione.' );
			} else {
				printf(
					'%s <code>%s</code> %s <code>%s</code> %s',
					esc_html( 'La cartella mu-plugins non è scrivibile direttamente (o FS_METHOD non è "direct"). Copia manualmente il file' ),
					esc_html( 'wp-content/plugins/' . dirname( plugin_basename( PLUGIN_FILE ) ) . '/mu-plugin/' . RescueInstaller::FILE_NAME ),
					esc_html( 'in' ),
					esc_html( 'wp-content/mu-plugins/' . RescueInstaller::FILE_NAME ),
					esc_html( '(permessi 0644).' )
				);
			}
			echo '</p></div>';
		}

		$storage = $this->plugin->storage();
		if ( $storage->isFallback() ) {
			echo '<div class="notice notice-info inline"><p>';
			echo esc_html( 'Lo storage privato è dentro wp-content (cartella con nome casuale, protetta da .htaccess). È consigliato spostarlo fuori dalla document root definendo in wp-config.php:' );
			echo '<br><code>define( \'DEVBRIDGE_STORAGE_DIR\', \'/percorso/fuori/webroot/devbridge\' );</code><br>';
			echo esc_html( 'Su Nginx .htaccess non viene letto: aggiungi alla configurazione del sito:' );
			printf( '<br><code>location ~ ^/wp-content/%s/ { deny all; return 404; }</code>', esc_html( basename( $storage->dir() ) ) );
			echo '</p></div>';
		}
	}

	private function renderReleases(): void {
		$releases = array_slice( ( new ReleaseStore( $this->plugin->storage()->releasesDir() ) )->all(), 0, 20 );
		echo '<h2>Release</h2>';
		if ( [] === $releases ) {
			echo '<p>Nessuna release.</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Data</th><th>Utente</th><th>Scritti</th><th>Cancellati</th><th>Esito</th></tr></thead><tbody>';
		foreach ( $releases as $release ) {
			printf(
				'<tr><td><code>%s</code></td><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%s</td></tr>',
				esc_html( (string) $release['id'] ),
				esc_html( wp_date( 'd/m/Y H:i', (int) $release['created_at'] ) ),
				(int) $release['user_id'],
				(int) $release['written'],
				(int) $release['deleted'],
				esc_html( (string) $release['status'] )
			);
		}
		echo '</tbody></table><p class="description">Rollback: <code>wp devbridge rollback [&lt;id&gt;]</code> oppure <code>wpdev rollback</code> dal companion.</p>';
	}

	/**
	 * Users that may be authorized: administrators, or super admins on a network.
	 *
	 * @return list<object>
	 */
	private function eligibleUsers(): array {
		if ( ! Options::network() ) {
			return array_values(
				get_users(
					[
						'capability' => 'manage_options',
						'fields'     => [ 'ID', 'user_login' ],
					]
				)
			);
		}
		$users = [];
		foreach ( get_super_admins() as $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user ) {
				$users[] = (object) [
					'ID'         => $user->ID,
					'user_login' => $user->user_login,
				];
			}
		}
		return $users;
	}

	private function renderSettings(): void {
		$s = $this->plugin->settings()->all();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'devbridge_settings' );
		echo '<input type="hidden" name="action" value="devbridge_settings"><table class="form-table" role="presentation"><tbody>';

		$admins = $this->eligibleUsers();
		echo '<tr><th>Utenti autorizzati</th><td>';
		foreach ( $admins as $user ) {
			printf(
				'<label><input type="checkbox" name="devbridge[allowed_user_ids][]" value="%d"%s> %s (#%d)</label><br>',
				(int) $user->ID,
				checked( in_array( (int) $user->ID, array_map( 'intval', (array) $s['allowed_user_ids'] ), true ), true, false ),
				esc_html( $user->user_login ),
				(int) $user->ID
			);
		}
		echo '<p class="description">' . esc_html( Options::network() ? 'Solo super admin della rete, autenticati con Application Password.' : 'Solo amministratori, autenticati con Application Password.' ) . '</p></td></tr>';

		$this->renderFolderPicker( array_map( 'strval', (array) $s['writable_roots'] ) );
		printf(
			'<tr><th>mu-plugins</th><td><label><input type="checkbox" name="devbridge[allow_mu_plugins]" value="1"%s> Consenti cartelle scrivibili sotto wp-content/mu-plugins/</label><p class="description">Dopo averla attivata, salva per vedere le cartelle di mu-plugins nell\'elenco.</p></td></tr>',
			checked( (bool) $s['allow_mu_plugins'], true, false )
		);
		$this->textarea( 'read_roots', 'Cartelle leggibili', array_map( static fn ( $r ) => '' === $r ? '.' : $r, (array) $s['read_roots'] ), '"." indica l\'intero sito (ABSPATH).' );
		$this->textarea( 'deny_patterns', 'Deny list (glob)', (array) $s['deny_patterns'], 'Applicata dopo la risoluzione dei percorsi. wp-config, .env, .git, .htpasswd e lo storage sono sempre esclusi.' );
		$this->textarea( 'write_extensions', 'Estensioni scrivibili', (array) $s['write_extensions'], 'Separate da virgola o una per riga.', true );
		$this->textarea( 'ip_allowlist', 'Allowlist IP', (array) $s['ip_allowlist'], 'Vuota = nessuna restrizione. IPv4/IPv6 o CIDR.' );
		$this->textarea( 'trusted_proxies', 'Proxy fidati', (array) $s['trusted_proxies'], 'X-Forwarded-For viene usato solo se la richiesta arriva da questi indirizzi.' );
		$this->textarea( 'grep_skip_dirs', 'Cartelle escluse dal grep', (array) $s['grep_skip_dirs'], '', true );
		printf(
			'<tr><th><label for="devbridge-grep_rg">%1$s</label></th><td><input type="text" id="devbridge-grep_rg" name="devbridge[grep_rg]" value="%2$s" class="regular-text code"><p class="description">%3$s</p></td></tr>',
			esc_html( 'Accelerazione ripgrep' ),
			esc_attr( (string) ( $s['grep_rg'] ?? '' ) ),
			esc_html( 'Facoltativa, disattivata se vuota. "rg" (dal PATH) o percorso assoluto di rg/rg.exe. I risultati passano comunque da PathGuard e dalla deny list; se rg non è eseguibile si usa la ricerca PHP.' )
		);
		$this->textarea( 'health_urls', 'URL di health check', (array) $s['health_urls'], 'Vuoto = home page. Solo URL dello stesso host.' );

		$this->number( 'retention_releases', 'Release conservate', (int) $s['retention_releases'] );
		$this->number( 'audit_retention_days', 'Giorni di audit log', (int) $s['audit_retention_days'] );
		$this->number( 'max_read_hours', 'Durata massima read (h)', (int) $s['max_read_hours'] );
		$this->number( 'max_write_hours', 'Durata massima write (h)', (int) $s['max_write_hours'] );
		foreach ( Settings::DEFAULT_LIMITS as $key => $default ) {
			$this->number( 'limits][' . $key, 'Limite ' . $key, (int) ( $s['limits'][ $key ] ?? $default ) );
		}
		echo '</tbody></table>';
		submit_button( 'Salva impostazioni' );
		echo '</form>';
	}

	// ------------------------------------------------------------------ writable folders picker

	private function folderPicker(): FolderPicker {
		return new FolderPicker( $this->plugin->rootValidator(), [ 'wp-content/plugins/' . dirname( plugin_basename( PLUGIN_FILE ) ) ] );
	}

	/**
	 * Admin-ajax: sub-folders of a container or of a selectable folder (read-only listing).
	 */
	public function ajaxFolders(): void {
		if ( ! current_user_can( Options::capability() ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'devbridge_folders' );
		$path = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : '';
		try {
			wp_send_json_success( $this->folderPicker()->children( $path ) );
		} catch ( PathException ) {
			wp_send_json_error( null, 400 );
		}
	}

	/**
	 * Theme and plugin names by folder, shown next to the folder names.
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
				$labels[ $key ] = trim( ( $labels[ $key ] ?? '' ) . ' · tema attivo', ' ·' );
			}
		}
		return $labels;
	}

	/**
	 * @param string[] $selected Saved writable roots.
	 */
	private function renderFolderPicker( array $selected ): void {
		$picker = $this->folderPicker();
		$labels = $this->folderLabels();
		echo '<tr><th>Cartelle scrivibili</th><td>';
		echo '<style>#devbridge-folders{max-height:30em;overflow:auto;border:1px solid #dcdcde;background:#fff;padding:.4em 1em}#devbridge-folders ul{margin:.2em 0 .2em 1.8em}#devbridge-folders>ul{margin-left:0}#devbridge-folders li{margin:.2em 0}#devbridge-folders .devbridge-container{margin:.6em 0 .2em;font-weight:600}</style>';
		printf(
			'<div id="devbridge-folders" data-ajax="%s" data-nonce="%s">',
			esc_url( admin_url( 'admin-ajax.php' ) ),
			esc_attr( wp_create_nonce( 'devbridge_folders' ) )
		);
		foreach ( $picker->containers() as $container ) {
			printf( '<p class="devbridge-container">%s/</p>', esc_html( $container ) );
			try {
				$this->renderFolderItems( $picker, $container, $selected, $labels );
			} catch ( PathException ) {
				echo '<p class="description">Cartella non disponibile.</p>';
			}
		}
		echo '</div>';
		$valid   = array_map( 'strtolower', $this->plugin->validWritableRoots() );
		$invalid = array_filter( $selected, static fn ( string $root ): bool => ! in_array( strtolower( $root ), $valid, true ) );
		if ( [] !== $invalid ) {
			printf( '<p class="description"><strong>Cartelle salvate non più valide, verranno rimosse al salvataggio:</strong> %s</p>', esc_html( implode( ', ', $invalid ) ) );
		}
		echo '<p class="description">Scegli il tema o plugin su cui lavorare (o solo alcune sue sottocartelle). Le cartelle intere themes/ e plugins/ e quella di Dev Bridge non sono selezionabili. Se selezioni una cartella, le sue sottocartelle sono già incluse.</p>';

		echo '<p style="margin-top:1em"><label for="devbridge-new-folder"><strong>Nuova cartella</strong></label><br><select name="devbridge_new_folder[container]" aria-label="Dove">';
		foreach ( $picker->containers() as $container ) {
			printf( '<option value="%1$s">%1$s/</option>', esc_attr( $container ) );
		}
		echo '</select> <input type="text" id="devbridge-new-folder" name="devbridge_new_folder[path]" class="regular-text code" placeholder="mio-plugin oppure mio-tema/blocks"> ';
		echo '<button type="submit" class="button">Crea e seleziona</button></p>';
		echo '<p class="description">Crea una cartella vuota (anche annidata) e la aggiunge alle cartelle scrivibili; salva anche le altre impostazioni. Dopo un <code>wpdev pull</code> puoi chiedere a Claude, per esempio, di inizializzare lì un nuovo plugin o un tema figlio.</p>';
		wp_print_inline_script_tag( self::folderPickerScript() );
		echo '</td></tr>';
	}

	/**
	 * @param string[]              $selected
	 * @param array<string, string> $labels
	 * @throws PathException When the parent cannot be listed.
	 */
	private function renderFolderItems( FolderPicker $picker, string $folder, array $selected, array $labels ): void {
		$list  = $picker->children( $folder );
		$lower = array_map( 'strtolower', $selected );
		echo '<ul>';
		foreach ( $list['items'] as $item ) {
			$path = strtolower( $item['path'] );
			$open = false;
			foreach ( $lower as $root ) {
				$open = $open || str_starts_with( $root, $path . '/' );
			}
			$note = '' !== $item['reason'] ? $item['reason'] : ( $labels[ $item['path'] ] ?? '' );
			printf(
				'<li><label><input type="checkbox" name="devbridge[writable_roots][]" value="%s"%s%s> <code>%s</code>%s</label>',
				esc_attr( $item['path'] ),
				checked( in_array( $path, $lower, true ), true, false ),
				disabled( ! $item['selectable'], true, false ),
				esc_html( $item['name'] ),
				'' === $note ? '' : ' <span class="description">— ' . esc_html( $note ) . '</span>'
			);
			if ( $item['expandable'] ) {
				printf(
					' <button type="button" class="button-link devbridge-expand" aria-expanded="%s" data-path="%s">%s</button>',
					$open ? 'true' : 'false',
					esc_attr( $item['path'] ),
					esc_html( $open ? 'nascondi sottocartelle' : 'sottocartelle' )
				);
				if ( $open ) {
					$this->renderFolderItems( $picker, $item['path'], $selected, $labels );
				} else {
					echo '<ul hidden data-lazy="1"></ul>';
				}
			}
			echo '</li>';
		}
		if ( $list['truncated'] ) {
			echo '<li class="description">… elenco troncato</li>';
		}
		echo '</ul>';
	}

	/**
	 * Lazy expansion of sub-folders; DOM built with textContent only.
	 */
	private static function folderPickerScript(): string {
		return <<<'JS'
(function () {
	var box = document.getElementById('devbridge-folders');
	if (!box) { return; }
	function item(it) {
		var li = document.createElement('li'), label = document.createElement('label'), cb = document.createElement('input'), code = document.createElement('code');
		cb.type = 'checkbox'; cb.name = 'devbridge[writable_roots][]'; cb.value = it.path; cb.disabled = !it.selectable;
		code.textContent = it.name;
		label.appendChild(cb); label.appendChild(document.createTextNode(' ')); label.appendChild(code);
		if (it.reason) { var s = document.createElement('span'); s.className = 'description'; s.textContent = ' — ' + it.reason; label.appendChild(s); }
		li.appendChild(label);
		if (it.expandable) {
			var b = document.createElement('button'), ul = document.createElement('ul');
			b.type = 'button'; b.className = 'button-link devbridge-expand'; b.dataset.path = it.path; b.setAttribute('aria-expanded', 'false'); b.textContent = 'sottocartelle';
			ul.hidden = true; ul.dataset.lazy = '1';
			li.appendChild(document.createTextNode(' ')); li.appendChild(b); li.appendChild(ul);
		}
		return li;
	}
	box.addEventListener('click', function (e) {
		var b = e.target.closest('.devbridge-expand');
		if (!b) { return; }
		e.preventDefault();
		var ul = b.nextElementSibling;
		function set(open) { ul.hidden = !open; b.setAttribute('aria-expanded', open ? 'true' : 'false'); b.textContent = open ? 'nascondi sottocartelle' : 'sottocartelle'; }
		if (b.getAttribute('aria-expanded') === 'true') { set(false); return; }
		if (!ul.dataset.lazy) { set(true); return; }
		b.disabled = true;
		var url = box.dataset.ajax + '?action=devbridge_folders&_ajax_nonce=' + encodeURIComponent(box.dataset.nonce) + '&path=' + encodeURIComponent(b.dataset.path);
		fetch(url, { credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res || !res.success) { throw new Error('load'); }
				res.data.items.forEach(function (it) { ul.appendChild(item(it)); });
				if (res.data.truncated) { var li = document.createElement('li'); li.className = 'description'; li.textContent = '… elenco troncato'; ul.appendChild(li); }
				delete ul.dataset.lazy;
				set(true);
			})
			.catch(function () { b.textContent = 'errore nel caricamento, riprova'; })
			.finally(function () { b.disabled = false; });
	});
})();
JS;
	}

	/**
	 * @param string[] $values
	 */
	private function textarea( string $key, string $label, array $values, string $help, bool $inline = false ): void {
		printf(
			'<tr><th><label for="devbridge-%1$s">%2$s</label></th><td><textarea id="devbridge-%1$s" name="devbridge[%1$s]" rows="%3$d" class="large-text code">%4$s</textarea>%5$s</td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			$inline ? 2 : 4,
			esc_textarea( implode( $inline ? ', ' : "\n", $values ) ),
			'' === $help ? '' : '<p class="description">' . esc_html( $help ) . '</p>'
		);
	}

	private function number( string $key, string $label, int $value ): void {
		printf(
			'<tr><th>%1$s</th><td><input type="number" min="1" name="devbridge[%2$s]" value="%3$d" class="regular-text"></td></tr>',
			esc_html( $label ),
			esc_attr( $key ),
			(int) $value
		);
	}

	private function renderAudit(): void {
		$network = Options::network();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$filters = [
			'endpoint' => isset( $_GET['endpoint'] ) ? sanitize_text_field( wp_unslash( $_GET['endpoint'] ) ) : '',
			'user_id'  => isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0,
			'blog_id'  => $network && isset( $_GET['blog_id'] ) ? absint( $_GET['blog_id'] ) : 0,
			'status'   => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'from'     => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
			'to'       => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '',
		];
		$page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		foreach ( [ 'from', 'to' ] as $key ) {
			if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters[ $key ] ) ) {
				$filters[ $key ] = '';
			}
		}
		$result = $this->plugin->audit()->query( $filters, $page );

		// Site labels (multisite): blog id => "domain/path".
		$sites = [];
		if ( $network ) {
			foreach ( get_sites( [ 'number' => 1000 ] ) as $site ) {
				$sites[ (int) $site->blog_id ] = $site->domain . $site->path;
			}
		}

		echo '<form method="get" style="margin:1em 0"><input type="hidden" name="page" value="devbridge"><input type="hidden" name="tab" value="audit">';
		echo '<select name="endpoint"><option value="">Tutti gli endpoint</option>';
		foreach ( [ '/status', '/list', '/read', '/grep', '/manifest', '/archive', '/log', '/deploy', '/rollback', '/releases', '/health', '/cache-flush' ] as $endpoint ) {
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $endpoint ), selected( $filters['endpoint'], $endpoint, false ) );
		}
		echo '</select> ';
		if ( $network ) {
			echo '<select name="blog_id"><option value="">Tutti i siti</option>';
			foreach ( $sites as $id => $label ) {
				printf( '<option value="%d"%s>%s</option>', (int) $id, selected( $filters['blog_id'], $id, false ), esc_html( $label ) );
			}
			echo '</select> ';
		}
		printf( '<input type="number" name="user_id" placeholder="ID utente" value="%s" class="small-text"> ', $filters['user_id'] ? (int) $filters['user_id'] : '' );
		printf(
			'<select name="status"><option value="">Tutti gli esiti</option><option value="ok"%s>OK</option><option value="errors"%s>Errori</option></select> ',
			selected( $filters['status'], 'ok', false ),
			selected( $filters['status'], 'errors', false )
		);
		printf( '<input type="date" name="from" value="%s"> <input type="date" name="to" value="%s"> ', esc_attr( $filters['from'] ), esc_attr( $filters['to'] ) );
		submit_button( 'Filtra', 'secondary', '', false );
		echo '</form>';

		echo '<table class="widefat striped"><thead><tr><th>Data (UTC)</th>' . ( $network ? '<th>Sito</th>' : '' ) . '<th>Utente</th><th>IP</th><th>Endpoint</th><th>Modalità</th><th>Percorsi</th><th>Byte</th><th>Esito</th><th>ms</th><th>Release</th></tr></thead><tbody>';
		if ( [] === $result['rows'] ) {
			printf( '<tr><td colspan="%d">Nessuna voce.</td></tr>', $network ? 11 : 10 );
		}
		foreach ( $result['rows'] as $row ) {
			$paths = json_decode( (string) $row->paths, true );
			$paths = is_array( $paths ) ? implode( ', ', $paths ) : '';
			$site  = '';
			if ( $network ) {
				$blog = (int) ( $row->blog_id ?? 1 );
				$site = '<td>' . esc_html( $sites[ $blog ] ?? '#' . $blog ) . '</td>';
			}
			printf(
				'<tr><td>%s</td>%s<td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%s</td></tr>',
				esc_html( (string) $row->ts ),
				$site, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				(int) $row->user_id,
				esc_html( (string) $row->ip ),
				esc_html( (string) $row->endpoint ),
				esc_html( (string) $row->mode ),
				'' === $paths ? '' : '<code>' . esc_html( $paths ) . '</code>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inline.
				(int) $row->bytes,
				(int) $row->status,
				(int) $row->duration_ms,
				esc_html( (string) $row->release_id )
			);
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $result['total'] / 50 );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				(string) paginate_links(
					[
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					]
				)
			);
			echo '</div></div>';
		}
	}
}
