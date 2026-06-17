<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Proxy;

/**
 * Fetches a single file from the Origin.
 *
 * The {@see RequestHandler} depends on this seam rather than the concrete
 * {@see OriginClient} so the network call can be substituted in tests; the
 * production implementation routes through WordPress's HTTP layer.
 */
interface OriginFetcher {

	/**
	 * Fetch the file described by $request.
	 */
	public function fetch( OriginRequest $request ): OriginResponse;
}
