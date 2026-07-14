# Agency Billing — What an agency pays CoreX

> **Ticket:** AT-11 — "Payment gate for payments"
> **Branch:** `AT-11-Payment-gate-for-payments`
> **Status:** DRAFT — awaiting Johan's sign-off
> **Author:** Andre (drafted with Claude), 2026-07-14
> **Governs:** the agency-facing "Billing" page, the System-Developer "Agency Billing"
> page, the pricing engine, plan auto-tracking, custom amounts and discounts.

---

## 1. Purpose & business requirement

CoreX charges agencies money and, today, **nothing in the product knows that**. There is no
price, no plan, no seat count, no invoice — the commercial relationship lives entirely
outside the system. This spec closes that gap.

Two surfaces:

1. **Agency-facing (`/billing`)** — a **read-only** page where an agency admin sees exactly
   what they owe CoreX this month and *why*: their plan, their billable seat count, the
   line-item breakdown, and any discount they are on (including how many months of it
   remain). No editing. No payment capture. Just the truth, plainly stated.
2. **System-Developer (`/admin/billing`)** — owner-only. Every agency, its seat count, its
   plan, and what it will be charged. Per-agency controls to (a) override the computed price
   with a **custom amount**, or (b) apply a **discount %** for a **number of months**.

### What this is NOT (Phase 1 scope boundary)

AT-11's description also asks for *"cut off to turn off corex etc. Users lock and admin give
notifications."* That is the **payment gate** — suspending an agency for non-payment. It is
**deliberately out of scope for this build** and is specced as Phase 2 (§13). Phase 1 ships
the money model and the two pages; you cannot build a credible gate before the system can
say what is owed.

Phase 2 will reuse the existing per-agency `maintenance_mode` lockout (AT-93,
`.ai/specs/maintenance-mode.md`) rather than inventing a second lockout mechanism.

---

## 2. Pillars

| Pillar | Relationship |
|---|---|
| **Agent** (`User`) | **Reads.** The billable seat count IS the agency's active user count. Agent lifecycle changes drive plan changes. |
| **Agency** (tenant) | **Reads + writes.** Owns the subscription record, the plan, the custom amount, the discount. |

Branches are read (for the extra-branch fee). Property / Contact / Deal are untouched.

---

## 3. Decisions locked (Johan, 2026-07-14)

| # | Decision |
|---|---|
| **D1** | **A billable seat is any active, non-deleted user on the agency.** `users.agency_id = X AND is_active = 1 AND deleted_at IS NULL`. Role is irrelevant — an admin, a principal and an agent all occupy a seat. Deactivated or archived users **do not** count. CoreX System Owners carry `agency_id = NULL` and therefore never count against any agency. |
| **D2** | **The plan auto-tracks headcount.** ≤ 10 seats → **CoreX Team**. The 11th seat → **CoreX Agency**, automatically. **The plans are two price shapes, not two feature sets — both get full access to everything.** There is no feature gating anywhere in CoreX based on plan. |
| **D2a** | **Extra branches are billed on both plans** (first branch free, R750/month each thereafter). An 8-agent agency with 2 branches is on Team **and** pays R750 for the second branch. |
| **D3** | **Every plan switch emails `andre@corexos.co.za` and `johan@corexos.co.za`**, sent via the existing `corex` mailer (`config/mail.php` → `mail.mailers.corex`, from `mail@corexos.co.za`). |
| **D4** | **No VAT.** Nothing about VAT appears on either page, in the data model, or in the engine. Prices are the number shown. |
| **D5** | **Custom amount and discount are mutually exclusive — never both at once.** The discount % applies to the **auto-computed** price, for a duration in whole months. The agency page shows **how many months remain**. |

---

## 4. Pricing model

All rates live in **`config/corex-billing.php`** — a price change is a config edit, never a
code hunt.

### CoreX Team — flat, headcount × R450

```
total = seats × R450
```
No base fee. No tiers. Applies while seats ≤ 10.

### CoreX Agency — base + graduated seat tiers

```
total = R1 495 base
      + graduated seat cost
      + extra-branch fee
```

Seats are **graduated**, not flat-switched — each band is priced at its own rate:

| Seat band | Rate per seat |
|---|---|
| 1 – 10 | R295 |
| 11 – 20 | R250 |
| 21+ | R195 |

**Worked example (25 seats), the reference case:**
```
10 × R295 = R2 950
10 × R250 = R2 500
 5 × R195 =   R975
            ────────
   seats  = R6 425
 + base   = R1 495
            ────────
   total  = R7 920   (before any branch fee)
```

### Extra branches — R750 / branch / month

The **first branch is included**. Each branch beyond the first costs R750/month.

> **OPEN-1 — RESOLVED (Johan, 2026-07-14): charge extra branches on BOTH plans.**
> The two plans are **names for two price shapes, not two feature sets** — both get full
> access to everything. So an agency with 8 agents and 2 branches is on **Team** (headcount
> ≤ 10) and **is charged R750 for the second branch**. The extra-branch fee is plan-agnostic:
> `branches_beyond_the_first × R750`, on Team and on Agency alike.
> The branch line is always rendered explicitly on both pages, so the charge is never hidden.

### Price resolution order (the single rule)

```
IF custom_amount is set        → payable = custom_amount            (discount ignored; D5)
ELSE IF discount is active     → payable = computed × (1 − pct/100)
ELSE                           → payable = computed
```

`computed` is always calculated and always stored/shown alongside `payable`, so a custom
amount or discount is always legible *against the list price*. Rounding: half-up to
2 decimals, applied once at the end.

---

## 5. Data model

### 5.1 New table — `agency_subscriptions` (one row per agency)

Migration: `database/migrations/2026_07_14_1200xx_create_agency_subscriptions_table.php`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `agency_id` | bigint unsigned, **unique**, FK → `agencies`, cascade | one subscription per agency |
| `plan` | varchar(20), NOT NULL, default `'team'` | `team` \| `agency`. The **last reconciled** plan — drift from the derived plan is what triggers the email. |
| `custom_amount_zar` | decimal(12,2) **nullable** | NULL = no override. Set = the final price. |
| `custom_amount_note` | varchar(255) nullable | why — shown to the agency ("Negotiated launch rate") |
| `discount_percent` | decimal(5,2) nullable | 0.01–100.00 |
| `discount_months` | smallint unsigned nullable | whole months the discount runs |
| `discount_starts_on` | date nullable | |
| `discount_note` | varchar(255) nullable | shown to the agency |
| `plan_changed_at` | timestamp nullable | last auto-switch |
| `notes` | text nullable | dev-only, never shown to the agency |
| `created_at` / `updated_at` | timestamps | |
| `deleted_at` | softDeletes | non-negotiable #1 |

**Invariant (D5), enforced at three layers:** `custom_amount_zar` and `discount_percent` are
never both non-NULL.
1. **UI** — a three-way mode selector (Automatic / Custom amount / Discount). Picking one
   visibly clears the others. *Prevent.*
2. **Validation** — `prohibits` rules both ways on the FormRequest. *Prevent.*
3. **Service** — `SubscriptionPricingService` nulls the counterpart on write, and on read the
   resolution order (§4) makes custom-amount win regardless. *Absorb* — even a row hand-edited
   in SQL to hold both cannot produce a wrong number or a crash.

`discount_ends_on` is **derived, never stored** (`discount_starts_on + discount_months`), so a
discount **expires by arithmetic**. No cron can forget to end it.

### 5.2 `agencies` table — unchanged

No new columns. All billing state lives on `agency_subscriptions`.

### 5.3 Model — `App\Models\Billing\AgencySubscription`

Uses `BelongsToAgency` (multi-tenancy #7) + `SoftDeletes`. Static
`forAgency(int $agencyId): self` mirrors `AgencyProformaSettings::forAgency()`
(`app/Models/Proforma/AgencyProformaSettings.php:31-43`) — `firstOrCreate` with defaults,
**guarded `<= 0`** per STANDARDS Rule 17 (returns an unsaved in-memory default rather than
FK-1452ing on a null-agency owner/console context).

---

## 6. The pricing engine

**`App\Services\Billing\SubscriptionPricingService`**

```php
public function quoteFor(Agency $agency): BillingQuote
```

Returns an immutable `BillingQuote` DTO:

| Field | Meaning |
|---|---|
| `seats` | billable seat count (D1) |
| `branches` / `billable_branches` | total / beyond the first |
| `derived_plan` | what the headcount says the plan should be |
| `stored_plan` | what the subscription row currently says |
| `lines[]` | `{label, qty, unit, amount}` — e.g. `Seats 11–20`, `qty 10`, `R250`, `R2 500` |
| `computed_zar` | list price before override/discount |
| `discount_active` / `discount_percent` / `discount_months_remaining` / `discount_ends_on` | |
| `custom_amount_zar` | or null |
| `payable_zar` | the number the agency owes (§4 resolution order) |
| `basis` | `automatic` \| `custom` \| `discounted` — why `payable` is what it is |

The quote is **pure** — it reads, computes, and returns. It never writes. That makes it
trivially testable and safe to call on every page render.

**Seat count query (D1):**
```php
User::withoutGlobalScope(AgencyScope::class)   // owner context has no tenant; see §9
    ->where('agency_id', $agency->id)
    ->where('is_active', 1)
    ->whereNull('deleted_at')                   // SoftDeletes already applies this
    ->count();
```

**Money formatting** uses `App\Support\Money\Zar::format()` / `::formatWhole()`
(`app/Support/Money/Zar.php`) — the documented single source of truth (AT-177). No inline
`'R ' . number_format(...)` in the new views.

---

## 7. Plan auto-tracking + the email (D2, D3)

### 7.1 The reconciler

**`App\Services\Billing\SubscriptionReconciler::reconcile(Agency): ?AgencyPlanChanged`**

Idempotent. Compares `derived_plan` (from the live seat count) against `stored_plan`. If they
differ, it flips the stored plan using a **compare-and-set** update:

```php
$affected = AgencySubscription::whereKey($sub->id)
    ->where('plan', $stored)            // ← CAS guard
    ->update(['plan' => $derived, 'plan_changed_at' => now()]);

if ($affected === 1) {                   // we won the race — we own the notification
    event(new AgencyPlanChanged($agency, $stored, $derived, $seats));
}
```

The CAS guard is what makes the email **exactly-once** even if two requests (or a request and
the nightly sweep) reconcile the same agency concurrently. Only the writer that actually
changed the row emits the event.

### 7.2 Reactivity — domain events, not ad-hoc hooks (non-negotiable #9)

| Emitter | Event | Listener | Mode |
|---|---|---|---|
| `UserObserver` (created / `is_active` changed / deleted / restored) | **`App\Events\Agent\AgencyHeadcountChanged`** *(new)* | `App\Listeners\Billing\ReconcileAgencySubscription` | **sync** — one count + at most one update; well inside the E10 budget |
| `SubscriptionReconciler` | **`App\Events\Billing\AgencyPlanChanged`** *(new)* | `App\Listeners\Billing\NotifyCoreXOfPlanChange` | **queued** |
| existing `AgencyCreated` | — | `App\Listeners\Billing\ProvisionAgencySubscription` | sync — creates the row |

Both new events extend `AbstractDomainEvent` and are added to the catalogue in
`.ai/specs/corex-domain-events-spec.md` §5. `UserObserver` already exists and already emits
domain events (`AgentVisibilityChanged`) — billing **subscribes**; it does not reach into the
Agent pillar.

> ⚠️ **`NotifyCoreXOfPlanChange` MUST NOT set `public string $queue`.** The domain-events spec
> §E4 shows `$queue = 'notifications'` — that example is a **trap in this codebase**: the live
> and staging workers run `queue:work` with **no `--queue` flag**, so they drain **`default`
> only**. A listener on a named queue is stranded forever. Leave `$queue` unset.
> `$tries = 3`, `$backoff = 30`.

### 7.3 Why the displayed number can never go stale

The seat count is **computed live on every read** — never stored, never incremented. So the
bill is correct no matter which code path changed a user (admin screen, import, tinker, raw
SQL). Events and the nightly sweep exist **only to make the *email* prompt**, not to keep the
*number* right. That is the prevent-or-absorb split: the number is *prevented* from being
wrong by construction; a missed event is *absorbed* by the next read or the nightly sweep.

### 7.4 Safety net — nightly sweep

`php artisan corex:billing-reconcile` (scheduled daily) reconciles every agency. Catches any
path that bypasses the observer entirely (bulk import, `DB::table('users')->update(...)`).
Idempotent by the CAS above, so the sweep never double-emails.

### 7.5 The email

`App\Mail\Billing\AgencyPlanChangedMail` → `mailer('corex')`, to `andre@corexos.co.za` +
`johan@corexos.co.za` (recipients in `config/corex-billing.php`, not hardcoded).

Subject: `[CoreX Billing] {Agency} moved to the {Plan} plan ({n} seats)`
Body: agency, old plan → new plan, seat count, old monthly → new monthly, timestamp, and a
deep link to `/admin/billing`.

**Failure isolation:** the listener wraps its send in try/catch and logs on failure. A dead
SMTP server must never break a user save. (Local `.env` currently has **no `MAIL_COREX_*`
keys** — the mailer falls back to `mail.corexos.co.za` with no credentials, so sends fail
locally. That is expected; tests use `Mail::fake()`.)

---

## 8. UI

### 8.1 Agency page — `/billing` (read-only)

View: `resources/views/billing/index.blade.php`. Complies with
`.ai/specs/UI_DESIGN_SYSTEM.md` (tokens only — no hardcoded hex; `check-design-tokens.ps1`
must pass).

- **Hero:** the payable amount, large, with the plan name and the billing month.
- **Why this number:** the line-item table (base, each seat band with its qty and rate, extra
  branches). This is the whole point of the page — an agency must be able to check our maths.
- **Discount banner** (when active): "20% off — **4 months remaining**, ends 30 Nov 2026",
  showing list price struck through next to the discounted price.
- **Custom-amount banner** (when set): "Your agreed rate: R5 000/month" + the note. The
  computed list price is still shown, quietly, so the concession is visible.
- **Seat explainer:** "You have **12 active users**. Deactivated and archived users are not
  billed." — pre-empts the #1 support question.
- **Plan-change notice** when derived ≠ stored at render (a 10→11 seat agency mid-flight):
  reconciliation runs *before* render, so this is informational, not a mismatch.
- Per STANDARDS "No Silent Locks": the page states plainly that it is read-only and that
  billing terms are managed by CoreX — with a `mailto:` to reach us. No dead edit buttons.

### 8.2 System-Developer page — `/admin/billing` (owner-only)

View: `resources/views/admin/billing/index.blade.php`. Copies the shape of
`resources/views/admin/ai-usage/index.blade.php` (KPI cards + agency table + per-agency form).

- **KPI cards:** total MRR, agencies on Team, agencies on Agency, total billable seats, total
  discounts given this month.
- **Table**, one row per agency: name · seats · branches · plan (badge) · computed · basis
  (Automatic / Custom / Discount −20%, 4 mo left) · **payable** · edit.
- **Edit form** (per agency) — a three-way mode selector enforcing D5:
  - **Automatic** — clears both. Price follows headcount.
  - **Custom amount** — one ZAR field + a note. Clears any discount.
  - **Discount** — percent + months + start date + note. Clears any custom amount.
- **Flags** surfaced, never silent: a Team-plan agency with > 1 branch (OPEN-1); an agency
  with 0 active users (bill = R0).
- Amounts via `Zar::format()`.

### 8.3 Navigation (non-negotiable #2 — same day)

| Page | Sidebar location |
|---|---|
| `/billing` | **Admin → Company** slide-panel, directly under *Company Settings* (Johan, 2026-07-14). Gated `@permission('billing.view')`. Two things must move with it, or the entry is unreachable/inert: (a) `billing.view` is added to the **Company group's own `hasAnyPermission([...])` gate** — the group only renders when the user holds one of its children's permissions, so a role granted *only* `billing.view` would otherwise have the whole group hidden; (b) `billing.*` is added to the **`$activeGroup` route map** so Company highlights as active on the billing page. |
| `/admin/billing` | **System Developer → Agency** slide-panel, after *AI Usage*. Inside the existing `@if($isOwner)` block — **no permission key** (see §9). `admin.billing.*` added to the `$activeGroup` map for the `agency` group. (No collision with `billing.*`: `routeIs('billing.*')` matches names *starting* with `billing.`, which `admin.billing.index` does not.) |

---

## 9. Permissions

**Agency page** — new key in `config/corex-permissions.php`:
```php
['key' => 'billing.view', 'label' => 'View Agency Billing', 'section' => 'settings',
 'type' => 'view', 'module' => 'settings', 'sort_order' => 10],
```
Granted to `admin` by default via `role_defaults`. Route: `middleware('permission:billing.view')`.
Controller re-checks server-side. Deploy step: **`php artisan corex:sync-permissions --merge-defaults`**
— without it the key exists in config but not in `nexus_permissions`, and the page is
invisible to everyone.

**Developer page** — **`owner_only` middleware, deliberately NO permission key.** A permission
key is *grantable* via Role Manager, and an agency admin who was handed it would see **every
other agency's commercial terms**. This follows the existing, documented rationale for Dev
Settings / Demo Access (`routes/web.php:2510-2513`). The controller re-checks
`$request->user()?->isOwnerRole()` and `abort(403)`.

**Cross-agency query safety (multi-tenancy #7, spec rule #5):** the developer page reads
across all tenants. `Agency` does not use `BelongsToAgency`, so `Agency::query()` is naturally
unscoped; `AgencySubscription` **does**, so the developer page uses the approved
`AgencySubscription::queryWithoutAgencyScope()` — never `withoutGlobalScope()` in request
code. The agency page uses the **scoped** query, so an admin structurally cannot read another
tenant's row.

---

## 10. Input space & prevent-or-absorb (BUILD_STANDARD §2, §3)

| Input | Handling |
|---|---|
| Agency with **0 active users** | *Absorb.* seats = 0, Team plan, payable **R0**. Page renders "No active users — nothing to bill." Never a divide-by-zero, never a crash. |
| Agency with **no subscription row** (pre-existing agencies) | *Absorb.* `forAgency()` `firstOrCreate`s on first read. A backfill migration provisions all existing agencies (§12). |
| **Owner / console / job with no agency context** (STANDARDS Rule 17) | *Absorb.* `forAgency()` guards `<= 0` and returns an unsaved in-memory default. No FK-1452, no `->on null`. |
| Custom amount **empty** | *Absorb.* NULL = no override. Not `0`. (`0` is a legitimate "free" — the form distinguishes empty from zero.) |
| Custom amount **negative** | *Prevent.* `numeric|min:0`. |
| Custom amount **non-numeric / "R5 000"** | *Absorb then validate.* `Zar::parse()` tolerates `R5 000`, `5,000.00`, `5000`. Genuinely unparseable → clear rejection. |
| Discount **0% or > 100%** | *Prevent.* `numeric|min:0.01|max:100`. |
| Discount **months = 0 / negative** | *Prevent.* `integer|min:1|max:120`. |
| Discount **% set but months absent** (and vice versa) | *Prevent.* `required_with` both ways. A half-set discount can never persist. |
| Discount **start date in the past** | *Accept* — backdating is legitimate. Months-remaining clamps at 0 and the discount simply reads as expired. |
| Discount **fully expired** | *Absorb.* Derived `ends_on` < today → `discount_active = false`, price silently returns to computed. No cron. |
| **Both** custom amount and discount submitted | *Prevent* (mode selector + `prohibits`) **and** *absorb* (custom wins in the resolution order). Triple-guarded, §5.1. |
| **Deleted agency** | Soft-deleted agencies excluded from the developer list and from MRR. |
| Seat count **crosses 10 mid-request** | *Absorb.* Reconcile-before-render + CAS. The page never shows a plan that disagrees with its own seat count. |
| **Concurrent** reconcile (request + nightly sweep) | *Prevent.* CAS update — only the winner emails. |
| Rounding | Half-up, 2dp, applied **once** at the end. `R7 920.00`, never `R7 919.999…`. |

---

## 11. Test matrix (BUILD_STANDARD §5)

`tests/Feature/Billing/` — every row is an assertion, not a hope.

**Engine (`SubscriptionPricingServiceTest`)**
- Team: 1 seat → R450. 7 seats → R3 150. 10 seats → R4 500.
- **The reference case: 25 seats on Agency → seats R6 425, + base R1 495 = R7 920.** (Johan's worked example, asserted verbatim.)
- Graduated boundaries: 10 / 11 / 20 / 21 seats — each band priced at its own rate, not flat-switched.
- 0 seats → R0, no crash.
- Branches: 1 branch → R0 fee. 3 branches → R1 500.
- Plan derivation: 10 → team; 11 → agency.

**Overrides**
- Custom amount set → payable = custom, `basis = custom`, computed still exposed.
- Custom amount = 0 → payable R0 (free) — distinct from NULL.
- Discount 20%, 6 months, started 2 months ago → payable = computed × 0.8, **4 months remaining**.
- Discount expired → payable = computed, `discount_active = false`.
- Discount + custom both forced into the row via raw SQL → custom wins, **no crash** (absorb layer).
- Validation rejects: both submitted; % without months; months without %; negative amount; 0%; 101%.

**Plan switch + email**
- Activate an 11th user → plan flips team → agency, `AgencyPlanChanged` emitted **once**, `Mail::fake()` asserts one mail to **both** recipients.
- Deactivate back to 10 → flips agency → team, emails again.
- Soft-delete a user → seat count drops, bill drops.
- **Restore** a soft-deleted user → seat count rises again.
- Reconcile twice in a row → **exactly one** email (CAS idempotency).
- Nightly sweep after a raw-SQL user insert → catches the drift, emails once.

**Access & isolation**
- Agency admin with `billing.view` → 200 on `/billing`, sees **only their own** figures.
- Agency admin → **403** on `/admin/billing`.
- Agent without `billing.view` → 403 on `/billing`.
- Owner → 200 on both; `/admin/billing` lists **all** agencies.
- Agency A's admin cannot read Agency B's subscription row (AgencyScope proof).

**Reality of data:** seed with real SA agency shapes — a 3-agent Shelly Beach shop, a
14-agent Margate agency across 2 branches, a 25-agent multi-branch — not "Test / Test".

---

## 12. Migration & backfill

1. `create_agency_subscriptions_table`.
2. **Backfill in the same migration:** insert one row per existing (non-deleted) agency, plan
   derived from its current active-user count. No agency is left without a row, so no
   `firstOrCreate` write ever happens in a read request on a live system.
3. `php artisan schema:dump` (per non-negotiable #12a — the migrations folder gained a file),
   run **against the test DB** (`DB_DATABASE=hfc_dash_test`, per the known `schema:dump` trap),
   committed in the same commit.
4. Deploy: `migrate --force` → **`corex:sync-permissions --merge-defaults`** (for `billing.view`)
   → `deploy:sync-reference-data` → clears → reload fpm → **restart the worker** (the plan-change
   email is queued).

---

## 13. Phase 2 — the payment gate (NOT in this build)

Specced here so Phase 1's data model doesn't have to be rebuilt for it:

- `agency_subscriptions` gains `status` (`active` / `past_due` / `suspended`), `due_day`,
  `suspend_after_days`, `last_paid_at`.
- Overdue → warning notifications to the agency admin → then lock, **reusing the existing
  `maintenance_mode` lockout** (AT-93) rather than a second mechanism.
- Owner can always lift a lock (as with maintenance mode today).
- Invoice history / payment capture / PayFast is a separate ticket and a separate spec.

---

## 14. Deliberately NOT in the Setup Wizard (non-negotiable #10a)

Non-negotiable #10a requires every new **setting** to be surfaced in the Agency Onboarding
Setup Wizard (`config/agency-onboarding-copy.php`) in the same prompt. **Nothing in this spec
goes in the wizard, and that is deliberate:**

Plan, custom amount and discount are **CoreX-side commercial terms set by us**, not settings
an agency configures about how CoreX behaves for them. Putting a "your discount %" control in
an agency's onboarding wizard would let a customer set their own price. There is no
agency-configurable setting anywhere in this build.

**This is on the record as a decision, not an oversight** — per #10a it is Johan's call to
confirm.

---

## 15. Acceptance criteria

- [ ] An agency admin opens `/billing` and sees the correct payable amount with a line-item breakdown they can check by hand.
- [ ] A 25-seat Agency-plan agency shows **R7 920** (R6 425 seats + R1 495 base) — the reference case.
- [ ] Activating an 11th user auto-switches Team → Agency, and one email lands at andre@ and johan@.
- [ ] Deactivating a user drops the seat count and the bill on the next page load.
- [ ] The developer page lists every agency with seats, plan, computed and payable.
- [ ] Setting a custom amount overrides the computed price on **both** pages.
- [ ] Setting a 20% / 6-month discount shows the discounted price and "**6 months remaining**", counting down monthly, and auto-expires with no intervention.
- [ ] Custom amount and discount can never both be active.
- [ ] An agency admin gets 403 on `/admin/billing`; an agency can never see another agency's terms.
- [ ] No VAT appears anywhere.
- [ ] Nav entries exist for both pages on day one.
- [ ] `check-design-tokens.ps1` passes; no hardcoded colours in the new views.

---

## 16. Files

**Create**
```
config/corex-billing.php
database/migrations/2026_07_14_1200xx_create_agency_subscriptions_table.php
app/Models/Billing/AgencySubscription.php
app/Services/Billing/BillingQuote.php                     (DTO)
app/Services/Billing/SubscriptionPricingService.php
app/Services/Billing/SubscriptionReconciler.php
app/Events/Agent/AgencyHeadcountChanged.php
app/Events/Billing/AgencyPlanChanged.php
app/Listeners/Billing/ReconcileAgencySubscription.php     (sync)
app/Listeners/Billing/ProvisionAgencySubscription.php     (sync)
app/Listeners/Billing/NotifyCoreXOfPlanChange.php         (queued — NO $queue property)
app/Mail/Billing/AgencyPlanChangedMail.php
app/Console/Commands/BillingReconcileCommand.php
app/Http/Controllers/Billing/BillingController.php        (agency, read-only)
app/Http/Controllers/Admin/AgencyBillingController.php    (owner-only)
app/Http/Requests/Billing/UpdateAgencySubscriptionRequest.php
resources/views/billing/index.blade.php
resources/views/admin/billing/index.blade.php
resources/views/emails/billing/plan-changed.blade.php
tests/Feature/Billing/SubscriptionPricingServiceTest.php
tests/Feature/Billing/PlanAutoSwitchTest.php
tests/Feature/Billing/BillingAccessTest.php
```

**Modify**
```
routes/web.php                                  (2 route groups)
config/corex-permissions.php                    (billing.view + role_defaults)
app/Observers/UserObserver.php                  (emit AgencyHeadcountChanged)
resources/views/layouts/corex-sidebar.blade.php (2 nav entries)
database/schema/mysql-schema.sql                (schema:dump)
.ai/specs/corex-domain-events-spec.md           (catalogue the 2 new events)
.ai/CHAT_STARTER.md                             (per §8i)
```
