# Spec: Seller Live Link

**Status:** Live on QA1 (2026-08-25) — awaiting Johan's test pass before travelling to Staging/live.

---

## What This Page Does And Why

The Seller Live Link (`property/live/{token}`) is a public, token-gated, no-login page an agent sends a seller so they can check on their own listing at any time — typically from a WhatsApp link, on a phone.

Johan's own framing, verbatim, is the design brief for every section on this page:

> "a page that should prove to a seller that we are working, and the data we provide them should show this."

It is not a marketing presentation and not a CMA. It is a "what's actually happening with my property" screen, built for a specific reader: a seller two months into a listing, wondering whether their agent is doing anything. Every section on the page exists to answer that question with a real, re-derivable number — never a chart the seller has to interpret alone, never a placeholder, never a "typical" figure.

**Pillars:** Property (the listing itself), Contact (the seller, read-only — never exposed to buyer-identity data), Agent (the point of contact shown on the page). Deal is not touched — this page is pre-transaction.

---

## Non-Negotiable Rules (governing every section, not just some)

1. **Nothing renders unless real data sits behind it.** No placeholder, no sample, no "typical" number.
2. **Every section collapses cleanly and completely when its data is absent.** A half-empty section (a heading with nothing under it, or a stat next to a dash) is worse than no section — remove the whole thing, heading included.
3. **Seller-visible feedback only.** `calendar_event_feedback.visibility = public_to_seller`, via `PropertyIntelligenceService::getFeedbackRollup()`/`getRecentViewings(excludeInternalOnly: true)`. Never `internal_only`. Do not reimplement this filter — call the existing methods.
4. **No buyer identity, ever.** Counts, tiers, and price bands only — never a name, contact detail, or anything that could identify a specific buyer.
5. **No other agency named, no other seller identifiable.**
6. **Renders fully with the Tailwind CDN blocked at the network level.** This page loads `@vite(['resources/css/app.css', 'resources/js/app.js'])` — no third-party script in the critical path. Proven with Puppeteer request-interception, not assumed.
7. **Mobile first.** A seller opens this from a WhatsApp link on a phone — verify at a phone viewport, not just desktop.
8. **Every number must be independently re-derivable.** Document the exact formula for anything computed (not just looked up) — see each section below.

---

## Files

| File | Role |
|---|---|
| `routes/web.php` (`property/live/{token}` → `seller-link.show`) | Route, throttled 30/min |
| `app/Http/Controllers/SellerLinkController.php` — `show()` | Resolves the token, assembles every data array, calls `view('seller-link.live', ...)` |
| `app/Http/Controllers/SellerLinkController.php` — private helpers | `buildBuyerDemand()`, `buildSellerSafeFeedback()`, `buildPriceChangeEvents()`, `buildPriceChangeNarrative()`, `buildSoldComparisonSentence()`, `buildPortalsLive()`, `showUnavailable()` |
| `app/Services/PropertyIntelligenceService.php` | Data layer — see per-section sourcing below |
| `resources/views/seller-link/live.blade.php` | The page |
| `resources/views/public/shared/_agent-card.blade.php`, `_company-footer.blade.php` | Shared partials, reused unmodified |
| `resources/js/nexus-charts.js` — `NexusCharts.portalEngagement()` | Shared chart renderer — same function the internal Intelligence tab calls, with a seller-framing `opts` param and a price-marker plugin, both additive/backward-compatible |
| `app/Services/PublicLinks/PublicLinkUnavailableResponder.php` + `resources/views/public/shared/_link-unavailable.blade.php` | The revoked/deleted/sold off-ramp (not the live page, but the same link family) |

---

## Page Order And Sourcing

### 1. Hero
Property title, "Hi {first name}, here's what's happening", last-refreshed timestamp, agent chip. Unchanged from the prior build. Always renders.

### Property photo + facts (between Hero and section 2, unchanged)
Hero photo — absent entirely (not a placeholder) when `PropertyThumbnailService`-gated `thumbFor()` returns null. Price, suburb, beds/baths/garages/erf — always shown, never gated on the photo.

### 2. Where your property stands
- **Asking price** — `$property->formattedPrice()`. Always shown.
- **Days on market** — `HumanDiff::daysBetween($property->listed_date)`. **Only rendered when `listed_date` is a real value** — no fallback to `p24_activated_at`/`published_at`/`created_at`. This is deliberately stricter than `PropertyIntelligenceService::getComplianceStatus()`'s own `days_on_market` (which does fall back) — that field stays as-is for its other consumers (the internal property page, comparable-listings days-on-market); this page computes its own, narrower figure so it never shows a number built on a proxy date.
- **Listing status** — `isLiveOnAnyPortal()` (fixed 2026-08-25 — was `(bool) $property->published_at`, which tracks whether a listing was EVER published, not whether it's live now).
- **Mandate status + expiry date in words** — `$compliance['mandate_expired']` for the badge, `$compliance['mandate_expiry']` formatted as "Your mandate runs until {date}." / "Your mandate expired on {date}." A bare "Mandate: Active" badge told a seller nothing on its own.

Always renders (asking price + badges always exist).

### 3. What we have done
Real counts, then one narrative sentence built from those SAME numbers:
- **Buyers matched** (+ strong/good tier breakdown) — `PropertyIntelligenceService::getBuyerInterestSignals()`, the same canonical matching engine the internal Core Matches tab uses. Counts only, no buyer identity.
- **Viewings held** — `getFeedbackRollup()['total_viewings']` (distinct calendar events, seller-visible only).
- **Enquiries received** — sum of `leads` + `pp_leads` across the FULL `getPortalEngagementSeries()` result (lifetime/backfill total, not a rolling 30-day window — this is a "what have we achieved" figure, not a recency figure).
- **Portals live** — `Property::portalLinks()` filtered to `status === 'live'`, labels only (e.g. "Property24", "Private Property", "Company Website").

The narrative sentence assembles only the parts that are non-zero (e.g. skips "0 enquiries received" entirely rather than saying it). **The whole section is absent when every count is genuinely zero** — a row of four zeroes is not "proof we're working."

### 4. Activity over time
The existing Views & Enquiries chart (`PropertyIntelligenceService::getPortalEngagementSeries()`, 180 days, same data the internal Intelligence tab's chart plots — one query, genuinely shared, not duplicated), **with price changes marked on it**:
- **Markers**: `SellerLinkController::buildPriceChangeEvents()` reads `property_audit_log` (`event_type = 'price_changed'`), structured `old_values`/`new_values` JSON (not the pre-formatted `human_summary` string) → `{date, old_price, new_price}`. Drawn via `NexusCharts.portalEngagement()`'s `opts.priceMarkers` — an inline Chart.js plugin (no new npm dependency), given `{index, price}` pairs recomputed client-side on every 30D/90D/6M range change (the marker's pixel position depends on which index in the CURRENTLY DISPLAYED window it falls at).
- **Narrative sentence** (`buildPriceChangeNarrative()`): for the MOST RECENT price change only — "Price {reduced/increased} to R{X} on {date} — daily views went from an average of {A} the week before to {B} the week after." **Both sides are 7-day averages** (before: the 7 days strictly preceding the change date; after: up to 7 days starting on the change date, fewer if the change is too recent) — deliberately NOT a single "the day after" spot figure, which would be a defensible-sounding number that is actually a cherry-pick. An average of the same window length on both sides is the same claim any second reader can re-derive identically.
- Always reports whichever price change is MOST RECENT, whatever the outcome — verified on real QA1 data to sometimes show a DECLINE in views after a reduction, not always the flattering increase. Do not special-case toward the more dramatic result.
- No markers, no sentence, when there's no price change. **Whole section absent** when there's no engagement data on either portal (unchanged rule).

### 5. What buyers said
- **Themes line** (`PropertyIntelligenceService::getFeedbackThemes()`): "{N} of {M} viewers mentioned {label}", top 1–2 themes, from the SAME structured `concern_option_ids` field (`AgencyFeedbackOption`, `category = 'concern'` — a controlled vocabulary: Price, Location, Condition, Size, Layout, Damp/maintenance, School zone, Parking, Garden/outdoor) `getFeedbackRollup()`'s own `top_concerns` already counts — never free-text keyword-matching, never AI-inferred. **Deliberately `property_id`-only, NOT the `calendar_event_links` OR-join `getFeedbackRollup()`/`getRecentViewings()` use** — a single viewing calendar event can be linked to more than one candidate property (a buyer shown two homes in one appointment), and that join was verified on real QA1 data to pull the OTHER property's own feedback row into this property's count (event 5739 → properties 16 and 17, each with its own distinct feedback row). A theme claim must not mix two properties' feedback. **The same leakage risk exists in `getFeedbackRollup()`/`getRecentViewings()` — flagged, not fixed, out of scope for this build.**
- "M viewers" = distinct viewings (`calendar_event_id`), matching `total_viewings`'s own definition — not raw feedback rows, so two co-buyers' feedback for one viewing count once, and a concern raised twice in one viewing counts once.
- **Written notes list** — unchanged from the prior build: `buildSellerSafeFeedback()`, seller-visible notes only, up to 5, most recent first.
- Confirmed on real data (property 5747, QA1): 8 feedback rows, only 2 carry written text — the themes line and the notes list correctly read different subsets of the same underlying rows.
- **Whole section absent** when there have been zero viewings. "No feedback yet" (not absent) when there have been viewings but no written notes and no themes.

### (What's your agent doing — unchanged, kept where it was)
Agent-authored, seller-visible recommendations (`property_recommendations`, `seller_visible = true`). Absent unless the agent has actually written one.

### 6. Similar homes on the market near you
`PropertyIntelligenceService::getActiveComparables()` — reuses `CompetitorStockMatchService::findComparableStock()` (the SAME vetted, on-market, family/band-scored engine the CMA presentation flow and the page's own prior "Similar properties" section already trusted), extended with `location` (complex name or street name), `property_type`, `beds`, `baths`. **Deliberately excludes `unit_number` and any agency-identifying field.**

**Sourcing caveat — disclosed, not hidden:** `findComparableStock()` is scoped to the SUBJECT property's own `agency_id`. This is the agency's own other active stock nearby, **not verified cross-agency market data**. QA1 only has one seeded agency, so cross-agency behaviour cannot be tested either way on this environment. Worded in the view as "Similar homes on the market near you", deliberately not "your competition" or "competing agencies" — that framing is not what this data source supports.

**Open data-quality flag:** some `complex_name` values in the underlying data embed unit-level detail as free text (e.g. one real QA1 row: `complex_name = "Tonmawr Section 15 Door 12 LLE"`) — the correct field is being read, but its content is more identifying than the field name implies. Not fixed here (a display-layer regex strip would be a risky heuristic on free text); flagged for whoever owns data entry / cc4's inventory.

**Whole section absent below 2 genuine comparables** — "one lonely comparable is not a market."

### 7. What has actually sold near you
`PropertyIntelligenceService::getAchievedComparableSales()` — `property_sold_records` (the M9 canonical sold-records table, the SAME source `MarketDataSnapshotService::getComparableSales()`/`calculateAreaAverages()` already use), suburb-scoped, 12-month window. **This table is NOT agency-scoped in its query path (suburb only)** — genuinely broader-than-one-agency sold intelligence, unlike section 6.

**Data-completeness finding (real, verified on QA1):** `property_sold_records`' OWN `bedrooms`/`bathrooms`/`days_on_market` columns are unpopulated system-wide (0 of 225 rows). Every row DOES carry `property_id`, and every one of those 225 linked properties DOES have `beds`/`baths` and a `listed_date` — so this method joins through to the linked `properties` row for real beds/baths, and computes days-to-sell as `HumanDiff::daysBetween(listed_date, sold_date)`, instead of shipping blanks or a fictional figure.

**Data-quality guard:** a real seed-data row had `baths = 25` (an entry error, not a mansion) — `sanePropertyCount()` bounds beds/baths to `0 < n ≤ 10`, dropping just that field (not the whole comparable — it's still a real match on type/price/location) when the value is outside a residentially-plausible range.

**Comparison sentence** (`buildSoldComparisonSentence()`): "Your {beds} bed {baths} bath {type} has been on the market {N} days; a comparable {beds} bed {baths} bath {type} nearby sold in {M} days at R{price}." Only produced when the subject has a real days-on-market figure (section 2's own gate) and a same-family sold comparable exists. **Property-type matching uses `App\Services\TitleTypeClassifier::fromPropertyType()`** (the same sectional/freehold classifier `CompetitorStockMatchService` already uses to family-gate active comparables) — a raw string match (`"Apartment"` vs `"Apartment / Flat"`) was verified to silently drop every real comparable on the first test property, because the two tables use different property-type vocabularies.

**Whole section absent** when there are no achieved sales in the window.

### 8. Agent card + footer
Unchanged shared partials.

---

## Known Limitations / Open Items

- **Section 6's cross-agency question** — see caveat above. If genuine cross-agency "competition" is wanted, that needs a different data source (`tracked_properties`, unverified on QA1 due to single-tenant seed data) and is a bigger, separate decision — not something this build should decide unilaterally.
- **`complex_name` free-text unit leakage** — see flag above.
- **`getFeedbackRollup()`/`getRecentViewings()` cross-property leakage risk** — the eventIds OR-join those two (pre-existing, unmodified) methods use was found to pull a sibling property's own feedback row into this property's numbers, for calendar events linked to more than one candidate property. `getFeedbackThemes()` (new, this build) avoids it by reading `property_id` only. The older methods were NOT changed (out of scope) — worth a look on their own.
- **The 30D chart default can look empty on an older property** even when the price-change narrative below it references real, older activity — a real consequence of the current-date-relative default toggle plus the system-wide portal-sync staleness already flagged separately (see Staging session notes) meeting a property whose real activity happened weeks/months ago. Not fixed here; the 30D/90D/6M toggle itself was explicitly kept unchanged.
- **Environment:** this spec describes QA1's current state as of 2026-08-25. Confirm before assuming Staging or live match it — see the session's branch/environment report for what has and hasn't travelled.
