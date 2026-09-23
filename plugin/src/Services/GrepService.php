<?php
/**
 * Server-side search in pure PHP (`POST /grep`, SPEC 2.6.2).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Services;

use Lab591\DevBridge\Security\Access;
use Lab591\DevBridge\Security\Glob;
use Lab591\DevBridge\Security\PathGuard;
use Lab591\DevBridge\Support\ApiException;

final class GrepService {

	public const MAX_CONTEXT     = 3;
	public const LINE_MAX_CHARS  = 300;
	public const BACKTRACK_LIMIT = 100000;

	/** Extensions never searched (skipped without opening the file). */
	private const BINARY_EXTENSIONS = [
		'png'   => true,
		'jpg'   => true,
		'jpeg'  => true,
		'gif'   => true,
		'webp'  => true,
		'avif'  => true,
		'ico'   => true,
		'woff'  => true,
		'woff2' => true,
		'ttf'   => true,
		'otf'   => true,
		'eot'   => true,
		'mo'    => true,
		'zip'   => true,
		'gz'    => true,
		'pdf'   => true,
		'mp3'   => true,
		'mp4'   => true,
		'webm'  => true,
	];

	/**
	 * @param string[] $skipDirNames Folder names never searched.
	 */
	public function __construct(
		private readonly PathGuard $guard,
		private readonly int $maxResultsLimit,
		private readonly int $timeBudgetLimitMs,
		private readonly int $maxFileBytes,
		private readonly array $skipDirNames,
	) {
	}

	/**
	 * Compiles the user pattern into a PCRE regex; throws `invalid_regex` (422) when invalid.
	 *
	 * @return array{0: string, 1: string} Regex with the `u` modifier and a byte-oriented fallback.
	 */
	public static function compile( string $pattern, bool $regex, bool $caseSensitive ): array {
		if ( '' === $pattern ) {
			throw new ApiException( 'invalid_param', 'pattern must not be empty', 400 );
		}
		if ( 1 === preg_match( '/[\x00]/', $pattern ) ) {
			throw new ApiException( 'invalid_param', 'pattern contains NUL bytes', 400 );
		}
		$body = $regex ? self::escapeDelimiter( $pattern ) : preg_quote( $pattern, '/' );
		$mods = $caseSensitive ? '' : 'i';
		$utf8 = '/' . $body . '/' . $mods . 'u';
		$raw  = '/' . $body . '/' . $mods;

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- captures PCRE compile warnings.
		set_error_handler( static fn (): bool => true );
		try {
			$okUtf8 = preg_match( $utf8, '' );
			$okRaw  = preg_match( $raw, '' );
		} finally {
			restore_error_handler();
		}
		if ( false === $okRaw || ( false === $okUtf8 && 1 === preg_match( '//u', $pattern ) ) ) {
			throw new ApiException( 'invalid_regex', 'Invalid regular expression', 422 );
		}
		return [ false === $okUtf8 ? $raw : $utf8, $raw ];
	}

	/**
	 * Escapes unescaped `/` so that any user pattern can be wrapped in `/.../`.
	 */
	private static function escapeDelimiter( string $pattern ): string {
		$out = '';
		$len = strlen( $pattern );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $pattern[ $i ];
			if ( '\\' === $c && $i + 1 < $len ) {
				$out .= $c . $pattern[ ++$i ];
				continue;
			}
			$out .= '/' === $c ? '\\/' : $c;
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $args Validated request arguments.
	 * @return array<string, mixed>
	 */
	public function grep( array $args ): array {
		$maxResults = max( 1, min( $this->maxResultsLimit, (int) ( $args['max_results'] ?? 100 ) ) );
		$context    = max( 0, min( self::MAX_CONTEXT, (int) ( $args['context'] ?? 1 ) ) );
		$budgetMs   = max( 100, min( $this->timeBudgetLimitMs, (int) ( $args['time_budget_ms'] ?? $this->timeBudgetLimitMs ) ) );
		$glob       = isset( $args['glob'] ) && '' !== $args['glob'] ? (string) $args['glob'] : null;

		$isRegex       = (bool) ( $args['regex'] ?? false );
		$caseSensitive = (bool) ( $args['case_sensitive'] ?? false );
		$literal       = $isRegex ? null : (string) $args['pattern'];

		[ $regexUtf8, $regexRaw ] = self::compile( (string) $args['pattern'], $isRegex, $caseSensitive );

		$root     = $this->guard->resolve( (string) $args['path'], Access::Read );
		$walker   = new TreeWalker( $this->guard, array_map( 'strtolower', $this->skipDirNames ) );
		$deadline = microtime( true ) + $budgetMs / 1000;
		$matches  = [];
		$scanned  = 0;
		$reason   = null;

		$previousLimit = ini_get( 'pcre.backtrack_limit' );
		// Bounds catastrophic backtracking (ReDoS); restored in finally.
		ini_set( 'pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		try {
			foreach ( $walker->files( $root ) as $file ) {
				if ( microtime( true ) > $deadline ) {
					$reason = 'time_budget';
					break;
				}
				if ( null !== $glob && ! Glob::match( $glob, $file->relative ) ) {
					continue;
				}
				if ( isset( self::BINARY_EXTENSIONS[ strtolower( pathinfo( $file->relative, PATHINFO_EXTENSION ) ) ] ) ) {
					continue;
				}
				$size = filesize( $file->absolute );
				if ( false === $size || $size > $this->maxFileBytes || ReadService::isBinary( $file->absolute ) ) {
					continue;
				}
				$content = file_get_contents( $file->absolute );
				if ( false === $content ) {
					continue;
				}
				++$scanned;
				$regex = 1 === preg_match( '//u', $content ) ? $regexUtf8 : $regexRaw;
				// Literal search: cheap whole-file check before splitting into lines.
				if ( null !== $literal && false === ( $caseSensitive ? strpos( $content, $literal ) : stripos( $content, $literal ) ) && 1 !== preg_match( $regex, $content ) ) {
					continue;
				}
				$lines = preg_split( '/\r\n|\n|\r/', $content );
				if ( false === $lines ) {
					continue;
				}
				foreach ( $lines as $index => $line ) {
					if ( 1 !== preg_match( $regex, $line ) ) {
						continue;
					}
					$matches[] = [
						'p'      => $file->relative,
						'l'      => $index + 1,
						'text'   => self::clip( $line ),
						'before' => array_map( [ self::class, 'clip' ], array_slice( $lines, max( 0, $index - $context ), min( $context, $index ) ) ),
						'after'  => array_map( [ self::class, 'clip' ], array_slice( $lines, $index + 1, $context ) ),
					];
					if ( count( $matches ) >= $maxResults ) {
						$reason = 'max_results';
						break 2;
					}
					if ( 0 === count( $matches ) % 50 && microtime( true ) > $deadline ) {
						$reason = 'time_budget';
						break 2;
					}
				}
			}
		} finally {
			ini_set( 'pcre.backtrack_limit', false === $previousLimit ? '1000000' : $previousLimit ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}

		$result = [
			'matches'       => $matches,
			'files_scanned' => $scanned,
			'truncated'     => null !== $reason,
		];
		if ( null !== $reason ) {
			$result['reason'] = $reason;
		}
		return $result;
	}

	public static function clip( string $line ): string {
		if ( strlen( $line ) <= self::LINE_MAX_CHARS ) {
			return $line;
		}
		if ( function_exists( 'mb_substr' ) && 1 === preg_match( '//u', $line ) ) {
			return mb_substr( $line, 0, self::LINE_MAX_CHARS, 'UTF-8' );
		}
		return substr( $line, 0, self::LINE_MAX_CHARS );
	}
}
