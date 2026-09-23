<?php
/**
 * Multisite behaviour (SPEC 2.14): network-wide storage, super admin capability, network health URLs.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Core;

use Lab591\DevBridge\Admin\SettingsForm;
use Lab591\DevBridge\Deploy\HealthPaths;
use Lab591\DevBridge\Deploy\Manifest;
use Lab591\DevBridge\Mode;
use Lab591\DevBridge\Security\PathPolicy;
use Lab591\DevBridge\Security\RateLimiter;
use Lab591\DevBridge\Security\WritableRootValidator;
use Lab591\DevBridge\Settings;
use Lab591\DevBridge\Support\ApiException;
use Lab591\DevBridge\Support\Options;
use Lab591\DevBridge\Tests\Support\FsFixture;
use Lab591\DevBridge\Tests\Support\WpStubs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MultisiteTest extends TestCase {

	private const SITES = [
		[
			'host' => 'example.test',
			'path' => '/',
		],
		[
			'host' => 'example.test',
			'path' => '/negozio/',
		],
		[
			'host' => 'shop.example.test',
			'path' => '/',
		],
	];

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_capability_and_storage_depend_on_multisite(): void {
		$this->assertSame( 'manage_options', Options::capability() );
		WpStubs::$multisite = true;
		$this->assertSame( 'manage_network_options', Options::capability() );
	}

	public function test_mode_and_settings_are_network_wide(): void {
		WpStubs::$multisite = true;
		$mode               = new Mode( new Settings() );
		$mode->enable( 'write', 2, 1 );
		$this->assertSame( 'write', WpStubs::$siteOptions[ Mode::OPTION ]['mode'] );
		$this->assertArrayNotHasKey( Mode::OPTION, WpStubs::$options, 'Nothing stored per site' );

		( new Settings() )->save( [ 'allowed_user_ids' => [ 1 ] ] );
		$this->assertSame( [ 1 ], WpStubs::$siteOptions[ Settings::OPTION ]['allowed_user_ids'] );
		$this->assertSame( [ 1 ], ( new Settings() )->allowedUserIds() );

		// A value stored per site (e.g. by a subsite before network activation) is ignored.
		WpStubs::$options[ Mode::OPTION ] = [
			'mode'       => 'off',
			'expires_at' => 0,
		];
		$this->assertSame( 'write', ( new Mode( new Settings() ) )->current() );
	}

	public function test_rate_limit_is_shared_across_the_network(): void {
		WpStubs::$multisite = true;
		$rl                 = new RateLimiter();
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertNull( $rl->hit( 1, 'write', 3, 1_000_040 ) );
		}
		$this->assertNotNull( $rl->hit( 1, 'write', 3, 1_000_040 ) );
		$this->assertSame( [], WpStubs::$transients );
	}

	public function test_blogs_dir_is_denied_by_default(): void {
		$this->assertContains( 'wp-content/blogs.dir/**', PathPolicy::DEFAULT_DENY_PATTERNS );
	}

	public static function networkUrls(): array {
		return [
			'main site'         => [ 'https://example.test/', true ],
			'subsite page'      => [ 'https://example.test/negozio/carrello/', true ],
			'subsite query'     => [ 'http://example.test/negozio/?p=1', true ],
			'mapped domain'     => [ 'https://shop.example.test/checkout/', true ],
			'foreign host'      => [ 'https://evil.test/', false ],
			'host suffix trick' => [ 'https://example.test.evil.test/', false ],
			'credentials'       => [ 'https://user:pw@example.test/', false ],
			'port'              => [ 'https://example.test:8080/', false ],
			'fragment'          => [ 'https://example.test/a#b', false ],
			'traversal'         => [ 'https://example.test/negozio/../wp-admin/', false ],
			'encoded traversal' => [ 'https://example.test/negozio/%2e%2e/x', false ],
			'other scheme'      => [ 'ftp://example.test/', false ],
			'scheme relative'   => [ '//example.test/', false ],
		];
	}

	#[DataProvider( 'networkUrls' )]
	public function test_full_urls_only_for_sites_of_the_network( string $url, bool $ok ): void {
		$this->assertSame( $ok, HealthPaths::isNetworkUrl( $url, self::SITES ) );
		if ( $ok ) {
			$this->assertSame( [ $url ], HealthPaths::parse( [ $url ], 'invalid_param', self::SITES ) );
		}
	}

	public function test_full_urls_are_refused_on_single_sites(): void {
		$this->expectException( ApiException::class );
		HealthPaths::parse( [ 'https://example.test/' ] );
	}

	public function test_manifest_accepts_network_health_urls(): void {
		$json = (string) json_encode(
			[
				'files'        => [
					[
						'p'      => 'wp-content/themes/child/a.css',
						'action' => 'delete',
					],
				],
				'health_paths' => [ '/', 'https://example.test/negozio/' ],
			]
		);
		$this->assertSame( [ '/', 'https://example.test/negozio/' ], Manifest::parse( $json, 10, self::SITES )->healthPaths );
		$this->expectException( ApiException::class );
		Manifest::parse( $json, 10 );
	}

	public function test_admin_health_urls_may_target_any_site_of_the_network(): void {
		$fx   = FsFixture::wordpress();
		$form = new SettingsForm(
			static fn ( bool $mu ) => new WritableRootValidator( $fx->abspath, [], $mu ),
			$fx->abspath,
			[ 'example.test', 'shop.example.test' ]
		);
		$out  = $form->sanitize( [ 'health_urls' => "https://example.test/negozio/\nhttps://shop.example.test/\nhttps://evil.test/" ], Settings::defaults() );
		$fx->cleanup();
		$this->assertSame( [ 'https://example.test/negozio/', 'https://shop.example.test/' ], $out['health_urls'] );
		$this->assertCount( 1, $form->errors() );
	}
}
