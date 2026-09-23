<?php
/**
 * Builds the distributable plugin zip (runtime files only) into ../dist/ (dev tool only).
 * The admin interface must be built first (npm run build): the zip ships build/, never the sources.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

$root    = dirname( __DIR__ );
$main    = (string) file_get_contents( $root . '/lab591-dev-bridge.php' );
$version = 1 === preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $main, $m ) ? $m[1] : 'dev';
$dist    = dirname( $root ) . '/dist';

foreach ( [ 'build/index.js', 'build/index.asset.json', 'languages/lab591-dev-bridge.pot' ] as $required ) {
	if ( ! is_file( $root . '/' . $required ) ) {
		fwrite( STDERR, "Missing $required: run \"npm install && npm run build\" (and the i18n steps) first.\n" );
		exit( 1 );
	}
}
if ( ! is_dir( $dist ) ) {
	mkdir( $dist, 0755, true );
}
$target = $dist . '/lab591-dev-bridge-' . $version . '.zip';

$zip = new ZipArchive();
if ( true !== $zip->open( $target, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "Cannot create $target\n" );
	exit( 1 );
}
$files = [ 'lab591-dev-bridge.php', 'uninstall.php' ];
foreach ( [ 'src', 'mu-plugin', 'build', 'languages' ] as $dir ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		$files[] = substr( str_replace( '\\', '/', $file->getPathname() ), strlen( str_replace( '\\', '/', $root ) ) + 1 );
	}
}
sort( $files );
foreach ( $files as $rel ) {
	$zip->addFile( $root . '/' . $rel, 'lab591-dev-bridge/' . $rel );
}
// GPL-2.0-or-later: the license text travels with the plugin.
$zip->addFile( dirname( $root ) . '/LICENSE', 'lab591-dev-bridge/LICENSE' );
$files[] = 'LICENSE';
$zip->close();
fwrite( STDOUT, sprintf( "%s (%d files)\n", $target, count( $files ) ) );
