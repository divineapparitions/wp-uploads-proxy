# Configuration resolves constants → env → database, not the database alone

**Status:** accepted

The proxy's configuration (origin URL, mode, Basic Auth) resolves in the order
`define()` constant → environment variable → database option → off. The intended
home is a local-only config file (`wp-config-local.php` on Pantheon/Lando,
`web_environment` env vars on DDEV). The database settings page is a
diagnostics/fallback UI only.

## Why this is not the database-first WordPress norm

This plugin exists to support pulling a **production database** into a local or
staging environment. Plugin settings stored in `wp_options` are *part of that
database*, so a DB-first design would overwrite the local configuration with
production's (where the plugin is inactive) on every pull — defeating itself at
the exact moment it is needed. Constants and environment variables live outside
the database, survive the pull, and configure the plugin hands-free in CI.

## Consequences

- A WordPress developer expecting Settings-API/`wp_options` storage will find the
  page read-only when a constant/env var is set; it shows the effective value and
  its source rather than silently ignoring saved input.
