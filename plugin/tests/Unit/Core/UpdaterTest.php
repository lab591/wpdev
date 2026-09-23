<?php
/**
 * Updates from GitHub Releases: only newer, stable releases with the plugin zip of this repository.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Core;

use Lab591\DevBridge\Support\Updater;
use PHPUnit\Framework\TestCase;

final class UpdaterTest extends TestCase {

	private const ZIP = 'https://github.com/lab591/wpdev/releases/download/v0.6.0/lab591-dev-bridge-0.6.0.zip';

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private static function release( array $overrides = [] ): array {
		return $overrides + [
			'tag_name'   => 'v0.6.0',
			'draft'      => false,
			'prerelease' => false,
			'assets'     => [
				[
					'name'                 => 'wpdev-0.6.0.tgz',
					'browser_download_url' => 'https://github.com/lab591/wpdev/releases/download/v0.6.0/wpdev-0.6.0.tgz',
				],
				[
					'name'                 => 'lab591-dev-bridge-0.6.0.zip',
					'browser_download_url' => self::ZIP,
				],
			],
		];
	}

	public function test_newer_release_with_zip_is_offered(): void {
		$update = Updater::toUpdate( self::release(), '0.5.0' );
		$this->assertSame( '0.6.0', $update['version'] );
		$this->assertSame( self::ZIP, $update['package'] );
		$this->assertSame( 'lab591-dev-bridge', $update['slug'] );
	}

	public function test_same_or_older_version_is_not_offered(): void {
		$this->assertNull( Updater::toUpdate( self::release(), '0.6.0' ) );
		$this->assertNull( Updater::toUpdate( self::release(), '0.7.0' ) );
	}

	public function test_drafts_prereleases_and_bad_tags_are_ignored(): void {
		$this->assertNull( Updater::toUpdate( self::release( [ 'prerelease' => true ] ), '0.5.0' ) );
		$this->assertNull( Updater::toUpdate( self::release( [ 'draft' => true ] ), '0.5.0' ) );
		$this->assertNull( Updater::toUpdate( self::release( [ 'tag_name' => 'latest' ] ), '0.5.0' ) );
	}

	public function test_zip_must_come_from_this_repository(): void {
		$foreign = self::release(
			[
				'assets' => [
					[
						'name'                 => 'lab591-dev-bridge-0.6.0.zip',
						'browser_download_url' => 'https://github.com/someone-else/wpdev/releases/download/v0.6.0/lab591-dev-bridge-0.6.0.zip',
					],
				],
			]
		);
		$this->assertNull( Updater::toUpdate( $foreign, '0.5.0' ) );
		$this->assertNull( Updater::toUpdate( self::release( [ 'assets' => [] ] ), '0.5.0' ) );
	}
}
