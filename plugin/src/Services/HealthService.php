<?php
/**
 * Health check via loopback requests and debug.log (SPEC 2.8).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Services;

use Lab591\DevBridge\Deploy\HealthChecker;

final class HealthService implements HealthChecker {

	public const MAX_ERRORS    = 20;
	public const MAX_LOG_BYTES = 1048576;
	public const FATAL_PATTERN = '/PHP (Fatal|Parse) error/';

	/**
	 * @param string[] $urls Same-host URLs to request.
	 */
	public function __construct(
		private readonly array $urls,
		private readonly ?string $logFile,
		private readonly ?string $stripPrefix = null,
	) {
	}

	public function logOffset(): int {
		if ( null === $this->logFile || ! is_file( $this->logFile ) ) {
			return 0;
		}
		clearstatcache( true, $this->logFile );
		return (int) filesize( $this->logFile );
	}

	public function check( int $logOffset ): array {
		$checks  = [];
		$failed  = false;
		$unknown = false;
		foreach ( $this->urls as $url ) {
			$started  = microtime( true );
			$response = wp_remote_get(
				add_query_arg( 'devbridge_health', bin2hex( random_bytes( 4 ) ), $url ),
				[
					'timeout'     => 10,
					'sslverify'   => true,
					'redirection' => 3,
					'headers'     => [ 'Cache-Control' => 'no-cache' ],
				]
			);
			$check    = [
				'url' => $url,
				'ms'  => (int) round( ( microtime( true ) - $started ) * 1000 ),
			];
			if ( is_wp_error( $response ) ) {
				$check['error'] = $response->get_error_message();
				$unknown        = true;
			} else {
				$check['code'] = (int) wp_remote_retrieve_response_code( $response );
				if ( $check['code'] >= 500 ) {
					$failed = true;
				}
			}
			$checks[] = $check;
		}

		$errors = $this->fatalLinesSince( $logOffset );
		if ( [] !== $errors ) {
			$failed = true;
		}

		$result = [
			'status' => $failed ? self::FAIL : ( $unknown ? self::UNKNOWN : self::OK ),
			'checks' => $checks,
			'errors' => $errors,
		];
		if ( ! $failed && $unknown ) {
			$result['message'] = 'Loopback request failed (network error or blocked by the host): changes were kept, check the site manually.';
		}
		return $result;
	}

	/**
	 * On-demand check (`POST /health`): fatal lines written in the last $seconds.
	 *
	 * @return array{status: string, checks: list<array<string, mixed>>, errors: list<string>, message?: string}
	 */
	public function checkRecent( int $seconds = 300 ): array {
		$result = $this->check( $this->logOffset() );
		if ( null !== $this->logFile && is_file( $this->logFile ) ) {
			$since  = time() - $seconds;
			$recent = [];
			foreach ( LogService::lastLines( $this->logFile, 1000 ) as $line ) {
				$ts = LogService::timestamp( $line );
				if ( null !== $ts && $ts >= $since && 1 === preg_match( self::FATAL_PATTERN, $line ) ) {
					$recent[] = LogService::clip( LogService::relativize( $line, $this->stripPrefix ) );
				}
			}
			$result['errors'] = array_slice( $recent, -self::MAX_ERRORS );
			if ( [] !== $result['errors'] ) {
				$result['status'] = self::FAIL;
				unset( $result['message'] );
			}
		}
		return $result;
	}

	/**
	 * Fatal/parse error lines appended to debug.log after $offset.
	 *
	 * @return list<string>
	 */
	private function fatalLinesSince( int $offset ): array {
		if ( null === $this->logFile || ! is_file( $this->logFile ) ) {
			return [];
		}
		clearstatcache( true, $this->logFile );
		$size = (int) filesize( $this->logFile );
		if ( $size < $offset ) {
			$offset = 0; // Rotated or truncated.
		}
		if ( $size === $offset ) {
			return [];
		}
		$fh = fopen( $this->logFile, 'rb' );
		if ( false === $fh ) {
			return [];
		}
		fseek( $fh, max( $offset, $size - self::MAX_LOG_BYTES ) );
		$chunk = (string) stream_get_contents( $fh );
		fclose( $fh );
		$errors = [];
		$lines  = preg_split( '/\r\n|\n|\r/', $chunk );
		foreach ( false === $lines ? [] : $lines as $line ) {
			if ( 1 === preg_match( self::FATAL_PATTERN, $line ) ) {
				$errors[] = LogService::clip( LogService::relativize( $line, $this->stripPrefix ) );
				if ( count( $errors ) >= self::MAX_ERRORS ) {
					break;
				}
			}
		}
		return $errors;
	}
}
