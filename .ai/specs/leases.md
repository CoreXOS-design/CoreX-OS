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

### 1.3a Gap 1 closed, 2026-09-15 (conductor ruling, "C") — Gap 2 deliberately untouched

> **SUPERSEDED 2026-10-07 (cc2, Johan's QA1 test):** linking a property to an approved application no longer creates a lease. It used to create lease 93 already ACTIVE, rent copied from the approved amount, with no agreement. See §15.25 — the approved application's "Continue to the lease" opens the §15.3 capture screen; the lease, the application's property link and the tenant link are all written only when that screen is completed.

> **Superseded 2026-10-07 (cleanup, cc2):** the "do NOT touch property status" carve-out below was closed by Johan's Gate 2 approval of AT-444 item 7 — see §12.5 point 1: activating a lease (including the one created here) lets the property out. `LeaseFromApprovalTest` asserts `let_out`, not "untouched".

Johan's ruling: build the lease creation on approval, capturing rent, deposit and dates in that flow,
with one explicit carve-out — do NOT touch property status. That is Gap 2 above, and it stays
Johan's call to make, pending.

`linkTenantProperty()` now additionally accepts `rental_amount`, `deposit_amount`, `lease_start_date`,
`lease_end_date` in the same request, and — when they're actually provided — creates a `Lease` +
`LeaseTenant` row (source `rental_application`, `rental_application_id` set) after the existing
`contact_property` link succeeds, and activates it via `LeaseActivationService`. Idempotent: a resubmit
or a race never creates a second `Lease` for the same application
(`Lease::where('rental_application_id', ...)->exists()` guard). If activation fails (a genuine overlap
— another lease already active on that property), the Lease is still created and the rent/deposit/dates
the agent just typed are never lost, just left as `draft` with a warning surfaced on screen naming the
conflict, rather than silently discarded.

The property-status code path (§0's "Deliberately does NOT touch `$property->status`" comment) is
completely untouched by this change — the new lease-creation block sits entirely separate from it in
the method, and the existing comment explaining why status stays untouched was left exactly as it was.

### 1.3b REGRESSION, found by cc1's baseline check 2026-09-16, and the fix

The first version of §1.3a made `rental_amount` and `lease_start_date` **required** on
`linkTenantProperty()`. That is the same endpoint the tenant-link flow has always used — every
pre-existing caller (`RentalApplicationTenantPropertyLinkTest`'s five tests, and by extension any real
caller that only ever knew about `property_id`) posts only `property_id`. With the new fields required,
those requests failed validation before the `contact_property` pivot was ever written — **and the
controller still redirected**, so the caller saw "success" while nothing happened. A silent failure on
an existing, working flow, caught only because cc1 baseline-checked the pre-existing suite against the
branch rather than trusting the new tests alone.

**Caller audit, done properly this time** (exhaustive grep across `*.php`/`*.blade.php`/`*.js` for the
route name and controller method — see the fix commit for the exact command): exactly one production
caller exists — the form in `resources/views/corex/rental-applications/view-readonly.blade.php`, the
same one §1.3a's own build modified to always send lease terms. No other blade view, no JS/fetch call,
no mobile API endpoint, no other controller references this route. Two test files reference it: the
restored pre-existing suite, and this feature's own new one.

**The fix:** `rental_amount`, `deposit_amount`, `lease_start_date` are all `nullable` again — validated
for shape when present (`required_with` cross-validation so a half-formed submission, one of the two
required-together fields without the other, is rejected rather than silently creating a broken lease),
never required outright. Lease creation is gated on `$request->filled('rental_amount') &&
$request->filled('lease_start_date')` — checked via `Request::filled()`, not `$validated` key
presence, since Laravel's `validate()` does not reliably include a key for a `nullable` field that was
never submitted at all. A caller that sends only `property_id` gets exactly the pre-existing behaviour:
the tenant link, and nothing else. The one real caller (the approval screen) still always sends both
fields together via required HTML attributes, so in real use approval still creates the lease — the
whole point of §1.3a still holds; only every OTHER possible caller of this shared endpoint is protected
from this class of failure now, not just the one this feature happened to touch.

Verified by walking BOTH shapes for real against `corex_qa1_walkthrough` (never live QA1), checked at
the database level rather than trusting the redirect status alone — the exact thing that hid the bug
the first time: a with-terms POST and a without-terms POST, each confirmed via a direct query
afterward (`contact_property` row exists either way; `leases` row exists only for the with-terms case).
Automated: the restored pre-existing suite (5/5, unweakened, not a single assertion changed) plus four
tests on this feature's own suite covering both shapes explicitly, including the exact "no lease terms
→ tenant link works, no Lease created" case that was broken.

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

### 3.7 Archive guard — a property with an active lease must never be archived (Johan's ruling, 2026-10-05)

**The rule, reduced to one check**: a property may not be archived (soft-deleted, by any path) while
it has a lease in `status = 'active'`. No second status is needed to express "month-to-month",
"under notice", or "signed but not yet started" — all three are, in this schema, simply an *active*
lease (§3.6): month-to-month leases and leases under notice both stay `status='active'` until they
actually end; a lease is activated (and therefore `active`) the moment its terms are agreed, even if
`start_date` is still in the future. One status check covers every case Johan described.

**Blocking**: `status = 'active'` — whatever its `is_month_to_month`/`start_date`/`end_date` shape.
**Not blocking**: `draft`, `cancelled`, `expired` — including a `draft` renewal chained
(`previous_lease_id`) onto an already-`expired`/`cancelled` lease; the renewal's own status, not the
lease it would replace, is what's checked, so a draft renewal never blocks archiving the property it's
drafted against.

**Enforcement point**: `PropertyObserver::deleting()` — a model-event hook that fires for every
`Property::delete()`/`forceDelete()` call, throwing `App\Exceptions\PropertyHasActiveLeaseException`
when `Property::blockingActiveLease()` finds a match. This is the single choke point for every archive
path (the single archive action, change-listing-type's archive-the-original step, the upload wizard's
discard-draft, and any future caller) — no call site re-implements the check, so none can bypass it.
`bootstrap/app.php` renders the exception as a friendly redirect to the lease itself (the "link to the
lease" the ruling asks for) for web requests, or a 422 JSON body (`lease_id`/`lease_url`) for API/AJAX
callers — the same pattern already used for `OwnershipLockedException`.

**No bulk-archive route exists for properties today** (confirmed by exhaustive grep of
`PropertyController`/`routes/web.php`/`routes/api.php` — see the 2026-10-05 build report). The guard
above already covers a future bulk action for free (it sits under every `delete()` call, not inside any
one controller method), but the UX this ruling also describes — skip blocked properties, list them,
archive the rest — has nothing to attach to yet and is not built here.

---

### 3.8 Archive and Restore for leases; the property's let status follows its active lease (7 Oct 2026, cc2 — Johan's QA1 test, lease 93)

**Why.** Johan could not archive a wrongly created lease: the only Archive button sat low on the lease screen, was hidden for Active leases and for any lease with escalation history, and the route behind it (`DELETE /corex/leases/{lease}`) had no status check (§15.22 #6). Design standard (CLAUDE.md #8 / BUILD_STANDARD §1a): Archive + Restore on every record, soft delete only.

**What it does.**
- **"Archive lease…"** — in the Lease actions menu for a draft or active lease (needs `leases.cancel`, the same key as Cancel — archiving a tenancy ends it), and as its own header button on a cancelled or expired lease (needs `leases.create`). A dialog takes a **required reason**; Confirm archives. The bare `DELETE /leases/{lease}` runs the same archive (reason defaults to "Archived"), so no path hides an active lease and leaves its property let.
- **One service, `App\Services\Rentals\LeaseArchiveService`.** Archiving a **draft or active** lease **cancels it in the same step** (`status = cancelled`; who / when / why on the lease; an agreement still open in e-sign is closed, as Cancel does) and records `archived_from_status`, `archived_by_user_id`, `archive_reason` (migration `2026_10_14_000500`); `deleted_at` is the archived-at. Reason for cancelling too: every scheduler, portal-access rule and activation guard reads `status`, and several look past soft-deletes on purpose — an archived lease must not keep escalating, alerting or holding the property. A **cancelled or expired** lease is only hidden; its status is untouched. Written to the **lease history** (`lease_archived` / `lease_restored`).
- **Every status can be archived — including a lease with escalation history.** This replaces the §3.6 / §2 "no delete once evidence is attached" guard for leases: archive is a soft delete, the escalations, inspections and documents all stay attached and visible on Restore. The old `isDeletable()` check and the old low-down Archive button are gone.
- **Hidden from the default list**; the list's existing **"Show archived"** toggle shows them (status badge "Archived <date>", what it was, the reason) with a **Restore** button (confirm). Restore of a draft/active lease needs `leases.cancel` too.
- **Restore** puts the lease back to what it was (`archived_from_status`; the cancel record added by the archive is cleared — a genuine earlier cancel is kept). If it was **active**, it re-checks **under a lock on the property** that no other live lease is active — if one is, it is refused with a plain message and the lease stays archived — and that the property itself is not archived. A restored active lease puts the property back to "Let out" through the same path activation uses. Leases archived before this build (no `archived_from_status`) come back as they were.
- **An archived lease blocks nothing.** `LeaseActivationService::activate()` now ignores `deleted_at` leases (it queried `withoutGlobalScopes()`, which also drops the soft-delete scope); tenants are not restricted to one lease, so the same tenant can be on a new lease; tenant-portal reads already excluded archived leases.

**The property's let status follows its active lease — one rule, every path.** `PropertyStatusFollowsLeaseService::restorePreLetStatus()` is the single release (called by Cancel, Archive and the out-inspection "lease ended" path): it does nothing while **another live lease is still active** on the property; otherwise the property goes to the first allowed of *what it was before it was let → the agency's default pre-let status → `active`*. The last step is the fix for a real defect: when the remembered status and the agency default were both outside the agency's status list, the old code cleared its memory and returned, leaving the property on "Let out" for ever. The agency toggles `auto_restore_status_on_lease_cancelled` / `_ended` still switch the automatic write off (an agency that opted out keeps full control); Archive of an active lease uses the cancelled toggle. Notice outcomes (re-advertise / withdraw) are untouched.

**Not built (scope).** No "End lease" action exists in the app — a lease ends by renewal, by out-inspection, or by Cancel; this build adds none. No new permission key: Archive/Restore reuse `leases.create` / `leases.cancel`. No new setting, so nothing for the Setup Wizard (rule #10a). Domain events for lease changes remain §15.22 #13.

**Tests.** `tests/Feature/Leases/LeaseArchiveRestoreTest.php`.

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

### 5.2 CPA expiry-notice obligation — built, 2026-09-15 (conductor ruling, "A")

**Superseded from the original "do not default this" stance below.** Johan's ruling: this reverses
that stance — his standing rule is absolute, any threshold is agency-configurable with a sensible
default from day one, never hardcoded, and the control ships with the feature rather than waiting on
anyone to ask for it. `LeaseSetting::expiryNoticeWindowDaysFor()` now returns
`DEFAULT_EXPIRY_NOTICE_WINDOW_DAYS = 60` instead of `null` when nothing is saved. Built: a settings
screen (`/corex/settings/leases`), a Setup Wizard step (`'leases'`, right after `'proforma'`), a new
`leases.manage_settings` permission, an entry on the central Settings hub. Johan's compliance officer's
eventual CPA figure only changes what an agency's default *starts at* — not whether the control
exists; nothing here is blocked on that number arriving.

**Original reasoning, kept for the record — still true of the underlying legal question itself, just
no longer true of whether the setting is built:** Johan is having his compliance officer confirm
whether a written notice is legally required before a fixed-term lease ends, and what a tenant's
cancellation rights look like regardless of lease terms. No SA law was researched to write this spec
or to build this control — the number 60 is a sensible operational default, not a legal claim, and the
control is not wired to any actual notification-sending logic yet regardless of what value is saved.

---

### 5.3 Automatic month-to-month — built, 7 Oct 2026 (Johan's ruling)

**The ruling.** When a lease reaches its end date and **no notice to vacate has been recorded** and **no renewal has been recorded**, the lease changes to month-to-month **automatically**. That is the correct behaviour. (It supersedes the 4 Oct 2026 "expiry is never automatic" for this one transition only: the lease stays `active`, nothing is expired, and `CheckLeaseExpiry` itself still never writes lease state.)

**What exists, checked first.** `CheckLeaseExpiry` (`signatures:check-lease-expiry`, daily 06:00) only alerts. Month-to-month existed only as the agent's own action (`LeaseRenewalService::recordMonthToMonth`, logged, reversible). Nothing switched a lease on its own.

**What is built.**
- **Command `leases:auto-month-to-month`** (`AutoMonthToMonthLeases`), scheduled **daily 05:30** — before the 06:00 expiry check, so a lease that has just switched is not also flagged overdue. `--dry-run` lists what would switch and changes nothing; `--lease=ID` restricts a real run to one lease. Agency by agency, never one bulk query.
- **`LeaseAutoMonthToMonthService`** — *due* means: `status = active`, not archived, not already month-to-month, has an end date, `end_date <= today − N days` where **N is the agency's setting**, **`notice_date` is null** (a notice from the tenant or the landlord, any outcome), **`renewed_lease_id` is null**, and **no renewal term is in flight** (no draft chained to it by `previous_lease_id` — which includes a renewal whose agreement is out for e-signing, one signed but not yet active, and one just started; a renewal the agent cancelled is not a renewal). The same conditions are re-checked under a row lock at the moment of the switch, so a notice or renewal recorded a moment earlier always wins.
- **The switch is exactly the agent's own "Goes month-to-month"**: `is_month_to_month = true`, `end_date` cleared. The lease stays `active`; the property keeps its status (§12.5.3); nothing else moves.
- **Logged** on the tenancy history as a `month_to_month_set` line with no actor ("Lease went month-to-month automatically — it ended on 31 Oct 2027 with no notice to vacate and no renewal on record"), the old end date and the grace in its metadata. **The agent is told** in-app (`LeaseMonthToMonthNotice`, database; to the agent who created the lease, else the property's agent), with how to undo it.
- **Idempotent**: a month-to-month lease is never a candidate again; running it twice changes nothing and tells nobody twice. **Not fought**: if the agent reverses it, the lease has no end date and is never a candidate again.
- **Agency setting** `lease_settings.month_to_month_after_end_days` (nullable = default; **default 1 = the day after the end date**; 0–365; 0 switches on the end date itself). On Settings → Leases ("Automatic month-to-month") **and** in the Setup Wizard leases step (rule #10a) with its explanation; the saver is the existing `LeaseSettingsController::update`, `has()`-guarded (§6.1) so a wizard post that does not carry it never wipes it.
- **"Record outcome" (§12.2 next-step card)** now opens the Lease actions menu itself, showing every outcome (renewed / month-to-month / ended — the notices) instead of jumping to the month-to-month box: `?action=outcomes` on the Lease Hub (a URL action, valid only on an active lease; it opens the menu, never a dialog).

**Not done / by design.** There is no on/off switch (only the timing): Johan ruled the behaviour correct; a switch can be added if an agency ever needs one. Reversing an automatic switch does not restore the old end date (the agent then records the real outcome) — restoring it would have the next run switch it again. **The scheduler is not running on QA1 or Staging**, so there the command is run by hand (see the build report).

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

## 6a. Editing a lease after creation — built, 2026-09-15 (conductor ruling, "B")

Full CRUD is the floor: deposit, end date, and lease type are editable after creation via an inline
edit toggle on the lease show screen, posting to the existing `update()` route. Deliberately NOT
editable there: `rental_amount` (only ever changes through a recorded escalation, §3.4, so the rate
history stays a true, unbroken record) and `start_date` (correcting it is a delete-and-recreate, only
possible while nothing has attached — §2's `isDeletable()` — not a silent edit of a term the tenant
agreed to).

### 6a.i Edit-screen layout, fixed 2026-09-22 (Johan: "why are the fields not lined up?")

The edit form's fields now use the SAME shared `.prop-input`/`.prop-select`/`.prop-label` classes
(`resources/css/corex.css`) already used on the property screen, onboarding, and rental inventories —
one shared box model guarantees identical label position and field height down the column, rather than
approximating it with one-off Tailwind + inline-style combos per field (the previous cause of the
misalignment: Monthly rental's read-only display and Deposit's editable input used near-identical but
not byte-identical class/style combinations). End date now sits beside the Month-to-month checkbox
instead of alone — it was being stranded by CSS grid's default (non-`dense`) auto-placement whenever a
later `col-span-2` item (Lease type or the no-approval threshold) couldn't fit beside it and forced a
new row instead of filling the gap.

**The grid container itself stays a CONSTANT `grid-cols-2`** (unchanged from before this pass) —
responsiveness lives on the INDIVIDUAL fields this commit owns instead (`col-span-2 sm:col-span-1`:
full width below 640px, half width paired above it), never on the container's own explicit column
count. This is not a style preference — it is a real bug found and fixed only by a genuine headless-
browser render at 390px (a static HTML/CSS read missed it entirely; see the Verify note below): the
no-approval threshold field's own `col-span-2` class is deliberately untouched per the cc5 coordination
below, and if the CONTAINER had instead dropped to `grid-cols-1` at mobile width (the first shape this
was built in, then reverted), that field's span-2 request would exceed the grid's one explicit column,
forcing CSS Grid to fabricate an unwanted IMPLICIT second column sized to that field's own content —
which then corrupted every OTHER row's column widths too, producing exactly the overlapping,
near-zero-width "Monthly rental" box Johan's original complaint was about, just relocated to mobile
width instead of fixed. Keeping the container's explicit column count constant at 2 makes the
untouched field's span-2 always resolve against real, existing tracks, at every viewport.

**Buttons — Save changes / Cancel / Activate / Cancel lease / Archive — now live in ONE unified flex
row**, replacing two previously-separate blocks (the edit form's own Save/Cancel div, and a second,
always-rendered "status actions" div below it that held Activate/Cancel lease/Archive). Save changes
now submits the form via the HTML5 `form="lease-edit-form"` attribute (the same cross-form-binding
pattern already used on the property screen — see `.ai/specs/rental-property-tab.md`) rather than
living inside the `<form>` element, so it can sit in the merged row without the fields themselves
needing to move. **Permission mapping is unchanged and independently preserved**: Edit/Save
changes/Cancel/Activate/Archive still require `leases.create`; Cancel lease still requires only
`leases.cancel` — the destructive group (Cancel lease, Archive) is deliberately NOT nested inside the
`leases.create` `@permission` block, so a user with `leases.cancel` but not `leases.create` still sees
Cancel lease, exactly as before this layout pass. Cancel lease and Archive are visually grouped,
right-aligned (`ml-auto`), and separated from Save/Cancel/Activate by a left border divider — Johan's
"keep it visually distinct... not an equal sibling of Save" — using the same `var(--ds-red, #dc2626)`
outline convention Archive already used elsewhere in CoreX (rental applications, rental inspections,
rental fault reports).

**Screen-space rule applied**: the no-approval spend threshold field's two lines of helper text
("Leave blank to use the agency's own default...") are removed — Johan's standing rule that every line
is either data or a control, never explanatory prose duplicating what the field's own state already
shows. **This field's label/input classes and grid placement are otherwise deliberately untouched** —
cc5 (work-order quotes build, 2026-09-22) is removing the whole field from this screen in a parallel
branch, since Johan ruled the no-approval threshold belongs on the PROPERTY, not the lease. Restyling
it here would just be redone/conflicted the moment cc5 lands; only the helper text — pure prose, no
functional relationship to cc5's removal — was touched.

**Mobile (390px)**: every one of this commit's own fields (`col-span-2` below the `sm:` breakpoint)
stacks full-width in the same top-to-bottom order; the untouched no-approval threshold and (when shown)
Lease type were already unconditional full-width `col-span-2` and are unaffected either way. The button
row uses `flex flex-wrap`, so at narrow widths the trailing destructive group (`ml-auto` pushes it to
the row's end on wide screens) wraps onto its own line below Save/Cancel/Activate rather than
overflowing horizontally — still visually distinct by colour, just stacked instead of side-by-side.
Verified live with a REAL headless browser (Puppeteer against system Chromium — the same tool
`scripts/rental-smoke.mjs` already uses) driving a genuine click on the real Edit button, not a static
HTML read — see the root-cause note above for why the static-only check this was first verified with
missed the real bug entirely.

Verify: a real headless-browser session (real Alpine JS execution, a real click on Edit, `getBoundingClientRect()`
read back from the live DOM — not a static HTML/CSS read, which is what let the container-level
`grid-cols-1` version ship past a first verification pass undetected) against an isolated local database
(local, not the deployed QA1 URL) at desktop (1280px) and 390px viewport widths, both with zero console
errors: Monthly rental (read-only) and Deposit sit on the same row with equal, non-overlapping boxes at
desktop, and stack full-width with zero overlap at 390px; End date and Month-to-month share a row at
desktop; Save changes/Cancel/Cancel lease render on one row at desktop with Cancel lease visually
separated; the removed helper text is gone from the rendered HTML; the untouched field (name, type,
value, placeholder, classes) is byte-identical to before this commit. `scripts/verify-alpine-render.mjs`
run against a static fetch of the same page: zero errors. Existing test suite
(`LeaseTypeVisibilityTest`/`LeaseEditTest`/`LeaseCoreTest`/`LeaseSettingsTest`/`LeaseTypeSettingTest`)
re-run as a regression check — all still passing, no changes needed to any test since none of them
assert exact DOM position, only presence/absence of text and values.

## 7. The Leases list screen — CRUD/list-screen floor (BUILD_STANDARD §1a-§1d)

New screen, new nav entry, same day as the build. Route group `corex.leases.*`, sidebar entry under
Rentals, alongside the (future) Rental Inspections list.

- **Search** (named fields): property address, tenant name(s), **landlord name(s)** (added below).
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

### 7.1 Landlord shown under tenant in the same cell (2026-10-05)

Johan was testing the list and found the row showed only the tenant — no landlord. Added, same cell,
tenant on top, landlord underneath, same muted/compact text style:

- Sourced from **`Lease::landlordContacts()`** only — never `Property::sellerOwnerContact()`'s
  sole-contact fallback (the AT-444 bug class: a property linked only to its tenant must never show
  that tenant as the landlord). `landlordContacts()` already has this guarantee built in (§ above).
- No landlord linked → muted `"No landlord linked"` text, linking to the property's Contacts tab
  (`corex.properties.show` with `?tab=contacts`) — the existing "Link landlord" destination already
  used by the Lease Hub and the rental context bar.
- **Eager-loaded** (`LeaseController::filteredLeasesQuery()` now loads `property.contacts` alongside
  the existing `tenants.contact`) — `Lease::landlordContacts()` reuses the already-loaded collection
  instead of calling `Property::contactsForRole()` (which always issues a fresh query regardless of
  what's eager-loaded) when the relation is loaded; `Property.php` itself is untouched.
- Landlord name is searchable (same `q` field, OR'd alongside property/tenant) and included in the
  CSV/XLSX export (`Landlord` column, via the new `Lease::landlordNames()` helper — same shape as the
  existing `tenantNames()`).

### 7.2 The New Lease form's Property field is type-to-search (2026-10-06)

**Bug (agents testing on QA1):** on `/corex/leases/create` the Property field was a plain `<select>` filled
with up to 500 rental properties (`create.blade.php`) — it could not be typed into, so with many
properties it was unusable. The list was also built by a bare `Property::where('listing_type','rental')`
inside the view, i.e. not narrowed to the user's own/branch visibility.

**Now:** a type-to-search picker, the same pattern as the rental-application and work-order property
pickers (`RentalApplicationController::searchProperties()` / `RentalWorkOrderController::searchProperties()`).

- **Searches by** (all via the one canonical `Property::scopeSearchAddress()`): street number and name,
  address, suburb, city, complex name, unit / section, **property name (`title`)**, **property number
  (the reference)**, erf number, P24 reference. Several words narrow the result (every word must match
  something), e.g. `beach road`; `unit 14` / `erf 442` bind to that column.
- **Endpoint:** `GET /corex/leases/search-rental-properties` (`corex.leases.search-rental-properties`),
  permission `leases.create` — same gate as `create`/`store`. Returns up to 10 rows
  `{id, label, status, agent, ref}`. Distinct from `corex.leases.search-properties`, which backs the
  LIST screen's filter and only offers properties that already have a lease.
- **Scoping (query layer, never a hidden link):** rental listings only (`listing_type = rental`), then
  `Property::visibleTo($user)` (own / branch / agency per the user's `properties` data scope) on top of
  the global `AgencyScope`. `store()` re-checks the same `visibleTo` rule, so the picker never offers a
  property the save would refuse, and posting an out-of-scope property id directly is a 404.
- **Behaviour:** needs 2+ typed characters (same as the sibling pickers); debounced; stale responses
  are discarded; ArrowUp/ArrowDown move through the results, Enter picks the highlighted one (it does
  not submit the form), Escape closes the list. Editing the text after a pick clears the pick, so the
  box and the posted `property_id` can never disagree.
- **Empty / error states:** no match → "No rental properties match …" (never a blank box); a failed
  request → "Could not search properties just now. Please try again."; submitting with nothing picked
  → inline "Choose a property from the list." (client) / "Please choose a property from the list."
  (server, `property_id.required`).
- **After a validation error** the picked property comes back: `create()` resolves `old('property_id')`
  through the same scoped query, so a property the user can no longer see (or that was archived in the
  meantime) comes back empty and must be re-picked.
- **Unchanged:** opening the form with `?property_id=` (from a property screen / rental application)
  still shows that property as fixed text with no picker.
- **Lease edit has no property field** — `update()` accepts deposit, end date, month-to-month and lease
  type only (the property is fixed at creation, §6a), so there is no second property picker to fix.
- **Proof:** `tests/Feature/Leases/LeaseCreatePropertySearchTest.php`.

### 7.3 New Lease form — fields survive an error, and only a rental property the user may see (2026-10-06)

Three defects found on `/corex/leases/create` while building §7.2, fixed together.

1. **Nothing is lost after a validation error.** A bounce used to clear the tenants and every field except the
   property. Now the tenant list (in the order chosen — the first is the primary), monthly rental, deposit, start
   and end date, month-to-month, lease type and activate-immediately all come back from `old()`. Tenants are
   re-resolved from their ids under the same rule `store()` validates (same agency), so a stale or forged id
   comes back as nothing. A fresh form is blank.
2. **A lease can only be saved on a rental property the user may see.** `store()` validates `property_id`
   against the picker's own query, so a sale listing, another agent's / branch's / agency's property, a made-up
   id and a malformed id all fail the same way with "Please choose a property from the list." (no lease is
   created, nothing to enumerate; previously out-of-scope ids were a 404 and sale listings were accepted).
3. **`?property_id=` pre-fills only a property the picker could have offered.** Out-of-scope, other-agency,
   sale or malformed ids pre-fill nothing and show no error page — the form just opens with the picker.

**One shared query:** `Property::scopeRentalVisibleTo($user)` (rental listings → `visibleTo` own/branch/agency, on
top of the global AgencyScope) is used by the search endpoint, the `store()` validation and the pre-fill, so they
cannot drift. A test asserts the picker and the save agree on every fixture property for every user type.

**Status rule (Johan's ruling, 2026-10-06):** the New Lease search returns **any rental property regardless of
status** — To Let, Rented, Withdrawn, Expired, Draft and so on. Example: an agent phones a withdrawn owner who
agrees to rent the property out; it must be findable and saveable on a lease. There is deliberately **no status
filter** in the query, and each result keeps its status badge (`Property::statusBadge()`) so the agent can tell a
live listing from a dead one. **Sale-only listings (`listing_type` ≠ `rental`) stay excluded**, whatever their
status. Overlap with an existing active lease is still caught at activation (§3.5), not by hiding the property.

**Proof:** `tests/Feature/Leases/LeaseCreatePropertySearchTest.php` (sale listing refused, out-of-scope refused,
other-agency / made-up / array / non-numeric refused, in-scope still saves, picker-vs-save drift guard, withdrawn /
rented / expired rentals findable with badge and saveable, withdrawn sale-only still excluded, pre-fill cases,
full field + tenant restore round trip, forged tenant ids not restored).

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

---

## 12. Lease Hub (AT-440, added 2026-10-04) — lease detail becomes the tenancy file

**Status:** SPEC ONLY. Approved design, Johan, 4 Oct 2026. Master spec: `.ai/specs/rentals-rebuild.md`
(read its §1 shared standards first — list standard and context bar are not restated here).
**Closes the §11 "property-status-flip" open item above** — see §12.5: it resurfaced exactly as this
section predicted, and is resolved here using the Property model's own existing status governance,
not a new mechanism.

### 12.1 What changes and why
Today a lease's `show()` page is a CRUD detail view — rent, dates, tenant, not much else (§7 of this
spec). Johan's instruction: the lease detail becomes **the tenancy file** — the one screen that
answers "what has happened on this tenancy, what needs to happen next, and is there anything open
right now" without an agent having to check four other screens. Nothing about the existing CRUD
(§2-§7 above) changes; this section adds to the `show()` page, it does not replace it.

### 12.2 Layout (full-width, not a narrow centre column — Screen rule from the task brief)
- **Header:** address, status (live, reflecting §12.5), tenant name(s), rent, term (start–end or
  "month-to-month"). **Actions:** Print tenancy report, Edit, Report a fault.
- **Rental context bar** (master spec §1.2) — Lease chip highlighted.
- **Lifecycle strip:** `Application → Approved → Lease signed → In-inspection → Tenancy →
  Renewal/notice → Out-inspection`, each node's done/current/pending state **derived from data**, not
  a stored field:
  | Node | Done when | Current when | Pending when |
  |---|---|---|---|
  | Application | `rental_applications` row linked via `leases.rental_application_id` has `status=approved` | that application exists and is not yet approved | no linked application (lease created directly) |
  | Approved | application approved | — (instantaneous with Application done) | — |
  | Lease signed | a signed e-sign document exists OR lease was manually marked active with terms captured | lease is `draft` | — |
  | In-inspection | an in-type `rental_inspections` row with `status=completed` exists for this lease | lease is `active` with no completed in-inspection yet | — |
  | Tenancy | lease `status=active` and in-inspection completed | — | — |
  | Renewal/notice | a renewal lease (`leases.previous_lease_id` — new column, §12.5.5) exists, OR notice recorded (§12.5.3) | lease `end_date` within the agency's reminder-lead-time setting (`LeaseSetting::expiryNoticeWindowDaysFor()`, already live, `LeaseSetting.php:58-67`) | lease active, outside that window |
  | Out-inspection | an out-type `rental_inspections` row with `status=completed` exists | lease `status` in (`notice_given`,`ended`) with no completed out-inspection yet | — |
- **Tenancy log:** one chronological, searchable, type-filterable list of everything on the tenancy —
  application events, lease create/edit/escalation, inspections, faults, work orders, job cards
  (Stage 4), documents, notices (Stage 7). This is the evidence trail for the out-inspection. Search:
  free text over each entry's description. Filter: by type (the list above) and date range. No
  pagination cap below 500 rows (a full tenancy's log is expected to be small; if this assumption
  breaks on real data, paginate — flagged, not built defensively here per BUILD_STANDARD §0's "don't
  over-engineer for a case that doesn't exist yet").
- **Next-step card:** single next action derived from lifecycle-strip state, e.g. no completed
  in-inspection on an active lease → "Start in-inspection" (links straight into the inspection flow,
  pre-filled with this lease). One action, not a list — if two are equally due, the earliest-dated
  one wins; tie-break on lifecycle-strip order above.
- **Lease terms card:** rent, deposit, landlord spend limit (`properties.rental_no_approval_spend_threshold`,
  already live), notice window opens (derived: `end_date` minus the agency's tenant-notice-period
  setting, Stage 6), landlord(s) (via `Property::sellerOwnerContact()`/`contactsForRole('landlord')` —
  audit Part 3 item G, the existing derive-don't-duplicate mechanism, unchanged here), lease document
  view/print.
- **Open items card:** open fault reports + open work orders for this lease, count + direct links.
- **Property's Rental tab gains occupancy history** (§12.6).

### 12.3 Search / sort / filter / pagination on the Tenancy log (per BUILD_STANDARD §1b)
- **Search fields:** entry description, actor name.
- **Sort:** date (default: newest first).
- **Filter:** entry type (multi-select: application / lease / inspection / fault / work order / job
  card / document / notice), date range.
- **Pagination:** 50/page if the log exceeds 500 rows (see note in §12.2).
- **Empty state:** "Nothing recorded yet on this tenancy" — a brand-new lease has a genuinely empty
  log, distinct from a filtered-to-nothing result ("No {type} entries in this date range").

### 12.4 Scoping, permissions, API
- Own/branch/agency scoping: unchanged from §7/§8 above — the Lease Hub is the existing `show()`
  route with more content, not a new route, so it inherits the per-record guard added in
  `rentals-foundation-at439.md` §4.1.
- No new permission key — reuses `leases.view` to see the hub, existing action keys
  (`leases.create`/`.renew`/`.cancel`) continue to gate the actions shown.
- **API:** `GET /api/v1/leases/{lease}/tenancy-log` (JSON, same scope guard, paginated) — the screen's
  own tenancy-log panel consumes this; Andre's mobile app calls the same endpoint for a tenant/agent
  mobile tenancy view. Named per CLAUDE.md non-negotiable #7 (versioned, `->name()`'d, appears in the
  `/admin/api` catalogue automatically).

### 12.5 Property status follows the lease (Johan's ruling, 4 Oct 2026)

**Builds on the EXISTING, already-settled status mechanism — nothing new is designed here:**
- Agency-configurable status list: `PropertySettingItem` (`group='property_status'`,
  `app/Models/PropertySettingItem.php:29`) — agencies add/rename/deactivate their own status options;
  CoreX does not hardcode a status enum.
- Vocabulary guard + audit + domain event, already wired: `PropertyObserver::updated()` validates any
  dirty `status` against `Property::isAllowedStatus()` (AT-307 guard, `app/Observers/PropertyObserver.php:139-175`),
  then calls `PropertyAuditService::logStatusChange($property, $old, $new)` (line 480-481) and fires
  the already-catalogued `Property\PropertyStatusChanged` domain event
  (`corex-domain-events-spec.md:305`, consumed today by `FlagPropertyAsOnBooks` and
  `NotifyExpiredMandateBranchManager`).
- The "leased out" status already exists and is already live: **`let_out`** (confirmed real,
  currently-live `property_status` option — `rentals-shared-screens.md` §5.2, 70 real agency-1
  properties carry it today). **No new status is introduced for the base leased-out case.**

**What this section adds:** the code paths that *drive* a status change automatically, firing through
the exact mechanism above (never bypassing it, never writing `properties.status` directly without
going through the model save that triggers the observer).

1. **Lease becomes active → property status set to the agency's configured "leased out" status**
   (default `let_out`, but see §12.5.4 — agency-configurable which status value this is, since a
   second agency may have renamed or not have `let_out` in its own `PropertySettingItem` list).
   Fires from `LeaseActivationService::activate()` (the existing activation service, audit Part 3) —
   after the existing overlap-prevention guard succeeds, it additionally calls
   `$property->update(['status' => $agency->leasedOutStatusValue()])` (new settings accessor, §12.5.4),
   which runs through the normal `Property::save()` path and therefore through the observer/audit/
   event chain above unchanged.

2. **A lease CAN be created/activated on a Withdrawn property.** Real scenario: owner withdrew, a
   qualifying tenant appears, owner agrees to let again. `LeaseActivationService::activate()` does
   **not** block this. The UI, at the moment of creating/activating a lease on a property whose
   current status is `withdrawn` (or any other non-`let_out` status), shows a confirmation dialog:
   > "This property is withdrawn. Are you sure you want to use it for this lease?"
   On confirm, activation proceeds exactly as in point 1 — the status change to "leased out" is
   automatic, there is no separate manual re-activation step the agent must also perform. On cancel,
   activation does not proceed (lease stays `draft`).

3. **Status keeps following what CoreX learns during the lease — the full transition table:**

   | Event | New property status (agency-configured value) | Advertise? | Availability-from date |
   |---|---|---|---|
   | Lease created, not yet active (`draft`) | unchanged (whatever it was) | unchanged | — |
   | Lease activated | "leased out" (default `let_out`) | **No** — must not stay advertised (§12.5.6) | — |
   | Tenant gives notice / landlord gives notice, move-out date known (new `leases.notice_date`/`notice_given_by` columns) | "notice given" (new, agency-configured status, default label "Notice Given") | **Yes** — advertising starts now, ahead of vacancy | = day after lease `end_date` (e.g. lease ends 31 Oct → available-from 1 Nov) |
   | Renewal signed (Stage 6) — new lease term linked, old term closed | "leased out" (stays, or re-set if it had drifted) | No | — |
   | Lease becomes month-to-month (no fixed end date, explicit agent action) | "leased out" (stays) | No | — |
   | Lease end date passes with no renewal/notice recorded and no out-inspection yet | unchanged automatically (flagged in the Command Centre as overdue, Stage 3) — **no silent automatic flip to vacant without evidence the tenant actually left** | No change | — |
   | Lease ended (out-inspection completed, property confirmed vacant) | "vacant"/agency's normal pre-let status (e.g. `draft`/`to_let` — agency-configured, §12.5.4) | Yes, if the agency wants it back on market immediately | today's date (available now) |
   | Lease cancelled (early termination, any cause) | same as "lease ended" row | Yes | the recorded cancellation/vacate date |

   Every automatic change in this table writes to the property audit trail via the existing
   `PropertyAuditService::logStatusChange()` call, with a cause string identifying it as lease-driven
   (e.g. `"Lease #{id} activated"`, `"Tenant notice recorded on Lease #{id}"`, `"Lease #{id} ended —
   out-inspection completed"`) — distinguishing it from a manual agent-initiated status change in the
   same audit log, per CLAUDE.md non-negotiable #1's spirit of a legible record, not per any new
   requirement on `PropertyAuditService` itself (it already accepts a free-text reason).

4. **Agency settings (new, every one surfaced in the Setup Wizard per CLAUDE.md non-negotiable #10a):**
   - `leased_out_status_value` — which of the agency's own `property_status` items means "leased
     out." Default: `let_out` if present in that agency's list, else the agency must pick one during
     onboarding (no silent fallback to a status that doesn't exist for them).
   - `notice_given_status_value` — which status value means "notice given, re-advertising." New
     `PropertySettingItem` row seeded per agency (not hardcoded), default label "Notice Given."
   - `post_tenancy_status_value` — which status a property reverts to once a lease ends/cancels with
     no renewal. Default: whatever the agency's normal pre-let status already is (commonly `draft` or
     `to_let` — read from the agency's existing list, not assumed).
   - Per-transition **on/off toggles**, each defaulting ON: `auto_status_on_lease_active`,
     `auto_status_on_notice_given`, `auto_status_on_lease_ended`. An agency that wants to keep full
     manual control over property status can turn any of these off individually; CoreX then performs
     every other part of the lease lifecycle (lifecycle strip, tenancy log, next-step card) unchanged
     — only the automatic property-status write is skipped, and the next-step card surfaces "Update
     property status" as a manual action instead.

5. **Portal syndication consequences, stated explicitly (per instruction):**
   - **"Leased out" must not stay advertised.** The existing portal-sync machinery already reads
     `Property::isOnMarket()`/the status-driven on-market predicate (`Property.php:1943-1965`) to
     decide what stays live on P24/PP/the website — a status change to the agency's configured
     "leased out" value is already sufficient to pull the listing, through the existing mechanism,
     with no new portal-sync code. This spec does not add a second, parallel "should this be
     advertised" check.
   - **"Notice given" re-advertises, with the availability date carried through.** The existing
     `advertise` boolean + availability-date fields already added to the Rental tab
     (`property_rental_details_custom_fields` migration, 2026-09-21 — confirmed existing column) are
     the vehicle: setting status to "notice given" sets `advertise=true` and the availability-from
     date per the table in point 3. The portal payload for a "notice given" property states the
     availability date explicitly (existing field on the rental advert block per `rental-property-tab.md`)
     — a prospective tenant sees "available from 1 Nov," not a bare live listing with no date.
   - **New lease on a withdrawn property (point 2)**: the property leaves "withdrawn" and its
     withdrawn-mandate implications (not separately advertised while withdrawn, per existing
     withdrawn-status behaviour) the instant the lease activates — same mechanism, no special case.

### 12.6 Property's Rental tab gains occupancy history
New read-only section on the existing Rental tab: every **past** lease on this property (status
`ended`/`cancelled`), newest first — tenant name(s), term, end reason (ended/cancelled/non-renewed),
link to that lease's own hub (§12.2). This is the "who stayed when" record the audit found missing
(audit Part 1 item 3, Part 5 top-gap #7) — the active-lease partial planned but never built is
superseded by linking straight to the Lease Hub for the current lease, rather than duplicating its
summary on the Property tab a second time (Screen rule: no fact shown twice).

### 12.7 Acceptance criteria (additive to §7's existing CRUD acceptance criteria)
- [ ] Lease `show()` renders the full Lease Hub layout (§12.2) for every lease, including one with
      no application, no inspections, and no faults/work-orders yet (the brand-new-lease empty case).
- [ ] Lifecycle strip's done/current/pending states are derived live, never a stored enum that can
      drift from reality.
- [ ] Tenancy log search/sort/filter/pagination/empty-state all work per §12.3.
- [ ] Activating a lease on a `withdrawn` property shows the confirmation dialog and proceeds
      correctly on confirm; status changes automatically, no separate manual step required.
- [ ] Every automatic status transition in §12.5.3's table fires through `PropertyObserver`/
      `PropertyAuditService`/`PropertyStatusChanged` — never a direct `DB::table('properties')->update()`.
- [ ] Each of the three new agency settings (§12.5.4) is in the Setup Wizard with `explain` +
      `affects` copy, and each per-transition toggle defaults ON and is independently switchable.
- [ ] Turning a toggle OFF stops that one automatic write and surfaces the manual next-step action
      instead, without breaking any other part of the Lease Hub.
- [ ] A property moved to "leased out" drops off P24/PP/website through the existing on-market
      predicate, with no new portal-sync code required.
- [ ] A property moved to "notice given" re-advertises with the correct availability-from date
      visible in the portal payload.
- [ ] Property's Rental tab shows occupancy history for every past lease, linking to each one's hub.

### 12.8 Open questions for Johan
- **§12.5.4 default status mapping**: this spec proposes `let_out` as the default "leased out" value
  and leaves "notice given"/"post-tenancy" to be set per-agency with no universal default beyond
  "whatever the agency's existing pre-let status is" — confirm this is acceptable, or state a
  universal default label for agencies that have never configured either.

### 12.9 Built, 2026-10-04 (AT-440, cc3) — what landed, what was scoped down, and corrections found

**Built, verified in Tinker + PHPUnit against real/throwaway data (never Johan's real properties):**
§12.2 Lease Hub layout (header/actions/lifecycle strip/next-step card/tenancy log/lease terms/open
items/escalation history, full-width), §12.3 tenancy-log search/filter/pagination/empty-state, §12.4
scoping/API (`GET /api/v1/leases/{lease}/tenancy-log`, scope-guarded), §12.6 occupancy history on the
property Rental tab, the Print Tenancy Report PDF, and a narrowed §12.5 (below). New files:
`app/Services/Rentals/LeaseTimelineService.php`, `app/Services/Rentals/LeaseHubService.php`,
`app/Http/Controllers/Concerns/AuthorizesLeaseAccess.php` (superseded and removed at the AT-439 merge,
2026-10-04 — see §13 below: AT-439's `AuthorizesRentalRecordScope::guardRentalRecordScope()` is
byte-for-byte the same own/branch/all/audit-log logic, generalised across Lease/RentalFaultReport/
RentalWorkOrder/RentalInspection; `LeaseController` now uses that trait exclusively, per this trait's
own original docblock: "should be consolidated with that one at merge time rather than two
near-identical guards living side by side"),
`resources/views/components/rental-context-bar.blade.php`,
`resources/views/corex/leases/pdf/tenancy-report.blade.php`, `tests/Feature/Leases/LeaseHubTest.php`.

**Context bar — built here, not in AT-439.** §12.4/master-spec §1.2/rentals-foundation-at439.md §5 all
describe ONE shared context-bar component; §5 there frames it as an AT-439 (Stage 1) deliverable. As
of this build, it did not exist in AT-439's own worktree (checked directly) and this ticket's own task
brief explicitly assigns the context bar to AT-440. Built once, here —
`resources/views/components/rental-context-bar.blade.php` — exactly to the master spec's §1.2 chip
list and order. Included on the Lease Hub and the property Rental tab in this build; the one-line
`<x-rental-context-bar :lease="$x->lease" current="..." />` include for the Inspection/Fault
Report/Work Order show screens is handed to AT-439/AT-442 rather than added here, to avoid editing
files those lanes have open concurrently. **Do not build a second copy of this component** — adopt
this one.

**§12.5 property-status-follows-lease — built narrower than specced, by design, for the 15 Oct
deadline.** Built: point 1 (lease activates → property status flips to `let_out`, through the
property's normal `save()` so `PropertyObserver`/`PropertyAuditService::log()` fire exactly as a
manual change would), point 2 (confirm dialog on Activate when the property is currently
`withdrawn`, proceeding automatically on confirm — client-side speed-bump only, `activate()` itself
never blocks on it), the audit-trail-with-cause requirement (`metadata.cause = "Lease #{id}
activated"`), and the portal consequence (verified directly: a property with real on-market status
flips `isOnMarket()` true→false purely from this status write — zero new portal-sync code, exactly as
specced). **NOT built, deliberately** — the §12.5.3 full transition table's "notice given" row
(`leases.notice_date`/`notice_given_by`, the re-advertise-with-availability-date behaviour) and the
"lease ended → reverts to pre-let status" row: both depend on the notice/renewal and out-inspection-
completion workflows that are explicitly Stage 6/7 in the master spec, outside AT-440's own task
brief (which named only "lease active → leased out," "withdrawn confirm," "audit trail," and "portal
consequence stated" for this ticket). Revisit when Stage 6/7 land.

**§12.5.4 settings — not built; `let_out` used directly instead, and this is a deliberate scope
cut, not an oversight.** Rather than a new `leased_out_status_value` agency setting (which the
generic Setup Wizard control renderer can't express as a per-agency dynamic select without new
plumbing — it only supports static `options` arrays), this build reuses the `let_out` slug directly.
This is NOT a new multi-agency hardcode: `let_out` is already a system-wide status slug baked into
`Property::OFF_MARKET_STATUSES`/`CONCLUDED_STATUSES` (`Property.php:68,1726`) for every agency, not
an HFC-specific value introduced by this ticket. Prevent-or-absorb: if an agency's own
`property_status` vocabulary doesn't include `let_out`, `LeaseActivationService` skips the automatic
write entirely (`Property::isAllowedStatus()` check) rather than writing an unconfigured status —
confirmed in Tinker. `notice_given_status_value`/`post_tenancy_status_value` are moot until Stage 6/7
are built. **Flagged for Johan**: if a second agency needs `let_out` renamed/different before Stage 6
lands, the real setting from §12.5.4 should be built then, not deferred indefinitely.

**Correction to §12.5's own text, found during this build**: §12.5 states
`Property\PropertyStatusChanged` is "already catalogued... consumed today by `FlagPropertyAsOnBooks`
and `NotifyExpiredMandateBranchManager`." Checked directly — neither listener file exists anywhere in
the codebase, and `app/Events/Property/` has no `PropertyStatusChanged.php`. The domain-events
catalogue entry is aspirational, not built — a pre-existing gap, not something this ticket introduces
or was asked to fix (non-negotiable #2: reported, not touched). Because of this, the status-flip in
`LeaseActivationService::flipPropertyToLeasedOut()` goes through the property's normal `save()` +
`PropertyAuditService::log()` directly (the mechanism that genuinely exists today), not through a new
`PropertyStatusChanged` listener — consistent with non-negotiable #9's intent (no ad-hoc side-channel;
this is the SAME aggregate `LeaseActivationService` already locks and saves in the same transaction,
not a new cross-pillar hook) but flagged here in case Johan wants the full domain-event catalogue
entry actually built as separate follow-up work.

**Tests**: `tests/Feature/Leases/LeaseHubTest.php` — 14 cases: lease-isolation (a previous tenant's
fault never appears on the next lease's log — the task's own named concern, confirmed both via direct
service call and over real HTTP), timeline ordering (newest-first), timeline search/type filter,
lifecycle derivation (both an active-no-inspection lease and a draft lease), next-step priority rule
and its null case, landlord derivation from the contact pivot (not a column), lease-scoped open-item
counts, the tenancy-log API's and the PDF's cross-agency 403/404 guard, an authorised fetch of each,
and a brand-new lease with nothing attached rendering its genuinely-empty tenancy log. 13 passed on
first write; one (`lease hub show renders for a brand new lease`) initially failed because
`LeaseTimelineService` was logging a "Lease created" entry even for an untouched draft — fixed by
removing that entry (§12.3's own "a brand-new lease has a genuinely empty log" wording was the
correct spec; the implementation was wrong, not the acceptance criterion) — then passed.

### 12.10 Built, 2026-10-04 (AT-444) — §12.5.3's `notice_date`/`notice_given_by` columns now real

`leases.notice_date`/`notice_given_by`/`notice_note`/`move_out_date` (§12.5.3's row 2 cites these as
"new" — they now exist, migration `2026_10_04_220000`, `rental-renewals.md` §2/§7). Written by the
new one-click "Tenant gave notice"/"Landlord not renewing" outcomes
(`App\Services\Rentals\LeaseRenewalService::recordNotice()`), reversible, with history kept in a new
append-only `lease_events` table (not on the lease row itself, so a reversal never erases that the
event happened — see `rental-renewals.md` §14 for the full reasoning).

**§12.5.3's property-status side effects (the "Advertise? Yes" column, the re-advertise-with-
availability-date behaviour) are now built too**, under GATE 2 (approved by the conductor 2026-10-04,
same day), WITH one change from this section's original wording: no new "Notice Given" property
status is introduced. Notice is a fact about the LEASE only. The existing status mechanism is driven
directly (`Property::isAllowedStatus()` / `isOnMarket()`) — the notice dialog's "put this property
back on the market" tick always uses the agency's configured on-market rental status (a setting, never
`status_before_letting` directly), while a lease ending or being cancelled restores the property's own
captured pre-let status (`properties.status_before_letting`, captured once at lease activation),
falling back to that same on-market setting only if nothing was ever captured — two genuinely
different rules, not one shared expression. See `rental-renewals.md` §15 for the full as-built
transition table, the three agency settings, and the portal-syndication findings (including a real
pre-existing gap found in both portal mappers' own availability-date handling, reported not fixed).

`LeaseHubService::nextStep()`'s "Review renewal"/"Record outcome" now link to the real renewal screen
(`corex.leases.renewal.create`) instead of AT-440's own lease-edit placeholder, and are suppressed once
`Lease::hasActiveNotice()` or `renewed_lease_id` is set — nothing left to "review" until reversed.

### 12.11 Built, 2026-10-05 (AT-444/AT-441 follow-up) — "Lease actions" menu items open as dialogs

**Root cause fixed:** every item in the header's "Lease actions ▾" menu (Renew lease, Goes
month-to-month, Tenant gave notice, Landlord not renewing, Reverse notice, Reverse month-to-month,
Cancel lease) toggled visibility of a plain `<div>`/hidden `<form>` sitting in the right-hand column,
below "Open items" — on a normal screen the agent clicked the menu item and the result was off-screen,
with no visible feedback. Each item now opens the EXACT same form (same routes, same field names — no
behaviour change in what gets submitted) inside `<x-modal>` (the app's existing Breeze-style modal
component, `resources/views/components/modal.blade.php`), with focus landing on the first field
(`focusable` attribute) and Escape/backdrop-click/Cancel all closing it (the component's existing
mechanism). "Renew lease…" and the two "Reverse …" items had no confirmation UI at all before (Renew
navigated away immediately; Reverse submitted a hidden form with zero feedback) — they now open a
lightweight confirm dialog too, for the same "something visibly happens on click" reason.

**`?action=renew|month-to-month|tenant-notice|landlord-notice`** on the Lease Hub URL opens the
matching dialog on page load — the mechanism the Command Centre (`rental-command-centre.md` §3.2/§3.3)
uses to deep-link an agent straight into the right dialog instead of landing them on the hub with one
more click still needed. An action not valid for the lease's CURRENT state (e.g. `?action=renew` on a
lease that is not active, or `?action=month-to-month` on a lease already month-to-month) is silently
ignored — never force-opened. `reverse-notice`/`reverse-month-to-month`/`cancel` are deliberately NOT
URL-triggerable (reachable only from the menu itself).

**New, pure-logic service:** `App\Services\Rentals\LeaseActionDialogResolver` — `validActionsFor(Lease)`
mirrors the EXACT conditions the menu itself uses to decide which items to render, and `resolve()`
decides which dialog (if any) opens: a just-failed submission (identified by a hidden `_lease_action`
field each dialog's form carries, echoed back via `old('_lease_action')`) always wins over `?action=`,
so the agent sees their own mistake corrected, not a different dialog. The hidden field exists because
Tenant-gave-notice and Landlord-not-renewing post the SAME field names (`move_out_date`, `note`) through
`LeaseRenewalController::recordNotice()` — without it, Laravel's single default error bag can't tell
which of the two actually failed, and `old()` would leak one form's entered values into the other's
same-named field.

Tests: `tests/Unit/Services/Rentals/LeaseActionDialogResolverTest.php` (pure logic — validity per
state, URL-action-wins vs reopen-wins, invalid-for-state is ignored either way) and
`tests/Feature/Leases/LeaseActionDialogsTest.php` (HTTP — `?action=` opens the right dialog and is
ignored when invalid; a failed tenant-notice/landlord-notice/cancel submission reopens the SAME dialog
with the entered values; the shared-field-name leak between tenant-notice and landlord-notice is
proven absent).

---

## 13. AT-439 (Rentals rebuild 1/7, "Foundation") — Part 1 fixes, 2026-10-04

Built strictly from `/tmp/rentals-stage1-investigation.md` (the prior read-only audit's root-cause
findings). Six items landed on `at439-rentals-foundation-2026-10-04` off `QA1`. Everything below is
already live in the code — this section documents what changed and why, it does not propose anything.

**Own/Branch/All scope on the Leases list (§C of the investigation).** `LeaseController::index()`
already called `Lease::visibleTo()` (own/branch/all query-layer scoping existed); it had no UI control
to let a user with a wider ceiling actually choose a narrower/wider view, and no screen told them the
control existed. Fixed: the same "Showing: Own | Branch | All" pill control `rental-applications`
already has (`$scopeOptions` built from `PermissionService::getDataScope($user, 'leases')`, never
offering a wider pill than the user's role permits; highlighted from `$resolvedScope`) now renders on
`corex/leases/index.blade.php`. **Per-record scope guard, added as a class, not per-controller**: a
new trait, `App\Http\Controllers\Concerns\AuthorizesRentalRecordScope::guardRentalRecordScope()`,
mirrors `AuthorizesRentalApplicationAccess::guardRentalApplication()` generalised across Lease/
RentalFaultReport/RentalWorkOrder/RentalInspection (all four shared this exact gap, found and fixed
together per BUILD_STANDARD §6). `LeaseController`'s `show`/`update`/`activate`/`cancel`/`escalate`/
`destroy`/`restore` all now call it before touching the bound `$lease` — previously a user whose list
screen was scoped to `own`/`branch` could still open/mutate any lease in the agency by direct URL/ID.
The guard resolves "branch" exactly the way `Lease::scopeVisibleTo()` already does — `leases.branch_id`
directly (unlike the other three models, which check the record's PROPERTY's branch_id instead — the
guard takes the resolved branch id as a parameter precisely so it never has to guess which column a
given model's own list query actually uses). See `rental-work-orders.md` §(AT-439 addendum) and
`rental-inspections.md` §(AT-439 addendum) for the same fix on Fault Reports/Work Orders/Inspections.

**Renewal-reminder command repointed from the legacy table to the real `leases` table (§E).**
`CheckLeaseExpiry` (`signatures:check-lease-expiry`, daily 06:00, signature/schedule entry unchanged)
previously queried `Docuperfect\LeaseRecord` (2 test-artifact rows) — the real rentals `Lease` model
(the one this spec, `rental-work-orders.md`, `rental-inspections.md`, and `rental-inventory.md` all
hang off) got no automated expiry alert at all. Fixed: the command now queries `Lease` directly
(`withoutGlobalScopes()`, explicit — console commands run with no authenticated user so `AgencyScope`
is already a no-op here, same as `LeaseSetting`'s own existing convention, but made explicit rather
than relied-on). **Expiry is never automatic — and since 7 Oct 2026 the one thing that IS automatic is the switch to month-to-month, by a SEPARATE command (§5.3); this command still never writes lease state (Johan's ruling, corrected 2026-10-04 after an
unscoped verification run of an earlier version of this command auto-flipped three real QA1
leases to `expired`)** — a lease whose `end_date` has passed stays `active` and is only ever
FLAGGED, via the same alert, for the agent to record the real outcome (renewed / month-to-month /
notice / ended); the status change happens only when the agent acts, through
`LeaseRenewalService`/`LeaseActivationService` (AT-444), never from this command (no intermediate
"expiring soon" status exists on `Lease` either, and none is invented here). The command also
iterates agencies EXPLICITLY (`foreach (Agency::all() as $agency)`, each iteration naming
`agency_id` in its own query) rather than one bulk `withoutGlobalScopes()` query implicitly
spanning every agency at once — the explicit loop is what makes the scope visible at the call
site, after the unscoped-bulk-query shape is exactly what let a verification run touch real data
across the whole table in one call. The agency's own
`LeaseSetting::expiryNoticeWindowDaysFor($lease->agency_id)` — already live on the Lease Settings
screen and the onboarding wizard, just never called from this command — now gates how far out the
tiered urgent(≤30)/warning(≤60)/notice alerts start firing, resolved PER LEASE'S OWN `agency_id`, so
one global run correctly serves every agency's own configured window. Recipient stays exactly who it
was before — the agent (`lease->createdByUser`, the real-Lease equivalent of the legacy command's
"the e-sign document's owner") — no tenant/landlord notification added. Delivery stays
database-notification-only; `LeaseExpirationMail` (the legacy, dead email path) was NOT revived.
New notification class `App\Notifications\LeaseExpiryAlert` (NOT a modification of
`LeaseExpirationAlert`, which keeps serving `LeaseRecord` exactly as before — the two models' display
shapes genuinely differ: property address/tenant name are relations on `Lease`, denormalised columns
on `LeaseRecord`). Idempotent via the same 7-day cache-dedup pattern, under a distinct key prefix
(`lease_v2_alert_*`) so it can never collide with the legacy command's own cache keys.

**E-sign → Lease: draft promotion instead of a duplicate (§F).** `SignatureService::
createLeaseFromSignedDocument()` already created a `Lease` row from a completed lease e-sign document
(the field-name mismatch a prior audit flagged — `lease_start_date` vs the real template's
`lease_start` — was already fixed before this build). What it never did: check for an existing
DRAFT lease on the same property to promote. Matching, in order: (1) a draft already linked to the
SAME tenant the document resolves (via the already-resolved `TenantContactResolver` contact) —
takes priority so a draft already tied to a DIFFERENT, known tenant (e.g. a renewal being prepared
alongside a still-active lease) is never silently reassigned; (2) failing that, a draft with NO
tenant linked at all yet — the common case, a draft started manually before any tenant was
decided — but ONLY when exactly one such open draft exists on the property, so an ambiguous
multi-draft property is never guessed at. Fixed: a draft match now gets its terms (`rental_amount`/`start_date`/`end_date`),
`source`, and `source_document_id` set from the signed document and is PROMOTED via
`LeaseActivationService::activate()` — inside a `DB::transaction()` — instead of a second, disconnected
row being created for the same real-world tenancy. If another lease is already active on that
property (a genuine conflict with `LeaseActivationService`'s own one-active-lease guard, not the
common case), the draft's terms/`source_document_id` are still saved so the document stays linked, but
its status is left as-is rather than letting the `ValidationException` escape into the e-sign
completion cascade. `SignatureAuditLog` records `lease_promoted_from_document` vs
`lease_created_from_document` so the two paths stay distinguishable in the audit trail.

**Landlord on a lease — derived accessor, still no column (§G).** `Lease::landlordContacts()`
(N-party — returns every landlord-side contact, never collapses to one) resolves through the
PROPERTY's existing `contact_property` pivot, expressed via the property's own canonical contact-role
keys (`contactsForRole('landlord')` merged with `contactsForRole('lessor')` — the same
`Property::pivotRolesForContactRole()` vocabulary the e-sign wizard's role picker already uses)
rather than a raw hardcoded pivot-role string check written fresh in `Lease.php`. No migration, no new
column — `Lease`'s own class docblock already stated the landlord is meant to be derived from the
property's existing link, never duplicated; this method is that derivation made callable.

**Legacy-migration tokenizer fix — 3 rows only, nothing else attempted (§D).** `Property::
scopeSearchAddress()` tokenises on whitespace only, so a comma/slash stays glued to the adjacent
token (`"Alomsee 4,"` never matches a bare `"4"`). Fixed ONLY in `LeasePropertyResolver::
matchOneByAddress()` — the one method both `leases:migrate-legacy` and the e-sign auto-population
above actually call — by replacing `,`/`/` with a space in the free-text address BEFORE it reaches
the shared scope, rather than widening `scopeSearchAddress()` itself (which also drives every live
property-search box across the app; that is a different, much larger-blast-radius change than this
one legacy-matching path calls for). Verified via `php artisan leases:migrate-legacy --dry-run`
(read-only, zero writes): unresolved `rentals` rows dropped from 54 to 51 — exactly the 3 rows this
fix targets (ids 3, 5, 58) — with no new rows becoming falsely ambiguous. The other 51 (24
bad/missing-address rows, 27 genuine same-complex multi-unit ambiguity) were explicitly NOT
attempted, per the investigation's own warning that any fuzzy/first-candidate matching there risks
silently merging two different units' tenancy history. The live migration itself was not re-run
beyond this dry run — the legacy tables were not written to or deleted from.

### Files changed (§13)

- `app/Http/Controllers/Concerns/AuthorizesRentalRecordScope.php` — new trait
- `app/Http/Controllers/CoreX/LeaseController.php` — scope control + per-record guard
- `resources/views/corex/leases/index.blade.php` — "Showing:" control
- `app/Console/Commands/CheckLeaseExpiry.php` — repointed to `Lease`
- `app/Notifications/LeaseExpiryAlert.php` — new notification class
- `app/Services/Docuperfect/SignatureService.php` — `createLeaseFromSignedDocument()` draft promotion
- `app/Models/Lease.php` — `landlordContacts()`

## 14. Soft-deleted related-record render fix (2026-10-05) — BUILD_STANDARD §4/§6

Confirmed bug on QA1: `/corex/leases/2` 500'd. Lease #2's property (#1092) had
been soft-deleted 2026-06-25. `Lease::property()` was a plain `belongsTo`
(excludes trashed), so `$lease->property` resolved to `null`; the Lease Hub's
"Link landlord" fallback (`leases/show.blade.php`) passed that `null` straight
into `route('corex.properties.show', $lease->property)`, which throws trying
to resolve the `{property}` route parameter — never a graceful 404, a hard 500
on page load.

Fixed as a **class**, not the one instance (BUILD_STANDARD §6): every BelongsTo
relation across the rentals models that points at Property/Lease/Contact/
supplier now carries `->withTrashed()`, matching the pre-existing
`RentalWorkOrder::property()` precedent (the one relation in this family that
already had it). The parent record is never null just because it was
archived; the trashed object loads and `->trashed()` tells the view so.

**Models changed:** `Lease` (`property()`, `rentalApplication()`,
`previousLease()`, `renewedLease()`), `LeaseTenant` (`lease()`, `contact()`),
`RentalFaultReport` (`property()`, `lease()`, `reportedByContact()`),
`RentalWorkOrder` (`lease()`, `supplier()`, `reportedByContact()` —
`property()` already had it), `RentalWorkOrderQuote` (`supplier()`),
`RentalJobCard` (`property()`, `lease()`), `RentalInspection` (`lease()`,
`property()`), `RentalInspectionItem` (`property()`), `RentalNotice`
(`lease()`), `RentalInventory` (`property()`, `lease()`), `RentalApplication`
(`contact()`, `property()`).

**View rule applied everywhere one of these relations renders:** show the
address/name with an `(archived)` marker; never link to that record's own
`show` route if it's archived — default route-model binding 404s on a
trashed record, so a link there is a dead end even though it no longer
crashes. Fixed this way: `leases/show.blade.php` (the landlord-link fallback,
the previous/renewed-lease links), `leases/index.blade.php` (the landlord
cell), `components/rental-context-bar.blade.php` (the shared bar included by
leases/show, fault-reports/show, work-orders/show, job-cards/show,
inspections/show — property/lease/inventory/documents chips and the
landlord-link all gated on `->trashed()`), `rental-notices/show.blade.php`
(two separate unguarded `route()` calls — the property line and the
"Back to Lease Hub" link off `$notice->lease`, found during the sweep, same
bug class, previously undetected because no soft-deleted lease had ever hit
a notice before), `rental-inventories/partials/_related-inventories.blade.php`
(shared by leases/show and rental-inspections/show), `rental-job-cards/show.blade.php`
(the quote-screen "Link landlord" fallback), plus archived-marker display
fixes on the fault-reports/work-orders/job-cards/inspections/notices/
rental-applications list and show screens, and the Command Centre queue row
partial. `rental-applications` views also gained `?->` nullsafe access on
`$application->contact` (was a bare `->`, logged a PHP warning rather than
crashing, but inconsistent with every other contact access in the same
files).

**Proof:** `tests/Feature/Rentals/SoftDeletedRelatedRecordRenderTest.php` —
one test per screen (leases show/index, fault reports, work orders, job
cards, inspections, notices — including a soft-deleted *lease* variant, not
just property — rental applications with both property and contact
soft-deleted, the Command Centre), each asserting 200 against a fixture with
a soft-deleted property/lease/tenant contact attached, plus a dedicated
regression case reproducing the exact lease-with-archived-property shape
that 500'd on QA1. 12/12 passing. Verified over real HTTP against QA1 after
deploy: `GET /corex/leases/2` → 200 (previously 500).

**Not touched, considered and ruled out of scope:** `LeaseEscalation::lease()`
and `LeaseEvent::lease()` (reverse BelongsTo, never dereferenced from a
render path in this sweep — `$lease->escalations`/`$lease->events` are the
only call sites and are HasMany, unaffected); `Lease::branch()` (Branch
archiving is a separate, much rarer admin action, not part of this bug
class — flagged, not fixed, per BUILD_STANDARD §2 "report, don't silently
expand scope").
- `app/Services/Rentals/LeasePropertyResolver.php` — comma/slash normalisation

---

## 15. One capture screen for New Lease and Renewal — "Create lease & prepare for signing" produces the agency's own e-sign lease agreement (6 Oct 2026, rulings of 13:51 written in; SPEC ONLY — no app code yet)

**Author:** cc6. **Status:** spec for build — **no open questions remain** (§15.20 is the rulings log). **Pillars:** Property (where the lease attaches), Contact (landlord, tenants), Agent (`User` — signs first and gives the final approval), Deal: none. **Domain events:** §15.12 (rule #9). **Investigated on:** `origin/QA1` `be0973f82`, 6 Oct 2026 (first pass `cb1fdfdc1`; the changes QA1 took on the New Lease screen since — §7.2/§7.3 — are folded in).
**Reading guide:** §15.0 the rulings · §15.1 what exists today (file:line) · §15.2 the design in one page · §15.3 the capture screen · §15.4 the two buttons · §15.5 status path · §15.6 renewal, new-tenant rule, paper copies · §15.7 where agreement values are stored · §15.8 the field map and how changes made in e-sign are detected · §15.9 what the agent sees when the document was changed · §15.10 data model · §15.11 filing the signed copy · §15.12 each agency's own lease agreement (set-up, link, HFC isolation, HFC's reference field map §15.12.5) · §15.13 scoping, permissions, navigation, list-screen additions · §15.14 settings and Setup Wizard · §15.15 audit, events, API · §15.16 failure cases · §15.17 multi-agency check · §15.18 acceptance criteria · §15.19 files · §15.20 tests · §15.21 build plan, exact files per build, conflict map with `rental-work-orders.md` §17 · §15.22 found while investigating — reported, not changed · §15.23 rulings log.

### 15.0 The rulings

**Provenance.** The earlier rulings L1–L6 reached this spec through the conductor's brief of 6 Oct 2026 (12:14). The seven rulings R1–R7 are Johan's rulings of **6 Oct 2026, 13:51, relayed by the conductor** — a relay, so they are recorded here as the conductor's paraphrase, **except the button name in quotation marks, which is the exact wording given**. Johan's own earlier phrase, quoted in the first brief: **"Create lease also produces the e-sign lease agreement"**.

| Id | Ruling |
|---|---|
| L1 | New Lease and Renewal use the SAME capture screen and the SAME functions. One screen takes the system lease fields AND the extra fields the agreement needs. |
| L2 | Two buttons. (a) *Create lease only* — the lease record and the property update, exactly as today, no document. (b) the second button — does (a), then generates the lease agreement in e-sign pre-filled from the captured data. |
| L3 | Flow for (b): everything required is present → the agent lands in e-sign fill-and-sign → verifies the pre-filled document → signs → it goes on to the recipients → on final approval the signed lease is filed against the lease and the lease is marked signed + accepted + active with its from/to dates. Nothing is typed twice. If required fields are missing the agent is told which and stays on the capture screen. |
| L4 | Renewal uses the same process and the same two buttons. The screen opens PRE-FILLED from the previous lease (and its e-sign values where they exist); the agent updates only what changed; (b) produces a NEW e-sign document. Renewal must be the simpler path. |
| L5 | A wet-ink (paper-signed) previous lease still gets renewal by e-sign: the screen pre-fills what the system lease holds, the agent fills the remaining agreement fields, the e-sign process is created as normal. |
| L6 | The spec says where e-sign field values are stored so a later renewal can pre-fill them, and which fields come up blank for a wet-ink first lease (§15.7, §15.6.3). |
| **R1** | The second button is named **"Create lease & prepare for signing"**. (Renewal wording: §15.4.) |
| **R2** | A signed paper lease can be attached on a **brand-new** lease as well as on renewal. |
| **R3** | **There is NO CoreX standard lease.** Each agency supplies its own lease agreement, set up and linked to this process. Until an agency has one linked, the second button is unavailable with a clear message saying where to set it up. The built-in template carrying HFC's name and fees is HFC's own and must not be offered to other agencies (enforcement: §15.12.4). |
| **R4** | Signing order is ALWAYS: **agent signs → tenant(s) sign → landlord signs.** No agency setting and no per-lease switch. |
| **R5** | "Accepted" = **the agent's final approval** when e-sign returns the fully signed document. On that approval the lease is filed and marked signed / accepted / active with its dates. |
| **R6** | Changes after sending use **e-sign's existing document-edit facility** (no separate "void and start again" flow). If CoreX detects that any lease-relevant value in the document was changed or edited in e-sign, the agent is shown the rental (lease) screen with the differences, to confirm everything is still the same or accept the changed values, and approve — **the lease record and the document must agree before acceptance.** The spec says how changes are detected (field map), what the agent sees, and that nothing is silently overwritten. (§15.8, §15.9) |
| **R7** | **Tenants cannot change at renewal.** A change of tenants is a NEW lease, not a renewal; the renewal screen says so and offers "start a new lease" pre-filled for that property. (§15.6.2) |

**Facts given by Johan, 6 Oct 2026, 14:32 (relayed by the conductor; paraphrase).** **F1** — live already has the lease imported as "Lease_Agreement_POPI_V8_CDS" (residential, about 4,450 words), but Johan has never created a lease from it on live; he will create all HFC's web-pack templates on live himself (planned 7 Oct). **So nothing in this section may assume a working, sendable HFC lease exists: "no lease agreement linked yet → button (b) unavailable with the set-up message" is the real starting state for HFC as much as for any other agency.** **F2** — the document's fill-in places, in order, are the reference field map for HFC's lease (§15.12.5).

What the rulings removed from the first draft of this section: the neutral CoreX lease and its clause settings, the agency choice of signing order, the "agency signs as managing agent" tick, the locked-terms-plus-"Void and start again" flow, and tenant editing at renewal.

### 15.1 What exists today (verified on `origin/QA1` `be0973f82`)

| # | Fact | Where |
|---|---|---|
| 1 | **Two separate screens, two validators, two writers.** New lease: `LeaseController::create` (`:326-368`) / `store` (`:399-473`) → `corex/leases/create.blade.php`. Renewal: `LeaseRenewalController::create` (`:44-67`) → `corex/leases/renewal.blade.php` (+ `_renewal-term-fields`). They share only the `Lease`/`LeaseTenant` models and `LeaseActivationService::activate()`. **Since the first pass, New Lease has a type-to-search property picker, keeps every field after an error and accepts only a rental property the user may see (§7.2, §7.3: `searchRentalProperties` `:244`, `pickableRentalProperties` `:229`, `Property::scopeRentalVisibleTo`, `oldTenantSeed` `:370`; any rental status is findable — Johan's 6 Oct status ruling).** The capture screen inherits all of that unchanged. | `LeaseController.php:229-260,326-473`; `LeaseRenewalController.php:44-159,292-301`; `LeaseRenewalService.php:40-77` |
| 2 | New lease writes `status=draft` (or active when "Activate immediately"), `source` manual/rental_application, tenants (first = primary). No transaction; an activation failure after the rows exist is not caught. No e-sign, no event, no `LeaseEvent`. | `LeaseController.php:399-473` |
| 3 | Renewal creates a NEW draft chained by `previous_lease_id`, copies tenants (cannot be changed), then one of four routes: copy-forward (needs the previous lease e-signed), draft from an agency template, upload a signed paper copy (`uploadRenewal` `:114-167` files the PDF as `source_type='lease'` and calls `activateRenewalTerm`), or an outcome (month-to-month, notices). | `LeaseRenewalController.php:69-167`; `RenewalDraftService.php:32-133` |
| 4 | **The only existing "record → pre-filled e-sign" launcher is `RenewalDraftService`.** It inserts one `flows` row (`type=esign`, `current_step=2`, `step_data` = template, property, recipients agent/landlord(s)/tenant(s) with `_contact_id`, details lease_start/lease_end/monthly_rental/deposit/lease_type) and redirects to `docuperfect.esign.step`. It touches no e-sign controller. Its `missingRequiredFields()` checks five keys through `WebTemplateDataService::resolve()` (`app/Services/WebTemplateDataService.php:44`). | `RenewalDraftService.php:68-97,144-241`; `LeaseRenewalController.php:81,104` |
| 5 | **No lease↔document link exists.** `flows` has no `lease_id`; the document and the signature envelope have no source columns; `leases` has only `source_document_id` (set at completion) and `renewal_draft_flow_id`. `flows.step_data['document_id'|'signature_template_id']` is written when the agent prepares signing. | `Lease.php:48-83`; `ESignWizardController.php:3813-3819` |
| 6 | **The only completion hook is hard-coded in `SignatureService`**, after the signed PDF is filed: `isLeaseDocument()` (keyword on document name/type) → `createLeaseRecord()` (legacy) + `createLeaseFromSignedDocument()`, which finds the lease by *property address text + tenant name*. No event fires on sent, completed, declined, cancelled or expired anywhere in the e-sign pipeline. | `SignatureService.php:4082-4087` (sync), `:4302-4311` (async), `:5344`, `:5506-5638`; decline `:5002`; cancel `ESignWizardController.php:8336`; expire `SignatureService.php:5162` |
| 7 | **`Lease` has no signed/accepted state**: `draft | active | expired | cancelled`. The hub's "lease signed" node is just `status !== draft`. | `Lease.php:24-27`; `LeaseHubService.php:22-71` |
| 8 | **After the last recipient signs, the agent must approve** (`pending_agent_approval`, AT-322). The approve form posts to `docuperfect.signatures.approveAndAdvance` → `SignatureController::approveAndAdvance` → `SignatureService::approveAndAdvance` → `completeDocument`. That approval is the "final approval" of R5. | `SignatureService.php:1676,3862`; `SignatureController.php:3328`; `web.php:6267`; form `docuperfect/signatures/review.blade.php:573` |
| 9 | The signed PDF is filed by the cascade as a `documents` row `source_type='esign'`, `source_id=<signature template id>`, attached to contacts and the property. A paper lease is filed `source_type='lease'`, `source_id=<lease id>`. `Lease` has no documents relation. | `SignatureService.php:4369-4490`; `LeaseRenewalController.php:131-150` |
| 10 | **Typed e-sign values live in several places**: `flows.step_data` (`details`, `fill_review.fieldValues`); `docuperfect_documents.web_template_data` (`_fill_review_overlay` keyed by `data-field`, flat resolved keys, signer-entered `field_values`); `fields_json` (a LIST of field objects, not a map); and the printed truth, the `data-field` spans in the stored `canonical_html`. The lease extractor reads `fields_json` as a map, which does not match a list. **Renewal copy-forward never reads the previous document's values.** | `ESignWizardController.php:2550-2605,3023-3027,3204-3247`; `CanonicalDocumentRenderer.php:615,756`; `SignatureService.php:5429-5442`; `RenewalDraftService.php:34-47,214` |
| 11 | **There is no e-sign-ready residential lease on QA1 or Staging.** The only lease rows are PDF imports (`is_esign=0`, ids 20, 23, 48); the seeded web template "Lease Agreement POPI V8" (`WebTemplateSeeder.php:70-80`) is not in either database (live not checked by this lane — **F1: live has the CDS import "Lease_Agreement_POPI_V8_CDS", never used to create a lease**). `rental_lease_templates` is empty on both. | `RentalLeaseTemplateController.php:56-68`; `Template.php:425-436` |
| 12 | **The V8 lease is HFC's**: "Home Finders Coastal" (blade lines 6, 411, 415, 1013, 1028), R1 200 / R2 000 / 10 % / 8.6 % figures. It is seeded **with no owning agency and `is_global = true`** — exactly the shape `applySharedWith` shows to every agency (`Template.php:425-436`). It is not in `deploy:sync-reference-data`; only `DemoDataSeeder` calls it. Seven `imported/lease-agreement-popi-v8-*.blade.php` copies carry the same text. | `WebTemplateSeeder.php:70-101`; `lease-agreement-popi-v8.blade.php`; `Template.php:365-453` |
| 13 | e-sign wizard steps: 1 template · 2 property · 3 recipients · 4 details · 5 fill & review · 6 signing set-up. A flow can only be opened by its creator. Agent is always signer #1 and signs in-app; each other signer needs an email and an ID/passport number. **Order is sequential only** — so R4's fixed order is the engine's natural order, no engine change. | `ESignWizardController.php:318-322,3320-3323,6539-6550`; `SignatureService.php:1086-1103,5243` |
| 14 | Renewal activation already does the right bookkeeping (`activateRenewalTerm`: escalation row on the new term, previous term expired, chain pointers, event). Plain `activate()` on a renewal draft skips the escalation and the event. | `LeaseRenewalService.php:88-117`; `LeaseController.php:608-619` |
| 15 | **e-sign has no single "edit a value after sending" facility; it has two kinds of edit, both reachable after send (R6):** (i) **text amends** — strike / reword of selected text, clause edits, added conditions — which keep the same envelope and document, collect an initial from every party per change, and record `pending_body_changes[]` (`old`, `new`, actor, time) in `web_template_data` plus `document_amendments` rows; allowed in `returned_to_candidate`, `amendment_review`, `amendment_chain_review` (agent) and on a signer's own turn; (ii) **raw field saves** — the agent's `saveAgentFields` / `saveAgentWebFields`, a signer's `saveFields` / `saveWebFields` / `completeWeb` — which overwrite `fields_json` / `web_template_data` in place with **no old/new record** (only a `fields_saved` / `web_fields_saved` audit row). The reject-and-revise action instead clones the document into a new `document_id` with no envelope. No Laravel event is dispatched by any of them. | `SignatureController.php:1154,1202,3630,3659,3708,4013,4074`; `SigningController.php:1424,1509,1786,4726,4845,4971`; `SelectionEditService.php:47,151-165`; `SignatureService.php:2829,3051,3416,3506,6512,6875`; routes `web.php:6247-6290,6321-6322,6556-6606` |

### 15.2 The design in one page

1. **One screen, one validator, one writer.** A single `corex/leases/capture.blade.php` serves `mode=new`, `mode=renew` and `mode=confirm` (§15.9); one `LeaseCaptureRequest` validates; one `LeaseCaptureService` writes (lease, tenants, agreement terms) in one transaction; one `LeaseSigningLauncher` builds the e-sign flow. The old create and renewal screens are retired (their routes redirect); the lease-hub dialogs for month-to-month and notices stay as they are.
2. **No CoreX lease. The agency's own, linked, with a field map (§15.12).** `rental_lease_templates` (the existing agency table) links an agency-owned e-sign template to the process and carries a `field_map` saying which of the template's fields hold rent, dates, names and the agreement terms. Until one is linked, button (b) is unavailable with a message that says where to set it up. A single guard refuses any template not owned by the acting agency.
3. **The agreement fields are stored on the lease, not only in the document.** `lease_agreement_terms` (one row per lease term) is what the screen reads for pre-fill, what renewal copies forward, and what the launcher feeds to the document (§15.7).
4. **A direct link replaces address guessing.** `flows.lease_id` is written at launch; when the agent prepares signing the lease receives `signature_template_id` and `document_id`; completion finds the lease by that id. The old address-and-tenant matching remains only for documents never launched from a lease.
5. **A separate signing state; `leases.status` untouched** (`draft → active → expired/cancelled`), so the overlap guard, archive guard (§3.7), property-status rules (§12.5) and every §17 screen behave as today. `signing_status` carries `not_sent / prepared / out_for_signing / awaiting_agent_review / signed`, or `declined / voided / expired`, or `signed_on_paper` (§15.5).
6. **Change detection by comparing end states, not by listening to edits (R6).** Whatever edit route was used, the printed values are read back through the field map and compared with the lease record; any difference puts the agent on the lease screen (`mode=confirm`) at the final approval, and the lease cannot be accepted until the two agree (§15.8–§15.9). Nothing is overwritten without the agent pressing confirm, row by row visible.
7. **The e-sign engine gets five small events, not lease-specific code** — `SignatureEnvelopeSent / Finalized / Declined / Cancelled / Expired` — and one Rentals listener; a read-time re-check plus a nightly command repair any missed event (§15.15).

### 15.3 The capture screen (`mode=new`, `mode=renew`; `mode=confirm` is §15.9)

Route `GET corex.leases.create` (new; accepts `?property_id=` and `?rental_application_id=`) and `GET corex.leases.renewal.create` (renew; `{lease}` = the lease being renewed) both render the same view. Layout follows the lease-edit screen (§6a.i): `.prop-input`/`.prop-label`, constant `grid-cols-2`, per-field `col-span-2 sm:col-span-1`, full width, one unified button row.

**Pre-fill precedence (every field):** (1) the value the agent just typed (failed submit, `old()`, as §7.3); (2) renew only — the previous term's `lease_agreement_terms` value; (3) renew only — the previous term's signed-document value, read through the field map (§15.7.3); (4) the lease column / contact / property value; (5) the agency default; (6) blank, **visibly marked "not on record"** rather than silently empty.

| Section | Field | Mode new | Mode renew | Needed for | Stored |
|---|---|---|---|---|---|
| Property | Property | the §7.2 type-to-search picker, unchanged (any rental status, `Property::scopeRentalVisibleTo`, `searchRentalProperties`), or fixed text when `?property_id=` | fixed, read-only | lease | `leases.property_id` |
| Parties | Landlord(s) — shown, not typed: derived from the property's landlord/lessor contacts (`landlordContacts()` only, never the sole-contact fallback). No landlord → link "Link landlord on the property" (new tab); signing is blocked | derived | derived | signing | property contacts (no landlord column on `leases`) |
| Parties | Tenant(s) — N-party picker, first = primary; each shows email and ID/passport status ("needs email", "needs ID number") with an inline "update contact" link | picker; from the rental application when given | **the previous term's tenants, shown read-only (R7). The panel says: "To change who the tenants are, start a new lease — a renewal keeps the same tenants." with the button "Start a new lease for this property" (§15.6.2)** | lease; signing needs email + ID/passport per signer | `lease_tenants` |
| Term | Start date | required | default = previous end date + 1 day (today if month-to-month) | lease | `leases.start_date` |
| Term | End date / Month-to-month (mutually exclusive — closes §15.22 #7) | optional | blank | lease | `end_date`, `is_month_to_month` |
| Term | Monthly rent | required | previous rent | lease | `leases.rental_amount` |
| Term | Deposit | blank; default = `LeaseSetting::defaultDepositMonthsFor` × rent when set | previous deposit | lease | `leases.deposit_amount` |
| Term | Lease type (only when `LeaseSetting::showLeaseTypeFieldFor`) | select | previous | lease | `leases.lease_type` |
| Term | Rental application (new only, hidden when none) | from `?rental_application_id` | — | lease | `leases.rental_application_id` |
| Agreement | **Only the agreement fields the linked lease agreement carries** (its `field_map`, §15.12.3): adults · maximum other persons · pets · yearly escalation % and month · earliest termination date · renewal option (months) · electricity/utilities arrangement · other conditions · anything else the map declares. A field the agency's lease does not carry is not shown. No lease agreement linked → this section is not shown (it exists for the agreement only) | prefilled from the rental application when linked | previous term | signing (per the map's "required" ticks) | `lease_agreement_terms` (+ `extra` json) |
| Signing | Lease agreement (the agency's linked residential lease agreements; the default preselected) | picker (only when the agency has more than one) | same as the previous term when it was e-signed through CoreX | button (b) | `leases.agreement_template_id` |
| Activation | "Activate immediately" (button (a) only; the withdrawn-property confirm of §12.5.2 applies) | tick | tick (uses `activateRenewalTerm`) | lease | — |
| Paper copy | "I already have the signed copy — attach it" (file; §15.6.5) | **available (R2)** | available | button (a) variant | `documents` |

Screen rules: every field shows its label and unit, no explanatory prose (§6a.i screen-space rule); a **"Needed for signing" checklist panel** updates as the agent types and lists exactly what is still missing; button (b) stays enabled once an agreement is linked — pressing it with gaps re-renders the same screen with the list (L3), so a lazy-but-valid submit never dead-ends (BUILD_STANDARD §2/§3). Input space: trimmed strings, dates validated for order (`end > start`, `earliest termination ≥ start`), rent ≥ 0, escalation 0–100, a contact deleted after the screen loaded is a clean validation error (§4).

### 15.4 The two buttons

**Names (R1).** New lease: **(a) "Create lease only"** · **(b) "Create lease & prepare for signing"**. Renewal: "Renew lease only" · "Renew lease & prepare for signing". Pressing (b) sends nothing to anyone; it opens the finished agreement for the agent to check and sign first.

**(b) availability (R3).** When the agency has no active, valid linked lease agreement, (b) is rendered **disabled** (never hidden) with this text directly beneath it: *"Your agency has not set up a lease agreement yet. An administrator sets one up under Settings → Rental lease agreements."* — with the words "Settings → Rental lease agreements" as a link for a user holding `rental_lease_templates.manage_settings`, and "Ask your agency administrator" for everyone else. Button (a), the paper copy and everything else work. The server enforces the same rule (the API returns `409 {code:"no_lease_agreement_linked"}`), so a forged POST cannot bypass it. **This is HFC's real starting state too (F1):** until Johan has created HFC's templates on live and linked one on Settings → Rental lease agreements, HFC sees the same disabled button and message as any new agency; (a) and the paper copy are what HFC uses meanwhile.

**(a) Create lease only** — in one transaction: `Lease` + `LeaseTenant` rows + `lease_agreement_terms` (when the section was shown); `LeaseEvent lease_created`; optional immediate activation (new → `LeaseActivationService::activate`, renew → `LeaseRenewalService::activateRenewalTerm`, failures caught and shown, never leaving a half-created lease — closes §15.22 #1). No document. Redirect to the Lease Hub. Idempotent on a hidden `capture_key` (double-click or back-and-resubmit returns the same lease).

**(b) Create lease & prepare for signing** — the same transaction as (a) minus activation, then:
1. **Gate** — `LeaseSigningLauncher::missing()` resolves the pre-filled data through `WebTemplateDataService::resolve()` (the call `RenewalDraftService::missingRequiredFields` uses), extended to the field map's required list and the signer gates (landlord present; every signer has email + ID/passport). Anything missing → **nothing is created**, the screen re-renders with the list and the agent's input intact; contact gaps link straight to the contact.
2. **Template guard** — `LeaseAgreementTemplateGuard::assertUsable($template, $agencyId)` (§15.12.4). Failing it is the "no lease agreement linked" case above, not a 500.
3. **Create** — lease as in (a) with `signing_status = prepared`, `status = draft`.
4. **Launch** — `LeaseSigningLauncher::launch()` inserts the `flows` row exactly like `RenewalDraftService::buildDraftFlow` (extracted into the one shared class so new and renewal use the same builder), plus `flows.lease_id`; **recipients in the one fixed order — agent, then the tenant(s) in primary order, then the landlord(s) (R4)**, each with `role` and `_contact_id`; `fill_review.fieldValues` seeded from the lease and the agreement terms through the field map.
5. **Land** — redirect to `docuperfect.esign.step` at **step 5 (Fill & review)**; the agent verifies the pre-filled document. *Build-time check:* confirm in a real browser that steps 2–4 need no re-save when the flow is created at `current_step = 5`; if they do, land on step 3 (recipients). Nothing the agent already typed is asked again.
6. The agent signs in-app. When `prepareSigning` writes `step_data.signature_template_id` (`ESignWizardController.php:3813-3819`), a one-line additive hook copies `signature_template_id` and `document_id` to the lease and sets `signing_status = out_for_signing`; the existing `advanceToNextParty` mails the first tenant, then the next, then the landlord.
7. **Completion** — §15.5 and §15.9.

The agent can leave and return at any point: the Lease Hub "Agreement" card (§15.13) shows the state and the "Continue" link.

### 15.5 Status path

`leases.status` (unchanged values) and `leases.signing_status`:

| signing_status | Meaning | Set by | `leases.status` | Property |
|---|---|---|---|---|
| `not_sent` | no agreement requested (button (a), or no lease agreement linked) | capture | draft or active | per §12.5 |
| `signed_on_paper` | a signed paper copy was attached on the capture screen (R2 / §15.6.5) | capture | active | per §12.5 |
| `prepared` | lease exists, flow created, agent has not yet prepared signing | launcher | draft | unchanged |
| `out_for_signing` | envelope exists (agent signing, or waiting on a tenant/landlord, or the document is being amended in e-sign) | `SignatureEnvelopeSent` listener | draft | unchanged |
| `awaiting_agent_review` | all recipients signed; the agent's final approval (AT-322) is pending, or the engine is in a returned/amendment state | listener on the envelope status | draft | unchanged |
| `signed` | agent approved, envelope completed and finalised | `SignatureEnvelopeFinalized` listener | **active** (same transaction) | flips to leased-out per §12.5.1 |
| `declined` | a signer declined or the agent rejected it | `SignatureEnvelopeDeclined` | draft | unchanged |
| `voided` | cancelled in e-sign (the engine's own cancel) | `SignatureEnvelopeCancelled` | draft | unchanged |
| `expired` | signing links lapsed | `SignatureEnvelopeExpired` | draft | unchanged |

Mapping from the engine's `SignatureTemplate` statuses: draft/ready/signing/awaiting_* /partial/deferred → `out_for_signing`; pending_agent_approval, returned_to_candidate, amendment_review, amendment_initialing, amendment_chain_review, editor_reacceptance → `awaiting_agent_review`; completed (+ finalisation done) → `signed`; declined, rejected → `declined`; cancelled → `voided`; expired, lapsed, re_lapsed → `expired`. The mapping lives in one method (`Lease::signingStatusFor(SignatureTemplate)`) used by the listener and the safety-net reader (§15.15).

**On `signed`** (one transaction, idempotent on `signed_at`) — and only when the document-versus-lease check of §15.9 has no unresolved difference: `signed_at = envelope completed_at`; **`accepted_at` / `accepted_by_user_id` = the agent's final approval (R5)** — the time and user of the approve action, which is also when any differences were confirmed; `source = 'esign_document'`, `source_document_id`; the previous term is expired and chained and the escalation recorded by `LeaseRenewalService::activateRenewalTerm` when `previous_lease_id` is set, otherwise `LeaseActivationService::activate`; the harvest of §15.7.2 runs; the signed copy is linked (§15.11); `LeaseEvent`s `agreement_signed`, `agreement_accepted`, `lease_activated_by_signing`; the agent is notified in-app and by mail. **From/to dates are the captured (or confirmed) start/end** — a signed lease is `active` the moment it is accepted, even before the start date (§3.7).
**If the check finds an unresolved difference** (an approval that bypassed the confirm step — wet-ink signing, an unattended completion, or an engine path that never touches the route): `signing_status = signed`, `status` stays `draft`, `LeaseEvent agreement_needs_confirmation`; the hub's next step reads "Signed — confirm the lease details" and opens the §15.9 screen with the button "Confirm and activate". The lease can never become active while it disagrees with its own agreement.
If activation is refused because another lease is already active on the property: `signing_status = signed` stays, `status` stays `draft`, `LeaseEvent signed_not_activated`, the next step says "Signed — another lease is still active on this property. End or renew it, then activate." Nothing is lost (the existing absorb-and-warn at `SignatureService.php:5596-5605`, made visible).
The hub's lifecycle node "lease signed" becomes `signed_at IS NOT NULL OR signing_status = signed_on_paper OR (source ≠ esign_document AND status ≠ draft)` — closing §15.22 #12.

### 15.6 Renewal, new tenants, paper copies

1. **Same screen, same two buttons** (L4). Entry points unchanged: Lease Hub "Renew lease" dialog (`show.blade.php:160-185`) → "Continue to renewal", Command Centre `?action=renew`, `LeaseHubService::nextStep()`. The "Renew or end tenancy" page (`renewal.blade.php`) is replaced; its outcome cards (month-to-month, tenant notice, landlord not renewing) already exist as Lease Hub dialogs (§12.11) and stay there. Old POST routes `renewal.draft`, `renewal.draft-from-template`, `renewal.upload` remain for the API mirror (`api/v1/leases/{lease}/renewal/*`, rule #7) and call the new services.
2. **Tenants cannot change at renewal (R7).** The renew screen shows the tenants of the lease being renewed as read-only text. Directly under them, a panel reads: *"A renewal keeps the same tenants. If a tenant is leaving or a new tenant is joining, that is a new lease, not a renewal."* with the button **"Start a new lease for this property"**, which opens `corex.leases.create?property_id=<this property>` — the New Lease screen with the property already fixed (the landlord is derived from the property; nothing else is carried over). Beneath it, one plain line: *"The current lease must be ended before the new one can be made active."* (the overlap rule of §3.5 — the agent is told on the screen, not at the moment of activation). The renew form's POST ignores any tenant field; the server rebuilds the tenant list from the previous term, so a forged field changes nothing.
3. **Wet-ink first lease (L5).** The previous term has no `source_document_id` and, unless the agent typed them when capturing it, an empty `lease_agreement_terms` row. The renew screen still offers both buttons. It pre-fills what the system lease holds — property, landlord(s), tenant(s), rent, deposit, dates, lease type, escalation history if any — and shows the rest **blank and marked "not on record — fill in"**: adults, maximum other persons, pets, escalation % and month, earliest termination date, renewal option, electricity arrangement, other conditions, and the tenant's residential address when the contact has none — each only if the linked lease agreement carries that field; plus any missing email/ID. Only the ones the agreement's map marks required block button (b). Nothing is guessed from the paper lease; the uploaded PDF stays the legal record of the old term.
4. **Previous term e-signed through CoreX (L4):** the reader pulls the values from the previous document (§15.7.3), so the agent sees the occupants, pets, escalation and conditions of the last agreement already filled in and changes only what changed. Dates shift to the new term (earliest termination moves by the same offset); rent is the previous rent until the agent types the new one. If the agency has since linked a different lease agreement whose map differs, only the fields that exist in both carry over; the rest show "not on record".
5. **Signed paper copy — renewal and new lease (R2).** The secondary link of button (a), "I already have the signed copy — attach it", is on **both** modes. A file is required (`documentUploadRule(20480)` as today). In one transaction: lease + tenants + terms; the PDF filed `source_type='lease'`, `source_id=<lease id>`, document type `lease_agreement`, attached to the property; `DocumentUploaded` fired; then activation (renew → `activateRenewalTerm`; new → `LeaseActivationService::activate`, with the §12.5.2 withdrawn-property confirm and the §3.5 overlap guard — a refusal rolls everything back and shows the blocking lease). `signing_status = signed_on_paper`, `source = 'uploaded_signed_copy'`. Works with no lease agreement linked.

### 15.7 Where the agreement values are stored — and how a later renewal pre-fills them (L6)

**15.7.1 Field registry and per-agency map.** `config/lease-agreement-fields.php` is the single list of KNOWN agreement fields (key, label, type, group, comparison mode, "can be accepted from the document" flag — §15.8). It is the vocabulary; the **mapping from that vocabulary to a particular lease agreement's own field names is stored per agency, per linked agreement**, in `rental_lease_templates.field_map` (§15.10, §15.12.3). The capture screen, the missing-list, the launcher's seeding, the harvest, the difference check and the renewal reader all go through registry + map — so a new agency's lease needs a map, not code.

**15.7.2 Primary store: `lease_agreement_terms`** — one row per lease term, typed columns for the common fields, `extra` JSON for template-specific ones, `source` = `captured | carried_forward | esign_harvest | confirmed`. Written when the agent captures the lease, and **re-written at completion by the harvest** `LeaseAgreementHarvest::fromDocument($lease, $document)`: reads the printed values through `LeaseAgreementValuesReader` (§15.8.2), parses display strings ("R6 940", "13 October 2026") into typed values, and updates the row. So whatever was corrected in Fill & review — or changed later in e-sign and confirmed by the agent — reaches the next renewal.

**15.7.3 Lazy fallback for leases e-signed before this build:** `PreviousTermValuesReader::for($lease)` returns the term's `lease_agreement_terms` if present; otherwise, if `source_document_id` is set, harvests from that document using the map of the template that produced it (found through `agreement_template_id`, else through the document's own template id) and **writes the row** (`source = esign_harvest`). When no map is known (a document made from a template the agency never mapped) the reader returns nothing and every agreement field shows "not on record" — it never guesses names. No search through `flows` JSON (no index).

**15.7.4 What stays where:** the printed, sealed document (`docuperfect_documents` + `document_sealed_versions` + the filed PDF) is the legal record of the agreement; `lease_agreement_terms` is the structured, queryable copy; `leases` columns remain the source for rent, dates and deposit. The document never silently overwrites the lease — the only way a document value reaches the lease record is the agent's explicit confirmation in §15.9, which logs old and new.

### 15.8 The field map, and how changes made in e-sign are detected (R6)

**15.8.1 Why end-state comparison.** e-sign's existing edit facility (§15.1 #15) has no event, no old/new record for field saves, and several entry points — text amends (agent and signer), the agent's field saves, the signer's field saves, completion's `field_values` merge. A listener on each would miss the next one added. So CoreX does **not** try to catch edits as they happen. At every moment that matters it **reads the document's printed values back through the field map and compares them with the lease record**. That catches every route — present and future — and needs no change to the pipeline-gated files (`SigningController.php`, `Template.php`, … are not touched).

**15.8.2 The reader.** `LeaseAgreementValuesReader::read(Document $document, array $fieldMap): array` returns `{key → {printed, raw, source, struck}}`. For each mapped key, in this precedence — (1) the `data-field` span text in the stored `canonical_html` (the printed truth; for a struck-and-reworded span, the `<ins>` text, never the `<del>`), (2) `web_template_data._fill_review_overlay[field]`, (3) `web_template_data.field_values[field]`, (4) flat `web_template_data[field]`, (5) the `fields_json` entry whose `field_name` matches (a list, searched by name). Unparseable → `printed` is kept, `parsed = null`. Parsing: money = strip every non-digit/non-decimal character (the "R" is CSS, nbsp, spaces and commas are removed) and compare to the cent; dates = `Carbon::parse` of either `Y-m-d` or `j F Y`; percentages = bare number; names and text = trimmed, whitespace-collapsed. *Build-time check:* confirm on a real `canonical_html` of each agency template type which form dates print in.

**15.8.3 The field map (registry key → lease side → comparison → what a difference allows).**

| Registry key | Compared with | Comparison | If different |
|---|---|---|---|
| `rent` | `leases.rental_amount` | money, to the cent | **acceptable** — confirm writes `rental_amount` |
| `start_date` | `leases.start_date` | date | acceptable |
| `end_date` | `leases.end_date` / `is_month_to_month` | date | acceptable when the document prints a date; a **blank** end date against a lease that has one is "cannot verify" |
| `deposit` | `leases.deposit_amount` | money | acceptable — **only when the agency's map carries it** (some leases print the deposit as a clause, not a field; an unmapped key is not compared and the confirm screen says "not printed in this agreement") |
| `tenant_name_1…n`, `landlord_name_1…n` | the lease's tenant contacts / the property's landlord contacts (by order) | case/whitespace/punctuation-insensitive text | **NOT acceptable** — a different person is a different lease (R7). The agent corrects the contact record and presses Re-check, or returns/cancels the agreement and starts a new lease |
| `adults`, `max_other_persons`, `pets`, `escalation_percent`, `escalation_month`, `earliest_termination_date`, `renewal_option_months`, `electricity_arrangement`, `other_conditions`, anything in `extra` | `lease_agreement_terms` | by type (number, date, text) | acceptable — confirm writes the term (`source = confirmed`) |
| `landlord_address`, `landlord_id`, `tenant_address`, `tenant_id` (contact keys) | the contact record | text, whitespace/case-insensitive | **NOT acceptable** — same rule as names: correct the contact and Re-check (§15.12.5) |
| `property_description`, `rent_in_words`, `escalation_in_words`, `agent_service_fee`, `net_to_owner` (calculated) | recomputed from the figures being confirmed | text/money | informational "calculated value differs"; no lease column to update (§15.12.5) |

Rule for a printed value that cannot be read ("cannot verify": empty where the lease has a value, or text where a number belongs): it is listed as a difference of that kind, the agent **types the value they see in the document** on the confirm screen, and the typed value is logged as "entered by <agent> while confirming". Never accepted silently, never blocking on something the agent can plainly read.

**Text changes** (strike / reword / added condition — `pending_body_changes[]`, `document_conditions`, `document_amendments`) are free text, not fields, and cannot be compared. Every accepted text change is **listed (old → new, who, when) on the confirm screen, read-only**, so the agent sees them next to the value differences. The engine's own gate (`outstandingChangeInitials`, `SignatureController.php:3328`) already forces every party to initial each of them; CoreX adds visibility, not a second gate. A lease-relevant value changed only inside clause text (no field) is therefore the agent's to read and, if it matters, to type into the lease — the screen says so in one line.

**15.8.4 When the comparison runs.**
1. **At the final approval** — a route middleware `EnsureLeaseAgreementConfirmed` on `docuperfect.signatures.approveAndAdvance` (`web.php:6267`) — §15.9.
2. **At completion, on every path** — the `SignatureEnvelopeFinalized` listener (§15.5) re-runs `LeaseAgreementCheck::verdict($lease)`; this is the net under approval paths that never touch the route.
3. **On read** — the Lease Hub, the Leases list row and the Command Centre call `Lease::reconcileSigning()` for an in-flight lease; when it first sees a difference it writes one `LeaseEvent agreement_edited` ("The agreement was changed in e-sign: rent R6 500 → R6 940") and the hub's next step becomes "Agreement changed — review before approving". Detection is by comparison, so this fires whichever edit route was used. It is information; it blocks nothing.

**15.8.5 Fingerprint.** A confirmation is valid only for the exact printed values it confirmed: `agreement_confirmed_fingerprint` = sha256 of the read result's `{key → printed}` map. Any later edit changes the fingerprint and the check runs again. Re-pressing approve on an unchanged document passes straight through (idempotent).

### 15.9 What the agent sees when the document was changed (R6)

**No difference → no extra screen.** The agent presses the engine's own approve button and the lease is accepted (§15.5). The comparison costs nothing visible.

**Difference found → the lease screen, in `mode=confirm`.** `EnsureLeaseAgreementConfirmed` redirects the approve request to `GET corex.leases.agreement.confirm {lease}` — the same `capture.blade.php`, opened on the lease's own record:
- **Heading line:** "The agreement was changed in e-sign. Check each highlighted value, then confirm." — nothing else explanatory.
- **Every field of the capture screen is shown, filled with the DOCUMENT's printed value.** Each field that differs is highlighted and carries the lease's current value beside it: **"Lease says R6 500 · Agreement says R6 940"**. Fields that agree are shown plainly, read-only.
- **Tenants and landlord(s):** shown read-only with the name printed in the document next to each. A name difference shows the red line "The agreement names a different person. A change of tenant is a new lease." with two links — "Correct the contact" (new tab, then **Re-check**) and "Back to the agreement" (where the engine's own return/cancel lives). The confirm button stays disabled while a name difference is open.
- **Cannot-verify fields** (§15.8.3) are editable inputs marked "type what the agreement says".
- **Text changes** (§15.8.3) in a read-only list below the fields.
- **One button: "Confirm lease details and approve".** Beside it a link "Back to the agreement" — leaving changes nothing; the lease record is untouched until the button is pressed. Rejecting the document is done where it already lives (the engine's "Return to signer with notes").

**What the button does (one transaction).** It posts to the engine's own approve route carrying the confirmed values and the fingerprint. The middleware, seeing a valid confirmation payload, (1) re-reads the document and refuses if its fingerprint no longer matches (someone edited meanwhile → the screen reopens with the new differences), (2) writes the accepted values to `leases` and `lease_agreement_terms`, (3) writes **one `LeaseEvent agreement_differences_confirmed` per changed field** with old value, new value, who and when (`metadata`), plus `agreement_confirmed_fingerprint` / `_at` / `_by_user_id` on the lease, then (4) lets the request continue into the engine's unchanged approve action. If the engine then refuses (e.g. an outstanding change initial), the confirmation stands and the engine's own message is shown; pressing approve again passes straight through.

**Nothing is silently overwritten — the four guarantees.** (1) The lease record changes only on that button; (2) every changed value is visible beside the old one before the button; (3) every changed value is logged old → new with the agent's name; (4) a difference that cannot be accepted (a different person) blocks instead of being absorbed. The document is never changed by CoreX at all.

**Same screen after completion.** For the net of §15.5 (signed, unresolved difference) the same screen opens from the hub next step with the button "Confirm and activate" — it posts to `corex.leases.agreement.confirm`, writes the same rows, then activates.

**Permissions.** Opening or confirming needs the user who holds the approval (the envelope's agent) or `leases.create`; the screen and the POST re-check own/branch/agency scope on the lease (§15.13).

### 15.10 Data model (migrations `2026_10_12_1000nn_…`, idempotent; then `schema:dump` + DEFINER strip, #12a — by the foundation build L1 only)

| Mig | Change |
|---|---|
| M1 | `lease_agreement_terms`: `id`, `agency_id` (BelongsToAgency), `lease_id` (FK, unique), `adults` smallint null, `max_other_persons` smallint null, `pets` string(255) null, `escalation_percent` decimal(5,2) null, `no_escalation` bool default false, `escalation_month` tinyint null, `earliest_termination_date` date null, `renewal_option_months` smallint null, `electricity_arrangement` string(500) null, `other_conditions` text null, `extra` json null (template-specific values, incl. the schedule's service-fee %/amount, other deduction and net to owner — §15.12.5), `source` string(30) default 'captured', timestamps, softDeletes; index (agency_id, lease_id). |
| M2 | `leases` additions (all nullable): `signing_status` string(30) default `not_sent`; `signing_flow_id` unsignedBigInteger; `signature_template_id` unsignedBigInteger (indexed); `agreement_document_id` unsignedBigInteger (the `docuperfect_documents` id, kept separate from `source_document_id` so a lease can show the in-flight document before completion); `agreement_template_id` unsignedBigInteger (→ `docuperfect_templates.id`, no FK, convention); `signed_at`, `accepted_at` timestamps; `accepted_by_user_id` FK users nullOnDelete; `agreement_confirmed_fingerprint` string(64); `agreement_confirmed_at` timestamp; `agreement_confirmed_by_user_id` FK users nullOnDelete; `capture_key` string(64) unique; `signing_failure_note` string(500). Index `(agency_id, signing_status)`. **`renewal_draft_flow_id` is kept and mirrored** (written alongside `signing_flow_id` for renewals); all readers switch to `signing_flow_id`. |
| M3 | `flows.lease_id` unsignedBigInteger null, indexed (`Flow::$fillable` gains `lease_id`). |
| M4 | `rental_lease_templates` additions: `field_map` json null (§15.7.1, §15.12.3); `is_default` bool default false (one default per agency + category, enforced in the service inside a transaction); `validated_at` timestamp null and `validation_problems` json null (the result of the last `LeaseAgreementTemplateGuard` check, shown on the set-up page). |
| M5 (data) | Back-fill, idempotent, DML-only: `signing_flow_id = renewal_draft_flow_id`; `signing_status = prepared/out_for_signing` from the flow's `step_data.signature_template_id` and the envelope's status; for `source='esign_document'` leases with a `source_document_id`: `signing_status = signed`, `signature_template_id`, `agreement_document_id`, `signed_at = accepted_at = envelope completed_at` where the envelope can be found; leases with a paper-renewal document (`documents.source_type='lease'`) → `signed_on_paper`; everything else `not_sent`. |

**No new `lease_settings` columns** — R3/R4 removed every setting the first draft had (signing order, agency-signs, minimum term, clause figures). Model changes: `Lease` (fillable, casts, constants `SIGNING_*`, `agreementTerms()`, `signedDocument()`, `isLockedForSigning()`, `signingStatusFor()`), new `LeaseAgreementTerms`, `RentalLeaseTemplate` (fillable, casts, `isUsableBy()`), `Flow` fillable. `LeaseEvent` gains the types of §15.15.

### 15.11 Filing the signed copy

No second copy is made. The cascade already files the signed PDF as a `documents` row (`source_type='esign'`, `source_id = signature_template_id`), attached to the property and each signer's contact (`SignatureService.php:4369-4490`). The lease finds it through `Lease::signedDocument()` = that row via `leases.signature_template_id`, falling back to `Document` `source_type='lease'`, `source_id = lease id` (the paper copy) — one accessor, both worlds. Shown on the Lease Hub "Agreement" card (view / download client copy / download audit certificate) and listed in the property's documents as today. The wizard document name is set at launch to `"<Lease agreement> – <property address> – <tenant name(s)> – <start date>"` so it is findable. Document type: the existing `lease_agreement` slug.

### 15.12 Each agency's own lease agreement — set-up, link, and HFC isolation (R3)

**15.12.1 What an agency has to do (the "set-up").** (1) Get its lease into CoreX as an e-sign template — with the tools that already exist for it (`docuperfect.templates.upload`, the CDS builder `docuperfect.cds.builder`, the document importer `docuperfect.import.*`; permission `manage_templates`) — which stamps the template with the agency (`TemplateController.php:128-136`). (2) Open **Settings → Rental lease agreements** (existing page `corex.rental-lease-templates.index`, nav entry `settings.blade.php:114-115`, permission `rental_lease_templates.manage_settings`), add a row, choose the template, the category (residential for this process) and press **Check and link**. (3) Map its fields (§15.12.3). Nothing else. CoreX supplies no lease document of its own.

**15.12.2 The page.** The existing CRUD page gains: the template picker lists **only the acting agency's own active e-sign templates** (`agency_id` equals the acting agency, `is_esign`, not archived — never `applySharedWith`, which closes the old "only global templates" defect, §15.22 #20); a "Make default" tick (`is_default`); a status chip per row — *Ready* (valid + mapped), *Needs field map*, *Not usable: <reason>* — from the last guard result; archive and restore as today.

**15.12.3 The field map.** Beneath each row, a "Map fields" panel: one line per registry key (§15.8.3), a dropdown of the template's own field names (`data-field` names for a web template; named fields from `fields_json` for a CDS/PDF template), a "required for signing" tick for the agreement-term fields, and a suggested match (by name similarity; **suggestions are never saved without the admin pressing Save**). Unmapped keys are simply not carried: the capture screen does not ask for them, the launcher does not seed them and the difference check does not compare them. To be **linkable**, the map must at least carry `rent`, `start_date`, one tenant name and one landlord name, and `end_date` (or the template must be month-to-month capable) — otherwise the row saves as *Needs field map* and button (b) stays unavailable. Stored in `rental_lease_templates.field_map`.

**15.12.4 Enforcement — the HFC template (and every other agency's) cannot reach another agency.** One class, `App\Services\Rentals\LeaseAgreementTemplateGuard`, with `problemsFor(Template, int $agencyId): array` and `assertUsable(...)`. It is called at **four** points, so no single path can be bypassed: (1) the set-up page picker query; (2) `RentalLeaseTemplateController::store`/`update` — which today validates only `exists:docuperfect_templates,id` and `assertAccessibleBy` and would accept a shared template (`:75-97`); (3) the capture screen's gate for button (b), `LeaseSigningLauncher::missing()`; (4) `LeaseSigningLauncher::launch()` immediately before the flow row is inserted (server-side, even for the API). The guard refuses unless **all** hold: the template's `agency_id` is not null and **equals the acting agency**; `is_esign`; not archived; its `field_map` carries the required keys; it has signing places for the three roles agent, tenant and landlord (read-only use of the existing role-block detection — no edit to those gated files). **A template with no owning agency — which includes the built-in HFC V8 lease, seeded `agency_id = NULL` + `is_global = true` — is refused for the lease process for every agency**, including HFC; `agency_id = null` is the leaking value, so refusing it is the whole point. Direct-URL/POST access with another agency's template id gets the same refusal as a non-existent id (404/validation message, no hint that it exists).
**How HFC keeps its own lease:** HFC's lease must be an agency-owned template (`agency_id` = HFC) before it can be linked. Per F1 Johan creates HFC's web-pack templates on live himself on 7 Oct — through the existing upload/CDS-builder/importer, which stamps the acting agency (`TemplateController.php:128-136`) — so no fix-up is expected; if a template ends up with no owner, the one-time artisan command `templates:assign-agency {template_id} {agency_id}` (written in Build L0: idempotent, prints before/after, writes an audit row, refuses a template already owned by another agency) repairs it — run on QA1/Staging by the conductor and, **only on Johan's explicit order for that exact action**, on live. No agency id is written into code. The existing `TemplateController::copy()` (`:443-455`) uses `replicate()`, so a copy of a shared template stays shared with no agency — it cannot be HFC's route (reported, §15.22 #26). *Pre-requisites before L0 is accepted (conductor, with Johan):* the live template's field names are checked against the reference map of §15.12.5, and the live template carries a signing place for the tenant (the repo's `template-121` does not).
**Defence in depth:** `WebTemplateSeeder` stays as is (HFC-worded web templates are HFC's asset — changing what the generic e-sign wizard shows is outside this spec, reported §15.22 #27), but nothing in the lease process can pick them up. A test scans every file this build creates (PHP, Blade, config, mail views) for "Home Finders" and "HFC" and fails if any appears.

**15.12.5 Reference field map for HFC's lease (Johan, 6 Oct 14:32; checked against the repo).** This is the worked example of §15.12.3 — what an agency's field map looks like for a real lease — and the test fixture for L0/L2/L3a/L3c. It is **HFC's** map: it lives in HFC's `rental_lease_templates.field_map` (data), never in code.
*Source of the list.* Johan's reading of the live document ("Lease_Agreement_POPI_V8_CDS", residential): the fill-in places in document order. The repo holds no copy of that document, but three CDS snapshots of a residential lease are in it (`resources/views/docuperfect/web-templates/cds/template-121.blade.php`, `-122`, `-75`; the ids are another environment's). **`template-121` has exactly these places, in this order** — 24 fill-in spots (23 distinct field names: `monthly_rental` is used twice) — so the "CDS field name" column below is read from it. *Johan's brief says 22 placeholders; his list as relayed names 24 places and the snapshot has 24; the mismatch is flagged for him, not resolved here.* The two "free-standing blocks" are **the pets block** (the clause permitting "the following type and number of pets") **and the Other Conditions block** (clause 23, above the landlord's signature line) — there is no annexure field. Whether the live template carries `template-121`'s names, `-122`'s or `-75`'s is **not knowable from the repo**; the conductor/Johan check the live template's field names against this table before L0 is accepted (§15.21 L0).

| # | Where in the document | Fill-in | CDS field (`template-121`) | Built-in V8 web template (`lease-agreement-popi-v8.blade.php`) | Registry key | Where the value comes from |
|---|---|---|---|---|---|---|
| 1 | 1.1 Landlord | name | `lessor_full` | `lessor_name` (+ `lessor_name_2`) | `landlord_name` | **Contact** — the property's landlord contact(s) (`Lease::landlordContacts()`), `Contact::full_name`; several landlords print "A and B" in order |
| 2 | | address | `lessor_address` | `lessor_address` | `landlord_address` | **Contact** — `contacts.address` (`Contact.php:46`). Missing → "needed for signing: landlord address" with the contact link |
| 3 | | ID / passport / registration no | `lessor_id_number` | `lessor_id` | `landlord_id` | **Contact** — `id_number`, or `passport_number` when `id_type` is passport. A company's registration number has no contact column that I found — *build-time check where companies keep it*; missing → blocks signing |
| 4 | 1.2 Tenant | name | `lessee_name_id` (a name + ID group field — the importer's identity-group binding, `CdsBindingSuggester.php`) | `lessee_name` (+ `lessee_name_2`) | `tenant_name` | **Contact** — `lease_tenants` in primary order, "A and B" |
| 5 | | address | `lessee_address` | `lessee_address` | `tenant_address` | **Contact** — `contacts.address` |
| 6 | | ID / passport / registration no | `lessee_id_number` | `lessee_id` | `tenant_id` | **Contact** — as #3 |
| 7 | Property | description / address | `property_full` | four fields: `street_address`, `unit_no`, `complex_name`, `erf_no` | `property_description` | **Property**, calculated: unit, complex, street number and name, suburb, town (and erf when the template asks) composed by one shared function. Today's resolver prints only "street, suburb" (`WebTemplateDataService.php:274`), so unit and complex are missing — fixed by the composer |
| 8 | 3.2 | number of adults | `occupants` | `adults` (resolver hard-blanks it, `:220`) | `adults` | **Capture screen** (agreement terms; number) |
| 9 | | maximum other persons | `kids` | `other_persons` (hard-blanked, `:222`) | `max_other_persons` | **Capture screen** (number). The first draft's free-text `other_occupants` becomes this numeric field |
| 10 | 4.1 | monthly rental, figures | `monthly_rental` | `rental_amount` | `rent` | **Lease record** — `leases.rental_amount` |
| 11 | | rental in words | `price_in_words` | `rental_in_words` | `rent_in_words` | **Calculated** from the rent (`numberToWords`, `WebTemplateDataService.php:305`). **Trap:** the resolver blanks `price_in_words` on any non-sales document (`:315`), so this CDS lease would print the words blank; and the V8 blade's `rental_in_words` is never emitted (resolver emits `rental_amount_words`). The launcher therefore seeds the words itself through the map, and the difference check recomputes them |
| 12 | 4.2 | escalation %, figures | `escalation` | `escalation_percent` | `escalation_percent` | **Capture screen** (agreement terms) |
| 13 | | escalation %, words | `escalation_alpha` | `escalation_in_words` (hard-blanked, `:324`) | `escalation_in_words` | **Calculated** from the escalation % (decimals read out, "seven point five") |
| 14 | | escalation month ("from the 1st day of …") | `escalation_month` | `escalation_month` | `escalation_month` | **Capture screen** (month list). Pre-filled with the month of the start date; the agent can change it |
| 15 | 5.1 | commencement date | `property_lease_start_date` | `lease_start` | `start_date` | **Lease record** — `leases.start_date` |
| 16 | 5.1.1 | earliest date notice may expire | `notice_date` (one date) | `min_term_day` / `min_term_month` / `min_term_year` (three fields, hard-blanked, `:327-329`) | `earliest_termination_date` | **Capture screen** (agreement terms; date). No default — blank and marked "not on record" |
| 17 | 5.2 | expiry date | `property_lease_end_date` | `lease_end` | `end_date` | **Lease record** — `leases.end_date` (blank on a month-to-month lease) |
| 18 | | renewal period, months | `renewal_period` | `renewal_months` (hard-blanked, `:330`) | `renewal_option_months` | **Capture screen** (number) |
| 19 | Pets clause | type and number of pets | `what_pets_are_allowed` | `pets_1` / `pets_2` (two lines, hard-blanked, `:224-225`) | `pets` | **Capture screen** (agreement terms; text, may stay blank) |
| 20 | 23 Other conditions | free text | `other_conditions` | no field — a block of blank lines handled by the renderer's other-conditions marker | `other_conditions` | **Capture screen** (agreement terms; multi-line). *Build-time check:* whether the live template uses this plain field or the engine's own other-conditions block (`document_conditions`), then seed the one it uses |
| 21 | Schedule | total rental amount | `monthly_rental` (second use) | `total_rental` | `rent` | **Lease record** — the same value as #10, printed twice, asked once |
| 22 | | less agent's service fee (incl. VAT) | `agent_fee_in_rands` | `service_fee` | `agent_service_fee` | **Calculated**: rent × the property's letting commission % (`properties.commission_percent`, `Property.php:793`) plus VAT **per the agency's own VAT set-up** (VAT-registered or not, and the rate the agency uses — as `RentalJobCardVatService` does), **not** the fixed 15 % of `WebTemplateDataService.php:139-140`. The commission % is shown on the capture screen when the map carries this key |
| 23 | | Let's Assist fee | `lets_assists_fee` | `lets_assist` | `other_deduction` | **Capture screen** (an amount; blank = R 0.00). The words "Let's Assist" exist only as the label in HFC's own map |
| 24 | | net amount to owner | `owner_nett` | `net_to_owner` | `net_to_owner` | **Calculated**: rent − service fee − other deduction (the formula already at `WebTemplateDataService.php:141-142`) |

*Totals:* from the **lease record** 4 (#10, 15, 17, 21); from a **contact** 6 (#1–6); from the **property**, calculated, 1 (#7); **calculated** 4 more (#11, 13, 22, 24); **must be on the capture screen** 9 (#8, 9, 12, 14, 16, 18, 19, 20, 23) plus the commission % shown beside #22. Registry keys added by this table to §15.8.3: `landlord_address`, `landlord_id`, `tenant_address`, `tenant_id`, `property_description`, `max_other_persons`, `rent_in_words`, `escalation_in_words`, `agent_service_fee`, `other_deduction`, `net_to_owner`.
*In the difference check (§15.8.3):* the contact keys (#1–6) compare with the contact record and are never acceptable from the document — a difference means "correct the contact, then Re-check, or it is a new lease"; the calculated keys (#7, 11, 13, 22, 24) are recomputed from the figures the agent confirms and shown as "calculated value differs" when the printed text disagrees (informational, no lease column to update); the schedule values live in `lease_agreement_terms.extra`.

*Differences between the live-style CDS lease and the built-in V8 web template (why the map is per template):* every one of the 24 places is named differently except `lessor_address`, `lessee_address` and `escalation_month`; the structure differs (property in one field vs four, earliest-notice date in one field vs three, pets in one vs two, V8 allows two names per party, the CDS snapshot one block per party; V8 carries electricity-settlement, cancellation, signing-date/time and addendum fields the CDS does not; neither prints a deposit field). Defects found in the repo copies that the guard and the set-up page will surface rather than hide: `template-121`'s signature block lists Lessor and Agent only — no tenant place, so §15.12.4's role check reports "Not usable: no signing place for the tenant"; `template-122` adds the tenant place but binds the tenant's name field to `lessor_full`, printing the landlord's name as the tenant; `template-75` is an older variant with different names (`lessor_name`, `permitted_pets`, `lets_assist_fee`, `net_amount_to_owner`, `renew_period`, …).

**Keeping the HFC-specific fees out of every other agency's lease.** Agent's service fee and Let's Assist are HFC's lease content, not CoreX's. (1) Nothing in code, config or the field registry names "Let's Assist" — the registry keys are neutral (`agent_service_fee`, `other_deduction`, `net_to_owner`) and the label shown on the screen and in the document comes from the agency's own map; (2) the capture screen shows the schedule inputs (commission %, the other deduction) **only when the agency's linked lease carries those keys** — an agency whose lease has no such schedule never sees them, and nothing is calculated, seeded or compared for it; (3) no agency gets a default for them (blank, never HFC's figure); (4) the §15.20 scan test fails the build if "Let's Assist" or "Home Finders"/"HFC" appears in anything the series creates.

### 15.13 Scoping, permissions, navigation, list-screen additions

- **Scoping (OWN/BRANCH/AGENCY, query layer):** the property picker and the property check in `store` use `Property::scopeRentalVisibleTo($user)` (§7.3); every lease read/write goes through `guardRentalRecordScope()` and the `Lease::visibleTo()` scope; `lease_agreement_terms` and the new events inherit `BelongsToAgency`; the lease-agreement picker is the agency's own `rental_lease_templates` rows only; the confirm screen and its POST re-check scope on the lease; direct-URL access to another agency's lease, flow, terms or confirm screen is a 404. The flow is created for the acting agent (`flows.user_id`); only that user can open it (`showStep` rule) — a different user who opens the lease sees the Agreement card with "Owned by <agent>". Capture creates the lease with `created_by_user_id` = the acting user.
- **Permissions (no new key):** mode new → `leases.create`; mode renew → `leases.renew`; button (b) additionally needs `access_docuperfect` and `create_docuperfect_docs` (hidden and server-checked otherwise, with the plain message "You do not have access to prepare agreements"); the confirm screen → the envelope's agent or `leases.create`; linking and mapping → `rental_lease_templates.manage_settings`.
- **Navigation:** no new sidebar entry. "New Lease" (Leases list, property Rental tab) and "Renew lease" (hub dialog, Command Centre, next-step card) open the one screen; "Start a new lease for this property" (renew screen) opens New Lease; the set-up page is the existing Settings entry. The Lease Hub gets an **Agreement card** (partial `corex/leases/_agreement-card.blade.php`, one `@include`): status chip, the linked lease agreement's name, each signer in signing order with their status and last reminder, buttons *Continue / Open agreement*, *Send reminder* (existing), *Review changes* (when §15.8.4 #3 found a difference), *View signed copy*. There is **no "Void and start again"** (R6): cancelling is e-sign's own cancel and shows here as `voided`; after `declined`, `voided` or `expired` the card offers **"Prepare again"**, which reopens the capture screen pre-filled from the lease and launches a new document linked to the same lease. The rental context bar chip shows the signing state when not `not_sent`.
- **Lease edit while the agreement is out:** the agreement-governed fields on the lease's own edit panel (`show.blade.php:505+`: end date, month-to-month, deposit, lease type) are read-only while `signing_status` is `out_for_signing` or `awaiting_agent_review`, with the text "This agreement is out for signing. Change values in the agreement — CoreX will ask you to confirm them at approval." (`Lease::isLockedForSigning()`, checked server-side in `update()`, `LeaseController.php:587-606`.) One place to change a value, so the lease and the document can never diverge from the lease side.
- **Leases list (BUILD_STANDARD §1b additions):** new filter "Agreement" (not sent / being prepared / out for signing / needs my approval / signed / signed on paper / declined / voided / expired); a status sub-label under the lease status badge; search by tenant/landlord/property as today; default sort unchanged (end date ascending); empty states unchanged. CSV/XLSX export gains "Agreement status" and "Signed on".
- **Lease Hub next-step card** gains: *Verify and sign the agreement* (prepared), *Waiting for <name>* (out for signing), *Agreement changed — review before approving* (§15.8.4 #3), *Approve the signed agreement* (awaiting agent review), *Signed — confirm the lease details* (§15.5), *Agreement declined by <name> — prepare again* (declined), *Signed — activate* (signed but not active).

### 15.14 Settings and the Setup Wizard (rule #10a)

R3 and R4 left **no new setting** in the lease code: signing order, who signs for the landlord, minimum term and clause figures are gone (the agency's own lease carries its own wording and numbers). The one thing an agency must configure is **its lease agreement link and field map** (§15.12), a Settings page, not a saver. To meet #10a the `leases` step of `config/agency-onboarding-copy.php` (step key at line 368) gains **one information row with a link**: explain — "The lease agreement CoreX opens when you prepare a lease for signing." affects — "What this changes: whether the 'Create lease & prepare for signing' button is available, and which of your own documents it produces. Until you set one up the button is greyed out." — linking to Settings → Rental lease agreements, with a done/not-done chip read from `rental_lease_templates` (`currentValues()` arm, `AgencySetupWizardController.php:605`). It has no saver, so there is nothing for a partial-step post to wipe (spec `agency-onboarding-setup.md` §6.1 is satisfied by absence). The wizard has no `link` control type today (types: number, select, text, textarea, toggle, user_multiselect); the row needs a small additive `link` type in the wizard renderer — **flagged for the build lane to confirm against the renderer first**; if it cannot be added without touching shared wizard code the lane stops and asks the conductor (rule #10a: leaving it out is Johan's call, not the lane's). The existing `default_deposit_months` and `show_lease_type_field` settings are reused unchanged.

### 15.15 Audit trail, domain events, API

- **`LeaseEvent` types (≤ 40 chars):** `lease_created`, `agreement_prepared`, `agreement_out_for_signing`, `agreement_edited`, `agreement_differences_confirmed`, `agreement_needs_confirmation`, `agreement_signed`, `agreement_accepted`, `lease_activated_by_signing`, `agreement_declined`, `agreement_voided`, `agreement_expired`, `signed_not_activated`, `lease_signed_on_paper`, `lease_edited`. Each carries actor, a plain-words description and `metadata` (template id, signer names, field, old, new, reason). **They reach the tenancy log with no change to `LeaseTimelineService`** — its `renewalEventEntries()` (`LeaseTimelineService.php:348-363`) already lists every `LeaseEvent` row of the lease with its description and actor — so the one file the first draft shared with maintenance Build 3 is no longer touched. The e-sign side already writes `signature_audit_log`; the launcher adds one `lease_linked` row.
- **Domain events (rule #9; catalogue rows in `corex-domain-events-spec.md`, listeners registered inline in `AppServiceProvider`):** engine events `Docuperfect\SignatureEnvelopeSent`, `…Finalized`, `…Declined`, `…Cancelled`, `…Expired` (payload: `signatureTemplateId`, `documentId`, `agencyId`, `reason?` — scalars only, sync listeners, emitted inside try/catch so a listener fault can never disturb a legally completed signing); rental events `Rentals\LeaseAgreementSigned`, `Rentals\LeaseAgreementFailed` (declined/voided/expired, with reason). One listener `UpdateLeaseSigningState` (idempotent, scoped by `leases.signature_template_id`) handles all five engine events and calls `LeaseAgreementCheck::verdict()` (§15.8.4 #2). Emission points: `SignatureService::sendSigningRequest` (`:1055`), the two lease-hook sites (`:4082`, `:4302`, replaced by the event for linked leases), `declineRequest` (`:5002`), a new `SignatureService::cancelEnvelope()` extracted from `ESignWizardController::cancelDocument` (`:8336`) with the controller delegating (behaviour-preserving — the one shared-file refactor in this spec), `expireOutstandingRequests` (`:5162`), `SignatureController::reject` (`:4013`, action `revise` ends the envelope and clones the document — mapped to `declined`, reason "returned for revision"; the clone is ignored and "Prepare again" restarts from the lease).
- **Safety net:** `Lease::reconcileSigning()` re-reads the envelope status through `signingStatusFor()` and runs the §15.8 comparison whenever the Lease Hub, the Leases list row or the Command Centre loads a lease whose `signing_status` is in flight; the daily `leases:reconcile-signing` command (agency-by-agency, never a bulk query across agencies, same rule as `CheckLeaseExpiry`) sweeps the rest — the AT-447 "push + re-read on read" pattern, so a missed event never strands a lease.
- **API (rule #7):** `POST /api/v1/leases/capture` (body = the screen fields + `intent=lease_only|lease_and_sign|paper_copy` + `capture_key`), `GET /api/v1/leases/{lease}/signing` (status, signers, missing list, differences), `POST /api/v1/leases/{lease}/signing/confirm` (the §15.9 confirmation). Named, versioned, scope-guarded, in the Admin → API catalogue; the existing `renewal/*` endpoints stay and delegate. Contract: `lease_and_sign` returns `{lease, signing_status, redirect_url}`, or `422 {missing:[{key,label,fix_url}]}`, or `409 {code:"no_lease_agreement_linked"}`.

### 15.16 Failure cases

| Case | What happens | Prevent or absorb |
|---|---|---|
| Required data missing at button (b) | Nothing created; same screen, the "needed for signing" list with links; the agent's input kept | prevent |
| No landlord linked / landlord or tenant without email or ID/passport | Same list; link to the contact | prevent |
| **No lease agreement linked (R3)** | (b) disabled with the message and the Settings link of §15.4; (a), paper copy unaffected; API `409` | prevent |
| Linked template later archived, un-mapped or no longer valid | Treated as "not linked": (b) disabled with "Your lease agreement needs attention — <reason>" and the link; leases already out are unaffected (their document is frozen) | prevent |
| Another agency's (or an ownerless) template id posted directly | Guard refuses (§15.12.4); same message as a missing template | prevent |
| Double-click / back and resubmit | `capture_key` returns the same lease; no duplicate draft | absorb |
| Activation refused at (a) or the paper copy (another lease active) | Transaction rolled back, plain message with the blocking lease; nothing half-created | absorb |
| **Value changed in e-sign after sending (R6)** | No lock, no restart. Detected by comparison; the agent confirms on the lease screen at approval (§15.9); lease edits for the same fields are blocked meanwhile | prevent |
| Document edited again after the agent confirmed, before approval completes | Fingerprint no longer matches; the confirm screen reopens with the new differences | prevent |
| A different person printed in the agreement | Confirm disabled; correct the contact and Re-check, or return/cancel and start a new lease (R7) | prevent |
| A value cannot be read from the document | Listed as "cannot verify"; the agent types what they see; logged | absorb |
| Approval that bypasses the route (wet-ink, unattended completion, other engine path) | `signed` + `draft` + "Signed — confirm the lease details"; lease cannot activate until confirmed (§15.5) | prevent |
| Lease edited from the lease screen while the agreement is out | Agreement-governed fields are read-only with the reason; server refuses a forged update | prevent |
| A signer declines | `declined`; the lease stays a draft; the agent is told in-app and by mail with the reason; the card offers "Prepare again" (or Cancel lease) | absorb |
| Agent returns the document for revision (`SignatureController::reject`, action revise) | `declined` (reason "returned for revision"); the engine's clone is orphaned and ignored; "Prepare again" restarts from the lease | absorb |
| Agent cancels from e-sign "My documents" | `SignatureEnvelopeCancelled` → `voided` | absorb |
| Links expire | `expired`; "Prepare again" | absorb |
| Signed, finalisation failed or slow | Card shows "Signed — filing the document"; the lease activates when `SignatureEnvelopeFinalized` fires; the existing stuck-finalisation alarm covers a failure | absorb |
| Signed but another lease is active | §15.5 — `signed` + `draft` + visible next step | absorb |
| Previous lease archived/soft-deleted after the renew screen loaded | Clean validation error "that lease is no longer available", never a 500 | prevent |
| Property, tenant or landlord contact deleted while the document is out | Document is frozen (engine doctrine); lease pages render with the denormalised names and a note (§4) | absorb |
| Lease cancelled while the agreement is out | Cancel also cancels the envelope in the same action (reason shared) | prevent |
| Event or listener failure | Never breaks signing (try/catch); the safety net repairs the lease on next read or nightly | absorb |
| A document launched from the generic wizard, not from a lease | Old behaviour: address/tenant matching (kept as fallback) — no comparison, no confirm screen (no lease link) | absorb |
| Tenant field posted on the renew form | Ignored; tenants rebuilt from the previous term (R7) | prevent |

### 15.17 Multi-agency check (rule #9 — "what does this look like for the SECOND agency?")

R3 makes this structural rather than a promise. CoreX ships **no lease document**, so there is no wording, fee, notice period, signature or address of any agency in the new code, views, mails or settings. A second agency with nothing set up sees a working lease-only path, a working paper-copy path, and on button (b) a plain, non-HFC message and a link to its own set-up page. Once it links its own lease, the agreement it produces contains only what its own template says; the map decides which values CoreX writes into it and compares. The guard (§15.12.4) makes it impossible for any agency to produce or even select a template it does not own, HFC's included; no agency id appears anywhere; the agent mails and hub texts use the agency's own trading name; the fixed signing order is the same for every agency by ruling, not by assumption. The second-agency test is an acceptance criterion (§15.18 #14).

### 15.18 Acceptance criteria

1. One view, one validator, one writer serve new, renew and confirm; the old create/renewal screens redirect; the lease-hub outcome dialogs still work; API `renewal/*` still works; every §7.2/§7.3 behaviour of the New Lease screen (type-to-search property, any rental status, fields kept after an error, scoped save) is unchanged.
2. Button (a): lease + tenants + terms created atomically; property updated exactly as today when activated; double-submit makes one lease; failure leaves nothing behind. Button names are exactly R1's.
3. **R2:** the signed-paper-copy link works on a brand-new lease and on renewal, with no lease agreement linked; the lease ends `active`, `signed_on_paper`, the PDF filed on the lease and property.
4. **R3:** with no valid linked agreement, button (b) is disabled with the message and link of §15.4 and the API returns `409`; the HFC V8 template (no owning agency) and another agency's template are refused by the guard at all four call sites. **HFC in its real starting state (F1)** — an HFC user, no linked agreement — sees the same disabled button and message; no test or screen assumes a sendable HFC lease exists.
5. Button (b) with gaps: nothing created, the list shown, input kept; with everything present: the agent lands on the pre-filled document, nothing already known is asked again.
6. **R4:** the document's recipients are the agent, then the tenant(s) in primary order, then the landlord(s) — in every case, with no setting or tick that changes it.
7. **R5:** on the agent's final approval, in one transaction: `signing_status=signed`, `signed_at`, `accepted_at/by`, lease `active` with the captured/confirmed from/to dates, property leased-out per §12.5, previous term expired (renewal) with escalation recorded, signed copy visible on the lease and in the property's documents, harvest written, events logged.
8. **R6:** a rent, date, deposit or agreement-term value changed in e-sign by each route (agent field save, signer field save, text amend, struck-and-reworded field) is detected; the approve click lands on the confirm screen showing "Lease says … · Agreement says …"; the lease changes only on "Confirm lease details and approve", each change logged old → new; a different person's name blocks; an approval path that skips the route leaves the lease `signed` + `draft` until confirmed; an unchanged document approves with no extra screen.
9. **R7:** the renewal screen shows the tenants read-only with the new-lease panel; "Start a new lease for this property" opens New Lease with the property fixed; a forged tenant field on the renew POST changes nothing.
10. Declined, voided, expired: lease stays a draft with the right status, the agent told, one click ("Prepare again") restarts pre-filled.
11. Renewal opens pre-filled from the previous term (and its document values when e-signed); only changed fields are typed; (b) produces a NEW document linked to the NEW term.
12. Wet-ink first lease: renewal still offers both buttons; system fields pre-filled; the §15.6.3 list shown blank and marked; only map-required fields block (b).
13. A lease launched from a lease and a document launched from the generic wizard both end correctly (linked vs fallback) — a second lease is never created for a linked document.
14. **Second-agency test:** an agency with no template sees lease-only + paper paths and a non-HFC message; an agency that links its own lease produces an agreement containing none of HFC's wording or numbers; the scan test finds no "Home Finders"/"HFC"/"Let's Assist" in anything this build creates; **an agency whose lease has no schedule never sees the service-fee, other-deduction or net-to-owner inputs, and none is calculated for it (§15.12.5).**
15. Own/branch/agency: a user without scope gets 404 on another's lease, terms, flow, signing, confirm and set-up rows.
16. Leases list: Agreement filter, sub-label, export columns; empty states; default sort unchanged. Tenancy log shows created → prepared → out for signing → (edited) → differences confirmed → signed → accepted → activated, with who and when — with `LeaseTimelineService` unchanged.
17. Setup Wizard: the leases-step link row present with explain/affects; saving the step never wipes settings it did not render.
18. **Reference map (F2):** with HFC's field map loaded (the 24 places of §15.12.5), the capture screen asks for exactly the nine capture-screen fields plus the commission %, the launcher seeds all 24 places (rent in words and escalation in words included), and every contact/lease/calculated source resolves to the stated field.

### 15.19 Files

**Create:** `config/lease-agreement-fields.php`; `app/Models/LeaseAgreementTerms.php`; `app/Http/Requests/CoreX/LeaseCaptureRequest.php`; `app/Services/Rentals/{LeaseCaptureService,LeaseSigningLauncher,LeaseAgreementValuesReader,LeaseAgreementHarvest,LeaseAgreementCheck,LeaseAgreementTemplateGuard,PreviousTermValuesReader}.php`; `app/Http/Middleware/EnsureLeaseAgreementConfirmed.php`; `app/Http/Controllers/CoreX/LeaseAgreementConfirmController.php`; `app/Events/Docuperfect/SignatureEnvelope{Sent,Finalized,Declined,Cancelled,Expired}.php`; `app/Events/Rentals/{LeaseAgreementSigned,LeaseAgreementFailed}.php`; `app/Listeners/Rentals/UpdateLeaseSigningState.php`; `app/Console/Commands/{ReconcileLeaseSigning,AssignTemplateToAgency}.php`; `app/Http/Controllers/Api/V1/LeaseCaptureApiController.php`; `resources/views/corex/leases/{capture,_agreement-fields,_signing-checklist,_agreement-card}.blade.php`, `resources/views/corex/rental-lease-templates/_field-map.blade.php`; migrations M1–M5.
**Modify:** `LeaseController` (create/store → capture, update lock), `LeaseRenewalController` (create → capture; draft/upload routes delegate), `RenewalDraftService` (builder extracted into the launcher), `Lease.php`, `LeaseEvent.php`, `RentalLeaseTemplate.php`, `LeaseHubService`, `corex/leases/{index,show}.blade.php`, `rental-context-bar.blade.php`, `RentalLeaseTemplateController` + its three views, the `leases` step of `config/agency-onboarding-copy.php` and `AgencySetupWizardController::currentValues`, `routes/web.php`, `routes/api.php`, `routes/console.php`, `bootstrap/app.php` (middleware alias), `AppServiceProvider`, `corex-domain-events-spec.md`, **shared e-sign files (small, additive):** `ESignWizardController.php` (one hook after `:3819`; `cancelDocument` delegates to the new `SignatureService::cancelEnvelope`), `SignatureService.php` (event emission at the points of §15.15; `createLeaseFromSignedDocument` prefers the link), `SignatureController.php` (`reject` emits), `Flow.php` (fillable), `docuperfect/signatures/review.blade.php` only if the middleware route proves insufficient (it should not). **Not touched:** every pipeline-gate file (`Template.php`, `CdsDraft.php`, `SigningController.php`, the role-block/normalizer/freshness/letterhead/insertable services), `LeaseTimelineService.php`, `TemplateController.php`, `WebTemplateSeeder.php`, `WebTemplateDataService.php`.

### 15.20 Tests (single files, run one at a time with `scripts/lane-test.sh` — CLAUDE.md #13, STANDARDS −1x)

Mirrors reality (BUILD_STANDARD §5/§5a): a wet-ink-signed predecessor, two drafts for one tenant on one property, a document completed twice, a soft-deleted-then-recreated terms row, an already-touched lease, each optional field omitted, the lazy-but-valid minimum (property + one tenant + rent + start date), one malformed value per validated field. Per build in §15.21. The render gate (`verify-alpine-render.mjs`, STANDARDS −1u) and the real-browser click-through belong to cc1/the conductor after landing (STANDARDS −1s) — lanes run `php -l`, `view:clear` and their own single test files.

### 15.21 Build plan — lane-sized builds, exact files, tests, and the conflict map with `rental-work-orders.md` §17

**Rules for every L build:** read §15 only (plus the sections it names); `/clear` first; work only the files listed; append to shared files only inside a `// LEASE-AGREEMENT BEGIN/END` marker; add a dated BUILD NOTE under §15; line numbers are `origin/QA1` `be0973f82` and **drift — find the method by name**; no `CHAT_STARTER.md` structure changes beyond the lane's own dated line; Sonnet, one lane per build, Jira: one ticket for the whole L series, builds are checklist items.
**State of §17:** the foundation landed on QA1 on 6 Oct (`rental-work-orders.md` §17.24; migrations `2026_10_11_1000nn`); Builds 1–3 are about to start and add **no migrations and no permission keys**. §17 touches the leases area at one place only, `LeaseTimelineService` (Build 3) and the hub's tenancy-log filter — and this build no longer touches `LeaseTimelineService` at all (§15.15).

#### Build L1 — Foundation (solo, first; changes no behaviour)
- **Create:** migrations M1–M5; `LeaseAgreementTerms`; `config/lease-agreement-fields.php`; service shells with final signatures — `LeaseCaptureService`, `LeaseSigningLauncher` (real: the builder extracted from `RenewalDraftService.php:144-241`, behaviour-identical), `LeaseAgreementValuesReader` (real, pure), `LeaseAgreementHarvest`, `PreviousTermValuesReader`, `LeaseAgreementCheck` (shell: `verdict()` → "no differences"), `LeaseAgreementTemplateGuard` (shell: `linkedFor($agency)` → none); the seven event classes; `UpdateLeaseSigningState` (inert).
- **Modify:** `Lease.php` (constants `:24-27`, `$fillable :48`, `$casts :85`, relations after `:257`); `LeaseEvent.php` (type constants `:17-24`); `RentalLeaseTemplate.php`; `Flow.php` (fillable); `RenewalDraftService.php` (delegates to the launcher); `AppServiceProvider` (register the inert listener in its own block); `database/schema/mysql-schema.sql` (schema:dump + DEFINER strip, by this build alone).
- **Tests:** `tests/Feature/Leases/LeaseFoundationTest.php` (migrations on a copy of Staging data; M5 back-fill for an e-signed lease, a paper renewal and a draft; models instantiate; soft-delete → recreate of a terms row); `tests/Unit/Leases/LeaseAgreementValuesReaderTest.php` (money `R6 940`/`6,940.00`/nbsp, dates `2026-10-13`/`13 October 2026`, struck `<ins>` wins over `<del>`, unreadable → null, precedence of the five sources); existing `LeaseRenewalTest.php` and `RenewalDraftEligibilityServiceTest.php` stay green (extraction is behaviour-identical).

#### Build L0 — Each agency's own lease agreement: set-up, link, field map, guard, HFC isolation (after L1)
- **Modify:** `RentalLeaseTemplateController.php` — `index :21-60` and `create :61-70` picker queries (own agency's e-sign templates only, replacing `applySharedWith :56-68`), `store :72-94` and `update :103-118` (call the guard, save `field_map`, `is_default` transactionally); `corex/rental-lease-templates/{index,create,edit}.blade.php` (+ new `_field-map`); `routes/web.php:3061-3068` (field-map route); fill `LeaseAgreementTemplateGuard` (§15.12.4); `config/agency-onboarding-copy.php` leases step (`:368`) + `AgencySetupWizardController.php:174,605` (link row + chip; confirm the renderer's `link` type first, else stop and ask).
- **Create:** `AssignTemplateToAgency` command.
- **Starting state (F1):** L0 and every later build is tested with HFC un-linked; nothing seeds, assumes or auto-links an HFC lease. The fixtures for the linked state are the second agency's own template and HFC's reference map of §15.12.5 loaded as a fixture map against a fixture template with the 24 field names of `template-121`.
- **Tests:** `LeaseAgreementReferenceMapTest.php` (the 24 places of §15.12.5: each source resolves, calculated values — words, service fee with and without agency VAT, net to owner — are right, an agency whose map has no schedule gets none of it); `RentalLeaseTemplateTest.php` (picker lists own only; archive/restore kept); `tests/Feature/Leases/LeaseAgreementTemplateGuardTest.php` (own valid template passes; `agency_id=NULL`+`is_global` HFC-style template refused for every agency incl. its would-be owner; other agency's template by direct POST refused with no existence hint; archived, non-e-sign, missing-map, missing-role refused); `AssignTemplateToAgencyCommandTest.php` (idempotent, refuses an already-owned template, writes the audit row); `LeaseSetupWizardLinkRowTest.php`; `NoHfcWordingInLeaseProcessTest.php` (scans the files this series creates for "Home Finders", "HFC" and "Let's Assist").

#### Build L2 — Capture screen, lease-only path, paper copy, renewal on it (after L1)
- **Create:** `capture.blade.php` (+ `_agreement-fields`, `_signing-checklist`); `LeaseCaptureRequest`; `LeaseCaptureApiController`; real `LeaseCaptureService`.
- **Modify:** `LeaseController.php` — `create :326-368` and `store :399-473` → capture mode new (keep `searchRentalProperties :244`, `pickableRentalProperties :229`, `oldTenantSeed :370` and §7.3's behaviour), `update :587-606` (agreement-governed lock), `export :302`/`filteredLeasesQuery :146` (Agreement filter + columns); `LeaseRenewalController.php` — `create :44-67` → capture mode renew (tenants read-only + new-lease panel), `uploadRenewal :114-167`, `draftCopyForward :69`, `draftFromTemplate :90` delegate to the capture service, `validateTerms :292` retired; remove/replace `create.blade.php`, `renewal.blade.php`, `_renewal-term-fields.blade.php`; `show.blade.php` renew dialog `:160-185` and edit panel `:505+`; `index.blade.php` filter (`:93` area); `routes/web.php` leases group `:3454-3503` (inside a `LEASE-CAPTURE` marker; old routes redirect); `routes/api.php` lease group `:479-497` (`POST /leases/capture`). Button (b) is rendered and shows the §15.4 disabled message until L0/L3a (guard shell returns "none").
- **Tests:** `tests/Feature/Leases/LeaseCaptureTest.php` (both modes; (a); paper copy on new AND renew; atomicity; idempotent `capture_key`; each optional field omitted; the minimum; one malformed per field; forged tenant field on renew ignored; direct-URL scope 404; renew tenant panel and link target; overlap refusal rolls back); keep `LeaseCreatePropertySearchTest.php` green; adapt `LeaseRenewalTest.php`, `LeaseCoreTest.php`, `LeaseEditTest.php` (lock), `LeaseActionDialogsTest.php`, `LeaseListStandardTest.php`.

#### Build L3b — Engine events, linked completion, safety net (after L1; the conductor confirms no other e-sign lane is open first)
- **Modify:** `SignatureService.php` — emit at `sendSigningRequest :1055`, `:4082-4087`, `:4302-4311`, `declineRequest :5002`, `expireOutstandingRequests :5162`; `createLeaseFromSignedDocument :5506-5638` (prefer `leases.signature_template_id`; never create an ACTIVE lease for a linked document; one-active guard; no rent overwrite with 0 — §15.22 #3, #4); new `cancelEnvelope()` extracted from `ESignWizardController.php:8336` (controller delegates); `SignatureController.php::reject :4013`; `Lease.php` (`signingStatusFor`, `reconcileSigning`); real `UpdateLeaseSigningState` (calls `LeaseAgreementCheck::verdict()` — shell until L3c, so completion simply activates); `routes/console.php:35` neighbourhood (`leases:reconcile-signing`); `corex-domain-events-spec.md`; the M5 back-fill verified on a copy of Staging data.
- **Create:** `ReconcileLeaseSigning`.
- **Tests:** extend `LeaseFromSignedDocumentPromotionTest.php` (linked completion activates exactly one lease; renewal path; fallback for an unlinked document unchanged; decline/void/expire map; document completed twice); `tests/Feature/Leases/LeaseSigningReconcileTest.php`; `tests/Feature/Docuperfect/SignatureEnvelopeEventsTest.php` (each emission point; a throwing listener never breaks a completion). `SignatureService`/`ESignWizardController` are not on the pipeline-gate list but carry test diffs by convention.

#### Build L3a — Launch and the hub (after L2 and L0)
- **Modify:** `LeaseSigningLauncher::missing()/launch()` (recipient order fixed: agent → tenants → landlords); `LeaseCaptureService` (path (b)); `ESignWizardController.php` one hook after `:3819`; `LeaseController::cancel :621-663` (also cancels the envelope); `LeaseRenewalController::cancelDraft :169` (abandons the flow); `LeaseHubService.php` lifecycle `:22-71` and `nextStep :86-137`; `show.blade.php` (header `:92-135`, next-step card `:353+`, one `@include`); `rental-context-bar.blade.php`; "Prepare again" route (inside the `LEASE-CAPTURE` marker); `routes/api.php` `GET leases/{lease}/signing`.
- **Create:** `_agreement-card.blade.php`.
- **Tests:** `tests/Feature/Leases/LeaseSigningLauncherTest.php` (missing list; flow shape; **recipient order always agent → tenants → landlords**; landing step; no-template and guard-bypass refusals; double-click; "Prepare again"); (b) cases added to `LeaseCaptureTest.php`; `LeaseHubTest.php` next-step wording per state.

#### Build L3c — Change check and confirm (after L1, L2, L0 and L3b; can run beside L3a)
- **Create:** real `LeaseAgreementCheck`; `EnsureLeaseAgreementConfirmed`; `LeaseAgreementConfirmController`.
- **Modify:** `bootstrap/app.php:94` (alias); `routes/web.php:6267` (adds the middleware to `docuperfect.signatures.approveAndAdvance` — the route line only, the engine controller is untouched); confirm routes inside the `LEASE-CAPTURE` marker; `capture.blade.php` (`mode=confirm`, L2's file — L3c edits it only after L2 landed); `Lease::reconcileSigning` (writes `agreement_edited`); `routes/api.php` `POST …/signing/confirm`.
- **Tests:** `tests/Feature/Leases/LeaseAgreementCheckTest.php` (every row of §15.8.3; one fixture per edit route — agent field save, signer web field, `completeWeb` merge, text amend, struck-and-reworded field; unreadable value; fingerprint change); `tests/Feature/Leases/LeaseAgreementConfirmTest.php` (screen content "Lease says · Agreement says"; the button writes lease and terms and one `agreement_differences_confirmed` per field with old/new; nothing written before the button; name difference blocks; typed cannot-verify value logged; edit after confirm reopens; idempotent re-approve; bypass path leaves `signed` + `draft`; real-HTTP pass on a record that has already been through a confirm — §5a).

#### Final — End-to-end (last; one lane)
`tests/Feature/Leases/LeaseAgreementEndToEndTest.php`: wet-ink predecessor renewal by e-sign; new lease by e-sign; paper copy on a new lease; decline; engine cancel; document completed twice; rent changed in e-sign then confirmed; second-agency run with its own template and the HFC template refused. Then `CHAT_STARTER.md` and the BUILD NOTES.

**Which lease builds run in parallel with which (the rule: lease builds touch disjoint files from each other except where stated):**

- **L1** runs alone, first.
- **L0 ∥ L2 ∥ L3b** — mutually parallel once L1 has landed (disjoint files: L0 the template set-up page, L2 the capture screen and lease controllers, L3b the e-sign engine files).
- **L3a** needs L2 and L0; it can run beside **L3b** and beside **L3c**.
- **L3c** needs L1, L2, L0 and L3b (it edits L2's `capture.blade.php` and fills L3b's listener hook); it can run beside **L3a** (L3a owns `show.blade.php` and `LeaseHubService`; L3c only supplies `Lease::agreementState()`).
- **Final** end-to-end runs last.

Recommended pairing under the two-working-lanes rule: **L1 alone → L2 ‖ L3b (or L2 ‖ L0 if the conductor has not yet confirmed that no other e-sign lane is open) → L0 ‖ the other of the two → L3a ‖ L3c → final.** No pair collides on a file except as listed in the map below.

**Conflict map — every file a lease build and a §17 build both touch (all small, additive, textually separable):**

| File | §17 | Lease series | Collision handling |
|---|---|---|---|
| `LeaseTimelineService.php` | Build 3 exclusive | **none** — agreement events are `LeaseEvent` rows that `renewalEventEntries()` already lists | no collision |
| `corex/leases/show.blade.php` (the hub) | Build 3: the tenancy-log filter region (main column, below the next-step card) | L2: renew dialog `:160-185` and edit panel `:505+`; L3a: header `:92-135`, next-step card `:353+`, one `@include` | disjoint hunks; **L3a's next-step edit sits directly above Build 3's filter region** — whoever merges second rebases; no semantic overlap |
| `routes/web.php` | marker blocks `:3972-3977` after the work-order group | leases group `:3454-3503` (`LEASE-CAPTURE` marker) + one middleware on `:6267` | ~500 and ~2 300 lines apart |
| `routes/api.php` | tenant (`:231-232`) and landlord (`:253-256`) markers | lease group `:479-497` | different regions |
| `config/agency-onboarding-copy.php` + `AgencySetupWizardController::currentValues` | `rental_work_orders` arm, per-build markers | L0: the `leases` step (`:368`; `currentValues :174,605`) | different arms — diff before merge |
| `AppServiceProvider.php` | inline listener lines, one block per build | L1's inert listener line | textual merge only |
| `corex-domain-events-spec.md`, `CHAT_STARTER.md` | rows / dated lines per build | rows / dated lines | append-only |
| `database/migrations`, `schema/mysql-schema.sql` | none after the foundation | M1–M5 (L1 alone) | other lanes' persistent test schemas self-update by fingerprint (STANDARDS −1x/−1y) |
| `config/corex-permissions.php` | foundation only | none (reuses `leases.*`, `rental_lease_templates.manage_settings`, `access_docuperfect`) | no collision |
| Property Rental tab, `PropertyController::updateRentalDetails` | Build 2 | none — "New Lease" keeps its route | no collision |
| `Lease.php`, `LeaseController`, `LeaseRenewalController`, `RenewalDraftService`, `LeaseHubService`, `RentalLeaseTemplate*` | not in any §17 list | ours | exclusive |
| `SignatureService.php`, `ESignWizardController.php`, `SignatureController.php`, `Flow.php` | not in any §17 list | L3b/L3a, additive | exclusive **unless another e-sign lane is open** — conductor confirms before L3b. (Platform E-Sign, AT-447, lives under `PlatformEsign/` and shares none of these.) |

**Answer for the conductor:** every lease build (L1, L0, L2, L3b, L3a, L3c) can run in parallel with every maintenance build (B1, B2, B3) — no migration, no permission key, no shared service and no shared test file. The only contact points are textual: `show.blade.php` between L3a and B3 (adjacent hunks — schedule them an hour apart or rebase), `AppServiceProvider` listener lines, the wizard copy file (different arms), and the append-only docs. Behaviour interplay: none — `leases.status` keeps its meaning, so faults, work orders, job cards, inspections, the tenant/landlord portals and the approval gate behave as before; a signed-and-accepted lease simply becomes `active` through the same `LeaseActivationService` they already read. The constraint on running them all at once is lane capacity (two working lanes), not code.

### 15.22 Found while investigating — REPORTED, not changed (scope-lock rule)

*Fixed inside the build above (because this build rewrites those paths):* 1 `store()` with activation is non-atomic and uncaught; 2 e-sign completion creates an ACTIVE lease without the one-active guard, the let-out flip or an event, and cancelling a renewal draft leaves its flow alive (`SignatureService.php:5608-5618`; `LeaseRenewalService.php:130-155`); 3 promotion matches by address and tenant, can pick the wrong draft (`:5549-5560`); 4 promotion overwrites rent with 0 when the document has no rent field (`:5521`); 5 plain `activate()` on a renewal draft skips escalation and event; 7 `update()` has no status guard, cannot clear lease type, allows an end date with month-to-month; 9 renewal form drift; 10 tenants at renewal (now: the new-lease rule, R7); 12 the "lease signed" lifecycle node is true for a cancelled draft; 14 landlord has no snapshot on the lease (the launcher snapshots recipients into the flow at launch). *Closed by QA1 since the first pass:* 15 (the 500-row property select and its inline query — §7.2/§7.3) and the property-pre-fill half of 8 and 21 (`?property_id=` now pre-fills a scoped rental property); the rest of 8 (the create screen ignoring the lease-type setting and the default-deposit months) is still fixed by L2. 20 (the picker listing only global templates) is fixed by L0 in the opposite direction — the agency's own templates only.
*Reported only — not touched by this build:*
6. ~~`LeaseController::destroy()` has no status check — a direct DELETE can archive an ACTIVE lease~~ — **fixed 7 Oct 2026 (§3.8):** every archive path goes through `LeaseArchiveService`, which cancels an active lease and releases the property.
11. `RentalLeaseTemplate.category` is a label nothing filters on (L0 filters residential in the capture picker only).
13. No domain event or `LeaseEvent` on lease activate/edit outside this flow (rule #9) — create and signing are covered here.
16. `corex-domain-events-spec.md:319` describes a "MandateController after e-sign callback" that does not exist; `DocumentSigned` and `MandateSigned` are registered to loggers but never dispatched.
17. `docuperfect.signatures.supersede` (`web.php:6362`) points at `SignatureController::supersede`, which does not exist.
18. `rental-renewals.md` §15 item 6 calls `SignatureService.php` pipeline-gated; `scripts/dev-check.ps1:150-161` does not list it.
19. Template defects: CDS snapshots 121/122/75 list only Lessor and Agent in the signature block, 122 prints the lessor's name as the lessee; `price_in_words` is blanked for non-sales documents; the V8 blade prints "rent in words" blank (`rental_in_words` vs the resolver's `rental_amount_words`), merges only the first two landlords/tenants, and prints no deposit field. Relevant to HFC's own lease, not to the process.
22. Both portal mappers still do not show the real availability date for a re-advertised residential rental (`rental-renewals.md` §15, pre-existing).
23. The V8 lease has "delete 4.2 if not applicable" as a manual strike — no conditional rendering (HFC's template).
24. `SignatureService::extractLeaseFields :5429` reads `fields_json` as a map; `fields_json` is a list of field objects, so the legacy extractor likely misses rent and dates.
25. Live (`nexus_os`) was **not** queried — whether live has the POPI V8 or CDS lease templates, and whether HFC's wizard uses them, is unverified (the L0 pre-requisite of §15.12.4).
26. `TemplateController::copy()` (`:443-455`) uses `replicate()`, so copying a template with no owning agency produces another ownerless, shared template — it cannot be used to give an agency its own copy of a shared template. A lane-scope decision for whoever owns the template library.
27. `WebTemplateSeeder.php:70-101` seeds HFC-worded web templates with no agency and `is_global = true` — every agency's e-sign wizard step 1 can list them. The lease process now refuses them; the generic wizard does not (outside this spec).
28. e-sign has no old/new record for raw field saves (`saveAgentFields :1154`, `saveAgentWebFields :1202`, `DocumentController::saveFields :150` carry no status guard; `SigningController::saveWebFields :1509` is gated by identity and field ownership, not by turn or status). Detection here is by comparison (§15.8) precisely because of this; the gap itself is unchanged.
29. `docuperfect.signatures.saveAgentWebFields` has no caller in `resources/`.

30. The repo's residential-lease CDS snapshots disagree with each other: `template-121`'s signature block has no tenant place; `template-122` binds the tenant's name field to `lessor_full`; `template-75` is an older variant with different field names (§15.12.5). Which one matches live's "Lease_Agreement_POPI_V8_CDS" is not knowable from the repo.
31. `WebTemplateDataService.php:139-141` calculates the service fee with a fixed 15 % VAT and the net to owner for any lease, regardless of the agency's VAT set-up; the resolver also blanks `price_in_words` on non-sales documents (`:315`), so the CDS lease's "rental in words" prints blank unless seeded.
32. Johan's brief says 22 placeholders; the list as relayed and `template-121` both have 24 fill-in places (§15.12.5).

### 15.23 Rulings log (replaces the open-questions list — nothing is open)

| # | Question put to Johan | Ruling (6 Oct 2026, 13:51, relayed by the conductor) | Where written in |
|---|---|---|---|
| 1 | Button names | "Create lease & prepare for signing" | §15.0 R1, §15.4 |
| 2 | Signed paper lease on a new lease | Yes — on a new lease as well as renewal | §15.0 R2, §15.3, §15.6.5 |
| 3 | Which lease agreement | No CoreX standard lease; each agency supplies and links its own; button (b) unavailable with a message until then; HFC's built-in template is HFC's own and not offered to others | §15.0 R3, §15.4, §15.12, §15.14, §15.17 |
| 4 | Who signs for the landlord / order | Always agent → tenant(s) → landlord; no setting, no per-lease switch | §15.0 R4, §15.4 step 4 |
| 5 | What "accepted" means | The agent's final approval on the fully signed document | §15.0 R5, §15.5 |
| 6 | Changes after sending | e-sign's existing document-edit facility; CoreX detects changed lease-relevant values, shows the lease screen with differences, agent confirms or accepts, then approves; lease and document must agree before acceptance | §15.0 R6, §15.8, §15.9 |
| 7 | Tenants at renewal | Cannot change; a change of tenants is a new lease; the renewal screen says so and offers "start a new lease" pre-filled for the property | §15.0 R7, §15.3, §15.6.2 |
| F1 | Live state (14:32) | Live has the CDS import, never used for a lease; Johan creates HFC's web-pack templates on live himself (7 Oct); "no agreement linked → button unavailable" is HFC's real starting state | §15.0, §15.1 #11, §15.4, §15.12.4 |
| F2 | Reference fields (14:32) | The document's fill-in places, in order, are HFC's reference field map; the two HFC fees stay out of other agencies' leases | §15.12.5 |
| — | Earlier (kept) | Renewal opens pre-filled; a wet-ink previous lease still gets e-sign on renewal | §15.0 L4, L5, §15.6.3 |

**Decisions taken by the lane, not by Johan (technical — he does not need to rule, the conductor may overrule):** (1) end-state comparison rather than catching edits as they happen (§15.8.1); (2) while an agreement is out, the agreement-governed fields on the lease's own edit panel are read-only so there is one place to change a value (§15.13) — reversible; (3) a different person printed in the agreement is blocked, not accepted (a change of tenant is a new lease, R7); (4) the earlier "Void and start again", agency signing-order, "agency signs for the landlord", minimum-term and clause-figure settings are dropped as R3/R4/R6 made them moot; (5) the agreement section of the capture screen shows only the fields the agency's own lease carries.

### 15.24 Build notes (one dated note per build; newest last)

**BUILD NOTE — L1 Foundation — 7 Oct 2026 (cc5, branch `cc5-lease-esign-L1-2026-10-07`, QA1).** BUILT. Changes no behaviour a user can see; everything below is in place and INERT.

*Built (exactly the §15.21 L1 list):*
- **Migrations** `2026_10_12_100001`–`100005` = M1 `lease_agreement_terms`, M2 `leases` signing columns (+ index `(agency_id, signing_status)`, unique `capture_key`), M3 `flows.lease_id`, M4 `rental_lease_templates` (`field_map`, `is_default`, `validated_at`, `validation_problems`), M5 data back-fill. All idempotent (`hasTable`/`hasColumn` guards; M5 only writes rows still at `not_sent` / with a null link). Snapshot regenerated with `scripts/schema-dump.sh` (never by hand).
- **Models:** `LeaseAgreementTerms` (BelongsToAgency + SoftDeletes; `forLease()` RESTORES a soft-deleted row because `lease_id` is unique); `Lease` (constants `SIGNING_*` + `SOURCE_ESIGN_DOCUMENT` / `SOURCE_UPLOADED_SIGNED_COPY`, fillable, casts, relations `agreementTerms`/`agreementTemplate`/`signingFlow`/`signatureTemplate`/`agreementDocument`/`acceptedByUser`, `signedDocument()` (§15.11), `isLockedForSigning()`); `LeaseEvent` (the 15 §15.15 type constants, all ≤ 40 chars); `RentalLeaseTemplate` (fillable, casts, `isUsableBy()`); `Flow` (fillable `lease_id`).
- **`config/lease-agreement-fields.php`** — the registry (§15.7.1). Neutral keys/labels only (no agency, fee scheme or document named). Also carries `link_requirements` (§15.12.3's "must carry rent, start date, a tenant name, a landlord name, and end date unless month-to-month capable") and `requirable_groups` for Build L0 to read.
- **Services** (`app/Services/Rentals/`): `LeaseAgreementValuesReader` — REAL and pure (§15.8.2): five-source precedence, struck-field rule (`<ins>` wins over `<del>`; struck with no replacement reads empty and is not refilled from a lower source), money to the cent (R / nbsp / comma-or-space thousands / either decimal mark), dates only in explicit formats (day-first; never a free parse), percent, integer, month. `LeaseSigningLauncher` — `buildStepData()` and `buildFlow()` REAL (extracted from `RenewalDraftService`, behaviour-identical), `missing()`/`launch()` shells. `LeaseAgreementTemplateGuard` — shell with the final signatures `problemsFor()`, `assertUsable()`, `linkedFor()`; **fail-closed** (refuses every template, links none) until Build L0. `LeaseAgreementCheck::verdict()` → "no differences" (shell, as specified). `PreviousTermValuesReader::for()` — returns the term's own row or null. `LeaseCaptureService::capture()` and `LeaseAgreementHarvest::fromDocument()` — shells that throw `LogicException` naming the build that fills them (nothing calls them).
- **Events:** `Docuperfect\SignatureEnvelope{Sent,Finalized,Declined,Cancelled,Expired}` (scalars: `signatureTemplateId`, `documentId`, `agencyId`, `reason?`) and `Rentals\LeaseAgreement{Signed,Failed}`; **`Listeners\Rentals\UpdateLeaseSigningState`** registered for the five engine events in `AppServiceProvider` (one `LEASE-AGREEMENT` block) — inert; nothing emits the events until L3b.
- **`RenewalDraftService`** now delegates the flow builder to the launcher and, alongside `renewal_draft_flow_id`, mirrors `signing_flow_id`, `signing_status = prepared` and `agreement_template_id` onto the new term (§15.10 M2 "kept and mirrored"). The flow row it creates now also carries `lease_id`.

*Decisions the build had to make where the spec was silent (technical; the conductor may overrule):*
1. **`field_map` shape** — `{ "<registry key>": { "field": "<template field name>", "required": bool, "label": ?string } }`; the shorthand `"<key>": "<field name>"` is accepted. A key the registry does not know is an agency-specific extra (read as text, stored in `lease_agreement_terms.extra`).
2. **M5 reading of "prepared / out_for_signing from the flow and the envelope":** only DRAFT leases with a live (not soft-deleted) flow get an in-flight state; a cancelled/active/expired lease with a flow stays `not_sent`. The envelope's status is mapped with the §15.5 table (completed → `signed`, declined/rejected → `declined`, cancelled → `voided`, expired/lapsed/re_lapsed → `expired`, the amendment/approval states → `awaiting_agent_review`, anything else → `out_for_signing`). `source='esign_document'` leases become `signed` whatever their `status`; `accepted_by_user_id` is left null (who approved an old lease is not knowable).
3. **Recipient order is unchanged** (agent, landlord(s), tenant(s) — what the renewal flow has always written). R4's fixed order agent → tenant(s) → landlord(s) is Build L3a's.
4. **Migration numbering:** `…_1000nn` as §15.10 says; it sorts before the existing `2026_10_12_200000_add_viewing_pack_cover_settings`, which is harmless (independent tables).

*Not done in L1 (by the plan):* `Lease::signingStatusFor()`/`reconcileSigning()` (L3b), the lazy harvest in `PreviousTermValuesReader` (needs `LeaseAgreementHarvest`, L2/L3b), the domain-events catalogue rows (L3b's file list), every screen. **Not run:** M5 against a copy of Staging data (no Staging copy is reachable from a lane — it was exercised against fixtures for an e-signed lease, a paper renewal, drafts in each envelope state, a cancelled draft, a deleted flow and an unreadable flow, and re-run for idempotence).

*Tests:* `tests/Feature/Leases/LeaseFoundationTest.php` (21), `tests/Unit/Leases/LeaseAgreementValuesReaderTest.php` (32); unchanged and still green: `LeaseRenewalTest`, `RenewalDraftEligibilityServiceTest`, `RentalLeaseTemplateTest`, `LeaseFromSignedDocumentPromotionTest`, `LeaseCoreTest`, `LeaseHubTest`, `SingleListenerRegistrationTest`.

**BUILD NOTE — L0 Each agency's own lease agreement — 7 Oct 2026 (cc5, branch `cc5-lease-esign-L0-2026-10-07`, QA1).** BUILT. After L1 (landed 7 Oct, `9c84c443d`).

*Built (the §15.21 L0 list):*
- **`LeaseAgreementTemplateGuard`** filled (was the L1 fail-closed shell): `refusalsFor()` (not the agency's own / no owner / archived / not e-sign — the hard refusals), `problemsFor($template, $agencyId, ?$fieldMap)` (refusals + map + signing places), `assertUsable()`, `mapProblems()` (the registry's `link_requirements`), `statusFor()` (ready / needs_map / not_usable), `recordCheck()` (stores `validated_at` / `validation_problems`), `linkedFor()` (live; default first; residential), `signingRolesFor()` and `fieldNamesFor()` (read-only reads of a template — none of the pipeline-gated files is touched). An ownerless template (`agency_id = NULL`, including `is_global = true`) is refused for EVERY agency; another agency's template gets one generic message that names nothing.
- **Set-up page** (`Settings → Rental lease agreements`, `RentalLeaseTemplateController` + `index/create/edit` + new `_field-map`): the picker lists only the agency's own active e-sign templates (replaces `applySharedWith`, §15.22 #20); `store` 404s a missing or foreign id identically, refuses an ownerless/archived/non-e-sign one with its reason, and otherwise saves the row and records the check (a row with no map saves as *Needs field map*); one default per agency + category, taken under a row lock; a Ready / Needs field map / Not usable chip per row, worked out live; "Map fields" panel on the edit page, saved by `PUT …/field-map` (route `corex.rental-lease-templates.field-map.update`, in a `LEASE-AGREEMENT` marker): a dropdown of the template's own fields per registry key, "required for signing" only for agreement-term/schedule keys, a label only for schedule keys, suggestions that pre-select but are never saved without Save.
- **`php artisan templates:assign-agency {template_id} {agency_id}`** (`AssignTemplateToAgency`): idempotent, prints before/after, refuses a template another agency owns, refuses an unknown template/agency, audits through a new domain event `Docuperfect\TemplateAgencyAssigned` (the `RecordDomainEvent` ledger is the audit row; catalogue row added to `corex-domain-events-spec.md`). No agency id appears in code.
- **Setup Wizard row (rule #10a):** the `leases` step gets one information row with a link and a done / not-done chip — added through the step's existing `partial` list (`agency-setup/steps/rentals-lease-agreement.blade.php`), so the shared wizard renderer is NOT touched and the "small additive `link` control type" §15.14 anticipated was not needed. The row has no saver and posts no field.
- **Tests:** `LeaseAgreementTemplateGuardTest` (15), `LeaseAgreementReferenceMapTest` (5), `RentalLeaseTemplateTest` (20; 13 new), `AssignTemplateToAgencyCommandTest` (5), `LeaseSetupWizardLinkRowTest` (5), `NoHfcWordingInLeaseProcessTest` (2 — scans every file the series creates, including the ones later builds will add).

*Decisions the build had to make where the spec was silent or contradicted the code (technical; the conductor may overrule):*
1. **Signing places are read as the union of every place a template declares who signs** (its `signing_parties`, signature zones, a CDS `signature_section`/`inline_signature`, the blade source's `signature-line` / `signature-block` includes and `data-marker-party`, signature entries in `fields_json`), roles normalised (lessor → landlord, lessee → tenant, owner_party → landlord, acquiring_party → tenant). §15.12.5 predicts the repo's `template-121` is refused for "no signing place for the tenant"; it is NOT, because its blade carries an inline `signature-line` for `tenant` (`@include(… ['party' => 'tenant'])`) next to the block that lists only Lessor and Agent. The guard counts what the document actually prints. **Flagged for Johan/conductor: whether live's imported lease has a tenant signing place is still unchecked (the L0 pre-requisite of §15.12.4).**
2. **"Month-to-month capable"** (§15.12.3's "or the template must be month-to-month capable") is an explicit tick stored as `field_map._meta.month_to_month`; without it `end_date` must be mapped. The reader ignores `_meta`.
3. **Role / map problems do not block saving a row** — only the hard refusals do (not yours, archived, not e-sign). A row with a problem is saved and shown as Needs field map / Not usable; it simply never counts as linked (`linkedFor()` checks live every time).
4. **The field-map panel lives on the edit page**, reached from a "Map fields" link on each row, rather than literally beneath each row of the list.
5. **`LeaseAgreementReferenceMapTest`** proves the registry classification (4 lease / 6 contact / 5 calculated / 9 typed), that the reference map is linkable and that the reader reads all 24 places. It cannot test the calculated VALUES (rent in words, escalation in words, service fee with/without VAT, net to owner): no code computes them until the launcher seeds the document (Build L3a). That part of the §15.21 L0 test list is therefore deferred to L3a.
6. The button "Create lease & prepare for signing" is NOT rendered by L0 (the New Lease screen is Build L2's; §15.21 L2: "Button (b) is rendered and shows the §15.4 disabled message"). Until L2 lands there is no button (b) on any screen; `linkedFor()` is what it will read.

**BUILD NOTE — L2 Capture screen, lease-only path, paper copy, renewal on it — 7 Oct 2026 (cc5, branch `cc5-lease-esign-L2-2026-10-07`, QA1).** BUILT. After L1 and L0.

*What "Create lease & prepare for signing" does in this build — conductor ruling, 7 Oct 2026, because the §15.21 L2 plan never says (it only says button (b) "is rendered and shows the §15.4 disabled message until L0/L3a", written before L0 made the guard real):* with a linked, ready lease agreement the button is ENABLED; pressing it checks that every agreement detail the agency's own lease marks "required for signing" is filled in (nothing missing → the agent is told which and stays on the screen, nothing created), creates the lease as a **draft**, remembers which agreement it is for (`agreement_template_id`), and **stops**: no e-sign `flows` row, no document, nothing sent; `signing_status` stays `not_sent` (it is not "prepared" while nothing is prepared). The agent lands on the Lease Hub with "Preparing the signing document is not available yet, so no document was made." Build L3a puts the document step (gate on the contact side, launcher, fixed order, landing on Fill & review) behind the same intent; nothing in L2 has to be undone for it.

*Built (the §15.21 L2 list):*
- **One screen** `corex/leases/capture.blade.php` (+ `_agreement-fields`, `_signing-checklist`) for `mode=new` and `mode=renew`; `corex/leases/create.blade.php`, `renewal.blade.php` and `_renewal-term-fields.blade.php` are removed. Route names are unchanged (`corex.leases.create`, `corex.leases.store`, `corex.leases.renewal.create`); one new route `corex.leases.renewal.store` (`POST /leases/{lease}/renewal/capture`, inside a `LEASE-CAPTURE` marker). Layout follows the lease edit screen (§6a.i: `.prop-*` classes, constant `grid-cols-2`, per-field `col-span-2 sm:col-span-1`).
- **`LeaseCaptureRequest`** — the one validator. Every §7.2/§7.3 rule the old `store()` had is kept (a rental property the user may see — the picker's own query —, same-agency tenants and rental application, the plain "choose a property" message), plus: month-to-month and an end date are mutually exclusive (closes the capture half of §15.22 #7), rent/deposit bounds, and the agreement details validated by registry type — **only for the fields the agency's own linked lease carries**; anything else posted is dropped. A renewal ignores any tenant field (R7). Permission by mode lives here as well as on the routes, so the API is held to the same rule: new → `leases.create`, renewal → `leases.renew` + own/branch/agency scope. No `intent` posted = "Create lease only" (Enter-key submits, older callers).
- **`LeaseCaptureService::capture()`** — the one writer, one transaction: lease + tenants + `lease_agreement_terms` + a `lease_created` event; (a) optional activation (new → `LeaseActivationService`, renewal → `activateRenewalTerm`); the paper copy (R2: PDF filed `source_type='lease'` on the lease and property, `DocumentUploaded` fired, lease activated, `signing_status = signed_on_paper`, `source = uploaded_signed_copy`, `lease_signed_on_paper` event) on a new lease AND a renewal, with no lease agreement linked; an activation refused by the overlap guard (§3.5) rolls the whole capture back and the stored file is removed (closes §15.22 #1); `capture_key` (stored as sha256(user|key), exactly the 64 characters the column holds) makes a double-click or back-and-resubmit return the same lease. Also the screen's helpers: `agreementStateFor()` (ready / attention / none), `agreementFields()` (registry fields the map carries, in registry order, then agency extras; indexed families and lease/contact/calculated keys are not asked for), `missingForSigning()`, `renewalDefaults()`.
- **Button (b) availability (R3, §15.4):** disabled — never hidden — with the message directly beneath it when the agency has no linked lease agreement ("Your agency has not set up a lease agreement yet. An administrator sets one up under Settings → Rental lease agreements" — the words are a link for a user holding `rental_lease_templates.manage_settings`, "Ask your agency administrator" for everyone else) or when its lease needs attention ("Your lease agreement needs attention — <first problem>"). Hidden for a user without `access_docuperfect` + `create_docuperfect_docs`, and server-checked ("You do not have access to prepare agreements."). The server refuses a forged POST: no linked agreement → `NoLeaseAgreementLinkedException` (API `409 {code:"no_lease_agreement_linked"}`); another agency's or a made-up `agreement_id` → the guard's one generic message that names nothing.
- **Agreement section (§15.3):** shown only when the agency has a ready lease agreement, and only the fields its map carries (adults, maximum other persons, pets, escalation % and month, earliest termination date, renewal option, electricity arrangement, other conditions, the `other_deduction` schedule line with the agency's own label, and any agency-specific extras — stored in `lease_agreement_terms.extra`). More than one ready agreement → a picker with the default preselected and one field group per agreement (only the chosen group's inputs are enabled, so only its values post). The "Needed for signing" panel updates as the agent types. A linked rental application pre-fills the adults.
- **Renewal (L4, L5, R7):** same screen on the lease being renewed — property and tenants shown read-only, the §15.6.2 new-lease panel with "Start a new lease for this property" (→ New Lease with the property fixed) and the overlap line; start date = the day after the previous term ended, rent/deposit/lease type = the previous term's, agreement details from the previous term's `lease_agreement_terms`, the earliest termination date moved by the same offset as the start date; a detail the previous term does not hold (a wet-ink first lease holds none) is marked "Not on record — fill in". A lease that is not active gets a clean message, not an error page. The tenants are rebuilt from the previous term whatever is posted. The old renewal screen's one-click outcomes (month-to-month, tenant/landlord notice, their reversals) already live in the Lease Hub's action dialogs and are unchanged.
- **`uploadRenewal` (web and `api/v1/leases/{lease}/renewal/upload`)** now delegate to the same paper-copy path (older callers that send no deposit keep the previous term's, as before).
- **API (rule #7):** `POST /api/v1/leases/capture` (`v1.leases.capture`) — `intent`, `previous_lease_id` for a renewal, `capture_key`; `201 {lease, signing_status, redirect_url}`, `422 {missing:[{key,label,fix_url}]}`, `409 {code:"no_lease_agreement_linked"}`; in the Admin → API catalogue by name.
- **Edit lock (§15.13):** while `signing_status` is `out_for_signing` or `awaiting_agent_review`, the lease edit panel shows deposit, end date, month-to-month and lease type read-only with "This agreement is out for signing. Change values in the agreement — CoreX will ask you to confirm them at approval.", and `update()` refuses a forged edit with the same words. (Nothing sets those states until L3b/L3a, so today this only guards leases already in them.)
- **Leases list (§15.13):** an "Agreement" filter (not sent / being prepared / out for signing / needs my approval / signed / signed on paper / declined / voided / expired), a status sub-label under the badge for any lease that has one, and "Agreement status" + "Signed on" in the CSV/XLSX export and the print view. Default sort, search and empty states unchanged.
- **`LeaseAgreementTemplateGuard::readyAgreementsFor()`** added (additive, L0's file): every ready agreement, the default first; `linkedFor()` is now simply its first row (behaviour identical).

*Decisions the build had to make where the spec was silent or contradicted the code (technical; the conductor may overrule):*
1. **Button (b): see the ruling above.** The three ways it could have gone were put to the conductor; "create the lease, check the details, stop" was chosen.
2. **Not in L2 — the contact side of the gate and the landlord panel.** §15.3 lists a derived Landlord(s) panel and per-tenant "needs email / needs ID" hints, and §15.4 step 1 puts the signer gates (landlord present, every signer with email + ID/passport) in `LeaseSigningLauncher::missing()` — which the plan gives to L3a. L2's "Needed for signing" checklist therefore covers the required AGREEMENT details only. The commission % shown beside the service fee (§15.12.5 #22) is likewise not shown: the calculated values arrive with the launcher (L3a).
3. **`draftCopyForward` / `draftFromTemplate` (web and API) are unchanged** — the plan says they "delegate to the capture service", but their job today is to build the e-sign flow, and path (b)'s flow is L3a. Delegating them now would remove a working behaviour before its replacement exists, so they keep calling `RenewalDraftService`, and `validateTerms()` stays for those two. The renewal SCREEN no longer offers them: until L3a, an e-sign renewal can only be started through those API routes. **Flag for the conductor.**
4. **The lazy harvest in `PreviousTermValuesReader` is not built** (§15.7.3). It needs `LeaseAgreementHarvest::fromDocument()`, which the plan gives to L3b and the L1 shell says so; the L1 reader docblock says "L2". A renewal therefore pre-fills from the previous term's own `lease_agreement_terms` row only; a lease e-signed through CoreX before this series has no row, so its details show "Not on record". **Flag for the conductor.**
5. **The lease type field follows the agency's `show_lease_type_field` setting and the default deposit is a client-side suggestion** (months of rent × the agency's `default_deposit_months`, only until the agent touches the deposit box) — §15.3's table and §15.22 #8. The lease-type select therefore disappears from New Lease for an agency that has not switched the setting on (it is hidden by default, like the lease edit panel). Tests that rendered it were adapted to switch the setting on.
6. **The activate-immediately tick is ignored by (b)** ("button (a) only", §15.3) and the §12.5.2 withdrawn-property confirm is a client-side speed-bump on (a) and the paper copy.
7. **`signing_status` stays `not_sent` for (b)** while nothing is prepared; the "prepared" state is for a lease whose flow exists (L3a).
8. **Idempotency:** the screen mints a `capture_key` per render and keeps it across a validation bounce (`old()`); the API takes it in the body.
9. **A test-only quirk found:** inside ONE test, a second API call as a different user is still answered as the first (Sanctum keeps the first caller on its guard in the single shared app instance); a real request never does. The cross-agency API case is therefore its own test, with the rival as the first caller.

*Found, not changed (scope lock):*
- **The hub's "Record outcome" next-step card still links to `corex.leases.renewal.create`** (`LeaseHubService::nextStep`, the lease-ended-no-outcome case) — §15.6.1 says the entry points stay, but that page no longer carries the outcome cards, so the agent lands on a renewal form and finds month-to-month / notice in the page's "Lease actions" menu instead. A one-line change in L3a's own file (point it at `?action=month-to-month`, or at the menu) — not made here.
- `LeaseController::update()` still allows an end date together with month-to-month and cannot clear the lease type (§15.22 #7's `update()` half); only the capture screen closes that.
- `app/Models/PropertySettingItem.php:45` still names `leases/create.blade.php` in a comment.

*Not done in L2 (by the plan):* the launcher's `missing()`/`launch()`, the contact-side gate, the document step (L3a); engine events and linked completion (L3b); the change check and confirm screen (L3c); the Agreement card, hub next-step wording and "Prepare again" (L3a). **Not run:** `dev-check.ps1` (PowerShell is not available on this box).

*Tests:* `tests/Feature/Leases/LeaseCaptureTest.php` (new, 73 — both screens and every state of button (b); the agreement fields only for what the map carries and the second-agency/schedule case; (a), the lazy-but-valid minimum, each malformed value, idempotency, overlap rollback; (b) with details missing / present / forged / foreign agreement / no document access; the paper copy on a new lease and a renewal incl. rollback and file cleanup; the renewal screen, wet-ink predecessor, forged tenant field, activation with escalation, own-scope and cross-agency refusals; the API's 201/409/422; the edit lock; the Agreement filter, sub-label and export). Adapted: `LeaseCreatePropertySearchTest` and `LeaseTypeSettingTest` (switch the lease-type setting on), `LeaseFoundationTest` (capture() is no longer a shell), `NoHfcWordingInLeaseProcessTest` (scans the new request trait/exception files too). Unchanged and still green: the property-search suite (every §7.2/§7.3 behaviour), `LeaseRenewalTest`, `LeaseCoreTest`, `LeaseEditTest`, `LeaseActionDialogsTest`, `LeaseActionsMenuTest`, `LeaseListStandardTest`, `LeaseHubTest`, the guard, template and renewal-draft suites.

**BUILD NOTE — L3a Launch and the hub — 7 Oct 2026 (cc5, branch `cc5-lease-esign-l3a-2026-10-07`, QA1).** BUILT. After L1, L0 and L2. **No migrations, no permission keys, no settings.**

*What "Create lease & prepare for signing" does now (replaces the L2 stop):* it gates, creates the lease as a DRAFT, opens the e-sign flow for the agency's own lease agreement with the signers in the one fixed order (agent → tenant(s), primary first → landlord(s)) and sends the agent to **Fill & review (step 5)** of that flow. Nothing is sent to anyone — the agent checks the document and signs first. Gate, lease and flow are ONE transaction (`LeaseCaptureService::capture` → `LeaseSigningLauncher::launch`), so a gap rolls the whole capture back: nothing is created. Renewal uses the same button ("Renew lease & prepare for signing"): a NEW term chained to the old one, pre-filled from it, its own flow.

*Built (the §15.21 L3a list):*
- **`LeaseSigningLauncher`**: `missing()` (the gate: a landlord on the property; every non-company signer has an email AND an ID or passport number; a party's address only when the agency's own lease prints one; the agency's "required for signing" agreement details; a letting commission % when its lease carries a service fee — each gap has a label and a link to where it is fixed), `launch()` (guard at launch, §15.12.4 call site 4 → gate → flow → `signing_status = prepared`, `signing_flow_id`, `agreement_template_id`, `agreement_prepared` event; idempotent — a lease already `prepared` gets its same flow back), `recipientRows()` (the fixed order; each row carries the contact's own email/ID/address, shaped like the wizard's recipients step), `linkEnvelope()` (the hook), `closeOpenAgreement()`, `cardFor()` / `signersSummary()` (the hub card and the API), `partyGaps()` / `contactNeeds()` / `landlordsOf()` (shared by the gate, the capture screen's panel and its JSON).
- **`LeaseAgreementDocumentValues`** (new): the values written INTO the agreement through the field map — lease record (rent, dates, deposit), agreement terms and schedule, and the calculated ones: property description (`Property::buildDisplayAddress()`), rent in words (`AmountInWords`, whole rands), escalation in words ("seven point five"), **service fee = rent × commission % plus VAT only when the AGENCY is VAT-registered, at the agency's own `vat_rate`** (no fixed 15 %), net to owner = rent − fee − other deduction. Only keys the agency's map carries; an agency whose lease has no schedule gets none of it and is never asked for a commission %.
- **`ESignWizardController`** — two additive changes: `buildFieldsFromMappings` is now `public` (so the launcher lists the document's fields exactly as the wizard will, and seeds each value under its field's id), and one hook after `prepareSigning` records the envelope on the flow (`if ($flow->lease_id) { …linkEnvelope(...) }`, inside a `LEASE-AGREEMENT` marker, never throws): the lease receives `signature_template_id` + `agreement_document_id`, becomes `out_for_signing`, gets an `agreement_out_for_signing` event and the envelope a `lease_linked` audit row.
- **Capture screen:** a "Who signs" panel (landlord(s) from the property, each tenant, what each still needs, a link to the contact; refreshed as the property/tenants change through `GET /corex/leases/party-check`, route `corex.leases.party-check`), a "Still needed before the agreement can be prepared" list with a link per gap after a refused submit, and the "Letting commission (%)" box (only when the lease carries a service fee; starts at the property's own commission).
- **Lease Hub:** the Agreement card (`_agreement-card.blade.php`: state, which agreement, each signer in signing order with their status and last reminder, Continue / Open in e-sign / Approve / View signed copy / **Prepare again**), a header status chip, the context-bar chip, the lifecycle node "Lease signed" per §15.5, and next-step wording per state (Verify and sign the agreement · Waiting for … · Approve the signed agreement · Signed — activate · Agreement declined/cancelled/expired — prepare again). **"Record outcome"** now opens the hub with the month-to-month dialog open (the other outcomes are one click away in the same Lease actions menu) instead of a renewal form.
- **"Prepare again"** (`POST /corex/leases/{lease}/signing/prepare-again`, `corex.leases.signing.prepare-again`): after a declined / cancelled / expired agreement, a fresh agreement for the SAME lease from what the lease already holds, same gate and guard.
- **Cancel:** `LeaseController::cancel` and `LeaseRenewalController::cancelDraft` also close an open agreement — a flow nobody has sent is abandoned (soft-deleted), an envelope already out is cancelled exactly as e-sign's own "cancel document" does (links stop working, waiting parties are mailed) and the lease shows `voided` with an `agreement_voided` event.
- **API (rule #7):** `GET /api/v1/leases/{lease}/signing` (`v1.leases.signing`: status, signers in order, what is missing, where to continue); `POST /api/v1/leases/capture` with `lease_and_sign` now answers `201 {signing_status:"prepared", redirect_url: Fill & review}` or `422 {missing:[{key,label,fix_url}]}`.

*Decisions the build had to make where the spec was silent or contradicted the code (technical; the conductor may overrule):*
1. **The six contact places are not seeded by the launcher.** §15.18 #18 says "the launcher seeds all 24 places"; the wizard already fills names, addresses and IDs from the recipients' own rows (which now carry them), and seeding them under the template's field ids would override the wizard's per-recipient expansion for two or more parties. The launcher seeds the 18 others (4 lease, 9 agreement/schedule, 5 calculated). To be confirmed in the browser: the party names, addresses and IDs appear in Fill & review.
2. **Steps 2–4 need no re-save** (§15.4 step 5 build-time check) — established from the code, NOT in a browser: `showStep` takes the step from the URL; the flow already carries the template, property, recipients (each with `_contact_id`, email, ID) and details; recipients are only auto-populated from the property when there is no non-agent recipient; `prepareSigning` re-sorts signers agent → tenant → landlord itself. If the browser shows otherwise, land on step 3 instead (`LeaseSigningLauncher::LANDING_STEP`).
3. **A company (entity) signer is not asked for a personal email/ID** by the gate — it signs through its representatives, which the engine checks itself when it sends.
4. **"Prepare again" re-launches from the lease's saved values** rather than reopening the capture screen (§15.13 wording) — nothing is typed twice and anything missing is listed with links; a screen that edits a lease already created would be a second writer.
5. **The envelope-cancel half of `closeOpenAgreement` duplicates `ESignWizardController::cancelDocument`'s changes** until Build L3b extracts `SignatureService::cancelEnvelope()` (§15.15) — it is a marked, single method to replace.
6. **`out_for_signing` is set when the agent prepares signing** (the hook), as §15.4 step 6 says; the later states (`awaiting_agent_review`, `signed`, `declined`, …) are Build L3b's events and are not set by anything yet.
7. **Commission %**: a synthetic agreement field `commission_percent` (type percent, stored in `lease_agreement_terms.extra`) appears in `LeaseCaptureService::agreementFields()` only when the map carries `agent_service_fee` or `net_to_owner`.
8. **Next-step "Waiting for …"/"Agreement prepared by …"** are statements (no link), so `nextStep()` may now return `route_name = null`; `LeaseHubService::nextStep($lease, $user)` takes the viewer so a flow only its owner can open is not offered to anyone else.

*Found, not changed (scope lock):*
- `WebTemplateDataService` still adds a fixed 15 % VAT to its own service fee (`:139-140`) — not used by this process (the launcher seeds its own), but any other template reading `service_fee` from the resolver still gets the fixed rate.
- `LeaseController::update()` still allows an end date together with month-to-month and cannot clear the lease type (§15.22 #7 — reported by L2).
- `app/Models/PropertySettingItem.php:45` still names the deleted `leases/create.blade.php` in a comment (reported by L2).

*Not done in L3a (by the plan):* the engine events and linked completion (L3b), the document-versus-lease check and confirm screen (L3c), the lazy harvest for old e-signed leases (L3b). **Not run:** a real-browser click-through (Johan's), `dev-check.ps1` (PowerShell is not available on this box).

*Tests:* `tests/Feature/Leases/LeaseSigningLauncherTest.php` (new — the gate and its fix links, the fixed order with joint tenants added out of order, each signer's own details, Fill & review seeding incl. calculated values and the 24-place reference lease, VAT-registered and not, no-schedule agency, the guard at launch for another agency's and an ownerless template, double press, the hook, cancel with the document unsent and out, Prepare again); `LeaseCaptureTest.php` (button (b) end to end, gap rollback + list, party panel/party-check, commission box, renewal agreement for the new term, API 201/422/status/404); `LeaseHubTest.php` (Record outcome, next step per state, lifecycle, the card); `LeaseFoundationTest.php` (launcher shells removed); `NoHfcWordingInLeaseProcessTest.php` (scans the new files).

**BUILD NOTE — L3b What happens after signing — 7 Oct 2026 (cc5, branch `cc5-lease-esign-l3b-2026-10-07`, QA1).** BUILT. After L1, L0, L2 and L3a. **No migrations, no permission keys, no settings, no new routes or screens** (so nothing for the Setup Wizard, the sidebar or the API catalogue).

*What changed for the user:* once the agent has signed, the lease now follows its agreement all the way. The status on the Lease Hub card, the list and the header moves **Out for signing → Needs my approval → Signed** (or **Declined / Cancelled / Expired**) on its own. When the agent presses the engine's own Approve and the signed copy is filed, the lease becomes **signed, accepted and active** with the dates that were captured, its signed copy is on the Lease Hub card (download the signed copy, download the signing certificate) and in the property's documents, the previous term of a renewal is expired with its escalation recorded, the agent gets an in-app note and one mail, and the agreement's printed values are read back so the next renewal pre-fills from what was actually signed. A declined, cancelled or expired agreement leaves the lease a draft, tells the agent why (declined / expired), and **"Prepare again"** is now reachable.

*Built (the §15.21 L3b list):*
- **The e-sign engine announces five things** (`SignatureService::announceEnvelope()`, one helper, try/catch, fire-and-forget): `SignatureEnvelopeSent` at the end of `sendSigningRequest()`; `…Finalized` inside `recordFinalizationSucceeded()` — **the one recorder both the synchronous cascade and `FinalizeSignedDocumentJob` already share**, so both finalisation paths announce from exactly one place, after the signed copy is filed (this replaces the spec's "the two lease-hook sites `:4082`/`:4302`" — those stay for unlinked documents); `…Declined` after `declineRequest()` commits and from `SignatureController::reject()` (archive → "Rejected — …", revise → "Returned for revision — …"); `…Cancelled` from the new `SignatureService::cancelEnvelope()`; `…Expired` from `expireOutstandingRequests()` (only when no signer can still sign) and `lapseExpiredCeremonies()` (the legal-deadline lapse).
- **`SignatureService::cancelEnvelope()`** — extracted from `ESignWizardController::cancelDocument()` (which now delegates, behaviour unchanged: same status, audit entry with IP/agent, waiting parties' links stop, same mail, same flash text) and used by `LeaseSigningLauncher::closeOpenAgreement()` (its private copy is gone).
- **`createLeaseFromSignedDocument()`** (the address-and-tenant fallback for documents NOT launched from a lease): returns early for a document that is linked to a lease; a matched draft no longer has its rent overwritten with 0, its start date with today, or its end date with blank (§15.22 #4); a new lease made from a document is created as a draft and activated through `LeaseActivationService` (one active lease per property, the let-out flip), staying a draft if the property already has an active lease (§15.22 #2, #3).
- **`LeaseSigningStateService`** (new; the one implementation behind both the listener and the safety net): `apply()` follows the envelope (`Lease::signingStatusFor()` — the §15.5 mapping in one place; a completed envelope counts as signed only once its signed copy is filed) and is idempotent, moving only a lease whose agreement it is and which is still in flight. `finalise()` (one transaction, locked row, idempotent on `signed_at`): `signed_at`, `accepted_at` (= the moment the document completed, which is the agent's approve action), `accepted_by_user_id` (whoever is signed in, else the agent who sent it), `source = esign_document`, `source_document_id`; events `agreement_signed`, `agreement_accepted`; harvest; the `LeaseAgreementCheck::verdict()` gate (a shell until L3c: no differences ⇒ activates; a difference ⇒ `signed` + `draft` + `agreement_needs_confirmation`); activation (a renewal through `activateRenewalTerm`, so the previous term is expired and the escalation recorded); `lease_activated_by_signing`, or `signed_not_activated` when another lease still holds the property. `fail()` → `declined` / `voided` / `expired` with the reason on the lease and a LeaseEvent. Domain events `LeaseAgreementSigned` / `LeaseAgreementFailed`; the agent is told through the in-app bell and one `LeaseAgreementStatusMail` sent through the agency mailbox path (`RentalMailDispatcher`), never for a cancel they made themselves.
- **`UpdateLeaseSigningState`** (was inert since L1): finds the lease by `signature_template_id` (one indexed look-up, then out for any envelope that is not a lease's), hands it to the service, swallows and logs every fault. The "request just sent" announcement is applied as *out for signing* whatever the envelope still reads — the engine emits it from inside the step that invites the next signer, before it advances the envelope's own status.
- **Safety net:** `Lease::reconcileSigning()` (re-reads the envelope; never throws), called when the Lease Hub opens and for every in-flight lease on a Leases-list page, and the nightly `leases:reconcile-signing` (`ReconcileLeaseSigning`, 00:20, agency by agency, idempotent).
- **`LeaseAgreementHarvest`** (was a shell): reads the agreement's printed values through the reader and the agency's map and writes `lease_agreement_terms` (typed columns, the agency's own extra keys in `extra`; never the lease's own columns, the people or the calculated values; a blank or unparseable value never wipes a stored one). **`PreviousTermValuesReader`** lazy fallback (§15.7.3): a previous term e-signed before this series, with no terms row, is harvested on first read through the map of the agreement that produced it and the row is written once; with no map known it returns nothing.
- **Lease Hub:** "Signed — filing the document" while the approved agreement's signed copy is still being filed; "Signed — another lease is still active on this property. End or renew it, then activate." when activation was refused; the signed-copy card gains "Download signing certificate".
- **Catalogue:** `corex-domain-events-spec.md` — the five engine events, the two rental events and the listener.

*Decisions the build had to make where the spec was silent or contradicted the code (technical; the conductor may overrule):*
1. **`Finalized` is announced from `recordFinalizationSucceeded()`**, not from the two lease-hook call sites the plan lists — it is the single choke point both finalisation paths already share (§4 prevent-or-absorb: guard the primitive, not each call site).
2. **`accepted_at` = the envelope's `completed_at`** and `accepted_by` = the signed-in user at that moment (else the agent who sent it): the engine sets `completed_at` inside the agent's approve action, so there is no separate approval timestamp to read, and the asynchronous finalisation path has no signed-in user.
3. **`awaiting_agent_review` is set by the safety net, not by an event** — the engine has no announcement at the moment the last recipient signs (§15.15 lists five). The Lease Hub, the Leases list and the nightly command repair it; the card's signer list is live from the envelope regardless.
4. **The Command Centre does not re-check** — it is a SQL summary over properties and shows no agreement state, so there is nothing stale to show there; the Lease Hub, the list and the nightly command cover §15.15's other callers.
5. **`signed_at` is also the idempotency key**, so an envelope that completes twice (the engine can announce twice) changes nothing the second time and mails the agent once.

*Found, not changed (scope lock):* `CheckLeaseExpiryCommandTest` — two tests fail identically on a clean `origin/QA1` (verified on a throwaway checkout, since removed); not caused by this build. The M5 back-fill was **not** re-verified on a copy of Staging data (no Staging copy is reachable from a lane) — it ran for real on QA1 in L1.

*Not done in L3b (by the plan):* the document-versus-lease check, the fingerprint and the confirm screen (L3c) — `LeaseAgreementCheck::verdict()` is still the shell, so completion simply activates. **Not run:** a real-browser click-through (Johan's), `dev-check.ps1` (PowerShell is not available on this box).

*Tests:* `tests/Feature/Leases/LeaseSigningReconcileTest.php` (new, 53 — the status mapping for every engine status; approval signs/accepts/activates with dates and events; agent notified; signed copy found; the same completion twice; a different approver; a renewal expires the previous term and records the escalation; another lease active; a cancelled lease; a mail and a listener that fail; out ⇄ waiting-for-approval; completed-but-still-filing; declined / cancelled / expired; late announcements ignored; an envelope that is not a lease's; cancel with the agreement out records one void; repair on opening the lease, on the list, on a missed finalisation and by the nightly command across two agencies; the harvest incl. a blank never wiping a value and no-map-no-guess; the lazy fallback for an old e-signed lease); `tests/Feature/Docuperfect/SignatureEnvelopeEventsTest.php` (new, 14 — each emission point once with the right ids, the cancel screen action unchanged, only the creator can cancel, reject archive/revise, both finalisation paths through the one recorder, a throwing listener never breaks a decline/cancel/finalisation/send/lapse); `LeaseFromSignedDocumentPromotionTest.php` (+3 — a linked document is never matched by address; no rent/dates overwritten by a document that lacks them; no second active lease); `LeaseFoundationTest.php` (the harvest shell and the inert-listener tests updated); `NoHfcWordingInLeaseProcessTest.php` (scans the new files).


**BUILD NOTE — L3c The change check and the confirm screen — 7 Oct 2026 (cc5, branch `cc5-lease-esign-L3c-2026-10-07`, QA1).** BUILT. After L1, L0, L2, L3a and L3b. **No migrations, no permission keys, no settings** (nothing for the Setup Wizard); one new screen state and one new API endpoint (named, versioned, in the Admin → API catalogue automatically); no sidebar entry (the screen is reached from the Lease Hub and from the approve click).

*What changed for the user:* if the lease agreement is changed in e-sign after it was prepared — by anyone, by any route (the agent in Fill & review, a signer, a reworded clause) — the lease no longer takes it silently. At the agent's final approval, if anything the agreement prints no longer matches the lease, the Approve click opens the lease screen instead, showing each field with **"Lease says R8 500 · Agreement says R6 940"**. One button, **"Confirm lease details and approve"**, writes the accepted values to the lease (each change logged old → new with the agent's name) and then lets e-sign approve as always. Nothing changes if they leave. A different person's name, ID or address printed in the agreement blocks (a change of tenant is a new lease); a value the agreement does not show clearly becomes a box to type into. If nothing differs, there is no extra screen at all. If an agreement is signed by a route that skips the Approve click, the lease is **signed but stays a draft**, the hub says "Signed — confirm the lease details", and the same screen has "Confirm and activate".

*Built (the §15.21 L3c list):*
- **`LeaseAgreementCheck::verdict()`** (was the L1 shell) — reads the printed values through `LeaseAgreementValuesReader` and the agency's map and compares each mapped key with the lease side: rent / dates / deposit with the lease, agreement terms and agency extras with `lease_agreement_terms`, names / IDs / addresses with the contacts (tenants primary first, landlords as the property gives them), calculated values (words, fee, net, property description) recomputed by `LeaseAgreementDocumentValues` from the figures **as they would be once accepted**. One row per key: `agree · differs · blocked · cannot_verify · informational`. Money to the cent, dates by value, text whitespace/case-insensitive. Read-only. The `fingerprint` is sha256 of every mapped key's printed text.
- **`LeaseAgreementConfirmService::confirm()`** — the one place a document value reaches the lease. One transaction, re-reads the agreement, refuses on a changed fingerprint / a different person / a value still to be typed / dates that would not make a valid lease, writes `leases` + `lease_agreement_terms` (`source = confirmed`) + `agreement_confirmed_*` + one `agreement_differences_confirmed` event per changed field (old, new, who, typed-or-read). `mayConfirm()` is the single permission rule (the agent who sent it, or `leases.create`, inside own agency + lease scope).
- **`EnsureLeaseAgreementConfirmed`** middleware (alias `lease.agreement.confirmed`) on the engine's `docuperfect.signatures.approveAndAdvance` route line only — **the engine's controller is untouched**. Acts only for a lease-linked agreement at `pending_agent_approval` and only for a user who may confirm; passes straight through otherwise.
- **`LeaseAgreementConfirmController`** + `capture.blade.php` `mode=confirm` (`_agreement-confirm.blade.php`) — `corex.leases.agreement.confirm` (GET, also "Re-check") and `…confirm.store` (POST, "Confirm and activate"). Stages: `approve`, `activate`, `preview` (agreement still out: look, not confirm).
- **`LeaseSigningStateService`** — `finalise()` now runs the check **before** the harvest (the harvest would write the document's values into the lease's agreement details and hide every difference), harvests only when clean, otherwise leaves the lease signed + draft with `agreement_needs_confirmation`; a check that cannot run is treated as "needs confirming". New `activateConfirmed()` (stamps the confirming agent as the approver, R5, and activates through the same `goLive()` as an ordinary signing). `reconcile()` also writes `agreement_edited` ("The agreement was changed in e-sign: Monthly rent R8 500 → R6 940") the first time a difference is seen and again only after a further edit (deduplicated by fingerprint) — information only.
- **Lease Hub:** next steps "Agreement changed — review before approving" and "Signed — confirm the lease details"; the Agreement card gains "Review changes" / "Confirm the lease details".
- **API:** `GET /api/v1/leases/{lease}/signing` now returns `differences` + `fingerprint`; `POST /api/v1/leases/{lease}/signing/confirm` (`v1.leases.signing.confirm`) — 200 / 409 `agreement_changed_again` / 422 `different_person_in_agreement|value_needed|value_not_acceptable|no_agreement_to_confirm`.

*Decisions the build had to make where the spec was silent or contradicted the code (technical; the conductor may overrule):*
1. **Check before harvest.** L3b's `finalise()` harvested first; with a real check that makes every agreement-term difference vanish. The harvest now runs only when the agreement and the lease agree (and the confirm itself writes the terms). One L3b test (`…harvest_runs_at_approval…`) asserted the silent overwrite and was changed to the ruled behaviour: corrected value reaches the lease **through the confirmation**.
2. **Fail-open on the route, fail-closed at completion.** A fault in the check on the approve click is logged and the approval goes through (a bug must not block a legally valid approval); the completion step re-checks on every path and, if it cannot, leaves the lease signed but a draft.
3. **Only the final approval is checked** (`pending_agent_approval`); the same route also approves-and-passes-on to the next party in other flows and those are left alone.
4. **A mapped field the document prints nothing for, where the lease holds a value, is "cannot verify"** (§15.8.3) — so an agreement whose template never prints a field its map declares will open the confirm screen at every approval until the map is corrected. Deliberate: never silent. (L3b/L3a seeded values do print in the reference templates.)
5. **Party matching is strict about WHO and tolerant about HOW**: word order, case, punctuation, titles (Mr/Mrs…), connectors, an ID number beside a name, and a company followed by its representative are the same person; a missing or an extra person is not. An agreement that prints joint parties in separate boxes but is mapped as one combined key blocks — the screen then says to map them one by one (Settings → Rental lease agreements).
6. **Calculated values are informational only** and never open the screen on their own (no lease column to update).
7. **"Review changes" shows only when there is a real difference**, not a cannot-verify; the Approve step uses either.
8. **"Confirm and activate" does not re-harvest** (the confirmation already wrote the terms); `accepted_at/by` is the confirming agent, at that moment.
9. The spec's `Lease::agreementState()` is supplied as `LeaseHubService::awaitingConfirmation()` + the card's `review_url` / `confirm_url`; no new model method was needed.

*Found, not changed (scope lock):* (a) `CheckLeaseExpiryCommandTest` — L3b's "2 of 7 fail on a clean checkout" was **stale tests, already fixed** by `3b6ba7ca9` (7 Oct 09:33, test file only; the command is untouched); all 7 pass. (b) `ImapMailboxPoller::connect()` (inbound mail poll, every 5 min) has no environment guard — outbound SMTP and the IMAP Sent-folder append are hard-gated off QA1 (`OutboundMailGuard::isSendingConfirmed()`), the inbound poll is not. Reported to the conductor. (c) L3b's "QA1 mail goes out for real through the agent's mailbox" is wrong: `PerMailboxMailTransportBuilder` redirects to Mailpit and `ImapSentFolderAppender` simulates, on every environment off the production/staging allowlist (QA1's own log shows both).

*Not done / not run:* a real-browser click-through (Johan's); the Alpine render gate and `dev-check.ps1` (no PowerShell here; the render gate belongs to the conductor after landing); **no real e-sign document made from an HFC-mapped template exists on QA1 yet, so the check has not been run against one** — the first real pass is Johan's test script's step 3 ("sign, nothing changed → Approve goes straight through"); if the party names or dates print differently from what the fixtures assume, that step shows the confirm screen on an unchanged agreement.

*Tests:* `tests/Feature/Leases/LeaseAgreementCheckTest.php` (new, 38 — every §15.8.3 row, one case per edit route, printed-another-way, cannot verify, typed values, different/extra/missing person, joint parties, company, month-to-month, deposit unmapped, calculated informational, fingerprint, wording changes, no document / no map / partial map, writes nothing); `LeaseAgreementConfirmTest.php` (new, 36 — the screen, nothing written before the button, the approve route with and without a payload, stale fingerprint, different person via a forged post, typed values, invalid dates, part-way approval left alone, a non-lease document, scope, the completion net incl. a check that fails, confirm-and-activate incl. another active lease and a double press, the `agreement_edited` note once, hub and card, API); `BuildsLeaseAgreementFixture.php` (shared); `LeaseSigningReconcileTest.php` (one test adapted — decision 1); `LeaseFoundationTest.php` (the L1 shell test replaced); `NoHfcWordingInLeaseProcessTest.php` (scans the new files). Mutation-checked: removing the redirect, running the harvest before the check, and accepting a different person each turn exactly the matching test red.

### 15.25 Approved application → lease capture (7 Oct 2026, cc2 — Johan's QA1 test; replaces §1.3a)

**What was wrong.** `RentalApplicationController::linkTenantProperty()` (the approved screen's only caller) wrote the tenant link AND created a `Lease` in one request, activated it at once (`LeaseActivationService`), took rent/deposit from a form pre-filled with the *approved* amount (a 2026-09-22 ruling), and defaulted the start date to today. The §15 capture screen and the e-sign agreement never came into play, and the hub's "Lease signed" node counted any active non-e-sign lease as signed.

**Now.**
1. On an approved application the picker (property search, with Searching… / "No rental properties match" states) submits a **GET** to `corex.leases.create?property_id=&rental_application_id=`. Nothing is written by choosing a property or by cancelling.
2. The capture screen opens pre-filled: the property, the applicant as primary tenant, the landlord panel, **rent = the property's own rent** (blank and flagged when the property has none — never the approved amount), **deposit = the approved deposit terms** (approved deposit ÷ approved rent, applied to the lease rent; the property's deposit when the application has no approved pair), start/end left for the agent. The application must be visible to the user under their own rental-application scope.
3. **Rent above the approved amount** — `RentalApplication::rentAboveApproved()` is the one definition. The screen shows both figures and the difference; `LeaseCaptureRequest` enforces it server-side: agency setting `rental_application_qualifying_settings.rent_above_approved_mode` — `warn` (default; a reason is required) or `block` (refused). The reason is written to the lease history (`LeaseEvent rent_above_approved_confirmed`) and the application audit trail. The setting is in Settings → Rental applications and the Setup Wizard's rentals step.
4. The existing three ways forward are unchanged: signed paper copy (active, signed on paper), "Create lease & prepare for signing" (draft, e-sign), "Create lease only" (draft; "Activate immediately (skip draft)" stays an explicit tick).
5. **Completing the screen** (`LeaseCaptureService::linkApplicationToLease`) points the application's `property_id` at the lease's property (an approved application may pick a different property than it carried), links the applicant as the property's tenant (`ContactPropertyLinker`, `ContactLinkedToProperty` event) and audits it. Renewals and leases without an application are unaffected.
6. **"Lease signed"** on the hub strip for a lease whose `source = rental_application` needs a signed agreement or paper copy (`signed_at`, `signing_status` = signed / signed_on_paper); being active is not enough. Other sources keep the §15.5 rule.
7. The old `link-tenant-property` POST remains as a link-only endpoint (tenant link, redirect to the capture screen); it never creates a lease and the screen no longer calls it.

**Existing leases are not touched.** A lease created by the old flow stays as it is; its rent and start date are not editable on the lease edit screen (§6a) — archive it and capture again.

**Tests:** `tests/Feature/Leases/LeaseFromApprovalTest.php` (replaces the §1.3a tests), `RentalApplicationRentDepositPrecedenceTest.php` (precedence ruling superseded).

### 15.26 Lease screen links, Tenancy-log filters, Rentals-tab dates (7 Oct 2026, cc2 — Johan's QA1 test)

- **Links open in a new tab.** On the lease screen the property address, each tenant and each landlord are links (`target="_blank" rel="noopener"`), shown only to a user holding `properties.view` / `contacts.view`; so the agent never loses the lease screen.
- **Tenancy-log type boxes are filters.** They were read as status ticks. They are now compact chips under a "Show:" label; with none on, every type is shown ("All types shown"); a "Clear filters" link appears when any filter is active; ticking a chip filters at once. Nothing is auto-ticked — "Lease signed" on the stage strip is decided by `LeaseHubService::lifecycle()` (§15.5: a signed agreement, a paper copy, or — for sources other than a rental application — a lease that is no longer a draft).
- **Property → Rental tab dates, one source of truth.** While a lease is ACTIVE on the property, Lease Start/End Date on the tab show that lease's dates, read-only and not posted. Only with no active lease are they the property's own editable dates (they are also the portal "available from" date — rental-renewals.md §19 — which is why they stay editable then). The tab's rental-details form is laid out as four label-over-input columns, compact, with no static helper text (an agency's own custom-field help text still shows).
- Tests: `tests/Feature/Leases/LeaseScreenLinksAndRentalTabTest.php`.


## 16. Lease screen — Tenant / Landlord portal access cards (7 Oct 2026, QA1)
The two cards at the foot of the lease screen's right panel show, per tenant / landlord of THIS lease, the portal
login's status and the personal portal link with Copy, Email link / Resend invite and WhatsApp share, and one
"Set up portal access & email the link" action when there is no login. A signed lease gives its parties portal
access automatically (agency setting, default ON, in the Setup Wizard) and its signed-copy email carries their
link. Behaviour, rules and tests: `.ai/specs/rental-portal-access.md` §16. View: `corex.leases._portal-access-person`.

Paper-signed copy (7 Oct 2026): attaching a signed paper copy (New Lease, Renewal, renewal upload, APIs) also emails the tenant(s) and
landlord(s) the copy with their portal link, once per lease — `.ai/specs/rental-portal-access.md` §18.

Portal (7 Oct 2026): a signed lease's agreement (e-signed final PDF or the wet-ink copy) is listed in the tenant's and owner's portal Documents
area; unsigned and archived leases never are — `.ai/specs/rental-portal-access.md` §19.

## FICA on the lease path — warns, never stops (8 Oct 2026, cc3)
Johan's QA1 rentals test (7 Oct): FICA stopped the agent where it should have warned. Ruling (8 Oct, relayed by the conductor): the FICA gate lifts on **submitted**, same as sales. On the lease path nothing the agent does is stopped by FICA: linking the tenant's property, creating the lease (capture screen), activating it, sending the agreement for signature, and setting up tenant/landlord portal access all go through whatever the FICA state. The capture screen's "Who signs" panel (`party-check` → `fica`) and the lease screen (`ficaWarnings`) show a plain warning with the link to request/complete FICA for any tenant or landlord who has not submitted; a lease that is expired or cancelled shows none. The only stop is the tenant's/landlord's OWN signing page when the agent left "FICA verification required before signing" ticked — the same external signer gate sales uses. One shared rule: `App\Services\Compliance\FicaGate`; full write-up and the list of intended hard stops in `.ai/specs/compliance.md` §"The FICA gate". Tests: `tests/Feature/Leases/LeaseFicaWarnNotBlockTest.php`.

## 17. Two agents on every lease — the owner's agent and the tenant's agent (8 Oct 2026, cc1 — Johan's ruling; QA1 only)

**Ruling (Johan, 8 Oct 2026, verbatim):** "we need to on lease know who the rental agent is for the owner and tenant - it does not mean that if retha is advertising the property that its her tenant... retha is the listing agent, maggie is the tenant agent. but I would still on lease show the agents as such and it can be changed if need be. and that selection is who is shown on the tenant and owner links."

**What was wrong.** A lease had no agent of its own. The portal "who to call" (rental-portal-access.md §20) therefore guessed: the agent who approved the signed agreement, else the one who captured it, else the property's agent — and the property's agent (the one who *advertises* it) was being read as the tenant's agent too.

### 17.1 Data
`leases.owner_agent_user_id` and `leases.tenant_agent_user_id` — nullable FK to `users`, null-on-delete (migration `2026_10_15_000600_add_owner_and_tenant_agent_to_leases`; columns only, no data written by the migration). The two may be the same person. Relations `Lease::ownerAgent()` / `tenantAgent()` (global scopes off, trashed kept, so a screen shows the name of an agent in another branch or one who has left). No new permission, no new setting (so nothing for the Setup Wizard, rule #10a): changing them uses `leases.create`, the key that already edits a lease.

### 17.2 Defaults at creation — `App\Services\Rentals\LeaseAgentService` (the one place that knows)
* **Owner's agent** = the property's primary agent (`properties.agent_id`, the one who lists / advertises it). If the property has none (or they are no longer an active user of the agency): whoever captured the lease.
* **Tenant's agent** = the agent who **sent out the rental application** that led to the lease (`rental_applications.created_by_user_id` — the invite is mailed from that agent), else the agent who **processed / approved** it (the latest `rental_application_status_history` row to `approved`, else the latest row with a user), else **whoever created the lease**, else the owner's agent.
* Only an **active user of the lease's own agency** ever qualifies at any step (never another agency's, never someone who left or was deactivated); every lookup pins the agency itself, no global scope involved.
* Capture screen (new lease and renewal) shows both as dropdowns, preselected; the owner's agent follows the property picked until the agent chooses one; a blank side takes the default. Posted values are validated by `LeaseCaptureRequest` against the same list the dropdown is built from.
* The rule that picked each agent is stored in the `lease_created` history row (`owner_agent_rule`, `tenant_agent_rule`: `property_agent`, `application_sender`, `application_approver`, `lease_creator`, `owner_agent`, `previous_term`, `chosen_on_screen`).
* Take-on import (`RentalTakeOnConfirmService`): the spreadsheet row's named agent (else the person running the import) stands in for "who created the lease" — owner's agent = the property's agent, tenant's agent = the row's agent.
* **Renewal carries both forward** from the term being renewed (`LeaseRenewalService::createRenewalTerm`, so every renewal path does, not only the capture screen); the renewal capture screen shows them preselected and they may be changed there. A term that never had agents carries what the default rules give for it.

### 17.3 Where they show, and changing them
* **Lease screen:** an "Agents" card in the right panel (owner's agent / tenant's agent; "(default)" beside a lease whose columns were never filled — it shows what the rules give). Someone holding `leases.create` sees **Change** → two dropdowns → **Save agents** (`PUT /leases/{lease}/agents`, `corex.leases.agents.update`; own/branch/agency guard; a lease of another agency is a 404). Both required; only an active user of the lease's agency can be chosen; the dropdown lists the lease's branch first and respects Split Branches.
* **Leases list / print list / CSV-XLSX export:** an Agents column (owner / tenant).
* **History:** every change writes one `lease_agent_changed` row per side changed to the tenancy log — who (`actor_user_id`), from, to (ids and names in `metadata`), when (`occurred_at`). Saving the same agents again writes nothing.

### 17.4 The portal "who to call" (replaces the approver / creator fallback of rental-portal-access.md §20)
The **tenant** portal shows the lease's **tenant's agent**; the **owner** portal shows the lease's **owner's agent**; if that person is not an active user of the agency → the **property's agent** → the **branch alone** (the office block is always there). A lease whose columns were never filled is read through the same default rules (`LeaseAgentService::effectiveIds`), so an old lease and a new one answer alike. Neither side is ever shown the other side's agent.

### 17.5 "Own" scoping
Before this, "own" for leases was derived from `created_by_user_id` only (`Lease::scopeVisibleTo` and the per-record `AuthorizesRentalRecordScope` guard). Now **own = I created it, OR I am its owner's agent, OR I am its tenant's agent** — in the list query, the tiles, the export and the direct-URL guard (`Lease::isOwnedBy`), so they cannot disagree. Changing an agent therefore moves the lease in and out of that person's own list. Branch and agency scope are unchanged. **Not changed (reported):** other rentals screens that derive "own" from a lease's creator or the property's agent — the inspections-due board and its reminder recipient (`RentalInspectionDueService::responsibleAgentId`), and the calendar (`RentalCalendarSource`) — see the build report.

### 17.6 Back-fill of existing leases — `php artisan leases:backfill-agents`
Run by hand, never by a deploy: `--dry-run` first, `--agency=` / `--lease=` to limit, `--revert` to undo. It fills only an **empty** side (a chosen one is never overwritten), by the same default rules, writes through the query builder (no model events, `updated_at` untouched), logs one `lease_agents_backfilled` row per lease (person and rule per side), and prints counts per rule per side. `--revert` empties exactly the sides it filled and only while they still hold what it wrote and no `lease_agent_changed` row has touched that side since.

### 17.7 Mail routing — NOT changed here
Which agent lease mail goes "from" / cc'd to today, and the proposed mapping, is in the build report (`/tmp/qa1-cc1-lease-agents-2026-10-08.md`); no mail routing was altered.

### 17.8 Multi-agency (rule #9)
Nothing here names an agency: the agent lists, defaults and portal answers are all per the lease's own agency, an agent of another agency is never offered, defaulted or shown, and the wording is neutral.

**Tests:** `tests/Feature/Leases/LeaseAgentsTest.php` (defaults and every fallback, capture dropdowns, change + history, permission, cross-agency, own scope, renewal, back-fill + revert), `tests/Feature/RentalPortalAccess/PortalHomeTest.php` (the right agent per side, fallbacks, another agency's agent never shown).

### 17.x Portal consumer of the agreement terms (8 Oct 2026, QA1)
`lease_agreement_terms.earliest_termination_date` (row 16 of the §15.12 field map — "earliest date notice may expire") and, where an agency's own agreement map declares them, `extra.notice_period_days` / `extra.early_cancellation_terms`, are now READ by the tenant / owner portal Home FAQ (`rental-portal-access.md` §22, `RentalPortalFaqService`). A lease holding none of them shows no FAQ; nothing is guessed from the agency's standard notice period alone. No capture field was added: there is still no per-lease notice-length or early-cancellation field on the capture screen.
