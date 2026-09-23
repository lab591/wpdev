<?php
/**
 * Minimal raw zip writer for tests: gives full control over entry names, duplicates and
 * attributes (e.g. Unix symlinks), which ZipArchive would normalize or refuse.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Support;

final class ZipBuilder {

	/**
	 * @param array<string, string> $entries  name => content.
	 * @param string[]              $symlinks Entry names to flag as Unix symlinks.
	 */
	public static function build( string $file, array $entries, array $symlinks = [] ): string {
		$list = [];
		foreach ( $entries as $name => $content ) {
			$list[] = [ (string) $name, $content, in_array( $name, $symlinks, true ) ];
		}
		return self::buildRaw( $file, $list );
	}

	/**
	 * @param list<array{0: string, 1: string, 2?: bool}> $entries [name, content, isSymlink].
	 */
	public static function buildRaw( string $file, array $entries ): string {
		$data    = '';
		$central = '';
		foreach ( $entries as $entry ) {
			[ $name, $content ] = $entry;
			$symlink            = $entry[2] ?? false;
			$crc                = crc32( $content );
			$size               = strlen( $content );
			$offset             = strlen( $data );
			$mode               = $symlink ? 0120777 : ( str_ends_with( $name, '/' ) ? 040755 : 0100644 );

			$data .= pack( 'VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, 0, 0x21, $crc, $size, $size, strlen( $name ), 0 ) . $name . $content;

			$central .= pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, 0x0314, 20, 0x0800, 0, 0, 0x21, $crc, $size, $size, strlen( $name ), 0, 0, 0, 0, ( $mode << 16 ), $offset ) . $name;
		}
		$eocd = pack( 'VvvvvVVv', 0x06054b50, 0, 0, count( $entries ), count( $entries ), strlen( $central ), strlen( $data ), 0 );
		file_put_contents( $file, $data . $central . $eocd );
		return $file;
	}
}
