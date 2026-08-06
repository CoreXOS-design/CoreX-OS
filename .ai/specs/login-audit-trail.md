# Login Audit Trail — Spec

> Status: Approved by Johan (verbal, in-session, 2026-08-04) — triggered by a
> real request to list every IP that has signed into a staff account
> (Elise@hfcoastal.co.za). Investigation found CoreX had NO durable record of
> this: the only IP capture was the `sessions` table, which is pruned by
> Laravel's session GC and only reflects the current/very-recent session.

## 1. What this feature does and why

Every successful login and logout by a staff/agent user (`App\Models\User`,
not the buyer-portal `ClientUser`) is appended to a permanent,
never-pruned `login_histories` table recording who, when, from which IP,
and which user agent. This gives Johan/admins a real answer to "who signed
into this account, and from where" — for security investigation (suspected
account compromise, unrecognised device, unusual location) — without relying
on session-table state that expires.

This is an **audit log**, not a user-editable entity: append-only, read-only
UI, no create/edit/delete surface. It follows the same shape as the existing
`impersonation_logs` table/`ImpersonationLog` model (recorded in
`ImpersonateController`), which is CoreX's only prior precedent for this kind
of IP-stamped security audit trail.

## 2. Pillars

- **Agent** (`User`) — every row is scoped to exactly one `User`. This is the
  audit trail of the Agent pillar's own authentication activity.

No Property/Contact/Deal linkage — a login event is not a business
transaction, it is account-security telemetry about the Agent pillar itself.

## 3. Data model

New table `login_histories` (mirrors `impersonation_logs` structure):

```
id              bigint PK
user_id         FK -> users.id (constrained)
event           enum('login','logout')
ip_address      varchar(45) nullable   -- IPv4 or IPv6
user_agent      text nullable
created_at      timestamp, useCurrent()
```

- `$timestamps = false` on the model (single `created_at`, no `updated_at`) —
  matches `ImpersonationLog`.
- Indexes: `(user_id, created_at)` for the per-user lookup this feature exists
  to answer.
- No `agency_id` column: unlike business-data pillars, an auth event is
  scoped by `user_id` alone, and the owning user's `agency_id` is reachable
  via the `user_id` relationship for any agency-scoped reporting later. This
  matches `impersonation_logs`, which has no `agency_id` either.
- No soft deletes: this is an append-only audit log. There is no delete
  affordance in the UI (matches non-negotiable #1's spirit — nothing here is
  ever "deleted" by a user in the first place, so there is nothing to soft-
  delete).

## 4. Capture mechanism

Two `Illuminate\Auth\Events\Login` / `Illuminate\Auth\Events\Logout` listeners
already exist in `app/Providers/AppServiceProvider.php` (they currently reset
agency-switcher session state on auth transitions). This feature adds a
`LoginHistory::create()` call inside each of those two existing closures —
no new listener class, reusing the established location for auth-transition
side effects in this codebase.

- `Login` → `event' => 'login'`, `ip_address` = `request()->ip()`,
  `user_agent` = `request()->userAgent()`.
- `Logout` → `event' => 'logout'`, same IP/UA capture, guarded on
  `$event->user` being non-null (defensive — Laravel always sets it, but
  Rule 17 in STANDARDS.md forbids assuming a non-null receiver).

Out of scope (deliberately, to keep this prompt to what was asked): **failed
login attempts** (`Illuminate\Auth\Events\Failed`) are not captured here. The
request was "IPs that have signed in" (successful logins). Failed-attempt
tracking is a reasonable follow-up but is a distinct feature (brute-force /
intrusion detection) and would need its own spec + threshold/alerting design.

## 5. UI placement and navigation entry

A **"Login History"** panel is added to the existing Admin → Users → Edit
page (`resources/views/admin/users/create-edit.blade.php`, served by
`UserManagementController::edit()`), already reachable via
`/admin/users/{user}/edit` from the Users list — no new route, no new nav
entry needed, since this hangs off a page that already has one. The panel
shows the 25 most recent rows (event, IP, user agent, timestamp), newest
first, for the user being edited. It is visually a read-only table (no edit
affordance) directly under the account tab, gated by the permission below.

## 6. User flow

1. Admin (owner or `manage_users`-permitted, and gated further by
   `users.login_history.view`) opens Users → clicks a user → Edit.
2. Scrolls to "Login History" panel.
3. Sees every login/logout event for that user with IP + user agent + when.

## 7. Permissions

New key added to `config/corex-permissions.php`:

```
['key' => 'users.login_history.view', 'label' => 'View User Login History',
 'section' => 'franchise-admin', 'type' => 'action', 'module' => 'users',
 'sort_order' => 17]
```

- Not added to any role's `exclude` list → `admin` role gets it automatically
  via the existing all-minus-exclude default (same behaviour as
  `manage_users`).
- `super_admin` (owner) gets it via the `'*'` wildcard.
- Any other role must be granted it explicitly via Role Manager (standard
  behaviour for new permissions on an existing install — role_defaults only
  apply on fresh installs per the file's own header comment).
- Controller enforces it explicitly (`hasPermission('users.login_history.view')`)
  in addition to the existing `manage_users` gate the edit page already
  requires — IP/device data is more sensitive than the rest of the edit page,
  so it gets its own explicit check rather than riding free on `manage_users`.

## 8. Acceptance criteria

- [ ] A successful login by any `User` writes one `login_histories` row with
      `event = 'login'`, the real request IP, and the request user agent.
- [ ] A logout writes one `login_histories` row with `event = 'logout'`.
- [ ] `SELECT ip_address, user_agent, created_at FROM login_histories WHERE
      user_id = ? ORDER BY created_at DESC` returns the full history — not
      just the current session — for any user, indefinitely (no GC/pruning).
- [ ] The Admin → Users → Edit page shows a Login History panel for
      `users.login_history.view`-permitted admins, and does NOT show it (or
      leak IP data) for admins who only have `manage_users`.
- [ ] No regression to the two existing `Login`/`Logout` listener behaviours
      (agency-switcher session reset, default managed branch).

## 9. Files to create or modify

- `database/migrations/2026_08_04_120000_create_login_histories_table.php` (new)
- `app/Models/LoginHistory.php` (new)
- `app/Providers/AppServiceProvider.php` (modify — add `LoginHistory::create()`
  calls inside the existing `Login`/`Logout` listeners)
- `config/corex-permissions.php` (modify — add `users.login_history.view`)
- `app/Http/Controllers/Admin/UserManagementController.php` (modify — `edit()`
  loads recent login history when the viewer has the new permission)
- `resources/views/admin/users/create-edit.blade.php` (modify — render the
  panel)
- `tests/Feature/Admin/LoginHistoryTest.php` (new)
- `database/schema/mysql-schema.sql` (regenerated via `schema:dump` per
  non-negotiable #12a, same commit as the migration)

## 10. Deliberately NOT in the Agency Onboarding Setup Wizard

This is not an agency-configurable setting (no toggle, no threshold, nothing
an agency chooses) — it is unconditional security telemetry, always on, for
every agency. Non-negotiable #10a's wizard-surfacing requirement applies to
settings; this ships with no setting to surface.
