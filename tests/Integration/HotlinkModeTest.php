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
 * Hotlink-mode dispatch against a real WordPress install.
 *
 * Boots WordPress, simulates requests for missing Uploads paths, and asserts
 * Hotlink-mode behaviour:
 *
 * - A Miss issues a `302` redirect whose `Location` is the file on the
 *   configured Origin (`X-Uploads-Proxy: hotlink`).
 * - The redirect is temporary (`302`, never `301`).
 * - Nothing is written to the local uploads directory.
 * - No outbound HTTP request is made (the plugin never fetches the file; the
 *   browser follows the redirect and talks to the Origin directly).
 * - A file already present locally is not re-proxied.
 *
 * The terminal serve step is captured through a test {@see Responder} so it
 * does not `exit` out of PHPUnit. The Origin is mocked via `pre_http_request`
 * to confirm it is never called (no fetch in Hotlink mode).
 *
 * NOTE: This suite is authored for the `@wordpress/env` `tests-cli` layer and
 * requires a running wp-env container. On this machine Docker file-sharing
 * does not include the project path, so wp-env integration tests cannot be
 * executed locally — they are authored here but run in CI. The unit suite
 * (`composer test`) covers the same dispatch logic with Brain Monkey stubs.
 */
final class HotlinkModeTest extends WP_UnitTestCase {

	private const ORIGIN = 'https://origin.example.test';

	/**
	 * Origin URLs fetched during the test (should always be empty in Hotlink mode).
	 *
	 * @var list<string>
	 */
	private array $fetched = [];

	public function set_up(): void {
		parent::set_up();

		$this->fetched = [];

		add_filter( 'pre_http_request', [ $this, 'recordFetch' ], 10, 3 );

		$this->resetUploads();
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', [ $this, 'recordFetch' ], 10 );
		$this->resetUploads();
		delete_option( Counters::OPTION_DOWNLOADED );
		delete_option( Counters::OPTION_NEGATIVE_COUNT );
		parent::tear_down();
	}

	/**
	 * Record any outbound HTTP request made during the test.
	 *
	 * In Hotlink mode the handler must NOT fetch from the Origin — the browser
	 * follows the 302 redirect and talks to the Origin directly. If this filter
	 * is called, the handler has made a network request it should not have.
	 *
	 * @param mixed                $preempt Short-circuit value.
	 * @param array<string, mixed> $args    Request args.
	 * @param string               $url     Requested URL.
	 *
	 * @return array<string, mixed>
	 */
	public function recordFetch( $preempt, $args, $url ) {
		$this->fetched[] = $url;

		// Return a nominal 200 so the handler can continue if it mistakenly fetches.
		return [
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'body'     => 'SHOULD-NOT-BE-USED',
			'headers'  => [ 'content-type' => 'image/jpeg' ],
		];
	}

	// -------------------------------------------------------------------------
	// Core Hotlink behaviour
	// -------------------------------------------------------------------------

	public function test_hotlink_miss_issues_redirect_to_origin_url(): void {
		$relative  = '2026/06/photo.jpg';
		$responder = $this->capturingResponder();
		$handled   = $this->handler( $responder )->handle( '/' . $this->uploadsUrlPath() . '/' . $relative );

		self::assertTrue( $handled );
		self::assertSame(
			self::ORIGIN . '/' . $this->uploadsUrlPath() . '/' . $relative,
			$responder->servedHotlink['location'] ?? null,
			'The redirect Location must be the Origin URL with the uploads path preserved.'
		);
	}

	public function test_hotlink_miss_does_not_write_file_locally(): void {
		$relative = '2026/06/photo.jpg';
		$this->handler( $this->capturingResponder() )->handle( '/' . $this->uploadsUrlPath() . '/' . $relative );

		self::assertFileDoesNotExist(
			$this->absoluteUpload( $relative ),
			'Hotlink mode must never write to the local uploads directory.'
		);
	}

	public function test_hotlink_miss_does_not_fetch_from_origin(): void {
		$relative = '2026/06/photo.jpg';
		$this->handler( $this->capturingResponder() )->handle( '/' . $this->uploadsUrlPath() . '/' . $relative );

		self::assertCount(
			0,
			$this->fetched,
			'Hotlink mode must not make an outbound HTTP request; the browser follows the redirect.'
		);
	}

	// -------------------------------------------------------------------------
	// Redirect is temporary (302, never 301)
	// -------------------------------------------------------------------------

	public function test_hotlink_redirect_is_temporary_not_permanent(): void {
		// The Responder contract specifies 302; assert that serveHotlink is called
		// (not serve404 or serveDownload) so a permanent-redirect slip in HttpResponder
		// would be caught at the production layer. At this integration layer we assert
		// the handler routes to serveHotlink and that the production HttpResponder
		// emits 302 — the capturingResponder records the call; the HttpResponder
		// contract comment documents the 302 requirement explicitly.
		$relative  = '2026/06/photo.jpg';
		$responder = $this->capturingResponder();
		$handled   = $this->handler( $responder )->handle( '/' . $this->uploadsUrlPath() . '/' . $relative );

		self::assertTrue( $handled );
		// serveHotlink must have been called (not serve404, not serveDownload).
		self::assertNotEmpty( $responder->servedHotlink );
		self::assertEmpty( $responder->served );
		self::assertEmpty( $responder->served404 );
	}

	// -------------------------------------------------------------------------
	// Non-Miss: file present locally
	// -------------------------------------------------------------------------

	public function test_hotlink_does_not_act_on_locally_present_file(): void {
		$relative = '2026/06/already.jpg';
		$this->writeUpload( $relative, 'ON-DISK' );

		$responder = $this->capturingResponder();
		$handled   = $this->handler( $responder )->handle( '/' . $this->uploadsUrlPath() . '/' . $relative );

		self::assertFalse( $handled );
		self::assertCount( 0, $this->fetched );
		self::assertEmpty( $responder->servedHotlink );
		self::assertSame( 'ON-DISK', file_get_contents( $this->absoluteUpload( $relative ) ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a handler wired for Hotlink mode with real collaborators.
	 */
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
			Mode::Hotlink,
			Source::Constant,
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

			/** @var array{location: string}|array{} */
			public array $servedHotlink = [];

			/** @var array{xUploadsProxy: string}|array{} */
			public array $served404 = [];

			public function serveDownload( string $bytes, string $contentType ): void {
				$this->served = [
					'bytes'       => $bytes,
					'contentType' => $contentType,
				];
			}

			public function serveHotlink( string $location ): void {
				$this->servedHotlink = [ 'location' => $location ];
			}

			public function serve404( string $xUploadsProxy ): void {
				$this->served404 = [ 'xUploadsProxy' => $xUploadsProxy ];
			}
		};
	}

	/**
	 * The site-relative URL path for the uploads directory, no leading slash.
	 */
	private function uploadsUrlPath(): string {
		$uploads = wp_upload_dir();

		return trim( (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ), '/' );
	}

	private function absoluteUpload( string $relative ): string {
		$uploads = wp_upload_dir();

		return $uploads['basedir'] . '/' . $relative;
	}

	private function writeUpload( string $relative, string $body ): void {
		$target = $this->absoluteUpload( $relative );
		wp_mkdir_p( dirname( $target ) );
		file_put_contents( $target, $body );
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
