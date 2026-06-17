<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Config;

/**
 * Reads configuration values that live outside the database.
 *
 * Abstracts the two out-of-database sources — PHP `define()` constants and
 * environment variables — so the resolver's precedence logic can be tested
 * without manipulating global constant/env state.
 */
interface Environment {

	/**
	 * The value of a defined constant, or null if it is not defined.
	 */
	public function constant( string $name ): ?string;

	/**
	 * The value of an environment variable, or null if it is unset/empty.
	 */
	public function env( string $name ): ?string;
}
