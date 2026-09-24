<?php
/**
 * E2E: sets the database access level of Dev Bridge (off|schema|read) and the test options.
 * Run with: wp eval-file wp-content/e2e-fixtures/db-access.php <level>
 *
 * @package Lab591\DevBridge
 */

$devbridge_e2e_level      = $args[0] ?? 'off';
$devbridge_e2e_settings   = (array) get_option( 'devbridge_settings', array() );
$devbridge_e2e_settings['db_access']          = $devbridge_e2e_level;
$devbridge_e2e_settings['db_redact_personal'] = true;
update_option( 'devbridge_settings', $devbridge_e2e_settings );

if ( 'off' === $devbridge_e2e_level ) {
	delete_option( 'e2e_smtp_password' );
	delete_option( 'e2e_integration' );
} else {
	update_option( 'e2e_smtp_password', 'tok-SECRET-VALUE' );
	update_option( 'e2e_integration', wp_json_encode( array( 'host' => 'api.example', 'api_key' => 'AK-NESTED-VALUE' ) ) );
}
echo 'DB_ACCESS=' . esc_html( $devbridge_e2e_level ) . "\n";
