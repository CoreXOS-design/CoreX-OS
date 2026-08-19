# MIC — Sold / Off-market + Ref lifecycle tracking

Status: BUILT on QA1 (cc2). Branch `cc2-mic-sold-offmarket` → origin/QA1.

## Business requirement (Johan)
Track each portal listing by its P24 reference across its whole lifecycle, so:
1. We know each listing's **portal status** (active / under-offer / sold / withdrawn) as reported by P24 at scrape time.
2. A listing that goes **off-market** (portal shows sold / under-offer / withdrawn, OR it stops being re-sighted) drops out of the active canvass pool — it must not sit at the top of MIC forever with a stale buyer-match score.
3. Rows are **kept** (never hard-deleted) so CoreX accumulates lifecycle intelligence keyed on the ref: what sold, and days-on-market.

## Pillars
Property (Tracked Property universe via existing `linkToTrackedProperty`). Reads/writes `prospecting_listings`.

## Data model — `prospecting_listings` (additive migration)
| Column | Type | Meaning |
|--------|------|---------|
| `portal_status` | string(20) nullable, indexed | Last portal-reported status: `active`,`under_offer`,`sold`,`withdrawn`. NULL = never observed (legacy / old extension). |
| `portal_status_changed_at` | timestamp nullable | When `portal_status` last changed. |
| `off_market_at` | timestamp nullable | When the listing left the active pool. days-on-market = `off_market_at − first_seen_at`. |

Off-market status set = `['under_offer','sold','withdrawn']` (`ProspectingListing::OFF_MARKET_STATUSES`).

## Capture (extension → import)
- **Extension** (`content-p24-detail.js`, the ACTIVE served script): `extractListing()` now reads a lifecycle status off each search card's ribbon/badge text (`extractP24Status`) and adds `portal_status` to the listing object. Default `active` (a normally-listed card has no sold/under-offer ribbon). Flows through `background.js` unchanged (it forwards whole listing objects). **Takes effect only on the NEXT capture after the agent re-downloads/reloads the extension.**
- **Import** (`ProspectingApiController::import`): accepts `listings.*.portal_status`. On create/update it records `portal_status` (+ `portal_status_changed_at` on change). When the reported status is off-market it sets `is_active=false` + stamps `off_market_at` and **purges that listing's cached `prospecting_buyer_matches`**; when active again it revives (`is_active=true`, clears `off_market_at`). No status sent (old extension) ⇒ unchanged legacy behaviour (`is_active=true`).

## Off-market sweep (extended, not duplicated)
`prospecting:flag-stale-listings` (`FlagStaleProspectingListings`) now flags on TWO signals, merged into one update + the SAME buyer-match cache purge it already did:
- **A — absence**: not re-confirmed within `--days` (existing behaviour).
- **B — explicit portal status**: `is_active=true` but `portal_status ∈ OFF_MARKET_STATUSES` (belt-and-braces for rows the import didn't flip, e.g. captured before this shipped).
Both signals: `is_active=false`, stamp `off_market_at` (once), purge cached buyer matches. Absence rows with no explicit status yet are labelled `withdrawn` (delisted, cause unknown) — never mislabelled `sold`.

## Acceptance
- A listing whose captured card shows sold/under-offer → import sets `is_active=false` → drops from the active pool (pool filters on `is_active`).
- Re-capture showing it active again → `is_active=true`, `off_market_at` cleared.
- `prospecting:flag-stale-listings` flips lingering off-market-status rows inactive and purges their buyer-match cache.
- Rows are never deleted; `off_market_at` + `first_seen_at` give days-on-market; `portal_status='sold'` gives "what sold".

## Files
- `database/migrations/2026_08_21_000010_add_portal_status_to_prospecting_listings.php`
- `app/Models/ProspectingListing.php` (additive: fillable, casts, constants, helpers, scope)
- `app/Http/Controllers/Api/ProspectingApiController.php` (import: status capture + off-market flip + cache purge)
- `public/chrome-extension/portal-capture/content-p24-detail.js` (+ `manifest.json` version bump)
- `app/Console/Commands/Prospecting/FlagStaleProspectingListings.php` (extended)

## Deliberately NOT touched
- `MarketIntelligenceController` counts (cc6 owns).
- Pool/list query services (they already gate on `is_active`; no change needed).
