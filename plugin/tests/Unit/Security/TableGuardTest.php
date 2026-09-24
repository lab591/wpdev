<?php
/**
 * Database read access (0.6.0): which tables and columns can be read, and how queries are built.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Security;

use Lab591\DevBridge\Security\TableGuard;
use Lab591\DevBridge\Support\ApiException;
use PHPUnit\Framework\TestCase;

final class TableGuardTest extends TestCase {

	private const TABLES = [ 'wp_posts', 'wp_postmeta', 'wp_options', 'wp_users', 'wp_usermeta', 'wp_wc_orders', 'wp_2_posts', 'other_app_accounts', 'wp_devbridge_audit' ];

	private const POSTS    = [ 'ID', 'post_author', 'post_title', 'post_status', 'post_type', 'post_content' ];
	private const OPTIONS  = [ 'option_id', 'option_name', 'option_value', 'autoload' ];
	private const USERS    = [ 'ID', 'user_login', 'user_pass', 'user_email', 'user_activation_key', 'display_name' ];
	private const POSTMETA = [ 'meta_id', 'post_id', 'meta_key', 'meta_value' ];

	private static function guard( array $excluded = [] ): TableGuard {
		return new TableGuard( 'wp_', $excluded );
	}

	private static function expectCode( string $code, callable $callback ): void {
		try {
			$callback();
		} catch ( ApiException $e ) {
			self::assertSame( $code, $e->errorCode(), $e->getMessage() );
			return;
		}
		self::fail( "Expected {$code}" );
	}

	// ------------------------------------------------------------------ tables

	public function test_only_existing_tables_of_the_site_are_available(): void {
		$guard = self::guard();
		$guard->checkTable( 'wp_posts', self::TABLES );
		$guard->checkTable( 'wp_2_posts', self::TABLES );
		foreach ( [ 'wp_devbridge_audit', 'other_app_accounts', 'wp_missing', 'wp_posts`; DROP TABLE x', 'WP_POSTS', '' ] as $table ) {
			self::expectCode( 'db_table_denied', fn () => $guard->checkTable( $table, self::TABLES ) );
		}
	}

	public function test_excluded_tables_are_denied_after_resolution(): void {
		$guard = self::guard( [ 'wp_wc_*', 'wp_2_*' ] );
		self::expectCode( 'db_table_denied', fn () => $guard->checkTable( 'wp_wc_orders', self::TABLES ) );
		self::expectCode( 'db_table_denied', fn () => $guard->checkTable( 'wp_2_posts', self::TABLES ) );
		$this->assertSame( [ 'wp_posts', 'wp_postmeta', 'wp_options', 'wp_users', 'wp_usermeta' ], $guard->visibleTables( self::TABLES ) );
	}

	// ------------------------------------------------------------------ sensitive names

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function columns(): array {
		return [
			'user_pass'            => [ 'user_pass', true ],
			'user_activation_key'  => [ 'user_activation_key', true ],
			'api_key'              => [ 'api_key', true ],
			'apikey'               => [ 'apikey', true ],
			'license_key'          => [ 'license_key', true ],
			'access_token'         => [ 'access_token', true ],
			'client_secret'        => [ 'client_secret', true ],
			'password_hash'        => [ 'password_hash', true ],
			'meta_key is a name'   => [ 'meta_key', false ],
			'post_author'          => [ 'post_author', false ],
			'option_value'         => [ 'option_value', false ],
			'token in a word only' => [ 'tokenizer_mode', false ],
		];
	}

	/**
	 * @dataProvider columns
	 */
	public function test_sensitive_columns( string $column, bool $sensitive ): void {
		$this->assertSame( $sensitive, TableGuard::isSensitiveColumn( $column ) );
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function keys(): array {
		return [
			'smtp password'   => [ 'wp_mail_smtp_pass', true ],
			'stripe secret'   => [ 'woocommerce_stripe_secret_key', true ],
			'api key'         => [ 'my_plugin_api_key', true ],
			'license'         => [ 'elementor_pro_license_key', true ],
			'session tokens'  => [ 'session_tokens', true ],
			'auth salt'       => [ 'auth_salt', true ],
			'webhook url'     => [ 'slack_webhook_url', true ],
			'app passwords'   => [ '_application_passwords', true ],
			'own settings'    => [ 'devbridge_settings', true ],
			'site url'        => [ 'siteurl', false ],
			'post author key' => [ '_author_bio', false ],
			'edit lock'       => [ '_edit_lock', false ],
		];
	}

	/**
	 * @dataProvider keys
	 */
	public function test_sensitive_keys( string $key, bool $sensitive ): void {
		$this->assertSame( $sensitive, TableGuard::isSensitiveKey( $key ) );
	}

	public function test_key_value_tables_are_recognized(): void {
		$this->assertSame(
			[
				'key'   => 'option_name',
				'value' => 'option_value',
			],
			TableGuard::keyValueColumns( self::OPTIONS )
		);
		$this->assertSame( 'meta_key', TableGuard::keyValueColumns( self::POSTMETA )['key'] ?? null );
		$this->assertNull( TableGuard::keyValueColumns( self::POSTS ) );
	}

	// ------------------------------------------------------------------ query building

	public function test_simple_select_with_defaults(): void {
		$plan = self::guard()->buildSelect( 'wp_posts', self::POSTS, [] );
		$this->assertSame( 'SELECT /*+ MAX_EXECUTION_TIME(5000) */ `ID`, `post_author`, `post_title`, `post_status`, `post_type`, `post_content` FROM `wp_posts` LIMIT 21 OFFSET 0', $plan->sql );
		$this->assertSame( [], $plan->args );
		$this->assertSame( 20, $plan->limit );
	}

	public function test_where_order_limit_use_placeholders_for_values(): void {
		$plan = self::guard()->buildSelect(
			'wp_posts',
			self::POSTS,
			[
				'columns'  => [ 'ID', 'post_title' ],
				'where'    => [
					[
						'column' => 'post_type',
						'op'     => '=',
						'value'  => "page' OR 1=1 --",
					],
					[
						'column' => 'post_status',
						'op'     => 'in',
						'value'  => [ 'publish', 'draft' ],
					],
					[
						'column' => 'post_title',
						'op'     => 'like',
						'value'  => '%Home%',
					],
					[
						'column' => 'post_content',
						'op'     => 'is not null',
					],
				],
				'order_by' => 'ID',
				'order'    => 'desc',
				'limit'    => 5,
				'offset'   => 10,
			]
		);
		$this->assertSame(
			'SELECT /*+ MAX_EXECUTION_TIME(5000) */ `ID`, `post_title` FROM `wp_posts` WHERE `post_type` = %s AND `post_status` IN (%s, %s) AND `post_title` LIKE %s AND `post_content` IS NOT NULL ORDER BY `ID` DESC LIMIT 6 OFFSET 10',
			$plan->sql
		);
		$this->assertSame( [ "page' OR 1=1 --", 'publish', 'draft', '%Home%' ], $plan->args );
	}

	public function test_unknown_identifiers_and_operators_are_rejected(): void {
		$guard = self::guard();
		$bad   = [
			'unknown column'   => [ 'columns' => [ 'nope' ] ],
			'injected column'  => [ 'columns' => [ 'ID`, (SELECT 1) AS `x' ] ],
			'unknown operator' => [
				'where' => [
					[
						'column' => 'ID',
						'op'     => 'regexp',
						'value'  => 'x',
					],
				],
			],
			'where on unknown' => [
				'where' => [
					[
						'column' => 'x',
						'op'     => '=',
						'value'  => '1',
					],
				],
			],
			'bad order'        => [ 'order_by' => 'nope' ],
			'limit too high'   => [ 'limit' => 101 ],
			'offset too high'  => [ 'offset' => 10001 ],
			'value missing'    => [
				'where' => [
					[
						'column' => 'ID',
						'op'     => '=',
					],
				],
			],
			'non scalar value' => [
				'where' => [
					[
						'column' => 'ID',
						'op'     => '=',
						'value'  => [ 'a' ],
					],
				],
			],
			'empty IN'         => [
				'where' => [
					[
						'column' => 'ID',
						'op'     => 'in',
						'value'  => [],
					],
				],
			],
		];
		foreach ( $bad as $name => $spec ) {
			try {
				$guard->buildSelect( 'wp_posts', self::POSTS, $spec );
				$this->fail( "Accepted: {$name}" );
			} catch ( ApiException $e ) {
				$this->assertSame( 'invalid_param', $e->errorCode(), $name );
			}
		}
	}

	public function test_sensitive_columns_are_never_selected_filtered_or_sorted(): void {
		$guard = self::guard();
		$plan  = $guard->buildSelect( 'wp_users', self::USERS, [] );
		$this->assertStringNotContainsString( 'user_pass', $plan->sql );
		$this->assertStringNotContainsString( 'user_activation_key', $plan->sql );
		$this->assertSame( [ 'user_pass', 'user_activation_key' ], $plan->hidden );

		foreach ( [
			[ 'columns' => [ 'ID', 'user_pass' ] ],
			[
				'where' => [
					[
						'column' => 'user_pass',
						'op'     => 'like',
						'value'  => '$P$B%',
					],
				],
			],
			[ 'order_by' => 'user_activation_key' ],
		] as $spec ) {
			self::expectCode( 'db_column_denied', fn () => $guard->buildSelect( 'wp_users', self::USERS, $spec ) );
		}
	}

	public function test_value_filters_on_key_value_tables_exclude_sensitive_rows(): void {
		// Otherwise `option_value LIKE 'a%'` on a secret option would leak it one character at a time.
		$plan = self::guard()->buildSelect(
			'wp_options',
			self::OPTIONS,
			[
				'where' => [
					[
						'column' => 'option_value',
						'op'     => '=',
						'value'  => 'yes',
					],
				],
			]
		);
		$this->assertStringContainsString( 'AND LOWER(`option_name`) NOT REGEXP %s', $plan->sql );
		$this->assertSame( TableGuard::SENSITIVE_KEY_SQL, $plan->args[1] );

		$sorted = self::guard()->buildSelect( 'wp_options', self::OPTIONS, [ 'order_by' => 'option_value' ] );
		$this->assertStringContainsString( 'WHERE LOWER(`option_name`) NOT REGEXP %s ORDER BY', $sorted->sql );

		$byName = self::guard()->buildSelect(
			'wp_options',
			self::OPTIONS,
			[
				'where' => [
					[
						'column' => 'option_name',
						'op'     => '=',
						'value'  => 'siteurl',
					],
				],
			]
		);
		$this->assertStringNotContainsString( 'REGEXP', $byName->sql, 'Filtering by name only: rows are redacted on output' );
	}

	public function test_only_exact_matches_on_values_of_key_value_tables(): void {
		// A plugin option {"host":"…","api_key":"AK-1…"} is not a sensitive key, but LIKE
		// '%"api_key":"A%' or a range would extract the nested key one character at a time.
		foreach ( [ 'like', 'not like', '<', '>', '<=', '>=' ] as $op ) {
			self::expectCode(
				'db_filter_denied',
				fn () => self::guard()->buildSelect(
					'wp_postmeta',
					self::POSTMETA,
					[
						'where' => [
							[
								'column' => 'meta_value',
								'op'     => $op,
								'value'  => '%api_key%',
							],
						],
					]
				)
			);
		}
		$ok = self::guard()->buildSelect(
			'wp_postmeta',
			self::POSTMETA,
			[
				'where' => [
					[
						'column' => 'meta_key',
						'op'     => 'like',
						'value'  => '_billing%',
					],
				],
			]
		);
		$this->assertStringContainsString( '`meta_key` LIKE %s', $ok->sql, 'Keys can be searched freely' );
	}

	public function test_the_key_column_is_always_returned_with_the_value(): void {
		$plan = self::guard()->buildSelect( 'wp_postmeta', self::POSTMETA, [ 'columns' => [ 'meta_value' ] ] );
		$this->assertSame( [ 'meta_key', 'meta_value' ], $plan->columns, 'Needed to decide whether the value is redacted' );
		$this->assertSame(
			[
				'key'   => 'meta_key',
				'value' => 'meta_value',
			],
			$plan->keyValue
		);
	}

	public function test_sensitive_key_sql_pattern_matches_the_php_one(): void {
		foreach ( self::keys() as [ $key, $sensitive ] ) {
			$this->assertSame( $sensitive, 1 === preg_match( '/' . TableGuard::SENSITIVE_KEY_SQL . '/', strtolower( $key ) ), $key );
		}
	}
}
