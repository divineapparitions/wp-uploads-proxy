<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Admin;

use DivineApparitions\UploadsProxy\Config\ConfigResolver;
use DivineApparitions\UploadsProxy\Registrable;

/**
 * WordPress glue for the production-environment notice.
 *
 * A thin adapter over the WordPress-free {@see EnvironmentNotice} decision seam:
 * it reads the current environment type and the proxy's enabled state from the
 * resolver, and renders an escaped, translated notice on `admin_notices` when, and
 * only when, the proxy is enabled but the environment is `production` — the exact
 * combination where interception silently no-ops (see
 * {@see \DivineApparitions\UploadsProxy\Proxy\RequestHandler}).
 *
 * Unlike {@see PermalinkNoticeRenderer}, the enabled state here is the raw
 * `isEnabled()` and is NOT gated by the production guard: this notice exists
 * precisely to explain why an enabled proxy does nothing on production. The
 * environment type is injected as a callable to mirror the handler and keep this
 * adapter free of hard WordPress calls beyond rendering.
 */
final class EnvironmentNoticeRenderer implements Registrable {

	/**
	 * @param ConfigResolver       $resolver        Effective configuration source.
	 * @param EnvironmentNotice    $notice          The decision seam.
	 * @param (callable(): string) $environmentType Resolves the current environment type.
	 */
	public function __construct(
		private readonly ConfigResolver $resolver,
		private readonly EnvironmentNotice $notice,
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

		if ( ! $this->notice->shouldShow( $this->isProduction(), $this->isEnabled() ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'Uploads Proxy:', 'uploads-proxy' ),
			esc_html__(
				'the proxy is enabled, but this site reports the "production" environment type, where the proxy stays inert so it never acts on the live Origin. Set WP_ENVIRONMENT_TYPE to "local", "development", or "staging" (for example in wp-config.php) for the proxy to work.',
				'uploads-proxy'
			)
		);
	}

	/**
	 * Whether the current environment type is production.
	 */
	private function isProduction(): bool {
		return 'production' === ( $this->environmentType )();
	}

	/**
	 * Whether the proxy is configured and enabled — the raw state, deliberately
	 * NOT gated by the production guard so this notice can fire on production.
	 */
	private function isEnabled(): bool {
		return $this->resolver->resolve()->isEnabled();
	}
}
