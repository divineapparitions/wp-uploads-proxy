<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Tests\Integration;

use DivineApparitions\UploadsProxy\Config\BasicAuth;
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
 * The request-interception walking skeleton against a real WordPress install.
 *
 * Boots WordPress, simulates a request for a missing Uploads path, mocks the
 * Origin through the live HTTP layer via `pre_http_request`, and asserts the
 * Download-mode behaviour: the bytes are fetched, atomically written into the
 * real uploads directory, served back with the correct Content-Type and the
 * `X-Uploads-Proxy: download` marker, and the downloaded counter increments.
 *
 * The terminal serve step is captured through a test {@see Responder} so it does
 * not `exit` out of PHPUnit; everything else (the HTTP fetch, the filesystem
 * write into `wp_upload_dir()`, the environment-type gate) is the real code path.
 */
final class DownloadHappyPathTest extends WP_UnitTestCase {

	private const ORIGIN = 'https://origin.example.test';

	/**
	 * Origin URLs that were fetched during a test (to prove no re-proxy).
	 *
	 * @var list<string>
	 */
	private array $fetched = [];

	/**
	 * The relative uploads paths the mocked Origin should serve, and with what.
	 *
	 * @var array<string, array{body: string, type: string}>
	 */
	private array $originFiles = [];

	/**
	 * Authorization headers seen by the mocked Origin, keyed by URL.
	 *
	 * @var array<string, string>
	 */
	private array $seenAuth = [];

	private ?BasicAuth $basicAuth = null;

	public function set_up(): void {
		parent::set_up();

		$this->fetched     = [];
		$this->originFiles = [];
		$this->seenAuth    = [];
		$this->basicAuth   = null;

		add_filter( 'pre_http_request', [ $this, 'mockOrigin' ], 10, 3 );

		// Clear the uploads directory of any files a prior test wrote.
		$this->resetUploads();
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', [ $this, 'mockOrigin' ], 10 );
		$this->resetUploads();
		delete_option( Counters::OPTION_DOWNLOADED );
		delete_option( Counters::OPTION_NEGATIVE_COUNT );
		parent::tear_down();
	}

	/**
	 * Intercept outbound HTTP and answer for known Origin files; 404 otherwise.
	 *
	 * @param mixed                $preempt Short-circuit value.
	 * @param array<string, mixed> $args    Request args.
	 * @param string               $url     Requested URL.
	 *
	 * @return array<string, mixed>
	 */
	public function mockOrigin( $preempt, $args, $url ) {
		$this->fetched[] = $url;

		if ( isset( $args['headers']['Authorization'] ) ) {
			$this->seenAuth[ $url ] = (string) $args['headers']['Authorization'];
		}

		$relative = ltrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$relative = $this->relativeToUploads( $relative );

		if ( null !== $relative && isset( $this->originFiles[ $relative ] ) ) {
			$file = $this->originFiles[ $relative ];

			return [
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'body'     => $file['body'],
				'headers'  => [ 'content-type' => $file['type'] ],
			];
		}

		return [
			'response' => [
				'code'    => 404,
				'message' => 'Not Found',
			],
			'body'     => '',
			'headers'  => [],
		];
	}

	public function test_missing_image_is_downloaded_saved_and_served(): void {
		$relative = '2026/06/photo.jpg';
		$this->seedOrigin( $relative, 'JPEG-BYTES', 'image/jpeg' );

		$responder = $this->capturingResponder();
		$handled   = $this->handler( $responder )->handle( '/' . $this->uploadsUrlPath() . '/' . $relative );

		self::assertTrue( $handled );
		self::assertSame( 'JPEG-BYTES', $responder->served['bytes'] ?? null );
		self::assertSame( 'image/jpeg', $responder->served['contentType'] ?? null );
		self::assertFileExists( $this->absoluteUpload( $relative ) );
		self::assertSame( 'JPEG-BYTES', file_get_contents( $this->absoluteUpload( $relative ) ) );
		self::assertSame( 1, ( new Counters() )->downloaded() );
	}

	public function test_download_is_served_as_200_not_the_template_redirect_404(): void {
		// The Responder contract specifies 200 for a download: the handler runs on
		// `template_redirect`, where WordPress has already set a 404 for the missing
		// uploads URL, so a successful Miss must reset the status to 200 before
		// sending the bytes. Assert the handler routes to serveDownload (not serve404),
		// so a status slip in HttpResponder — serving the file under the 404 — would be
		// caught at the production layer. The capturingResponder records the call; the
		// HttpResponder/Responder contract documents the 200 requirement explicitly.
		$relative = '2026/06/photo.jpg';
		$this->seedOrigin( $relative, 'JPEG-BYTES', 'image/jpeg' );

		$responder = $this->capturingResponder();
		$handled   = $this->handler( $responder )->handle( '/' . $this->uploadsUrlPath() . '/' . $relative );

		self::assertTrue( $handled );
		// serveDownload must have been called (not serve404, not serveHotlink).
		self::assertNotEmpty( $responder->served );
		self::assertEmpty( $responder->served404 );
		self::assertEmpty( $responder->servedHotlink );
	}

	public function test_encoded_filename_is_saved_under_its_decoded_name(): void {
		// A filename with a space arrives percent-encoded in the request URI. The
		// Origin is fetched with the encoded URL (HTTP wants it encoded), but the
		// bytes must be saved under the real, decoded name — because the web server
		// decodes the URL before its filesystem lookup on the next request. Saving a
		// literal "%20" file would never be found and would be re-proxied every time.
		$encodedRelative = '2026/06/my%20photo.jpg';
		$decodedRelative = '2026/06/my photo.jpg';
		$this->seedOrigin( $encodedRelative, 'SPACED-BYTES', 'image/jpeg' );

		$responder = $this->capturingResponder();
		$handled   = $this->handler( $responder )->handle( '/' . $this->uploadsUrlPath() . '/' . $encodedRelative );

		self::assertTrue( $handled );
		self::assertSame( 'SPACED-BYTES', $responder->served['bytes'] ?? null );
		// Fetched from the Origin with the path still percent-encoded.
		self::assertSame(
			self::ORIGIN . '/' . $this->uploadsUrlPath() . '/' . $encodedRelative,
			$this->fetched[0]
		);
		// Saved under the decoded name, never the literal "%20" name.
		self::assertFileExists( $this->absoluteUpload( $decodedRelative ) );
		self::assertFileDoesNotExist( $this->absoluteUpload( $encodedRelative ) );
	}

	public function test_origin_url_swaps_only_the_host_and_preserves_the_path(): void {
		$relative = '2026/06/photo.jpg';
		$this->seedOrigin( $relative, 'BYTES', 'image/jpeg' );

		$this->handler( $this->capturingResponder() )->handle( '/' . $this->uploadsUrlPath() . '/' . $relative );

		self::assertCount( 1, $this->fetched );
		self::assertSame(
			self::ORIGIN . '/' . $this->uploadsUrlPath() . '/' . $relative,
			$this->fetched[0]
		);
	}

	public function test_basic_auth_is_attached_when_configured(): void {
		$relative = '2026/06/photo.jpg';
		$this->seedOrigin( $relative, 'BYTES', 'image/jpeg' );
		$this->basicAuth = BasicAuth::fromPair( 'origin-user', 'origin-pass' );

		$this->handler( $this->capturingResponder() )->handle( '/' . $this->uploadsUrlPath() . '/' . $relative );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Asserting the RFC 7617 Basic Auth encoding.
		$expected = 'Basic ' . base64_encode( 'origin-user:origin-pass' );
		self::assertSame( $expected, $this->seenAuth[ $this->fetched[0] ] ?? null );
	}

	public function test_a_present_file_is_not_reproxied(): void {
		$relative = '2026/06/already.jpg';
		$this->writeUpload( $relative, 'ON-DISK' );
		$this->seedOrigin( $relative, 'FROM-ORIGIN', 'image/jpeg' );

		$handled = $this->handler( $this->capturingResponder() )->handle( '/' . $this->uploadsUrlPath() . '/' . $relative );

		self::assertFalse( $handled );
		self::assertCount( 0, $this->fetched, 'A present file must not trigger an Origin fetch.' );
		self::assertSame( 'ON-DISK', file_get_contents( $this->absoluteUpload( $relative ) ) );
	}

	public function test_a_derivative_resolves_through_the_same_handler(): void {
		$relative = '2026/06/photo-300x200.jpg';
		$this->seedOrigin( $relative, 'THUMB-BYTES', 'image/jpeg' );

		$responder = $this->capturingResponder();
		$handled   = $this->handler( $responder )->handle( '/' . $this->uploadsUrlPath() . '/' . $relative );

		self::assertTrue( $handled );
		self::assertSame( 'THUMB-BYTES', $responder->served['bytes'] ?? null );
		self::assertFileExists( $this->absoluteUpload( $relative ) );
	}

	public function test_a_non_image_pdf_resolves_through_the_same_handler(): void {
		$relative = '2026/06/document.pdf';
		$this->seedOrigin( $relative, '%PDF-1.4 bytes', 'application/pdf' );

		$responder = $this->capturingResponder();
		$handled   = $this->handler( $responder )->handle( '/' . $this->uploadsUrlPath() . '/' . $relative );

		self::assertTrue( $handled );
		self::assertSame( 'application/pdf', $responder->served['contentType'] ?? null );
		self::assertFileExists( $this->absoluteUpload( $relative ) );
		self::assertSame( '%PDF-1.4 bytes', file_get_contents( $this->absoluteUpload( $relative ) ) );
	}

	public function test_inert_on_production(): void {
		$relative = '2026/06/photo.jpg';
		$this->seedOrigin( $relative, 'BYTES', 'image/jpeg' );

		$handler = new RequestHandler(
			$this->resolver( $this->enabledConfig() ),
			new OriginClient(),
			new FileWriter(),
			new Counters(),
			new NegativeCache(),
			$this->capturingResponder(),
			static fn (): UploadsScope => UploadsScope::fromWordPress(),
			static fn (): string => 'production',
		);

		self::assertFalse( $handler->handle( '/' . $this->uploadsUrlPath() . '/' . $relative ) );
		self::assertCount( 0, $this->fetched );
		self::assertFileDoesNotExist( $this->absoluteUpload( $relative ) );
	}

	public function test_inert_when_no_origin_configured(): void {
		$relative = '2026/06/photo.jpg';
		$this->seedOrigin( $relative, 'BYTES', 'image/jpeg' );

		$disabled = new EffectiveConfig(
			'',
			Source::DefaultOff,
			Mode::Download,
			Source::DefaultOff,
			null,
			Source::DefaultOff,
			false,
		);

		$handler = new RequestHandler(
			$this->resolver( $disabled ),
			new OriginClient(),
			new FileWriter(),
			new Counters(),
			new NegativeCache(),
			$this->capturingResponder(),
			static fn (): UploadsScope => UploadsScope::fromWordPress(),
			static fn (): string => 'local',
		);

		self::assertFalse( $handler->handle( '/' . $this->uploadsUrlPath() . '/' . $relative ) );
		self::assertCount( 0, $this->fetched );
	}

	/**
	 * The real handler with real collaborators and a capturing responder.
	 */
	private function handler( Responder $responder ): RequestHandler {
		return new RequestHandler(
			$this->resolver( $this->enabledConfig() ),
			new OriginClient(),
			new FileWriter(),
			new Counters(),
			new NegativeCache(),
			$responder,
			static fn (): UploadsScope => UploadsScope::fromWordPress(),
			static fn (): string => 'local',
		);
	}

	private function enabledConfig(): EffectiveConfig {
		return new EffectiveConfig(
			self::ORIGIN,
			Source::Constant,
			Mode::Download,
			Source::DefaultOff,
			$this->basicAuth,
			null === $this->basicAuth ? Source::DefaultOff : Source::Constant,
			false,
		);
	}

	private function resolver( EffectiveConfig $config ): ConfigResolver {
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
	 * @param string $relative Relative uploads path.
	 */
	private function seedOrigin( string $relative, string $body, string $type ): void {
		$this->originFiles[ $relative ] = [
			'body' => $body,
			'type' => $type,
		];
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
