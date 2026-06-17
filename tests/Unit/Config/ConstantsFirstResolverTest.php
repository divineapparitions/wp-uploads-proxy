<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Tests\Unit\Config;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DivineApparitions\UploadsProxy\Config\ConstantsFirstResolver;
use DivineApparitions\UploadsProxy\Config\Mode;
use DivineApparitions\UploadsProxy\Config\Source;
use DivineApparitions\UploadsProxy\Settings\Settings;
use DivineApparitions\UploadsProxy\Tests\Support\FakeEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DivineApparitions\UploadsProxy\Config\ConstantsFirstResolver
 * @covers \DivineApparitions\UploadsProxy\Config\EffectiveConfig
 */
final class ConstantsFirstResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// untrailingslashit is used when normalising the Origin URL.
		Functions\when( 'untrailingslashit' )->alias(
			static fn ( $value ): string => rtrim( (string) $value, '/' )
		);
		// esc_url_raw is a thin escaper here; return the input untouched.
		Functions\when( 'esc_url_raw' )->returnArg();
		// Default: the DB option is empty unless a test seeds it.
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'wp_parse_args' )->alias(
			static fn ( $args, $defaults ): array => array_merge( $defaults, (array) $args )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a resolver over a controllable environment + a stubbed DB option.
	 *
	 * @param array<string, string> $environment Constant/env name => value pairs.
	 * @param array<string, mixed>  $dbOption    The stored option array (defaults merged later).
	 */
	private function resolver( array $environment = [], array $dbOption = [] ): ConstantsFirstResolver {
		if ( [] !== $dbOption ) {
			Functions\when( 'get_option' )->justReturn( $dbOption );
		}

		return new ConstantsFirstResolver( new Settings(), new FakeEnvironment( $environment ) );
	}

	public function test_off_when_nothing_is_configured(): void {
		$config = $this->resolver()->resolve();

		self::assertFalse( $config->isEnabled() );
		self::assertSame( '', $config->origin() );
		self::assertSame( Source::DefaultOff, $config->originSource() );
	}

	public function test_constant_origin_wins_over_env_and_db(): void {
		$config = $this->resolver(
			[
				'UPLOADS_PROXY_ORIGIN'     => 'https://from-constant.test',
				'env:UPLOADS_PROXY_ORIGIN' => 'https://from-env.test',
			],
			[
				'enabled'    => true,
				'origin_url' => 'https://from-db.test',
			]
		)->resolve();

		self::assertSame( 'https://from-constant.test', $config->origin() );
		self::assertSame( Source::Constant, $config->originSource() );
	}

	public function test_env_origin_wins_over_db_when_no_constant(): void {
		$config = $this->resolver(
			[ 'env:UPLOADS_PROXY_ORIGIN' => 'https://from-env.test' ],
			[
				'enabled'    => true,
				'origin_url' => 'https://from-db.test',
			]
		)->resolve();

		self::assertSame( 'https://from-env.test', $config->origin() );
		self::assertSame( Source::Env, $config->originSource() );
	}

	public function test_db_origin_used_when_no_constant_or_env(): void {
		$config = $this->resolver(
			[],
			[
				'enabled'    => true,
				'origin_url' => 'https://from-db.test',
			]
		)->resolve();

		self::assertSame( 'https://from-db.test', $config->origin() );
		self::assertSame( Source::Db, $config->originSource() );
	}

	public function test_trailing_slash_is_stripped_from_origin(): void {
		$config = $this->resolver(
			[ 'UPLOADS_PROXY_ORIGIN' => 'https://origin.test/' ]
		)->resolve();

		self::assertSame( 'https://origin.test', $config->origin() );
	}

	public function test_an_origin_alone_enables_the_proxy(): void {
		// No explicit enabled flag anywhere: a constant Origin is enough.
		$config = $this->resolver(
			[ 'UPLOADS_PROXY_ORIGIN' => 'https://origin.test' ]
		)->resolve();

		self::assertTrue( $config->isEnabled() );
	}

	public function test_not_enabled_when_no_origin_even_if_db_enabled_flag_is_true(): void {
		$config = $this->resolver(
			[],
			[
				'enabled'    => true,
				'origin_url' => '',
			]
		)->resolve();

		self::assertFalse( $config->isEnabled() );
		self::assertSame( '', $config->origin() );
	}

	public function test_db_enabled_flag_false_disables_a_db_origin(): void {
		// The DB option carries its own enabled flag; honoured when the Origin
		// also comes from the DB.
		$config = $this->resolver(
			[],
			[
				'enabled'    => false,
				'origin_url' => 'https://from-db.test',
			]
		)->resolve();

		self::assertFalse( $config->isEnabled() );
	}

	public function test_mode_defaults_to_download(): void {
		$config = $this->resolver(
			[ 'UPLOADS_PROXY_ORIGIN' => 'https://origin.test' ]
		)->resolve();

		self::assertSame( Mode::Download, $config->mode() );
		self::assertSame( Source::DefaultOff, $config->modeSource() );
	}

	public function test_mode_constant_wins_over_env_and_db(): void {
		$config = $this->resolver(
			[
				'UPLOADS_PROXY_ORIGIN'   => 'https://origin.test',
				'UPLOADS_PROXY_MODE'     => 'hotlink',
				'env:UPLOADS_PROXY_MODE' => 'download',
			],
			[
				'origin_url' => 'https://from-db.test',
				'mode'       => 'download',
			]
		)->resolve();

		self::assertSame( Mode::Hotlink, $config->mode() );
		self::assertSame( Source::Constant, $config->modeSource() );
	}

	public function test_mode_env_used_when_no_constant(): void {
		$config = $this->resolver(
			[
				'UPLOADS_PROXY_ORIGIN'   => 'https://origin.test',
				'env:UPLOADS_PROXY_MODE' => 'hotlink',
			]
		)->resolve();

		self::assertSame( Mode::Hotlink, $config->mode() );
		self::assertSame( Source::Env, $config->modeSource() );
	}

	public function test_mode_from_db_when_no_constant_or_env(): void {
		$config = $this->resolver(
			[],
			[
				'origin_url' => 'https://from-db.test',
				'mode'       => 'hotlink',
			]
		)->resolve();

		self::assertSame( Mode::Hotlink, $config->mode() );
		self::assertSame( Source::Db, $config->modeSource() );
	}

	public function test_unrecognised_mode_falls_back_to_download(): void {
		$config = $this->resolver(
			[
				'UPLOADS_PROXY_ORIGIN' => 'https://origin.test',
				'UPLOADS_PROXY_MODE'   => 'nonsense',
			]
		)->resolve();

		self::assertSame( Mode::Download, $config->mode() );
		self::assertSame( Source::DefaultOff, $config->modeSource() );
	}

	public function test_basic_auth_absent_by_default(): void {
		$config = $this->resolver(
			[ 'UPLOADS_PROXY_ORIGIN' => 'https://origin.test' ]
		)->resolve();

		self::assertFalse( $config->hasBasicAuth() );
		self::assertNull( $config->basicAuth() );
		self::assertSame( Source::DefaultOff, $config->basicAuthSource() );
	}

	public function test_basic_auth_constant_wins_over_env_and_db(): void {
		$config = $this->resolver(
			[
				'UPLOADS_PROXY_ORIGIN'        => 'https://origin.test',
				'UPLOADS_PROXY_AUTH_USER'     => 'const-user',
				'UPLOADS_PROXY_AUTH_PASS'     => 'const-pass',
				'env:UPLOADS_PROXY_AUTH_USER' => 'env-user',
				'env:UPLOADS_PROXY_AUTH_PASS' => 'env-pass',
			],
			[
				'origin_url'      => 'https://from-db.test',
				'basic_auth_user' => 'db-user',
				'basic_auth_pass' => 'db-pass',
			]
		)->resolve();

		self::assertTrue( $config->hasBasicAuth() );
		$auth = $config->basicAuth();
		self::assertNotNull( $auth );
		self::assertSame( 'const-user', $auth->username() );
		self::assertSame( 'const-pass', $auth->password() );
		self::assertSame( Source::Constant, $config->basicAuthSource() );
	}

	public function test_basic_auth_from_env_when_no_constant(): void {
		$config = $this->resolver(
			[
				'UPLOADS_PROXY_ORIGIN'        => 'https://origin.test',
				'env:UPLOADS_PROXY_AUTH_USER' => 'env-user',
				'env:UPLOADS_PROXY_AUTH_PASS' => 'env-pass',
			]
		)->resolve();

		$auth = $config->basicAuth();
		self::assertNotNull( $auth );
		self::assertSame( 'env-user', $auth->username() );
		self::assertSame( Source::Env, $config->basicAuthSource() );
	}

	public function test_basic_auth_from_db_when_no_constant_or_env(): void {
		$config = $this->resolver(
			[],
			[
				'origin_url'      => 'https://from-db.test',
				'basic_auth_user' => 'db-user',
				'basic_auth_pass' => 'db-pass',
			]
		)->resolve();

		$auth = $config->basicAuth();
		self::assertNotNull( $auth );
		self::assertSame( 'db-user', $auth->username() );
		self::assertSame( 'db-pass', $auth->password() );
		self::assertSame( Source::Db, $config->basicAuthSource() );
	}

	public function test_partial_basic_auth_credentials_are_ignored(): void {
		// A username without a password (or vice versa) is not valid Basic Auth.
		$config = $this->resolver(
			[
				'UPLOADS_PROXY_ORIGIN'    => 'https://origin.test',
				'UPLOADS_PROXY_AUTH_USER' => 'lonely-user',
			]
		)->resolve();

		self::assertFalse( $config->hasBasicAuth() );
		self::assertNull( $config->basicAuth() );
	}
}
