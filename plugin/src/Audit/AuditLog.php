<?php
/**
 * Audit log table `{prefix}devbridge_audit` (SPEC 2.11). File contents are never recorded.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Audit;

use Lab591\DevBridge\Support\Options;

final class AuditLog {

	public const DB_VERSION        = '2';
	public const DB_VERSION_OPTION = 'devbridge_audit_db';
	public const MAX_PATHS         = 20;
	public const MAX_PATH_CHARS    = 200;
	public const MAX_PATHS_JSON    = 2000;

	public function table(): string {
		global $wpdb;
		// One network-wide table on multisite (base_prefix equals prefix on single sites).
		return $wpdb->base_prefix . 'devbridge_audit';
	}

	public function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $this->table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ts datetime NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				blog_id bigint(20) unsigned NOT NULL DEFAULT 1,
				ip varchar(45) NOT NULL DEFAULT '',
				endpoint varchar(32) NOT NULL DEFAULT '',
				mode varchar(8) NOT NULL DEFAULT '',
				paths text NULL,
				bytes bigint(20) unsigned NOT NULL DEFAULT 0,
				status smallint(5) unsigned NOT NULL DEFAULT 0,
				duration_ms int(10) unsigned NOT NULL DEFAULT 0,
				release_id varchar(40) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY ts (ts),
				KEY user_id (user_id),
				KEY endpoint (endpoint)
			) {$charset};"
		);
		Options::update( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public function maybeUpgrade(): void {
		if ( Options::get( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			$this->install();
		}
	}

	/**
	 * @param array{user_id: int, ip: string, endpoint: string, mode: string, paths: string[], bytes: int, status: int, duration_ms: int, release_id?: string} $entry
	 */
	public function record( array $entry ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table, write-only log.
		$wpdb->insert(
			$this->table(),
			[
				'ts'          => gmdate( 'Y-m-d H:i:s' ),
				'user_id'     => max( 0, $entry['user_id'] ),
				'blog_id'     => function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1,
				'ip'          => substr( $entry['ip'], 0, 45 ),
				'endpoint'    => substr( $entry['endpoint'], 0, 32 ),
				'mode'        => substr( $entry['mode'], 0, 8 ),
				'paths'       => self::encodePaths( $entry['paths'] ),
				'bytes'       => max( 0, $entry['bytes'] ),
				'status'      => $entry['status'],
				'duration_ms' => max( 0, $entry['duration_ms'] ),
				'release_id'  => substr( $entry['release_id'] ?? '', 0, 40 ),
			],
			[ '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' ]
		);
	}

	/**
	 * JSON list of paths, bounded in count and length.
	 *
	 * @param string[] $paths
	 */
	public static function encodePaths( array $paths ): string {
		$total = count( $paths );
		$paths = array_slice( array_values( $paths ), 0, self::MAX_PATHS );
		$paths = array_map( static fn ( $p ): string => mb_substr( (string) $p, 0, self::MAX_PATH_CHARS ), $paths );
		if ( $total > self::MAX_PATHS ) {
			$paths[] = sprintf( '... +%d', $total - self::MAX_PATHS );
		}
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
		$json  = (string) wp_json_encode( $paths, $flags );
		$size  = strlen( $json );
		$count = count( $paths );
		while ( $size > self::MAX_PATHS_JSON && $count > 1 ) {
			array_splice( $paths, -2, 1 );
			$json  = (string) wp_json_encode( $paths, $flags );
			$size  = strlen( $json );
			$count = count( $paths );
		}
		return $json;
	}

	public function cleanup( int $days ): void {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE ts < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}

	/**
	 * @param array{endpoint?: string, user_id?: int, blog_id?: int, status?: string, from?: string, to?: string} $filters
	 * @return array{rows: list<object>, total: int}
	 */
	public function query( array $filters, int $page = 1, int $perPage = 50 ): array {
		global $wpdb;
		$table  = $this->table();
		$where  = [ '1=1' ];
		$params = [];
		if ( ! empty( $filters['endpoint'] ) ) {
			$where[]  = 'endpoint = %s';
			$params[] = $filters['endpoint'];
		}
		if ( ! empty( $filters['blog_id'] ) ) {
			$where[]  = 'blog_id = %d';
			$params[] = (int) $filters['blog_id'];
		}
		if ( ! empty( $filters['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $filters['user_id'];
		}
		if ( 'errors' === ( $filters['status'] ?? '' ) ) {
			$where[] = 'status >= 400';
		} elseif ( 'ok' === ( $filters['status'] ?? '' ) ) {
			$where[] = 'status < 400';
		}
		if ( ! empty( $filters['from'] ) ) {
			$where[]  = 'ts >= %s';
			$params[] = $filters['from'] . ' 00:00:00';
		}
		if ( ! empty( $filters['to'] ) ) {
			$where[]  = 'ts <= %s';
			$params[] = $filters['to'] . ' 23:59:59';
		}
		$sqlWhere = implode( ' AND ', $where );
		$offset   = max( 0, ( $page - 1 ) * $perPage );

		// Dynamic WHERE built only from fixed fragments above; every value goes through prepare().
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
		$countSql = "SELECT COUNT(*) FROM {$table} WHERE {$sqlWhere} AND %d = %d";
		$rowsSql  = "SELECT * FROM {$table} WHERE {$sqlWhere} ORDER BY id DESC LIMIT %d OFFSET %d";
		$total    = (int) $wpdb->get_var( $wpdb->prepare( $countSql, array_merge( $params, [ 1, 1 ] ) ) );
		$rows     = $wpdb->get_results( $wpdb->prepare( $rowsSql, array_merge( $params, [ $perPage, $offset ] ) ) );
		// phpcs:enable

		return [
			'rows'  => is_array( $rows ) ? array_values( $rows ) : [],
			'total' => $total,
		];
	}

	public function drop(): void {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		Options::delete( self::DB_VERSION_OPTION );
	}
}
