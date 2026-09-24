<?php
/**
 * Read-only database access for understanding and debugging a site (0.6.0).
 *
 * Two levels, chosen by the administrator (setting `db_access`, off by default):
 * - "schema": structure only — tables, columns, indexes, meta keys and option names with counts
 *   and sizes, never a stored value;
 * - "read": also rows, through structured queries built by TableGuard (no free SQL) and
 *   redacted by DataRedactor.
 * Nothing here writes: only SHOW and SELECT statements are ever executed.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Services;

use Lab591\DevBridge\Security\DataRedactor;
use Lab591\DevBridge\Security\TableGuard;
use Lab591\DevBridge\Support\ApiException;

final class DatabaseService {

	public const OFF    = 'off';
	public const SCHEMA = 'schema';
	public const READ   = 'read';
	public const LEVELS = [ self::OFF, self::SCHEMA, self::READ ];

	public const TOPICS    = [ 'tables', 'table', 'meta_keys', 'options' ];
	public const META      = [ 'postmeta', 'usermeta', 'termmeta', 'commentmeta' ];
	public const MAX_ITEMS = 300;

	/** @var list<string>|null */
	private ?array $tables = null;

	public function __construct(
		private readonly \wpdb $db,
		private readonly TableGuard $guard,
		private readonly bool $redactPersonal,
	) {
	}

	/**
	 * Structure of the database (level "schema").
	 *
	 * @param string $topic    One of TOPICS.
	 * @param string $name     Table (topic "table") or meta table (topic "meta_keys": postmeta, usermeta…).
	 * @param string $postType Only for meta_keys of postmeta: keys used by this post type.
	 * @return array<string, mixed>
	 * @throws ApiException On invalid input or denied access.
	 */
	public function schema( string $topic, string $name = '', string $postType = '' ): array {
		return match ( $topic ) {
			'tables'    => $this->tablesOverview(),
			'table'     => $this->table( $name ),
			'meta_keys' => $this->metaKeys( $name, $postType ),
			'options'   => $this->options(),
			default     => throw new ApiException( 'invalid_param', 'Unknown topic', 400 ),
		};
	}

	/**
	 * Rows of a table (level "read").
	 *
	 * @param array<string, mixed> $spec table, columns, where, order_by, order, limit, offset.
	 * @return array<string, mixed>
	 * @throws ApiException On invalid input or denied access.
	 */
	public function query( array $spec ): array {
		$table = (string) ( $spec['table'] ?? '' );
		$this->guard->checkTable( $table, $this->allTables() );
		$plan = $this->guard->buildSelect( $table, array_column( $this->columns( $table ), 'name' ), $spec );

		$sql  = [] === $plan->args ? $plan->sql : (string) $this->db->prepare( $plan->sql, $plan->args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers checked by TableGuard, values are placeholders.
		$rows = $this->db->get_results( $sql, ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- see above; live data on purpose.
		$this->failOnError();

		$rows     = is_array( $rows ) ? $rows : [];
		$more     = count( $rows ) > $plan->limit;
		$redactor = new DataRedactor( $this->redactPersonal );
		$out      = [];
		foreach ( array_slice( $rows, 0, $plan->limit ) as $row ) {
			$out[] = $redactor->row( $plan->columns, array_values( (array) $row ), $plan->keyValue );
		}
		return [
			'table'    => $table,
			'columns'  => $plan->columns,
			'rows'     => $out,
			'offset'   => (int) ( $spec['offset'] ?? 0 ),
			'more'     => $more,
			'hidden'   => $plan->hidden,
			'redacted' => $redactor->count(),
			'personal' => $this->redactPersonal ? 'masked' : 'shown',
		];
	}

	// ------------------------------------------------------------------ schema topics

	/**
	 * @return array<string, mixed>
	 */
	private function tablesOverview(): array {
		$visible = array_flip( $this->guard->visibleTables( $this->allTables() ) );
		$status  = $this->db->get_results( $this->db->prepare( 'SHOW TABLE STATUS LIKE %s', $this->db->esc_like( $this->db->base_prefix ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->failOnError();
		$items = [];
		foreach ( is_array( $status ) ? $status : [] as $row ) {
			$name = (string) ( $row['Name'] ?? '' );
			if ( ! isset( $visible[ $name ] ) ) {
				continue;
			}
			$items[] = [
				'name'    => $name,
				'rows'    => (int) ( $row['Rows'] ?? 0 ),
				'size_kb' => (int) round( ( (int) ( $row['Data_length'] ?? 0 ) + (int) ( $row['Index_length'] ?? 0 ) ) / 1024 ),
				'engine'  => (string) ( $row['Engine'] ?? '' ),
			];
		}
		return [
			'topic'     => 'tables',
			'prefix'    => $this->db->prefix,
			'items'     => array_slice( $items, 0, self::MAX_ITEMS ),
			'truncated' => count( $items ) > self::MAX_ITEMS,
			'excluded'  => count( $this->allTables() ) - count( $visible ),
			'note'      => 'rows is an estimate for InnoDB tables.',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function table( string $name ): array {
		$this->guard->checkTable( $name, $this->allTables() );
		$columns = $this->columns( $name );
		foreach ( $columns as &$column ) {
			if ( TableGuard::isSensitiveColumn( $column['name'] ) ) {
				$column['hidden'] = true; // Structure is shown; values can never be read.
				unset( $column['default'] );
			}
		}
		unset( $column );
		$indexes = [];
		$raw     = $this->db->get_results( 'SHOW INDEX FROM `' . $name . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- name checked by TableGuard.
		foreach ( is_array( $raw ) ? $raw : [] as $row ) {
			$key                          = (string) $row['Key_name'];
			$indexes[ $key ]['name']      = $key;
			$indexes[ $key ]['unique']    = '0' === (string) $row['Non_unique'];
			$indexes[ $key ]['columns'][] = (string) $row['Column_name'];
		}
		$kv = TableGuard::keyValueColumns( array_column( $columns, 'name' ) );
		return [
			'topic'     => 'table',
			'name'      => $name,
			'columns'   => $columns,
			'indexes'   => array_values( $indexes ),
			'key_value' => $kv,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function metaKeys( string $meta, string $postType ): array {
		if ( ! in_array( $meta, self::META, true ) ) {
			throw new ApiException( 'invalid_param', 'name must be one of: ' . implode( ', ', self::META ), 400 );
		}
		$table = (string) $this->db->{$meta};
		$this->guard->checkTable( $table, $this->allTables() );
		$hint = '/*+ MAX_EXECUTION_TIME(' . TableGuard::TIMEOUT_MS . ') */';
		if ( '' !== $postType && 'postmeta' === $meta ) {
			$this->guard->checkTable( $this->db->posts, $this->allTables() );
			$sql = $this->db->prepare( "SELECT {$hint} m.meta_key, COUNT(*) AS n FROM `{$table}` m INNER JOIN `{$this->db->posts}` p ON p.ID = m.post_id WHERE p.post_type = %s GROUP BY m.meta_key ORDER BY n DESC LIMIT %d", $postType, self::MAX_ITEMS + 1 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table names.
		} else {
			$sql = $this->db->prepare( "SELECT {$hint} meta_key, COUNT(*) AS n FROM `{$table}` GROUP BY meta_key ORDER BY n DESC LIMIT %d", self::MAX_ITEMS + 1 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table name.
		}
		$rows = $this->db->get_results( (string) $sql, ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$this->failOnError();
		$rows  = is_array( $rows ) ? $rows : [];
		$items = [];
		foreach ( array_slice( $rows, 0, self::MAX_ITEMS ) as $row ) {
			$items[] = [
				'key'   => (string) $row[0],
				'count' => (int) $row[1],
			];
		}
		return [
			'topic'     => 'meta_keys',
			'table'     => $table,
			'post_type' => $postType,
			'items'     => $items,
			'truncated' => count( $rows ) > self::MAX_ITEMS,
		];
	}

	/**
	 * Option names by size, with the autoload total: never the values.
	 *
	 * @return array<string, mixed>
	 */
	private function options(): array {
		$table = $this->db->options;
		$this->guard->checkTable( $table, $this->allTables() );
		$hint     = '/*+ MAX_EXECUTION_TIME(' . TableGuard::TIMEOUT_MS . ') */';
		$autoload = function_exists( 'wp_autoload_values_to_autoload' ) ? wp_autoload_values_to_autoload() : [ 'yes', 'on', 'auto-on', 'auto' ];
		$in       = implode( ', ', array_fill( 0, count( $autoload ), '%s' ) );
		$rows     = $this->db->get_results( (string) $this->db->prepare( "SELECT {$hint} option_name, LENGTH(option_value) AS bytes, autoload FROM `{$table}` ORDER BY bytes DESC LIMIT %d", self::MAX_ITEMS + 1 ), ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$total    = $this->db->get_row( (string) $this->db->prepare( "SELECT COUNT(*), COALESCE(SUM(LENGTH(option_value)), 0) FROM `{$table}` WHERE autoload IN ({$in})", $autoload ), ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$this->failOnError();
		$rows  = is_array( $rows ) ? $rows : [];
		$items = [];
		foreach ( array_slice( $rows, 0, self::MAX_ITEMS ) as $row ) {
			$items[] = [
				'name'     => (string) $row[0],
				'bytes'    => (int) $row[1],
				'autoload' => (string) $row[2],
			];
		}
		return [
			'topic'          => 'options',
			'table'          => $table,
			'items'          => $items,
			'truncated'      => count( $rows ) > self::MAX_ITEMS,
			'autoload_count' => (int) ( $total[0] ?? 0 ),
			'autoload_bytes' => (int) ( $total[1] ?? 0 ),
		];
	}

	// ------------------------------------------------------------------ helpers

	/**
	 * Tables of the database that start with the base prefix (the guard filters them further).
	 *
	 * @return list<string>
	 */
	private function allTables(): array {
		if ( null === $this->tables ) {
			$found        = $this->db->get_col( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $this->db->base_prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->tables = array_values( array_map( 'strval', is_array( $found ) ? $found : [] ) );
		}
		return $this->tables;
	}

	/**
	 * @return list<array{name: string, type: string, nullable: bool, key: string, default?: string|null, extra: string}>
	 */
	private function columns( string $table ): array {
		$raw = $this->db->get_results( 'SHOW COLUMNS FROM `' . $table . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- name checked by TableGuard.
		$this->failOnError();
		$columns = [];
		foreach ( is_array( $raw ) ? $raw : [] as $row ) {
			$columns[] = [
				'name'     => (string) $row['Field'],
				'type'     => (string) $row['Type'],
				'nullable' => 'YES' === $row['Null'],
				'key'      => (string) $row['Key'],
				'default'  => null === $row['Default'] ? null : (string) $row['Default'],
				'extra'    => (string) $row['Extra'],
			];
		}
		return $columns;
	}

	/**
	 * @throws ApiException On invalid input or denied access.
	 */
	private function failOnError(): void {
		if ( '' !== (string) $this->db->last_error ) {
			$message = substr( (string) $this->db->last_error, 0, 200 );
			throw new ApiException( 'db_error', 'Database error: ' . $message, 500 );
		}
	}
}
