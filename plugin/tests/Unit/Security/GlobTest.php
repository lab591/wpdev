<?php
/**
 * Glob matcher tests.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Security;

use Lab591\DevBridge\Security\Glob;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GlobTest extends TestCase {

	public static function cases(): array {
		return [
			[ 'wp-config.php', 'wp-config.php', true ],
			[ 'wp-config.php', 'sub/wp-config.php', true ],
			[ 'wp-config.php', 'wp-config.php.bak', false ],
			[ 'wp-config-*.php', 'wp-config-sample.php', true ],
			[ '**/.env*', '.env', true ],
			[ '**/.env*', 'a/b/.env.local', true ],
			[ '**/.git/**', '.git', true ],
			[ '**/.git/**', 'a/.git/HEAD', true ],
			[ '**/.git/**', 'a/.gitignore', false ],
			[ '**/*.sql', 'x.sql', true ],
			[ '**/*.sql', 'a/b/x.SQL', true ],
			[ '**/*.sql', 'a/b/x.sql.gz', false ],
			[ 'wp-content/uploads/**', 'wp-content/uploads', true ],
			[ 'wp-content/uploads/**', 'wp-content/uploads/2024/a.jpg', true ],
			[ 'wp-content/uploads/**', 'wp-content/uploads-old/a.jpg', false ],
			[ 'wp-content/devbridge-*/**', 'wp-content/devbridge-x1/rescue.json', true ],
			[ '*.php', 'a/b/c.php', true ],
			[ 'inc/*.php', 'inc/a.php', true ],
			[ 'inc/*.php', 'inc/sub/a.php', false ],
			[ 'inc/**/*.php', 'inc/a.php', true ],
			[ 'inc/**/*.php', 'inc/sub/deep/a.php', true ],
			[ 'file?.txt', 'file1.txt', true ],
			[ 'file?.txt', 'file10.txt', false ],
			[ '**/node_modules/**', 'wp-content/themes/x/node_modules/a/b.js', true ],
			[ 'a+b(c).txt', 'a+b(c).txt', true ],
			[ '#x.txt', '#x.txt', true ],
		];
	}

	#[DataProvider( 'cases' )]
	public function test_match( string $pattern, string $path, bool $expected ): void {
		$this->assertSame( $expected, Glob::match( $pattern, $path ), "$pattern vs $path" );
	}

	public function test_case_sensitive_mode(): void {
		$this->assertFalse( Glob::match( '*.PHP', 'a.php', false ) );
		$this->assertTrue( Glob::match( '*.PHP', 'a.php', true ) );
	}

	public function test_ancestors(): void {
		$this->assertTrue( Glob::matchesAnyWithAncestors( [ 'secret.d' ], 'x/secret.d/file.txt' ) );
		$this->assertFalse( Glob::matchesAnyWithAncestors( [ 'secret.d' ], 'x/other/file.txt' ) );
	}
}
