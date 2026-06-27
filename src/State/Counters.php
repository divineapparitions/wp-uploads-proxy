<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\State;

/**
 * Counters-only state for the diagnostics page.
 *
 * The plugin deliberately keeps no per-file manifest (ADR/CONTEXT decision); it
 * only tracks aggregate totals. This slice tracks the number of files
 * downloaded-and-served from the Origin. The count is a non-autoloaded option so
 * it never bloats the autoloaded options cache.
 */
final class Counters implements CountersStore {

	/**
	 * Option name for the downloaded-files total.
	 */
	public const OPTION_DOWNLOADED = 'uploads_proxy_downloaded_count';

	/**
	 * Option name for the negative-cache entries created total.
	 */
	public const OPTION_NEGATIVE_COUNT = 'uploads_proxy_negative_count';

	/**
	 * The number of files downloaded from the Origin and saved locally.
	 */
	public function downloaded(): int {
		return (int) get_option( self::OPTION_DOWNLOADED, 0 );
	}

	/**
	 * Record one successful download-and-serve, returning the new total.
	 */
	public function recordDownload(): int {
		$next = $this->downloaded() + 1;
		update_option( self::OPTION_DOWNLOADED, $next, false );

		return $next;
	}

	/**
	 * The number of Negative-cache entries ever created (a running total, not the current live count).
	 */
	public function negativeCount(): int {
		return (int) get_option( self::OPTION_NEGATIVE_COUNT, 0 );
	}

	/**
	 * Record one new Negative-cache entry, returning the new total.
	 */
	public function recordNegative(): int {
		$next = $this->negativeCount() + 1;
		update_option( self::OPTION_NEGATIVE_COUNT, $next, false );

		return $next;
	}

	/**
	 * Reset both aggregate totals to zero.
	 *
	 * Used by the `wp uploads-proxy clear-cache` command to give CI a clean slate.
	 * This only touches the counter options; it never deletes downloaded media.
	 */
	public function reset(): void {
		update_option( self::OPTION_DOWNLOADED, 0, false );
		update_option( self::OPTION_NEGATIVE_COUNT, 0, false );
	}
}
