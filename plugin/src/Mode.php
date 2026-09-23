<?php
/**
 * Development mode gate (SPEC 2.2). Changed only from wp-admin or WP-CLI, never via REST.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge;

use Lab591\DevBridge\Support\Options;

final class Mode {

	public const OPTION = 'devbridge_mode';
	public const OFF    = 'off';
	public const READ   = 'read';
	public const WRITE  = 'write';

	/** Hard upper bounds; settings can only lower them. */
	public const HARD_MAX_HOURS = [
		self::READ  => 72,
		self::WRITE => 8,
	];

	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * Current state with expiry applied.
	 *
	 * @return array{mode: string, expires_at: int, user_id: int, since: int}
	 */
	public function state(): array {
		$off    = [
			'mode'       => self::OFF,
			'expires_at' => 0,
			'user_id'    => 0,
			'since'      => 0,
		];
		$stored = Options::get( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			return $off;
		}
		$mode    = (string) ( $stored['mode'] ?? self::OFF );
		$expires = (int) ( $stored['expires_at'] ?? 0 );
		$since   = (int) ( $stored['since'] ?? 0 );
		if ( ! isset( self::HARD_MAX_HOURS[ $mode ] ) ) {
			return $off;
		}
		$now = time();
		// Expired, or an expiry beyond what could ever have been granted: treat as off.
		if ( $expires <= $now || $expires > $since + self::HARD_MAX_HOURS[ $mode ] * 3600 ) {
			if ( self::OFF !== $mode ) {
				Options::update( self::OPTION, $off, true );
			}
			return $off;
		}
		return [
			'mode'       => $mode,
			'expires_at' => $expires,
			'user_id'    => (int) ( $stored['user_id'] ?? 0 ),
			'since'      => $since,
		];
	}

	public function current(): string {
		return $this->state()['mode'];
	}

	/**
	 * Whether the active mode satisfies the required one (write implies read).
	 */
	public function allows( string $required ): bool {
		$current = $this->current();
		if ( self::READ === $required ) {
			return self::READ === $current || self::WRITE === $current;
		}
		return self::WRITE === $required && self::WRITE === $current;
	}

	public function maxHours( string $mode ): int {
		$hard       = self::HARD_MAX_HOURS[ $mode ] ?? 0;
		$configured = (int) $this->settings->get( self::READ === $mode ? 'max_read_hours' : 'max_write_hours' );
		return $configured > 0 ? min( $hard, $configured ) : $hard;
	}

	/**
	 * @throws \InvalidArgumentException On invalid mode or duration.
	 * @return array{mode: string, expires_at: int, user_id: int, since: int}
	 */
	public function enable( string $mode, int $hours, int $userId ): array {
		if ( ! isset( self::HARD_MAX_HOURS[ $mode ] ) ) {
			throw new \InvalidArgumentException( 'Mode must be "read" or "write".' );
		}
		$max = $this->maxHours( $mode );
		if ( $hours < 1 || $hours > $max ) {
			throw new \InvalidArgumentException( sprintf( 'Hours for "%s" must be between 1 and %d.', $mode, $max ) );
		}
		$now   = time();
		$state = [
			'mode'       => $mode,
			'expires_at' => $now + $hours * 3600,
			'user_id'    => $userId,
			'since'      => $now,
		];
		Options::update( self::OPTION, $state, true );
		return $state;
	}

	public function disable(): void {
		Options::update(
			self::OPTION,
			[
				'mode'       => self::OFF,
				'expires_at' => 0,
				'user_id'    => 0,
				'since'      => 0,
			],
			true
		);
	}
}
