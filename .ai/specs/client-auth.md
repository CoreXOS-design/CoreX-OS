# Client Auth — Spec

> Mobile (`corex_mobile`) Client Portal authentication.
> Created: 2026-05-09
> Status: BUILT 2026-05-09 (initial implementation in `andre` branch)
>
> **Design note (post-build):** The original draft put `client_password`/etc.
> directly on the `contacts` table. During implementation this was reworked
> into a separate `client_users` table — one row per real person (keyed by
> globally-unique email), linked from `contacts.client_user_id`. This avoids
> password sync problems when the same person appears as a contact in
> multiple agencies, and lets Sanctum issue tokens against a clean
> Authenticatable model. The user-facing flows in this spec are unchanged.

## Purpose

Give the **client** (a Contact in the system — buyer, seller, tenant, landlord, prospect) their own sign-in path inside the existing CoreX mobile app, separate from the agent/user login. A client signs in with their email, gets a one-time activation OTP, sets a password, and from then on logs in with email + password. Once authenticated they see Client-side features (Core Matches first; more later).

The mobile app login screen splits into two paths:
- **User Login** — existing agent/user flow (unchanged)
- **Client Login** — new flow defined here

## Pillars

- **Contact** (primary) — every client IS a contact; auth is contact-scoped
- **Agent** (secondary) — agency selection scopes the session to one agency's data

## Non-negotiables compliance

- Soft deletes on every new table ✓
- Sidebar/admin nav for the new "Client App Activity" page on day one ✓
- All HTTP endpoints under `/api/v1/*` with `->name()` (auto-listed in `/admin/api`) ✓
- Connects to Contact + Agent pillars ✓
- Permissions in `CoreXPermissionSeeder.php` ✓
- Multi-tenancy: cross-agency identifier lookup is isolated to **one** sanctioned service method (`ClientAuthService::findContactsByIdentifierAcrossAgencies`) — no `withoutGlobalScope` anywhere else. Once an agency is chosen, the request is scoped normally.

## Data model (as built)

### `client_users` (NEW) — one row per real person
| Column | Type | Purpose |
|---|---|---|
| `id` | bigint pk | |
| `email` | string, indexed (see `active_email` below for the real uniqueness rule) | Login identifier. Real or agent-fabricated under `@corexclient.co.za`. |
| `active_email` | string, **generated column**, `IF(deleted_at IS NULL, email, NULL)`, **unique** | Added by migration `2026_08_22_000004` (bug fix — see "Account deletion" below). The real uniqueness boundary: at most one ACTIVE row may hold a given email; any number of soft-deleted rows may share one, so a deleted account's email is immediately reusable by a fresh signup. Never read/write this column directly — it exists purely so MySQL enforces the invariant every call site already assumes via Eloquent's SoftDeletes scope. Hidden from all JSON output. |
| `password` | string, nullable, hashed | bcrypt; nullable until set |
| `password_must_change` | boolean | True when an agent set a temp password — blocks all endpoints except `/password/set` + `/logout` + `/me` |
| `password_set_at` | timestamp, nullable | |
| `activated_at` | timestamp, nullable | First OTP verification |
| `first_login_at` / `last_login_at` | timestamp, nullable | |
| `preferred_agency_id` | FK agencies, nullable | Favourite (does not lock) |
| `locked_to_agency_id` | FK agencies, nullable | When set, picker is skipped — login returns this agency directly |
| `current_agency_id` | FK agencies, nullable | Currently selected agency for this session |
| `created_by_agency_id` | FK agencies, nullable | **Origin agency** — the one that first created the client login (via agent UI or OTP self-signup). Only this agency may reset password, force logout, or remove access. Other agencies see read-only "Managed by …" status. Backfilled for legacy rows from the earliest linked contact's agency. Added 2026-05-12. |
| `last_ip` | string | |
| timestamps + `deleted_at` | | Soft deletes |

Implements `HasApiTokens` (Laravel Sanctum). Tokens carry the `client` ability.

### `contacts` — additions
| Column | Type | Purpose |
|---|---|---|
| `client_user_id` | FK `client_users`, nullable, indexed | Links a contact row to its client identity. A single ClientUser may link to multiple Contact rows across different agencies. |

### `client_otps` (new) — as built
Same intent as draft. Keyed by `email` (so OTPs can be issued before a ClientUser exists), with optional FK to `client_user_id` once known.
| Column | Type |
|---|---|
| `id` | bigint pk |
| `contact_id` | FK contacts |
| `agency_id` | FK agencies (the agency context the OTP was issued under, if known) |
| `code_hash` | string (bcrypt of 6-digit code) |
| `expires_at` | timestamp (10 min) |
| `used_at` | timestamp, nullable |
| `attempts` | tinyint, default 0 (max 5 then invalidated) |
| `ip` | string |
| `created_at` / `updated_at` / `deleted_at` |

### `client_access_logs` (new)
| Column | Type |
|---|---|
| `id` | bigint pk |
| `contact_id` | FK contacts |
| `agency_id` | FK agencies |
| `event` | enum: `lookup`, `otp_sent`, `otp_verified`, `otp_failed`, `password_set`, `password_changed`, `password_login_success`, `password_login_failed`, `password_reset_by_agent`, `agency_selected`, `agency_locked`, `logout`, `screen_viewed`, `account_deleted` (self-service, one row per linked agency/contact — see "Account deletion") |
| `meta` | json (e.g. screen name, agency_id chosen) |
| `ip` | string |
| `user_agent` | string |
| `device_name` | string nullable (Sanctum token name) |
| `created_at` / `deleted_at` |

### `client_signin_attempts` (new) — anonymous prospect tracker
| Column | Type |
|---|---|
| `id` | bigint pk |
| `identifier` | string (email entered) |
| `matched` | boolean |
| `agency_count` | tinyint |
| `ip` | string |
| `user_agent` | string |
| `created_at` / `deleted_at` |

### Sanctum
- Token name: device name from app (`"iPhone 15 — André"`)
- Ability: `client` (distinct from agent tokens which have no/different abilities)
- Expiry: 30 days, sliding (renewed on use via middleware)

## API surface — all under `/api/v1/client-auth/*` and `/api/v1/client/*`

| Method | Route | Name | Auth | Purpose |
|---|---|---|---|---|
| POST | `/api/v1/client-auth/lookup` | `client-auth.lookup` | none | `{email}` → `{exists, requires_otp, requires_password, agencies:[{id,name,is_preferred,is_locked}]}` |
| POST | `/api/v1/client-auth/otp/send` | `client-auth.otp.send` | none | `{email}` — sends OTP via mail; rate-limited 1/min, 5/hour per email+IP |
| POST | `/api/v1/client-auth/otp/verify` | `client-auth.otp.verify` | none | `{email, code}` → activation token (short-lived, 15 min) for password set |
| POST | `/api/v1/client-auth/password/set` | `client-auth.password.set` | activation token OR `auth:sanctum` (ability `client`) when `client_password_must_change` | `{password, password_confirmation}` |
| POST | `/api/v1/client-auth/login` | `client-auth.login` | none | `{email, password, device_name, agency_id?}` → `{token, contact, agencies}` |
| POST | `/api/v1/client-auth/agency/select` | `client-auth.agency.select` | `auth:sanctum` (`client`) | `{agency_id, lock?, favourite?}` |
| POST | `/api/v1/client-auth/password/change` | `client-auth.password.change` | `auth:sanctum` (`client`) | `{current_password, password, password_confirmation}` |
| POST | `/api/v1/client-auth/password/forgot` | `client-auth.password.forgot` | none | `{email}` — issues recovery OTP (real emails only) |
| POST | `/api/v1/client-auth/logout` | `client-auth.logout` | `auth:sanctum` (`client`) | revokes current token |
| DELETE | `/api/v1/client-auth/account` | `client-auth.account.delete` | `auth:sanctum` (`client`) | Apple 5.1.1(v) account-deletion. `{password}` (required unless `password_must_change`). Revokes all tokens, unlinks every linked Contact across every agency, wipes the password, soft-deletes the `ClientUser`. Contact rows are NEVER touched — see "Account deletion" below. Reachable even while `password_must_change=true` (allow-listed in `EnsureClientAbility`) |
| GET  | `/api/v1/client/me` | `client.me` | `auth:sanctum` (`client`) | returns contact summary, current agency, lock state, and (optional) `agent` — the contact's assigned agent in the current agency (capturing/QR-signup agent via `created_by_user_id`); key omitted when none. Shape: `{id, first_name, last_name, full_name, title, phone, whatsapp, email, photo_url}` with empty channels dropped and phones in E.164 |
| GET  | `/api/v1/client/match-options` | `client.match-options` | `auth:sanctum` (`client`) | Listing types, property types, suburb suggestions for the agency |
| GET  | `/api/v1/client/matches` | `client.matches` | `auth:sanctum` (`client`) | List the client's matches in current agency, each with `feedback_summary` counts |
| POST | `/api/v1/client/matches` | `client.matches.create` | `auth:sanctum` (`client`) | Client creates a new match for themselves |
| GET  | `/api/v1/client/matches/{match}` | `client.matches.show` | `auth:sanctum` (`client`) | Match detail + result properties + per-property reaction state |
| PUT  | `/api/v1/client/matches/{match}` | `client.matches.update` | `auth:sanctum` (`client`) | Client edits filters on their own match |
| POST | `/api/v1/client/matches/{match}/feedback/{property}` | `client.matches.feedback` | `auth:sanctum` (`client`) | Body: `{reaction: interested\|not_interested\|saved, note?}` — same shape as the shared web link |
| POST | `/api/v1/client/matches/{match}/view/{property}` | `client.matches.view` | `auth:sanctum` (`client`) | Increment per-property view counter on the match |
| GET  | `/api/v1/client/properties/{property}` | `client.properties.show` | `auth:sanctum` (`client`) | Full property detail (mobile equivalent of the web Live Preview); only properties that appear in the client's match results are accessible |

Rate limits: `lookup`/`login` — 10/min/IP. `otp/send` — 1/min, 5/hour per email+IP. `password/forgot` — 3/hour per email.

## User flows

### Flow A — first activation (real email)
```
[Mobile] tap "Client Login"
  → enter email
  → POST /lookup
      ├ not found → "You're not on any agency's contact list. Ask your agent to add you." (logged to client_signin_attempts)
      └ found, requires_otp=true
          → POST /otp/send → email with 6-digit code
          → user enters code → POST /otp/verify → activation token
          → "Set your password" screen → POST /password/set (with activation token)
          → auto-login: POST /login → sanctum token saved on device
          → if agencies.length > 1 → agency picker
          → land on Client home (Core Matches)
```

### Flow B — agent-created credentials (no/fake email)
```
[Web — Contact detail page] agent clicks "Create client login"
  → modal: email field (pre-filled with auto-generated suggestion under @corexclient.co.za)
           + temp password (auto-generated, copyable)
  → server validates uniqueness, suggests next available if collision
  → saves: client_login_email, client_password (hashed), client_password_must_change=true
  → agent communicates creds to client out-of-band

[Mobile] client signs in
  → POST /lookup → requires_password=true, requires_otp=false
  → POST /login → success but server returns must_change_password=true
  → "Choose a new password" screen → POST /password/set
  → continue as normal
```

### Flow C — repeat login
```
[Mobile] (saved token, app open) → GET /client/me → resume session
  └ on token expiry / logout → enter email + password → POST /login
        ├ locked_to_agency_id set → straight to home
        └ multiple agencies, no lock → picker every time until "Only use this agency" tapped
```

### Flow D — forgot password
```
[Mobile] "Forgot password" link
  → POST /password/forgot {email}
      ├ real email → OTP sent → /otp/verify → /password/set
      └ fake email (no MX / @hfc.local domain marker) → response: "Contact your agent to reset your password"

[Web — agent] Contact detail → "Reset client password" → generates temp + must_change=true
```

## Account deletion (added 2026-08-13)

**Purpose:** Apple App Store Review Guideline 5.1.1(v) — an app that supports account
creation must offer in-app account deletion, not just deactivation, and (when the
deletion isn't fully self-contained in-app) a direct link to finish it. CoreX's
deletion is fully self-contained in-app — no website hop needed.

**What "delete my account" means here:** the client's `Contact` row is agency business
data (FICA/POPIA/deal-history record) — it is never deleted by this endpoint, and the
client cannot delete it themselves (non-negotiable #1 also means it could only ever be
soft-deleted by staff, never by a self-service client action). What Apple's requirement
maps to is the **login identity** — the `ClientUser` row and its ability to authenticate.
Deleting that fully satisfies the guideline: after this call, the email can never sign
back in, no data about the client is retrievable through the app, and a fresh signup
with the same email starts completely clean (Flow A from scratch).

**Endpoint:** `DELETE /api/v1/client-auth/account` (`client-auth.account.delete`),
`auth:sanctum` + ability `client`. Body: `{ "password": "..." }` — required unless the
account is currently in `password_must_change` state (mirrors `password/change`'s
validation shape). Wrong password → 422, nothing is touched. Deliberately reachable even
while `password_must_change=true` (allow-listed in `EnsureClientAbility`) — a client must
always be able to delete, never gated behind an unrelated forced flow.

**What it does, in order:**
1. Loads every `Contact` linked to this `ClientUser` across every agency (the same
   sanctioned cross-agency read as `agenciesFor()`/lookup).
2. Logs one `account_deleted` row per linked agency/contact (or a single row with no
   agency/contact if none are linked), then nulls `contacts.client_user_id` on each —
   the Contact itself, its deal/document/FICA history, and its `client_access_logs`
   trail are all preserved untouched.
3. Revokes every Sanctum token for the `ClientUser` (all devices signed out immediately).
4. Wipes the password, resets `password_must_change=true`, clears
   `preferred_agency_id`/`locked_to_agency_id`/`current_agency_id`.
5. Soft-deletes the `ClientUser` row (`deleted_at` — never a hard delete, non-negotiable
   #1). Standard model scoping means every other endpoint (`lookup`, `login`, etc.) then
   behaves exactly as if the email never signed up.

**Origin-agency lock does not apply.** `ClientLoginController::ensureOriginAgency()`
restricts *agent-initiated* actions (reset/force-logout/remove) on agency-managed
logins to the owning agency. This endpoint is *client-initiated on their own account* —
the origin lock is irrelevant here; a client can always delete their own login
regardless of which agency created it.

**Admin visibility:** the `account_deleted` rows land in `client_access_logs` exactly
like every other event, so they surface in the (planned) "Client App Activity" admin
page per agency once that page is built — see "Deliberately NOT yet built" note below.

## Web (admin / agent) UI

### Contact detail page — new "Client App Access" panel
- If no `client_login_email`: **[Create client login]** button → modal (email + temp password fields, generate suggestions)
- If set:
  - Status row: Activated YES/NO • First login • Last login • Password set at • Lock state
  - **[Reset password]** (sets must_change=true)
  - **[Force logout all devices]** (revoke all sanctum tokens with ability `client`)
  - **[Remove client access]** (clears `client_login_email`/`client_password`, keeps logs)
  - Recent activity list (last 20 from `client_access_logs`)

### Admin → "Client App Activity" page (NEW sidebar entry under Admin)
- System-wide table: contact, agency, event, IP, device, when
- Filters: event, agency, date range, contact search
- Tabs: **Activity** | **Sign-in attempts (no match)** — second tab surfaces prospects who tried to sign in but aren't on any contact list

### Sidebar (web)
- New entry under Admin → **Client App Activity** (gated by `client_app.view_logs`)

## Permissions (new keys in `CoreXPermissionSeeder.php`)

| Key | Allows |
|---|---|
| `client_app.create_login` | Create/edit client login email + temp password on a contact |
| `client_app.reset_password` | Reset a client's password (sets must_change) |
| `client_app.force_logout` | Revoke client tokens |
| `client_app.remove_access` | Wipe client_login_email/password from a contact |
| `client_app.view_logs` | View Client App Activity admin page + per-contact panel |

Default: agents get `create_login` + `reset_password` for their own contacts; admins get all five system-wide.

## Fake-email generation (agent-created logins)

When an agent opens "Create client login" and the contact has no real email (or chooses to override it), the server auto-suggests an address under the dedicated domain `@corexclient.co.za`. This domain is NOT a deliverable mailbox — it is purely a login identifier.

**Algorithm** (`ClientAuthService::generateFakeLoginEmail(Contact $contact)`):
1. Build base slug from contact name:
   - Prefer `first_name` (lowercase, ASCII-folded, alpha-numeric only). Fallback to first token of `full_name`. Fallback to `client` if no name available.
   - Strip diacritics (`André` → `andre`), strip spaces/punctuation.
2. Candidate = `{slug}@corexclient.co.za`.
3. Query: `SELECT 1 FROM contacts WHERE LOWER(client_login_email) = ? LIMIT 1` — case-insensitive, **across all agencies** (uses the same sanctioned cross-agency lookup as identifier resolution; this is the second sanctioned `withoutGlobalScope` site, identically tagged).
4. If taken, increment suffix: `{slug}1@`, `{slug}2@`, … until free. Hard-cap at 9999; beyond that append a 4-char random suffix.
5. Returned to the modal as a *suggestion* — the agent may edit the local-part. On submit, server re-validates uniqueness; if a race produced a collision, return 422 with the next suggestion.
6. Domain is fixed by config (`config/clientauth.php` → `fake_email_domain`, default `corexclient.co.za`) so it can be changed without code edits.

Examples:
- `André Roets` → `andre@corexclient.co.za` (or `andre1@…` if taken)
- `Jonté O'Brien` → `jonte@corexclient.co.za`
- contact with only company name "Acme Pty" → first token `acme@corexclient.co.za`
- nameless lead → `client@corexclient.co.za`, then `client1@…`, etc.

The agent may also paste a fully custom email if the client wants something specific — same uniqueness check applies.

## Email (OTP delivery)

- New mailable: `App\Mail\ClientAuthOtpMail` — branded with the **selected** (or matched single) agency's brand colours when known, else CoreX default.
- **From:** `Otp@corexos.co.za` (display name: `CoreX OS`)
- **Reply-To:** `noreply@corexos.co.za` (or per-agency support email when single-agency match)
- Subject: `Your CoreX sign-in code: 123456`
- Body: code, 10-min expiry, "If you didn't request this, ignore this email."
- SMTP credentials live in `.env` ONLY — never committed. New env keys:
  - `MAIL_OTP_MAILER=smtp` (or reuse default mailer with `from` override on the mailable)
  - `MAIL_OTP_FROM_ADDRESS=Otp@corexos.co.za`
  - `MAIL_OTP_FROM_NAME="CoreX OS"`
  - `MAIL_OTP_USERNAME=Otp@corexos.co.za`
  - `MAIL_OTP_PASSWORD=` (set on local + production `.env` only)
  - `MAIL_OTP_HOST=` / `MAIL_OTP_PORT=` / `MAIL_OTP_ENCRYPTION=` (from corexos.co.za mail provider — confirm before build)
- Add a dedicated `otp` mailer entry in `config/mail.php` reading these env keys, so OTP mail is sent through the dedicated mailbox without affecting other transactional mail.

## Files (as built — 2026-05-09)

### New
- `database/migrations/2026_05_09_120001_create_client_users_table.php`
- `database/migrations/2026_05_09_120002_add_client_user_id_to_contacts.php`
- `database/migrations/2026_05_09_120003_create_client_otps_table.php`
- `database/migrations/2026_05_09_120004_create_client_access_logs_table.php`
- `database/migrations/2026_05_09_120005_create_client_signin_attempts_table.php`
- `app/Models/ClientUser.php` (Authenticatable + HasApiTokens + SoftDeletes)
- `app/Models/ClientOtp.php`
- `app/Models/ClientAccessLog.php`
- `app/Models/ClientSigninAttempt.php`
- `app/Services/ClientAuthService.php` — houses `findContactsByIdentifierAcrossAgencies()` and `isClientEmailTaken()` (sole sanctioned `withoutGlobalScope(AgencyScope|ContactScope)` sites, comment-tagged with reference to this spec)
- `app/Http/Controllers/Api/V1/ClientAuthController.php`
- `app/Http/Controllers/Api/V1/ClientPortalController.php` (`/me`, `/matches`)
- `app/Http/Controllers/Admin/ClientAppActivityController.php`
- `app/Http/Controllers/Contacts/ClientLoginController.php` (web — create/reset/force-logout/remove)
- `app/Http/Middleware/EnsureClientAbility.php` (Sanctum ability check + must_change_password gate)
- `app/Mail/ClientAuthOtpMail.php`
- `resources/views/emails/client-auth/otp.blade.php`
- `resources/views/admin/client-app-activity/index.blade.php`
- `resources/views/corex/contacts/partials/client-app-access.blade.php`
- `config/clientauth.php`

### Modified
- `bootstrap/app.php` — registered `client.ability` middleware alias
- `config/mail.php` — added dedicated `otp` SMTP mailer
- `config/corex-permissions.php` — added 5 new keys (synced via `corex:sync-permissions`)
- `routes/api.php` — `/api/v1/client-auth/*` and `/api/v1/client/*` groups
- `routes/web.php` — admin activity route + contact client-login routes
- `app/Models/Contact.php` — added `client_user_id` to fillable + `clientUser()` relation + `hasClientLogin()` helper
- `resources/views/corex/contacts/show.blade.php` — include client-app-access partial in Info tab
- `resources/views/layouts/corex-sidebar.blade.php` — Admin → Client App Activity entry

### Deferred to a follow-up commit
- `tests/Feature/ClientAuth/*` — full test coverage for the 11 endpoints
- `.ai/MOBILE_APP.md` — document the new endpoints
- Mobile-app prompt — to be written once endpoints are smoke-tested live

### Known gap (found 2026-08-13, NOT fixed by the account-deletion prompt)
This "Files (as built)" list has claimed since 2026-05-09 that
`app/Http/Controllers/Admin/ClientAppActivityController.php`,
`resources/views/admin/client-app-activity/index.blade.php`, the admin route, and the
sidebar entry exist. **They do not** — verified absent from the repo while adding the
account-deletion endpoint. `client_app.view_logs` permission exists but gates nothing.
This is a pre-existing discrepancy, not introduced by this prompt — flagged here rather
than silently building the missing admin page as unscoped extra work. Needs its own
prompt: build the controller/view/route/sidebar entry per the "Admin → Client App
Activity" section below, or strike the claim from this file if it's been deliberately
deprioritised.

## Files (account deletion — added 2026-08-13)

### Modified
- `app/Http/Controllers/Api/V1/ClientAuthController.php` — `deleteAccount()`
- `app/Services/ClientAuthService.php` — `allContactsFor()` (sanctioned cross-agency read)
- `app/Http/Middleware/EnsureClientAbility.php` — allow-listed `client-auth.account.delete` under `password_must_change`
- `routes/api.php` — `DELETE /api/v1/client-auth/account`
- `tests/Feature/ClientAuth/ClientAuthFlowTest.php` — 5 new tests
- `.ai/specs/client-auth-MOBILE-PROMPT.md` — added the Settings-screen "Delete account" row

## Acceptance criteria

1. Cross-agency lookup returns every agency a given email appears on; no other code path uses `withoutGlobalScope(AgencyScope)`.
2. Contact A (Agency X) cannot read Contact B (Agency Y) data through any client endpoint, even when their emails differ only by case or whitespace.
3. OTP: 6 digits, 10-min expiry, 5 attempts then invalid; rate limits enforced.
4. After successful OTP verify, only `password/set` accepts the activation token; it cannot be used as a long-lived session.
5. Sanctum tokens carry ability `client`; agent endpoints reject them, client endpoints reject agent tokens.
6. `client_password_must_change=true` blocks every endpoint except `/password/set` and `/logout` until cleared.
7. Multi-agency contact: picker shown every login until `/agency/select` called with `lock=true`; from then on `/login` returns the locked agency directly.
8. "Forgot password" on a fake-email contact returns a friendly "contact your agent" message and creates **no** OTP row.
9. Every state-changing event lands in `client_access_logs` with IP + device.
10. `/admin/api` lists all 11 new endpoints; each has `->name()` and the `api/v1/client*` URI prefix.
11. Sidebar shows "Client App Activity" for users with `client_app.view_logs`; gated 403 otherwise.
12. `php artisan view:clear && route:clear && cache:clear`, `php -l` clean on all changed PHP, `scripts/dev-check.ps1` passes with 0 new failures.
13. New migrations roll back cleanly.
14. Soft-delete preserved on all new tables; "Remove client access" does NOT hard-delete logs.
15. `DELETE /api/v1/client-auth/account` never hard-deletes anything; the `ClientUser` row is soft-deleted, every linked `Contact` row and its history is untouched (only `client_user_id` is nulled), and every `client_access_logs`/`client_signin_attempts` row is preserved.
16. After account deletion, `/lookup` and `/login` for that email behave exactly as a fresh, never-signed-up email (soft-deleted `ClientUser` is excluded from the default query scope).
17. Account deletion is reachable even while `password_must_change=true` (allow-listed in `EnsureClientAbility`) — a client is never blocked from deleting their own account.
18. After account deletion, a fresh signup (OTP flow) with the SAME email succeeds end to end (send OTP → verify OTP → `findOrCreateClientUser` creates a genuinely new, distinct `ClientUser` row) with no 500 and no false "invalid code" caused by the OTP being consumed on a failed prior attempt.

### Bug found & fixed same day (2026-08-13) — stale unique index blocked re-signup after deletion
`client_users.email` originally carried a plain global-unique index. Soft-deleting a `ClientUser`
(account deletion) does not free that email at the DB level, so a real re-signup after deletion hit
a 1062 duplicate-entry error inside `findOrCreateClientUser()` — AFTER `OtpService::verify()` had
already marked the OTP as used (single-use, written one line earlier), so the user saw a server
error on the correct code, then "Invalid or expired code" on retrying the SAME correct code (already
consumed). Confirmed live in production within minutes of the endpoint shipping (real row:
`a.roets12@gmail.com`, soft-deleted 2026-08-13 13:31:19). Root-cause fixed via migration
`2026_08_22_000004_scope_client_users_email_unique_to_active_rows` — see `active_email` above.
Verified in production (rolled-back transaction: a fresh insert with the same email as the trashed
row now succeeds; two ACTIVE rows with the same email still correctly collide). No manual data
cleanup was needed — the blocking row's `active_email` becomes NULL automatically once the
migration lands, since its `deleted_at` was already set.

## Cross-agency contact linking (added 2026-05-12)

Two related rules govern how a single `ClientUser` (one real person, keyed by email) interacts with Contact rows in multiple agencies:

### Rule 1 — Auto-link on Contact creation
When a `Contact` is being created with a non-empty `email`, `ContactObserver::creating()` checks whether a `ClientUser` already exists for that email. If yes, the new Contact's `client_user_id` is set automatically. The client therefore immediately sees the new agency in their app's agency picker on next session refresh — no action required from staff. Staff in the new agency see the contact as already having a client login (the "Create Client Login" button is hidden and "Managed by {origin agency}" is shown instead).

### Rule 2 — Origin-agency lock on login management
The origin-agency lock applies **only to agency-managed logins** — those fabricated by an agency with a non-deliverable fake email under the configured `fake_email_domain` (`@corexclient.co.za`) plus an agent-set temp password. For those, the agency that **first created** the login owns its lifecycle. Only that agency may:
- Reset the password
- Force logout all devices
- Remove client app access

Other agencies see the panel in read-only mode with a "Managed by {Origin Agency Name}" notice.

**Self-service logins are never locked.** When a client self-registers with a real email and sets their own password via OTP, they own their credentials and can self-recover (forgot-password OTP). No agency "owns" such a login, so the origin-agency lock does **not** apply — any agency the client is linked to sees the full management panel. The discriminator is `ClientUser::isAgencyManaged()` (email ends with the fake-email domain).

This is enforced both in the blade view (`client-app-access.blade.php`) and server-side in `ClientLoginController::ensureOriginAgency()` (returns 403 on mismatch, only for agency-managed logins).

The origin is recorded in `client_users.created_by_agency_id`:
- Set to `contact->agency_id` in `ClientLoginController::create()` when an agent creates the login.
- Set to the earliest linked contact's `agency_id` in `ClientAuthService::findOrCreateClientUser()` when the client self-signs up via OTP.
- Backfilled by migration `2026_05_12_170000_add_created_by_agency_id_to_client_users.php` for existing rows.

Legacy rows with NULL origin are treated as unrestricted (any agency may manage) until the next sign-in or agent action backfills the origin.

## Out of scope (v2)

- WhatsApp / SMS OTP channels
- Biometric (FaceID/fingerprint) unlock
- Push notifications to clients
- Client-initiated chat with agent
- Client viewing deals / documents (separate spec)

## Mobile prompt

A standalone mobile-app prompt (`.ai/specs/client-auth-MOBILE-PROMPT.md`) will be written **after** this API ships and the endpoints have been smoke-tested, so it can reference real request/response shapes rather than guesses.
