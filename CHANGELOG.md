# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.6.0] - 2026-06-27

### Added

- Hotlink mode dispatch (issue #6): when `mode = hotlink`, a Miss issues a `302`
  temporary redirect (never `301`) to the file on the configured Origin with an
  `X-Uploads-Proxy: hotlink` header. Nothing is written locally, so every Miss
  issues a fresh redirect — toggling modes or fixing the Origin is never poisoned
  by permanent browser caching. Download mode remains the default.
- `Responder::serveHotlink( string $location ): void` interface method;
  `HttpResponder` implementation sets `http_response_code(302)`, emits the
  `Location` and `X-Uploads-Proxy: hotlink` headers, then exits.
- Unit tests (Brain Monkey) covering all Hotlink dispatch paths: redirect issued
  to Origin URL, no file written locally, no outbound Origin fetch, and
  confirmation that Download mode remains the default.
- Integration test suite `HotlinkModeTest` (wp-env `tests-cli`) asserting the
  redirect Location, that nothing is written locally, and that no outbound HTTP
  request is made when Hotlink mode is active.

## [0.5.0] - 2026-06-27

### Added

- Miss-fallback + Negative-cache deep module (issue #5): Origin `404`/`410` →
  serve a local `404` marked with `X-Uploads-Proxy: negative`, record a
  short-lived Negative-cache transient (~10 min, keyed by the relative uploads
  path via `NegativeCache`) so repeat Misses short-circuit without re-hitting
  the Origin. Origin `5xx`/timeout → serve `404` with no cache entry so the next
  request retries.
- `NegativeCache` (`src/State/NegativeCache.php`): manages `get_transient` /
  `set_transient` with a `uploads_proxy_neg_` + md5 key scheme and a `TTL` of
  600 seconds.
- `Counters::negativeCount()` / `Counters::recordNegative()`: running total of
  Negative-cache entries ever created, stored in the non-autoloaded option
  `uploads_proxy_negative_count`.
- `OriginResponse::isGone()` (404 or 410) and `isServerError()` (5xx or status 0)
  helpers for branching in `RequestHandler`.
- `Responder::serve404( string $xUploadsProxy ): void` interface method;
  `HttpResponder` implementation sets `http_response_code(404)` and the optional
  `X-Uploads-Proxy` header, then exits.
- Unit tests (Brain Monkey) covering all miss-fallback paths: 404 caches + header
  + counter, 5xx does not cache, short-circuit on repeat miss.
- Integration test suite `MissFallbackTest` (wp-env `tests-cli`) asserting the
  full 404/410/5xx/repeat-miss behaviours against real WordPress transients and
  options, with the Origin mocked via `pre_http_request`.

## [0.4.0] - 2026-06-27

### Added

- Download write-policy hardening (issue #4): `UploadsScope::isAllowedFile()` now
  enforces a two-layer gate — (1) a hard deny on executable extensions (`.php`,
  `.phtml`, `.pht`, `.phar`, `.cgi`, `.pl`, etc.) and (2) a WordPress allowed-MIME
  gate via `wp_check_filetype()` so only file types the site explicitly permits are
  ever written to the uploads directory. A stray unknown extension (e.g. `.bat`,
  `.bin`) that slips through on the Origin returns a local `404` without writing
  anything to disk.
- Unit tests (Brain Monkey) covering the MIME/exec gate: `wp_check_filetype()`
  returning `type=false` prevents both the write and the Origin fetch; executable
  extensions short-circuit before any WordPress call.
- Test documenting path-containment: `absolutePathFor()` with an absolute-path
  input stays within the uploads basedir via `ltrim`, reinforcing the traversal
  protection already in `relativePathFor()`.
- Test documenting host-fixed Origin fetch: `OriginRequest::url()` always uses the
  configured Origin host regardless of path content, so no arbitrary-host SSRF is
  possible.

## [0.3.0] - 2026-06-17

### Added

- Request-interception walking skeleton (ADR-0001): on `template_redirect`, a
  Miss for a missing Uploads path is resolved against the configured Origin. In
  Download mode (default) the file is fetched through WordPress's HTTP layer
  (host swapped, relative uploads path preserved, optional Basic Auth attached),
  contained to the uploads basedir with executable extensions refused, streamed
  to a temp file and atomically `rename()`d into place, then served in the same
  request with `Content-Type`/`Content-Length` and an `X-Uploads-Proxy: download`
  header. A downloaded counter tracks the total.
- Deep modules under `src/Proxy/` (`RequestHandler`, `OriginClient`,
  `OriginRequest`/`OriginResponse`, `UploadsScope`, `FileWriter`, `HttpResponder`)
  and `src/State/Counters`. A Derivative and a non-image (PDF) resolve through the
  same handler.
- `@wordpress/env` `tests-cli` integration harness booting real WordPress with the
  Origin mocked via `pre_http_request`, asserting the download/atomic-save/serve
  behaviour, the header, and the file landing on disk.

### Changed

- Pinned PHPUnit to `^9.6` (with `yoast/phpunit-polyfills`) so the WordPress core
  test suite can run under wp-env — the WP test suite is not PHPUnit 10 compatible.

### Removed

- The superseded URL-rewriting `MediaProxy`, replaced by the request-interception
  handler (ADR-0001).

## [0.2.0] - 2026-06-16

### Added

- Configuration resolver (deep module) that resolves the Origin, mode, and
  optional Basic Auth in strict precedence order `define()` constant →
  environment variable → DB option → off, and reports the source of each
  effective value for the diagnostics page (ADR-0002).
- `Mode` (`download` default / `hotlink`), `Source`, and `BasicAuth` value
  objects exposed through a typed `EffectiveConfig`, so callers never touch the
  raw option array.

### Changed

- Reshaped the stored DB option from `production_url`/`verify_local` to an
  enabled flag, Origin URL, mode, and Basic Auth credentials — the fallback
  configuration source only. The plugin stays off until an Origin is configured,
  regardless of the enabled flag.
- The scaffold settings page and `MediaProxy` now read effective configuration
  through the resolver instead of the raw option.

## [0.1.0] - 2026-06-16

### Added

- Initial plugin scaffold: header, version/PHP guards, and Composer autoloader bootstrap.
- `Settings → Uploads Proxy` admin page (Settings API) for the production origin.
- `MediaProxy` that rewrites attachment, image, and `srcset` URLs to a production
  origin when a file is missing locally, with an optional filesystem check.
- Safety guard that disables proxying on production environments.
- Multisite-aware uninstall cleanup.
- Development tooling: PHP_CodeSniffer (WPCS), PHPStan, PHPUnit (Brain Monkey),
  and a GitHub Actions CI workflow across PHP 8.2–8.4.

[Unreleased]: https://github.com/philltran/wp-uploads-proxy/compare/v0.6.0...HEAD
[0.6.0]: https://github.com/philltran/wp-uploads-proxy/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/philltran/wp-uploads-proxy/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/philltran/wp-uploads-proxy/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/philltran/wp-uploads-proxy/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/philltran/wp-uploads-proxy/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/philltran/wp-uploads-proxy/releases/tag/v0.1.0
