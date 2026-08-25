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
| `app/Http/Controllers/SellerLinkController.php` — private helpers | `buildBuyerDemand()`, `buildSellerSafeFeedback()`, `buildPriceChangeEvents()`, `buildPriceChangeNarrative()`, `buildBestComparison()`, `buildPortalsLive()`, `showUnavailable()` |
| `app/Http/Controllers/SellerLinkController.php` — `LABELS` (private const) | **The one place every sold/under-offer customer-facing word lives** — see §7 below before touching either |
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

  **DECISION (Johan, 2026-08-25) — SHIPS FOR EVERYTHING, no gating.** 85 of 117 active listings on QA1 carried a distorted `listed_date` (off by up to 168 days, all Property24-onboarded stock) — Johan considered hiding the figure on onboarded stock until fixed, and ruled against it: he approved a one-time backfill instead, correcting `listed_date` from the portal history already on file. **cc4 ran that backfill on QA1 on 2026-08-25.** Do not build an "onboarded stock" gate — it was explicitly rejected as unnecessary once the backfill lands. **The numbers on any already-tested demo page will change under this backfill** — do not re-verify days-on-market (or anything computed from `listed_date`: this section's own figure, and the `days`/`days_to_offer`/`days_to_sell`-style figures inside sections 6/7's comparables) until cc4 confirms the backfill is complete, then re-check and report anything that moved that shouldn't have.
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

### 6. Your competition right now

**DECISION (Johan, 2026-08-25 — RULING on the identifiability flag raised when this section first shipped, supersedes the original build):** LESS identifiable than first built — verbatim: *"No address at all. Not street, not complex, not unit."* His reasoning, verbatim: *"a seller needs to see the shape of their competition, not a directory of rival listings on their own agent's page."*

A competitor renders as **characteristics only** — property type, beds, baths, asking price, days on market, and the SUBJECT's own suburb (already visible elsewhere on the page, not anything read off the comparable's own record): *"A 2-bed, 2-bath apartment in your suburb, asking R1,200,000, 45 days on market."*

`PropertyIntelligenceService::getActiveComparables()` reuses `CompetitorStockMatchService::findComparableStock()` (the SAME vetted, on-market, family/band-scored engine the CMA presentation flow already trusts) but **does not fetch `id` or `location` at all any more** — not merely hides them in the view, there is nothing left to leak. Checked per Johan's explicit instruction to check "the HTML and any JSON payload, not just the rendered output": this data is never `@json()`-dumped anywhere on the page (unlike the engagement chart's `data-engagement-series`), only rendered as plain sentence text.

**Sourcing caveat — disclosed, not hidden (unchanged):** `findComparableStock()` is scoped to the SUBJECT property's own `agency_id` — the agency's own other active stock nearby, **not verified cross-agency market data**. QA1 only has one seeded agency, so cross-agency behaviour cannot be tested either way on this environment.

**Whole section absent below 2 genuine comparables** — "one lonely comparable is not a market."

### 7. What has actually sold near you / What has recently gone under offer near you

**⚠ SOLD-VS-UNDER-OFFER RULE — READ BEFORE TOUCHING THIS SECTION. Two rulings, in sequence, on the same day — the SECOND one is current. Do not implement the first.**

**Background (still true):** this page originally sourced "sold" comparables from `property_sold_records`. That table's `sold_price` is confirmed **not an achieved sale price** — `SuburbReportDataService`'s own docblock (2026-08-24) records that every row's `sold_price` mirrors `listing_price_at_sale`, the property's own last advertised price copied into itself. **`property_sold_records` must never be used for a "sold" claim on this page — full stop.**

**Ruling 1 (superseded, do not build):** registered-only counts as sold; anything else with an offer is under offer.

**Ruling 2 — CURRENT (Johan, 2026-08-25, verbatim): "pending = under offer, granted and registered = sold."** A GRANTED deal counts as sold even before it has a registration date. This is his call, not an inference from the audit — cc4 is separately checking whether a granted deal can still collapse/fall through; if it can, this wording may change a third time, which is exactly why it lives in one place (`SellerLinkController::LABELS`) and nowhere else.

**Real classification** (verified against real QA1 data — these are the ONLY distinct values either status column holds):
```
deals_v2.status:        'granted' | 'active' | 'declined'
deals.accepted_status:  'G' | 'P' | 'R' | 'D' | '' (blank)

SOLD        = status='granted' OR actual_registration IS NOT NULL     (Dr2 / deals_v2)
            = accepted_status IN ('G','R') OR registration_date IS NOT NULL   (Dr1 / legacy deals)
UNDER OFFER = status='active' AND actual_registration IS NULL         (Dr2)
            = accepted_status='P' AND registration_date IS NULL       (Dr1)
EXCLUDED    = status='declined' (Dr2) / accepted_status='D' (Dr1) / blank accepted_status
              with no registration_date (Dr1) — unclassifiable, never guessed into either bucket
```

`PropertyIntelligenceService::getSoldComparables()` and `getUnderOfferComparables()` each **UNION real rows from BOTH deal tables** — a "sold"/"under offer" claim isn't tied to which system happened to record the deal — **deduplicated** so a deal migrated from the legacy `deals` table into `deals_v2` is never counted twice (excluded via `deals.id NOT IN (SELECT legacy_deal_id FROM deals_v2 WHERE legacy_deal_id IS NOT NULL)` on the legacy-side query). Verified this matters: on real QA1 data, every one of the 9 `deals_v2` rows with `status='granted'` is a mirror of a `deals` row with `accepted_status='G'` — a naive union without the dedupe would have shown each of those 9 real sales twice.

**Reference date per row** — not assumed, checked against real data: EVERY `deals_v2` `'granted'` row (11 of 11, QA1) has `actual_registration` NULL, so the date falls back to `offer_date`. EVERY legacy `'G'` row (43 of 43) has `deal_date` populated (`registration_date` only 3 of 43, `granted_at` only 30 of 43) — falls back `registration_date ?? granted_at ?? deal_date`, in that order of confidence. `days` = `listed_date → that date`.

Both sources require the deal's `property_id` to resolve to a real `Property` row — comparability (type/beds/baths) cannot be confirmed otherwise. Verified against a real case: the legacy `deals` table's own genuine Ramsgate registration (R770,000, `registration_date` 2026-03-30) has no `property_id` link, so it is correctly EXCLUDED even though the sale itself is real.

**Every customer-facing word for this distinction lives in `SellerLinkController::LABELS`** (`sold_heading`, `sold_subtitle`, `sold_verb`, `sold_days_suffix`, `under_offer_heading`, `under_offer_subtitle`, `under_offer_verb`, `under_offer_days_suffix`) and ONLY there — a rename is a one-line edit to that array, never a hunt through the view. `sold_days_suffix`/`under_offer_days_suffix` exist separately from the main verbs because "N days to sold" is broken English — found and fixed while proving this on real data; the per-row caption needs its own grammatical form ("N days to sale" / "N days to offer"), not a direct reuse of the sentence verb. The view templates both main sentences through one shared closure (`$buildComparisonSentence`) parameterized by verb, so the two branches can never structurally drift apart.

**Data-quality guard (unchanged):** `sanePropertyCount()` bounds beds/baths to `0 < n ≤ 10` — a real seed-data row had `baths = 25` (an entry error), and this drops just that field, not the whole comparable.

**Property-type matching** uses `App\Services\TitleTypeClassifier::fromPropertyType()` (the same sectional/freehold classifier `CompetitorStockMatchService` already uses to family-gate active comparables) — applied inside both query methods before a comparable ever reaches `buildBestComparison()`.

**Verified against cc4's exact demo figures, independently, against real QA1 data, end to end through the real controller and view (not by construction):**
- `getUnderOfferComparables()` on a real Ramsgate subject returns `House, 4 bed, 2 bath, R1,650,000, days=13` (`listed_date` 2026-07-15 → `offer_date` 2026-07-28) → "...a comparable 4 bed 2 bath nearby went under offer in 13 days at R1,650,000." exactly.
- `getSoldComparables()` on a real Margate subject (property 6079) returns a granted Margate apartment comparable → "...a comparable 2 bed 2 bath nearby sold in 49 days at R1,075,000." — confirms the granted-counts-as-sold branch renders correctly end to end.

**Whole (sub)section absent** when its own collection is empty — the two halves collapse fully independently of each other.

**Consumes the same underlying data model `SuburbReportDataService` uses, not its live output directly:** cc4 landed a first fix to that service on 2026-08-25 (`achievedSalesFromDr2()` → `salesActivityForSuburb()`, registered-vs-under-offer split at suburb-aggregate granularity) and is now re-running it against Johan's Ruling 2 above. This page's two `PropertyIntelligenceService` methods implement Ruling 2 independently, at per-property comparable granularity — when `SuburbReportDataService` catches up to the same ruling, the two should describe the same underlying reality the same way; if they ever disagree, that's a bug worth chasing, not two valid opinions.

**⚠ RE-VERIFICATION HOLD:** do not re-check the day-counts in this section until cc4 confirms the `listed_date` backfill (Decision 2, §2 above) is complete — every `days`/`days_to_offer`/`days_to_sell`-style figure here depends on `listed_date` and will shift when it lands.

### 8. Agent card + footer
Unchanged shared partials.

---

## Known Limitations / Open Items

- **Section 6's cross-agency question** — see caveat above. If genuine cross-agency "competition" is wanted, that needs a different data source (`tracked_properties`, unverified on QA1 due to single-tenant seed data) and is a bigger, separate decision — not something this build should decide unilaterally.
- **`complex_name` free-text unit leakage** — see flag above.
- **`getFeedbackRollup()`/`getRecentViewings()` cross-property leakage risk** — the eventIds OR-join those two (pre-existing, unmodified) methods use was found to pull a sibling property's own feedback row into this property's numbers, for calendar events linked to more than one candidate property. `getFeedbackThemes()` (new, this build) avoids it by reading `property_id` only. The older methods were NOT changed (out of scope) — worth a look on their own.
- **The 30D chart default can look empty on an older property** even when the price-change narrative below it references real, older activity — a real consequence of the current-date-relative default toggle plus the system-wide portal-sync staleness already flagged separately (see Staging session notes) meeting a property whose real activity happened weeks/months ago. Not fixed here; the 30D/90D/6M toggle itself was explicitly kept unchanged.
- **Environment:** this spec describes QA1's current state as of 2026-08-25. Confirm before assuming Staging or live match it — see the session's branch/environment report for what has and hasn't travelled.
