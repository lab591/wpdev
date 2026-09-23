<?php
/**
 * A validated deploy operation, ready to be executed.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Deploy;

use Lab591\DevBridge\Security\ResolvedPath;

final class PlannedOp {

	/**
	 * @param string       $action   "write" or "delete".
	 * @param ResolvedPath $target   Target resolved by PathGuard (Write/WriteNew).
	 * @param string|null  $staged   Extracted and hash-verified content (write only).
	 * @param string|null  $newHash  Hash of the new content (write only).
	 * @param bool         $existed  Whether the target existed before the deploy.
	 * @param string|null  $prevHash Hash of the target before the deploy.
	 */
	public function __construct(
		public readonly string $action,
		public readonly ResolvedPath $target,
		public readonly ?string $staged,
		public readonly ?string $newHash,
		public readonly bool $existed,
		public readonly ?string $prevHash,
	) {
	}
}
