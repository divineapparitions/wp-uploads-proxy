<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Config;

/**
 * Resolves the plugin's effective configuration.
 */
interface ConfigResolver {

	/**
	 * Produce the effective configuration snapshot.
	 */
	public function resolve(): EffectiveConfig;
}
