<?php
/**
 * PathGuard tests (SPEC 2.5).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Security;

use Lab591\DevBridge\Security\Access;
use Lab591\DevBridge\Security\PathException;
use Lab591\DevBridge\Security\PathGuard;
use Lab591\DevBridge\Security\PathPolicy;
use Lab591\DevBridge\Tests\Support\FsFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathGuardTest extends TestCase {

	private FsFixture $fx;

	protected function setUp(): void {
		$this->fx = FsFixture::wordpress();
	}

	protected function tearDown(): void {
		$this->fx->cleanup();
	}

	private function guard( array $overrides = [] ): PathGuard {
		$args = array_merge(
			[
				'abspath'        => $this->fx->abspath,
				'read_roots'     => [ '' ],
				'writable_roots' => [ 'wp-content/themes/child', 'wp-content/plugins/myplug' ],
				'deny_patterns'  => PathPolicy::DEFAULT_DENY_PATTERNS,
				'write_exts'     => PathPolicy::DEFAULT_WRITE_EXTENSIONS,
				'protected'      => [
					$this->fx->abs( 'wp-content/plugins/lab591-dev-bridge' ),
					$this->fx->abs( 'wp-content/devbridge-abc123' ),
				],
			],
			$overrides
		);
		return new PathGuard(
			new PathPolicy(
				abspath: $args['abspath'],
				readRoots: $args['read_roots'],
				writableRoots: $args['writable_roots'],
				denyPatterns: $args['deny_patterns'],
				writeExtensions: $args['write_exts'],
				protectedPaths: $args['protected'],
			)
		);
	}

	private function assertRejected( string $path, Access $access, ?string $code = null, ?PathGuard $guard = null ): void {
		try {
			( $guard ?? $this->guard() )->resolve( $path, $access );
		} catch ( PathException $e ) {
			if ( null !== $code ) {
				$this->assertSame( $code, $e->errorCode(), 'Unexpected error code for ' . json_encode( $path ) );
			}
			$this->assertStringNotContainsString( $this->fx->base, $e->getMessage(), 'Error message leaks absolute path' );
			return;
		}
		$this->fail( 'Path was accepted: ' . json_encode( $path ) );
	}

	// ---------------------------------------------------------------- happy path

	public function test_resolves_regular_file_for_read(): void {
		$r = $this->guard()->resolve( 'wp-content/themes/parent/style.css', Access::Read );
		$this->assertSame( 'wp-content/themes/parent/style.css', $r->relative );
		$this->assertSame( realpath( $this->fx->abs( 'wp-content/themes/parent/style.css' ) ), $r->absolute );
		$this->assertTrue( $r->exists );
		$this->assertFalse( $r->isDir );
	}

	public function test_backslash_separators_are_normalized(): void {
		$r = $this->guard()->resolve( 'wp-content\\themes\\parent\\style.css', Access::Read );
		$this->assertSame( 'wp-content/themes/parent/style.css', $r->relative );
	}

	public function test_site_root_is_readable_as_dot(): void {
		$r = $this->guard()->resolve( '.', Access::Read );
		$this->assertSame( '', $r->relative );
		$this->assertTrue( $r->isDir );
	}

	public function test_trailing_slash_on_directory_is_accepted(): void {
		$r = $this->guard()->resolve( 'wp-content/themes/', Access::Read );
		$this->assertSame( 'wp-content/themes', $r->relative );
		$this->assertTrue( $r->isDir );
	}

	public function test_missing_file_is_not_found(): void {
		$this->assertRejected( 'wp-content/themes/nope.php', Access::Read, 'not_found' );
	}

	public function test_names_with_spaces_and_unicode(): void {
		$r = $this->guard()->resolve( 'wp-content/themes/child/fonts/caffè latte.txt', Access::Write );
		$this->assertSame( 'wp-content/themes/child/fonts/caffè latte.txt', $r->relative );

		$new = $this->guard()->resolve( 'wp-content/themes/child/nuova cartella/città ☕.css', Access::WriteNew );
		$this->assertSame( 'wp-content/themes/child/nuova cartella/città ☕.css', $new->relative );
		$this->assertFalse( $new->exists );
	}

	// ---------------------------------------------------------------- malformed input

	public static function malformedPaths(): array {
		return [
			'empty'                  => [ '' ],
			'null byte'              => [ "index.php\0.jpg" ],
			'null byte at end'       => [ "wp-content/themes/child/style.css\0" ],
			'control char'           => [ "wp-content/\x01themes" ],
			'DEL char'               => [ "wp-content/\x7fthemes" ],
			'newline'                => [ "index.php\n" ],
			'unix absolute'          => [ '/etc/passwd' ],
			'windows root absolute'  => [ '\\Windows\\win.ini' ],
			'windows drive'          => [ 'C:\\Windows\\win.ini' ],
			'windows drive slash'    => [ 'c:/Windows/win.ini' ],
			'windows drive relative' => [ 'C:secret.txt' ],
			'UNC'                    => [ '\\\\server\\share\\x.php' ],
			'file wrapper'           => [ 'file:///etc/passwd' ],
			'php wrapper'            => [ 'php://filter/resource=wp-config.php' ],
			'phar wrapper'           => [ 'phar://x.phar/a.php' ],
			'relative wrapper'       => [ 'wp-content/x://y' ],
			'alternate data stream'  => [ 'wp-content/themes/child/style.css::$DATA' ],
			'dot'                    => [ 'wp-content/./themes' ],
			'dot only segment end'   => [ 'wp-content/themes/.' ],
			'double slash'           => [ 'wp-content//themes' ],
			'trailing dot'           => [ 'wp-content/themes/child/style.css.' ],
			'trailing space'         => [ 'wp-content/themes/child/style.css ' ],
			'four dots'              => [ '....//outside/secret.txt' ],
			'four dots inner'        => [ 'wp-content/....//wp-config.php' ],
			'dos short name'         => [ 'WP-CON~1.PHP' ],
			'too long'               => [ str_repeat( 'a/', 600 ) . 'x.php' ],
		];
	}

	#[DataProvider( 'malformedPaths' )]
	public function test_malformed_paths_are_rejected( string $path ): void {
		$this->assertRejected( $path, Access::Read, 'path_invalid' );
		$this->assertRejected( $path, Access::WriteNew );
	}

	// ---------------------------------------------------------------- traversal

	public static function traversalPaths(): array {
		return [
			'parent'                   => [ '../outside/secret.txt' ],
			'nested parent'            => [ 'wp-content/../wp-config.php' ],
			'deep escape'              => [ 'wp-content/themes/child/../../../../outside/secret.txt' ],
			'backslash parent'         => [ '..\\outside\\secret.txt' ],
			'mixed separators'         => [ 'wp-content\\..\\..\\outside/secret.txt' ],
			'trailing parent'          => [ 'wp-content/themes/..' ],
			'parent from writable dir' => [ 'wp-content/themes/child/../parent/style.css' ],
		];
	}

	#[DataProvider( 'traversalPaths' )]
	public function test_dot_dot_segments_are_rejected( string $path ): void {
		$this->assertRejected( $path, Access::Read, 'path_invalid' );
		$this->assertRejected( $path, Access::WriteNew, 'path_invalid' );
	}

	public static function encodedTraversalPaths(): array {
		return [
			'url encoded'       => [ '%2e%2e/outside/secret.txt' ],
			'url encoded slash' => [ '..%2foutside%2fsecret.txt' ],
			'double encoded'    => [ '%252e%252e/outside/secret.txt' ],
			'overlong utf8 dot' => [ "\xc0\xae\xc0\xae/outside/secret.txt" ],
			'fullwidth dots'    => [ '．．/outside/secret.txt' ],
		];
	}

	/**
	 * Encoded sequences are never decoded: they are literal (non-existent) names,
	 * so the guard must never resolve them outside the site.
	 */
	#[DataProvider( 'encodedTraversalPaths' )]
	public function test_encoded_traversal_never_escapes( string $path ): void {
		try {
			$r = $this->guard()->resolve( $path, Access::Read );
			$this->fail( 'Encoded traversal resolved to ' . $r->relative );
		} catch ( PathException $e ) {
			$this->assertContains( $e->errorCode(), [ 'not_found', 'path_invalid' ] );
		}

		try {
			$r = $this->guard()->resolve( 'wp-content/themes/child/' . $path, Access::WriteNew );
			$this->assertStringStartsWith( 'wp-content/themes/child/', $r->relative );
			$this->assertStringStartsWith( realpath( $this->fx->abs( 'wp-content/themes/child' ) ) . DIRECTORY_SEPARATOR, $r->absolute );
		} catch ( PathException $e ) {
			$this->assertContains( $e->errorCode(), [ 'path_invalid', 'extension_denied' ] );
		}
	}

	public function test_invalid_utf8_is_rejected(): void {
		$this->assertRejected( "wp-content/\xff\xfe.css", Access::Read, 'path_invalid' );
	}

	// ---------------------------------------------------------------- deny list

	public static function deniedReads(): array {
		return [
			'wp-config'            => [ 'wp-config.php' ],
			'wp-config sample'     => [ 'wp-config-sample.php' ],
			'case variant upper'   => [ 'WP-CONFIG.PHP' ],
			'case variant mixed'   => [ 'Wp-Config.php' ],
			'env'                  => [ '.env' ],
			'git dir'              => [ '.git' ],
			'git config'           => [ '.git/config' ],
			'sql dump'             => [ 'dump.sql' ],
			'debug log'            => [ 'wp-content/debug.log' ],
			'uploads dir'          => [ 'wp-content/uploads' ],
			'uploads file'         => [ 'wp-content/uploads/2024/a.jpg' ],
			'storage dir'          => [ 'wp-content/devbridge-abc123' ],
			'storage file'         => [ 'wp-content/devbridge-abc123/rescue.json' ],
			'uploads case variant' => [ 'WP-CONTENT/Uploads/2024/a.jpg' ],
		];
	}

	#[DataProvider( 'deniedReads' )]
	public function test_deny_list_blocks_reads( string $path ): void {
		$this->assertRejected( $path, Access::Read, 'path_denied' );
	}

	public function test_deny_list_applies_to_new_files_too(): void {
		$this->assertRejected( 'wp-content/themes/child/.env.local', Access::WriteNew );
		$this->assertRejected( 'wp-content/themes/child/backup.sql', Access::WriteNew );
		$this->assertRejected( 'wp-content/themes/child/.git/config', Access::WriteNew );
		$this->assertRejected( 'wp-content/themes/child/keys/server.pem', Access::WriteNew );
	}

	public function test_protected_paths_are_denied_even_if_inside_roots(): void {
		$guard = $this->guard( [ 'writable_roots' => [ 'wp-content/plugins/lab591-dev-bridge' ] ] );
		$this->assertRejected( 'wp-content/plugins/lab591-dev-bridge/lab591-dev-bridge.php', Access::Write, 'path_denied', $guard );
		$this->assertRejected( 'wp-content/plugins/lab591-dev-bridge/lab591-dev-bridge.php', Access::Read, 'path_denied', $guard );
		$this->assertRejected( 'wp-content/plugins/lab591-dev-bridge/new.php', Access::WriteNew, 'path_denied', $guard );
	}

	public function test_empty_read_roots_deny_everything(): void {
		$this->assertRejected( 'index.php', Access::Read, 'path_denied', $this->guard( [ 'read_roots' => [] ] ) );
	}

	public function test_limited_read_root(): void {
		$guard = $this->guard( [ 'read_roots' => [ 'wp-content/themes' ] ] );
		$this->assertSame( 'wp-content/themes/parent/style.css', $guard->resolve( 'wp-content/themes/parent/style.css', Access::Read )->relative );
		$this->assertRejected( 'index.php', Access::Read, 'path_denied', $guard );
		$this->assertRejected( 'wp-content/themes-other', Access::Read, null, $guard );
	}

	public function test_root_prefix_must_be_followed_by_separator(): void {
		$this->fx->write( 'wp-content/themes/child-evil/x.php', "<?php\n" );
		$this->assertRejected( 'wp-content/themes/child-evil/x.php', Access::Write, 'path_denied' );
		$this->assertRejected( 'wp-content/themes/child-evil/new.php', Access::WriteNew, 'path_denied' );
	}

	// ---------------------------------------------------------------- write rules

	public function test_write_existing_file_inside_writable_root(): void {
		$r = $this->guard()->resolve( 'wp-content/themes/child/functions.php', Access::Write );
		$this->assertSame( 'wp-content/themes/child/functions.php', $r->relative );
		$this->assertTrue( $r->exists );
	}

	public function test_write_outside_writable_roots_is_denied(): void {
		$this->assertRejected( 'wp-content/themes/parent/style.css', Access::Write, 'path_denied' );
		$this->assertRejected( 'index.php', Access::Write, 'path_denied' );
		$this->assertRejected( 'wp-content/themes/new.php', Access::WriteNew, 'path_denied' );
	}

	public function test_writable_root_itself_is_not_a_write_target(): void {
		$this->assertRejected( 'wp-content/themes/child', Access::Write, 'path_denied' );
		$this->assertRejected( 'wp-content/themes/child', Access::WriteNew, 'path_denied' );
	}

	public function test_write_requires_existing_file_but_write_new_does_not(): void {
		$this->assertRejected( 'wp-content/themes/child/new.php', Access::Write, 'not_found' );
		$r = $this->guard()->resolve( 'wp-content/themes/child/a/b/c/new.php', Access::WriteNew );
		$this->assertSame( 'wp-content/themes/child/a/b/c/new.php', $r->relative );
		$this->assertSame(
			realpath( $this->fx->abs( 'wp-content/themes/child' ) ) . DIRECTORY_SEPARATOR . implode( DIRECTORY_SEPARATOR, [ 'a', 'b', 'c', 'new.php' ] ),
			$r->absolute
		);
		$this->assertFalse( $r->exists );
	}

	public function test_write_new_accepts_existing_file(): void {
		$r = $this->guard()->resolve( 'wp-content/themes/child/style.css', Access::WriteNew );
		$this->assertTrue( $r->exists );
	}

	public function test_write_new_below_a_file_is_rejected(): void {
		$this->assertRejected( 'wp-content/themes/child/style.css/x.css', Access::WriteNew, 'not_a_directory' );
	}

	public function test_write_to_directory_is_rejected(): void {
		$this->assertRejected( 'wp-content/themes/child/inc', Access::Write, 'not_a_file' );
		$this->assertRejected( 'wp-content/themes/child/inc', Access::WriteNew, 'not_a_file' );
	}

	public static function writeNames(): array {
		return [
			// name => allowed?
			'plain php'         => [ 'x.php', true ],
			'uppercase ext'     => [ 'X.PHP', true ],
			'css'               => [ 'assets/app.css', true ],
			'gitkeep'           => [ 'empty/.gitkeep', true ],
			'jpg then php'      => [ 'x.jpg.php', true ],
			'php then jpg'      => [ 'x.php.jpg', false ],
			'php then txt'      => [ 'x.PHP.txt', false ],
			'phtml'             => [ 'x.phtml', false ],
			'phtml mixed case'  => [ 'x.PhTmL', false ],
			'phar'              => [ 'x.phar', false ],
			'phar inner'        => [ 'x.phar.png', false ],
			'php3'              => [ 'x.php3', false ],
			'php5'              => [ 'x.php5', false ],
			'php8'              => [ 'x.php8', false ],
			'pht'               => [ 'x.pht', false ],
			'phps'              => [ 'x.phps', false ],
			'htaccess'          => [ '.htaccess', false ],
			'htaccess in sub'   => [ 'sub/.htaccess', false ],
			'user ini'          => [ '.user.ini', false ],
			'php ini'           => [ 'php.ini', false ],
			'php ini upper'     => [ 'PHP.INI', false ],
			'no extension'      => [ 'Makefile', false ],
			'hidden file'       => [ '.hidden.css', false ],
			'hidden directory'  => [ '.cache/app.css', false ],
			'not in allowlist'  => [ 'tool.exe', false ],
			'shell script'      => [ 'deploy.sh', false ],
			'trailing dot only' => [ 'x.', false ],
		];
	}

	#[DataProvider( 'writeNames' )]
	public function test_write_extension_rules( string $name, bool $allowed ): void {
		$path = 'wp-content/themes/child/' . $name;
		if ( $allowed ) {
			$r = $this->guard()->resolve( $path, Access::WriteNew );
			$this->assertSame( $path, $r->relative );
		} else {
			$this->assertRejected( $path, Access::WriteNew );
		}
	}

	public function test_extension_rules_do_not_apply_to_reads(): void {
		$this->fx->write( 'wp-content/themes/parent/README', 'x' );
		$this->assertSame( 'wp-content/themes/parent/README', $this->guard()->resolve( 'wp-content/themes/parent/README', Access::Read )->relative );
	}

	// ---------------------------------------------------------------- case sensitivity

	public function test_case_variant_resolves_to_canonical_case_on_case_insensitive_fs(): void {
		if ( ! PathPolicy::detectCaseInsensitive() ) {
			$this->assertRejected( 'WP-CONTENT/themes/parent/style.css', Access::Read, 'not_found' );
			return;
		}
		$r = $this->guard()->resolve( 'WP-CONTENT/Themes/Parent/STYLE.css', Access::Read );
		$this->assertSame( 'wp-content/themes/parent/style.css', $r->relative );
	}

	public function test_case_variant_of_writable_root_is_matched_case_insensitively(): void {
		if ( ! PathPolicy::detectCaseInsensitive() ) {
			$this->markTestSkipped( 'Case-sensitive filesystem.' );
		}
		$r = $this->guard()->resolve( 'wp-content/THEMES/Child/functions.php', Access::Write );
		$this->assertSame( 'wp-content/themes/child/functions.php', $r->relative );
		$this->assertRejected( 'wp-content/THEMES/Parent/style.css', Access::Write, 'path_denied' );
	}

	// ---------------------------------------------------------------- symlinks

	private function requireSymlink( string $target, string $rel ): void {
		if ( ! $this->fx->symlink( $target, $rel ) ) {
			$this->markTestSkipped( 'Symlinks are not supported on this system (Windows without developer mode?).' );
		}
	}

	public function test_symlink_pointing_outside_is_rejected(): void {
		$this->requireSymlink( $this->fx->outside . DIRECTORY_SEPARATOR . 'secret.txt', 'wp-content/themes/parent/link.txt' );
		$this->assertRejected( 'wp-content/themes/parent/link.txt', Access::Read, 'path_denied' );
	}

	public function test_directory_symlink_pointing_outside_is_rejected(): void {
		$this->requireSymlink( $this->fx->outside, 'wp-content/themes/child/ext' );
		$this->assertRejected( 'wp-content/themes/child/ext/secret.txt', Access::Read, 'path_denied' );
		$this->assertRejected( 'wp-content/themes/child/ext/new.php', Access::WriteNew, 'path_denied' );
	}

	public function test_symlink_to_denied_file_is_rejected(): void {
		$this->requireSymlink( $this->fx->abs( 'wp-config.php' ), 'wp-content/themes/parent/config.txt' );
		$this->assertRejected( 'wp-content/themes/parent/config.txt', Access::Read, 'path_denied' );
	}

	public function test_symlink_inside_writable_root_to_other_theme_is_rejected_for_write(): void {
		$this->requireSymlink( $this->fx->abs( 'wp-content/themes/parent' ), 'wp-content/themes/child/parent-link' );
		$this->assertRejected( 'wp-content/themes/child/parent-link/style.css', Access::Write, 'path_denied' );
		$this->assertRejected( 'wp-content/themes/child/parent-link/new.css', Access::WriteNew, 'path_denied' );
	}

	public function test_write_target_that_is_a_symlink_is_rejected(): void {
		$this->requireSymlink( $this->fx->abs( 'wp-content/themes/child/functions.php' ), 'wp-content/themes/child/alias.php' );
		$this->assertRejected( 'wp-content/themes/child/alias.php', Access::Write, 'path_denied' );
		$this->assertRejected( 'wp-content/themes/child/alias.php', Access::WriteNew, 'path_denied' );
	}

	public function test_dangling_symlink_is_rejected_for_write_new(): void {
		$this->requireSymlink( $this->fx->outside . DIRECTORY_SEPARATOR . 'missing.php', 'wp-content/themes/child/dangling.php' );
		$this->assertRejected( 'wp-content/themes/child/dangling.php', Access::WriteNew, 'path_denied' );
	}

	public function test_writable_root_that_is_a_symlink_to_outside_is_ignored(): void {
		$this->requireSymlink( $this->fx->outside, 'wp-content/themes/evil' );
		$guard = $this->guard( [ 'writable_roots' => [ 'wp-content/themes/evil' ] ] );
		$this->assertRejected( 'wp-content/themes/evil/secret.txt', Access::Write, 'path_denied', $guard );
	}
}
