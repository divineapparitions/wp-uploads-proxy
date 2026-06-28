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
	 * The plugin keeps no manifest of which paths have been cached, so the cached
	 * keys are discovered by querying the options table for the `_transient_`
	 * value rows of the `uploads_proxy_neg_*` family, then removed via
	 * {@see delete_transient()} — one call per entry. Going through the transient
	 * API (rather than a raw `DELETE`) is deliberate: it removes both the value
	 * and `_transient_timeout_` rows AND invalidates WordPress's in-memory option
	 * cache, so an `isNegative()` check later in the same request sees the entry
	 * gone. A raw `DELETE` leaves that cache stale. This only touches the
	 * short-lived transients — never downloaded media on disk. Returns the number
	 * of entries cleared.
	 *
	 * Note: with a persistent object cache backing transients (no option rows),
	 * there is nothing to enumerate here — a known limitation for that setup.
	 */
	public function clearAll(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- No WP API enumerates transients by key pattern; the prepared LIKE query is read once and each row is immediately cleared via delete_transient() (see method docblock).
		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::KEY_PREFIX ) . '%'
			)
		);

		$cleared = 0;

		foreach ( $option_names as $option_name ) {
			$transient = substr( (string) $option_name, strlen( '_transient_' ) );

			if ( delete_transient( $transient ) ) {
				++$cleared;
			}
		}

		return $cleared;
	}

	/**
	 * Build the transient key for a relative uploads path.
	 */
	private function key( string $relativePath ): string {
		return self::KEY_PREFIX . md5( $relativePath );
	}
}
