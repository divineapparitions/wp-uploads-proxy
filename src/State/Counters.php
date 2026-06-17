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
final class Counters {

	/**
	 * Option name for the downloaded-files total.
	 */
	public const OPTION_DOWNLOADED = 'uploads_proxy_downloaded_count';

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
}
