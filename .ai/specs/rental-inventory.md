# Spec: Rental Inventory

**Status:** Capture half AND comparison half pushed, awaiting landing (2026-10-01, cc6). §8's four
open questions were resolved by cc5 (recommendations) and approved by Johan (all four, as stated,
2026-10-01) before the comparison half was built — see §8 for the shape as actually built.

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

## 8. The comparison half — BUILT, cc5's recommendations, Johan's approval (2026-10-01)

Coordinated with cc5 before settling the capture shape (§3.2), then again on the four open questions
below before building this half — cc5 answered with implementation recommendations, Johan approved all
four as stated. Quoted/paraphrased plainly so the reasoning survives, not just the conclusion:

1. **How a move-out disposition per line gets captured: append-only, one row per line per move-out
   event — never a status column overwritten on the line itself.** Johan: "it matches the
   supersede-never-edit discipline across the whole module and it means a move-out record survives a
   later dispute." Built as `RentalInventoryLineDisposition` — mirrors how `RentalInspectionObservation`
   is a distinct, comparable event against a persistent `RentalInspectionItem`. No `update()` method
   exists on this class; a correction is a new row, and `RentalInventoryLine::latestDisposition()` reads
   the most recent as current while every earlier row stays exactly as filed.
2. **Vocabulary: a purpose-built set (present / short / damaged / missing), NOT
   `RentalInspectionObservation::condition`** — an inventory line is a different question from a wall's
   condition. Agency-configurable via the same `{key, label, requires_notes}` shape cc2 built for
   inspection condition states, per cc5's explicit recommendation — but stored on this module's OWN new
   `RentalInventorySetting` model, not by extending `RentalInspectionSetting`: cc2's actual
   `condition_states` column lives on a different, unlanded branch this build cannot see, and touching
   that file risked a collision with work in flight. The SHAPE matches; the STORAGE is deliberately
   separate. See the settings migration's own docblock.
3. **Quantity shortfall: read directly off `quantity` (move-in) vs a NULLABLE `quantity_found`
   (move-out) — no separate status needed for the delta itself.** Johan: "null means not-yet-counted and
   is never coerced to zero... A tenant must never be charged for something nobody counted." This is the
   EXACT discipline `rental-inspections.md` §17's header block already established for
   `keys_count`/`remotes_count`, carried here deliberately — `RentalInventoryComparisonService` only
   computes a delta when `quantity_found` is genuinely present; a line nobody has counted yet shows as
   "not yet checked," never as a false shortfall. `disposition_key` and `quantity_found` are orthogonal on
   the same row — cc5's own example, straight off Johan's real form: "2x Big pots... missing" is a
   `disposition_key='missing'` row; "3 of 4 chairs returned AND damaged" is `quantity_found=3` (against
   `quantity=4`) PLUS `disposition_key='damaged'`, both true on the same finding.
4. **Where the outcome lives: `RentalInventoryComparisonService`, a read-time-only comparison — no
   stored classification, no currency anywhere.** Mirrors the same money boundary cc5 holds for the
   inspection comparison. Output is a proposal an agent reviews on `/corex/rental-inventories/{id}
   /comparison`, computed fresh on every load. Explicitly does NOT touch `rental-work-orders.md` — that
   module is about repairs, this is about presence/shortfall. If Johan wants inventory and inspection
   findings combined into one deposit view, that is a later, explicit call, not assumed here.

### 8.1 Data model addition

```
rental_inventory_line_dispositions   -- APPEND-ONLY, never updated, never deleted
  id, agency_id, rental_inventory_line_id, rental_inventory_id (denormalized)
  disposition_key       -- agency-configurable key, e.g. 'present' | 'short' | 'damaged' | 'missing'
  quantity_found         -- nullable int. NEVER coerced to 0 — absent means "not yet counted."
  notes                  -- required when the agency's own preset for this key has requires_notes=true
  recorded_by_user_id, recorded_at

rental_inventory_settings            -- one row per agency
  disposition_presets    -- JSON array of {key, label, requires_notes}, default:
                          --   Present / Short — quantity missing / Damaged (requires notes) /
                          --   Missing entirely (requires notes)
```

`RentalInventoryLineDisposition::record()` is the one factory method enforcing every invariant: the
inventory must already be `completed` (a finding compared against a baseline that isn't final yet is
comparing against nothing settled), the `disposition_key` must exist in the agency's configured presets,
and `notes` is required exactly when that preset's `requires_notes` is true.

### 8.2 The review screen

`GET /corex/rental-inventories/{inventory}/comparison` — gated on the inventory being `completed`, linked
from the inventory's own show page (visible only once that status is reached, so the link is never a dead
end). Table of every active line: quantity at move-in, the latest recorded finding (or "Not yet checked"),
and a Record/Correct action per row that posts a new disposition row. No deposit figure, no currency
symbol, anywhere on this screen.

### 8.3 Settings screen

`/corex/settings/rental-inventory` — its own page (not a section added to `/corex/settings/
rental-inspections`, for the same unlanded-branch-collision reason as §8.1), gated on the new
`rental_inventories.manage_settings` permission, linked from the main Settings index alongside every
other rental_* settings page.

---

## 9. Files

- `database/migrations/2026_10_01_120000_create_rental_inventories_table.php`
- `database/migrations/2026_10_01_120100_create_rental_inventory_lines_table.php`
- `database/migrations/2026_10_01_120200_create_rental_inventory_signatures_table.php`
- `database/migrations/2026_10_01_130000_create_rental_inventory_settings_table.php`
- `database/migrations/2026_10_01_130100_create_rental_inventory_line_dispositions_table.php`
- `app/Models/RentalInventory.php`, `RentalInventoryLine.php`, `RentalInventorySignature.php`,
  `RentalInventorySetting.php`, `RentalInventoryLineDisposition.php`
- `app/Services/Rentals/RentalInventoryComparisonService.php`
- `app/Http/Controllers/CoreX/RentalInventoryController.php` (list/CRUD/lifecycle/comparison),
  `RentalInventoryRecordingController.php` (lines, dispositions, signatures, complete),
  `RentalInventorySettingsController.php`
- `routes/web.php` — `corex.rental-inventories.*`, `corex.settings.rental-inventory.*`
- `config/corex-permissions.php` — `rental_inventories.view`/`.create`/`.manage_settings`
- `resources/views/layouts/corex-sidebar.blade.php` — nav entry
- `resources/views/corex/settings.blade.php` — settings-index link
- `resources/views/corex/rental-inventories/{index,create,show,comparison}.blade.php`
- `resources/views/corex/settings/rental-inventory.blade.php`

---

## 10. Verification status

Same limitation as `rental-inspections.md` §16.7/§17.6: all five migrations (three from the capture half,
two from §8's comparison half) reviewed, not executed against the shared `corex_qa1` schema — `php
artisan migrate` refuses outright from any worktree that
isn't `/corex-qa1` (Standard −1g). All PHP passes `php -l`; every Blade view (including the new
`rental-inventories/*` set, the comparison screen, and the settings page) compiles cleanly via `php
artisan view:cache` against this worktree's own independent `vendor/`. Route list confirms all 14
`rental-inventories.*` routes plus the 2 settings routes register with no conflicts; the new permission
key (`rental_inventories.manage_settings`) loads correctly from config. No browser tool is available in
this environment — a real capture over HTTP, recording a move-out disposition, and the browser console on
the live JS (the inventory show page's own signing/line-add component and the comparison screen's own
recording component, neither reused from the property tab) are all unverified by this build.
