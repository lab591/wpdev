<?php
/**
 * Health checker stub for deploy tests.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Support;

use Lab591\DevBridge\Deploy\HealthChecker;

final class FakeHealth implements HealthChecker {

	/** @var array{status: string, checks: list<array<string, mixed>>, errors: list<string>, message?: string} */
	public array $result = [
		'status' => HealthChecker::OK,
		'checks' => [],
		'errors' => [],
	];

	public function logOffset(): int {
		return 0;
	}

	/** @var list<string> Paths received by the last check. */
	public array $lastPaths = [];

	public function check( int $logOffset, array $extraPaths = [] ): array {
		$this->lastPaths = $extraPaths;
		return $this->result;
	}
}
