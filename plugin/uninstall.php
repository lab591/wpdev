<?php
/**
 * Uninstall: removes options, audit table and transients (SPEC 2.13).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/Autoloader.php';
Lab591\DevBridge\Autoloader::register( __DIR__ . '/src' );

( new Lab591\DevBridge\Audit\AuditLog() )->drop();

delete_option( Lab591\DevBridge\Settings::OPTION );
delete_option( Lab591\DevBridge\Mode::OPTION );
wp_clear_scheduled_hook( Lab591\DevBridge\Plugin::CRON_AUDIT );

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off cleanup of our own transients.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_devbridge_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_devbridge_' ) . '%'
	)
);
