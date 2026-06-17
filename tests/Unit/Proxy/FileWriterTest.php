<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Tests\Unit\Proxy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DivineApparitions\UploadsProxy\Proxy\FileWriter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DivineApparitions\UploadsProxy\Proxy\FileWriter
 */
final class FileWriterTest extends TestCase {

	private string $baseDir;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// FileWriter leans on two WordPress filesystem helpers; back them with the
		// real native operations so the atomic write is exercised end to end.
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

		$this->baseDir = sys_get_temp_dir() . '/uploads-proxy-test-' . uniqid();
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
		$items = scandir( $dir ) ?: [];
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir( $path ) ? $this->removeDir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	public function test_writes_bytes_to_the_target_path_creating_directories(): void {
		$writer = new FileWriter();
		$target = $this->baseDir . '/2026/06/photo.jpg';

		$result = $writer->write( $target, 'IMAGE-BYTES' );

		self::assertTrue( $result );
		self::assertFileExists( $target );
		self::assertSame( 'IMAGE-BYTES', file_get_contents( $target ) );
	}

	public function test_leaves_no_temp_file_behind(): void {
		$writer = new FileWriter();
		$target = $this->baseDir . '/2026/06/photo.jpg';

		$writer->write( $target, 'BYTES' );

		$leftovers = glob( $this->baseDir . '/2026/06/*' ) ?: [];
		self::assertSame( [ $target ], $leftovers );
	}
}
