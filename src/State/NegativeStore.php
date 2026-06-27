<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\State;

/**
 * The Negative-cache surface the rest of the plugin depends on.
 *
 * Recording and querying per-path entries (the request flow) plus clearing the
 * whole family at once (the WP-CLI `clear-cache` reset). Extracted as an
 * interface so the command's logic can be unit tested against a fake without the
 * concrete {@see NegativeCache} transient glue.
 */
interface NegativeStore {

	/**
	 * Whether the given relative uploads path is already recorded as absent on the Origin.
	 *
	 * @param string $relativePath Uploads-relative path (e.g. `2026/06/photo.jpg`).
	 */
	public function isNegative( string $relativePath ): bool;

	/**
	 * Record that the given relative uploads path returned 404 or 410 on the Origin.
	 *
	 * @param string $relativePath Uploads-relative path (e.g. `2026/06/photo.jpg`).
	 */
	public function record( string $relativePath ): void;

	/**
	 * Clear every Negative-cache entry, returning the number of entries removed.
	 *
	 * Never touches downloaded media on disk — only the short-lived transients.
	 */
	public function clearAll(): int;
}
