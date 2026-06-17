<?php
/**
 * Integration test bootstrap.
 *
 * Boots the real WordPress test framework provided inside the `@wordpress/env`
 * `tests-cli` container, then loads this plugin as a mu-style plugin so its hooks
 * are wired before any test runs. Unlike the isolated Brain Monkey unit suite
 * (tests/bootstrap.php), these tests run against a live WordPress install.
 *
 * @package DivineApparitions\UploadsProxy
 */

declare(strict_types=1);

// The WordPress test library, mounted by wp-env. WP_TESTS_DIR is exported by the
// tests-cli container; fall back to the conventional path it mounts.
$uploads_proxy_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( false === $uploads_proxy_tests_dir || '' === $uploads_proxy_tests_dir ) {
	$uploads_proxy_tests_dir = '/wordpress-phpunit';
}

$uploads_proxy_functions = $uploads_proxy_tests_dir . '/includes/functions.php';

if ( ! is_readable( $uploads_proxy_functions ) ) {
	fwrite(
		STDERR,
		"Could not find the WordPress test library at {$uploads_proxy_tests_dir}.\n"
		. "Run these tests through `npm run test:integration` (inside wp-env).\n"
	);
	exit( 1 );
}

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once $uploads_proxy_functions;

// Load the plugin before WordPress finishes booting so its hooks register.
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/uploads-proxy.php';
	}
);

require $uploads_proxy_tests_dir . '/includes/bootstrap.php';
