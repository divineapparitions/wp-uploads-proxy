<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Admin;

use DivineApparitions\UploadsProxy\Config\ConfigResolver;
use DivineApparitions\UploadsProxy\Config\Mode;
use DivineApparitions\UploadsProxy\Config\Source;
use DivineApparitions\UploadsProxy\Registrable;
use DivineApparitions\UploadsProxy\Settings\Settings;
use DivineApparitions\UploadsProxy\State\Counters;

/**
 * The diagnostics-first settings page under Settings → Uploads Proxy.
 *
 * Its primary job is to let a developer confirm at a glance that the proxy is
 * working: it shows the active status, the effective Origin and mode each labelled
 * with where the value came from (constant / env / DB, read from the resolver),
 * the download and Negative-cache counters, and a "Test Origin connection" button.
 *
 * Per ADR-0002 the DB fields (Origin, mode, Basic Auth) are editable only when no
 * constant/env var overrides them; an overridden field is shown read-only in the
 * status panel. The Basic Auth password is write-only: it is never rendered into
 * the DOM — the status panel shows a fixed mask when one is stored, and the editor
 * offers an empty field that only overwrites the stored password when filled in
 * (issue #7).
 *
 * Decision logic lives in the WordPress-free {@see Diagnostics}, {@see OriginProbe},
 * and {@see ProbeResult}; this class only renders and escapes their output.
 */
final class SettingsPage implements Registrable {

	private const MENU_SLUG    = 'uploads-proxy';
	private const PARENT_SLUG  = 'options-general.php';
	private const SETTINGS_KEY = 'uploads_proxy';

	/**
	 * `admin_post` action name (and nonce action) for the Test Origin button.
	 */
	private const ACTION_TEST_ORIGIN = 'uploads_proxy_test_origin';

	/**
	 * Fixed mask shown for a stored Basic Auth password — never the real bytes,
	 * never its length (issue #7).
	 */
	private const PASSWORD_MASK = '••••••••';

	public function __construct(
		private readonly Settings $settings,
		private readonly ConfigResolver $resolver,
		private readonly Counters $counters,
		private readonly OriginProbe $probe,
	) {
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'addMenuPage' ] );
		add_action( 'admin_init', [ $this, 'registerSettings' ] );
		add_action( 'admin_post_' . self::ACTION_TEST_ORIGIN, [ $this, 'handleTestOrigin' ] );
	}

	public function addMenuPage(): void {
		add_options_page(
			__( 'Uploads Proxy', 'uploads-proxy' ),
			__( 'Uploads Proxy', 'uploads-proxy' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'renderPage' ]
		);
	}

	public function registerSettings(): void {
		register_setting(
			self::SETTINGS_KEY,
			Settings::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this->settings, 'sanitize' ],
				'default'           => $this->settings->defaults(),
			]
		);
	}

	/**
	 * Handle the "Test Origin connection" POST: probe the Origin and redirect
	 * back with the result encoded as a status code (issue #7).
	 *
	 * Capability-gated and nonce-protected so it cannot be triggered via CSRF.
	 */
	public function handleTestOrigin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to test the Origin connection.', 'uploads-proxy' ) );
		}

		check_admin_referer( self::ACTION_TEST_ORIGIN );

		$config = $this->resolver->resolve();
		$args   = [ 'page' => self::MENU_SLUG ];

		if ( '' !== $config->origin() ) {
			$args['probe_status'] = $this->probe->probe( $config )->statusCode();
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( self::PARENT_SLUG ) ) );
		exit;
	}

	public function renderPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$config      = $this->resolver->resolve();
		$diagnostics = new Diagnostics(
			$config,
			$this->counters->downloaded(),
			$this->counters->negativeCount(),
		);

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html( get_admin_page_title() ) );

		$this->renderProbeNotice();
		$this->renderStatusPanel( $diagnostics );
		$this->renderTestOriginForm( $diagnostics );
		$this->renderSettingsForm( $diagnostics );

		echo '</div>';
	}

	/**
	 * Render the result of a Test Origin probe, read from the redirect query arg.
	 */
	private function renderProbeNotice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice rendered after our own nonce-checked admin-post redirect; reads an HTTP status code, changes nothing.
		if ( ! isset( $_GET['probe_status'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above; absint() sanitises the status code.
		$result = new ProbeResult( absint( wp_unslash( $_GET['probe_status'] ) ) );
		$code   = $result->statusCode();

		if ( $result->isReachable() ) {
			$class = 'notice-success';
			$text  = sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Origin is reachable (HTTP %d).', 'uploads-proxy' ),
				$code
			);
		} elseif ( $result->hasResponse() ) {
			$class = 'notice-error';
			$text  = sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Origin is unreachable (HTTP %d).', 'uploads-proxy' ),
				$code
			);
		} else {
			$class = 'notice-error';
			$text  = __( 'Origin is unreachable (no response).', 'uploads-proxy' );
		}

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $text )
		);
	}

	/**
	 * The read-only diagnostics panel: status, effective values + their sources,
	 * and the counters.
	 */
	private function renderStatusPanel( Diagnostics $diagnostics ): void {
		echo '<h2>' . esc_html__( 'Status', 'uploads-proxy' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:720px">';
		echo '<tbody>';

		$this->renderStatusRow(
			esc_html__( 'Proxy', 'uploads-proxy' ),
			$diagnostics->isActive()
				? esc_html__( 'Active', 'uploads-proxy' )
				: esc_html__( 'Inactive', 'uploads-proxy' )
		);

		$origin = '' !== $diagnostics->origin()
			? esc_html( $diagnostics->origin() )
			: esc_html__( 'Not configured', 'uploads-proxy' );
		$this->renderStatusRow(
			esc_html__( 'Origin', 'uploads-proxy' ),
			$origin . ' ' . $this->sourceBadge( $diagnostics->originSource() )
		);

		$this->renderStatusRow(
			esc_html__( 'Mode', 'uploads-proxy' ),
			esc_html( $diagnostics->mode()->label() ) . ' ' . $this->sourceBadge( $diagnostics->modeSource() )
		);

		$this->renderStatusRow(
			esc_html__( 'Origin Basic Auth', 'uploads-proxy' ),
			$this->basicAuthSummary( $diagnostics ) . ' ' . $this->sourceBadge( $diagnostics->basicAuthSource() )
		);

		$this->renderStatusRow(
			esc_html__( 'Files downloaded', 'uploads-proxy' ),
			esc_html( (string) $diagnostics->downloadedCount() )
		);

		$this->renderStatusRow(
			esc_html__( 'Negative-cache entries', 'uploads-proxy' ),
			esc_html( (string) $diagnostics->negativeCacheCount() )
		);

		echo '</tbody></table>';
	}

	/**
	 * One status row. Both arguments must already be escaped by the caller.
	 *
	 * @param string $label Escaped row label.
	 * @param string $value Escaped row value (may contain safe markup).
	 */
	private function renderStatusRow( string $label, string $value ): void {
		printf(
			'<tr><th scope="row" style="width:200px">%1$s</th><td>%2$s</td></tr>',
			$label, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped by the caller.
			$value  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped by the caller.
		);
	}

	/**
	 * The Basic Auth summary for the status panel: username + a fixed password
	 * mask when set, or "Not set". Returns escaped, display-ready markup and never
	 * the real password (issue #7).
	 */
	private function basicAuthSummary( Diagnostics $diagnostics ): string {
		if ( ! $diagnostics->hasBasicAuth() ) {
			return esc_html__( 'Not set', 'uploads-proxy' );
		}

		return sprintf(
			/* translators: 1: Basic Auth username, 2: a fixed password mask. */
			esc_html__( '%1$s (password %2$s)', 'uploads-proxy' ),
			esc_html( $diagnostics->basicAuthUsername() ),
			esc_html( self::PASSWORD_MASK )
		);
	}

	/**
	 * Escaped source-label badge (constant / env / DB / default).
	 */
	private function sourceBadge( Source $source ): string {
		return '<code>' . esc_html( $source->label() ) . '</code>';
	}

	/**
	 * The "Test Origin connection" form. Only shown once an Origin is configured.
	 */
	private function renderTestOriginForm( Diagnostics $diagnostics ): void {
		if ( '' === $diagnostics->origin() ) {
			return;
		}

		printf(
			'<form action="%s" method="post">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		printf(
			'<input type="hidden" name="action" value="%s" />',
			esc_attr( self::ACTION_TEST_ORIGIN )
		);
		wp_nonce_field( self::ACTION_TEST_ORIGIN );
		submit_button(
			__( 'Test Origin connection', 'uploads-proxy' ),
			'secondary',
			'submit',
			false
		);
		echo '</form>';
	}

	/**
	 * The editable settings form. Each field renders as a real input when it is
	 * editable, or as a hidden value-preserving input when a constant/env var
	 * overrides it (ADR-0002), so saving never wipes a shadowed DB value. The form
	 * is suppressed entirely when every field is overridden.
	 */
	private function renderSettingsForm( Diagnostics $diagnostics ): void {
		echo '<h2>' . esc_html__( 'Settings', 'uploads-proxy' ) . '</h2>';

		$anyEditable = $diagnostics->isOriginEditable()
			|| $diagnostics->isModeEditable()
			|| $diagnostics->isBasicAuthEditable();

		if ( ! $anyEditable ) {
			echo '<p class="description">' . esc_html__(
				'Every setting is supplied by a constant or environment variable, so there is nothing to edit here. The effective values and their sources are shown in the status panel above.',
				'uploads-proxy'
			) . '</p>';
			return;
		}

		echo '<form action="options.php" method="post">';
		settings_fields( self::SETTINGS_KEY );
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->renderEnabledRow( $diagnostics );
		$this->renderOriginRow( $diagnostics );
		$this->renderModeRow( $diagnostics );
		$this->renderBasicAuthRow( $diagnostics );

		echo '</tbody></table>';
		submit_button();
		echo '</form>';
	}

	/**
	 * Emit a hidden input under the settings option for $key with $value.
	 */
	private function hiddenField( string $key, string $value ): void {
		printf(
			'<input type="hidden" name="%1$s[%2$s]" value="%3$s" />',
			esc_attr( Settings::OPTION_NAME ),
			esc_attr( $key ),
			esc_attr( $value )
		);
	}

	/**
	 * The enabled flag. Editable only when the Origin itself is editable — a
	 * constant/env Origin is self-enabling, so the stored flag is just preserved.
	 */
	private function renderEnabledRow( Diagnostics $diagnostics ): void {
		if ( ! $diagnostics->isOriginEditable() ) {
			$this->hiddenField( 'enabled', $this->settings->dbEnabled() ? '1' : '0' );
			return;
		}

		echo '<tr><th scope="row">' . esc_html__( 'Enable proxying', 'uploads-proxy' ) . '</th><td>';
		printf(
			'<label><input type="checkbox" name="%1$s[enabled]" value="1" %2$s /> %3$s</label>',
			esc_attr( Settings::OPTION_NAME ),
			checked( $this->settings->dbEnabled(), true, false ),
			esc_html__( 'Proxy Uploads that are missing locally to the Origin.', 'uploads-proxy' )
		);
		echo '</td></tr>';
	}

	private function renderOriginRow( Diagnostics $diagnostics ): void {
		if ( ! $diagnostics->isOriginEditable() ) {
			$this->hiddenField( 'origin_url', $this->settings->dbOriginUrl() );
			return;
		}

		echo '<tr><th scope="row"><label for="uploads_proxy_origin_url">' . esc_html__( 'Origin URL', 'uploads-proxy' ) . '</label></th><td>';
		printf(
			'<input type="url" class="regular-text code" id="uploads_proxy_origin_url" name="%1$s[origin_url]" value="%2$s" placeholder="https://example.com" />',
			esc_attr( Settings::OPTION_NAME ),
			esc_attr( $this->settings->dbOriginUrl() )
		);
		echo '<p class="description">' . esc_html__(
			'Scheme and host of the Origin, e.g. https://example.com (no trailing slash needed).',
			'uploads-proxy'
		) . '</p>';
		echo '</td></tr>';
	}

	private function renderModeRow( Diagnostics $diagnostics ): void {
		if ( ! $diagnostics->isModeEditable() ) {
			$this->hiddenField( 'mode', $this->settings->dbMode() );
			return;
		}

		$current = $this->settings->dbMode();

		echo '<tr><th scope="row"><label for="uploads_proxy_mode">' . esc_html__( 'Mode', 'uploads-proxy' ) . '</label></th><td>';
		printf(
			'<select id="uploads_proxy_mode" name="%s[mode]">',
			esc_attr( Settings::OPTION_NAME )
		);
		foreach ( $this->modeChoices() as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__(
			'Download saves a missing file locally and serves it; Hotlink redirects the browser to the Origin.',
			'uploads-proxy'
		) . '</p>';
		echo '</td></tr>';
	}

	/**
	 * The Basic Auth row. The username is editable; the password is write-only —
	 * the field is always empty and only a non-empty submission replaces the
	 * stored password, so the real value never reaches the DOM (issue #7).
	 */
	private function renderBasicAuthRow( Diagnostics $diagnostics ): void {
		if ( ! $diagnostics->isBasicAuthEditable() ) {
			// Preserve the stored username; the password is preserved by the
			// write-only Settings::sanitize() when no field is submitted.
			$this->hiddenField( 'basic_auth_user', $this->settings->dbBasicAuthUser() );
			return;
		}

		$passwordIsSet = '' !== $this->settings->dbBasicAuthPass();

		echo '<tr><th scope="row"><label for="uploads_proxy_basic_auth_user">' . esc_html__( 'Origin Basic Auth', 'uploads-proxy' ) . '</label></th><td>';
		printf(
			'<input type="text" class="regular-text" id="uploads_proxy_basic_auth_user" name="%1$s[basic_auth_user]" value="%2$s" autocomplete="off" placeholder="%3$s" />',
			esc_attr( Settings::OPTION_NAME ),
			esc_attr( $this->settings->dbBasicAuthUser() ),
			esc_attr__( 'Username', 'uploads-proxy' )
		);
		printf(
			' <input type="password" class="regular-text" name="%1$s[basic_auth_pass]" value="" autocomplete="new-password" placeholder="%2$s" />',
			esc_attr( Settings::OPTION_NAME ),
			$passwordIsSet
				? esc_attr__( 'Set — leave blank to keep', 'uploads-proxy' )
				: esc_attr__( 'No password set', 'uploads-proxy' )
		);
		echo '<p class="description">';
		echo $passwordIsSet
			? esc_html__( 'A password is stored. Leave the field blank to keep it, or type a new one to replace it.', 'uploads-proxy' )
			: esc_html__( 'Optional Basic Auth credentials sent with the outbound Origin request, for a locked Test or Dev Origin.', 'uploads-proxy' );
		echo '</p>';
		echo '</td></tr>';
	}

	/**
	 * Available resolution modes, value => label.
	 *
	 * @return array<string, string>
	 */
	private function modeChoices(): array {
		return [
			Mode::Download->value => Mode::Download->label(),
			Mode::Hotlink->value  => Mode::Hotlink->label(),
		];
	}
}
