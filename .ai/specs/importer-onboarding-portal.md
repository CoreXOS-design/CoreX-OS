# P24 Importer — Onboarding Review Portal (Public Shareable Link)

> Module: **Importer** — extension to `.ai/specs/importer.md`
> Status: DRAFT — awaiting approval
> Author: Andre (via Claude)
> Date: 2026-04-15
> Pillars touched: **Agency**, **Property**, **Agent** (User)
> Depends on: `.ai/specs/importer.md` (Stage 1 + Stage 2 parse already exist)

---

## 1. Purpose / Business Requirement

When a new agency signs onto CoreX OS, HFC platform admins import that
agency's P24 CSVs on their behalf. The *new agency* must then walk through
their own stock and confirm / exclude / correct each listing before it goes
live on CoreX. These people are **not CoreX platform users** — they cannot
be given access to `/admin/importer`, which exposes all agencies and
platform-admin tools.

We need a **tokenised, public, per-agency onboarding portal** where the new
agency's staff can review *only their own pending listings*, confirm them
(individually or in bulk), exclude junk, flag errors, and sign off — all
without an account, without seeing any other agency's data, and without
touching platform-admin surfaces.

This spec also addresses the known **confirm-button bug** on the existing
admin review page, because the root-cause fix (queued confirm job) is
shared by both the internal and public flows.

---

## 2. Pillar Connections

| Pillar | Read | Write |
|--------|------|-------|
| Agency | Portal is scoped to exactly one agency by token | — |
| Property | Pending rows preview → on confirm, writes to `properties` | Creates/updates `properties` |
| Agent (User) | Resolved agent shown per listing; portal user may re-assign from the agency's imported agents list | Updates `properties.agent_id` |
| Deal | not touched | — |

Every write still flows through the same `ProcessImporterRunJob` /
row-confirm path as the admin screen — the portal is a **different front
door to the same action**, not a parallel implementation.

---

## 3. How it differs from existing `/admin/importer/review`

| Concern | Admin review (`review.blade.php`) | Onboarding portal (new) |
|---|---|---|
| Audience | HFC platform admin | New agency's own staff |
| Auth | `auth` + `can:admin.importer` | Signed token, no login |
| Scope | All agencies (filterable) | Exactly one agency, locked |
| Surface | Inside CoreX sidebar / layout | Standalone branded page, no sidebar, no admin nav |
| Link sharing | — | Copy-link button in admin; emailable |
| Confirm path | Synchronous controller call (bug) | Queued job + polling (fix) |
| Visible fields | Raw + mapped payload, platform internals | Business-facing preview only (address, price, photos, agent, errors) |

---

## 4. Data Model

### 4.1 New table `p24_onboarding_portals`

One row per agency onboarding session. A portal is the *container* for a
shareable review URL; it points at the `agency_id` and optionally at
specific `p24_import_runs` it should show. If no runs are pinned, it shows
all pending rows for that agency.

Columns:
- `id`
- `agency_id` — FK, NOT NULL, indexed
- `token` — char(40), unique, URL-safe random
- `label` — nullable string (e.g. "Home Finders Ballito go-live")
- `created_by` — FK users.id (the HFC admin who generated it)
- `expires_at` — nullable timestamp (default +30 days on create)
- `revoked_at` — nullable timestamp (soft kill)
- `last_opened_at`, `open_count` — telemetry
- `completed_at` — set when the agency clicks "Finish review"
- `run_ids_json` — optional JSON array of `p24_import_runs.id` values to
  scope the portal to specific runs; null = all pending rows for the agency.
- timestamps + `deleted_at` (SoftDeletes — non-negotiable #1)

### 4.2 No changes to `p24_import_rows`
Status enum (`pending|confirmed|excluded|error`) is already the right
shape. Add one nullable audit column:
- `confirmed_via` enum(`admin`,`portal`) nullable — lets us report on who
  confirmed what.
- `confirmed_by_portal_id` nullable FK → `p24_onboarding_portals.id`.

### 4.3 Migration
- `database/migrations/2026_04_15_000001_create_p24_onboarding_portals.php`
- `database/migrations/2026_04_15_000002_add_portal_audit_to_p24_import_rows.php`
- `database/migrations/2026_04_15_000003_create_p24_portal_events.php`

### 4.4 New table `p24_portal_events` (activity log)
Columns: `id`, `portal_id` FK, `agency_id` FK (denormalised for fast
filtering), `actor_type` enum(`portal_visitor`,`admin`,`system`),
`actor_label` string (e.g. masked IP, admin name), `event` string (see
§5.1 list), `target_row_id` nullable FK `p24_import_rows.id`,
`target_external_id` nullable string (listing #), `meta_json` (counts,
agent reassignment from/to, etc.), `ip` nullable string,
`user_agent` nullable string, `created_at` only (immutable log).

---

## 5. UI / Navigation

### 5.1 Admin side (inside CoreX)

**The existing `/admin/importer/review` page is replaced in full.**
There is no admin-side review queue any more — confirming listings is the
new agency's job, done in the public portal. The admin page becomes a
*portal management + activity log* screen.

Route stays the same: `admin.importer.review` →
`resources/views/admin/importer/review.blade.php` (rewritten).

Layout of the new page:

1. **Header** — "Property Onboarding" + short explainer: "Send each new
   agency a secure link where they confirm their imported properties.
   You do not confirm listings here — the agency does."

2. **Per-agency section** (one card per agency that has pending P24 rows
   OR an existing portal). Each card shows:
   - Agency name + logo + pending / confirmed / excluded / error counts.
   - **Active portals** list: Label, public URL (copyable), Created,
     Expires, Open count, Last opened, Status badge
     (Active / Expired / Revoked / Completed).
     Row actions: **Copy link**, **Open as agency** (admin preview),
     **Extend**, **Revoke**.
   - **Create portal** button → modal: Label, expiry (default 30d),
     optional run picker (multi-select of this agency's `p24_import_runs`).
   - **Activity history** (collapsible, scoped to this agency across all
     portals, newest first, paginated 50/page). Each entry:
     - timestamp, actor (`Portal visitor · {ip masked}` or admin name),
       portal label, event, target (listing number + address).
     - Events logged: `portal.opened`, `portal.row.confirmed`,
       `portal.row.excluded`, `portal.row.agent_reassigned`,
       `portal.bulk.confirmed` (count), `portal.bulk.excluded` (count),
       `portal.finished`, `portal.revoked`, `portal.extended`,
       `portal.created`.
     - Source: new `p24_portal_events` table (see §4.4).

On portal create, the server generates a 40-char token and returns the
full URL: `https://corex.hfcoastal.co.za/onboarding/{token}`.

### 5.2 Public portal (new, no auth)

Route: `GET /onboarding/{token}`
Route name: `onboarding.portal.show`
Controller: `App\Http\Controllers\Public\OnboardingPortalController`
Layout: new `resources/views/layouts/onboarding-portal.blade.php` — minimal,
CoreX-branded but with **no sidebar, no admin nav, no agency switcher**.
Header shows only: agency logo/name, portal label, progress counter
("4 of 37 confirmed"), Help link, Finish button.

Pages:
1. **Welcome / intro** (`onboarding/portal/welcome.blade.php`) — one-screen
   explainer: "Home Finders Coastal has imported your Property24 stock into
   CoreX. Please review each listing and confirm or exclude it. Your
   changes go live only after you click *Finish*." CTA: Start.
2. **Review queue** (`onboarding/portal/review.blade.php`) — same table
   shape as admin review but trimmed:
   - Filters: Status (Pending/Confirmed/Excluded/Error/All), Type (Sale/Rental),
     Search (address, listing number).
   - Columns: checkbox, ListingNumber, Address, Type, Price, Beds/Baths,
     Agent, Photos count, Errors, Status, Actions.
   - Actions: **View** (side drawer with full mapped preview + image
     gallery), **Confirm**, **Exclude**, **Reassign agent** (dropdown of
     this agency's imported agents only).
   - Bulk actions: **Confirm selected**, **Exclude selected**,
     **Confirm all pending** (filtered).
   - *No raw-payload / internal JSON / internal IDs exposed.*
3. **Finish** (`onboarding/portal/finish.blade.php`) — summary screen:
   X confirmed, Y excluded, Z still pending (warn if non-zero). Button
   **Mark review complete** → sets `completed_at`, emails HFC admins.

### 5.3 Sidebar entry
No sidebar entry for the public portal (it's outside CoreX's auth wall).
Admin entry is added to the *existing* `Admin → Importer` page — no new
sidebar item required, satisfying non-negotiable #2 because the feature
surfaces the same day via that page.

---

## 6. Permissions

- Portal access is **token-based only**. No user auth, no role.
- Token middleware (`ResolveOnboardingPortal`) on every `/onboarding/*`
  route:
  - Loads portal by token or 404.
  - Rejects if `revoked_at` or `expires_at < now` → friendly expired page.
  - Binds `agency_id` into the request for downstream scoping.
- Every DB query inside portal controllers scopes to that `agency_id`;
  `AgencyScope` is engaged by impersonating the agency context for the
  request lifetime (set `app('currentAgencyId')` = portal.agency_id). No
  `withoutGlobalScope` calls (non-negotiable #7).
- Admin-side portal management: new permission key
  `admin.importer.portals` (add to `CoreXPermissionSeeder.php`). Piggybacks
  on existing `admin.importer` for basic access.
- Rate limit: 60 req/min/IP on portal routes; 10 confirms/sec/portal.
- CSRF: portal POSTs are protected by the normal Laravel CSRF; the portal
  page embeds a token in the Blade view.

---

## 7. Flow (Happy Path)

1. HFC admin imports Agency X's agents (Stage 1) and listings+images
   (Stage 2) per existing `.ai/specs/importer.md`. Rows land in
   `p24_import_rows` with `status=pending`.
2. HFC admin opens `/admin/importer`, picks Agency X, clicks
   **Create onboarding portal**, sets label + expiry, confirms.
3. Admin copies the link and emails it to the agency principal
   (email template: `OnboardingPortalInvitation`, subject
   "Review your CoreX property import").
4. Agency principal opens link → Welcome screen → Start.
5. Reviews listings, uses Confirm / Exclude / Reassign agent, bulk actions
   where they want. Each action fires POST; server validates the row
   belongs to the portal's agency; updates status.
   - **Confirm** dispatches `ProcessPortalConfirmJob` per-row (or batched)
     → queued. Row status flips to `confirming` immediately (optimistic
     UI), then `confirmed` when the job finishes (images downloaded,
     `properties` row written).
   - The portal polls `/onboarding/{token}/status` every 3s while any rows
     are in `confirming` to update badges.
6. When done, agency clicks **Finish review** → `completed_at` set → HFC
   admin gets notification email.
7. HFC admin can re-open the portal admin panel, see 100% complete, and
   archive.

---

## 8. Fix for the existing confirm bug

Root cause (observed in `review.blade.php:203-217` +
`ImporterController::confirmBulk`): confirm/bulk-confirm runs image
downloads and `properties` writes inside the HTTP request. For any
non-trivial selection the request exceeds PHP/Nginx limits; for a single
row the multiple rapid clicks each spawn a long request, the browser only
sees a response when one finally completes — giving the "click 100 times,
sometimes works" behaviour.

Fix (applies to BOTH admin review and new portal):
- Extract the per-row confirm work into `ProcessPortalConfirmJob` (queued).
- Controller endpoint validates + enqueues + returns 202 immediately with
  the new row statuses set to `confirming`.
- Front-end: disable buttons for rows currently `confirming`; show spinner;
  poll status endpoint every 3s until no `confirming` rows remain;
  *never* `location.reload()` mid-flight.
- Select-all bug: `toggleAll` in `review.blade.php:190-197` only toggles
  the currently-rendered page. Bulk "Confirm all filtered" must send the
  filter criteria (not the checked IDs) so the server picks up every
  matching row across pagination.
- Queue: use existing queue worker; `queue:work` must be running on the
  production server — add a health check to `scripts/dev-check.ps1`.

---

## 9. Validation Rules

- Portal token: exactly 40 chars, alphanumeric.
- Expiry: must be a future date on create; max 180 days.
- Reassign agent: target `users.id` must have `agency_id = portal.agency_id`
  AND `p24_agent_id IS NOT NULL` (only imported agents).
- Confirm: row must have `agency_id = portal.agency_id` AND
  `status IN (pending, error)`.
- Exclude: row must have `status IN (pending, error, confirmed)` — allow
  un-confirm during the review window.
- All portal actions rejected if portal is revoked / expired / completed.

---

## 10. Acceptance Criteria

- [ ] Admin can create a portal for Agency X; link resolves to a public
      page with Agency X's branding (logo, brand colours).
- [ ] Visiting the link with no auth loads the Welcome screen.
- [ ] Review table shows ONLY Agency X's pending rows. Attempting to call
      `/onboarding/{token}/rows/{id}/confirm` with a row from Agency Y
      returns 403.
- [ ] Revoking the portal immediately locks out in-flight sessions on next
      request.
- [ ] Expired portal shows a friendly "This link has expired" page.
- [ ] Bulk confirm of 50 rows returns within 1 second (queued) and the UI
      reflects all 50 as `confirmed` within ~30 seconds via polling —
      never requires a click more than once.
- [ ] Existing admin review page uses the same queued path — single-click
      confirm works; "Confirm all filtered" confirms across pagination.
- [ ] On Finish, `completed_at` set, HFC admin receives
      `OnboardingPortalCompletedNotification`.
- [ ] No `withoutGlobalScope` calls introduced.
- [ ] All new models use `SoftDeletes`; no hard deletes.
- [ ] `scripts/dev-check.ps1` passes with 0 new failures.
- [ ] `php -l` clean on all new/changed files.

---

## 11. Files to Create / Modify

**New**
- `app/Http/Controllers/Public/OnboardingPortalController.php`
  (welcome, review, status, confirmRow, excludeRow, reassignAgent,
  bulkConfirm, bulkExclude, confirmAllFiltered, finish)
- `app/Http/Controllers/Admin/ImporterPortalController.php`
  (index-in-page partial, create, revoke, extend, previewAs)
- `app/Http/Middleware/ResolveOnboardingPortal.php`
- `app/Jobs/ProcessPortalConfirmJob.php`
- `app/Models/P24OnboardingPortal.php`
- `app/Notifications/OnboardingPortalInvitation.php`
- `app/Notifications/OnboardingPortalCompletedNotification.php`
- `resources/views/layouts/onboarding-portal.blade.php`
- `resources/views/onboarding/portal/welcome.blade.php`
- `resources/views/onboarding/portal/review.blade.php`
- `resources/views/onboarding/portal/finish.blade.php`
- `resources/views/onboarding/portal/expired.blade.php`
- `resources/views/admin/importer/partials/portals-panel.blade.php`
- `database/migrations/2026_04_15_000001_create_p24_onboarding_portals.php`
- `database/migrations/2026_04_15_000002_add_portal_audit_to_p24_import_rows.php`

**Modify**
- `routes/web.php` — add `/onboarding/{token}/*` public group + admin
  portal routes
- `resources/views/admin/importer/index.blade.php` — include portals panel
- `resources/views/admin/importer/review.blade.php` — swap synchronous
  confirm for queued path + polling
- `app/Http/Controllers/Admin/ImporterController.php` — queue the confirm
  work via `ProcessPortalConfirmJob` (shared with portal)
- `database/seeders/CoreXPermissionSeeder.php` — add
  `admin.importer.portals`
- `scripts/dev-check.ps1` — add `queue:work` health assertion

---

## 12. Out of Scope (v1)

- Agency self-uploading their own P24 CSVs (HFC admin still does the
  upload; agency only confirms).
- Editing listing fields (price, description, photos) in the portal — v1
  is confirm / exclude / reassign agent only.
- Multi-language portal (English only).
- Mobile app; responsive web only.
- SSO / magic-link per-user auth inside the portal (v1 is single shared
  token).

---

## 13. Resolved Decisions (2026-04-15)

1. Portal is **confirm / exclude / reassign agent only**. No field edits
   in v1. Typos → re-import.
2. Default expiry is **30 days** from create.
3. **One active portal per agency** at a time. Creating a new portal
   while one is active: server revokes the previous, logs
   `portal.revoked` with reason `superseded`, then creates the new one.
4. Branding is **co-branded**: CoreX chrome (layout, typography, geometry,
   "Powered by CoreX OS" footer) + agency logo and agency brand colours
   (`sidebar_color`, `icon_color`, `default_color`, `button_color`) applied
   to accents and CTAs.
5. Portal events are logged immutably in `p24_portal_events` (see §4.4).

---

*End of spec. Awaiting approval before code.*
