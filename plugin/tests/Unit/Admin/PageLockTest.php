<?php
/**
 * Password protection of the Dev Bridge admin page (0.7.0).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Admin;

use Lab591\DevBridge\Admin\PageLock;
use Lab591\DevBridge\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class PageLockTest extends TestCase {

	private const PASSWORD = 'correct horse battery';

	private int $now = 1_800_000_000;

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	private function lock(): PageLock {
		return new PageLock( fn (): int => $this->now );
	}

	private function enabled(): PageLock {
		$lock = $this->lock();
		$lock->set( self::PASSWORD, self::PASSWORD, '', 1, 'session-a' );
		return $lock;
	}

	private static function expectRefused( string $fragment, callable $callback ): void {
		try {
			$callback();
		} catch ( \InvalidArgumentException $e ) {
			self::assertStringContainsString( $fragment, $e->getMessage() );
			return;
		}
		self::fail( 'Expected a refusal: ' . $fragment );
	}

	public function test_off_by_default_everything_is_open(): void {
		$lock = $this->lock();
		$this->assertFalse( $lock->enabled() );
		$this->assertTrue( $lock->isUnlocked( 1, 'any' ) );
	}

	public function test_only_a_hash_is_stored(): void {
		$this->enabled();
		$stored = (string) wp_json_encode( WpStubs::$options );
		$this->assertStringNotContainsString( self::PASSWORD, $stored );
		$this->assertArrayHasKey( PageLock::OPTION, WpStubs::$options );
	}

	public function test_setting_the_password_keeps_the_current_session_unlocked_only(): void {
		$lock = $this->enabled();
		$this->assertTrue( $lock->enabled() );
		$this->assertTrue( $lock->isUnlocked( 1, 'session-a' ) );
		$this->assertFalse( $lock->isUnlocked( 1, 'session-b' ), 'Another login of the same user' );
		$this->assertFalse( $lock->isUnlocked( 2, 'session-a' ), 'Another user' );
	}

	public function test_password_rules(): void {
		$lock = $this->lock();
		self::expectRefused( '10', fn () => $lock->set( 'short', 'short', '', 1, 's' ) );
		self::expectRefused( 'match', fn () => $lock->set( 'long enough password', 'different password!', '', 1, 's' ) );
		$this->assertFalse( $lock->enabled() );
	}

	public function test_unlock_with_the_right_password_only(): void {
		$lock = $this->enabled();
		$this->assertFalse( $lock->attempt( 1, 'session-b', 'wrong password' )['ok'] );
		$this->assertFalse( $lock->isUnlocked( 1, 'session-b' ) );
		$this->assertTrue( $lock->attempt( 1, 'session-b', self::PASSWORD )['ok'] );
		$this->assertTrue( $lock->isUnlocked( 1, 'session-b' ) );
	}

	public function test_unlock_expires_after_inactivity_and_is_renewed_by_use(): void {
		$lock       = $this->enabled();
		$this->now += PageLock::TTL - 10;
		$this->assertTrue( $lock->isUnlocked( 1, 'session-a' ), 'Still valid, and renewed' );
		$this->now += PageLock::TTL - 10;
		$this->assertTrue( $lock->isUnlocked( 1, 'session-a' ) );
		$this->now += PageLock::TTL + 1;
		$this->assertFalse( $lock->isUnlocked( 1, 'session-a' ) );
	}

	public function test_too_many_wrong_attempts_block_even_the_right_password(): void {
		$lock = $this->enabled();
		for ( $i = 0; $i < PageLock::MAX_FAILS; $i++ ) {
			$lock->attempt( 1, 'session-b', 'nope nope nope' );
		}
		$result = $lock->attempt( 1, 'session-b', self::PASSWORD );
		$this->assertFalse( $result['ok'] );
		$this->assertGreaterThan( 0, $result['retry_after'] ?? 0 );
		$this->now += PageLock::FAIL_WINDOW + 1;
		$this->assertTrue( $lock->attempt( 1, 'session-b', self::PASSWORD )['ok'], 'Allowed again after the window' );
	}

	public function test_change_and_remove_need_the_current_password(): void {
		$lock = $this->enabled();
		self::expectRefused( 'current', fn () => $lock->set( 'a brand new password', 'a brand new password', 'wrong', 1, 'session-a' ) );
		$lock->set( 'a brand new password', 'a brand new password', self::PASSWORD, 1, 'session-a' );
		$this->assertTrue( $lock->attempt( 1, 'session-c', 'a brand new password' )['ok'] );

		self::expectRefused( 'current', fn () => $lock->remove( self::PASSWORD, 1 ) );
		$lock->remove( 'a brand new password', 1 );
		$this->assertFalse( $lock->enabled() );
	}

	public function test_relock_and_clear(): void {
		$lock = $this->enabled();
		$lock->relock( 1, 'session-a' );
		$this->assertFalse( $lock->isUnlocked( 1, 'session-a' ) );
		$lock->clear();
		$this->assertFalse( $lock->enabled(), 'Recovery from WP-CLI' );
		$this->assertTrue( $lock->isUnlocked( 1, 'session-a' ) );
	}

	public function test_empty_session_never_unlocks_by_itself(): void {
		$lock = $this->enabled();
		$this->assertFalse( $lock->isUnlocked( 1, '' ) );
		$this->assertFalse( $lock->attempt( 1, '', self::PASSWORD )['ok'], 'No login session: nothing to bind the unlock to' );
	}

	public function test_expiry_read_back_as_a_string_still_counts(): void {
		// Transients stored in the options table return scalars as strings.
		$lock = $this->enabled();
		foreach ( WpStubs::$transients as $key => $value ) {
			WpStubs::$transients[ $key ] = is_int( $value ) ? (string) $value : $value;
		}
		$this->assertTrue( $lock->isUnlocked( 1, 'session-a' ) );
	}
}
