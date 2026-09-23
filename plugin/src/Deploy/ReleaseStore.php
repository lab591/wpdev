<?php
/**
 * Releases: backups of the files touched by each deploy plus `release.json` (SPEC 2.7 step 5).
 *
 * Layout: `<releases>/<id>/release.json` and `<releases>/<id>/files/<relative path>`.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Deploy;

final class ReleaseStore {

	public const ID_PATTERN = '/^\d{8}-\d{6}-[0-9a-f]{6}$/';

	public const STATUS_PENDING     = 'pending';
	public const STATUS_OK          = 'ok';
	public const STATUS_UNKNOWN     = 'health_unknown';
	public const STATUS_ROLLED_BACK = 'rolled_back';
	public const STATUS_RESCUED     = 'rescued';
	public const STATUS_FAILED      = 'failed';

	/** Statuses whose changes are still live on the site. */
	public const ACTIVE = [ self::STATUS_OK, self::STATUS_UNKNOWN ];

	public function __construct( private readonly string $dir ) {
	}

	public static function newId(): string {
		return gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 3 ) );
	}

	public function releaseDir( string $id ): string {
		return $this->dir . DIRECTORY_SEPARATOR . $id;
	}

	public function backupPath( string $id, string $relative ): string {
		return $this->releaseDir( $id ) . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
	}

	/**
	 * Backs up every existing target of the plan and writes a pending release.json.
	 *
	 * @return array<string, mixed> The release record.
	 */
	public function create( string $id, int $userId, DeployPlan $plan ): array {
		$ops = [];
		foreach ( $plan->ops as $op ) {
			if ( $op->existed ) {
				$backup = $this->backupPath( $id, $op->target->relative );
				$dir    = dirname( $backup );
				if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
					throw new \RuntimeException( 'backup folder could not be created' );
				}
				if ( ! copy( $op->target->absolute, $backup ) ) {
					throw new \RuntimeException( 'backup failed' );
				}
			}
			$ops[] = [
				'p'       => $op->target->relative,
				'action'  => $op->action,
				'existed' => $op->existed,
				'prev_h'  => $op->prevHash,
				'new_h'   => 'write' === $op->action ? $op->newHash : null,
			];
		}
		$release = [
			'id'         => $id,
			'created_at' => time(),
			'user_id'    => $userId,
			'status'     => self::STATUS_PENDING,
			'written'    => $plan->count( 'write' ),
			'deleted'    => $plan->count( 'delete' ),
			'ops'        => $ops,
		];
		$this->save( $release );
		return $release;
	}

	/**
	 * @param array<string, mixed> $release
	 */
	public function save( array $release ): void {
		$dir = $this->releaseDir( (string) $release['id'] );
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
			throw new \RuntimeException( 'release folder could not be created' );
		}
		$tmp = $dir . DIRECTORY_SEPARATOR . 'release.json.tmp';
		file_put_contents( $tmp, (string) json_encode( $release, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		rename( $tmp, $dir . DIRECTORY_SEPARATOR . 'release.json' );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function load( string $id ): ?array {
		if ( 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			return null;
		}
		$file = $this->releaseDir( $id ) . DIRECTORY_SEPARATOR . 'release.json';
		if ( ! is_file( $file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $data ) && ( $data['id'] ?? null ) === $id ? $data : null;
	}

	/**
	 * All releases, newest first.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function all(): array {
		if ( ! is_dir( $this->dir ) ) {
			return [];
		}
		$ids = array_filter(
			(array) scandir( $this->dir ),
			static fn ( $name ): bool => is_string( $name ) && 1 === preg_match( self::ID_PATTERN, $name )
		);
		rsort( $ids, SORT_STRING );
		$out = [];
		foreach ( $ids as $id ) {
			$release = $this->load( $id );
			if ( null !== $release ) {
				$out[] = $release;
			}
		}
		return $out;
	}

	/**
	 * Keeps the newest $keep releases and deletes the others.
	 */
	public function rotate( int $keep ): void {
		foreach ( array_slice( $this->all(), max( 1, $keep ) ) as $release ) {
			$dir = $this->releaseDir( (string) $release['id'] );
			if ( is_dir( $dir ) ) {
				\Lab591\DevBridge\Storage\Storage::removeTree( $dir );
			}
		}
	}
}
