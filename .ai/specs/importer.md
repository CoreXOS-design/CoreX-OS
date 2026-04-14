# P24 Importer — Spec

> Module: **Importer** (Admin section)
> Status: DRAFT — awaiting approval
> Author: Andre
> Date: 2026-04-14
> Pillars touched: **Agent** (User), **Property**, (indirectly) **Agency**

---

## 1. Purpose / Business Requirement

When HFC signs a new agency onto CoreX OS, Property24 exports three CSVs containing the agency's full live stock (agents, listings, images). Today this must be migrated by hand. The Importer module ingests these three CSVs — exactly as P24 exports them — downloads every referenced image locally, maps every field into the correct CoreX pillar, shows a preview for human confirmation, and on confirm writes the records into the live pillars under the selected Agency.

The CSV shape is **fixed** — P24 always exports the same columns, so the parser can be strict.

---

## 2. Pillar Connections

| Pillar | Read | Write |
|--------|------|-------|
| Agency | Required — user selects target agency before upload | Updates agency's agent + property sets |
| Agent (User) | — | Creates/updates `users` rows (role=agent) from Agents CSV |
| Property | — | Creates/updates `properties` rows from Listings CSV, images downloaded & attached |
| Deal | not touched in v1 | — |

No feature is created as an "island" — every row written links back to its `agency_id` and (for listings) its `agent_id` via `SourceReference` → `CoreX-Agent-XX` mapping.

---

## 3. CSV Inputs (P24 fixed schema)

### 3.1 `Agency-{AgencyId}-export-agents.csv`
Columns: `AgencyId, AgentId, Firstname, Lastname, Status, SourceReference, CountryId, MobileNumber, MobileNumber1..3, FaxNumber, WorkNumber, EmailAddress, Qualification, About, Property24ProfilePictureURL, Published, ReceiveStatsMail, ReceiveGroupListingEmail`.

### 3.2 `Agency-{AgencyId}-export-listings.csv`
Columns (74): `AgencyId, ContactAgentIds, ListingNumber, ListingType, Status, Price, ListingVisibility, OccupationDate, ExpiryDate, Description, DescriptionHeader, …, PropertyTypeId, Bedrooms, Bathrooms, Garages, …, Pool, Flatlet, RentalRate, AuctionDate, …` (full schema captured in importer parser).

`ContactAgentIds` is a comma-separated list of P24 `AgentId`s — first one = primary agent.

### 3.3 `Agency-{AgencyId}-export-images.csv`
Columns: `ListingNumber, Caption, Ordinal, Prop24ImageUrl`.
Join key: `ListingNumber` → matches `Listings.ListingNumber`.

---

## 4. Field Mapping

### 4.1 Agents CSV → `users` table (role = agent)
| P24 column | CoreX column |
|------------|--------------|
| AgentId | `p24_agent_id` (new nullable int, indexed) |
| Firstname + Lastname | `name` |
| EmailAddress | `email` (unique; if collision → update existing) |
| MobileNumber | `phone` / `mobile` |
| WorkNumber | `work_phone` |
| About | `bio` |
| Qualification | `designation` (if empty) |
| Property24ProfilePictureURL | downloaded → `profile_photo_path` |
| Status (Active/Inactive) | `is_active` (bool) |
| Published | `is_published` |
| AgencyId | `agency_id` (from selected agency) |
| SourceReference (`CoreX-Agent-XX`) | `source_reference` (nullable string) used to resolve listing→agent |

**Account state on import:**
- All imported agents are created with `is_active = false` (inactive) regardless of their P24 `Status` — the admin explicitly activates them by sending an invite.
- No password set; `password` column gets a random unusable hash.
- Role defaulted to `agent`.
- After import, the preview/run detail shows each agent with a **Send Invite** button (and a **Send All Invites** bulk action). Invite = signed email link → set-password screen → on completion `is_active` flips to true and `email_verified_at` is set.
- Invites use Laravel's password-reset/`Notification` system with a custom `AgentInviteNotification` subject "Welcome to CoreX OS — set your password".
- Agents must exist in `users` **before** the listings pass runs, so that listings can resolve `agent_id` by `p24_agent_id` lookup.

### 4.2 Listings CSV → `properties` table
| P24 column | CoreX column |
|------------|--------------|
| ListingNumber | `external_id` (also indexed as `p24_listing_number`) |
| ListingType (Sale/Rental) | `listing_type` |
| Status (NewListing/Active/Reduced/Rented/Sold) | `status` (normalised) |
| Price | `price` (Sale) / `rental_amount` (Rental) |
| Description | `description` |
| DescriptionHeader | `headline` / `title` |
| StreetNumber, StreetName | `street_number`, `street_name`, `address` |
| ErfSize, ErfAreaUnit | `erf_size_m2` (converted) |
| FloorArea, FloorAreaAreaUnit | `size_m2` |
| Bedrooms / Bathrooms / Garages | `beds` / `baths` / `garages` |
| PropertyTypeId | `property_type` (via P24 lookup map) |
| OccupationDate, ExpiryDate | `occupation_date`, `expiry_date` |
| MonthlyLevy, MunicipalRatesAndTaxes | `levy`, `rates_taxes` |
| SourceReference | `source_reference` |
| ContactAgentIds[0] | `agent_id` (resolved via `p24_agent_id`) |
| AgencyId | `agency_id` |
| Pool, Flatlet, Garden, Furnished, PetsAllowed | boolean flags on `features_json` |
| LeasePeriod, RentalRate, DepositRequirementsComments | rental fields |
| AuctionDate, AuctionVenue, AuctionDescription | auction fields |
| Latitude, Longitude | same |

### 4.3 Images CSV → images downloaded + `properties.images_json`
- Group rows by `ListingNumber`, sort by `Ordinal`.
- Download each `Prop24ImageUrl` to `storage/app/public/properties/{property_id}/{ordinal}.jpg`.
- Store ordered relative paths in `properties.images_json`; first = cover.
- Failed downloads logged to import run but do not abort the row.

### 4.4 Agent profile picture
Downloaded to `storage/app/public/agents/{user_id}.jpg`, path written to `users.profile_photo_path`.

---

## 5. UI / Navigation

### 5.1 Placement
- **Sidebar → Admin → Importer** (new entry, icon: `upload-cloud`)
- Route: `/admin/importer`
- Route name: `admin.importer.index`
- Permission key: `admin.importer` (add to `config/corex-permissions.php`)
- Middleware: `auth`, `can:admin.importer`

### 5.2 Pages

**`index.blade.php` — Upload screen**
Top bar: **Target Agency** dropdown (required, defaults to current agency).
Two cards side-by-side:

1. **Agents**
   - File input: accepts `*-export-agents.csv`
   - "Parse & Preview" button

2. **Listings + Images** (grouped, both required together)
   - File input 1: `*-export-listings.csv`
   - File input 2: `*-export-images.csv`
   - Note: "Images are matched to Listings by ListingNumber."
   - "Parse & Preview" button

Below: history table of previous import runs (date, agency, type, rows, status, link to detail).

**`preview.blade.php` — Preview screen**
Shown after parse. Server has stored the raw parsed rows in a `listing_import_runs` record (status=`pending_confirm`) with child `listing_import_rows`. No pillar writes yet.

Layout:
- Summary cards: `X agents, Y listings, Z images` + counts of `new / update / skip / error`.
- Tabs: **Agents**, **Listings**, **Images**.
- Agents tab: table — AgentId, Name, Email, Status, action (create/update/skip), validation errors in red.
- Listings tab: table — ListingNumber, Type, Status, Price, Address, Primary agent resolution, image count, action, errors.
- Images tab: thumbnails grouped per listing (images not yet downloaded — shows P24 URL + count; downloads happen on confirm).
- Row-level checkboxes to exclude any row from the import.
- Buttons: **Cancel** (discard run) / **Confirm & Import**.

**`show.blade.php` — Run detail (post-import)**
Read-only view of a completed run: counts, per-row outcomes, download failures, links to created records.

**`review.blade.php` — Property Review Queue (persistent, standalone)**
Sidebar entry: **Admin → Importer → Property Review** (own nav link, route `admin.importer.review`).

Purpose: listings parsed via Stage 2 land here in a **pending** state and stay there until an admin explicitly confirms each one (or bulk-confirms). This is not a modal — it's a working queue the admin can leave and return to over multiple sessions.

Layout:
- Filters bar (sticky top):
  - Agency dropdown
  - Import run dropdown (or "All")
  - Agent (resolved) filter
  - Listing type: Sale / Rental / All
  - Status: Pending / Confirmed / Excluded / Error / All (default: Pending)
  - Has errors: Yes / No / All
  - Search box (ListingNumber, address, suburb)
- Bulk actions bar: **Confirm selected**, **Exclude selected**, **Confirm all (filtered)**.
- Table columns: checkbox, ListingNumber, Address, Suburb, Type, Price, Beds/Baths, Resolved Agent, Image count, Errors (red badge with count), Row status, Actions.
- Row actions (per listing): **View details** (side drawer showing full mapped payload + all image thumbnails + raw CSV payload), **Confirm**, **Exclude**, **Re-resolve agent**.
- Errors column expands inline: missing agent, image download failures, invalid price, unknown PropertyTypeId, etc.
- Confirming a row triggers the same write path as bulk confirm (transactional): creates/updates `properties`, downloads any pending images, links `agent_id`, writes `images_json`, marks row `confirmed`.
- Excluding a row soft-marks it (`excluded=true`); nothing is written to `properties`. Can be un-excluded later.
- Empty state: "No listings pending review. Start a new import from Admin → Importer."

Persistence: rows live in `listing_import_rows` with `row_type=listing`, `status` one of `pending|confirmed|excluded|error`. The review screen simply queries this table — admin can close and re-open any time.

**Important distinction from the old single-run preview:**
- The *run preview* (Section 5.2 `preview.blade.php`) is a one-shot parse-time confirm for **agents**.
- For **listings**, parse completes straight into the review queue with `status=pending` — there is no forced "confirm everything now" step. Admins confirm at their own pace on the review screen.

### 5.3 UX rules
- Follows CoreX design system: `rounded-md`, surface tokens, brand buttons.
- Confirm button uses `--brand-button`.
- No destructive action without confirmation modal.
- Soft delete only (if a run is cancelled post-confirm, we mark run `deleted_at`; created records remain but flagged).

---

## 6. Data Model

### 6.1 New tables (decided 2026-04-14 during build)
We do **NOT** reuse the pre-existing `listing_import_runs` / `listing_import_rows` — those tables are already in use for the Agent Listing Stock import flow and have an entirely different schema/purpose. Instead the P24 importer uses two new dedicated tables:
`p24_import_runs`, `p24_import_rows`.

Columns on `p24_import_runs`:
- `kind` enum(`agents`,`listings_images`) — distinguishes run type
- `agency_id` FK
- `agents_csv_path`, `listings_csv_path`, `images_csv_path` (nullable)
- `status` enum: `parsing`, `pending_confirm`, `importing`, `completed`, `failed`, `cancelled`
- `counts_json` (summary snapshot)
- `user_id` (who ran it)
- `confirmed_at`, `completed_at`

Columns on `p24_import_rows`:
- `row_type` enum(`agent`,`listing`,`image`)
- `external_id` (AgentId / ListingNumber)
- `payload_json` (raw CSV row)
- `mapped_json` (parsed/normalised payload — what will be written on confirm)
- `action` enum(`create`,`update`,`skip`)
- `status` enum(`pending`,`confirmed`,`excluded`,`error`) — drives the review queue
- `resolved_agent_id` (users.id, nullable) — pre-resolved at parse time
- `target_id` (properties.id once confirmed)
- `errors_json`
- `image_urls_json` (per-listing ordered P24 URLs, populated at parse so images can download on confirm)
- `confirmed_at`, `excluded_at`, `confirmed_by`

### 6.2 New migration
- `users`: add `p24_agent_id` (int, nullable, index), `source_reference` (string, nullable).
- `properties`: add `p24_listing_number` (string, nullable, index) if not present.

All schema changes via new migrations, never edit historical ones.

---

## 7. Flow (Happy Path)

The importer is **sequential and enforced in two stages** — agents must be imported (and exist as users) before listings can be imported, so listings can bind to real `agent_id`s.

### Stage 1 — Agents
1. Admin opens **Admin → Importer**, picks Target Agency.
2. Uploads `*-export-agents.csv`. Server parses, validates, shows preview.
3. Admin confirms. Job creates all agents in `users` as **inactive** (`is_active=false`, no password), downloads profile photos, stores `p24_agent_id` + `source_reference`.
4. Run detail page shows all imported agents with:
   - Per-row **Send Invite** button
   - **Send All Invites** bulk action at top
5. Agent receives email → sets password → account flips to active.

### Stage 2 — Listings + Images
6. Admin returns to **Admin → Importer** (or is auto-advanced from Stage 1 completion).
7. The Listings+Images upload card is **disabled until at least one Agents run has completed for the selected agency** (guardrail — message: "Import agents first so listings can be linked.").
8. Admin uploads `*-export-listings.csv` + `*-export-images.csv` together. Server parses both; for each listing, resolves `agent_id` via `ContactAgentIds[0]` → `users.p24_agent_id` under the selected agency.
9. Preview shows listings with resolved agent name next to each row; unresolved = row error (admin must fix by importing the missing agent first or unchecking the row).
10. Admin confirms. Job (`ProcessImporterRunJob`):
    - Creates/updates `properties` rows, `agent_id` set from resolution, `agency_id` set from run.
    - Downloads images per `ListingNumber` in order, writes `images_json`.
11. Run marked `completed`; listings now live under their agent's name and under Properties.

*Agents activation (invite → password set) can happen in parallel with Stage 2 — a listing can be assigned to an inactive agent; it becomes visible in their dashboard the moment they activate.*

---

## 8. Validation Rules

- Agency must be selected.
- Agents CSV: AgentId numeric, Email valid & unique within file.
- Listings CSV: ListingNumber numeric unique within file; ContactAgentIds references must resolve (against either already-imported agents OR agents CSV in same session) — unresolved = row error.
- Images CSV: ListingNumber must match a listing in the Listings CSV (or an existing property) — orphans listed under "Images tab → Orphans".
- File size cap: 50 MB per CSV (configurable).
- Image download: timeout 15s, 3 retries, failures logged.

---

## 9. Permissions

```php
// config/corex-permissions.php
'admin.importer' => [
    'label' => 'Access Importer',
    'group' => 'Admin',
],
```
Sidebar entry gated by `@can('admin.importer')`. Route middleware `can:admin.importer`. Controller method-level `authorize('admin.importer')`.

---

## 10. Acceptance Criteria

- [ ] Sidebar shows **Admin → Importer** for permitted users, hidden otherwise (same day as page).
- [ ] Uploading the three sample CSVs (`Agency-31357-export-*.csv`) parses without error and shows preview with:
  - 5 agents (2 active, 3 inactive)
  - 6 listings (Sale + Rental mix)
  - 134-row image set mapped to 6 listings
- [ ] Preview flags `100314486` (SourceReference `CoreX-17`) with no resolvable agent — because `ContactAgentIds=77825` resolves from imported agents.
- [ ] On confirm, 6 properties created under Agency 31357, each with correctly-ordered local images; 5 users created with profile photos.
- [ ] Re-running the same CSV → action = `update` for every row (idempotent), no duplicates.
- [ ] All rows soft-deletable; no hard deletes used anywhere.
- [ ] `scripts/dev-check.ps1` passes with 0 new failures.
- [ ] `php -l` clean on all new/changed PHP files.
- [ ] Tinker verification: route resolves, view renders, model instantiates, confirmed run persists + loads.

---

## 11. Files to Create / Modify

**New**
- `app/Http/Controllers/Admin/ImporterController.php` (index, parseAgents, parseListings, preview, confirm, show, cancel, sendInvite, sendAllInvites)
- `app/Notifications/AgentInviteNotification.php`
- `app/Services/Importer/P24AgentsCsvParser.php`
- `app/Services/Importer/P24ListingsCsvParser.php`
- `app/Services/Importer/P24ImagesCsvParser.php`
- `app/Services/Importer/P24ImageDownloader.php`
- `app/Services/Importer/P24PropertyTypeMap.php`
- `app/Jobs/ProcessImporterRunJob.php`
- `resources/views/admin/importer/index.blade.php`
- `resources/views/admin/importer/preview.blade.php`
- `resources/views/admin/importer/show.blade.php`
- `resources/views/admin/importer/review.blade.php`
- `resources/views/admin/importer/partials/property-drawer.blade.php`
- `database/migrations/2026_04_14_000001_extend_listing_import_runs_for_p24_importer.php`
- `database/migrations/2026_04_14_000002_add_p24_ids_to_users_and_properties.php`

**Modify**
- `routes/web.php` — admin importer routes
- `resources/views/layouts/corex-sidebar.blade.php` — add nav entry under Admin
- `config/corex-permissions.php` — add `admin.importer`
- `app/Models/ListingImportRun.php` — new casts/fillable
- `app/Models/ListingImportRow.php` — new casts/fillable

---

## 12. Out of Scope (v1)

- Scheduled/automatic P24 pulls (this is a **manual** upload tool).
- Deal import from P24.
- Updating listings on P24 from CoreX (one-way only: P24 → CoreX).
- Contact (buyer/seller) enrichment from listing data.

---

## 13. Open Questions

1. When agent email collides with an existing CoreX user (different agency), do we **skip**, **update**, or **refuse import**? → *Proposal: skip with warning; admin can manually re-assign.*
2. Should re-import update image files, or only when P24 URL changes? → *Proposal: hash-compare filename; only re-download if URL differs.*
3. Should inactive P24 agents be imported at all, or skipped by default? → *Proposal: import but mark `is_active=false`, checkbox in preview to exclude.*
4. Image storage: local disk vs S3? → *Proposal: whatever current `properties.images_json` uses — mirror that.*

---

*End of spec. Awaiting approval before code.*
