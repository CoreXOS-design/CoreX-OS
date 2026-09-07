# Spec: Rental Applications (AT-392)

**Status:** Phase 1 approved by Johan 2026-09-04. This spec covers Phase 1 only.
**Ticket:** AT-392 — Rental Applications lane.

---

## Why

The Rental Application (V8) document was being sent through the full e-sign
wizard, which structurally requires the agent to be a signing party
(`ESignWizardController` auto-injects and locks an `agent` recipient row —
see AT-332 investigation). For a tenant intake form this is overkill: the
agent never needs to sign a rental application, and the wizard's multi-step
flow (property → recipients → details → fill & review → signing setup →
prepare-signing) is far more machinery than "pick a contact, send a form."

Phase 1 replaces that path with a dedicated page for this one document type.

---

## Phase 1 scope — what this build includes

1. **Dedicated page**, not the e-sign wizard. Contact **required**, property
   **optional**. Every field **optional** — nothing blocks send. Prefill
   from the contact wherever a field maps to a real `contacts` column.
2. **Fields** (from Rental Application V8):
   - Property address
   - Personal: full name, ID number, marital status, spouse name, spouse ID,
     citizenship, current residential address, email, cell, work
   - Emergency contact: name, cell, work
   - Current landlord: name, tel, current rental amount, from, to
   - Employment: employer, position, employer address, employer tel,
     monthly salary
   - Lease requirement: occupation date, rental terms, special conditions,
     adults, children
3. **Agent does not sign.** The applicant signs twice in one sitting: the
   truth-of-information declaration, and the Tenant Profile Network (TPN)
   credit-check consent.
4. **One send, two return routes**, applicant's choice:
   - (a) Download the PDF, complete by hand, scan, return — with an upload
     link so the scanned/completed copy and supporting documents come back
     into CoreX rather than an inbox.
   - (b) Tokenised link — complete online, sign both blocks, upload
     supporting documents, data lands directly against the contact.
   - (b) is modelled on the existing `/sign/{token}` mechanism
     (`SignatureRequest`/`SigningController` pattern) — reused, not
     reinvented: same token generation shape (`Str::random(64)`, uniqueness
     loop), same 14-day expiry convention, same "blocked/expired" no-identity
     -leak handling shape.
5. **Supporting documents.** Reuses the existing e-sign recipient
   supporting-document upload path's contract (allowlist
   `pdf,jpg,jpeg,png,doc,docx`, 15MB/file, max 10 files/request — see
   `SigningController::uploadSupportingDocuments()`). Required-document list
   is agency-configurable, defaulting to the V8 checklist per employment
   type (permanently employed / business owner — personal account /
   business owner — business account). **Nothing is enforced** — missing
   documents show as outstanding on the Returned Applications screen; they
   never block submission.
6. **Returned Applications** — its own menu item for the rental team.
   Status values: `sent`, `in_progress`, `returned`, `under_assessment`,
   `approved`, `declined`, `withdrawn`.
7. **Branding.** No literals. The live template (`template-97.blade.php`)
   hardcodes `letting@hfcoastal.co.za` (`:58`) and `039 315 0857` (`:77`).
   The new build reads agency email/phone/website via the same `$d()`
   accessor pattern already used in
   `docuperfect/web-templates/components/company-header.blade.php`
   (`agencies.email`, `agencies.phone` / `phone_secondary`,
   `agencies.website_url`).
8. **Navigation** (standing rule — every page ships with its nav entry the
   same day): Rentals → Rental Applications. Rentals → Returned
   Applications. Settings → Rental Applications.
9. **Soft deletes only** (`SoftDeletes`), **agency-scoped**
   (`BelongsToAgency` + global `AgencyScope`) — no exceptions.

## Explicitly OUT of Phase 1 (later phases, per Johan)

- Assessment split-screen with agency-configurable affordability calculator
  and approval routing.
- Applicant highlighting and OCR keyword marking on returned documents.

---

## Pillars

- **Contact** — required. The application is filed against a contact; the
  contact's own fields (name, ID number, email, phone, address) prefill the
  form and the PDF's Document ↔ Contact link (`document_contacts` /
  `documents.agency_id`) is how it appears on the contact record.
- **Property** — optional. When set, links via `documents.property_id` /
  `document_properties`, same convention as every other filed document.
- **Deal** — not required for Phase 1 (a rental application precedes any
  deal; nothing here creates or requires a `deals` row).
- **Agent** — the creating/sending user; recorded, never a signer.

---

## Data model

### `rental_applications`

One row per sent application. `agency_id` (BelongsToAgency), `branch_id`,
`contact_id` (NOT NULL — the one required link), `property_id` (nullable),
`created_by_user_id`, `status` (enum, see §6 above, default `sent`),
`token`, `token_expires_at`, `delivery_mode` (`download` | `online`,
nullable until the applicant picks one), plus one nullable column per V8
field listed in §2. `submitted_at`, soft deletes.

**Every V8 field column is nullable.** Per BUILD_STANDARD §2 (the
input-space rule), an empty optional field must never reach the DB and
error — nullable columns are the DB-layer half of that guarantee.

### `rental_application_signatures`

Two rows per completed application (`kind`: `declaration` | `tpn_consent`),
each with the captured signature image path, signed-at timestamp, and the
IP/user-agent of the signer — mirrors the audit shape already used for
e-sign (`SignatureAuditLog`), scoped down to what a two-signature intake
form needs.

### `rental_application_document_requirements`

Agency-configurable checklist. `agency_id`, `employment_type` (enum:
`permanently_employed`, `business_owner_personal_account`,
`business_owner_business_account`), `document_type_id` (FK to the existing,
shared `document_types` table — no parallel type system), `sort_order`.
**No agency row present ⇒ the V8 defaults apply in memory** (Rule 17-safe
pattern: never persists a hardcoded default, just returns it when nothing
is configured), so an agency that never opens the settings screen still
gets the correct, working default checklist.

### Documents

Supporting documents and the returned/scanned copy file through the
**existing, shared `documents` table** (`source_type = 'rental_application'`,
`source_id = rental_applications.id`, `agency_id` stamped from the
application) — never a parallel documents table. Contact/property linkage
uses the same pivots (`document_contacts`, `document_properties`) every
other filed document uses.

---

## Routes (Phase 1)

| Purpose | Route |
|---|---|
| List / create | `GET/POST corex/rental-applications` |
| Show / edit (pre-send) | `GET corex/rental-applications/{rentalApplication}` |
| Send | `POST corex/rental-applications/{rentalApplication}/send` |
| Returned Applications inbox | `GET corex/rental-applications/returned` |
| Settings — document checklist | `GET/POST corex/settings/rental-applications` |
| Public token entry | `GET rental-application/{token}` |
| Public submit | `POST rental-application/{token}/submit` |
| Public supporting-doc upload | `POST rental-application/{token}/documents` |
| Public supporting-doc view | `GET rental-application/{token}/documents/{document}` |
| Public supporting-doc remove (archive) | `POST rental-application/{token}/documents/{document}/remove` |
| Public supporting-doc replace | `POST rental-application/{token}/documents/{document}/replace` |
| PDF download (agent side, any time) | `GET corex/rental-applications/{rentalApplication}/pdf` |

---

## Permissions

- `rental_applications.view` / `rental_applications.create` /
  `rental_applications.send` — the intake page and list.
- `rental_applications.view_returned` — the Returned Applications inbox
  (rental team).
- `rental_applications.manage_settings` — the document-checklist settings
  screen.

---

## Acceptance criteria (Phase 1)

- An agent can open Rental Applications, pick a contact (required),
  optionally a property, leave every other field blank, and send — no
  validation error.
- The applicant can either download a correctly-branded PDF (agency's own
  email/phone/website, not HFC literals) or open a tokenised link.
- On the tokenised link, the applicant can fill any subset of fields,
  capture both signatures (declaration + TPN consent), upload supporting
  documents, and submit in one sitting.
- The submission is visible on the contact record and in Returned
  Applications with the correct status.
- Missing checklist documents are shown as outstanding, never block
  submission.
- Deleting an application soft-deletes it; nothing is hard-deleted.
- A second agency's data is never visible to this agency, in the list, the
  inbox, or the settings screen.

---

## Deploy requirements (mandatory, every environment — do not skip)

**1. Run the permission sync after every deploy that lands this feature.**
`config/corex-permissions.php` defines 4 `rental_applications.*` keys, but
they only take effect once granted to roles in `role_permissions`. This is
NOT automatic on `migrate` — the sync command must run explicitly:

```
php artisan corex:sync-permissions --merge-defaults
```

Root-caused on QA1 2026-09-07 (cc2): after this feature landed, `role_permissions`
had **zero** grant rows for any of the 4 keys, on any role, for any agency —
`hasPermission('rental_applications.view')` evaluated false for every user,
including admin, and the nav entry stayed invisible. Confirmed fixed on QA1
by running the sync (210 rows inserted, admin role only, every agency).
**Note when running this:** the command processes every permission key
missing from the DB, not just this feature's — on QA1 it also caught up one
unrelated pre-existing key (`view_photo_upload_report`, from an older
diagnostics feature that had never been synced either). That is expected
behaviour of `--merge-defaults`, not a side effect of this feature — verify
the diff on each run rather than assuming it only touches
`rental_applications.*`.

**2. RESOLVED 2026-09-07 — the nav entry was inside the owner-only "Hidden"
panel; it now has its own agency-visible main-menu section.** Originally
found nested inside the sidebar's `$isOwner`-gated "Hidden" section (via
the pre-existing "Rentals" drill-down there) — a normal per-agency admin,
including Johan's own regular login, could not see it no matter what
permissions they held. See "Rentals main-menu section" below for the full
build, verification, and the known naming collision this created.

---

## Rentals main-menu section (Johan's decision, 2026-09-07)

Johan: Rental Applications is an agency admin/agent feature, not a
system-owner tool. Built as a proper top-level "Rentals" main-menu section
in `resources/views/layouts/corex-sidebar.blade.php` — the container for a
**growing** section, not a one-off link. Rental Applications is only the
first child.

**Structure.** Matches the existing "Reports"/"Leave Management" pattern
exactly — same `corex-nav-item corex-nav-group-toggle` / `corex-nav-panel`
/ push-pop Alpine markup as every other top-level drill-down group in the
file. Placed between the "Reports" and "Trust Interest" groups (a natural,
low-disruption boundary — both neighbours are themselves standalone,
independently-gated groups, same shape as this one).

**Internal Alpine group key is `rental-applications`, NOT `rentals`.**
Deliberately distinct from the existing Hidden panel's `rentals` key (see
below) — two groups sharing one key would corrupt each other's
open/active panel-stack state via `$groupOpen()`/`$navGroupParents`. This
is invisible to the user; only the visible label is "Rentals."

**Section-level gate:** `@if($user && $user->hasAnyPermission(['rental_applications.view', 'rental_applications.view_returned']))`
— no `@feature()` wrap. Two reasons: (1) there is no dedicated
feature-registry entry for `rental_applications` in
`config/corex-features.php`, and adding one would be scope creep on a
nav-only change; (2) this exactly mirrors the "Reports" group immediately
above it in the file, which is also permission-only for the same reason
(its own comment: *"Gated by its own existing permission... not a new
key"*). An agency with neither child permission sees no "Rentals" menu at
all — not an empty shell.

**Adding the next rentals feature:** one more
`@permission(...)`/`<a>`/`@endpermission` block inside the panel `<div>`,
directly after Returned Applications, same shape as the two existing
children. Documented inline in the blade file's own comment at the same
spot.

### Known, deliberate, pending reconciliation — two things named "Rentals"

**Not resolved, not ours to resolve.** A second, unrelated "Rentals"
drill-down still exists, nested inside the sidebar's owner-only "Hidden"
section (`$isOwner` gate) — lease capture, dashboard, e-signatures, active/
expired leases. `config/corex-features.php`'s `'rentals'` feature key
(`sidebar_section: 'hidden.rentals'`) belongs to that panel, not to this
one. **Both are untouched by this build** — this section only adds the new
agency-visible "Rentals" menu; the Hidden one is exactly as it was.

Pre-build investigation (reported to Johan before any code was written,
2026-09-07) established the Hidden panel is real, working, and — on the
evidence — deliberately hidden rather than abandoned:
- All 5 of its links resolve (`rentals.index`, `rental.dashboard`,
  `rental.signatures`, `rental.active-leases`, `rental.expired-leases`),
  backed by real controllers (`RentalsController`, `Rental\RentalDivisionController`)
  with substantive query logic, not stubs.
- All 5 views exist and are substantial (97–1,116 lines).
- Real production-shaped data exists on QA1 (58 `Rental` rows, 2
  `LeaseRecord` rows).
- Dispatching a real authenticated request to all 5 routes returns 200.
- 28 commits have touched this code, most recently in a 239-page app-wide
  style sweep (July 2026) — still actively carried along by maintenance,
  not forgotten.
- The section's own comment is explicit: *"HIDDEN — pages hidden from
  agency users, visible to system owners only."*

**What a system owner actually sees, right now, with both sections live**
(rendered and verified, not inferred): a "Rentals" toggle in the normal,
agency-visible part of the menu (this build) that expands to "Rental
Applications" / "Returned Applications" — **and**, separately, further
down inside "Hidden," a second "Rentals" toggle that expands to a panel
also titled "Rentals," which itself contains a link whose visible text is
also just "Rentals" (the `rentals.index` link, labelled identically to its
own parent group and panel). Three identical "Rentals" labels total, in
two unrelated places, for the one role that can see both.

**Assessment:** this does not fail or crash — both work independently and
correctly (confirmed via the distinct Alpine keys) — but it reads as
confusing or duplicated to a human looking at the sidebar, not as an
intentional design. An owner encountering both without this context would
reasonably wonder why "Rentals" appears twice. Flagged for Johan; nothing
renamed or merged without his explicit call.

### Verification (rendered output, real kernel dispatch — not blade source)

- **User 22** (johan@hfcoastal.co.za, admin, agency 1): "Rentals" section
  present and expandable (`push('rental-applications')` fires once), both
  children present with correct hrefs (`corex/rental-applications`,
  `corex/rental-applications/returned`).
- **User 24** (agent, no `rental_applications.*` permissions): the entire
  section — button, panel, both children — is absent. Zero occurrences of
  "rental-applications" anywhere in the rendered page.
- **Nothing else broke.** Compared three rendered states (original Hidden
  placement → intermediate Tools placement → this build) for the same
  user: every major nav section marker (Agents, Branch Manager, Tools,
  Admin, System Developer, Dashboard, Properties, Deeds, Compliance,
  Payroll, Reports, Trust Interest) has an identical count across all
  three. A full span-by-span diff between the immediately-prior state and
  this one shows only the expected structural delta (`Rentals` +
  `Back` labels added for the new panel chrome) — no other label moved,
  vanished, or duplicated.
- `php -l` and a full `Blade::compileString()` compile check: both clean.
- `dev-check.ps1` **cannot run on this box** — `pwsh` is not installed on
  this Linux QA host. Substituted `tests/Feature/Features/FeatureNavGuardCoverageTest.php`
  (the structural test that reads the sidebar file directly and checks
  every feature-registry entry against its actual nav guard) — **PASS**.

---

## Applicant-facing fixes (Johan, 2026-09-07 — "no submit application button")

Six real defects on the public token-based side, all found by rendering the
real page for a real token (not inferred) and, for the first one, a
headless-browser screenshot — not just reading the HTML.

1. **The submit button was invisible, not absent.** Root cause: the button
   used the raw Tailwind arbitrary-value class `bg-[#0b2a4a]`. Tailwind's
   JIT compiler only emits a utility class if it can see it in a scanned
   file AT BUILD TIME — the compiled CSS bundle on this box predates this
   blade file by ~2 weeks, so the class compiled to nothing. Confirmed with
   a real headless-browser render: the button had correct dimensions, was
   Alpine-hydrated, zero console errors — but rendered as white text on an
   unstyled background against the white card behind it. Fixed with an
   inline `style="background: var(--brand-default, #0b2a4a);"`, the same
   var()-with-fallback pattern UI_DESIGN_SYSTEM.md already requires — this
   can never depend on a Tailwind rebuild, so it can't regress the same way.
2. **Validation-failure redirects silently discarded the applicant's typed
   answers.** `<x-rental-application-field>` and three raw textareas/one
   select read straight from `$application` (the DB row), never `old()`.
   Laravel's own validate() failure already flashes old input to the
   session correctly — the views just never read it back. Fixed in the
   component (fixes every field that uses it in one place) and the four
   raw fields individually. No error messages were shown anywhere either —
   added a summary banner plus a per-field `@error()` message.
3. **No agent notification existed at all** on a successful submission.
   Added `RentalApplicationNotifier` + `RentalApplicationReturnedMail`
   (new files, isolated from the agent-side lane's own
   `RentalApplicationMailer`), fired from `submit()` after the DB
   transaction commits (a mail failure must never roll back the
   applicant's already-saved submission). Verified via Mailpit's real HTTP
   API (`127.0.0.1:8025`) — the notification actually arrived, addressed
   to the sending agent, nothing sent to a real address (MAIL_HOST is
   Mailpit's own local listener, port 1025 — structurally cannot escape).
4. **`submit()`'s writes were not transactional.** The record save and both
   signature captures could partially land (e.g. a disk failure between
   the two signature writes) leaving `status='returned'` with only one
   signature saved — an inconsistent state with no way back. Wrapped in
   `DB::transaction()`.
5. **Supporting-document upload was silently unavailable after
   submission.** `uploadDocuments()` never blocked on status (matching the
   spec's "before OR after signing" design, mirroring the e-sign
   precedent) — but `already-submitted.blade.php` never rendered the
   upload form at all, cutting off a path the backend already supported.
   Added the same upload section already-submitted.blade.php was missing.
6. **A rejected upload (wrong file type, too large) showed neither success
   nor error** — confirmed by deliberately uploading a non-PDF file and
   getting a silent, message-free redirect. `$errors` was populated but
   nothing on either page ever displayed `supporting_files` /
   `supporting_files.*` errors. Added per those keys to both upload
   sections.

**Verified end-to-end via real HTTP requests** (curl, cookie jar, real
CSRF, against the actual live QA1 URL) against real application id=1:
malformed email → redirected back with "Test Applicant XYZ" preserved and
the exact field error shown; valid submission → DB row shows every field
persisted, both signature files genuinely exist on disk
(`Storage::exists()` confirmed); re-opening the token shows "Application
already received" with no submit form present, and a direct POST replay
with a valid CSRF for the same session was refused by `submit()`'s own
status guard (DB row and signature count unchanged, confirmed after);
document upload confirmed with a real PDF — file exists on disk, `documents`
row correctly linked to the contact; the agent-notification email was
captured by Mailpit with the correct subject and recipient.

**Also removed**: a dead, unreachable status-check branch in
`show.blade.php` — `RentalApplicationSigningController::show()` already
routes any `returned`-or-later status to the separate
`already-submitted.blade.php` view before this template is ever reached,
so the branch could never fire.

---

## Applicant-side document CRUD & access control (Johan, 2026-09-07 — "proper CRUD" standard)

Full CRUD for the applicant's own supporting documents, applied to the
public token-based side per the box-wide standard: "we always need proper
CRUD... own / branch / agency levels... design and build correctly from
the word go." List-screen requirements (search/sort/filter/pagination)
do not apply here — there is no list screen on the public side, only an
individual applicant's own small document set.

### Access control mechanism

A `documents.id` is a globally auto-incrementing key on a table shared
across every agency, every module, and every application — knowing or
guessing an id proves nothing. Every document action on the public route
(`view`, `remove`, `replace`) re-derives the application from the URL
**token** first, then independently verifies the target document's
`source_type === 'rental_application' AND source_id === $application->id`
before permitting any read or write
(`RentalApplicationSigningController::scopedDocument()`). A mismatch
returns **404, never 403** — a 403 would confirm "this id exists but is
off-limits," leaking information a 404 does not.

**Verified with real HTTP requests, not asserted:**
- Fetching Application A's document using Application B's token → 404.
- Fetching a raw/guessed document id (999999) using a valid but unrelated
  token → 404.
- Removing Application A's document using Application B's token → blocked;
  confirmed via DB that the document's `deleted_at` stayed `NULL` — the
  blocked attempt did not silently soft-delete anything anyway.
- A completely invalid/guessed token → 404 (`firstOrFail()` in
  `findByToken()`), same as any other unknown resource.
- The identical action with the document's own correct token → 200, real
  file returned. Confirms the control is a real boundary, not a
  blanket failure that would also break the legitimate path.

### Token expiry

`token_expires_at` is checked independently on every route that touches
an application — `show`, `submit`, `uploadDocuments`, `viewDocument`,
`removeDocument`, `replaceDocument` — not only on the entry page. Verified
with a genuinely expired test application (`token_expires_at` in the
past): the entry page renders the "This link has expired" view with no
form; `viewDocument` on a document that existed before expiry returns 404;
`uploadDocuments`, `submit`, `removeDocument`, and `replaceDocument` all
redirect back with "This link has expired" and perform no write (confirmed
via DB — the application's `status`/`submitted_at` and the document's
`deleted_at` were unchanged after the blocked attempts).

### Full CRUD, including after submission

The applicant can **view, replace, and remove (archive)** their own
documents, not just create — "create-and-submit-only is not finished."
`replaceDocument()` performs the swap atomically inside a `DB::transaction`:
the new document is filed and the old one archived together, or neither
happens.

Document actions remain available **after the application is submitted**
(`status = 'returned'`), matching the pre-existing spec design that
supporting documents are uploadable "both before signing and after
signing" (§ Documents). Only the *main application form* is blocked once
submitted (`submit()`'s own status guard, unchanged) — re-reading Johan's
"an expired/revoked/already-submitted token cannot be used to read or
write anything" as applying to the application record itself, not to the
already-approved post-submission document flow it would otherwise
contradict. **Flagged for confirmation, not assumed**: if document actions
should also lock once `status = 'returned'`, that is a one-line status
check to add to `scopedDocument()` or each action — not done here pending
Johan's call.

Verified end-to-end against a real, already-submitted application: opened
the "Application already received" page with its own valid token,
replaced its one existing document — old document's `deleted_at` set
(soft delete only, file remains on disk), new document created in the
same transaction (identical timestamp), `documents()` relationship count
correctly drops the old doc; then removed the new document — `deleted_at`
set, file still exists on disk, `documents()` relationship count now `0`,
and re-fetching the removed document's own view URL correctly 404s.

### No hard deletes

`removeDocument()` and the old-document side of `replaceDocument()` both
call `Document::delete()` — `Document` already uses `SoftDeletes`, so this
is always a soft delete; no `forceDelete()` anywhere in this code path.
Confirmed by DB inspection after every remove/replace test above: the row
persists with `deleted_at` set, the file remains on disk, and the record
would be recoverable exactly as with any other soft-deleted document.

### Agent-side visibility

No change needed on the agent side (confirmed with cc3, agent-side lane
owner): the agent's document listing already reads through the standard
`documents()` Eloquent relationship, which respects `SoftDeletes`
automatically — an applicant's replace/remove is reflected there with no
additional work.

### Routes added

`GET /rental-application/{token}/documents/{document}` (`throttle:30,1`),
`POST /rental-application/{token}/documents/{document}/remove`
(`throttle:10,1`), `POST /rental-application/{token}/documents/{document}/replace`
(`throttle:10,1`) — same throttle convention already used by
`/sign/{token}`.

---

## Agent-side hardening (2026-09-07)

Johan tested the applicant side himself and hit a blocker within minutes;
the agent side was assumed equally under-tested and every screen was
re-rendered through the kernel as a real authenticated user (not just
grepped) to check. It was — four real gaps found and fixed, none of which
had any test coverage before this pass:

1. **`searchProperties()` returned every listing type.** A rental
   application's property picker showed for-sale, commercial, and vacant
   land listings alongside rentals. Fixed with a `listing_type = 'rental'`
   filter — `RentalApplicationController::searchProperties()`.
2. **`show.blade.php` rendered only ~40% of the V8 field list.** Missing
   entirely: `property_address_override`, `current_residential_address`,
   the whole Emergency Contact block, the whole Current Landlord block,
   `employer_address`/`employer_tel`, and the whole Requirement of Lease
   block (`occupation_date`, `rental_terms`, `special_conditions`,
   `adults`, `children`). An agent opening a submitted application could
   not see or edit most of what the applicant actually submitted, even
   though the PDF template (`corex/rental-applications/pdf.blade.php`)
   already rendered every field correctly — the two views had silently
   diverged. Fixed by adding the missing sections to the edit form,
   mirroring the PDF's own field list exactly. Three of the added fields
   (`current_residential_address`, `employer_address`,
   `special_conditions`, all `max:2000` per the shared validation rules)
   use a plain inline `<textarea>` rather than the shared
   `x-rental-application-field` component — that component is also used by
   the public applicant view and was being actively edited by the
   applicant-side lane at the same time, so it was left untouched rather
   than risk a collision.
3. **No document-download route existed on the agent side.** Supporting
   documents were listed by filename only, with no way to open one. Added
   `GET corex/rental-applications/{rentalApplication}/documents/{document}`
   → `RentalApplicationController::downloadDocument()`. Both route
   parameters are agency-scoped by their own model's `BelongsToAgency`
   global scope (a cross-agency id 404s at route-model-binding, before the
   method body ever runs); the method additionally checks
   `source_type`/`source_id` match as defense-in-depth against a
   same-agency agent guessing a document id belonging to a different
   application.
4. **No archive/delete route existed.** `RentalApplication` already had
   `SoftDeletes`; the destroy action was simply never wired up. Added
   `DELETE corex/rental-applications/{rentalApplication}` →
   `RentalApplicationController::destroy()`, gated on
   `rental_applications.create` (the spec defines no separate delete
   permission), with a confirm dialog on the Archive button per
   STANDARDS.md.

Also verified and confirmed already correct (no changes needed): contact
prefill on create, send + Mailpit-only delivery, agency isolation at both
the route-model-binding and raw-query level, the settings-screen
save/reload round-trip, and PDF generation (`RentalApplicationPdfService`,
shared with the applicant-side `pdf()` route) — a real PDF was generated
and opened, containing the applicant's actual submitted field values,
correct agency branding (no hardcoded HFC literals), and rendering
uploaded signature images correctly.

New test coverage: `tests/Feature/RentalApplications/RentalApplicationAgentControllerTest.php`
— none existed for this controller before this pass.

---

## CRUD / search / sort / scoping standard (Johan, 2026-09-07 — permanent, applies from the word go)

Johan, verbatim, mid-build: "we always need proper crud? search / sort /
own / branch / agency levels. that should be the design standard. not me
asking for it once we get to that stage." This is a correctness/security
requirement, not a setting — there is no toggle, it just works. Applied
immediately to the agent side of this module rather than deferred; the
same standard is being written into BUILD_STANDARD.md/STANDARDS.md/CLAUDE.md
project-wide (a separate lane's work, not this one's).

**Full CRUD.** Create (`store`), read (`index`/`returned`/`show`), update
(`update`), archive (`destroy` — soft delete only, `RentalApplication`
already had `SoftDeletes`), and restore (`restore`, new). A route-model-
bound `{rentalApplication}` 404s on a soft-deleted row by default, so
`restore()` explicitly binds `RentalApplication::withTrashed()->findOrFail()`
— the only action in the controller that needs to.

**Search** (both `index` and `returned`, `?q=`): matches the application
id itself (an agent quoting "#42"), `property_address_override`, the
linked contact's `first_name`/`last_name`/`email`, and the linked
property's `address`/`title`. Both list screens show a real empty state
("No rental applications match this search" / "No returned applications
match this filter") when a search/filter combination matches nothing,
distinct from the true-empty-table state.

**Sort** (`?sort=&direction=`): `contact` (joins `contacts.last_name`),
`property` (joins `properties.address`), `status`, `date` (`created_at`
on `index`, `submitted_at` on `returned`). Default: `date desc` (newest
first) on both screens, unchanged from before this standard, now explicit
and user-controllable via clickable column headers. **`status` is a MySQL
`enum`** (`RentalApplication::STATUSES`) — sorting by it orders by
*declared* index (`sent` → `in_progress` → `returned` → ...), i.e. rough
workflow order, never alphabetically. This is intentional/more useful
than alphabetical for a status column, not a defect — documented here so
a future reader doesn't "fix" it into alphabetical order.

**Date range** (`?date_from=&date_to=`): filters the same date column
sorting defaults to (`created_at` / `submitted_at`).

**Pagination**: 25 per page on every list (`index`, `returned`, and the
new archived sub-list), `->withQueryString()` so search/sort/filter
survive pagination.

**Own / branch / agency scoping — enforced at the query layer, on every
list, detail view, PDF, and document download, never by hiding a link:**

- `RentalApplication::scopeVisibleTo($query, $user)` (new model method) —
  the list-query guard, applied in `index()`, `returned()`, and the
  archived sub-query. Mirrors `Docuperfect\Document::scopeVisibleTo()`
  EXACTLY: `PermissionService::getDataScope($user, 'rental_applications')`
  resolves to `'own'` (→ `created_by_user_id` — the creating agent, this
  module's equivalent of Document's `owner_id`), `'branch'` (→
  `branch_id`), or `'all'` (agency-wide — the tenant boundary itself is
  still `BelongsToAgency`'s own global scope underneath this).
- `AuthorizesRentalApplicationAccess::guardRentalApplication()` (new
  trait, `app/Http/Controllers/Concerns/`) — the single-record sibling,
  called at the top of `show()`, `update()`, `send()`, `pdf()`,
  `destroy()`, `restore()`, and `downloadDocument()`. Mirrors
  `AuthorizesDocumentAccess::guardDocument()` exactly, so list and
  single-record access can never disagree.
- This is the SAME mechanism the Documents module already uses — wiring
  an existing, established pattern into a new module, not new
  architecture.
- Proven with real requests in
  `tests/Feature/RentalApplications/RentalApplicationCrudStandardTest.php`,
  not asserted: an `agent`-role user (own scope) sees only applications
  they created and gets a real 403 opening another agent's; a
  `branch_manager` (branch scope) sees only their branch's; an `admin`
  (agency/all scope) sees every branch in the agency; a different
  agency's admin gets a real 404 on both `show` and `pdf` by direct URL
  (route-model-binding never resolves a cross-agency id at all, before
  any scope check runs).
- **Known gap, not fixed here (deploy/ops, out of this lane's scope):**
  `corex:sync-permissions --merge-defaults` has so far only granted the 4
  `rental_applications.*` permission keys to the `admin` role (per cc2's
  2026-09-07 QA1 fix, see "Deploy requirements" above) — no
  `rental_applications.view` *scope* row exists for any other role on
  real QA1 data, so a genuine front-line `agent` account cannot open this
  feature at all yet (403 at the permission-middleware layer, before
  `scopeVisibleTo()` is ever reached). The scoping mechanism itself is
  correct and tested against the `agent`/`branch_manager` roles' own
  built-in fallback defaults (`PermissionService`'s AT-265 unseeded-grants
  posture) — what's missing is deciding and granting which roles besides
  `admin` should hold `rental_applications.*` at all, and at what scope.
  That is a product/permissions decision for Johan, not a code defect.

---

## Temporary Rentals-panel visibility (Johan, 2026-09-07 — QA1 only)

> **PARKED, 2026-09-07 — leave exactly as is.** Johan has seen the
> unhidden panel and said: *"old rental menu back. for now just park it.
> we will work through it."* **Parked means untouched and still
> reachable, not reverted.** Do NOT re-hide it, do NOT remove it, do NOT
> touch any of its 5 screens, do NOT revoke or change any permission.
> Current state:
> - The panel is unhidden on QA1 (commit `9a1dedde3`) exactly as
>   described below, and remains so.
> - **No permission grants were made.** Checked before granting anything:
>   user 22 already held all 3 required checks (`view_rentals`,
>   `access_rental_signatures`, `hasFeature('rentals')`) — nothing needed
>   to change. `role_permissions` was backed up anyway per instruction
>   (`/root/db-backups/qa1-role_permissions-20260907-083432.sql`), as a
>   precaution, not because anything was written to it.
> - The one-step reversal is documented in full below and inline in the
>   blade file's own comment at the unhidden block. It has NOT been
>   applied, and should not be, until Johan has walked through the panel
>   and decided what stays.
> - Johan will return to this later to decide what stays and what goes —
>   nobody should "tidy" this away, merge it with the new AT-392 section,
>   or rename anything, in the meantime.

Johan's words, verbatim: *"On the rental menu - put it back for me on qa1.
then let me click through it. but I know already the whole esign is
redundant. we trashed it when we built the esign part and unless code are
shared we dont need it at all as we will only use the esign going
forward. the rest once I can see it we can look at what we are keeping
and what not."*

This is a **visibility change for evaluation only** — not a decision to
keep anything. The pre-existing "Rentals" drill-down (Rentals / Dashboard
/ Electronic Signatures / Active Leases / Expired Leases), previously
nested inside the sidebar's owner-only `$isOwner`-gated "Hidden" section
(see "Known, deliberate, pending reconciliation" above), was extracted out
to its own top-level group so a normal agency admin can reach it. Every
individual `@permission` gate on its 5 links is completely unchanged;
only its position in the sidebar and its wrapper CSS classes (promoted
from subgroup to top-level styling to match the new depth) moved.

**Prior state (for the one-step reversal):**
- The whole block (from `{{-- Rentals — nested drill-down --}}` through its
  `@endpermission`) sat directly before `{{-- Evaluation — nested
  drill-down --}}`, inside the `<div>` opened by `push('hidden')`.
- Its button/panel classes were `corex-nav-subitem corex-nav-group-toggle
  corex-nav-subgroup-toggle` (no icon).
- `$navGroupParents` (near the top of the file) included `'rentals' =>
  'hidden'`.
- **To reverse:** move the block back to that exact spot, restore those
  classes, and put `'rentals' => 'hidden'` back in `$navGroupParents`. The
  live sidebar file's own inline comment at the unhidden block's new
  location repeats these exact steps.

**Permissions — none were actually missing.** Checked before granting
anything: user 22 (johan@hfcoastal.co.za, admin, agency 1) already held
`view_rentals`, `access_rental_signatures`, and `hasFeature('rentals')` —
all three required checks were already true. Backed up `role_permissions`
to `/root/db-backups/qa1-role_permissions-20260907-083432.sql` (5.4MB,
verified valid) before confirming this, per instruction, even though no
grant turned out to be necessary.

**Verified working for a normal agency admin, not just the system-owner
account used in earlier rounds.** Dispatched real authenticated requests
(full kernel, not route:list) to all 5 routes as user 22:

| Route | Status | Response length | Error markers in body |
|---|---|---|---|
| `rentals.index` (`/rentals`) | 200 | 315,749 bytes | none |
| `rental.dashboard` (`/rental`) | 200 | 187,893 bytes | none |
| `rental.signatures` (`/rental/signatures`) | 200 | 547,388 bytes | none |
| `rental.active-leases` (`/rental/active-leases`) | 200 | 191,722 bytes | none |
| `rental.expired-leases` (`/rental/expired-leases`) | 200 | 183,781 bytes | none |

Also scanned every response body for embedded error text (a caught
exception can still return HTTP 200 with an error view) — clean on all 5.
Confirmed `storage/logs/laravel.log` gained zero new lines during the
entire test run. None of these will 500 for Johan.

**What a normal admin now sees, overall — reported plainly, not tidied:**
two separate "Rentals" toggles, back to back, in the same part of the
sidebar. The first (this build's new section) expands to "Rental
Applications" / "Returned Applications." The second (this unhidden panel)
expands to a panel also titled "Rentals," containing a link also labelled
"Rentals," plus Dashboard / Electronic Signatures / Active Leases /
Expired Leases. Deliberately placed adjacent rather than apart, so Johan
can see and compare both directly. This is the same naming collision
already flagged, now visible to more than just a system-owner account —
nothing renamed or merged to resolve it.

### Code-sharing investigation — "Electronic Signatures" vs. current DocuPerfect e-sign

Read-only, per instruction. Answer: **the "Electronic Signatures" screen
(and the Dashboard screen, which draws from the same query) are not a
separate system — they are a filtered view over the exact same tables and
service class the current e-sign module uses.** Removing them later would
NOT be a clean excision.

**Shared, confirmed by reading the actual code:**
- `Rental\RentalDivisionController` (`dashboard()` and `signatures()`)
  both call `App\Services\Docuperfect\SignatureService::getRentalDashboardData()`
  — the SAME `SignatureService` class DocuPerfect's own e-sign wizard
  uses for template creation, marker/zone placement, and document-hash
  verification (`createTemplate()`, `saveMarkers()`, `expandZone()`, etc.
  all live in this one class).
- `getRentalDashboardData()` queries `App\Models\Docuperfect\Document`
  directly (filtering `document_type = 'rental_upload_send'` or
  `template.template_type = 'rental'`) and
  `App\Models\Docuperfect\SignatureTemplate` directly
  (`whereIn('document_id', $documentIds)`) — the exact central tables
  every other e-sign document in CoreX uses, distinguished only by a
  type/template-type value, not a separate table.
- `App\Models\Docuperfect\LeaseRecord` (`lease_records` table) has **hard
  foreign-key constraints**, not just a runtime query relationship:
  `document_id` → `docuperfect_documents.id` (`cascadeOnDelete()`) and
  `signature_template_id` → `signature_templates.id`
  (`cascadeOnDelete()`) — confirmed directly in
  `database/migrations/2026_02_26_600007_create_lease_records_table.php`.
  A `lease_records` row cannot exist without a matching row in both
  central e-sign tables, and deleting either cascades the lease record
  away with it.

**Genuinely separate, not shared:** `App\Models\Rental\RentalProperty`
and `App\Models\Rental\RentalDocumentType` — rental-specific tables with
no FK or query relationship into `documents`/`signature_templates`. The
top-level `Rentals`/`rentals.index` screen (`App\Models\Rental` +
`RentalsController`) also has zero references to
`Docuperfect`/`SignatureTemplate` anywhere in its model or controller.

**Net:** "Active Leases" and "Expired Leases" (via `LeaseRecord`) and
"Electronic Signatures"/"Dashboard" (via `SignatureService` +
`Document`/`SignatureTemplate`) all sit on top of the same e-sign
infrastructure the DocuPerfect module owns. Only the plain "Rentals" list
screen and its underlying `Rental`/`RentalProperty`/`RentalDocumentType`
models are self-contained. Not acted on — reported only, per instruction.
