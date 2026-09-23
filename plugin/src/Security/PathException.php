<?php
/**
 * Rejection raised by PathGuard. Messages never contain absolute server paths.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Security;

use Lab591\DevBridge\Support\ApiException;

final class PathException extends ApiException {

	public static function invalid( string $reason ): self {
		return new self( 'path_invalid', 'Invalid path: ' . $reason, 400 );
	}

	public static function denied( string $reason = 'path not allowed' ): self {
		return new self( 'path_denied', 'Access denied: ' . $reason, 403 );
	}

	public static function extension( string $reason ): self {
		return new self( 'extension_denied', 'File name not allowed for writing: ' . $reason, 403 );
	}

	public static function notFound(): self {
		return new self( 'not_found', 'File or directory not found', 404 );
	}

	public static function notAFile(): self {
		return new self( 'not_a_file', 'Path is not a file', 400 );
	}

	public static function notADirectory(): self {
		return new self( 'not_a_directory', 'Path is not a directory', 400 );
	}
}
