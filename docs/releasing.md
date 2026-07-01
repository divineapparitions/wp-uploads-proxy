# Releasing

Maintainer reference for how Divine Apparitions Uploads Proxy is built and published. This file is
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

- **Ships:** `divine-apparitions-uploads-proxy.php`, `autoload.php`, `src/`, `uninstall.php`,
  `readme.txt`, `LICENSE`, and any `languages/` translations.
- **Excluded from the package:** everything listed in `.distignore` (the
  WordPress.org build) and in the `export-ignore` block of `.gitattributes`
  (`git`/`composer archive`) — `tests/`, `docs/`, `vendor/`, `node_modules/`,
  CI config, and dev tooling config.
- Keep `.distignore` and the `.gitattributes` `export-ignore` list roughly in
  sync — they are two mechanisms expressing the same "dev-only, do not ship" set.

## Building the upload package

> **Always upload the zip produced by `bin/build-zip.sh`.** Do **not** right-click
> the plugin folder and "Compress" in Finder/Explorer (that injects `__MACOSX/` and
> `.DS_Store` entries that break WordPress.org's readme/header detection — symptom:
> the review reports "We cannot find the readme.txt" and "We cannot find the file
> containing plugin headers" even though both exist), and do **not** upload GitHub's
> "Download ZIP" / source archive (wrong top-folder name, includes dev files).

```sh
composer build          # or: bash bin/build-zip.sh [git-ref]
```

This runs `git archive` from `HEAD` (honouring `.gitattributes` `export-ignore`),
stages the tree under a single top folder named for the slug, zips it to
`dist/divine-apparitions-uploads-proxy-<version>.zip`, and **verifies** the result:
a single top folder, `readme.txt` + the header file at its root, a real
`Plugin Name:` header, and no macOS cruft or dev files. The script exits non-zero
if any check fails — if it prints `OK`, that exact zip is safe to upload.

Optional: confirm against the same checks WordPress.org runs by extracting the zip
into a folder named for the **current** `.org` slug and running Plugin Check, e.g.
`wp plugin check uploads-proxy`. While `.org` still has the slug as `uploads-proxy`,
the only expected finding is `WordPress.WP.I18n.TextDomainMismatch` (the directory
team flips the slug to `divine-apparitions-uploads-proxy` on their side; see
ADR-0003). There must be **no** readme or plugin-header "cannot find" errors.

## Version bump checklist (every release)

Three version markers must agree:

1. `Version:` header in `divine-apparitions-uploads-proxy.php`
2. `const VERSION` in `divine-apparitions-uploads-proxy.php`
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

**Plugin slug: `divine-apparitions-uploads-proxy`** (not `wp-uploads-proxy`). The
slug is the installed folder name and the `.org` permalink
(`wordpress.org/plugins/divine-apparitions-uploads-proxy/`), and WordPress.org
requires it to equal the plugin **Text Domain** — which is
`divine-apparitions-uploads-proxy` throughout the code. The GitHub repo is named
`wp-uploads-proxy`; the `.org` slug deliberately differs and that is fine.

> Gotcha: `10up/action-wordpress-plugin-deploy` defaults its `slug` to the GitHub
> repository name (`wp-uploads-proxy`). Set `slug: divine-apparitions-uploads-proxy`
> explicitly in the deploy workflow, and install/extract the plugin into a folder
> named `divine-apparitions-uploads-proxy/` when validating with Plugin Check —
> otherwise Plugin Check derives the expected text domain from the folder name and
> reports a spurious `WordPress.WP.I18n.TextDomainMismatch` for every translatable
> string.

The `.org` plugin directory is SVN-based, separate from Git. Recommended
automation:

- Submit the plugin once for review to obtain the SVN repository. (Done — approved
  2026-07-01; the first release, 0.11.1, was uploaded to SVN manually.)
- `.github/workflows/deploy.yml`: when a **GitHub Release is published**,
  `10up/action-wordpress-plugin-deploy` builds the package per `.distignore` and
  pushes it to SVN `trunk` and `tags/X.Y.Z`. The `.org` slug is pinned to
  `divine-apparitions-uploads-proxy` (the action otherwise defaults to the GitHub
  repo name), and a leading `v` on the tag is stripped so the SVN tag matches
  `readme.txt` `Stable tag`.
- `.github/workflows/assets.yml`: on push to `master` touching `.wordpress-org/`,
  `10up/action-wordpress-plugin-asset-update` pushes the `.org` page assets
  (banner, icon, screenshots) to the SVN `assets/` directory (outside `trunk`).
  Put the images in `.wordpress-org/` — see its `README.md` for names and sizes.
- Both workflows require the `SVN_USERNAME` and `SVN_PASSWORD` repository secrets
  (Settings → Secrets and variables → Actions).
- Because there are no runtime dependencies, the build needs no `composer install`
  for the shipped package — `autoload.php` + `src/` are all that run.

> To cut a release: bump the version markers (checklist above), commit, then
> publish a GitHub Release tagged `X.Y.Z` (or `vX.Y.Z`) — `deploy.yml` does the SVN
> push. If unsure, validate first with the workflow's `dry-run` option.
