<?php
/**
 * Settings form validation (SPEC 2.4).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Core;

use Lab591\DevBridge\Admin\SettingsForm;
use Lab591\DevBridge\Security\WritableRootValidator;
use Lab591\DevBridge\Settings;
use Lab591\DevBridge\Tests\Support\FsFixture;
use PHPUnit\Framework\TestCase;

final class SettingsFormTest extends TestCase {

	private FsFixture $fx;

	protected function setUp(): void {
		$this->fx = FsFixture::wordpress();
	}

	protected function tearDown(): void {
		$this->fx->cleanup();
	}

	private function form(): SettingsForm {
		$fx = $this->fx;
		return new SettingsForm(
			static fn ( bool $mu ) => new WritableRootValidator( $fx->abspath, [ $fx->abs( 'wp-content/plugins/lab591-dev-bridge' ) ], $mu ),
			$fx->abspath,
			'example.test'
		);
	}

	public function test_valid_input(): void {
		$form = $this->form();
		$out  = $form->sanitize(
			[
				'allowed_user_ids'   => [ '1', '7', 'x', '-3' ],
				'writable_roots'     => "wp-content/themes/child\r\nwp-content/plugins/myplug/\n",
				'read_roots'         => ".\nwp-content/themes",
				'deny_patterns'      => "wp-config.php\n**/*.bak",
				'write_extensions'   => 'php, .CSS, js',
				'ip_allowlist'       => "203.0.113.0/24\n2001:db8::1",
				'trusted_proxies'    => '',
				'health_urls'        => "https://example.test/\nhttps://EXAMPLE.test/shop/",
				'retention_releases' => '5',
				'max_write_hours'    => '20',
				'limits'             => [ 'read_bytes' => '1000' ],
			],
			Settings::defaults()
		);
		$this->assertSame( [], $form->errors() );
		$this->assertSame( [ 1, 7 ], $out['allowed_user_ids'] );
		$this->assertSame( [ 'wp-content/themes/child', 'wp-content/plugins/myplug' ], $out['writable_roots'] );
		$this->assertSame( [ '', 'wp-content/themes' ], $out['read_roots'] );
		$this->assertSame( [ 'php', 'css', 'js' ], $out['write_extensions'] );
		$this->assertSame( 5, $out['retention_releases'] );
		$this->assertSame( 8, $out['max_write_hours'], 'Capped at the hard maximum' );
		$this->assertSame( 1000, $out['limits']['read_bytes'] );
		$this->assertCount( 2, $out['health_urls'] );
	}

	public function test_invalid_entries_are_dropped_and_reported(): void {
		$form = $this->form();
		$out  = $form->sanitize(
			[
				'writable_roots'   => "wp-content/plugins/lab591-dev-bridge\nwp-content/themes\nwp-content/uploads\n../outside",
				'read_roots'       => "../outside\nwp-config.php",
				'write_extensions' => 'phtml, php5, ex e, css',
				'ip_allowlist'     => 'example.com',
				'health_urls'      => "https://evil.test/\nftp://example.test/\nnot a url",
			],
			Settings::defaults()
		);
		$this->assertSame( [], $out['writable_roots'] );
		$this->assertSame( [], $out['read_roots'] );
		$this->assertSame( [ 'css' ], $out['write_extensions'] );
		$this->assertSame( [], $out['ip_allowlist'] );
		$this->assertSame( [], $out['health_urls'] );
		$this->assertGreaterThanOrEqual( 12, count( $form->errors() ) );
	}

	public function test_ripgrep_binary_setting(): void {
		$form = $this->form();
		$this->assertSame( 'rg', $form->sanitize( [ 'grep_rg' => ' rg ' ], Settings::defaults() )['grep_rg'] );
		$this->assertSame( '', $form->sanitize( [ 'grep_rg' => '' ], Settings::defaults() )['grep_rg'] );
		$bad = $form->sanitize( [ 'grep_rg' => 'C:/Windows/System32/cmd.exe' ], Settings::defaults() );
		$this->assertSame( '', $bad['grep_rg'] );
		$this->assertCount( 1, $form->errors() );
	}

	public function test_mu_plugins_only_when_enabled(): void {
		$this->fx->write( 'wp-content/mu-plugins/tools/tools.php', "<?php\n" );
		$off = $this->form()->sanitize( [ 'writable_roots' => 'wp-content/mu-plugins/tools' ], Settings::defaults() );
		$this->assertSame( [], $off['writable_roots'] );
		$on = $this->form()->sanitize(
			[
				'writable_roots'   => 'wp-content/mu-plugins/tools',
				'allow_mu_plugins' => '1',
			],
			Settings::defaults()
		);
		$this->assertSame( [ 'wp-content/mu-plugins/tools' ], $on['writable_roots'] );
	}

	public function test_checkbox_selection_drops_folders_covered_by_a_selected_parent(): void {
		$form = $this->form();
		$out  = $form->sanitize(
			[ 'writable_roots' => [ 'wp-content/themes/child/inc', 'wp-content/themes/child', 'wp-content/themes/parent', 'wp-content/plugins/myplug' ] ],
			Settings::defaults()
		);
		$this->assertSame( [ 'wp-content/themes/child', 'wp-content/themes/parent', 'wp-content/plugins/myplug' ], $out['writable_roots'] );
		$this->assertSame( [], $form->errors() );
	}

	public function test_database_settings(): void {
		$form = $this->form();
		$out  = $form->sanitize(
			[
				'db_access'          => 'read',
				'db_redact_personal' => false,
				'db_excluded_tables' => [ 'wp_wc_*', 'wp_users; DROP', 'wp_x' ],
			],
			Settings::defaults()
		);
		$this->assertSame( 'read', $out['db_access'] );
		$this->assertFalse( $out['db_redact_personal'] );
		$this->assertSame( [ 'wp_wc_*', 'wp_x' ], $out['db_excluded_tables'] );
		$this->assertCount( 1, $form->errors() );

		$defaults = Settings::defaults();
		$this->assertSame( 'off', $defaults['db_access'], 'Off by default' );
		$this->assertTrue( $defaults['db_redact_personal'], 'Personal data masked by default' );
		$this->assertSame( 'off', $form->sanitize( [ 'db_access' => 'write' ], $defaults )['db_access'], 'No write level exists' );
		$this->assertTrue( $form->sanitize( [], $defaults )['db_redact_personal'], 'Missing field keeps the current value' );
	}
}
