# AT-78 — Presentation comp shows wrong address (55 Garden Ave → R13m "14 Nautilus")

> **Status: In Progress — INVESTIGATION ONLY. No code changed.**
> Date: 2026-06-21 · DB: production `nexus_os` (`/mnt/HC_Volume_103099143/corex`) · all records `is_demo=0` (LIVE)
> Reported by: Johan, off Elize's real CMA for 55 Garden Avenue.
> **Investigation went through 3 passes** (each corrected the prior). Pass 1 wrongly assumed manual
> entry; pass 2 found the generator backfill; pass 3 ruled out the contact-link suspect and confirmed
> the backfill. **Confirmed root cause is §0.**

---

## 0. ROOT CAUSE — the presentation generator auto-wrote "Unit 14, NAUTILUS" by borrowing an unrelated report

The agent never entered the scheme/unit, and contact-linking did **not** write it (ruled out in §0.1).
`complex_name`+`unit_number` were stamped onto the freehold subject **during presentation generation**,
copied from a *different* property's CMA report that merely shares the suburb. Chain (file:line, `app/`):

1. **The auto-write.** `PresentationGeneratorService::backfillSubjectSectionalIdentity()` runs on every
   Generate (called at `PresentationGeneratorService.php:254`, step "3.4c"). It writes
   `complex_name`+`unit_number` onto the subject `Property` whenever both are blank (`:362-405`), no
   agent input. Intent (docblock `:244-252`): fill a *sectional* subject's identity from **its own**
   CMA. Defect: it does not verify the report is the subject's own.

2. **Wrong candidate set — suburb-only match.** It picks the report via
   `SubjectReportResolver::resolveReportIds($agencyId, $property->address, $property->suburb)`
   (`:370-374`). Property 557's legacy **`address` column is NULL** (its address lives in
   `street_name='Garden Avenue'`/`street_number='55'` — the documented address-fragmentation gotcha),
   so `extractAddressNeedles('')` returns `[]` and the resolver falls through to a **suburb-only OR
   match**: `SubjectReportResolver.php:98-99` → `LOWER(source_suburb) = 'uvongo beach'`. That returns
   **every** agency-1 report in Uvongo Beach: **`[48,78,79,80,81,169,170,171,172]`** (verified live).
   The resolver's own docblock admits "Resolution is by address-fragment **OR suburb** match"
   (`SubjectReportResolver.php:27-29`) — the same bug-class as the earlier GPS-borrow fix, which was
   applied to `AddressResolverService` but **not** here.

3. **Borrowed identity.** From that suburb-wide set the backfill takes the highest-id report carrying a
   sectional subject identity (`whereNotNull subject_scheme_name/section_number … orderByDesc('id')`,
   `:380-386`) → **`market_reports` id `81`**: `subject_scheme_name='NAUTILUS'`,
   `subject_section_number='14'`, `subject_address='75 MARINE DRIVE'`, `source_suburb='UVONGO BEACH'`,
   subject GPS `-30.8308040 / 30.3963730` (created 2026-06-03). That is the **real NAUTILUS sectional
   scheme at 75 Marine Drive** — a different property, next door to the R13m comp's 71 Marine Drive.
   The backfill copies `NAUTILUS` + `14` onto property 557 (`:392-401`).

4. **No `title_type` guard.** It writes sectional identity onto a `title_type='full_title'` freehold
   6-bed house (erf 1012 m²) with no check on the property's own title type. A freehold home should
   never receive `complex_name`/`unit_number`.

**Reproduced live (code, not assumption):** running the exact resolver + backfill selection query for
property 557 *today* returns **report 81 → NAUTILUS / 14**. The backfill only fires when the fields are
blank, so the value auto-restamps on any regenerate. (The current NULL on 557 — §1 — is a manual clear
at `updated_at=17:33:12`, after the bug; not the fix.)

**Who/what/when:** written by the **system** (presentation generator, during agent 41's Generate), at
generation time (subject snapshot frozen 17:08:33 already shows "Unit 14, NAUTILUS"). The
`property_audit_log` has only the `property_created` row (id 574, 17:07:38, NULL field snapshot) — the
backfill `$property->save()` bypasses the audit observer, itself a gap.

### 0.1 Contact-linking ruled OUT (Johan's exact repro sequence checked on live)

Johan's sequence: create property → capture clean address "55 Garden Avenue, Uvongo Beach" (no complex/
unit) → Save (requires a contact) → search + link contact **"Johan Maree"** → Save → refresh →
presentation data loaded → NAUTILUS/14 present. Prime suspect was the contact-link copying the
contact's structured address onto the property. **Disproven on live:**

- **The linked contact is empty.** `contacts` id **16062 "Johan Maree"** — `complex_name`,
  `unit_number`, `street_number`, `street_name`, `suburb`, `address` are **all NULL**. Linked to 557 in
  `contact_property` at 17:07:39 (role blank). It carries no address to copy.
- **No contact anywhere carries NAUTILUS/14.** A DB-wide scan of `contacts` for
  `complex_name LIKE '%NAUTILUS%'` or `unit_number='14'` returns **zero rows**.
- **The link path writes no property address columns.** `ContactPropertyController::link`
  (`:38-90`) and the property-store link loop (`PropertyController.php:650-716`) only
  `syncWithoutDetaching([$id => ['role' => …]])` the pivot and fire `ContactLinkedToProperty`; the sole
  listener is `LogContactEvent` (logging only — `AppServiceProvider.php:380`). The *only* contact→
  property address copy in the codebase is `PropertyController.php:401-404`, and that is in the
  **`create()` GET prefill** (`request('contact_id')` on the create form), not the link/store path —
  and it reads from the contact, which here is empty.

So the contact-link step was a necessary precondition (Save requires a contact) but **not the writer**.
The "appeared around save/refresh" timing the user saw is the **presentation generation** that ran on
that refresh — i.e. the §0 backfill — not the link.

---

## TL;DR (the symptom)

There is **no join/stitch bug** between a comp's price and a comp's address. Two **separate** real
defects were combined by the eye into one symptom:

1. **"14 Nautilus" is the SUBJECT's address — AUTO-WRITTEN onto it by the generator (see §0).** The
   display-address builder just rendered what was wrongly stored — the bug is the **write**, not the
   render.
2. **The R13m comp is a genuine, single, internally-consistent record** — `71 MARINE DRIVE`
   (`market_report_comp_rows` 1035, report 170; erf 240, 1896 m², R13m, 2025-08-04, 255 m). It shows in
   the **analysis-stage** comps table because the **display** path applies **no outlier exclusion**,
   unlike the valuation engine (which excluded it: `excluded_by_outlier:1`). Later unticked → **NOT in
   the published PDF** (12 comps). A CODE gap (display path ≠ valuation path), independent of #1.

The R13m comp row renders its **own** address ("71 Marine Drive"), never "14 Nautilus".

---

## 1. The presentation / subject / snapshot (all LIVE)

| Artifact | Record | Key values |
|----------|--------|-----------|
| Presentation | `presentations` **id=98** | "55 Garden Avenue, Uvongo Beach, Margate"; **asking R2,500,000**; `property_id=557`; user **41 (Elize Southbroom)**; created **17:08:33**; status `draft` |
| Published version | `presentation_versions` **id=197** | `published_at 17:14:28`; `included_comp_ids_json` = 12 ids (**EXCLUDES 2001**) |
| Analysis snapshot | `presentation_snapshots` **id=63** | `computed_json` vicinity = **13 rows incl. R13m**; `subject_property.display_address = "Unit 14, NAUTILUS, 55 Garden Avenue…"` |
| Subject property | `properties` **id=557** | street_number `55`, street_name `Garden Avenue`, **`address` column = NULL**, suburb "Uvongo Beach", `property_type=House`, **`title_type=full_title`**, beds 6, erf 1012; agent 41. `complex_name`/`unit_number`: **NAUTILUS/14 at generation, NULL now (`updated_at=17:33:12` manual clear)** |
| Linked contact | `contacts` **id=16062 "Johan Maree"** | structured address **all NULL** (ruled out as source, §0.1) |
| Borrowed source | `market_reports` **id=81** | `NAUTILUS` / `14` / `75 MARINE DRIVE` / `source_suburb=UVONGO BEACH`, created 2026-06-03 — a *different* property's CMA |

"Nautilus" is a real sectional scheme at **75 Marine Drive** — not 55 Garden Avenue. The scheme identity
was machine-borrowed from a same-suburb neighbour.

## 2. The R13m comp — a single consistent record

- `presentation_sold_comps` **2001** (`raw_row_json`: `source_report_id=170`, `mic_comp_row_id=1035`,
  `address="71 MARINE DRIVE, UVONGO BEACH"`, `distance_m=255`, `extent_m2=1896`, `sale_date=2025-08-04`)
  → source `market_report_comp_rows` **1035** (report 170, row 0, `erf_number=240`, R13,000,000). Price
  and address are the **same row** — not mis-stitched. A real Lightstone sold comp, not a competitor /
  tracked / P24/PP listing. No "Nautilus" row exists in report 170.

## 3. The address mismatch — where each value comes from (NO render stitch)

- **"14 Nautilus" = subject `display_address`**, built from the wrongly-stored complex/unit:
  `AnalysisDataService.php:161-170` → `Property::buildDisplayAddress()` (`Property.php:398-452`). The
  gate is **data-presence, not `title_type`** (`:164`), so auto-written sectional fields on a freehold
  trigger the sectional label.
- **Comp address is strictly the comp's own value.** PDF cells print `$sale['address'] ?? '—'`
  (`PresentationPdfService.php:1847/1919/2382`); comp map pins use `CompLabel` of the comp's own row
  (`:2128`); subject identity is a separate variable (`:670-671`). `$sale['address']` =
  `CompLabel::build()` of the comp's `raw_row_json` (`CompLabel.php:65-98`) = "71 MARINE DRIVE" for comp
  2001. No path lets a comp inherit the subject's address.

## 4. Why a R13m comp was in a R2.5m set

- MIC-snapshot comps: all 13 Lightstone rows stored verbatim; import does not band-filter.
- **Two independent paths.** Valuation `CmaComputeService::cleanPool` (`:122,151`) IQR-excludes it
  (`excluded_by_outlier:1`; cleaned max R2.65m; valuation R1.98–2.49m). Display
  `AnalysisDataService::compileComparableSales` (`:216-349`) applies **no** outlier cut (`:277-283`) →
  R13m row renders. At analysis time `included_comp_ids` null → all 13 in pool (`:82-83`); later
  unticked → published version froze 12 (R13m gone). Manual untick, not the engine, removed it.

## 5. Is "14 Nautilus" a real property? / 6. Demo vs real

No "14 Nautilus" property/listing exists and it was not matched into a comp. "Nautilus" is the scheme at
75 Marine Drive (its CMA = report 81). "Unit 14, NAUTILUS" was fabricated onto the subject by §0. All
records are LIVE production (`is_demo=0`) — created today by Elize except borrowed report 81 (2026-06-03)
and contact 16062.

---

## Root cause (final)

1. **CODE — generator borrows a neighbour's identity (the "14 Nautilus" cause).**
   `PresentationGeneratorService::backfillSubjectSectionalIdentity` (`:254,362-405`) auto-writes
   `complex_name`+`unit_number` onto the subject from a CMA report chosen by
   `SubjectReportResolver::resolveReportIds`, which **OR-matches on suburb alone**
   (`SubjectReportResolver.php:98-99`) when the property's `address` column is empty. It takes the
   most-recent sectional report in the suburb (`:380-386`) — report 81 (NAUTILUS/14, 75 Marine Drive) —
   and stamps it on a `full_title` freehold with **no `title_type` guard**. NOT agent entry, NOT
   contact-linking (§0.1).
2. **CODE — display/valuation outlier parity (the R13m-in-table cause).** Display comps table renders
   the raw pool with no outlier cut while the valuation engine excludes the same outlier.

**Not** a render/join bug stitching address+price from different records.

## Recommended fix shape (NOT yet built — for spec/approval)

- **Kill the borrow (primary):** in `SubjectReportResolver::resolveReportIds`, **require an
  address-needle match**; let suburb only *confirm*, never select alone (mirror the GPS-borrow fix).
  Source the address from `buildDisplayAddress()`/street fields, not the empty legacy `address` column.
  AND gate `backfillSubjectSectionalIdentity` to never write sectional identity onto a `full_title`
  property, and only from a report whose subject GPS/erf/address actually matches the subject.
- **Audit the write:** route the backfill `save()` through the property audit observer.
- **Display outlier parity:** make the rendered comps table honour the valuation engine's outlier
  exclusion (or visibly flag outlier rows).
