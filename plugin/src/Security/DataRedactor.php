<?php
/**
 * Redaction of database values before they leave the site (0.6.0).
 *
 * Secrets are always redacted: values of sensitive keys in key-value tables, sensitive keys nested
 * in serialized or JSON values (matched as text: nothing is unserialized), and values that look like
 * credentials anywhere (password hashes, private keys, API tokens, URL credentials). Personal data
 * (emails, IPs, phones, names, addresses) is masked unless the administrator turns that off.
 * Redaction is best effort on free text: the protections that cannot fail are in TableGuard.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Security;

final class DataRedactor {

	public const MAX_CELL = 2000;

	public const SECRET         = '[redacted]';
	public const PERSONAL       = '[personal]';
	public const EMAIL          = '[email]';
	private const PERSONAL_NAME = '/(^|_)(email|e_mail|ip|phone|tel|mobile|address(_[12])?|postcode|zip|birth(day|date)?|dob|first_?name|last_?name|surname|display_name|nickname)(_|$)|^comment_author$/';

	/** Credential-looking values, redacted wherever they appear. */
	private const SECRET_VALUES = [
		'/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s',
		'/\$wp\$2[aby]\$\d\d\$[.\/A-Za-z0-9]{53}/',
		'/\$2[aby]\$\d\d\$[.\/A-Za-z0-9]{53}/',
		'/\$[PH]\$[.\/A-Za-z0-9]{31}/',
		'/\$argon2(?:id|i|d)\$[^\s"\']+/',
		'/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/',
		'/\b(?:sk|rk|pk)_(?:live|test)_[A-Za-z0-9]{10,}/',
		'/\bAKIA[0-9A-Z]{16}\b/',
		'/\bgh[pousr]_[A-Za-z0-9]{36,}/',
		'/\bxox[abprs]-[A-Za-z0-9-]{10,}/',
		'/\bAIza[0-9A-Za-z_-]{35}/',
		'/\bBearer\s+[A-Za-z0-9._~+\/-]{20,}=*/',
	];

	private int $count = 0;

	public function __construct( private readonly bool $personal ) {
	}

	/** Number of values (or parts of values) redacted so far. */
	public function count(): int {
		return $this->count;
	}

	/**
	 * @param list<string>                           $columns Column names, in row order.
	 * @param list<mixed>                            $row     Values as returned by the database.
	 * @param array{key: string, value: string}|null $kv      Key and value columns of a key-value table.
	 * @return list<mixed>
	 */
	public function row( array $columns, array $row, ?array $kv ): array {
		$key = null;
		if ( null !== $kv ) {
			$index = array_search( $kv['key'], $columns, true );
			$key   = false === $index ? null : (string) ( $row[ $index ] ?? '' );
		}
		$out = [];
		foreach ( array_values( $row ) as $i => $value ) {
			$column = (string) ( $columns[ $i ] ?? '' );
			if ( null !== $kv && $kv['value'] === $column && null !== $key && null !== $value ) {
				if ( TableGuard::isSensitiveKey( $key ) ) {
					$out[] = $this->hit( self::SECRET );
					continue;
				}
				if ( $this->personal && self::isPersonalName( $key ) ) {
					$out[] = $this->hit( self::PERSONAL );
					continue;
				}
			}
			$out[] = $this->value( $column, $value );
		}
		return $out;
	}

	private function value( string $column, mixed $value ): mixed {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		if ( TableGuard::isSensitiveColumn( $column ) ) {
			return $this->hit( self::SECRET );
		}
		if ( $this->personal && '' !== $value && self::isPersonalName( $column ) ) {
			return $this->hit( self::PERSONAL );
		}
		$value = $this->serialized( $value );
		$value = $this->json( $value );
		foreach ( self::SECRET_VALUES as $pattern ) {
			$value = $this->replace( $pattern, self::SECRET, $value );
		}
		$value = $this->replace( '#(\b[a-z][a-z0-9+.-]*://)[^/\s:@]+:[^/\s@]+@#i', '$1' . self::SECRET . '@', $value );
		if ( $this->personal ) {
			$value = $this->replace( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', self::EMAIL, $value );
		}
		$length = mb_strlen( $value );
		return $length > self::MAX_CELL ? mb_substr( $value, 0, self::MAX_CELL ) . ' [+' . ( $length - self::MAX_CELL ) . ' chars]' : $value;
	}

	private static function isPersonalName( string $name ): bool {
		return 1 === preg_match( self::PERSONAL_NAME, strtolower( $name ) );
	}

	/**
	 * Sensitive keys inside PHP-serialized text (`s:8:"password";s:9:"…";`): the value length is
	 * read from the serialization, so values containing quotes or semicolons are fully covered.
	 */
	private function serialized( string $value ): string {
		if ( ! str_contains( $value, '";s:' ) ) {
			return $value;
		}
		$offset = 0;
		while ( 1 === preg_match( '/s:\d+:"([^"]{1,200})";s:(\d+):"/', $value, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
			$start  = $m[0][1] + strlen( $m[0][0] );
			$length = (int) $m[2][0];
			$offset = $start;
			if ( TableGuard::isSensitiveKey( $m[1][0] ) && $start + $length <= strlen( $value ) ) {
				$value  = substr( $value, 0, $start ) . self::SECRET . substr( $value, $start + $length );
				$offset = $start + strlen( self::SECRET );
				++$this->count;
			}
		}
		return $value;
	}

	/** Sensitive keys inside JSON text (`"api_key":"…"`), at any depth. */
	private function json( string $value ): string {
		if ( ! str_contains( $value, '":' ) ) {
			return $value;
		}
		return (string) preg_replace_callback(
			'/"((?:[^"\\\\]|\\\\.){1,200})"(\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/',
			function ( array $m ): string {
				if ( ! TableGuard::isSensitiveKey( $m[1] ) ) {
					return $m[0];
				}
				++$this->count;
				return '"' . $m[1] . '"' . $m[2] . '"' . self::SECRET . '"';
			},
			$value
		);
	}

	private function replace( string $pattern, string $replacement, string $value ): string {
		$count        = 0;
		$result       = preg_replace( $pattern, $replacement, $value, -1, $count );
		$this->count += $count;
		return null === $result ? $value : $result;
	}

	private function hit( string $marker ): string {
		++$this->count;
		return $marker;
	}
}
