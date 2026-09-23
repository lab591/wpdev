<?php
/**
 * Folder picker for the writable roots (admin settings): lists the folders that can be
 * selected below themes/, plugins/ (and mu-plugins/ when enabled).
 *
 * Every folder is resolved and checked by WritableRootValidator (PathGuard included), the same
 * check applied when the settings are saved and at every request: what is selectable here is
 * exactly what the server accepts.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Admin;

use Lab591\DevBridge\Security\PathException;
use Lab591\DevBridge\Security\ResolvedPath;
use Lab591\DevBridge\Security\WritableRootValidator;

final class FolderPicker {

	/** Maximum folders listed for one parent. */
	public const MAX_ENTRIES = 500;

	/**
	 * @param WritableRootValidator $validator    Same validator used when saving the settings.
	 * @param string[]              $devBridgeDirs Folders of Dev Bridge itself (relative), to explain why they are locked.
	 */
	public function __construct(
		private readonly WritableRootValidator $validator,
		private readonly array $devBridgeDirs = [],
	) {
	}

	/**
	 * @return string[]
	 */
	public function containers(): array {
		return $this->validator->containers();
	}

	/**
	 * Sub-folders of a container or of a selectable folder, sorted by name.
	 *
	 * @param string $path Container (e.g. "wp-content/themes") or folder relative to ABSPATH.
	 * @return array{items: list<array{path: string, name: string, selectable: bool, reason: string, expandable: bool}>, truncated: bool}
	 * @throws PathException When the parent is neither a container nor a selectable folder.
	 */
	public function children( string $path ): array {
		$parent = in_array( $path, $this->containers(), true )
			? $this->validator->resolveContainer( $path )
			: $this->validator->resolve( $path );

		$items     = [];
		$truncated = false;
		foreach ( self::names( $parent ) as $name ) {
			$rel = $parent->relative . '/' . $name;
			try {
				$resolved = $this->validator->resolve( $rel );
				$item     = [
					'path'       => $resolved->relative,
					'name'       => $name,
					'selectable' => true,
					'reason'     => '',
					'expandable' => self::hasSubfolders( $resolved ),
				];
			} catch ( PathException $e ) {
				if ( in_array( $e->errorCode(), [ 'not_a_directory', 'not_found' ], true ) ) {
					continue; // Files are not listed.
				}
				$item = [
					'path'       => $rel,
					'name'       => $name,
					'selectable' => false,
					'reason'     => in_array( $rel, $this->devBridgeDirs, true ) ? __( 'contains Dev Bridge files', 'lab591-dev-bridge' ) : self::reason( $e ),
					'expandable' => false,
				];
			}
			if ( count( $items ) >= self::MAX_ENTRIES ) {
				$truncated = true;
				break;
			}
			$items[] = $item;
		}
		return [
			'items'     => $items,
			'truncated' => $truncated,
		];
	}

	/**
	 * Entry names of a resolved folder, without dot entries, sorted naturally.
	 *
	 * @return string[]
	 */
	private static function names( ResolvedPath $dir ): array {
		$entries = scandir( $dir->absolute );
		if ( false === $entries ) {
			return [];
		}
		$names = array_values( array_filter( $entries, static fn ( string $n ): bool => '' !== $n && '.' !== $n[0] ) );
		natcasesort( $names );
		return array_values( $names );
	}

	private static function hasSubfolders( ResolvedPath $dir ): bool {
		foreach ( self::names( $dir ) as $name ) {
			if ( is_dir( $dir->absolute . DIRECTORY_SEPARATOR . $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Short reason shown next to a folder that cannot be selected.
	 */
	private static function reason( PathException $e ): string {
		$message = $e->getMessage();
		if ( str_contains( $message, 'Dev Bridge' ) ) {
			return __( 'contains Dev Bridge files', 'lab591-dev-bridge' );
		}
		if ( str_contains( $message, 'outside' ) || str_contains( $message, 'symbolic' ) ) {
			return __( 'symbolic link pointing outside', 'lab591-dev-bridge' );
		}
		return __( 'not allowed (deny list or invalid name)', 'lab591-dev-bridge' );
	}
}
