<?php
/**
 * Folder picker for writable roots: lists only what the validator accepts.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Core;

use Lab591\DevBridge\Admin\FolderPicker;
use Lab591\DevBridge\Security\PathException;
use Lab591\DevBridge\Security\WritableRootValidator;
use Lab591\DevBridge\Tests\Support\FsFixture;
use PHPUnit\Framework\TestCase;

final class FolderPickerTest extends TestCase {

	private FsFixture $fx;

	protected function setUp(): void {
		$this->fx = FsFixture::wordpress();
		$this->fx->write( 'wp-content/themes/index.php', "<?php\n" );
		$this->fx->write( 'wp-content/themes/.hidden/x.css', '' );
		$this->fx->write( 'wp-content/mu-plugins/tool/tool.php', "<?php\n" );
	}

	protected function tearDown(): void {
		$this->fx->cleanup();
	}

	private function picker( bool $mu = false ): FolderPicker {
		return new FolderPicker(
			new WritableRootValidator(
				$this->fx->abspath,
				[ $this->fx->abs( 'wp-content/plugins/lab591-dev-bridge' ), $this->fx->abs( 'wp-content/devbridge-abc123' ) ],
				$mu
			),
			[ 'wp-content/plugins/lab591-dev-bridge' ]
		);
	}

	/**
	 * @param array{items: list<array<string, mixed>>, truncated: bool} $listing
	 * @return array<string, array<string, mixed>>
	 */
	private static function byName( array $listing ): array {
		return array_column( $listing['items'], null, 'name' );
	}

	public function test_lists_theme_folders_only_without_hidden_entries(): void {
		$items = self::byName( $this->picker()->children( 'wp-content/themes' ) );
		$this->assertSame( [ 'child', 'parent' ], array_keys( $items ) );
		$this->assertSame( 'wp-content/themes/child', $items['child']['path'] );
		$this->assertTrue( $items['child']['selectable'] );
		$this->assertTrue( $items['child']['expandable'], 'child has inc/ and fonts/' );
		$this->assertFalse( $items['parent']['expandable'] );
	}

	public function test_dev_bridge_folder_is_listed_but_not_selectable(): void {
		$items = self::byName( $this->picker()->children( 'wp-content/plugins' ) );
		$this->assertTrue( $items['myplug']['selectable'] );
		$this->assertFalse( $items['lab591-dev-bridge']['selectable'] );
		$this->assertSame( 'contains Dev Bridge files', $items['lab591-dev-bridge']['reason'] );
	}

	public function test_expands_subfolders_of_a_selectable_folder(): void {
		$items = self::byName( $this->picker()->children( 'wp-content/themes/child' ) );
		$this->assertSame( [ 'fonts', 'inc' ], array_keys( $items ) );
		$this->assertSame( 'wp-content/themes/child/inc', $items['inc']['path'] );
	}

	public function test_mu_plugins_only_when_enabled(): void {
		$this->assertNotContains( 'wp-content/mu-plugins', $this->picker()->containers() );
		$this->assertContains( 'wp-content/mu-plugins', $this->picker( true )->containers() );
		$this->assertSame( [ 'tool' ], array_keys( self::byName( $this->picker( true )->children( 'wp-content/mu-plugins' ) ) ) );
		$this->expectException( PathException::class );
		$this->picker()->children( 'wp-content/mu-plugins' );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function forbiddenParents(): array {
		return [
			'uploads'          => [ 'wp-content/uploads' ],
			'wp-content'       => [ 'wp-content' ],
			'site root'        => [ '' ],
			'traversal'        => [ 'wp-content/themes/../../' ],
			'absolute'         => [ '/etc' ],
			'dev bridge'       => [ 'wp-content/plugins/lab591-dev-bridge' ],
			'storage'          => [ 'wp-content/devbridge-abc123' ],
			'missing'          => [ 'wp-content/themes/nope' ],
			'file'             => [ 'wp-content/themes/child/functions.php' ],
			'container prefix' => [ 'wp-content/themes/' ],
		];
	}

	/**
	 * @dataProvider forbiddenParents
	 */
	public function test_refuses_to_list_anything_else( string $path ): void {
		$this->expectException( PathException::class );
		$this->picker()->children( $path );
	}
}
