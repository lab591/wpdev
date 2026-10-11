<?php
/**
 * E2E: settings import (WP-CLI) and "keep settings" across an uninstall.
 * Run with: wp eval-file wp-content/e2e-fixtures/keep-settings.php <keep|forget|state>
 *
 * - keep|forget: exports the settings, sets keep_on_uninstall (and retention_releases 7|10) in the
 *   export and imports it back;
 * - state: prints whether the settings and the mode option exist.
 *
 * @package Lab591\DevBridge
 */

$devbridge_e2e_action = $args[0] ?? 'state';

if ( 'state' === $devbridge_e2e_action ) {
	$devbridge_e2e_settings = get_option( 'devbridge_settings' );
	echo 'SETTINGS=' . ( is_array( $devbridge_e2e_settings )
		? 'kept retention=' . (int) $devbridge_e2e_settings['retention_releases'] . ' keep=' . ( empty( $devbridge_e2e_settings['keep_on_uninstall'] ) ? 'no' : 'yes' )
		: 'none' ) . "\n";
	echo 'MODE=' . ( false === get_option( 'devbridge_mode' ) ? 'none' : 'present' ) . "\n";
	return;
}

$devbridge_e2e_doc = json_decode( WP_CLI::runcommand( 'devbridge settings export', array( 'return' => true ) ), true );
$devbridge_e2e_doc['settings']['keep_on_uninstall']  = 'keep' === $devbridge_e2e_action;
$devbridge_e2e_doc['settings']['retention_releases'] = 'keep' === $devbridge_e2e_action ? 7 : 10;
$devbridge_e2e_file = tempnam( sys_get_temp_dir(), 'devbridge-e2e' );
file_put_contents( $devbridge_e2e_file, wp_json_encode( $devbridge_e2e_doc ) );
WP_CLI::runcommand( 'devbridge settings import ' . escapeshellarg( $devbridge_e2e_file ) );
unlink( $devbridge_e2e_file );
