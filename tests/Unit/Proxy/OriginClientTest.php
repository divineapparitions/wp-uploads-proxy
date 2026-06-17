<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Tests\Unit\Proxy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DivineApparitions\UploadsProxy\Config\BasicAuth;
use DivineApparitions\UploadsProxy\Proxy\OriginClient;
use DivineApparitions\UploadsProxy\Proxy\OriginRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DivineApparitions\UploadsProxy\Proxy\OriginClient
 * @covers \DivineApparitions\UploadsProxy\Proxy\OriginResponse
 */
final class OriginClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn ( $r ) => $r['response']['code'] ?? 0
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn ( $r ) => $r['body'] ?? ''
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			static fn ( $r, $h ) => $r['headers'][ $h ] ?? ''
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_fetches_the_built_origin_url_with_auth_headers(): void {
		$request = new OriginRequest(
			'https://origin.test',
			'/wp-content/uploads/2026/06/photo.jpg',
			BasicAuth::fromPair( 'user', 'pass' )
		);

		Functions\expect( 'wp_remote_get' )
			->once()
			->with(
				'https://origin.test/wp-content/uploads/2026/06/photo.jpg',
				\Mockery::type( 'array' )
			)
			->andReturnUsing(
				function ( string $url, array $args ) {
					self::assertSame(
						// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Asserting the RFC 7617 Basic Auth encoding.
						'Basic ' . base64_encode( 'user:pass' ),
						$args['headers']['Authorization'] ?? null
					);

					return [
						'response' => [ 'code' => 200 ],
						'body'     => 'IMAGE-BYTES',
						'headers'  => [ 'content-type' => 'image/jpeg' ],
					];
				}
			);

		$response = ( new OriginClient() )->fetch( $request );

		self::assertSame( 200, $response->statusCode() );
		self::assertSame( 'IMAGE-BYTES', $response->body() );
		self::assertSame( 'image/jpeg', $response->contentType() );
		self::assertTrue( $response->isOk() );
	}

	public function test_wp_error_is_reported_as_a_failed_response(): void {
		Functions\when( 'is_wp_error' )->justReturn( true );
		Functions\expect( 'wp_remote_get' )->once()->andReturn( 'WP_Error' );

		$request  = new OriginRequest( 'https://origin.test', '/wp-content/uploads/x.jpg', null );
		$response = ( new OriginClient() )->fetch( $request );

		self::assertFalse( $response->isOk() );
		self::assertSame( 0, $response->statusCode() );
	}
}
