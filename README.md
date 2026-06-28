# Uploads Proxy

> Proxy missing media to a production origin so staging and local environments don't need a full copy of the uploads directory.

When you pull a production database into a staging or local environment, every
media URL points at a file you didn't copy — pages render with broken images and
dead file links. Uploads Proxy intercepts the request for any **missing** uploads
file and resolves it against a configured production **Origin**, so pages render
with real media without syncing gigabytes of uploads.

It works at the request level — hooking `template_redirect` when the web server
routes a missing file to `index.php` (nginx `try_files`, used by DDEV, Lando, and
Pantheon). That means it catches *every* missing upload, including images embedded
in post content, size derivatives, and `srcset` candidates, not just the URLs
WordPress generates for attachments. (See
[`docs/adr/0001-request-interception.md`](docs/adr/0001-request-interception.md).)

A Miss resolves in one of two modes:

- **Download mode** (default): stream the file from the Origin, save it into the
  local uploads directory, and serve it. Every later request is served by the web
  server, so your environment accumulates exactly the subset of media your tests
  touch — never the whole uploads folder.
- **Hotlink mode** (opt-in): issue a temporary `302` redirect to the file on the
  Origin, writing nothing locally.

## Requirements

- PHP **8.2+**
- WordPress **6.5+**
- [Composer](https://getcomposer.org/) (for development)

## Installation (from source)

```bash
git clone git@github.com:philltran/wp-uploads-proxy.git uploads-proxy
cd uploads-proxy
composer install --no-dev   # production dependencies only
```

Place the resulting `uploads-proxy` directory in `wp-content/plugins/` and
activate it from **Plugins** in the admin.

> `vendor/` is not committed. Running `composer install` generates the autoloader
> the plugin needs to boot.

## Configuration

Configuration resolves in precedence order: **`define()` constant → environment
variable → DB option → off**. Constants and environment variables are the intended
home — they live outside the database, so they survive pulling a production
database into a local or staging environment and configure themselves hands-free
in CI. The DB option (set via the settings page) is a fallback for when no
constant or env var is present. (See
[`docs/adr/0002-constants-first-config.md`](docs/adr/0002-constants-first-config.md).)

| Constant / env var | Description |
| --- | --- |
| `UPLOADS_PROXY_ORIGIN` | Scheme + host of the Origin, e.g. `https://example.com`. Configuring an Origin this way is self-enabling. |
| `UPLOADS_PROXY_MODE` | `download` (default) or `hotlink`. |
| `UPLOADS_PROXY_AUTH_USER` | Optional Basic Auth username for a locked Test/Dev Origin. |
| `UPLOADS_PROXY_AUTH_PASS` | Optional Basic Auth password (only active alongside a username). |

Define these wherever your stack keeps environment-specific config that is *not*
overwritten by a database pull — e.g. `wp-config-local.php` on Pantheon/Lando, or
`web_environment` vars on DDEV.

### Settings page (fallback)

**Settings → Uploads Proxy** is diagnostics-first: it shows the effective Origin,
mode, and Basic Auth each labelled with where the value came from (constant / env
/ DB), the active status, the download and Negative-cache counters, and a **Test
Origin connection** button. Fields are editable only when no constant or env var
overrides them; the Basic Auth password is write-only and never rendered back into
the page.

As a safety guard, the proxy is **inert when `wp_get_environment_type()` returns
`production`**, so it never acts on the live site, and it stays off until an Origin
is configured.

### Apache note

Request interception needs the web server to route a missing file to `index.php`.
nginx (DDEV, Lando, Pantheon) always does this. On Apache it only happens with
pretty permalinks enabled — the plugin shows an admin notice for this case. Enable
pretty permalinks at **Settings → Permalinks**.

### Filters

| Filter | Default | Purpose |
| --- | --- | --- |
| `uploads_proxy_is_allowed_file` | WordPress's allowed-MIME list | Whether a non-executable Uploads file may be downloaded and saved (Download mode). |
| `uploads_proxy_origin_timeout` | `15` (seconds) | Outbound Origin request timeout. |

**SVG (and other front-end-disallowed types).** In Download mode a file is only
saved if `wp_check_filetype()` recognises its extension. On a front-end request that
list omits types that are only registered for logged-in uploaders — notably **SVG**
(via the *SVG Support* plugin) — so SVGs are not proxied and appear broken. Opt them
in (executable extensions can never be re-enabled this way):

```php
add_filter( 'uploads_proxy_is_allowed_file', function ( $allowed, $relativePath, $ext ) {
    return 'svg' === $ext ? true : $allowed;
}, 10, 3 );
```

Alternatively, switch to **Hotlink** mode, which redirects to the Origin and applies
no write gate, so SVGs (and every other type) resolve without local copies.

**Large media.** The whole Origin response is buffered in memory, so the timeout is
a short 15s by default. For an Origin with large files (multi-MB) on a slow link,
either raise it — `add_filter( 'uploads_proxy_origin_timeout', fn() => 60 );` — or
prefer Hotlink mode to avoid downloading and buffering large files locally.

## WP-CLI

```bash
wp uploads-proxy status        # active state, effective Origin/mode + source, counters
wp uploads-proxy clear-cache   # clear the Negative cache and reset counters (keeps media)
```

## Development

```bash
composer install        # install dev tooling
composer lint           # PHP_CodeSniffer (WordPress Coding Standards)
composer lint:fix       # auto-fix what it can
composer analyze        # PHPStan static analysis
composer test           # PHPUnit unit tests (Brain Monkey, no WP boot)
composer check          # all of the above
```

Integration tests boot real WordPress via [`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env)
(Docker required) with the Origin mocked through `pre_http_request`:

```bash
npm install
npm run env:start          # start the wp-env containers
npm run test:integration   # PHPUnit against real WordPress
```

> PHPUnit is pinned to `^9.6`: the WordPress core test suite that wp-env runs
> still uses a PHPUnit 9 API removed in PHPUnit 10, so integration tests cannot
> run on 10.

### Project layout

```
uploads-proxy.php            Plugin header + bootstrap (PHP/WP version guards, autoloader)
uninstall.php                State cleanup on delete (multisite-aware; never deletes media)
src/
  Plugin.php                 Thin orchestrator; wires components to hooks
  Registrable.php            Contract for self-registering components
  Config/                    Constants-first resolver, effective config, Mode, Basic Auth
  Proxy/                     Request interception: RequestHandler, UploadsScope,
                             OriginClient, FileWriter, HttpResponder
  Settings/Settings.php      Typed option accessor + sanitiser
  Admin/                     Diagnostics-first settings page, Origin probe, permalink notice
  State/                     Counters, NegativeCache, Uninstaller
  Cli/                       `wp uploads-proxy` command (status / clear-cache)
tests/Unit/                  PHPUnit unit tests (Brain Monkey, no WP bootstrap)
tests/Integration/          PHPUnit integration tests (real WordPress via @wordpress/env)
.github/workflows/ci.yml     Lint + analyse + test on PHP 8.2–8.4
```

Architecture is deliberately small and follows a deep-module/glue split: a
WordPress-free decision core (e.g. `UploadsScope`, `ConstantsFirstResolver`,
`Uninstaller`) sits behind thin glue that talks to WordPress. The main file only
bootstraps, `Plugin` constructs each component and lets it register its own hooks,
and all option access flows through `Settings` so option keys live in exactly one
place. See [`CONTEXT.md`](CONTEXT.md) for the domain language and design decisions.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
