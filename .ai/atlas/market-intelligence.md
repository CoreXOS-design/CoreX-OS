# Atlas — Market Intelligence Centre (MIC) + buyer-property matching

> **Status: DONE** · Last verified: 2026-06-22
> Pillars: **Property** × **Contact** (matches properties/listings to buyer wishlists).
> Companion: `.ai/specs/build-f-market-intelligence-redesign-spec.md`. Cited audits/tickets: AT-71→AT-75
> (canonical scoring), AT-73 (engine on surfaces), AT-74 (presentations/staleness), AT-72 (buyer auto-land).

---

## 1. WHAT IT DOES

The MIC scores **buyers (wishlists) against properties and prospecting listings** to surface demand: which
buyers match a listing, how strongly, and how many countable buyers exist at/above a threshold. It powers
the Core Matches tab, the Buyer Pipeline auto-land, the buyer panels on prospecting listings, and the
buyer-demand panel on presentations. There are **two scoring engines** (A = canonical `MatchingService`,
B = legacy cached `PropertyMatchScoringService`); AT-75 partially unified them onto the canonical score.

---

## 2. ENTRY POINTS

### Routes (`routes/web.php`) — group `market-intelligence.*` at `:3247-3248` (prefix `corex/market-intelligence`)
| Route | Name | Handler | Notes |
|-------|------|---------|-------|
| `GET /` and `/work` `:3251-3252` | `market-intelligence.work` | `MarketIntelligenceController::work` | **the work landing page** |
| `/opportunities[/{id}]` `:3253-3256` | `.opportunities[.show]` | — | opportunity surfaces |
| `/analyse`, `/market-pulse` `:3257-3258` | `.analyse`, `.market-pulse` | — | analysis |
| `POST /analyse/regenerate-brief` `:3274-3277` | `.brief.regenerate` | `regenerateBrief` | **perm `mic.regenerate_brief`** (permission name ≠ route name) |
| `/...buyer-matches` `:3347` | `.buyer-matches` | `buyerMatches` `:2013-2029` | buyers for a listing |
| `/...details`, `.show`, `.claim/.feedback/.release` `:3351-3366` | — | — | per-listing |
| CMA report import sub-group `:3300-3335` | — | — | perm `mic.upload_reports` — see `cma-report-import.md` |

Other `mic.*` permissions: `mic.view_ai_costs`, `mic.edit_address`, `mic.merge_duplicates`,
`mic.restore_reports`. Legacy `/prospecting` is retired onto the same controller (`web.php:3376-3421`,
bare `/prospecting` 301-redirects).

Controller: `app/Http/Controllers/CoreX/MarketIntelligenceController.php` — `work()` `:57-62`,
`computeSnapshotKpis()` `:1760-1824`, %-band slider handling `:292-348`, `buyerMatches()` `:2013-2029`,
`details()` `:2035-2062`, `show()` `:2361+`. *(One controller; `ProspectingController` was deleted.)*

### Blade — the tile + slider live in `_stats-strip.blade.php`
`resources/views/corex/market-intelligence/work.blade.php` (main) +
`resources/views/corex/market-intelligence/_stats-strip.blade.php`:
- **Threshold-anchored "Buyer matched" tile** `:50-54` (def) / `:68-80` (render); label `… · {threshold}%+`
  `:72`; **`buyers_matched`** value `:74` (from `$kpis['buyers_matched']`); `properties_matched` `:77`.
- **%-range dual-handle slider** `:106-144`: two native ranges `mic-score-min`/`mic-score-max` `:110-111`;
  crossing logic `Math.min/Math.max` `:127`; navigates on change with `score_min`/`score_max` +
  `sort=match_score` `:131-133`. State read from request `:25-28`.

---

## 3. THE TWO ENGINES

### Engine A — `MatchingService` (canonical, real-% path) — `app/Services/Matching/MatchingService.php`
Constants: `MIN_SCORE_TO_SURFACE=40` (`:19`), `MIN_SCORE_TO_DISPLAY=50` (`:25`), `TIER_STRONG_MIN=80`
(`:28`), `TIER_GOOD_MIN=65` (`:29`), `TIER_FAIR_MIN=50` (`:30`); `tierFor()` → strong/good/fair `:50-56`.
- `matchesForProperty(Property)` `:106-127` (sets `match_score` via `score()` `:122`).
- `propertiesForMatch(ContactMatch, overrides)` `:146-263` (relaxed bands: price ±30% `:231`, count −1
  `:232`, size ±30% `:233`; drops below `MIN_SCORE_TO_DISPLAY` `:260`).
- **`score(Property, ContactMatch, float $priceBandPct=0.0)` `:342-411`** — only specified criteria enter
  the denominator:

| Criterion | Weight | Line |
|-----------|--------|------|
| must-have features (hard gate → 0 if missing) | — | `:344-347` |
| price (`priceFitRatio` with band) | **25** | `:351-353` |
| suburb (`p24_suburb_id`) | **20** | `:354-356` |
| beds / baths / garages | 8 / 7 / 5 | `:357-365` |
| category / property_type (neutral when property NULL — AT-75) | 5 / 5 | `:373-378` (`:366-372`) |
| floor size / erf size | 5 / 5 | `:379-384` |
| nice-to-have features (proportional) | **15** | `:385-392` |

Empty-components guard (AT-71): returns 100 only if countable else 0 (`:394-401`); final =
`earned/totalWeight*100` clamped (`:403-410`). Helpers: `priceFitRatio` with AT-75 `$bandPct` `:421-442`,
`suburbFitRatio` `:461-467`, negation tokens (no_pool/unfurnished/no_pets/no_X) `:477-517`.

Engine A consumers (real-% surfaces): `PropertyIntelligenceService.php:94-112` (Intelligence/Core Matches),
`PropertyController.php:242`, `ContactMatchController.php:41`, `MobileCoreMatchController.php`,
`ClientPortalController.php:32`, `MatchPropertyJob.php:24`.

### Engine B — `PropertyMatchScoringService` (legacy cached) — `app/Services/PropertyMatchScoringService.php`
`MIN_SCORE_TO_CACHE=50` (`:47`), `ACTIVE_BUYER_STATES=['new','warm']` (`:54`). Legacy weighted
`calculateScore()` `:72-153`: hard filters first (bedroom range `:89`, deal-breakers `:92`), then weights
price 25 (`:101`), area 20 (`:109`), type 10 (`:118`), must-haves 15 (`:126`), deal-breakers 10 (`:135`),
bedrooms 20 (`:141`). **Tier labels** `determineTier()` `:817-830`: `perfect`≥90, `strong`≥80 (AT-73
realigned from 70), `approximate`≥50, else `none`.

**AT-75 canonical path** `canonicalBestAcross()` `:604-636` calls Engine A's `score()` at `:625`
(`matcher()` resolves `MatchingService` `:638-643`).

### How they relate / are NOT unified
- Still two parallel engines (TODO at `PropertyMatchScoringService.php:5-6`, `ContactMatch.php:5`).
- **`prospecting_buyer_matches` WRITE path is canonical** (Engine A via `canonicalBestAcross`) — used by
  `recomputeProspectingMatches()` `:478` and `recomputeProspectingMatchesForBuyer()` `:546`; tiers still
  via Engine B `determineTier()` `:629`.
- **`property_buyer_matches` WRITE path is STILL legacy** (`calculateScore()` via `bestResultAcross()`
  `:410, 592-602`). So the two cache tables are scored by different engines.
- `getBuyerDemandForProperty()` migrated to canonical (`matchesForProperty`) for the seller panel `:215-227`.

---

## 4. DATA IT READS / WRITES

### Cache tables (`database/schema/mysql-schema.sql`)
- **`property_buyer_matches`** `:8178-8197` — `score smallint`, `tier varchar(20)`, `breakdown json`,
  `missing_features json`, `computed_at`; UNIQUE(`property_id`,`contact_id`) `:8189`.
- **`prospecting_buyer_matches`** `:8627-8655` — `score`, `tier enum('perfect','strong','approximate')`
  `:8633`, `matched_features json`, `missing_features json`, `matched_at`, `last_recompute_at`,
  `agent_notified_at`, `dismissed_at`; UNIQUE(`prospecting_listing_id`,`contact_id`) `:8644`.

### Writers
- `recomputeForBuyer()` → `property_buyer_matches` `:384-442` (chunked upsert `:434`).
- `recomputeProspectingMatches()` (per-listing) `:449-511`, `recomputeProspectingMatchesForBuyer()`
  (per-buyer) `:518-579` → `prospecting_buyer_matches` (chunked to dodge the 65,535-placeholder limit,
  AT-74 `:317-337`).
- Job `RegenerateBuyerMatchesJob` rebuilds both `:80-81`; sets `corex.matches.regenerating` cache flag
  `:58,107` (read by `isRegenerating()` `:63-66`).
- Dispatch triggers: `ContactMatchObserver::dispatchRecompute()` on wishlist create/update
  (`app/Observers/ContactMatchObserver.php:185-189`); `ProspectingListingObserver::recomputeAndNotify()`
  on listing change (`:30-45`; high-match notify ≥80 `:54-55`).

### Artisan + schedule (`routes/console.php`)
- `matches:recompute` (`RecomputePropertyMatches.php`) → `property_buyer_matches`; scheduled `04:30` `:162`.
- `prospecting:recompute-matches` (`RecomputeProspectingMatches.php`) → `prospecting_buyer_matches`;
  scheduled `04:00` `:165`.
- `wishlist:regenerate-matches` — full rebuild via the job.
- `buyers:autoland-pipeline` (`BuyersAutolandPipelineCommand.php:45-49`) — **NOT a recompute**; lands
  countable buyers onto the pipeline (`buyer_state='new'`, AT-72). **Manual only — not scheduled** (`:35`).

### Tile reconciliation (`computeSnapshotKpis()` `:1760-1824`)
`$threshold = AgencyContactSettings::forAgency()->micMatchThreshold()` `:1777`; **`buyers_matched`** =
DISTINCT `contact_id` in `prospecting_buyer_matches` with `score ≥ threshold`, not dismissed, in the active
canvass pool `:1778-1784` (only countable buyers are cached, AT-71, so distinct contact_id = distinct
countable buyer); `properties_matched` = DISTINCT `prospecting_listing_id` at threshold `:1785`. Slider
band re-filters per-listing counts in `work()` `:292-348`.

---

## 5. AGENCY SETTINGS / CONFIG

`app/Models/AgencyContactSettings.php`:
| Setting | Default | Reader | Migration |
|---------|---------|--------|-----------|
| `mic_match_threshold` | **75** (`DEFAULT_MIC_MATCH_THRESHOLD` `:50`) | `micMatchThreshold()` clamp 1-100 `:93-97`; tile `MarketIntelligenceController.php:1777`, band default `:295` | `2026_06_21_062000_add_mic_match_settings_to_agency_contact_settings.php:25-28` |
| `mic_price_band_pct` | **10** (`DEFAULT_MIC_PRICE_BAND_PCT` `:52`) | `micPriceBandFraction()` → fraction `:100-104`; passed as `$bandPct` into `score()` (`PropertyMatchScoringService.php:472,541`) | same migration |

Both `unsignedTinyInteger`, fillable `:29-30`, cast integer `:42-43`, seeded in `forAgency()` firstOrCreate
`:83-84`. Tier display thresholds for buyer panels: `BuyerMatchTierService` `strong_min_score`/`mid_min_score`
(`app/Services/Prospecting/BuyerMatchTierService.php:40,131-132`).

---

## 6. WHAT FEEDS IT / WHAT IT FEEDS

**Feeds in:** `prospecting_listings` (canvass stock, wrapped via `wrapCaptureAsProperty()`
`PropertyMatchScoringService.php:847-863`); `contact_matches` (buyer wishlists — canonical buyer criteria);
`properties` (Agency Stock, for `property_buyer_matches`); `buyer_property_views` (historic demand
`:234-242`). **Feeds out:** Core Matches/Intelligence tab (Engine A), Buyer Pipeline auto-land (AT-72),
prospecting buyer panels (`buyerMatches` → `BuyerMatchTierService`), Presentations buyer-demand +
competitor stock (`PresentationController.php:339-340`, `AnalysisDataService.php:123`), buyer portal.

---

## 7. KNOWN FRAGILITIES

1. **Two writers, two scorers, one read surface.** `prospecting_buyer_matches` is canonical (Engine A,
   post-AT-75) but `property_buyer_matches` is still Engine B legacy `calculateScore()`
   (`PropertyMatchScoringService.php:410`). The MIC tile reads only `prospecting_buyer_matches` (canonical);
   presentations reading `property_buyer_matches` via `getMatchesForBuyer/Property` (`:170-192`) are NOT —
   though `getBuyerDemandForProperty` was separately migrated to canonical (`:215`). Same listing can score
   differently depending on which table a surface reads.
2. **Tier vocab differs across readers.** `prospecting_buyer_matches.tier` = perfect/strong/approximate
   (schema `:8633`); Engine A `tierFor()` = strong/good/fair (`MatchingService.php:50-54`);
   `BuyerMatchTierService` = strong/mid/weak (`:131-132`). Same 80 strong-floor, different labels — a
   reporting-consistency hazard.
3. **Garbage-in from import.** Scoring quality is capped by the tracked/prospecting data quality
   (`prospecting-tracked-properties.md` §4: property_type 81% missing, GPS 87% missing, no features). The
   canonical `score()` neutralises NULL property-side category/type (AT-75 `:366-372`), so missing data
   silently widens matches rather than failing them.
4. **`features_json` only scored on the property side, absent on comps/listings.** Must-have/nice-to-have
   feature scoring works for Agency Stock but the canvass pool has no feature column (AT-81 §2.3) → the
   feature axis is effectively one-sided for prospecting matches.
5. **Auto-land is manual.** `buyers:autoland-pipeline` is not scheduled (`:35`) — countable-buyer landing
   relies on the `ContactMatchObserver` path or a manual run; a missed run leaves buyers unlanded.
6. **`buyers_matched` truth depends on the countability invariant.** The tile equates distinct cached
   contact_id with distinct countable buyers (AT-71) — correct only because non-countable buyers are never
   cached. If that invariant breaks upstream, the tile over/under-counts.

---

## Key file:line index
- `app/Services/Matching/MatchingService.php` — `:19-30` consts, `:50-56` tierFor, `:106-127`
  matchesForProperty, `:342-411` score, `:421-467` fit ratios, `:477-517` negation.
- `app/Services/PropertyMatchScoringService.php` — `:72-153` calculateScore, `:817-830` determineTier,
  `:604-636` canonicalBestAcross, `:384-442`/`:449-579` recompute writers, `:215-227` getBuyerDemandForProperty.
- `app/Http/Controllers/CoreX/MarketIntelligenceController.php` — `:57-62` work, `:1760-1824` KPIs, `:292-348` slider.
- `app/Models/AgencyContactSettings.php` — `:50,52` defaults, `:93-104` readers.
- `resources/views/corex/market-intelligence/_stats-strip.blade.php` — `:50-80` tile, `:106-144` slider.
- Observers/jobs: `ContactMatchObserver.php:185-189`, `ProspectingListingObserver.php:30-55`,
  `RegenerateBuyerMatchesJob.php`, `routes/console.php:162,165`.
