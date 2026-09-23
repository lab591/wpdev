<?php
/**
 * Development mode and rate limiter tests (SPEC 2.2, 2.3).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Core;

use Lab591\DevBridge\Mode;
use Lab591\DevBridge\Security\RateLimiter;
use Lab591\DevBridge\Settings;
use Lab591\DevBridge\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class ModeTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	public function test_default_is_off(): void {
		$mode = new Mode( new Settings() );
		$this->assertSame( 'off', $mode->current() );
		$this->assertFalse( $mode->allows( 'read' ) );
	}

	public function test_enable_read_and_write(): void {
		$mode = new Mode( new Settings() );
		$mode->enable( 'read', 72, 1 );
		$this->assertTrue( $mode->allows( 'read' ) );
		$this->assertFalse( $mode->allows( 'write' ) );
		$mode->enable( 'write', 8, 1 );
		$this->assertTrue( $mode->allows( 'read' ) );
		$this->assertTrue( $mode->allows( 'write' ) );
		$this->assertEqualsWithDelta( time() + 8 * 3600, $mode->state()['expires_at'], 2 );
	}

	public function test_hours_limits(): void {
		$mode = new Mode( new Settings() );
		foreach ( [ [ 'write', 9 ], [ 'read', 73 ], [ 'read', 0 ], [ 'write', -1 ], [ 'off', 1 ], [ 'admin', 1 ] ] as [ $m, $h ] ) {
			try {
				$mode->enable( $m, $h, 1 );
				$this->fail( "Accepted $m for $h hours" );
			} catch ( \InvalidArgumentException ) {
				$this->assertSame( 'off', $mode->current() );
			}
		}
	}

	public function test_configured_maximum_can_only_lower(): void {
		WpStubs::$options[ Settings::OPTION ] = [
			'max_write_hours' => 2,
			'max_read_hours'  => 500,
		];
		$mode                                 = new Mode( new Settings() );
		$this->assertSame( 2, $mode->maxHours( 'write' ) );
		$this->assertSame( 72, $mode->maxHours( 'read' ) );
		$this->expectException( \InvalidArgumentException::class );
		$mode->enable( 'write', 3, 1 );
	}

	public function test_expired_mode_turns_off(): void {
		WpStubs::$options[ Mode::OPTION ] = [
			'mode'       => 'write',
			'expires_at' => time() - 1,
			'since'      => time() - 3600,
			'user_id'    => 1,
		];
		$mode                             = new Mode( new Settings() );
		$this->assertSame( 'off', $mode->current() );
		$this->assertSame( 'off', WpStubs::$options[ Mode::OPTION ]['mode'] );
	}

	public function test_tampered_expiry_beyond_hard_max_is_off(): void {
		WpStubs::$options[ Mode::OPTION ] = [
			'mode'       => 'write',
			'expires_at' => time() + 86400 * 30,
			'since'      => time(),
			'user_id'    => 1,
		];
		$this->assertSame( 'off', ( new Mode( new Settings() ) )->current() );
	}

	public function test_disable(): void {
		$mode = new Mode( new Settings() );
		$mode->enable( 'write', 1, 1 );
		$mode->disable();
		$this->assertSame( 'off', $mode->current() );
	}

	public function test_rate_limiter_window(): void {
		$rl  = new RateLimiter();
		$now = 1_000_040; // 20 s into the window starting at 1_000_020.
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertNull( $rl->hit( 7, 'write', 10, $now ) );
		}
		$this->assertSame( 40, $rl->hit( 7, 'write', 10, $now ) );
		$this->assertNull( $rl->hit( 8, 'write', 10, $now ), 'Buckets are per user' );
		$this->assertNull( $rl->hit( 7, 'read', 10, $now ), 'Buckets are per kind' );
		$this->assertNull( $rl->hit( 7, 'write', 10, $now + 60 ), 'New window' );
	}
}
