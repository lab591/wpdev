<?php
/**
 * Immutable configuration consumed by PathGuard (no WordPress dependency).
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Security;

final class PathPolicy {

	public const DEFAULT_DENY_PATTERNS = [
		'wp-config.php',
		'wp-config-*.php',
		'**/.env*',
		'**/.git/**',
		'**/*.sql',
		'**/*.sql.gz',
		'**/*.log',
		'**/*.key',
		'**/*.pem',
		'wp-content/uploads/**',
		'wp-content/blogs.dir/**',
		'wp-content/devbridge-*/**',
		'**/.htpasswd',
	];

	/** Always applied, even if an administrator removes them from `deny_patterns`. */
	public const MANDATORY_DENY_PATTERNS = [
		'wp-config.php',
		'wp-config-*.php',
		'**/.env*',
		'**/.git/**',
		'**/.htpasswd',
		'wp-content/devbridge-*/**',
	];

	public const DEFAULT_WRITE_EXTENSIONS = [
		'php',
		'js',
		'mjs',
		'css',
		'scss',
		'json',
		'html',
		'twig',
		'svg',
		'png',
		'jpg',
		'jpeg',
		'webp',
		'gif',
		'woff',
		'woff2',
		'ttf',
		'txt',
		'md',
		'pot',
		'po',
		'mo',
		'xml',
	];

	/** Extensions that some server configurations execute as PHP: never writable, in any position. */
	public const EXECUTABLE_EXTENSIONS = [ 'phar', 'phtml', 'pht', 'phps', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8' ];

	/** File names that are never writable. */
	public const FORBIDDEN_NAMES = [ '.htaccess', '.user.ini', 'php.ini', '.htpasswd' ];

	/** @var string[] */
	public readonly array $readRoots;
	/** @var string[] */
	public readonly array $writableRoots;
	/** @var string[] */
	public readonly array $denyPatterns;
	/** @var string[] */
	public readonly array $writeExtensions;
	/** @var string[] */
	public readonly array $protectedPaths;
	public readonly bool $caseInsensitive;

	/**
	 * @param string    $abspath         WordPress ABSPATH.
	 * @param string[]  $readRoots       Roots relative to ABSPATH ('' = ABSPATH).
	 * @param string[]  $writableRoots   Roots relative to ABSPATH.
	 * @param string[]  $denyPatterns    Glob patterns applied to canonical relative paths.
	 * @param string[]  $writeExtensions Allowed extensions for writing (lowercase, without dot).
	 * @param string[]  $protectedPaths  Absolute paths never accessible (plugin dir, storage, rescue file).
	 * @param bool|null $caseInsensitive Filesystem case sensitivity; autodetected when null.
	 */
	public function __construct(
		public readonly string $abspath,
		array $readRoots,
		array $writableRoots,
		array $denyPatterns = self::DEFAULT_DENY_PATTERNS,
		array $writeExtensions = self::DEFAULT_WRITE_EXTENSIONS,
		array $protectedPaths = [],
		?bool $caseInsensitive = null,
	) {
		$this->readRoots       = array_values( array_map( 'strval', $readRoots ) );
		$this->writableRoots   = array_values( array_map( 'strval', $writableRoots ) );
		$this->denyPatterns    = array_values( array_filter( array_map( 'strval', $denyPatterns ), static fn ( string $p ): bool => '' !== trim( $p ) ) );
		$this->writeExtensions = array_values( array_map( static fn ( $e ): string => strtolower( ltrim( (string) $e, '.' ) ), $writeExtensions ) );
		$this->protectedPaths  = array_values( array_map( 'strval', $protectedPaths ) );
		$this->caseInsensitive = $caseInsensitive ?? self::detectCaseInsensitive();
	}

	public static function detectCaseInsensitive(): bool {
		return in_array( PHP_OS_FAMILY, [ 'Windows', 'Darwin' ], true );
	}
}
