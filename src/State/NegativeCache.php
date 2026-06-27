<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\State;

/**
 * Short-lived negative cache for uploads paths known to be absent on the Origin.
 *
 * When the Origin returns a 404 or 410 for a given uploads path the proxy records
 * a transient entry so that repeat Misses for a genuinely-absent file short-circuit
 * without re-hitting the Origin. A 5xx or transport timeout does NOT create an
 * entry, so those paths will retry the Origin on the next request.
 *
 * The transient key is derived from an md5 hash of the relative uploads path, so
 * keys are always within the WordPress 172-character transient-name limit regardless
 * of path depth.
 */
final class NegativeCache {

	/**
	 * Transient key prefix.
	 */
	private const KEY_PREFIX = 'uploads_proxy_neg_';

	/**
	 * Transient lifetime in seconds (~10 minutes).
	 */
	public const TTL = 600;

	/**
	 * Whether the given relative uploads path is already recorded as absent on the Origin.
	 *
	 * @param string $relativePath Uploads-relative path (e.g. `2026/06/photo.jpg`).
	 */
	public function isNegative( string $relativePath ): bool {
		return false !== get_transient( $this->key( $relativePath ) );
	}

	/**
	 * Record that the given relative uploads path returned 404 or 410 on the Origin.
	 *
	 * The entry expires after {@see NegativeCache::TTL} seconds. Calling this on a
	 * path that already has an entry silently refreshes its TTL.
	 *
	 * @param string $relativePath Uploads-relative path (e.g. `2026/06/photo.jpg`).
	 */
	public function record( string $relativePath ): void {
		set_transient( $this->key( $relativePath ), '1', self::TTL );
	}

	/**
	 * Build the transient key for a relative uploads path.
	 */
	private function key( string $relativePath ): string {
		return self::KEY_PREFIX . md5( $relativePath );
	}
}
