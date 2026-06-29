# Divine Apparitions Uploads Proxy

A WordPress plugin that serves locally-missing media by proxying to a remote
origin, so a development or staging site can run against a production database
without copying the entire uploads directory. Its purpose is to keep images and
file links unbroken during local Playwright end-to-end testing.

## Language

**Origin**:
The remote site the plugin proxies missing files from (e.g. production).
_Avoid_: Source, upstream, remote (use "Origin" consistently).

**Miss**:
A request for an uploads path that does not exist on the local filesystem.
_Avoid_: 404, broken image (those are symptoms; a Miss is the trigger).

**Proxy** (verb):
To resolve a Miss by either downloading the file from the Origin or redirecting
the browser to it.

**Download mode**:
On a Miss, stream the file from the Origin, save it into the local uploads
directory, then serve it. Subsequent requests are served by the web server.
_Avoid_: Cache mode, fetch mode.

**Hotlink mode**:
On a Miss, redirect the browser straight to the file on the Origin. Nothing is
written locally.
_Avoid_: Redirect mode (acceptable as a clarifier, but "Hotlink" is canonical).

**Uploads**:
Files under WordPress's upload directory (`wp_upload_dir()` basedir/baseurl).
This is the only scope the proxy acts on.

**Derivative**:
A resized/cropped variant of an uploaded image (a WordPress "image size"),
stored as its own file beside the original (e.g. `photo-300x200.jpg`). The proxy
treats a Derivative like any other Uploads path — it fetches the exact requested
file from the Origin rather than regenerating it locally.

**Negative cache**:
A short-lived record (transient) that the Origin also lacks a given path, so
repeated Misses for a genuinely-absent file stop re-hitting the Origin.

## Relationships

- A **Miss** triggers the **Proxy**, which runs in exactly one of **Download
  mode** or **Hotlink mode** against the **Origin**.
- The **Proxy** only acts on **Uploads** paths; requests outside the uploads
  directory are ignored.

## Decisions

- Interception happens at the **request** for the missing file, not at
  URL-generation time. This is what lets the proxy catch URLs embedded in post
  content (not just WordPress-generated attachment URLs) and what makes Download
  mode possible. (See `docs/adr/0001-request-interception.md`.)
- Reachability relies on the web server sending missing files to `index.php`
  (nginx `try_files`, which DDEV, Lando, and Pantheon all use). The proxy hooks
  `template_redirect`. A `manage_options`-gated `admin_notices` warning covers the
  Apache-plain-permalinks edge case — on Apache the `index.php` fallback only fires
  with pretty permalinks, so an active proxy with plain permalinks would silently
  appear inert. The decision is a WordPress-free seam (`PermalinkNotice`, inputs
  is-Apache / has-pretty-permalinks / is-active) behind a thin glue
  (`PermalinkNoticeRenderer`) that reads `$GLOBALS['is_apache']`,
  `permalink_structure`, and the resolver's active state. nginx and a
  `production` environment never see it.
- **Download mode is the default**; **Hotlink mode** is opt-in. An optional
  Basic Auth credential pair is attached to the outbound Origin request (for the
  occasional locked Test/Dev origin); the typical Origin is the public
  production domain.
- Download mode fetches the **exact requested file** (original or Derivative)
  from the Origin; no local image regeneration. WordPress does not regenerate
  derivatives on front-end render, so this faithfully reproduces production.
- Miss fallback: Origin `200` → save + serve; Origin `404/410` → local `404` +
  **Negative cache** (~10 min); Origin `5xx`/timeout → local `404`, not cached
  (so it retries).
- Configuration resolves in precedence order: `define()` constant → environment
  variable → DB option → off. Constants/env are the intended home (e.g.
  `wp-config-local.php` on Pantheon/Lando, `web_environment` env vars on DDEV) so
  config survives prod DB pulls. The plugin is agnostic about which file defines
  them. (See `docs/adr/0002-constants-first-config.md`.)
- The settings page is diagnostics-first: shows effective config + its source,
  active status, mode, and download/negative-cache counts, with a "test origin"
  button. DB fields are editable only when no constant/env is set. The "test
  origin" button probes the Origin root and grades **only a 2xx as reachable**
  (4xx/5xx/transport-error are unreachable, with the status code surfaced). The
  Basic Auth password is **write-only**: it is never rendered to the page —
  masked when set, an empty submit keeps the stored value.
- Download write policy: only file types permitted by `wp_check_filetype()` /
  the site's allowed mime types are saved, with a hard deny on executable
  extensions. Responses are streamed to a temp file and atomically renamed.
  Guards: path-traversal containment to the uploads basedir, uploads-scope-only,
  and host-fixed Origin fetch (no arbitrary-host SSRF).
- State is counters-only (downloaded total + negative-cache size) for the
  diagnostics page — no per-file manifest. A `wp uploads-proxy` WP-CLI command
  exposes `status`/`clear-cache`, and an `X-Uploads-Proxy` response header marks
  proxied responses.
- Uninstall (issue #10) removes all of the plugin's persisted state — the
  settings option, both counter options, and every Negative-cache transient — on
  the current site and, on multisite, every site, but **never** deletes downloaded
  media. The decision is a WordPress-free seam (`Uninstaller`, given `delete_option`
  and the Negative-cache clear as injected collaborators) behind thin glue
  (`uninstall.php`) that enforces the `WP_UNINSTALL_PLUGIN` guard, bootstraps the
  autoloader, and iterates sites. The seam owns no filesystem collaborator, so the
  "never delete media" guarantee is structural.

## Tooling decisions

- **Testing stack: PHPUnit `^9.6`, not 10.** Unit tests run with Brain Monkey
  (no WordPress boot); integration tests boot real WordPress via
  `@wordpress/env` (`npm run test:integration` → `wp-env run tests-cli
  vendor/bin/phpunit -c phpunit-integration.xml.dist`), with the Origin mocked
  through `pre_http_request`. PHPUnit is pinned to `^9.6` (with
  `yoast/phpunit-polyfills`) because the WordPress core test suite that wp-env
  runs still uses the PHPUnit 9 API (`parseTestMethodAnnotations()`) that was
  removed in PHPUnit 10 — integration tests cannot run on 10. Do not "upgrade"
  PHPUnit past 9 without first confirming the WP test suite supports it.
- **Coding standards: a PSR-4 OOP profile, not classic procedural WPCS.** This
  plugin is namespaced PSR-4 OOP (strict types, enums, typed properties). The
  phpcs ruleset keeps WordPress-Extra's security/best-practice sniffs but drops
  the sniffs that fight that style (short-array/short-ternary prohibition,
  snake_case variable/method names, hyphenated `class-*.php` filenames) and does
  not reference WordPress-Docs.
- **Runtime autoloading is self-contained, not Composer's.** The plugin has no
  runtime Composer dependencies, so `divine-apparitions-uploads-proxy.php` and `uninstall.php` load
  the `DivineApparitions\UploadsProxy\` namespace from `src/` through a hand-rolled
  PSR-4 `spl_autoload_register` (`autoload.php`), not `vendor/autoload.php`. The
  plugin therefore runs from a plain zip with no build step and ships no `vendor/`.
  Composer stays the dev-tooling manager (PHPUnit, PHPStan, PHP_CodeSniffer); the
  test bootstraps still load `src/` via Composer's dev autoloader.
- **Distribution is a plain zip with no build step.** The shipped package is
  `src/` + `autoload.php` + the main/uninstall files + `readme.txt`/`LICENSE`;
  `vendor/`, `tests/`, `docs/`, and dev config never ship (excluded by
  `.distignore` and `.gitattributes` `export-ignore`). The full build and
  WordPress.org publish process lives in `docs/releasing.md`.

## Flagged ambiguities

- _(none yet)_
