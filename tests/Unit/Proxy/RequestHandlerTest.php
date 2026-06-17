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

	private function capturingResponder(): Responder {
		return new class() implements Responder {
			/** @var array<string, string> */
			public array $served = [];

			public function serveDownload( string $bytes, string $contentType ): void {
				$this->served = [
					'bytes'       => $bytes,
					'contentType' => $contentType,
				];
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
			$responder,
			static fn (): UploadsScope => $scope,
			static fn (): string => 'production' === 'staging' ? 'production' : 'local',
		);

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
			$this->capturingResponder(),
			static fn (): UploadsScope => $scope,
			static fn (): string => 'local',
		);

		self::assertFalse( $handler->handle( '/wp-content/uploads/2026/06/photo.jpg' ) );
		self::assertSame( 'ALREADY-HERE', file_get_contents( $this->baseDir . '/2026/06/photo.jpg' ) );
	}

	public function test_refuses_executable_extension_even_on_origin_200(): void {
		$scope   = new UploadsScope( $this->baseDir, '/wp-content/uploads' );
		$handler = new RequestHandler(
			$this->resolver( $this->config() ),
			$this->originClient( new OriginResponse( 200, '<?php evil', 'application/x-php' ) ),
			new FileWriter(),
			new Counters(),
			$this->capturingResponder(),
			static fn (): UploadsScope => $scope,
			static fn (): string => 'local',
		);

		self::assertFalse( $handler->handle( '/wp-content/uploads/2026/06/shell.php' ) );
		self::assertFileDoesNotExist( $this->baseDir . '/2026/06/shell.php' );
	}
}
