<?php
/**
 * Private storage lifecycle (SPEC 2.10, 2.13).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Core;

use Lab591\DevBridge\Storage\Storage;
use Lab591\DevBridge\Tests\Support\FsFixture;
use PHPUnit\Framework\TestCase;

final class StorageTest extends TestCase {

	public function test_destroy_removes_everything_the_plugin_created(): void {
		$fx      = FsFixture::wordpress();
		$storage = new Storage( $fx->base . DIRECTORY_SEPARATOR . 'storage' );
		$storage->ensure();
		foreach ( [ 'rescue.json', 'deploy.lock', 'hash-cache.json', 'hash-cache.json.ab12.tmp', 'rescue.json.tmp' ] as $name ) {
			file_put_contents( $storage->dir() . DIRECTORY_SEPARATOR . $name, 'x' );
		}
		mkdir( $storage->releasesDir() . DIRECTORY_SEPARATOR . '20260923-101500-abcdef' . DIRECTORY_SEPARATOR . 'files', 0777, true );
		file_put_contents( $storage->tmpDir() . DIRECTORY_SEPARATOR . 'rg-1.json', 'x' );

		$storage->destroy();
		$gone = ! is_dir( $storage->dir() );
		$fx->cleanup();
		$this->assertTrue( $gone, 'Storage folder removed' );
	}

	public function test_destroy_keeps_foreign_files_in_a_shared_folder(): void {
		$fx      = FsFixture::wordpress();
		$storage = new Storage( $fx->base . DIRECTORY_SEPARATOR . 'shared' );
		$storage->ensure();
		file_put_contents( $storage->dir() . DIRECTORY_SEPARATOR . 'someone-else.txt', 'keep me' );

		$storage->destroy();
		$kept = is_file( $storage->dir() . DIRECTORY_SEPARATOR . 'someone-else.txt' );
		$fx->cleanup();
		$this->assertTrue( $kept );
	}
}
