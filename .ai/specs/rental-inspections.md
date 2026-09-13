# Rental Inspections — move-in / move-out condition reports, evidence, and deposit consequence

**Status:** Spec — awaiting Johan's sign-off. No code, no migrations written against this spec.
**Date:** 2026-09-13 (seventh revision — see §0 for what changed and why)
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
- **Couples and sharers are named together, not one standing in for the other.** If two people
  are both renting a property, both are named on the inspection and both sign — the system
  never has to pick one over the other.

One thing below is marked **OPEN QUESTION, genuinely reopened by what was learned this round**
— a real judgement call, not something I've decided quietly, because guessing wrong on it could
put the wrong person's name on a signed report.

---

## 0. What changed in this revision, and why

Seventh pass at this spec. Each correction has made it smaller, more accurate, and closer to
what actually exists — not bigger:

1. **First correction**: a "Rental" tab already exists for lease terms.
2. **Second correction**: the "Rental Images" tab already has in-inspection/out-inspection
   photo upload — this spec extends that tab, it does not add a new one.
3. **Third revision**: dropped a proposed new `PropertyTenancy` table entirely, in favour of
   reading `contact_property` (the mechanism cc3 is actually building against) directly; split
   spaces into a persistent per-property list plus a frozen per-inspection snapshot; made
   meters a typed space rather than a bolted-on feature.
4. **This revision — resolving Johan's answers to the three open questions, plus one genuinely
   new complication surfaced by coordinating with cc3 directly (not guessed):**
   - **Tenants are a SET, not a single contact.** `rental_inspections` no longer has a single
     `tenant_contact_id`/`landlord_contact_id` column — a new `inspection_parties` table
     records every tenant AND every landlord party, because Johan ruled that co-tenants (a
     couple, sharers) must all be named, never reduced to one. See §2, §4.2.
   - **A genuinely reopened problem, not resolved by that ruling on its own**: cc3 confirmed
     `contact_property` role='tenant' links are ADD-ONLY — nothing ever automatically removes
     an old tenant when they move out, and there is no way to tell a genuine current co-tenant
     from a link nobody cleaned up from a previous tenancy. "Include everyone currently
     linked" (Johan's instinct, and the right one for real co-tenants) could therefore also
     include a long-departed tenant on a brand-new signed report. See §2.2 — flagged for
     Johan, not decided here.
   - E-sign roles now resolve from the `contact_property` link's own `role` column, never from
     the Contact's `ContactType` — Johan's ruling, with the reasoning recorded. See §7.
   - A space added mid-inspection persists to the property's permanent list, visibly and
     reversibly — Johan's ruling. See §4.1.2.
   - Two new sections added, both requested directly: what happens when a tenancy ends (§2.4),
     and what an out-inspection with no matching in-inspection looks like (§5.1) — the second
     is not hypothetical, it will happen on day one for every property an agency already has
     tenanted when they join CoreX.
5. **Fifth pass — Johan's rulings on the review step, and one finding escalated rather than
   answered:**
   - **Q1 decided**: the visible review step ("these people will be named — remove anyone no
     longer here") is now the built design, not a proposed option. See §2.2.
   - **A named, separate strategic finding**: `contact_property` has no dates and cannot
     express a tenancy's start or end — found independently by this spec and by cc3 from the
     other direction. Not designed or built here; Johan is taking it to a product decision. See
     §2.3.
6. **Sixth pass — the review step's remove action, checked against what it actually does, not
   assumed:** raised that the remove action would call cc3's unlink directly, meaning it would
   edit the property's own tenant record, not just this inspection — and CONFIRMED, with file
   and line, that unlink is a hard delete (`RentalApplicationController.php:1083`,
   `->detach()` on a pivot table with no `deleted_at` column), and that the same pattern is
   systemic across three OTHER pre-existing call sites too. Reported directly to the conductor
   and to cc3 the moment it was confirmed.
7. **This revision — the fix is not wording, it's scope.** Once unlinking was confirmed to be
   a genuine, untraceable, permanent deletion, "word the remove button more carefully" was the
   wrong fix. **The review step no longer calls unlink at all.** Removing a name from an
   inspection's party list now only excludes them from THAT inspection — it writes nothing to
   `contact_property`, destroys nothing, and needs no undo path, because nothing destructive
   happens there any more. See §2.2, rewritten in full.

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
(confirmed directly with cc3, not assumed) — one live table, already audited via a domain
event on link.

**Correction, confirmed after this section was first written — "reversible" was wrong.**
Linking is genuinely reversible; UNlinking is not. Every unlink path on this pivot
(`RentalApplicationController.php:1083`, plus three pre-existing call sites — see §2.2) calls
`detach()`, a real SQL `DELETE`. `contact_property` has no `deleted_at` column. The row is
gone outright, not archived. This is a confirmed, reported, pre-existing hard-delete finding,
bigger than this spec (§2.2) — recorded here as fact, not fixed here.

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

## 2. THE DEPENDENCY — resolved without a new table, with the tenant question reopened by a real finding

### 2.1 Tenants and landlords are a SET, captured at creation, never re-read

Johan's ruling: *"an inspection is NOT tied to one tenant. It belongs to the property and the
tenancy period, and ALL contacts currently linked as tenant are parties to it... Do not pick
one and do not make the agent choose."* Reasoning, in his own words: couples and sharers are
the normal case, not an edge case, and forcing a choice between two people who both live there
would be wrong.

Design, resolved: `rental_inspections` no longer captures a single `tenant_contact_id` /
`landlord_contact_id`. At the moment an in-pass (or a no-prior-in-pass out-pass, §5.1) is
created, EVERY contact currently linked to the property via `contact_property` with
`role = 'tenant'` becomes a tenant party on that inspection; every contact with
`role IN ('landlord', 'lessor')` becomes a landlord party (§7 covers why the raw `role` value
is what's used, not the Contact's own type). This is a full set, not a single pick, stored in
a new `inspection_parties` table (§4.2) — the report names all of them, and all of them sign
(§7).

Lease dates (`lease_start_date`/`lease_end_date`) are copied from the property's current
Rental-tab values (§1.1) at the same moment. All of this is captured ONCE, at creation, and
never re-read — the same "frozen snapshot" principle already used for spaces (§4.1). **No new
tenancy table is required** — `contact_property` plus this one-time capture is enough.

### 2.2 The problem this reopened, and the answer — DECIDED, correct independent of whether §2.2's own finding is ever fixed

Coordinated directly with cc3 (who is building the `contact_property` tenant link right now)
before finalising this, per Johan's own instruction — the answer changes the picture twice
over: once on "which tenants," and again, more seriously, on what the fix must NOT do.

- **Links are add-only.** `linkTenantProperty()` only ever adds a role='tenant' row for the
  ONE contact being linked; it never touches any other contact's tenant link on the same
  property. Two genuine co-tenants correctly accumulate side by side — this part is exactly
  right for Johan's ruling.
- **Nothing ever automatically removes a tenant link.** The only removal path is a manual,
  deliberate "unlink" button, per application, clicked by an agent. There is no "tenant moved
  out" detection anywhere.
- **There is no grouping key.** `contact_property` is `id, contact_id, property_id, role,
  timestamps` — nothing ties two links together as "the same tenancy," and nothing
  distinguishes a genuine current co-tenant from a link nobody ever cleaned up from a tenancy
  three years ago.
- **`Property.status` is not a signal of "currently tenanted"** — confirmed with cc3 this is
  not wired to the tenant link at all; don't key anything off it.
- **CONFIRMED, with file and line — unlinking is a hard delete, and it's systemic.** Every
  unlink path on this pivot fires a real SQL `DELETE`, and `contact_property` has no
  `deleted_at` column at all — the row does not survive in any form once removed, not even as
  history; only a separate audit-log entry (where one exists) survives, and it does not
  survive the pivot row's own `role`/`created_at`. Four call sites do this today:
  `RentalApplicationController.php:1083` (cc3's own, at least logs a human-readable audit
  entry first), `ContactPropertyController.php:112`, `PropertyContactController.php:388`, and
  `MobilePropertyController.php:1284` (the latter three log nothing at all before destroying
  the row). This is a real, pre-existing, system-wide violation of the project's
  no-hard-deletes rule — reported directly to the conductor and to cc3 the moment it was
  confirmed. **It is Johan's decision how far to fix it, and this spec does not depend on that
  decision landing either way** — see below.

**The consequence, stated plainly**: "include every contact currently linked as role='tenant'"
— Johan's own correct instinct for the couple/sharer case — could, on a property nobody has
tidied up, ALSO include a tenant who moved out two tenancies ago, with the system having no
way to tell the difference. Combined with the hard-delete finding, this means the OBVIOUS
naive fix — let the agent remove a stale name from the review list by calling the existing
unlink action — would make an inspection screen the trigger for a permanent, untraceable
deletion of a tenancy record. **That cannot ship.**

**DECIDED — the review step is inspection-scoped only. It never calls unlink, never writes to
`contact_property`, and destroys nothing.** At the moment an in-pass (or a no-prior-in-pass
out-pass, §5.1) is started, the agent sees the full current set of linked tenant/landlord
contacts as a plain, visible list — *"these people will be named on this inspection: [list] —
untick anyone who's not part of this tenancy."* Unticking a name does exactly one thing: that
contact is excluded from THIS inspection's own `inspection_parties` set (§4.2). Nothing is
removed from the property, nothing is deleted, nothing is even written back to
`contact_property` — the review step only decides what gets frozen onto this one inspection.

This is a better design than the one it replaces, not a compromise: the agent's actual
knowledge at that moment is *"this person is not part of this inspection"* — that is a
narrower, safer, and more honestly-scoped statement than *"this person was never a tenant
here,"* which is what calling unlink would have silently claimed on their behalf. It is still
**not** a forced single pick between people who genuinely live there together — Johan's
original objection still doesn't apply, since nobody is choosing between real co-tenants, only
excluding names that don't belong on this one form.

**If an agent genuinely believes a `contact_property` link is stale and should be removed from
the property itself, that stays exactly where it lives today** — a separate, deliberate action
on the property's own Contacts tab or the contact's own Properties tab, never a side effect of
starting an inspection. The review step is a good MOMENT to *notice* a stale link (the agent
is looking straight at the list); it is deliberately not the place to *act* on one, precisely
because that action is currently destructive and irreversible, and an inspection screen is not
where an irreversible record deletion should ever be one tap away.

**Why this makes the spec simpler, not more complicated**: with nothing property-level
happening in this screen, there is nothing to word carefully as a property-level action, and
nothing to build an undo path for — both real requirements under the design this replaces,
both gone now because the destructive action they were protecting against is no longer
reachable from here at all. This design is correct today, and stays correct without any
change to this spec if the hard-delete finding above is ever fixed, or if it never is.

### 2.3 STRATEGIC FINDING, escalated — a tenancy has no start or end date anywhere in this system

This is bigger than inspections, and is recorded here as its own named finding, not a caveat
buried inside §2.2's interim fix. **Not designed or built here — Johan is taking this to a
product decision directly.**

`contact_property` links a contact to a property with a role. It has no date. Nothing marks
when a tenancy began, nothing marks when it ended, and nothing distinguishes "this tenant, now"
from "a tenant, at some point." The Rental tab's own `lease_start_date`/`lease_end_date`
(§1.1) exist, but describe the PROPERTY's current advertised lease terms, not a specific
tenant's occupancy — they get overwritten, not versioned, and were never linked to a specific
`contact_property` row in the first place. **The concept missing from this codebase is a
tenancy itself: a record with a start, an end, and the parties who held it.**

This was found from two independent directions, which is usually the sign a gap is real, not
imagined: this spec found it while working out how an inspection knows who its parties are;
cc3 found the identical gap independently while building the property-link action itself, from
the other side. Johan's own words on what the Rental tab is for — *"its the lease / parties /
and whatever else that we can put on that rental tab"* — describe exactly this concept without
it existing yet.

**§2.2's review step is the correct INTERIM answer with what exists today** — it makes the
risk visible to the person best placed to catch it, at the moment it matters, and it is being
built on that basis. **It becomes unnecessary the day tenancy dates exist**, because a real
tenancy record would answer "who are the current parties" directly, with no review needed. This
spec does not propose a shape for that record, does not add it to any table above, and does
not depend on it existing — §2.1's design works correctly without it, today, indefinitely, if
that's where Johan lands. Flagged, not decided, not built.

### 2.4 What happens when a tenancy ends and a new one begins — stated plainly, for the record

Because each inspection's tenant/landlord/dates are captured once at creation (§2.1) and never
re-derived, **a new tenancy's in-pass does not depend on the old tenancy's `contact_property`
links ever being removed.** The new in-pass captures whichever contacts and dates are current
and confirmed via the §2.2 review step AT THAT MOMENT, and that capture is what the new
inspection permanently belongs to — regardless of what the old tenant's own now-stale
`contact_property` row still says. The PREVIOUS tenancy's inspections are entirely unaffected:
they keep the tenant/landlord/dates they captured at their own creation, forever, whether or
not anyone ever manually unlinks the old tenant afterward.

**This means tidying up old `contact_property` links is never required for correctness** —
it's a separate, optional housekeeping action (the existing unlink button) that improves the
accuracy of future "who's currently linked" lookups, but a property with accumulated stale
tenant links from years of tenancies still produces correct, uncorrupted historical
inspections either way, because each one carries its own frozen answer.

---

## 3. Pillar connections

- **Property** — an inspection belongs to a property; its space list starts from the
  property's own advertised layout and persists on the property going forward (§4.1).
- **Contact** — an inspection's landlord(s)/tenant(s) resolve to Contacts via `contact_property`
  (§2.1); its signing parties (§7) are the same people, potentially more than two.
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
  added_via_inspection_id (FK, nullable) -- which inspection first added this, when source=manual
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

**A space added mid-inspection persists to `property_spaces` — Johan's ruling, resolved.**
*"The agent standing in the property is the person with the best information about what that
property actually contains... that is a fact about the property, not about that one visit."*
`property_spaces.source = 'manual'` (§4.1) already marks exactly this. Johan's one condition:
**visible and reversible** — the property's own space-list screen shows which spaces came from
advertising, which from the agency template, and which an agent added on a specific
inspection (the `source` column plus a reference back to which inspection first added it), and
any of them can be archived afterward if it was added in error — same soft-delete-only rule as
everything else in this module (§11), no special case.

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
  lease_start_date, lease_end_date  -- captured at creation, see §2.1 — never re-read after
  started_at, completed_at
  created_by_user_id
  signature_template_id (FK, nullable until signing starts)
  signed_at
  deleted_at                        -- soft delete only
```

```
inspection_parties
  id
  rental_inspection_id (FK)
  contact_id (FK)
  role                    -- enum: tenant | landlord  (mirrors contact_property.role values, §2.1)
  created_at
```

One `rental_inspections` row per PASS (in, out) — not one row holding both. Every tenant and
landlord captured at creation (§2.1) is one row in `inspection_parties` — a genuine set, not a
single column, because Johan's ruling requires ALL co-tenants named, never reduced to one.

An in-pass and its out-pass are linked by sharing the same `inspection_spaces` snapshot (§4.1)
and — practically — by being the two most recent in/out rows for the same property with the
SAME set of tenant `inspection_parties`; there is no separate join table connecting them,
since nothing beyond that is needed given §2's resolution. The §2.2 review step's confirmed
set is exactly what reliably pairs an in-pass with its out-pass in practice.

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

### 5.1 When an out-inspection has no matching in-inspection

Requested explicitly, and not hypothetical: every property an agency already has tenanted the
day they join CoreX will hit this. There is no in-pass to compare against because none was ever
recorded in this system.

**This must degrade gracefully, not silently.** Starting an out-pass with no matching in-pass
for this property (checked by: does an in-pass exist sharing this property and the same tenant
`inspection_parties` set, per §4.2) is a genuine, supported path, not an error state:

- The out-pass seeds its OWN space list directly from `property_spaces` (§4.1) — effectively
  the same seeding an in-pass would do, since there's no snapshot to inherit from.
- Every screen involved — the form, and critically the finished report (§6) — shows a plain,
  unmissable statement: **"No move-in inspection is on file for this tenancy — this report
  shows move-out condition only."** This is placed once, prominently, not repeated as noise on
  every row.
- The report's IN columns (§6) render this same message in place of blank cells — **never a
  blank IN column**, because a blank cell next to a filled OUT cell could be misread as "no
  damage was found at move-in" when the true meaning is "we have no idea what move-in looked
  like." An empty cell and "no record exists" are different facts and must never look the
  same.
- Signing still works normally (§7) — an out-only report is still real evidence of the
  property's condition at that moment, just without a documented baseline to compare it to.

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
createLeaseRecord()` for the identical `tenant`/`landlord` role pair. An inspection's parties
resolve from `inspection_parties` (§2.1, §4.2) — potentially MORE than two entries, since
Johan's ruling means co-tenants are both named and both sign. `SignatureTemplate` already
supports this: its own duplicate-role handling suffixes same-role parties (`tenant`,
`tenant_2`, ...) rather than requiring exactly one of each role — this spec relies on that
existing behaviour rather than inventing anything new for the multi-party case.

**Which value decides the signing role — RESOLVED, Johan's ruling.** `ContactType`'s canonical
set has `Lessor` (id 10) and a separately-added `Tenant` (id 11, `esign_role='lessee'`) —
neither a clean 1:1 with "landlord"/"tenant," and a Contact's OWN type is never authoritative
for this, because the same person can genuinely be a landlord on one property and a tenant on
another (a small investor — common in Johan's market), and contact type is additive, never
replaced, making it exactly the wrong signal to read for "who are they on THIS property."
**The `contact_property.role` column on the property link wins, always** — a Contact linked
`role='landlord'` on property A signs as landlord for an inspection on property A, full stop,
regardless of what `ContactType`(s) that Contact otherwise carries.

**Where the two disagree, that disagreement is surfaced, never silently resolved** — per
Johan's own instruction. If a landlord-role-linked Contact does not also carry a `Lessor`
ContactType (or a tenant-role-linked Contact doesn't carry `Tenant`), the inspection's party
list/review step (§2.2) shows a plain, visible note (e.g. "Signing as Landlord — this contact
is not tagged as Lessor in Contacts") rather than hiding the mismatch — informational, not
blocking; the link's role still wins for signing purposes, the note is so an agent isn't
confused later about why a contact's badges don't match their signing role.

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
- **A tenancy record with a start and end date** (§2.3) — a real, named, escalated finding,
  not designed or built here; Johan's own product decision to make.
- **Cleaning up old `contact_property` tenant links** — not required for this module's
  correctness (§2.4), and not built or enforced here; remains the existing manual unlink
  action, unchanged.
- **The legacy-photo manual reclassification tool** (§4.4.1) — named, not built.
- **Fixing the hard-delete on `contact_property` unlink** (§2.2) — a real, confirmed finding,
  reported to the conductor and to cc3 directly the moment it was found; not this spec's fix
  to design or make. This spec's own design (§2.1/§2.2) is correct either way — it does not
  depend on that fix landing.

---

## 13. Questions this spec raised, and where each one landed

All three of the original open questions are now resolved by Johan's own ruling. One of them
surfaced a finding bigger than this spec, which is tracked separately (§13.1), not left mixed
in with a resolved question.

1. **RESOLVED — §7, signing role.** Use `contact_property.role`, never the Contact's own
   `ContactType`. Johan's reasoning: the same person can be a landlord on one property and a
   tenant on another, and contact type is additive/never-replaced, making it the wrong signal
   for "who are they on THIS property." Disagreement between the two is surfaced to the agent,
   never silently hidden.
2. **RESOLVED — §4.1.2, mid-inspection space additions.** Persist to the property's permanent
   list, visibly (marked where it came from) and reversibly (archivable, never hard-deleted).
   Johan's reasoning: the agent in the property has the best information about what's actually
   there, and the space most likely to matter in a dispute is exactly the one most likely to
   have been missed on the advert.
3. **RESOLVED — §2.2, which tenants are named.** Johan's original ruling — include every
   contact currently linked as `role='tenant'`, never force a pick between real co-tenants —
   stands, and is built as a visible review step: the agent sees the current set and can
   UNTICK anyone who's not part of this tenancy. Resolving this surfaced that the obvious way
   to build the "remove" side of that step (calling the existing unlink action) would silently
   trigger a permanent, untraceable deletion of a tenancy record (§13.1) — so the review step
   was corrected to be inspection-scoped only: it excludes a name from this inspection, full
   stop, and never touches `contact_property` at all. Correct independent of whether the
   hard-delete finding below is ever fixed.

### 13.1 Not a question this spec answers — two findings escalated to Johan directly

Resolving question 3 surfaced two things bigger than an inspections detail, each tracked as
its own named section, not folded into question 3's resolution above:

- **`contact_property` has no way to express a tenancy's start or end** — found independently
  from two directions (this spec, and cc3 building the link itself). Written up in §2.3.
- **Unlinking a tenant is a hard delete, confirmed systemic across four call sites** — found
  while working out what the review step's "remove" action should safely do. Written up in
  §2.2 itself, since it directly shaped that section's final design.

**Neither is a spec decision — Johan is taking both to a product decision directly, and this
spec neither designs nor depends on either outcome.**

---

## 14. Acceptance criteria

- A rental property shows a structured inspection area on its existing Rental Images tab; a
  sale property does not.
- Starting the FIRST-ever in-pass for a property seeds `property_spaces` from advertised
  spaces + the agency default template, in that order; every space is independently
  addable/renameable/reorderable/archivable from that point on, and stays that way for every
  future tenancy.
- A space added mid-in-pass appears immediately on that inspection's out-pass AND on the next
  tenancy's fresh in-pass (per §13 point 2, resolved).
- A meter-type space asks for a numeric reading + unit + photo, never a condition checklist.
- Out-pass screen shows each space's in-pass condition/reading/notes/photos directly alongside
  the out-pass's own entry fields for that space, live, not only in a separate report.
- An out-pass started with no matching in-pass seeds its own space list from `property_spaces`
  and shows the "no move-in record" statement in place of every blank IN cell, never a bare
  empty column (§5.1).
- Two contacts genuinely co-tenanting a property are BOTH named on the inspection and BOTH
  reach the signing flow — never one standing in for the other (§2.1).
- Unticking a name in the review step (§2.2) excludes them from that inspection ONLY —
  verified directly: `contact_property` is unchanged before and after, no unlink action fires,
  the contact remains linked to the property exactly as before.
- The report renders every space with in/out columns side by side (including meter readings),
  downloadable as a filed PDF once both passes exist.
- Damage items on the out-pass appear under their space with an estimated-deduction total.
- Every party in `inspection_parties` independently reaches the existing e-sign flow, with
  their signing role resolved from `contact_property.role`, never the Contact's own type
  (§7).
- Archiving a space, a photo, or a damage item never removes it from an inspection it was
  already recorded against, and never hard-deletes the underlying row.
- OWN/BRANCH/AGENCY scoping enforced on every list, detail, export, and download — verified by
  direct-URL-by-ID test.
- The list screen supports search/sort/filter/pagination with correct distinct empty states.
- Every setting in §10.6 is surfaced in the Agency Onboarding Setup Wizard in the same landing
  that ships it.

---

## 15. Files likely to be created (spec-level list — no code written)

- Migrations: `property_spaces`, `rental_inspections`, `inspection_parties`,
  `inspection_spaces`, `inspection_space_entries`, `inspection_photos`,
  `inspection_damage_items`, `rental_inspection_settings`.
- Models: `PropertySpace`, `RentalInspection`, `InspectionParty`, `InspectionSpace`,
  `InspectionSpaceEntry`, `InspectionPhoto`, `InspectionDamageItem`,
  `RentalInspectionSettings`.
- Controller(s): a new `RentalInspectionController` (agent-facing, mirroring
  `RentalApplicationController`'s scoping/tile pattern) + settings controller additions.
- Views: `properties/show.blade.php`'s existing Rental Images tab (in/out sections replaced in
  place), a new `Rentals → Inspections` list screen, the report Blade/PDF template.
- Config: `rental_inspections.*` permission keys, `agency-onboarding-copy.php` entries.
- Events/listeners per §10.7.
- Mobile API: NOT designed here — coordinate directly with Andre once this spec is approved,
  using §9 as the starting contract.
