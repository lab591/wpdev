<?php
/**
 * Health check: fatal errors detection in debug.log (SPEC 2.8).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Services;

use Lab591\DevBridge\Services\HealthService;
use Lab591\DevBridge\Tests\Support\FsFixture;
use PHPUnit\Framework\TestCase;

final class HealthServiceTest extends TestCase {

	public function test_first_fatal_ever_is_reported_even_if_the_log_did_not_exist(): void {
		$fx  = FsFixture::wordpress();
		$log = $fx->abs( 'wp-content/new-debug.log' );
		$svc = new HealthService( [], $log, $fx->abspath );

		$offset = $svc->logOffset();
		$this->assertSame( 0, $offset );
		file_put_contents( $log, '[23-Sep-2026 10:00:00 UTC] PHP Fatal error:  Uncaught Error in ' . $fx->abs( 'wp-content/plugins/x/x.php' ) . ":3\n#0 {main}\n" );

		$result = $svc->check( $offset );
		$fx->cleanup();
		$this->assertSame( 'fail', $result['status'] );
		$this->assertCount( 1, $result['errors'] );
		$this->assertStringNotContainsString( $fx->abspath, $result['errors'][0], 'Paths are relative to the site' );
	}

	public function test_only_lines_after_the_offset_count(): void {
		$fx  = FsFixture::wordpress();
		$log = $fx->write( 'wp-content/debug.log', "[23-Sep-2026 09:00:00 UTC] PHP Fatal error: old\n" );
		$svc = new HealthService( [], $log );

		$offset = $svc->logOffset();
		file_put_contents( $log, "[23-Sep-2026 10:00:00 UTC] PHP Warning: harmless\n", FILE_APPEND );
		$this->assertSame( 'ok', $svc->check( $offset )['status'] );

		file_put_contents( $log, "[23-Sep-2026 10:00:01 UTC] PHP Parse error: new\n", FILE_APPEND );
		$result = $svc->check( $offset );
		$fx->cleanup();
		$this->assertSame( [ '[23-Sep-2026 10:00:01 UTC] PHP Parse error: new' ], $result['errors'] );
	}

	public function test_new_warnings_are_collected_once_without_timestamp(): void {
		$fx  = FsFixture::wordpress();
		$log = $fx->write(
			'wp-content/debug.log',
			'[23-Sep-2026 09:00:00 UTC] PHP Warning:  old one
'
		);
		$svc = new HealthService( [], $log, $fx->abspath );

		$offset = $svc->logOffset();
		$file   = $fx->abs( 'wp-content/themes/child/functions.php' );
		file_put_contents(
			$log,
			"[23-Sep-2026 10:00:00 UTC] PHP Warning:  Undefined variable \$a in {$file} on line 3
" .
			"[23-Sep-2026 10:00:01 UTC] PHP Warning:  Undefined variable \$a in {$file} on line 3
" .
			"[23-Sep-2026 10:00:02 UTC] PHP Deprecated:  Old API in {$file} on line 9
" .
			'[23-Sep-2026 10:00:03 UTC] Some unrelated line
',
			FILE_APPEND
		);
		$result = $svc->check( $offset );
		$fx->cleanup();
		$this->assertSame( 'ok', $result['status'], 'Warnings are not failures' );
		$this->assertCount( 2, $result['warnings'] );
		$this->assertStringStartsWith( 'PHP Warning:  Undefined variable $a in wp-content', $result['warnings'][0] );
		$this->assertStringNotContainsString(
			$fx->abspath,
			implode(
				'
',
				$result['warnings']
			)
		);
	}
}
