<?php
/**
 * WP-CLI: `wp devbridge status|enable|disable` (SPEC 2.12).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Cli;

use Lab591\DevBridge\Mode;
use Lab591\DevBridge\Plugin;
use const Lab591\DevBridge\VERSION;

/**
 * Manages the Dev Bridge development mode.
 */
final class Command {

	public function __construct( private readonly Plugin $plugin ) {
	}

	/**
	 * Shows mode, expiry and writable folders.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		$state = $this->plugin->mode()->state();
		\WP_CLI::line( 'Dev Bridge ' . VERSION );
		\WP_CLI::line( 'Mode:     ' . $state['mode'] . ( Mode::OFF !== $state['mode'] ? ' (expires ' . wp_date( 'Y-m-d H:i', $state['expires_at'] ) . ')' : '' ) );
		$roots = $this->plugin->validWritableRoots();
		\WP_CLI::line( 'Writable: ' . ( [] === $roots ? '(none)' : implode( ', ', $roots ) ) );
		$users = $this->plugin->settings()->allowedUserIds();
		\WP_CLI::line( 'Users:    ' . ( [] === $users ? '(none)' : implode( ', ', $users ) ) );
	}

	/**
	 * Enables development mode for a limited time.
	 *
	 * ## OPTIONS
	 *
	 * --mode=<mode>
	 * : read or write.
	 *
	 * --hours=<hours>
	 * : Duration in hours (read max 72, write max 8).
	 *
	 * ## EXAMPLES
	 *
	 *     wp devbridge enable --mode=write --hours=4
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function enable( array $args, array $assoc_args ): void {
		$mode  = (string) ( $assoc_args['mode'] ?? '' );
		$hours = (string) ( $assoc_args['hours'] ?? '' );
		if ( 1 !== preg_match( '/^\d+$/', $hours ) ) {
			\WP_CLI::error( '--hours must be a positive integer.' );
		}
		try {
			$state = $this->plugin->mode()->enable( $mode, (int) $hours, get_current_user_id() );
		} catch ( \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}
		\WP_CLI::success( sprintf( 'Mode "%s" enabled until %s.', $state['mode'], wp_date( 'Y-m-d H:i', $state['expires_at'] ) ) );
	}

	/**
	 * Disables development mode immediately.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function disable( array $args, array $assoc_args ): void {
		$this->plugin->mode()->disable();
		\WP_CLI::success( 'Development mode disabled.' );
	}
}
