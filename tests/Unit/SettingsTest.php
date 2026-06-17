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
