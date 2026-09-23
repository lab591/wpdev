<?php
/**
 * Notifications (0.5.0): email and/or webhook when something changes on the site through
 * Dev Bridge — deploys (and automatic rollbacks), manual rollbacks, write mode enabled.
 *
 * Messages carry metadata only: site, user, release, outcome and file paths. Never file
 * contents, tokens or passwords (the rescue token is stripped before the event is fired).
 * Webhooks go through wp_safe_remote_post (no internal addresses) and do not block.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Services;

use Lab591\DevBridge\Settings;

final class Notifier {

	public const EVENTS = [ 'deploy', 'rollback', 'write' ];

	public const MAX_PATHS = 50;

	public function __construct( private readonly Settings $settings ) {
	}

	public function register(): void {
		add_action( 'devbridge_deployed', [ $this, 'onDeploy' ], 10, 3 );
		add_action( 'devbridge_rolled_back', [ $this, 'onRollback' ], 10, 2 );
		add_action( 'devbridge_mode_enabled', [ $this, 'onModeEnabled' ], 10, 1 );
	}

	/**
	 * @param array<string, mixed> $response Deploy response (without the rescue token).
	 * @param string[]             $paths    "write <path>" / "delete <path>".
	 */
	public function onDeploy( array $response, array $paths, int $userId ): void {
		if ( null === ( $response['release_id'] ?? null ) ) {
			return; // Nothing changed on the server.
		}
		$this->dispatch( 'deploy', self::build( 'deploy', $response + [ 'paths' => $paths ], $this->context( $userId ) ) );
	}

	/**
	 * @param list<string> $releases Rolled back release ids.
	 * @param string[]     $paths    Restored or removed paths.
	 */
	public function onRollback( array $releases, array $paths ): void {
		$this->dispatch(
			'rollback',
			self::build(
				'rollback',
				[
					'rolled_back' => $releases,
					'paths'       => $paths,
				],
				$this->context( get_current_user_id() )
			)
		);
	}

	/**
	 * @param array{mode: string, expires_at: int, user_id: int} $state
	 */
	public function onModeEnabled( array $state ): void {
		if ( 'write' !== $state['mode'] ) {
			return;
		}
		$this->dispatch(
			'write',
			self::build( 'write', [ 'until' => wp_date( 'Y-m-d H:i T', (int) $state['expires_at'] ) ], $this->context( (int) $state['user_id'] ) )
		);
	}

	/**
	 * Sends a test message with the saved settings and reports what happened (blocking).
	 *
	 * @return array{email: string, webhook: string}
	 */
	public function test( int $userId ): array {
		return $this->send( self::build( 'test', [], $this->context( $userId ) ), true );
	}

	/**
	 * @param array{subject: string, text: string, payload: array<string, mixed>} $message
	 */
	private function dispatch( string $event, array $message ): void {
		if ( in_array( $event, (array) $this->settings->get( 'notify_events' ), true ) ) {
			$this->send( $message, false );
		}
	}

	/**
	 * @param array{subject: string, text: string, payload: array<string, mixed>} $message
	 * @return array{email: string, webhook: string} "off", "sent" or a short error.
	 */
	private function send( array $message, bool $blocking ): array {
		$result = [
			'email'   => 'off',
			'webhook' => 'off',
		];
		$emails = array_values( array_filter( array_map( 'strval', (array) $this->settings->get( 'notify_emails' ) ) ) );
		if ( [] !== $emails ) {
			$result['email'] = wp_mail( $emails, $message['subject'], $message['text'] ) ? 'sent' : 'failed';
		}
		$url = (string) $this->settings->get( 'notify_webhook' );
		if ( '' !== $url ) {
			$args = [
				'timeout'  => $blocking ? 10 : 3,
				'blocking' => $blocking,
				'headers'  => [ 'Content-Type' => 'application/json' ],
				'body'     => (string) wp_json_encode( $message['payload'] ),
			];
			// Local development may target localhost; everywhere else internal addresses are refused.
			$response          = 'local' === wp_get_environment_type() ? wp_remote_post( $url, $args ) : wp_safe_remote_post( $url, $args );
			$result['webhook'] = is_wp_error( $response )
				? 'error: ' . $response->get_error_message()
				: ( $blocking ? 'HTTP ' . (int) wp_remote_retrieve_response_code( $response ) : 'sent' );
		}
		return $result;
	}

	/**
	 * @return array{site: string, url: string, user: string}
	 */
	private function context( int $userId ): array {
		$user = $userId > 0 ? get_userdata( $userId ) : false;
		return [
			'site' => (string) get_bloginfo( 'name' ),
			'url'  => (string) home_url( '/' ),
			'user' => $user ? (string) $user->user_login : ( $userId > 0 ? '#' . $userId : 'WP-CLI' ),
		];
	}

	/**
	 * Builds subject, plain text and webhook payload of a notification (pure, no I/O).
	 * The payload has `text` (Slack) and `content` (Discord) plus structured fields.
	 *
	 * @param array<string, mixed>                           $data
	 * @param array{site: string, url: string, user: string} $ctx
	 * @return array{subject: string, text: string, payload: array<string, mixed>}
	 */
	public static function build( string $event, array $data, array $ctx ): array {
		$site  = '' === $ctx['site'] ? $ctx['url'] : $ctx['site'];
		$paths = array_slice( array_map( 'strval', (array) ( $data['paths'] ?? [] ) ), 0, self::MAX_PATHS );
		switch ( $event ) {
			case 'deploy':
				$rolledBack = 'rolled_back' === ( $data['status'] ?? '' );
				$summary    = $rolledBack
					/* translators: 1: site name, 2: release id, 3: user. */
					? sprintf( __( '[%1$s] Deploy %2$s by %3$s rolled back automatically: the health check failed.', 'lab591-dev-bridge' ), $site, (string) ( $data['release_id'] ?? '' ), $ctx['user'] )
					/* translators: 1: site name, 2: release id, 3: user, 4: files written, 5: files deleted, 6: health status. */
					: sprintf( __( '[%1$s] Deploy %2$s by %3$s: %4$d written, %5$d deleted, health check %6$s.', 'lab591-dev-bridge' ), $site, (string) ( $data['release_id'] ?? '' ), $ctx['user'], (int) ( $data['written'] ?? 0 ), (int) ( $data['deleted'] ?? 0 ), (string) ( $data['health']['status'] ?? '?' ) );
				break;
			case 'rollback':
				/* translators: 1: site name, 2: user, 3: release ids. */
				$summary = sprintf( __( '[%1$s] Rollback by %2$s: %3$s.', 'lab591-dev-bridge' ), $site, $ctx['user'], implode( ', ', array_map( 'strval', (array) ( $data['rolled_back'] ?? [] ) ) ) );
				break;
			case 'write':
				/* translators: 1: site name, 2: user, 3: date and time. */
				$summary = sprintf( __( '[%1$s] Write mode enabled by %2$s until %3$s: Claude can publish changes.', 'lab591-dev-bridge' ), $site, $ctx['user'], (string) ( $data['until'] ?? '' ) );
				break;
			default:
				/* translators: %s: site name. */
				$summary = sprintf( __( '[%s] Dev Bridge test notification: notifications work.', 'lab591-dev-bridge' ), $site );
		}
		$text = $summary;
		if ( [] !== $paths ) {
			$text .= "\n\n" . implode( "\n", $paths );
			$more  = count( (array) ( $data['paths'] ?? [] ) ) - count( $paths );
			if ( $more > 0 ) {
				/* translators: %d: number of other files. */
				$text .= "\n" . sprintf( __( '… and %d more', 'lab591-dev-bridge' ), $more );
			}
		}
		$text   .= "\n\n" . $ctx['url'];
		$payload = [
			'text'    => $text,
			'content' => mb_substr( $text, 0, 1900 ),
			'event'   => $event,
			'site'    => $ctx['url'],
			'user'    => $ctx['user'],
			'release' => (string) ( $data['release_id'] ?? '' ),
			'status'  => (string) ( $data['status'] ?? '' ),
			'files'   => $paths,
			'time'    => gmdate( 'c' ),
		];
		return [
			'subject' => mb_substr( $summary, 0, 150 ),
			'text'    => $text,
			'payload' => $payload,
		];
	}
}
