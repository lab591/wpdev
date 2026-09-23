<?php
/**
 * Tail of `debug.log` (`GET /log`). The log path comes from WordPress configuration, never from the request.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Services;

use Lab591\DevBridge\Support\ApiException;

final class LogService {

	public const MAX_LINES      = 1000;
	public const LINE_MAX_CHARS = 2000;
	public const MAX_SCAN_BYTES = 4194304;
	private const CHUNK         = 65536;

	/**
	 * @param string|null $logFile     Log path from the WordPress configuration.
	 * @param string|null $stripPrefix Absolute site root removed from log lines (paths become relative).
	 */
	public function __construct(
		private readonly ?string $logFile,
		private readonly ?string $stripPrefix = null,
	) {
	}

	/**
	 * Removes the absolute site root from a log line, in both separator styles.
	 */
	public static function relativize( string $line, ?string $root ): string {
		if ( null === $root || '' === $root ) {
			return $line;
		}
		$root  = rtrim( $root, '/\\' );
		$forms = array_unique( [ $root . DIRECTORY_SEPARATOR, str_replace( '\\', '/', $root ) . '/', str_replace( '/', '\\', $root ) . '\\' ] );
		return str_ireplace( $forms, '', $line );
	}

	/**
	 * Resolves the debug log location from the WP_DEBUG_LOG constant value.
	 */
	public static function locate( mixed $debugLog, string $contentDir ): ?string {
		if ( true === $debugLog || '1' === $debugLog || 1 === $debugLog ) {
			return rtrim( $contentDir, '/\\' ) . DIRECTORY_SEPARATOR . 'debug.log';
		}
		if ( is_string( $debugLog ) && '' !== $debugLog && ! in_array( strtolower( $debugLog ), [ 'false', '0' ], true ) ) {
			return $debugLog;
		}
		return null;
	}

	/**
	 * @return array{lines: list<string>, truncated: bool}
	 */
	public function tail( int $lines = 200, ?int $since = null ): array {
		if ( null === $this->logFile || ! is_file( $this->logFile ) ) {
			throw new ApiException( 'log_disabled', 'debug.log is not enabled or does not exist', 404 );
		}
		$lines = max( 1, min( self::MAX_LINES, $lines ) );
		$all   = self::lastLines( $this->logFile, $lines + 1 );

		if ( null !== $since ) {
			$filtered = [];
			$current  = null;
			foreach ( $all as $line ) {
				$ts = self::timestamp( $line );
				if ( null !== $ts ) {
					$current = $ts;
				}
				if ( null !== $current && $current >= $since ) {
					$filtered[] = $line;
				}
			}
			$all = $filtered;
		}

		$truncated = count( $all ) > $lines;
		$all       = array_slice( $all, -$lines );
		return [
			'lines'     => array_map( fn ( string $l ): string => self::clip( self::relativize( $l, $this->stripPrefix ) ), $all ),
			'truncated' => $truncated,
		];
	}

	/**
	 * Unix timestamp of a PHP error_log line ("[23-Sep-2026 10:00:00 UTC] ..."), or null.
	 */
	public static function timestamp( string $line ): ?int {
		if ( 1 !== preg_match( '/^\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2})(?: ([A-Za-z_\/+\-0-9]+))?\]/', $line, $m ) ) {
			return null;
		}
		try {
			$tz   = isset( $m[2] ) && '' !== $m[2] ? new \DateTimeZone( $m[2] ) : new \DateTimeZone( 'UTC' );
			$date = \DateTimeImmutable::createFromFormat( 'd-M-Y H:i:s', $m[1], $tz );
		} catch ( \Exception ) {
			return null;
		}
		return false === $date ? null : $date->getTimestamp();
	}

	/**
	 * Reads the last $count lines scanning backwards in chunks (bounded by MAX_SCAN_BYTES).
	 *
	 * @return list<string>
	 */
	public static function lastLines( string $file, int $count ): array {
		$fh = fopen( $file, 'rb' );
		if ( false === $fh ) {
			return [];
		}
		fseek( $fh, 0, SEEK_END );
		$pos    = (int) ftell( $fh );
		$buffer = '';
		$read   = 0;
		while ( $pos > 0 && substr_count( $buffer, "\n" ) <= $count && $read < self::MAX_SCAN_BYTES ) {
			$len  = min( self::CHUNK, $pos );
			$pos -= $len;
			fseek( $fh, $pos );
			$buffer = (string) fread( $fh, $len ) . $buffer;
			$read  += $len;
		}
		fclose( $fh );
		$lines = preg_split( '/\r\n|\n|\r/', rtrim( $buffer, "\r\n" ) );
		if ( false === $lines ) {
			return [];
		}
		if ( $pos > 0 ) {
			array_shift( $lines ); // First line is partial.
		}
		return array_values( array_slice( $lines, -$count ) );
	}

	public static function clip( string $line ): string {
		if ( strlen( $line ) <= self::LINE_MAX_CHARS ) {
			return $line;
		}
		$head = function_exists( 'mb_strcut' ) ? mb_strcut( $line, 0, self::LINE_MAX_CHARS, 'UTF-8' ) : substr( $line, 0, self::LINE_MAX_CHARS );
		return $head . ' [...]';
	}
}
