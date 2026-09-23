<?php
/**
 * File reading by line range (`POST /read`, SPEC 2.6.1).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Services;

use Lab591\DevBridge\Security\Access;
use Lab591\DevBridge\Security\PathException;
use Lab591\DevBridge\Security\PathGuard;
use Lab591\DevBridge\Support\ApiException;

final class ReadService {

	public const BINARY_PROBE = 8192;

	public function __construct(
		private readonly PathGuard $guard,
		private readonly int $maxBytes,
		private readonly ?HashCache $hashes = null,
	) {
	}

	private function hashOf( string $absolute, string $relative, int $size, int $mtime ): string {
		return null === $this->hashes ? (string) hash_file( 'xxh128', $absolute ) : $this->hashes->hash( $absolute, $relative, $size, $mtime );
	}

	public static function isBinary( string $absolute ): bool {
		$fh = fopen( $absolute, 'rb' );
		if ( false === $fh ) {
			return false;
		}
		$head = (string) fread( $fh, self::BINARY_PROBE );
		fclose( $fh );
		return str_contains( $head, "\0" );
	}

	/**
	 * @param array{s: int, m: int, h: string}|null $known Client cache metadata (conditional read, M3).
	 * @return array<string, mixed>
	 */
	public function read( string $path, ?int $from = null, ?int $to = null, ?array $known = null ): array {
		$file = $this->guard->resolve( $path, Access::Read );
		if ( $file->isDir ) {
			throw PathException::notAFile();
		}
		if ( null !== $known ) {
			clearstatcache( true, $file->absolute );
			$size  = (int) filesize( $file->absolute );
			$mtime = (int) filemtime( $file->absolute );
			if ( $size === $known['s'] && $mtime === $known['m'] ) {
				return [ 'status' => 'unchanged' ];
			}
			if ( $this->hashOf( $file->absolute, $file->relative, $size, $mtime ) === $known['h'] ) {
				return [
					'status' => 'unchanged',
					's'      => $size,
					'm'      => $mtime,
				];
			}
		}
		if ( self::isBinary( $file->absolute ) ) {
			throw new ApiException( 'binary_file', 'Binary files cannot be read as text', 422 );
		}
		if ( null !== $from && $from < 1 ) {
			throw new ApiException( 'invalid_param', 'from must be >= 1', 400 );
		}
		if ( null !== $to && null !== $from && $to < $from ) {
			throw new ApiException( 'invalid_param', 'to must be >= from', 400 );
		}

		clearstatcache( true, $file->absolute );
		$size  = (int) filesize( $file->absolute );
		$mtime = (int) filemtime( $file->absolute );
		$hash  = $this->hashOf( $file->absolute, $file->relative, $size, $mtime );
		$from  = $from ?? 1;

		$fh = fopen( $file->absolute, 'rb' );
		if ( false === $fh ) {
			throw new ApiException( 'internal_error', 'File could not be opened', 500 );
		}
		$content   = '';
		$bytes     = 0;
		$total     = 0;
		$last      = $from - 1;
		$truncated = false;
		$full      = false;
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( false !== ( $line = fgets( $fh ) ) ) {
			++$total;
			if ( $total < $from || $full || ( null !== $to && $total > $to ) ) {
				continue;
			}
			if ( $bytes + strlen( $line ) > $this->maxBytes ) {
				if ( 0 === $bytes ) {
					// A single line larger than the limit (e.g. minified code): return its head.
					$content = function_exists( 'mb_strcut' ) ? mb_strcut( $line, 0, $this->maxBytes, 'UTF-8' ) : substr( $line, 0, $this->maxBytes );
					$bytes   = strlen( $content );
					$last    = $total;
				}
				$full      = true;
				$truncated = true;
				continue;
			}
			$content .= $line;
			$bytes   += strlen( $line );
			$last     = $total;
		}
		fclose( $fh );

		if ( $total > 0 && $from > $total ) {
			throw new ApiException( 'invalid_param', sprintf( 'from exceeds total_lines (%d)', $total ), 400 );
		}
		if ( null === $to && $last < $total ) {
			$truncated = true;
		}

		return [
			'status'      => 'ok',
			's'           => $size,
			'm'           => $mtime,
			'h'           => $hash,
			'total_lines' => $total,
			'from'        => $from,
			'to'          => max( $last, $from - 1 ),
			'content'     => $content,
			'truncated'   => $truncated,
		];
	}
}
