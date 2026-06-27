<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Tests\Unit\Admin;

use DivineApparitions\UploadsProxy\Admin\PermalinkNotice;
use PHPUnit\Framework\TestCase;

/**
 * The WordPress-free decision seam behind the Apache-plain-permalinks notice.
 *
 * Request interception relies on the web server routing a missing uploads file to
 * `index.php`. On nginx (the real target) `try_files` always does this; on Apache
 * the `index.php` fallback only fires with pretty permalinks. So the notice is the
 * one combination where the proxy would silently appear inert: Apache, plain
 * permalinks, and the proxy active. This seam encodes exactly that truth table,
 * free of WordPress and HTML so it can be asserted directly; the thin
 * {@see \DivineApparitions\UploadsProxy\Admin\PermalinkNoticeRenderer} reads the
 * WordPress globals/option and renders the escaped, translated output.
 *
 * @covers \DivineApparitions\UploadsProxy\Admin\PermalinkNotice
 */
final class PermalinkNoticeTest extends TestCase {

	private PermalinkNotice $notice;

	protected function setUp(): void {
		parent::setUp();
		$this->notice = new PermalinkNotice();
	}

	public function test_shows_on_apache_with_plain_permalinks_when_active(): void {
		self::assertTrue(
			$this->notice->shouldShow( isApache: true, hasPrettyPermalinks: false, isActive: true )
		);
	}

	public function test_hidden_on_apache_when_pretty_permalinks_are_enabled(): void {
		self::assertFalse(
			$this->notice->shouldShow( isApache: true, hasPrettyPermalinks: true, isActive: true )
		);
	}

	public function test_hidden_on_nginx_regardless_of_permalinks(): void {
		self::assertFalse(
			$this->notice->shouldShow( isApache: false, hasPrettyPermalinks: false, isActive: true )
		);
		self::assertFalse(
			$this->notice->shouldShow( isApache: false, hasPrettyPermalinks: true, isActive: true )
		);
	}

	public function test_hidden_when_the_proxy_is_inactive(): void {
		self::assertFalse(
			$this->notice->shouldShow( isApache: true, hasPrettyPermalinks: false, isActive: false )
		);
	}
}
