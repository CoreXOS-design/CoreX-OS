# AT-449 — CoreX Chrome extension: Web Store readiness + restyle

**Status:** in build on QA2 (Andre's lane). **Owner decision needed before submission:** listing visibility + owning Google account (see §6).

## 1. What and why
Agents currently install the extension by hand (download zip → Developer mode → Load unpacked). That
does not auto-update, shows a "disable developer mode extensions" warning at every Chrome start, and
cannot be rolled out to a second agency cleanly. Publishing to the Chrome Web Store (Unlisted) fixes all
three. In the same ticket the popup is restyled to the current CoreX look and given the real CoreX logo.

Source of truth for the extension: `public/chrome-extension/portal-capture/` (served by
`ProfileController::downloadExtension`, v3.6.x). The root-level `chrome-extension/portal-capture/`
(v3.3.6, "Portal Capture for HFC") is a stale copy and is **never** to be published.

## 2. Pillars
Tooling for ingest only. Capture/pull still go through the existing token-authenticated ingest endpoints,
which already call `TrackedPropertyMatchOrCreateService::matchOrCreate()` (non-negotiable #10). **No
server-side change in this ticket.**

## 3. Scope (exactly this, nothing else)
1. **Logo/icon** — toolbar + store icons (16/48/128) generated from the current CoreX logo, `CoreX Logo.png`
   (repo root, added 2026-10-12). It is a square mark on a white background, so it is placed on a white
   rounded tile that reads on both light and dark Chrome toolbars. Same icon shown in the popup header. The
   older glowing-ring image `storage/images/CoreX logo.png` was unreferenced and is deleted.
2. **Theme** — a small light/dark toggle in the popup header. The popup **always opens in light**. The
   choice applies to that popup session only (not persisted) — by design, per Johan.
3. **Restyle** — popup restyled to the AT-336 neutral system (`.ai/DESIGN-SYSTEM.md`): slate neutrals,
   6px radius, flat header, brand blue `#0ea5e9` only on the primary CTA and accents. No agency colours
   (the popup is not agency-aware and must not become HFC-looking — non-negotiable #9).
4a. **First-run Settings/Back bug (found by Andre, pre-existing since 2026-03)** — with no token saved the popup opens
   on Settings, so Back returned to Settings and the Settings button did nothing. Back now returns to the screen
   Settings was opened from, or the "Browse to a portal" screen when there was none.
4. **Store-readiness fixes** — remove the Google Fonts `@import` (a third-party request on every popup open
   and a privacy-disclosure item; system font stack instead); bump version to 3.7.0; add
   `scripts/build-extension-zip.sh` producing the store package (manifest at zip root, no `tests/`).
5. **Store paperwork** — listing text, single-purpose statement, per-permission justifications and privacy
   disclosure (§5), recorded here.

## 4. Audit findings that shaped scope (checked against the code)
- Permissions `storage, activeTab, unlimitedStorage, notifications, alarms` are **all used** (offline IndexedDB
  queue, completion notifications, queue-drain alarm). Nothing to trim.
- `importScripts('queue-idb.js')` loads a bundled file — allowed (no remotely hosted code anywhere).
- **CoreX host permission deliberately NOT added.** The API URL is user-configurable and calls work via CORS
  (`config/cors.php` allows `chrome-extension://`). Declaring a fixed host would add an install warning and
  still not cover a custom URL. Revisit only if the store reviewer asks.
- `ProfileController::downloadExtension` zips the whole folder including `tests/` — harmless for agents,
  left unchanged (out of scope); the store build script excludes `tests/`.
- No server code gates on the extension version, so the bump is safe.

## 5. Store paperwork

**Name:** CoreX  **Category:** Productivity  **Visibility:** Unlisted (recommended)

**Short description (≤132 chars):** Capture and pull property listings from Property24, PrivateProperty and more straight into your CoreX account.

**Long description:**
CoreX for Chrome connects your browser to your CoreX OS account. Open a listing on Property24 or
PrivateProperty and pull it into CoreX as a property in one click, or capture a whole page of search
results for prospecting. On supported deeds and trace pages (CMAInfo, TheVirtualAgent) it captures the
record you are viewing into CoreX. Nothing is captured unless you click; captured data goes only to the
CoreX address you configure. Requires a CoreX account and an API token from My Portal.

**Single purpose:** Capture property and ownership information from the portal page the user is viewing and
send it to the user's own CoreX account.

**Permission justifications**
| Permission | Why |
|---|---|
| `activeTab` | Read the listing on the tab the user opens the popup on. |
| `storage` | Save the user's CoreX address, API token and last-capture status. |
| `unlimitedStorage` | Hold large captures (listing photos/records) in a local queue until the CoreX server accepts them. |
| `notifications` | Tell the user a capture or pull has finished while the popup is closed. |
| `alarms` | Retry sending queued captures after a network failure. |
| Host access: property24.com, privateproperty.co.za, cmainfo.co.za, app.thevirtualagent.co.za | Content scripts that read the page the user is capturing from. |

**Privacy disclosure (dashboard "Privacy practices" tab):** collects *website content* and *personally
identifiable information* (owner/director names and IDs shown on the pages the user captures); used only to
provide the capture feature; sent only to the user's CoreX server; not sold, not used for advertising, not
transferred to third parties. A public **privacy policy URL is mandatory** — see §6.

## 6. Decisions / blockers for Johan (business, not technical)
- **Who owns the Google developer account** (US$5 one-off) — a company account, not a personal Gmail.
- **Unlisted vs Public** — Unlisted: agents install from a link, never searchable. Recommended.
- **Privacy policy URL** — `/privacy` exists (platform policy) but does not mention the browser extension.
  Adding an extension section to a legal page is Johan's call; suggested wording is the §5 disclosure.
- Store screenshots (1280×800) and the 440×280 promo tile need a real browser session to capture; not
  produced in this ticket.

## 7. Acceptance criteria
- Popup opens light every time; toggle flips light↔dark immediately; every state (not-on-portal, settings,
  choose, ready, capturing, complete, resume, pull preview, pulling, pull complete, error, duplicate warning,
  connection footer) is legible in both themes.
- Toolbar and header show the CoreX logo; `manifest.json` icons resolve; version reads v3.7.0 in popup and
  `chrome://extensions`.
- No network request leaves the popup except to the configured CoreX API URL.
- `popup.js` behaviour unchanged (no DOM ids/classes removed); `deeds-cleanslate.test.cjs` still 71/71.
- `scripts/build-extension-zip.sh` output has `manifest.json` at root, valid JSON, referenced files all
  present, no `tests/`.

## 8. Files
Modify: `public/chrome-extension/portal-capture/{manifest.json,popup.html,popup.js,styles.css,icons/*}`.
Create: `scripts/build-extension-zip.sh`, this spec.
Not in the setup wizard: no agency setting is added (theme is a per-device UI preference).
