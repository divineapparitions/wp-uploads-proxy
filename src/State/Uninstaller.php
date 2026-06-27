<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\State;

use DivineApparitions\UploadsProxy\Settings\Settings;

/**
 * The WordPress-free logic behind plugin uninstall.
 *
 * On uninstall the plugin removes all of its own persisted state — the DB
 * settings option, both aggregate counter options, and the Negative-cache
 * transients — while deliberately leaving downloaded media in place. A developer
 * uninstalling the plugin must never lose media they might still need, so this
 * seam owns no filesystem collaborator: it literally cannot delete a file.
 *
 * Separated from the {@see uninstall.php} glue (which bootstraps the autoloader,
 * iterates every site on multisite, and enforces the `WP_UNINSTALL_PLUGIN`
 * guard) so the cleanup behaviour is unit-testable against injected
 * collaborators without a WordPress boot — mirroring the deep-module/glue split
 * used across the codebase.
 */
final class Uninstaller {

	/**
	 * The plugin's complete set of persisted options.
	 *
	 * Built from the canonical key constants so the option names never drift from
	 * where they are written ({@see Settings}, {@see Counters}). There is no
	 * per-file manifest, so this — plus the Negative-cache transients — is the
	 * whole of the plugin's persisted state.
	 *
	 * @return list<string>
	 */
	public static function optionNames(): array {
		return [
			Settings::OPTION_NAME,
			Counters::OPTION_DOWNLOADED,
			Counters::OPTION_NEGATIVE_COUNT,
		];
	}

	/**
	 * @param callable(string): void $deleteOption    Deletes a single option by name (WordPress `delete_option`).
	 * @param callable(): int        $clearTransients Clears the Negative-cache transient family ({@see NegativeCache::clearAll}).
	 */
	public function __construct(
		private $deleteOption,
		private $clearTransients,
	) {
	}

	/**
	 * Remove the plugin's persisted state on the current site.
	 *
	 * Deletes the settings option and both counter options outright (uninstall
	 * removes them entirely rather than zeroing them) and clears every
	 * Negative-cache transient. Never touches the uploads directory.
	 */
	public function purge(): void {
		foreach ( self::optionNames() as $name ) {
			( $this->deleteOption )( $name );
		}

		( $this->clearTransients )();
	}
}
