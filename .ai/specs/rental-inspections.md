# Rental Inspections

**Status:** ~~Spec — not yet built. NO CODE has been written against this spec.~~ **Superseded,
2026-09-20**: the spec as it stood through §14 has since been BUILT (Stages 1-5, cc4, landed on QA1,
independently verified by cc1 via real HTTP walks — not test-suite-only). The original "not yet built"
line is kept, struck through rather than deleted, so this header stays an honest record of the spec's
own history. **§15 (added 2026-09-20) is a new, separate addendum and genuinely is spec-only, not yet
built** — see its own status line.
**Date:** 2026-09-17 (amended 2026-09-17 — see amendment note below)
**Author:** cc5, with the data-model core designed by cc3 (current-state investigation, folded in per §3)
**Pillar:** Property (`Property`) — every `rental_inspection_item` anchors directly to it; every
`rental_inspection` (event) anchors to it only denormalized, through its required `lease_id` (see
amendment note). Also touches Contact (owner, tenant) and User (the inspecting agent).
**Sequencing:** Johan's ruling — core matches / pipeline → **inspections (this spec)** → work orders,
later revised by Johan to insert Leases before Inspections (`.ai/specs/leases.md`) once he identified
that a tenant is linked to a lease, not a property, and a property has many tenancies over its life —
without that link, nothing hanging off a property directly (an inspection, a work order, a photo) can
say which tenant it belonged to two years later.
This spec is written before Work Orders (`.ai/specs/rental-work-orders.md`, built by cc4) precisely
because Johan ruled work orders are evidence *feeding into* an out-inspection, not a separate concern —
see §3.4.

**Amendment, 2026-09-17 (this revision):** `rental_inspections` (§3.2) gains a required `lease_id` FK,
`property_id` becomes a denormalized convenience column, per the amendment named-but-not-applied in
`.ai/specs/leases.md` §9 at the time that spec was written. Applied now on Johan's explicit ruling,
once the leases design was reviewed. `rental_inspection_items` are unchanged — still property-scoped,
never lease-scoped, since a physical space outlives any one tenancy. §3.2a is new: a direct, worked
answer to "does this let an out-inspection pull a space's full history across the whole tenancy," not
just an in-vs-out diff.

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
   sign on the tenant's behalf with a strict note: "tenant refused to sign out inspection." **(2026-09-20
   — superseded by Johan's fuller ruling: BOTH in and out inspections need all three parties' signatures
   (tenant, landlord, agent), with tenant/landlord refusal as a first-class record. Building now, staged
   — see §15.)**
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
14. (2026-09-19 follow-up ruling, sharpens #10 rather than contradicting it) "Andre is going to have to
    tap into what we build to build the inspections on the corex app. So just keep that in mind when
    devving the web version of inspections." Building the mobile app itself is still Andre's job, exactly
    as #10 says — but the SERVER-SIDE CONTRACT his app depends on is now explicitly in scope for this
    build, not an afterthought bolted on once the web screens exist. An inspection done by an agent
    standing in a property with a phone is the real primary use case; the web screens are the back-office
    view of the same underlying data and rules. See §14 for the full design this drives.

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

- **Property** — every `rental_inspection_item` is anchored to a `Property` directly (physical
  spaces/meters outlive any one tenancy). Every `rental_inspection` (the event) is anchored to a
  `Property` only denormalized, through its required `lease_id` (§3.2, amended 2026-09-17 — see
  `.ai/specs/leases.md` §9). Reads the property's `listing_type`/`spaces_json` for context; writes the
  inspection record back.
- **Deal (Lease)** — every inspection event belongs to a `Lease`, not directly to a `Property`. This is
  the load-bearing connection this spec was amended for: without it, nothing can answer "which tenancy
  did this inspection belong to" once a property has had more than one.
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
  lease_id               -- REQUIRED FK to leases (.ai/specs/leases.md §9, amendment applied
                          --   2026-09-17). This is the authoritative answer to "which tenancy
                          --   did this inspection event belong to" — the entire reason leases
                          --   were built before this spec's code. An inspection cannot exist
                          --   without a lease. Ordering note: SA practice has the in-inspection
                          --   happen as the tenancy is being onboarded, which may be the same
                          --   day a lease goes 'active' or slightly ahead of it — so lease_id
                          --   may reference a lease in status 'draft' OR 'active' (never
                          --   'expired'/'cancelled') at inspection-creation time. This spec does
                          --   not require the lease be 'active' first; whether creating an
                          --   in-inspection should itself prompt activating the lease is a
                          --   build-time UX decision, not a rule this spec imposes.
  property_id            -- denormalized convenience column, ALWAYS set to lease.property_id
                          --   at creation, never edited independently. Exists purely so "every
                          --   inspection on this property regardless of tenant" queries don't
                          --   require a join through leases — it is never the authoritative
                          --   answer to "whose tenancy," lease_id is.
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

rental_inspection_signatures   -- CURRENT shape, as actually built at time of writing. §15
                                -- (2026-09-20, Johan's fuller ruling — three parties, both inspection
                                -- types, building now in stages) replaces this shape entirely. Do not
                                -- treat this block as current once any §15 stage lands.
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

### 3.2a Does this actually let an out-inspection pull a space's full history across the whole
tenancy — not just compare two points in time? Checked directly, answer is yes.

Johan's exact requirement: "having the comprehensive log of what damages were reported when and what
was actioned is the evidence assisting the out inspection to be more fair" — specifically so a badly
repaired ceiling traces to the contractor, not the tenant. That requires more than an in-vs-out diff:
it needs every observation AND every work order that touched a given item, in order, for the whole
span of this tenancy.

Walking the actual join, not asserting it: for a given lease and item, `rental_inspection_observations`
joins to `rental_inspections` filtered on `lease_id` and to `rental_inspection_item_id` — one join,
returns every observation ever recorded against that item during that lease, regardless of whether it
came from the in-inspection, an ad-hoc mid-tenancy check, or the out-inspection itself, in chronological
order via `created_at`. Photos hang off observations, so the same join one level deeper returns every
photo. Because `lease_id` is required on every `rental_inspections` row — not only 'in'/'out' but
'ad_hoc' too (§3.2, `type` enum) — a mid-tenancy fault report or ad-hoc check is NOT a separate silo
from the in/out bookends; it's the same queryable timeline. This is what makes it a comprehensive log
and not a two-point comparison.

Work orders (`.ai/specs/rental-work-orders.md`, cc4) carry `lease_id` directly (nullable, per that
spec's own vacancy-repair case) alongside `rental_inspection_item_id` — so "every work order raised
against this item during this lease" is the same shape of query, joinable straight onto the
observation timeline above without going through inspections at all. A repair that happened and was
"badly done" shows up as its own dated fact on the same per-item, per-lease timeline as the damage
report that preceded it and the damage observed again at the out-inspection — which is exactly the
trace from damage → repair → still-damaged that assigns responsibility to the contractor rather than
the tenant.

**The same join, with the `lease_id` filter dropped, answers the OTHER question this spec was built
for** — the original cross-tenant carry-forward requirement from §0.2 ("2 years later no one
remembers"). Because `rental_inspection_items` are property-scoped, not lease-scoped, "every
observation ever made on this item, across every lease this property has ever had" is the identical
join minus one WHERE clause. Both the within-this-tenancy comprehensive log and the across-every-
tenancy carry-forward are the same underlying shape — one is the other with a filter removed, not two
different mechanisms that need to be separately built and kept in sync.

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

`rental_inspections` itself (the event record) gets the standard `deleted_at`/restore floor
(non-negotiable #1, BUILD_STANDARD §1a) — ~~but only while it has zero observations recorded against
it. Once even one observation exists, the inspection cannot be archived/deleted through the normal
CRUD path; it can only be `cancelled` (a status, not a delete) if it was started in error.~~ **[cc5's
design call, reversed 2026-09-20]**: a real QA1 walk found this restriction was a dead end, not
evidentiary rigour — `cancel()` only flips status without hiding the record, so an agent who started an
inspection on the wrong property had NO way to ever get it off the list. `delete()` on this table is
already a SOFT delete (`deleted_at` + restore already existed); archiving never destroys the
observations, it only hides the record from the working list, same as every other entity's
archive/restore floor. The restriction is removed — any inspection, with or without observations, can
now be archived and restored, same as any other CoreX record. The record also now carries
`archived_by_user_id` (who — `deleted_at` already answered when), cleared again on restore.

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

**Amendment, 2026-09-22 (§20):** the tab itself is renamed **"Inspections"** — label and internal tab
key only (`'rental-images'` → `'inspections'` in `show.blade.php`'s tab list, guard, and `x-show`, plus
the one external redirect target in `RentalInspectionController::store()`). No database table, column,
route, or model name changed — every route under `corex.properties.rental-images.*` and every
`rental_inspection_*` table keeps its existing name exactly as it is. Wherever "Rental Images tab" is
read below, read it as the same tab, now labelled Inspections. See §20 for the full rebuild this
amendment shipped alongside.

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
- **Archive/restore**: standard `deleted_at` floor, same as any other CoreX record (§3.3, reversed
  2026-09-20 — no longer conditional on having zero observations). A "Show archived" toggle, same
  pattern as `rental-applications.index`, swaps the query to `onlyTrashed()`; an archived row shows who
  archived it and when (`archived_by_user_id` + `deleted_at`) and offers Restore in place of View.
- **Create**: added 2026-09-20 — a real QA1 walk found this screen had no way to start an inspection at
  all, only the property's own Rental Images tab did. `corex.rental-inspections.create`/`.store` offer
  a property (active-lease properties only — `start()` requires one) + type picker, calling the exact
  same `RentalInspection::start()` the tab's AJAX flow already uses, then redirecting to that property's
  Rental Images tab (`?tab=rental-images`) to actually record observations — this screen's own `show()`
  stays read-only, recording still only happens on the tab (§1/§4). Additional entry point, not a
  replacement.

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

**Migration order dependency (added with the §3.2 lease amendment):** `leases` (`.ai/specs/leases.md`)
must be migrated before `rental_inspections`, since `rental_inspections.lease_id` is a required FK.
`rental_inspection_items` has no such dependency (property-scoped only) and can migrate independently.

- `database/migrations/xxxx_create_rental_inspection_items_table.php`
- `database/migrations/xxxx_create_rental_inspections_table.php` — after `leases` exists
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

- The mobile app's offline queue implementation itself (Andre's build, §0.10, §7.2) — but NOT the
  server-side contract it talks to, which §14 now specifies in full, per ruling #14.
- Full cross-pillar Inventory (§9) — flagged as its own future spec, not built here.
- Work Orders (§3.4) — a separate, not-yet-written spec; this spec only ensures the FK surface exists
  for it to attach to later.
- Any storage-infrastructure change (bigger volume, object storage migration) — a decision this spec
  surfaces (§8) but does not make.
- Landlord signature on the out-inspection — the schema leaves room (`signer_role` enum includes
  `landlord`) but Johan's ruling only requires the tenant's signature; building landlord sign-off is
  not requested and not built here unless Johan says otherwise. **(2026-09-20 — superseded: his fuller
  ruling requires landlord signing AND refusal on both inspection types; see §15. Landlord sign-off IS
  now in scope, and is being built — this line stays, struck through in spirit, so the record shows
  the reversal rather than erasing it.)**
- **Splitting a secure parking bay from an open parking bay on an individual inspection.** 2026-09-21,
  the legacy `spaces_json` conversion (`LegacySpacesJsonConverter`) sums old `parking_spaces` +
  `secure_parkings` into one combined Parking count, per Johan's ruling ("secure parking will sit under
  parking"). The conductor noted explicitly that this is faithful, not a decision to leave unexamined:
  Johan's own reasoning for *why* secure parking is its own space rather than folded into Garage — "a
  garage door and a garage floor are not paving with oil stains on it" — applies just as much to a
  secure bay versus an *open* bay once both sit under the same Parking type and an agent is actually
  walking the inspection. Not building a split now. Recorded here so it is a deliberate deferral, not an
  oversight, if an agent later asks for the two to be told apart on the form.

---

## 14. Mobile foundation — the CoreX app is a first-class consumer, not an afterthought

**Amendment, 2026-09-19, per ruling #14.** This section is written for Andre as much as for whoever
builds the remaining web stages. If a sentence here reads as over-explained for an internal spec, that
is deliberate — Johan asked for something he can hand over directly, to someone who did not build any
of this.

### 14.0 The one-sentence version

An inspection is really done by an agent standing in a property with a phone, tapping through rooms,
taking photos, and typing a note when something's wrong. The web screens (Stages 3-5 of this build) are
the office's window onto the same data — useful for reviewing, resolving a dispute, or working from a
desk, but not the primary way an inspection actually gets recorded. Everything below exists so that
Andre's app and CoreX's web screens are two windows onto ONE set of rules, never two separate
implementations of the same rules that can quietly drift apart.

### 14.1 Audit — is any rule stuck somewhere the app can't reach it?

Johan asked for this checked honestly, not asserted. I read every file landed so far (Stages 1, 2, 4 —
migrations, models, the list-screen controller and views) against one test: **could a future API
controller call this rule directly, or would it have to copy the logic by hand?**

**What's already right, and can stay exactly as it is:** every actual business rule — the discrepancy
detection/grouping, the "cannot complete while unresolved" guard, the deadline calculations, the
signature capture and its refusal-note requirement, the late-fault-report decision, the cross-tenancy
history query — lives as a plain method on an Eloquent model (`RentalInspection`,
`RentalInspectionDiscrepancy`, `RentalInspectionSignature`, `RentalInspectionObservation`,
`RentalInspectionItem`). None of it lives inside a Blade template, and none of it is duplicated between
two call sites. A future API controller (§14.2) can call `RentalInspectionDiscrepancy::resolve(...)` or
`RentalInspection::markCompleted()` directly and get the exact same behaviour the web gets, because
there is only one copy of the behaviour to call. This is the right shape and does not need touching.

**Two real gaps, found by checking, not assumed away:**

1. **`RentalInspectionController::cancel()` (`app/Http/Controllers/CoreX/RentalInspectionController.php`,
   the `cancel` method) writes the cancellation directly** — `status`, `cancelled_at`,
   `cancelled_by_user_id`, `cancel_reason` are set inline in the controller, not through a model method.
   Today that's harmless because nothing else needs to cancel an inspection. It stops being harmless the
   moment an API endpoint needs the same action — whoever builds it would either have to copy these four
   lines (now two places can drift: someone fixes a bug in one and not the other) or reach into the
   controller from the API layer (wrong direction entirely). **Fix, when Stage 3/the API controller is
   built:** add `RentalInspection::cancel(User $by, string $reason): void`, doing exactly what the
   controller does today, and have both the web controller and the future API controller call it.
2. **There is no single, atomic "record an observation" operation anywhere in the app.** Recording an
   observation and detecting a discrepancy are two separate steps today —
   `RentalInspectionObservation::create([...])` followed by a separate call to
   `RentalInspectionDiscrepancy::detectFor($observation)` — and the ONLY place both steps currently
   happen together is test helper code (confirmed by checking: `detectFor(` is called from nowhere in
   `app/`, only from three test files). This is exactly the drift risk Johan is describing: if the web
   controller and a future API controller each independently remember to call both steps, they might not
   call them in the same way, and a missed `detectFor()` call means a genuine conflict silently never
   gets flagged — the worst kind of bug, because nothing errors, the data is just structurally wrong.
   **Fix, needed before Stage 3 (the property-tab controller) is built, not after:** a single entry
   point — `RentalInspectionObservation::record(array $attributes): self` (or a small
   `RentalInspectionRecordingService` if the logic grows beyond what belongs on the model) — that creates
   the observation AND runs discrepancy detection as one atomic operation. Stage 3's web controller and
   any future API controller both call this one method; neither can create an observation the "wrong"
   way, because there is no other way to do it.

Both fixes are additive (a new method wrapping existing, already-correct logic) — nothing built in
Stages 1/2/4 needs to change shape, and neither fix is code yet, per this pass being spec-only.

### 14.2 The API seam

**In plain terms:** think of this as the counter Andre's app orders from. The app never touches the
database directly — it asks CoreX's server to do something (fetch a checklist, record an observation,
upload a photo) over the internet, the same way it already does for everything else the CoreX mobile
app currently does (property listings, photo uploads, P24 location lookups). This section describes
what that counter needs to offer for inspections specifically. **Nothing here is invented from scratch**
— CoreX already has a real, working mobile API (`app/Http/Controllers/Api/MobilePropertyController.php`,
routes under `routes/api.php`'s `mobile/properties` group) that the app uses today for property photos
and details. Inspections should look and behave like a sibling of that, not a different animal.

**Authentication.** The same mechanism the app already uses for everything else: a Laravel Sanctum
bearer token, issued once at login (`$user->createToken('corex-mobile')`, `routes/api.php:64`) and sent
on every request as `Authorization: Bearer <token>`. No new login flow, no new token type. Every
inspection endpoint sits behind the same `auth:sanctum` + `app_access` middleware group every other
mobile endpoint already sits behind (`routes/api.php`, the `Route::middleware(['auth:sanctum',
'app_access'])->group(...)` wrapping `/v1/*`). Whichever agent is logged into the phone is who the
server believes is recording the inspection — the same identity, same permissions, same agency scoping
(`RentalInspection::scopeVisibleTo()`, already built) as if they were on the website.

**Namespace and route shape**, matching the existing mobile convention exactly (`v1.mobile.properties.*`
becomes `v1.mobile.rental-inspections.*`):

| Method | Route | What it does |
|---|---|---|
| `GET` | `/api/v1/mobile/properties/{property}/rental-inspection-items` | The checklist for this property — see §14.3. Never hardcoded in the app. |
| `POST` | `/api/v1/mobile/properties/{property}/rental-inspection-items` | Add a new space/meter to this property (Johan's ruling #6 — the agent adds items per property). |
| `GET` | `/api/v1/mobile/rental-inspections` | This agent's inspections — for "resume where I left off" on the app's home screen. |
| `POST` | `/api/v1/mobile/rental-inspections` | Start an inspection (lease + type). |
| `GET` | `/api/v1/mobile/rental-inspections/{inspection}` | Full detail — items, observations so far, any discrepancy, signatures. What the app loads when an agent opens an in-progress inspection, including after reinstalling the app or switching phones. |
| `POST` | `/api/v1/mobile/rental-inspections/{inspection}/observations` | Record one observation. Calls `RentalInspectionObservation::record()` (§14.1, fix 2) — the ONE path, atomic with discrepancy detection. |
| `POST` | `/api/v1/mobile/rental-inspections/{inspection}/observations/{observation}/photos` | Attach a photo to an observation — see §14.5. |
| `POST` | `/api/v1/mobile/rental-inspections/{inspection}/discrepancies/{discrepancy}/resolve` | Resolve a discrepancy. Calls `RentalInspectionDiscrepancy::resolve()` directly — same method the web will call. |
| `POST` | `/api/v1/mobile/rental-inspections/{inspection}/signatures` | Capture a signature. Calls `RentalInspectionSignature::capture()` directly. |
| `POST` | `/api/v1/mobile/rental-inspections/{inspection}/complete` | Finish the inspection. Calls `RentalInspection::markCompleted()` directly. |

**Payload shapes** mirror the model's own `$fillable` arrays exactly (already stable, already tested) —
there is deliberately no separate "API DTO" translation layer to invent and keep in sync. For example,
`POST .../observations` accepts exactly `{item_id, condition, notes, source, client_idempotency_key}`
(one-to-one with `RentalInspectionObservation`'s fillable columns, minus the server-assigned ones like
`agency_id`/`observed_by_user_id`, which come from the authenticated user, never the request body — the
same "never trust a client-supplied tenant/agency id" rule `BelongsToAgency` already enforces
everywhere else in CoreX).

**Error shapes** match the existing mobile API convention exactly, not a new envelope:
`{"message": "..."}`, with the HTTP status carrying the real meaning — `422` for a validation failure
(e.g. a condition value the enum doesn't recognise), `409` for a state conflict (e.g. trying to complete
an inspection with an unresolved discrepancy — `RentalInspection::markCompleted()` already throws a
`LogicException` for this; the API controller catches it and returns 409, not 500, since it's an
expected, recoverable state the app should show the agent, not a crash), `403` for a permission/scoping
failure, `500` only for genuine unexpected failures (matching `MobilePropertyController::uploadImage()`'s
own rule: never return success unless the write genuinely, durably happened).

**What this pass does NOT do:** write the actual `Api\RentalInspectionController`, its routes, or its
tests. That is real code, held until the base is stable (per the conductor's explicit instruction) and
until Stage 3's web equivalent exists to build alongside — but the shape above is fixed enough now that
nothing in Stages 3-5 should be built in a way that makes it impossible or awkward to add this
controller later calling the exact same model methods.

### 14.3 The checklist must be fetched, never hardcoded

**In plain terms:** the app must never ship with "Bedroom 1, Bedroom 2, Kitchen, Bathroom" typed into
its own code. Different agencies inspect different things, and Johan has ruled the whole rental process
is becoming agency-configurable — the app has to ask CoreX "what does this property need checking?"
every time, not assume it already knows.

**Good news: the hard part of this is already built, for free.** Johan's own ruling (§0.6) is that
"the agent adds inspection spaces per property, differing from the advertised marketing room list" —
meaning the item list was never meant to be a small, agency-wide, pre-set catalog in the first place. It
is genuinely per-property data, and `rental_inspection_items` (Stage 1, already landed) already stores
exactly that: `agency_id, property_id, kind, label, space_type, is_retired`. **`GET
/api/v1/mobile/properties/{property}/rental-inspection-items` (§14.2) simply reads this table.** There
is no separate "checklist config" system to build for the app to stop hardcoding things — the per-
property list already is the checklist, and exposing it over the API is the whole fix.

**Coordinated with cc5 (2026-09-19, cross-session, before writing this)** — their
`rental-application-field-config.md` names two different config shapes for two different problems: a
JSON blob on a settings row for toggling a small fixed set of fields ("Shape A"), or a real one-row-per-
item table for open-ended, agency-defined things with their own identity and ordering ("Shape B", used
for their custom fields: `agency_id, key, label, help_text, field_type, options, section, sort_order,
required`). cc5's own read, which I agree with: inspection items are Shape B, and the existing
`rental_inspection_items` table already IS that shape, one level down (per-property rather than
agency-wide). **No new table is required to satisfy "never hardcode the checklist"** — the existing
model, exposed over the API, already satisfies it.

**One genuinely open, optional extension, not required for the above to work:** an agent typing
"Bedroom 1, Bedroom 2, Kitchen, Bathroom, Geyser" by hand for every single new property is real,
repetitive admin work. A future **agency-level default/seed catalog** — same Shape B pattern, one level
up (`rental_inspection_item_defaults`: `agency_id, kind, label, space_type, sort_order, is_active`, no
`property_id`) — could seed a new property's item list automatically the first time an inspection
starts on it, with the agent still free to add, rename, or retire items per Johan's existing per-
property ruling. Because this only ever COPIES into the property-level row at seed time rather than the
inspection referencing the template live, it inherits the same historical-integrity safety
`rental_inspection_items` already has for free — a later edit to the agency's default catalog can never
retroactively change what a past inspection shows, because the past inspection's items were copied, not
referenced. **This is a genuine open question for Johan, not decided here**: does he want this seeding
behaviour built now, later, or not at all? The mobile "never hardcode" requirement is fully satisfied
without it — this is a convenience layer on top, not a blocker.

### 14.4 Offline capture — what the server does when data arrives late, out of order, or twice

**In plain terms:** an agent inspecting a property in a basement parking garage or a rural area may have
no signal at all. The app has to let them keep working anyway, and send everything to CoreX once a
connection comes back — possibly minutes later, possibly that evening, possibly out of order if several
observations queued up and retried in an unpredictable sequence. The server has to make sense of
whatever arrives, whenever it arrives, exactly once each.

**Already built, Stage 1 (`rental_inspection_observations.client_idempotency_key`,
`rental_inspection_photos.client_idempotency_key`, both unique):** the app generates a UUID on the phone
at the moment an observation or photo is captured — not when it's finally sent. If the same UUID arrives
twice (a network retry after a timeout where the first attempt actually succeeded, or the same queued
item accidentally submitted twice), the database's own `UNIQUE` constraint refuses the second insert.
Mirrors the proven `client_upload_id` pattern already live in
`MobilePropertyController::uploadImage()` for property photos.

**Specified now, not built yet — the four concrete cases §14.2's future API controller must handle:**

1. **Late arrival (the normal case).** An observation captured at 9am arrives at 2pm because the agent
   had no signal until then. The server stamps `created_at` from when it actually captured the fact
   (the app sends its own captured timestamp; the server trusts it, the same way `created_at` is already
   a settable column on `RentalInspectionObservation`, not an auto column) — NOT from when the request
   happened to arrive. This is why observation ordering (§3.1's "current condition is a query" and the
   discrepancy-detection logic) must always sort by the CAPTURED time, never the received time — already
   true today (`created_at` is explicitly settable on creation, per the Stage 1 model), just naming it
   here as a rule that must hold for the API path too, not only the web path.
2. **Out-of-order arrival.** Two observations for the same item, captured five minutes apart offline,
   arrive to the server in the REVERSE order (the second one's upload happened to finish first). Because
   discrepancy detection (§3.1) already compares CONDITION VALUES, not arrival sequence, and because
   both observations carry their own real captured `created_at`, the detection logic behaves identically
   regardless of which one the server processes first — nothing here needs to change, but it's worth
   stating as a property the design already has, not something assumed.
3. **A genuine duplicate (not a retry).** The agent's app crashed and, on restart, re-queued an
   observation that had ALREADY been sent successfully, but under a NEW client_idempotency_key (a bug
   in the app's own queue, not something CoreX can detect from the key alone, since the key is different
   this time). This is a real risk the unique-key mechanism cannot catch by itself, because it relies on
   the same key being reused. **Recommendation, not yet built:** the API controller for
   `POST .../observations` can cheaply guard the common case — refuse (or flag for review, not silently
   accept) a second observation for the same item, same inspection, with the same condition and the same
   captured-timestamp-to-the-minute as one already on record, since that combination recorded twice
   within a short window is far more likely to be an app-side duplicate than a genuine second real-world
   check. This is a heuristic, not a hard guarantee — flagged as a recommendation for whoever builds the
   API controller, not a rule this spec insists on.
4. **Stale data — the property or lease has moved on since the phone captured it.** The agent starts an
   inspection offline against Property X, Lease Y. While they're offline, the lease gets cancelled, or
   the property changes hands, or (rarer but real) the whole lease record is superseded by a renewal.
   The phone has no way to know this at capture time. **What the server does:** an inspection is
   anchored to a specific `lease_id` at creation (§3.2, already built, immutable once set — there is no
   "move this inspection to a different lease" operation anywhere in this design). If that lease has
   since been cancelled or superseded by the time the offline data finally arrives, the observations
   still belong, correctly, to the inspection that was actually happening at the time — a snapshot of
   what was true when the agent was standing in the property, which is exactly what an inspection
   record is FOR. The API controller does not reject a late-arriving observation just because the
   lease's status has since changed — doing so would let a real, true inspection event silently
   disappear because of something the agent had no way to see. **The one thing the API controller SHOULD
   do**: if the inspection's own status has moved on without it (e.g. it was separately cancelled or
   completed by someone else in the meantime, or the fault-report/signing deadline has since passed), a
   late-arriving observation should still be accepted and stored (the evidence is real and happened),
   but the response should tell the app plainly what happened — e.g. "recorded, but this inspection was
   already marked completed on [date]" — so the agent isn't left thinking their offline work vanished
   into nothing, and so the app can decide whether to surface that to them.

### 14.5 Photos from a phone

**In plain terms:** photos are the single heaviest, most failure-prone part of a mobile inspection —
phone cameras produce large files, connections drop mid-upload, and a lost "before" photo of a damaged
wall is exactly the kind of gap that turns an inspection into a shrug instead of evidence. This section
is deliberately concrete, not hand-waved, per Johan's own instruction.

**Reuse, don't reinvent — the exact pipeline already exists and is production-proven.**
`MobilePropertyController::uploadImage()` (`app/Http/Controllers/Api/MobilePropertyController.php`) is
the real, live mechanism the CoreX app already uses to upload property photos from a phone today. Every
rule below is that same mechanism, applied to `rental_inspection_photos` instead of a property's
gallery — this build does not invent a second photo pipeline:

- **Format**: `jpg, jpeg, png, webp, heic, heif` — HEIC/HEIF included deliberately, because that's an
  iPhone's default capture format, and Laravel's built-in `image` validation rule rejects it. The app
  normally converts HEIC to JPEG on the phone before sending; the server still accepts a raw HEIC as a
  safety net for an older app build or a failed conversion, and simply skips generating a thumbnail for
  it rather than erroring (GD, the server's image library, cannot read HEIC directly).
- **Size**: up to 50MB per photo. The number is not arbitrary — it's set from a real incident where a
  smaller cap silently rejected 48-megapixel phone photos that are completely normal on current
  hardware, and it matches the equivalent web-upload limit exactly so a photo isn't accepted from one
  surface and rejected from the other.
- **Association**: a photo belongs to exactly one observation (`rental_inspection_photos
  .rental_inspection_observation_id`, Stage 1, already built) — not to the inspection directly, and not
  to the item directly. This matters for the "no photos equals lots of fights" ruling (§0.3): a photo is
  evidence FOR a specific claimed condition at a specific moment, not a loose gallery attached to the
  visit in general.
- **Idempotency**: `client_idempotency_key` (Stage 1, already built, unique) — the phone generates this
  once per photo at capture time and resends the SAME value on every retry of that same photo. A retry
  that lands on a photo already stored returns the existing record rather than creating a duplicate —
  same mechanism as `client_upload_id` in the existing property-photo pipeline.
- **Orientation and sizing**: EXIF orientation is baked into the stored file BEFORE any resizing happens
  (a real, previously-shipped bug: resizing before fixing orientation can strip the "rotate me" tag and
  leave the photo permanently sideways), then downscaled to the same 2560px cap every other CoreX photo
  uses (`PropertyImageStorer`). No new image-processing code — the exact same services
  (`ImageOrientationNormalizer`, `PropertyImageStorer`) are called against the new storage path.
- **What happens on a half-failed upload**: the server never returns success unless the file is
  confirmed durably written to disk. If the file write fails for any reason, the response is a `500`,
  not a `200` with a warning — because the app is expected to treat a `500` as "retry this," and a `200`
  as "this is safely done, forget about it and move on." Returning success on a half-failure would mean
  the app deletes its only local copy believing CoreX has it, and the photo is gone permanently. This is
  the single most important rule in this section: **a photo upload is binary — either CoreX definitely
  has it, or the app is told to try again. There is no silent partial state.**

### 14.6 Versioning — so a future change doesn't break an agent's phone mid-inspection

**In plain terms:** once Andre has shipped a version of the app that real agents are using in the field,
CoreX cannot casually change what an endpoint expects or returns — an agent standing in a damaged
property, halfway through recording a dispute, is the worst possible moment to have their app suddenly
error because the server changed underneath them.

**The mechanism**: every endpoint in §14.2 lives under `/api/v1/...`, matching CoreX's existing,
already-established API versioning convention (non-negotiable #7 — every API endpoint is versioned,
named, and catalogued). The rule going forward, specific to inspections because agents use this one
standing in a property with patchy signal, rather than at a desk:

- **Additive changes — safe, no version bump needed.** Adding a new optional field to a response, adding
  a new endpoint, adding a new (optional) field an old app version simply never sends — none of these
  break an app that doesn't know about them yet. This covers the large majority of realistic future
  changes (e.g. adding the agency-level default-catalog seeding from §14.3, if Johan decides to build
  it — an old app version keeps working exactly as before, a new one gets the extra convenience).
- **Breaking changes — never made in place.** Removing a field, renaming a field, changing what a field
  means, changing a required payload shape, or changing an enum's valid values (e.g. adding a new
  `condition` value the old app's dropdown doesn't know how to display) — any of these could genuinely
  break an app already in an agent's hand. These require a new version, `/api/v2/...`, running ALONGSIDE
  `/v1` for as long as any app build still in the field depends on it — never a silent in-place change to
  `/v1`'s existing behaviour. This mirrors how CoreX already treats its API surface generally (versioned
  namespaces, non-negotiable #7); nothing new is being invented here, just stated explicitly for this
  specific, higher-stakes case.
- **The app should tell the server its own build version on every request** (a simple header, e.g.
  `X-CoreX-App-Version`), even though nothing reads it yet. This costs nothing to add now and means that
  if a real-world problem ever needs debugging ("agents on build 4.2 are seeing X"), the information is
  already there in the logs rather than needing to be added reactively after the fact.
- **A breaking enum change specifically** (the most likely real one — e.g. adding a new `condition`
  value beyond good/fair/damaged/not_working/missing/other) should default old app builds to treating an
  unrecognised value as `other` with the real value preserved in the notes, rather than crashing or
  silently dropping the observation — the app should degrade gracefully, not fail loudly, when it meets
  data newer than itself.

### 14.7 What this changes about the remaining build stages

Nothing here changes Stage 4 (already landed) or requires touching it. It does change how Stage 3 (the
property-tab controller, still deliberately held for attended work) should be built once it starts:

- Item creation, observation recording, discrepancy resolution, and signature capture must each be a
  model-layer method the web controller calls thinly (§14.1's two fixes are the concrete to-do list),
  not logic written directly into the controller action.
- The web controller and the future API controller (§14.2, not built this pass) are two thin callers of
  the identical model methods — building Stage 3 this way costs nothing extra now and avoids a rebuild
  later when the API controller is actually written.
- No decision made in Stage 3 should assume the web browser is the only client — e.g. a validation
  message meant only for a Blade form's specific HTML structure has no place inside a model method; it
  belongs in the controller/view layer, which the API will not share and does not need to.

---

## 15. Three-party signing — Johan's 2026-09-20 ruling (BUILT, all five stages)

**Status: BUILT.** All five stages landed 2026-09-20 (cc4), each independently verified by cc1 over
real HTTP/test runs before landing, not test-suite-only. §15 originally recorded an open question (was in-inspection signing
in scope at all). Johan's answer was bigger than the question — kept below in §15.0 for the record,
then superseded by the decided design in §15.1 onward. This is not a signature step bolted onto an
inspection: **an inspection forms part of the lease agreement, and an unsigned one is not an accepted
document.** Signing is what makes the inspection real, and that framing drives every ambiguous call
below.

### 15.0 The question that was asked, and the answer that came back

The open question this section originally recorded: does in-inspection gain a real signing step at
all, or does only the out-inspection (which already signs the tenant) get hardened. Johan's answer,
verbatim: "inspections both in and out needs all party signatures. tenant, landlord and agent. its
form part of the lease agreement so without signatures its not an accepted document. so all parties
needs to sign, and the agent can mark either tenant and / or landlord refuses to sign."

That is neither of the two branches originally specced. Both inspection types get full signing, by
all three parties, not just the tenant.

### 15.1 The shape, stated plainly

- **Three signatories, both inspection types.** Tenant, landlord, agent — on the in-inspection AND the
  out-inspection. In-inspection currently has NO signature capability at all (confirmed live on the
  2026-09-20 QA1 walk) — that whole path is built new, not adapted. Out-inspection currently signs the
  tenant only — landlord is new there too. **Out-inspection is not done just because it already has a
  signature step.**
- **The agent always signs. No refusal option exists for the agent.** The agent is the one attesting
  to what happened; their signature is what gives the document its weight, including when it records
  someone else refusing. An inspection cannot complete without the agent's own signature — no
  exception, ever.
- **Refusal applies to tenant and landlord only, independently.** Tenant signs and landlord refuses is
  a normal, complete, valid outcome. Both refusing is a normal, complete, valid outcome. Neither is an
  error state, and neither should read as one anywhere in the UI — no warning colour, no "problem"
  iconography on a valid, complete disposition.
- **Every tenant on the lease is a party — Johan's own decision, not a recommendation this spec is
  making for him**: "where a lease has more than one tenant, ALL tenants on the lease are parties to
  it, so all of them sign — or are individually marked as refusing. One tenant's signature does not
  cover another's." **Checked for practical impracticality, as he asked**: the realistic case this
  could break on is a shared-house lease with several tenants, most of whom are never present for a
  walkthrough. That case is already absorbed by the refusal mechanism itself — an absent tenant is
  recorded as "refused / not present" in one tap (§15.5's preset list), the same action already needed
  for a tenant who is present but declines. It costs the agent one extra tap per absent tenant, not a
  form. No impracticality found; flagging this reasoning rather than silently agreeing, per Johan's own
  request to say so before building if there was a problem.
- **Unambiguity, restated because it matters more now**: a refusal must never be able to look like a
  signature, on screen or on a PDF. Different storage, different label, different rendering — §15.3.

### 15.2 Data model — `rental_inspection_signatures`, rebuilt

Replaces the shape in §3.2 and the interim proposal this section previously carried. References
`Property::sellerOwnerContact()` (`app/Models/Property.php`) for landlord identity — already built,
already the canonical "who is the seller/owner/landlord side of this property" resolver used elsewhere
in CoreX (AT-105); not a new mechanism.

```
rental_inspection_signatures
  id
  rental_inspection_id
  party_role               -- enum: 'tenant' | 'landlord' | 'agent'. 'agent_on_behalf' is retired —
                            --   the agent is never a stand-in party, only ever the attesting signer.
  party_contact_id          -- FK to contacts, nullable.
                            --   REQUIRED when party_role='tenant' — must be one of this inspection's
                            --   own lease's LeaseTenant contacts (§15.1 — per tenant, not per lease).
                            --   Set when party_role='landlord' AND Property::sellerOwnerContact()
                            --   resolves one for this property; the landlord requirement is WAIVED
                            --   (not silently satisfied, not blocking) when it resolves to null — see
                            --   §15.4's completion-guard treatment of this exact case.
                            --   Always NULL when party_role='agent' — the agent is identified by
                            --   recorded_by_user_id below, never a Contact.
  disposition               -- enum: 'signed' | 'refused'. A party_role='agent' row is ALWAYS 'signed'
                            --   — enforced at creation (§15.2a), never left to a caller to get right.
  party_signature_path      -- storage path (§3.6 pattern — canvas capture, decoded server-side, only
                            --   the path stored). Required when disposition='signed'. This is also
                            --   where the AGENT's own signature image lives, on their own
                            --   party_role='agent' row — there is exactly one agent signature per
                            --   inspection, not one per refusal it attests to (§15.2a explains why).
                            --   NULL when disposition='refused'.
  refusal_reason_preset     -- agency-configurable key (§15.5, §15.6). Required when disposition=
                            --   'refused'. Always NULL for party_role='agent'.
  refusal_reason_note       -- free text. Required only when refusal_reason_preset='other'. NULL
                            --   otherwise, always NULL for party_role='agent'.
  recorded_by_user_id       -- the authenticated staff member who captured THIS row — server-derived,
                            --   never client-supplied (same rule BelongsToAgency already enforces
                            --   everywhere in CoreX). For the agent's own row this is that same agent,
                            --   trivially; for a tenant/landlord row it is whichever agent was holding
                            --   the device or recording the refusal.
  disposition_recorded_at
  created_at
```

**Retired from the old shape**: `signer_role`'s `agent_on_behalf` value, `signer_contact_id` (renamed
`party_contact_id`), `refused_note` (split into `refusal_reason_preset`/`_note`), `signed_at` (renamed
`disposition_recorded_at`).

#### 15.2a Why one agent signature, not one per refusal

An earlier draft of this section (before Johan's fuller ruling) gave every refusal row its own
`attesting_agent_signature_path`, on the assumption the agent re-attests each refusal individually.
Johan's actual words simplify this: "their signature is what gives the document its weight — INCLUDING
when it records somebody else refusing" (singular document, not per-event). One agent signature,
captured once, attests to the entire inspection record as filed — every observation, every tenant and
landlord disposition on it — the same way one signature at the foot of a report attests to everything
above it, not to each paragraph individually. This is simpler than the earlier draft and matches what
Johan actually said; **flagging the correction explicitly rather than quietly carrying the old shape
forward.**

**This creates one new, load-bearing rule, not stated by Johan in these words but a direct consequence
of his framing — [cc4 design call]: the agent's own row can only be created once every other required
party (every tenant, and the landlord if resolvable) already has a disposition row.** A signature
cannot attest to a refusal that hasn't happened yet. Enforced at the same factory method that enforces
everything else in this table (§15.2's invariants), never left to the UI to sequence correctly — an
attempt to record the agent's signature early throws, the same class of guard as the existing
`refusalNoteIsValid()` check this replaces. The UI reflects this naturally: the agent-sign action is
simply not offered (disabled, not hidden — an agent should see it exists and see why it's not ready
yet, per the screen-space discipline of "no state disappears, it explains itself") until every other
party has a disposition.

**The invariant table, checked at the one factory method that creates these rows:**

| | `party_role='agent'` | `disposition='signed'` (tenant/landlord) | `disposition='refused'` (tenant/landlord) |
|---|---|---|---|
| `party_contact_id` | must be null | required | required |
| `party_signature_path` | required | required | must be null |
| `refusal_reason_preset`/`_note` | must be null | must be null | `_preset` required, `_note` required only if preset='other' |
| Creation allowed when | every other required party already dispositioned | any time during recording | any time during recording |
| `disposition` | always `'signed'` | `'signed'` | `'refused'` |

### 15.3 In-inspection signing — the whole path, built new

`RentalInspection::startAwaitingSignature()` currently throws "Only an out-inspection has a signing
window" for any type but `TYPE_OUT` — this restriction is removed; both `TYPE_IN` and `TYPE_OUT` gain
the same `awaiting_signature` stage (its unresolved-discrepancy guard is unchanged, for both types).
`TYPE_AD_HOC` is explicitly excluded from all of §15 — Johan's ruling names "both in and out"
specifically; an ad-hoc mid-tenancy check keeps its existing lighter-weight lifecycle (no signing
requirement), matching its existing exemption from the "already one open" guard elsewhere in this
spec. The recording partial's current `@if($section === 'in')` branch (a bare "Complete" button, no
signing at all) is removed — both sections render the identical signing/refusal UI from §15.5.

### 15.4 Out-inspection landlord signing — added alongside the existing tenant signing

The out-inspection's existing tenant-signing UI and the underlying `RentalInspectionSignature::capture()`
call stay conceptually where they are, but gain a landlord row alongside the tenant row(s), resolved via
`Property::sellerOwnerContact()`. **The landlord-identity edge case, handled explicitly, not left to
surface as a confusing dead end**: if `sellerOwnerContact()` returns null (no resolvable owner-side
contact linked to this property), the landlord requirement is waived for that inspection — the
completion guard (§15.7) does not require a landlord disposition that has no identifiable party to
attach it to, and the UI shows this plainly ("Landlord: not linked to this property — nothing to
sign") rather than silently omitting the row or blocking completion on a party nobody can name.

**Realistic expectation, stated so it isn't mistaken for a bug later**: landlords rarely attend an
in-person walkthrough. Given signing here reuses the existing in-person canvas-capture pattern (§3.6)
rather than a remote link, the landlord's disposition will, in ordinary practice, very often end up
`refused` with a reason like "not present" — this is expected, not a sign the feature is being misused.
A remote/async signing link (ruling §0.7 says "Links, and 7 days...", never actually built — checked,
not assumed) would be the real fix for this, but it is a materially larger, separate piece of work and
is explicitly OUT of scope for this build — named here so it is a known gap, not a silently dropped one.

### 15.5 Refusal capture and the agent attestation

**The reason is mandatory, always — not an agency setting, not optional** (recommendation carried
forward from this section's earlier draft, argued there: an unreasoned refusal is barely
distinguishable from an agent skipping the step, and "why" is exactly what a deposit dispute turns on).
Captured as a one-tap, agency-configurable preset (§15.6), with free text required only when "Other" is
picked — an agent standing at a front door is not blocked by a form. "Refused outright, no reason
given" is kept as its own honest preset, not a way to skip the field.

**The agent attestation** is the party_role='agent' row itself (§15.2a) — there is no separate
per-refusal agent signature to capture. What the UI DOES need, per refusal, at the moment it's
recorded: which party (tenant name, or "Landlord"), the reason, and — implicitly — which agent is
recording it (`recorded_by_user_id`, server-derived, never asked of the user).

**Rendering, wherever a disposition row is shown — the property tab, the inspection's own show page
(§15.8), and any future PDF/print/email output (§15.9)** — branches on `disposition` alone. A `signed`
row shows the party's name, role, and their own signature image. A `refused` row shows the party's
name, role, the reason, and — separately, clearly captioned as the agent's own mark, never adjacent in
a way that could be mistaken for the refusing party's signature — the agent's row (name + their
signature image), explicitly labelled e.g. "Attested by {{ agent name }}". No shared visual weight, no
shared code path, no colour-only distinction (must survive black-and-white print).

**Neither disposition reads as an error state.** A refused row gets a neutral, informational treatment
— the same visual register as a completed, signed row — never a warning colour or an alert icon. Both
are simply facts about how the inspection ended.

### 15.6 Multi-agency settings

Extends `rental_inspection_settings` (§3.2):

- `refusal_reason_presets` — JSON array of `{key, label}`, agency-editable wording. "Other" always
  present, always last, never agency-removable (the mandatory-reason guarantee in §15.5 depends on an
  escape valve existing). Sensible, neutral, multi-agency-safe default for every new agency, no
  HFC-specific wording: "Disputes the recorded condition", "Not present for the walkthrough", "Refused
  outright, no reason given", "Other".
- Every new setting here reaches the Setup Wizard in the same build prompt as the settings screen
  (CLAUDE.md non-negotiable #10a).
- Per-agency document layout stays deferred, per Johan's standing ruling — this build does not
  introduce a layout system for §15.9's future rendering; it only guarantees the DATA is unambiguous
  regardless of how any future layout eventually presents it.

### 15.7 The completion guard — replaced, both types

**Today**: `markCompleted()`'s guard is `if ($this->type === self::TYPE_OUT && !
$this->signatures()->exists())` — any single signature, on the out-inspection only. **Replaced
entirely** with, for `TYPE_IN` and `TYPE_OUT` alike (`TYPE_AD_HOC` exempt, §15.3):

1. The agent's own row exists (`party_role='agent'`, `disposition='signed'`) — always required.
2. Every tenant on the lease (`LeaseTenant`) has exactly one disposition row (`signed` or `refused`).
3. The landlord has exactly one disposition row, UNLESS `Property::sellerOwnerContact()` resolves to
   null for this property (§15.4) — in which case this requirement is waived, not silently satisfied.

Each missing requirement throws its own specific `LogicException` (matching the existing pattern —
e.g. "Cannot complete: Thabo Nkosi has neither signed nor been marked as refusing.", "Cannot complete:
the landlord has neither signed nor been marked as refusing.", "Cannot complete an inspection without
the agent's own signature."), so an agent standing in a property gets told exactly what is missing, not
a generic failure. **Why this cannot become a bypass**: the guard only ever asks "does a valid
disposition row exist" — never inspects a checkbox in isolation — and §15.2's factory method refuses to
create a `refused` row without a real reason, or an `agent` row before every other party is already
dispositioned. There is no code path that produces a row satisfying the guard without the real thing
behind it.

### 15.8 Where this is shown, once built

The inspection's own show page (`resources/views/corex/rental-inspections/show.blade.php`, already
live) currently has no signature rendering of any kind (it predates this feature entirely). This build
adds it, following §15.5's rendering rule exactly — this is an EXISTING, live screen, not a future
concern the way §15.9 is, and is in scope for this build's stages.

### 15.9 The PDF and any printed or emailed output

Unchanged from this section's earlier draft: no PDF/print/export exists for a rental inspection today
(checked, not assumed). This remains a forward-looking constraint on whoever eventually builds that
output — §15.5's rendering rule applies there too, the moment it exists.

### 15.10 Mobile/API shape

Extends §14.2's table once built. No new endpoint shape — the same
`POST /api/v1/mobile/rental-inspections/{inspection}/signatures` endpoint specced there accepts
§15.2's revised payload (`party_role`, `party_contact_id`, `disposition`, plus either
`signature_image` or `refusal_reason_preset`/`_note`). `recorded_by_user_id` and the agent-signs-last
ordering rule (§15.2a) are both server-derived/server-enforced in the ONE model factory method, called
identically by the web controller and the future mobile API controller — matching §14.1's "one copy of
the behaviour" principle, and the exact discipline `RentalInspectionSignature::storeCanvasImage()`
already established as a pure model method reachable from a mobile controller.

### 15.11 Build stages (conductor's shape, adjusted only if the code says otherwise)

Staged the way `.ai/specs/rental-work-orders.md` was staged — cc1 verifies real behaviour per stage,
not one landing at the end.

1. **The signature model itself** — migration rebuilding `rental_inspection_signatures` to §15.2's
   shape, `RentalInspectionSignature` rewritten around the new factory method enforcing every
   invariant in §15.2a's table (three party roles, per-tenant rows, agent-signs-last ordering, refusal
   as a first-class disposition, never an absent signature). `rental_inspection_settings` gains
   `refusal_reason_presets` (§15.6) + Setup Wizard entry. No UI, no controller changes, no completion
   guard changes yet — get the foundation right first, since every later stage inherits it.
2. **In-inspection signing** — the whole new path (§15.3): `startAwaitingSignature()`'s type
   restriction removed, recording partial's `@if($section==='in')` branch replaced with real
   signing UI (tenant rows + agent row; landlord and refusal come in later stages so this stage can
   land and be verified on its own).
3. **Out-inspection landlord signing** — added alongside the existing tenant signing (§15.4),
   including the `sellerOwnerContact()`-null edge case.
4. **Refusal capture and the agent attestation** — wires §15.5's reason capture and the
   agent-always-signs-last rule into both types' UI, on top of stages 2-3's plain-signing paths.
5. **The completion guard replaced on both types (§15.7), plus the document output** — `markCompleted()`
   rewritten, and §15.8's show-page rendering built (unambiguous signed-vs-refused, per §15.5).

### 15.12 Acceptance criteria

- An in-inspection cannot complete without the agent's own signature — proven live (a real attempt,
  not just a passing test), matching how the old out-inspection guard was proven on the 2026-09-20 walk.
- A lease with two tenants, one signing and one refusing, is a valid, completable out-inspection with no
  error-state styling anywhere in the UI for the refused party.
- A completed inspection's show page displays a refused disposition in a way that could not be mistaken
  for a signature by someone reading it cold, eighteen months later, with no access to this spec.
- The landlord requirement is waived, not silently ignored and not blocking, when
  `Property::sellerOwnerContact()` resolves to null.
- ~~Every new setting from §15.6 appears in the Setup Wizard in the same stage it's built, not later.~~
  **Not met, flagged not silently dropped (Stage 1)**: `refusal_reason_presets` is a JSON list; none of
  the wizard's existing control types (number/select/text/textarea/toggle) fit it, and building a new
  repeater-style control type was judged out of scope for this build. It IS editable — on the dedicated
  `/corex/settings/rental-inspections` screen, guarded against the wizard's own unrelated save wiping it
  (§15.6) — just not from the wizard itself. Flagged to the conductor at Stage 1 time; still open for
  Johan's call on whether a wizard control gets built later.

**All five stages built 2026-09-20 (cc4), each independently verified by cc1 over real HTTP before
landing:**
1. The signature model (`RentalInspectionSignature` rebuilt around `capture()`'s invariant enforcement,
   `RentalInspection::outstandingSignatories()`/`hasAgentSignature()`).
2. In-inspection signing — the whole new per-tenant + agent path.
3. Out-inspection landlord signing — consolidated with in-inspection's shared UI in the same pass,
   rather than left as a second divergent shape (a deviation from the conductor's own suggested stage
   split, made because leaving out-inspection without agent-signing while in-inspection already had it
   would have been real inconsistency, not a deliberate design choice — flagged to her directly at the
   time).
4. Refusal capture + the agent attestation, including `rental_inspections.sign_on_behalf` finding its
   real successor use (gating a refused disposition specifically) after Stage 3's cleanup left it
   dormant.
5. The completion guard replaced on both types (§15.7) and the show-page's unambiguous signed-vs-refused
   rendering (§15.8).

---

## 17. Wet-ink signing — a tenant or landlord who signed on paper (2026-10-01, cc6)

**Status: pushed, awaiting landing.** §15 built three-party, in-person, canvas-capture signing. It never
built a path for a party who signs on a physical page instead — §15.4 named this explicitly as a known,
deliberately deferred gap ("a remote/async signing link... is explicitly OUT of scope for this build").
This section is that gap, built as a THIRD `disposition` alongside `signed`/`refused` — not a second
signing system. It does NOT build §15.4's larger remote/async link (a landlord signing from home before
ever meeting the agent); it builds the narrower, immediately real case: a page someone signed in person
or handed back later, then photographed or scanned into the system by whoever is holding the device.

### 17.1 Why this stays on `RentalInspectionSignature`, not the DocuPerfect e-sign module

Investigated first, per the standing instruction not to build a second signing system if an existing one
fits. DocuPerfect's full e-sign flow (`SignatureRequest`, `WetInkInspection`, `choose-method.blade.php`)
already has a mature wet-ink mechanism — `signing_method`, `wet_ink_upload_path`, a recipient-facing
upload portal, and a staff review step (approved/rejected, with notes). It does not fit here for the same
reason §3.6 already gave for not reusing that flow's signed path: it is keyed to a `SignatureRequest` tied
to a `Template`/`CdsDraft` document ceremony and an externally-tokenized recipient flow. A rental
inspection has neither — there is no document being sent out, and the agent captures everything in
person, on their own device, not via a link sent to the signer. What's reused is the STORAGE discipline
(a real uploaded file, decoded/stored to disk, never inline in the DB) and the "third disposition"
framing DocuPerfect's `wet_ink` signing_method models — not the tables themselves. Named as a [design
call], not assumed silently.

**Consciously NOT built**: DocuPerfect's staff-review step (`WetInkInspection`'s approve/reject). Nobody
asked for a wet-ink upload to be reviewable/rejectable before it counts — an uploaded page is accepted as
the disposition directly, same trust level as a canvas signature or a refusal reason. If a review step is
wanted later, that is DocuPerfect's `WetInkInspection` pattern to borrow from, not something silently
added here.

### 17.2 Data model — three columns added to `rental_inspection_signatures`

```
rental_inspection_signatures  (adds to §15.2's shape)
  wet_ink_upload_path         -- storage path, same properties/{id}/rental-inspection-signatures/
                               --   directory as a canvas signature (one storage location for this
                               --   feature). Required when disposition='wet_ink'. NULL otherwise.
  superseded_at                -- nullable timestamp. Set when this row has been replaced by a
                               --   corrected re-upload. The row is NEVER edited or deleted
                               --   (non-negotiable #1) — it stays, visibly marked, pointing at its
                               --   replacement.
  superseded_by_signature_id   -- nullable, self-referencing FK. The replacement row's id.
```

`disposition` gains `RentalInspectionSignature::DISPOSITION_WET_INK = 'wet_ink'`. Never valid for
`party_role='agent'` — the agent is always present, always §15's live canvas capture; enforced in
`capture()`, the same one factory method that enforces every other invariant in this table (§15.2a).

### 17.3 Superseding — evidence, never edited in place, never destroyed

A wrong or unreadable wet-ink upload is corrected by `RentalInspectionSignature::supersedeWetInk()`:
marks the existing row `superseded_at` (excluded from `capture()`'s duplicate-disposition check and
`RentalInspection::outstandingSignatories()` from that point on — both now filter `whereNull
('superseded_at')`), then calls `capture()` itself to create the replacement — never duplicating its
invariants. Both writes happen in one transaction, so no window exists where a party has zero or two live
dispositions. The old row's file is left on disk; nothing is removed, only marked.

**[design call] Refused once the agent has already signed.** The agent's own signature attests to the
complete record as it stood (§15.2a) — replacing a party's evidence after that point would silently
change what was attested to. Not asked for in this build; correcting evidence on a completed inspection
is a separate, larger amendment mechanism, not this one.

### 17.4 Permission — evidence-backed, not `sign_on_behalf`

`rental_inspections.sign_on_behalf` (§15.5) gates an agent asserting a REFUSAL — a claim with no evidence
but the agent's word. A wet-ink upload is the opposite: it arrives WITH evidence, the scan itself, the
same epistemic weight as a canvas-captured signature. It stays behind only the base
`rental_inspections.create` permission the whole signing endpoint already requires — not gated further.

### 17.5 Rendering — a third shape, never mistaken for the other two

`resources/views/corex/rental-inspections/show.blade.php`'s disposition branch (§15.5/§15.8) gains a
third case: no signature-image markup (never presentable as an e-signature, same principle as a refusal
never being presentable as a signature), a distinct "Signed on paper (wet-ink)" label, a link to the
uploaded page, who uploaded it and when, and — if superseded — a plain note pointing at the replacement
rather than the stale upload. The live recording UI (`rental-inspection-recording.blade.php`) gains a
"Wet ink" button alongside "Sign"/"Refuses" for tenant and landlord rows only, and a "Replace" link on an
already-uploaded wet-ink row, shown only while it is genuinely replaceable (§16.3's guard, mirrored
client-side in `canReplaceWetInk()` so the UI never offers an action the server will refuse).

### 17.6 Files

- `database/migrations/2026_10_01_100000_add_wet_ink_to_rental_inspection_signatures.php`
- `app/Models/RentalInspectionSignature.php` — `DISPOSITION_WET_INK`, `capture()` extended,
  `storeWetInkUpload()`, `supersedeWetInk()`, `supersededBy()`, `isWetInk()`.
- `app/Models/RentalInspection.php` — `outstandingSignatories()` excludes superseded rows.
- `app/Http/Controllers/CoreX/RentalInspectionRecordingController.php` — `storeSignature()` extended,
  new `supersedeWetInkSignature()`.
- `app/Http/Controllers/CoreX/RentalInspectionController.php` — eager-loads `signatures.supersededBy`.
- `routes/web.php` — `corex.rental-inspections.signatures.supersede-wet-ink`.
- `resources/views/corex/properties/partials/rental-inspection-recording.blade.php`,
  `resources/views/corex/properties/partials/rental-inspection-wetink-form.blade.php` (new),
  `resources/views/corex/properties/show.blade.php` (the shared Alpine component's JS),
  `resources/views/corex/rental-inspections/show.blade.php`.

### 17.7 Verification status — stated plainly, not glossed over

The `2026_10_01_100000` migration was NOT run against the shared `corex_qa1` schema by this build — `php
artisan migrate` refuses outright from any worktree that isn't `/corex-qa1` itself (Standard −1g,
enforced in code, not a paragraph to remember). It has been reviewed but not executed against a live
MySQL schema; a full historical-chain replay against a disposable local SQLite database was attempted for
self-verification and got as far as an unrelated, pre-existing MySQL-only migration several months
earlier in the chain (`2026_04_22_110001_make_fica_submission_token_nullable`'s raw `MODIFY` syntax,
nothing to do with this change) before SQLite's more limited `ALTER TABLE` support stopped the replay —
so this migration's own `up()`/`down()` were never executed end-to-end by this build, only reviewed. All
PHP files pass `php -l`; every touched Blade file (including the two new/edited partials) compiles
cleanly via `php artisan view:cache` against this worktree's own independent `vendor/`. The functional
path — the migration actually running, a real signature capture over real HTTP, and the browser console
on the live JS — is unverified by this build and needs proving once landed through `/corex-qa1`. No
browser tool is available in this environment; the console check needs a human or a session that has one.

### 17.8 The real paper form signs in two places, not one — noted, not built (2026-10-01)

Johan sent his agency's actual paper documents (the source for `.ai/specs/rental-inspection-form.md`,
cc5's — this note records the one finding from them that lands directly on §17 and is NOT a duplicate of
that spec). The real out-inspection form has **five** signature slots, not the three this build assumed:
**Landlord** Name + Signature and **two separate Tenant** Name + Signature lines partway through the
document, then, separately, at the foot of the form: **"Inspection done by" + Signature** and
**"Tenant" + Signature** again, with a date. The same tenant is asked to sign twice, in two different
places, for two different things (agreeing to the recorded condition partway through; confirming the
completed document at the foot) — not one signature that this build's single `party_role='tenant'` row
per inspection currently models.

**In the real example Johan sent**: the landlord line is unsigned, the tenant signed at both the mid-form
and foot positions, and the agent signed only at the foot. **A partially-signed inspection — most
concretely, an unsigned landlord — is the NORMAL, everyday shape of a real, usable, already-in-use
document, not an error or incomplete state.** §15.1 already established this in principle ("refusal is
normal, not an error"); this is the concrete evidence that the principle is load-bearing in practice, not
theoretical.

**Not decided here, not built here**: whether the signing model should move to two signing MOMENTS per
party (matching the real form's mid-document + foot structure) rather than one `party_role` row per
inspection, and how that interacts with §16's wet-ink work and the completion guard (§15.7). That is
Johan's call, informed by cc5's `rental-inspection-form.md`, not this section's to pre-empt. Recorded here
so nobody later "corrects" the current single-signature-per-party shape back to what this build already
knew was incomplete, without realizing it was a documented, deliberate pause — not an oversight.

---

## 18. The header block — everything above the room tables (2026-10-01, cc6)

**Status: pushed, awaiting landing.** Johan's real paper form has a substantial header above the room
grading: meter readings, furnished state, property type, keys/remotes handed over, the tenant(s) and
landlord's names, who did the inspection, and — on the out-form — the original move-in date. None of it
was captured before this build; an inspection was a property, a status, a recorder, and an empty
observations list.

### 18.1 Fields, and where each one's value actually comes from

| Field | Shape | Source |
|---|---|---|
| `electricity_meter_reading` | free text, not numeric | fresh each inspection — never defaulted |
| `water_meter_reading` | free text, not numeric | fresh each inspection — never defaulted |
| `furnished_status` | agency-configurable | defaults from `Property::furnished_status` at start |
| `property_type` | agency-configurable | defaults from `Property::property_type` at start |
| `keys_count` + `keys_description` | int + string | fresh each inspection — never defaulted |
| `remotes_count` + `remotes_description` | int + string | fresh each inspection — never defaulted |
| `move_in_date_recorded` | date, TYPE_OUT only | defaults from `Lease::start_date` at start |
| Landlord / tenant(s) / inspecting agent names | display only | `Property::sellerOwnerContact()`, `Lease::tenants`, `RentalInspection::createdBy` — the SAME relations §15's signing block already resolves |

**Meter readings are text, not numbers**, because the real form's example property reads "BODY CORP" for
both electricity and water — a body corporate property has no individual meter, and a numbers-only field
would be unusable there. This is not a defect the number field would later need fixing; it is what the
real document requires from day one.

**Furnished state and property type reuse the existing agency-configurable lists** —
`PropertySettingItem::GROUP_FURNISHED_STATUS`/`GROUP_TYPE`, the exact groups `Property::furnished_status`/
`property_type` already use elsewhere in this codebase, already seeded with sensible, multi-agency-safe
defaults (Unfurnished/Furnished/Part-Furnished; House/Apartment-Flat/Townhouse/Vacant Land/Farm/Commercial
Property/Industrial Property — covering Johan's named House/Flat/Townhouse/Commercial set without a
second, narrower list). **[design call]** — a new rental-specific subset list was considered and rejected:
CLAUDE.md's standing rule against a second mechanism where one already fits applies here exactly the same
way it applied to §16's decision not to reuse DocuPerfect's e-sign tables.

**Keys/remotes/meter readings are deliberately NEVER defaulted, even on an out-inspection where the
in-inspection's own values already exist.** Considered and rejected: pre-filling the out-inspection's
keys_count from the in-inspection's would undermine the exact thing Johan asked for — "capture them as
countable, comparable values" only works if the out-count is a genuine fresh count, not an accepted
carry-forward that could silently mask a real loss.

### 18.2 Landlord/tenant/agent names are never re-typed, never re-stored

Johan: "do not make an agent retype a name CoreX already holds." These three are rendered directly from
existing relations (already resolved for §15's signing block) — no new columns, no snapshot. Unlike
`property_type`/`furnished_status` (which genuinely can differ inspection to inspection and are worth
freezing as a point-in-time fact), a party's NAME is not a fact that needs its own historical copy on the
inspection — the signing block already captures WHO signed, with its own `party_contact_id`, which is the
actual point-in-time record for identity. The header display is confirmation, not a second source of
truth.

### 18.3 Keys and remotes are a comparison input, not header decoration

Coordinated directly with cc5 (building the in-vs-out deposit comparison in a separate worktree,
`cc5-rental-inspection-deposit-comparison`) before landing this shape. Confirmed field names, on
`rental_inspections` directly (one row per inspection event — "in" and "out" are separate rows with their
own values, matching every other fact in this table): `keys_count`/`keys_description`,
`remotes_count`/`remotes_description`, `electricity_meter_reading`/`water_meter_reading`. cc5 reads these
by plain attribute access with no dependency ordering — a column that doesn't exist yet reads as null
through Eloquent, so neither side needed to wait on the other's migration landing first. cc5 confirmed
back: the int-count shape (rather than the free-text field originally sketched in their own spec) gives a
clean, direct signal for the comparison — a numeric drop is unambiguous, no parsing required.

### 18.4 Editable until completion, defaulted once, never after

`RentalInspection::updateDetails()` — every field optional per call, refuses once the inspection is
`completed` or `cancelled` (the completed document is evidence, not a form left open for revision, same
principle §16 applies to a wet-ink upload). Defaults are set ONCE, in `start()`, at inspection creation —
never re-applied or overwritten by a later "refresh from property" action; once an agent has confirmed or
corrected a value, that correction is authoritative until the agent changes it again.

### 18.5 Files

- `database/migrations/2026_10_01_110000_add_header_block_to_rental_inspections.php`
- `app/Models/RentalInspection.php` — new fillable/casts, `start()` defaulting, `updateDetails()`.
- `app/Http/Controllers/CoreX/RentalInspectionRecordingController.php` — new `updateDetails()` action;
  `start()`/`tabPayloadFor()` eager-load `createdBy` for the header's display-only name.
- `routes/web.php` — `corex.rental-inspections.details.update`.
- `resources/views/corex/properties/partials/rental-inspection-recording.blade.php` — the header block
  markup, reusing `$settingItems['furnishedStatuses']`/`['types']` already loaded by
  `PropertyController::show()` for the property's own edit form.
- `resources/views/corex/properties/show.blade.php` — `saveDetailsFor()` and its supporting state.

### 18.6 Verification status

Same limitation as §16.7: `php artisan migrate` refuses from any worktree that isn't `/corex-qa1`
(Standard −1g) — this migration has been reviewed, not executed against MySQL. All PHP passes `php -l`;
every touched Blade file compiles via `php artisan view:cache` against this worktree's own independent
`vendor/`. No browser tool is available in this environment — a real save over HTTP and the browser
console on the live JS are both unverified by this build.

(Note: §17's own subsections above were originally numbered §16.x by their author before the
already-landed Room-type picker / grouping section below claimed §16 for itself; this section is
renumbered §18 for the same reason. See the note ahead of §16 for that section's own history.)

## 16. Room-type picker fix (2026-09-21) — the manual add path never carried a type

**Root cause, found by Johan on a real browser walk of property 5792 (QA1):** the Rental Images tab's
manual "Add" control (§4, the flat add form below the Inspection Items list) let an agent add a Space
with only a free-text label and a `kind` of `space`/`meter` — no room type. `RentalInspectionItem.
space_type` (§3.2) has always existed as a column, but nothing in the UI or
`RentalInspectionRecordingController::storeItem()` ever populated it. `RentalInspectionSetting::
roomTypeItemsFor($agencyId, $spaceType)` — the agency's own configured checklist defaults, built at
`/corex/settings/rental-inspections` (§3.5-adjacent settings screen) — was therefore never reachable
from this path: it needs a real `$spaceType` to key its lookup, and the manual add never had one to
give it. A manually-added space like "Bedroom 1" landed with zero checklist items under it, while
`RentalInspectionFormSeeder::seedFromAdvertising()` (§14.3-adjacent, Stage 2) worked correctly because
it always had a real space type from `spaces_json`.

This was NOT a second "make a feature its own space" gap (an earlier framing this investigation
withdrew) — Parking/Garage/Flatlet etc. were already ordinary, working space types. It was specifically
that the ONE path an agent uses to hand-add a room to the inspection checklist had no type field to
carry to `roomTypeItemsFor()`.

### 16.1 The fix

- The manual add form (`resources/views/corex/properties/show.blade.php`, the Inspection Items panel)
  gained a Room Type `<select>`, rendered server-side from `config('property-spaces.all_space_types')`
  — the authoritative PHP catalog, never the page's own JS copy of that list (a DIFFERENT catalog,
  `feature_categories`, was found drifted from its JS copy earlier the same day; `all_space_types` was
  checked and confirmed NOT drifted, but the picker still renders from PHP directly rather than trust
  the JS copy going forward). Required whenever `kind = space`; not shown for `kind = meter` (a meter
  has no room, per §3.2's own comment).
- `RentalInspectionRecordingController::storeItem()` now validates `space_type` against that same
  catalog (`Rule::in(config('property-spaces.all_space_types'))`) when `kind = space`, then — in one
  transaction — creates a real `PropertyRoom` (type, label, `source = manual`) and generates that room's
  default checklist items via `RentalInspectionSetting::roomTypeItemsFor()`, exactly the same call
  `RentalInspectionFormSeeder` already makes. Both callers now go through one shared private method
  (`createRoomChecklist()`) so they can never diverge into two different item shapes for the same room
  type again. `kind = meter` is unchanged — a bare item, no room.
- **This narrows §3.2's original note** ("space_type... for consistency with the marketing spaces list
  WITHOUT being constrained to it... free-text label always wins display") — that note is still true of
  the item's own free-text LABEL ("Bedroom 1" vs "Bedroom 2" stays free text), but the TYPE used to seed
  a room's checklist must now be constrained to the real catalog, because it is a live lookup key into
  the agency's own configured defaults, not a display string. An unconstrained type would silently miss
  an agency's customisation and fall back to `DEFAULT_ROOM_TYPE_ITEMS` instead.
- **Existing typeless items are not orphaned.** A pre-fix space (`kind = space`, no `property_room_id`,
  no `space_type` — e.g. "Bedroom 1" on property 5792) gets an inline "Give it a room type…" picker
  instead of the normal Retire-only row. Choosing a type and confirming
  (`RentalInspectionRecordingController::assignType()`, `POST .../rental-inspection-items/{item}/
  assign-type`) creates a real `PropertyRoom` from the item's own label, generates that room's default
  checklist through the same shared `createRoomChecklist()`, and RETIRES (never deletes, §3.3) the old
  bare item — any observation history already recorded against it stays exactly where it is and stays
  queryable (`carryForwardItems()`/`fullHistory()` both explicitly include retired items); the new
  room's items become the live checklist going forward.
- Agents can still add, edit (retire), and hand-add further items after either path runs — seeding
  (whether via "Build from advertising details" or a manual add's auto-generated checklist) is a
  starting point, never a cage (Johan, §0 rulings: "agents can add their own on inspections as well").

### 16.2 Multi-agency note

`config('property-spaces.all_space_types')` is the same single, agency-neutral catalog the advertising
Spaces screen already uses for every agency — no change to that contract. What's agency-specific is
*which default items* a given type resolves to, via `RentalInspectionSetting::roomTypeItemDefaultsFor
($agencyId)` — already built, already per-agency, already reached correctly through this fix's shared
`createRoomChecklist()`. No agency-specific type list, wording, or default was introduced.

### 16.3 Room-heading grouping (2026-09-21) — Johan on property 5792

**The problem, in his own words:** "why would we add bedroom 2 such a lot of times - surely that should
be a heading - bedroom 2. then list everything underneath that we have to check in that room?" Property
5792's checklist rendered all 15 facet items flat, each row prefixed with its room's name —
`itemDisplayLabel()`'s `${item.room.label} — ${item.label}` string — so "Bedroom 2" printed once per
each of its 5 facets. Pure repeated metadata, a direct hit on Johan's standing rule that every row of a
working screen must be data the agent needs or a control they act on, not decoration.

**The fix:** both the Inspection Items panel and the In/Out Inspection recording checklist
(`resources/views/corex/properties/show.blade.php`, `resources/views/corex/properties/partials/
rental-inspection-recording.blade.php`) now render `roomGroups()` — one heading per room (`group.room.
label`, printed once), its facet items nested underneath showing their own bare `item.label`. Items with
no room (meters, and legacy spaces still awaiting a room type via §16.1's `assign-type`) land in one
trailing "General" group. `itemDisplayLabel()` is removed — both its call sites are gone, so nothing
else in the app used it.

**The grouping key, and why casing is a non-issue:** `roomGroups()` keys strictly on `item.room.id` —
the real `PropertyRoom` primary key carried through the item's own `property_room_id` foreign key — and
never on `item.room.label`, the free-text display string. Two items either share the same `room_id` or
they don't; the label is read only for display, after grouping has already happened. Johan raised a
real, separate concern — property 5792 has both "bedroom 2" (lowercase) and "Bedroom 1" (capitalised),
since he typed them as free text — and asked us to confirm grouping doesn't key on that string. It
doesn't, and never has: there is no code path anywhere in this feature that compares room names to
decide whether two items belong together. The casing inconsistency is real but purely cosmetic here;
whether existing free-text room names should ever be normalised is a separate, deliberately un-taken
decision — Johan's own data, his call, not touched by this fix.

**Ordering note:** groups sort by `group.room.sort_order` ascending — `PropertyRoom`'s own existing
column. This fix does not change what that column contains or how it's assigned; today that's still
creation/seed order, which is Johan's second, larger complaint (rooms don't line up in a sensible
walking order, and can't be reordered) — tracked separately, not solved by this grouping change. Once a
sensible default and agent-driven reordering land on `sort_order`, this same `roomGroups()` reflects it
automatically, with no further change to either view.

---

## 19. Real item vocabularies for Kitchen/Bathroom/Bedroom/Garage/Yard (2026-09-21)

_Renumbered from cc4's original §18 during landing — §18 was already claimed by "The header
block" (cc6, landed earlier the same night). No content changed, only the heading numbers._

The conductor's own numbers exposed the gap: property 4862 seeded 51 items across 9 rooms — under 6
items/room — against Retha's real kitchen checklist of 18 lines. `RentalInspectionSetting::
DEFAULT_ROOM_TYPE_ITEMS` (§16's five-item flat baseline — Ceiling/Walls/Floors/Windows/Doors) was being
applied identically to every space type with nothing more specific, including the highest-traffic ones.
"We were seeding a skeleton and calling it a checklist."

**The fix:** a new `DEFAULT_ROOM_TYPE_ITEMS_BY_TYPE` map on `RentalInspectionSetting`, transcribed
directly from Retha's real form for exactly five types — Kitchen (18 items), Bathroom (15), Bedroom
(14), Garage (7), Yard (6). `roomTypeItemDefaultsFor()` now looks up this map first and only falls back
to the generic five-item baseline for a type not in it. Transcribed as given, not assumed to be a
superset of the old baseline — her Bedroom has Walls and Ceilings but no separate Floors/Windows/Doors
lines at all, so those genuinely are absent from the system default for Bedroom now, where they weren't
before.

**This is a SYSTEM default, not agency 1's.** `array_merge($defaults, $overrides)` — the existing
mechanism, unchanged — always lets an agency's own `customRoomTypeOverridesFor()` entry win outright for
that type. Checked directly before shipping: agency 1 (Johan's own) has **already customised Kitchen,
Bathroom, Bedroom, and Garage itself** (his own, thinner lists — Bedroom is saved as literally just the
plain five-item baseline). This change is therefore invisible on agency 1 for those four types — his own
saved lists keep winning, exactly as the "never overwrite an agency's configured list" rule requires.
The one type on agency 1 this visibly changes is **Yard**, which Johan has never customised, and any
future agency (Cape Town, October) that hasn't touched these types yet gets Retha's real vocabulary as
its starting point instead of the bare baseline. If Johan wants his own Kitchen/Bathroom/Bedroom/Garage
brought up to Retha's fuller lists, that's a separate, explicit action on his own saved settings — not
implied by this change.

**Already-seeded properties are untouched, structurally, not just by convention.** `RentalInspectionItem`
rows are persistent database records created once by `RentalInspectionFormSeeder::seedFromAdvertising()`
(§2, one-time guarded on `rental_inspection_form_seeded_at`) or by the manual add path (§16) — neither
path re-reads the settings default after creation. Confirmed directly, not assumed: property 4862's
active item count was 51 before this change and 51 after; 2061 was 46 and 46; 4954 was 47 and 47.

### 19.1 Two items recorded as open, not resolved (2026-09-21, conductor's ruling)

Converting agency stock (`LegacySpacesJsonConverter`, this session) surfaced old `features_json` keys
with no home in the new `{spaces, features}` shape. All of it survives untouched in every affected
property's `spaces_json_legacy_backup` column — nothing is lost — but two of the keys found are
deliberately **left unmapped and unactioned**, not fixed tonight:

- **`show_location`** (a map-visibility toggle, ~754 properties in the pre-conversion data). Confirmed
  this is not purely historical: `P24ListingsCsvParser.php:78` still writes it into `features_json` on
  *current* P24 imports. Backfilling only the historical shape while the importer keeps producing the
  old shape going forward would just mean new imports land wrong again immediately — this is a decision
  about the importer, not a data-conversion backfill, and is explicitly not being made here.
- **`age`** (building age in years, ~345 properties). No existing column or clear destination identified
  for it in either the spaces/features shape or elsewhere on `Property`. Left alone pending a real
  decision on where it belongs.

Other unmapped keys found in the same scan (`beds_description` and four sibling `*_description` fields,
`deposit_requirements` — which duplicates the real, currently-empty `properties.deposit_amount` column
— and a third `features_json` shape entirely, a plain array of catalog-matching feature label strings)
were reported in full at the time but are not ruled on in this document; see the session record rather
than assuming silence here means resolved.

### 16.4 Room walking order (2026-09-21) — Johan on property 5792, continued

**The problem, in his own words:** "then we can also allow sorting of rooms. currently it just adds
rooms at the bottom. but theres no logical way to line up the rooms as the inspection goes. Im pretty
sure bedroom 1, bedroom 2, etc would be a logica sort order?" — plus his explicit correction after
seeing the first cut of this design: grouping alone would have left 5792 reading Bedroom 2 / Study /
Bedroom 1 forever, because existing rooms correctly keep whatever `sort_order` they already have (never
silently recomputed by a deploy). The fix needed three parts together, not one:

1. **A sensible, agency-configurable default order for NEW rooms.** `RentalInspectionSetting::
   DEFAULT_ROOM_TYPE_WALKING_ORDER` — every one of `config('property-spaces.all_space_types')`'s types
   (verified 1:1, no gaps, no extras), bucketed: Entrance & Reception, Living & Social, Kitchen &
   Domestic, Bedrooms & Private, Bathrooms, Outside & Leisure, then Utility/Storage/Vehicle — matching
   Johan's own stated order ("entrance/reception first, living spaces, kitchen, bedrooms, bathrooms,
   then outside spaces"), with every type Johan didn't name placed in the most defensible remaining
   bucket. `RentalInspectionSetting::roomTypeWalkingOrderFor($agencyId)` is the read-time-default
   resolver (same pattern as every other setting on this model); `room_type_walking_order` (new JSON
   column, migration `2026_09_21_150000_...`) is the agency's own override — a FULL reordering of every
   type, not a sparse list. A saved order that predates a later catalog addition gets the missing
   type appended automatically, in the default order's own relative position — never left unsortable.
   Editable at `/corex/settings/rental-inspections` (up/down controls, `updateRoomTypeWalkingOrder()`).

   **Ruling — Setup Wizard exemption, granted 2026-09-21:** same reasoning already applied to
   `refusal_reason_presets` (§15.6) — a full-permutation reorder of every space type has no fitting
   wizard control type (number/select/text/textarea/toggle). Deliberately NOT in the wizard.

   **Update, 2026-09-30 (owner's ruling):** the refusal-reason presets, condition states (+ baseline),
   photo-note classifications and inventory condition states ARE now in the Setup Wizard (Rentals step,
   `agency-setup.steps.rentals-inspection-lists` partial, reusing the settings screens' repeater markup)
   — see `agency-onboarding-setup.md` §5.1. The room-type walking order above is NOT covered by that
   ruling and stays out of the wizard.

2. **Natural-numeric tiebreak within a type, computed, never stored as a sort key.**
   `RentalInspectionSetting::defaultRoomSortOrderFor($agencyId, $type, $label)` = `(walking position ×
   1000) + min(first number found in $label, 999)`. Johan: "natural-numeric, NOT alphabetical:
   alphabetical gives 1, 10, 2." No sibling-room query needed — the tiebreak reads only this room's own
   label text. A label with no number (e.g. bare "Study") sorts first within its type. Wired into both
   `storeItem()` and `assignType()` in place of the old `max(sort_order) + 1` append-to-the-end counter.

3. **Existing rooms are never silently recomputed — but the agent gets both an explicit one-click fix
   AND manual control.** `POST .../rental-inspection-rooms/apply-default-order`
   (`RentalInspectionRecordingController::applyDefaultRoomOrder()`) recomputes every one of a property's
   EXISTING rooms through the exact same `defaultRoomSortOrderFor()` formula, agent-triggered, one
   click, idempotent — this is 5792's actual fix. `POST .../rental-inspection-rooms/reorder`
   (`reorderRooms()`) takes the agent's own full ordering of the property's room IDs and rewrites
   `sort_order` to match exactly — up/down controls on each room heading in the Inspection Items panel.
   Both write the SAME column `roomGroups()` (§16.3) already sorts by, so neither view needs its own
   change to reflect either action. Johan: "the button is a starting point, the same way seeding is a
   starting point" — an agent can always reorder further by hand afterwards.

   **Known, deliberate simplification:** a manual reorder (`reorderRooms()`) assigns plain `0..N-1`
   positions to the rooms it's given, a different numeric range than the default-order formula's
   `position × 1000 + tiebreak`. A brand new room added after a manual reorder lands wherever its own
   type's walking position computes to, which can in principle interleave into the middle of an
   agent's hand-curated order rather than always appending at the end. Not solved here — flagged as a
   known interaction, not a silent gap, since Johan's own framing of manual reorder as "a starting
   point, not the be all and end all" means an agent re-checking/re-nudging order after adding a new
   room to an already-hand-ordered property is an acceptable, expected step, not a defect.

**Ruling — `PropertyRoom.sort_order` reuse, approved 2026-09-21.** This column was documented (its own
migration's docblock, §3.2-adjacent) as "also used by sales" as a future possibility; nothing in Sales
currently reads or writes it (verified by search), and it is already the exact field the room-type-
picker fix (§16.1) populates for precisely this ordering purpose. Adding a second parallel ordering
column would have been worse. Logged here for Johan and Andre's record: **inspections now drives this
column** — a future Sales feature that also wants to order rooms needs to either share this same
ordering or coordinate before introducing a second, conflicting one.

**Multi-agency note:** `DEFAULT_ROOM_TYPE_WALKING_ORDER` is one neutral starting order shared by every
agency — same architecture as `DEFAULT_ROOM_TYPE_ITEMS` and `DEFAULT_INSPECTION_FEATURE_LABELS`. No
agency's wording, branding, or a single hardcoded order is forced on another agency; every agency edits
its own copy from here.

**Two things from Johan's real paper documents (2026-09-21) that confirm/bear on this work — noted here
as evidence, not acted on further; cc5 owns `.ai/specs/rental-inspection-form.md`, the spec for whatever
the recording screen becomes once Johan rules on rebuilding it to match those documents:**

1. **Natural-numeric ordering within a type is the common case, not an edge case.** Johan's real room
   list for one flat: Kitchen, En Suite Bathroom, Main Bedroom, Bedroom 1, Bedroom 2, Bedroom 3,
   Bedroom 4, Bathroom 1, Garage, Yard — ten rooms, four of them numbered bedrooms. Confirms
   `defaultRoomSortOrderFor()`'s natural-numeric tiebreak (§16.4 point 2) was the right call, not
   over-engineering for a rare case.
2. **The inspection and the inventory do not share a room list.** His inventory document covers rooms
   his inspection doesn't: Sunroom, Rubbish bin room, Entrance from glass front door, Dining
   room/Balcony, Lounge, Laundry Room, Outside front of house. If an inventory feature is ever built on
   top of `PropertyRoom` (already noted, §3.2-adjacent, as a table Sales may also use), it must not
   assume it inherits whatever rooms an inspection happens to have — the two are separate room sets in
   Johan's own real usage, not one list viewed two ways.

### 16.5 Real-world bug found live on property 5792 — a missing stability tiebreak, not a casing bug

Johan pressed "Apply default order" on 5792 himself and watched "bedroom 2" (lowercase, created first)
keep sorting ahead of "Bedroom 1" (capitalised, created second) — the exact case his own correction to
Problem 3 (§16.3) had flagged as worth checking. Investigated directly rather than assumed:

- **Case sensitivity: ruled out.** `defaultRoomSortOrderFor()`'s natural-numeric extraction
  (`preg_match('/(\d+)/', $label, $matches)`) matches digits, which have no case — verified directly by
  calling it against the live agency: `defaultRoomSortOrderFor(1, 'Bedroom', 'bedroom 2')` → `15002`,
  `defaultRoomSortOrderFor(1, 'Bedroom', 'Bedroom 1')` → `15001`. Bedroom 1 already sorted correctly
  before bedroom 2 in isolation — casing was never the mechanism.
- **Silent fallback to the old sort_order: ruled out.** The function always computes a fresh value from
  the walking position and the label's own digits; it never reads the room's existing `sort_order` at
  all, so there is no path back to stale creation-order values.
- **What was actually wrong, found by executing `applyDefaultRoomOrder()` directly against property
  5792's real data:** the formula and the controller were both already correct — running the exact
  deployed method fixed 5792's real rows immediately (`Bedroom 1` → `15001`, `bedroom 2` → `15002`,
  confirmed by direct query). The real, separate defect was requirement #3's own concern: **no secondary
  tiebreak existed anywhere sort_order was used to order rooms** — neither in the PHP queries returning
  a property's rooms (`RentalInspectionRecordingController.php`'s three `PropertyRoom::...
  ->orderBy('sort_order')` call sites) nor in the client-side `roomGroups()` sort
  (`show.blade.php`) that actually drives what an agent sees. Two rooms of the same type that both lack
  a number, or share one, resolve to the identical `sort_order` — and without an explicit tiebreak,
  their relative order is whatever MySQL/the array happens to return, which is not guaranteed stable
  across requests. Fixed by adding `id` as the secondary sort key everywhere — `orderBy('sort_order')
  ->orderBy('id')` in every affected PHP query, and `(a.room.sort_order ?? 0) - (b.room.sort_order ?? 0)
  || (a.room.id - b.room.id)` in `roomGroups()` — matching the box-wide convention already used for
  exactly this reason elsewhere (`Contact.php:275`, `RentalInventory.php:71,77`,
  `ProformaInvoice.php:48`, and others).
- **Why 5792 appeared unchanged when Johan clicked:** resolved, and it was not a code bug. cc1 was
  working the same property in the same window and ran a manual reorder that landed inside Johan's own
  fourteen-minute test — he pressed "Apply default order," cc1's manual reorder persisted moments later,
  and the order Johan read back afterward was cc1's deliberate reorder, not a failure of the button.
  cc1 re-pressed the same button on the same property independently afterward and confirmed Bedroom 1
  sorted ahead of bedroom 2 correctly. No code changed as a result of this half of the report — nothing
  needed to. Recorded here so the "two lanes changing the same property's data at the same time look
  like a contradiction" lesson isn't lost: say so in the shared channel before changing state on a real
  property.

### 16.6 The real bug — cc1 found it testing live, and it is fixed

cc1's own test on 5792 surfaced a genuine, distinct defect in `defaultRoomSortOrderFor()`: the original
`preg_match('/(\d+)/', $label)` matches the FIRST digit anywhere in the label, not a trailing room
number. A leftover test room labelled "Bedroom CC1 Verify" resolved to the identical `sort_order` as a
real "Bedroom 1", because the regex matched the "1" inside "CC1". Harmless against Johan's own clean
labels today ("Bedroom 1", "bedroom 2") — but a real bug the moment any agent types a label with an
incidental digit anywhere in it: a unit number, a floor, "Flat 2 Bedroom", "Garage B1", an agency's own
naming convention. Not exotic — expected, ordinary usage.

**Fix:** anchored the regex to the END of the label — `preg_match('/(\d+)\s*$/', $label, $matches)` —
so a genuinely trailing instance number ("Bedroom 1", "Garage B1") is still read correctly, while an
incidental digit earlier in the label ("Flat 2 Bedroom", "Bedroom CC1 Verify") is correctly ignored and
falls back to the untrailing-numbered tiebreak (0), same as a label with no number at all. Verified
directly against every named scenario: `Bedroom 1`→15001, `bedroom 2`→15002, `BEDROOM 10`→15010,
`Bedroom CC1 Verify`→15000 (no longer collides with `Bedroom 1`), `Flat 2 Bedroom`→15000, `Garage
B1`→correctly reads trailing `1`. Two new regression tests
(`RentalInspectionFeatureAndRoomTypeSettingsTest.php`) lock in the incidental-digit case and the
trailing-after-letter case directly. §16.5's `id`-tiebreak fix is unaffected and still required — two
labels that both fall back to 0 (no trailing number) still need it to stay stable.

Room names are never normalised or rewritten by this fix — the regex only reads the label to compute a
sort position; Johan's ruling on whether room names should ever be normalised remains open and untouched.

## 17. N/A, room notes, overall notes (2026-09-21) — three gaps evidenced on Retha's real paper form

Johan corrected an earlier read of his own: `/corex/rental-inspections/1` is a thin read-only summary of
an empty draft, not the actual recording surface. The real recording screen — property 5792, Rental
Images tab, In Inspection expanded — already had room groupings (§16.3), per-item condition grading,
notes, photos, and three-party signing (§15) working. Comparing that working screen against Retha's two
real paper documents (a filled-in out-inspection and a separate inventory) surfaced three concrete gaps,
all directly evidenced on her form, no design ruling needed:

### 17.1 N/A as a condition state, agency-configurable vocabulary

**The problem, in Johan's own words:** "we have 'Missing', which means it should be here and isn't —
that is a deposit argument. Retha needs 'was never here', which is not an argument at all. Two
completely different meanings and we can only say one of them." Her paper form uses N/A constantly
("Ceiling Fans N/A", "Blinds N/A") and strikes entire rooms out with one N/A across the whole table
(Bedroom 3, Bedroom 4 on her real form).

**The fix:**

- `RentalInspectionSetting::DEFAULT_CONDITION_STATES` — the shipped six (Good/Fair/Damaged/Not
  working/Missing/Other) plus N/A, each entry `{key, label, requires_notes}`. `requires_notes`
  generalizes §0.3's old hardcoded "anything but Good needs a reason" rule — N/A needs none either
  (Johan: "not an argument at all"), and the rule now expresses correctly for an agency that reduces or
  renames the whole set.
- **The SET itself is agency-configurable, not just an addition to ours.** Retha's own paper form uses
  an entirely different vocabulary — Good / OK / Bad — from CoreX's shipped Good / Fair / Damaged / Not
  working / Missing / Other. Neither is forced on the other agency: `condition_states` (new JSON column
  on `rental_inspection_settings`, migration `2026_09_21_160000_...`), resolved via
  `RentalInspectionSetting::conditionStatesFor($agencyId)` (read-time default, same pattern as every
  other setting on this model) and `conditionRequiresNotesFor($agencyId, $key)`. Editable at
  `/corex/settings/rental-inspections` — add/remove/relabel/retoggle any row; an existing row's `key` is
  carried as a hidden field, never re-derived from its label, so it can't drift out from under
  observations already recorded against it.
- `RentalInspectionRecordingController::storeObservation()` validates `condition` against the agency's
  own resolved key list (`Rule::in(array_column(...))`), not a hardcoded six-item enum; the "needs a
  reason" check calls `conditionRequiresNotesFor()` instead of a literal `!== 'good'` comparison.
  `RentalInspectionObservation::requiresNotes()` (previously dead code, unused anywhere in `app/`) now
  delegates to the same resolver, so the concept has exactly one implementation.
- The recording UI's condition `<select>` (`rental-inspection-recording.blade.php`) now renders from
  `condition_states` (delivered through `RentalInspection::tabPayloadFor()`), never a hardcoded set of
  `<option>` tags — an agency that reduces to three states sees exactly three options, not CoreX's six
  plus theirs.
- **Room-level bulk N/A** — "she strikes ENTIRE ROOMS out with one big N/A." New endpoint
  `POST /corex/rental-inspections/{inspection}/rooms/{room}/mark-na`
  (`RentalInspectionRecordingController::markRoomNa()`) records N/A against every active item in the
  room through the exact same atomic `RentalInspectionObservation::record()` path a single-item
  observation uses — a genuine conflict with an earlier, different observation on the same item in the
  same inspection still raises a real discrepancy (§0.4), exactly as it should; this is a bulk
  convenience over the one real recording path, never a second one. Only offered in the UI when N/A is
  actually one of the agency's configured states — an agency that removes it loses the bulk button too.

### 17.2 Room-level notes, in addition to per-item notes

**The problem:** every room table on Retha's paper form carries its own free-text notes box holding
evidence that belongs to the whole room, not any single item — "3x nails in wall", "can't test aircon no
batteries", "1x key in door", "damp under windows in corner", "damp on wall under mirror". Per-item notes
(`rental_inspection_observations.notes`) are finer-grained and stay exactly as they are — this is in
addition, never instead.

**The fix:** new `rental_inspection_room_notes` table (migration `2026_09_21_160100_...`) and
`RentalInspectionRoomNote` model, scoped to `(rental_inspection_id, property_room_id)` — never to
`PropertyRoom` itself, which is a permanent, cross-tenancy record (§3.2) with no natural home for "what
one specific walkthrough found in this room." Immutable, same convention as an Observation (§3.3): never
edited, never deleted (`UPDATED_AT = null`); a correction is a NEW row, and "the room's current note" is
simply the latest one for that inspection — the exact same pattern an item's `currentObservation()`
already uses. New endpoint `POST /corex/rental-inspections/{inspection}/rooms/{room}/notes`
(`storeRoomNote()`); rendered as one textarea + Save button per room heading in the recording UI.

### 17.3 Overall notes — one free-text summary per inspection

**The problem:** Retha's paper form ends with a single summary line: "OVERALL - APARTMENT CLEAN - FAIR -
PARTIALLY FURNISHED."

**The fix:** `overall_notes` — a plain, nullable TEXT column directly on `rental_inspections` (migration
`2026_09_21_160200_...`), not an append-only history like §17.2's room notes. `RentalInspection` is
already a mutable lifecycle record (`status`, `cancel_reason`, etc., all plain columns) — this is the
inspection's own editable summary, not an evidentiary per-event fact, so a plain column with its own
small update endpoint (`POST /corex/rental-inspections/{inspection}/overall-notes`,
`updateOverallNotes()`) fits the existing shape rather than inventing a new one. Rendered as one textarea
+ Save button at the foot of each section's checklist.

### 17.4 Multi-agency note

None of the three additions assume Retha's, HFC's, or any single agency's vocabulary or wording. The
condition-state SET is fully agency-configurable with a neutral default (§17.1); room notes and overall
notes are freeform text fields with no agency-specific default content at all.

### 17.5 Scope discipline

Johan was explicit: these three are directly evidenced on his real paper documents and needed no design
ruling — build them now. Everything else visible on Retha's documents (whether the recording screen gets
a broader rebuild to match her form's full shape) is Johan's decision, still pending, and is cc5's
`.ai/specs/rental-inspection-form.md` to own — not touched or anticipated here.

---

## 20. Inspections-tab rebuild (2026-09-22, cc2) — kill the 90-click walk, one-tap condition, autosave

**Why:** Johan, ahead of a client demo: "looks shit, cannot see anyone wanting to use it. it will become
a dead feature." Root cause named directly: every item had its own Save button — a 15-room property
meant roughly 90 clicks to record a walkthrough. This section rebuilds the recording surface (§4/§14/§17/
§18) to remove that friction, without changing the underlying data model (§3) or the API contract
(§14.2) at all — every endpoint listed below already existed before this rebuild; only the client that
calls them changed.

### 20.1 Tab renamed

"Rental Images" → **"Inspections"**, label and tab-key only (§4's amendment note). No table, column,
route, or model renamed.

### 20.2 Autosave — no per-field Save button

Condition, item notes, room notes, overall notes, and the header block (meter readings, furnished
status, keys/remotes, move-in date) all persist on change with a debounce (700ms for the header block,
800ms for free-text notes) — no button. One condition is skipped: **signature capture buttons stay
explicit clicks** — signing is a deliberate once-per-party action, not a per-field save, and was never
part of Johan's "90 clicks" complaint.

The contract did not need to change to make this possible: `POST .../details`, `.../observations`,
`.../rooms/{room}/notes`, and `.../overall-notes` already accepted a full or partial payload and
already returned the updated record — autosave just calls them on a timer instead of on a click.

One consequence worth recording plainly: because observations and room notes are append-only/immutable
(§3.1/§17.2 — never edited, only superseded by a new row), a user who keeps editing an already-recorded
item's notes after the fact creates a NEW observation row each time a debounce settles, not an edit to
the first one. This was already true under the old explicit-Save-button flow (clicking Save again did
the same); autosave does not introduce a new class of behaviour, it just removes the click that used to
gate it.

**One quiet indicator for the whole screen** (`saveState`, top-right of the tab) shows Saving…/Saved/
failed — never a per-row indicator. A failed save stays visible with a working Retry that re-runs
exactly the save that failed (never the whole form), so nothing is silently lost.

### 20.3 One-tap condition

The per-item `<select>` is replaced with inline buttons, one per
`RentalInspectionSetting::conditionStatesFor()` state, rendered left-to-right in the agency's configured
order — never a hardcoded list. A state with `requires_notes` shows/keeps the notes field required
before the tap counts as recorded (autosave fires once notes are non-empty); a state without
`requires_notes` commits immediately on tap.

### 20.4 Duplicate condition text removed

The old right-aligned grey condition label (duplicating the same fact the select already showed) is
deleted — the selected condition button is now the only place the current condition is shown.

### 20.5 "All Good" bulk-fill — new agency setting, two new endpoints

New column `rental_inspection_settings.baseline_condition_key` (migration
`2026_09_22_090000_...`), resolved via `RentalInspectionSetting::baselineConditionKeyFor()`: honours a
saved key only if it still names one of the agency's own configured condition states, otherwise prefers
a state with `requires_notes = false` (defaulting to `'good'`) so a one-tap bulk action can never stall
waiting on typed notes. Editable on the existing `/corex/settings/rental-inspections` condition-states
form (same page, same saver, `updateConditionStates()`) — **not yet wired into the Setup Wizard**; see
§20.9's FOUND-NOT-FIXED entry.

Two new endpoints, both reusing `RentalInspectionObservation::record()` — the same atomic
observation-plus-discrepancy-detection path every other observation goes through, never a second one:
- `POST /corex/rental-inspections/{inspection}/rooms/{room}/mark-good` — every item in the room with no
  observation yet on this inspection.
- `POST /corex/rental-inspections/{inspection}/mark-all-good` — every item on the whole inspection with
  no observation yet on this inspection.

Both skip any item already recorded this inspection (server-enforced, not just client-side) — an
agent's own entry is never overwritten. Reversible the same way any observation is: recording a
different condition on that item afterward simply becomes the new current fact (§3.1).

### 20.6 Photos visible

Each item shows its photo count and the most recent thumbnail once it has any (from
`observation.photos`, already eager-loaded by `tabPayloadFor()` — no new table); clicking opens the
existing image viewer already used elsewhere on this tab. An item with zero photos shows only the
camera control. A photo picked before an item has a committed observation is staged client-side and
uploaded automatically once one exists; a photo picked against an already-recorded item uploads
immediately against its latest observation, via the same existing photo endpoint either way.

### 20.7 Progress and collapse

Each room heading shows `recorded/total` plus a photo count; the whole inspection shows one overall
`recorded/total` on the In/Out Inspection section heading itself. A room where `recorded === total`
collapses to its heading line automatically; clicking the heading always toggles it open again
regardless of that computed default, so a finished room stays reviewable/correctable. **Amended
2026-09-22 — see §20.11**: the first landing shipped the toggle without a visible affordance and left
the per-room bulk buttons showing on a room with nothing left to fill; both are fixed there, alongside
the actual blocking bug this report also surfaced.

### 20.8 Property type — read-only, derived from Property

Per Johan's ruling ("the property already knows it"), the property-type `<select>` in the header block
is removed. The header block now displays `Property::property_type` read-only. The per-inspection
`rental_inspections.property_type` column (set once at `RentalInspection::start()`, per §18) is
unchanged and still exists as a historical snapshot — it is simply no longer client-editable or
displayed; `updateDetails()` still accepts it in its validation array for backward compatibility, but
nothing sends it any more.

### 20.9 FOUND, NOT FIXED (scope-locked, reported not changed)

- **`RentalInspectionSetting`'s existing settings — `condition_states`, `room_type_item_defaults`,
  `room_type_walking_order`, `refusal_reason_presets`, `inspection_feature_labels`, and now the new
  `baseline_condition_key` — are still not wired into the Setup Wizard** (`config/agency-onboarding-copy.php`),
  contradicting CLAUDE.md non-negotiable §10a ("a setting that exists only on the settings page is not
  done"). This gap predates this build (already flagged once, §3.5, for the two window fields — the rest
  of the model's fields were never wizard-wired either). Adding one more field to an already-flagged gap
  is consistent with existing precedent, not a new violation, but the underlying gap is real and is
  Johan's call on priority, not silently fixed here under today's demo deadline.
- **`rental_inspection_item_findings`** — an orphaned table flagged by cc1 during independent
  verification of an earlier, unrelated change on this same tab. Not part of this rebuild; not touched.

### 20.10 Explicitly not built today (per instruction)

Printable tick-box form, scan mark-reading (OMR), the mobile/API layer beyond what §14.2 already
specifies, ad-hoc inspection types, and the in-vs-out side-by-side comparison view — none of these were
started, scaffolded, or prepared for in this rebuild.

### 20.11 Regression fix, same day (2026-09-22) — "none of the conditions can be clicked"

Johan opened the landed §20 build and every condition button appeared dead. Two real, distinct bugs,
both confirmed in code before fixing, not guessed:

1. **The actual blocking bug**: `:disabled="obsBusy[_obsKey(section, item.id)]"` — an inline
   bracket-lookup on a dynamically computed key, bound directly in the template — rendered every single
   condition button on the page permanently disabled (105/105 on the test property, confirmed via a
   real headless-browser count, both before and after any interaction), even though `obsBusy` itself was
   genuinely empty. Every OTHER per-item lookup on this same surface (`selectedConditionFor()`,
   `itemPhotosFor()`, etc.) already went through a plain method call rather than an inline bracket
   expression and was never affected. Fix: added `isObsBusy(section, itemId)` — a method wrapping the
   identical lookup — and pointed the one affected `:disabled` binding at it
   (`resources/views/corex/properties/show.blade.php`,
   `resources/views/corex/properties/partials/rental-inspection-recording.blade.php`). Verified
   directly: disabled-button count on the same test property went from 105/105 to 0/105 after the fix,
   with no other change.
2. **The collapse trap Johan also named**: a fully-recorded room auto-collapsed (§20.7) with no visible
   way back in — the toggle itself (a bare 12px chevron + text, no hover state) had no affordance, and
   the still-visible "All Good"/"Mark room N/A" buttons on a fully-recorded room did nothing when
   clicked (nothing left to fill), which read as "nothing here is clickable" on its own. Fixed at the
   class level: the whole heading row is now one visibly-interactive click target (hover background,
   `cursor:pointer`); "All Good"/"Mark room N/A" are hidden once a room has nothing left to fill, rather
   than shown doing nothing; a new "Expand all" / "Collapse all" control opens or closes every room in
   a section in one action. Applies identically to In and Out Inspection — both render through the same
   partial, parameterized only by `section`.

**Collapse plugin, confirmed, not assumed**: no `@alpinejs/collapse` package in `package.json`, no
`Alpine.plugin(...)` registration anywhere in `resources/js/`. `x-collapse` resolves to Alpine core's
own inert stub (`node_modules/alpinejs/dist/cdn.js:3445,3450` —
`directive('collapse', el => warn(...))`) — confirmed by reading Alpine's source directly: it only
`console.warn`s and returns; it does not block, delay, or otherwise interfere with any other directive
(including `x-show`) on the same element. It was NOT the cause of either bug above (`x-show` toggling
worked correctly in every direct test), but it is genuinely dead weight — dropped from this rebuild's
own room-body toggle in favour of a plain `x-show`, per explicit instruction not to install a plugin as
part of this fix. **Found, not fixed**: the same inert `x-collapse` is used in 9 other places across
`show.blade.php` alone (readiness/marketed-elsewhere panels, the Info sub-sections, the outer
Inspection Items/In/Out Inspection/custom-section accordion toggles, and one property-history panel) and
in 10 files app-wide — none block their own `x-show`, matching this investigation's finding, but every
one of them is carrying a directive that does nothing and reads as if it should. Not touched here —
outside this fix's scope.

### 20.12 Regression fix, same day (2026-09-22) — discrepancy banner grew on every recorded condition

Johan, live during his demo, on a fresh property whose rooms he was recording normally: a pink banner
appeared between the header block and the room list reading `undefined: good vs n_a vs good vs n_a`,
with a radio per value and a "Resolve" button — growing by one option every time he tapped a condition.
Investigated and confirmed in code before any change, per instruction:

1. **The real bug — `app/Models/RentalInspectionDiscrepancy.php:94-124` (`detectFor()`)**. Two or more
   observations on the same item, in the same inspection, with different conditions were treated as a
   conflict needing human resolution, with **zero regard for who recorded them**. §20.2's autosave made
   re-tapping a different button on the same item trivially easy — every self-correction (a normal act,
   the model's own §3.1 "current condition is a query, never a column" principle already implies
   latest-wins) was indistinguishable from two different people genuinely disagreeing (§0.4, the
   mechanism's actual, original purpose). Confirmed via `git log`: this file is untouched since
   `cc0b72d19`, "Stage 1 — data model," 2026-09-15 — pre-existing, not introduced by either of my two
   branches; my rebuild just made the trigger constant instead of rare.
   **Fix**: `detectFor()` now excludes an earlier observation from "conflicting" if it shares the same
   author (`observed_by_user_id` or `observed_by_contact_id`) as the new one — `sameAuthor()`,
   `RentalInspectionDiscrepancy.php:150-163`. Two DIFFERENT agents (or an agent and a self-reporting
   tenant) disagreeing still raises a real discrepancy exactly as designed — unaffected.
2. **The "undefined" label — `app/Models/RentalInspection.php:499-506` (`tabPayloadFor()`)**. The banner
   read `discrepancy.observations[0]?.item?.label`, but `discrepancies.observations` was eager-loaded
   without a nested `.item` — a separate relation path from the top-level `observations.item`, which
   never inherited it. Always undefined on this data path since the discrepancy-resolution UI was built;
   also pre-existing, also untouched by either of my branches.
   **Fix**: the banner now reads `discrepancy.item?.label` directly — the discrepancy's own `item()`
   relation, matching the pattern the agency-level list screen's detail view already uses
   (`$discrepancy->item?->label ?? 'Unknown item'`,
   `resources/views/corex/rental-inspections/show.blade.php:98`) — more robust than depending on an
   array's first element, and `discrepancies.item` is now eager-loaded alongside it.
3. **Out Inspection confirmed, not assumed**: both fixes sit in the shared model layer
   (`RentalInspectionDiscrepancy`, `RentalInspection::tabPayloadFor()`) and the one partial both sections
   render through — there is no separate code path for Out Inspection to have missed. The existing
   `test_matching_observations_across_different_inspections_do_not_conflict` test already proves
   `detectFor()` scopes strictly by `rental_inspection_id`, so this fix cannot leak between an
   inspection's in and out events either.

**Test fixture debt found and fixed alongside**: eleven existing tests across three files
(`RentalInspectionRecordingControllerTest` ×5, `RentalInspectionDataModelTest` ×5,
`RentalInspectionWorkflowTest` ×1) set up their "two conflicting observations" fixtures using the SAME
acting agent for both — which the fix now correctly treats as a non-conflict, so they'd have failed (a
route-generation error for two of them, since the discrepancy they expected to route to was never
created; a plain assertion failure for the rest). Updated to use a genuinely different second agent for
the conflicting observation, matching what those tests were actually meant to prove (§0.4's real,
multi-agent scenario). Two new tests added (`RentalInspectionRecordingControllerTest`,
`RentalInspectionDataModelTest`) asserting the opposite: the SAME agent recording a different condition
on the same item does NOT create a discrepancy.

**QA1 data cleanup**: one real discrepancy existed on property 5792 / inspection 14 (item 249) at the
time of this fix, mixing one original test-fixture observation (user 144) with several of my own testing
taps (user 22) — resolved directly (accepting the latest recorded condition), not deleted, with a note
recording why. Not a blanket cleanup script — checked first, found to be the only unresolved discrepancy
on QA1, resolved by hand.

**Two more stale tests found running the wider net this fix required, both unrelated to today's actual
bug — one fixed, one reported, not fixed:**
- `RentalInspectionListScreenTest::test_the_list_screen_can_start_a_new_inspection` asserted a redirect
  to `?tab=rental-images` — stale from §20.1's tab rename (`rental-images` → `inspections`), an earlier,
  already-landed change. Fixed (one-line, matches already-shipped behaviour, zero risk).
- `RentalImagesTabRendersTest::test_rental_images_tab_renders_with_items_and_an_active_lease` asserts
  `assertSee('Record…')` against a fixture that creates an item but never starts an inspection.
  `Record…` was the old per-item condition control's placeholder text from before the recording UI was
  restructured to gate all condition controls behind an active in/out inspection
  (`<template x-if="currentInspection(section)">`) — this fixture's setup predates that architecture and
  the assertion no longer matches anything the current UI would ever render for it. **Not fixed** —
  requires deciding what this test should actually assert now (does the "Inspection Items" panel alone,
  with no inspection started, have anything of its own worth asserting on, or should the fixture start an
  inspection first to reach real condition-recording markup), which is a real decision, not a
  find-and-replace like the redirect fix above, and is not part of today's discrepancy-banner scope.

---

## 20.13 Photos rebuilt — multi-file, room-level, whole-inspection bulk upload + tag (2026-09-22)

Johan, after the collapse/discrepancy fixes landed: "wheres the bulk photo upload per inspection, wheres
the upload photos per space? wheres the multi select per ceiling in a room or whatever?" Three things,
built together since they share one upload/tag mechanism, never three separate ones.

### 20.13.1 Data model — `rental_inspection_photos` gains room/tray support

Migration `2026_09_22_140000_add_room_and_tray_support_to_rental_inspection_photos_table`:

```
rental_inspection_photos   (existing table, extended)
  rental_inspection_id           -- NEW, nullable FK — denormalized the same way property_id is
                                  --   denormalized on rental_inspections itself: every photo on
                                  --   this inspection, tagged or not, without a join through
                                  --   observations. Backfilled from the existing observation link
                                  --   for every pre-existing row.
  property_room_id               -- NEW, nullable FK — set when tagged to a room (with or without
                                  --   a specific item; item-tagged rows carry the item's own room
                                  --   here too, denormalized, so "every photo in this room" never
                                  --   needs a join through observations->items either).
  rental_inspection_observation_id  -- EXISTING column, made NULLABLE (was required) — a raw
                                  --   `ALTER ... MODIFY`, not Blueprint::change(): doctrine/dbal
                                  --   is not installed on this box.
  tagged_at, tagged_by_user_id   -- NEW, nullable — when/who filed it. Both null = untagged (tray).
  archived_by_user_id            -- NEW, nullable — who archived it (deleted_at already says when).
  deleted_at                     -- NEW (SoftDeletes)
```

**Both null = tray. Room set, item null = a general room shot. Both set = filed against one item.**
An item id is always validated to belong to this inspection's own property before it's trusted, and
tagging to an item always resolves that item's OWN room server-side — a client-supplied room_id that
disagrees with the item's real room is silently overridden, never trusted.

**Tagging supersedes, never appends** (`RentalInspectionPhoto::tagTo()`/`untag()`) — a plain column
update, not a new row. Re-filing a photo from one room to another simply changes where it currently
sits; untagging is calling `tagTo(null, null, ...)` — the exact same operation in reverse, so undo needs
no separate mechanism. This is a **deliberately different discipline from `rental_inspection_observations`**
(immutable, evidentiary, never touched after creation, §3.1) — a photo's FILING is not itself evidence,
only the photo is.

**Amendment to the original "no deleted_at at all" design call** (§3.3, reasoned for observations
specifically): a photo an agent uploaded by mistake (duplicate, wrong property, blurry) must be
removable — `RentalInspectionPhoto::archive()` is a real soft-delete, never a hard one (non-negotiable
#1). Wired into the tray's own thumbnails today (the highest-value case — screening out mistakes before
filing); not yet wired into the item/room photo views — see §20.13.6.

`RentalInspection::photos()` (new `hasMany`) is the single source of truth for the tray/room views —
every photo on the inspection regardless of tag state. `RentalInspectionObservation::photos()` (existing,
unchanged) still serves item-level display and is unaffected — a nullable FK simply matches fewer rows
now, nothing about that relation's own behaviour changed.

### 20.13.2 One upload endpoint, three surfaces

`POST /corex/rental-inspections/{inspection}/photos` (`storePhotos()`) — `photos[]` (1-10 files),
optional `property_room_id`, optional `rental_inspection_observation_id`, `client_idempotency_keys[]`
(one per file, same retry-safety pattern as the existing single-photo endpoint). Neither tag field sent
= lands untagged in the tray (item 3); room only = a general room shot (item 2); an observation id =
filed against that item, its room resolved server-side (item 1). **One endpoint, not three** — the item
camera control, the room heading's own upload control, and the whole-inspection bulk dropzone all call
the exact same route with different fields, matching the settled pattern (§20.13.4) rather than growing
a second uploader per surface.

The existing single-file `POST .../observations/{observation}/photos` (`storePhoto()`) is **unchanged
and still callable** — kept for backward compatibility (a future mobile client, or anything else already
using it) — but now also populates the new tagging columns for consistency, and the web UI no longer
calls it; the item camera control (item 1) calls the new batch endpoint instead, tagged to that item.

Four more endpoints complete the tagging lifecycle:
- `POST .../photos/{photo}/tag` — file one photo (from the tray, or re-filing an already-tagged one).
- `POST .../photos/tag-bulk` — the tray's own multi-select-then-drop action: many ids, one room, in one
  call. Skips any id that doesn't belong to this inspection rather than failing the whole batch — a stale
  tray selection (another tab already tagged one of them) never blocks filing the rest.
- `POST .../photos/{photo}/untag` — back to the tray. The exact reverse of tag/tag-bulk.
- `DELETE .../photos/{photo}` — archive (§20.13.1).

All five gated by the existing `rental_inspections.create` permission, all route-model-bound through
`{rentalInspection}` (agency-scoped globally) and cross-checked against `rental_inspection_id` inside the
method — a photo id from a different inspection (or a different agency's, invisible to the global scope
in the first place) 404s, never silently reachable.

### 20.13.3 Multi-file everywhere (item 1)

The item camera control's `<input type="file">` gained `multiple` — several photos attach to one item in
one pick. An item with no observation yet has nothing to tag a photo TO (the endpoint requires a real
observation id); those files are staged on the same `obsField()` a single photo already staged (now
`photos: []`, plural) and uploaded together, tagged to the new observation, the moment
`_commitObservation()` creates it — no behaviour change to when an item "counts as recorded" (§20.3),
only to how many photos can ride along.

### 20.13.4 The reusable piece — `public/js/corex-photo-batch-uploader.js`

**This is the component cc6 can consume for rental-inventory's own capture surface** — a plain static
file (`window.corexPhotoBatchUploader(config)`), included via a normal `<script src>` tag, deliberately
NOT a Vite entry point: `public/build` is gitignored, so a new Vite entry would need `npm run build` added
to every deploy of this feature, a real risk this codebase's existing deploy checklist doesn't currently
carry. Matches the already-proven pattern of `corex-connection-guard.js`/`corex-ad-render.js` — plain,
committed, no build step.

**Config-driven, backend-agnostic**: `{ csrf, uploadUrl, tagUrl(id), tagBulkUrl, untagUrl(id),
archiveUrl(id), photos }`. It owns:
- **Client batching** — reuses the SAME `window.planUploadBatches` global already defined for the
  property gallery uploader (10 files/request AND a byte ceiling, whichever binds first — PHP's
  `max_file_uploads=20` on this box makes a single 90-file request impossible regardless of connection;
  a byte-only or count-only limit alone was already proven insufficient by the property gallery's own
  history, §"Split a file selection into POST batches" comment in `show.blade.php`).
- **Raw XHR per batch** (real upload-progress events, `Accept: application/json` always) — matching the
  existing inspection-photo and property-gallery upload precedent, never `fetch`.
- **Per-file idempotency keys** — a retried batch never double-uploads a file that already landed.
- **Per-batch, independently retryable failure** — a bad file fails only its own batch; every other
  batch's success is untouched, and `retryBatch()` re-sends only the failed one.
- **Multi-select**: click (select one), shift-click (range, against the caller's own current render
  order — "everything between", not a numeric id range), ctrl/cmd-click (toggle one in/out), and a
  drag-marquee (mousedown on empty tray background, drag a rubber-band rect, release to select every
  thumbnail it intersects).
- **Drag the selection onto a room** (native HTML5 drag/drop) — `dragStartSelection()`/`dropOnRoom()`.
  A `select` + "Tag selected" button is the touch/mobile equivalent (item 7) — HTML5 drag-and-drop is
  unreliable on phones, so dropping a room isn't the ONLY way to file a selection.

**The one real divergence from cc6's own, already-shipped rental-inventory photo backend**, found while
building this (not invented to justify a mismatch — cc6's `RentalInventoryPhoto`/`storePhotos()`
already exists, built independently, same session): inventory tags a photo to a LINE ITEM via a
many-to-many pivot (`rental_inventory_line_photos` — one photo can illustrate several line items at
once, e.g. "the TV and the stand in one lounge photo"), while THIS spec's photos supersede a single
room/item tag (§20.13.1) — Johan's own explicit instruction for inspections, and the right shape for a
different question ("what does this photo evidence for THIS item's condition" vs. "what does this photo
show"). The JS component itself doesn't care which shape its `tagUrl`/`tagBulkUrl` implement — it just
calls them with `{photo_id(s), room_id}`-shaped bodies — so cc6's existing endpoints could be wired
straight into this same file's `uploadFiles`/multi-select/marquee/drag layer without a backend change,
even though the two features' tagging semantics differ. Reported to the conductor for cc6 to actually
wire up; not done here — out of this build's own scope.

### 20.13.5 Room-level photos (item 2)

Each room heading gained its own upload control (always available, not gated on recording progress —
unlike "All Good"/"Mark room N/A") and a compact thumbnail strip shown only when at least one general
room photo exists (§9 screen-space discipline — nothing renders for an empty case). The room heading row
is also a drop target for the tray's drag-a-selection action, highlighted while a drag is over it. The
room's photo count now correctly includes both its own general shots and its items' rolled-up photos
(`roomProgress()`), not just the latter.

### 20.13.6 The tray (item 3)

A dedicated upload control ("Upload photos to this inspection") posts with no tag fields, landing
everything in the tray; the tray's own count IS the progress indicator (no separate badge — "N
untagged"). Per-batch upload status/failure/retry renders inline. Selected photos get a room via drag or
via the mobile-friendly `select` + button. **Found, not fixed**: per-photo archive is wired into the tray
only today (the highest-value real case — screening duplicates/blurry shots before filing); the
already-tagged room/item photo views have no archive affordance yet — the endpoint exists and works
(§20.13.2), only the UI hook is missing there. Flagged rather than silently left looking finished.

### 20.13.7 Mobile-callable

Every new endpoint sits under the same `/corex/rental-inspections/...` web-route group every other
inspection action already uses (§14.2's established mobile-API pattern — session or Sanctum token, same
routes, no separate versioned surface) — nothing new to build for a future mobile client to call these.

### 20.13.8 Scoping and standards

OWN/BRANCH/AGENCY enforced at the query layer exactly like every other action on this surface: every new
route is bound through `{rentalInspection}` (globally agency-scoped) and every photo id is cross-checked
against `rental_inspection_id` inside the controller — a photo from a different inspection, or a
different agency's (invisible to the global scope before the controller is even reached), 404s. Archive
is a real soft delete (non-negotiable #1) — see §20.13.1's amendment note for why this table now carries
one, narrower than the original "no delete path at all" reasoning for observations.

### 20.13.9 Verified in an isolated worktree, not against the deployed site

Per explicit instruction after an earlier incident this same day (verifying against `/corex-qa1` directly
showed a fix that then vanished when the branch was reset) — this entire build happened in a dedicated
git worktree (`/mnt/HC_Volume_103099143/corex-worktrees/cc2-inspection-photos-2026-09-22`) with its own
independent `composer install` (never a shared/symlinked `vendor/`, per the box-wide isolation rule) and
its own isolated MySQL databases (`corex_qa1_wt_cc2photos` for manual/local-server checks,
`hfc_dash_test_92` for PHPUnit — neither is QA1's real `corex_qa1` schema). `/corex-qa1` itself was not
touched during this build. Real click-through verification (multi-file select, drag-and-drop onto a
room, marquee-select, tag-then-reload persistence) happened against a local `php artisan serve` instance
bound to this worktree and its own database, not the deployed URL — the deployed site is verified by the
conductor after cc1 lands this branch, per instruction.

## 20.14 Two field bugs fixed, layout redesigned per Johan's own spec (2026-09-22, same day)

Same worktree, a fresh branch (`cc2-inspection-photos-fixes-2026-09-22`) rebased onto `origin/QA1` after
cc1 landed §20.13. Verified locally exactly per §20.13.9's methodology — never against the deployed site;
cc1 lands, the conductor checks the deployed URL after.

### 20.14.1 Bug 1 — the upload progress row never reached a terminal state

Root cause, found by reproducing Johan's exact report (6 photos, real browser, real upload) rather than
guessing: `public/js/corex-photo-batch-uploader.js`'s `uploadFiles()` pushed a freshly-created plain object
(`entry`) into the reactive `uploadBatches` array, then mutated that SAME pre-push closure reference
(`entry.status = 'done'`) from inside `_cpu_uploadBatch()`. Alpine/Vue's reactivity only intercepts
property writes made THROUGH its own proxy — pushing the raw object into the array is a structural change
that correctly renders once, but a later property mutation on the raw, never-re-read reference never fires
through any proxy `set` trap, so the template's tracked dependency is never notified. The row froze on its
first render ("Uploading N photo(s)… 0%") forever, even though the photo had already uploaded and rendered
correctly as a thumbnail — exactly Johan's report. `retryBatch(entry)` never had this bug because its
`entry` argument comes from the template's own `x-for` iteration, which DOES read through the reactive
array's proxy.

Fix: after `this.uploadBatches.push(...)`, re-read the just-pushed entry back OFF the reactive array
(`this.uploadBatches[this.uploadBatches.length - 1]`) before passing it into `_cpu_uploadBatch()`, so every
subsequent mutation (percent, done, failed) goes through the tracked proxy. One fix in the shared component
fixes it for all three upload surfaces (item/room/tray) and for cc6's future reuse — no controller change
needed; the server-side response was already correct. Verified: a real 6-file upload now reaches `done`
and disappears from the progress list; a batch with one invalid file (mixed jpg+txt) reaches `failed` with
a visible Retry button, never hangs.

### 20.14.2 Bug 2 — no secondary tag (room, then item within that room)

Johan: "clicking a photo allows you to tag the room, but theres no secondary tag yet. cant tag room and
then ceiling as example." No backend change was needed — `tagPhoto()` (§20.13.2) already accepted
`property_room_id` and `rental_inspection_observation_id` together and already resolved an item's own room
server-side; only the UI had no control to reach it. Built as part of the R1/R2 photo-strip redesign below
(kept together since Johan's own redesign IS where these photos now live):

- **Room → item**: each general room-photo tile (R1) carries a small `<select>` overlay listing the room's
  own items, defaulting to "General". Choosing an item calls `tagPhoto(photo.id, { property_room_id,
  rental_inspection_observation_id })` — the same supersede-never-append discipline as §20.13.1, just a
  reachable UI path to it.
- **Item → room**: each item photo tile (R2) carries a small "↑ back to room" button — the exact reverse,
  one click, `tagPhoto(photo.id, { property_room_id })` with no observation id. Hidden for the roomless
  "General" group (meters/legacy items with no room — there is nothing to step back to). Untag steps back
  the same way, as asked, with no separate mechanism.

Verified: tagging a room photo to "Ceiling" moves it out of `roomPhotosFor()` and into `itemPhotosFor()`;
clicking "back to room" on it moves it back — confirmed via the exact counter deltas across a real browser
round trip, not just a single-direction happy path.

### 20.14.3 R1 — room photos, gallery-sized, one row by default

**Corrected, 2026-09-22 (same day, photo-tagging pass) — this subsection originally described a
HEIGHT-based clip; that was a real defect, since fixed, and the text below now matches the actual
shipped mechanism, not the superseded one.** The original `max-height:6.5rem; overflow:hidden` clip cut
every tile short at gallery sizing (a grid column is easily 150-300px wide, so an `aspect-ratio:1/1`
tile is that tall too — 104px clipped it mid-tile), which also hid the bottom-anchored per-tile
controls (the room→item chooser select, the room→untagged button) inside the clipped-away area on any
real screen width. **Clipping is now COUNT-based, never height-based**: the collapsed state renders
`roomPhotosFor(...).slice(0, 3)` (the first 3 photos only, by array index — never a CSS clip on a
rendered tile), expanding to every photo on "Show all N" via the same `roomPhotosExpanded[roomId]` flag.
Every rendered tile is always whole; nothing is ever cut in half.

Johan: "the small images is a waste of time. it either has to show it big enough like on gallery... maybe
show 1 row of photos, then expand to see more?" The room-photo strip uses the SAME tile size/grid as the
property Gallery (`rental-section-body.blade.php`: `grid-cols-3 sm:grid-cols-5`, `aspect-ratio:1/1`,
`object-cover`). The "Show all N" / "Show less" toggle appears once a room has more than 3 (the tighter
of the two responsive column counts, so the toggle is never missing on mobile even though it's
occasionally a harmless no-op on desktop at exactly 4–5 photos). Never gated on a hardcoded photo count
for WHETHER to clip — only the toggle's own visibility threshold, chosen to hold reasonably at both
breakpoints rather than requiring a live column-count read. A 15-room property no longer pushes its own
items ten screens down. Verified with an 11-photo room: 3 render collapsed, all 11 after "Show all", no
tile ever partially clipped in either state.

### 20.14.4 R2 — item row: two-column condition buttons, photo strip at a matched, fixed height

Johan: "why dont we stack the buttons neat and tidy on top of each other and use 2 columns for all the
buttons under ceiling. sizing should work out that its essentially the same height as the photos running
next to the buttons towards the right and we allow the scroll if more than the screen allows." Each item
row is now a `flex items-stretch` row: a left, content-sized block (a `grid grid-cols-2` of the agency's own
`conditionStates` — any length, never a hardcoded count or split — plus the notes field when the selected
condition requires one), and a right block (`flex-1 min-w-0 overflow-x-auto`) holding that item's own
photos at a real size plus one always-present "add photo(s)" tile at the end (replacing the old separate
"no photo yet" vs "add more" branches — one structure covers both). The row's own height comes from
ordinary flex `stretch` alignment, not a hardcoded pixel value — whatever height the button grid + notes
need is exactly the height every photo tile gets too (`height:100%`, `aspect-ratio:1/1` for the width), so
the shape holds for a 3-state or a 9-state agency condition list without any special-casing. More photos
never grow the row; the strip scrolls horizontally instead. Verified: a real item row with 7 condition
buttons measured `rowHeight` and `stripHeight` identically (124px each), `overflow-x:auto` confirmed on the
strip.

### 20.14.5 R3 — full width for a single open inspection

Johan: "in in inspection we can use the full width of the screen." Reuses the property page's EXISTING
sidebar collapse toggle (`sbCollapsed`, defined on the page's outer `x-data`, persisted to
`localStorage('hfc.propSidebar.collapsed')`) rather than inventing a second one — an `x-effect` on the
Inspections tab's own root collapses it (`sbCollapsed = true`) whenever exactly one of In/Out Inspection is
open. Deliberately one-directional: it only ever collapses, never force-reopens, and only ever runs while
`activeTab === 'inspections'` — a user who manually re-expands the sidebar keeps that choice until they
next toggle an inspection section, and the effect can never touch the sidebar preference on any other tab.
No auto-restore was built; the existing collapse-rail's own "Expand sidebar" button is the way back,
unchanged. Verified: `sbCollapsed` flips to `true` the moment In Inspection alone is opened.

### 20.14.6 Found, not fixed (out of scope for this build)

- The render gate (`verify-alpine-render.mjs`) reports a set of pre-existing scope-gap warnings and a
  `form.getAttribute is not a function` script-eval error on this property page. Confirmed via an identical
  before/after run (stashing this build's changes and re-rendering) that every one of these is byte-for-byte
  pre-existing on `origin/QA1` already — unrelated to inspections, spanning the marketing gallery, the spaces
  picker, the readiness panel, and others. Not touched here; flagged for whoever owns those areas.
- §20.13.6's own "Found, not fixed" item (archive affordance missing on already-tagged room/item photo
  views) is still not built — out of this build's two-bugs-plus-R1-R3 scope, unchanged from before.

## 20.15 The compare view — In vs the next inspection, photo matching (2026-09-22)

Johan, restated after an earlier "synchronised sliders" draft was overruled: "on any further inspections
we split into 2 panels - left is in inspection, right is the next inspection (and i state next inspection
as it can be ad hoc or out inspection)... you cannot let both rotate together... the clever part is to
match photos - flip in to photo 1, flip out to photo 3, hit a link photos or match photos button and the
photos move to stay together on the view, reports etc afterwards."

### 20.15.1 Data model — `rental_inspection_photo_matches`

A many-to-many self-join over `rental_inspection_photos`: `photo_id_a`/`photo_id_b` (always stored lower-id-
first, canonicalized in `RentalInspectionPhotoMatch::matchPhotos()`, never at the DB layer), denormalized
`agency_id`/`property_id` (same reasoning as `rental_inspection_photos.rental_inspection_id`), `matched_by_
user_id`/`matched_at` and `unmatched_by_user_id` mirroring that table's own `tagged_by`/`archived_by`
pairing — this is deposit-dispute evidence and carries the same audit weight as a recorded condition
(Johan's own words). Soft-deletable (unmatch), never hard-deleted (non-negotiable #1). A photo may carry
more than one match — the same damage can show in several shots — so this is a genuine many-to-many, not a
column on the photo.

Every index/constraint name is explicit and short (`ripm_*`), not left to Laravel's auto-generated naming —
the same MySQL 64-character identifier limit already documented on `rental_inspection_photos`' own
migrations applies here too, and a two-long-column-name unique pair index on this table's full name would
risk it.

`RentalInspectionPhotoMatch::matchPhotos($a, $b, $by)` is idempotent and restore-aware (BUILD_STANDARD §5a):
re-matching an already-active pair returns the same row; re-matching a previously-unmatched pair restores
it rather than colliding with the pair's own unique index (a soft-deleted row still occupies that slot).

### 20.15.2 Resolving the pair — `RentalInspection::compareRightFor()`/`mostRecentFor()`

`mostRecentFor($property, $type)` generalizes the existing `mostRecentOutFor()` (now a one-line delegate to
it) — completed-inclusive, excludes only cancelled. `compareRightFor($property)` finds the LEFT side first
(`mostRecentFor($property, TYPE_IN)`), then the earliest non-in, non-cancelled inspection on that SAME
lease. Deliberately generic over type — never a literal `TYPE_OUT` check — so a future ad-hoc inspection
(the `TYPE_AD_HOC` constant already exists; this build does not add any way to create one) slots into
"the next inspection" without touching this method. Both lookups are completed-inclusive on purpose: by the
time a second inspection exists to compare against, the in-inspection is almost always already completed,
and the pair must stay comparable after the out-inspection completes too (the deposit-dispute case), not
only while it's in progress.

`RentalInspection::tabPayloadFor()` gained `compare_left_inspection`/`compare_right_inspection` (both null
when there's nothing yet to compare — the common single-inspection case) and `photo_matches` (every match
touching either side's photos, eager-loaded with both `photoA`/`photoB`).

### 20.15.3 One endpoint pair, property-scoped like items/rooms

`POST /corex/properties/{property}/rental-inspection-photo-matches` (`photo_id_a`, `photo_id_b`) and
`DELETE .../rental-inspection-photo-matches/{match}` — property-scoped (not inspection-scoped, unlike the
photo tag/untag/archive routes) because a match genuinely spans two different inspections on the same
property, the same reasoning `rental-inspection-items`/`rental-inspection-rooms` are already property-
scoped. Both photos must belong to inspections on the request's own property (404 otherwise, same
cross-scope check pattern as `retireItem`/room-note routes); matching a photo to itself or to another photo
on the SAME inspection is rejected (422) — matching only makes sense across a comparison. Gated by the same
`rental_inspections.create` permission as every other recording action.

### 20.15.4 Alignment is automatic, for free

Rooms and items belong to the PROPERTY, not to a specific inspection — both the in- and the next inspection
reference the exact same underlying `PropertyRoom`/`RentalInspectionItem` rows. `roomGroups()` (already
built for the single-inspection recording view) is reused UNCHANGED as the compare view's own row source —
Bedroom 1/Ceiling on one side and Bedroom 1/Ceiling on the other are, structurally, the same `item.id`
iterated twice. The agent never aligns rooms by hand because there is nothing to align.

### 20.15.5 Independent flip, persisted match (items 3/4)

`comparePhotoUploader(side)` mirrors the existing `photoUploader(section)` factory but resolves the
inspection from `compareLeft`/`compareRight` (§20.15.2's completed-inclusive lookups) instead of
`currentInspection()` (which excludes completed and would go null on the left side almost immediately) —
same shared `corexPhotoBatchUploader` component either way, so `roomPhotos()`/`itemPhotos()` work
identically.

`compareIndex` tracks each side's current photo position independently, keyed by `side + '_' + rowKey`
(`room_<id>` or `item_<id>`) — flipping the left side of Bedroom 1/Ceiling never touches the right side's
position, or any other row's. Per Johan: "you cannot let both rotate together."

`toggleCompareMatch(leftPhoto, rightPhoto)` links (or, if already linked, unlinks) whichever photo is
CURRENTLY showing on each side — the real feature: flip to the pair that shows the same damage, hit
"Match photos", and the persisted link is what the next visit reads. `compareIndexes(key, leftPhotos,
rightPhotos)`, called once per row via `x-init`, is what makes a matched pair "stay together... afterwards"
(Johan) — on a row nobody has manually flipped yet this session, if a persisted match exists between any
photo on the left and any photo on the right for that row, both indices jump straight to that pair instead
of sitting at an arbitrary 0/0.

### 20.15.6 Expand to a modal (item 5)

`openCompareModal(kind, key, room, item)` opens a larger side-by-side view sharing the EXACT same
`compareIndex`/`photoMatches` state as the compact row — flipping or matching in the modal is reflected in
the row underneath immediately (and vice versa), never a second, disconnected copy of the state.

### 20.15.7 Reused, not rebuilt

The tagging controls (single-destination buttons, the opened-photo-viewer "Move to…" chooser) landed
separately this same day (`ca5d69a27`) and are untouched by this build — the compare view is purely
additive in `show.blade.php`, never touching `rental-inspection-recording.blade.php` (the file that work
lives in). The item photo strip's own height mechanism (also landed separately, `503429dab`) is likewise
untouched.

### 20.15.8 Mobile — one panel at a time, not stacked

Johan: "two panels side by side will not work at 390px... I would expect one panel at a time with a way to
switch sides" — a plain toggle (`compareMobileSide`) above the compare section at phone width, switching
which side is visible; both sides show side by side from the `sm:` breakpoint up. Implemented as a reactive
`:class` binding (`'block'`/`'hidden'` combined with `sm:block`), not a `window.innerWidth` check — the
latter isn't reactive to a real resize/rotate in Alpine, only to whatever expression the class binding
itself depends on.

### 20.15.9 API shape (item 6) — deliberately not made impossible for AT-429 (parked, not built)

Every photo already carries `rental_inspection_id`/`property_room_id`/`rental_inspection_observation_id`;
every match record carries `photo_id_a`/`photo_id_b`/`matched_at`/`matched_by_user_id` with both photos
eager-loadable. A mobile client can find "the in-photo for this item" (Andre's parked AT-429 ghost-overlay
idea) by querying photos for the in-inspection filtered by `rental_inspection_observation_id`, and can find
"is there a match for this photo" by filtering the matches list for either id column — no reverse-engineering
of the screen required. Nothing here builds any part of AT-429 itself.

### 20.15.10 Not in this build

Ad-hoc inspection type creation, deposit outcome/dispute resolution, and report/document generation are all
explicitly out of scope — `compareRightFor()` is written generically enough that a future ad-hoc inspection
slots in as "the next inspection" without changing this method, but nothing here adds a way to create one.

---

## 20.16 Photo comparison — groups, not pairs; a real viewer (2026-09-23, cc2)

Johan, describing the real case §20.15's pairwise design couldn't express: "in inspection carries 2
photos showing the same area, out inspection carries 5 photos... can you tag them together so you can
work with them together?" and, on the viewer itself: "if the photos are clicked to display in full there
should be 2 views, and a carousal at the bottom... in comparison view if I click a photo either side of
the carousel it not only loads that photo into view, it also loads the tagged photo on the other side."

### 20.16.1 Pairwise was wrong — investigated, confirmed, replaced

§20.15.1's `rental_inspection_photo_matches` is a genuine self-join over exactly two photo id columns
(`photo_id_a`/`photo_id_b`), with a unique index over the PAIR — structurally incapable of expressing a
set. Worse than just "10 links for 2×5 photos" (Johan's own math, confirmed exactly right given the
existing same-inspection-match rejection): the old JS (`matchFor()`/`matchPartnerId()`) only ever
compared the two CURRENTLY-DISPLAYED photos directly — a photo matched to B, and B matched to C, were
invisible to each other even though transitively related. Confirmed via `RentalInspection::
tabPayloadFor()` (§20.15.2) too: `compare_left_inspection`/`compare_right_inspection` is a fixed PAIR of
inspections, not a chain — Johan's "third and fourth inspection" vision needs `compareRightFor()`
generalized into a full inspection history as a SEPARATE follow-on piece of work; the group model below
is necessary for that vision but not sufficient by itself, and that generalization is not built here.

### 20.16.2 The group model

Two new tables, additive, replacing (not extending) the pairwise design:

```
rental_inspection_photo_match_groups
  id, agency_id, property_id (denormalized, same reasoning as the old table)
  created_by_user_id, archived_by_user_id, deleted_at   -- soft-deletable, never hard-deleted

rental_inspection_photo_match_group_members
  id, agency_id, rental_inspection_photo_match_group_id, rental_inspection_photo_id
  added_by_user_id, added_at, removed_by_user_id, deleted_at
```

**A photo belongs to at most ONE active group at a time** — Johan's own instinct, taken as the rule:
"one group per photo keeps it comprehensible." No real case for a photo needing two groups surfaced
during design (a photo showing two distinct defects would need the AGENT to disambiguate which group it
means every time it's clicked — genuine added confusion for a rare case, not a real need) — enforced in
`RentalInspectionPhotoMatchGroup::addMember()`, NOT a database unique constraint on the photo id alone: a
plain unique index can't express "unique among non-deleted rows only" in MySQL, the exact gotcha
`RentalInspectionPhotoMatch::matchPhotos()` already had to work around for its own pair-uniqueness (a
soft-deleted row still occupies its unique slot). `addMember()` soft-deletes any OTHER active membership
for that photo first — a "move," both ends of which are independently audit-visible — before creating or
restoring the new one (BUILD_STANDARD §5a: restore-aware, never colliding with a stale soft-deleted row).

`RentalInspectionPhotoMatchGroup::linkPhotos($clicked, $anchor, $by)` is the actual "Match" action: decisive
about the one genuinely ambiguous case (both photos already belong to DIFFERENT existing groups) —
`$clicked` always moves into `$anchor`'s group, "whichever photo you're introducing into the comparison
joins the group already anchored on the other side," never a silent merge of two pre-existing groups.

Removing a photo (`RentalInspectionPhotoMatchGroupMember::removeAndMaybeArchiveGroup()`) soft-deletes
just that membership; if the group drops to one or zero active members, the GROUP is archived too —
nothing left to compare. Every removal records `removed_by_user_id`, mirroring the old table's
`unmatched_by_user_id` — this is deposit-dispute evidence and carries the same audit weight as a recorded
condition (Johan's own framing, §20.15.1, unchanged by this rebuild).

### 20.16.3 Migration — connected components, not one-group-per-row

`2026_10_03_100200_migrate_pairwise_photo_matches_into_groups` runs automatically on `migrate` (not a
manual step a deploy could forget). Every ACTIVE pairwise edge is treated as a graph edge; union-find
collapses each connected component into ONE group with every touched photo as a member — deliberately
NOT "one group per old row," because that would still miss the transitive A-B/B-C case §20.16.1 found the
old UI couldn't see. Proven directly (`RentalInspectionPhotoMatchGroupMigrationTest`): a single pair
becomes a group of two; a transitive chain (A-B, B-C, never A-C directly) collapses into ONE group of
three; two disjoint pairs become two separate groups; a soft-deleted (already-unmatched) old row is never
migrated; the old table is left byte-for-byte untouched (`rental_inspection_photo_matches` — model and
migration both marked SUPERSEDED, kept purely as historical record, never dropped, never written to
again — dropping a table is not what "no hard deletes" protects, but there was no reason to discard a
working historical record either). Group/member `created_by`/`added_by`/timestamps are backfilled from
each component's/photo's own earliest touching edge, not just stamped "now" — a defensible provenance,
not a guess. **Nothing failed to carry over** — every active pairwise row's information (which photos,
who matched them, when) is fully represented in the new shape; the only semantic change is that a
transitively-connected chain now shows as one group instead of being invisible to itself, which is a
correction to a real gap, not a loss.

### 20.16.4 SUPERSEDED by §20.17

The first version of the viewer (two modes, a single shared carousel, opened from a standalone "Compare —
In vs Out" section) shipped, was reviewed live on QA1, and was found not to match what Johan actually
needed — see §20.17 for the full replacement, the defects found, and the mockup it was rebuilt against.
That standalone Compare section no longer exists at all (§20.17.1).

---

## 20.17 The compare viewer rebuilt to Johan's approved mockup (2026-09-24, cc2)

Two rounds of live review on QA1 (property 5792) found the first version of the viewer (§20.16.4) did not
work and did not match the spec: compare mode showed "Nothing yet" on one side while a matched photo
existed, the two panes were different sizes with the right image clipped, one carousel was shared instead
of one per side, a stray blank tile rendered in the carousel, the site's own top banner showed through the
modal's toolbar, and "zoom" was a lock toggle with no actual zoom control. Johan then approved a full
mockup and said to build to it exactly — that mockup is now this spec.

### 20.17.1 The standalone Compare section is deleted

Johan: "im not sure why we are still stuffing around with a compare section. it should work from the next
inspection screen... the modal that loads should carry the functionality, not a complete compare
section." The entire §20.15/§20.16.4 inline "Compare — In vs Out" collapsible section — its two-column
photo rows, per-row carousels, flip arrows, and inline match button — is removed from `show.blade.php`
outright, along with every JS method that existed only to render it (`compareRoomPhotos`,
`compareItemPhotos`, `comparePhotoUploader`, `compareUploaders`, `compareIndex`/`compareIndexes`,
`compareCurrentPhoto`, `compareFlip`, `compareMobileSide`, and the `compareLeft`/`compareRight` JS state
that fed it). Comparison now happens in exactly one place: the viewer below, opened from a photo on
cc3's side-by-side predecessor/tail screen. `compareLeft`/`compareRight` as a JS concept is gone; the
viewer reads `chainPredecessor`/`chainTail` directly, the same state cc3's own read-only panel already
uses (`roomPhotosForInspection()`/`conditionForInspection()` — the viewer is now a second consumer of
those two functions, not a parallel data path).

### 20.17.2 Entry contract — `openCompareViewer(photo, insp)`

Unchanged in shape from §20.16.4, confirmed against cc3's own `rental-inspection-readonly-panel.blade.php`
docblock (which names this exact seam): `insp` is whichever of `chainPredecessor`/`chainTail` the clicked
photo belongs to — cc3's panel already has both in scope at every photo it renders (its own `$inspectionJs`
include variable) and calls this directly on click; cc2 does not edit that panel's structure. `openCompareViewer`
resolves everything else itself: which side the photo lands on (tail is always the right/"CURRENT" pane,
matching the side-by-side screen it was clicked from), whether it's a room-level or item-level photo (from
the photo's own `property_room_id`/`rental_inspection_observation_id` — no new field needed), the room/item
label, and — the core defect fix — the matched counterpart on the OTHER side via the photo's own match
group, filtered to that other inspection's id. A photo with no match yet renders "Nothing yet" on the other
side honestly; it does not fall back to guessing "whatever photo happens to be first."

### 20.17.3 Layout, top to bottom (the approved mockup)

1. **Top bar** (`cv-topbar`, 56px) — title + property address + which inspection; Single/Compare segmented
   control; a "Move together: On/Off" toggle (the same zoom-lock concept as §20.16.4, now labelled in
   words, never an icon alone); an amber pill naming how many untagged photos exist on the current
   inspection; close.
2. **Space row** — a tab per real room on the property (`compareViewerRoomTabs()`, `roomGroups().filter(g
   => g.room)`), so the agent can move to any room without closing the modal. Active tab gets a cyan
   underline.
3. **Item row** — the selected room's own items as pill chips, "Whole room" always first (room-level
   general photos), each chip showing "IN-count / CURRENT-count" (`compareViewerChipCounts()`) so a gap
   ("Windows 0 / 2") is visible without opening anything.
4. **Two panes**, identical fixed-height boxes (`compare-viewer-pane`, one CSS height on both, single mode
   and compare mode alike — this is the direct fix for "the two panes are not the same size": flex-stretch
   alone had let the two boxes size from their own image content, which is exactly what let them drift
   apart). Each pane: a header (type badge — "IN" muted / "CURRENT" cyan, never "OUT", since the chain can
   run In → Routine → Out — inspection name, date in mono, a condition pill on completed item-level
   photos); the image itself, centred/contained on a near-black background, with a bottom-anchored zoom
   cluster (real − / percentage / + / Fit buttons, `compareViewerZoomInBtn`/`OutBtn`/`DoubleClickReset` —
   not just the lock toggle) and a top-right expand button; a tag bar underneath (tagged: green check +
   "Tagged to X › Y" + Retag; untagged: amber dot + explanatory text + a solid "Tag photo" button).
5. **Two thumbnail rails**, one per side, in the same two-column grid as the panes above them — the direct
   fix for "one carousel, shared": `compareViewerCarouselPhotos(side)` is now called with an explicit
   side, filtered to that side's own inspection, and filters out any photo missing a `storage_path` (the
   fix for the stray blank tile). Clicking a thumbnail on EITHER rail loads it into its own pane AND loads
   its matched group's counterpart into the opposite pane (`compareViewerSelectCarouselPhoto(photo,
   side)`) — Johan's own framing, unchanged since §20.16.4.

**Single mode** — one header strip (badge, name, date, room › item, condition pill, Retag), one large
image with the same zoom cluster plus a "drag to pan" hint once zoomed, a "Back to compare" button that
returns to the two-pane view on the SAME photo, and one centred rail for that side only.

**Z-index fix** — `.compare-viewer-backdrop`'s z-index is set to the maximum safe CSS value
(2147483647) rather than relying on a Tailwind utility class competing against whatever stacking context
the page's own top banner establishes; the toolbar rows also get their own fully opaque background as a
second, independent fix — correct regardless of which of the two was the actual cause of the bleed-through.

### 20.17.4 Tagging, from the modal — reused, not reinvented

Johan: "borrowed from the recording screen — same mental model the agents already use, do not invent a
second one." "Tag photo"/"Retag" opens a 420px panel anchored over the pane that triggered it
(`compareViewerOpenTagPanel(side)`), two columns — SPACE (every room) and ITEM IN `<room>` ("Whole room"
first, then that room's items) — calling the EXACT SAME endpoint the recording screen's own chooser calls
(`POST .../photos/{photo}/tag`, via `compareViewerConfirmTag()`), not a second tagging mechanism. The
opposite pane dims while the panel is open (`cv-dim-overlay`) so focus is obvious.

**One real constraint, surfaced rather than silently worked around:** `tagPhoto()` requires an EXISTING
`rental_inspection_observation_id` to file a photo against a specific item — it does not create an
observation on the fly (same constraint the recording screen's own camera control already works around,
by staging uploads until an observation exists). The tagging panel's ITEM column therefore only lists
items that already have a recorded observation on that side's inspection; "Whole room" always works,
since room-level tagging needs no observation at all. An item with zero observations recorded yet is
simply not offered as a retag destination from the modal — recording a condition for it first (on the
recording screen) is what makes it retaggable.

An **untagged tray** runs along the bottom of the modal whenever the current (tail) inspection has any
untagged photos at all — thumbnails with an amber border, multi-select (`compareViewerToggleUntaggedSelect`
/`compareViewerSelectAllUntagged`), then bulk-tag to the currently-selected room
(`compareViewerBulkTagUntagged`, calling the same `POST .../photos/tag-bulk` the recording screen's own
tray already uses).

### 20.17.5 Visual

Dark chrome (`#0B0E12` backdrop, `#10151B` panels, `#1E262F`/`#29323C` borders, `#E4EBF1`/`#8C99A6` text,
`#3FC9E6` cyan accent, `#4FBE82`/`#E0A34A`/`#D9534F` condition colours), `IBM Plex Sans`/`IBM Plex Mono`
declared as the font-family with real system-font fallbacks. **Deliberately not done in this pass:** no
new font file/stylesheet is loaded — if IBM Plex isn't already available on the page, these fall back to
the system stack rather than the mockup's exact typeface; adding a new external font dependency is a
separate decision, not bundled into this fix quietly. The "expand this photo full screen" button
(top-right of each pane) is implemented as switching to Single mode on that pane's photo, not the browser's
native Fullscreen API — a lower-risk interpretation of the mockup's own icon, named here rather than
assumed silently correct.

### 20.17.6 Accessibility

Every control is a real `<button>` (none use a `div` with a click handler); icon-only buttons (`±`, close,
rail prev/next, expand) carry an explicit `aria-label`; the lock toggle exposes `aria-pressed`; every
interactive control an agent uses on a tablet (`cv-touch`) has a 44px minimum tap target via padding, even
where the mockup's own drawn height is smaller than that.

### 20.17.7 Scoping

Unchanged from §20.16.5 — `agency_id`/denormalized `property_id` on both group tables, checked in the
controller. No new agency-configurable setting: room/item navigation, the zoom bounds (1×–6×), and the
mockup's own layout are fixed UX decisions, not something an agency would reasonably want to vary.

### 20.17.8 Files touched this round

- `resources/views/corex/properties/show.blade.php` — the standalone Compare section deleted outright;
  the viewer rebuilt in full (state, room/item navigation, tagging panel, untagged tray, CSS)
- `app/Models/RentalInspection.php` — `photo_matches` repointed from `compareLeft`/`compareRight` to the
  chain's actual current predecessor/tail pair (`$rawPredecessor`/`$rawChainTail`), so it stays correct
  once a third or fourth inspection joins the chain rather than silently pinning to the original in/out pair

### 20.17.9 Known limits, named on purpose

- §20.16.1's "third and fourth inspection" generalization of the SCREEN itself (cc3's territory) is
  unaffected by this round — the viewer and the group model both already work for any number of members
  from any number of inspections; only the surrounding screen currently ever loads two at a time.
- Item-level retagging to an item with no recorded observation yet on that inspection is not possible from
  the modal (§20.17.4) — record a condition for it first, on the recording screen.
- No new font file is loaded (§20.17.5) — the declared IBM Plex stack falls back to system fonts until a
  separate decision is made to add one.
- "Open this photo full screen" switches to Single mode rather than invoking the browser's native
  Fullscreen API (§20.17.5).

---

## 20.18 Six defects from live QA1 review, property 5792 (2026-09-24, cc2)

Johan browser-tested the §20.17 deploy on QA1 (commit `86dac8df5`) and found the layout, top bar, item
chips, tag bars and single-mode switcher all correct — **not rebuilt**. Six defects, fixed in place.

**Defect 1/2 (BLOCKER, one root cause) — the item chip row didn't render on open, and opening from an
item-level photo (inside `.rir-compare-cell`) could land on the wrong room/item with nothing loaded.**
`_compareViewerContextFor(photo, insp)` resolved an item-kind photo's item id by searching the ONE
passed-in `insp` snapshot's (`chainPredecessor` or `chainTail`) `.observations` for the clicked photo's
`rental_inspection_observation_id` — and its returned object never carried a `roomId` key at all for the
item-kind branch. Two consequences from that single function: `compareViewer.roomId` stayed `null`, so
the item row's `x-show="compareViewerCurrentRoomGroup()"` never matched a room and the whole row was
invisible on open (Defect 1); and because the tail cell's live photo objects (from `itemPhotosFor()`'s
photoUploader cache) aren't guaranteed to be the same references the `chainTail` snapshot's own
`.observations` was loaded from, the id lookup could resolve to the wrong item or nothing (Defect 2).
Fixed by resolving BOTH `roomId` and `itemId` from `this.items` — the single global per-property item
list `roomGroups()`/the SPACE and ITEM chip rows themselves already read from — by scanning each item's
own `.observations` (unscoped by inspection, per `tabPayloadFor()`) for the clicked photo's observation
id. One source of truth for "which chip is active" and "what data loads" removes the two-snapshot
mismatch entirely. `_compareViewerContextFor()` no longer takes an `insp` parameter (it was already
unused on the room-kind branch, and is now unused on the item-kind branch too).

**Defect 3 — rail labels rendered as a narrow vertical strip beside the thumbnails, not one line above
them.** The rail's per-side wrapper (`<div class="px-3 py-2 compare-viewer-side">`) reused the same
`.compare-viewer-side` class as the pane wrapper above it (for the shared mobile-hide rule), but the pane
wrapper also carries Tailwind's `flex flex-col` while the rail wrapper did not — `.compare-viewer-side`
itself only declares `display:flex`, no `flex-direction`, so the rail wrapper defaulted to flex ROW and
squeezed its two children (the label line, the thumbnail-strip line) side by side instead of stacking
them. Fixed by adding `flex flex-col` to the rail wrapper, matching the pane wrapper exactly.

**Defect 4 — the two rails' thumbnails didn't start at the same offset.** The prev/next chevron buttons
used `x-show`, which removes them from layout (not just hides them) whenever that side's carousel had
one or zero photos — so whichever side happened to have 2+ photos kept its button's 44px slot pushing the
thumbnail strip in, while the other side's strip sat flush against the column edge. Fixed by keeping both
buttons always in the layout (`:class="... ? '' : 'invisible'"` plus `:disabled`) so each rail's thumbnail
strip starts at the same offset regardless of either side's own photo count.

**Defect 5 — the zoom cluster sat bottom-right, overlapping the photo; the mockup puts it bottom-left,
clear of the image.** Moved compare-mode's per-pane zoom cluster from `bottom-2 right-2` to
`bottom-2 left-2`. The "1 of N matched candidates" step control was already at `bottom-2 left-2`
(shown only when a side's match group has 2+ candidates) — left as-is it would now overlap the zoom
cluster whenever both are visible, so the step control moved to `top-2 left-2` (mirroring the existing
`top-2 right-2` expand-to-single button) as a direct, minimal consequence of putting the zoom cluster
where Johan asked for it, not a separate design change.

**Defect 6 — "Move together" defaulted Off; comparing the same patch of wall on both sides is the primary
use of this screen, so it now defaults On.** `compareViewer.zoomLocked` (both the component's initial
state and `openCompareViewer()`'s per-open reset) changed from `false` to `true`. This supersedes §20.17's
"Independent by default" framing (itself citing an earlier Johan ruling), replaced by his own words this
round: "independent panning is the exception." The two independent per-side zoom-transform objects
(`compareViewerZoomLeft`/`Right`) are unchanged and still used whenever an agent explicitly turns the
toggle off.

**Verified** against the real authenticated render (`scripts/fetch-authenticated-page.php`, user 22,
`/corex/properties/5792`, QA1) — grepped the served HTML for each of the six fixes (all six present
verbatim, including the corrected `_compareViewerContextFor` body reading `this.activeItems()`), and ran
`scripts/verify-alpine-render.mjs` against the dump: 1869 Alpine attribute expressions on the page compile
clean, with zero scope-gap warnings anywhere in the `compareViewer*` component. The gate's overall FAIL
on that run is pre-existing, page-wide noise unrelated to this change (Node has no `localStorage`/
`document`, tripping unrelated `x-data` blocks elsewhere on the same property page — `qaOpen`,
`wbReportOpen`, the spaces/features editors, etc.) — none of it named `compareViewer` or anything this
round touched. **Not run: a real browser** — Alpine's actual click-driven reactivity (does clicking a
photo really re-render the item row, does the rail scroll) can only be proven with JavaScript executing
in a browser, which this verification pass deliberately did not do.

### 20.18.1 Files touched this round

- `resources/views/corex/properties/show.blade.php` — `_compareViewerContextFor()` (Defects 1/2),
  `openCompareViewer()`'s `zoomLocked` reset (Defect 6), the rail wrapper's classlist (Defect 3), the
  rail prev/next buttons (Defect 4), and the compare-mode pane's zoom-cluster/step-control positions
  (Defect 5)

---

## 20.19 "Next inspection" moved to the section header; "Add photo section" entry point removed (2026-09-25)

Two changes from Johan browser-testing the deployed Inspections tab directly.

**Change 1 — "Next inspection" moved from the bottom of the section to its header.** Johan: "after an in
inspection how do we do another inspection - ad hoc, out etc? should we not have a next inspection or new
inspection button?" The control (§20's own Johan's-ruling-2026-09-23 "Next inspection" block) already
existed and its chain logic/type options/Start behaviour were correct — the problem was purely position:
it sat at the very bottom of the section, below every room, OVERALL NOTES, the three signature rows and
the Complete button. Functionally present, practically invisible.

Moved into the section's own header row (the `<div class="prop-section">` wrapper for `toggle('inspection')`),
always visible regardless of `open['inspection']`, beside the existing type/status text
(`chainTail.type + ' — ' + chainTail.status + ' · recorded/total'`, e.g. "Out — awaiting signature · 5/28").
The header row that used to be a single `<button class="prop-section-toggle">` spanning full width is now a
flex wrapper div (`background:var(--surface-2); border-bottom:1px solid var(--border)` — moved off the
button and onto the wrapper) containing two siblings: the toggle button (`flex:1 1 auto`, its own
`border-bottom:0` so only the wrapper draws it) and the Next-inspection control, gated the same as before by
`@permission('rental_inspections.create')` and `x-show="chainTail"`. Siblings, not nested — a `<select>`/
`<button>` cannot legally sit inside another `<button>`, which the old accordion-toggle button was. The
helper sentence ("Compares against this inspection's own recorded condition, room by room.") moved from a
trailing `<span>` under the row into a `title` attribute (native tooltip) on the "Next inspection:" label, so
it costs no vertical space in the header. No change to `nextType`/`nextBusy`/`nextError`/`nextInspection()` —
same Alpine state, same call, only the markup's position moved.

**Change 2 — "Add photo section" entry-point button removed from the Inspections tab.** Johan: "the add
photo section is redundant." This was the button (renamed 2026-09-24 per §20.18's sibling note, from
"+ Add section") that called `openAdd()` → `rental-images.save` with `action: 'add_section'`, creating a
named custom photo-gallery folder (`data.custom[]`) — not an inspection. Removed the button and its
explanatory comment only.

**What was NOT removed, and why:** the "Add / Rename photo section" modal (`x-show="modal.open"`) directly
below the removed button is shared — `openRename(customId)` (called from the "Rename" button on each
existing custom section in `rental-section-body.blade.php`) opens the same modal in `mode: 'rename'`.
Deleting the modal would have broken Rename, which Johan did not ask to remove and which is still a live,
needed way to manage existing custom sections. The modal, `openAdd()`, and `submitModal()`'s `add_section`
branch stay in place; `openAdd()` is simply never called from any UI now, so that branch is unreachable but
harmless dead code rather than stripped — stripping it was judged out of this task's exact scope (BUILD
STANDARD rule 6, no silent extras).

**Where else `add_section` is reachable — investigated and reported, not changed:** grepped the whole repo.
The web route `POST rental-images.save` → `PropertyController::saveRentalImagesMeta()` still accepts
`action: 'add_section'` (unchanged, per Johan's explicit instruction not to touch the route/controller/
underlying feature). The ONLY web-UI caller of `openAdd()` anywhere in this codebase was the removed button —
confirmed by grep across `resources/views/`; three unrelated `openAdd()` functions exist in other,
completely separate Alpine components (`dr2/_supplier-work-orders.blade.php`, `tools/pdf_splitter_review.blade.php`,
`communications/triage/index.blade.php`) — different modules, different meaning, not this feature. The
mobile API (`MobileRentalImagesController::save()`, `POST /api/v1/mobile/properties/{property}/rental-images/save`)
also accepts `action: 'add_section'` in its validation rules — that action is reachable from the mobile app
if the native app (source not in this repo) calls it; this spec cannot confirm or rule that out from here.
**Conclusion for Johan: the Inspections tab's button was the only web entry point; whether the native mobile
app itself exposes an "add section" affordance is unverifiable from this repository and needs an answer from
whoever owns that app's source.**

**The two accidental sections on property 5792 — still render.** "Ad Hoc inspection (0)" and "test
inspection (0)" are rows in `data.custom[]` (real `rental_inspection_sections`-style rows created through
the old button before its 2026-09-24 rename). The custom-sections list
(`<template x-for="sec in data.custom">`) is not gated by the Add button at all — it renders every existing
`data.custom` entry unconditionally. Removing the entry point does nothing to remove data that already
exists: **both empty rows still render on the Inspections tab today**, exactly the confusion the 2026-09-24
rename was meant to end, now just without a way to create a third one. Per the no-hard-delete rule and
Johan's own instruction this round, they were left untouched — his call whether to rename, archive, or
merge them.

### 20.19.1 Files touched this round

- `resources/views/corex/properties/show.blade.php` — Inspection section header restructured (Next
  inspection control moved in, old bottom-of-section copy removed); "Add photo section" button removed
  (modal, `openAdd()`, `openRename()`, `submitModal()` all left unchanged)

---

## 20.20 AT-433 Part A — item-cell photos become horizontal strips (2026-09-26, cc)

**Numbering note:** this branch was cut from `origin/QA1` at a point that does not yet include a §20.19
authored on a separate, still-unmerged branch (`origin/cc-inspection-tab-header-controls-2026-09-25` —
"Next inspection" moved to the section header; "Add photo section" removed). That number is already
spoken for there, so this round is filed as §20.20 to avoid a collision when the two branches merge —
whoever performs that merge should confirm no third branch has also claimed 20.20 in the meantime.

Johan approved a mockup: each cell's photos in the item-level comparison row
(`rental-inspection-item-cell.blade.php`, included twice per row by `rental-inspection-recording.blade.php`'s
shared `rir-compare-row`/`rir-compare-cell` grid) become a fixed-size horizontal strip of small thumbnails
instead of the previous 165x124 inline-block scroll strip. Layout only — no data model change, no change to
`openCompareViewer(photo, insp)`'s two-argument contract, no change to recording/autosave logic. The room-level
photo gallery (`.rir-room-photo-tile`) and the untagged tray (`.rir-tray-tile`) are untouched and keep their
existing sizes — a new tile class was added rather than resizing either.

**New CSS** (`rental-inspection-recording.blade.php`'s own `<style>` block, alongside the existing
`.rir-item-photo-tile`/`.rir-room-photo-tile`/`.rir-tray-tile`): `.rir-strip-row` (the horizontal scroller —
always `overflow-x:auto`, never wraps, so a strip too wide for its cell scrolls instead of silently clipping
or pushing the page sideways), `.rir-strip-tile` (86x64, 6px gap, the new thumbnail), `.rir-strip-badge`
(the pair-number badge), `.rir-strip-nomatch`/`.rir-strip-nomatch-label` (the gap placeholder), `.rir-strip-more`
(the "+N" collapse tile).

**Pairing is positional only — the seam Part B replaces.** `stripPairCount(item)` (show.blade.php, next to
`isRoomOpen`) is `Math.max(predecessor's photo count, tail's photo count)` for that item; `_stripPad()` builds
an array of `{ index, photo }` up to that count, `photo` null wherever a side has fewer photos than the other.
Both cells call this with the SAME item, so slot N is always slot N on both sides — `tile.index` (0-based,
badge shows `index + 1`) is a plain array position today, not a real photo-match id. **The exact swap point for
Part B's real pair id:** the `index` field returned by `_stripPad()` in show.blade.php, plus the two
`:key="tile.index"` bindings and the two `x-text="tile.index + 1"` badges in
`rental-inspection-item-cell.blade.php` — replace `index` with the real pair/group id in those four places and
nothing else in this round needs to change.

**NO MATCH placeholder.** Wherever `tile.photo` is null (the shorter side, for that slot), a `.rir-strip-nomatch`
tile renders in the same footprint, still carrying the position badge — a missing photo is visible, not silently
absent.

**Collapse beyond 4.** `stripVisibleCount(item)`/`stripMoreCount(item)` (show.blade.php) cap the rendered slots
at 4 (real or NO MATCH) plus a `.rir-strip-more` "+N" tile when the shared pair count exceeds 4; the cap and the
"+N" count are computed against the SAME shared paired count both cells read, not each side's own raw photo
count, so collapsed and expanded states always show the identical number of slots on both sides — the same
reasoning that keeps thumbnail N level with thumbnail N. Clicking "+N" (or the room-level control below) expands
every slot for that item on both sides at once.

**Per-item and room-level expand/collapse, remembered per user.** `itemStripExpanded` (show.blade.php) is keyed
by `item.id` only — not by side — so one state drives both cells. `toggleItemStrip(item)` is the per-item
control (the tile itself, or its own "+N" tile); `toggleAllItemStrips(group)`/`allItemStripsOpenInRoom(group)`
is the new room-level master switch, added to the room heading's existing button cluster in
`rental-inspection-recording.blade.php` (next to "All Good"/"Mark room N/A"), rendered only on the editable
(tail) render pass — the predecessor cell has no room header of its own to hang a control on, but shares and
reacts to the same state regardless of which side toggled it. **No per-user preference endpoint exists for this
screen** — checked first: `roomOpenOverride`/`roomPhotosExpanded` (the screen's other two "remembered" UI
states) are both plain in-memory Alpine objects, never persisted server-side. Rather than inventing a second
persistence mechanism, this reuses the same client-only `localStorage` pattern the page's own sidebar collapse
(`hfc.propSidebar.collapsed`) already uses, under the key `hfc.inspStripExpanded`, loaded in a new `init()` on
`rentalImages()` (which had no `init()` before this round). This is browser-local, not a real per-account
server-side preference — flagged here rather than silently treated as equivalent; building the latter is a
separate decision if Johan wants the state to follow an agent across devices.

**Found, not fixed — reported per scope lock, not touched:**
- `RentalInspectionChainTest.php`'s `both comparison cells open the shared compare viewer on photo click` test
  (line 581) asserts the rendered page contains the literal string
  `openCompareViewer('item', 'item_' + item.id, null, item)` — a 4-argument call shape that has never matched
  the actual, settled `openCompareViewer(photo, insp)` two-argument contract (§20.17.2) at any point in this
  branch's history, confirmed by grepping the pre-edit `HEAD` copy of `rental-inspection-item-cell.blade.php`
  (0 matches, same as after this round's edit). Pre-existing failure, unrelated to this round's diff — the
  count this assertion checks was already 0 before this change and stays 0 after it.

### 20.20.1 Sweeps run against every changed Blade file, per the four standing gotchas on this screen

- Bound `:style` co-located with a static `style` attribute on the same tag: **0 found.**
- A literal `"` inside a `//` comment inside a quoted Alpine attribute: **0 found** — every `//` comment
  landed inside `show.blade.php`'s real `<script>` tag (not inside any `x-...="..."` attribute string), and
  the two Blade partials use only `{{-- --}}` comments, never an inline `//` inside an attribute.
- `<template x-if>`/`<template x-for>` wrapping multi-root content: **1 found and fixed during this round**
  (not shipped) — the first draft nested two sibling `<template x-if>` tags (photo / NO MATCH) inside one
  `<template x-for>` in `rental-inspection-item-cell.blade.php`; x-for needs exactly one root element per
  iteration just like x-if does, and two sibling `<template>` children broke that. Rewritten to one root
  `<div>`/`<button>` per iteration, with `x-show` (not nested `x-if`) toggling the photo-vs-placeholder
  content inside it. 0 remaining after the fix.
- `hasOwnProperty` on Alpine 3 reactive state: **0 found.**

### 20.20.2 Files touched this round

- `resources/views/corex/properties/partials/rental-inspection-item-cell.blade.php` — the photo-rendering
  block (both the read-only/predecessor and live/tail branches) rewritten around `stripTilesForInspection()`/
  `stripTilesFor()`; the existing select-checkbox control moved from top-left to bottom-left on the live cell
  to make room for the new top-left pair badge (tag-to-room and untag stay top-right/bottom-right, unchanged)
- `resources/views/corex/properties/partials/rental-inspection-recording.blade.php` — new `<style>` rules
  (`.rir-strip-row`/`.rir-strip-tile`/`.rir-strip-badge`/`.rir-strip-nomatch`/`.rir-strip-nomatch-label`/
  `.rir-strip-more`); the room heading's button cluster gained the "Expand photos"/"Collapse photos" toggle
- `resources/views/corex/properties/show.blade.php` — `stripPairCount()`, `_stripPad()`,
  `stripTilesForInspection()`, `stripTilesFor()`, `stripVisibleCount()`, `stripMoreCount()`,
  `itemStripExpanded`, `isItemStripExpanded()`, `toggleItemStrip()`, `allItemStripsOpenInRoom()`,
  `toggleAllItemStrips()`, `_persistStripExpanded()`, and a new `init()` on `rentalImages()`

### 20.20.3 Not built this round (Part B, explicitly out of scope)

Real pairing (matching left/right photos by their actual photo-match-group relationship rather than array
position) and the pair-id-based badge/key described in §20.20.1's swap point above.

---

## 20.22 AT-433 — a photo uploads immediately, always; never staged (STAGE 1: backend, 2026-09-26, cc)

**Numbering note:** §20.21 is reserved by `spec-inspection-strip-file-drop-2026-09-26` (AT-433 Part E, investigation
stage, not yet merged to QA1 at the time this was written) — filed as 20.22 to avoid the collision proactively.
Whoever performs that merge should confirm no third branch has also claimed either number in the meantime.

**The bug, found live on property 5792 (Johan testing QA1, 2026-09-26):** `onItemPhotosSelected()` staged a
photo picked before an item's condition was recorded as a plain in-memory `File` object
(`this.obsForm[key].photos`, show.blade.php) — nothing wrote it to localStorage, IndexedDB, or the server.
`_commitObservation()` only uploaded it once a condition was tapped. Reload, navigate away, or close the tab
before that tap, and the photo was gone — no record, no error, no trace. DB evidence at the time: property
5792's active out-inspection had 25 of 28 checklist items (89%) with zero recorded condition — the exact
exposure surface for a "photograph everything, rate it after" field workflow, which is the NORMAL way to do
a walkthrough, not an edge case.

**Johan's rule, verbatim in substance: "adding a photo uploads it. Immediately. Always. Whether or not a
condition has been recorded on that item... No staging, no commit-later, no hidden dependency on a separate
deliberate action."**

### 20.22.1 The decision: (b), not (a) — reuse the observation, never a second photo-linkage path

Two options were on the table: (a) make `rental_inspection_photos.rental_inspection_observation_id` the
attachment point stay nullable and add a new direct `rental_inspection_item_id` column so a photo can hang off
an item with no observation at all; or (b) create the observation at photo-upload time, with no condition
recorded, and keep the photo linked exactly as it always has been.

**(b) is right, and it costs zero schema migration** — checked directly (`SHOW COLUMNS`), not assumed:
`rental_inspection_photos.rental_inspection_observation_id` is ALREADY nullable, and `condition` on
`rental_inspection_observations` is `varchar(20) NOT NULL` with no CHECK constraint, so an empty string is a
valid value today with no column change. (a) would have needed a new column AND a rewrite of every "get this
item's photos" reader (`itemPhotosFor()` in show.blade.php, the PDF services, the comparison service) to union
two link paths — exactly the "second code path" Johan's own instinct was warning against. (b) means every one
of those readers is completely unchanged: a photo-anchor observation is just one more row in the same
append-only history `itemPhotosFor()` already aggregates across (§20.20's own "Item 6" comment already
established that pattern — every observation for an item, not just the latest one).

### 20.22.2 The sentinel: `RentalInspectionObservation::CONDITION_PENDING = ''`

An empty string, not a word like `'pending'`. Two reasons: it's already a valid value for the existing
`NOT NULL varchar(20)` column (zero migration), and it's already falsy in JS — every existing client-side
truthiness check on `.condition` (`selectedConditionFor()`, show.blade.php) needs no new constant to exclude
it correctly. `storeObservation()`'s own `Rule::in(array_column($conditionStates, 'key'))` validation already
rejects an empty string from the real recording endpoint, so only the new photo-upload path can ever produce
one — a normal user action can never submit this value by mistake.

**`isPending(): bool`** and **`scopeRecorded()`** (`->where('condition', '!=', CONDITION_PENDING)`) added to
the model (`app/Models/RentalInspectionObservation.php`) as the ONE filter every "is this item recorded"
query must apply.

### 20.22.3 The non-negotiable, audited: every site that reads "is this item recorded"

Johan's own words: "if creating an observation to hold a photo silently ticks an item as recorded, we have
traded a data-loss bug for a false-completion bug, which is worse." Checked every call site that reads an
item's condition or counts what's recorded — found EIGHT, not the one or two an ad-hoc patch would have
caught, several with real, demonstrable consequences beyond a wrong progress counter:

| # | Site | File:line | Without the fix |
|---|------|-----------|------------------|
| 1 | `RentalInspectionItem::currentObservation()` | `app/Models/RentalInspectionItem.php:131` | An item's "current condition" (used everywhere the property-level fact matters) resolves to an empty string instead of falling through to an earlier real value or null. |
| 2 | `RentalInspection::itemsWithMissingRequiredNotes()` | `app/Models/RentalInspection.php:231` | A photo-only item gets flagged "condition requires notes but notes are empty" (`conditionRequiresNotesFor()` defaults an unrecognized key to `true`, confirmed by reading it directly) — **blocks `startAwaitingSignature()`/`markCompleted()`** for an agency with the notes-gate enabled, over a photo nobody has even looked at yet. |
| 3 | `RentalInspection::historyFor()` | `app/Models/RentalInspection.php:735-737` (both branches) | The predecessor "Good → Good → Damaged" run (and `RentalInspectionReportPdfService`'s `history_text`, `ucfirst()`'d) prints an empty segment into the arrow-chain on the COMPLETED report. |
| 4 | `RentalInspectionRecordingController::markRoomGood()` | `.../RentalInspectionRecordingController.php:566` | `$alreadyRecordedItemIds` counts the pending row's mere existence — "All Good" silently SKIPS an item that only has a photo, leaving it stuck requiring a manual tap even though the agent explicitly asked to bulk-fill everything. |
| 5 | `RentalInspectionRecordingController::markAllGood()` | same file, `:606` (post-edit line) | Same bug, whole-inspection scope. |
| 6 | `RentalInspectionDiscrepancy::detectFor()` | `app/Models/RentalInspectionDiscrepancy.php:113` | If a DIFFERENT agent than whoever uploaded the photo later records the real condition, `sameAuthor()` doesn't suppress the comparison — a spurious `RentalInspectionDiscrepancy` row gets created purely because someone uploaded a photo before someone else assessed the item. Guarded twice: an early return if `$newObservation` is itself pending, AND `->recorded()` on the conflict-candidate query, so neither direction can produce a false conflict. |
| 7 | `RentalInspectionComparisonService::compareItems()` | `app/Services/RentalInspectionComparisonService.php:130-144` | The in/out deposit-dispute comparison treats an empty string as a real, gradeable condition (`'' !== 'n_a'` is true) and can surface it as a "declined"/"improved" finding — corrupting the one document this whole module exists to produce correctly. |
| 8 | `RentalInspectionReportPdfService::generate()` | `app/Services/Rentals/RentalInspectionReportPdfService.php:43` | The COMPLETED inspection's own printed report's `$currentByItem` shows an empty string as the item's assessed condition — this can genuinely reach a finished, legally-relevant PDF, since nothing today gates completion on every item being recorded (checked `RentalInspection::markCompleted()`/`startAwaitingSignature()` directly — the only gates are discrepancy-resolution, required-notes, and signatures; no "every item assessed" check exists at all, with or without this change). |

**Checked and found NOT to need a change:** `RentalInspectionFormPdfService` — the BLANK pre-inspection OMR
capture form (Johan's own docblock: "generated BEFORE an inspection happens"). It lays out tick-box
coordinates from the agency's condition vocabulary; it never reads an existing observation's condition at all,
so there's nothing here for a pending row to corrupt. `RentalInspection::tabPayloadFor()` — deliberately
unfiltered; it must keep forwarding every observation, pending included, to the client exactly as it does
today, since `itemPhotosFor()` needs the pending row to find the photo. Only the DERIVED "is this recorded"
reads change, never the raw data feed.

**Still open, blocked on Stage 2's own release (cannot touch `show.blade.php` while cc3 is in it):**
`roomProgress()` (show.blade.php:7031) and `inspectionProgress()` (show.blade.php:7240) both currently do
`group.items.filter(i => this.conditionFor(section, i.id))` — truthy on the OBSERVATION OBJECT, not
`.condition`. A pending observation is a truthy object with an empty `.condition`, so these two progress
counters will over-count "recorded" the moment Stage 2 ships unless changed to
`this.conditionFor(section, i.id)?.condition` (empty string is already falsy — no new constant needed
client-side either). This is a REQUIRED part of Stage 2, bundled with `onItemPhotosSelected()`'s own rewrite,
not optional polish — flagged here now so it isn't dropped once cc3 is out and the Blade edit actually happens.

### 20.22.4 The new upload path

`RentalInspectionRecordingController::storePhotos()` (`POST /corex/rental-inspections/{inspection}/photos`)
gains an optional `rental_inspection_item_id` input, independent of the existing
`rental_inspection_observation_id` (unchanged, still the path used whenever a caller already has a real
observation id — `storePhoto()` singular and any pre-AT-433 caller are untouched). When `item_id` is given and
no `observation_id` is, a new private helper resolves the attachment:

**`currentOrPendingObservationFor(RentalInspection, RentalInspectionItem, User)`** — finds the item's own
observation on THIS inspection (real or an earlier pending one) if any exists, or creates a new pending one.
Uses plain `RentalInspectionObservation::create()`, deliberately never `::record()` — a pending row is not an
assessment and must never run discrepancy detection (§20.22.3 item 6's guard is belt-and-suspenders on top of
this, not the only protection).

**Known, accepted race, not engineered away:** two near-simultaneous uploads to the SAME never-before-touched
item can each see "none exists yet" and each create their own pending row. Harmless — `scopeRecorded()`
excludes every pending row regardless of how many exist, and every "this item's photos" read already
aggregates across all of an item's observations — but no DB-level lock was added, since a unique index on
`(inspection, item, condition)` would also block the legitimate, real feature of re-confirming the same real
condition twice (`test_the_same_agent_correcting_their_own_earlier_condition_does_not_create_a_discrepancy`
already proves that path must keep working).

### 20.22.5 Tests

Four new cases in `tests/Feature/RentalInspections/RentalInspectionRecordingControllerTest.php` (the "AT-433,
2026-09-26" group, right after the existing photo tests): a photo to an unrecorded item creates a pending
observation AND the item reads as unrecorded everywhere checked (`scopeRecorded()`, `currentObservation()`,
`itemsWithMissingRequiredNotes()`); two photos before any condition share one observation, not two; "All
Good" still fills in an item that only has a photo; a different agent's real condition after a photo does not
raise a spurious discrepancy. Run in an isolated worktree with its own independent `composer install` (QA1's
own `vendor/` has no dev dependencies — see the verification-floor answer from the same day) — all new tests
green, no change to the pre-existing baseline failures.

### 20.22.6 Files touched this round (Stage 1 — backend only)

- `app/Models/RentalInspectionObservation.php` — `CONDITION_PENDING`, `isPending()`, `scopeRecorded()`
- `app/Models/RentalInspectionItem.php` — `currentObservation()` gains `->recorded()`
- `app/Models/RentalInspection.php` — `itemsWithMissingRequiredNotes()`, `historyFor()` both gain the filter
- `app/Models/RentalInspectionDiscrepancy.php` — `detectFor()` early-return guard + `->recorded()`
- `app/Http/Controllers/CoreX/RentalInspectionRecordingController.php` — `storePhotos()`'s new `item_id`
  path, `markRoomGood()`/`markAllGood()` gain `->recorded()`, new `currentOrPendingObservationFor()`
- `app/Services/RentalInspectionComparisonService.php` — `compareItems()`'s two observation queries gain `->recorded()`
- `app/Services/Rentals/RentalInspectionReportPdfService.php` — `generate()`'s `$currentByItem` excludes pending
- `tests/Feature/RentalInspections/RentalInspectionRecordingControllerTest.php` — four new tests
- No migration. No change to `rental_inspection_photos`, `rental_inspection_observations`, or any other schema.

### 20.22.7 Not built this round (Stage 2, blocked on cc3 releasing show.blade.php)

`onItemPhotosSelected()` posting immediately instead of staging; the PENDING strip tile changing meaning from
"waiting for you to set a condition" to "uploading right now" with a failure/retry state; `roomProgress()`/
`inspectionProgress()`'s one-line fix from §20.22.3's open item above, bundled into the same Blade edit since
it's the same file at the same moment of availability.

---

## 20.23 AT-436 — Stage 2: the Blade/JS side of "a photo uploads immediately" (2026-09-27, cc)

Completes §20.22 (Stage 1, backend) — same bug, same fix, the client half. Built the moment `show.blade.php`
was free of cc3's Part C work.

### 20.23.1 `onItemPhotosSelected()` — no staging branch left at all

Before: an item with no recorded condition staged picked files in `obsField(...).photos` (plain `File` objects,
in-memory only — the exact data-loss bug §20.22 exists to fix) and uploaded them only once `_commitObservation()`
created a real observation. After: every file always calls `photoUploader(section).uploadFiles(files, {
rental_inspection_item_id: item.id })` — one call, no branch, no knowledge of whether the item has a condition
yet. The server (`currentOrPendingObservationFor()`, §20.22.4) resolves that. `obsField()`'s default shape lost
its now-meaningless `photos: []` key; `_commitObservation()` lost the ~15-line dead block that used to upload
`stagedPhotos` on commit — with nothing ever staging a file client-side any more, that block could never run.

**The wiring gap this surfaced, fixed alongside it:** `itemPhotosFor()` resolves a photo through
`insp.observations` (filtered to the item, then `photoUploader().itemPhotos(obsIds)`) — it has no way to know
about an observation the CURRENT request just created server-side. Extended `storePhotos()`'s JSON response
with a top-level `observation` key (the resolved-or-created one, `null` when neither an item nor an
observation id was given) and added `uploadFiles()`'s third, optional `onBatchDone` argument
(`public/js/corex-photo-batch-uploader.js`) — called with `(responseBody, entry)` once a batch's POST succeeds,
stored ON the entry so `retryBatch(entry)` keeps calling it on every retry, not just the first attempt. A new
`_mergeObservation(section, observation)` folds it into `insp.observations` (a no-op if the id is already
there, which is every case except a photo landing on an item with no observation at all before this exact
request) — this is the ONLY reason the just-uploaded photo shows up in the strip without a reload.

### 20.23.2 The PENDING tile: from "waiting for you" to "uploading right now"

`stagedPhotosFor()` is gone — nothing stages a file any more, so there was nothing left for it to read. Replaced
by `pendingUploadTilesFor(section, item)`: reads `photoUploader(section).uploadBatches` directly (the SAME array
the untagged tray's own "Uploading… N%" / failed-with-retry row already reads, `rental-inspection-recording.
blade.php`'s tray section — not a second upload-tracking mechanism), filtered to this item's own batches
(`extraFields.rental_inspection_item_id === item.id`) that aren't `'done'`, flattened to one entry per FILE
(`{file, batch}`) so a mixed batch still renders one tile per photo, each carrying its own batch's shared
status/error.

**A failed upload says so and offers a retry — it never silently vanishes** (Johan's own words: "which is the
entire defect we are fixing"). The tile's label is a `<button>`, not a `<span>`: `disabled` while `status !==
'failed'` (PENDING, non-interactive — native `disabled`, no `pointer-events` hack needed) and a real, clickable
`Retry` calling `photoUploader(section).retryBatch(entry.batch)` when `status === 'failed'`, `:title` carrying
the exact server error. `retryBatch()` re-runs the SAME batch (same files, same `extraFields`) — a genuine
retry of the same upload, not a new one, and since `onBatchDone` is stored on the entry (§20.23.1), a
successful retry still merges the observation and revokes the preview URL exactly as a first-try success would.

**The add-tile's own tint/tooltip** used to read `obsField(...).photos.length` (the staged-and-waiting queue) —
that queue no longer exists, so it now reads `pendingUploadTilesFor(...).length`: highlighted while genuinely
uploading, tooltip "Uploading…" instead of the old (now false) "saves once this item is recorded".

**Constraint honoured:** no absolutely-positioned element went back into `.rir-strip-row` — the retry/PENDING
button is `position:absolute` *within* its own `.rir-strip-tile` (which already has `position:relative` via the
existing `relative` utility class), the exact same pattern the tile's own Select/tag/untag buttons already use.
Nothing was added as a sibling of the row itself.

**Preserved unchanged, confirmed still correct:** cc6's pairing-drag guard on an id-less photo
(`pairDropOnPredecessor()`, show.blade.php — "This photo is still uploading — it can be paired once it has
finished.") needed no change at all. It was written for a staged file with no server id; a file mid-upload also
has no server id, for the identical reason, and cc6's own message already describes the CORRECT AT-436 meaning
verbatim, by coincidence, not by editing it — checked directly, not assumed.

### 20.23.3 `roomProgress()`/`inspectionProgress()` — the SAME correction as the 8 backend sites

Both used to do `group.items.filter(i => this.conditionFor(section, i.id))` — truthy on the whole observation
OBJECT. A photo-anchor observation (§20.22's `CONDITION_PENDING`, an empty string) is a real, truthy object, so
before this fix, adding a photo to an unrecorded item would have made every "N/28 recorded" counter, "All
Good"'s visibility gate, and the room-auto-collapse-on-complete logic all believe that item was assessed the
instant a photo landed on it — the exact false-completion risk named on the backend side, now closed on the
one screen Johan actually reads the number from. Fixed to `this.conditionFor(section, i.id)?.condition` —
empty string is already falsy, so this needed no new constant, matching `selectedConditionFor()`'s own
(already-correct) pattern one function up. `_collapseRoomIfCompleteById()`, the room-open toggle, and the tray
header's own progress text all consume `roomProgress()`/`inspectionProgress()`'s return value rather than
recomputing "recorded" themselves — fixing the two source functions was the whole fix, confirmed by reading
every caller, not assumed from the function names.

### 20.23.4 Re-checked the whole file for the same class of bug — found exactly these two, no others

Grepped every `conditionFor(section, ...)` call site in `show.blade.php` (six total): `onItemPhotosSelected`'s
old branch (removed, §20.23.1), `selectedConditionFor()` (already correct — `?.condition || null`),
`roomProgress()`/`inspectionProgress()` (fixed, above), `itemChoicesFor()` (returns `observationId` for photo
destination pickers — correctly treats ANY observation, pending or real, as a valid place to file a photo; not
a "recorded" check, no bug), and `ensureObservationFor()` (a DIFFERENT, pre-existing, out-of-scope surface —
see below). No other independent "is this item recorded" computation exists anywhere else in the file — every
other progress/completion display reads `roomProgress()`/`inspectionProgress()`'s own output.

**Found, not touched — a different surface, a different root cause, pre-dates AT-433/436:**
`ensureObservationFor()` (show.blade.php, 2026-09-22 — the "drag an untagged room photo onto an item" / "Move
to…" viewer feature) creates a NEW observation with the agency's `baselineConditionKey` — a REAL condition,
immediately — for an item with nothing recorded, rather than a photo-anchor row. This is the OPPOSITE risk
direction from the bug this round fixes (it over-records with a real value on first touch, rather than
under-recording with an empty one) and is a genuinely different gesture (re-filing an EXISTING, already-
uploaded photo, never a NEW upload) — not part of AT-433/436's named scope. Flagged here per the standing
report-don't-fix rule; Johan's call whether this deserves its own round.

### 20.23.5 Verification

`php -l` clean on all five changed files; `node --check` clean on the JS file. `php artisan view:clear` +
a real authenticated fetch (`scripts/fetch-authenticated-page.php`, property 5792) + `scripts/verify-alpine-
render.mjs` per standing rule 2398bfd99 — run against BOTH this round's diff and the pre-diff baseline (Stage 1
only, via a temporary `git stash`) to isolate what this round actually changed: identical pre-existing failures
on both (two `localStorage`/`document`-referencing inline `x-data` blocks unrelated to rental inspections —
sidebar collapse, the readiness widget — plus a `form.getAttribute` script-eval issue and several WARN-only
scope-gap heuristics on the spaces/showdays/contact-linking/wellbeing-report areas of this same large property
page), none of it new. The specific number the standing rule names — Alpine expression syntax errors (check 4,
the actual "every Alpine attribute value... compiles clean" pass/fail signal) — is **zero**, both before and
after: 1929 expressions baseline, 1934 with this round's five new bindings, all compiling clean either way. The
gate's OVERALL exit code is still non-zero on this page regardless of this round's diff (pre-existing,
unrelated failures) — reported exactly as that, not glossed over as a pass.

Five backend tests already covered §20.22's invariants (Stage 1). One assertion added to the first of those
(`test_uploading_a_photo_to_an_unrecorded_item_creates_a_pending_observation_the_item_is_not_recorded`) checks
the new `observation` response key directly, since without it this round's client-side merge has nothing to
merge. Run in the same isolated worktree pattern as Stage 1 (QA1's own `vendor/` has no dev dependencies) — all
green, no new baseline failures.

### 20.23.6 Files touched this round

- `resources/views/corex/properties/show.blade.php` — `onItemPhotosSelected()` rewritten, `_commitObservation()`
  loses its dead staged-photo block, `obsField()` loses `photos: []`, `stagedPhotosFor()` replaced by
  `pendingUploadTilesFor()`, new `_mergeObservation()`, `roomProgress()`/`inspectionProgress()` fixed
- `resources/views/corex/properties/partials/rental-inspection-item-cell.blade.php` — the staged-photo tile
  block replaced with the pending-upload/failed-retry tile; the add-tile's tint/tooltip re-pointed at
  `pendingUploadTilesFor()`
- `resources/views/corex/properties/partials/rental-inspection-recording.blade.php` — `.rir-strip-pending-label`
  reworked as a real (disableable) button; new `.rir-strip-pending-failed`
- `public/js/corex-photo-batch-uploader.js` — `uploadFiles()`/`_cpu_uploadBatch()` gain the optional
  `onBatchDone` callback, stored on the batch entry
- `app/Http/Controllers/CoreX/RentalInspectionRecordingController.php` — `storePhotos()`'s response gains the
  `observation` key
- `tests/Feature/RentalInspections/RentalInspectionRecordingControllerTest.php` — one new assertion

No migration. No new backend "recorded" call site touched — Stage 1's audit already covered all eight.

---

## 20.24 AT-433 Part E — drop a desktop file onto an item's strip to upload it (built, 2026-09-27, cc)

The investigation-only round (`spec-inspection-strip-file-drop-2026-09-26`, its own branch) never landed on QA1 — this
section is the build, written against what actually shipped, superseding that round's predictions with what was
verified true. Johan's ask: "dropping a photo file from the desktop onto an ITEM'S photo strip uploads it to that
item. Today only the room heading accepts a drop, so an agent who drops where they are looking gets nothing."

### 20.24.1 One dispatcher, branched on `dataTransfer.types` — read against cc6's LANDED code, not assumed

cc6's pairing drag (`insp-photo-notes-2026-09-26`/QA1 merge history, `photoDraggedForPairing()`/`pairDragOverTile()`/
`pairDropOnPredecessor()`, show.blade.php) was read directly in this checkout before writing a line here. Its
`@dragover`/`@drop` handlers live on the **read-only predecessor cell's individual tiles**
(`rental-inspection-item-cell.blade.php`'s `@if($readOnly)` branch) — never on the tail/editable side at all. The
tail side's `.rir-strip-row` had no drag handling whatsoever before this round. So the two features don't even share
a target element — but the type-gate is still applied on this side anyway, both because Johan asked for it explicitly
and because a drag genuinely can pass over the tail strip on its way to the predecessor cell one column over, and
that pass-through must be inert.

New `.rir-strip-row` (tail/editable side only, `@unless`-equivalent via a duplicated `@if($readOnly)/@else` on the
row's own opening tag — the row's tile CONTENTS were already branched this way, only the opening tag needed
splitting):

```
@dragover="stripDragOverTile($event, item.id)"
@dragleave="stripDragLeaveTile()"
@drop="stripDropOnTile($event, {{ $inspectionJs }}, item)"
```

`stripDragOverTile()`/`stripDropOnTile()` (show.blade.php) both start with the same guard:
`if (!Array.from(event.dataTransfer.types || []).includes('Files')) return;` — no `.prevent` Alpine modifier used
anywhere (that would call `preventDefault()` unconditionally); `event.preventDefault()` is called manually, only
inside that guard. A genuine OS file drag always carries the `'Files'` type; an in-page `dragstart(setData(...))`
call (cc6's pairing drag, or the pre-existing tag-to-room drag `dropOnRoom()`) can never produce it — mutually
exclusive by construction, verified live (below), not just argued. If a drag somehow carried both, `Files` wins:
checked first, unconditionally — a real, visibly-dragged file always takes priority over any same-drag internal
payload.

### 20.24.2 Same path, not a second one

`stripDropOnTile()`'s file branch is one line: `await this.onItemPhotosSelected(section, item, event.dataTransfer.files);`
— the exact function the add-tile's `<input type=file>` already calls. Since AT-436 (§20.22/20.23) shipped first,
that function no longer branches on whether the item has a condition — every file always posts immediately,
so a drop on an unrecorded item behaves identically to picking the file: upload now, PENDING tile, real thumbnail
when the POST returns, a failed upload shows Retry. No new upload path, no new staging, nothing Part E owns beyond
routing a `drop` event into the same call an existing control already makes.

### 20.24.3 Affordance — the row's own box, no new child

`stripFileDragOverItemId` (show.blade.php, single scalar, same shape as the pre-existing `dragOverRoom`) drives a
`:style` binding added to `.rir-strip-row` itself:

```
:style="stripFileDragOverItemId === item.id ? 'outline:2px dashed var(--brand-icon,#0ea5e9); background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 15%, transparent);' : ''"
```

Identical dashed-outline/tint values to `dragOverRoom`'s own convention on the room heading (`rental-inspection-
recording.blade.php`) — reused deliberately, not reinvented, so an agent who already knows what that signal means
on the room heading reads the same thing on the item strip. No new element — the row already exists and had no
`:style` binding before this round, so there is no clobber risk (checked: nothing else on this element sets a
static `style` attribute). This is exactly the constraint the overlap fix (§20.20 follow-up) established: never an
absolutely-positioned child back inside `.rir-strip-row`. Nothing was added as a child at all here — only a style
toggle on the row's own existing box.

### 20.24.4 Room-heading drop — unchanged, confirmed by diff

`dropOnRoom()` and its `dragOverRoom`/`@dragover`/`@drop` wiring are untouched — checked via `git diff`, zero lines
changed in that function or its call sites. Johan's ruling stands unargued: it is a different, valid gesture (tag
an already-uploaded, untagged photo to a room) solving a different problem than Part E (upload a new file directly
to an item).

### 20.24.5 Verified live — drag, not click, and coexistence proven, not assumed

Per standing rule 2398bfd99 (Alpine render gate before push) plus Johan's explicit "drag the real thing in a browser
before you call it done": built and served from an isolated worktree (`composer install` + `npm run build` +
`php artisan serve`, own vendor and own compiled assets, this repo's own `fetch-authenticated-page.php` pointed at
that local server rather than QA1 — the same pattern cc3's Part C round used for the identical reason: this branch
was never deployed to QA1). `scripts/verify-alpine-render.mjs` against a real authenticated fetch: **1986 Alpine
attribute expressions, all compile clean** — same pre-existing, unrelated failure set as every prior round on this
page (two inline `x-data` blocks unconnected to rental inspections, one script-eval issue), nothing new.

Real drag verified with a genuine `DataTransfer` carrying an actual `File` object (not a stand-in string),
dispatched as real `dragover`/`drop` `DragEvent`s at the live `.rir-strip-row` element in a real Chromium page —
never a direct call to `stripDropOnTile()` itself:

- A `text/plain` JSON payload (cc6's own pairing-drag shape, `[999999]`) dragged over the tail strip: **no outline
  shown, no upload, no tile created.** Confirms coexistence by observation, not assumption — the exact thing Johan
  asked to see checked rather than read and moved past.
- A real `File` dragged over the same strip: the dashed-outline/tint affordance appeared, matching the `:style`
  string above exactly.
- Dropped: uploaded through the real endpoint, a real photo tile rendered after the batch settled.
- Reloaded: the dropped photo was still there — the actual persistence promise, not just a client-side illusion.
- Zero new console errors (64 present, all four identical pre-existing `featureCategoryTab`/`catDef`/`features is
  not defined` messages already tied to the unrelated Features/amenities picker component, confirmed against the
  same baseline every prior round in this file has already ruled out).

### 20.24.6 Files touched this round

- `resources/views/corex/properties/partials/rental-inspection-item-cell.blade.php` — `.rir-strip-row`'s opening
  tag split into an `@if($readOnly)/@else`, the editable branch gaining the drop handlers and affordance `:style`
- `resources/views/corex/properties/show.blade.php` — new `stripFileDragOverItemId` state, `stripDragOverTile()`,
  `stripDragLeaveTile()`, `stripDropOnTile()`

No migration. No change to `dropOnRoom()`, `photoDraggedForPairing()`, `pairDragOverTile()`, or
`pairDropOnPredecessor()`.

---

## 20.25 The AT-436 "ghost row" scare — traced, not the code, and fixed anyway (2026-09-27, cc)

cc5 found a real `rental_inspection_photos` row (id 120, property 5792) whose `storage_path` pointed at a file
that does not exist on disk — reported as data loss, correlated with AT-436's backend landing on QA1
(b7ee63e15) at 2026-09-26 16:55, and escalated as urgent: "we replaced a bug that lost photos on reload with a
bug that records a photo and stores nothing."

**Traced, confirmed from evidence, and the correlation did not hold.** `storePhotos()`
(RentalInspectionRecordingController.php:799-812) already calls `PropertyImageStorer::store()` and uses its
return value to build `RentalInspectionPhoto::create()` — store-then-record, identical line order for both the
pre-AT-433 `$observationId`-direct branch and the new `$itemId → currentOrPendingObservationFor()` branch. No
code-level difference in write ordering between old and new paths; the storage path is built only from
`$propertyId`, never from the observation. Enumerated every photo created since 2026-09-26 16:55 (25 rows) and
checked each on disk, not just the two that fit the theory: **22 of 25 exist and serve.** The three that
don't are two unrelated, identified causes, neither of which is AT-436's write path failing:

- **Id 120** — a testing artifact. A `php artisan serve` process run from an isolated worktree, `.env` pointed
  at the real `corex_qa1` database for a genuine-upload verification, wrote its file to that worktree's own
  separate `storage/app/public/` rather than `/corex-qa1`'s. The row landed in the shared database; the file
  landed on a filesystem that no longer exists (the worktree was later removed). See STANDARDS.md Rule 18 —
  this exact incident is why it now exists.
- **Ids 110/111** — an automated gate/smoke-test writing a literal fixture path (`/gate-fixture/ceiling.jpg`)
  directly into the real QA1 database against a synthetic "Gate Fixture Property" (id 21056), never meant to
  have a real file behind it. Not Johan's data, unrelated to AT-436.

Confirmed live, not inferred: POSTed a real photo to the live `storePhotos()` endpoint on `qatesting1.corexos.co.za`
before any fix was deployed — file landed on disk, served 200. Uploads were not failing at the time of the
report.

**Property 5792, checked end to end per Johan's own instruction ("look at the whole of property 5792 the way
he sees it"):** every LIVE (non-deleted) photo row on the property — 108 rows — checked against disk.
**Zero missing** once id 120 (below) is accounted for. Whatever else was rendering as broken on that screen
was not a missing-file/storage-path problem across the rest of the property.

**Photo id 120 soft-deleted** (`RentalInspectionPhoto::find(120)->delete()` — the model's own `SoftDeletes`
trait, `deleted_at` set, confirmed absent from a live re-fetch of the tab-data payload). Ids 110/111 left
untouched — they sit on the fixture property, not Johan's. No hard deletes, per standing rule.

**The class fix, landed regardless of cause:** `PropertyImageStorer::store()` — the one shared point every
caller (rental inspections, the marketing gallery, the mobile API) gets a URL back from — now verifies the
file exists and is non-empty on the same disk instance, immediately after the initial write and again after
`downscale()` (which overwrites in place and could otherwise leave a corrupted/truncated file passing the
first check). Throws before returning a URL if either check fails, so the caller's `::create()` is never
reached — no row to roll back, because nothing was written. Three new tests
(`tests/Feature/Images/PropertyImageStorerTest.php`): the happy path still returns a URL whose file verifiably
exists; a path that never landed is refused, not handed back; a zero-byte file is refused too.

**The testing-practice fix:** STANDARDS.md Rule 18 — a local worktree's own dev server must never point at a
shared database. The same root shape as the `/corex-qa1`-checkout incident the Conductor & Lane Intake
Protocol already exists to prevent: an isolated resource (a worktree's filesystem, a worktree's checked-out
branch) mixed with a shared one (the QA1 database, the QA1 deploy target), trusted to behave like one
coherent environment. Two sound ways to exercise a real write against real data from now on, never a mix:
against the shared environment itself in place, or against a fully isolated throwaway schema.

Landed on QA1 (merge commit `551666fb9`, fast-forwarded into `/corex-qa1`, `php8.2-fpm` reloaded, no
migration). Re-verified post-deploy with one more live upload — still lands on disk correctly.

---

## 21. Add an item to an EXISTING room (2026-09-22, cc1) — there was no way to do this at all

Johan, verbatim, looking at property 4862's Inspection Items panel: *"I want to add lets say bic to
bedroom 1 - no ways to do this? I tried to select kitchen, enter the inspection item name clicked add
and it adds a whole space. so please explain to me how do I add to a room, not a new room."*

**What was actually there, checked directly against the deployed screen, not assumed:** the Inspection
Items "Add" row had exactly one type picker — **Space | Meter** — a WHAT-AM-I-CREATING choice, never a
WHICH-ROOM choice. Selecting "Space" and picking a room TYPE (e.g. Kitchen) from the second dropdown
looks like naming an existing room but isn't — `RentalInspectionRecordingController::storeItem()`
(§14.1) unconditionally created a brand-new `PropertyRoom` from whatever label was typed, seeded with
that room type's checklist (`RentalInspectionSetting::roomTypeItemsFor()`). There was no code path
anywhere — web or, by extension, the future mobile API (§14.2) that calls the same method — that took
an existing `property_room_id` and added one facet to it. The control did exactly what it was built to
do; it just could not do the thing Johan needed.

**Checked and answered directly, not assumed: editing the room-type vocabulary in Settings would not
have helped either.** `RentalInspectionSetting::roomTypeItemsFor()` is read exactly once, at the moment
`createRoomChecklist()` creates a NEW room (`storeItem()`/`assignType()`) — it is never re-applied to a
room that already exists. Bedroom 1 on property 4862 already existed; nothing in Settings reaches back
into an already-built room, regardless of what the agency's checklist defaults say today.

### 21.1 The fix — a third, additive `kind` at the request level, not a new stored kind

`RentalInspectionItem::KIND_SPACE`/`KIND_METER` (the two STORED values) are unchanged. `storeItem()`
now also accepts `kind=item`, which is a REQUEST-shape distinction only — the row it creates still
stores `kind='space'`, because it behaves exactly like any other facet under that room (§3.1: an item
has no condition of its own; conditions/notes/photos are observations against it, identical machinery
regardless of how the item came to exist). `kind=item` requires `property_room_id` (an existing,
non-retired `PropertyRoom` on this property) instead of `space_type`, and creates exactly the one row
asked for — no checklist reseed, no new room. The existing `space`/`meter` branches are byte-for-byte
unchanged; Space still creates a new room, Meter still creates a bare item, neither regressed.

The Add row's type picker gained a third option, **"Item (in a space)"**, and a room-picker `<select>`
(sourced from the property's own already-built rooms, shown only when `kind=item`) appears in the same
slot the room-TYPE picker occupies for `kind=space` — one row, one control set, matching the shape Johan
asked for. The placeholder text is now kind-dependent (`newItemPlaceholder()`) rather than the old
static "e.g. Bedroom 2, Water meter", which no longer implies only two kinds exist.

### 21.2 Full CRUD — rename, reorder within the room, retire/restore

- **Rename** — `RentalInspectionItem::rename()`, a new `.../rename` endpoint. Label only; never touches
  `id`, so every observation/photo/discrepancy already recorded against the item stays attached to the
  exact same row, unaffected.
- **Reorder within a room** — new `sort_order` column (mirrors `property_rooms.sort_order` exactly —
  same type, same default, same `orderBy('sort_order')->orderBy('id')` tiebreak convention already used
  for rooms). Move up/down per item, scoped to `property_room_id` so one room's reorder can never touch
  another room's items even on the same property. A freshly-seeded room's checklist facets now get
  sequential `sort_order` at creation (previously all `0`, relying only on the `id` tiebreak) —
  cosmetic today, meaningful now that items are explicitly reorderable.
- **Retire/restore** — retire already existed (§3.3, `is_retired`, never `deleted_at` — the architectural
  reason is unchanged and not revisited here). **Restore did not exist** — a retired item had no way
  back in the UI at all. New `.../restore` endpoint + a closed-by-default "N retired item(s)" toggle on
  the panel (matching the archive/restore convention already used elsewhere in CoreX), never a badge on
  every row.
- Every one of these follows the exact same `abort_if($item->property_id !== $property->id, 404)`
  scoping already used by `retireItem()`/`assignType()` — AGENCY is the `Property` route-model-binding's
  own global scope (never crossed); this is the additional per-property check within it. No new
  permission key — these routes sit in the exact same `access_properties` group every sibling item route
  already does, unchanged.

### 21.3 Not built, deliberately

No new agency setting was added. Johan's build brief asked for "agency-configurable... for any limit or
label" — checked against what was actually needed: the only field-level constraint here is the item
label's 191-character max, which already matches the pre-existing convention every other label on this
same panel uses (space label, meter label, room-type-picker free text). There is no new numeric
threshold (e.g. a max-items-per-room cap) anywhere in this build for a setting to govern — inventing one
nobody asked for would be exactly the kind of unrequested addition CLAUDE.md rules against. If Johan
wants such a cap, that is a real, separate ask.

### 21.4 Verified

`php -l` on every changed file; `php artisan view:clear`; the existing
`tests/Feature/RentalInspections/RentalInspectionRecordingControllerTest.php` extended in place (not a
new file) with the add-to-existing-room path (including the regression proof that `kind=space` still
creates a new room, untouched), rename, restore, and reorder-scoped-to-one-room cases, run against the
per-lane isolated test database. No browser harness, no dev server, no minted session — per Johan's own
standing instruction for this build, verification stopped at that line; the deployed screen is his to
confirm.

---

## 22. Photo tagging — the governing model, all six moves BUILT (2026-09-22, consolidated same day)

**Consolidation note (spec-only pass, no code changed):** §20.13/§20.14 above were each written mid-build,
before the full photo-tagging pass landed. This section pulls the finished shape together in one place —
the model that governs it, exactly which of the six required moves are built and where each one lives —
so a lane resetting tomorrow reads the actual finished design instead of reconstructing it from several
mid-build sections. Nothing here contradicts §20.13/§20.14's own factual claims about what THEY built;
§20.14.3 above has its own inline correction for the one place it did go stale (the room-photo clip
mechanism). This section additionally records the debugging story behind the item-photo-strip sizing
fix, which existed only in commit history before now, so it survives a lane reset.

### 22.1 The model

A photo lives in exactly one of three places, never more than one at a time:

- **UNTAGGED** — sitting in the inspection's own tray (`rental_inspection_id` set, `property_room_id`
  AND `rental_inspection_observation_id` both null).
- **ROOM** — a general shot of a space (`property_room_id` set, `rental_inspection_observation_id`
  null).
- **ITEM** — filed against one specific facet within that space, e.g. "Ceiling" (both set — the item's
  own room is always resolved server-side from the observation, never trusted from the client; §20.13.1).

**Tagging supersedes, it never appends** (`RentalInspectionPhoto::tagTo()`) — moving a photo is a plain
column update, not a new row. **Untag is the identical operation in reverse** (`tagTo(null, null)`) — no
separate "undo" mechanism exists because none is needed. **Soft delete only** — `archive()` is a real
`deleted_at`, never a hard delete (non-negotiable #1); wired into the tray today, not yet into the
already-tagged room/item views (§20.13.6's own "Found, not fixed," unchanged by this consolidation).

### 22.2 All six moves — confirmed built, one action each, not a two-step trip

| Move | Control | Destination count | Where it lives | Code |
|---|---|---|---|---|
| untagged → room | drag-drop onto a room heading, OR select photo(s) + "Tag to…" dropdown + "Tag selected" | many (every room) | the tray's own selection bar | `dropOnRoom()` / `applyTrayDestination()`, `rental-inspection-recording.blade.php` |
| untagged → item | select photo(s) + "Tag to…" dropdown (rooms AND individual items in one list) + "Tag selected" | many (every item across every room) | the SAME tray selection bar as above — one combined chooser, not two | `applyTrayDestination()` → `tagSelectedToItem()`. **Was genuinely missing** until this same-day pass — an agent had to route through a room first; the combined dropdown closes that gap in one action, not two |
| room → item | click the room photo → the opened photo viewer's "Move to…" chooser (single photo), OR select several + the room heading's own "Tag to…" dropdown (bulk) | many (every item in this one room) | opened photo viewer (single) / room heading bulk bar (multi) | `openInspectionPhoto()` sets `viewer.room`; `moveViewerPhotoTo()` (single) / `tagSelectedRoomPhotosToItem()` (bulk), `show.blade.php` + `rental-inspection-recording.blade.php` |
| room → untagged | small ↑ button, top-right of the tile | one (the tray) | on the tile itself | `untagPhoto()`, room-photo tile |
| item → room | small ↑ button, top-right of the tile ("Back to room") — hidden for the roomless "General" group, since there is no room to step back to | one (this item's own room) | on the tile itself | `tagPhoto(photo.id, { property_room_id })`, item-photo tile |
| item → untagged | small ⇗ button, bottom-right of the tile ("Back to untagged") — a visually distinct double-arrow icon from item→room's single arrow, since both are one-button moves on the same tile and must read as two different destinations at a glance | one (the tray) | on the tile itself | `untagPhoto()`, item-photo tile |

**The rule behind the control choice, stated once so it never needs re-deriving:** a move with exactly
ONE possible destination gets a small button, directly on the tile. A move with MANY possible
destinations (which room? which of a room's several items?) needs a chooser, not a button — squeezing a
dropdown onto a thumbnail was tried first and was a real, shipped defect (wrong id type, clipped out of
the clickable area by the height bug in §22.3 below) — so the many-destination choosers live in the
opened photo viewer (room→item, single-photo case) or in a selection bar with room enough for a real
`<select>` (untagged→room, untagged→item, and the bulk case of room→item).

**Clicking a photo opens the viewer. Every other control on a tile is its own small hit target,
`@click.stop`, so no tile control ever also opens the photo it sits on** — confirmed in code at every
tile (select toggle, untag button, back-to-room button all carry `@click.stop`).

### 22.3 The item-photo-strip height bug — the real cause, so it is never rebuilt wrong

Johan's ask (§20.14.4) was simple to state and took **four attempts** to actually ship, because the
first three each fixed a real but incomplete piece of the real cause. Recorded here in full because none
of the three false starts is visible from reading the final code — only from the commit history, which a
lane reset does not have.

**The real cause**: uploaded inspection photos are resized to **2560px on the long edge**
(`PropertyImageStorer`, §3.6 — the same pipeline the marketing gallery uses). Nothing in the button-
grid/photo-strip row constrained that. A flex row's default `min-height` is `auto`, which means a flex
child's own CONTENT-based height (a real photo's natural, unconstrained rendered height — potentially
hundreds of pixels even inside a strip meant to be ~124px tall) can override the row's intended
`stretch` sizing and become the row's real height floor instead. The button grid was never the tall one;
the photo, at its full natural resolution, was.

**Why local verification passed at every attempt except the last**: the R2 build's own local checks used
a **1×1 pixel test fixture** for the "photo." A 1×1 image has no meaningful natural size to expose a
`min-height:auto` gotcha — it measured correctly by coincidence, not because the layout was sound.
**Any future test of this specific layout must use a real, large photo (near the actual 2560px ceiling),
never a 1×1 or other trivially-small fixture** — that substitution is exactly what let this ship broken
three times before it was caught on the real deployed page with a real photo.

**The four attempts, in order** (full detail in each commit's own message — `fa8a52490`, `3426afd82`,
`543cc3a2d`, `f409f8061`):
1. First pass (part of §20.14.4 as originally built) sized the strip via flex `stretch` alone — correct
   in principle, incomplete because nothing set `min-height:0` anywhere in the chain, so the
   `min-height:auto` gotcha above still applied.
2. `fa8a52490` added `min-height:0` at each level plus explicit `max-height`/`object-fit` on the `img`
   itself. Better, but a photo's native resolution still found a path through the remaining
   percentage/stretch cascade on the real deployed page.
3. `3426afd82` abandoned percentage/flex sizing for the strip entirely in favour of literal pixel values
   (`80px`) on every dimension. This genuinely fixed the immediate symptom, but hardcoded a number that
   was only correct for the one agency's condition-list length it was measured against — wrong the
   moment an agency configures 9 states instead of 6 (Johan: "surely theres a specific size the buttons
   take up and that same size can be applied to photos size or height").
4. `543cc3a2d` (the shape that shipped, reinforced by `f409f8061`'s belt-and-braces `align-self`/longhand
   `inset` pass) is the actual final mechanism: the row is `flex; align-items:stretch`, the button grid is
   `flex:none` (so the buttons alone set the row's real height, whatever an agency's condition-list length
   needs), and the photo strip is `flex:1; min-width:0; min-height:0; position:relative` holding NOTHING
   that can itself contribute height — the actual horizontally-scrolling photos live in a `position:
   absolute; inset:0` scroller INSIDE that strip. Because the scroller is taken out of normal flow, a
   photo's native resolution — 2560px or otherwise — has no path back into the row's height at all,
   regardless of how large the source file is. This holds for any agency's condition-list length and for
   any photo resolution, which is the actual requirement — not a number tuned to today's fixture data.

The camera/add-photo control sits in its own `flex:none` slot, a plain sibling of the scroller, not
inside it — a real regression this same day (Johan: "Ive now lost the tagging per item. the little
camera per item is gone?") when an earlier draft put it inside the scrollable strip, where it scrolled
off to the right and became unreachable the moment an item had any photos at all. Outside the scroller,
it receives the same row-driven stretch height directly and stays visible at a fixed position whether the
item has zero photos or twelve — matching the task's own requirement in those exact terms.

### 22.3a A FIFTH attempt, same bug class, one level deeper — fixed 2026-09-22, live measurements this time

The four attempts above fixed the ROW/STRIP/SCROLLER's own sizing. They never touched the TILE and IMG
one level deeper — which still used `display:inline-block; height:100%` (tile) and `height:100%;
width:auto` (img), no explicit width anywhere. Johan caught this live with `getBoundingClientRect()` on
the deployed page (property 5792, Kitchen): the item photo `<img>` measured 847x635/860x645 — its own
natural resolution — and the strip's own (correctly ~1fr) width then clipped that oversized image down to
a ~22px visible sliver. **Not an empty strip — an oversized photo behind a small overflow:hidden window
onto it.** One concrete consequence: at 22px visible width, the item→untagged button had no clickable
room at all — one of the six photo moves was dead on the deployed page purely from this sizing bug, not a
logic bug.

**First attempted fix, NOT the one that landed — recorded so the false start isn't repeated**: switching
the scroller to `display:flex; align-items:stretch;` and each tile to `flex:none; align-self:stretch;
aspect-ratio:1/1;`, reasoning that flex-stretch is a more reliable mechanism than a percentage-height
chain. This never reached `origin/QA1` — a full second live DOM trace (`getBoundingClientRect()` on the
WRAPPER, STRIP CONTAINER, and SCROLLER, all three measuring correctly at their existing, already-shipped
sizes) showed the percentage-height chain was NEVER actually the problem at the outer levels — the
scroller's own `white-space:nowrap; font-size:0;` mechanism (§22.3) already works and did not need
replacing. The bug was narrower and lower than either diagnosis first assumed: the TILE had NO explicit
size AT ALL (not even the `height:100%` the very first pass assumed was already there) — as a plain block
it took the scroller's full ~860px width, height stayed auto, and the img's `height:100%` then had no
definite parent to resolve against and fell back to its natural 860x645.

**The fix that actually landed**: the scroller stays exactly as `white-space:nowrap; font-size:0;`
(unchanged, confirmed already correct). Each tile becomes `display:inline-block; vertical-align:top;
width:165px; height:100%; overflow:hidden;` — `height:100%` now resolves correctly because the tile's
immediate containing block (the scroller) has a real, already-working explicit height; only WIDTH needed
to stop being implicit. **165px is a literal, fixed pixel value, chosen to read roughly 4:3 against a
124px row (the common condition-list-length case)** — never a percentage, never derived from the image,
and never a height in px on the tile itself, so an agency with a taller or shorter row (more or fewer
condition states) still gets a 165px-wide tile at whatever THAT row's own height is; the scroller alone
sets the row height, exactly as before. The img is `display:block; width:100%; height:100%;
object-fit:cover`. No Tailwind class in either version of this fix — every property is an inline style, so
neither carries build-step risk. Predicted computed size at desktop width, confirmed against Johan's own
live measurement of the working levels above: scroller 860x124 (unchanged), tile 165x124, img 165x124.

Not verified in a browser (Standard −1s) — verified by confirming the exact same pre-existing test
failures (room-checklist-seeding content, unrelated to this change) fail identically against unmodified
`origin/QA1` (`git stash`, re-run, byte-identical failures) and that `php artisan view:cache` compiles the
changed templates clean. Branch rebased directly onto the `origin/QA1` tip immediately before push, and
`git merge-base --is-ancestor origin/QA1 HEAD` checked true, so neither `f510880a8` (cc2's compare-frame
fix) nor `666be218a` (cc4's inventory-uploader adoption of this exact photo machinery) is at risk of being
reverted by this landing.

**Four more bugs found live on the same screen, fixed alongside:**

1. **The viewer's "Move to…" chooser only listed items that already had a recorded observation** —
   built from `rental_inspection_observation_id`, so an item nobody had rated yet had no id to offer. Johan:
   "the photo is usually what prompts the rating" — backwards, an agent photographing a cracked floor
   couldn't attach that photo to Floors until they'd first rated it. Fixed with a new, viewer-only
   `itemMoveChoicesFor()` (keyed by item id, not observation id, listing every item in the room regardless
   of rating state) — `itemChoicesFor()`/`allItemChoices()` themselves are UNCHANGED and still
   rated-items-only, since they back the tray's bulk "Tag to…" action, which tags several photos in one
   request and has no single moment to create a missing observation against. `moveViewerPhotoTo()` now
   creates the item's observation on the fly when none exists — the exact same POST
   `_commitObservation()` makes for a tapped chip, no second code path, seeded with the agency's own
   baseline condition key (`RentalInspectionSetting::baselineConditionKeyFor()`, the same starting-value
   mechanism "All Good" bulk-fill already uses) rather than inventing a null/placeholder condition. The
   agent's own next real chip tap on that item is a new, current observation (§3.1, append-only) — the
   starting value is never locked in as the actual finding.
2. **The "Move to…" control itself rendered at the viewport's bottom-left corner, on top of the app
   sidebar** — it was `absolute bottom-4 left-4` inside the viewer's own `fixed inset-0` (full-viewport)
   wrapper, so it positioned against the WHOLE VIEWPORT, not against anything resembling the modal's own
   photo. Fixed by folding it into the SAME wrapper as the Download button, reusing Download's own already-
   correct `bottom-4 right-4` anchor rather than introducing a second, unproven one.
3. **The untagged tray's Archive × rendered at the identical coordinate for every photo, several
   stacked at 0x0** — the × is correctly `position:absolute`, correctly anchored to ITS OWN tile
   (`position:relative` was already there), but the tile div itself carried no explicit size at all; only
   the `<img>` inside it did (`width:3rem; height:3rem`). Relying on an inline img to establish its block
   parent's box is the exact same "elastic container" pattern as the item strip bug above, one screen
   section over. Fixed by giving the tile the same explicit `width:3rem; height:3rem` directly, so every
   tile is an independent 48x48 box and the × lands on its own photo, not the right edge of the tray.
   `archivePhoto()` confirmed a real soft-delete (`deleted_at`, never hard) while investigating this.
4. **A room heading's photo count and the gallery underneath it disagreed** — "KITCHEN — 2/7 · 7 PHOTOS"
   over a gallery whose own "Show all N" said 4. Both numbers are correct: the heading's count
   (`roomProgress().photos`) is deliberately the room's own shots PLUS every item's rolled-up photos
   (§20.13.5), the gallery beneath it shows only the room's own general shots. Nothing on screen explained
   the difference, so it read as the screen being wrong. Chose to LABEL rather than reconcile the two
   counts: forcing them to agree would mean either hiding the room's real total photo count or rendering
   item photos a second time in the room gallery (duplicating them on screen) — both worse than one word.
   The heading now reads "... · N photos total".

### 22.3b The SIXTH attempt, and the actual root cause — Alpine's `:style` clobbers a static `style` — fixed 2026-09-22

§22.3a's fix ("the fix that actually landed") was the right shape and still is — but it never actually
reached the deployed page. Johan landed it (`8fccccb5a`), a new CSS bundle hash confirmed the qa-deploy
build trigger fired, and the photos were STILL broken on live measurement: item tile and img both still
860x645, untagged tray photos 1054x791, tray container 1606px tall. The tile element's `style` attribute
read `style=""` — present, but EMPTY. Not missing, not wrong — wiped.

**The cause**: Alpine's `x-bind:style` (`:style="..."`) does not merge with a co-located static
`style="..."` attribute on the same element. It sets `style.cssText` wholesale from the bound expression
on every reactive render. When that expression evaluates to `''` — the common, nothing-selected case for
every tile on this screen — it wipes every static declaration too. The img right next to the broken tile
had no `:style` binding at all, which is exactly why it rendered correctly while the tile, one attribute
different, did not — same commit, same fix, one element affected and one not, purely by the presence of
a `:style` attribute on the same tag as a static one.

**The fix, per Johan's explicit instruction ("fix the bug class, not the instance")**: swept both
`rental-inspection-recording.blade.php` and `show.blade.php` for every element carrying BOTH a static
`style="..."` and a bound `:style="..."`. **17 found, all fixed** (not 16 — the tray-tile instance below
was found mid-sweep, after the initial count had already been reported):

- **`rental-inspection-recording.blade.php` (5)**: the marquee-select rectangle, the room photo gallery
  tile, the item photo tile, the camera/add-photo slot, and — critically — **the untagged tray tile's
  own §22.3a fix #3 above** (`width:3rem; height:3rem` as a static declaration sitting next to its own
  `:style="isSelected(...) ? 'outline:...' : ''"`). That §22.3a fix never actually took effect on the
  deployed page for this exact reason — it is the direct explanation for the 1054x791/1606px symptoms
  Johan measured this round, three sections after it was first "fixed." All five moved their static
  declarations into new CSS classes (`.rir-marquee-rect`, `.rir-room-photo-tile`, `.rir-item-photo-tile`,
  `.rir-tray-tile`; the camera slot's class was later removed, see the add-tile fix below), leaving
  `:style` with only the one thing it's actually meant to control.
- **`show.blade.php` (12)**: the Overview tab button (static declaration was fully redundant with the
  `:style` ternary — deleted outright, no class needed); the AI feature drag pill, the AI drop target,
  the space tab button, and the feature-category tab button (all four one-off, `:style` always non-empty
  on these — folded the static value straight into the `:style` expression rather than minting a class
  for a single use); six identical visibility-toggle knobs for the advertising-flag switches
  (masterHideAll, hideStreetNumber, hideStreetName, hideComplexName, hideUnitNumber, p24HideAddress) —
  genuinely repeated markup, so one shared `.toggle-knob` class; and the gallery-upload dropzone label,
  which also carries `onmouseover`/`onmouseout` setting `this.style.borderColor` directly — moved to
  `.gallery-upload-dropzone` so the raw JS hover handlers now override a stable class-based default
  instead of a static attribute Alpine could wipe out from under them.

Real CSS shipped as inline `<style>` blocks directly in each Blade file (the recording partial's own
block; `show.blade.php`'s pre-existing top-of-file block, same one `.corex-props-v2 .prop-tab-panel`
etc. already live in) — no Vite build-step dependency, matching the `.compare-side` precedent already in
`show.blade.php` (§20.15) and consistent with §22.3a's own "no Tailwind class, no build-step risk" choice,
just applied one layer more strictly: the static declarations don't just avoid Tailwind, they now also
avoid ever sharing a tag with a `:style` binding that could erase them.

Verified with a tag-boundary scanner (treats `"` as the sole attribute-quote character — a naive scan
that also honours `'` false-positives on apostrophes inside Blade `{{-- --}}` comments) confirming zero
static-`style`-plus-`:style` pairs remain in either file after the fix. Not verified in a browser
(Standard −1s) — `php -l` clean on both files, `php artisan view:cache` compiles both clean. Johan
measured the deployed result directly and confirmed all four target numbers exactly: item tiles 165x124
at the predicted x-stride, untagged tray tiles 48x48 with independent ×s, tray container 1606px → 64px,
zero oversized images left on screen.

**Lesson for this codebase generally (Johan's words)**: a static `style="..."` and a bound `:style="..."`
must never share the same tag. If a property needs to be static, it goes in a class. If it needs to be
reactive, either it's the only thing in `:style`, or every static property that tag needs is folded INTO
the `:style` expression so the bound value is always the complete, correct style string on every render —
never a partial one relying on an unrelated static attribute Alpine doesn't know exists.

**Sixth, immediate follow-up — the add-tile, same screen, same row, 2026-09-22**: once the tile/tray fix
landed, the item row's rightmost element (the camera/add-photo slot) turned out to be its own separate,
visible defect: it was a `flex:none` sibling of the scroller-wrapper inside the row's outer flex
container, and since the wrapper was `flex:1` (always claiming the full available row width regardless of
how many photos it actually held), the add-slot was pinned to the far right edge of that full width —
`~x=1410-1445` on a row with only three photos ending around `x=1082`, a large empty gap, reading as
unfinished. On a row with zero photos it was a lone white sliver floating at the right edge.

**Why it couldn't just become a shrink-to-fit flex sibling**: the scroller-wrapper's only child is the
`position:absolute; inset:0` scroller div (§22.3a's own mechanism, still required — it's what makes
`height:100%` resolve reliably on the tile). A `position:absolute` child contributes NOTHING to its
parent's intrinsic/max-content size in CSS — so any flex-basis:auto/max-content sizing on the wrapper
collapses to a phantom 0px, not "however wide the photos actually are." Content-based flex sizing was a
dead end without either abandoning the (proven, three-rounds-costly) absolute-positioning tile mechanism
or computing the width in JS.

**The fix**: the add-tile is no longer a flex sibling of the wrapper at all. It moved INSIDE the wrapper,
as a sibling of the `overflow-x:auto` scroller div (still never a descendant of the scroller itself — the
exact placement Johan's instruction required, "it disappeared once before because it got put inside the
scroller, do not repeat that"). It's `position:absolute; top:0; bottom:0;` (so it takes its height from
the same already-proven wrapper-stretch mechanism as the scroller, and — being `position:absolute` —
contributes nothing back to the row's own height, keeping the chip grid the row height's sole source, as
required). Its `left` is computed in `:style` from the live photo count: `left:min(<count*171>px,
calc(100% - 124px))` — `171px` is the tile's own stride (165px width + the 0.375rem/6px gap the scroller
already uses between tiles), so the add-tile sits in exactly the position the NEXT tile would occupy; the
`calc(100% - 124px)` clamp is against the wrapper's own actual rendered width via CSS, not a hardcoded
pixel guess, so it degrades gracefully (flush at the strip's right edge, same as the old pinned position)
if a row ever has enough photos to fill the whole visible strip, without ever running off-screen or
becoming unreachable. At zero photos, `count*171 = 0`, so it renders at the wrapper's own left edge —
exactly where the first photo tile would start.

**Size chosen: square 124x124** (row-height square), not the 165x124 rectangle the photo tiles use — the
square is deliberately NOT the same shape as a photo tile, so it reads at a glance as a distinct "add"
affordance rather than as an empty/broken photo slot. The old `.rir-camera-slot` class (flex:none inline
layout, 2.5rem/40px wide) was removed entirely — nothing else referenced it — and replaced by
`.rir-add-tile` (`position:absolute; top:0; bottom:0; width:124px;` plus the existing flex-centering for
the camera icon). The `:style` binding carries both the computed `left` and the existing
has-photos/no-photos background/color ternary — one `:style`, no co-located static `style`, so this is
not an eighteenth clobber pair.

### 22.3c Out-inspection photos couldn't be tagged to most items — the destination lists required an observation to already exist — fixed 2026-09-22

Johan's report: "out inspections photos cannot be tagged to ceiling like on in inspections." Reproduced
and measured on property 5792 (both sections expanded): the IN inspection (17/23 recorded) offered 23
destinations in its bulk "Tag to…" dropdowns; the OUT inspection (1/23 recorded) offered 7 — the 4 rooms
plus exactly the 3 items that already happened to have an observation (2 from cc6's seeding, 1 created
thirty seconds earlier by testing the viewer's "Move to…" fix). On a genuinely fresh out-inspection —
the normal real-world case, an agent walking in on move-out day with a blank form — almost no items were
reachable at all, and it got worse the emptier the inspection was.

**Root cause**: `itemChoicesFor(section, room)` — the function building the room-bulk "Tag selected → item"
`<select>` and (via `allItemChoices()`) the untagged tray's "Tag to…" `<select>` — filtered its list to
`.filter(i => i.observationId)`. An item only appeared as a destination if it already had a recorded
observation, because both selects' options carried an OBSERVATION id (`tagSelectedToItem()` posts
`rental_inspection_observation_id` directly, no create-on-demand) rather than an item id. This is the
exact same defect §22.3a's FACT 4 already fixed once, in a different control — the single-photo viewer's
"Move to…" chooser, which was rebuilt to offer every item and create its observation on the fly
(`moveViewerPhotoTo()`). `itemChoicesFor()`/`allItemChoices()` were deliberately left filtered at the
time, reasoned (wrongly, per Johan now) to be a genuinely different case because a bulk action "has no
per-photo moment to create a missing observation against" — true for a single photo, false for the batch
as a whole, which only ever needs ONE observation created before tagging every selected photo to it.

**How many controls shared the bad list — two**: the room-photo bulk "Tag selected" dropdown
(`roomPhotoTagItemChoice`, `tagSelectedRoomPhotosToItem()`) and the untagged-tray bulk "Tag to…" dropdown
(`trayTagRoomChoice`, `applyTrayDestination()`'s `item:` branch) — both fed by `itemChoicesFor()` via
`allItemChoices()` for the tray. The per-tile arrows (item→room, room→untagged — both single-destination,
no chooser, tag directly to a room id, never an item/observation) and the drag-and-drop room targets
(`dragOverRoom`, built from `roomGroups()` directly — rooms are structural, never observation-gated) were
never affected; neither was the viewer's "Move to…" chooser, already fixed in §22.3a.

**Is the destination list now computed in one place — yes.** `itemChoicesFor()` is now the single
function every item-destination control is built from (`allItemChoices()` still wraps it per-room for the
tray; `itemMoveChoicesFor()` — the viewer's own function — now simply delegates to it instead of carrying
a second, parallel implementation). Fixing the filter there once fixes both bulk controls; a third
item-destination control added later inherits the fix automatically by calling the same function rather
than re-deriving its own list.

**The fix**: removed the `.filter(i => i.observationId)` from `itemChoicesFor()` — it now returns every
item of the room, with `observationId` present-but-nullable so callers know whether one exists yet. Both
`<select>` options switched from binding `i.observationId` to binding `i.id` (the tray's `:key` moved off
`i.observationId` too, since it's null for most items now and would collide across several). Extracted
the create-on-demand logic §22.3a's `moveViewerPhotoTo()` already had into a new shared
`ensureObservationFor(section, itemId)` — same baseline-condition POST, same
`RentalInspectionSetting::baselineConditionKeyFor()` starting value, same never-a-null-placeholder-
condition rule — and both `tagSelectedRoomPhotosToItem()` and `applyTrayDestination()`'s item branch now
call it before tagging (one observation created per bulk action, then every selected photo tagged to
it — not one create-call per photo). `moveViewerPhotoTo()` itself now also calls the shared helper instead
of carrying its own copy of the same three lines — one code path, three callers.

**Confirmed on a genuinely blank inspection (0/23 recorded)**: `allItemChoices()`'s room list comes from
`roomGroups()`, which is built from `activeItems()` (the property's own rooms/items, structural — has no
dependency on any observation existing) — and `itemChoicesFor()` no longer filters by observation either,
so on a zero-observation inspection both bulk dropdowns list every room and every item from the first
photo taken, not just the ones an agent happens to have already rated.

Not verified in a browser (Standard −1s) — `php -l` clean on both files, `php artisan view:cache` compiles
clean, and the static-style/`:style` clobber rescan (§22.3b) still returns zero, confirming this change
didn't reintroduce that class of bug while editing the same templates.

### 22.4 Standing rules this section is built to, restated plainly

- **Agency-configurable, sensible default.** The condition-button grid holds any length list an agency
  configures (6 states, 9 states, whatever) with no hardcoded split or count anywhere in the sizing
  mechanism (§22.3) — this is not incidental, it is the actual requirement the four-attempt bug hunt was
  chasing.
- **Screen space goes to function.** Room photos render nothing when a room has none (§20.13.5); the
  "Show all N" toggle only appears once there is something to show more of (§20.14.3); no separate
  "N untagged" badge exists beyond the tray's own count (§20.13.6); no fact is printed twice.
- **Soft delete only.** Photo archive is a real `deleted_at`, never a hard delete (§22.1).
- **OWN/BRANCH/AGENCY at the query layer.** Every photo route is bound through the agency-scoped
  `{rentalInspection}` and cross-checked against `rental_inspection_id` inside the controller — a photo
  from a different inspection or a different agency's 404s, never leaks (§20.13.8, unchanged).

---

## 23. Compare view — the in/out (or ad-hoc) comparison panel — SUPERSEDED, NOW BUILT (see §20.15)

**Status update, 2026-09-22, same day: this section was the pre-build design record — it is now BUILT
and deployed.** Johan asked directly whether this section was still accurate ("is that actually built and
written to, or is §23 still accurate that this is specced-not-built?") while re-testing the compare view;
this stale header would have answered that question wrongly, so it is corrected here rather than left to
mislead the next reader. **§20.15 is the current, authoritative record of what actually shipped** —
`rental_inspection_photo_matches` is a real table, written to by `RentalInspectionPhotoMatch::matchPhotos()`
via `POST /corex/properties/{property}/rental-inspection-photo-matches`, exactly as designed below. The
rest of this section is kept as the original design reasoning (still accurate — nothing here was built
differently from what was decided), not as a "not yet built" disclaimer.

### 23.1 The shape

When a property has a second inspection (an out-inspection, or an ad-hoc check, once an in-inspection
already exists), the recording surface gains a two-panel comparison view: **In Inspection on the left,
the NEXT inspection on the right** — labelled by its real type (Out Inspection, or the ad-hoc
inspection's own label), never a generic "before/after." Panels align by room and by item automatically
— the same `rental_inspection_item_id` on both sides, since items are property-scoped and reused across
every inspection on that property (§3.1), so "Ceiling — Bedroom 1" on the left is guaranteed to be the
identical row as "Ceiling — Bedroom 1" on the right, never a name-matching heuristic.

### 23.2 Photos flip INDEPENDENTLY per side — explicitly not synchronised

**Johan overruled synchronised flipping directly, verbatim**: *"photo 1 on in inspection shows damages.
photos 4 on out shows same. so the agent needs to match it that way."* The two panels' photo strips each
have their own, independent current-index — paging through the left (in) panel's photos never moves the
right (out) panel's index, and vice versa. This is deliberate, not an oversight to fix later: the photo
that best shows a given piece of damage is very rarely at the same array position on both sides (a wall
might be photo 1 at move-in and photo 4 at move-out, depending on what else was photographed that day),
so forcing the two indices to move together would make it actively HARDER for an agent to line up the
comparison, not easier.

### 23.3 "Match photos" — a persisted link, not a per-view convenience

Because the two strips flip independently, the agent needs an explicit way to say "this specific in-photo
and this specific out-photo show the same thing" — the **"Match photos" control**, which links whichever
photo is currently showing on the left to whichever photo is currently showing on the right.

- **The link is PERSISTED** (a real row, not a client-side/session-only pairing) — it must survive and
  carry into every later surface that shows these photos: the compare view itself, the photo viewer/modal
  described in §22, and any report or document generated afterwards (deposit-dispute paperwork).
- **Unmatch breaks the link** — the reverse of Match, same discipline as untag (§22.1): no separate
  "remove match" mechanism, the same control reversed.
- **A photo may hold more than one match** — e.g. one wide in-photo of a wall might reasonably match
  several close-up out-photos of different damage on that same wall. The link is many-to-many, not a
  single paired-photo-id column on either side.
- **Who matched and when is recorded on the link itself** — this is deposit-dispute evidence: a match an
  agent made needs the same "who said so, when" provenance every other observation on this feature
  already carries (§3.1's own `observed_by_user_id`/`created_at` discipline applies here by the same
  reasoning, not by coincidence).

**Data model implication, not yet built**: a new pivot-shaped table (working name
`rental_inspection_photo_matches` — final name at build time) — `in_photo_id`, `out_photo_id` (or a more
general `photo_id_a`/`photo_id_b` shape if ad-hoc-to-ad-hoc matching is ever needed), `matched_by_user_id`,
`matched_at`, soft-deletable to match this feature's existing archive-not-hard-delete discipline for
photos (§22.1). Exact column shape is a build-time decision, not fixed by this record — the REQUIREMENTS
above (persisted, many-to-many, who/when, survives into every later surface) are what's fixed.

### 23.4 Future dependency to protect — the mobile ghost-image feature

**Not built now, but the API/data shape decided above must not foreclose it**: the mobile app is
expected to use the room, the item, and the match link together to **ghost the in-photo on screen while
an agent takes the matching out-photo** — i.e., overlay a faded version of the original photo as a
framing guide so the agent reproduces the same angle at move-out. This is why room/item/match-link must
stay reachable through the same API-shaped endpoints every other inspection action already uses
(§14.2's established mobile-API pattern) rather than being built as a web-only convenience — a future
mobile client needs to resolve "what was the matched in-photo for this item, so I can show it as a
ghost overlay before this out-photo is taken," and that resolution path does not exist if the match link
is, for example, computed only in a Blade view with no underlying queryable record.

### 23.5 Out of scope for this section (named, not decided)

- The exact UI mechanism for triggering "Match photos" (a button between the two panels, drag-and-drop
  between strips, or something else) is a build-time UI decision, not fixed here.
- Whether a match, once made, should visually annotate BOTH photos everywhere they appear (e.g. a small
  "matched" badge on the thumbnail) — a real UX question, not decided by this record. **If built, the
  badge must not violate §22.4's "no badge lit on every row" screen-space discipline** — a badge that
  appears on every photo regardless of match state is exactly what that rule exists to prevent;
  the badge, if built, must be conditional on an actual match existing.
- Report/document generation itself consuming matched photo pairs — named as a future consumer (§23.3),
  not designed here.

---

## 24. AT-433 Part B — drag-to-pair, auto-pair proposal, viewer paired-first ordering

**Status, 2026-09-26 (updated again): BACKEND and BLADE/JS are both built.** Backend — migration, setting,
auto-pair service, controller endpoint, route, Setup Wizard entry (§24.9). Blade/JS — real pair-based
strip ordering (replacing Part A's positional stand-in), the drag gesture, the transient predecessor
drop-target, the automatic first-view trigger plus the explicit "Auto-pair" button, and the staged-photo
refusal (§24.10). Rebased onto QA1 `e22983c2b` (Part A landed and verified — a real upload survived a
reload — plus the add-tile/strip-overlap fix) before any markup was touched, per Johan's explicit release.
§24.1-24.8 below are the original investigation, kept as written; §24.9 records the backend rulings/build;
§24.10 records the Blade/JS rulings/build.

Johan's feature, verbatim in substance: two blocks side by side; drag a photo to pair it with its
counterpart, or tag them to link them; the photo viewer shows the tagged pairs first, then the unmatched
photos.

### 24.1 The headline finding — most of this already exists

Two prior commits (`93d16d843`, 2026-09-22; `ea8c605c2`, 2026-09-23 — §20.15/§20.16/§20.17 above) already
built a real pairing feature: a persisted, many-to-many, soft-deleted, audit-carrying link between photos
on different inspections, a full-screen compare viewer, and a click-based "Match" action. Reading §24.1-24.4
against §20.15-20.17 above before writing a single new line is the load-bearing step of this investigation —
the risk on this round is not "how do we build pairing," it is "don't rebuild what §20.16/§20.17 already
shipped."

What already exists and should be **reused, not rebuilt**:

1. **The pair IS an explicit link, already spans a lineage, already survives add/remove/reorder** —
   `RentalInspectionPhotoMatchGroup`/`RentalInspectionPhotoMatchGroupMember` (§24.4 below). Johan's design
   decision #1 in this round's brief is already the shipped model, not a new one to design.
2. **"Tag them to link them" already exists** — `RentalInspectionPhotoMatchGroup::linkPhotos()`
   (`app/Models/RentalInspectionPhotoMatchGroup.php:126-145`), reached via clicking a thumbnail in either
   side's carousel then the viewer's "Match" button (`compareViewerMatch()`,
   `resources/views/corex/properties/show.blade.php:6033-6038`), POSTing to
   `POST /corex/properties/{property}/rental-inspection-photo-matches`
   (`RentalInspectionRecordingController::storePhotoMatch()`, same file `:897-914`). What is genuinely new
   this round is the **drag** gesture (§24.6) as a second, faster way to reach the exact same
   `linkPhotos()` call — not a new linking mechanism.
3. **The "third inspection" question is already answered** — a group is a SET, not a pairwise edge; §20.16.1
   states outright that the group model "already work[s] for any number of members from any number of
   inspections" (§20.17.9). §24.4 confirms this by reading the actual code, not just the spec's own claim.
4. **The compare viewer (the "two blocks side by side" AND the "photo viewer") both already exist** —
   §24.5/§24.6 identify exactly which of the two Johan means by each phrase, since they are two different
   screens in this codebase, not one.

What is genuinely new and not built anywhere: the **drag** gesture itself, **auto-pair**, and **paired-first
ordering** in the viewer's carousel. §24.5-24.7 propose all three.

### 24.2 Photo storage today — file:line

- `RentalInspectionPhoto` (`app/Models/RentalInspectionPhoto.php`) — one row per photo. Belongs always to
  an inspection (`rental_inspection_id`, `:38`); OPTIONALLY to a room (`property_room_id`, `:40`) and/or a
  specific item's observation within that inspection (`rental_inspection_observation_id`, `:39`) — both
  null means "sits in the inspection's own untagged tray" (`isUntagged()`, `:95-98`). Tagging is a
  **supersede**, not an append (`tagTo()`, `:107-115`; `untag()`, `:118-121` — the exact same call with
  nulls). Soft-deletable (`archive()`, `:124-128`), never hard-deleted.
- Migrations: base table `database/migrations/2026_09_17_100300_create_rental_inspection_photos_table.php`;
  room/tray columns added by
  `database/migrations/2026_09_22_140000_add_room_and_tray_support_to_rental_inspection_photos_table.php`.
- **Rooms and items are property-scoped, not inspection-scoped** — `PropertyRoom` and `RentalInspectionItem`
  belong to the `Property` directly and are reused across every inspection on it
  (`RentalInspection.php:656-659`'s own docblock: "Rooms/items are property-wide... every link in the chain
  automatically shares the same structure"). This is why `property_room_id` is directly comparable across
  two different inspections' photos — the same physical room has the same id everywhere. An **item**,
  however, is reached from a photo only via `rental_inspection_observation_id` →
  `RentalInspectionObservation::item()` (`app/Models/RentalInspectionObservation.php:92-94`,
  `rental_inspection_item_id` at `:53`) — the *observation* is inspection-scoped (each inspection creates
  its own), but the *item* it points at (`rental_inspection_item_id`) is the same property-wide id on both
  sides. Any code keying photos by "same item across two inspections" must resolve through
  `observation->rental_inspection_item_id`, never assume the observation ids themselves line up.
- **The chain** — `RentalInspection.previous_inspection_id` (`RentalInspection.php:68`), one linear chain,
  set once at creation by `startNext()` (`:671-700`), never edited afterward. `chainTailFor()` (`:543-555`)
  finds whichever link has no successor yet; `inferredPredecessorFor()` (`:579-588`) is the type+date
  fallback for a pre-chain pair recorded before this column existed. `compareRightFor()`/`mostRecentFor()`
  (`:603-615`) resolve the original fixed in/out pair the compare viewer's `chainPredecessor`/`chainTail`
  state is built from (`tabPayloadFor()`, `:847-998`, the `photo_matches` key specifically at `:977-996`).

### 24.3 Compare viewer ordering today — file:line

The carousel a paired-vs-unmatched sort would need to change is
`compareViewerCarouselPhotos(side)`/`compareViewerPhotosForSide(side)`
(`resources/views/corex/properties/show.blade.php:5938-5948`). Today it returns
`roomPhotosForInspection()`/`conditionForInspection()`'s own photo array
(`roomPhotosForInspection()` defined at `:6687`), filtered only for a present `storage_path` (`:5944`) —
**no pairing-aware sort exists**. Order is whatever `itemPhotosForInspection()`/the underlying eager-loaded
`photos` relation returns (upload/creation order, unsorted for this purpose). `groupForPhoto()`
(`:5755-5757` area) is already available inside this same component and is the lookup a paired-first sort
would call per photo.

Clicking a thumbnail here (`compareViewerSelectCarouselPhoto()`, `:5960-5973`) already loads the clicked
photo's matched-group counterpart into the opposite pane — this is the mechanic Johan's "the photo viewer
shows the tagged pairs first" extends: today the agent has to already know to click a paired photo to see
the pairing; the ask is to surface it as a visible ordering instead of something the agent discovers by
clicking.

**Proposed change (build-time, not yet built):** `compareViewerCarouselPhotos(side)` sorts its result into
two runs — every photo with an active group membership (`groupForPhoto(photo.id)` truthy) first, ordered
by pair order (§24.4's open question on what "pair order" means), then every unmatched photo, labelled by
side in the UI exactly as Johan specified ("no match" / the side it came from). This is a pure read-side
sort — it changes what order `compareViewerCarouselPhotos()` returns, not the underlying data.

### 24.4 The pair's data model — REUSE `RentalInspectionPhotoMatchGroup`, do not design a new one

Johan's brief asks four things of a pair's data model: explicit link (not position); survives add/remove/
reorder; states what happens when a third inspection joins the chain; states what happens when a paired
photo is removed. All four are already answered by the shipped model:

- **Explicit link, not position** — `rental_inspection_photo_match_group_members` is a real row per photo
  per group (`database/migrations/2026_10_03_100100_..._members_table.php`), not a computed position.
- **A photo belongs to at most ONE active group** — enforced in application code, not a DB constraint
  (`RentalInspectionPhotoMatchGroup::addMember()`, `:84-114`), because a plain unique index can't express
  "unique among non-deleted rows" alongside soft deletes (the member migration's own docblock,
  `2026_10_03_100100...php:8-25`, names this exact MySQL gotcha).
- **Third inspection joining the chain** — **a photo pairs to a lineage (the group), never to "one
  predecessor."** `RentalInspectionPhotoMatchGroup::photos()` (`:48-58`) is a `hasManyThrough` with no
  inspection filter at all — a group can and does hold members from any number of inspections
  simultaneously. `RentalInspection::tabPayloadFor()`'s `photo_matches` key (`:977-996`) already reads
  "every match GROUP touching the chain's CURRENT predecessor/tail pair," with its own comment naming
  exactly the case Johan is asking about: "a group may carry members from more than just these two
  inspections (Johan's own 'third and fourth inspection' case), and every one of those members ships to
  the frontend too." **This is not a proposal — it is what is already running on QA1.**
- **Removing a paired photo** — `RentalInspectionPhotoMatchGroupMember::removeAndMaybeArchiveGroup()`
  (`:59-69`) soft-deletes just that one membership; if the group drops to ≤1 active member, the GROUP is
  archived too (`RentalInspectionPhotoMatchGroup::archive()`, `:148-152` — a soft delete, `deleted_at`,
  never a hard delete). The removed photo's own row is untouched; only its membership row and, possibly,
  the now-empty group are archived.

**Recommendation: build nothing new here.** The drag gesture (§24.6) and auto-pair (§24.5) should both
call the exact same `RentalInspectionPhotoMatchGroup::linkPhotos($clicked, $anchor, $by)` the click-based
UI already calls (`RentalInspectionRecordingController::storePhotoMatch()`,
`app/Http/Controllers/CoreX/RentalInspectionRecordingController.php:897-914`) — one linking primitive, three
ways to trigger it (click, drag, auto-pair), never a second table or a second write path.

**Open question — "sets the order":** the brief says the drag gesture "makes the link and sets the order."
Today's schema has no explicit position column — `toComparePayload()` (`RentalInspectionPhotoMatchGroup.php
:161-174`) returns `$this->members->map(...)` in whatever order the `members()` relation yields, which
without an explicit `orderBy` is insertion order (ascending `id`, i.e. the order photos were added to the
group). **[cc design call, not yet approved]:** proposing this insertion order stand in for "pair order"
rather than adding a new `position`/`sort_order` column — it already reflects "the order the agent paired
them in," which is very likely all "sets the order" means, and a same-group reorder-without-relinking
feature was not asked for. If Johan wants a photo to be movable to a different position WITHIN an existing
group without unlinking and relinking it, that is a real schema gap (no column carries an explicit
position today) and needs a business answer before it's built: **is "the order photos were paired in"
good enough, or does an agent need to manually reorder an already-paired set?**

### 24.5 Auto-pair — proposed rule, not built anywhere today

`grep -i "auto-pair"` across the entire spec returns zero hits before this section — confirmed nothing
resembling this exists, on QA1 or in any prior design record.

**Proposed rule, stated precisely enough to predict its output:**

1. Scope: the chain's current predecessor/tail pair (`$rawPredecessor`/`$rawChainTail`,
   `RentalInspection.php:910-912` — the same pair `photo_matches` is already scoped to).
2. For every **tagged** photo (`isUntagged()` false, `RentalInspectionPhoto.php:95-98`) on either side that
   does **not** already belong to an active group (`RentalInspectionPhotoMatchGroup::forPhoto()` returns
   null, `:66-71`), compute a key:
   - item-level photo (`rental_inspection_observation_id` set): key = `(property_room_id,
     observation->rental_inspection_item_id)`.
   - room-level-only photo (`property_room_id` set, `rental_inspection_observation_id` null): key =
     `(property_room_id, null)`.
   - untagged photos are never candidates — there is no tag signal to key off, and guessing from image
     content is out of scope for this build.
3. For every key present on BOTH sides: if the predecessor side has **exactly one** ungrouped candidate for
   that key AND the tail side has **exactly one** ungrouped candidate for that key, propose the pair
   (call `linkPhotos()`, `matched_by_user_id` recorded as a system/auto actor — see open question below).
   **If either side has zero, or either side has more than one, propose NOTHING for that key** — this is
   Johan's own ruling stated in the brief ("proposes nothing rather than guessing wrong"), and it is the
   only rule that can't silently mismatch: with four in-photos and two out-photos tagged to the same item,
   the key has 4 candidates on one side and 2 on the other — count ≠ 1 on both sides, so auto-pair proposes
   nothing for that item and leaves all six for the agent to pair by hand (drag or click), exactly as
   instructed.
4. Idempotent by construction: re-running the rule after some pairs already exist only ever looks at
   *ungrouped* candidates (step 2's filter), so it can safely run more than once without touching photos an
   agent already paired or already decided not to pair.

**Open questions Johan needs to rule on — not guessed:**

- **When does it run?** Three real options: (a) automatically, once, the first time both sides of a
  predecessor/tail pair have tagged photos (event-driven, silent); (b) automatically every time the
  two-block screen or the compare viewer is opened (idempotent per §24.5.4, so safe to re-run, but the
  agent never sees it as a discrete step); (c) an explicit "Auto-pair" button the agent presses, so pairing
  the forty photos Johan describes is a deliberate, visible action with a result the agent then reviews —
  closer to "runs first" as a step in a flow than something that happens invisibly on page load. This is a
  business/UX call (what the agent sees happen on screen), not an engineering one — Johan's call.
- **Auto-pair on or off by default** (§24.7's setting) — Johan's brief names this as one of the settings to
  build but doesn't state the default.
- **Who is recorded as `matched_by_user_id` on an auto-created pair?** The column is `nullable` (schema:
  `2026_10_03_100100_..._members_table.php:38-39`), so a system-attributed row (null `added_by_user_id`) is
  possible without a schema change — but this is deposit-dispute evidence (§20.16.2's own framing), so
  whether "auto-paired, nobody confirmed" needs to read differently from "agent X paired this" anywhere it
  surfaces (the group's audit trail, any future report) is worth Johan's explicit ruling, not a silent
  default.

### 24.6 Where drag-and-drop attaches — an existing pattern on THIS EXACT SCREEN, and a live conflict

**"Two blocks side by side" is the item-level comparison grid**, not the compare-viewer modal. It lives in
`resources/views/corex/properties/partials/rental-inspection-recording.blade.php:611-629`
(`rir-compare-row`, `grid-template-columns:1fr 1fr` at `:629`) — ONE `x-for` over `group.items` drives both
columns so "item N is always item N on both sides by construction" (that file's own docblock, `:612-627`).
Each cell is rendered by the SAME shared partial,
`resources/views/corex/properties/partials/rental-inspection-item-cell.blade.php`, once with `$readOnly =
true` (the predecessor/left cell) and once `$readOnly = false` (the tail/right cell) — confirmed by reading
that partial's own docblock (`item-cell.blade.php:1-49`) and its `@if($readOnly)` branches
(`:54-136`).

**Room-level photos have NO side-by-side block today.** The room photo strip (§20.14.3's "R1")
(`rental-inspection-recording.blade.php:500-609`) renders ONLY the tail side — its own comment says so
outright: "there is no predecessor-side room gallery in this file" (`:551`). If Johan wants room-level
(not just item-level) photos pairable via drag between two visible blocks, that block does not exist yet
and building it is itself new scope, separate from wiring drag onto the item-level grid that already has
two sides. **[Open question]:** does this round cover item-level pairing only (where two blocks already
exist), or does it also require building a predecessor-side room gallery that doesn't exist today?

**"The photo viewer" is the separate compare-viewer modal** (`show.blade.php`, opened via
`openCompareViewer(photo, insp)` from either cell — item-cell.blade.php `:107` (read-only) and `:124`
(live)). §24.3 is where its paired-first ordering change belongs. These are two different UI surfaces in
this codebase — the brief's two sentences describe two different screens, not one.

**The drag mechanic already exists on this exact screen, for a different purpose — match it, don't invent a
second one.** `rental-inspection-recording.blade.php:349-450` already implements native HTML5
drag-and-drop for tagging: `draggable="true"` + `@dragstart="photoUploader({{ $sectionJs }}).
dragStartSelection($event, photo.id)"` (`:349-350`) on a tray photo tile, and `@dragover.prevent`/
`@drop.prevent="...dropOnRoom($event, group.room.id)"` (`:448-450`) on a room heading as the drop target.
The handlers themselves live in the reusable component `public/js/corex-photo-batch-uploader.js:308-317`:
`dragStartSelection(event, id)` stashes the dragged photo id(s) in `event.dataTransfer`;
`dropOnRoom(event, roomId)` reads them back out and calls the existing tag action. **Proposed reuse:** the
same two-function shape — a new `dragStartPairCandidate(event, photoId)` on the tail (live) cell's photo
tile (`item-cell.blade.php:123-124`, currently NOT draggable — no `draggable` attribute exists on this
element today) and a new `dropOnPairCandidate(event, photoId)` on the predecessor (read-only) cell's
counterpart tile (`item-cell.blade.php:106-107`), calling `linkPhotos()`'s existing endpoint via the anchor/
clicked shape `storePhotoMatch()` already accepts (`photo_id`/`anchor_photo_id`,
`RentalInspectionRecordingController.php:899-902`) — no new endpoint needed, only a new client-side trigger
for the one that exists.

**A real design tension, named rather than resolved:** the read-only cell's own docblock states its rule
in Johan's own words — *"Read-only means disabled controls or a static rendering of the same component — it
does not mean a different component with a different look"* (`item-cell.blade.php:12-14`) — and today that
branch (`:90-109`) has **zero interactive affordances**: no select, no tag button, no drag handle, nothing
but a click that opens the viewer. Making it a **drop target** is a new interactive behaviour on a cell
this file's own rule was written to keep inert. It is arguably not a "control" in the sense the rule means
(nothing on the read-only side becomes editable; it only ever receives a drop that acts on the OTHER side's
photo), but it is a change to that cell's behaviour that its own docblock did not anticipate, and this
round should not decide unilaterally that the rule doesn't apply. **[Open question for Johan]:** is a drop
target on the read-only predecessor cell consistent with "read-only," or does dragging need to work the
other direction only (drag the read-only/predecessor photo onto the live/tail cell) to keep every write
action anchored on the side that already accepts writes?

**Live conflict, confirmed, not assumed:** `git worktree list` shows a second, currently-checked-out
worktree, `/mnt/HC_Volume_103099143/corex-worktrees/insp-photo-strips-2026-09-26` (branch
`insp-photo-strips-2026-09-26`, branched from the same `origin/QA1` tip as this investigation's own
worktree, no commits ahead of it yet — the restyle is uncommitted work-in-progress at the time of this
investigation). Its name and Johan's own framing ("cc1 is restyling the same Blade file right now") both
point at exactly the two files this section proposes to touch
(`rental-inspection-recording.blade.php`/`item-cell.blade.php`, specifically the `.rir-item-photo-tile`/
`.rir-room-photo-tile` tile styling declared at `rental-inspection-recording.blade.php:96-111`). **This
build must not start until that restyle lands on QA1 and this branch rebases onto it** — building drag
handlers against tile markup that is about to be restyled underneath them is the exact race Johan is
already sequencing against.

### 24.7 Agency-configurable settings — proposed, following the existing pattern exactly

`RentalInspectionSetting` (`app/Models/RentalInspectionSetting.php`) is a single row per agency, one real
column plus one static `...For(?int $agencyId)` accessor per setting (e.g.
`requireNotesBlocksProgressionFor()`, `:256-264`; `refusalReasonPresetsFor()`, `:303-...`) — never a
generic key-value blob. Proposed additions, following that exact shape:

- **`auto_pair_photos_enabled`** (boolean column, new `...For()` accessor) — Johan's brief names this
  explicitly as needed; **default not stated, Johan's call** (§24.5's open question).
- Depending on Johan's answer to §24.5's "when does it run" question, possibly no second setting is
  needed — if auto-pair is always an explicit button (option (c)), the on/off toggle IS the only setting;
  if it runs automatically, a second question (can an agent re-trigger it manually even when the automatic
  setting is off) may need its own toggle or may not — deferred until the first question is answered, not
  designed twice.

**Setup Wizard — non-negotiable #10a, not yet done, named so it isn't forgotten:** the existing rental-
inspections wizard step lives in `config/agency-onboarding-copy.php` (`source: 'rental_inspections'` block,
approximately `:396-411`, settings page route `corex.settings.rental-inspections.edit` referenced at
`:487-489`). Any new setting from this section ships in the SAME prompt that builds it, added to that same
step with a real `explain`/`affects` pair — not deferred to a later prompt.

### 24.8 Open questions — the complete list, restated together

1. **§24.4** — is insertion order (the order photos were added to the group) sufficient for "pair order,"
   or does an agent need to reorder an already-paired set without unlinking/relinking?
2. **§24.5** — does auto-pair run automatically (silently on load, or once when both sides first have
   tagged photos) or via an explicit "Auto-pair" button the agent presses?
3. **§24.5** — auto-pair's default (on/off) for a new agency.
4. **§24.5** — how an auto-created pair's provenance (`matched_by_user_id` = null/system) should read
   anywhere it surfaces, versus an agent-made pair.
5. **§24.6** — does this round cover item-level photo pairing only, or does it also require building a
   predecessor-side room-level photo gallery that does not exist today (§20.14.3's R1 strip currently
   renders the tail side only)?
6. **§24.6** — is a drop target on the read-only predecessor cell an acceptable exception to that cell's
   own "no interactive affordances" rule, or should the drag direction be reversed (predecessor photo
   dragged onto the live tail cell) to keep all write actions anchored on the side that already accepts
   them?
7. **§24.6 (sequencing, not a design question)** — this build waits for `insp-photo-strips-2026-09-26` to
   land on QA1 and for this branch to rebase onto that restyle before any markup changes are written.

---

### 24.9 Johan's rulings, 2026-09-26, and the backend built against them

**Ruling on open question 5 (room-level scope) — item-level only, room-level named as a deliberate,
tracked gap, not silently skipped.** Johan: "the room-level side-by-side block does not exist and we are
not inventing it inside this ticket." Recorded here so it is a decision on the record, not an oversight:
**building drag-pairing for room-level (not just item-level) photos would first require building a
predecessor-side room photo gallery in `rental-inspection-recording.blade.php` — today's R1 strip
(§20.14.3, that file's lines ~500-609) renders ONLY the tail side, by design, per that section's own
comment ("there is no predecessor-side room gallery in this file").** Until that gallery exists, room-level
photos have no "two blocks side by side" to drag between at all. Not built here; a real, separate scope
decision if Johan wants it later.

**Ruling on open question 2 (auto-pair timing) — automatic on first view, PLUS the explicit "Auto-pair"
button from the approved mockup, kept.** Johan: "pairing forty photos by hand is exactly the work we are
supposed to be doing for them... An explicit 'Auto-pair' button also exists to re-run it after photos are
added — that is in Johan's approved mockup, so keep it. Any pair, proposed or manual, can be broken by the
agent." Built as ONE endpoint serving both callers (§24.9.1) — the automatic trigger and the button are the
exact same idempotent operation, differing only in when they're called, never in what they do.

**Ruling on open question 3 (default) — ON.** Built as `RentalInspectionSetting::
DEFAULT_AUTO_PAIR_PHOTOS_ENABLED = true`.

**Ruling on open question 6 (drop-target exception) — APPROVED, with a hard limit: transient only, never
persistent.** Johan: "The cell gains a drop target ONLY while a drag is in progress — no persistent
affordance, no hover state, nothing on that cell when the agent is not dragging. Do not reverse the drag
direction: ... current-inspection photo drags onto its predecessor." **Written here so the next person does
not "fix" it into a permanent-looking control:** when the Blade/JS pass is built, the read-only
predecessor cell's drop-target styling/listener must be conditional on a drag actually being in flight
(e.g. an Alpine `dragging` flag toggled by the tail cell's own `dragstart`/`dragend`), and must render
IDENTICALLY to today's inert cell (§24.6's own read of `item-cell.blade.php:90-109`) at every other time —
no border, no highlight, no cursor change, nothing an agent would notice by hovering when not dragging.
Direction is fixed: the LIVE tail-side photo is the one made `draggable`; the READ-ONLY predecessor-side
photo is the one that gains the (transient) drop target. Never the reverse.

**Open question 1 (pair order) and open question 4 (auto-pair provenance) — resolved by how the backend
was actually built, not left open:**

- **Pair order**: built using insertion order (§24.4's proposed default) — no `position` column was added.
  If an agent later needs to reorder an already-paired set without unlinking/relinking, that is a real,
  separate schema change, not something this round silently ruled out.
- **Provenance**: there is no "system" actor in this design at all. Both the explicit "Auto-pair" button
  and the (not-yet-wired) automatic first-view trigger are ordinary authenticated HTTP requests from the
  agent viewing the screen — `RentalInspectionPhotoAutoPairService::runFor()` takes the acting `User` and
  passes it straight into `linkPhotos()`, so an auto-created pair's `added_by_user_id` is genuinely the
  agent who was looking at the screen when it fired, exactly the same provenance an agent-made pair
  carries. Nothing reads differently; open question 4 does not need a UI distinction because there is
  nothing distinct to show.

#### 24.9.1 What was built

- **Migration** — `database/migrations/2026_10_03_200600_add_auto_pair_photos_enabled_to_rental_inspection_settings_table.php`
  — one nullable boolean column, same read-time-default pattern as every sibling column on this table.
- **Setting** — `RentalInspectionSetting::DEFAULT_AUTO_PAIR_PHOTOS_ENABLED` / `autoPairPhotosEnabledFor()`,
  following the existing one-column-plus-static-accessor pattern exactly.
- **Auto-pair service** — `app/Services/RentalInspectionPhotoAutoPairService.php`, `runFor(RentalInspection
  $predecessor, RentalInspection $tail, User $by)`. Implements §24.5's rule exactly: keys tagged,
  never-touched photos by `(property_room_id, observation->rental_inspection_item_id)`, pairs a key only
  when EXACTLY ONE candidate exists on each side, calls the same `RentalInspectionPhotoMatchGroup::
  linkPhotos()` the click-based UI already uses. "Never-touched" is checked via `withTrashed()` on
  `RentalInspectionPhotoMatchGroupMember`, not just "no active group" — this is what makes it safe to
  re-run on every view without silently overriding an agent's own explicit unmatch (verified in §24.9.2).
- **Controller endpoint** — `RentalInspectionRecordingController::autoPairPhotoMatches()`, `POST
  /corex/properties/{property}/rental-inspection-photo-matches/auto-pair`
  (`rental-inspection-photo-matches.auto-pair`), resolves the property's current chain predecessor/tail
  pair the same way `RentalInspection::tabPayloadFor()` already does, then calls the service. Always
  available regardless of the setting — the setting only gates whether a future caller invokes this
  automatically; the explicit button always works.
- **Data-layer flag for the frontend** — `RentalInspection::tabPayloadFor()` now returns
  `auto_pair_photos_enabled`, threaded the same way `condition_states`/`refusal_reason_presets` already
  are. The Blade/JS pass (not yet built) reads this to decide whether to call the endpoint automatically.
- **Correctness fix, in scope because Johan's own verification list named it** —
  `RentalInspectionPhoto::archive()` now also removes the photo's active match-group membership (soft
  delete, mirroring an explicit unmatch), auto-archiving the group if that drops it to ≤1 member. Before
  this fix, archiving a paired photo left a dangling active membership pointing at a trashed photo — the
  surviving photo in a two-member group kept reading as "matched" against nothing. Verified directly
  (§24.9.2).
- **Setup Wizard entry (non-negotiable #10a)** — `config/agency-onboarding-copy.php`'s `leases` step gains
  the `auto_pair_photos_enabled` toggle control (default 1) and its own saver registration
  (`RentalInspectionSettingsController::updateAutoPairPhotosEnabled`), has()-guarded per §6.1 of the
  onboarding spec.
- **Dedicated settings-page saver + route** — `RentalInspectionSettingsController::
  updateAutoPairPhotosEnabled()`, `POST /corex/settings/rental-inspections/auto-pair-photos`
  (`corex.settings.rental-inspections.auto-pair-photos`), same one-concern-per-endpoint discipline as the
  four existing dedicated savers on this controller. **Not built this round:** the actual checkbox on
  `resources/views/corex/settings/rental-inspections.blade.php` — that file is Blade, out of scope for
  this backend-only pass per instruction; the controller/route/saver are ready for it.
- **Scoping** — `BelongsToAgency` on the group tables (unchanged, pre-existing); the new endpoint mirrors
  the exact same property-match `abort_if` discipline `storePhotoMatch()`/`destroyPhotoMatch()` already
  use; permission middleware reuses the existing `rental_inspections.create`/`rental_inspections.
  manage_settings` keys — no new permission was needed.

#### 24.9.2 Verification performed

**Tinker, against the real `corex_qa1` schema, wrapped in a transaction that was rolled back regardless of
outcome (zero permanent footprint — no scratch database created; `DB::beginTransaction()`/`rollBack()`
around real fixture rows under the existing property 1724/lease 5).** 14 checks, all passing: auto-pair
creates exactly one group for an unambiguous 1:1 key and nothing for an ambiguous 1:2 key (Johan's own
worked example, scaled down); unmatch soft-deletes the membership and auto-archives the group at ≤1
member; a photo the agent explicitly unmatched is never silently re-proposed on a second auto-pair run;
manually re-pairing after an unmatch produces a working, fully active group again; archiving a currently
paired photo (the new `archive()` fix) cascades to remove its membership and auto-archive the group,
without touching the surviving photo on the other side.

**PHPUnit, one file (non-negotiable #13 — single most relevant file, not a broad suite):**
`tests/Feature/Onboarding/RentalsStepSaverIndependenceTest.php`, extended with two new cases —
the wizard step's has()-guard never wipes an agency's own explicit `auto_pair_photos_enabled = false` back
to the default, and the new dedicated settings route saves its own field without touching sibling
settings. Both pass, along with all 5 pre-existing cases in that file except one
(`test_saving_the_combined_rentals_step_persists_all_four_fields`) — that failure is on
`RentalApplicationSettingsController`'s field-display savers, code this round never touched, confirmed
unrelated by reading the diff (non-negotiable #13's own instruction: reason from the diff, don't chase it
via more test runs) and further marked out by running roughly 1000x slower than every other case in the
file — a pre-existing baseline issue, found and reported here, not fixed (outside this round's scope).

`php -l` clean on every changed/new PHP file. `php artisan view:clear`/`route:clear`/`config:clear` clean.
No Blade file was changed or rendered.

**Environment note for whoever picks this branch up next:** this worktree required its own independent
`composer install` (per the box's vendor-isolation rule — no vendor/ existed in a fresh worktree) and its
own `.env` pointed at the real `corex_qa1` credentials (copied from a sibling QA1 worktree, matching
Standard −1a/−1g). `php artisan migrate` against `corex_qa1` is guarded and refuses from any worktree but
`/corex-qa1` itself (Standard −1g) — this migration was NOT applied to `corex_qa1` from here; it travels
with this commit and lands on QA1 the sanctioned way. A temporary, empty `hfc_dash_test_926261` database
was created for the PHPUnit run above, following the box's own sanctioned per-lane test-DB naming
convention (Standard −1a) — it was NOT dropped afterward (the shell command was blocked by this session's
own tool-permission gate on both a raw `DROP DATABASE` and a Tinker-issued equivalent); it is empty and
harmless but should be dropped by whoever next has that permission.

---

### 24.10 Blade/JS built, 2026-09-26, on top of Part A + the overlap fix (QA1 `e22983c2b`)

Rebased onto QA1 after Johan confirmed Part A (photo strips) and `0ca4501fa` (kill the add-tile/strip
overlap class, staged-photo pending tile) were both merged, deployed, and verified — a real upload
survived a reload. Two things in that landed code changed what this section builds against, both handled
below: `.rir-strip-row` is a real flex container (no absolutely-positioned drop-target sibling is ever
added here — see each drag/drop binding's own comment); and a staged (picked, not yet uploaded) photo now
renders as its own PENDING tile with no server id.

**Real pairing replaces Part A's positional stand-in.** `stripTilesForInspection()`/`stripTilesFor()`
(`show.blade.php`) previously zipped the predecessor/tail photo arrays by raw index — Part A's own
docblock named this a deliberate stand-in for Part B. `pairedStripRows(item)` is now the ONE function
deciding row order for BOTH cells: every matched group touching this item's own photo pool first (ordered
by group id — the insertion-order choice already recorded in §24.9), each row spending one predecessor and
one tail member so neither is reused by a later row; then whichever side has a photo nobody has claimed,
"NO MATCH" on the other side. `item-cell.blade.php`'s own `:key="tile.index"`/`x-text="tile.index + 1"`
bindings needed NO change — `index` is still a stable per-row position, just computed from real pairs now.

**Drag-to-pair.** The tail (live) tile is the only ever drag source (`:draggable="!!tile.photo"`,
`photoDraggedForPairing()`, which calls `photoUploader(section).dragStartSelection()` — the exact same
function the untagged tray already uses for its own drag-onto-a-room gesture, per Johan's instruction not
to build a second mechanism); the predecessor (read-only) tile is the only ever drop target
(`pairDragOverTile()`/`pairDropOnPredecessor()`). Direction is fixed exactly as ruled — never reversed.

**Transient drop target, no persistent affordance.** `pairDragActive` (set on drag start, cleared on drag
end/drop) gates the predecessor tile's drop-target class (`.rir-strip-pair-eligible`/`.rir-strip-pair-over`,
new CSS alongside `.rir-strip-row`'s own block) — a tile carries neither class at any time no drag is in
flight. Neither `pairDragOverTile()` nor `pairDropOnPredecessor()` uses Alpine's `.prevent` modifier
(which would call `preventDefault()` unconditionally on every dragover/drop, foreign drags included); both
call it manually, only inside their own `pairDragActive` guard — an unrelated drag (the pre-existing,
separate, explicitly out-of-scope desktop-file-onto-the-strip question Johan named this round) sees
identical behaviour to before this feature existed.

**Staged photo — refused, visibly.** The PENDING tile is also draggable (`dragStartSelection(event,
null)` — the agent has no way to know in advance a drop will be refused, so the drag itself is not blocked)
but carries no id; `pairDropOnPredecessor()` detects the id-less payload and sets the same `this.error`
banner every other failure on this screen already uses ("This photo is still uploading — it can be paired
once it has finished."), never a silent no-op.

**Auto-pair, both triggers.** `maybeAutoPairPhotos()` (called from `init()` for a property whose chain
already has both sides on first render, and again from `refreshInspectionData()` after starting an
inspection or advancing the chain) runs the automatic, unambiguous-only pairing once per predecessor/tail
pair — keyed on the pair's own two ids, not just "ran once this page load," so a genuinely new pair still
gets its own run. The "Auto-pair" button (new toolbar row beside "Next inspection:", gated on both
`chainTail` and `chainPredecessor`) calls the same `runAutoPair()` directly, bypassing that guard — its
whole purpose is re-running after new photos are added, exactly as ruled. Both read `auto_pair_photos_enabled`
from `RentalInspection::tabPayloadFor()`'s own flag (already threaded in §24.9); the button itself always
works regardless of the setting.

**Breaking a pair** — unchanged from §20.16/§24.9: `toggleCompareMatch()`'s existing DELETE path, soft
delete only, never touched by this round.

**Not built, named on purpose (per instruction, not an oversight):** file drops from the desktop onto the
strip — pre-existing, separate, Johan's own question to raise elsewhere. Room-level drag-pairing — §24.9's
own recorded gap (no predecessor-side room gallery exists yet). Manual reordering of an already-paired
set — §24.9's own recorded gap (insertion order only).

**Files touched:** `resources/views/corex/properties/show.blade.php` (URLs, state, the six new
methods, the toolbar button, `pairedStripRows()` replacing `_stripPad()`, `init()`/
`refreshInspectionData()` wiring); `resources/views/corex/properties/partials/rental-inspection-item-cell.blade.php`
(drag/drop attributes on the three tile kinds); `resources/views/corex/properties/partials/rental-inspection-recording.blade.php`
(two new CSS classes only). No other file changed.

**Verification this round:** `php -l` clean on all three files. `php artisan view:cache` — compiles every
Blade template in the app, including these three — clean (this is the real structural check for a Blade
file; the STANDARDS.md incident this check guards against was exactly this class of defect, an unbalanced
directive silently breaking a page). `dev-check.ps1` — confirmed via `.ai/STANDARDS.md` ("There is no
`pwsh` on this box. It has never run here, for any build, ever") that it cannot run in this environment;
stated here plainly rather than claimed as passing. Its named replacements
(`fetch-authenticated-page.php` + `verify-alpine-render.mjs`) verify a DEPLOYED page's real rendered HTML —
since this round does not deploy (Johan's explicit instruction; cc5 lands and verifies in a real browser
next), running them now would only check the pre-existing QA1 page, not this commit's own changes, so they
were not run. No live-browser verification was performed this round — that is cc5's step, not this one's.

---

### 24.11 The compare viewer does not reflect pairing — investigation and approach only, NOT YET BUILT

**Status: investigation and design only. `show.blade.php` has not been touched.** Sequenced behind cc3's
concurrent photo-notes Blade pass in the same file, per Johan's explicit instruction — this section is
written so the change is ready to make the moment cc3 is out and Johan releases the file, not worked out
live while racing another lane's edits.

Two prior fixes today (§24.10's original interleave, then the follow-up in commit `5e8d3e22b`) covered the
recording screen's own two-block strip. Neither touched the SEPARATE full-screen compare viewer
(`compareViewer.*`, §20.17) — confirmed by reading, not assumed: `compareViewerCarouselPhotos()`
(`show.blade.php:6113-6115`) still reads straight through `compareViewerPhotosForSide()`
(`:6105-6111`, unchanged since §20.17.3, 2026-09-24), which is a raw per-side photo array with no reference
anywhere to `photoMatches`/`groupForPhoto`. The viewer's "click a photo, its match loads on the other side"
mechanic (`compareViewerSelectCarouselPhoto()` `:6127-6140`, `openCompareViewer()` `:6063-6089`, both via
`compareViewerGroupSideMembers()` `:6145-6149`) already IS pairing-aware and already works — that part of
"pairing" was built in §20.16/§20.17 and needs no change. What's missing is narrower and specific: the
PASSIVE order of each side's own carousel/thumbnail rail — what an agent sees scrolling through it, or
opening the viewer cold — has never reflected pairing at all.

#### What the viewer builds its list from today

`compareViewerPhotosForSide(side)` (`:6105-6111`): resolves `insp` (`chainPredecessor` for left,
`chainTail` for right), then reads `roomPhotosForInspection(insp, roomId)` when `compareViewer.kind ===
'room'`, or `conditionForInspection(insp, itemId).photos` when `kind === 'item'` — filtered only for a
present `storage_path`. Room-kind and item-kind are two different underlying arrays, but the function
already abstracts that distinction away for every caller. `compareViewerCarouselPhotos(side)`
(`:6113-6115`) is a one-line pass-through, gated only on `compareViewer.open`.

#### Where pairing data would come from

Exactly what the strip already uses: `this.photoMatches` (the array of group payloads, already loaded —
`config.inspectionData.photo_matches`, refreshed on every `refreshInspectionData()` call) and
`this.groupForPhoto(photoId)` (`:5807` at the time of writing — a plain lookup, already used by
the viewer's OWN existing click-loads-partner mechanic via `compareViewerGroupSideMembers()`). No new data
plumbing is needed — the viewer already has everything the strip has.

#### The carousel index / selection state — traced, not assumed a risk

`compareViewer.leftPhotoId`/`rightPhotoId` are PHOTO IDs, never array positions (`compareViewer[side +
'PhotoId'] = photo.id`, `compareViewerCurrentPhoto()`/`compareViewerPhotoById()` both resolve by `.find(p
=> p.id === id)`). Reordering the array a photo lives in does not invalidate which photo is selected — the
same photo object is still found by id regardless of where it now sits. The "N of M" position label
(`:5318`, `:5439`) is computed fresh every render via `.findIndex()`, never cached, so it updates correctly
on its own when order changes. `compareViewer.step.left`/`.right` (the "1 of 5" stepper) is a SEPARATE
index into `compareViewerCandidatesFor(side)` — a matched GROUP's own members on one side, an entirely
different array from the carousel/rail list — reordering the rail does not touch it. Conclusion: there is
no stale-index risk from reordering the carousel array. The one deliberate, desirable side effect: if an
agent matches or unmatches a photo from inside the open viewer (`compareViewerMatch()`), `this.photoMatches`
changes reactively and the rail re-sorts itself immediately — the newly-paired photo jumping toward the
front of both rails is correct behaviour, not a bug to guard against.

#### Proposed approach

1. **Extract the existing (already fixed, already verified) row-building algorithm out of `pairedStripRows()`
   into a two-argument, data-only function** — `pairedRows(predPhotos, tailPhotos)` — that takes two
   already-resolved photo arrays and returns `{predecessorPhoto, tailPhoto, index}[]`: matched-group rows
   first (sorted by group id, same "insertion order" choice §24.9 already recorded), then every remaining
   photo on either side, interleaved one-pred/one-tail so neither side is ever starved — the exact logic
   `5e8d3e22b` proved correct for the strip, just no longer hard-coded to read `itemPhotosForInspection`/
   `itemPhotosFor` itself.
2. **`pairedStripRows(item)` becomes a thin wrapper**: `this.pairedRows(this.itemPhotosForInspection(this.chainPredecessor,
   item.id), this.itemPhotosFor(this.tailSection(), item))`. No behaviour change for the strip — same
   algorithm, same inputs, just called through the extracted core.
3. **A new `compareViewerPairedRows()`**: `this.pairedRows(this.compareViewerPhotosForSide('left'),
   this.compareViewerPhotosForSide('right'))`. Because `compareViewerPhotosForSide()` already branches on
   `kind` internally, this covers room-kind AND item-kind for free — `pairedRows()` itself only ever looks
   at `photo.id` and group membership, it has no idea whether the photos came from a room or an item.
   (Room-level pairs already exist today via the viewer's own pre-existing click-to-match action or
   auto-pair's room-level key — §24.9's `(property_room_id, null)` case — even though drag-to-pair itself
   is item-only per Johan's ruling §24.9. The viewer reflecting a pair is a separate concern from what can
   CREATE one.)
4. **`compareViewerCarouselPhotos(side)` is redefined in terms of it**, keeping its exact existing
   signature and return shape (an array of photo objects) so every template binding that already consumes
   it — the "N of M" label, both thumbnail rails, the single-mode carousel — needs no change at all:
   ```
   compareViewerCarouselPhotos(side) {
       if (!this.compareViewer.open) return [];
       return this.compareViewerPairedRows()
           .map(r => side === 'left' ? r.predecessorPhoto : r.tailPhoto)
           .filter(Boolean);
   }
   ```
   Filtering out the null half of each row is enough — the viewer's two rails are independent scrollable
   lists, not a fixed two-column grid like the strip, so there is no "NO MATCH" placeholder tile to invent
   here; a side's own unmatched photos simply follow its own matched ones, in its own rail. "Labelled with
   the side it came from" (the spec's own wording) is already satisfied structurally — each rail sits
   under its own pane, already headed "IN"/"CURRENT" (§20.17.3) — not something this change needs to add.
5. **`openCompareViewer(photo, insp)` is untouched** — its signature, its behaviour, and every line inside
   it stay exactly as they are. This satisfies the settled contract by construction, not by care taken
   around it: nothing above calls or modifies it.

**Proof the "nothing paired yet" case degrades to today's exact behaviour, not an empty or reordered
carousel:** with zero relevant groups, `pairedRows(pred, tail)` produces only the interleave step —
`unmatchedPred` is the FULL `pred` array (nothing was ever added to `usedPred`) and `unmatchedTail` is the
full `tail` array, walked in their own original order and merely alternated pred-row/tail-row/pred-row/....
Mapping `compareViewerCarouselPhotos('left')` back out and filtering nulls recovers `pred` in its exact
original order — every OTHER row in the interleave is a tail-only row contributing `null` to the
predecessor side, filtered away. Same for the right side. **This is a provable no-op on the common,
fresh-inspection case, not merely an expectation** — the fresh, nothing-paired screen looks identical to
today's.

**Secondary, NOT decided here — flagged for Johan, not silently folded in:** `compareViewerSelectItem()`
(`:6291-6313`, switching room/item tabs inside an already-open viewer) currently defaults the left/right
selection to `compareViewerPhotosForSide(side)[0]` — array position, not pairing order. Swapping that one
read to `compareViewerCarouselPhotos(side)[0]` would make the tab-switch default consistent with the new
rail order (land on the first PAIR when one exists, rather than an arbitrary upload-order photo) — a small,
low-risk, one-line change, but it is an addition to what was asked for ("order the carousel"), not required
by it, so it is named here rather than made without asking.

**Files this will touch when built:** `resources/views/corex/properties/show.blade.php` only — the
extraction described above, entirely inside the existing `rentalImages()` script block. No other file.
Estimated surface: the existing ~23-line `pairedStripRows()` body moves into a new `pairedRows(predPhotos,
tailPhotos)`, both wrapper functions become one-line calls into it, `compareViewerCarouselPhotos()` gains a
`compareViewerPairedRows()` companion. No template (`.blade.php` markup) changes are needed anywhere in
this round — every consumer of `compareViewerCarouselPhotos()` already expects exactly the shape it will
keep returning.

---

## 25. AT-433 Part C — photo notes (2026-09-27)

Johan's feature, verbatim in substance: every photo carries its own note — what THIS photo shows, not
what the item is like overall (that's `RentalInspectionObservation.notes`, unchanged, untouched). Two
levels, never merged: the item comment says what the item is like; the photo note says what makes THIS
photo evidence. A note carries a classification (Defect / Wear and tear / Reference by default, agency-
configurable — every list is a setting). Shown under the thumbnail in the strip, truncated to two lines;
the full note opens with the photo in the compare viewer. A room-level "Photo notes on/off" switch,
remembered per user. The note travels with the photo into the compare viewer AND (data-layer only this
round — see §25.5) onto the printed inspection form, where the classification is what lets a defect list
render on its own at the end. Full CRUD, soft delete only. Read-only once the inspection is completed and
signed (Johan's ruling, confirmed against the code — see §25.2).

Built in two passes, both by the same lane: backend first (parked mid-build when cc4's photo-upload-
immediate fix needed the same file), then the Blade/JS pass once cc4 (`2cc8951f9`) and cc6's pairing work
were both out of `show.blade.php`.

### 25.1 Investigation — file:line

- **Photo identity** — `RentalInspectionPhoto` (`app/Models/RentalInspectionPhoto.php`), one row per
  photo, real `id`, soft-deletable (`archive()`). Nothing resembling a per-photo note existed anywhere in
  the codebase before this round (checked — the only prior per-photo metadata is tagging: room/observation
  id, `tagged_at`/`tagged_by_user_id`).
- **The item comment** — `RentalInspectionObservation.notes` (immutable, append-only, §3.3) — the ONLY
  existing free-text field on the item/photo surface before this round. Deliberately not touched or
  reused: it answers "what is this item like," never "what does this specific photo show."
- **The printed inspection form — two different documents, only one is relevant:**
  - `RentalInspectionFormPdfService` — the BLANK OMR tick-box capture form, generated BEFORE an
    inspection, for wet-ink recording. Renders no photos, no notes — not the document Johan meant.
  - `RentalInspectionReportPdfService` — the COMPLETED inspection's own report, generated AFTER. Johan's
    own ruling on this exact service (2026-09-23, its own docblock): **no photos** ("printing the photos
    will be a shitshow... the inspection reports will turn into 100 pages") — a QR/link to the public page
    instead. This is where a defect list attaches: the classification lets the report list every
    `defect`-classified note in one section at the end, as TEXT — never a photo grid, consistent with
    Johan's own no-photos ruling on this exact document. **Not built this round** — the report's own
    `generate()`/`report-pdf.blade.php` are Blade/PDF-template work, out of scope for the backend-only
    pass and not reached in the Blade/JS pass either (that pass's scope was the recording screen and
    compare viewer, per Johan's own instruction) — see §25.5.

### 25.2 Data model

`rental_inspection_photo_notes` — `agency_id`, `rental_inspection_id` (denormalized, same convention as
`rental_inspection_photos.rental_inspection_id`), `rental_inspection_photo_id` (the real parent),
`classification_key`, `note` (text), `created_by_user_id`/`updated_by_user_id`/`archived_by_user_id`,
`timestamps()` + `softDeletes()`.

Full CRUD, soft delete only — a deliberate departure from this module's usual immutable/append-only
convention for observations/room notes/findings, per Johan's explicit ruling for this feature specifically.
At most one LIVE note per photo, enforced at the model/controller layer
(`RentalInspectionPhotoNoteController::store()` refuses a second one), **not** a DB unique constraint — a
unique index has no soft-delete awareness (BUILD_STANDARD §5a) and would collide on the very archive-then-
recreate flow this design supports.

**What happens when the photo is removed:** `RentalInspectionPhoto::archive()` cascades — the photo's live
note (if any) is archived in the same call. This was NOT the original design call (the first draft assumed
a parent's own SoftDeletes global scope would protect a child read FROM the parent for free, the same way
it protects the reverse direction — `$note->photo` going null once the photo is trashed) — found wrong by
testing: `$photo->fresh()->note` still returned the live note after archiving, because `fresh()` explicitly
bypasses global scopes on itself, and a plain `hasOne` relation has no reason to consult the parent's own
trashed state. Fixed with an explicit cascade rather than left mismatched. No symmetric auto-restore on
photo restore: no route in this module restores an archived photo at all yet (a pre-existing gap, flagged
to the conductor, not fixed here per SCOPE LOCK).

**Read-only once signed:** `RentalInspectionPhotoNote::assertMutable()` throws once the inspection's status
is `completed` or `cancelled`. Confirmed against the code, not assumed — `RentalInspection::markCompleted()`
is the ONE transition that both sets `status=completed` AND is gated on every required signature already
existing (§15), so "signed" and "completed" are the same event in this data model; there is no in-between
state where signing is done but completion hasn't happened yet. Mirrors `RentalInspection::updateDetails()`'s
own precedent exactly (locks on COMPLETED or CANCELLED, never COMPLETED alone).

**Classification vocabulary** — `RentalInspectionSetting::photoNoteClassificationsFor($agencyId)`, same
read-time-default JSON-column pattern as `condition_states`. Default: Defect / Wear and tear / Reference.
Agency-configurable via `RentalInspectionSettingsController::updatePhotoNoteClassifications()` — the
controller-side saver and its route are built; the Setup Wizard/settings-screen Blade section is not (see
§25.5 — same "no fitting repeater control" reasoning already recorded for `refusal_reason_presets` and
`room_type_walking_order`, flagged for Johan's call rather than decided here).

**Own/branch/agency scoping** — this module has never implemented an own/branch narrowing dimension
anywhere (photos, observations, signatures, room notes are all agency-scoped only, via `BelongsToAgency`'s
`AgencyScope`, plus an explicit "does this photo/note actually belong to this inspection" check in every
controller action). Photo notes follow the exact same, only-existing precedent rather than inventing a new
scoping dimension unilaterally for one feature — flagged to the conductor as a module-wide question, not
decided here.

**A false alarm, corrected:** an early cross-agency 404 test failed (got 201, not 404), which briefly read
as a real `AgencyScope` security gap affecting this whole module. Root cause, found by direct comparison
with a raw Tinker reproduction that DID scope correctly: `BelongsToAgency`'s `creating()` hook force-stamps
`agency_id` from the CURRENTLY AUTHENTICATED user's own effective agency, overriding any explicit value
passed to `create()`. The test built its "other agency" fixture while already `actingAs()` the first
agency's user, so every row silently landed on agency 1 regardless of the `agency_id` written in the test —
the cross-agency scenario was never actually exercised. Fixed by building that fixture logged-out (matching
this test class's own `setUp()` pattern), matching the tinker reproduction's own working order. Retracted
directly, in full, the moment the real cause was found — `AgencyScope` and the pre-existing
`RentalInspectionRecordingController` have no defect here.

### 25.3 Backend built

- Migrations: `2026_10_03_210000_create_rental_inspection_photo_notes_table.php`,
  `2026_10_03_210100_add_photo_note_classifications_to_rental_inspection_settings_table.php`.
- `RentalInspectionPhotoNote` model — full CRUD, `assertMutable()`, `liveFor()`.
- `RentalInspectionPhoto::note()` (hasOne) + `archive()` cascade (§25.2).
- `RentalInspectionSetting::photoNoteClassificationsFor()` + `DEFAULT_PHOTO_NOTE_CLASSIFICATIONS`.
- `RentalInspectionPhotoNoteController` — store/update/archive/restore, routed under
  `/corex/rental-inspections/{rentalInspection}/photos/{photo}/notes[/{note}[/restore]]`, gated
  `rental_inspections.create` (matching every other mutation on this controller family). Web routes
  returning JSON, not `/api/v1/*` — deliberately following this exact module's own established
  convention (every sibling photo/tag/signature endpoint here is the same shape), not an oversight of
  CLAUDE.md non-negotiable #7.
- `RentalInspectionSettingsController::updatePhotoNoteClassifications()` + its route — same narrow-saver
  discipline as `updateConditionStates()`.
- Read paths carry the note for free: `RentalInspection::tabPayloadFor()`'s `photos.note`/
  `observations.photos.note` eager-loads (recording screen), `RentalInspectionPhotoMatchGroup::
  toComparePayload()`'s `members.photo.note` + `'note'` field (compare viewer's own group payload), and
  `tabPayloadFor()`'s new `photo_note_classifications` key (the agency's vocabulary, client-side).
- **Verification:** 16 feature tests (`RentalInspectionPhotoNoteControllerTest`) — happy-path CRUD,
  one-note-per-photo, each required field individually empty, an unknown classification key rejected,
  cross-agency 404 (via route-model binding, §25.2), archive cascades to the note, restore rejected when a
  different live note already exists, mutations blocked once completed AND once cancelled. All green.
  `php -l` clean on every changed PHP file.

### 25.4 Blade/JS built

Rebased onto QA1 `4fe10b6bd`, then onto cc4's `fix-inspection-photo-upload-immediate-2026-09-26` branch tip
(`2cc8951f9`, not yet landed on QA1 at the time — read in full before touching the same markup, per Johan's
explicit instruction) before any markup was touched.

- **Under the thumbnail, two lines** — `.rir-strip-note` (new CSS class,
  `rental-inspection-recording.blade.php`), an absolutely-positioned overlay CAPTION inside the existing
  `.rir-strip-tile`, `pointer-events:none`. Deliberately NOT a taller tile: this row's height has already
  broken and been re-fixed multiple times (§22.3/22.3a/22.3b) on the same "class-level static geometry,
  never a bound `:style` next to a static one" principle; an overlay changes nothing about tile/row height
  at all, so it cannot reopen that class of regression, and `pointer-events:none` means it never competes
  with the Select/tag/untag corner buttons it visually sits under. Rendered in BOTH tile branches of
  `rental-inspection-item-cell.blade.php` (read-only predecessor AND editable tail), keyed off
  `tile.photo.note` — never row index, position, or pairing state (Johan's ruling: every note renders for
  every photo that renders).
- **The composer, in the compare viewer** — "the full note opens with the photo in the compare viewer,
  where there is room for it" (Johan, verbatim). A `.cv-tagpanel`-shaped panel (`compareViewerNotePanel`,
  same anchored-over-the-pane shell as `compareViewerTagPanel`, not a second panel mechanism), reachable
  via an "Add note"/"Edit note" button beside "Tag photo"/"Retag" in both single-mode and compare-mode
  headers. Classification picker + textarea; Save (POST if none exists yet, PATCH if editing) and Remove
  note (DELETE), via a small self-contained fetch helper (`_compareViewerNoteRequest()` — not a reuse of
  the shared `_post()`, which is hardcoded to POST). The full note (untruncated) also renders inline in
  both pane headers whenever one exists.
- **No note control on a tile with no id yet** — satisfied by construction, not a bolted-on guard: a
  PENDING/uploading tile (`pendingUploadTilesFor()`, cc4's own AT-436 rework) has no `openCompareViewer()`
  binding at all — only a real, server-persisted photo tile opens the viewer, and the note composer only
  ever opens FROM the viewer. There is no code path that could offer note editing on an id-less tile.
- **Room-level "Photo notes on/off," remembered per user** — `photoNotesVisible` (keyed by room id),
  `localStorage` key `hfc.inspPhotoNotesVisible`. Same client-only persistence pattern this screen already
  uses for `itemStripExpanded` (`hfc.inspStripExpanded`) — this screen has no per-user preference endpoint
  at all (checked, same finding that pattern's own docblock already states), so this is the same mechanism
  under a new key, not a second one. Defaults ON. Toggle button rendered beside "Collapse/Expand photos" in
  the room heading (editable side only, shared state reacts on both cells — same "one master switch"
  convention as that button).
- **Signed inspections read-only** — the note panel's Save/Remove buttons are hidden and replaced with a
  plain-language explanation (`compareViewerNoteLocked()`, mirroring the server's own `assertMutable()`
  gate) whenever the owning inspection is completed or cancelled — STANDARDS.md "No Silent Locks": say why,
  never let a control silently 409.
- **Never keyed off row position or visible index** — the note lives on `tile.photo.note` / the photo
  object itself throughout; nothing added this round reads `tile.index` or any array position to resolve a
  note. A photo that is paired, unpaired, reordered, or has its strip collapsed/expanded keeps its note
  unchanged, because nothing about those operations touches the photo's own `.note` field.

**Files touched:** `resources/views/corex/properties/show.blade.php` (config/refresh wiring for
`photoNoteClassifications`, the room-toggle state/methods, `photoNoteClassificationLabel()`, the compare-
viewer note-panel state/methods/markup, the pane-header note button + inline note display, both modes);
`resources/views/corex/properties/partials/rental-inspection-recording.blade.php` (the `.rir-strip-note`
CSS class, the room-heading toggle button); `resources/views/corex/properties/partials/rental-inspection-
item-cell.blade.php` (the note caption in both tile branches). No other file changed.

**Verification this round:** `php -l` clean on all three files (a weak signal for `.blade.php` — Blade
directives outside `<?php ?>` are inert text to the PHP parser, so this only proves no genuinely broken raw
PHP tag exists, not that the Blade compiles). The real check: stood up this worktree's OWN local render
(`composer install` + `npm install && npm run build` + a seeded agency/property/inspection/photo/note in
this worktree's own isolated test schema + `php8.2 artisan serve`), then ran
`scripts/fetch-authenticated-page.php` + `scripts/verify-alpine-render.mjs` against that real, self-served
response — not the pre-existing QA1 page, this commit's own rendered output. Ran it twice: once against
this diff, once against the same commit with only the three Blade files stashed back to their pre-diff
state (`git stash push -- <the three files>`), to isolate what this round changed, same method cc4's own
round used. Identical failure set both times — two pre-existing `localStorage`/`document` inline-`x-data`
issues (sidebar collapse, readiness widget) and one pre-existing `form.getAttribute` script-eval issue,
none of it rental-inspections code, all already known from cc4's own round. The metric the standing rule
actually names, Alpine expression syntax errors: zero both before (1927 attributes) and after (1975
attributes) — this round's 48 new bindings all compile clean. `php artisan view:cache` — compiles every
Blade template in the app — clean. Did not deploy, per instruction.

### 25.5 What still needs the Blade/PDF-template pass — not built this round, named on purpose

- **The printed report's defect-list section** — `RentalInspectionReportPdfService::generate()` would need
  to query live, non-archived `defect`-classified notes for the inspection and pass them to
  `report-pdf.blade.php` for a new end-of-document section; the template itself needs that section built.
  Not started — this round's scope was the recording screen and compare viewer (Johan's own instruction),
  and the report service/template are a distinct Blade surface with their own render-gate exposure.
- **Settings-screen UI + Setup Wizard entry for the classification list** — the resolver, saver, and route
  are built (§25.3); the actual `<form>` section on `/corex/settings/rental-inspections` and its Setup
  Wizard control are not. Same "no repeater control type" gap already on record for `refusal_reason_presets`
  and `room_type_walking_order` — Johan's call whether this needs a new wizard control type or stays
  settings-screen-only, not decided here.
- **Mobile/API shape** — §14.2's mobile-parity table does not yet list a photo-note endpoint. Not requested
  this round; noted so it isn't mistaken for an oversight if the mobile app needs it later.

---

## 26. The dead-bulk-action-button class, closed — plus compare-viewer pairing order and real click-through coverage (2026-09-27)

Three deliverables, one root incident: cc5 found "All Good" (a per-room bulk-fill button) rendering
permanently disabled on every fresh page load, unclicked for five days since the 2026-09-22 rebuild.
Investigated read-only first (Johan's own instruction), released once cc3's photo-notes pass (§25) was out
of `show.blade.php`. Rebased onto `83bcaa65a` (§25 landed) before touching anything.

### 26.1 The bug, confirmed by reading, not assumed

`rental-inspection-recording.blade.php:540`, at the time: `:disabled="markGoodBusy[group.room?.id]"`.
`markGoodBusy: {}` (`show.blade.php`) starts genuinely empty and is populated ONLY imperatively inside
`markRoomGood()` — no room's key exists until that room's button has fired once, so every fresh load reads
`undefined`, not `false`. Same root cause this repo already named and fixed twice: `isObsBusy()`
(2026-09-22, condition buttons) and `scripts/rental-click-through.mjs`'s own documented 2026-09-15
incident (the strike/restore button) — a boolean-attribute binding backed by `undefined` does not behave
like one backed by `false`. `markGoodBusy`/`markNaBusy` were written in the SAME 2026-09-22 rebuild that
fixed `obsBusy` — the fix reached one sibling, never the other two that share the identical shape.

### 26.2 Fixed — three instances, the proven pattern, not a new one

`isMarkGoodBusy(roomId)`, `isMarkNaBusy(roomId)`, `isDiscBusy(discrepancyId)` — each `return !!this.xBusy[key]`,
mirroring `isObsBusy()` exactly. Used in both the `:disabled` binding and the `x-text` reading the same key
(`show.blade.php`); `discBusy[discrepancy.id]`/`markGoodBusy[group.room?.id]`/`markNaBusy[group.room?.id]`
replaced with the wrapped calls at their three template sites
(`rental-inspection-recording.blade.php:288,567,570,575,578`). `discBusy` (the "Resolve" discrepancy button)
was fixed alongside the confirmed-dead one on structural evidence — byte-identical shape (object initialised
`{}`, populated only imperatively inside an async handler, read via a raw non-negated bracket lookup,
inside an `x-for`) to the cc5-confirmed-dead case — not independently reproduced live in a real browser this
round (Playwright/Puppeteer browser binaries are not available in this environment without a network
fetch this round chose not to gamble on mid-incident; real-browser confirmation is §26.4's click-through
checks, run by cc5).

### 26.3 The class swept, not just the instance — complete list

Every boolean-attribute binding (`:disabled`/`:readonly`/`:required`/`:checked`) on the Inspections tab
reading a bracket lookup on an object populated lazily, checked directly:

| Control | Binding | In an `x-for`? | Verdict |
|---|---|---|---|
| All Good (per-room) | `markGoodBusy[group.room?.id]` | yes | **Fixed** — confirmed dead |
| Mark room N/A | `markNaBusy[group.room?.id]` | yes | **Fixed** — same shape as confirmed-dead |
| Resolve (discrepancy) | `discBusy[discrepancy.id] \|\| !discField(...).accepted_observation_id` | yes | **Fixed** — same shape |
| Tag selected (room photo bulk-tag) | `!roomPhotoTagItemChoice[group.room.id]` | yes | Negated (`!`) — Alpine always receives a real boolean regardless of key presence. Not the same risk; not changed. |
| "Set" room type (property spaces) | `itemBusy \|\| !assignTypeChoice[item.id]` | yes | `itemBusy` is a plain scalar; the bracket half is negated. Not the same risk; not changed. |
| All good — whole inspection | `markAllGoodBusy` (plain scalar) | no | Different, safe shape. |
| Start In/Out/Ad-hoc Inspection | `startBusy[{{ $sectionJs }}]` / `startBusy['in']` | no (single element, never repeated per-item) | Not inside an `x-for`; empirically proven working throughout this whole build. |
| Condition buttons | `isObsBusy(section, itemId)` | yes | Already carries the 2026-09-22 fix. |

**No fourth or fifth dead control found.** The two remaining bracket-lookups (`roomPhotoTagItemChoice`,
`assignTypeChoice`) are both guarded by JavaScript's own `!` negation, which always returns a real boolean
regardless of whether the underlying key exists — structurally immune to this specific failure, independent
of x-for or population timing.

### 26.4 Compare-viewer pairing order, built (§24.11's approach, unchanged)

Exactly the approach written up and released for build in §24.11: extracted `pairedStripRows()`'s
already-fixed row algorithm into `pairedRows(predPhotos, tailPhotos)` (data-only, two arguments);
`pairedStripRows(item)` and a new `compareViewerPairedRows()` are both one-line wrappers around it;
`compareViewerCarouselPhotos(side)` redefined against it with its exact existing signature. No template
changes anywhere — every consumer already expected an ordered array of photo objects.
`openCompareViewer(photo, insp)` untouched, not called or modified by anything in this round. The
nothing-paired-yet case is a provable no-op (§24.11's own analysis, unchanged by this build).

### 26.5 Click-through coverage for the Inspections tab — the class fix

`scripts/rental-click-through.mjs` existed for exactly this ("a test proving the endpoint works is not the
same claim as an agent can reach it") but its coverage was an opt-in, named list that never included the
Inspections tab — the actual reason a dead button went unnoticed for five days, not bad luck. Closed by
adding this screen to the SAME gate, not a parallel one:

- **`scripts/rental-inspection-click-through-fixture.php`** (new) — a throwaway property/lease/rooms/items/
  chain, mirroring `rental-click-through-fixture.php`'s own create/cleanup discipline exactly. Verified
  end-to-end for real against `corex_qa1` this round (create, confirmed `tabPayloadFor()` resolves the
  chain and `RentalInspectionPhotoAutoPairService` finds its one unambiguous pair, cleanup) — not merely
  `php -l`'d. Rooms/items deliberately spread across Bedroom 1 / Bathroom 1 / Garage so the three "mark"
  checks (#21/#22/#23) each have their own untouched precondition and don't consume each other's.
  `RentalInspectionItem`/`PropertyRoom` are not soft-deletable anywhere in this module (checked, not
  assumed) — left in place under the cleaned-up, now-trashed property/agency, same tolerance the original
  fixture already extends to `RentalApplicationAssessment`.
- **Seven new named checks** (#20–#26 in the gate's own header) — Photo notes on/off (client-only, asserts
  the label flips, same style as the existing gate's own #7), Mark room good, Mark room N/A, All good
  whole inspection, Add photo(s) (a real Puppeteer file upload, not a synthetic FileList), Next inspection,
  Auto-pair. Ordered deliberately (§26.5's own header comments state why for each) so no check consumes a
  precondition a LATER check still needs.
- **`newPage()` gained an `acceptDialogsMatching` param**, default `[]` (identical behaviour for every
  existing caller) — `markRoomNa()`/`markAllGood()` are the only two controls in this WHOLE gate that still
  use a genuine native `window.confirm()`, unlike Approve/Decline (which had theirs removed after the
  2026-09-16 frozen-tab incident this file's own header documents). A real, per-control allowlist by message
  substring, not a blanket auto-accept — any dialog not on the list still fails loudly via the same path as
  before. Not a weakening of the standing policy; named here so it reads as a deliberate, scoped exception,
  not a regression of it.
- **Real, existing selectors added, not invented ones assumed** — `data-qa` attributes on all seven controls
  (`mark-room-good`, `mark-room-na`, `mark-all-good`, `toggle-photo-notes`, `next-inspection`, `auto-pair`,
  `add-item-photo`), and the tab switch uses the property page's own real `data-prop-tab="inspections"`
  attribute (checked directly, not guessed).

**Not run end-to-end this round** — Playwright/Puppeteer needs browser binaries this environment doesn't
have cached, and fetching them requires network access this round chose not to gamble on mid-incident. The
fixture script itself WAS run for real (§26.5's own note above); the full `.mjs` gate, including these
seven new checks against a live page with real clicks, is cc5's step next, per Johan's own "cc5 lands and
verifies" instruction for this round.

### 26.6 Verification this round

`php -l` clean on all five changed/new files. `php artisan view:cache` — compiles every Blade template in
the app — clean. Real Alpine render gate: local `php artisan serve` against this worktree, fetched
`/corex/properties/1724` through `scripts/fetch-authenticated-page.php` as a real authenticated user, ran
`scripts/verify-alpine-render.mjs` against the response — **2130/2130 Alpine attribute expressions compile
clean**, attribute-scoped per instruction (every attribute on the page, not just the lines touched this
round); the same pre-existing, unrelated `localStorage`/`document`/`form.getAttribute` findings as every
prior round, none referencing anything in this diff (checked by name). Did not deploy.

---

## 27. Recording-screen navigation — space nav, photo show/hide, problem filter (2026-09-27, investigation & spec only — NO CODE)

**Scope of this section:** Johan asked for the navigation/filtering layer the recording screen is
missing — the same "search/sort/filter" design standard BUILD_STANDARD §1b already requires for list
screens, applied to a form screen instead. His own words, verbatim: "The inspection screen. I know what
we are still missing. shall I call it crud? like with the photo compare - top spaces to quick navigate
to. maybe a tick to show / hide photos, maybe a tick to show all or only problem spaces. you know, a
proper navigation of the inspection screen. a small inspection is a scroll. a large inspection is a
proper scroll." Nothing below is built. This is investigation and proposal only, per instruction, so
Johan can rule on the open questions before any lane touches code.

### 27.0 Process finding, stated first because it changes what "the file" means

**`resources/views/corex/properties/show.blade.php` on `Staging` is NOT the screen Johan is describing.**
Confirmed directly: `origin/Staging` is 330 commits behind `origin/QA1` and carries zero commits QA1
doesn't have (`git log --oneline origin/Staging..origin/QA1` = 330; the reverse = 0). The entire
rental-inspections module — the recording screen, the compare viewer, the settings page, every model and
migration this section references — exists only on `origin/QA1` and its feature branches; it has never
been merged to `Staging`. On `Staging`'s own checkout, `resources/views/corex/properties/show.blade.php`
contains only the old flat "In Inspection"/"Out Inspection" image-gallery sections (18 matches, lines
~4390-4800) — pre-dating this whole rebuild.

Every file/line reference below is against **`origin/QA1`**, read via `git show origin/QA1:<path>`
(never checked out, never merged — this investigation touched nothing on disk in this checkout). This
also means this spec file itself, `.ai/specs/rental-inspections.md`, does not exist on `Staging` at all
— its §§1-24 above were pulled from `origin/QA1` onto this branch in the same commit as this section, so
the file is internally consistent (this section's own cross-references to §17.1/§20/§20.14 resolve to
real content) rather than starting a duplicate, divergent copy. Flagged loudly rather than silently: the
two lanes Johan said are "inside `properties/show.blade.php` right now" must be working against QA1 (or
a branch rebased on it), not `Staging` — worth Johan confirming, since this file cannot be the one they
are editing if they are looking at a Staging checkout.

### 27.1 What already exists — the four reuse points, confirmed by reading the code

**1. Space navigation.** The pattern Johan is pointing at is real and already shipped, but only inside
the compare-viewer MODAL (§20.17.3), not on the recording screen itself:

- `compareViewerRoomTabs()` (`show.blade.php:6281-6283`) is simply `this.roomGroups().filter(g =>
  g.room)` — it reuses the SAME room grouping the recording screen's own vertical list already builds
  from (`roomGroups()`, `show.blade.php:6541-6570`), so a top nav strip on the recording screen would
  need no new data source, only a new rendering of data that already exists.
- The strip markup (`show.blade.php:5240-5250`, the "SPACE row"): a `<div class="flex items-center gap-1
  overflow-x-auto">` of `<button>` tabs, one per room, active tab gets a class swap
  (`.cv-space-tab-active`, CSS at `:82-83` — a 2px cyan bottom border, no background change). Below it, a
  second identical-pattern row (`:5256-5272`, the "ITEM row") does the same for the selected room's items.
- **The room set is identical for In and Out** — `roomGroups()` is built from `this.items` (property-wide
  `rental_inspection_items`), not from either section's own observations, so a top nav strip built the
  same way would show the same tabs regardless of which section (In/Out/ad-hoc) is the current tail; only
  a room's `recorded/total` badge differs per section (`roomProgress(section, group)`,
  `show.blade.php:7029-7038`).
- **Mobile is already answered by this exact pattern, not a separate design question** — see §27.5.

**2. Photo show/hide — a real control exists, but it is not the control Johan is asking for.** Two
distinct, already-shipped mechanisms, easy to conflate and worth naming precisely:

- **Per-item strip expand/collapse** — `itemStripExpanded: {}` (`show.blade.php:7346`), keyed by item id
  only (not by section/side — the code comment at `:7336-7345` states this explicitly: "toggling from
  either cell... moves both sides together"), persisted client-side to `localStorage('hfc.inspStripExpanded')`
  (`_persistStripExpanded()`, `:7360-7362`; restored in `init()`, `:7367-7371`). Its ONLY effect is how
  many thumbnails a strip shows — `stripVisibleCount()`/`stripMoreCount()` (`:7327-7335`) cap it at 4
  when collapsed, uncapped when expanded (`isItemStripExpanded()`, `:7347`). The strip's presence — the
  `flex-1 min-w-0 overflow-x-auto` block plus its always-present "add photo(s)" tile (§20.14.4/R2,
  `rental-inspection-item-cell.blade.php`) — is unconditional; this flag never removes it.
- **Room-level "master switch" for that same flag** — `allItemStripsOpenInRoom(group)` /
  `toggleAllItemStrips(group)` (`show.blade.php:7352-7359`), rendered as a button literally labelled
  "Expand photos"/"Collapse photos" (`rental-inspection-recording.blade.php:524-528`) next to each room
  heading. This is the "room-level control" the task named. It sets `itemStripExpanded[i.id]` for every
  item in that ONE room in one call — the exact building block a global version needs, just scoped to one
  room today.
- **The gap**: neither control ever makes the photo area disappear. Johan's ask — "an agent recording
  conditions gets a dense screen" — needs the item row's right-hand photo block (and the room's own R1
  photo gallery, §20.14.3) to not be rendered at all, so the left condition-button block can take the full
  row width. That is a different axis (presence vs. count) from what `itemStripExpanded` controls today.
  **My reading: this is not simply "promote the existing room-level button to the top" — the existing
  button's mechanism (4-vs-all thumbnail count) doesn't produce the density Johan described. It needs a
  new, separate boolean** (proposed name: `photosVisible`, default `true`, one flag for the whole screen,
  no per-room/per-item variant) that wraps BOTH photo blocks (`x-show="photosVisible"` around the item
  strip block and the room R1 gallery block) so hiding it visibly reclaims the row's width for condition
  buttons and labels. **Open question for Johan, §27.6.1**, since he named these two controls himself and
  should confirm before one is folded into the other.
- Where it would live: a single toggle in the same top bar as the new space-nav strip (§27.1.1), reusing
  the identical toggle-button visual language the compare viewer's Single/Compare segmented control
  already uses (`show.blade.php:5219-5224`, `.compare-viewer-mode-btn`/`-active`) rather than inventing a
  third visual style for a binary switch on this same screen.

**3. The condition vocabulary is real, agency-configurable, and already exposed to JS — but has no
"is this a problem" flag today.** `RentalInspectionSetting::DEFAULT_CONDITION_STATES`
(`app/Models/RentalInspectionSetting.php:175-184`) ships Good/Fair/Damaged/Not working/Missing/Other/N/A
— exactly Johan's own list plus N/A — each row shaped `{key, label, requires_notes}`. Resolved per-agency
via `conditionStatesFor()` (`:541-554`), delivered to the client as `config.inspectionData.condition_states`
(`show.blade.php:5745`) and read everywhere as `this.conditionStates` (`:6829` sets it; `:52-53` of
`rental-inspection-item-cell.blade.php` renders the two-column condition-button grid directly from it —
"any length, never a hardcoded count or split," per §20.14.4). **`requires_notes` is the only per-state
flag that exists, and it answers a different question** ("does picking this need a typed reason") from
what Johan is asking ("does this condition mean the space needs attention") — `fair` ships with
`requires_notes => false` in the shipped default, yet Johan's own proposed problem-list includes Fair.
The two flags are genuinely orthogonal and neither can stand in for the other.

**A hardcoded-word precedent already exists and is exactly the anti-pattern the task told me to avoid**
— worth flagging as a sibling defect, not fixed here per SCOPE LOCK: `compareViewerConditionClass()`
(`show.blade.php:6329-6332`) colours a condition pill by checking `conditionKey === 'good'` and
`conditionKey.indexOf('damag') !== -1` directly, with everything else falling into one "needs a look"
bucket. This works only by accident for an agency that keeps the shipped English keys; it would silently
mis-colour (or mis-flag, if reused for a filter) any agency that reduced or renamed its condition set
(Retha's own Good/OK/Bad, named in §17.1, has no `damag`-containing key at all). **The new problem-filter
must not extend this pattern** — see §27.2.

**4. "Not yet recorded" is already a real, distinct data state, not something to invent.**
`roomProgress(section, group)` (`show.blade.php:7029-7038`) already computes `recorded` as `group.items
.filter(i => this.conditionFor(section, i.id))` — an item with no observation on the current section is
already a first-class, already-computed case (it drives every "X/Y recorded" heading and the room
auto-collapse rule, §20.7). A three-way filter needs no new per-item state; it needs a read of the same
`conditionFor()` result the screen already computes for every item, every render.

### 27.2 Proposal — the problem filter must derive from settings, per Johan's own instruction

**New per-condition-state flag, following the exact existing pattern** (`requires_notes` is already
exactly this shape — one more boolean per row, same JSON column, same settings form):

- `needs_attention` (bool), added to every row of `RentalInspectionSetting::DEFAULT_CONDITION_STATES`
  and to `conditionStatesFor()`'s validated shape. Shipped defaults, matching Johan's own stated starting
  position exactly: `good => false`, `n_a => false`, `fair => true`, `damaged => true`, `not_working =>
  true`, `missing => true`, `other => true`.
- New resolver `RentalInspectionSetting::conditionNeedsAttentionFor(?int $agencyId, string $conditionKey):
  bool`, mirroring `conditionRequiresNotesFor()` (`:565-570`) line for line — an unknown/removed key
  defaults to `true` (same "an unknown state is never silently waved through" reasoning already used for
  `requires_notes`), so a stale filter never hides something the agent should still see.
- Settings UI: one more checkbox column on the existing condition-states editor
  (`resources/views/corex/settings/rental-inspections.blade.php:315-343`) — same row, same
  `states[i]` Alpine array, same hidden-field-carries-key discipline already in place for `requires_notes`
  at `:342-343`. An agency that renames "Good" to "OK" (Retha's set) or adds a brand-new state neither
  CoreX nor Retha shipped ("Excellent," say) sets this flag explicitly per row — there is no derivation
  from the label text, exactly per the task's instruction not to key off hardcoded words.
- Client-side: `itemNeedsAttention(section, item)` — reads the item's current condition key, looks it up
  in `this.conditionStates` (already resolved client-side the same way `conditionRequiresNotes()`
  already does at `show.blade.php:6925-6928`), returns its `needs_attention` flag. An item with no
  observation yet is NOT "needs attention" under this function — it is the third bucket, §27.3.

This is additive to the existing `condition_states` shape — no migration touches `requires_notes` or any
existing row's `key`/`label`; every already-recorded observation is unaffected (the flag lives on the
condition-state DEFINITION, never on the observation row itself).

### 27.3 The three-way filter — agreeing with Johan's instinct, with the reasoning made explicit

**All / Needs attention / Not yet recorded**, single-select (not three independent checkboxes), because
every item is in exactly one of these three buckets at any moment, never zero and never more than one:
recorded-and-fine, recorded-and-flagged, or not recorded at all. A pair of independent checkboxes
("show problems" + "show unrecorded") would let both switch off at once, silently hiding the entire
screen with no explanation — exactly the class of bug BUILD_STANDARD §1b calls a "real empty state"
requirement violation ("no results for this filter" must always be reachable and always explained, never
an accidental all-hidden state with no visible cause). A single three-way control can never produce that
trap: one of the three is always selected, "All" is always available as the un-filter, and empty is only
ever the truthful "you filtered to X and there is currently nothing in X."

- **Filtering by item, not by room** — a room shows in the vertical list (and its top-nav tab is enabled,
  not greyed) if it has AT LEAST ONE item in the selected bucket; within a visible room, only matching
  items render. This matches the existing, Johan-approved principle for this exact table ("Stop rendering
  two lists... render ONE list of rows," §20's own comment at `rental-inspection-recording.blade.php:665-680`)
  — the filter narrows which rows exist, it does not invent a second rendering path.
  - Filtering hides/dims a room from the top nav strip in the same reasoning the compare viewer's item
    chips already show a count per chip (`compareViewerChipCounts()`) — a filtered-empty room's tab
    should show a `0` or grey state rather than disappear outright, so the agent isn't left wondering
    where a room went; **open question, §27.6.4**, since disappearing vs. greying is a real UX call.
  - A filtered-to-match room is force-opened (overriding `roomOpenOverride`/the auto-collapse default,
    §20.7) while a non-"All" filter is active, so the filter is never fighting the room's own collapse
    state to show what it just promised to show.
- **Which side does the filter apply to?** The recording screen renders BOTH cells of every item row
  (predecessor read-only, tail editable) from the SAME `x-for`, per §20's "one list, two cells" rule
  above. The filter should read the TAIL side's condition (the side being actively worked), and hide/show
  the WHOLE row (both cells) together — never split a row so only one cell reacts, which would break the
  "Kitchen Ceiling is structurally beside Kitchen Ceiling" guarantee that same section is built to
  protect.

### 27.4 Files this would touch, if Johan approves (not built — named for the next lane)

- `app/Models/RentalInspectionSetting.php` — `needs_attention` added to `DEFAULT_CONDITION_STATES`,
  `conditionNeedsAttentionFor()` resolver.
- `resources/views/corex/settings/rental-inspections.blade.php` — one checkbox column on the existing
  condition-states editor.
- `resources/views/corex/properties/show.blade.php` — new top-of-tab nav bar (space strip, reusing
  `roomGroups()`/the `.cv-space-tab` visual language); new `photosVisible` flag + toggle button; new
  `filterMode` state (`'all'|'attention'|'unrecorded'`) + three-way control; `itemNeedsAttention()`,
  `roomHasAttentionItem()`, `itemMatchesFilter()` helper methods; scroll-to-room on tab click (needs a
  DOM anchor — room headings in the partial below currently carry no `id`, so this also touches that
  file).
- `resources/views/corex/properties/partials/rental-inspection-recording.blade.php` — room heading gets
  an `id` anchor for the nav strip to scroll to; the room-level "Expand photos/Collapse photos" button
  (`:524-528`) either removed in favour of the new global toggle or kept as a genuinely different,
  narrower control (§27.6.1); every room/item render gated additionally on `itemMatchesFilter()`.
- `resources/views/corex/properties/partials/rental-inspection-item-cell.blade.php` — photo block wrapped
  in `x-show="photosVisible"`.
- `database/migrations/` — one migration, additive JSON shape only (no column rename, no data
  migration — `needs_attention` is a new key inside the existing `condition_states` JSON, defaulted by
  `conditionStatesFor()`'s own read-time fallback exactly as `requires_notes` already is).
- Per CLAUDE.md §10a: `needs_attention` is a per-row flag on an ALREADY-not-wizard-wired setting
  (`condition_states` itself is tracked as a gap since §20.9) — this proposal does not newly break wizard
  coverage, but does not fix the pre-existing gap either; Johan's call on priority, same as §20.9 already
  flags.

### 27.5 Mobile — answered by the reuse, not a new design question

Johan asked whether room tabs wrap into four lines on a small screen. They do not, because the pattern
being reused already solves this: the compare viewer's space row (`show.blade.php:5240-5250`) is
`overflow-x-auto` with `flex-nowrap` implied by `flex items-center gap-1` and no `flex-wrap` class — it
scrolls horizontally, never wraps, and every tab carries `cv-touch` (a 44px minimum tap target, per
§20.17.6's accessibility rule). A new top nav strip built the identical way inherits this for free. The
existing R1 photo-gallery breakpoints (`grid-cols-3 sm:grid-cols-5`) and the compare viewer's own mobile
single-panel fallback (`compareViewerMobileSide`, `:5277-5279`, `sm:hidden`) are further evidence this
screen's author already designs mobile-first for this exact class of control — nothing about the new nav
strip needs a different answer.

### 27.6 Open questions for Johan — restated together, not scattered

1. **§27.1.2** — is "Show/hide photos" a genuinely new global control (my recommendation, since the
   existing room-level button's mechanism — thumbnail count — doesn't produce the density Johan
   described), or should the existing per-room "Expand photos/Collapse photos" button be removed entirely
   once the new global toggle exists (my recommendation, to avoid two controls that sound like they do
   the same thing but don't)?
2. **§27.3** — three-way single-select (All/Needs attention/Not yet recorded), confirmed as your own
   instinct — proceeding on this unless you say otherwise.
3. **§27.3** — a room that the filter empties out: hide its top-nav tab entirely, or grey it with a `0`
   count? (I lean grey-with-count, so the agent always sees every real room and never wonders where one
   went; open either way.)
4. **§27.1.2** — when photos are shown (`photosVisible = true`), should the per-item 4-thumbnail cap
   (`itemStripExpanded`) stay as a second, separate density control, or should "show photos" always mean
   "show all of them" and the 4-cap distinction be dropped? (Dropping it is one fewer control for the
   agent to learn; keeping it preserves today's shipped behaviour for anyone already used to it.)
5. **§27.4** — is `needs_attention` (my proposed name) the right label for the new per-condition-state
   flag, or does Johan want different wording on the settings screen itself (this is copy, not
   engineering, and belongs to him per CLAUDE.md §8)?

### 27.7 What's remembered per user vs. resets every visit — the honest current state, and the proposal

**Nothing on this screen is remembered per USER today — only per BROWSER, and only for one of the three
controls in play.** Confirmed by reading the code, not assumed:

| Control | Persisted? | Mechanism | Scope |
|---|---|---|---|
| Per-item photo-strip expand (`itemStripExpanded`) | **Yes** | `localStorage('hfc.inspStripExpanded')` (`show.blade.php:7360-7362`, restored in `init()` `:7367-7371`) | This browser/device only — not the CoreX user account. Explicitly noted in the code's own comment (`:7339-7341`): "No per-user preference endpoint exists for this screen." |
| Room open/collapsed override (`roomOpenOverride`) | **No** | In-memory Alpine state only | Resets to the computed default (open unless fully recorded, §20.7) on every page load |
| Sidebar collapse on this tab (`sbCollapsed`, §20.14.5) | **Yes** | `localStorage('hfc.propSidebar.collapsed')` | This browser/device only, same caveat as above |

**Proposal for the three new controls in this section** — follow the existing precedent exactly rather
than inventing a heavier mechanism: `photosVisible` and `filterMode` persist to `localStorage`
(`hfc.inspPhotosVisible`, `hfc.inspFilterMode`), same as `itemStripExpanded`/`sbCollapsed` already do;
the selected room tab does NOT persist (resets to whichever room is first/open on every visit, matching
`roomOpenOverride`'s own reset-on-reload behaviour) — a remembered room selection from a DIFFERENT
inspection or property would be actively wrong the next time this screen opens, which is exactly the
"filter persists and hides most of the screen next time, with no obvious reason" bug the task warned
against. **Named as a limitation, not silently treated as solved**: because this is `localStorage`, it
is per-device, not per-CoreX-user — an agent recording on their phone and reviewing on a desktop sees the
defaults on each, not a synced preference. Building real per-user persistence (a settings row, an API
round-trip) is a materially bigger change than anything else in this section and is not proposed here;
flagged so Johan can decide if the localStorage answer is good enough or if this needs to go on a
separate ticket.

### 27.8 Out of scope for this investigation

No code was written. Nothing in `resources/views/corex/properties/show.blade.php` — on either `Staging`
or `QA1` — was edited; the two lanes Johan said are inside that file were not touched or blocked (this
investigation only ever ran `git show origin/QA1:<path>` into a scratch file, never `git checkout`, never
a working-tree edit). Printable-form/OMR interaction with this filter, and any mobile-app/API surface for
these three controls (the checklist-must-be-fetched principle, §14.3, would apply identically if this
ever reaches the app) are both unconsidered here — named, not silently assumed out.

### 27.9 Built, 2026-09-27 — room chips restyled to the inventory pattern, everything on one line

Johan, property 5294, live-testing after §27.1's original ship: the room links read as "a row of plain
grey text room tabs (.cv-space-tab) + Photos: shown on one line, then the filter on a SECOND line below."
Asked for the inventory capture screen's own room-chip look (`resources/views/corex/rental-inventories/
capture.blade.php`) reused verbatim, and everything — chip strip, Photos: shown, the three filter buttons
— collapsed onto one line.

**Chips** — copied the inventory screen's exact markup shape (`flex items-center gap-1.5 text-xs
font-semibold px-3 py-1.5 rounded-full shrink-0` pill + an 8×8 `rounded-full` status dot + label), NOT its
`.cv-space-tab`/`-active` underline styling (unchanged, still used by the separate Compare viewer
elsewhere on this same page — not touched here). The "0/5" recorded count stays inside the chip, exactly
as before. The dot's colour is derived from data already printed next to it, not new tracked state:
red (`#D9534F`) if `roomHasAttentionItem()`, brand-cyan (`#3FC9E6`) if fully recorded
(`recorded >= total && total > 0`), else muted grey. Inventory's own chip has a genuine "active/selected
room" state (its screen shows one room panel at a time); inspections has no equivalent — every room
renders simultaneously and a chip click only scrolls to it (`scrollToInspectionRoom()`, unchanged) — so
there is deliberately no "filled = active" chip state here. Named as a scoping decision, not a silent
omission: adding one would mean tracking which room is currently scrolled-to, a new piece of state
Johan's own "behaviour unchanged" instruction ruled out for this pass.

**One line** — outer row: `flex items-center gap-2 flex-wrap lg:flex-nowrap` (same 1024px breakpoint as
§28.2). Chip strip: `flex-1 min-w-0 overflow-x-auto` — it absorbs the row and scrolls internally (native
browser scrollbar, visible by default; nothing in this codebase's CSS hides it) before yielding to the
two fixed-width controls, same "flexible content area, fixed-width controls stay put" pattern §28.2 used
for the Inspection header's own controls. Photos: shown/hidden (`compare-viewer-mode-btn`, unchanged) and
the three filter buttons (`compare-viewer-mode-btn` each) both `flex-none`.

**Segmented filter** — All / Needs attention / Not yet recorded now render inside one bordered,
`overflow:hidden`, `border-radius:6px` wrapper (`.rir-seg-group`) with `.rir-seg-btn` stripping each
button's own individual border/radius down to a shared right-hand divider (`border-right:1px solid
#1E262F`, none on the last child) — reads as one compact control instead of three separate pill buttons.
New rule, combined-selector (`.rir-seg-btn.compare-viewer-mode-btn`) so it reliably beats that class's own
single-class border declaration regardless of which `<style>` block the browser applies first. Click
handlers (`setFilterMode()`) and persistence (§27.7/`RentalInspectionScreenPreference`) unchanged.

**Verified**: `php -l` clean. Whole-app `php artisan view:cache` clean. Real authenticated render + click,
property 5792, user 365 (fixture, never Johan's own live QA1 session on 5294), qatesting1.corexos.co.za —
see this feature's own landing commit for the exact pixel measurements (all nav controls' vertical
band at 1440px, chip-click-still-scrolls confirmation, and a sub-1024px wrap check).

---

## 28. Inspections tab section-header layout — chevron left, one line at desktop (2026-09-27)

Johan, property 5792, measuring the real screen: the "Inspection" section header — `button.prop-section-
toggle` (title + chain-tail status + chevron) beside a controls row (Auto-pair, "Next inspection:" label,
type select, Start) — wrapped into "a shitty like 2 and a half lines... its wasted space and the collapse
arrow sits uncomfortably in the middle of it all." Root cause: the header row (`flex items-center flex-
wrap`) and both controls groups (each its own `flex items-center gap-2 flex-wrap`) all allowed wrapping,
and the toggle button's own `flex:1 1 auto` let it consume the whole row width before any control got a
chance to sit beside it.

### 28.1 Fix — one new CSS modifier, never the shared base classes

`.prop-section-toggle`/`.prop-section-chevron`/`.prop-section-heading` (`resources/css/corex.css`) are used
on FOUR other section headers on this same property page (Identity, Pricing & Costs, Property Details,
Mandate & Assignment — all on the Info tab) and by a completely different screen,
`resources/views/corex/rental-inventories/partials/_related-inventories.blade.php`. Johan's ask was scoped
to "this tab" — editing the base classes would have silently changed those five untouched surfaces too.
Fixed with one new, additive modifier class instead:

```css
.prop-section-toggle-chevron-left { flex-direction: row-reverse; }
.prop-section-toggle-chevron-left .prop-section-chevron { margin-left: 0; margin-right: 0.5rem; }
```

`flex-direction: row-reverse` flips the toggle button's two DOM children (`h3.prop-section-heading` then
`svg.prop-section-chevron`) into chevron-first visual order with no HTML reordering needed; the base
`.prop-section-chevron` rule's `margin-left: auto` (which pushes it flush right in the ORIGINAL row
direction) is overridden back to a small fixed `margin-right` gap, since CSS margins are physical and
don't reverse with `flex-direction`. Applied to exactly the three headers on the Inspections tab that use
this component: **Inspection Items**, **Inspection** (the reported one), and the **custom photo sections**
(`x-for="sec in data.custom"`, real, agency-named sections — this is what Johan saw as "Ad Hoc"/"test
inspection": his own real custom-section names on property 5792, not separate hardcoded labels).

### 28.2 The "Inspection" header itself — nowrap at desktop, controls right-aligned

- Outer row: `flex items-center flex-wrap` → `flex items-center gap-2 flex-wrap lg:flex-nowrap` (Tailwind's
  default `lg` breakpoint is 1024px, matching the ask exactly — wrapping stays allowed below it).
- Both controls groups (Auto-pair; Next inspection/select/Start): added `flex-none lg:flex-nowrap`, so
  neither shrinks to fight the title for space nor drops its own children (the select/Start pair) onto a
  second line at desktop widths.
- Toggle button already had `flex:1 1 auto; min-width:0` (an inline override, pre-existing) so it fills
  the middle and yields space to the controls first when the row is tight — this is exactly why the
  controls read as right-aligned with no `margin-left:auto`/`justify-content` needed: the flexible title
  area absorbs the row, the fixed-width controls sit at its natural end.
- Row-height fix, found only once wrapping stopped exposing it: both controls' wrapping `<div>`s carried
  their own `py-1.5` AROUND a button/select that already had its own `py-1.5` — invisible while broken
  across 2.5 lines, but once single-line, this doubled padding made the controls row 42–46px tall against
  the toggle button's 28px, an uneven header. Removed the wrapper `py-1.5` (kept `pr-3`); height now comes
  from each control's own padding only, same as the toggle button's.

### 28.3 Verified

`php -l` clean. Whole-app `php artisan view:cache` clean. Real authenticated fetch (isolated worktree,
own throwaway `hfc_dash_test_*` schema — never the shared QA1 database, per Rule 18) +
`scripts/verify-alpine-render.mjs`: 2031 Alpine attribute expressions, zero new syntax errors, identical
pre-existing `form.getAttribute`/scope-gap warning set before and after (confirmed via `git stash` on
`show.blade.php`/`corex.css` and a byte-for-byte diff of the console-error list). Real headless-Chrome
measurement at 1440px viewport, using a seeded chain (completed In-inspection predecessor + a Routine
tail with all 28 items recorded — the exact `Routine — draft · 28/28` text Johan's own screen showed):
chevron confirmed left of the title text; all three header children's vertical positions land within a
3px band (one line, not wrapped) at a measured row height of **35px** (target "~36px"). Four real Puppeteer
clicks (CDP-dispatched, not synthetic): title toggles the section, chevron toggles the section, Auto-pair
does NOT toggle it, the Next-inspection select does NOT toggle it — all four pass.

### 28.4 Regression fix, 2026-09-27 — §28.1's own verification only checked the vertical band

§28.3's headless measurement proved chevron-left-of-title and a one-line row, but never checked
HORIZONTAL position — and on Johan's real browser, `/corex/properties/5294?tab=inspections`, chevron+title
were pushed hard against the button's RIGHT edge, not the left: **Inspection Items (54)** button
`left=350 width=1126`, heading `left=1314` (≈960px of empty space to the LEFT of the title, inside the
button); **Inspection** header heading `left=937` in a `781px`-wide button (same symptom).

**Root cause**: `flex-direction: row-reverse` alone only reverses which DOM child renders first — it does
NOT move the packed group's position. `justify-content` was left at its default (`flex-start`), which
packs the group against the main-axis **start**; under `row-reverse`, main-start is the PHYSICAL RIGHT
edge, not the left. So both children packed flush right, exactly as measured.

**Fix — one property added, nothing else touched:**

```css
.prop-section-toggle-chevron-left { flex-direction: row-reverse; justify-content: flex-end; }
```

Under `row-reverse`, main-**end** is the physical left, so `justify-content: flex-end` packs the
(visually-reordered) chevron+title group flush against the button's left edge — the look Johan asked for
in §28.1, actually achieved this time. `.prop-section-toggle-chevron-left .prop-section-chevron`'s existing
`margin-left:0; margin-right:0.5rem` is unchanged; the outer controls row (§28.2, the `Auto-pair`/`Next
inspection` siblings outside the button) is unaffected — that alignment comes from the OUTER row's own
flex layout, not this button's internal `justify-content`.

**Verified**: `php -l` clean on `resources/css/corex.css`. Applies uniformly to all three headers using
this modifier (Inspection Items, Inspection, custom/"Ad Hoc" sections) since the fix lives in the shared
CSS rule, not per-header markup. Real-render horizontal measurement (heading.left − button.left) recorded
per header in the landing commit for this fix — see that commit's own report for the exact pixel deltas
on property 5792 at 1440px and 1024px.

---

## 29. Untagged-photo tray — Small/Large tile-size toggle (2026-09-27)

Johan, property 5294, live-testing: "I don't mind the small thumbnails, but can't really see them. Keep
them this size and have a resize option or tick to enlarge them to the same size as the thumbnail photos
in the room/item sections (e.g. Kitchen)."

### 29.1 Sizes

- **Small (default)** — unchanged: `.rir-tray-tile`, `3rem` (48px) square
  (`rental-inspection-recording.blade.php`).
- **Large** — a new `.rir-tray-tile-lg` modifier, `86px × 64px` — read directly from `.rir-strip-tile`
  (`rental-inspection-item-cell.blade.php`, the room/item comparison-grid photo tile Johan pointed at,
  e.g. Kitchen), not guessed, so Large is a pixel-for-pixel match to what an agent already sees on every
  item's own photo strip.

### 29.2 Control

A `Small`/`Large` segmented toggle on the untagged tray's own header line, grouped directly beside the
"N untagged" count (not a separate `justify-between` child stranded at the row's far edge) — same
`compare-viewer-mode-btn`/`-active` styling already used by the "Photos: shown/hidden" and filter
buttons on this screen (§27), so it reads as the same family of control. Remove (×) and click-to-tag
behaviour are byte-for-byte unchanged in both sizes — the toggle only ever adds/removes one CSS class
(`:class="trayTileSize === 'large' ? 'rir-tray-tile-lg' : ''"`) on the existing tile `<div>`; the earlier
`:style`-wholesale-overwrite bug documented at the top of this file (2026-09-22) is why size is a `:class`
addition here, never folded into the tile's existing `:style` binding.

### 29.3 Persistence

Per-user, server-side — the same `RentalInspectionScreenPreference` mechanism as `photos_visible`/
`filter_mode` (§27.7), not a third mechanism and not `localStorage` (an agent's tray-size preference
follows them from laptop to phone, same reasoning as the other two). New preference key
`tray_tile_size`, default `'small'`, values `'small'|'large'` — an unrecognised stored/posted value
clamps to `'small'` (`RentalInspectionRecordingController::updateScreenPreference()`), same
clamp-not-reject pattern already used for `filter_mode`.

### 29.4 Verified

`php -l` clean on all three changed PHP files. `RentalInspectionRecordingControllerTest.php` — 8/8
passing (18 assertions): default-state payload now includes `tray_tile_size => 'small'`; posting
`large` persists and round-trips through the next tab-data fetch without disturbing the other two
preferences; an unknown value (`'huge'`) clamps to `'small'`; the existing unknown-key-rejected and
per-user-scoping tests are unaffected. Real-render tile measurement (48×48 in Small, 86×64 in Large)
recorded in the landing commit's own report.

---

## 30. Photo strip collapsing to 0×0 — first inspection, no predecessor (2026-09-27)

Johan, property 5294, inspection 33 (real move-in, 32 real photos, all tagged to real observations —
data confirmed correct in every way): every `.rir-strip-row` measured `clientHeight: 0` (`scrollHeight`
64, tiles 86×64) — the photos were never missing, the box holding them was invisible.

### 30.1 Root cause

`.rir-strip-row` (`rental-inspection-recording.blade.php`) is `position:absolute; top:0; left:0; right:0;
bottom:0; height:100%`. An absolutely-positioned element contributes NOTHING to its containing block's
own auto-height calculation — CSS spec, not a bug in Chromium. Its containing block
(`rental-inspection-item-cell.blade.php`'s own wrapper, `display:block; flex:1; align-self:stretch;
min-width:0; min-height:0; position:relative;`) therefore had no normal-flow content of its own to size
against; its real height came entirely from being cross-axis-stretched (`align-items:stretch`) by
whatever ELSE was in that row. `min-height:0` on both wrapper divs explicitly zeroed out the one thing
that could have put a floor under that — so whenever the stretch context didn't hand it a real height
(reproduced on a freshly-seeded fixture, property 21034/inspection 34: `type='in'`,
`previous_inspection_id NULL`, 2 items, 3 tagged photos — same shape as 5294/33), the strip and every
ancestor up to the compare-row measured 0×0, and the tiles inside clipped to nothing despite being real,
correctly-tagged, correctly-loaded images.

Checked via `git log -L` on both the wrapper line and `.rir-strip-row`'s own CSS rule: neither has ever
had any OTHER value — this shipped this way from AT-433 Part A's first commit (`7b42a167f`) and the
shared item-cell partial's own first commit (`73d8969cb`). Not a regression from a later change; a
latent design gap that had simply never been hit by this exact combination (first inspection, heavily
tagged, room reopened) until now.

### 30.2 Fix — a real min-height floor, not a redesign

```css
/* was: min-height:0 on both divs */
min-height:4rem;   /* = .rir-strip-tile's own height (64px), read from that class, not guessed */
```

Applied to BOTH wrapper divs (the outer `display:flex` one and the inner `position:relative` one) in
`rental-inspection-item-cell.blade.php`. A `min-height` is an unconditional floor — it holds regardless
of whether a sibling column happens to be taller this time, so it protects every layout that shares this
one partial: first inspection (no predecessor), compare-with-predecessor, and (per the partial's own
`$readOnly` toggle) both the read-only and live/editable cells. Tile size, absolute positioning, overflow
behaviour, and the already-safe room-level strip (`rir-room-photo-tile`, real CSS Grid, never had this
bug) are all unchanged.

### 30.3 Verified

`php -l` clean. Whole-app `php artisan view:cache` clean. Fixture built as user 365 (Tinker, real Eloquent
creates — not raw SQL) on property 21034 ("12 Fixture Lane", agency 1, previously empty — never 5294,
never 5792, never user 22): a lease, one room, two items, one `type='in'` inspection with
`previous_inspection_id NULL`, three photos tagged to its observations (reusing an existing on-disk image
file, no new upload). `listing_type` had to be corrected to `'rental'` on this fixture — the Inspections
tab doesn't render at all otherwise, a real gap in the repro, not the bug under investigation. Real
authenticated Puppeteer measurement, qatesting1.corexos.co.za, user 365 (never Johan's own live user 22
on 5294): **before the fix**, every `.rir-strip-row` on this fixture measured `0×0`, matching Johan's own
report exactly. **After the fix**, re-measured on the same fixture plus, separately, on property 5792's
own real chain (inspection 32 tail / inspection 29 predecessor, predecessor has real tagged photos) to
confirm the already-working compare-layout did not regress — see this fix's own landing commit for the
exact pixel heights recorded on both.

---

## 31. Nav-row control height/colour mismatch (2026-09-27)

Johan, property 5294: "you're killing my OCD. make the buttons the same, that's just off." Room pills
(§27.9, light `var(--surface-2)`, `rounded-full`) and Photos:shown/the filter (dark `.compare-viewer-
mode-btn` family, `#10151B`/`#3FC9E6`, `rounded-md`) were two different visual languages, AND the pill
strip's own native horizontal scrollbar (an `overflow-x:auto` box with `height:auto` grows to fit its
scrollbar BELOW its content — standard browser behaviour) added height to only that one child, so the
row's `items-center` centred the shorter Photos/filter buttons on a different line than the taller
pill-strip box.

**Fix**: one shared class family, `.rir-nav-pill`/`.rir-nav-pill-active` (light inactive matching the
pills, filled `var(--brand-button)`/white active matching the inventory screen's own active-chip look),
applied to the room pills, Photos:shown, and every segmented-filter button — scoped to this row only,
`.compare-viewer-mode-btn` itself untouched everywhere else it's used on this page. Every control gets an
EXPLICIT `height:2.75rem` (not a `min-height` floor), and the outer row switches `items-center` →
`items-start`: since every control starts at the same top edge and is the same explicit height, their
centres coincide without depending on the scrollbar's extra height at all — the scrollbar now simply
extends below that shared line, in its own space, exactly as asked. `border-radius` unified to
`rounded-full`/`9999px` (Photos button, the segmented group) to match the pills' own family. The
segmented buttons get `height:auto` (not the shared `2.75rem`) specifically so `align-items:stretch` fills
the group's own bordered interior exactly — an explicit height there would overflow the group's own
`box-sizing:border-box` 1px inset by 2px.

**Verified**: `php -l` clean. Whole-app `php artisan view:cache` clean. Real authenticated Puppeteer
measurement, qatesting1.corexos.co.za, property 5792, user 365 (never Johan's own live user 22 on 5294):
every control's own height and centre-Y recorded in this fix's landing commit.

### 31.1 Splitter between the pill strip and Photos:shown (2026-09-27, approved)

Johan, after §31 landed: "we just need some form of splitter to split the buttons from the spaces. it
sort of looks cut" — the pill strip ended flush against Photos:shown with the last pill visibly
truncated by the scroll wrapper's own edge.

Two additive pieces, heights/centres of every control unchanged (still `2.75rem`, still one shared
top-aligned line per §31):

- **Edge fade** — a `pointer-events:none`, `aria-hidden` 24px-wide `linear-gradient(to right,
  transparent, var(--surface))` overlay, absolutely positioned over the pill wrapper's own right edge
  (`position:relative` added to that wrapper for the anchor), height `2.75rem`/`top:0` — matches the pill
  band only, never the taller scrollbar-reserved space below it. An overflowing pill now fades into the
  panel background instead of being hard-clipped.
- **Divider** — a `1px` × `28px` `var(--border)` line between the pill strip and Photos:shown,
  `margin:8px 4px 0` (the `8px` top-offset centres its 28px within the shared 44px band using the SAME
  top-anchored technique §31 already established — `align-self:center` would have centred it against the
  pill wrapper's own taller, scrollbar-inclusive line box instead, landing it off-centre again; the `4px`
  side margins plus the row's own existing `gap-2` (8px) total the ~12px gap either side Johan asked for).

**Verified**: `php -l` clean, whole-app `view:cache` clean. Real authenticated measurement, qatesting1,
property 5792, user 365: divider position relative to the pill strip's right edge and Photos:shown's left
edge, plus confirmation every control is still `2.75rem` tall with centres aligned, recorded in this
fix's own landing commit.

## 32. Compare-row duplicate rendering + nav-bar not staying on screen (2026-09-28, Johan, property 5294)

Two bugs Johan found on property 5294's Inspection panel, on a chain's first inspection ("in", no
predecessor). Both fixed in `rental-inspection-recording.blade.php` only.

### 32.1 Every item appeared to render twice — the predecessor cell must not appear with nothing to compare

**Root cause, part one (why the layout looked stacked, not side-by-side).** `.rir-compare-row` (the
shared room/item comparison grid, §26/`rir-compare-row`/`rir-compare-cell`) declared its
`display:grid; grid-template-columns:1fr 1fr` **inline**, on the same element as
`x-show="itemMatchesFilter(...)"`. Alpine's `x-show` toggles visibility via
`el.style.setProperty('display','none')` on hide and `el.style.removeProperty('display')` on show —
the show path does not restore whatever custom `display` value was inline before, it deletes the
`display` entry outright, and per Alpine's own `once()`-gated toggle this fires even on the very FIRST
evaluation when a row starts visible (the normal case, since the default filter matches everything). A
bare `<div>` then falls back to the UA default `display:block`, and the two `.rir-compare-cell` children
stack instead of sitting side by side. **Fix:** the grid declaration moved into a real CSS class
(`.rir-compare-row` in this file's own `<style>` block) — a class-based rule is never touched by
`x-show`, which only ever mutates the element's own INLINE `style.display`. Verified live (Puppeteer,
real QA1 data, property 5792 — a chain WITH a predecessor): rows render `display:grid`, two
non-overlapping 496px-wide cells side by side, and stay that way through a filter toggle
(Needs-attention → All), the exact hide/show cycle that broke it before.

**Root cause, part two (why the SAME item appeared twice).** The row unconditionally rendered a
predecessor cell (item label + a greyed, disabled copy of the condition buttons, via
`rental-inspection-item-cell.blade.php`'s `readOnly=true` branch) even when `$predecessorJs`
(`chainPredecessor`) is null — a deliberate 2026-09-23 design (property 5792, this file's own docblock):
"a null predecessor renders every item's cell as its own empty state, row by row." Correct when a
predecessor inspection EXISTS but hasn't recorded a given item; wrong for the chain's first inspection,
where there is no predecessor at all — the header directly above the grid already special-cased this
(`!$predecessorJs` → "First inspection in this chain — nothing yet to compare against"), the item rows
never did. **Fix:** the predecessor cell is now wrapped in `<template x-if="{{ $predecessorJs }}">`, the
same gate the header already uses. No predecessor at all → only the tail cell renders, full width
(`.rir-compare-row-solo`, `display:block`, one column). Predecessor exists but this item has nothing
recorded on it → unchanged, still an empty grey cell (still a meaningful signal — the existing spec's
own reasoning at line ~44 of this file's own docblock stands for that case).

**Verified**: `php -l` clean, Blade compile-string clean. Real authenticated headless Chrome, real QA1
data: property 5792 (predecessor exists, 6-inspection chain) — two-cell grid, both cells populated,
496px each, confirmed with a filter-toggle regression pass; property 5577 (chain's first inspection, no
predecessor) — one-cell, full-width (1008px), `.rir-compare-row-solo` applied, confirmed across all 81
items and after the same filter-toggle pass. Neither is property 5294; verification ran as user 365
(`qa1-browser-verify@corexos.local`), never user 22.

### 32.2 Room nav bar (room pills + Photos:shown + All/Needs attention/Not yet recorded) not staying on screen

Johan: clicking a room lower down (e.g. Garden) left the user scrolled past the nav bar, with no way to
pick the next room without scrolling all the way back up.

**Why plain `position:sticky` — the pattern already proven elsewhere on this same page
(`_property-shell-tabs.blade.php`'s own tab bar, `show.blade.php:3868`'s gallery tag bar) — does NOT
work here.** Checked live, real QA1 data (property 5792): this nav bar lives inside the "Inspection"
collapsible card (`show.blade.php:4903`, class `.prop-section`), and `.prop-section` itself has
`overflow:hidden` (`corex.css:608`) — there purely to clip the card's own rounded corners, shared by
every collapsible section on the properties page (Identity/Pricing/Mandate/Items/Inspection alike), so
not this feature's to change. An `overflow:hidden` ancestor sitting between a `position:sticky` element
and its real scrolling ancestor (`.prop-tab-panel`) becomes the sticky element's containing block
instead — and since `.prop-section` itself never scrolls, a sticky descendant of it just scrolls away
with the card. Measured directly: scrolling `.prop-tab-panel` moved a plain-sticky nav bar by the exact
same distance as the scroll (fully unpinned), while the actual (shell) tab bar — which has no such
ancestor — stayed correctly fixed throughout.

**Fix:** a small, self-contained `x-init` scroll listener on the nav bar itself (vanilla JS, not a new
method on `show.blade.php`'s `rentalImages()` factory — this is a presentation-only concern of one bar,
kept local to this partial). It watches `.prop-tab-panel`'s scroll position and switches the bar to
`position:fixed` (confirmed no ancestor sets `transform`/`filter`/`contain`, so `fixed` escapes the
`overflow:hidden` clipping that defeats `sticky`) once its natural position would scroll above the
shared tab bar's own bottom edge, computing `top`/`left`/`width` from `.prop-tab-panel`'s live
`getBoundingClientRect()` so it reads as pinned directly under the tab bar, same width as the tab
content — and back to normal flow once scrolled back above the pin point. A same-height placeholder
(inserted once, at init) keeps the layout from jumping while pinned.

Each room heading (`rental-inspection-recording.blade.php`'s `insp-room-{ro|rw}-*` anchors, the same
elements `scrollToInspectionRoom()`'s `scrollIntoView({block:'start'})` targets) got a
`.rir-room-anchor { scroll-margin-top: 115px }` (tab-bar height 55px + nav-bar height 52px + a few px
breathing room, both measured live, never guessed from a spec sheet) so a clicked room's heading now
stops just under the pinned bars instead of landing hidden behind them.

**Verified**: real headless Chrome, real QA1 data, property 5792 and property 5577 (never 5294), user
365 only. Nav bar measured pinned (`top` tracking the tab bar's own bottom edge exactly, zero overlap)
at `scrollTop` 0, 1500, and scrolled to the very bottom of a 5600px/16600px-tall panel on the two test
properties respectively. Deep-scroll room-click test: starting at `scrollTop 14907`, clicking a room
chip near the top of the list moved the panel to `scrollTop 2536` (a real ~12,371px scroll), and the nav
bar was still correctly pinned with zero overlap immediately after.

### 32.3 Round 2 (2026-09-28) — three real defects in round 1's nav-bar pin, found by Johan in real
Chrome at 1522×784

Round 1 shipped (`650cd2cce`) and Johan checked it in real Chrome. The compare-row duplicate fix (§32.1)
held. The nav-bar pin (§32.2) had three real defects, all traced back to gaps in round 1's own
verification — none of round 1's Puppeteer checks actually exercised the failure conditions below.

**1. Room clicks landing at the wrong place.** Root cause, in two layers:

- `.rir-insp-nav-sticky` sits inside `.prop-section-body.space-y-3` (§32.2's include), and Tailwind's
  `space-y-3` puts a real `margin-top:0.75rem` (12px) on it as a normal-flow sibling.
  `getBoundingClientRect()`/`offsetHeight` never include an element's own margin — switching to
  `position:fixed` without explicitly zeroing that margin left it applying on top of the computed `top:`
  value (the "gap" defect, #3 below), AND the placeholder — sized from `bar.offsetHeight` alone, margin
  excluded — reserved 12px LESS flow height than the bar actually consumed before pinning, shrinking the
  panel's total scrollable height by 12px at the exact moment of pinning, out from under any smooth
  `scrollIntoView` animation already in flight. Fixed: read the bar's real computed `margin-top` once
  (never hardcode the Tailwind value), fold it into the placeholder's reserved height so total flow
  height is identical before and after pinning, and zero the bar's own inline `margin-top` while fixed.
- Independently, `--rir-room-anchor-offset` (the CSS custom property `.rir-room-anchor`'s
  `scroll-margin-top` reads, feeding `scrollToInspectionRoom()`'s `scrollIntoView`) was computed ONCE,
  at this bar's own `x-init` (component mount) time, via a `ResizeObserver` on the theory that "panel
  offset and bar height are scroll-invariant." True for the panel and the bar — **false for the shared
  property-shell tab bar** (`_property-shell-tabs.blade.php`, `position:sticky; top:0`): its
  `getBoundingClientRect().bottom` only reports its real, STUCK position once the page has scrolled at
  least once and that sticky has engaged. Before the first-ever scroll it reports its natural,
  pre-stick document position instead — measured live, property 5577, narrow 768px viewport: 382.6px
  vs its real stuck 253.6px, a 129px difference. A one-shot measurement at mount time permanently baked
  in the wrong (pre-stick) value, breaking exactly the FIRST room click on a freshly-opened section —
  confirmed live: clicking the very first room chip on a fresh page landed 141px too low; every click
  after that (once the page had scrolled at all) landed correctly, which is exactly why round 1's own
  tests — none of which clicked a room as literally the first interaction on a truly fresh page load
  without any other scrolling first — never caught it. Fixed: `updateAnchorOffset()` now runs as part of
  `update()` itself (already firing on every settled scroll), so the offset self-corrects the moment the
  tab bar's own sticky engages, before `scrollToInspectionRoom()` is ever given a chance to use a stale
  value.
- A third, narrower issue surfaced and was ruled out during this work: the *very first* `update()` call
  (before the "Inspection" section has ever been expanded) reads `bar.offsetHeight === 0`, since its
  `x-collapse`'d ancestor has no layout box yet. A bounded `requestAnimationFrame` retry loop was tried
  first and rejected — it gives up long before the agent ever opens the section, permanently baking in a
  wrong value since nothing else ever recomputes it. Replaced with the `ResizeObserver` approach
  described above (§32.2), which reacts exactly when a real measurement becomes possible.
- One more real, timing-only race was found and fixed along the way: the original `update()` — even after
  the margin fix — still ran on every raw `scroll` event, computing `getBoundingClientRect()` on every
  call. During a native `scrollIntoView({behavior:'smooth'})` animation (dozens of scroll events per
  second) this measurably perturbed the browser's own scroll-completion timing — confirmed by observing
  that adding `console.log` instrumentation (which slows the handler down) made a previously-reproducing
  failure stop reproducing, the signature of a genuine race rather than a logic bug (traced values showed
  the threshold math itself was always correct). Fixed by debouncing `update()` — waiting for scroll
  events to go quiet for 80ms before running — rather than throttling it to run continuously during the
  scroll. A `scrollIntoView` animation fires a steady, gapless stream of scroll events for its whole
  duration, so it now gets zero layout writes from this bar until it finishes; a real mouse-wheel/
  scrollbar-drag scroll has small natural gaps, so the bar still visually snaps to pinned/unpinned within
  about one frame of the user pausing.

A fourth theory — "a room near the very end of a long list doesn't have enough trailing content for the
browser to scroll it all the way up" — was tested (a trailing spacer sized to `--rir-room-anchor-offset`
was added) and **disproven**: with the real bugs above fixed and an adequate wait for the (now correctly
un-interfered-with) native smooth-scroll to actually finish, every room — including the last one on a
16-room property — landed correctly with no spacer at all. The spacer was removed; it would have shipped
a fix for a problem that didn't exist, on the strength of a test harness that simply hadn't waited long
enough for a ~15,000px animated scroll to complete (Chrome's `scrollIntoView({behavior:'smooth'})`
duration scales with distance).

**2. Bar width wider than the card.** `applyPinnedGeometry()` computed `left`/`width` from
`.prop-tab-panel`'s own `getBoundingClientRect()` — the panel sits OUTSIDE this card's own padding
(`p-6` on the Inspections-tab wrapper, plus the card's own inset), so the bar spanned wider than the
visible card on both sides (measured: 322→1497 against the card's real 349→1477). Fixed: measure
`.prop-section` (the card itself, via `bar.closest('.prop-section')`) instead of `.prop-tab-panel`.

**3. Gap above the bar showing scrolled content through it.** Direct consequence of the unaddressed
`margin-top` in defect #1 — the bar's background started 12px below where `top:` said it would. Same
fix as #1 resolves this: `margin-top:0` while pinned.

**Verified, round 2**: `php -l` + Blade compile-string clean. Real headless Chrome, real QA1 data,
property 5577 (16 real rooms — Bedroom 1-4, Bathroom 1-3, Garage 1-2, Parking 1-3, Study 1, Kitchen 1,
Garden 1, Pool 1 — chosen specifically to exercise adjacent AND far-apart clicks, never 5294), user 365
only, at both 1522×784 and a narrow 768×800:
- Width/gap: `gap: 0`, bar left/right matching the card's own left/right exactly, at both viewports.
- Every ordered pair tested — adjacent rooms (Bedroom 1→2, Bathroom 1→2, Garden 1→Pool 1), first↔last
  (Bedroom 1↔Pool 1, Bedroom 4→Pool 1), and reverse (Pool 1→Bedroom 1) — landed the target room's own
  heading flush under the pinned bar (within ~13px), at both viewports, confirmed across multiple
  repeated runs (not a one-off pass).
- A deep-scroll case: starting at `scrollTop 14907`, clicking a room chip near the top of the list moved
  the panel to `scrollTop 2536` (a real ~12,371px animated scroll) and landed correctly.

### 32.4 Autosave data loss — an agent's own typing silently overwritten (2026-09-28, Johan, property 5294)

Johan, recording on 5294's In inspection: "it's like a refresh happens and some of what he entered is
lost" while working down the page. Investigated and fixed as a bug **class**, not a single site.

**The rule, going forward, for every autosave in this codebase:** an autosave's response may only ever
update the fields its own request sent — never the whole object. If a field the request didn't send can
be safely applied unconditionally (nothing else writes to it — a server-computed lifecycle
status/timestamp, say), do that. If a field IS something the agent can be actively typing into via a
live `x-model` (free text, a reading, a count), capture a snapshot of it at send time and only apply the
server's value if the local value is still EXACTLY what was captured — if it changed locally in the
meantime (still typing, or a newer save already in flight), the newer local edit wins and the stale
response is dropped for that field. Two shared helpers do this: `_mergeIfUnchanged(insp, snapshot,
updated, fields)` for the first case, `_mergeFields(insp, updated, fields)` for the second
(`show.blade.php`, both just above `itemError:` — §6648 area).

**Root cause.** `RentalInspectionRecordingController::updateDetails()`/`updateOverallNotes()`/
`startAwaitingSignature()`/`complete()` all returned a bare `$rentalInspection->fresh()` — every plain
COLUMN on the row (meter readings, `overall_notes`, keys/remotes, `status`, timestamps — `fresh()` has
no `$with` on the model, so relations like `observations`/`photos`/`roomNotes` are genuinely absent, not
a risk here), no relations. The corresponding JS (`_commitDetails()`/`_commitOverallNotes()`/
`startAwaitingSignature()`/`completeInspection()`, `show.blade.php`) did `Object.assign(insp, updated)`
— the WHOLE snapshot onto the SAME shared `insp` object that the header-block inputs and the Overall
Notes textarea are directly `x-model`-bound to (`rental-inspection-recording.blade.php:323-378`, `~1218`
for the header fields and Overall Notes respectively). Two of these racing — or one landing while the
agent is still mid-keystroke on a field it also carries an older value for — let the slower/stale
response stomp whatever the other one (or the agent's own live typing) had just written.

**Reproduced live, before the fix** (real Chrome, property `20` on QA1, user 365): typed a meter reading,
then immediately typed `"INSPECTION LOOKS FINE OVERALL"` into Overall notes before the meter save's own
response had landed. The text PERSISTED to the database corrupted — not just visually — landing as
`"INE OVERALL"` in one run and `"INSPECTION LOOKS FINE OVERALL FINE OVERALL"` in another (the exact
corruption shape varies with timing, since this is a genuine race, but it was wrong every time).

**Audited every autosave in the inspection recording flow, not just the two named above:**

| Function | Pattern | Verdict |
|---|---|---|
| `_commitDetails()` (meters/keys/remotes/furnished/move-in) | was `Object.assign` | **fixed** — `_mergeIfUnchanged` |
| `_commitOverallNotes()` | was `Object.assign` | **fixed** — `_mergeIfUnchanged` |
| `completeInspection()` | was `Object.assign` | **fixed** — `_mergeFields` (`status`/`completed_at`/`fault_report_deadline_at`, the only fields `RentalInspection::markCompleted()` touches; nothing types into any of the three, so no snapshot needed) — not autosave/debounced (an explicit "Complete" action), but shares the identical anti-pattern and could clobber concurrently-typed overall notes, so fixed alongside the two above |
| `startAwaitingSignature()` | was `Object.assign` | **fixed** — `_mergeFields` (`status`/`signing_deadline_at`, same reasoning) |
| `_commitObservation()` (item condition + notes) | `insp.observations.push(observation)` | already safe — append-only, never overwrites another item's data. Confirmed live: two items in different rooms, condition + notes typed with keystrokes interleaved, both saved correctly, no corruption. |
| `_commitRoomNote()` (per-room notes) | `insp.roomNotes.push(note)` | already safe — same append-only reasoning. Confirmed live, explicitly (Johan: "test room notes"): two different rooms' notes typed with keystrokes interleaved, both saved correctly; a room note typed while a condition click in a DIFFERENT room committed immediately (no debounce) also survived intact. |
| `markRoomNa()` / `markRoomGood()` / `markAllGood()` | `insp.observations.push(...result.observations)` | already safe — same append-only reasoning; none of the three touch any other field on `insp`. |
| `compareViewerSaveNote()` (photo notes) | `photo.note = note` | already safe — writes only the ONE photo object's own `.note`, never touches `insp` at all; also an explicit "Save note" button, not a live-typing autosave. |
| `resolveDiscrepancy()` | `Object.assign(discrepancy, updated)` | reviewed, left as-is — targets a single `discrepancy` object nothing else concurrently writes to (the working form lives in the separate `discForm[id]`, per the pattern §14 of this spec already established); an explicit "Resolve" action, not autosave. Not part of this bug class. |

**Verified, before/after, real Chrome, real QA1 data (property `20`), user 365, never 5294:**
1. Meter reading + Overall notes, typed back to back (the exact repro above) — before: corrupted every
   run (different garbling each time, always wrong); after: exact text saved, 3 consecutive clean runs.
2. Two room notes, different rooms, keystrokes interleaved — passed both before and after (already safe,
   confirmed unchanged by the fix).
3. A room note typed while a condition click in a different room commits (no debounce, immediate) —
   passed both before and after (already safe, confirmed unchanged by the fix).

## 33. Completed inspection shows every item as blank/unrecorded — condition, notes, photos, room notes
all missing despite the data existing (2026-09-28, Johan, property 5294)

Johan recorded and completed 5294's In inspection (header read "In — completed · 59/59"). Every item
rendered as the greyed, disabled button grid with NO condition selected and NO photos — e.g. "Bedroom 1
— 5/5 · 3 photos total" in the room heading, but Ceiling showed no highlighted condition and no photo
tiles. The data genuinely existed (the counts proved it); only the completed/read-only RENDER was wrong.

**Root cause, confirmed pre-existing via `git blame` (`73d8969cb3`, 2026-09-23 — five days before
`650cd2cce`, this file's own predecessor-cell fix from earlier the same day as this section, which never
touched the line in question):** the tail cell's own item-cell include
(`rental-inspection-recording.blade.php`) passed `$sectionJs` — a raw JS expression like `tailSection()`,
evaluating to the STRING `'in'`/`'out'` — as `inspectionJs`, while `readOnly` becomes true once
`$tailReadOnly` is true (completed). But `rental-inspection-item-cell.blade.php`'s own docblock is
explicit: `readOnly=true` means `inspectionJs` MUST be an INSPECTION OBJECT expression — exactly what the
PREDECESSOR cell already passes (`$predecessorJs` / `chainPredecessor`) — read via
`conditionForInspection()`/`itemPhotosForInspection()`, both of which do `insp.observations`/
`insp.photos`. A plain STRING has neither, so both silently returned empty/null for every item on every
completed inspection ever viewed, on this build, since the day it shipped.

**Fix:** `$tailInspectionJs` computed once, PHP-side, right before the item x-for loop — unchanged
(`$sectionJs`) while the tail is still editable (the existing accessors resolve the section internally
and were never broken); `currentInspection($sectionJs)` (resolves to the real `chainTail` object) once
`$tailReadOnly` is true — the exact pattern the predecessor cell already used.

**Room notes were a second, independent gap** — not just non-editable but omitted entirely: the whole
room-notes block sat inside `@unless($tailReadOnly)` with no read-only counterpart at all. Added an
`@if($tailReadOnly)` branch showing the note as plain text (only when one exists — no empty box for a
room nobody wrote anything about), reusing the existing `roomNoteFor()` accessor unchanged (it already
resolves `section` internally, no object/string split needed there).

**A third, genuinely separate bug surfaced while verifying the room-notes fix live, and blocked it
outright until found:** `roomNoteFor()`/`_commitRoomNote()` read/wrote `insp.roomNotes` (camelCase) —
but `RentalInspection::tabPayloadFor()` eager-loads the relation as `roomNotes` (the PHP method name),
and Eloquent's own `toArray()`/JSON serialization snake_cases a multi-word relation key by default. Every
OTHER relation this file reads (`observations`, `photos`, `signatures`, `discrepancies`) is a single
word, so this is the first place that default ever mattered — confirmed directly in the raw page source,
the server payload genuinely carries the data under `"room_notes"`, not `"roomNotes"`. `insp.roomNotes`
was therefore ALWAYS `undefined`, silently, on every load — not a thrown error, just nothing found. This
means the bug was never limited to the completed view: `roomNoteField()` (used to seed the EDITABLE
textarea when a room is first opened) called the same broken `roomNoteFor()`, so an EXISTING room note
never pre-populated on a fresh page load either, in the editable view, since the day room notes shipped
(2026-09-22) — not data loss (the note was always safely in the database, and `_commitRoomNote()` only
ever appends, per §3.2's own "immutable, latest wins" design), but a real "did my note just disappear"
scare, and the direct reason the read-only display added above returned nothing until this was found.
Fixed by renaming all three `insp.roomNotes` references (`show.blade.php`) to `insp.room_notes`, matching
what the server actually sends.

**Verified live**, real headless Chrome, real QA1 data, property `5577`'s inspection `20` taken all the
way through mark-good/refuse-tenant/refuse-landlord/agent-sign/complete as user 365 (never 5294, never
user 22):
- Condition highlighted + note text: two items ("Ceiling"→Damaged→"CRACKED TILE NEAR DOOR",
  "Walls"→Damaged→"STAIN ON CEILING CORNER") both rendered correctly highlighted with their notes visible
  in the completed view, confirmed via DOM inspection of the actual rendered button/text state, not just
  the underlying data.
- Photo tile: a real photo tagged to one of those two items' observations rendered as an actual `<img>`
  tile pointing at its real storage URL in the completed view (test photo removed — soft-deleted —
  immediately after verification, per this project's no-hard-deletes rule; the file itself was written
  under this worktree's own local storage, never touching a real upload).
- Room notes: two different rooms' latest notes ("CEILING FAN LOOSE, RATTLES", "DAMP PATCH UNDER SINK")
  both rendered as plain read-only text in the completed view.
- Regression check: the same property's OTHER, still-draft inspection (editable path, `tailReadOnly`
  false) still accepted a condition click normally after these changes — the `$tailInspectionJs`/
  `room_notes` fixes only ever change behaviour for the completed/read-only branch or correct a
  previously-always-broken read, never the already-working editable path.

**`awaiting_signature` checked, not affected:** this status renders via the SAME fully-editable branch as
draft/in_progress (`show.blade.php`'s own gate is `chainTail.status !== 'completed'`, which covers
`awaiting_signature` too) — items display correctly there via the already-working editable accessors, so
this bug never applied. Whether an inspection should still be editable once `awaiting_signature` (signing
implies the content is finalized) is a real, separate product question, not raised or ruled on here.

**Signed-PDF/report view checked, not affected:** `RentalInspectionReportPdfService` is a wholly separate,
server-side PHP path — no relation to the JS accessor bug above — that pulls `$inspection->observations`
directly via real Eloquent relations. It deliberately excludes photos already, by Johan's own 2026-09-23
design ruling recorded in that file's own docblock ("printing the photos will be a shitshow... the
inspection reports will turn into 100 pages") — a QR/link to the public page carries the photos instead.
Nothing to fix here; this was a pre-existing, deliberate, and correct design.

## 34. Agent signature — reused the existing PIN signature, not a second one (2026-09-28, Johan's ruling)

Johan's ruling: the AGENT's own signature on an inspection must use the SAME PIN signature CoreX already
uses elsewhere for agents, not a separately-built hand-drawn pad — reuse, don't rebuild.

**Found and reused, verbatim:** `resources/views/signature/_placer.blade.php` — the existing, documented,
reusable "place my signature" widget (its own docblock: "Consumed by BOTH e-sign and the CMA certificate
generator") — backed by `App\Services\AgentSignatureService` / `App\Http\Controllers\
AgentSignatureController` (`GET /signature/status`, `POST /signature/unlock`, `GET
/signature/asset/{type}`), which in turn reads `App\Models\AgentSignature` (an agent's own saved
signature/initial images + bcrypt-hashed PIN, set once in My Portal). Nothing new was built — this is the
FIRST real consumer of this component beyond its own documentation.

**What changed, tenant/landlord untouched:** only the AGENT's own signing block
(`rental-inspection-recording.blade.php`) was replaced — the hand-drawn `SignaturePad` canvas + "Save
signature" button became a nested `x-data="signaturePlacer({ context: 'rental-inspection:' + <inspection
id> })"` scope: "Sign with PIN" → `ensureUnlocked()` opens the shared PIN modal → on a correct PIN, the
agent's own decrypted saved-signature image loads and previews → "Confirm & save signature" posts it
through the SAME `saveAgentSignatureFor()`/`_saveDisposition()` endpoint every other party's signature
already goes through (only the SOURCE of the image data-URI changed — where it was `pad.toDataURL(...)`
from a canvas, it is now the already-decrypted image the PIN unlock handed back). Tenant/landlord keep
their existing hand-drawn canvas exactly as-is — they have no CoreX account, no saved signature, no PIN,
so the shared widget genuinely doesn't apply to them; this was never proposed. `context` is scoped
per-inspection (`'rental-inspection:' + id`) so a PIN unlock — and the decrypted image it reveals — can
never leak across two different documents, the same isolation the component's own docblock already
describes for e-sign/CMA.

**Two real, pre-existing bugs found and fixed IN THE SHARED COMPONENT while wiring up its first real
consumer** — both were latent because nothing had actually rendered this component inside a real page
before:

1. `signature/_placer.blade.php`'s own top docblock had a literal Blade comment-open/comment-close token
   pair NESTED inside its own USAGE example text (annotating the `@include` line: "(the PIN modal, inside
   this x-data scope)" was originally written as a real inline comment). Blade's comment stripper is not
   nesting-aware — it regex-matches from the FIRST comment-open token to the very NEXT comment-close
   token it finds anywhere after it — so the OUTER comment actually closed right there, and everything
   below it (a stray `</div>` from the usage example, the "After unlock..." paragraph, this whole block's
   own real closing token) rendered as LITERAL page content on every single consumer of this file,
   corrupting whatever DOM it landed in. This had never been caught because nothing had used the
   component in a real page render before this build. Fixed by de-Bladeifying the inline annotation
   (plain parentheses) and rewriting the explanation of the bug itself to never repeat the same literal
   token pair in prose — confirmed live, twice, that doing so reintroduces the identical failure.
2. This component's own `<script>` (defining the global `signaturePlacer()` Alpine function) is meant to
   print once, globally, via Blade's `@once`. The REAL agent-signing usage sits many layers deep inside
   nested `<template x-if>` blocks (inspection exists → `status === 'awaiting_signature'` → not yet
   dispositioned) — Alpine clones a `<template>`'s content into the live DOM at runtime, and per the HTML
   spec a `<script>` tag inside a `<template>` is inert and never auto-executes, even the first time it's
   cloned in. Confirmed live: "signaturePlacer is not defined" on the very first attempt. Fixed with a
   tiny, invisible, otherwise-unused `x-data="signaturePlacer({ context: '_boot' })"` instantiation placed
   directly inside the Inspections tab's own `x-data` wrapper (`show.blade.php`) — real, always-present
   DOM from first paint (that wrapper uses `x-show`, never `x-if`), so its own `@include` runs completely
   normally and the script prints there; `@once` then correctly skips re-printing it for the real, nested
   usage later on the page, which only needs the (Alpine-directive-based, not `<script>`-based) modal HTML
   anyway.

**Verified live**, real headless Chrome, real QA1 data, property `5577`'s inspection `20`, user 365 (a
PIN + saved signature/initial images configured on user 365's own account for this test, via
`AgentSignatureService::save()` directly — never touching 5294 or user 22): clicked "Sign with PIN" →
PIN modal opened → entered the correct PIN → the saved signature image loaded and previewed (confirmed
via its actual `data:image/png;...` src matching what was configured) → "Confirm & save signature" →
`agentDisposition('in')` became truthy (a real signature row now exists) → "Complete" succeeded,
`chainTail.status` became `'completed'`.

`php -l` and a Blade compile-string check both clean on `show.blade.php`.

## 35. Completed inspection — real photos never rendered, tiles unclickable (2026-09-28, Johan, property 5294, "photos disappear")

Johan reported this repeatedly across the weekend, including on a brand-new inspection created
specifically to rule out old test data — same result both times, confirmed via Chrome after §33
(`a71dc40b2`) had already shipped and gone live on QA1. §33 fixed conditions/notes rendering for a
completed inspection; this is a distinct bug in the same area, in the PHOTO strip specifically.

### 35.1 What was actually wrong

`show.blade.php`'s `stripTilesForInspection(insp, item)` — the accessor `rental-inspection-item-
cell.blade.php`'s readOnly branch calls to build the photo strip — ignored its own `insp` argument and
unconditionally read `row.predecessorPhoto` off every paired row:

```js
stripTilesForInspection(insp, item) {
    return this.pairedStripRows(item).map(row => ({ index: row.index, photo: row.predecessorPhoto }));
},
```

That was correct for the ONE caller this function originally had — the genuine predecessor cell, which
always passes `chainPredecessor`. §33 gave the completed/awaiting_signature TAIL cell a second, valid
reason to call this same function, passing `currentInspection(tailSection())` (i.e. `chainTail`) once an
inspection is read-only — but the function itself was never updated to tell the two callers apart. On
any inspection that is FIRST in its chain (`chainPredecessor` is `null` — exactly property 5294's
shape, and exactly what Johan's brand-new test inspection also was), `pairedRows()` has nothing to pair
against, so every row comes back as `{ predecessorPhoto: null, tailPhoto: <the real, uploaded photo> }`.
Reading `row.predecessorPhoto` off that row is `null` every time — the real photo was always present in
the data, just read off the wrong side of the pair. This silently discarded a REAL uploaded photo on
every single tile of every item, on every first-in-chain completed inspection — not a display glitch,
not stale/corrupt data, the read accessor was structurally wrong for its second caller.

Two knock-on symptoms, same root cause, not separate bugs: the `<img>` (`x-show="tile.photo"`) never
shows because `tile.photo` is always null, and the tile's click handler (`@click="tile.photo &&
openCompareViewer(...)"`) is a no-op for the same reason — so photo tiles appeared entirely unclickable,
not just visually empty.

### 35.2 Evidence ruling out every other explanation

Before touching any code, checked and ruled out, against property 5294's real rows (read-only,
`user 365`, no writes, no `user 22` session — Johan's own instruction):

- **Soft-delete / archival cascade** — 0 of 5294's 32 photo rows are soft-deleted or archived
  (`deleted_at`, `archived_by_user_id` both null on every row). Not the cause. (A same-day, unrelated
  cleanup on inspections 28/29 had archived some near-empty photos elsewhere — confirmed that pass never
  touched inspection 33.)
- **Corrupt/near-empty files** — every one of 5294's 35 stored files (`storage/app/public/properties/
  5294/*.jpg`) is a genuine 1280×960 JPEG, 33KB–228KB. No tiny/blank files, no upload-pipeline
  compression bug. Not the cause.
- **Broken FK linkage** — all 32 photos have non-null `rental_inspection_observation_id` and
  `property_room_id`; none orphaned, none re-paired incorrectly by signing/completing. Not the cause.
- **Missing comment/notes** — `rental_inspection_observations.notes` (e.g. obs 127, Bedroom 1 → Windows,
  "Broken pane on left") is present in the DB, present in the server payload, and DOES render correctly
  in the Windows item cell when checked against the correctly-identified DOM element. The original
  four-symptom report's "no comments show" and "fewer than 5/5 items" did not reproduce once tested
  against the right item — both were artifacts of an earlier diagnostic script clicking a selector that
  matched both the visible ('ro') and hidden ('rw') copies of the same room header (they share
  `roomOpenOverride` state), double-toggling the room closed again. Bedroom 1 genuinely has and renders
  all 5 items (692 Ceiling, 693 Walls, 694 Floors, 695 Windows, 696 Doors).

### 35.3 The fix

`stripTilesForInspection()` now tells its two callers apart the same way `openCompareViewer(photo, insp)`
already does elsewhere in this same file (`isTail = this.chainTail && insp && insp.id ===
this.chainTail.id`) — reading `row.tailPhoto` for the tail cell, `row.predecessorPhoto` for the genuine
predecessor cell:

```js
stripTilesForInspection(insp, item) {
    const isTail = this.chainTail && insp && insp.id === this.chainTail.id;
    return this.pairedStripRows(item).map(row => ({ index: row.index, photo: isTail ? row.tailPhoto : row.predecessorPhoto }));
},
```

`openCompareViewer()` itself needed no change — it already branches on the identical `isTail` check, so
once a tile's `photo` is correctly populated, clicking it was already wired to open the compare viewer
on the correct side.

### 35.4 Verified

Real headless Chrome, real QA1 data (`corex_qa1`), `user 365` (agency 1, view-only — no writes, no
`user 22` session), both before and after:

- **Property 5294, inspection 33 (completed, no predecessor)** — Bedroom 1 → Walls (item 693, 3 real
  photos on obs 109). Before: `stripTilesForInspection()` returned 3 rows, all `{ photo: null }`; DOM
  showed 3 "NO MATCH" placeholders, 0 real thumbnails, clicking did nothing. After: all 3 tiles show
  their real photo (`storage/properties/5294/*.jpg` src), 0 "NO MATCH" labels visible, clicking a tile
  opens the compare viewer (`compareViewer.open === true`, `rightPhotoId` set, `primarySide: 'right'` —
  correctly resolved as the tail side).
- **Regression check, property 5792, inspection 32 (draft, real predecessor = inspection 29)** — item
  628 (Walls, 5 real photos on the PREDECESSOR side, obs 88/inspection 29). `stripTilesForInspection
  (chainPredecessor, item)` still correctly returns all 5 real predecessor photos — the genuine
  predecessor cell, the function's original caller, is unaffected by this fix.

`php -l` and a Blade compile-string check both clean on `show.blade.php`.

## 36. Condition colours by severity + note callouts (2026-09-28, Johan's ruling, property 5294)

Johan, recording on 5294's In inspection: "Broken pane on left" on Bedroom 1 → Windows read like plain
text and he missed it several times — a condition and its note must JUMP OUT.

**1. `severity` — a new per-condition-state field, agency-configurable, replacing §27.2's
`needs_attention` boolean.** Added to `RentalInspectionSetting::DEFAULT_CONDITION_STATES`
(`app/Models/RentalInspectionSetting.php`) alongside `key`/`label`/`requires_notes`: one of
`blue`/`red`/`amber`/`grey`. Shipped defaults, exactly Johan's ruling: `good`/`fair` → `blue` (calm —
"selected state as now"), `damaged`/`not_working`/`missing` → `red` (a real issue), `other` → `amber`
(caution), `n_a` → `grey` (neutral — "was never here, not an argument at all"). Resolved via
`conditionSeverityFor($agencyId, $conditionKey)` (unknown key defaults to `red` — the same "never
silently hidden" default every other resolver on this class already uses) and backfilled onto legacy
rows by `conditionStatesFor()` (a stored row with only the old `needs_attention` maps `true`→`red`,
`false`→`blue`; a row with neither key at all defaults to `red`).

**Why `needs_attention` was folded into `severity`, not kept alongside it.** The two flags were
answering the identical underlying question — "does this condition mean the space needs attention" —
through two different lenses, and Johan's own ruling ties them together explicitly ("The 'Needs
attention' filter must include every red/amber item"). Keeping both as independently-configurable
per-row fields would have let them silently disagree (e.g. `fair` shipped `needs_attention: true` under
§27.2, but today's ruling colours `fair` blue/calm) — exactly the duplicate-source-of-truth this
codebase's own Architectural Laws forbid. `conditionNeedsAttentionFor()` is now `severity IN
('red','amber')`, a pure derivation, never a second stored value.
`RentalInspectionSetting::SEVERITY_COLORS`/`SEVERITY_LABELS` are the one place the four values' literal
colours/human labels live — read by the settings edit form's picker and the PDF (§36.3 below); the live
screens use the equivalent CSS tokens directly (`--brand-button`/`--ds-crimson`/`--ds-amber`/
`--text-secondary`), never this constant.

**Settings UI** (`resources/views/corex/settings/rental-inspections.blade.php`,
`RentalInspectionSettingsController::updateConditionStates()`) — the "Needs attention" checkbox is
replaced by a "Colour" `<select>` (Calm/Issue/Caution/Neutral), same per-row-array persistence
discipline as `requires_notes`. An unrecognised submitted value falls back to `red`.

**2. Condition button colour — every rendering state.** `rental-inspection-item-cell.blade.php`'s
condition-button grid (shared by BOTH the editable tail cell and the read-only predecessor/completed
cell, per this file's own docblock) now applies `conditionSelectedClass(key)` —
`rir-cond-btn-selected-{blue|red|amber|grey}` — to the selected button instead of one hardcoded
`background:var(--brand-button)` for every condition. Four new CSS classes live in
`rental-inspection-recording.blade.php`'s own `<style>` block (§32.1's precedent: page-specific CSS
stays with the partial that renders it), theme-aware CSS custom properties throughout, never a
hardcoded hex. Because the readOnly/editable branches share the exact same markup skeleton, this one
change covers every state the task named: editable, awaiting-signature (same editable branch —
`awaiting_signature` renders exactly like draft/in_progress, §33's own note on this), completed
read-only, AND the compare/predecessor cell (the identical `readOnly=true` branch, §32.1).

The compare VIEWER's own condition pill (`compareViewerConditionClass()`, `show.blade.php` — a
different surface, the photo lightbox opened by tapping a tile) was also extended from its existing
two-way good/damaged split to the same four-way severity (`cv-pill-sev-blue/red/amber/grey`), so the
colour an agent sees on a condition button matches the colour they see on that same condition's pill
inside the photo viewer. This surface is a fixed-dark overlay by its own pre-existing design (every
colour in that `<style>` block is literal hex, never a light/dark-aware token) — the four new classes
follow that same convention, using the exact same hex values as `SEVERITY_COLORS` so the two can never
drift apart.

**3. Note callouts — never muted grey.** An item's recorded note (`rental-inspection-item-cell.blade.php`'s
read-only branch) now renders inside `.rir-note-callout` — a tinted background (`color-mix` against
`var(--surface)`), a 3px left border, normal-weight text — instead of plain `color:var(--text-muted)`
text. Tone (`noteCalloutTone()`, `show.blade.php`) is red/amber for an issue-severity condition, light
blue otherwise (a blue OR grey-severity condition's note still gets the calm blue tone — Johan: "never
muted grey," so N/A's note is never shown muted either). Room notes (`rental-inspection-recording.blade.php`,
§33's own read-only block) get the identical callout treatment, always the blue tone — a room note has
no condition of its own to derive a severity from.

**Signed PDF report** (`RentalInspectionReportPdfService`, `report-pdf.blade.php`) — the condition text
is coloured per severity (`.cond-{blue|red|amber|grey}`, bold) and a note renders inside the same
red/amber/blue callout box (`.notes-callout-{tone}`) as the live screen, resolved once per `generate()`
call via `RentalInspectionSetting::conditionStatesFor()` and attached to each row as
`current_severity`/`previous_severity`. DomPDF has no theme/dark-mode and no CSS custom-property
support, so these are literal hex — read from `RentalInspectionSetting::SEVERITY_COLORS` (passed to the
view as `$severityColors`) rather than restated in the blade file, so the PDF can never drift out of
sync with the live screens' own mapping. Room notes are NOT added to the PDF by this build — that report
already omits photos by Johan's own 2026-09-23 design ruling (this file's own docblock), and adding a
new content section (rather than recolouring an existing one) is a distinct scope question left for
Johan to raise explicitly if wanted; nothing here changes that boundary.

**4. Issue markers.** `roomIssueCount(section, group)` (`show.blade.php`) — every item in the room whose
current condition is red/amber severity (built on `itemNeedsAttention()`, itself now severity-derived,
§36.1). Rendered as "· N issue(s)" on both the room nav pill (`rental-inspection-recording.blade.php`'s
top strip) and the room heading (e.g. "Bedroom 1 — 5/5 · 3 photos total · 1 issue"), red/bold on the
pill. The "Needs attention" filter (`itemMatchesFilter()`'s `'attention'` branch) already reads
`itemNeedsAttention()`, so it automatically includes every red/amber item — no separate wiring needed,
by construction of §36.1's fold.

**Verified live**, real headless Chrome (system `chromium`, arm64 — the puppeteer-downloaded x86-64
binary in this environment's cache does not execute here), real QA1 data (`corex_qa1`), served locally
from this worktree (`php artisan serve` + a locally-built `npm run build`, needed only because a real
Alpine-driven page requires compiled assets a bare `php -l`/Blade-compile check cannot exercise):

- **Property 5294, inspection 33 (completed, no predecessor), user 365, read-only, no writes** —
  Bedroom 1 → Windows: `rir-cond-btn-selected-red` applied, computed `background-color: rgb(196, 30,
  58)` (`--ds-crimson`). Its note ("Broken pane on left") rendered inside
  `.rir-note-callout.rir-note-callout-red`, computed `border-left-color: rgb(196, 30, 58)`. Room heading
  read "Bedroom 1 — 5/5 · 3 photos total · 1 issue".
- **Property 5792, inspection 32 (draft/editable), user 365** — `good`-condition buttons rendered
  `rir-cond-btn-selected-blue`; `n_a` rendered `rir-cond-btn-selected-grey`; zero JS/render errors
  attributable to this change (see below on two unrelated pre-existing errors).
- **Property 5577, inspection 20 (completed), user 365** — two `damaged` items rendered
  `rir-cond-btn-selected-red` with red note callouts; a `good` item's callout rendered blue; one room's
  own pill/heading read "· 2 issues".
- **Settings page** (`/corex/settings/rental-inspections`, user 22) — renders with 7 severity
  `<select>`s, correct default option set/labels, no console errors. The form was NOT submitted during
  verification — agency 1's `condition_states` is the shared QA1 config Johan tests against, and a save
  wasn't needed to prove the wiring (the controller/model logic was proven independently via `php
  artisan tinker`).
- **Signed PDF, inspection 20** — rendered to an actual page image (`pdftoppm`) and eyeballed: "Damaged"
  in bold crimson with a red-bordered/tinted callout under it ("CRACKED TILE NEAR DOOR", "STAIN ON
  CEILING CORNER"); "Good" in blue. `pdftotext` confirmed the underlying row data survives DomPDF's
  render (a first, wrong reading of a suspiciously-small PDF byte count as "rows came back empty" was a
  false alarm — traced to DomPDF's own compression on a short table, not a data or logic bug; both the
  original, unmodified PDF blade and this build's produce a similarly small file for the same 3-row
  fixture).

**Two pre-existing JS errors, confirmed NOT caused by this change** (present identically before and
after, on every page tested, sourced from an unrelated Property-page component): `featureCategoryTab is
not defined` / `catDef is not defined` / `features is not defined` (repeats per property-features
category), and a same-origin `403` on some unrelated fetch. Reported per SCOPE LOCK, not fixed here —
neither traces to any file this build touched.

`php -l` clean on every changed PHP file; `php artisan view:cache` compiled every Blade template in the
app (including all five touched here) with zero errors.

## 37. Photo compare viewer opens empty-left on a first inspection (2026-09-28, Johan, property 5294)

Johan: on a FIRST inspection (no predecessor), the photo viewer opened straight into "Compare" mode
with the entire left half permanently empty ("Nothing yet") — there is no predecessor photo that could
ever fill it.

**Root cause.** `openCompareViewer(photo, insp)` (`show.blade.php`) unconditionally set
`mode: 'compare'` regardless of whether `otherInsp` (the OTHER side's inspection — `chainPredecessor`
when the clicked photo belongs to the tail) existed. On a chain's first inspection `chainPredecessor` is
always `null`, so `otherInsp` is always `null` too, and the left pane's `leftPhotoId` is never set —
exactly the same shape as §32.1's compare-ROW bug (an unconditionally-rendered predecessor cell with
nothing to show), just in the photo viewer instead of the item grid.

**Fix.** `mode: otherInsp ? 'compare' : 'single'` — the SAME per-call `otherInsp` truthiness already
computed for pairing the other side's photo, not a second, separate `chainPredecessor` check. This is
deliberately scoped to THIS viewer instance, not a global chain-wide gate: a predecessor that exists but
happens to have no photo for one specific item is a different, legitimate "no match" case (§35) that
still belongs in compare mode — only "there is no predecessor inspection at all" defaults to Single.

**The Compare toggle and its "Back to compare" escape hatch (from single view) are both hidden —
not disabled — whenever `chainPredecessor` is null**, so there is no path into an empty compare view at
all, matching STANDARDS.md's own "a blocked/hidden action is hidden, not a dead button" convention
(never the "No Silent Locks" pattern — there is nothing to unlock; a first inspection stays first
forever). The mobile Predecessor/Current side toggle needed no separate gate — it is already
`x-show="compareViewer.mode === 'compare'"`, which this fix makes unreachable by construction once mode
never becomes `'compare'`.

**Verified live**, real headless Chrome, property 5294 (inspection 33, no predecessor), user 365,
read-only, no writes: opened a real photo tile on Bedroom 1 → Windows — the compare modal opened with
`compareViewer.mode === 'single'`, the "Single" button rendered active, and the "Compare" button was not
visible (`display:none` via `x-show`).

`php -l` clean on `show.blade.php`.

## 38. Signed PDF report — embeds the actual signature image, not text-only "Signed" (2026-09-28, Johan's ruling)

Johan, verifying the report-fixes work from §36/§41 (public share page + signed PDF) live: the signed
PDF is the record that gets auto-emailed to the tenant and landlord (`SignedDocumentDistributionService`,
§41) — a text-only "Signed" line is not acceptable for a document standing in as legal evidence of who
actually signed. **Explicit ruling: leave the existing "no photos in the PDF" rule (§15.9/§36) exactly as
it is — item/room photos still live behind the QR/public-link only — this applies to signature images
only.** A signature is not a room photo; it is the one piece of visual evidence the whole document exists
to carry, and it was the only thing genuinely missing.

**What was built.** `App\Support\StorageDataUri::fromPublicStoragePath()` — a small, shared, stateless
helper (used by both this report and Inventory's own, §19 of `rental-inventory.md`; the gap was one class,
not two instances) that reads a `party_signature_path` (or any other 'public'-disk-stored path) straight
off disk and returns a `data:` URI. **Base64, never a URL** — DomPDF's `isRemoteEnabled` stays off, so the
PDF-generation path never makes an HTTP round trip to its own app (or, worse, becomes a live SSRF vector)
just to fetch an image it could read locally. `party_signature_path`/`wet_ink_upload_path` are always
stored as the PUBLIC URL form (`Storage::disk('public')->url($path)`, e.g.
`/storage/properties/{id}/.../sig_....png`) — the helper strips everything up to and including the
`/storage/` segment to recover the real disk-relative key before reading. Returns `null` (never throws) on
a missing/unreadable file — a signature image is evidence, never load-bearing for whether the PDF itself
renders.

**Wet-ink stays exactly as it already was — text + a link, never an inline image.** This is not an
oversight; `RentalInspectionSignature::DISPOSITION_WET_INK`'s own docblock is explicit: "evidence of a
real signature, but never presentable as one on screen." A canvas-drawn signature and a photographed
paper page are different classes of evidence, and rendering both identically inline would blur exactly the
distinction that docblock exists to hold. Only `disposition === DISPOSITION_SIGNED` (`party_signature_path`)
gets embedded.

**Wiring.** `RentalInspectionReportPdfService::generate()` maps `RentalInspection::signatureSummaryRows()`'s
own output (§41), adding one new `signature_image_data_uri` key per row (null for refused/wet-ink/not-
required rows) — the SAME shared resolver the public page also reads, still the one place "who signed, and
how" is decided; only the PDF service ever calls `StorageDataUri` on top of it. `report-pdf.blade.php`
renders it with a new `.sig-image` class (`max-height: 50px` — Johan's own sizing call, "a sensible size…
this is a printed legal record, not a canvas viewer") directly under the existing "Signed" text, inside the
same signatures table cell — no layout restructuring.

**Verified**, real throwaway data, never property 5294 (per Johan's own standing instruction on this whole
report-fixes round): property 21062/inspection 36 (2 items, 3 real 400×150 canvas signatures — tenant,
landlord, agent — same real-sized-PNG fixture built for §19's inventory proof), downloaded the real,
live-generated PDF over real HTTPS as user 365, and confirmed via `pdfimages -list` that the QR code AND
all three signature images are genuinely embedded as raster objects in the PDF (not just referenced) —
four images total, three at 400×150 matching the real signature dimensions exactly, none of them
served/streamed over HTTP at render time (base64-inlined, confirmed by inspecting the compiled Blade
source — no `<img src="https://...">` for a signature anywhere in this file).

---

## 39. Wet-ink follow-up: awaiting_wet_ink, document filing, "print for signature" — closes §17.8-adjacent gaps (2026-09-29)

Conductor brief 2026-09-29 — built alongside wet-ink signing for rental Inventory for the first time (see
`rental-inventory.md` §21, the primary writeup — this section records what changed HERE, on the
already-built §16/§17 inspections mechanism, kept in lockstep with Inventory's own new build). Full
reasoning for each piece lives in `rental-inventory.md` §21.1-§21.6; not restated here.

### 39.1 A new pre-state: `awaiting_wet_ink`

§16/§17 only ever built a DIRECT wet-ink capture — no way to record "the paper has been sent, nothing has
come back yet." `RentalInspectionSignature::DISPOSITION_AWAITING_WET_INK` (new) is a tracking marker only
— no upload, no signature image, no refusal fields — never valid for `party_role=agent`. `capture()`
extended with a fourth branch; `disposition` widened `string(10)` → `string(20)` (17-character value).

- `RentalInspection::firstAwaitingWetInkSignatory()` (new) — `markCompleted()` now refuses completion
  while any party is still awaiting, with a plain message naming them, checked AFTER the existing
  outstanding-signatories gate and BEFORE the agent-signature gate.
- `RentalInspectionSignature::capture()`'s own agent branch gained the identical check — the agent cannot
  sign while a party is still awaiting a paper signature, closing a real gap this build found: the
  PRE-EXISTING `outstandingSignatories()->isNotEmpty()` guard never caught an awaiting party (they DO have
  a live row), so without this fix the agent could have signed prematurely, attesting to a record with a
  party's evidence still outstanding.
- `RentalInspectionSignature::supersedeWetInk()` (§16.3) now accepts an existing row in EITHER `wet_ink`
  (the original "correct a wrong upload" case) or `awaiting_wet_ink` (resolving the marker the first time
  a scan arrives) — one mechanism, two starting points. `isAwaitingWetInk()`/`isReplaceableWetInk()`
  helpers added alongside the existing `isWetInk()`.

**The action:** "Send for wet-ink" — a new button alongside Sign/Wet ink/Refuses on
`rental-inspection-recording.blade.php`'s tenant/landlord rows, POSTing `disposition=awaiting_wet_ink`
through the SAME `signatures.store` endpoint (no new route). Once awaiting, the row shows "Awaiting paper
signature" and an "Upload scan" action (the SAME wet-ink upload form, now resolving via supersede-wet-ink
since a row already exists) in place of the four action buttons.

### 39.2 The scan is now filed as a property Document (new)

Previously a wet-ink upload only ever lived on the signature row itself (`wet_ink_upload_path`) — never
separately filed to the property's Document store. Both `storeSignature()` (a direct wet_ink capture) and
`supersedeWetInkSignature()` (resolving/correcting) now call a new private
`fileInspectionWetInkScan()`/`wetInkScanAsPdfBytes()` pair, filing through the SHARED
`SignedDocumentDistributionService::fileToProperty()` — never a bespoke second filing path. Keyed on
`(source_type, source_id) = ('rental_inspection_signature_wet_ink', $signature->id)`; doc type
`inspection_report` (the existing global catalogue slug, reused). Full mechanism — why an image gets
wrapped in a one-page PDF first, why the source_id is always the NEW signature's own id, why a filing
failure is absorbed rather than failing the capture — in `rental-inventory.md` §21.3 (identical on both
modules, written up once).

### 39.3 "Print for signature" (new)

`GET /corex/rental-inspections/{inspection}/print-for-signature` →
`RentalInspectionReportPdfService::generateForSignature()` — the SAME `report-pdf.blade.php` content
`report()` already builds, plus a red "FOR SIGNATURE" banner and blank Signature/Date blocks for every
party with no live disposition yet, or one still `awaiting_wet_ink`. Reachable from the inspection show
page (next to "Download report (PDF)") and from the property tab's own recording panel (next to the
"Ready to sign" control, visible any time the inspection isn't completed/cancelled — printing before
anyone has signed anything is the normal case, not an edge case).

### 39.4 Wording made consistent + the public-page bug fixed

Every wet_ink display now reads **"Signed on paper — scan on file"** (was "Signed (wet-ink upload)" in
some places) — the report PDF, the public page, the live recording screen's `dispositionLabel()`.
`awaiting_wet_ink` reads "Awaiting paper signature" everywhere the same badge appears.

**The bug this build fixes**, named exactly:
`resources/views/rental-inspections/public/show.blade.php:234-236` rendered EVERY
`wet_ink_upload_path` as an `<img>`, including a PDF scan — a PDF is not a browser-decodable image
format, so it silently rendered nothing, with no visible error. Now branches on the file extension: an
image extension (`jpg/jpeg/png/heic/heif`) keeps the existing linked-`<img>`; a PDF gets a plain "View
uploaded scan (PDF)" link instead. Both the public page and the report PDF also now filter/branch on
`superseded_at` so a corrected/resolved row's OLD entry never prints a second, stale line next to its
replacement.

### 39.5 Files

See `rental-inventory.md` §21.8 for the full file list across both modules — this section's own new/changed
files are the inspections-side half of that same list:
`app/Models/RentalInspectionSignature.php`, `app/Models/RentalInspection.php`,
`app/Http/Controllers/CoreX/RentalInspectionRecordingController.php`,
`app/Http/Controllers/CoreX/RentalInspectionController.php`,
`app/Services/Rentals/RentalInspectionReportPdfService.php`,
`resources/views/corex/rental-inspections/report-pdf.blade.php`,
`resources/views/corex/rental-inspections/show.blade.php`,
`resources/views/corex/properties/partials/rental-inspection-recording.blade.php`,
`resources/views/corex/properties/show.blade.php` (shared Alpine JS),
`resources/views/rental-inspections/public/show.blade.php`,
`database/migrations/2026_10_05_100000_widen_disposition_on_rental_signature_tables.php`,
`tests/Feature/RentalInspections/RentalInspectionWetInkAwaitingTest.php` (new).

### 39.6 Three real bugs found and fixed via the real click-through (2026-09-29)

See `rental-inventory.md` §21.9 for the full writeup. Summary of what landed on this module specifically:
the pre-existing "Save upload" button on this page's own wet-ink form (`properties/show.blade.php`,
shipped since §16, never proven against a real browser per §17.7's own docblock) never actually enabled
for a real click — a genuine Alpine reactivity gap in `wetInkField()`, not a logic bug (fixed); and
`resources/views/corex/rental-inspections/show.blade.php` read the original "Signed on paper (wet-ink)"
wording and had no branch at all for the new `awaiting_wet_ink` disposition (fell through to "Refused to
sign" with a bogus reason) — both fixed. Verified via a real HTTP flow (mark tenant awaiting → resolve
via supersede-wet-ink with a PDF → landlord direct wet-ink capture with a JPG → agent signs → complete)
against a throwaway property/inspection (21074/38, agency 1, user 365 — never 5294): the report PDF,
the public share page, and this show page all correctly read "Signed on paper — scan on file" for both
parties, and both scans were filed as Documents (`document_type_id` resolving to the `inspection_report`
catalogue slug).

---

## 40. Compare viewer opens with the other side empty when the clicked photo has no auto-pair match (2026-09-29, Johan, property 5294)

Johan reported this on 5294 (read-only for this fix — never written to; reproduced instead against a
throwaway fixture, per §35's own standing instruction): he had added real photos to BOTH the previous/old
inspection side and the new/current inspection side of the same item. Clicking a photo opened the compare
viewer, but it never showed the other side — an old photo opened showed only old, a new photo opened
showed only new, in both directions, every time.

### 40.1 Root cause

`openCompareViewer(photo, insp)` (`show.blade.php`) only populates the OTHER side's `PhotoId` when the
clicked photo already belongs to an auto-pair match group with a member on that side:

```js
if (otherInsp) {
    const candidates = this.compareViewerGroupSideMembers(photo.id, otherInsp.id);
    if (candidates.length) {
        this.compareViewer[otherSide + 'PhotoId'] = candidates[0].photo_id;
    }
}
```

When a photo was uploaded to both sides but nobody ever ran auto-pair (or the auto-pair service found no
confident match), `candidates` is empty — `otherSide + 'PhotoId'` is left `null`, and the other pane
renders its "Nothing yet" empty state, even though that side has real photos of its own (visible in its
own carousel strip underneath, which sources from `compareViewerPhotosForSide()` independent of pairing).
This is the exact scenario Johan hit: two independently-uploaded, never-paired photos on the same item.

A second, compounding gap in the same function: `compareViewerPhotosForSide()` sourced item-kind photos
via `conditionForInspection(insp, itemId)`, which resolves only the item's SINGLE LATEST observation. Any
item with 2+ observations this inspection (e.g. a corrected condition) had its earlier photos silently
missing from the compare viewer's own rail/carousel, even though the strip tile that was clicked
(`stripTilesForInspection()` / `stripTilesFor()`, both built on `itemPhotosForInspection()` /
`itemPhotosFor()` — EVERY observation, never just the latest) showed them fine.

### 40.2 The fix

`openCompareViewer()` now falls back to that side's first real photo (`compareViewerPhotosForSide(otherSide)[0]`)
when there is no auto-pair match — the same fallback `compareViewerSelectItem()` (the room/item-tab
navigation handler used while the viewer is already open) already uses for its own initial load: prefer
an exact match, but never leave a side with real photos showing nothing.

`compareViewerPhotosForSide()`'s item-kind branch now reads `itemPhotosForInspection(insp, itemId)` (every
observation) instead of `conditionForInspection(insp, itemId)` (latest observation only) — the same source
the strip tiles and the predecessor side already used, now consistent on both sides.

Neither change touches `pairedRows()`/`pairedStripRows()` (the strip's own pairing/ordering, unaffected)
or the auto-pair service itself — this is purely which photo the compare viewer's OWN rails default to
showing when nothing has been explicitly paired yet.

### 40.3 Verified

`php -l` clean on every changed file. `tests/Feature/RentalInspections/RentalInspectionChainTest.php`:
26/28 pass; the 2 failures (`test_comparison_row_is_a_grid_container_with_predecessor_and_tail_cells_as_siblings`,
`test_both_comparison_cells_open_the_shared_compare_viewer_on_photo_click`) are pre-existing, unrelated to
this change — both assert against a stale CSS string / a stale `openCompareViewer()` call-shape signature
from before an earlier refactor (their own docblocks reference "cc2" removing the standalone Compare
section and changing the call signature); this fix never touches the `.rir-compare-row` markup or the
`@click="...openCompareViewer(...)"` call-site arguments, only the function bodies.

`scripts/rental-click-through.mjs` gained a new named check, **#27 — Compare viewer: opening a photo
shows both sides**, run against a throwaway fixture (never 5294) with a real, servable photo on both the
Ceiling item's predecessor and tail side (§40.4 below) — clicks the old-side tile, asserts BOTH panes
render a real image (`naturalWidth`/`naturalHeight` > 0); clicks the new-side tile, asserts the same in
reverse. Run BEFORE check #26 (Auto-pair) so the fixture's photos are still genuinely unpaired when this
check exercises the fallback.

**Run for real against the actually-deployed QA1 site** (Standard −1u), headless Chromium via Puppeteer
(this repo's own established tool for every gate in this file — `rental-smoke.mjs`,
`rental-click-through.mjs`, `verify-alpine-render.mjs` all use it; not a new Playwright/xvfb harness):
both directions PASS — `clicked=300x200, other=300x200` opening from the old side, `clicked=300x200,
other=300x200` opening from the new side, real `naturalWidth`/`naturalHeight` in both panes both times.
Screenshots of both directions confirm it visually: the IN (old) and CURRENT (new) panes both show their
own real photo, side by side, from either entry direction.

While proving this, three real gaps surfaced in the shared Inspections-tab gate entry sequence — none of
them product bugs, all fixed in the same push since they blocked every check in this section (#20-27) from
a genuinely fresh session, not just #27:

1. **The "Inspection" panel starts collapsed on every fresh page load** — `open: {}` has no default entry
   for `open['inspection']`, and nothing but its own toggle ever sets one.
   `rental-inspection-recording.blade.php` (mark-room-good, mark-room-na, toggle-photo-notes,
   add-item-photo — checks #20-24) renders entirely inside that collapsed body. Added
   `data-qa="toggle-inspection-panel"` and click it once, before any numbered check runs.
2. **A genuinely fresh fixture user auto-opens the first-run "Working on a property" product tour**,
   which sits on top of the tab bar and silently swallows the tab-switch click (no error — the click just
   lands on the tour overlay). Dismissed via the tour's own `data-tour-close` control (AT-41 — overlay/X/
   ESC close is deliberately disabled).
3. `rental-inspection-recording.blade.php` **is included TWICE** (the active and completed `x-show`
   branches — already documented in `RentalInspectionChainTest`'s own comments), so the new
   `data-qa="insp-tile-*"` selectors legitimately match more than one DOM node. Check #27 walks all
   matches via `$$()` + `boundingBox()` and acts on the first genuinely visible one, rather than
   `$()`'s first-DOM-match (which could land on the hidden branch's copy).

None of the three are specific to this bug fix — they are pre-existing gaps in the gate's own entry
sequence, only surfaced because nothing had run checks #20-27 against a truly fresh session/user since
the "Inspection" panel's collapsible wrapper and the onboarding tour were added. Fixed here because they
directly blocked proving this fix; reported to the conductor as a standing finding for whoever next
touches this section.

### 40.4 Fixture changes — real, servable photos instead of a fake path; a direct `User::create()` instead of `User::factory()`

`rental-inspection-click-through-fixture.php`'s Ceiling item photos used to be a bare `storage_path`
string (`/gate-fixture/ceiling.jpg`) pointing at nothing on disk — fine for checks that only needed a
photo ROW (auto-pair, the strip tiles), but check #27 asserts the `<img>` actually renders real pixels, so
a 404'd src would fail regardless of whether the fix works. Both photos now route through the same
`PropertyImageStorer::store()` every real upload uses, reusing the existing test JPEG
(`tests/Fixtures/Images/huawei-orientation0.jpg`) already checked in for check #24's own real-upload
proof — a genuine servable file each run, not a second fake path to keep in sync with reality.

Separately: `User::factory()->create()` fatal'd on QA1 (`Call to undefined function
Database\Factories\fake()`) — Laravel's own `fake()` helper is defined only `if
(class_exists(\Faker\Factory::class))`, and `fakerphp/faker` is a `require-dev`-only package, absent from
`/corex-qa1`'s `vendor/` (a `--no-dev`-style install). Pre-existing, environment-level, blocking every
run of this fixture — **not introduced by this change** and **not fixed in the sibling
`rental-click-through-fixture.php`** (the rental-applications gate's own identical fixture, a different
feature, out of scope for this fix — reported to the conductor separately). Fixed here by building the
`User` row directly with the same fields `UserFactory::definition()` sets, removing the `fake()`/Faker
dependency from this one fixture entirely.

---

## 41. Manual photo linking — a button in the compare viewer, not just a drag gesture (2026-09-29, Johan)

Johan's question that started this: *"where on the inspections do we link photos? Either on the
inspections screen or the photo screen?"* Investigation found the honest answer was neither, in any
discoverable way — see §41.1.

### 41.1 What already existed (investigated first, file:line)

The match-group data layer was already fully built and correct:

- **Model/table** — `App\Models\RentalInspectionPhotoMatchGroup` (`app/Models/RentalInspectionPhotoMatchGroup.php`,
  table `rental_inspection_photo_match_groups`) and `App\Models\RentalInspectionPhotoMatchGroupMember`
  (`app/Models/RentalInspectionPhotoMatchGroupMember.php`, table
  `rental_inspection_photo_match_group_members`) — a photo belongs to at most one active group; a group
  auto-archives once it drops to ≤1 member. Both soft-deletable, migrations
  `database/migrations/2026_10_03_100000_create_rental_inspection_photo_match_groups_table.php` and
  `..._100100_create_rental_inspection_photo_match_group_members_table.php` (superseding the older
  pairwise `rental_inspection_photo_matches` table, migrated forward by `..._100200_migrate_pairwise_
  photo_matches_into_groups.php`).
- **The one linking primitive** — `RentalInspectionPhotoMatchGroup::linkPhotos()`
  (`app/Models/RentalInspectionPhotoMatchGroup.php:126-145`). **The one unlinking primitive** —
  `RentalInspectionPhotoMatchGroupMember::removeAndMaybeArchiveGroup()`
  (`app/Models/RentalInspectionPhotoMatchGroupMember.php:59-69`) — already a genuine soft action with a
  full audit trail (`removed_by_user_id`, soft `delete()`, never hard-deleted, auto-archives the group);
  **no change needed here.**
- **Auto-pair service** — `App\Services\RentalInspectionPhotoAutoPairService::runFor()`
  (`app/Services/RentalInspectionPhotoAutoPairService.php:43-61`) already refuses to touch a manually
  matched photo: `everTouched()` (originally lines 96-101) checks `withTrashed()` for ANY match
  membership ever, active or removed — a photo linked (or even linked-then-unlinked) once is permanently
  excluded from future auto-pair consideration. **No change needed here** — proven with a new test
  (§41.4).
- **Manual endpoints — already existed, routes/web.php:4514-4522**:
  `POST /corex/properties/{property}/rental-inspection-photo-matches` →
  `RentalInspectionRecordingController::storePhotoMatch()` (line 1013 pre-this-build) — validated same
  property (404) and refused same-inspection pairing (422), but had **no same-item/space validation at
  all**. `DELETE .../rental-inspection-photo-matches/{member}` → `destroyPhotoMatch()` (line 1039 pre)
  — same gap. `POST .../rental-inspection-photo-matches/auto-pair` → `autoPairPhotoMatches()` (line
  1067 pre) — unchanged.
- **Frontend — the actual gap, and the reason for Johan's question**: `toggleCompareMatch(leftPhoto,
  rightPhoto)` (`show.blade.php:5997-6021` pre-this-build) — the link/unlink primitive — and
  `compareViewerMatch()`/`compareViewerIsMatched()` (`show.blade.php:6426-6435` pre, docblock:
  *"Match/unmatch straight from the viewer/carousel, without leaving it (item 6, approved)"*) **already
  existed and were fully correct, but were never called from anywhere in the template** — confirmed by
  grep: each function has exactly one definition and zero `@click`/`x-show`/`x-text` callers anywhere in
  `show.blade.php`. Dead, unwired code. The **only** functioning manual-link UI before this build was
  the drag-and-drop gesture on the inspections screen itself (`pairDropOnPredecessor()`/
  `photoDraggedForPairing()`, `show.blade.php:5995-6066`, item-cell.blade.php's predecessor tile as drop
  target) — a real mechanism, but not on the photo/compare screen, and not discoverable as a button —
  exactly what Johan's question named.
- **"Retag"/"Tag photo"** (`show.blade.php:5332` single mode, `:5463` compare mode,
  `compareViewerOpenTagPanel()` at `:6567`) is a **different feature** — assigning ONE photo to a
  room/item, not linking two photos together. Not touched.
- **"Move together"** (`show.blade.php:5270` label, `compareViewerToggleLock()`) shares ONE pan/zoom
  transform between both panes while comparing — a VIEWING convenience, separate from the match-group
  DATA link. Navigating within a side's carousel (`compareViewerStep()`/`compareViewerSelectCarouselPhoto()`)
  already reads through the same `compareViewerGroupSideMembers()` → `groupForPhoto()` → `this.photoMatches`
  chain a manually-created link updates — **confirmed by reading, no code change needed**: a manually
  linked pair already moves together under "Move together" and steps together under carousel navigation,
  the exact same as an auto-paired one, because both are the same underlying data.

### 41.2 What was built

**Backend — same-item/space validation** (the one real gap in the shared linking primitive, so BOTH the
new button AND the pre-existing drag gesture get it, not just one path):

- `RentalInspectionPhoto::matchKey(): string` (new, `app/Models/RentalInspectionPhoto.php`) —
  `property_room_id . ':' . (item id or 'none')`, the same identity `RentalInspectionPhotoAutoPairService`
  already computed privately to decide which candidates are even OFFERED for auto-pair.
  `RentalInspectionPhotoMatchGroup::linkPhotos()` now throws `InvalidArgumentException` when
  `$clicked->matchKey() !== $anchor->matchKey()` — two untagged photos in the same room CAN still be
  linked (both resolve to the same `'<room>:none'` key); a tagged photo and an untagged one, or two
  different items, cannot. `RentalInspectionPhotoAutoPairService`'s own private `keyFor()` was refactored
  to call this same new method — one identity computation, not two that could drift.
- **Backend — the completed/cancelled lock**: `RentalInspectionRecordingController::
  assertPhotoMatchingUnlocked(Property $property)` (new, private) — resolves `RentalInspection::
  chainTailFor($property)` (same resolution `autoPairPhotoMatches()` already used) and aborts 409 once
  that TAIL specifically is completed or cancelled. Checked the TAIL only, deliberately — the predecessor
  side of any pair is, by definition, always already completed (that's how it became a predecessor), so
  gating on "either photo's own inspection is locked" would make matching permanently impossible. Called
  from both `storePhotoMatch()` and `destroyPhotoMatch()`. This endpoint has no Blade `@if($readOnly)`
  branch to fall back on the way item-cell.blade.php's tile buttons do once their own tail completes, so
  it is the one place server-side enforcement is the actual guard, not just belt-and-braces.
- `storePhotoMatch()`'s call to `linkPhotos()` is now wrapped in `try/catch (\InvalidArgumentException $e)
  → 422` — previously an uncaught self-match/item-mismatch exception would have reached the browser as a
  raw 500 (BUILD_STANDARD §4).

**Frontend — the compare-viewer button** (`show.blade.php`, top bar, next to "Move together"): shown only
in compare mode, once both panes have a real photo, and only while `compareViewerMatchingLocked()` (new
— mirrors the server's own tail-status check) is false. Not linked → **"Link these"** button. Linked → a
green **"Linked"** pill plus an **"Unlink"** button. Both call the pre-existing `compareViewerMatch()` →
`toggleCompareMatch()`, which now also surfaces the server's error message via `this.error` on a non-OK
response (previously silent) — the new 409/422 failure modes need to be visible, not swallowed, the same
way `pairDropOnPredecessor()`'s own errors already are.

**Frontend — the inspection-screen indicator** (item-cell.blade.php): a small clickable green badge
(🔗, class `.rir-strip-linked-badge`) on the **predecessor (read-only) tile**, opposite corner from the
index-number badge (that side's corners were free); a green **outline**
on the **tail (live) tile** instead of a badge — that side's four corners were already Select/
Back-to-room/Back-to-untagged/index, so a fifth floating badge would have collided. Both read the same
`groupForPhoto(tile.photo.id)` the compare viewer already uses. Clicking either the badge or the tile
itself opens the compare viewer already anchored on that exact pair (`openCompareViewer()`, unchanged —
already resolves the matched partner via the fix in §40).

### 41.3 Deliberately not changed

- The drag-and-drop mechanism itself — still there, still the tail-tile-drags-onto-predecessor-tile
  gesture, now protected by the same same-item and lock checks since it shares `storePhotoMatch()`.
- `compareViewerSelectCarouselPhoto()`/`compareViewerStep()` — confirmed already correct for manual
  links (§41.1), not touched.
- No frontend same-item check was added to the compare-viewer button specifically — structurally
  unreachable: the compare viewer's own carousels are already scoped to one room/item at a time
  (`compareViewerPhotosForSide()`), so `compareViewer.leftPhotoId`/`rightPhotoId` can never be two
  different items in the first place. The drag-and-drop path (tiles from different items both visible on
  a scrollable screen) genuinely could reach it before this build — that's why the check lives in the
  shared backend primitive, not a frontend-only guard on one caller.

### 41.4 Verified

`php -l` clean on every changed file; `php artisan view:clear` clean (confirms the new Blade/Alpine
markup compiles). New tests in `tests/Feature/RentalInspections/RentalInspectionRecordingControllerTest.php`:
`test_matching_two_photos_tagged_to_the_same_item_is_allowed`,
`test_matching_two_photos_tagged_to_different_items_is_rejected`,
`test_matching_is_refused_once_the_current_inspection_is_completed`,
`test_unmatching_is_refused_once_the_current_inspection_is_cancelled`,
`test_auto_pair_never_reconsiders_a_manually_linked_photo` (the last one is new coverage for the
`everTouched()` behaviour, which had zero PHPUnit coverage before this build — only ever exercised by the
real-browser click-through gate). All 5 pass, plus all 8 pre-existing photo-match tests in the same file
still pass unchanged — 13/13 on the full photo-matching subset (52 assertions).

Building the cancelled-lock test caught a real bug in the first draft of `assertPhotoMatchingUnlocked()`:
routing the cancelled check through `chainTailFor($property)` (same as completed) silently never fired,
because `chainTailFor()`'s own query structurally EXCLUDES cancelled inspections from ever being resolved
as "the tail" — the moment the inspection got cancelled, `chainTailFor()` just started resolving a
DIFFERENT (non-cancelled) inspection as the tail instead, and the lock check found nothing wrong with
that one. Fixed by checking cancelled directly off each of the two photos' own inspections instead (§41.2
above reflects the corrected version) — found by the test failing (409 expected, got 200), not assumed.

**Unrelated, pre-existing, confirmed not caused by this build** — 3 failures found running the full test
file, none of them touching photo-matching:
- `test_kind_item_adds_one_facet_to_an_existing_room_and_creates_no_new_room` and a neighbouring
  "existing room" item-template test (lines 140/180) — expected item labels (`Ceiling`/`Walls`/`Floors`/
  `Windows`/`Doors`) vs. a completely different 14-label set (`Aircon`/`Blinds`/`Carpet`/...). This diff's
  own test-file change is a pure addition (104 insertions, 0 deletions, confirmed via `git diff --stat`)
  — neither test was touched, and both are about room/item KIND templates, unrelated to photo matching.
  Very likely the same class of seed/snapshot drift `RentalInventoryPartyRolesTest` §22.4 already
  documents for `ContactType` rows — not investigated further here (out of scope), flagged to the
  conductor.
- `test_tab_payload_exposes_the_compare_pair_and_its_matches_once_an_out_inspection_exists` — asserts
  `photo_matches` has 1 group after `linkPhotos()`, gets 0. **Confirmed pre-existing, not a regression**:
  re-ran this exact test with every §41 code change (`app/`, `resources/`, `database/`) stashed out,
  against the clean QA1 baseline — fails identically, same assertion, same line. Not investigated further
  (out of scope for this build), flagged to the conductor.

**A pre-existing spec-numbering collision, unrelated, flagged not fixed**: `app/Models/
RentalInspectionSetting.php:396` carries its own `/** §41 — read-time-default resolver... */` comment,
from commit `2fd77ff40` (2026-09-28, signed-document distribution — a different feature entirely). That
commit's own §41 was never written as a real `## 41.` section in this spec file (checked: `## 41.` did
not exist anywhere in the file before this build added it) — an orphaned code-comment reference to a
spec section number that was reserved but never actually landed. This build's own §41 (the numbering
this file's own last-section-before-this-build, §40, made the next sequential number) is a genuine
content collision with that comment's REFERENCE, though not with any actual spec text (there was none to
collide with). Not renumbered here — renumbering would touch a different module's code comments, outside
this task's scope. Flagged to the conductor/cc3 for whoever owns that module next.

**Real-browser proof, headless Chromium via Puppeteer** (this repo's own established tool, not a new
Playwright/xvfb harness — same reasoning as §40's own verification): check #28 in
`scripts/rental-click-through.mjs`, run against the real deployed QA1 site on a throwaway property
(never 5294), full link → reopen → partner-shows → unlink sequence, **PASS**:
`before link: Link-these button=true, Linked badge=false | after Link these clicked: Linked badge=true,
Unlink button=true | reopened: left=300x200, right=300x200, Linked badge=true | after Unlink clicked:
Link-these button=true, Linked badge=false`. Screenshots of all four states confirm it visually — the
"Link these"/"Move together: On" pair in the top bar, then "Linked"/"Unlink" once clicked, both real
photos still rendering (300×200 each) after closing and reopening the viewer (proving the link persisted
server-side, not just client state).

**A second real gap found while proving this, fixed in the fixture, not the product**:
`RentalInspectionSetting::DEFAULT_AUTO_PAIR_PHOTOS_ENABLED = true` means the frontend auto-pairs an
item's photos the FIRST time its comparison is viewed, for any agency with no explicit setting row —
including this fixture's own brand-new throwaway agency. Check #28's own first attempt failed at its
very first assertion (`Linked=true` before any click) because of this — genuinely correct, pre-existing,
unrelated system behaviour, not a bug in this build. Fixed by having the fixture create its own
`RentalInspectionSetting` row with `auto_pair_photos_enabled=false`, so checks #27/#28 (which both need
the Ceiling item genuinely unpaired on first view) get a clean starting state; check #26 (the explicit
Auto-pair button) is unaffected either way, per that setting's own docblock ("always runs on request
regardless of this setting").

---

## AT-439 (Rentals rebuild 1/7, "Foundation") — Own/Branch/All scope, 2026-10-04

Built strictly from `/tmp/rentals-stage1-investigation.md` item C. Same finding and same fix as
`leases.md` §12 and `rental-work-orders.md`'s own AT-439 addendum (one shared trait, BUILD_STANDARD
§6 — "fix the class, not the instance"): `RentalInspectionController::index()` already called
`->visibleTo($user, $request->get('scope'))`, with no UI control and no per-record guard on any
other action. Fixed:

- **UI**: the "Showing: Own | Branch | All" pill control now renders on
  `corex/rental-inspections/index.blade.php`.
- **Per-record guard**: `App\Http\Controllers\Concerns\AuthorizesRentalRecordScope::
  guardRentalRecordScope()` now runs at the top of every `RentalInspectionController` action that
  receives a bound `$rentalInspection` — `show`, `form`, `report`, `printForSignature`, `next`,
  `generatePublicLink`, `revokePublicLink`, `cancel`, `destroy`, `restore` (10 routes). "Branch"
  resolves via `$rentalInspection->property?->branch_id`, matching `RentalInspection::
  scopeVisibleTo()`'s own `whereHas('property', ...)` check exactly.

**Follow-on, same day: the four sibling controllers, brought into scope.** Originally reported
rather than fixed (they receive a bound `RentalInspection`, or a record reached through one, with
the identical gap), then explicitly pulled into AT-439 Part 1 as the same bug class. Same trait,
same `property?->branch_id` branch resolution, applied to every non-public action:

- **`RentalInspectionRecordingController`** (tab recording) — 20 routes: `next`, `updateDetails`,
  `storeObservation`, `markRoomNa`, `markRoomGood`, `markAllGood`, `storeRoomNote`,
  `updateOverallNotes`, `storePhoto`, `storePhotos`, `tagPhoto`, `tagPhotosBulk`, `untagPhoto`,
  `archivePhoto`, `resolveDiscrepancy`, `storeSignature`, `supersedeWetInkSignature`,
  `startAwaitingSignature`, `complete`, `resendReport`.
- **`RentalInspectionComparisonController`** (deposit comparison) — 2 routes: `show`,
  `recordFinding`.
- **`RentalInspectionScanController`** (OMR scan review/download) — 5 routes: `store`, `review`,
  `apply`, `download`, `destroy`.
- **`RentalInspectionPhotoNoteController`** — 4 routes: `store`, `update`, `archive`, `restore`.

**Deliberately left unguarded, and why**: this controller's own Property-scoped-only actions
(`tabData`, `updateScreenPreference`, `start`, `storeItem`, `assignType`, `retireItem`,
`restoreItem`, `renameItem`, `reorderItems`, `applyDefaultRoomOrder`, `reorderRooms`,
`seedFromAdvertising`, `storePhotoMatch`, `destroyPhotoMatch`, `autoPairPhotoMatches`) never
receive a bound `RentalInspection` — they take a `Property` — so they are a different concern,
outside this specific trait's remit, and were left as-is. More importantly:
**`App\Http\Controllers\RentalInspectionPublicController::show()` — the tenant/landlord-facing
public inspection report link — was NOT touched and must never be.** It is a top-level (not
`CoreX`) controller reached by someone with no CoreX session at all, looked up purely by an
expiring public token (`RentalInspection::findByPublicToken()`, `withoutGlobalScopes()`, no
route-model-binding at all) — `auth()->user()` is null for every request this controller serves,
so running it through `guardRentalRecordScope()` would 403 every legitimate public viewer, not
just an attacker. Confirmed this is the ONLY public-facing inspection route: there is no separate
public signing-capture endpoint in this codebase — every signature/disposition
(`storeSignature()`/`supersedeWetInkSignature()`) is captured by an AUTHENTICATED agent asserting
it on a party's behalf (per those methods' own docblocks), never submitted directly by the tenant/
landlord through a public link.

### Files changed (AT-439)

- `app/Http/Controllers/CoreX/RentalInspectionController.php` — scope control + 10 guarded routes
- `app/Http/Controllers/CoreX/RentalInspectionRecordingController.php` — 20 guarded routes
- `app/Http/Controllers/CoreX/RentalInspectionComparisonController.php` — 2 guarded routes
- `app/Http/Controllers/CoreX/RentalInspectionScanController.php` — 5 guarded routes
- `app/Http/Controllers/CoreX/RentalInspectionPhotoNoteController.php` — 4 guarded routes
- `resources/views/corex/rental-inspections/index.blade.php` — "Showing:" control

---

## 42. Inspection Follow-up — fault reports / work orders / job cards straight from a marked item (AT-447, 2026-10-05)

**Cross-reference only — the full design lives in `.ai/specs/rental-work-orders.md` §15**, since the
feature creates `rental_fault_reports`/`rental_work_orders`/`rental_job_cards` rows (that spec's own
tables), not a new inspection-side data model. What changed on THIS spec's own screen:
`resources/views/corex/rental-inspections/show.blade.php` gained a "Follow-up" block, rendered after
the Observations block, listing every observation whose `condition` is not the agency's configured
baseline (`RentalInspectionSetting::baselineConditionKeyFor()`) with a tick box and, per row, a create
action (or the already-linked record, idempotent) for each of the three target types.
`RentalInspectionController::show()` now also loads `observations.item.room` (previously just
`observations.item`) so the Follow-up block's own title format (`"<Room> — <Item>: <Condition>"`)
needs no extra per-row query, and a new `POST .../follow-up/fault-reports` route/action
(`storeFollowUpFaultReports()`) handles the direct fault-report creation path. See
`rental-work-orders.md` §15 for the lease/property resolution rule, the combine/batch behaviour, the
photo-linking mechanism, and the back-links this same build added to the fault report/work order/job
card show pages.

---

## 43. Scheduling, notifications, and calendar sync (2026-10-05) — approved, Section A of Johan's build; Section B (Core Matches quick-share) NOT built here

Johan's approval, verbatim in substance: build Section A (inspection scheduling) now, per the
investigation report at `/tmp/inspection-scheduling-and-corematch-share-2026-10-05.md`. Section B
(a quick-share button on Core Matches) stayed explicitly NOT approved and is untouched by this build.

That investigation found: `scheduled_for` existed as a column but had no write path anywhere in the
app ("scheduling" meant "start now"); no notification existed at all, at any point, to any party;
and no calendar integration existed. This section closes all three gaps.

### 43.1 Schedule — a real action, distinct from the existing immediate Start

`scheduled_for` (unchanged, still a `date` column) gains four new columns on `rental_inspections`:
`scheduled_time` (nullable `time`), `scheduled_duration_minutes` (nullable `unsignedSmallInteger`),
`inspector_user_id` (nullable FK → `users`, `nullOnDelete` — the agent actually doing the inspection,
may differ from whoever books it), `schedule_note` (nullable text).

`RentalInspection::schedule(Property $property, string $type, User $by, array $attrs): self` — a new
static method, sibling to `start()`, sharing its two guards verbatim (active lease required; not
already under way for this type, via the existing `currentFor()`) rather than refactoring `start()`
itself, which stays byte-for-byte unchanged and is still what every pre-existing caller (including the
property tab's own AJAX flow and the Lease Hub's "Start in/out-inspection" links) uses. `inspector_user_id`
defaults to `$by->id` when not given.

One endpoint, two intents: `RentalInspectionController::store()` now branches on `$request->input('intent')`.
No `intent` (or any value other than `'schedule'`) — including every pre-existing caller — keeps the
ORIGINAL behaviour exactly: `start()`, redirect straight into the property's Inspections tab.
`intent=schedule` validates `scheduled_for` (required) + `scheduled_time`/`scheduled_duration_minutes`/
`inspector_user_id`/`schedule_note` (all optional) and calls `schedule()` instead, redirecting to the
inspection's own (read-only) show page — nothing has been recorded yet.

The create form (`resources/views/corex/rental-inspections/create.blade.php`) is the SAME form for
both — two submit buttons (`name="intent" value="start_now"` / `value="schedule"`), no JS needed. This
is also the Lease Hub's own "Start in/out-inspection" next-step link's destination
(`LeaseHubService::nextStep()`, untouched — `lease_id`/`type` prefilled via the existing query params),
so the Lease Hub gets scheduling for free: **"a Schedule action on the inspections screen AND on the
lease"** is satisfied by one shared form, not two builds.

"Start" on a scheduled inspection: both the list screen's row and the inspection's own show page gained
a "Start"/"Start recording" link to the exact same property-tab URL `store()`'s immediate path already
redirects to (`?tab=inspections`) — shown whenever `scheduled_for` is set and the inspection is still
recordable. The tab itself needed no change: it already resolves the property's current open
inspection via `RentalInspection::currentFor()`, which a scheduled (status=`draft`) inspection already
satisfies.

**Reschedule** — `RentalInspection::reschedule(array $new, User $by, ?string $reason = null):
RentalInspectionReschedule`. Guarded by the same `assertRecordable()` as `cancel()`/`markCompleted()` —
a completed/cancelled/archived inspection can never be rescheduled. Writes one immutable history row
FIRST (via `RentalInspectionReschedule::record()`, mirroring `ContactMatchReassignment`'s own
append-only pattern — `SoftDeletes` from the first migration per the standing no-hard-deletes rule),
then updates the inspection, then re-syncs its calendar event and re-notifies the parties. Route:
`POST /corex/rental-inspections/{id}/reschedule`, gated on the existing `rental_inspections.create`
permission (no new permission — reusing the existing create-gate, same as every other write action on
this screen). Reason is optional (unlike cancel, which already required one before this build) — a
reschedule is a normal operational adjustment, not something needing a justification on record the way
cancelling is.

New table `rental_inspection_reschedules`: `agency_id`, `rental_inspection_id`, `old_scheduled_for`/
`old_scheduled_time`/`old_inspector_user_id`, `new_scheduled_for`/`new_scheduled_time`/
`new_inspector_user_id`, `reason` (nullable), `changed_by_user_id`.

**Cancel** — already existed (`cancel(User $by, string $reason)`, reason already required, soft via
`status=cancelled` + the standard `deleted_at` floor for archiving separately) — unchanged except for
two new one-line calls appended at the end: calendar sync (dismisses the event) and
`RentalInspectionNotificationService::notifyCancelled()`.

**Minimum notice** — `RentalInspectionSetting::minimumNoticeDaysFor($agencyId)` (default 1). Checked in
the controller only, after a successful schedule/reschedule: **warns, never blocks** — a flash
`warning` banner on the show page, the booking/reschedule itself always succeeds. Per Johan's own
instruction, not enforced in the model.

**List screen** (§5, extended): sortable/filterable by `inspector_id` (new query param + column +
`leftJoin` sort, mirroring the existing `property`-sort join pattern exactly), search now also matches
the inspector's name. The pre-existing "Scheduled (upcoming)" tile (`scheduled_for >= now()`) now
actually populates, since `scheduled_for` finally has a write path.

**Own/branch/agency scoping** — `RentalInspection::scopeVisibleTo()`'s `own` branch widened (never
narrowed) to `created_by_user_id OR inspector_user_id IN $user->dataIdentityIds()` — an inspector
booked by someone else (e.g. a branch manager scheduling on an agent's behalf) sees it on their own
board too. `branch`/`agency` scopes are unaffected (already see everything in scope, inspector or
not).

### 43.2 Notify — tenant(s), landlord, inspector; mail is real, WhatsApp is honestly not

`App\Services\Rentals\RentalInspectionNotificationService` — one service, four entry points
(`notifyScheduled()`, `notifyRescheduled()`, `notifyCancelled()`, `notifyReminder()`), called from the
model (`schedule()`/`reschedule()`/`cancel()`) and the reminder command (below).

**Recipients**, each gated by its own agency setting (all default ON except WhatsApp):
- **Tenant(s)** / **Landlord** — resolved via two new methods on
  `RentalInspectionNotificationService` (`tenantContacts()`/`landlordContacts()`, also reused by the
  calendar sync service for the event description, via DI, so the two can never disagree on who
  counts) — **same semantics as the existing `Lease::tenantContacts()`/`landlordContacts()`**
  (landlord resolution never falls back to "the only contact on file" — **Johan's explicit
  instruction**), but NOT literal calls to those methods. Reason, found running the real test suite,
  not assumed: `Contact` carries its own `ContactScope` (role-based `own`/`branch`/`all` READ
  visibility keyed to the CURRENTLY ACTING user's own Contacts permission — `core-matches.md`'s own
  "ContactScope trap" section documents the identical collision on a different screen). A booking
  agent whose personal Contacts scope is `own`, and who didn't personally create the landlord's (or
  even the tenant's) Contact row, got ZERO recipients back from the existing relation-based methods
  in this build's own test run — not a missing landlord, a missing NOTIFICATION, for a system action
  that must never depend on who happened to click the button. Fixed the same way `core-matches.md`'s
  own "actual fix" fixed the identical trap: resolve the matching contact IDs via a fresh top-level
  query on the plain pivot table (`lease_tenants` / `contact_property`, neither of which carries
  `ContactScope`), then ONE fresh top-level `Contact::query()->withoutGlobalScope(ContactScope::class)`
  — never inside a relation closure, which that investigation also proved does not reliably reach the
  compiled SQL. `AgencyScope` is untouched either way — still a hard cross-agency boundary. Verified
  by test: a lease with a tenant and genuinely no landlord tag logs zero landlord-role rows, never
  substitutes the tenant; the main notify-all-three test (run as a plain `agent`-role user who did
  NOT create the tenant/landlord Contact rows) correctly reaches all three parties.
- **Inspector** — `RentalInspection::inspector()` (the new relation), when set.

**Channels**:
- **Mail** — a REAL, fully automated send. One new Mailable
  (`App\Mail\Rentals\RentalInspectionNotificationMail`, extends `App\Mail\Signatures\BaseSignatureMail`
  for the same per-agent-mailbox send infrastructure `SignedDocumentDistributionMail` already reuses),
  sent via `SignedDocumentDistributionService::sendGenericMail()` — the existing path already built for
  exactly this (a Mailable outside the `SignedDocumentDistributable` contract), carrying the SAME
  non-production test-mail-redirect safety rail as every other outbound CoreX mail. No third outbound
  mail mechanism invented.
- **WhatsApp** — **not a real automated send, and this is stated plainly rather than faked.**
  Investigated before building: there is no server-side WhatsApp sending API anywhere in CoreX today —
  every existing "WhatsApp" feature (Core Matches, the Outreach Queue) opens `wa.me` in a human's own
  browser. The Outreach Queue specifically was considered and rejected as the mechanism here: it gates
  on marketing consent (wrong for an operational notice a tenant cannot opt out of) and has no path at
  all for notifying a `User` (the inspector is not a `Contact`). So turning this channel on logs a
  `queued` row carrying the composed message on the inspection's own history, for an agent to act on by
  hand — it does not pretend to send anything on its own. Default OFF for exactly this reason.

**Templates** — subject line only, via the existing `PerformanceSetting` per-agency key/value store
(same mechanism Core Matches' own WhatsApp message template already uses,
`matches_wa_message`/`PerformanceSetting::get()`): key `rental_inspection_notification_subject`,
placeholders `{event}`/`{type}`/`{address}`. The email body is a fixed Blade template
(`resources/views/emails/rentals/inspection-notification.blade.php`) — no body templating mechanism
existed to reuse, and building a new one was out of scope for this build's time budget; flagged here,
not silently decided, if Johan wants the body editable too.

**Logging** — every attempt, every channel, every recipient, logged to a new append-only table
`rental_inspection_notifications` (`event`, `party_role`, `recipient_contact_id`/`recipient_user_id`,
`channel`, `recipient`, `status` — sent/failed/queued/skipped, `error`). A recipient with no
email/phone on file logs `skipped` with a reason, never silently drops. Shown on the inspection's own
show page (reschedule history + notification log, both read-only lists).

**Settings** — seven new nullable columns on the existing `rental_inspection_settings` table (same
"one more Core-Matches-style knob on the table that already owns the siblings" reasoning as every
other setting on this model): `notify_tenant_enabled`, `notify_landlord_enabled`,
`notify_inspector_enabled`, `notify_via_mail_enabled`, `notify_via_whatsapp_enabled` (all default ON
except the last), `minimum_notice_days` (default 1), `reminder_days_before` (default 1, 0 = off). One
new saver, `RentalInspectionSettingsController::updateScheduleNotifications()` — has()/filled()-guarded
per field exactly like `update()`'s own established pattern on this controller, never a hard
"submitted" marker, since this saver is also registered on the Setup Wizard step and must never
force-default a field the wizard's own render doesn't post.

**Setup Wizard** — all seven added to `config/agency-onboarding-copy.php`'s existing Rentals step
(same step as `fault_report_window_days`/`auto_send_report_enabled`), per CLAUDE.md #10a — every
toggle/number here fits the wizard's existing generic control types, so none of them needed the
"editable here, NOT in the wizard" exception the condition-states/refusal-presets repeaters already
use.

### 43.3 Calendar — one event per scheduled inspection, kept in sync

`App\Services\Rentals\RentalInspectionCalendarSyncService::syncForInspection(RentalInspection
$inspection): ?CalendarEvent` — idempotent, `CalendarEvent::updateOrCreate(['source_type' =>
RentalInspection::class, 'source_id' => $inspection->id], [...])`, the same keying
`App\Services\CommandCenter\AutoEventService` already uses elsewhere in CoreX for exactly this
"never duplicate, always update" reason.

Only a genuinely SCHEDULED inspection gets an event — an immediate "Start Now" has no advance booking
to put on a calendar, so `scheduled_for === null` is a deliberate no-op (verified by test). Fields:
`user_id` = the inspector (falls back to the creator if somehow unset), `event_type='lease'`,
`category='rental_inspection'`, `title` = `"<Type>-inspection — <property address>"`, `description` =
every tenant/landlord contact with their phone number where on file, the schedule note if any, and a
link back to the inspection (`route('corex.rental-inspections.show', $inspection)`), `event_date`/
`end_date` from the new `scheduledForDateTime()`/`scheduledEndDateTime()` helpers (date + time +
duration combined), `all_day` = true only when no time was given.

**Status lifecycle**, mapped onto `CalendarEvent`'s own existing vocabulary (pending/completed/
dismissed — no new status invented): `pending` while open, `completed` when `markCompleted()` runs
(one new line appended there), `dismissed` (with `dismissal_reason_code='inspection_cancelled'` +
`dismissal_reason_notes` = the cancel reason) when `cancel()` runs (one new line appended there).
Reschedule calls the same sync method again — same `source_type`/`source_id` key, so the existing row
is updated in place, never duplicated (verified by test).

### 43.4 Portals — already true, not a gap

The tenant and landlord portals (`RentalPortalScopeService::tenantInspections()`/
`landlordInspections()`) already list every inspection for their lease/properties regardless of
status, so a scheduled inspection's date/time is already visible there the moment it's booked — no
change needed. Confirmed during the original investigation, not assumed here.

### 43.5 Files

- `database/migrations/2026_10_05_120000_add_scheduling_fields_to_rental_inspections_table.php`
- `database/migrations/2026_10_05_120100_create_rental_inspection_reschedules_table.php`
- `database/migrations/2026_10_05_120200_create_rental_inspection_notifications_table.php`
- `database/migrations/2026_10_05_120300_add_schedule_notification_settings_to_rental_inspection_settings_table.php`
- `app/Models/RentalInspection.php` — `schedule()`/`reschedule()`/`scheduledForDateTime()`/
  `scheduledEndDateTime()`/`inspector()`/`reschedules()`/`notifications()`; `cancel()`/`markCompleted()`
  gained the calendar-sync (+ notify, for cancel) call; `scopeVisibleTo()`'s `own` branch widened.
- `app/Models/RentalInspectionReschedule.php`, `app/Models/RentalInspectionNotification.php` — new.
- `app/Models/RentalInspectionSetting.php` — seven new constants + accessors.
- `app/Services/Rentals/RentalInspectionCalendarSyncService.php`,
  `app/Services/Rentals/RentalInspectionNotificationService.php` — new.
- `app/Mail/Rentals/RentalInspectionNotificationMail.php` + `resources/views/emails/rentals/inspection-notification.blade.php` — new.
- `app/Console/Commands/SendRentalInspectionReminders.php` — new; registered in `routes/console.php`
  (`dailyAt('07:15')`).
- `app/Http/Controllers/CoreX/RentalInspectionController.php` — `create()`/`store()` extended,
  `storeScheduled()`/`reschedule()` new, `index()`/`filteredInspectionsQuery()` extended for inspector
  sort/filter/search, `show()` extended for the new relations.
- `app/Http/Controllers/CoreX/RentalInspectionSettingsController.php` — `edit()` extended,
  `updateScheduleNotifications()` new.
- `routes/web.php` — one new route (`.reschedule`), one new settings route
  (`.schedule-notifications`). No new permission — reuses `rental_inspections.create`/`.view`.
- `resources/views/corex/rental-inspections/create.blade.php` — the shared Start-now/Schedule form.
- `resources/views/corex/rental-inspections/index.blade.php` — inspector column/filter/sort, time
  shown alongside date, a "Start" action for a not-yet-recorded scheduled inspection.
- `resources/views/corex/rental-inspections/show.blade.php` — scheduling block (date/time/duration/
  inspector/note), Reschedule toggle + form, reschedule history, notification log, a warning-flash
  banner.
- `resources/views/corex/settings/rental-inspections.blade.php` — the new "Schedule notifications"
  panel.
- `config/agency-onboarding-copy.php` — seven new wizard controls + the new saver registered on the
  existing Rentals step.
- `tests/Feature/RentalInspections/RentalInspectionSchedulingTest.php` — schedule/reschedule/cancel,
  notification recipients per setting (mail faked; tenant never substituted as landlord), the reminder
  command (fires once on the configured day, never twice same day, off at 0), calendar sync (create/
  update-not-duplicate/dismiss/never-for-an-immediate-start), own-scope-by-inspector, cross-agency
  isolation.
- `database/schema/mysql-schema.sql` — re-dumped, `DEFINER` clauses stripped.

### 43.6 Not built here — flagged, not silently decided

- **Core Matches quick-share** (Johan's Section B) — explicitly NOT approved for this build; nothing
  in `.ai/specs/core-matches.md` touched.
- **WhatsApp as a real automated send** — see §43.2. Would need an actual WhatsApp Business API
  integration (none exists in CoreX today) before this channel could do more than log a queued
  message.
- **Email body as an agency-editable template** — only the subject line is templated
  (`PerformanceSetting`); the body is a fixed Blade view. A body-templating mechanism was out of scope
  for this build's time budget.
- **Export/print-list columns** — `RentalInspectionController::export()`/`printList()` were not
  extended to show the inspector column; only the on-screen list was.

## 44. Chain tail survives the lease ending; predecessor inference is same-second-safe (2026-10-06)

Two real defects behind six failing tests in `RentalInspectionRecordingControllerTest`, found by the QA1
overnight pass (2026-10-05) and finished 2026-10-06. The other four failures were stale/mis-seeded tests, not
app bugs (below).

**1. `RentalInspection::chainTailFor()` went blind the moment an Out-inspection completed.**
`RentalInspectionCompletionObserver` (rental-renewals.md §15, GATE 2 row 6) correctly flips the lease to
`expired` when an Out completes. `chainTailFor()` only looked for the property's `active` lease, so at exactly
that moment it returned null — the Inspections tab lost its current/predecessor pair and the §41 "completed —
photos can no longer be linked or unlinked" lock (409) stopped firing, i.e. the one moment the chain matters
most (a deposit dispute). Now: the active lease if one exists (unchanged), otherwise the property's most recent
lease that has a non-cancelled inspection. It never looks past an ACTIVE lease, so a new tenancy with no
inspection yet still reads "no chain" rather than showing the previous tenant's finished one.

**2. `RentalInspection::inferredPredecessorFor()` found no predecessor for two inspections created in the same
second.** It required `created_at <` strictly; `created_at` has one-second resolution, so an In and an Out made
in the same second (batch/backfill/scheduling) tied and the Out showed "first in chain" with no photo matches.
`id` is now the tiebreaker (it already was in the `orderBy`).

**Tests that were stale, not the app** (updated, not weakened):
- Two Bedroom checklist assertions expected the old generic 5-item fallback. Bedroom has had its own
  transcribed-from-the-paper-form checklist since 2026-09-21 (`RentalInspectionSetting::DEFAULT_ROOM_TYPE_ITEMS_BY_TYPE`);
  the tests now assert against that constant.
- Two cross-agent tests (two agents on one inspection) need `rental_inspections.view` at `branch` scope to reach the
  controller at all, since the 2026-10-04 Own/Branch/All ruling (AT-439, above). They now seed that, plus `.create`
  (seeding `.view` alone switches the role to strictly-enrolled and 403s the recording routes).

Files: `app/Models/RentalInspection.php` (`chainTailFor`, `inferredPredecessorFor`),
`tests/Feature/RentalInspections/RentalInspectionRecordingControllerTest.php`.

---

## 44a. Public report link stops working when the inspection is archived or cancelled (2026-10-06, cc1 — BUILT, security fix)

**Found while measuring the module against Johan's standard** (see the §45 gap report): an archived or
cancelled inspection's public report link (`/rental-inspection-report/{token}` — the QR/link on the PDF and in
the completion email) kept serving the full report (photos, signatures, tenant names, property) for the rest of
the link's 90-day expiry. Cause: `RentalInspection::findByPublicToken()` runs `withoutGlobalScopes()` (an
unauthenticated caller has no agency context), which also strips `SoftDeletes`, and it never looked at `status`.
`destroy()` and `cancel()` never revoked the token.

**The rule (Johan's ruling, 6 Oct):** the link must stop working the moment the inspection is **archived**
(soft-deleted) or **cancelled**, and work again if it is **restored**; the standard "this link isn't available"
page, never a stack trace; signed/expiry rules otherwise unchanged.

**Fix — one place, deliberately not a token wipe.** `findByPublicToken()` now also requires
`deleted_at IS NULL` and `status != 'cancelled'`. The token is NOT cleared on archive/cancel, so restoring an
archived inspection revives the SAME link (and the QR already printed on its PDF) — clearing it would have
broken that. Expiry, revoke (clears the token) and regenerate (replaces it) behave exactly as before; an
expired, revoked or replaced token stays dead after a restore. The unavailable page is the existing uniform one
(`rental-inspections.public.unavailable`): an archived/cancelled link looks identical to a wrong or expired one,
so a stranger cannot tell which. Cancelled is terminal today (no un-cancel path exists), so "restored" applies
to archived; the rule is status-based, so it would apply to any future un-cancel.

`RentalInspection::publicLinkIsAvailable()` (new) = token unexpired AND not archived AND not cancelled, used
only for what the agent's inspection page DISPLAYS: a cancelled inspection no longer shows "Live until …" but
"The link is switched off while this inspection is cancelled." `publicLinkIsValid()` is deliberately left
token-only — `SignedDocumentDistributionService::ensurePublicLink()` uses it to decide whether to generate a
token and must never overwrite one (breaking the printed QR) merely because an inspection is archived.

**Class sweep — every public/tokenised route that can expose inspection data:**

| Route | Lookup | Status |
|---|---|---|
| `GET /rental-inspection-report/{token}` (`rental-inspections.public.show`) | `findByPublicToken()` | **Fixed** (via the shared lookup) |
| `GET /rental-inspection-report/{token}/signatures/{signature}/{kind}` (`rental-inspections.public.signature-file`) — the only file route under the link (signature image / wet-ink scan) | `findByPublicToken()` | **Fixed** (same lookup; 404 when archived/cancelled) |
| Contractor secure links, crew job links, crew page (`rentals.secure-link.*`, `rentals.crew-job.*`, `rentals.crew-page.*`) | own tokens | Checked: expose no inspection data (`inspection` appears only in a docblock) — nothing to change |
| Authenticated routes (`corex.rental-inspections.*`, signature file, PDFs, scans) | route binding + guard | Not public; archived inspections already 404 through binding |
| `GET /rental-inventory-report/{token}` (+ buyer-acceptance POST) | `RentalInventory::findByPublicToken()` | **Same defect, NOT changed (different module — reported, awaiting Johan's go)** |

**Reported, not changed:**
1. **Inventory public link has the identical gap** — `RentalInventory::findByPublicToken()` (`app/Models/RentalInventory.php:641-647`, `withoutGlobalScopes()`, no `deleted_at`/status check) and `RentalInventoryPublicController::show()`/`storeBuyerAcceptance()`; an archived inventory's report (and the unauthenticated buyer-acceptance write) stays live. The identical two-line change fixes it; it is another module, so it waits for an explicit go.
2. **Photos on the public page are static public-disk URLs**, not served under the token (`rental-inspections/public/show.blade.php:177-181`, `storage_path`). The page stops showing them, but a photo URL someone already holds keeps working until the file is removed — they are unguessable capability URLs shared with the agent's own screens. Making them revocable needs a token-gated photo route (every photo streamed through PHP) — a design call for Johan, not done here.
3. The unavailable page returns HTTP 200 (`RentalInspectionPublicController.php:35-44`); a dead link would be more correct as 404/410. Unchanged (behaviour change on every dead-link case).
4. The property Inspections tab builds a share URL from the chain tail's token in JS (`properties/show.blade.php:7507-7509`); not changed.
5. `RentalInspectionController::printForSignature()` still mints a public link on a GET for a cancelled inspection (`:642-644`, no cancelled check); the link is now dead on arrival, but the state change on GET remains.

**Tests** (`tests/Feature/RentalInspections/RentalInspectionPublicLinkLifecycleTest.php`, 15 tests; the existing
`RentalInspectionPublicLinkTest.php` still passes, 8): archived page · archived signature file · cancelled page ·
cancelled signature file · restored page and file (same token) · archive → restore → archive again · archive and
restore through the agent's own HTTP routes · token not cleared by archiving · expired/revoked/replaced tokens
stay dead after a restore · archived link identical to a never-issued token (same status, same text) · agency B's
live link unaffected by agency A's archive · agent screen no longer calls a cancelled link live. With the model fix
reverted, 12 of the 15 fail (the 3 that still pass guard rules that did not change); with it, 23/23 pass.

Files: `app/Models/RentalInspection.php` (`findByPublicToken`, `publicLinkIsAvailable`),
`resources/views/corex/rental-inspections/show.blade.php` (link status text),
`tests/Feature/RentalInspections/RentalInspectionPublicLinkLifecycleTest.php`. No migration, no setting, no
permission, no route change.

---

## 45. Johan's inspection standard, measured — gap table, specs for the missing parts, build plan (2026-10-06, cc1, INVESTIGATE + SPEC ONLY — NO CODE)

**Status: spec only. Nothing in this section is built** (the one security fix that came out of it, §44a, is). Measured against `origin/QA1` `2fac97490`; **amended 6 Oct 15:11 with Johan's rulings (§45.0).**
Johan's ruling of 6 Oct 14:05: measure the rental inspections module against the seven-point standard
below and build the gaps. This section is the measurement, the spec for every missing part, the open
questions only Johan can answer, and the build plan. Evidence is file:line against that commit.

### 45.0 Johan's rulings of 6 Oct 2026 15:11 — applied throughout this section

Numbering below is the numbering of the question list printed to Johan on 6 Oct (it is also the numbering
of §45.9). Status of each: **RULED** (built to it), **PARKED** (belongs to the finance build — not in the
current plan), **OPEN** (not yet ruled).

| # | Subject | Status | What was ruled | Effect on the plan |
|---|---|---|---|---|
| 1–4 | Deposit, deductions list, who finalises, amount charged | **PARKED** | They belong to the finance build. | **Build I-8 is PARKED until the finance side is built** and is removed from the current plan (§45.7b kept as a parked design, marked). The three older "no deductions" rulings stay in force until then. |
| 5 | Photos required | **RULED** | **No enforcement.** Photos are uploaded per inspection and can be tagged to a room or to a section of a room (e.g. windows) — that is sufficient. | I-1 loses the "no photo" guard, the room-photo guard and their two settings. Tagging verified in code — see below. |
| 6 | Interim inspections | **RULED** | **No automatic scheduling.** The agency loads its own interim inspection dates when it wants them (not every agency does interims); CoreX reminds from the loaded dates. Revisit when more agencies are on. | I-5 re-cut: no interval setting and no computed interim due; a "loaded dates" list with reminders instead (§45.7). |
| 7 | Signing | **RULED** | **All parties sign every inspection — agent, tenant AND landlord — incoming, interim and outgoing alike.** It is part of the legal documentation. | I-4: interim is signed by all three, exactly as In and Out. |
| 9 | Default checklist | **RULED (a)** | Approve the floor-to-ceiling default list **and** add the "Add missing standard items" button for existing properties. | I-2 unblocked for both. |
| 8, 10–15 | PDF photo times · inventory · representative · non-attendee copy · portal · In-while-signing · remote signing | **OPEN** | Not yet ruled. | Kept as open questions (§45.9); each build says exactly which part waits. |

**Photo tagging — confirmed in code (6 Oct): tagging to a ROOM and to a SECTION of a room both exist today; no new tagging build is needed.**
A photo carries `property_room_id` (the room) and, through its observation, `rental_inspection_item_id` (the room's item/facet —
e.g. "Windows", "Ceiling"); `RentalInspectionPhoto::tagTo()` sets both, `tagPhoto()` resolves the room from the item server-side
(`RentalInspectionRecordingController.php:986-1015`), and all six moves (tray → room, tray → item, room → item, item → room, either → tray)
are built (§22.1–§22.2). Tagging to an item that has no condition recorded yet works (§22.3c, §20.22). Anything finer than an item
("the north window") is the photo's own note (§25). **One practical caveat, not a missing mechanism:** a room's "sections" are that room's
checklist items, and some default lists have no Windows or Doors item (Bedroom, Kitchen and Bathroom — §19). An agent can add one
(§21), and the ruled default-checklist build (I-2, Q9a) supplies them on new properties and, via "Add missing standard items", on existing ones.

**Rules this section is written to (stated once, apply to every build below):**
BUILD_STANDARD §1a–§1d (full CRUD incl. restore, list search/sort/filter/pagination/empty state,
own/branch/agency scoping at the query layer, per screen — each build states them), §2–§3 (input space,
prevent-or-absorb), CLAUDE.md #9 (multi-agency — every default neutral, every wording/threshold an agency
setting), #10a (every new setting reaches the Setup Wizard in the same build, or is recorded as
"Deliberately NOT in the wizard" by Johan's call), non-negotiable #1 (soft delete only). Observations and
photos stay append-only (§3.3). Nothing here writes legal conclusions into product text: where wording
touches rights under the Rental Housing Act the build records FACTS and the exact string is Johan's
(§45.11).

### 45.1 Gap table

Legend: HAVE = works as the standard says · PARTLY = exists but misses part of the standard · MISSING.

| # | Standard | Verdict | What exists (evidence) | What is missing |
|---|---|---|---|---|
| 1a | Room-by-room checklist, every building element "floor to ceiling" | **PARTLY** | Rooms = `property_rooms`, items = per-property `rental_inspection_items`, seeded once from the advert's Spaces/Features (`RentalInspectionFormSeeder.php:35-128`), plus hand-added rooms/items/meters (`RentalInspectionRecordingController.php:169-373`). | Default vocabulary is thin and uneven: 45 of 50 room types fall back to `Ceiling, Walls, Floors, Windows, Doors` (`RentalInspectionSetting.php:78`); only Kitchen/Bathroom/Bedroom/Garage/Yard have fuller lists (`:92-113`), and those omit windows, doors, floors, skirting, geyser, drains. Nothing forces every item to be graded before completion (`RentalInspection.php:484-540` checks discrepancies, required notes, signatures — not unrecorded items). |
| 1b | …and every inventory item | **PARTLY** | Inventory is its own module (`rental_inventories`/`_lines`, free-text lines, own photos/dispositions/comparison), joined to inspections only by shared `property_rooms` (`rental-inventory.md` §4b). | Furniture/contents are not on the inspection checklist; inventory has no agency template (starter list) — every furnished let starts from a blank list or "copy from last". |
| 1c | Agency-configurable room/item templates | **PARTLY** | Agency overrides item list per room type, walking order, condition vocabulary, feature allow-list at `/corex/settings/rental-inspections` (`RentalInspectionSettingsController.php:202-232`, `RentalInspectionSetting.php:506-516`). | Room TYPES are a hard-coded 50-item config list — an agency cannot add one ("Roof space", "DB board", "Pool house") (`config/property-spaces.php`; validated at `RecordingController.php:175-180`, `SettingsController.php:211-218`). Existing properties never pick up an improved template (seeded once, §19). Item lists/walking order are link-out only in the wizard (spec-ruled exemption §19). |
| 2a | Every flaw has a written note | **HAVE** | Agency vocabulary flags which states need a note; 422 on save (`RecordingController.php:539-541`); completion blocked by default (`RentalInspection.php:269-317,500`; setting `require_notes_blocks_progression`). | — |
| 2b | …and one or more photos | **PARTLY** | Multi-photo per item (≤10/request), room-level "general" photos, tray, immediate upload (`RecordingController.php:823-901`, §20.13, §20.22). | **No "no photo" guard anywhere** — and by Johan's ruling of 6 Oct (Q5) none is wanted: photos tagged to a room or a section of a room are sufficient. Nothing to build. |
| 2c | Each photo time-stamped, capture time stored server-side, shown on the report | **MISSING** | Only `created_at` = server RECEIVED time (`RentalInspectionPhoto.php:63-65`); `tagged_at` is filing time. EXIF date is destroyed by `PropertyImageStorer.php:32-47` and never read. Spec §14.4/§14.5 say the app sends its captured time and the server trusts it — the code does not, and there is no mobile write API for inspections. | A `taken_at` column + source; client/EXIF/server priority; display on recording tile, compare viewer, public page, PDF. Today an offline 9am photo uploaded at 2pm reads 2pm. No view renders any photo time (`photo.created_at` has zero hits in views). |
| 2d | General condition photos per room, on the report | **PARTLY** | Capture HAVE (room-tagged, no item). | Public report page loads only item photos (`RentalInspectionPublicController.php:60-61`); PDF has no photos by Johan's 23 Sep ruling, and no room/overall notes, no photo times. |
| 3a | Incoming inspection before move-in | **PARTLY** | Type `in`; manual create/schedule; scheduled-inspection invitation + one reminder (`RentalInspection.php:797-874`, `SendRentalInspectionReminders.php`). Lease Hub / Command Centre show a "Start inspection" prompt (`LeaseHubService.php:108`, `RentalCommandCentreService.php:721-756`). | Nothing raises it from the lease; a human must notice the prompt. Needs an ACTIVE lease. |
| 3b | Interim inspections on an agency interval (default 6 months), raised automatically, with reminders | **MISSING** | `ad_hoc` is the manual mid-tenancy check and is exempt from signing (`RentalInspection.php:452,502`). | No interim type; nothing creates inspections from a lease; `signing_deadline_at` and "due" are never acted on. **Ruled 6 Oct (Q6): interim inspections are NOT scheduled automatically — the agency loads its own interim dates and CoreX reminds from them (§45.7); no interval setting.** |
| 3c | Outgoing inspection at lease end | **PARTLY** | Type `out`; hub prompt once notice is recorded (`LeaseHubService.php:104`); completed Out expires the lease (`RentalInspectionCompletionObserver`). | Nothing schedules/raises it from `move_out_date`/`end_date`; `CheckLeaseExpiry` only sends the agent a bell alert (`:146`). |
| 4a | Signed by tenant and agent (e-sign or wet-ink upload) | **PARTLY** | In-person canvas signature for tenant/landlord, PIN signature for agent, wet-ink upload + "awaiting wet-ink", refusal with reasons, evidence-superseding (`RentalInspectionSignature.php:129-249`; §15, §17, §34, §39). Completion guard for in/out (`RentalInspection.php:503-534`). | Interim/ad-hoc checks are unsigned — **ruled 6 Oct (Q7): ALL parties (agent, tenant, landlord) sign EVERY inspection incl. interim; ad-hoc not covered, stays unsigned as today (flagged)**. No remote signing link for a party who cannot attend (§15.4 deliberately not built). `signing_deadline_at` is display-only. |
| 4b | Copies to tenant, landlord, agency automatically on completion | **PARTLY** | "Complete" click files the signed PDF to the property and auto-emails by default (`RecordingController.php:1523-1616`; `auto_send_report_enabled` default ON; `SignedDocumentDistributionService`). | No agency recipient (the "agency copy" is a filed document + CC of the creating agent only). Recipients resolved through the acting agent's `ContactScope` + sole-contact fallback (`RentalInspection.php:1107-1127`, `Property.php:1289`) so a party can be dropped or a tenant mailed as landlord. All failures swallowed, delivery log has no reader (`RecordingController.php:1538-1545`). Sent from the creator, not the inspector. |
| 5a | Attendance: who attended (tenant / landlord or representative / agent) | **MISSING** | Only signature dispositions. | No attendance record, arrival, capacity, or non-lease attendee (`RentalInspectionSignature.php:173-184` forces tenant = `LeaseTenant`, landlord = owner contact). |
| 5b | Invitations sent, and when | **PARTLY** | Append-only per-recipient/per-channel log for scheduled/rescheduled/cancelled/reminder (`rental_inspection_notifications`; shown `show.blade.php:253-266`); reschedule history. | "Start now" inspections log nothing; off-system invitations (phone/WhatsApp by hand) cannot be recorded; WhatsApp is only "queued", never sent; log has no sender or content snapshot; invitation button is the generic `/portal` link. |
| 5c | Recorded "did not attend" outcome | **PARTLY** | Only as refusal preset "Not present for the walkthrough", stored as `refused` and printed as "Refused to sign" (`show.blade.php:646-650`; `RentalInspectionSetting.php:28-33`). | Non-attendance and refusal are one stored fact. |
| 6a | Side-by-side per room/item, both photo sets | **PARTLY** | Inspections tab shows predecessor vs current per item with photo strips + paired-photo viewer (`rental-inspection-recording.blade.php:1300-1318`, §20.15–§20.17, §40–§41). | The separate deposit-comparison page (out-inspection) shows text + a photo COUNT only, no photos (`deposit-comparison-page.blade.php:89,95`), and pairs against a different "baseline" than the tab (`RentalInspectionComparisonService.php:86-94` vs chain predecessor). |
| 6b | Differences marked | **PARTLY** | Three unrelated marks: needs-attention (tail only), Follow-up ticks, deposit-comparison findings. | No per-row "changed since move-in" marker on the side-by-side screen; "improved" hard-codes `'good'` though the baseline is agency-configurable (`RentalInspectionComparisonService.php:213`). |
| 6c | Deductions schedule built from marked items | **MISSING** | `rental_inspection_item_findings` (wear_and_tear / flagged + note, no amount) is the nearest thing. Deliberately stopped before money (`rental-inspection-form.md` §7.3). | Item, evidence, who bears it, amount, status. |
| 6d | …linked to the deposit refund | **MISSING** | Only `leases.deposit_amount` (agreed amount). | No deposit-held, interest, deductions, refund. **PARKED 6 Oct — belongs to the finance build (§45.0, Q1–Q4); the three standing "no deposit ledger" rulings (`rental-work-orders.md` §5.1a, `leases.md` §3.3, `rental-inspection-form.md` §7.3) stay in force until then.** |
| 6e | …and to a job card where repairs are needed | **PARTLY** | Follow-up raises fault report / work order / job card from a marked observation, photos linked (`rental-work-orders.md` §15; `RentalInspectionFollowUpService`). | Not driven by the comparison; nothing flows back to a deduction; Follow-up on an out-inspection lists defects that were already present at move-in (`:96-103`). `RentalJobCardService.php:685` hard-codes `paid_by=owner`, so an in-house repair can never be recorded as tenant-paid. |
| 7a | Full CRUD, soft delete only | **PARTLY** | Create (immediate/scheduled/tab), show, header edit, reschedule, cancel, archive, restore (`RentalInspectionController.php`). | Completed, signed inspection can be archived by any agent (`:737-745`, no status guard); restore wipes `archived_by`; archive/cancel never revoke the public link (§45.8 H1). |
| 7b | List: search/sort/filter/pagination/empty state | **HAVE** (2 defects) | Search address/tenant/creator/inspector; sort; status/type/date/inspector/discrepancy filters; 10–100 paging; 3 empty states; tiles, print, CSV/XLSX (`RentalInspectionController.php:206-352`). | Lease Hub's `?lease_id=` link is ignored (`leases/show.blade.php:500`); immediately-started inspections have NULL `scheduled_for`, sort last and can never match the date filter. |
| 7c | Own/branch/agency scoping at query layer | **PARTLY** | `{rentalInspection}` routes: binding + guard on ~40 actions; PDFs/scans/signatures on the private disk. | Property-level tab/recording endpoints check agency only (`RecordingController.php:55,90,1108`); create picker/`store` unscoped and `inspector_user_id` accepts another agency's user (`RentalInspectionController.php:50,105,133,171`); own-scope inspector sees the row then gets 403 (`RentalInspection.php:431-433` vs `AuthorizesRentalRecordScope.php:57`). |
| 7d | Navigation | **HAVE** | Sidebar, settings, property tab, Lease Hub, tenancy log. | — |
| 7e | Audit trail | **PARTLY** | Per-field attribution, immutable observations, reschedule rows, notification log. | No audit log: header edits, restore, public link issue/revoke, most status changes unrecorded. |
| 7f | Tenant/landlord portal visibility | **PARTLY** | API list (id/type/status/completed_at) (`ClientTenantRentalsController.php:92-107`, `ClientLandlordRentalsController.php:284-299`). | No portal screen, no report access, no scheduled date; the list includes draft/cancelled; invitation emails link to a portal page that shows no inspections. |

### 45.2 Design principles shared by every build below (decided here, so no lane re-decides)

1. **Facts, not conclusions.** Attendance, invitations, photo times and deductions are recorded as dated facts with who recorded them. No screen, PDF or email states what a fact means for anyone's rights.
2. **Append-only where it is evidence.** New evidence tables follow the signatures/findings pattern: never edited in place, a correction supersedes (`superseded_at`, `superseded_by_id`); archived, never deleted.
3. **Read-time derivation over stored status** (§3.1) wherever a value can be computed — due inspections, differences, refund balance preview.
4. **One source for each concept.** Reuse `rental_inspection_signatures` for signing, `rental_inspection_notifications` for invitations, `rental_inspection_item_findings` as the source of deduction lines, `SignedDocumentDistributionService` for copies, the existing Follow-up service for repair records. No parallel second implementation (conflict-map F-1/F-3).
5. **Agency settings, wizard in the same build.** Each new setting below carries its wizard row (control + `explain` + `affects` + `$request->has()`-guarded saver; `AgencySetupWizardController::currentValues()` arm per key).
6. **Migrations** use the reserved prefix `2026_10_13_1000nn` (lease series is `2026_10_12_*`); one lane per day regenerates `mysql-schema.sql` via `scripts/schema-dump.sh` (DEFINER stripped), others commit migrations without it (§45.10).

### 45.3 Build I-1 — Evidence: photo capture time, every item graded, report completeness (points 2a-2d, 1a guard) — amended 6 Oct: no photo enforcement (Q5)

**Data.** `rental_inspection_photos` gains `taken_at` (nullable datetime, immutable once set) and `taken_at_source` (string(10): `client` | `exif` | `server`). `created_at` stays as RECEIVED time. Backfill: existing rows get `taken_at = created_at`, source `server`. `rental_inspection_settings` gains `all_items_required_to_complete` (bool, default true) — applies to `in`/`out`/`interim`, never `ad_hoc`. **No photo-requirement settings (Johan, Q5: photos are not enforced).** *The every-item-graded guard is not part of the 6 Oct rulings: it stays as proposed in this spec and is a one-line switch (the setting) if Johan wants it off.*

**Capture-time rules (server-side, all upload endpoints: `storePhotos`, legacy `storePhoto`, tray, and the future mobile endpoint).**
1. Accept optional `captured_at` (ISO-8601 with offset) per file, sent once alongside `client_idempotency_key` (retry returns the existing row unchanged — never rewrites `taken_at`).
2. Otherwise read EXIF `DateTimeOriginal` (+ `OffsetTimeOriginal` when present, else the agency/app timezone) from the original upload BEFORE `PropertyImageStorer`/`ImageOrientationNormalizer` strip it; source `exif`.
3. Otherwise `taken_at = now()`, source `server`.
4. A client/EXIF time later than now + 5 minutes (clock skew) is rejected as a claim: stored as `server`, original claim written to the log, upload still succeeds (absorb, never fail an evidence upload). Both times are always visible: `created_at` is never hidden.
5. Spec §14.4/§14.5 are brought in line: the mobile contract carries `captured_at`; Andre's endpoint (not built) must send it.

**Display.** Per-photo caption `Taken 12 Aug 2026 14:03` on the recording tile, both sides of the compare viewer (per photo, replacing the inspection-date-only header `properties/show.blade.php:6640-6647`, with a fallback to `completed_at`/`created_at` for unscheduled inspections), the public report page, and the live inspection page. When `created_at` differs from `taken_at` by at least one minute the caption adds `· uploaded 16:20`; when source is `server` it reads `Uploaded 14:03` (never presented as capture time). **PDF:** photos stay out (Johan 23 Sep). Each item with a flaw prints `3 photos — taken 12 Aug 14:03 to 14:09` and each room prints its general-photo count and time range, beside the existing QR/link. Whether to go further (thumbnail appendix) is Q8 — **still open: build the capture time, the screens and the public page now; the PDF per-flaw line waits for Q8.**

**Guard (completion, in/out/interim).** Every checklist item must be graded (N/A counts) when `all_items_required_to_complete` — lists the unrecorded items by room, never a bare 422 (counting follows §20.22.3: the `CONDITION_PENDING` sentinel is not "recorded"). One guard exception class (like `RentalInspectionRequiredNotesMissingException`) returns the full offender list; the Complete button shows it as a checklist with jump links. Prevent-or-absorb: prevented at completion, absorbed everywhere else (an agent can always keep recording). **There is deliberately NO photo guard of any kind** — not per flaw, not per room, not per item (Johan, 6 Oct, Q5); photos stay optional, uploaded per inspection and tagged to a room or a section of a room as today.

**Report completeness (same build, evidence defects found while measuring).** Public report page also loads room-level photos and photo notes (`RentalInspectionPublicController.php:60-65`); PDF and public page print room notes and overall notes. **Needs Johan's go (reported defect, not changed here):** retired items currently vanish from a completed inspection's PDF/public page (`RentalInspectionReportPdfService.php:62-65`, `RentalInspectionPublicController.php:70-73`) — recommended fix: show an item if it has any observation in THIS inspection, retired or not.

**Scoping/CRUD.** No new entity; photos keep archive (no hard delete; observations untouched). Photo endpoints keep binding+guard. **Wizard:** the one setting above. **Multi-agency:** per-agency with a neutral default.

**Input space / tests.** Client time present / absent / malformed / future / ancient; EXIF present / absent / HEIC; offline retry with same key; photo moved between items (tag move must not touch `taken_at`); inspection with: no flaws, flaws with no photos (must still complete), every item N/A, unscheduled, an item left ungraded (blocked, listed), the setting off (completes). Files: migration, `RentalInspectionPhoto`, `RentalInspectionRecordingController` (upload methods + `complete`), `RentalInspection::markCompleted` + new exception, `PropertyImageStorer` (read-time seam only — shared by 5 modules, touch with care), `RentalInspectionSetting`, settings page + controller, `agency-onboarding-copy.php` + `currentValues`, report PDF service + blade, public controller + blade, recording partial + compare viewer in `properties/show.blade.php`.

**I-1 — BUILT 7 Oct 2026 (QA1), branch `cc1-inspections-i1-2026-10-07`.** Migrations `2026_10_13_100100` (photos: `taken_at`, `taken_at_source`, backfilled `created_at`/`server`) and `2026_10_13_100110` (setting `all_items_required_to_complete`, nullable → read-time default ON). `mysql-schema.sql` NOT regenerated here (§45.10: one lane per day regenerates it; `lane-test.sh` applies the delta).
- **Capture time.** `RentalInspectionPhotoCaptureTime::resolve()` is called by BOTH upload endpoints (`storePhotos` — item / room / tray — and the legacy `storePhoto`) with the ORIGINAL upload before `PropertyImageStorer::store()` (which is not touched). Order: client `captured_at` (one per file, parallel to `photos[]`, ISO-8601) → EXIF `DateTimeOriginal` (+ offset tag; PHP 8.2 exposes the offset as `UndefinedTag:0x9011`, newer builds as `OffsetTimeOriginal` — both read) → server time. Absorb, never fail: missing / malformed / non-string / pre-1970 (EXIF `0000:00:00` garbage) = no claim; a claim later than now + 5 min is stored as `server` and logged (`capture time in the future rejected`). Only JPEG EXIF is readable; HEIC/PNG/WebP with no client time honestly fall to `server`. Idempotent retry returns the existing row unchanged; `taken_at`/`taken_at_source` are immutable once set (model `updating` hook restores them; a tag move, archive or re-save cannot change them).
- **Display.** `RentalInspectionPhoto` appends `taken_caption` (`Taken 12 Aug 2026 14:03`, `… · uploaded 16:20` when ≥ 1 min apart, `Uploaded 12 Aug 2026 14:03` for `server`) and `taken_caption_short` (`12 Aug 14:03` / `Uploaded 14:03`) so every screen shows identical wording in the app timezone. Shown: overlay on the item-strip tiles (both sides) and the room gallery, tray tiles in Large mode (tooltip on all), the compare viewer header per photo (both sides; falls back to the inspection's date, then `completed_at`/`created_at`, for an unscheduled inspection — the old blank), the public report page. The agency-level inspection page shows photo COUNTS only (Johan 23 Sep: no photos on the completed report) and is unchanged.
- **Guard.** `RentalInspection::guardUngradedItems()` runs at `startAwaitingSignature()` AND `markCompleted()` (the same two transitions the required-notes guard already gates) for every type except `ad_hoc` (so the future `interim` type is covered automatically). An item is graded when this inspection holds a non-`CONDITION_PENDING` observation for it (N/A counts; a bare photo anchor does not; retired items are excluded) — exactly the screen's own "Not yet recorded" definition. Throws `RentalInspectionItemsUngradedException` (room-grouped list in walking order); the recording controller answers 409 `{message, ungraded_items:[{item_id,item_label,room_id,room_label}]}`; the property tab shows a panel under the Complete button (room names jump to the room; "Show only what is left" applies the Not-yet-recorded filter). Setting `all_items_required_to_complete` (settings page + Setup Wizard Rentals step + `currentValues()`; saver is the existing `RentalInspectionSettingsController::update`, `has()`-guarded so a wizard step that does not render it cannot wipe it). **Decision recorded:** the spec names "completion"; the guard also covers sending for signature, because an inspection signed while incomplete is the harm the guard exists to prevent and the required-notes guard already gates both transitions. Jump is to the ROOM (the screen has no per-item anchors).
- **Report completeness.** Public page now loads room-level photos and photo notes (agency scope lifted ONLY — archived photos stay hidden), renders a room that has only photos/a room note and no graded item, and shows each photo's caption + note. The signed PDF now prints the overall notes and each room's note (it printed neither). **Not built — waiting:** the PDF per-flaw line (`3 photos — taken … to …`) and per-room photo count/time range wait for Q8; nothing else on the PDF changed (photos stay out). **Not built — needs Johan's go (reported defect):** retired items still vanish from a completed inspection's PDF/public page.
- **No photo enforcement** of any kind (Q5).
- **Tests:** `RentalInspectionI1EvidenceTest` (25 tests / 120 assertions): client/EXIF/server order and precedence, offset handling, malformed / ancient / array / empty claims absorbed, future client + EXIF claim rejected and logged, 3-minute skew accepted, retry unchanged, legacy endpoint, tag move + direct write cannot change `taken_at`, caption wording per source, caption in the tab payload, guard list + wording, N/A counts, photo anchor is not a grade, retired excluded, setting off, ad-hoc exempt, no checklist = not blocked, setting default/save/subset-post, public page room photos + times + notes + room-only section + archived photo hidden + empty inspection, PDF overall/room notes.

### 45.4 Build I-2 — Checklist content: floor-to-ceiling baseline, custom room types, inventory starter lists (points 1a-1c) — baseline list and "Add missing standard items" RULED (Q9a); inventory starter lists wait for Q10

1. **Neutral baseline vocabulary.** Replace the generic fallback with a floor-to-ceiling baseline per room FAMILY (living, bedroom, kitchen, bathroom/WC, garage/outbuilding, outdoor, passage/other), each covering at minimum: Ceiling, Walls, Skirting, Floor covering, Windows (frames/glass/handles/burglar bars), Doors (door/frame/handle/lock), Light fittings, Light switches, Plug sockets — plus the family's own fittings (kitchen: sink & taps, stove/hob/oven, extractor, cupboards/tops; bathroom: bath/shower/basin/toilet/taps/extractor/mirror/geyser access). **Approved by Johan 6 Oct (Q9a)** — the floor-to-ceiling default list, with the exact items in the build's first commit and the usual review at QA1. HFC's current Kitchen/Bathroom/Bedroom/Garage overrides (transcribed from Retha's form, §19) stay as HFC's own saved override; defaults never overwrite an agency override.
2. **Existing properties — agent-triggered top-up.** Seeded properties never re-read settings (§19). Add a per-property action "Add missing standard items" on the room: shows the diff (items in the agency template the room lacks), agent confirms, adds them via `RentalInspectionItem::addToRoom()` (never reorders or retires existing items, never touches recorded observations). No bulk/automatic change of existing properties.
3. **Agency-addable room types.** `rental_inspection_settings.custom_room_types` (JSON list of `{key, label}`; key slug auto-generated, unique per agency, never collides with the 50 config keys). Merged into the room-type picker, the item-defaults editor and the walking-order list; both validators that today reject unknown types (`RecordingController.php:175-180`, `SettingsController.php:211-218`) accept the agency's own keys. Archive (not delete) a custom type: existing rooms keep it.
4. **Inventory starter lists.** `rental_inventory_settings` gains `room_type_starter_lines` (JSON: room type → ordered list of `{description, default_quantity}`), editable on the inventory settings screen, used only to pre-fill a NEW inventory's lines for a furnished let (agent can still add/remove; free-text stays). Inventory stays its own module — no merging of the two item models (**Q10 — still open: do not build this item until Johan rules**; items 1–3 and 5 are unblocked). A room on the inspection screen shows `Inventory: 12 items recorded` linking to that room's inventory lines.
5. **Wizard.** Custom room types, item defaults and starter lists remain "Deliberately NOT in the wizard" only if Johan says so (today they are link-out under a §19 exemption); recommended: promote the baseline-approval and "custom room types" row to a wizard link step so a new agency is told the checklist is theirs to shape.

Input space: duplicate custom label, label colliding with a standard type, empty label, 100 types, archived type still used by a room, top-up on a room with an item of the same label (match case-insensitively, skip, report). Files: `config/property-spaces.php` (read-only), `RentalInspectionSetting` (+`RentalInventorySetting`), both settings controllers/views, `RentalInspectionRecordingController` (room-type validation + top-up action), `RentalInspectionFormSeeder`, wizard config.

**BUILT-STATE NOTE — Build I-2, 2026-10-07 (cc6, lane B) — landed on QA1 only; items 1, 2, 3 and 5 built, item 4 WAITING on Q10.**

- **Item 1 — baseline.** `RentalInspectionSetting::DEFAULT_ROOM_TYPE_ITEMS` is now the nine-item floor-to-ceiling baseline (Ceiling, Walls, Skirting, Floor covering, Windows, Doors, Light fittings, Light switches, Plug sockets) and is the fallback for any type no family claims. `DEFAULT_ROOM_FAMILY_ITEMS` holds eight families (living, bedroom, kitchen, bathroom, outbuilding, covered_outdoor, outdoor, other); `ROOM_TYPE_FAMILY` maps **all 50** standard types to one (a test pins that none is forgotten); `DEFAULT_ROOM_TYPE_ITEMS_BY_TYPE` now holds only the six types whose fittings differ from their family (Garage, Parking, Pool, Scullery, Laundry Room, Outside Toilet). `defaultItemsForType()` resolves own list → family → baseline; the agency's own saved list for a type still wins over all of it (`roomTypeItemDefaultsFor()` merges overrides last), so a default never overwrites an override. The exact item lists are in the commit and in those constants — **Johan reviews them at QA1** (the spec's "usual review").
- **Premise correction, no effect on HFC.** This section said HFC's Kitchen/Bathroom/Bedroom/Garage lists "stay as HFC's own saved override". In the code they were the SYSTEM default (the old `DEFAULT_ROOM_TYPE_ITEMS_BY_TYPE`), and HFC's saved row (33 types, QA1 snapshot of live) already overrides all four with its own lists — so those keep winning exactly as before. The transcribed Retha lists are no longer a system default (they were HFC-specific wording; the families replace them). Of the five types they covered, the only one HFC had NOT saved is Yard, which now takes the `outdoor` family list instead of Retha's six-item Yard list; if Johan wants the old Yard list kept for HFC it is one settings save.
- **Item 2 — "Add missing standard items".** Per-room button on the property's inspection-items list (same row as Move up / Move down) → preview of the difference → agent unticks any item → "Add N items". `RentalInspectionItem::standardItemDiffFor()` / `addMissingStandardItems()`; endpoints `GET|POST /corex/properties/{property}/rental-inspection-rooms/{room}/missing-standard-items` (`RentalInspectionRoomChecklistController`, own file so it shares no lines with I-6a; same two scoping layers I-6a puts on property-level actions). Matching is case- and spacing-insensitive against **every** item on the room, **retired ones included** (a retired item was a decision; a top-up never undoes it) — those are reported as "already on this room". The server recomputes the difference on apply, so a stale preview or forged label adds nothing; a repeat adds nothing; new items are appended after the room's last item; existing items, order and observations are never touched. Not bulk, not automatic.
- **Item 3 — agency room types.** `rental_inspection_settings.custom_room_types` (JSON `{key, label, archived}`; migration `2026_10_13_100020`). Key = `custom_` + slug, generated once and never changed by a rename (so rooms never orphan) and structurally unable to collide with a standard name. Archive, never delete; restore. Both validators that rejected unknown types (`storeItem`, `assignType`) now accept the agency's own **active** types; the settings item-defaults editor and walking order accept archived ones too (existing rooms must still resolve). The room-type picker (both selects on the property page) lists the 50 + the agency's own. Rules live in one pure function (`mergeCustomRoomTypes`) so the settings page and the wizard cannot differ: blank ignored, label trimmed/collapsed/cut to 60, duplicate of a standard or own type (any case/spacing, archived included) skipped and reported, remove-and-re-add in one save keeps the same type, a posted key that is not ours is never trusted, cap 200. A room of a custom type is checklisted from the generic baseline until the agency gives it items in "Room type default items".
- **Item 5 — wizard.** "Your own room types" repeater added to the Rentals step's lists partial (control + "What it is" + "What this changes"), saved through `RentalListsWizardSaver::inspectionCustomRoomTypes` (no-op without its `custom_room_types_submitted` marker; renders ACTIVE types only; a type removed in the wizard is archived). **Johan's call recorded as built, not omitted** (§10a). Item defaults and walking order stay link-out under the §19 exemption, unchanged. A skipped duplicate is reported on the settings page; the wizard has no flash channel for it, so it is silently not added there.
- **Item 4 — inventory starter lists and the "Inventory: N items recorded" count — NOT BUILT, waiting on Q10.** Nothing of it exists.
- **Not done / for the record:** `mysql-schema.sql` not regenerated (one lane per day does it; the migration runs on top of the snapshot). `scripts/dev-check.ps1` cannot run on this box.

### 45.5 Build I-3 — Attendance record and invitation trail (point 5)

**Data (one new table, two additive columns).**
`rental_inspection_attendances` — `id`, `agency_id`, `rental_inspection_id`, `party_role` (`tenant`|`landlord`|`agent`|`other`), `party_contact_id` (nullable), `party_user_id` (nullable, agent), `attendee_name` (nullable; required when the attendee is not the party themselves), `attended_as` (`self`|`representative`|`co_occupant`|`other` — agency-labelled vocabulary like refusal reasons), `represents_party_role` (nullable), `outcome` (`attended`|`did_not_attend`), `arrived_at` (nullable time), `note` (nullable), `authority_document_path` (nullable, private disk, optional upload), `recorded_by_user_id`, `recorded_at`, `superseded_at`, `superseded_by_id`, `created_at`. Append-only; a correction supersedes. `rental_inspection_signatures` gains nullable `signed_by_name` + `signing_capacity` (so a landlord's representative can sign on the landlord's row, tied to an attendance row of capacity `representative`; today `capture()` forces landlord = owner contact, `:179-184`). `rental_inspection_notifications` gains `sent_by_user_id`, `subject_snapshot`, `occurred_at` and a new `event = 'invitation_manual'` / `channel = 'manual'` (+ `method` free text: phone, WhatsApp by hand, in person) so an invitation given off-system can be recorded with the real time.

**Behaviour.**
- **Attendance panel** on the inspection page (and recording screen header): one row per expected party (every lease tenant, landlord contact, the inspector/agent), pre-filled from the lease; the agent records `Attended` / `Did not attend` per party, adds representatives and other attendees, optional arrival time. A party row shows its invitation facts underneath, read-only, from the notification log: `Invited by email on 2 Oct 2026 10:14` / `Reminder sent 5 Oct` / `No invitation recorded` (a fact, not an accusation) / `Invitation recorded manually by <agent>, phone, 1 Oct 16:20`. Reschedules appear in the same line.
- **Start-now inspections** get an invitation trail the moment an attendance row is created: nothing is sent, the panel simply says no invitation was recorded and offers "Record an invitation given".
- **Completion guard** (in/out/interim): every expected party must have an attendance outcome. This replaces today's forced conflation: the signature step still requires a disposition per party, but when the attendance outcome is `did_not_attend` the signature row is rendered "No signature — did not attend" (never "Refused to sign"). The existing `not_present` refusal preset stays for old data; a migration backfills an attendance row (`did_not_attend`, source note `from signature record`) for every existing signature with reason `not_present`, and display is switched in the same build.
- **Where shown:** inspection page, signed PDF (a plain "Attendance" block: party · outcome · capacity · invitation facts), public report page, the distributed copy. Strings are Johan's (§45.11).
- **Agent identity fix (reported defect, in scope here):** the report names `created_by_user_id` as the agent (`RentalInspection.php:1214-1218`, `ReportPdfService.php:59`); this build names the inspector who attended.
- **Permissions:** `rental_inspections.record_attendance` (granted wherever `rental_inspections.create` is today); superseding another user's attendance record needs `rental_inspections.resolve_discrepancy`.
- **List screen:** new filters on the Rental Inspections list: `attendance = incomplete | any party did not attend`; column "Attended" (e.g. `2 of 3`). Sort by it. No new list screen (attendance is a child record, archived with its inspection).
- **Scoping:** child of `rental_inspections`; every endpoint resolves through `{rentalInspection}` binding + guard (never a bare id), authority documents on the private disk behind the same guard.
- **Wizard:** attendance vocabulary (`attended_as` labels) as a list control in the existing inspection-lists wizard step; whether the minimum-notice figure is also recorded on the inspection as a fact when an invitation is sent is a wording/records call for Johan (§45.11 item 5), not decided here.

Input space: party with no email/phone, tenant deleted after the invitation, two landlords (one signs, both invited — attendance row per invited landlord), representative without a name (prevented), `did_not_attend` then the same person turns up later (supersede), attendance recorded then inspection archived/restored, retry of the same record (idempotency key). Tests also cover create → supersede → re-record (§5a). Files: migration, 2 models, `RentalInspection` (`markCompleted`, `signatureSummaryRows`, summary rows), `RentalInspectionSignature::capture`, recording controller + routes, recording partial + show blade, PDF blade, public blade, notification service (event + sender), list controller/view, permissions config, wizard.

**I-3 — BUILT 7 Oct 2026 (QA1), branch `cc1-inspections-i3-2026-10-07`.** Migrations `2026_10_13_100200` (table `rental_inspection_attendances`), `…100210` (signatures: `signed_by_name`, `signing_capacity`), `…100220` (notifications: `sent_by_user_id`, `subject_snapshot`, `occurred_at`, `method`), `…100230` (setting `attended_as_labels`), `…100240` (backfill). `mysql-schema.sql` NOT regenerated (§45.10).
- **Attendance record.** `RentalInspectionAttendance` — append-only, one live row per EXPECTED party (each lease tenant, each invited landlord via the scheduling resolver plus the signing landlord, the inspector — falling back to the creator); extra people in the room are `party_role = other` rows. A correction writes a new row and marks the old `superseded_at`/`superseded_by_id`; **Withdraw** sets `superseded_at` with no replacement (kept on file, party returns to "not recorded"). Nothing is hard-deleted; the inspection's own archive carries the rows. `client_idempotency_key` makes a retry return the first row unchanged. All rules live in `RentalInspectionAttendanceService` (`record`, `withdraw`, `recordInvitation`, `board`, `missingParties`, list helpers). A party who did not attend is stored with no representative, name or arrival time; a representative needs a name and is recorded on the PARTY's own row (`attended_as = representative`, `represents_party_role`); an `other` person needs a name and can only have attended.
- **Invitation trail.** The board reads `rental_inspection_notifications`: the system's own scheduled / rescheduled / reminder / cancelled mails per party, plus manually recorded invitations (`event = invitation_manual`, `channel = manual`, free-text `method`, the real `occurred_at`, `sent_by_user_id`; a future time is refused). A start-now inspection simply reads "No invitation recorded" with a "Record an invitation given" action. Each line carries `printable`: the signed PDF and the public page print only invitations actually given (e-mail sent, reschedule, manual); reminders, cancellations and failed sends show on screen only — **whether they belong on the printed record is Johan's call (§45.11 item 2)**.
- **Completion guard** (`RentalInspection::guardAttendanceRecorded()`, in `markCompleted()` only, every type except `ad_hoc`, so `interim` is covered): every expected party needs a recorded outcome; `RentalInspectionAttendanceMissingException` → 409 `{message, missing_attendance:[{key,party_role,name}]}`; the panel is outlined and scrolled to. Deliberately not a setting (the spec gives none) — it only asks that the outcome be written down.
- **Signatures.** `signatureSummaryRows()` now carries each party's attendance; a refused row for a party recorded as `did_not_attend` reads **"No signature — did not attend"** (agency inspection page, recording screen, signed PDF, public page); a real refusal by someone who attended still reads "Refused to sign". The `not_present` refusal preset stays for old data; the backfill migration writes a `did_not_attend` row (note `from signature record`) for every live `not_present` refusal and leaves the signature itself untouched. A landlord's/tenant's representative can sign on the party's own row: `signed_by_name` must match a LIVE attendance record of capacity `representative` for that party (else 422); stored as the attendance record spells it with `signing_capacity = representative`, shown as "Signed by X (capacity)". The agent line on the report/public page now names the inspector (creator as fallback).
- **Screens.** Recording screen: an Attendance panel (per party: Attended / Did not attend / someone attended on their behalf + name + arrival, Change, Withdraw, the invitation line, Record an invitation given, add someone else) — read-only once completed/cancelled; each inspection in the tab payload carries `attendance_board`. Agency inspection page: read-only Attendance block + manual invitations in the "Notifications sent" log. Signed PDF: an Attendance table (party · outcome · invitation) before the rooms. Public page: an Attendance block (not shown to a logged-in user of ANOTHER agency — their scope would hide every row). Rental Inspections list: **Attended** column ("2 of 3"; "—" for ad-hoc/cancelled), sort by it, and an **Attendance** filter (Not fully recorded / Someone did not attend) on the same scoped query.
- **Routes / permissions.** `corex.rental-inspections.attendance.{show,store,withdraw,invitations.store}` under the existing `/corex/rental-inspections/{rentalInspection}` group (binding + `guardRentalRecordScope` on every action; an attendance id from another inspection 404s). New key `rental_inspections.record_attendance` (granted wherever `.create` is); correcting or withdrawing a record someone ELSE made also needs `rental_inspections.resolve_discrepancy`. (Follows this module's existing `/corex/…` session-route pattern rather than `/api/v1`.)
- **Wizard / settings.** The only new setting is the vocabulary: `attended_as_labels` (the four ways to attend — in person / on behalf of the party / co-occupant / other — fixed keys, agency-worded labels, neutral placeholder defaults) on Settings → Rental Inspections and in the Setup Wizard Rentals step's inspection-lists partial, saved through `RentalListsWizardSaver::inspectionAttendedAsLabels` (no-op without its `_submitted` marker, §6.1). Whether the minimum-notice figure is printed as a fact is NOT decided (§45.11 item 5).
- **Not built — waiting:** the authority-letter upload and rule (Q11) — name + capacity are recorded now. **Not changed:** the "did not attend" signature disposition still has to be recorded in the signing step (refuse → "Not present…", an admin-only action per the existing `sign_on_behalf` rule); attendance does not auto-create it.
- **Tests:** `RentalInspectionI3AttendanceTest` (34 tests / 197 assertions): expected parties (tenants, two landlords, inspector or creator), each outcome with who/when, representative and extra-attendee validation, malformed input, party with no contact details, correction/supersede/re-record after a no-show, withdraw, idempotent retry, closed inspections refused, archive/restore, record/resolve permissions, other agency / out-of-scope / foreign-id 404s, the completion guard list and the full happy-path completion, ad-hoc exempt, no-signature wording on page/PDF/public + a real refusal unchanged, the backfill (idempotent, evidence untouched), representative signing (matches the record / refused without / cannot refuse), system + reminder + manual invitation lines (printable vs on-screen), validation of manual invitations, inspector named on the report, list column/filters/sort, wording setting (default, save, blank keeps default, no-marker no-op), tab payload board, permission key granted.

### 45.6 Build I-4 — Copies to all parties on completion (point 4b) and signing roles for interim (point 4a) — interim signing RULED (Q7); non-attendee copy rule waits for Q12

1. **Recipient resolution fixed at the root** (reported defect, in scope): `RentalInspection::distributionRecipients()` stops using `$leaseTenant->contact` / `sellerOwnerContact()` and uses the ContactScope-bypassing, strict resolvers `RentalInspectionNotificationService::tenantContacts()/landlordContacts()` already built for scheduling mails (§43.2) — one resolver for invitations and for copies, so they can never disagree. All landlord/lessor contacts with an email receive a copy (signing stays single-landlord). A party with no email gets a `skipped` log row with the reason, never silently dropped.
2. **Agency copy.** `rental_inspection_settings.report_agency_copy_emails` (comma list, default empty) + toggle `report_copy_inspector` (default true) + `report_copy_creator` (default true). Recipients = tenant(s) + landlord(s) + agency copy addresses + inspector + creator, de-duplicated by address, each logged with role. Sent from the inspector's mailbox (fallback: creator, then shared mailer) — not always the creator.
3. **Visible and retryable.** A "Copies sent" panel on the inspection page reads `signed_document_distribution_logs` (today write-only): party · address · sent/failed/skipped · when. A failed or skipped recipient raises an in-app alert to the inspector and a per-recipient **Resend**. `complete()` still never fails the completion because of mail, but it no longer swallows silently (`RecordingController.php:1538-1545`).
4. **Trigger unchanged by design.** Completion stays an agent's deliberate click (auto-completing on the last signature would remove the review step); when every signature/attendance is in, the Complete button is highlighted with "Ready to complete". The auto-send fires once, on completion.
5. **Signing roles for `interim` — RULED 6 Oct (Q7): all parties sign every inspection.** The agent, the tenant(s) AND the landlord sign incoming, interim and outgoing alike, because it is part of the legal documentation. Concretely the three-party completion guard (`RentalInspection.php:452,502-534`, which tests `in`/`out` today) also covers `interim`; wet-ink, refusal and attendance paths are identical to In/Out. **`ad_hoc` is not covered by the ruling** and stays exempt from signing as today — flagged here for Johan to confirm, not changed.
6. **Remote signing link — NOT in this build (since built — §46).** A party who cannot attend signing later from their own device is I-9 (optional, §45.10); until then non-attendees follow the existing wet-ink/"awaiting wet-ink" path.
7. **Deductions schedule seam:** `SignedDocumentDistributionService` gets an `extraAttachments()` seam so I-8 can attach the issued deductions schedule without a second send path.

Wizard: copy addresses + the two toggles in the existing `auto_send_report_enabled` step row group. Scoping: copies' PDF is the private-disk file behind the guard; the public link is unchanged. Tests: tenant not created by the completing agent, landlord with no email, tenant listed also as landlord contact (one mail, correct role), two landlords, agency copy empty/one/many/duplicated, mail failure then resend, non-production redirect untouched. Files: `RentalInspection` (`distributionRecipients`, `distributionAgent`), `SignedDocumentDistributionService`, recording controller (`complete`, new `resendRecipient`), `RentalInspectionSetting` + settings + wizard, show blade panel, mail template.

**I-4 — BUILT 7 Oct 2026 (QA1), branch `cc4-inspections-i4-2026-10-07`.** One migration (3 nullable settings columns, `2026_10_13_100300`), no new permission key (the existing `rental_inspections.create` gates Resend, `manage_settings` gates the settings).
- **Recipients, fixed at the root (item 1).** `RentalInspection::distributionRecipients()` now uses `RentalInspectionNotificationService::tenantContacts()` / `landlordContacts()` — the same strict, ContactScope-bypassing resolvers the scheduling emails use — so an invitation and a copy can never disagree about who the parties are, and a tenant the completing agent's own contact scope cannot see still gets a copy. Every landlord with an email gets one (signing stays single-landlord). De-duplicated by address (one mail to a person listed twice, under the first role: tenant → landlord → agency → inspector → creator). A party with no usable address is not dropped: the new optional `App\Contracts\ReportsUnreachableRecipients` makes the shared service write a **`skipped` row with the reason** ("No email address on file") on a full send. Inventory does not implement it and is unchanged.
- **Agency copy + toggles (item 2).** `rental_inspection_settings.report_agency_copy_emails` (comma/semicolon/space/new-line list, validated as a whole on save — one bad address saves nothing and names it; at most 10), `report_copy_inspector` (default on), `report_copy_creator` (default on). Settings page card "Who else gets a copy of the report" + Setup Wizard rows on the Rentals inspection step (`updateReportCopies`, every field `has()`-guarded so a step that omits one never wipes it; `currentValues()` arms added). Copies go out **from the inspector's mailbox** (`distributionAgent()` = inspector, then creator, then the shared mailer). The shared service no longer CCs the sending agent when that agent is already a recipient (no double copy); with both toggles off the sender is still CC'd, per the standing §41 ruling.
- **Visible and retryable (item 3).** `App\Services\Rentals\RentalInspectionCopiesService` now owns file-and-send (the recording controller's private method is gone; `complete()` and `resendReport()` delegate). A **"Copies sent" panel** on the inspection page (`partials/_copies-sent.blade.php`, plain Blade) shows the latest outcome per recipient — who, address, sent / failed / skipped with the reason, when, automatic or manual — read from `signed_document_distribution_logs`. A failed or skipped copy shows a per-recipient **Resend** (`POST …/resend-recipient`, `RentalInspectionCopiesController`) that resolves the recipient on the server from a delivery-log row of THIS inspection (never from a posted address) and mails only that person. Any failed/skipped copy — or the whole send failing — raises an in-app alert to the inspector (`RentalInspectionCopiesNotDelivered`, database channel only; the inspector falls back to the creator). `complete()` still never fails because of mail. The Inspections tab's "Resend report" popover is untouched; its JSON response keeps `results` (one line per address) and now also returns `skipped` separately.
- **Trigger unchanged (item 4)** — completion stays the agent's deliberate click; the auto-send fires once, on completion.
- **Interim signing (item 5)** — already in place from I-5 (`markCompleted()` / the signing window cover `interim`); this build proves it (interim cannot complete without tenant, landlord AND agent; `ad_hoc` stays unsigned) and its report goes out like any other.
- **Non-attendee copy (Q12)** — unchanged: every party still gets the copy. One-rule change when ruled.
- **NOT built, and why:** (a) the "Ready to complete" highlight on the Complete button (item 4) — it lives in the recording screen's Alpine, the exact file cc1's I-3 is rewriting, and the "every attendance is in" half depends on I-3's attendance record; build it with or after I-3. (b) the `extraAttachments()` seam (item 7) — its only consumer is the parked I-8; adding an unused hook now would be dead code. (c) Remote signing link (Q15/I-9) — as specced.
- **I-4 top-up — "Ready to complete" pill, BUILT 7 Oct 2026 (cc1, branch `cc1-inspections-i4-topup-2026-10-07`)** — the one item (a) above that was deferred. A green **Ready to complete** pill beside the Complete button (recording screen, an inspection awaiting signature) once every party has a signing outcome and none is still waiting for a paper scan (`awaiting_wet_ink`), the agent has signed, and the attendance board is complete (I-3). It is `readyToComplete(section)` in the property page's recording component — client-side on purpose, so it reacts the moment the agent signs or records attendance, before any reload — and display-only: the server re-checks everything on Complete, and the pill deliberately does NOT claim unrecorded items (I-1) or required notes are done, because only the server decides those. Test: `RentalInspectionReadyToCompletePillTest` (the pill and the five facts its rule reads are on the page); live behaviour verified in a real browser on QA1. (b) the `extraAttachments()` seam remains unbuilt (parked I-8's only consumer).
- **Files:** migration `2026_10_13_100300_…`; `RentalInspectionSetting` (3 columns, resolvers, `parseEmailList`); `RentalInspection` (recipients, unreachable, agent); `SignedDocumentDistributionService` (`onlyEmails`, skipped rows, CC de-dupe); `Contracts/ReportsUnreachableRecipients`; `RentalInspectionCopiesService`; `RentalInspectionCopiesController`; `Notifications/RentalInspectionCopiesNotDelivered`; `RentalInspectionRecordingController` (`complete`, `resendReport` delegate); `RentalInspectionSettingsController` (`updateReportCopies`); `AgencySetupWizardController::currentValues`; `config/agency-onboarding-copy.php`; `routes/web.php`; views `corex/rental-inspections/partials/_copies-sent`, `show` (one include), `corex/settings/rental-inspections`.
- **Tests:** `tests/Feature/RentalInspections/RentalInspectionI4CopiesTest.php` — recipients (hidden-from-agent tenant, several tenants/landlords, no email, tenant-also-landlord, agency copy none/one/many/duplicated/mixed case, inspector/creator toggles and dedupe), completion mail + log + panel, auto-send off, skipped party + alert, failed send + alert + per-recipient resend, refused/foreign/unknown/uncompleted resend targets, a later-added email, the tab endpoint shape, interim signing all three, ad-hoc unsigned, settings save/normalise/clear/reject/never-wipe/defaults, cross-agency refusal. Mail is always `Mail::fake()` with the redirect set to the one permitted test address.

### 45.7 Build I-5 — Due dates: In/Out raised from the lease, Interim from dates the AGENCY loads (points 3a-3c) — re-cut 6 Oct (Q6)

**Johan's ruling (6 Oct, Q6): NO automatic scheduling of interim inspections.** Not every agency does interims. The agency loads its
own interim inspection dates when it wants them and CoreX reminds from the loaded dates. Revisit when more agencies are on. This
**removes** the interim interval setting (`interim_interval_months`, default 6) and the computed interim due from the earlier design; nothing
in CoreX creates, proposes or computes an interim date. **In and Out are not touched by the ruling** and stay as designed below
(a computed due list with agent reminders — still no tenant e-mail and no pre-created drafts).

**Design decision (unchanged): computed/loaded due list + reminders, not pre-created drafts.** Pre-creating future inspection rows would inflate the Command Centre "Inspections due" tile (`RentalCommandCentreService.php:243,339`), break the one-live-tail rule (`previous_inspection_id` UNIQUE, `tabPayloadFor`) and invite tenants to a date nobody chose.

1. **New type `interim`** (`rental_inspections.type` is `string(10)`, no schema change). It joins the chain between In and Out (`startNext()` accepts it), is signed by all three parties like In/Out (§45.6 item 5, Q7), and compares against the previous inspection. `ad_hoc` stays for unplanned checks.
2. **Loaded interim dates — `rental_inspection_planned_dates` (new, full CRUD).** `id`, `agency_id`, `lease_id`, `property_id` (denormalised as on inspections), `type` (`interim`; room for others later), `planned_on` (date, required), `note`, `status` (`planned` | `booked` | `done` | `skipped`), `skipped_reason`, `rental_inspection_id` (nullable — set when the agent books/starts the inspection from it; `done` when that inspection completes), `created_by_user_id`, timestamps, `deleted_at` + `archived_by_user_id` (archive/restore, never hard delete). An agent adds a date (or several at once) against a lease; edits (moves) it; marks it skipped with a reason; archives and restores it. **Book from this date** opens the existing Schedule action (§43) prefilled with the date. Loading is always a deliberate agency action — there is no generator, no "every N months" helper and no import in this build (a CSV load of many leases' dates is a possible later addition if Johan wants it; not specced here).
3. **Reminders from loaded dates only — daily command `rentals:send-planned-inspection-reminders`.** For each `planned`/`booked` date it writes one append-only `rental_inspection_planned_date_notices` row per milestone (`planned_date_id`, `milestone` = `lead` | `due` | `overdue`, `recipient_user_id`, `channel`, `status`, `created_at`; unique per date+milestone → idempotent, and a missed cron tick is caught up, unlike the exact-day match in `SendRentalInspectionReminders.php:52-55`) and sends the responsible agent (`properties.agent_id`, falling back to the lease creator) an in-app notification + e-mail: "Interim inspection due 3 Dec 2026 — 14 Jackson St". **It never e-mails a tenant or landlord** — the tenant is invited when the agent books, through the existing §43 flow. Dates loaded in the past are shown as overdue on the list but not e-mailed in bulk (go-live safety: loading a backlog never floods an agent).
4. **In and Out (unchanged): `RentalInspectionDueService` (read-time, no table), now In and Out only.** For each ACTIVE lease returns `{type, due_on, reason}`: `in` — no completed In on the property's tenancy chain (`previous_lease_id` walk, so a renewal never re-prompts In — fixes `LeaseHubService.php:28,108`), due on `start_date`; `out` — when `move_out_date` is set (or `end_date` for fixed terms), due that date; month-to-month with no notice has no Out due. An already-open inspection of that type satisfies "due". The same daily scan writes append-only `rental_inspection_due_notices` (`lease_id`, `type`, `due_on`, `milestone`, `recipient_user_id`, `channel`, `status`, `created_at`; unique per lease+type+due_on+milestone) and reminds the agent only. It scans ACTIVE leases, so it needs no "lease became active" hook (activation happens in five places and has no event) and does not depend on the lease e-sign builds.
5. **Where it shows.** One new "Due" tab on the Rental Inspections list combining In/Out due items and loaded interim dates (type, due date, lease, agent, status; row actions Schedule/Start/Book prefilled); Command Centre: the existing `start_inspection` rule becomes the In rule plus new Out and loaded-interim rules (tile counts only due-within-lead or overdue, never future); the Lease Hub next-step card is **deferred until the lease hub lane (L3a) lands** (to avoid editing `LeaseHubService::nextStep()` concurrently), and loaded dates are managed from the Rental Inspections module screens, not from `corex/leases/show.blade.php`, for the same reason.
6. **Settings (`RentalInspectionSetting`, agency, wizard row each):** `planned_date_lead_days` (default 14), `out_due_lead_days` (default 7), `raise_due_inspections_enabled` (In/Out only; default true). **No interval setting.** Permission `rental_inspections.manage_planned_dates`, granted wherever `rental_inspections.create` is today.
7. **`start()`/`schedule()` guards** still require an ACTIVE lease (`RentalInspection.php:799,845`). With the lease e-sign builds a lease becomes active on acceptance, normally before `start_date`, so an In can be booked before move-in; if Johan wants an In booked while the lease is still "out for signing" these guards must change — **open question Q14; until ruled, the guards stay as they are.**
8. **List/CRUD standard — "Planned dates" and the "Due" tab:** search (property address, tenant, agent, note), sort (date ascending default, property, type, status, days overdue), filter (type, status planned/booked/done/skipped/overdue, date range, agent, own/branch/agency pill), pagination 25/50/100, empty states ("no dates loaded yet — add one" vs "no match"), CSV/print export on the same scoped query, archive/restore with who/when. Scoping at the query layer through lease → property exactly as the inspections list does (`visibleTo`); every show/edit/archive/book action resolves the date through route binding + the shared guard — a direct URL by id to another agent's/branch's/agency's date is refused, not merely unlinked.

Input space: lease with no start date, month-to-month, take-on lease, lease renewed after dates were loaded (dates stay attached to the lease they were loaded on; the list shows the tenancy), a date loaded on a lease that is then cancelled/expired (shown as "lease ended", never mailed), the same date loaded twice (rejected with a plain message), a date in the past, an inspection booked from a date then cancelled (date returns to `planned`), property without an agent, agent deactivated, two leases on one property (draft renewal + active). Files: `RentalInspection` (const + `startNext`), new `rental_inspection_planned_dates` + notices tables/models/controller/views/migration, `RentalInspectionDueService` (In/Out only), the two daily commands + one line each in `routes/console.php`, `RentalCommandCentreService`, list controller/view ("Due" tab), `RentalInspectionSetting` + settings + wizard, permissions config, notification service labels (`interim`).

**BUILT-STATE NOTE — Build I-5, 2026-10-07 (cc6, lane B) — landed on QA1 only; everything in §45.7 built except the one waiting guard (Q14).**

- **Interim type.** `RentalInspection::TYPE_INTERIM = 'interim'`; `startNext()` accepts it; it joins the chain. **Signing:** the two in/out signing checks (`startAwaitingSignature`, `markCompleted`) now include interim, because §45.7 item 1 says interim is signed by all three parties and leaving it out would let an interim complete with no signatures at all. This is the same one-token change §45.6 item 5 describes for I-4 — lane A (cc1) will find it already in when I-4 lands; ad-hoc is untouched (still unsigned, flagged as before). The booking forms, the list type filter and the create/next validators accept `interim`; observations recorded on an interim carry source `ad_hoc` (the existing default branch), the recording screen labels it "Routine-inspection" (the property tab's label map has no interim entry — left alone, that partial is lane A's; reported).
- **Loaded dates.** `rental_inspection_planned_dates` (full CRUD: load one or several at once, move while still planned, skip with a required reason + reopen, archive/restore with who/when — soft delete only), `…_planned_date_notices`, `rental_inspection_due_notices` (append-only, unique per milestone). A repeat date on the same tenancy is refused with a plain message; an archived twin is restored instead of colliding (create → archive → load again proven). A booked date can only have its note edited; a date on an ended lease is shown "Lease ended" and never mailed. **Nothing computes an interim date** — no interval setting, no generator, no CSV load.
- **Due tab** `/corex/rental-inspections/due` (tab switch on both the list and the Due page): In/Out items from `RentalInspectionDueService` + the loaded dates. Search (property address, tenant, agent, note), sort (due date default, property, type, status, days overdue), filter (type, status incl. overdue/due now/upcoming/booked/done/skipped/lease ended, due-date range, agent, own/branch/agency pill, archived), pages 25/50/100, two real empty states, print + CSV/XLSX on the same rows. Row actions: **Book from this date** / **Schedule / Start** open the ordinary start/schedule form prefilled (type, date, `planned_date_id`); the server re-checks the id (same visible date, still planned, same lease and type) and ignores anything else.
- **Scoping.** Loaded dates: `RentalInspectionPlannedDate::scopeVisibleTo()` + route binding through it (a colleague's/another agency's date by id is a 404) under the `rental_inspections` data scope — own = a date the user loaded or one on a property they are the agent on; branch = the property's branch. In/Out items: the same ceiling applied to the lease query. Command Centre: same.
- **Due service.** In = ACTIVE lease, no completed In anywhere on its `previous_lease_id` chain (fixes the old single-lease check: a renewal no longer re-prompts In), none already open; due on `start_date`. Out = a move-out date, else a fixed term's end date; month-to-month with no notice has none; none already open or completed. States: overdue / due (within the lead window; In has no lead) / upcoming.
- **Reminders** (agent only — the property's agent, else the lease creator; never a tenant or landlord): `rentals:send-planned-inspection-reminders` 07:20 and `rentals:send-due-inspection-reminders` 07:25 (`routes/console.php`, inside an I-5 marker). In-app + email (`RentalInspectionDueReminder`; subject e.g. "Interim inspection due 3 Dec 2026 — 14 Jackson St"). One notice row per milestone (lead/due/overdue), unique, so a re-run or a missed tick is harmless; catch-up sends only the latest reached milestone (earlier ones recorded as skipped). **Go-live safety:** a loaded date only mails for milestones AFTER the day it was loaded (a backlog loaded today is overdue on the list but never a flood of mails); a computed In/Out item whose milestone is more than 3 days old when reminders first reach it is recorded as skipped, not sent. The 3-day figure is an operational go-live guard, not an agency setting — flagged.
- **Status follow-through** (`RentalInspectionPlannedDateObserver`, one `AppServiceProvider` line in an I-5 marker): booked inspection completes → date **done**; cancelled or archived → date back to **planned** (and reminded again).
- **Command Centre.** The old `start_inspection` rule is now the In rule (chain-aware) plus a move-out rule (`start_out_inspection`) and a loaded-date rule (`interim_inspection_due`), all from the one service, only for items within their lead window or overdue — never future; the "inspections due" tile and its list use the same property set (`dueNowPropertyIds`).
- **Settings + wizard** (`planned_date_lead_days` 14, `out_due_lead_days` 7, `raise_due_inspections_enabled` on; one narrow `updateDueDates` saver, every field has()/filled()-guarded; settings section "Due inspections and reminders"; three wizard controls each with explain + affects; `currentValues` arms). Permission `rental_inspections.manage_planned_dates` (granted wherever `.create` is: branch managers and agents; admin has all).
- **Waiting, not built:** Q14 — an In booked while the lease is still out for signing. `start()`/`schedule()` still require an ACTIVE lease, unchanged.
- **Reported, not changed:** the Lease Hub next-step card and any block on `corex/leases/show.blade.php` stay deferred until lease-hub lane L3a lands, as this section says; interim has no label on the property tab's recording screen (above); one open interim per tenancy at a time (the existing "already under way for this tenancy" rule), so a second loaded date can only be booked after the first inspection is completed or cancelled.

### 45.7a Build I-7 — The move-out comparison: one screen, both photo sets, differences marked, classified (points 6a-6b, no money)

**One screen, not three.** The out-inspection's "Deposit comparison" page (`/corex/rental-inspections/{id}/deposit-comparison`) is rebuilt as the **Move-out comparison**; the property tab keeps its "vs previous" view for day-to-day walking. Baseline for the comparison page = the tenancy's completed **In** inspection (the standard says "outgoing vs incoming"), resolved along the `previous_lease_id` chain; interim inspections and fault reports appear as context between the two, never as the baseline. (Today the page picks "this lease's latest completed In" while the tab uses the chain predecessor, so they can disagree once interims exist — `RentalInspectionComparisonService.php:86-94`.)

1. **Side-by-side per room, per item** — one row per item: baseline cell | outgoing cell, each with condition (agency LABEL, not the raw key `ucfirst()` the page prints today, `deposit-comparison-page.blade.php:86-94`), note, photo strip with capture times (I-1), photo notes. The same match groups and viewer as the tab (`openCompareViewer`) — photos open paired, not as a count. Room-level general photos shown per room, both sides. Rooms in walking order; collapse per room; "Differences only" toggle.
2. **Differences marked — derived at read time, never stored.** A row is marked when the outgoing condition's severity bucket (`RentalInspectionSetting::conditionSeverityFor()`, agency-configured — replacing the hard-coded `'good'` test at `RentalInspectionComparisonService.php:213`) is worse than the baseline's (green → amber → red), or the bucket is the same but the state differs (marked *Different*, never auto-called worse), or the item exists on one side only, or one side is N/A. Labels: *Worse than move-in* · *Different from move-in* · *Same as move-in (already present)* · *Better* · *New item* · *Not at move-out*. Keys, remotes and meter readings keep their header diff.
3. **Context on the same row** (fixes "damp wall reported, never repaired"): every observation of source `tenant_fault_report`/interim/ad-hoc on that item, every fault report and work order raised against the item (status, closed date, who did it) — one compact timeline per marked row. Money figures are NOT shown here (I-8 owns money).
4. **Classification (a human judgement, append-only).** `rental_inspection_item_findings.disposition` is `string(20)` (`2026_09_21_120000…:40`) so no schema change: existing `wear_and_tear` and `flagged` stay valid; new values `pre_existing`, `landlord_cost`, `charge_tenant` (all ≤20 chars). Note stays required on every finding; supersede chain unchanged. A finding can now be recorded on any marked row (today only on `declined`, `RentalInspectionComparisonService.php:297-305`). `charge_tenant` rows are recorded as the agent's judgement and are the intended SOURCE of deduction lines when the finance build (parked I-8, §45.0) is built; nothing in the current plan turns them into money.
5. **Follow-up becomes comparison-aware:** on an out-inspection the Follow-up list (`RentalInspectionFollowUpService.php:96-103`) shows items that are worse than move-in first and labels already-present defects "Already present at move-in", preselected off — raising a repair record for an old defect stays possible but is deliberate (Q to be confirmed with the build prompt; low risk).
6. **Inventory comparison** stays its own page (`RentalInventoryComparisonService`); this screen links to it (the finance build can pull both into one schedule later).

**List/CRUD/scoping.** Child screen of an inspection: no list of its own. Search within the page (item/room text), filter (room, difference type, classification state), sort (walking order default, severity), nothing to paginate beyond collapsed rooms (page loads per room beyond 150 items — N+1 on photos fixed with eager-loading, `RentalInspectionComparisonController.php:42`). Read through `{rentalInspection}` binding + guard; the baseline inspection is resolved from the same lease chain, never from a posted id. Findings recorded with `rental_inspections.review_deposit_comparison` (existing). Archive of a finding = supersede with a note (no hard delete). **Wizard:** none (no new setting). **Tests:** In only/Out only, no baseline at all (first inspection ever), baseline archived, agency with a non-`good` baseline state, item retired after In, renamed room, 0/1/many photos each side, finding superseded twice, tenant fault report between. Files: `RentalInspectionComparisonService`, `RentalInspectionComparisonController`, comparison view + partials, `RentalInspectionItemFinding`, `RentalInspectionFollowUpService` (ordering/label only), compare-viewer include in `properties/show.blade.php`.

**BUILT-STATE NOTE — Build I-7, 2026-10-07 (cc6, lane B) — landed on QA1 only; everything in §45.7a built, with ONE deliberate deviation (the photo viewer, below).**

- **One screen.** `/corex/rental-inspections/{id}/deposit-comparison` (route name unchanged) is now the **Move-out comparison**; the property tab keeps its own "vs previous" view. Baseline = the tenancy's completed In, found along `previous_lease_id` (nearest lease first; the old single-lease lookup is replaced; archived and not-completed Ins still serve as before). Interim inspections and fault reports are row context only. The baseline is worked out from the lease — never from an id in the request.
- **Differences, read time, never stored** (`RentalInspectionComparisonService::differenceFor()`): the agency's own severity buckets (`conditionSeverityFor`: blue/grey < amber < red; an unmapped state ranks red, the same cautious default the colours use) — higher outgoing bucket = **Worse than move-in**; lower = **Better**; same bucket but a different state = **Different from move-in** (never auto-called worse); identical = **Same as move-in** (an unchanged defect reads **Same as move-in (already present)**); recorded at move-in only, or N/A at move-out = **Not at move-out**; recorded at move-out only, or N/A at move-in = **New item**; N/A on both sides is not a row. Marked rows = worse, different, new, not-at-move-out. This replaces the hard-coded `'good'` test; the old `compareItems()` labels now follow the same rule (worse and different stay DECLINED, better = IMPROVED). **Consequence, by design of the rule:** fair → good (both calm) used to read "improved" and now reads "different"; one existing test was updated and a new one pins it.
- **Rows.** Rooms in the property's room order (`sort_order`, then id), items in their own order, items without a room last under "General"; each condition is the agency's LABEL on a chip in its severity colour; the note; the photo strip with capture times (I-1 captions) and the photo's own note; room-level general photos per room, both sides; each room collapses; "Differences only"; count chips that filter. Search (item, room, note), filters (room, difference, classification state), sort (walking order or worst first). Keys/remotes/meters keep their header diff.
- **Context on a marked row** ("What happened in between"): observations from the tenancy's other inspections of source tenant fault report or ad-hoc/interim, every fault report and work order raised against the item (status, closed date, "by <supplier>" or "by our team"), linked. **No money anywhere.**
- **Classification** (append-only, a note is required, supersede chain unchanged): the existing `wear_and_tear` and `flagged` plus `pre_existing`, `landlord_cost`, `charge_tenant` (`disposition` is string(20) — no schema change), recordable on ANY marked row. Screen words: Fair wear and tear · Flagged as a genuine difference · Pre-existing · Landlord's responsibility · Charge to tenant — the last three follow this section's wording and are **provisional until Johan approves the exact strings (§45.11 item 4)**. `charge_tenant` is a recorded judgement and the intended source of deduction lines for the parked finance build; nothing here turns it into money.
- **Follow-up is comparison-aware** (`RentalInspectionFollowUpService`, ordering and label only): on an out-inspection the list shows what got worse since move-in first, then different/new, and a defect that was already present at move-in last, labelled **Already present at move-in**; nothing is preselected and every item keeps every action. In-inspections are untouched.
- **Inventory** has its own comparison; this screen links to it when the lease has a completed inventory.
- **DEVIATION — the photo viewer (reported, needs a decision).** §45.7a item 1 says to reuse the property tab's `openCompareViewer`. That viewer is bound to the property page's own state (`chainTail`/`chainPredecessor`, the page's item/photo/match arrays, tag and note panels, its mutation endpoints) — reusing it on a separate screen means embedding the whole property page's Alpine app, or deep-linking into the tab (which would pair predecessor-vs-current, not In-vs-Out, the very disagreement §45.7a exists to remove once interims exist), and either needs edits inside the 11,000-line shared `properties/show.blade.php`. I built a small paired viewer ON the comparison screen instead: move-in beside move-out for the row you clicked, photos linked by the existing photo match groups first, then the unmatched ones, with each photo's capture time and note; arrows or buttons step through, Esc closes. It reads the same match groups, so a pair made on the property tab shows paired here. It has no tagging, noting or linking controls (those stay on the tab). If Johan wants the tab's viewer itself, say so — that is a separate, larger piece of work in lane A's file.
- **Not changed / reported:** `RentalInspectionComparisonService::compareItems()` stays as the legacy API (used by `recordFinding()` and its tests). The comparison builds the vocabulary lookup once per service instance, so a 40-item inspection costs a bounded handful of queries (a test pins it).

### 45.7b Build I-8 — Deposit settlement and deductions schedule (points 6c-6e). **PARKED 6 Oct 2026 — belongs to the finance build; REMOVED from the current plan (§45.0, Q1–Q4).**

> **PARKED.** Johan, 6 Oct 15:11: deposit, the deductions list, who finalises it and the amount charged belong to the finance build and are not to be built now. Everything below is kept only as the starting design for that build; none of it is scheduled, and the "no deposit ledger" rulings it would amend (`rental-work-orders.md` §5.1a, `leases.md` §3.3, `rental-inspection-form.md` §7.3) remain in force. Question numbers inside this subsection use the OLD numbering (old Q9–Q12 = printed Q1–Q4). **Revisit when the finance side is built.**

**Scope as recommended (Q9b): record facts, move no money.** This is a record of the deposit settlement, not a trust ledger. It amends (in the same commit as the build) the three standing rulings — `rental-work-orders.md` §5.1a, `leases.md` §3.3, `rental-inspection-form.md` §7.3/§7.4 — and notes in `rental-money.md` that a deposit refund is a payment out that spec does not model.

**Data (two new tables keyed by `lease_id`; no column added to `leases`, so lease e-sign M2 is never touched).**
- `rental_deposit_settlements` — `id`, `agency_id`, `lease_id` (unique per tenancy term), `out_inspection_id` (nullable), `agreed_deposit_amount` (copied from `leases.deposit_amount` for reference, never edited here), `deposit_held_amount` (decimal(12,2), entered; prefilled from the agreed amount and labelled "agreed — confirm what was actually held"), `interest_amount` (decimal(12,2) nullable, entered; a "Use the deposit interest calculator" prefill links the existing `DepositInterestCalculatorService`, never auto-applied), `status` (`draft` | `issued` | `settled`), `issued_at`, `issued_by_user_id`, `approved_by_user_id` (Q12), `refund_due_snapshot` (frozen at issue), `refund_paid_on` (nullable date), `refund_reference` (free text), `refund_recorded_by_user_id`, `note`, `created_by_user_id`, timestamps, `deleted_at` + `archived_by_user_id`. Refund balance = held + interest − sum(active lines), computed live in draft, frozen at issue.
- `rental_deposit_deduction_lines` — `id`, `agency_id`, `settlement_id`, `source` (`finding` | `inventory` | `keys_meters` | `repair` | `manual`), `rental_inspection_id`, `rental_inspection_item_id` (nullable), `finding_id` (nullable), `inventory_line_id` (nullable), `rental_work_order_id` / `rental_job_card_id` (nullable), `description` (required), `amount` (decimal(10,2), required, ≥0), `amount_basis` (`estimate` | `quote` | `invoice` | `actual`), `suggested_amount` (nullable, what the system pre-filled and from where — Q10), `bearer` (`tenant`; reserved for later), `status` (`proposed` | `included` | `waived`), `waived_reason`, `created_by_user_id`, `superseded_at`, `superseded_by_id`, timestamps, `deleted_at`. Issued settlements are immutable: a change supersedes lines/versions and re-issues; nothing is edited in place.

**Behaviour.**
1. **Build from marked items.** "Start deductions schedule" on an out-inspection (or its comparison page) creates the settlement and offers every `charge_tenant` finding (I-7), inventory shortfalls/damage (`rental_inventory_line_dispositions`), and keys/remotes/meter differences as `proposed` lines; the agent includes, edits or waives each with a reason. Manual line allowed (cleaning, arrears are out of scope until Rental Money). Pre-existing/fair-wear/landlord-cost findings never create lines.
2. **Repairs link to job cards both ways.** On a line (or a marked comparison row) **Raise repair** uses the existing Follow-up creation (`RentalInspectionFollowUpService`, `rental-work-orders.md` §15) with the item FKs — fault report, work order or job card; the line stores the created `work_order_id`/`job_card_id`. Conversely, `RentalWorkOrderClosed` (dispatched at `RentalWorkOrder.php:788`) lets a listener offer "Add to deductions schedule" on a closed repair linked to this lease/item — an offer only, never an automatic charge. The pre-filled amount follows Q10 (default: the owner-facing total) and the agent confirms. **I-8 never edits `paid_by`, `RentalCloseGuards`, `RentalJobCardService::complete()` or `RentalReportService::workOrders()`** (maintenance Build 3's files); the deduction line, not `paid_by`, records who ultimately bears a cost.
3. **Issue.** Per Q12 a manager/principal approves; issuing freezes the schedule, generates a PDF (schedule + the evidence links/QR already used by the inspection report, attendance block from I-3), files it to the property documents via `SignedDocumentDistributionService` and sends it as a copy to tenant(s), landlord(s) and the agency via the I-4 seam (Q11b tenant visibility; landlord always). Recording **refund paid** (date, reference) moves it to `settled`. Neither step moves money.
4. **Written exception to "tenants never see a price":** the *issued* settlement is the only money a tenant sees — never job-card cost, markup or selling lines; the exception is stated in this section and covered by a test in the style of `CrewPayloadNeverCarriesSellingTest`.
5. **Tenancy log:** settlement events appear in the Lease Hub tenancy log through existing `LeaseEvent` rows (not an edit to `LeaseTimelineService`, which maintenance Build 3 owns).

**List screen (new): Deposit settlements** — nav entry under Rentals (same-day), `rental_deposit_settlements.view`. **Search:** property address, tenant name, lease reference, agent name, refund reference. **Sort:** move-out date (default newest first), property, status, refund due, issued date. **Filters:** status (draft/issued/settled), move-out date range, agent, "refund unpaid for more than N days" (N from a new agency setting `deposit_refund_reminder_days`, default 7, 0 = off, with a due-notice mirroring I-5), branch/own/agency pill. **Pagination** 25/50/100; **empty states** (no settlements yet vs no match); **archive/restore** (soft delete; archived rows show who/when; issued settlements can only be archived by `rental_deposit_settlements.archive_issued`). Print/CSV export of the filtered list, same scoping.
**Scoping:** `BelongsToAgency` on both tables; own = lease's property agent or creator, branch = property branch, agency = all, resolved at the query layer through `lease → property` exactly as inspections do; show/edit/issue/PDF/export re-check via guard; no public token.
**Permissions (new, `config/corex-permissions.php`):** `rental_deposit_settlements.view`, `.manage` (draft lines), `.issue`, `.record_refund`, `.archive_issued`; defaults: agents view + manage, managers/principals issue.
**Wizard:** `deposit_refund_reminder_days`, `deposit_settlement_requires_approval` (Q12, default true), `tenant_sees_issued_settlement` (Q11, default true) — rows with explain/affects, `has()`-guarded saver, `currentValues()` arm.
**Input space / tests:** deposit never captured (`deposit_amount` null → prompt, never 500), held ≠ agreed, deductions exceed deposit (refund balance negative → shown as "shortfall", never hidden or clamped), zero lines (full refund), line with no amount, work order closed with no cost, repair raised twice for one item (idempotent per item+inspection), finding superseded after line created (line shows "finding changed", agent re-confirms), lease renewed (settlement stays on the old term), settlement for a lease with no out-inspection (manual), create → archive → recreate on the unique `lease_id` (§5a: `withTrashed()`+restore), issue → supersede → re-issue, tenant sees only issued, other-agency id by URL. Files: 2 migrations + models, settlement service, controllers + routes, 4 views (list, draft/edit, PDF, portal block), listener on `RentalWorkOrderClosed` (one `AppServiceProvider` line in the inspections marker), `SignedDocumentDistributionService` seam (I-4), permissions, wizard, nav.

### 45.8 Build I-6 — Scoping, audit, permissions, portal (point 7). I-6a is a security fix and should go first. (H1 below is already BUILT — §44a; H2–H4 remain. The portal part waits for Q13.)

**I-6a — security (small, standalone, recommended before everything else):**
- **H1 public link outlives archive/cancel — BUILT and deployed 6 Oct (§44a for inspections, `rental-inventory.md` §25 for inventory), by refusing archived/cancelled records in the one public lookup rather than by revoking the token (so a restore revives the same link). The text below is the original finding.**  — `findByPublicToken()` ignores soft deletes (`RentalInspection.php:1076-1082`); `destroy()`/`cancel()` never revoke. Fix: `findByPublicToken` honours `deleted_at`/`cancelled`; archive and cancel call `revokePublicLink()`; restore does not re-issue silently.
- **H2 property-level endpoints** (`RecordingController.php:55,90,169-442,1108,1189,1230`) apply own/branch/agency via `authorizeProperty()` + the inspection scope before returning or changing anything.
- **H3 create/store** — property picker and `store` use `Property::visibleTo`; `inspector_user_id` validated with an agency-scoped `Rule::exists('users')->where('agency_id', …)` (a cross-agency user can today be emailed schedule details and given a calendar event, `NotificationService.php:123-131`).
- **H4 own-scope parity** — guard accepts the inspector as well as the creator, matching `scopeVisibleTo` (`AuthorizesRentalRecordScope.php:57` vs `RentalInspection.php:431-433`); add the missing inspector test.
Tests: direct-URL-by-ID for each, every role, archived/cancelled token, cross-agency inspector id.

**I-6a H2–H4 — BUILT 7 Oct 2026 (QA1), branch `cc1-inspections-i6a-2026-10-07`.** No migration, no setting, no route, no permission key, no view.
- **H2.** `RentalInspectionRecordingController` now uses `AuthorizesPropertyAccess` and calls one private guard, `authorizePropertyForInspections()`, as the FIRST statement of all 15 property-level actions: `tabData` (view breadth), `start`, `next`, `storeItem`, `assignType`, `retireItem`, `restoreItem`, `renameItem`, `reorderItems`, `applyDefaultRoomOrder`, `reorderRooms`, `seedFromAdvertising`, `storePhotoMatch`, `destroyPhotoMatch`, `autoPairPhotoMatches` (mutation breadth). The guard = (1) `authorizeProperty()` — the property's own/branch/agency scope, i.e. exactly the rule the property page itself applies — AND (2) the `rental_inspections` scope ceiling: `branch` requires the property to be in the user's branch; `own` adds nothing at property level (own = creator/inspector, enforced per inspection); no scope = 403. The three photo-match actions additionally call `guardRentalRecordScope()` on the property's CURRENT inspection (the chain tail), deliberately NOT on the predecessor (comparing against a colleague's finished inspection is the point of the chain). `next` keeps its existing inspection guard on top.
- **H3.** `RentalInspectionController::create()` picker adds `->visibleTo($user)`; `store()` resolves the property through `Property::query()->visibleTo($user)->find()` (never a bare `findOrFail`) and answers 403 + a warning log when it is outside the user's scope (a nonexistent id is still the ordinary validation error). `inspector_user_id` is validated with `Rule::exists('users','id')->where('agency_id', <the inspection's agency>)` in BOTH places an inspector can be set — `storeScheduled()` and `reschedule()` (the same defect on a second line, fixed as the class, §6).
- **H4.** `AuthorizesRentalRecordScope::guardRentalRecordScope()` — for a `RentalInspection` under `own` scope — now accepts `inspector_user_id` as well as the creator, matching `scopeVisibleTo()`. (The shared trait; the change is one `instanceof RentalInspection` block, nothing for Lease/fault-report/work-order.)
- **Tests:** `tests/Feature/RentalInspections/RentalInspectionI6aScopingTest.php`, 15 tests / 117 assertions — every property-level endpoint refused (403/404, nothing written) for a same-branch own-scoped colleague, a branch manager of another branch and another agency's admin; property agent / branch manager / admin still work; an inspections scope of `branch` under a properties scope of `all` is a real ceiling; photo matching refused on a colleague's tail under `own`; inspector can work an inspection they did not create, a stranger cannot, the guard itself refuses when the binding is bypassed; picker offers only in-scope properties (agent, BM, admin offered; colleague, other-branch BM, other agency not); `store` refuses out-of-scope (3 users) and still starts/schedules in scope; cross-agency inspector refused on schedule and reschedule (booking untouched, no mail), same-agency and empty inspector accepted. With the app changes reverted 11 of the 15 fail. One existing test (`RentalInspectionRecordingControllerTest::test_screen_preference_is_scoped_per_user_not_shared`) had a same-branch own-scoped colleague read the agent's property tab; it now uses a branch manager, because that read is exactly what H2 refuses.
- **Not changed, for Johan (business question):** the property tab still lists EVERY inspection on a property the user may open. A role whose properties scope is wider than its inspections scope (e.g. sees all properties, only its own inspections) therefore sees colleagues' inspections of that property on the tab. Filtering the tab's chain by inspection scope would change how the "current/previous inspection" chain behaves, so it is not done here.

**I-6b — audit, permissions, portal, list fixes:**
- **Audit log:** `rental_inspection_audit_log` (`rental_inspection_id`, `agency_id`, `event`, `user_id`, `before` json, `after` json, `created_at`; append-only, pattern of `RentalApplicationAuditLog`). Events: header edit, status change, archive, restore (and keep `archived_by` history), public link issued/revoked, attendance/signature/finding supersede, settings-driven auto actions (copies sent, due notice). A "History" panel on the inspection page (who/what/when, newest first, filter by event).
- **Permission keys split** (today all use `.create`): `rental_inspections.edit_details`, `.archive`, `.restore`, `.cancel`, `.reschedule`, `.public_link`, `.export`, plus `.archive_completed` (completed + signed inspections are evidence: archiving needs it; default managers/principals). Existing roles are granted the equivalents in the migration so nobody loses an action they have today except archiving a completed inspection.
- **Portal (tenant and landlord):** a portal Inspections region (web shell + `GET /api/v1/client/rentals/inspections/{id}`): upcoming (date, time, inspector, what to bring — no internal notes), completed with the signed report and photo gallery (via a portal-authenticated route, never the public token), draft/cancelled hidden (`RentalPortalScopeService.php:168-176,320-328` gain a status filter), no deductions schedule (finance build, parked). Invitation emails deep-link to the inspection, not `/portal`.
- **List defects:** `lease_id` filter honoured; date filter/sort use `COALESCE(scheduled_for, created_at)`; export row cap with a clear message; inspector dropdown scoped to the viewer.
- **Wizard:** none of the above are settings except the permission grants; the portal toggle (`portal_show_inspections`, default true) gets a wizard row.

**I-6b — BUILT 7 Oct 2026 (QA1), branch `cc1-inspections-i6b-2026-10-07`** (portal region NOT built — waits on Q13). Migrations `2026_10_13_100400` (table `rental_inspection_audit_log`) and `…100410` (permission grants). No new setting, so nothing for the Setup Wizard (§10a) — the portal toggle waits with the portal.
- **History.** `RentalInspectionAuditLog` — append-only (an update or delete of a row throws), `user_id` null = the system acted, `before`/`after` carry only the changed fields, `summary` is the one plain line the panel prints. Hooked on the MODEL so every path leaves the same trail: created, status changed, **cancelled (with the reason)**, **archived**, **restored (naming who had archived it — a restore clears `archived_by_user_id`, so the log is now the only lasting record)**, header details edited (changed fields only; a save that changes nothing leaves no row), rescheduled, public link issued (never the token) / revoked, attendance recorded / corrected / withdrawn, invitation recorded (I-3), paper signature replaced, finding replaced (I-7), and the automatic report mailing's outcome counts (a settings-driven action). Not logged: the due-notice reminders (I-5) — they are per lease, not per inspection, and have their own notice table. A failure to write a row never breaks the action and is logged at ERROR. Agency inspection page: a **History** panel (`#history`) — who · what · when, newest first, before → after for edits, filter by event kind (only kinds that occur, with counts), 200 most recent.
- **Permission split.** `rental_inspections.edit_details | archive | restore | cancel | reschedule | public_link | export` plus `archive_completed`. Routes re-gated (details update, destroy, restore, cancel, reschedule, public-link generate/revoke, export, print-list); every button on the inspection and list pages follows its own key. **Nobody loses an action:** migration `…100410` grants the six write keys to every agency/role that holds `.create` (scope copied), `.export` to every role that holds `.view`, and `archive_completed` only to roles that hold `.resolve_discrepancy` (the managerial tier). The one deliberate loss — as ruled — is archiving a COMPLETED or signed inspection (`RentalInspection::isEvidenceRecord()`: completed, or any live signed / paper-signed signature): needs `archive` AND `archive_completed`, checked server-side in `destroy()` (a plain draft stays archivable with `archive` alone). Role defaults updated for the branch-manager and agent lists. Existing `.create` keeps start / record / next / scans.
- **List fixes.** (1) `?lease_id=` (the Lease Hub link) is honoured on the same own/branch/agency-scoped query, with a visible "Showing inspections for …" chip and Show all; junk ids are ignored, unknown/unseen ones match nothing. (2) Date range filters and the default sort use `COALESCE(scheduled_for, created_at)` so a start-now inspection is found by the day it was started and no longer always sorts last; the Scheduled column shows "(started)" for those. (3) Print / export refuse a list larger than `config('rental-inspections.export_row_cap', 5000)` with a plain "narrow it" message instead of building it, and use the same newest-first order as the screen. (4) The inspector filter offers only people inside the viewer's reach (own → themselves, branch → their branch, all → the agency; never another agency).
- **Not built — waiting:** the tenant/landlord portal Inspections region (Q13). **Reported, not changed:** other per-record guards that accept only the creator under `own` (Lease / fault reports / work orders) may share I-6a's H4 gap — not checked.
- **Tests:** `RentalInspectionI6bTest` (22 tests / 189 assertions): every history event with who/what, before/after, no-op edit writes nothing, token never logged, append-only refusal, system actor, a failing log write never breaks the action (and is logged loudly), the panel (newest first, filter, empty state, edit diff), another agency refused; each action refused without its own key and `.create` alone opens none of them; one key opens exactly one action; archiving completed/signed needs `archive_completed` (draft doesn\'t); buttons follow permissions; the grant migration (every action kept, view-only roles get only export, managers only get archive_completed, scope copied, idempotent); the eight keys defined and defaulted; lease filter + chip + junk/unknown/out-of-scope ids; start-now placed by creation date in filters and both sort directions; export/print cap refusal with message and narrowing; screen order in print; inspector options by scope. **Test-hygiene fix:** BelongsToAgency forces a record created while an ordinary user is logged in into THAT user\'s agency, so "another agency" users must be created with nobody logged in; the I-3 test\'s outsider had silently been a same-agency colleague and now really is another agency.

### 45.9 Open questions for Johan — business only (numbering = the list printed to Johan on 6 Oct; statuses after his 15:11 rulings)

| # | Question | Status | Ruling, or options → recommendation | Blocks |
|---|---|---|---|---|
| 1 | Deposit and deductions — how far should CoreX go? | **PARKED** — finance build | — | I-8 (parked) |
| 2 | Does the tenant see the deductions list? | **PARKED** — finance build | — | I-8 (parked) |
| 3 | Who may finalise a deductions list? | **PARKED** — finance build | — | I-8 (parked) |
| 4 | Amount for a repair charged to the tenant | **PARKED** — finance build | — | I-8 (parked) |
| 5 | How strict on photos? | **RULED 6 Oct: no enforcement** | Photos are uploaded per inspection and tagged to a room or a section of a room (e.g. windows) — sufficient. Tagging to room AND section confirmed built (§45.0). | I-1 (photo guards removed) |
| 6 | Interim inspections — automatic? | **RULED 6 Oct: no automatic scheduling** | The agency loads its own interim dates; CoreX reminds from them. Revisit when more agencies are on. | I-5 (re-cut, §45.7) |
| 7 | Who signs an interim inspection? | **RULED 6 Oct: all parties, every inspection** | Agent, tenant and landlord sign incoming, interim and outgoing alike — legal documentation. (Ad-hoc checks not covered — stay unsigned as today; flagged.) | I-4 |
| 8 | PDF report and photo times (PDF stays photo-free per the 23 Sep ruling) | **OPEN** | (a) per flaw "3 photos, taken 12 Aug 14:03–14:09" + the QR link, with times on every photo in the gallery; (b) also a thumbnail appendix of flaw photos; (c) leave as is. **Recommend (a).** | I-1 — the PDF line only; capture time, screens and public page proceed |
| 9 | Default checklist | **RULED 6 Oct: (a)** | Approve the floor-to-ceiling default list AND add the "Add missing standard items" button on existing properties. | I-2 (unblocked) |
| 10 | Furniture and contents | **OPEN** | (a) keep in Inventory with an agency starter list per room, show the count on each room; (b) merge into the inspection checklist. **Recommend (a).** | I-2 — inventory starter lists only |
| 11 | Someone attends on behalf of a tenant or landlord — what do we record? | **OPEN** | (a) name and who they represent; (b) plus optional authority letter; (c) plus mandatory letter. **Recommend (b).** | I-3 — the authority-letter part only (name + capacity can be recorded now) |
| 12 | Does a party who did not attend still get the completion copy? | **OPEN** | (a) yes, outcome shown as a fact; (b) no. **Recommend (a).** Until ruled, today's behaviour (every party gets the copy) stands. | I-4 — that one rule only |
| 13 | Portal: tenants/landlords see their completed report, photos and upcoming bookings (not drafts)? | **OPEN** | (a) yes, default on, agency can switch off; (b) agency opts in. **Recommend (a).** | I-6b — the portal part only |
| 14 | Book an incoming inspection while the lease is still out for signing? | **OPEN** | (a) only once the lease is live; (b) also while signing. **Recommend (a).** Until ruled, only once live. | I-5 — that one guard only |
| 15 | Remote signing link for someone who could not attend | **OPEN** | (a) later, after attendance exists; (b) now. **Recommend (a).** | None of I-1…I-7 (optional I-9 only) |

### 45.10 Build plan — lane-sized builds, order, conflict map (re-cut 6 Oct 2026 after Johan's rulings)

**Builds (each = one lane, one Jira ticket, one cleared lane per task):**

| Build | Scope (as ruled) | Migration? | Depends on | Status |
|---|---|---|---|---|
| **I-6a** | Security fixes **H2–H4** (property-page scoping, create/inspector scope, own-scope parity). **H1 (public link) is DONE — §44a / inventory §25.** | none | — | **BUILT 7 Oct on QA1 — see §45.8** |
| **I-1** | Photo capture time (stored, shown on screens/public page); every-item-graded completion guard; report completeness. **No photo enforcement (Q5).** PDF per-flaw time line waits for Q8. | yes (photos, 1 setting) | I-6a merged (shares the recording controller) | **BUILT 7 Oct on QA1 — see §45.3** (PDF line waits Q8) |
| **I-2** | Floor-to-ceiling default list + "Add missing standard items" (Q9a RULED) + agency custom room types. Inventory starter lists wait for Q10. | yes (settings JSON) | — | **BUILT on QA1 2026-10-07** (cc6) — inventory starter lists still wait Q10 |
| **I-3** | Attendance record + invitation trail (incl. manually recorded invitations); "did not attend" as its own outcome. Representative: name + capacity now; authority-letter handling waits Q11. | yes | I-1 (report/PDF + viewer files) | **BUILT 7 Oct on QA1 — see §45.5** (letter part waits Q11) |
| **I-4** | Copies to tenant, landlord, agency address, inspector, creator; visible delivery + resend; **all three parties sign interim (Q7 RULED)**. Non-attendee copy rule: today's behaviour until Q12. | yes (settings) | I-3 (recipients/attendance in the report); the `interim` type constant from I-5 | **BUILT 7 Oct on QA1 — see §45.6** (non-attendee copy rule waits Q12; "Ready to complete" pill BUILT in the I-4 top-up — see below) |
| **I-5** | **Re-cut (Q6 RULED):** interim = dates the agency LOADS + reminders from them (no interval, no automatic scheduling); In/Out computed due list + agent reminders unchanged. | yes (planned dates, 2 notices tables, settings) | none of the lease e-sign builds | **BUILT on QA1 2026-10-07** (cc6) — guard on In-while-signing stays until Q14 |
| **I-7** | Move-out comparison: both photo sets with times, differences marked, classified; mid-tenancy context; no money. | yes (findings dispositions only, no column) | I-1 (viewer captions), I-5 (interim in the chain) | **BUILT on QA1 2026-10-07** (cc6) — paired viewer is a purpose-built one on the screen, not the tab's `openCompareViewer` (see the built-state note) |
| **I-6b** | Audit log, permission split, list fixes; portal inspections region waits Q13. | yes (audit log, permission migration) | I-3 (audit events) | **BUILT 7 Oct on QA1 — see §45.8** (portal part waits Q13) |
| ~~I-8~~ | ~~Deposit settlement + deductions schedule + job-card links + issue + copies~~ | — | **PARKED 6 Oct (Q1–Q4) — belongs to the finance build; removed from the current plan.** Revisit when the finance side is built. | **PARKED** |
| I-9 (optional) | Remote signing link | yes | I-3; Q15 | Open question |

**Now unblocked (can be given to lanes today):** I-6a, I-1, I-2, I-3, I-4, I-5, I-7, and I-6b (all except the named waiting parts). **Waiting on an open question — only these parts:** I-1's PDF per-flaw time line (Q8), I-2's inventory starter lists (Q10), I-3's authority-letter handling (Q11), I-4's non-attendee copy rule (Q12), I-6b's portal region (Q13), I-5's In-booked-while-signing guard (Q14), I-9 (Q15). **Parked:** I-8.

**Two lanes at a time (usage rule), one kept clear.** Lane X (touches `RentalInspection.php`, the recording controller, report PDF, show blade — must be sequential): I-6a → I-1 → I-3 → I-4 → I-6b. Lane Y (settings/wizard/lease-adjacent): I-2 → I-5 → I-7. I-7 starts only after I-1 has merged (shared compare-viewer file). Parallel-safe today: I-2 alongside I-6a/I-1.

**Conflict map — maintenance §17 (rental-work-orders.md) Builds 1–3.** §17 Foundation is on QA1 (migrations `2026_10_11_*`, in the snapshot); Builds 1–3 add no migrations and no permission keys. Verified against §17.21 exclusive-file lists:
- **I-1…I-7 and I-6a/b: no shared file with Builds 1–3** except append-only shared files — `routes/web.php`, `routes/api.php`, `config/agency-onboarding-copy.php`, `AgencySetupWizardController::currentValues`, `config/corex-permissions.php`, `AppServiceProvider` — each build appends only inside its own `// INSPECTIONS BEGIN/END` marker. Different outbound mail path (inspections: `SignedDocumentDistributionService`; maintenance: `RentalMailDispatcher`) — do not mix.
- **I-8 was the only real collision and is now PARKED**, which removes the whole inspections-vs-maintenance collision from the current plan. (When the finance build is scheduled, its design note stands: link to job cards/work orders only through the existing keys and read-only services; never edit `paid_by`, `RentalCloseGuards`, `RentalJobCardService::complete()` — hard-codes `paid_by=owner` at `:685` — `RentalReportService::workOrders()`, `LeaseTimelineService` or the tenant Jobs tab, all maintenance Build 3's; schedule it after Builds 1 and 3, and re-test Follow-up after Build 3.)
- **Rental Money (AT-446, spec only)** models Deposit as a charge with inbound payments only — relevant to the finance build, not to the current plan.

**Conflict map — lease e-sign series (`leases.md` §15, spec only; merged to QA1 as spec).** It changes only `.ai/specs/leases.md` and touches no inspection code.
- **I-5 is independent of the lease builds:** it scans ACTIVE leases daily (In/Out due) and reads its own loaded-dates table (interim), so it needs no activation hook and edits none of L1–L3's files (`Lease.php`, `LeaseController`, `LeaseHubService`, `corex/leases/show.blade.php`); the Lease Hub card and any lease-page block wait for L3a.
- **I-4's interim signing** extends the in/out signing guard in `RentalInspection.php` — an inspections file, not a lease file.
- **Shared mechanics:** `database/schema/mysql-schema.sql` (76 commits in 3 weeks) — one regenerating lane per day (`scripts/schema-dump.sh`, DEFINER stripped), prefix `2026_10_13_*` for inspection migrations; `routes/console.php` (L3b adds `leases:reconcile-signing` near `:35`; I-5 adds its two commands beside `:48`) — textual only; `config/agency-onboarding-copy.php` — different arms (leases step vs inspections `:504-540`).
- **Other unlanded branches that touch the same hot files** (AT-432 Auctions, PPRA letter, fault-routing slice 3, platform e-sign): textual only for `routes/web.php`/`properties/show.blade.php`/permissions; fault-routing slice 3 rewrites `RentalWorkOrder::selectQuote()` (also Build 2's) — not an inspections file, reported for the Build 2 owner.

### 45.11 Wording flagged for Johan (strings I have NOT written — facts only until approved)

1. Attendance outcome labels and the printed attendance line (today a non-attendee prints "Refused to sign — Reason: Not present for the walkthrough", `show.blade.php:646-650`): attended / did not attend / attended on behalf of … — exact wording and any sentence about what attendance means for deposit rights under the Rental Housing Act. **Not drafted.**
2. Invitation fact line: "Invited by email to <name> on <date, time>" — confirm; whether failed sends and reminders appear on the printed record.
3. ~~Deductions-schedule headings, status words and the replacement footer sentence~~ — **parked with I-8**; the existing comparison-page footer ("This is a proposal for review, not a deduction…", `deposit-comparison-page.blade.php:125`) stays as it is.
4. Comparison page buttons: "Fair wear and tear", "Flag as a genuine difference", "Difference — needs review" (`:106-119,:25`) and the new classification words (pre-existing, landlord's responsibility, charge to tenant) — **the three classification words are now an AGENCY SETTING (§45.14, Johan 7 Oct), defaults unchanged; no approval needed for them any more.**
5. Whether the minimum-notice figure (`RentalInspectionSetting.php:842`, warn-only today) is printed on the record as a fact.
6. The completion email subject/body (an ad-hoc inspection currently reads "Ad_hoc-inspection report", `RentalInspection.php:1140`).

### 45.12 Reported, not changed (found while measuring)

Already listed against the builds above where a build fixes them; all remain UNCHANGED until Johan's go. Beyond those: ~~retired items disappear from a completed report~~ (FIXED 7 Oct, §45.13) · compare viewer shows a blank date for unscheduled inspections (`properties/show.blade.php:6642`, I-1) · `signing_deadline_at` is display-only (`RentalInspection.php:462`) · reminder command matches only the exact day and has no per-row try/catch (`SendRentalInspectionReminders.php:52-65`; I-5's due notices do not use it, the existing reminder is untouched) · `printForSignature()`/`form()` change state on GET (`RentalInspectionController.php:633-649,583-594`) · export/print list unbounded (`:395,:408`) · "improved" hard-codes `good` (`RentalInspectionComparisonService.php:213`; fixed in I-7) · invitation recipients (all landlords) ≠ signing landlord (one) (`NotificationService.php:165-185` vs `RentalInspection.php:350`) · Follow-up on an out-inspection lists defects already present at move-in (`RentalInspectionFollowUpService.php:96-103`; fixed in I-7) · `RentalJobCardService.php:685` hard-codes `paid_by=owner` (Build 3's file; I-8 works around it) · spec/code drift: `rental-inspection-form.md` §7.1/§7.4 describe a photo-showing comparison page the code does not have; `rental-renewals.md` header still says "SPEC ONLY"; §17.1 "what exists today" is partly superseded by §17.24 · two lease models still written (`Lease`, legacy `Docuperfect\LeaseRecord`, `SignatureService.php:5297-5396`) · name collisions to keep out of prompts: "Inspection Pack" (PPRA compliance, not rental), "completion" (inspection / maintenance round / job card), "distribution" (signed-document service vs deal distributions), "deductions" (also payroll).

### 45.13 Inspections bug-fix batch — 7 Oct 2026 (cc1; Johan's go on the four reported defects) — BUILT

1. **Archived item photos stay off the public report link.** `RentalInspectionPublicController` loaded `observations.photos` with every global scope lifted, which also lifted the soft-delete ("archived") filter. It now lifts only the AGENCY scope (the same as the room photos added in I-1), so an archived photo — e.g. one removed as "wrong property" — never reaches the tenant/landlord link. Rule: on the public page, never lift the soft-delete scope on anything.
2. **A report is a record of what was inspected at the time.** Retiring an item later no longer removes it from that inspection's reports. `RentalInspectionItem::scopeListedOnReportOf($inspectionId)` = every live item PLUS any retired item with a **recorded** (not photo-anchor-only) observation in THAT inspection. Used by the three report builders: the public link, the signed PDF (`RentalInspectionReportPdfService`) and the comparison on the inspection page (`RentalInspectionController::buildComparisonRows`). A retired item the inspection never assessed stays out; a later inspection (made after the retirement) does not list it either. The blank form PDF and the recording screen are unchanged (a retired item is not asked again).
3. **Interim reads "Interim" on the property's Inspections tab.** One helper (`inspectionTypeLabel`, in the property page's Alpine component) now names the type for the tab's header line and both compare-viewer headers (previously: anything not in/out printed "Routine-inspection"). Ad-hoc keeps its existing "Routine" wording there — not part of this fix.
4. **cc4's I-4 "another agency is refused" test used a same-agency user** — the same blind spot as I-3's: `BelongsToAgency` forces a newly created `User` into the *acting* user's agency, so a "stranger" created while the agent is logged in is silently a colleague. Fixed in `RentalInspectionI4CopiesTest` (log out first; assert the stranger's `agency_id`). **Test-writing rule for every inspections test: create the other-agency user with nobody logged in, then assert its `agency_id`.**

Tests: `RentalInspectionReportRecordTest` (new) + the corrected `RentalInspectionI4CopiesTest`.

### 45.14 Move-out classification wording is an agency setting — 7 Oct 2026 (cc1; Johan's instruction) — BUILT

**What:** the three classifications an agent can record against a marked row on the move-out comparison — *Pre-existing*, *Landlord's responsibility*, *Charge to tenant* — are worded per agency. The current three are the defaults, so an agency that never opens the setting sees no change.

- **Keys are fixed, logic never reads wording.** `pre_existing`, `landlord_cost`, `charge_tenant` (`RentalInspectionItemFinding::DISPOSITION_*`) are what is stored, validated (`in:` the keys), filtered (`classification=` the key) and compared. The labels are display only. Tested with the nastiest case: two labels swapped onto each other's default wording changes nothing the code does. The two older judgements (*Fair wear and tear*, *Flagged as a genuine difference*) are not agency-worded (not asked).
- **Storage:** `rental_inspection_settings.move_out_classification_labels` (nullable JSON key → label; migration `2026_10_13_100500`). Read time: `RentalInspectionSetting::moveOutClassificationLabelsFor($agencyId)` (always all three keys; a blank/missing label falls back to the default; max 60 characters; unknown keys ignored). `dispositionLabelsFor($agencyId)` = the five keys in their fixed order with this agency's wording — what the comparison screen shows.
- **Where it is set:** Settings → Rental Inspections → *Move-out classifications* (`corex.settings.rental-inspections.move-out-classification-labels`, permission `rental_inspections.manage_settings`) and the Setup Wizard Rentals step (same control, "What it is" + "What this changes", saver `RentalListsWizardSaver::inspectionMoveOutClassificationLabels` — a no-op unless its `_submitted` marker was posted, so a wizard step that never rendered it cannot wipe it; the canonical saver refuses a post without the marker). Clearing every box returns the agency to the defaults (stored as null).
- **Where the wording shows:** the classification dropdown, the Classification filter and the "Classified … by …" line on the move-out comparison screen — nowhere else. A reworded label applies to the screen from then on, including findings recorded earlier (the stored key is unchanged).
- **Reports and PDFs already produced keep their wording — and today none of them carries these words.** The signed PDF and the public report link do not print classifications (asserted by a test: they contain neither the default nor the agency wording). So nothing already produced can change. **Rule for the parked I-8 (deductions schedule) and anything else that will print a classification: write the wording used AT PRODUCTION TIME into the produced document (a snapshot string stored with it); never re-read the setting when re-rendering an already-produced document.**
- **Second agency:** each agency has its own row; another agency sees the defaults until it saves its own (tested).

Tests: `RentalInspectionMoveOutLabelsTest`.

---

## 46. Signing an inspection by personal link, QR code, or on the agent's device — 7 Oct 2026 (cc6; Johan's instruction) — BUILT on QA1; the held part (§46.8) is now ruled and built as §47

**Why.** Johan: *"get the report on a link that can be sent to all parties; they can peruse it and, when happy, sign electronically from the link and we record it. The agent is at the property and opened the link on their phone to do the inspection; the agent can share to the tenant who is at the property and they sign on their own phone, or sign on the agent's phone. Also: give the agent a QR code for the owner and for the tenant; the tenant scans it, the report opens and they can sign off."* This is what §45.6 item 6 / Q15 / I-9 called the remote signing link; Johan has now asked for it, so it is built. The signing rules of §15/§16/§45.5–45.6 are unchanged: agent, tenant(s) and landlord sign every in / interim / out inspection; attendance and "not present" are untouched; wet-ink is untouched.

### 46.1 What existed before (measured, file:line on QA1 `e05e1dfb8`)

| Question | Answer |
|---|---|
| Who signs, where | Tenants on the lease, the landlord (`Property::sellerOwnerContact()`), the agent — only on the agent's logged-in screen: a canvas pad for tenant/landlord, the PIN signature for the agent (§34). `RentalInspectionRecordingController::storeSignature` (`:1391`). |
| How a signature is captured and stored | One `rental_inspection_signatures` row per party via `RentalInspectionSignature::capture()` (`:129-250`): disposition signed / refused / wet_ink / awaiting_wet_ink, PNG on the private disk (`storeCanvasImage()`), `recorded_by_user_id`. A correction supersedes the old row. It stored **no IP, device, typed name or "which link"**. |
| Public report link | One token per inspection (`rental_inspections.public_token`, `RentalInspection.php:1202-1275`), shared by everyone, read-only, no sign step, expiry setting `public_link_expiry_days` (default 90), already dead when archived/cancelled (§44a). |
| Per-party tokens | None. |
| Completion and "copies sent" | A deliberate agent click on **Complete** (`markCompleted()`, then `RentalInspectionRecordingController::complete()` calls `RentalInspectionCopiesService::fileAndSend(autoOnly: true)`); the **Copies sent** panel reads the delivery log. |
| Wet-ink | A scan is uploaded, or the party is marked "awaiting wet-ink" and the scan arrives later (`supersedeWetInk()`); Complete is refused while anyone is still awaiting paper. |

### 46.2 The rules (decided here)

- **One personal link per party**, per inspection: each tenant, the landlord, the agent. An unguessable 48-character token (`Str::random(48)`, unique index), stored as issued so the screen can show the same link again (copy / QR / WhatsApp) without sending a new one each time. Table `rental_inspection_signing_links` (migration `2026_10_14_100000`). A link is never deleted: revoking stamps `revoked_at`; replacing revokes the old row and issues a new one, so who-was-sent-what survives.
- **A link shows only its own inspection.** The page is the SAME read-only report as the general public link (rooms, items, conditions, notes, photos, attendance, signatures) — `RentalInspectionPublicController::reportData()` is the single builder, so the two can never disagree — plus a **Sign** step at the end.
- **When a party can sign.** Only while the inspection is **ready to sign** (`awaiting_signature` — exactly where the recording screen offers signing today), the agency has the setting on, the inspection is an in / interim / out (ad-hoc is not signed), the link is live (not revoked, not expired), the link has not already been used, and the party has no live signing outcome recorded another way (an agent who recorded a paper refusal first wins; a link then answers "already recorded"). Before ready-to-sign the party can read the report; the page says signing opens when the agent finishes. After completion a link stays a read-only view of the signed report until it expires or is revoked; it accepts nothing.
- **The Sign step.** Type full name, draw signature, tick *I have read this inspection report*, optional comment. Or **decline / dispute** — which reuses the agency's own refusal reasons (§15.6: "Disputes the recorded condition", "Not present for the walkthrough", "Refused outright, no reason given", "Other" — or whatever the agency edited them to; "Other" needs a note); nothing new is invented. A decline is a `refused` disposition, a first-class outcome (§15.5), never an error.
- **What is recorded** (columns on `rental_inspection_signatures`, migration `2026_10_14_100010`, all nullable so older rows read exactly as before): `signed_via` (`link` = the party's own phone; `agent_device` = signed on the agent's logged-in device), `signing_link_id`, `signed_on_device_by_user_id` (the agent), `signed_typed_name`, `read_confirmed_at`, `signer_comment`, `signed_ip`, `signed_user_agent`, `signed_report_fingerprint` (§46.8), plus the existing `disposition_recorded_at` as the date/time. Signing goes through `RentalInspectionSignature::capture()` itself, so every invariant (one disposition per party, a real PNG, never after completion) holds for a link signature too. The web report page (the general public link and the party links) shows "signed from their own link as <name>" / "signed on the agent's device as <name>" and the comment; the signed PDF is not changed (reported in §46.9).
- **Signing twice is impossible**: the link row is locked inside the transaction; the second attempt is refused ("already used") and exactly one signature exists. A refused attempt (no signature drawn, box not ticked, bad image, name missing) records nothing and does not use the link up.
- **The agent's link** opens the report read-only. The agent does **not** sign through a token: an unauthenticated link that signs *as the agent* would bypass the PIN that §34 deliberately requires. The page says so and offers "Open this inspection in CoreX"; the agent signs there as today (and only after every other party has an outcome — §15.2a unchanged). Flagged for Johan below.
- **Completion is unchanged.** Link signatures do not complete the inspection; the agent still signs and presses **Complete**, `markCompleted()` still requires every tenant, the landlord and the agent, attendance is still required, and the completion copies go out exactly as today (`RentalInspectionCopiesService`). The "Ready to complete" pill now also turns green when a party signs from their phone, because the recording screen refreshes its signatures when the panel sees a change.
- **A non-attendee can sign from a link.** Attendance is untouched (a party recorded "did not attend" still reads that way on the report); the signature row says it was signed from their own link. This is the remote signing Johan has asked for; it does not auto-record anything about attendance.
- **Expiry** is an agency setting (default 30 days from the day the link is issued). Wrong, revoked, expired, archived and cancelled all give the one uniform "This link isn't available" page (§44a) — a stranger cannot tell which. Restoring an archived inspection revives its live links.

### 46.3 Sending (agent side)

`CoreX\RentalInspectionSigningLinkController`, all under `/corex/rental-inspections/{inspection}/signing-links` (the existing inspection route group — the module's own convention, like attendance and copies; not a new `/api/v1` namespace):

| Action | Route | Permission |
|---|---|---|
| Panel (per-party status) | `GET …/signing-links` (`corex.rental-inspections.signing-links.index`) | `rental_inspections.view` + own/branch/agency scope |
| Issue / copy / WhatsApp / QR / device marker | `POST …/signing-links` (`.issue`) | `rental_inspections.public_link` |
| Email everyone | `POST …/signing-links/send-all` (`.send-all`) | `rental_inspections.public_link` |
| Email (or resend) one | `POST …/signing-links/{link}/email` (`.email`) | `rental_inspections.public_link` |
| Revoke | `POST …/signing-links/{link}/revoke` (`.revoke`) | `rental_inspections.public_link` |
| QR (data URI of the link) | `GET …/signing-links/{link}/qr` (`.qr`) | `rental_inspections.public_link` |
| Sign on this device | `GET`/`POST …/signing-links/{link}/device` (`.device`, `.device-submit`) | `rental_inspections.create` |

No new permission key: sharing a signing link is the same act as sharing the public report link (`.public_link`), and signing on the agent's device is the same act as recording a signature (`.create`). Every action is scoped through `guardRentalRecordScope()` and a link must belong to the inspection in the URL (404 otherwise); another agency's user gets 403/404 and nothing is sent.

- **Email** uses the agent's own mailbox through `SignedDocumentDistributionService::sendGenericMail()` — the same path as the scheduling mails — so in every non-production environment it is redirected to the configured test address (QA1: `can.assurance@gmail.com`). Neutral wording; the agency name and the agent's details come from the agent footer. A party with no email address is **skipped with a reason**, never silently dropped. **Email everyone** issues and emails every tenant and the landlord (not the agent), and reports per person.
- **WhatsApp** opens `wa.me` with the link in the message (number normalised by the existing `WhatsAppNumberFormatter` with the contact's own dial code — not the SA-only normaliser); with no number it still opens so the agent can pick the chat. There is no server-side WhatsApp send in CoreX, so this is honest: it counts as "sent" when the agent opens it. **Copy link** likewise.
- **Status per party** (chip + detail line): *Not sent* · *Sent* (by email to …, or WhatsApp/copied/QR) · *Opened* (first-opened time; an agent previewing while logged in does not count) · *Signed* (with time and how) · *Declined / disputed* · *Expired* · *Revoked*, with "Email failed: …" / "No email address on file." shown when relevant. A party recorded by another route (paper, in person) shows that outcome instead.
- **Show QR** — next to each tenant/landlord: a full-screen white overlay on the agent's phone with that party's link as a large QR code; the tenant or owner scans it and the report opens on their own phone. The image encodes the link's URL and nothing else (asserted byte-for-byte in the tests). It sits on the **inspection page** and on the **property Inspections tab recording screen** (the phone screen), shown for any open inspection and hidden when the agency has the setting off or the inspection is ad-hoc.
- **Sign on this device** — the agent hands over their own logged-in phone; the party sees the same report and Sign step; the submit goes through the agent's session (CSRF-protected, `.create`), so the record says `agent_device` and carries the agent's user id (also on the History row). Enabled only when the inspection is ready to sign.
- **Live refresh.** The panel re-reads every 15 s while visible; when someone's outcome changes it tells the host screen (the recording screen re-fetches its data; the inspection page reloads), so a tenant signing on their own phone shows up on the agent's screen without a refresh.
- **History.** New events: *Signing link sent*, *Signing link revoked*, *Signed from a link* (never the token).

### 46.4 Public routes

`/rental-inspection-sign/{token}` (page), `POST …/submit` (CSRF-exempt like the other token-gated public POSTs — the token is the credential and a lapsed session must not turn a signature into "this link has expired"; the agent's own device POST is **not** exempt), `GET …/report.pdf` (the same PDF the completion copies carry — "Download the report"), `GET …/signatures/{signature}/{kind}` (the token-authorised signature image, only for that link's own inspection). Rate limits: reading 60/min per link, the signing POST 10/min per link and address. `X-Robots-Tag`, `Cache-Control: no-store`, `Referrer-Policy: no-referrer` as on the public report.

### 46.5 Settings (agency, with defaults, also in the Setup Wizard — CLAUDE.md #10a)

`rental_inspection_settings.signing_link_enabled` (default **on**) and `signing_link_expiry_days` (default **30**) — migration `2026_10_14_100020`, nullable read-time-default pattern (`RentalInspectionSetting::signingLinkEnabledFor()` / `signingLinkExpiryDaysFor()`). Edited at Settings → Rental Inspections (the existing shared `update()` saver, both `has()`-guarded so a wizard step that renders only one cannot wipe the other) and in the Setup Wizard Rentals step (`config/agency-onboarding-copy.php`, each with "What it is" and "What this changes", values in `AgencySetupWizardController::currentValues()`).

### 46.6 Screens and scoping (BUILD_STANDARD §1a)

This is a panel on existing screens, not a new list screen or entity: no new list (so no new search/sort/filter), no new entity to archive (a link is revoked, never deleted — non-negotiable #1), and every endpoint enforces own/branch/agency at the query layer as above; a direct-URL id from another agency is refused, not merely unlinked.

### 46.7 Files

`database/migrations/2026_10_14_100000_create_rental_inspection_signing_links_table.php`, `…100010_add_link_signing_evidence_to_rental_inspection_signatures_table.php`, `…100020_add_signing_link_settings_to_rental_inspection_settings_table.php`; `app/Models/RentalInspectionSigningLink.php`; `app/Services/Rentals/RentalInspectionSigningLinkService.php`; `app/Exceptions/RentalInspectionSigningLinkException.php`; `app/Mail/Rentals/RentalInspectionSigningLinkMail.php` + `resources/views/emails/rentals/inspection-signing-link.blade.php`; `app/Http/Controllers/RentalInspectionSigningController.php` (public) and `CoreX/RentalInspectionSigningLinkController.php` (agent); `RentalInspectionPublicController` (report data extracted to `reportData()` — behaviour unchanged); `resources/views/rental-inspections/public/show.blade.php` + `partials/sign-section.blade.php`; `resources/views/corex/rental-inspections/partials/_signing-links.blade.php` + `resources/js/rental-inspection-signing.js` (imported in `app.js`); `corex/rental-inspections/show.blade.php` and `corex/properties/partials/rental-inspection-recording.blade.php` (one include each); `RentalInspection` (`reportFingerprint()`), `RentalInspectionSignature` (fillable/casts/constants), `RentalInspectionSetting`, `RentalInspectionAuditLog` (3 events); routes in `routes/web.php`; CSRF exception in `bootstrap/app.php`; two rate limiters in `AppServiceProvider`; settings page + `RentalInspectionSettingsController` + wizard config/values.

### 46.8 (was HELD — now ruled by Johan and built: see §47) "edited after a party signed" — spec was silent, Johan asked to be asked

Johan: *"If the report is edited after a party signed, that party's signature is flagged as given on an earlier version and the agent is told (follow what the spec already says about edits after signing; if it is silent, stop and ask me)."* **The spec is silent**: §33 (`:5903`) records that an inspection in `awaiting_signature` renders and behaves exactly like draft — items, conditions, notes and photos stay editable — and says "whether an inspection should still be editable once `awaiting_signature` … is a real, separate product question, not raised or ruled on". Nothing in §15/§16/§45 ties a signature to a version of the report. So the flag is **not built**, and the question is put to Johan in the build report.

What IS built so the answer can be applied without losing anything: every link signature stores `signed_report_fingerprint` — a SHA-256 of the report's content at that moment (every observation with its condition and note, every live photo, every room note, the overall notes; `RentalInspection::reportFingerprint()`). Whichever rule Johan picks, a signature already given can be checked against it; nothing is lost by waiting. The two business options are in the report.

### 46.9 Reported, not changed

- The general public link (`/rental-inspection-report/{token}`) remains read-only with no Sign step; party links are separate (a party link is revocable per person; the general link is one shared token).
- The property tab's own in-person Sign buttons (canvas on the agent's device with no report view) are untouched; "Sign on this device" is the new, fuller version that shows the report first. Whether to retire the old buttons is Johan's call.
- Photos on both public pages remain static public-disk URLs (§44a item 2) — unchanged.
- The signed PDF's signature block does not print "signed from a link as <name>" or a signer's comment — only the web report page does. Not asked for; say if the PDF should carry it.

### 46.10 Tests

`tests/Feature/RentalInspections/RentalInspectionSigningLinkTest.php` — see the build report for the run.


---

## 47. Every type is signed · a signed report is locked · a sent report is final — 7 Oct 2026 (cc6; Johan's rulings) — BUILT on QA1

### 47.1 The bug that started it (property 6069)

Johan clicked **Ready to sign** on inspection #90 and was told "Only an in-, interim or out-inspection has a signing window." #90 was a **Routine** inspection — chosen from the *Next inspection* picker, stored as `ad_hoc`. `RentalInspection::startAwaitingSignature()` refused every type except in / out / interim (§15.3 had left `ad_hoc` out; §45.6 flagged it "for Johan to confirm"). Johan has now ruled: **every inspection type can be marked ready to sign and signed — by link, QR, on the agent's device and in CoreX.**

- The type check is gone from `startAwaitingSignature()`; the link service's own type list (`SIGNED_TYPES`, `NOT_SIGNABLE_TYPE`) is gone too. Nothing in the UI restricted it (the recording screen shows **Ready to sign** for whatever the tail is).
- **Signing-window length** stays the agency's own setting, one setting for every type (`out_inspection_signing_window_days`, default 7 — the column keeps its old name; the label now says "Days a tenant has to sign an inspection", in Settings and the Setup Wizard).
- **Not changed, reported (§47.8):** signing is now *allowed* for Routine; Complete is not newly *tied* to it — a Routine inspection still completes without signatures, as before, unless Johan says otherwise.

### 47.2 Routine vs Interim — two real types, one word each

`ad_hoc` and `interim` are **two different stored types**, not one under two names, so they are NOT merged:

| Shown as | Stored as | What it is | Where it comes from |
|---|---|---|---|
| **Routine** | `ad_hoc` | an unplanned mid-tenancy check | the *Next inspection* picker (Routine / Interim / Out) and the new-inspection form |
| **Interim** | `interim` | a planned mid-tenancy inspection, booked from a date the agency loaded (Due tab → *Book from this date*) | the Due tab, the new-inspection form, and now also the *Next inspection* picker |

What differs in the code today: Interim is tied to the agency's loaded dates (a booked date follows its inspection — §45.7) and carries the full completion checks (every item recorded, required notes, attendance, three signatures); Routine is the light-weight check (no every-item rule, no attendance rule, no signature requirement to complete). With signing open to every type, the *signing* difference is gone; the checks above remain (§47.8).

**One word per type everywhere** (screens, messages, mails, PDFs, history, calendar, list, filters, settings, wizard): `RentalInspection::typeLabel()` → In / Out / Routine / Interim, `typeName()` → "In-inspection", "Out-inspection", "Routine inspection", "Interim inspection". It replaces every `ucfirst(str_replace('_','-',$type))` that used to print "Ad_hoc-inspection" / "Ad-hoc" (the list filter and new-inspection form said "Ad-hoc", the picker said "Routine", the history said "Ad_hoc-inspection created"). No data or enum rename. The *Next inspection* pickers on the inspection page and the property tab now offer Routine, Interim and Out (the property-tab `next` endpoint accepts `interim`).

### 47.3 A signed report is locked (Johan: replaces options A/B)

"A signed report is locked." **Locked** = at least one live **signed** or **paper (wet-ink)** signature exists (`RentalInspection::isSignedLocked()`); voided / superseded signatures never count. A refusal or a "paper sent" marker alone does not lock — nobody has attested to the report. This applies to **every type and every way of signing** (link, QR, the agent's device, CoreX, the agent's PIN — they all end in the same signature row).

Enforced server-side on every **content** write path through one guard, `RentalInspection::assertContentEditable()` (recordable AND not signed-locked → else `RentalInspectionSignedLockedException`, HTTP 409, `reason: signed_locked`, message: *"This report has been signed, so it is locked. To change it, press "Edit report" — that clears ALL signatures and everyone must sign again."*):

observations (store, room N/A, room all-good, everything all-good) · room notes · overall notes · header details (a real change; the autosave that posts back what is already there is a no-op) · photo uploads (tray and item) · photo tag / bulk tag / untag / archive · photo notes (create, edit, archive, restore) · discrepancy resolution · scan apply · photo pairing by hand and auto-pair · the property's checklist while the open tail is signed (add item, rename, retire, restore, re-type, seed from advertising, add missing standard items). **Signing itself, attendance, link management and resend stay open** — more people can still sign a locked report. (Attendance and invitation records are append-only supersede records about who was present, not report content — reported in §47.8.)

### 47.4 "Edit report" — the one way to change a signed report

`POST /corex/rental-inspections/{inspection}/reopen` (`corex.rental-inspections.reopen`), permission **`rental_inspections.edit_details`** (the existing inspection-edit key — no new key; `.create` alone is not enough), own/branch/agency scoped. The screen (inspection page and the phone recording screen) shows **Edit report** on a locked report; pressing it shows, in red: ***"Editing clears ALL signatures. Everyone must sign again."***, asks **why** (required, 3–1000 characters) and only then acts. One transaction (`RentalInspectionReopenService::reopen()`), row-locked:

1. **Every** live signature — each tenant, the landlord, the agent, however given — is voided: marked superseded (so it never counts, never prints as a signature, exactly like a corrected wet-ink upload) and pointed at the reopen (`voided_by_reopen_id`). **Nothing is deleted.**
2. A `rental_inspection_reopens` row (append-only: an update or delete throws) records **who, when, why, the previous status, the report exactly as the signers saw it** (`report_snapshot` + `report_fingerprint` — the report is locked from the first signature to this moment, so its state now *is* what they signed), the list of voided signatures (who, role, when, how, the fingerprint on each) and the revoked link ids. Every signature, by any route, now stores `signed_report_fingerprint` (set in `RentalInspectionSignature::capture()`).
3. **All** outstanding signing links of the inspection are revoked (`revoked_reason = 'reopened'`), signed ones too — an old link must not keep showing a stale "you signed" over a changed report.
4. The report goes back to the not-signed state (`draft`, signing deadline cleared). **One report** — no second copy; the same inspection id.
5. History: *"Report reopened for editing by <name>: <reason> — N signature(s) voided, M signing link(s) revoked. Everyone must sign again."*, with the voided signatures listed. The inspection page lists each voided signature as history ("Voided … when <name> reopened the report: “<reason>”") — no image, never labelled Signed.

**Then:** the agent edits and presses **Ready to sign** again (the usual completeness checks run again). That issues **fresh links** to every party whose link the reopen revoked (issued, **not sent**; the response says how many). **Nobody is emailed automatically.** The agent sees a standing notice ("This report was edited after people signed … resend each person their link") until every voided signer has been sent theirs or has signed again. When the agent resends, a person who had signed gets the email subject *"Changed report — please sign again"* and a paragraph saying the report changed and their earlier signature no longer counts; their new link page shows the same notice. Everyone then signs again; completion is exactly as before.

### 47.5 DISTRIBUTED — the moment copies are sent — is final (Johan's second ruling)

**Definition.** A report is **distributed** from the moment copies are sent. In the code today that moment is the agent pressing **Complete**: `markCompleted()` flips the status, then `RentalInspectionRecordingController::complete()` calls `RentalInspectionCopiesService::fileAndSend()` — it files the signed PDF to the property and emails every party (the email only when the agency's auto-send setting is on; a later **Resend** is a second send of the same, already-distributed report). `RentalInspection::isDistributed()` is therefore **true when the status is `completed`** (whether or not the agency's auto-send was on) **or when any email copy to a tenant or landlord has been logged as sent** (`signed_document_distribution_log`; a copy to the inspector, creator or the agency's own address does not count). There is no way back from it.

**Rule.** A distributed report can **never** be edited or reopened — by anyone, in any role (an edit after the tenant received it would be a Rental Housing Tribunal tampering claim). Server-side, on every write path: all the content paths of §47.3 (the shared `assertRecordable()` already refuses a completed inspection — its message now says *"…It has been sent to the parties, so the only way to correct it is a new inspection that replaces it."*), signatures (store, wet-ink correction, link signing, device signing), attendance and invitations, link issue / email, ready-to-sign, complete, photo pairing (including **auto-pair, which had no completed-lock until this build**), discrepancy resolution, scan apply, header details (now answers 409 with the same message instead of 400), and **reopen**, which refuses with *"This report has been sent to the parties, so it can never be edited or reopened — by anyone. To correct it, start a new inspection that replaces it."* — in the controller **and** in the service (called directly it refuses too). Archiving and the existing tenant fault-report carve-out are separate matters (§47.8).

**The only way forward — "Start new inspection".** The screens (inspection page and recording screen) say it plainly — *"This report has been sent to the parties, so it can never be changed — by anyone. If something in it is wrong, the only way forward is a new inspection that replaces it. This one stays exactly as it was sent."* — with a **Start new inspection** button (`POST …/replace`, `corex.rental-inspections.replace`, `.create`): a NEW inspection for the same property, lease and type, status draft. `rental_inspections.replaces_inspection_id` points at the old one; "replaced by" is its inverse. The new inspection joins the chain **after the old tail** (so the replacement compares against what was recorded and nothing forks); the tail must be closed (another open inspection on the tenancy is finished or cancelled first); an inspection can be replaced once. The old inspection is not touched in any way — its report, signatures, public link and PDF stay as sent — and **both histories carry a line** ("Replaced by … #N" / "Replaces … #M"), and both inspection pages link to each other.

### 47.6 Files

Migrations `2026_10_14_200000` (`rental_inspection_reopens`), `…200010` (`voided_by_reopen_id` on signatures, `revoked_reason` on links), `…200020` (`replaces_inspection_id`). `RentalInspection` (`typeLabel`/`typeName`, `isSignedLocked`, `assertContentEditable`, `canBeReopened`, `isDistributed`, `reportSnapshot`, `startReplacement`, `replaces`/`replacedBy`/`reopens`, `updateDetails` lock, the type restriction removed), `RentalInspectionReopen`, `RentalInspectionSignedLockedException` (extends `RentalInspectionNotRecordableException`, no longer `final`), `RentalInspectionReopenService`, `RentalInspectionSigningLinkService` (lock/reopened state, re-sign flag, no type list), `RentalInspectionRecordingController` (content guard on every write path, `reopen`, `replace`, fresh links after ready-to-sign, photo-pairing locks), `RentalInspectionRoomChecklistController`, `RentalInspectionPhotoNote`/`…Controller`, `RentalInspectionScanController`, `RentalInspectionSignature` (fingerprint at every signing), `RentalInspectionSigningLinkMail` + view + the party page (re-sign notice), `_report-lock.blade.php` + `rental-inspection-signing.js`, the inspection page (voided signatures, replaces / replaced by), the recording screen and property tab (lock include, the voided-agent-signature fix, Interim in the picker), label sweep (notifications, calendar, timeline, PDFs, list, filters, history, settings, wizard).

### 47.7 Tests

`RentalInspectionAllTypesSigningTest` (29), `RentalInspectionSignedLockTest` (35) and the updated `RentalInspectionSigningLinkTest`, `RentalInspectionWorkflowTest`, `RentalInspectionDueDatesTest`. Every write path above is exercised against a signed report and against a completed (distributed) one, for each type where it matters, asserting HTTP 409 **and** that nothing changed.

### 47.8 Reported, not decided (questions for Johan)

**Where a copy can leave CoreX before "distributed" (today these are all NOT treated as distribution — listed, not decided):**
1. A tenant or landlord who signed from their link can **download the report PDF** (`/rental-inspection-sign/{token}/report.pdf`) the moment they sign — before the agent completes it; and every link shows them the full report page, which has a Print button.
2. The agent's own **Print / PDF** of an unfinished report (`…/report`, `…/print-for-signature`, `…/form`) and the **general public report link** (`/rental-inspection-report/{token}`), which can be generated before completion.
3. **Paper copies** printed for a wet-ink signature, and the **signing-link email/WhatsApp itself** (it carries a link to the full report, not an attachment).
4. **Portal:** the tenant and landlord client API lists every inspection of their lease with id, type and status — including ones not completed — but no report content; the Inspections region of the portal (Q13) is not built.
5. Once a report is *signed* the parties have in practice already seen it; the lock plus "Edit report" (which voids their signatures and tells them) is what covers that window.

**Write paths that still touch a distributed inspection, left as they are:** (a) a **tenant fault report** filed inside the fault-report window appends an observation to the completed In-inspection (§3.5 — a deliberate legal mechanism: the tenant's own addition); (b) **move-out comparison findings** (the agent's classification of a difference on a completed Out, §45.14) are separate append-only records that the PDF and public page do not print; (c) **archiving** a completed inspection (needs `archive_completed`) hides it, it does not change it; (d) **renaming or retiring a property checklist item** changes how an already-sent report reads when re-rendered (the report is rebuilt from the live checklist — the standing "retired items vanish from completed reports" question, now also covering renames); a snapshot of the item names at completion would close it; (e) **attendance and invitation records** are not locked by a signature (they are append-only "who was present" records, and a representative or a non-attendee can sign before them).

**Behaviour left as it was, to confirm:** a **Routine** inspection still completes without signatures, without every-item grading, without required-note checks and without attendance (only In / Out / Interim carry those); should Routine now require the three signatures before Complete, like the others?

---

## 48. AT-436 + AT-437 re-verified against QA1, and the two bug CLASSES closed properly — 7 Oct 2026 (cc6; Johan's instruction)

Both tickets were still "In Progress" in Jira. Checked against the QA1 code of 7 Oct (`66bb84573`), not against what the spec says happened.

### 48.1 AT-436 (photos silently lost) — core ALREADY FIXED; four neighbouring silent-drop paths found and fixed now

**Already fixed, by:** `b7ee63e15` (server: a photo with no condition lands on a holding observation, `CONDITION_PENDING = ''`, which never counts as recorded — §20.22) and `2cc8951f9` (screen: no staging, every pick uploads at once, the PENDING tile means "uploading now", a failed upload shows Retry — §20.23). Confirmed in today's code: `onItemPhotosSelected()` has a single path and posts immediately; `storePhotos()` resolves/creates the holding row; `roomProgress()`/`inspectionProgress()` read `.condition`. Proven again by `RentalInspectionPhotoSafetyTest::test_a_photo_added_before_any_condition_is_still_there_after_a_reload_and_the_item_stays_unrecorded` (photo-only item survives a fresh read of the page payload; the counters still read 1 of 2).

**Still open in the same class — fixed now**, all in the shared uploader `public/js/corex-photo-batch-uploader.js` (used by the Inspections screen and the untagged tray / room photos):

| # | How a photo was still lost | Fix |
|---|---|---|
| 1 | One photo over 50 MB in a selection pushed a single failed row and **returned** — every other photo picked with it was never uploaded and never reported | Each oversize file gets its own visible failed row; the rest upload normally |
| 2 | Closing the tab / reloading / navigating while a photo was uploading **or had failed** lost it with no warning (the page's only `beforeunload` watches the property edit form) | One page-wide `beforeunload` guard over every uploader: warns while any batch is `uploading` or `failed`; silent when all landed. `window.corexUnsavedPhotoCount()` exposes the number |
| 3 | An exception in the post-upload callback left the batch promise unresolved, so every **later** batch of the same selection was never sent | Callback wrapped; the promise always resolves (photos are already saved at that point) |
| 4 | An aborted request fired neither `onload` nor `onerror` — the row sat on "Uploading…" forever and the queue behind it stalled | `onabort` ends it as a visible failed row with Retry |

**Other recording surfaces, checked:**
- *Phone recording screen* = the same web screen on a phone (same uploader, same fixes). The CoreX mobile app's rental-images upload (`MobileRentalImagesController::upload`) writes under a row lock, is idempotent by `client_upload_id`, and refuses to return 2xx unless the file is on disk — nothing staged, nothing to lose.
- *Link signing / public report* (`rental-inspection-sign/*`, `rental-inspection-report/*`): read and sign only; no route accepts a photo. `test_the_link_signing_and_public_report_pages_accept_no_photo_uploads` fails if one ever does without these guarantees being added.

**Known and NOT changed (reported):** the uploader mints a fresh idempotency key on every attempt, so a Retry after a network error where the server had actually saved the photos can create a duplicate photo (visible, archivable — not a loss). The header comment of the file claims the opposite. Not changed: it is a duplicate, not a drop, and outside this ticket.

**Limit that cannot be engineered away:** a phone that discards a backgrounded tab mid-upload gives the page no chance to warn. What changed is that every deliberate way of leaving now warns first, and every failure is visible with a Retry.

### 48.2 AT-437 (dead controls) — ALREADY FIXED; the class now has the guard it never had

**Already fixed, by** `e3c615507` (27 Sep): `isMarkGoodBusy()` / `isMarkNaBusy()` / `isDiscBusy()` return `!!this.xBusy[key]`, bound in both `:disabled` and `x-text` (spec §26). Fresh sweep today of every `:disabled/:readonly/:required/:checked/:hidden/:selected` binding in `show.blade.php`, the `rental-inspection-*` partials, `corex/rental-inspections/**` and the public inspection pages: no remaining raw lookup on a lazily-populated object except the two `startBusy[...]` ("Start In/Out-Inspection") buttons, which §26.3 judged safe because they are not inside an `x-for`.

**Done now:** those two were converted too (`isStartBusy(section)`, same pattern) so the rule has **no exceptions** and can be enforced mechanically: `RentalInspectionPhotoSafetyTest::test_no_boolean_attribute_binding_on_an_inspection_screen_reads_a_lazy_object_by_raw_bracket_lookup` scans every inspection Blade file and fails the build on any boolean-attribute binding that reads `obj[key]` unless the lookup is negated (`!obj[key]`). A self-test (`test_the_lint_itself_catches_the_exact_shapes_that_shipped_dead`) feeds it the four dead shapes and the safe shapes so the lint cannot rot into a pass-everything. A third test pins that the five coercing methods exist and are actually bound.

**Not proven in a browser** (instruction for this task: no browser harness). The statement "an agent can click All Good" rests on: the binding now returns a real boolean; the lint; and the click-through checks #21–#23 that §26.5 added to `scripts/rental-click-through.mjs`, which were never run end to end. **Open point worth knowing:** Alpine 3.15.3's `bindAttribute` (the version QA1 bundles) *removes* `disabled` for `undefined` — the code does not show the "undefined disables the button" mechanism the ticket and §26.1 describe. The fix is the proven `isObsBusy` pattern and is harmless, but if Johan still finds a dead control on this screen, the cause is something other than the one named in AT-437 and the click-through gate (`node scripts/rental-click-through.mjs`) is the tool to find it.

### 48.3 Files

`public/js/corex-photo-batch-uploader.js` (4 fixes + guard) · `resources/views/corex/properties/show.blade.php` (`isStartBusy`, one button) · `resources/views/corex/properties/partials/rental-inspection-recording.blade.php` (the other Start button) · `tests/js/photo-batch-uploader.mjs` (new — 25 checks against the real uploader in a node sandbox; the QA1-tip uploader fails 3 of them and crashes on a 4th) · `tests/Feature/RentalInspections/RentalInspectionPhotoSafetyTest.php` (new). No migration, no backend change, no change to the signing/lock rules (§46/§47).

### 48.4 Verification (7 Oct 2026)

`php -l` clean · `node --check` clean · `view:clear` · `bash scripts/lane-test.sh`: `RentalInspectionPhotoSafetyTest` 7 passed (46 assertions); `RentalInspectionRecordingControllerTest` 110 passed (292 assertions, includes the §20.22 backend cases) · `node tests/js/photo-batch-uploader.mjs` 25/25 (against the QA1-tip uploader: 3 fail, 1 crashes).

---

## 49. Johan's rulings of 8 Oct 2026 — downloads only once everyone has signed · DRAFT stamp · signatures per type · all four types everywhere · added-after-sent · checklist wording fixed (cc6) — BUILT on QA1

Answers the open questions of §47.8 and the "Behaviour left as it was" line there. QA1 only; nothing on Staging or live; no data repaired.

### 49.1 What "everyone has signed", "sent", "final" and "locked" mean (the vocabulary these rules share)

| Word | Defined by | Meaning |
|---|---|---|
| **Fully signed** | `RentalInspection::isFullySigned()` | Every tenant on the lease, the landlord (when the property resolves one) and the agent each hold a LIVE drawn / link / PIN signature or a paper signature on file. A refusal, a "paper sent" marker and a voided signature are not signatures. |
| **Distributed / sent** | `isDistributed()` — **unchanged (§47.5)** | Completed, with the copies sent (or an email copy to a tenant/landlord logged as sent). **A party's own signature does not make a report "sent".** |
| **All required parties signed** | `allRequiredPartiesSigned()` | `! signaturesRequired() || isFullySigned()` — honours the agency's per-type setting (§49.4): where signatures are optional (Routine by default) nothing is outstanding; where required, every party must hold a live signature. A refusal never counts. |
| **Party copy available** | `partyCopyAvailable()` | `allRequiredPartiesSigned() && (isDistributed() || isFullySigned())`; never for a cancelled or archived inspection. So a required-type report with a refusal on record never opens a party-side PDF, even once completed. |
| **Final report** | `isFinalReport()` | Completed. (Completion itself requires the agency's signatures for the type — §49.4 — so a completed report is final.) |
| **Locked** | §47.3 / §47.5 — **unchanged** | A signed report's content is locked (changed only by "Edit report"); a sent report can never be edited or reopened. |

**Held for Johan (§49.9 Q1):** the ruling reads "a party's own signature does not lock the report". Read as "a party's own signature is not what *sends* the report — sending stays Complete + copies sent", which is what is built. §47.3's "a signed report is locked" (his ruling of 7 Oct) was NOT removed.

### 49.2 Rule 1 — a tenant's / landlord's PDF exists only once EVERYONE has signed (and stays after)

Every path a party can take a copy of the report by, server-side (the button is hidden too, but the server refuses):

| Path | Before everyone has signed | Once fully signed / after completion |
|---|---|---|
| Signing link PDF — `GET /rental-inspection-sign/{token}/report.pdf` (`RentalInspectionSigningController::pdf`) | **403** with the plain page `public/download-not-ready` ("The PDF isn't ready yet…"), nothing generated | the PDF, as before |
| Signing page (`public/show` + `partials/sign-section` + `partials/download-link`) | the report is on screen; the "Download the report (PDF)" button is replaced by "A PDF copy … becomes available once everyone has signed it…" (also under the party's own "Thank you, your signature is recorded" confirmation) | the button |
| Browser Print / Save-as-PDF on the signing page and on the general public link (`/rental-inspection-report/{token}`) | the Print button is replaced by "Printing and the PDF open once everyone has signed"; a `@media print` rule prints only "This report can only be printed … once everyone has signed it" instead of the report | the Print button and the report print |
| Agent's "Sign on this device" page | same page, same rule (it shares `reportData()` and `signingContext()`) | same |
| Portal Documents (tenant / owner) — `RentalPortalDocumentService::inspectionIsShareable()` | not listed — offered only when the report has been **sent** (`isDistributed()`) **and** `allRequiredPartiesSigned()`; the file route 404s | listed. The portal names the type with the one shared wording (`RentalInspection::typeLabel()` / `typeName()`: In, Out, Routine, Interim — it used to say "Move-in", "Move-out" and "Ad hoc"). |
| Emailed copy at completion / Resend (`RentalInspectionCopiesService::fileAndSend()`) | refuses a report that is neither completed nor party-copy-available (`LogicException`); the agent's Resend endpoints already refuse a non-completed inspection. The completion mail is the agency's own act and is unchanged (it also goes out when a refusal is on record — see §49.9 Q4) | sent |

`public/show.blade.php` is the one page for the general link, a party's link and the agent's device, so all three agree. Wet-ink scan links on that page are a party's own paper signature and unchanged.

**The agent's own Print / PDF is not governed by this rule** (§49.3). The blank capture form (`…/form`) is not a report and is untouched.

### 49.3 Rule 2 — the agent may print an unfinished report; every page says "DRAFT - not final"

`RentalInspectionReportPdfService::generate()` / `generateForSignature()` pass `isDraft = ! isFinalReport()` to `corex.rental-inspections.report-pdf`. When true, two `position: fixed` elements repeat on **every page** (DomPDF repeats fixed elements): a bold band in the top margin and a faint diagonal across the page body, both reading exactly **DRAFT - not final**. The stamp is absent only when the inspection is completed. So: draft, ready to sign, part signed, fully signed but not completed, cancelled → stamped; completed → clean. A party downloading after everyone has signed but before the agent presses Complete therefore receives a stamped PDF — the stamp leaves only on the completed report (Johan's wording). The emailed copy at completion is generated after the status flips to completed, so it is clean.

### 49.4 Rule 3 — signatures per inspection type are an agency setting

Migration `2026_10_16_100000`: four nullable booleans on `rental_inspection_settings` — `signatures_required_in`, `_out`, `_interim`, `_routine` (Routine = the stored `ad_hoc`). Read-time default (`RentalInspectionSetting::signaturesRequiredFor($agencyId, $storedType)`): **In, Out, Interim required; Routine optional**; an unknown type is treated as required. **Required** = `markCompleted()` refuses until every tenant, the landlord and the agent have an outcome and the agent has signed (the existing §15.7 gate, unchanged in content); **optional** = it completes without them. What stays by type and is NOT a setting: every-item-recorded, required notes and attendance (§45.3, §45.5) — Routine remains exempt from those (the existing `all_items_required_to_complete` setting still governs the first). Signing itself is open for every type (§47.1) whatever the setting says.

Surfaced in **Settings → Rental inspections** ("Signatures needed before an inspection can be completed", four ticks) and in the **Setup Wizard** (four toggle controls with explain/affects, config/agency-onboarding-copy.php, values in `AgencySetupWizardController::currentValues()`), both through the one shared saver `RentalInspectionSettingsController::update()` — each field `has()`-guarded so a step that renders only some cannot wipe the rest (§6.1).

### 49.5 Rule 4 — all four types wherever an inspection is started or scheduled

One list, `RentalInspection::TYPE_PICKER` / `typePickerOptions()`: **In** — move-in condition · **Routine** — an unplanned mid-tenancy check · **Interim** — a planned mid-tenancy inspection, from a date you loaded · **Out** — move-out condition.

Where only three (or one) showed, and what changed:

| Place | Before | Now |
|---|---|---|
| Inspection page → **Next inspection** (`corex/rental-inspections/show.blade.php`) | Routine, Interim, Out — **no In** | all four |
| Property **Inspections tab** header → **Next inspection** (also what the phone recording screen shows — it is the same tab) (`corex/properties/show.blade.php`) | Routine, Interim, Out — **no In** | all four |
| Property **Inspections tab**, first inspection of a property | a single "Start In-Inspection" button — **only In** | a picker with all four + "Start inspection" |
| `POST …/rental-inspections/start` (tab) | accepted In, Out, Routine — **not Interim** | accepts all four |
| New-inspection form (`rental-inspections/create`, reached from the list, the lease hub, the command centre and the Due tab) | all four, own hand-written list | all four from the shared list |
| List filter | all four | unchanged |

**In after other inspections.** An In is offered in the Next pickers. A tenancy has one move-in condition, so `RentalInspection::startNext()` now accepts `in` when the lease has no live In-inspection (it chains after the predecessor like any link) and refuses it plainly ("This tenancy already has an In-inspection (#N)…") when it has one; the pickers grey the option out with that reason (`leaseInInspection()`, `lease_in_inspection_id` on the tab payload). Start messages now say "Routine inspection started." (they said "Ad_hoc-inspection started."). Also fixed in the same sweep: the portal Documents label "Ad hoc" → "Routine"; the PDF cover and the inspection page said "Compared against the ad_hoc-inspection".

### 49.6 Rule 5 — added after the report was sent

`RentalInspectionAddedAfterSentService::entriesFor()` is the one builder (inspection page, PDF, general public link, a party's link, the agent's device page). A block titled **Added after the report was sent**, boxed apart from and below the report body, says "The report above is exactly as it was sent", and lists each entry with its kind, date/time and who:

* a **tenant fault report** — an observation with source `tenant_fault_report` created at or after the inspection was completed (the §3.5 window; filed through the existing observation endpoint). `RentalInspection::isAddedAfterSent()` / `bodyObservations()` keep it OUT of the body: the item's condition and notes in the body read exactly as sent. Who = the tenant when the record names one, otherwise "<agent> (recorded for the tenant)". Its photos, if any, appear in the block on the web pages and are counted in the PDF.
* a **move-out comparison finding** — a live (not superseded) finding on a completed Out recorded at or after completion. Findings recorded before completion are not part of this block (they were never printed and still are not); a corrected finding shows only its live version. **New:** these findings are now shown on the PDF and the web report pages at all (§47.8 said the PDF and public page did not print them).

Unchanged: the write paths themselves (the fault-report window; the findings screen), archiving, and attendance records. Not changed: the Inspections tab's read-only predecessor panel and the Out comparison still read a tenant fault report as the In's latest condition for an item (it is a comparison working surface, not the sent report).

### 49.7 Rule 6 — a sent (or signed) report keeps the checklist wording it was sent with

`rental_inspections.checklist_wording_snapshot` (JSON `{item id: label}` for every item of the property, live or retired) + `checklist_wording_snapshot_at` (migration `2026_10_16_100010`). Taken, fill-if-empty (`ensureChecklistWordingSnapshot()`), at the first live signed / paper signature (`RentalInspectionSignature::capture()` — every route funnels through it) and at completion (`markCompleted()`); **cleared by "Edit report"** (nobody's signature stands, `RentalInspectionReopenService`; what the signers saw stays in the reopen record's `report_snapshot`, which now stores the snapshot wording). Every report builder applies it in memory (`applyWordingSnapshot()` sets the label and syncs the original — nothing is ever written back to the checklist): the PDF, the public / signing / device pages, the inspection page (items, discrepancies, comparison rows), the reopen snapshot and the added-after-sent block. An item added later is simply not in the snapshot and keeps its own label; a retired item was already kept on a report that assessed it (§47 scope `listedOnReportOf`) and now also keeps its wording. A rename or a retire of the *live* checklist still works exactly as before — the checklist changes, the sent report does not.

**Existing inspections on QA1 (no data repair).** The column starts empty, so every report that exists today reads from the live checklist exactly as before. Counts on QA1 at build time: **6 completed (sent) inspections** (all 6 carry signatures), **3 open inspections already carrying a live signature**; 0 retired checklist items on those properties; 0 tenant fault reports and 0 move-out findings filed after a completion. A back-fill would freeze *today's* wording, which may already differ from what was sent and cannot be proved either way — it is **not done; asked of Johan (§49.9 Q2).**

### 49.8 Files

Migrations `2026_10_16_100000_add_signature_requirements_to_rental_inspection_settings_table`, `2026_10_16_100010_add_checklist_wording_snapshot_to_rental_inspections_table`. `RentalInspection` (picker list; `signaturesRequired`, `isFullySigned`, `partyCopyAvailable`, `isFinalReport`, `isAddedAfterSent`, `bodyObservations`, wording snapshot methods, `leaseInInspection`, `startNext` In rule, `markCompleted` gate + snapshot, `reportSnapshot`), `RentalInspectionSetting` (signature requirement constants + `signaturesRequiredFor`), `RentalInspectionSignature::capture` (snapshot hook), `RentalInspectionReopenService` (snapshot clear), `RentalInspectionAddedAfterSentService` (new), `RentalInspectionReportPdfService` + `report-pdf.blade.php` (stamp, wording, body observations, marked block), `RentalInspectionPublicController::reportData` + `RentalInspectionSigningController` (download gate, `pdf_available`), `public/show.blade.php`, `public/partials/sign-section` + `download-link` + `public/download-not-ready` (new), `RentalInspectionController` (show: wording, marked block, `next` accepts all four), `RentalInspectionRecordingController` (`start`/`next` accept all four), `RentalInspectionCopiesService` (guard), `RentalPortalDocumentService` (gate, label), `RentalInspectionSettingsController` + settings page + `config/agency-onboarding-copy.php` + `AgencySetupWizardController::currentValues`, `corex/rental-inspections/create`, `show` and `corex/properties/show.blade.php` (pickers).

### 49.9 Tests and questions

Tests (one file at a time through `scripts/lane-test.sh`): `RentalInspectionRulingsDownloadsTest` (24), `RentalInspectionRulingsSettingsAndPickersTest` (39), `RentalInspectionRulingsAfterSentAndWordingTest` (20), `RentalImagesTabRendersTest` updated (its "In-only button" assertion encoded the old rule). Every download path is asserted refused in every pre-signature state for every type, opened once fully signed, and still open after completion; the stamp is asserted on EVERY page of a multi-page PDF (pdftotext) and absent on the completed report.

**Questions for Johan (business, not decided):**

1. *"A party's own signature does not lock the report."* Yesterday's ruling was "a signed report is locked" (Edit report voids every signature). Is the lock to stay — edits after someone has signed go through **Edit report** — or should a signature no longer block edits? If it should not block: an agent could change the report under a tenant's signature, and we would need your rule for what then happens to that signature.
2. The 6 sent reports (and 3 signed open ones) on QA1 pre-date the wording snapshot. Back-fill them with today's wording (cannot prove it matches what was sent), or leave them reading from the live checklist as before?
4. A tenant who **refused** to sign: the report can still be completed and the agency's completion email still goes to them, but the party-side PDF (their link, the portal) stays closed because "all parties have signed" is not true. Should the completion email also hold back the PDF for them?
3. A Routine inspection is still exempt from "every item recorded", required notes and attendance (only the signatures became a setting). Should those follow the per-type setting too?

Reported, not changed: the Inspections tab's read-only panels show the live checklist wording and a post-sent fault report as the In's latest condition (a working comparison surface); room names are not part of the wording snapshot; `…/form` (blank capture form) carries no stamp.


---

## 50. The Rentals inspections walk, 8 Oct 2026 (cc4; QA1 only) — what was found, what was fixed, what waits for Johan

A walk of the whole inspection life as an agent does it (In, Interim, Routine, Out: schedule → due board → record → photos → attendance → ready to sign → sign on the device / by link / by QR → locked → edit report → distributed → final → portal → PDF) against §0–§49 and the code at QA1 `3cd6adb73`, read line by line and driven with real requests on QA1 data inside rolled-back transactions. Step table and click-by-click test script: `/tmp/qa1-cc4-inspections-walk-2026-10-08.md`. No browser harness was available; button behaviour on the recording screen was checked by route/URL existence and server-side guards only.

### 50.1 Fixed (each has a test in `RentalInspectionWalkFixesTest`; all 21 fail on the QA1 tip and pass now)

| # | Defect | Class | Fix |
|---|---|---|---|
| 1 | "Whose inspection is it" had five answers (list, planned dates, due board rows, the "Agent" column + reminders, the per-record guard) and none included the lease's **owner's agent / tenant's agent** | one rule per surface, none shared | Two small, named lease rules (`Lease::scopeAgentedBy()` / `isAgentedBy()` = owner's agent OR tenant's agent; `scopeInvolvingUsers()` = those two OR the lease creator, which `scopeVisibleTo` 'own' now uses). `RentalInspection::scopeVisibleTo` 'own', the per-record guard (`AuthorizesRentalRecordScope`) and `RentalInspectionPlannedDate::scopeVisibleTo` add the lease's AGENTS to creator/inspector (merely having created the lease still does not show a colleague's inspection — that refusal is tested and kept); the Due board's lease constraint uses the creator-inclusive rule it already had plus both agents. `RentalInspectionDueService::responsibleAgentId()` = the lease's owner's agent, else tenant's agent, else the property's agent, else the lease creator |
| 2 | A second In-inspection could be started or scheduled on a tenancy that already had one (only `startNext` refused it); also made unlinked chain roots | several entry points, no single rule | `RentalInspection::assertTypeStartable()` — one rule used by `start()` and `schedule()` (and carried by `startNext()`) |
| 3 | Archiving a scheduled inspection left its calendar event "pending"; restoring never revived it | side effect hooked on the caller, not the model | model `deleted`/`restored` events call the calendar sync |
| 4 | Signing-links panel feed returned each party's live link to anyone who can merely view the inspection (a view-only user could sign as the tenant) | secret exposed by a read weaker than the write that mints it | `url` removed from `describeLink()` (the JS never used it) |
| 5 | A link for someone no longer on the lease showed a sign form that could never work, then a raw internal sentence | link validated against the link row, not the party it names | `signability()` checks the party is still on the inspection |
| 6 | A refused signature (double tap, party left) left its PNG on the private disk | file stored before the invariants are checked | `RentalInspectionSignature::capture()` removes the files it was handed when it refuses |
| 7 | A link signature could land just after "Edit report" read the live signatures | lock order | `submit()` locks the inspection first, then the link (the order reopen takes) |
| 8 | Due board print / export had no `rental_inspections.export` gate (the list's own have) | permission on one of two sibling endpoints | middleware on both routes; buttons hidden without it |
| 9 | List search "Jane Smith" found nothing | single-column LIKE | also matches first + last name together |
| 10 | Reminder commands could only walk every agency (no scheduler on QA1, so a hand run touched all) | | `--agency=` and `--dry-run` on `rentals:send-planned-inspection-reminders` / `rentals:send-due-inspection-reminders` |
| 11 | Checklist reorder (items, rooms, apply default order) worked on a signed report | one guard missing from three siblings | same `refuseIfTailSigned()` as the other checklist writes |
| 12 | "Build from advertising" burned its one shot when it built nothing | flag set unconditionally | refuses (409) and keeps the flag when no room or item was made |
| 13 | A 70 000-character note and a key count of 99999999999 were HTTP 500s | unbounded input | `max:4000` on the note, `max:1000` on keys/remotes |
| 14 | A discrepancy resolved twice overwrote the first decision (ruling 4: append, never overwrite) | | 409 "already resolved by … on …" |
| 15 | The signed PDF dropped the header block (meters, keys, remotes, furnished, type, original move-in date) — the deposit evidence | two renderers of one report | "Details" block on the PDF cover, nothing when nothing recorded |
| 16 | A tenant could appear on the signing roster as the landlord (property with only a tenant linked) | lenient "sole contact" resolver used for signing | every inspection roster/attendance/signing/PDF-form lookup uses `Property::landlordContact()` (strict); no landlord linked → the existing "Not required — no landlord linked" row |
| 17 | The property tab's "Resend report" popover rebuilt its recipient list in the browser (wrong people) | two sources of truth | tab payload carries the server's own `distributionRecipients()` as `report_recipients` |
| 18 | "Ready to complete" pill ignored the per-type rules (Routine, signatures setting) | display disagreed with the server | tab payload carries `signatures_required` / `attendance_required`; the pill uses them |
| 19 | "Ad hoc" / `ad_hoc` / "Routine-inspection" stragglers: rental reports (group-by-type, lease timeline), PDF file names, the tab's JS, one permission label | label sweep missed | `typeLabel()` / `typeName()` everywhere; file name `inspection-report-routine-…` |
| 20 | `resend-report` answered 500 when the PDF or filing failed | missing failure path | same alert as Complete, 502 with a plain message |
| 21 | `rental-inspections:backfill-report-filing` also filed archived inspections | | live inspections only |
| 22 | stray `"` in the create form's date input | | removed |

### 50.2 Found and NOT changed (Johan's call, or outside this slice)

1. **QA1 sends no inspection mail** (signing-link emails, "Email everyone", scheduling mails, completion copies): `MAIL_NON_PRODUCTION_REDIRECT` is not set in `/corex-qa1/.env`, so the guard (`SignedDocumentDistributionService`, correctly) suppresses every send. Environment, not code. See the decision list.
2. **Deposit deductions / settlement** — PARKED by Johan on 6 Oct for the finance build. The Move-out comparison page exists and says honestly that no amount is calculated.
3. **The 7-day signing window and the 7-day tenant fault window are only dates on screen** (`signing_deadline_at` is printed and never enforced; a tenant fault report outside the window is refused instead of "accepted and flagged"; `reported_outside_window` is never set).
4. **Property tab access for a lease agent who is not the property's agent**: the inspection now appears in their list and they can open and record on it by id, but the Inspections TAB lives on the property page, whose own visibility is property-scoped (agent_id) and not lease-aware. Out of this slice (property visibility is a system-wide rule).
5. **Calendar event goes on the inspector's calendar only** (default inspector = the booking user). Putting it on the other lease agent's calendar is a business choice (decision list).
6. The due board still says "Move-in / Move-out" where every other screen says "In / Out" (the §47.2 sweep did not name it). Left as is — decision list.
7. The mobile app's rental-images endpoints still write the old flat gallery, not inspections (§14 contract not yet consumed) — for Andre.
8. A cancelled Out-inspection still accepts move-out classifications (low). The due/create pickers cap at 500 properties/leases (low, matters for an agency with more than 500 active tenancies). The portal copy is frozen at completion, so "added after sent" never appears there (by design; asked).
9. The `cc4-inspections-i7-2026-10-07` worktree holds ~980 uncommitted lines of an earlier, parallel build of the move-out comparison that QA1 already has in a larger, tested form. No defect in QA1's version was found that the WIP fixes. It was not merged; diff kept in the scratchpad of this session.

### 50.3 Files

`app/Models/Lease.php`, `RentalInspection.php`, `RentalInspectionPlannedDate.php`, `RentalInspectionSignature.php`; `app/Http/Controllers/Concerns/AuthorizesRentalRecordScope.php`; `CoreX/RentalInspectionController.php`, `RentalInspectionDueController.php`, `RentalInspectionRecordingController.php`; `app/Services/Rentals/RentalInspectionDueService.php`, `RentalInspectionDueReminderService.php`, `RentalInspectionSigningLinkService.php`, `RentalInspectionAttendanceService.php`, `RentalInspectionFormSeeder.php`, `RentalInspectionFormPdfService.php`, `RentalInspectionReportPdfService.php`, `RentalReportService.php`; the two reminder commands and the backfill command; `routes/web.php` (two middleware); `config/corex-permissions.php` (one label); `report-pdf.blade.php`, `due.blade.php`, `create.blade.php`, `properties/show.blade.php`, `properties/partials/rental-inspection-recording.blade.php`. No migration. No new setting (so nothing new for the Setup Wizard, CLAUDE.md #10a). Tests: `RentalInspectionWalkFixesTest` (21, new); `RentalInspectionReadyToCompletePillTest` extended.


---

## 51. Decisions taken while Johan was away — the 15 questions of the §50 walk, built at their recommended defaults (cc4, QA1 only, 8 Oct 2026)

Johan was offline until Sunday and instructed that the recommended default of each open question be built, consistent with his rulings of 6–8 Oct (signed report locked; Edit report wipes signatures and needs re-sign; a distributed report is final and a change is a new inspection; a signed-but-not-distributed report stays editable through Edit report; Routine is on the ready-to-sign list). **Every business rule below is an agency setting** (`rental_inspection_settings`, nullable, read at run time — `RentalInspectionSetting::INSPECTION_RULES` / `ruleFor()`), at Settings → Rental inspections AND in the Setup Wizard Rentals step (CLAUDE.md #10a; one list feeds the page, the has()-guarded saver and the wizard so they cannot drift). Switching a rule off restores the old behaviour at once; nothing is deleted. Migration `2026_10_19_100000` (9 nullable columns + `rental_inspection_signing_notices`).

| # | Question | Built | Setting (default) | Undo |
|---|---|---|---|---|
| 1 | QA1 test mail | (§50) `MAIL_NON_PRODUCTION_REDIRECT` set on QA1 | environment | delete the line |
| 2 | Keep the lock + Edit report | unchanged (§47) — signed ⇒ locked, Edit report voids every signature, distributed ⇒ final | not a setting (Johan's ruling) | — |
| 3 | Signing window | the window length stays `out_inspection_signing_window_days`; the inspection page and the Inspections tab show **days left / closed N days ago and how many still have to sign** (`RentalInspection::signingWindowStatus()`); daily command `rentals:send-signing-window-reminders` (07:30; `--agency`, `--dry-run`) reminds the inspecting agent (inspector, else creator — never a tenant or landlord) N days before the closing day and the day after, once each (`rental_inspection_signing_notices`, UNIQUE per inspection+milestone), recorded on the inspection's History; milestones older than 3 days are logged skipped (go-live safety). Nothing is done automatically to the inspection | `signing_window_reminders_enabled` (on), `signing_reminder_lead_days` (2; 0 = only after it closed), `out_inspection_signing_window_days` (7) | turn off |
| 4 | Tenant 7-day fault window (stamp inside/outside, outside waits for the agent) | **NOT built — still open.** No screen creates a `tenant_fault_report` observation (the portal fault flow is cc6's lane and does not write to the inspection); stamping needs that connection designed | — | — |
| 5 | Lease agents' calendar | a booked inspection also goes on the calendar of the lease's owner's agent and tenant's agent (category `rental_inspection_lease_agent`, one per agent, never duplicating the inspector; follows reschedule, cancel, archive/restore; extras are dismissed, never deleted) | `calendar_include_lease_agents` (on) | turn off — extras are dismissed |
| 6 | No landlord linked | unchanged from §50 (strict landlord; "Not required — no landlord linked") | — | — |
| 7 | Cancelling a signed, unsent report | refused with "press Edit report first"; Edit report clears the signatures and records why, then cancel works | `cancel_signed_requires_edit` (on) | turn off |
| 8 | Photos on everything | **the count is always shown** at Ready to sign ("N items have no photo", hover lists them; N/A items excluded); optional hard stop | `photos_required_to_sign` (off) | turn off |
| 9 | Empty checklist | Ready to sign / Complete refused with "add the rooms and items first" while "every item recorded" is on | `empty_checklist_blocks_signing` (on) | turn off |
| 10 | Agency identity on the report | the PDF cover and the web report open with the agency name and logo (`Agency::publicBrandingFor()` — the portal header's own source; the logo is embedded in the PDF) and the inspecting agent's name, role, phone and email | `report_shows_agency_branding` (on) | turn off |
| 11 | Routine checks | Routine follows every-item-recorded, required notes and attendance (signatures remain their own per-type setting, Routine optional) | `routine_follows_full_checks` (on) | turn off — Routine is light again |
| 12 | Refused signature | the completion email to the person who refused carries the link but no PDF attachment; everyone else, and the agency copy addresses, unchanged | `hold_pdf_when_refused` (on) | turn off |
| 13 | Due board wording "Move-in/Move-out" | left as is (no recommendation to change) | — | — |
| 14 | Property tab visibility for a lease agent who is not the property's agent | **NOT built — still open** (property visibility is a system-wide rule) | — | — |
| 15 | Back-fill the checklist wording snapshot for the 6 already-sent reports | not done (recommended: leave) | — | — |

Roles / scoping: no new permission keys — the settings saver keeps the existing settings permission; the reminder goes only to the inspecting agent; the calendar extras are scoped to the inspection's agency; every reminder is on the inspection's History (`signing_reminder`). Tests: `RentalInspectionDefaultsTest` (16). Files: `RentalInspectionSetting`, `RentalInspection` (`signingWindowStatus`, `itemsWithoutPhoto`, `reportBranding`, `isRoutineExemptFromChecks`, guards in `startAwaitingSignature`/`cancel`/`guardUngradedItems`), `RentalInspectionSigningReminderService` + `RentalInspectionSigningReminder` + `SendSigningWindowReminders` + `routes/console.php`, `RentalInspectionCalendarSyncService`, `RentalInspectionCopiesService`/`SignedDocumentDistributionService` (`withoutPdfEmails`), `report-pdf`, public report, recording partial, inspection page, settings controller/page, wizard config + values.
