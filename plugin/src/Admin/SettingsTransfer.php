<?php
/**
 * Export and import of the settings as JSON (0.7.1). Pure logic, reusable from WP-CLI.
 *
 * The export holds only the settings (`devbridge_settings`): never the development mode, the page
 * password, releases or the audit log. An import goes through SettingsForm like a manual save.
 *
 * User IDs differ between sites: an export also lists the logins of the allowed users, and an
 * import from another site maps them by login (see resolveUsers()).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Admin;

use Lab591\DevBridge\Settings;

final class SettingsTransfer {

	public const FORMAT = 'lab591-dev-bridge-settings';

	/** Largest JSON accepted on import. */
	public const MAX_BYTES = 262144;

	/**
	 * Export document: format marker, plugin version, origin site, allowed users' logins and the settings.
	 *
	 * @param array<string, mixed> $settings Settings in the interface shape (AdminController::uiSettings()).
	 * @param string[]             $logins   Logins of the allowed users.
	 * @return array<string, mixed>
	 */
	public static function export( array $settings, array $logins, string $version, string $site, int $time ): array {
		return [
			'format'        => self::FORMAT,
			'version'       => $version,
			'site'          => $site,
			'exported'      => gmdate( 'Y-m-d\TH:i:s\Z', $time ),
			'allowed_users' => array_values( $logins ),
			'settings'      => array_intersect_key( $settings, Settings::defaults() ),
		];
	}

	/**
	 * Decodes an export (or a bare settings object) into form input. Unknown keys and values of the
	 * wrong type are dropped and listed in `ignored`.
	 *
	 * @return array{settings: array<string, mixed>, ignored: string[], site: string, users: string[]|null}
	 * @throws \InvalidArgumentException With a message for the user when the text is not a valid export.
	 */
	public static function parse( string $json ): array {
		$json = trim( $json );
		if ( '' === $json ) {
			throw new \InvalidArgumentException( __( 'Paste the exported settings first.', 'lab591-dev-bridge' ) );
		}
		if ( strlen( $json ) > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( __( 'The text is too long to be a Dev Bridge settings export.', 'lab591-dev-bridge' ) );
		}
		$data = json_decode( $json, true, 8 );
		if ( ! is_array( $data ) || array_is_list( $data ) ) {
			throw new \InvalidArgumentException( __( 'The text is not valid JSON: paste the whole export, from { to }.', 'lab591-dev-bridge' ) );
		}
		$site  = '';
		$users = null;
		if ( isset( $data['format'] ) || isset( $data['settings'] ) ) {
			if ( ( $data['format'] ?? '' ) !== self::FORMAT || ! is_array( $data['settings'] ?? null ) || array_is_list( $data['settings'] ) ) {
				throw new \InvalidArgumentException( __( 'This is not a Dev Bridge settings export.', 'lab591-dev-bridge' ) );
			}
			$site = is_string( $data['site'] ?? null ) ? $data['site'] : '';
			if ( is_array( $data['allowed_users'] ?? null ) ) {
				$users = array_values( array_filter( $data['allowed_users'], 'is_string' ) );
			}
			$data = $data['settings'];
		}

		$defaults = Settings::defaults();
		$settings = [];
		$ignored  = [];
		foreach ( $data as $key => $value ) {
			$key = (string) $key;
			if ( 'invalid_roots' === $key ) {
				continue; // Computed by the interface, not a setting.
			}
			if ( ! array_key_exists( $key, $defaults ) || ! self::sameShape( $defaults[ $key ], $value ) ) {
				$ignored[] = $key;
				continue;
			}
			$settings[ $key ] = $value;
		}
		if ( [] === $settings ) {
			throw new \InvalidArgumentException( __( 'No Dev Bridge setting found in the text.', 'lab591-dev-bridge' ) );
		}
		return [
			'settings' => $settings,
			'ignored'  => $ignored,
			'site'     => $site,
			'users'    => $users,
		];
	}

	/**
	 * Allowed users of an import. IDs are kept only when the export comes from this same site;
	 * otherwise users are matched by login (`$findId` returns 0 for unknown logins).
	 *
	 * @param array{settings: array<string, mixed>, site: string, users: string[]|null} $import  Result of parse().
	 * @param callable(string): int                                                     $findId  Login to user ID.
	 * @return array{ids: int[]|null, missing: string[]} `ids` null when the import does not set users.
	 */
	public static function resolveUsers( array $import, string $currentSite, callable $findId ): array {
		$sameSite = '' !== $import['site'] && self::normalizeUrl( $import['site'] ) === self::normalizeUrl( $currentSite );
		if ( $sameSite && array_key_exists( 'allowed_user_ids', $import['settings'] ) ) {
			return [
				'ids'     => array_values( array_map( 'intval', (array) $import['settings']['allowed_user_ids'] ) ),
				'missing' => [],
			];
		}
		if ( null === $import['users'] ) {
			// No logins (bare object, or IDs from another site): IDs alone could be other people here.
			return [
				'ids'     => array_key_exists( 'allowed_user_ids', $import['settings'] ) ? [] : null,
				'missing' => [],
			];
		}
		$ids     = [];
		$missing = [];
		foreach ( $import['users'] as $login ) {
			$id = $findId( $login );
			if ( $id > 0 ) {
				$ids[] = $id;
			} else {
				$missing[] = $login;
			}
		}
		return [
			'ids'     => $ids,
			'missing' => $missing,
		];
	}

	/**
	 * Whether an imported value has the type of the default (lists of scalars, the limits map, scalars).
	 */
	private static function sameShape( mixed $fallback, mixed $value ): bool {
		if ( is_bool( $fallback ) ) {
			return is_bool( $value );
		}
		if ( is_int( $fallback ) ) {
			return is_int( $value ) || ( is_string( $value ) && is_numeric( $value ) );
		}
		if ( is_string( $fallback ) ) {
			return is_string( $value );
		}
		if ( ! is_array( $value ) ) {
			return false;
		}
		$list = array_is_list( $fallback ) || [] === $fallback;
		if ( $list !== array_is_list( $value ) && [] !== $value ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) ) {
				return false;
			}
		}
		return true;
	}

	private static function normalizeUrl( string $url ): string {
		return strtolower( rtrim( (string) preg_replace( '#^https?://#i', '', trim( $url ) ), '/' ) );
	}
}
