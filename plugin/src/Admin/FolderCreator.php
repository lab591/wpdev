<?php
/**
 * Creates an empty writable folder from the admin page (e.g. the folder of a new plugin or
 * child theme), so that the companion can pull it and the agent can fill it.
 *
 * Admin-only (never exposed via REST). The parent is resolved by PathGuard through
 * WritableRootValidator, new segments are strictly validated, and the created folder must pass
 * the same validation as any writable root, otherwise it is removed again.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Admin;

use Lab591\DevBridge\Security\PathException;
use Lab591\DevBridge\Security\WritableRootValidator;

final class FolderCreator {

	public const MAX_SEGMENTS = 4;

	private const SEGMENT = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

	public function __construct( private readonly WritableRootValidator $validator ) {
	}

	/**
	 * Creates `<container>/<path>` (intermediate folders included) and returns it, relative to
	 * ABSPATH. An already existing folder is returned as is (if acceptable).
	 *
	 * @param string $container One of the validator containers (e.g. "wp-content/plugins").
	 * @param string $path      Folder name or sub-path below the container (e.g. "mio-tema/blocks").
	 * @throws PathException When the folder is not acceptable or cannot be created (message in Italian).
	 */
	public function create( string $container, string $path ): string {
		try {
			$base = $this->validator->resolveContainer( $container );
		} catch ( PathException ) {
			throw PathException::denied( 'contenitore non ammesso' );
		}
		$segments = self::segments( $path );

		// Nearest existing ancestor: the container or an acceptable writable folder.
		$existing = 0;
		$absolute = $base->absolute;
		foreach ( $segments as $segment ) {
			if ( ! is_dir( $absolute . DIRECTORY_SEPARATOR . $segment ) ) {
				break;
			}
			$absolute .= DIRECTORY_SEPARATOR . $segment;
			++$existing;
		}
		$target = $base->relative . '/' . implode( '/', $segments );
		if ( $existing > 0 ) {
			try {
				$ancestor = $this->validator->resolve( $base->relative . '/' . implode( '/', array_slice( $segments, 0, $existing ) ) );
			} catch ( PathException $e ) {
				throw PathException::denied( 'la cartella non può stare lì (' . self::reason( $e ) . ')' );
			}
			if ( count( $segments ) === $existing ) {
				return $ancestor->relative; // Already there.
			}
			$absolute = $ancestor->absolute;
		}

		// Create the missing segments one by one, remembering them for the cleanup.
		$created = [];
		foreach ( array_slice( $segments, $existing ) as $segment ) {
			$absolute .= DIRECTORY_SEPARATOR . $segment;
			if ( ! mkdir( $absolute, 0755 ) && ! is_dir( $absolute ) ) {
				self::cleanup( $created );
				throw new PathException( 'internal_error', 'Impossibile creare la cartella (permessi del server?)', 500 );
			}
			$created[] = $absolute;
		}

		try {
			return $this->validator->validate( $target );
		} catch ( PathException $e ) {
			self::cleanup( $created );
			throw PathException::denied( 'cartella non ammessa (' . self::reason( $e ) . ')' );
		}
	}

	/**
	 * @return string[]
	 * @throws PathException On invalid names.
	 */
	private static function segments( string $path ): array {
		$path = trim( str_replace( '\\', '/', trim( $path ) ), '/' );
		if ( '' === $path ) {
			throw PathException::invalid( 'nome mancante' );
		}
		$segments = explode( '/', $path );
		if ( count( $segments ) > self::MAX_SEGMENTS ) {
			throw PathException::invalid( 'al massimo ' . self::MAX_SEGMENTS . ' livelli' );
		}
		foreach ( $segments as $segment ) {
			if ( 1 !== preg_match( self::SEGMENT, $segment ) || str_ends_with( $segment, '.' ) ) {
				throw PathException::invalid( 'nome "' . $segment . '" non valido: usa lettere, numeri, "-", "_" e "." (non all\'inizio)' );
			}
		}
		return $segments;
	}

	/**
	 * Removes the folders created by this call, deepest first (only if still empty).
	 *
	 * @param string[] $created
	 */
	private static function cleanup( array $created ): void {
		foreach ( array_reverse( $created ) as $dir ) {
			if ( is_dir( $dir ) ) {
				rmdir( $dir );
			}
		}
	}

	private static function reason( PathException $e ): string {
		$message = $e->getMessage();
		if ( str_contains( $message, 'Dev Bridge' ) || str_contains( $message, 'path not allowed' ) ) {
			return 'deny list o cartella protetta';
		}
		return $message;
	}
}
