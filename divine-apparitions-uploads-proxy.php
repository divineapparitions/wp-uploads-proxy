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
 * Plugin Name:       Divine Apparitions Uploads Proxy
 * Plugin URI:        https://github.com/divineapparitions/wp-uploads-proxy
 * Description:       Proxy missing media to a production origin so staging and local environments don't need a full copy of the uploads directory.
 * Version:           0.10.0
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            Divine Apparitions
 * Author URI:        https://github.com/divineapparitions
 * Text Domain:       divine-apparitions-uploads-proxy
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
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

const VERSION             = '0.10.0';
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
				esc_html__( 'Uploads Proxy:', 'divine-apparitions-uploads-proxy' ),
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
			__( 'requires PHP %1$s or newer. You are running PHP %2$s.', 'divine-apparitions-uploads-proxy' ),
			MINIMUM_PHP_VERSION,
			PHP_VERSION
		)
	);
	return;
}

// Guard against unsupported WordPress versions. The `Requires at least` header
// blocks admin-driven activation, but a git/manual install on older WordPress
// would otherwise boot unguarded.
if ( version_compare( get_bloginfo( 'version' ), MINIMUM_WP_VERSION, '<' ) ) {
	bootstrap_failure(
		sprintf(
			/* translators: 1: required WordPress version, 2: current WordPress version. */
			__( 'requires WordPress %1$s or newer. You are running WordPress %2$s.', 'divine-apparitions-uploads-proxy' ),
			MINIMUM_WP_VERSION,
			get_bloginfo( 'version' )
		)
	);
	return;
}

// Register the plugin's PSR-4 autoloader. There are no runtime Composer
// dependencies, so loading is self-contained and needs no `vendor/`.
require_once __DIR__ . '/autoload.php';

// Activation / deactivation lifecycle.
register_activation_hook( __FILE__, [ Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );

// Boot the plugin once all plugins are loaded.
add_action(
	'plugins_loaded',
	static function (): void {
		( new Plugin( VERSION ) )->register();
	}
);
