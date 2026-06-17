<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Config;

/**
 * A Basic Auth credential pair attached to the outbound Origin request.
 *
 * Only meaningful when both a username and a password are present, so it can
 * only be constructed from a complete pair via {@see BasicAuth::fromPair()}.
 */
final class BasicAuth {

	private function __construct(
		private readonly string $username,
		private readonly string $password,
	) {
	}

	/**
	 * Build credentials from a username/password pair, or null if either half
	 * is missing (a partial pair is not valid Basic Auth).
	 */
	public static function fromPair( ?string $username, ?string $password ): ?self {
		if ( null === $username || '' === $username || null === $password || '' === $password ) {
			return null;
		}

		return new self( $username, $password );
	}

	public function username(): string {
		return $this->username;
	}

	public function password(): string {
		return $this->password;
	}
}
