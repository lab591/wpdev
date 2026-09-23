<?php
/**
 * Rescue token lifecycle (SPEC 2.9): only sha256(token) is stored, in `rescue.json`.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Rescue;

final class RescueTokens {

	public const TTL = 86400;

	public function __construct( private readonly string $file ) {
	}

	/**
	 * Issues a new single-use token for the release, replacing any previous one.
	 *
	 * @param string[] $ipAllowlist
	 * @param string[] $trustedProxies
	 * @return string The token (64 hex chars): returned to the client, never stored or logged.
	 */
	public function issue( string $releaseId, array $ipAllowlist, array $trustedProxies, bool $allowHttp ): string {
		$token = bin2hex( random_bytes( 32 ) );
		$data  = [
			'v'               => 1,
			'token_sha256'    => hash( 'sha256', $token ),
			'release_id'      => $releaseId,
			'created_at'      => time(),
			'expires_at'      => time() + self::TTL,
			'ip_allowlist'    => array_values( $ipAllowlist ),
			'trusted_proxies' => array_values( $trustedProxies ),
			'allow_http'      => $allowHttp,
		];
		$tmp   = $this->file . '.tmp';
		file_put_contents( $tmp, (string) json_encode( $data, JSON_UNESCAPED_SLASHES ) );
		chmod( $tmp, 0600 );
		rename( $tmp, $this->file );
		return $token;
	}

	public function revoke(): void {
		if ( is_file( $this->file ) ) {
			unlink( $this->file );
		}
	}

	/**
	 * Release covered by the active token, or null.
	 */
	public function activeRelease(): ?string {
		if ( ! is_file( $this->file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $this->file ), true );
		if ( ! is_array( $data ) || (int) ( $data['expires_at'] ?? 0 ) < time() ) {
			return null;
		}
		return is_string( $data['release_id'] ?? null ) ? $data['release_id'] : null;
	}
}
