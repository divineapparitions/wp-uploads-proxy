# The public name is brand-led (Divine Apparitions Uploads Proxy); internal identifiers stay `uploads_proxy`

**Status:** accepted

The plugin's public identity is **display name "Divine Apparitions Uploads Proxy"**
with **slug and text domain `divine-apparitions-uploads-proxy`**. The internal
identifiers — the `uploads_proxy_*` option keys, the public
`uploads_proxy_is_allowed_file` / `uploads_proxy_origin_timeout` filters, the
`X-Uploads-Proxy` response header, the `wp uploads-proxy` WP-CLI command, and the
`?page=uploads-proxy` admin-page slug — deliberately keep the original
`uploads_proxy` / `uploads-proxy` prefix.

## Why the rename

The WordPress.org submission (v0.10.0) was **pended at auto-prereview on
2026-06-29**. The blocking issue was the name: `Uploads Proxy` / `uploads-proxy`
is too generic and too close to existing names in the "uploads proxy" space, which
the directory's naming guideline does not allow. The reviewer's recommended remedy
is a distinctive identifier *at the front* of the name — ideally the author's own
brand.

Leading with **Divine Apparitions** (the company that owns the plugin, and already
the `DivineApparitions\UploadsProxy` namespace and the `Author` header) is the
reviewer's preferred pattern: it is distinctive, it keeps the descriptive
"Uploads Proxy" tail so the name still says what the plugin does, and — because the
brand is the owner — it also quietly answers the review email's ownership question.

Alternatives considered and rejected:

- **A generic qualifier** ("Local Uploads Proxy", "Advanced …"). The guideline
  explicitly says adding a generic word does not make a name distinctive; "Local"
  additionally collides with WP Engine's "Local" dev-app trademark.
- **A standalone coined product name** ("Phantom / Apparition / Conjure Uploads
  Proxy"). Viable and on-brand, but a brand-first name is the lowest-risk choice,
  matches the existing namespace/author, and needs no separate trademark clearance.

## Why internal identifiers do not change

The slug and text domain are the plugin's *public* identity, and WordPress.org
requires the text domain to equal the slug — so those had to move. The option
keys, filter names, response header, CLI command, and admin-page slug are a
*different contract*: stored data, an integration API third parties may hook, and
internal routing. Renaming them would buy nothing for the review (the directory
does not inspect them), would churn the public filter/header API, and — for the
option keys — would orphan settings on any existing install. Keeping them also
keeps the `WordPress.NamingConventions.PrefixAllGlobals` allowlist (`uploads_proxy`
+ `DivineApparitions\UploadsProxy`) intact.

This is a deliberate split between the *marketing identity* (`divine-apparitions-uploads-proxy`)
and the *code/integration identity* (`uploads_proxy` / `X-Uploads-Proxy`), recorded
here so a future contributor does not "fix" the apparent inconsistency.

## Consequences

- The `.org` permalink and SVN path become `divine-apparitions-uploads-proxy`
  (`wordpress.org/plugins/divine-apparitions-uploads-proxy/`). This supersedes the
  earlier `slug = uploads-proxy` decision documented in `docs/releasing.md` and the
  CI `plugin-check` job; both were updated to the new slug (the `git archive`
  `--prefix`, `build-dir`, and the `WordPress/plugin-check-action` `slug:` input).
- The GitHub repository stays `wp-uploads-proxy`; the `.org` slug deliberately
  differs, which is allowed.
- The main plugin file was renamed `uploads-proxy.php` →
  `divine-apparitions-uploads-proxy.php` (the file should match the slug); its
  references in `phpstan.neon.dist` and the integration bootstrap were updated.
- The admin menu label and the bootstrap/notice prefixes stay the short
  "Uploads Proxy"; only the settings-page heading, plugin header, and readme titles
  carry the full brand-led name.
- Before re-upload, a short reply to `plugins@wordpress.org` must request the
  `divine-apparitions-uploads-proxy` slug reservation (the slug is permanent after
  approval).
