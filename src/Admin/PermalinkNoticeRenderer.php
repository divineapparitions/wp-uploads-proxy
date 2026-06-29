<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Admin;

use DivineApparitions\UploadsProxy\Config\ConfigResolver;
use DivineApparitions\UploadsProxy\Registrable;

/**
 * WordPress glue for the Apache-plain-permalinks notice (issue #9).
 *
 * A thin adapter over the WordPress-free {@see PermalinkNotice} decision seam: it
 * reads the conventional WordPress signals — `$GLOBALS['is_apache']` (set by core
 * in `wp-includes/vars.php`) and `get_option( 'permalink_structure' )` (empty ⇒
 * plain, non-empty ⇒ pretty) — together with the proxy's active state from the
 * resolver, and renders an escaped, translated notice on `admin_notices` when, and
 * only when, the proxy would silently appear inert.
 *
 * The active state is gated exactly as request interception is (see
 * {@see \DivineApparitions\UploadsProxy\Proxy\RequestHandler}): the proxy is inert
 * on `wp_get_environment_type() === 'production'`, so the notice is suppressed
 * there too. The environment type is injected as a callable to mirror that handler
 * and keep this adapter free of hard WordPress calls beyond rendering.
 */
final class PermalinkNoticeRenderer implements Registrable {

	/**
	 * @param ConfigResolver       $resolver        Effective configuration source.
	 * @param PermalinkNotice      $notice          The decision seam.
	 * @param (callable(): string) $environmentType Resolves the current environment type.
	 */
	public function __construct(
		private readonly ConfigResolver $resolver,
		private readonly PermalinkNotice $notice,
		private $environmentType,
	) {
	}

	public function register(): void {
		add_action( 'admin_notices', [ $this, 'maybeRender' ] );
	}

	/**
	 * Render the notice when the seam says it applies.
	 */
	public function maybeRender(): void {
		// Only admins who could act on the advice should see it.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->notice->shouldShow( $this->isApache(), $this->hasPrettyPermalinks(), $this->isActive() ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'Uploads Proxy:', 'uploads-proxy' ),
			esc_html__(
				'the proxy is active, but this site runs on Apache with plain permalinks. A Miss is only intercepted when the web server routes the missing file to index.php, which on Apache requires pretty permalinks. Enable pretty permalinks under Settings → Permalinks for the proxy to work.',
				'uploads-proxy'
			)
		);
	}

	/**
	 * Whether the proxy is configured and active — gated by the production guard
	 * so the notice tracks the same inert-on-production rule as interception.
	 */
	private function isActive(): bool {
		if ( 'production' === ( $this->environmentType )() ) {
			return false;
		}

		return $this->resolver->resolve()->isEnabled();
	}

	/**
	 * Whether the web server is Apache, via the WordPress-provided global.
	 *
	 * nginx (and any non-Apache server) reports false, so it never sees the notice.
	 */
	private function isApache(): bool {
		return ! empty( $GLOBALS['is_apache'] );
	}

	/**
	 * Whether pretty permalinks are enabled: a non-empty `permalink_structure`.
	 */
	private function hasPrettyPermalinks(): bool {
		return '' !== (string) get_option( 'permalink_structure', '' );
	}
}
