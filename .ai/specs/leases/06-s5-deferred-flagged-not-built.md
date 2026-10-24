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
