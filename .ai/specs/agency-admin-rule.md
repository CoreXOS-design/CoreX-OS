# Agency Admin Rule — Spec

> Status: **DRAFT — pending approval**
> Owner: Andre / Johan
> Created: 2026-05-07
> Amended: 2026-08-12 (Johan) — Register First Admin becomes email-only invite; see §R1a.
> Related: `.ai/specs/multi-tenancy.md`, `.ai/specs/agency-onboarding-setup.md`

---

## Why this exists

Every agency in CoreX OS must have at least one Admin user at all times. Today an agency can be created with zero users, leaving it orphaned and unmanageable except by System Owner. This spec enforces the invariant **"every agency has ≥1 Admin"** structurally — at creation time, on Admin removal, and via permission-matrix cleanup.

Two permissions that should never have been delegable to agency-level users are also being pulled out of the matrix and made System-only:

- **Agencies** management (create / edit / delete agencies)
- **Importer** settings

These are platform-operator concerns, not tenant concerns.

---

## Pillars touched

- **Agent (User)** — Admin role assignment, lifecycle, last-Admin protection
- Indirectly: all pillars (an agency without an Admin cannot operate any pillar)

---

## Rules

### R1. New agency creation requires an Admin
When a System Owner / System Admin creates a new **live** agency, the flow is **not complete** until an Admin user is registered for that agency. Agency creation and Admin registration are atomic — if Admin registration is cancelled, the agency is not persisted.

Because a new agency has zero users, the only path is **Register New Admin** (inline user-creation form within the agency-creation wizard). The new Admin becomes the agency's first user.

**Demo agencies are exempt.** When the `is_demo` flag is checked at creation, the Admin section is hidden and skipped — the agency is created empty. Demo agencies are for showcasing/training/sales and do not need to be operable. The R3/R4 sole-Admin protections still apply *if* a demo agency later gains an Admin.

> Existing agencies (pre-rule) are **ignored** — no backfill, no forced prompt.

### R1a. Email-only invite — no password ever typed by the creator (amended 2026-08-12, Johan)

The System Owner **never types or sees the new Admin's password.** The "Register First Admin" step
captures **name, email, mobile** only — no password field exists on the form. This replaces the
earlier "password (or 'send invite' toggle)" language below; invite-by-email is now the **only**
path, not an option. This is a deliberate departure from R1's original UI note, superseded by this
section.

**Flow:**
1. Agency + Admin `User` are created atomically as before (R1), but the Admin row is created with
   an **unusable placeholder password** — `Hash::make(User::pendingInvitePassword())`, the exact
   mechanism `UserManagementController`'s existing "invite a user" flow already uses (AT-268) — and
   `invited_at = now()`. `email_verified_at` stays `null` (CoreX's existing "invite not yet
   redeemed" marker; see `User::isPendingInvite()`).
2. `App\Mail\UserInviteMail` is sent to the admin's email immediately (same Mailable already used
   for every other CoreX user invite — no new mail class). It carries a 7-day signed
   `account.setup` link. The Admin sets their real password there
   (`App\Http\Controllers\Auth\AccountSetupController`), which stamps `email_verified_at`. Until
   redeemed, the account cannot authenticate (`AuthenticatedSessionController`'s AT-268 belt-and-
   braces gate refuses the placeholder hash regardless of what's submitted).
3. **The agency-onboarding-setup email does NOT fire at agency-creation time anymore.** The
   `AgencyOnboardingSetup` record is still created immediately (idempotent, as before — the
   platform-owner tracking page and the token exist right away), but
   `App\Mail\AgencyOnboardingSetupMail` is deferred to the Admin's **first successful login** (see
   §R1b). This supersedes `.ai/specs/agency-onboarding-setup.md` §3.5 step 2 — full amendment
   recorded there.

### R1b. First-successful-login trigger — onboarding email + welcome pop-up (added 2026-08-12, Johan)

On the Admin's first successful login (detected via a new `users.first_login_at` nullable
timestamp, stamped once and never again — existing accounts are backfilled to their `created_at`
at migration time so only genuinely new accounts trip this path):

1. If the logging-in user is the `admin_user_id` on an `AgencyOnboardingSetup` row that has not yet
   had its invite email sent (`invite_email_sent_at IS NULL`): send
   `App\Mail\AgencyOnboardingSetupMail` (unchanged Mailable/template — only the trigger moved) and
   stamp `invite_email_sent_at`.
2. The same request flashes a one-time "Thank you for choosing CoreX OS" welcome pop-up (session
   flash, not a persisted dismissal record — Laravel's flash semantics already guarantee it
   surfaces on the very next page load only) with a CTA straight to the onboarding wizard
   (`AgencyOnboardingSetup::publicUrl()`).
3. **Scope:** this fires only for the invited Admin of a pending onboarding setup — not for every
   user's first login system-wide. Regular agents invited via the existing `UserInviteMail` flow
   never see an "agency setup" prompt that isn't theirs; the onboarding wizard itself is already
   Admin-gated (`agency-onboarding-setup.md` §3.3.2), so the pop-up follows the same scope.
4. **Resend:** the platform-owner tracking page (`admin.agency-setup-progress`) gets a "Resend
   invite" action so Johan/Andre can re-trigger `AgencyOnboardingSetupMail` manually if the
   first-login email is lost, the link expires, or the Admin needs it before ever logging in
   (support path — independent of the first-login trigger).

### R1c. Implementation note — explicit service call, NOT the generic Login event (amended 2026-08-12, audit)

§R1b originally wired the above into `AppServiceProvider`'s existing `Event::listen(Login::class,
...)` closure. An audit the same day found this broke on impersonation: `ImpersonateController`
calls `Auth::login($user)`, which fires the identical `Illuminate\Auth\Events\Login` event — so an
owner impersonating a brand-new, still-pending-invite Admin silently consumed the Admin's own
first-login trigger. The mail sent from the owner's action, the pop-up flags landed in the owner's
(impersonating) session, and the real Admin's later genuine login was a no-op.

**Fix:** the logic now lives in `App\Services\Onboarding\AgencyAdminFirstLoginService::handle()`,
called explicitly from only the two genuine-login call sites —
`AuthenticatedSessionController::store()` and `AgencySetupGateController::login()` (the wizard's own
login gate, which passes `showWelcomePopup: false` since that login already lands the Admin in the
wizard — a "go start onboarding" pop-up on top of it would be redundant, though the mail still
sends). Impersonation code simply never calls it — structural prevention, not detect-and-exclude
(BUILD_STANDARD §3). `handle()` also uses an atomic compare-and-swap update (not read-then-write) on
both `first_login_at` and `invite_email_sent_at`, closing a same-day-found race where two
near-simultaneous logins could both pass a check-then-act null check and double-send.

`AgencyAdminFirstLoginService::sendMail()` is also reused by `AgencySetupProgressController::resend()`
and `agency:backfill-onboarding-setups --email` — the backfill command previously sent the mail
without stamping `invite_email_sent_at`, so the same admin's later first login would re-send it; now
both paths stamp on success through the one shared method.

### R2. Admin gets full permission matrix
On creation, the new Admin is granted every permission key in the (post-cleanup) matrix. They can subsequently grant/revoke permissions for other users they create within their agency.

### R3. Last-Admin protection
If an agency has exactly one Admin, that Admin **cannot be deleted, demoted, or have their Admin role revoked** — by anyone, including themselves and System Owner. The UI surfaces this as a disabled action with a tooltip explaining why.

### R4. Admin handover flow
To remove or replace the sole Admin:
1. The current Admin (or a System Owner / System Admin) assigns a second user the Admin role.
2. Once ≥2 Admins exist, the original Admin can be demoted or deleted.

Only an **Admin of the same agency** or a **System Owner / System Admin** may assign the Admin role. Regular users cannot self-promote.

### R5. Permission matrix cleanup
Remove from the agency-level permission matrix entirely:
- `agencies.*` (all keys related to agency CRUD)
- `importer.*` / Importer Settings

These become **System Owner / System Admin only** — accessible from a System area, not the agency permission matrix. Sidebar entries gated accordingly.

---

## Data model

No new tables. Uses existing:
- `users` (`agency_id`, role)
- `agencies`
- `nexus_permissions` (table name unchanged per memory)

Add a model-level helper on `Agency`:
```php
public function admins(): HasMany   // users where role = admin
public function adminCount(): int
public function hasSoleAdmin(User $user): bool
```

---

## UI / UX

### Agency creation wizard (System Owner area)
Step 1: Agency details (name, branding, etc.)
Step 2: **Register First Admin** — required, cannot skip
  - Full name, email, mobile — **no password field** (§R1a: email-only invite, always)
  - On submit: agency + admin created in a single DB transaction; admin gets an unusable
    placeholder password and is emailed a `UserInviteMail` setup link immediately
Step 3: Confirmation

### User management (within agency)
- Admin list shows badge "Sole Admin — protected" when count = 1
- Demote / Delete actions on the sole Admin are disabled with tooltip:
  *"This is the only Admin for this agency. Assign another Admin before removing this user."*
- "Make Admin" action visible to existing Admins and System Owner / System Admin only

### Permission matrix
- `Agencies` row: removed
- `Importer` row: removed
- Both surfaced in a new **System Settings** area (sidebar: System → Agencies / Importer), gated to `system_owner` / `system_admin` roles only

---

## Permissions

Add / confirm:
- `system.agencies.manage` — System Owner / System Admin only
- `system.importer.manage` — System Owner / System Admin only
- `agency.admin.assign` — granted to Admin role + System roles
- `agency.admin.revoke` — granted to Admin role + System roles (subject to R3)

Remove from agency matrix:
- All `agencies.*` keys
- All `importer.*` keys

---

## Enforcement points (defence in depth)

1. **DB / model**: `User::deleting` and role-change observers throw `LastAdminException` if the action would leave the agency with zero Admins.
2. **Controller / FormRequest**: validation rejects demote/delete with a friendly error.
3. **UI**: actions disabled with tooltip — never relied on alone.
4. **Agency creation transaction**: `DB::transaction(fn() => [$agency, $admin] = ...)` — rollback on Admin failure.

---

## User flow — Create new agency (happy path)

1. System Owner: System → Agencies → **+ New Agency**
2. Fills agency details → Next
3. Fills first Admin details (name, email, mobile — no password) → Create
4. Transaction: agency row + admin user row (unusable placeholder password) + role assignment +
   full permission grant
5. Redirect to new agency dashboard, logged-in context unchanged (System Owner stays System Owner)
6. New Admin immediately receives a `UserInviteMail` setup-link email → sets their password →
   signs in → **first successful login** fires the agency-onboarding-setup email (§R1b) and the
   welcome pop-up

## User flow — Replace sole Admin

1. Current sole Admin opens Users → selects another user → **Make Admin**
2. System now has 2 Admins for that agency
3. Original Admin can now be demoted or deleted by either the new Admin or System Owner / System Admin

---

## Acceptance criteria

- [ ] Cannot complete agency creation without registering an Admin (transaction rolls back)
- [ ] New Admin has every (post-cleanup) permission granted
- [ ] Attempting to delete / demote the sole Admin returns a clear error (UI, controller, model — all three layers)
- [ ] Once a second Admin exists, the original can be removed
- [ ] `Agencies` and `Importer` rows do not appear in the agency permission matrix
- [ ] `Agencies` and `Importer` are accessible only to `system_owner` / `system_admin` roles
- [ ] Existing agencies without Admins are not modified or prompted (rule applies to **new** creations only)
- [ ] `dev-check.ps1` passes with 0 new failures
- [ ] All routes registered, named, discoverable in `/admin/api`

---

## Files to create / modify

**Modify:**
- `app/Http/Controllers/.../AgencyController.php` (or System area equivalent) — atomic create flow
- `app/Models/Agency.php` — admin helpers
- `app/Models/User.php` — `deleting` observer / role-change guard
- `database/seeders/CoreXPermissionSeeder.php` — remove `agencies.*` and `importer.*` from agency matrix; add `system.*` keys
- `resources/views/.../permissions/matrix.blade.php` — exclude removed rows
- `resources/views/components/corex-sidebar.blade.php` — gate System area, remove old links
- Agency creation views — multi-step wizard with required Admin step
- User management views — sole-Admin badge + disabled actions

**Create:**
- `app/Exceptions/LastAdminException.php`
- `app/Http/Requests/StoreAgencyWithAdminRequest.php`
- System area scaffolding for Agencies + Importer (controllers, routes, views) if not already isolated
- Tests: `tests/Feature/AgencyAdminRuleTest.php`

**§R1a/§R1b (2026-08-12) — Modify:**
- `resources/views/admin/agencies/create-edit.blade.php` — drop `admin_password` input
- `app/Http/Controllers/Admin/AgencyController.php` — drop `admin_password` validation; create admin
  with `User::pendingInvitePassword()` + `invited_at`; send `UserInviteMail`
- `app/Listeners/Onboarding/CreateAgencySetupPortal.php` — stop sending
  `AgencyOnboardingSetupMail` at creation time
- `app/Models/User.php` — add `first_login_at` to `$casts`
- `app/Models/AgencyOnboardingSetup.php` — add `invite_email_sent_at` to `$fillable`/`$casts`
- `app/Http/Controllers/Admin/AgencySetupProgressController.php` — add `resend()`
- `resources/views/admin/agency-setup-progress/index.blade.php` — add "Resend invite" button
- `routes/web.php` — add `admin.agency-setup-progress.resend` route

**§R1c (2026-08-12, audit fix) — Modify:**
- `app/Providers/AppServiceProvider.php` — REMOVED the `Event::listen(Login::class, ...)`
  first-login block (broke on impersonation — see §R1c above)
- `app/Http/Controllers/Auth/AuthenticatedSessionController.php` — calls
  `AgencyAdminFirstLoginService::handle()` after the `is_active` gate
- `app/Http/Controllers/Public/AgencySetupGateController.php` — calls the same with
  `showWelcomePopup: false`
- `app/Http/Controllers/Admin/AgencySetupProgressController.php` — `resend()` no longer
  resolves the admin via the scoped `admin()` relation (cross-agency scope bug); `index()`
  had the identical bug in its eager load, fixed the same way; both reuse
  `AgencyAdminFirstLoginService::sendMail()`
- `app/Console/Commands/BackfillAgencyOnboardingSetups.php` — `--email` path now stamps
  `invite_email_sent_at` on success (was a duplicate-send bug)
- `resources/views/partials/_env-banner.blade.php` — "Open Mailpit" gate now checks the
  `corex` and `otp` mailers too, not just the default `smtp` one
- `tests/Feature/Onboarding/AgencySetupWizardTest.php` — updated the two tests that asserted
  mail sent at creation time; added coverage for the first-login trigger, impersonation
  exclusion, the wizard-gate login path, backfill non-duplication, and both resend/index
  cross-agency-scope fixes

**§R1a/§R1b (2026-08-12) — Create:**
- Migration: `users.first_login_at` (nullable timestamp, backfilled to `created_at`)
- Migration: `agency_onboarding_setups.invite_email_sent_at` (nullable timestamp)
- `resources/views/layouts/partials/welcome-onboarding-modal.blade.php`

**§R1c (2026-08-12, audit fix) — Create:**
- `app/Services/Onboarding/AgencyAdminFirstLoginService.php`

---

## Out of scope

- Backfilling Admins for existing agencies
- Multi-Admin invitation flows beyond the basic "Make Admin" action
- Admin-role audit log (covered separately if needed)
