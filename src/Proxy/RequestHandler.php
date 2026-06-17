<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Proxy;

use DivineApparitions\UploadsProxy\Config\ConfigResolver;
use DivineApparitions\UploadsProxy\Config\Mode;
use DivineApparitions\UploadsProxy\Registrable;
use DivineApparitions\UploadsProxy\State\Counters;

/**
 * Resolves a Miss by intercepting the request for the missing file (ADR-0001).
 *
 * On `template_redirect` — reachable because the web server routes a missing
 * uploads file to `index.php` (nginx `try_files`) — this detects whether the
 * current request is for an Uploads path that is absent locally (a Miss). In
 * Download mode it fetches the exact file from the Origin through WordPress's
 * HTTP layer, contains and gates the target path, atomically writes the bytes
 * into the uploads directory, and serves them in the same request marked with
 * `X-Uploads-Proxy: download`. Once the file is on disk the web server serves
 * subsequent requests directly, so there is no second Origin fetch.
 *
 * This replaces the superseded URL-rewriting `MediaProxy` (ADR-0001).
 */
final class RequestHandler implements Registrable {

	/**
	 * @param ConfigResolver           $resolver        Effective configuration source.
	 * @param OriginFetcher            $originClient    Fetches files from the Origin.
	 * @param FileWriter               $writer          Atomic uploads writer.
	 * @param Counters                 $counters        Downloaded-total state.
	 * @param Responder                $responder       Serves the bytes back to the browser.
	 * @param (callable(): UploadsScope) $scopeFactory  Builds the live uploads scope (deferred to request time).
	 * @param (callable(): string)     $environmentType Resolves the current environment type.
	 */
	public function __construct(
		private readonly ConfigResolver $resolver,
		private readonly OriginFetcher $originClient,
		private readonly FileWriter $writer,
		private readonly Counters $counters,
		private readonly Responder $responder,
		private $scopeFactory,
		private $environmentType,
	) {
	}

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'onTemplateRedirect' ] );
	}

	/**
	 * Entry point hooked on `template_redirect`.
	 */
	public function onTemplateRedirect(): void {
		$requestUri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) )
			: '';

		if ( '' === $requestUri ) {
			return;
		}

		$this->handle( $requestUri );
	}

	/**
	 * Resolve a Miss for $requestUri. Returns true when the request was proxied
	 * (file downloaded, written, and served), false when the handler stayed inert
	 * or the request was not a serviceable Miss.
	 *
	 * Separated from {@see RequestHandler::onTemplateRedirect()} so it can be
	 * driven directly with an explicit URI in tests.
	 */
	public function handle( string $requestUri ): bool {
		// Never act on the Origin itself.
		if ( 'production' === ( $this->environmentType )() ) {
			return false;
		}

		$config = $this->resolver->resolve();

		// Off until an Origin is configured; this slice handles Download mode only.
		if ( ! $config->isEnabled() || Mode::Download !== $config->mode() ) {
			return false;
		}

		$scope        = ( $this->scopeFactory )();
		$relativePath = $scope->relativePathFor( $requestUri );

		// Not an in-scope, safe uploads path.
		if ( null === $relativePath ) {
			return false;
		}

		$target = $scope->absolutePathFor( $relativePath );

		// Present locally: not a Miss. The web server should have served it; do nothing.
		if ( is_file( $target ) ) {
			return false;
		}

		// Hard-deny executable extensions before any network call.
		if ( ! $scope->isAllowedFile( $relativePath ) ) {
			return false;
		}

		$response = $this->originClient->fetch(
			new OriginRequest( $config->origin(), $requestUri, $config->basicAuth() )
		);

		if ( ! $response->isOk() ) {
			return false;
		}

		// Atomically save into the uploads directory so the web server serves
		// every subsequent request directly (no re-proxy).
		if ( ! $this->writer->write( $target, $response->body() ) ) {
			return false;
		}

		$this->counters->recordDownload();

		$this->responder->serveDownload( $response->body(), $response->contentType() );

		return true;
	}
}
