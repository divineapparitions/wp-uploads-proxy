<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DivineApparitions\UploadsProxy\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DivineApparitions\UploadsProxy\Settings\Settings
 */
final class SettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// esc_url_raw / sanitize_text_field are thin escapers here; pass through.
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		// sanitize() now reads the stored password to preserve it on an empty
		// submit (write-only field), so the DB-read seam must be stubbed.
		// Default: nothing stored. Individual tests override get_option.
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'wp_parse_args' )->alias(
			static fn ( $args, $defaults ): array => array_merge( $defaults, is_array( $args ) ? $args : [] )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_sanitize_normalises_truthy_input(): void {
		$result = ( new Settings() )->sanitize(
			[
				'enabled'         => '1',
				'origin_url'      => '  https://example.com  ',
				'mode'            => 'hotlink',
				'basic_auth_user' => 'user',
				'basic_auth_pass' => 's3cret',
			]
		);

		self::assertTrue( $result['enabled'] );
		self::assertSame( 'https://example.com', $result['origin_url'] );
		self::assertSame( 'hotlink', $result['mode'] );
		self::assertSame( 'user', $result['basic_auth_user'] );
		self::assertSame( 's3cret', $result['basic_auth_pass'] );
	}

	public function test_sanitize_treats_missing_fields_as_defaults(): void {
		$result = ( new Settings() )->sanitize( [] );

		self::assertFalse( $result['enabled'] );
		self::assertSame( '', $result['origin_url'] );
		self::assertSame( 'download', $result['mode'] );
		self::assertSame( '', $result['basic_auth_user'] );
		self::assertSame( '', $result['basic_auth_pass'] );
	}

	public function test_sanitize_preserves_stored_password_on_empty_submit(): void {
		// The password field is write-only: an empty submission must keep
		// the stored password rather than blanking it.
		Functions\when( 'get_option' )->justReturn( [ 'basic_auth_pass' => 'stored-secret' ] );

		$result = ( new Settings() )->sanitize(
			[
				'basic_auth_user' => 'user',
				'basic_auth_pass' => '',
			]
		);

		self::assertSame( 'stored-secret', $result['basic_auth_pass'] );
	}

	public function test_sanitize_preserves_stored_password_when_field_absent(): void {
		Functions\when( 'get_option' )->justReturn( [ 'basic_auth_pass' => 'stored-secret' ] );

		$result = ( new Settings() )->sanitize( [ 'basic_auth_user' => 'user' ] );

		self::assertSame( 'stored-secret', $result['basic_auth_pass'] );
	}

	public function test_sanitize_overwrites_password_when_a_new_one_is_submitted(): void {
		Functions\when( 'get_option' )->justReturn( [ 'basic_auth_pass' => 'stored-secret' ] );

		$result = ( new Settings() )->sanitize(
			[
				'basic_auth_user' => 'user',
				'basic_auth_pass' => 'new-secret',
			]
		);

		self::assertSame( 'new-secret', $result['basic_auth_pass'] );
	}

	public function test_sanitize_coerces_unknown_mode_to_download(): void {
		$result = ( new Settings() )->sanitize( [ 'mode' => 'nonsense' ] );

		self::assertSame( 'download', $result['mode'] );
	}

	public function test_sanitize_handles_non_array_input(): void {
		$result = ( new Settings() )->sanitize( 'unexpected' );

		self::assertSame(
			[
				'enabled'         => false,
				'origin_url'      => '',
				'mode'            => 'download',
				'basic_auth_user' => '',
				'basic_auth_pass' => '',
			],
			$result
		);
	}
}
