<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Tests\Support;

use DivineApparitions\UploadsProxy\Config\Environment;

/**
 * In-memory {@see Environment} for unit tests.
 *
 * Keys are constant names; prefix a key with `env:` to register it as an
 * environment variable instead. This keeps the precedence ladder testable
 * without touching real `define()` / `getenv()` global state.
 */
final class FakeEnvironment implements Environment {

	/**
	 * @var array<string, string>
	 */
	private array $constants = [];

	/**
	 * @var array<string, string>
	 */
	private array $envVars = [];

	/**
	 * @param array<string, string> $values Constant names, or `env:NAME` for env vars.
	 */
	public function __construct( array $values = [] ) {
		foreach ( $values as $key => $value ) {
			if ( str_starts_with( $key, 'env:' ) ) {
				$this->envVars[ substr( $key, 4 ) ] = $value;
			} else {
				$this->constants[ $key ] = $value;
			}
		}
	}

	public function constant( string $name ): ?string {
		return $this->constants[ $name ] ?? null;
	}

	public function env( string $name ): ?string {
		return $this->envVars[ $name ] ?? null;
	}
}
