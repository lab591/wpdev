<?php
/**
 * E2E setup, run inside WordPress with `wp eval-file`: fresh copy of the test theme, Dev Bridge
 * configured for the admin user with the theme as writable folder, write mode enabled.
 * Prints the Application Password on the last line (test environment only).
 *
 * No strict_types: wp eval-file evaluates this code, where the declaration is not allowed.
 *
 * @package Lab591\DevBridge
 */

$source = WP_CONTENT_DIR . '/e2e-fixtures/e2e-child';
$target = get_theme_root() . '/e2e-child';
if ( is_dir( $target ) ) {
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $target, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $target );
}
mkdir( $target );
foreach ( glob( $source . '/*' ) as $file ) {
	copy( $file, $target . '/' . basename( $file ) );
}
switch_theme( 'e2e-child' );

$plugin   = \Lab591\DevBridge\Plugin::instance();
$settings = $plugin->settings()->all();
$settings['allowed_user_ids'] = [ 1 ];
$settings['writable_roots']   = [ 'wp-content/themes/e2e-child' ];
$plugin->settings()->save( $settings );
$plugin->mode()->enable( 'write', 2, 1 );

foreach ( WP_Application_Passwords::get_user_application_passwords( 1 ) as $item ) {
	WP_Application_Passwords::delete_application_password( 1, $item['uuid'] );
}
[ $password ] = WP_Application_Passwords::create_new_application_password( 1, [ 'name' => 'e2e' ] );
if ( is_file( WP_CONTENT_DIR . '/debug.log' ) ) {
	file_put_contents( WP_CONTENT_DIR . '/debug.log', '' );
}
echo "\nE2E_PASSWORD=" . $password . "\n";
