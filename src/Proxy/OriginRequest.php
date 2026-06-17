<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Proxy;

use DivineApparitions\UploadsProxy\Config\BasicAuth;

/**
 * The outbound request for a single missing Uploads file, addressed at the Origin.
 *
 * Only the host is swapped: the relative uploads path (original or Derivative) is
 * preserved verbatim so the Origin serves the exact same file. Optional Basic Auth
 * is rendered into an Authorization header for the WP HTTP layer.
 */
final class OriginRequest {

	/**
	 * @param string         $origin       Origin URL with no trailing slash (e.g. https://origin.test).
	 * @param string         $relativePath The site-relative uploads path (e.g. /wp-content/uploads/...).
	 * @param BasicAuth|null $basicAuth    Optional Origin Basic Auth credentials.
	 */
	public function __construct(
		private readonly string $origin,
		private readonly string $relativePath,
		private readonly ?BasicAuth $basicAuth,
	) {
	}

	/**
	 * The absolute Origin URL for this file (host swapped, path preserved).
	 */
	public function url(): string {
		return $this->origin . '/' . ltrim( $this->relativePath, '/' );
	}

	/**
	 * Request headers for the WP HTTP layer — an Authorization header when Basic
	 * Auth is configured, otherwise none.
	 *
	 * @return array<string, string>
	 */
	public function headers(): array {
		if ( null === $this->basicAuth ) {
			return [];
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Basic Auth credentials must be base64-encoded per RFC 7617.
		$credentials = base64_encode( $this->basicAuth->username() . ':' . $this->basicAuth->password() );

		return [
			'Authorization' => 'Basic ' . $credentials,
		];
	}
}
