<?php
/**
 * E2E: turns WP Super Cache on (simple mode, PHP-served cache) or off, as its settings page would.
 * Run with: wp eval-file wp-content/e2e-fixtures/supercache.php on|off
 *
 * @package Lab591\DevBridge
 */

$devbridge_e2e_on = 'off' !== ( $args[0] ?? 'on' );
if ( ! function_exists( 'wp_cache_enable' ) ) {
	echo "WPSC_MISSING\n";
	return;
}
if ( $devbridge_e2e_on ) {
	wp_cache_verify_config_file();
	wp_cache_create_advanced_cache();
	wp_cache_enable();
	wp_cache_setting( 'wp_cache_mod_rewrite', 0 );
	wp_cache_setting( 'cache_compression', 0 );
} else {
	wp_cache_disable();
}
if ( function_exists( 'wp_cache_clear_cache' ) ) {
	wp_cache_clear_cache();
}
echo $devbridge_e2e_on ? "WPSC_ON\n" : "WPSC_OFF\n";
