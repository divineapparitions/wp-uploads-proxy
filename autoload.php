<?php
/**
 * PSR-4 autoloader for Uploads Proxy's own classes.
 *
 * The plugin has no runtime Composer dependencies, so it neither ships nor
 * relies on Composer's generated `vendor/autoload.php`. This self-contained
 * autoloader maps the `DivineApparitions\UploadsProxy\` namespace onto `src/`,
 * letting the plugin run from a plain zip with no build step. Composer is used
 * for development tooling only.
 *
 * @package DivineApparitions\UploadsProxy
 */

declare(strict_types=1);

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'DivineApparitions\\UploadsProxy\\';
		$length = strlen( $prefix );

		// Bail fast on classes outside this plugin's namespace, leaving them for
		// the next registered autoloader (e.g. Composer's, when running tests).
		if ( 0 !== strncmp( $prefix, $class, $length ) ) {
			return;
		}

		$relative = substr( $class, $length );
		$file     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);
