# Agent Activation Gate

> Status: Approved — Johan, 2026-08-14 (drafted and approved in the same session as a live-found bug).
> Related: `.ai/specs/agent-seat-release-lock.md` (AT-278, offboarding/reinstatement),
> `.ai/specs/agency-admin-rule.md` §R1b (first-login mechanics this spec reuses).

## 1. What this is and why

A newly created agent (`User`) is currently marked `is_active = true` at creation time —
before they have ever set a password or signed in. Two concrete symptoms, both found live
on 2026-08-14 while testing a P24 import against a fresh "Demo Agency Test" agency:

- A brand-new invited agent already shows as **active** in every agent list, headcount
  count, and seat-billing figure, despite never having touched the system.
- Assigning that agent a branch + designation (`UserManagementController::updateRole()`,
  the quick-assign action) additionally force-stamps `email_verified_at = now()` — which
  is CoreX's "invite accepted" marker (`User::isPendingInvite()`). That strands the agent:
  their invite link's `AccountSetupController::show()` now sees `email_verified_at` set and
  redirects them to "Your account is already set up. Please sign in" — but they never set
  a password, so they cannot sign in. A branch/designation assignment made before the
  invitee opens their email permanently locks them out of ever setting their own password.

**Required behavior:** an agent stays `is_active = false` from creation until they
complete their own invite (set a password) **and** successfully sign in for the first
time. Assigning role/branch/designation before that must never advance their activation
state.

## 2. Pillars

- **Agent** (`User`) — the model this entirely concerns. `is_active` gates: agent
  directory visibility, seat/headcount billing (`AgencyHeadcountChanged` event via
  `UserObserver`), and login.
- No other pillar's data model changes. No new migration — see §4.

## 3. The existing mechanism this reuses

CoreX already has everything needed to gate this correctly, just not wired to `is_active`:

- `User::pendingInvitePassword()` — a random 72-char string set as the password at
  creation. A pending invitee cannot authenticate under any password they might guess;
  only `AccountSetupController::store()` (the signed invite-link POST) can ever replace
  it with their real one. This already fully prevents login before invite acceptance —
  it is not touched by this spec.
- `users.first_login_at` — already stamped exactly once, atomically, on a user's genuine
  first successful login, by `AgencyAdminFirstLoginService::handle()` (called from both
  real login entry points: `AuthenticatedSessionController::store()` and
  `AgencySetupGateController::login()`). Today it only gates the agency-admin welcome
  popup/email. This spec repurposes it as the general "has this agent ever completed
  activation" marker — semantically it already means exactly that for every role, not
  just agency admins.

Because `first_login_at` is stamped with an atomic
`UPDATE ... WHERE first_login_at IS NULL` (race-safe across double-clicks/two tabs), this
spec extends that same statement to flip `is_active = 1` in the same atomic write — no new
column, no new race condition, reuses code that is already proven and tested.

## 4. Data model

No migration. `users.is_active` (existing) and `users.first_login_at` (existing) are the
only columns involved. `first_login_at IS NOT NULL` becomes the durable "has been active
at least once" signal, distinguishing:

- **Pending invite** — `is_active = false`, `first_login_at IS NULL`. Never yet activated.
- **Active** — `is_active = true`, `first_login_at IS NOT NULL`.
- **Offboarded** — `is_active = false`, `first_login_at IS NOT NULL`. Was active, an admin
  deactivated them. Reinstatement remains gated by `AgentSeatLockService` exactly as today
  (AT-278) — this spec does not change offboarding/reinstatement behavior at all.

## 5. User flow

1. Admin creates an agent (`UserManagementController::store()`). Agent is created
   `is_active = false`, `email_verified_at = null`, `first_login_at = null`, with an
   unguessable random password. An invite email sends as today.
   - **Exception**: the existing "test agent" bypass (`test_agent=1`) already skips the
     invite flow and force-verifies immediately — it also force-activates
     (`is_active = true`) in the same step, since there is no real person expected to sign
     in and the existing bypass intent already treats the account as pre-activated.
2. Admin may freely assign branch/designation/role to the still-pending agent
   (`updateRole()`) at any point before they accept their invite. This updates
   branch/role/designation as requested but must not touch `is_active` or
   `email_verified_at` — the agent remains pending.
3. Agent opens their invite link, sets a password (`AccountSetupController::store()`,
   unchanged by this spec — still only sets `password` + `email_verified_at`).
4. Agent signs in for the first time (`AuthenticatedSessionController::store()` or the
   agency-admin gate). `AgencyAdminFirstLoginService::handle()`'s atomic claim fires,
   stamping `first_login_at` **and** flipping `is_active = true` in the same statement.
   The agent is now active — headcount/seat billing, directory visibility, and future
   `updateRole()` calls all reflect it correctly from this point on.
5. If an admin later deactivates this (now-activated) agent and subsequently touches their
   role/branch/designation, `updateRole()`'s existing AT-278 seat-lock-gated reinstatement
   logic applies exactly as it does today — unchanged, still requires
   `AgentSeatLockService::assertCanReinstate()` to pass.

## 6. Permissions

No change. Existing `manage_users` permission gates both `store()` and `updateRole()` as
today.

## 7. Files to change

- `app/Http/Controllers/Admin/UserManagementController.php`
  - `store()`: `is_active => true` → `is_active => false`; test-agent branch explicitly
    force-activates alongside its existing force-verify.
  - `updateRole()`: gate the existing seat-lock reinstatement block AND the
    `email_verified_at` auto-stamp on `$user->first_login_at !== null` — i.e. only ever
    apply to an agent who has been active before. A never-activated pending invitee is
    left untouched by both.
- `app/Services/Onboarding/AgencyAdminFirstLoginService.php`
  - `handle()`: the atomic `first_login_at` claim also sets `is_active = 1` in the same
    `UPDATE`.
- `app/Http/Controllers/Auth/AuthenticatedSessionController.php`
  - `store()`: call `AgencyAdminFirstLoginService::handle()` **before** the `!is_active`
    rejection gate (currently after), so a genuinely-first-time sign-in's activation lands
    before the gate reads it. Because `handle()` writes via `DB::table()`, the already-
    loaded `auth()->user()` instance must be `->refresh()`ed before the gate reads it, or
    it sees the stale pre-activation value straight back out of memory.
  - `app/Http/Controllers/Public/AgencySetupGateController.php::login()` has the identical
    pattern (its own `is_active` check before `handle()`) — same reorder + refresh fix
    applies there too.

## 8. Acceptance criteria

- Creating a new (non-test) agent leaves `is_active = false`.
- Assigning branch/designation/role to a still-pending agent does not change `is_active`
  or `email_verified_at`, and does not invoke the seat-lock reinstatement path.
- A pending agent cannot sign in (unchanged — already enforced by the unguessable
  password).
- Completing the invite (`AccountSetupController::store()`) still only sets password +
  `email_verified_at`, exactly as today.
- The agent's first successful sign-in flips `is_active = true` and stamps
  `first_login_at`, and they land on the dashboard as today.
- A second, later sign-in does not re-run the activation write (the atomic claim only
  fires once — proven by the existing `first_login_at` mechanism).
- An offboarded (previously-active) agent whose role/branch/designation is edited still
  goes through the existing AT-278 seat-lock reinstatement gate, unchanged.
- Test-agent creation (`test_agent=1`) still results in an immediately active account, as
  today.
