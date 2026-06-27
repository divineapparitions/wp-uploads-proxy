<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Proxy;

/**
 * Serves proxied bytes back to the browser in the same request.
 *
 * Abstracted behind an interface so the terminal "emit headers, stream the body,
 * then `exit`" step has a seam: the production {@see HttpResponder} ends the
 * request, while tests substitute a capturing double that records what would have
 * been served without halting the test process.
 */
interface Responder {

	/**
	 * Emit a Download-mode response: the bytes with their Content-Type, the
	 * Content-Length, and the `X-Uploads-Proxy: download` marker. Production
	 * implementations terminate the request after sending.
	 */
	public function serveDownload( string $bytes, string $contentType ): void;

	/**
	 * Emit a Hotlink-mode response: a `302` temporary redirect to the Origin URL
	 * with the `X-Uploads-Proxy: hotlink` marker.
	 *
	 * The status MUST be `302` (temporary) and NEVER `301` (permanent) so that
	 * toggling modes or fixing the Origin is never poisoned by browser caches that
	 * lock in a permanent redirect.
	 *
	 * Production implementations terminate the request after sending.
	 */
	public function serveHotlink( string $location ): void;

	/**
	 * Emit a 404 Not Found response.
	 *
	 * When `$xUploadsProxy` is non-empty it is emitted as the `X-Uploads-Proxy`
	 * header value (use `'negative'` for a Negative-cache miss so devtools and
	 * Playwright tests can distinguish it from an unhandled 404). Pass an empty
	 * string for a 5xx/timeout fallback where no header is appropriate.
	 *
	 * Production implementations terminate the request after sending.
	 */
	public function serve404( string $xUploadsProxy ): void;
}
