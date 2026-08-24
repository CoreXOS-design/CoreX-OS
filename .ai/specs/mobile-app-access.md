# Mobile App Access ("Delete My Account") — Spec

> **Status:** APPROVED — Johan approved the three business decisions in §7 live in
> chat, 2026-08-24. Technical/architectural calls below are the implementer's,
> per CLAUDE.md rule #8.
> **Author:** Claude (agent), drafted 2026-08-24
> **Trigger:** Apple App Store guideline 5.1.1(v) — an app that supports account
> creation must also let the user delete their account from inside the app.

---

## 1. What this feature does and why

CoreX's agent-facing mobile app lets an agent log in with their normal CoreX
credentials (`POST /v1/login`, a plain Sanctum token, no separate "mobile
account" — it's the same `App\Models\User` row used everywhere else in CoreX).
Apple's reviewers require a "delete my account" path reachable from inside the
app. A real delete of the `User` row is wrong here: an agent's CoreX account
carries deals, commissions, FICA history and compliance records that belong to
the agency, not solely to the individual, and non-negotiable #1 forbids hard
deletes outright.

**App Access** is the correct-shaped answer: a per-user flag, ON by default,
that gates mobile/API login only. When an agent taps "Delete my account" in
the app, App Access turns off — the app can no longer log them in, satisfying
Apple's requirement — while their CoreX web account, deals, and employment
record are completely untouched. They can turn it back on themselves at any
time from **My Portal → Tools** on the CoreX website (Johan, 2026-08-24 — this
is reversible self-service, not a real account deletion).

## 2. Pillar connections

| Pillar | Relationship |
|--------|--------------|
| **Agent** (`User`) | **Reads and writes.** New `app_access_revoked_at` column on `users`. This is the one and only place this data lives — no separate table, because it is a single fact about one existing pillar record, not a new kind of entity. |

## 3. Data model

### 3.1 `users.app_access_revoked_at`

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `app_access_revoked_at` | timestamp | NULL | NULL = App Access ON (the default for every existing and new row — no backfill needed). Non-NULL = OFF, and records exactly when. |

No new `app_access_enabled` boolean: a nullable timestamp gives the same
on/off semantics AND a free audit fact ("when"), matching the existing
`system_updates.published_at` / `users.first_login_at` convention in this
codebase rather than adding a second column that could drift out of sync with
a boolean.

Deliberately NOT mass-assignable (`$fillable`) — it is set only by the two
dedicated model methods below, never by a generic profile-update form.

### 3.2 Model methods (`App\Models\User`)

- `hasAppAccess(): bool` — `is_null($this->app_access_revoked_at)`.
- `revokeAppAccess(): void` — stamps the timestamp, deletes only the
  `corex-mobile`-named Sanctum tokens (never a Chrome-extension `api_token` or
  any other personal token the user generated on the Tools tab — see §9.1),
  and deletes the user's `device_tokens` rows so a revoked account also stops
  receiving push notifications.
- `restoreAppAccess(): void` — clears the timestamp. No token/device-token
  side effect — the agent logs into the app again normally and a fresh token
  and device-token row are created then, exactly like first-time login.

## 4. API endpoints

### 4.1 Login gate — `POST /v1/login` (and its legacy alias `POST /login`)

After the existing password check and before issuing a token: if
`! $user->hasAppAccess()`, respond `403` with
`{"message": "This account has been deleted.", "code": "account_deleted"}`
and issue **no token**. Placed after the password check (not before) so a
wrong-password guess can never be used to probe whether an account has
deleted app access.

### 4.2 Delete — `DELETE /api/v1/me/app-access`

`auth:sanctum`, self-scoped (`$request->user()`), name
`v1.me.app-access.destroy`, catalogued automatically at `/admin/api` per
non-negotiable #7.

Body: `{"password": "..."}` — required, checked against the account's current
password (same defensive pattern as every other destructive self-action in
this codebase, e.g. the existing web "Delete Account" button on My Portal →
Password). Wrong password → `422` `{"message": "Incorrect password.", "code":
"invalid_password"}`, nothing changes. Correct password → calls
`revokeAppAccess()`, returns `200` with a confirmation message. Idempotent —
calling it again while already revoked succeeds harmlessly (matches
BUILD_STANDARD's "no destructive action errors on a no-op").

### 4.3 Defense in depth — `App\Http\Middleware\EnsureAppAccess`

**Investigated and NOT used:** `app/Http/Middleware/Authenticate.php` looks
like the natural place for this (it already logs out/403s an authenticated
request when `! $user->is_active`), but it is dead code — grepped, it is
never bound to the `auth` middleware alias or referenced anywhere in
`bootstrap/app.php`, so its existing `is_active`/agency-disabled checks are
themselves never actually invoked by the framework. Reported to Johan as an
out-of-scope finding (see CHAT_STARTER); not touched here.

Instead, a small new middleware (`app/Http/Middleware/EnsureAppAccess.php`,
aliased `app_access`) is applied to the real `auth:sanctum` route group in
`routes/api.php` that every mobile/API-token route lives in — this file is
already documented (`bootstrap/app.php`) as bearer-token-only, mobile-only
(Sanctum's stateful-cookie promotion is stripped from the `api` middleware
group), so this can never reach a web session by construction, no guard-name
check needed. The one exception is the delete-account route itself
(`->withoutMiddleware(EnsureAppAccess::class)`), so it stays reachable — and
idempotent — even after access is already off.

## 5. Web UI — My Portal → Tools

New card on the existing Tools tab (`resources/views/agent/portal.blade.php`,
alongside Theme Preference / API Token / Chrome Extension), unconditional —
every agent has this, unlike the other Tools cards which are gated by an
agency setting.

- **App Access: Enabled** (default state) — plain status line, nothing to
  click. This is what almost every agent sees, always.
- **App Access: Disabled — turned off via the mobile app on {date}** plus a
  **Turn App Access back on** button, shown only when
  `! $user->hasAppAccess()`.

### 5.1 Route

`POST /my-portal/app-access/restore`, name `agent.portal.app-access.restore`,
middleware `permission:edit_own_profile` + `agency.required` (same convention
as the existing `agent.portal.profile.update` route it sits beside),
controller `AgentPortalController::restoreAppAccess()`.

No web-side "turn off" control — the only way App Access turns off is the
mobile app's delete-account action (§4.2). A web toggle to turn it off was
not asked for and would just be a second, weaker path to the same
destructive-feeling action a user already has to confirm inside the app.

## 6. Robustness (BUILD_STANDARD §2, §3)

- **Wrong password on delete** → prevented, `422`, nothing changes, and the
  account remains fully accessible.
- **Delete called twice** → absorbed, idempotent, no error.
- **Restore called when already enabled** → absorbed, no-op, no error.
- **Login attempted after deletion** → prevented with a clear, Apple-review-
  legible message; no token issued.
- **An already-issued token used after deletion** → prevented on the very
  next authenticated request (§4.3), not just at the next login attempt.
- **Deleting app access must never touch:** the web session/login, the
  Chrome-extension `api_token`, any other named personal API token, deals,
  commissions, FICA records, or the `User` row itself. All four are
  structurally impossible to touch here — `revokeAppAccess()` only ever
  writes one column plus deletes rows explicitly scoped to
  `name = 'corex-mobile'` (tokens) or `user_id` (device tokens).

## 7. Decisions on the record

| # | Decision | Made by | Date |
|---|----------|---------|------|
| 1 | Turning off App Access affects the mobile app only — the web CoreX account is completely unaffected | Johan | 2026-08-24 |
| 2 | Lives on My Portal → Tools, self-service both ways — the agent can turn it back on themselves, no admin override needed | Johan | 2026-08-24 |
| 3 | Login attempt after deletion must show an "account has been deleted"-style message, to satisfy Apple's review | Johan | 2026-08-24 |
| 4 | A nullable timestamp (not a real row/table delete) — the underlying `User` row and all business data are untouched | Claude (spec, technical) — non-negotiable #1 forbids a hard delete regardless | 2026-08-24 |
| 5 | Revoke also clears push device tokens, so a "deleted" account stops receiving push notifications too | Claude (spec, technical) | 2026-08-24 |

### Still open
Nothing. Buildable.

## 8. Permissions

No new permission key. The delete endpoint is self-scoped via `auth:sanctum`
+ `$request->user()` (identical convention to `/v1/me/theme`) — there is no
"delete someone else's app access" surface to protect. The web restore route
reuses the existing `edit_own_profile` permission already gating every other
My Portal self-service write.

## 9. Notes for the mobile app team

### 9.1 Token naming is load-bearing
Mobile login issues a Sanctum token named exactly `corex-mobile`
(`$user->createToken('corex-mobile')`, `routes/api.php`). `revokeAppAccess()`
deletes only tokens with that exact name, so a personal API token an agent
generated on the Tools tab for the Chrome extension (a different mechanism,
`users.api_token`, unrelated to Sanctum) is never touched by an app deletion.
Nothing for the mobile app to do about this — it's a server-side guarantee —
but it explains why deleting the account from the app cannot accidentally
break an unrelated integration.

### 9.2 What the app must do after a successful delete
The server does not (and cannot) revoke a bearer token still held on the
device beyond deleting it server-side — the app must discard its locally
stored token and any cached session state immediately on a successful
`DELETE /v1/me/app-access` response, and return to the logged-out state, the
same as if the user had manually logged out.

## 10. Files to create / modify

**Create**
```
database/migrations/2026_08_24_000003_add_app_access_revoked_at_to_users_table.php
app/Http/Controllers/Api/V1/AppAccessController.php
app/Http/Middleware/EnsureAppAccess.php
tests/Feature/Api/AppAccessTest.php
```

**Modify**
```
app/Models/User.php                          hasAppAccess() / revokeAppAccess() / restoreAppAccess()
bootstrap/app.php                             app_access middleware alias
routes/api.php                                login-handler gate + app_access group gate + DELETE /v1/me/app-access
app/Http/Controllers/Agent/AgentPortalController.php   restoreAppAccess()
routes/web.php                                POST /my-portal/app-access/restore
resources/views/agent/portal.blade.php        Tools tab — App Access card
database/schema/mysql-schema.sql              re-dumped (#12a)
.ai/CHAT_STARTER.md                           status move on landing
```

**Investigated, NOT modified:** `app/Http/Middleware/Authenticate.php` —
looked like the right place for §4.3, turned out to be dead code (never
bound as the `auth` alias). Left untouched; flagged to Johan separately.
