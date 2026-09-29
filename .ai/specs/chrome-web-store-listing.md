# Chrome Web Store listing — CoreX extension

**Status:** Prep only — no submission made as part of this build. Web Store owner account:
`corexaj2026@gmail.com` (Johan).

## Store description

**Short description (132 char max):**
> Pull property listings from Property24 and Private Property straight into CoreX OS — your
> agency's real estate operating system.

**Full description:**
> CoreX is the companion extension for CoreX OS, the operating system real estate agencies use to
> run their business. While browsing a listing or search-results page on Property24 or Private
> Property in your own logged-in browser, CoreX lets you:
>
> - **Pull a listing into CoreX** as your agency's own property — reference, price, address,
>   features, sizes and every photo, imported in one click.
> - **Capture search results** for prospecting — build market intelligence on stock you don't yet
>   hold.
> - **Import another agency's listing as Other Agency Stock** — share it with your buyers in
>   viewing packs and match searches, with the source agency's full permission, never re-advertised
>   or syndicated by your agency.
> - **Capture deeds-office and identity lookups** from CMA Info and The Virtual Agent for
>   compliance record-keeping.
>
> CoreX requires a CoreX OS account — it is not a standalone tool. Sign in (or paste your API
> token) once in the extension's Settings screen.

## Single-purpose statement

> This extension's single purpose is to let a CoreX OS user bring property-listing and contact
> data they are already viewing on a small, explicit list of supported websites (Property24,
> Private Property, CMA Info, The Virtual Agent) into their own CoreX OS account, on their own
> explicit action. It performs no other function.

## Permission justifications

| Permission | Why it's needed |
|---|---|
| `activeTab` | Read the page the user is currently viewing, only when they invoke the extension — never a background scrape. |
| `storage` | Store the user's CoreX API URL/token and small capture-queue state locally on their device. |
| `unlimitedStorage` | A capture batch (many listings + queued offline retries) can exceed the default quota. |
| `notifications` | Tell the user when a pull/capture/import finishes or fails — no other use. |
| `alarms` | Periodically retry a queued capture that failed to reach CoreX (e.g. offline), and periodically drain the offline queue — no other use. |
| `scripting` | Inject the small, targeted extraction functions used by "Pull Property" and "Import as Other Agency Stock" into the active tab — including, for Private Property specifically, reading the page's own already-parsed `window.serverVariables` object in the page's MAIN execution world (an ISOLATED-world content script cannot see it). Never used to inject anything other than these two read-only extraction functions, and never on a page the user hasn't explicitly acted on. |

## Host permission justifications

| Host | Why |
|---|---|
| `https://www.property24.com/*` | Read Property24 search and listing pages the user is viewing, to extract listing data on request. |
| `https://www.privateproperty.co.za/*` | Same, for Private Property. |
| `https://www.cmainfo.co.za/*` | Read a CMA Info deeds-search result page the user is viewing, for FICA/compliance capture. |
| `https://app.thevirtualagent.co.za/*` | Read a Virtual Agent person/company lookup page the user is viewing, for compliance/prospecting capture. |

No host permission is broader than the specific site it serves — no `<all_urls>`, no wildcard
TLD. (The stale, un-packaged duplicate extension at `chrome-extension/portal-capture/` — NOT the
one this listing describes — does request `cookies` + `<all_urls>`; flagged separately, not part
of this submission. See `scripts/package-chrome-extension.sh`.)

## Privacy policy URL

`https://www.corexos.co.za/extension/privacy` (`public.extension-privacy` route,
`App\Http\Controllers\Public\LegalController::extensionPrivacy()`).

## Screenshots list (to be captured before actual submission — not produced by this build)

1. Popup — action-card chooser on a Property24 listing page (Pull Property / Capture Listings /
   Import as Other Agency Stock).
2. Popup — "Pull Property" preview (thumbnail, price, address, feature pills) before import.
3. Popup — "Import as Other Agency Stock" preview, showing the required consent checkbox.
4. Popup — Settings screen (API URL + token fields).
5. CoreX OS — the imported property's show page, with the "Locked — imported from Property24"
   line and portal link visible.

## Version

Bumped to **3.7.0** in this build (from 3.6.5) — the `scripting` permission addition and the new
Other Agency Stock action are a real capability change, not a patch.
