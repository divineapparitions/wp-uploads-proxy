<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Config;

/**
 * Default {@see Environment} backed by real PHP constants and `getenv()`.
 *
 * The plugin is deliberately agnostic about which file defines a constant or
 * exports an environment variable (e.g. `wp-config-local.php`, DDEV's
 * `web_environment`); it only cares that the value is present at runtime.
 */
final class SystemEnvironment implements Environment {

	public function constant( string $name ): ?string {
		if ( ! defined( $name ) ) {
			return null;
		}

		$value = constant( $name );

		return is_scalar( $value ) ? (string) $value : null;
	}

	public function env( string $name ): ?string {
		$value = getenv( $name );

		if ( false === $value || '' === $value ) {
			return null;
		}

		return $value;
	}
}
