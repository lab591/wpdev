<?php
/**
 * PSR-4 autoloader (no Composer at runtime).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge;

final class Autoloader {

	private const PREFIX = __NAMESPACE__ . '\\';

	public static function register( string $srcDir ): void {
		spl_autoload_register(
			static function ( string $class_name ) use ( $srcDir ): void {
				if ( ! str_starts_with( $class_name, self::PREFIX ) ) {
					return;
				}
				$relative = substr( $class_name, strlen( self::PREFIX ) );
				// The file is derived only from a class name inside our namespace, restricted to plain identifiers.
				if ( 1 !== preg_match( '/^[A-Za-z0-9_\\\\]+$/', $relative ) ) {
					return;
				}
				$file = $srcDir . '/' . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_file( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}
