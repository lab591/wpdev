<?php
/**
 * Server-side xxh128 cache keyed by `path|size|mtime` (M3), persisted in the private storage.
 *
 * Used only by read paths (manifest, read): deploy conflict checks always hash the real file.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Services;

final class HashCache {

	public const MAX_ENTRIES = 50000;
	/** Files modified this recently are not cached (same-second rewrites with equal size). */
	public const MIN_AGE = 2;

	/** @var array<string, string>|null key "path|size|mtime" => hash */
	private ?array $entries = null;
	private bool $dirty     = false;

	/**
	 * @param string|null $file JSON file in the storage; null disables persistence.
	 */
	public function __construct( private readonly ?string $file ) {
	}

	public function hash( string $absolute, string $relative, int $size, int $mtime ): string {
		$this->load();
		$key = $relative . '|' . $size . '|' . $mtime;
		if ( isset( $this->entries[ $key ] ) ) {
			return $this->entries[ $key ];
		}
		$hash = (string) hash_file( 'xxh128', $absolute );
		if ( $mtime <= time() - self::MIN_AGE ) {
			$this->entries[ $key ] = $hash;
			$this->dirty           = true;
		}
		return $hash;
	}

	/**
	 * Persists new entries (keeps the most recent MAX_ENTRIES).
	 */
	public function save(): void {
		if ( ! $this->dirty || null === $this->file || null === $this->entries ) {
			return;
		}
		$dir = dirname( $this->file );
		if ( ! is_dir( $dir ) ) {
			return;
		}
		if ( count( $this->entries ) > self::MAX_ENTRIES ) {
			$this->entries = array_slice( $this->entries, -self::MAX_ENTRIES, null, true );
		}
		$tmp = $this->file . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		if ( false !== file_put_contents( $tmp, (string) json_encode( $this->entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ) {
			rename( $tmp, $this->file );
		}
		$this->dirty = false;
	}

	private function load(): void {
		if ( null !== $this->entries ) {
			return;
		}
		$this->entries = [];
		if ( null === $this->file || ! is_file( $this->file ) ) {
			return;
		}
		$data = json_decode( (string) file_get_contents( $this->file ), true );
		if ( ! is_array( $data ) ) {
			return;
		}
		foreach ( $data as $key => $hash ) {
			if ( is_string( $key ) && is_string( $hash ) && 1 === preg_match( '/^[0-9a-f]{32}$/', $hash ) ) {
				$this->entries[ $key ] = $hash;
			}
		}
	}
}
