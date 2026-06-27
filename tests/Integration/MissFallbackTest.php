<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Tests\Integration;

use DivineApparitions\UploadsProxy\Config\ConfigResolver;
use DivineApparitions\UploadsProxy\Config\EffectiveConfig;
use DivineApparitions\UploadsProxy\Config\Mode;
use DivineApparitions\UploadsProxy\Config\Source;
use DivineApparitions\UploadsProxy\Proxy\FileWriter;
use DivineApparitions\UploadsProxy\Proxy\OriginClient;
use DivineApparitions\UploadsProxy\Proxy\RequestHandler;
use DivineApparitions\UploadsProxy\Proxy\Responder;
use DivineApparitions\UploadsProxy\Proxy\UploadsScope;
use DivineApparitions\UploadsProxy\State\Counters;
use DivineApparitions\UploadsProxy\State\NegativeCache;
use WP_UnitTestCase;

/**
 * Miss-fallback + Negative-cache behaviour against a real WordPress install.
 *
 * Boots WordPress, simulates requests for missing Uploads paths, and mocks the
 * Origin through the live HTTP layer via `pre_http_request`. Asserts:
 *
 * - Origin 404/410 → local 404 with `X-Uploads-Proxy: negative`, a Negative-cache
 *   transient created, and the negative counter incremented.
 * - A repeat Miss for a negative-cached path → local 404 with no outbound Origin
 *   request (short-circuit).
 * - Origin 5xx / timeout → local 404, no Negative-cache entry created (so the next
 *   request retries).
 *
 * The terminal serve step is captured through a test {@see Responder} so it does not
 * `exit` out of PHPUnit; everything else (the HTTP layer, real WordPress transients,
 * and the option-based counters) runs through the real code path.
 */
final class MissFallbackTest extends WP_UnitTestCase {

	private const ORIGIN = 'https://origin.example.test';

	/**
	 * Origin URLs fetched during the test.
	 *
	 * @var list<string>
	 */
	private array $fetched = [];

	/**
	 * Status codes the mocked Origin will return, keyed by uploads-relative path.
	 *
	 * @var array<string, int>
	 */
	private array $originStatus = [];

	public function set_up(): void {
		parent::set_up();

		$this->fetched      = [];
		$this->originStatus = [];

		add_filter( 'pre_http_request', [ $this, 'mockOrigin' ], 10, 3 );

		$this->resetUploads();
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', [ $this, 'mockOrigin' ], 10 );
		$this->resetUploads();
		delete_option( Counters::OPTION_DOWNLOADED );
		delete_option( Counters::OPTION_NEGATIVE_COUNT );

		// Remove any negative-cache transients written during the test.
		// WordPress test suite resets the DB between tests, so transients set via
		// set_transient (which go to the options table) are rolled back automatically.
		// This explicit cleanup is a belt-and-suspenders guard.
		parent::tear_down();
	}

	/**
	 * Intercept outbound HTTP: return the configured status for known paths, 200 otherwise.
	 *
	 * @param mixed                $preempt Short-circuit value.
	 * @param array<string, mixed> $args    Request args.
	 * @param string               $url     Requested URL.
	 *
	 * @return array<string, mixed>
	 */
	public function mockOrigin( $preempt, $args, $url ) {
		$this->fetched[] = $url;

		$relative = ltrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$relative = $this->relativeToUploads( $relative );

		if ( null !== $relative && isset( $this->originStatus[ $relative ] ) ) {
			$code = $this->originStatus[ $relative ];

			return [
				'response' => [
					'code'    => $code,
					'message' => '',
				],
				'body'     => '',
				'headers'  => [],
			];
		}

		return [
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'body'     => 'FALLBACK-BYTES',
			'headers'  => [ 'content-type' => 'image/jpeg' ],
		];
	}

	// -------------------------------------------------------------------------
	// Origin 404 / 410 → Negative-cache
	// -------------------------------------------------------------------------

	public function test_origin_404_creates_negative_transient_and_serves_404(): void {
		$relative                        = '2026/06/missing.jpg';
		$this->originStatus[ $relative ] = 404;

		$responder = $this->capturingResponder();
		$handled   = $this->handler( $responder )->handle( '/' . $this->uploadsUrlPath() . '/' . $relative );

		self::assertTrue( $handled );
		self::assertSame( 'negative', $responder->served404['xUploadsProxy'] ?? null );
		self::assertFileDoesNotExist( $this->absoluteUpload( $relative ) );

		// Transient must exist.
		$transientKey = 'uploads_proxy_neg_' . md5( $relative );
		self::assertNotFalse( get_transient( $transientKey ), 'Negative-cache transient must be set after a 404.' );
	}

	public function test_origin_410_creates_negative_transient_and_serves_404(): void {
		$relative                        = '2026/06/deleted.jpg';
		$this->originStatus[ $relative ] = 410;

		$responder = $this->capturingResponder();
		$handled   = $this->handler( $responder )->handle( '/' . $this->uploadsUrlPath() . '/' . $relative );

		self::assertTrue( $handled );
		self::assertSame( 'negative', $responder->served404['xUploadsProxy'] ?? null );

		$transientKey = 'uploads_proxy_neg_' . md5( $relative );
		self::assertNotFalse( get_transient( $transientKey ), 'Negative-cache transient must be set after a 410.' );
	}

	public function test_origin_404_increments_negative_counter(): void {
		$relative                        = '2026/06/missing.jpg';
		$this->originStatus[ $relative ] = 404;

		$this->handler( $this->capturingResponder() )->handle( '/' . $this->uploadsUrlPath() . '/' . $relative );

		self::assertSame( 1, ( new Counters() )->negativeCount() );
	}

	// -------------------------------------------------------------------------
	// Negative-cache short-circuit (repeat Miss)
	// -------------------------------------------------------------------------

	public function test_repeat_miss_returns_404_without_origin_fetch(): void {
		$relative                        = '2026/06/missing.jpg';
		$this->originStatus[ $relative ] = 404;

		$handler = $this->handler( $this->capturingResponder() );

		// First request: hits the Origin, gets 404, records negative cache.
		$handler->handle( '/' . $this->uploadsUrlPath() . '/' . $relative );

		$fetchCountAfterFirst = count( $this->fetched );
		self::assertSame( 1, $fetchCountAfterFirst );

		// Second request: must short-circuit — no second Origin fetch.
		$responder2 = $this->capturingResponder();
		$handled2   = $this->handler( $responder2 )->handle( '/' . $this->uploadsUrlPath() . '/' . $relative );

		self::assertTrue( $handled2 );
		self::assertSame( 'negative', $responder2->served404['xUploadsProxy'] ?? null );
		self::assertCount( 1, $this->fetched, 'Second request must NOT hit the Origin.' );
	}

	// -------------------------------------------------------------------------
	// Origin 5xx / timeout → no Negative cache
	// -------------------------------------------------------------------------

	public function test_origin_500_serves_404_without_creating_negative_transient(): void {
		$relative                        = '2026/06/server-error.jpg';
		$this->originStatus[ $relative ] = 500;

		$responder = $this->capturingResponder();
		$handled   = $this->handler( $responder )->handle( '/' . $this->uploadsUrlPath() . '/' . $relative );

		self::assertTrue( $handled );
		// No X-Uploads-Proxy: negative header — it's a transient blip, not a confirmed miss.
		self::assertSame( '', $responder->served404['xUploadsProxy'] ?? null );
		self::assertFileDoesNotExist( $this->absoluteUpload( $relative ) );

		// Transient must NOT exist so the next request retries the Origin.
		$transientKey = 'uploads_proxy_neg_' . md5( $relative );
		self::assertFalse( get_transient( $transientKey ), 'No negative-cache transient must be created after a 5xx.' );
	}

	public function test_origin_5xx_does_not_increment_negative_counter(): void {
		$relative                        = '2026/06/server-error.jpg';
		$this->originStatus[ $relative ] = 503;

		$this->handler( $this->capturingResponder() )->handle( '/' . $this->uploadsUrlPath() . '/' . $relative );

		self::assertSame( 0, ( new Counters() )->negativeCount() );
	}

	public function test_origin_5xx_allows_retry_on_next_request(): void {
		$relative = '2026/06/server-error.jpg';

		// First request: Origin is down (503).
		$this->originStatus[ $relative ] = 503;
		$this->handler( $this->capturingResponder() )->handle( '/' . $this->uploadsUrlPath() . '/' . $relative );

		$fetchCountAfterFirst = count( $this->fetched );

		// Second request: Origin recovers — remove the error status so it returns 200.
		unset( $this->originStatus[ $relative ] );
		$responder2 = $this->capturingResponder();
		$handled2   = $this->handler( $responder2 )->handle( '/' . $this->uploadsUrlPath() . '/' . $relative );

		self::assertGreaterThan( $fetchCountAfterFirst, count( $this->fetched ), 'Second request must retry the Origin.' );
		self::assertSame( 'FALLBACK-BYTES', $responder2->served['bytes'] ?? null );
		self::assertTrue( $handled2 );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function handler( Responder $responder ): RequestHandler {
		return new RequestHandler(
			$this->resolver(),
			new OriginClient(),
			new FileWriter(),
			new Counters(),
			new NegativeCache(),
			$responder,
			static fn (): UploadsScope => UploadsScope::fromWordPress(),
			static fn (): string => 'local',
		);
	}

	private function resolver(): ConfigResolver {
		$config = new EffectiveConfig(
			self::ORIGIN,
			Source::Constant,
			Mode::Download,
			Source::DefaultOff,
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

	private function capturingResponder(): Responder {
		return new class() implements Responder {
			/** @var array<string, string> */
			public array $served = [];

			/** @var array{xUploadsProxy: string}|array{} */
			public array $served404 = [];

			public function serveDownload( string $bytes, string $contentType ): void {
				$this->served = [
					'bytes'       => $bytes,
					'contentType' => $contentType,
				];
			}

			public function serve404( string $xUploadsProxy ): void {
				$this->served404 = [ 'xUploadsProxy' => $xUploadsProxy ];
			}
		};
	}

	private function uploadsUrlPath(): string {
		$uploads = wp_upload_dir();

		return trim( (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ), '/' );
	}

	private function absoluteUpload( string $relative ): string {
		$uploads = wp_upload_dir();

		return $uploads['basedir'] . '/' . $relative;
	}

	/**
	 * Map a full request path back to its uploads-relative portion, or null.
	 */
	private function relativeToUploads( string $path ): ?string {
		$prefix = $this->uploadsUrlPath() . '/';

		if ( ! str_starts_with( $path, $prefix ) ) {
			return null;
		}

		return substr( $path, strlen( $prefix ) );
	}

	private function resetUploads(): void {
		$uploads = wp_upload_dir();
		$dir     = $uploads['basedir'] . '/2026';

		if ( is_dir( $dir ) ) {
			$this->deleteRecursive( $dir );
		}
	}

	private function deleteRecursive( string $dir ): void {
		foreach ( scandir( $dir ) ?: [] as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir( $path ) ? $this->deleteRecursive( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}
}
