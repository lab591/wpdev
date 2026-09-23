<?php
/**
 * One manifest line.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Deploy;

final class ManifestEntry {

	/**
	 * @param string      $path   Path relative to ABSPATH as sent by the client (not yet validated).
	 * @param string      $action "write" or "delete".
	 * @param string|null $h      Hash of the new content (write only).
	 * @param string|null $baseH  Hash of the server file known to the client (null: new file).
	 */
	public function __construct(
		public readonly string $path,
		public readonly string $action,
		public readonly ?string $h,
		public readonly ?string $baseH,
	) {
	}
}
