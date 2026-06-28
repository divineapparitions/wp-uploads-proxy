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

$uploads_proxy_autoload = __DIR__ . '/vendor/autoload.php';

if ( is_readable( $uploads_proxy_autoload ) ) {
	require_once $uploads_proxy_autoload;
}

/**
 * Purge the plugin's persisted state on the current site.
 *
 * Prefers the {@see Uninstaller} seam (so the option keys and the transient
 * clear stay in one canonical place). If the Composer autoloader is unavailable
 * — uninstall.php must stay robust even when `vendor/` has been pruned — it
 * falls back to the literal option names and a self-contained transient delete,
 * mirroring {@see NegativeCache::clearAll}. Neither path touches the uploads
 * directory.
 */
if ( ! function_exists( 'uploads_proxy_purge_site_state' ) ) {
	function uploads_proxy_purge_site_state(): void {
		if ( class_exists( Uninstaller::class ) && class_exists( NegativeCache::class ) ) {
			$negative_cache = new NegativeCache();

			( new Uninstaller(
				static function ( string $name ): void {
					delete_option( $name );
				},
				static fn (): int => $negative_cache->clearAll(),
			) )->purge();

			return;
		}

		// Self-contained fallback when vendor/ is missing: literal option/transient keys.
		delete_option( 'uploads_proxy_settings' );
		delete_option( 'uploads_proxy_downloaded_count' );
		delete_option( 'uploads_proxy_negative_count' );

		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_uploads_proxy_neg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_uploads_proxy_neg_' ) . '%'
			)
		);
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
