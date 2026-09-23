<?php
/**
 * Manual rollback (`POST /rollback`, WP-CLI): undoes a release and every newer one.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Deploy;

use Lab591\DevBridge\Rescue\RescueTokens;
use Lab591\DevBridge\Security\Access;
use Lab591\DevBridge\Security\PathException;
use Lab591\DevBridge\Security\PathGuard;
use Lab591\DevBridge\Storage\Storage;
use Lab591\DevBridge\Support\ApiException;

final class RollbackService {

	public function __construct(
		private readonly PathGuard $guard,
		private readonly Storage $storage,
	) {
	}

	/**
	 * @return array{status: string, rolled_back: list<string>, files: list<array{p: string, h: string|null}>}
	 * @throws ApiException `no_release`, `conflict`, `deploy_locked`, `deploy_failed`.
	 */
	public function rollback( ?string $releaseId, bool $force ): array {
		$this->storage->ensure();
		$lock = new Lock( $this->storage->lockFile() );
		$lock->acquire();
		try {
			$store  = new ReleaseStore( $this->storage->releasesDir() );
			$active = array_values( array_filter( $store->all(), static fn ( array $r ): bool => in_array( $r['status'] ?? '', ReleaseStore::ACTIVE, true ) ) );
			if ( [] === $active ) {
				throw new ApiException( 'no_release', 'No active release to roll back', 404 );
			}
			$targets = [];
			foreach ( $active as $release ) {
				$targets[] = $release;
				if ( null === $releaseId || $release['id'] === $releaseId ) {
					break;
				}
			}
			if ( null !== $releaseId && end( $targets )['id'] !== $releaseId ) {
				throw new ApiException( 'no_release', 'Release not found or already rolled back', 404 );
			}

			if ( ! $force ) {
				$conflicts = $this->conflicts( $targets );
				if ( [] !== $conflicts ) {
					throw new ApiException( 'conflict', 'Files changed on the server after the release', 409, [ 'conflicts' => $conflicts ] );
				}
			}

			$restorer = new ReleaseRestorer( $this->guard, $store );
			$files    = [];
			$done     = [];
			foreach ( $targets as $release ) {
				try {
					foreach ( $restorer->restore( $release ) as $file ) {
						$files[ $file['p'] ] = $file;
					}
				} catch ( \Throwable $e ) {
					throw new ApiException( 'deploy_failed', 'Rollback failed while restoring a release', 500, [ 'rolled_back' => $done ] );
				}
				$release['status'] = ReleaseStore::STATUS_ROLLED_BACK;
				$store->save( $release );
				$done[] = (string) $release['id'];
			}
			( new RescueTokens( $this->storage->rescueFile() ) )->revoke();

			return [
				'status'      => 'ok',
				'rolled_back' => $done,
				'files'       => array_values( $files ),
			];
		} finally {
			$lock->release();
		}
	}

	/**
	 * Files whose current content differs from what the newest release left.
	 *
	 * @param list<array<string, mixed>> $releases Newest first.
	 * @return list<array{p: string, reason: string}>
	 */
	private function conflicts( array $releases ): array {
		$checked   = [];
		$conflicts = [];
		foreach ( $releases as $release ) {
			foreach ( (array) $release['ops'] as $op ) {
				$p = (string) $op['p'];
				if ( isset( $checked[ $p ] ) ) {
					continue;
				}
				$checked[ $p ] = true;
				try {
					$target = $this->guard->resolve( $p, Access::WriteNew );
				} catch ( PathException ) {
					$conflicts[] = [
						'p'      => $p,
						'reason' => 'denied',
					];
					continue;
				}
				$current = $target->exists ? (string) hash_file( 'xxh128', $target->absolute ) : null;
				$expect  = 'write' === $op['action'] ? ( $op['new_h'] ?? null ) : null;
				if ( $current !== $expect ) {
					$conflicts[] = [
						'p'      => $p,
						'reason' => null === $current ? 'deleted' : 'modified',
					];
				}
			}
		}
		return $conflicts;
	}
}
