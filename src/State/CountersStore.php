<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\State;

/**
 * The counters surface the rest of the plugin depends on.
 *
 * Reading the two aggregate totals and resetting them both to zero. Extracted as
 * an interface so collaborators (e.g. the WP-CLI command's logic) can be unit
 * tested against a fake without the concrete {@see Counters} WordPress glue.
 */
interface CountersStore {

	/**
	 * The number of files downloaded from the Origin and saved locally.
	 */
	public function downloaded(): int;

	/**
	 * The number of Negative-cache entries ever created (a running total).
	 */
	public function negativeCount(): int;

	/**
	 * Reset both aggregate totals to zero.
	 */
	public function reset(): void;
}
