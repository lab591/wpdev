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

	public const WARNING_PATTERN = '/PHP (User )?(Warning|Notice|Deprecated|Strict Standards)/';

	/** Distinct new warnings collected per check (the deployer keeps those in the deployed files). */
	public const MAX_WARNINGS = 200;

	/**
	 * @param string[] $urls        Same-host URLs to request.
	 * @param string[] $backendUrls Back-end URLs (login page, admin-ajax ping): a fatal error that only
	 *                              hits the admin must not lock the administrator out.
	 */
	public function __construct(
		private readonly array $urls,
		private readonly ?string $logFile,
		private readonly ?string $stripPrefix = null,
		private readonly array $backendUrls = [],
	) {
	}

	public function logOffset(): int {
		if ( null === $this->logFile || ! is_file( $this->logFile ) ) {
			return 0;
		}
		clearstatcache( true, $this->logFile );
		return (int) filesize( $this->logFile );
	}

	public function check( int $logOffset, array $extraPaths = [] ): array {
		$checks  = [];
		$failed  = false;
		$unknown = false;
		$targets = [];
		foreach ( $this->urls as $url ) {
			$targets[ $url ] = 'admin';
		}
		foreach ( $this->backendUrls as $url ) {
			$targets[ $url ] = $targets[ $url ] ?? 'backend';
		}
		foreach ( $extraPaths as $path ) {
			// Full URLs were already restricted to sites of the network by HealthPaths.
			$url = 1 === preg_match( '#^https?://#i', $path ) ? $path : home_url( $path );
			if ( ! isset( $targets[ $url ] ) ) {
				$targets[ $url ] = 'agent';
			}
		}
		foreach ( $targets as $url => $source ) {
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
				'url'    => $url,
				'source' => $source,
				'ms'     => (int) round( ( microtime( true ) - $started ) * 1000 ),
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

		[ 'fatal' => $errors, 'warnings' => $warnings ] = $this->linesSince( $logOffset );
		if ( [] !== $errors ) {
			$failed = true;
		}

		$result = [
			'status'   => $failed ? self::FAIL : ( $unknown ? self::UNKNOWN : self::OK ),
			'checks'   => $checks,
			'errors'   => $errors,
			'warnings' => $warnings,
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
	public function checkRecent( int $seconds = 300, array $extraPaths = [] ): array {
		$result = $this->check( $this->logOffset(), $extraPaths );
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
	 * Fatal/parse error lines appended to debug.log after $offset (used by the preview check).
	 *
	 * @return list<string>
	 */
	public function fatalSince( int $offset ): array {
		return $this->linesSince( $offset )['fatal'];
	}

	/**
	 * Fatal/parse error lines and distinct warning lines appended to debug.log after $offset.
	 *
	 * @return array{fatal: list<string>, warnings: list<string>}
	 */
	private function linesSince( int $offset ): array {
		$none = [
			'fatal'    => [],
			'warnings' => [],
		];
		if ( null === $this->logFile || ! is_file( $this->logFile ) ) {
			return $none;
		}
		clearstatcache( true, $this->logFile );
		$size = (int) filesize( $this->logFile );
		if ( $size < $offset ) {
			$offset = 0; // Rotated or truncated.
		}
		if ( $size === $offset ) {
			return $none;
		}
		$fh = fopen( $this->logFile, 'rb' );
		if ( false === $fh ) {
			return $none;
		}
		fseek( $fh, max( $offset, $size - self::MAX_LOG_BYTES ) );
		$chunk = (string) stream_get_contents( $fh );
		fclose( $fh );
		$errors   = [];
		$warnings = [];
		$lines    = preg_split( '/\r\n|\n|\r/', $chunk );
		foreach ( false === $lines ? [] : $lines as $line ) {
			if ( 1 === preg_match( self::FATAL_PATTERN, $line ) ) {
				if ( count( $errors ) < self::MAX_ERRORS ) {
					$errors[] = LogService::clip( LogService::relativize( $line, $this->stripPrefix ) );
				}
			} elseif ( count( $warnings ) < self::MAX_WARNINGS && 1 === preg_match( self::WARNING_PATTERN, $line ) ) {
				// Same warning on every request: keep one copy, without the timestamp.
				$text              = LogService::clip( LogService::relativize( (string) preg_replace( '/^\[[^\]]*\]\s*/', '', $line ), $this->stripPrefix ) );
				$warnings[ $text ] = true;
			}
		}
		return [
			'fatal'    => $errors,
			'warnings' => array_keys( $warnings ),
		];
	}
}
