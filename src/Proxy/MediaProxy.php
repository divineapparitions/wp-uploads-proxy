<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Proxy;

use DivineApparitions\UploadsProxy\Config\ConfigResolver;
use DivineApparitions\UploadsProxy\Registrable;

/**
 * Rewrites uploads URLs to a configured Origin when the file is missing locally.
 *
 * Superseded by the request-interception handler (ADR-0001) and slated for
 * removal in the walking-skeleton slice; retained here only so the plugin keeps
 * booting. It now reads its Origin from the configuration resolver rather than
 * the raw option array.
 */
final class MediaProxy implements Registrable {

	public function __construct(
		private readonly ConfigResolver $resolver,
	) {
	}

	public function register(): void {
		// Don't touch the production environment itself, and do nothing until configured.
		if ( wp_get_environment_type() === 'production' || ! $this->resolver->resolve()->isEnabled() ) {
			return;
		}

		add_filter( 'wp_get_attachment_url', [ $this, 'filterUrl' ] );
		add_filter( 'wp_get_attachment_image_src', [ $this, 'filterImageSrc' ] );
		add_filter( 'wp_calculate_image_srcset', [ $this, 'filterSrcset' ] );
	}

	/**
	 * Rewrite a single attachment URL.
	 */
	public function filterUrl( mixed $url ): mixed {
		return is_string( $url ) ? $this->maybeProxy( $url ) : $url;
	}

	/**
	 * Rewrite the URL inside a `wp_get_attachment_image_src` result.
	 *
	 * @param mixed $image array{0: string, 1: int, 2: int, 3: bool}|false
	 */
	public function filterImageSrc( mixed $image ): mixed {
		if ( is_array( $image ) && isset( $image[0] ) && is_string( $image[0] ) ) {
			$image[0] = $this->maybeProxy( $image[0] );
		}

		return $image;
	}

	/**
	 * Rewrite every candidate URL in a srcset array.
	 *
	 * @param mixed $sources array<int, array{url: string, descriptor: string, value: int}>
	 */
	public function filterSrcset( mixed $sources ): mixed {
		if ( ! is_array( $sources ) ) {
			return $sources;
		}

		foreach ( $sources as $width => $source ) {
			if ( isset( $source['url'] ) && is_string( $source['url'] ) ) {
				$sources[ $width ]['url'] = $this->maybeProxy( $source['url'] );
			}
		}

		return $sources;
	}

	/**
	 * Swap a local uploads URL for the production origin when appropriate.
	 */
	private function maybeProxy( string $url ): string {
		$uploads  = wp_upload_dir();
		$base_url = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
		$base_dir = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';

		// Only rewrite URLs that live under this site's uploads directory.
		if ( '' === $base_url || ! str_starts_with( $url, $base_url ) ) {
			return $url;
		}

		// Leave it alone if the file is present locally.
		if ( '' !== $base_dir ) {
			$relative = ltrim( substr( $url, strlen( $base_url ) ), '/' );
			$path     = strtok( $relative, '?' ) ?: $relative;

			if ( is_file( $base_dir . '/' . $path ) ) {
				return $url;
			}
		}

		// Replace only the origin (scheme://host[:port]) so the full uploads
		// path — e.g. /wp-content/uploads/2026/06/img.jpg — is preserved.
		$origin = $this->originOf( $base_url );

		if ( '' === $origin ) {
			return $url;
		}

		return $this->resolver->resolve()->origin() . substr( $url, strlen( $origin ) );
	}

	/**
	 * Extract the scheme://host[:port] origin from a URL, or '' if unparseable.
	 */
	private function originOf( string $url ): string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$origin = $parts['scheme'] . '://' . $parts['host'];

		if ( isset( $parts['port'] ) ) {
			$origin .= ':' . $parts['port'];
		}

		return $origin;
	}
}
