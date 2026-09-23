<?php
/**
 * Post-deploy health check (SPEC 2.8).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Deploy;

interface HealthChecker {

	public const OK      = 'ok';
	public const FAIL    = 'fail';
	public const UNKNOWN = 'unknown';

	/**
	 * Current size of debug.log (0 when disabled): fatal errors are searched after this offset.
	 */
	public function logOffset(): int;

	/**
	 * @return array{status: string, checks: list<array<string, mixed>>, errors: list<string>, message?: string}
	 */
	public function check( int $logOffset ): array;
}
