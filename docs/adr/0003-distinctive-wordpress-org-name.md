# The public *display name* is brand-led (Divine Apparitions Uploads Proxy); the slug, text domain, and internal identifiers stay `uploads-proxy` / `uploads_proxy`

**Status:** accepted (updated 2026-06-29 after WordPress.org re-review — see *Update* below)

The plugin's public identity is a **brand-led display name, "Divine Apparitions
Uploads Proxy"**, over a **slug and text domain that stay `uploads-proxy`**. The
internal identifiers — the `uploads_proxy_*` option keys, the public
`uploads_proxy_is_allowed_file` / `uploads_proxy_origin_timeout` filters, the
`X-Uploads-Proxy` response header, the `wp uploads-proxy` WP-CLI command, and the
`?page=uploads-proxy` admin-page slug — also keep the `uploads_proxy` /
`uploads-proxy` prefix. Only the human-readable name carries the brand.

## Why the brand-led name

The WordPress.org submission was **pended at auto-prereview on 2026-06-29**. The
blocking issue was the **name**: "Uploads Proxy" is too generic and too close to
existing names in the "uploads proxy" space, which the directory's naming guideline
does not allow. The reviewer's recommended remedy is a distinctive identifier *at
the front* of the name — ideally the author's own brand.

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

## Why the slug and text domain stay `uploads-proxy`

The slug is assigned by WordPress.org at first submission and, in practice, is **not
changed** for an existing submission — the directory kept this plugin's original
`uploads-proxy` slug. WordPress.org requires the **Text Domain to equal the slug**,
so the text domain stays `uploads-proxy` to match. The display name is free-form and
does *not* have to match the slug, so the brand can lead the name while the slug and
text domain remain the original short identifier.

This also keeps the slug / text domain aligned with the internal `uploads_proxy`
prefix (option keys, filters, header, CLI), and keeps the
`WordPress.NamingConventions.PrefixAllGlobals` allowlist (`uploads_proxy` +
`DivineApparitions\UploadsProxy`) and the `WordPress.WP.I18n` `text_domain`
(`uploads-proxy`) intact.

## The main plugin file

The main file is **`divine-apparitions-uploads-proxy.php`**. WordPress identifies a
plugin by the file that carries the plugin header, not by a name matching the slug,
and WordPress.org's own Plugin Check accepts a main file whose name differs from the
slug folder (it reported zero filename findings for
`uploads-proxy/divine-apparitions-uploads-proxy.php`). The file name is therefore
left as-is; the slug folder and the text domain are what must align, and they do.

## Update (2026-06-29, re-review)

An earlier revision of this ADR moved the **slug and text domain** to
`divine-apparitions-uploads-proxy`, on the assumption that the renamed plugin would
receive a new matching slug. It would not: the directory kept the original
`uploads-proxy` slug, and the v0.11.0 upload failed Plugin Check with a
`WordPress.WP.I18n.TextDomainMismatch` on every translatable string ("expected
`uploads-proxy`, got `divine-apparitions-uploads-proxy`"). The fix was to revert the
**Text Domain header, every i18n text-domain argument, the `phpcs.xml.dist`
`text_domain` property, and the build/CI slug** back to `uploads-proxy`, while
keeping the brand-led display name. This ADR records the corrected decision.

The earlier revision also renamed the main file to
`divine-apparitions-uploads-proxy.php`; that file name is retained (see *The main
plugin file* above) because Plugin Check accepts it and renaming it back buys
nothing.

## Consequences

- The `.org` permalink and SVN path are `uploads-proxy`
  (`wordpress.org/plugins/uploads-proxy/`). The CI `plugin-check` job builds and
  validates under that slug (the `git archive --prefix`, `build-dir`, and the
  `WordPress/plugin-check-action` `slug:` input are all `uploads-proxy`).
- The GitHub repository stays `wp-uploads-proxy`; the `.org` slug deliberately
  differs, which is allowed.
- The admin menu label, bootstrap / notice prefixes, and internal identifiers stay
  the short "Uploads Proxy" / `uploads_proxy`; only the settings-page heading, the
  plugin header `Plugin Name`, and the readme titles carry the full brand-led name.
