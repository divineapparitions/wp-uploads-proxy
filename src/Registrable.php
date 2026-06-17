<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy;

/**
 * A component that hooks itself into WordPress.
 */
interface Registrable {

	/**
	 * Add the component's actions and filters.
	 */
	public function register(): void;
}
