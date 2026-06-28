<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Tests\Unit\Admin;

use DivineApparitions\UploadsProxy\Admin\OriginProbe;
use DivineApparitions\UploadsProxy\Config\BasicAuth;
use DivineApparitions\UploadsProxy\Config\EffectiveConfig;
use DivineApparitions\UploadsProxy\Config\Mode;
use DivineApparitions\UploadsProxy\Config\Source;
use DivineApparitions\UploadsProxy\Proxy\OriginFetcher;
use DivineApparitions\UploadsProxy\Proxy\OriginRequest;
use DivineApparitions\UploadsProxy\Proxy\OriginResponse;
use PHPUnit\Framework\TestCase;

/**
 * The "Test Origin connection" probe (issue #7).
 *
 * Reachability is graded strictly: only a 2xx status counts as reachable; every
 * 4xx, 5xx, and a transport error (status 0) is unreachable, but the actual code
 * is preserved so the page can tell "HTTP 403" from "no response" apart. The
 * probe reuses the existing Origin seam ({@see OriginRequest}/{@see OriginFetcher})
 * rather than making its own HTTP call.
 *
 * @covers \DivineApparitions\UploadsProxy\Admin\OriginProbe
 * @covers \DivineApparitions\UploadsProxy\Admin\ProbeResult
 */
final class OriginProbeTest extends TestCase {

	private function config( string $origin = 'https://origin.test', ?BasicAuth $basicAuth = null ): EffectiveConfig {
		return new EffectiveConfig(
			$origin,
			Source::Constant,
			Mode::Download,
			Source::Constant,
			$basicAuth,
			null === $basicAuth ? Source::DefaultOff : Source::Constant,
			false,
		);
	}

	/**
	 * A fetcher that records the request it was given and returns a fixed response.
	 *
	 * @return array{0: OriginFetcher, 1: \stdClass}
	 */
	private function recordingFetcher( OriginResponse $response ): array {
		$spy          = new \stdClass();
		$spy->request = null;
		$fetcher      = new class( $response, $spy ) implements OriginFetcher {
			public function __construct(
				private OriginResponse $response,
				private \stdClass $spy,
			) {}

			public function fetch( OriginRequest $request ): OriginResponse {
				$this->spy->request = $request;
				return $this->response;
			}
		};

		return [ $fetcher, $spy ];
	}

	public function test_probes_the_origin_root_with_basic_auth_attached(): void {
		[ $fetcher, $spy ] = $this->recordingFetcher( new OriginResponse( 200, '', '' ) );
		$probe             = new OriginProbe( $fetcher );

		$probe->probe( $this->config( basicAuth: BasicAuth::fromPair( 'u', 'p' ) ) );

		self::assertInstanceOf( OriginRequest::class, $spy->request );
		self::assertSame( 'https://origin.test/', $spy->request->url() );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Asserting the RFC 7617 Basic Auth encoding.
		self::assertSame( 'Basic ' . base64_encode( 'u:p' ), $spy->request->headers()['Authorization'] ?? null );
	}

	public function test_2xx_response_is_reachable(): void {
		[ $fetcher ] = $this->recordingFetcher( new OriginResponse( 200, '', '' ) );

		$result = ( new OriginProbe( $fetcher ) )->probe( $this->config() );

		self::assertTrue( $result->isReachable() );
		self::assertSame( 200, $result->statusCode() );
	}

	public function test_any_2xx_is_reachable_not_only_200(): void {
		[ $fetcher ] = $this->recordingFetcher( new OriginResponse( 204, '', '' ) );

		$result = ( new OriginProbe( $fetcher ) )->probe( $this->config() );

		self::assertTrue( $result->isReachable() );
	}

	public function test_4xx_is_unreachable_but_reports_the_status_code(): void {
		[ $fetcher ] = $this->recordingFetcher( new OriginResponse( 403, '', '' ) );

		$result = ( new OriginProbe( $fetcher ) )->probe( $this->config() );

		self::assertFalse( $result->isReachable() );
		self::assertSame( 403, $result->statusCode() );
		self::assertTrue( $result->hasResponse() );
	}

	public function test_5xx_is_unreachable(): void {
		[ $fetcher ] = $this->recordingFetcher( new OriginResponse( 503, '', '' ) );

		$result = ( new OriginProbe( $fetcher ) )->probe( $this->config() );

		self::assertFalse( $result->isReachable() );
		self::assertSame( 503, $result->statusCode() );
		self::assertTrue( $result->hasResponse() );
	}

	public function test_3xx_is_unreachable_only_2xx_counts(): void {
		[ $fetcher ] = $this->recordingFetcher( new OriginResponse( 302, '', '' ) );

		$result = ( new OriginProbe( $fetcher ) )->probe( $this->config() );

		self::assertFalse( $result->isReachable() );
	}

	public function test_transport_error_is_unreachable_with_no_response(): void {
		[ $fetcher ] = $this->recordingFetcher( new OriginResponse( 0, '', '' ) );

		$result = ( new OriginProbe( $fetcher ) )->probe( $this->config() );

		self::assertFalse( $result->isReachable() );
		self::assertSame( 0, $result->statusCode() );
		self::assertFalse( $result->hasResponse() );
	}
}
