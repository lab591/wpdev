<?php
/**
 * Result of a successful validation: the only input the writer accepts.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Deploy;

final class DeployPlan {

	/**
	 * @param list<PlannedOp> $ops
	 * @param string          $stagingDir Folder holding the staged files (removed after the deploy).
	 */
	public function __construct(
		public readonly array $ops,
		public readonly string $stagingDir,
	) {
	}

	public function count( string $action ): int {
		return count( array_filter( $this->ops, static fn ( PlannedOp $op ): bool => $action === $op->action ) );
	}
}
