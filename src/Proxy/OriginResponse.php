<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Proxy;

/**
 * The outcome of fetching one file from the Origin.
 *
 * A small immutable view over the parts of a WordPress HTTP response the proxy
 * cares about: the status code, the raw body, and the Content-Type. A transport
 * error (a `WP_Error`) is normalised to a status code of 0.
 */
final class OriginResponse {

	public function __construct(
		private readonly int $statusCode,
		private readonly string $body,
		private readonly string $contentType,
	) {
	}

	public function statusCode(): int {
		return $this->statusCode;
	}

	public function body(): string {
		return $this->body;
	}

	public function contentType(): string {
		return $this->contentType;
	}

	/**
	 * Whether the Origin returned a `200 OK` (the only status this slice serves).
	 */
	public function isOk(): bool {
		return 200 === $this->statusCode;
	}
}
