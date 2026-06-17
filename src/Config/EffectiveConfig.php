<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Config;

/**
 * The plugin's effective configuration, with each value's resolved {@see Source}.
 *
 * An immutable snapshot produced by a {@see ConfigResolver}. Callers ask this
 * object whether the proxy is enabled and how it should behave; they never
 * touch the raw option array or read constants/env themselves.
 */
final class EffectiveConfig {

	/**
	 * @param string         $origin          Origin URL (no trailing slash), or '' when unconfigured.
	 * @param Source         $originSource    Where the Origin came from.
	 * @param Mode           $mode            Resolution mode.
	 * @param Source         $modeSource      Where the mode came from.
	 * @param BasicAuth|null $basicAuth       Optional Origin Basic Auth credentials.
	 * @param Source         $basicAuthSource Where the Basic Auth came from.
	 * @param bool           $dbEnabled       The DB option's own enabled flag (only consulted for a DB Origin).
	 */
	public function __construct(
		private readonly string $origin,
		private readonly Source $originSource,
		private readonly Mode $mode,
		private readonly Source $modeSource,
		private readonly ?BasicAuth $basicAuth,
		private readonly Source $basicAuthSource,
		private readonly bool $dbEnabled,
	) {
	}

	/**
	 * Whether the proxy should act.
	 *
	 * Off until an Origin is configured, regardless of any enabled flag. When
	 * the Origin comes from the DB, the DB option's own enabled flag also
	 * applies; a constant/env Origin is self-enabling.
	 */
	public function isEnabled(): bool {
		if ( '' === $this->origin ) {
			return false;
		}

		if ( Source::Db === $this->originSource ) {
			return $this->dbEnabled;
		}

		return true;
	}

	/**
	 * The Origin URL (no trailing slash), or '' when unconfigured.
	 */
	public function origin(): string {
		return $this->origin;
	}

	public function originSource(): Source {
		return $this->originSource;
	}

	public function mode(): Mode {
		return $this->mode;
	}

	public function modeSource(): Source {
		return $this->modeSource;
	}

	/**
	 * The Origin Basic Auth credentials, or null when none are configured.
	 */
	public function basicAuth(): ?BasicAuth {
		return $this->basicAuth;
	}

	public function hasBasicAuth(): bool {
		return null !== $this->basicAuth;
	}

	public function basicAuthSource(): Source {
		return $this->basicAuthSource;
	}
}
