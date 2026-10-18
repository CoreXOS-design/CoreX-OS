#!/usr/bin/env bash
set -euo pipefail

# Build a clean, submittable zip of the CoreX Chrome extension for the Web
# Store. .ai/specs/other-agency-stock.md §11 / .ai/specs/chrome-web-store-
# listing.md.
#
# Source of truth is public/chrome-extension/portal-capture/ — the live,
# maintained extension (manifest v3, has icons, current version). This
# script packages ONLY that directory.
#
# There is a second, STALE, un-iconned duplicate at
# chrome-extension/portal-capture/ (no leading public/) that additionally
# requests `cookies` + `<all_urls>` permissions it does not need. It is
# reported here, not deleted — cleaning it up is a separate, deliberate
# call for Johan to make, out of scope for this build. Because this script
# only ever zips public/chrome-extension/portal-capture/, that stale
# duplicate can never end up in the submitted zip by construction, not by
# a fragile exclude-pattern.

SOURCE_DIR="public/chrome-extension/portal-capture"
STALE_DIR="chrome-extension/portal-capture"
OUT_DIR="build/chrome-extension"

if [ ! -d "$SOURCE_DIR" ]; then
  echo "ERROR: $SOURCE_DIR not found. Run this from the repo root." >&2
  exit 1
fi

if [ -d "$STALE_DIR" ]; then
  echo "NOTE: stale duplicate extension found at $STALE_DIR — NOT included in this package." \
       "It requests broader permissions (cookies, <all_urls>) than the live extension and has no icons." \
       "This is a reported finding, not an automatic cleanup — leave it for a deliberate decision." >&2
fi

VERSION=$(node -e "console.log(require('./${SOURCE_DIR}/manifest.json').version)")
mkdir -p "$OUT_DIR"
ZIP_PATH="${OUT_DIR}/corex-extension-${VERSION}.zip"
rm -f "$ZIP_PATH"

# Zip the directory CONTENTS (manifest.json at the zip root), not the
# directory itself — the Web Store rejects a zip whose manifest.json isn't
# at the top level.
(cd "$SOURCE_DIR" && zip -r -q "../../../${ZIP_PATH}" . -x '*.DS_Store' -x 'tests/*' -x '*.test.cjs' -x '*.test.js')

echo "Packaged ${ZIP_PATH} (extension v${VERSION})"
