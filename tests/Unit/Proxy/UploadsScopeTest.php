<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Tests\Unit\Proxy;

use DivineApparitions\UploadsProxy\Proxy\UploadsScope;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DivineApparitions\UploadsProxy\Proxy\UploadsScope
 */
final class UploadsScopeTest extends TestCase {

	private function scope(): UploadsScope {
		// basedir on disk, and the site-relative URL path prefix for uploads.
		return new UploadsScope( '/var/www/wp-content/uploads', '/wp-content/uploads' );
	}

	public function test_relative_path_of_an_uploads_request(): void {
		self::assertSame(
			'2026/06/photo.jpg',
			$this->scope()->relativePathFor( '/wp-content/uploads/2026/06/photo.jpg' )
		);
	}

	public function test_relative_path_strips_a_query_string(): void {
		self::assertSame(
			'2026/06/photo.jpg',
			$this->scope()->relativePathFor( '/wp-content/uploads/2026/06/photo.jpg?ver=2' )
		);
	}

	public function test_relative_path_handles_a_derivative(): void {
		self::assertSame(
			'2026/06/photo-300x200.jpg',
			$this->scope()->relativePathFor( '/wp-content/uploads/2026/06/photo-300x200.jpg' )
		);
	}

	public function test_relative_path_handles_a_non_image(): void {
		self::assertSame(
			'2026/06/document.pdf',
			$this->scope()->relativePathFor( '/wp-content/uploads/2026/06/document.pdf' )
		);
	}

	public function test_returns_null_for_a_request_outside_uploads(): void {
		self::assertNull(
			$this->scope()->relativePathFor( '/wp-content/themes/foo/style.css' )
		);
		self::assertNull(
			$this->scope()->relativePathFor( '/wp-admin/' )
		);
	}

	public function test_rejects_path_traversal(): void {
		self::assertNull(
			$this->scope()->relativePathFor( '/wp-content/uploads/../../../etc/passwd' )
		);
	}

	public function test_rejects_a_null_byte(): void {
		self::assertNull(
			$this->scope()->relativePathFor( "/wp-content/uploads/evil\0.jpg" )
		);
	}

	public function test_absolute_target_path_is_contained_to_the_basedir(): void {
		self::assertSame(
			'/var/www/wp-content/uploads/2026/06/photo.jpg',
			$this->scope()->absolutePathFor( '2026/06/photo.jpg' )
		);
	}

	public function test_refuses_executable_extensions(): void {
		self::assertFalse( $this->scope()->isAllowedFile( '2026/06/shell.php' ) );
		self::assertFalse( $this->scope()->isAllowedFile( '2026/06/shell.phtml' ) );
		self::assertFalse( $this->scope()->isAllowedFile( '2026/06/shell.PHP' ) );
		self::assertFalse( $this->scope()->isAllowedFile( '2026/06/shell.pht' ) );
		self::assertFalse( $this->scope()->isAllowedFile( '2026/06/script.cgi' ) );
	}

	public function test_allows_ordinary_uploads(): void {
		self::assertTrue( $this->scope()->isAllowedFile( '2026/06/photo.jpg' ) );
		self::assertTrue( $this->scope()->isAllowedFile( '2026/06/photo-300x200.jpg' ) );
		self::assertTrue( $this->scope()->isAllowedFile( '2026/06/document.pdf' ) );
	}
}
