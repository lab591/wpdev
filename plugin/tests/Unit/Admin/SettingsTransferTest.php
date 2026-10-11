<?php
/**
 * Settings export/import (0.7.1).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Admin;

use Lab591\DevBridge\Admin\SettingsTransfer;
use Lab591\DevBridge\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTransferTest extends TestCase {

	private const SITE = 'https://example.test';

	/**
	 * @return array<string, mixed>
	 */
	private static function exported( string $site = self::SITE ): array {
		$settings                     = Settings::defaults();
		$settings['allowed_user_ids'] = [ 2, 7 ];
		$settings['writable_roots']   = [ 'wp-content/themes/child' ];
		$settings['invalid_roots']    = [];
		return SettingsTransfer::export( $settings, [ 'anna', 'marco' ], '0.7.1', $site, 1_800_000_000 );
	}

	private static function json( mixed $data ): string {
		return (string) json_encode( $data );
	}

	public function testExportHasOnlySettingsAndTheLoginsOfTheAllowedUsers(): void {
		$doc = self::exported();
		$this->assertSame( SettingsTransfer::FORMAT, $doc['format'] );
		$this->assertSame( '2027-01-15T08:00:00Z', $doc['exported'] );
		$this->assertSame( [ 'anna', 'marco' ], $doc['allowed_users'] );
		$this->assertArrayNotHasKey( 'invalid_roots', $doc['settings'] );
		$this->assertSame( array_keys( Settings::defaults() ), array_keys( $doc['settings'] ) );
	}

	public function testRoundTrip(): void {
		$import = SettingsTransfer::parse( self::json( self::exported() ) );
		$this->assertSame( [], $import['ignored'] );
		$this->assertSame( [ 'wp-content/themes/child' ], $import['settings']['writable_roots'] );
		$this->assertSame( self::SITE, $import['site'] );
		$this->assertSame( [ 'anna', 'marco' ], $import['users'] );
	}

	public function testABareSettingsObjectIsAccepted(): void {
		$import = SettingsTransfer::parse( '{"db_access":"read","retention_releases":"5","unknown":1,"invalid_roots":[]}' );
		$this->assertSame(
			[
				'db_access'          => 'read',
				'retention_releases' => '5',
			],
			$import['settings']
		);
		$this->assertSame( [ 'unknown' ], $import['ignored'] );
		$this->assertNull( $import['users'] );
	}

	public function testValuesOfTheWrongShapeAreIgnored(): void {
		$import = SettingsTransfer::parse(
			self::json(
				[
					'health_backend'   => 'yes',
					'deny_patterns'    => [ [ 'nested' ] ],
					'write_extensions' => [ 'a' => 'css' ],
					'limits'           => [ 'read_bytes', 'grep_ms' ],
					'grep_rg'          => 5,
					'notify_emails'    => [ 'a@example.test' ],
					'limits_ok'        => true,
				]
			)
		);
		$this->assertSame( [ 'notify_emails' => [ 'a@example.test' ] ], $import['settings'] );
		$this->assertSame( [ 'health_backend', 'deny_patterns', 'write_extensions', 'limits', 'grep_rg', 'limits_ok' ], $import['ignored'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function invalidTexts(): array {
		return [
			'empty'            => [ '  ' ],
			'not json'         => [ 'db_access=read' ],
			'list'             => [ '[1,2]' ],
			'other format'     => [ '{"format":"something-else","settings":{"db_access":"read"}}' ],
			'settings missing' => [ '{"format":"lab591-dev-bridge-settings"}' ],
			'no known setting' => [ '{"foo":1}' ],
			'too deep'         => [ '{"a":' . str_repeat( '[', 10 ) . str_repeat( ']', 10 ) . '}' ],
			'too long'         => [ '{"grep_rg":"' . str_repeat( 'x', SettingsTransfer::MAX_BYTES ) . '"}' ],
		];
	}

	/**
	 * @dataProvider invalidTexts
	 */
	public function testInvalidTextsAreRefused( string $text ): void {
		$this->expectException( \InvalidArgumentException::class );
		SettingsTransfer::parse( $text );
	}

	public function testUserIdsAreKeptOnlyOnTheSameSite(): void {
		$never = static fn (): int => throw new \LogicException( 'not called' );
		$same  = SettingsTransfer::resolveUsers( SettingsTransfer::parse( self::json( self::exported( 'http://Example.test/' ) ) ), self::SITE, $never );
		$this->assertSame( [ 2, 7 ], $same['ids'] );
	}

	public function testOnAnotherSiteUsersAreMatchedByLogin(): void {
		$import = SettingsTransfer::parse( self::json( self::exported( 'https://other.test' ) ) );
		$users  = SettingsTransfer::resolveUsers( $import, self::SITE, static fn ( string $login ): int => 'anna' === $login ? 40 : 0 );
		$this->assertSame( [ 40 ], $users['ids'] );
		$this->assertSame( [ 'marco' ], $users['missing'] );
	}

	public function testIdsWithoutLoginsFromAnUnknownSiteAreDropped(): void {
		$import = SettingsTransfer::parse( '{"allowed_user_ids":[1,2]}' );
		$users  = SettingsTransfer::resolveUsers( $import, self::SITE, static fn (): int => 1 );
		$this->assertSame( [], $users['ids'] );

		$none = SettingsTransfer::resolveUsers( SettingsTransfer::parse( '{"db_access":"off"}' ), self::SITE, static fn (): int => 1 );
		$this->assertNull( $none['ids'] ); // Users untouched.
	}
}
