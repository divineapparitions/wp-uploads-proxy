<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Admin;

/**
 * Decision logic for the production-environment admin notice.
 *
 * Request interception (ADR-0001) is deliberately inert when
 * `wp_get_environment_type() === 'production'` — the proxy must never act on the
 * live Origin itself. But a default DDEV, Lando, or vanilla WordPress install
 * reports `production` unless `WP_ENVIRONMENT_TYPE` is set, so a user can enable
 * the proxy and point it at a reachable Origin yet see every Miss silently
 * ignored, with no feedback.
 *
 * This is the one combination worth flagging: the proxy is enabled but the
 * environment is production. The seam is WordPress- and HTML-free so the truth
 * table can be unit tested directly; the thin {@see EnvironmentNoticeRenderer}
 * reads the environment type and resolver state and renders the escaped,
 * translated copy that tells the user to set `WP_ENVIRONMENT_TYPE`.
 */
final class EnvironmentNotice {

	/**
	 * Whether the production-environment notice should be shown.
	 *
	 * @param bool $isProduction The environment type is `production`.
	 * @param bool $isEnabled    The proxy is configured and turned on.
	 */
	public function shouldShow( bool $isProduction, bool $isEnabled ): bool {
		return $isProduction && $isEnabled;
	}
}
