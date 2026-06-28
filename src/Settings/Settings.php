<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Settings;

use DivineApparitions\UploadsProxy\Config\Mode;

/**
 * Typed accessor and sanitiser for the plugin's stored DB option.
 *
 * The DB option is the *fallback* configuration source only — constants and
 * environment variables take precedence (see ADR-0002). All reads and writes
 * of the raw option flow through this class so nothing else touches the array
 * or its keys; the {@see \DivineApparitions\UploadsProxy\Config\ConfigResolver}
 * reads these `db*` accessors as the bottom rung of the precedence ladder.
 *
 * @phpstan-type SettingsArray array{
 *     enabled: bool,
 *     origin_url: string,
 *     mode: string,
 *     basic_auth_user: string,
 *     basic_auth_pass: string
 * }
 */
final class Settings {

	public const OPTION_NAME = 'uploads_proxy_settings';

	/**
	 * Default option values.
	 *
	 * @return SettingsArray
	 */
	public function defaults(): array {
		return [
			'enabled'         => false,
			'origin_url'      => '',
			'mode'            => Mode::Download->value,
			'basic_auth_user' => '',
			'basic_auth_pass' => '',
		];
	}

	/**
	 * The full, defaults-merged option array.
	 *
	 * @return SettingsArray
	 */
	public function all(): array {
		$stored = get_option( self::OPTION_NAME, [] );

		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		/** @var SettingsArray $merged */
		$merged = wp_parse_args( $stored, $this->defaults() );

		return $merged;
	}

	/**
	 * The DB option's own enabled flag (consulted only for a DB-sourced Origin).
	 */
	public function dbEnabled(): bool {
		return (bool) $this->all()['enabled'];
	}

	/**
	 * The Origin URL stored in the DB, or '' when unset.
	 */
	public function dbOriginUrl(): string {
		return (string) $this->all()['origin_url'];
	}

	/**
	 * The resolution mode explicitly stored in the DB, or '' when unset.
	 *
	 * Unlike the other accessors this deliberately does NOT fall back to the
	 * `defaults()` mode: the schema default is not a developer-chosen value, so
	 * the resolver must be able to tell "mode unconfigured" (→ {@see Source::DefaultOff},
	 * defaulting to {@see Mode::Download}) apart from a mode genuinely stored in
	 * the DB option (→ {@see Source::Db}).
	 */
	public function dbMode(): string {
		$stored = get_option( self::OPTION_NAME, [] );

		if ( ! is_array( $stored ) || ! isset( $stored['mode'] ) ) {
			return '';
		}

		return (string) $stored['mode'];
	}

	/**
	 * The Basic Auth username stored in the DB, or '' when unset.
	 */
	public function dbBasicAuthUser(): string {
		return (string) $this->all()['basic_auth_user'];
	}

	/**
	 * The Basic Auth password stored in the DB, or '' when unset.
	 */
	public function dbBasicAuthPass(): string {
		return (string) $this->all()['basic_auth_pass'];
	}

	/**
	 * Sanitise raw input from the settings form.
	 *
	 * The password is a write-only field: the page never renders the stored
	 * password back into the form, so an empty submission means "keep what is
	 * stored" rather than "blank it". Only a non-empty value overwrites the
	 * stored password (issue #7).
	 *
	 * @param mixed $input Raw submitted value.
	 *
	 * @return SettingsArray
	 */
	public function sanitize( mixed $input ): array {
		$input = is_array( $input ) ? $input : [];

		$mode = isset( $input['mode'] ) ? (string) $input['mode'] : '';

		$submittedPass = isset( $input['basic_auth_pass'] ) ? (string) $input['basic_auth_pass'] : '';

		return [
			'enabled'         => ! empty( $input['enabled'] ),
			'origin_url'      => isset( $input['origin_url'] )
				? esc_url_raw( trim( (string) $input['origin_url'] ) )
				: '',
			'mode'            => Mode::fromString( $mode )->value,
			'basic_auth_user' => isset( $input['basic_auth_user'] )
				? sanitize_text_field( (string) $input['basic_auth_user'] )
				: '',
			// Write-only: preserve the stored password when none is submitted.
			'basic_auth_pass' => '' !== $submittedPass ? $submittedPass : $this->dbBasicAuthPass(),
		];
	}
}
