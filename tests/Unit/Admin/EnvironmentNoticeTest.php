<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Tests\Unit\Admin;

use DivineApparitions\UploadsProxy\Admin\EnvironmentNotice;
use PHPUnit\Framework\TestCase;

/**
 * The WordPress-free decision seam behind the production-environment notice.
 *
 * Request interception is inert when `wp_get_environment_type() === 'production'`
 * (the "never proxy on the live Origin" guard in
 * {@see \DivineApparitions\UploadsProxy\Proxy\RequestHandler}). A default DDEV /
 * Lando / vanilla WordPress install reports `production` unless `WP_ENVIRONMENT_TYPE`
 * is set, so a user can enable the proxy and configure a reachable Origin yet see
 * nothing happen, with no feedback. This is the one combination worth flagging:
 * the proxy is enabled but the environment is production. The seam encodes exactly
 * that truth table, free of WordPress and HTML so it can be asserted directly; the
 * thin {@see \DivineApparitions\UploadsProxy\Admin\EnvironmentNoticeRenderer} reads
 * the environment type and resolver state and renders the escaped, translated output.
 *
 * @covers \DivineApparitions\UploadsProxy\Admin\EnvironmentNotice
 */
final class EnvironmentNoticeTest extends TestCase {

	private EnvironmentNotice $notice;

	protected function setUp(): void {
		parent::setUp();
		$this->notice = new EnvironmentNotice();
	}

	public function test_shows_when_enabled_on_a_production_environment(): void {
		self::assertTrue(
			$this->notice->shouldShow( isProduction: true, isEnabled: true )
		);
	}

	public function test_hidden_on_a_non_production_environment(): void {
		self::assertFalse(
			$this->notice->shouldShow( isProduction: false, isEnabled: true )
		);
	}

	public function test_hidden_when_the_proxy_is_not_enabled(): void {
		self::assertFalse(
			$this->notice->shouldShow( isProduction: true, isEnabled: false )
		);
		self::assertFalse(
			$this->notice->shouldShow( isProduction: false, isEnabled: false )
		);
	}
}
