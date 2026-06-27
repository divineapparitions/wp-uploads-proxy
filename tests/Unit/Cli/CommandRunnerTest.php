<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Tests\Unit\Cli;

use DivineApparitions\UploadsProxy\Cli\CommandRunner;
use DivineApparitions\UploadsProxy\Config\ConfigResolver;
use DivineApparitions\UploadsProxy\Config\EffectiveConfig;
use DivineApparitions\UploadsProxy\Config\Mode;
use DivineApparitions\UploadsProxy\Config\Source;
use DivineApparitions\UploadsProxy\State\CountersStore;
use DivineApparitions\UploadsProxy\State\NegativeStore;
use PHPUnit\Framework\TestCase;

/**
 * The WordPress-free logic behind the `wp uploads-proxy` command.
 *
 * `status()` turns the resolver's {@see EffectiveConfig} plus the two counters
 * into a scriptable view-model; `clearCache()` clears the Negative-cache
 * transients and resets the counters, leaving downloaded media untouched. The
 * thin WP-CLI adapter ({@see \DivineApparitions\UploadsProxy\Cli\Command}) only
 * formats these results, so all the behaviour is asserted here without a
 * WordPress (or WP-CLI) boot.
 *
 * @covers \DivineApparitions\UploadsProxy\Cli\CommandRunner
 */
final class CommandRunnerTest extends TestCase {

	private function config(
		string $origin = 'https://origin.test',
		Source $originSource = Source::Constant,
		Mode $mode = Mode::Download,
		Source $modeSource = Source::Constant,
		bool $dbEnabled = false,
	): EffectiveConfig {
		return new EffectiveConfig(
			$origin,
			$originSource,
			$mode,
			$modeSource,
			null,
			Source::DefaultOff,
			$dbEnabled,
		);
	}

	private function resolverReturning( EffectiveConfig $config ): ConfigResolver {
		return new class( $config ) implements ConfigResolver {

			public function __construct( private readonly EffectiveConfig $config ) {
			}

			public function resolve(): EffectiveConfig {
				return $this->config;
			}
		};
	}

	private function counters( int $downloaded = 0, int $negative = 0 ): CountersStore {
		return new class( $downloaded, $negative ) implements CountersStore {

			public int $resetCalls = 0;

			public function __construct(
				private readonly int $downloadedTotal,
				private readonly int $negativeTotal,
			) {
			}

			public function downloaded(): int {
				return $this->downloadedTotal;
			}

			public function negativeCount(): int {
				return $this->negativeTotal;
			}

			public function reset(): void {
				++$this->resetCalls;
			}
		};
	}

	private function negativeCache( int $cleared = 0 ): NegativeStore {
		return new class( $cleared ) implements NegativeStore {

			public int $clearCalls = 0;

			public function __construct( private readonly int $cleared ) {
			}

			public function isNegative( string $relativePath ): bool {
				return false;
			}

			public function record( string $relativePath ): void {
			}

			public function clearAll(): int {
				++$this->clearCalls;
				return $this->cleared;
			}
		};
	}

	public function test_status_reports_active_origin_mode_and_source(): void {
		$runner = new CommandRunner(
			$this->resolverReturning( $this->config( mode: Mode::Hotlink, modeSource: Source::Env ) ),
			$this->counters( downloaded: 12, negative: 3 ),
			$this->negativeCache(),
		);

		$status = $runner->status();

		self::assertTrue( $status['active'] );
		self::assertSame( 'https://origin.test', $status['origin'] );
		self::assertSame( 'hotlink', $status['mode'] );
		self::assertSame( 'env', $status['mode_source'] );
		self::assertSame( 'constant', $status['origin_source'] );
	}

	public function test_status_reports_both_counters(): void {
		$runner = new CommandRunner(
			$this->resolverReturning( $this->config() ),
			$this->counters( downloaded: 12, negative: 3 ),
			$this->negativeCache(),
		);

		$status = $runner->status();

		self::assertSame( 12, $status['downloaded'] );
		self::assertSame( 3, $status['negative_cache'] );
	}

	public function test_status_reports_inactive_when_no_origin(): void {
		$runner = new CommandRunner(
			$this->resolverReturning(
				$this->config( origin: '', originSource: Source::DefaultOff )
			),
			$this->counters(),
			$this->negativeCache(),
		);

		$status = $runner->status();

		self::assertFalse( $status['active'] );
		self::assertSame( '', $status['origin'] );
	}

	public function test_clear_cache_clears_negative_cache_and_resets_counters(): void {
		$counters = $this->counters();
		$cache    = $this->negativeCache( cleared: 5 );

		$runner = new CommandRunner(
			$this->resolverReturning( $this->config() ),
			$counters,
			$cache,
		);

		$result = $runner->clearCache();

		self::assertSame( 1, $cache->clearCalls );
		self::assertSame( 1, $counters->resetCalls );
		self::assertSame( 5, $result['cleared'] );
	}
}
