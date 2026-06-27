<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Cli;

use DivineApparitions\UploadsProxy\Admin\Diagnostics;
use DivineApparitions\UploadsProxy\Config\ConfigResolver;
use DivineApparitions\UploadsProxy\State\CountersStore;
use DivineApparitions\UploadsProxy\State\NegativeStore;

/**
 * The WordPress-free logic behind the `wp uploads-proxy` command.
 *
 * Separated from the WP-CLI glue ({@see Command}) so its behaviour can be unit
 * tested without a WordPress (or WP-CLI) boot, mirroring how the rest of the
 * codebase keeps deep modules apart from WordPress adapters. `status()` turns the
 * resolved configuration plus the two counters into a flat, scriptable
 * view-model; `clearCache()` clears the Negative-cache transients and resets the
 * counters, and never deletes downloaded media.
 */
final class CommandRunner {

	public function __construct(
		private readonly ConfigResolver $resolver,
		private readonly CountersStore $counters,
		private readonly NegativeStore $negativeCache,
	) {
	}

	/**
	 * Build a scriptable status view-model.
	 *
	 * Reuses the {@see Diagnostics} view-model the settings page already computes,
	 * so the CLI and the admin page report the same effective configuration and
	 * sources rather than each re-deriving them.
	 *
	 * @return array{
	 *     active: bool,
	 *     origin: string,
	 *     origin_source: string,
	 *     mode: string,
	 *     mode_source: string,
	 *     downloaded: int,
	 *     negative_cache: int
	 * }
	 */
	public function status(): array {
		$diagnostics = new Diagnostics(
			$this->resolver->resolve(),
			$this->counters->downloaded(),
			$this->counters->negativeCount(),
		);

		return [
			'active'         => $diagnostics->isActive(),
			'origin'         => $diagnostics->origin(),
			'origin_source'  => $diagnostics->originSource()->value,
			'mode'           => $diagnostics->mode()->value,
			'mode_source'    => $diagnostics->modeSource()->value,
			'downloaded'     => $diagnostics->downloadedCount(),
			'negative_cache' => $diagnostics->negativeCacheCount(),
		];
	}

	/**
	 * Clear the Negative cache and reset the counters, giving CI a clean slate.
	 *
	 * Removes every Negative-cache transient and zeroes both counters. It never
	 * deletes downloaded media from the uploads directory.
	 *
	 * @return array{cleared: int} The number of Negative-cache entries removed.
	 */
	public function clearCache(): array {
		$cleared = $this->negativeCache->clearAll();
		$this->counters->reset();

		return [ 'cleared' => $cleared ];
	}
}
