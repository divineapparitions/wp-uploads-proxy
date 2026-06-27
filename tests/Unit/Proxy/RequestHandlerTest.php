<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Tests\Unit\Proxy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DivineApparitions\UploadsProxy\Config\ConfigResolver;
use DivineApparitions\UploadsProxy\Config\EffectiveConfig;
use DivineApparitions\UploadsProxy\Config\Mode;
use DivineApparitions\UploadsProxy\Config\Source;
use DivineApparitions\UploadsProxy\Proxy\FileWriter;
use DivineApparitions\UploadsProxy\Proxy\OriginFetcher;
use DivineApparitions\UploadsProxy\Proxy\OriginRequest;
use DivineApparitions\UploadsProxy\Proxy\OriginResponse;
use DivineApparitions\UploadsProxy\Proxy\RequestHandler;
use DivineApparitions\UploadsProxy\Proxy\Responder;
use DivineApparitions\UploadsProxy\Proxy\UploadsScope;
use DivineApparitions\UploadsProxy\State\Counters;
use DivineApparitions\UploadsProxy\State\NegativeCache;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DivineApparitions\UploadsProxy\Proxy\RequestHandler
 */
final class RequestHandlerTest extends TestCase {

	private string $baseDir;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_mkdir_p' )->alias(
			static fn ( string $dir ): bool => is_dir( $dir ) || mkdir( $dir, 0o755, true )
		);
		Functions\when( 'wp_delete_file' )->alias(
			static function ( string $file ): void {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
		);

		$this->baseDir = sys_get_temp_dir() . '/uploads-proxy-handler-' . uniqid();
		mkdir( $this->baseDir, 0o755, true );
	}

	protected function tearDown(): void {
		$this->removeDir( $this->baseDir );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function removeDir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: [] as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir( $path ) ? $this->removeDir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	private function config(): EffectiveConfig {
		return new EffectiveConfig(
			'https://origin.test',
			Source::Constant,
			Mode::Download,
			Source::DefaultOff,
			null,
			Source::DefaultOff,
			false,
		);
	}

	private function hotlinkConfig(): EffectiveConfig {
		return new EffectiveConfig(
			'https://origin.test',
			Source::Constant,
			Mode::Hotlink,
			Source::Constant,
			null,
			Source::DefaultOff,
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

	private function originClient( OriginResponse $response ): OriginFetcher {
		return new class( $response ) implements OriginFetcher {
			public function __construct( private OriginResponse $response ) {}

			public function fetch( OriginRequest $request ): OriginResponse {
				return $this->response;
			}
		};
	}

	/**
	 * A counting origin client that records fetched requests and returns a fixed response.
	 * Uses a shared stdClass counter so both references stay in sync.
	 *
	 * @return array{0: OriginFetcher, 1: \stdClass}
	 */
	private function countingOriginClient( OriginResponse $response ): array {
		$counter       = new \stdClass();
		$counter->hits = 0;
		$fetcher       = new class( $response, $counter ) implements OriginFetcher {
			public function __construct(
				private OriginResponse $response,
				private \stdClass $counter,
			) {}

			public function fetch( OriginRequest $request ): OriginResponse {
				++$this->counter->hits;
				return $this->response;
			}
		};

		return [ $fetcher, $counter ];
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

	public function test_download_happy_path_writes_file_serves_bytes_and_counts(): void {
		$scope     = new UploadsScope( $this->baseDir, '/wp-content/uploads' );
		$responder = $this->capturingResponder();

		$handler = new RequestHandler(
			$this->resolver( $this->config() ),
			$this->originClient( new OriginResponse( 200, 'IMAGE-BYTES', 'image/jpeg' ) ),
			new FileWriter(),
			new Counters(),
			new NegativeCache(),
			$responder,
			static fn (): UploadsScope => $scope,
			static fn (): string => 'production' === 'staging' ? 'production' : 'local',
		);

		Functions\when( 'wp_check_filetype' )->justReturn(
			[
				'ext'  => 'jpg',
				'type' => 'image/jpeg',
			]
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\expect( 'update_option' )->once();

		$handled = $handler->handle( '/wp-content/uploads/2026/06/photo.jpg' );

		self::assertTrue( $handled );
		self::assertFileExists( $this->baseDir . '/2026/06/photo.jpg' );
		self::assertSame( 'IMAGE-BYTES', file_get_contents( $this->baseDir . '/2026/06/photo.jpg' ) );
		self::assertSame( 'IMAGE-BYTES', $responder->served['bytes'] ?? null );
		self::assertSame( 'image/jpeg', $responder->served['contentType'] ?? null );
	}

	public function test_inert_on_production(): void {
		$scope   = new UploadsScope( $this->baseDir, '/wp-content/uploads' );
		$handler = new RequestHandler(
			$this->resolver( $this->config() ),
			$this->originClient( new OriginResponse( 200, 'X', 'image/jpeg' ) ),
			new FileWriter(),
			new Counters(),
			new NegativeCache(),
			$this->capturingResponder(),
			static fn (): UploadsScope => $scope,
			static fn (): string => 'production',
		);

		self::assertFalse( $handler->handle( '/wp-content/uploads/2026/06/photo.jpg' ) );
		self::assertFileDoesNotExist( $this->baseDir . '/2026/06/photo.jpg' );
	}

	public function test_inert_when_no_origin_configured(): void {
		$disabled = new EffectiveConfig(
			'',
			Source::DefaultOff,
			Mode::Download,
			Source::DefaultOff,
			null,
			Source::DefaultOff,
			false,
		);

		$scope   = new UploadsScope( $this->baseDir, '/wp-content/uploads' );
		$handler = new RequestHandler(
			$this->resolver( $disabled ),
			$this->originClient( new OriginResponse( 200, 'X', 'image/jpeg' ) ),
			new FileWriter(),
			new Counters(),
			new NegativeCache(),
			$this->capturingResponder(),
			static fn (): UploadsScope => $scope,
			static fn (): string => 'local',
		);

		self::assertFalse( $handler->handle( '/wp-content/uploads/2026/06/photo.jpg' ) );
	}

	public function test_ignores_requests_outside_uploads(): void {
		$scope   = new UploadsScope( $this->baseDir, '/wp-content/uploads' );
		$handler = new RequestHandler(
			$this->resolver( $this->config() ),
			$this->originClient( new OriginResponse( 200, 'X', 'image/jpeg' ) ),
			new FileWriter(),
			new Counters(),
			new NegativeCache(),
			$this->capturingResponder(),
			static fn (): UploadsScope => $scope,
			static fn (): string => 'local',
		);

		self::assertFalse( $handler->handle( '/wp-content/themes/foo/style.css' ) );
	}

	public function test_does_not_reproxy_a_file_present_locally(): void {
		mkdir( $this->baseDir . '/2026/06', 0o755, true );
		file_put_contents( $this->baseDir . '/2026/06/photo.jpg', 'ALREADY-HERE' );

		$scope   = new UploadsScope( $this->baseDir, '/wp-content/uploads' );
		$handler = new RequestHandler(
			$this->resolver( $this->config() ),
			$this->originClient( new OriginResponse( 200, 'FROM-ORIGIN', 'image/jpeg' ) ),
			new FileWriter(),
			new Counters(),
			new NegativeCache(),
			$this->capturingResponder(),
			static fn (): UploadsScope => $scope,
			static fn (): string => 'local',
		);

		self::assertFalse( $handler->handle( '/wp-content/uploads/2026/06/photo.jpg' ) );
		self::assertSame( 'ALREADY-HERE', file_get_contents( $this->baseDir . '/2026/06/photo.jpg' ) );
	}

	public function test_disallowed_mime_type_on_origin_200_writes_nothing(): void {
		// wp_check_filetype returns type=false: the site does not allow this extension.
		// Even though the Origin returns 200, nothing is written and the handler returns false.
		Functions\when( 'wp_check_filetype' )->justReturn(
			[
				'ext'  => false,
				'type' => false,
			]
		);

		$scope   = new UploadsScope( $this->baseDir, '/wp-content/uploads' );
		$handler = new RequestHandler(
			$this->resolver( $this->config() ),
			$this->originClient( new OriginResponse( 200, 'MALWARE-BYTES', 'application/octet-stream' ) ),
			new FileWriter(),
			new Counters(),
			new NegativeCache(),
			$this->capturingResponder(),
			static fn (): UploadsScope => $scope,
			static fn (): string => 'local',
		);

		self::assertFalse( $handler->handle( '/wp-content/uploads/2026/06/payload.bat' ) );
		self::assertFileDoesNotExist( $this->baseDir . '/2026/06/payload.bat' );
	}

	public function test_refuses_executable_extension_even_on_origin_200(): void {
		$scope   = new UploadsScope( $this->baseDir, '/wp-content/uploads' );
		$handler = new RequestHandler(
			$this->resolver( $this->config() ),
			$this->originClient( new OriginResponse( 200, '<?php evil', 'application/x-php' ) ),
			new FileWriter(),
			new Counters(),
			new NegativeCache(),
			$this->capturingResponder(),
			static fn (): UploadsScope => $scope,
			static fn (): string => 'local',
		);

		self::assertFalse( $handler->handle( '/wp-content/uploads/2026/06/shell.php' ) );
		self::assertFileDoesNotExist( $this->baseDir . '/2026/06/shell.php' );
	}

	// -------------------------------------------------------------------------
	// Miss-fallback: Origin 404 / 410 → Negative cache
	// -------------------------------------------------------------------------

	public function test_origin_404_serves_local_404_with_negative_header(): void {
		$scope     = new UploadsScope( $this->baseDir, '/wp-content/uploads' );
		$responder = $this->capturingResponder();

		$handler = new RequestHandler(
			$this->resolver( $this->config() ),
			$this->originClient( new OriginResponse( 404, '', '' ) ),
			new FileWriter(),
			new Counters(),
			new NegativeCache(),
			$responder,
			static fn (): UploadsScope => $scope,
			static fn (): string => 'local',
		);

		Functions\when( 'wp_check_filetype' )->justReturn(
			[
				'ext'  => 'jpg',
				'type' => 'image/jpeg',
			]
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'update_option' )->justReturn( true );

		$handled = $handler->handle( '/wp-content/uploads/2026/06/photo.jpg' );

		self::assertTrue( $handled );
		self::assertSame( 'negative', $responder->served404['xUploadsProxy'] ?? null );
		self::assertFileDoesNotExist( $this->baseDir . '/2026/06/photo.jpg' );
	}

	public function test_origin_410_serves_local_404_with_negative_header(): void {
		$scope     = new UploadsScope( $this->baseDir, '/wp-content/uploads' );
		$responder = $this->capturingResponder();

		$handler = new RequestHandler(
			$this->resolver( $this->config() ),
			$this->originClient( new OriginResponse( 410, '', '' ) ),
			new FileWriter(),
			new Counters(),
			new NegativeCache(),
			$responder,
			static fn (): UploadsScope => $scope,
			static fn (): string => 'local',
		);

		Functions\when( 'wp_check_filetype' )->justReturn(
			[
				'ext'  => 'jpg',
				'type' => 'image/jpeg',
			]
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'update_option' )->justReturn( true );

		$handled = $handler->handle( '/wp-content/uploads/2026/06/photo.jpg' );

		self::assertTrue( $handled );
		self::assertSame( 'negative', $responder->served404['xUploadsProxy'] ?? null );
	}

	public function test_origin_404_records_negative_cache_transient(): void {
		$scope       = new UploadsScope( $this->baseDir, '/wp-content/uploads' );
		$capturedKey = null;

		$handler = new RequestHandler(
			$this->resolver( $this->config() ),
			$this->originClient( new OriginResponse( 404, '', '' ) ),
			new FileWriter(),
			new Counters(),
			new NegativeCache(),
			$this->capturingResponder(),
			static fn (): UploadsScope => $scope,
			static fn (): string => 'local',
		);

		Functions\when( 'wp_check_filetype' )->justReturn(
			[
				'ext'  => 'jpg',
				'type' => 'image/jpeg',
			]
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				static function ( string $key ) use ( &$capturedKey ): bool {
					$capturedKey = $key;
					return true;
				}
			);
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'update_option' )->justReturn( true );

		$handler->handle( '/wp-content/uploads/2026/06/photo.jpg' );

		self::assertStringStartsWith( 'uploads_proxy_neg_', (string) $capturedKey );
	}

	public function test_origin_404_increments_negative_counter(): void {
		$scope        = new UploadsScope( $this->baseDir, '/wp-content/uploads' );
		$capturedArgs = null;

		$handler = new RequestHandler(
			$this->resolver( $this->config() ),
			$this->originClient( new OriginResponse( 404, '', '' ) ),
			new FileWriter(),
			new Counters(),
			new NegativeCache(),
			$this->capturingResponder(),
			static fn (): UploadsScope => $scope,
			static fn (): string => 'local',
		);

		Functions\when( 'wp_check_filetype' )->justReturn(
			[
				'ext'  => 'jpg',
				'type' => 'image/jpeg',
			]
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\expect( 'update_option' )
			->once()
			->andReturnUsing(
				static function ( string $option, int $value ) use ( &$capturedArgs ): bool {
					$capturedArgs = [ $option, $value ];
					return true;
				}
			);

		$handler->handle( '/wp-content/uploads/2026/06/photo.jpg' );

		self::assertSame( [ Counters::OPTION_NEGATIVE_COUNT, 1 ], $capturedArgs );
	}

	// -------------------------------------------------------------------------
	// Miss-fallback: Origin 5xx / timeout → no cache
	// -------------------------------------------------------------------------

	public function test_origin_500_serves_local_404_without_caching(): void {
		$scope     = new UploadsScope( $this->baseDir, '/wp-content/uploads' );
		$responder = $this->capturingResponder();

		$handler = new RequestHandler(
			$this->resolver( $this->config() ),
			$this->originClient( new OriginResponse( 500, '', '' ) ),
			new FileWriter(),
			new Counters(),
			new NegativeCache(),
			$responder,
			static fn (): UploadsScope => $scope,
			static fn (): string => 'local',
		);

		Functions\when( 'wp_check_filetype' )->justReturn(
			[
				'ext'  => 'jpg',
				'type' => 'image/jpeg',
			]
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'set_transient' )->never();

		$handled = $handler->handle( '/wp-content/uploads/2026/06/photo.jpg' );

		self::assertTrue( $handled );
		// The header should be absent (empty string), indicating no negative-cache marker.
		self::assertSame( '', $responder->served404['xUploadsProxy'] ?? null );
	}

	public function test_origin_timeout_zero_status_serves_local_404_without_caching(): void {
		$scope     = new UploadsScope( $this->baseDir, '/wp-content/uploads' );
		$responder = $this->capturingResponder();

		$handler = new RequestHandler(
			$this->resolver( $this->config() ),
			$this->originClient( new OriginResponse( 0, '', '' ) ),
			new FileWriter(),
			new Counters(),
			new NegativeCache(),
			$responder,
			static fn (): UploadsScope => $scope,
			static fn (): string => 'local',
		);

		Functions\when( 'wp_check_filetype' )->justReturn(
			[
				'ext'  => 'jpg',
				'type' => 'image/jpeg',
			]
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'set_transient' )->never();

		$handled = $handler->handle( '/wp-content/uploads/2026/06/photo.jpg' );

		self::assertTrue( $handled );
		self::assertSame( '', $responder->served404['xUploadsProxy'] ?? null );
	}

	// -------------------------------------------------------------------------
	// Miss-fallback: Negative-cache short-circuit
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// Hotlink mode
	// -------------------------------------------------------------------------

	public function test_hotlink_mode_miss_issues_redirect_to_origin_url(): void {
		$scope     = new UploadsScope( $this->baseDir, '/wp-content/uploads' );
		$responder = $this->capturingResponder();

		$handler = new RequestHandler(
			$this->resolver( $this->hotlinkConfig() ),
			$this->originClient( new OriginResponse( 200, 'IGNORED', 'image/jpeg' ) ),
			new FileWriter(),
			new Counters(),
			new NegativeCache(),
			$responder,
			static fn (): UploadsScope => $scope,
			static fn (): string => 'local',
		);

		$handled = $handler->handle( '/wp-content/uploads/2026/06/photo.jpg' );

		self::assertTrue( $handled );
		self::assertSame(
			'https://origin.test/wp-content/uploads/2026/06/photo.jpg',
			$responder->servedHotlink['location'] ?? null
		);
	}

	public function test_hotlink_mode_miss_does_not_write_file_locally(): void {
		$scope     = new UploadsScope( $this->baseDir, '/wp-content/uploads' );
		$responder = $this->capturingResponder();

		$handler = new RequestHandler(
			$this->resolver( $this->hotlinkConfig() ),
			$this->originClient( new OriginResponse( 200, 'IGNORED', 'image/jpeg' ) ),
			new FileWriter(),
			new Counters(),
			new NegativeCache(),
			$responder,
			static fn (): UploadsScope => $scope,
			static fn (): string => 'local',
		);

		$handler->handle( '/wp-content/uploads/2026/06/photo.jpg' );

		self::assertFileDoesNotExist( $this->baseDir . '/2026/06/photo.jpg' );
	}

	public function test_hotlink_mode_does_not_fetch_from_origin(): void {
		$scope                 = new UploadsScope( $this->baseDir, '/wp-content/uploads' );
		[ $fetcher, $counter ] = $this->countingOriginClient( new OriginResponse( 200, 'BYTES', 'image/jpeg' ) );

		$handler = new RequestHandler(
			$this->resolver( $this->hotlinkConfig() ),
			$fetcher,
			new FileWriter(),
			new Counters(),
			new NegativeCache(),
			$this->capturingResponder(),
			static fn (): UploadsScope => $scope,
			static fn (): string => 'local',
		);

		$handler->handle( '/wp-content/uploads/2026/06/photo.jpg' );

		self::assertSame( 0, $counter->hits, 'Hotlink mode must not fetch from the Origin.' );
	}

	public function test_hotlink_mode_does_not_serve_download(): void {
		$scope     = new UploadsScope( $this->baseDir, '/wp-content/uploads' );
		$responder = $this->capturingResponder();

		$handler = new RequestHandler(
			$this->resolver( $this->hotlinkConfig() ),
			$this->originClient( new OriginResponse( 200, 'IGNORED', 'image/jpeg' ) ),
			new FileWriter(),
			new Counters(),
			new NegativeCache(),
			$responder,
			static fn (): UploadsScope => $scope,
			static fn (): string => 'local',
		);

		$handler->handle( '/wp-content/uploads/2026/06/photo.jpg' );

		self::assertEmpty( $responder->served, 'Hotlink mode must not call serveDownload.' );
	}

	public function test_download_mode_remains_default_and_is_not_hotlink(): void {
		// Config() uses Mode::Download (the default). Assert that Download mode
		// still calls serveDownload and never calls serveHotlink.
		$scope     = new UploadsScope( $this->baseDir, '/wp-content/uploads' );
		$responder = $this->capturingResponder();

		$handler = new RequestHandler(
			$this->resolver( $this->config() ),
			$this->originClient( new OriginResponse( 200, 'IMAGE-BYTES', 'image/jpeg' ) ),
			new FileWriter(),
			new Counters(),
			new NegativeCache(),
			$responder,
			static fn (): UploadsScope => $scope,
			static fn (): string => 'local',
		);

		Functions\when( 'wp_check_filetype' )->justReturn(
			[
				'ext'  => 'jpg',
				'type' => 'image/jpeg',
			]
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\expect( 'update_option' )->once();

		$handled = $handler->handle( '/wp-content/uploads/2026/06/photo.jpg' );

		self::assertTrue( $handled );
		self::assertSame( 'IMAGE-BYTES', $responder->served['bytes'] ?? null );
		self::assertEmpty( $responder->servedHotlink, 'Download mode must not call serveHotlink.' );
	}

	public function test_negative_cached_path_short_circuits_without_origin_fetch(): void {
		$scope                 = new UploadsScope( $this->baseDir, '/wp-content/uploads' );
		[ $fetcher, $counter ] = $this->countingOriginClient( new OriginResponse( 200, 'BYTES', 'image/jpeg' ) );
		$responder             = $this->capturingResponder();

		$handler = new RequestHandler(
			$this->resolver( $this->config() ),
			$fetcher,
			new FileWriter(),
			new Counters(),
			new NegativeCache(),
			$responder,
			static fn (): UploadsScope => $scope,
			static fn (): string => 'local',
		);

		Functions\when( 'wp_check_filetype' )->justReturn(
			[
				'ext'  => 'jpg',
				'type' => 'image/jpeg',
			]
		);
		// get_transient returns '1': this path is already negative-cached.
		Functions\when( 'get_transient' )->justReturn( '1' );

		$handled = $handler->handle( '/wp-content/uploads/2026/06/photo.jpg' );

		self::assertTrue( $handled );
		self::assertSame( 0, $counter->hits, 'No Origin fetch should occur for a negative-cached path.' );
		self::assertSame( 'negative', $responder->served404['xUploadsProxy'] ?? null );
		self::assertFileDoesNotExist( $this->baseDir . '/2026/06/photo.jpg' );
	}
}
