# Rental Inspections

**Status:** Spec — not yet built. NO CODE has been written against this spec.
**Date:** 2026-09-17
**Author:** cc5, with the data-model core designed by cc3 (current-state investigation, folded in per §3)
**Pillar:** Property (`Property`) — reads from and writes back to the property record; also touches
Contact (owner, tenant) and User (the inspecting agent).
**Sequencing:** Johan's ruling — core matches / pipeline → **inspections (this spec)** → work orders.
This spec is written before Work Orders (`.ai/specs/rental-work-orders.md`, not yet written) precisely
because Johan ruled work orders are evidence *feeding into* an out-inspection, not a separate concern —
see §3.4.

---

## 0. Johan's rulings (verbatim in substance, final — not open for redesign)

These are the constraints this spec is built to satisfy. Anywhere this spec makes a design call not
directly stated below, it is marked **[cc5 design call]** so it's visibly separable from Johan's own
words.

1. SA standard: owner + agent inspect before any tenancy begins. Then agent + tenant do the
   **in-inspection**. The tenant then has a **7-day window** to report further faults — inside 7 days
   is allowed as of right; outside 7 days is still allowed, but the agent makes a call on what to do
   with it. **Both are recorded either way.**
2. The **out-inspection is not a clean comparison against the in-inspection** — previously-reported
   issues sit *on* the out-inspection. Reason, verbatim: "this covers the 2 years later no one
   remembers that a damp wall was reported and never repaired so owner cannot blame tenant for damages
   as owner neglected to fix it back then."
3. **Photos are required on everything**, good or bad. "no photos equals lots of fights." Agents take
   enormous numbers of inspection photos; CoreX must cater for that volume as evidence.
4. **Multiple agents can inspect the same property and it appends, never overwrites.** Example: two
   agents record conflicting condition for the same item, both kept, the merged inspection **shows the
   discrepancy** for the agents to resolve between themselves, all date-stamped and tracked as
   evidence. **An inspection cannot be completed while a discrepancy is unresolved** — "they have to
   resolve it."
5. Inspections are **deliberate events**, not random acts.
6. The agent adds **inspection spaces** per property, and they **differ from the advertised room
   list**. Water and electricity meters are handled the same way as spaces.
7. **Links, and 7 days for the tenant to sign** the out-inspection. If unsigned in time, the agent can
   sign on the tenant's behalf with a strict note: "tenant refused to sign out inspection."
8. **Offline is critical** — "no internet is a reality... an offline way to do this is critical as
   well." Flagged explicitly, not quietly solved, in §7.
9. **Two sides**: the property inspection (this spec), and **inventory lists** — "if we get inventory
   lists right properly it will expand to sales properties as well. we do a lot of fully furnished
   property sales as well." Inventory is meant to be built on the property from day one, shared by
   sales and rentals. Scoping decision on this in §8.
10. Mobile: Andre builds that side once ready — this spec defines the server-side data model and web
    surface; the mobile client build is explicitly out of scope here.
11. CoreX is a no-delete system; FICA dictates five years' retention after the business relationship
    ends.
12. Every threshold above (7-day fault window, 7-day signing window) is an **agency setting** with a
    sensible default, never hardcoded.
13. (2026-09-17 follow-up ruling, given directly to the conductor while this spec was in progress)
    "geyser bursting is an event that will carry an inspection / photos of the damages / work
    conducted at some stage. and that is what is needed on an out inspection when lets say the ceiling
    is badly repaired by a contractor, and all the evidence sits on that event that happened... the
    law gives us in inspection, and out inspection. but having the comprehensive log of what damages
    were reported when and what was actioned is the evidence assisting the out inspection to be more
    fair." This confirms: Inspections and Work Orders are two separate objects, linked, not fused
    (cc5's original recommendation, now Johan-confirmed) — **but the link must be strong enough that
    an out-inspection can pull the full history of a SPACE**, not just of a property. See §3.4.

---

## 1. Goal

Give an agent a structured, evidence-first way to record a rental property's condition at in-
inspection, out-inspection, and any point in between (a tenant's post-move-in fault report, an ad-hoc
mid-tenancy check), such that:

- Every observation any agent or tenant makes about any item or space is preserved forever, never
  overwritten, never deleted — "current condition" is always derived by reading history, never a
  mutable field.
- Two agents recording conflicting conditions for the same item is caught, surfaced, and must be
  resolved before the inspection can complete.
- An out-inspection automatically carries forward every previously-reported issue for the property, so
  a dispute two years later can be settled by the record, not by memory.
- Every space/item is photographed, every tenant signature is captured (or its refusal is recorded),
  and every one of this is bound by agency-configurable time windows, never a hardcoded number.

This EXPANDS the existing **Rental Images** tab on a property (`.ai/specs/rental-images.md`) — it is
not a new tab, not a new module surface. The tab is where an inspection happens. What changes
underneath is the data model: the tab's current flat `rental_images_json` (one date + one flat photo
array per section) cannot represent multi-agent append-only evidence with discrepancy resolution, so
the tab's In Inspection / Out Inspection sections are rebuilt on the relational model in §2, while the
tab remains the single UI home for doing inspection work on a property. See §4.

A second, new **Rental Inspections** list screen is added at the agency level (§5) — this is not a
contradiction of "expand the tab, don't add a module": the tab is where an inspection *happens*, the
list screen is where inspections are *tracked and searched across every property in the agency*, which
Johan's own concept explicitly asked for ("a notification system and tracking way to keep track of
[...] if the work has been completed or not" — same requirement he gave for work orders, and it
applies equally to inspections) and which the CRUD/list-screen design floor (§5, BUILD_STANDARD.md
§1a-§1d) requires regardless.

---

## 2. Pillar connections

- **Property** — every inspection, item, and observation is anchored to a `Property` (`property_id`).
  Reads the property's `listing_type`/`spaces_json` for context; writes the inspection record back.
- **Contact** — the tenant (observation author when the tenant self-reports a fault; signer of the
  out-inspection) and, at least by reference, the owner/landlord (recipient of inspection-completion
  notifications, per the shared notification infrastructure noted in §3.4 and the prior work-orders
  investigation this spec follows).
- **Agent** (`User`) — every observation, item, and signature-on-behalf-of records which agent
  performed it. `agency_id` scopes everything (§5).
- **Deal** — no direct connection today. If Work Orders (future spec) later ties into a maintenance
  cost against a deal/expense ledger, that connection belongs in that spec, not this one.

---

## 3. Data model

### 3.1 The core shape — Property → Item → Observation, Inspection is the event

This is cc3's design (relayed via the conductor, folded in verbatim in substance — see the
conductor's message for cc3's full original wording, preserved as the authoritative source of this
model's reasoning).

> **The hierarchy:** Property → Item → Observation, with Inspection as the event an observation
> happens inside.
>
> An **Item** is the actual thing being watched over time — a whole space ("Bedroom 1") or something
> finer inside it ("Bedroom 1 — built-in cupboard"). An item itself has no condition field — it's just
> an address.
>
> An **Observation** is a single, immutable fact: this item, seen by this person, during this
> inspection, at this time, in this condition, with notes and photos attached directly to the
> observation (never the item). An observation is never edited and never deleted, not even
> soft-deleted.
>
> **Current condition is a query, never a column.** "Current condition" = the most recent observation
> for that item that isn't sitting inside an unresolved discrepancy.
>
> **A discrepancy** is two or more observations for the *same item, within the same inspection* that
> disagree on condition — scoped to "the same inspection" deliberately, so ordinary wear-and-tear
> between check-in and check-out never registers as a discrepancy. A discrepancy has its own row
> (detected time, nullable resolved time/resolver/note); resolving it records a NEW observation and
> stamps which observation is now accepted as current. Losing observations are never touched.

This directly satisfies Johan's rulings §0.1 (fault window), §0.2 (carry-forward), §0.4 (append, never
overwrite, discrepancy blocks completion).

### 3.2 Tables

```
rental_inspection_items
  id
  agency_id            -- BelongsToAgency
  property_id           -- FK properties, cascade on property delete? NO — see §3.3
  kind                  -- enum: 'space' | 'meter'
  label                 -- e.g. "Bedroom 1", "Water meter"
  space_type            -- nullable, references config('property-spaces.all_space_types') key,
                         --   for consistency with the marketing spaces list WITHOUT being
                         --   constrained to it (Johan: inspection spaces differ from the
                         --   advertised room list — free-text label always wins display)
  is_retired            -- bool, default false. NOT deleted_at — see §3.3 for why.
  created_by_user_id
  created_at, updated_at

rental_inspections
  id
  agency_id
  property_id
  type                  -- enum: 'in' | 'out' | 'ad_hoc'
  status                -- enum: 'draft' | 'in_progress' | 'awaiting_signature' | 'completed' | 'cancelled'
  scheduled_for          -- nullable date the inspection is/was booked for (§0.5, "deliberate events")
  fault_report_deadline_at   -- set at creation for type='in': created_at + agency's fault window (§3.5)
  signing_deadline_at        -- set when status moves to 'awaiting_signature': + agency's signing window
  completed_at
  cancelled_at, cancelled_by_user_id, cancel_reason   -- see §3.3, only legal pre-observation
  created_by_user_id
  created_at, updated_at, deleted_at   -- standard soft-delete, gated per §3.3

rental_inspection_observations
  id
  agency_id
  rental_inspection_id
  rental_inspection_item_id
  observed_by_user_id       -- nullable if the observer is the tenant (see observed_by_contact_id)
  observed_by_contact_id    -- nullable, set when the tenant is the one reporting (post-move-in fault)
  condition                 -- enum: 'good' | 'fair' | 'damaged' | 'not_working' | 'missing' | 'other'
  notes                     -- text, required if condition != 'good' (agreed with the "no photos
                             --   equals lots of fights" spirit — a bad rating needs a reason on record)
  source                    -- enum: 'in_inspection' | 'tenant_fault_report' | 'out_inspection' | 'ad_hoc'
  reported_outside_window   -- bool, only meaningful for source='tenant_fault_report'
  window_decision           -- nullable enum: 'accepted' | 'rejected' | 'deferred' — agent's call per §0.1
  window_decision_note      -- nullable text
  window_decision_by_user_id, window_decision_at   -- nullable
  client_idempotency_key    -- uuid, unique. See §7 (offline sync safety)
  created_at                -- the ONLY timestamp. No updated_at (nothing to update), NO deleted_at
                             --   at all — not soft-deleted, not deletable, full stop. Stricter than
                             --   non-negotiable #1's "soft delete only" floor, by design: this is
                             --   FICA/legal evidence, and even a soft-deleted row is still a row that
                             --   could theoretically be excluded from a query by mistake. There is no
                             --   "delete" action anywhere in the UI for an observation.

rental_inspection_photos
  id
  rental_inspection_observation_id
  storage_path
  uploaded_by_user_id
  client_idempotency_key    -- uuid, unique. Mirrors the existing MobilePropertyController pattern
                             --   (client_upload_id) — see §7.
  file_size_bytes
  created_at                -- immutable, no deleted_at, same reasoning as observations above

rental_inspection_discrepancies
  id
  agency_id
  rental_inspection_id
  rental_inspection_item_id
  detected_at
  resolved_at, resolved_by_user_id, resolution_note   -- all nullable until resolved
  accepted_observation_id   -- nullable FK to rental_inspection_observations, set on resolution

rental_inspection_discrepancy_observations   -- pivot: which observations are in conflict
  discrepancy_id, observation_id

rental_inspection_signatures
  id
  rental_inspection_id
  signer_role            -- enum: 'tenant' | 'agent_on_behalf' | 'landlord' (landlord signature is
                          --   not required by Johan's ruling but the enum leaves room for a future
                          --   ruling without a schema change — [cc5 design call])
  signer_contact_id       -- nullable (set for 'tenant'/'landlord')
  signed_by_user_id       -- nullable, set for 'agent_on_behalf' — WHO pressed the button
  signature_path          -- storage path, file-on-disk pattern (§3.6), never inline base64 in the DB
  refused_note             -- required, non-null, when signer_role='agent_on_behalf': Johan's exact
                            --   required wording is "tenant refused to sign out inspection" — the
                            --   field is free text so the agent can add detail, but the UI pre-fills
                            --   and validates that exact phrase is present verbatim, since Johan
                            --   specified it as the strict note, not just "some reason"
  signed_at
  created_at

rental_inspection_settings   -- one row per agency, §3.5
  id, agency_id (unique)
  fault_report_window_days          -- default 7
  out_inspection_signing_window_days -- default 7
  created_at, updated_at
```

### 3.3 Why `rental_inspection_items` is retired, not deleted — and why `rental_inspections` is
soft-deletable only before it has evidence

`rental_inspection_observations` and `rental_inspection_photos` carry **no `deleted_at` column at
all** — stricter than non-negotiable #1's "soft delete only" floor, deliberately. These rows are
FICA-relevant legal evidence (§0.11); nothing about them is ever hidden, corrected in place, or
removed. If a photo was uploaded to the wrong item, the fix is a NEW observation with a note
explaining the correction, not an edit or delete of the wrong one — the wrong one stays on the record
as a true historical fact about what was captured and when, exactly as cc3's design states.

`rental_inspection_items` uses `is_retired` (a boolean flag) instead of `deleted_at`, for the same
integrity reason: an item cannot become invisible to history-reading queries once any observation
references it — a `deleted_at`-scoped global query would silently drop retired items from "pull every
observation for this item across every inspection," which is exactly the query an out-inspection
carry-forward and a work-order's future space-history lookup (§3.4) depend on. `is_retired` only
affects "can a NEW observation be recorded against this item" (no), never "does this item's history
still show up" (always yes).

`rental_inspections` itself (the event record) DOES get the standard `deleted_at`/restore floor
(non-negotiable #1, BUILD_STANDARD §1a) — but **only while it has zero observations recorded against
it**. Once even one observation exists, the inspection cannot be archived/deleted through the normal
CRUD path; it can only be `cancelled` (a status, not a delete) if it was started in error, and
cancellation never removes the observations already on it. **[cc5 design call]**: this reconciles the
full-CRUD-floor requirement with the evidence-integrity requirement — an inspection created by mistake
with nothing recorded on it yet is ordinary CRUD noise and should be archivable like anything else; an
inspection with real observations on it is evidence and the floor's own "archive, don't destroy"
principle already implies it shouldn't vanish either.

### 3.4 The Work Orders link (Johan's ruling §0.13)

This spec does not build Work Orders — that is a separate, not-yet-written spec
(`.ai/specs/rental-work-orders.md`), sequenced after this one per Johan's own ordering. What THIS spec
must do is make sure the link is strong enough when that spec is written:

A future `rental_work_orders` table should carry `rental_inspection_item_id` (nullable — not every
work order originates from an inspection, per cc5's original geyser-on-a-random-Tuesday argument,
which Johan confirmed) rather than only `property_id`. Because `rental_inspection_item_id` is stable
across every inspection ever done on that property (§3.1 — items are per-property, reused across
inspections, not recreated each time), a work order linked this way lets an out-inspection pull "every
work order ever raised against THIS item" (e.g. every repair ever logged against "Ceiling — lounge"),
not just "every work order on this property" — satisfying Johan's explicit requirement that the link
support **space-level**, not just property-level, history. This spec defines `rental_inspection_items`
now specifically so that future FK exists to point at.

### 3.5 Agency settings — the 7-day windows

Following the established pattern (`RentalApplicationApprovalEmailSetting`,
`RentalApplicationQualifyingSetting` — one agency-scoped settings row, a `public const DEFAULT_...`
next to each column, a static `...For(?int $agencyId)` lookup that returns the constant for a null/
absent-row agency and never writes on read):

```php
class RentalInspectionSetting extends Model
{
    use BelongsToAgency;

    public const DEFAULT_FAULT_REPORT_WINDOW_DAYS = 7;
    public const DEFAULT_SIGNING_WINDOW_DAYS = 7;

    public static function faultReportWindowDaysFor(?int $agencyId): int { /* ... */ }
    public static function signingWindowDaysFor(?int $agencyId): int { /* ... */ }
}
```

**Per CLAUDE.md non-negotiable #10a — this reaches the Setup Wizard in the same build prompt as the
settings screen, not later.** This spec deliberately does NOT repeat the already-flagged gap where the
rental-applications settings cluster never reached `config/agency-onboarding-copy.php` (a pre-existing,
separately-tracked issue — `.ai/specs/rental-applications.md:7053`). Both inspection windows get an
explain/affects entry in the wizard at build time, wired to `RentalInspectionSetting`'s saver, guarded
per §6.1 of `.ai/specs/agency-onboarding-setup.md` (`$request->has()` on any boolean, though these two
fields are plain integers so the main risk is a blank-posted-as-zero footgun — validate `min:1`).

### 3.6 Photos and signatures — which existing infrastructure is reused, and how

- **Photo storage**: reuses `PropertyImageStorer` (2560px max edge / JPEG 85) exactly as the existing
  Rental Images tab and marketing gallery already do — no new image pipeline. Files land under
  `storage/app/public/properties/{id}/inspections/`. See §7.2 for the storage-scale concern this
  raises — flagged, not solved here.
- **Signatures**: reuses the *lightweight* pattern already proven outside DocuPerfect (compliance
  policy-acknowledgement signing: `resources/views/compliance/policy-ack/sign.blade.php` +
  `app/Http/Controllers/Compliance/RmcpAcknowledgementController.php`) — canvas capture via the
  existing bundled `signature_pad` library, base64 PNG posted, decoded server-side to a file on disk,
  only the **path** stored in the DB row, never the raw base64 inline. This is NOT the full DocuPerfect
  e-sign flow (`SignatureService`, `SignatureRequest`, `SignatureTemplate`) — that flow requires a
  Template/CdsDraft document ceremony that doesn't fit "tenant signs one out-inspection form," and
  reusing it would be materially heavier than this feature needs.

---

## 4. UI placement & navigation — expanding the Rental Images tab

The existing **Rental Images** tab (`resources/views/corex/properties/show.blade.php`, guarded on
`listing_type === 'rental'`) is rebuilt in place, not replaced with a new tab:

- **In Inspection** and **Out Inspection** sections change from "date + flat photo array" to: a list
  of the property's `rental_inspection_items` (spaces + meters), each expandable to show its latest
  observation (condition, notes, photos, who/when), with an "Add observation" action per item. The
  carry-forward requirement (§0.2) means the **Out Inspection** section additionally shows every
  unresolved-or-recently-resolved issue from the item's full history, not just what's recorded during
  this specific out-inspection event.
- **+ Add space/meter** — replaces "+ Add section." Creates a new `rental_inspection_items` row (not a
  JSON blob entry), with the space-type picker seeded from `config('property-spaces.all_space_types')`
  for convenience but accepting a free-text label per §0.6.
- A discrepancy, when one exists on an item, renders inline as a visible banner on that item ("Retha
  said X on 12 Sep, Maggie said Y on 12 Sep — unresolved") with a "Resolve" action, matching §0.4's
  requirement that the discrepancy is visible for the agents to work out between themselves.
- The **+ Add custom section** freeform-gallery behaviour from the original spec (e.g. "Garden
  handover") maps onto an `ad_hoc`-type inspection against one or more items — no separate mechanism
  is needed; an ad-hoc inspection is the same object, just not gated by the in/out lifecycle or the
  fault/signing windows.

Both the tab guard and every route stay under the existing `corex.properties.` group pattern,
extended, not duplicated.

---

## 5. The Rental Inspections list screen — CRUD/list-screen floor (BUILD_STANDARD §1a-§1d)

New screen, new nav entry, same day as the build (non-negotiable #2). Route group:
`corex.rental-inspections.*`, sidebar entry under the existing Rentals section.

- **Search** (named fields): property address, tenant name, agent name.
- **Sort**: inspection date (default: most recent first), property address, status, type. Default
  stated explicitly per §1b: most-recent-first.
- **Filter**: status (draft/in_progress/awaiting_signature/completed/cancelled — status is itself the
  minimum-required filter per §1b), type (in/out/ad_hoc), date range (minimum per §1b), and a
  domain-specific filter this feature genuinely needs: **"has unresolved discrepancy"** — this is the
  tracking mechanism Johan asked for ("a [...] tracking way to keep track of [...] if the work has
  been completed or not," restated from the work-orders conversation but equally true here).
- **Pagination**: standard page size, consistent with other CoreX list screens.
- **Empty state**: distinct copy for "no inspections yet on this agency" vs "no results for this
  filter," per §1b.
- **Scoping**: `rental_inspections`/`rental_inspection_items`/etc. all `use BelongsToAgency` +
  `AgencyScope` (hard agency boundary, never crossed); OWN/BRANCH visibility layered on top via the
  same role/permission pattern already used by the rental-applications list (agent sees own, branch
  manager sees branch, agency admin sees all). Direct-URL access to another agency's inspection by ID
  is blocked at the query layer (404 via the global scope), not hidden by omitting a link — same
  standard as everywhere else in CoreX.

---

## 6. Permissions

New permission keys in `config/corex-permissions.php`, following the existing `rental_applications.*`
naming convention:
- `rental_inspections.view`
- `rental_inspections.create` (covers recording observations, adding items, starting an inspection)
- `rental_inspections.resolve_discrepancy` — deliberately separate from `.create`, since resolving a
  discrepancy is a judgment call between two agents, arguably warranting a tighter grant than "anyone
  who can log an observation" **[cc5 design call, flagged for Johan]** — if this distinction is
  unwanted, collapsing it into `.create` is a one-line change at build time.
- `rental_inspections.sign_on_behalf` — the "agent signs for the tenant" action, gated separately
  since it's the one action that overrides a party's own consent, matching the seriousness CoreX
  already gives similarly consequential actions elsewhere.

---

## 7. Offline & conflict handling — explicitly flagged, not solved here

Johan's ruling (§0.8) is unambiguous that this is required, not optional. This section states the real
problem honestly rather than assuming it away.

### 7.1 What the append-only design gets right for free

Because observations are never overwritten and multiple observations for the same item are an
*expected, designed-for* outcome (they're how a discrepancy gets detected at all), the core data —
new observations arriving from two agents who were both offline — does **not** collide in the classic
sense. There is no "last write wins" hazard for observation content itself: both writes simply become
two rows. This is a real strength of the model, not an accident.

### 7.2 What is still a genuine, unsolved problem

- **Duplicate item creation.** Items are created ad-hoc by an agent, per §0.6 ("differ from the
  advertised room list"). If two agents are both offline at the same property and each independently
  adds "Bedroom 3" (or one types "Main bedroom" and the other "Bedroom 1 — main"), those become TWO
  DIFFERENT item rows once both sync — and because discrepancy detection depends on two observations
  pointing at the literal same `rental_inspection_item_id`, this silently defeats the entire
  discrepancy mechanism for that item: two agents' conflicting condition claims on what is physically
  the same room never get compared, because the system doesn't know they're the same room.

  **[cc5 recommendation, not yet Johan-approved]**: require that a property's item list (spaces +
  meters) is defined **online**, in the office, before an inspection goes into the field — i.e. item
  *creation* requires connectivity, but item *observation* (condition, notes, photos against an
  already-existing item) does not. This trades away "discover a room you didn't think of while
  standing in front of it with no signal" in exchange for eliminating the duplicate-item merge problem
  entirely. I'm not confident this is the right tradeoff for how HFC's agents actually work in the
  field — it needs Johan's call, not a silent decision, which is why it's marked as a recommendation
  and not folded into §3 as settled.

- **Idempotent sync writes.** Both `rental_inspection_observations` and `rental_inspection_photos`
  carry a `client_idempotency_key` (§3.2) specifically so a retried sync after a dropped connection
  never double-records the same fact — this directly mirrors the existing, already-proven
  `client_upload_id` + row-lock pattern in `MobilePropertyController::uploadImages()`, which was built
  for exactly this failure mode on the existing gallery upload path. This part of the offline story
  has a working precedent to copy.

- **Delayed discrepancy visibility.** Johan's example (Retha and Maggie both in the office, looking at
  the same live merged inspection) implies near-real-time visibility of a conflict. Offline breaks
  that: if one agent has no signal for hours or days, the discrepancy won't be detected (server-side,
  at sync time) until they're back online — which could be well after the inspection event itself.
  This is stated plainly as a real limitation of doing this offline, not hidden: the resolution
  conversation Johan describes may end up happening after the fact, once both agents have synced,
  rather than in the room. Whether that delay is acceptable is a business call for Johan, not
  something this spec resolves.

- **The client-side offline queue itself does not exist.** Per Johan's own ruling (§0.10), Andre owns
  the mobile-app build once this is ready — there is no service worker, IndexedDB, or local write-ahead
  queue anywhere in CoreX today (confirmed: `.ai/MOBILE_APP.md` lists "Offline support / data caching"
  under Features Needed, not built). This spec defines a server-side API contract that is safe to call
  from an eventually-built offline queue (idempotency keys, no assumption of real-time ordering,
  discrepancy detection deferred to sync time) — it does not, and cannot, build that queue itself.

---

## 8. Photo storage at scale — the numbers, stated plainly

Not hand-waved. Current real measurements (QA1, `/corex-qa1`, live query + `du`):

- Whole `storage/app/public/properties/` directory today: **7.5 GB** (4,194 property subdirectories,
  ~18,892 full-size images + ~18,431 thumbnails).
- Average processed (post-downscale, JPEG 85 @ 2560px max edge) full-size photo: **≈348 KB**.
- Current gallery usage across ALL properties (marketing photos, not inspections): 31,393 images total,
  averaging 4.1 images per property that has any.
- `rental_images_json` (the existing, barely-used inspection-photo mechanism): only 12 properties have
  any images in it at all, 36 images total. This feature is effectively greenfield for volume.
- The disk this lives on (`/mnt/HC_Volume_103099143`, the shared data volume) is currently **86% used,
  28 GB free** — shared with `/corex`, `/corex-qa2`, `/corex-staging`, and `corex-storage`.

**The math that matters**: Johan wants "enormous numbers" of photos, required on every item, good or
bad (§0.3), retained for FICA's five years minimum (§0.11), never deleted (§0.4/§0.11 combined mean
this only ever grows). A conservative estimate — 5 items/spaces average, 4 photos per observation, 2
inspection events per property per year, 5-year minimum retention — is 200 photos/property-year × 5
years ≈ 1,000 photos/property, at 348 KB each ≈ **348 MB per property**. Applied across even a modest
few hundred real rental properties, this is a multi-hundred-GB requirement within a few years, against
**28 GB of current headroom on a disk already at 86%** — this is the same disk-hygiene concern already
documented in CLAUDE.md's 2026-08-20 incident history, not a new category of risk.

**This is a real infrastructure decision that has to be made before this feature ships wide, not an
implementation detail to sort out later**: either (a) provision meaningfully more disk on the data
volume ahead of rollout, or (b) route inspection photos specifically to cheaper, larger object storage
(S3-compatible) rather than local disk, or (c) both. This spec does not pick one — it is Johan's or
Andre's infrastructure call, flagged here explicitly so it isn't discovered the hard way months into
real use, the way the 2026-08-20 disk incident was discovered by an alert nobody was watching.

---

## 9. Inventory — scoping decision, flagged not silently made

Johan's ruling (§0.9) describes inventory as a genuinely separate, broader thing than this spec: shared
between rentals AND sales, built on the property "from day one." This spec's `rental_inspection_items`
table is deliberately named and scoped to inspections only (rental-specific, condition-tracking) rather
than as the general Inventory concept Johan describes — cc3's own note (relayed by the conductor)
recommends the eventual Inventory feature define the same "items" this model hangs off, and this spec
agrees with that direction in principle, but does not attempt to build the cross-pillar (sale + rental)
Inventory feature here.

**[cc5 scoping decision, flagged for Johan]**: treating full Inventory as its own future spec — not
because it's unimportant, but because "shared between sale and rental listings" is architecturally
bigger than a rentals-only spec should absorb, and Johan's own sequencing (core matches / pipeline →
inspections → work orders) didn't explicitly slot Inventory into this build. If Johan wants Inventory
pulled forward and merged into this build instead of sequenced after, that's his call to make
explicitly — this spec does not assume it either way. Confirmed via cc3's investigation: nothing named
"inventory" exists anywhere in the codebase as a property-condition concept today (only generic English
usage and an unrelated real-estate market-supply metric), so there is no existing implementation this
spec risks colliding with either way.

---

## 10. User flow (summary)

1. Agent books/creates an **in-inspection** (type='in') on a rental property — a deliberate event
   (§0.5), not an implicit side-effect of anything else.
2. Agent (with owner, per §0.1's SA-standard sequence — no system enforcement of owner presence, this
   is a real-world process step) walks the property, adds/confirms `rental_inspection_items` (spaces +
   meters), records an observation per item with required photos and notes.
3. Tenant does the same walk-through with the agent; may add their own observations directly, or via
   the agent capturing on their behalf.
4. `fault_report_deadline_at` is set (creation time + agency's `fault_report_window_days`, default 7).
   Within that window, the tenant may add further observations against the same inspection or as a new
   `tenant_fault_report`-sourced observation without any agent gate. After the window, a new
   observation from the tenant is still accepted, but is flagged `reported_outside_window=true` and
   sits pending until an agent records a `window_decision` (accept/reject/defer).
5. Whenever two observations on the same item within the same inspection disagree, a
   `rental_inspection_discrepancies` row is created automatically and rendered inline (§4). The
   inspection cannot move to `completed` while any linked discrepancy has `resolved_at IS NULL`.
6. At move-out, agent creates the **out-inspection** (type='out'). Its view pulls forward every
   observation ever recorded against every item on this property (§0.2), not just what's captured
   during this event — so a two-year-old unresolved damp-wall report is visible on the out-inspection
   even if nobody photographed it again today.
7. Tenant signs (§3.6). `signing_deadline_at` = the moment status becomes `awaiting_signature` +
   agency's `out_inspection_signing_window_days` (default 7). If unsigned by then, an agent may sign
   `agent_on_behalf` with the required refusal note.
8. Inspection moves to `completed`.

---

## 11. Acceptance criteria

- An item, once created, is never deleted from the record — only `is_retired`, and its full
  observation history remains queryable regardless of retired state.
- An observation, once recorded, has no edit or delete path anywhere in the UI or API.
- Two observations against the same item in the same inspection, with differing `condition`, produce
  exactly one `rental_inspection_discrepancies` row (not one per conflicting pair) referencing all
  conflicting observations via the pivot.
- An inspection's status cannot transition to `completed` while it has any discrepancy with
  `resolved_at IS NULL`.
- A tenant-sourced observation created after `fault_report_deadline_at` is stored with
  `reported_outside_window=true` and does not block anything until an agent records a decision.
- An out-inspection's view includes every prior observation on every item for that property, not only
  observations recorded during the out-inspection event itself.
- `fault_report_window_days` and `out_inspection_signing_window_days` are read from
  `RentalInspectionSetting` per-agency, default to 7 when no row exists, and are never hardcoded
  anywhere in the observation/signing-deadline logic.
- Both windows appear in the Setup Wizard with an `explain` and `affects` entry, wired to a saver
  guarded per `.ai/specs/agency-onboarding-setup.md` §6.1.
- The Rental Inspections list screen supports search (property/tenant/agent), sort (default:
  most-recent-first) across every listed column, filter (status, type, date range, unresolved
  discrepancy), pagination, and a real empty state distinguishing "none yet" from "none matching this
  filter."
- A user outside the inspection's agency is rejected (404 via global scope) on every list, show, and
  API endpoint, independent of how the request is constructed.
- `client_idempotency_key` on both observations and photos prevents a retried/duplicated sync from
  creating two rows for what was really one capture.
- A signature record with `signer_role='agent_on_behalf'` is rejected by validation unless
  `refused_note` contains the required phrase.

---

## 12. Files to create (none yet written — spec only)

- `database/migrations/xxxx_create_rental_inspection_items_table.php`
- `database/migrations/xxxx_create_rental_inspections_table.php`
- `database/migrations/xxxx_create_rental_inspection_observations_table.php`
- `database/migrations/xxxx_create_rental_inspection_photos_table.php`
- `database/migrations/xxxx_create_rental_inspection_discrepancies_table.php`
- `database/migrations/xxxx_create_rental_inspection_discrepancy_observations_table.php`
- `database/migrations/xxxx_create_rental_inspection_signatures_table.php`
- `database/migrations/xxxx_create_rental_inspection_settings_table.php`
- `app/Models/RentalInspectionItem.php`, `RentalInspection.php`, `RentalInspectionObservation.php`,
  `RentalInspectionPhoto.php`, `RentalInspectionDiscrepancy.php`, `RentalInspectionSignature.php`,
  `RentalInspectionSetting.php` — all `use BelongsToAgency`.
- `app/Http/Controllers/CoreX/RentalInspectionController.php` — property-tab endpoints (item CRUD,
  observation create, discrepancy resolve, signature capture).
- `app/Http/Controllers/CoreX/RentalInspectionListController.php` (or equivalent) — the agency-level
  list screen (§5).
- `resources/views/corex/properties/partials/rental-inspections-tab.blade.php` — replaces the current
  In/Out Inspection section markup within `show.blade.php`'s Rental Images tab.
- `resources/views/corex/rental-inspections/index.blade.php` — the new list screen.
- `config/corex-permissions.php` — new permission keys (§6).
- `config/agency-onboarding-copy.php` — wizard entries for both windows (§3.5).
- Sidebar entry for the new list screen (same-day, non-negotiable #2).
- `tests/Feature/RentalInspections/*` — discrepancy detection/blocking, window-deadline logic, agency
  scoping, signature-refusal validation, at minimum.
- Re-run `php artisan schema:dump`, commit refreshed `database/schema/mysql-schema.sql` (non-negotiable
  #12a).

---

## 13. Out of scope (this spec)

- The mobile app's offline queue implementation itself (Andre's build, §0.10, §7.2).
- Full cross-pillar Inventory (§9) — flagged as its own future spec, not built here.
- Work Orders (§3.4) — a separate, not-yet-written spec; this spec only ensures the FK surface exists
  for it to attach to later.
- Any storage-infrastructure change (bigger volume, object storage migration) — a decision this spec
  surfaces (§8) but does not make.
- Landlord signature on the out-inspection — the schema leaves room (`signer_role` enum includes
  `landlord`) but Johan's ruling only requires the tenant's signature; building landlord sign-off is
  not requested and not built here unless Johan says otherwise.
