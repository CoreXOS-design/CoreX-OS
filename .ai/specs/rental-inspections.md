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

Johan: "the small images is a waste of time. it either has to show it big enough like on gallery... maybe
show 1 row of photos, then expand to see more?" The room-photo strip now uses the SAME tile size/grid as
the property Gallery (`rental-section-body.blade.php`: `grid-cols-3 sm:grid-cols-5`, `aspect-ratio:1/1`,
`object-cover`) — every photo still renders in the DOM (never truncated server- or client-side), clipped
to roughly one row (`max-height:6.5rem; overflow:hidden`) by default via a per-room, per-section
`roomPhotosExpanded[roomId]` flag, with a "Show all N" / "Show less" toggle appearing once a room has more
than 3 (the tighter of the two responsive column counts, so the toggle is never missing on mobile even
though it's occasionally a harmless no-op on desktop at exactly 4–5 photos). Never gated on a hardcoded
photo count for WHETHER to clip — only the toggle's own visibility threshold, chosen to hold reasonably at
both breakpoints rather than requiring a live column-count read. A 15-room property no longer pushes its
own items ten screens down. Verified with an 11-photo room: all 11 render in the DOM, clipped to
`max-height:104px` by default, `max-height:none` after "Show all".

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
