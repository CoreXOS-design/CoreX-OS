# Atlas — Prospecting / Tracked Properties (the intelligence pool)

> **Status: DONE** · Last verified: 2026-06-22
> Pillar: **Property** (`tracked_properties` = the intelligence tier, distinct from `properties` Agency Stock).
> Companion: non-negotiable #10 (Universal Match-or-Create), `.ai/specs/cma-property-pillar-binding.md`.
> Cited audits: **AT-81** (structured-import rework + safety investigation) — the primary source for this doc.

---

## 1. WHAT IT DOES

`tracked_properties` is **every property CoreX has intelligence on** — built organically from portal
captures, the Chrome scraper, P24 alert emails, CMA report parsing, and deeds lookups. It is the canvass
pool the MIC scores buyers against and the comp source for CMA comparability. The governing rule
(non-negotiable #10): **every data ingress calls
`TrackedPropertyMatchOrCreateService::matchOrCreate()` before storing** — match first, create only on no
match, append every contribution to an append-only `source_chain` audit. When a mandate is signed, a
tracked property is **promoted to Agency Stock** (`properties`) via `promoteToStock()`.

Two tiers, clearly separated:

| Tier | Table | Purpose |
|------|-------|---------|
| Agency Stock | `properties` | Formal mandates HFC works (My Listings) — see `properties.md` |
| Tracked Properties | `tracked_properties` | Every property CoreX has intelligence on |

---

## 2. ENTRY POINTS — the ingest pipelines (three islands, AT-81 §0)

There are **two import pipelines feeding three destination islands**, and not all reach `tracked_properties`:

### A. Email alerts (Property24) → `p24_listings` — ISLAND (violates #10)
```
ImportP24AlertsJob::handle()            app/Jobs/ImportP24AlertsJob.php:24
 → Artisan p24:import → ImportP24Alerts  app/Console/Commands/ImportP24Alerts.php:19
   → P24ImapImportService::import()       app/Services/P24/P24ImapImportService.php:25
       body read :120-122 (then DISCARDED)
       → P24EmailParserService::parse()   app/Services/P24/P24EmailParserService.php:22 (regex)
       → P24Listing::create/update        P24ImapImportService.php:134-179
```
**Calls `P24Listing::create()` (`:161`), NEVER `matchOrCreate()`** → `p24_listings` is an isolated island,
outside the two-tier model and **invisible to every scorer** (AT-81 §1.4). 20 typed columns, **no
free-text/raw/features/size/GPS** — the email body is parsed then thrown away (AT-81 §1.2).

### B. Scraper / Chrome extension → `tracked_properties` (two converging paths)
```
Path A (LIVE, blob): POST /portal-captures/ingest  routes/web.php:3436
  → PortalCaptureController::ingest        app/Http/Controllers/Presentation/PortalCaptureController.php:22
      raw_html on disk + JSON-LD blob → portal_captures → portal_listings (current_fields_json BLOB)
      → tracked_properties (typed)  PortalListingTrackingService::linkPortalItemsToTrackedProperties:1279-1305
Path B (typed): POST /api/v1/prospecting/import     routes/api.php:295 (legacy :513)
  → ProspectingApiController::import        app/Http/Controllers/Api/ProspectingApiController.php:20
      → prospecting_listings (typed) → tracked_properties  linkToTrackedProperty:297-318
```
Both converge on `TrackedPropertyMatchOrCreateService::matchOrCreate()` (AT-81 §2.1).

### C. CMA report parse → `tracked_properties` (address back-propagation)
`ParseMarketReportJob` back-propagates parsed subject/comp addresses through
`matchOrCreate()` with `source_type='cmainfo'` (`ParseMarketReportJob.php:211-230` — see `cma-report-import.md`).

### Nav / UI
Tracked Properties surface under the MIC (`market-intelligence.work`) and the tracked-properties routes
(`routes/web.php:3198`). Admin importer (`admin.importer.*`, `web.php:475-509`) is a **separate** CSV/agent
onboarding importer — **not** this pipeline (AT-81 §1.1 note, §3 caveat).

---

## 3. THE FLOW — Match-or-Create

`app/Services/Prospecting/TrackedPropertyMatchOrCreateService.php`. `matchOrCreate()` (`:83-103`) runs in a
DB transaction: `resolveMatch()` → hit ? `enrich()` : `create()` → append address history + external ref.

### The 5 (really 6) dedup strategies — first match wins (`resolveMatch` `:132-264`)
| # | Strategy | Keys | Threshold | file:line |
|---|----------|------|-----------|-----------|
| 0 | Address-history | `tracked_property_addresses`: exact street_number + normalised street_name + normalised suburb, OR GPS ~5m | confidence-ordered; suburb-only NOT matched | `:134-145, 278-335` |
| 1 | **Source-ref** | `tracked_property_external_refs(agency_id, source_type, source_ref)` | exact — strongest | `:147-167` |
| 2 | **GPS proximity** | `cma_gps_lat/lng` then `lat/lng` within ±0.00005° | **~5m** (`GPS_TOLERANCE_DEGREES` `:52`) | `:169-199` |
| 3 | **Erf + suburb** | `erf_number` + normalised `suburb` | exact both | `:201-215` |
| 4 | **Normalised address** | `street_number` + normalised `street_name` + normalised `suburb` | exact all three | `:217-232` |
| 5 | **Token-overlap** | same suburb + **≥2 shared tokens (len≥3)** over street_number+street_name | loose last resort, cap 50 | `:234-261` |

Match → `enrich()` (`:461-515`): first-source-wins for stable identifiers (fills only empty/null); the
`NEWER_WINS_FIELDS` set (`:58-64`: municipal_valuation[_year], last_known_asking/sold_price, sold_date)
overwrites on change; `source_chain` append-only. No match → `create()` (`:435-459`). Street-name
normalisation (`:593-604`) canonicalises only **6** suffixes: st, rd, ave, dr, lane, close.

### `canonicalFactsForWrite` whitelist (`:561-572`)
The fields persisted to `tracked_properties`: price, bedrooms, bathrooms, garages, floor_size_m2,
erf_size_m2, property_type, latitude, longitude, suburb, street_*, unit_number, complex_name. **No
features key** → any offering feature passed in `facts` is silently dropped (AT-81 §2.3, §5).

---

## 4. DATA IT READS / WRITES

**Reads (for matching):** `tracked_property_addresses`, `tracked_property_external_refs`,
`tracked_properties` (GPS, erf, normalised address). **Writes (scoring layer ONLY):**
`tracked_properties` (`:437,498`), `tracked_property_external_refs` (`:521,537`),
`tracked_property_addresses` (`:294,315,365,400`), `source_chain` (append-only json audit on the TP).

**It NEVER writes `properties`** (AT-81 §1.2 — verified: `Property::`/`properties` appears only inside
`promoteToStock()`). The ingest pipeline cannot touch an advertised property.

### `promoteToStock()` — the one bridge to Agency Stock (`:625-703`)
Separate, explicit method (takes `$promotingUserId`, fired by mandate signing/promotion, **not** by
import). Mints a `Property` with **`status='draft'`** (`:670`) — a draft is not syndicated. The
TrackedProperty persists post-promotion as the audit trail; `promoted_to_property_id` points at the
operational Property (per non-negotiable #10).

### The three islands (data quality, AT-81 §4.1, sampled 2026-06-21)
| Field | `p24_listings` (email, n≈7,710) | `prospecting_listings` (scraper, n≈7,775) | `tracked_properties` (canonical, n≈7,588) |
|-------|-------------------------------|------------------------------------------|------------------------------------------|
| property_type missing | 4% | 1% | **81%** |
| baths null | **100%** | 28% | 27% |
| garages null | **100%** | 32% | **86%** |
| floor/unit size | no col | **100% NULL** | 68% |
| GPS missing | n/a | 23% | **87%** |
| unit_number / complex_name | n/a | n/a | **0% populated** |

---

## 5. AFFECTS DOWNSTREAM / AFFECTED BY UPSTREAM

**Feeds:** the **MIC** scores buyers against `prospecting_listings` (canvass stock) and the canonical pool
(`market-intelligence.md`); **CMA comparability** (Presentations competitor stock) reads
`prospecting_listings` via `CompetitorStockMatchService::adaptCandidateRow`; **`promoteToStock`** feeds
`properties`. **Fed by:** the scraper/extension, CMA report parsing (address back-prop), portal captures.

---

## 6. AGENCY SETTINGS / CONFIG

No dedicated agency settings govern Match-or-Create matching today (thresholds are service constants:
`GPS_TOLERANCE_DEGREES` `:52`, token len≥3, ≥2 overlap). MIC scoring of this pool uses
`mic_match_threshold`/`mic_price_band_pct` (see `market-intelligence.md`). New import schema must ship with
`agency_id` + SoftDeletes (non-negotiable / AT-81 §7 constraint).

---

## 7. KNOWN FRAGILITIES (all AT-81)

1. **Strategy 5 token-overlap false-merges different house numbers (AT-81 §1.3 — biggest hazard).** Tokens
   <3 chars are dropped (`:611`), so the house number ("12", "98") is filtered out → "12 Mitchell Street"
   and "98 Mitchell Street" both reduce to `{mitchell, street}` → overlap 2 ≥ 2 → **wrong merge**. The
   sparse email feed (suburb + loose text, no number) lands exactly here. Must supply
   street_number+street_name+suburb (≥ strategy 4) before routing sparse feeds through `matchOrCreate()`.
2. **Strategy 2 GPS (~5m) merges stacked sectional units.** Units in one scheme share a footprint GPS;
   `unit_number`/`complex_name` are 0% populated on import → units collapse into one TP. Strategy 0
   address-history GPS has the same hazard.
3. **Duplicate storm on sparse ingest.** No source-ref + no GPS + no erf + no street → no strategy fires →
   `create()` every time. The email feed (suburb string only) mints a new TP per re-ingest.
4. **Normalisation gaps.** Only 6 suffixes canonicalised (`:593-604`); Crescent/Cres, Boulevard/Blvd,
   Place/Pl, Terrace, Way are not → same street under two spellings misses strategy 4 → duplicate.
5. **Cross-source ref mismatch.** P24 ref ≠ PP ref for the same property; strategy 1 won't bridge — relies
   on GPS/erf/address being present.
6. **Email island (#10 violation, AT-81 §1.4).** `p24_listings` never reaches `tracked_properties` → email
   intelligence is invisible to every scorer. A rework requirement, not yet built.
7. **No feature column on the import/comp tables (AT-81 §2.3, §4.1, §5).** `prospecting_listings` and
   `tracked_properties` have no `features_json`; the scraper captures no offering features (pool/study/
   sea-view live only in the on-disk raw HTML). This is the AT-77 "offering not structured on comps" gap —
   worse, it's *absent*, and `canonicalFactsForWrite` would drop it anyway.

**Scope guard (AT-81 §3, non-negotiable):** this layer is isolated from advertising/syndication. The
rework must NEVER write `properties`, the `p24_*`/`pp_*` columns, the syndication mappers, or the admin
CSV importer (which writes `spaces_json`/`features_json` straight into `properties`).

---

## Key file:line index
- `app/Services/Prospecting/TrackedPropertyMatchOrCreateService.php` — `:83-103` matchOrCreate, `:132-264`
  resolveMatch (strategies), `:461-515` enrich, `:435-459` create, `:561-572` canonicalFactsForWrite,
  `:593-604` normaliseStreetName, `:625-703` promoteToStock (status draft `:670`), consts `:52,58-64`.
- Email: `app/Services/P24/P24ImapImportService.php:25,120-122,134-179`, `P24EmailParserService.php:22`.
- Scraper: `PortalCaptureController.php:22`, `ProspectingApiController.php:20`, `routes/web.php:3436`, `routes/api.php:295`.
- Audits: `.ai/audits/AT-81-structured-import-rework-2026-06-21.md`, `.ai/audits/AT-81-rework-safety-investigation-2026-06-21.md`.
