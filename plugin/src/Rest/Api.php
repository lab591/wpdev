<?php
/**
 * REST routes `devbridge/v1` (SPEC 2.6): thin layer over Services.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Rest;

use Lab591\DevBridge\Deploy\HealthPaths;
use Lab591\DevBridge\Deploy\Manifest;
use Lab591\DevBridge\Deploy\ReleaseStore;
use Lab591\DevBridge\Mode;
use Lab591\DevBridge\Plugin;
use Lab591\DevBridge\Services\ArchiveService;
use Lab591\DevBridge\Services\CacheFlushService;
use Lab591\DevBridge\Services\GrepService;
use Lab591\DevBridge\Services\ListService;
use Lab591\DevBridge\Services\LogService;
use Lab591\DevBridge\Services\ManifestService;
use Lab591\DevBridge\Services\ReadService;
use Lab591\DevBridge\Services\StatusService;
use Lab591\DevBridge\Support\ApiException;

final class Api {

	public const NAMESPACE = 'devbridge/v1';

	/** WordPress core error codes mapped to the stable codes of docs/protocol.md. */
	private const CODE_MAP = [
		'rest_invalid_param'             => 'invalid_param',
		'rest_missing_callback_param'    => 'invalid_param',
		'rest_invalid_json'              => 'invalid_param',
		'incorrect_password'             => 'invalid_credentials',
		'invalid_username'               => 'invalid_credentials',
		'invalid_email'                  => 'invalid_credentials',
		'application_passwords_disabled' => 'invalid_credentials',
	];

	/**
	 * Required mode and rate limit bucket per route. The gate runs in `rest_pre_dispatch`,
	 * before WordPress validates parameters, so that unauthenticated callers never get past it.
	 */
	private const GATES = [
		'/status'      => [ Mode::READ, 'read' ],
		'/list'        => [ Mode::READ, 'read' ],
		'/read'        => [ Mode::READ, 'read' ],
		'/grep'        => [ Mode::READ, 'read' ],
		'/manifest'    => [ Mode::READ, 'read' ],
		'/archive'     => [ Mode::READ, 'read' ],
		'/log'         => [ Mode::READ, 'read' ],
		'/health'      => [ Mode::READ, 'read' ],
		'/deploy'      => [ Mode::WRITE, 'write' ],
		'/rollback'    => [ Mode::WRITE, 'write' ],
		'/releases'    => [ Mode::WRITE, 'read' ],
		'/cache-flush' => [ Mode::WRITE, 'read' ],
	];

	private Gate $gate;
	private float $started      = 0.0;
	private bool $auditable     = false;
	private int $auditBytes     = 0;
	private string $releaseId   = '';
	private ?string $streamFile = null;
	/** @var string[]|null Paths recorded in the audit log instead of the request ones. */
	private ?array $auditPaths = null;
	/**
	 * Gate decision for the request being dispatched, keyed by spl_object_id().
	 *
	 * @var array<int, bool|\WP_Error>
	 */
	private array $decisions = [];

	public function __construct( private readonly Plugin $plugin ) {
		$this->gate = new Gate( $plugin );
	}

	public function register(): void {
		$path = [
			'type'      => 'string',
			'required'  => true,
			'minLength' => 1,
			'maxLength' => 1024,
		];
		$read = [ $this, 'permission' ];

		$this->route( '/status', 'GET', [ $this, 'status' ], $read, [] );
		$this->route(
			'/list',
			'POST',
			[ $this, 'list' ],
			$read,
			[
				'path'        => $path,
				'depth'       => self::int( 1, ListService::MAX_DEPTH, 1 ),
				'max_entries' => self::int( 1, ListService::MAX_ENTRIES, ListService::MAX_ENTRIES ),
			]
		);
		$this->route(
			'/read',
			'POST',
			[ $this, 'read' ],
			$read,
			[
				'path'  => $path,
				'from'  => self::int( 1, PHP_INT_MAX ),
				'to'    => self::int( 1, PHP_INT_MAX ),
				'known' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => [ 's', 'm', 'h' ],
					'properties'           => [
						's' => [
							'type'    => 'integer',
							'minimum' => 0,
						],
						'm' => [
							'type'    => 'integer',
							'minimum' => 0,
						],
						'h' => [
							'type'    => 'string',
							'pattern' => '^[0-9a-f]{32}$',
						],
					],
				],
			]
		);
		$this->route(
			'/grep',
			'POST',
			[ $this, 'grep' ],
			$read,
			[
				'pattern'        => [
					'type'      => 'string',
					'required'  => true,
					'minLength' => 1,
					'maxLength' => 1000,
				],
				'regex'          => self::bool(),
				'case_sensitive' => self::bool(),
				'path'           => $path,
				'glob'           => [
					'type'      => 'string',
					'maxLength' => 200,
				],
				'max_results'    => self::int( 1, 200, 100 ),
				'context'        => self::int( 0, GrepService::MAX_CONTEXT, 1 ),
				'time_budget_ms' => self::int( 100, 60000, 5000 ),
			]
		);
		$this->route(
			'/manifest',
			'POST',
			[ $this, 'manifest' ],
			$read,
			[
				'root'    => $path,
				'exclude' => self::strings( 100, 200 ),
			]
		);
		$this->route(
			'/archive',
			'POST',
			[ $this, 'archive' ],
			$read,
			[
				'paths'   => self::strings( 500, 1024 ),
				'root'    => array_merge( $path, [ 'required' => false ] ),
				'exclude' => self::strings( 100, 200 ),
			]
		);
		$this->route(
			'/log',
			'GET',
			[ $this, 'log' ],
			$read,
			[
				'lines' => self::int( 1, LogService::MAX_LINES, 200 ),
				'since' => self::int( 0, PHP_INT_MAX ),
			]
		);

		$this->route(
			'/health',
			'POST',
			[ $this, 'health' ],
			$read,
			[
				'paths' => [
					'type'     => 'array',
					'maxItems' => \Lab591\DevBridge\Deploy\HealthPaths::MAX_PATHS,
					'items'    => [
						'type'      => 'string',
						'maxLength' => \Lab591\DevBridge\Deploy\HealthPaths::MAX_LENGTH,
					],
				],
			]
		);
		$this->route(
			'/deploy',
			'POST',
			[ $this, 'deploy' ],
			$read,
			[
				'manifest' => [
					'type'      => 'string',
					'maxLength' => 4194304,
				],
			]
		);
		$this->route(
			'/rollback',
			'POST',
			[ $this, 'rollback' ],
			$read,
			[
				'release_id' => [
					'type'    => 'string',
					'pattern' => '^\d{8}-\d{6}-[0-9a-f]{6}$',
				],
				'force'      => self::bool(),
			]
		);
		$this->route( '/releases', 'GET', [ $this, 'releases' ], $read, [] );
		$this->route(
			'/cache-flush',
			'POST',
			[ $this, 'cacheFlush' ],
			$read,
			[
				'targets' => [
					'type'     => 'array',
					'maxItems' => 3,
					'items'    => [
						'type' => 'string',
						'enum' => CacheFlushService::TARGETS,
					],
				],
			]
		);

		add_filter( 'rest_pre_dispatch', [ $this, 'onPreDispatch' ], 10, 3 );
		add_filter( 'rest_post_dispatch', [ $this, 'onPostDispatch' ], 999, 3 );
		add_filter( 'rest_pre_serve_request', [ $this, 'onServe' ], 10, 4 );
	}

	// ------------------------------------------------------------------ endpoints

	/**
	 * Permission callback: returns the decision taken in onPreDispatch() (or takes it now).
	 */
	public function permission( \WP_REST_Request $request ): bool|\WP_Error {
		$id = spl_object_id( $request );
		if ( ! array_key_exists( $id, $this->decisions ) ) {
			$this->decisions[ $id ] = $this->decide( $request );
		}
		return $this->decisions[ $id ];
	}

	/**
	 * `/status` is reachable with mode off, but then only reveals `{mode: "off"}`.
	 */
	private function decide( \WP_REST_Request $request ): bool|\WP_Error {
		$route = self::localRoute( $request );
		if ( '/status' === $route && Mode::OFF === $this->plugin->mode()->current() ) {
			return true;
		}
		[ $mode, $bucket ] = self::GATES[ $route ] ?? [ Mode::WRITE, 'write' ];
		return $this->gate->check( $mode, $bucket );
	}

	public function status(): \WP_REST_Response {
		if ( Mode::OFF === $this->plugin->mode()->current() ) {
			return new \WP_REST_Response( [ 'mode' => Mode::OFF ] );
		}
		return new \WP_REST_Response( ( new StatusService( $this->plugin ) )->status() );
	}

	public function list( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			fn () => ( new ListService( $this->plugin->guard() ) )->list(
				(string) $request['path'],
				(int) $request['depth'],
				(int) $request['max_entries']
			)
		);
	}

	public function read( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ): array {
				$known = $request['known'];
				$out   = ( new ReadService( $this->plugin->guard(), $this->plugin->settings()->limit( 'read_bytes' ), $this->plugin->hashCache() ) )->read(
					(string) $request['path'],
					null === $request['from'] ? null : (int) $request['from'],
					null === $request['to'] ? null : (int) $request['to'],
					is_array( $known ) ? [
						's' => (int) $known['s'],
						'm' => (int) $known['m'],
						'h' => (string) $known['h'],
					] : null
				);
				$this->plugin->hashCache()->save();
				$this->auditBytes = strlen( (string) ( $out['content'] ?? '' ) );
				return $out;
			}
		);
	}

	public function grep( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run( fn () => $this->plugin->grepService()->grep( $request->get_params() ) );
	}

	public function manifest( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ): array {
				$out = ( new ManifestService( $this->plugin->guard(), $this->plugin->settings()->limit( 'manifest_files' ), $this->plugin->hashCache() ) )->manifest(
					(string) $request['root'],
					array_map( 'strval', (array) ( $request['exclude'] ?? [] ) )
				);
				$this->plugin->hashCache()->save();
				return $out;
			}
		);
	}

	public function archive( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$settings = $this->plugin->settings();
			$service  = new ArchiveService(
				$this->plugin->guard(),
				$settings->limit( 'archive_files' ),
				$settings->limit( 'manifest_files' ),
				$settings->limit( 'archive_bytes' ),
				get_temp_dir()
			);
			$out      = $service->build(
				null === $request['paths'] ? null : array_map( 'strval', (array) $request['paths'] ),
				null === $request['root'] ? null : (string) $request['root'],
				array_map( 'strval', (array) ( $request['exclude'] ?? [] ) )
			);
		} catch ( ApiException $e ) {
			return self::toError( $e );
		} catch ( \Throwable $e ) {
			return self::internal( $e );
		}
		$this->streamFile = $out['file'];
		$this->auditBytes = $out['bytes'];
		$response         = new \WP_REST_Response( null, 200 );
		$response->header( 'Content-Type', 'application/zip' );
		$response->header( 'Content-Length', (string) $out['bytes'] );
		$response->header( 'X-DevBridge-Files', (string) $out['count'] );
		return $response;
	}

	public function log( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			fn () => ( new LogService( StatusService::debugLogFile(), ABSPATH ) )->tail(
				(int) $request['lines'],
				null === $request['since'] ? null : (int) $request['since']
			)
		);
	}

	public function health( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ): array {
				$paths            = HealthPaths::parse( $request->get_param( 'paths' ) );
				$this->auditPaths = $paths;
				return $this->plugin->health()->checkRecent( 300, $paths );
			}
		);
	}

	public function deploy( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ): array {
				$json = $request->get_param( 'manifest' );
				if ( ! is_string( $json ) || '' === $json ) {
					$length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
					if ( $length > 0 && $length >= $this->plugin->deployLimits()['deploy_zip_bytes'] ) {
						throw new ApiException( 'too_large', 'Request larger than the server upload limits', 413 );
					}
					throw new ApiException( 'invalid_manifest', 'Invalid manifest: missing "manifest" field', 400 );
				}
				$limits   = $this->plugin->deployLimits();
				$manifest = Manifest::parse( $json, $limits['deploy_files'] );

				$this->auditPaths = array_map( static fn ( $e ): string => $e->action . ' ' . $e->path, $manifest->entries );
				$bundle           = self::uploadedBundle( $request );
				$this->auditBytes = null === $bundle ? 0 : (int) filesize( $bundle );

				$out             = $this->plugin->deployer()->deploy( $manifest, $bundle, get_current_user_id() );
				$this->releaseId = (string) $out['release_id'];
				return $out;
			}
		);
	}

	/**
	 * Path of the uploaded `bundle` (PHP temporary file), or null when absent.
	 */
	private static function uploadedBundle( \WP_REST_Request $request ): ?string {
		$files = $request->get_file_params();
		if ( ! isset( $files['bundle'] ) || ! is_array( $files['bundle'] ) ) {
			return null;
		}
		$error = (int) ( $files['bundle']['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return null;
		}
		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			throw new ApiException( 'too_large', 'Bundle larger than the server upload limits', 413 );
		}
		$tmp = (string) ( $files['bundle']['tmp_name'] ?? '' );
		if ( UPLOAD_ERR_OK !== $error || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			throw new ApiException( 'invalid_bundle', 'Invalid bundle: upload failed', 422 );
		}
		return $tmp;
	}

	public function rollback( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ): array {
				$id  = $request->get_param( 'release_id' );
				$out = $this->plugin->rollbackService()->rollback( is_string( $id ) && '' !== $id ? $id : null, (bool) $request->get_param( 'force' ) );

				$this->releaseId  = implode( ',', $out['rolled_back'] );
				$this->auditPaths = array_column( $out['files'], 'p' );
				return $out;
			}
		);
	}

	public function releases(): \WP_REST_Response|\WP_Error {
		return $this->run(
			function (): array {
				$store = new ReleaseStore( $this->plugin->storage()->releasesDir() );
				$list  = [];
				foreach ( $store->all() as $release ) {
					$list[] = [
						'id'         => (string) $release['id'],
						'created_at' => (int) $release['created_at'],
						'user_id'    => (int) $release['user_id'],
						'written'    => (int) $release['written'],
						'deleted'    => (int) $release['deleted'],
						'status'     => (string) $release['status'],
					];
				}
				return [ 'releases' => $list ];
			}
		);
	}

	public function cacheFlush( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$targets = $request->get_param( 'targets' );
		return $this->run(
			fn () => [ 'results' => ( new CacheFlushService() )->flush( is_array( $targets ) && [] !== $targets ? $targets : CacheFlushService::TARGETS ) ]
		);
	}

	// ------------------------------------------------------------------ plumbing

	/**
	 * @param callable(): array<string, mixed> $handler
	 */
	private function run( callable $handler ): \WP_REST_Response|\WP_Error {
		try {
			return new \WP_REST_Response( $handler(), 200 );
		} catch ( ApiException $e ) {
			return self::toError( $e );
		} catch ( \Throwable $e ) {
			return self::internal( $e );
		}
	}

	public static function toError( ApiException $e ): \WP_Error {
		return new \WP_Error( $e->errorCode(), $e->getMessage(), array_merge( $e->extra(), [ 'status' => $e->status() ] ) );
	}

	private static function internal( \Throwable $e ): \WP_Error {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- server-side diagnostics only.
		error_log( sprintf( '[Dev Bridge] %s in %s:%d', get_class( $e ), basename( $e->getFile() ), $e->getLine() ) );
		return new \WP_Error( 'internal_error', 'Internal error', [ 'status' => 500 ] );
	}

	/**
	 * @param callable             $callback
	 * @param callable             $permission
	 * @param array<string, mixed> $args
	 */
	private function route( string $path, string $method, callable $callback, callable $permission, array $args ): void {
		register_rest_route(
			self::NAMESPACE,
			$path,
			[
				'methods'             => $method,
				'callback'            => $callback,
				'permission_callback' => $permission,
				'args'                => $args,
			]
		);
	}

	private static function isOurs( \WP_REST_Request $request ): bool {
		return str_starts_with( $request->get_route(), '/' . self::NAMESPACE . '/' );
	}

	/**
	 * Route without the namespace prefix and trailing slash (e.g. "/read").
	 */
	private static function localRoute( \WP_REST_Request $request ): string {
		return rtrim( substr( $request->get_route(), strlen( '/' . self::NAMESPACE ) ), '/' );
	}

	/**
	 * @param mixed $result
	 * @return mixed
	 */
	public function onPreDispatch( $result, \WP_REST_Server $server, \WP_REST_Request $request ) {
		if ( ! self::isOurs( $request ) ) {
			return $result;
		}
		$this->started    = microtime( true );
		$this->auditable  = Mode::OFF !== $this->plugin->mode()->current();
		$this->auditBytes = 0;
		$this->releaseId  = '';
		$this->auditPaths = null;
		if ( null !== $result || ! isset( self::GATES[ self::localRoute( $request ) ] ) ) {
			return $result;
		}
		$decision = $this->permission( $request );
		return true === $decision ? $result : $decision;
	}

	/**
	 * Rewrites errors to `{error:{code,message,...}}` and records the audit entry.
	 */
	public function onPostDispatch( \WP_HTTP_Response $response, \WP_REST_Server $server, \WP_REST_Request $request ): \WP_HTTP_Response {
		if ( ! self::isOurs( $request ) ) {
			return $response;
		}
		$status = $response->get_status();
		$data   = $response->get_data();
		if ( $status >= 400 && is_array( $data ) && isset( $data['code'] ) ) {
			$error = [
				'code'    => self::CODE_MAP[ $data['code'] ] ?? (string) $data['code'],
				'message' => (string) ( $data['message'] ?? '' ),
			];
			$extra = is_array( $data['data'] ?? null ) ? $data['data'] : [];
			unset( $extra['status'] );
			if ( isset( $extra['params'] ) && is_array( $extra['params'] ) ) {
				$error['message'] .= ': ' . implode( '; ', array_map( 'strval', $extra['params'] ) );
				unset( $extra['params'], $extra['details'] );
			}
			$response->set_data( [ 'error' => array_merge( $error, $extra ) ] );
			if ( 429 === $status && isset( $extra['retry_after'] ) ) {
				$response->header( 'Retry-After', (string) (int) $extra['retry_after'] );
			}
		}
		if ( $this->auditable ) {
			$this->plugin->audit()->record(
				[
					'user_id'     => get_current_user_id(),
					'ip'          => $this->gate->clientIp(),
					'endpoint'    => self::localRoute( $request ),
					'mode'        => $this->plugin->mode()->current(),
					'paths'       => $this->auditPaths ?? self::requestPaths( $request ),
					'bytes'       => $this->auditBytes,
					'status'      => $status,
					'duration_ms' => (int) round( ( microtime( true ) - $this->started ) * 1000 ),
					'release_id'  => $this->releaseId,
				]
			);
		}
		return $response;
	}

	/**
	 * Streams the zip built by `/archive` instead of the JSON body.
	 *
	 * @param bool $served
	 */
	public function onServe( $served, \WP_HTTP_Response $result, \WP_REST_Request $request, \WP_REST_Server $server ): bool {
		if ( null === $this->streamFile || ! self::isOurs( $request ) ) {
			return (bool) $served;
		}
		$file             = $this->streamFile;
		$this->streamFile = null;
		if ( 200 === $result->get_status() && is_file( $file ) ) {
			readfile( $file );
		}
		if ( is_file( $file ) ) {
			unlink( $file );
		}
		return true;
	}

	/**
	 * Paths named by the request, for the audit log (never contents).
	 *
	 * @return string[]
	 */
	private static function requestPaths( \WP_REST_Request $request ): array {
		$paths = [];
		foreach ( [ 'path', 'root' ] as $key ) {
			$value = $request->get_param( $key );
			if ( is_string( $value ) && '' !== $value ) {
				$paths[] = $value;
			}
		}
		$list = $request->get_param( 'paths' );
		if ( is_array( $list ) ) {
			foreach ( $list as $p ) {
				if ( is_string( $p ) ) {
					$paths[] = $p;
				}
			}
		}
		return $paths;
	}

	// ------------------------------------------------------------------ schema helpers

	/**
	 * @return array<string, mixed>
	 */
	private static function int( int $min, int $max, ?int $fallback = null ): array {
		$arg = [
			'type'    => 'integer',
			'minimum' => $min,
			'maximum' => $max,
		];
		if ( null !== $fallback ) {
			$arg['default'] = $fallback;
		}
		return $arg;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function bool(): array {
		return [
			'type'    => 'boolean',
			'default' => false,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function strings( int $maxItems, int $maxLength ): array {
		return [
			'type'     => 'array',
			'maxItems' => $maxItems,
			'items'    => [
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => $maxLength,
			],
		];
	}
}
