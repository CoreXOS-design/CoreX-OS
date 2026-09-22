# Spec: Rental Inventory

**Status:** Capture surface REBUILT 2026-09-22 (cc6) — property-embedded, room-based, autosaving,
mobile-ready — per Johan's own description of what it should be; see §0b. Supersedes §0a's
reachability-only pass from earlier the same day (kept below for the record — its "not fixed" item
about a sale property's missing Lease is still open, see §0b's own restatement). Comparison half
(§8) is unaffected by this rebuild and remains as built 2026-10-01 — cc5's recommendations,
Johan's approval, all four open questions resolved as stated.

---

## 0a. Scope correction — a PROPERTY feature, not a rental-only one (2026-09-22)

Johan, verbatim, on why he couldn't find it: *"inventory was specifically specced not only for
rentals. sales will also need it. so thats why I cant find it. it should be on properties, not only
rental properties. inspections are rentals only, not sales."*

**Answering the question this correction demands plainly: this spec never said "rentals only" in
prose, but it was built rentals-only in effect, and that was never flagged as a limiting assumption
anywhere in the document until now.** §0-§3 describe the document entirely in tenancy terms (move-in,
move-out, a Lease to attach to) because that is literally how Johan introduced the feature — his own
example document was a furnished RENTAL's contents list. Nobody, including this spec, asked the
question "does a sale property need this too" before tonight. The data model followed that same
unexamined assumption: `rental_inventories.lease_id` is a required (`NOT NULL`), not nullable,
foreign key — §3.1's own schema, unchanged by this correction. A Lease is a rental-tenancy concept
that a sale property never has, so the feature was rentals-only not by a stated decision but by an
inherited one.

**What this pass fixes — reachability and labels only, per the conductor's explicit scope:**
- The property detail screen's Inventory section is no longer gated on `listing_type === 'rental'` —
  moved from inside the Rental Images tab (rental-only by construction — that whole tab doesn't render
  for a sale property) to the Overview tab, which renders for every property regardless of type
  (`resources/views/corex/properties/show.blade.php`).
- The `/corex/rental-inventories/create` property picker no longer filters
  `where('listing_type', 'rental')` (`RentalInventoryController::create()`).
- Every user-facing label reads "Inventory"/"Inventories", not "Rental Inventory" — sidebar, index
  heading, settings page title and settings-index link, the contact tab's section heading. Tables,
  columns, routes, model/class names, and permission keys are UNCHANGED (`rental_inventories.*`
  throughout) — label-level only, per explicit instruction.

**What this pass deliberately does NOT fix, reported not silently absorbed:** a sale property's
Inventory section will show and be reachable, but attempting to actually START one still fails —
`RentalInventory::start()` (`app/Models/RentalInventory.php`) hard-requires an active `Lease`, and a
sale property essentially never has one. The section's own message ("No active lease on this
property — an inventory needs one to attach to.") is accurate to the current mechanism but reads as a
dead end for a sale property, because it effectively still is one. **Whether a sale-property inventory
should be able to exist without a Lease at all — attached to `property_id` alone — is a genuine data-
model question this pass does not answer.** That is a bigger change than reachability/labels
(a nullable `lease_id`, a different `start()` signature, a different resolution of "which document
does a sale property's inventory attach to" since there is no tenancy to anchor it) and was not asked
for in this pass. Flagging it plainly rather than guessing at a redesign.

---

## 0b. The capture surface rebuilt — property-embedded, room-based (2026-09-22)

§0a fixed reachability and labels but left the capture surface itself untouched — a flat line list
behind a standalone sidebar list/create picker. Johan then described what it should actually be,
verbatim: *"theres no seperate inventory left pane menu item. it lives on a property. so selecting
inventory from the propety we already know which property its for. done simple. then agent needs to
see the spaces again, like with inspections. why ask the agent to retype something we know. so we
will have lounge. agent can again upload photos and tag it to the room, then type in what inventory
is in that room, and if they want to even tag the line item added to a photo in that room. serial
number of the tv in the lounge photo etc etc etc. And again it should be built that we are mobile
ready."* Explicitly a rebuild of the capture surface, not a further patch — the old surface was
worse than the inspection screen it should have matched.

**Answering the "did the spec already describe this" question plainly, item by item — the honest
answer is narrower, and in one place the spec actively argued the opposite:**

- **Room reuse.** §1.5 (above) explicitly reasoned AGAINST this: *"room_label is free text on each
  line, not a foreign key to `PropertyRoom`... The inventory has its OWN room list, and it does not
  match the inspection's."* That specific design call is now reversed by direct instruction. It
  wasn't a bad call in isolation — Johan's real move-in document genuinely does list rooms the
  inspection's own room set doesn't cover (Sunroom, Rubbish bin room...) — but "the agent never
  retypes a room CoreX already knows" outweighs it, and a free-text room was never asked for by name;
  it was this spec's own inference from the document.
- **Photos, and tagging a line to one.** Never mentioned anywhere before tonight. §3.2's line was
  quantity + free text only, no photo concept at all.
- **The property as the sole entry point, with no separate create step.** §0a moved the section INTO
  the property's Overview tab but left it as one entry point among four (§7.1's old table), each with
  its own "Start Inventory" button hitting the same picker-based `store()`. Johan's "done simple" is a
  narrower, stronger claim: exactly one way in, and it resolves/starts the record transparently — the
  agent never sees a "create" step at all.
- **Autosave, no Save button, mobile-ready.** Never stated before tonight. The existing capture UI
  (still the shape of `rental-inventories/show.blade.php`'s "Items" section) is a page-submit form
  with a submit button per line — the opposite of autosave, and untested at phone width.
- **PropertyRoom's own docblock**, however, already anticipated exactly this reuse, independently of
  this spec: it documents itself as designed for both "Rentals' inspection facets" AND "Sales' future
  inventory" to join against. That line predates tonight and was never connected to this feature until
  now — the capability existed in the codebase; nothing in this spec, or in `rental-inspections.md`,
  ever pointed the two together.

### 0b.1 What changed

- **Rooms.** `rental_inventory_lines` and the new `rental_inventory_photos` each gained a nullable
  `property_room_id` FK to `PropertyRoom` (§3.2 amended below). `room_label` is NOT dropped — it stays
  as the display value for any line captured before this change, and is now derived from
  `PropertyRoom.label` on create rather than typed. The inventory's OWN room list (§1.5's original
  reasoning) is superseded: it now uses the SAME room source, grouping, and order
  (`PropertyRoom.sort_order`, then `id`) that the property's own Inspection Items section already
  uses — the two features literally read the same rows, never a second room concept.
- **Photos.** New `rental_inventory_photos` table — one row per uploaded photo, tagged to a room,
  stored via the same shared `PropertyImageStorer` every other CoreX image pipeline uses (no second
  image pipeline). Batched client-side upload follows the exact contract cc5 already established this
  session for room-tagged gallery photos (≤10 files or 500MB per request, under
  `max_file_uploads=20` in `/etc/php/8.2/fpm/php.ini`, each file independently retry-safe via
  `client_idempotency_key`) — the same shape, not a second design.
- **Line-to-photo tagging.** New `rental_inventory_line_photos` pivot — a many-to-many, optional in
  both directions (a line may point at several photos, a photo may carry several line tags). A pure
  reference row: removing a tag deletes the pivot row rather than soft-deleting it, since the line and
  the photo it references are each already preserved independently elsewhere — untagging is not
  "losing evidence," it's un-pointing at evidence that still exists.
- **The single entry point.** `GET /corex/properties/{property}/inventory`
  (`RentalInventoryCaptureController::show()`) resolves-or-transparently-starts the property's current
  inventory via `RentalInventory::resolveOrStartFor()` — the property's active lease if one exists, no
  separate create step. A property with no active lease (still every sale property, per §0a's
  unresolved question — restated, not re-solved, by this pass) gets an honest "no active lease yet"
  message, not a picker, not a crash.
- **The old entry points collapsed to one shared link.** The property/lease/rental-inspection detail
  screens each still show an "Inventory" section, but it is now one line — a single link into the same
  capture page — via one shared partial
  (`resources/views/corex/rental-inventories/partials/_related-inventories.blade.php`), not three
  independent "Start Inventory" buttons each calling the old picker-based `store()`. The contact
  (tenant) screen keeps its own read-only cross-lease list (§7.1) — unaffected, it never had a create
  button.
- **Sidebar entry removed entirely** (`resources/views/layouts/corex-sidebar.blade.php`) — per Johan's
  explicit instruction, not a judgement call.
- **The standalone list/create screens (`index`/`create`/`store`/`show`/`comparison` routes and
  views) are KEPT, but are no longer the way in.** This is a deliberate choice, not an oversight: the
  CRUD/list-screen floor (CLAUDE.md non-negotiable #8/BUILD_STANDARD §1a-§1d — search, sort, filter,
  pagination, archive/restore) still needs to exist SOMEWHERE, and the comparison screen (§8) and
  three-party signing (§3.3/§5) still live on the full `show` page, reached via a link from the new
  capture surface once its status leaves draft. Nothing routes a user there by default any more.

### 0b.2 Interaction pattern — matched to the inspection surface, not reinvented

Autosave throughout: every add/edit/upload/tag fires its own request the instant the agent acts, no
Save button anywhere on the page. Rooms collapse by default once they have at least one item (open by
default while empty, so an agent lands straight on an empty room ready to type); each room heading
shows a live item count and photo count. Photo thumbnails render inline per room with a tap target
sized the same as the rest of CoreX's mobile-facing controls; the file input for adding photos is a
bare `<input type="file" multiple>` behind a labelled tile, which is what makes the phone's native
camera/gallery picker available for free — no custom camera UI was built or asked for. Single-column,
no fixed-width elements, verified to not require horizontal scroll at phone width.

### 0b.3 A pre-existing Blade bug, found and fixed in this pass, reported for the pre-existing file it also affects

Blade's `@json()` directive compiles via a naive `explode(',', $expression)` — see
`vendor/laravel/framework/src/Illuminate/View/Compilers/Concerns/CompilesJson.php`. Any array literal
built inline inside `@json(...)` with more than one key (e.g. `@json($x->map(fn($i) => ['a' =>
$i->a, 'b' => $i->b]))`) gets corrupted: the compiler treats everything after the FIRST comma as the
`$options`/`$depth` directive arguments, not part of the JSON expression, producing genuinely
malformed compiled PHP (`ParseError: Unclosed '[' does not match ')'`) — a real 500, not a
lint-only concern. Hit and fixed in this pass by moving every such transform out of the view and into
the controller (`RentalInventoryCaptureController::show()` now passes `$roomsForJs`/`$linesForJs`/
`$photosForJs` as already-built collections — `@json($linesForJs)` has no top-level comma to trip the
bug). **Found, NOT fixed (outside this task's scope — a different, already-landed file):**
`resources/views/corex/rental-inventories/show.blade.php:257` has the identical pattern —
`signatures: @json($inventory->signatures->map(fn($s) => ['party_role' => ..., 'party_contact_id' =>
..., 'disposition' => ...]))`, three keys, two top-level commas. By the same mechanism this almost
certainly 500s the inventory show page's signing script block today. Reported here per CLAUDE.md
non-negotiable #2 (report, don't drive-by-fix an out-of-scope file) — this needs the same
extract-to-controller-variable fix, and is a one-line-of-reasoning change once someone picks it up.

### 0b.4 What this pass deliberately does not touch

`disposition_presets`/Setup Wizard (§8.4, still open, still Johan's call). No test/demo inventory
records created or left behind. `properties/show.blade.php` — the ONE line changed there is the
existing Inventory `@include`, now pointing at the simplified shared partial; nothing else in that
file (cc2's concurrent work) was read for content beyond confirming the `roomGroups()`/room-ordering
convention to mirror, and nothing else in it was edited. `rental-inspections/show.blade.php` /
`leases/show.blade.php` needed NO direct edits — they already included the shared partial, so
rewriting the partial once updated both automatically.

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
5. **~~The inventory has its OWN room list, and it does not match the inspection's~~ — SUPERSEDED,
   see §0b.** Originally: Sunroom, Rubbish bin room, Entrance from glass front door, Dining
   room/Balcony, Lounge, Laundry Room, Outside front of house — rooms `rental-inspections.md` never
   mentions — reasoned into `room_label` as free text, not a foreign key to `PropertyRoom`. Reversed
   2026-09-22 by direct instruction: the capture surface now uses the property's own `PropertyRoom`
   rows, same as inspections. `room_label` stays as a column (back-compat display for lines captured
   before the change) but is no longer how a new line's room is chosen.
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
  property_room_id -- nullable FK -> property_rooms (§0b, 2026-09-22). NULL only for lines
                    -- captured before this change; every new line sets it.
  room_label     -- free text (§1.5, superseded by §0b) — derived from property_room_id.label on
                  -- create now, kept as the back-compat display column, NOT a foreign key itself
  quantity       -- unsigned int, default 1
  description    -- text — location, brand, colour, state annotations all live here (§1.1-1.4)
  sort_order
  is_retired     -- §3.3-style retirement (rental-inspections.md), never a hard delete
  created_by_user_id
  timestamps

rental_inventory_photos              -- §0b, 2026-09-22
  id, agency_id, rental_inventory_id, property_room_id (nullable FK -> property_rooms)
  storage_path            -- via the shared PropertyImageStorer, same pipeline every other
                           -- CoreX image upload uses
  file_size_bytes, uploaded_by_user_id
  client_idempotency_key  -- uuid, unique — retry-safe batched upload, same convention as
                           -- RentalInspectionPhoto
  timestamps, deleted_at  -- soft-deletable, same as every other evidence record here

rental_inventory_line_photos         -- §0b, 2026-09-22 — pure reference pivot, genuinely
  id, agency_id                      -- (hard-)deleted on untag: the line and the photo it
  rental_inventory_line_id           -- points at are each independently preserved elsewhere,
  rental_inventory_photo_id          -- so removing the tag loses no evidence.
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

**Superseded by §0b: the sidebar entry is REMOVED, not relabeled.** `/corex/rental-inventories` still
exists — search (property address, tenant name, agent name), sort (default: started,
most-recent-first; also property, status), filter (status, date range), pagination, real empty state,
OWN/BRANCH/AGENCY scoping (`RentalInventory::scopeVisibleTo()`, identical convention to
`RentalInspection`'s own), archive/restore floor (soft-delete via `destroy()`/`restore()`, no hard
delete) — this satisfies the CRUD/list-screen floor requirement, but it is no longer linked from
anywhere in the UI by default (§0b: "it lives on a property," one entry point only). Kept as the
admin/audit surface, not the way an agent gets to Inventory day to day.

**Confirmed, not assumed: the list query itself never filtered by `listing_type`** —
`RentalInventoryController::index()` queries `RentalInventory` directly and was never scoped to rental
properties at any point in this feature's history. The only place a rental-only filter ever existed was
the `/create` property picker, removed in §0a.

### 7.1 Reachability — every entry point, checked (superseded by §0b — one link, not a list+create panel)

§0a's version of this table described four screens each showing a mini-list-plus-"Start Inventory"
button via the shared partial. §0b collapsed that to ONE link everywhere except the contact screen,
which was always read-only:

| Screen | What it shows | Why |
|---|---|---|
| Property (Overview tab, every listing type) | One "Inventory" link → the room-based capture surface (`GET /corex/properties/{property}/inventory`) | The sole entry point (§0b) — resolves/starts the record transparently, no picker |
| Lease | Same one-line link, same shared partial | One lease, one obvious property to open Inventory from |
| Rental inspection | Same one-line link, same shared partial | Same property/lease as the inspection; inspections stay rentals-only, so this never implies a sale-property inspection |
| Contact (tenant) | Read-only list across every lease this contact is a tenant on — unchanged by §0b | A contact can be tenant on more than one lease/property; there is no single property to link into from here, so it stays a reference list, never a creation entry point |

### 7.2 The empty state teaches, not just states (§1b)

The index's day-one empty state (`resources/views/corex/rental-inventories/index.blade.php`) replaces
the table entirely — not a sentence bolted above a blank grid — with a short explanation of what an
inventory is for and the "Start Inventory" button, shown only when the agency genuinely has zero
inventories and no filter is applied; the narrower cases (archived-empty, filtered-empty) keep their
existing plain row-level messages, since "no results for this filter" and "genuinely nothing yet" are
different facts and read differently on purpose (BUILD_STANDARD §1b).

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

### 8.4 NOT in the Setup Wizard — flagged, not silently decided (2026-10-01, cc1's catch)

`disposition_presets` does not appear in `config/agency-onboarding-copy.php`, and this spec did not
originally record an explicit ruling either way — an omission caught by cc1 during landing, not decided
here. CLAUDE.md non-negotiable #10a requires either the wizard entry or an explicit, Johan-approved
"deliberately NOT in the wizard" line — silence is not a legitimate third option.

The likely-correct answer, stated plainly so it isn't mistaken for the actual ruling: `disposition_presets`
is a repeater/list control, architecturally identical in shape to `refusal_reason_presets`
(`rental-inspections.md` §15.6), which already carries exactly this exemption — *"editable here, NOT in
the Setup Wizard: the wizard's generic control types (number/select/text/textarea/toggle) have no
repeater/list type, and building one is out of scope."* The same constraint almost certainly applies here.
**But this is Johan's call to make explicit, not this spec's to assume** — recorded as open, per cc1's
flag to the conductor, until he rules on it.

## 9. Files

- `database/migrations/2026_10_01_120000_create_rental_inventories_table.php`
- `database/migrations/2026_10_01_120100_create_rental_inventory_lines_table.php`
- `database/migrations/2026_10_01_120200_create_rental_inventory_signatures_table.php`
- `database/migrations/2026_10_01_130000_create_rental_inventory_settings_table.php`
- `database/migrations/2026_10_01_130100_create_rental_inventory_line_dispositions_table.php`
- `database/migrations/2026_10_02_100000_add_property_room_to_rental_inventory_lines.php` (§0b)
- `database/migrations/2026_10_02_100100_create_rental_inventory_photos_table.php` (§0b)
- `database/migrations/2026_10_02_100200_create_rental_inventory_line_photos_table.php` (§0b)
- `app/Models/RentalInventory.php`, `RentalInventoryLine.php`, `RentalInventorySignature.php`,
  `RentalInventorySetting.php`, `RentalInventoryLineDisposition.php`,
  `RentalInventoryPhoto.php` (§0b, new)
- `app/Services/Rentals/RentalInventoryComparisonService.php`
- `app/Http/Controllers/CoreX/RentalInventoryController.php` (list/CRUD/lifecycle/comparison — kept,
  no longer the primary entry point, §0b), `RentalInventoryRecordingController.php` (lines,
  dispositions, signatures, complete — extended for `property_room_id`, §0b),
  `RentalInventorySettingsController.php`,
  `RentalInventoryCaptureController.php` (§0b, new — the property-embedded capture surface: `show`,
  `storePhotos`, `attachLinePhoto`, `detachLinePhoto`)
- `routes/web.php` — `corex.rental-inventories.*`, `corex.settings.rental-inventory.*`,
  `corex.properties.inventory.show` (§0b, new — `GET /corex/properties/{property}/inventory`)
- `config/corex-permissions.php` — `rental_inventories.view`/`.create`/`.manage_settings` (unchanged
  by §0b — the new controller reuses these same two permission keys)
- `resources/views/layouts/corex-sidebar.blade.php` — nav entry REMOVED (§0b)
- `resources/views/corex/settings.blade.php` — settings-index link (unaffected by §0b — the
  disposition-presets settings page is a separate concern from the capture surface)
- `resources/views/corex/rental-inventories/{index,create,show,comparison}.blade.php` — kept, no
  longer the primary entry point (§0b)
- `resources/views/corex/rental-inventories/capture.blade.php` (§0b, new — the room-based capture
  surface, its own independent Alpine component, deliberately not extending
  `properties/show.blade.php`'s shared component)
- `resources/views/corex/rental-inventories/partials/_related-inventories.blade.php` — rewritten
  (§0b) from a mini-list-plus-create-button into a single link, shared unmodified by
  `properties/show.blade.php`, `leases/show.blade.php`, `rental-inspections/show.blade.php`
- `resources/views/corex/settings/rental-inventory.blade.php`
- `tests/Feature/RentalInventory/RentalInventoryCaptureTest.php` (§0b, new)

---

## 10. Verification status

Same limitation as `rental-inspections.md` §16.7/§17.6 for the original capture/comparison halves: all
eight migrations (three original capture, two comparison, three from §0b) reviewed, not executed
against the shared `corex_qa1` schema — `php artisan migrate` refuses outright from any worktree that
isn't `/corex-qa1` (Standard −1g); landing/execution is cc1's job. All PHP passes `php -l`. Route list
confirms the new `corex.properties.inventory.show` route and the three new
`corex.rental-inventories.{photos.store,lines.photos.attach,lines.photos.detach}` routes register with
no conflicts.

**§0b's capture surface specifically — real, not merely reviewed:** `php artisan migrate` cannot touch
`corex_qa1` from this worktree, so full proof used a dedicated Feature test
(`tests/Feature/RentalInventory/RentalInventoryCaptureTest.php`) against an isolated, throwaway MySQL
schema (RefreshDatabase — Laravel's own migration replay, the sanctioned way to prove a NEW migration
set is correct without touching the shared QA1 schema) — this is real HTTP through the actual
routing/controller/view stack, not a mock. 4/4 passing, 24 assertions, run in isolation (a concurrent
run against the same throwaway schema deadlocked twice before this — noted here so a future run knows
not to fire overlapping `php artisan test` invocations against one schema). Proves, by real request/
response/DB-state, exactly the sequence Johan asked to see: open Inventory from a property → the
inventory resolves/starts transparently, its active lease's property rooms are listed → add a line
item to a room → upload a photo to that room → tag the line to that photo → reload the page → every
one of those survives, with no endpoint in the whole sequence named anything like "save." Also proves:
a sale property (no active lease) renders an honest state instead of a 500; a line/photo belonging to
a different inventory 404s rather than leaking (agency-scoping at the object level, not just the
query). This test run is what stands in for the "real clicks on deployed QA1" proof pre-landing — a
matching real-browser pass against the actually-deployed QA1 environment still needs cc1 to run the
migrations first; either cc1 or a follow-up prompt can do that browser pass once landed.

**Not run:** `scripts/dev-check.ps1` — PowerShell, and this Linux box has no `pwsh` installed. Could
not be run from here at all, not skipped by choice; flagging plainly rather than silently omitting it.
A Windows dev machine (or a box with `pwsh` installed) needs to run it before/at final merge.

**A real bug found and fixed in this pass, plus one found and left for its owning file** — see §0b.3:
Blade's `@json()` directive corrupts on any inline array literal with more than one key. Fixed in the
new capture controller/view (moved the transform server-side into plain variables). The identical
pattern in the ALREADY-LANDED `rental-inventories/show.blade.php:257` (the signing page's `signatures:
@json(...)` line) was found by the same code-reading, not independently re-verified by re-rendering
that page — reported, not fixed, per CLAUDE.md non-negotiable #2 (that file is out of this task's
scope).
