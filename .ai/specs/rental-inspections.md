# Rental Inspections — move-in / move-out condition reports, evidence, and deposit consequence

**Status:** Spec — awaiting Johan's sign-off. No code, no migrations written against this spec.
**Date:** 2026-09-13 (third revision — see §0 for what changed and why)
**Author:** Claude (cc4)
**Pillars:** Property (`Property`) and Contact (`Contact`) — reads from both, writes back to both.
**Commercial context:** this module, together with Rental Applications, is what Johan is pitching to a
prospective agency this week. It is not a Monday nice-to-have.

---

## For Johan — the plain version

CoreX already lets an agent upload photos for a "move-in inspection" and a "move-out
inspection" on a rental property. What it can't do yet is organise those photos by ROOM, put a
condition checklist and notes next to each room, show the move-in photo of a room while you're
standing in that same room doing the move-out inspection, or turn all of that into one signed
report that settles a deposit argument.

This spec adds exactly that, on top of what's already there — it does not replace the property
screen or add a confusing new place to look. Specifically:

- **Rooms are called "spaces."** Every property gets its own list — starting from what was
  advertised (3 bed 2 bath), plus the things nobody advertises but every inspection needs
  (domestic bathroom, water meter, electricity meter, garage). An agent can add, rename, or
  remove spaces for that property at any time — the list is never fixed to the advert.
- **Meters get a number, not a tick-box.** A water or electricity meter is treated as a space
  like any other, but instead of "good/fair/damaged" it asks for a reading and a photo of the
  meter face — same place, same flow, just the right kind of answer for what it is.
- **The same list carries from move-in to move-out**, for the SAME tenant. If the agent adds
  "geyser" while doing the move-in inspection, it's there waiting when they do the move-out
  inspection months later — nothing has to be re-typed.
- **Move-out shows move-in, side by side, per room**, while the agent is doing the work — not
  just afterward on a report. That's the actual point: standing in bedroom 1 at move-out, with
  the move-in photo of bedroom 1 right there to compare against.
- **Every space's history survives even if it's later removed** from the list — an inspection
  record is evidence in a possible deposit dispute, and evidence doesn't get deleted because
  someone tidied up a room list.
- **New tenant, clean slate, same rooms.** When a tenant moves out and a new one moves in, the
  new inspection is its own separate record — it doesn't get mixed up with the old tenant's
  photos — but it starts from the same space list, since the rooms haven't changed, only the
  people.

Three things below are marked **OPEN QUESTION** — real judgement calls I'd rather put in front
of you than guess on, because guessing wrong on the wrong one could weaken a report in an
actual deposit dispute.

---

## 0. What changed in this revision, and why

Third pass at this spec, each correction real and each one making it smaller and more
accurate, not bigger:

1. **First correction**: a "Rental" tab already exists for lease terms — read it before
   inventing a new dependency. Done (§2).
2. **Second correction**: the "Rental Images" tab already has in-inspection/out-inspection
   photo upload — this spec extends that tab, it does not add a new one. Done (§1.3, §10.1).
3. **This revision**: two further, substantial corrections that simplify the data model —
   - Reading `contact_property` (role='tenant'/'landlord' — the mechanism cc3 is actively
     building the approved-application→property link against right now, confirmed directly,
     not guessed) means this spec no longer needs a new blocking table before inspections can
     start. An inspection can capture its own tenant/landlord/dates directly from what already
     exists at the moment it's created — see §2, rewritten from the prior draft's
     `PropertyTenancy` proposal.
   - Spaces need TWO layers, not one: a **persistent, per-property** list (a geyser doesn't
     stop existing when a tenant leaves) and a **frozen snapshot** of that list taken by each
     inspection (so a later edit to the property's list never changes what an already-signed
     inspection says it inspected). The prior draft only had the second layer. See §4.1.
   - Meters are now explicitly a TYPED space (a numeric reading field, not a condition
     checklist) rather than an unexplained ordinary space. See §4.1.3.

---

## 1. What already exists — read directly from the live code

### 1.1 The "Rental" tab — lease terms, no parties

Built and live (`rentals-shared-screens.md` §11, `Property.php`, `show.blade.php`'s Rental
tab). Holds: `rental_amount`, `deposit_amount`, `lease_start_date`, `lease_end_date`,
`lease_period` (free text), `lease_type` (a commercial-lease enum), `price_per_day/week/year`,
`has_deposit`, `commission_percent`, `admin_fee`, `marketing_fee`, `furnished_status`,
`occupation_date`, `water/electricity/levies_included`. All real, saved columns.

**No landlord field, no tenant field, no Contact link anywhere on this tab.**

### 1.2 The tenant/landlord link — already live, confirmed via direct code cross-check with cc3

`Property::contacts()` (`Property.php:776-781`) is `belongsToMany(Contact::class,
'contact_property')->withPivot('role')`. `PropertyContactController::LINK_ROLES` includes
`landlord`, `tenant`, `lessor`, `owner`, `seller`, `buyer`. `ContactPropertyController` is the
mirror entry point from the Contact side, writing the identical pivot. **This is the exact
mechanism cc3 is building the approved-application → property tenant link against right now**
(confirmed directly with cc3, not assumed) — one live table, reversible (unlink without
deleting), already audited via a domain event on link.

**What it does NOT have**: no start/end date on the link itself, and nothing marks which
linked tenant is *current* if more than one were ever linked. This matters for §2.2.

### 1.3 The "Rental Images" tab — in/out photo upload already exists

Built and live (`.ai/specs/rental-images.md`, `Property.php:1858-1904`,
`PropertyController::uploadRentalImages/saveRentalImagesMeta/deleteRentalImage`). Verified
against the current model code directly:

```php
// Property::rentalImagesStructure() — Property.php:1865-1904
'in_inspection'  => ['date' => ..., 'images' => [ /* flat array of URL STRINGS */ ]],
'out_inspection' => ['date' => ..., 'images' => [ /* flat array of URL STRINGS */ ]],
'custom'         => [ ['id', 'name', 'date', 'images'] , ... ],
```

Every image is a plain URL string (`array_filter(..., 'is_string')`, `Property.php:1881-1884`)
— no per-image id, no space/room tag, no metadata. One date per section, not per photo. A full
mobile API (`MobileRentalImagesController`) mirrors this exact shape today.

**What it does NOT have**: any concept of a "space" — each section is one flat bucket for the
WHOLE property. No checklist, no notes, no meter readings, no signatures, no report, no
deposit consequence, no link to which tenancy the photos belong to.

### 1.4 Can existing in/out photos be given a space retroactively? No.

A bare URL string has nothing to attach a space to. **Nothing here is deleted, hidden, or
force-migrated.** Existing photos become a clearly labelled "Photos from before structured
inspections" block, visible and downloadable, not counted in the new per-space report. See
§4.4.1 for the full disposition.

---

## 2. THE DEPENDENCY — resolved without a new table

### 2.1 What this spec previously proposed, and why it's dropped

The first two drafts of this spec proposed a new `PropertyTenancy` table (property + landlord
+ tenant + dates) as a hard prerequisite. Reading what cc3 is actually building removes that
need: **each inspection captures its own tenant, landlord, and lease dates at the moment it is
created**, read from what already exists —

- `tenant_contact_id` ← whichever contact currently holds `contact_property.role = 'tenant'`
  for this property (§1.2).
- `landlord_contact_id` ← whichever contact currently holds `role = 'landlord'` (or `lessor` —
  see the naming note in §7).
- `lease_start_date` / `lease_end_date` ← the property's current Rental-tab values (§1.1) at
  that moment.

These four values are copied onto the `rental_inspections` row itself (§4.2) once, at
creation, and never re-read afterward. **No new table is required to unblock this spec.**

### 2.2 What this answers, and what it doesn't — OPEN QUESTION flagged, not guessed

This directly answers "does the previous tenancy's inspection stay attached to the property
forever, or belong to a tenancy?" — **each inspection belongs to whichever tenant/landlord/
dates it captured at its own creation**, so a property with three tenants over its life has
three inspections (well, three in/out pairs) each correctly and permanently labelled with
their own tenant, never confused with each other, with no separate tenancy table needed at all.

**OPEN QUESTION, genuinely uncertain, not decided here**: `contact_property` has no dates on
the link and does not prevent two contacts holding `role='tenant'` on the same property at
once. If an agent starts a new tenant's in-pass before removing the old tenant's `contact_
property` link (a realistic sequencing mistake during a busy handover), which contact does
"whichever contact currently holds tenant" resolve to — the old one, the new one, or an error?
This spec does not decide that rule. Recommendation for discussion: require the agent to
explicitly CONFIRM which tenant contact the new in-pass is for (a simple select, pre-filled
with the current `contact_property` tenant link if there's exactly one, but never silently
guessed if there's more than one) — flagged for Johan's decision, not built quietly either way.

---

## 3. Pillar connections

- **Property** — an inspection belongs to a property; its space list starts from the
  property's own advertised layout and persists on the property going forward (§4.1).
- **Contact** — an inspection's landlord/tenant resolve to Contacts via `contact_property`
  (§2.1); its two signing parties (§7) are the same two people.
- **Deal** — not directly connected. Out of scope here.

---

## 4. Data model

### 4.1 Spaces — TWO layers, not one

**Layer 1 — `property_spaces`: persistent, per property, survives every tenancy.**

A geyser is a fact about the building, not about who's renting it — if it needed inspecting
for tenant A, it needs inspecting for tenant B too. This list lives on the PROPERTY, is
created once (seeded, §4.1.1), and is then agent-editable (add/rename/reorder/archive) at any
time, independent of any specific inspection or tenancy.

```
property_spaces
  id
  property_id (FK)
  name                    -- "Bedroom 1", "Domestic Bathroom", "Water Meter"
  type                    -- enum: room | meter | other   (see §4.1.3)
  sort_order
  source                  -- enum: advertised | template | manual  (audit: where this came from)
  deleted_at              -- archived, never destroyed (§11)
```

**Layer 2 — `inspection_spaces`: a frozen snapshot, per inspection, taken at creation time.**

When an in-pass is created, EVERY currently-active `property_spaces` row for that property is
copied into `inspection_spaces` for that specific inspection. This snapshot is what the form
and report actually work against — editing the property's space list afterward (adding a space
next year, or archiving one) never retroactively changes what an already-recorded or
already-signed inspection says it inspected.

```
inspection_spaces
  id
  rental_inspection_id (FK)
  property_space_id (FK, nullable)  -- which property_spaces row this was copied from;
                                     -- nullable because a space added mid-inspection (below)
                                     -- may not have one yet at the instant it's created
  name, type, sort_order            -- copied values, frozen at snapshot time
  deleted_at                        -- archived, never destroyed
```

**The out-pass reuses the SAME `inspection_spaces` rows as its own in-pass** (both passes for
one tenancy share one space snapshot) — it does NOT take a fresh snapshot from
`property_spaces`. This is what makes "if the agent adds geyser during the in-inspection, is
it there at out-inspection" true: geyser was added to the shared snapshot at in-pass time, and
the out-pass reads that same snapshot, not a new one.

**A space added mid-inspection is added to BOTH layers.** If an agent, mid in-pass, adds
"Geyser" because it wasn't on the property's existing list: it's added to THIS inspection's
snapshot immediately (so the out-pass will see it), AND to `property_spaces` for the property
(so the NEXT tenancy's in-pass starts with it already there, never needing to be re-added).
Reasoning: a space added during a real inspection is almost always a real, permanent fact
about the property, not a one-off — the tedium Johan is trying to remove ("water and
electricity meters... we won't have it on advertising") is exactly the tedium of re-adding the
same physical facts every single time. **This is a design decision, not neutral — flagged as
one Johan should confirm rather than silently agree with by not noticing it (§13, Q3).**

#### 4.1.1 Seeding — when, and why a default list makes sense

The FIRST time `property_spaces` is populated for a property (the very first structured
inspection ever started for it — see §10.1), it's seeded from two sources, in order:

1. **Advertised spaces** — `Property.spaces_json` (already exists, `config/property-
   spaces.php` for the vocabulary), expanded from `{type, count}` into named positional
   entries (Bedroom×3 → "Bedroom 1", "Bedroom 2", "Bedroom 3").
2. **The agency's default template** — appended after the advertised spaces: Domestic
   Bathroom, Water Meter, Electricity Meter, Garage, Garden, Outbuildings (the shipped
   default; agency-editable, §10.6). "Domestic Bathroom" is technically already in the
   advertised vocabulary (`config/property-spaces.php`) but is very often left unselected on
   an advert — the template guarantees it's asked about at inspection time regardless.

**Why a default list, reasoned, not assumed**: Johan is explicit that spaces vary from
advertising and must be addable — that stands, nothing here narrows it. But offering a
sensible starting point (rather than a blank list an agent builds from nothing on property
one, then again on property two) removes exactly the friction Johan named ("we won't have it
on advertising, but we need it on the inspection") without removing any flexibility — every
seeded space can be renamed or removed, and any space can be added, at any time, by any agent
with access. The alternative (starting blank every time) is strictly worse for speed and
consistency with no compensating benefit.

Once seeded, `property_spaces` is never re-seeded automatically — a later change to the
property's own advertised `spaces_json` does not retroactively add/remove anything from the
already-established `property_spaces` list. This matches the "advertising is a starting point,
never the constraint" instruction literally: it constrains only the very first moment.

#### 4.1.2 Editing

Add, rename, reorder (a simple sort-order integer, no drag-library dependency implied), and
remove — remove is **always** a soft-delete/archive (§11), on both layers, since an inspection
already recorded against a space must keep showing that space even after it's archived from
the active list.

#### 4.1.3 Meters — a typed space with a reading, not a bolted-on feature

Johan: *"same as water and electricity meters... a meter reading is captured at in-inspection
and at out-inspection, with a photo, the same way a room is."* Justification for the choice
made here: a meter is asked about at the same two moments, in the same flow, by the same
agent, with a photo either way — it belongs in the SAME table as every other space, not a
separate "meter readings" feature living somewhere else that an agent has to remember to visit.
What's genuinely different is the SHAPE of the answer: a meter needs a number, not a
good/fair/damaged tick.

`property_spaces.type` / `inspection_spaces.type` carries this: `room` (the default — gets the
condition checklist, §4.3) or `meter` (gets a numeric reading field instead — see §4.3.1).
`other` is available for anything that's neither (a placeholder, not expected to see much use
at launch). This is a column on the SAME table, read by the SAME per-space entry form,
producing a different set of fields depending on its value — not a second table, not a second
screen.

### 4.2 The inspection itself

```
rental_inspections
  id
  agency_id                       -- BelongsToAgency, standard global scope
  property_id (FK)
  type                              -- enum: in | out
  status                            -- enum: draft | in_progress | completed | signed
  tenant_contact_id (FK, nullable)  -- captured at creation, see §2.1 — never re-read after
  landlord_contact_id (FK, nullable)-- same
  lease_start_date, lease_end_date  -- captured at creation, same
  started_at, completed_at
  created_by_user_id
  signature_template_id (FK, nullable until signing starts)
  signed_at
  deleted_at                        -- soft delete only
```

One row per PASS (in, out) — not one row holding both. An in-pass and its out-pass are linked
by sharing the same `inspection_spaces` snapshot (§4.1) and — practically — by being the two
most recent in/out rows for the same property with the same `tenant_contact_id`; there is no
separate join table connecting them, since nothing beyond that is needed given §2's
resolution. **If Johan's OPEN QUESTION in §2.2 is resolved by requiring an explicit tenant
confirmation at in-pass creation, that confirmation is what reliably pairs an in-pass with its
out-pass — flagged as the same open point, not a second one.**

### 4.3 Per-space record — checklist, notes, photos

```
inspection_space_entries
  id
  rental_inspection_id (FK)       -- which pass (in or out)
  inspection_space_id (FK)        -- which space (from the shared snapshot)
  condition_json                   -- ROOM type only, shape below
  reading_value, reading_unit      -- METER type only, e.g. 4821.5 / "kWh"
  notes                            -- either type
  created_at, updated_at
```

`condition_json` (room type) — a flat map of checklist item → condition, agency-configurable
list (§10.6), e.g.:
```jsonc
{ "walls": "good", "floor": "fair", "ceiling": "good", "fixtures": "damaged", "windows": "good" }
```

#### 4.3.1 Meter entries

For `type = meter`, the form shows `reading_value` (numeric) + `reading_unit` (a short label,
agency-configurable per meter type, e.g. "kWh" for electricity, "kL" for water) + a photo of
the meter face — the same photo mechanism as any other space (§4.4), just no condition
checklist, since "good/fair/damaged" doesn't mean anything for a meter reading.

### 4.4 Photos

```
inspection_photos
  id
  inspection_space_entry_id (FK)
  storage_path, disk
  original_name
  width, height                    -- NEW — nothing existing captures this (confirmed, §9)
  orientation                      -- NEW — same
  captured_at                      -- client-declared, the phone's own clock (§8 offline model)
  client_upload_id                 -- idempotency key, mirrors mobile_photo_events' own pattern
  sort_order
  created_at
  deleted_at                       -- archived, never destroyed (§11)
```

Reuses the existing property/gallery image storage pipeline (downscale, JPEG re-encode) — but
this is a genuinely new table, not another `*_json` column, because a photo here needs its own
identity (for the report's side-by-side pairing, the offline sync contract, and the mobile
ghost-image feature, §9) that a flat array of URLs cannot carry.

#### 4.4.1 Existing legacy photos — disposition, stated plainly

When a property's structured inspection area (§10.1) is opened for the first time, any
existing `rental_images_json.in_inspection`/`out_inspection` photos render in a clearly
labelled **"Photos from before structured inspections"** block, above the new per-space
layout — visible, downloadable, unchanged. They are NOT counted in the new per-space report
(§5) — there's no space to put them in, and guessing would be worse than a labelled gap. A
one-time, OPTIONAL manual "sort these into spaces" tool is a genuinely useful future addition,
named here so it isn't lost, but **not built in this spec**. Custom ad-hoc gallery sections on
the same tab (§1.3, unrelated to in/out) are entirely untouched.

### 4.5 Deposit consequence

```
inspection_damage_items
  id
  rental_inspection_id (FK)        -- always an OUT-pass row
  inspection_space_entry_id (FK)
  description
  estimated_cost                    -- nullable
  created_at, updated_at
  deleted_at                        -- archived, never destroyed (§11)
```

---

## 5. THE FORM, and how in/out are actually compared

**In-pass**: the space list per §4.1 (add/rename/reorder/archive available inline). Per space:
the condition checklist or meter reading (§4.3), free notes, photos. Saves progressively per
space — an agent walking room to room never loses an earlier room's work because the last one
wasn't finished.

**Out-pass**: the SAME space list (the shared snapshot, §4.1). **This is the commercial core
of the feature, stated concretely**: for each space, the out-pass screen shows the IN-pass's
own recorded condition/reading, notes, and photos **directly on the same screen, next to the
field the agent is currently filling in for the out-pass** — not a separate report to check
afterward, not a second tab to switch to. Standing in bedroom 1 doing the move-out inspection,
the move-in photo of bedroom 1 is visible on the same screen, at the same time, while the
out-pass's own photo capture is active. This is read-only reference data here — the in-pass
itself is never editable from this screen.

**Both passes**: mobile-first (§8/§9 — most inspections happen from a phone standing in an
empty flat), rendering equally on desktop for an agent finishing up at her screen.

---

## 6. THE REPORT

Per inspection pair (in + out, sharing one space snapshot): one filed document, one row per
space, two columns:

| Space | IN — condition/reading & notes | IN photos | OUT — condition/reading & notes | OUT photos | Damage flagged |
|---|---|---|---|---|---|
| Bedroom 1 | Walls: good, Floor: fair | [thumbnails] | Walls: good, Floor: **damaged** | [thumbnails] | "Water stain, ceiling corner — R450 est." |
| Water Meter | 4821.5 kWh | [meter photo] | 5,102.0 kWh | [meter photo] | — |

Generated via the same PDF-rendering pattern this codebase already uses elsewhere (one Blade
template → filed PDF), not a live-only screen — this is evidence, not a screen. Downloadable,
and the same document both parties sign (§7) — never a separate "pretty" copy and a separate
signed one. A partial report (out-pass still `in_progress`) renders with that state clearly
labelled, not hidden.

### 6.1 Deposit consequence

`inspection_damage_items` (§4.5) list per space under "Damage flagged," with a total estimated
deduction at the foot of the report — labelled explicitly as the agent's estimate, not a final
figure (adjudication is outside this module). CoreX has no deposit-holding/escrow ledger
anywhere in the codebase (`Property.deposit_amount` is the advertised figure, not a live
trust-account balance) — this module produces the evidence a real deduction conversation
needs; it does not move or hold money.

---

## 7. SIGNATURES — reuse e-sign, do not invent a second path

`App\Models\Docuperfect\SignatureTemplate.parties_json` — an array of `{role, name, email}`
signing parties — is the actual reuse point, already used by `SignatureService::
createLeaseRecord()` for the identical `tenant`/`landlord` role pair. An inspection's two
parties resolve from `rental_inspections.tenant_contact_id`/`landlord_contact_id` (§2, §4.2).

**Naming note, flagged rather than assumed**: `ContactType`'s canonical set has `Lessor`
(id 10) and a separately-added `Tenant` (id 11, `esign_role='lessee'`), neither a clean 1:1
with the English words "landlord"/"tenant." §1.2's `contact_property.role` values (`landlord`,
`tenant`, `lessor`) are a THIRD vocabulary again, distinct from `ContactType` names. This spec
resolves the SIGNING role labels (`landlord`/`tenant` in `parties_json`) from whichever
Contact is linked via `contact_property.role IN ('landlord','lessor')` / `role = 'tenant'`
respectively — the `ContactType` the Contact itself carries is irrelevant to which signing
role they get here. **OPEN QUESTION**: is this the right reading, or should the Contact's own
`ContactType` (Lessor vs Tenant) be the deciding factor instead of the property link's `role`
column? These could disagree (a Contact could be linked with `role='landlord'` on
`contact_property` while not carrying the `Lessor` ContactType at all) — flagged for
confirmation, not silently resolved.

Both passes are signed independently: the in-pass once `completed` (protects the agency from
day one, independent of whether an out-pass ever happens), the out-pass/report once ITS
`completed`. `rental_inspections.signature_template_id`/`.signed_at` hold the result — no new
signing UI.

---

## 8. OFFLINE

The real precedent in this codebase is `mobile_photo_events` — a client-declared `phase`
(captured → queued → upload_started → upload_ok/failed/dropped), a client-generated
`client_upload_id` (idempotency), grouped by `batch_id`, with `occurred_at` as the phone's own
clock and `received` written server-side only on arrival. **Not** the rental-application
applicant-form autosave, which requires connectivity and degrades silently — the wrong model
to copy. This spec's `inspection_photos.client_upload_id`/`captured_at` (§4.4) exist
specifically to carry this pattern, and the SAME phase-tracking model applies to the
checklist/meter/notes answers themselves (`inspection_space_entries`), not just photos — a
locally-queued answer reconciles by the same keys once signal returns. A half-finished
inspection on a dead phone recovers exactly as `mobile_photo_events` already does: whatever
wasn't yet synced sits queued on-device, keyed by `client_upload_id`, resumable the next time
that device has signal and reopens the same inspection — this is mobile's local-queue
implementation to own (§9), the web/API side's job is to accept a late-arriving batch keyed
this way without ever rejecting it for being "too old."

---

## 9. THE MOBILE BOUNDARY (Andre's side, named explicitly)

**Web owns**: the `rental_inspections`/`property_spaces`/`inspection_spaces`/
`inspection_space_entries`/`inspection_photos`/`inspection_damage_items` tables and their API
(source of truth for both platforms); seeding `property_spaces` (§4.1.1 — this logic lives
once, server-side, so mobile and web can never seed differently); report generation (§6) and
the signature-template hookup (§7); the desktop form (§5); list screens, permissions, scoping,
settings (§10).

**Mobile owns (Andre)**: the in-the-field capture UX, the local offline queue mechanics (§8),
and the **ghost-image overlay** (overlaying the in-pass photo semi-transparently to guide the
same framing on the out-pass) — a camera/UI concern, built entirely on-device, **not designed
here**.

**What both sides must agree on**: (1) the space list shape — `inspection_spaces`' columns
(id, name, type, sort_order) are the one list both platforms render, mobile never invents its
own; (2) the photo model — `width`/`height`/`orientation`/`captured_at` exist specifically so
mobile's ghost overlay can scale/rotate/label correctly; if mobile needs more (device
orientation sensor data, camera intrinsics), that's an ADDITION proposed by mobile, not a
redesign; (3) the sync contract — the `mobile_photo_events`-style phase/idempotency-key/
client-clock pattern (§8) is what both sides implement against.

**Flagged**: the existing `MobileRentalImagesController` and its flat-photo API (§1.3) will
need to change shape once structured inspections replace its In/Out sections — coordinate
directly with Andre once this spec is approved; not designed here.

---

## 10. Own/branch/agency scoping, list screens, navigation, permissions, settings

### 10.1 Where this lives — no new tab

Per Johan's own instruction, verbatim: *"we can expand the inspections on the rental images
tab."* The existing Rental Images tab's In Inspection/Out Inspection cards are replaced IN
PLACE by the structured, per-space form (§5) — same tab, same gating
(`listing_type === 'rental'`). Custom ad-hoc sections on that tab (§1.3) are untouched. The
Rental tab is unaffected by this spec (its lease-term fields already exist and are read from,
per §2, not written to by inspections).

A separate **"Rentals → Inspections"** sidebar entry, alongside the existing "Rentals →
Properties"/"Core Matches"/"Rental Pipeline" entries, is the list screen required by §10.2 —
every inspection across the agency, not found by clicking into properties one at a time.

### 10.2 List screen

- **Search fields**: property address, tenant name, landlord name.
- **Sort columns**: property address, inspection date, status. **Default: most recently
  updated first.**
- **Filters**: status (draft/in_progress/completed/signed), type (in/out), a date range on
  `started_at`/`completed_at`.
- **Pagination**: standard page size, matching the Rental Applications control centre.
- **Empty state**: distinct copy for "none yet" vs. "no filter matches."

### 10.3 Scoping

OWN = created by the logged-in agent. BRANCH = the property's branch. AGENCY = the property's
agency (the outer, non-negotiable boundary via `BelongsToAgency`/`AgencyScope`). Enforced
identically on the list query, the detail/report view, the PDF download, and the future mobile
API — `PermissionService::getDataScope()` against a new `rental_inspections` permission
module, the same mechanism `RentalApplicationController::index()` already uses.

### 10.4 Full CRUD

Create (start an in-pass), Read (form mid-progress, finished report), Update (progressive
per-space save, §5), Archive (soft-delete, `rental_inspections.deleted_at` — a signed
inspection can still be archived from active lists; its report remains the historical record),
Restore (from an admin archive screen). **No hard delete anywhere in this module** — see §11.

### 10.5 Permissions

```php
['key' => 'rental_inspections.view',            'label' => 'View Rental Inspections',    'module' => 'rental_inspections', 'type' => 'access'],
['key' => 'rental_inspections.create',          'label' => 'Start & Record Inspections', 'module' => 'rental_inspections', 'type' => 'action'],
['key' => 'rental_inspections.manage_settings', 'label' => 'Manage Inspection Settings',  'module' => 'rental_inspections', 'type' => 'action'],
['key' => 'rental_inspections.archive',         'label' => 'Archive',                     'module' => 'rental_inspections', 'type' => 'action'],
```

### 10.6 Settings — `RentalInspectionSettings`, agency-scoped, sensible defaults, nothing hardcoded

Same pattern as `RentalApplicationQualifyingSetting` (one row per agency, static `xFor()`
readers, hardcoded fallback default, never a raw value read directly by a controller):

- **Default space template** (§4.1.1) — Domestic Bathroom, Water Meter, Electricity Meter,
  Garage, Garden, Outbuildings — the fallback default; agency-editable.
- **Default checklist items** (§4.3) — walls/floor/ceiling/fixtures/windows — same pattern.
- **Default meter units** (§4.3.1) — e.g. Electricity → "kWh", Water → "kL" — agency-editable
  per meter-type space name.
- **Max photos per space** — new setting, default **10** — an explicit ceiling so nothing
  about photo volume is hardcoded into a controller.
- **Days before `lease_end_date` to prompt an out-inspection** — new setting, default **30
  days**, reusing the existing calendar-source pattern (`RentalCalendarSource.php` already
  surfaces lease events — extend it, don't fork it). Reads `Property.lease_end_date` directly
  (§1.1) — no new tenancy table needed for this either, per §2's resolution.

Every setting above gets a control in `config/agency-onboarding-copy.php`'s rentals-related
step, with its `explain`/`affects` copy and canonical saver, per CLAUDE.md non-negotiable
#10a.

### 10.7 Domain events

Per `.ai/specs/corex-domain-events-spec.md`'s established mechanism: `InspectionStarted`,
`InspectionCompleted`, `InspectionSigned`.

---

## 11. No hard deletes — evidence does not get destroyed

Every table in this spec (`property_spaces`, `inspection_spaces`, `inspection_photos`,
`inspection_damage_items`, `rental_inspections` itself) uses soft-delete only. "Remove a
space" from either the property's persistent list or a specific inspection's snapshot always
means `deleted_at`, never a real delete — Johan's own reasoning, stated as the rule:
*"inspection records are evidence in a deposit dispute and that is exactly when someone will
want them back."* An archived space still shows on any inspection it was already recorded
against; it just stops appearing as an option to add NEW entries under, and can be restored
(unarchived) by an admin the same way any other CoreX soft-delete is restored.

---

## 12. Out of scope (explicitly, so it is never assumed later)

- **A deposit-holding/escrow ledger** — evidence and an estimate only, no money movement.
- **A general-purpose Lease/Tenancy module** — §2 deliberately avoids building one; the
  existing `contact_property` link plus the Rental tab's own dates are read from, not
  replaced.
- **`LeaseRecord` (the separate DocuPerfect-only lease-signing system)** — untouched, unrelated
  to this spec's mechanism.
- **Rental Images' custom ad-hoc galleries** — unreplaced (§1.3).
- **The mobile ghost-image overlay itself** — a requirement on the photo model (§4.4/§9), not
  designed here.
- **Per-space-type checklist customisation** — one shared default checklist to start (§4.3);
  named as a future refinement.
- **Automatic resolution of the "which tenant" ambiguity in §2.2** — flagged as an open
  question, not decided.
- **The legacy-photo manual reclassification tool** (§4.4.1) — named, not built.

---

## 13. OPEN QUESTIONS — for Johan, not guessed

1. **§2.2** — if two contacts ever hold `contact_property.role='tenant'` on the same property
   at once (a handover sequencing mistake), which one does a new in-pass assume, or should the
   agent be forced to confirm explicitly? Recommendation given, not decided.
2. **§7** — should an inspection's landlord/tenant signing role be resolved from
   `contact_property.role` (the property-link column) or from the Contact's own `ContactType`
   (Lessor/Tenant)? These can disagree. Recommendation given (use the link), not decided.
3. **§4.1** — a space added mid-inspection is proposed to ALSO persist to the property's
   permanent `property_spaces` list, not just that one inspection. This is a real design
   choice (not neutral) — confirm or override.

---

## 14. Acceptance criteria

- A rental property shows a structured inspection area on its existing Rental Images tab; a
  sale property does not.
- Starting the FIRST-ever in-pass for a property seeds `property_spaces` from advertised
  spaces + the agency default template, in that order; every space is independently
  addable/renameable/reorderable/archivable from that point on, and stays that way for every
  future tenancy.
- A space added mid-in-pass appears immediately on that inspection's out-pass AND on the next
  tenancy's fresh in-pass (per §13 Q3 above, pending confirmation).
- A meter-type space asks for a numeric reading + unit + photo, never a condition checklist.
- Out-pass screen shows each space's in-pass condition/reading/notes/photos directly alongside
  the out-pass's own entry fields for that space, live, not only in a separate report.
- The report renders every space with in/out columns side by side (including meter readings),
  downloadable as a filed PDF once both passes exist.
- Damage items on the out-pass appear under their space with an estimated-deduction total.
- Both passes independently reach the existing e-sign flow with landlord/tenant as the two
  resolved parties.
- Archiving a space, a photo, or a damage item never removes it from an inspection it was
  already recorded against, and never hard-deletes the underlying row.
- OWN/BRANCH/AGENCY scoping enforced on every list, detail, export, and download — verified by
  direct-URL-by-ID test.
- The list screen supports search/sort/filter/pagination with correct distinct empty states.
- Every setting in §10.6 is surfaced in the Agency Onboarding Setup Wizard in the same landing
  that ships it.

---

## 15. Files likely to be created (spec-level list — no code written)

- Migrations: `property_spaces`, `rental_inspections`, `inspection_spaces`,
  `inspection_space_entries`, `inspection_photos`, `inspection_damage_items`,
  `rental_inspection_settings`.
- Models: `PropertySpace`, `RentalInspection`, `InspectionSpace`, `InspectionSpaceEntry`,
  `InspectionPhoto`, `InspectionDamageItem`, `RentalInspectionSettings`.
- Controller(s): a new `RentalInspectionController` (agent-facing, mirroring
  `RentalApplicationController`'s scoping/tile pattern) + settings controller additions.
- Views: `properties/show.blade.php`'s existing Rental Images tab (in/out sections replaced in
  place), a new `Rentals → Inspections` list screen, the report Blade/PDF template.
- Config: `rental_inspections.*` permission keys, `agency-onboarding-copy.php` entries.
- Events/listeners per §10.7.
- Mobile API: NOT designed here — coordinate directly with Andre once this spec is approved,
  using §9 as the starting contract.
