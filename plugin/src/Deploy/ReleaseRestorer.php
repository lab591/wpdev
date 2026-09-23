<?php
/**
 * Undoes a release from its backup: existing files are restored, new files removed.
 *
 * Targets are re-validated with PathGuard (WriteNew) before touching the site.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Deploy;

use Lab591\DevBridge\Security\Access;
use Lab591\DevBridge\Security\PathGuard;

final class ReleaseRestorer {

	public function __construct(
		private readonly PathGuard $guard,
		private readonly ReleaseStore $store,
	) {
	}

	/**
	 * @param array<string, mixed> $release
	 * @return list<array{p: string, h: string|null}> Final state of every restored path.
	 * @throws \RuntimeException On the first failure (already restored files stay restored).
	 */
	public function restore( array $release ): array {
		$files = [];
		foreach ( array_reverse( (array) $release['ops'] ) as $op ) {
			$relative = (string) $op['p'];
			$target   = $this->guard->resolve( $relative, Access::WriteNew );
			$root     = $this->guard->writableRootFor( $target->absolute );
			if ( ! empty( $op['existed'] ) ) {
				$backup = $this->store->backupPath( (string) $release['id'], $relative );
				if ( ! is_file( $backup ) ) {
					throw new \RuntimeException( 'backup missing' );
				}
				FileWriter::writeAtomic( $backup, $target->absolute );
				$files[ $relative ] = [
					'p' => $relative,
					'h' => $op['prev_h'] ?? (string) hash_file( 'xxh128', $target->absolute ),
				];
			} else {
				FileWriter::delete( $target->absolute, (string) $root );
				$files[ $relative ] = [
					'p' => $relative,
					'h' => null,
				];
			}
		}
		return array_values( $files );
	}
}
