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

#### §3.1a Optional registration cut-off (AMENDMENT — NOT YET BUILT)

**Status: proposed. Needs Johan's gate before any code is written.**

**Why.** Registration currently closes only when the webinar *starts*. The team needs
to close sign-ups earlier — "register by Friday 17:00" — so the attendee list is final
before the day, in time to load it into Zoom and brief around it. The marketing
website's admin console already collects the field and its public page already enforces
and displays it; the value has nowhere to persist, so today it is silently dropped.

**New column:**

```
registration_closes_at  timestamp  nullable, after starts_at
```

**NULL is the whole compatibility story.** NULL means "no cut-off" — registration stays
open until the webinar starts, exactly as it does today. Every existing row is NULL, so
nothing is backfilled and no live webinar changes behaviour. The field is opt-in per
webinar.

House migration pattern: anonymous class, docblock citing this section,
`Schema::hasTable` guard, symmetric `down()`.

**Model.** `Webinar::isOpenForRegistration()` gains a third clause and **stays derived** —
no stored `closed` flag. A stored flag would need something to flip it (a scheduled job,
or a write on read), and until that something ran the API would report a webinar as open
past its own cut-off. Deriving it means the cut-off is true the instant the clock passes
it, with nothing to run and nothing to forget:

```php
return $this->archived_at === null
    && $this->starts_at->greaterThan($now)
    && $this->demoAccessEndsAt()->greaterThan($now)
    && ($this->registration_closes_at === null
        || $this->registration_closes_at->greaterThan($now));   // ← new
```

Also needs `registration_closes_at` in `$fillable` and cast to `datetime`, or the
comparison above runs against a raw string.

**Validation:** `nullable`, `date`, and **`before:starts_at`**. A cut-off at or after the
start is not a cut-off — it either does nothing or contradicts the rule that registration
closes when the webinar begins, and both read as "the field is broken."

**One consequence to decide, not to assume — `statusLabel()`.** It currently returns
"Open for registration" for any un-archived webinar whose `starts_at` is in the future.
With a cut-off in the past that label becomes **wrong on screen** while
`registration_open` is correctly `false` — the admin list would show a webinar as open
that is in fact refusing sign-ups. The proposal is a third branch returning
**"Registration closed"** for that case. Flagged rather than silently included, because
it changes copy Johan reads on the admin list. **Johan's call.**

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

### §4.4 Send the joining link to everyone already registered (AMENDMENT)

**Status: added after §4.3 shipped. Needs Johan's gate before Staging.**

A webinar is created before its Zoom link exists — the date goes up as soon as it is
decided, the link is generated days later. Everyone who registers in that window gets a
confirmation email with **no joining link at all**: `WebinarConfirmationMail` emits
nothing for a null `join_url`, and the `.ics` attachment falls back to `Online`. Until
now there was no way to reach those people. Editing the webinar (§4.3 `PUT /{slug}`)
saves the link for *future* registrants and tells the existing cohort nothing.

This is the one action that closes that gap: **save the link and tell everyone already
signed up, in one press.** The website's registrants screen posts to it.

```php
Route::post('/{slug}/join-link', …'sendJoinLink')->name('v1.webinars.send-join-link');
```

Same group, same `site.connector` middleware, same `throttle:website-api`. No ordering
hazard: there is no `POST /{slug}`, so nothing can swallow it. It is declared beside the
other §4.3 admin routes because that is where a reader looks for the console's verbs.

**`POST /api/v1/webinars/{slug}/join-link`**

```json
{ "join_url": "https://zoom.us/j/123456789" }
```

| Code | Body |
|------|------|
| `200` | `{"ok":true,"join_url":"…","notified":47}` |
| `422` | `{"ok":false,"errors":{"join_url":["…"]}}` |
| `404` | `{"ok":false,"message":"…"}` — unknown or archived slug |

#### Validation

`join_url` → `['required', 'url', 'max:500']`. The `url` and `max` rules are
character-for-character those of `Admin\WebinarController::validated()`, and the
`join_url.url` message is reused **verbatim**:

> The joining link needs to be a full web address, starting with https://

Two front doors to one field must not disagree about what a valid link is, and the
website renders CoreX's message against its own input — a message written for a
different form would read as nonsense there.

`required` is the one deliberate divergence: the admin form treats `join_url` as
`nullable` because a webinar may legitimately not have a link yet, whereas this endpoint
exists *only* to set one, so an absent link is a mistake rather than a state. It
therefore needs a message the admin form has never had:

> `join_url.required` → Paste the joining link before sending it to registrants.

`max:500` keeps Laravel's default message, exactly as the admin form does.

#### Behaviour

1. Resolve the webinar with `Webinar::notArchived()`. Miss → `404`.
2. Save `join_url`.
3. Queue **one** email per existing registration, carrying the link.
4. Stamp `join_link_sent_at` on each registration.
5. Return the number queued as `notified`.

**Archived webinars are a `404`, never a send.** A cancelled webinar's cohort has already
been told it is off; mailing them a joining link for it is worse than doing nothing.
Unlike §4.2's registration endpoint — which deliberately conflates closed, archived, past
and non-existent so nobody can map the sales calendar by probing slugs — this endpoint
may be honest about *why*, because it is reached only with the admin connector token. Its
404 therefore does **not** reuse the shared `notFound()` helper (*"That webinar is not
open for registration"*, which is untrue of an archived webinar and meaningless to an
operator):

> That webinar no longer exists, or has been archived.

**Re-sending is expected, not guarded against.** Zoom links get regenerated, and the whole
point of this endpoint is to push the new one out. Every call mails the entire cohort
again and overwrites `join_link_sent_at`. There is no "only those not yet told" filter,
because after a link change the people already told are precisely the people who most need
telling again. `notified` is therefore always the full registration count, not a delta.

**A webinar with no registrants is a `200` with `notified: 0`**, and the link is still
saved. Nothing is queued and nothing is wrong — it is the ordinary case of setting the
link before anyone has signed up.

#### The guard rail: one transaction, and why it actually holds

The website reports *"saved and emailed to N"* on a `200`, and an operator will believe
it. A partial failure that saved `join_url` and queued nothing — or queued 200 emails and
then failed to save — would make that sentence a lie, in a direction nobody can undo.
Email does not roll back.

So the save, the queueing and the stamps happen inside **one `DB::transaction()`**, and
here that is real atomicity rather than a gesture:

- `QUEUE_CONNECTION=database`, and `DB_QUEUE_CONNECTION` is unset — so the `jobs` table
  lives on the **same connection** as `webinars` and `webinar_registrations`.
- `config/queue.php` sets `'after_commit' => false`, so each queued mail is `INSERT`ed
  into `jobs` *inside* the open transaction rather than deferred past it.
- A throw at any point therefore rolls back the `join_url` write, every
  `join_link_sent_at` stamp **and every queued job together**. Uncommitted `jobs` rows are
  invisible to the worker, so no email can escape from a transaction that later dies.

**This atomicity is a property of the queue configuration, not of the code.** If
`DB_QUEUE_CONNECTION` is ever pointed at a separate database, or the driver moved to
Redis/SQS, the jobs stop rolling back and this guarantee silently becomes false while
every test still passes. Anyone making that change must revisit this endpoint. That is the
trade being accepted here, recorded so it is a decision rather than a surprise.

The cost is a transaction holding ~2 writes per registrant. At the scale this feature
operates (tens to low hundreds) that is a sub-second transaction and the correct trade.
Should a cohort ever reach the thousands, the answer is a single queued fan-out job —
**not** chunked commits, which would reintroduce exactly the partial state this prevents.

This is deliberately **unlike** `SendWebinarReminders` (§6.4), which stamps per row,
swallows one bad address and lets the next hourly sweep retry. That is right for a
recurring sweep with a second chance. This is a one-shot operator action whose reported
count is read as fact, so it is all-or-nothing instead.

#### The email — a new Mailable, not a reuse

`WebinarJoinLinkMail`, alongside the other two in `app/Mail/`:

- **It carries the joining link and nothing else of value.** It must **not** restate or
  re-issue demo credentials. The access code is delivered exactly once, at registration;
  CoreX stores `bcrypt(code)` alone and *cannot* re-send it (§0 D6). An email implying
  otherwise generates support mail nobody can answer.
- It is *"here is your joining link"*, not a second confirmation. Re-sending
  `WebinarConfirmationMail` would tell people they have registered — which they know —
  and re-attach a calendar invite they already accepted.
- `ShouldQueue`, **no pinned queue name.** The CoreX workers run `queue:work` with no
  `--queue` flag and drain `default` only; anything else is stranded forever.
- Sent via `Mail::mailer('corex')` **at the call site**, like every other CoreX product
  email. `Mailer::queue()` stamps the sending mailer onto the mailable on its way to the
  queue, so the call site always wins whatever the constructor sets. A `From` that
  disagrees with the authenticated SMTP account fails SPF and is binned silently.
- Closest existing template is `WebinarReminderMail` — same shape, same constraints, same
  reason for carrying no credential.

#### Auditability

`webinar_registrations.join_link_sent_at` (nullable timestamp) records who was told and
when, and survives the re-sends above by being overwritten each time. Without it there is
no answer to *"did this person ever get the link?"* — the one question that gets asked on
the morning of a webinar.

**Log the count, never the list.** §4.3 established that registrants are personal data
with no second copy in CoreX; a log line naming recipients creates that second copy in a
file with a different retention policy and no delete path:

```
[webinars] join link sent  {webinar_id, notified}
```

#### One field added to §4.3

`GET /{slug}/registrations` returns a `webinar` block of `{slug,title,starts_at}`. **Add
`join_url`.** The website's screen renders "Link is set" / "Not set yet" from it and,
lacking the field, currently always says "Not set yet" — which walks an operator straight
into sending the link a second time to a cohort that already has it.

`GET /{slug}` (§4.1, public) still returns **no** `join_url`. That is unchanged and
deliberate: the link is earned by registering.

#### Decisions Claude made

1. **A `WebinarJoinLinkSent` domain event is emitted**, with no listener. Its two siblings
   — `WebinarRegistered` and `WebinarReminderSent` — exist purely so the fact lands in
   `domain_event_log` (§6.5); without one, this becomes the only webinar mail invisible to
   the audit log, which is the log people reach for when asked what a registrant was sent.
   `agencyId()` null, matching the other two, plus a catalogue row in
   `corex-domain-events-spec.md` (non-negotiable #9).
2. **The 404 names the archived case plainly.** Safe here, and only here, because this
   endpoint is reachable only with the admin connector token — §4.2's deliberate
   vagueness exists to stop anonymous slug-probing, which does not apply.
3. **`join_link_sent_at` overwrites rather than accumulating.** A history table for
   "times we mailed this person a link" answers a question nobody asks; the last send is
   the one an operator needs on the morning of the webinar.

#### The one business consequence worth stating plainly

Pressing this button emails **everyone who has registered**, including people who
registered *after* the link was set and already have it in their confirmation email.
Those people receive the link twice.

That is the deliberate trade: the alternative — mailing only those whose confirmation went
out without a link — silently skips anyone whose link has since *changed*, which is the
main reason to press the button a second time. A duplicate email is a small annoyance; a
registrant sitting on a dead Zoom link at the start of the webinar is a lost sale. If
Johan would rather the button only ever mailed people who have never been sent a link,
that is a one-line change to the query and this note is where it gets revisited.

#### Files

| File | Change |
|------|--------|
| `routes/api.php` | `POST /{slug}/join-link` in the `v1/webinars` group |
| `app/Http/Controllers/Api/V1/WebinarApiController.php` | `sendJoinLink()`; `join_url` added to `registrations()`'s webinar block |
| `app/Mail/WebinarJoinLinkMail.php` | **new** |
| `resources/views/emails/webinars/join-link.blade.php` | **new** |
| `database/migrations/*_add_join_link_sent_at_to_webinar_registrations.php` | **new** |
| `database/schema/mysql-schema.sql` | re-dumped (non-negotiable #12a), `DEFINER` stripped |
| `app/Models/WebinarRegistration.php` | `join_link_sent_at` in `$fillable` + `$casts` |
| `app/Events/Webinars/WebinarJoinLinkSent.php` | **new**, if decision 1 is yes |
| `tests/Feature/Webinars/…` | see below |

#### Acceptance criteria

1. A webinar with registrants and `join_url = null`; `POST …/join-link` with a valid link
   → `200`, `notified` equals the registration count, `join_url` persisted.
2. Exactly one mail queued per registration, on the `default` queue, and every
   `join_link_sent_at` stamped.
3. The queued mail contains the joining link and **no** access code or credentials.
4. `GET …/registrations` afterwards reports the new `join_url`.
5. Invalid link → `422`, field-keyed under `join_url`, with the §4.4 messages — and
   **nothing** saved, queued or stamped.
6. Archived slug → `404`, and no mail queued.
7. Unknown slug → `404`.
8. A forced failure mid-send leaves `join_url` unchanged **and** the `jobs` table empty —
   the transaction assertion, and the one that would have caught the failure mode this
   guard rail exists for.
9. A second call re-queues the whole cohort and updates `join_link_sent_at`.
10. Zero registrants → `200`, `notified: 0`, link saved.
11. No log line anywhere names a registrant's email.

### §4.5 Registration cut-off — API surface (AMENDMENT — NOT YET BUILT)

**Status: proposed alongside §3.1a. Needs Johan's gate.**

The new `registration_closes_at` (§3.1a) touches the API in **three** places, and the
CoreX-side admin screens in a fourth (§7.2) so the two front doors keep agreeing about
what a valid webinar is.

**1. `GET /api/v1/webinars/{slug}` (§4.1)** — add `registration_closes_at` to the webinar
object: ISO 8601 with the SAST offset, or `null`. The website's public page displays it
("Registration closes Friday 17:00") and stops accepting submissions once it passes.
`registration_open` already carries the enforcement; this field is what lets the page
*explain* the closure instead of just refusing.

**2. `GET /api/v1/webinars` (§4.3)** — same field on each row. This is free: index, store
and update all render through `listPayload()`, so adding it there covers all three
responses at once. `GET /{slug}` builds its own body (deliberately — it must never carry
`join_url`) and therefore needs the field added separately. Two edits, four responses.

**3. `POST /` and `PUT /{slug}` (§4.3)** — accept `registration_closes_at`, with a
distinction the website depends on:

| Request carries | Meaning | Result |
|---|---|---|
| `"registration_closes_at": "2026-09-08T17:00:00+02:00"` | set/replace the cut-off | column set |
| `"registration_closes_at": ""` | **clear the cut-off** | column set to `NULL` |
| key **absent** | leave unchanged | column untouched |

**This distinction is load-bearing and it is currently implicit.** It works because
`ConvertEmptyStringsToNull` turns `""` into `null` before validation, and Laravel's
`validate()` returns keys that are *present but null* while omitting keys that are
*absent* — so `$webinar->update($data)` nulls the column in the first case and never
mentions it in the second. Nothing states that today, and a later refactor to
`$request->input('registration_closes_at')` or to an `array_merge` with defaults would
quietly turn "leave unchanged" into "clear it" — wiping a cut-off on every unrelated
edit, with no error. **A test must pin all three rows of that table**, not just the happy
path. The same reasoning already governs `slug` on update (§4.3), which is the precedent.

**4. `Admin\WebinarController` (§7.2)** — the field on the create and edit screens and in
`validated()`, with the same `nullable|date|before:starts_at` rule. Label: **"Registration
closes"**; help text: *"Leave blank to keep sign-ups open until the webinar starts."*

**`POST /{slug}/register` needs no new code and must not gain any.** It already refuses
on `! $webinar->isOpenForRegistration()` with the existing
`404 "That webinar is not open for registration."` — so a passed cut-off is answered
identically to archived, past, and never-existed. That sameness is the point: a distinct
"registration has closed" status would let anyone probe slugs and map the sales calendar.

#### Acceptance

1. A webinar starting **next week** with `registration_closes_at` **yesterday** returns
   `registration_open: false` from `GET /{slug}`, and the field itself in the response.
2. `POST /{slug}/register` against that webinar returns `404`, creates no registration
   and issues no demo grant.
3. `PUT` with `"registration_closes_at": ""` nulls the column and the webinar is open
   again — `registration_open: true`, `POST /register` succeeds.
4. `PUT` **omitting** the key leaves an existing cut-off exactly as it was.
5. A cut-off at or after `starts_at` is rejected `422`, field-keyed.
6. `registration_closes_at: null` behaves exactly as today for every existing webinar.

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
