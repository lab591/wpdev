<?php
/**
 * Cache flush (`POST /cache-flush`).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Services;

final class CacheFlushService {

	public const TARGETS = [ 'opcache', 'object', 'elementor' ];

	/**
	 * @param string[] $targets
	 * @return array<string, string> target => "ok" | "unavailable" | "failed"
	 */
	public function flush( array $targets ): array {
		$results = [];
		foreach ( array_values( array_intersect( self::TARGETS, $targets ) ) as $target ) {
			$results[ $target ] = match ( $target ) {
				'opcache'   => $this->opcache(),
				'object'    => wp_cache_flush() ? 'ok' : 'failed',
				'elementor' => $this->elementor(),
			};
		}
		return $results;
	}

	private function opcache(): string {
		if ( ! function_exists( 'opcache_reset' ) || ! filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN ) ) {
			return 'unavailable';
		}
		return opcache_reset() ? 'ok' : 'failed';
	}

	private function elementor(): string {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return 'unavailable';
		}
		$instance = \Elementor\Plugin::$instance ?? null;
		if ( null === $instance || ! isset( $instance->files_manager ) || ! method_exists( $instance->files_manager, 'clear_cache' ) ) {
			return 'unavailable';
		}
		$instance->files_manager->clear_cache();
		return 'ok';
	}
}
