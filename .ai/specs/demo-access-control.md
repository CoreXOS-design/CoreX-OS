# Demo Access Control — Spec

> **Status:** DRAFT — authored by Claude 2026-07-11 from the AT-230 build prompt plus
> direct codebase investigation. The original spec was never committed to this repo
> (verified: absent from `.ai/specs/`, absent from every ref in `git log --all`).
> **Johan must review §0 before this is treated as approved.**
>
> Module: Demo Access Control · Ticket: AT-230 · Branch: `AT-147-Demo-mode-hide`

---

## §0 — Decisions Claude invented (REVIEW THESE FIRST)

The build prompt carried every hard architectural decision (instance split, the
correctness invariants, the landmines, the build order). It did **not** carry the
concrete contracts. The following are therefore **my** decisions, not Johan's. Each
is cheap to change *before* the migrations run; expensive after.

| # | Decision | What I chose | Why |
|---|----------|--------------|-----|
| D1 | **API namespace** | `/api/v1/demo-access/*` | `/api/v1/demo/*` is **already taken** by the mobile-app group at `routes/api.php:109-115` (`api.demo.status`, `api.demo.login`). Colliding would break the mobile demo login. |
| D2 | **Credential shape** | `email` + `access_code` (16 chars, base32, shown once) | The prompt says "emailed credentials" and "`credential_hash` is a bcrypt hash" (singular secret). Email identifies the grant; the code is the secret. No password to choose, no reset flow, nothing to forget. |
| D3 | **Scope constants** | `demo:gate` and `demo:telemetry` | Two scopes, per the prompt. Gate = verify/consume; telemetry = write sessions/page-views. Split so a leaked telemetry key cannot mint access. |
| D4 | **Event names** | `DemoAccessGranted`, `DemoAccessFirstLogin`, `DemoTncAccepted`, `DemoAccessRevoked`, `DemoAccessExpired` | Past-tense facts per domain-events spec E1. |
| D5 | **Default grant length** | `DevSetting('demo_default_expiry_hours', 72)` — 72h from first login | A 3-day trial matches the 3-day reset cadence. Per-grant override on the create form; the chosen value is **copied** onto the grant. |
| D6 | **Reset anchor** | `DevSetting('demo_reset_anchor_date')`, ISO date; next reset = anchor + 3n days at **03:00 SAST** | Pure function, per the prompt. Anchor is a setting so the cadence can be re-phased without a migration. |
| D7 | **Gate cache TTL** | 60s (`DevSetting('demo_gate_cache_ttl', 60)`) | The prompt fixes revoke latency at "≤60s". This is the knob that defines it. |
| D8 | **Session identity** | Signed cookie `corex_demo_session` holding a UUID; row in `demo_sessions` on **primary** | Demo DB is destroyed every 3 days, so session rows cannot live there. |
| D9 | **Telemetry transport** | Browser → demo-local endpoint (204 immediately) → queued job → `DemoControlClient` → primary | Keeps the page fast and fails open. Job dispatches to the **`default`** queue (the workers drain nothing else). |
| D10 | **Watermark content** | `{company_name} · {email} · {ISO timestamp}`, tiled diagonal, 0.06 opacity, `pointer-events:none`, visible in print | Attributes a leaked screenshot to a company without obstructing the UI. |
| D11 | **Grant statuses** | `pending` → `active` → `expired` / `revoked`, plus `archived` | Derived, never stored (§4.2). |
| D12 | **Archive is a real column** | `archived_at`, **not** Laravel `SoftDeletes` | The prompt says `SELECT COUNT(*)` must never decrease and grants are legal evidence. `SoftDeletes` adds a global scope that silently hides rows from every default query, which is exactly the wrong default for an evidence table. `archived_at` is explicit at the call site. **This is the one place I deviate from non-negotiable #1's usual mechanism — flagging it loudly.** |

Everything below §0 follows from the prompt and is not mine to negotiate.

---

## §1 — Purpose

Gate `demo1.corexos.co.za` behind **time-boxed, company-attributed, emailed
credentials**, so that RR Technologies knows exactly which prospect company is in the
demo, what they looked at, that they accepted the T&C, and when their access dies.

Today the demo is open: anyone with the URL is in, anonymously, forever. That is a
sales-intelligence hole and a legal one.

This is **system-owner sales tooling**, not an agency feature.

---

## §2 — Pillar connections

| Pillar | Relationship |
|--------|-------------|
| **Contact** | A grant MAY link to a `Contact` via nullable `contact_id` — the prospect becomes a CRM record. `company_name` is the grant's **own** column; `contacts` has no `company` column and does not gain one. |
| **Agent** | `issued_by_user_id` → the owner-role user who issued the grant. |

Property and Deal are not involved. The grant is a sales artefact, not a transaction.

---

## §3 — Instance roles

One codebase, two roles. A new config flag decides which half is live.

```php
// config/corex.php
'instance' => [
    'role' => env('COREX_INSTANCE_ROLE', 'primary'),   // 'primary' | 'demo'
    'control_url'   => env('COREX_DEMO_CONTROL_URL'),   // demo → primary base URL
    'control_token' => env('COREX_DEMO_CONTROL_TOKEN'), // demo → primary AgencyApiKey token
],
```

```php
// app/Support/Instance.php
Instance::role(): string      // 'primary' | 'demo'
Instance::isPrimary(): bool
Instance::isDemo(): bool
```

**Why a new flag and not an existing one.** `config('app.env_label')` (`config/app.php:45`)
is **cosmetic** — it drives the banner colour and nothing else. And
`DemoLoginController::isEnabled()` requires `!app()->environment('production')`, but
**the demo host runs `APP_ENV=production`**, so that flag is *false* on the demo box.
Neither is usable as a security predicate. **Never gate security on a display string.**

Durable records — grants, T&C versions, acceptances, sessions, page views — live in the
**primary** database. The demo database is destroyed every 3 days (§6.7); anything
stored there is evidence that deletes itself.

---

## §4 — Data model

Five tables. **All five live on primary.** Migrations follow the house pattern:
anonymous class, docblock citing this spec, `Schema::hasTable` guard,
`unsignedBigInteger` + explicit `->foreign()`, named indexes, symmetric `down()`.

### §4.1 `demo_tnc_versions` — immutable

```
id
version          unsignedInteger, unique      -- 1, 2, 3…
body             longText                     -- the T&C text as shown
published_at     timestamp
published_by_user_id  unsignedBigInteger FK → users, nullable
created_at / updated_at
```

**Published rows are NEVER updated.** "Edit" in the admin UI **publishes a new version**
with `version = max(version) + 1`. There is no update endpoint and no update path.

**Why:** an acceptance record that points at text which has since been edited is
worthless as evidence. That is the entire point of clickwrap. If the row is mutable,
the feature is a lie.

The **current** version is `max(version)`. Publishing v2 re-prompts everyone, including
users who accepted v1 and are mid-session. v1 acceptances still render the **v1 body**
forever.

### §4.2 `demo_access_grants`

```
id
company_name         string        NOT NULL      -- the prospect company (grant's own column)
contact_email        string        NOT NULL, indexed
contact_name         string        nullable
contact_id           unsignedBigInteger FK → contacts, nullable  -- optional CRM link
credential_hash      string        NOT NULL      -- bcrypt of the access code; plaintext NEVER stored
expiry_hours         unsignedInteger NOT NULL    -- COPIED at issue, not referenced
first_login_at       timestamp     nullable      -- NULL until first successful login
expires_at           timestamp     nullable      -- NULL until first login; = first_login_at + expiry_hours
revoked_at           timestamp     nullable
revoked_by_user_id   unsignedBigInteger FK → users, nullable
archived_at          timestamp     nullable      -- "delete" sets this; row is NEVER removed
issued_by_user_id    unsignedBigInteger FK → users, NOT NULL
notes                text          nullable
created_at / updated_at

INDEX demo_grants_email_idx        (contact_email)
INDEX demo_grants_lifecycle_idx    (archived_at, revoked_at, expires_at)
```

**`expiry_hours` is copied, not referenced.** If the default setting later changes from
72 to 24, already-issued grants keep the length they were sold on. A grant that silently
shortens because someone edited a setting is a broken promise.

**Status is DERIVED, never stored:**

```php
public function status(): string
{
    if ($this->archived_at) return 'archived';
    if ($this->revoked_at)  return 'revoked';
    if ($this->first_login_at === null) return 'pending';   // NULL expires_at is NOT expired
    if ($this->expires_at && $this->expires_at->isPast()) return 'expired';
    return 'active';
}
```

**Why derived:** a stored status column goes stale the instant `expires_at` passes with
nobody writing to the row. There is no cron that could keep it honest — and a cron that
"fixes" statuses is a cron that can fail silently and let an expired prospect back in.

**The NULL trap (§11 R4).** `expires_at` is NULL until first login. `null > now()` is
`false` in PHP and `NULL > NOW()` is `NULL` (falsy) in MySQL — so the naive check
`expires_at > now()` locks out **every freshly-issued grant**. The `first_login_at === null`
branch must come **before** any `expires_at` comparison, and every SQL predicate must be
`(expires_at IS NULL OR expires_at > NOW())`.

### §4.3 `demo_tnc_acceptances`

```
id
demo_access_grant_id  unsignedBigInteger FK → demo_access_grants, NOT NULL
demo_tnc_version_id   unsignedBigInteger FK → demo_tnc_versions,  NOT NULL
accepted_at           timestamp NOT NULL
ip_address            string(45) nullable
user_agent            string     nullable
created_at / updated_at

UNIQUE demo_tnc_accept_unq (demo_access_grant_id, demo_tnc_version_id)
```

The UNIQUE makes acceptance idempotent: a double-submit is one row, not two.

### §4.4 `demo_sessions`

```
id
demo_access_grant_id  unsignedBigInteger FK → demo_access_grants, NOT NULL
session_token         char(36) UNIQUE NOT NULL   -- UUID in the signed cookie
started_at            timestamp NOT NULL
last_seen_at          timestamp NOT NULL
ip_address            string(45) nullable
user_agent            string     nullable
created_at / updated_at

INDEX demo_sessions_grant_idx (demo_access_grant_id, started_at)
```

### §4.5 `demo_page_views`

```
id
demo_session_id       unsignedBigInteger FK → demo_sessions, NOT NULL
path                  string  NOT NULL       -- e.g. /corex/properties
route_name            string  nullable
title                 string  nullable
viewed_at             timestamp NOT NULL
created_at / updated_at

INDEX demo_page_views_session_idx (demo_session_id, viewed_at)
```

---

## §5 — API contract (primary side)

Bearer-token routes → **`routes/api.php`**. (Browser/session XHR would have to go in
`routes/web.php:323`; these are machine-to-machine, so `api.php` is correct.)

Reuse the existing machine-auth layer — `AgencyApiKey` + the `agency-api` guard
(`config/auth.php:50-51`, driver wired at `AppServiceProvider.php:593-594`) + the
`website.scope` middleware. **Do NOT build a new auth layer. Do NOT put these behind
`website.live`** — that middleware 403s unless `agency.website_enabled`, which is
unrelated to the demo.

Two new scope constants on `AgencyApiKey`:

```php
public const SCOPE_DEMO_GATE      = 'demo:gate';
public const SCOPE_DEMO_TELEMETRY = 'demo:telemetry';
```

```php
Route::prefix('v1/demo-access')                    // NOT v1/demo — that is taken
    ->middleware(['auth:agency-api', 'throttle:website-api'])
    ->group(function () {
        Route::middleware('website.scope:demo:gate')->group(function () {
            // Verify email + code. Stamps first_login_at (race-safe) and computes
            // expires_at. Returns the grant, its status, and the current T&C version.
            Route::post('/verify',  [DemoAccessApiController::class, 'verify'])->name('v1.demo-access.verify');

            // Re-check an established session. Called by the demo gate middleware
            // on every request (cached 60s). This is what makes revoke bite.
            Route::get('/session/{token}', [DemoAccessApiController::class, 'session'])->name('v1.demo-access.session');

            // Clickwrap acceptance.
            Route::post('/accept-tnc', [DemoAccessApiController::class, 'acceptTnc'])->name('v1.demo-access.accept-tnc');
        });

        Route::middleware('website.scope:demo:telemetry')->group(function () {
            Route::post('/page-view', [DemoAccessApiController::class, 'pageView'])->name('v1.demo-access.page-view');
        });
    });
```

All four appear automatically in the Admin → API catalogue (non-negotiable #7 — the
catalogue is generated from the route table and these carry the `api/` prefix + a
`->name()`).

**Response envelope** — every endpoint, success or failure:

```json
{ "ok": true,  "status": "active", "grant": {…}, "tnc": {…} }
{ "ok": false, "status": "expired", "message": "This demo access expired on 14 July 2026." }
```

---

## §6 — Flows

### §6.1 Issue a grant (on PRIMARY)

1. Owner opens Dev Settings → Demo Access → New Grant.
2. Enters `company_name` (required), `contact_email` (required), `contact_name`
   (optional), `expiry_hours` (defaults from `DevSetting`, editable), optional
   `contact_id` CRM link, optional `notes`.
3. Server mints a 16-char base32 access code, stores `bcrypt($code)` in
   `credential_hash`. **The plaintext is never written to the DB, never logged.**
4. `expires_at` is left **NULL**. `expiry_hours` is **copied** onto the row.
5. Fires `DemoAccessGranted`.
6. `SendDemoAccessGrantEmail` listener queues `DemoAccessGrantMail` to `contact_email`,
   carrying the URL + email + **plaintext code** (the only time it exists outside the
   response).

**The email is sent from PRIMARY, never from demo.** The demo host's mailer points at
Mailpit — the demo bar literally links to it
(`resources/views/partials/_env-banner.blade.php:57`). Mail sent from demo lands in a
local catcher and **the prospect never receives it, silently**. Grants are issued on
primary; the event fires on primary; the mail leaves through primary's mailer.

### §6.2 First login (on DEMO)

1. Prospect hits any demo URL → `EnsureDemoGrant` sees no session cookie → redirects to
   the gate (`/demo/gate`).
2. Enters email + access code.
3. Demo calls primary `POST /api/v1/demo-access/verify` via `DemoControlClient`.
4. Primary finds the grant by `contact_email`, verifies the code against
   `credential_hash`, and rejects archived / revoked / expired.
5. **Stamps `first_login_at` race-safely.** Two tabs, one credential:

   ```php
   $stamped = DB::table('demo_access_grants')
       ->where('id', $grant->id)
       ->whereNull('first_login_at')          // ← the guard IS the race fix
       ->update([
           'first_login_at' => $now,
           'expires_at'     => $now->copy()->addHours($grant->expiry_hours),
       ]);
   // $stamped === 1 → this request won and set the clock
   // $stamped === 0 → another request already won; re-read the row, do NOT re-stamp
   ```

   **Read-then-write is WRONG here.** `if (!$grant->first_login_at) { $grant->save(...) }`
   lets both tabs pass the check and the second `save()` moves `expires_at` forward —
   silently extending the trial. Exactly one writer must win. A conditional `UPDATE …
   WHERE first_login_at IS NULL` is atomic in MySQL; the read-then-write is not.
6. Creates a `demo_sessions` row, returns the session token.
7. Demo sets the signed `corex_demo_session` cookie.
8. If the grant has not accepted the **current** T&C version → redirect to `/demo/tnc`.
9. On accept → `POST /api/v1/demo-access/accept-tnc` → `DemoTncAccepted` → into the demo.

### §6.3 The gate — FAILS CLOSED

`EnsureDemoGrant` middleware, active only when `Instance::isDemo()`:

- No cookie → gate.
- Cookie → `GET /api/v1/demo-access/session/{token}`, **cached 60s**.
- Verdict not `active` → gate, with the reason.
- **Primary unreachable / times out / 5xx → NOBODY GETS IN.** The gate is a security
  control. An access control that opens when its authority is unreachable is not an
  access control.

The 60s cache is why **revoke bites within ≤60s, not instantly.** The revoke confirm
dialog must **say so**. Do not imply a kill you cannot deliver.

Exempt paths: the gate itself, the T&C page, the telemetry endpoint, static assets,
health checks. (Otherwise the gate redirects to itself forever.)

### §6.4 Telemetry — FAILS OPEN

The inversion in §6.3 is **deliberate**. A demo page must never block, slow, or error
because a page-view could not be logged.

1. Every demo page fires one `navigator.sendBeacon` to a **demo-local** endpoint.
2. That endpoint returns **204 immediately** and dispatches a queued job.
3. The job calls primary `POST /api/v1/demo-access/page-view` via `DemoControlClient`.
4. **Any failure is swallowed and logged.** No retry storm, no user-visible error.

The job goes on the **`default`** queue — the workers (`corex-worker-live`,
`hfc-staging-queue`) run `queue:work` with **no `--queue` flag**, so a job on a named
queue is stranded forever.

### §6.5 Watermark — BOTH layouts

There are **two** authenticated layouts and the watermark must be in **both**:

| Layout | Body tag | Views |
|--------|----------|-------|
| `resources/views/layouts/corex-app.blade.php` | `:76` | ~159 |
| `resources/views/layouts/corex.blade.php` | `:68` | ~231 |

Doing only the first — which is where the docs point — leaves **the majority of pages
unmarked**. Extract `resources/views/partials/_demo-watermark.blade.php` and `@include`
it in both, immediately after `<body>` (both already `@include('partials._env-banner')`
at that exact spot, so the insertion point is proven).

Renders **only** when `Instance::isDemo()` **and** a grant is resolved. On primary it
emits nothing — no element, no layout shift.

### §6.6 Shared sandbox — ACCEPTED, not a compromise

Concurrent prospects share **one** demo database and see each other's changes. This is
**Johan's decision, taken 2026-07-11**, with the countdown banner as the mitigation.
Not to be re-litigated.

### §6.7 Reset — every 3 days, a PURE FUNCTION of time

```php
// app/Support/DemoResetSchedule.php
DemoResetSchedule::next(): CarbonImmutable   // anchor + (3 × n) days, 03:00 SAST
DemoResetSchedule::isResetDay(): bool
```

Next reset = the smallest `anchor + 3n days at 03:00 SAST` that is in the future.
**No stored value. No quiet-hours skip. No deferral.**

**Why pure:** a stored "next reset" row in the **demo** DB is destroyed by the very
event it describes. And a *deferrable* countdown lies to every user watching it — if the
banner says 4 hours and the reset can slip, the banner is decoration. The scheduler and
the banner must compute from the **same function**, or they will disagree and the
countdown becomes a bug report.

The countdown renders in `_env-banner.blade.php` beside the existing DEMO label.

`php artisan demo:reset` → `migrate:fresh --force` + `demo:seed`, scheduled in
`routes/console.php` to run daily at 03:00 SAST and **no-op unless `isResetDay()`**.
Guarded: **refuses to run unless `Instance::isDemo()`.** A `migrate:fresh` that fires on
primary is an extinction event.

---

## §7 — Domain events (non-negotiable #9)

Five events, registered in `.ai/specs/corex-domain-events-spec.md` §5. Past-tense facts
(E1), uniform payload (E3), idempotent listeners (E5).

| Event | Fired when | Listener |
|-------|-----------|----------|
| `DemoAccessGranted` | Grant issued | `SendDemoAccessGrantEmail` (queued) |
| `DemoAccessFirstLogin` | `first_login_at` stamped (the winning writer only) | — (audit) |
| `DemoTncAccepted` | Clickwrap accepted | — (audit) |
| `DemoAccessRevoked` | Owner revokes | — (audit) |
| `DemoAccessExpired` | Gate observes an expired grant | — (audit) |

All five are caught by the existing wildcard `RecordDomainEvent` audit listener.

**Listener registration:** rely on Laravel 12 auto-discovery (`shouldDiscoverEvents`).
Do **not** also add an explicit `Event::listen` — that binds the listener twice and the
email sends twice.

---

## §8 — Owner-only. NO permission key.

This is **RR Technologies' own sales tooling** — the list of companies evaluating CoreX.
It lives as a **Dev Settings sub-section**, gated by `owner_only`
(`app/Http/Middleware/OwnerOnly.php`). **No keys are added to
`config/corex-permissions.php`.**

**Why this satisfies rather than violates non-negotiable #5.** A permission key is
*grantable*. One mis-click in the Role Manager and an agency admin is reading the list of
other agencies evaluating CoreX — including their rivals. `owner_only` has **no
delegation path**: `$user->isOwnerRole()` or 403. It is a *stronger* gate than a
permission key, not a weaker one. This follows the existing Dev Settings precedent
exactly (`routes/web.php:2318`; the sidebar link at
`corex-sidebar.blade.php:1660-1666` is unwrapped by any `@permission`).

Enforced at **three** layers regardless:
1. Route middleware `owner_only`.
2. `abort_unless($user->isOwnerRole(), 403)` at the top of **every** controller action.
3. The sidebar's existing owner-gated block.

`php artisan corex:sync-permissions` must be a **no-op** for this feature. If it reports
new keys, a permission was added and it should not have been.

---

## §9 — UI & navigation (non-negotiable #2)

**Dev Settings → Demo Access** (`/admin/dev-settings/demo-access`), alongside the
existing Demo Sidebar Curation page.

- **List** — company, email, status chip (derived), issued, first login, expires,
  page-view count. Filters: status, company.
- **Create** — the §6.1 form. On save, the plaintext code is shown **once**, with a
  copy button and "this will not be shown again".
- **Show** — grant detail, T&C acceptance (which version, when, IP), session list, page
  views.
- **Edit** — notes + CRM link only. **Not** `expiry_hours` (already sold), **not** the
  code (it is a bcrypt hash; it cannot be recovered — only re-issued).
- **Revoke** — confirm dialog stating **"takes effect within 60 seconds"**.
- **Archive** — confirm dialog. Sets `archived_at`. The row **stays**.
- **T&C versions** — list + "Publish new version" (never edit).
- **Reset now** — fires `demo:reset` on the demo host. Confirm dialog.

Plus: sidebar link + the Dev Settings index card.

Status chips are plain English (STANDARDS F.8): "Not used yet" (pending), "Active",
"Expired", "Revoked", "Archived".

---

## §10 — Reference data (non-negotiable #8 / AT-162)

`DemoTncVersionSeeder` publishes **v1**. It is a must-travel GLOBAL reference row: with
no v1, the T&C gate has nothing to show and **every prospect is hard-blocked**.

Seeders do **not** run on a `git pull` deploy. So the seeder **must** be registered in
`app/Console/Commands/Deploy/SyncReferenceData.php` `$seeders` — alongside
`CalendarEventClassSeeder`, `DataDictionarySeeder`, `ReferencePackDictionarySeeder`. It
must be **idempotent** (`firstOrCreate` on `version = 1`) since it runs on every deploy.

---

## §11 — Input space (every row gets a passing test)

| # | Input / state | Expected | Prevent or absorb |
|---|--------------|----------|-------------------|
| R1 | Issue with company + email only (lazy-but-valid) | Works end to end; `expiry_hours` = default | Absorb |
| R2 | Issue with company blank | Rejected: "Enter the company name." | Prevent |
| R3 | Issue with malformed email | Rejected: "Enter a valid email address." | Prevent |
| R4 | **Fresh grant, `expires_at` IS NULL** | **NOT expired** — status `pending`, login allowed | Absorb — *the classic null-comparison bug* |
| R5 | Two concurrent first logins (two tabs) | Exactly **one** stamps; `expires_at` identical after both | Prevent (conditional UPDATE) |
| R6 | Login with wrong code | Rejected, generic message, no user enumeration | Prevent |
| R7 | Login with archived grant | Rejected | Prevent |
| R8 | Login after `expires_at` | Rejected; `DemoAccessExpired` fires | Prevent |
| R9 | Revoked mid-session | Blocked on next gate check (≤60s) | Prevent |
| R10 | **Primary unreachable** at the gate | **Nobody gets in** (fail CLOSED) | Prevent |
| R11 | **Primary unreachable** for telemetry | Page renders normally; view is dropped, logged (fail OPEN) | Absorb |
| R12 | Double-submit the T&C accept | One acceptance row (UNIQUE) | Absorb |
| R13 | T&C v2 published mid-session | User re-prompted; v1 acceptance still renders **v1 body** | Absorb |
| R14 | Grant links a **deleted** Contact | Grant page renders; shows `company_name`, notes the missing link | Absorb |
| R15 | Archive a grant | `archived_at` set; row **still exists**; `COUNT(*)` unchanged | Absorb |
| R16 | Whitespace around email on login | Trimmed; matches | Absorb |
| R17 | `demo:reset` invoked on **primary** | **Refuses.** Loud error. | Prevent |
| R18 | Page view POSTed with an unknown session token | 204, silently dropped (never 500 a demo page) | Absorb |
| R19 | Watermark on a `corex.blade.php` page (not just `corex-app`) | Present | Absorb |
| R20 | `DemoResetSchedule::next()` vs the scheduler | Identical instant | — |

---

## §12 — Files

**Create**
```
app/Support/Instance.php
app/Support/DemoResetSchedule.php
app/Models/DemoAccessGrant.php
app/Models/DemoTncVersion.php
app/Models/DemoTncAcceptance.php
app/Models/DemoSession.php
app/Models/DemoPageView.php
app/Services/Demo/DemoControlClient.php
app/Services/Demo/DemoAccessService.php
app/Http/Middleware/EnsureDemoGrant.php
app/Http/Controllers/Api/V1/DemoAccessApiController.php      (primary)
app/Http/Controllers/Demo/DemoGateController.php             (demo)
app/Http/Controllers/Demo/DemoTelemetryController.php        (demo)
app/Http/Controllers/Admin/DemoAccessController.php          (primary, owner-only)
app/Jobs/Demo/FlushDemoPageViewJob.php
app/Mail/DemoAccessGrantMail.php
app/Listeners/Demo/SendDemoAccessGrantEmail.php
app/Events/Demo/{DemoAccessGranted,DemoAccessFirstLogin,DemoTncAccepted,DemoAccessRevoked,DemoAccessExpired}.php
app/Console/Commands/Demo/DemoReset.php
database/migrations/*_create_demo_access_tables.php  (×5)
database/seeders/DemoTncVersionSeeder.php
resources/views/partials/_demo-watermark.blade.php
resources/views/demo/gate.blade.php
resources/views/demo/tnc.blade.php
resources/views/admin/demo-access/{index,create,show,edit,tnc}.blade.php
resources/views/emails/demo-access-grant.blade.php
tests/Feature/DemoAccess/*.php  (8 files)
```

**Modify**
```
config/corex.php                       — instance block
.env.example                           — COREX_INSTANCE_ROLE, COREX_DEMO_CONTROL_URL/TOKEN
app/Models/AgencyApiKey.php            — 2 scope constants (:38-58)
routes/api.php                         — v1/demo-access group (NOT v1/demo)
routes/web.php                         — demo gate + admin routes
routes/console.php                     — 3-day reset schedule
app/Console/Commands/Deploy/SyncReferenceData.php  — register DemoTncVersionSeeder
resources/views/layouts/corex-app.blade.php        — watermark include
resources/views/layouts/corex.blade.php            — watermark include  ← DO NOT FORGET
resources/views/partials/_env-banner.blade.php     — reset countdown
resources/views/layouts/corex-sidebar.blade.php    — Demo Access link
database/schema/mysql-schema.sql        — schema:dump (non-negotiable #12a)
.ai/specs/corex-domain-events-spec.md   — register the 5 events
```

---

## §13 — House patterns to reuse (do not invent)

- **Outbound HTTP:** `app/Services/Syndication/Property24/Property24ApiClient.php:389-490`
  — `Http::withToken()->timeout()->connectTimeout(15)`, try/catch `ConnectionException`
  **and** `\Exception`, **never throw to the caller**, always return
  `['success' => bool, 'status_code' => ?int, 'message' => ?string, 'data' => array]`.
- **Queued mail:** `app/Mail/FeedbackReportMail.php` (`implements ShouldQueue`).
- **Settings:** `DevSetting::get/set/bool` — defaults declared at the **call site**.
  There is no global `Setting` facade and no `settings` table.
- **Tests:** no factories exist for `Agency` or `Contact` — only `UserFactory` and
  `PortalCaptureFactory`. Hand-build the world. Copy
  `tests/Feature/AgencyPublicApi/Phase2WebsiteApiTest.php:35-74`, which is also the
  reference for minting an `AgencyApiKey` (note the required
  `withoutGlobalScope(AgencyScope::class)`).

---

## §14 — Acceptance criteria

1. On **primary**, `Instance::isDemo()` is false; the gate, watermark and countdown are
   all inert; no demo route is reachable.
2. Issuing a grant stores a **bcrypt** `credential_hash`; the plaintext appears **nowhere**
   in the DB or logs; `expires_at` is **NULL**; `expiry_hours` is **copied**.
3. The grant email is queued to `contact_email` **from primary**.
4. First login stamps `expires_at = first_login_at + expiry_hours`. A **second concurrent**
   accept does **not** move it.
5. The T&C gate blocks until the **current** version is accepted. Publishing v2 re-prompts
   everyone; v1 acceptances still render the **v1 body**.
6. Page views land against the right session **on primary**.
7. Expiry blocks on the next request. Revoke blocks within **≤60s**.
8. Archive sets `archived_at`; `SELECT COUNT(*)` on `demo_access_grants` is **unchanged**.
9. `DemoResetSchedule::next()` **equals** the scheduler's own computation.
10. The watermark renders on pages from **both** layouts.
11. `corex:sync-permissions` is a **no-op**.
12. Every row of §11 has a passing test.

---

## §15 — Deployment (NOT in this session)

**Ordering constraint that will bite:** primary must be deployed **and the `AgencyApiKey`
minted** BEFORE demo is flipped to `COREX_INSTANCE_ROLE=demo`. Otherwise the gate fails
closed (correctly) and **locks everyone out of the demo**.

1. Deploy **primary**: `git pull` → `migrate --force` → `deploy:sync-reference-data`
   (carries T&C v1) → clears → reload php-fpm → restart worker.
2. Mint an `AgencyApiKey` on primary with scopes `demo:gate` + `demo:telemetry`.
3. Put it in **demo's** `.env` as `COREX_DEMO_CONTROL_TOKEN`, with
   `COREX_DEMO_CONTROL_URL` → primary.
4. **Only now** set `COREX_INSTANCE_ROLE=demo` on demo. `config:clear`.
5. Verify: gate appears, a test grant logs in, a page view lands on primary.
