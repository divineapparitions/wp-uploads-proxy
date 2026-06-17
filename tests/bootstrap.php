<?php
/**
 * PHPUnit bootstrap.
 *
 * These are isolated unit tests — WordPress core is not loaded. WordPress
 * functions are stubbed per-test with Brain Monkey.
 *
 * @package DivineApparitions\UploadsProxy
 */

declare(strict_types=1);

$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! is_readable( $autoload ) ) {
	fwrite( STDERR, "Composer dependencies are missing. Run `composer install` first.\n" );
	exit( 1 );
}

require_once $autoload;
