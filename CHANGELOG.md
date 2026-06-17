# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/philltran/wp-uploads-proxy/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/philltran/wp-uploads-proxy/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/philltran/wp-uploads-proxy/releases/tag/v0.1.0
