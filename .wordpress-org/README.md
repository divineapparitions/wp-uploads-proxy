# WordPress.org plugin assets

Images in this directory are published to the plugin's WordPress.org **page**
(`https://wordpress.org/plugins/divine-apparitions-uploads-proxy/`) — the directory
"store" assets. They are **not** shipped inside the plugin zip. On push to `master`
touching this directory, `.github/workflows/assets.yml` syncs them to the SVN
`assets/` directory via `10up/action-wordpress-plugin-asset-update`.

Drop the real images here with these **exact** names (PNG or JPG unless noted):

## Icon (square, 1:1)
- `icon-128x128.png` — standard
- `icon-256x256.png` — retina (recommended)
- `icon.svg` — optional vector; takes precedence over the PNGs when present

## Banner (top of the plugin page)
- `banner-772x250.png` — standard
- `banner-1544x500.png` — retina (recommended)

## Screenshots
- `screenshot-1.png`, `screenshot-2.png`, … — shown in the Screenshots tab, in
  order. Each `screenshot-N` pairs with the Nth line under `== Screenshots ==` in
  `readme.txt`; add that section with one caption per screenshot.

## Notes
- Only these recognised names are displayed. Any other file here (including this
  `README.md`) is ignored by WordPress.org.
- Keep files reasonably small; the directory recompresses banners.
- Official spec: https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/
