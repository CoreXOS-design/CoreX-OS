# Rental Inspection Form — the paper document, faithfully specified

**Status:** Partially built, 2026-09-21; extended 2026-09-22 (§12, the printable OMR tick-box form —
part 1 of a two-part job, a separate lane builds the scan reader against §12's own contract). Johan
confirmed the gap analysis (§1) himself, in writing, after opening the real recording surface directly —
the hold on building was lifted for the in-vs-out comparison specifically (§7), on his own instruction:
build the comparison mechanics if unambiguous, stop before anything that turns a difference into a number
against a deposit. §7.3 records exactly what shipped. Everything else in this document (header-block
capture beyond keys/remotes/meters, four-state grading, per-room notes, Inventory) remains spec-only,
awaiting his ruling — §12 is a separate, explicitly-ruled build (the printable form itself, not any of
those still-open items) and does not change that status for the rest of the document.
**Date:** 2026-09-21 (amended 2026-09-22)
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

No files were created or modified other than this spec document itself, as of the 2026-09-21 revision —
see §12 for what was built 2026-09-22.

---

## 12. The printable OMR tick-box form (built 2026-09-22) — part 1 of a two-part job

Johan's requirement, verbatim in substance: an agent downloads a physical form, takes it to the
property, marks it in wet ink, gets it signed, scans it back in. **"We read the form back by OMR —
COLUMN MARKING ONLY. We do NOT read handwriting, ever. Free written text can be retyped, or the scanned
copy stays on file."** Every item gets a row; condition ratings sit in tick-box COLUMNS on the right.
A separate lane builds the scan reader against exactly what this section specifies — this build's output
contract matters as much as the form itself, so it is stated here in full, not left implicit in code.

### 12.1 Why the printed columns are the same values the screen uses, not a parallel scale

The tick-box columns are `RentalInspectionSetting::conditionStatesFor($agencyId)` — the EXACT same
agency-configurable vocabulary the on-screen one-tap chips render from (§20.3 of
`rental-inspections.md`), read fresh at generation time. An agency that reduces to three states prints
three columns; nothing here hardcodes CoreX's own six-plus-N/A default. This directly satisfies the
"printed columns must be the same condition values" instruction — there is no second, PDF-only condition
list anywhere in this build.

### 12.2 PDF tooling — used what was already there, added nothing

`barryvdh/laravel-dompdf` (already in `composer.json`, already the house convention —
`RentalDocumentPdfService`, `PropertyBrochureService`) generates the PDF. `smalot/pdfparser` (already in
`composer.json`, previously unused by this feature family) was reused, read-only, for this build's own
verification pass — cross-checking the manifest's stated page count against the actual rendered PDF's
real page count independently. **No barcode/QR library exists anywhere in `composer.json`, and none was
added.** `PropertyBrochureService::qrSrc()` generates a QR via a REMOTE third-party HTTP API
(`api.qrserver.com`), not a composer package — deliberately NOT reused here: a legal inspection
document's page identifier should not depend on a third-party service being reachable at print time, nor
should this feature's server-side generation step send any of an inspection's data to an external host.
The page identifier is instead a small OMR bit-grid (§12.5), read by the SAME column-marking mechanism as
every condition tick-box — one detection pipeline for the whole form, not two.

### 12.3 Data model — one new table, additive, versioned, never deleted

```
rental_inspection_forms
  id
  agency_id, branch_id           -- BelongsToAgency; branch_id denormalized from the property
  rental_inspection_id           -- FK rental_inspections
  version                        -- unsigned int, starts at 1
  content_hash                   -- sha256 of the ordered (room, item, condition-state) shape this
                                  --   version was generated from — the "has the item list changed"
                                  --   check. A rename counts as a change (the printed label itself
                                  --   would be stale otherwise), by design.
  pdf_storage_path                -- private disk (Storage::disk('local')), gated download route —
                                  --   never the public-disk pattern rental_inspection_photos uses;
                                  --   this is a legal document, not a marketing photo.
  manifest_json                   -- THE CONTRACT — §12.6. A JSON column, not a sibling file: one
                                  --   atomic row write means the PDF path and its manifest can never
                                  --   drift apart independently of each other.
  page_count, box_count           -- denormalized; box_count is checked against
                                  --   count(manifest_json->boxes) directly in this build's own tests.
  generated_by_user_id
  created_at, updated_at, deleted_at   -- soft-delete floor present (non-negotiable #1); nothing in
                                  --   this build ever calls it — a new version does not touch or
                                  --   supersede an old one's row, both stay live, permanently, so a
                                  --   returned scan can always be matched to the exact version that
                                  --   was physically printed.
```

`RentalInspectionFormPdfService::generate()` is idempotent on content: re-downloading with nothing
changed returns the current version's existing row rather than spawning a duplicate on every click.
Any change to the room/item list, an item's label, or the agency's condition-state vocabulary produces a
genuinely new `content_hash`, and therefore a new `version` row — the old one is retained, unmodified,
forever.

### 12.4 Layout — one PHP computation drives both what's drawn and what's recorded

`RentalInspectionFormPdfService::buildLayout()` is the single source of truth: it returns page/room/item/
box/fiducial/page-identifier/signature-block arrays that (a) the Blade view
(`resources/views/corex/rental-inspections/form-pdf.blade.php`) iterates to render the PDF via
absolutely-positioned, pt-unit `<div>`s, and (b) `buildManifest()` serializes directly into the persisted
contract. There is deliberately no second computation anywhere — the PDF and the manifest cannot describe
two different geometries, because only one geometry is ever computed.

Pagination: rooms flow top to bottom; a room that spans a page break repeats its own heading as
"(cont.)" plus the condition-column header on the new page, so every page is self-describing on its own —
neither a human nor the reader ever needs to consult a different page to know which room or which
columns a given page shows. The three-party (tenant(s)/landlord/agent — §15 of `rental-inspections.md`)
signature block never splits across a page break.

### 12.5 Registration marks and the page identifier — dependent on nothing but column marking

- **Fiducials** — four filled marks per page, fixed inset from every corner, present on EVERY page (not
  just page 1), so a scan of any single page can be de-skewed and scaled independently. The bottom-right
  mark is deliberately a filled CIRCLE, not a square — the other three squares alone are
  180°-rotation-symmetric, and this asymmetry lets a reader resolve a rotated scan from the fiducials
  alone, before it has decoded anything else.
- **Page identifier** — a fixed-position (top-right, every page, same offset from the top-right fiducial
  on every form this service ever generates) 8×5 grid of 40 small tick-boxes: 24 bits `rental_inspection_id`
  + 8 bits `page_number` + 8 bits `form_version`, MSB-first per field, row-major. A human-readable line
  ("INSP #123 · Page 2/5 · v2") is printed directly above the grid for a person to read; the grid is what
  the reader lane decodes — by the identical column-marking method as every condition box, no OCR, no
  barcode decoder. Because the grid's position is a fixed design constant (not something that varies per
  inspection), a reader can locate and decode it WITHOUT consulting any manifest first — it only needs
  the manifest afterward, once it knows which inspection/page/version it's looking at, to read everything
  else (condition-box positions, room/item labels). The manifest's own `page_identifiers` block repeats
  the same bit coordinates anyway, so nothing about the encoding is ever a second, undocumented source of
  truth.

### 12.6 THE CONTRACT — the manifest schema, in full

Persisted as `rental_inspection_forms.manifest_json`. Every field name and shape below is final as
handed to the reader lane; anything not listed here is not part of the contract.

**Coordinate space** (stated once, applies to every coordinate in the manifest): **origin top-left of
each page; x increases rightward; y increases downward; unit = pt (PDF point, 1/72 inch — deliberately
DPI-independent, since a scan's actual resolution is unknown and unconstrained); page = A4 portrait,
595.28 × 841.89 pt.** A DPI figure is stated separately, as a print/detectability RECOMMENDATION only,
never as part of the coordinate contract itself: `recommended_min_scan_dpi: 150` — a 10pt tick-box at
150 DPI resolves to roughly 21×21 real pixels, comfortably OMR-detectable on a phone photo or a cheap
office scanner. The four per-page fiducials are what let a reader calibrate an arbitrary scan's actual
pixel grid back into this same physical space — the reader is never expected to assume a scan's DPI.

```jsonc
{
  "manifest_version": 1,
  "rental_inspection_id": 123,
  "form_version": 2,
  "agency_id": 7,
  "content_hash": "…sha256 hex…",
  "generated_at": "2026-09-22T09:14:00+02:00",
  "page_count": 3,
  "page_size": { "name": "A4", "width_pt": 595.28, "height_pt": 841.89 },
  "coordinate_space": {
    "origin": "top-left", "x_axis": "…", "y_axis": "…",
    "unit": "pt (PDF point, 1/72 inch — DPI-independent)",
    "recommended_min_scan_dpi": 150, "note": "…"
  },
  "condition_states": [ { "key": "good", "label": "Good" }, /* …agency's own configured set… */ ],
  "fiducials": [
    { "page": 1, "corner": "top_left", "x": 12.0, "y": 12.0, "size": 10.0, "shape": "filled_square" },
    { "page": 1, "corner": "top_right", "...": "..." },
    { "page": 1, "corner": "bottom_left", "...": "..." },
    { "page": 1, "corner": "bottom_right", "...": "...", "shape": "filled_circle" }
    /* …repeated per page… */
  ],
  "page_identifiers": [
    {
      "page": 1, "encoding": "binary_omr_grid",
      "bit_order": "row_major_left_to_right_top_to_bottom_msb_first_per_field",
      "grid": { "x": 397.28, "y": 48.0, "cols": 8, "rows": 5 },
      "fields": [
        { "name": "rental_inspection_id", "bit_length": 24, "value": 123 },
        { "name": "page_number", "bit_length": 8, "value": 1 },
        { "name": "form_version", "bit_length": 8, "value": 2 }
      ],
      "bits": [ { "index": 0, "value": 0, "x": 397.28, "y": 48.0, "width": 6.0, "height": 6.0 }, /* …40 total… */ ]
    }
    /* …one per page… */
  ],
  "boxes": [
    {
      "page": 1,
      "rental_inspection_item_id": 456,
      "room_label": "Bedroom 1", "item_label": "Ceiling",
      "condition_key": "good", "condition_label": "Good",
      "x": 166.0, "y": 154.0, "width": 10.0, "height": 10.0
    }
    /* …one entry per (item × condition-state) tick-box actually printed… */
  ],
  "signature_blocks": [
    { "page": 3, "party_role": "tenant", "party_contact_id": 88, "label": "Thabo Tenant", "x": 36.0, "y": 700.0, "width": 160.0, "height": 74.0 }
    /* …tenant(s), landlord, agent… */
  ]
}
```

Notes for the reader lane, stated plainly rather than left to be discovered:

- **`boxes[].rental_inspection_item_id` is an ITEM id, never an observation id.** Nothing has been
  observed yet at print time — the form is blank. A detected mark at a box's position means "create a
  new observation for this item with this `condition_key`," which is exactly the atomic
  `RentalInspectionObservation::record()` entry point `rental-inspections.md` §14.1 already names as the
  one correct way to do that — the reader lane calls that, it does not write to the table directly.
- **Any manifest number may round-trip through JSON as a plain integer even when the PHP source was a
  float** (e.g. `x: 166.0` can arrive as `166`) — treat every coordinate/size field as a generic number,
  never assume a specific numeric type.
- **`signature_blocks` is geometry only, never OMR-read** — nothing there is ever marked or machine-
  decided. It is included because it costs nothing to record and connects directly to the ALREADY-BUILT
  `RentalInspectionSignature::storeWetInkUpload()` (§16 of `rental-inspections.md`): a downstream step MAY
  crop a signed region from a scan and file it as that party's wet-ink upload without re-deriving where
  on the page it is. This is the one deliberate addition beyond the five numbered build items in Johan's
  brief — flagged here explicitly rather than left to be discovered as an unannounced extra.

### 12.7 No new agency setting — checked, not skipped

"Every list/label/threshold is agency-configurable" is already satisfied by what's reused: the
condition-state vocabulary (§12.1) is the existing `RentalInspectionSetting::conditionStatesFor()`, room/
item labels are the existing per-property data. Nothing new introduced by this build is a business-level
list, label, or threshold an agency would ever want to change — box size, fiducial size, margins, and the
bit-grid layout are print/layout CONSTANTS, not settings, the same way no agency has ever needed to
configure a button's pixel padding on screen. Page size (A4) is a plain constant, not a setting — no
agency using CoreX today operates outside SA/A4 conventions; if that changes, it's a real, separate ask.

### 12.8 Scoping, permission, navigation

`rental_inspection_forms` uses `BelongsToAgency`. The download route
(`GET corex/rental-inspections/{rentalInspection}/form`) sits in the existing `rental-inspections` route
group, inheriting that group's `permission:rental_inspections.view` gate — no new permission key, same
precedent as `RentalWorkOrderController::pdf()` reusing its own `show()`'s gate. Route-model-binding on
`RentalInspection` already 404s a cross-agency request via the global `AgencyScope`; the controller never
trusts an id alone. Navigation: a "Download printable form" link on the inspection's own show screen
(`resources/views/corex/rental-inspections/show.blade.php`), same day as the build, per non-negotiable
#2 — no new top-level nav entry, matching this whole document's additive framing.

### 12.9 Verified

Real PDFs generated (not mocked) against seeded fixture data, in an isolated worktree — not against the
deployed site (Standard −1s): a 2-room/15-item property produced 1 page/105 boxes; a 10-room/80-item
property produced 3 pages/560 boxes. In both cases `manifest_json`'s own `boxes` array length matched
`box_count` exactly, and `page_count` matched an INDEPENDENT count via `smalot/pdfparser` reading the
actual rendered PDF bytes — the manifest was never trusted to grade its own homework. No box landed
outside its stated page's valid range; no two boxes on the same page shared an identical position.
Regenerating with no room/item change returned the same version (no duplicate row); adding one item
produced a new version while the prior version's row and PDF file both remained present. A new PHPUnit
file, `tests/Feature/RentalInspections/RentalInspectionFormPdfServiceTest.php`, covers all of the above
plus the download route and its cross-agency 404.

### 12.10 Files created

- `database/migrations/2026_10_02_150000_create_rental_inspection_forms_table.php`
- `app/Models/RentalInspectionForm.php`
- `app/Services/Rentals/RentalInspectionFormPdfService.php`
- `resources/views/corex/rental-inspections/form-pdf.blade.php`
- `app/Http/Controllers/CoreX/RentalInspectionController.php` (`form()` method added)
- `routes/web.php` (one new route, `corex.rental-inspections.form`)
- `resources/views/corex/rental-inspections/show.blade.php` (one new link)
- `tests/Feature/RentalInspections/RentalInspectionFormPdfServiceTest.php`

## 13. The OMR scan reader — part 2 of the two-part job (built 2026-09-22)

Built against §12's manifest exactly as specified — no geometry re-derived from
`RentalInspectionFormPdfService`'s layout code. Johan, restated: **"we read the form back by OMR —
COLUMN MARKING ONLY. We do NOT read handwriting, ever."** This service never runs OCR and never samples
outside a rectangle the manifest names — it is structurally incapable of reading the notes columns,
because it only ever looks at `boxes[]` and `page_identifiers[].bits[]` positions.

### 13.1 One additive change to cc5's own file

`RentalInspectionFormPdfService::pageIdentifierGridLayout()` was added — a public static method
returning the page-identifier grid's fixed design layout (`x, y, cols, rows, bit_size, bit_gap,
bits_inspection, bits_page, bits_version`), refactored out of the existing `pageIdentifierFor()` so
there is still exactly one computation of that geometry, now called from both places. This exists because
the identifier grid's position is a published, universal constant (§12.5: "a reader can locate and decode
it WITHOUT consulting any manifest first") — the reader needs that geometry BEFORE it knows which
inspection's manifest to load, so it cannot get it from a manifest row. Consuming a method that returns
cc5's own already-computed geometry is what "never re-derive geometry from the layout code" means in
practice when even the fixed part needs a shared reference; re-implementing the same numbers by hand in
the reader would have been the actual violation. No other change was made to that file. Re-ran cc5's own
`RentalInspectionFormPdfServiceTest` after this change — all 8 tests still pass unchanged.

### 13.2 Pipeline

1. Rasterize the upload. A PDF is rendered via PHP's `Imagick` (Ghostscript-backed) at a fixed internal
   DPI; an image upload (a phone photo) is loaded as-is, one page. Both `gs` and `pdftoppm`/`pdftocairo`
   (Poppler) were already installed on the box, and the `imagick`/`gd` PHP extensions were already
   enabled — nothing new was installed.
2. **Calibrate** each page: locate the four fiducials, resolve which pixel blob is which manifest corner as
   TWO separate questions (Johan, 2026-09-22 — see §13.12 for the full story and its one known limit): the
   fiducial rectangle's own edge-length structure answers "portrait or sideways" from position alone, at
   any rotation angle — an A4 page is never square, so no shape/fill-ratio classification is needed for
   this half. That narrows to exactly two candidates differing only by "right way up or upside down" — the
   one question the bottom-right circle actually exists to answer — resolved primarily by decoding the
   printed page identifier under each candidate and keeping whichever matches this scan's own known
   `rental_inspection_id` (deterministic, shape-independent, no rotation blind spot), falling back to
   relative fill-ratio comparison only if that's inconclusive. Then fit ONE affine transform (least squares
   over the four correspondences) mapping manifest pt-space onto that page's actual pixels. A 180-degree
   rotation and a camera-angle skew are both just different affine matrices to this step — there is no
   separate rotation-handling code path.
3. **Decode the page identifier** through that transform, using `pageIdentifierGridLayout()` (§13.1) — this
   is possible before any manifest is loaded, exactly as designed.
4. **Match against the inspection's CURRENT form version.** If the decoded version doesn't match, the scan
   is marked `version_mismatch` and NOTHING is sampled further — an agency reprinting after adding an item
   must never have an old scan silently write onto the new layout (Johan's explicit instruction).
5. **Sample every box** on the matched manifest for that page, through the same transform, and decide
   marked/unmarked via `RentalInspectionSetting::omrMarkThresholdFor()` (agency-configurable, default 0.35
   — see §13.6).

### 13.3 Data model — two new tables, additive, one column added to an existing settings table

```
rental_inspection_scans
  id, agency_id, branch_id, rental_inspection_id
  rental_inspection_form_id      -- nullable; unknown until the identifier decodes (§13.2 step 3-4)
  original_filename, storage_path, mime_type   -- PRIVATE disk (Storage::disk('local')), gated download —
                                  --   same pattern as PropertyFileController::download(), never the
                                  --   public-disk pattern (this is a legal document, not a photo)
  status                          -- processing -> needs_review -> applied
                                  --            -> version_mismatch | failed
  failure_reason                 -- human-readable, shown on the review screen
  decoded_inspection_id, decoded_form_version   -- plain diagnostic columns, not FKs — what the bits
                                  --   actually said, even when it doesn't match anything real
  page_count
  uploaded_by_user_id, applied_by_user_id, applied_at, archived_by_user_id
  deleted_at                     -- soft-deletable (archived, never hard-deleted, non-negotiable #1) —
                                  --   "the scan on file is what backs up anything we could not read,"
                                  --   Johan's own words, applies even to a scan that failed to decode
                                  --   at all: the original is retained regardless.

rental_inspection_scan_marks
  id, agency_id, rental_inspection_scan_id, rental_inspection_item_id, page_number
  detected_condition_key, detected_confidence, ambiguous   -- what the reader found; null/true when
                                  --   nothing could be confidently decided (§13.4)
  confirmed_condition_key, confirmed_by_user_id, confirmed_at   -- what a human confirmed on the review
                                  --   screen — always independent of what was detected, a correction is
                                  --   not an edit of the detected value
  applied_observation_id         -- FK to rental_inspection_observations — THE audit trail (§13.5)
  deleted_at                     -- soft-deletable, matching every other table in this build

rental_inspection_settings.omr_mark_threshold   -- nullable decimal(3,2); RentalInspectionSetting::
                                  --   omrMarkThresholdFor() resolves it, defaulting to 0.35 when unset —
                                  --   same read-time-default pattern as every other column on that table
```

### 13.4 Ambiguity is flagged, never guessed

Per item per page: if exactly one of that item's condition boxes reads above the threshold, that's the
detected condition. **Two marks on one row, or none at all, are BOTH flagged ambiguous** — a blank row is
not treated as "nothing to report," because a real inspection form expects every row marked; a genuinely
missed row needs a human to notice it, not a reader that silently moves on. An ambiguous or unmarked row's
`detected_condition_key` is always null; the review screen never pre-selects a guess for it.

### 13.5 Applying — an ordinary observation, never a parallel storage path

`RentalInspectionScanReaderService::applyMark()` calls `RentalInspectionObservation::record()` — the
EXACT SAME entry point a screen tap (`onConditionTap()`, rental-inspections.md §14.1) uses. The
observation table gained no new column for this feature. The audit trail Johan asked for ("every applied
mark records that it came from a scan, which scan, which page, and who confirmed it") lives entirely on
`rental_inspection_scan_marks`: `applied_observation_id` points forward to the real observation it
produced, and the mark's own `rental_inspection_scan_id`/`page_number`/`confirmed_by_user_id`/
`confirmed_at` are the "which scan, which page, who, when" — reachable by following that one foreign key
backward from any observation a deposit dispute needs to trace.

### 13.6 Agency-configurable, per standing rule

`RentalInspectionSetting::omrMarkThresholdFor($agencyId)` — the fraction of a box's interior that must
read as dark ink to count as marked. Default 0.35, chosen to tolerate a slightly light photocopy or an
unevenly lit phone photo while staying well clear of paper-texture noise; an agency scanning on worse
equipment can raise or lower it without a code change. **Deliberately NOT added to the Agency Onboarding
Setup Wizard** (non-negotiable #10a) — this is an expert/rarely-touched calibration knob an agency would
tune only after a real accuracy problem, not something meaningful to present during onboarding before any
agency has ever scanned a form; recorded here as a deliberate omission, not an oversight. Every other
number in this build (box size, fiducial size, grid layout) is a print/layout constant inherited unchanged
from cc5's own §12.7 reasoning — not a setting, for the same reason a button's pixel padding isn't one.

### 13.7 Scoping, storage, permission

`rental_inspection_scans`/`rental_inspection_scan_marks` both use `BelongsToAgency`. Every controller
action cross-checks `$scan->rental_inspection_id === $rentalInspection->id` before touching a scan (the
same pattern already used throughout this module for a photo/tag/observation from a different inspection),
on top of the global `AgencyScope` a cross-agency id would already fail before the controller runs. Upload/
apply/archive sit behind `permission:rental_inspections.create` (mutating); review/download behind the
route group's own `.view` gate — matching cancel/destroy vs form/show's existing split in
`RentalInspectionController`.

### 13.8 Scope decisions, named rather than silently assumed

- **One PDF or one image per upload, never several images combined into one multi-page upload.** A form
  photographed page-by-page (rather than scanned as one PDF) is uploaded as several separate scans, one
  per photo — each one decodes its own page identifier independently and contributes marks only for
  whichever items live on that page. This is sufficient for "accept PDF and common image formats" without
  building a client-side multi-file-to-multi-page assembly step nobody asked for.
- **Processing is synchronous**, not queued. QA1 (and QA2) run web-only, no queue worker (BUILD_STANDARD
  §8's promotion-flow note) — a queued job would be genuinely untestable on the first environment Johan
  actually looks at. A few pages' rasterize-and-sample pass is a few seconds' work, well within a normal
  request.
- **Partial apply is allowed.** An agent can confirm some rows on the review screen and leave the rest for
  a later visit; the scan's own status only flips to `applied` once every mark on it has an
  `applied_observation_id`.
- **No multi-match/multi-page reconciliation UI beyond the plain per-scan review screen** — if an agent
  uploads the same physical form twice (a genuine duplicate), both scans are reviewed independently; there
  is no "these two scans agree/disagree" comparison built here. Not asked for, not built.

### 13.9 Verified

`tests/Feature/RentalInspections/RentalInspectionScanReaderServiceTest.php` — a REAL form generated by
cc5's own service (never a hand-built fixture manifest), rasterized, programmatically inked with a
different condition per item so a systematic offset bug couldn't accidentally pass, fed back through the
reader, and asserted to read back exactly what was inked:
- a straight page,
- the SAME page rotated 180 degrees,
- the SAME page skewed 6 degrees and, separately, 20 degrees (not 90-degree multiples — proves the general
  affine fit, and the 20-degree case proves the working envelope genuinely reaches well past the ~7-degree
  ceiling the original shape-threshold approach had — see §13.12),
- the SAME page rotated a clean 90 degrees (photographed sideways — a realistic mistake, and the exact case
  that used to resolve CONFIDENTLY WRONG under the original approach, not merely reject; now reads correctly),
- two marks inked on one row → flagged ambiguous, not guessed,
- zero marks inked on one row → also flagged ambiguous, never silently treated as a valid "unmarked" reading,
- a scan of an old form version (the item list changed after it was printed, producing v2) → flagged
  `version_mismatch`, zero marks recorded, nothing applied,
- applying a confirmed mark → an ordinary `RentalInspectionObservation` with the audit trail intact
  (mark → scan/page/confirmed-by, mark → applied observation),
- the threshold setting's default and its agency-override.

Rasterized at 220 DPI in the test — deliberately different from the reader's own internal 200 DPI constant
used when it rasterizes a PDF itself — because the coordinate contract is explicitly DPI-independent and an
uploaded image is never re-rasterized by the reader at all; this is the realistic path for a phone photo or
a scanner set to whatever DPI it happens to use, not a coincidental match to an internal constant.

No browser harness, no dev server (Standard −1s) — a file-level PHPUnit test throughout.

### 13.10 Found, not built here

- OCR/handwriting reading of the free-text notes column — explicitly and permanently out of scope, per
  Johan's own instruction, not a deferred item.
- A UI to reconcile two independent scans of the same physical page (§13.8).
- Queued/background processing, should QA ever gain a queue worker.

### 13.12 Rotation envelope — what it handles, and one known, deliberate limit

**History.** The first working version resolved fiducial correspondence by classifying the bottom-right
blob as "the circle" via an absolute fill-ratio threshold. That worked, but Johan pushed back the same
night it shipped: measured, the envelope was only ±5 degrees around upright plus the full 180-degree
upside-down case — too narrow for how an agent actually takes the photo (phone held in hand, not braced
against anything). The root cause: a rotated SQUARE's own axis-aligned fill ratio is not rotation-invariant
(it falls from 1.0 toward 0.5 as rotation increases), so past roughly 8 degrees it can read as MORE
circle-like than the true circle — and worse, at exactly 90 degrees the old logic didn't just fail, it
resolved a wrong-but-plausible-looking transform and reported it as trustworthy. Confidently wrong, not
merely narrow — the one outcome this feature can never produce.

**The fix — two questions, not one.** Johan's own framing, verbatim: "orientation is really TWO separate
questions, and only one of them needs the circle." Portrait-vs-sideways is answered by the fiducial
rectangle's own edge-length structure (A4 is never square) — true at ANY rotation angle, from position
alone, no shape classification involved at all. That narrows four possible orientations to exactly two,
differing only by "right way up or upside down" — the ONE question the circle exists to answer, and now a
binary choice between two candidates rather than a 1-vs-3 classification against a threshold that drifts
with angle. Resolved primarily via the printed page identifier (decoded under each candidate, matched
against this scan's own known target) — deterministic, shape-independent, no rotation blind spot — with
fill-ratio comparison demoted to a fallback for the rare case that's inconclusive.

**Measured result** (fine-grained sweep against a real generated form; every ACCEPTED angle checked against
both the exact rotation applied and the decoded page identifier — zero confidently-wrong reads anywhere in
the sweep):

| Range | Result |
|---|---|
| 0°–24° | Reads correctly |
| 25°–65° | Rejected (`status = failed`, scan retained, human re-scans) |
| 66°–114° | Reads correctly (includes a clean 90-degree sideways photo) |
| 115°–155° | Rejected |
| 156°–180° | Reads correctly (includes upside-down) |

**Known, deliberate limit — the 25°–65° / 115°–155° bands.** This is NOT a gap in the correspondence logic
above — that now resolves correctly at ANY angle wherever it receives valid input. It's a SEPARATE,
earlier bottleneck: the fiducial search itself looks for each corner mark inside a quadrant anchored to one
of the four IMAGE corners. Past roughly 25 degrees of rotation, canvas expansion moves the true fiducials
away from every image corner entirely (confirmed directly: at 45 degrees, three of the four corner searches
find nothing at all) — there is nothing for the correspondence logic to work with, regardless of how good
it is. Closing this would mean changing WHERE the search looks (e.g. scanning image edge-midpoints, or a
full-frame blob sweep) — a genuinely separate piece of work, not a refinement of this one.

**Deliberately left as-is.** Johan, 2026-09-22: "Nobody photographs a document at 45°. A phone held roughly
upright, or turned a quarter turn, or upside down, covers what agents actually do... Closing that band would
be real work for a case that does not occur, and I would rather spend it on inventory." If this is ever
revisited, it needs a different search strategy, not a tighter threshold — start from the corner-quadrant
search notes above, not from the correspondence-resolution code, which is already correct at every angle it
can see.

### 13.13 Files created

- `database/migrations/2026_10_02_160000_add_omr_mark_threshold_to_rental_inspection_settings_table.php`
- `database/migrations/2026_10_02_160100_create_rental_inspection_scans_table.php`
- `database/migrations/2026_10_02_160200_create_rental_inspection_scan_marks_table.php`
- `app/Models/RentalInspectionScan.php`
- `app/Models/RentalInspectionScanMark.php`
- `app/Services/Rentals/RentalInspectionScanReaderService.php`
- `app/Http/Controllers/CoreX/RentalInspectionScanController.php`
- `resources/views/corex/rental-inspections/scan-review.blade.php`
- `routes/web.php` (five new routes, `corex.rental-inspections.scans.*`)
- `resources/views/corex/rental-inspections/show.blade.php` (upload form + scan list added)
- `app/Models/RentalInspection.php` (`scans()` relation added)
- `app/Models/RentalInspectionSetting.php` (`omr_mark_threshold` column support added)
- `app/Services/Rentals/RentalInspectionFormPdfService.php` (`pageIdentifierGridLayout()` added — §13.1)
- `tests/Feature/RentalInspections/RentalInspectionScanReaderServiceTest.php`
