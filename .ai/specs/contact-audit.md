# AT-321-C — Contact Audit Trail: log EVERY change, always attributable, never bypassable

> **Status:** BUILT — QA1 only (branch `QA1`). Johan-approved 2026-07-21 ("same spec as property but
> for contacts, get it done"). **NEVER promote to Staging/live without Johan's explicit go.**
> **Author:** cc6. **Date:** 2026-07-21.
> **Template:** this spec mirrors `.ai/specs/at-321-property-audit-trail.md` (the property audit trail),
> applied to the **Contact** pillar to the exact same standard.
> **Mandate (Johan):** the contact audit trail must log *any and every* change on a contact, always with
> WHO (or a clear system/source label) and WHEN, old→new value. No event allow-list, no observer bypass,
> no silent "System".

---

## 1. Problem statement (what was broken before this build)

`App\Models\Contact` had **no** field-change audit trail. `ContactObserver` (`app/Observers/ContactObserver.php`)
had `created()` (fires a `ContactCreated` domain event), `saved()` (mirrors child identifier rows) and
`creating()` (branch/agent defaults) — **no `updated()`/`saving()` and no audit write**. An edit to
`first_name`, `phone`, `email`, `address`, `id_number`, `agent_id`, … produced **no row anywhere**.

Adjacent, purpose-specific facilities existed but none is a generic who-changed-what field diff:
- **Consent ledger** — `contact_consent_records` (+ `ContactConsentRecordObserver`, `ContactConsentChanged`).
- **Access log** — `contact_access_log` (+ `LogsContactAccess` middleware): records *that* a view/edit/export/merge
  happened, by whom — **not which fields or old→new values**.
- **ID-capture provenance** — `contacts.id_number_captured_at` / `id_number_source` (first-populate only).
- **Domain events** — a handful of named lifecycle events in `domain_event_log` (create/tag/merge/consent),
  **not** field diffs.

The exact analog of the property #3492 defect (an agent reassignment with zero attributable trail) applies
to contacts: a contact reassigned agent→agent left no recoverable actor. This build closes it.

---

## 2. Pillars & multi-tenancy

- **Contact** (primary — the audited subject). **Agent/User** (the actor).
- Every audit row is filed under the **contact's** `agency_id` (never a hardcoded `1`); `agency_id` is
  **nullable** so the unbypassable DB trigger can always insert a backstop row without a NOT-NULL/FK
  constraint ever rolling back a contact save (Rule-17 + bulletproof-INSERT contract).

---

## 3. Design — mirrors the property build, four coordinated changes

### 3.1 LOG EVERYTHING — generic dirty-field diff

`ContactObserver::saving()` captures originals for **all** dirty non-noise columns; `saved()` (existing
records only) emits **one consolidated `contact_updated` row** carrying every changed non-excluded column as
`old_values`/`new_values`, **plus** a dedicated rich **`agent_assigned`** event when `agent_id` changes
(the #3492-analog — a contact reassignment must read cleanly). `created()` writes one `contact_created` row.

**Explicit exclusion list (NOISE — never logged; pure timestamps / counters / derived stamps):**
```
updated_at, created_at, deleted_at, loaded_at, modified_at,
last_contacted_at, last_activity_at, last_consent_check_at,
whatsapp_count, email_count,
id_number_captured_at, buyer_pipeline_entered_at,
outreach_permission_asked_at, messaging_opt_out_at, messaging_opted_in_at
```
Everything NOT on this list is "meaningful" and logs (names, phone, email, notes, id_number, all address
fields, bank fields, preapproval, is_buyer/buyer_state/buyer_source, contact_type/source, agent ids, opt-out
flags, messaging reason/kind/source). The list is the ONLY allow-list that remains — an *exclusion* list of
noise. `agent_id` is captured but excluded from the generic row (it gets the dedicated event).

### 3.2 CLOSE EVERY BYPASS — hybrid (app layer + unbypassable trigger)

Mirrors property Option 3:
1. **App layer (rich):** the generic observer diff captures every Eloquent `save()`/`update()`. For quiet
   writes, `Contact::auditedQuietUpdate(array $attrs, …)` performs the `updateQuietly()` **and** writes the
   audit row + de-dupes the trigger.
2. **Structural gate:** `scripts/dev-check.ps1` §9 fails a diff that adds
   `DB::table('contacts')->update(` / `->updateQuietly(` / `->saveQuietly(` on a Contact without an audit
   test in `tests/Feature/Contacts/Audit/` (and not via `auditedQuietUpdate()`/the `corex_audit_handled` plumbing).
3. **Unbypassable runtime backstop:** a MySQL `AFTER UPDATE` + `AFTER INSERT` trigger on `contacts` writes a
   bulletproof bare `INSERT` into `contact_audit_log` for any meaningful change from ANY write path, de-duped
   from the app layer via the shared `@corex_audit_handled` session flag. Actor from `@corex_actor_*`.
   **Watched columns (backstop subset):** `agent_id, second_agent_id, first_name, last_name, phone, email,
   id_number, address, contact_type_id, contact_source_id, is_buyer, buyer_state`.

### 3.3 ALWAYS CAPTURE ACTOR OR SOURCE — REUSED from the property build

The attribution machinery is **pillar-agnostic and shared**, not duplicated:
- `App\Support\Audit\AuditContext` — the single source of truth for "who/what source" and the bridge to the
  DB trigger via `@corex_actor_*` / `@corex_audit_handled`. **Extracted from `PropertyAuditContext`**, which
  is now a thin backward-compatible forwarder to it (property call-sites unchanged). Contact code uses
  `AuditContext` directly.
- `App\Http\Middleware\SetPropertyAuditActor` (web+api) stamps the authenticated user — now drives BOTH
  pillars (it forwards into the shared context).
- `AppServiceProvider` `Queue::before` / `CommandStarting` source-stampers (`job:<Name>`, `console:<sig>`) —
  shared, drive both pillars.
- **Schema:** `contact_audit_log` carries `actor_type` (24) / `actor_label` (120) / `source` (60) from day
  one; `agency_id` nullable.
- **Failure event/channel:** a shared, generic `App\Events\Audit\AuditWriteFailed(pillar, subjectId, stage,
  message)` + a `contact_audit` log channel (mirrors property's `property_audit`). Property keeps its own
  `PropertyAuditWriteFailed`/`property_audit` (its tests depend on them); convergence of property onto the
  shared event is a future non-urgent cleanup — out of scope here.

### 3.4 FULL trail, visible

- `ContactController::show()` loads `$fullAuditLog` **paginated (50/page)** — no cap — and an unlimited CSV
  export (`?tab=history&export=csv`), mirroring property.
- A **History tab** on `resources/views/corex/contacts/show.blade.php` renders the paginated trail with
  actor + source, category dots, and a Show-details expander. Visible to any user who can open the contact.

### 3.5 ROBUSTNESS — audit failure never breaks a save, never silent

Every audit write is wrapped: on throw → `Log::channel('contact_audit')->error(...)` **and** an
`AuditWriteFailed` event; the contact save always commits. The DB trigger is a bare INSERT only (nullable/
literal columns) so it can never roll back a save.

### 3.6 No hard deletes

`contact_audit_log` uses `SoftDeletes` (`deleted_at`) per Non-Negotiable #1 — rows are append-only and never
user-deletable, but the model complies with the platform no-hard-delete rule.

---

## 4. Acceptance criteria

1. Editing **any** non-excluded contact field writes a `contact_updated` row with old→new and a real actor.
2. **No write path escapes:** an Eloquent edit, `auditedQuietUpdate()`, and a raw
   `DB::table('contacts')->update()` (where the trigger exists) all produce an audit row.
3. **Every** row has `user_id` **or** a non-blank `actor_label`+`source` — never a contextless "System".
4. The History tab shows the **full** trail (paginated), visible to any contact-viewing user; CSV unlimited.
5. A contact save **still succeeds** if the audit write throws; the failure is logged + `AuditWriteFailed`.
6. The dev-check §9 gate fails a diff that adds an unaudited raw/quiet contact write.

## 5. Files created / modified

**Create**
- `database/migrations/2026_08_10_000001_create_contact_audit_log_table.php`
- `database/migrations/2026_08_10_000002_add_contact_audit_trigger.php`
- `app/Models/ContactAuditLog.php`
- `app/Services/Audit/ContactAuditService.php`
- `app/Support/Audit/AuditContext.php` (shared; extracted from PropertyAuditContext)
- `app/Events/Audit/AuditWriteFailed.php` (shared, generic)
- `tests/Feature/Contacts/Audit/ContactAuditTrailTest.php`

**Modify**
- `app/Support/Audit/PropertyAuditContext.php` → thin forwarder to `AuditContext` (BC shim; property untouched)
- `app/Observers/ContactObserver.php` → `saving()` capture + markHandled; `saved()` clearHandled + generic
  diff + `agent_assigned`; `created()` `contact_created` row
- `app/Models/Contact.php` → `auditedQuietUpdate()` + `auditLogs()` relation
- `app/Http/Controllers/CoreX/ContactController.php::show()` → paginated `$fullAuditLog` + CSV export
- `resources/views/corex/contacts/show.blade.php` → History tab (nav + content), paginated
- `config/logging.php` → `contact_audit` channel
- `scripts/dev-check.ps1` → §9 contact-write gate
- `database/schema/mysql-schema.sql` → `php artisan schema:dump` (Non-Negotiable 12a)

## 6. Tests (`tests/Feature/Contacts/Audit/ContactAuditTrailTest.php`)

Mirrors the property suite: generic diff logs old→new; dedicated `agent_assigned` fires; excluded noise
column silent; actor captured for authenticated user; source captured for job/import (never blank);
`auditedQuietUpdate()` records a row; save survives an audit throw (`AuditWriteFailed` dispatched); and the
backstop trigger catches a raw `DB::table('contacts')->update()` **where the trigger exists** (skips with a
clear message where the DB privilege does not — proven on QA1 on-site).

## 7. Deploy / operational caveat (SAME as property — FLAGGED)

- **QA1 only.** Deploy: migrate → `deploy:sync-reference-data` → view/route/config clear → reload fpm →
  restart worker; re-run `schema:dump`.
- **The unbypassable DB-trigger backstop needs a privileged out-of-band step per environment.** Creating a
  trigger needs `SUPER` or `log_bin_trust_function_creators=ON`. The migration **absorbs** the `1419`
  privilege error (logs a warning, never fails the batch) — so `migrate` on a restricted app-DB user creates
  the app-layer audit fully but **NOT** the trigger. On QA1 the trigger is created out-of-band (root,
  `corex_qa1` schema only). **For Staging/live a DBA must create the `contacts` trigger out-of-band (or
  enable the privilege) — this lane does NOT attempt the privileged grant.** Same caveat, same watched-column
  and backstop-fidelity trade-offs as AT-321 §3.2.

## 8. Deliberately NOT in this ticket

- No new "settings" (nothing for the Setup Wizard).
- No retroactive attribution of past unlogged contact changes — the guarantee is **from this build forward**.
- Property audit is unchanged except the pillar-agnostic `AuditContext` extraction (BC-preserving).
