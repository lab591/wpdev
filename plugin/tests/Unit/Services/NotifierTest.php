<?php
/**
 * Notifications: message content (metadata only) and destination validation.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Tests\Unit\Services;

use Lab591\DevBridge\Admin\SettingsForm;
use Lab591\DevBridge\Services\Notifier;
use PHPUnit\Framework\TestCase;

final class NotifierTest extends TestCase {

	private const CTX = [
		'site' => 'Shop',
		'url'  => 'https://shop.example/',
		'user' => 'claudio',
	];

	public function test_deploy_message_has_summary_paths_and_structured_payload(): void {
		$paths = [];
		for ( $i = 0; $i < 55; $i++ ) {
			$paths[] = "write wp-content/themes/child/f{$i}.php";
		}
		$m = Notifier::build(
			'deploy',
			[
				'release_id' => '20260923-101500-abc123',
				'status'     => 'ok',
				'written'    => 55,
				'deleted'    => 0,
				'health'     => [ 'status' => 'ok' ],
				'paths'      => $paths,
			],
			self::CTX
		);
		$this->assertSame( '[Shop] Deploy 20260923-101500-abc123 by claudio: 55 written, 0 deleted, health check ok.', $m['subject'] );
		$this->assertStringContainsString( 'write wp-content/themes/child/f49.php', $m['text'] );
		$this->assertStringNotContainsString( 'f50.php', $m['text'] );
		$this->assertStringContainsString( '… and 5 more', $m['text'] );
		$this->assertSame( 'deploy', $m['payload']['event'] );
		$this->assertCount( Notifier::MAX_PATHS, $m['payload']['files'] );
		$this->assertSame( $m['text'], $m['payload']['text'], 'Slack reads "text"' );
		$this->assertArrayHasKey( 'content', $m['payload'], 'Discord reads "content"' );
	}

	public function test_rolled_back_deploy_rollback_and_write_mode(): void {
		$rolled = Notifier::build(
			'deploy',
			[
				'release_id' => 'r1',
				'status'     => 'rolled_back',
			],
			self::CTX
		);
		$this->assertStringContainsString( 'rolled back automatically', $rolled['subject'] );
		$this->assertStringContainsString( 'r2, r1', Notifier::build( 'rollback', [ 'rolled_back' => [ 'r2', 'r1' ] ], self::CTX )['subject'] );
		$this->assertStringContainsString( 'Write mode enabled by claudio until 2026-09-23 12:00 UTC', Notifier::build( 'write', [ 'until' => '2026-09-23 12:00 UTC' ], self::CTX )['subject'] );
	}

	public function test_messages_never_contain_tokens_or_contents(): void {
		$m    = Notifier::build(
			'deploy',
			[
				'release_id'   => 'r1',
				'status'       => 'ok',
				'rescue_token' => str_repeat( 'a', 64 ),
				'content'      => '<?php secret();',
			],
			self::CTX
		);
		$json = (string) json_encode( $m );
		$this->assertStringNotContainsString( str_repeat( 'a', 64 ), $json );
		$this->assertStringNotContainsString( 'secret()', $json );
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function webhooks(): array {
		return [
			'slack https'       => [ 'https://hooks.slack.com/services/T0/B0/xyz', true ],
			'http public'       => [ 'http://hooks.example.com/x', false ],
			'http localhost'    => [ 'http://localhost:8080/hook', true ],
			'http .test'        => [ 'http://ci.test/hook', true ],
			'credentials'       => [ 'https://user:pass@hooks.example.com/x', false ],
			'ftp'               => [ 'ftp://hooks.example.com/x', false ],
			'not a url'         => [ 'hooks.example.com', false ],
			'javascript scheme' => [ 'javascript:alert(1)', false ],
		];
	}

	/**
	 * @dataProvider webhooks
	 */
	public function test_webhook_destinations( string $url, bool $ok ): void {
		$this->assertSame( $ok, SettingsForm::isWebhookUrl( $url ) );
	}
}
