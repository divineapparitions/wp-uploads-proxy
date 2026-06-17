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
}
