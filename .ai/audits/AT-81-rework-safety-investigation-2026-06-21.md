# AT-81 — Structured-import rework: SAFETY investigation (dedup · taxonomy blast radius · layer separation)

> **Status: INVESTIGATION ONLY. No code changed. Addendum to `.ai/audits/AT-81-structured-import-rework-2026-06-21.md`.**
> Date: 2026-06-21 · Code root: `/mnt/HC_Volume_103099143/corex-dev` · Data sampled: LIVE `nexus_os`
> Purpose: determine whether the structured-import / MIC-scoring rework is SAFE to build this week
> **unattended**, given HFC goes live on P24 + Private Property syndication **Thursday**.
> **Scope guard (Johan, non-negotiable):** the rework touches the PROSPECTING / MIC SCORING layer ONLY
> (`prospecting_listings`, `tracked_properties`, `portal_listings`, the scorers). It must NEVER write to
> the live advertising tables or touch SYNDICATION code (Andre's domain, Thursday go-live).

---

## TL;DR — VERDICT: the rework is ISOLATED from advertising/syndication. Safe to build, with 3 named guards.

- **Match-or-Create writes ONLY the scoring layer** (`tracked_properties` + `tracked_property_external_refs`
  + `tracked_property_addresses`). It NEVER writes `properties`. The only bridge to `properties` is the
  explicit, user-initiated `promoteToStock()` which mints a **`status='draft'`** Property (not advertised).
- **The P24/PP syndication feeds do NOT read the feature-taxonomy dictionary at all.** P24 reads the
  feature *data* (`features_json`/`spaces_json`) with its own inline portal vocabulary; PP reads neither
  (scalar columns only). The taxonomy lift cannot disturb the feeds.
- **Nothing advertising-facing reads the import tables** (`prospecting_listings`/`tracked_properties`).
  Adding feature columns there is purely additive.
- **Three guards** make it provably safe (§4): (1) don't change the *shape/label strings* of
  `properties.spaces_json`/`features_json`; (2) verify `config/property-spaces.php` ⊇ Blade vocabulary
  before flipping the editor onto config; (3) fix the Match-or-Create dedup gaps (§1) before routing the
  sparse email feed through it, or it will false-merge / duplicate `tracked_properties`.

---

## 1. MATCH-OR-CREATE DEDUP BEHAVIOUR + FAILURE MODES

`app/Services/Prospecting/TrackedPropertyMatchOrCreateService.php`. `matchOrCreate()` (`:83-103`) runs in a
DB transaction: `resolveMatch()` → if hit `enrich()`, else `create()`, then append address history.

### 1.1 The matching keys / thresholds (first match wins — `resolveMatch` `:132-264`)

| # | Strategy | Keys | Threshold / note | file:line |
|---|----------|------|------------------|-----------|
| 0 | Address-history | `tracked_property_addresses`: exact `street_number`+normalised `street_name`+normalised `suburb`, OR GPS ~5m | confidence-ordered (verified>high>medium>low); suburb-only NOT matched | `:134-145, 278-335` |
| 1 | Source-ref | `tracked_property_external_refs(agency_id, source_type, source_ref)` | exact; strongest signal | `:147-167` |
| 2 | GPS proximity | `cma_gps_lat/lng` then `lat/lng` within ±`0.00005°` | **~5m** (`GPS_TOLERANCE_DEGREES` `:52`) | `:169-199` |
| 3 | Erf + suburb | `erf_number` + normalised `suburb` | exact both | `:201-215` |
| 4 | Normalised address | `street_number` + normalised `street_name` + normalised `suburb` | exact all three | `:217-232` |
| 5 | Token-overlap | same suburb + **≥2 shared address tokens** (len≥3) over street_number+street_name | loose last resort; candidate cap 50 | `:234-261` |

Merge → `enrich()` (`:461-515`): first-source-wins for stable identifiers (fills only empty/null); the
`NEWER_WINS_FIELDS` set (`:58-64` — municipal_valuation[_year], last_known_asking/sold_price, sold_date)
overwrite on change; `source_chain` append-only. Create → `create()` (`:435-459`). Street-name normalisation
(`:593-604`) only canonicalises **6** abbreviations: st, rd, ave, dr, lane, close.

### 1.2 CONFIRMED: operates on the SCORING layer, NOT advertising properties

Every read/write in `resolveMatch`/`enrich`/`create`/`appendIngestedAddressToHistory`/`writeExternalRef`
targets **`TrackedProperty` / `tracked_property_external_refs` / `tracked_property_addresses`** only
(`:149,156,174,187,203,219,236,294,315,365,400,437,498,521,537`). The string `Property::` /
`properties` table appears **only** inside `promoteToStock()` (`:625-703`) — a *separate, explicit*
method (takes a `$promotingUserId`, fired by mandate-signing/promotion, NOT by import) that creates a
Property with **`'status' => 'draft'`** (`:670`). A draft is not syndicated (P24/PP submit is a manual
action on an active listing). **So routing the email + scraper imports through `matchOrCreate()` cannot
write to, or alter, any advertised property.**

### 1.3 Failure modes (what the rework must guard before routing the sparse email feed through it)

**WRONG-MERGE risks (collapse two different properties into one TP):**
- **Strategy 5 token-overlap merges different house numbers on the same street.** Tokens <3 chars are
  dropped (`:611`), so the house *number* (e.g. "12", "98") is filtered out; "12 Mitchell Street" and
  "98 Mitchell Street" both reduce to tokens `{mitchell, street}` → overlap 2 ≥ 2 → **MATCH**. Strategy 4
  (exact number) fails first (different numbers), so it *falls through* to strategy 5 and wrongly merges.
  This is the single biggest false-merge hazard, and the email feed (suburb + loose text, no number) is
  exactly the input that lands here.
- **Strategy 2 GPS (~5m) merges stacked sectional units.** Units in one scheme share a building footprint
  GPS; within 5m they collapse to one TP. `unit_number`/`complex_name` are 0% populated on import
  (AT-81 §4.1), so units are indistinguishable → sectional schemes merge into a single TP.
- **Strategy 0/B (address-history GPS) — same ~5m hazard.**

**DUPLICATE-CREATION risks (mint a second TP for the same property):**
- **Sparse ingest = no match = create.** If facts lack source-ref AND GPS AND erf AND street, no strategy
  fires → `create()`. The **email feed** has suburb-string only (no street/GPS/erf/suburb_id — AT-81
  §1.2/§1.3) → every email re-ingest mints a **new** TP → duplicate storm. Strategy 5 also needs a
  `street_name`/`address`, which the email feed lacks.
- **Normalisation gaps.** `normaliseStreetName` (`:593-604`) handles only 6 suffixes; "Crescent/Cres",
  "Boulevard/Blvd", "Place/Pl", "Terrace", "Way" are not canonicalised → the same street under two
  spellings misses strategy 4 → duplicate.
- **Cross-source ref mismatch.** P24 ref ≠ PP ref for the same property; strategy 1 won't bridge them —
  they must be caught by GPS/erf/address (strategies 2-4), which require those facts to be present.

**Implication for the rework:** the email pipeline must supply at minimum `street_number` + `street_name`
+ `suburb` (for strategy-4 exact dedup) — and ideally GPS + a stable `source_ref` — BEFORE being routed
through `matchOrCreate()`. Routing the current sparse email shape through it would dedup poorly (strategy-5
false-merges) or not at all (duplicate storm). This is a rework *requirement*, not a blocker to the
advertising path (it only affects `tracked_properties`).

---

## 2. FEATURE-TAXONOMY BLAST RADIUS

**Key correction to AT-81's framing:** the taxonomy is **already lifted** — `config/property-spaces.php`
(`:19-142`: `all_space_types`, `space_features`, `default_space_features`, `feature_categories`,
`half_unit_spaces`) is a server-side source of truth that the **Mobile API** (`MobilePropertyController.php:389-397`)
and the **public website feed** (`ListingResource.php:278` `config('property-spaces.feature_categories')`)
and the **AI suggestor** (`PropertyAiSuggestionService.php:76`) already read. The "lift" is really a
**de-duplication** of three hand-synced copies onto that config:
- Copy #2 — Blade JS in `show.blade.php`: `_SPACE_FEATURES` (`:5583`), `_DEFAULT_SPACE_FEATURES` (`:5659`),
  `_ALL_SPACE_TYPES` (`:5665`), `_FEATURE_CATEGORIES` (`:5666`), `_HALF_UNIT_SPACES` (`:5672`) — the
  property **editor UI** (advertising-input).
- Copy #3 — `VisionRecognitionService.php:31` `const SPACE_TYPES` ("Mirrors _ALL_SPACE_TYPES").

### 2.1 Does any ADVERTISING surface depend on the taxonomy or on features_json/spaces_json?

- **P24 syndication feed — reads the DATA, not the dictionary.** `Property24ListingMapper.php:130-134`
  reads `features_json` + `spaces_json`, then maps via its OWN inline portal vocabulary (`'Garden'`,
  `'Pool'`, `'Solar Panel'`… `:138-251`) — independent of `_FEATURE_CATEGORIES`. Relocating the editor
  dictionary cannot touch it.
- **PP syndication feed — reads NEITHER `features_json` NOR `spaces_json`.** `PrivatePropertyListingMapper`
  `buildAttributes()` (`:355-361`) uses scalar columns only (beds/baths/garages/floor/land). Fully
  feature-immune.
- **Public website** (`ListingResource.php:99,199,273` + the config feature_categories) and the **property
  edit/show/preview** Blades read the data + the dictionary — advertising-facing, but unaffected by a
  *verbatim* relocation.

### 2.2 SCORING/MIC-only consumers of `features_json` (safe — read-only, never the dictionary):
`MatchingService.php:500`, `PropertyMatchScoringService.php:708,858`, `CompetitorStockMatchService.php:933`
(sets `features_json => null` for comps — the AT-77 missing-offering gap).

### 2.3 Verdict for the lift
SAFE provided two things: (a) the values copied to config are **byte-identical** to the current Blade/Vision
copies (the three are hand-synced and may already have drifted — diff `config/property-spaces.php` against
the Blade constants and confirm config ⊇ Blade before flipping the editor onto config; any label only in
Blade would silently vanish from the editor AND any P24 `hasFeature('X')` whose `X` lives only in the old
Blade list would stop matching the feed); (b) the **shape/keys** of `properties.spaces_json`
(`{spaces:[{type,count,featuresAll,units}], features:{category:[…]}}`) and the flat `features_json` array
are **NOT** altered — `Property24ListingMapper` and `ListingResource` parse those exact shapes.

---

## 3. LAYER SEPARATION — import/MIC vs advertising/syndication

| Layer | Tables (write) | Touched by the rework? |
|-------|----------------|------------------------|
| **IMPORT / MIC SCORING** | `tracked_properties`, `tracked_property_external_refs`, `tracked_property_addresses`, `prospecting_listings`, `portal_listings`, `portal_captures`, `p24_listings` (email island), `prospecting_buyer_matches`, `property_buyer_matches`, `market_reports`/`market_report_comp_rows` | **YES — this is the rework's surface** |
| **ADVERTISING / SYNDICATION** | `properties` (incl. `features_json`/`spaces_json`/images + `p24_*`/`pp_*` columns), `p24_suburbs`, `pp_suburbs`, `p24_syndication_logs`, syndication mappers/clients/jobs | **NO — must not write/alter** |

- **Import → advertising bridges:** exactly one, and it's explicit: `promoteToStock()` (mandate signing)
  → `properties` `status='draft'`. The import *pipeline* (email/scraper → `matchOrCreate`) never crosses.
- **The ONE shared surface is a READ:** `properties.features_json`/`spaces_json` is read by BOTH the P24
  mapper (advertising) AND the scorers (MIC). The rework does **not** change that column — it ADDS feature
  columns to the *import* tables (`prospecting_listings`/`tracked_properties`), which no advertising
  surface reads. So the shared read is untouched.
- **One out-of-scope caveat to flag:** there IS an *admin CSV/spreadsheet* importer
  (`P24ListingsCsvParser.php:140-141` → `ConfirmP24PropertyRowJob.php:58,63`) that writes `spaces_json` /
  `features_json` straight into **`properties`** (agency stock). That is a DIFFERENT importer from the
  AT-81 rework target (email-alerts + Chrome scraper → `tracked_properties`). The rework must **not**
  touch that CSV path — leave it exactly as is. It is not part of the email/scraper pipeline.

---

## 4. CLEAR STATEMENT — is the rework safe to build this week unattended?

**Yes — the import/MIC rework is isolated from the advertising/syndication path and cannot affect the
Thursday portal go-live, subject to three guards being honoured in the spec:**

1. **Write only the scoring layer.** All new writes go through `matchOrCreate()` into
   `tracked_properties`/`prospecting_listings` (+ the new feature columns there). Never write `properties`,
   never call the syndication mappers/clients, never touch `p24_*`/`pp_*` columns or the CSV importer.
2. **Taxonomy lift is a verbatim de-dup onto `config/property-spaces.php`.** Diff the three copies first,
   confirm config ⊇ Blade vocabulary, keep label strings byte-identical, and do NOT change the
   `spaces_json`/`features_json` shape on `properties` (the P24 mapper + website parse it).
3. **Fix Match-or-Create dedup before routing the sparse email feed through it** (§1.3) — supply
   street+suburb (≥ strategy 4) and tighten strategy 5 / GPS-for-sectional — else the email feed will
   false-merge or duplicate `tracked_properties`. This is a scoring-layer correctness issue only; it has
   zero advertising blast radius.

No code, schema, or data was changed by this investigation. **Next:** Johan approves scope → write the
structured-import rework spec in `.ai/specs/` (per AT-81 §7's 8 decisions, plus the dedup hardening above).
