# Rental Inspection Form — the paper document, faithfully specified

**Status:** Partially built, 2026-09-21. Johan confirmed the gap analysis (§1) himself, in writing, after
opening the real recording surface directly — the hold on building was lifted for the in-vs-out
comparison specifically (§7), on his own instruction: build the comparison mechanics if unambiguous, stop
before anything that turns a difference into a number against a deposit. §7.3 records exactly what
shipped. Everything else in this document (header-block capture beyond keys/remotes/meters, four-state
grading, per-room notes, Inventory) remains spec-only, awaiting his ruling.
**Date:** 2026-09-21
**Author:** cc5
**Pillar:** Property (`Property`), Deal (`Lease`), Contact (tenant, landlord), Agent (`User`) — this
spec does not introduce a new pillar connection; it describes a gap against the existing
`.ai/specs/rental-inspections.md` data model, which already connects to all four.
**Relationship to the existing spec:** `.ai/specs/rental-inspections.md` is the built inspections
system — its data model (items, observations, discrepancies, three-party signatures, settings) is
real, substantial, and already landed on QA1 across five stages. This document does not replace it or
repeat its data model. It is a gap analysis against two real paper documents Johan sent, transcribed
verbatim by the conductor from HFC's actual use on 31 Aug 2026, comparing them to what the built
system captures today, so Johan can rule on whether to close the gap. Where this spec proposes new
data-model shape, it is additive to the existing tables, not a redesign of them.

---

## 0. Why this document exists

Johan sent the conductor two real paper documents his agency actually uses — a filled-in out-inspection
and a separate inventory, both dated 31 Aug 2026, both from a real Ramsgate flat. He asked how CoreX's
inspection screen compares. The honest answer, given to him directly: his paper form is a better
instrument than our software. This document is the faithful specification of what the paper form
*actually does* — not CoreX's interpretation of what an inspection form should do — so that decision can
be made on the real thing, not a summary of it.

---

## 1. What CoreX does today — stated plainly, verified directly, not assumed

Two different things are true at once here, and both matter to Johan's decision, so both are stated
precisely rather than collapsed into one impression.

### 1.1 What Johan actually saw on QA1 inspection 1

`https://qatesting1.corexos.co.za/corex/rental-inspections/1` renders `corex.rental-inspections.show`
(`resources/views/corex/rental-inspections/show.blade.php`) — the agency-level **list screen's** detail
view. Checked directly against the source, not assumed: this view is *deliberately* read-only and thin
by the existing spec's own design (`.ai/specs/rental-inspections.md` §5, "Create" note — "this screen's
own `show()` stays read-only, recording still only happens on the tab"). It shows the property, the
status, the four lifecycle dates (`scheduled_for`, `fault_report_deadline_at`, `signing_deadline_at`,
`completed_at`), who recorded it, and — because inspection 1 genuinely has zero observations recorded
against it — the literal string `"No observations recorded yet."`
(`resources/views/corex/rental-inspections/show.blade.php:125`). What Johan described is accurate for
what he looked at: that screen, on that record, really is that thin. It is not evidence the deeper
system doesn't exist — it's evidence that inspection 1 is an empty draft and that this particular screen
was never meant to be where recording happens.

### 1.2 What actually exists underneath — the real recording surface

The **property's own Rental Images tab** (`RentalInspectionRecordingController`, not the list screen) is
where an agent actually records an inspection, and it already has real structure:

- A `rental_inspection_items` row per space/meter, condition graded via `rental_inspection_observations`
  — condition enum **`good | fair | damaged | not_working | missing | other`**
  (`app/Models/RentalInspectionObservation.php:24-29`), notes **required whenever condition ≠ good**
  (same file, `requiresNotes()`), photos attached per observation.
- Two or more conflicting observations on the same item within the same inspection are detected
  automatically and block completion until resolved (`RentalInspectionDiscrepancy`).
- Three-party signing — tenant, landlord, agent — with a genuine refused-and-recorded disposition and a
  wet-ink (paper-signed, photographed/scanned) fallback, already built
  (`RentalInspectionSignature::capture()`).
- An agency-configurable default item list per space type — but see §1.3, this is where the real gap
  starts.

This matters to the decision Johan is making: rebuilding the *grading, evidence, and signing* mechanics
from nothing is **not** the work in front of him. Those exist and are already fairly rigorous. What is
genuinely missing is narrower, and named exactly in §1.3-§1.6 below.

### 1.3 The four gaps, checked directly against the code — not asserted

1. **No four-state Good/OK/Bad/N/A grading.** The built condition enum is
   `good/fair/damaged/not_working/missing/other` — six states, richer in some ways, but there is no "OK"
   equivalent and no "N/A" state at all, per-item or per-room. Paper form: one tick out of exactly four,
   every time, and an entire room can be struck N/A in one action (Bedroom 3 and Bedroom 4 both are, in
   the real example).
2. **No header-block capture anywhere on `rental_inspections`.** Checked the actual migration
   (`database/migrations/..._create_rental_inspections_table.php`): the table has `type`, `status`,
   `scheduled_for`, the deadline timestamps, and cancellation fields — nothing else. There is no column,
   anywhere in the built system, for meter readings, furnished state, key count, remote count, property
   type, or move-in date. None of it is captured today, at all.
3. **No real per-room item vocabularies.** `RentalInspectionSetting::DEFAULT_ROOM_TYPE_ITEMS` is a flat
   five items — `Ceiling, Walls, Floors, Windows, Doors` — applied **identically to every space type**
   (`app/Models/RentalInspectionSetting.php:83`, confirmed by reading `roomTypeItemDefaultsFor()`
   itself, which loops every `config('property-spaces.all_space_types')` entry and assigns the same
   constant to each). The paper form's Kitchen list alone has 18 items, none of which are "Ceiling, Walls,
   Floors, Windows, Doors."
4. **No deposit outcome.** Checked the entire existing spec (`.ai/specs/rental-inspections.md`) for any
   comparison mechanism or deposit computation — none exists. `carryForwardItems()`/`mostRecentOutFor()`
   surface an item's full history for an agent to *read*, but nothing compares an in-inspection
   observation against an out-inspection observation for the same item and produces a decision. This is
   the single biggest gap, because it's the entire *purpose* of the paper form (§7).

### 1.4 The room-vocabulary mismatch is structural, not a data-entry gap

`config('property-spaces.all_space_types')` (48 types) does not contain **Sunroom, Rubbish bin room,
Entrance from glass front door, Dining room/Balcony** (it has "Dining Room," not the same thing), or
**Outside front of house** — every one of these appears on Johan's real inventory. It does contain
**Laundry Room**. This is not a shortfall to patch with one or two additions — it confirms the existing
spec's own ruling (`.ai/specs/rental-inspections.md` §0.6: "inspection spaces differ from the advertised
room list") was correct, and that whatever vocabulary an inspection/inventory form uses must be free-text
first, agency-editable, never constrained to the marketing space-type list.

### 1.5 No Inventory document exists in any form

Checked directly: no model, migration, or table named anything inventory-shaped exists anywhere in the
codebase. `PropertyRoom` (`app/Models/PropertyRoom.php`) is a neutral per-property room concept, built
for exactly this future use (its own docblock: "Rentals' inspection facets... and Sales' future
inventory both join against this table"), but nothing has been built on top of it yet.

### 1.6 Two tenant signature slots

The built `RentalInspectionSignature` model supports **any number** of tenant party rows — it resolves
tenants from `LeaseTenant` per lease, not a fixed count — so "two tenant slots" is already structurally
fine; the paper form's fixed two slots are a paper-form artifact of a printed layout, not a system
constraint worth replicating.

---

## 2. The two documents — what each actually captures

### 2.1 Document 1: the out-inspection (5 pages, HFC letterhead)

**Header block** (per inspection event, captured once at intake, referenced at out-inspection):
- Inspector name + date
- Landlord name + signature
- Tenant 1 name + signature; Tenant 2 name + signature (paper allows two; §1.6 — the built system
  already allows any number via `LeaseTenant`)
- Move-in date
- Address (already resolved via the property/lease — no new capture needed)
- Property type: House | Flat/Apartment | Townhouse | Commercial/Office — tick one
- Meter readings **as free text**: Electricity, Water — the real example reads "BODY CORP" and "BODY
  CORP", not numbers. Any format must be accepted; this is never a numeric-only field.
- Furnished state: also free text — the real example reads "PARTIALLY," not a boolean.
- Keys: count as free text ("3x SET KEYS")
- Remotes: count as free text ("3x REMOTES")

**Legal spine — printed verbatim on the form, and this is the reason the form exists at all:**
> "Tenant(s) complete(s) this checklist within seven days of moving in and tenant(s) and landlord or
> manager review property and complete checklist together and mutually agree on the condition of the
> property upon move-in by signing this form. Each party keeps a copy of signed checklist. Tenant(s) and
> landlord or manager uses the move-in checklist during the pre-move out inspection and again when
> determaning if any of the tenant's deposit will be retained for cleaning or repairs after move-out."
>
> "BE SPECIFIC AND DETAILED WHEN FILLING OUT THE CHECKLIST"

This is not decoration. It states the form's entire purpose in one sentence: the in-inspection and the
out-inspection are the same instrument used twice, and the difference between the two readings is what
decides the deposit. §7 specs this directly.

**Room tables.** Every room is a table: room name | Good | OK | Bad — tick exactly one per item.
`N/A` is written in by hand per item, or across an entire room in one stroke (Bedroom 3, Bedroom 4 in
the real example — both struck N/A wholesale, not item-by-item).

**Item vocabularies — the real ones, agency-default, never hardcoded to HFC (§8):**

| Room type | Item count | Items |
|---|---|---|
| Kitchen | 18 | Walls, Ceiling, Ceiling Fans, Aircon, Light Fittings, Light Switches, Carpet, Tiles, Blinds, Curtain Rails, Plug Sockets, Stoves, Stove Plates, Oven, Tops, Hinges, Cupboard Doors, Door Frames |
| En Suite Bathroom / Bathroom | 15 | Ceiling, Extractor Fan, Walls, Tiles, Light Fittings, Light Switches, Bath, Shower, Basin, Toilet, Taps, Towel Rails, Blinds, Curtain Rails, Door |
| Main Bedroom / Bedroom | 14 | Walls, Ceilings, Ceiling Fans, Aircon, Light Fittings, Light Switches, Carpet, Tiles, Blinds, Curtain Rails, Plug Sockets, Cupboard Doors, Hinges, Mirror |
| Garage | 7 | Ceiling, Doors, Floors, Lights, Light Fittings, Walls, Windows |
| Yard | 6 | Fences and Gates, Retaining Wall, Garden, Gutters, Downspouts, Roof |

Each room table has **blank rows at the bottom** for the agent to add items on-site (§4.7), and its own
**multi-line notes box** immediately below the table — this is where the real evidentiary content lives;
the ticks are a skeleton, the notes carry the specifics, verbatim from the real example:

- Kitchen: *"STOVE TOP PLATES STAIN MARKS"*
- En Suite Bathroom: *"ROUGH PATCH IN BATH - REFLECTS DIRTY. TOILET DIRT STAINS - RUST WEAR + TEAR"*
- Main Bedroom: *"1x OUTSIDE HINGE BROKEN FROM CUPBOARD BEHIND BEDROOM DOOR / 2x REMOTES / 1x KEY IN
  DOOR / 1x WALL MIRROR - DARK FRAME"*
- Bedroom 1: *"3x NAILS IN WALL / CAN'T TEST AIRCON NO BATTERIES / 1x KEY IN DOOR / DAMP UNDER WINDOWS IN
  CORNER / DAMP ON WALL UNDER MIRROR"* — plus a hand-added note under a different room's heading:
  *"ENSUITE BATHROOM — CUPBOARDS UNDER SINK WATER DAMAGE"* (a real example of an agent needing to record
  something against a room that isn't the one whose table they're currently filling in — the form itself
  is not perfectly rigid about this, and neither should the system be, per §4.7)
- Bedroom 2: *"2x PINS IN WALL / 1x HINGE BROKEN CLOSET"*
- Bathroom 1 (labelled "ENSUITE SECOND BEDROOM" by hand): *"TOILET LEAKING - CLOSED TAP / FAIR WEAR &
  TEAR CUPBOARDS"*
- Garage: *"AUTOMATED DOORS WORKING CONDITION / 1ST WINDOW DOESNT UNLATCHED / FISHING RODS PAINT ETC
  STILL THERE"*
- Yard: *"BODY CORP"*

Rooms actually present in this one real inspection: Kitchen, En Suite Bathroom, Main Bedroom, Bedroom 1,
Bedroom 2, Bedroom 3 (N/A), Bedroom 4 (N/A), Bathroom 1, Garage, Yard.

**Footer**: a large free-text "Over all notes" block — real example: *"OVERALL - APARTMENT CLEAN - FAIR -
PARTIALLY FURNISHED"* — followed by inspector signature, tenant signature, date. Both signed in the real
example.

### 2.2 Document 2: the inventory (2 pages, handwritten) — a genuinely separate document

Not condition grading at all. A counted list of contents, grouped by room, one line per item:
**quantity + description**. Real examples, verbatim:

- *"2x Single beds matrasses + bases"*
- *"1x Wooden TV Table"*
- *"4x Fishing rods on top of cupboard"*
- *"1x LG Fridge/freezer silver"*
- *"1x Defy silver dishwasher"*
- *"2x Blue gasbottles in gas cupboard"*
- *"1x Silver padlock combination"*
- *"2x Big pots under dustbin room window missing"* — note "missing" recorded **inline as part of the
  item's own text**, not as a separate status field. The inventory has no condition/status column at
  all; if something is absent, that fact is written into the description itself.

Signed at the foot of each page.

**Rooms in the inventory that do not appear in the inspection at all**: Sunroom, Rubbish bin room,
Entrance from glass front door, Dining room/Balcony, Lounge, Laundry Room, Outside front of house. The
two documents do not share a room list — confirmed against §1.4, neither document's room list is the
marketing space-type list either.

---

## 3. What this spec must capture — the eight requirements, each grounded in the source above

1. **Four-state condition per item: Good / OK / Bad / N/A.** N/A must be assignable per item AND as a
   single action across a whole room (§2.1, Bedroom 3/4). This is additive alongside the existing
   six-state `condition` enum on `rental_inspection_observations` — see §5.1 for how the two coexist
   without breaking anything already built.
2. **Per-room notes — free text, multi-line.** This is where the real value lives (§2.1's real examples
   above are all notes, not ticks). A per-room note is distinct from a per-item note — the paper form has
   both (item-level ticks carry no text; the room's notes box is where detail is recorded), and the note
   sometimes needs to reference a *different* room than the one it's filed under (Bedroom 1's ensuite
   note, §2.1) — the capture mechanism must not force a note into a rigid single-room box if the agent
   is standing in front of something that spans two.
3. **Overall notes** — one free-text block per inspection event, independent of any room.
4. **The header block** — inspector, date, landlord name+signature, tenant name+signature (any number of
   tenant slots, §1.6), move-in date, property type (tick-one), meter readings as free text, furnished
   state as free text, keys count as free text, remotes count as free text. None of this exists on
   `rental_inspections` today (§1.3.2) — every field here is a new column or a new small table, additive
   to the existing schema.
5. **Signature capture for landlord + tenant(s)** — already built (§1.2, `RentalInspectionSignature`);
   this spec does not ask for new signature mechanics, only that the header-block fields above sit
   alongside the existing signing flow, not replace it.
6. **The real item vocabularies (§2.1's table) as room-type defaults** — replacing the current flat
   five-item-for-every-type default (§1.3.3) with real per-type lists. Multi-agency: HFC's lists are
   HFC's own starting defaults, editable per agency, never hardcoded as "the" list (§8).
7. **Room for an agent to add items on-site** — the paper form's blank rows. The existing system already
   supports ad-hoc item creation per property (`rental_inspection_items`, kind='space'/'meter', free-text
   label) — this requirement is closer to "make sure that path stays available mid-inspection" than a
   new mechanism.
8. **Inventory as a genuinely separate document** — its own shape (quantity + description per room, no
   condition grading, "missing" recorded inline in the text rather than as a status), its own room list,
   sharing only the property it belongs to. See §6.

---

## 4. Proposed data-model shape — additive, not a redesign

**[cc5 design call — none of this is Johan-ruled yet; flagged throughout for his decision, not assumed]**

### 4.1 Header block — built by cc6 as columns directly on `rental_inspections`, not a new table

**Superseded, 2026-09-21**: this section originally proposed a separate `rental_inspection_intake_details`
table. cc6 built the header-block fields directly on `rental_inspections` instead (one row per inspection
event, so "in" and "out" naturally carry their own values, same as everything else on that model) —
simpler than a join for no real benefit, since it's a strict 1:1 relationship either way. Coordinated
directly (cc5↔cc6, cross-session) before either side built anything, so the comparison mechanics in §7
read these exact names:

```
rental_inspections  (columns added, not a new table)
  keys_count                  -- nullable int
  keys_description             -- nullable string
  remotes_count                -- nullable int
  remotes_description           -- nullable string
  electricity_meter_reading    -- nullable string — "BODY CORP" is a real, valid value (§2.1)
  water_meter_reading          -- nullable string, same reasoning
```

Landlord and tenant name/signature are **not** duplicated here — they're already covered by
`RentalInspectionSignature` (§1.2), which resolves tenants from the lease and already has a `landlord`
party role. `RentalInspectionComparisonService::compareHeaderFacts()` (§7.3) reads these directly off the
in- and out-inspection rows — safe to call even before this migration lands anywhere, since Eloquent
attribute access on an absent column returns null rather than erroring, so it degrades to "nothing to
compare yet" automatically. Property type / furnished state / move-in date were part of the original
paper-form transcription (§2.1) but are not confirmed built as of this revision — check
`rental_inspections`' actual columns before assuming they exist.

### 4.2 Four-state grading, alongside the existing condition enum — not replacing it

**[cc5 design call, flagged for Johan]** Two ways to reconcile the paper form's four states with the
already-built six-state enum, named honestly rather than picking silently:

- **(a) Map, don't replace.** Treat Good/OK/Bad as a *simplified agent-facing view* over the existing
  enum (Good→`good`, OK→`fair`, Bad→ any of `damaged`/`not_working`/`missing`, agent picks which when
  ticking "Bad"), and add `condition = 'not_applicable'` as a seventh enum value for N/A. This keeps
  every existing discrepancy-detection, current-condition, and carry-forward query working unchanged —
  none of `RentalInspectionObservation`/`RentalInspectionDiscrepancy`/`RentalInspectionItem`'s already-
  built logic reasons about specific condition values in a way that breaks by adding one.
- **(b) Replace the enum outright** with `good | ok | bad | not_applicable`, losing the finer-grained
  `not_working`/`missing`/`other` distinctions the current system already has. Simpler for the agent, but
  discards information the existing build already collects, and this spec found no evidence anyone
  asked for that information to be removed.

This spec recommends **(a)** — it is Johan's actual paper process mapped onto the existing model with
nothing thrown away — but this is exactly the kind of implementation choice non-negotiable #8 says is
not Johan's to be asked as a technical question. Restated as the business consequence: *"Bad" on the
form becomes one of a few more specific reasons in the system (damaged, not working, or missing) — the
agent picks which when they tick Bad. Nothing is lost, the badge on screen still says roughly what the
paper form says.*

**Whole-room N/A** (§3.1, Bedroom 3/4): a one-tap action on a room that records `not_applicable` against
every item in that room in one call, not one tap per item — the paper form's single pen-stroke across
the row, replicated as one bulk action rather than n individual ones.

### 4.3 Per-room notes vs per-item notes

The existing `rental_inspection_observations.notes` column is per-item (§1.2's `requiresNotes()`). The
paper form's room notes box is a genuinely separate thing — one note per room per inspection event, not
tied to any single item. New table:

```
rental_inspection_room_notes
  id
  agency_id
  rental_inspection_id
  property_room_id          -- FK to the existing PropertyRoom (§1.5) — reuses the neutral room
                             --   concept already built for exactly this purpose, never a new
                             --   room-naming mechanism
  notes                     -- text, multi-line
  created_by_user_id
  created_at, updated_at    -- editable in place (unlike an observation) — this is a working note
                             --   attached to a room for the duration of one inspection event, not
                             --   itself the evidentiary record (the item-level observations +
                             --   photos remain that); §2.1's Bedroom-1-references-ensuite example
                             --   means an agent may reasonably need to correct which room a note
                             --   belongs under before the inspection is signed
```

### 4.4 Overall notes

One nullable text column on `rental_inspections` itself — `overall_notes` — rather than a new table,
since it's one value per inspection event, not a repeating structure.

---

## 5. Photos remain required — already true, no change needed

`RentalInspectionObservation::requiresNotes()` already requires notes when condition ≠ good; the existing
spec's §0.3 already establishes photos are required on every observation, good or bad. This document adds
no new requirement here — the paper form's expectation (photograph everything, not just the bad) is
already the built system's stricter standard.

---

## 6. Inventory — a separate document, specced on its own terms

Per the existing spec's own §9 scoping decision, Inventory was deliberately deferred as "architecturally
bigger than a rentals-only spec should absorb." Johan's real document confirms that decision was correct
— it genuinely is a different shape, not a variant of the inspection form:

```
property_inventory_items
  id
  agency_id
  property_id                -- property-scoped, not lease-scoped — like rental_inspection_items,
                              --   contents outlive any one tenancy's record of them, and Johan's own
                              --   ruling (rental-inspections.md §0.9) says inventory should extend to
                              --   sales properties too, which have no lease at all
  property_room_id           -- FK to the same PropertyRoom used by inspections (§1.5) — reusing the
                              --   room concept, not duplicating it; an inventory room list and an
                              --   inspection room list are independently populated (§2.2 — the two
                              --   documents don't share a room list) but both point at the same
                              --   underlying room-naming table
  quantity                   -- e.g. "2x", "1x" — stored as entered rather than forced numeric, since
                              --   the paper form's real entries ("2x Big pots... missing") mix
                              --   quantity and status in one line — see description below
  description                -- free text — "Single beds matrasses + bases", "LG Fridge/freezer
                              --   silver". Deliberately no separate condition/status column: the
                              --   paper form has none, and "missing" is written directly into this
                              --   field, not a flag (§2.2) — replicating that faithfully rather than
                              --   inventing a status enum nothing on the source document has
  is_retired                 -- bool, not deleted_at — same integrity reasoning as
                              --   rental_inspection_items (§3.3 of the existing spec): an inventory
                              --   line that's been counted in a prior year's record should stay
                              --   queryable, not vanish
  created_by_user_id
  created_at, updated_at
```

**Recorded, not built here**: this is a new small module in its own right (its own list screen, its own
CRUD, its own permission key), not a section bolted onto the inspection tab. It shares the property pillar
and the `PropertyRoom` table with inspections; nothing else. If Johan wants Inventory built alongside this
work, it is sized here so that decision is informed, not a surprise.

---

## 7. The actual purpose — in vs out comparison and the deposit outcome

This is the gap the existing spec never addressed (§1.3.4) and the one the paper form's own printed
legal text (§2.1) says is the entire reason it exists: *"Tenant(s) and landlord or manager uses the
move-in checklist during the pre-move out inspection and again when determaning if any of the tenant's
deposit will be retained for cleaning or repairs after move-out."*

Two inspection events that never talk to each other cannot do that. This spec proposes the comparison as
a **read-time computed view**, not a new mutable status anywhere — consistent with the existing spec's
own hard-won principle that "current condition is a query, never a column"
(`.ai/specs/rental-inspections.md` §3.1), applied one level up to the in-vs-out relationship itself:

### 7.1 The comparison

For a given lease's `type='out'` inspection, for every `rental_inspection_item` on the property:

1. Read the item's **in-inspection condition** — the most recent observation on that item, from that
   lease's `type='in'` inspection (via `currentObservation()`, already built, §1.2).
2. Read the item's **out-inspection condition** — the most recent observation on that item from the
   `type='out'` inspection itself.
3. Read the item's **full carry-forward history** for the tenancy — every observation of any source
   between the two, already available via the existing spec's `RentalInspection::carryForwardItems()`
   (§3.2a of the existing spec) — so a mid-tenancy fault report or a work order that already repaired
   something is visible alongside the two bookend readings, not hidden behind them. This is what answers
   Johan's own "owner cannot blame tenant for damages the owner neglected to fix" ruling
   (`.ai/specs/rental-inspections.md` §0.2) at the exact moment it matters — the deposit decision itself.
4. Classify each item into one of:
   - **Unchanged** — in and out conditions match (or both N/A).
   - **Improved** — out reads better than in (rare, but the comparison must not assume decline-only).
   - **Declined, no interim record** — out reads worse than in, and nothing in the carry-forward history
     between the two explains it (no work order, no mid-tenancy fault report already covering it). This
     is the category that actually costs the tenant something.
   - **Declined, already on record** — out reads worse than in, but a mid-tenancy observation or linked
     work order already accounts for it (e.g. reported, and either fixed by the landlord or left
     unfixed by the landlord's own choice) — per Johan's ruling, this is NOT automatically the tenant's
     cost; a human still decides, but the system surfaces the full trail so that decision is informed,
     not guessed.
   - **N/A on one side only** — an item marked N/A at move-in that has a real observation at move-out
     (or vice versa) — flagged distinctly, since it likely means the item didn't exist / wasn't
     applicable before and does now (or the reverse), which is a fact an agent needs to see plainly
     rather than have silently folded into "declined."

### 7.3 Built now (2026-09-21) — the comparison mechanics, not the money

Per the conductor's explicit instruction: build the comparison if it's unambiguous, stop before anything
that turns a difference into a number against a deposit. What's actually landed, on branch
`cc5-rental-inspection-deposit-comparison`:

- **`RentalInspectionComparisonService`** (`app/Services/`) — `compareItems(RentalInspection $out)`
  returns one row per item that has at least one observation on either side (an item with observations
  on neither side never existed for this tenancy and isn't returned), each row carrying the item, the
  classification, both observations (condition/notes/photos), and any recorded finding. Classification is
  **read-time only** — never a stored column, same "never a column" principle as
  `RentalInspectionItem::currentObservation()` in the already-built spec, so it can never drift out of
  sync with the observations it describes.
- **Matching is by `rental_inspection_item_id`, not fuzzy label matching.** Items are property-scoped and
  reused across every inspection on that property (already-built spec §3.1), so the SAME item row is
  automatically the same physical space on both the in- and out-inspection, as long as the agent picked
  it from the existing list rather than creating a new one. There is no rename/edit path for an item's
  label anywhere in the built system (checked directly — `RentalInspectionRecordingController` has
  `storeItem()`, no `updateItem()`) — an agent who wants to call a room something different creates a NEW
  item row instead. That is the SAME duplicate-item risk already named and left unsolved in
  `rental-inspections.md` §7.2, not a new one. It surfaces here honestly as one row in `only_at_in` and a
  separate row in `only_at_out` — the paper form's own "ensuite second bedroom" hand-labelling problem,
  made visible rather than silently guessed at.
- **Classification set, actually implemented**: `unchanged` / `improved` / `declined` / `only_at_in` /
  `only_at_out` / `na_mismatch` — `na_both` is computed internally but never returned as a row at all,
  per Johan's own wording ("an item N/A at both ends is not a finding"). This is a flatter set than the
  five-category sketch in the original §7.1 draft above (no separate `declined_on_record` vs
  `declined_no_record` split) — `declined` always carries the full carry-forward context through the
  existing out-inspection tab view (`RentalInspection::carryForwardItems()`), so an agent reviewing a
  `declined` finding already sees whether it was reported mid-tenancy before deciding anything; the
  service doesn't pre-split that judgement into two labels.
- **N/A handling is forward-compatible by construction, not by guessing cc2's exact constant name.** The
  service whitelists the six real condition values that exist today; anything outside that whitelist —
  including whatever string cc2's in-flight N/A work lands as — is automatically treated as not-gradeable.
  N/A on both sides is excluded entirely; N/A on one side only is `na_mismatch`, flagged for a human, per
  Johan's ruling that this needs a plain business decision (§7.4), not a system default.
- **`RentalInspectionItemFinding`** (new table + model) — the ONE thing that genuinely can't be derived:
  an agent's own judgement that a `declined` item is fair wear and tear (excluded from the deposit
  conversation) or should stay flagged as a genuine difference. Required note on every finding (mirrors
  `requiresNotes()`'s existing standard). Never edited in place — a second judgement on the same item
  supersedes the first (`superseded_at`/`superseded_by_finding_id`), exactly the pattern already proven by
  `RentalInspectionSignature::supersedeWetInk()`. Only recordable against a `declined` row — the service
  refuses a finding on `unchanged`/`improved`/`na_*` outright, since there's nothing to judge.
- **Header-block facts (keys/remotes/meters, §4.1)** — `compareHeaderFacts()` diffs the in- and
  out-inspection's `keys_count`/`remotes_count` (a numeric drop is a clean, direct signal) alongside the
  free-text meter readings (surfaced for the agent to read, never auto-flagged as changed — two
  independent free-text entries rarely match verbatim, and that alone isn't evidence).
- **New screen**: `GET .../rental-inspections/{inspection}/deposit-comparison` — read-only comparison
  table plus the wear-and-tear/flagged form, linked directly from the out-inspection's own detail page
  (the exact screen Johan looked at when he first raised this, §1.1) — additive, not a new top-level nav
  entry, matching this whole document's "additive, not a redesign" framing.
- **No amount, no currency, no deduction anywhere in any of the above.** No column on
  `RentalInspectionItemFinding` or anywhere else stores a rand value. The comparison page's own footer
  states this in plain text: "This is a proposal for review, not a deduction — no amount has been
  calculated or applied against any deposit."
- **Not built**: the `rental_inspection_deposit_outcomes` table sketched in the original draft of this
  section (classification + `deposit_action` + `retained_amount`) — that whole table is exactly the "turns
  a difference into a number" line the conductor named explicitly to stop before. It stays a sketch, not
  code, until §7.4 is ruled on.

### 7.4 What's Johan's to rule — put as plain business consequences, not technical questions

1. **Whether the outcome he sees is an amount, a list of items, or both.** What's built today gives him a
   list — every `declined` item, side by side, with both photos and both notes, and whether an agent has
   called it wear-and-tear or flagged it as real. If he wants a rand total on top of that list, someone
   still has to decide what each item is worth — the system won't do that math for him unless he says he
   wants it to.
2. **Whether the tenant sees the list of proposed deductions and has to sign it, the same way they already
   sign the inspection itself.** Right now, this screen is agent/office-facing only — a tenant has no way
   to see it or respond to it. If Johan wants the tenant to see what's being proposed against their
   deposit and agree or dispute it before it's final, that's a new screen and a new signing step, not
   something this build already does.
3. **What happens when the in-inspection and the out-inspection disagree about whether something even
   existed.** Today, an item recorded only at move-out (with nothing to compare it to at move-in) is shown
   to the agent as its own separate case, clearly marked — the system does not guess whether that item
   was there all along and just never written down, or is genuinely new. If Johan wants a default stance
   on that situation (e.g. "no charge unless the agent can show it wasn't there before"), that's his call
   to make, and nothing in the build assumes an answer either way.

---

## 8. Multi-agency — every list above is a default, never "the" list

- **The item vocabularies (§2.1's table)** ship as HFC's *starting* per-space-type defaults, stored the
  same way the existing `RentalInspectionSetting::room_type_item_defaults` override already works
  (`app/Models/RentalInspectionSetting.php` — an agency's saved override wins, the constant is the
  fallback for a type nobody has customized). A second agency with different rooms and different
  standard items changes these without touching code.
- **The property-type tick-list** (House/Flat/Townhouse/Commercial in HFC's example) is agency-editable
  text, not a hardcoded enum (§4.1) — another agency may sell/let a different mix of property types
  entirely.
- **The inventory room list** is free-text per property, never constrained to
  `config('property-spaces.all_space_types')` (§1.4) — confirmed structurally necessary, not just
  theoretically desirable, since HFC's own real documents already don't fit that list.
- No agency ID, agency name, or HFC-specific wording appears in any proposed migration, model constant,
  or default copy in this spec.

---

## 9. CRUD / list-screen / scoping floor — stated before any code exists

Per BUILD_STANDARD §1a and this repo's non-negotiable #8: every new entity in this spec
(`rental_inspection_intake_details`, `rental_inspection_room_notes`, `property_inventory_items`,
`rental_inspection_deposit_outcomes`) is agency-scoped via `BelongsToAgency`/`AgencyScope`, and:

- The intake-details and room-notes tables have no independent list screen — they are always accessed
  through their parent `rental_inspection`, which already has full CRUD, search/sort/filter, pagination,
  and OWN/BRANCH/AGENCY scoping (`.ai/specs/rental-inspections.md` §5). No new list screen needed for
  either.
- **`property_inventory_items`** needs its own list surface if Johan approves building Inventory (§6):
  search (property address, room, description), sort (room, then a stated default — most likely
  creation order within a room, matching how the paper form itself reads top to bottom), filter (by
  room, by property), pagination if a property's inventory list grows long, own/branch/agency scoping
  matching the existing rentals pattern, archive/restore (never hard delete) via `is_retired`.
- **`rental_inspection_deposit_outcomes`** (still not built, §7.4) would be read through the
  out-inspection's own detail view, not a separate list screen — it is one inspection's own outcome, not
  an independently browsable entity.
- **`rental_inspection_item_findings`** (built, §7.3) follows the identical reasoning: no independent list
  screen, read only through its parent out-inspection's comparison view
  (`corex.rental-inspections.deposit-comparison`), `agency_id` + `BelongsToAgency` scoped, reachable only
  via the already-agency-scoped `rental_inspection_id`/route-model-bound inspection — a cross-agency
  request 404s at the same global-scope layer as everything else in this module, not a separate check.
  Recording a finding requires `rental_inspections.review_deposit_comparison`, separately gated from
  `.view`/`.create` per §6's existing reasoning for `.resolve_discrepancy`.
- Every threshold this spec introduces (none numeric beyond what's already agency-configurable in the
  existing spec) stays agency-configurable; this spec adds no new hardcoded number.
- No hard deletes anywhere in this document — `is_retired` (matching the existing spec's own reasoning
  for why `rental_inspection_items` uses a flag rather than `deleted_at`, §3.3 of that spec) for
  inventory lines, standard `deleted_at` for anything else new here.

---

## 10. Out of scope — named, not silently dropped

- **Building any of this.** Johan has not ruled. This is a spec, nothing else.
- **The deposit's connection to actual trust-account/payment handling** (§7.2) — flagged, not assumed.
- **A formula that computes a rand amount from a condition change** — deliberately not proposed; this
  stays a human decision informed by the evidence (§7.2).
- **Redesigning anything already built** in `.ai/specs/rental-inspections.md` — the six-state condition
  enum, the discrepancy mechanism, the three-party signing flow, the offline/idempotency design all stay
  exactly as they are; this document is additive only.
- **Mobile app changes** — same boundary as the existing spec (§0.10/§14 of that spec): Andre's build,
  once the server-side shape here is decided.
- **Splitting the four-state Good/OK/Bad/N/A UI decision from the existing six-state enum outright** —
  §4.2 names two ways to reconcile them and recommends one, but the choice is flagged for Johan per
  non-negotiable #8, not made unilaterally here.

---

## 11. Files referenced / created

**Read, not modified, in producing the original version of this spec:**
- `.ai/specs/rental-inspections.md` — the existing built spec, source of everything in §1.2.
- `app/Models/RentalInspection.php`, `RentalInspectionItem.php`, `RentalInspectionObservation.php`,
  `RentalInspectionDiscrepancy.php`, `RentalInspectionSignature.php`, `RentalInspectionSetting.php`,
  `PropertyRoom.php`, `RentalInspectionPhoto.php`
- `app/Http/Controllers/CoreX/RentalInspectionController.php`,
  `RentalInspectionRecordingController.php`
- `resources/views/corex/rental-inspections/show.blade.php` (the exact screen Johan looked at, §1.1)
- `database/migrations/..._create_rental_inspections_table.php`
- `config/property-spaces.php` (`all_space_types`, §1.4)

**Created for §7.3 (comparison mechanics, 2026-09-21):**
- `database/migrations/2026_09_21_120000_create_rental_inspection_item_findings_table.php`
- `app/Models/RentalInspectionItemFinding.php`
- `app/Services/RentalInspectionComparisonService.php`
- `app/Http/Controllers/CoreX/RentalInspectionComparisonController.php`
- `resources/views/corex/rental-inspections/partials/deposit-comparison-page.blade.php`
- `routes/web.php` (two new routes, appended to the existing `rental-inspections` group)
- `config/corex-permissions.php` (`rental_inspections.review_deposit_comparison`)
- `resources/views/corex/rental-inspections/show.blade.php` (one new conditional link, out-inspections only)
- `tests/Feature/RentalInspections/RentalInspectionComparisonServiceTest.php`

No files were created or modified other than this spec document itself.
