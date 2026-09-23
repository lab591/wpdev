<?php
/**
 * Optional `/grep` acceleration with ripgrep (M3). Disabled by default.
 *
 * Ripgrep runs through proc_open() in array form (no shell). Every reported file is
 * re-validated with PathGuard, so the deny list and the roots apply exactly as in the PHP
 * implementation; ripgrep only makes the scan faster.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Services;

use Lab591\DevBridge\Security\Access;
use Lab591\DevBridge\Security\Glob;
use Lab591\DevBridge\Security\PathException;
use Lab591\DevBridge\Security\PathGuard;
use Lab591\DevBridge\Security\ResolvedPath;

final class RipgrepSearcher {

	private const READ_CHUNK = 65536;
	private const POLL_USEC  = 10000;

	/**
	 * @param string   $binary       Absolute path to rg (or "rg" from PATH), configured by an administrator.
	 * @param string[] $skipDirNames Folder names never searched.
	 * @param string   $tmpDir       Private folder for rg output (pipes cannot be polled on Windows).
	 */
	public function __construct(
		private readonly PathGuard $guard,
		private readonly string $binary,
		private readonly int $maxFileBytes,
		private readonly array $skipDirNames,
		private readonly bool $pcre2,
		private readonly string $tmpDir,
	) {
	}

	/**
	 * Whether a configured binary value is acceptable: "rg" or an absolute path to rg/rg.exe.
	 */
	public static function isValidBinary( string $binary ): bool {
		if ( 'rg' === $binary ) {
			return true;
		}
		$name = strtolower( basename( str_replace( '\\', '/', $binary ) ) );
		return in_array( $name, [ 'rg', 'rg.exe' ], true ) && is_file( $binary );
	}

	/**
	 * Runs `rg --pcre2-version`: true when PCRE2 regexes are supported.
	 */
	public static function detectPcre2( string $binary, string $tmpDir ): bool {
		$out = self::run( [ $binary, '--pcre2-version' ], $tmpDir, 5.0 );
		return null !== $out && 0 === $out['code'];
	}

	/**
	 * Runs `rg --version`: the first line (e.g. "ripgrep 14.1.1"), or null when rg cannot run.
	 */
	public static function version( string $binary, string $tmpDir ): ?string {
		$out = self::run( [ $binary, '--version' ], $tmpDir, 5.0 );
		if ( null === $out || 0 !== $out['code'] ) {
			return null;
		}
		$line = trim( explode( "\n", $out['output'] )[0] );
		return '' === $line ? null : substr( $line, 0, 80 );
	}

	public function supports( bool $regex ): bool {
		return ! $regex || $this->pcre2;
	}

	/**
	 * @return array<string, mixed>|null Same shape as GrepService::grep(), or null when rg cannot run.
	 */
	public function search( ResolvedPath $root, string $pattern, bool $regex, bool $caseSensitive, ?string $glob, int $maxResults, int $context, float $deadline ): ?array {
		$args = [
			$this->binary,
			'--json',
			'--no-config',
			'--no-ignore',
			'--hidden',
			'--no-follow',
			'--glob-case-insensitive',
			'--max-filesize',
			(string) $this->maxFileBytes,
			'--context',
			(string) $context,
			$caseSensitive ? '--case-sensitive' : '--ignore-case',
			$regex ? '--pcre2' : '--fixed-strings',
		];
		foreach ( $this->skipDirNames as $name ) {
			$args[] = '--glob';
			$args[] = '!**/' . $name . '/**';
		}
		if ( null !== $glob && ! str_contains( $glob, '/' ) ) {
			$args[] = '--glob';
			$args[] = $glob;
		}
		$args[] = '--regexp';
		$args[] = $pattern;
		$args[] = '--';
		$args[] = $root->absolute;

		$outFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'rg-' . bin2hex( random_bytes( 6 ) ) . '.json';
		$process = self::start( $args, $outFile );
		if ( null === $process ) {
			return null;
		}
		$reader = fopen( $outFile, 'rb' );
		if ( false === $reader ) {
			proc_terminate( $process );
			proc_close( $process );
			self::unlinkQuietly( $outFile );
			return null;
		}

		$matches  = [];
		$reason   = null;
		$sawEvent = false;
		$buffer   = '';
		$file     = null;
		$current  = null;
		$allowed  = [];
		$exitCode = null;

		while ( true ) {
			$chunk = (string) fread( $reader, self::READ_CHUNK );
			if ( '' === $chunk ) {
				if ( null !== $exitCode ) {
					break; // Process ended and the output file is fully consumed.
				}
				if ( microtime( true ) > $deadline ) {
					$reason = 'time_budget';
					break;
				}
				$status = proc_get_status( $process );
				if ( ! $status['running'] ) {
					$exitCode = (int) $status['exitcode'];
					continue; // One more read to drain what was written before exiting.
				}
				usleep( self::POLL_USEC );
				clearstatcache( true, $outFile );
				fseek( $reader, 0, SEEK_CUR );
				continue;
			}
			$buffer .= $chunk;
			while ( true ) {
				$nl = strpos( $buffer, "\n" );
				if ( false === $nl ) {
					break;
				}
				$line   = substr( $buffer, 0, $nl );
				$buffer = substr( $buffer, $nl + 1 );
				$event  = json_decode( $line, true );
				if ( ! is_array( $event ) || ! isset( $event['type'] ) ) {
					continue;
				}
				$sawEvent = true;
				$type     = $event['type'];
				$data     = is_array( $event['data'] ?? null ) ? $event['data'] : [];
				if ( 'summary' === $type ) {
					continue;
				}
				$path = $data['path']['text'] ?? null;
				if ( 'begin' === $type ) {
					$file    = is_string( $path ) ? $this->allowedFile( $path, $glob, $allowed ) : null;
					$current = [
						'lines'   => [],
						'matches' => [],
					];
					continue;
				}
				if ( null === $file || null === $current ) {
					continue;
				}
				if ( 'match' === $type || 'context' === $type ) {
					$number = (int) ( $data['line_number'] ?? 0 );
					$text   = $data['lines']['text'] ?? null;
					if ( $number > 0 && is_string( $text ) ) {
						$current['lines'][ $number ] = rtrim( $text, "\r\n" );
						if ( 'match' === $type ) {
							$current['matches'][] = $number;
						}
					}
					continue;
				}
				if ( 'end' === $type ) {
					foreach ( self::fileMatches( $file->relative, $current['lines'], $current['matches'], $context ) as $match ) {
						$matches[] = $match;
						if ( count( $matches ) >= $maxResults ) {
							$reason = 'max_results';
							break 3;
						}
					}
					$file    = null;
					$current = null;
				}
			}
		}

		fclose( $reader );
		if ( proc_get_status( $process )['running'] ) {
			proc_terminate( $process );
		}
		proc_close( $process );
		self::unlinkQuietly( $outFile );
		if ( ! $sawEvent && null === $reason && 2 === $exitCode ) {
			return null; // rg failed (bad binary, regex rejected by rg...): let the PHP implementation run.
		}

		$result = [
			'matches'       => $matches,
			// rg's JSON summary only counts files with output: report the files with matches.
			'files_scanned' => count( array_filter( $allowed ) ),
			'truncated'     => null !== $reason,
			'engine'        => 'rg',
		];
		if ( null !== $reason ) {
			$result['reason'] = $reason;
		}
		return $result;
	}

	/**
	 * Builds the match records of one file from the lines rg reported (matches + context).
	 *
	 * @param array<int, string> $lines   line number => text.
	 * @param int[]              $matched Matching line numbers, in order.
	 * @return list<array{p: string, l: int, text: string, before: list<string>, after: list<string>}>
	 */
	public static function fileMatches( string $relative, array $lines, array $matched, int $context ): array {
		$out = [];
		foreach ( $matched as $number ) {
			$before = [];
			for ( $i = max( 1, $number - $context ); $i < $number; $i++ ) {
				if ( isset( $lines[ $i ] ) ) {
					$before[] = GrepService::clip( $lines[ $i ] );
				}
			}
			$after = [];
			for ( $i = $number + 1; $i <= $number + $context; $i++ ) {
				if ( isset( $lines[ $i ] ) ) {
					$after[] = GrepService::clip( $lines[ $i ] );
				}
			}
			$out[] = [
				'p'      => $relative,
				'l'      => $number,
				'text'   => GrepService::clip( $lines[ $number ] ?? '' ),
				'before' => $before,
				'after'  => $after,
			];
		}
		return $out;
	}

	/**
	 * PathGuard re-validation of a path reported by rg (cached per request).
	 *
	 * @param array<string, ResolvedPath|false> $allowed
	 */
	private function allowedFile( string $absolute, ?string $glob, array &$allowed ): ?ResolvedPath {
		if ( ! array_key_exists( $absolute, $allowed ) ) {
			$allowed[ $absolute ] = false;
			$relative             = $this->guard->relativize( $absolute );
			if ( null !== $relative && ( null === $glob || Glob::match( $glob, $relative ) ) ) {
				try {
					$resolved = $this->guard->resolve( $relative, Access::Read );
					if ( ! $resolved->isDir ) {
						$allowed[ $absolute ] = $resolved;
					}
				} catch ( PathException ) {
					$allowed[ $absolute ] = false;
				}
			}
		}
		return false === $allowed[ $absolute ] ? null : $allowed[ $absolute ];
	}

	/**
	 * Starts rg (array form: no shell) with stdout redirected to $outFile and stderr discarded.
	 *
	 * @param string[] $command
	 * @return resource|null
	 */
	private static function start( array $command, string $outFile ) {
		$null  = 'Windows' === PHP_OS_FAMILY ? 'NUL' : '/dev/null';
		$pipes = [];
		// A missing or invalid binary only disables the fast path: the warning is not an error here.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		set_error_handler( static fn (): bool => true );
		try {
			// Array form (no shell), only for ripgrep as allowed by the specification (M3).
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open
			$process = proc_open(
				$command,
				[
					0 => [ 'file', $null, 'r' ],
					1 => [ 'file', $outFile, 'w' ],
					2 => [ 'file', $null, 'w' ],
				],
				$pipes
			);
		} catch ( \Throwable ) {
			$process = false;
		} finally {
			restore_error_handler();
		}
		if ( ! is_resource( $process ) ) {
			self::unlinkQuietly( $outFile );
			return null;
		}
		return $process;
	}

	/**
	 * Runs a short command and returns its exit code and output, or null on failure/timeout.
	 *
	 * @param string[] $command
	 * @return array{code: int, output: string}|null
	 */
	private static function run( array $command, string $tmpDir, float $timeout ): ?array {
		$outFile = $tmpDir . DIRECTORY_SEPARATOR . 'rg-' . bin2hex( random_bytes( 6 ) ) . '.out';
		$process = self::start( $command, $outFile );
		if ( null === $process ) {
			return null;
		}
		$end = microtime( true ) + $timeout;
		do {
			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				proc_close( $process );
				$output = is_file( $outFile ) ? (string) file_get_contents( $outFile ) : '';
				self::unlinkQuietly( $outFile );
				return [
					'code'   => (int) $status['exitcode'],
					'output' => $output,
				];
			}
			usleep( self::POLL_USEC );
		} while ( microtime( true ) < $end );
		proc_terminate( $process );
		proc_close( $process );
		self::unlinkQuietly( $outFile );
		return null;
	}

	private static function unlinkQuietly( string $file ): void {
		if ( is_file( $file ) ) {
			unlink( $file );
		}
	}
}
