# Spec — Agent Seat Release Lock (the 30-day reinstatement block)

**Ticket:** AT-278
**Status:** DRAFT — awaiting Johan's sign-off
**Owner:** Andre
**Created:** 2026-08-06
**Pillars touched:** Agent (`User`) — reads + writes. Billing subscribes (already, via `AgencyHeadcountChanged`).
**Related specs:** `.ai/specs/agency-billing.md` (§3 D1 seat definition, §7 reconciler),
`.ai/specs/agent-delete-reassignment.md`, `.ai/specs/at118-communications-access-gate.md` §3.4

---

## 1. Purpose & business requirement

CoreX bills each agency on its **live billable seat count** — `users.agency_id = X AND
is_active = 1 AND deleted_at IS NULL AND is_assistant = 0`, computed fresh on every read
(`SubscriptionPricingService::billableSeats()`, billing spec §3 D1, §7.3).

Computing live is the right call for honesty — an agency that genuinely sheds an agent
stops paying immediately. But it also means **a seat can be freed and retaken at will, at
zero cost**. Nothing today stops an agency from archiving eight agents on the 28th,
letting the bill compute low, and restoring them on the 1st. The seat count is a
photograph, and the agency controls when the shutter opens.

This spec closes that. **When a billable seat is released, that person cannot re-occupy a
seat for 30 days.** The bill still drops the moment they leave — we are not charging for
people who are gone — but the seat cannot bounce. An agency that removes an agent to
dodge a month's R450 loses that agent's *production* for 30 real days, which costs far
more than the seat. The incentive dies without punishing an honest departure.

**A CoreX System Owner can override the lock immediately.** Real life produces genuine
mistakes — a wrong agent archived, a resignation withdrawn, an admin fat-fingering the
toggle. The override is one click for us, logged with a mandatory reason, and unavailable
to the agency itself. That asymmetry is the whole design: the agency cannot self-serve
the bypass, so the deterrent holds; we can fix any honest error in seconds, so the rule
never traps a real customer.

### The rule in one line

> Releasing a billable seat starts a 30-day clock. The released person cannot be
> restored, reactivated, or re-created until it elapses — unless a CoreX System Owner
> overrides, with a reason, on the record.

---

## 2. Pillars

| Pillar | Reads | Writes |
|---|---|---|
| **Agent** (`User`) | `is_active`, `deleted_at`, `agency_id`, `is_assistant`, role | gates `deleted_at` → null (restore), `is_active` 0 → 1 (reactivate), and same-email re-create |
| **Billing** | — | none directly. Billing already subscribes to `AgencyHeadcountChanged`; unchanged. The bill still drops on release. |

No new pillar. This is a **guard on an existing Agent-pillar transition**, not a new island.

---

## 3. Decisions locked

| # | Decision |
|---|---|
| **D1** | **Both seat-freeing actions are covered: soft-delete AND deactivate.** `is_active = 0` frees a seat identically to `deleted_at` (billing spec §3 D1 counts both), and the deactivate toggle is *one click* with no successor, no QR reroute and no modal — it is the cheaper dodge. Locking only delete would leave the front door open. |
| **D2** | **The seat frees immediately; the RETURN is what is blocked.** We never bill for someone who is gone. The deterrent is the 30-day absence of the person, not a phantom charge. (Rejected alternative: hold the seat billable for 30 days — it kills the incentive at source but taxes every honest departure, including retrenchments and deaths.) |
| **D3** | **30 days, platform-wide, config-driven, NOT an agency setting.** `config('corex-billing.seat_release.lock_days', 30)`. An agency configuring its own anti-abuse window is the abuse. See §10 — deliberately NOT in the Setup Wizard. |
| **D4** | **The window is stamped at release time, not computed at read time.** `reinstatable_at` is STORED. Changing the policy from 30 to 45 days must not retroactively extend locks an agency is already sitting inside — that would be a rule change applied backwards, and we would deserve the support ticket. |
| **D5** | **Only a CoreX System Owner (`is_owner` role, platform identity, `agency_id` NULL) may override.** Checked on the user's **REAL** role (`isOwnerRole()`), never the View-As lens (`isEffectiveOwner()`) — an owner viewing as an agency admin is still an owner, and an agency admin can never become one by any lens. An override requires a typed reason (min 10 chars) and is recorded permanently. |
| **D6** | **Re-creating the same person under the same email is the same act as restoring them** and is blocked identically. Today `UserManagementController::store()` does `User::onlyTrashed()->where('email', …)->forceDelete()` — a **hard delete**, violating non-negotiable #1, destroying the AT-118 offboarding audit anchor, and handing the agency a trivial bypass. This is deleted outright (§6.4). |
| **D7** | **Assistants are exempt.** `users.is_assistant = 1` is not a billable seat (billing spec §3 D1 amendment), so releasing one frees nothing and there is nothing to abuse. No lock is written for an assistant. |
| **D8** | **Users with no agency are exempt.** `agency_id IS NULL` (System Owners, console fixtures) occupy no seat. No lock. Mirrors `UserObserver::announceHeadcountChange()`. |

---

## 4. Data model

### 4.1 New table — `agent_seat_releases`

One row per release **cycle**. Append-only history; the row is *closed* (not deleted) when
the person comes back. The open row — `reinstated_at IS NULL` — **is** the lock. There is
no denormalised copy of lock state on `users`: one source of truth per data point
(STANDARDS, Architectural Laws).

```
id                    bigint PK
agency_id             bigint  NOT NULL, FK agencies, indexed      -- the agency that was billed
user_id               bigint  NOT NULL, FK users,    indexed      -- the released person
released_at           timestamp NOT NULL
released_by_user_id   bigint  NULL, FK users nullOnDelete         -- NULL = console/system
release_reason        varchar(20) NOT NULL                        -- 'deleted' | 'deactivated'
reinstatable_at       timestamp NOT NULL                          -- STORED (D4) = released_at + lock_days
reinstated_at         timestamp NULL                              -- NULL = OPEN = locked/serving
reinstated_by_user_id bigint  NULL, FK users nullOnDelete
reinstated_via        varchar(20) NULL                            -- 'elapsed' | 'owner_override'
override_reason       text    NULL                                -- mandatory when owner_override
created_at/updated_at timestamps

INDEX (user_id, reinstated_at)      -- the hot path: "is this user locked?"
INDEX (agency_id, released_at)      -- the churn report: "what is this agency doing?"
```

**No `deleted_at`.** This is an append-only audit record and the evidence in a billing
dispute. Nothing may remove a row. (Non-negotiable #1 governs user-facing records; audit
logs across CoreX — `comms_access_audit_log`, `property_audit_log`, `domain_event_log` —
are uniformly append-only, and this follows that pattern.)

**Multi-tenancy:** carries `agency_id` per non-negotiable #7, but the model deliberately
does **NOT** use `BelongsToAgency`. A CoreX System Owner (`agency_id` NULL) must read and
override across every agency, and the AgencyScope owner bypass dies the moment
`session('active_agency_id')` is set — the known owner-switcher blind spot. Scoping is
therefore explicit at the query site (`forAgency()`), never implicit. Documented here so
the omission reads as a decision, not an oversight.

### 4.2 Model — `App\Models\Billing\AgentSeatRelease`

`REASON_DELETED`, `REASON_DEACTIVATED`, `VIA_ELAPSED`, `VIA_OWNER_OVERRIDE` constants.
`scopeOpen()`, `isElapsed()`, `remainingDays()`.

---

## 5. The service — `App\Services\Admin\AgentSeatLockService`

The single choke point. Every path that frees or re-occupies a seat goes through it.

| Method | Behaviour |
|---|---|
| `release(User $user, string $reason, ?int $actorId): ?AgentSeatRelease` | Opens a lock. **Idempotent** — returns the existing open row rather than stacking a second (delete-then-toggle on the same agent must not double-lock). Returns `null` and writes nothing for an assistant (D7) or an agency-less user (D8). |
| `openReleaseFor(User $user): ?AgentSeatRelease` | The open row, or null. |
| `isLocked(User $user): bool` | An open row exists **and** `now() < reinstatable_at`. |
| `lockedUntil(User $user): ?Carbon` | For the UI message. |
| `assertCanReinstate(User $user, ?User $actor, ?string $overrideReason): void` | Throws `SeatReinstatementLockedException` unless the clock elapsed, or `$actor?->isOwnerRole()` **and** a reason ≥10 chars is supplied. The only gate. |
| `reinstate(User $user, ?User $actor, ?string $overrideReason): void` | Closes the open row, stamping `reinstated_via`. Call **after** `assertCanReinstate` passes. |
| `bypass(callable $fn): mixed` | Escape hatch for seeders, factories and console fixtures. Sets a static flag the observer backstop honours. Never reachable from an HTTP path. |

### 5.1 Enforcement points (all of them)

A guard in one controller is not a guard. Every transition that re-occupies a seat:

| # | Path | Change |
|---|---|---|
| 1 | `UserManagementController::delete()` | `release(… 'deleted')` after the soft-delete |
| 2 | `UserManagementController::toggle()` — deactivating | `release(… 'deactivated')` |
| 3 | `UserManagementController::toggle()` — activating | `assertCanReinstate()` → `reinstate()` |
| 4 | `UserManagementController::restore()` — **NEW** | `assertCanReinstate()` → `$user->restore()` → `reinstate()` |
| 5 | `UserManagementController::store()` | same-email trashed user → §6.4 (no more `forceDelete`) |
| 6 | `UserManagementController::updateRole()` | line 662 sets `is_active = 1` **unconditionally** — a silent reactivation bypass. Now only lifts an inactive user when unlocked. |
| 7 | `UserObserver::restoring()` / `updating()` | **class-level backstop.** Throws on a locked `restore()` or a locked `is_active` 0→1 from *any* caller — a future controller, an Artisan command, a queued job. Honours `bypass()`. This is what makes the rule structural instead of a habit. |

---

## 6. Behaviour & UI

### 6.1 Archived Agents — the restore path that does not exist today

There is currently **no** restore route, no archived-agents screen, and no way back except
re-creating the user (which hard-deletes the original). You cannot gate a door that is not
there, so this build adds it.

- **`/admin/users/archived`** — table of `onlyTrashed()` agency users: name, email, role,
  archived date, who archived them, **Locked until {date} ({n} days)** or **Eligible to
  restore**, and a **Restore** button.
- The Restore button is **disabled with the reason on it** while locked — never a dead
  button, never a silent refusal (STANDARDS: *No Silent Locks — read-only states must say
  why and offer the way forward*).
- **System Owners additionally see "Override & restore now"**, which opens a small modal
  demanding a typed reason before it will submit.
- **Navigation (non-negotiable #2, same day):** an **Archived** tab/button in the header of
  `/admin/users`, showing the archived count.

### 6.2 The refusal message

Plain English, with the date and the way forward (STANDARDS F.8, No Silent Locks):

> **{Name} cannot be reinstated until {12 September 2026} (26 days).**
> A seat released on {13 August 2026} is held for 30 days before that person can occupy a
> seat again. This prevents removing agents to lower a monthly bill and adding them back
> afterwards. If this was a mistake, contact CoreX support — a System Owner can lift the
> hold immediately.

### 6.3 Deactivate/reactivate toggle

Reactivating a locked user fails with the same message. The toggle button on
`/admin/users` renders disabled with a tooltip carrying the date, rather than failing
after the click.

**Amended 2026-08-06:** a deactivated-but-not-deleted agent (`is_active = 0`,
`deleted_at IS NULL`) never appears on Archived Agents — that page is `onlyTrashed()`
only. Originally this control had no override, only the disabled state above, which
left a System Owner with no way back in for this class of agent at all. `toggle()` now
accepts the same `override_reason` as `restore()`, and a System Owner sees an
"Override & activate now" control here identical in shape to the one on Archived
Agents (§6.1). Everyone else still sees the plain disabled button — the server
re-checks `isOwnerRole()` regardless of what renders.

### 6.4 Re-creating the same email (replaces the `forceDelete`)

`store()` currently **hard-deletes** any trashed user with the submitted email. Removed. Now:

- **Trashed user with this email exists and is LOCKED** → validation error naming the
  person, the date, and pointing at Archived Agents. No new row.
- **Trashed user exists and is NOT locked** → validation error directing the admin to
  **restore** them instead: *"{Name} was archived on {date}. Restore them from Archived
  Agents so their history, deals and QR code stay attached — creating a new account would
  orphan all of it."* A second account for the same person is a data-integrity bug
  regardless of billing; this is the correct answer independent of this ticket.
- **No trashed user** → unchanged.

The `Rule::unique('users','email')->whereNull('deleted_at')` on the email field stays as-is.

---

## 7. Input space & prevent-or-absorb (BUILD_STANDARD §2, §3)

| Input | Decision |
|---|---|
| Assistant released (`is_assistant = 1`) | *Absorb.* No lock written (D7). Restores freely. |
| User with `agency_id` NULL released | *Absorb.* No lock written (D8). |
| Agent deleted, then toggled inactive (already inactive) | *Absorb.* `release()` is idempotent — one open row, clock does not restart. |
| Agent deactivated, then deleted | *Absorb.* Same open row reused. The clock runs from the **first** release, which is the honest reading — the seat was freed then. |
| Agent restored, released again a day later | *Prevent.* Old row closed, a **new** row opens with a fresh 30 days. Serial churn gets progressively more expensive, which is the point. |
| Owner override with an empty/3-char reason | *Prevent.* Validation, min 10 chars. An unexplained override is an unaudited one. |
| Non-owner posts the override route directly | *Prevent.* `assertCanReinstate` re-checks `isOwnerRole()` server-side; the missing button is not the security boundary. |
| Lock elapses mid-request | *Absorb.* Compared against `now()` at assert time; worst case the admin retries and it works. |
| `reinstatable_at` in the past, row still open | *Absorb.* That is simply "eligible" — the row is closed lazily on the next successful restore. No cron needed. |
| Two admins restore simultaneously | *Absorb.* `reinstate()` closes the row inside a transaction with a `whereNull('reinstated_at')` compare-and-set; the loser is a no-op, not a duplicate. |
| Policy changed 30 → 45 days | *Absorb.* Existing locks keep their stamped `reinstatable_at` (D4). |
| Seeder/factory creates + deletes users | *Absorb.* `bypass()` (§5). |
| Agency deleted with locked users | *Absorb.* Rows survive as the audit record; `agency_id` FK is `cascadeOnDelete`-free (restrict/nullOnDelete per the migration). |

---

## 8. Permissions

- Restore (unlocked) — existing **`manage_users`**. No new key.
- Override (locked) — **CoreX System Owner role only** (`isOwnerRole()`), not a permission
  key. Deliberate: a permission key can be granted to an agency admin by another agency
  admin, which would hand the agency the bypass and void the entire rule. This authority
  is not delegable.
- `/admin/users/archived` — `manage_users`, same as `/admin/users`.

---

## 9. Test matrix (BUILD_STANDARD §5)

**Lock lifecycle**
- Delete an agent → open release row, `reinstatable_at` = +30 days, `release_reason = deleted`.
- Deactivate an agent → open row, `release_reason = deactivated`.
- Delete then deactivate → still exactly ONE open row, clock unchanged (idempotency).
- Assistant deleted → NO row. Agency-less user deleted → NO row.

**The block**
- Restore inside the window → refused, message names the date.
- Reactivate (`toggle`) inside the window → refused.
- `updateRole()` on a locked inactive user → does not silently reactivate.
- Re-create with the same email inside the window → refused, no new user row, **and the
  trashed row still exists** (the anti-`forceDelete` assertion).
- Restore after the window → succeeds, row closed with `reinstated_via = elapsed`.

**The override**
- System Owner + 10-char reason inside the window → succeeds, `via = owner_override`, reason stored.
- System Owner, blank reason → refused.
- Agency admin posting the override route → 403.
- Agency admin *with* `manage_users` → still 403 (authority is not delegable).

**Billing integration (proves the point of the ticket)**
- 10 seats → delete 1 → `billableSeats()` = 9 **immediately** (D2: the bill still drops).
- Restore blocked inside 30 days → seats stay 9.
- Owner override → seats back to 10, `AgencyHeadcountChanged` fires, reconciler runs.

**Lockout audit (the second half of AT-278)**
- Soft-deleted user cannot authenticate (SoftDeletes global scope on the eloquent provider).
- Soft-deleted user's Sanctum tokens are revoked and session rows purged (§11).
- Soft-deleted user does not appear in the worksheet-market agent list (§11 leak fix).

Test data uses real KZN South Coast agent shapes, not `Test / Test / 0000000000`.

---

## 10. Deliberately NOT in the Setup Wizard (non-negotiable #10a)

`seat_release.lock_days` is **not** an agency setting and must never appear in
`config/agency-onboarding-copy.php`.

It is a **platform commercial control**, like the seat rate and the plan thresholds — which
are likewise config-only and already recorded as wizard-exempt in `agency-billing.md` §14.
An agency that can set its own anti-abuse window has no anti-abuse window; a wizard step
offering "how long before you can re-add a removed agent?" would be handing over the key
and calling it onboarding. Same reasoning, same file, same exemption.

Flagged for Johan's confirmation per #10a — this is his call to record, not the lane's.

---

## 11. The lockout audit (second half of AT-278) — findings & fixes

Audited: can a soft-deleted agent still get in, and are they gone from every surface?

**Already correct — no change needed:**
- **Login is blocked.** `User` uses `SoftDeletes`; `config/auth.php` uses the stock
  eloquent provider, so `retrieveById`/`retrieveByCredentials` apply the global scope and
  return null. `Authenticate` middleware additionally rejects `is_active = 0`. Existing
  sessions die on the next request for the same reason.
- **P24** — `delete()` pushes the agent inactive before soft-deleting.
- **Branch assignments** — deleted in `delete()`.
- **QR codes** — mandatory reroute, chained (`agent-qr-onboarding.md`).
- **Website API** — `AgentVisibilityChanged('removed')` fires from `UserObserver::deleted()`.
- Calendar sources, `ReportingService`, `SubscriptionPricingService` — all correctly
  filter `deleted_at`.

**Gaps this build fixes:**
1. **`store()` hard-deletes archived users** — non-negotiable #1 violation and the billing
   bypass. Removed (§6.4). *The single most important fix in this ticket.*
2. **Sanctum tokens are never revoked** on delete/deactivate. They currently fail to
   resolve (the morphTo hits the soft-delete scope), so this is defence-in-depth rather
   than a live hole — but a token row outliving its user is a loaded gun. Revoked
   explicitly.
3. **Session rows are never purged** — same reasoning; the session is already dead on
   next request, but the row lingers. Purged on release.
4. **`WorksheetMarketController:23`** lists users with **neither** a `deleted_at` **nor**
   an `is_active` filter — a soft-deleted agent still appears in the worksheet market
   picker. Fixed.
5. **Three headcount queries filter `is_active` but not `deleted_at`** —
   `CommandCentreService:771`, `EvidenceGatheringService:385`,
   `BM/PerformanceController:445`. Correct *today* only because `delete()` happens to set
   `is_active = 0` first. That is a coupled invariant nothing enforces: any future path
   that soft-deletes without deactivating leaks a ghost into three headcounts. Guards
   added (BUILD_STANDARD §6 — fix the class).

---

## 12. Files

**Create**
- `database/migrations/*_create_agent_seat_releases_table.php`
- `app/Models/Billing/AgentSeatRelease.php`
- `app/Services/Admin/AgentSeatLockService.php`
- `app/Exceptions/SeatReinstatementLockedException.php`
- `resources/views/admin/users/archived.blade.php`
- `tests/Feature/Billing/AgentSeatReleaseLockTest.php`

**Modify**
- `app/Http/Controllers/Admin/UserManagementController.php` — `delete`, `toggle`, `store`,
  `updateRole`, + new `archived`, `restore`, `overrideRestore`
- `app/Observers/UserObserver.php` — `restoring()` / `updating()` backstop
- `config/corex-billing.php` — `seat_release.lock_days`
- `routes/web.php` — archived / restore / override routes (named)
- `resources/views/admin/users/index.blade.php` — Archived nav entry + locked toggle state
- `app/Http/Controllers/Admin/WorksheetMarketController.php` + the three headcount queries (§11)
- `.ai/specs/agency-billing.md` — cross-reference §3 D1 to this lock
- `database/schema/mysql-schema.sql` — re-dump (non-negotiable #12a)

---

## 13. Acceptance criteria

- [ ] Deleting or deactivating an agent opens exactly one release row with a stamped `reinstatable_at`.
- [ ] The agency's bill drops immediately on release (D2 — no behaviour change to `billableSeats()`).
- [ ] Restore, reactivate, role-update-reactivation and same-email re-create are ALL refused inside 30 days.
- [ ] Every refusal states the unlock date and the way forward; no dead buttons.
- [ ] A CoreX System Owner can override with a mandatory reason; nobody else can, including an agency admin holding `manage_users`.
- [ ] After 30 days, restore works with no override and closes the row.
- [ ] `store()` never hard-deletes a user again — asserted by test.
- [ ] `/admin/users/archived` exists, is reachable from `/admin/users`, and is permission-gated.
- [ ] The §11 leak fixes are in and covered.
