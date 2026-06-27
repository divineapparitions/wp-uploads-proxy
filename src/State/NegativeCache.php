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
final class NegativeCache implements NegativeStore {

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
	 * Clear every Negative-cache entry, returning the number of entries removed.
	 *
	 * The plugin keeps no manifest of which paths have been cached, so individual
	 * keys cannot be replayed through `delete_transient`. Instead the whole
	 * `uploads_proxy_neg_*` transient family is removed in one query: both the
	 * `_transient_` value rows and their `_transient_timeout_` siblings. This only
	 * deletes the short-lived transients — it never touches downloaded media on
	 * disk. Returns the number of entries cleared (each entry owns two option rows).
	 */
	public function clearAll(): int {
		global $wpdb;

		$like = $wpdb->esc_like( '_transient_' . self::KEY_PREFIX ) . '%';
		$rows = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$like,
				$wpdb->esc_like( '_transient_timeout_' . self::KEY_PREFIX ) . '%'
			)
		);

		// Each cached entry persists as two option rows (value + timeout).
		return intdiv( $rows, 2 );
	}

	/**
	 * Build the transient key for a relative uploads path.
	 */
	private function key( string $relativePath ): string {
		return self::KEY_PREFIX . md5( $relativePath );
	}
}
