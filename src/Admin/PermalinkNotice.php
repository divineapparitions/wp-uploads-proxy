<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Admin;

/**
 * Decision logic for the Apache-plain-permalinks admin notice.
 *
 * Request interception (ADR-0001) relies on the web server routing a missing
 * uploads file to `index.php`. On nginx — the real target (DDEV, Lando, Pantheon)
 * — `try_files` always does this, so nginx must never see this notice. On Apache,
 * that `index.php` fallback only fires when pretty permalinks are enabled; with
 * plain (default) permalinks an active proxy would silently appear to do nothing.
 *
 * This is the one combination worth flagging: Apache, plain permalinks, proxy
 * active. The seam is WordPress- and HTML-free so the truth table can be unit
 * tested directly; the thin {@see PermalinkNoticeRenderer} reads the WordPress
 * globals/option, applies the active/production gate, and renders the escaped,
 * translated copy.
 */
final class PermalinkNotice {

	/**
	 * Whether the Apache-plain-permalinks notice should be shown.
	 *
	 * @param bool $isApache            Web server is Apache (not nginx).
	 * @param bool $hasPrettyPermalinks Pretty permalinks are enabled.
	 * @param bool $isActive            The proxy is configured and active.
	 */
	public function shouldShow( bool $isApache, bool $hasPrettyPermalinks, bool $isActive ): bool {
		return $isActive && $isApache && ! $hasPrettyPermalinks;
	}
}
