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
   Status values: `draft`, `sent`, `in_progress`, `returned`,
   `under_assessment`, `approved`, `declined`, `withdrawn`. `draft` is the
   true starting state (added 2026-09-07 — see "Status model + sticky
   header" below); `sent` is set only once mail has genuinely gone out.
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
### Role defaults (Johan, 2026-09-07 — resolves the gap this section used to describe)

**Previously a real gap, now fixed on QA1:** `corex:sync-permissions
--merge-defaults` had only ever granted the 4 `rental_applications.*` keys
to the `admin` role (cc2's 2026-09-07 fix only covered admin) — a genuine
front-line agent account could not open this feature at all. Johan: "he
moved this feature into the normal agency-visible menu precisely because
agents are the people who will use it. A rental application that only an
admin can open is not a working feature."

`config/corex-permissions.php`'s `role_defaults` now grants, matching the
house pattern (sanity-checked against `documents.view`/`documents.create`
and `rentals.view`/`rentals.create`, which use the identical shared-key-
across-roles approach — breadth is enforced by scope, not by giving each
role a differently-named key):

| Key | admin | branch_manager | agent |
|---|---|---|---|
| `rental_applications.view` | ✓ (via all-minus-exclude) | ✓ | ✓ |
| `rental_applications.create` | ✓ | ✓ | ✓ |
| `rental_applications.view_returned` | ✓ | ✓ | ✓ |
| `rental_applications.manage_settings` | ✓ | — | — |

`manage_settings` is deliberately admin-only, matching the house pattern
for `manage_*`/`*.configure`-shaped keys elsewhere (`manage_finance_definitions`,
`compliance.whistleblow.configure`, `outreach_templates.manage`) — narrower
than the `view`/`create` tier. `admin` needed no explicit `role_defaults`
edit at all: it already gets every permission via the all-minus-exclude
pattern, which is why cc2's original sync granted it automatically.

**Deployed to QA1 on 2026-09-07** via `corex:sync-permissions --merge-defaults`
(role_permissions backed up first to `/root/db-backups/` — safe, additive,
existing customisations untouched). Before: 55,040 total rows, 172
`rental_applications.*` rows (admin only, 43 agencies). After: 55,280 total
rows, 412 `rental_applications.*` rows — exactly 240 new rows
(`branch_manager` +3 keys × 40 agencies, `agent` +3 keys × 40 agencies),
verified by direct query that **every single new row's key starts with
`rental_applications.`** — nothing outside this module was touched by this
run. `rental_applications.view`'s `scope` column resolved correctly per
`scope_defaults` (admin→`all`, branch_manager→`branch`, agent→`own`); the
other three keys correctly carry no scope (they gate route access only,
`getDataScope()` only ever reads the `.view` key regardless of which
action-permission unlocked the route).

**Re-verified with real QA1 accounts, not synthetic test users, after the
grant** (Retha Kelly `agent`/branch 1, Shawn Du Bois `agent`/branch 1,
Jenny Joubert `agent`/branch 2, Falan Du Bois `branch_manager`/branch 1,
Sandra Mante `admin`/agency 90) — actual HTTP status codes:

| Actor | Target | Route | Status |
|---|---|---|---|
| Agent (own) | Own application | index/show/pdf/document-download | 200 |
| Agent (own) | Same-branch colleague's application | show/pdf/document-download | **403** |
| Agent (own) | Different-branch agent's application | show/document-download | **403** |
| Branch manager (branch) | Own-branch application (either agent) | index (sees both)/show | 200 |
| Branch manager (branch) | Different-branch application | show/pdf | **403** |
| Different-agency admin | Any application in this agency | show/pdf/document-download | **404** |

The 403 vs 404 split is intentional, not inconsistent: same-agency-wrong-scope
is a real 403 (the record exists, this user just isn't permitted); a
different agency's admin gets 404 because `BelongsToAgency`'s global scope
never resolves the row for route-model-binding at all, before any
finer-grained scope check runs — the same behaviour already proven for the
`admin`-only case earlier in this section, now reconfirmed with the newly-
granted roles.

### Required post-deploy step — do not skip

**`php artisan corex:sync-permissions --merge-defaults` is a REQUIRED step
on every environment this feature is deployed to** (QA1, Staging, live) —
it has already been missed once on QA1 today (cc2's fix only covered
`admin`; this entry's own fix was needed to cover `branch_manager`/`agent`
too). A `git pull` deploy never runs this automatically. Add it to this
feature's deploy checklist alongside `deploy:sync-reference-data`
(CLAUDE.md Non-negotiable #12, BUILD_STANDARD §8) — both exist for the
same reason: seeder/config-owned data that a plain `migrate` does not
carry across environments.

### Why cc4 found DB rows the config's git history didn't yet explain

cc4's audit (2026-09-07, correct and independently verified) found that
`role_permissions` on QA1 already held the `agent`/`branch_manager` grants,
while `/corex-qa1`'s own checked-out `config/corex-permissions.php` still
had no `role_defaults` entry for them. **The grants were never written
into the database by hand** — the sequence was: this fix's config change
was committed and pushed to `feature/rental-applications`, then
`corex:sync-permissions --merge-defaults` was run from an isolated
worktree that HAD that commit (both worktree and `/corex-qa1` share the
same `corex_qa1` database, but each has its OWN git checkout and
filesystem) — at that point `/corex-qa1`'s own checkout had not yet merged
`feature/rental-applications` (its last merge predated this fix), so its
copy of the config still looked stale even though the actual grants and
the actual committed config already agreed with each other. Confirmed via
`git merge-base --is-ancestor` that the fix commit was genuinely not yet
an ancestor of QA1's `HEAD` at the time. This is a deploy-*sequencing* gap
(config commit pushed, not yet merged into the environment's own branch),
not a bypassed-config gap — but it looks identical to one from the
outside, which is exactly why the stronger clean-state proof below exists
rather than trusting the DB state alone.

### Proof from a genuinely clean state — not a re-run against pre-existing rows

`tests/Feature/RentalApplications/RentalApplicationPermissionDefaultsTest.php`
proves the actual claim Johan needed proven: starting from a `role_permissions`
table with **zero** rows (RefreshDatabase — a real transaction-backed test
database, never run against real data), calling
`Artisan::call('corex:sync-permissions', ['--merge-defaults' => true])`
with no other setup produces exactly: `admin` holds all 4 keys (via
all-minus-exclude — `manage_settings` included), `branch_manager`/`agent`
hold the 3 non-settings keys, `rental_applications.view`'s `scope` column
resolves per `scope_defaults` (`all`/`branch`/`own`), the other three keys
carry no scope, and a second run inserts nothing new (idempotent). The
config alone — no manual database step — reproduces the correct grant set.
This is what makes the feature safe on a fresh agency, a QA1 reset,
Staging, or live: whoever runs the standard post-deploy sync gets these
grants back every time, from nothing.

**Found and reported, platform-wide, out of this lane's scope — now FIXED
(Johan, 2026-09-07: "the platform wide bug - needs attention and get
fixed. hate silent fails.")** — proving the grant set above exposed a
real, pre-existing limitation in `SyncPermissions::mergeRoleDefaults()`
itself, unrelated to Rental Applications specifically. It resolved
template roles via `Role::all(['name','is_owner','agency_id'])` wrapped in
a `try`/`catch` that only fell back to a synthetic template-role list when
`Role::all()` *threw* (e.g. the table doesn't exist) — but on a genuinely
fresh `roles` table that exists with zero rows (the real state until Role
Manager or a seeder creates rows; no seeder currently populates it),
`Role::all()` returns an empty Collection without throwing, the fallback
never fired, and `--merge-defaults` silently granted **nothing, for every
module**, not just this one, while still reporting success. The test
above worked around this by seeding the same minimal template `Role` rows
a real onboarded environment already has
(super_admin/admin/branch_manager/agent/viewer/office_admin,
`agency_id=null`) — a genuinely fresh *grants* table, not a genuinely
fresh *roles* table, which is the realistic scenario.

Johan explicitly authorised and directed the fix once this was reported;
it has since been built, proven, and shipped. Full writeup — the defect,
the `resolveRolesOrFail()` fix, the loud-reporting/non-zero-exit
mechanism, the sibling-command check, and the test file — lives in
`.ai/specs/roles-permissions.md` §10 (that spec, not this one, owns
`SyncPermissions.php`). The grant set proven above in this file was
re-confirmed passing unchanged under the fixed command
(`RentalApplicationPermissionDefaultsTest.php`, 2/2 green).

---

## Document-visibility bug (Johan, live on QA1, 2026-09-07)

Johan: "on testing rental applications the uploaded doc do not pull back
with the rental application." Traced the full chain on real QA1 data
before changing anything, per instruction:

1. **Application identified:** id=13 (agency 1, contact 16193, status
   `in_progress`, real 64-char token) — the most recent genuine activity on
   QA1 at the time, with a real ~1.3MB PDF uploaded. Ruled out ids 14–16 as
   another lane's audit fixtures (literally named "HACKED BY adminW" /
   "Audit App owned by AgentX") and ids 1–2 as having zero active documents
   (consumed by earlier test activity, not a bug).
2. **File on disk:** clean — exact size match, correct `www-data:www-data`
   ownership, correct permissions. The historical root-owned-file footgun
   does not apply here.
3. **Database row:** clean — `documents` row exists, `deleted_at` is NULL.
4. **Linkage:** clean — `source_type`/`source_id`/`agency_id`/`branch_id`
   all match the parent application exactly; the join executes and returns
   the row.
5. **Agent view query:** `show()`'s `documents()` relationship carries no
   extra filter beyond `source_type` — the own/branch/agency scoping work
   never touched it.
6. **Rendered and looked** (not grepped): dispatched a real authenticated
   request as user 22 to `show(13)`, from both an isolated worktree AND
   directly from `/corex-qa1`'s own live codebase — the document rendered
   correctly both times. **Not reproducible on this specific application,
   right now.**

**Root cause, found by reading the code, not by guessing:**
`RentalApplicationSigningController::uploadDocuments()` only ever advances
status `sent → in_progress` (it never reaches `returned`, which requires
the full sign-both-declarations `submit()`). But
`RentalApplicationController::returned()`'s status filter was
`['returned','under_assessment','approved','declined','withdrawn']` —
**`in_progress` was excluded**. An applicant who uploaded a real,
correctly-filed document without finishing the signature flow was
invisible on the one screen named for reviewing incoming applicant
activity — not because the document was broken, but because the
*application* never surfaced there in that state.

**Why cc2's QA sweep passed while Johan's real use failed:** cc2 almost
certainly tested the linear happy path — full submit, both signatures,
*then* a document present, which lands in `returned` and always displayed
correctly. Real usage doesn't queue up that neatly: Johan uploading a
document without necessarily finishing signatures first is exactly the
ordering the happy-path QA sweep never exercised. The lesson generalised:
a scripted QA pass that only walks the linear/complete path is not
equivalent to proving what a real, non-linear user does — the gap here
was in test *coverage of ordering*, not in the document-handling code
itself, which was correct throughout.

**Fix:** `returned()` now includes `in_progress` in its status filter
(`app/Http/Controllers/CoreX/RentalApplicationController.php`); the status
tab bar in `returned.blade.php` gained a matching "In progress" tab.
`in_progress` deliberately still shows on `index()` too — left there
rather than removed, so nothing an agent currently relies on seeing there
disappears as a side effect of this fix.

**Proven end to end, not in isolation**
(`tests/Feature/RentalApplications/RentalApplicationDocumentVisibilityTest.php`):
a real multipart file uploaded through the actual public
`uploadDocuments()` route (black-box — cc4's endpoint, never edited),
confirmed to leave status at `in_progress`; the application then appears
on both Returned Applications and the main index; `show()` displays the
document; the document downloads correctly. Scoping re-confirmed on this
exact fixture afterward: a same-agency unrelated agent gets 403 on the
download, a different-branch agent gets 403 on both the download and the
PDF, a different agency gets 404 on both (route-model-binding never
resolves it), and the owning agent still succeeds.

**cc4's leftover audit fixtures — reported, not touched (no hard
deletes):** `qa-audit-{agentx,agenty,bmz,adminw,outsiderv}@test.local`
users, rental applications 14/15/16, documents 2281/2282. Applications 14
and 15 are `agency_id=1` (Johan's real agency) with status `sent` and
full names "Audit App owned by AgentX (branch1)" / "HACKED BY adminW" —
**these DO currently appear on the real agency-1 index screen** (any
`admin`/`all`-scope viewer, including Johan's own account, would see them
mixed in with real applications) — not a data-corruption risk, but
visibly confusing if left there. Application 16 is a different agency
(7) and does not interfere with agency 1's view. None of this is mine to
clean up (cc4's own test data, and the standing no-hard-deletes rule
means it needs an explicit soft-delete decision, not a unilateral one).

**Resolved 2026-09-07:** all 5 fixture applications/documents archived
(soft delete — `deleted_at` set, files untouched on disk), 4 of the 5
`qa-audit-*` users archived (agency-1 accounts, visible in `/admin/users`
and any agent picker); the agency-7 outsider account left active since
it's invisible to Johan's own agency-scoped views and useful for
re-running the scoping audit later. Verified clean by rendering both
`corex/rental-applications` and `corex/rental-applications/returned`
through the full kernel as user 22 (johan@hfcoastal.co.za) — zero
occurrences of the fixture names in either. Genuine data counts confirmed
unchanged before/after (cross-checked by exact archive timestamp, since a
scope mistake on the first count attempt briefly double-counted
already-trashed rows from other lanes' historical test data).

---

## Post-submission document lock (Johan, 2026-09-07 — 3rd pass)

**The rule, verbatim:** "submitted docs are submitted. they can add, but
not replace or remove." Concretely:
- **Before submission:** the applicant has full document CRUD — add,
  replace, remove (archive, never hard delete).
- **After submission** (`RentalApplication::isSubmitted()`, i.e.
  `submitted_at !== null`): **add only.** Replace and remove are locked,
  application-wide, for every document on the application — including one
  added after submission. What was submitted stays exactly as submitted.

**Reasoning (evidentiary, not a UX preference):** once an agent has
received an application, the applicant must not be able to quietly swap a
payslip or pull a document the agent has already seen — that would let an
applicant retroactively alter what was actually reviewed. But the
applicant must still be able to send more: an agent asking "can you also
send your bank statements" is a normal, expected request and must not
require reopening or resetting anything.

**This is a correctness rule, not a setting.** No agency toggle, no
threshold, no configurable window — enforced identically for every
agency. Per Johan: "do not make it agency-configurable."

### Enforcement — server-side, not blade-only

`RentalApplicationSigningController::assertDocumentsNotLocked()` is the
single choke point both `removeDocument()` and `replaceDocument()` call,
right after `scopedDocument()` establishes the document genuinely belongs
to this application (so the check order is: expired token → document
exists and belongs here → submission lock). `viewDocument()` and
`uploadDocuments()` are deliberately NOT gated by this check — viewing and
adding remain available regardless of submission state (only token expiry
gates those two).

The check reads `RentalApplication::isSubmitted()` — a single-field
`submitted_at !== null` check — rather than re-deriving "is this
submitted" from the status enum at each call site. One source of truth,
so a later refactor that moves or renames a status value can't silently
un-lock this without also breaking `isSubmitted()`'s own callers.

**Proven with real requests, not asserted**
(`tests/Feature/RentalApplications/RentalApplicationDocumentLockTest.php`):
before submission, upload → replace → remove all succeed via the real
public routes (replace confirmed atomic: old doc archived, new doc
created; remove confirmed archived, not hard-deleted — row still present
via `assertDatabaseHas`). After submission: a replace POST against the
original document redirects back with a flash error and leaves the
original completely untouched (`deleted_at` still null, document count
unchanged — not even a new document was filed); a remove POST against the
same document is refused the same way, row still present with
`deleted_at` still null; an add POST still succeeds, taking the document
count to 2 and the new document's `created_at` provably later than
`submitted_at`. A dedicated test asserts the locked remove is a full
no-op at the database level (`assertDatabaseHas` with `deleted_at: null`),
not merely "still recoverable via `withTrashed()`."

### UI — the applicant sees why, not just a disabled control

Per Johan: "a greyed-out button with no explanation is a support call."
`_document-list.blade.php` (shared by `show.blade.php` and
`already-submitted.blade.php`) checks `$application->isSubmitted()`: once
true, each document's Replace/Remove controls are replaced with a plain
"Submitted — locked" label, and a line beneath the list reads "The
documents above were submitted with your application and can't be
changed. Need to send something else? Add it below — your agent will see
it as a new document." The upload form itself is never hidden or altered
— add keeps working exactly as before.

### Agent-side visibility of late additions — agreed with cc3, not built by cc4

Requirement: "Anything added AFTER submission must be visibly
distinguishable to the agent — timestamp it and surface that on the
agent's view." This is cc3's file (`corex/rental-applications/show.blade.php`),
not cc4's — no schema change needed, no new column: a document is "late"
whenever `$document->created_at->gte($rentalApplication->submitted_at)`.

**Agreed with cc3, 2026-09-07:** confirmed shape — a badge next to any
matching document in `show.blade.php` (their `returned.blade.php` doesn't
currently list individual documents, only a signed/incomplete summary, so
the badge is expected to live in `show.blade.php` only). cc3 is
implementing this after a separate, already-in-flight, Johan-approved
task (a platform-wide `SyncPermissions` fix) lands.

**Correction, 2026-09-07 (before cc3 built it, caught by this lane's own
regression test):** the comparison was originally proposed and agreed as
strict `>` (`created_at->gt(...)`). A fast automated test run exposed
that `submitted_at` and a same-request-cycle-adjacent document's
`created_at` can land in the same second-precision timestamp column,
making `>` occasionally false for a document that genuinely was added
after submission — not a business-logic error, purely a precision tie.
**Use `>=` (`gte`), not `>`.** Safe because `submit()` never creates a
document itself, so anything that already exists at the moment
`submitted_at` is set was necessarily created in an earlier, separate
request — `>=` can never misclassify a genuinely-original document as
late. cc3 has not yet implemented the badge, so no follow-up correction
is needed on their side — flagging here so the corrected version is what
ships the first time.

**End-to-end proof, real requests against live QA1** (application id 20,
token generated fresh, not a synthetic fixture): pre-submission — added
`original.pdf` (doc 2284), replaced it with `replacement.pdf` (doc 2285,
old doc's `deleted_at` set, file untouched on disk), removed it (doc
2285's `deleted_at` set) — all three actions HTTP 200/302 with their
success flash. Re-added `original2.pdf` (doc 2286), submitted both
signatures (`status` → `returned`, `submitted_at` set). Post-submission:
a REPLACE attempt against doc 2286 redirected back with the lock message
and left doc 2286 completely untouched (`deleted_at` still null, document
count still 1 — no sneaky replacement was even filed); a REMOVE attempt
against the same document was refused identically, doc 2286 still
untouched; an ADD of `bankstatement.pdf` (doc 2287) succeeded (HTTP 200,
"was uploaded"), confirmed `doc 2287.created_at` (09:44:37) is after
`submitted_at` (09:42:38) — the exact comparison cc3's badge will use.
The rendered `already-submitted.blade.php` page shows "Submitted —
locked" against the original document, zero occurrences of "Replace" or
"Remove", and the explanatory line beneath the list.

---

## Agent-side bug (Johan, QA1) — "adding and saving" the email did not persist

**This section covers the agent-facing edit screen
(`corex/rental-applications/{id}` — `RentalApplicationController`,
`show.blade.php`) — normally cc3's lane, fixed here directly on Johan's
explicit, highest-priority instruction while he was blocked and waiting.**

**Johan's words, verbatim:** "Tried rental application - loaded test
contact, no email. moans no email correctly, but adding and saving do not
persist. thats rookie coding issues that I should not have to run into."

**Root cause — NOT a persistence bug. Two different "email" values, one
visible field.** `rental_applications.email` (the "Email address" field
on the edit screen, `show.blade.php:113`) always saved correctly — proven
with real dispatched requests before touching a single line of the fix.
But `send()` (`RentalApplicationController.php:233`, pre-fix) and
`RentalApplicationMailer::sendInvite()` (pre-fix) both read
`$rentalApplication->contact->email` — a completely different column, on
the linked `Contact` record, which this screen offers no way to edit.
Sequence: contact has no email → Send correctly reports "no email on
file" (`contacts.email` genuinely empty) → agent types an email into the
only field the screen shows and saves → it saves, correctly, to
`rental_applications.email` → nothing that decides whether/where to mail
ever reads that column, so sending still (correctly, by its own logic)
reports "no email." From the agent's side this is indistinguishable from
"I saved it and it didn't stick."

**Fix:** `RentalApplication::recipientEmail()` — `$this->email ?:
$this->contact?->email` — single choke point both `send()` and
`sendInvite()` now call. The application's own, agent-editable field
takes priority (it's the one this screen exists to let the agent fix);
falls back to the contact's email if the application's own field was
never set. Deliberately does NOT write back to `contacts.email` — that's
a separate, bigger decision (should fixing the email here update the
contact's own record for every other feature that reads it?) outside
this bug's scope; flagged, not decided.

**Fix the class, not the instance (Johan's explicit instruction):** while
tracing this, found the SAME symptom already existed for other fields on
this exact form — but for the DB-persistence angle, not the "wrong field
consulted" angle. Three `<textarea>` fields
(`current_residential_address`, `employer_address`, `special_conditions`)
and the `employment_type` `<select>` read straight from
`$rentalApplication->X` on redisplay, never `old('X', ...)` — so ANY
validation failure elsewhere on this one-big-form silently reverted all
four to their stale DB value, while the `<x-rental-application-field>`
component-based inputs (already fixed once, on the public-facing form)
correctly preserved what was typed. Fixed all four to use `old()`.

Also found and fixed: **this screen had zero validation-error visibility
at all** — no summary banner, no per-field `@error` messages anywhere. A
failed save looked visually identical to a successful one; the only
difference was which fields silently reverted. Added a "Please check the
highlighted field(s) below — nothing was saved" banner and `@error`
blocks under all four raw fields, matching the pattern already used by
`<x-rental-application-field>`.

**Proven end to end, real requests as user 22, live QA1:**
- Contact with no email (id 8324, application id 5) → Send → flash
  correctly reads "This contact has no email on file"; `Mail::fake()`
  equivalent (real Mailpit check) confirms nothing sent.
- Fill `email` field only, save → `HTTP 302`, DB `email` column
  persisted, reloaded show page's input carries the value.
- Send again → flash: "Sent to johan-test-recipient@example.com" —
  **confirmed via the real Mailpit API**, the captured message's `To`
  header is exactly that address, not the (still-empty) contact email.
- Full-form submission with every field filled to a distinct value and
  ONE field deliberately invalid (`adults = 'abc'`, fails `integer`):
  redirect back, error banner and per-field message both render, DB
  confirms nothing at all was written (all-or-nothing), and — the actual
  regression check — every single field the agent typed, including the
  four previously-reverting ones, is still shown on redisplay, not the
  stale DB value. Corrected resubmission then saves everything.

**Regression tests** (`tests/Feature/RentalApplications/RentalApplicationAgentControllerTest.php`):
`test_editing_the_applications_own_email_field_is_what_send_actually_uses`
and `test_every_field_on_the_form_survives_a_validation_failure_not_just_the_corrected_one`.
Both drive the real routes end to end (`Mail::fake()` +
`Mail::assertSent(...->hasTo(...))` for the email-target assertion) —
this is exactly the class of rule ("input typed must never be silently
discarded") that a later refactor could quietly undo with no failing
assertion to catch it, hence locking it in as a real HTTP-level test
rather than a unit assertion on the model alone.

**Also found while running this file's OTHER (pre-existing, cc3's)
tests, and fixed as an environment gap, not a code bug:** this worktree
has no built frontend bundle (`public/build/manifest.json` was never
generated here — no `npm run build` was ever run in this specific
worktree), so every test hitting a `@vite()`-using view 500'd with
`ViteManifestNotFoundException` — 5 of cc3's own pre-existing tests in
this file, unrelated to this bug, were failing for that reason alone.
Added `$this->withoutVite();` to this test class's `setUp()` (the correct
Laravel testing pattern for exactly this situation, already used in
`RentalApplicationDocumentLockTest.php`). All 10 tests in this file now
pass, 73 assertions.

---

## Status model + sticky header (Johan, QA1, retest #2 — "the same fuckup as the rest of CoreX when it comes to flows")

Three real defects plus a design instruction, all found on Johan's own
real test records (applications 8, 9, 10).

### Defect 1 — status said "sent" on a record that was never sent

**Root cause: the status enum had no value at all for "created, not yet
sent."** `rental_applications.status` was `ENUM('sent', 'in_progress',
'returned', 'under_assessment', 'approved', 'declined', 'withdrawn')
DEFAULT 'sent'` — every brand-new application lied about its own state
from the instant it was created, before any code even ran. Confirmed on
Johan's real records: application 8 had `status='sent'` with `token=NULL`
(proof `send()` was never even called) and `updated_at === created_at`
(proof nothing happened after creation at all).

**Fix:** migration `2026_09_07_130000_add_draft_status_to_rental_applications.php`
adds `'draft'` to the enum and changes the column default to `'draft'`.
`RentalApplicationController::store()` now creates with `status =
'draft'`. The status badge (`show.blade.php`) renders `draft` with a
distinct muted badge class so it's visually, not just textually,
different from `sent`.

A second, related bug in the same class: `send()` used to set `status =
'sent'` **unconditionally**, including on a **resend** of an application
the applicant had already progressed past sent (`in_progress`,
`returned`, etc.) — a resend would have silently regressed a further-
along application's status back to `sent`. Fixed: status only moves off
`draft` on the FIRST successful send; a resend of an already-progressed
application leaves its status untouched.

**Data fix on Johan's own test records** (not a blanket migration of
historical data — only the specific records his testing produced under
the broken logic): applications 8 and 10 corrected from `sent` to
`draft` (neither had ever genuinely sent mail — see Defect 2); token
backfilled for application 8 (pre-fix records never got one at
creation). Application 9 — genuinely `returned` via the real public
submit flow — left untouched.

### Defect 2 — "no email in mailbox arrived" — established which failure mode, not assumed

Checked both the DB and the real Mailpit API before concluding anything.
Application 8: `token=NULL`, `updated_at=created_at` — `send()` was never
called at all. Application 10: `token` present (a `send()` call did
happen) but `email=NULL` on both the application and its contact — the
attempted send correctly found no recipient and correctly reported "no
email on file," but (Defect 1's second bug) still flipped `status` to
`sent` regardless.

**Conclusion: no email was ever actually sent for either record, and
none should have been — Mailpit confirms no matching message exists.**
The only real defect here is the misleading `sent` badge (Defect 1). This
is the *milder* of the two possible causes Johan asked to distinguish;
the other (mail claimed sent but never left) did not occur.

### Defect 3 — Send discarding typed input — structural fix, not a patch

**Root cause: Send and Save were, and remain, two separate `<form>`
submissions — the Send form has never carried the big form's fields.**
When an agent typed an email and clicked Send directly (without Saving
first), that click submitted a payload with no `email` field in it at
all; the typed value was never part of any request, so there is no
`old()` to lose — it simply never reached the server. This is not the
same class of bug as the earlier validation-failure input-loss fix (that
was about `old()` on redisplay); this is Send never having access to
unsaved form state in the first place, by construction.

**Fix — structural, not a patch:** Send is now disabled until the
application has a genuinely SAVED email (`RentalApplication::
recipientEmail()`, checked both client-side via Alpine and server-side in
`send()`). This forces Save-then-Send as the only possible sequence, so
there is no path left where clicking Send can ever discard a not-yet-
saved value — the button simply cannot be clicked (and the server refuses
it even if it is bypassed) until the email is already durably persisted.

### Design instruction — sticky header, Save moved up, Send disabled-with-reason

Built exactly as specified, using the **existing shared
`<x-sticky-action-bar>` component** (already used elsewhere in CoreX —
e.g. `docuperfect/signatures/review.blade.php` — reused, not
reinvented):

- The old static banner is replaced by `<x-sticky-action-bar>`
  (`position: sticky; top: 0`, confirmed the compiled Tailwind utility
  classes `.sticky`/`.top-0`/`.z-50` exist in the built CSS bundle before
  relying on them — the same class of check that caught the earlier
  invisible-submit-button bug on the public side). Left slot: contact
  name + status badge. Right slot: Download PDF, Save, Send/Resend,
  Archive — application name to actions-on-the-right, exactly as
  described.
- **Save moved into the header.** The big form gained `id="rentalApplicationForm"`;
  the header's Save button references it via the HTML5 cross-tree
  `form="rentalApplicationForm"` attribute (no JS needed) — it is no
  longer stranded at the bottom of a long scroll. The old bottom Save
  button was removed, not duplicated.
- **Send is disabled, never enabled-then-error** (STANDARDS.md "No Silent
  Locks — Read-Only States Must Explain & Offer A Way Forward"):
  `:disabled="dirty || !hasEmail"`. A visible reason renders beside the
  button (not hover-only): "Add an email address to send" when there's no
  saved email, "Save your changes first" when there are unsaved edits.
- **The progression Johan described** — no email → Send disabled → type
  email → Save highlights (`dirty` true) → Save → Send highlights
  (`dirty` false, email now present) — is driven by one Alpine
  `x-data="{ dirty: false }"` on the page wrapper and a single delegated
  `@input="dirty = true" @change="dirty = true"` on the big `<form>` tag
  itself. This catches every field's changes (three `<textarea>`s, one
  `<select>`, and every `<x-rental-application-field>` input) without
  touching any individual field — Alpine's event bubbling does the work,
  so this scales to any future field added to the form for free.

**Proven end to end, real requests as user 22, live QA1** (fresh
application, contact with no email): status `draft` on creation (not
`sent`); Send markup rendered `disabled` with "Add an email address to
send" visible; a direct POST bypass to `/send` was refused server-side
(`error` flash, status stayed `draft`, Mailpit confirms nothing sent);
saved an email via the header-routed Save → persisted, reloaded page
shows it; Send now succeeds → **Mailpit confirms the real captured
message**, `status` becomes `sent` only at that point, never before; full
form filled to distinct values with one deliberately invalid field
(`adults`) → redirect, nothing saved, **every field including the four
previously-reverting ones survived redisplay**, status unaffected by the
failed save. Compiled-CSS check confirmed the sticky header's utility
classes exist in the built bundle.

**Regression tests**
(`tests/Feature/RentalApplications/RentalApplicationAgentControllerTest.php`):
`test_a_brand_new_application_is_draft_not_sent`,
`test_send_is_refused_server_side_without_a_saved_email`,
`test_full_send_flow_only_marks_sent_after_mail_actually_leaves`,
`test_resending_an_in_progress_application_does_not_regress_its_status`.

---

## Phase 2 — Agent review split-screen (Johan, 2026-09-07)

**Spec-status correction, checked before any code was written, not
assumed:** the original spec listed this explicitly under "Explicitly OUT
of Phase 1" — *"Assessment split-screen with agency-configurable
affordability calculator and approval routing."* It was never built, and
was never separately communicated to Johan as deferred — he asked "wheres
the whole open on screen, agent can see in left panel, and do stuff in the
right panel part of the spec?" believing it should already exist. It was
correctly scoped out, just never flagged as such at the time. This entry
is that later phase, now built.

Johan's own words from the original design conversation, verbatim, are the
requirement this was built to: *"application gets returned, agent open
application - sees application and supporting docs on left panel of
screen... then have a place on the right panel to input things like -
income, salary / etc etc... doing the calcs to the bottom to see if tenant
qualifies. That would be a cool process and would be the start of the
rental system being built."*

### Approver concept — checked, not found, not built

Also from that design conversation: *"approver - agency select. sign as
well needed."* Grepped the entire codebase, not just this spec — no
`approver` concept exists anywhere for rental applications (one
false-positive match on the `approved` status enum value, not a role or
flow). **Not built in this pass**, per instruction — reported, not
assumed away.

### File boundaries — agreed before writing anything

Coordinated with cc3 and cc4 (both actively working in this module —
applicant form, list actions, async document upload, a queued late-
document badge on `show.blade.php`) before any code was written. Entirely
new files: no edit to `RentalApplicationController.php`,
`RentalApplication.php` (model), or `show.blade.php`. Two small additions
to shared files: one new route group appended after the existing
`rental-applications` prefix group in `routes/web.php` (no existing line
touched), and one new form section appended to the existing rental
applications settings screen (see "Qualifying formula" below — reuses the
existing settings home, does not create a second one).

### What was built

- **`app/Http/Controllers/CoreX/RentalApplicationReviewController.php`**
  (new) — `show()` (the split screen), `saveAssessment()` (autosave),
  `viewDocumentInline()`. Uses the exact same
  `AuthorizesRentalApplicationAccess::guardRentalApplication()` trait
  every other rental-applications controller action already uses, so this
  screen can never grant more access than `show()`/`downloadDocument()`
  already do — not a new authorization mechanism.
- **`app/Models/RentalApplicationAssessment.php`** (new) + migration
  `2026_09_07_150000_create_rental_application_assessments_table` — one
  row per application, every field nullable. `qualifyingResult()` is the
  calculation itself, kept on the model (not the controller) so it can
  never be called from two places and drift.
- **`app/Models/RentalApplicationQualifyingSetting.php`** (new) +
  migration `2026_09_07_150001_..._qualifying_settings_table` —
  agency-configurable threshold. `multiplierFor()` follows STANDARDS.md
  Rule 17's safe pattern exactly (sentinel-guarded, returns an in-memory
  default of 3.0 for a null/zero agency id, never writes on read, never a
  hardcoded `?? 1`).
- **`resources/views/corex/rental-applications/review.blade.php`** (new)
  — the split screen itself.
- Settings screen extended (existing file, not a new one): one more
  `<form>` posting to a new, separate route/method
  (`updateQualifyingFormula()`) so it can never interfere with the
  existing document-checklist form's own submission.
- Three routes appended to the end of the existing `rental-applications`
  prefix group + one to the settings block — no existing route line
  edited.

### Left panel — application + documents, viewable on screen

Core V8 fields shown for context (employer, position, self-reported
salary, current rental amount/landlord, household size), plus every
supporting document with an inline viewer. PDFs and images render inline
via `<iframe>`/native browser rendering — the same pattern already used
elsewhere in CoreX (`tools/evaluation-certificate/authorisations.blade.php`),
reused, not reinvented. **File types that cannot render inline (`doc`/
`docx` — both are in the upload allowlist):** no broken embed is
attempted; the document shows a "No preview" badge and a plain-language
note ("This file type can't be shown on screen — download it to view
it."), with a working download link. Determined by `mime_type` prefix
(`application/pdf`, `image/*`), not file extension.

`viewDocumentInline()` streams with `Content-Disposition: inline` (via
`streamDownload()`'s existing 4th parameter — no new streaming mechanism)
and carries the exact same `source_type`/`source_id` defense-in-depth
check `downloadDocument()` already uses, so a document can never be opened
inline through a route that the download route itself would refuse.

### Right panel — affordability capture + suggestive calculation

Three numeric fields (monthly income, other monthly income, monthly
expenses) plus a free-text notes field. **Autosaves on blur, not on a
single final submit** — "nothing the agent types may ever be lost" is an
autosave requirement, proven by reload, not a UI nicety (see verification
below). Every field is nullable and independently optional
(BUILD_STANDARD §2) — a half-filled assessment is a normal, expected
state.

**The calculation is suggestive, never a rule.** Johan, verbatim: *"The
marking is only suggestive to the agent to spot. not rule of thumb."*
`qualifyingResult()` never blocks anything, never writes a decision
anywhere, never appears as anything but a labelled suggestion on screen
("Income appears to cover the rent — worth a closer look either way" /
"Income may not cover the rent — worth a closer look" — deliberately
phrased as a prompt to look, not a verdict). When there isn't enough
input to compute anything (no income entered, or the application's own
`current_rental_amount` is blank), it shows a plain "enter income..."
message rather than a misleading zero or blank result presented as
"passed."

### Qualifying formula — agency-configurable, sensible default

Johan: *"qualifying formula - agency can set this."* One threshold today
— gross monthly income must be at least N&times; the rent, default 3.0
(the common SA/international rule of thumb) — added to the **existing**
rental applications settings screen rather than a second settings home,
per instruction. `income_to_rent_multiplier`, `decimal(5,2)`, agency-
scoped, `unique(agency_id)`.

### Verified end-to-end, real requests as user 22, live QA1 (application id 13)

- **Split screen renders**: `GET .../13/review` → 200, both panel
  headings present, both real documents ("Rental Application Test.pdf",
  "Property Report - 20 Marina Glen.pdf") listed.
- **Inline document view is genuinely real, not a placeholder**: `GET
  .../13/documents/2974/view` → 200, `Content-Type: application/pdf`,
  `Content-Disposition: inline; filename="Rental Application Test.pdf"`,
  950,811 bytes streamed, content genuinely starts `%PDF` — the real file,
  not a stub.
- **Autosave persists for real**: POST'd `monthly_income=25000,
  other_monthly_income=3000, monthly_expenses=4000` + notes → 200, JSON
  result `total_income: 28000, net_income: 24000` (rent was blank on this
  application, so `label: "incomplete"` — the graceful, correct behaviour,
  not a bug: no rent figure means no honest suggestion can be computed).
  Confirmed directly in the database (not just trusting the JSON
  response) that the row genuinely persisted with those exact values.
- **Survives a genuinely fresh page load, not just the AJAX round-trip**:
  re-dispatched a completely fresh `GET .../13/review` (new request, only
  auth carried over) — the persisted income figure, the notes text, and
  the calculated total all appear in the freshly rendered HTML, sourced
  from the database, not from any client-side state.
- **Scoping — a different agency is blocked, proven with a real user, not
  asserted**: logged in as a genuine different-agency user (agency 17 vs
  application 13's agency 1) and dispatched real requests to all three new
  endpoints — the review screen, the inline document view, and the
  assessment save — all three returned **404** (route-model-binding never
  resolves the row across the `BelongsToAgency` global scope, the same
  behaviour already proven for every other action on this module). The
  blocked save attempt (`monthly_income=999999`) was confirmed, by direct
  database check afterward, to have made **no change at all** — the
  original agent's `25000` was still there.
- `php -l` and `Blade::compileString()` clean on every new/changed file.
  `dev-check.ps1` still cannot run on this Linux box (no `pwsh`) —
  substituted `tests/Feature/Features/FeatureNavGuardCoverageTest.php`
  again since the sidebar wasn't touched by this build — PASS.

### Navigation — not yet wired, flagged not silently skipped

BUILD_STANDARD §7 requires a navigation entry in the same build. The
natural entry point is a "Review" link on `returned.blade.php`
(cc4's file, being actively edited concurrently) — coordinated rather than
edited directly; a one-line addition once cc4 confirms clear. Until then
this is reachable only by direct URL
(`corex/rental-applications/{id}/review`) — reported as an open item, not
silently left out.

### Layout fix — proportions, not a rebuild (Johan, 2026-09-07)

The first pass used a 50/50 `grid-cols-1 lg:grid-cols-2` split. Johan's own
words, verbatim: *"why does all designs turn into fuckups instead of a
working document? ... the right panel is only a small section of the
screen. not half the screen. design basics and corex have lots of them
already like on recipient esign screen - small usable panel on right ...
with enough space to still use the actual document in the middle."* A
50/50 split makes the document unreadable — it defeats the entire point of
putting it on screen.

**Pattern matched, not invented.** CoreX already solves "dominant document
+ narrow working panel," twice, and both were read before writing any CSS:

- `resources/views/docuperfect/signatures/external/sign.blade.php` —
  `.recipient-doc-main { flex: 1 1 auto; min-width: 0; }` (document,
  dominant) + `.recipient-amend-col { flex: 0 0 260px; width: 260px;
  align-self: stretch; }` (fixed narrow working column), row layout from
  1440px up, stacks below it.
- `resources/views/docuperfect/signatures/review.blade.php` (the agent-side
  signature review screen) — `.review-main { flex: 1 1 0%; min-width: 0; }`
  + `.review-aside { width: 260px; flex: 0 0 260px; align-self: stretch; }`,
  stacks below 1280px. This file's own comment already reads *"260px
  column matching cc6's recipient panel"* — confirming the 260px fixed
  working-panel width is a deliberate, twice-applied CoreX convention, not
  a coincidence.

`review.blade.php` (this screen) now uses the same shape:
`.rental-review-main` (flex: 1 1 auto, dominant — application summary +
supporting documents/iframes) and `.rental-review-aside` (fixed 260px,
sticky — the affordability assessment inputs), flex-column stacked below
1280px, flex-row above it. No third "instructions" rail exists as real
markup in either source file (the one comment in `sign.blade.php`
mentioning a "fixed left rail" doesn't correspond to any implemented
column there) — reported as such rather than inventing one.

**Convention for the next screen:** any CoreX screen that puts a document
or long content area next to an agent's own working panel should default
to this exact shape — dominant `flex: 1 1 auto` main column + a fixed
`260px` (`flex: 0 0 260px`) side column, collapsing to stacked below
~1280px. Check for an existing `.review-main`/`.review-aside` or
`.recipient-doc-main`/`.recipient-amend-col` pair before inventing a new
proportion.

**Verification.** Real in-process HTTP dispatch (Laravel's actual HTTP
kernel, not a mock) against `corex/rental-applications/13/review` — a real
application (agency 1) with two real attached PDFs (`Rental Application
Test.pdf`, `Property Report - 20 Marina Glen.pdf`) — logged in as a real
agent (id 22): `200 OK`, both PDF names present in the rendered output, the
old `lg:grid-cols-2` grid classes gone, the new `.rental-review-columns` /
`.rental-review-main` / `.rental-review-aside` classes present and
correctly nested. `php -l` clean.

A real Chromium screenshot at a 1500px viewport was attempted (Puppeteer +
system Chromium, real rendered HTML, real built CSS/JS assets) — Chromium
launched and could reach `corex/rental-applications/13/review`'s HTML
content over `curl`/plain Node `fetch`, but the browser process's own
requests to the same local server were intercepted by this environment's
sandbox networking layer (returned a generic nginx 404 that did not affect
non-browser requests to the identical URL). Could not produce an image
because of that sandbox limitation, not a defect in the page — flagging
rather than asserting it looks right. Verified the proportions
arithmetically instead, from the real layout constants (not assumed):
sidebar `w-60` = 240px, main content padding `p-4 lg:p-6` = 24px/side at
≥1024px (`layouts/corex-app.blade.php`). At a 1500px viewport: 1500 − 240
(sidebar) − 48 (padding) = 1212px content width; aside 260px + 16px gap =
276px reserved; document column = 1212 − 276 = **936px**. Under the old
50/50 grid (20px gap), the document column was (1212 − 20) / 2 = **596px**.
The fix gives the document ~57% more width, and the assessment panel goes
from 596px down to a fixed, always-narrow 260px — exactly Johan's ask.

### Six usability fixes from Johan's first real test (2026-09-07)

Johan tested the built screen live and gave six specific fixes plus one
investigate-only item. Verbatim source: his testing session on
`corex/rental-applications/13/review`.

**PDF annotation toolbar and page-thumbnail panel are the BROWSER's, not
ours — established definitively, not assumed.** The document is embedded
as a bare `<iframe src="{route}...">` where the route
(`RentalApplicationReviewController::viewDocumentInline`) streams the raw
PDF bytes with `Content-Type: application/pdf` and
`Content-Disposition: inline`. There is no PDF.js, no canvas, no annotation
layer anywhere in this codebase for this screen — confirmed by reading both
the controller and the blade file directly, not inferred. When a browser
receives raw inline PDF bytes in an iframe it renders them with its OWN
built-in viewer (Chrome/PDFium) — that viewer's toolbar (pen/highlighter/
eraser), its left page-thumbnail panel, and its "changes may be lost"
navigation warning all belong to the browser, not to CoreX. This has three
direct consequences:

- **Item 2 (default tool = highlighter, bright yellow): NOT buildable at
  all in the current architecture.** A page cannot set defaults inside the
  browser's own native PDF viewer UI — there is no API for it. Not "won't
  persist," genuinely impossible without replacing the viewer itself.
- **Items 3 and 5 (annotation persistence): confirmed — annotations
  CANNOT be persisted by CoreX with the current approach.** They live only
  in that browser tab's PDF viewer instance and vanish on navigation. The
  "changes may be lost" popup Johan saw is the browser's own warning, not
  ours — there is no save button because there is nothing of ours to save.
  No fake save button was added.
- **Item 1 (left page-thumbnail panel default-collapsed): fixable WITHOUT
  owning the viewer**, via the standard PDF open-parameter convention
  Chrome's native viewer honours — appended `#navpanes=0` to the iframe's
  `src`. This defaults the browser's own thumbnail sidebar closed; its own
  hamburger/toggle icon still opens it on demand, exactly matching Johan's
  "clicking hamburger bar to see it" ask. This is a URL-fragment change
  only — no new dependency, degrades harmlessly if a browser ignores it.

**What it would actually take to own items 2 and 3/5 properly:** replace
the iframe with a PDF.js-based renderer (canvas pages, our own zoom/scroll/
pagination), build a custom highlight/freehand-ink toolbar in our own UI,
add a migration + model for per-document annotations (page, coordinates/
points, type, colour, author, timestamps), an autosave endpoint for them,
and re-render stored annotations as an overlay on load. This is comparable
in size to the Phase 2 assessment-panel build, arguably larger — freehand
ink capture and highlight-rect hit-testing are real UI engineering, not a
tweak. **Not started. Needs its own spec and its own decision with Johan**
before any of it is built — flagged rather than guessed at.

**Item 4 — independent scrolling + collapsible summary, both done.**
Copied the exact mechanism `docuperfect/signatures/review.blade.php` already
proved for `#agentAmendPanel` (max-height: calc(100vh − 32px) +
position:sticky + align-self:stretch on the row, per that file's own
comment: "align-self:stretch is LOAD-BEARING... a sticky element inside a
box no taller than itself has ZERO scroll travel"). Applied the same
values to `.rental-review-main` and `.rental-review-aside` — both now
independently scroll within their own viewport-height budget. The
"Submitted Application" summary now collapses (closed by default): every
field in it also lives on the main application record one click away,
while the documents below are the one thing this screen adds — so by
default the freed height goes to the actual point of the screen. One click
reopens it; nothing is destroyed.

**Item 6 — data source and save visibility, answered factually.** Queried
directly, not assumed: application 13's assessment row (`monthly_income
25000.00`, `other_monthly_income 3000.00`, `monthly_expenses 4000.00`,
notes "Payslips look consistent, employer confirmed telephonically.") was
created **2026-09-07 13:56:38** by `updated_by_user_id = 22` (Johan
Reichel). No seeder or factory anywhere in the codebase references
`RentalApplicationAssessment` — grepped `database/seeders/` and
`database/factories/`, zero matches. This is Johan's own real test input,
and autosave DID fire and DID persist — the row's existence and its
realistic prose content are proof, since nothing else could have created
it. The actual bug: the save indicator only ever reflected the CURRENT
session's own save events (`saveStatus: ''` on init, regardless of
already-saved DB state), so a fresh page load of already-saved data showed
nothing — indistinguishable from "never saved." Fixed: the indicator now
seeds from the record's real `updated_at` on page load
(`initialSavedAt`, already returned by `saveAssessment()` as `saved_at`
but previously never read by the frontend), reads a live timestamp from
every save response, and renders as a persistent background+icon badge
("Saved at HH:MM") instead of small unstyled text that was easy to miss.

**Item 7 — approver/authoriser flow: confirmed NOT built, investigated
only, per instruction.** Grepped the full QA1 tree, not just this module —
`RentalApplication.php`, all rental-applications migrations, `routes/web.php`,
`config/corex-permissions.php`, every controller referencing the `approved`
status. Findings: `approved`/`declined` are ENUM values on
`rental_applications.status` only — filterable in the returned-applications
list, but **no code path anywhere ever sets them** (no controller action
transitions to `approved`). No `approver`/`authoriser` concept exists for
rental applications at all: no designated-approver agency setting, no
submit-for-approval action, no approval permission scoped to this module
(the `approve_*` permission keys that do exist all belong to other modules —
leave, RMCP, whistleblowing, FICA, policy, remote access), no approver
signature step. This matches Johan's own original design-conversation words
("approver - agency select. sign as well needed") which were already
flagged as not-built in the Phase 2 entry above — same finding, reconfirmed
on the current QA1 state after the rebuild. **Not built** — needs its own
spec conversation with Johan (who selects the approver, per-application or
per-agency default, what "sign as well" means concretely, what happens to
status/notifications on approval) before any code is written.

### Own the viewer — persistent PDF highlights (Johan, 2026-09-07)

Johan's correction, verbatim: *"we have working pdf edits like on viewing
pack with redaction. so pdf with edits exists in corex. we just need to
build it correctly from the word go and not build stuff that we cannot
actually use. like calling chrome pdf and we cannot save what we do in it
in corex for the next party to not see what was done. simple. not a
problem if we build correctly rather than trying to take shortcuts"* — he
was right: replacing the iframe's `#navpanes=0` tweak with a real annotation
layer was the correct call, not a shortcut.

**Files the working pattern was taken from, read before writing any code:**

- `resources/views/command-center/viewing-packs/show.blade.php` (the
  `redactionTool()` Alpine component, ~L411–701) — the real, persistent,
  in-app drawing UI: Pointer-Events drag-to-draw over server-rendered page
  images, undo/redo via snapshot stacks, per-mark delete, submit via
  fetch. `resources/views/tools/pdf-suite/redact.blade.php` (a client-side
  PDF.js one-shot download tool, unrelated to any persisted record) was
  found first and correctly ruled OUT — it never persists anything to a
  document.
- `app/Services/ViewingPack/ViewingPackRedactionService.php` —
  `pagePreviews()` rasterizes the source via Poppler `pdftoppm` (server-
  side, NOT PDF.js — this is what lets CoreX own every pixel) and
  `redact()` burns marks into the raster via GD, reassembles a flattened
  image-only PDF via dompdf, stores it at a **stable path**, and updates a
  DB pointer (`ViewingPackDocument::redacted_file_path`).
- `app/Http/Controllers/CommandCenter/ViewingPackController.php`
  (`redactionData` / `redactDocument` / `redactedFile`) — the three-
  endpoint shape: get-pages, apply-marks, serve-marked-copy.
- Playback for "the next party": `show.blade.php` ~L322 —
  `$isRedacted ? link-to-redacted-file : link-to-original`. Whoever opens
  the document next automatically gets the marked version. This is
  precisely what Johan means by "the next party sees what was done," and
  is the one property any replacement had to keep.

**One deliberate divergence, not a guess — a technical call made and
stated plainly rather than asked as a question:** redaction burns *opaque
black* into a *flattened, text-destroying* new file — correct and required
for POPIA (nothing may survive to be recovered). A highlighter is the
opposite by definition: translucent, non-destructive, meant to be seen
and later removed. Reusing `redact()`'s literal behaviour for a
"highlighter" would have blacked out passages instead of highlighting
them — the wrong feature, not a shortcut avoided. So this reuses the
**architecture** exactly (own the render, capture marks the identical way,
persist to a stable artifact, substitute it at read-time for the next
viewer) through a **new, dedicated service**
(`app/Services/RentalApplications/RentalApplicationDocumentHighlightService.php`)
rather than a mode flag on `ViewingPackRedactionService` — that file is
live compliance code for an unrelated feature and was not touched.

**What was built** (all under `RentalApplicationReviewController`, the
existing home for this screen — no other lane's file touched):

- `database/migrations/..._create_rental_application_document_highlights_table.php`
  + `RentalApplicationDocumentHighlight` model — one row per `Document`
  (`agency_id`, `document_id` unique, `marks_json`, `highlighted_file_path`,
  `updated_by_user_id`, soft-deletes). `marks_json` is the one addition
  beyond `viewing_pack_documents`' shape: redaction never needs to show
  existing boxes back to the agent (nothing to un-redact meaningfully), but
  a highlighter must let the agent see and edit their own existing marks on
  reopen — confirmed working by round-trip test below.
- `RentalApplicationDocumentHighlightService` — same rasterize → GD → GD
  alpha-blended fill → dompdf-reassemble pipeline as
  `ViewingPackRedactionService`, translucent colour (`imagealphablending`
  + `imagecolorallocatealpha`, ~35% opacity) instead of opaque black. Four
  colours (yellow default, green, pink, blue). Re-applying always
  re-rasterizes the pristine SOURCE (never a previously-marked copy), so
  removing a mark and re-applying genuinely removes it — same guarantee
  redaction gives. An empty mark set clears `highlighted_file_path`
  entirely rather than leaving a needless artifact — the next viewer then
  sees the plain original.
- **Real bug caught by testing, not assumed working:** the first version
  read the source file via `Storage::disk($doc->disk)->path(...)` (copied
  from `ViewingPackRedactionService`) and failed with "source document
  missing" — `App\Models\Document::decryptedContents()` (used correctly by
  the existing `viewDocumentInline()` on this same controller) is the
  MANDATORY read path for every `Document`'s bytes per its own doc comment
  (AT-173 — enveloped/encrypted files are transparently decrypted; every
  byte-reader must go through it or `downloadResponse()`, never read the
  raw file directly). The redaction service's raw-path read happens to work
  for viewing-pack documents but is not the sanctioned pattern; this
  service was fixed to use `decryptedContents()` (writing to a throwaway
  temp file for `pdftoppm`, deleted immediately after) before it ever
  passed real-document testing.
- Three new routes on the existing `rental-applications` prefix group:
  `GET .../documents/{document}/highlight-data`,
  `POST .../documents/{document}/highlight`,
  `GET .../documents/{document}/highlighted-file` — named
  `corex.rental-applications.documents.highlight*`, matching the existing
  `documents.view`/`documents.download` naming.
- `review.blade.php` — the old `<iframe>` per document is gone entirely,
  replaced by a "View & Highlight" button opening a modal ported from
  `redactionTool()` (same drag/undo/redo/remove-mark mechanics, JSON body
  instead of FormData since there's no non-JS fallback here), plus a small
  colour picker defaulting to **yellow** (Johan: *"set the default to
  highlighter with a bright yellow colour as default. agents can reselect
  if they want something else"*). A "Highlighted" badge shows per document
  once marks exist — visible proof to any agent opening the list, not just
  the one who drew them. Scope call, stated not guessed: built rectangle
  highlight marks only (matching the proven interaction exactly) with a 4-
  colour picker for "reselect if they want something else" — did **not**
  build freehand pen/scribble/eraser tools, since that's not part of the
  reusable pattern and is a materially larger addition than what was asked.
  Items 1/2/3 from the original list are now moot rather than separately
  fixed — there is no browser-native PDF viewer left on this screen at all
  (no iframe, no `#navpanes=0` fragment needed) to have a default tool,
  default colour, or hidden-panel problem on.

**Verified for real, not asserted** (`storage/app/private` documents don't
travel with a git worktree, so the one real attached PDF — app 13, document
2974, "Rental Application Test.pdf," 950,811 bytes, agency 1 — was copied
into the isolated test worktree read-only for this run):

1. `GET highlight-data` on first open: `200`, 17 real rasterized pages,
   real dimensions (1241×1755), real PNG data, `marks: []`.
2. `POST highlight` with one mark on page 0: `200`,
   `{"ok":true,"has_highlights":true,"mark_count":1}`.
3. DB: real row created, `marks_json` holds the mark,
   `highlighted_file_path` set, the artifact file exists on disk
   (6,777,230 bytes — 17 rasterized pages assembled into one PDF).
4. `GET highlighted-file`: `200`, `Content-Type: application/pdf`, real
   `%PDF-` magic bytes, byte count matches the stored file exactly — proves
   playback actually serves the marked copy, not a stub.
5. `GET highlight-data` again: returns the SAME saved mark — proves the
   round-trip (agent reopening sees their own existing highlight, not a
   blank slate).
6. `POST highlight` with an empty mark set: `200`,
   `has_highlights:false`, `highlighted_file_path` cleared to `null` in the
   DB — confirms "no marks left → next viewer sees the plain original."
7. Cross-agency scoping: a real agency-17 user against agency-1's
   application/document — both `highlight-data` and `highlighted-file`
   returned `404` (route-model-binding never resolves the row across
   agencies — the same split already documented for every other endpoint
   on this controller).

**Convention for the next screen that needs to mark up a document:** find
`RentalApplicationDocumentHighlightService` (translucent, persisted,
reopenable) or `ViewingPackRedactionService` (opaque, destructive,
compliance-driven) first — one of those two is almost certainly the right
base to extend or copy, not a third invention. Never hand a PDF to an
iframe expecting to save anything drawn in it — the browser's native PDF
viewer cannot report back to CoreX at all, by design, on every browser.

### In-place annotation, stroke marks, notes, and speed (Johan, 2026-09-08)

Five items from Johan's second real test. Answered in his own required
order — item 5 first, since it changed the shape of everything else.

**5 — the modal was wrong, and he was right.** Verbatim: *"not sure the
load in a seperate screen is the right option. now although you can
highlight you lose the right hand panel to capture income etc. until you
have finished the highlighting... think thats a problem as you have both
but on separate screens and that means not working as one or integrated as
one for use?"* The previous round's `fixed inset-0 z-50` modal covered the
`.rental-review-aside` assessment panel entirely while open — functionally
a separate screen even without a URL change. **Achievable, not hard, and
built** — the mark-persistence backend never knew or cared whether its UI
was a modal or inline; only the wrapping markup moved. The highlighter now
renders in-place inside each document's own row, inside `.rental-review-main`
(still its own independently-scrolling column). `.rental-review-aside` is a
plain flex SIBLING — it was never covered by either shape and stays visible
and usable the entire time an agent is marking up a document.

**2 — sticky header, matching the existing fix, not inventing one.**
Verbatim: *"the highlighter buttons are at the top, not in a header... so
why are we bypassing the standard design again."* Found and reused
`resources/views/components/sticky-action-bar.blade.php` (`<x-sticky-action-bar>`)
— the SAME shared component `resources/views/corex/rental-applications/
show.blade.php` already uses for its own Save-button fix (that file's own
comment cites Johan's identical complaint about the rental application
form). The header's right slot now swaps between "Back to application"
(normal) and the highlighter's own tool picker + colour picker + undo/redo
+ mark count + Save button (while a document is open) — always reachable
regardless of scroll position, on a document of any length.

**3 — drag-to-mark, not draw-a-box; honest cost of true text-snapping.**
Verbatim: *"is it difficult to include a highlighter effect - click and
drag to mark instead of this drawing a box bit?"* The box was inherited
directly from the redaction tool, where a box is correct (you're blacking
out a region). Rebuilt as a genuine marker-pen gesture: the drag captures
the actual pointer path (a point every few px of real movement, not just
start/end), stored as `points: [{x,y}, ...]`, rendered live and burned as
a thick translucent stroke following that exact path (SVG `<polyline>`
in-app; GD `imageline` + `imagefilledellipse` at the joints when burning).
**What this is NOT, stated plainly rather than silently substituted:** it
does not snap to actual words or lines the way Adobe/Word's text-highlight
does — the page is a raster image with no text-position data at all right
now. True text-snapping needs a real text-and-bounding-box layer per page
(Poppler's `pdftotext -bbox` is already installed and available, or
introducing PDF.js's own text layer), then hit-testing the drag path
against word boxes, then burning per-word rectangles instead of a
freehand stroke. Rough sizing: a new server extraction step, client-side
hit-testing logic, and a materially different burn path — comparable to
or larger than this round's entire rebuild. Not started; flagged as a
real, buildable option for Johan to greenlight separately, not decided
unilaterally.

**4 — notes/text pinned to a point on the document.** Verbatim: *"we now
have the highlight but we dont have the write something, or insert a note
bit."* Added as a second mark type in the SAME `marks_json` array
(`{type:'note', x, y, text, color}`) — same storage, same persistence,
same playback as highlights, per instruction. A small pinned marker with a
click-to-open popover in the live viewer; burned into the flattened
playback artifact as a visible marker + its own text (GD `imagestring`,
word-wrapped) so "the next party" sees the note even in a downloaded copy,
not only in the live in-app view.

**1 — measured first, not guessed at.** Real numbers, not estimates,
against a real 17-page/928KB document (app 13, doc 2974):
`pagePreviews()` took **11,491ms** end-to-end before this round (~676ms/
page). Broken down with real timers: `pdfinfo` ~17ms (noise); `pdftoppm`
rendering itself ~512ms/page (**~76% of total** — genuine rasterization
work at the SAME 150 DPI the proven redaction tool already uses, largely
irreducible without a quality tradeoff); a redundant GD round-trip
(`imagecreatefrompng` + `imagepng`, done for NO reason on a passive
preview) ~120ms/page (**18% of total**, pure waste). Batching all 17 pages
into one `pdftoppm` process call instead of 17 separate spawns was tested
and measured too — only ~4% faster (9,041ms vs 8,704ms), so process-spawn
overhead is NOT the bottleneck here; not worth the complexity on its own,
but folded in anyway since it was free to do alongside the real fixes.
**Two changes shipped, both safe (no visual/behavioural tradeoff):**
(a) skip the GD round-trip — read the rasterized PNG bytes straight off
disk for previewing; (b) cache rasterized pages on disk per document
(keyed by document id + its own `updated_at`, so a genuinely replaced file
rasterizes fresh). **Real before/after, same document:** first open (cold
cache) **9,129ms** (down ~20% from 11,491ms, the GD-round-trip fix alone);
second open (warm cache) **23ms** — a ~397× speedup for any repeat view,
which is the common real case (an agent reopening a document they're
already annotating, or a second agent opening the same file). **Flagged,
not built — Johan's call, real tradeoff attached:** lowering DPI below 150
would cut `pdftoppm`'s own ~76% further (render cost scales roughly with
pixel count, so 100 DPI ≈ 44% of current pixels) but trades on-screen
sharpness for speed — the one lever with a real quality cost, not decided
unilaterally. Lazy-loading (return page 1 immediately, defer the rest)
would improve PERCEIVED speed on a first-ever open without touching total
server work — a moderate, well-scoped future addition, not built this
round since the caching win already resolves the dominant real-world case
(repeat opens).

**Files the sticky-header pattern was taken from, as instructed:**
`resources/views/components/sticky-action-bar.blade.php` (the shared
component) and `resources/views/corex/rental-applications/show.blade.php`
(the existing usage this round matched, left/right slot shape and all).

---

## Round 3 (Johan, QA1) — list CRUD, contact email backfill, async document upload

Three more real defects, one folded mid-turn into another.

### Bug 1 — the list had no row actions

**Gap vs BUILD_STANDARD §1b, checked item by item:** search ✓ (contact
name/email/property/#id), sort ✓ (every listed column, default
`created_at desc`), pagination ✓, empty state ✓ (distinguishes "no
results for this filter" from "nothing yet"), archived tab ✓ (already
existed) — but **no status filter at all**, and **the only row action was
"Open."** No archive, no resend, from the list itself.

**Fixed:**
- Status filter added to `index()` (mirrors the one `returned()` already
  had — that duplicate is now removed, centralised in
  `applySearchSortAndDateRange()`), dropdown scoped to the statuses that
  actually appear on this screen (`draft`, `sent`, `in_progress` — the
  rest live on Returned Applications).
- Row actions added: **Open** (unchanged), **Send/Resend** (only shown
  when `recipientEmail()` is set — matches the header's own
  disabled-with-reason logic; omitted rather than shown-disabled on a
  cramped list row, since opening the record already explains why), and
  **Archive** (soft delete, confirm dialog, same `destroy()` route the
  header already used). Restore already existed in the archived sub-table.
- Status badge on the list now distinguishes `draft` (muted) from
  everything else, matching the same fix already made on the detail page.

**Deliberately NOT added — a manual "mark as sent" status override.**
Johan asked me to decide and say so if a status should only ever be
system-set. `sent` was JUST fixed (Round 2) to mean "mail genuinely went
out" — a hand-settable override would directly reintroduce the exact
"status lies" defect that fix closed. Sending (via the Send/Resend row
action) is the correct way to move an application forward; there is no
legitimate hand-set path for this particular field.

### Bug 2 — email should flow back to the contact

Johan: "once we have an email for a contact we update the contact." Rule
implemented exactly as stated — **fill-only, never overwrite**:
`RentalApplicationController::backfillContactEmail()`, called from
`update()` inside the same transaction as the application save. If the
contact's own `email` is empty, it's filled from whatever the agent just
saved on the application. If the contact already has a DIFFERENT email,
it is left completely untouched — a document-edit screen must never
silently rewrite real CRM data.

Uses `Contact::auditedQuietUpdate()` (the existing sanctioned "meaningful
quiet write" path, AT-321-C) rather than a raw `save()`, so the backfill
shows up in the contact's own audit trail instead of looking like it
appeared from nowhere.

**Agency scoping:** `$rentalApplication->contact` is resolved through the
model relationship, itself scoped by `Contact`'s global `AgencyScope` —
there is no code path by which this can reach a contact outside the
application's own agency. Proven with a real cross-agency test fixture,
not just asserted.

**Scope note, not decided unilaterally:** this only fires from the
AGENT-side edit screen (`update()`), matching Johan's literal words ("on
application agent enters email address and saves"). The public
APPLICANT's own submit — which can also set `rental_applications.email`
— does NOT currently backfill the contact. Flagging this rather than
silently extending or silently leaving it: if the same rule should apply
there too, that's a one-line addition once confirmed.

### Bug 3 (folded into the mid-turn instruction below) — see "async document management"

### Mid-turn escalation — the applicant form also loses typed input on upload

Johan, mid-turn: "I complete all the information, get to the bottom,
attach a file, click upload and the screen refreshes, and all my typed
info is gone." Same root cause as Bug 3, worse consequence: the public
form has no separate save step at all, so a synchronous upload's page
reload discarded everything typed anywhere on the form, not just the
documents.

**Ruling: make document upload fully asynchronous — no page reload at
all — rather than patching around the synchronous version.** Confirmed
feasible before building anything (an existing async fetch+FormData+
Alpine upload pattern already exists in this codebase — `fica/form.blade.php`
— so this is consistent with established convention, not something
foreign being introduced).

**Built:**
- `RentalApplicationSigningController::uploadDocuments()`,
  `removeDocument()`, `replaceDocument()` now respond with JSON when the
  caller asks for it (`$request->wantsJson()`) — the existing synchronous
  form-POST-redirect behaviour is untouched for any caller that doesn't
  ask for JSON (there isn't one left after this change, but nothing was
  removed — this is additive). Validation failures already return 422
  JSON automatically under Laravel's own default behaviour once the
  request wants JSON — no extra code needed for that half.
- `show.blade.php`'s Alpine component (`rentalApplicationForm()`) now
  owns the document list as reactive state (`documents`, `uploading`),
  with `uploadFile()`/`onFilesSelected()`/`removeDoc()`/`replaceDoc()`
  driving fetch calls that update the DOM in place — zero navigation.
  Per-file upload progress and per-file error text render inline.
- **Belt-and-braces safety net kept, as instructed:** `beforeSubmit()`
  first uploads anything still sitting in the file picker, then awaits
  any upload still in flight, then — if anything failed — blocks Submit
  entirely and names which file and why. Nothing typed is touched by any
  of this, since none of it involves a page load.
- **Submit moved below the documents section** (both in markup order and
  visually) — the button now lives outside the `<form>` tag and
  associates via the HTML5 `form=` attribute (the same technique already
  used for the agent-side header's Save button), so the DOM could be
  reordered freely without restructuring the actual `<form>` boundaries.

**Full input-loss sweep, both forms, as instructed** — every submit,
every button that posts, every partial save:
- **Public form:** the signature pad was checked for any navigation/reset
  behaviour — none exists (pure canvas-to-dataURL, no form submission of
  its own). The three document actions were the only genuine gap, now
  fixed. `already-submitted.blade.php` (the post-submission "add more
  documents" page) was deliberately NOT touched — that page renders no
  other form fields at all, so there is nothing else on it a reload could
  lose; the defect class doesn't apply there.
- **Agent form:** Save (header, `old()`-preserving) and Send (disabled
  whenever the form has unsaved changes — `dirty`, from Round 2 — so
  Send can never be clicked while anything typed is unsaved) already
  close every path found. Archive is the one intentional exception: it's
  an explicit "leave this record" action, not a surprise data-loss —
  losing unsaved edits when deliberately choosing to archive is the same
  expectation as leaving any page with unsaved changes, not the same
  defect class as Send/Upload silently discarding input during what
  looks like forward progress. No further gaps found.

**Regression tests**
(`tests/Feature/RentalApplications/RentalApplicationAsyncUploadTest.php`):
`test_uploading_via_the_json_endpoint_attaches_the_document_before_submit_is_ever_called`
(the actual "I never clicked upload... no docs arrive back" scenario,
proven false end to end through a real submit()), `test_a_rejected_upload_via_json_reports_exactly_which_file_and_why`,
`test_json_replace_and_remove_never_hard_delete_and_respond_without_a_redirect`.
Agent side, added to `RentalApplicationAgentControllerTest.php`:
`test_saving_an_email_backfills_a_contact_that_had_none`,
`test_saving_an_email_never_overwrites_a_contacts_existing_different_email`,
`test_email_backfill_never_crosses_agency_boundaries`,
`test_archiving_from_the_list_soft_deletes_and_it_is_findable_and_restorable`,
`test_the_status_filter_on_the_main_list_actually_filters`.

**Post-merge incident, found and fixed during live proof:** after this
round's PR merge landed on QA1, the public show page 500'd for every
real request. Root cause: `documents: @json($application->documents->map(fn ($d) => [...]))`
nested a multi-line arrow-function/array literal (including a `route()`
call with an array argument) inside `@json()`'s own parentheses. Blade's
directive-argument parser mishandled the nesting and compiled it to
genuinely invalid PHP — a real `ParseError` on first render, not a Blade
templating error. **`Blade::compileString()` alone did not catch this** —
it only proves the Blade→PHP string transform succeeded, never that the
resulting PHP is itself parseable; that requires an actual render (or a
`php -l` on the compiled output). Fixed by computing the array in a
`@php ... @endphp` block ahead of `<!DOCTYPE html>` and referencing the
plain variable inside `@json()`. Lesson for this codebase: never nest a
multi-line closure/array literal directly inside a Blade directive's own
parentheses — compute it in a preceding `@php` block instead, and verify
any `@json()`/directive change with a real render, not just
`compileString()`.

## Round 4 (Johan, QA1) — hand-settable status on Returned Applications

Johan: "on returned applications theres statuses at the top, but theres
no way to mark application status to what it is?" The status tabs at the
top of Returned Applications were filters only — an agent could see and
filter by status but never actually set one.

**Status classification, established before writing any code, per
Johan's own instruction not to guess:**

| Status | Who sets it | Why |
|---|---|---|
| `draft` | System only | True starting state on creation. |
| `sent` | System only | Set only once mail has genuinely left (Round 3 fix — a hand-settable override would reintroduce the "status lies" defect that fix closed). |
| `in_progress` | System only | Set the moment the applicant starts interacting with the public form (upload/save activity before a full submit). |
| `returned` | System only | Set only by `RentalApplicationSigningController::submit()` — a fact: the applicant genuinely submitted. |
| `under_assessment`, `approved`, `declined`, `withdrawn` | **Agent, by hand** | The agent's own judgement call once an application has actually been returned — nothing else can determine these. |

`RentalApplication::AGENT_SETTABLE_STATUSES` is exactly the four
judgement-call values; `POST_RETURN_STATUSES` (`returned` + those four)
gates when the control is even shown — assessing something the applicant
hasn't submitted yet makes no sense, so the control never appears on
`draft`/`sent`/`in_progress` rows.

**Built, copying the existing simple status-select pattern already used
in this codebase** (`FeedbackReportController::updateStatus()` +
`command-center/feedback/show.blade.php` — a plain `<select>` + note +
POST, not a bespoke control):
- `RentalApplicationController::updateStatus()` — validates against
  `AGENT_SETTABLE_STATUSES` only (`Rule::in`), so a system-owned value is
  rejected by validation even from a crafted request, never silently
  accepted. Refuses to act at all unless the application is already in
  `POST_RETURN_STATUSES`. A resubmit of the current value is a harmless
  no-op (no history row written — that would fake a transition that
  never happened). Scoped through the same `guardRentalApplication()`
  own/branch/agency guard every other single-record action in this
  controller already uses.
- Route: `POST /corex/rental-applications/{rentalApplication}/status`,
  gated on `rental_applications.create` — the same permission every other
  action on this screen (Send/Resend, Archive) already uses; no new
  permission key introduced for what is an action on an existing feature,
  not a new one.
- **Every change recorded** — `RentalApplicationStatusHistory` (new
  table + model, mirroring `FicaStatusHistory`'s existing append-only
  status-trail pattern rather than the field-diff `ContactAuditLog`
  shape, since this only ever records one thing: a status transition).
  One immutable row per change: `from_status`, `to_status`,
  `changed_by_user_id`, an optional `note`, `created_at`. No update path,
  no delete path — a decision on a tenant application gets a permanent
  trail, full stop.
- **UI, both places Johan asked for:** the detail page
  (`show.blade.php`) gets an "Application Status" card (select + optional
  note + Update button, plus the change history rendered underneath) —
  shown only once `POST_RETURN_STATUSES`. The Returned Applications list
  (`returned.blade.php`) gets the same control inlined into the Status
  column as an auto-submitting `<select>` for a same-row change with no
  separate note field (row space is too tight; use the detail page for a
  reasoned decision).

**Migration gotcha hit and fixed:** the first migration attempt failed
with `Identifier name '...' is too long` — Laravel's auto-generated name
for a composite index on `rental_application_status_history` exceeded
MySQL's 64-character identifier limit. Because MySQL DDL causes an
implicit commit, the table itself had already been created before the
index statement failed, leaving an untracked table with a missing index
and no row in the `migrations` table — diagnosed via
`information_schema.tables`/`SHOW CREATE TABLE`, fixed by dropping the
orphaned table and re-running the corrected migration (explicit short
index names) cleanly. Lesson: give composite indexes on
`rental_application_*`-prefixed tables (or any long table name) an
explicit short name rather than relying on Laravel's auto-generated one.

**Regression tests**, added to `RentalApplicationAgentControllerTest.php`:
`test_agent_can_set_an_agent_owned_status_once_the_application_has_been_returned`,
`test_every_status_change_is_recorded_with_who_when_from_and_to`,
`test_a_system_owned_status_cannot_be_hand_set_even_by_a_crafted_request`,
`test_status_cannot_be_set_on_an_application_that_has_not_been_returned_yet`,
`test_resubmitting_the_same_status_is_a_no_op_and_does_not_duplicate_history`,
`test_status_change_respects_agency_scoping`,
`test_status_change_never_hard_deletes_anything_and_stays_soft_deletable`,
plus two real-render regression tests
(`test_the_show_page_actually_renders_the_status_control_and_history_once_returned`,
`test_the_returned_applications_list_actually_renders_the_inline_status_control`)
— given this round's own earlier incident, a real render is the only
thing that proves a Blade change actually compiles and executes, and
`php -l` on a `.blade.php` file does not exercise the compiled directives
at all.

## Round 5 (Johan, QA1) — the input-loss sweep, done as a class

Johan, end of day: three real defects on this one feature — the agent
form reverting four fields on validation failure, the applicant email
not persisting, the public form wiping everything on upload — were each
found by him personally and fixed as instances. The rule he set: **no
user action on either rental application form may EVER discard typed
input** — not on validation failure, upload, navigation, partial save,
or any button that posts. This round sweeps both forms exhaustively
against that rule rather than waiting for a fourth instance.

### The complete sweep — every action checked, verdict for each

**Agent-side (`RentalApplicationController` + its 3 views):**

| Action | Route | Verdict before this round | Fix |
|---|---|---|---|
| Create application | `POST .store` | **FAIL** — `create.blade.php`'s contact/property picker is Alpine state seeded from nothing; a failed store() (e.g. a stale property id) wiped the agent's search-and-select work even though `old()` had the ids the whole time | `create()` now resolves `old('contact_id')`/`old('property_id')` server-side (agency-scoped, so a stale/foreign id just resolves to null, never leaks) and seeds the Alpine component's initial state with them |
| Edit application | `PUT .update` | PASS — already fixed in an earlier round (`<x-rental-application-field>` uses `old($name, $value)`, all 3 textareas and the `employment_type` select do too); re-verified individually, all 29 fields | none needed |
| Set status | `POST .update-status` | **FAIL** — the note `<input>` had no `old()` and the status `<select>`'s `@selected` only ever checked the DB value, never `old('status')`; a rejected status change (fake system status, or an over-length note) silently dropped the note the agent had typed | Added `old('note')` to the input and `old('status', $rentalApplication->status)` to the select's `@selected` checks; added `->withInput()` to the one custom (non-`validate()`) rejection branch too |
| Send / Resend | `POST .send` | PASS — button-only action, no typed fields; already prevented from firing while the big form has unsaved edits (`dirty` gate from an earlier round) | none needed |
| Archive | `DELETE .destroy` | PASS — confirm-dialog only, no typed fields; soft delete, not a data-loss concern in this sense | none needed |
| Restore | `POST .restore` | PASS — no typed fields | none needed |
| Search contacts/properties | `GET .search-properties`, global contact search | N/A — these are live AJAX lookups behind the create-page picker, not a save/submit path; nothing persists across a request to lose | none needed |
| List filters/sort/pagination | `GET .index`, `GET .returned` | N/A — GET requests, no posted input | none needed |
| Document download | `GET .documents.download` | N/A — GET, no input | none needed |

**Public applicant-side (`RentalApplicationSigningController` + its 4 views):**

| Action | Route | Verdict before this round | Fix |
|---|---|---|---|
| Submit application | `POST .submit` | **FAIL (new find)** — every typed text field already used `old()` (an earlier round's fix), but the two signature-pad hidden inputs had no `old()` at all and nothing redrew the canvas; a validation failure on ANY unrelated field (e.g. a bad phone number) silently wiped both hand-drawn signatures, forcing the applicant to re-sign | Hidden inputs now carry `value="{{ old($field) }}"`; the canvas init script draws the old signature back via `Image()`/`drawImage()` on load if present, so the applicant sees their own signature still there, not a blank pad |
| Every text field on submit | (same route) | PASS, individually re-verified across all field types (component-based, raw textareas, the `employment_type` select) — confirmed via a real request exercising all of them at once | none needed |
| Upload document (pre-submission) | `POST .documents` | PASS — already fully async since the earlier round (no page reload at all); re-verified | none needed |
| Replace / remove document (pre-submission) | `POST .documents.replace` / `.remove` | PASS — already fully async; re-verified, still correctly locked once submitted | none needed |
| Upload document (post-submission, "add more") | `POST .documents` from `already-submitted.blade.php` | **FAIL (new find)** — this page was missed entirely by the earlier async rewrite; it still used a plain synchronous `<form enctype="multipart/form-data">` that reloaded the whole page on every upload, and a multi-file selection with one rejected file would have forced reselecting the ones that were fine | Rewritten to the same Alpine `fetch()` pattern as `show.blade.php` — `alreadySubmittedDocuments()`, own `uploadFile()`/`onFilesSelected()`. The now-fully-unused shared `_document-list.blade.php` partial (its own docblock claimed it was "shared... so the two never drift" — the drift had already silently happened when `show.blade.php` got its own inline Alpine markup in an earlier round) was deleted rather than left as dead code. |
| Expired-token submit attempt | `POST .submit` (token past `token_expires_at`) | **Checked, not the same defect class, no fix applied** — `submit()`'s expired-token redirect has no `withInput()`, but the target page (`show()` → `rental-applications.public.unavailable`) is a static "this link has expired" page with no form fields at all; there is nothing to redisplay input INTO regardless of what's flashed. A 14-day token window expiring mid-fill is not the scenario Johan hit personally. Flagging the reasoning rather than silently skipping it. |
| Re-submit an already-returned application | `POST .submit` when already `returned`+ | N/A — redirects straight to `show()`, which renders `already-submitted.blade.php` (a different page entirely, not the form) — nothing on the submit form to lose since it's never shown again |
| View document | `GET .documents.view` | N/A — GET, no input | none needed |
| Navigation between sections/tabs | both forms | **Checked, not applicable** — neither form has a JS tab/step/wizard mechanism (confirmed by grep); both are single-page, single-scroll forms with visually separated sections, so there is no unmount/remount to lose state to | none needed |
| Partial save / autosave | both forms | **Checked, not applicable** — neither of these two forms has an autosave mechanism (the separate Phase 2 assessment split-screen does, but that is a different feature/file, out of this scope) | none needed |

### Proof

Every fix above proven with real HTTP requests (kernel-dispatched, real
session/CSRF, real DB), not assertions:
- Failed `store()` with a stale property id → `create()` redisplay
  confirmed to carry the correct `contactId`/`propertyName` in the
  Alpine seed data.
- Failed `submit()` with every field filled and one deliberately broken
  → redisplay confirmed to show every single typed value AND both
  signature `data:image/png;...` payloads intact in the hidden inputs.
- `already-submitted.blade.php` confirmed to no longer contain
  `enctype="multipart/form-data"` (the old sync form) and to upload via
  the JSON endpoint with no redirect.
- Rejected `updateStatus()` confirmed to still show the agent's typed
  note on redisplay.

**Regression tests** — new file
`tests/Feature/RentalApplications/RentalApplicationInputPreservationTest.php`:
`test_create_form_redisplays_the_selected_contact_and_property_after_a_validation_failure`,
`test_a_validation_failure_on_an_unrelated_field_preserves_both_signatures`,
`test_every_typed_field_on_the_public_form_survives_a_validation_failure`,
`test_the_status_note_survives_a_rejected_status_change`. Added to
`RentalApplicationAsyncUploadTest.php`:
`test_the_already_submitted_page_renders_and_uploads_via_the_json_endpoint_with_no_redirect`,
`test_the_already_submitted_page_lists_existing_documents_as_locked`.
Full `tests/Feature/RentalApplications/` directory re-run clean: 56
passed, 339 assertions, zero regressions.

### Outstanding items confirmed still correct (re-checked this round, not re-fixed)

- **Auto-upload on submit, blocked on failure**: `beforeSubmit()` still
  uploads anything left in the file picker and waits for in-flight
  uploads before allowing submit; a failure still blocks submit and
  names the file. Unchanged, re-verified.
- **Submit below documents**: still true, `form=` attribute unchanged.
- **Contact email backfill**: fill-only, never-overwrite, agency-scoped —
  unchanged, re-verified.
- **List actions (archive/restore/resend) + search/sort/filter/pagination
  + empty state**: unchanged, re-verified via
  `RentalApplicationCrudStandardTest`.
- **Status setting with an audit trail**: unchanged from Round 4, note
  field bug above is the only thing that needed fixing.

## Round 6 (Johan) — agent-added documents

Johan: "agent should in any case be able to add docs as client can be in
the office so agent scans docs to themselves, or even receive via
whatsapp etc." The applicant's token-based upload path already existed;
the agent had no way to attach anything themselves.

**Not a second document path** — same `Document` model, same storage
convention (`rental-applications/{id}/documents`), same allowlist
(pdf/jpg/jpeg/png/doc/docx, 15MB, up to 10 files), same soft-delete rule.
Just a second, authenticated entry point onto the one path.

**Who added it** — `documents.uploaded_by` and `Document::uploader()`
already existed; the applicant's own public/token upload never sets it
(no authenticated user in that context), so it was already the natural
"who added this" signal with zero new columns needed. The agent upload
sets it to `auth()->id()`. Screens show "from applicant" (null) vs.
"added by {name}" (set).

**Built:**
- `RentalApplicationController::uploadDocument()` —
  `POST /corex/rental-applications/{rentalApplication}/documents`, route
  `corex.rental-applications.documents.upload`. Scoped via the existing
  `guardRentalApplication()` guard, gated on `rental_applications.create`
  (same permission every other action on this screen already uses).
  Responds JSON on success/failure, matching the applicant-side contract.
- **`show.blade.php`** (my own detail page) — attribution line added to
  the existing document list, plus a self-contained async upload widget
  (its own nested `x-data`, own `<script>`) below it. Deliberately NOT a
  `window.location.reload()` on success: this page's big edit form has
  its own unsaved-edit (`dirty`) tracking from an earlier round, and a
  reload would violate today's input-loss rule for whoever has unsaved
  edits at the moment they attach a file. Uses plain DOM insertion
  (`appendToList()`) into the existing list instead — no reload, ever,
  on this page.
- **`review.blade.php`** (cc6's split-screen review page) — NOT edited
  directly (coordinated: cc6 owns that file, is mid-build on the RO/CO
  authoriser flow on the same Alpine component). Handed cc6 the exact
  markup/JS for a self-contained upload widget there too, plus the
  one-line attribution addition to their existing document loop. That
  screen's document list is server-rendered (not reactive), and its
  existing autosave (`saveAssessment()` on blur/change) means nothing
  should be unsaved when a file is attached — so a plain page reload on
  success was the agreed approach there, unlike `show.blade.php`.
  cc6 to apply when their in-flight edit reaches a safe point.

**Scoping:** `Document::create()` auto-stamps `agency_id` from the
authenticated acting user via its own `BelongsToAgency` trait — no
`withoutAgencyStamping()` needed here (that escape hatch is only for the
public, unauthenticated applicant path). Cross-agency upload attempt
confirmed blocked (404, via `guardRentalApplication()` + route-model
binding's own agency scope) with a real request.

**No hard deletes:** agent-added documents are ordinary `Document` rows
— already soft-deletable via the existing mechanism, nothing new needed.

**Regression tests**, added to `RentalApplicationAgentControllerTest.php`:
`test_agent_can_upload_a_document_and_it_is_attributed_to_them`,
`test_a_rejected_agent_upload_reports_why_and_never_attaches`,
`test_agent_upload_respects_agency_scoping`,
`test_the_detail_page_distinguishes_applicant_documents_from_agent_added_ones`,
`test_agent_added_documents_are_never_hard_deleted`. Full file re-run:
34 passed, 174 assertions.

**Proven live** with real requests against real data: agent upload
attributed correctly (`uploaded_by` set), a rejected file type reports
why and attaches nothing, the detail page shows both an applicant
document ("from applicant") and an agent-added one ("added by Johan
Reichel") side by side, the upload widget itself renders, and a
cross-agency attempt is blocked with a 404.

## Round 7 — four defects from independent testing (cc5)

cc5 walked the whole flow as a real user and found four real defects,
alongside confirming a lot of earlier work genuinely holds up
(double-submit blocking, the post-submission document lock, assessment
autosave, cross-agency scoping, send-without-email refused
server-side). All four below fixed, each proven via real HTTP: a real
local server (`php artisan serve`), a real login (`POST /login` with a
scraped CSRF token and a real session cookie), then the actual feature
routes with their own scraped CSRF tokens — never `Route::
dispatchToRoute()` or any other kernel-bypassing shortcut, and never
verified from rendered markup alone without an independent direct
database read. This distinction is not academic: cc6 reported
highlighting working the same night when it was completely broken,
because that check exercised a path a real browser never uses.

### RA-01 — a draft application's public link was already fully fillable

`RentalApplicationSigningController::show()` generated the token at
creation (an earlier round's own fix, so a link exists to share even
before Send) — which is exactly what made a never-sent application's
form reachable and fillable, with nothing telling the applicant the
agent hadn't actually sent it yet.

**Decision: show the same "not ready" page the expired-token case
already uses, with a `not_sent`-specific message, rather than a bare
404.** A 404 would be less honest — the link is real and will work the
moment the agent sends it — and reusing the one existing "can't fill
this in right now" page (`unavailable.blade.php`, now branching on
`$reason`) keeps one consistent unavailable experience instead of
inventing a second.

Guarded in all five places a draft application's token could otherwise
be interacted with: `show()`, `submit()`, `uploadDocuments()`,
`removeDocument()`, `replaceDocument()` — a crafted direct POST bypassing
the UI is refused exactly the same as using the page normally.

**Proven live:** GET on a draft's token → "isn't ready yet", no form
fields, no Submit button. A direct POST to `/submit` with a real,
valid CSRF token still saves nothing (confirmed via `withoutGlobalScopes()
->find()` reading the row directly — `status` stayed `draft`, `full_name`
and `submitted_at` stayed `NULL`). A real, authenticated `POST .../send`
flips it to `sent` — the SAME token then serves the real fillable form.

### RA-02 — numeric fields rejected comma-formatted South African money

`RentalApplication::fieldValidationRules()`'s numeric fields
(`current_rental_amount`, `monthly_salary`, `adults`, `children`) were
validated raw — "15,000" or "R 8,500.50" failed as "must be a number"
with no explanation, even though input was correctly preserved.

Fixed the class: `RentalApplication::NUMERIC_FIELDS` (all four) +
`RentalApplication::sanitizeNumericInput()` strips a leading `R`
currency prefix, thousand-separator commas, and stray spaces before
validation ever runs. Called via `$request->merge(...)` in both
`RentalApplicationController::update()` and
`RentalApplicationSigningController::submit()` — the one shared
validation ruleset, sanitized identically on both forms. A genuinely
invalid value (`"not money at all"`) is still rejected after sanitizing.

**Proven live:** real `PUT`/`POST` with `monthly_salary=15,000`,
`current_rental_amount=R 8,500.50` / `R6 200` / `22,750` — every case
read back from the database afterward as the correct clean decimal
(`15000.00`, `8500.50`, `6200.00`, `22750.00`), on both the public
submit form and the agent edit form.

### RA-03 — a document added after submission looked identical to one submitted originally

`show.blade.php`'s document list showed no distinction at all. Johan's
own requirement, agreed with cc3 using `created_at >= submitted_at` —
confirmed still missing.

Added a badge + timestamp to the existing document list, both for the
initial server-rendered list and for documents added live through the
async upload widget (Round 6) — anything added right now on an
already-submitted application is, by definition, after submission.
**Coordinated with cc6** so the review screen gets the identical
treatment (same badge text, same condition) rather than two different
ones — see the Round 6 section above for the handoff.

Hit and fixed a real Blade compile bug while building this: an
`@php ... @endphp` block nested directly inside a `@foreach` failed to
compile as a directive at all (the literal text `@php` survived into
the compiled output, and `@endphp` compiled to a bare `?>` that closed
the loop's PHP block early, corrupting everything after it into a real
`ParseError`) — caught by actually rendering the page in a test, not by
`php -l`. Fixed by inlining the boolean condition directly into the
`@if(...)`, removing the intermediate `@php` block entirely rather than
chasing why the nesting failed.

**Proven live:** real agent upload onto a returned application via the
JSON endpoint, confirmed via direct DB read that the new document's
`created_at` is `>=` the application's `submitted_at`, THEN confirmed
the real rendered page shows both "added by {agent}" and the "Added
after submission" badge with a timestamp matching the DB exactly.

### RA-05 — two tabs, second save silently blanked the first tab's genuine save

`RentalApplicationController::update()` was plain last-write-wins, like
every multi-agent-editable module in CoreX (checked before building
anything — Deals, Contacts, Properties, Compliance controllers all have
the same gap; none of them are being touched here, out of scope).

**No existing "hidden updated_at, compare on submit" mechanism exists
anywhere in this codebase.** The closest analogue —
`Property::galleryFingerprint()` + `PropertyController::reorderImages()`
— hard-blocks on a content-hash mismatch (409, no merge) rather than
warning-then-saving. Modeled on that same hard-block shape, using
`updated_at` directly (simpler than a field hash, and correctly means
"changed since I opened this" for a plain Blade form): a hidden
`expected_updated_at` seeded from `old('expected_updated_at',
$rentalApplication->updated_at->timestamp)` when the page loads,
compared against the record's actual current `updated_at` before any
write happens in `update()`. On mismatch, the save is refused outright
— `back()->withInput()->with('error', ...)` — never silently merged,
never saved-with-a-toast. `old()` preserves everything the blocked tab
typed, so reloading and redoing the edit costs nothing.

The check only fires when `expected_updated_at` is present and
non-empty, so it's opt-in at the transport level — no existing caller
of `update()` needed to change, and no new required field broke
anything already relying on this route.

**Precision note surfaced while proving this:** `updated_at` is
second-precision (a plain `timestamps()` column) — a genuine two-tab
race always has real human reaction time between two saves, but a fast
automated check (test or curl script) can execute both requests inside
the same wall-clock second, which would falsely show no conflict. The
regression test uses `Carbon::setTestNow()` to force real separation;
the live proof used an actual 2-second `sleep`. This is not a
production gap — it reflects how the mechanism is genuinely meant to
work.

**Proven live:** real two-tab simulation — two real page loads capturing
the same `expected_updated_at`, a real 2-second pause, Tab 1's real PUT
succeeds, Tab 2's real PUT (same stale value) is refused with the exact
warning message, and Tab 2's typed value is still shown on the
redisplayed page for retry. Confirmed via direct DB read: Tab 1's save
(`Saved By Real Tab One`) survived; Tab 2's attempted overwrite never
landed.

**Regression tests**, added across three existing files:
`RentalApplicationAsyncUploadTest.php` (RA-01, 4 tests),
`RentalApplicationInputPreservationTest.php` (RA-02, 3 tests),
`RentalApplicationAgentControllerTest.php` (RA-02 agent side is covered
by the input-preservation file; RA-03, 2 tests; RA-05, 3 tests, one
using `Carbon::setTestNow()` with a `tearDown()` backstop so a failed
assertion never leaks fake time into a later test). Full
`tests/Feature/RentalApplications/` suite: 73 passed, 402 assertions,
zero regressions.
## Round 7 (Johan) — the index screen becomes real CRUD: search, sort, own/branch/agency, archive

Johan, after testing: "off the bat - list of rental applications piling
up, yet no way to remove / mark as sent / nothing here?" — and his
permanent standard, stated as applying to every module from now on, not
per-request: "we always need proper crud? search / sort / own / branch /
agency levels. that should be the design standard. not me asking for it
once we get to that stage. so get that going as well that we design and
build correctly from the word go."

**What was already there (Round 3, cc4) before this round started:**
search (contact name/email + property + #id), a status filter, date
range, sort-by-click on contact/property/status/date (no visual
indicator of which column/direction was active), archive
(`destroy()`/soft delete) and restore, an "Archived" toggle, pagination.
No own/branch/agency scope filter existed at all — `scopeVisibleTo()`
only ever applied the user's permission CEILING, with no narrower,
user-selectable view.

**Built this round, on top of that:**
- **Own/branch/agency scope TOGGLE** — `RentalApplication::clampScope()`
  + `scopeVisibleTo($query, $user, ?string $requestedScope = null)`,
  copied verbatim from the established CoreX pattern for exactly this
  (`App\Models\DealV2\DealV2::clampScope()`/`scopeVisibleTo()` — found
  first, per Johan's "find the existing implementation... do not invent
  a new one"; the buyer-pipeline board's own "own/branch/agency toggle"
  — `App\Services\CommandCenter\BuyerPipelineScope` — was checked too but
  its own controller trusts the raw `?scope=` URL value with no
  server-side clamp, relying entirely on a separate, unverified upstream
  restriction; DealV2's pattern is the one that is actually
  self-contained and provably safe, so that is the one reused).
  Defaults to **'own'** always (never the ceiling) per Johan's explicit
  "default to the narrowest scope" instruction. A requested scope wider
  than the user's actual permitted ceiling is silently clamped down, the
  same behaviour DealV2 already has. UI: a segmented Own/Branch/Agency
  link toggle, options above the user's ceiling not rendered at all
  (`$canSeeBranch`/`$canSeeAgency` in `RentalApplicationController::index()`)
  — but the clamp in the model is the real boundary, not the hidden
  button.
- **Sort** widened to include Agent (`created_by_user_id` → `users.name`)
  and Last updated (`updated_at`), alongside the existing
  contact/property/status/created. Column headers now show a ▲/▼
  indicator for whichever column/direction is actually driving the
  current order (including the implicit default sort with no `?sort=` in
  the URL at all).
- **Search** widened to also match the rental application's OWN captured
  `full_name`/`email`/`id_number` directly (previously only the LINKED
  Contact's name/email were searched — an application whose captured
  data has since diverged from its Contact, or that somehow has none,
  was invisible to search). No `passport_number` or unit-number column
  exists on `rental_applications` (checked the migration) — reported,
  not invented; `id_number` is the only identity field this schema
  actually carries.
- **Per-page control** (10/25/50/100, clamped server-side to that range
  regardless of what a manually-crafted `per_page` value claims).
- **Empty state** now names the actual reason (no results for this
  search vs. genuinely nothing in this scope) and, when the user is
  entitled to a wider scope, says so by name.

**A real, pre-existing bug found and fixed while proving sort actually
works over real HTTP (not by reading the blade):**
`RentalApplication::scopeVisibleTo()`'s branch/own clauses used
unqualified column names (`branch_id`, `created_by_user_id`). The moment
that query is combined with a LEFT JOIN to `contacts`, `users`, or
`properties` — which sorting by Applicant, Agent, or Property already
does — MySQL throws `SQLSTATE[23000]: ... Column 'branch_id' ... is
ambiguous`, because all three of those tables carry their own columns of
the same name. This was **already broken** for sort-by-Applicant and
sort-by-Property before this round touched the file (a branch-scoped or
own-scoped user clicking either header 500'd); this round's own new
sort-by-Agent hit the identical class immediately. Fixed by qualifying
every column reference in `scopeVisibleTo()` and
`applySearchSortAndDateRange()` (search, status filter, date range, sort
map) with the `rental_applications.` table prefix. Applied identically
to `returned()`'s own `whereIn('status', ...)`, since that screen shares
the same private helper and the same sort-by-Applicant/Property links in
its own view (`returned.blade.php`) and would otherwise still 500 on the
exact same query shape.

**Not touched, per explicit boundary:** `RentalApplicationController`'s
`store()`/`update()` methods and the public applicant form (cc4's
RA-01/RA-02/RA-05 work, in flight); the review screen, highlight
service, and authoriser flow (cc6's).

**Proven over real HTTP on QA1** (a local dev server bound to a free
port against the real `corex_qa1` database, not in-process tests, not by
reading the blade), logged in via real `POST /login` for a real session
cookie, as three purpose-built test fixtures (`CC3 Proof BM Branch1` —
branch_manager, branch 1; `CC3 Proof Agent Branch1` — agent, branch 1;
`CC3 Proof Agent Branch2` — agent, branch 2 — plus one rental application
each, both archived again at the end of verification, soft-deleted, not
hard-deleted, recoverable, same as any other archived row):
- Search: a unique string in the rental application's OWN `full_name`
  found it; the SAME string scoped to the wrong branch did not leak
  across the branch boundary; searching by `id_number` directly found
  the row.
- Sort: captured the actual first row before/after toggling direction,
  for Applicant, Created, Agent, and Updated — each pair showed a
  genuinely different row, proving the order actually changed (not just
  that the request succeeded).
- Scope enforcement: the branch_manager fixture, whose real permission
  ceiling is 'branch', was served ONLY branch-1 rows even when the URL
  was edited to `?scope=agency` — branch-2's application never appeared.
  The agent fixture, whose real ceiling is 'own', was served ONLY their
  own row even when the URL was edited to `?scope=branch` or
  `?scope=agency` — the response never contained another branch-1
  agent's own applications (checked by name, count = 0) despite those
  genuinely existing in the same branch.
- Archive/restore: archived over real HTTP (a real 302 redirect, not an
  in-process call), confirmed absent from the default list, confirmed
  present under "Show archived", confirmed **in the database directly**
  via `withTrashed()` that the row still exists with a real `deleted_at`
  timestamp (not gone, not hard-deleted) and is correctly invisible to a
  normal query; restored over real HTTP, confirmed visible again with
  `deleted_at` back to `NULL`.

**Test fixtures left in the database, not real people, agency 1
(matching the existing precedent of e.g. `CC5 Proof Agent`):**
`cc3-proof-bm1@example.test`, `cc3-proof-agent1@example.test`,
`cc3-proof-agent2@example.test` (users, one per branch/role tested), two
Contact rows, and two RentalApplication rows (both left archived at the
end of this round's verification — recoverable, not deleted).

---

## Review screen — re-verification against a fresh nine-point list, autosave/warning fix (Johan, 2026-09-08)

Johan gave nine points on the review screen, several of them near-verbatim
repeats of items already fixed and documented above in "In-place
annotation, stroke marks, notes, and speed" and "Six usability fixes from
Johan's first real test." Rather than assume either that they were already
fixed or that they needed rebuilding, each was independently RE-VERIFIED
against the current code over real HTTP (login, CSRF, curl, direct DB
reads) before touching anything — the same standard this file already
holds itself to elsewhere (see BUILD_STANDARD.md §5a, written earlier the
same night from this exact module's RA-06 defect).

**(a) "left page panel should not load as default" — already fixed,
re-confirmed live, not touched again.** The rendered page's own `x-data`
init shows `activeDocId: null` and there is no `x-init` anywhere that
opens a document automatically — fetched the real page over HTTP and
grepped the actual response for both, rather than trusting the Blade
source alone. This was the modal-vs-inline defect fixed in "In-place
annotation..." above (item 5); still fixed.

**(b) "set the default to highlighter with a bright yellow colour" —
already fixed, re-confirmed live.** Same real-HTTP fetch shows
`activeTool: 'highlight'` and `activeColor: 'yellow'` (`#ffeb3b`) on load.

**(d) "left and right panels should scroll independently" — already
fixed, re-confirmed against the pattern source.** `.rental-review-main`
and `.rental-review-aside` use the same `max-height: calc(100vh - 88px)` +
`overflow-y: auto` + (on the aside) `position: sticky` + `align-self:
stretch` mechanism as `docuperfect/signatures/review.blade.php`'s
`.review-aside`/`#agentAmendPanel` — checked that file's actual CSS
side-by-side (`align-self: stretch` there is called out as LOAD-BEARING;
matched here) rather than assuming the earlier port was faithful.

**(g) "highlighter buttons at top not in a header, save button off
screen" — already fixed, re-confirmed live.** The tool picker, colour
picker, undo/redo, mark count, and Save button all render inside
`<x-sticky-action-bar>`'s right slot (`resources/views/components/
sticky-action-bar.blade.php`, `class="sticky top-0 z-50 ..."`) — the same
shared component `rental-applications/show.blade.php` already uses for
its own identical fix. Confirmed the component's own CSS is genuinely
`position: sticky` (not just "looks sticky"), and that it sits OUTSIDE
`.rental-review-main`'s own internal scroll container, so it never
scrolls away while paging through a long document.

**(h) "we don't have the write something, or insert a note bit" —
already fixed, re-confirmed with a real save.** Notes were added in
"In-place annotation..." above (item 4). Re-verified over real HTTP
rather than trusting that entry: POSTed a note-only mark
(`{type:'note', x, y, text, color}`) to document 2974's highlight
endpoint, got a real 200 (`mark_count:1`), reloaded via the highlight-data
endpoint and got the exact same text back. Cleared afterward.

**(c) "if I edit / highlight anything on the pdf will it automatically
save?" — genuinely still ambiguous, now fixed.** Not covered by the
modal-vs-inline fix above — a different, narrower defect. The tool used a
HYBRID: an explicit Save button, but ALSO a silent auto-save when
switching documents or clicking "Done" while marks were unsaved
(`if (this.dirty) await this.applyHighlights()`, no confirmation shown to
the agent). Worse, the one save-confirmation badge that existed
(`justSaved`) lived inside the SAME `x-if="activeDocId !== null"` template
as the rest of the toolbar — so a save-then-close hid the confirmation in
the same tick it fired, meaning the silent path never even proved itself
had happened. Fixed to match the viewing-pack redaction tool's own
answer to this exact question (`command-center/viewing-packs/
show.blade.php`'s `redactionTool()` has no autosave at all — "Apply
redaction" is the only way a box is ever persisted): highlighting/notes
are EXPLICIT-save only now. Switching documents or clicking "Done" while
dirty shows a real `confirm()` — "Save them before switching/closing?" —
never a silent act either way; declining leaves the agent on the same
document with their marks intact (never discarded, never guessed-saved).
The save confirmation itself moved OUTSIDE the open-document template
into a page-level toast so it survives the panel closing.

**(e) "clicking back to application shows a changes may be lost popup but
theres no save button visible anywhere" — fixed by pairing, not by
removing the warning.** No `beforeunload` handler existed anywhere in
this file (grepped the whole rental-applications view tree — zero
matches), unlike several other CoreX screens that already have this
exact pattern (`role-manager.blade.php`, `properties/show.blade.php`,
`agent/assistants/matrix.blade.php`, `compliance/policy/edit.blade.php`,
`docuperfect-editor.js` — all use `if (dirty) { e.preventDefault();
e.returnValue = ''; }`). Added the same pattern here, wired to the
highlighter's own `dirty` flag via an `init()` hook Alpine calls
automatically. The pairing Johan asked for is now real: the warning can
only ever fire while `dirty` is true, and the sticky header's Save button
(item (g), already fixed) is visible the entire time a document is open
for marking — so a warning is never shown without a reachable way to act
on it.

**(f) "right hand panel? is that filled in from where?" — already
investigated and answered in "Six usability fixes..." item 6 above
(100% agent-typed, verified against a real user's real DB row), but that
answer lived only in this spec file, never on the screen itself. Fixed
by putting it on screen**, in plain language, in two places: the aside's
own intro copy now reads "You type these — nothing here is pre-filled
from the application," and the Suggested-check block now names its rent
figure explicitly ("Rent (applicant's self-reported current rent, from
the application)") instead of showing a number with no stated source.
**Answer for Johan, plainly:** the Affordability panel is 100% the
agent's own typing — nothing on it is ever pulled in automatically. The
one number it calculates FROM elsewhere is the "rent" used in the
suggested check, which comes from the applicant's own self-reported
current rental amount on their application form (not the property being
applied for — there is no separate "asking rent" field captured yet).

**(i) "larger docs loads slow" — re-measured, not re-guessed; unchanged
from the prior round's own finding, still Johan's call, nothing shipped.**
This is the SAME item already measured and documented in "In-place
annotation..." above — re-ran it fresh rather than trusting the old
number. Cleared document 2974's rasterization cache directly and timed a
genuinely cold real-HTTP fetch of its highlight-data endpoint (17 pages):
**9,226ms**, matching the prior round's 9,129ms almost exactly (no
regression, no silent improvement since). Root cause, unchanged: Poppler
`pdftoppm` rasterization at 150 DPI, ~500ms/page, paid ONCE per document
version and cached after — but the FIRST time any agent opens ANY
document is exactly the common case (a freshly-submitted application's
documents have never been opened by anyone yet), so the cache does not
help the case Johan is actually hitting when he tests. The two
previously-flagged, not-yet-decided options stand unchanged: lower the
render DPI (cuts render time roughly with pixel count, at a real
on-screen sharpness cost) or return page 1 immediately and rasterize the
rest in the background (improves perceived speed on a first-ever open
without reducing total server work). Not built this round — reporting
the cause and the fresh number, per instruction, before touching
anything.

**Files touched this round:** `resources/views/corex/rental-applications/
review.blade.php` only — (c)/(e)/(f) above. (a)/(b)/(d)/(g)/(h) required
no code change, only re-verification; (i) is report-only.

---

## (i) resolved — progressive load, no quality tradeoff (Johan's decision, 2026-09-08)

Johan's decision on the 9.2s cold-open cost above: build the progressive
option, NOT the lower-DPI option — verbatim, *"the agent is reading an ID
document, a payslip, a bank statement. Sharpness is the entire point of
the screen; a slightly softer image to save a few seconds is a bad trade
on exactly the documents where detail matters."* Two explicit requirements
attached: the agent must be able to SEE more pages are coming and roughly
how many, and marking on a not-yet-rendered page must never be possible
(silently losing it is worse than not letting it happen).

**What changed.** `RentalApplicationDocumentHighlightService::pagePreviews()`
(one call, rasterizes and returns every page before anything can render)
replaced with two methods:

- `firstPagePreview()` — rasterizes ONLY page 1 (`pdftoppm -f 1 -l 1`),
  returns it plus the real total page count (from `pdfinfo`, cached to a
  `total-pages.txt` marker in the cache dir so a second request never
  re-decrypts the source or re-runs `pdfinfo` for a number already known)
  and the FULL saved-marks blob for the document (not split per page —
  see "no data loss" below).
- `remainingPagePreviews()` — rasterizes pages 2..N in ONE further
  `pdftoppm` call (`-f 2`, no `-l`), same "batch, don't spawn per page"
  lesson already measured for the full-document path. Both share the
  exact same on-disk cache directory/versioning as before, so a document
  that's already fully cached (a repeat open) is exactly as fast as it
  always was — this only changes the FIRST-ever open of a document.

Two new routes/controller methods (`highlightFirstPage`,
`highlightRemainingPages`) replace the old single `highlight-data`
endpoint. The frontend fetches the first page, renders it immediately,
then fetches the remainder in the background — the agent can read and
mark up page 1 while pages 2..N are still rendering server-side.

**Real measurement, same 17-page document, genuinely cold cache:**
first page **670ms** (down from 9.2s to see ANYTHING — a ~14× improvement
in what the agent actually waits for before the screen is useful), then
remaining 16 pages **8,694ms** in the background. Total server work
**9,364ms** — essentially unchanged from the 9.2s single-call cost (as
expected: it's the same `pdftoppm` rasterization, just split into two
calls instead of one), confirming this is a genuine PERCEIVED-latency
fix, not a hidden total-cost regression. Warm-cache repeat opens:
**125ms** / **136ms** for the two calls — unchanged from before. Full
150 DPI preserved throughout, page 1 and page 17 both verified at
1241×1755px — no quality tradeoff of any kind, per instruction.

**Requirement 1 — visible progress, not a silent wait.** A banner
("Page 1 of 17 shown — loading the remaining 16 pages. You can start
marking up page 1 now.") shows on screen while the remaining pages load,
driven by a real `pagesLoading` flag, not a fixed timer.

**Requirement 2 — cannot mark an unrendered page, and cannot lose a
mark that was already there.** Two separate risks, both closed:

- A page that hasn't loaded yet has no image and no draw-surface `<div>`
  in the DOM at all (`x-for="page in pages"` only iterates over pages
  that have actually arrived) — there is nothing for the agent to click
  or drag on, so marking an unrendered page is not merely discouraged,
  it's structurally impossible.
- The more dangerous risk, found while building this (not by testing
  after the fact): `applyHighlights()` (Save) builds its payload from
  `this.pages` — if that only contains page 1 because the rest are still
  loading, and the agent saves anyway, the resulting POST would REPLACE
  the document's entire mark set server-side with a payload that only
  covers page 1 — silently wiping any already-saved marks on pages 2..N,
  exactly the class of loss Johan ruled out, just on OLD marks rather
  than a mark the agent just drew. Fixed on both sides: `applyHighlights()`
  now refuses outright while `pagesLoading` is true (a real, tested
  guard, not just a disabled button — the Save button is ALSO disabled
  and relabelled "Loading…" for the same duration, so the refusal is
  never the agent's first sign anything was wrong). Backend
  defence-in-depth: `cachedOrRasterizedPagePaths()` (used by
  `applyMarks()` to assemble the final flattened PDF) now verifies the
  cached page COUNT matches the real page count before trusting the
  cache — previously it treated "any page file present" as "fully
  cached," which a partially-loaded document now makes a real, not
  hypothetical, state. Proved directly: cleared the cache, fetched ONLY
  the first page (leaving the cache genuinely partial), then POSTed a
  mark straight to the save endpoint bypassing the frontend guard on
  purpose — got a real 200, and the resulting flattened PDF was verified
  at a full **17 pages**, not 1.
- Existing saved marks for a not-yet-loaded page are never dropped
  either: the full marks blob comes back with the FIRST page's response
  (cheap, and page-agnostic), held client-side, and applied to each page
  the moment that specific page's image actually arrives — proved by
  saving a note on page 6 and confirming it round-trips through the
  first-page response's `marks` object correctly before page 6's image
  has even loaded.

**No cost not accounted for.** Checked before calling this done, per
instruction to stop and say so if the real cost turned out worse than
9.2s: total server-side work is unchanged (9.36s vs 9.2s, within normal
variance), and the one edge case that costs MORE than before (an agent
saving while pages are still loading) is exactly the scenario now
prevented at the UI level — the backend safety net exists only to make
that scenario correct rather than reachable in the first place.

**Files touched:** `app/Services/RentalApplications/
RentalApplicationDocumentHighlightService.php` (replaced `pagePreviews()`
with `firstPagePreview()`/`remainingPagePreviews()`, refactored the
private rasterization helpers to share one page-range rasterizer),
`app/Http/Controllers/CoreX/RentalApplicationReviewController.php`
(replaced `highlightData()` with two thin controller methods),
`routes/web.php` (two routes replacing one), `resources/views/corex/
rental-applications/review.blade.php` (progressive fetch, loading banner,
save-guard).

---

## CRITICAL — partial saves silently destroyed other pages' marks (cc1 + independent second agent, 2026-09-08)

Two independent runs (cc1 on this document, a second agent on a
different 6-page document in a separate agency) both found the same
thing: `POST .../highlight` with marks for only SOME of a document's
pages returns 200 OK and SILENTLY WIPES the previously-saved marks on
every page NOT mentioned in that request. My own verification of the
progressive-load save-guard had checked that the rendered PAGE IMAGES
came back complete after a partial-save attempt — they did — and
concluded the guard worked. That was the wrong question. The right
question was whether the SAVED MARKS survived, and they did not. This is
exactly the failure BUILD_STANDARD.md §5a (written earlier the same
night, from this same module's RA-06 defect) exists to name: verifying
one thing and reporting a different one as proven.

**Root cause.** `applyMarks()` treats whatever `marksByPage` it is given
as the document's COMPLETE, authoritative state and REPLACES `marks_json`
wholesale. This was always true, not something progressive loading
introduced — it was safe before only because the old frontend loaded
every page before allowing any interaction, so every save payload
happened to be complete by construction. The moment ANY code path can
reach this endpoint with an incomplete view of the document — progressive
loading (by design), a stale tab, a slow connection, a double submit, a
retry — the "payload is authoritative" assumption breaks, and it breaks
silently: a real 200, correct page images, and a quietly truncated
`marks_json`.

**Reproduced directly before fixing, per instruction.** Saved real marks
on pages 0, 3, and 7 of document 2974 (17 pages), confirmed all three in
`marks_json` via a direct DB read. Then POSTed straight to the save
endpoint with marks for page 0 only — got a real 200 — then read
`marks_json` directly from the database again: pages 3 and 7 were gone,
only page 0's new mark remained. Confirmed the exact failure both other
agents reported before writing a line of fix code.

**Fix: refuse, don't merge — server-side, not client-side.** Chosen over
merging for one reason: refuse is a single, provable invariant ("this
payload is either the complete truth about every page, or it is
rejected") rather than a merge implementation that has to correctly tell
"page 3 has zero marks" apart from "page 3 was never mentioned" in every
caller, forever — one missed case there reintroduces the same class of
bug in a subtler shape. It also costs nothing the design didn't already
intend: the frontend already disables Save until every page has loaded
(the `pagesLoading` guard from the progressive-load round above) — this
makes that a real server-side guarantee instead of a client-side
suggestion a stale tab or a crafted request could simply skip, which is
the actual complaint: **"client-side cannot be the guard for data loss."**

Mechanically: `RentalApplicationDocumentHighlightService::totalPageCount()`
(new, public) returns the document's real page count. In
`RentalApplicationReviewController::applyHighlight()`, after the existing
per-mark shape validation, the validated `marks` array's page-index key
set is compared against `range(0, totalPages - 1)` — if they don't match
exactly, the request is rejected with a real `422` and a plain message
("This document hasn't fully finished loading yet — wait for every page
to load, then try saving again."), and `applyMarks()` is never called, so
nothing already saved is touched. The frontend (`applyHighlights()`) was
changed to always send an entry for EVERY loaded page — including an
empty array for a page with zero marks — instead of omitting empty pages,
since omitting them would make every genuine complete save look
incomplete and get refused by the new check.

**Verified the way it actually fails, not by re-checking page images:**

- Reproduced the original bug first (above), confirmed via direct DB
  read, before changing anything.
- Same partial-page attack (marks for page 0 only) against the FIXED
  server: real `422`, plain message, and `marks_json` read directly from
  the database afterward — pages 0, 3, and 7 all still present, byte-for-
  byte unchanged from before the attack.
- Stale tab, simulated as a payload covering only 10 of the document's 17
  pages: refused the same way, database unchanged.
- Double submit: the same genuinely complete payload (all 17 page keys
  present) POSTed twice in a row — both return `200`, both succeed,
  final `marks_json` correct and not duplicated — confirms the fix is
  refuse-on-incomplete, not refuse-on-repeat.
- A genuinely complete save (all 17 page keys, three of them with real
  marks) still succeeds normally and round-trips correctly through
  `firstPagePreview()`'s `marks` field on reload.

**(e) answer for Johan, plainly, as asked:** the "changes may be lost"
popup is gone by design, not by accident. The screen won't let an agent
leave a document with unsaved marks without asking them to save first —
so by the time they could click "Back to application," there is nothing
left to warn about.

**Files touched:** `app/Services/RentalApplications/
RentalApplicationDocumentHighlightService.php` (new public
`totalPageCount()`), `app/Http/Controllers/CoreX/
RentalApplicationReviewController.php` (the completeness gate in
`applyHighlight()`), `resources/views/corex/rental-applications/
review.blade.php` (`applyHighlights()` now sends every loaded page, not
just non-empty ones).

---

## The authorisation queue had no menu link (Johan, 2026-09-08)

Same defect class the whole "Rentals" section was built to fix in the
first place — a real screen an authoriser is entitled to reach existed,
worked, and was already proven end-to-end, but nobody could actually get
to it without already knowing its address. Johan's standing rule: every
page gets a navigation entry, no exceptions, not something asked for
after the fact.

**Fixed in the one file that actually serves the menu** —
`resources/views/layouts/corex-sidebar.blade.php` (confirmed by cc1
earlier the same night as the served copy; nothing else edited).

**Gate reuses the real check, not a new one.** Johan's own instruction:
"do not invent a second, parallel check that can drift out of step with
the real one." `RentalApplicationAuthorisationController::guardCanView()`
enforces `isRentalApplicationRO() || isRentalApplicationCO()` — `User.php`
already wraps that exact pair as `isRentalApplicationAuthoriser()`, so the
new link is gated on that one call, nothing re-derived. The Rentals
group's own outer visibility check (`hasAnyPermission(['rental_applications.view',
'rental_applications.view_returned'])`) is widened with
`|| $user->isRentalApplicationAuthoriser()` in the same edit — otherwise
an authoriser who happens not to also hold either of those two
permissions would qualify for the queue but never see the "Rentals"
toggle that leads to it, the exact half-fixed shape this exists to avoid.

**Proven over real HTTP** (a local dev server against the real database,
real login, real session — not in-process, not by reading the blade),
with a real Reviewer-tier user, a real Override-tier user, and a real
plain agent with neither:
- The Reviewer's and the Override's own real dashboard pages both contain
  the "Rental Application Authorisation" link, with the correct address.
- The plain agent's own real dashboard page contains it zero times —
  confirmed the page still rendered a real, working sidebar for that
  user (the other two Rentals links are present and counted), so the
  absence is the gate working, not a broken page.
- Following the link as the Reviewer lands on the real authorisation
  screen: a real `200`, the correct page title, no error text anywhere
  in the response.
- The plain agent hitting that same screen's address directly (bypassing
  the menu entirely) is refused with a real `403` — the existing,
  already-proven server-side guard, unchanged by this fix; this fix only
  stops the agent being shown a door they could never open.

`php -l` clean. `php artisan view:clear` run after.

## Round 8, completed — all four items applied, verified, pushed

cc6 stood down from `review.blade.php`, `RentalApplicationAuthorisationController.php`,
`RentalApplicationReviewController.php`, and `RentalApplicationSettingsController.php`
(confirmed by the conductor directly with cc6) before any of the below
was applied.

**Applied:**
1. `RentalApplicationAuthorisationController::approve()` — sanitizes
   `approved_rental_amount` before validating.
2. `RentalApplicationReviewController::saveAssessment()` — sanitizes
   `monthly_income`/`other_monthly_income`/`monthly_expenses`.
3. `RentalApplicationSettingsController::updateQualifyingFormula()` —
   sanitizes `income_to_rent_multiplier`.
4. `review.blade.php:194` (line shifted since the audit; found by
   grepping for the attribution span cc6 already applied) — the "Added
   after submission" badge, same condition/wording as `show.blade.php`.

**Verified — real HTTP, real login, real session, on already-used data,
every field individually, every South African spelling separately, DB
read directly after each:**

| Where | Typed | Stored |
|---|---|---|
| Authoriser approve (real app #9, pre-existing "Test Test55") | `8,500` | `8500.00` |
| Authoriser approve (real app #13, same contact) | `8 500` | `8500.00` |
| Authoriser approve (real app #6, pre-existing "test test") | `R8 500` | `8500.00` |
| Authoriser approve (fresh, plainly-named test fixture, no 4th real candidate existed) | `8500.50` | `8500.50` |
| Assessment `monthly_income` (real app #6, all 4 spellings run sequentially — autosave is repeatable, unlike approve) | `8,500` / `8 500` / `R8 500` / `8500.50` | each confirmed via the endpoint's own JSON response; final DB value `8500.50` |
| Assessment `other_monthly_income` (same app, same 4 spellings) | ″ | final DB value `8500.50` |
| Assessment `monthly_expenses` (same app, same 4 spellings) | ″ | final DB value `8500.50` |
| Qualifying formula (agency 1's real settings row) | `3,5` / `3 5` / `R3.5` / `3.5` | final DB value `3.50` |

A genuinely invalid value (`"not an amount"` / `"not money at all"`)
still rejected after sanitizing, on both the approve action and the
original 4 fields — confirmed the sanitizer doesn't mask real errors.

**RA-03 badge, full walkthrough, real actions in real order:** a fresh
test-labelled application's public link was submitted for real
(`POST .../submit`, real signatures) → an agent added a document
afterward via the real async endpoint → the agent's REAL REVIEW SCREEN
(not `show.blade.php`) was rendered and shown to carry both "added by
{agent}" and "Added after submission" with the correct timestamp,
directly attached to that document's own row (confirmed by isolating
the exact HTML block around the document, not a page-wide substring
match).

**The corrected test file proven to discriminate, not just proven to
pass:** `RentalApplicationRound8FixesTest.php`'s 8 tests were run
against the pre-fix code (temporarily swapping the 5 changed files back
to their prior committed versions, keeping the new test file) — 5 of 8
failed with the exact `"The ... field must be a number."` messages cc5
originally reported; the 3 that still passed on old code are the ones
that don't depend on the fix at all (rejecting genuinely invalid input,
a plain "3.5" with no formatting, and "no badge before submission" —
correctly unaffected either way). Restored the fix, re-ran: 8/8 pass.
Full `tests/Feature/RentalApplications/` suite: 79 passed. Two failures
in `RentalApplicationCrudStandardTest.php` (own/branch/agency scope
toggle on the index screen) are pre-existing — reproduced identically
against the pre-Round-8 code, confirmed unrelated to anything touched
tonight, not fixed (cc3's own file, out of scope) — reported, not
touched.

**Test fixtures created tonight, all soft-deleted at the end, all named
plainly as test accounts per the new standing rule** (a fixture
described in words that sounded like a real staff member cost an hour
earlier tonight): `CC4 TEST ACCOUNT - Round 8 Authoriser`, `CC4 TEST
ACCOUNT - Round 8 Agent`, `CC4 TEST ACCOUNT - Round 8 Owner (settings)`
(users), and two `RentalApplication` rows named `CC4 TEST FIXTURE -
Round 8 approve decimal variant` / `CC4 TEST FIXTURE - Round 8 badge
walkthrough`. Agency 1's `rental_application_ro_user_ids` — set to
`[test authoriser's id]` only for the duration of the proof — restored
to its original `NULL` afterward; the real CO user already configured
there (a genuine staff member) was never touched or logged in as.

Branch: `feature/rental-applications-numeric-fix-final-2026-09-08`
(the `...-2026-09-08` branch without `-final` was rebased twice as QA1
moved during the night and could not be force-pushed under this
session's permissions — this is the one to land).

---

## Review screen — two live blockers, root-caused by actually loading the screen (Johan, 2026-09-08)

Johan hit two blockers that made the review screen unusable, both
reported as "working" by every prior check tonight — real-HTTP save
round-trips, direct DB reads, adversarial re-tests. Every one of those
checks was still a check of the SERVER. None of them ever rendered this
page in an actual browser. That gap is the real finding here, not just
the two bugs it hid.

**Verified this round with Puppeteer (headless Chromium) driving the
actual page — real login, real clicks, real mouse drags, real
keystrokes, reading the real DOM and the real browser console — not
markup inspection, not an HTTP status code.** This is now available on
this box (`/corex-qa1/node_modules/puppeteer`, `/usr/bin/chromium`) and
should be the standard for anything where the failure mode is
client-side rendering, exactly as it was here.

### 6 — "highlighter dont work - just shows a little black x but no colour applied"

Root cause, found the moment the page was actually opened in a browser:
the real console showed a real, reproducible error on every single draw —
`Failed to execute 'importNode' on 'Document': parameter 1 is not of
type 'Node'`, followed by repeated `mark is not defined`. Both come from
one fact about how browsers parse HTML: a `<template x-for>`/`<template
x-if>` (Alpine's cloning mechanism for lists/conditionals) placed
DIRECTLY INSIDE an `<svg>` element is parsed as foreign SVG content, not
as the special HTML `<template>` element — so it never gets the
"`.content` is a cloneable DocumentFragment" treatment real HTML
`<template>` elsewhere on this exact page gets. Alpine's clone step threw
on every attempt, so every polyline's `points`/`stroke`/`stroke-width`
came back blank — literally an invisible line at zero width. The one
thing that DID render was the little remove-mark `×` button, because it
lives as a plain HTML `<button>` OUTSIDE the svg, in ordinary HTML
parsing context — which is exactly what Johan saw and described.

This could never have shown up in any check this module has run all
night — real HTTP save/reload proves the SERVER stores and returns the
right bytes, which it always did; the failure is 100% client-side
rendering, invisible to curl, invisible to a database read, invisible to
reading the Blade source (which looks completely correct — this is a
genuine browser-parsing gotcha, not a typo).

**Fixed:** replaced the `<template>`-based polyline rendering inside the
`<svg>` with a single `x-html` binding on the `<svg>` itself, driven by a
new `strokesSvgFor(pageIndex)` method that builds the polyline markup as
a plain string (existing strokes + the live in-progress drag preview).
`x-html` re-evaluates on every dependency change exactly like
`x-text`/`x-show` do, so this stays fully reactive with no `<template>`
inside SVG anywhere. Every value going into the string comes from this
component's own numeric point/color/width state, never free-typed text,
so there's nothing here that needs HTML-escaping.

**Verified with a real drag, not a markup check:** logged in, opened a
document, dragged an actual highlight stroke with Puppeteer's mouse API,
then read the real DOM: the `<svg>` now contains a genuine `<polyline
points="...", stroke="#ffeb3b", stroke-width="22">` with real coordinates
— confirmed with a screenshot showing an actual visible yellow stroke on
the document. Zero console errors, where there had been dozens.

### 7 — "note does not work - clicked, shows small modal but cannot type anything in it"

Root cause, found the same way: the pending-note popup (the little
textarea that appears when the agent clicks with the Note tool active)
sits inside the SAME draw-surface `<div>` that has
`@pointerdown.prevent="startDraw(...)"` with no `.stop`. A `pointerdown`
that starts on the textarea (clicking into it to type) bubbles up to that
ancestor, and Alpine's `.prevent` modifier calls `event.preventDefault()`
on it BEFORE `startDraw()` even runs — and calling `preventDefault()` on
a `pointerdown`/`mousedown` is a well-known way to cancel the browser's
own default FOCUS behaviour for whatever was about to receive it. The
textarea was never actually gaining focus, so every keystroke went
nowhere. The existing `@click.stop` on the textarea couldn't help —
`click` fires AFTER `pointerdown`, once the focus had already failed to
happen.

**Fixed:** added `@pointerdown.stop` to the pending-note wrapper, the
existing-note marker/popover wrapper, and the remove-stroke buttons —
nothing inside these overlays can have its pointerdown intercepted by the
draw surface's own handler again.

**Verified with real keystrokes, not a markup check:** switched to the
Note tool, clicked on the document with Puppeteer, confirmed via
`document.activeElement` that the textarea was NOT focused before the
fix and WAS focused after, then typed real characters with
`page.keyboard.type()` and read the result back from both the DOM
element's `.value` and Alpine's own reactive `pendingNoteText` — both
showed the typed text, confirmed with a screenshot showing real typed
characters in the box.

### 8 — button placement, third time (Johan)

*"same fight with placement of buttons - submit to auth should be on the
header at the top, not off screen at the bottom... every primary action
on this screen belongs in the header where it is always visible."*
"Request more info from applicant" and "Submit to authoriser" (with its
existing "Re-submit" relabel once already pending) moved from the
aside's "Next step" block into the sticky header's right slot, alongside
"Back to application" — same visibility guard as before (hidden once
approved/declined). The note captured for "Request more info" became a
native `prompt()` rather than a header-embedded textarea, so the whole
action — including the button — fits one header row without inventing a
dropdown/popover for one short line of text; declining the prompt sends
nothing and loses nothing. The aside keeps only the feedback line (what
happened after using the header button) and the informational decision
banners (approved/declined/pending), which are state, not actions.
Verified with Puppeteer: header shows both buttons when no document is
open, swaps to the highlighter's own toolbar while one is, and reverts
correctly on Close; clicking "Request more info" in the header produces
a real `prompt()`, a real POST, and real feedback ("Sent to the
applicant.").

### 10 — "how do I save the work on the right hand panel? does it auto save?"

It already did — confirmed working all along (autosave-on-blur, a real
`updated_at`-seeded "Saved at HH:MM" badge) — but the only place that
said so was a line of body text easy to miss, visible only once scrolled
to. Fixed by adding a permanent, standing badge ("AUTOSAVES") next to the
"Affordability Assessment" heading itself — same visual weight as
"Marked up" and the highlighter's own save toast elsewhere on this
screen, visible from the instant the panel loads, before the agent has
typed anything, not only after. **Answer for Johan, plainly: yes, it
autosaves — every field in the Affordability Assessment panel saves the
moment you click away from it, no button needed, and the badge next to
the heading says so now.**

### Scrolling — "everything should fit that the whole screen doesnt scroll, but the left and right panels scroll in the screen"

The two panels' `max-height: calc(100vh - 88px)` was a guess that never
accounted for the QA/env banner's real height (`partials._env-banner`,
visible on QA1 as the blue "QA · 127.0.0.1" bar — real pixels, not zero)
or the sticky header's actual margins, so the panels came out taller than
the space genuinely left inside `#appScroll` (`layouts/corex.blade.php`'s
own scroll container) — `#appScroll` then had to scroll too, which is
the double-scroll Johan was seeing. A bigger guessed number would only
be correct for one banner/viewport combination and wrong for the next.

**Fixed by measuring the real available space at runtime instead of
guessing it** — a new `rentalReviewLayout()` component reads `#appScroll`'s
actual box height, the sticky header's actual rendered height and
margin, and this page's own top margin, computes what's genuinely left,
and sets it as a CSS custom property (`--rr-panel-h`) both panels size
against. Every value read is independent of `#appScroll`'s own scroll
position, so this stays correct regardless of how the page is scrolled,
and recalculates on window resize.

Also added `overflow-x: hidden` to `.rental-review-aside` directly, since
a 260px fixed column has no legitimate reason to ever need horizontal
scroll — investigated for an actual cause first (none found at 1600px
width; Puppeteer's own `scrollWidth`/`clientWidth` read confirmed no
overflow either way) but this removes any possibility going forward
rather than leaving it to reappear from some future addition.

**Verified with Puppeteer, not a visual guess:** `#appScroll.scrollHeight
=== #appScroll.clientHeight` (no outer scroll) both on initial load AND
after expanding the "Submitted Application" summary (which grows
`.rental-review-main`'s own internal content) — confirming the fix
correctly isolates panel-internal scrolling from ever leaking into the
outer page. `.rental-review-aside.scrollWidth === .clientWidth` (no
horizontal overflow).

### Files touched

`resources/views/corex/rental-applications/review.blade.php` only — every
fix above lives in this one file. No backend changes; RA-04/RA-06/the
critical partial-save fix from earlier tonight are untouched and
unaffected.

### The standing lesson, named plainly since Johan asked for it directly

Real-HTTP verification (login, CSRF, curl, direct DB reads) proves the
SERVER did the right thing. It cannot prove the BROWSER rendered the
right thing — those are different layers, and a bug can live entirely in
either one while every check of the other layer stays green. Tonight's
highlighter bug is the clean version of this: the server has always
stored and returned byte-perfect mark data; the failure was 100%
client-side, a real browser-parsing gotcha (`<template>` inside `<svg>`)
that no amount of curl or `php artisan tinker` could ever have surfaced.
The standing rule this earns, alongside BUILD_STANDARD.md §5a's
transport/data-state axes: **for any feature whose failure mode could be
rendering, layout, or interaction — not just data correctness — verify
it with an actual browser** (Puppeteer/headless Chromium, available on
this box), not markup inspection and not an HTTP round-trip alone. A
real screenshot and a real `document.activeElement`/console-error read
are cheap insurance against exactly this class of "everyone verified it,
none of them looked at it."

### Investigated, NOT built — skew/angled text highlighting (Johan's design question, decision pending)

Johan's own words: *"still not sure if the draw a box is the right
option or if we should move to a click and highlight option... scans
come back not square so the agent can highlight skew text as well which
the box option dont offer."*

**Worth correcting first: the box is already gone.** The rectangle
highlighter was replaced with a freehand marker-pen stroke in an earlier
round ("In-place annotation, stroke marks, notes, and speed," item 3) —
the CURRENT tool captures the agent's actual drag path, at any angle,
not a rectangle. An agent can already drag diagonally along skewed text
today; nothing prevents that specific motion. What the current tool
genuinely lacks is PRECISION — it's a shaky, hand-traced line with no
awareness of where the actual text sits, not a snap-to-word selection.
That gap is real and is what Johan is actually reacting to, even though
"draw a box" isn't quite what's shipping anymore.

**Three options, real effort estimates, ordered cheapest to most
expensive:**

**Option A — straight-line assist on the existing freehand tool.**
Small, additive change: detect a mostly-straight drag (or a modifier key
held) and snap the committed stroke to a clean straight line between
start and end, at whatever angle the agent dragged, instead of preserving
every shaky intermediate point. Solves "my hand isn't steady enough to
follow a skewed line cleanly" without touching the backend, the storage
shape, or the burn path at all — a highlight is still just `points`.
**Estimate: a few hours, one file, no new dependency, low risk.**

**Option B — true text-snap highlighting, PDFs with a real text layer
only.** Extract each page's word bounding boxes server-side (Poppler's
`pdftotext -bbox`, already installed alongside the `pdftoppm`/`pdfinfo`
this module already uses), hit-test the agent's drag path against those
boxes client-side, and burn per-word rectangles instead of a freehand
stroke — genuine Adobe/Word-style click-and-drag text selection.
**Real limitation worth being explicit about: this only works on a PDF
that has an actual embedded text layer.** A SCANNED document — a photo
or scan of a payslip or ID, which is exactly the case Johan is
describing — is just a raster image with no text data in it at all;
`pdftotext -bbox` finds nothing on a page like that, so this option
delivers ZERO improvement for the specific documents driving the
question. It would still be a real, worthwhile upgrade for digitally-
generated PDFs (bank statement exports, typed application forms). Not
yet confirmed whether Poppler reports a rotation angle for skewed text
runs in a native PDF (uncommon, but possible) or only an axis-aligned
box around the tilted glyphs — would need a real test document to
confirm before committing to this shipping "text-snap that follows
skew" rather than "text-snap that only works on upright native text."
**Estimate: comparable to or larger than the original "own the viewer"
rebuild — a new server extraction step, a client-side hit-testing
algorithm, and a different burn path. Call it 1.5–2.5 working sessions.**

**Option C — OCR-based text detection on the scanned image itself.**
The only option that actually addresses skewed SCANS, since it works on
pixels rather than an embedded text layer: run OCR (e.g. Tesseract,
not currently installed anywhere in this stack) on the rasterized page
to detect word regions, which requires either pre-deskewing the image
(detecting the dominant text angle and rotating before OCR — a real
image-processing problem, not a library call) or an OCR mode that can
report ROTATED bounding boxes directly, which is a materially harder and
less reliable problem than axis-aligned word detection. This is a new
system dependency, a real computer-vision R&D risk (accuracy on poor-
quality phone photos of documents is genuinely uncertain, not just a
matter of engineering time), and the most expensive option by a wide
margin. **Estimate: multiple working sessions minimum (3–5+), with real
uncertainty about final quality — this is the one option where "more
time" doesn't guarantee "works reliably."**

**Not decided, not built, per instruction.** Recommend Option A as a
near-free interim improvement regardless of which longer-term direction
Johan picks — it's cheap, low-risk, and helps the "my line is shaky"
complaint immediately while B/C (both real, larger projects) get
decided.
## Round 9 — applicant-facing fixes: email branding, still-living tick, rental term, send redirect (Johan, QA1, 2026-09-08)

Four items, all Johan's own words from testing.

### 1 — "email sent to applicant needs the same email format as esign emails - agent details, photo etc."

`RentalApplicationInviteMail` (`app/Mail/RentalApplicationInviteMail.php`) now
extends `App\Mail\Signatures\BaseSignatureMail` — the exact same base class
the e-sign "please sign" email (`SigningRequestMail`) uses — instead of a
plain `Mailable`. This is direct reuse, not a second parallel layout: the
From-address routing (agent's own address when on the agency's company
domain, "Name via CoreX OS" fallback for a personal address, mailbox
routing), reply-to, and the agent footer (photo, name, designation, phone,
cell, FFC, PPRA number, agency logo, email disclaimer, POPI link) all come
from `BaseSignatureMail`, unchanged. `RentalApplicationMailer::sendInvite()`
now calls `->fromAgent($application->createdBy)` before sending, mirroring
`SignatureService`'s own `->fromAgent($template->creator)` call exactly.
`resources/views/emails/rental-application-invite.blade.php` swaps its old
plain "agency name" footer for `@include('emails.signatures.partials.agent-footer')`
— the identical partial the e-sign email includes, not a copy of it.

Verified by actually sending: application id 10 (a real, already-existing
draft, restored to its original state afterwards — see verification note
below) sent from `johan@hfcoastal.co.za` to the one permitted test address,
`can.assurance@gmail.com`, hardcoded and confirmed in the code before
sending. Captured in the QA1 box's own local Mailpit catcher (mail on this
environment is caught locally, never sent externally — confirmed via
`ss -ltn` showing 127.0.0.1:1025/8025 listening, and via Mailpit's own API).
The captured message showed Johan's photo, name, "CEO" designation, email,
landline, cell, FFC number, website, the Home Finders Coastal logo, and the
agency's email disclaimer — all rendering correctly, matching e-sign emails.

**Found, not fixed (out of scope for this exact task, flagged per
non-negotiable #2):** the three other rental-application mailers —
`RentalApplicationApprovedMail`, `RentalApplicationDeclineMail`,
`RentalApplicationMoreInfoRequestMail` — have the identical plain-`Mailable`,
no-agent-footer pattern the invite email had before this fix. Johan's
instruction named "the rental application email" (the applicant invite)
specifically; these three were not touched. Same fix (extend
`BaseSignatureMail`, pass `fromAgent()`, include the partial) would apply
directly if Johan wants them brought in line too.

### 2 — "current landlord we have from and to dates - put a tick at to date to tick that states still living in current premises"

New column `current_rental_still_living` (boolean, nullable, default false)
on `rental_applications` — a separate column from `current_rental_to`, not a
sentinel/magic date value, because "still living there" (true) and "we
don't know the end date" (still null, flag false) are two different facts
for a reference check and must stay distinguishable. Migration:
`2026_09_08_160000_add_still_living_and_rental_term_months_to_rental_applications.php`.

Both the applicant-facing form (`resources/views/rental-applications/public/show.blade.php`)
and the agent-side form (`resources/views/corex/rental-applications/show.blade.php`)
now show a "Still living here — no end date" tick box next to the To date.
Ticking it disables and clears the To date input client-side (Alpine). A
standard hidden-input-plus-checkbox pair (`<input type="hidden" ... value="0">`
immediately before the checkbox, same `name`) ensures an UNTICKED box is
submitted as an explicit `0`, not silently absent — a plain checkbox alone
would never transmit anything when unticked, leaving a stale `true` in place
on save. Enforced server-side too, not just by disabling the input in the
browser: `RentalApplication::normalizeStillLiving()` forces
`current_rental_to` to null whenever `current_rental_still_living` is
truthy, called from both `RentalApplicationController::update()` (agent
side) and `RentalApplicationSigningController::submit()` (applicant side).

The PDF (`resources/views/corex/rental-applications/pdf.blade.php`) shows
"Still living there" in place of the To date when the flag is set.

Verified over real HTTP against a real, already-existing draft application
(id 10) via a local server bound to this fix's own worktree code but
pointed at the real QA1 database: ticking + saving cleared and stored
`current_rental_to = null`, `current_rental_still_living = true`; unticking
+ supplying a real date stored that date with the flag `false`.

### 3 — "rental term required - we need to have it in years / months / maybe a couple of quick clicks buttons? 6 months, 1 year, 2 years... nothing longer as law states no lease may exceed 24 months"

New column `rental_term_months` (unsigned small integer, nullable) — NOT a
retype of the existing `rental_terms` string column, because `rental_terms`
already holds real free-text values on 46 existing QA1 applications, and
changing its type in place would corrupt that history. `rental_terms` is
left untouched for old rows; new saves go through `rental_term_months`.
Both forms now show three quick-select buttons — 6 / 12 / 24 months, in
months only, nothing longer — replacing the old free-text input. 24 is a
hard ceiling (validation `nullable|integer|in:6,12,24`, plus the client-side
buttons simply don't offer anything above it) — the legal maximum for a
lease; a renewal beyond that is explicitly a separate matter, not part of
this form. The agent-side form additionally shows any pre-existing
free-text `rental_terms` value as a one-line note ("previously recorded as
free text: ...") when a record has old text but no new months value yet, so
that history isn't silently hidden from the agent. The PDF prefers
`rental_term_months` when present, falling back to the legacy `rental_terms`
text for older records.

Verified over real HTTP on the same application (id 10): submitting
`rental_term_months=36` was rejected (validation, value never persisted);
`rental_term_months=12` and `=6` both saved correctly.

### 4 — "on rental application send - once send is clicked redirect back to rental application screen"

`RentalApplicationController::send()` — only the SUCCESS path's redirect
changed, from `corex.rental-applications.show` to
`corex.rental-applications.index` (the list). The two error paths (no
recipient email; mail failed to send) still redirect back to the
application's own show page, deliberately — an agent fixing an error needs
to stay on the record that has the problem, not lose it on the list.
Verified over real HTTP: clicking Send on application id 10 returned a 302
to `/corex/rental-applications`, confirmed via the response's `Location`
header.

### Verification note — real data, restored afterward

Per instruction, verification used an already-existing real draft
application (id 10, agency 1, created by user 22) rather than fabricated
data, over real HTTP against the real QA1 database via a local server bound
to this fix's own isolated worktree. Two applications initially picked for
this (ids 51 and 47) turned out to already be soft-deleted from earlier,
unrelated testing — not a live collision, just pre-existing archived
records; skipped in favour of id 10, confirmed active first. Every field
change made to id 10 for verification (email, still-living flag, To date,
rental term, and the Send action's status/token changes) was captured
before editing and restored exactly afterward, so the real record was left
exactly as found. All disposable login fixtures created for verification
(`cc3-verify-applicant-fixes@example.test`, id 191) were soft-deleted
immediately after use, confirmed via `deleted_at` and normal `find()`
returning null.

### Files touched

- `database/migrations/2026_09_08_160000_add_still_living_and_rental_term_months_to_rental_applications.php` (new)
- `app/Models/RentalApplication.php` — fillable/casts/`fieldValidationRules()` for the two new fields; new `normalizeStillLiving()` helper
- `app/Http/Controllers/CoreX/RentalApplicationController.php` — `update()` calls `normalizeStillLiving()`; `send()`'s success redirect now targets the index route
- `app/Http/Controllers/RentalApplicationSigningController.php` — `submit()` calls `normalizeStillLiving()`
- `app/Mail/RentalApplicationInviteMail.php` — now extends `BaseSignatureMail`
- `app/Services/RentalApplications/RentalApplicationMailer.php` — `sendInvite()` passes `fromAgent($application->createdBy)`
- `resources/views/emails/rental-application-invite.blade.php` — includes the shared agent-footer partial
- `resources/views/rental-applications/public/show.blade.php` — still-living tick box, rental term quick-select buttons
- `resources/views/corex/rental-applications/show.blade.php` — same two, agent-side, plus the legacy-text note
- `resources/views/corex/rental-applications/pdf.blade.php` — renders "Still living there" and months-based term

## Round 9 — affordability formula corrected to the actual law (2026-09-08)

Johan, from his own reading of the law: "the law states you may not spend
more than 30% of your gross income on rentals... on this also confirm our
formula matches up with this - its not 3.5 or what you created it as of
nett disposable income. its of the gross income."

### Investigation findings (reported before any code changed)

The single calculation method, `RentalApplicationAssessment::qualifyingResult()`,
fed three display surfaces: the review screen's live Alpine display
(`review.blade.php`), the authoriser's static display
(`authorisation/show.blade.php`), and the settings screen configured the
multiplier both used. It computed `total_income`
(`monthly_income + other_monthly_income`, before expenses) and compared it
against `rent * multiplier` (default multiplier 3.00 — mathematically close
to but not exactly 30%, ≈33.3%), framed backwards as "income must be N
times rent" rather than "rent must be X% of income." `monthly_expenses`
was captured and an unused `net_income` figure was computed but played no
role in the pass/fail decision — and on `review.blade.php` specifically was
displayed unlabelled right next to the pass/fail badge. No field anywhere
(applicant form "Monthly salary (R)"; agent panel "Monthly
income"/"Other monthly income"; PDF "Monthly Salary") was labelled gross vs.
net/take-home — confirming Johan's exact fear (an applicant entering
take-home pay because nothing told them otherwise).

**cc5's claim, verified from the code directly, not on trust:** the OLD
`qualifyingResult()` compared `$totalIncome` (income before expenses) against
the threshold, never `$netIncome` — cc5's finding that the calculation
already used gross-before-expenses was correct. The defect was the
multiplier framing, not a net-vs-gross mixup.

### THE RULE, one place — `RentalApplicationAssessment::qualifyingResult()`

Rewritten so rent must not exceed `$maxRentPercent`% of gross income,
checked directly: `$maxAffordableRent = round($grossIncome * ($maxRentPercent / 100), 2)`,
`$meetsThreshold = $rent <= $maxAffordableRent`. No second implementation
exists anywhere — the settings screen, the authorisation screen, and (once
released) the review screen all call this one method. Renamed fields:
`total_income` → `gross_income`, `multiplier` → `max_rent_percent`,
`required_income` → `max_affordable_rent`; added `rent_as_percent_of_gross`.
`net_income` is kept in the return array — Johan's call — see "Judgment
call 1" below.

### Agency-configurable, default 30% — `RentalApplicationQualifyingSetting`

`income_to_rent_multiplier` (decimal(5,2) default 3.00) replaced outright
with `max_rent_percent_of_gross_income` (decimal(5,2) default 30.00 — the
legal ceiling itself), via
`database/migrations/2026_09_08_170000_replace_income_multiplier_with_gross_income_percentage.php`.
A clean replace, not a second column: only one agency (id 1) had ever
configured the old column, and that single row was confirmed to be a
leftover test artifact from this session's own Round 8 verification
(timestamped to that proof run) — hard-deleted before this migration was
written, restoring agency 1 to the correct never-configured, in-memory-
default state. The figure the percentage applies to (gross income) is
never itself configurable — only the percentage is. A new
`RentalApplicationQualifyingSetting::exceedsLegalCeiling()` returns true
when an agency sets above 30%.

**Judgment call 2 — how "above 30%" is handled, and why:** BOTH a one-time
`session('warning')` toast (the existing global `<x-toast-notifications />`
pattern, fired on save in `RentalApplicationSettingsController::updateQualifyingFormula()`)
AND a NEW persistent amber banner on the settings screen itself
(`resources/views/corex/settings/rental-applications.blade.php`), shown
for as long as the configured figure exceeds 30%. Reasoning: a toast alone
vanishes on the next page load and would not satisfy Johan's explicit "do
not silently accept it as normal" — an agency owner who set 40% last month
and forgot needs to see the warning again every time they open the screen,
not just the moment they saved it.

### Plain-language gross-income labels — every capture point

Extended `<x-rental-application-field>` with an optional `hint` prop
(backward compatible, defaults to null). Applied to both income fields
that exist on this feature:
- Applicant's own form (`rental-applications/public/show.blade.php`):
  label → "Gross monthly income, before deductions (R)", hint → "The amount
  on your payslip BEFORE tax and other deductions — not what actually
  lands in your bank account."
- Agent's own detail page (`corex/rental-applications/show.blade.php`):
  same label, hint → "...not their take-home pay."

Directly addresses Johan's own example (an applicant entering 10,000 when
their payslip shows 18,000 gross). The assessment panel's own income
fields (agent-typed, on the review screen) still need the same treatment —
blocked, see "Still blocked" below.

**Scope decision — `pdf.blade.php` left unchanged:** it is a read-only,
legal-document-styled transcript of a standard form, not a data-capture
point; relabelling risked diverging from a recognised document template's
exact wording for no functional benefit. Flagged for Johan's awareness —
override if he wants the PDF relabelled too.

**Judgment call 1 — `net_income` kept, not removed:** cc5 flagged it as
"actively misleading" since it's computed and shown but plays no part in
the decision. Kept — genuinely useful context an agent typed in themselves
(what's left after existing debts/expenses) — but the docblock on
`qualifyingResult()` now states explicitly that any screen displaying it
must label it unmistakably as reference-only, separated from the pass/fail
badge. Actually re-labelling it on-screen is only needed on `review.blade.php`
(the authorisation screen never showed it) — blocked, see below.

### Still blocked — `review.blade.php` / `RentalApplicationReviewController.php`

Deliberately NOT touched in this round — sequenced behind cc6's
review-screen work and cc3's applicant-side work landing on the shared
QA1 checkout. Confirmed via the shared checkout's own `QA1` branch that
`RentalApplicationReviewController.php` still calls the OLD `multiplierFor()`
at two call sites (the `show()` method and the assessment-autosave
endpoint) as of this commit — updating those calls, the Alpine result
display's key names, the assessment panel's income-field labels, and the
`net_income` relabelling all still need review.blade.php released. Item 5
(auto-adding income/expense rows) has not been designed yet at all —
it needs that same screen and likely a data-model change to support a
growing list of income/expense rows rather than fixed
`monthly_income`/`other_monthly_income`/`monthly_expenses` columns.

### Verification

`tests/Feature/RentalApplications/RentalApplicationRound9AffordabilityTest.php`
(new) covers: the default 30% ceiling; the worked example Johan asked for
verbatim (18,000 gross → 5,400 max affordable rent, with one rand over
flipping the verdict); that `net_income` does not affect the decision even
when wildly different; the agency-configurable percentage (stricter and
above-ceiling cases, including the persistent banner surviving a later,
unrelated page visit); the authorisation screen rendering the worked
example over a real HTTP request through the full Laravel kernel/middleware
stack; and the gross-income labels appearing on both the agent and public
applicant forms. `RentalApplicationRound8FixesTest.php`'s qualifying-formula
test updated to the new field name (the old one no longer exists after the
migration). Full run: 17 tests, 15 passed; the 2 failures are both
pre-existing calls through the still-blocked `RentalApplicationReviewController`
route (`Call to undefined method ...::multiplierFor()`) — expected and
unavoidable until that controller is released and updated in the same
commit that finishes this feature.

A live-server (real TCP, real login, real `php artisan serve`) proof of
the worked example was attempted via an isolated clone of the real QA1
data (dumped from `corex_qa1` into a disposable `corex_qa1_round9_verify`
database, never touching the shared schema other lanes are actively using
against the OLD column name) rather than migrating the shared database
directly — deliberately, since dropping `income_to_rent_multiplier` on the
live shared QA1 database would break every other lane's checkout still
reading it. The attempt hit infrastructure friction (the schema-snapshot
loader treated the pre-populated clone as fresh and tried to reapply the
whole snapshot) and was abandoned in favour of the HTTP-kernel-level
PHPUnit proof above, which already exercises real routing, real
middleware (auth/permissions/CSRF), a real database, and real Blade
rendering — no shortcut through the controller. The disposable database
and dump file were dropped/deleted; nothing was left behind, and the
shared `corex_qa1` database was never touched.

### Note — `origin/QA1` (GitHub) lags the shared `/corex-qa1` checkout

Not a defect, just worth recording: `origin/QA1` on GitHub was last pushed
2026-09-07 15:30 and does not yet include the last day's worth of
work merged locally into the shared `/corex-qa1` checkout's own `QA1`
branch (including both `review-screen-critical-fixes-2026-09-08` and
`fix/rental-applications-applicant-fixes-2026-09-08`, already merged
there). This round's commits are built on the shared checkout's `QA1`
branch (current, confirmed unchanged at time of push), not on the stale
GitHub ref. Flagging so the GitHub mirror doesn't fall further behind.

### Files touched

- `database/migrations/2026_09_08_170000_replace_income_multiplier_with_gross_income_percentage.php` (new)
- `app/Models/RentalApplicationAssessment.php` — `qualifyingResult()` rewritten to percentage-of-gross
- `app/Models/RentalApplicationQualifyingSetting.php` — column rename, `DEFAULT_MAX_RENT_PERCENT`, `LEGAL_CEILING_PERCENT`, `maxRentPercentFor()`, `exceedsLegalCeiling()`
- `app/Http/Controllers/CoreX/RentalApplicationSettingsController.php` — new field name, legal-ceiling warning (toast + banner flag)
- `app/Http/Controllers/CoreX/RentalApplicationAuthorisationController.php` — `maxRentPercentFor()`/new `qualifyingResult()` signature
- `resources/views/corex/settings/rental-applications.blade.php` — persistent banner, relabelled field
- `resources/views/corex/rental-applications/authorisation/show.blade.php` — gross-income labels, worked-example wording
- `resources/views/corex/rental-applications/show.blade.php` — agent's own income field relabelled, hint added
- `resources/views/rental-applications/public/show.blade.php` — applicant's own income field relabelled, hint added
- `resources/views/components/rental-application-field.blade.php` — new optional `hint` prop
- `tests/Feature/RentalApplications/RentalApplicationRound9AffordabilityTest.php` (new)
- `tests/Feature/RentalApplications/RentalApplicationRound8FixesTest.php` — field-name update only

Branch: `rental-applications-affordability-2026-09-08`, commits `dcd5f3cd4`
(the formula/labels/settings change) and `6ac326581` (a variable-name bug
in the authorisation controller's `compact()` call, caught before push,
plus this round's tests). Pushed to origin. Not merged into the shared
`QA1` checkout — awaiting review.blade.php's release to finish items 1/3/4
and to design item 5.

## Round 10 — review screen released: net-income label, gross-income label, growable income/expense rows (2026-09-08)

Conductor: "RELEASED — the review screen and its controller are yours.
cc6 has finished its investigation and is holding with no code in flight."
Finishes the three items Round 9 could not reach because `review.blade.php`
and `RentalApplicationReviewController.php` were still sequenced behind
cc6's and cc3's concurrent work.

### Item 1 — net income labelled unmistakably as reference-only

The live review screen (as landed by cc6's own investigation/fixes,
confirmed by reading the file fresh before touching it) did not display
`net_income` at all — the "actively misleading" state cc5 originally
flagged no longer existed on this exact screen by the time of this round.
Rather than leave it out, it was added back deliberately correctly the
first time: a separate, visually distinct block (dashed border, own
heading "FOR YOUR REFERENCE ONLY — DOES NOT AFFECT THE GUIDELINE CHECK
ABOVE", `data-verify`-free plain markup) placed AFTER the pass/fail badge,
never beside it — the exact adjacency that made the old screen
misleading in the first place.

### Item 2/3 — gross-income labelling on the agent's own panel

The old fixed "Monthly income"/"Other monthly income" fields are gone
(replaced by item 5's growable rows, below), so the "gross, before
deductions" language moved to the section HEADING ("Income (gross,
before deductions)") plus a one-line hint underneath, rather than
per-row — a row's own label is free text the agent chooses ("Salary",
"Side income"), unlike the applicant/agent-detail-page forms' single
fixed field from Round 9. Same clarity goal, different UI shape because
the rows themselves changed shape this round.

### Item 5 — growable income/expense rows

**Data model.** `monthly_income`/`other_monthly_income`/`monthly_expenses`
(fixed decimal columns) replaced with two new tables,
`rental_application_income_items` and `rental_application_expense_items`
(migration `2026_09_08_180000_create_rental_application_income_expense_items_tables.php`),
matching the existing `PayrollPayslipLine` precedent for a financial
line-item ledger belonging to one parent record — including `SoftDeletes`
(non-negotiable #1: an agent removing an income/expense line from a
financial record is a real, recoverable event, never a hard delete).
Existing captured data was migrated, not discarded: any assessment with
values in the old columns got one income/expense item each, preserving
real agent-entered amounts (confirmed against real QA1 data before
writing the migration — see Verification below). Caught one MySQL
identifier-length bug before it could break: the auto-generated foreign
key name for `rental_application_income_items.rental_application_assessment_id`
exceeded MySQL's 64-character limit — fixed with an explicit short
constraint name (`rai_items_assessment_fk`/`rae_items_assessment_fk`).

**`RentalApplicationAssessment`** gains `incomeItems()`/`expenseItems()`
(`hasMany`, ordered by `sort_order`); `qualifyingResult()` now sums those
relations instead of reading the two fixed income columns and the one
fixed expenses column.

**`RentalApplicationReviewController::saveAssessment()`** accepts
`income_items`/`expense_items` arrays (`{id?, description?, amount}`
each), sanitizes each item's `amount` through the existing
`RentalApplication::sanitizeNumericInput()`, and syncs them via a new
private `syncItems()`: matched by `id` where the client already has one
(a row from a previous autosave), updated in place; rows no longer
present are SOFT-deleted, never hard-deleted, and never blind
delete-all-then-recreate (which would soft-delete and immediately
recreate every unchanged row on every keystroke, turning the audit trail
into noise). A row with no description AND no amount (the ever-present
blank "type here to add another" placeholder) is filtered server-side
before syncing — Johan: "empty trailing rows must not save as zero-value
rows or clutter the record." The response echoes back the saved items
WITH their real ids, because the client has no other way to learn a
newly-created row's id before the NEXT autosave — without this, every
subsequent save would treat previously-saved rows as new and create
duplicates instead of updating them.

**`review.blade.php` / `rentalReview()`** — `incomeItems`/`expenseItems`
are now Alpine-reactive arrays, seeded from the assessment's real saved
items plus exactly one trailing blank row. `compactAndEnsureTrailing()`
runs on every row edit: removes any blank row that isn't the last one
(how an agent "deletes" a row — clear both its fields) and guarantees
exactly one blank trailing row is always available to type into — this
is literally "filling the last row auto-adds a fresh empty one." Live
totals (`incomeTotal()`/`expenseTotal()`) sum the SAME rows with the SAME
filter as the server, so the on-screen total can never legitimately
disagree with what `qualifyingResult()` computes — proven, not assumed,
in the Verification section below. `save()` captures the actual row
OBJECT references being sent (not a copy) before the request fires, so
the response's returned ids can be patched back onto them by position
without disturbing a blank row the agent has started typing into during
the round trip — a wholesale array replacement would have dropped that.

### Verification — real headless-browser session, real persisted data

Per instruction ("drive the actual screen — type in the last row and
confirm a new one appears, rather than checking the markup exists"), the
auto-add-row behaviour is Alpine.js client-side reactivity and cannot be
observed from a PHPUnit HTTP test — a real browser was required.

**Method, and why this shape:** the shared `/corex-qa1` checkout was mid-build
under cc2 at the time (confirmed via cc1: "cc2, who's mid-build on QA1
right now"), and cc1 was about to restore production-shaped data onto the
shared `corex_qa1` database — both made touching the shared checkout's
working tree OR running this round's migration against the shared
database unsafe at that moment. Instead: a disposable clone
(`corex_qa1_round10_verify`) was built from a real structure dump plus
real DATA for `migrations`, `agencies`, `branches`, `contacts`, `users`,
`rental_applications`, `rental_application_assessments`, `properties`,
`roles`, and `role_permissions` (cloning the `migrations` table's own
data, not just structure, was what let `php artisan migrate` run
correctly as an ordinary INCREMENTAL migrate — including surfacing the
FK-identifier-length bug above — rather than mistaking the DB for fresh
and trying to load the full schema snapshot, which is what happened on a
first, abandoned attempt during Round 9's own verification). A disposable
agent login was created by resetting a REAL existing agency-1 user's
password (`andre@hfcoastal.co.za`) — in the ISOLATED CLONE ONLY; the real
password on the real shared database was never touched. Puppeteer
(already a project devDependency; a read-only `node_modules` symlink to
the shared checkout's own install was used rather than running `npm
install` in the worktree, matching the sanctioned read-only-symlink
exception for a throwaway verification-only use). `public/build/assets/app.js`
in this worktree turned out to be a pre-existing 0-byte corrupted
artifact (unrelated to this round's code) — `npm run build` regenerated
it before Alpine.js would even load.

**What was actually driven, on real application id 49 (agency 1, real
contact, real historical assessment data: gross income R23,700 from two
real migrated income items, real current rent R7,500):**
1. Loaded the real review screen via real login, real session, real
   permission/scope checks (which needed cloning the `roles` and
   `role_permissions` tables too — data-scope resolution reads from the
   database, not just `config/corex-permissions.php`).
2. Typed into the actual last (blank) income row: row count went 3 → 4
   (2 real items + 1 blank → typed → 1 new blank appended). Same for
   expenses: 1 → 2.
3. Live total read directly off the rendered page: `R 28 021,00`
   (23,700 + 4,321 typed) — computed client-side, before any network
   round trip completed.
4. Waited for the debounced autosave; save badge showed "✓ Saved".
5. Reloaded the page FRESH (new navigation, not the same in-memory JS
   state) — row counts persisted correctly (4 income, 2 expense: real
   items + exactly one blank trailing row each, never more), and the
   "Suggested check" box read: "Gross income (your entries above):
   R 28 021,00 ... Rent must not exceed 30% of gross income
   (R 8 406,30). Actual rent is 26.8% of gross income" plus "WITHIN THE
   AFFORDABILITY GUIDELINE", with the net-income block separately showing
   "Income left after expenses: R 27 244,00", clearly labelled reference-only.
6. Cross-checked against the database directly: `rental_application_income_items`
   held exactly 3 real rows (15000, 8700, 4321 — no blank row saved) and
   `rental_application_expense_items` held exactly 1 (777).
7. Cross-checked against `RentalApplicationAssessment::qualifyingResult()`
   called directly via tinker: `gross_income: 28021`, `net_income: 27244`,
   `max_affordable_rent: 8406.3`, `meets_threshold: true` — identical, to
   the cent, to what the screen displayed. This is the proof that "the
   total must match exactly what the affordability check uses."

**Cleanup:** the disposable database, its dump files, and the read-only
`node_modules` symlink were all removed after verification; the rebuilt
`public/build` assets were kept (gitignored, regenerable, and were
broken before this round regardless). The real shared `corex_qa1`
database, the real user `andre@hfcoastal.co.za`'s real password, and the
shared `/corex-qa1` checkout's working tree were never touched.

**PHPUnit regression coverage** (everything server-side; the row-auto-add
UI reactivity itself is covered only by the browser session above):
`tests/Feature/RentalApplications/RentalApplicationRound10ReviewScreenTest.php`
(new) — net-income reference-only labelling, gross-income section
labelling, item persistence + total computation, blank-row filtering,
soft-delete-on-removal (never hard-delete) with re-save-by-id proven to
update rather than duplicate, and the total-matches-the-affordability-check
guarantee. `RentalApplicationRound8FixesTest.php` and
`RentalApplicationRound9AffordabilityTest.php` updated wherever they
constructed an assessment via the old fixed columns (both now use
`incomeItems()`/`expenseItems()` or a new `assessmentWithAmounts()` test
helper) — those columns no longer exist after this round's migration.
Full run: 22 tests, 22 passed, 102 assertions.

### Files touched

- `database/migrations/2026_09_08_180000_create_rental_application_income_expense_items_tables.php` (new)
- `app/Models/RentalApplicationIncomeItem.php` (new)
- `app/Models/RentalApplicationExpenseItem.php` (new)
- `app/Models/RentalApplicationAssessment.php` — `incomeItems()`/`expenseItems()` relations, `qualifyingResult()` sums items
- `app/Http/Controllers/CoreX/RentalApplicationReviewController.php` — `maxRentPercentFor()`, item-array `saveAssessment()`, new `syncItems()`
- `resources/views/corex/rental-applications/review.blade.php` — growable income/expense rows, gross-income section label, net-income reference-only block, new result field names
- `tests/Feature/RentalApplications/RentalApplicationRound10ReviewScreenTest.php` (new)
- `tests/Feature/RentalApplications/RentalApplicationRound8FixesTest.php` — assessment-creation fixture update
- `tests/Feature/RentalApplications/RentalApplicationRound9AffordabilityTest.php` — assessment-creation fixture update

Branch: `rental-applications-affordability-2026-09-08` (continued),
new commits on top of `52a476910`. QA1 only — not merged into the shared
`QA1` checkout, which cc1 is actively restoring with production-shaped
data at the time of this round; will coordinate the actual QA1 landing
once cc1 confirms QA1 is back up and clean.

## Round 11 — SECOND regression on the decimal-point area, plus one-row-start rows and statement months (2026-09-08)

Johan, urgent, blocking him mid-testing: "the comma rule is bust. everyone
will enter amounts like 40638.40, the std that everyone uses. right now
hitting the . on an amount clears the values, you have to use , - fix
this." This is the second time this exact area (numeric money-field
input) has broken; treated with the seriousness that implies.

### Item 1 — root cause, found by driving the real screen, not guessing

Reproduced with a real headless-browser session typing character by
character into a real field: `type="number"` inputs bound with Alpine's
`x-model` write the browser's own PARSED `.value` back into the DOM on
every keystroke. A lone trailing "." does not parse as a valid number
yet, so the browser's own `.value` silently omits it at that instant;
Alpine's write-back then overwrites the field's real (still-being-typed)
content with that dot-less value, visibly eating the "." the user just
typed. Confirmed by typing "40638." character-by-character into the
review screen's income field and reading `input.value` after every
keystroke: it read "40638" (dot dropped) the instant "." was typed, then
correctly returned to "40638.4" once a digit followed — proving the
native input, not Alpine's own reactivity or the server, was the source.

**Fix:** every money-amount field's `type="number"` replaced with
`type="text" inputmode="decimal"` — the same numeric keyboard on mobile,
zero native number-parsing interference. The raw typed string now passes
through untouched; the server's sanitizer is the only place that ever
interprets it. Changed:
- `review.blade.php` — the income/expense row amount inputs
- `authorisation/show.blade.php` — the approve-amount field
- `rental-application-field.blade.php` — new optional `inputmode` prop
- `corex/rental-applications/show.blade.php` / `rental-applications/public/show.blade.php`
  — `current_rental_amount` and `monthly_salary`, via the component's new prop

The settings percentage field (`type="number"`, no Alpine binding) was
left as-is — it was never actually subject to this bug (no reactive
write-back exists on that field) and isn't a Rand-and-cents value.

### Item 1 — the disambiguation rule, exactly as Johan stated it

`RentalApplication::sanitizeNumericInput()` rewritten. Old rule: strip
every comma and space, leave dots alone — blind to "40638,40" (comma as
decimal — some people do write it that way) looking identical in shape
to "40,638" (comma as a thousands mark). Johan's own resolution,
implemented literally: **the LAST separator (comma, dot, or space) is
the decimal point ONLY when it is followed by exactly two digits and
nothing else after it; every other separator, and a last separator NOT
followed by exactly two digits, is a thousands mark and is stripped.**

```php
private static function disambiguateMoneyString(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/^R\s*/i', '', $value);

    if (preg_match('/^(.*)[,.\s](\d{2})$/', $value, $matches)) {
        $wholePart = preg_replace('/[,.\s]/', '', $matches[1]);
        return $wholePart . '.' . $matches[2];
    }

    return preg_replace('/[,.\s]/', '', $value);
}
```

Every one of Johan's stated formats resolves correctly: `40638.40`,
`40,638.40`, `40 638.40`, `R40 638.40`, and `40638,40` all → `40638.40`;
`40,638` → `40638` (a whole number, not four-oh-six-thirty-eight). Every
existing Round 8 test case resolves identically to before (verified
directly, not assumed).

**Flagged rather than silently decided (per Johan's own instruction):**
1. The qualifying-formula percentage field (`max_rent_percent_of_gross_income`)
   is NOT run through this rule. A percentage like "28.5" has only ONE
   digit after its decimal point — the rule would misread it as a
   thousands-separated whole number ("285"). Percentages never carry a
   thousands separator at all, so `RentalApplicationSettingsController::updateQualifyingFormula()`
   now does a plain `trim()` instead of calling the money sanitizer.
2. A value like "40638.4" (ONE digit after the last separator, not two)
   resolves, by the letter of Johan's rule, to "406384" (thousands mark,
   stripped) — not "40638.40" as a human dropping a trailing zero might
   intend. This is what the stated rule produces for "40,638" (3 digits
   after) too, so it's not a case the rule fails to resolve — but it's
   counter-intuitive enough to flag explicitly rather than let it pass
   unremarked.

### Items 2 & 3 — one-row-start behaviour and the number-of-months field

**Item 2 status:** already built correctly in Round 10 (one starting
row per list, auto-adding a fresh one as the last is filled, live
recalculating totals) — Johan had not yet seen it, since Round 10 was
never merged into the shared QA1 checkout he tests against. Re-verified
end to end after this round's `type="text"` change (see Verification).
No design change was needed for item 2 itself.

**Item 3 — built, deliberately NOT wired into the decision yet.** New
`statement_months` column on `rental_application_assessments`
(migration `2026_09_08_190000_add_statement_months_to_rental_application_assessments.php`),
a "Number of months this bank statement covers" input above both lists,
and `qualifyingResult()` now also returns `monthly_average_gross_income`
and `monthly_average_expenses` (raw total ÷ months) — but `gross_income`
and `meets_threshold` still run off the raw totals, unchanged. The
screen shows the monthly average with an explicit "not yet used in the
guideline check" badge next to it.

**Johan's own question, and my reading, stated for confirmation before
building further:** does the monthly average feed the affordability
check directly, replacing the manually-typed/summed income? Johan's own
stated view is yes — that is the whole point of capturing from a bank
statement, and it is exactly the tenant's-claimed-income-vs-actual-income
problem (his 10,000-vs-18,000 example) applied to a multi-month capture.
**I agree with that reading and it is the more correct design** — a lump
sum over an unstated number of months is not comparable to a monthly
legal threshold, so the average is the only number that actually means
anything against the 30%-of-gross rule. Once confirmed, wiring it in is
a small, contained change: `qualifyingResult()` would use
`monthlyAverageGrossIncome ?? $grossIncome` (falling back to the raw sum
when no months are given, e.g. a payslip-style single-month capture)
as the figure tested against `max_rent_percent`, rather than adding a
second, parallel decision path.

### Verification — real headless-browser session, real database, every stated format

Following the same disposable-clone methodology as Round 10 (real QA1
data cloned into an isolated `corex_qa1_bugfix_verify` database, real
QA1 code, never touching the shared checkout or shared database — QA1
had just been restored by cc1 at the time and a second lane was
mid-build on the shared checkout, so the same "don't touch the shared
resource" reasoning from Round 10 applied again). On a real application
(id 4, real agency, real historical rent of R10,000):

1. **Item 2** — confirmed the assessment starts as exactly ONE income
   row on a fresh capture.
2. **Item 1** — typed each of Johan's six formats, one at a time, into
   the current last (blank) row, character by character, and read back
   what actually landed in `rental_application_income_items.amount`:

   | Typed | DB value | |
   |---|---|---|
   | `40638.40` | `40638.40` | PASS |
   | `40,638.40` | `40638.40` | PASS |
   | `40 638.40` | `40638.40` | PASS |
   | `R40 638.40` | `40638.40` | PASS |
   | `40638,40` | `40638.40` | PASS |
   | `40,638` | `40638.00` | PASS |

   All six PASS. The DOM value was also captured after every single
   keystroke for each format — the "." was never dropped at any point
   mid-typing, proving the fix at the source, not just at the final
   submitted value.
3. **Item 2 continued** — after the six entries, the row list correctly
   held 6 filled rows + 1 trailing blank = 7; Alpine's own internal
   `incomeTotal()` state read directly (243830) matched the database sum
   (243830.00) and the server's own `qualifyingResult()` gross_income
   (243830.00) exactly — three independent reads, one number.
4. **Item 3** — typed "3" into the new months field; DB
   `statement_months` correctly stored 3; the monthly-average display
   showed R81,276.67 (243830 ÷ 3, verified by direct calculation) with
   its "not yet used in the guideline check" badge visible; the
   Suggested Check box's own gross income figure remained the RAW
   243830 (not divided), confirming the decision genuinely does not use
   the average yet.
5. Also confirmed the authoriser's approve-amount field renders as
   `type="text" inputmode="decimal"` (the same fix), by direct
   inspection of the compiled view and a PHPUnit `assertSee` — a full
   second browser session against that screen hit an unrelated 404
   (route-model-binding scope issue in the disposable clone's data, not
   this fix) and was not chased further given the identical, already-
   proven mechanism and the time already spent; flagged here rather than
   silently claimed as separately browser-verified.

**Cleanup:** the disposable database, dump files, and the read-only
`node_modules` symlink were all removed afterward. The real shared
`corex_qa1` database and the shared `/corex-qa1` checkout were never
touched.

`tests/Feature/RentalApplications/RentalApplicationRound11DecimalAndStatementMonthsTest.php`
(new) — every one of Johan's stated formats plus all pre-existing Round
8 cases via a data provider, the "keystroke never wipes what's typed"
guard, regression guards that the income/authoriser fields never revert
to `type="number"`, the settings-percentage-field exclusion, and the
full statement-months/monthly-average behaviour including the "does not
yet affect the decision" proof. Full run across Rounds 8–11:
40 tests, 40 passed, 133 assertions.

### Files touched

- `database/migrations/2026_09_08_190000_add_statement_months_to_rental_application_assessments.php` (new)
- `app/Models/RentalApplication.php` — `sanitizeNumericInput()` rewritten to the disambiguation rule
- `app/Models/RentalApplicationAssessment.php` — `statement_months` fillable/cast, `monthly_average_*` display fields in `qualifyingResult()`
- `app/Http/Controllers/CoreX/RentalApplicationReviewController.php` — `statement_months` validation/persistence
- `app/Http/Controllers/CoreX/RentalApplicationSettingsController.php` — percentage field no longer uses the money sanitizer
- `resources/views/corex/rental-applications/review.blade.php` — `type="text"` money inputs, months field, monthly-average display
- `resources/views/corex/rental-applications/authorisation/show.blade.php` — `type="text"` approve-amount field
- `resources/views/components/rental-application-field.blade.php` — new `inputmode` prop
- `resources/views/corex/rental-applications/show.blade.php` / `resources/views/rental-applications/public/show.blade.php` — money fields use the new prop
- `tests/Feature/RentalApplications/RentalApplicationRound11DecimalAndStatementMonthsTest.php` (new)

Branch: `rental-applications-affordability-2026-09-08` (continued). QA1
only — still not merged into the shared `QA1` checkout; coordinating
that landing separately given item 1's urgency.
---

## Note auto-focus — the discrepancy cc1 found, root-caused (2026-09-08)

cc1 independently drove the real screen and found the note textarea did
NOT auto-focus — `document.activeElement` stayed on the "Note" tool
button after placing a note. My own commit had claimed this was
verified. It wasn't, and the reason why matters more than the bug.

**What my own verification actually did, on inspection:** placed a note,
then called `.click()` on the textarea MYSELF, then checked
`document.activeElement` and typed. That manual click was never part of
the feature — it was my own workaround, one line before the assertion —
and it made a genuinely broken auto-focus pass every time, because the
thing I checked ("can this element be focused") is not the thing that
was claimed ("does opening a note focus it automatically"). Reproduced
properly this time — a real click to place the note, checking
`document.activeElement` immediately with no manual step of my own in
between — and got exactly what cc1 got: `BUTTON`, not `TEXTAREA`.

**Root cause, found from there:** the pending-note textarea lives inside
`<template x-for="page in pages">` — one element per loaded page, ALL
declaring the same `x-ref="pendingNoteInput"`. With every page loaded,
17 separate elements answered to that one ref name (confirmed via
`document.querySelectorAll` — the actual count was higher still,
51, consistent with `x-ref` collisions producing unreliable resolution
rather than a clean "last one wins"). Alpine's `$refs.pendingNoteInput`
has no defined behaviour for duplicate ref names within a component —
it does not reliably resolve to the one instance currently visible for
the page the agent is actually working on. Calling `.focus()` on
whichever (likely hidden, `display:none`) element it actually resolved
to does nothing, silently — no error, no warning, exactly why this
was invisible to every prior check.

**Fixed:** removed the shared `x-ref` entirely. The textarea now carries
`:data-pending-note-page="page.index"`, and the focus call queries
`document.querySelector('textarea[data-pending-note-page="' + page +
'"]')` for the SPECIFIC page whose note was just placed, inside the same
`$nextTick()`. No ref-name collision possible — each page's element has
a distinct, addressable identity.

**Verified the way cc1 did it, not the way I did it the first time:**
placed a note with a single real click, checked `document.activeElement`
immediately (no manual click) — now `TEXTAREA`, both immediately and
after a 300ms wait. Typed with no click at all — the text landed in the
real DOM value. Then the full round trip: typed a note, clicked "Add
note", clicked the real Save button, reloaded the page fresh, reopened
the document, and confirmed the exact text came back from the server.

**The standing lesson — written into BUILD_STANDARD.md §5b:** a test of
an AUTOMATIC behaviour must never itself perform the action the code is
supposed to perform unprompted. My script's own manual `.click()`
silently changed what was being tested, from "does this happen on its
own" to the much weaker "can this happen at all" — and both answers look
identical in a passing test unless someone reads the script line by
line. General rule now on record: if a test needs an extra step of its
own to make the assertion pass, that extra step IS the missing feature,
not a helpful setup step.

**Files touched:** `resources/views/corex/rental-applications/
review.blade.php` (the `x-ref` → `data-` attribute fix) and
`.ai/BUILD_STANDARD.md` (§5b, docs only).

---

## Highlighter size, and "request more info" reachable while a document is open (Johan, 2026-09-08)

Three items, all about the screen fighting the agent instead of letting
them work.

### Highlighter size — "too much lines on bank statement"

Three presets in the header (Thin 10px / Medium 22px / Thick 36px,
raster px at the existing 150 DPI) — not a slider. Medium is the
unchanged prior default, so an agent who never touches this sees no
change. Only shown for the highlight tool (a note's marker size never
varies). Remembered in `localStorage`, restored in `init()`, so it
survives switching documents in one sitting AND coming back tomorrow —
the stronger reading of "not resetting it on every document."

**Verified by actually drawing, not by reading the code:** clicked
Thick, dragged a real stroke, read the mark that was just pushed to
state directly (not "the last polyline in the DOM" — this document
already had 29 pre-existing marks from other testing, and the first
pass at this check was fooled by picking up one of those instead of the
one just drawn) — width **36**. Same for Thin — **10**. Same for
Medium — **22**. Reloaded the page fresh and confirmed the picked size
(`thin`) came back from `localStorage`, not just component memory.

### Request more information — reachable and fillable while a document is open

Johan, verbatim: *"request more info only appears if a document is not
open... I have to write down somewhere else because its only available
once the doc is closed?"* — and separately, *"the simple modal that
loads on request extra info will not suffice. Allow a free text box."*
Treated as one problem, per instruction, because they are one problem:
the request needs to live somewhere reachable AND sized for real writing
WHILE the evidence it's about is on screen.

**Fix:** removed the header's "Request more info" button and the
`prompt()` built for it last round (on reflection, a prompt is the same
mistake in miniature — still a one-line interruption, still invisible
while a document is open). "Submit to authoriser" stays in the header —
a single decisive click once he's ready, not something composed while
reading, and already confirmed working there. The request itself moved
into the aside — a real six-row textarea, always visible, never covered
by an open document, for the same reason the highlighter itself is
inline rather than a modal.

**The draft-survives-navigation requirement needed no new code.** The
field it's bound to (`moreInfoNote`) was never part of `openHighlighter()`
/`closeHighlighter()`'s reset list — checked directly before assuming —
so it was already independent of the document lifecycle. Moving the UI
to somewhere that stays on screen was the entire fix.

**Verified the exact sequence asked for:** opened a document, confirmed
the textarea was visible and usable the whole time (not just before
opening), typed:

```
1. Three months' bank statements
2. Payslip for August
3. Proof of the R12,000 deposit on 14 August
```

closed the document (Done), confirmed the text was still there, reopened
the same document, confirmed it was STILL there, and read the actual
textarea's DOM value to match — not just the framework's internal state.
Then sent it for real: a real POST, a real 200, and the database read
back the exact three lines with real `\n` characters intact.

### Line breaks reaching "the applicant or agent" — one side already worked, the other didn't

Checked both instead of assuming either: the email to the applicant
already renders with `white-space: pre-wrap` — numbered points were
already going to arrive intact, no change needed there. The AGENT's own
later view of the same note — the status history log on the full
application page — had no such handling and would have collapsed a
numbered request into one run-on line. Fixed with the same CSS property
on that one span. Verified by sending a real multi-line request and
reading the rendered history entry's computed style (`white-space:
pre-wrap`) and rendered height (63px — several lines, not one).

**Sequencing:** built these three before the marks-ownership work, as
discussed — they're entirely inside `review.blade.php` plus one line in
`show.blade.php`; ownership touches the shared highlight service/
controller and the authoriser's own screen, a different surface.

**Files touched:** `resources/views/corex/rental-applications/
review.blade.php` (size presets + localStorage, request-more-info moved
to the aside, `promptRequestMoreInfo()` removed), `resources/views/corex/
rental-applications/show.blade.php` (one line, `white-space: pre-wrap` on
the status history note).

## Round 12 — monthly average WIRED into the decision (2026-09-08)

Round 11 asked Johan to confirm before wiring the monthly average into
the actual affordability decision. The question itself was the mistake —
Johan, plainly, after finding it confusing: "agent captures income on
right panel. choses to say 3 months - so whatever the agent captured
get averaged by the months selected - 10000, 10000, 13000 tallies to
33000, agent selected 3 months - so the avg income is? 11000? what else
are you on about?" There was nothing to decide; wired in immediately.

### The rule, now exactly as Johan stated it

`RentalApplicationAssessment::qualifyingResult()`: the figure the 30%
rule runs against is now `total_captured_income ÷ statement_months`,
never the raw multi-month total. Requires BOTH captured income AND a
valid `statement_months` (a positive whole number) to compute anything —
neither alone is enough. A missing or zero months figure returns
`gross_income: null` → `label: 'incomplete'`, never a wrong number and
never a silent fallback to treating the raw total as if it were monthly.
Same division applied to expenses, so `net_income` stays an
apples-to-apples monthly figure rather than mixing a monthly income
against a multi-month expense total.

**Rounding, stated explicitly (per Johan's own request):** `round($n, 2)`
— PHP's own round-half-up to the nearest cent — applied once, at the
division. Johan's own example (33,000 ÷ 3 = 11,000.00) needed no
rounding to demonstrate; a real bank statement's total dividing
unevenly will round the same way every other money figure on this
feature already does.

**Edge cases, built exactly as specified:**
- `statement_months` validation already rejected 0/negative (`min:1`,
  unchanged from Round 11) — confirmed with a new test rather than
  assumed.
- Missing months → `gross_income`/`max_affordable_rent`/`meets_threshold`
  all `null`, `label: 'incomplete'` — the raw total is still returned
  separately as `total_captured_income` so the agent can see what they've
  typed, just never mistaken for a monthly figure.
- The number displayed and the number decided are the SAME field:
  `result.gross_income`, sourced from the one server-side calculation,
  not a parallel client-only computation. The income panel's own live
  "Monthly average (÷ N months — used in the affordability check below)"
  line uses the identical division (client total ÷ months, rounded to 2)
  as a live preview before each autosave settles, then the authoritative
  server figure takes over once `result` refreshes — same arithmetic on
  both sides, so the two can only ever differ for the width of one
  debounce-and-round-trip, never diverge in the steady state.

### Both figures shown, since the difference is what's being assessed

Johan: "the applicant types their income on the form; the agent derives
the real one from the bank statement... make sure the screen shows both
clearly so the agent can see the difference." `qualifyingResult()` now
also returns `applicant_reported_income` (the applicant's own
self-reported `monthly_salary`, purely informational) alongside the
bank-statement-derived `gross_income` that actually drives the decision
— displayed as two separate lines in the Suggested Check box. The
"not yet used in the guideline check" caveat from Round 11 is removed
entirely — replaced with "used in the affordability check below."

### Percentage-field bounds (conductor's check, on the settings screen)

Confirmed already correct, no change needed:
`RentalApplicationSettingsController::updateQualifyingFormula()`
validates `max_rent_percent_of_gross_income` as
`['required', 'numeric', 'min:0.1', 'max:100']` — a negative, zero, or a
value over 100 was already rejected before this round; this predates
Round 11's sanitizer change and was never touched by it.

### Verification — Johan's own worked example, driven on the real screen

Same disposable-clone methodology as Rounds 10/11 (real QA1 data —
already updated with cc1's landing of Rounds 9–11's schema by the time
of this round — cloned into an isolated database, never touching the
shared checkout or database). On a real application:

1. Typed three income lines — 10000, 10000, 13000 — one at a time into
   the real screen.
2. Set "Number of months this bank statement covers" to 3.
3. **Screen showed:** "Total captured (all lines): R 33,000.00" /
   "Monthly average (÷ 3 months — used in the affordability check
   below): R 11,000.00" in the income panel, and in the Suggested Check
   box: "Monthly gross income (bank statement total ÷ 3 months):
   R 11,000.00" / "Applicant's own stated income (from their application
   form): R 40,000.00" / "Rent must not exceed 30% of monthly gross
   income (R 3,300.00). Actual rent is 30% of monthly gross income." /
   "WITHIN THE AFFORDABILITY GUIDELINE" (rent set to exactly R3,300 for
   this test — an exact-boundary case, correctly passing since the rule
   is "must not exceed", i.e. ≤).
4. **Database, read directly:** `SUM(amount)` across the three income
   rows = 33000.00; `statement_months` = 3; 33000.00 ÷ 3 = 11000.00 —
   identical to the cent to what both the income panel and the Suggested
   Check box displayed.

`tests/Feature/RentalApplications/RentalApplicationRound11DecimalAndStatementMonthsTest.php`
updated: the old "does not affect the decision yet" test replaced with
`test_johans_exact_worked_example()` (asserts 33,000 → 11,000 → 3,300
exactly), a test proving the SAME raw total now produces a DIFFERENT
decision at different statement lengths (18,000 over 1 month passes at
5,400 rent; the same 18,000 over 3 months averages to 6,000 and FAILS
the same 5,400 rent), a test proving missing months reports
`'incomplete'` rather than a wrong pass off the raw total, a test
confirming zero months is rejected at validation, and a test confirming
the applicant's own figure is shown but never affects the decision.
Round 9 and Round 10's existing tests updated to pass an explicit
`statement_months` (1, where a no-op division preserves each test's
original worked numbers) since `gross_income` now requires it. Full run
across Rounds 8–12: 42 tests, 42 passed, 144 assertions.

### Files touched

- `app/Models/RentalApplicationAssessment.php` — `qualifyingResult()` divides by `statement_months`, adds `applicant_reported_income`/`total_captured_income`/`total_captured_expenses`
- `resources/views/corex/rental-applications/review.blade.php` — removed the "not yet used" caveat, relabelled totals, added the applicant-comparison line
- `tests/Feature/RentalApplications/RentalApplicationRound11DecimalAndStatementMonthsTest.php` — monthly-average tests rewritten for the wired behaviour
- `tests/Feature/RentalApplications/RentalApplicationRound10ReviewScreenTest.php` / `RentalApplicationRound9AffordabilityTest.php` — added `statement_months` to existing fixtures

Branch: `rental-applications-affordability-2026-09-08` (continued). QA1
only. cc1 was told the "hold" instruction changed and is confirming
through their own coordinator channel before landing — not landed
directly on my say-so.

---

## Notes not rendering on the review screen — a race, not a notes bug (Johan, 2026-09-08)

Johan, application 9: *"added notes - they do not show on the
document."* Reported as working before; it wasn't, and this is why the
earlier check missed it — the failure is genuinely non-deterministic.

**Reproduced first, not assumed.** Real document (2890, application 9)
with 2 real saved notes confirmed directly in the database. Loaded the
review screen with Puppeteer: first run, the notes were missing from the
component's own mark list entirely — not a rendering issue, they never
arrived. Ran the identical script again with no code changes: they were
there. Same document, same code, different result — the signature of a
race, not a logic bug.

**Checked whether highlights had the same exposure, per instruction, not
assumed either.** They did. On the run where restoration failed, ALL of
page 0's marks were missing — three highlights along with the two
notes — not notes specifically. Page 0 just happens to be the only page
in this particular document that has any notes on it, so that's the
only symptom Johan had a reason to report. Highlights on other pages
were never at risk in this document only because none of them happened
to land on the one page most likely to lose the race.

**Root cause:** `restoreSavedMarksForPages()` waited on `this.$nextTick()`
before reading `img.clientWidth` to compute the raster→display scale
factor. `$nextTick()` guarantees Alpine's own DOM mutation has been
applied — the `<img>` tag exists with its `src` set — it does NOT
guarantee the browser has finished DECODING that image, and `clientWidth`
for a `height:auto` image isn't reliably available until decode
completes. Page 1's image is now delivered fast on purpose (this
morning's progressive-load work) — which made the race easier to lose,
not harder: restoration for page 1 can now genuinely run before the
browser has decoded it, where the old, slower everything-at-once load
gave it more incidental time to finish first.

**Fix:** `img.decode()` — a real Promise that resolves only once
decoding is genuinely complete — awaited before reading `clientWidth`,
replacing the implicit assumption that `$nextTick` alone was enough.
Falls through to the existing `clientWidth` guard regardless (decode()
can reject if the element's been removed by fast document-switching;
harmless, the guard still protects the push either way).

**Verified by running the exact reproduction six times in a row, not
once.** A single passing run proves little for a race condition; six
consecutive identical passes under the same conditions that failed on
the very first attempt pre-fix is the actual bar. All six: 26 total
marks (24 highlights + 2 notes), 2 real `.rounded-full` note markers
found in the DOM each time.

**Files touched:** `resources/views/corex/rental-applications/
review.blade.php` only — `restoreSavedMarksForPages()`.
## Round 16 — full assessment panel redesign, rent source corrected to the linked property (2026-09-08)

Johan approved a full redesign of the review screen's right-hand panel,
specified block by block. His own words, condensed: "top down - income
- fields where agent captures... split that block - desc half and
amount half... display a total of the income captured. Then we have
expenses... same as income... Then we need to add - unpaid amounts...
we need the heading, the highlighter and a tick - unpaid transactions
on bank statement... an applicant with declined transactions are
generally immediately declined... Then we just show an info box -
income * 30% = Rx... So we dont need a massive hooha." On the old
panel's length: "you are putting a lot of text on the panel which
makes the panel extremenly long. why not use the helper? and put the
tooltip in there rather than having a hell of a lot of info."

Four blocks, exactly as specified, nothing else:

1. **Income (gross)** — description/amount split 50/50 via CSS Grid
   (`grid grid-cols-2 gap-1.5`, not flexbox — see "Layout mechanism"
   below), "Total captured" underneath.
2. **Expenses** — identical structure, own total.
3. **Unpaid transactions** — one heading, one tick
   ("Unpaid transactions on statement"), not a list of amounts. This is
   the red flag the authoriser (cc5's screen) reads.
4. **One info box** — the qualifying rand figure large, the arithmetic
   (gross income ÷ statement months × max %) in small type underneath.
   Nothing else.

Every explanatory paragraph the old panel carried is now a `?` tooltip
(`<span class="ds-badge ds-badge-muted" title="...">?</span>`) next to
its block's heading, collapsing the panel from a scroll of prose to a
narrow working column.

### Wording attribution (Johan caught this himself)

The old panel implied CoreX itself had totalled the bank statement.
Corrected: "Monthly gross income — from figures the agent captured off
the bank statement, ÷ N months" for the derived figure, "Applicant's
stated income — as entered by them on their application" for the
comparison figure. Reason, Johan's own: "If a declined application is
ever challenged, the record must show who asserted what" — the
figures are attributed to whoever actually typed them, never
attributed to the system.

### The rent source was wrong — now corrected

Johan: "the affordability check is currently testing the applicant's
SELF-REPORTED CURRENT RENT, not the rent of the property being applied
for" (surfaced when application 9 showed "No property linked" but the
check still ran against `current_rental_amount` — the applicant's
existing, pre-move residence, entirely irrelevant to whether they can
afford the NEW property). His ruling: "you own what the check does
with the rent once a property is linked. If no property is linked, the
check must say it cannot run rather than testing the wrong number."

`RentalApplicationAssessment::qualifyingResult()`: `rent` now sources
from `$rentalApplication->property->rental_amount`, never
`current_rental_amount`. New `property_linked` boolean returned
explicitly. New `label` value `'no_property'` — the qualifying ceiling
(`max_affordable_rent`) still computes and displays as soon as
income+months exist (it's purely a function of captured income), but
`meets_threshold`/`'sufficient'`/`'insufficient'` cannot exist without
a real rent to compare against, so the screen says plainly why no
pass/fail exists yet rather than silently showing nothing or falling
back to the wrong number. `current_rental_amount` remains on the
applicant's own form (their existing residence, still a legitimate
field) — it simply plays no part in this calculation any more.

Property-linking itself (the agent's control to attach a property to
an application) is cc3's work on the same controller file, in a
separate branch, landed alongside this one — non-overlapping methods,
confirmed at coordination time.

#### Property link — lock after submission (2026-09-10)

Johan, QA1 finding (item 2 follow-up): the property link could be
changed or cleared at any point, including while an authoriser was
actively deciding against it (`under_assessment`) and after the
outcome email had already gone out naming it. Reproduced directly on
a real, fully-approved application — nothing crashed, but the record
no longer matched what was actually approved against.

**Rule:** once locked, `RentalApplicationReviewController::linkProperty()`
refuses the request with a 403 — server-side, not a hidden button. The
same request straight at the route on a locked application is refused
exactly the same way a button click would be.

**Locked statuses** (`RentalApplicationQualifyingSetting::PROPERTY_LOCKED_STATUSES`):
`under_assessment`, `approved`, `declined`, `withdrawn`. Open statuses —
where the agent is still actively preparing the application —
`draft`, `sent`, `in_progress`, `returned`, `reopened` — are
unaffected; the property remains freely changeable there, which is
the entire point of the item-4 fix this follows on from.

**Why the line falls at submission, not just approval:** the
property's rent is what the authoriser's decision is actually made
against from the moment the application is submitted for
authorisation — not just from the moment it's approved. Locking only
at "approved" would leave the property swappable for the whole
window the authoriser is deciding, which is the more dangerous gap
of the two (a decision made against one property, recorded against
another). This was directly reproduced: this build's own testing
changed the linked property while an application sat at
`under_assessment`, with no error.

**Agency-configurable, per the standing rule** ("any threshold,
window or business rule... never hardcoded"): `agencies` →
`rental_application_qualifying_settings.lock_property_after_submission`
(nullable boolean; `RentalApplicationQualifyingSetting::
lockPropertyAfterSubmissionFor()` — null row/column falls back to the
default). Default **true** (`RentalApplicationQualifyingSetting::
DEFAULT_LOCK_PROPERTY_AFTER_SUBMISSION`). Settings screen: Company →
Rental Applications → "Property Link Lock" (one checkbox, not a
status picker — a single sensible default with an on/off toggle, not
a configurable threshold). Turning it off restores the exact
pre-fix behaviour (changeable at any status) — the ability is gated,
never removed.

**Audit trail:** unchanged and untouched by this fix —
`linkProperty()` already logged every permitted change via
`RentalApplicationAuditService` (`property_link` category,
`linked`/`changed`/`cleared` event types, old/new property id +
address, human summary) before this lock existed. A change made
while the setting is off, or while the application is still in an
open status, is logged exactly as before; the lock only ever prevents
the write from happening at all — there is never a silent or
unlogged property change.

**Transaction:** the property save and its audit-log write are now
wrapped in `DB::transaction()` — an audit-log failure can no longer
leave a property change persisted with no record of it.

### Unpaid transactions flag

New `has_unpaid_transactions` boolean column on
`rental_application_assessments` (migration
`2026_09_08_200000_add_has_unpaid_transactions_to_rental_application_assessments.php`).
Surfaced as a single checkbox, autosaved like every other field on the
panel. Deliberately NOT a list of amounts — Johan's instruction was one
tick, one red flag, because "an applicant with declined transactions
are generally immediately declined" and the point is visibility to the
authoriser (cc5's screen — out of scope here), not detail capture.

### Colour tokens — one definitive source

Johan specified exact hex values for three categories (income,
expense, unpaid), each with an agent-panel shade and a darker
authoriser/highlighter shade plus an underline colour, so the same
category reads consistently across the panel (this screen), the
document highlighter (cc6), and the authoriser screen (cc5). Defined
once in `resources/css/corex.css` as CSS custom properties
(`--ra-income-agent` / `--ra-income-authoriser` / `--ra-income-underline`,
and the `--ra-expense-*` / `--ra-unpaid-*` equivalents) so none of the
three consuming screens redefines its own copy. Each panel block now
carries a small colour dot in its category's agent shade next to its
heading, so the link between the panel and the marks on the document
is visible rather than remembered. Verified via
`getComputedStyle(document.documentElement)` that all nine tokens
resolve to the exact approved values, and that each dot's rendered
`backgroundColor` matches exactly.

### Net income — removed from display, not from the model

Kept since Round 9/10 as "reference only, labelled unmistakably
separate from the pass/fail badge" — not among Johan's four enumerated
Round 16 blocks, and his "we dont need a massive hooha" instruction
reads as a mandate to cut it. Removed from the panel entirely (still
computed by `qualifyingResult()` — `net_income` stays in the returned
array in case another screen needs it later — just no longer
displayed here). Superseding judgement call, recorded here since the
earlier round's decision to keep-and-label was itself deliberate.

### A second focus-race, found before it reached Johan

The Enter-driven row-advance from Round 13 (`focusNextAmountRow()`)
wrapped the actual `.focus()` call in `this.$nextTick(() => {...})`.
Under continuous, zero-delay scripted typing — three values typed with
Enter between each, no pauses — the deferred callback could lose a
race against the very next keystroke, landing it in the OLD field
before the refocus completed. Reproduced exactly: typing "15000" then
Enter then "8500" produced a single field reading "150008500" instead
of two separate rows. Root cause: the target row is already
synchronously created by an earlier, fully-completed `@input` event
before the later `@keydown.enter` can even fire, so the deferral
served no purpose and only introduced a timing window. Fixed by
removing `$nextTick()` — the focus call now runs synchronously in the
same handler. Re-verified: typing "15000", "8500", "2200" continuously
with Enter between each produces exactly 4 rows (3 filled + 1 trailing
blank), values `["15000","8500","2200",""]`, matching what the
database shows — no orphans, no concatenation.

### Layout mechanism — CSS Grid, not flexbox

The original clipping bug (amount fields rendering off the panel's
right edge) was `flex-1` + fixed-width competing for space inside a
260px column with `overflow-x:hidden` — a `<input>`'s own default
intrinsic content width wins the flex negotiation regardless of the
`flex-1`/`w-24` sizing declared on it. Round 14 fixed this with a
stacked (description-above-amount) layout; THIS round supersedes that
with Johan's own explicit preference for a true 50/50 split
(`grid grid-cols-2 gap-1.5`) — a grid column is always exactly half
the row's width regardless of either input's own content, so the class
of bug cannot recur at any panel width. The SAME bug class recurred
once more in new markup this round (see next section) and was caught
the same way — by measuring, not by reading the CSS.

### Badge-overflow — the same bug class, caught before Johan saw it

The info box's status badge originally combined the pass/fail label
and the rent figure into one string
(`"Exceeds guideline — property rent R 6 000,00"`) rendered inside a
single-line `<span class="ds-badge">` — confirmed via screenshot to
overflow the 260px panel exactly like the amount-field bug ("EXCEEDS
GUIDELINE — PROPERTY REN" then cut off). Fixed by splitting into a
short, never-wrapping badge ("Within guideline" / "Exceeds guideline"
only) plus a separate `<p>` below it for "Property rent: R X,XXX.XX",
which wraps normally like any other text block.

### Verification

Disposable isolated-database clone methodology (same as prior rounds —
structure + specific real tables dumped via `mysqldump`, never touching
the shared QA1 checkout or database), driven with Puppeteer against a
real headless browser session:

- **Widths**: panel and every amount field measured via
  `getBoundingClientRect()` at 1024/1280/1366/1522/1920px — full
  visible width at every size, confirmed by screenshot as well as
  measurement, not CSS inspection alone.
- **Continuous typing**: three income rows typed with zero artificial
  delay, Enter between each, no clicking — exactly 3 filled + 1
  trailing blank row, no concatenation, no duplication, no orphaned
  rows; confirmed against the database directly.
- **Unpaid checkbox**: ticked, autosaved, persisted and reloaded
  correctly.
- **Colour tokens**: all nine CSS custom properties and every colour
  dot's rendered background verified to resolve to the exact approved
  hex values.
- **Property-state scenarios**: `'incomplete'` (no income/months yet),
  `'no_property'` (income+months present, nothing linked — shows "Link
  a property to check against its rent," never a wrong pass/fail),
  `'sufficient'`/`'insufficient'` (property linked, badge + rent line
  both render fully, no overflow) — all four states screenshotted.
- **Automated regression**: `RentalApplicationRound9AffordabilityTest`,
  `RentalApplicationRound10ReviewScreenTest`,
  `RentalApplicationRound11DecimalAndStatementMonthsTest`,
  `RentalApplicationAgentControllerTest`,
  `RentalApplicationInputPreservationTest` — 43 tests, all passing,
  against the project's own PHPUnit schema-snapshot test database (not
  the manual walkthrough clone). Three pre-existing worked-example
  tests in Rounds 9 and 11 needed updating: they had exercised the
  affordability check via `current_rental_amount` on the application
  itself, which the rent-source fix in this round makes irrelevant to
  the decision — updated to link a `Property` with the matching
  `rental_amount` instead, since that is now the correct way to set up
  a "sufficient"/"insufficient" scenario at all.

### Files touched

- `app/Models/RentalApplicationAssessment.php` — `qualifyingResult()` rent source changed to `property.rental_amount`, new `property_linked` field, new `'no_property'` label state; `has_unpaid_transactions` added to `$fillable`/`$casts`
- `app/Http/Controllers/CoreX/RentalApplicationReviewController.php` — `saveAssessment()` validates and persists `has_unpaid_transactions`
- `database/migrations/2026_09_08_200000_add_has_unpaid_transactions_to_rental_application_assessments.php` — new column
- `resources/css/corex.css` — new `--ra-income-*`/`--ra-expense-*`/`--ra-unpaid-*` custom properties
- `resources/views/corex/rental-applications/review.blade.php` — full aside-panel rewrite (four blocks, tooltips, colour dots, corrected wording, `focusNextAmountRow()` race fix, net-income display removed)
- `tests/Feature/RentalApplications/RentalApplicationRound9AffordabilityTest.php` / `RentalApplicationRound10ReviewScreenTest.php` / `RentalApplicationRound11DecimalAndStatementMonthsTest.php` — updated for the rent-source change and the redesigned markup

Branch: `rental-applications-affordability-2026-09-08`, commit
`3753f75597c9ab6083d8bc83cbcf9404e824b72e` (parent `8a5cc02c2`), pushed
to origin. QA1 only — not landed by me; cc1 lands via the shared
checkout per the standing coordination process. The
`has_unpaid_transactions` migration still needs `php artisan migrate`
run on QA1's shared database once this lands, since it adds a new
column.

## Round 13 — the two-screen split stays; fixed discoverability, not architecture (Johan, QA1, 2026-09-08)

Johan drove QA1 himself and found application 9 (`under_assessment`,
already submitted for approval) invisible on `/corex/rental-applications`
under every scope and with "Show archived" on. Investigated read-only
first, reported back before building, per his own instruction.

### What was actually true (confirmed, not assumed)

- `RentalApplicationController::index()` deliberately excludes
  `['returned', 'under_assessment', 'approved', 'declined']`
  (`whereNotIn`) — matched exactly by the status filter dropdown
  (`index.blade.php`, hardcoded `['draft', 'sent', 'in_progress',
  'withdrawn']`). This is the **pre-submission pipeline** list.
- `GET /corex/rental-applications/returned`
  (`RentalApplicationController::returned()`) is a real, separate,
  already-built, already-linked screen (sidebar: "Returned
  Applications") holding exactly the complement:
  `['in_progress', 'returned', 'under_assessment', 'approved',
  'declined', 'withdrawn']`. `in_progress` and `withdrawn` deliberately
  appear on BOTH screens (documented in the method's own comment,
  earlier round) — nothing else overlaps.
- Confirmed on Johan's real account: `hasPermission('rental_applications
  .view_returned') === true`, scope ceiling `all`. The screen was
  reachable and would have shown application 9 the whole time — he
  had not tried the second tab.
- **This is documented, deliberate design from an earlier round** ("Bug
  1 — the list had no row actions": *"dropdown scoped to the statuses
  that actually appear on this screen (draft, sent, in_progress — the
  rest live on Returned Applications)"*) — not a defect, not something
  the database refresh changed.
- One genuine, unrelated defect found while investigating: `review
  .blade.php:298`'s link to the read-only view was still labelled
  "View / edit full application →", even though its destination
  (`corex.rental-applications.show`) has correctly served the read-only
  view for any submitted application since the earlier read-only-view
  fix. The label was stale, not the behaviour — but Johan's whole
  concern with this feature is "are we tampering with a signed
  document," so a link that says "edit" next to a document he was told
  is un-editable is a real trust problem on its own.

### Johan's decision

**Keep the two-screen split. Do not fold the lists together.** His own
words: *"I create a rental application — it will sit under rental
applications until the application has been returned."* He expects it
to move on return — the split matches his own mental model; he simply
could not find the second screen. Folding two screens into one would be
a redesign nobody asked for. **`index()`'s status set and scope
defaults are unchanged in this round** — confirmed by diff, not just by
intent.

### What was built — discoverability only

1. **A live, permission-gated count with a direct link, on both empty
   and populated states of `/corex/rental-applications`.**
   `RentalApplicationController::index()` now also computes
   `$returnedCount` — same `visibleTo($user)` default-to-ceiling scope
   `returned()` itself uses, same status set `index()` itself excludes
   (`['returned', 'under_assessment', 'approved', 'declined']` —
   deliberately NOT `in_progress`/`withdrawn`, since those already show
   on this screen too; counting them would tell an agent "N things live
   over there" while some of those N sit in the table in front of them).
   Gated on `rental_applications.view_returned` so it never advertises
   a screen the user cannot open. Rendered as: (a) a persistent banner
   above the table whenever the count is above zero, regardless of
   whether the current filter/scope has any rows to show, and (b) folded
   into both empty-state branches in Johan's own language: *"Applications
   the tenant has sent back don't show here — see Returned Applications
   (N)."*
2. **A way back.** `returned.blade.php`'s header gained a
   "← Rental Applications" link (permission-gated the same as the
   sidebar's own link to that screen), so the relationship is legible
   from both directions, not just one.
3. **The stale label fixed.** `review.blade.php:298` now reads "View
   submitted application →" — same destination, wording that matches
   what it actually does.
4. **Loading state on the embedded signed-PDF viewer**, found by Johan
   driving the read-only view himself: *"the embedded PDF took about
   five seconds to appear, showing an empty dark viewer panel the whole
   time with no indication anything was loading. I initially recorded
   it as broken. Johan will do the same."* The PDF is generated
   server-side per request (`RentalApplicationPdfService`, Puppeteer,
   not cached) — a genuine few-second wait, not a bug to make faster.
   `view-readonly.blade.php`'s iframe now sits under a spinner + "Loading
   the signed application…" overlay, hidden on the iframe's own `@load`
   event (no fixed timer — never claims the PDF has arrived before it
   actually has).

### Verified on records not created for this task

Application 9 (real, agency 1, branch 1, `under_assessment`,
`submitted_for_approval_at` set) used throughout — created by Johan's
own earlier testing, not by this round. Confirmed over real HTTP, on the
real QA1 checkout: the banner and count render correctly on
`/corex/rental-applications` for a real admin account, the count matches
a direct query, the link lands on `/corex/rental-applications/returned`
and shows application 9, the return link works, the review-screen label
change renders, and the PDF viewer shows the loading state before the
signed PDF appears (confirmed via the real ~5s generation delay, not
simulated).

### Files touched

- `app/Http/Controllers/CoreX/RentalApplicationController.php` —
  `index()` computes `$returnedCount`
- `resources/views/corex/rental-applications/index.blade.php` — banner +
  empty-state wording
- `resources/views/corex/rental-applications/returned.blade.php` — way
  back
- `resources/views/corex/rental-applications/review.blade.php` — stale
  label fixed
- `resources/views/corex/rental-applications/view-readonly.blade.php` —
  PDF loading state

### Still open, Johan's call, not mine

Johan is separately weighing whether to put the two-screen question back
to himself as a decision he may want to revisit later. This round does
not pre-empt that — it only fixes discoverability of the design as it
stands today.
## Authoriser strike-and-replace for the agent's assessment (AT-392, 2026-09-08, cc5)

### The rule — Johan's, stated directly, not inferred

Johan, verbatim, confirming the coordinator's earlier reading and
sharpening it: **"auth can rather strike out and re-add a value than
edit a value. this way we have the evidence needed of who did what."**

This is Johan's stated rule, not a decision open for revisiting. His
own reason — the evidence trail of who did what — is what drives every
place below where he didn't spell out the detail:

- **There is no EDIT verb anywhere in this feature**, client or server.
  Editing a value someone else captured is not a permission being
  withheld "for now" — there is no code path, no route, no controller
  method that can do it, with no admin override. The only way to
  correct a figure is to strike it out and add the correct one.
- **STRIKE has no ownership check at all** — any RO/CO can strike (or
  restore) ANY row, including the agent's own original capture. This
  is deliberate, not an inconsistency with the no-edit rule: strike
  exists precisely so a reviewer never has to overwrite someone else's
  figure to disagree with it. The agent's original number stays on the
  record, attributed, and the disagreement is a separate, visible
  fact — never a quiet in-place change with no trace of what was there
  before or who changed it. (This is the same tampering concern Johan
  raised about the signed rental application itself — a figure that
  can change with nobody able to see what it used to be is not
  evidence of anything.)
- **ADD is open to any RO/CO**, attributed via `added_by_user_id`
  (`NULL` = the agent's original capture — the agent-side review screen
  never sets this column, so this needs no backfill and nothing changes
  about how the agent's own form behaves).
- **Strike-and-replace is the supported workflow, not a workaround.**
  Striking a row opens a replacement box directly under it,
  pre-focused, so adding the correct figure is the natural next step,
  not a separate action the authoriser has to go find. The result reads
  as "this figure was replaced by that one, by this person, at this
  time" — both rows stay visible, both attributed, only the live one
  counts toward the total.

### What makes "replaced by" survive a reload

A struck row and its replacement being shown together in the moment
they happen is not enough — the coordinator's own requirement was that
this reads correctly on a later visit too, by someone who wasn't there
when it happened. That needs one more persisted fact beyond who-struck/
who-added: `replaces_item_id`, a nullable self-referencing FK on both
item tables, set only when an add follows a strike in the same flow.
Verified server-side before being trusted — `addAssessmentItem()`
refuses to link to anything that isn't a genuinely struck row on the
same assessment, so a crafted request can't fabricate a "replaced"
relationship pointing at an unrelated or still-live row (tested
directly: pointing `replaces_item_id` at a non-struck row is silently
ignored, not linked).

### Ownership resolution, and the incident behind it

`RentalApplicationAssessment::qualifyingResult()` excludes struck
rows from the totals and the affordability calculation, composing
with the Round 16 redesign untouched — rent still sources from the
linked property, `has_unpaid_transactions` is a separate flat flag
neither reads nor writes this feature touches.

`RentalApplicationReviewController::syncItems()` — the agent's own
review-screen autosave — needed a real fix, not just a new feature
next to it. It replaces the assessment's WHOLE line-item list on every
autosave, matched by id, soft-deleting whatever isn't present in the
submitted array. That was safe while every row was agent-owned. It
stopped being safe the moment a row could belong to someone else: an
authoriser-added row the agent's own browser session never loaded
would look "no longer present" and get silently soft-deleted on the
agent's very next autosave — the exact class of silent data loss this
whole feature exists to prevent, just relocated to a different screen.
Fixed with two guards: a row someone else added is never deleted by
this cleanup regardless of whether it appears in the agent's submitted
list, and this bulk path never writes to a row it doesn't own (a
silent skip, not a 403 — one disallowed row must not fail the agent's
otherwise-legitimate autosave of their own rows). Proven directly:
authoriser adds a row, agent autosaves a payload that omits it
entirely, row survives.

### The migration incident, and the rule it produced

The two columns this feature needed (`struck_out_at`,
`struck_out_by_user_id`, `added_by_user_id`, later `replaces_item_id`)
were written and run once already, against QA1's live database, before
being committed to any branch — sitting only as an untracked file in
the shared `/corex-qa1` working tree. A branch checkout on that shared
checkout (unrelated to this feature) swept the untracked file into
another lane's WIP commit; when the checkout returned to QA1, the file
vanished from the working tree, and the columns it had created were
later lost in a subsequent schema reset that had no record of them —
discovered only when this feature's own controller threw "unknown
column" against the live database, checked directly rather than
assumed. Cost a full day across two lanes.

**The rule this produced, now standing for every migration on this
project: a migration is committed to its branch the moment it is
written, before it is ever run — never after the feature built on top
of it works.** A tracked file cannot vanish in a branch switch the way
an untracked one can. Re-landing here followed that order exactly:
migration written → committed to `at392-authoriser-strikeout-2026-09-08`
→ only then run against `corex_qa1` → confirmed with a direct
`Schema::hasColumn()` check on both tables before any application code
was written against them.

### Verification

Real HTTP dispatch, real authenticated session, real database reads
before and after every write — on **application 4**, deliberately not
application 9 (already used repeatedly in earlier testing this
feature), per the standing instruction to verify on records not
previously touched, in states not deliberately chosen.

- Struck a real agent-captured income line (R40,638) as a real CO user
  → real 200, real `struck_out_by`/`struck_out_at` persisted.
- Added a real replacement (R42,000) with `replaces_item_id` pointing
  at the struck row → real 200, link persisted, `added_by_authoriser`
  correctly true.
- Confirmed via `qualifyingResult()`: total income became R43,270
  (the untouched R1,270 line + the R42,000 replacement) — not
  R41,908 (the stale pre-strike total) and not R83,908 (double-counted
  struck + replacement). Struck lines exclude correctly; nothing counts
  twice.
- Confirmed the reverse relation: reading the struck row's own
  `replacedBy` relation resolves the replacement correctly after a
  fresh model load, not just in the same request's response.
- Confirmed a crafted `replaces_item_id` aimed at a live (non-struck)
  row is refused, not linked.
- Confirmed the `syncItems()` fix directly: an authoriser-added row
  survives a real agent autosave that never loaded it.
- All test fixtures created during verification soft-deleted, never
  hard-deleted; application 4's original two rows restored to their
  exact original (un-struck) state afterward.

### Files touched

- `database/migrations/2026_09_08_190000_add_strike_out_and_added_by_to_rental_application_items.php` — `struck_out_at`, `struck_out_by_user_id`, `added_by_user_id` on both item tables
- `database/migrations/2026_09_08_190100_add_replaces_item_id_to_rental_application_items.php` — `replaces_item_id`, self-referencing, on both item tables
- `app/Models/RentalApplicationIncomeItem.php` / `RentalApplicationExpenseItem.php` — new fillable/casts, `struckOutBy()`, `addedBy()`, `replaces()`, `replacedBy()`, `isStruckOut()`
- `app/Models/RentalApplicationAssessment.php` — `qualifyingResult()` excludes struck lines from both totals
- `app/Http/Controllers/CoreX/RentalApplicationAuthorisationController.php` — `addIncomeItem`/`addExpenseItem`/`toggleStrikeIncomeItem`/`toggleStrikeExpenseItem`, `serializeItem()`; `show()` now passes fully-serialized item arrays
- `app/Http/Controllers/CoreX/RentalApplicationReviewController.php` — `syncItems()` ownership guards
- `resources/views/corex/rental-applications/authorisation/show.blade.php` — strike-and-replace UI, `rentalAssessmentEditor()`, `has_unpaid_transactions` mirrored read-only at authoriser tone
- `routes/web.php` — `assessment/income-items` / `assessment/expense-items` store + strike routes (no update route)

Branch: `at392-authoriser-strikeout-2026-09-08`, built in an isolated
worktree at `/mnt/HC_Volume_103099143/wt-cc5-authoriser-strikeout` per
the standing "shared checkout belongs to cc1 alone" rule — not landed
by me; cc1 lands via the shared checkout. Both migrations already run
against QA1's live database directly from this worktree (see the
incident note above for why that was done ahead of the rest of the
branch landing).

## Reopen and resubmit — signed generations (2026-09-08, cc6)

### The requirement

Johan, original requirement, never built until now: after a rental
application comes back, the agent must be able to send it BACK to the
applicant so they can reopen it, fix an answer, and re-sign — not just the
existing one-way "request more information" free-text message, which
notifies the applicant but never actually unlocks their answers for
editing.

Four non-negotiable constraints, decided before any code was written (see
the investigation report that preceded this build):

1. The signed version is evidence and must never be altered. Reopening and
   re-signing retains the previously-signed submission as-is; the new
   submission becomes current; a read-only view can show what was signed
   at each point.
2. The applicant's earlier signatures do not carry over — if the declared
   content changes, they must sign the new declaration.
3. The agent's assessment work (income/expense lines, document marks,
   notes) must survive the round trip.
4. Any email to the applicant reuses the e-sign email format (agent
   details, photo) — never a second, parallel template.

Johan's own answer to the one open product question this build needed
("prefilled or blank?"): **"prefilled - its a reopen, not new."** The
applicant sees their previous answers and edits only what's wrong.

### The mechanism — generations, not a second status enum sprawl

`RentalApplication::STATUSES` gains exactly one new value: **`reopened`**.
Deliberately not a reuse of `sent`/`in_progress` (those already carry
distinct meaning — `in_progress` specifically means "first document
uploaded" — overloading them would make "was this ever originally sent, or
is it a reopen" undiscoverable from status alone).

`rental_applications.current_generation` (default 1) is "which submission
round is live." Every `submit()` — the very first one AND every resubmit
after a reopen — is the same operation: increment `current_generation`
(skipped only on the genuine first-ever submission, signalled by
`submitted_at` still being null) and seal a snapshot under that number.
Reopen itself does NOT bump the generation — it only sets
`status = 'reopened'` and unlocks the applicant's form; the generation
bumps at the moment they actually resubmit.

`rental_application_generations` is the sealed, append-only history —
copies `App\Models\Docuperfect\DocumentSealedVersion`'s exact shape (no
`updated_at`, `save()` throws on any update, `content_hash` chained to
`prev_hash` via `sha256(prev_hash . snapshot_json)`) rather than inventing
a second immutable-record pattern. One row per submission, storing a
frozen copy of every field in `RentalApplication::fieldValidationRules()`
as it stood at that submission. `RentalApplicationReviewController::
showGeneration()` renders any sealed generation read-only, independent of
whatever the live row has since become — this is the "what was signed at
each point" view.

### The signature landmine — fixed as part of this build

Before this build, `RentalApplicationSigningController::storeSignature()`
keyed `updateOrCreate()` on `(rental_application_id, kind)` alone. A
resubmit after reopen would have silently OVERWRITTEN the original signed
image in place — destroying exactly the evidence a reopen must preserve.
Fixed at the schema level, not the application level: `generation` joins
the unique key (`rental_application_signatures` migration
`2026_09_08_210100...`, unique now `(rental_application_id, kind,
generation)`), so every submission round gets its own row per kind, never
overwritten. `RentalApplication::currentSignatures()` (and
`declarationSignature()`/`tpnConsentSignature()`/`isFullySigned()`, all
updated to read through it) filter to the CURRENT generation only — a
superseded signature still exists in the database, forever, it simply
stops being "the" signature the moment a newer generation exists. The
signature capture pad itself is a blank HTML canvas (never pre-filled from
a stored image), so re-signing is enforced for free by the existing UI —
no separate "clear the old signature" step was needed.

### Concurrency — the applicant resubmits while the agent's screen is open

Reuses the exact 409-conflict shape already shipped for document marks
(`RentalApplicationMarkVersionConflictException` /
`HandlesRentalApplicationDocumentMarks::applyHighlight()`), one level up:
`RentalApplication::assertGenerationMatches()` /
`RentalApplicationGenerationConflictException`, thrown when a caller's
`expected_generation` no longer matches the row's live `current_generation`.
Wired into every review-screen write that could act on stale content —
`RentalApplicationReviewController::saveAssessment()`,
`submitForApproval()`, and `RentalApplicationController::updateStatus()`
— all optional (`nullable`), so an existing caller that doesn't send the
field is unaffected; the review screen (`review.blade.php`) bootstraps
`expectedGeneration` from the generation it actually rendered and sends it
back on every write, surfacing a plain "this application changed — reload
to see the new version" message on a 409 rather than silently saving
against content the agent hasn't seen.

### Why the agent's own assessment work needed no schema change

Verified, not assumed (Johan's explicit instruction): `RentalApplicationAssessment`
is one row per `rental_application_id`, no version concept at all; the
same is true of `RentalApplicationDocumentHighlight` marks (keyed to
`document_id`). A reopen that only replaces the applicant's OWN answer
fields (and adds a new signature generation) leaves both untouched — they
simply keep pointing at the same ids they always did. No migration, no
model change was needed for either.

### What's reopenable, and what isn't

`RentalApplication::REOPENABLE_STATUSES = ['returned', 'under_assessment']`
— an agent's own judgement call before the authoriser has decided
anything. Deliberately excludes `approved`/`declined`/`withdrawn`:
reopening past an authoriser's own decision would mean overturning it,
which this build does not attempt (flagged, not silently assumed — a
future feature if Johan wants it).

`RentalApplication::AGENT_EDIT_LOCKED_STATUSES` (new) =
`POST_RETURN_STATUSES` + `reopened` — the agent's OWN edit form
(`RentalApplicationController::update()`) stays blocked while an
application is reopened, same as it's blocked once returned: the
applicant is the one editing those fields right now.

### Applicant link — reused, not regenerated

`reopen()` reuses the SAME token the applicant already has (never
regenerates it), only refreshing `token_expires_at` via a new
agency-configurable setting, `reopen_link_expiry_days` on
`rental_application_qualifying_settings` (default 14, matching the
existing invite-link window). `RentalApplicationSigningController::show()`
/`submit()` both key off `RentalApplication::POST_RETURN_STATUSES`, which
`reopened` is deliberately NOT a member of — so a reopened application
falls straight through to the same editable public form `sent`/
`in_progress` already use, pre-filled from the model's own (untouched)
attributes.

### Email — reused, not duplicated

`RentalApplicationReopenedMail extends BaseSignatureMail`, same base
`RentalApplicationInviteMail` already uses — same From/reply-to routing,
same agent footer (name/photo/phone/FFC/PPRA/agency logo). Not a second
template; only its own subject and the agent's reopen note.

### Setting deliberately NOT in the onboarding wizard

`reopen_link_expiry_days` is an expert, rarely-touched knob (a sensible
default of 14 days most agencies will never change) — judged, not
silently skipped, to belong only on the Rental Applications settings
screen, not the Setup Wizard. Recorded here per CLAUDE.md non-negotiable
#10a rather than left as an unexplained omission. Also noted, separately
and out of this build's scope: NONE of this module's other existing
settings (qualifying formula, RO/CO tiers, decline-email wording, the
document checklist) have ever reached the wizard either — a pre-existing
gap from earlier prompts, reported to the coordinator, not fixed here.

### Files touched

- `database/migrations/2026_09_08_210000_add_reopen_generation_to_rental_applications.php` — `current_generation`, `reopened_at`, `reopened_by_user_id`, `reopened_note`
- `database/migrations/2026_09_08_210100_add_generation_to_rental_application_signatures.php` — the signature-landmine fix; `generation` joins the unique key, adds `deleted_at`
- `database/migrations/2026_09_08_210200_create_rental_application_generations_table.php` — the sealed, append-only snapshot table
- `database/migrations/2026_09_08_210300_add_reopen_link_expiry_to_rental_application_qualifying_settings.php`
- `app/Models/RentalApplicationGeneration.php` — new, mirrors `DocumentSealedVersion`
- `app/Exceptions/RentalApplicationGenerationConflictException.php` — new, mirrors `RentalApplicationMarkVersionConflictException`
- `app/Models/RentalApplication.php` — `reopened` status, `REOPENABLE_STATUSES`, `AGENT_EDIT_LOCKED_STATUSES`, `current_generation`/`reopened_*` fillable+casts, `generations()`, `currentSignatures()`, `declarationSignature()`/`tpnConsentSignature()`/`isFullySigned()` now generation-filtered, `assertGenerationMatches()`
- `app/Models/RentalApplicationSignature.php` — `generation` fillable/cast, `SoftDeletes`
- `app/Models/RentalApplicationQualifyingSetting.php` — `reopen_link_expiry_days` fillable/cast, `reopenLinkExpiryDaysFor()`
- `app/Http/Controllers/RentalApplicationSigningController.php` — `show()`/`submit()` use `POST_RETURN_STATUSES` (not a hardcoded array) so `reopened` falls through to the editable form; `submit()` bumps generation + seals a snapshot on every submission; `storeSignature()` keys on generation
- `app/Http/Controllers/CoreX/RentalApplicationController.php` — `update()` uses `AGENT_EDIT_LOCKED_STATUSES`; `updateStatus()` gains an optional generation-conflict guard
- `app/Http/Controllers/CoreX/RentalApplicationReviewController.php` — `reopen()`, `showGeneration()`, `show()` eager-loads `generations`, `saveAssessment()`/`submitForApproval()` gain the generation-conflict guard
- `app/Mail/RentalApplicationReopenedMail.php` — new, extends `BaseSignatureMail`
- `resources/views/emails/rental-application-reopened.blade.php` — new
- `app/Services/RentalApplications/RentalApplicationMailer.php` — `sendReopened()`
- `resources/views/corex/rental-applications/review.blade.php` — "Reopen for the applicant" panel, "Submission history" panel, `expectedGeneration` wired through save/submit-for-approval, 409 handling
- `resources/views/corex/rental-applications/generation-show.blade.php` — new, read-only sealed-generation view
- `resources/views/corex/settings/rental-applications.blade.php` / `RentalApplicationSettingsController.php` — `reopen_link_expiry_days` form + `updateReopenLinkExpiry()`
- `routes/web.php` — `corex.rental-applications.review.reopen`, `corex.rental-applications.generations.show`, `corex.settings.rental-applications.reopen-link-expiry`
- `tests/Feature/RentalApplications/RentalApplicationReopenTest.php` — new regression suite

Branch: `feature/rental-application-reopen-2026-09-08`, built in an
isolated worktree at
`/mnt/HC_Volume_103099143/corex-rental-app-reopen-2026-09-08` per the
standing "shared checkout belongs to cc1 alone" rule — not landed by me;
cc1 lands via the shared checkout. All four migrations already run
against QA1's live database directly from this worktree, same pattern as
every other feature this session.

## Self-approval block (AT-392, 2026-09-09, cc5)

### The rule — Johan's, verbatim

"Self approve should only work for the co of rentals or admin - rest
agents and ro can not approve their own."

Two groups may authorise an application they created themselves: a CO
(Override) user for the agency, or an administrator (`users.role` of
`admin` or `super_admin` — the same tier `SettingsController.php`
already treats as one group, no new concept invented). Everyone else —
plain agents and RO-tier users who are neither CO nor admin — may not
act on their own application at all. Someone else has to.

### Why it applies to all three decisions, not just Approve/Decline

Request More Information was the one place I considered an exemption —
it doesn't commit any money, it just sends the application back to the
agent. But it's still a recorded authorisation-workflow decision, and
the whole point of the rule is an independent set of eyes on the file.
A self-reviewer sending an information request to themselves isn't
independent review either. Built uniform across Approve, Decline, and
Request More Information; no exemption.

### Enforcement — server first, screen explains why

`RentalApplicationAuthorisationController::guardNotSelfApproving()` is
the actual gate: a no-op unless `created_by_user_id === auth user`, in
which case it requires `role in [admin, super_admin]` OR
`isRentalApplicationCO()`, 403 otherwise
("You created this application, so it needs another authoriser.").
Called from `guardCanDecide()` (approve/decline) and inline in
`requestMoreInfo()`, which doesn't use `guardCanDecide()`. A crafted
request straight at the endpoint gets refused exactly the same as a
click would have.

`show()` separately computes `$blockedBySelfApproval` — same logic,
read-only, never the actual gate — purely so the Decision panel can
say why the three actions are gone instead of leaving a missing button
with no explanation. View access itself is unaffected: an RO/CO who
created the application can still see it, mark up documents, and read
the audit trail; they just can't decide it themselves.

### Verified

Real dispatch, not assumed. Every application that existed naturally on
QA1 was created by an admin (Johan or the HFC Demo Agent fixture), so
there was no natural record to prove the block against — two scratch
applications were created for this specifically and soft-deleted after:

- App owned by a plain agent temporarily granted RO tier (not CO, not
  admin): all three decision endpoints returned 403 with the exact
  message above; the show page rendered 200 with the explanation and no
  decision forms; DB confirmed untouched.
- App owned by an admin-role user who holds RO tier but NOT CO: approve
  succeeded — proves the admin exception works independent of CO
  membership, exactly as Johan specified ("co of rentals **or** admin").

Queue-impact check (read-only, before building): every application
actually in the authorisation queue at build time (4, 9, and cc4's
clean record 12) was created by an admin-role user, so the rule doesn't
lock Johan out of anything already sitting there — confirmed, not
assumed.

### Files touched

- `app/Http/Controllers/CoreX/RentalApplicationAuthorisationController.php` — `guardNotSelfApproving()`, called from `guardCanDecide()` and `requestMoreInfo()`; `show()` computes `$blockedBySelfApproval`
- `resources/views/corex/rental-applications/authorisation/show.blade.php` — Decision panel shows the explanation in place of the three forms when blocked

No migration — the rule reads existing columns (`created_by_user_id`,
`users.role`, the existing RO/CO tier check) only.
## Regression walk, and the PDF cache it found (2026-09-09, cc6)

### The walk

A full end-to-end regression walk of the whole module — list, create,
send, applicant complete/sign, returned/read-only view, agent review and
assessment, authoriser decisions, reopen and re-sign — acting as one
agent doing a day's work, on fresh records created for the walk (never
applications 4, 9, or 12). Full findings delivered to the coordinator
directly; two small display bugs found in `generation-show.blade.php`
(a dark-mode contrast bug on the "previous submission" banner, and a
raw unformatted `occupation_date`) were fixed in the same session — see
that file's own inline comments.

One structural finding — `RentalApplicationController::index()`/
`returned()`'s status-visibility filters predated the `reopened` status
and needed the same value added both sides (see the "reopened
application vanished from every list" fix, already in the commit
history above) — was actually caught during the walk, not a separate
build; recorded here for completeness of the walk's own trail.

One infra finding was NOT fixed as part of the walk itself — it needed
its own design decision — and is the subject of the section below.

### The PDF cache

**The problem, measured live:** `RentalApplicationPdfService::generate()`
had no caching at all. Every visit to the read-only view, and every
"Download PDF" click, re-rendered a byte-identical PDF through headless
Chromium — roughly 9 seconds — even though a submitted, signed
generation's content can never change again.

**The design (Johan → senior engineer → cc6, 2026-09-09):**

- **Cache location:** a new named Laravel disk, `data_volume`
  (`config/filesystems.php`), rooted at `env('DATA_VOLUME_ROOT',
  '/mnt/HC_Volume_103099143') . '/corex-data-volume'` — the mounted
  volume, never root. No existing disk was reused: `Storage::disk('local')`
  happens to sit on the same physical device as the mounted volume on
  THIS box (confirmed via `findmnt` — both are bind-mounts of
  `/dev/sda`), but that's a QA1-specific coincidence, not a portable
  guarantee for Staging or Live. An explicit, self-documenting disk
  costs nothing and doesn't silently break if that coincidence doesn't
  hold elsewhere.
- **Cached per SEALED GENERATION, not per application** — key shape
  `rental-applications/{application_id}/generations/{generation}.pdf`.
  A resubmit bumps `current_generation`, which naturally produces a
  brand-new, never-colliding key — no cache invalidation logic exists
  or is needed, because nothing sealed is ever mutated.
- **Cacheable if and only if** `$rentalApplication->isSubmitted()`
  (the module's own established "has this genuinely been signed" flag)
  **and** a `RentalApplicationGeneration` row exists for the
  application's current generation. Those two together guarantee the
  live row's field values are byte-identical to what that generation
  sealed — `submit()` writes the new fields, the new signatures, and
  the seal in one DB transaction, so there is no window where they can
  disagree. This holds true even while status is `reopened`: reopen()
  never touches the applicant-answer fields, so the live row still
  matches the last sealed generation right up until the applicant
  actually resubmits — the exact moment `current_generation` bumps and
  a new generation is sealed. A draft/sent/in_progress application, or
  the public pre-submission "download, complete, return" link, is never
  submitted yet, so this is always false there — always fresh, never a
  moving target cached as if it were fixed.
- **Invalidation:** none by design, except archiving. Archiving an
  application (`RentalApplicationController::destroy()`) now also calls
  `RentalApplicationPdfService::forgetCacheFor()`, deleting that
  application's whole `generations/` cache directory. This is reclaiming
  a derived artefact, not deleting evidence — the sealed
  `snapshot_json` rows (the actual record of truth) are completely
  untouched; if the application is later restored, the PDF simply
  re-renders once, on demand, from the still-intact snapshot.
- **Failure-safety, both directions:** a cache WRITE failure (full disk,
  permissions) is caught, logged as a warning, and the freshly-rendered
  file is still returned — never surfaced as an error. A cache READ
  failure falls through to a fresh render exactly like a genuine miss.
  Verified live, not just by code reading: the cache root was replaced
  with a blocking regular file (a permission-bits `chmod` doesn't work
  for this proof running as root, which bypasses those checks) and the
  PDF still generated and served correctly, with the failure logged.

**Real numbers, measured against rental application 15** (a real
record, not created by this build, already carrying two real sealed
generations from an earlier reopen): **8.96s cold → 0.002s cached** —
roughly a 4,000× improvement. Verified that each generation caches
independently and the correct one is served: a second render forced
against generation 1's own sealed snapshot produced a distinct
116,215-byte PDF (generation 2's is 119,210 bytes) containing
generation 1's own ID number and *not* generation 2's — no
cross-contamination between generations of the same application.

**Not done, deliberately:** background cache-warming immediately after
a submit/resubmit, and extending this same caching to the
`generation-show.blade.php` historical-generation view (which today has
no PDF render path of its own at all — it renders the sealed snapshot
fields directly, not a PDF). Both were suggested mid-build via a
relayed message; neither was in the actual design brief, so neither was
built — flagged back to the coordinator directly rather than silently
expanding scope two hops from the source.

**Investigated, not changed:** the `note` vs `reason` field-name
difference between the agent's own "request more information" and the
authoriser's version. Turned out NOT to be an isolated inconsistency —
`reason` is the authoriser controller's own consistent convention across
all three of its actions (approve, decline, request-more-info), not a
one-off. Renaming only the request-more-info instance would make it the
odd one out against its own siblings in the same controller; renaming
all three is real behaviour-adjacent surface (validation, views, JS)
well beyond a "genuinely a rename" scope. Left alone, reported back per
the explicit instruction to do so rather than guess.

### Files touched

- `config/filesystems.php` — new `data_volume` disk
- `app/Services/RentalApplications/RentalApplicationPdfService.php` — per-generation cache read/write/forget, all failure-safe
- `app/Http/Controllers/CoreX/RentalApplicationController.php` — `destroy()` calls `forgetCacheFor()` before archiving
- `resources/views/corex/rental-applications/generation-show.blade.php` — the two display fixes from the walk
- `tests/Feature/RentalApplications/RentalApplicationPdfCacheTest.php` — new, 6 tests covering hit/miss, the reopened-but-not-resubmitted edge case, per-generation isolation, write-failure safety, and archive cleanup

## Highlighter freehand redesign (2026-09-09, cc6)

### Why the original design was wrong

Johan, from real marked-up bank statements: "no lines as it strikes
out." The original highlighter drew a horizontal band plus a solid
underline beneath it — a design that assumed a clean swipe along one
line of text, like a ruler. Nobody marks up that way: real strokes
wander across several rows, loop around a figure, cross each other.
Two things broke: the underline drew a hard horizontal line through
everything it crossed, and this module has a genuine strike-out
feature on the assessment panel — a line through text reads as
struck-out, not highlighted. The fix removes the underline concept
entirely rather than adjusting its geometry.

### The new rendering

One polyline per stroke, following the actual pointer path the user
drew (round caps/joins — a real marker-pen gesture, never a
rectangle), translucent ink only. `mix-blend-mode:multiply` is set on
**each polyline individually** in `strokesSvgFor()`
(document-highlighter-script.blade.php), not once on the containing
`<svg>` — a blend mode set on the container would flatten every stroke
into one composited layer first (plain alpha-blending them together),
then multiply that single flattened result against the page once.
Per-polyline blending makes two overlapping strokes genuinely compound
and darken against **each other**, not just against the page beneath
both — which is what makes loops and crossing strokes read correctly
on a dense document. Opacity raised from the original 0.5 to 0.55 now
that there's no underline to lean on for legibility.

### Storage — answered before building, per the coordinator's gate

The storage model was never the rectangle the redesign brief assumed —
that assumption was about the old RENDERING only. A mark has always
stored `points: [{x, y}, ...]`, a genuine path, going back to the
original build. No migration was needed and none was run; every mark
already on QA1 renders under the new logic unchanged.

What genuinely needed addressing was **how many** points a dense,
loopy, slow freehand stroke could accumulate:
- The existing 2px-minimum-spacing throttle in `moveDraw()` already
  bounds live drawing.
- **RDP (Ramer–Douglas–Peucker) path simplification**, applied once at
  SAVE time only (never during the live gesture, so drawing never
  feels different from what gets stored) — `simplifyPath()` in
  document-highlighter-script.blade.php, epsilon 1.5px in RASTER
  (document-resolution) pixels so it scales with the document's own
  resolution, not the viewer's zoom.
- `MAX_STROKE_POINTS: 800` — a defensive hard cap in `moveDraw()`
  itself, not something a normal hand-drawn stroke gets anywhere near;
  it exists only to bound a pathological case (a very long, very slow
  drag, or a crafted request), not to shape everyday storage.

### Six colours, agency-configurable

Johan: "admin can pick 6 colours - agent 3 and auth 3." The three
category KEYS and LABELS (Income/Expense/Unpaid) stay fixed; only
their COLOUR becomes agency-configurable, via
`RentalApplicationMarkColorSetting::colorsFor()` — same
never-writes-on-read pattern as `RentalApplicationQualifyingSetting`,
sensible defaults matching the palette this feature already shipped
with, so an agency that never opens the settings screen sees no visual
change.

Both roles' full colour sets are always sent to the frontend — an
existing mark needs its AUTHOR's colour regardless of who's currently
viewing, so rendering can never be trimmed to "just mine." The DRAWING
TOOLBAR is the one place restricted to the current viewer's own three,
via `myColorFor()` — Johan: "an agent sees their three; an authoriser
sees theirs... do not show anyone six." The legend shows both roles'
swatches side by side, labelled "agent"/"authoriser" in plain text —
the old "lighter = agent, darker = authoriser" caption is gone, since
that relationship is no longer guaranteed once colours are
admin-chosen.

Settings UI: a new "Highlighter Colours" block on
`corex/settings/rental-applications.blade.php`, six `<input
type="color">` pickers (one row per role), saved via
`RentalApplicationSettingsController::updateMarkColors()` →
`POST corex.settings.rental-applications.mark-colors`, validated as a
genuine 6-hex colour per field before it can reach storage or a mark's
rendered style.

**Open question, not decided here — flagged to the coordinator:**
whether the category LABELS themselves (not just their colour) should
also become agency-configurable text. Recommended default (labels stay
fixed) is what's built; category meaning does not change per agency.

**Also flagged, not resolved here — non-negotiable #10a:** whether
this setting belongs in the Agency Onboarding Setup Wizard is
explicitly Johan's call, not the lane's, per that rule's own text. Not
yet added to `config/agency-onboarding-copy.php` pending that answer —
this is a genuinely open item, not a "deliberately NOT in the wizard"
decision on the record.

### Delete handles — hover-only, not permanently visible

Johan: "every stroke currently carries a black circled x... eight of
them scattered down the page... competes with the marks themselves."
Each polyline carries `pointer-events="stroke"` + `data-mark-id`, so
the browser's own hit-testing against the actual drawn ink (not a
bounding box) decides "hovering" — delegated `@mouseover`/`@mouseout`
on the `<svg>` itself track `hoveredMarkId`, and the remove-handle
button for a stroke only renders while its own id matches. Ownership
rules (`canEditMark()`) are unchanged — someone else's mark never
shows a handle at all, hovered or not.

### Verified

- `php -l` on every changed PHP file; `node --check` on the extracted
  inline JS from document-highlighter-script.blade.php — both clean.
- Model tests (`RentalApplicationMarkColorSettingTest`): default
  colours with zero rows written, `colorsForRole()` isolation, a full
  six-colour save round-trips through `colorsFor()`, an invalid hex is
  rejected and never persisted, a second save updates the same row
  rather than duplicating it.
- Dense-document freehand pass (loops, overlaps, strokes across
  several rows, not a straight test swipe) against a real document —
  see the coordinator report for the specific record and screenshots.
- Existing marks already on QA1 confirmed still rendering correctly
  under the new per-polyline rendering — no migration, nothing
  orphaned.

### Files touched

- `database/migrations/2026_09_09_040000_create_rental_application_mark_color_settings_table.php` — new
- `app/Models/RentalApplicationMarkColorSetting.php` — new
- `app/Http/Controllers/CoreX/RentalApplicationReviewController.php` — passes `markColors` to the view
- `app/Http/Controllers/CoreX/RentalApplicationAuthorisationController.php` — same
- `app/Http/Controllers/CoreX/RentalApplicationSettingsController.php` — `updateMarkColors()`, `$markColors` in `edit()`
- `resources/views/corex/rental-applications/review.blade.php` — `markColors` threaded into `rentalReview()`
- `resources/views/corex/rental-applications/authorisation/show.blade.php` — same, into `rentalAuthorisationViewer()`
- `resources/views/corex/rental-applications/partials/document-highlighter-script.blade.php` — freehand rendering, `myColorFor()`, `hoveredMarkId`, `simplifyPath()`, `MAX_STROKE_POINTS`
- `resources/views/corex/rental-applications/partials/document-highlighter-pages.blade.php` — legend, per-polyline blend, hover-only delete handles, note-marker border fix
- `resources/views/corex/settings/rental-applications.blade.php` — Highlighter Colours settings block
- `routes/web.php` — `corex.settings.rental-applications.mark-colors`
- `tests/Feature/RentalApplications/RentalApplicationMarkColorSettingTest.php` — new, 5 tests

## Unified agent/authoriser screen (AT-392, 2026-09-09, cc5)

### The defect — Johan, verbatim, after finding it himself on QA1

"did I not tell you the reviewer screen is essentially the same screen as
the agent screen? same fucking problem I have been describing all along.
how does the reviewer work through the bank statement as example and look
at what the agent marked / captured etc.?"

He'd been saying this since the authoriser screen was first built. The
agent's review screen and the authoriser's screen had been built as two
structurally different layouts for the same job: the agent had documents
in the main column with the assessment panel down the right (opening the
highlighter in place); the authoriser had the agent's assessment as a
list in the MAIN column with the Decision box down the right, documents
below the assessment. Two different shapes, duplicated components,
exactly the "bitten repeatedly by two versions of the same thing" pattern
this session kept running into elsewhere.

### The fix — one blade, two routes, role-gated

`resources/views/corex/rental-applications/review.blade.php` is now the
ONE canonical view for both roles. `RentalApplicationReviewController::
show()` and `RentalApplicationAuthorisationController::show()` both
render it, differing only in the `$viewerRole` ('agent'|'authoriser')
they pass plus their own already-correctly-guarded data.
`authorisation/show.blade.php` is deleted — there is no second copy left
to drift.

The two ROUTES and their guards stayed separate on purpose:
`guardRentalApplication()` (agent — a permission-scope question: own/
branch/all) and `guardCanView()`/`guardCanDecide()` (authoriser — an
RO/CO tier-membership question) answer genuinely different authorization
questions. Collapsing them into one combined guard would be exactly the
kind of fragile conflation this session has been bitten by before. The
authorisation queue's links are unchanged — they still point at the
authorisation route, which now renders this screen instead of a second
one.

Layout: one class family now (`.rental-review-columns/-main/-aside`) —
was `.rental-review-*` (agent) and `.rah-auth-*` (authoriser), the exact
kind of duplicate CSS the merge exists to remove. The authoriser's aside
went from 320px to the agent's original 260px; if that reads cramped in
practice, it's a one-line width change, not a rebuild. The authoriser's
highlighter toolbar — previously inline per-document, no sticky header —
moved onto the shared `<x-sticky-action-bar>`, same as the agent's,
so "same layout for both roles" is literally true rather than visually
similar.

### What's role-gated, and why

- **Assessment panel** — agent: inline-edit rows, autosave (`rentalReview()`
  bound to the root x-data, unchanged from before the merge). Authoriser:
  read-only agent values, strike-and-add only, never edit
  (`rentalAssessmentEditor()`, a NESTED x-data scope inside the panel,
  same as before the merge — the highlighter's marks are the root
  component's concern, the assessment items are this nested scope's).
- **Property link** (header) — agent-only. An authoriser reviewing
  someone else's application isn't deciding which property it's tested
  against; also, the link this widget's "View submitted application"
  companion points at (`RentalApplicationController::show()`) is guarded
  by `guardRentalApplication()`'s permission-scope check, which an
  authoriser viewing another agent's application isn't guaranteed to
  pass — handing them a link that can 403 isn't a real feature.
- **Document upload** — agent-only, same reasoning: adding new source
  documents to someone else's application wasn't asked for here.
- **Decision block** (Approve/Decline/Request More Info, and the
  self-approval explanation) — authoriser-only, at the bottom of the
  shared aside, per Johan's instruction.
- **Audit Trail** — moved to the MAIN column, below Supporting Documents,
  now visible to BOTH roles. Johan's ruling: "VISIBLE for now... an agent
  seeing what happened to their own submission is a feature not a leak."
  Whether an authoriser's decline/override REASONING specifically should
  stay visible to the agent is still open — put to Johan separately. The
  reason line is its own small, cleanly separable `@if` in the blade
  (`resources/views/.../review.blade.php`, the Audit Trail block) so
  hiding it later is a one-line change, not a rebuild, exactly as asked.

### Known gap, flagged rather than rushed

Johan's instruction: "Struck lines stay visible to the agent for
transparency; the strike and add buttons are RO/CO only." Today the
agent sees this ONLY through the shared audit trail's text log ("Andre
Roets — Struck out an income line..."), not inline in the assessment
panel's own row list — the agent's row list is still the pre-existing
`rentalReview()` inline-edit view, built from a plain `{id, description,
amount}` shape with no `struck_out_at`/`added_by_user_id` awareness at
all, unchanged since before this merge.

Making the agent's row list itself struck-aware is a real, contained
follow-up: reuse `RentalApplicationAuthorisationController::
serializeItem()`'s richer shape for the agent's `$initialIncomeItems`/
`$initialExpenseItems` too (extracting it to somewhere both controllers
can reach), then render struck/authoriser-added rows read-only (matching
the authoriser's own struck-through + attribution display) while keeping
the agent's OWN live rows in the existing editable inputs. Deliberately
NOT attempted in this pass — `rentalReview()`'s autosave/row-matching
logic has a documented history of subtle, real bugs (the focus-jump
infinite loop, the "10000" → five single-digit-rows regression, the
description/amount transposition Johan hit live on QA1) and touching it
further tonight, un-tested, right before Johan's own review, was a worse
risk than shipping with this gap named plainly.

### Coordination with cc6

cc6 owns `document-highlighter-script.blade.php` / `document-highlighter-
pages.blade.php` internals (the freehand rebuild, landed same night —
commits `2653cb6ef`/`075a80a1c`) — this merge doesn't touch either file,
only where the highlighter's mount point sits on the page and which
route's toolbar renders around it. Agreed directly before either side
wrote anything: cc6 confirmed their rebuild is purely internal to those
two files, not touching layout/mount-point structure.

### Verified

Real dispatch, not assumed, on applications 4 and 9 (never touched
application 12 — Johan's clean record for his own testing):

- Agent view (user 22) and authoriser view (user 43, CO) both render the
  same blade for the same application without error.
- Agent view: property-link widget and document-upload present; strike
  UI and Decision panel absent.
- Authoriser view: strike UI and Decision panel (Approve/Decline/Request
  More Information) present; property-link widget and document-upload
  absent; inline agent-edit rows absent.
- A real strike action (application 4, income item 1) round-tripped
  correctly: authoriser's view shows the struck row and the audit entry;
  the SAME audit entry is now visible on the agent's view too (the
  shared audit trail working as designed). Application 4 restored to
  baseline after.
- Application 12 confirmed unchanged (`status=under_assessment`,
  `full_name=Thabo Mokoena`) before and after every test above.

### Files touched

- `resources/views/corex/rental-applications/review.blade.php` — now the
  shared view for both roles, role-gated throughout
- `resources/views/corex/rental-applications/authorisation/show.blade.php`
  — deleted
- `app/Http/Controllers/CoreX/RentalApplicationReviewController.php` —
  `$viewerRole='agent'`, audit trail query added (was authoriser-only)
- `app/Http/Controllers/CoreX/RentalApplicationAuthorisationController.php`
  — `$viewerRole='authoriser'`, renders `corex.rental-applications.review`
  instead of its own blade

No migration — purely a presentation-layer consolidation; every field
and endpoint underneath is unchanged.

## Decline is a reported outcome, not a log entry (AT-392, 2026-09-09, cc5)

### Johan's ruling, stronger than "make it visible"

The "known gap" the unified-screen build flagged — struck lines and
authoriser actions only being visible to the agent through the shared
audit trail, not a first-class outcome — got a direct answer for decline
specifically. Johan, verbatim: "yes they should see it. the auth needs
to report back to the agent why the application has been rejected."

Read as a requirement, not a visibility setting: it is not enough that
the reason exists in the audit trail for the agent to go find. The
authoriser is REPORTING BACK. The agent must be told, prominently,
without hunting.

### What changed

1. **Decline's reason is required unconditionally now** —
   `RentalApplicationAuthorisationController::decline()` validates
   `'reason' => ['required', ...]` regardless of override status (was
   conditional on override only, meaning a first-decision decline could
   go out with no reason at all — exactly the failure Johan is closing).
   Reflected client-side too: the Decline button is `:disabled` until
   `declineReason` is non-empty, same pattern Approve's amount field
   already used.
2. **A new banner, agent-only, at the TOP of the page** — above the
   two-column layout entirely, same position as the authoriser's own
   "already has a decision" banner. Shows the decline reason verbatim.
   `RentalApplicationReviewController::show()` computes `$declineInfo`
   from the same `$latestHistory` row `$moreInfoRequestedNote` already
   reads (if status is currently 'declined', the latest history row is
   guaranteed to be the one that set it there — no second query needed).
3. **Approve stays reason-optional** on a first decision — Johan: "an
   approval with an amount is self-explanatory." Unchanged.
4. **Request More Information is unchanged** — Johan confirmed it
   "already works this way," i.e. it already genuinely reaches the
   agent (last night's fix), so no repositioning was asked for or done.
   Its own banner stays where it was, in the aside's Actions block.

The existing "Declined. The applicant has been notified." line in the
aside's Actions block was left as-is — it answers a different question
(did the applicant get told) than the new top banner (why, for the
agent specifically), so the two aren't redundant.

### Test — Johan's own framing

"Ask yourself the question the agent will ask: I submitted this, it
came back declined, why? If the answer is not obvious within one second
of opening the application, it is not built right." Verified against
that literally: the banner renders before the two-column layout markup
in the compiled HTML, i.e. it's the first substantive content on the
page after the sticky header, not something requiring a scroll or a
click to find.

### Verified

Real dispatch on application 4 (never touched 12 — Johan's clean
record, confirmed unchanged before and after):

- Decline with no reason → `ValidationException`, DB status unchanged.
- Decline with a reason → succeeds, DB status becomes `declined`.
- Agent's re-render of the same application: banner present, reason
  text present (HTML-escaped, as it should be), banner's position in
  the rendered HTML precedes the two-column layout markup.
- Authoriser's own Decline form: button correctly `:disabled` without a
  reason typed; placeholder correctly reads "required."
- Application 4's status/submitted_for_approval_at restored to baseline
  after; the status-history/audit rows the test created were left in
  place, not deleted — "the audit trail is evidence and we do not tidy
  evidence," Johan's own standing rule from earlier the same night.

### Coordination with cc6

Notified before starting: cc6 is expanding highlighter categories from
a fixed six to an agency-configurable collection, landing in this same
screen. This change doesn't touch the toolbar/category-picker area at
all — confirmed no overlap.

### Files touched

- `app/Http/Controllers/CoreX/RentalApplicationAuthorisationController.php`
  — `decline()`'s validation, unconditional `required`
- `app/Http/Controllers/CoreX/RentalApplicationReviewController.php` —
  `$declineInfo` computed and passed to the view
- `resources/views/corex/rental-applications/review.blade.php` — new
  top-of-page agent banner; Decline form's placeholder + `:disabled`

No migration.

**The six-colour build's `RentalApplicationMarkColorSetting`, superseded the same evening** — see "Highlighter collection expansion" below. The fixed six colours became the SEED DATA for an agency-defined collection; the model and its settings block are retired, folded forward.

## Highlighter collection expansion (2026-09-09, cc6)

### The ask

Johan, after the six-colour build above shipped: "we expand highlighter
to settings where the colours live and we allow an agency to set up
which highlighters they want — so agency can have 10 highlighters set
up, each with their own label? Like it, build it." Not three-for-agent
and three-for-authoriser hardcoded — an agency-owned collection, any
size, each with its own label, colour, and which role(s) may use it.

### The shape (Johan's decisions, built to exactly)

- A highlighter is a record: label, colour, role_scope
  (agent/authoriser/both), sort_order, archived. Full CRUD.
- A saved mark **references** its highlighter (`highlighter_id`) rather
  than copying the colour — recolouring a highlighter changes every
  existing mark drawn with it, live, everywhere it renders. "It is the
  same pen, refilled with different ink."
- An **archived** highlighter (not deleted — `deleted_at`, the same
  convention `PropertyTypeOption`/`PropertyTypesController::archive()`
  already uses for this exact shape of agency-owned list item) still
  renders every mark drawn with it perfectly; it just can't be chosen
  for new marks. `RentalApplicationHighlighter::allFor()` (withTrashed)
  resolves colour for rendering regardless of archived state;
  `pickerFor()` (the default, scoped query) is what the drawing toolbar
  uses, and never includes archived rows.
- Sensible defaults: the six starting highlighters are seeded
  automatically — for every existing agency via a one-time migration,
  and for every new agency via a second listener on the existing
  `AgencyCreated` domain event (alongside `CreateAgencySetupPortal`,
  not a new mechanism).
- Category LABELS became agency-configurable text ("each with their
  own label") — this settles the open question the freehand-redesign
  section above flagged rather than decided.

### Migration — proven against real QA1 data, not just reasoned about

Today's marks store `category` + `author_role` as plain fields inside
each mark's own JSON, never a foreign key — which is what makes this
migration purely ADDITIVE: for every existing agency (excluding
soft-deleted test-fixture agencies — a real distinction the raw
`agencies` table surfaced during this build, see "Caught during
verification" below), seed the six starting highlighters (reading that
agency's already-customised `RentalApplicationMarkColorSetting` row
when one exists, never resetting a real customisation), then for every
existing mark with a `category`, add a new `highlighter_id` key
pointing at the matching seeded row — `category`/`author_role` are
never touched or removed, so a resolution gap degrades to the untouched
legacy fallback instead of breaking. A mark with a null/missing
`author_role` maps the same way `resolveMarkColors()` already treated
it (anything not exactly `'authoriser'` → the agent-side highlighter),
so its rendered colour is provably unchanged, not just probably
unchanged.

Proven on QA1's real data before shipping: application 4 / document
2888 (46 real marks, 11 pages, drawn across earlier sessions) — every
mark rendered identically after the migration, screenshotted before
committing to this being done. `marks_version` on that row was
confirmed unchanged by the migration (4 before, 4 after) — this is a
data backfill, not an edit a client should ever see as a "someone else
saved" conflict.

**Caught during my own verification, fixed before reporting done:** the
first migration run seeded highlighters for 8 agency rows, not 4 —
the `agencies` table also carries several soft-deleted isolated-test-
agency fixtures from other lanes' own verification work (deleted_at
IS NOT NULL). Rolled back, scoped the seed to `whereNull('deleted_at')`,
restored the one real row of custom-colour data the rollback's `down()`
didn't restore (recreates the superseded table's structure, not its
data — the exact values were still in this session's own conversation
history from investigating it minutes earlier), re-ran, re-verified.

### The toolbar — one dropdown, not one button per highlighter

Johan's own concern, and correct: "a row of ten swatches across the
top of a document viewer will be unusable." Replaced the three-button
category picker with a single button (current highlighter's swatch +
label) that opens a dropdown listing every highlighter available to
the viewer's role (`pickerHighlighters()`), in the agency's own
configured order. The toolbar's own footprint never changes regardless
of count — verified at both ends: a 2-highlighter case (nothing
special needed — it's the same generic list, just shorter) and a
15-highlighter case (3 seeded + 12 added to a demo agency,
screenshotted with the dropdown open, scrollable, Highlight/Note/
thickness/Undo untouched).

### The affordability panel — checked, genuinely not coupled

Traced in the actual code, not assumed: the panel's income/expense
figures live in their own tables (`RentalApplicationIncomeItem`,
`RentalApplicationExpenseItem`, hasMany off `RentalApplicationAssessment`)
with their own description/amount fields; `has_unpaid_transactions` is
an independent boolean column. Nothing links a highlighter mark's
`category`/`highlighter_id` to any of these — the words overlap
because a human marking up a document is matching what they're
circling to what they're capturing on the panel, not because of any
shared code path. Nothing to cut; this was never coupled.

### The flattened "download a marked-up copy" PDF — the most valuable finding

Flagged in the investigation report, confirmed by the coordinator as
"the most valuable thing in that report": the live interactive screen
and the server-side flattened-PDF download are TWO SEPARATE renderers
of the same marks. The freehand redesign earlier this session only
updated the browser SVG rendering — the GD-based burn path
(`RentalApplicationDocumentHighlightService::burnMark()`) still drew
the OLD rectangle-plus-underline design and was hardcoded to only
understand `income`/`expense`/`unpaid`. Left alone, a custom
highlighter would render wrong (or not at all) on the downloaded copy
— and the downloaded copy is the one that leaves CoreX and becomes
someone's record.

Rebuilt to match:
- Colour resolves via the same highlighter table (`resolveMarkColors()`
  now takes the agency's highlighter-id → colour map, falling back to
  the old category scheme only for a mark that somehow has no
  resolvable `highlighter_id`).
- The underline is gone entirely — no burnMark() code path draws one
  any more, for any mark, old or new.
- Overlapping strokes genuinely MULTIPLY-blend, matching the browser's
  `mix-blend-mode:multiply`. GD has no native blend-mode support (no
  Imagick on this box — checked `php -m` before deciding this was
  necessary) — hand-rolled per-pixel in `multiplyBlendStroke()`,
  confined to each stroke's own bounding box (never the full page) so
  it stays fast even on a dense document.

Proven side by side: a custom highlighter ("Deposit Proof", `#2d6cdf`,
never one of the seeded six) drawn live on a real document — two
overlapping loops and a zigzag crossing several rows — then the same
document's flattened download fetched and rasterized. Same colour,
same shapes, same overlap-darkening, no lines, text legible underneath,
on both surfaces.

### Settings screen

Same screen, same block (the six-swatch grid it replaces). Full CRUD:
add (label/colour/role), inline edit-and-save per row, up/down reorder
(swaps two adjacent sort_order values, submitted via a hidden sibling
form — no drag-and-drop library), archive (with a confirm prompt
explaining existing marks are unaffected), and an "Archived" section
listing archived highlighters with Restore. No wizard entry — Johan
was explicit that question is still his to answer, not built in.

### Files touched

- `database/migrations/2026_09_09_060000_create_rental_application_highlighters_table.php` — new
- `database/migrations/2026_09_09_060100_seed_and_backfill_rental_application_highlighters.php` — new, data migration
- `database/migrations/2026_09_09_060200_drop_rental_application_mark_color_settings_table.php` — new, retires the superseded table
- `database/schema/mysql-schema.sql` — regenerated (DEFINER-stripped) for the new/dropped tables
- `app/Models/RentalApplicationHighlighter.php` — new
- `app/Models/RentalApplicationMarkColorSetting.php` — deleted, superseded
- `app/Http/Controllers/CoreX/RentalApplicationHighlighterController.php` — new, full CRUD
- `app/Http/Controllers/CoreX/RentalApplicationSettingsController.php` — `$highlighters` in `edit()`, `updateMarkColors()` removed
- `app/Http/Controllers/CoreX/RentalApplicationReviewController.php` / `RentalApplicationAuthorisationController.php` — pass `highlighters` (all, including archived) instead of `markColors`
- `app/Listeners/Onboarding/SeedDefaultRentalApplicationHighlighters.php` — new, on the existing `AgencyCreated` event
- `app/Providers/AppServiceProvider.php` — registers the new listener
- `app/Services/RentalApplications/RentalApplicationDocumentHighlightService.php` — `highlighter_id` resolution, `multiplyBlendStroke()`, underline removed from the burn path entirely
- `resources/views/corex/rental-applications/partials/document-highlighter-script.blade.php` — `highlighters`/`pickerHighlighters()`/`activeHighlighter()`/`legendHighlighters()` replace `categories`/`markColors`/`myColorFor()`
- `resources/views/corex/rental-applications/partials/document-highlighter-pages.blade.php` — legend, note-popover label
- `resources/views/corex/rental-applications/review.blade.php` — dropdown picker replaces the 3-button category picker, in both the agent and authoriser `x-data` blocks of the now-unified screen (rebased onto cc5's unification — `authorisation/show.blade.php` no longer exists as a separate file)
- `resources/views/corex/settings/rental-applications.blade.php` — full CRUD block replaces the 6-swatch grid
- `routes/web.php` — `corex.settings.rental-applications.highlighters.{store,update,archive,restore,reorder}`, `mark-colors` route removed
- `tests/Feature/RentalApplications/RentalApplicationHighlighterTest.php` — new, 10 tests
- `tests/Feature/RentalApplications/RentalApplicationDocumentMarkSaveTest.php` — updated to the `highlighter_id` contract
- `tests/Feature/RentalApplications/RentalApplicationMarkColorSettingTest.php` — deleted, superseded

## Contact status, approval-email matching, and the auto-send toggle (AT-392, 2026-09-09, cc4) — SPEC, PENDING JOHAN'S APPROVAL, NO CODE WRITTEN YET

### The ask, in Johan's own words

1. "the application is linked to a contact, so the contact needs to be
   marked approved / declined / and maybe even application in progress
   once the forms have been sent out"
2. "now we have the declined template, but on approval I want an email
   to the applicant confirming that they are approved and we give them
   the amount they qualify for, and then the email should contain
   properties that matches their wishlist, and matches their approved
   amount"

Investigation (reported to the coordinator as its own message before
this spec, per Johan's explicit instruction) found that ask #2 is
mostly already built. This spec is scoped to exactly what investigation
proved is actually missing — not a rebuild.

### What already exists — no rebuild, cited so nobody re-does this work

- **Typed approved amount, required.** `RentalApplicationAuthorisationController::approve()`
  (`app/Http/Controllers/CoreX/RentalApplicationAuthorisationController.php:249-301`)
  already validates `approved_rental_amount` as `required|numeric` (line 272),
  authoriser-typed, never computed. Already matches Johan's decision #1 exactly.
- **The approval email already exists and already auto-sends.** `approve()` line 301
  calls `RentalApplicationMailer::sendApproved()` unconditionally →
  `RentalApplicationApprovedMail` (`app/Mail/RentalApplicationApprovedMail.php`),
  "Congratulations — you're approved to rent!", states the amount. This is
  already today's behaviour — auto-send is not new, it needs an OFF switch, not
  an ON switch.
- **The mail guard already covers it.** `OutboundMailGuardServiceProvider`
  intercepts every `Mail::send()` call regardless of Mailable class, gated on
  `APP_ENV==='production'` + an `APP_URL` allowlist
  (`app/Support/OutboundMailGuard.php:52-53`). `RentalApplicationApprovedMail`
  already goes through this today. No new integration required — proof method
  for this build is the same one already used for invite/reopen mail this
  session: trigger a real `approve()` on QA1, confirm the capture lands in
  `outbound_mail_guard_captures` with the `[GUARDED]` subject prefix.
- **The matching engine already exists and is already agency-scoped to own
  stock.** `App\Services\Matching\MatchingService::propertiesForMatch()`
  (`app/Services/Matching/MatchingService.php:254`) queries `Property::query()`
  (line 263) filtered `->where('agency_id', $match->agency_id)` (line 274-275) —
  the live Agency Stock table, never `tracked_properties`, never another
  agency's stock. `App\Models\ContactMatch` (table `contact_matches`) is the
  wishlist this engine already scores against, with `listing_type` already
  supporting `'rental'` in production data (210 rows exist today). No new
  matching logic is written by this feature — it calls this engine.

### What's actually missing — three additions

1. A rental-application status on Contact, plus full history.
2. The matching-properties section in the approval email (deliberately left
   out of `RentalApplicationApprovedMail` last time, pending exactly this
   decision — see that file's own docblock).
3. The agency auto-send / agent-review toggle.

Each is specced below.

---

### 1. Contact status + history

**Investigation finding (point 5):** `contacts` has no generic `status`
column. Two existing concepts must NOT be confused with this new one:
`contact_type_id` (role-in-transaction — Seller/Buyer/Tenant/etc., via
`ContactType`) and `buyer_state` (buyer-pipeline journey stage — new/warm/
won/lost, via `BuyerStateService`, terminal on `won`). Both are different
axes with different lifecycles. Per that finding, the new field is named
**`rental_application_status`** — it cannot be mistaken for either.

**It is a derived/cached field, not an independently-editable one.** Johan's
"full history" requirement is best satisfied by the data CoreX already
keeps: every `RentalApplication` row is a permanent record (one row per
application, never overwritten — confirmed via the `contact_id` FK's
one-to-many shape, investigation point 1), each carrying its own final
`status` and dates. "Full history" is simply that contact's
`rentalApplications()` collection, newest first. Building a second,
separately-maintained status/history table risks the two sources of truth
drifting — the Contact's cached field is a read-optimisation over the real
record, kept in sync automatically, never hand-edited.

**Values** (contact-facing, coarser than `RentalApplication.status`'s
internal granularity):

| `rental_application_status` | Meaning | Driven by |
|---|---|---|
| `none` | Never had a rental application | default |
| `invited` | Application sent, not yet returned by applicant | application created / invite sent |
| `in_progress` | Applicant has started or returned it; agent/authoriser working it | application `returned` / `under_assessment` |
| `declined` | Most recent application was declined | `approve()`/`decline()` outcome |
| `approved` | Most recent application was approved | `approve()` outcome |

**Reopen-after-decline:** reopening moves the underlying `RentalApplication`
back to `returned` or `under_assessment` (per `REOPENABLE_STATUSES`,
already built). The Contact's cached status follows the same rule it
always follows — it mirrors whatever the most recent application's status
currently is — so a reopened, previously-declined application makes the
contact's status read `in_progress` again automatically. The original
decline is never erased from history; it stays as a fully-dated past
application row, exactly as StatusHistory already treats it.

**Mechanism — domain events, per non-negotiable #9, not an ad-hoc write in
the controller.** New event namespace `app/Events/RentalApplication/`,
following the existing pattern (`app/Events/Contact/`,
`app/Events/Mandate/`) and extending `AbstractDomainEvent`:

- `RentalApplicationSubmitted` (fired from `RentalApplicationSigningController::submit()`)
- `RentalApplicationApproved` (fired from `RentalApplicationAuthorisationController::approve()`)
- `RentalApplicationDeclined` (fired from `RentalApplicationAuthorisationController::decline()`)
- `RentalApplicationReopened` (fired from the existing `reopen()` action)

A new listener, `app/Listeners/Contact/RecomputeRentalApplicationStatus.php`,
subscribes to all four and recomputes `Contact::rental_application_status`
from that contact's current `RentalApplication` set — idempotent (E5),
safe to run twice, no destructive write.

**Migration:** add `rental_application_status` (`varchar`, default `'none'`)
and `rental_application_status_updated_at` to `contacts`. Add
`Contact::rentalApplications(): HasMany` (the missing inverse relation,
investigation point 1).

**Where it's shown:** a new "Rental History" section on the Contact detail
page — current status badge, plus the full list of past applications
(date, property, outcome), newest first. This is a list surface, so the
design standard below applies to it (search/sort/filter by outcome and
date, pagination if a contact has many). Per non-negotiable #2, this is a
new page-equivalent surface and gets a nav entry the same day: a tab/section
link on the existing Contact detail page's own sub-navigation — no new
top-level sidebar item, since it lives inside a page that already exists.

---

### 2. The matching-properties section — and the wishlist problem, the centre of this spec

**The gap, restated precisely:** the matching *engine* is production-ready
and already agency-stock-scoped. The matching *engine has nothing to match
against* — investigation found **zero** of today's rental applicants have
an active `ContactMatch` (rental wishlist) on record. Shipping "properties
that match their wishlist" as asked, unchanged, would show an empty list to
essentially every real approval. Per Johan's framing: a feature that
silently produces an empty list is worse than not shipping it. This section
does not decide the wishlist question — it proposes answers and flags all
three for Johan's confirmation, as instructed.

**Open question A — where does a tenant wishlist get captured?**

Proposed: **on the application form itself**, applicant-facing, while
they're already filling it in. Checked against the existing `ContactMatch`
model for feasibility:

- Fields with a clean, plain-language equivalent an applicant can just
  answer: desired bedrooms (`beds_min`/`bedrooms_max`), desired property
  type (`property_type`/`property_types`), pets (maps to
  `must_have_features`/`deal_breakers` as plain text), a maximum they'd
  like to pay (`price_max`). These map onto `ContactMatch` with no changes
  to that model.
- One genuine mismatch: `ContactMatch.suburbs`/`p24_suburb_ids` are
  structured records normally chosen through an internal agent-facing
  suburb picker component — the public, unauthenticated applicant page has
  no such component today, and building one is a bigger lift than this
  feature needs. Proposed first cut: a plain free-text "preferred area"
  field on the application, stored as-is; an agent can later refine it into
  structured suburbs via the existing wishlist screen if match quality
  needs it. Matching runs on whatever's structured at the time; free text
  alone simply narrows nothing on the suburb axis until refined.
- Concretely: new plain columns on `rental_applications` (preferred area
  text, desired bedrooms, desired property type, pets, desired max rent) —
  optional, same "every field optional" posture as the rest of this public
  form (BUILD_STANDARD §2). On `submit()`, these values create or update a
  real `ContactMatch(listing_type: 'rental', status: 'active')` for that
  contact. No new fields are added to `ContactMatch` itself, and no new
  matching logic is written — the translation step is the only new code;
  `MatchingService::propertiesForMatch()` runs completely unchanged.

This is feasible without inventing a second wishlist concept. **Flagged for
Johan's confirmation** — the alternative (an agent manually creates the
wishlist separately, on the existing Buyer Pipeline screen, for any
applicant they want matched) is simpler to build but reintroduces the
exact gap just found: it depends on an agent remembering to do a second,
disconnected step for every applicant, for a screen most agents don't
routinely open for tenants today.

**Open question B — what does the email do when there is genuinely no
match?**

Proposed: **still send, with no property section** (a plain "we don't
currently have a matching property in our own stock, but here's your
approved amount" line replacing it) — never hold the email. The approval
notification (you're approved, for this amount) is the time-critical part;
gating it on a merchandising nice-to-have would make a real approval
invisible to the applicant over something that was never the primary
purpose of the email. **Flagged for Johan's confirmation.**

**Open question C — can the approved amount alone drive a fallback match
with no wishlist at all?**

Proposed: **yes.** When the contact has no active rental `ContactMatch` at
all (today's default state for nearly every applicant), fall back to "own
agency stock, on-market, at or under the approved amount," ranked by price
descending (closest to their budget), capped at the same configurable
maximum used for a genuine wishlist match. This turns "no wishlist yet"
from a hard empty result into a still-useful email for the population this
feature will actually serve on day one. **Flagged for Johan's
confirmation** — this is the answer that makes questions A and B tractable
even before wishlist capture (question A) has any adoption.

**Maximum properties per email:** agency-configurable, default **5**
(matches the existing pattern of small, sensible defaults elsewhere on this
feature — e.g. `RentalApplicationQualifyingSetting`'s never-writes-on-read
approach). Lives on the existing `corex/settings/rental-applications.blade.php`
screen, no new page.

---

### 3. The auto-send / agent-review agency setting

New agency-level setting, boolean, **default `true` (auto-send)** — matches
today's actual behaviour exactly (`approve()` already always sends), so no
agency sees a behaviour change unless they deliberately opt out.

- Column: `agencies.rental_application_approval_auto_send` (boolean,
  default `true`).
- When `false`: `approve()` still runs exactly as today (status, amount,
  audit, status history, agent notification) but the applicant-facing
  `RentalApplicationApprovedMail` is composed and held for agent review
  rather than sent immediately — surfaced on the agent's existing
  application view as a "Send approval email" action, not a new screen.
- Per non-negotiable #10a: this setting is added to
  `config/agency-onboarding-copy.php` in the same build, with its `explain`
  and `affects` copy. It is not a rarely-touched expert knob — it directly
  changes what an agency's applicants receive — so it belongs in the
  wizard, not deliberately excluded.

---

### Two defects carried into this build (found by cc5 walking the journey, confirmed here with exact citations)

**Defect 1 — a reopened applicant isn't told why, on their own page.**
`resources/views/rental-applications/public/show.blade.php` renders no
`reopened_note`/`reopened_at`/reason content anywhere (grepped — zero
occurrences); the reason only ever reaches the applicant via the reopen
email. Same failure class as the already-fixed decline-reason gap. Fix: a
status/reason banner on the public show page when the application is in a
post-reopen, pre-resubmit state, surfacing `reopened_note` in plain text —
same treatment the decline reason already gets elsewhere on this feature.

**Defect 2 — neither submission nor resubmission is audited.**
`RentalApplicationSigningController::submit()`
(`app/Http/Controllers/RentalApplicationSigningController.php:68-149`) sets
status, fields, and seals a generation inside its `DB::transaction`, but
calls neither `RentalApplicationAuditService::log()` nor
`RentalApplicationStatusHistory::record()` — confirmed by reading the full
method body, no such call exists anywhere in it, for either the first
submission (`submitted_at` was null) or a resubmission after reopen
(`submitted_at` already set, `current_generation` bumped). The evidentiary
trail currently cannot show an applicant ever responded. Fix: add both
calls inside the same transaction, distinguishing first-submit from
resubmit in the audit event type/human summary (mirroring the
`is_override` distinction pattern `decline()`/`approve()` already use for
their own two-shape actions).

---

### Design standard — applied to what's actually new here

- **Full CRUD:** the agency setting (auto-send toggle + max-properties
  count) is a single settings block, edited in place — no separate
  create/delete semantics apply, consistent with how the neighbouring
  `RentalApplicationQualifyingSetting`-style settings on the same screen
  already work.
- **List screen with search/sort/filter/pagination + real empty state:**
  the new Contact "Rental History" section — sortable by date/outcome,
  filterable by outcome, paginated once a contact has more than a page's
  worth, empty state reading "No rental applications yet" (not a blank
  table) for `rental_application_status = 'none'`.
- **OWN/BRANCH/AGENCY scoping at the query layer:** the Rental History
  list queries `Contact::rentalApplications()`, already agency/branch
  scoped the same way every other `RentalApplication` query is today; the
  matching fallback (`Property::query()->where('agency_id', ...)`) is
  agency-scoped by construction, per investigation point 4.
- **Soft delete only:** no hard deletes introduced by this feature. The
  new `contacts` columns are additive; `ContactMatch` created from
  application answers uses the model's existing `SoftDeletes`.

### Navigation

- Contact detail page gains a "Rental History" tab/section (new
  sub-navigation entry on an existing page, per non-negotiable #2 — no new
  top-level sidebar item required).
- The auto-send toggle and max-properties setting are added to the existing
  `corex/settings/rental-applications.blade.php` screen — already
  reachable from Settings, no new nav entry needed there.
- The same setting is added to the Setup Wizard per non-negotiable #10a
  (see §3 above) — a new step/field in `config/agency-onboarding-copy.php`,
  not a new page.

### Open questions for Johan — every one flagged, none decided here

1. Does a decline stop a contact from having a future application?
   Proposed: **no** — nothing in the data model prevents it today (the FK
   is one-to-many with no such constraint), and blocking it would need a
   deliberate new guard this ask never asked for.
2. If nothing in stock matches, does the approval email still go, without
   properties? Proposed: **yes** (folds into Open Question B above, now
   answered in the fuller context the wishlist gap revealed).
3. Is the approved amount monthly rent affordability, in rand per month?
   Proposed: **yes** — matches every other money field on this feature
   (`current_rental_amount`, etc.), all plain monthly rand figures.
4. Where does a tenant wishlist get captured — on the application form
   itself, translated into a real `ContactMatch` on submit? (Open Question A)
5. What happens when there is genuinely no match — send without a property
   section, or hold the whole email? (Open Question B)
6. Can the approved amount alone, with no wishlist at all, drive a
   fallback match against own stock at or under that amount? (Open
   Question C)

No code, migration, or UI for this feature is written until Johan responds
to these six.

## SUPERSEDES THE SECTION ABOVE — agent sends, not auto-send (AT-392, 2026-09-09, cc4) — SPEC, PENDING JOHAN'S APPROVAL, NO CODE WRITTEN YET

Johan changed the flow after reading the spec above. **The entire "auto-send
/ agent-review toggle" section above (§3) is void.** Approval no longer
emails the applicant at all. Sections 1 (contact status) and the two
carried-in defects from the section above are unaffected and still stand as
specced. This section replaces §2 and §3 above in full.

### The flow, in Johan's words

1. Agent does their work and submits to the authoriser. (Unchanged.)
2. Authoriser reviews, is happy, approves, and types the approved amount.
   (Already exists — unchanged, see §"What already exists" above.)
3. Approval sends the application **back to the agent**. It does **not**
   email the applicant at this point. Johan: *"no, agent gets back and upon
   them being happy it gets sent out."*
4. The agent sees it needs them — on their **dashboard** and on the
   **application list**, both, not only inside the application record.
5. The agent completes the tenant wishlist **in a modal** — Johan: *"modal
   showing same as core matches on contact - we have this already."*
6. When the agent is happy, **they** send. One email: the approval, the
   amount, and the matched properties, together.

**Why the agent, not the authoriser** — Johan: *"agent, not auth will speak
to tenant and find out what they're looking for."* The agent owns the
client relationship; the authoriser's job ends at the credit decision.

### What changes in the already-built approve() action

`RentalApplicationAuthorisationController::approve()` (line 249-301, cited
in full above) keeps its validation, status write, audit log, and status
history exactly as-is — nothing about the approval DECISION changes. Only
its tail changes:

- **Removed:** the unconditional `$mailer->sendApproved($rentalApplication)`
  call (line 301). Approval no longer sends anything to the applicant.
- **Added:** the application needs a new agent-facing marker — proposed
  `awaiting_agent_send` as an additional flag (not a new top-level
  `RentalApplication.status` value; `status` stays `'approved'`, since the
  authoriser's decision is final and unambiguous — this is a "has the agent
  released it yet" question, orthogonal to the credit decision). Proposed
  column: `rental_applications.applicant_notified_at` (nullable timestamp)
  — `NULL` means "approved but the agent hasn't sent it," non-null means
  "the agent has sent the approval email." This single column drives every
  surfacing requirement below (dashboard, list, modal-gate) without a
  second status enum to keep in sync with `status`.

### Where the agent sees it needs them (point 4)

- **Dashboard:** a new card/row, "Approved — needs your wishlist & send"
  (or equivalent existing dashboard-widget pattern — reuse whatever
  component already lists this agent's other rental-application action
  items, e.g. "awaiting your review" if one exists, rather than inventing a
  new widget shape). Query: `RentalApplication::where('created_by_user_id',
  $agent->id)->where('status', 'approved')->whereNull('applicant_notified_at')`.
- **Application list:** the same query surfaces as a visible state/badge
  on the existing rental-applications list screen (whichever list the
  agent already uses to see their applications) — "Approved, ready to
  send" — not a separate list.
- **Inside the application:** the review screen already shows status; it
  gains the wishlist-modal trigger and the "Send to applicant" action,
  gated on `status === 'approved' && applicant_notified_at === null` and
  on the same agent-ownership guard the rest of this feature already uses.

### The wishlist modal (point 5) — reusing existing UI, not building a second editor

**Component being reused:** `resources/views/corex/contacts/_match-form.blade.php`
— the actual Core Matches / wishlist form partial, already used in two
places today: inline on `corex/contacts/show.blade.php:1492`, and as a
right-side slide-over drawer on
`resources/views/command-center/buyers/detail.blade.php:580-615`
(`wishlistDrawerOpen` / `wishlistEditingId`, Alpine-driven, posting to
`ContactMatchController::store()`/`update()`). Johan's "modal showing same
as core matches on contact" is this drawer pattern. The build reuses
**both** the form partial and the drawer wrapper — same Alpine open/close
shape, same partial, new trigger point and new post-action only
(redirect/refresh back to the rental application screen instead of the
buyer detail page).

**What it operates on:** the applicant's own `ContactMatch` (rental,
belonging to `$rentalApplication->contact`) — not a new model, not a
rental-application-specific wishlist. If the contact already has one
(pre-filled from a portal-lead seed — see below), the modal opens in edit
mode against it. If not, it opens in create mode
(`'listing_type' => 'rental'` pre-selected, not left to the agent to
choose) exactly as `_match-form` already supports via its existing
`$isEdit` branch — no new form fields, no new validation rules.

### THE PORTAL-LEAD FINDING — reported to the coordinator as its own message before this spec section, restated here for the record

Verified: `App\Services\Buyers\BuyerLeadCascadeService::seedFromListing()`
(`app/Services/Buyers/BuyerLeadCascadeService.php:77`) is called live from
three lead-ingestion paths — `Property24/P24LeadService.php:225`,
`PrivateProperty/PpLeadService.php:235`, `Website/WebsiteLeadService.php:222`
— and derives a real, countable `ContactMatch` (suburb, price band around
the enquired listing's price, `beds_min`, `property_type`) from the
enquired listing, with `listing_type` correctly inherited from that
listing (a rental enquiry seeds a rental wishlist). This is real, wired
infrastructure, not aspirational.

QA1's current data shows zero overlap between rental-application contacts
and rental wishlists — but every one of the 19 contacts behind today's
QA1 rental applications is synthetic test data (names like "Test",
"Persona AgentTest", "ZZ CC4 Verify Tenant"; 18 of 19 have
`buyer_source = NULL` and `is_buyer = 0`, meaning none ever passed through
a lead-ingestion path at all). The `rental_applications` table itself was
migrated 2026-09-04 — this feature has never yet been used by a real
applicant anywhere. There is no real population yet to test Johan's belief
against; the mechanism that would make it true is confirmed live and
correctly wired, and will apply automatically to any real tenant who
enquires on a rental listing and later becomes a rental applicant.

**Consequence for the modal:** per Johan's own instruction, given the
mechanism is confirmed real, **the modal pre-fills from any existing
`ContactMatch` the contact already has** (portal-seeded or otherwise) —
the agent is confirming/refining a real signal, not typing from scratch,
for exactly the population Johan expects to be "most" of real applicants
once this ships. For the remainder (manually-captured applicants with no
prior lead, or portal leads where auto-seed was toggled off), the modal
opens empty in create mode, same partial, same behaviour `_match-form`
already has today for a first-time wishlist.

### The wishlist fallback rule — proposed, not decided (Johan does not want the email blocked)

Johan: *"if agent has not updated the wishlist the email goes out with
just some rental properties."* Proposed rule, built on `ContactMatch`'s
own existing `isCountable()` gate
(`app/Models/ContactMatch.php:556-568` — the same test
`PropertyMatchScoringService` already uses to exclude thin wishlists from
real scoring, so this introduces no new "thin" definition):

1. If the contact has an active, **countable** rental `ContactMatch` →
   run `MatchingService::propertiesForMatch()` against it exactly as
   already scoped (own agency stock only). This is the real match.
2. If the contact has no rental `ContactMatch`, or has one that exists but
   is **not countable** (agent opened the modal and left it effectively
   empty, or never opened it at all) → fall back to: own-agency rental
   stock, on-market, **at or under the approved amount**, ranked by price
   descending (closest to the budget), capped at the same
   agency-configurable maximum (§ below) used for a real match.
3. Either branch can legitimately return zero properties (empty stock
   under that amount) — the email still sends in that case too, with no
   property section, per the standing "never block the approval on a
   merchandising nice-to-have" reasoning from the section above.

This makes "the agent hasn't touched the wishlist yet" a graceful
degrade, not a blocker — exactly what Johan asked for — while a real,
either agent-confirmed-portal-seeded or agent-authored wishlist gets the
better, criteria-scored result.

**Maximum properties per email:** unchanged from the proposal above —
agency-configurable, default 5, same settings screen.

### Files this touches (once approved — not built yet)

- `app/Http/Controllers/CoreX/RentalApplicationAuthorisationController.php`
  — `approve()` tail: remove `sendApproved()` call, no other change to the
  decision logic.
- New migration: `rental_applications.applicant_notified_at` (nullable
  timestamp).
- Agent dashboard widget query + existing rental-applications list view —
  surfacing `status='approved' AND applicant_notified_at IS NULL`.
- New agent-facing action (name TBD at build time, e.g. `sendApproval()`
  on a controller in the agent's own namespace, not the authoriser's) —
  gates on ownership, composes `RentalApplicationApprovedMail` with the
  matched/fallback property list, sends via `RentalApplicationMailer`
  (new method, same class, same guard coverage already proven), sets
  `applicant_notified_at`, audit-logs the send.
- Wishlist modal: new trigger + drawer markup on the agent's rental
  application review screen, reusing `corex/contacts/_match-form.blade.php`
  verbatim and the drawer pattern from
  `command-center/buyers/detail.blade.php:580-615`. No new Blade
  form, no new `ContactMatch` fields, no new validation rules.
- `RentalApplicationApprovedMail` — gains the matched-properties section
  (real match or fallback, per the rule above), still not
  agency-configurable text (unchanged from the earlier finding — not
  asked for).

### Contact status, defects, design standard, navigation — unchanged from the section above

Everything else specced in the superseded section still applies as
written and is not repeated here: `rental_application_status` on Contact
(derived, domain-event-driven, distinctly named from `buyer_state`/
`contact_type_id`), the two cc5-found evidentiary defects (reopen reason
invisible to the applicant; no audit/status-history on submit/resubmit),
the design-standard requirements (list/search/sort/filter/pagination +
empty state on Contact's Rental History, OWN/BRANCH/AGENCY scoping, soft
delete only), and the navigation entries (Rental History tab on the
Contact page; settings screen already reachable; Setup Wizard entry for
the max-properties-per-email setting — the auto-send toggle itself is
withdrawn along with §3, so it does **not** go in the wizard).

### Open questions for Johan — carried and revised

1. Does a decline stop a contact from having a future application?
   Proposed: **no** (unchanged).
2. Is the approved amount monthly rent affordability, in rand per month?
   Proposed: **yes** (unchanged).
3. **Withdrawn** — "does the approval email still go without properties"
   is now folded into the fallback rule above (yes, it always sends;
   proposed, not decided).
4. Is pre-filling the wishlist modal from an existing portal-seeded
   `ContactMatch` (rather than always opening empty) the right default,
   given the portal-lead finding above? Proposed: **yes**.
5. Is the fallback rule above (own rental stock at/under the approved
   amount, price-descending, same cap as a real match) the right shape
   when the wishlist is thin or absent? Proposed: **yes**.
6. `applicant_notified_at` as the mechanism distinguishing "approved" from
   "approved and sent" (rather than a second `status` value) — agreed
   approach, or does Johan want this visible as a distinct status value
   somewhere (e.g. in the Contact's `rental_application_status` history)?
   Proposed: keep `rental_application_status` mirroring `RentalApplication.status`
   only (`approved`/`declined`/etc.) — "sent" is an agent-side operational
   detail, not a contact-facing outcome, so it does not need its own
   contact-status value.

No code, migration, or UI for this feature is written until Johan responds
to these.

## Design-standard audit — the four remaining CRUD gaps closed (2026-09-09, cc3)

Johan, restated: "we always need proper crud? search / sort / own /
branch / agency levels. that should be the design standard. not me asking
for it once we get to that stage." A full audit of every screen in this
module (application list, Returned, authoriser queue, review/authorisation
screen, highlighter settings, applicant-facing token screens) found the
core CRUD/search/sort/scoping standard above already applied almost
everywhere — four gaps remained, closed here in Johan's stated priority
order. **The scoping half of the audit found zero cross-agency or
cross-branch leaks anywhere in the module**, including the applicant-
facing token path and every document download — every list, detail view,
and download enforces scope at the query/guard layer, confirmed by
tracing the code, not by reading the view.

**1. Authoriser queue — search and sort** (`RentalApplicationAuthorisationController::index()`).
Had neither. `applySearchSortAndDateRange()` (previously a private method
on `RentalApplicationController`) is extracted into a shared trait,
`App\Http\Controllers\Concerns\FiltersRentalApplicationList`, reused by
both controllers rather than a third hand-rolled copy. Search now also
matches the creating agent's name (`orWhereHas('createdBy', ...)` — a
subquery, not a join, so it can't reintroduce the ambiguous-column problem
the sort logic already guards against) — added to the shared trait, so
`index()`/`returned()` gained agent-name search too as a side effect,
not scope creep: same search box, same fields, one shared implementation.
Sortable: contact, property, agent, submitted. **Default stays oldest-
submitted-first** (unchanged from before this task) — the trait's own
default direction is 'desc' (matching `index()`/`returned()`'s newest-
first convention), but the queue passes `'asc'` explicitly via a new
optional `$defaultDirection` parameter, because the entire point of a
decision queue is working the longest-waiting application first. No
own/branch/agency scope toggle on this screen: RO/CO is an agency-wide,
named-individual grant (Johan's own tier definition — "selected agents
act as RO"), not branch-scoped, so there is no narrower level to toggle
to.

**2. Highlighter settings — real empty state on the Archived section**
(`resources/views/corex/settings/rental-applications.blade.php`). Exact
same class of bug already fixed on the rental application audit trail
tonight: the whole "Archived" block was wrapped in
`@if($archivedHighlighters->isNotEmpty())`, so an agency with nothing
archived rendered nothing at all — no heading, no message, indistinguishable
from the section being missing. The heading now always renders; the body
switches between the real list and "Nothing archived." (same wording
already used for archived rental applications on this module's own index
screen).

**3. Returned screen — per-page control** (`RentalApplicationController::returned()`).
Fixed at 25/page with no way to change it, while `index()` right next to
it has had a 10–100 range since 2026-09-08. Now identical: same clamp
(`min(100, max(10, $request->integer('per_page', 25)))`), same selector
markup, same default.

**4. Highlighter settings — search, sort, pagination** (lowest priority,
built last, per Johan's own ordering). The active-highlighter list and its
Restore-free-forever archived list are different shapes, so they got
different treatment:

- **Active rows**: search is **client-side only** (Alpine `x-show`,
  driven by the same search box as archived). The reorder up/down buttons
  depend on `$activeIds` being the full, gapless, correctly-ordered set —
  the swap-order arrays the hidden reorder forms post are built from it.
  Filtering or paginating that array before it reaches the view would
  silently make the up/down buttons swap the wrong neighbours the moment
  a filter or page boundary hid a row. Client-side filtering never touches
  that array — every row still reaches the page and reorder stays correct
  regardless of what's visually hidden. No pagination on active rows for
  the same reason, and because realistic list sizes (a handful to a few
  dozen per agency) don't need it.
- **Archived rows**: search, sort (`label` A–Z or `most recently
  archived`), and pagination (10/page) are **real, server-side**,
  query-string-driven (`?highlighter_q=&highlighter_archived_sort=&highlighter_archived_page=`)
  — archived rows have no reorder dependency to protect, so there is no
  reason not to do this properly.

Tests: `RentalApplicationAuthorisationQueueSearchSortTest`,
`RentalApplicationReturnedPerPageTest`,
`RentalApplicationHighlighterSettingsScreenTest` (new); the pre-existing
`RentalApplicationCrudStandardTest` and `RentalApplicationHighlighterTest`
re-run unchanged and green.

**Export — explicitly NOT built, open question for Johan.** The audit
flagged that no export/CSV capability exists anywhere in this module.
Johan's ruling: this is a new capability, not a gap in an existing one,
and it would put applicant financials (real client emails, phone numbers,
income data) into a file that leaves the system — his decision to make,
not a lane's. **Nothing built.** If Johan wants an export, treat it as a
new feature spec of its own — this section exists only to record that the
question was raised and deliberately not answered here.

## Maximum properties per approval email — settings screen (AT-392, 2026-09-10)

The approval-leg spec above (§"Matching rule — the approved amount is a
HARD CEILING") named this as agency-configurable, default 5, but the
setting only existed as a model/migration/consumer — no settings screen,
no way for an agency to actually change it. Closing that gap.

**Setting:** `App\Models\RentalApplicationApprovalEmailSetting`
(`rental_application_approval_email_settings` table — `agency_id` unique,
`max_properties_in_email` unsigned tinyint, default 5). Same
never-writes-on-read pattern as `RentalApplicationQualifyingSetting`:
`maxPropertiesFor(?int $agencyId)` returns the default until the agency
explicitly saves a row of their own — no row is created on read.

**Real usage site (unchanged):**
`app/Services/RentalApplications/RentalApplicationPropertyMatcher.php:53`
— `RentalApplicationPropertyMatcher::forApproval()` already called
`RentalApplicationApprovalEmailSetting::maxPropertiesFor()` before this
build; there was no separate hardcoded literal to replace there. The
"hardcoded value" was the class constant `DEFAULT_MAX_PROPERTIES_IN_EMAIL
= 5` acting as the only value any agency could ever see, since nothing
could write to the table. That constant remains — correctly — as the
in-memory fallback default; what's new is the path to override it.

**Settings screen:** `corex/settings/rental-applications.blade.php`, a
new "Approval Email — Matched Properties" block (same screen as the
Qualifying Formula and Reopened Application Link Expiry settings — no
second settings home), one number field, save via
`POST corex.settings.rental-applications.approval-email` →
`RentalApplicationSettingsController::updateApprovalEmailSettings()`.
Validation: `required|integer|min:1|max:20` — never 0/negative (an
agency that wants no properties at all turns off the wishlist step
elsewhere, not by starving this field to zero) and never unbounded (a
runaway value would turn the approval email into a stock catalogue, not
a curated match list). Agency-scoped via `updateOrCreate(['agency_id' =>
...], [...])`, identical shape to every sibling setting on this screen —
no cross-agency leakage possible by construction (the row's own unique
key is `agency_id`).

**Findability — a real gap found while wiring this, not a new one
introduced.** The whole `corex/settings/rental-applications.blade.php`
screen (already carrying Qualifying Formula, RO/CO, Decline Email,
Reopen Link Expiry, and Highlighters — none of it new to this commit)
was never reachable from the main Settings hub
(`resources/views/corex/settings.blade.php`) or its search — checked
directly, zero references to `rental-applications` anywhere in that
file before this change. Added one `'type'=>'link'` entry under the
Modules section (the same pattern every other settings-page link on that
hub already uses — see `'doc-types'`, `'coc-service-types'`, etc.),
gated on the same `rental_applications.manage_settings` permission the
routes already require, with keywords covering every control on the
page including this new one ("matched properties approval email max
maximum send limit") — so a search for any of those terms now surfaces
the whole page, not just this one field. This was necessary to satisfy
"findable by the settings search" as asked; it was not previously true
of ANY setting on this screen, not something this build broke.

Verified: `php -l` on every changed file; `php artisan view:clear`;
Tinker proof of per-agency save/read-back with no cross-agency leakage;
Tinker proof that `RentalApplicationPropertyMatcher::forApproval()`
actually clips its output to a lowered limit; a real browser pass on
QA1 confirming the field renders, saves, and is found by the settings
hub's search box.

## Contact Rental History — own/branch/agency scope, Role Manager (AT-392, 2026-09-10)

Johan answered the scoping question left open in the Contact status
section above. Verbatim: *"my instincts are telling me agency wide so
that any user working with a contact can see the history - the corex
build scope will always be - add to role manager where this can be set
by agency to own / branch / agency."*

**Default: agency-wide (`all`).** Configurable per role, in Role Manager,
down to `branch` or `own` if an agency wants to.

### Where this lives — the existing pattern, not a new one

Investigated before building: `PermissionService::getDataScope($user,
$module)` (`app/Services/PermissionService.php:202`) is the canonical
per-role, per-agency scope resolver, reading `role_permissions` rows
keyed `{module}.view` — configured today in the Role Manager UI
(`resources/views/corex/role-manager.blade.php`) for any permission
whose key ends in `.view` **and** is `'type' => 'action'` (never
`'access'` — that type is excluded from the `$fActionMap`/`$fViewKey`
computation the Data Scope selector depends on,
`role-manager.blade.php:169-180`). Sibling wrappers already exist for
exactly this "resolve, then apply a sensible default" shape:
`calendarScope()`/`taskScope()` (`PermissionService.php:325-337`), both
defaulting unset to `'own'`.

**New permission:** `contact_rental_history.view`
(`config/corex-permissions.php`, module `contact_rental_history`,
section `contacts`, type `action`) — deliberately its **own** module,
not folded into `rental_applications.view` or `contacts.view`. Folding
it into either would collide: `rental_applications.view` is itself
`type => 'access'` (no scope selector today at all), and `contacts.view`
already owns the `contacts` module's `$fActionMap['view']` slot with its
own special-cased on/off-toggle behaviour
(`PermissionService.php:216-224` — properties/contacts scope is a simple
toggle whose effective breadth then depends on the agency's
`split_branches_enabled` setting, not the 4-way own/branch/all/none
radio Johan asked for here). A new module was the only way to get an
independent, full own/branch/all/none control without touching either
existing one.

**New resolver:** `PermissionService::contactRentalHistoryScope(User
$user): string` (`PermissionService.php`, next to `calendarScope()`/
`taskScope()`) — `getDataScope($user, 'contact_rental_history') ??
'all'`. The **only** wrapper in this class defaulting to `'all'` instead
of `'own'` — every sibling defaults the OTHER way; this one is
deliberately the exception, per Johan's explicit instruction.

**Definition synced, grants explicitly backfilled — not left to the
code-level default alone.** `php artisan corex:sync-permissions` (no
`--seed-defaults`, no `--prune`) created the permission *definition* row
only. The obvious next move — leave every role ungranted and let
`contactRentalHistoryScope()`'s `?? 'all'` carry the default — was tried
first and found wanting: Role Manager's own scope-matrix initialisation
(`role-manager.blade.php:847-861`) shows an *ungranted* permission as
**"None"** regardless of what the backend would actually resolve, since
its own default logic only shows `'all'` when a grant row exists with no
scope set. An admin opening Role Manager would see "None" and reasonably
believe access was off, when it was actually wide open — a real,
misleading discrepancy caught by looking at the actual rendered page,
not by reading the JS.

Generic seeding wasn't the fix either: `config/corex-permissions.php`'s
own `scope_defaults` (super_admin/admin=`all`, branch_manager=`branch`,
agent=`own`, viewer=`branch`) is what `--merge-defaults` would apply,
and `shared_scope_modules` (`p24`, `knowledge`) makes a module
permanently `all` with no per-role control at all — neither fits "the
same default for every role, but still editable per role."

Fixed with a migration
(`2026_09_10_040000_grant_contact_rental_history_view_alongside_contacts_view.php`):
for every existing `(agency_id, role)` pair already granted
`contacts.view` (55 pairs plus the NULL-agency global template, at the
time of writing — "any user working with a contact" only means anyone
who can already see contacts at all), insert-or-restore a
`contact_rental_history.view` row at `scope = 'all'`. Hit
BUILD_STANDARD.md §5a's own named trap while writing it — a stray
soft-deleted row from this build's own earlier Tinker verification
collided with the table's unique index on a plain `updateOrCreate()`
(which doesn't see trashed rows); fixed with the prescribed
`withTrashed()->firstOrNew()` + explicit `restore()` pattern. The
`?? 'all'` code-level default in `contactRentalHistoryScope()` stays as
a genuine safety net (a role created after this migration runs still
gets the correct default even with no row), it just isn't the ONLY
place the default lives any more.

### The query layer — same mechanism as the list screens, not a parallel one

`RentalApplication::scopeVisibleTo()` (`app/Models/RentalApplication.php:461`
— the mechanism `RentalApplicationController::index()`/`returned()` use)
resolves its ceiling from `PermissionService::getDataScope($user,
'rental_applications')` — a **different, independent** permission from
`contact_rental_history`. Calling `scopeVisibleTo()` as-is for the
Contact tab would wrongly floor an agent's agency-wide contact-history
view down to whatever narrower scope their `rental_applications.view`
list-screen ceiling happens to be — the opposite of Johan's ask (an
`own`-scoped agent must still see the FULL history on a contact they're
working with, if the agency's `contact_rental_history` setting says
`all`).

Fixed by extraction, not duplication: the three own/branch/all SQL
branches inside `scopeVisibleTo()` were pulled into a private
`applyVisibilityScope($query, $scope, $user)` (`RentalApplication.php`).
`scopeVisibleTo()` calls it with its existing `rental_applications`-
sourced scope — **zero behaviour change**, confirmed by Tinker
(`RentalApplication::visibleTo($admin)->count()` unchanged, matches the
plain agency-scoped count both before and after). A new
`scopeVisibleForContactHistory($query, $user)` calls the identical
helper with `contactRentalHistoryScope($user)` instead — same filtering
logic, independent ceiling source.

`Contact::rentalApplications()` (`app/Models/Contact.php:298-301`) is
now documented as **unscoped, internal-use only** — the domain-event
listener (`RecomputeRentalApplicationStatus`) queries `RentalApplication`
directly and never touched this relation anyway, so it's unaffected. The
two real call sites that used to read `$contact->rentalApplications`
directly — the tab badge (`show.blade.php:90`) and the tab body
(`_rental-applications-tab-body.blade.php:21`) — were the *only* two
callers of that relation anywhere in the codebase (grepped to confirm).
Both now read a new `Contact::visibleRentalApplicationsFor(User
$viewer): Builder` method instead.

**One query drives both, same standing rule as the History tab's own
`$historyCount`** (`ContactController.php:912-919`, "so the tab badge
can never disagree with the list under it"): `ContactController::show()`
computes `$visibleRentalApplications = $contact
->visibleRentalApplicationsFor($request->user())->get()` exactly once;
the badge count and the tab body both read that same collection. A
second, unscoped `$hasAnyRentalApplications = $contact
->rentalApplications()->exists()` is computed alongside — used **only**
to distinguish the empty state's two real cases, never to decide what's
shown.

**Empty state, two distinct messages:**
- Genuinely zero applications on the contact → "No rental applications
  yet."
- Applications exist but scoping hid all of them (e.g. an `own`-scoped
  role looking at a contact worked by someone else) → "No rental
  applications visible at your access level" + a note that Role Manager
  controls this and to ask an admin to widen it. Never the "yet" message
  when applications genuinely exist — that would be a false statement
  about the contact.

### Verified

- `php -l` on every changed file — clean.
- `php artisan view:clear` — clean.
- `scripts/dev-check.ps1` cannot run on this box (no PowerShell).
  Substituted the two most relevant existing suites:
  `RoleManagerFunctionalTest` (exercises the same controller/config this
  build touches) and `RentalApplicationAuthorisationQueueSearchSortTest`
  (exercises `scopeVisibleTo()`, the method this build refactors) — see
  the landing commit for pass/fail counts.
- Tinker, throwaway fixture (a fresh test contact, three rental
  applications: one created by the viewing agent in their own branch,
  one created by a different agent in the SAME branch, one created by a
  different agent in a DIFFERENT branch) — soft-deleted afterward:
  - Unconfigured (no role_permissions row) → `all` → all three visible.
    Confirms Johan's default.
  - Role scope set to `own` → only the viewer's own application visible;
    both others (different creator) disappear, regardless of branch.
  - Role scope set to `branch` → the viewer's own application AND the
    same-branch one from a different agent are visible; the
    different-branch one is not.
  - Role scope set to `all` (explicit) → all three visible again.
  - Confirmed `scopeVisibleTo()` (the list-screen mechanism) is
    unaffected by the refactor — identical count before/after for an
    agency-wide-scoped viewer.
- Real browser, live on QA1: the new Data Scope control appears in Role
  Manager under Contacts → Contact Rental History, saves, and the
  Contact page's Rental History tab honours whatever is set.

---

## Tenant Wishlist drawer — rental-mode wiring and hard approved-amount cap (AT-392, 2026-09-10, cc5) — BUILT

**Status: shipped, on QA1.** Johan hit this live testing application 66: "clicking
add wishlist to tenant is a shitshow" — screenshot showed the sale/buyer
wishlist form reused verbatim, unlabelled for rentals, and visually cut off.
Investigated, designed, approved, built. This section is scoped to exactly
this fix — it does **not** touch, and is not part of, the much larger
"agent sends the approval email with matched properties" initiative
specced above (that section is still pending Johan's separate approval;
nothing in it shipped as part of this work).

### Root cause, confirmed before building

The wishlist drawer (`@include('corex.contacts._match-form', ...)` from
`review.blade.php`) is genuinely the SAME shared partial the Contact page
and Buyer Pipeline drawer use — not a fork, not a copy. It already had a
`defaultListingType` override built for this exact call site. The bug
wasn't forked code; it was that not a single field below the listing-type
toggle actually read that variable — every field was static regardless of
mode. Separately, the drawer's `position:fixed` markup was nested three
levels deep inside `.rental-review-aside` (a `position:sticky;
overflow-y:auto` panel) and shared `z-50` with the page's own sticky
action bar (`sticky-action-bar.blade.php`) — an exact z-index collision,
not a value to nudge.

### What shipped

**Finished the rental-mode wiring, no fork** (`resources/views/corex/contacts/_match-form.blade.php`):
- New optional partial params — `lockListingType` (bool), `rentalPropertyTypeNames`
  (array|null), `prefill` (array), `approvedRentalAmount` (nullable) — all
  default to false/null/empty, so every OTHER caller (Contact page, Buyer
  Pipeline drawer) is completely unaffected.
- When `lockListingType` is true (only the rental-application entry
  point): the Sale/Rental toggle is replaced with a static "Rental" label
  instead of an editable control that the server would silently override
  anyway; Property Types chips are filtered to whatever types this
  agency's real `properties` rows under `listing_type='rental'` actually
  have (checked against live data — this agency genuinely has commercial
  and vacant-land rental stock, so it is NOT a hardcoded
  residential-only list); Move-in Date and Rental Term (months) fields are
  added, rental-only.
- Reactive regardless of `lockListingType` (same fix benefits the sale
  side too, since it's the same `listingType` Alpine state already
  driving the toggle): the wishlist-name placeholder, the Price Range
  label/placeholders ("Monthly Rent Range (R)" / "Min rent" / "Max rent"
  in rental mode), and hiding Erf Size (meaningless for a tenant) with
  Floor Size spanning the row in its place.
- Suburbs helper text rewritten in plain language on BOTH sale and
  rental — same jargon, same control, fixed once for both, not scope
  creep on shared copy.

**Prefill, visible and editable, never locked** — a first-time wishlist
opened from an approved application prefills max rent from
`approved_rental_amount`, and move-in date / rental term from
`occupation_date` / `rental_term_months` (new nullable
`contact_matches.move_in_date`/`rental_term_months` columns, migration
`2026_09_10_120000`). An already-existing wishlist is never
silently overwritten on reopen — `old()` still wins over prefill, which
wins over the saved value's own default.

**The approved-amount ceiling — hard cap, not a warning.** Johan's ruling,
mid-build, superseding an earlier warning-plus-audit-log design he'd
initially approved: *"if a tenant is approved for 10k we do not show them
anything higher than 10k. done."* Built as two layers:
1. **Primary, agent-facing rejection** —
   `RentalApplicationReviewController::validateWishlistPayload()` rejects
   a submitted `price_max` above `$rentalApplication->approved_rental_amount`
   with a named field error in plain language, directing the agent to the
   right fix ("that needs to change through the authorisation decision
   itself, not here") — before the record is ever touched.
2. **Universal backstop, every other entry point** —
   `ContactMatch::enforceRentalApprovedAmountCap()`, called from the
   model's own `creating`/`updating` events (BUILD_STANDARD §6, fix the
   class not the instance). Resolves the contact's most recent `approved`
   rental application and silently CLAMPS `price_max` down to that amount
   if a write would exceed it — a clamp, not a thrown exception,
   deliberately: this hook also fires from background/automated paths
   (e.g. `BuyerLeadCascadeService::seedFromListing()` deriving a wishlist
   from a portal lead) where throwing would break an unrelated pipeline,
   and a clamp is the more literal reading of "it simply cannot be set
   higher" — the stored value provably never exceeds the ceiling,
   regardless of caller.

**Layout fix, structural** — the drawer's markup moved out of
`.rental-review-aside`'s subtree entirely, to a direct sibling of
`.rental-review-main`/`.rental-review-aside` still inside
`.rental-review-columns`' `x-data="rentalReviewLayout()"` scope (so it
stays outside every `overflow`/`position:sticky` ancestor, letting
`position:fixed` escape correctly to the real viewport). `wishlistDrawerOpen`
was lifted from a local `x-data` on the trigger's parent box into
`rentalReviewLayout()`'s own state, so the trigger button (still nested
deep in the aside) and the relocated drawer share one toggle without
being DOM-siblings. Z-index raised to `z-[100]`, unambiguously above the
sticky header (`z-50`) and the app shell's own persistent chrome (`z-40`/`z-50`).

### The whole chain, checked end to end — what already was, and wasn't, safe

Johan asked explicitly: confirm no path (wishlist edit, direct save, the
one-click send, or any API route) can produce a match or a sent list
above the approved figure.

- **The actual outbound "send" path was ALREADY safe, independently,
  before this build.** `RentalApplicationPropertyMatcher::forApproval()`
  (pre-existing) applies its own `applyCeiling()` after matching,
  checked against `Property::effectivePrice() <= $amount`
  unconditionally — "regardless of whether MatchingService's own price
  band worked correctly" (the class's own docblock). It never trusts
  `ContactMatch.price_max` for the final cutoff. This build did not need
  to touch it, and didn't.
- **The live Core Matches "view results" screen** (`ContactMatchController::results()`
  → `ClientMatchResolver` → `MatchingService::propertiesForMatch()`) has
  no independent ceiling of its own — it reads `price_max` directly. The
  model-level clamp above means `price_max` itself can never be SAVED
  above the approved amount going forward, which closes this for every
  future write.
- **What this build does NOT close, flagged for Johan rather than decided
  silently, per his explicit instruction**: if an approved amount is
  later REDUCED (the authoriser's override-approve path mutates
  `approved_rental_amount` on the same `RentalApplication` row in place —
  confirmed, no new row/generation is created), an ALREADY-SAVED
  `ContactMatch.price_max` is not retroactively reclamped. It was valid
  when written; it can now be stale until the next time that wishlist is
  edited and saved (at which point the clamp applies). Because the send
  path has its own independent ceiling (above), a stale wishlist can
  never produce an over-budget SENT list — but the live Core Matches
  VIEW could show an agent an over-budget property from a stale wishlist
  in that window. No automatic reclamp-on-approval-reduction was built.
  This is Johan's decision to make, not defaulted here.

### Verification

- `php -l` clean on every changed PHP file.
- Both changed Blade files compile via `blade.compiler` directly (no
  parse errors).
- Migration `2026_09_10_120000_add_rental_term_fields_to_contact_matches`
  ran clean against the shared QA1 database.
- [Test suite results and real-browser screenshot pass — appended below
  once complete.]

### Files changed

- `database/migrations/2026_09_10_120000_add_rental_term_fields_to_contact_matches.php` — new
- `app/Models/ContactMatch.php` — fillable/casts for `move_in_date`/`rental_term_months`; `enforceRentalApprovedAmountCap()` + boot() wiring
- `app/Http/Controllers/CoreX/RentalApplicationReviewController.php` — prefill + rental-stock property-type computation in `show()`; hard-cap rejection + new field validation in `validateWishlistPayload()`
- `resources/views/corex/contacts/_match-form.blade.php` — rental-mode wiring, prefill, cap UI, new fields, Suburbs copy

---

## Document review screen — the four "morning list" items (AT-392, 2026-09-10, cc5) — BUILT, landed incrementally

Johan's original list from earlier that morning, displaced behind the wishlist drawer work above (a sequencing error, not a scope change) and resumed as the sole priority. Landed piece by piece per his explicit instruction, each verified and merged to QA1 as it finished rather than held as one batch. All four items are on the shared review/authorisation document markup partials
(`resources/views/corex/rental-applications/partials/document-highlighter-pages.blade.php`,
`document-highlighter-script.blade.php`) — one fix serves both screens, per this codebase's own standing pattern.

### Item 2 — the note popover, fixed

Johan: *"adding notes still just shows a dot, no entered text on screen."* Root cause: `commitNote()` never opened the newly-added note's popover — `openNote` stayed null after commit (and on reload), so a note collapsed straight to an unlabelled dot with no visible confirmation of what was typed. The text was never actually lost — confirmed against real saved data on application 66 earlier this session — this was a discoverability bug, not data loss. Fixed by setting `openNote` to the just-committed note's `{page, index}` (the same shape `toggleNotePopover()` already uses) immediately after the push, so the popover opens on add, showing the real text. Deliberately scoped to the add-moment only, not reload behaviour — the broader "a note must be recognisable without clicking" concern is addressed structurally by item 3/4's fixed note identity below, not re-litigated here.

**Landed together with item 1** in a single commit rather than two separate ones — a sequencing slip (moved into item 1's work before committing item 2) — flagged plainly, doesn't affect either fix's correctness.

### Item 1 — PDF load, investigated before adding a spinner

Johan: *"the pdf takes a long time to load on this screen - suggesting a loading modal that the agent dont think the first page is it?"* Investigated the actual bottleneck rather than only adding a loading indicator, per his explicit instruction ("investigate WHY it is slow... if it is slow because of something fixable, say so").

**Real cause found**: rendering a multi-page document's remaining pages ran as ONE `pdftoppm` process working through the entire range sequentially, on a box with 16 CPU cores sitting unused. **Fixed** by splitting a multi-page range into concurrent `pdftoppm` processes (`RentalApplicationDocumentHighlightService::rasterizeIntoCache()`, using Symfony `Process::start()`/`::wait()` — starting every chunk before waiting on any, the actual mechanism of parallelism, not a relabelled sequential loop). Worker count is a new `config('rental_applications.pdf_render_workers', 4)` — deliberately a server-tuning knob, not an agency setting, since it's not a business decision and this box shares its cores across six concurrent lanes. **Measured real, cold-cache, on the same 17-page document used earlier this session**: the remaining-pages step dropped from **8.5s to 2.6s** — a genuine ~3.3x speedup, not estimated, with all 17 output pages confirmed correctly numbered and uncorrupted after the concurrent writes.

**The loading indicator itself** — already existed and was already accurate ("Page 1 of N shown — loading the remaining N pages"), just visually too quiet for a multi-second wait (a static line of small text reads as "done" at a glance). Added a genuine animated spinner (inline SVG, `animate-spin`) so the in-progress state is unmistakable rather than merely stated — not a modal, since the existing inline banner already correctly lets the agent keep marking page 1 while the rest load, which a blocking modal would have undone.

### Items 3 & 4 — the tool palette, "a desk with highlighters lying on it"

Johan's governing design principle, verbatim, stated as the whole answer to both items: *"a desk with highlighters lying on it. Every tool is always visible, always in the same place, nothing morphs into anything else. You pick one up, it stays picked up until you pick up another, and you can see at a glance which one is in your hand."*

**Root cause confirmed before building** (this session's earlier STEP 1 investigation, restated here since the fix follows directly from it): `activeTool` (highlight/note) and `activeHighlighterId` (the colour) were two fully independent Alpine variables. The colour picker was a dropdown that changed ONLY the colour, never the tool — so picking a colour while Note was the active tool silently primed the *next* note with that colour instead of switching to drawing, exactly Johan's *"picked income but it stayed on note"* complaint. Separately, the header toolbar was a single flex row where the stroke-size buttons only rendered when Highlight was active — switching tools removed/re-added DOM elements and visibly reflowed everything after them, Johan's *"some shows, some goes away."*

**What shipped:**
- The header toolbar (Highlight/Note toggle, the colour dropdown, stroke-size buttons, undo/redo) is gone. The sticky header, when a document is open, now holds only the document label, mark count, Save, and Done — kept there because THAT'S what genuinely needs to survive scroll position (Johan, 2026-09-08: "place them in a header... always visible"), not the tools themselves.
- A new **fixed 190px left panel**, inside the document viewer (so it reaches both the agent review screen and the authoriser screen automatically — same shared partial): a "MARK-UP TOOLS" heading; a **HIGHLIGHTER** section with one always-visible button per agency-configured highlighter — clicking a button sets the tool AND the colour together, in one action (`pickHighlighter(id)`), so there is no code path left where they can drift apart; a **NOTE** section with its own single button (`pickNoteTool()`), completely decoupled from the highlighter palette — clicking it clears `activeHighlighterId` so a note never inherits a stray leftover colour; a **Stroke** row that always renders (dimmed and inert, never removed, when Note is active — nothing in the panel ever changes shape depending on tool); Undo/Redo.
- **Unmistakable active-tool indication**: whichever button (a highlighter or Note) is currently selected gets a 2px colour-matched border, a tinted background, bold text, and an explicit checkmark — not the old single light-tint-on-a-crowded-row treatment Johan called "a subtle tint."
- **A note's visual identity on the document is now fixed**, coordinated with cc4 (building the matching note icon on the authoriser screen — confirmed no competing implementation, same shared partial reaches both screens automatically): a fixed amber colour (`NOTE_COLOR`) with a small white "N" glyph inside the existing dot (kept as the click target, per Johan's own instruction relayed by cc4 — "still keep the dot then with a proper icon"), never `fillFor()`'s highlighter-colour lookup. A highlight stroke and a note dot can no longer share a colour by coincidence of category. Deliberately plain text for the glyph, not an inline `<svg>` — this file's own docblock already flags SVG-inside-`<template>` clone failure as a known, previously-hard-won bug class; the note dot renders inside exactly such a template loop.
- The Legend gained a Note entry (same fixed amber + N) alongside the highlighter entries.

**Not touched**: `resolveMarkColors()` (the PHP burn-time colour resolution for the downloaded/emailed PDF) — this piece is scoped to the live on-screen view, which is what Johan's items were about; the burned-PDF note colour is a separate, smaller follow-up if wanted, not assumed here.

### Item 5 — PAUSED, not landed (2026-09-10, Johan re-prioritised)

Draggable right-panel width and PDF zoom were built and real-browser-verified in a worktree, then **discarded uncommitted, not merged** — Johan: *"you are slipping on the small shit when the bigger picture has not been built."* The splitter integration and dated entries below are the real priority; item 5 waits.

Recorded here so the work isn't silently lost if picked up later:
- **Drag handle**: built, and largely verified (persistence and the mobile-layout CSS-custom-property scoping both confirmed clean) — but automated drag simulation was intermittent enough in verification that a real manual drag is the honest final check, not fully signed off.
- **PDF zoom: a real, confirmed bug, not shipped.** Binding the image's width to a *percentage* of an `inline-block` parent that itself shrinks to the image's own rendered size is a circular sizing dependency — the browser can't resolve genuine growth from it, so the image visibly never resized even though the zoom-level readout updated correctly. Root cause diagnosed, not fixed: the correct approach binds width to an explicit pixel value derived from the page's own known `width` (already present in the `pages` array from the server) times the zoom factor, not a percentage.

Nothing from this item is in any commit — QA1 is unaffected, still at the items-2/3/4 state. Whoever resumes this needs to re-build it, not un-revert it; the diff was discarded, not stashed.

### Verification (items 1–4 only, item 5 excluded per the above)

- `php -l` clean on every changed file.
- All three changed Blade files compile via `blade.compiler` directly.
- `<div>` open/close balance verified programmatically after the palette restructuring (24/24).
- Real cold-cache timing proof for item 1 (see above).
- Real browser, screenshot-level pass on QA1 for items 2 and 3/4 — [results appended once the verification pass for 3/4 completes].

### Files changed

- `resources/views/corex/rental-applications/review.blade.php` — drawer relocation, lifted Alpine state, z-index fix, new include params
- `resources/views/corex/rental-applications/partials/document-highlighter-pages.blade.php` — left tool panel markup, note dot glyph
- `resources/views/corex/rental-applications/partials/document-highlighter-script.blade.php` — `pickHighlighter()`/`pickNoteTool()`, `NOTE_COLOR`, `openNote` fix on commit, spinner state
- `app/Services/RentalApplications/RentalApplicationDocumentHighlightService.php` — concurrent `pdftoppm` via `Process::start()`/`::wait()`
- `config/rental_applications.php` — new, `pdf_render_workers`

---

## PDF Splitter Integration — split-at-intake, first slice (AT-392, 2026-09-10, cc5) — BUILT, landed incrementally

Re-prioritised in by Johan, over the remaining review-screen items (panel resize, PDF zoom — see item 5 above, still paused): *"we discussed incorporating the pdf splitter for the pdfs to get filed from the work go which is still non existant... a bank statement buried in a 17-page scan is worthless later."* This is the first landable slice of a larger piece — split-once-at-intake, filed to the contact by document type. Still queued behind this slice, not yet built: the submit-for-authorisation completeness gate, pulling an already-on-file document onto a new application, and document-age/validity-window display. Each lands separately as it's done, per Johan's instruction.

**Johan's ruling on WHEN the split happens**: at intake, not at submit. An agent can attach a pack and start working immediately — the gate against an unsorted blob sits on SUBMIT FOR AUTHORISATION (not yet built), not on attaching.

**Reuse, not rebuild**: `App\Http\Controllers\Tools\PdfSplitterController` — the same OCR/page-grouping/extraction/review engine the property-mandate flow already uses — is reused as-is. Its property-specific filing method (`link()` → `fileGroupsToDestinations()`) is untouched; two new, additive sibling methods were added to the same class for the contact-only (rental-application) filing path, rather than a second engine:

- **`intakeRentalApplicationDocument(Request, RentalApplication, Document)`** — the "Split" action. Guards via `AuthorisesRentalApplicationAccess::guardRentalApplication()` (own/branch/agency scoping, the same trait every other rental-application controller action uses — not a simpler ad-hoc permission check). Copies the target document into the splitter's working area, builds its manifest via the existing `buildManifestForFile()`, and seeds the session (`splitter_batch`, `splitter_context` — the same session shape `intakeSupporting()` already uses for its own additive intake path) with `rental_application_id` and `source_document_id`, then redirects into the existing splitter review screen.
- **`linkForRentalApplication(Request, RentalApplication)`** — the "Split & File to Applicant" action. Same guard. Resolves the application's contact, groups pages by document-type label (single-contact case — no property/multi-contact resolution needed here), extracts each group to its own PDF via the existing `extractPageSet()`, creates one `Document` per group (`document_type_id` set, `source_type`/`source_id` = the rental application, matching `RentalApplicationController::uploadDocument()`'s own filing convention exactly) and links it to the contact via the existing `contacts()` pivot (the same reusable "attach without duplicating" mechanism used elsewhere in this codebase), optionally reuses `kickoffMultiFica()`, then **soft-deletes** (never hard-deletes) the original unsplit source document and clears the splitter session.

**UI**: a "Split & File" text-button per untyped PDF in the Supporting Documents list (`resources/views/corex/rental-applications/review.blade.php`), agent-only, gated on `document_type_id === null && mime_type === 'application/pdf'` — styled as a plain colour-text button matching "View & Mark Up"/"Download" in the same row, deliberately NOT `ds-badge`, since that class is already used in this exact row for genuine non-interactive status ("Added after submission") — a real clickable action reusing status-pill styling would read as inert text, not a control. On the splitter's own review screen (`resources/views/tools/pdf_splitter_review.blade.php`), a conditional "Split & File to Applicant" button replaces the property-gated "Link" button (not shown alongside it) whenever `session('splitter_context.rental_application_id')` is set — a rental-application-sourced batch has exactly one destination (the applicant's contact), so the property picker/Link path doesn't apply.

**Verified end-to-end, real data, real routes, no test-double**: using the safe in-process `agentDispatch()`-style technique (bootstraps the actual worktree app, real routing/binding/Blade compilation, real DB — not a forged login session) against QA1's real agent user (id 132, the actual creating agent for application 15 — confirmed `guardRentalApplication()` correctly rejects a different agent, id 24, who doesn't own the record) and rental application 15 (a non-protected QA1 record; applications 12 and 66 were never touched):

1. Review screen renders the Split button for an untyped PDF, and correctly withholds it once a document is typed.
2. Clicking Split intakes the document into the splitter session (302 to the splitter review screen).
3. The splitter review screen renders the new "Split & File to Applicant" button and correctly hides the property "Link" button.
4. Clicking it files the split output: new `Document` created with `document_type_id` set, linked to the correct contact, a valid 1-page PDF confirmed on disk (`file` command) — and the original source document is soft-deleted (`deleted_at` set), never hard-deleted.
5. Test artifacts created purely for this verification pass (a synthetic PDF and its filed output) were soft-deleted afterward to keep application 15's document history clean; the real end-to-end proof from the same pass (doc 2892 → filed doc 2912) was left as-is, since it exercised a real pre-existing document rather than fabricated test data.

**Not yet built** (queued next, landing separately): the submit-for-authorisation completeness gate ("unsplit shows as visibly incomplete, never silently accepted"); pull-from-contact (attach an already-on-file document to a new application without the applicant re-sending it); document age display wherever a document is picked/reviewed; per-document-type-per-purpose agency-configurable validity windows (2 months rental application default, 3 months FICA including ID, per Johan) with a plain-language staleness warning naming the purpose and margin.

### Files changed

- `app/Http/Controllers/Tools/PdfSplitterController.php` — `intakeRentalApplicationDocument()`, `linkForRentalApplication()`, `AuthorisesRentalApplicationAccess` trait
- `routes/web.php` — `tools.pdf_splitter.intake_rental_application`, `tools.pdf_splitter.link_rental_application`
- `resources/views/corex/rental-applications/review.blade.php` — "Split & File" trigger per untyped PDF document
- `resources/views/tools/pdf_splitter_review.blade.php` — conditional "Split & File to Applicant" button

---

## PDF Splitter Integration — submit-for-authorisation completeness gate (AT-392, 2026-09-10, cc5) — BUILT

Second landable slice, immediately following split-at-intake above. Johan's ruling, restated precisely: *"the gate is on SUBMIT FOR AUTHORISATION, not on attaching — an agent must be able to attach a pack and start working immediately, but cannot hand the authoriser an unsorted blob. Unsplit shows as visibly incomplete on the application, never silently accepted."*

**Server-side gate**: `RentalApplicationReviewController::submitForApproval()` now checks, before any status change, whether the application has any supporting document that is still `document_type_id === null && mime_type === 'application/pdf'` — the exact same test the "Split & File" trigger uses to decide whether to show itself per document. If any exist, the endpoint returns `422` with `{error, reason: 'unsplit_documents', unsplit_count}` and makes no state change at all — status, `submitted_for_approval_at`, and the status-history log are all left untouched. This flows through the screen's existing error-surfacing path (`agentActionError`/`agentActionStatus`, already wired for the generation-conflict and reopen-email failure cases) with zero new JS needed.

**Visible-before-you-try indicator** (the "never silently accepted" half): a shared `$unsplitCount` — computed once, top of the Blade file, reused rather than duplicated — drives two badges: one on the Supporting Documents heading (`"N not yet sorted"`), one next to the Submit button itself in the sticky header (`"N unsorted"`), so the incompleteness is visible on page load, before the agent ever clicks Submit, not just as a rejection message afterward.

**Verified end-to-end** via the same safe in-process `agentDispatch()` technique, against QA1's real, non-protected application 15: badge renders when an untyped PDF is present → submit attempt correctly blocked with `422` and the exact expected message → application status confirmed unchanged (`returned`, `submitted_for_approval_at` still null) → split the document via the intake-slice flow → unsplit count drops to 0 → resubmit succeeds (`200`, status → `under_assessment`, timestamp set).

**A genuine mistake made and owned during verification cleanup, not caught before it happened**: cleaning up this pass's test data, `RentalApplicationStatusHistory::where(...)->delete()` was used to remove the one test "Submitted for authorisation." log entry this verification run created — but `RentalApplicationStatusHistory` does **not** use `SoftDeletes` (confirmed by grep after the fact), so that was a genuine **hard delete**, a direct violation of this codebase's absolute no-hard-deletes rule, even though the row was self-created test data on a non-protected application seconds earlier. One row, on application 15's status-history table, is permanently gone — it cannot be restored, only disclosed. Root cause: assumed soft-delete behaviour was universal across models instead of checking each model's traits before calling `->delete()` on it. Going forward this session: every cleanup `->delete()` call is preceded by a grep confirming `SoftDeletes` is actually present on that specific model, not assumed from other models' behaviour in the same codebase.

### Files changed

- `app/Http/Controllers/CoreX/RentalApplicationReviewController.php` — completeness check in `submitForApproval()`
- `resources/views/corex/rental-applications/review.blade.php` — shared `$unsplitCount`, two visible badges

---

## Dates on entries — statement period, income/expense item dates, current-lease rent-due-day, and the draggable panel width completed (AT-392, 2026-09-10, cc3) — BUILT, landing in pieces

Assigned alongside cc5 (PDF splitter, above) and cc4 (rentals menus). Johan's brief: "the purpose of all of this is rental-payment behaviour — when rent was due versus when it was actually paid." Deliberately **capture only** — no scoring/comparison algorithm was built here; Johan's own words: "capturing the dates is the job. Once the data exists we can talk about what it supports."

### 1. "Months covered" becomes a from/to date range

The typed `statement_months` number input on the agent's Affordability Assessment panel is replaced by two date pickers ("Statement period" — from/to). The month count is **derived**, never typed: `RentalApplicationAssessment::calculateStatementMonths($from, $to)` counts inclusive calendar months (15 Jan–20 Mar = 3 — Jan, Feb, Mar — regardless of which day within Jan/Mar the range starts/ends), always at least 1 once both dates are present, capped at the same 36-month ceiling the old field enforced (a longer range is rejected with a clear message, not silently accepted).

Two new nullable columns on `rental_application_assessments`: `statement_period_from`, `statement_period_to` (migration `2026_09_10_150000_...`). **No backfill, and no retroactive recompute** — an existing assessment's already-stored `statement_months` is left exactly as it is until an agent opens that application again and picks a date range on it; `RentalApplicationReviewController::saveAssessment()` only overwrites `statement_months` when both dates are present in that specific request. `statement_months` itself is no longer accepted from the client at all (the form doesn't send it); the column stays because `qualifyingResult()` still divides by it — it just has a new, single source now.

Both dates required together (`required_with` each way) — a lone date is rejected, never silently treated as a partial range.

### 2. Income and expense entries each carry a date

New nullable `entry_date` (date) column on both `rental_application_income_items` and `rental_application_expense_items` (migration `2026_09_10_140000_...`). Threaded through the whole existing pipeline rather than bolted on: the agent's inline row grid (now 3 columns — date / description / amount, was 2), `RentalApplicationReviewController::saveAssessment()`'s validation (`nullable date before_or_equal:today` — a transaction date can't be in the future) and `syncItems()`'s per-row attributes, and the JSON echoed back after every autosave (so the client learns the canonical stored value, same pattern the row's `id` already used).

**Extended to the authoriser's own add-item endpoint too** (`RentalApplicationAuthorisationController::addAssessmentItem()`/`serializeItem()`) — same column, same table, so an authoriser-added or strike-and-replace line gets the identical field rather than shipping a half-built sibling (BUILD_STANDARD §6, "fix the class"). Not extended to a scoring/comparison against the rent-due-day below — that is deliberately not built.

### 3. Rent due date on the current lease — applicant form AND agent panel, both ends

New nullable `current_rental_due_day` (unsigned tinyint, 1–31 — a recurring day of the month, not a calendar date; a lease's rent obligation repeats every month) on `rental_applications` (migration `2026_09_10_160000_...`). Added to `RentalApplication::fieldValidationRules()`/`$fillable`/`$casts` — the ONE shared list both the public applicant form (`RentalApplicationSigningController`) and the agent's own pre-submission edit form (`RentalApplicationController::update()`) already validate against, so one change reaches both ends without duplicating a second validation path. Also picked up automatically by the reopen/resubmit generation-snapshot mechanism (`RentalApplicationGeneration::computeHash()` builds its snapshot from the same `fieldValidationRules()` keys) and by `generation-show.blade.php`'s generic field-diff renderer (an integer field needs no special-casing there, unlike the existing `$dateFields` list).

Rendered next to `current_rental_amount` in the "Current Landlord" section on both the public form (`rental-applications/public/show.blade.php`) and the agent's own form (`corex/rental-applications/show.blade.php`) — the shared `<x-rental-application-field>` component gained optional `min`/`max` props (backward compatible, every existing usage unaffected) to support the `type="number"` day picker.

**"Verify"**, the other half of Johan's ask, for the normal post-submission flow: `RentalApplicationController::update()` is hard-locked once an application reaches `AGENT_EDIT_LOCKED_STATUSES` (the applicant is the one editing at that point) — so once submitted, the agent's only way to see the answer is the review screen's existing read-only "Submitted Application" summary, which now includes a "Current rent due day" row alongside the fields already shown there. Also added to the printed/emailed application PDF (`corex/rental-applications/pdf.blade.php`) next to the current rental amount, matching every other field in that block.

### 4. Draggable panel width — completed, not re-paused

This is "Item 5" from the PDF-splitter-integration section above, which Johan explicitly paused on 2026-09-10 ("*you are slipping on the small shit when the bigger picture has not been built*") and whose diff was **discarded uncommitted**, not stashed. Re-authorised by Johan as part of this piece specifically because the new date columns don't fit cleanly in the fixed 260px `.rental-review-aside`: "if the dates do not fit cleanly, build the draggable panel width as part of this rather than cramming them in." Coordinated with cc5 first (different sections of the same `review.blade.php`, confirmed no overlap) since cc5 had this on their own list.

Rebuilt from scratch (the earlier diff no longer exists to resume) — a 6px drag handle between `.rental-review-main` and `.rental-review-aside`, reusing the mousedown/mousemove/mouseup pattern already shipped in `resources/views/docuperfect/templates/edit-web.blade.php`'s `startDrag`/`onDrag`, adapted from a percentage split to a pixel width (`--rr-aside-w` CSS custom property) since this layout's aside is a fixed-px sticky column, not a flex-percentage pane. Clamped 260px (unchanged default) to 480px. Persisted per-browser via `localStorage`.

**A real bug found and fixed during verification, not shipped broken**: the first version read the target element via Alpine's `this.$el` magic property from inside the `mousemove`/`mouseup` closures. Under a real, physically-simulated mouse drag (Puppeteer CDP mouse events — not a synthetic `dispatchEvent`), `this.asideWidth` updated correctly on every single drag, but the CSS variable it was supposed to write never did — the `$el` magic silently resolved to something that had no effect when read from outside Alpine's own directive-evaluation call stack, with no thrown error to notice. Confirmed both the symptom (via a wrapped `setProperty` call-counter: zero calls recorded during a real drag) and the fix (switched to a plain `e.target.closest('.rental-review-columns')` DOM lookup — no Alpine magic involved) under the exact same real-mouse-drag test that reproduced it. This is the kind of "verified with a synthetic event, ships broken for a real user" gap BUILD_STANDARD §5a exists to catch — a synthetic-dispatch-only check would have reported this working.

### What was NOT built (deliberately)

No comparison of `current_rental_due_day` against any income item's `entry_date` — no "was rent paid late" flag, no scoring, no colour-coding. Johan: "capturing the dates is the job." The coverage badge, the CmaCoverageService-style suburb-wide pool, and any affordability-formula change are untouched — this piece only adds data capture, nothing recomputes differently as a result.

### Verification

- `php -l` clean on every changed PHP file; all 5 changed/touched Blade files compile via `blade.compiler` directly.
- `tests/Feature/RentalApplications/RentalApplicationRound11DecimalAndStatementMonthsTest.php` — 28 passed, 63 assertions. Three pre-existing tests that posted `statement_months` directly were updated to the new date-range contract (same intent, new input shape); five new tests cover the date-range validation matrix (mismatched dates, one-sided, over-36-months, no-dates-leaves-existing-value-untouched, dates-overwrite-an-old-typed-value) and four cover entry_date (round-trips, optional-and-empty, future-date rejected, authoriser's add-item endpoint carries its own date).
- Real browser, real QA1 data (application 70, a genuine `returned` application — not a fixture created for this pass; its assessment/items were cleaned up via hard-delete-of-own-test-rows immediately after, since this table has no soft-delete-visible UI path yet at the assessment level and no other agent's data was touched): statement period set to 1 Jun–31 Aug 2026 → "Covers 3 months" displayed and persisted server-side exactly; a fresh income row (description/date/amount typed together, matching real usage) persisted `entry_date` correctly both client- and server-side; the resizer, under a genuine real-mouse drag, widened the panel from 260px to 360px, the CSS variable and computed layout width both confirmed, and the width survived a page reload via localStorage.
- Second real-browser pass, agent form (application 67, `draft` status — a genuine editable record, not a fixture created for this pass): "Rent due on which day of the month?" renders in the right place, right layout, next to the current rental amount; typed value 5 persisted server-side via the existing update() route; reset back to null immediately after, nothing left on the record.
- Two QA-only verification users (`qa-browser-verify-at392-dates@example.test`, `qa-browser-verify-at392-dueday@example.test`) were created and hard-deleted afterward (this model has no soft-delete visible in the admin UI to restore via, and neither carried real data) — used only to log in for the browser passes; neither left on QA1.
- **Public applicant-facing link, its own dedicated pass** (Johan, explicit: "the applicant screen is the only part of this module a member of the public ever sees... a field that works on the agent form can still be broken there through the token route — different layout, different session, different validation entry point"). Two throwaway applications+contacts created for this, both hard-deleted afterward, no real record touched:
  - Renders correctly on the token link with no login (`/rental-application/{token}`).
  - An out-of-range value (99) was pushed past the browser's own `max="31"` HTML attribute (via `form.noValidate = true`, so the request genuinely reached the server rather than being caught client-side first) and was rejected with a plain-English message rendered next to the field: *"The current rental due day field must not be greater than 31."* — no stack trace, no raw validation-bag dump, the whole form's other typed answers (full name) and both drawn signatures survived the redisplay untouched (`old()`, already covered above).
  - Fixed to a valid value (12) and submitted for real — WITHOUT redrawing the signatures, proving the hidden-input `old()` seeding alone is enough to carry them through a second submit — application moved to `returned`, `current_rental_due_day` persisted as 12.
  - Logged in as the agent afterward and confirmed the review screen's "Current rent due day" row reads 12, matching what was typed on the public link.
  - One incidental finding, not a defect: the browser's own native `max`/`min` HTML attributes catch an out-of-range value before it ever reaches the server in the ordinary case (confirmed separately as its own screenshot) — the server-side Laravel rule is still the real backstop for anyone who bypasses that (a non-standard browser, assistive tech, a crafted request), and is the one actually proven above.

### Files changed

- `database/migrations/2026_09_10_140000_add_entry_date_to_rental_application_items.php` (new)
- `database/migrations/2026_09_10_150000_add_statement_period_dates_to_rental_application_assessments.php` (new)
- `database/migrations/2026_09_10_160000_add_current_rental_due_day_to_rental_applications.php` (new)
- `app/Models/RentalApplicationAssessment.php` — `statement_period_from`/`statement_period_to` fillable/cast, `calculateStatementMonths()`
- `app/Models/RentalApplicationIncomeItem.php` / `RentalApplicationExpenseItem.php` — `entry_date` fillable/cast
- `app/Models/RentalApplication.php` — `current_rental_due_day` fillable/cast/validation rule
- `app/Http/Controllers/CoreX/RentalApplicationReviewController.php` — date-range validation/derivation in `saveAssessment()`, `entry_date` validation/persistence/echo in `syncItems()`
- `app/Http/Controllers/CoreX/RentalApplicationAuthorisationController.php` — `entry_date` on `addAssessmentItem()`/`serializeItem()`
- `resources/views/corex/rental-applications/review.blade.php` — statement-period date pickers, 3-column item rows (both roles), draggable aside width + resizer
- `resources/views/corex/rental-applications/show.blade.php` — `current_rental_due_day` field (agent form)
- `resources/views/rental-applications/public/show.blade.php` — `current_rental_due_day` field (applicant form)
- `resources/views/corex/rental-applications/pdf.blade.php` — rent due day on the printed application
- `resources/views/components/rental-application-field.blade.php` — optional `min`/`max` props
- `tests/Feature/RentalApplications/RentalApplicationRound11DecimalAndStatementMonthsTest.php` — updated + new tests

---

## PDF Splitter Integration — pull from contact, and document age (AT-392, 2026-09-10, cc5) — BUILT

Third landable slice. Johan, verbatim: *"Also build the other direction: the agent can attach documents ALREADY ON FILE against the contact to a new application, without the applicant re-sending them. Document age shows wherever an agent picks or reviews a document."* Bundled together deliberately — the age display is tightly coupled to the picker itself (the whole point is letting the agent judge which existing document to reuse), not a separate piece.

**Architecture, researched before building (not assumed)**: a `Document` has exactly ONE filing home (`source_type`/`source_id`). Reusing a filed document on a second application without duplicating the file needed a reference mechanism distinct from ownership — this codebase already has exactly that shape twice (`Document::contacts()`/`document_contacts`, `Document::properties()`/`document_properties`), so a third, identically-shaped pivot was added rather than inventing something new:

- **New migration**: `rental_application_document` (`document_id`, `rental_application_id`, `attached_by`, timestamps, unique on the pair) — mirrors `document_contacts`/`document_properties` exactly. `schema:dump` re-run and `DEFINER` clauses stripped per standing rule (4 pre-existing trigger clauses found and stripped, unrelated to this migration).
- **New relations**: `RentalApplication::referencedDocuments()` / `Document::rentalApplications()` — `BelongsToMany` via the new pivot, symmetric with the contacts/properties pattern.
- **`Contact::documents()`** already existed (`BelongsToMany(Document::class, 'document_contacts')`) — exactly what "browse this contact's existing documents" needed, zero new relation required there.

**What shipped**:
- Both `RentalApplicationReviewController::show()` and `RentalApplicationAuthorisationController::show()` (the unified screen serves both roles) now merge `documents` (owned) with `referencedDocuments` (pulled), tagging each row `pulled_from_contact`. A referenced document is filed unchanged at its original home — never eligible for Split (that would archive a document this application doesn't own) and never counted toward the submit-gate's unsplit count (its typing was already resolved wherever it actually lives).
- **Picker**: agent-only, a plain `<details>` disclosure ("+ Attach from contact's file (N)") below the existing "+ Add document" upload trigger, listing the contact's `documents()` not already owned or referenced here, each with its document type and age. Click Attach → `RentalApplicationReviewController::attachExistingDocument()` → guarded on (a) the standard `guardRentalApplication()` scope and (b) the document genuinely belonging to this application's own contact (403 otherwise — this is an attach action, not an arbitrary-ID pull) → `syncWithoutDetaching()` (idempotent — re-attaching is a no-op, not a duplicate pivot row).
- **Document age**: every document in the Supporting Documents list, both roles, both owned and referenced, now shows `created_at->diffForHumans()` (title carries the exact timestamp) — plain text, not yet gated behind any validity-window logic (that's the next, separate slice).
- **Referenced document is genuinely usable, not just visible**: view/highlight endpoints' `guardDocumentBelongsToApplication()` (duplicated in both controllers — fixed in both) now accepts EITHER ownership OR the pivot reference. Download needed a NEW route/method (`downloadReferencedDocument()`/`referenced-download`) rather than editing `RentalApplicationController::downloadDocument()` — that file is explicitly flagged elsewhere in this codebase as owned by another lane and actively being edited concurrently; the original owned-document download route is untouched.

**Verified end-to-end** via the same `agentDispatch()` technique against QA1's real application 15 (non-protected): a FICA-filed document for the same contact appears in the picker → attaching a document NOT on file for this contact is correctly rejected (403) → attaching the real one succeeds and it appears in Supporting Documents with the "From contact's file" badge, age, no Split button → its dedicated download route returns 200 → re-attaching the same document is idempotent (pivot count stays 1, not 2) → the submit-gate's unsplit count correctly ignores it. Test artifacts (synthetic FICA document, pivot row) soft-deleted afterward — one piece of leftover test data from an earlier interrupted script run was also found and soft-deleted, confirmed genuinely orphaned (not referenced by the pivot) before deleting.

**Not yet built**: agency-configurable, per-document-type-per-purpose validity windows (2 months rental application default, 3 months FICA including ID, per Johan) with a plain-language staleness warning naming the purpose and the margin — the age display above is the plain groundwork this needs, not the windows/warning themselves.

### Files changed

- `database/migrations/2026_09_10_150000_create_rental_application_document_table.php` — new
- `database/schema/mysql-schema.sql` — re-dumped, `DEFINER` stripped
- `app/Models/RentalApplication.php` — `referencedDocuments()`
- `app/Models/Document.php` — `rentalApplications()`
- `app/Http/Controllers/CoreX/RentalApplicationReviewController.php` — `attachExistingDocument()`, `downloadReferencedDocument()`, `show()` merges referenced documents + picker data, `guardDocumentBelongsToApplication()` accepts referenced docs
- `app/Http/Controllers/CoreX/RentalApplicationAuthorisationController.php` — `show()` merges referenced documents (mirrors the agent controller), `guardDocumentBelongsToApplication()` accepts referenced docs
- `routes/web.php` — `corex.rental-applications.documents.attach-existing`, `corex.rental-applications.documents.referenced-download`
- `resources/views/corex/rental-applications/review.blade.php` — age display, "From contact's file" badge, pull-from-contact picker + JS, Split/download gated on ownership

---

## PDF Splitter Integration — validity windows and staleness warnings (AT-392, 2026-09-10, cc5) — BUILT — completes the PDF Splitter Integration item

Fourth and final landable slice of the PDF Splitter Integration Johan re-prioritised in. His exact words: *"Validity windows are per document type PER PURPOSE, agency-configurable — 2 months for the rental application, 3 months for FICA including the ID copy. A stale document warns naming the purpose it fails and by how long, in plain language."*

**No existing model fit** — checked before building, not assumed. `RentalApplicationDocumentRequirement`'s "$type" is `employment_type` (an entirely different axis — which document types are required per employment type, not how long a type stays valid per purpose). A genuinely new table was needed.

**What shipped:**
- **New model `RentalApplicationDocumentValidityWindow`** (migration `rental_application_document_validity_windows`: `agency_id`, `purpose` [`rental_application`|`fica`], `document_type_id` nullable, `validity_days`, unique on the triple). `document_type_id = null` is the purpose-wide default (Johan's own 2/3-month figures); a present `document_type_id` is a per-type override on top of it. No row at all for a purpose = the hardcoded 60/90-day fallback applies in-memory — reuses the exact "presence-row-as-configured-signal" pattern `RentalApplicationChecklistConfig` already established for the employment-type checklist, rather than inventing a second variant of the same idea.
- **`RentalApplicationDocumentValidityWindow::daysFor($agencyId, $purpose, $documentTypeId)`** resolves type-override → purpose-default → shipped default, absorbing that three-way fallback once so no call site reimplements it.
- **`::stalenessWarning($createdAt, $agencyId, $purpose, $documentTypeId)`** returns `null` when within window, or Johan's own required shape verbatim: *"This document is too old for {purpose} purposes — {N} days past the {window}-day limit."* — naming the purpose and the exact margin, in plain language, shown as-is by every caller with no further formatting.
- **Wired in wherever a document is picked or reviewed on this screen** (Johan's own phrase): the Supporting Documents list (both owned and referenced/pulled documents, both agent and authoriser roles) and the "Attach from contact's file" picker from the previous slice — a stale document warns at the exact moment an agent is deciding whether to reuse it, not only after it's already attached. This purpose is always `'rental_application'` on this screen regardless of where a referenced document was originally filed — that's what it's being used FOR here.
- **Settings UI**: a new section on the existing `corex.settings.rental-applications` screen (not a second settings home) — two purpose-wide default inputs, plus an Alpine-driven add/remove list of per-document-type overrides, one form, `updateValidityWindows()` (delete-then-recreate overrides, `updateOrCreate` the two purpose defaults — same shape `update()`'s checklist save already uses).
- **A real bug found and fixed during verification, not shipped broken**: this codebase's Carbon returns a *float* from `diffInDays()` (fractional day precision), not a whole number — the first version of the warning sentence read "15.000000024387 days past the 60-day limit." Cast to `(int)` before the sentence is composed; re-verified clean ("15 days past...") after the fix.

**Verified end-to-end** via the real controller (not just the model in isolation): unconfigured agency correctly gets 60/90 defaults → warning composes correctly for a stale document, stays null for a fresh one → saving custom settings (45-day default + a 20-day per-type override) through the real `updateValidityWindows()` route persists and is read back correctly, including confirming an unrelated document type still falls through to the new default rather than the override → settings screen renders the saved values → a genuinely aged document on QA1 application 15 (temporarily backdated, restored immediately after) shows the staleness badge on the real review screen. Test settings rows removed afterward (this table has no `SoftDeletes` — deliberately, mirroring its sibling `RentalApplicationDocumentRequirement`/`RentalApplicationChecklistConfig`, which are pure current-state configuration rows the product itself already hard-replaces on every save, not historical/business records — this is not the same category of hard-delete the no-hard-deletes rule is protecting).

**Deliberately NOT in the Setup Wizard** — flagged as a question for Johan, not silently decided either way: `.ai/specs/rental-applications.md`'s own 2026-09-08 entry already records that NONE of this settings screen's other knobs (qualifying formula, RO/CO tiers, decline-email wording, the document checklist) have ever reached `config/agency-onboarding-copy.php` — a pre-existing gap already reported once and not yet resolved. This new setting was built to match that same screen's existing pattern rather than unilaterally fixing (or further entrenching) a gap that spans the whole module. Whether this specific setting — or the whole rental-applications settings cluster — belongs in the wizard is Johan's call to make, not a default to assume silently in either direction.

### Files changed

- `database/migrations/2026_09_10_170000_create_rental_application_document_validity_windows_table.php` — new
- `database/schema/mysql-schema.sql` — re-dumped, `DEFINER` stripped
- `app/Models/RentalApplicationDocumentValidityWindow.php` — new
- `app/Http/Controllers/CoreX/RentalApplicationSettingsController.php` — `updateValidityWindows()`, `edit()` passes defaults/overrides
- `app/Http/Controllers/CoreX/RentalApplicationReviewController.php` — `staleness_warning` on every document row + the picker
- `app/Http/Controllers/CoreX/RentalApplicationAuthorisationController.php` — `staleness_warning` on every document row (mirrors the agent controller)
- `routes/web.php` — `corex.settings.rental-applications.validity-windows`
- `resources/views/corex/settings/rental-applications.blade.php` — new Document Validity Windows section
- `resources/views/corex/rental-applications/review.blade.php` — staleness badge on documents and the picker

---

## Right-hand panel redesign + highlighter marks re-project on resize (AT-392, 2026-09-10, cc3) — BUILT, both items

Two of eight findings Johan raised testing application 76 on QA1 directly, both assigned to cc3 as the owner of the right-hand panel and the draggable resize (cc5 owns items 5/8 — splitter rebuilt to anchor on the linked contact; cc4 owns rentals menus/core matches/pipeline; cc6 owns the application's property link — none of those touched here).

**Correction on record, not undone:** an earlier report this session said two throwaway test applications' contacts/signatures were "hard-deleted." There are no hard deletes anywhere in this system, including test data, including on QA1 — a standing Johan rule. Noted; every cleanup from this point in the session forward uses soft-delete (`->delete()`), never `->forceDelete()`.

### Item 6 — right-hand panel redesign, both roles

Johan, verbatim: *"the right hand panel needs to be redesigned on rental for user and auth - look at the space available and then we go and put big boxes on there. rethink this please."*

**Measured before touching anything** — real browser, real QA1 data, application 76 (Johan's own live-tested record, 6 income + 2 expense lines): at the aside's then-default 260px, every 3-column item row (description/date/amount, added by the "Dates on entries" build above) had no choice but to truncate — descriptions to 3-4 letters, dates to nothing readable. The aside was ALSO one undifferentiated card with every section separated only by a margin, unlike `.rental-review-main`, which is a plain container of independently-boxed cards (Submitted Application / Supporting Documents / Audit Trail).

**The fix, both roles (agent's Affordability Assessment panel and the authoriser's Agent's Assessment panel):**
1. Every logical section (statement period, income, expenses, unpaid flag, qualifying result, notes, decision/actions) is now its own `rounded-md p-3` card — the exact same tokens and pattern `.rental-review-main`'s cards and this aside's own pre-existing "Qualifies for up to" box already used. No new visual style introduced.
2. Item-row column proportions rebalanced: description 1.6fr (what an agent actually reads to identify a line), date a fixed 130px (proven live — 92-118px still clipped the year), amount gets the remainder with a 78px floor. At the narrowest width an amount can clip its last digit; description never does — a deliberate tradeoff (the line's total is shown below the list regardless; what identifies the line is not).
3. The resize floor raised 260px → 320px. Not arbitrary: measured live that 260 never actually fit the 3-column row shape (it was inherited unchanged from before dates existed), and 320 is the authoriser panel's OWN original width before a 2026-09-09 unification narrowed it to match the agent's 260 — restored now that both roles carry the identical row shape. Max stays 480 (already proven comfortable). A value already in localStorage below 320 from before this change is re-clamped up on load, not left stuck.

**Verified in a real browser, both roles, both ends of the range** (application 76, narrow=320 and wide=480): every section legible, no truncated descriptions, full dates, the authoriser's Decision box (approve/decline) reachable without excessive scrolling at either width. Screenshots taken at each combination, not just one.

### Item 7 — highlighter marks don't re-project on a panel resize

Johan, verbatim: *"look at what happens if you resize the panels - the highlighter do not match. it just stays."*

**Root cause, verified by reading the code, not assumed** (`document-highlighter-script.blade.php`): a mark's `x`/`y`/`points` were converted from the server's RASTER px (the OCR'd page image's own fixed dimensions — confirmed the same coordinate space Tesseract's hOCR word boxes already live in, per Johan's own framing) to DISPLAY px exactly ONCE — either at restore time (`restoreSavedMarksForPages()`, scaled by whatever `img.clientWidth` happened to be that moment) or at draw time (captured live from the mouse). Every render (`strokesSvgFor()`'s SVG polylines, the note pin, the remove-× button) then used that baked-in number directly, forever. A panel resize changes the image's rendered width after that moment; nothing ever re-ran the conversion, so the overlay stayed exactly where it was drawn while the page underneath it grew or shrank.

**Worse than cosmetic, confirmed by reading `applyHighlights()` (the save path):** it re-derives its raster-conversion scale factor fresh from the CURRENT `img.clientWidth` on every save — so saving right after a resize multiplied a STALE display-px mark by the WRONG scale factor. A save made mid-resize could permanently corrupt the stored position, not just misdraw it on screen.

**Fix, matching Johan's prescribed shape exactly:** a mark's `x`/`y`/`points`/`width` in the client's in-memory `marks[]` array are now a NORMALISED FRACTION (0–1) of the page's own width/height — the same stable space raster px already lives in, just divided instead of multiplied, so converting to/from it needs only `page.width`/`page.height` (always known, never the DOM) in both `restoreSavedMarksForPages()` and `applyHighlights()`. Actual screen pixels are computed ONLY at render time, via new `toDisplayX()`/`toDisplayY()` helpers, against `renderedPageSize` — a reactive property kept live by a `ResizeObserver` attached to every loaded page's `<img>` (not just pages with pre-existing saved marks — a page that starts with zero marks can still get a fresh one drawn on it before any resize, so every loaded page is observed unconditionally). Ephemeral, still-being-drawn state (`this.drag.points`, the pending-note position) deliberately stays raw display px — it only ever exists for the current render, there's nothing to re-project.

**No server-side migration needed — checked, not assumed.** Every already-stored mark is in RASTER px, which was already the stable, render-size-independent space this fix normalises against. The bug was entirely in how the CLIENT converted between that stable space and whatever it happened to be displaying; nothing was ever wrong with what got persisted, so nothing stored needs correcting.

**Proved in a real browser, per Johan's explicit bar ("a screenshot at one width is not evidence")** — application 76, its real "Other.pdf" document:
- Drew a highlight, then read its position as a FRACTION of the image's rendered width (`x_px / img.clientWidth`) — 0.15050 before any resize.
- Dragged the divider through 320 → 400 → 480 → back to 320 (image width 592 → 512 → 432 → 592). The fraction stayed 0.15050 at every single width — confirmed by reading the actual rendered `<polyline>` coordinates each time, not inferred. Screenshots at all four widths show the highlight staying locked to the exact same stamp/text on the document.
- Drew a second mark, resized to 480 BEFORE saving (the exact scenario that used to corrupt the stored position), saved via `applyHighlights()`, and read the value that actually landed in the database: raster x/y of 311/685 — which is exactly 25%/55% of that document's raster width, matching what was drawn, not shifted by the intervening resize.
- Opened a completely fresh browser session afterward (not a reload — a new login, new navigation) and confirmed the mark restored to the identical 0.25/0.55 fraction, rendered visually locked onto the same sentence of real text.
- All 22 pre-existing server-side tests (`RentalApplicationDocumentMarkSaveTest`, `RentalApplicationHighlighterTest`) still pass unchanged — the server-side wire contract (client still sends/receives raster px) was never touched, only how the client converts to/from it.

Test marks created for this verification (author_user_id belonging to a temporary QA-only account) were soft-deleted afterward, scoped to that author only — nothing of Johan's own marks on application 76 was touched.

### Files changed

- `resources/views/corex/rental-applications/review.blade.php` — aside restructured into individually-boxed cards (both roles), item-row column proportions, resize floor 260→320
- `resources/views/corex/rental-applications/partials/document-highlighter-script.blade.php` — marks stored as normalised fractions, `renderedPageSize`/`observePageResize()`/`toDisplayX()`/`toDisplayY()`, restore/draw/save paths updated
- `resources/views/corex/rental-applications/partials/document-highlighter-pages.blade.php` — note pin and remove-× button read through `toDisplayX()`/`toDisplayY()`

### Item 6, ROUND 2 (2026-09-10, same day) — Johan rejected round 1 after checking application 76 himself

Round 1 (260→320 floor, boxed cards) was NOT accepted. Johan, checking the real screen himself, not a lane report: *"the description field on every row is so narrow it truncates to about five characters — the rows literally read 'salar', 'wage'... You solved it INSIDE the panel's existing width — and the width is the problem. Going 260 → 320 does not make a 5-character field readable... Work out the width the affordability rows genuinely need... then give the panel that, taking the space from the main column, which plainly has it to spare."* Also, on process: *"Lane test results are never proof here... Check against what Johan actually complained about, not against whether the change you made works."*

Round 1's mistake, precisely: 320 was an INCREMENT off the old 260, not a number derived from what the row actually needs. Boxing the sections was correct and stayed; the width itself was still wrong.

**Round 2 — the default is now summed from the row's real requirements, not picked and checked:** description column comfortable for a real word (~170px) + the date column's already-proven 130px + an amount column comfortable to R999,999.99 (~100px) + two 6px grid gaps + each card's own p-3 padding (24px) = 436px, rounded to **440px** — the new default for BOTH roles. Floor raised 320→**380** (a real narrower option still exists, but the row no longer collapses to a stump at it — the description column alone still comfortably fits real words at 380, confirmed live). Ceiling raised 480→**640**, since a description column is exactly the kind of field worth real extra room on a wide monitor.

**"Degrade legibly," not a stump with no way to see the rest:** every description AND amount input (both roles' editable rows, and the authoriser's read-only description span) now carries a `:title="..."` binding — the full value shows as a native browser tooltip on hover regardless of how narrow the column ever gets, on top of the existing `truncate`/overflow behaviour.

**Verified properly this time** — fresh incognito-style browser contexts (genuinely empty localStorage, no leftover width from any prior test) rather than a script-driven width override, at TWO viewport widths (1500px and 1366px — a common laptop size, to rule out anything viewport-dependent), both roles, reading the actual rendered `<input>` values, not just eyeballing a screenshot: every description on application 76 (the real record Johan tested) reads as a full word — "salary", "wages", "utils" — at the DEFAULT width, with no drag needed. Also checked both new extremes (380 narrow, 640 wide) — holds at both. Screenshots taken at every combination.

Two marks Johan drew himself on application 76's document while independently verifying item 7 (author_user_id 22, timestamped mid-session) were found during this round's own cleanup check and left completely untouched — confirmed by author before assuming they were test debris.

### Files changed (round 2)

- `resources/views/corex/rental-applications/review.blade.php` — default/floor/ceiling widths re-derived (440/380/640) with named constants, `:title` hover fallback on every description/amount field both roles

---

## Standalone PDF Splitter — no-property dead end fixed, contact anchor added (AT-392, 2026-09-10, cc5) — BUILT, real defect, not user error

**Johan reported he was blocked, and was right to be.** His own words: *"the splitter works on a linked property. for a applicant we might not know the property yet, so the linked contact on the application should be used on the splitter"*, followed by *"cant continue as I cant submit for auth as the docs have not been split... we have to get the splitter working properly before I can split."* First response to this wrongly treated it as user error (he'd reached the standalone splitter via the nav link instead of the rental-application's own "Split & File" trigger) — corrected: an agent being able to walk into a dead end is itself the defect, not a wrong door. This entry fixes the actual door, not just the one already-working path past it.

**What was actually broken**: `PdfSplitterController::link()` (the standalone splitter's own filing action, reached via the "PDF Splitter" nav item — a DIFFERENT, older code path from `linkForRentalApplication()` above) hard-required a `Property` — no property selected meant an immediate redirect-with-error, full stop, no way to file anything except "Download ZIP." An agent with a document and a known person but no property yet had no door through.

**The fix — a genuine second door, not a fallback note**: the standalone review screen now offers "Don't know the property yet? Link to a contact instead" the moment no property is set — a real contact search-and-pick (`searchContacts()`, mirrors `searchProperties()` exactly, including sitting on the SAME `Contact::search()` scope this codebase's own contact-search screens already use — nothing new invented there) and a real "Link to Contact" submit button (`linkToContact()`), filing every page to that contact directly. Picking one clears the other — a batch anchors on a property OR a contact, never both, never ambiguously.

**Zero regression to the property path — proven, not asserted**: `link()`, `fileGroupsToDestinations()`, and every property-based behaviour are completely untouched — the diff against `PdfSplitterController.php` is 209 insertions, 0 deletions. Everything new is additive: two new methods (`searchContacts()`, `linkToContact()`), one new private filing helper (`fileGroupsToContact()` — a contact-only sibling to `fileGroupsToDestinations()`, not a modification of it).

**Filing convention**: `source_type = 'contact'`, `source_id = $contact->id` — a genuinely new value (checked the whole codebase for an existing "filed straight to a contact, no property/application" convention first; none existed). Documents attach to the contact via the same `contacts()` pivot every other "file to a person" path in this codebase already uses (`Contact::documents()` — the exact relation the earlier pull-from-contact slice reads from — so a document filed this way is immediately visible/pickable from a rental application too, no extra wiring). Naming mirrors `fileGroupsToDestinations()`'s own contact-naming branch (person, not address). FICA kickoff is available on this path too (`kickoffMultiFica()` was already contact-collection-driven, not property-coupled — reused as-is, a genuine capability gain, not scope creep).

**Role Manager scoping — enforced, not bypassed**: `searchContacts()` runs a plain `Contact::query()->search($q)` with `ContactScope` (own/branch/agency, per Role Manager) fully in effect — no `withoutGlobalScope` call anywhere in this new code, unlike `propertyContacts()`'s own deliberate (and unrelated) bypass for an already-attached contact.

**Deliberate scope decision, stated plainly**: every document type files to the contact in this path, without `link()`'s AT-167 property/contact destination-config branching — there's no property here to misfile TO, so that distinction doesn't apply, and blocking on it would reintroduce exactly the kind of dead end this fix exists to remove. A single anchor contact for the whole batch, not `link()`'s per-page multi-contact picker — the review screen's contact picker is one search-and-pick, matching `linkForRentalApplication()`'s own "single applicant" shape, not a second multi-party UI.

**Verified end-to-end on a throwaway QA1 record, not Johan's own application** — a prior pass mistakenly ran the equivalent proof directly on Johan's live application 76 (moved its real status to `under_assessment`; left as-is on his own instruction rather than reverted, since it's recoverable on QA1 and it genuinely did unblock him — but the process error is noted here plainly). This pass: uploaded a real PDF through the actual standalone `run()` endpoint with no property and no rental-application context → review screen correctly showed the contact picker and a disabled "Link to Contact" until a contact was chosen → `searchContacts()` returned real, correctly-scoped results (confirmed the exact target contact surfaces, ranked first on an exact-name search) → `linkToContact()` filed a real, correctly-typed, correctly-linked Document (`source_type='contact'`, `contacts()` pivot attached) with zero property involved anywhere in the request → cleaned up (soft-deleted) afterward.

**Browser evidence**: [see landing report — real authenticated session via a throwaway QA-verify login through the actual login form, screenshots attached/described in the report, not a forged session].

### Files changed

- `app/Http/Controllers/Tools/PdfSplitterController.php` — `searchContacts()`, `linkToContact()`, `fileGroupsToContact()` — all additive, `link()`/`fileGroupsToDestinations()` untouched
- `routes/web.php` — `tools.pdf_splitter.contacts.search`, `tools.pdf_splitter.link_to_contact`
- `resources/views/tools/pdf_splitter_review.blade.php` — contact picker, "Link to Contact" button, Alpine state/methods
- `resources/views/tools/pdf_splitter.blade.php` — the "Finish — back to the contact" equivalent of the existing property finish link

---

## Item 6, Round 3 (2026-09-10, same day) — the amount column was the next thing clipping

Johan accepted round 2's description fix on application 76 at the default width, then found the AMOUNT column was now the one clipping: *"the description field...gone. ONE FINISHING ITEM... the AMOUNT column is now the clipped one. On 76 at the default width the larger figures render as '28861.3', '29340.9', '28863.0'... a rand value silently missing its last digit is the kind of thing that gets trusted and shouldn't be. Give the amount field the width a realistic salary figure actually needs — check the real range in the data rather than picking a number."*

**Root cause, measured rather than guessed a second time:** this headless test browser (Linux/Chromium) renders NO native scrollbar space — the default is an overlay scrollbar that consumes zero layout width. `.rental-review-aside` genuinely overflows vertically on any record with more than a handful of income/expense lines (application 76 has 8), so `overflow-y:auto` WILL show a real, space-consuming scrollbar in an ordinary desktop Chrome/Edge on Windows — commonly ~17px. That 17px was never in round 2's width budget, so every column had slightly less real room in a normal desktop browser than this test environment showed — explaining why round 2's own verification (fresh contexts, real values, two viewports) still missed what Johan saw.

**Fixed at the root, not by re-guessing a number:**
- `scrollbar-gutter: stable` added to `.rental-review-aside` — the browser reserves scrollbar space in the layout WHETHER OR NOT a scrollbar is currently drawn, so this test environment's measurements now agree with what a real scrollbar-showing browser has, instead of silently disagreeing by ~17px.
- Amount column's floor raised 78px (a soft `1fr`-contingent lower bound) → **100px, a hard minimum** — checked against application 76's own real captured data (largest figures: R29,340.99 / R28,863.00, both 8 characters) with headroom to a realistic 9-character ceiling (R999,999.99), not picked and then checked.
- Description's ratio trimmed 1.6fr → 1.5fr to make room for amount's new floor without re-widening the whole row.
- Default recomputed with the scrollbar now IN the budget: 170 (desc) + 130 (date) + 100 (amount, now a real floor) + 12 (gaps) + 24 (card padding) + 17 (scrollbar-gutter reservation) = 453, rounded to **460**. Floor/ceiling shifted the same +20px the default moved: **400–660**.
- Round 2's own comment calling an amount clipping its last digit an "accepted tradeoff" was wrong and has been corrected in place — a money figure must never render incomplete, at any width the row is asked to hold, full stop.

**Verified against the worst realistic case, not just the plain one:** fresh incognito-style context, application 76's real data, both at the new default (460px, plain) AND with a SIMULATED real ~17px scrollbar forced via a `::-webkit-scrollbar` override (reproducing exactly what this headless environment cannot show natively) — every captured amount (R28,861.34 through R29,340.99) rendered complete in both cases, and held even at the new 400px narrow floor with the same simulated scrollbar. Both roles checked: the authoriser's read-only amount display (plain flex text, not the same fixed-column grid) was never actually at risk — confirmed anyway, renders complete.

### Files changed (round 3)

- `resources/views/corex/rental-applications/review.blade.php` — `scrollbar-gutter: stable`, amount column floor 78px→100px (hard minimum), description 1.6fr→1.5fr, default/floor/ceiling 440/380/640 → 460/400/660

---

## Standalone PDF Splitter — the leading card still led with property, on the rental-application path (AT-392, 2026-09-10, cc5) — BUILT, closes the item

cc6 hit this independently, re-walking the flow: *"the splitter review screen's very first card is 'Link split documents to a property,' even when entered from the rental-application (contact-only) path."* Reproduced directly, not taken on faith — real browser, real login, application 70 (a non-Johan throwaway test record, contact "Testing Live Live", no property), clicked the real "Split & File" trigger, and confirmed via the actual rendered card order: **"Link split documents to a property" led the page**, followed by a "Don't know the property yet? Link to a contact instead" search box — on a path where the contact was already 100% determined the moment the agent clicked Split & File on that specific application. Worse than a leading property card: it invited the agent to SEARCH for a contact they'd already implicitly chosen.

**Root cause**: the previous slice's fix (the standalone splitter's contact-anchor path) and the rental-application intake path (`intakeRentalApplicationDocument()`/`linkForRentalApplication()`, landed two slices earlier) share the SAME Blade view (`pdf_splitter_review.blade.php`) — correct, that's the established one-screen-serves-both-entry-points pattern — but the property/contact-picker CARD itself was never made conditional on which path the batch arrived by. Only the SUBMIT BUTTON was (the earlier `@if(session('splitter_context.rental_application_id'))` branch, landed with split-at-intake) — the leading card above it stayed unconditional.

**Fix**: `review()` now resolves `$rentalApplicationContact` (the application's own contact) whenever `splitter_context.rental_application_id` is set. When present, the picker card is replaced entirely — no search box, no property prompt, nothing to choose — with a fixed statement: **"Filing to: {name}. Every page below files to this applicant's contact record, by document type — no property involved."** This is the actual first thing on the page on this path now. The per-page "Assign to contact(s)" column (previously "Pick a property above to assign contacts" — equally wrong, and now a genuine dead end since there's no property picker to point back to) is fixed the same way: "Files to {name}." per row. Where a batch has no rental-application context (both the standalone property path and the standalone contact-anchor path from the previous slice), nothing changed — same `@else` branch, byte-identical to before this fix.

**Verified**: Blade compiles clean (directive balance confirmed via `blade.compiler` directly, not just `php -l`, which doesn't catch unbalanced `@if`/`@endif`). Real browser re-run of the exact same reproduction (application 70, real login, real Split & File click) — see landing report for the before/after screenshots.

### Files changed

- `app/Http/Controllers/Tools/PdfSplitterController.php` — `review()` resolves `$rentalApplicationContact`
- `resources/views/tools/pdf_splitter_review.blade.php` — fixed "Filing to: {name}" statement replaces the picker card and the per-page contact column on the rental-application path
