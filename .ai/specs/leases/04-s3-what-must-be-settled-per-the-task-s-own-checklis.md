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
