<?php
/**
 * Uninstall handler for Uploads Proxy.
 *
 * Runs when the plugin is deleted from the WordPress admin. Removes the
 * plugin's stored options on the current site and, on multisite, every site.
 *
 * @package DivineApparitions\UploadsProxy
 */

declare(strict_types=1);

// Only run in the context of a genuine uninstall request.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

const UPLOADS_PROXY_OPTION = 'uploads_proxy_settings';

/**
 * Delete the plugin's option on the current site.
 */
function uploads_proxy_delete_options(): void {
	delete_option( UPLOADS_PROXY_OPTION );
}

if ( is_multisite() ) {
	$site_ids = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		uploads_proxy_delete_options();
		restore_current_blog();
	}
} else {
	uploads_proxy_delete_options();
}
