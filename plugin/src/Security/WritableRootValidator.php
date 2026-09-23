<?php
/**
 * Validates `writable_roots` entries (SPEC 2.4), at save time and at every request.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Security;

final class WritableRootValidator {

	/**
	 * @param string   $abspath        WordPress ABSPATH.
	 * @param string[] $protectedPaths Absolute paths a root can neither be, contain nor be inside of.
	 * @param bool     $allowMuPlugins Whether roots below wp-content/mu-plugins are allowed.
	 * @param string[] $denyPatterns   Deny list: a root matching it is refused.
	 */
	public function __construct(
		private readonly string $abspath,
		private readonly array $protectedPaths,
		private readonly bool $allowMuPlugins = false,
		private readonly array $denyPatterns = PathPolicy::DEFAULT_DENY_PATTERNS,
	) {
	}

	/**
	 * Returns the canonical root (relative, with `/`) or throws with a user-facing reason.
	 *
	 * @throws PathException When the root is not acceptable.
	 */
	public function validate( string $root ): string {
		$root = trim( str_replace( '\\', '/', trim( $root ) ), '/' );
		if ( '' === $root ) {
			throw PathException::invalid( 'empty folder' );
		}

		$containers = [ 'wp-content/themes', 'wp-content/plugins' ];
		if ( $this->allowMuPlugins ) {
			$containers[] = 'wp-content/mu-plugins';
		}

		// Everything below a container must be a sub-folder, never the container itself.
		$container = null;
		foreach ( $containers as $candidate ) {
			if ( 0 === strcasecmp( $root, $candidate ) ) {
				throw PathException::denied( 'the themes/plugins folder itself cannot be writable' );
			}
			if ( 0 === strncasecmp( $root, $candidate . '/', strlen( $candidate ) + 1 ) ) {
				$container = $candidate;
				break;
			}
		}
		if ( null === $container ) {
			throw PathException::denied( 'writable folders must be inside wp-content/themes/ or wp-content/plugins/' );
		}

		$guard    = new PathGuard(
			new PathPolicy(
				abspath: $this->abspath,
				readRoots: [ $container ],
				writableRoots: [],
				denyPatterns: $this->denyPatterns,
				protectedPaths: $this->protectedPaths,
			)
		);
		$resolved = $guard->resolve( $root, Access::Read );
		if ( ! $resolved->isDir ) {
			throw PathException::notADirectory();
		}
		if ( 0 !== strcasecmp( $resolved->relative, $container ) && 0 !== strncasecmp( $resolved->relative, $container . '/', strlen( $container ) + 1 ) ) {
			throw PathException::denied( 'folder resolves outside its container' );
		}
		if ( 0 === strcasecmp( $resolved->relative, $container ) ) {
			throw PathException::denied( 'the themes/plugins folder itself cannot be writable' );
		}

		foreach ( $this->protectedPaths as $protected ) {
			$real = realpath( $protected );
			if ( false === $real ) {
				continue;
			}
			$prefix = rtrim( $resolved->absolute, '/\\' ) . DIRECTORY_SEPARATOR;
			if ( str_starts_with( strtolower( $real . DIRECTORY_SEPARATOR ), strtolower( $prefix ) ) ) {
				throw PathException::denied( 'folder contains Dev Bridge files' );
			}
		}
		return $resolved->relative;
	}
}
