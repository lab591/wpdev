<?php
/**
 * IP allowlist and client IP resolution tests.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Security;

use Lab591\DevBridge\Security\ClientIp;
use Lab591\DevBridge\Security\IpMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpTest extends TestCase {

	public static function matchCases(): array {
		return [
			[ '203.0.113.5', '203.0.113.5', true ],
			[ '203.0.113.5', '203.0.113.6', false ],
			[ '203.0.113.5', '203.0.113.0/24', true ],
			[ '203.0.114.5', '203.0.113.0/24', false ],
			[ '10.1.2.3', '10.0.0.0/8', true ],
			[ '10.1.2.3', '10.0.0.0/9', true ],
			[ '10.200.2.3', '10.0.0.0/9', false ],
			[ '1.2.3.4', '0.0.0.0/0', true ],
			[ '2001:db8::1', '2001:db8::/32', true ],
			[ '2001:db9::1', '2001:db8::/32', false ],
			[ '2001:db8::1', '2001:DB8:0:0:0:0:0:1', true ],
			[ '::ffff:192.0.2.1', '192.0.2.0/24', true ],
			[ '192.0.2.1', '2001:db8::/32', false ],
			[ 'not-an-ip', '0.0.0.0/0', false ],
			[ '1.2.3.4', '1.2.3.4/33', false ],
			[ '1.2.3.4', '1.2.3.4/abc', false ],
		];
	}

	#[DataProvider( 'matchCases' )]
	public function test_matches( string $ip, string $entry, bool $expected ): void {
		$this->assertSame( $expected, IpMatcher::matches( $ip, $entry ) );
	}

	public function test_valid_entries(): void {
		$this->assertTrue( IpMatcher::isValidEntry( '192.168.0.0/16' ) );
		$this->assertTrue( IpMatcher::isValidEntry( '::1' ) );
		$this->assertFalse( IpMatcher::isValidEntry( 'example.com' ) );
		$this->assertFalse( IpMatcher::isValidEntry( '10.0.0.0/40' ) );
	}

	public function test_forwarded_for_ignored_without_trusted_proxy(): void {
		$this->assertSame( '198.51.100.7', ClientIp::resolve( '198.51.100.7', '1.1.1.1', [] ) );
		$this->assertSame( '198.51.100.7', ClientIp::resolve( '198.51.100.7', '1.1.1.1', [ '10.0.0.1' ] ) );
	}

	public function test_forwarded_for_used_behind_trusted_proxy(): void {
		$this->assertSame( '1.1.1.1', ClientIp::resolve( '10.0.0.1', '1.1.1.1', [ '10.0.0.0/8' ] ) );
		// Spoofed left-most entries are ignored: the first untrusted hop from the right wins.
		$this->assertSame( '2.2.2.2', ClientIp::resolve( '10.0.0.1', '9.9.9.9, 2.2.2.2, 10.0.0.2', [ '10.0.0.0/8' ] ) );
		// Garbage in the chain: fall back to the last trusted hop.
		$this->assertSame( '10.0.0.1', ClientIp::resolve( '10.0.0.1', 'garbage', [ '10.0.0.0/8' ] ) );
	}
}
