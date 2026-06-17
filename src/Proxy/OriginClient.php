<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Proxy;

/**
 * Fetches a single file from the Origin through WordPress's HTTP layer.
 *
 * Routing the request through `wp_remote_get()` means it honours the site's HTTP
 * configuration and — crucially for testing — can be intercepted with the
 * `pre_http_request` filter, so the integration suite never makes a real network
 * call. Only the host is swapped from the requested URL; the {@see OriginRequest}
 * owns URL building and Basic Auth.
 */
final class OriginClient implements OriginFetcher {

	/**
	 * Outbound request timeout, in seconds.
	 */
	private const TIMEOUT = 15;

	/**
	 * Fetch the file described by $request, normalising the result.
	 */
	public function fetch( OriginRequest $request ): OriginResponse {
		$response = wp_remote_get(
			$request->url(),
			[
				'timeout'     => self::TIMEOUT,
				'redirection' => 5,
				'headers'     => $request->headers(),
			]
		);

		if ( is_wp_error( $response ) ) {
			return new OriginResponse( 0, '', '' );
		}

		return new OriginResponse(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response ),
			(string) wp_remote_retrieve_header( $response, 'content-type' ),
		);
	}
}
