<?php
/**
 * Optional password in front of the Dev Bridge admin page (0.7.0).
 *
 * For sites whose owner is an administrator but not a developer: with a password set, the page
 * shows nothing but a password field and every admin action is refused until the page is
 * unlocked. It prevents accidental changes; it is not a barrier against an administrator who
 * means harm (they can deactivate plugins or edit the database anyway).
 *
 * - Only a hash is stored (wp_hash_password), in its own option, never exposed by any API.
 * - An unlock is bound to the user AND the login session (wp_get_session_token): another
 *   browser, another user or a new login starts locked. It lasts TTL seconds since the last use.
 * - MAX_FAILS wrong passwords (also when changing or removing it) block that user for FAIL_WINDOW.
 * - Forgotten password: `wp devbridge lock --clear` (server access).
 *
 * Expiries are stored in the values and checked against the clock, so they do not depend on how
 * the transient backend (database or object cache) handles expiration.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Admin;

use Lab591\DevBridge\Support\Options;

final class PageLock {

	public const OPTION      = 'devbridge_page_lock';
	public const MIN_LENGTH  = 10;
	public const TTL         = 1800;
	public const MAX_FAILS   = 5;
	public const FAIL_WINDOW = 900;

	/** @var \Closure(): int */
	private readonly \Closure $clock;

	/**
	 * @param (\Closure(): int)|null $clock Current time (tests).
	 */
	public function __construct( ?\Closure $clock = null ) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	public function enabled(): bool {
		$data = Options::get( self::OPTION, [] );
		return is_array( $data ) && is_string( $data['hash'] ?? null ) && '' !== $data['hash'];
	}

	/**
	 * Whether this user, in this login session, may use the page; renews the unlock when it does.
	 */
	public function isUnlocked( int $userId, string $session ): bool {
		if ( ! $this->enabled() ) {
			return true;
		}
		if ( $userId <= 0 || '' === $session ) {
			return false;
		}
		$key     = self::unlockKey( $userId, $session );
		$expires = Options::getTransient( $key );
		// Stored in the options table, a plain number comes back as a string.
		if ( ! is_numeric( $expires ) || (int) $expires < $this->now() ) {
			Options::deleteTransient( $key );
			return false;
		}
		$this->grant( $userId, $session );
		return true;
	}

	/**
	 * Tries to unlock the page with $password.
	 *
	 * @return array{ok: bool, retry_after?: int} `retry_after`: seconds to wait after too many failures.
	 */
	public function attempt( int $userId, string $session, string $password ): array {
		$wait = $this->throttled( $userId );
		if ( $wait > 0 ) {
			return [
				'ok'          => false,
				'retry_after' => $wait,
			];
		}
		if ( $userId <= 0 || '' === $session || ! $this->matches( $password ) ) {
			$this->fail( $userId );
			$wait = $this->throttled( $userId );
			return $wait > 0 ? [
				'ok'          => false,
				'retry_after' => $wait,
			] : [ 'ok' => false ];
		}
		Options::deleteTransient( self::failKey( $userId ) );
		$this->grant( $userId, $session );
		return [ 'ok' => true ];
	}

	/**
	 * Sets or changes the password; the session that sets it stays unlocked.
	 *
	 * @param string $current Current password (required when changing it).
	 * @throws \InvalidArgumentException With a translated message.
	 */
	public function set( string $password, string $confirm, string $current, int $userId, string $session ): void {
		if ( $this->enabled() ) {
			$this->requireCurrent( $current, $userId );
		}
		if ( mb_strlen( $password ) < self::MIN_LENGTH ) {
			/* translators: %d: minimum number of characters. */
			throw new \InvalidArgumentException( sprintf( __( 'The password must be at least %d characters long.', 'lab591-dev-bridge' ), self::MIN_LENGTH ) );
		}
		if ( $password !== $confirm ) {
			throw new \InvalidArgumentException( __( 'The two passwords do not match.', 'lab591-dev-bridge' ) );
		}
		Options::update(
			self::OPTION,
			[
				'hash'   => wp_hash_password( $password ),
				'set_by' => $userId,
				'set_at' => $this->now(),
			]
		);
		if ( '' !== $session && $userId > 0 ) {
			$this->grant( $userId, $session );
		}
	}

	/**
	 * Removes the protection.
	 *
	 * @throws \InvalidArgumentException When the current password is wrong or attempts are throttled.
	 */
	public function remove( string $current, int $userId ): void {
		$this->requireCurrent( $current, $userId );
		Options::delete( self::OPTION );
	}

	/** Locks the page again for this session. */
	public function relock( int $userId, string $session ): void {
		Options::deleteTransient( self::unlockKey( $userId, $session ) );
	}

	/** Recovery (WP-CLI): removes the password. Unlock entries expire by themselves. */
	public function clear(): void {
		Options::delete( self::OPTION );
	}

	// ------------------------------------------------------------------ internals

	/**
	 * @throws \InvalidArgumentException When the password is wrong or attempts are throttled.
	 */
	private function requireCurrent( string $current, int $userId ): void {
		$wait = $this->throttled( $userId );
		if ( $wait > 0 ) {
			throw new \InvalidArgumentException( self::throttleMessage( $wait ) );
		}
		if ( ! $this->matches( $current ) ) {
			$this->fail( $userId );
			throw new \InvalidArgumentException( __( 'The current password is wrong.', 'lab591-dev-bridge' ) );
		}
		Options::deleteTransient( self::failKey( $userId ) );
	}

	public static function throttleMessage( int $seconds ): string {
		$minutes = max( 1, (int) ceil( $seconds / 60 ) );
		/* translators: %d: minutes. */
		return sprintf( _n( 'Too many wrong passwords: try again in %d minute.', 'Too many wrong passwords: try again in %d minutes.', $minutes, 'lab591-dev-bridge' ), $minutes );
	}

	private function matches( string $password ): bool {
		$data = Options::get( self::OPTION, [] );
		$hash = is_array( $data ) ? (string) ( $data['hash'] ?? '' ) : '';
		return '' !== $hash && '' !== $password && wp_check_password( $password, $hash );
	}

	private function grant( int $userId, string $session ): void {
		Options::setTransient( self::unlockKey( $userId, $session ), $this->now() + self::TTL, self::TTL );
	}

	private function fail( int $userId ): void {
		$key  = self::failKey( $userId );
		$data = Options::getTransient( $key );
		$now  = $this->now();
		if ( ! is_array( $data ) || (int) ( $data['since'] ?? 0 ) + self::FAIL_WINDOW < $now ) {
			$data = [
				'count' => 0,
				'since' => $now,
			];
		}
		++$data['count'];
		Options::setTransient( $key, $data, self::FAIL_WINDOW );
	}

	/** Seconds this user still has to wait, 0 when attempts are allowed. */
	private function throttled( int $userId ): int {
		$data = Options::getTransient( self::failKey( $userId ) );
		if ( ! is_array( $data ) || (int) ( $data['count'] ?? 0 ) < self::MAX_FAILS ) {
			return 0;
		}
		return max( 0, (int) $data['since'] + self::FAIL_WINDOW - $this->now() );
	}

	private static function unlockKey( int $userId, string $session ): string {
		// Only a hash of the session token is used in the key.
		return 'devbridge_unlock_' . substr( hash( 'sha256', $userId . '|' . $session ), 0, 32 );
	}

	private static function failKey( int $userId ): string {
		return 'devbridge_lockfail_' . $userId;
	}

	private function now(): int {
		return ( $this->clock )();
	}
}
