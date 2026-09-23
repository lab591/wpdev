<?php
/**
 * Deploy manifest (SPEC 2.7): `{files:[{p, action, h?, base_h?}], force}`.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Deploy;

use Lab591\DevBridge\Support\ApiException;

final class Manifest {

	public const HASH_PATTERN = '/^[0-9a-f]{32}$/';

	/**
	 * @param list<ManifestEntry> $entries
	 * @param list<string>        $healthPaths Extra same-site paths to check after this deploy.
	 */
	private function __construct(
		public readonly array $entries,
		public readonly bool $force,
		public readonly array $healthPaths = [],
	) {
	}

	/**
	 * @throws ApiException `invalid_manifest` (400) or `too_many_files` (413).
	 */
	public static function parse( string $json, int $maxFiles ): self {
		try {
			$data = json_decode( $json, true, 8, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			throw self::invalid( 'manifest is not valid JSON' );
		}
		if ( ! is_array( $data ) || array_is_list( $data ) || ! isset( $data['files'] ) || ! is_array( $data['files'] ) || ! array_is_list( $data['files'] ) ) {
			throw self::invalid( 'manifest must be an object with a "files" list' );
		}
		if ( [] === $data['files'] ) {
			throw self::invalid( 'manifest has no files' );
		}
		if ( count( $data['files'] ) > $maxFiles ) {
			throw new ApiException( 'too_many_files', sprintf( 'At most %d files per deploy', $maxFiles ), 413 );
		}
		$force = $data['force'] ?? false;
		if ( ! is_bool( $force ) ) {
			throw self::invalid( '"force" must be a boolean' );
		}

		$entries = [];
		$seen    = [];
		foreach ( $data['files'] as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['p'] ) || ! is_string( $item['p'] ) || '' === $item['p'] ) {
				throw self::invalid( 'each file needs a string "p"' );
			}
			$path   = $item['p'];
			$action = $item['action'] ?? null;
			if ( 'write' !== $action && 'delete' !== $action ) {
				throw self::invalid( '"action" must be "write" or "delete"', $path );
			}
			$h = $item['h'] ?? null;
			if ( 'write' === $action && ( ! is_string( $h ) || 1 !== preg_match( self::HASH_PATTERN, $h ) ) ) {
				throw self::invalid( '"h" must be a lowercase xxh128 hex hash', $path );
			}
			$baseH = $item['base_h'] ?? null;
			if ( null !== $baseH && ( ! is_string( $baseH ) || 1 !== preg_match( self::HASH_PATTERN, $baseH ) ) ) {
				throw self::invalid( '"base_h" must be null or a lowercase xxh128 hex hash', $path );
			}
			$key = strtolower( str_replace( '\\', '/', $path ) );
			if ( isset( $seen[ $key ] ) ) {
				throw self::invalid( 'duplicate path', $path );
			}
			$seen[ $key ] = true;
			$entries[]    = new ManifestEntry( $path, $action, 'write' === $action ? $h : null, $baseH );
		}
		return new self( $entries, $force, HealthPaths::parse( $data['health_paths'] ?? null, 'invalid_manifest' ) );
	}

	private static function invalid( string $message, ?string $path = null ): ApiException {
		return new ApiException( 'invalid_manifest', 'Invalid manifest: ' . $message, 400, null === $path ? [] : [ 'path' => self::safePath( $path ) ] );
	}

	/**
	 * Echoes a client path back in errors only when it is short and printable.
	 */
	public static function safePath( string $path ): string {
		return 1 === preg_match( '/^[^\x00-\x1F\x7F]{1,300}$/u', $path ) ? $path : '(invalid path)';
	}
}
