<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Proxy;

/**
 * The local Uploads directory and the rules for staying inside it.
 *
 * Maps an incoming request URI to a site-relative uploads path, rejecting
 * anything that escapes the uploads scope (paths outside the uploads URL prefix,
 * `..` traversal, null bytes, absolute-path escapes), and answers whether a given
 * file may be written. The write-policy gate has two layers:
 *   1. A hard deny on executable extensions (pure PHP, no WordPress).
 *   2. A WordPress allowed-MIME gate via `wp_check_filetype()`: only extensions
 *      the site's allowed-MIME list recognises are permitted.
 *
 * The path logic methods ({@see UploadsScope::relativePathFor()},
 * {@see UploadsScope::absolutePathFor()}) have no WordPress dependency.
 * {@see UploadsScope::isAllowedFile()} calls `wp_check_filetype()` and must run
 * inside a WordPress bootstrap or a Brain Monkey test that stubs WP functions.
 * {@see UploadsScope::fromWordPress()} builds an instance from `wp_upload_dir()`.
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
		if ( '' === $this->basePath ) {
			return null;
		}

		// Drop any query string / fragment, then percent-decode so the path matches
		// the real Origin / on-disk filename (e.g. "%20" → space). Decode BEFORE the
		// safety checks below, so an encoded null byte ("%00") or traversal segment
		// ("%2e%2e") cannot slip past them.
		$path = rawurldecode( (string) strtok( $requestUri, '?#' ) );

		if ( str_contains( $path, "\0" ) ) {
			return null;
		}

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
	 * Whether a relative uploads path may be written.
	 *
	 * Two-layer gate:
	 *   1. Hard deny on executable extensions — no network call, no WordPress.
	 *   2. WordPress allowed-MIME check via `wp_check_filetype()`: only extensions
	 *      the site's allowed-MIME-type list recognises are written. This prevents
	 *      a stray non-executable but disallowed file on the Origin (e.g. `.bat`,
	 *      an unknown binary) from ever being saved locally.
	 *
	 * Callers must ensure WordPress (or a Brain Monkey stub) is available for the
	 * second layer; the first layer is pure PHP.
	 */
	public function isAllowedFile( string $relativePath ): bool {
		$extension = strtolower( pathinfo( $relativePath, PATHINFO_EXTENSION ) );

		// Layer 1: hard deny on executable extensions — pure PHP, no WP call.
		if ( '' === $extension || in_array( $extension, self::EXECUTABLE_EXTENSIONS, true ) ) {
			return false;
		}

		// Layer 2: gate against WordPress's allowed-MIME-type list. Only extensions
		// that wp_check_filetype() maps to a known, non-false type are permitted.
		$check   = wp_check_filetype( basename( $relativePath ) );
		$allowed = false !== $check['type'];

		/**
		 * Filters whether a non-executable Uploads file may be downloaded and saved.
		 *
		 * The default is WordPress's allowed-MIME-type list, which on a front-end
		 * (anonymous) request omits types only registered for logged-in uploaders —
		 * notably SVG via the "SVG Support" plugin — so those files are not proxied in
		 * Download mode and appear broken. Return true to proxy such a type anyway
		 * (e.g. SVG), or false to refuse one. Executable extensions are hard-denied
		 * before this filter runs and can never be re-enabled through it.
		 *
		 * @param bool   $allowed      Whether the file is allowed by default.
		 * @param string $relativePath The site-relative uploads path.
		 * @param string $extension    The lower-cased file extension.
		 */
		return (bool) apply_filters( 'uploads_proxy_is_allowed_file', $allowed, $relativePath, $extension );
	}
}
