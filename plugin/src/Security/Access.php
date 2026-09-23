<?php
/**
 * Access kinds for PathGuard.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Security;

enum Access {
	/** Read an existing file or directory inside `read_roots`. */
	case Read;
	/** Overwrite or delete an existing file inside `writable_roots`. */
	case Write;
	/** Create (or overwrite) a file inside `writable_roots`; parent folders may not exist yet. */
	case WriteNew;

	public function isWrite(): bool {
		return self::Read !== $this;
	}
}
