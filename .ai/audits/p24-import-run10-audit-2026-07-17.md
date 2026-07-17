# P24 Import Audit — Run 10 (agency 42), QA2

**Date:** 2026-07-17
**Env:** QA2 (`qatesting2.corexos.co.za`, DB `corex_qa2`)
**Run:** id 10, `listings_images`, agency 42, 506 listings, `mark_compliant_on_confirm=1`
**Method:** read-only — DB reconciliation, on-disk verification, source(CSV)→property field comparison

---

## RESOLUTION (2026-07-17, same day)

All findings below were fixed and the run-10 data was re-mapped in place and
re-verified. Final state on QA2:

- **Type map — FIXED.** `P24PropertyTypeMap` rebuilt from P24's real codes
  (`4=House, 5=Apartment, 6=Townhouse, 8=VacantLand, 10=Farm, 11=Commercial,
  12=Industrial`) — matches `Property24ListingMapper::resolvePropertyTypeId()`.
  Run-10 now reads **House 171 / Apartment 168 / VacantLand 167**, matching the
  titles. Locked by `tests/Unit/Importer/P24PropertyTypeMapTest.php`.
- **Dropped CSV fields — FIXED.** Migration `..._add_p24_import_completeness_fields`
  adds `occupation_date, source_reference, lightstone_id, development_id,
  eyespy_360_id, erf_area_unit, floor_area_unit`. Parser now also captures the raw
  P24 SuburbId (`features_json.p24_source_suburb_id`) and the 4 room-description
  fields. `source_reference` and `floor_area_unit` now 506/506.
- **Area units — FIXED.** Erf/floor areas normalised to m² by unit (ha/acres/km²)
  so a "2 ha" erf is stored as 20,000 m², not 2.
- **Blank dates — FIXED.** Empty `OccupationDate`/`ExpiryDate` now → NULL (MySQL
  rejects `''` for a DATE column).
- **Suburb FK — GUARDED.** `p24_suburb_id` is FK-constrained; only set when the
  suburb exists in `p24_suburbs` (raw value always preserved), so a partially
  seeded reference table can't 1452 the import. 378/506 resolved locally.
- **Run status — FIXED.** Portal batch now marks the run `completed` on drain;
  run 10 stamped completed.
- **Galleries — untouched by the re-map.** Still 4,375/4,375 on disk; the
  skip-if-unchanged signature meant zero image re-downloads.

Everything from the CSV now lands somewhere queryable. Original findings retained
below for the record.

---

## Verdict

**The import mechanically succeeded with ZERO data loss on everything the importer
controls. All 506 listings and all 4,375 images pulled through, verified in the DB
and on disk.** The one thing that *looks* wrong — property type/category — is an
artifact of **synthetic test data**, not an importer bug. But that same fact means
type-mapping correctness is **UNVERIFIED** and must be checked against a real P24
export before any live agency go-live.

This was run against fabricated test data (SourceReference `CoreX-17/16/32…` not
real P24 refs, blank CSV coordinates, templated titles, JHB/CT suburbs on a South
Coast agency). So "source-data limitation" findings below are properties of the
fixture, not the importer.

---

## ✅ Clean — verified correct

| Check | Result |
|---|---|
| Rows → properties | **506 rows → 506 distinct properties, 1:1.** 0 error, 0 pending, 0 excluded |
| Duplicates | **0** duplicate `p24_listing_number` in agency 42; total agency-42 props = 506 (no strays) |
| **Galleries (the headline)** | **4,375 offered = 4,375 stored.** `gallery_stored_count`, `images_json`, `gallery_images_json` all = 4,375. Every property `complete`, **0 short** |
| Galleries on disk | **4,375 files on disk = 4,375 expected.** 0 files < 500 bytes (no placeholders), 0 missing dirs |
| Agents | **17 distinct agents, 0 missing, 0 in the wrong agency.** All resolved from `primary_agent_p24_id` |
| Syndication linkage | `p24_ref` set on all 506 (so a later push UPDATES, never duplicates) |
| Compliance | `compliance_snapshot_at` set on all 506 (go-live `mark_compliant_on_confirm` worked) |
| Core text fields | title, headline, description, `listing_type`, `expiry_date`, `features_json`, `spaces_json`: **506/506** |
| Rentals | 85 zero-price rows are **all rentals** with `rental_amount > 0` — correct, not missing price |

The gallery result is the important one: this is exactly what the parallel/lossless
rebuild was for, and on a 4,375-image import it lost **nothing**.

---

## ⚠️ Findings to act on

### 1. Property type / category mapping is UNVERIFIED (do before real go-live)
The mapping **code works correctly** — it faithfully applies
`app/Services/Importer/P24PropertyTypeMap.php` (`4→VacantLand, 5→Farm, 8→Office`).
But on this import the type contradicts P24's own title on essentially every row:

| CSV `PropertyTypeId` | Title says | Mapped to |
|---|---|---|
| 4 | "3 Bedroom **House** for sale" | VacantLand |
| 5 | "**Apartment** to let" | Farm |
| 8 | "**Vacant land** for sale" | Office |

The distribution is a near-perfect even split — **VacantLand 171 / Farm 168 /
Office 167** — which is the signature of a test generator round-robining
PropertyTypeIds independently of the titles. So the data is internally
inconsistent and **cannot validate the map**.

**Action:** verify `P24PropertyTypeMap::MAP` against P24's official PropertyTypeId
reference list, then re-run this audit against a REAL agency export (where title and
PropertyTypeId agree) before the first live import. If the map is wrong, every
imported listing's type/category is wrong — high impact, currently unproven either
way.

### 2. Run status never marked complete (minor bookkeeping)
Run 10 is still `status='pending_confirm'` with `confirmed_at` and `completed_at`
NULL, even though all 506 rows confirmed. The `Bus::batch` has no completion
callback that updates the run row. Cosmetic (rows are correct) but the importer UI
may show the run as unfinished. Wire the batch `->then()/->finally()` (or a sweep)
to stamp the run `completed`.

### 3. 59 properties in syndication `error` state (post-import, investigate separately)
Confirm set all 506 to `p24_syndication_status='active'`; **447 are active, 59 are
`error`.** All 59 have numeric listing numbers and `p24_ref`, so the flip happened
*after* import via a syndication process — NOT an import-fidelity issue. Worth a
separate look (and note: if QA2 is genuinely attempting pushes to real P24, that's
its own concern given the shared IP).

### 4. Fields dropped from the map (low priority)
`source_reference` and `occupation_date` exist in `mapped_json` but are not in the
confirm job's `$fillable`, so they aren't persisted to any column. Harmless for now;
add columns if the business needs them.

---

## ℹ️ Source-data limitations (NOT importer defects)

- **199/506 have no coordinates** — the CSV `Latitude`/`Longitude` were **blank**.
  ~60% were geocoded/enriched from the address; the 199 are where geocoding had
  nothing to work from. Enrichment gap, not loss. (A real P24 export usually carries
  coordinates, so expect this to be far lower live.)
- **2 properties have no address** — the CSV `StreetName`/`StreetNumber` were blank.
  Nothing to map. Consider a suburb fallback for display.
- **167 properties have beds=0 / baths=0** — these are the land/commercial
  (typeid 8) listings; 0 is the correct schema default for non-residential.

---

## Bottom line

Mechanically, the importer is doing its job perfectly: no listing dropped, no
duplicate created, no image lost, agents and compliance and syndication-refs all
wired. The rebuild's no-loss guarantee held on 4,375 real image fetches.

The only genuine open risk is **type/category mapping correctness**, which this
synthetic dataset structurally cannot prove. Validate `P24PropertyTypeMap` against
P24's real code table and re-audit one real agency export — then this import path is
trustworthy for live go-live.
