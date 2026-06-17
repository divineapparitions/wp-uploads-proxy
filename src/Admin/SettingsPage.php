<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Admin;

use DivineApparitions\UploadsProxy\Config\Mode;
use DivineApparitions\UploadsProxy\Registrable;
use DivineApparitions\UploadsProxy\Settings\Settings;

/**
 * Adds a Settings API page under Settings → Uploads Proxy.
 */
final class SettingsPage implements Registrable {

	private const MENU_SLUG    = 'uploads-proxy';
	private const SETTINGS_KEY = 'uploads_proxy';
	private const SECTION_ID   = 'uploads_proxy_main';

	public function __construct(
		private readonly Settings $settings,
	) {
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'addMenuPage' ] );
		add_action( 'admin_init', [ $this, 'registerSettings' ] );
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

		add_settings_section(
			self::SECTION_ID,
			__( 'Origin', 'uploads-proxy' ),
			[ $this, 'renderSectionIntro' ],
			self::MENU_SLUG
		);

		add_settings_field(
			'enabled',
			__( 'Enable proxying', 'uploads-proxy' ),
			[ $this, 'renderEnabledField' ],
			self::MENU_SLUG,
			self::SECTION_ID,
			[ 'label_for' => 'uploads_proxy_enabled' ]
		);

		add_settings_field(
			'origin_url',
			__( 'Origin URL', 'uploads-proxy' ),
			[ $this, 'renderOriginUrlField' ],
			self::MENU_SLUG,
			self::SECTION_ID,
			[ 'label_for' => 'uploads_proxy_origin_url' ]
		);

		add_settings_field(
			'mode',
			__( 'Mode', 'uploads-proxy' ),
			[ $this, 'renderModeField' ],
			self::MENU_SLUG,
			self::SECTION_ID,
			[ 'label_for' => 'uploads_proxy_mode' ]
		);

		add_settings_field(
			'basic_auth',
			__( 'Origin Basic Auth', 'uploads-proxy' ),
			[ $this, 'renderBasicAuthField' ],
			self::MENU_SLUG,
			self::SECTION_ID,
			[ 'label_for' => 'uploads_proxy_basic_auth_user' ]
		);
	}

	public function renderSectionIntro(): void {
		echo '<p>' . esc_html__(
			'Point this environment at an Origin to serve Uploads that are missing from the local uploads directory. A constant or environment variable overrides these database fields.',
			'uploads-proxy'
		) . '</p>';
	}

	public function renderEnabledField(): void {
		printf(
			'<label><input type="checkbox" id="uploads_proxy_enabled" name="%1$s[enabled]" value="1" %2$s /> %3$s</label>',
			esc_attr( Settings::OPTION_NAME ),
			checked( $this->settings->dbEnabled(), true, false ),
			esc_html__( 'Proxy Uploads that are missing locally to the Origin.', 'uploads-proxy' )
		);
	}

	public function renderOriginUrlField(): void {
		printf(
			'<input type="url" class="regular-text code" id="uploads_proxy_origin_url" name="%1$s[origin_url]" value="%2$s" placeholder="https://example.com" />',
			esc_attr( Settings::OPTION_NAME ),
			esc_attr( $this->settings->dbOriginUrl() )
		);
		echo '<p class="description">' . esc_html__(
			'Scheme and host of the Origin, e.g. https://example.com (no trailing slash needed).',
			'uploads-proxy'
		) . '</p>';
	}

	public function renderModeField(): void {
		$current = $this->settings->dbMode();

		printf(
			'<select id="uploads_proxy_mode" name="%1$s[mode]">',
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
	}

	public function renderBasicAuthField(): void {
		printf(
			'<input type="text" class="regular-text" id="uploads_proxy_basic_auth_user" name="%1$s[basic_auth_user]" value="%2$s" autocomplete="off" placeholder="%3$s" />',
			esc_attr( Settings::OPTION_NAME ),
			esc_attr( $this->settings->dbBasicAuthUser() ),
			esc_attr__( 'Username', 'uploads-proxy' )
		);
		printf(
			' <input type="password" class="regular-text" name="%1$s[basic_auth_pass]" value="%2$s" autocomplete="off" placeholder="%3$s" />',
			esc_attr( Settings::OPTION_NAME ),
			esc_attr( $this->settings->dbBasicAuthPass() ),
			esc_attr__( 'Password', 'uploads-proxy' )
		);
		echo '<p class="description">' . esc_html__(
			'Optional Basic Auth credentials sent with the outbound Origin request, for a locked Test or Dev Origin.',
			'uploads-proxy'
		) . '</p>';
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

	public function renderPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::SETTINGS_KEY );
				do_settings_sections( self::MENU_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
