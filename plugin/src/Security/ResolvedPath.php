<?php
/**
 * Result of a successful PathGuard resolution.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Security;

final class ResolvedPath {

	/**
	 * @param string $absolute Absolute, symlink-free path (native separators).
	 * @param string $relative Canonical path relative to ABSPATH, with `/` ('' for ABSPATH itself).
	 * @param bool   $exists   Whether the target exists.
	 * @param bool   $isDir    Whether the target is an existing directory.
	 */
	public function __construct(
		public readonly string $absolute,
		public readonly string $relative,
		public readonly bool $exists,
		public readonly bool $isDir,
	) {
	}
}
