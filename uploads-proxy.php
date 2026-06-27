<?php
/**
 * Uploads Proxy
 *
 * @package           DivineApparitions\UploadsProxy
 * @author            Divine Apparitions
 * @copyright         2026 Divine Apparitions
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Uploads Proxy
 * Plugin URI:        https://github.com/philltran/wp-uploads-proxy
 * Description:       Proxy missing media to a production origin so staging and local environments don't need a full copy of the uploads directory.
 * Version:           0.9.0
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            Divine Apparitions
 * Author URI:        https://github.com/philltran
 * Text Domain:       uploads-proxy
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/philltran/wp-uploads-proxy
 */

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Bail if the plugin is somehow loaded twice.
if ( defined( __NAMESPACE__ . '\VERSION' ) ) {
	return;
}

const VERSION             = '0.9.0';
const MINIMUM_PHP_VERSION = '8.2';
const MINIMUM_WP_VERSION  = '6.5';

/**
 * Render an admin notice and abort bootstrapping.
 *
 * @param string $message Already-translated, plain-text message.
 */
function bootstrap_failure( string $message ): void {
	add_action(
		'admin_notices',
		static function () use ( $message ): void {
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'Uploads Proxy:', 'uploads-proxy' ),
				esc_html( $message )
			);
		}
	);
}

// Guard against unsupported PHP versions.
if ( version_compare( PHP_VERSION, MINIMUM_PHP_VERSION, '<' ) ) {
	bootstrap_failure(
		sprintf(
			/* translators: 1: required PHP version, 2: current PHP version. */
			__( 'requires PHP %1$s or newer. You are running PHP %2$s.', 'uploads-proxy' ),
			MINIMUM_PHP_VERSION,
			PHP_VERSION
		)
	);
	return;
}

// Load the Composer autoloader (vendor/ is not committed; run `composer install`).
$uploads_proxy_autoloader = __DIR__ . '/vendor/autoload.php';

if ( ! is_readable( $uploads_proxy_autoloader ) ) {
	bootstrap_failure(
		__( 'is missing its Composer dependencies. Run `composer install` in the plugin directory.', 'uploads-proxy' )
	);
	return;
}

require_once $uploads_proxy_autoloader;

// Activation / deactivation lifecycle.
register_activation_hook( __FILE__, [ Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );

// Boot the plugin once all plugins are loaded.
add_action(
	'plugins_loaded',
	static function (): void {
		( new Plugin( __FILE__, VERSION ) )->register();
	}
);
