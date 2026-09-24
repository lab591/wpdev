<?php
/**
 * Cache detection (0.6.0): generic signals, known plugins and hosts, OPcache, cache-hit headers.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Services;

use Lab591\DevBridge\Services\CacheDetector;
use PHPUnit\Framework\TestCase;

final class CacheDetectorTest extends TestCase {

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private static function facts( array $overrides = [] ): array {
		return array_replace(
			[
				'wp_cache'         => false,
				'advanced_cache'   => null,
				'object_cache'     => null,
				'active_plugins'   => [ 'akismet/akismet.php' ],
				'hosts'            => [],
				'opcache'          => [
					'enabled'             => true,
					'validate_timestamps' => true,
					'revalidate_freq'     => 2,
					'restrict_api'        => '',
				],
				'self_path'        => '/var/www/html/wp-content/plugins/lab591-dev-bridge/src/Services/CacheDetector.php',
				'development_mode' => '',
			],
			$overrides
		);
	}

	public function test_nothing_detected_on_a_plain_site(): void {
		$report = CacheDetector::analyze( self::facts() );
		$this->assertSame( [], $report['page'] );
		$this->assertNull( $report['object'] );
		$this->assertSame( [], $report['assets'] );
		$this->assertSame( 'ok', $report['opcache'] );
		$this->assertNull( CacheDetector::notice( $report ) );
	}

	public function test_known_page_cache_plugin_counts_only_with_its_drop_in(): void {
		$plugins = [ 'active_plugins' => [ 'wp-super-cache/wp-cache.php' ] ];
		$this->assertSame( [], CacheDetector::analyze( self::facts( $plugins ) )['page'], 'Installed but caching disabled' );

		$report = CacheDetector::analyze(
			self::facts(
				$plugins + [
					'wp_cache'       => true,
					'advanced_cache' => '',
				]
			)
		);
		$this->assertSame( [ 'WP Super Cache' ], $report['page'] );
		$this->assertSame( [ 'WP Super Cache' ], CacheDetector::notice( $report )['page'] ?? null );
	}

	public function test_unknown_plugin_is_caught_by_the_drop_in(): void {
		$report = CacheDetector::analyze(
			self::facts(
				[
					'wp_cache'       => true,
					'advanced_cache' => 'Acme Turbo',
				]
			)
		);
		$this->assertSame( [ 'Acme Turbo (advanced-cache.php)' ], $report['page'] );
		// The drop-in without WP_CACHE is not loaded by WordPress.
		$this->assertSame( [], CacheDetector::analyze( self::facts( [ 'advanced_cache' => 'Acme Turbo' ] ) )['page'] );
	}

	public function test_server_caches_and_hosts_count_without_drop_in(): void {
		$report = CacheDetector::analyze(
			self::facts(
				[
					'active_plugins' => [ 'litespeed-cache/litespeed-cache.php', 'autoptimize/autoptimize.php' ],
					'hosts'          => [ 'Kinsta' ],
				]
			)
		);
		$this->assertSame( [ 'LiteSpeed Cache', 'Kinsta' ], $report['page'] );
		$this->assertSame( [ 'Autoptimize' ], $report['assets'] );
	}

	public function test_object_cache_only_when_the_drop_in_is_in_use(): void {
		$redis = [ 'active_plugins' => [ 'redis-cache/redis-cache.php' ] ];
		$this->assertNull( CacheDetector::analyze( self::facts( $redis ) )['object'] );
		$this->assertSame( 'Redis Object Cache', CacheDetector::analyze( self::facts( $redis + [ 'object_cache' => '' ] ) )['object'] );
		$this->assertSame( 'Object Cache Pro', CacheDetector::analyze( self::facts( [ 'object_cache' => 'Object Cache Pro' ] ) )['object'] );
		$this->assertSame( 'object-cache.php', CacheDetector::analyze( self::facts( [ 'object_cache' => '' ] ) )['object'] );
		$this->assertNull( CacheDetector::notice( CacheDetector::analyze( self::facts( [ 'object_cache' => '' ] ) ) ), 'Code changes do not go through the object cache' );
	}

	/**
	 * @return array<string, array{array<string, mixed>, string, ?int}>
	 */
	public static function opcacheCases(): array {
		$base = [
			'enabled'             => true,
			'validate_timestamps' => true,
			'revalidate_freq'     => 2,
			'restrict_api'        => '',
		];
		return [
			'disabled'                         => [ [ 'enabled' => false ] + $base, 'off', null ],
			'not restricted'                   => [ $base, 'ok', null ],
			'restricted to our path'           => [ [ 'restrict_api' => '/var/www/html' ] + $base, 'ok', null ],
			'restricted, revalidates'          => [ [ 'restrict_api' => '/opt/other' ] + $base, 'restricted', 2 ],
			'restricted, checks every request' => [
				[
					'restrict_api'    => '/opt/other',
					'revalidate_freq' => 0,
				] + $base,
				'restricted',
				null,
			],
			'restricted, never revalidates'    => [
				[
					'restrict_api'        => '/opt/other',
					'validate_timestamps' => false,
				] + $base,
				'restricted',
				-1,
			],
		];
	}

	/**
	 * @dataProvider opcacheCases
	 * @param array<string, mixed> $opcache
	 */
	public function test_opcache( array $opcache, string $state, ?int $stale ): void {
		$report = CacheDetector::analyze( self::facts( [ 'opcache' => $opcache ] ) );
		$this->assertSame( $state, $report['opcache'] );
		$this->assertSame( $stale, $report['opcache_stale_s'] );
		$this->assertSame( null !== $stale, null !== CacheDetector::notice( $report ) );
	}

	/**
	 * @return array<string, array{array<string, string|string[]>, ?string}>
	 */
	public static function headerCases(): array {
		return [
			'no cache headers'     => [ [ 'Content-Type' => 'text/html' ], null ],
			'litespeed hit'        => [ [ 'X-LiteSpeed-Cache' => 'hit' ], 'x-litespeed-cache: hit' ],
			'litespeed miss'       => [ [ 'X-LiteSpeed-Cache' => 'miss' ], null ],
			'cloudflare hit'       => [ [ 'CF-Cache-Status' => 'HIT' ], 'cf-cache-status: HIT' ],
			'cloudflare dynamic'   => [ [ 'CF-Cache-Status' => 'DYNAMIC' ], null ],
			'cloudfront hit'       => [ [ 'X-Cache' => 'Hit from cloudfront' ], 'x-cache: Hit from cloudfront' ],
			'cloudfront miss'      => [ [ 'X-Cache' => 'Miss from cloudfront' ], null ],
			'nginx hit, multiple'  => [ [ 'x-cache-status' => [ 'MISS', 'HIT' ] ], 'x-cache-status: MISS, HIT' ],
			'varnish hit'          => [ [ 'X-Varnish' => '32770 32768' ], 'x-varnish: 32770 32768' ],
			'varnish miss'         => [ [ 'X-Varnish' => '32770' ], null ],
			'age zero'             => [ [ 'Age' => '0' ], null ],
			'age positive'         => [ [ 'Age' => '37' ], 'age: 37' ],
			'hit word in a string' => [ [ 'X-Cache' => 'whitelisted' ], null ],
		];
	}

	/**
	 * @dataProvider headerCases
	 * @param array<string, string|string[]> $headers
	 */
	public function test_hit_evidence( array $headers, ?string $expected ): void {
		$this->assertSame( $expected, CacheDetector::hitEvidence( $headers ) );
	}
}
