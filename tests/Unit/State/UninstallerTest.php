<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Tests\Unit\State;

use DivineApparitions\UploadsProxy\State\Counters;
use DivineApparitions\UploadsProxy\State\Uninstaller;
use DivineApparitions\UploadsProxy\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * The WordPress-free logic behind plugin uninstall.
 *
 * `purge()` deletes the plugin's settings option and both counter options and
 * clears the Negative-cache transients, given the WordPress delete primitive and
 * the transient-clear as injected collaborators. It owns no filesystem
 * collaborator, so it can never delete downloaded media — uninstall must leave
 * the uploads directory untouched. The thin glue ({@see uninstall.php}) supplies
 * the real `delete_option` and {@see \DivineApparitions\UploadsProxy\State\NegativeCache::clearAll}
 * and handles the multisite iteration and the `WP_UNINSTALL_PLUGIN` guard, so all
 * the cleanup behaviour is asserted here without a WordPress boot.
 *
 * @covers \DivineApparitions\UploadsProxy\State\Uninstaller
 */
final class UninstallerTest extends TestCase {

	/**
	 * @return array{deleted: list<string>, cleared: int, deleteOption: callable, clearTransients: callable}
	 */
	private function spies(): array {
		$deleted = [];
		$cleared = 0;
		$spies   = [
			'deleted' => &$deleted,
			'cleared' => &$cleared,
		];

		$spies['deleteOption']    = static function ( string $name ) use ( &$deleted ): void {
			$deleted[] = $name;
		};
		$spies['clearTransients'] = static function () use ( &$cleared ): int {
			++$cleared;
			return 4;
		};

		return $spies;
	}

	public function test_purge_deletes_the_settings_option(): void {
		$spies = $this->spies();

		( new Uninstaller( $spies['deleteOption'], $spies['clearTransients'] ) )->purge();

		self::assertContains( Settings::OPTION_NAME, $spies['deleted'] );
	}

	public function test_purge_deletes_both_counter_options(): void {
		$spies = $this->spies();

		( new Uninstaller( $spies['deleteOption'], $spies['clearTransients'] ) )->purge();

		self::assertContains( Counters::OPTION_DOWNLOADED, $spies['deleted'] );
		self::assertContains( Counters::OPTION_NEGATIVE_COUNT, $spies['deleted'] );
	}

	public function test_purge_clears_the_negative_cache_transients(): void {
		$spies = $this->spies();

		( new Uninstaller( $spies['deleteOption'], $spies['clearTransients'] ) )->purge();

		self::assertSame( 1, $spies['cleared'] );
	}

	public function test_purge_deletes_exactly_the_three_known_options(): void {
		$spies = $this->spies();

		( new Uninstaller( $spies['deleteOption'], $spies['clearTransients'] ) )->purge();

		\sort( $spies['deleted'] );
		$expected = [
			Settings::OPTION_NAME,
			Counters::OPTION_DOWNLOADED,
			Counters::OPTION_NEGATIVE_COUNT,
		];
		\sort( $expected );

		self::assertSame( $expected, $spies['deleted'] );
	}
}
