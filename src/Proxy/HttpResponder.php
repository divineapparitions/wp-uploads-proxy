<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Proxy;

/**
 * The production {@see Responder}: emits headers, streams the body, and exits.
 *
 * Marks every proxied response with `X-Uploads-Proxy: download` so the behaviour
 * is observable, sets a correct Content-Type / Content-Length, then ends the
 * request so WordPress does not continue rendering a template over the bytes.
 */
final class HttpResponder implements Responder {

	public function serveDownload( string $bytes, string $contentType ): void {
		if ( ! headers_sent() ) {
			header( 'X-Uploads-Proxy: download' );

			if ( '' !== $contentType ) {
				header( 'Content-Type: ' . $contentType );
			}

			header( 'Content-Length: ' . strlen( $bytes ) );
		}

		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw binary file bytes from the Origin; escaping would corrupt them.

		exit;
	}

	public function serve404( string $xUploadsProxy ): void {
		if ( ! headers_sent() ) {
			http_response_code( 404 );

			if ( '' !== $xUploadsProxy ) {
				header( 'X-Uploads-Proxy: ' . $xUploadsProxy );
			}
		}

		exit;
	}
}
