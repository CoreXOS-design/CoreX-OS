# Rental take-on import — Landing 1

**Status:** Spec — approved for build (Johan, via conductor, 2026-10-05).
**Pillars:** Property (creates/matches `properties`), Contact (creates/matches landlord + tenant
`contacts`), Deal: not touched. Agent: resolves the importing row's agent.
**Investigation this build follows:** `/tmp/rental-takeon-import-investigation-2026-10-05.md` (not
committed — a scratch investigation doc). Copies the P24 importer's `{Run}+{Row}` batch/per-row-confirm
shape (`app/Http/Controllers/Admin/ImporterController.php`,
`app/Models/P24ImportRun.php`/`P24ImportRow.php`) wholesale. Calls
`TrackedPropertyMatchOrCreateService` (non-negotiable #10) and `ContactDuplicateService` for all
de-dup — no new matching logic is written anywhere in this feature.

---

## 0. Why, in one sentence

A new agency going live on CoreX with an existing book of leases needs to bring that book in as a
batch, with every property/landlord/tenant de-duplicated against what CoreX already knows, nothing
written until a human confirms it, and zero risk of a notification reaching a real tenant or landlord
during the migration.

---

## 1. Scope of THIS landing

Landing 1 only: a fixed Excel template this feature itself generates, upload → dry-run preview →
confirm → archivable batch history. **Landing 2 (saved column mappings for an arbitrary CRM export)
is explicitly NOT built here** — Landing 1's parser reads ONLY the exact template this feature
ships, the same way the P24 importer's parser is allowed to be strict because P24's own export shape
never changes (`importer.md` §1). No `column_mapping` table, no mapping UI, in this landing.

---

## 2. Data model

### 2.1 `rental_take_on_import_runs` — the batch (mirrors `p24_import_runs`)

```
id
agency_id            -- BelongsToAgency, NOT NULL
branch_id            -- nullable, the importing user's branch at upload time
user_id              -- FK users, who ran it
status               -- enum: parsing | pending_confirm | importing | completed | failed | cancelled
source_filename      -- original uploaded filename, for display
source_file_path     -- storage path, read by the async parse job
counts_json          -- {total, will_create_property, will_match_property, will_create_contact,
                      --  will_match_contact, complete, draft, errors}
error_message        -- nullable, set on a run-level parse failure
confirmed_at
completed_at
created_at, updated_at, deleted_at   -- SoftDeletes = "Archive batch" (§7)
```

### 2.2 `rental_take_on_import_rows` — one spreadsheet row (mirrors `p24_import_rows`)

```
id
run_id                           -- FK, NOT NULL
row_number                       -- spreadsheet row number, for error reporting
payload_json                     -- raw parsed cell values, keyed by template field name
property_match_action            -- enum: create | match, resolved at dry-run
property_match_tracked_id        -- nullable, TrackedProperty.id the dry-run found (read-only — no
                                  --   TrackedProperty is created at preview time)
property_match_label             -- nullable string, human-readable match description
landlord_match_json               -- nullable array, one entry per landlord column with duplicate-
                                   --   candidate info from ContactDuplicateService
tenant_match_json                 -- nullable array, one entry per tenant column (up to 4)
lease_completeness                -- enum: complete | draft — whether every hard-mandatory field
                                   --   (§4) is present
errors_json                       -- row-level + per-field errors, plain language
warnings_json                     -- non-blocking notices (e.g. "agent email not found — will use
                                   --   your account")
status                            -- enum: pending | included | excluded | confirmed | error
target_property_id                -- nullable FK, set on confirm
target_lease_id                   -- nullable FK, set on confirm
target_landlord_contact_ids_json  -- nullable array, set on confirm
target_tenant_contact_ids_json     -- nullable array, set on confirm
confirmed_at, confirmed_by         -- FK users
created_at, updated_at, deleted_at  -- SoftDeletes (re-parse on redelivery soft-deletes+recreates,
                                    --   same idempotency guard as ParseP24ListingsImportJob)
```

No `agency_id` on the row — scoped via `run_id → run.agency_id`, exactly like `p24_import_rows`.

---

## 3. The Excel template (download)

Generated on request (`GET /corex/rentals/take-on-import/template`), via PhpSpreadsheet (already a
dependency — the OpenSpout writer used by `ExportsRentalList` cannot write data-validation dropdowns
or a second sheet, so this one export uses PhpSpreadsheet directly, the same library
`SoldPropertyImporter`'s read-side already depends on).

**Sheet 1 — "Take-on data"**, one row per tenancy. Header row bold + frozen; required columns
marked with a leading `*` in the header text (plain-language, not a separate legend the user has to
cross-reference).

| Column | Required | Notes |
|---|---|---|
| Street number | | |
| Street name | * | property match input |
| Unit / complex name | | |
| Suburb | * | property match input |
| Erf number | | property match input |
| Property type | | dropdown: House, Apartment/Flat, Townhouse, Duplex, Vacant Land, Commercial, Other |
| Landlord 1 — name | * | |
| Landlord 1 — ID or company reg no | | |
| Landlord 1 — email | | |
| Landlord 1 — phone | | |
| Landlord 2 — name | | co-owner, optional |
| Landlord 2 — ID or company reg no | | |
| Landlord 2 — email | | |
| Landlord 2 — phone | | |
| Tenant 1 — name | * | |
| Tenant 1 — ID number | | |
| Tenant 1 — email | | |
| Tenant 1 — phone | | |
| Tenant 2/3/4 — name/ID/email/phone | | optional, same shape ×3 |
| Lease start date | * | date |
| Lease end date | | required unless Lease type = Month-to-month |
| Lease type | * | dropdown: Fixed term, Month-to-month |
| Monthly rental amount | * | number |
| Escalation % | | number |
| Next escalation date | | date |
| Deposit held | | number |
| Arrears / opening balance | | number — captured and stored only, see §6 |
| Management fee % | | number — mutually exclusive with the next column |
| Management fee amount | | number |
| Last inspection date | | date — captured and stored only, see §6 |
| Agent email | | must match an existing agent's email on this agency, else falls back to the importer |
| Branch | | dropdown, populated from the agency's own branches at generation time |
| Notes | | free text |

**Sheet 2 — "Instructions"**: one row per column above explaining what it's for and its format
(e.g. dates as `YYYY-MM-DD`), plus: "Do not reorder or rename the columns on the Take-on data sheet —
the import reads them by position." and the no-notification guarantee in plain words ("Importing a
tenancy here never emails or messages the landlord or tenant.").

Dropdown columns use PhpSpreadsheet `DataValidation` with the explicit lists above (property type,
lease type) or the agency's own branch names (generated per-agency, not hardcoded — multi-agency
non-negotiable #9).

---

## 4. Hard-mandatory fields (per `LeaseActivationService`/schema, from the investigation)

`leases.property_id`, `leases.rental_amount`, `leases.start_date` are the only columns the `leases`
table itself requires NOT NULL. A row missing Street name+Suburb (nothing to resolve a property from),
Landlord 1 name, Tenant 1 name, Monthly rental amount, or Lease start date is marked
`lease_completeness = 'draft'` and imports anyway (per the instruction: "rows missing non-critical
data import as DRAFT leases flagged 'needs completing'") **except** that a property cannot be
resolved/created at all without at least Street name + Suburb, OR Erf number + Suburb — a row with
neither is a hard `error`, not a draft, because there is nothing to attach a lease to.

A fixed-term row (`Lease type = Fixed term`) with no end date is `draft`, not an error — the end date
is schema-nullable and the row explains what's missing in `warnings_json`, same prevent-or-absorb
reasoning BUILD_STANDARD §3 requires for every other optional field.

---

## 5. Flow

### 5.1 Upload + parse — SYNCHRONOUS, not async (corrected from the original plan)

`POST /corex/rentals/take-on-import` — validates an `.xlsx`/`.csv` file (same `mimes` + `max:51200`
convention as every other CoreX importer), stores it (`imports/rental-takeon/{agency}/...`), creates
the Run (`status = parsing`), and parses it **in the same request** —
`RentalTakeOnRowParser`/`RentalTakeOnDryRunResolver` run inline, mirroring exactly how the P24
importer's own Stage 1 (`uploadAgents()`) parses synchronously. **Corrected from the original plan,
which had this dispatch an async job on the `p24import` queue**: BUILD_STANDARD §8 states plainly
that QA sites run no queue worker at all — a dispatched job would never execute on QA1, leaving every
run stuck at `status = parsing` forever on the exact environment this feature is being built and
proven against. The P24 importer's own listings stage only went async after a real incident at
**4,753 rows in one request** (`importer-async-parse.md`); a take-on book is tens to a few hundred
tenancies (the instruction's own framing), nowhere near that scale, so synchronous parsing is the
correct choice here, not a shortcut — this is Landing 1's actual answer, not a placeholder pending a
queue worker that doesn't exist on QA1.

The parse streams the upload via OpenSpout (`ContactImportController::streamRows()`'s own pattern:
`XlsxReader`/`CsvReader`, first sheet only), maps each row by fixed column position to the template
field names in §3, and — mirroring `ParseP24ListingsImportJob`'s idempotency guard — soft-deletes any
rows already written for this run before (re)writing, so a re-upload onto the same run never
duplicates (in practice a fresh upload always creates a fresh Run, so this guard is defensive, not
load-bearing day one).

### 5.2 Dry-run preview, per row — `RentalTakeOnDryRunResolver`

For every parsed row, writes `errors_json`/`warnings_json`/`lease_completeness`/`property_match_*`/
`landlord_match_json`/`tenant_match_json`/`status = 'pending'` onto the `RentalTakeOnImportRow`:

- **Property** — `TrackedPropertyMatchOrCreateService::findExistingMatch($agencyId, $facts)` (the
  read-only Phase A.2.5 method — creates nothing) + `describeLastMatch()` for the human-readable
  reason. A match sets `property_match_action = 'match'` + the TrackedProperty id/label; no match sets
  `'create'`. This runs the exact same 7-strategy cascade every other ingestion path uses — no new
  fuzzy matching is written for this feature.
- **Landlord(s) / tenant(s)** — for each populated landlord/tenant column pair,
  `ContactDuplicateService::findDuplicatesForIdentifiers($phones, $emails, $idNumber, $agencyId)`.
  Any hit is shown as a candidate match in the preview (plain-language: "Looks like an existing
  contact: Jane Smith, 082 555 0101") — **never auto-linked silently**, per leases.md §6's own
  migration principle ("where a confident match can't be made, queue for a human to confirm").
- **Agent / Branch** — `Agent email` resolved against `User::where('agency_id', ...)->where('email', ...)`
  within this agency; `Branch` resolved against `Branch::where('agency_id', ...)->where('name', ...)`.
  Either absent/unresolved is a `warnings_json` entry, never an error — absorbed by falling back to
  the importing user's own id/branch at confirm time (same fallback `promoteToStock()` already uses
  for a queued job with no auth context).

The review screen (`resources/views/corex/rentals/take-on-import/preview.blade.php`) shows one row per
spreadsheet row: resolved action (new property / match property "at 12 Beach Rd" / new contact / match
contact "Jane Smith"), `draft`/`complete` badge, errors in red, warnings in amber, a per-row
include/exclude checkbox (default: included unless `status = 'error'`), and a bulk "select all
without errors" + "Confirm selected" action. **Nothing is written to `properties`/`contacts`/`leases`
until this screen's Confirm is pressed** — the entire point of the dry run.

### 5.3 Confirm — `RentalTakeOnConfirmService::confirmRow()`

One DB transaction per row (a failed row never half-imports, per the instruction):

1. `TrackedPropertyMatchOrCreateService::matchOrCreate($agencyId, $facts, ['type' => 'rental_takeon_import', 'ref' => "run:{$run->id}:row:{$row->id}"])` — resolves/creates the `TrackedProperty`.
2. `TrackedPropertyMatchOrCreateService::promoteToStock($tp->id, $userId, ['listing_type' => 'rental', 'branch_id' => $resolvedBranchId], forceCreate: false)` — resolves/creates the real `Property` (rental stock). This is the Universal Match-or-Create Rule (non-negotiable #10) applied correctly — the investigation flagged that neither the P24 nor Sold-Property importer actually does this; this feature does, from day one, without touching either of those two pre-existing gaps (out of scope, reported not fixed, per non-negotiable #2).
3. For each landlord/tenant column pair with data: reuse the dry run's own stored match
   (`landlord_match_json`/`tenant_match_json`'s `existing_contact_id`) rather than re-querying — confirm
   must act on exactly what the preview screen showed, not a second, possibly-different query result.
   A `null` match creates a `Contact`: `first_name`/`last_name` split on the first space of the name
   column (`contacts.first_name`/`last_name` are NOT NULL with no DB default — a single-token name
   gets an empty-string `last_name`, never a crash); the "ID or company reg no" value is classified as
   a personal ID only when it is exactly 13 numeric digits (the SA ID shape), else treated as a
   company registration number and the contact is created with `contact_kind = 'entity'`,
   `entity_name` = the name column, `entity_reg_no` = the value — a documented simplification
   (there is no column on the template that states "this landlord is a company" explicitly); `phone`/
   `email` as given; `contact_type_id` resolved by name `'Landlord'`/`'Tenant'` within the agency,
   left `null` (schema-nullable) if the agency has no such type configured — absorbed, never a hard
   requirement.
4. `ContactPropertyLinker::link($landlordContactId, $propertyId, 'landlord')` for each landlord.
5. `Lease::create([...], 'source' => 'migrated_takeon', 'migrated_from_table' => 'rental_take_on_import_rows', 'migrated_from_id' => $row->id, 'status' => 'draft'])`.
6. `LeaseTenant::create(['lease_id' => ..., 'contact_id' => ..., 'is_primary' => $isFirst])` for each tenant.
7. If the row represents a currently in-force tenancy (lease start date in the past, no notice/cancel
   data — i.e. every take-on row by definition, since this is an existing book), attempt
   `LeaseActivationService::activate($lease)`. A genuine conflict (another active lease already on
   that property — e.g. two rows in the same batch resolved to the same property) leaves the lease
   `draft` with the conflict named in the row's own `errors_json` after the fact, rather than failing
   the whole row — the lease and its contacts are not lost, exactly the "draft flagged needs
   completing" behaviour the instruction asks for.
8. Row marked `confirmed`, `target_*_id(s)` stamped for traceability.

`source = 'migrated_takeon'` is a new value for `leases.source` — checked directly against the
migration: it is a plain `string(30)`, not a real MySQL `ENUM`, so no schema change is needed to add
a new value, only a new `Lease::SOURCE_MIGRATED_TAKEON` constant for consistency with the model's
existing `STATUS_*` constants. This is the exact slot leases.md §2 reserves for "audit trail of how
this lease came to exist."

**No email, WhatsApp, or portal invite is ever sent by this path.** Confirmed by inspection: nothing
in `RentalTakeOnConfirmService` calls `Mail::`, a `Notification`, `SendAgentInviteJob`, or any portal
helper — the service touches only `TrackedProperty`/`Property`/`Contact`/`ContactProperty`/`Lease`/
`LeaseTenant`. A dedicated test asserts `Mail::assertNothingSent()` across a full confirm run (§9).

Per-row confirm (`POST .../rows/{row}/confirm`) and bulk confirm (`POST .../rows/confirm-bulk`, same
shape as P24's `confirmBulk()`) both exist; bulk confirm processes rows synchronously in this landing
(a take-on batch is tens to a few hundred rows, not the thousands a P24 stock import can be — no
`Bus::batch()` queue dispatch needed yet; revisit if a real batch proves this wrong).

---

## 6. What this import deliberately does NOT write anywhere yet

- **Arrears / opening balance** — stored in the row's `payload_json` only (visible in the batch's
  per-row detail and error report). Rental money / trust-ledger accounting is not built in CoreX yet
  (per the instruction) — there is no table to post this to, so none is invented here.
- **Escalation % / next escalation date** — `lease_escalations` is an append-only record of escalation
  events that have ALREADY happened (`previous_rental_amount`/`new_rental_amount` both required,
  computed at entry time — leases.md §3.4). A take-on row's "next escalation" is a future, not-yet-
  happened event; writing it into that table would mean fabricating a previous/new amount pair that
  never actually occurred — a false historical record. There is also no "scheduled escalation clause"
  field on `leases` itself (out of scope for this spec to add — that is a `leases.md` schema change,
  not an import-feature change, and risks colliding with the several other lanes actively adding
  columns to `leases` this week). Stored in `payload_json` only. **Flagged for Johan, plain language:**
  the escalation % and next-due date you type in is saved and visible on the import batch, but will
  not yet show on the lease screen itself — if you want it there, that's a small separate follow-up
  to `leases.md`, not part of this import.
- **Last inspection date** — `rental_inspections.lease_id` is a required FK (leases.md §9.1); it
  cannot exist before the `Lease` row does, and building a historical-inspection-record importer was
  not asked for here. Stored in `payload_json` only.

None of these three are silently dropped — every one survives in the row's own stored data and in the
downloadable error/detail report, satisfying "captured and stored" exactly as instructed, without
inventing a destination that doesn't exist or isn't this ticket's to build.

---

## 7. Batch history screen — CRUD/list-screen floor (BUILD_STANDARD §1a-§1d)

New screen, new nav entry, same day. Route group `corex.rentals.take-on-import.*`, sidebar entry under
Rentals (alongside Leases).

- **Search** (named fields): source filename, uploading user's name.
- **Sort**: created date (default: newest first — a batch history is read most-recently-first, unlike
  the Leases list's own soonest-to-expire default), status, row count.
- **Filter**: status (parsing/pending_confirm/importing/completed/failed/cancelled), date range.
- **Pagination**: standard page size.
- **Empty state**: distinct copy for "no take-on imports yet" vs "none matching this filter."
- **Scoping**: `rental_take_on_import_runs` `use BelongsToAgency` + `AgencyScope`; direct-URL access
  to another agency's run is a 404, not a hidden link. Permission-gated, agency-admin-only (§8).

**Archive** (soft delete, the run's own `deleted_at`): soft-deletes the run AND soft-archives every
`Lease`/`Property`/`Contact` this batch created (never one it only MATCHED to an existing record —
the row's own `property_match_action`/`landlord_match_json`/`tenant_match_json` `action` entries say
which; for the property specifically this is the REAL confirm-time outcome, re-derived from
`Property::wasRecentlyCreated` at confirm and written back onto the row, not the dry run's earlier
prediction — `promoteToStock()` runs its own second, independent match against the live `properties`
table on top of the dry run's TrackedProperty-only check, so a row the preview called "create" can
still end up reusing an existing Property once confirmed) **that has not been edited since
creation** — compared via `updated_at === created_at` on each created record, the simplest honest
signal that nothing has touched it since the import wrote it. **Known limitation, accepted rather
than engineered around**: these timestamp columns are second-precision, so an edit landing in the
SAME second as the import's own write is indistinguishable from "never touched" and would be archived
anyway. In practice nobody edits a freshly-imported lease within the literal same second it was
created, and the cost of being wrong is small and reversible (Restore brings it straight back) — this
is a documented trade-off, not an oversight. Anything already edited by an agent (outside that one-
second window) is left alone, and the "left alone" set is named in the confirmation screen before the
archive runs ("3 leases have been edited since import and will NOT be archived — archive them
individually from the Leases list if you want them gone too."). **Restore** reverses exactly the same
set this run's own archive action archived (tracked via a `rental_take_on_import_rows.archived_at`
stamp per row at archive time, so restore doesn't have to re-derive "what did I touch" from scratch
and risk sweeping up something unrelated that happened to match the same `migrated_from_*` pointer
after a since-reversed restore).

Per-row downloadable error report (`GET .../runs/{run}/errors.csv`) — every row with a non-empty
`errors_json` or `warnings_json`, in plain language, one line per issue.

---

## 8. Permissions

New key, following the `leases.*` naming convention: `rentals.take_on_import` — agency-admin only
(not every agent — this is a one-time, high-stakes bulk operation per agency, not a day-to-day
action). Gates the sidebar entry, every route, and controller-level `authorize()`.

No new agency **setting** is introduced by this feature (template shape, column mapping, archive
behaviour are all fixed logic, not something an agency configures) — non-negotiable #10a therefore
does not apply; noted here explicitly rather than silently skipped.

---

## 9. Tests (`tests/Feature/Rentals/TakeOnImport/`)

- Parse: happy-path template round-trip (download the generated template, fill it, upload it, assert
  the right number of rows + fields land in `payload_json`).
- Dry-run: property match found vs. not found (via `findExistingMatch`); landlord/tenant duplicate
  found vs. not found; agent/branch resolved vs. unresolved (warning, not error); lease_completeness
  `complete` vs `draft` for each hard-mandatory field individually omitted (BUILD_STANDARD §5 —
  each-empty-path).
- The lazy-but-valid shortcut: a row with only Street name+Suburb, Landlord 1 name, Tenant 1 name,
  Monthly rental, and Lease start date — nothing else — imports successfully as a complete lease with
  no escalation/deposit/notes.
- Malformed input per validated field (bad date format, non-numeric rent) rejected with a plain-
  language row error, not a 500.
- Row transaction rollback: a confirm that throws partway (e.g. simulated property-creation failure)
  leaves no orphaned Contact/Lease for that row.
- Draft-vs-active rule: two rows in the same batch that resolve to the same property — the second
  stays `draft` with the conflict named, the first activates.
- **No-notification guarantee**: `Mail::fake()` + a full confirm run of a batch with real-looking
  email addresses on every landlord/tenant/agent column → `Mail::assertNothingSent()`.
- Batch archive: creates leave-alone records untouched, touched records archived, restore reverses
  exactly that set.
- Agency isolation: a second agency's user cannot see/download/confirm/archive another agency's run
  (direct-URL-by-ID test, not just absent from the menu — BUILD_STANDARD §8).

Test data mirrors real messiness (BUILD_STANDARD §5): real SA cell formats (`082 555 0101`), ID
numbers, mixed-case emails with leading/trailing whitespace, a joint-landlord row, a 4-tenant row, a
month-to-month row with no end date.

---

## 10. Files

**New**
- `database/migrations/2026_10_05_xxxxxx_create_rental_take_on_import_runs_table.php`
- `database/migrations/2026_10_05_xxxxxx_create_rental_take_on_import_rows_table.php`
- `database/migrations/2026_10_05_xxxxxx_add_archived_at_to_rental_take_on_import_rows_table.php`
- `app/Models/RentalTakeOnImportRun.php`, `RentalTakeOnImportRow.php`
- `app/Services/Rentals/TakeOnImport/RentalTakeOnTemplateService.php` (§3)
- `app/Services/Rentals/TakeOnImport/RentalTakeOnRowParser.php` (§5.1)
- `app/Services/Rentals/TakeOnImport/RentalTakeOnDryRunResolver.php` (§5.2)
- `app/Services/Rentals/TakeOnImport/RentalTakeOnConfirmService.php` (§5.3)
- `app/Services/Rentals/TakeOnImport/RentalTakeOnArchiveService.php` (§7)
- `app/Http/Controllers/CoreX/RentalTakeOnImportController.php`
- `resources/views/corex/rentals/take-on-import/index.blade.php` (upload + batch history)
- `resources/views/corex/rentals/take-on-import/preview.blade.php`
- `resources/views/corex/rentals/take-on-import/show.blade.php`
- `tests/Feature/Rentals/TakeOnImport/*`

**Modify**
- `routes/web.php` — new route group
- `resources/views/layouts/corex-sidebar.blade.php` — nav entry under Rentals
- `config/corex-permissions.php` — `rentals.take_on_import`
- `database/schema/mysql-schema.sql` — re-dumped, DEFINER-stripped (non-negotiable #12a)

---

## 11. Out of scope (this landing)

- Saved/reusable column mappings for an arbitrary CRM export (Landing 2).
- Posting arrears/opening balance anywhere (§6).
- Writing a scheduled escalation clause onto `leases` (§6).
- Importing historical inspection records (§6).
- Any notification/invite to tenants or landlords (§5.3 — guaranteed absent, not just unbuilt).
