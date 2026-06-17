<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Tests\Unit\Proxy;

use DivineApparitions\UploadsProxy\Config\BasicAuth;
use DivineApparitions\UploadsProxy\Proxy\OriginRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DivineApparitions\UploadsProxy\Proxy\OriginRequest
 */
final class OriginRequestTest extends TestCase {

	public function test_swaps_only_the_host_and_preserves_the_relative_path(): void {
		$request = new OriginRequest(
			'https://origin.test',
			'/wp-content/uploads/2026/06/photo.jpg',
			null
		);

		self::assertSame(
			'https://origin.test/wp-content/uploads/2026/06/photo.jpg',
			$request->url()
		);
	}

	public function test_preserves_a_derivative_path(): void {
		$request = new OriginRequest(
			'https://origin.test',
			'/wp-content/uploads/2026/06/photo-300x200.jpg',
			null
		);

		self::assertSame(
			'https://origin.test/wp-content/uploads/2026/06/photo-300x200.jpg',
			$request->url()
		);
	}

	public function test_normalises_a_relative_path_without_a_leading_slash(): void {
		$request = new OriginRequest(
			'https://origin.test',
			'wp-content/uploads/2026/06/photo.jpg',
			null
		);

		self::assertSame(
			'https://origin.test/wp-content/uploads/2026/06/photo.jpg',
			$request->url()
		);
	}

	public function test_no_auth_headers_when_no_credentials(): void {
		$request = new OriginRequest(
			'https://origin.test',
			'/wp-content/uploads/file.pdf',
			null
		);

		self::assertSame( [], $request->headers() );
	}

	public function test_attaches_basic_auth_header_when_configured(): void {
		$request = new OriginRequest(
			'https://origin.test',
			'/wp-content/uploads/file.pdf',
			BasicAuth::fromPair( 'user', 'pass' )
		);

		self::assertSame(
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Asserting the RFC 7617 Basic Auth encoding.
			[ 'Authorization' => 'Basic ' . base64_encode( 'user:pass' ) ],
			$request->headers()
		);
	}
}
