<?php
/**
 * Back-end health check: login page and admin-ajax ping are checked with the front end.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Services;

use Lab591\DevBridge\Services\HealthService;
use Lab591\DevBridge\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class HealthBackendTest extends TestCase {

	private const LOGIN = 'https://example.test/wp-login.php';
	private const PING  = 'https://example.test/wp-admin/admin-ajax.php?action=devbridge_ping';

	protected function tearDown(): void {
		WpStubs::reset();
	}

	/**
	 * @param array<string, int|null> $codes URL prefix => HTTP code (null = network error).
	 */
	private static function answer( array $codes ): void {
		WpStubs::$http = static function ( string $url ) use ( $codes ): ?array {
			foreach ( $codes as $prefix => $code ) {
				if ( str_starts_with( $url, $prefix ) ) {
					return null === $code ? null : [ 'code' => $code ];
				}
			}
			return [ 'code' => 200 ];
		};
	}

	private static function service(): HealthService {
		return new HealthService( [ 'https://example.test/' ], null, null, [ self::LOGIN, self::PING ] );
	}

	public function test_all_ok_lists_backend_checks(): void {
		self::answer( [] );
		$result = self::service()->check( 0 );
		$this->assertSame( 'ok', $result['status'] );
		$this->assertSame( [ 'admin', 'backend', 'backend' ], array_column( $result['checks'], 'source' ) );
	}

	public function test_fatal_only_in_admin_fails_the_check(): void {
		self::answer( [ self::PING => 500 ] );
		$this->assertSame( 'fail', self::service()->check( 0 )['status'] );
	}

	public function test_login_page_hidden_by_a_security_plugin_is_not_a_failure(): void {
		// Plugins that rename the login page answer 404/403 or redirect: only 5xx means broken.
		self::answer( [ self::LOGIN => 404 ] );
		$this->assertSame( 'ok', self::service()->check( 0 )['status'] );
	}

	public function test_unreachable_backend_is_unknown_not_failed(): void {
		self::answer( [ self::PING => null ] );
		$this->assertSame( 'unknown', self::service()->check( 0 )['status'] );
	}

	public function test_backend_url_already_listed_by_the_admin_keeps_its_source(): void {
		self::answer( [] );
		$result = ( new HealthService( [ self::LOGIN ], null, null, [ self::LOGIN, self::PING ] ) )->check( 0 );
		$this->assertSame( [ 'admin', 'backend' ], array_column( $result['checks'], 'source' ) );
	}

	public function test_page_served_from_a_proxy_cache_is_not_proof(): void {
		WpStubs::$http = static fn ( string $url ): array => str_starts_with( $url, 'https://example.test/?' )
			? [
				'code'    => 200,
				'headers' => [ 'x-litespeed-cache' => 'hit' ],
			]
			: [ 'code' => 200 ];
		$result        = self::service()->check( 0 );
		$this->assertSame( 'unknown', $result['status'], 'No automatic OK on a cached page' );
		$this->assertSame( 'x-litespeed-cache: hit', $result['checks'][0]['cached'] );
		$this->assertArrayNotHasKey( 'cached', $result['checks'][1] );
		$this->assertStringContainsString( 'served from a cache', $result['message'] );
	}

	public function test_a_cached_page_does_not_hide_a_real_failure(): void {
		WpStubs::$http = static fn ( string $url ): array => str_starts_with( $url, self::PING )
			? [ 'code' => 500 ]
			: [
				'code'    => 200,
				'headers' => [ 'cf-cache-status' => 'HIT' ],
			];
		$this->assertSame( 'fail', self::service()->check( 0 )['status'] );
	}
}
