# Webinar Registration — Spec

> **Status:** APPROVED — Johan answered §0 on 2026-08-26 and said build.
> Author: Claude · Module: Webinars (system-owner sales tooling)
> Ticket: AT-383 (second half of the branch — `…-and-new-invite-demo-api-for-webinears`)
> Related specs: [`demo-access-control.md`](demo-access-control.md) · [`agency-public-api.md`](agency-public-api.md) · [`corex-domain-events-spec.md`](corex-domain-events-spec.md)

---

## §0 — Johan's decisions

| # | Question | Johan's answer | What it means in the build |
|---|----------|----------------|----------------------------|
| A1 | Who gets demo access? | **Anyone who signs up** | No approval queue. Submitting the form issues credentials immediately. |
| A2 | One email or two? | **One email** | A single mail carries confirmation, join link, and demo credentials. §6.2 stops the existing demo-invite mail firing as a second one. |
| A3 | How long does demo access last? | **Until a fixed date — the webinar date, or a few days after. Anyone who never uses their login just loses access on that date.** | This **reverses** the existing grant clock. See §5 — it is the most significant change in this spec. |
| A4 | Reminder email before the webinar? | **Yes** | §6.4. One reminder per registration, lead time set per webinar. |
| A5 | Do registrants become CRM contacts? | **No** | `contact_id` stays NULL. **Business consequence: registrants appear nowhere in Contacts.** The registrations screen (§7.2) is the only place they exist. |
| A6 | Calendar invite on the confirmation email? | **Yes** | A `.ics` attachment so the webinar lands in their diary (§6.3). |

### Decisions Claude made

| # | Decision | What I chose | Why |
|---|----------|--------------|-----|
| D1 | **Auth for the website→CoreX call** | A new universal **`SiteConnector`**, same shape as `DemoConnector` — one credential, minted in Admin, pasted into the website's env | Not `auth:agency-api`: that guard resolves an **agency** as the tenant, and webinar signups are RR Technologies' sales data. And not the demo connector itself: reusing it would let the marketing website call the demo control API (`verify`, `session`, `page-view`). Separate credential, separate blast radius. |
| D2 | **Endpoint namespace** | `/api/v1/webinars/*` | `v1/demo` is the mobile demo-login group; `v1/demo-access` is the demo host's control API. |
| D3 | **Registration closes automatically** | Open while `now() < starts_at` and not archived — **derived, not a column** | Otherwise the link keeps minting free demo logins forever after the event. No flag to forget to flip. |
| D4 | **Form fields** | name, email, **company (required)**, phone (optional) | `demo_access_grants.company_name` is `NOT NULL`, and company is what makes a registration a sales lead rather than an email address. |
| D5 | **Repeat submit** | Same email + webinar → the registration row is reused; a **fresh** grant is issued and the one email re-sent, throttled to once per 15 min | The code is unrecoverable by design (bcrypt only), so "resend my email" is impossible — re-issuing is the only honest way to serve someone who lost it. `verify()` already takes the most recent grant for an address, so the new code supersedes the old. |
| D6 | **The reminder carries no credentials** | It links to the webinar and says "use the access code in your confirmation email" | By reminder time the plaintext no longer exists in CoreX. A reminder that promised the code and omitted it would generate support mail on the morning of the webinar. |
| D7 | **Suppression is a flag on the event, not a DB column** | `DemoAccessGranted` gains `public readonly bool $deliverInvitationEmail = true`, appended **after** `$traceId` so no existing call site changes | §6.2. It is a delivery instruction alive for one dispatch, not a fact worth keeping forever. |
| D8 | **Fixed deadline = `expiry_hours` NULL** | `demo_access_grants.expiry_hours` becomes **nullable**. NULL means "this grant has a fixed deadline; read `expires_at`, which was set at issue" | §5.2. One column encodes the mode, so the two cannot drift. The alternative — an `expiry_mode` enum alongside a meaningless `expiry_hours` — stores the same fact twice and invites exactly that drift. |
| D9 | **Deadline lands at end of day** | `starts_at + N days`, then `endOfDay()` (SAST) | "Access runs until three days after the webinar" means the end of that third day to a human, not 14:00 on it. |

### Deliberately NOT in v1

- **Attendance tracking** — CoreX never sees the webinar platform, so nothing records who turned up.
- **Registrant → Contact** — explicitly declined (A5).

### Not a Setup Wizard setting (non-negotiable #10a checked)

`access_ends_days_after` and `reminder_hours_before` live on the **webinar record**, chosen per registration link. They configure one event, not how CoreX behaves for an agency, and no agency ever sees them. **Nothing here belongs in the Agency Onboarding Setup Wizard.**

---

## §1 — What this feature does and why

RR Technologies runs webinars that walk prospects through CoreX. A prospect must be able to land on a registration page **on the CoreX marketing website**, fill in a short form, and receive **one** email confirming their place, giving them the join link, attaching a calendar invite, and handing them working credentials for `demo1.corexos.co.za`. A reminder follows before the webinar.

The page lives on the marketing website — it owns the design, copy and funnel. CoreX OS owns the credentials, the email and the record.

**Most of this already exists.** [`DemoAccessService::issue()`](../../app/Services/Demo/DemoAccessService.php) mints a one-time code, [`SendDemoAccessGrantEmail`](../../app/Listeners/Demo/SendDemoAccessGrantEmail.php) mails it over the `corex` mailer, and the demo gate enforces it. This spec adds the **public front door** and the webinar record it hangs off.

```
  [ CoreX marketing website ]        registration form, its own design
              │
              │  server-to-server POST, SiteConnector bearer token
              │  (the browser never talks to CoreX, never sees a code)
              ▼
  [ corexos.co.za — PRIMARY ]        POST /api/v1/webinars/{slug}/register
              │
              ├─ webinar_registrations row
              ├─ DemoAccessService::issue()  → grant with a FIXED deadline
              └─ ONE queued email: confirmation + join link + .ics + credentials
              │
              ▼
   200 { registered: true }          website renders its own thank-you page
```

**PRIMARY only.** Grants live on primary and the demo database is destroyed every 3 days (`demo-access-control.md` §3). The website's API base must point at the production CoreX install, never at staging or the demo host.

---

## §2 — Pillar connections

| Pillar | Relationship |
|--------|-------------|
| **Agent** (`User`) | `webinars.created_by_user_id` — the owner who published the link. That user becomes `demo_access_grants.issued_by_user_id` for every grant it produces (the column is `NOT NULL`, there is no logged-in user on a public form, and attributing the grant to whoever opened the door is both true and useful). |
| **Contact** | **None, by A5.** `contact_id` stays NULL. |

Property and Deal are not involved. Like Demo Access, this is a sales artefact — it extends existing system-owner tooling rather than becoming a new island.

---

## §3 — Data model

House migration pattern: anonymous class, docblock citing this spec, `Schema::hasTable` guard, `unsignedBigInteger` + explicit `->foreign()`, named indexes, symmetric `down()`.

### §3.1 `webinars`

```
id
slug                    string, unique, indexed   -- the public URL segment
title                   string       NOT NULL
description             text         nullable     -- rendered by the website
starts_at               datetime     NOT NULL     -- SAST
duration_minutes        unsignedInteger nullable
join_url                string       nullable     -- Zoom/Teams/Meet link
access_ends_days_after  unsignedInteger NOT NULL default 3   -- A3 (0 = ends the webinar day)
reminder_hours_before   unsignedInteger NOT NULL default 24  -- A4
created_by_user_id      unsignedBigInteger FK → users, NOT NULL
archived_at             timestamp    nullable     -- "delete" archives (non-negotiable #1)
created_at / updated_at
```

`demoAccessEndsAt()` = `starts_at + access_ends_days_after days`, `endOfDay()` (D9). Registration is open when `archived_at IS NULL AND starts_at > now()` — derived (D3), never stored.

### §3.2 `webinar_registrations`

```
id
webinar_id              unsignedBigInteger FK → webinars, NOT NULL
name                    string       NOT NULL
email                   string       NOT NULL, indexed
company_name            string       NOT NULL     -- D4
phone                   string       nullable
demo_access_grant_id    unsignedBigInteger FK → demo_access_grants, nullable
confirmation_sent_at    timestamp    nullable
reminder_sent_at        timestamp    nullable     -- NULL = reminder still owed
last_issued_at          timestamp    nullable     -- the 15-min re-issue cooldown (D5)
ip_address              string(45)   nullable     -- abuse trail
user_agent              string       nullable
source                  string       NOT NULL default 'website'
created_at / updated_at

UNIQUE (webinar_id, email)                        -- one person, one place, per webinar
INDEX  (webinar_id, created_at)
```

`demo_access_grant_id` points at the **most recent** grant. Superseded grants stay in `demo_access_grants` — that table is evidence and rows are never removed.

### §3.3 `site_connectors`

Structural copy of `demo_connectors` (`name`, `key_prefix`, `secret_hash`, `last_used_at`, `revoked_at`, `created_by`, timestamps). Prefix `cx_site_`. Rotation is **INSERT + revoke-the-old, never UPDATE**, so the table doubles as the audit trail of every token the website has held; at most one row un-revoked. `SiteConnector` mirrors `DemoConnector::mint()` / `::resolve()`, including the single-message failure path — a 401 that says *which* part was wrong is an oracle.

### §3.4 `demo_access_grants.expiry_hours` → nullable

The one change to an existing table. See §5.2.

---

## §4 — API contract

```php
Route::prefix('v1/webinars')
    ->middleware(['site.connector', 'throttle:website-api'])
    ->group(function () {
        Route::get('/ping',             …'ping')     ->name('v1.webinars.ping');
        Route::get('/{slug}',           …'show')     ->name('v1.webinars.show');
        Route::post('/{slug}/register', …'register') ->name('v1.webinars.register');
    });
```

Registered under `api/v1/*` with `->name()`, so it appears automatically in **Admin → API** (non-negotiable #7).

### §4.1 `GET /api/v1/webinars/{slug}`

So the website renders live details instead of hard-coding them and drifting.

```json
{ "ok": true,
  "webinar": { "slug": "corex-walkthrough-sept",
               "title": "…", "description": "…",
               "starts_at": "2026-09-10T14:00:00+02:00",
               "duration_minutes": 60,
               "registration_open": true,
               "demo_access_ends_at": "2026-09-13T23:59:59+02:00" } }
```

`join_url` is **never** in this response — it is earned by registering, not by reading the page.

### §4.2 `POST /api/v1/webinars/{slug}/register`

```json
{ "name": "Jane Smith", "email": "jane@acme.co.za",
  "company_name": "Acme Properties", "phone": "+27 82 000 0000" }
```

Validation: `name` required ≤255 · `email` required, valid, ≤255 · `company_name` required ≤255 · `phone` nullable ≤50.

| Outcome | Status | Body |
|---|---|---|
| Registered (new or re-issued) | 200 | `{"ok":true,"registered":true,"message":"…"}` |
| Validation failed | 422 | `{"ok":false,"errors":{…}}` — field-keyed, so the website renders them inline |
| Closed / archived / unknown slug | 404 | `{"ok":false,"message":"That webinar is not open for registration."}` |
| Re-issue inside the cooldown | 200 | `{"ok":true,"registered":true,"throttled":true,…}` — never an error; the person did nothing wrong |
| Bad/absent connector token | 401 | one message for every failure mode |

**The response never contains the access code**, and the code is never logged. It exists in the queued email and nowhere else.

### §4.3 Admin API — the website's own console (AMENDMENT)

**Status: added after §4.1/§4.2 shipped. Needs Johan's gate before Staging.**

§7.2 puts webinar admin inside CoreX at `admin/dev-settings/webinars`, and it stays
there — those screens are not being removed. This amendment adds a **second way in**,
for the CoreX marketing website's own admin console, because the person who runs a
webinar is the person who runs the website's funnel and should not need an owner
login to CoreX to hand out a registration link or pull a registrant list.

Same group, same `site.connector` middleware, same throttle:

```php
Route::get   ('/',                        …'index')             ->name('v1.webinars.index');
Route::post  ('/',                        …'store')             ->name('v1.webinars.store');
Route::put   ('/{slug}',                  …'update')            ->name('v1.webinars.update');
Route::delete('/{slug}',                  …'archive')           ->name('v1.webinars.archive');
Route::get   ('/{slug}/registrations',     …'registrations')    ->name('v1.webinars.registrations');
Route::get   ('/{slug}/registrations.csv', …'registrationsCsv') ->name('v1.webinars.registrations-csv');
```

Ordering matters: `/` and the two `registrations*` routes are registered so that
`GET /{slug}` cannot swallow them. `registrations.csv` is declared before
`registrations` for the same reason.

**`GET /api/v1/webinars?include_archived=false`** — the list.

```json
{ "ok": true,
  "webinars": [
    { "slug": "…", "title": "…", "starts_at": "…+02:00", "duration_minutes": 60,
      "registration_open": true, "status_label": "Open for registration",
      "demo_access_ends_at": "…+02:00", "registration_count": 47,
      "registration_url": "https://corexweb.co.za/webinars/…", "archived": false } ] }
```

`registration_url` is built from `integrations.corex_website_url`, **not** `app.url`.
It names the marketing site, and CoreX has no other way to know that hostname — this
is the one field only CoreX can get right, and getting it wrong hands out a link to
the API instead of to the page.

**`POST /`** / **`PUT /{slug}`** — create and edit. Same body and the same validation
rules as `Admin\WebinarController::validated()`, deliberately: two front doors to one
record must not disagree about what a valid record is. `slug` blank on create →
derived from the title. `200`/`201` with the webinar object; `422` field-keyed.

**`DELETE /{slug}`** — archive, never delete. Idempotent: archiving an
already-archived webinar is `200`, not an error.

**`GET /{slug}/registrations?page=1&per_page=100`** — paginated, newest first, with a
`meta` block. Returns the registrant's PII.

**`GET /{slug}/registrations.csv?format=zoom|full`** — streamed, chunked, never
buffered whole. `zoom` is column-for-column what Zoom's bulk-registrant importer
expects; `full` is the sales follow-up and matches the existing admin export.

#### Two knowingly-imperfect things

**1. `first_name` / `last_name` are derived, not stored.** §3.2 stores a single
`name`. The website's console and Zoom's importer both need the halves, so they are
split at the **first space** — "Jan van der Merwe" → `Jan` / `van der Merwe`. The
split is lossless on rejoin, so the website's list renders the true full name either
way, and `name` is also returned so nothing depends on the guess. When the
`first_name`/`last_name` column split lands, this derivation is deleted and the real
columns are returned; the API shape does not change.

**2. One token, two scopes — the scopes are not enforced.** The website holds
`COREX_WEBINAR_PUBLIC_TOKEN` and `COREX_WEBINAR_ADMIN_TOKEN` separately so that a
compromise of the public registration page cannot read the registrant list. CoreX
mints **one** `SiteConnector` (§3.3), and `mint()` revokes every previous active
token, so both website variables currently hold the same value — meaning that
separation is a property of the website's code only, and any valid site token can
read registrant PII. Closing this needs a `scope` column on `site_connectors`, a
middleware parameter, and one active token per scope. **Not built here** — it is a
change to the connector's own model and admin card, and it belongs in its own task.

---

## §5 — The expiry model (A3) — the significant change

### §5.1 What Johan asked for, and why the existing model cannot do it

Today a grant's countdown starts at **first login**: `issue()` leaves `expires_at` NULL, and `stampFirstLogin()` sets it to `first_login_at + expiry_hours`. A grant that is never used stays `pending` **forever** — `status()` returns `PENDING` before it ever looks at `expires_at`.

That is wrong for a webinar in two ways:

1. Someone who registers two weeks early and logs in that evening burns their whole window and arrives locked out.
2. Someone who never logs in keeps a live credential indefinitely, long after the webinar — the opposite of "anyone that doesn't use the login just loses access".

So webinar grants get an **absolute deadline, fixed at issue**: everyone in a given webinar's cohort loses access at the same moment, used or not.

### §5.2 The mechanism

**`expiry_hours` becomes nullable, and NULL means "fixed deadline — read `expires_at`"** (D8). The two modes are mutually exclusive and each grant carries exactly one:

| Mode | `expiry_hours` | `expires_at` at issue | Clock |
|------|----------------|----------------------|-------|
| **Rolling** (admin-issued, unchanged) | set | NULL | starts at first login |
| **Fixed** (webinar) | **NULL** | the deadline | already running |

Three surgical changes to the existing lifecycle:

**1. `DemoAccessService::issue()`** accepts `$data['expires_at']`. When present it writes `expiry_hours = null` and `expires_at = <deadline>`. Passing both `expires_at` and `expiry_hours` is a programming error and throws `InvalidArgumentException` — a grant with two clocks has no defined behaviour, and failing loudly at the call site beats guessing.

**2. `DemoAccessGrant::status()`** — the expiry check moves **above** the pending branch:

```php
if ($this->expires_at !== null && $this->expires_at->isPast()) return STATUS_EXPIRED;
if ($this->first_login_at === null)                            return STATUS_PENDING;
return STATUS_ACTIVE;
```

This is **behaviour-preserving for rolling grants**: theirs is NULL while pending, so the new branch cannot fire and they still fall through to `PENDING`. The §11 R4 bug the original ordering guarded against (`null->isPast()` fataling, and `NULL > NOW()` being falsy in SQL) is still guarded — by the explicit `!== null`, which is the honest guard rather than a lucky ordering. `scopeUsable()` already gets this right (`expires_at IS NULL OR expires_at > NOW()`) and needs no change.

**3. `stampFirstLogin()`** must not overwrite a deadline. The write becomes `expires_at = COALESCE(expires_at, <now + expiry_hours>)`, inside the same single atomic conditional UPDATE — the two-tab race guard (`WHERE first_login_at IS NULL`) is untouched. When `expiry_hours` is NULL the column is left alone entirely.

### §5.3 What this means on screen

Demo Access → grant screens show **"Access length: 72 hours"** for rolling grants and **"Access ends: 13 Sep 2026"** for fixed ones. `number_format(null)` would render a confident `0 hours`, which is worse than wrong — it is wrong and plausible. Both the show and edit screens branch explicitly.

---

## §6 — Behaviour

### §6.1 Registration

`WebinarRegistrationService::register(Webinar $webinar, array $data, ?string $ip, ?string $ua): array`

1. Assert the webinar is open (D3) and its deadline is still in the future — else 404.
2. `updateOrCreate` on `(webinar_id, email)`; refresh name/company/phone from the newer submission.
3. If `last_issued_at` is inside 15 minutes → return `throttled`, send nothing.
4. `DemoAccessService::issue([...], issuedByUserId: $webinar->created_by_user_id)` with:
   - `company_name` from the form,
   - **`expires_at` = `$webinar->demoAccessEndsAt()`** (A3),
   - `notes` = `"Webinar: {title} — self-serve registration"` (why this grant has no human issuer),
   - `contact_id` = null (A5),
   - **`deliver_email` = false** (A2, §6.2).
5. Store `demo_access_grant_id`, `last_issued_at`, `confirmation_sent_at`.
6. Queue `WebinarConfirmationMail` with the plaintext code.

Steps 2–5 run in a transaction; the mail is queued **after** commit. A grant that exists without its email can be re-issued; an email whose grant was rolled back is a dead credential in a prospect's inbox.

### §6.2 One email, not two (A2)

`DemoAccessGranted` already has a listener that mails the standard demo invitation. Left alone, a registrant gets **two emails carrying the same code**.

Fix (D7): the event gains `public readonly bool $deliverInvitationEmail = true`, appended after `$traceId` so no existing call site changes. `issue()` reads `$data['deliver_email'] ?? true` and passes it. `SendDemoAccessGrantEmail` returns early when false.

The **event still fires** — audit log, domain-event trail, future listeners — only delivery is skipped. Suppressing the event instead would erase the grant from the audit trail, the opposite of what this system is for. A test asserts a registration produces **exactly one** mail, and that it is `WebinarConfirmationMail`.

### §6.3 The confirmation email

`WebinarConfirmationMail`, `ShouldQueue`, sent via `Mail::mailer('corex')` — mail@corexos.co.za over corexos.co.za's own SMTP, matching `DemoAccessGrantMail`: the default mailer authenticates as the agency's mailbox, and a From that disagrees with the authenticated account fails SPF and is binned with nothing raised our side.

Contents: title, date/time (SAST), duration, join link; then the credentials block — email, access code, and the gate URL built by **concatenation from `config('corex.instance.demo_url')`**, never `route()` (sent from primary, `route()` would mail the prospect primary's own domain); then the plain-English deadline: access ends on *this date*, whether or not it is used.

**A `.ics` calendar attachment** (A6), `METHOD:PUBLISH`, UID derived from the registration so a re-send updates the same diary entry rather than duplicating it. `DTSTART`/`DTEND` in UTC with a `Z` suffix — the one format every calendar client agrees on. It carries the join link, never the access code: calendar entries sync to phones, shared screens and assistants' diaries, and a credential does not belong there.

Queued to the **`default`** queue. The workers run `queue:work` with no `--queue` flag and drain nothing else — a named queue would strand it forever.

### §6.4 The reminder (A4)

`php artisan webinars:send-reminders`, scheduled `->hourly()->withoutOverlapping()`.

Hourly, not daily: "24 hours before a 14:00 webinar" cannot be honoured by a job that runs once at 08:00.

Selects registrations where the webinar is not archived, `starts_at > now()`, `now() >= starts_at - reminder_hours_before`, and `reminder_sent_at IS NULL`. Queues `WebinarReminderMail`, stamps `reminder_sent_at` — the stamp is what makes it exactly once. Carries title, time and join link; **not** the access code (D6).

### §6.5 Domain events

Per non-negotiable #9, new facts get named past-tense events: `WebinarRegistered` and `WebinarReminderSent`, both system-owner events (`agencyId()` null), catalogued in `corex-domain-events-spec.md`.

**No listeners in v1.** The mail is queued by the service and the command directly (§6.1, §6.4); the events exist for the audit trail. Event auto-discovery is OFF in this codebase, so if a listener is added later it must be registered explicitly in `AppServiceProvider::boot()` — and must stay **synchronous**, queueing a Mailable rather than itself: a queued listener on a domain event fatals on deserialisation (`AbstractDomainEvent`'s parent-declared readonly properties cannot be restored from the child scope).

---

## §7 — UI, navigation and permissions

### §7.1 Sidebar (non-negotiable #2 — same day)

A **Webinars** entry beside **Demo Access** in the owner-only Admin block of `corex-sidebar.blade.php`. Same gate, same section — it is the same job: knowing who is evaluating CoreX.

### §7.2 Screens — `admin/dev-settings/webinars`, `owner_only`

| Route | Screen |
|---|---|
| `GET /` | List — title, date, open/closed, registration count |
| `GET /create`, `POST /` | Create: title, slug, description, date/time, duration, join URL, **how many days after the webinar demo access ends** (A3), **reminder lead time** (A4). On save, shows the public registration URL to hand to the website. |
| `GET /{webinar}` | Registrations: name, company, email, phone, registered-at, grant status, confirmation + reminder sent. **The only place registrants exist** (A5). CSV export. |
| `GET /{webinar}/edit`, `PUT /{webinar}` | Edit |
| `DELETE /{webinar}` | **Archives** — sets `archived_at`, closing registration. Never removes the row (non-negotiable #1). |

Connector minting lives on the existing **Demo Access → Connection** screen as a second card ("CoreX Website connector"), same mint / show-once / revoke flow.

### §7.3 Permissions (non-negotiable #5)

Owner-only, matching Demo Access exactly: `owner_only` middleware on the group, `abort_unless($user->isOwnerRole(), 403)` in **every** action, and the sidebar's own owner gate. Three layers, deliberately. No new key in `config/corex-permissions.php` — this is system-owner sales tooling, and a grantable key would put it one mis-click from a tenant.

---

## §8 — Acceptance criteria

1. An owner creates a webinar and is shown the public registration URL.
2. `GET` returns title, description, time and the access-ends date — **no** join URL.
3. A valid `POST` returns 200 and creates one registration row and one grant.
4. The registrant receives **exactly one** email: join link, `.ics` attachment, and working demo credentials.
5. The grant's `expires_at` is the webinar date + `access_ends_days_after` days, end of day — **set at issue, not at first login**.
6. A grant that is **never used** reports `expired` once that date passes (A3).
7. Logging in does **not** move the deadline.
8. A **rolling** (admin-issued) grant is unchanged: `expires_at` NULL until first login, then first login + `expiry_hours`.
9. A second `POST` with the same email inside 15 minutes returns 200 `throttled`, creates **no** second row and sends **no** second email.
10. A `POST` after `starts_at` returns 404 and issues nothing.
11. `webinars:send-reminders` sends one reminder per registration at the configured lead time, never twice, never with an access code in it.
12. A missing or revoked connector token returns 401 revealing nothing about which part failed.
13. The endpoints appear in **Admin → API** without being registered there by hand.
14. Archiving closes registration and removes **no** row.
15. No registrant appears in Contacts (A5).

---

## §9 — Files

**Create**

```
database/migrations/…_create_webinars_table.php
database/migrations/…_create_webinar_registrations_table.php
database/migrations/…_create_site_connectors_table.php
database/migrations/…_make_expiry_hours_nullable_on_demo_access_grants_table.php
app/Models/Webinar.php
app/Models/WebinarRegistration.php
app/Models/SiteConnector.php
app/Http/Middleware/EnsureSiteConnector.php
app/Http/Controllers/Api/V1/WebinarApiController.php
app/Http/Controllers/Admin/WebinarController.php
app/Services/Webinars/WebinarRegistrationService.php
app/Support/IcsCalendarInvite.php
app/Mail/WebinarConfirmationMail.php
app/Mail/WebinarReminderMail.php
app/Console/Commands/SendWebinarReminders.php
app/Events/Webinars/WebinarRegistered.php
app/Events/Webinars/WebinarReminderSent.php
resources/views/admin/webinars/{index,create,edit,show,_form}.blade.php
resources/views/emails/webinars/{confirmation,reminder}.blade.php
tests/Feature/Webinars/WebinarExpiryModelTest.php
tests/Feature/Webinars/WebinarRegistrationApiTest.php
tests/Feature/Webinars/WebinarReminderTest.php
tests/Feature/Webinars/WebinarAdminTest.php
```

`_form.blade.php` is shared by create and edit rather than duplicated: the access
window and reminder lead time ARE the policy of a webinar, and two copies that drifted
would issue grants on rules nobody could see afterwards.

**Modify**

```
routes/api.php                           — the v1/webinars group
routes/web.php                           — admin/dev-settings/webinars (owner_only)
                                           + demo-access/site-connection
routes/console.php                       — hourly webinars:send-reminders
bootstrap/app.php                        — site.connector alias
app/Events/Demo/DemoAccessGranted.php    — + deliverInvitationEmail (D7)
app/Services/Demo/DemoAccessService.php  — deliver_email + expires_at (D8)
app/Models/DemoAccessGrant.php           — status() order, hasFixedDeadline(),
                                           stampFirstLogin COALESCE (§5.2)
app/Listeners/Demo/SendDemoAccessGrantEmail.php — early return when suppressed
app/Http/Controllers/Admin/DemoAccessController.php — website connector card
resources/views/admin/demo-access/connection.blade.php
resources/views/admin/demo-access/{show,edit}.blade.php — fixed vs rolling (§5.3)
resources/views/layouts/corex-sidebar.blade.php      — Webinars nav entry
database/schema/mysql-schema.sql         — re-dump after the migrations
```

**Not modified, deliberately:** `app/Providers/AppServiceProvider.php`. The events
have no listeners in v1 (§6.5) — the confirmation and reminder mails are queued
directly by the service and the command, because they carry a plaintext credential
whose lifetime is the transaction that mints it. There is nothing to register.

`.ai/specs/corex-domain-events-spec.md` — the two events still need cataloguing there.

**Not in this repo:** the registration page itself. The marketing website gets the §4 contract, one connector token, and the public URL.

---

## §10 — Build order

1. Migrations + `schema:dump` (from the **test** DB — a plain dump reads the stale dev DB and silently deletes tables from the committed snapshot; strip `DEFINER` clauses after).
2. The expiry-model change (§5.2) + its test **first**. It touches a live lifecycle; everything else depends on it being right.
3. `SiteConnector` + `EnsureSiteConnector` + admin card. Prove `GET /ping` with a real token.
4. `GET /{slug}`.
5. The one-email suppression (§6.2) **before** the register endpoint — otherwise the first end-to-end test mails two codes and we learn it from a prospect.
6. `POST /{slug}/register` + service + confirmation mail + `.ics`.
7. Admin screens + sidebar entry (same day, non-negotiable #2).
8. Reminder command + schedule entry.
9. Tests, then hand the website team §4 and a token.
