<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy;

use DivineApparitions\UploadsProxy\Admin\OriginProbe;
use DivineApparitions\UploadsProxy\Admin\SettingsPage;
use DivineApparitions\UploadsProxy\Config\ConstantsFirstResolver;
use DivineApparitions\UploadsProxy\Config\SystemEnvironment;
use DivineApparitions\UploadsProxy\Proxy\FileWriter;
use DivineApparitions\UploadsProxy\Proxy\HttpResponder;
use DivineApparitions\UploadsProxy\Proxy\OriginClient;
use DivineApparitions\UploadsProxy\Proxy\RequestHandler;
use DivineApparitions\UploadsProxy\Proxy\UploadsScope;
use DivineApparitions\UploadsProxy\Settings\Settings;
use DivineApparitions\UploadsProxy\State\Counters;
use DivineApparitions\UploadsProxy\State\NegativeCache;

/**
 * Wires the plugin's components into WordPress.
 *
 * Kept intentionally thin: it constructs each component and lets the component
 * register its own hooks. No business logic lives here.
 */
final class Plugin {

	/**
	 * Components that register their own WordPress hooks.
	 *
	 * @var list<Registrable>
	 */
	private array $components;

	/**
	 * @param string $file    Absolute path to the main plugin file.
	 * @param string $version Plugin version.
	 */
	public function __construct(
		private readonly string $file,
		private readonly string $version,
	) {
		$settings = new Settings();
		$resolver = new ConstantsFirstResolver( $settings, new SystemEnvironment() );
		$counters = new Counters();

		$this->components = [
			new SettingsPage( $settings, $resolver, $counters, new OriginProbe( new OriginClient() ) ),
			new RequestHandler(
				$resolver,
				new OriginClient(),
				new FileWriter(),
				$counters,
				new NegativeCache(),
				new HttpResponder(),
				static fn (): UploadsScope => UploadsScope::fromWordPress(),
				static fn (): string => wp_get_environment_type(),
			),
		];
	}

	/**
	 * Register all hooks for the plugin and its components.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'loadTextDomain' ] );

		foreach ( $this->components as $component ) {
			$component->register();
		}
	}

	/**
	 * Load translations on `init` (WordPress 6.7+ warns if done earlier).
	 */
	public function loadTextDomain(): void {
		load_plugin_textdomain(
			'uploads-proxy',
			false,
			dirname( plugin_basename( $this->file ) ) . '/languages'
		);
	}

	/**
	 * Runs on plugin activation. Seeds default options.
	 */
	public static function activate(): void {
		$settings = new Settings();

		if ( false === get_option( Settings::OPTION_NAME, false ) ) {
			add_option( Settings::OPTION_NAME, $settings->defaults() );
		}
	}

	/**
	 * Runs on plugin deactivation. Nothing persistent to tear down yet.
	 */
	public static function deactivate(): void {
		// Intentionally empty. Option cleanup happens in uninstall.php.
	}

	public function version(): string {
		return $this->version;
	}
}
