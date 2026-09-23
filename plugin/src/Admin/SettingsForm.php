<?php
/**
 * Validation of the settings form (SPEC 2.4). Pure logic, reusable from WP-CLI.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Admin;

use Lab591\DevBridge\Mode;
use Lab591\DevBridge\Security\Access;
use Lab591\DevBridge\Security\IpMatcher;
use Lab591\DevBridge\Security\PathException;
use Lab591\DevBridge\Security\PathGuard;
use Lab591\DevBridge\Security\PathPolicy;
use Lab591\DevBridge\Security\WritableRootValidator;
use Lab591\DevBridge\Services\RipgrepSearcher;
use Lab591\DevBridge\Settings;

final class SettingsForm {

	/** @var string[] */
	private array $errors = [];

	/**
	 * @param callable(bool): WritableRootValidator $validatorFactory Receives the allow_mu_plugins flag.
	 * @param string                                $abspath          ABSPATH, for read roots validation.
	 * @param string|string[]                       $allowedHosts     Hosts accepted in health URLs (the site, or every site of a network).
	 */
	public function __construct(
		private readonly mixed $validatorFactory,
		private readonly string $abspath,
		private readonly string|array $allowedHosts,
	) {
	}

	/**
	 * @return string[] Messages about rejected values.
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Returns sanitized settings; invalid entries are dropped and reported in errors().
	 *
	 * @param array<string, mixed> $input   Raw form input (already unslashed).
	 * @param array<string, mixed> $current Current settings (fallback for invalid scalar values).
	 * @return array<string, mixed>
	 */
	public function sanitize( array $input, array $current ): array {
		$this->errors = [];
		$out          = array_merge( Settings::defaults(), $current );

		$out['allowed_user_ids'] = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $input['allowed_user_ids'] ?? [] ) ), static fn ( int $id ): bool => $id > 0 ) ) );
		$out['allow_mu_plugins'] = ! empty( $input['allow_mu_plugins'] );
		$out['health_backend']   = ! empty( $input['health_backend'] );

		$validator             = ( $this->validatorFactory )( $out['allow_mu_plugins'] );
		$out['writable_roots'] = [];
		foreach ( self::lines( $input['writable_roots'] ?? '' ) as $root ) {
			try {
				$out['writable_roots'][] = $validator->validate( $root );
			} catch ( PathException $e ) {
				/* translators: 1: folder, 2: reason. */
				$this->errors[] = sprintf( __( 'Writable folder "%1$s" rejected: %2$s', 'lab591-dev-bridge' ), $root, $e->getMessage() );
			}
		}
		$out['writable_roots'] = self::withoutNested( array_values( array_unique( $out['writable_roots'] ) ) );

		$out['read_roots'] = [];
		$guard             = new PathGuard( new PathPolicy( $this->abspath, [ '' ], [] ) );
		foreach ( self::lines( $input['read_roots'] ?? '' ) as $root ) {
			if ( '.' === $root || '/' === $root ) {
				$out['read_roots'][] = '';
				continue;
			}
			try {
				$resolved = $guard->resolve( trim( $root, '/' ), Access::Read );
				if ( ! $resolved->isDir ) {
					throw PathException::notADirectory();
				}
				$out['read_roots'][] = $resolved->relative;
			} catch ( PathException $e ) {
				/* translators: 1: folder, 2: reason. */
				$this->errors[] = sprintf( __( 'Readable folder "%1$s" rejected: %2$s', 'lab591-dev-bridge' ), $root, $e->getMessage() );
			}
		}
		$out['read_roots'] = array_values( array_unique( $out['read_roots'] ) );

		$out['deny_patterns'] = array_values( array_unique( self::lines( $input['deny_patterns'] ?? '' ) ) );

		$exts = [];
		foreach ( self::lines( $input['write_extensions'] ?? '', true ) as $ext ) {
			$ext = strtolower( ltrim( $ext, '.' ) );
			if ( 1 !== preg_match( '/^[a-z0-9]{1,10}$/', $ext ) ) {
				/* translators: %s: file extension. */
				$this->errors[] = sprintf( __( 'Extension "%s" is not valid', 'lab591-dev-bridge' ), $ext );
				continue;
			}
			if ( in_array( $ext, PathPolicy::EXECUTABLE_EXTENSIONS, true ) ) {
				/* translators: %s: file extension. */
				$this->errors[] = sprintf( __( 'Extension "%s" is never writable', 'lab591-dev-bridge' ), $ext );
				continue;
			}
			$exts[] = $ext;
		}
		$out['write_extensions'] = array_values( array_unique( $exts ) );

		foreach ( [ 'ip_allowlist', 'trusted_proxies' ] as $key ) {
			$out[ $key ] = [];
			foreach ( self::lines( $input[ $key ] ?? '', true ) as $entry ) {
				if ( IpMatcher::isValidEntry( $entry ) ) {
					$out[ $key ][] = $entry;
				} else {
					/* translators: %s: IP address or CIDR range. */
					$this->errors[] = sprintf( __( 'IP address/CIDR "%s" is not valid', 'lab591-dev-bridge' ), $entry );
				}
			}
		}

		$out['grep_skip_dirs'] = [];
		foreach ( self::lines( $input['grep_skip_dirs'] ?? '', true ) as $dir ) {
			if ( 1 === preg_match( '#^[^/\\\\:*?"<>|]{1,100}$#', $dir ) ) {
				$out['grep_skip_dirs'][] = strtolower( $dir );
			}
		}

		$rg = trim( (string) ( $input['grep_rg'] ?? '' ) );
		if ( '' === $rg || RipgrepSearcher::isValidBinary( $rg ) ) {
			$out['grep_rg'] = $rg;
		} else {
			$out['grep_rg'] = '';
			/* translators: %s: configured ripgrep binary. */
			$this->errors[] = sprintf( __( 'ripgrep "%s" rejected: use "rg" or the absolute path of rg/rg.exe', 'lab591-dev-bridge' ), $rg );
		}

		$out['health_urls'] = [];
		foreach ( self::lines( $input['health_urls'] ?? '' ) as $url ) {
			$parts = self::parseUrl( $url );
			if ( null === $parts || ! in_array( $parts['scheme'], [ 'http', 'https' ], true ) || ! in_array( strtolower( $parts['host'] ), array_map( 'strtolower', (array) $this->allowedHosts ), true ) ) {
				/* translators: %s: URL. */
				$this->errors[] = sprintf( __( 'Health check URL "%s" rejected: it must be on the same host as the site', 'lab591-dev-bridge' ), $url );
				continue;
			}
			$out['health_urls'][] = $url;
		}

		$out['retention_releases']   = self::intIn( $input['retention_releases'] ?? null, 1, 100, (int) $out['retention_releases'] );
		$out['audit_retention_days'] = self::intIn( $input['audit_retention_days'] ?? null, 1, 3650, (int) $out['audit_retention_days'] );
		$out['max_read_hours']       = self::intIn( $input['max_read_hours'] ?? null, 1, Mode::HARD_MAX_HOURS[ Mode::READ ], (int) $out['max_read_hours'] );
		$out['max_write_hours']      = self::intIn( $input['max_write_hours'] ?? null, 1, Mode::HARD_MAX_HOURS[ Mode::WRITE ], (int) $out['max_write_hours'] );

		$limits = [];
		foreach ( Settings::DEFAULT_LIMITS as $key => $default ) {
			$limits[ $key ] = self::intIn( $input['limits'][ $key ] ?? null, 1, $default * 10, (int) ( $out['limits'][ $key ] ?? $default ) );
		}
		$out['limits'] = $limits;

		return $out;
	}

	/**
	 * Drops folders already covered by a selected parent (e.g. a theme and one of its subfolders).
	 *
	 * @param string[] $roots
	 * @return string[]
	 */
	public static function withoutNested( array $roots ): array {
		return array_values(
			array_filter(
				$roots,
				static function ( string $root ) use ( $roots ): bool {
					foreach ( $roots as $other ) {
						if ( $other !== $root && 0 === strncasecmp( $root, $other . '/', strlen( $other ) + 1 ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);
	}

	/**
	 * Splits a textarea (or array) into trimmed, non-empty lines.
	 *
	 * @return string[]
	 */
	public static function lines( mixed $value, bool $commas = false ): array {
		if ( is_array( $value ) ) {
			$items = array_map( 'strval', $value );
		} else {
			$split = preg_split( $commas ? '/[\r\n,]+/' : '/[\r\n]+/', (string) $value );
			$items = false === $split ? [] : $split;
		}
		return array_values( array_filter( array_map( 'trim', $items ), static fn ( string $s ): bool => '' !== $s ) );
	}

	/**
	 * Parses an absolute http(s) URL; returns scheme and host or null.
	 *
	 * @return array{scheme: string, host: string}|null
	 */
	private static function parseUrl( string $url ): ?array {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- also used without WordPress.
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		return [
			'scheme' => strtolower( (string) $parts['scheme'] ),
			'host'   => (string) $parts['host'],
		];
	}

	private static function intIn( mixed $value, int $min, int $max, int $fallback ): int {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return max( $min, min( $max, $fallback ) );
		}
		return max( $min, min( $max, (int) $value ) );
	}
}
