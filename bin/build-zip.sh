#!/usr/bin/env bash
#
# Build the exact package that ships to WordPress.org, and verify it before you
# upload it. ALWAYS upload the zip this script produces — never a folder zipped
# by Finder/Explorer (those add __MACOSX/ and .DS_Store entries that break the
# directory's readme/header detection) and never the GitHub "Download ZIP".
#
# Usage:
#   bin/build-zip.sh [git-ref]
#
# Builds from the given git ref (default: HEAD). Uses `git archive`, which honours
# the `export-ignore` attributes in .gitattributes (kept in sync with .distignore),
# so dev-only files (vendor/, node_modules/, tests/, CI and tooling config) never
# reach the package and no macOS cruft is added.
#
# Output: dist/divine-apparitions-uploads-proxy-<version>.zip
set -euo pipefail

SLUG="divine-apparitions-uploads-proxy"   # WordPress.org folder/slug + zip prefix.
MAIN_FILE="${SLUG}.php"                    # File carrying the plugin headers.
REF="${1:-HEAD}"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

# Derive the version from the plugin header so the zip name always matches the
# shipped Version. (Read from the ref being built, not the working tree.)
VERSION="$(git show "${REF}:${MAIN_FILE}" \
  | sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' | head -n1)"
if [[ -z "$VERSION" ]]; then
  echo "ERROR: could not read Version: header from ${MAIN_FILE} at ${REF}." >&2
  exit 1
fi

WORKDIR="$(mktemp -d)"
trap 'rm -rf "$WORKDIR"' EXIT

# Stage the shippable tree under a single top folder named for the slug.
git archive --format=tar --prefix="${SLUG}/" "$REF" | tar -x -C "$WORKDIR"

mkdir -p dist
ZIP="${REPO_ROOT}/dist/${SLUG}-${VERSION}.zip"
rm -f "$ZIP"
( cd "$WORKDIR" && zip -rq "$ZIP" "$SLUG" )

# ---------------------------------------------------------------------------
# Verify the package the way WordPress.org's intake checks do.
# ---------------------------------------------------------------------------
fail() { echo "VERIFY FAILED: $*" >&2; exit 1; }

LISTING="$(unzip -Z1 "$ZIP")"

# Exactly one top-level entry, and it is the slug folder.
TOP="$(printf '%s\n' "$LISTING" | sed 's#/.*##' | sort -u)"
[[ "$TOP" == "$SLUG" ]] || fail "expected a single top folder '${SLUG}/', got: ${TOP//$'\n'/, }"

# readme.txt and the header file are present at the folder root.
printf '%s\n' "$LISTING" | grep -qx "${SLUG}/readme.txt" \
  || fail "readme.txt missing from package root."
printf '%s\n' "$LISTING" | grep -qx "${SLUG}/${MAIN_FILE}" \
  || fail "${MAIN_FILE} (plugin header file) missing from package root."

# The header file actually carries a Plugin Name header.
unzip -p "$ZIP" "${SLUG}/${MAIN_FILE}" | grep -q '^[[:space:]]*\*\?[[:space:]]*Plugin Name:' \
  || fail "${MAIN_FILE} has no 'Plugin Name:' header."

# No macOS cruft or dev files leaked in. The `bin/` build tooling is caught here
# too: shell scripts trip WordPress.org's "application files are not permitted"
# check, so a leaked bin/ would fail intake even though it is harmless at runtime.
CRUFT_RE='(^|/)(__MACOSX|\.DS_Store)(/|$)|(^|/)(vendor|node_modules|tests|bin)/'
if printf '%s\n' "$LISTING" | grep -Eq "$CRUFT_RE"; then
  fail "package contains macOS cruft or dev files:"$'\n'"$(printf '%s\n' "$LISTING" | grep -E "$CRUFT_RE")"
fi

echo "OK  $ZIP"
echo "    version: ${VERSION}   ref: ${REF}   files: $(printf '%s\n' "$LISTING" | grep -vc '/$')"
echo "    Upload THIS zip to WordPress.org. Do not Finder/Explorer-zip the folder."
