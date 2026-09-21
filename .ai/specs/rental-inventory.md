# Spec: Rental Inventory

**Status:** Capture half pushed, awaiting landing (2026-10-01, cc6). Comparison half NOT built —
shape reported to the conductor, held pending her and cc5's ruling. See §8.

---

## 0. What this is, and why it is not a tab on the inspection

Johan sent his agency's real paperwork twice tonight. The first document — the in/out condition
walkthrough — is what `.ai/specs/rental-inspections.md` builds to. The second is a genuinely different
instrument: **a counted list of the contents of a furnished or partly-furnished property, grouped by
room.** Not condition grading. Quantity plus description, one line each, handwritten and signed at the
foot of every page.

Real lines from it, verbatim, so this spec builds to reality and not to an assumption:

```
2x Single beds matrasses + bases
1x Wooden TV Table
2x White wooden headboards
1x White big lamp with weaved shade
4x Remotes - 2x Fans - 2x Aircon
1x LG Fridge/freezer silver
1x Defy silver dishwasher
3x Silver adjustable bar chairs
4x Fishing rods on top of cupboard
2x Blue gasbottles in gas cupboard
1x Gold padlock with Key
1x Silver padlock combination
2x Big pots under dustbin room window missing
```

**Johan's explicit instruction: build it as its own document, not a tab on the inspection.** It attaches
to the property and the lease, is produced at move-in, and is compared at move-out. It is evidence.

---

## 1. What the real document teaches, one line at a time

1. **Quantity and description are one thought, two values.** "4x Remotes - 2x Fans - 2x Aircon" is a
   count of 4 with a description that itself breaks down further. Not over-structured further — a rigid
   schema (a `type`/`brand`/`colour`/`location` column each) would not survive a real agent in a real
   flat. `quantity` (int) + `description` (free text) is the whole shape.
2. **State gets annotated inline.** "2x Big pots under dustbin room window MISSING" — the word "missing"
   is written as part of the line, not a separate field. Handled in §8, not by adding a `status` enum to
   this build's own lines (see §8's reasoning for why that decision waits for the comparison design).
3. **Location lives in the description on purpose.** "on top of cupboard", "in bedside drawer", "in gas
   cupboard", "under dustbin room window", "against wall" — that is how you find the thing again at
   move-out. Never pulled into a separate structured field.
4. **Brand and colour matter.** "LG Fridge/freezer silver", "Defy silver dishwasher" — this document
   proves what was there. Same reason: stays in `description`, free text.
5. **The inventory has its OWN room list, and it does not match the inspection's.** Sunroom, Rubbish bin
   room, Entrance from glass front door, Dining room/Balcony, Lounge, Laundry Room, Outside front of
   house — rooms `rental-inspections.md` never mentions. `room_label` is free text on each line, not a
   foreign key to `PropertyRoom` or `RentalInspectionItem` (§3.1's own reasoning).
6. **Every page is signed.** Built here as the same three-party (tenant/landlord/agent) shape §15 of
   `rental-inspections.md` already established, with refusal as a first-class, non-error disposition —
   see §5 for what was and was not carried across from that build.

---

## 2. Pillar connections

Attaches to **Property** (`property_id`) and **Deal/Lease** (`lease_id`) — the two pillars Johan named
explicitly. Reads Contact via `Property::sellerOwnerContact()` (landlord) and `Lease::tenants` (tenants),
same resolvers §15 already established — never re-derived.

---

## 3. Data model

### 3.1 `rental_inventories` — the header

```
rental_inventories
  id
  agency_id, property_id, lease_id
  status              -- draft | awaiting_signature | completed | cancelled
  signing_deadline_at -- present in the schema, NOT yet wired to any deadline logic —
                       --   rental-inspections.md §17 found the equivalent field on
                       --   RentalInspection is stored/displayed but never enforced as a gate;
                       --   this column exists for future parity, not claimed as built.
  completed_at, cancelled_at, cancelled_by_user_id, cancel_reason
  archived_by_user_id, created_by_user_id
  timestamps, soft-deletes
```

**No `type` column.** Unlike `RentalInspection` (a genuinely repeated event, in AND out), Johan's real
document is produced ONCE per tenancy — `RentalInventory::start()` refuses a second inventory for the
same lease. `RentalInventory::currentFor($lease)` scopes by `lease_id`, so a new tenancy (a new `Lease`
row) always gets a fresh inventory without any special-casing.

### 3.2 `rental_inventory_lines` — one line per real item

```
rental_inventory_lines
  id, agency_id, rental_inventory_id
  room_label     -- free text, NOT a foreign key (§1.5)
  quantity       -- unsigned int, default 1
  description    -- text — location, brand, colour, state annotations all live here (§1.1-1.4)
  sort_order
  is_retired     -- §3.3-style retirement (rental-inspections.md), never a hard delete
  created_by_user_id
  timestamps
```

### 3.3 `rental_inventory_signatures` — three-party signing

Identical shape to `rental_inspection_signatures` pre-§16 (signed/refused only, no wet-ink — see §5).
`party_role` (tenant/landlord/agent), `party_contact_id`, `disposition` (signed/refused),
`party_signature_path`, `refusal_reason_preset`/`_note`, `recorded_by_user_id`, `disposition_recorded_at`.

**[design call] A genuinely separate table, not a polymorphic relation shared with
`rental_inspection_signatures`.** Unifying the two signing mechanisms behind one polymorphic table is a
legitimate future refactor — flagged, not silently done — but rewriting the signing model
`rental-inspections.md` §15/§16 landed and verified THIS SAME NIGHT is out of scope for this build. The
duplication is deliberate and acknowledged, not an oversight.

---

## 4. Items are quantity + free text, deliberately not more structured

Confirmed directly against Johan's real lines (§1.1). `RentalInventoryLine::room_label` +
`quantity` + `description` is the entire shape. No `brand`, `colour`, `location` columns — all live in
`description`, matching how the real document actually reads.

---

## 5. Signing — reused from §15, minus what wasn't asked for here

Built: three party roles, refusal as a first-class disposition with a mandatory reason, the agent signing
last and only once every tenant + the (resolvable) landlord already has a disposition —
`RentalInventorySignature::capture()` enforces the identical invariant set as
`RentalInspectionSignature::capture()`.

**Deliberately NOT built here, flagged rather than silently assumed:**
- **Wet-ink** (rental-inspections.md §16) — "every page is signed" was Johan's observation about the real
  document, not an explicit instruction to build the wet-ink path a second time tonight. If wanted here
  too, that is a follow-up ask, matching how wet-ink was its own separately-scoped task for inspections.
- **`sign_on_behalf`-gated refusal permission** — inspections gate a refusal specifically behind
  `rental_inspections.sign_on_behalf` (an agent asserting something on a party's behalf with no evidence
  but their word). Not wired here; every refusal is gated only by the base `rental_inventories.create`
  permission the whole recording surface already requires. If the same distinction is wanted for
  inventories, that is a one-permission-key addition once asked for.
- **Refusal reason list**: reuses `RentalInspectionSetting::refusalReasonPresetsFor()` directly — the
  SAME agency-configurable list inspections already use ("why didn't this party sign" is one concept, not
  two lists for two documents). No new settings table.

---

## 6. Permissions

`rental_inventories.view` (access), `rental_inventories.create` (record lines & sign) —
`config/corex-permissions.php`, same `agency-tracker` section as `rental_inspections.*`. No separate
`manage_settings` key — this build introduces no agency-level settings of its own (§5's reuse of the
existing refusal-preset list means there is nothing new to manage).

---

## 7. The list screen — CRUD/list-screen floor (BUILD_STANDARD §1a-§1d)

`/corex/rental-inventories` — search (property address, tenant name, agent name), sort (default: started,
most-recent-first; also property, status), filter (status, date range), pagination, real empty state,
OWN/BRANCH/AGENCY scoping (`RentalInventory::scopeVisibleTo()`, identical convention to
`RentalInspection`'s own). Archive/restore floor: soft-delete via `destroy()`, `restore()` — no hard
delete anywhere in this feature. Sidebar entry: "Rental Inventories", same-day, right under "Rental
Inspections" (CLAUDE.md non-negotiable #2).

---

## 8. The comparison half — NOT BUILT, shape reported, held for ruling

**This is the part Johan said matters as much as the capture, and it is the part this build does not
touch.** Coordinated with cc5 (building the in-vs-out deposit comparison, a separate worktree,
`cc5-rental-inspection-deposit-comparison`) before settling even the capture shape — see §3.2's rationale
and cc5's own confirmation (relayed to the conductor directly).

**What is genuinely undecided, and needs Johan's ruling, not an assumption baked into a migration:**

- **How a move-out disposition per line gets captured at all.** Johan's real document annotates state
  ("missing") inline, on the same line, at whatever point that became known — it does not describe a
  second, separately-produced document the way in/out inspections are two distinct events. Whether the
  digital shape should be (a) a `status` column added to `rental_inventory_lines` itself, set/overwritten
  at move-out, or (b) a separate append-only "disposition" row per line (mirroring how
  `RentalInspectionObservation` is a distinct, comparable event against a persistent
  `RentalInspectionItem`) is not decided here. Option (b) keeps a clean point-in-time audit trail (what
  was true at move-in vs what was found at move-out, never overwritten); option (a) is simpler but loses
  that distinction unless paired with its own history log.
- **What vocabulary a move-out disposition uses.** `RentalInspectionObservation::condition` already has
  good/fair/damaged/not_working/missing/other — reusing it for inventory-line disposition was considered
  during this build but not committed to, since "missing" there means something narrower (a condition
  grade) than what an inventory line needs (present in full quantity / partially present / quantity short
  / damaged / missing entirely) — a genuinely different vocabulary that deserves its own naming, not
  forced reuse of a list built for a different question.
- **Whether a quantity SHORTFALL (3 keys handed over, 2 returned) is itself the deposit-relevant fact**,
  separate from a per-line status — i.e. does the comparison read `quantity` directly (move-in line
  quantity vs whatever gets recorded at move-out) rather than needing a new status field at all for the
  count-based lines. This may differ from how a non-countable single item ("1x LG Fridge/freezer silver")
  needs to be marked missing/damaged, where there is no meaningful "quantity" comparison to make.
- **Where the deposit outcome itself lives** — on `RentalInventory`, on the comparison service's own
  output, or feeding into `rental-work-orders.md`'s existing deposit-adjacent machinery — cc5's design,
  not pre-empted here.

**Not decided, not built, not assumed. Reported per Johan's explicit instruction to bring the shape
before building the comparison half.**

---

## 9. Files

- `database/migrations/2026_10_01_120000_create_rental_inventories_table.php`
- `database/migrations/2026_10_01_120100_create_rental_inventory_lines_table.php`
- `database/migrations/2026_10_01_120200_create_rental_inventory_signatures_table.php`
- `app/Models/RentalInventory.php`, `RentalInventoryLine.php`, `RentalInventorySignature.php`
- `app/Http/Controllers/CoreX/RentalInventoryController.php` (list/CRUD/lifecycle),
  `RentalInventoryRecordingController.php` (lines, signatures, complete)
- `routes/web.php` — `corex.rental-inventories.*`
- `config/corex-permissions.php` — `rental_inventories.view`/`.create`
- `resources/views/layouts/corex-sidebar.blade.php` — nav entry
- `resources/views/corex/rental-inventories/{index,create,show}.blade.php`

---

## 10. Verification status

Same limitation as `rental-inspections.md` §16.7/§17.6: all three migrations reviewed, not executed
against the shared `corex_qa1` schema — `php artisan migrate` refuses outright from any worktree that
isn't `/corex-qa1` (Standard −1g). All PHP passes `php -l`; every Blade view (including the new
`rental-inventories/*` set) compiles cleanly via `php artisan view:cache` against this worktree's own
independent `vendor/`. Route list confirms all 12 routes register with no conflicts. No browser tool is
available in this environment — a real capture over HTTP and the browser console on the live JS (the
inventory show page's own signing/line-add Alpine component, not reused from the property tab) are both
unverified by this build.
