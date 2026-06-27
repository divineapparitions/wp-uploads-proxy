<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Tests\Integration;

use DivineApparitions\UploadsProxy\Admin\PermalinkNotice;
use DivineApparitions\UploadsProxy\Admin\PermalinkNoticeRenderer;
use DivineApparitions\UploadsProxy\Config\ConstantsFirstResolver;
use DivineApparitions\UploadsProxy\Config\SystemEnvironment;
use DivineApparitions\UploadsProxy\Settings\Settings;
use WP_UnitTestCase;

/**
 * The Apache-plain-permalinks admin notice against a real WordPress install
 * (issue #9).
 *
 * Boots WordPress and renders the `admin_notices` adapter across the truth table
 * using the real signals the production code reads: the `$GLOBALS['is_apache']`
 * global, the real `permalink_structure` option, and the resolver's active state
 * computed from real options. The notice must appear only on Apache + plain
 * permalinks + active, and never on nginx, with pretty permalinks, when inert, or
 * for a non-admin.
 *
 * AUTHORED-ONLY: this machine cannot mount the plugin path into the wp-env Docker
 * container, so this suite is written to be correct against real WordPress but is
 * not executed locally. The decision logic it covers is also unit-tested under
 * tests/Unit/Admin/PermalinkNoticeTest.php (Brain Monkey-free), which does run.
 */
final class PermalinkNoticeTest extends WP_UnitTestCase {

	private const ORIGIN = 'https://origin.example.test';

	/**
	 * Saved $GLOBALS['is_apache'] so each test can restore it.
	 *
	 * @var mixed
	 */
	private $savedIsApache;

	public function set_up(): void {
		parent::set_up();
		$this->savedIsApache = $GLOBALS['is_apache'] ?? null;
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down(): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the server-type global the test set.
		$GLOBALS['is_apache'] = $this->savedIsApache;
		delete_option( Settings::OPTION_NAME );
		update_option( 'permalink_structure', '' );
		parent::tear_down();
	}

	/**
	 * Configure the environment and capture the rendered `admin_notices` output.
	 */
	private function render( bool $isApache, bool $pretty, bool $active ): string {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the server-type global WP sets in wp-includes/vars.php.
		$GLOBALS['is_apache'] = $isApache;
		update_option( 'permalink_structure', $pretty ? '/%postname%/' : '' );
		update_option(
			Settings::OPTION_NAME,
			[
				'enabled'    => $active,
				'origin_url' => $active ? self::ORIGIN : '',
				'mode'       => 'download',
			]
		);

		$settings = new Settings();
		$renderer = new PermalinkNoticeRenderer(
			new ConstantsFirstResolver( $settings, new SystemEnvironment() ),
			new PermalinkNotice(),
			// Non-production so the proxy is allowed to be active.
			static fn (): string => 'local',
		);

		ob_start();
		$renderer->maybeRender();
		return (string) ob_get_clean();
	}

	public function test_notice_shows_on_apache_with_plain_permalinks_when_active(): void {
		$output = $this->render( isApache: true, pretty: false, active: true );

		self::assertStringContainsString( 'notice-warning', $output );
		self::assertStringContainsString( 'pretty permalinks', $output );
	}

	public function test_notice_hidden_on_apache_with_pretty_permalinks(): void {
		self::assertSame( '', $this->render( isApache: true, pretty: true, active: true ) );
	}

	public function test_notice_hidden_on_nginx(): void {
		self::assertSame( '', $this->render( isApache: false, pretty: false, active: true ) );
		self::assertSame( '', $this->render( isApache: false, pretty: true, active: true ) );
	}

	public function test_notice_hidden_when_inert(): void {
		self::assertSame( '', $this->render( isApache: true, pretty: false, active: false ) );
	}

	public function test_notice_hidden_on_production(): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating Apache.
		$GLOBALS['is_apache'] = true;
		update_option( 'permalink_structure', '' );
		update_option(
			Settings::OPTION_NAME,
			[
				'enabled'    => true,
				'origin_url' => self::ORIGIN,
				'mode'       => 'download',
			]
		);

		$settings = new Settings();
		$renderer = new PermalinkNoticeRenderer(
			new ConstantsFirstResolver( $settings, new SystemEnvironment() ),
			new PermalinkNotice(),
			static fn (): string => 'production',
		);

		ob_start();
		$renderer->maybeRender();
		self::assertSame( '', (string) ob_get_clean() );
	}

	public function test_notice_hidden_for_non_admin(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		self::assertSame( '', $this->render( isApache: true, pretty: false, active: true ) );
	}
}
