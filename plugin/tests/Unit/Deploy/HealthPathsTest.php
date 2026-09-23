<?php
/**
 * Agent-declared health check paths: validation (never full URLs, same site only).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Deploy;

use Lab591\DevBridge\Deploy\HealthPaths;
use Lab591\DevBridge\Deploy\Manifest;
use Lab591\DevBridge\Support\ApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HealthPathsTest extends TestCase {

	public function test_valid_paths_are_kept_and_deduplicated(): void {
		$this->assertSame(
			[ '/', '/shop/', '/contatti/?utm=1', '/città/' ],
			HealthPaths::parse( [ '/', '/shop/', '/shop/', '/contatti/?utm=1', '/città/' ] )
		);
		$this->assertSame( [], HealthPaths::parse( null ) );
		$this->assertSame( [], HealthPaths::parse( [] ) );
	}

	public static function invalid(): array {
		return [
			'not a list'       => [ 'shop' ],
			'object'           => [ [ 'a' => '/x' ] ],
			'non string'       => [ [ 5 ] ],
			'full url'         => [ [ 'https://evil.test/' ] ],
			'scheme relative'  => [ [ '//evil.test/' ] ],
			'no leading slash' => [ [ 'shop/' ] ],
			'backslash'        => [ [ '/\\evil.test' ] ],
			'dot dot'          => [ [ '/a/../wp-admin/' ] ],
			'encoded dot dot'  => [ [ '/a/%2e%2e/b' ] ],
			'control char'     => [ [ "/a\n" ] ],
			'fragment'         => [ [ '/a#b' ] ],
			'userinfo'         => [ [ '/@evil.test' ] ],
			'too long'         => [ [ '/' . str_repeat( 'a', 300 ) ] ],
			'too many'         => [ array_map( static fn ( $i ) => "/p$i/", range( 1, 11 ) ) ],
		];
	}

	#[DataProvider( 'invalid' )]
	public function test_invalid_paths_are_rejected( mixed $value ): void {
		$this->expectException( ApiException::class );
		HealthPaths::parse( $value );
	}

	public function test_manifest_carries_health_paths(): void {
		$json = (string) json_encode(
			[
				'files'        => [
					[
						'p'      => 'wp-content/themes/child/a.css',
						'action' => 'delete',
					],
				],
				'health_paths' => [ '/shop/' ],
			]
		);
		$this->assertSame( [ '/shop/' ], Manifest::parse( $json, 10 )->healthPaths );

		try {
			Manifest::parse( str_replace( '\/shop\/', 'https:\/\/evil.test\/', $json ), 10 );
			$this->fail( 'Full URL accepted' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'invalid_manifest', $e->errorCode() );
		}
	}
}
