<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Proxy;

/**
 * The production {@see Responder}: emits headers, streams the body, and exits.
 *
 * Marks every proxied response with `X-Uploads-Proxy: download` so the behaviour
 * is observable, sets a correct Content-Type / Content-Length, then ends the
 * request so WordPress does not continue rendering a template over the bytes.
 *
 * The status is always set through WordPress's `status_header()`, never the raw
 * `http_response_code()`. The handler runs on `template_redirect`, where WordPress
 * has already resolved the missing-file request to a 404; a host page-cache layer
 * that tracks the response status through the `status_header` pipeline (notably
 * Pantheon Advanced Page Cache) re-asserts that remembered 404 late in the request,
 * so a status set with the raw PHP call is invisible to it and is overwritten.
 * Routing through `status_header()` keeps the status visible to those integrations.
 */
final class HttpResponder implements Responder {

	public function serveDownload( string $bytes, string $contentType ): void {
		if ( ! headers_sent() ) {
			// Reset the template_redirect 404 to 200 before sending the bytes, or the
			// successfully proxied file would be served under a 404 — which strict
			// browsers, CDNs and caches treat as a failure on the first Miss. Via
			// status_header() so host cache layers (e.g. Pantheon) see the 200.
			status_header( 200 );

			header( 'X-Uploads-Proxy: download' );

			if ( '' !== $contentType ) {
				header( 'Content-Type: ' . $contentType );
			}

			// The Content-Type is the Origin's verbatim. Tell the browser not to
			// MIME-sniff a different type out of the bytes — defence in depth on top
			// of the executable-extension / allowed-MIME write gate in UploadsScope.
			header( 'X-Content-Type-Options: nosniff' );

			header( 'Content-Length: ' . strlen( $bytes ) );
		}

		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw binary file bytes from the Origin; escaping would corrupt them.

		exit;
	}

	public function serveHotlink( string $location ): void {
		if ( ! headers_sent() ) {
			// status_header(), not http_response_code(): the redirect has no body, so
			// nothing flushes the headers early, and a host cache layer that re-asserts
			// the template_redirect 404 late (e.g. Pantheon Advanced Page Cache) would
			// otherwise overwrite a raw 302 — leaving a 404 with a Location header.
			status_header( 302 );
			header( 'Location: ' . $location );
			header( 'X-Uploads-Proxy: hotlink' );
		}

		exit;
	}

	public function serve404( string $xUploadsProxy ): void {
		if ( ! headers_sent() ) {
			// Via status_header() for the same reason as the other paths: keep the
			// status visible to host cache layers that track it (e.g. Pantheon).
			status_header( 404 );

			if ( '' !== $xUploadsProxy ) {
				header( 'X-Uploads-Proxy: ' . $xUploadsProxy );
			}
		}

		exit;
	}
}
