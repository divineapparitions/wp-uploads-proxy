# Releasing

Maintainer reference for how Uploads Proxy is built and published. This file is
**not shipped** in the plugin zip — `docs/` is excluded by `.distignore` and by
`export-ignore` in `.gitattributes`.

## Distribution model: plain zip, no build step

The plugin runs from a plain zip with **no build step**. It has no runtime
Composer dependencies and loads its own classes through a self-contained PSR-4
autoloader (`autoload.php`), so `vendor/` is **development tooling only** and is
never shipped. Composer manages the dev tools (PHPUnit, PHPStan, PHP_CodeSniffer);
`composer.lock` is committed for reproducible CI but excluded from the package.

This means a raw source zip already works — there is no `composer install` step
for end users, and `composer`/`git archive` produces an installable plugin.

## What ships vs. what doesn't

- **Ships:** `uploads-proxy.php`, `autoload.php`, `src/`, `uninstall.php`,
  `readme.txt`, `LICENSE`, and any `languages/` translations.
- **Excluded from the package:** everything listed in `.distignore` (the
  WordPress.org build) and in the `export-ignore` block of `.gitattributes`
  (`git`/`composer archive`) — `tests/`, `docs/`, `vendor/`, `node_modules/`,
  CI config, and dev tooling config.
- Keep `.distignore` and the `.gitattributes` `export-ignore` list roughly in
  sync — they are two mechanisms expressing the same "dev-only, do not ship" set.

## Version bump checklist (every release)

Three version markers must agree:

1. `Version:` header in `uploads-proxy.php`
2. `const VERSION` in `uploads-proxy.php`
3. `Stable tag:` in `readme.txt` (the WordPress.org format)

Then:

4. Move `CHANGELOG.md` `[Unreleased]` items under the new version and add the
   `compare/` link at the bottom.
5. Tag the release: `git tag vX.Y.Z`.

> Known drift to reconcile at the next release: `readme.txt` `Stable tag` may lag
> the plugin `Version` — verify all three markers match before tagging.

## GitHub

Canonical repo: **`github.com/divineapparitions/wp-uploads-proxy`** (the Divine
Apparitions org). Releases are cut from `master`.

## WordPress.org (when publishing there)

The `.org` plugin directory is SVN-based, separate from Git. Recommended
automation:

- Submit the plugin once for review to obtain the SVN repository.
- On a pushed git tag, a GitHub Action (e.g.
  `10up/action-wordpress-plugin-deploy`) builds the package per `.distignore` and
  pushes it to SVN `trunk` and `tags/X.Y.Z`. Store the SVN credentials as repo
  secrets.
- `.org` page assets (banner, icon, screenshots) live in the SVN `assets/`
  directory (outside `trunk`), updated via
  `10up/action-wordpress-plugin-asset-update`.
- Because there are no runtime dependencies, the build needs no `composer install`
  for the shipped package — `autoload.php` + `src/` are all that run.

> Status: the deploy workflow is **not yet committed** — `.github/workflows/` has
> CI only. Add a tag-triggered deploy workflow before the first `.org` release.
