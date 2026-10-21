# Rental Portal Uploads — meter readings and utility bills from the tenant and owner portal (AT-484)

**Status:** SPEC DRAFT, 21 Oct 2026 — NOT built, NOT approved. Written for Johan's review; no code until he says go.
**Ticket:** AT-484. **Pillars:** Contact (the tenant or owner who sends it), Property, Lease (the tenancy it belongs to),
Agent (`User` — the person who reviews it).
**Builds on, does not duplicate:** `rental-portal-access.md` (the login-gated `/portal`, tenant and owner identities, Documents
visibility, fault photos §22), `rental-command-centre.md` §3.2 (the agent's needs-action queue),
`notification-system-overview.md` (alerts), `rental-inspections.md` (meter readings at move-in / move-out stay there, untouched).
**Source of this draft:** cc2's investigation findings as relayed by Johan on 21 Oct 2026, each re-checked against the code on
`origin/QA1` (7e78c2a9f). Anything not in that brief and not in the code is marked **DECISION (proposed)** or **OPEN (business)**.

---

## PART A — WHAT THE PEOPLE SEE (plain language)

### The tenant
Signed in to their portal, the tenant of an active lease sees two new things under their lease:

1. **Meter readings.** "Send a meter reading." They pick the meter (electricity, water — the agency decides which meters exist),
   take a photo of the meter or choose one from their gallery, type the number they can see, check the date (today unless they
   say otherwise), and press Send. They see a list of every reading they have sent, newest first, each marked
   **Received**, **Accepted** or **Query from your agent** (with the agent's question in plain words). If they made a typing
   mistake they can correct a reading until the agent has dealt with it (or for a short window the agency sets), and they can
   withdraw a reading they sent by mistake.
2. **Utility bills.** "Send a bill or statement." They choose what it is (water bill, electricity bill, levy statement, other),
   add one or more files or photos (phone photos, PDFs), and Send. They see everything they have sent, with the date, and can open
   it again. They never see anyone else's documents.

Everything works from a phone: **Take photo** and **From gallery** are two separate buttons (exactly like the fault photo picker
today), iPhone photos just work, and big photos are shrunk before they are sent so a poor signal does not stop them.

### The owner
Signed in to their portal, the owner sees the readings and utility documents for **their own** properties (read-only for readings),
and can send a **utility document** — for example a rates or levy statement — for a property of theirs. They do not send meter
readings (they are not at the meter). **OPEN (business) #1** asks Johan to confirm this.

### The agent (and the office)
- A reading or a bill arriving makes a **new row in the agent's needs-action queue** ("Meter reading received" / "Utility
  bill received", with the property address and who sent it, one **Open** button) **and an alert**. The row goes away when the
  agent accepts it, sends a query back, or archives it.
- On the lease screen there is a **Meter & utilities** tab: every reading (with the photo), every bill, who sent it and when,
  and the buttons **Accept**, **Query** (type a question; the tenant is told), **Edit** (correct a reading on the tenant's
  behalf, with a reason that is kept), **Archive** and **Restore**. The agent can also **add** a reading or a bill on a tenant's
  behalf (the tenant phoned it in).
- A **Meter readings** list and a **Utility documents** list under Rentals show everything the viewer is allowed to see, with search,
  sorting, filters and paging (details in Part B §8).

### What the agency can switch (every one is an agency setting, with a sensible default)
Whether tenants may send readings, whether tenants and owners may send utility bills, which meters exist, how far back a reading may be
dated, how long a tenant may correct their own reading, how many photos and files and how big, and whether the agent and the
tenant are emailed. These are also offered in the **Setup Wizard** (leases step) so an agency is walked through them.

---

## PART B — TECHNICAL

### 1. Principles (non-negotiable, carried from the standards)
- **Multi-agency, never single.** No HFC wording, address, meter list or default anywhere. Every number, list and switch below is
  an agency setting in `RentalPortalSetting` (read-time default pattern: a blank column resolves to the `DEFAULT_*` constant).
  The test for every line: *what does this look like for the second agency?*
- **No hard deletes.** Archive = soft delete (`deleted_at`); Restore is a real action; nothing is ever removed from disk by an
  archive (PrivateImageService guards switch the picture's link off instead).
- **Scoping at the query layer** (own / branch / agency) for every list, detail, export, download, image link and API endpoint.
  A direct URL or id the viewer may not see is a 404, never an "unlinked" screen.
- **No exploring in the build:** the files in §12 are the scope.

### 2. What already exists and is reused (verified on `origin/QA1`)
| Need | Reuse | Where |
|---|---|---|
| Portal sign-in, tenant/owner identities, shell | `/portal/{any?}` → `RentalPortalShellController::show`; `ClientUser` ↔ `Contact`; tenant = Contact on an active `lease_tenants` row; owner = `contact_property.role IN ('landlord','lessor')` | `routes/web.php:113`, `rental-portal-access.md` §2–3 |
| Photo picker (camera + gallery, shrink in browser) | `rentals/portal/_photo-picker.blade.php` + the shell's `addPhotos` / `compressPhoto` | `resources/views/rentals/portal/` |
| iPhone (HEIC) photos | Browser-side shrink converts for display; server side an undecodable HEIC raises `UnreadableImageException` (audit L3) which must be turned into a plain "please retake the photo" message, never a 500 | `app/Exceptions/UnreadableImageException.php` |
| Private picture storage + expiring links | `PrivateImageService` — `private:<path>` refs on the private `local` disk, `signedUrl()` via `GET /private-image/{token}`, expiry from the agency setting, **guards** = the DB rows the picture belongs to (archive → link dies at once) | `app/Services/Images/PrivateImageService.php` |
| Files (PDF/Word/etc.) from a no-agent screen | The precedent is `RentalApplicationSigningController::uploadDocuments` — validate array + `mimes` + `max`, store on the private `local` disk, create a `Document` row stamped with the *record's own* agency/branch via `Document::withoutAgencyStamping()`, attach `document_contacts` and `document_properties` | `app/Http/Controllers/RentalApplicationSigningController.php:1245` |
| "Which documents may this person see" | `Document.tenant_portal_visible` / `landlord_portal_visible` + `document_contacts` | `rental-portal-access.md` §2/§5 |
| Settings container | `RentalPortalSetting` (+ `RentalPortalSettingsController`, one narrow saver per setting) | `app/Models/RentalPortalSetting.php` |
| Agent queue | the Rentals needs-action queue, one row per item, urgency-then-age | `rental-command-centre.md` §3.2 |
| Alerts | the notification dispatcher + `RentalAlertTitle::withProperty()` (every rentals alert title ends " - <property short address>") | `app/Support/RentalAlertTitle.php` |
| Meter readings elsewhere | `rental_inspection_items` rows with `kind='meter'`; readings are **TEXT, not numeric** (a body-corporate meter can read "00123.4 / 0098") | `2026_10_01_110000_add_header_block_to_rental_inspections.php` |

There is **no** table for portal-submitted meter readings today (only the inspection-item meter rows) — that is the one new table.

### 3. Data model

**3.1 New table `rental_meter_readings`** (soft deletes)
| Column | Notes |
|---|---|
| `id`, `agency_id`, `branch_id` | `BelongsToAgency`, `BelongsToBranch`, `InheritsBranchFromParent` (same traits as `RentalApplication`) |
| `property_id`, `lease_id` | required; `lease_id` is the lease active on the reading date (a reading with no active lease is refused, §6) |
| `meter_type` | string, one of the agency's configured meter types (§7) — stored as the key, not a free label |
| `meter_number` | nullable string (the number printed on the meter; helps when a property has two) |
| `reading_text` | string(100), **text not numeric** (same reason as inspections); validated as digits, spaces, `.`/`,`/`/`/`-` only, 1–100 chars |
| `reading_date` | date; default today; may be back-dated at most `meter_reading_max_backdate_days`; never future |
| `photo_ref` | nullable string — a `private:` ref (PrivateImageService). Photo required or optional per setting `meter_reading_photo_required` |
| `submitted_by_contact_id` / `submitted_by_user_id` | exactly one is set: the portal contact, or the agent who captured it on the tenant's behalf |
| `submitted_via` | `portal` \| `agent` |
| `status` | `received` \| `accepted` \| `queried` |
| `reviewed_by_user_id`, `reviewed_at`, `agent_note` (private, staff-only), `query_text` (shown to the tenant) | |
| `corrected_at`, `correction_reason` | set when anyone edits a reading after it was sent; the reason is mandatory for an agent edit |
| timestamps, `deleted_at`, `deleted_by_user_id` | |
Indexes: `(agency_id, status, created_at)`, `(lease_id, meter_type, reading_date)`, `(property_id)`.
**Duplicate guard:** the same lease + meter + reading date + reading text within `meter_reading_duplicate_hours` is refused with "You already sent this reading."

**3.2 Utility documents — no new table.** They are `Document` rows, as in the application-documents precedent:
`source_type = 'rental_portal_upload'`, `source_id = lease_id`, `document_type_id` = a "Utility bill / statement" type
(seeded per agency via the existing document-types mechanism; the four portal choices map to it plus a `description`), `agency_id`
/`branch_id` from the **lease**, `document_contacts` = the sender, `document_properties` = the property, `tenant_portal_visible =
true` for a tenant's own upload (so the tenant sees it again), `landlord_portal_visible` per agency setting
`utility_docs_visible_to_owner` (default on), `uploaded_by_contact_id` where the column exists, else recorded on `source_meta`.
Stored on the private `local` disk under `rental-portal-uploads/{lease_id}/…` (never the public disk).
A utility **photo** (a phone picture of a bill) is stored the same way (a `Document`, mime image/jpeg|png|webp) — not as a
`private:` picture ref — because it is a document the office files, not a decorative image.

**3.3 Review state for utility documents** — a small `rental_utility_document_reviews` is **not** created; the agent's queue row for
a bill clears on **Open + mark filed** which sets `Document.meta.reviewed_at/by` (existing `meta` JSON if present, else one nullable
`reviewed_at`/`reviewed_by_user_id` pair on `documents` — the build verifies which exists and uses it; no new table either way).

### 4. Behaviour rules
1. **Who may send:** a tenant on an active lease of this property (tenant portal enabled for the agency + `tenant_meter_readings_enabled`
   / `tenant_utility_uploads_enabled`); an owner for utility documents only (`owner_utility_uploads_enabled`). Anyone else: 403.
2. **A reading belongs to the lease active on `reading_date`.** Ended/future leases do not accept readings. A tenant never sees or can
   address another lease's readings (id enumeration returns 404).
3. **Photo handling:** the picker shrinks before upload; the server re-validates (mime allow-list `jpeg, png, webp, heic, heif`,
   max `portal_upload_max_mb`, max `meter_reading_photo_max_count` per reading), stores via `PrivateImageService`, and on an
   undecodable HEIC answers with the plain retake message. SVG is never accepted.
4. **Files:** allow-list `pdf, jpg, jpeg, png, webp, heic, heif, doc, docx, xls, xlsx` (the application-documents list, reviewed in the
   build), max `utility_upload_max_files` files of `portal_upload_max_mb` each per send. Original filename kept for display, stored
   name is generated.
5. **Tenant correction:** a tenant may edit or withdraw (archive) their own reading while `status = received` and within
   `meter_reading_correction_hours` of sending, or any time while `status = queried`. After the agent accepts it, only an agent can
   change it (with a reason). Every change stamps `corrected_at` / `correction_reason`.
6. **Query loop:** agent **Query** → `status = queried`, `query_text` shown to the tenant, tenant emailed if
   `notify_tenant_on_reading_query` (default on); the tenant corrects or re-sends → back to `received` (new queue row).
7. **Accept:** `status = accepted`; the queue row clears; nothing is emailed by default.
8. **No effect on inspections or billing.** A portal reading never writes to `rental_inspection_items` or any money table.
   (Using readings in a utility-recovery calculation is out of scope — see §14.)

### 5. Agent queue and alerts
- **Queue (rental-command-centre §3.2):** two new rules, one row **per item** (not collapsed per lease), one action each:
  | Rule | Fires when | Action | Links to |
  |---|---|---|---|
  | Meter reading received | `rental_meter_readings.status = 'received'` and not archived | **Open** | the reading on the lease's *Meter & utilities* tab |
  | Utility bill received | a `rental_portal_upload` Document not yet marked reviewed and not archived | **Open** | the document on the same tab |
  Detail line names its own record ("Electricity 04512 — <tenant name>", "Water bill — <tenant name>"); `age_days` is a clean
  non-negative whole number (the §10.3 Carbon rule). Queue scoping is the same property-first own/branch/agency derivation as
  the other five rules.
- **Alerts:** two new alert types (`rental_meter_reading.received`, `rental_utility_document.received`) through the existing
  dispatcher, to the property's agent (own scope) with branch/agency fall-back as the other rental alerts do. Title =
  `RentalAlertTitle::withProperty(…)`.
  **Trap to design out (known):** the dispatcher's per-type-per-subject cooldown swallows a second alert of the same type for the same
  subject. The **subject must be the reading / document row, not the lease**, so two readings in a day each alert. The build adds a
  test that two readings on one lease both alert.
- **Domain events (non-negotiable #9):** emit `RentalMeterReadingSubmitted` and `RentalUtilityDocumentUploaded` (add both to
  `corex-domain-events-spec.md`); the queue/alert/mail are listeners, not inline calls.

### 6. Portal API (non-negotiable #7 — `/api/v1/*`, named, listed on `/admin/api`)
Portal session endpoints under the existing client portal prefix, each throttled by the agency's `portal_api_per_minute` (uploads
count):
- `GET  /api/v1/client/meter-readings` (own lease, paged) · `POST …/meter-readings` · `PATCH …/{reading}` · `DELETE …/{reading}`
  (= archive) — tenant only; a reply carries `status`, `query_text`.
- `GET  /api/v1/client/utility-documents` · `POST …/utility-documents` (multipart) · `GET …/{document}/file` (signed, expiring)
  — tenant and owner, scoped as in §2.
- `GET  /api/v1/client/meter-types` — the agency's configured meters, so the portal never hard-codes them.
Staff (web) routes: `corex.rental-meter-readings.index|show|create|store|edit|update|accept|query|archive|restore|export`,
`corex.rental-utility-documents.index|show|store|file|mark-reviewed|archive|restore|export`, plus the *Meter & utilities* tab on
`corex.leases.show`. Image/file links are signed + expiring; a revoked/archived row's link stops working at once.

### 7. Agency settings (`RentalPortalSetting` columns; every default is a constant, every value validated, none hard-coded)
| Setting | Default | Range / note |
|---|---|---|
| `tenant_meter_readings_enabled` | on | |
| `tenant_utility_uploads_enabled` | on | |
| `owner_utility_uploads_enabled` | on | |
| `meter_types` | `electricity`, `water` | list of key + label; agency can add gas, solar, etc.; at least one; keys immutable once used |
| `meter_reading_photo_required` | on | |
| `meter_reading_photo_max_count` | 2 | 1–10 |
| `meter_reading_max_backdate_days` | 7 | 0–60 |
| `meter_reading_correction_hours` | 24 | 0–720 |
| `meter_reading_duplicate_hours` | 24 | 0–168 |
| `portal_upload_max_mb` | 8 | 1–15 (same ceiling family as the fault photo size) |
| `utility_upload_max_files` | 10 | 1–20 |
| `utility_docs_visible_to_owner` | on | |
| `notify_agent_on_portal_upload` | on | |
| `notify_tenant_on_reading_query` | on | |
**Setup Wizard (non-negotiable #10a):** every setting above is a control in the **`leases`** step of
`config/agency-onboarding-copy.php` (the step that already carries the rentals-portal savers), each with a full-sentence `explain` and
a concrete "What this changes:" `affects`; each wired through its own narrow saver whose boolean write is `$request->has()`-guarded
(spec `agency-onboarding-setup.md` §6.1 — the step posts a subset). Settings page and wizard stay in step; a control removed from one
is removed from the other in the same change. **Deliberately not in the wizard: none proposed** (if Johan wants an expert knob kept
off the wizard it goes in `agency-onboarding-setup.md` §5.1 by his decision).

### 8. Screens — the design standard (full CRUD, search / sort / filter / pagination, scoping, navigation)
**A. Meter readings list** (`corex.rental-meter-readings.index`, Rentals sidebar → *Meter readings*)
- **Search (named fields):** property address, tenant name, meter number, reading text.
- **Sort:** reading date (**default, newest first**), submitted at, property, tenant, meter type, status.
- **Filters:** status (received / accepted / queried / archived), meter type, reading-date range (minimum), property, sent by (portal / agent), agent.
- **Paging:** the agency's per-page setting; real empty state ("No meter readings yet — tenants can send them from their portal").
- **Row actions:** Open, Accept, Query, Edit, Archive / Restore (archived rows only in the *archived* filter). Bulk: Accept selected.
- **Export:** CSV of the filtered, **scoped** list.
**B. Utility documents list** (`corex.rental-utility-documents.index`, Rentals → *Utility documents*)
- **Search:** file name, property address, sender name, document type.
- **Sort:** uploaded at (**default, newest first**), property, sender, type.
- **Filters:** reviewed / not reviewed, type, uploaded-date range, property, sender (tenant / owner / agent), archived.
- **Row actions:** Open (signed link), Mark reviewed, Archive / Restore. **Export** as above.
**C. Lease screen *Meter & utilities* tab** — the same data for one lease, readings grouped by meter with the photo, plus "Add a reading" /
"Add a document" on the tenant's behalf. Linked from the lease and from the queue row.
**D. Portal screens** (tenant, owner) — as Part A; both have paging and an empty state.
**Navigation (non-negotiable #2):** both lists are in the Rentals sidebar the day this ships; the lease tab is added to the lease screen; the portal
tabs are added to the portal shell. A page without a link does not exist.
**Permissions (non-negotiable #5):** new keys in `config/corex-permissions.php`: `view_rental_meter_readings`, `manage_rental_meter_readings`
(accept / query / edit / archive / restore / add on behalf), `view_rental_utility_documents`, `manage_rental_utility_documents`; sidebar gating, route
middleware and controller checks. Defaults follow the other rentals keys (office admin + agents on their scope).
**Scoping (query layer):** `BelongsToAgency` + the rentals property-first own / branch / agency derivation used by faults and work orders
(own = the viewer's properties/leases; branch; agency). Portal queries are scoped by the signed-in contact's lease/property links, never by an id in the URL.

### 9. Security
Nothing is web-reachable by path. Pictures only through `PrivateImageService::signedUrl()`; files only through a signed expiring route that re-checks
scope. Mime allow-lists on both client hint and server (server wins); SVG and scripts refused; filenames never trusted; per-minute throttle per
signed-in portal person (`portal_api_per_minute`); every upload, correction, query and archive writes an audit row (who, what, when, from where).
A tenant's reading photo is never shown to the owner unless the agency enables that (it is not enabled by this spec — see OPEN #2).

### 10. Acceptance criteria
1. A tenant on an active lease sends an electricity reading with a phone photo (JPEG and iPhone HEIC); it appears in their list as *Received*.
2. The agent gets a queue row **and** an alert naming the property; a second reading the same day gives a second row **and** a second alert.
3. Agent *Query* shows the question to the tenant and emails them (setting on); the tenant corrects it; it returns to the queue.
4. A tenant cannot see, edit or fetch another lease's reading or file by guessing an id (404), and cannot edit after acceptance.
5. A utility bill sent as 3 files files three `Document` rows attached to the tenant contact and the property; the tenant sees them again; the owner sees them only if the agency allows.
6. Every list has working search, sort (stated default), filters (status + date range), paging and an empty state; export honours the viewer's scope.
7. Archive hides without deleting; Restore brings it back; archiving a reading kills its photo link at once.
8. Every setting changes behaviour, appears on the settings page **and** in the wizard, and a fresh agency with nothing configured works on the defaults.
9. Nothing in the output names HFC; a second agency with different meter types and limits behaves correctly.

### 11. Tests (targeted, `scripts/lane-test.sh` one file at a time)
Portal send (photo + HEIC fallback message, files), correction window, query loop, duplicate guard, scoping (tenant A vs lease B, owner vs another owner,
branch/agency agent), queue rows (two readings → two rows), alert-cooldown (two readings → two alerts), archive/restore + signed-link death, every setting honoured,
wizard saver `has()`-guard (a posted subset does not wipe other settings), list search/sort/filter/page, export scope.

### 12. Files (proposed — the build confirms exact paths)
New: migration `rental_meter_readings`; `app/Models/RentalMeterReading.php`; `app/Services/Rentals/RentalMeterReadingService.php`,
`RentalUtilityUploadService.php`; `app/Http/Controllers/CoreX/RentalMeterReadingController.php`, `RentalUtilityDocumentController.php`;
portal API controller(s) beside the existing client-portal ones; two Mail classes; two events + listeners; Blade: lists, show/edit, the lease tab partial;
portal shell tab + form components (reusing `_photo-picker`); tests.
Modified: `RentalPortalSetting` (+ migration for columns) and `RentalPortalSettingsController` (one saver per setting); `config/agency-onboarding-copy.php`
(`leases` step); `config/corex-permissions.php`; Rentals sidebar; `corex.leases.show`; the Rentals queue service; `corex-domain-events-spec.md`; `rental-portal-access.md` (cross-reference).

### 13. OPEN — business questions for Johan (plain; nothing technical)
1. Should the **owner** be able to send meter readings too, or only utility bills / statements? (This draft: bills only.)
2. Should the **owner** be able to see the tenant's meter reading **photos**, or only the numbers? (This draft: neither — the agent decides what to pass on.)
3. When the agent accepts a reading, should the tenant get a "thanks, received" message? (This draft: no, only when the agent has a question.)

### 14. Out of scope (stated so it is not built by accident)
Monthly "please send your reading" reminders; turning readings into utility recovery amounts or invoices; reading history charts; tenant-uploaded proof of rent payment; changing the move-in / move-out inspection meter capture.
