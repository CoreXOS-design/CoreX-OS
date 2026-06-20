# Spec: CMA → Property Pillar Binding (subject size, GPS pin, subject-meta pipeline)

> Status: **DRAFT — awaiting approval.** Raised 2026-06-20 by Johan + Claude while
> refreshing presentation 97 (AT-58 live). The comp-parse fix shipped; this spec
> covers a *separate, structural* gap surfaced during that work.
> Owner pillar: **Property**. Related: Presentations, Market Reports (MIC).

---

## 1. Why this exists (the trigger)

AT-58 corrected the CMA comp parser (PUMULA scheme / "976" year-tail phantom).
After re-parsing presentation 97's source reports (163/164/165/166), the comps
became correct, but the **subject property record (property #547)** still showed:

- `size_m2 = NULL`
- a **fallback** GPS pin (`-30.8468120, 30.3764720`) ~1.2 km off the true Duke
  Road location, shared verbatim by 4 agency-1 properties (#537/544/547/548),
  all with `address = NULL`.

Investigation showed this is **not** a per-property data miss — the CMA→Property
binding is broken at the class level in four independent ways. Hand-setting
values on #547 would paper over a system-wide gap, so the fix is specced here.

## 2. Root cause — four structural gaps

**A. No `size_m2` binding exists.**
`App\Services\Presentation\PropertyCmaPropagationService::RELEVANT_KEYS` =
`subject.erf, subject.gps, subject.address, subject.suburb, subject.title_deed,
municipal.total_value, municipal.valuation_year`. There is **no size/extent
key**, and `buildUpdates()` never writes `properties.size_m2`. The CMA subject
extent lives on `market_reports.subject_extent_m2` with no pipeline to the
property. Affects every property, not just #547.

**B. The GPS propagation writes non-existent columns.**
`buildUpdates()` maps `subject.gps` → `cma_gps_lat` / `cma_gps_lng`. Those
columns **do not exist** on `properties` (verified: col absent). It never writes
the pin columns `latitude` / `longitude`. So `cma:backfill` structurally cannot
set the map pin, and its GPS branch is a latent no-op/error.
File: `app/Services/Presentation/PropertyCmaPropagationService.php` (`buildUpdates`).

**C. The pin's only real binding needs an address #547 lacks.**
`geocoding:backfill` → `App\Services\Geocoding\PropertyGeoBackfillService::
backfillProperty()` resolves `latitude`/`longitude` via `AddressResolverService`
from the property address. #547: `address = NULL`, `street_number = '0'`,
`street_name = 'Duke'`. With no resolvable address it falls to the shared
Margate centroid. The authoritative Duke Road coord (`-30.8578220, 30.3742930`)
was resolved by the CMA parser and sits on `market_reports.subject_latitude/
longitude` (reports 163/165) — but nothing propagates it to the property.

**D. Presentations don't capture subject metadata into `presentation_fields`.**
pres 97's only `presentation_fields` are CMA price ranges
(`cma.lower/middle/upper_range`). No `subject.gps` / `subject.address` rows
exist, so even a correct propagation has nothing to read. The subject's true
address/extent/coords exist only on the `market_reports` subject metadata,
disconnected from both the presentation and the property.

## 3. Proposed design

Bind the **`market_reports` subject metadata** (the parser's authoritative,
already-resolved subject facts) to the Property pillar, and complete the
existing propagation:

1. **Subject-meta → presentation_fields**: when a report is attached to a
   presentation's source set, emit `subject.address`, `subject.gps`,
   `subject.extent`, `subject.scheme`, `subject.section` into
   `presentation_fields` (so the existing propagation has real inputs). Prefer
   the type-4 (property report) parse where subject metadata is populated.
2. **`PropertyCmaPropagationService` completion**:
   - Add a `subject.extent` → `properties.size_m2` mapping in `RELEVANT_KEYS`
     + `buildUpdates()` (only when property size is blank — never clobber a
     human-entered size).
   - Fix the GPS branch to write `latitude`/`longitude` (the pin) with a
     `geo_source = 'cma'` provenance stamp — OR drop the `cma_gps_*` write and
     route the resolved subject coord through `PropertyGeoBackfillService` so
     all pin writes share one path + provenance. (Decide: direct write vs
     resolver round-trip. Resolver round-trip is preferred for provenance
     consistency.)
3. **Address backfill from subject-meta**: when `properties.address` is blank
   but the linked report has `subject_address`, seed it (respecting the
   match-or-create + no-clobber rules) so `geocoding:backfill` can resolve a
   real pin instead of the centroid.
4. **No-clobber + staleness**: keep the existing `last_cma_at >= updated_at`
   stale-guard; never overwrite human-supplied address/size/coord.

## 4. Acceptance criteria

- A property whose linked CMA report carries subject address/extent/coords, and
  which has blank size/address/fallback-coord, receives: `size_m2` from the CMA
  extent, a real `latitude`/`longitude` (not the shared centroid), and an
  address — each only when the property field was blank.
- `cma:backfill` no longer references non-existent `cma_gps_*` columns.
- Re-running is idempotent (no spurious writes; stale-guard holds).
- A regression test covers: subject-meta → presentation_fields → property for
  size + pin, including the no-clobber path.
- Backfill report: how many existing properties were on the shared fallback
  centroid and got corrected (start: the 4 agency-1 no-address properties).

## 5. Files in scope

- `app/Services/Presentation/PropertyCmaPropagationService.php` (RELEVANT_KEYS, buildUpdates)
- `app/Services/Geocoding/PropertyGeoBackfillService.php` (pin write path / provenance)
- presentation generation / hydration (subject-meta → presentation_fields emit)
- `app/Console/Commands/BackfillPropertyCmaFromPresentations.php` (cma:backfill)
- migration: confirm/remove the orphaned `cma_gps_*` expectation
- tests: `tests/Feature/...` propagation coverage

## 6. Out of scope / notes

- AT-58 itself (comp-parse fix) is **done and live**.
- The immediate one-off (#547 pin) is intentionally NOT hand-fixed pending this
  spec — see the decision log in the AT-58 close-out.
- pres 97 regeneration is deferred until this binding lands so the regenerated
  PDF shows a correct subject size + Duke Road pin.
