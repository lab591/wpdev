<?php
/**
 * Introspection: callbacks are described by name and site-relative location only.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Services;

use Lab591\DevBridge\Services\IntrospectionService;
use Lab591\DevBridge\Support\ApiException;
use PHPUnit\Framework\TestCase;

final class IntrospectionServiceTest extends TestCase {

	/** This test file lives under the plugin folder: use it as the "site root". */
	private static function root(): string {
		return dirname( __DIR__, 3 );
	}

	public static function sampleStatic(): void {
	}

	public function sampleMethod(): void {
	}

	public function test_closure_has_relative_file_and_line(): void {
		$line = __LINE__ + 1;
		$cb   = static function (): void {};
		$d    = IntrospectionService::describeCallback( $cb, self::root() );
		$this->assertSame( '{closure}', $d['callback'] );
		$this->assertSame( 'tests/Unit/Services/IntrospectionServiceTest.php', $d['file'] );
		$this->assertSame( $line, $d['line'] );
	}

	public function test_methods_static_instance_and_string_forms(): void {
		$static = IntrospectionService::describeCallback( [ self::class, 'sampleStatic' ], self::root() );
		$this->assertSame( self::class . '::sampleStatic', $static['callback'] );
		$this->assertArrayHasKey( 'line', $static );

		$instance = IntrospectionService::describeCallback( [ $this, 'sampleMethod' ], self::root() );
		$this->assertSame( self::class . '->sampleMethod', $instance['callback'] );

		$string = IntrospectionService::describeCallback( self::class . '::sampleStatic', self::root() );
		$this->assertSame( $static['line'], $string['line'] );
	}

	public function test_invokable_object(): void {
		$obj = new class() {
			public function __invoke(): void {
			}
		};
		$d   = IntrospectionService::describeCallback( $obj, self::root() );
		$this->assertStringEndsWith( '::__invoke', $d['callback'] );
		$this->assertSame( 'tests/Unit/Services/IntrospectionServiceTest.php', $d['file'] );
	}

	public function test_internal_function_and_code_outside_the_site_have_no_location(): void {
		$this->assertSame( [ 'callback' => 'strlen' ], IntrospectionService::describeCallback( 'strlen', self::root() ) );
		$outside = IntrospectionService::describeCallback( static function (): void {}, sys_get_temp_dir() . '/other-site' );
		$this->assertSame( [ 'callback' => '{closure}' ], $outside, 'No absolute path ever leaks' );
	}

	public function test_unknown_callbacks_do_not_throw(): void {
		$this->assertSame( [ 'callback' => 'missing_function_xyz' ], IntrospectionService::describeCallback( 'missing_function_xyz', self::root() ) );
		$this->assertSame( [ 'callback' => 'Nope\\Missing::run' ], IntrospectionService::describeCallback( 'Nope\\Missing::run', self::root() ) );
		$this->assertSame( [ 'callback' => 'unknown' ], IntrospectionService::describeCallback( 42, self::root() ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function invalidRequests(): array {
		return [
			'unknown topic'     => [ 'options', '' ],
			'hook without name' => [ 'hook', '' ],
			'name with spaces'  => [ 'hook', 'init now' ],
			'name with quotes'  => [ 'hook', "init'" ],
			'name too long'     => [ 'hook', str_repeat( 'a', 201 ) ],
		];
	}

	/**
	 * @dataProvider invalidRequests
	 */
	public function test_invalid_requests_are_rejected( string $topic, string $name ): void {
		$this->expectException( ApiException::class );
		( new IntrospectionService( self::root() ) )->get( $topic, $name );
	}
}
