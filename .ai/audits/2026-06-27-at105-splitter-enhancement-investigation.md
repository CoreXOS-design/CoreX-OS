# AT-105 PDF Splitter — Pre-Enhancement Investigation

> Investigation only. No code changed. Staging (`/corex-staging`, `hfc_staging`).
> Date: 2026-06-27. Feeds the enhancement spec (assign split docs to contacts BY
> ROLE + multiple independent FICA processes per transaction).
> Spec of record: `.ai/specs/pdf-splitter-routing.md`.

---

## === 1. CURRENT AT-105 STATE ===

### Commits on Staging (HEAD = `b84b9234`)

| Commit | What |
|--------|------|
| `53a45445` | AT-105 build #5 — destination-aware routing + FICA wet-ink auto-kickoff |
| `168792e7` | **AT-105 FICA-trigger FIX** — toggle keys off CONTACT FICA state, not property compliance |
| `b84b9234` | docs: CHAT_STARTER (FICA-trigger fix + staging deploy notes) — HEAD |

### Is the FICA-trigger fix present? — YES.

`168792e7` is committed and sits at HEAD~2 on `origin/Staging` (local `Staging` and
`origin/Staging` are identical — both at `b84b9234`). The fix is live in the working
tree. Verified by reading the actual files, not just the diff:

- **searchProperties()** returns the seller's OWN FICA state per result —
  `app/Http/Controllers/Tools/PdfSplitterController.php:97-109`:
  ```php
  $seller = $p->sellerOwnerContact();
  $sellerFica = $seller ? $seller->ficaStatus() : null; // complete|expiring|incomplete|null
  ...
  'seller'      => $seller ? trim(...) : null,
  'seller_fica' => $sellerFica,
  ```
- **Review-screen toggle** defaults CHECKED when seller is not verified, driven by
  `seller_fica` (the CONTACT), never property compliance —
  `resources/views/tools/pdf_splitter_review.blade.php:621-634`:
  ```js
  get ficaIncomplete() { return this.hasSeller && this.prop.seller_fica !== 'complete'; },
  get checked() {
      if (!this.canTrigger) return false;
      return this.override === null ? this.ficaIncomplete : this.override;
  },
  ```

### The trigger condition as it currently stands

The server-side fire decision is `PdfSplitterController::confirm()` lines **475–495**.
It does NOT read property compliance at any point:
```php
if ($request->boolean('trigger_fica')) {                       // :475 agent toggle
    if (! $request->user()->hasPermission('access_compliance')) // :476 permission gate
        $ficaNote = '... you do not have compliance access.';
    elseif (! isset($outBySlug['fica']))                        // :478 FICA page must be in pack
        $ficaNote = '... no page ... labelled FICA Form.';
    elseif (! $sellerContact)                                   // :480 seller must resolve
        $ficaNote = '... no clearly linked seller/owner contact.';
    elseif ($agencyId <= 0)                                     // :482 agency must resolve
        $ficaNote = '... could not determine the agency.';
    else {
        $existing = $this->existingActiveFica($sellerContact);  // :487 dedupe by CONTACT
        if ($existing) { $ficaSubmission = $existing; $ficaReused = true; }
        else { $ficaSubmission = $this->kickoffWetInkFica(...); } // :492 create
    }
}
```

### Staging integrity after the parallel-session ride-along — CLEAN.

`git status` shows only untracked `.ai/audits/*` files (unrelated prior audits); zero
modified/half-applied AT-105 files. No merge markers, no conflicting AT-105 state.
`Staging` == `origin/Staging` == `b84b9234`. The four AT-105 runtime files
(`PdfSplitterController.php`, `pdf_splitter.blade.php`, `pdf_splitter_review.blade.php`,
plus the test) all carry the fixed code. The destination migration
(`2026_06_26_160000_add_destination_flags_to_agency_document_type_compliance.php`) is
present. No regression risk from the ride-along.

---

## === 2. THE SPLITTER ===

### Where it lives (file:line)

| Piece | Location |
|-------|----------|
| Controller | `app/Http/Controllers/Tools/PdfSplitterController.php` (1260 lines) |
| Upload/index view | `resources/views/tools/pdf_splitter.blade.php` (`index()` → `:52`) |
| Review view | `resources/views/tools/pdf_splitter_review.blade.php` (`review()` → `:291`) |
| Routes | `routes/web.php:762-768` (splitter), `:806-815` (doc-type settings) |
| Doc-type registry model | `app/Models/SplitterDocType.php` (table = `document_types`) |
| Destination service | `app/Services/Compliance/AgencyComplianceDocTypeService.php` |

Routes (all `permission:access_pdf_splitter`):
- `tools.pdf_splitter.index` GET `/tools/pdf-splitter` — `web.php:762`
- `tools.pdf_splitter.run` POST `/run` — `:763` (upload → OCR classify → manifest)
- `tools.pdf_splitter.review` GET `/review` — `:764`
- `tools.pdf_splitter.confirm` POST `/confirm` — `:765` (split → ZIP → file → FICA)
- `tools.pdf_splitter.thumb` GET `/thumb/{page}` — `:766`
- `tools.pdf_splitter.download` GET `/download` — `:767`
- `tools.pdf_splitter.properties.search` GET `/properties/search` — `:768`

### How it produces split documents

Two-step flow:
1. **`run()`** (`:113`) — stores upload under `private/splitter/originals/`, counts pages
   via `qpdf --show-npages` (`:716`), then `classifyPage()` (`:903`) per page: render page
   to PNG (`pdftoppm`), crop top 30%, OCR with Tesseract, keyword-score against
   **11 hard-coded doc-type keyword groups** (`:917-963`), pick by a **hard-coded priority
   order** (`:976-983`: mandate > offer_to_purchase > fica > ids > por > rates_taxes >
   body_corporate > house_rules > condition_report > listing_form > disclosure > other).
   Learned bigram phrase boosts from `pdf_splitter_learned_phrases` are layered on
   (`:966-974`, threshold 5 hits to activate). Writes a `manifest.json`; redirects to review.
2. **`confirm()`** (`:323`) — applies posted label overrides (whitelisted against active
   `docTypes()`), groups contiguous same-label pages into ranges (`groupRanges()` `:1012`),
   extracts each range with `qpdf --pages` (`:734`), merges multi-range buckets with
   `pdfunite` (`:747`). Output files named `{base}__{label}.pdf`. Only labels with ≥1 page
   produce a file. Bundles all into a ZIP + `summary.txt`.

**Page ranges:** contiguity-based — a bucket can be several ranges, united into one PDF.
**Doc-type assignment:** OCR keyword score → label slug per page, agent-overridable in review.

### The `splitter_doc_types` table — NOTE: renamed to `document_types`

There is **no `splitter_doc_types` table anymore.** Migration
`2026_03_24_100001_rename_splitter_doc_types_to_document_types.php` renamed it to the
global `document_types` catalogue and added a `grouping` column (`:24`,
default `'shared'`). The model `SplitterDocType` (`app/Models/SplitterDocType.php:13`)
points at `protected $table = 'document_types'`. `DocumentType` is a second model on the
same table.

**Columns:** `id, slug, label, sort_order, is_active, grouping (varchar 20), listing_types
(json, added 2026_04_01_100001), timestamps, deleted_at (SoftDeletes)`.

**Seeded grouping** (`rename` migration `:28-37`):
- `grouping='contact'` → `fica, ids, por`
- `grouping='property'` → `condition_report, rates_taxes, body_corporate, house_rules`
- everything else (`mandate, offer_to_purchase, listing_form, disclosure, other`, plus
  merged docuperfect/rental types) → `grouping='shared'` (defaults to property destination).

The 11 splitter buckets are the OCR keyword set in `classifyPage()` (`:917-963`);
`docTypes()` (`:43`) reads active rows `pluck('label','slug')` to drive dropdowns/shortcuts.

### Review/result UI

The post-Split screen is `pdf_splitter_review.blade.php`. It shows a per-page table:
thumbnail + page#, the auto-label badge, an override `<select>`, the non-zero OCR scores,
and the OCR snippet (`:362-418`).

- **Top "shortcuts":** the keyboard-shortcut legend (`:314-328`, `$keyMap` `:14-27`) and a
  bulk **toolbar** (`:330-346`) — "set selected → {type} / Apply", "Reset selected",
  "Set ALL → Other". Above those sit two **AT-105 cards**: the optional **property picker**
  (`:223-267`, typeahead → `properties.search`) and the **FICA auto-kickoff toggle**
  (`:275-312`, only if `$canFica`).
- **Bottom button(s):** `:422-427` — a single submit button **"ZIP" / "ZIP & Link"**
  (label flips when a property is selected) plus a **"← Upload a different PDF"** back link.
- **The download link:** does NOT live on the review screen. `confirm()` redirects to the
  index page and stashes `splitter_download_url`; the index page auto-triggers a hidden-iframe
  download via `tools.pdf_splitter.download` (`downloadLastZip()` `:1244`). The index page
  also renders the **filing status banner** (`session('status')`) and the **FICA banner**
  (`pdf_splitter.blade.php:144-167`: started / reused / note variants with an "Open the FICA
  verification to finish →" link).

### Current destination routing — how "Contact" is resolved

The Contact is **DERIVED, not selectable.** `confirm()` (`:456`) calls
`$linkedProperty->sellerOwnerContact()`, then `linkOutputsToDestinations()` (`:548`) files
each output per the agency's per-type "Save To" config.

Resolution code — `app/Models/Property.php:415-434`:
```php
public function sellerOwnerContact(): ?Contact
{
    $contacts = $this->contacts()->get();
    if ($contacts->isEmpty()) return null;

    $sellerSide = ['seller', 'owner', 'landlord', 'lessor'];
    $match = $contacts->first(function ($c) use ($sellerSide) {
        $role = strtolower(trim((string) ($c->pivot->role ?? '')));
        return in_array($role, $sellerSide, true);
    });
    if ($match) return $match;

    return $contacts->count() === 1 ? $contacts->first() : null;   // sole-contact fallback
}
```
**Single-valued.** Returns the FIRST seller-side contact, or (if none seller-side) the sole
linked contact, or `null` when there are multiple contacts and none is seller-side. It does
NOT surface co-sellers/joint owners. This is the crux the enhancement must replace.

Filing (`PdfSplitterController.php:548-624`): per output, create ONE `Document`
(`source_type='pdf_splitter'`, `source_id=property->id` provenance `:594`), then attach to
property and/or contact per `destinationForSlug()`. Contact attach uses the
`document_contacts` pivot with **`party_role`** derived from `$contact->pivot->role ?: 'seller'`
(`:566`, `:609`). **No-orphan fallback** anchors to the property when the destination can't be
honoured (`:617-620`). All splitter-doc-type → destination logic is per-agency.

---

## === 3. PROPERTY ↔ CONTACT MODEL ===

### Link + role

Pivot table **`contact_property`** —
`database/migrations/2026_03_05_200001_create_contact_property_table.php`:
- `contact_id` (`:13`), `property_id` (`:14`), **`role` string(50) NULLABLE** (`:15`,
  comment "owner,buyer,tenant"), timestamps.
- **`unique(['contact_id','property_id'])`** (`:18`) — unique on the PAIR only, NOT on role.

Relationships (both expose `withPivot('role')`):
- `Property::contacts()` — `app/Models/Property.php:396-401` (belongsToMany).
- `Contact::properties()` — `app/Models/Contact.php:307-312` (belongsToMany).

**No role-scoped helpers exist** (`sellers()`, `buyers()`, `owners()`, …) on Property/Contact.
The only such helpers are on the **separate** `DealV2` model
(`app/Models/DealV2/DealV2.php:227-235`, on `deal_v2_contacts`, NOT `contact_property`). On
`contact_property`, role filtering is always inline `->wherePivotIn('role', [...])` or
in-memory on `$c->pivot->role`.

**Allowed roles** (app-layer only, no DB enum):
- Validation allow-list — `app/Http/Controllers/CoreX/PropertyContactController.php:129`:
  `const LINK_ROLES = ['seller','buyer','owner','landlord','tenant','lessor'];`
- "Seller side" bucket used everywhere — `['owner','seller','landlord','lessor']`
  (`Property.php:423`, `PropertyContactController.php:174/226/282/346`,
  `MobilePropertyController.php:793`, `MarketingReadinessService.php:111/340/413/421`,
  `CalendarController.php:1392`, …).
- **Gotcha:** the esign-type auto-link maps a "seller" contact-type to pivot role **`owner`**
  (`MobilePropertyController.php:963`, migration `2026_03_31_100000:10-15`:
  `seller→owner, lessor→lessor, buyer→buyer, lessee→tenant`). So code testing "is this the
  seller?" must test the whole seller-side SET, never just `'seller'`. Roles can also be
  NULL / mixed-case on historical rows (the reason `BackfillContactPropertyRoles` exists) —
  always `strtolower(trim())` before comparing.

### Multiple contacts in the same role? — YES, definitively.

The only unique constraint is `(contact_id, property_id)`. **`role` is in no unique index.**
Two different contacts can both hold `role='seller'` (or `'buyer'`) on one property — joint
sellers / joint buyers are fully supported by the schema. (A single contact can hold only ONE
role per property — one pivot row per pair.) `sellerOwnerContact()`'s multi-contact handling
and the backfill command's "which is the seller?" comment both presuppose this.

### Resolving contacts by role in code

No helpers → use the existing `contacts()` relation with pivot filtering (the established
pattern, e.g. `CalendarController.php:1392`):
```php
$sellers   = $property->contacts()->wherePivotIn('role', ['owner','seller','landlord','lessor'])->get();
$buyers    = $property->contacts()->wherePivot('role', 'buyer')->get();
$tenants   = $property->contacts()->wherePivot('role', 'tenant')->get();   // legacy 'lessee' may exist
$landlords = $property->contacts()->wherePivotIn('role', ['landlord','lessor'])->get();
```
**Note:** no existing `contact_property` call site filters specifically for `buyer` or
`tenant` today — buyer/co_buyer filtering currently only exists on the DealV2 pivot. The
enhancement is the first consumer of buyer-side resolution on `contact_property`.

---

## === 4. THE FICA WET-INK PROCESS ===

### Where it lives

- Shared creator: `app/Services/Compliance/FicaWetInkService.php` (`create()` `:39-77`) —
  AT-105 extracted this from `FicaController::storeWetInk` so manual intake AND the splitter
  use ONE creator (no fork). Manual controller: `app/Http/Controllers/Compliance/FicaController.php`
  (`storeWetInk()` `:173-220`, `show()` `:225-232`).
- Models: `app/Models/FicaSubmission.php`, `app/Models/FicaDocument.php`.
- Migration: `database/migrations/2026_03_26_100000_create_fica_tables.php`.
- Route `compliance.fica.show` → `routes/web.php:1609` (`/compliance/fica/{submission}`,
  group `permission:access_compliance` + `agency.required`), backed by `FicaController::show`.

### How a FICA process is created / what it pre-fills

`FicaWetInkService::create(Contact $contact, int $agencyId, array $opts = [])` sets
(`:55-76`): `contact_id` (REQUIRED), `agency_id` (REQUIRED), `requested_by`,
`status` (default `'submitted'`), `intake_type='wet_ink'`, `entity_type` (default
`'natural'`), `wet_ink_received_date` (default today), `wet_ink_confirmed_by`, `signed_at`,
and a `form_data` JSON snapshot. It sets **NO property_id / deal_id** (those columns do not
exist — see below).

Document slots are attached SEPARATELY (not by `create()`):
- `addUploadedDocument()` `:82-97` (manual UploadedFile path).
- `addStoredDocument()` `:104-132` (splitter path — copies bytes from a stored file).
- Slots used: **`fica_form`, `id_copy`, `proof_of_address`, `supporting`** (FicaDocument
  `document_type` is a free VARCHAR(50), `2026_03_26_100000:40`; labels enumerated in
  `FicaDocument::getDocumentTypeLabelAttribute()` `:45-59`).

### How AT-105 hands off to it

`PdfSplitterController::kickoffWetInkFica()` (`:653-680`) — quote:
```php
$submission = $service->create($contact, $agencyId, ['source' => 'pdf_splitter']);
$slotMap = ['fica' => 'fica_form', 'ids' => 'id_copy', 'por' => 'proof_of_address'];
foreach ($slotMap as $slug => $slot) {
    if (isset($outBySlug[$slug]) && is_file($outBySlug[$slug]))
        $service->addStoredDocument($submission, $outBySlug[$slug], basename(...), $slot);
}
// ...then, after the transaction:
$service->fireSubmitted($submission, $contact, auth()->id());   // fires FicaSubmitted (domain event)
```
The redirect stashes `splitter_fica_url = route('compliance.fica.show', $ficaSubmission)`
(`confirm()` `:526`), surfaced as the index banner.

### THE FICA AUTO-LOAD BUG — diagnosis (do not fix)

**Status: ROOT-CAUSED and FIXED in `168792e7`. Currently present in Staging.**

What Johan observed: a FICA pack split filed correctly (mandate/house_rules → property,
fica/ids → contact) but **the wet-ink FICA never started.**

**Actual root cause (from the fix commit + code):** it was NOT the property-vs-contact-state
bug that the framing suspected. The trigger never read property compliance at all. The real
cause was a **UX/default bug in the toggle**: in build #5 the FICA checkbox was
*property-selected-enabled but defaulted UNCHECKED* and gave the agent no signal, so on
Generate `trigger_fica` was false → `confirm():475` short-circuited → no FICA. The agent
simply never ticked it. The P24 "auto-marked compliant" property snapshot was a red herring —
it was never in the trigger path.

**The fix (now live):** drive the checkbox default off the **CONTACT's own FICA state**
(`seller_fica` from `searchProperties()`), defaulting CHECKED when the seller is
incomplete/expiring (`pdf_splitter_review.blade.php:630-633`), so the common case fires
automatically on Generate. Verified end-to-end:
`searchProperties` returns `seller_fica` (`:97-109`) → toggle `checked` getter
(`review:630`) → `confirm()` `$request->boolean('trigger_fica')` (`:475`) →
`kickoffWetInkFica` (`:492/653`) → `FicaSubmitted` + banner. Trace is intact; no break remains.

**Residual conditions that can still legitimately suppress auto-load** (by design, surfaced
as a `ficaNote`, NOT bugs — but relevant to the enhancement):
1. User lacks `access_compliance` → toggle not even rendered (`review:275`, `$canFica`).
2. `sellerOwnerContact()` returns `null` — property has **multiple linked contacts and none is
   seller-side** (`Property.php:432`), or no contacts. Toggle shows "no clearly linked
   seller/owner" and disables (`review:292-294`; server `confirm():480`).
3. The FICA page wasn't labelled `fica` by OCR/agent → server blocks with "no page labelled
   FICA Form" (`confirm():478`).
4. `agencyId <= 0` (`confirm():482`).
5. **Alpine `:checked` binding** — the checkbox uses `:checked="checked"` + `@change`
   (`review:279-282`). This renders correctly server-trace-wise, but the actual submitted
   value when the box is auto-checked-but-never-clicked should be confirmed in a real browser
   (cannot be verified statically here). Flagging as a manual-QA item, not a known defect.

### Multiple independent FICA processes per transaction? — supported at the data layer, but UNLINKED.

`fica_submissions` is keyed to a **CONTACT only** — `contact_id` is a plain index, NOT unique
(`2026_03_26_100000:13,34`); the only `unique()` is `token` (`:16`). There is **NO
`property_id` and NO `deal_id`** column (confirmed across all `*fica*` migrations), and no
property/deal relationship on the model. `Contact::ficaSubmissions()` is a plain `hasMany`
(`Contact.php:246-249`).

⇒ **N concurrent, independent FICA processes per transaction are fully supported** (seller-
contact FICA + buyer-contact FICA = two independent rows, no conflict; even two for the same
contact succeed — no unique guard). **BUT the model has no concept of "the FICA for THIS
transaction"** — FICA is purely contact-centric. To kick off seller-FICA + buyer-FICA per
property, the enhancement must either:
- (a) drive off the property's linked contacts (`contact_property` pivot by role) with **no
  schema change**, accepting that no row records which property a submission belongs to; or
- (b) add a `property_id` / `deal_id` (or polymorphic) linkage column to `fica_submissions`.

Dedupe today is **per-contact**: `existingActiveFica()` (`PdfSplitterController.php:691-698`)
checks open submissions for that contact only — correct for multi-contact (each contact
independent), but it has no per-property awareness.

`Contact::ficaStatus()` (`Contact.php:256-286`) collapses ALL of a contact's submissions to
one string (`complete|expiring|incomplete`); **only `status='approved'` counts as verified**
(`:260`), `expiring` at ≥11 months via `diffInMonths` (`:266`). It is contact-global —
a per-transaction view would need its own logic.

---

## === 5. DOC-TYPE SETTINGS ===

### The settings screen

`resources/views/admin/splitter/doc-types.blade.php`, controller
`app/Http/Controllers/Admin/SplitterDocTypeController.php`. Reachable two ways (same
controller/view, `$context` switch):
- Splitter route: `admin.splitter.doc-types.index` (`web.php:806`, `permission:access_pdf_splitter`).
- Settings route: `admin.settings.document-types.index` (`web.php:813`, `permission:access_settings`).

### Configurable per type today

Per row (bulk-saved via `bulkSave()` `:89-135`):
- `label`, `slug` (auto from label, read-only), `sort_order`, `is_active` (Yes/No).
- `listing_types[]` (sale/rental → controls which Drive folders appear).
- **AT-105 "Save To"** — two independent checkboxes **Property** + **Contact** (either / both /
  neither), `blade:197-215`, persisted by `AgencyComplianceDocTypeService::setDestination()`
  (`:179-191`).
- **Compliance required** checkbox (per-agency), `setRequired()` (`:56-67`).

The catalogue (`document_types`) is **global**; the per-agency config (compliance flag +
Save-To destinations) lives on **`agency_document_type_compliance`**. Destination resolution:
`destinationForSlug()` / `destinationMapFor()` merge the stored nullable flags over the
grouping-derived default (`AgencyComplianceDocTypeService:100-172`;
`defaultDestinationForGrouping()` `:85-93`: grouping=contact → contact, else → property).

### Room for per-type `contact_role` and `fica_slot`? — YES, cleanly.

Two clean insertion points, mirroring exactly how AT-105 added `save_to_property` /
`save_to_contact`:

1. **Global catalogue defaults** — add columns to `document_types` (alongside `grouping`,
   `listing_types`). E.g. `default_contact_role` (varchar) and `fica_slot` (varchar:
   `fica_form|id_copy|proof_of_address|…`). Add to `SplitterDocType::$fillable`
   (`SplitterDocType.php:15`) and the `bulkSave` validation/update (`:91-119`).
2. **Per-agency overrides** — add nullable columns to `agency_document_type_compliance`
   (the AT-105 pattern: nullable = "use catalogue default"), resolved through
   `AgencyComplianceDocTypeService` exactly like the Save-To flags. `setDestination()`/
   `destinationForSlug()` are the templates to copy.

Both tables take new columns cleanly (plain `Schema::table(...)->after(...)` migrations; the
service already isolates resolution). The `bulkSave` payload pattern (unchecked = absent →
`filter_var(... ?? false)`) extends directly. Remember to `php artisan schema:dump` after the
migration (non-negotiable #12a).

**Architectural caveat for the spec:** today the splitter hard-codes the FICA slug→slot map
in `kickoffWetInkFica()` (`PdfSplitterController.php:658-662`:
`fica→fica_form, ids→id_copy, por→proof_of_address`) and the contact `party_role` defaults to
the seller's pivot role / `'seller'` (`:566`). A per-type `contact_role` + `fica_slot` config
would replace BOTH hard-codes — that is the natural home for the role-routing enhancement.

---

## Things I could NOT determine statically (flag for QA / spec)

- Whether the auto-checked-but-never-clicked FICA checkbox actually submits `trigger_fica=1`
  in a live browser (Alpine `:checked` binding, `review:279-282`). Needs a real-browser click-
  through test — cannot be proven from code.
- Whether legacy `lessee` (vs canonical `tenant`) role rows exist in `hfc_staging` data.
- Exact live seeded `document_types` rows on `hfc_staging` (read the schema/migrations, not the
  live table) — confirm before relying on any non-core slug existing.

---

## One-line conclusions for the enhancement spec

1. **AT-105 + FICA-trigger fix are both live and clean on Staging** (`b84b9234`). The
   "FICA didn't auto-load" bug was a toggle-default UX bug, already fixed by keying the default
   off the contact's `ficaStatus()`.
2. **`sellerOwnerContact()` is single-valued and null-on-ambiguity** — to assign docs to
   contacts BY ROLE (incl. joint parties + buyers), replace it with role-scoped
   `contacts()->wherePivotIn('role', …)` collection queries. No role helpers exist yet.
3. **`document_contacts.party_role`** is the existing per-attachment role channel (distinct
   from `contact_property.role`) — the splitter already writes it (`:609`).
4. **FICA supports N independent processes per transaction**, but `fica_submissions` has no
   property/deal linkage — the enhancement must add it (option b) or drive off
   `contact_property` (option a, no schema change).
5. **Per-type `contact_role` + `fica_slot`** belong on `document_types` (catalogue default) +
   nullable overrides on `agency_document_type_compliance`, resolved via
   `AgencyComplianceDocTypeService` — exactly the AT-105 Save-To pattern.
