<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Admin;

/**
 * The outcome of a "Test Origin connection" probe (issue #7).
 *
 * Reachability is graded strictly: only a 2xx status counts as reachable, so a
 * 4xx, a 5xx, and a transport error (the normalised status 0) are all treated as
 * unreachable. The raw status code is preserved so the page can distinguish
 * "Unreachable (HTTP 403)" from "Unreachable (no response)".
 */
final class ProbeResult {

	public function __construct(
		private readonly int $statusCode,
	) {
	}

	/**
	 * The HTTP status code from the Origin, or 0 for a transport error/timeout.
	 */
	public function statusCode(): int {
		return $this->statusCode;
	}

	/**
	 * Whether the Origin is reachable — true only for a 2xx status.
	 */
	public function isReachable(): bool {
		return $this->statusCode >= 200 && $this->statusCode < 300;
	}

	/**
	 * Whether the Origin sent any HTTP response at all.
	 *
	 * False only for the normalised transport-error status 0 (a timeout or a
	 * connection failure), which the page reports as "no response" rather than a
	 * specific status code.
	 */
	public function hasResponse(): bool {
		return 0 !== $this->statusCode;
	}
}
