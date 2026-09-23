<?php
/**
 * Base exception carrying a stable API error code and an HTTP status.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Support;

class ApiException extends \RuntimeException {

	/**
	 * @param string               $errorCode Stable error code (see docs/protocol.md).
	 * @param string               $message   Human readable message, without absolute paths.
	 * @param int                  $status    HTTP status.
	 * @param array<string, mixed> $extra     Additional fields for the error payload.
	 */
	public function __construct(
		private readonly string $errorCode,
		string $message,
		private readonly int $status = 400,
		private readonly array $extra = [],
	) {
		parent::__construct( $message );
	}

	public function errorCode(): string {
		return $this->errorCode;
	}

	public function status(): int {
		return $this->status;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function extra(): array {
		return $this->extra;
	}
}
