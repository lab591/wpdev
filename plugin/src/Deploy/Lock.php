<?php
/**
 * Exclusive, non-blocking deploy lock based on flock().
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Deploy;

use Lab591\DevBridge\Support\ApiException;

final class Lock {

	/** @var resource|null */
	private $handle = null;

	public function __construct( private readonly string $file ) {
	}

	/**
	 * @throws ApiException `deploy_locked` (409) when another deploy/rollback holds the lock.
	 */
	public function acquire(): void {
		$handle = fopen( $this->file, 'c' );
		if ( false === $handle ) {
			throw new ApiException( 'internal_error', 'Lock file could not be opened', 500 );
		}
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle );
			throw new ApiException( 'deploy_locked', 'Another deploy or rollback is in progress', 409 );
		}
		$this->handle = $handle;
	}

	public function release(): void {
		if ( null !== $this->handle ) {
			flock( $this->handle, LOCK_UN );
			fclose( $this->handle );
			$this->handle = null;
		}
	}

	public function __destruct() {
		$this->release();
	}
}
