<?php
/**
 * Redaction of database values returned to the agent (0.6.0): secrets always, personal data by default.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Security;

use Lab591\DevBridge\Security\DataRedactor;
use PHPUnit\Framework\TestCase;

final class DataRedactorTest extends TestCase {

	private const KV = [
		'key'   => 'option_name',
		'value' => 'option_value',
	];

	/**
	 * @param list<string>                           $columns
	 * @param list<mixed>                            $row
	 * @param array{key: string, value: string}|null $kv
	 * @return list<mixed>
	 */
	private static function row( array $columns, array $row, ?array $kv = null, bool $personal = true ): array {
		return ( new DataRedactor( $personal ) )->row( $columns, $row, $kv );
	}

	public function test_values_of_sensitive_keys_are_redacted(): void {
		$cols = [ 'option_name', 'option_value' ];
		$this->assertSame( [ 'wp_mail_smtp_pass', '[redacted]' ], self::row( $cols, [ 'wp_mail_smtp_pass', 'hunter2' ], self::KV ) );
		$this->assertSame( [ 'blogname', 'My site' ], self::row( $cols, [ 'blogname', 'My site' ], self::KV ) );
	}

	public function test_secrets_nested_in_serialized_and_json_values(): void {
		$cols       = [ 'option_name', 'option_value' ];
		$serialized = 'a:2:{s:4:"host";s:13:"smtp.test.com";s:8:"password";s:9:"p;a"s:s{s";}';
		$out        = self::row( $cols, [ 'wp_mail_smtp', $serialized ], self::KV )[1];
		$this->assertStringContainsString( 's:4:"host";s:13:"smtp.test.com"', $out );
		$this->assertStringNotContainsString( 'p;a"s:s{s', $out );
		$this->assertStringContainsString( '[redacted]', $out );

		$json = '{"client_id":"abc","client_secret":"s3cr3t\"x","nested":{"api_key":"k-1"}}';
		$out  = self::row( $cols, [ 'integration', $json ], self::KV )[1];
		$this->assertStringContainsString( '"client_id":"abc"', $out );
		$this->assertStringNotContainsString( 's3cr3t', $out );
		$this->assertStringNotContainsString( 'k-1', $out );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function secrets(): array {
		return [
			'phpass hash'   => [ '$P$BzH9h0aBcDeFgHiJkLmNoPqRsTuVwX/' ],
			'bcrypt'        => [ '$2y$10$' . str_repeat( 'a', 53 ) ],
			'wp bcrypt'     => [ '$wp$2y$10$' . str_repeat( 'b', 53 ) ],
			'stripe'        => [ 'sk_live_51HxYzAbCdEfGhIjKlMn' ],
			'jwt'           => [ 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U' ],
			'aws'           => [ 'AKIAIOSFODNN7EXAMPLE' ],
			'github'        => [ 'ghp_' . str_repeat( 'A', 36 ) ],
			'private key'   => [ "-----BEGIN RSA PRIVATE KEY-----\nMIIEow\n-----END RSA PRIVATE KEY-----" ],
			'url with auth' => [ 'https://user:secretpw@example.com/feed' ],
		];
	}

	/**
	 * @dataProvider secrets
	 */
	public function test_secret_looking_values_are_redacted_anywhere( string $secret ): void {
		$out = self::row( [ 'post_content' ], [ "before {$secret} after" ], null, false )[0];
		$this->assertStringStartsWith( 'before ', $out );
		$this->assertStringContainsString( '[redacted]', $out );
		$this->assertStringNotContainsString( 'secretpw', $out );
		$this->assertStringNotContainsString( substr( $secret, 4, 12 ), $out );
	}

	public function test_personal_data_is_masked_by_default(): void {
		$cols = [ 'ID', 'user_login', 'user_email', 'display_name' ];
		$this->assertSame( [ 1, 'admin', '[personal]', '[personal]' ], self::row( $cols, [ 1, 'admin', 'jane@example.com', 'Jane Doe' ] ) );
		$this->assertSame( [ 'Contact [email] for info' ], self::row( [ 'post_content' ], [ 'Contact jane@example.com for info' ] ) );
		$this->assertSame( [ '[personal]' ], self::row( [ 'comment_author_IP' ], [ '203.0.113.9' ] ) );

		$meta = [
			'key'   => 'meta_key',
			'value' => 'meta_value',
		];
		$this->assertSame( [ 'billing_phone', '[personal]' ], self::row( [ 'meta_key', 'meta_value' ], [ 'billing_phone', '+39 333 1234567' ], $meta ) );
		$this->assertSame( [ '_price', '19.90' ], self::row( [ 'meta_key', 'meta_value' ], [ '_price', '19.90' ], $meta ) );
	}

	public function test_personal_data_can_be_shown_but_secrets_never(): void {
		$this->assertSame( [ 'jane@example.com' ], self::row( [ 'user_email' ], [ 'jane@example.com' ], null, false ) );
		$this->assertSame( [ 'wp_mail_smtp_pass', '[redacted]' ], self::row( [ 'option_name', 'option_value' ], [ 'wp_mail_smtp_pass', 'x' ], self::KV, false ) );
	}

	public function test_long_values_are_clipped_and_nulls_kept(): void {
		[ $long, $null, $number ] = self::row( [ 'post_content', 'post_excerpt', 'ID' ], [ str_repeat( 'x', DataRedactor::MAX_CELL + 50 ), null, 7 ] );
		$this->assertSame( DataRedactor::MAX_CELL, mb_strlen( explode( ' [+', $long )[0] ) );
		$this->assertStringEndsWith( '[+50 chars]', $long );
		$this->assertNull( $null );
		$this->assertSame( 7, $number );
	}

	public function test_redactions_are_counted(): void {
		$redactor = new DataRedactor( true );
		$redactor->row( [ 'user_email', 'post_content' ], [ 'a@b.co', 'sk_live_51HxYzAbCdEfGhIjKlMn' ], null );
		$this->assertSame( 2, $redactor->count() );
	}
}
