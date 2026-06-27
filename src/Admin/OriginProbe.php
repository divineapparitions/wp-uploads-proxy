<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Admin;

use DivineApparitions\UploadsProxy\Config\EffectiveConfig;
use DivineApparitions\UploadsProxy\Proxy\OriginFetcher;
use DivineApparitions\UploadsProxy\Proxy\OriginRequest;

/**
 * Performs the diagnostics page's "Test Origin connection" reachability check.
 *
 * Probes the Origin root (`GET {origin}/`) with any configured Basic Auth
 * attached, reusing the existing {@see OriginRequest} + {@see OriginFetcher} seam
 * so it never hand-rolls an HTTP call (issue #7, Fork A). Grading the response is
 * delegated to {@see ProbeResult}: only a 2xx is reachable. Callers must ensure an
 * Origin is configured before probing.
 */
final class OriginProbe {

	public function __construct(
		private readonly OriginFetcher $fetcher,
	) {
	}

	/**
	 * Probe the Origin root and grade the response.
	 */
	public function probe( EffectiveConfig $config ): ProbeResult {
		$response = $this->fetcher->fetch(
			new OriginRequest( $config->origin(), '/', $config->basicAuth() )
		);

		return new ProbeResult( $response->statusCode() );
	}
}
