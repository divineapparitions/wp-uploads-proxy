<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Config;

/**
 * Where an effective configuration value was resolved from.
 *
 * Reported per value so the diagnostics page can label each one with its
 * origin in the precedence ladder (constant → env → DB → off).
 */
enum Source: string {

	case Constant   = 'constant';
	case Env        = 'env';
	case Db         = 'db';
	case DefaultOff = 'default';

	/**
	 * Human-readable label for the diagnostics page.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Constant   => __( 'Constant', 'divine-apparitions-uploads-proxy' ),
			self::Env        => __( 'Environment variable', 'divine-apparitions-uploads-proxy' ),
			self::Db         => __( 'Database option', 'divine-apparitions-uploads-proxy' ),
			self::DefaultOff => __( 'Default', 'divine-apparitions-uploads-proxy' ),
		};
	}
}
