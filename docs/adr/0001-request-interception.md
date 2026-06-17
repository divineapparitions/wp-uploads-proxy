# Intercept the missing-file request, not the generated URL

**Status:** accepted

The plugin resolves a missing upload by intercepting the **HTTP request for the
file itself** (hooking `template_redirect`, reachable because nginx `try_files`
— used by DDEV, Lando, and Pantheon — sends missing files to `index.php`) and
then either downloading-and-serving or `302`-redirecting to the origin. We did
**not** take the more obvious WordPress route of filtering attachment URL
functions (`wp_get_attachment_url`, `wp_calculate_image_srcset`, …).

## Considered options

- **URL-rewriting filters** (the "obvious" WP approach, and what the initial
  scaffold sketched): rejected because it only sees URLs WordPress itself
  generates — it misses images embedded directly in `post_content` (the most
  common broken image after a database pull) and can *only* hotlink, never
  download-and-cache.
- **Request interception** (chosen): operates on the real request, so it catches
  every missing upload regardless of how its URL was produced, supports both
  download and hotlink modes, and costs nothing once a file is on disk (the web
  server serves it directly thereafter).

## Consequences

- On Apache the `index.php` fallback only fires with pretty permalinks enabled;
  an admin notice covers that edge case. nginx (the real target) is unaffected.
- A future reader who assumes URL filtering would be "simpler" should know it was
  considered and rejected for the two reasons above.
