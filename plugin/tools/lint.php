<?php
/**
 * Runs `php -l` equivalent syntax checks on every PHP file of the plugin (dev tool only).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

$root  = dirname( __DIR__ );
$dirs  = array( $root . '/src', $root . '/tests', $root . '/mu-plugin' );
$files = array( $root . '/lab591-dev-bridge.php', $root . '/uninstall.php' );
foreach ( $dirs as $dir ) {
	if ( ! is_dir( $dir ) ) {
		continue;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( 'php' === $file->getExtension() ) {
			$files[] = $file->getPathname();
		}
	}
}
$failed = 0;
foreach ( $files as $file ) {
	if ( ! is_file( $file ) ) {
		continue;
	}
	// Tokenizing with TOKEN_PARSE throws ParseError on invalid syntax, without spawning processes.
	try {
		token_get_all( (string) file_get_contents( $file ), TOKEN_PARSE );
	} catch ( ParseError $e ) {
		++$failed;
		fwrite( STDERR, sprintf( "%s:%d %s\n", $file, $e->getLine(), $e->getMessage() ) );
	}
}
fwrite( STDOUT, sprintf( "Syntax check: %d files, %d errors\n", count( $files ), $failed ) );
exit( $failed > 0 ? 1 : 0 );
