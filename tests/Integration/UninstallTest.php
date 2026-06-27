<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Tests\Integration;

use DivineApparitions\UploadsProxy\State\Counters;
use DivineApparitions\UploadsProxy\State\NegativeCache;
use DivineApparitions\UploadsProxy\Settings\Settings;
use WP_UnitTestCase;

/**
 * The plugin's `uninstall.php` against a real WordPress install.
 *
 * Drives the actual uninstall glue — autoloader bootstrap, the
 * {@see \DivineApparitions\UploadsProxy\State\Uninstaller} seam, and the real
 * `delete_option` / {@see NegativeCache::clearAll} — by defining
 * `WP_UNINSTALL_PLUGIN` and including the file the way WordPress does on plugin
 * deletion. Asserts:
 *
 * - the settings option and both counter options are deleted,
 * - every Negative-cache transient is cleared,
 * - downloaded media in the uploads directory is left untouched,
 * - including `uninstall.php` without `WP_UNINSTALL_PLUGIN` defined is a no-op.
 *
 * NOTE: authored-only. This project cannot be mounted into the shared
 * `@wordpress/env` Docker setup here, so this suite is not executed in this
 * environment; it is written to run under `npm run test:integration` where the
 * harness is available.
 */
final class UninstallTest extends WP_UnitTestCase {

	private function uninstallFile(): string {
		return dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	private function runUninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'uploads-proxy/uploads-proxy.php' );
		}

		require $this->uninstallFile();
	}

	public function tear_down(): void {
		delete_option( Settings::OPTION_NAME );
		delete_option( Counters::OPTION_DOWNLOADED );
		delete_option( Counters::OPTION_NEGATIVE_COUNT );
		$this->resetUploads();
		parent::tear_down();
	}

	public function test_uninstall_deletes_all_plugin_options(): void {
		update_option( Settings::OPTION_NAME, [ 'origin_url' => 'https://origin.test' ] );
		update_option( Counters::OPTION_DOWNLOADED, 9, false );
		update_option( Counters::OPTION_NEGATIVE_COUNT, 4, false );

		$this->runUninstall();

		self::assertFalse( get_option( Settings::OPTION_NAME, false ) );
		self::assertFalse( get_option( Counters::OPTION_DOWNLOADED, false ) );
		self::assertFalse( get_option( Counters::OPTION_NEGATIVE_COUNT, false ) );
	}

	public function test_uninstall_clears_negative_cache_transients(): void {
		$cache = new NegativeCache();
		$cache->record( '2026/06/missing-a.jpg' );
		$cache->record( '2026/06/missing-b.jpg' );

		self::assertTrue( $cache->isNegative( '2026/06/missing-a.jpg' ) );

		$this->runUninstall();

		self::assertFalse( $cache->isNegative( '2026/06/missing-a.jpg' ) );
		self::assertFalse( $cache->isNegative( '2026/06/missing-b.jpg' ) );
	}

	public function test_uninstall_never_deletes_downloaded_media(): void {
		$uploads = wp_upload_dir();
		$dir     = $uploads['basedir'] . '/2026/06';
		wp_mkdir_p( $dir );
		$file = $dir . '/keep-me.jpg';
		file_put_contents( $file, 'IMAGE-BYTES' );

		( new NegativeCache() )->record( '2026/06/gone.jpg' );
		update_option( Counters::OPTION_DOWNLOADED, 3, false );

		$this->runUninstall();

		self::assertFileExists( $file );
		self::assertSame( 'IMAGE-BYTES', (string) file_get_contents( $file ) );
	}

	private function resetUploads(): void {
		$uploads = wp_upload_dir();
		$dir     = $uploads['basedir'] . '/2026';

		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( scandir( $dir ) ?: [] as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$this->deleteRecursive( $dir . '/' . $item );
		}
		rmdir( $dir );
	}

	private function deleteRecursive( string $path ): void {
		if ( is_dir( $path ) ) {
			foreach ( scandir( $path ) ?: [] as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$this->deleteRecursive( $path . '/' . $item );
			}
			rmdir( $path );
			return;
		}
		unlink( $path );
	}
}
