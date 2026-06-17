<?php

declare(strict_types=1);

namespace DivineApparitions\UploadsProxy\Config;

use DivineApparitions\UploadsProxy\Settings\Settings;

/**
 * Resolves configuration in the order `define()` constant → environment
 * variable → DB option → off.
 *
 * Constants and environment variables are the intended home: they live outside
 * the database, so they survive pulling a production database into a local or
 * staging environment. The DB option is a fallback source only. (ADR-0002.)
 */
final class ConstantsFirstResolver implements ConfigResolver {

	/**
	 * Constant/env variable name for the Origin URL.
	 */
	public const ORIGIN = 'UPLOADS_PROXY_ORIGIN';

	/**
	 * Constant/env variable name for the resolution mode.
	 */
	public const MODE = 'UPLOADS_PROXY_MODE';

	/**
	 * Constant/env variable name for the Basic Auth username.
	 */
	public const AUTH_USER = 'UPLOADS_PROXY_AUTH_USER';

	/**
	 * Constant/env variable name for the Basic Auth password.
	 */
	public const AUTH_PASS = 'UPLOADS_PROXY_AUTH_PASS';

	public function __construct(
		private readonly Settings $settings,
		private readonly Environment $environment,
	) {
	}

	public function resolve(): EffectiveConfig {
		[ $originRaw, $originSource ] = $this->resolveValue( self::ORIGIN, $this->settings->dbOriginUrl() );
		$origin                       = null === $originRaw ? '' : untrailingslashit( $originRaw );

		[ $modeRaw, $modeSource ] = $this->resolveValue( self::MODE, $this->settings->dbMode() );
		$mode                     = Mode::fromString( $modeRaw );
		// A mode value that didn't parse contributes nothing — report it as default.
		if ( null === $modeRaw || null === Mode::tryFrom( $modeRaw ) ) {
			$modeSource = Source::DefaultOff;
		}

		[ $basicAuth, $basicAuthSource ] = $this->resolveBasicAuth();

		return new EffectiveConfig(
			$origin,
			'' === $origin ? Source::DefaultOff : $originSource,
			$mode,
			$modeSource,
			$basicAuth,
			$basicAuthSource,
			$this->settings->dbEnabled(),
		);
	}

	/**
	 * Resolve a single value down the ladder: constant → env → DB → off.
	 *
	 * @param string      $name  Constant/env variable name.
	 * @param string|null $dbRaw The DB fallback value, or null/'' when absent.
	 *
	 * @return array{0: string|null, 1: Source} The value (or null) and its source.
	 */
	private function resolveValue( string $name, ?string $dbRaw ): array {
		$constant = $this->environment->constant( $name );
		if ( null !== $constant && '' !== $constant ) {
			return [ $constant, Source::Constant ];
		}

		$env = $this->environment->env( $name );
		if ( null !== $env && '' !== $env ) {
			return [ $env, Source::Env ];
		}

		if ( null !== $dbRaw && '' !== $dbRaw ) {
			return [ $dbRaw, Source::Db ];
		}

		return [ null, Source::DefaultOff ];
	}

	/**
	 * Resolve the Basic Auth pair, treating the username + password as a single
	 * unit so a partial pair never becomes active and the source is coherent.
	 *
	 * @return array{0: BasicAuth|null, 1: Source}
	 */
	private function resolveBasicAuth(): array {
		$constUser = $this->environment->constant( self::AUTH_USER );
		$constPass = $this->environment->constant( self::AUTH_PASS );
		$auth      = BasicAuth::fromPair( $constUser, $constPass );
		if ( null !== $auth ) {
			return [ $auth, Source::Constant ];
		}

		$envUser = $this->environment->env( self::AUTH_USER );
		$envPass = $this->environment->env( self::AUTH_PASS );
		$auth    = BasicAuth::fromPair( $envUser, $envPass );
		if ( null !== $auth ) {
			return [ $auth, Source::Env ];
		}

		$auth = BasicAuth::fromPair( $this->settings->dbBasicAuthUser(), $this->settings->dbBasicAuthPass() );
		if ( null !== $auth ) {
			return [ $auth, Source::Db ];
		}

		return [ null, Source::DefaultOff ];
	}
}
