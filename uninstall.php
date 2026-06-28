<?php
/**
 * Uninstall handler for Uploads Proxy.
 *
 * Runs when the plugin is deleted from the WordPress admin. Removes all of the
 * plugin's own persisted state — the DB settings option, both aggregate counter
 * options, and the Negative-cache transients — on the current site and, on
 * multisite, every site. Downloaded media in the uploads directory is
 * deliberately left in place: a developer uninstalling the plugin must never lose
 * media they might still need.
 *
 * This file is the thin glue around the WordPress-free
 * {@see DivineApparitions\UploadsProxy\State\Uninstaller} seam: it enforces the
 * uninstall-context guard, iterates sites on multisite, and supplies the real
 * `delete_option` and Negative-cache clear. The cleanup logic and its
 * "never delete a file" guarantee are unit-tested against the seam.
 *
 * @package DivineApparitions\UploadsProxy
 */

declare(strict_types=1);

use DivineApparitions\UploadsProxy\State\NegativeCache;
use DivineApparitions\UploadsProxy\State\Uninstaller;

// Only run in the context of a genuine uninstall request.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/autoload.php';

/**
 * Purge the plugin's persisted state on the current site.
 *
 * Delegates to the {@see Uninstaller} seam so the option keys and the
 * Negative-cache clear stay in one canonical place. The seam owns no filesystem
 * collaborator, so it never touches the uploads directory.
 */
if ( ! function_exists( 'uploads_proxy_purge_site_state' ) ) {
	function uploads_proxy_purge_site_state(): void {
		$negative_cache = new NegativeCache();

		( new Uninstaller(
			static function ( string $name ): void {
				delete_option( $name );
			},
			static fn (): int => $negative_cache->clearAll(),
		) )->purge();
	}
}

if ( is_multisite() ) {
	$uploads_proxy_site_ids = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	);

	foreach ( $uploads_proxy_site_ids as $uploads_proxy_site_id ) {
		switch_to_blog( (int) $uploads_proxy_site_id );
		uploads_proxy_purge_site_state();
		restore_current_blog();
	}
} else {
	uploads_proxy_purge_site_state();
}
