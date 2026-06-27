<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Tests\Integration;

use DivineApparitions\UploadsProxy\Cli\CommandRunner;
use DivineApparitions\UploadsProxy\Config\ConfigResolver;
use DivineApparitions\UploadsProxy\Config\EffectiveConfig;
use DivineApparitions\UploadsProxy\Config\Mode;
use DivineApparitions\UploadsProxy\Config\Source;
use DivineApparitions\UploadsProxy\State\Counters;
use DivineApparitions\UploadsProxy\State\NegativeCache;
use WP_UnitTestCase;

/**
 * The `wp uploads-proxy` command's logic against a real WordPress install.
 *
 * Exercises {@see CommandRunner} (the WordPress-free seam the thin WP-CLI adapter
 * delegates to) against real options and transients. Asserts:
 *
 * - `status()` reports the active state, effective Origin, mode + source, and both
 *   counters read from the live resolver and option store.
 * - `clearCache()` removes every Negative-cache transient and resets both counters
 *   to zero.
 * - `clearCache()` never deletes a downloaded file from the uploads directory.
 *
 * NOTE: authored-only. This project cannot be mounted into the shared
 * `@wordpress/env` Docker setup here, so this suite is not executed in this
 * environment; it is written to run under `npm run test:integration` where the
 * harness is available.
 */
final class CliCommandTest extends WP_UnitTestCase {

	private const ORIGIN = 'https://origin.example.test';

	public function tear_down(): void {
		delete_option( Counters::OPTION_DOWNLOADED );
		delete_option( Counters::OPTION_NEGATIVE_COUNT );
		$this->resetUploads();
		parent::tear_down();
	}

	public function test_status_reports_config_and_counters(): void {
		update_option( Counters::OPTION_DOWNLOADED, 9, false );
		update_option( Counters::OPTION_NEGATIVE_COUNT, 4, false );

		$runner = new CommandRunner( $this->resolver(), new Counters(), new NegativeCache() );

		$status = $runner->status();

		self::assertTrue( $status['active'] );
		self::assertSame( self::ORIGIN, $status['origin'] );
		self::assertSame( 'constant', $status['origin_source'] );
		self::assertSame( 'download', $status['mode'] );
		self::assertSame( 9, $status['downloaded'] );
		self::assertSame( 4, $status['negative_cache'] );
	}

	public function test_clear_cache_removes_negative_transients_and_resets_counters(): void {
		$cache = new NegativeCache();
		$cache->record( '2026/06/missing-a.jpg' );
		$cache->record( '2026/06/missing-b.jpg' );
		update_option( Counters::OPTION_DOWNLOADED, 5, false );
		update_option( Counters::OPTION_NEGATIVE_COUNT, 2, false );

		self::assertTrue( $cache->isNegative( '2026/06/missing-a.jpg' ) );

		$runner = new CommandRunner( $this->resolver(), new Counters(), $cache );
		$result = $runner->clearCache();

		self::assertSame( 2, $result['cleared'] );
		self::assertFalse( $cache->isNegative( '2026/06/missing-a.jpg' ) );
		self::assertFalse( $cache->isNegative( '2026/06/missing-b.jpg' ) );
		self::assertSame( 0, ( new Counters() )->downloaded() );
		self::assertSame( 0, ( new Counters() )->negativeCount() );
	}

	public function test_clear_cache_does_not_delete_downloaded_media(): void {
		$uploads = wp_upload_dir();
		$dir     = $uploads['basedir'] . '/2026/06';
		wp_mkdir_p( $dir );
		$file = $dir . '/keep-me.jpg';
		file_put_contents( $file, 'IMAGE-BYTES' );

		( new NegativeCache() )->record( '2026/06/gone.jpg' );

		$runner = new CommandRunner( $this->resolver(), new Counters(), new NegativeCache() );
		$runner->clearCache();

		self::assertFileExists( $file );
		self::assertSame( 'IMAGE-BYTES', (string) file_get_contents( $file ) );
	}

	private function resolver(): ConfigResolver {
		$config = new EffectiveConfig(
			self::ORIGIN,
			Source::Constant,
			Mode::Download,
			Source::Constant,
			null,
			Source::DefaultOff,
			false,
		);

		return new class( $config ) implements ConfigResolver {
			public function __construct( private EffectiveConfig $config ) {}

			public function resolve(): EffectiveConfig {
				return $this->config;
			}
		};
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
