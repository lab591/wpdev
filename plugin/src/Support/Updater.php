<?php
/**
 * Updates from GitHub Releases (0.5.0). The plugin header declares `Update URI:
 * https://github.com/lab591/wpdev`, so WordPress asks this class (filter
 * `update_plugins_github.com`) instead of wordpress.org.
 *
 * The latest release is read from the GitHub API (cached 12 hours, dropped by "Check again" on
 * Dashboard → Updates); an update is offered only
 * when its version is newer and it has the plugin zip attached as a release asset of the same
 * repository. WordPress then downloads and installs it as for any other plugin.
 * Disable with `define( 'DEVBRIDGE_DISABLE_UPDATES', true );`.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Support;

use const Lab591\DevBridge\PLUGIN_FILE;
use const Lab591\DevBridge\VERSION;

final class Updater {

	public const REPO = 'lab591/wpdev';

	public const CACHE_KEY = 'devbridge_latest_release';

	public const SLUG = 'lab591-dev-bridge';

	public function register(): void {
		if ( defined( 'DEVBRIDGE_DISABLE_UPDATES' ) && DEVBRIDGE_DISABLE_UPDATES ) {
			return;
		}
		add_filter( 'update_plugins_github.com', [ $this, 'check' ], 10, 3 );
		add_action( 'load-update-core.php', [ $this, 'onForceCheck' ] );
	}

	/**
	 * "Check again" on Dashboard → Updates also skips the 12-hour cache of the GitHub answer,
	 * so a release published a minute ago shows up immediately.
	 */
	public function onForceCheck(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: only drops a cache, like core does for the same flag.
		if ( isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) {
			Options::deleteTransient( self::CACHE_KEY );
		}
	}

	/**
	 * @param array<string, mixed>|false $update     Update data from other filters, or false.
	 * @param array<string, mixed>       $pluginData Plugin headers.
	 * @param string                     $pluginFile Plugin basename.
	 * @return array<string, mixed>|false
	 */
	public function check( $update, array $pluginData, string $pluginFile ) {
		if ( plugin_basename( PLUGIN_FILE ) !== $pluginFile ) {
			return $update;
		}
		$release = $this->latest();
		return null === $release ? $update : ( self::toUpdate( $release, VERSION ) ?? $update );
	}

	/**
	 * Latest release as returned by the GitHub API (cached), or null when unavailable.
	 *
	 * @return array<string, mixed>|null
	 */
	private function latest(): ?array {
		$cached = Options::getTransient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return [] === $cached ? null : $cached;
		}
		$response = wp_safe_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			[
				'timeout' => 10,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'lab591-dev-bridge/' . VERSION,
				],
			]
		);
		$data     = is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response )
			? null
			: json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data     = is_array( $data ) ? $data : null;
		// Failures are cached too (shorter), so a GitHub outage does not slow every admin page.
		Options::setTransient( self::CACHE_KEY, $data ?? [], null === $data ? HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Update data for WordPress from a GitHub release (pure): null when the release is not newer,
	 * has an invalid version or no plugin zip from this repository.
	 *
	 * @param array<string, mixed> $release GitHub API "release" object.
	 * @return array<string, mixed>|null
	 */
	public static function toUpdate( array $release, string $current ): ?array {
		$version = ltrim( (string) ( $release['tag_name'] ?? '' ), 'vV' );
		if ( 1 !== preg_match( '/^\d+\.\d+\.\d+(?:[-.][0-9A-Za-z.]+)?$/', $version ) || ! version_compare( $version, $current, '>' ) ) {
			return null;
		}
		if ( ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
			return null;
		}
		$prefix  = 'https://github.com/' . self::REPO . '/releases/download/';
		$package = null;
		foreach ( (array) ( $release['assets'] ?? [] ) as $asset ) {
			$name = (string) ( $asset['name'] ?? '' );
			$url  = (string) ( $asset['browser_download_url'] ?? '' );
			if ( 1 === preg_match( '/^lab591-dev-bridge-[0-9A-Za-z.\-]+\.zip$/', $name ) && str_starts_with( $url, $prefix ) ) {
				$package = $url;
				break;
			}
		}
		if ( null === $package ) {
			return null;
		}
		return [
			'id'           => 'github.com/' . self::REPO,
			'slug'         => self::SLUG,
			'version'      => $version,
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => $package,
			'requires'     => '6.6',
			'requires_php' => '8.1',
		];
	}
}
