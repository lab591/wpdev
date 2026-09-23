<?php
/**
 * Uninstall: removes options, audit table, transients, the rescue mu-plugin and the storage (SPEC 2.13).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

require_once __DIR__ . '/src/Autoloader.php';
Lab591\DevBridge\Autoloader::register( __DIR__ . '/src' );

( new Lab591\DevBridge\Rescue\RescueInstaller(
	__DIR__ . '/mu-plugin/' . Lab591\DevBridge\Rescue\RescueInstaller::FILE_NAME,
	defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins',
	''
) )->remove();
( new Lab591\DevBridge\Rescue\RescueInstaller(
	__DIR__ . '/mu-plugin/' . Lab591\DevBridge\Rescue\RescueInstaller::PREVIEW_FILE,
	defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins',
	'',
	Lab591\DevBridge\Rescue\RescueInstaller::PREVIEW_FILE,
	Lab591\DevBridge\Rescue\RescueInstaller::PREVIEW_MARKER
) )->remove();
// Preview copies (`*--devbridge-preview` next to themes and plugins).
foreach ( [ get_theme_root(), WP_PLUGIN_DIR ] as $devbridge_container ) {
	foreach ( (array) glob( rtrim( (string) $devbridge_container, '/\\' ) . '/*--devbridge-preview', GLOB_ONLYDIR ) as $devbridge_copy ) {
		if ( ! is_link( (string) $devbridge_copy ) ) {
			Lab591\DevBridge\Storage\Storage::removeTree( (string) $devbridge_copy );
		}
	}
}

$devbridge_has_storage = defined( 'DEVBRIDGE_STORAGE_DIR' ) || false !== Lab591\DevBridge\Support\Options::get( Lab591\DevBridge\Storage\Storage::SUFFIX_OPTION );
if ( $devbridge_has_storage ) {
	Lab591\DevBridge\Storage\Storage::fromWordPress()->destroy();
}

( new Lab591\DevBridge\Audit\AuditLog() )->drop();

foreach ( [
	Lab591\DevBridge\Settings::OPTION,
	Lab591\DevBridge\Mode::OPTION,
	Lab591\DevBridge\Storage\Storage::SUFFIX_OPTION,
	Lab591\DevBridge\Rescue\RescueInstaller::CHECK_OPTION,
] as $devbridge_option ) {
	Lab591\DevBridge\Support\Options::delete( $devbridge_option );
	delete_option( $devbridge_option ); // Leftovers of a single-site install converted to multisite.
}
if ( is_multisite() ) {
	switch_to_blog( get_main_site_id() );
	wp_clear_scheduled_hook( Lab591\DevBridge\Plugin::CRON_AUDIT );
	restore_current_blog();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off cleanup of our own network transients.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
			$wpdb->esc_like( '_site_transient_devbridge_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_devbridge_' ) . '%'
		)
	);
}
wp_clear_scheduled_hook( Lab591\DevBridge\Plugin::CRON_AUDIT );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off cleanup of our own transients.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_devbridge_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_devbridge_' ) . '%'
	)
);
