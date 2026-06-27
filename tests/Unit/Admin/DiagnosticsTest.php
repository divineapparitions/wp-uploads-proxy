<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Tests\Unit\Admin;

use DivineApparitions\UploadsProxy\Admin\Diagnostics;
use DivineApparitions\UploadsProxy\Config\BasicAuth;
use DivineApparitions\UploadsProxy\Config\EffectiveConfig;
use DivineApparitions\UploadsProxy\Config\Mode;
use DivineApparitions\UploadsProxy\Config\Source;
use PHPUnit\Framework\TestCase;

/**
 * The diagnostics page's decision logic: active status, each value's resolved
 * source, the editable-vs-read-only rule (ADR-0002), and the counters it shows.
 *
 * A WordPress-free view-model, so these assertions need no Brain Monkey boot.
 *
 * @covers \DivineApparitions\UploadsProxy\Admin\Diagnostics
 */
final class DiagnosticsTest extends TestCase {

	private function diagnostics(
		EffectiveConfig $config,
		int $downloaded = 0,
		int $negative = 0,
	): Diagnostics {
		return new Diagnostics( $config, $downloaded, $negative );
	}

	private function config(
		string $origin = 'https://origin.test',
		Source $originSource = Source::Constant,
		Mode $mode = Mode::Download,
		Source $modeSource = Source::Constant,
		?BasicAuth $basicAuth = null,
		Source $basicAuthSource = Source::DefaultOff,
		bool $dbEnabled = false,
	): EffectiveConfig {
		return new EffectiveConfig(
			$origin,
			$originSource,
			$mode,
			$modeSource,
			$basicAuth,
			$basicAuthSource,
			$dbEnabled,
		);
	}

	public function test_is_active_reflects_effective_config_enabled(): void {
		$active = $this->diagnostics( $this->config() );
		self::assertTrue( $active->isActive() );

		$inactive = $this->diagnostics(
			$this->config( origin: '', originSource: Source::DefaultOff )
		);
		self::assertFalse( $inactive->isActive() );
	}

	public function test_origin_value_and_source_pass_through(): void {
		$d = $this->diagnostics(
			$this->config( origin: 'https://o.test', originSource: Source::Db, dbEnabled: true )
		);

		self::assertSame( 'https://o.test', $d->origin() );
		self::assertSame( Source::Db, $d->originSource() );
	}

	public function test_mode_value_and_source_pass_through(): void {
		$d = $this->diagnostics(
			$this->config( mode: Mode::Hotlink, modeSource: Source::Env )
		);

		self::assertSame( Mode::Hotlink, $d->mode() );
		self::assertSame( Source::Env, $d->modeSource() );
	}

	public function test_basic_auth_source_passes_through(): void {
		$auth = BasicAuth::fromPair( 'user', 'pass' );
		$d    = $this->diagnostics(
			$this->config( basicAuth: $auth, basicAuthSource: Source::Db )
		);

		self::assertTrue( $d->hasBasicAuth() );
		self::assertSame( Source::Db, $d->basicAuthSource() );
	}

	public function test_basic_auth_username_is_exposed_for_the_read_only_label(): void {
		$auth = BasicAuth::fromPair( 'origin-user', 'origin-pass' );
		$d    = $this->diagnostics(
			$this->config( basicAuth: $auth, basicAuthSource: Source::Constant )
		);

		self::assertSame( 'origin-user', $d->basicAuthUsername() );
	}

	public function test_basic_auth_username_is_empty_when_no_credentials(): void {
		$d = $this->diagnostics( $this->config() );

		self::assertSame( '', $d->basicAuthUsername() );
	}

	public function test_fields_are_read_only_when_value_comes_from_a_constant(): void {
		$d = $this->diagnostics(
			$this->config(
				originSource: Source::Constant,
				modeSource: Source::Constant,
				basicAuthSource: Source::Constant,
			)
		);

		self::assertFalse( $d->isOriginEditable() );
		self::assertFalse( $d->isModeEditable() );
		self::assertFalse( $d->isBasicAuthEditable() );
	}

	public function test_fields_are_read_only_when_value_comes_from_an_env_var(): void {
		$d = $this->diagnostics(
			$this->config(
				originSource: Source::Env,
				modeSource: Source::Env,
				basicAuthSource: Source::Env,
			)
		);

		self::assertFalse( $d->isOriginEditable() );
		self::assertFalse( $d->isModeEditable() );
		self::assertFalse( $d->isBasicAuthEditable() );
	}

	public function test_fields_are_editable_when_value_comes_from_the_db(): void {
		$d = $this->diagnostics(
			$this->config(
				originSource: Source::Db,
				modeSource: Source::Db,
				basicAuthSource: Source::Db,
				dbEnabled: true,
			)
		);

		self::assertTrue( $d->isOriginEditable() );
		self::assertTrue( $d->isModeEditable() );
		self::assertTrue( $d->isBasicAuthEditable() );
	}

	public function test_fields_are_editable_when_nothing_is_configured(): void {
		$d = $this->diagnostics(
			$this->config(
				origin: '',
				originSource: Source::DefaultOff,
				modeSource: Source::DefaultOff,
				basicAuthSource: Source::DefaultOff,
			)
		);

		self::assertTrue( $d->isOriginEditable() );
		self::assertTrue( $d->isModeEditable() );
		self::assertTrue( $d->isBasicAuthEditable() );
	}

	public function test_counters_pass_through(): void {
		$d = $this->diagnostics( $this->config(), downloaded: 12, negative: 3 );

		self::assertSame( 12, $d->downloadedCount() );
		self::assertSame( 3, $d->negativeCacheCount() );
	}
}
