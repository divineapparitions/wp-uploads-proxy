# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/philltran/wp-uploads-proxy/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/philltran/wp-uploads-proxy/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/philltran/wp-uploads-proxy/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/philltran/wp-uploads-proxy/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/philltran/wp-uploads-proxy/releases/tag/v0.1.0
