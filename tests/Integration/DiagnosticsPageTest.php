<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Tests\Integration;

use DivineApparitions\UploadsProxy\Admin\OriginProbe;
use DivineApparitions\UploadsProxy\Admin\SettingsPage;
use DivineApparitions\UploadsProxy\Config\BasicAuth;
use DivineApparitions\UploadsProxy\Config\ConstantsFirstResolver;
use DivineApparitions\UploadsProxy\Config\EffectiveConfig;
use DivineApparitions\UploadsProxy\Config\Mode;
use DivineApparitions\UploadsProxy\Config\Source;
use DivineApparitions\UploadsProxy\Config\SystemEnvironment;
use DivineApparitions\UploadsProxy\Proxy\OriginClient;
use DivineApparitions\UploadsProxy\Settings\Settings;
use DivineApparitions\UploadsProxy\State\Counters;
use WP_Error;
use WP_UnitTestCase;

/**
 * The diagnostics-first settings page against a real WordPress install (issue #7).
 *
 * Boots WordPress and exercises three real code paths: the "Test Origin
 * connection" probe through the live HTTP layer (mocked with `pre_http_request`,
 * graded 2xx-only), the write-only password preservation through real
 * `get_option`/`update_option`, and the page render — asserting the
 * status panel masks the password and never emits the stored bytes into the DOM.
 *
 * AUTHORED-ONLY: this machine cannot mount the plugin path into the wp-env Docker
 * container, so this suite is written to be correct against real WordPress but is
 * not executed locally. The decision logic it covers is also unit-tested under
 * tests/Unit/ (Brain Monkey), which does run.
 */
final class DiagnosticsPageTest extends WP_UnitTestCase {

	private const ORIGIN = 'https://origin.example.test';

	/**
	 * The canned HTTP response the mocked Origin returns, or a WP_Error.
	 *
	 * @var array<string, mixed>|WP_Error
	 */
	private array|WP_Error $originResponse;

	public function set_up(): void {
		parent::set_up();

		$this->originResponse = [
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'body'     => '',
			'headers'  => [],
		];

		add_filter( 'pre_http_request', [ $this, 'mockOrigin' ], 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', [ $this, 'mockOrigin' ], 10 );
		delete_option( Settings::OPTION_NAME );
		delete_option( Counters::OPTION_DOWNLOADED );
		delete_option( Counters::OPTION_NEGATIVE_COUNT );
		parent::tear_down();
	}

	/**
	 * @param mixed                $preempt Short-circuit value.
	 * @param array<string, mixed> $args    Request args.
	 * @param string               $url     Requested URL.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function mockOrigin( $preempt, $args, $url ) {
		return $this->originResponse;
	}

	private function probe(): OriginProbe {
		return new OriginProbe( new OriginClient() );
	}

	private function config( ?BasicAuth $basicAuth = null ): EffectiveConfig {
		return new EffectiveConfig(
			self::ORIGIN,
			Source::Constant,
			Mode::Download,
			Source::Constant,
			$basicAuth,
			null === $basicAuth ? Source::DefaultOff : Source::Constant,
			false,
		);
	}

	// -------------------------------------------------------------------------
	// Test Origin probe: only 2xx is reachable.
	// -------------------------------------------------------------------------

	public function test_probe_reports_reachable_on_2xx(): void {
		$this->originResponse['response']['code'] = 200;

		$result = $this->probe()->probe( $this->config() );

		self::assertTrue( $result->isReachable() );
		self::assertSame( 200, $result->statusCode() );
	}

	public function test_probe_reports_unreachable_on_4xx_but_keeps_the_status(): void {
		$this->originResponse['response']['code'] = 403;

		$result = $this->probe()->probe( $this->config() );

		self::assertFalse( $result->isReachable() );
		self::assertSame( 403, $result->statusCode() );
		self::assertTrue( $result->hasResponse() );
	}

	public function test_probe_reports_unreachable_on_5xx(): void {
		$this->originResponse['response']['code'] = 503;

		$result = $this->probe()->probe( $this->config() );

		self::assertFalse( $result->isReachable() );
		self::assertSame( 503, $result->statusCode() );
	}

	public function test_probe_reports_no_response_on_transport_error(): void {
		$this->originResponse = new WP_Error( 'http_request_failed', 'Connection timed out' );

		$result = $this->probe()->probe( $this->config() );

		self::assertFalse( $result->isReachable() );
		self::assertSame( 0, $result->statusCode() );
		self::assertFalse( $result->hasResponse() );
	}

	public function test_probe_targets_the_origin_root_with_basic_auth(): void {
		$seen = [];
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$seen ) {
				$seen['url']  = $url;
				$seen['auth'] = $args['headers']['Authorization'] ?? null;
				return [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'body'     => '',
					'headers'  => [],
				];
			},
			5,
			3
		);

		$this->probe()->probe( $this->config( BasicAuth::fromPair( 'u', 'p' ) ) );

		self::assertSame( self::ORIGIN . '/', $seen['url'] ?? null );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Asserting the RFC 7617 Basic Auth encoding.
		self::assertSame( 'Basic ' . base64_encode( 'u:p' ), $seen['auth'] ?? null );
	}

	// -------------------------------------------------------------------------
	// Write-only password against real options.
	// -------------------------------------------------------------------------

	public function test_empty_password_submit_preserves_the_stored_password(): void {
		update_option(
			Settings::OPTION_NAME,
			[
				'enabled'         => true,
				'origin_url'      => self::ORIGIN,
				'mode'            => 'download',
				'basic_auth_user' => 'auth-user',
				'basic_auth_pass' => 'stored-secret',
			]
		);

		$sanitized = ( new Settings() )->sanitize(
			[
				'enabled'         => '1',
				'origin_url'      => self::ORIGIN,
				'mode'            => 'download',
				'basic_auth_user' => 'auth-user',
				'basic_auth_pass' => '',
			]
		);

		self::assertSame( 'stored-secret', $sanitized['basic_auth_pass'] );
	}

	public function test_non_empty_password_submit_replaces_the_stored_password(): void {
		update_option(
			Settings::OPTION_NAME,
			[
				'enabled'         => true,
				'origin_url'      => self::ORIGIN,
				'mode'            => 'download',
				'basic_auth_user' => 'auth-user',
				'basic_auth_pass' => 'stored-secret',
			]
		);

		$sanitized = ( new Settings() )->sanitize(
			[
				'origin_url'      => self::ORIGIN,
				'mode'            => 'download',
				'basic_auth_user' => 'auth-user',
				'basic_auth_pass' => 'new-secret',
			]
		);

		self::assertSame( 'new-secret', $sanitized['basic_auth_pass'] );
	}

	// -------------------------------------------------------------------------
	// Page render: capability gate, masking, source labels.
	// -------------------------------------------------------------------------

	public function test_render_masks_the_password_and_never_emits_the_real_value(): void {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';

		update_option(
			Settings::OPTION_NAME,
			[
				'enabled'         => true,
				'origin_url'      => self::ORIGIN,
				'mode'            => 'download',
				'basic_auth_user' => 'auth-user',
				'basic_auth_pass' => 'topsecret',
			]
		);

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test shim: get_admin_page_title() reads $title, which wp-admin would set.
		$GLOBALS['title'] = 'Uploads Proxy';

		$output = $this->render();

		self::assertStringContainsString( '••••••••', $output );
		self::assertStringNotContainsString( 'topsecret', $output );
		// The DB-sourced values are labelled with their source.
		self::assertStringContainsString( 'Database option', $output );
		// The Origin appears in the status panel.
		self::assertStringContainsString( self::ORIGIN, $output );
	}

	public function test_render_is_capability_gated(): void {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test shim: get_admin_page_title() reads $title, which wp-admin would set.
		$GLOBALS['title'] = 'Uploads Proxy';

		self::assertSame( '', $this->render() );
	}

	/**
	 * Render the settings page and capture its HTML.
	 */
	private function render(): string {
		$settings = new Settings();
		$page     = new SettingsPage(
			$settings,
			new ConstantsFirstResolver( $settings, new SystemEnvironment() ),
			new Counters(),
			$this->probe(),
		);

		ob_start();
		$page->renderPage();
		return (string) ob_get_clean();
	}
}
