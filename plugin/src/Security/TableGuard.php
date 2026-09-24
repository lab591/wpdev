<?php
/**
 * Database read access (0.6.0): the PathGuard of tables.
 *
 * Every table and column name coming from outside is checked against the real schema (and a
 * strict identifier pattern) before it reaches SQL; values only travel as prepare() placeholders.
 * Checks happen after resolution, like the path deny list:
 * - only existing tables with the site prefix, minus Dev Bridge's own tables and the excluded patterns;
 * - sensitive columns (passwords, keys, tokens…) are never selected, filtered or sorted, so they
 *   cannot be read nor guessed through a WHERE/ORDER BY oracle;
 * - in key-value tables (options, *meta) values of sensitive keys are redacted on output
 *   (DataRedactor) and, when the query filters or sorts on the value column, those rows are
 *   excluded in SQL for the same oracle reason; the value column only accepts exact matches,
 *   so secrets nested in other values cannot be probed with LIKE or ranges either.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Security;

use Lab591\DevBridge\Support\ApiException;

final class TableGuard {

	public const IDENTIFIER    = '/^[A-Za-z0-9_]{1,64}$/';
	public const DEFAULT_LIMIT = 20;
	public const MAX_LIMIT     = 100;
	public const MAX_OFFSET    = 10000;
	public const MAX_WHERE     = 10;
	public const MAX_IN        = 50;
	public const MAX_VALUE     = 1000;
	public const TIMEOUT_MS    = 5000;

	public const OPERATORS = [ '=', '!=', '<', '>', '<=', '>=', 'like', 'not like', 'in', 'not in', 'is null', 'is not null' ];

	/**
	 * Operators allowed on the value column of key-value tables: exact matches only. Partial or
	 * range matches (LIKE '%"api_key":"A%', < 'x') would extract, one character at a time, secrets
	 * nested in serialized/JSON values of keys that are not themselves sensitive.
	 */
	public const VALUE_OPERATORS = [ '=', '!=', 'in', 'not in', 'is null', 'is not null' ];

	/** Column names holding secrets (lowercase). */
	public const SENSITIVE_COLUMN = '/(^|_)(pass|password|passwd|pwd|secret|secrets|token|tokens|salt|nonce|credential|credentials|private|hash)(_|$)|(api|license|licence|secret|private|access|activation|auth|encryption|signing)_?key/';

	/**
	 * Keys of key-value rows holding secrets, as a MySQL REGEXP (applied to the lowercased key) and
	 * reused in PHP: plain alternation only, valid in MySQL 8 (ICU), MariaDB (PCRE) and PCRE.
	 */
	public const SENSITIVE_KEY_SQL = 'pass|pwd|secret|token|api_?key|licen[cs]e|private|credential|salt|nonce|session|auth_key|signature|encrypt|webhook|devbridge';

	/** Key-value layouts: [key column, value column]. */
	private const KEY_VALUE = [
		[ 'option_name', 'option_value' ],
		[ 'meta_key', 'meta_value' ],
	];

	/**
	 * @param string   $prefix   Base table prefix of the installation (`$wpdb->base_prefix`).
	 * @param string[] $excluded Table name patterns (`*` wildcard) excluded by the administrator.
	 */
	public function __construct(
		private readonly string $prefix,
		private readonly array $excluded,
	) {
	}

	public static function isSensitiveColumn( string $column ): bool {
		return 1 === preg_match( self::SENSITIVE_COLUMN, strtolower( $column ) );
	}

	public static function isSensitiveKey( string $key ): bool {
		return 1 === preg_match( '/' . self::SENSITIVE_KEY_SQL . '/', strtolower( $key ) );
	}

	/**
	 * Key and value columns when the table is a key-value table (options, *meta, sitemeta).
	 *
	 * @param string[] $columns
	 * @return array{key: string, value: string}|null
	 */
	public static function keyValueColumns( array $columns ): ?array {
		foreach ( self::KEY_VALUE as [ $key, $value ] ) {
			if ( in_array( $key, $columns, true ) && in_array( $value, $columns, true ) ) {
				return [
					'key'   => $key,
					'value' => $value,
				];
			}
		}
		return null;
	}

	/**
	 * Tables the agent may see, in the given order.
	 *
	 * @param string[] $existing All tables of the database.
	 * @return list<string>
	 */
	public function visibleTables( array $existing ): array {
		return array_values( array_filter( $existing, fn ( string $t ): bool => $this->allowed( $t ) ) );
	}

	/**
	 * @param string[] $existing All tables of the database.
	 * @throws ApiException When the table does not exist, belongs to another application or is excluded.
	 */
	public function checkTable( string $table, array $existing ): void {
		if ( ! in_array( $table, $existing, true ) || ! $this->allowed( $table ) ) {
			// Same answer for missing and denied tables: no probing of what exists.
			throw new ApiException( 'db_table_denied', 'Table not available (missing, not part of this site or excluded in the Dev Bridge settings)', 403 );
		}
	}

	private function allowed( string $table ): bool {
		if ( 1 !== preg_match( self::IDENTIFIER, $table ) || ! str_starts_with( $table, $this->prefix ) ) {
			return false;
		}
		if ( str_contains( strtolower( substr( $table, strlen( $this->prefix ) ) ), 'devbridge' ) ) {
			return false; // Dev Bridge's own tables (audit log: IPs, users), like the private storage for files.
		}
		foreach ( $this->excluded as $pattern ) {
			$regex = '/^' . str_replace( '\*', '.*', preg_quote( (string) $pattern, '/' ) ) . '$/i';
			if ( 1 === preg_match( $regex, $table ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Builds a SELECT for an already checked table.
	 *
	 * @param string[]             $tableColumns Real columns of the table, in order.
	 * @param array<string, mixed> $spec         columns, where [{column, op, value}], order_by, order, limit, offset.
	 * @throws ApiException On unknown identifiers, invalid operators/values or sensitive columns.
	 */
	public function buildSelect( string $table, array $tableColumns, array $spec ): SelectPlan {
		$tableColumns = array_values( array_map( 'strval', $tableColumns ) );
		$kv           = self::keyValueColumns( $tableColumns );
		$hidden       = array_values( array_filter( $tableColumns, [ self::class, 'isSensitiveColumn' ] ) );

		// Columns.
		$requested = $spec['columns'] ?? [];
		if ( ! is_array( $requested ) ) {
			throw new ApiException( 'invalid_param', 'columns must be a list', 400 );
		}
		if ( [] === $requested ) {
			$columns = array_values( array_diff( $tableColumns, $hidden ) );
		} else {
			$columns = [];
			foreach ( $requested as $column ) {
				$columns[] = $this->column( $tableColumns, $column );
			}
			$columns = array_values( array_unique( $columns ) );
			if ( null !== $kv && in_array( $kv['value'], $columns, true ) && ! in_array( $kv['key'], $columns, true ) ) {
				array_unshift( $columns, $kv['key'] );
			}
		}

		// Conditions.
		$where = $spec['where'] ?? [];
		if ( ! is_array( $where ) || count( $where ) > self::MAX_WHERE ) {
			throw new ApiException( 'invalid_param', 'where must be a list of at most ' . self::MAX_WHERE . ' conditions', 400 );
		}
		$conditions = [];
		$args       = [];
		$usesValue  = false;
		foreach ( $where as $condition ) {
			if ( ! is_array( $condition ) ) {
				throw new ApiException( 'invalid_param', 'Invalid condition', 400 );
			}
			$column = $this->column( $tableColumns, $condition['column'] ?? null );
			$op     = strtolower( trim( (string) ( $condition['op'] ?? '=' ) ) );
			if ( ! in_array( $op, self::OPERATORS, true ) ) {
				throw new ApiException( 'invalid_param', 'Unsupported operator: use one of ' . implode( ', ', self::OPERATORS ), 400 );
			}
			$isValue = null !== $kv && $kv['value'] === $column;
			if ( $isValue && ! in_array( $op, self::VALUE_OPERATORS, true ) ) {
				throw new ApiException(
					'db_filter_denied',
					'Only exact matches (' . implode( ', ', self::VALUE_OPERATORS ) . ") are allowed on {$column}: partial matches could reveal secrets nested in values. Filter by {$kv['key']} and read the values instead",
					403
				);
			}
			$usesValue = $usesValue || $isValue;
			$quoted    = '`' . $column . '`';
			if ( 'is null' === $op || 'is not null' === $op ) {
				$conditions[] = $quoted . ' ' . strtoupper( $op );
				continue;
			}
			if ( ! array_key_exists( 'value', $condition ) ) {
				throw new ApiException( 'invalid_param', "Missing value for {$column}", 400 );
			}
			$value = $condition['value'];
			if ( 'in' === $op || 'not in' === $op ) {
				if ( ! is_array( $value ) || [] === $value || count( $value ) > self::MAX_IN ) {
					throw new ApiException( 'invalid_param', 'IN needs a list of 1-' . self::MAX_IN . ' values', 400 );
				}
				$conditions[] = $quoted . ' ' . strtoupper( $op ) . ' (' . implode( ', ', array_fill( 0, count( $value ), '%s' ) ) . ')';
				foreach ( $value as $item ) {
					$args[] = self::scalar( $item );
				}
				continue;
			}
			$conditions[] = $quoted . ' ' . strtoupper( $op ) . ' %s';
			$args[]       = self::scalar( $value );
		}

		// Order.
		$order = '';
		if ( isset( $spec['order_by'] ) && '' !== $spec['order_by'] ) {
			$by        = $this->column( $tableColumns, $spec['order_by'] );
			$usesValue = $usesValue || ( null !== $kv && $kv['value'] === $by );
			$direction = 'desc' === strtolower( (string) ( $spec['order'] ?? 'asc' ) ) ? 'DESC' : 'ASC';
			$order     = " ORDER BY `{$by}` {$direction}";
		}

		if ( $usesValue && null !== $kv ) {
			// Filtering or sorting on values: rows of sensitive keys must not take part at all.
			$conditions[] = 'LOWER(`' . $kv['key'] . '`) NOT REGEXP %s';
			$args[]       = self::SENSITIVE_KEY_SQL;
		}

		$limit  = self::bounded( $spec['limit'] ?? self::DEFAULT_LIMIT, 1, self::MAX_LIMIT, 'limit' );
		$offset = self::bounded( $spec['offset'] ?? 0, 0, self::MAX_OFFSET, 'offset' );

		$sql = 'SELECT /*+ MAX_EXECUTION_TIME(' . self::TIMEOUT_MS . ') */ '
			. implode( ', ', array_map( static fn ( string $c ): string => '`' . $c . '`', $columns ) )
			. ' FROM `' . $table . '`'
			. ( [] === $conditions ? '' : ' WHERE ' . implode( ' AND ', $conditions ) )
			. $order
			. ' LIMIT ' . ( $limit + 1 ) . ' OFFSET ' . $offset; // One extra row tells whether there are more.

		return new SelectPlan( $sql, $args, $columns, $hidden, $kv, $limit );
	}

	/**
	 * @param string[] $tableColumns
	 * @throws ApiException On invalid input or denied access.
	 */
	private function column( array $tableColumns, mixed $column ): string {
		if ( ! is_string( $column ) || 1 !== preg_match( self::IDENTIFIER, $column ) || ! in_array( $column, $tableColumns, true ) ) {
			throw new ApiException( 'invalid_param', 'Unknown column' . ( is_string( $column ) && 1 === preg_match( self::IDENTIFIER, $column ) ? ": {$column}" : '' ), 400 );
		}
		if ( self::isSensitiveColumn( $column ) ) {
			throw new ApiException( 'db_column_denied', "Column {$column} holds secrets and cannot be read, filtered or sorted", 403 );
		}
		return $column;
	}

	/**
	 * @throws ApiException On invalid input or denied access.
	 */
	private static function scalar( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			throw new ApiException( 'invalid_param', 'Condition values must be strings or numbers', 400 );
		}
		$value = (string) $value;
		if ( strlen( $value ) > self::MAX_VALUE ) {
			throw new ApiException( 'invalid_param', 'Condition value too long', 400 );
		}
		return $value;
	}

	/**
	 * @throws ApiException On invalid input or denied access.
	 */
	private static function bounded( mixed $value, int $min, int $max, string $name ): int {
		if ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) {
			throw new ApiException( 'invalid_param', "{$name} must be an integer", 400 );
		}
		$value = (int) $value;
		if ( $value < $min || $value > $max ) {
			throw new ApiException( 'invalid_param', "{$name} must be between {$min} and {$max}", 400 );
		}
		return $value;
	}
}
