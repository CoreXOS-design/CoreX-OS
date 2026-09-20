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

## 15. Three-party signing — Johan's 2026-09-20 ruling (DECIDED — building now, staged)

**Status: decided, building.** §15 originally recorded an open question (was in-inspection signing
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
- Every new setting from §15.6 appears in the Setup Wizard in the same stage it's built, not later.
