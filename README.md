# Uploads Proxy

> Proxy missing media to a production origin so staging and local environments don't need a full copy of the uploads directory.

When you pull a production database into a staging or local environment, every
media URL points at a file you didn't copy. Uploads Proxy rewrites those URLs to
a configured production origin **only when the file is missing locally**, so
pages render with real images without syncing gigabytes of uploads.

It does this by filtering the URLs WordPress generates for attachments — no
web-server rewrite rules or 404 handlers required.

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

Go to **Settings → Uploads Proxy** and set:

| Setting | Description |
| --- | --- |
| **Enable proxying** | Master switch. Off by default. |
| **Production URL** | Scheme + host of the production site, e.g. `https://example.com`. |
| **Only proxy missing files** | Check the local filesystem first and only rewrite URLs for files that are absent. Leave on for accuracy; turn off to skip the filesystem check and rewrite every uploads URL. |

As a safety guard, the proxy is inert when `wp_get_environment_type()` returns
`production`, so it never rewrites URLs on the live site.

## Development

```bash
composer install        # install dev tooling
composer lint           # PHP_CodeSniffer (WordPress Coding Standards)
composer lint:fix       # auto-fix what it can
composer analyze        # PHPStan static analysis
composer test           # PHPUnit unit tests
composer check          # all of the above
```

### Project layout

```
uploads-proxy.php            Plugin header + bootstrap (PHP/version guards, autoloader)
uninstall.php                Option cleanup on delete (multisite-aware)
src/
  Plugin.php                 Thin orchestrator; wires components to hooks
  Registrable.php            Contract for self-registering components
  Settings/Settings.php      Typed option accessor + sanitiser
  Admin/SettingsPage.php     Settings API admin page
  Proxy/MediaProxy.php       URL-rewriting proxy logic
tests/                       PHPUnit unit tests (Brain Monkey, no WP bootstrap)
.github/workflows/ci.yml     Lint + analyse + test on PHP 8.2–8.4
```

Architecture is deliberately small: the main file only bootstraps, `Plugin`
constructs each component and lets it register its own hooks, and all option
access flows through `Settings` so option keys live in exactly one place.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
