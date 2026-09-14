# Leases — the spine of rentals

**Status:** Spec — not yet built. NO CODE has been written against this spec.
**Date:** 2026-09-17
**Author:** cc5
**Pillar:** Property (`Property`) is where a lease attaches; Contact (owner, tenant(s)) is who it
binds; Deal has no connection today.
**Sequencing:** Johan's ruling — this spec replaces the inspections spec as the immediate build
target. `.ai/specs/rental-inspections.md` was written first and is NOT wasted — it is amended (§9) to
attach to leases instead of properties directly, which is the entire reason leases are being done
first. Work Orders (not yet written) attaches the same way once its own spec exists.

---

## 0. Why leases, in Johan's own words

> "the spec should be leases - essentially a way to take a property, we have the owner, provide the
> tenant, and lease terms - rental amount, start date, expiry date, etc. Then we have a lease screen.
> this would guide us then to have all set up for in inspections to link to, work orders to link to,
> out inspections to link to?"

The concrete failure this prevents: a tenant is not linked to a property, a tenant is linked to a
**lease**, and the lease is linked to the property. A property has many tenancies over its life. If an
inspection, a work order, or a photo hangs off the property directly, two years later nothing can tell
you which tenant it belonged to — and that is precisely the question a deposit dispute turns on.

---

## 1. Investigation — what exists today (confirmed against live QA1 data, not assumed)

Johan's own account, quoted in the task: *"leases are a REPLACE, NOT EXTEND: three unlinked schemas,
partly not multi-agency-aware, frozen since February 2026, with 58 legacy rows that migrate across,
nothing deleted."* Confirmed/corrected below — the 58-row figure is exactly right; the "three schemas"
count is off by one.

### 1.1 Four existing schemas, not three

| Schema | Table | Model | Property link | Agency-aware | Live rows (QA1) | Last touched |
|---|---|---|---|---|---|---|
| A | `rentals` | `Rental.php` | **None** — free-text `lease_address` only | No — `BelongsToBranch` only, no `agency_id` | **59 total, 58 not soft-deleted** | 2026-07-14 (scoping patch, not a feature) |
| B | `properties` (columns) | `Property.php` | N/A — same row | Yes, fully | 6 have `lease_start_date`, 4 have `lease_end_date`, 950 have `rental_amount` (of 9,143 properties) | 2026-09-10 (Rental tab UI move) |
| C | `lease_records` | `Docuperfect\LeaseRecord.php` | Nullable `property_id`, **no FK constraint** | **No** — documented gap, patched only at query layer (`LeaseRecord.php:89-91`) | 2 | 2026-07-21 (security patch) |
| D | `rental_properties` | `Rental\RentalProperty.php` | **None** — free-text address | Added Aug 2026, absent before | 2 | — |

**"58 legacy rows" is confirmed, exactly, and is schema A (`rentals`) only.** No other schema comes
close, individually or combined. **"Three unlinked schemas" is off by one — there are four.** Johan
most likely wasn't aware of schema D, a low-traffic DocuPerfect landlord-prefill helper — it's real,
live code (used by `RentalDivisionController` and document prefill), but it carries **no tenant and no
lease dates at all**. It is not actually lease-shaped in substance, and I'd exclude it from "58 rows
migrate across" framing — flagged explicitly in §6 rather than silently folded in.

**"Frozen since February 2026" is approximately right for feature work, not literally true.** Both
`rentals` and `lease_records` received commits as recently as July 2026 — but both were
scoping/security patches (closing a cross-tenant leak), not lease functionality. Schema B
(`properties`' own columns) is the exception: actively touched as recently as 2026-09-10.

### 1.2 A signed lease document — structured data exists, but the auto-population is broken today

`SignatureService::createLeaseRecord()` (`app/Services/Docuperfect/SignatureService.php:5359-5401`)
is real: on a signed document detected as a lease (keyword match on name/type —
`isLeaseDocument()`, line 5332), it reads the document's typed `fields_json` (structured merge-field
values, not just rendered text — confirmed, `docuperfect_documents.fields_json`) and writes a typed
`LeaseRecord` row.

**I checked this myself and it's broken for at least the flagship lease template.**
`extractLeaseFields()` (line 5406-5417) looks for the key `lease_start_date` (or `commencement_date`/
`start_date`); the actual field on `lease-agreement-popi-v8.blade.php:485` is named `lease_start`.
None of the three candidates the extractor checks match. This is very likely why only 2 `lease_records`
exist against 58 manually-captured `rentals` rows — the mechanism is real in shape but doesn't reliably
fire.

**Conclusion for this spec:** a signed lease document CAN populate structured lease data automatically
— the pattern (typed field storage → keyword-triggered extraction → typed record) is sound and worth
keeping — but it needs to be rebuilt correctly against the new `Lease` model (matching real field names
per template, not a fixed guess-list), not repointed as-is. Until that's rebuilt, an agent retypes the
terms; this spec does not assume auto-population works on day one.

### 1.3 What rental-application approval creates today, and what changes

`RentalApplicationController::linkTenantProperty()` (line 1102-1199) is agent-triggered (never
automatic on `approve()`), resolves a scoped `Property` and the applicant's `Contact`, and writes a
`contact_property` pivot row (role `tenant`) via `ContactPropertyLinker::link()`.

**I confirmed the uniqueness constraint myself**: `(contact_id, property_id)` is the unique key on
`contact_property` — role is NOT part of it, meaning a contact holds exactly one role per property,
ever (`ContactPropertyLinker.php:96-98`, comment confirms this is Johan's own rule). This means
multiple *different* contacts CAN each hold `tenant` against the same property today (joint tenants
are not blocked by this constraint) — but there is no date-range, no lease-period scoping, and no way
to tell which tenancy a given `tenant` link belonged to once a property has had several. This is
exactly the gap Johan is describing.

**Is swapping this to "create a Lease" easy?** Reasonably, with two real gaps, not a rewrite:
1. The method already has the right trigger point and already resolves the right Property + Contact —
   extending it to also create a `Lease` row is a contained change at one call site.
2. **Gap 1** — no rent amount or lease dates flow through this action today. `approved_rental_amount`
   is populated on only 66 of 423 applications; there is no lease start/end date captured anywhere in
   the application flow (`rental_term_months` describes the applicant's *requested* duration, not a
   lease's actual dates). New UI/data-capture is needed here, not just a rename.
3. **Gap 2** — the property-status-flip to `let_out` on tenant-link was already built once and
   deliberately pulled (documented in `.ai/specs/rental-applications.md:12579-12614`) after an
   investigation found the off-market/portal-delist path doesn't safely cover a rented lifecycle.
   Building `Lease` does not resolve this pulled decision on its own — whatever eventually consumes
   "a Lease is now active" (a status flip, a portal re-tag) still needs that separate decision made,
   or it risks reintroducing the same live-portal-withdrawal incident that got it pulled the first
   time. **Not decided in this spec — flagged for whoever builds this integration.**

### 1.4 The Rental tab today — Johan's description is aspirational, not current state

Read directly (`resources/views/corex/properties/show.blade.php:4049-4299`, governing spec
`.ai/specs/rentals-shared-screens.md` §11, status COMPLETE 2026-09-10). The tab renders exactly 17
fields — monthly rental, deposit, rental price type, lease start/end date, lease period/type, price
per day/week/year, has-deposit, commission %, admin fee, marketing fee, furnished status, availability
date, water/electricity/levies-included. **No tenant, no landlord, no party information, no link to
any lease/tenancy record anywhere on this tab.** The governing spec's own gap table confirms it
plainly: *"Tenant link (who currently leases it)... Not built — never in scope for any of Parts 1-5."*
Johan's description ("the lease / parties / and whatever else") is the tab's *intended future state*
once Leases exist — this spec is what fills that gap, not a correction of a wrong prior claim.

---

## 2. The core (kept small, deliberately)

A lease takes a **Property** (owner already known via the existing owner/landlord contact link —
`Property::sellerOwnerContact()`, `contact_property` pivot roles `owner`/`landlord`/`lessor`, already
built and out of scope to respec here), adds **one or more Tenants**, and carries **terms**. That's it.
Everything else in this spec exists to support that core correctly, not to expand it.

```
leases
  id
  agency_id              -- BelongsToAgency
  branch_id
  property_id             -- FK properties, REQUIRED (this is the entire point of the spine)
  status                  -- enum: 'draft' | 'active' | 'expired' | 'cancelled'
  rental_amount            -- decimal, THIS lease's agreed rent — distinct from
                           --   properties.rental_amount, which is the property's advertised/
                           --   asking rent. Naming them the same thing in two places is exactly
                           --   the kind of confusion this spec exists to remove — see §6.
  deposit_amount            -- nullable decimal (§4)
  start_date               -- required
  end_date                  -- nullable (month-to-month has no fixed end — see is_month_to_month)
  is_month_to_month         -- bool
  lease_type                -- reuses the existing enum shape from properties.lease_type
                            --   (Net/Gross/Modified Gross/Percentage) for consistency, not
                            --   reinvented
  source                    -- enum: 'manual' | 'rental_application' | 'esign_document' — audit
                            --   trail of how this lease came to exist
  rental_application_id      -- nullable FK, set when source='rental_application'
  source_document_id         -- nullable FK to docuperfect_documents, set when
                             --   source='esign_document' (§1.2 — reserved for when the
                             --   extraction is rebuilt correctly; not required to be populated
                             --   on day one)
  previous_lease_id          -- nullable, self-referencing FK — see §3 (renewal chain)
  renewed_lease_id            -- nullable, self-referencing FK — the inverse pointer, set once
                              --   THIS lease has been renewed into a new one
  created_by_user_id
  cancelled_at, cancelled_by_user_id, cancel_reason   -- nullable, set only for status='cancelled'
  created_at, updated_at, deleted_at   -- soft-delete, gated exactly per §3.3 of
                                       --   rental-inspections.md's own reasoning (same author,
                                       --   same principle): deletable only while NOTHING has
                                       --   attached to it yet (no inspection, no escalation, no
                                       --   work order). Once evidence exists, only 'cancelled' —
                                       --   never destroyed.

lease_tenants                -- joint tenants, N-party, per Johan's e-sign doctrine (§3.2)
  id
  lease_id
  contact_id
  is_primary                -- bool — which tenant is the primary correspondence contact;
                             --   does not imply the others have lesser standing on the lease
  created_at
  UNIQUE(lease_id, contact_id)

lease_escalations             -- append-only rate history, §5
  id
  lease_id
  effective_date
  previous_rental_amount        -- snapshotted, not re-derived later
  new_rental_amount
  escalation_rate_percent        -- computed AND stored at entry time (see §5 reasoning)
  note
  created_by_user_id
  created_at                     -- immutable, no updates, no deletes — same evidence-integrity
                                 --   reasoning as rental_inspection_observations

lease_settings                  -- one row per agency, §8
  id, agency_id (unique)
  expiry_notice_window_days       -- nullable, PENDING legal confirmation — see §8. Do not
                                  --   default this to a specific number of weeks; leave null
                                  --   (meaning "no automatic notice yet") until Johan's
                                  --   compliance officer confirms the real figure.
  created_at, updated_at
```

---

## 3. What must be settled (per the task's own checklist)

### 3.1 End of lease — new row per term, linked, argued

**Decision: every lease TERM, including a renewal of the same tenant, is its own `leases` row, linked
via `previous_lease_id`/`renewed_lease_id`.** The same record is never extended in place.

This isn't invented from scratch — it mirrors the renewal chain **already present and working** in
the existing `lease_records` schema (`previous_lease_id`/`renewed_lease_id`, confirmed at §1.1 schema
C). Reasons to keep it, argued:

1. **It's the only design that actually answers "which tenant did this belong to" precisely, even
   within one tenant's multiple renewals.** If the same tenant renews three times over six years and
   the record is extended in place, every inspection/escalation/work-order across all six years sits
   on one row — you'd know it was "this tenant," but you couldn't cleanly separate "the damp-wall
   report from year 2's term" from "the one from year 5's term" without re-deriving term boundaries
   from a separate history table anyway. A new row per term makes that boundary the primary key
   itself, not something reconstructed.
2. **Status stays meaningful.** `draft → active → expired` (natural end, no renewal) or
   `draft → active → cancelled` (early termination) are both clean, unambiguous transitions per row.
   An ever-extending record makes "expired" almost never fire and "draft" only ever apply once at the
   very beginning of a relationship that might run a decade — the status field stops describing
   anything useful.
3. **It matches Johan's own one-click-renewal idea (§7) exactly**, without building it: "renewal asks
   the agent only for the escalation and a few fields" is naturally *"create a new lease row, seeded
   from the old one's tenants and property, plus the new amount"* — which is what this shape already
   is, not a new operation bolted on.
4. **Carry-forward evidence still works across renewals — this needed its own argument, see §9.2.**
   The concern "does splitting leases into separate rows break the 'damp wall reported 2 years ago'
   carry-forward Johan wants on out-inspections?" is real and is answered directly in the amendment to
   `rental-inspections.md` (§9): items (physical spaces) stay property-scoped, so a query across every
   lease a property has ever had is a simple join, regardless of how many lease rows exist.

### 3.2 One tenant or many — many, always

`lease_tenants` is a proper table from day one, never a single `tenant_contact_id` column on `leases`
— per the task's explicit instruction that Johan's e-sign doctrine is N-party and never assumes 1-2
parties. `is_primary` exists only for "who do we address correspondence to primarily," not as a
statement that joint tenants have unequal standing on the lease itself.

**Out of scope, flagged not built**: a tenant leaving mid-lease while others remain (partial
tenant substitution within one lease term). Johan didn't ask for this; building it would mean
per-tenant start/end dates within `lease_tenants` rather than the whole lease sharing one term. Noted
so it isn't silently assumed either way.

### 3.3 The deposit — a field today, flagged as a question the moment it grows

`deposit_amount` is a plain decimal on `leases`, matching the existing `properties.deposit_amount`/
`has_deposit` precedent. **This spec does NOT build**: whether the deposit is actually held (paid,
in an agency trust account), reconciliation against out-inspection damage evidence at move-out, or
partial-refund tracking. That is a real accounting/trust-ledger feature, materially bigger than a
field, and is flagged here rather than silently scoped in or out — Johan's own words in the task
("flag it as a question if it grows beyond a field") are the exact trigger for this flag.

### 3.4 Escalation as a rate, not only the new amount

`lease_escalations` stores `previous_rental_amount`, `new_rental_amount`, AND
`escalation_rate_percent` — the rate is computed at entry time and stored, not left to be re-derived
from amount history later. **[cc5 design call]**: storing a computed value alongside its inputs is a
mild denormalization, justified here because the rate is explicitly what Johan says gets asked about
at renewal time and reported on across a portfolio — re-deriving it from amount pairs on every report
query is unnecessary friction for a number that's cheap to snapshot once, and a stored rate survives
correctly even if source amounts are later corrected (the corrected escalation gets its own new row,
the original stays a true historical fact — same evidence-integrity principle as observations).

### 3.5 Overlap — a property must never have two active leases at once

**MySQL has no partial/filtered unique index** (unlike Postgres), so this cannot be a bare DB
constraint the way "one row per (lease_id, contact_id)" can. Enforcement is at the service layer, via
the same `lockForUpdate()`-guarded-transaction pattern already proven in this codebase for an
analogous "must not race" problem
(`MobilePropertyController::uploadImages()`'s gallery-append lock, cited in
`rental-inspections.md:7.2`): a `LeaseActivationService::activate(Lease $lease)` locks the property
row, checks for any OTHER lease on that property with `status = 'active'`, and refuses (or, for the
renewal case, atomically expires the old one and activates the new one in the same transaction) rather
than allowing two simultaneously-active leases to exist even momentarily. Multiple `draft` leases on
the same property ARE allowed to coexist (e.g. an agent preparing a renewal's paperwork while the
current lease is still active) — the constraint is specifically "at most one `active` lease per
property at any time," not "at most one lease record."

### 3.6 Status meanings, and what each does to the screens hanging off it

- **draft** — being prepared, not yet in force. Visible on the Leases list screen and the property's
  Rental tab shows "no active lease" (or shows the draft clearly marked as not yet active, TBD at
  build time). Does not block anything, does not gate inspections.
- **active** — the current, in-force lease. Exactly one per property (§3.5). The Rental tab reflects
  its tenant(s), dates, and rent. Inspections and (later) work orders may attach.
- **expired** — term ended naturally. Either superseded by a renewal (`renewed_lease_id` set) or ended
  with no immediate new lease. Remains fully visible for history; every attached inspection,
  escalation, and (later) work order stays exactly as it was — nothing about becoming `expired`
  removes or hides anything.
- **cancelled** — ended early, or a `draft` abandoned before ever going active. A cancelled `draft`
  with nothing attached to it yet is ordinary CRUD noise and can be soft-deleted like any other record;
  a cancelled lease that already has evidence attached follows the same no-delete-once-evidence-exists
  rule as `rental-inspections.md` §3.3 — it stays, cancelled, permanently visible.

---

## 4. Attachment points — what this spec exists to enable

Named here, not built:

- **In-inspection / out-inspection** (`.ai/specs/rental-inspections.md`) — a `rental_inspections` row
  gets a required `lease_id`. See §9 for the exact amendment this requires against the already-written
  inspections spec, and the reasoning for why items stay property-scoped while inspections become
  lease-scoped.
- **Work orders** (future spec, not yet written) — a future `rental_work_orders` row should carry
  `lease_id` (which tenancy this repair happened under) in addition to the `rental_inspection_item_id`
  link already reasoned about in the inspections spec's §3.4 (which physical space/item). Both matter:
  the item tells you WHAT broke and its full cross-tenancy history; the lease tells you WHO was living
  there and under what agreement when it broke. Naming this now so the work-orders spec has both FKs
  to reach for.

---

## 5. Deferred, flagged, not built

### 5.1 One-click renewal — Johan has NOT asked for this to be built; this design must not foreclose it

Johan's idea: a signed lease document updates the Rental tab with its dates, and renewal then asks the
agent only for the escalation and a few fields. This spec's shape — `lease_tenants` as a reusable
tenant set, `previous_lease_id`/`renewed_lease_id` as a first-class chain, `lease_escalations` as a
structured rate history — means a future `LeaseRenewalService::renew()` really could be "create a new
`leases` row seeded from the old one's property + tenants, take a new amount, compute the rate,
activate atomically." Naming this so it's visibly not foreclosed. **Not built here.**

### 5.2 CPA expiry-notice obligation — do not research, do not encode a number

Johan is having his compliance officer confirm whether a written notice is legally required before a
fixed-term lease ends, and what a tenant's cancellation rights look like regardless of lease terms.
This spec reserves `lease_settings.expiry_notice_window_days` as a **nullable, agency-configurable**
placeholder — explicitly not defaulted to any specific number of weeks, and not wired to any actual
notification logic yet, so that whatever the attorney confirms slots into an existing column rather
than requiring a schema change. No SA law was researched to write this spec, per instruction.

---

## 6. Migration — "replace, not extend," nothing deleted

Every one of the 58 `rentals` rows, and every `lease_records`/`rental_properties` row that genuinely
represents a tenancy, migrates into `leases` — none are deleted, matching non-negotiable #1 and
Johan's own framing.

**The real difficulty, named plainly rather than hand-waved:** `rentals.lease_address` and
`lease_records.property_address`/`rental_properties`' address lines are **free text with no FK to
`properties`**. Migrating 58+ rows into a schema where `property_id` is required means resolving free
text to a real property first. CoreX already has a formal mechanism built for exactly this class of
problem — `TrackedPropertyMatchOrCreateService` (non-negotiable #10, the Universal Match-or-Create
Rule) and `Property::scopeSearchAddress()`'s token-matching — and this migration should reuse that
matching discipline rather than inventing new fuzzy-matching logic. Where a confident match can't be
made, the row should NOT be silently guessed into the wrong property — it should be queued for a human
agent to confirm before becoming a real `leases` row. This spec does not assume every legacy row
resolves automatically; some manual confirmation pass should be expected and budgeted for at build
time.

**One exclusion, flagged not silently folded in**: `rental_properties` (schema D) has no tenant and no
lease dates at all — it is a landlord-contact prefill helper, not a tenancy record in substance. I'd
recommend it stay as-is (untouched, still serving DocuPerfect prefill) rather than being forced into
the `leases` migration — but this is a scoping call for whoever builds this, not decided unilaterally
here.

**`properties.rental_amount` is the property's advertised/asking rent — do not conflate it with
`leases.rental_amount`, the actual agreed rent for a specific tenancy.** They will usually be close but
are not the same fact, and giving them the same column name in two tables (as happens informally today)
is exactly the ambiguity this spine is meant to remove. The 6 properties with `lease_start_date`/4
with `lease_end_date` populated are one-off manual entries reflecting some historical tenancy — worth
reviewing as migration candidates individually, not a systematic source.

**Existing consumers that must be repointed when this is built** (named so nothing is silently
forgotten): `app/Http/Controllers/Docuperfect/LeaseController.php`,
`app/Console/Commands/CheckLeaseExpiry.php`, `app/Notifications/LeaseExpirationAlert.php`,
`app/Mail/Signatures/LeaseExpirationMail.php`, `app/Services/CommandCenter/Calendar/Sources/
RentalCalendarSource.php` — all currently read `lease_records`.

---

## 7. The Leases list screen — CRUD/list-screen floor (BUILD_STANDARD §1a-§1d)

New screen, new nav entry, same day as the build. Route group `corex.leases.*`, sidebar entry under
Rentals, alongside the (future) Rental Inspections list.

- **Search** (named fields): property address, tenant name(s).
- **Sort**: end date, start date, status, property address. **Default: end date ascending (soonest to
  expire first)** — stated explicitly per §1b, chosen because tracking what's coming up for renewal is
  the most common reason to look at this list day to day.
- **Filter**: status (draft/active/expired/cancelled — minimum per §1b), date range (start or end
  date), property, branch.
- **Pagination**: standard page size.
- **Empty state**: distinct copy for "no leases yet on this agency" vs "none matching this filter."
- **Scoping**: `leases`/`lease_tenants`/`lease_escalations` all `use BelongsToAgency` + `AgencyScope`;
  OWN/BRANCH layered on top via the same role pattern already used by rental applications and (planned)
  rental inspections. Direct-URL access to another agency's lease is a 404 via the global scope, not a
  hidden link.

The property's **Rental tab** additionally surfaces the property's currently-active lease (tenant(s),
dates, rent) read-only, sourced from `leases` — this is what fills the gap the tab's own governing spec
already names ("Tenant link... Not built") and is what makes Johan's original description of the tab
("the lease / parties / and whatever else") actually true once this ships.

---

## 8. Permissions

New keys in `config/corex-permissions.php`, following the `rental_applications.*`/
`rental_inspections.*` naming convention:
- `leases.view`
- `leases.create` (covers drafting, activating, linking tenants)
- `leases.renew` — separate from `.create` **[cc5 design call, flagged for Johan]**, since renewal
  changes the property's active lease and touches the overlap-prevention guard (§3.5); if this
  distinction is unwanted, collapsing it into `.create` is a one-line change at build time.
- `leases.cancel`

---

## 9. Required amendment to `.ai/specs/rental-inspections.md` — not applied yet, named here

This spec does not silently rewrite the already-committed inspections spec. The following change is
what's needed once Johan has reviewed this document, to be applied as its own small follow-up:

### 9.1 The change

`rental_inspections` (§3.2 of the inspections spec) gains a required `lease_id` FK. `property_id` is
kept as a denormalized convenience column (always set to `lease.property_id` at creation, never edited
independently) so simple "every inspection on this property" queries don't require a join — but
`lease_id` becomes the authoritative answer to "which tenancy did this inspection belong to."
`rental_inspection_items` (the physical spaces/meters) are **unchanged — still property-scoped, not
lease-scoped** — a "Bedroom 1" is a physical fact about the property that outlives any one tenancy.

### 9.2 Why this doesn't break the carry-forward requirement — argued, because it looked like a
contradiction at first

Johan's inspections ruling wants an out-inspection to carry forward a fault reported "2 years ago" so
an owner can't blame a tenant for damage the owner never fixed. Read literally, this could mean
carry-forward evidence needs to span across DIFFERENT tenants' leases, not just one lease's own
history — which could look like it contradicts wanting inspections scoped to a specific lease at all.

**It doesn't, once items and inspections are split the way §9.1 describes.** Because
`rental_inspection_items` stay property-scoped while `rental_inspections` become lease-scoped, the
carry-forward query ("show every observation ever made on this physical item, regardless of which
lease it happened under") is a simple join — item → observations → inspections → leases — and it
naturally spans every tenant the property has ever had. At the same time, "which tenant did THIS
specific inspection event belong to" stays unambiguous, because the inspection event itself carries
`lease_id` directly. Both of Johan's requirements — cross-tenant evidence, and per-tenant attribution
— are satisfied simultaneously, not in tension, once the item/inspection split is in place. This is
the reasoning that justifies doing leases first at all, made explicit.

---

## 10. Files to create (none yet written — spec only)

- `database/migrations/xxxx_create_leases_table.php`
- `database/migrations/xxxx_create_lease_tenants_table.php`
- `database/migrations/xxxx_create_lease_escalations_table.php`
- `database/migrations/xxxx_create_lease_settings_table.php`
- `database/migrations/xxxx_migrate_rentals_and_lease_records_into_leases.php` (data migration, §6 —
  expect a manual-review queue for unresolved address matches, not a silent one-shot script)
- `app/Models/Lease.php`, `LeaseTenant.php`, `LeaseEscalation.php`, `LeaseSetting.php` — all
  `use BelongsToAgency`.
- `app/Services/Rentals/LeaseActivationService.php` — the overlap-prevention guard (§3.5).
- `app/Http/Controllers/CoreX/LeaseController.php` — CRUD, activate, cancel, escalate.
- `resources/views/corex/leases/index.blade.php` — the new list screen (§7).
- `resources/views/corex/properties/partials/rental-tab-active-lease.blade.php` — the read-only active-
  lease summary added to the existing Rental tab.
- `config/corex-permissions.php` — new permission keys (§8).
- Sidebar entry for the new list screen (same-day, non-negotiable #2).
- `tests/Feature/Leases/*` — overlap prevention, renewal chain integrity, joint-tenant CRUD, agency
  scoping, migration-script correctness against a fixture mirroring the real `rentals` shape, at
  minimum.
- Re-run `php artisan schema:dump`, commit refreshed `database/schema/mysql-schema.sql`
  (non-negotiable #12a).
- **Separately, as its own follow-up prompt once this spec is approved**: the §9 amendment to
  `.ai/specs/rental-inspections.md`.

---

## 11. Out of scope (this spec)

- Deposit-held tracking / trust-account reconciliation (§3.3) — flagged as a question, not built.
- One-click renewal's actual UI/trigger (§5.1) — the design doesn't foreclose it; it isn't built here.
- The CPA expiry-notice obligation's actual number and notification logic (§5.2) — pending legal
  confirmation; only the agency-setting placeholder is reserved.
- Rebuilding the e-sign auto-population (§1.2) correctly against the new model — named as necessary
  future work, not built in this spec.
- The property-status-flip-on-tenant-link decision (§1.3, gap 2) — a separate, previously-pulled
  decision that resurfaces once Lease exists; not re-decided here.
- Partial tenant substitution mid-lease (§3.2) — not requested, not built.
- `rental_properties` (schema D)'s disposition (§6) — flagged as a scoping question, not decided.
