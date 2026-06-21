# AT-78 — Presentation comp shows wrong address (55 Garden Ave → R13m "14 Nautilus")

> **Status: In Progress — INVESTIGATION ONLY. No code changed.**
> Date: 2026-06-21 · DB: production `nexus_os` (`/mnt/HC_Volume_103099143/corex`) · all records `is_demo=0` (LIVE)
> Reported by: Johan, off Elize's real CMA for 55 Garden Avenue.
> **Investigation went through 3 passes** (each corrected the prior). Pass 1 wrongly assumed manual
> entry; pass 2 found the generator backfill; pass 3 ruled out the contact-link suspect and confirmed
> the backfill. **Confirmed root cause is §0.**
> **Pass 4 (2026-06-21, NEW EVIDENCE — screenshot of 557's Internal Address modal): see §A below.**
> The screenshot shows a THIRD field — `unit_section_block = "Elizabeth Reichel"` (a PERSON'S name in
> an address field) — that the §0 backfill does NOT explain. Pass 4 is a full-DB contamination hunt for
> that name + the Nautilus/14/Elizabeth-Reichel trio. **It splits the symptom into TWO independent
> writes and revises the model: §0 (backfill) still explains Nautilus/14; a separate client-side write
> explains "Elizabeth Reichel".**

---

## §A. PASS 4 — full-DB hunt for "Elizabeth Reichel" + the 14/Nautilus/Reichel trio (LIVE)

**Method:** generated per-table `LIKE` queries across **all 1,961 char/varchar/text/json/enum columns in
all 366 text-bearing tables** of live `nexus_os` (collation-normalised). Exhaustive, not sampled.

### A.1 The symptom is TWO independent contaminations layered onto property 557 — not one

| # | Field on 557 | Value | When written | Source | Emits event? | Audited? |
|---|--------------|-------|--------------|--------|--------------|----------|
| 1 | `unit_section_block` | **"Elizabeth Reichel"** | **at INSERT, 17:07:38** | **NOT any CoreX record** (see A.3) — client-side form entry/autofill (A.6) | yes (in `PropertyCaptured` payload) | no (only `property_created`) |
| 2 | `complex_name` + `unit_number` | **NAUTILUS / 14** | **after creation** (generator backfill) | `market_reports` id **81** (§0) | **no** | **no** |

Proof of ordering (live `domain_event_log`, the only audit trail that caught any of it):
- Property 557 has exactly **two** domain events, both at creation:
  `PropertyCaptured` (id 80555, 17:07:38.731) and `PropertySuburbLinked` (id 80557, 17:07:39.057).
- **Both payloads contain "Elizabeth Reichel" and NEITHER contains "NAUTILUS".** So at the moment of
  the INSERT, `unit_section_block` was already "Elizabeth Reichel" while `complex_name`/`unit_number`
  were still NULL. Nautilus/14 arrived **later**, written silently by the §0 backfill (no event, no
  audit row — confirms §0's "bypasses the audit observer" note).
- `property_audit_log` for 557 = **one row** (id 574, `property_created`, 17:07:38, NULL value
  snapshots). The Nautilus/14 backfill write and the later clears (557 `updated_at` moved 17:33→17:45)
  left **no** audit trace.

### A.2 Every DB location of "Nautilus" (case-insensitive, all tables)

18 tables contain "Nautilus". **All are legitimate references to the real NAUTILUS sectional scheme at
75 Marine Drive, Uvongo Beach** — none is a source for 557's value except the backfill's report 81:

`geocoding_cache`(4), `document_library_items`(1), `contacts`(10 — all in the free-text **`address`**
column, i.e. people who *live* in Nautilus; **zero** in `complex_name`/name fields),
`presentation_snapshots`(2), **`market_reports`(1 = id 81 — the §0 backfill source)**,
`presentation_uploads`(10), `prospecting_listings`(6), `presentations`(1), `tracked_properties`(1),
`portal_captures`(3), `tracked_property_addresses`(1), `presentation_sold_comps`(12),
`market_report_comp_rows`(8), `pp_suburbs`(1), `p24_suburbs`(1), `document_filing_register`(2),
`presentation_versions`(2), `domain_event_log`(19). Report 81: `subject_scheme_name='NAUTILUS'`,
`subject_section_number='14'`, `subject_address='75 MARINE DRIVE'`, `source_suburb='UVONGO BEACH'` —
**no "Reichel" anywhere in it.** Confirms §0: the backfill copied the *string* NAUTILUS/14 (no FK link).

### A.3 Every DB location of "Elizabeth Reichel" — it exists NOWHERE as source data

Exact-phrase scan of the entire DB: **"Elizabeth Reichel" appears in exactly ONE table — `domain_event_log`
— in exactly 3 rows, ALL of which are property 557's own creation/link events:**

| event id | event_name | subject |
|----------|-----------|---------|
| 80555 | `PropertyCaptured` | Property 557 (payload snapshot) |
| 80557 | `PropertySuburbLinked` | Property 557 (payload snapshot) |
| 80559 | `ContactLinkedToProperty` | Contact 16062 (payload embeds the 557 property snapshot) |

There is **no contact, no user, no tracked_property, no listing, no scheme, no comp row** anywhere in the
DB — **including soft-deleted/trashed rows** — that carries "Elizabeth Reichel". The name entered CoreX
**once**, at 557's INSERT, and is found *only* as the value it became on 557 (echoed into 557's own event
payloads). It is **not** copied from any record.

Closest real records (all unrelated to 557's address):
- **`users` id 23 "Elize Reichel"** (`elize@hfcoastal.co.za`); also id 42 "Elize Reichel Ballito".
- **`contacts` id 10298 "Elize Reichel"**, agency 1 — structured address all NULL; free-text
  `address = "Clarendon Road 19a, Uvongo"` (NOT Garden Avenue; `unit_section_block` NULL).
- **The creating agent is user 41 = "Elize *Southbroom*"** (`elizesouthbroom@hfcoastal.co.za`) — *not*
  Reichel. "Elize" is short for "Elizabeth", so "Elizabeth Reichel" is the formal form of Elize Reichel
  (Johan's wife / agency principal), but that formal string is stored on **no** record — it is a
  human/profile rendering, which is the tell for client-side autofill (A.6).

### A.4 Is there a single source record carrying the trio 14 + Elizabeth Reichel + Nautilus? — NO

There is **no contact, scheme, tracked_property, unit listing, or any row anywhere** that carries
`unit_number=14` + `unit_section_block/section="Elizabeth Reichel"` + `complex="Nautilus"` as a set. The
trio never existed as a source; it is an **artifact of the two independent writes in A.1** colliding on
one property. (Searched: properties, contacts, tracked_properties, tracked_property_addresses,
prospecting_listings, market_reports, market_report_comp_rows, presentation_sold_comps — none holds the
combination.)

### A.5 Property 557 address columns — current state + write history

Current (all sectional fields cleared): `address`=NULL, `street_number`='55', `street_name`='Garden
Avenue', `suburb`='Uvongo Beach', `complex_name`=NULL, `unit_number`=NULL, **`unit_section_block`=NULL**,
`title_type`='full_title', `created_at`=17:07:38, `updated_at`=**17:45:37**. Write history:
1. **17:07:38 INSERT** — `unit_section_block="Elizabeth Reichel"` already present; `complex_name`/
   `unit_number` NULL. (Captured in `PropertyCaptured` payload; only `property_created` audit row.)
2. **after creation** — §0 backfill writes `complex_name='NAUTILUS'`, `unit_number='14'`. No event, no
   audit row.
3. **17:33:12 then 17:45:37** — manual clears of the sectional fields (per §1 / `updated_at` movement).
   No event, no audit row. The backfill *re-stamps* Nautilus/14 on any regenerate (§0); the
   "Elizabeth Reichel" write does **not** re-occur (it has no server source).

### A.6 Code-path check — can a contact/user NAME field land in a property address column? — NO server path does

Every server write to `properties.unit_section_block` was traced:
- **Create-prefill — `PropertyController::create()` (`PropertyController.php:401-409`)** copies the
  contact's structured address **field-for-field** when `?contact_id=` is present:
  `$property->unit_section_block = $contact->unit_section_block;` (`:403`). This is **field-aligned**
  (block→block) — there is **no name→address collision**. And it is **inert here**: a DB-wide check
  shows **not a single contact in `nexus_os` has any non-NULL `unit_section_block`**, so this prefill can
  only ever copy NULL. **RULED OUT** as the source of "Elizabeth Reichel" (this also independently
  re-confirms §0.1's contact-link exoneration — the linked contact 16062 "Johan Maree" is empty).
- **Store — `PropertyController::store()` (`:493`)** and **wizard `createDraft()` (`:85`)** validate
  `unit_section_block` as a plain `nullable|string` and persist whatever the **form submitted**. They do
  not synthesize it from any name/contact/user field.
- **Form field — `resources/views/corex/properties/show.blade.php:2823`** (show.blade is the live "New
  Property" form; create-edit is dead): the input is
  `<input name="unit_section_block" value="{{ old(...,$property->unit_section_block) }}" autocomplete="off">`
  — a **plain, un-bound text input** (not Alpine `x-model`), label **"Name of Unit, Section or Block"**,
  sitting inside the "Complex or Estate" address group. For a new property the rendered value is empty.

**Conclusion:** no CoreX server/data path writes a person's name into a property address column. Combined
with A.3 (the string exists nowhere in the DB as source), the **only remaining vector for
"Elizabeth Reichel" is client-side entry into that text input** — overwhelmingly **browser/profile
autofill** (Chrome routinely ignores `autocomplete="off"` on inputs whose label/name read as a *name*
field — "**Name** of Unit, Section or Block" is exactly such bait — and fills them from the saved profile,
here "Elizabeth Reichel"), with manual mis-keying the only alternative. This fits every observed fact:
the agent "did not enter" it (autofill did), it is a person's name in an address field (autofill
profiles hold names), it is **non-reproducible** (autofill is opportunistic/one-off), and **557 is the
only property in the entire DB that has ever held a person-name in `unit_section_block`** (DB-wide
regex scan for a two-word capitalised value returned only — now-cleared — 557).

### A.7 Is "Nautilus" a real scheme, and was 557 linked to it? (re-confirm §0/§6)

Yes — NAUTILUS is a real sectional scheme at 75 Marine Drive, Uvongo Beach (its CMA = `market_reports`
81). Property 557 was **not** FK-linked/match-or-created onto it; the §0 backfill copied the scheme-name
**string** into `complex_name`. No relational association exists.

### A.8 Revised root cause (supersedes the single-cause framing)

The "Unit 14, NAUTILUS — Elizabeth Reichel" the user saw on 557 is **two unrelated defects on one
record**, not one:
1. **"Elizabeth Reichel" → `unit_section_block`** = **client-side write** (browser autofill of a
   name-baited, un-bound text field; manual entry the only alternative). **Not** sourced from any CoreX
   record or server code path (A.3, A.6). Fix surface = the form field, not a service: set a real
   `autocomplete` token / `name` that Chrome won't name-match (and/or split the field so a freehold has
   no sectional inputs), plus route property writes through the audit observer so silent writes/clears
   are traceable.
2. **"NAUTILUS / 14" → `complex_name`/`unit_number`** = **§0 generator backfill** borrowing report 81 on
   a suburb-only match (unchanged — see §0 for the fix shape).

The earlier passes saw only #2 because the screenshot that exposed #1 ("Elizabeth Reichel") arrived in
Pass 4. Neither is a render/join bug.

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
