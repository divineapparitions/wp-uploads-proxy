<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Tests\Unit\State;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DivineApparitions\UploadsProxy\State\NegativeCache;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DivineApparitions\UploadsProxy\State\NegativeCache
 */
final class NegativeCacheTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_is_negative_returns_false_when_no_transient(): void {
		Functions\when( 'get_transient' )->justReturn( false );

		self::assertFalse( ( new NegativeCache() )->isNegative( '2026/06/photo.jpg' ) );
	}

	public function test_is_negative_returns_true_when_transient_exists(): void {
		Functions\when( 'get_transient' )->justReturn( '1' );

		self::assertTrue( ( new NegativeCache() )->isNegative( '2026/06/photo.jpg' ) );
	}

	public function test_record_sets_transient_with_ten_minute_ttl(): void {
		$capturedTtl = null;

		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				static function ( string $key, string $value, int $ttl ) use ( &$capturedTtl ): bool {
					$capturedTtl = $ttl;
					return true;
				}
			);

		( new NegativeCache() )->record( '2026/06/photo.jpg' );

		self::assertSame( NegativeCache::TTL, $capturedTtl );
	}

	public function test_record_uses_a_key_scoped_to_the_given_path(): void {
		$capturedKey = null;

		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				static function ( string $key ) use ( &$capturedKey ): bool {
					$capturedKey = $key;
					return true;
				}
			);

		( new NegativeCache() )->record( '2026/06/photo-a.jpg' );

		self::assertNotNull( $capturedKey );
		self::assertStringStartsWith( 'uploads_proxy_neg_', (string) $capturedKey );
	}

	public function test_different_paths_produce_different_transient_keys(): void {
		$keys = [];

		Functions\expect( 'set_transient' )
			->twice()
			->andReturnUsing(
				static function ( string $key ) use ( &$keys ): bool {
					$keys[] = $key;
					return true;
				}
			);

		$cache = new NegativeCache();
		$cache->record( '2026/06/photo-a.jpg' );
		$cache->record( '2026/06/photo-b.jpg' );

		self::assertCount( 2, $keys );
		self::assertNotSame( $keys[0], $keys[1] );
	}

	public function test_same_path_always_produces_the_same_transient_key(): void {
		$keys = [];

		Functions\expect( 'set_transient' )
			->twice()
			->andReturnUsing(
				static function ( string $key ) use ( &$keys ): bool {
					$keys[] = $key;
					return true;
				}
			);

		$cache = new NegativeCache();
		$cache->record( '2026/06/photo.jpg' );
		$cache->record( '2026/06/photo.jpg' );

		self::assertCount( 2, $keys );
		self::assertSame( $keys[0], $keys[1] );
	}

	public function test_clear_all_deletes_each_negative_transient_via_the_api_and_returns_count(): void {
		$fakeWpdb = new class() {

			public string $options    = 'wp_options';
			public ?string $lastQuery = null;
			public ?string $lastLike  = null;

			public function esc_like( string $text ): string {
				$this->lastLike = $text;
				return $text;
			}

			/**
			 * @param array<int, mixed> $args
			 */
			public function prepare( string $query, ...$args ): string {
				$this->lastQuery = $query;
				return $query;
			}

			/**
			 * @return array<int, string>
			 */
			public function get_col( string $query ): array {
				return [
					'_transient_uploads_proxy_neg_aaa',
					'_transient_uploads_proxy_neg_bbb',
					'_transient_uploads_proxy_neg_ccc',
				];
			}
		};

		$GLOBALS['wpdb'] = $fakeWpdb;

		$deleted = [];
		Functions\when( 'delete_transient' )->alias(
			static function ( string $transient ) use ( &$deleted ): bool {
				$deleted[] = $transient;
				return true;
			}
		);

		$cleared = ( new NegativeCache() )->clearAll();

		// Three transients discovered and deleted through the transient API, so the
		// option cache is invalidated (a raw DELETE would leave it stale).
		self::assertSame( 3, $cleared );
		self::assertSame(
			[ 'uploads_proxy_neg_aaa', 'uploads_proxy_neg_bbb', 'uploads_proxy_neg_ccc' ],
			$deleted
		);
		self::assertSame( 'SELECT', strtok( (string) $fakeWpdb->lastQuery, ' ' ) );
		self::assertStringContainsString( '_transient_uploads_proxy_neg_', (string) $fakeWpdb->lastLike );

		unset( $GLOBALS['wpdb'] );
	}
}
