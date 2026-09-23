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
use Lab591\DevBridge\Settings;
use const Lab591\DevBridge\PLUGIN_FILE;

final class AdminPage {

	public const SLUG = 'devbridge';

	private const NOTICE_KEY = 'devbridge_notice_';

	public function __construct( private readonly Plugin $plugin ) {
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_post_devbridge_mode', [ $this, 'handleMode' ] );
		add_action( 'admin_post_devbridge_settings', [ $this, 'handleSettings' ] );
		add_action( 'admin_notices', [ $this, 'pluginsScreenNotices' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), [ $this, 'confirmDeactivation' ] );
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
		if ( null === $screen || 'plugins' !== $screen->id || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( RescueInstaller::INSTALLED !== $this->plugin->rescueInstaller()->state() ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html( 'Dev Bridge: il mu-plugin di rescue non è installato, il rollback fuori banda non è disponibile.' ),
				esc_url( add_query_arg( [ 'page' => self::SLUG ], admin_url( 'options-general.php' ) ) ),
				esc_html( 'Dettagli' )
			);
		}
	}

	public function menu(): void {
		add_options_page( 'Dev Bridge', 'Dev Bridge', 'manage_options', self::SLUG, [ $this, 'render' ] );
	}

	// ------------------------------------------------------------------ actions

	public function handleMode(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
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
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'lab591-dev-bridge' ), 403 );
		}
		check_admin_referer( 'devbridge_settings' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated field by field in SettingsForm.
		$input = isset( $_POST['devbridge'] ) && is_array( $_POST['devbridge'] ) ? wp_unslash( $_POST['devbridge'] ) : [];
		$form  = $this->form();
		$clean = $form->sanitize( $input, $this->plugin->settings()->all() );
		$this->plugin->settings()->save( $clean );
		$this->plugin->reset();
		if ( [] !== $form->errors() ) {
			$this->notice( 'warning', "Impostazioni salvate con alcune voci scartate:\n" . implode( "\n", $form->errors() ) );
		} else {
			$this->notice( 'success', 'Impostazioni salvate.' );
		}
		$this->redirect( 'settings' );
	}

	public function form(): SettingsForm {
		$plugin = $this->plugin;
		return new SettingsForm(
			static fn ( bool $mu ) => new \Lab591\DevBridge\Security\WritableRootValidator( ABSPATH, $plugin->protectedPaths(), $mu, $plugin->denyPatterns() ),
			ABSPATH,
			(string) wp_parse_url( home_url(), PHP_URL_HOST )
		);
	}

	private function notice( string $type, string $message ): void {
		set_transient( self::NOTICE_KEY . get_current_user_id(), [ $type, $message ], 60 );
	}

	private function redirect( string $tab ): void {
		wp_safe_redirect(
			add_query_arg(
				[
					'page' => self::SLUG,
					'tab'  => $tab,
				],
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	// ------------------------------------------------------------------ rendering

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
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
						admin_url( 'options-general.php' )
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
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
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

	private function renderSettings(): void {
		$s = $this->plugin->settings()->all();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'devbridge_settings' );
		echo '<input type="hidden" name="action" value="devbridge_settings"><table class="form-table" role="presentation"><tbody>';

		$admins = get_users(
			[
				'capability' => 'manage_options',
				'fields'     => [ 'ID', 'user_login' ],
			]
		);
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
		echo '<p class="description">Solo amministratori, autenticati con Application Password.</p></td></tr>';

		$this->textarea( 'writable_roots', 'Cartelle scrivibili', (array) $s['writable_roots'], 'Una per riga, relative ad ABSPATH, sotto wp-content/themes/ o wp-content/plugins/ (es. wp-content/themes/mio-child).' );
		printf(
			'<tr><th>mu-plugins</th><td><label><input type="checkbox" name="devbridge[allow_mu_plugins]" value="1"%s> Consenti cartelle scrivibili sotto wp-content/mu-plugins/</label></td></tr>',
			checked( (bool) $s['allow_mu_plugins'], true, false )
		);
		$this->textarea( 'read_roots', 'Cartelle leggibili', array_map( static fn ( $r ) => '' === $r ? '.' : $r, (array) $s['read_roots'] ), '"." indica l\'intero sito (ABSPATH).' );
		$this->textarea( 'deny_patterns', 'Deny list (glob)', (array) $s['deny_patterns'], 'Applicata dopo la risoluzione dei percorsi. wp-config, .env, .git, .htpasswd e lo storage sono sempre esclusi.' );
		$this->textarea( 'write_extensions', 'Estensioni scrivibili', (array) $s['write_extensions'], 'Separate da virgola o una per riga.', true );
		$this->textarea( 'ip_allowlist', 'Allowlist IP', (array) $s['ip_allowlist'], 'Vuota = nessuna restrizione. IPv4/IPv6 o CIDR.' );
		$this->textarea( 'trusted_proxies', 'Proxy fidati', (array) $s['trusted_proxies'], 'X-Forwarded-For viene usato solo se la richiesta arriva da questi indirizzi.' );
		$this->textarea( 'grep_skip_dirs', 'Cartelle escluse dal grep', (array) $s['grep_skip_dirs'], '', true );
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$filters = [
			'endpoint' => isset( $_GET['endpoint'] ) ? sanitize_text_field( wp_unslash( $_GET['endpoint'] ) ) : '',
			'user_id'  => isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0,
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

		echo '<form method="get" style="margin:1em 0"><input type="hidden" name="page" value="devbridge"><input type="hidden" name="tab" value="audit">';
		echo '<select name="endpoint"><option value="">Tutti gli endpoint</option>';
		foreach ( [ '/status', '/list', '/read', '/grep', '/manifest', '/archive', '/log', '/deploy', '/rollback', '/releases', '/health', '/cache-flush' ] as $endpoint ) {
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $endpoint ), selected( $filters['endpoint'], $endpoint, false ) );
		}
		echo '</select> ';
		printf( '<input type="number" name="user_id" placeholder="ID utente" value="%s" class="small-text"> ', $filters['user_id'] ? (int) $filters['user_id'] : '' );
		printf(
			'<select name="status"><option value="">Tutti gli esiti</option><option value="ok"%s>OK</option><option value="errors"%s>Errori</option></select> ',
			selected( $filters['status'], 'ok', false ),
			selected( $filters['status'], 'errors', false )
		);
		printf( '<input type="date" name="from" value="%s"> <input type="date" name="to" value="%s"> ', esc_attr( $filters['from'] ), esc_attr( $filters['to'] ) );
		submit_button( 'Filtra', 'secondary', '', false );
		echo '</form>';

		echo '<table class="widefat striped"><thead><tr><th>Data (UTC)</th><th>Utente</th><th>IP</th><th>Endpoint</th><th>Modalità</th><th>Percorsi</th><th>Byte</th><th>Esito</th><th>ms</th><th>Release</th></tr></thead><tbody>';
		if ( [] === $result['rows'] ) {
			echo '<tr><td colspan="10">Nessuna voce.</td></tr>';
		}
		foreach ( $result['rows'] as $row ) {
			$paths = json_decode( (string) $row->paths, true );
			printf(
				'<tr><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td><code>%s</code></td><td>%d</td><td>%d</td><td>%d</td><td>%s</td></tr>',
				esc_html( (string) $row->ts ),
				(int) $row->user_id,
				esc_html( (string) $row->ip ),
				esc_html( (string) $row->endpoint ),
				esc_html( (string) $row->mode ),
				esc_html( is_array( $paths ) ? implode( ', ', $paths ) : '' ),
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
