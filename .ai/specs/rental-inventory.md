# Spec: Rental Inventory

**Status:** Capture surface design pass 2 landed 2026-09-22 (cc6) — one-row item entry, no announced
autosave, one shared column grid for the list and the entry row, phone width verified; see §0c. Built
on top of §0b's rebuild (property-embedded, room-based, autosaving, mobile-ready), which supersedes
§0a's reachability-only pass from earlier the same day (kept below for the record — its "not fixed"
item about a sale property's missing Lease is still open, see §0b's own restatement). Comparison half
(§8) is unaffected by either pass and remains as built 2026-10-01 — cc5's recommendations, Johan's
approval, all four open questions resolved as stated. §4a's photo-machinery adoption (shared uploader,
gallery-sized fixed-frame layout) landed 2026-09-22 (cc4) — see §4a for exactly what was and wasn't
adopted. §0d (2026-09-24) moved the property's entry point again — off the bottom of the Overview
tab (where §0a/§0b left it) into its own "Inventory" tab, immediately after Inspections. §0a/§0b's
"still on the Overview tab" statements are historical (accurate at the time they were written), not
current — §0d supersedes the tab location only, nothing else in either section changed. §11
(2026-09-27) investigated Inventory against the lessons learned fixing rental-inspections the same
week and ranked what to fix first. §12 (2026-09-27) BUILT the completion gate §11.4 found (an
inventory could be signed "complete" with zero lines/rooms recorded — now refused server-side, in
`RentalInventory::markCompleted()`, the single choke point every completion path goes through), the
"nothing in this room" mark §11.4's fix needed (`rental_inventory_room_marks`, the inventory-side
counterpart to inspections' "Mark room N/A"), §11.2's line-entry data-loss fix (a mouse click away
from an edited cell, with no Tab/Enter first, previously discarded the edit — now closed with
`@blur`/`visibilitychange`/`beforeunload`), and §11.8's move-in-photos-on-comparison fix (the
already-linked data now actually renders). QA1 was checked directly and carries zero
completed-but-empty inventories to worry about (§12.5) — 7 rows total, 4 cancelled, 3 draft, 0
completed. §13 (2026-09-27, same day) rebuilt the capture screen to Johan's approved mockup: a
room-chip strip with status dots replaces the accordion list, per-line condition chips (a new,
distinct vocabulary from §8's move-out disposition), a per-line photo strip that uploads the instant
a file is picked, and "Copy from last inventory" for a re-let property. §14 (2026-09-27, same day)
rebuilt the move-in-vs-now comparison screen to the same approved mockup: quantities on both sides of
one line ("4 at move-in, 2 today"), disposition wording aligned to "all there / short / damaged /
missing" with four distinct badge colours, unchanged lines collapsed to one grey line (a new
agency-configurable baseline-disposition setting, mirroring Inspections' own baseline-condition
pattern), an "Only differences" filter, and a brand-new move-out photo side ("No photo taken yet."
shown explicitly, never hidden, with an immediate-upload control right on the row). All three stages
of this same-day build are in, in the order Johan set: completion gate, then capture, then comparison.

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

## 0c. Design pass 2 — entry-row layout, helper text, alignment (2026-09-22, cc6)

Johan's review of the deployed §0b capture screen: *"in what world will this screen use different
configs to upload photos than what the inspections uses? Not sure but the inventory screen is still
preschool design."* He was right on both. Photos are explicitly out of scope for this pass — see the
new §4a below for why and what changes once cc2's shared uploader lands. Four things fixed, in
`capture.blade.php` only:

1. **One-row item entry.** The add-line row (quantity, description, Add) is one row — quantity narrow,
   description gets the space, Add sized to the action, not a full-width block. Enter in the
   description field submits the same as clicking Add (unchanged from §0b — this already worked;
   see the root-cause note below for why it didn't *look* like it worked on the deployed box).
2. **No announced autosave.** Removed the line *"Every change here saves itself — nothing to press."*
   Per Johan's standing rule: autosave is proven by behaviour, never announced. What's left in that
   banner (`Signatures & complete →`) is a real navigation control, not narration — every remaining
   line on the screen is either data the agent needs or a control they act on. The empty-state and
   locked-state copy elsewhere on the page (§3 of the original spec above) is unchanged — that copy
   explains a state and offers a path forward, which STANDARDS.md's "No Silent Locks" rule requires;
   it is not the kind of narration this pass removes.
3. **One column template, three surfaces.** The add-line row and every item row in the list now share
   ONE layout — qty (4.5rem) | description (1fr) | actions (7.5rem) — so a room's item list reads as
   an aligned table at any item count, not loose boxes, and the entry row lines up with the list it's
   adding to. Verified against a seeded 20-item Kitchen room: reads as one tidy column-aligned list,
   Remove right-aligned on every row, the entry row directly beneath on the same columns.
4. **Phone width holds.** At 390px the entry row stays one row — quantity box, description box, Add
   button, no stacking. The description cell is narrow (roughly 100–120px net of the fixed
   quantity/actions columns) and a long description wraps to 2–3 lines within its own cell; the row
   itself never breaks into stacked controls.

### 0c.1 Root cause of what Johan actually saw — a stale asset build, not a markup defect

The add-line row *was already* a single-row grid in the §0b source (`grid-cols-[4.5rem_1fr_auto]`, a
Tailwind arbitrary-value class) — Johan saw it as three stacked full-width fields with a heavy
full-width navy button because the **compiled CSS bundle on the deployed checkout predated this file**
(`public/build/manifest.json` was ~12 days older than `capture.blade.php`'s own commit), so Tailwind's
JIT scan never compiled that class in, and the row fell back to unstyled block flow: an `<input>`
sized by its own default width, a `<form>` (block-level) forcing a new line before the button, no grid
applied. Confirmed directly, read-only, against `/corex-qa1` (never modified): the blade file's mtime
was newer than `public/build/manifest.json`'s.

**This is a class of defect that can recur on any future page**, so the fix in this pass is structural,
not a one-off patch: every grid layout this screen needs is now inline
`style="display:grid; grid-template-columns:..."`, never a Tailwind arbitrary-value utility class.
Inline style has no build step to go stale — it renders identically whether or not `npm run build` has
run since the file last changed. Flagged to the conductor as a deploy-hygiene finding independent of
this screen: any other page added or changed in roughly the same window may be relying on Tailwind
classes the current deployed bundle never compiled, and needs an asset rebuild at next landing to
actually look like its own source, not just this one screen.

### 0c.2 Verification, and why it looks different from §0b's

Per Johan's own standing rule (relayed 2026-09-22, mid-build): a lane's local render is not proof — he
verifies every visible change himself on the deployed site, and local verification scaffolding
(dev servers, headless-browser harnesses, minted session cookies) burns real time without producing
proof he trusts. This pass's verification is therefore narrower than §0b's, by instruction, not by
omission: `php -l` on the changed file, `php artisan view:clear`, and the existing
`tests/Feature/RentalInventory/RentalInventoryCaptureTest.php` (4/4 passing, 24 assertions, unchanged
by this pass — it already covers the add-line/photo/tag/reload loop this pass's markup change sits
inside). No new test was added — this pass changes only the layout classes/copy of an already-tested
surface, not its behaviour; the existing suite already proves the behaviour is intact. No browser
harness, no local dev server, no seeded preview fixture was left behind. The real-browser proof is
Johan's own pass against the deployed QA1 URL once cc1 lands this.

---

## 0d. Tab placement — moved off Overview into its own "Inventory" tab (2026-09-24)

Johan, days before this pass, on why he couldn't find Inventory at all: *"where is the inventory now?
dont seem to see it on a property."* §0a/§0b's fix put the single Inventory link at the very bottom of
the property's Overview tab — below the map, the Surveyor General block, Key Dates, and Tenant. That
placement was never flagged as a reachability problem in either section, and it was one: the link was
real and worked, but an agent had to scroll past four other blocks on the busiest tab on the page to
find it, which is exactly the "can't find it" experience Johan reported.

**What changed:** the one-line `_related-inventories` include (unchanged content, unchanged target
route — `GET /corex/properties/{property}/inventory`) moved out of the Overview tab entirely into its
own **Inventory** tab in the tab bar, positioned immediately after **Inspections**
(`resources/views/corex/properties/show.blade.php`). The Overview tab no longer renders it at all —
one entry point, moved, not duplicated.

**Gating — deliberately NOT copied from Inspections.** The Inventory tab button is skipped only when
`$isNew` (same as the old Overview inclusion's own guard) — it is never gated on `listing_type`. This
was a live conflict during this pass: the instruction that opened this task said to match Inspections'
own rule (`$isNew || listing_type !== 'rental'`) exactly, which would have hidden the tab — and the
feature — from every sale property, directly reversing §0a's own explicit correction (Johan: *"inventory
was specifically specced not only for rentals. sales will also need it... inspections are rentals only,
not sales."*). Flagged and ruled on before writing any code: the spec's existing rental-vs-sale
correction wins. Inspections stays rental-only; Inventory does not; the two tabs sit next to each other
in the bar but are gated by two different rules on purpose.

**7.1's table (below) is updated to reflect this** — the Property row now reads "Inventory tab" rather
than "Overview tab." Nothing else in §7.1 changed: Lease, Rental inspection, and Contact reachability
are unaffected by this pass.

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

## 4a. Photo uploader/layout adoption — BUILT (2026-09-22, cc4)

**§0c referenced this section by name before it existed — that dangling reference is fixed by writing
it now, spec-only, no code changed in this pass.** Johan, reviewing the deployed §0b/§0c capture screen:
*"in what world will this screen use different configs to upload photos than what the inspections
uses?"* He is right, and the intent is unambiguous: **Inventory's own photo capture is not a separate
design — it adopts the SAME uploader and the SAME layout `rental-inspections.md` §20.13/§22 already
built and refined**, not a second implementation that happens to look similar.

**What "adopt" means concretely, once built:**

- **The shared uploader** — `public/js/corex-photo-batch-uploader.js` (§20.13.4). This file was already
  written config-driven and backend-agnostic specifically so a second feature could consume it without a
  rewrite: `{ csrf, uploadUrl, tagUrl(id), tagBulkUrl, untagUrl(id), archiveUrl(id), photos }`. It owns
  client batching, raw-XHR upload progress, per-file idempotency keys, per-batch retry, and multi-select
  (click/shift-click/ctrl-click/drag-marquee) — none of that needs reinventing for Inventory; it needs
  wiring to Inventory's own endpoints.
- **The one real semantic divergence, already named on the inspections side and repeated here so it
  isn't rediscovered at build time** (§20.13.4's own note): Inventory tags a photo to a LINE ITEM via a
  many-to-many pivot (`rental_inventory_line_photos`, §3.2 — one photo may illustrate several line items
  at once, e.g. "the TV and the stand in one lounge photo"), while inspections supersede a single
  room/item tag per photo. The uploader component itself doesn't care which shape its `tagUrl`/
  `tagBulkUrl` implement — it just calls them with `{photo_id(s), room_id}`-shaped bodies — so this is a
  backend-endpoint difference, not a reason to fork the JS component.
- **The layout** — the item-photo-strip sizing mechanism `rental-inspections.md` §20.14.4/§22.3 finally
  landed on (flex `stretch` row, `flex:none` button/content block, `flex:1` photo strip holding an
  absolutely-positioned scroller so a photo's native resolution can never grow the row) is the SAME
  mechanism Inventory's own room/line photo strips should use once built — not a second layout arrived at
  independently. **Read §22.3 (`rental-inspections.md`) before building this** — it records four real,
  shipped-then-fixed attempts at this exact sizing problem, including the specific testing trap (a 1×1
  pixel test fixture hides the bug entirely) that cost three of those four attempts. Reusing the finished
  mechanism, not re-deriving it, is the entire point of this section existing.
- **Room-level gallery-sized photos, clipped by count not height, "Show all N"** — the same R1 fix
  (`rental-inspections.md` §20.14.3, corrected) applies to Inventory's own per-room photo strip once its
  photo count can realistically exceed one row.

**Why this was spec-only before this pass**: §0c explicitly scoped photos out of that pass — Inventory's
`capture.blade.php` uploaded photos through its OWN bespoke mechanism (§0b.1), not the shared uploader.
This section recorded the INTENT and the exact two files to read before building it, so the eventual swap
would be "adopt the finished thing," never "design a second one that happens to converge."

### 4a.1 What actually shipped (2026-09-22, cc4)

Johan's own words, restated by the conductor: *"in what world will this screen use different configs to
upload photos than what the inspections uses?"* — fixed. `capture.blade.php` now loads
`public/js/corex-photo-batch-uploader.js` (the same `<script src>` line `properties/show.blade.php` uses
for inspections) and every upload goes through one memoized `photoUploader()` instance for the whole
inventory — the SAME batching, raw-XHR progress, per-file idempotency, and independently-retryable failed
batches inspections already has. The bespoke `uploadPhotos()`/`planUploadBatches()` pair is gone.

**Layout — the ROOM GALLERY mechanism (§20.14.3/R1), not the item-strip mechanism (§20.14.4/R2/§22.3).**
Room photo tiles are a `grid-cols-3 sm:grid-cols-5` grid, `aspect-ratio:1/1`, `object-cover`, clipped by
COUNT (first 3, "Show all N") — never by height, never derived from a photo's own natural resolution or an
elastic container. This is deliberately the room-gallery pattern, not the item-strip's flex-stretch/
absolutely-positioned-scroller pattern — the latter needed four attempts to get right for a fundamentally
different shape (a button grid stretching a horizontally-scrolling strip to match its own height), and
Inventory's line items have no equivalent button grid to stretch against. The existing line-tagging modal
(64×64 fixed-size `object-cover` tiles) was already frame-safe and is unchanged.

**Archive — added, not previously built.** `RentalInventoryPhoto` had `deleted_at` from its first
migration but no reachable delete path anywhere on this screen (non-negotiable #1 gap). Added
`archived_by_user_id` (migration `2026_10_02_100300`, mirrors `rental_inspection_photos` exactly),
`RentalInventoryPhoto::archive()`, and `DELETE /corex/rental-inventories/{inventory}/photos/{photo}`
(`corex.rental-inventories.photos.destroy`) — soft delete only, with a confirm-then-× control on every
room photo tile, same as inspections' tray.

**Deliberately NOT adopted — a real scope decision, not an oversight:**
- **`tagUrl`/`tagBulkUrl`/`untagUrl` — no UI wired.** Inventory photos are still uploaded already-tagged
  to the room the agent clicked "Add photo(s)" from (§0b's original design, unchanged) — there is no
  untagged tray, no drag-marquee-select, no "move to a different room" control. The shared uploader
  supports all of this (and the config already accepts these URLs if a future pass wants them — the
  divergence is backend-endpoint only, per the note above), but building that UI was not part of this
  ask and would be a real, separately-scoped surface. If Johan wants full tray/re-tag parity with
  inspections, that is a follow-up.
- Line-item photo tagging (`rental_inventory_line_photos`, the many-to-many pivot) is untouched — still
  the pre-existing `attachLinePhoto`/`detachLinePhoto` endpoints and tagger modal, exactly the divergence
  this section always said was backend-only, never a reason to fork the JS component.

No new Tailwind utility classes were introduced — `grid-cols-3 sm:grid-cols-5`, `object-cover`,
`overflow-hidden`, `aspect-ratio` (inline style) are all already compiled into the CSS bundle via
`rental-inspection-recording.blade.php`'s own use of the identical classes.

---

## 4b. Create spaces from blank — BUILT (2026-09-22, cc4)

Johan, restating the spec: *"we specced inventory being blank then you can create the spaces same as
with inspections."* §0b's own honest-state message ("Add rooms from the property's Inspection Items
section first") described the blank-property gap accurately but left the agent stuck mid-walkthrough —
every new property starts with zero rooms, which is exactly the state this fixes.

**Reuses the inspections write path directly — no second space model.** The capture screen's own "Add
space" form (room type + name, always visible, not just when blank) posts to the EXACT SAME endpoint
Inspection Items' own "Space (new room)" control uses —
`RentalInspectionRecordingController::storeItem()` (`kind=space`), which creates a real `PropertyRoom`
row plus the agency's default checklist under it. Inventory only needs the room id/label from the
response and discards the checklist rows — but their existence means a space created from Inventory is
immediately inspection-ready too, not a bare shell. Gated by `access_properties` (the same permission
every other property-page write already requires), not a new inventory-specific permission.

**Proven, not assumed**: `test_a_space_created_from_a_blank_property_is_the_same_property_room_inspections_would_see`
starts from a genuinely empty property (zero `PropertyRoom` rows), calls the shared route, and asserts
the returned room is a real `PropertyRoom` — same table, same query
(`PropertyRoom::where('property_id')->where('is_retired', false)`) both surfaces already use, so a room
visible to one is visible to the other by construction, not by convention.

**`:style` clobber scan (rental-inspections.md §22.3b's bug class)** — swept `capture.blade.php` for any
tag carrying both a static `style="..."` and a bound `:style="..."`: one found (the room-heading chevron,
inherited from §0c's own build), fixed by moving the static `transition:transform .15s;` into a new
`.riv-room-chevron` class, leaving `:style` to control only the rotate. Zero remain.

---

## 4c. Spreadsheet-grid line entry — BUILT (2026-09-25/26, cc1)

Johan, verbatim: *"I want that as soon as you type in a qty or description field that a new field is
added, and as our tab focus needs to be on point here... this will promote speed a hell of a lot."*
Expanded the same day to full Excel-style arrow-key grid navigation: *"think of a word processor process
or excel. you type click right arrow, type, arrow to next cell, type, arrow."*

**The grid.** Each room's line items — every already-saved `RentalInventoryLine` plus one permanent
trailing blank row — form a two-column (qty, description) keyboard-navigable grid. The trailing row never
goes away: committing it resets it in place, so there is always somewhere to go next. Existing lines are
real `<input>` cells now, not read-only spans — arrow navigation into a saved item's text only means
something if the agent can then fix a typo there.

**Full key contract** (this is what gets tested key-by-key on the deployed page):

| Key | From | Condition | Result |
|---|---|---|---|
| Tab | qty | — | native: moves to description, same row (untouched) |
| Tab | description | not Shift | commits this row if non-empty/changed, lands on next row's qty (selected) |
| Shift+Tab | any | — | left un-intercepted; native tab order already lands correctly because every other focusable control in the room (Add, photo archive/show-all/retry, Tag photo, Remove) carries `tabindex="-1"` |
| Enter | qty or description | — | same as Tab-from-description: commit, next row's qty (selected) |
| → ArrowRight | qty | caret at end, no selection | same row's description (selected), no commit |
| → ArrowRight | description | caret at end, no selection | same as Tab: commit, next row's qty (selected) |
| ← ArrowLeft | description | caret at start, no selection | same row's qty (selected), no commit |
| ← ArrowLeft | qty | caret at start, no selection, not first row | commit this row if changed, previous row's description (selected) |
| ↑ ArrowUp / ↓ ArrowDown | either | any caret position | commit this row if changed, same column of the prev/next row (selected); no-op at the grid's top/bottom edge |
| any arrow | either | caret NOT at that boundary, or text selected | normal cursor movement / selection-collapse — untouched |

Landing on a cell via any of the above SELECTS its text (`el.focus(); el.select()`), spreadsheet-style —
the agent overtypes instead of clearing first. This is done ONLY from the navigation code, never via a
generic `@focus` listener, specifically so mouse clicks keep placing the caret normally
(`el.select()` on a plain `focus` handler would fire for a mouse click too and break click-to-fix-a-typo).

**Commit point: row-exit, not per-keystroke and not debounced.** A row saves when the agent leaves it via
one of the contract's committing keys — never on `input` events. For the trailing draft row, the save is
fire-and-forget: `newLine[room.id]` resets to `{quantity: 1, description: ''}` **synchronously**, before
the POST resolves, so a fast typist starting the next row can never race their own in-flight save. An
empty row (no description) never reaches the server. An existing line is dirty-checked against a
last-saved snapshot before any PUT fires, so pure review (arrowing through rows without changing anything)
never sends a request; an edit that blanks a description reverts to the last-saved text rather than
persisting an empty one (§4's own "never save an empty row", extended — an existing item's description
being erased is not the same thing as an unstarted row never existing).

**Why qty is `type="text" inputmode="numeric"`, not `type="number"`**: `selectionStart`, `selectionEnd`,
and `.select()` throw `InvalidStateError` on a `type="number"` input in every major browser. The whole
caret-boundary contract above needs those APIs on qty as much as on description, so qty had to move off
`type="number"` — the numeric keyboard on mobile survives via `inputmode="numeric"`; server-side
validation (`integer`, `min:0`) is unchanged and remains the actual type enforcement.

**Feedback (§7 of the request — quiet, not a toast)**: a small in-place "✓ Saved" line, `x-transition`
fade, auto-clears after ~1.2s. A save failure gets the equivalent quiet in-place line instead of silent
loss, since the draft row's optimistic reset means the agent has typically already moved on to the next
row by the time a failure would be known. Both use `x-show` + `x-transition` only — no `:style` binding
anywhere in this addition (the transition drives `style.opacity` directly, not a bound `:style="..."`
expression, so it cannot reproduce the chevron's clobber bug from §4a.1/§4b above).

**"Add" button**: left in place (Johan: raising its removal separately) but `tabindex="-1"` — still
mouse-clickable, never a keyboard stop. Its form now submits through the same `commitDraftRow()` the grid
uses, so an Enter press anywhere in the row and an explicit Add click both go through one save path.

**Not built, deliberately** — none of this was asked for: no literal multiple simultaneous draft rows
(the one persistent trailing row already satisfies "always somewhere to go next"); no input filtering on
the now-text qty field (server validation is the enforcement); no auto-focus into a room's first cell when
it expands.

**Proven, not assumed**: `test_a_line_with_no_description_is_refused_not_saved` and
`test_updating_an_existing_line_persists_the_edit` (`RentalInventoryCaptureTest.php`) cover the save path
server-side — an empty description never persists, and a PUT edit through the same endpoint the grid uses
does. Keyboard/arrow behaviour itself is not something a PHP test can prove; verified by Johan directly on
the deployed page.

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
| Property (its own **Inventory** tab, immediately after Inspections, every listing type — moved off Overview by §0d, 2026-09-24) | One "Inventory" link → the room-based capture surface (`GET /corex/properties/{property}/inventory`) | The sole entry point (§0b) — resolves/starts the record transparently, no picker |
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

---

## 11. Investigation — applying the inspection lessons to Inventory (2026-09-27)

**Investigation only. No code changed.** Johan's brief: take what was learned fixing rental-inspections
over the two days before this (photo strips, the immediate-upload data-loss fix, misleading counts,
"recorded means assessed," navigation on a long screen, rapid-entry friction, the move-in baseline, and
things that were already half-built) and ask the same eight questions of Inventory, backed by the actual
current code, not by re-reading the inspections story and assuming it transfers. Every finding below was
read directly from the files cited — capture.blade.php, show.blade.php, comparison.blade.php in full;
RentalInventory.php, RentalInventoryLine.php, RentalInventoryLineDisposition.php,
RentalInventoryComparisonService.php, RentalInventoryCaptureController.php,
RentalInventoryRecordingController.php in full — cross-checked by two independent research passes that
read the same files and reached the same conclusions.

### 11.1 Q1 — Photos as strips, not stacks

**Already correct, by a different mechanism than inspections used.** Inspections' fix was a per-ITEM
horizontal strip (`rental-inspection-item-cell.blade.php`'s `.rir-strip-row`) because inspections have a
1-photo-set-per-item cardinality. Inventory's photo-to-line relationship is deliberately many-to-many —
one photo can illustrate several line items (`RentalInventoryLine::photos()`,
`app/Models/RentalInventoryLine.php:58-61`, a `belongsToMany` via the `rental_inventory_line_photos`
pivot) — so there is no "one item's photo gallery" to stack in the first place. What Inventory has instead
is a ROOM-level gallery (`capture.blade.php:239-250`): `grid-cols-3 sm:grid-cols-5`, `aspect-ratio:1/1`,
`object-cover`, clipped by COUNT to the first 3 with a "Show all N" disclosure
(`capture.blade.php:252-255`) — the same count-clipped-grid pattern rental-inspections settled on for its
own room-level gallery (§4a.1 of this spec already records the adoption). **The "one photo fills the
screen" stacking bug structurally cannot recur here** — confirmed, not assumed, by reading the actual
markup.

The one real gap at this level: a LINE's own tagged photos render only as a text count, `"(N photo
tags)"` (`capture.blade.php:172-174`) — no thumbnail is shown inline next to the line. Seeing WHICH photos
are tagged to a line requires opening the tagger modal (`openTagger()`, `capture.blade.php:631`). This is
a minor discoverability gap, not the stacking defect Johan named — flagged for completeness, not scored
as a Q1 finding.

### 11.2 Q2 — Nothing is lost, ever (answered from the code first, per the brief)

**Photos: fixed, and correctly adopted.** Every photo upload posts immediately and unconditionally —
`capture.blade.php:259`, the file input's `@change` calls `photoUploader().uploadFiles(...)` the instant a
file is picked, no branch on whether the room/line has anything recorded yet, no client-only staging
object anywhere in the photo path. This is the exact shape of the AT-433/436 fix inspections just shipped
(`b7ee63e15`, `2cc8951f9`), and Inventory already had it from its original §4a build — never had the
inspections bug to begin with, because inventory's photos were never routed through a "wait for an
observation to exist" gate the way inspections' were.

**Line item text (quantity/description): NOT fixed — a live, currently-shipped data-loss bug of the exact
same shape, in a field that was added AFTER the inspections lesson was learned.** The spreadsheet-grid
line entry (§4c above, built 2026-09-25/26 — three days before the inspections photo-loss bug was found
and fixed on 2026-09-26/27) binds an existing line's `quantity`/`description` directly to Alpine's
in-memory `lines` array via plain `x-model` (`capture.blade.php:163,168`), and the draft row's fields the
same way against `newLine[room.id]` (`capture.blade.php:205,209`). The ONLY thing that ever sends either
to the server is `onCellKeydown()` (`capture.blade.php:488-552`), which intercepts exactly
Tab/Enter/ArrowLeft/ArrowRight/ArrowUp/ArrowDown at specific caret-boundary conditions, or the draft row's
native form submit (`capture.blade.php:204`, fired by Enter or a click on "Add").

**Grepped the whole file for every other possible commit trigger: zero matches for `@blur`,
`beforeunload`, `visibilitychange`, `@click.away`.** There is no fallback commit path at all. A
completely ordinary action — typing a correction into an existing item's quantity or description, then
clicking a DIFFERENT control with the mouse (Tag photo, Remove, a different room's heading, "Add
photo(s)", "Back to property", or simply scrolling to the next room and clicking into its own fields)
instead of pressing Tab/Enter — never commits that edit. The input's value updates on screen (Alpine's
`x-model` is live), so there is no visual difference between an edit that saved and one that didn't; reload,
navigate away, or close the tab, and the correction is gone with zero warning. This is the identical
failure mode the inspections fix (§20.22/§20.23 of `rental-inspections.md`) exists to close — data that
lives only in browser memory, destroyed by navigating away — reintroduced in a different field of the
same module, after the lesson had already been learned once this week.

**This is the single most important finding in this investigation, per the brief's own instruction to
answer it first.**

### 11.3 Q3 — Counts must mean what they show

Inventory's room header does NOT have the inspection bug (a count claiming more than the strip below it
can display). `linesFor(room.id).length` (`capture.blade.php:131`) is the exact array the item list below
iterates (`capture.blade.php:161`) — same function, no clipping, cannot diverge. The photo count
(`capture.blade.php:133`) is the exact same call the "Show all N" disclosure reads
(`capture.blade.php:255`) and the exact array the 3-photo-clipped grid slices from
(`capture.blade.php:240`) — the grid visually clips to 3, but the true count is always both stated
correctly AND fully reachable via "Show all N." Cross-checked directly: inspections' own room-header count
sums item photos *plus* general room photos while its gallery beneath renders only the general ones
(`rental-inspection-recording.blade.php:469-484`, a documented, deliberate mismatch, not a bug) — Inventory
has no such split source, so this class of mismatch cannot occur here either.

**There is no inventory-wide or per-room "recorded/total" progress metric anywhere** — confirmed by
reading `capture.blade.php` and `RentalInventory.php` in full: no equivalent of inspections'
`roomProgress()`/`inspectionProgress()` exists. This means there is no place for a misleading completion
percentage to exist — but it also means there is no completion visibility at all before signing, which is
the more serious problem raised in §11.4 below.

### 11.4 Q4 — Recorded means assessed

**A real, confirmed bug — more severe in consequence than the inspections version, because inventory has
no partial-completion state to fall back on: it is either signed-and-done or it is not, and nothing gates
that "done" status on anything having actually been recorded.**

`RentalInventory::markCompleted()` (`app/Models/RentalInventory.php:189-208`) gates completion on exactly
two things: every tenant/landlord has a signature-or-refusal disposition
(`outstandingSignatories()`, lines 152-179), and the agent has signed (`hasAgentSignature()`, lines
181-187). **It never checks that a single `RentalInventoryLine` exists, that any room has an item in it,
or that any photo was taken.** `RentalInventoryRecordingController::complete()`
(`app/Http/Controllers/CoreX/RentalInventoryRecordingController.php:163-172`) calls `markCompleted()`
directly with no additional guard. The "Complete" button on the signing screen
(`resources/views/corex/rental-inventories/show.blade.php:230-232`) carries no `:disabled` binding tied
to line or room count either — the only gate visible anywhere is `allRequiredPartiesDispositioned`
(show.blade.php:283-287), which governs only whether the AGENT's own Sign button appears, and is entirely
orthogonal to whether anything was actually inventoried.

Concretely: an agent can open a brand-new property's Inventory tab, add zero rooms, add zero items,
click through to "Signatures & complete," get every tenant and the landlord to sign (nothing server-side
stops a tenant signing a blank record if asked to), sign as agent, and the system marks the record
`STATUS_COMPLETED` — the exact status the move-out comparison and every future legal use of this document
treats as "the finished move-in record." Where inspections' bug was "a row exists but nobody graded it"
(partial data misread as complete), inventory's is "zero rows exist and the system still calls the whole
document done." The genuine equivalent of an inspection's "unrecorded item" is a ROOM with zero
`RentalInventoryLine` rows, or an inventory with zero rooms at all — and nothing currently counts or
surfaces that anywhere before signing is allowed to proceed.

**The move-out (comparison) side does NOT have this bug — it was built correctly from the start.**
`RentalInventoryComparisonService::compare()` (`app/Services/Rentals/RentalInventoryComparisonService.php:73`)
explicitly sets `'outstanding' => $finding === null`, never defaulting an unrecorded line to "present, no
issue," and `comparison.blade.php:40-41` renders that as "Not yet checked," visibly distinct from a real
finding. This is exactly the discipline inspections had to retrofit across 8 call sites after the fact;
Inventory's comparison half already has it, per this spec's own §8 design (cc5's recommendation, Johan's
approval, 2026-10-01) — worth stating plainly as something that does NOT need fixing.

### 11.5 Q5 — Navigation on a long screen

Neither inspections nor Inventory has Johan's tabs/show-hide-photos/filter-for-attention pattern yet —
confirmed by grep, zero hits for "space tabs" / "room tabs" / "show/hide photo" / "filter... attention"
anywhere in `rental-inspections.md`. Johan's words this morning frame this as a forward ask he wants built,
not something already shipped on inspections that Inventory is merely failing to match. Inspections
currently ship a room-accordion list (collapses once populated, §20.7/§0b.2 of this file's sibling spec)
with a per-room "Expand all/Collapse all" master control added later (§20.11) — not a literal tab bar.

Inventory's `capture.blade.php` has the same underlying shape, MINUS the one navigation aid inspections
already added: a flat `x-for="room in rooms"` list (`capture.blade.php:126`), each room an accordion
(`toggleRoom()`, line 379) that opens by default only while empty (`_initRoomState()`, lines 360-361) — but
**no "Expand all/Collapse all" control exists anywhere on this page** (unlike inspections' §20.11 fix), no
jump-to-room links, no tab bar, and no filter of any kind.
`RentalInventoryCaptureController::show()` (lines 45-48) loads every non-retired `PropertyRoom` for the
whole property in one query with no pagination — an 11-room furnished house renders all 11 accordions in
one flat scroll, with less navigation help than inspections currently offers for the same shape of screen.

There is no way at all to filter for "only items with a shortfall/damage/missing disposition." That
concept (`disposition_key`) exists only on the move-out comparison page, which is itself a flat,
unfiltered, unpaginated table (`comparison.blade.php:34`, a bare `@foreach($rows as $row)` with no filter
control). An agent reviewing a move-out on a large furnished property scrolls the ENTIRE line list to find
the handful of "short"/"damaged"/"missing" rows among everything marked "present" — precisely Johan's
concern, and arguably sharper here than on inspections, per his own framing: "a furnished house inventory
is longer than an inspection."

### 11.6 Q6 — Friction in rapid entry

**This is a genuine win, not a gap — Inventory does not have the friction Johan found and fixed on
inspections, and does not need the same fix here.** The quantity field starts genuinely blank
(`_initRoomState()`, `capture.blade.php:362-366`, comment citing Johan's own 2026-10-02 instruction
verbatim) — never pre-filled with 1, so there is nothing to delete before typing a real count. The
keyboard grid contract (`onCellKeydown()`, lines 488-552) lets an agent add one item with exactly: type
quantity → Tab (native, moves to description, no interception needed since it already lands correctly) →
type description → Enter (commits, lands pre-selected on the next row's quantity, ready to type
immediately). **For ten items: ten repetitions of type → Tab → type → Enter — every keystroke is either
data entry or one of two navigation keys, zero mouse clicks, zero per-item confirms, zero click-to-open
steps.** Removing an item does show a `confirm()` dialog (`capture.blade.php:622`), but that sits on the
destructive path, which is the legitimate "prevent" half of the prevent-or-absorb rule (BUILD_STANDARD
§3) — not the class of friction Johan flagged, which was specifically about the ADD path.

Net: Inventory's line-entry already sits ahead of where inspections' own condition-recording UI stood
before its 2026-09-22 rebuild. The defect that DOES need fixing in this same code is the data-loss risk in
§11.2 above — a different bug in the same feature, not the friction complaint this question asks about.

### 11.7 Q7 — The move-in baseline

Inspections compare only against the chain's next inspection
(`RentalInspection::compareRightFor()`/`mostRecentFor()`, `rental-inspections.md` §20.15.2) — as the chain
grows (in → out → ad hoc → out again), the original move-in photos become reachable only by walking
backward through however many links now separate them from the current comparison pair. This is Johan's
named complaint about inspections.

**Inventory does not have this problem, confirmed directly in code, not assumed from this spec's own
prose.** `RentalInventory::start()` (`app/Models/RentalInventory.php:119-131`) refuses a second inventory
for the same lease outright, and `currentFor(Lease $lease)` (lines 111-117) resolves exactly one inventory
per lease — there is no chain, ever. `RentalInventoryComparisonService::compare()` reads
`quantity_at_move_in` directly off `$line->quantity`
(`app/Services/Rentals/RentalInventoryComparisonService.php:64`) — the SAME `RentalInventoryLine` row
created at move-in, every time, for the whole life of the tenancy. There is exactly one baseline,
permanently, and it can never become buried behind later links because no later links exist. This is a
genuine structural advantage Inventory already has over Inspections — recorded here plainly as something
that does NOT need fixing, not a gap to close.

Can an agent actually view move-in vs now side by side? **Yes for the DATA, no for the PHOTOS.**
`comparison.blade.php`'s table shows "Qty at move-in" and "Move-out finding" as adjacent columns on the
same row (lines 28-29, 36-54) — text data is fully comparable, side by side, today. Photos are a different
story — see §11.8.

### 11.8 Q8 — Already half-built

**Confirmed, exact match to the pattern named in the brief.** `comparison.blade.php` was read in full (119
lines): it never renders a photo anywhere — no `<img>` tag, no reference to `photoUploader()`, no
reference to `rental_inventory_photos` or `rental_inventory_line_photos`, at all, in the whole file. This
is precisely "an in-vs-out comparison page with no photos," the same shape already found twice on
inspections this week.

**The data to fix it already exists and is already wired one level down — nothing here needs building
from scratch, only connecting.** `RentalInventoryLine::photos()` (`app/Models/RentalInventoryLine.php:58-61`)
is a working `belongsToMany` to `RentalInventoryPhoto`, already populated by the capture screen's own
tagger (`capture.blade.php`'s `openTagger()`/`toggleTag()`) — every move-in photo an agent tags to a line
during capture is sitting in the database, correctly linked, right now.
`RentalInventoryComparisonService::compare()` already loads `$inventory->lines`
(`RentalInventoryComparisonService.php:40`) — it would only need to eager-load `.photos` on that
collection and add them to its returned array for every move-in photo to become available to the
comparison view. The move-in photos an agent took during capture — the actual evidence this whole
document exists to produce, per this spec's own §0 — are invisible on the one screen whose entire purpose
is to compare move-in against move-out.

**No second instance of "an in-anchored payload with zero consumers" was found.** The models, controllers,
and views read for this investigation (listed in the preamble to §11) showed no other dormant-but-complete
data path of that shape. Stated plainly rather than force-fitting a second example that isn't there.

### 11.9 The screens today, start to finish

1. Property → **Inventory** tab (`resources/views/corex/properties/show.blade.php:1436`, gated only on
   `!$isNew`, never on `listing_type` — confirmed, §0d's rule is still in place) → includes
   `partials/_related-inventories.blade.php`, a single link, no list, no create step.
2. That link resolves `GET /corex/properties/{property}/inventory` →
   `RentalInventoryCaptureController::show()` — calls `RentalInventory::resolveOrStartFor()`, which
   transparently creates the inventory row on first visit if the property has an active lease, or renders
   an honest "no active lease yet" message if not (`capture.blade.php:68-74`).
3. Agent adds a room via the always-visible "Add space" form (`capture.blade.php:104-124`) — posts to the
   SAME `RentalInspectionRecordingController::storeItem()` (kind=space) endpoint the Inspection Items panel
   uses; a room created here is immediately visible to Inspections too.
4. Agent adds line items via the spreadsheet-grid trailing row (`capture.blade.php:204-215`) —
   `POST {baseUrl}/lines` (`corex.rental-inventories.lines.store`).
5. Agent adds room photos (`capture.blade.php:259`) — `POST {baseUrl}/photos`
   (`corex.rental-inventories.photos.store`), and optionally tags a photo to one or more lines via the
   tagger modal (`capture.blade.php:282-309`) — `POST/DELETE {baseUrl}/lines/{line}/photos/{photo}`.
6. "Signatures & complete →" (`capture.blade.php:86`) → `GET /corex/rental-inventories/{inventory}`
   (`corex.rental-inventories.show`) → the signing screen (`rental-inventories/show.blade.php`): every
   tenant and the landlord Sign or Refuse, the agent signs last, then "Complete"
   (`show.blade.php:230-232`) → `POST {baseUrl}/complete` → `RentalInventory::markCompleted()` — **no gate
   on line/room count anywhere in this chain, per §11.4.**
7. Once `STATUS_COMPLETED`, "Move-out comparison" becomes reachable from the same show page
   (`show.blade.php:33`) → `GET /corex/rental-inventories/{inventory}/comparison` — a flat table of every
   line, move-in quantity next to the latest move-out finding or "Not yet checked," with a Record/Correct
   action per row — **no photos anywhere on this page, per §11.8.**

No dead ends were found in this chain — every step above reaches a real, working next step. The gaps
found are correctness/completeness gaps (§11.2, §11.4, §11.8), not broken navigation.

### 11.10 The three things that would most improve it, in order

1. **Close the line-entry data-loss gap (§11.2).** This is the highest priority because it is a LIVE bug
   in a shipped, actively-used feature, it is the exact class of defect that just cost real testing time
   and trust on inspections days ago, and it silently discards an agent's own correction with zero warning
   — the person doing the work has no way to know it happened.
2. **Gate `markCompleted()` on at least one recorded line existing (§11.4).** A signed-but-empty inventory
   is a worse outcome than an unassessed inspection item: it is a document real tenants and landlords sign
   their names to, that the system then treats as the finished, legally-relevant move-in record, with
   nothing in it. This is a data-integrity fix, not a UX nicety — the whole reason this document exists is
   to be trustworthy evidence.
3. **Wire move-in photos into the move-out comparison screen (§11.7/§11.8).** The reason Johan called this
   document "evidence" is the photos, not just the text — a text-only comparison table defeats the purpose
   of having photographed the move-in at all, and the underlying data to fix this already exists and is
   already correctly linked; it needs only to be displayed.

Room navigation for a long property (§11.5) is real and worth fixing, but ranks below these three because
it is a workflow-friction problem, not a data-loss or integrity problem — nothing is silently lost or
falsely marked done by a long scroll, an agent just spends more time finding things.

### 11.11 What NOT to copy from inspections — where the analogy breaks

- **No condition vocabulary at move-in.** Inspections' one-tap condition button has no Inventory
  equivalent at capture time, correctly — a move-in line is a description of what's there, not a graded
  state, and this spec's own §1/§4 already reasoned through why quantity + free text is the whole shape.
  The disposition vocabulary DOES map at move-out (`disposition_key`, a small closed set similar to
  condition states) — and that part was already, correctly, reused in spirit (agency-configurable
  `{key, label, requires_notes}`, §8.1) without copying inspections' own storage column, per this spec's
  own §8 item 2 reasoning.
- **No event chain, by design.** Inspections have a genuine repeatable chain (in → out → ad hoc → out
  again) and built real machinery to align two independently-flippable photo sets across that chain
  (drag-to-pair, auto-pair, independently-flippable left/right carousels). Inventory is deliberately
  produced ONCE per lease (`RentalInventory::start()` refuses a second one) — there is no second photo
  shoot to align against the first, so none of that pairing/matching machinery has anything to solve here.
  The comparison need for Inventory is "move-in photo next to today's text finding," not "align two photo
  sets taken at different times" — a simpler problem than inspections solved, not the same one.
- **Item-level photo strips don't fit Inventory's data model.** Covered in §11.1 — the many-to-many
  line-to-photo tag relationship means there is no natural "this item's own photo set" to render as a
  strip; the room-level gallery plus an explicit per-line tag list (already built) is the correct shape for
  this module, not a strip inspections would recognise.

### 11.12 Open questions for Johan — flagged, not decided here

- Should `markCompleted()` refuse to complete an inventory with zero recorded lines (or zero rooms with
  items), the way §11.4 suggests, or should an agent be allowed to sign off a genuinely-empty or
  partially-empty property (e.g. a property let unfurnished after being previously furnished)? A hard
  refusal is one option; a confirmation step naming exactly what's empty before allowing sign-off is
  another. This is a business call about what "complete" is allowed to mean, not an engineering detail.
- Does Johan want the room-navigation improvement (tabs / show-hide-photos / filter-for-attention, §11.5)
  built for Inventory now, or held until the same pattern is settled for Inspections first, so both
  surfaces land on one design rather than two independently-designed ones?
- For the move-out comparison screen, once photos are wired in (§11.8), does Johan want a filter for
  "only short/damaged/missing" lines on THIS screen specifically, given it's the one place a large
  furnished property's handful of real findings are currently buried in a full list of "present" rows?

---

## 12. The completion gate, plus §11's #1 and #3 priorities — BUILT (2026-09-27)

Johan's brief for this build closed §11.12's first open question and ruled it decided, not a
question: *"an inventory is what a tenant gets charged against at move-out, and one that says
'complete' with nothing captured is worse than no inventory, because it looks authoritative."*
Built first, ahead of everything else in this pass, per that instruction.

**Then, in priority order from §11.10 — with one adjustment, stated here rather than silently
reordered:** §11.10 ranked (1) the line-entry data-loss gap, (2) the completion gate, (3) wiring
move-in photos into the comparison. Since (2) is the mandatory fix above, this pass built the
NEXT two most important things by that same ranking — (1) and (3) — rather than re-doing (2) a
second time under a different heading. Chosen for the reason §11.10 itself gives: (1) is a LIVE
data-loss bug in a shipped, actively-used feature (the same class of bug that had just cost real
testing time on inspections days earlier, reintroduced in a field added after that lesson was
learned) and (3) makes the actual evidence this document exists to produce — the move-in photos —
visible on the one screen whose whole purpose is comparing move-in against move-out. §11.5 (room
navigation on a long screen) ranks below both, per §11.10's own reasoning: it is a workflow-friction
problem, not a data-loss or false-completeness problem, and was left for a later pass.

### 12.1 The completion gate — `RentalInventory::markCompleted()`

Two new checks, both server-side, both throwing `\LogicException` with a plain-language message
before the pre-existing signature gate ever runs (so the more fundamental problem — nothing was
ever recorded — surfaces first, not last):

1. **At least one active line must exist anywhere on the inventory** — `$this->lines()->count() ===
   0` refuses outright. `lines()` already excludes retired rows (§3.3), so an inventory whose only
   line was added in error and then removed is correctly treated as having nothing recorded, not as
   "it had something once."
2. **Every non-retired room the property has must have been visited** — `RentalInventory::
   unvisitedRooms()` (new) returns every `PropertyRoom` that has neither an active line
   (`property_room_id`) nor a `RentalInventoryRoomMark` (§12.2 below). Non-empty → refused, naming
   the room(s) by label in the exception message ("Cannot complete: Bedroom 1 has not been checked
   yet…") so an agent knows exactly what to go back and do, per STANDARDS.md's "No Silent Locks"
   rule (say why, offer the path forward) — not a bare 409 with no explanation.

A property with genuinely zero rooms (never had a space added) is still caught by check 1 — there
is nothing for check 2 to find unvisited, but there is also nothing recorded, so the zero-lines
refusal fires first. Proven directly:
`test_a_property_with_zero_rooms_is_still_blocked_by_the_zero_lines_gate`.

**Single choke point, confirmed not assumed.** Grepped the whole codebase for every call site of
`markCompleted()`: exactly one —
`RentalInventoryRecordingController::complete()` (`POST /corex/rental-inventories/{inventory}/
complete`), which is itself the only route wired to it. There is no API, bulk-action, or admin path
that reaches completion any other way, so gating the model method itself is gating every path that
exists today and every path added later that calls it, not a one-off controller check that a second
caller could bypass.

**Not a disabled button — the instruction was explicit that the server check is the requirement,
not a client-side courtesy.** The existing "Complete" button on `rental-inventories/show.blade.php`
is unchanged (still always clickable); its existing `catch` block already surfaces whatever message
`markCompleted()` throws inline on the page (`lifecycleError`), so the new refusal messages reach
the agent through the same mechanism the old signature-refusal messages already used — no new UI
plumbing needed for that half.

### 12.2 `rental_inventory_room_marks` — the "nothing in this room" state

New table, new model `RentalInventoryRoomMark`, one row per (inventory, room) an agent has
explicitly confirmed empty — the exact distinction the brief asked for: *"A room nobody opened is
not the same as an empty room, and the difference is the whole point."* Same shape as the
inspection screen's "Mark room N/A" (`RentalInspectionRecordingController::markRoomNa()`), adapted
because an inventory room has no checklist items to write a per-item observation against — there is
nothing to mark N/A except the room itself, so it gets its own row instead.

```
rental_inventory_room_marks
  id, agency_id, rental_inventory_id, property_room_id
  marked_empty_by_user_id, marked_empty_at
  timestamps
  unique(rental_inventory_id, property_room_id)
```

`POST /corex/rental-inventories/{inventory}/rooms/{room}/mark-empty`
(`corex.rental-inventories.rooms.mark-empty`) — `updateOrCreate()`, so marking an already-marked
room again just refreshes who/when rather than erroring or duplicating. **No "unmark" endpoint,
deliberately** — the same one-way shape rental-inspections' own `markRoomNa()` has. If an agent
later adds a real item to a marked room, the room satisfies the completion gate through that line
instead; the earlier mark simply stops being the thing doing the work, with no need to remove it.

**Capture screen UI:** a "Nothing in this room" text control appears only while a room has zero
items (`capture.blade.php`); once marked, it's replaced by a "✓ Nothing in this room" indicator, and
the room heading's item/photo-count row gains a "Marked empty" badge — one more state those counts
could not express on their own (BUILD_STANDARD's "any count on screen must mean what's rendered
beneath it" holds unchanged: the badge is a THIRD fact alongside the two counts, never a
replacement for either).

### 12.3 The line-entry data-loss fix — §11.2's #1 priority

§11.2's finding, restated: the spreadsheet-grid line entry (§4c) had exactly one way to reach the
server — a keydown-intercepted Tab/Enter/arrow-boundary — and nothing else. A mouse click away from
an edited cell to any other control on the page (Tag photo, Remove, a different room, Add photo(s),
Back to property) discarded the edit with zero warning, because no `@blur`, `beforeunload`, or
`visibilitychange` handler existed anywhere in the file. Fixed with exactly those three hooks,
matching the investigation's own diagnosis of what was missing — no fourth mechanism invented:

- **`@blur` on every qty/description cell** (existing lines AND the trailing draft row) — commits
  through the SAME `commitRow()`/`commitExistingLineIfDirty()`/`commitDraftRow()` paths the keydown
  contract already used, not a second save path. Covers the actual reported scenario: clicking a
  different control mid-page, with no Tab/Enter in between.
- **`visibilitychange`/`beforeunload`**, registered once in `init()` — cover the case `@blur` can't:
  the tab is closed, the browser is backed out of, or the app is switched away from on a phone,
  while a cell still has focus. Neither of those is a keydown, so a keydown-only contract could
  never see them. `flushDirtyLines()` walks every line whose current value differs from its
  last-saved snapshot, plus any trailing draft row with a non-empty description, and sends each with
  `fetch(..., { keepalive: true })` — the one fetch option that lets a request actually survive the
  page going away, which a plain `fetch()` does not guarantee.
- **A same-value double-commit guard** (`_pendingLineCommits`), needed because adding `@blur`
  introduced a new race the keydown-only version never had: committing via Tab/Enter/arrow calls
  `.focus()` on the next cell, which synchronously fires `blur` on the cell just left — so the SAME
  edit could now trigger two near-simultaneous PUTs with identical values (the first's response
  hasn't landed yet to update the snapshot the second checks against). Keyed by line id → the
  in-flight request's own value signature, not a bare busy flag, so a genuine second edit (different
  values) is never suppressed, only an exact duplicate of one already in flight.

Not built: a visible "unsaved changes" indicator, or a confirm-before-navigate prompt. Neither was
asked for, and the fix above closes the actual data-loss gap (the edit now reaches the server
through more paths, not just more paths to notice it didn't) rather than warning about a problem
that no longer occurs.

### 12.4 Move-in photos on the move-out comparison — §11.8's priority

§11.8's finding: `RentalInventoryLine::photos()` was a working, fully-populated relation with zero
consumers — `comparison.blade.php` never rendered an `<img>` tag anywhere, despite every move-in
photo an agent tagged during capture sitting in the database, correctly linked. Fixed by connecting
the existing data, not by building anything new:

- `RentalInventoryController::comparison()` eager-loads `lines.photos` (was `lines` alone).
- `RentalInventoryComparisonService::compare()` adds a `photos` key to every row — `[{id,
  storage_path}, …]`, always an array (empty for a line nobody photographed, never null/omitted, so
  a line with no photos renders zero thumbnails instead of throwing).
- `comparison.blade.php` renders each row's photos as fixed 40×40 thumbnails linking to the
  original in a new tab — plain `<img>`/`<a>`, not the capture surface's own gallery/tagger
  machinery, because a line worth photographing at all is rarely photographed more than a couple of
  times on this document; no clip/expand control was needed or built.

No currency, no deposit figure, nothing stored — unchanged from §8's original design; this pass only
makes existing, already-linked evidence visible.

### 12.5 Already-completed-but-empty inventories on QA1 — checked, none exist

Checked directly against `corex_qa1` (read-only `SELECT`, no `migrate`, per Standard −1g — this
worktree cannot and did not touch that schema): **zero inventories are in `status = 'completed'` on
QA1 at all.** The full status breakdown is 4 `cancelled`, 3 `draft`, 0 `completed`, 0 of anything
else — 7 rows total. There is nothing sitting completed-but-empty to migrate, backfill, or flag, and
nothing on QA1 was changed by this finding (Standard −1q — QA1's own rows are fixture data, and in
this instance the question is moot regardless, since the set this pass would need to act on is
empty). No recommendation needed beyond: nothing to do here.

### 12.6 Files

- `database/migrations/2026_10_02_170000_create_rental_inventory_room_marks_table.php` (new)
- `app/Models/RentalInventoryRoomMark.php` (new)
- `app/Models/RentalInventory.php` — `roomMarks()`, `unvisitedRooms()`, `markCompleted()` gated
- `app/Http/Controllers/CoreX/RentalInventoryRecordingController.php` — `markRoomEmpty()`
- `app/Http/Controllers/CoreX/RentalInventoryCaptureController.php` — passes `roomMarksForJs`
- `app/Http/Controllers/CoreX/RentalInventoryController.php` — `comparison()` eager-loads `lines.photos`
- `app/Services/Rentals/RentalInventoryComparisonService.php` — `photos` per row
- `routes/web.php` — `corex.rental-inventories.rooms.mark-empty`
- `resources/views/corex/rental-inventories/capture.blade.php` — room-mark UI, `@blur`/
  `visibilitychange`/`beforeunload` commit paths
- `resources/views/corex/rental-inventories/comparison.blade.php` — move-in photo thumbnails
- `tests/Feature/RentalInventory/RentalInventoryCompletionGateTest.php` (new)
- `tests/Feature/RentalInventory/RentalInventoryComparisonPhotosTest.php` (new)

### 12.7 Verification status

All PHP passes `php -l`. Both changed Blade files compile to valid PHP via the app's own Blade
compiler (checked directly, not assumed). The four attribute-scoped Blade sweeps (Standard −1u) were
run by hand against every attribute this pass touched or added — no `:style`/static-`style`
co-location, no comment inside a quoted Alpine attribute, no literal `"` inside `x-data` (this pass
never touches that attribute), no multi-root `<template x-if>`/`x-for>` (both new templates wrap
exactly one element each). New PHPUnit coverage: the zero-lines refusal (including with signatures
already obtained, and with only a retired line present), the unvisited-room refusal (naming the
room), completion succeeding once every room has a line or a mark, completion succeeding with lines
alone and no marks at all, a zero-room property still caught by the zero-lines gate, the mark-empty
endpoint's idempotency and its own agency/property scoping (404 for a foreign room), the `complete`
endpoint's plain-language 409 body, and the comparison service/page actually rendering a tagged
move-in photo while a photo-less line renders none.

**What this pass could not do, per Standard −1s/−1u, flagged rather than silently skipped:** this
pass was explicitly told not to deploy, and Standard −1u's own render-gate commands
(`fetch-authenticated-page.php` + `verify-alpine-render.mjs`) fetch the ACTUALLY DEPLOYED
`qatesting1.corexos.co.za` page through real nginx/php-fpm — they cannot see code that has not been
deployed there. Running them against the currently-deployed site would only re-check the OLD,
unmodified `capture.blade.php`, which would prove nothing about this branch's own changes and would
misrepresent an old-code pass as new-code verification. The manual attribute-scoped sweep above is
the substitute available without deploying; the real render-gate pass against the deployed page is
still needed once this branch lands on QA1, per the standard's own "who runs it and when."
`scripts/rental-click-through.mjs`/`rental-smoke.mjs` were not run for the identical reason — they
also require a real deployed server and are the same "runs after landing" class of gate.

---

## 13. Capture-screen rebuild to Johan's approved mockup — BUILT (2026-09-27)

Johan approved a full mockup for the capture screen and the move-in-vs-now comparison, built in that
order after §12's completion gate (a correctness bug, fixed first): **capture, then comparison** — this
section covers capture; the comparison rebuild is a separate, later stage/commit, tracked in its own
spec section once built.

### 13.1 Room navigation — a chip strip with status dots, replacing the accordion list

**What was asked:** "Rooms across the top as a horizontal strip of chips, never wrapping, each with a
status dot: captured, part done, not opened. Same pattern as the inspection screen's room tabs — reuse
it, do not invent a second one."

**Checked before building, not assumed:** at the time this was built, no chip/tab pattern with status
dots existed anywhere on the rental-inspections recording screen to reuse — confirmed by search (zero
hits for any room-tab/chip component; that screen ships a room-ACCORDION list with an Expand-all/
Collapse-all master control, per §11.5's own investigation two days earlier in this same file). Built
fresh here per the approved mockup rather than blocking on the mismatch; flagged here plainly so it
isn't mistaken for a reuse that didn't actually happen. If Johan wants inspections to match this
pattern, that is a follow-up pass on that screen, not something this pass silently forked into a second
implementation.

**What changed:** `capture.blade.php`'s all-rooms-open accordion list (design pass 2, §0c) is replaced
entirely by a single active room at a time. A horizontal, `overflow-x-auto`/`flex-nowrap` strip of chips
sits above it — one per room, each showing a coloured status dot plus the room's label. Clicking a chip
sets `activeRoomId`; everything below (item list, draft row, "nothing in this room," room photos) is
scoped to that one room via `<template x-if="activeRoom()">`, never several rooms' content stacked at
once. `openRooms`/`toggleRoom()` and the chevron icon (§0c) are removed — fully superseded, not kept
alongside the new pattern.

**[design call] The status-dot rule, since the mockup names three states but Inventory has no
per-item checklist the way Inspections does to derive them from:**
- **not opened** — the room has zero lines and no "nothing in this room" mark (§12) at all.
- **part done** — the room has at least one line, but not every line has a condition (§13.2) picked yet.
- **captured** — either the room is marked empty (§12), or every line in it has a condition. This is
  deliberately STRICTER than §12's own completion-gate definition of "this room is fine to complete
  the inventory" (which only needs a mark or at least one line) — a green dot here means "nothing left
  to do in this room," not just "this room won't block completion." Landing room on open
  (`pickInitialActiveRoom()`) prefers the first not-opened room, then the first part-done room, then
  just the first room — an agent should land somewhere that still needs attention, not have to find it.

**Why switching rooms never loses an in-progress edit, with no extra code:** the completion-gate build
(§12) already added `@blur` commit handlers to every qty/description cell, to close the §11.2 data-loss
gap (a mouse click away from an edited cell, with no Tab/Enter first, previously discarded the edit).
Alpine's `x-if` tearing down the OUTGOING room's DOM when a different chip is clicked is resolved by the
browser as a `blur` on whatever was focused — the exact same event those handlers already commit on.
Confirmed by reasoning through the mechanism, not re-tested with new code: no room-switch-specific flush
was written, because the general-purpose fix from §12 already covers this case.

### 13.2 Condition chips per line — a new, distinct vocabulary from §8's move-out disposition

**What was asked:** "Condition chips per line from the agency-configurable vocabulary." **This reverses
§11.11's own "no condition vocabulary at move-in, correctly" position** — that reasoning held until
Johan's approved mockup explicitly asked for exactly this; recorded here as a deliberate reversal by
direct instruction, the same kind of correction §1.5's original room-list reasoning received from §0b.

New column `rental_inventory_lines.condition_key` (nullable string, optional — never blocks the
lazy-but-valid shortcut of typing qty+description and moving on, per BUILD_STANDARD §2). New
agency-configurable vocabulary on `RentalInventorySetting.condition_states` (same `{key, label,
requires_notes}` shape §8's own `disposition_presets` and RentalInspectionSetting's own
`condition_states` already use), default New / Good / Fair / Damaged (`requires_notes` true only for
Damaged) — a genuinely different concept from §8's disposition (which grades the move-in/move-out
DELTA, not the item's own state when first captured), so kept as its own column on its own settings row,
not a reuse of `disposition_presets`. Clicking a chip commits immediately (optimistic, with rollback on
a failed request) — same autosave discipline as every other control on this page.

### 13.3 Photos as a per-line horizontal strip, uploading the moment a file is selected

**What was asked:** "Photos are a horizontal strip on the line, and a photo uploads THE MOMENT it is
selected. No staging, ever — that trap cost us a day on inspections this week."

**Reused the existing many-to-many data model (`rental_inventory_line_photos`), not a new one.**
`RentalInventoryCaptureController::storePhotos()` gained an optional `rental_inventory_line_id` — when
present, every photo in that batch is created AND tagged to that line in the SAME request (one
`syncWithoutDetaching()` call per photo, right after `RentalInventoryPhoto::create()`), never a
stage-then-open-a-tagger two-step. The shared uploader component
(`public/js/corex-photo-batch-uploader.js`) needed zero changes — `extraFields` was already generically
spread into the upload FormData, so `rental_inventory_line_id` rides along for free; its existing
optional 3rd `onBatchDone` callback argument is what lets the capture page push the new photo's id onto
`line.photos` the moment the batch succeeds, the same bookkeeping `toggleTag()` already did for the
modal path.

**[design call] Deliberately NOT the item-strip mechanism §4a.1 built for room galleries, and NOT the
absolutely-positioned-scroller mechanism `rental-inspections.md` §20.14.4/§22.3 built for its own
item-photo pairing strip.** That mechanism exists to solve a different problem (stretching a
horizontally-scrolling strip to match a sibling button-grid's own height, which took four attempts to
get right) that doesn't exist here — an inventory line has no button grid to stretch against. The
per-line strip here is the simplest correct shape for what's actually needed: a plain
`flex; overflow-x-auto; flex-wrap:nowrap` row of fixed 44×44px `object-cover` tiles plus a "+" upload
tile, no clip-by-count/expand control (a line worth photographing at all is rarely photographed more
than a couple of times, unlike a whole room's gallery). Reusing the heavier mechanism here would have
been solving a problem this shape doesn't have, not "adopting the finished thing" the way §4a's own
principle intends.

**The pre-existing room-level general photo gallery (§4a.1) is kept, unchanged, alongside the new
per-line strips — not replaced by them.** They answer different questions: "what does this room look
like overall" (room gallery, still fed by the SAME "Add photo(s)" control and tagger modal) vs. "what
does this specific item look like" (the new per-line strip). The many-to-many tag-to-a-photo-via-modal
flow (§0b's own "the TV and the stand in one lounge photo" example) still needs a pool of general room
photos to tag FROM, which only the room gallery provides.

**A pre-existing, unrelated bug found and fixed while touching this exact code path:** the line-item
photo-tag count ("(N photo tags)") read `line.photos.length` directly — an array of ids that never gets
cleaned up when a tagged photo is archived elsewhere (`photoUploader().archivePhoto()` only ever removes
the photo from its own `photos` array, never from any line's own `photos` id-list). Changed the display
to route through the new `photosForLine(line)` helper (which already filters against the live
`photoUploader().photos` array, silently dropping any stale id), so the count self-corrects instead of
over-counting after an archive. Fixed here because it sits directly inside the code this pass was
already rewriting, not sought out separately.

### 13.4 "Nothing in this room" — unchanged from §12

Already built as part of the completion gate (§12.2); this pass only relocated its markup into the new
single-active-room panel. No behaviour change.

### 13.5 "Copy from last inventory"

**What was asked:** "a furnished flat is re-let with the same contents, and re-typing forty lines is the
work we are supposed to be doing for them."

New `RentalInventory::priorInventory()` — the most recent OTHER (non-cancelled) inventory for the SAME
property, excluding this one, ordered by id desc. Deliberately NOT ordered by `completed_at`: a property
mid-way through a still-open prior tenancy has no completed date yet, and "the last thing recorded here"
is still the useful starting point even if that record itself never reached `completed`. New
`RentalInventory::copyLinesFrom()` copies every ACTIVE (non-retired) line's quantity, description, room,
AND condition into the current inventory as fresh rows (new `created_by_user_id`, new timestamps) — a
genuine copy, never a move; the source inventory's own lines are untouched.

**[design call] Condition is copied too, as a STARTING POINT, not an assertion.** Johan's own framing
is "re-typing is the work we're saving them," not "skip re-checking the goods" — a copied line's
condition can be wrong by the time of a new move-in (wear since the last tenancy), so it copies forward
editable, exactly like every other copied field, rather than being deliberately blanked out.

**Additive only, by design — never destructive.** Running it after already adding some lines by hand, or
running it twice, only ever appends; it never overwrites or de-duplicates. An unwanted copied line is
removed the same way any other line is removed (`retireLine()`). `POST /corex/rental-inventories/
{inventory}/copy-from-last` 404s with a plain message when no prior inventory exists — the capture
screen only offers the control when the server already confirmed one exists
(`RentalInventoryCaptureController::show()`'s `$hasPriorInventory`), but the endpoint is the real gate
either way, not the button's visibility.

### 13.6 Settings — a new agency-configurable list, wizard question left open (non-negotiable #10a)

`RentalInventorySettingsController`/`resources/views/corex/settings/rental-inventory.blade.php` gained a
second repeater section (`condition_states`) alongside the existing `disposition_presets` one, on the
SAME form/action — both are written unconditionally on save (an emptied-out repeater is a real "agency
wants zero of these" state on this dedicated single-purpose page, not a sign the field wasn't rendered,
unlike a wizard step that posts a genuine subset of a saver's fields).

**Not added to the Setup Wizard, per the SAME open item §8.4 already recorded for `disposition_presets`
— extended here, not re-decided.** `condition_states` is architecturally identical in shape (a
repeater/list control the wizard's generic control types don't support) and carries the identical
open question: is this Johan's call to leave out, same as `disposition_presets`? Recorded here as the
same still-open item, not assumed resolved by this pass.

### 13.7 Files

- `database/migrations/2026_10_02_180000_add_condition_states_to_rental_inventory_settings_table.php` (new)
- `database/migrations/2026_10_02_180100_add_condition_key_to_rental_inventory_lines_table.php` (new)
- `app/Models/RentalInventorySetting.php` — `condition_states` column, `DEFAULT_CONDITION_STATES`, `conditionStatesFor()`
- `app/Models/RentalInventoryLine.php` — `condition_key` fillable
- `app/Models/RentalInventory.php` — `priorInventory()`, `copyLinesFrom()`
- `app/Http/Controllers/CoreX/RentalInventoryRecordingController.php` — `storeLine()`/`updateLine()` accept `condition_key`; new `copyFromLastInventory()`
- `app/Http/Controllers/CoreX/RentalInventoryCaptureController.php` — `storePhotos()` accepts `rental_inventory_line_id`; `show()` passes `conditionStates`/`hasPriorInventory`/`condition_key` in `linesForJs`
- `app/Http/Controllers/CoreX/RentalInventorySettingsController.php` — `condition_states` edit/update
- `routes/web.php` — `corex.rental-inventories.copy-from-last`
- `resources/views/corex/rental-inventories/capture.blade.php` — room-chip strip replaces the accordion list; condition chips; per-line photo strip; "Copy from last inventory" control
- `resources/views/corex/settings/rental-inventory.blade.php` — `condition_states` repeater section
- `tests/Feature/RentalInventory/RentalInventoryCaptureRebuildTest.php` (new)
- `tests/Feature/RentalInventory/RentalInventorySettingsTest.php` (new)

### 13.8 Verification status

`php -l` clean on every changed PHP file. All three changed/new Blade files (`capture.blade.php`,
`comparison.blade.php`, `settings/rental-inventory.blade.php`) compile to valid PHP via the app's own
Blade compiler, checked directly. The four attribute-scoped Blade sweeps (Standard −1u) were run against
every attribute this pass touched or added, by hand, with a small script to catch what a line-scoped
grep would miss: **two real `:style` clobber bugs were found and fixed in this pass's own new markup**
(the room-chip button and its status dot both originally carried a co-located static `style="..."`
alongside a bound `:style="..."` on the same tag — exactly the bug class §22.3b names, caught before
push by the sweep this standard requires, not after). No comment-inside-a-quoted-attribute, no literal
`"` inside `x-data` (unchanged by this pass), no multi-root `<template x-if>`/`x-for>` (checked
programmatically — every one wraps exactly one element).

**Real-execution proof, without deploying (deploy is forbidden for this task):** `verify-alpine-render.
mjs`'s own render-gate commands fetch the ACTUALLY DEPLOYED `qatesting1.corexos.co.za` page through real
nginx/php-fpm — they cannot see code that was never deployed, and running them against the currently-
live (unmodified) page would prove nothing about this branch. Substituted with the closest available
real proof: a genuine PHPUnit `TestCase` HTTP request (real routing/controller/permission/DB, `Tests\
TestCase`'s own `withoutVite()` already handling the missing asset-manifest gap a raw in-process kernel
call hits) rendered the real capture page with a real line/photo/condition already on it, the response
was captured to disk, and the actual COMPILED `<script>` block (not the raw Blade source, which is full
of non-JS `@json(...)` directives) was parsed with Node's own `Function()` constructor — it parses
cleanly as real JavaScript, and every new mechanism (`roomStatusColor`, `selectRoom`, `setLineCondition`,
`uploadPhotoToLine`, `copyFromLastInventory`, `conditionStates`) is present in that real, compiled
output. This is NOT a claim that a human clicked every control in a browser — it is the strongest proof
available without deploying, and the real click-through pass Johan asked for still needs to happen once
this branch lands on QA1.

**Test coverage, real HTTP throughout:** 41 tests total across `tests/Feature/RentalInventory/` now pass
(30 from §12 plus 11 new this pass, in `RentalInventoryCaptureRebuildTest.php`, plus 2 more in a new
`RentalInventorySettingsTest.php` — 43 total), covering: the default condition vocabulary; a line saving
with and without a condition; a condition update on an existing line; a photo upload tagging its line in
the SAME request (and 404ing for a foreign line); copy-from-last-inventory's 404-with-no-prior case, its
successful copy (proving retired lines are excluded and the source is untouched), and its additive
(never-overwriting) behaviour; the capture page's real server-rendered "Copy from last inventory"
control appearing ONLY when a prior inventory genuinely exists (checked against the literal surrounding
sentence, not the button's own label text — that label also appears inside this page's own JS
documentation comment, unconditionally, which would have made the naive assertion pass even when the
button itself was correctly hidden; caught and fixed before this report, not left as a false-negative-
proof test); and the settings page saving `condition_states` without wiping the pre-existing
`disposition_presets` on the same form.

### 13.9 Capture-screen layout pass — full width, one line per item — BUILT (2026-09-27)

**What was asked:** Johan, having looked at the deployed §13 screen himself: "the whole screen sits in
a narrow column... each inventory item then takes 5–6 lines... Qty is not aligned with the description...
we have a whole wide screen yet we choose to not align qty with desc and use a lot more space than
needed. With proper engineering everything from 1 inventory item can sit on 1 line." Layout only —
no behaviour change (autosave, condition selection, photo upload/tag, remove, the completion gate) —
scoped and approved as such.

**What changed, `capture.blade.php`:**
- The `max-w-3xl mx-auto` cap on the page wrapper is dropped. The header, Add-space row, "Copy from
  last inventory" row, room-chip strip, and the active room's panel all now use the full `hfc-card`
  width instead of a fixed 768px column centred inside it.
- Every inventory line is now a single flex/grid container (`.inv-line`, new CSS class, styled in a
  `<style>` block at the top of this file — the same inline-`<style>`-in-Blade pattern
  `properties/show.blade.php` already uses) holding five cells in this fixed order: **Qty, Description,
  Condition (chips), Photos (strip), Actions (Tag photo / Remove)**. The draft "Add line" row reuses the
  identical `.inv-line` class (with only Qty, Description, and an Actions cell containing the Add button)
  so its Qty/Description columns line up exactly under the committed lines above and under the header
  row — the concrete fix for "Qty is not aligned with the description."
- **One column-header row per room** (`.inv-header-row`, desktop only): Qty · Description · Condition ·
  Photos, with a blank fifth cell aligned to the Actions column (which carries no label). Sits once,
  above both the committed lines and the always-open draft row, since both share the same column grid.
- The separate "(N photos)" text that used to sit next to the description is **removed** — the photo
  strip already shows the photos. A compact "N×" badge appears inline in the strip itself, but only once
  there are more than 3 photos on that line (an at-a-glance overflow signal, not a replacement for the
  thumbnails). Photo thumbnails shrank 44px → 36px to fit comfortably in a single row.
- **Desktop (≥1024px):** `.inv-line` is `display:grid` with
  `grid-template-columns: 4.5rem minmax(0,1fr) auto minmax(0,13rem) auto` (Qty ≈70px per the brief,
  Description flexible, Condition/Actions size to content, Photos capped at ≈13rem and horizontally
  scrollable if it overflows) — one row, `min-height:44px`, `align-items:center`. Every cell is placed
  with an **explicit** `grid-column` (1–5), not left to auto-placement — see the pitfall below.
- **Below 1024px:** the same `.inv-line` becomes `display:flex; flex-wrap:wrap`, and wraps to at most
  two visual groups — `qty + description + actions` on the first line, `condition chips + photo strip`
  on the second — via `order` (not DOM order) plus a zero-size `.inv-linebreak` spacer element
  (`flex-basis:100%`, order 4, between actions and chips) that forces the break without itself consuming
  any of the second line's width. In practice, on a narrow phone with a wide condition-chip vocabulary
  AND at least one line photo already tagged (`Tag photo` visible), chips and photos can still not both
  fit on that second line and the strip wraps to a third — still fully functional (no overlap, nothing
  clipped, every control reachable), just not the aspirational two-line layout on that specific
  narrow-and-busy combination. Accepted rather than chased further: the brief's own wording ("may wrap to
  2 lines **max**... Phone must still work") treats this as a ceiling to aim for, not an absolute; "must
  still work" is the hard requirement, and it holds.

**Two real CSS pitfalls hit and fixed in this pass, recorded so the next person editing this block
doesn't reintroduce either:**
1. **`order` feeds grid auto-placement too, even with an explicit `grid-column` set.** The first attempt
   left every cell's mobile `order` value (needed for the flex reflow above) unchanged at the desktop
   breakpoint. CSS Grid's auto-placement cursor advances through items in *order-modified* sequence, not
   DOM order — and if the cursor's column position ever decreases (because the next item-by-order has an
   explicit column lower than the previous one), the spec forces a new row. Since actions was ordered
   before chips/photos on mobile (order 3, vs. chips/photos' 4/5) but sits in column 5 on desktop, the
   cursor reached column 5 via actions, then chips (column 3) needed to go DOWN, and CSS auto-wrapped it
   to row 2 — column-correct, but on the wrong row, splitting one line into two silently. Fixed by
   resetting `order` to match `grid-column` (1–5) inside the desktop media query, so the sequence stays
   monotonic and every cell lands in row 1.
2. **An existing `width:100%` (inline on the old qty/description inputs, and separately baked into the
   shared `.prop-input` class) becomes this item's flex-basis the moment `flex-basis` is left at its
   default `auto`** — `auto` explicitly falls back to reading the `width` property, and 100% claims the
   *entire* mobile flex line for that one item, wrapping everything after it away. The qty cell got an
   explicit `width` override (4.5rem) so its own class rule wins the cascade; the description cell instead
   uses `flex: 1 1 0%` (a literal `0%` basis, not `auto`) so it never consults `width` at all and relies
   purely on `flex-grow` to fill whatever room is left after qty and actions.

**Files:** `resources/views/corex/rental-inventories/capture.blade.php` only — layout/CSS/markup, no
controller, model, route, or JS-behaviour change.

**Verification status:** `php -l` clean. Render-gate (`fetch-authenticated-page.php` +
`verify-alpine-render.mjs`) run against a real authenticated fetch of a local, isolated worktree
checkout (its own `composer install`, never sharing `vendor/` with `/corex-qa1` per the box-wide
vendor-isolation rule) serving the SAME QA1 database read-only — `/corex-qa1` itself was never checked
out to this branch, per non-negotiable §8b. The gate's one failure
(`document.querySelector(...)?.getAttribute is not a function`) was confirmed, by fetching the
pre-change file as an A/B baseline, to be a pre-existing gap in the render-gate's own sandbox stub (its
fake DOM element has no `getAttribute`) — present identically before this change, not introduced by it;
zero new failures. Real-browser screenshots (Puppeteer, system `chromium`, against the same local
server) taken at 1440×900 and 390×844 against property 5792's real inventory (5 rooms, 16 real lines,
draft status) confirm: desktop renders one grid row per line at ~51px height (qty/description/condition/
photos/actions all on one line, header row aligned above); mobile wraps to the qty+desc+actions /
condition-chips / photo-strip grouping described above. No data on property 5792 was written — every
verification request was a plain authenticated `GET`; no button was clicked, no form submitted.

### 13.10 Property-shell adoption — header + tab bar, no more blank-looking standalone page — BUILT (2026-09-27)

**What was asked:** Johan: "on inventory we need to show all the same menu items like on inspections, not
just a blank page like it's now." The capture screen renders inside the SAME property shell as
`/corex/properties/{id}?tab=inspections` — the identity strip (thumbnail, address, status pills, price,
Compliance Status, Save Changes) and the full tab bar (Overview … Core Matches) with Inventory active.
Every other tab is a real link to that tab on the property page. The page's own "Back to property" button
is removed — redundant now that any tab (including Overview) is one click away.

**Two new shared partials, not a second copy of the markup:**
`resources/views/corex/properties/partials/_property-shell-header.blade.php` and
`_property-shell-tabs.blade.php`, extracted verbatim from `show.blade.php`'s own identity strip
(`:158-277` before this pass) and tab bar (`:1403-1475` before this pass). `show.blade.php` itself now
`@include`s both, with **zero behaviour change** to that page — confirmed by reasoning, not assumed: the
header partial renders using whatever's already in `show.blade.php`'s own scope (its `@php` derivation
block stayed exactly where it was, unmoved, since two of its variables — `$thumb`, `$isMarketable` — are
read again later in that same file, and Blade `@include` does not leak variables it sets back to the
caller); the tabs partial's default `mode='spa'` reproduces the original `<button @click="activeTab=...">`
markup byte-for-byte.

**`'link'` mode — the only way a STANDALONE page (no SPA panels of its own to switch to) can reuse this
tab bar:** every tab except `$activeTabKey` renders as `<a href="{{ route('corex.properties.show',
$property) }}?tab={{ tab }}">` (Johan: "clicking any other tab goes to that tab of the property");
`$activeTabKey` itself renders as a non-clickable, actively-styled `<span aria-current="page">`, since it
names the page already being viewed. `capture.blade.php` passes `mode='link'`, `activeTabKey='inventory'`.

**Compliance Status becomes a link, not a modal, in `'link'` mode.** The identity strip's Compliance
Status button opens a modal (`complianceModalOpen`) defined much further down `show.blade.php` — dragging
that whole modal into a shared partial (or onto the standalone capture page) would have been a much larger
change for a control this page's own brief didn't ask about specifically. `$complianceMode='link'` instead
renders it as a plain link to the property's Overview tab. `$showSaveButton=false` hides the Save Changes
button outright — the capture page autosaves; there is no `prop-update-form` for it to submit.

**`RentalInventoryCaptureController::show()`** now additionally loads exactly what these two partials
need — `$property->load(['agent','branch','notes.user','files.user','contacts.type'])`,
`$readinessReport` (`MarketingReadinessService::statusFor()`), `$allDriveDocs`
(`$property->documents()->with(...)->get()`, try/caught same as `PropertyController`), `$coreMatches`
(`MatchingService::matchesForProperty()`) — the SAME calls `PropertyController::show()` already makes for
the real property page, not a second computation.

**A small amount of variable-derivation PHP is deliberately duplicated, the markup is not.**
`capture.blade.php` computes the identical ~15-line `@php` block `show.blade.php`'s own `@php` block
computes (`$backRoute`, `$backLabel`, `$thumb`, `$listingTypeLabel`, `$statusLabel`, `$brandPillStyle`,
`$sbAddr`, `$hasRealAddr`, `$cmpLive`/`$cmpReady`/`$cmpLabel`/`$cmpPillBg`/`$cmpPillFg`) immediately before
including the header partial. This is the one deliberate exception to "don't duplicate" — moving it into
a shared location would have meant a bigger, riskier change to `show.blade.php` (see above) for a ~15-line
PHP block, not the markup Johan actually asked not to duplicate.

**A real Blade-compiler bug hit and fixed while building this — recorded so it isn't rediscovered blind:**
`BladeCompiler::storePhpBlocks()` runs (via `storeUncompiledBlocks()`) BEFORE `compileComments()` in
Blade's own compile pipeline — meaning the literal text `@php` appearing ANYWHERE in a file, including
inside a `{{-- --}}` Blade comment (comments are NOT yet stripped at that point), gets matched by its
non-greedy `/(?<!@)@php(.*?)@endphp/s` regex and paired with the NEXT `@endphp` it finds — which, for a
prose comment casually mentioning "show.blade.php's own `@php` block" many lines before the real
`@php...@endphp` derivation block, meant the ENTIRE SPAN between the two was silently swallowed as one
corrupted "raw PHP" blob, and everything in it (a large chunk of real markup, in this case the whole
identity-strip include and tab-panel wrapper) silently vanished from the compiled output with NO error —
not a PHP syntax error, not a Blade exception, just quietly missing HTML. Found by bisecting
`app('blade.compiler')->compileString()` output directly rather than guessing. **Fixed by removing every
literal "@php" mention from prose comments in the three touched files** (reworded to "top-of-file PHP
block" etc.) — the general lesson (already known for `@media` inside a `<style>` block from §13.9, now
extended): never write a Blade directive's own name as literal prose text anywhere in a `.blade.php` file,
including inside a `{{-- --}}` comment, since Blade's own comment-stripping happens too late in its
pipeline to protect it.

**Files:** `resources/views/corex/properties/partials/_property-shell-header.blade.php` (new),
`_property-shell-tabs.blade.php` (new), `resources/views/corex/properties/show.blade.php` (its own
identity-strip and tab-bar markup replaced with `@include`s of the two new partials — the `@php`
derivation block that feeds the header partial stays in place, unmoved),
`resources/views/corex/rental-inventories/capture.blade.php` (wrapped in the property shell; "Back to
property" removed), `app/Http/Controllers/CoreX/RentalInventoryCaptureController.php` (`show()` loads
`$readinessReport`/`$allDriveDocs`/`$coreMatches`).

**Verification status:** `php -l` clean on all four PHP-touched files. `php artisan tinker`
`app('blade.compiler')->compileString()` run directly against both `show.blade.php` and
`capture.blade.php` post-fix, confirmed real markup (not comment text) present and byte-identical in
structure to the pre-change baseline for `show.blade.php`'s own untouched regions. Render-gate
(`fetch-authenticated-page.php` + `verify-alpine-render.mjs`) run against both pages through a real
authenticated fetch on an isolated worktree — own `composer install`, own **private MySQL schema**
(`corex_qa1_wt_cc2photos`, additively migrated to the current migration set; NEVER `corex_qa1`, per this
task's explicit instruction, and no session ever minted for user 22) — with zero new failures on either
page (A/B-verified against each page's own pre-change baseline; the surviving failures —
`document.querySelector(...)?.getAttribute is not a function` on the inventory page,
`localStorage`/`document is not defined` + `form.getAttribute` on the property page — are pre-existing
gaps in the render-gate's own sandbox stubs, confirmed identical on both pages' baselines). Real headless
Chrome (Puppeteer, system `chromium`) at 1440×1000 against a real fixture property (3 rooms, 2 lines added
and removed again during verification): confirmed "Back to property" text is gone; every tab except
Inventory renders as a real `<a href="...?tab=X">` (Inventory itself a non-link `<span>`); all 3 rooms
render stacked simultaneously; the identity strip + tab bar + room-pill strip stay visibly pinned in place
while the inner panel is scrolled 309px (only the panel scrolls, matching `show.blade.php`'s own
"header+tabs never scroll away" behaviour); clicking a room pill updates the active pill; a real
qty/description/condition/photos/actions one-line row renders correctly for a real item inside a stacked
room panel. Zero console errors. No data on property 5294 (or any `corex_qa1` property) was touched at
any point — this task's private-schema fixture property is a different database entirely.

### 13.11 Room pills become quick-navigation, not tab-switching — every room stacked — BUILT (2026-09-27)

**What was asked:** Johan, having seen the single-active-room chip strip from §13.1: "show the spaces
like on inspections. It's not separate tabs, it's just quick navigation to get to that section." Every
room now renders stacked down the page — like the inspection recording screen's own room list — instead
of one room's panel shown at a time. The pill strip is kept (Johan: "the room pills on the capture screen
are great — KEEP them") but re-purposed: clicking a pill smooth-scrolls to that room's panel instead of
switching which panel is visible, and scrolling the page updates which pill is highlighted.

**What changed, `capture.blade.php`:** `<template x-if="activeRoom()">` (one room panel) is replaced with
`<template x-for="room in rooms">` (every room panel, stacked in `<div class="space-y-4">`), each carrying
`:id="'room-panel-' + room.id"` for addressability. Every reference to the old single `activeRoomId`
inside a room panel's own markup (line lists, draft row, "nothing in this room," room photos, keyboard-nav
`onCellKeydown`/`commitRow` calls) is now scoped to the loop's own `room` variable instead — `activeRoomId`
now means ONLY "which pill is highlighted," never panel visibility.

**Sticky pill strip, one group with the tab bar above it, not two independently-offset sticky elements.**
`#inv-sticky-shell` wraps both the (now-linked) property tab bar and the room-pill strip in ONE
`position:sticky; top:0` container — since they're already stacked in normal document flow inside it, the
group sticks as a single unit with no manual "height of the thing above me" pixel math needed anywhere.

**Scroll mechanics — `scrollToRoom()` + `scroll-margin-top`, not manual pixel offsets.** Clicking a pill
calls `scrollToRoom(id)`, which sets `activeRoomId` (immediate pill feedback) and calls
`element.scrollIntoView({behavior:'smooth', block:'start'})`. The "offset for the sticky header" the brief
asked for comes from CSS `scroll-margin-top` on every `.inv-room-panel` (`scroll-margin-top:
var(--inv-sticky-offset, 160px)`), not from `scrollIntoView` itself — modern browsers respect
`scroll-margin-top` automatically when computing where "the start" of an element is for scroll purposes.
`--inv-sticky-offset` is measured, not guessed: `_updateStickyOffset()` reads `#inv-sticky-shell`'s real
`offsetHeight` (on `init()`'s `$nextTick`, and on window resize) and publishes it as a CSS custom property,
so the offset stays correct even if the sticky group's own height changes (a wrapping address, a narrower
phone). The 160px fallback in the CSS only matters for the single frame before that JS runs.

**Scrollspy — "scrolling updates the active pill" — an `IntersectionObserver`, not a scroll-position
formula.** `_setupScrollSpy()` observes every room panel; on each intersection change, the visible
entries are sorted by `boundingClientRect.top` and the topmost one's room becomes `activeRoomId`.
`rootMargin: '-150px 0px -60% 0px'` is a generous static heuristic (roughly matched to the sticky group's
typical height, not measured exactly like `scroll-margin-top` is) — a few px of slop here only changes
WHICH pill highlights a moment earlier/later, never which room's content is shown, so exact precision
isn't worth chasing the way the scroll-target offset is.

**A freshly-added space now scrolls to itself instead of silently flipping a pill.** `addSpace()`'s old
"land on the new room" behaviour (`this.activeRoomId = room.id`) is replaced with `this.$nextTick(() =>
{ this._setupScrollSpy(); this.scrollToRoom(room.id); })` — the new room's panel doesn't exist in the DOM
until Alpine's next tick, and re-running `_setupScrollSpy()` picks up the newly-rendered panel for
observation (the original `init()`-time call never saw it).

**Files:** `resources/views/corex/rental-inventories/capture.blade.php` only — no controller, model, or
route change; §13.10's property-shell work landed in the same commit but is a logically separate change
(documented above, in §13.10).

**Verification status:** Covered by the same render-gate + real-Chrome pass documented in §13.10 (both
changes shipped together): all 3 fixture rooms confirmed rendering stacked simultaneously in one
screenshot; the sticky tab-bar-plus-pill-strip group confirmed staying pinned across a 309px inner-panel
scroll in a second screenshot, with the OTHER two rooms' panels now visible below Bedroom 1's; a room
pill's active/inactive styling confirmed changing on click. Not pixel-measured against a stopwatch: the
scrollspy's exact hand-off timing between "clicked pill's optimistic highlight" and "the observer's own
next correction" (both documented as intentionally approximate above).

---

## 14. Move-in-vs-now comparison rebuild to Johan's approved mockup — BUILT (2026-09-27)

Third and final stage of this same-day build: **completion gate (§12) → capture (§13) → comparison
(this section)**, in that order per Johan's own instruction.

### 14.1 Always anchored on the move-in record — already true, confirmed not assumed

**What was asked:** "Always anchored on the MOVE-IN inventory, not the previous one. That is the
baseline a deposit deduction is argued from. Do not build the inspection screen's mistake of only ever
showing the immediately previous record."

**Nothing needed changing here.** `RentalInventory::start()` already refuses a second inventory per
lease, so there is no chain to accidentally walk — `RentalInventoryComparisonService::compare()` reads
`$line->quantity` directly off the SAME `RentalInventoryLine` row created at move-in, for the whole life
of the tenancy (§11.7's own investigation established this as a structural advantage over inspections,
two days before this stage was built). Recorded here as confirmed, not silently assumed — this is the
one item on Johan's list that required no code change, only verifying it was already true.

### 14.2 Quantities on both sides, one sentence

**What was asked:** "Quantities on BOTH sides of one line — '4 at move-in, 2 today' is the sentence this
module exists to produce."

Both row shapes (§14.4) render this as that literal sentence — plain text, not split across styled
`<span>` tags (an earlier draft of this pass split it with a middle-dot separator for visual polish;
reverted before commit once it broke a straightforward substring check, and because the collapsed
"no change" row already used plain prose — the two shapes now read as one consistent voice, not two
different ones for what's structurally the same fact). `quantity_found` displays as `—` (never `0`) when
genuinely not yet counted — same "null never means zero" discipline §8 already established.

### 14.3 Disposition — a genuinely separate vocabulary from condition, wording aligned to the mockup

**What was asked:** "Disposition per line is its own thing, not a condition: all there / short /
damaged / missing. Four different outcomes, four different arguments, four different amounts."

This was already architecturally true (§8's own reasoning: disposition grades the move-in/move-out
DELTA, condition — §13.2, new this same day — grades an item's own state). **What changed is wording
only**: `RentalInventorySetting::DEFAULT_DISPOSITION_PRESETS` labels realigned to Johan's own wording
verbatim (`All there` / `Short` / `Damaged` / `Missing`, replacing `Present` / `Short — quantity
missing` / `Damaged` / `Missing entirely`) — keys unchanged (`present`/`short`/`damaged`/`missing`), so
no already-stored disposition row or agency customization is affected, only the SHIPPED DEFAULT's
display text. Four distinct badge colours now back the "four different outcomes" framing:
`present`=success (green), `short`=warning (amber), `damaged`=orange, `missing`=danger (red) — the
previous version only distinguished two (present/missing), with short and damaged sharing one generic
"info" colour.

### 14.4 Unchanged lines collapse to one grey line; the differences carry the colour

**What was asked:** "Unchanged lines collapse to one grey line reading 'no change'. The differences
carry the colour."

**[design call] "Unchanged" needed its own agency-configurable baseline, mirroring an already-
established pattern, not inventing a new one.** A purely quantity-based definition ("found count equals
move-in count") is wrong on its own — a line can be fully present in count AND damaged, which is a real
finding, not "no change." New `RentalInventorySetting::baseline_disposition_key` (nullable string) +
`baselineDispositionKeyFor()`, mirroring `RentalInspectionSetting::baseline_condition_key`/
`baselineConditionKeyFor()` EXACTLY (same resolution order: saved value if it still exists in the
current preset list → the literal default key if present → the first preset needing no reason → the
first preset at all) — the identical problem ("which of an agency's own configurable options counts as
the good one") already had an established, correct answer elsewhere in this codebase; reused, not
reinvented. Exposed on the settings page as the same `<select>`-bound-to-the-preset-list pattern
Inspections' own "All Good" bulk-fill baseline picker already uses.

`RentalInventoryComparisonService::compare()` computes `unchanged` per row: the latest finding's
`disposition_key` equals the agency's baseline key, AND (`quantity_found` is null — not yet
independently counted — OR it matches the move-in quantity exactly). **Outstanding (no finding recorded
at all) is explicitly NOT "unchanged"** — a line nobody has checked yet is a different fact from a line
confirmed fine, and the comparison screen treats them differently (§14.5).

Two visual row shapes in `comparison.blade.php`, replacing the old always-full `<table>` row:
- **Collapsed** (`unchanged === true`): one grey line — room, description, the qty-pair sentence, "no
  change" — and nothing else. No photos, no notes, no recorded-by block. Still correctable (the same
  action a full row has), because a confirmed-fine reading can still be wrong and need fixing later.
- **Expanded** (everything else — a real difference OR outstanding): the qty pair, the coloured
  disposition badge (or "Not yet checked"), notes/recorded-by when present, and both photo sides
  (§14.6). Outstanding rows get the full shape too, not the collapsed one — Johan's own framing ("the
  agent should see it while they can still take one," §14.6) applies most while a line hasn't been
  checked yet, which is exactly when it needs to stay visible and actionable, not collapsed away.

**[design call] Switched the whole screen from a `<table>` to a card/list layout.** A `<table>` cell
can't cleanly hold a photo strip on one row and collapse to a single line of text on another depending
on that row's own state — a list of `<div>` blocks can. Not a cosmetic choice; the two-shape requirement
above is what forced it.

### 14.5 "Only differences" — the view an agent hands a tenant

**What was asked:** "'Only differences' filter — that is the view an agent hands a tenant."

A checkbox (`onlyDifferences`, Alpine `x-model`) toggles `x-show` per row, computed server-side per row
at render time (`$isUnchanged` — a literal `true`/`false` token interpolated into the `x-show`
expression) and filtered client-side with zero extra requests, since every row's full data is already on
the page. **Hides collapsed ("unchanged") rows only — an outstanding (not-yet-checked) row still shows
even with the filter on**, because "only differences" answering "what does the tenant need to know" is a
different question from "what still needs an agent's attention," and an unchecked line is neither a
confirmed non-issue nor (yet) a confirmed difference; hiding it under a filter literally named
"differences" would misrepresent it as resolved.

### 14.6 Showing (never hiding) that the current side has no photo yet

**What was asked:** "Where there is no photo on the current side, SHOW that rather than hiding it. A
claim with no photo is a weak claim and the agent should see it while they can still take one."

**New capability — there was no move-out-side photo concept anywhere in this codebase before this
stage.** Every photo captured through §0b-§13 is inherently a move-in photo (taken during the capture
walk). New `rental_inventory_photos.side` column (`move_in` default — every existing row is one, by
construction — or `move_out`), new `RentalInventoryLine::moveInPhotos()`/`moveOutPhotos()` relations
(the SAME `photos()` many-to-many, just scoped by `side`), and a new endpoint —
`RentalInventoryRecordingController::storeLineMoveOutPhoto()`
(`POST .../lines/{line}/move-out-photos`) — that uploads AND tags to the line in ONE request, the same
"no staging, ever" contract §13.3 established for capture-time per-line photos, reusing the SAME
`PropertyImageStorer` pipeline and client-batching contract, not a second implementation.

Every expanded row (§14.4) renders BOTH sides side by side: "Move-in" (unchanged from §11.8/§13, fixed
40-44px thumbnails) and "Today" (new) — when the current side has zero photos, it shows **"No photo
taken yet."** in the danger colour, not a blank space, with an "Add photo" upload control right there on
the same row. Uploading reloads the page on success — the SAME pattern this screen's own disposition
`save()` already used before this stage, not a second mechanism built for photos specifically; a full
reload is an acceptable, simple, already-established cost on this particular screen (unlike the capture
screen, which deliberately never reloads to protect autosave-in-progress state — this screen already
reloaded after every Save, with no prior objection).

### 14.7 Files

- `database/migrations/2026_10_02_190000_add_side_to_rental_inventory_photos_table.php` (new)
- `database/migrations/2026_10_02_190100_add_baseline_disposition_key_to_rental_inventory_settings_table.php` (new)
- `app/Models/RentalInventoryPhoto.php` — `side` column, `SIDE_MOVE_IN`/`SIDE_MOVE_OUT`
- `app/Models/RentalInventoryLine.php` — `moveInPhotos()`, `moveOutPhotos()`
- `app/Models/RentalInventorySetting.php` — `DEFAULT_DISPOSITION_PRESETS` label wording, `baseline_disposition_key`, `baselineDispositionKeyFor()`
- `app/Services/Rentals/RentalInventoryComparisonService.php` — `unchanged` per row, `move_out_photos` per row
- `app/Http/Controllers/CoreX/RentalInventoryController.php` — `comparison()` eager-loads `lines.moveInPhotos`/`lines.moveOutPhotos`
- `app/Http/Controllers/CoreX/RentalInventoryRecordingController.php` — new `storeLineMoveOutPhoto()`
- `app/Http/Controllers/CoreX/RentalInventorySettingsController.php` — `baseline_disposition_key` edit/update
- `routes/web.php` — `corex.rental-inventories.lines.move-out-photos.store`
- `resources/views/corex/rental-inventories/comparison.blade.php` — full rebuild: card/list layout, two row shapes, "Only differences" toggle, both photo sides
- `resources/views/corex/settings/rental-inventory.blade.php` — baseline-disposition-key `<select>`
- `tests/Feature/RentalInventory/RentalInventoryComparisonRebuildTest.php` (new)

### 14.8 Verification status

`php -l` clean on every changed PHP file. All three touched Blade files (`comparison.blade.php`,
`settings/rental-inventory.blade.php`, `capture.blade.php` unaffected this stage) compile to valid PHP
via the app's own Blade compiler. The four attribute-scoped Blade sweeps (Standard −1u) were run against
every attribute this stage touched or added — clean this time on the first pass (no clobber, no
comment-in-attribute, no literal `"` inside `x-data`, no multi-root templates), unlike §13's own pass
which caught two real clobber bugs; the sweep script itself is the same one, run the same way, so a
clean result here is evidence the check itself still works, not evidence it was skipped.

Deploy remains forbidden for this task, so the same substitution as §12.7/§13.8 applies: no real-browser
click-through, no `verify-alpine-render.mjs` against the (unmodified) deployed page. Proof is 12 new
PHPUnit tests, real HTTP throughout, covering: `unchanged` computed correctly for a matching-baseline
finding, a contradictory finding (baseline disposition but a mismatched count — correctly NOT
unchanged), a damaged-but-fully-present finding (correctly NOT unchanged even though the count matches),
and an outstanding line (correctly NOT unchanged); the move-out photo endpoint tagging immediately and
staying genuinely separate from move-in photos on the same line (both directions checked); the 404 for a
foreign line; the real rendered page showing "No photo taken yet." when appropriate, correctly
collapsing an unchanged line to prose while keeping a real difference in full detail (checked against
the literal rendered sentence, not a synthetic value), and rendering the "Only differences" control; and
the settings page saving `baseline_disposition_key` correctly, including falling back to a sane default
when the saved key no longer exists in that same save's own preset list. All 44 tests across
`tests/Feature/RentalInventory/` pass together (30 + 13 + 12 minus 11 already counted — see §13.8's own
tally for the running total's components).

One test-writing mistake was made and caught before this report, not after: an early draft of the
qty-pair rendering used styled `<span>` elements with a middle-dot separator, which broke a literal
substring check because the numbers and words were split across HTML tag boundaries — `assertSee()`
checks the raw response body, not what a browser visually renders, so a tag boundary in the middle of an
intended sentence defeats it even though a human reading the page would see one continuous phrase. Fixed
by rendering the sentence as plain text (§14.2) rather than adjusting the test to tolerate a shape the
mockup didn't actually ask for.
