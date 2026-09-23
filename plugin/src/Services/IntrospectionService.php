<?php
/**
 * Read-only introspection of the running site (0.5.0): what Claude would otherwise reconstruct
 * with many greps — active plugins and theme, content types, shortcodes, hook callbacks with
 * file and line, REST routes, cron events, blocks.
 *
 * Only names, versions and code locations are returned: never option values, secrets or file
 * contents. File paths are relative to ABSPATH (paths outside it are reported without location).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Services;

use Lab591\DevBridge\Support\ApiException;

final class IntrospectionService {

	public const TOPICS = [ 'overview', 'post_types', 'taxonomies', 'shortcodes', 'hook', 'rest_routes', 'cron', 'blocks' ];

	public const MAX_ITEMS = 300;

	/** Hook names and filters: letters, digits and the separators used by WordPress and plugins. */
	public const NAME_PATTERN = '/^[A-Za-z0-9_\-.\/:{}\[\]]{1,200}$/';

	public function __construct( private readonly string $abspath ) {
	}

	/**
	 * @param string $topic One of TOPICS.
	 * @param string $name  Hook name (topic "hook", required) or filter prefix (rest_routes, blocks).
	 * @return array{topic: string, items: list<array<string, mixed>>, truncated: bool, note?: string}
	 * @throws ApiException On unknown topic or invalid name.
	 */
	public function get( string $topic, string $name = '' ): array {
		if ( ! in_array( $topic, self::TOPICS, true ) ) {
			throw new ApiException( 'invalid_param', 'Unknown topic', 400 );
		}
		if ( '' !== $name && 1 !== preg_match( self::NAME_PATTERN, $name ) ) {
			throw new ApiException( 'invalid_param', 'Invalid name', 400 );
		}
		if ( 'hook' === $topic && '' === $name ) {
			throw new ApiException( 'invalid_param', 'The hook topic needs a hook name (e.g. "init")', 400 );
		}
		$items = match ( $topic ) {
			'overview'    => $this->overview(),
			'post_types'  => $this->postTypes(),
			'taxonomies'  => $this->taxonomies(),
			'shortcodes'  => $this->shortcodes(),
			'hook'        => $this->hook( $name ),
			'rest_routes' => $this->restRoutes( $name ),
			'cron'        => $this->cron(),
			'blocks'      => $this->blocks( $name ),
		};
		$out = [
			'topic'     => $topic,
			'items'     => array_slice( $items, 0, self::MAX_ITEMS ),
			'truncated' => count( $items ) > self::MAX_ITEMS,
		];
		if ( in_array( $topic, [ 'hook', 'shortcodes' ], true ) ) {
			$out['note'] = 'Callbacks registered while serving an API request: hooks added only on the front end (e.g. in template_redirect) or only in wp-admin may be missing.';
		}
		return $out;
	}

	// ------------------------------------------------------------------ topics

	/**
	 * @return list<array<string, mixed>>
	 */
	private function overview(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all     = get_plugins();
		$network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) : [];
		$active  = array_values( array_unique( array_merge( (array) get_option( 'active_plugins', [] ), $network ) ) );
		$items   = [
			[
				'key'   => 'wordpress',
				'value' => get_bloginfo( 'version' ),
			],
			[
				'key'   => 'php',
				'value' => PHP_VERSION,
			],
			[
				'key'   => 'environment',
				'value' => wp_get_environment_type(),
			],
			[
				'key'   => 'multisite',
				'value' => is_multisite() ? 'yes' : 'no',
			],
			[
				'key'   => 'locale',
				'value' => get_locale(),
			],
			[
				'key'   => 'permalinks',
				'value' => '' === (string) get_option( 'permalink_structure' ) ? 'plain' : (string) get_option( 'permalink_structure' ),
			],
			[
				'key'   => 'object_cache',
				'value' => wp_using_ext_object_cache() ? 'external' : 'none',
			],
		];
		foreach ( [ 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'DISALLOW_FILE_EDIT', 'DISABLE_WP_CRON' ] as $constant ) {
			$items[] = [
				'key'   => $constant,
				'value' => defined( $constant ) ? ( constant( $constant ) ? 'true' : 'false' ) : 'undefined',
			];
		}
		$theme   = wp_get_theme();
		$items[] = [
			'key'     => 'theme',
			'value'   => $theme->get_stylesheet(),
			'name'    => (string) $theme->get( 'Name' ),
			'version' => (string) $theme->get( 'Version' ),
			'parent'  => $theme->parent() ? $theme->get_template() : '',
		];
		foreach ( $active as $file ) {
			$data    = $all[ $file ] ?? [];
			$items[] = [
				'key'     => 'plugin',
				'value'   => (string) $file,
				'name'    => (string) ( $data['Name'] ?? '' ),
				'version' => (string) ( $data['Version'] ?? '' ),
				'network' => in_array( $file, $network, true ),
			];
		}
		foreach ( get_mu_plugins() as $file => $data ) {
			$items[] = [
				'key'     => 'mu-plugin',
				'value'   => (string) $file,
				'name'    => (string) ( $data['Name'] ?? '' ),
				'version' => (string) ( $data['Version'] ?? '' ),
			];
		}
		return $items;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function postTypes(): array {
		$items = [];
		foreach ( get_post_types( [], 'objects' ) as $type ) {
			$items[] = [
				'name'         => $type->name,
				'label'        => (string) $type->label,
				'public'       => (bool) $type->public,
				'hierarchical' => (bool) $type->hierarchical,
				'show_in_rest' => (bool) $type->show_in_rest,
				'has_archive'  => (bool) $type->has_archive,
				'rewrite'      => is_array( $type->rewrite ) ? (string) ( $type->rewrite['slug'] ?? '' ) : '',
				'supports'     => array_keys( get_all_post_type_supports( $type->name ) ),
			];
		}
		return $items;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function taxonomies(): array {
		$items = [];
		foreach ( get_taxonomies( [], 'objects' ) as $tax ) {
			$items[] = [
				'name'         => $tax->name,
				'label'        => (string) $tax->label,
				'object_type'  => array_values( (array) $tax->object_type ),
				'public'       => (bool) $tax->public,
				'hierarchical' => (bool) $tax->hierarchical,
				'show_in_rest' => (bool) $tax->show_in_rest,
			];
		}
		return $items;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function shortcodes(): array {
		global $shortcode_tags;
		$items = [];
		foreach ( (array) $shortcode_tags as $tag => $callback ) {
			$items[] = [ 'tag' => (string) $tag ] + self::describeCallback( $callback, $this->abspath );
		}
		usort( $items, static fn ( array $a, array $b ): int => strcmp( $a['tag'], $b['tag'] ) );
		return $items;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function hook( string $name ): array {
		global $wp_filter;
		$items = [];
		$hook  = $wp_filter[ $name ] ?? null;
		if ( ! is_object( $hook ) || ! isset( $hook->callbacks ) ) {
			return [];
		}
		$callbacks = (array) $hook->callbacks;
		ksort( $callbacks, SORT_NUMERIC );
		foreach ( $callbacks as $priority => $list ) {
			foreach ( (array) $list as $entry ) {
				$items[] = [
					'priority' => (int) $priority,
					'args'     => (int) ( $entry['accepted_args'] ?? 1 ),
				] + self::describeCallback( $entry['function'] ?? null, $this->abspath );
			}
		}
		return $items;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function restRoutes( string $prefix ): array {
		$items = [];
		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			if ( '' !== $prefix && ! str_starts_with( ltrim( $route, '/' ), ltrim( $prefix, '/' ) ) ) {
				continue;
			}
			$methods = [];
			foreach ( (array) $handlers as $handler ) {
				foreach ( array_keys( (array) ( $handler['methods'] ?? [] ) ) as $method ) {
					$methods[ $method ] = true;
				}
			}
			$items[] = [
				'route'   => (string) $route,
				'methods' => array_keys( $methods ),
			];
		}
		return $items;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function cron(): array {
		$items = [];
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			foreach ( (array) $hooks as $hook => $events ) {
				foreach ( (array) $events as $event ) {
					$items[] = [
						'hook'     => (string) $hook,
						'next'     => gmdate( 'Y-m-d H:i:s', (int) $timestamp ) . ' UTC',
						'schedule' => empty( $event['schedule'] ) ? 'single' : (string) $event['schedule'],
					];
				}
			}
		}
		return $items;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function blocks( string $prefix ): array {
		$items = [];
		$core  = 0;
		foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $block ) {
			if ( '' === $prefix && str_starts_with( $name, 'core/' ) ) {
				++$core;
				continue;
			}
			if ( '' !== $prefix && ! str_starts_with( $name, $prefix ) ) {
				continue;
			}
			$item = [
				'name'  => (string) $name,
				'title' => (string) ( $block->title ?? '' ),
			];
			if ( is_callable( $block->render_callback ?? null ) ) {
				$item += self::describeCallback( $block->render_callback, $this->abspath );
			}
			$items[] = $item;
		}
		if ( $core > 0 ) {
			$items[] = [
				'name'  => 'core/*',
				'title' => sprintf( '%d core blocks (ask with name "core/" to list them)', $core ),
			];
		}
		return $items;
	}

	// ------------------------------------------------------------------ callbacks

	/**
	 * Readable name and code location of a PHP callback, with the file relative to ABSPATH.
	 *
	 * @return array{callback: string, file?: string, line?: int}
	 */
	public static function describeCallback( mixed $callback, string $abspath ): array {
		try {
			if ( $callback instanceof \Closure ) {
				$ref = new \ReflectionFunction( $callback );
				return self::located( '{closure}', $ref, $abspath );
			}
			if ( is_string( $callback ) ) {
				if ( str_contains( $callback, '::' ) ) {
					[ $class, $method ] = explode( '::', $callback, 2 );
					return self::located( $callback, new \ReflectionMethod( $class, $method ), $abspath );
				}
				return function_exists( $callback )
					? self::located( $callback, new \ReflectionFunction( $callback ), $abspath )
					: [ 'callback' => $callback ];
			}
			if ( is_array( $callback ) && 2 === count( $callback ) && is_string( $callback[1] ?? null ) ) {
				$target = $callback[0];
				$class  = is_object( $target ) ? get_class( $target ) : (string) $target;
				$label  = $class . ( is_object( $target ) ? '->' : '::' ) . $callback[1];
				return method_exists( $class, $callback[1] )
					? self::located( $label, new \ReflectionMethod( $class, $callback[1] ), $abspath )
					: [ 'callback' => $label ];
			}
			if ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				return self::located( get_class( $callback ) . '::__invoke', new \ReflectionMethod( $callback, '__invoke' ), $abspath );
			}
		} catch ( \ReflectionException ) {
			return [ 'callback' => is_string( $callback ) ? $callback : 'unknown' ];
		}
		return [ 'callback' => 'unknown' ];
	}

	/**
	 * @return array{callback: string, file?: string, line?: int}
	 */
	private static function located( string $label, \ReflectionFunctionAbstract $ref, string $abspath ): array {
		$file = $ref->getFileName();
		if ( false === $file ) {
			return [ 'callback' => $label ]; // Internal PHP function.
		}
		$file = str_replace( '\\', '/', $file );
		$root = rtrim( str_replace( '\\', '/', $abspath ), '/' ) . '/';
		if ( 0 !== strncasecmp( $file, $root, strlen( $root ) ) ) {
			return [ 'callback' => $label ]; // Outside the site root: no location disclosed.
		}
		return [
			'callback' => $label,
			'file'     => substr( $file, strlen( $root ) ),
			'line'     => (int) $ref->getStartLine(),
		];
	}
}
