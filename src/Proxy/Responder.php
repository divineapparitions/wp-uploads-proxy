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
 *
 * Every status MUST be emitted through WordPress's `status_header()`, never the
 * raw `http_response_code()`. The handler runs on `template_redirect`, where
 * WordPress has already set a `404`; a host page-cache layer that tracks the
 * status through the `status_header` pipeline (notably Pantheon Advanced Page
 * Cache) re-asserts that 404 late in the request and overwrites a status set with
 * the raw PHP call, so the chosen status would silently revert to 404.
 */
interface Responder {

	/**
	 * Emit a Download-mode response: the bytes with their Content-Type, the
	 * Content-Length, and the `X-Uploads-Proxy: download` marker.
	 *
	 * The status MUST be `200` (via `status_header()`). The handler runs on
	 * `template_redirect`, where WordPress has already set a `404` for the
	 * missing-file request; a production implementation must reset the status to
	 * `200` before sending, or the proxied file is served under a 404 and strict
	 * clients/caches reject the first Miss.
	 *
	 * Production implementations terminate the request after sending.
	 */
	public function serveDownload( string $bytes, string $contentType ): void;

	/**
	 * Emit a Hotlink-mode response: a `302` temporary redirect to the Origin URL
	 * with the `X-Uploads-Proxy: hotlink` marker.
	 *
	 * The status MUST be `302` (temporary, via `status_header()`) and NEVER `301`
	 * (permanent) so that toggling modes or fixing the Origin is never poisoned by
	 * browser caches that lock in a permanent redirect.
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
