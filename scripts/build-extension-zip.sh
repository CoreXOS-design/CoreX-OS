#!/usr/bin/env bash
# AT-449 — build the Chrome Web Store upload package for the CoreX extension.
#
# Usage: scripts/build-extension-zip.sh [output.zip]
#   default output: <repo>/storage/app/corex-extension-store.zip
#
# Packages public/chrome-extension/portal-capture with manifest.json at the zip
# ROOT (a Web Store requirement) and WITHOUT tests/. Fails before zipping if the
# manifest is invalid JSON or any file it (or the service worker) references is
# missing — a broken package is only discovered after a multi-day review otherwise.
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$REPO/public/chrome-extension/portal-capture"
OUT="${1:-$REPO/storage/app/corex-extension-store.zip}"

[ -f "$SRC/manifest.json" ] || { echo "ERROR: $SRC/manifest.json not found" >&2; exit 1; }

python3 - "$SRC" <<'PY'
import json, os, re, sys
src = sys.argv[1]
m = json.load(open(os.path.join(src, 'manifest.json'), encoding='utf-8-sig'))
refs = set()
refs.add(m['background']['service_worker'])
refs.add(m['action']['default_popup'])
refs.update(m.get('icons', {}).values())
refs.update(m['action'].get('default_icon', {}).values())
for cs in m.get('content_scripts', []):
    refs.update(cs.get('js', []))
    refs.update(cs.get('css', []))
# files pulled in by the service worker / popup
sw = open(os.path.join(src, m['background']['service_worker']), encoding='utf-8').read()
refs.update(re.findall(r"importScripts\(\s*'([^']+)'", sw))
html = open(os.path.join(src, m['action']['default_popup']), encoding='utf-8').read()
refs.update(re.findall(r'(?:src|href)="([^"#]+)"', html))
missing = sorted(r for r in refs if not r.startswith(('http://', 'https://')) and not os.path.isfile(os.path.join(src, r)))
if missing:
    sys.exit('ERROR: manifest/popup reference missing files: ' + ', '.join(missing))
remote = [r for r in refs if r.startswith(('http://', 'https://'))]
if remote:
    sys.exit('ERROR: remote resources referenced (Web Store policy): ' + ', '.join(remote))
if re.search(r'@import\s+url\(', open(os.path.join(src, 'styles.css'), encoding='utf-8').read()):
    sys.exit('ERROR: styles.css imports a remote stylesheet')
print('manifest OK — %s v%s, %d referenced files present' % (m['name'], m['version'], len(refs)))
PY

mkdir -p "$(dirname "$OUT")"
rm -f "$OUT"
( cd "$SRC" && zip -q -r "$OUT" . -x 'tests/*' -x '*.DS_Store' )
echo "built: $OUT ($(du -h "$OUT" | cut -f1))"
unzip -l "$OUT" | tail -n +4 | head -n -2 | awk '{print "  " $4}'
