<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Proxy;

/**
 * The local Uploads directory and the rules for staying inside it.
 *
 * Maps an incoming request URI to a site-relative uploads path, rejecting
 * anything that escapes the uploads scope (paths outside the uploads URL prefix,
 * `..` traversal, null bytes), and answers whether a given file may be written
 * (a hard deny on executable extensions). Pure path logic with no WordPress
 * dependency, so it can be unit-tested directly; {@see UploadsScope::fromWordPress()}
 * builds one from `wp_upload_dir()`.
 *
 * The full mime/exec hardening and its exhaustive matrix are issue #4's job;
 * this implements the basic safe gate the walking skeleton needs.
 */
final class UploadsScope {

	/**
	 * Extensions that must never be written, regardless of mime type.
	 *
	 * @var list<string>
	 */
	private const EXECUTABLE_EXTENSIONS = [
		'php',
		'php3',
		'php4',
		'php5',
		'php7',
		'phtml',
		'pht',
		'phar',
		'cgi',
		'pl',
	];

	/**
	 * @param string $baseDir  Absolute filesystem path to the uploads basedir (no trailing slash).
	 * @param string $basePath Site-relative URL path prefix for uploads (e.g. /wp-content/uploads).
	 */
	public function __construct(
		private readonly string $baseDir,
		private readonly string $basePath,
	) {
	}

	/**
	 * Build a scope from the live WordPress uploads directory.
	 */
	public static function fromWordPress(): self {
		$uploads  = wp_upload_dir();
		$baseDir  = isset( $uploads['basedir'] ) ? untrailingslashit( (string) $uploads['basedir'] ) : '';
		$baseUrl  = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
		$parsed   = wp_parse_url( $baseUrl, PHP_URL_PATH );
		$basePath = is_string( $parsed ) ? untrailingslashit( $parsed ) : '';

		return new self( $baseDir, $basePath );
	}

	/**
	 * The site-relative uploads path for a request URI, or null when the request
	 * is not a safe, in-scope uploads path.
	 *
	 * Returns null for requests outside the uploads URL prefix, paths containing
	 * a null byte, or paths that try to traverse out of the uploads directory.
	 */
	public function relativePathFor( string $requestUri ): ?string {
		if ( '' === $this->basePath || str_contains( $requestUri, "\0" ) ) {
			return null;
		}

		// Drop any query string / fragment.
		$path = (string) strtok( $requestUri, '?#' );

		$prefix = $this->basePath . '/';
		if ( ! str_starts_with( $path, $prefix ) ) {
			return null;
		}

		$relative = ltrim( substr( $path, strlen( $prefix ) ), '/' );

		if ( '' === $relative ) {
			return null;
		}

		// Refuse traversal: no segment may be empty or `..`.
		foreach ( explode( '/', $relative ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return null;
			}
		}

		return $relative;
	}

	/**
	 * The absolute filesystem path a relative uploads path maps to, contained to
	 * the basedir.
	 */
	public function absolutePathFor( string $relativePath ): string {
		return $this->baseDir . '/' . ltrim( $relativePath, '/' );
	}

	/**
	 * The uploads basedir.
	 */
	public function baseDir(): string {
		return $this->baseDir;
	}

	/**
	 * Whether a relative uploads path may be written: in-scope and not an
	 * executable extension.
	 */
	public function isAllowedFile( string $relativePath ): bool {
		$extension = strtolower( pathinfo( $relativePath, PATHINFO_EXTENSION ) );

		if ( '' === $extension || in_array( $extension, self::EXECUTABLE_EXTENSIONS, true ) ) {
			return false;
		}

		return true;
	}
}
