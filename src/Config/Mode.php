<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Config;

/**
 * How a Miss is resolved against the Origin.
 *
 * - {@see Mode::Download} (default): stream the file from the Origin, save it
 *   into the local uploads directory, then serve it.
 * - {@see Mode::Hotlink} (opt-in): redirect the browser straight to the file on
 *   the Origin; nothing is written locally.
 */
enum Mode: string {

	case Download = 'download';
	case Hotlink  = 'hotlink';

	/**
	 * Parse a raw mode string, falling back to {@see Mode::Download} when the
	 * value is missing or unrecognised.
	 */
	public static function fromString( ?string $value ): self {
		return self::tryFrom( (string) $value ) ?? self::Download;
	}

	/**
	 * Human-readable label for the diagnostics page.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Download => __( 'Download', 'divine-apparitions-uploads-proxy' ),
			self::Hotlink  => __( 'Hotlink', 'divine-apparitions-uploads-proxy' ),
		};
	}
}
