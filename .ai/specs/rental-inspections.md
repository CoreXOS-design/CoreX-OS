# Rental Inspections — move-in / move-out condition reports, evidence, and deposit consequence

**Status:** Spec — awaiting Johan's sign-off. No code written against this spec.
**Date:** 2026-09-13
**Author:** Claude (cc4)
**Pillars:** Property (`Property`) and Contact (`Contact`) — reads from both, writes back to both.
**Commercial context:** this module, together with Rental Applications, is what Johan is pitching to a
prospective agency this week. It is not a Monday nice-to-have.

---

## 0. What already exists — read directly from the live code, twice corrected, now precise

Two rounds of correction on this spec, both real and both worth recording: first, that a
"Rental" tab already exists for lease/party information; second, that the "Rental Images" tab
already has in-inspection and out-inspection photo upload. Both are true. Leading with exactly
what's there and exactly what's missing, per instruction — this is now a smaller spec than
either earlier draft, and that's the right direction for it to move in.

### 0.1 The "Rental" tab — lease terms, no parties (unchanged finding from the first draft)

Built and live (`rentals-shared-screens.md` §11, `Property.php`, `show.blade.php`'s Rental tab).
Holds: `rental_amount`, `deposit_amount`, `lease_start_date`, `lease_end_date`, `lease_period`
(free text), `lease_type` (a commercial-lease enum), `price_per_day/week/year`, `has_deposit`,
`commission_percent`, `admin_fee`, `marketing_fee`, `furnished_status`, `occupation_date`,
`water/electricity/levies_included`. All real, saved columns — not cosmetic.

**Missing, precisely**: no landlord field, no tenant field, no link to a `Contact` anywhere on
this tab. Separately — NOT on this tab, but real and already working — `PropertyContactController::
LINK_ROLES` (`app/Http/Controllers/CoreX/PropertyContactController.php:86`) already includes
`landlord` and `tenant` as linkable roles via the property's **Contacts tab**, a different tab
again. So a landlord and a tenant CAN already be linked to a property as real Contacts today —
just not from the Rental tab, and the link itself carries no start/end date and no "this one is
current" marker (`syncWithoutDetaching()` allows more than one contact holding `tenant`
simultaneously with nothing to say which is active). Johan's "which property is leased to whom,
when are they moving out" is therefore answerable TODAY only by combining two different tabs by
hand, with no history once a tenant changes.

### 0.2 The "Rental Images" tab — in/out photo upload already exists, confirmed live in code

Built and live (`.ai/specs/rental-images.md`, `Property.php:1858-1904`,
`PropertyController::uploadRentalImages/saveRentalImagesMeta/deleteRentalImage`). Verified
directly against the CURRENT model code, not the original 2026-06-24 spec alone (specs can go
stale; this was checked fresh):

```php
// Property::rentalImagesStructure() — Property.php:1865-1904
'in_inspection'  => ['date' => ..., 'images' => [ /* flat array of URL STRINGS */ ]],
'out_inspection' => ['date' => ..., 'images' => [ /* flat array of URL STRINGS */ ]],
'custom'         => [ ['id', 'name', 'date', 'images'] , ... ],
```

Confirmed by reading the actual filter (`array_filter(..., 'is_string')`, `Property.php:1881-1884`):
**every image is a plain URL string. No per-image id, no space/room tag, no order beyond array
position, no metadata of any kind.** One date per section, not per photo. Who can upload: any
user with ordinary property-edit access within their own agency scope (`authorizeProperty()`,
the same guard the rest of the property screen uses) — there's no separate "only the agent
assigned to this tenancy" gate, and no party/signature concept at all. A full working mobile API
mirrors this exact shape (`MobileRentalImagesController`), gated by
`Property::rentalInspectionsAvailable()` (rental listing type AND currently on-market).

**Missing, precisely**: everything Johan actually wants compared per space. There is no
concept of a "space" anywhere in this data — In Inspection and Out Inspection are each one flat
bucket of photos for the WHOLE property, not per-room. No condition checklist, no notes, no
signatures, no report, no deposit consequence. No link to a specific tenancy either — if a
property gets a new tenant, the SAME `rental_images_json` column holds whatever's there; nothing
distinguishes "this tenant's in-inspection" from a previous tenant's.

### 0.3 THE KEY QUESTION — can existing in/out photos be given a space retroactively? No.

Checked directly against the live filter above: an entry in `images[]` is a bare string, nothing
else. There is no photo-level record to attach a `space_id` to — assigning an existing photo to
a space would require a HUMAN looking at each one and deciding, because the system has never
recorded which room it was taken in. **This spec does not attempt that automatically.** See §4.4
for exactly what happens to photos already sitting in `rental_images_json` when this ships —
they are not deleted, not silently hidden, and not force-migrated; they become a clearly labelled
legacy view.

### 0.4 What this spec adds, and where

Given both tabs already exist and hold real, live data: this spec is now about giving
**structure** to the photo side (space-grouping, checklist, comparison, report, deposit
consequence, signatures) and **completing** the party side (linking a tenancy's dates to its
actual landlord and tenant, in one place) — not building a module from nothing. See §2
(dependency — the party/date gap) and §10.1 (where the new inspection UI lives — expanding the
Rental Images tab in place, per Johan's own instruction, not a new tab).

---

## 1. Goal

Johan, verbatim: *"a list of rooms created from the spaces on the advertising... link the
photos from the in inspection to the in inspection per space... when a tenant moves out the
agent can again go and do the out inspection from the same form... allow the agent to upload
photos to the same space... a report that shows a space — bedroom 1 — shows list of in
inspection and what was selected, and photos, and then out inspection shows list of same
photos plus out inspection photos and report of space next to each other."*

One inspection **form**, used twice per tenancy (in, then out), organised by **space**
(Bedroom 1, Bedroom 2, Kitchen, Domestic Bathroom, ...), each space carrying a condition
checklist, free notes, and photos. The out-pass shows the in-pass answers while the agent
works, so she compares rather than remembers. The finished **report** lays both passes side
by side per space — this is the artefact that settles a deposit argument, and is designed as
evidence, not as a screen.

---

## 2. THE REMAINING DEPENDENCY — the party/date gap, smaller than first stated

An inspection needs to know: **which property, whose tenancy, which landlord, which tenant,
when it started, when it ends.** Per §0.1, the Rental tab already holds the dates and the
Contacts tab already supports real landlord/tenant Contact links — this is genuinely smaller
than either earlier draft of this spec assumed. What's still missing is that **three separate,
disconnected mechanisms exist, and none of them alone is "the tenancy record" that ties dates
and parties together as one addressable thing** — this is the gap, stated precisely, item by
item (A and B restate §0.1 for completeness; C is additional, found by investigation, not
mentioned by Johan):

| # | What exists today | Where | What it has | What it's missing |
|---|---|---|---|---|
| A | `Property.lease_start_date` / `Property.lease_end_date` | The property's own **Rental tab** (`rentals-shared-screens.md` §11, built and live) | Dates. Single value per property. | **No landlord or tenant on it at all.** Overwritten on every save — no history. When a new tenant moves in, the OLD tenancy's dates are gone, along with any inspection's ability to say which dates it belonged to. |
| B | `property_contact` pivot, `role ∈ {landlord, tenant, lessor, owner, ...}` (`PropertyContactController::LINK_ROLES`) | The property's **Contacts tab** | Real `Contact` FKs for landlord AND tenant — this already works today. | **No start/end date on the link itself.** `syncWithoutDetaching()` means nothing stops two contacts holding "tenant" on the same property at once, and nothing marks which one is *current*. No concept of "this tenancy, that tenancy." |
| C | `App\Models\Docuperfect\LeaseRecord` | Auto-created by `SignatureService::createLeaseRecord()` whenever a lease/rental DocuPerfect document is fully signed | Dates, a real status lifecycle (active → expiring_soon → expired/renewed/terminated), a working expiry-alert command (`CheckLeaseExpiry`) — Johan's "when escalations are due" already runs, just here. | `tenant_name`/`landlord_name` are **plain strings, not Contact FKs**. No `agency_id` column (a known, already-flagged security gap — scope is derived indirectly through the signed document). **Never referenced anywhere on the Property screen** — reachable only via a separate "Rental Division" sidebar item nobody would think to check from a property. |

**None of A, B, or C is "the tenancy."** A has dates but no parties. B has parties but no
dates and no "current" concept. C has both but is a different Contact-less system entirely,
bolted to e-signed lease documents, invisible from the property itself.

### 2.1 What this spec needs, and recommends — not built here

A single new, small, additive record: **`PropertyTenancy`** — `property_id`,
`landlord_contact_id`, `tenant_contact_id`, `start_date`, `end_date`,
**`escalation_date`** (nullable — Johan named this explicitly as commercially critical and
nothing today captures it; `lease_period`/`lease_type` are free-text/enum, neither is a real
date), `status` (active/ended), soft-deletes. One property can have many `PropertyTenancy`
rows over its life (history), but only one `active` at a time (enforced at the write path, not
just convention). This is the record an inspection points at.

**Explicitly not proposed:** touching `LeaseRecord` (C) or the `property_contact` pivot (B).
Both keep doing what they already do. `PropertyTenancy` is additive, populated going forward
from the Rental tab + Contacts tab UI (a small, separate, focused piece of work — a real
"parties" section added to the existing Rental tab, per Johan's own framing: *"its the lease /
parties / and whatever else that we can put on that rental tab"*). Reconciling A/B/C into one
system long-term is a real, larger decision — flagged for Johan, not decided or built here.

**Inspections cannot ship without `PropertyTenancy` existing.** This is the one dependency
this whole spec rests on. If Johan wants a different shape for the parties/dates work landing
on the Rental tab, that shape — not a new one invented here — is what Inspections should point
at; the field names above are a proposal, not a requirement.

---

## 3. Pillar connections

- **Property** — an inspection belongs to a property, is reached from the property record, and
  its space list is seeded from the property's own advertised layout.
- **Contact** — an inspection's two signing parties resolve to Contacts via `PropertyTenancy`
  (landlord → **Lessor**, contact type id 10; tenant → **Tenant**, contact type id 11 — neither
  is a clean 1:1 with the English words "landlord"/"tenant," flagged explicitly here per
  `ContactType::CANONICAL`/`ADDITIONAL_PARENTS` — this is a naming choice worth Johan's
  confirmation, not assumed silently).
- **Deal** — not directly connected. A future "tenant won" trigger (already specced
  separately, `rentals-shared-screens.md` §5) is the natural place a `PropertyTenancy` would
  eventually get created automatically; out of scope here.

---

## 4. Data model

### 4.1 Spaces — seeded, then fully editable

Johan's correction, verbatim: *"we need to allow adding spaces for inspections. it will vary
from advertising... same as water and electricity meters. we need it on the inspection but we
wont have it on advertising... Advertising is a starting point, never the constraint."*

**Source data already exists**: `Property.spaces_json` (`config/property-spaces.php` for the
vocabulary — "Domestic Bathroom" is already in that list, contrary to Johan's own example;
"Water meter"/"Electricity meter" are not). The existing shape is `{type, count, units[]}` per
space TYPE (e.g. `{"type":"Bedroom","count":3,...}`) — no individually-named rooms ("Bedroom
1"/"Bedroom 2" don't exist anywhere today).

**Seeding an inspection**: when the FIRST inspection (the in-pass) is created for a
`PropertyTenancy`, expand the property's `spaces_json` into one named `InspectionSpace` row
per unit (Bedroom×3 → "Bedroom 1", "Bedroom 2", "Bedroom 3"), in the order the property lists
them. This is a one-time copy, not a live link — editing the property's advertised spaces
afterward never retroactively changes an in-progress or completed inspection.

**Agency default template** — a new agency-level setting, `RentalInspectionSettings` (same
pattern as `RentalApplicationQualifyingSetting` — one row per agency, static `xFor()` readers
with a hardcoded fallback default), holding an ordered list of **template spaces always
added** regardless of what's advertised: Domestic Bathroom, Water Meter, Electricity Meter,
Garage, Garden, Outbuildings (the exact default list; agency-editable, never hardcoded into a
controller). These are appended after the seeded advertised spaces, not instead of them.

**Then fully editable**: add, rename, reorder (a simple sort-order integer column, no
drag-library dependency implied by this spec — a Blade/Alpine up/down control is enough), and
remove (soft-delete only — a removed space's already-recorded in-pass data must survive for
the out-pass/report, so "remove" here means "hide from the active space list," never delete
recorded answers). This editing happens once, when the in-pass is first opened, before or
during recording — not a separate settings-Alpine-page.

```
inspection_spaces
  id
  rental_inspection_id (FK)
  name                    -- "Bedroom 1", "Domestic Bathroom", "Water Meter"
  sort_order
  source                  -- enum: advertised | template | manual  (audit: where did this space come from)
  deleted_at              -- soft delete = "hidden from the active list", never a hard remove
```

### 4.2 The inspection itself

```
rental_inspections
  id
  agency_id                       -- BelongsToAgency, standard global scope
  property_id (FK)
  property_tenancy_id (FK)        -- see §2.1 — the dependency
  type                             -- enum: in | out
  status                           -- enum: draft | in_progress | completed | signed
  started_at, completed_at
  created_by_user_id
  -- signing (see §6):
  signature_template_id (FK, nullable until signing starts)
  signed_at
  deleted_at                       -- soft delete only
```

One `rental_inspections` row per pass (in, out) per tenancy — NOT one row holding both passes.
The "same form" Johan describes is a UI/UX property (the out-pass form reads and displays the
in-pass's own row alongside itself), not a shared database row — an in-pass and its out-pass
must be independently completable, independently signed, and one must never overwrite the
other's answers.

### 4.3 Per-space record — checklist, notes, photos

```
inspection_space_entries
  id
  rental_inspection_id (FK)       -- which pass (in or out)
  inspection_space_id (FK)        -- which space
  condition_json                   -- the checklist answers, shape below
  notes                            -- free text
  created_at, updated_at
```

`condition_json` shape — a flat map of checklist item → selected condition, e.g.:
```jsonc
{ "walls": "good", "floor": "fair", "ceiling": "good", "fixtures": "damaged", "windows": "good" }
```
The checklist ITEMS per space (walls/floor/ceiling/fixtures/windows, etc.) are an
agency-configurable list (`RentalInspectionSettings`, same pattern as the space template
default), not hardcoded — a domestic bathroom's checklist items may reasonably differ from a
bedroom's, but this spec proposes ONE shared default checklist to start (simplicity first);
per-space-type checklists are a clearly-labelled future refinement, not built here.

### 4.4 Photos

```
inspection_photos
  id
  inspection_space_entry_id (FK)
  storage_path, disk
  original_name
  width, height                    -- NEW: nothing in this codebase's existing photo models
                                    --      captures this today (confirmed — see §8)
  orientation                      -- NEW: same
  captured_at                      -- client-declared, the phone's own clock (see §7 offline model)
  client_upload_id                 -- idempotency key, mirrors mobile_photo_events' own pattern
  sort_order
  created_at
  deleted_at                       -- soft delete only
```

Reuse the property/gallery image pipeline for storage (downscale, JPEG re-encode) — but this
table is genuinely new, not another `*_json` column on `properties`, because a photo here
needs its own identity (for the report's side-by-side pairing, for the offline sync contract,
and for the mobile ghost-image feature — see §8) that a flat JSON array of URLs cannot carry.

#### 4.4.1 What happens to photos already sitting in `rental_images_json` — answered plainly, per Johan's own question

Confirmed in §0.3: an existing in/out photo is a bare URL string with no space, no id, no
metadata — there is nothing to automatically re-home it into `inspection_photos`. **Nothing
here is deleted, hidden, or silently migrated.** When a property's structured inspection UI
(§10.1) is opened for the first time:

- Any existing `rental_images_json.in_inspection`/`out_inspection` photos render in a clearly
  labelled **"Photos from before structured inspections"** block, once, above the new per-space
  layout — visible, downloadable, exactly as they are today.
- They are NOT counted in the new per-space report (§6) — there is no space to put them in, and
  guessing would be worse than leaving them out and labelled.
- A one-time, OPTIONAL "sort these into spaces" tool (an agent manually drags/assigns each
  legacy photo to a space, converting it into a real `inspection_photos` row) is a genuinely
  useful future addition, named here so it isn't lost, but **not built in this spec** — v1 ships
  with the legacy block read-only, and a property with no legacy photos never sees the block at
  all.
- Custom ad-hoc sections (§0.2, unrelated to in/out) are entirely untouched by any of this.

### 4.5 Deposit consequence

```
inspection_damage_items
  id
  rental_inspection_id (FK)        -- always an OUT-pass row; an in-pass has none
  inspection_space_entry_id (FK)
  description
  estimated_cost                    -- nullable — an agent may flag damage before pricing it
  photo_ids                         -- which inspection_photos back this specific claim
  created_at, updated_at
  deleted_at
```

See §6 for how this feeds an actual deduction.

---

## 5. THE FORM

One form, two passes, per Johan's own framing.

**In-pass** (`type = in`, on a brand-new `PropertyTenancy` with no prior inspection):
- Space list rendered per §4.1 (seeded + template, editable inline: add/rename/reorder/hide).
- Per space: the condition checklist, a free-notes field, a photo uploader (multiple photos,
  captured via phone camera or file picker).
- Saves progressively per space (not one giant submit at the end) — an agent walking room to
  room should never lose the prior room's work because the last one wasn't finished.

**Out-pass** (`type = out`, created against the SAME `PropertyTenancy`, once its in-pass is
`completed` or `signed`): the SAME space list (copied from the in-pass's own
`inspection_spaces`, not re-seeded from the property — the property's advertised layout may
have changed since move-in, and the out-pass must inspect what was actually recorded at move-
in, not what's advertised today). Per space, **the in-pass's own checklist answer, notes, and
photos are shown alongside the space** while the agent records the out-pass — Johan's own
words: *"so she is comparing rather than remembering."* This is read-only reference data on
this screen, never editable from here (the in-pass stays exactly as it was signed).

**Both passes**: mobile-first (see §7/§8 — most inspections happen from a phone standing in an
empty flat), but the same form renders on desktop for an agent finishing up at her screen
without a strict device requirement.

---

## 6. THE REPORT

Johan: *"a space — bedroom 1 — shows list of in inspection and what was selected, and photos,
and then out inspection shows list of same photos plus out inspection photos and report of
space next to each other."*

Per `PropertyTenancy`, once BOTH passes exist (out-pass may be `in_progress` — a partial report
is still useful, clearly labelled "Out inspection in progress" rather than hidden until
complete): one document, one row per space, two columns (In | Out):

| Space | IN — condition & notes | IN photos | OUT — condition & notes | OUT photos | Damage flagged |
|---|---|---|---|---|---|
| Bedroom 1 | Walls: good, Floor: fair... | [thumbnails] | Walls: good, Floor: **damaged**... | [thumbnails] | "Water stain, ceiling corner — R450 est." |

This is generated as a real, filed document (reusing the existing PDF-generation pipeline the
module already uses elsewhere — `corex.rental-applications.pdf`'s own pattern of one Blade
template rendered to PDF and filed against a record), not a live-only screen — Johan's own
words: *"design it as evidence, not as a screen."* It must be downloadable, and it is the
document both parties sign (§6.1) — the same artefact, not a separate "pretty" copy and a
separate "legal" one.

### 6.1 Deposit consequence

`inspection_damage_items` (§4.5), entered during the out-pass, are what makes the report
usable in an actual deposit argument:
- Each damage item names a space, a description, an optional estimated cost, and points at the
  specific photo(s) proving it.
- The report's "Damage flagged" column (above) lists these per space, with cost where given.
- A **total estimated deduction** is shown at the foot of the report — the sum of every priced
  damage item — clearly labelled as the AGENT'S estimate, not a final legally-binding figure
  (that determination is a business/legal process outside this module's scope; this module's
  job is to produce the evidence and a starting number, not adjudicate the dispute).
- This total is NOT automatically deducted from anything — CoreX has no deposit-holding/escrow
  ledger anywhere in the codebase today (confirmed: `Property.deposit_amount` is just the
  advertised deposit figure, not a live trust-account balance). Building that ledger is
  explicitly out of scope (§11) — this spec's job is to make sure the EVIDENCE a real deduction
  conversation needs exists, tied to real photos, in one filed document.

---

## 7. SIGNATURES — reuse e-sign, do not invent a second path

Johan: *"CoreX already has e-sign and DocuPerfect — reuse them, do not invent a second signing
path."*

**The actual reuse point, found by investigation, not assumed**: `App\Models\Docuperfect\
SignatureTemplate.parties_json` — an array of `{role, name, email, ...}` signing parties,
already built for exactly "two named roles sign one document" (its own `tenant`/`landlord`
role handling is what `SignatureService::createLeaseRecord()` already reads). An inspection
report becomes a `SignatureTemplate` instance the moment both passes reach `completed`:

- Party 1, role `landlord` → resolved from `PropertyTenancy.landlord_contact_id` (Contact type
  Lessor).
- Party 2, role `tenant` → resolved from `PropertyTenancy.tenant_contact_id` (Contact type
  Tenant, id 11).
- The document attached is the report generated in §6.
- Both parties sign **twice across the tenancy** — once when the in-pass is finalised (before
  move-in, confirming the recorded starting condition), once when the out-pass/report is
  finalised (at move-out, confirming the comparison and any flagged damage). This is two
  separate signing rounds on two states of the same eventual report, not one signature at the
  very end — an in-pass signed at move-in is itself evidence, independent of whether an
  out-pass ever happens (a tenancy that runs its full course with no dispute never needs the
  out-pass signed urgently, but the in-pass protects the agency from day one).
- `rental_inspections.signature_template_id` / `.signed_at` (per §4.2) hold the resulting link
  and timestamp — no new signature-capture UI, no new party-management screen; this is
  configuration handed to the existing DocuPerfect flow, exactly as `createLeaseRecord()`
  already does for lease documents.

---

## 8. OFFLINE

Johan: *"these happen in empty flats with the power off and no wifi. Photos and answers must
survive no signal and sync later."*

**The real, already-proven precedent in this codebase is NOT the rental-application
applicant-form autosave** (a server-side debounced save that requires connectivity and
degrades silently on failure — explicitly the wrong model to copy). **It is
`mobile_photo_events`** — a client-declared `phase` (captured → queued → upload_started →
upload_ok / upload_failed / dropped), keyed by a client-generated `client_upload_id`
(idempotency — the same photo retried after a dropped connection is recognised as the same
photo, never duplicated), grouped by a `batch_id` (one inspection session), with
`occurred_at` being **the phone's own clock**, and `received` written server-side only when it
actually arrives. This is a genuine "assume no signal, reconcile later" design already running
in production for mobile photo uploads. This spec adopts the identical shape for inspection
photos (§4.4's `client_upload_id`/`captured_at` columns exist specifically for this) and
proposes the SAME phase-tracking model for the checklist/notes answers themselves, not just
photos — an `inspection_space_entries` row should be writable locally-first with the same
queued/synced states, not just its photos.

**A half-finished inspection on a dead phone**: the space-by-space progressive save (§5) means
whatever was captured before the phone died is already either (a) synced if there was signal
at the time, or (b) sitting queued locally on that device in the same shape
`mobile_photo_events` already uses for photos — recoverable the next time that device gets
signal and reopens the same inspection, keyed by the same `client_upload_id`/inspection id, no
data entered twice. **This is the mobile app's job to implement the local queue** (§9) — the
web/API side's job is to accept a batch of queued events keyed this way and never reject or
silently drop a late-arriving one just because time has passed.

---

## 9. THE MOBILE BOUNDARY (Andre's side, named explicitly)

Johan: *"say clearly what the web side owns, what the mobile side owns, and what they must
agree on."*

**Flagged first, before the ownership split**: the existing `MobileRentalImagesController` and
its `rental_inspections_available`/`in_inspection`/`out_inspection` flat-photo API (§0.2)
already ships in the mobile app today. Once the structured model (§4) replaces those two
sections, this API's response shape changes — a mobile client built against today's flat
`images: [url,...]` array will not understand `inspection_spaces`/`inspection_photos`. This is
a real coordination point with Andre, not a detail to redesign here: whether the old endpoints
are versioned, replaced outright, or kept serving the legacy block (§4.4.1) while new endpoints
serve the structured data is his and this spec author's call to make together once this spec is
approved — named here so it is not discovered mid-build.

**Web owns:**
- The `rental_inspections` / `inspection_spaces` / `inspection_space_entries` /
  `inspection_photos` / `inspection_damage_items` tables and their API — this is the source of
  truth both platforms read/write.
- Seeding a space list from `Property.spaces_json` + the agency default template (§4.1) — this
  logic lives once, server-side, so mobile and web can never seed a space list differently.
- Report generation (§6) and the signature-template hookup (§7) — these are document/e-sign
  concerns, squarely web/DocuPerfect's existing territory.
- The desktop form (§5) for an agent finishing up at her screen.
- List screens, permissions, scoping, settings (§10 below).

**Mobile owns (Andre):**
- The actual in-the-field capture UX — camera integration, the local offline queue mechanics
  (§8), and the **"ghost image" overlay**: Johan liked overlaying the in-pass photo semi-
  transparently to guide the agent into the same framing on the out-pass. This is a camera/UI
  concern, built entirely on-device — **not designed here**, flagged explicitly as mobile's to
  own.
- Deciding exactly how/when queued events flush to the API (background sync, foreground retry,
  wifi-only setting, etc.).

**What both sides MUST agree on (the actual contract, not left implicit):**
1. **The space list** — the exact shape of `inspection_spaces` (id, name, sort_order, source)
   is the one list both platforms render; mobile never invents its own space-naming logic.
2. **The photo model** — `inspection_photos`' columns (§4.4) are deliberately richer than any
   existing photo table in this codebase specifically so mobile's ghost-image feature has what
   it needs: `width`/`height`/`orientation` (so mobile can correctly scale/rotate the in-photo
   overlay against the out-pass's live camera preview) and `captured_at` (so mobile can label
   which historical photo is which, and — combined with `client_upload_id` — reconcile a
   locally-queued photo against its eventually-synced server record). **If mobile's ghost
   overlay needs anything beyond these four fields (e.g. device orientation sensor data, a
   reference grid, camera intrinsics), that is an ADDITION to this table, proposed by mobile,
   not a redesign of it** — the web side is committing to this shape as the stable contract
   mobile builds against.
3. **The sync contract** — the `mobile_photo_events`-style phase/idempotency-key/client-clock
   pattern (§8) is the one both sides implement against; mobile does not invent a different
   offline strategy, and web does not reject a late-arriving synced item just because its
   `occurred_at` is old.

---

## 10. Own/branch/agency scoping, list screens, navigation, permissions, settings

Per Johan's standing rule, stated before any code: every list screen ships with search, sort,
filter, pagination, and a real empty state; every list/detail/export/download enforces
OWN/BRANCH/AGENCY scoping at the query layer; every screen names its navigation entry; every
threshold is an agency setting with a sensible default.

### 10.1 Where this lives — NOT a new tab; expand the two tabs that already exist

Johan's own instruction, verbatim: *"we can expand the inspections on the rental images tab."*
No new tab. Two existing tabs, each doing more of what it already does:

- **The "Rental Images" tab** (existing, §0.2) — its current "In Inspection" / "Out Inspection"
  cards are replaced IN PLACE by the new structured, per-space form (§5): space list, condition
  checklist, notes, and photos per space, with the legacy-photo handling from §4.4.1. The tab's
  own gating is unchanged (`listing_type === 'rental'`), with one addition: starting a NEW
  in-pass additionally requires an active `PropertyTenancy` to exist (§2) — a property advertised
  for rent with nobody living in it yet has nothing to inspect. Custom ad-hoc sections on this
  same tab (§0.2) are entirely unaffected — they keep working exactly as they do today.
- **The "Rental" tab** (existing, §0.1) — gains the parties/dates work this spec depends on
  (§2.1) — landlord, tenant, start/end/escalation dates, becoming `PropertyTenancy`. This is
  where Johan already said this belongs: *"its the lease / parties / and whatever else that we
  can put on that rental tab."* The finished REPORT (§6) is reachable from either tab (a
  "View inspection report" link once both passes exist), since it's genuinely the product of
  data from both.

A SECOND, separate entry point — **"Rentals → Inspections"** in the sidebar, alongside the
already-existing "Rentals → Properties" / "Rentals → Core Matches" / "Rentals → Rental
Pipeline" entries (`rentals-shared-screens.md`) — is the list screen required by the CRUD
standard below: every inspection across the agency, not found by clicking into properties one
at a time. Same "reachable from the pillar AND from its own control centre" pattern Rental
Applications already uses.

### 10.2 List screen — search, sort, filter, pagination, empty state

- **Search fields**: property address, tenant name, landlord name.
- **Sort columns**: property address, tenancy start date, inspection date, status. **Default:
  most recently updated first** (an agent's worklist is "what needs my attention now," not
  alphabetical).
- **Filters**: status (draft/in_progress/completed/signed), type (in/out), a date range on
  `started_at`/`completed_at` — status and date range are the stated minimum per
  `BUILD_STANDARD.md` §1b.
- **Pagination**: standard page size, matching the Rental Applications control centre's own
  convention.
- **Empty state**: distinct copy for "nothing yet" vs. "no results for this filter" — e.g.
  "No inspections yet — they start from a property's Rental tab once a tenancy is active" vs.
  "Nothing matches this filter."

### 10.3 Scoping

OWN = created by the logged-in agent. BRANCH = the property's branch. AGENCY = the property's
agency (the outer, non-negotiable boundary via `BelongsToAgency`/`AgencyScope` — never
crossable regardless of own/branch permission). Enforced identically on the list query, the
detail/report view, the PDF download, and the (future) API surface mobile will need —
`PermissionService::getDataScope()` against a new `rental_inspections` permission module, same
mechanism `RentalApplicationController::index()` already uses (§10.5).

### 10.4 Full CRUD

Create (start an in-pass), Read (the form mid-progress, the finished report), Update (space-
by-space progressive save, §5), Archive (soft-delete only — `deleted_at` on
`rental_inspections`; a signed inspection can still be archived/hidden from active lists, its
report remains the historical record), Restore (from an admin archive screen, matching the
platform-wide convention). No hard delete anywhere in this module, including photos and damage
items (§4.4, §4.5 both specify soft-delete).

### 10.5 Permissions

New `rental_inspections.*` keys, same flat shape as the existing `rental_applications.*` block
in `config/corex-permissions.php`:

```php
['key' => 'rental_inspections.view',            'label' => 'View Rental Inspections',           'module' => 'rental_inspections', 'type' => 'access'],
['key' => 'rental_inspections.create',          'label' => 'Start & Record Inspections',        'module' => 'rental_inspections', 'type' => 'action'],
['key' => 'rental_inspections.manage_settings', 'label' => 'Manage Inspection Settings',         'module' => 'rental_inspections', 'type' => 'action'],
['key' => 'rental_inspections.archive',         'label' => 'Archive',                            'module' => 'rental_inspections', 'type' => 'action'],
```

### 10.6 Settings — `RentalInspectionSettings`, agency-scoped, sensible defaults

Same pattern as `RentalApplicationQualifyingSetting` (one row per agency, static `xFor()`
readers, hardcoded fallback default, never a raw config value read directly by a controller):

- **Default space template** (§4.1) — Domestic Bathroom, Water Meter, Electricity Meter,
  Garage, Garden, Outbuildings — agency-editable list, this default shown above is the
  fallback for an agency that hasn't customised it.
- **Default checklist items** (§4.3) — walls/floor/ceiling/fixtures/windows — same pattern.
- **Days before `PropertyTenancy.end_date` to prompt an out-inspection** — new setting, default
  **30 days** (a sensible lead time for scheduling a move-out inspection before a tenant
  actually leaves) — surfaces as a dashboard/calendar reminder, reusing the existing calendar-
  source pattern (`RentalCalendarSource.php` already surfaces lease events; extend it, don't
  fork it).

Every setting above gets a control in `config/agency-onboarding-copy.php`'s rentals-related
step, with its `explain`/`affects` copy and canonical saver, per CLAUDE.md non-negotiable
#10a — not optional, not deferred.

### 10.7 Domain events

Per `.ai/specs/corex-domain-events-spec.md`'s established mechanism (Eloquent observers +
named events + queued, idempotent listeners — not an ad-hoc query path): `InspectionStarted`,
`InspectionCompleted`, `InspectionSigned`. `InspectionSigned` (out-pass specifically) is the
natural future hook for the "tenant vacated" side of `rentals-shared-screens.md` §5's own
"tenant won" trigger family — not wired here, just named as the obvious future listener.

---

## 11. Out of scope (explicitly, so it is never assumed later)

- **A deposit-holding/escrow ledger.** This module produces the evidence and an estimated
  deduction figure (§6.1); it does not hold, disburse, or reconcile actual trust-account money.
- **Reconciling `Property.lease_start_date`/`property_contact`/`LeaseRecord` into one system**
  (§2). `PropertyTenancy` is additive; the larger reconciliation is Johan's call, not this
  spec's.
- **A Lease/Tenancy module in the general sense** (renewals, rent escalations as a workflow,
  notice periods) — `PropertyTenancy` here is deliberately the smallest record that unblocks
  Inspections, not a general-purpose lease system. `LeaseRecord`'s own renewal-chain machinery
  already exists separately for the DocuPerfect-signed-lease-document case and is untouched.
- **Rental Images' custom ad-hoc galleries** (e.g. "Garden handover" mid-tenancy, unrelated to
  a formal in/out pass) — stay exactly as they are, unreplaced.
- **The mobile ghost-image overlay itself** — noted as a requirement ON the photo model
  (§4.4/§9), not designed here.
- **Per-space-type checklist customisation** — one shared default checklist to start (§4.3);
  named as a future refinement.
- **Automatic `PropertyTenancy` creation from a signed lease or a "tenant won" trigger** —
  named as the obvious future connection (§3, §10.7), not built now.

---

## 12. Acceptance criteria

- A rental property with an active `PropertyTenancy` shows an Inspections area on its own
  record; one with no active tenancy does not offer to start an in-pass.
- Starting an in-pass seeds the space list from the property's `spaces_json` PLUS the agency's
  default template, in that order; every seeded space is independently addable/renameable/
  reorderable/hideable (soft) from that point on.
- Per-space condition + notes + photos save independently as the agent moves through the form;
  losing connectivity or the app mid-inspection never loses an already-saved space's data.
- Starting an out-pass on the same `PropertyTenancy` shows the in-pass's own recorded answer,
  notes, and photos alongside each space while the agent records the out-pass; the in-pass data
  itself is never editable from the out-pass screen.
- The report renders every space with in/out columns side by side, including photos, and is
  downloadable as a filed PDF once both passes exist (partial-out-pass reports are clearly
  labelled as in-progress).
- Damage items entered on the out-pass appear on the report under their space, with cost where
  given, summing to a clearly-labelled estimated-deduction total.
- Both the in-pass and (separately) the out-pass/report can be sent through the existing
  e-sign flow with landlord and tenant as the two resolved parties, producing a real,
  independently-timestamped signed record for each.
- A user outside the property's agency scope is rejected on every list, detail, export, and
  download endpoint — verified by direct-URL-by-ID test, not just absence from a menu.
- The list screen (`Rentals → Inspections`) supports search/sort/filter/pagination and shows
  the correct distinct empty-state copy for "none yet" vs. "no filter matches."
- Every setting in §10.6 is surfaced in the Agency Onboarding Setup Wizard in the same landing
  that ships it.

---

## 13. Files likely to be created (spec-level list — not exhaustive, no code written)

- Migrations: `property_tenancies`, `rental_inspections`, `inspection_spaces`,
  `inspection_space_entries`, `inspection_photos`, `inspection_damage_items`,
  `rental_inspection_settings`.
- Models: `PropertyTenancy`, `RentalInspection`, `InspectionSpace`, `InspectionSpaceEntry`,
  `InspectionPhoto`, `InspectionDamageItem`, `RentalInspectionSettings`.
- Controller(s): a new `RentalInspectionController` (agent-facing, mirroring
  `RentalApplicationController`'s scoping/tile pattern) + settings controller additions.
- Views: `properties/show.blade.php`'s existing Rental Images tab (in/out sections replaced
  in place) and Rental tab (parties/dates added), a new `Rentals → Inspections` list screen,
  the report Blade/PDF template.
- Config: `rental_inspections.*` permission keys, `agency-onboarding-copy.php` entries.
- Events/listeners per §10.7.
- Mobile API: NOT designed here — coordinate directly with Andre once this spec is approved,
  using §9 as the starting contract.
