# Build F — Market Intelligence Redesign

**Spec file:** `.ai/specs/build-f-market-intelligence-redesign-spec.md`
**Version:** v1
**Status:** APPROVED — ready to build
**Absorbs:** former Build E.2 (chip rendering, flag-to-BM, TP chip, 48h fix), former Build E.4 (thresholds settings tab)
**Series:** the pre-Wednesday redesign of the prospecting page into a world-class market intelligence workspace

---

## 1. Purpose

The current `/prospecting` page is structurally wrong for what the data and the agent's workflow actually need.

- It tries to be a dashboard AND a work tool. Both fail.
- Three viewport-heights of KPI tiles, panels, and filters before the first listing appears.
- Each row eats ~600px of vertical space with stacked state chips.
- Buyer-side intelligence and seller-side listings are stacked, not synthesised.
- "Prospecting" describes what the agent does, not what the page is.

This build rebuilds the page from scratch as **Market Intelligence**, a dedicated workspace for the universe of properties not on our books — the canvassing pool. Same data, radically reshaped to give the agent answers and direct paths to action.

---

## 2. Pillar separation (architectural)

Three sidebar pillars, three nouns, three clearly separated concerns:

| Pillar | What it is | Data scope |
|---|---|---|
| **Market Intelligence** (renamed from Prospecting) | The canvassing universe and the analytical lens over it | `prospecting_listings` where `matched_property_id IS NULL` (not in our stock) |
| **Tracked Properties** | The full audit-graded universe across all sources | `tracked_properties` (everything we have intelligence on) |
| **Properties** | Our agency stock | `properties` (mandates won) |

Once a property is promoted to agency stock — Madeira's case — it disappears from Market Intelligence and lives in `/properties` from then on. The prospecting screen does not show "our" stock.

---

## 3. Two modes, one page

Same URL `/corex/market-intelligence`. Mode toggle in the top bar.

| Mode | Purpose | Default |
|---|---|---|
| **Work** | Filter the universe, act on listings, pitch sellers, manage claims | Default landing |
| **Analyse** | Read the market — heat maps, opportunity pockets, Ellie brief, competitive landscape, velocity | Click to enter |

Modes share the same top stats strip and agency selector. Filters set in one mode persist to the other within the session.

---

## 4. Scope

In:
- Rename: routes, controllers, views, sidebar, breadcrumbs from `prospecting` → `market-intelligence`
- Redirects from old URLs for bookmark continuity (90 days, then retired)
- In-stock filter at the data layer (default-on, BM/admin can toggle off to audit)
- Work mode layout: top stats strip, filter rail, action list, detail slide-over
- Analyse mode layout: Ellie strategic brief, demand-supply matrix, opportunity pockets, market velocity, agency competitive landscape
- Build E suggested-action chip integration on every row
- Inline action icons per row: claim, call, WhatsApp, view buyers
- Bug fixes 5.1–5.5 from the Build E investigation report (absorbed)
- Flag-to-BM endpoint + modal (absorbed from old E.2)
- Suggested-action thresholds settings tab (absorbed from old E.4)
- Settings preview endpoint (live count of each chip rule's effect)

Out:
- Mobile responsive design — Build G post-Wednesday (desktop ships first)
- Full-screen Tracked Property detail integration on the slide-over — Build G (link is enough for now)
- Keyboard shortcuts (j/k/Enter/numbers) — Build G
- "What changed since last visit" banner — Build G
- The market-intelligence-screen-as-its-own-page idea — folded into Analyse mode, not a separate page
- Outbound call log endpoint and chip — deferred to old Build E.5
- Snooze / dismiss listing — deferred to old Build E.5
- Pitch recency for non-stock listings — deferred to old Build E.5
- Demand pocket auto-recompute job — Build H (we compute on-the-fly for now)

---

## 5. Mandatory pre-reads (every VS Code prompt for this build)

1. `CLAUDE.md`
2. `.ai/STANDARDS.md`
3. `.ai/specs/build-f-market-intelligence-redesign-spec.md` (this file)
4. `.ai/specs/build-e-suggested-action-chips-spec.md` (v2 — E.1 ships the resolver Build F consumes)
5. `.ai/specs/prospecting-intelligence-spec.md` (the legacy spec — references the segment service we partly reuse)
6. Build E investigation report (2026-05-14)
7. `app/Http/Controllers/ProspectingController.php`
8. `app/Services/Prospecting/ProspectingListingStateEnricher.php`
9. `app/Services/Prospecting/SuggestedActionResolver.php` (shipped in E.1)
10. `app/Services/Prospecting/BuyerMatchTierService.php`
11. `app/Services/Prospecting/ProspectingConfigurationService.php`
12. `app/Services/Prospecting/ProspectingIntelligenceService.php`
13. `resources/views/prospecting/index.blade.php` (the page being replaced)
14. The CoreX sidebar partial path (`resources/views/layouts/_sidebar.blade.php` or whatever the audit confirms)

---

## 6. The rename

| Concern | From | To |
|---|---|---|
| Route group prefix | `/prospecting` | `/corex/market-intelligence` |
| Route names | `prospecting.*` | `market-intelligence.*` |
| Controller | `App\Http\Controllers\ProspectingController` | `App\Http\Controllers\CoreX\MarketIntelligenceController` |
| View directory | `resources/views/prospecting/` | `resources/views/corex/market-intelligence/` |
| Sidebar label | "Prospecting" | "Market intelligence" |
| Breadcrumbs | "Prospecting" | "Market intelligence" |
| Permission names | (keep) `prospecting.manage`, `access_prospecting` | unchanged (audit cost too high; URL-only rename) |
| DB module-name strings (if any) | "prospecting" | unchanged (data integrity) |
| Filter param names | (keep all) | unchanged |
| Domain event class names | (keep) `Prospecting\Events\*` | unchanged |

Redirects: `/prospecting` and `/prospecting/*` → 301 to `/corex/market-intelligence/*` preserving query string. Live for at least 90 days.

---

## 7. In-stock filter

Default query on the index view:

```php
$listings = ProspectingListing::query()
    ->where('agency_id', $agencyId)
    ->where('is_active', true)
    ->whereNull('matched_property_id')   // NEW — exclude stock we already mandated
    ->where(/* normal filters */)
    ->paginate();
```

Admin toggle (audit mode):

A header switch visible only to users with `prospecting.manage`: `Show in-stock too`. When on, the `whereNull` is dropped. Purpose: BM/admin audit to find leaks (e.g. Madeira showing here after a botched promotion). Default off.

The toggle state is session-stored, not persisted to user prefs.

Sidebar counts on the Market Intelligence link reflect the *filtered* count — so HFC sees 980, not 983 (subtracting the 3 in stock).

---

## 8. Work mode layout

### 8.1 Top bar
- Agency name + scope label (left)
- Mode toggle: Work | Analyse (centre-right)
- Market intelligence button (links to Analyse mode if a separate link is needed — same as the toggle; redundant but discoverable)
- Setup button (links to settings — same level as buyer match tiers, suggested action thresholds, etc.)

### 8.2 Stats strip — two rows

**Row 1 (snapshot, informational):**
- Active listings — `count(is_active=true, matched_property_id NULL)`
- Buyer matched — `count(... AND buyer_match_count > 0)`
- In stock (clickable to flip the audit toggle) — `count(... AND matched_property_id NOT NULL)`
- New today — `count(... AND first_seen_at >= today)`
- Cross-listed — `count(... AND portal_count > 1)`

**Row 2 (action presets, clickable filters):**
- `Pitch now · high` — strong-tier ≥ `thresholds.high_value_strong_min`, no pitch in `thresholds.pitch_recency_days`, no claim. Click → filter list to these.
- `Pitch now` — strong-tier ≥ 1, no recent pitch, no claim, below high threshold.
- `Log outcomes` — pitched ≥ `thresholds.outcome_overdue_days` ago, no outcome logged, by current user.
- `My claims` — active claims by current user.
- `Expiring` — current user's claims, no feedback, `hours_left < thresholds.expiry_warning_hours`.

The currently-active preset highlights in info-blue.

### 8.3 Split pane

**Left rail (filters + intelligence-as-filters):**
- Search input (address, agent, agency, ref) — debounced 300ms
- Active filters (removable pills)
- By town — accordion, counts beside each, click to filter
- By type — same pattern
- By beds — same
- By price band — same
- Demand pockets section: top 3–5 opportunity pockets (e.g. "Margate · 3 bed · 13b/4l") rendered as clickable filter shortcuts

**Right pane (action list):**
- Header strip: result count + sort selector
- Listing rows, paginated 50/page, infinite-scroll on the desktop view

### 8.4 The row

Single row ~75px tall. Eight visible per typical desktop viewport.

| Zone | Content |
|---|---|
| Thumb (44×44) | Property photo if available, else type icon |
| Primary line | Address (truncate) + price (right-aligned) |
| Meta line (10px secondary) | Suburb · beds\|baths\|garages · type · agency · portal ref · first seen |
| State + demand row | Inline tags (pitched 12 May, you · 38h, unclaimed, claimed · NAME) + demand microbar (5 strong, 20 mid) |
| Action zone (right) | Suggested-action chip from `SuggestedActionResolver` (top) + 3 inline icon buttons (bottom) |

Inline icon buttons by row state:

| Row state | Icons shown |
|---|---|
| Unclaimed | bookmark (claim), phone, whatsapp |
| Claimed by me | phone, whatsapp, users (buyers) |
| Claimed by other | eye (view detail), users |
| Pitched recently | phone, whatsapp, users (no re-claim) |

Click anywhere on the row (except a button or icon) → opens **detail slide-over** from the right.

### 8.5 Detail slide-over

Triggered by row click. Slides in from right, 40% viewport width, dismissable via X or click-outside.

Header:
- Larger thumb (96×96)
- Address (full), suburb, price, beds|baths|garages, type
- Source badges: portal (P24, PP), TP source chain count
- Action bar: Pitch · Call · Log · Note · Claim/Release (state-aware)

Tabs:
- **Overview** (default) — Ellie's one-paragraph summary of this property + top 5 matched buyers with contact icons + market position (suburb median, this listing vs median, YoY) + latest activity
- **Buyers** — full list of matched buyers ranked by score, with phone/whatsapp/wishlist details, contact actions
- **Activity** — pitch history (all sends, outcomes), claim notes timeline, calls (when E.5 ships)
- **Market** — comparable sales (last 90 days, same suburb/beds), price trend, days-on-market
- **Source** — TP source chain (CMA, P24, PP, portal capture rows) — chronological audit trail

---

## 9. Analyse mode layout

Same top bar, same stats strip. Body changes to the analytical view.

### 9.1 Ellie strategic brief (hero)
- Generated once per day per agency (cached, TTL 6h)
- Service: `App\Services\Ellie\MarketStrategyBriefService`
- Input: aggregate counts from `ProspectingIntelligenceService` + buyer wishlist data + sales velocity data
- Output: 3–5 sentence narrative + 2–3 one-click action buttons
- Action buttons link back to Work mode pre-filtered (e.g. "Canvass Margate 3-bed" → Work mode, filter set)

### 9.2 Demand-supply matrix
- Suburb (rows) × bedrooms (columns)
- Cell value: `active_buyers / active_listings` (ratio)
- Cell colour: ramp from grey (≤0.5) → amber (0.5–1.5) → teal (>1.5)
- Click cell → Work mode filtered to that segment
- Max 10 suburbs visible (top by activity); "+ N more" link expands

### 9.3 Opportunity pockets
- List view of top pockets where `buyers ≥ 2× listings AND buyers ≥ 3`
- Each row: pocket label + ratio badge + raw counts
- Click → Work mode filtered

### 9.4 Market velocity
- Avg days-on-market by price band, last 90 days of sold properties
- Δ vs previous 90 days
- Source: `properties` table joined with `deals` (when sold) — agency-wide

### 9.5 Agency competitive landscape
- Per selected suburb (defaults to top-pocket suburb)
- Stacked bar: agency name + % of active listings + count
- HFC's own row highlighted
- Click an agency name → Work mode filtered to that agency

### 9.6 Buyer funnel (folded in from current page)
- New buyers entering by status over time (last 7d, 30d, 60d, 90d)
- Stays in Analyse mode; removed from Work mode

---

## 10. Suggested-action chip integration

Consume `$suggestedActions[$listing->id]` from the controller (E.1 wired this). Render via new partial `_suggested-action-chip.blade.php` matching the visual tiers from the Build E v2 spec §6.2.

Click behaviour exactly per `SuggestedAction` DTO: `clickType=anchor` → `<a href="$href">`, `clickType=alpine` → `@click="{$alpineCall}"`, `clickType=modal` → opens the named modal.

Tooltip rendering: shown on hover via title attribute (simple v1) — upgrade to floating UI tooltip in Build G.

---

## 11. Bug fixes absorbed

| # | Bug | Fix |
|---|---|---|
| 5.1 | `PITCH (STOCK)` shadows recent-pitch warning | Solved by the ranked resolver replacing the legacy CTA branch |
| 5.2 | Two 48h countdown definitions per row | Delete the inline `diffInHours` calc; consume `hours_left` from enricher |
| 5.3 | `tracked_property_id` invisible | Add `TP →` link chip in row state area + relation on model (E.1 added the fillable/cast already; F adds the UI) |
| 5.4 | `needsReminder`/`needsBmFlag` dead from view | E.1 surfaced them; F's resolver consumes them in R1/R4 |
| 5.5 | `loadPresentations` not agency-scoped | E.1 added the agency filter |

---

## 12. New endpoint — flag-to-BM

`POST /corex/market-intelligence/claims/{claim}/flag` → `MarketIntelligenceController@flagToManager`

- Requires `prospecting.manage` permission
- `reason` required, min 5 chars
- Sets `flagged_at=now()`, prepends timestamped note: `[2026-05-15 14:23] FLAGGED BM by NAME — REASON`
- Fires `ProspectingClaimFlagged` domain event (already defined in E.1)
- Redirect back to index with flash

UI: opens via the R1 `FLAG TO BM` chip click. Modal at bottom of the index page, Alpine-driven, with a textarea and confirm button.

---

## 13. Suggested-action thresholds settings tab

Lives at `/corex/settings/prospecting` (existing) as a 6th tab labelled "Suggested Actions".

Tab content:
- One form with 10 numeric inputs grouped by rule (R1–R9)
- Each input has inline help text explaining the threshold and which chip it controls
- Defaults pre-populated from `suggested_action_thresholds` row (E.1 created the table)
- Save button → `MarketIntelligenceController@updateSuggestedActions` → calls `ProspectingConfigurationService::updateSuggestedActionThresholds` (E.1 added the method)
- Right-side live preview: "With these settings, your agency would see" + chip-by-chip count breakdown, recomputed on input blur via AJAX
- Preview endpoint: `POST /corex/market-intelligence/suggested-actions/preview` (returns JSON `{r1: n, r2: n, ..., r9: n}`)

---

## 14. Files touched

### New
- `app/Http/Controllers/CoreX/MarketIntelligenceController.php` — rebuilt from `ProspectingController`, splits `index()` into `work()` + `analyse()`, adds `flagToManager()`, `updateSuggestedActions()`, `previewSuggestedActions()`, retains all existing query / filter logic
- `app/Services/Ellie/MarketStrategyBriefService.php` — generates the Analyse hero narrative + action buttons
- `app/Services/Prospecting/OpportunityPocketService.php` — computes demand-pocket lists for the filter rail and Analyse mode
- `app/Services/Prospecting/DemandSupplyMatrixService.php` — computes the suburb × beds matrix
- `app/Services/Prospecting/MarketVelocityService.php` — days-on-market by price band
- `app/Services/Prospecting/CompetitiveLandscapeService.php` — agency share by suburb
- `resources/views/corex/market-intelligence/work.blade.php` — Work mode page
- `resources/views/corex/market-intelligence/analyse.blade.php` — Analyse mode page
- `resources/views/corex/market-intelligence/_stats-strip.blade.php` — shared top stats
- `resources/views/corex/market-intelligence/_filter-rail.blade.php` — left filter rail
- `resources/views/corex/market-intelligence/_listing-row.blade.php` — the new row
- `resources/views/corex/market-intelligence/_suggested-action-chip.blade.php` — Build E chip
- `resources/views/corex/market-intelligence/_detail-slideover.blade.php` — slide-over panel
- `resources/views/corex/market-intelligence/_flag-modal.blade.php` — flag-to-BM modal
- `resources/views/corex/market-intelligence/_ellie-brief.blade.php` — Analyse hero
- `resources/views/corex/market-intelligence/_heat-matrix.blade.php` — demand-supply matrix
- `resources/views/corex/market-intelligence/_opportunity-pockets.blade.php` — pockets list
- `resources/views/corex/market-intelligence/_market-velocity.blade.php` — velocity strip
- `resources/views/corex/market-intelligence/_agency-share.blade.php` — competitive landscape
- `resources/views/settings/prospecting/_suggested-actions.blade.php` — thresholds tab
- `routes/web.php` — new route group + redirects from old

### Modified
- `resources/views/layouts/_sidebar.blade.php` — relabel link, change URL target
- `resources/views/settings/prospecting/index.blade.php` — register 6th tab
- `app/Http/Middleware/...` — none changed
- `app/Domain/Prospecting/Events/ProspectingClaimFlagged.php` — already exists from E.1
- `app/Services/Prospecting/SuggestedActionResolver.php` — no change (E.1 ships clean)
- `app/Services/Prospecting/ProspectingListingStateEnricher.php` — no change post E.1

### Deleted (after migration window)
- `app/Http/Controllers/ProspectingController.php` (replaced by `MarketIntelligenceController`)
- `resources/views/prospecting/index.blade.php` (replaced)

---

## 15. Performance budget

| Metric | Budget |
|---|---|
| Work mode page render (50 rows) | < 600ms p95 |
| Analyse mode page render | < 1.2s p95 (Ellie brief from 6h cache, services on-the-fly) |
| Filter rail interaction (click suburb) | < 250ms |
| Detail slide-over open | < 200ms (async fetch panel content) |
| Settings preview endpoint | < 200ms |
| Additional DB queries vs. E.1 baseline (Work mode) | +1 (in-stock filter — but actually `WHERE` clause, query count unchanged) |
| Additional DB queries (Analyse mode) | +5–7 (one per analytical service, all cacheable) |

All Analyse services support a 6h cache keyed on `agency_id`. Stats strip caches 1h.

---

## 16. Verification matrix

For each sub-build (F.1–F.6) the build prompt's final report must include its own slice. Cumulative checks:

1. Old `/prospecting` URL 301-redirects to `/corex/market-intelligence` preserving query string
2. Sidebar shows "Market intelligence" with the same icon, badge counts match
3. Mode toggle switches between Work and Analyse without losing filter state
4. Default Work view excludes in-stock listings; HFC sees 980 (= 983 - 3)
5. Admin "Show in-stock too" toggle brings the count to 983
6. Stats Row 1 numbers match the count queries listed in §8.2
7. Stats Row 2 action-preset numbers match the same conditions as the chip rules they preview
8. Click an action-preset tile → list filters; pill appears in active-filters
9. Filter rail "By town" expands; click "Uvongo Beach" → list filters; count cell highlights
10. Filter rail "Demand pocket" shortcut → list filters to that suburb+bed combo
11. Listing row renders all five zones (thumb, primary line, meta line, state row, action zone)
12. Build E chip renders correctly for every test row in §11.7–11.10 of the E spec
13. Click row → detail slide-over opens with property header + tabs + Overview content
14. Slide-over Buyers tab shows the top 5 with phone/whatsapp icons; clicking a phone icon initiates a tel link
15. Click `FLAG TO BM` chip → modal opens; submit → claim flagged_at populated; domain event recorded
16. Suggested-action thresholds tab loads; edit a threshold; preview counts update within 200ms; save persists
17. Analyse Ellie brief renders with real HFC narrative (Margate 3-bed example or similar real pocket)
18. Demand-supply matrix shows 10 suburbs × 5 bed columns with ratios
19. Click matrix cell → switches to Work mode pre-filtered
20. Opportunity pockets list shows current top 4+
21. Market velocity shows 4 price bands with day counts + deltas
22. Agency competitive landscape shows ≥ 4 agency rows
23. Buyer funnel folded into Analyse mode (gone from Work mode)
24. Bug 5.1 fixed: Madeira (if in-stock toggle on) shows R3 LOG OUTCOME, not Pitch (stock)
25. Bug 5.2 fixed: `grep -r "diffInHours" resources/views/corex/market-intelligence/` returns zero
26. Bug 5.3 fixed: every row has the TP-link chip
27. Performance: Work mode render < 600ms; Analyse < 1.2s
28. `php -l` on every changed PHP file
29. `php artisan view:clear`
30. `scripts/dev-check.ps1` passes with 0 new failures

Final line of each build report:
```
BUILD F.{n} COMPLETE — XX/YY VERIFICATIONS PASSED.
```

---

## 17. Build sequence

| Build | Scope | Verifications | Wednesday-critical? |
|---|---|---|---|
| **F.1** | Rename foundation: routes, controller, view path, sidebar, redirects, mode toggle scaffold, in-stock filter. Behaviour-preserving — old view renders inside the new route. | 1, 2, 3, 4, 5, 28, 29, 30 | Yes |
| **F.2** | Work mode top stats strip + filter rail + in-stock toggle wiring | 6, 7, 8, 9, 10, 28, 29, 30 | Yes |
| **F.3** | New listing row partial + suggested-action chip integration + inline action icons + bug fixes 5.1–5.3 | 11, 12, 24, 25, 26, 27, 28, 29, 30 | Yes |
| **F.4** | Detail slide-over panel — header, action bar, tabs (Overview, Buyers, Activity, Market, Source) | 13, 14, 28, 29, 30 | Yes |
| **F.5** | Flag-to-BM endpoint + modal + thresholds settings tab | 15, 16, 28, 29, 30 | Nice to have |
| **F.6** | Analyse mode — Ellie brief + demand-supply matrix + opportunity pockets + velocity + agency share + buyer funnel | 17, 18, 19, 20, 21, 22, 23, 28, 29, 30 | Yes (one big demo moment) |

Per-prompt: read this spec, investigate, report, then code. Each ends with `php -l`, `view:clear`, `dev-check.ps1`, Tinker verification, build report.

---

## 18. Demo script for Wednesday

1. **Open Market intelligence as Johan.** Top bar: clean. Stats strip: "983 active, 19 buyers, 7 pockets, ↑ Margate trending." Below: filter rail + 8 rows of properties. *"This is what your agents see Monday morning. No scrolling, no hunting."*
2. **Click action preset `Pitch now · high 665`** — list filters to high-conversion candidates. Madeira-style row at top. *"Six hundred and sixty-five conversations the system is telling them to start, ranked by likelihood."*
3. **Click `PITCH NOW · HIGH` chip on a row** — composer opens, pre-filled. *"One click from intel to action."*
4. **Click a row body** — detail slide-over slides in. Show Buyers tab. Click WhatsApp icon next to a buyer. *"Twenty-five buyers matched. The top 5 are one click from a contact."*
5. **Click `Analyse` mode toggle.** Ellie's brief appears: "Margate 3-bed is the clearest opportunity this week..." *"Every Monday morning, the AI tells your agency where the money is."*
6. **Click `Canvass Margate 3-bed` button in Ellie's brief** — switches to Work mode pre-filtered. *"The recommendation IS the workflow."*
7. **Back to Analyse. Click a green cell in the heat matrix (Uvongo 2bd)** — Work mode again, different filter. *"Every analytical insight is one click from being acted on."*
8. **Show settings → Suggested Actions tab.** Change `high_value_strong_min` from 3 → 5. Live preview shifts the count. *"Every agency tunes the system to their rhythm."*

---

## 19. Promotion path

HFC2402 (build) → Staging (verified) → main → live. Spec rides with the code. Sidebar copy translation deferred to copy review post-Wednesday.
