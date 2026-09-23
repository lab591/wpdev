<?php
/**
 * Deploy orchestration (SPEC 2.7): lock → validate → backup → atomic writes → rescue token
 * → health check → automatic rollback on failure.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Deploy;

use Lab591\DevBridge\Rescue\RescueTokens;
use Lab591\DevBridge\Security\PathGuard;
use Lab591\DevBridge\Storage\Storage;
use Lab591\DevBridge\Support\ApiException;

final class Deployer {

	/**
	 * @param array{deploy_zip_bytes: int, deploy_files: int, deploy_file_bytes: int}    $limits
	 * @param array{ip_allowlist: string[], trusted_proxies: string[], allow_http: bool} $rescueContext
	 */
	public function __construct(
		private readonly PathGuard $guard,
		private readonly Storage $storage,
		private readonly HealthChecker $health,
		private readonly array $limits,
		private readonly int $retention,
		private readonly array $rescueContext,
	) {
	}

	/**
	 * @return array<string, mixed> Response payload (SPEC 2.7 step 9).
	 * @throws ApiException On validation errors, conflicts, lock contention or write failures.
	 */
	public function deploy( Manifest $manifest, ?string $zipPath, int $userId ): array {
		$this->storage->ensure();
		$lock = new Lock( $this->storage->lockFile() );
		$lock->acquire();
		$store  = new ReleaseStore( $this->storage->releasesDir() );
		$rescue = new RescueTokens( $this->storage->rescueFile() );
		$plan   = null;
		try {
			$plan = ( new DeployValidator( $this->guard, $this->limits, $this->storage->tmpDir() ) )->validate( $manifest, $zipPath );
			if ( [] === $plan->ops ) {
				return [
					'release_id' => null,
					'status'     => ReleaseStore::STATUS_OK,
					'written'    => 0,
					'deleted'    => 0,
					'health'     => [
						'status' => 'skipped',
						'checks' => [],
					],
				];
			}

			$offset  = $this->health->logOffset();
			$id      = ReleaseStore::newId();
			$release = $store->create( $id, $userId, $plan );

			$applied = [];
			try {
				foreach ( $plan->ops as $op ) {
					if ( 'write' === $op->action ) {
						FileWriter::writeAtomic( (string) $op->staged, $op->target->absolute );
					} else {
						FileWriter::delete( $op->target->absolute, (string) $this->guard->writableRootFor( $op->target->absolute ) );
					}
					$applied[] = $op;
				}
			} catch ( \Throwable $e ) {
				$this->undo( $store, $release, count( $applied ) );
				$release['status'] = ReleaseStore::STATUS_FAILED;
				$store->save( $release );
				throw new ApiException( 'deploy_failed', 'Write failed: files already written were restored from the backup', 500 );
			}

			$token  = $rescue->issue( $id, $this->rescueContext['ip_allowlist'], $this->rescueContext['trusted_proxies'], $this->rescueContext['allow_http'] );
			$health = $this->health->check( $offset, $manifest->healthPaths );

			$response = [
				'release_id' => $id,
				'status'     => ReleaseStore::STATUS_OK,
				'written'    => $release['written'],
				'deleted'    => $release['deleted'],
				'health'     => array_diff_key( $health, [ 'errors' => true ] ),
			];
			if ( [] !== $health['errors'] ) {
				$response['errors'] = $health['errors'];
			}

			if ( HealthChecker::FAIL === $health['status'] ) {
				$this->undo( $store, $release, count( $plan->ops ) );
				$rescue->revoke();
				$release['status']  = ReleaseStore::STATUS_ROLLED_BACK;
				$response['status'] = ReleaseStore::STATUS_ROLLED_BACK;
			} else {
				$release['status']        = HealthChecker::UNKNOWN === $health['status'] ? ReleaseStore::STATUS_UNKNOWN : ReleaseStore::STATUS_OK;
				$response['status']       = $release['status'];
				$response['rescue_token'] = $token;
			}
			$release['health'] = $health['status'];
			$store->save( $release );
			$store->rotate( $this->retention );
			return $response;
		} finally {
			if ( null !== $plan ) {
				DeployValidator::removeDir( $plan->stagingDir );
			}
			$lock->release();
		}
	}

	/**
	 * Restores the first $count operations of a release (in reverse order).
	 *
	 * @param array<string, mixed> $release
	 */
	private function undo( ReleaseStore $store, array $release, int $count ): void {
		$partial        = $release;
		$partial['ops'] = array_slice( (array) $release['ops'], 0, $count );
		( new ReleaseRestorer( $this->guard, $store ) )->restore( $partial );
	}
}
