<?php
/**
 * Plugin settings (option `devbridge_settings`). Changed only from wp-admin or WP-CLI.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge;

use Lab591\DevBridge\Security\PathPolicy;

final class Settings {

	public const OPTION = 'devbridge_settings';

	public const DEFAULT_LIMITS = [
		'read_bytes'        => 524288,
		'grep_results'      => 200,
		'grep_ms'           => 5000,
		'grep_file_bytes'   => 1048576,
		'manifest_files'    => 20000,
		'archive_files'     => 500,
		'archive_bytes'     => 104857600,
		'deploy_zip_bytes'  => 20971520,
		'deploy_files'      => 500,
		'deploy_file_bytes' => 5242880,
	];

	/** @var array<string, mixed>|null */
	private ?array $cache = null;

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'allowed_user_ids'     => [],
			'ip_allowlist'         => [],
			'trusted_proxies'      => [],
			'writable_roots'       => [],
			'allow_mu_plugins'     => false,
			'read_roots'           => [ '' ],
			'deny_patterns'        => PathPolicy::DEFAULT_DENY_PATTERNS,
			'write_extensions'     => PathPolicy::DEFAULT_WRITE_EXTENSIONS,
			'grep_skip_dirs'       => [ 'node_modules', 'vendor', '.git' ],
			'limits'               => self::DEFAULT_LIMITS,
			'health_urls'          => [],
			'retention_releases'   => 10,
			'audit_retention_days' => 90,
			'max_read_hours'       => 72,
			'max_write_hours'      => 8,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored           = get_option( self::OPTION, [] );
			$merged           = array_merge( self::defaults(), is_array( $stored ) ? $stored : [] );
			$merged['limits'] = array_merge( self::DEFAULT_LIMITS, is_array( $merged['limits'] ) ? $merged['limits'] : [] );
			$this->cache      = $merged;
		}
		return $this->cache;
	}

	public function get( string $key ): mixed {
		return $this->all()[ $key ] ?? null;
	}

	public function limit( string $key ): int {
		return (int) ( $this->all()['limits'][ $key ] ?? self::DEFAULT_LIMITS[ $key ] ?? 0 );
	}

	/**
	 * @return int[]
	 */
	public function allowedUserIds(): array {
		return array_values( array_filter( array_map( 'intval', (array) $this->get( 'allowed_user_ids' ) ) ) );
	}

	/**
	 * @return string[]
	 */
	public function writableRoots(): array {
		return array_values( array_map( 'strval', (array) $this->get( 'writable_roots' ) ) );
	}

	/**
	 * Health URLs; the home page when none is configured.
	 *
	 * @return string[]
	 */
	public function healthUrls(): array {
		$urls = array_values( array_filter( array_map( 'strval', (array) $this->get( 'health_urls' ) ) ) );
		return [] === $urls ? [ home_url( '/' ) ] : $urls;
	}

	/**
	 * Persists already sanitized settings.
	 *
	 * @param array<string, mixed> $settings
	 */
	public function save( array $settings ): void {
		update_option( self::OPTION, $settings, false );
		$this->cache = null;
	}

	public function flush(): void {
		$this->cache = null;
	}
}
