<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Tests\Unit\State;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DivineApparitions\UploadsProxy\State\Counters;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DivineApparitions\UploadsProxy\State\Counters
 */
final class CountersTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_downloaded_reads_the_stored_count(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( Counters::OPTION_DOWNLOADED, 0 )
			->andReturn( 7 );

		self::assertSame( 7, ( new Counters() )->downloaded() );
	}

	public function test_record_download_increments_and_persists(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( Counters::OPTION_DOWNLOADED, 0 )
			->andReturn( 4 );

		Functions\expect( 'update_option' )
			->once()
			->with( Counters::OPTION_DOWNLOADED, 5, false );

		self::assertSame( 5, ( new Counters() )->recordDownload() );
	}

	public function test_negative_count_reads_the_stored_count(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( Counters::OPTION_NEGATIVE_COUNT, 0 )
			->andReturn( 3 );

		self::assertSame( 3, ( new Counters() )->negativeCount() );
	}

	public function test_record_negative_increments_and_persists(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( Counters::OPTION_NEGATIVE_COUNT, 0 )
			->andReturn( 2 );

		Functions\expect( 'update_option' )
			->once()
			->with( Counters::OPTION_NEGATIVE_COUNT, 3, false );

		self::assertSame( 3, ( new Counters() )->recordNegative() );
	}
}
