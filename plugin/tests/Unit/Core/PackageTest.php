<?php
/**
 * Plugin package layout: what WordPress sees after a zip upload.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

final class PackageTest extends TestCase {

	/**
	 * WordPress scans the plugin folder and its direct subfolders for "Plugin Name" headers and,
	 * after a zip upload, points "Activate" at the first one found: only the main file may have it.
	 */
	public function test_only_the_main_file_has_a_plugin_name_header(): void {
		$root  = dirname( __DIR__, 3 );
		$files = array_merge( (array) glob( $root . '/*.php' ), (array) glob( $root . '/*/*.php' ) );
		$found = [];
		foreach ( $files as $file ) {
			$head = (string) file_get_contents( (string) $file, false, null, 0, 8192 );
			if ( 1 === preg_match( '/^[ \t\/*#@]*Plugin Name:/mi', $head ) ) {
				$found[] = substr( (string) $file, strlen( $root ) + 1 );
			}
		}
		$this->assertSame( [ 'lab591-dev-bridge.php' ], $found );
	}
}
