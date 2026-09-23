<?php
/**
 * Fixed-window per-user rate limiter backed by transients (SPEC 2.3).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Security;

use Lab591\DevBridge\Support\Options;

final class RateLimiter {

	public const WINDOW = 60;

	/**
	 * Counts a request; returns null when allowed, or the seconds to wait.
	 */
	public function hit( int $userId, string $bucket, int $limit, ?int $now = null ): ?int {
		$now    = $now ?? time();
		$window = intdiv( $now, self::WINDOW ) * self::WINDOW;
		$key    = sprintf( 'devbridge_rl_%s_%d_%d', $bucket, $userId, $window );
		$count  = (int) Options::getTransient( $key );
		if ( $count >= $limit ) {
			return max( 1, $window + self::WINDOW - $now );
		}
		Options::setTransient( $key, $count + 1, self::WINDOW * 2 );
		return null;
	}
}
