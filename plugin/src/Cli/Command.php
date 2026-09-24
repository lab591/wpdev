<?php
/**
 * WP-CLI: `wp devbridge status|enable|disable|releases|rollback|lock` (SPEC 2.12).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Cli;

use Lab591\DevBridge\Deploy\ReleaseStore;
use Lab591\DevBridge\Mode;
use Lab591\DevBridge\Plugin;
use Lab591\DevBridge\Support\ApiException;
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
	 * Shows or removes the password that protects the Dev Bridge admin page.
	 *
	 * ## OPTIONS
	 *
	 * [--clear]
	 * : Remove the password (for example when it has been forgotten).
	 *
	 * ## EXAMPLES
	 *
	 *     wp devbridge lock
	 *     wp devbridge lock --clear
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function lock( array $args, array $assoc_args ): void {
		$lock = $this->plugin->pageLock();
		if ( ! empty( $assoc_args['clear'] ) ) {
			$lock->clear();
			\WP_CLI::success( 'Page password removed: the Dev Bridge page is open to administrators again.' );
			return;
		}
		\WP_CLI::line( $lock->enabled() ? 'The Dev Bridge page is protected by a password (remove it with --clear).' : 'The Dev Bridge page is not protected by a password.' );
	}

	/**
	 * Lists deploy releases, newest first.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function releases( array $args, array $assoc_args ): void {
		$rows = [];
		foreach ( ( new ReleaseStore( $this->plugin->storage()->releasesDir() ) )->all() as $release ) {
			$rows[] = [
				'id'      => $release['id'],
				'date'    => wp_date( 'Y-m-d H:i', (int) $release['created_at'] ),
				'user'    => $release['user_id'],
				'written' => $release['written'],
				'deleted' => $release['deleted'],
				'status'  => $release['status'],
			];
		}
		if ( [] === $rows ) {
			\WP_CLI::line( 'No releases.' );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'date', 'user', 'written', 'deleted', 'status' ] );
	}

	/**
	 * Rolls back the latest active release, or the given one and every newer release.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Release id (default: latest active release).
	 *
	 * [--force]
	 * : Roll back even if files changed on the server after the release.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function rollback( array $args, array $assoc_args ): void {
		try {
			$out = $this->plugin->rollbackService()->rollback( $args[0] ?? null, isset( $assoc_args['force'] ) );
		} catch ( ApiException $e ) {
			$detail = '';
			if ( isset( $e->extra()['conflicts'] ) ) {
				$detail = ' ' . implode( ', ', array_column( $e->extra()['conflicts'], 'p' ) ) . ' (use --force)';
			}
			\WP_CLI::error( $e->getMessage() . $detail );
			return;
		}
		\WP_CLI::success( sprintf( 'Rolled back %s (%d files).', implode( ', ', $out['rolled_back'] ), count( $out['files'] ) ) );
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
