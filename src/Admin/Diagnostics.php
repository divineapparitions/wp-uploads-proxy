<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Admin;

use DivineApparitions\UploadsProxy\Config\EffectiveConfig;
use DivineApparitions\UploadsProxy\Config\Mode;
use DivineApparitions\UploadsProxy\Config\Source;

/**
 * Decision logic for the diagnostics-first settings page.
 *
 * A WordPress-free view-model: given the resolved {@see EffectiveConfig} and the
 * two request-flow counters, it decides what the page should show — active
 * status, each value with its resolved {@see Source}, and (per ADR-0002) whether
 * each DB field is editable or rendered read-only because a constant or
 * environment variable overrides it. Kept free of WordPress and HTML so the
 * page's decisions can be unit tested directly; {@see SettingsPage} renders the
 * result and escapes the output.
 */
final class Diagnostics {

	/**
	 * @param EffectiveConfig $config             The resolved configuration snapshot.
	 * @param int             $downloadedCount    Files downloaded-and-served from the Origin (issue #3).
	 * @param int             $negativeCacheCount Negative-cache entries created (issue #5).
	 */
	public function __construct(
		private readonly EffectiveConfig $config,
		private readonly int $downloadedCount,
		private readonly int $negativeCacheCount,
	) {
	}

	/**
	 * Whether the proxy is currently active.
	 */
	public function isActive(): bool {
		return $this->config->isEnabled();
	}

	/**
	 * The effective Origin URL, or '' when unconfigured.
	 */
	public function origin(): string {
		return $this->config->origin();
	}

	public function originSource(): Source {
		return $this->config->originSource();
	}

	public function isOriginEditable(): bool {
		return $this->isEditable( $this->config->originSource() );
	}

	public function mode(): Mode {
		return $this->config->mode();
	}

	public function modeSource(): Source {
		return $this->config->modeSource();
	}

	public function isModeEditable(): bool {
		return $this->isEditable( $this->config->modeSource() );
	}

	public function hasBasicAuth(): bool {
		return $this->config->hasBasicAuth();
	}

	/**
	 * The effective Basic Auth username, or '' when none is configured.
	 *
	 * Exposed so the page can show the username (read-only when constant/env
	 * sourced) without ever revealing the password (issue #7).
	 */
	public function basicAuthUsername(): string {
		return $this->config->basicAuth()?->username() ?? '';
	}

	public function basicAuthSource(): Source {
		return $this->config->basicAuthSource();
	}

	public function isBasicAuthEditable(): bool {
		return $this->isEditable( $this->config->basicAuthSource() );
	}

	/**
	 * Files downloaded-and-served from the Origin (issue #3).
	 */
	public function downloadedCount(): int {
		return $this->downloadedCount;
	}

	/**
	 * Negative-cache entries created (issue #5).
	 */
	public function negativeCacheCount(): int {
		return $this->negativeCacheCount;
	}

	/**
	 * A DB field is editable only when no constant or environment variable
	 * supplies its value (ADR-0002). A constant- or env-sourced value is shown
	 * read-only and labelled with its source, so an edit that would not stick is
	 * never offered.
	 */
	private function isEditable( Source $source ): bool {
		return Source::Constant !== $source && Source::Env !== $source;
	}
}
