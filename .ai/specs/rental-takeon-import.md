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

## 6. What this import writes, shows, and still deliberately does NOT build

**Amended 2026-10-08 (Johan, overnight queue item 1) — escalation %/next date, opening arrears, and
last inspection date are now stored on the `Lease` AND shown, read-only, on the Lease Hub screen.**
Originally these three were left stranded in the row's own `payload_json` (see the superseded
reasoning kept below) — Johan's ruling closes that gap without building either of the two heavier
features this spec always said were genuinely out of scope (a scheduled-escalation feature, a rental
ledger):

- **New columns on `leases`** (migration `2026_10_08_100000_add_takeon_fields_to_leases_table`):
  `migrated_escalation_percent` (decimal 6,2, nullable), `migrated_next_escalation_date` (date,
  nullable), `migrated_opening_arrears` (decimal 12,2, nullable), `migrated_last_inspection_date`
  (date, nullable). Named with the SAME `migrated_` prefix as the pre-existing
  `migrated_from_table`/`migrated_from_id` — these are historical facts about one migrated-in
  tenancy, not a general capability every lease gets, and are never written by any other flow (no
  edit form exposes them, no calculation reads them).
- **`RentalTakeOnConfirmService::confirmRow()`** now copies `escalation_percent`/
  `next_escalation_date`/`arrears_opening_balance`/`last_inspection_date` from the row's own payload
  straight onto these four columns at `Lease::create()` time — the data was already being parsed and
  validated (§5.2); this is wiring, not new capture.
- **`resources/views/corex/leases/show.blade.php`** (the Lease Hub's "Lease terms" card) shows an "As
  captured at take-on" block — read-only text, no edit control anywhere — **only when at least one of
  the four was actually captured** (BUILD_STANDARD §2, optional-and-empty never renders a bare label).
  The arrears figure is captioned "(note only — not posted to any ledger)" directly in the UI, so an
  agent reading the screen never mistakes it for a real trust-account balance — there is still no
  ledger to post it to, and none is built here.
- **Still correctly out of scope, unchanged from the original reasoning**: `lease_escalations`
  (append-only record of escalation events that have ALREADY happened,
  `previous_rental_amount`/`new_rental_amount` both required — leases.md §3.4) is never written from
  these four columns — a take-on's "next escalation" is a future, not-yet-happened event, and writing
  it there would mean fabricating a previous/new amount pair that never occurred. These four columns
  are a narrower, honest answer: display-only facts, not a scheduled-escalation feature. Likewise no
  `rental_inspections` row is created from `migrated_last_inspection_date` (`rental_inspections.lease_id`
  is a required FK, leases.md §9.1 — it cannot exist before the Lease row does, and a historical-
  inspection-record importer was never asked for).

**Superseded reasoning, kept for the record (why these were originally payload-only):** `payload_json`
-only storage satisfied "captured and stored" literally, but the original build correctly flagged
this as half-finished — a fact typed into a spreadsheet and never surfaced again is not useful to the
agent who has to act on it. Johan's ruling (above) is the completion of that flagged gap, not a reversal
of the reasoning that originally kept the heavier features out of scope.

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
confirm** — compared via `updated_at <= row.confirmed_at`, not `created_at`. `created_at` was tried
first and rejected during build: `RentalTakeOnConfirmService`'s own pipeline makes at least one
further `save()` on a Lease AFTER creating it (`LeaseActivationService::activate()` flips its status;
activation may also `save()` the Property) — both confirm's own normal work, not an agent's edit —
so `updated_at === created_at` could read a just-imported, untouched lease as "edited" purely because
those two writes landed in different seconds (second-precision columns), found for real during this
build's own test run. `row.confirmed_at` is stamped LAST, strictly after every write confirm itself
makes, so comparing against it instead is correct regardless of where a second boundary falls — only
a genuinely later edit (an agent's, after confirm finished) can land after it. **A property that
still has a blocking active lease is also always left alone**, not just one that fails the timing
check — `PropertyObserver::deleting()` (a guard that landed independently of this feature) refuses to
soft-delete any Property with an active lease, so this is checked explicitly before attempting the
delete rather than letting that exception escape and roll back the whole batch's archive. Anything
already edited by an agent is left alone, and the "left alone" set is named in the confirmation screen
before the archive runs ("3 leases have been edited since import and will NOT be archived — archive
them individually from the Leases list if you want them gone too."). **Restore** reverses exactly the
same
set this run's own archive action archived (tracked via a `rental_take_on_import_rows.archived_at`
stamp per row at archive time, so restore doesn't have to re-derive "what did I touch" from scratch
and risk sweeping up something unrelated that happened to match the same `migrated_from_*` pointer
after a since-reversed restore).

Per-row downloadable error report (`GET .../runs/{run}/errors.csv`) — every row with a non-empty
`errors_json` or `warnings_json`, in plain language, one line per issue.

---

## 8. Permissions

Two keys, module `rentals_take_on_import` (`config/corex-permissions.php:177-178`): `rentals_take_on_import.view`
(batch list, review screen, issues download) and `rentals_take_on_import.manage` (upload, map columns, confirm,
exclude, cancel, archive/restore, saved mappings). Agency-admin only by default (not every agent — a one-time,
high-stakes bulk operation per agency). They gate the sidebar entry, every route (`routes/web.php:4038-4073`) and,
through `RentalTakeOnImportRun::scopeVisibleTo`, the own/branch/agency level. The whole feature also sits behind the
agency feature toggle `rental-take-on-import` (`config/corex-features.php:588`).
*(Corrected 2026-10-21, AT-479: this section used to say the key was `rentals.take_on_import`. That key never
existed; the two above are what the code has always used.)*

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

## Audit fix 2026-10-18 (A-M2)
`rental_take_on_import_rows` now carries its own `agency_id` (`BelongsToAgency`; migration `2026_11_16_100000_add_agency_id_to_rental_take_on_import_rows`, backfilled from the parent run). Every run and row action in `RentalTakeOnImportController` (show, preview, issues CSV, map/confirm mapping, confirm-bulk, confirm/exclude row, cancel, archive, restore) first checks the parent run with `RentalTakeOnImportRun::visibleTo($user)` (own/branch/agency) and answers 404 otherwise, before any read or write. Tests: `RentalTakeOnImportHttpTest`.

---

# reOS Tenancy Schedule import (AT-479)

**Status:** Spec — approved for build (Johan, via conductor, 2026-10-21). Lands on QA1 only.
**Pillars:** Property (match-or-create via `TrackedPropertyMatchOrCreateService`), Contact (owner + tenant,
de-duped through `ContactDuplicateService`), Deal: not touched, Agent (`User`, matched by name, or picked).
**Investigation this follows:** the 2026-10-21 report on the existing take-on import (in the conversation; the
file references below are the exact ones it cited).

## R0. Why, in one sentence

An agency moving from reOS exports its "Tenancy Schedule" (and optionally its "Rent Roll"); CoreX brings the
leases in as a reviewed batch — every property, owner and tenant matched against what CoreX already knows, the user
choosing exactly what is imported — and a second upload of a newer export updates the leases it already brought
in instead of duplicating them. **No email, WhatsApp or notification is sent on any path of this feature.**

## R1. Import type

`rental_take_on_import_runs.source` (varchar 30, NOT NULL, default `corex_template`): `corex_template` (everything
that exists today — unchanged) or `reos_tenancy_schedule`. Constants on `RentalTakeOnImportRun`. Every existing run
reads as `corex_template`. The reOS path never goes through the column-mapping screen: a file is either exactly the
reOS layout or it is rejected with a plain message.

`/corex/rentals/take-on-import` (`index.blade.php`) gets a second card beside the existing upload:
"reOS Tenancy Schedule import" — a Tenancy Schedule file (required) and a Rent Roll file (optional) in one form.
Same permissions (`rentals_take_on_import.view` / `.manage`), same `visibleTo` scoping, same feature toggle, same
batch list (a "Type" column is added so the two kinds are told apart). No new menu entry (the page is the entry).

## R2. Recognising and reading the files

Both files are `.xlsx` (the CSV path is not offered for reOS — the Lease ID is a formula cell). Read with OpenSpout
(`SHOULD_PRESERVE_EMPTY_ROWS = true` so a row's index is its real spreadsheet row number).

**The header row is FOUND, not assumed** (corrected 21 Oct 2026 after Johan's real files: the Rent Roll has a "Date Range"
line, so its headers are on row 5, the Accounts Receivable report has them on row 3). Both parsers scan the first **15 rows**
of the sheet for the row that holds every expected header (compared normalised) and read the data from the row below it;
blank rows and any metadata lines above it (title, "Compiled", "Date Range", …) are ignored. The columns are found by their
heading too, so extra columns, a shifted first column or a different order do not matter. A file is refused only when no row
in the first 15 holds the full header set, with a message that names the sheet or the expected headers. The row number quoted
on the review screen is always the real spreadsheet row.

**Tenancy Schedule** — sheet named exactly `Tenancy Schedule` (case/space-insensitive); the 21 headers below; fully blank
rows skipped. Anything else is rejected before a run exists with a message that says what was found ("The sheet 'Tenancy
Schedule' was not found — this file has: X, Y." / "The header row … was not found in the first 15 rows … It should have
these columns: …").

Lease ID | Property Address | Owner | Owner Email | Owner Phone | Tenant | Tenant Email | Tenant Phone |
Lease Type | Lease Status | Lease Start | Lease End | Rollover | Deposit Required | Current Rent | Fixed Invoices |
Variable Invoices | Current Commission (including VAT) | Current Commission (excluding VAT) | Agent | Lease Segment

**Rent Roll (optional)** — sheet `Rent Roll`. Only these six columns are looked for: Property Address, Agent, Owner, Tenant, Tenant Wallet, Lease End. **Only Tenant Wallet is used**;
every money column is ignored (not read, not kept).

**Lease ID cell** is the formula `=HYPERLINK("https://app.reos.co.za/leases/<uuid>", "<uuid>")`. OpenSpout returns a
formula cell's formula text (`Row::toArray()` → `FormulaCell::getValue()`), not the cached value — proven in
`ReosTenancyScheduleParserTest` on a synthetic workbook, not assumed. Argument separator `,` or `;` both accepted.
The uuid and the URL are extracted (uuid = the `…/leases/<uuid>` tail of the URL, checked against the friendly-text
argument when both are present; lower-cased). A cell holding only a bare uuid is accepted for matching and keeps no
URL (CoreX does not invent a link). A row with no readable Lease ID is an error row (it could never be matched on a
re-import).

Cell parsing: dates `YYYY-MM-DD` strings (Excel date cells also tolerated, via the existing
`RentalTakeOnRowParser::parseDate`); Rollover `"True"/"False"` (also real booleans, 1/0, yes/no); money as decimals
(`RentalTakeOnRowParser::parseNumber`: absorbs `R`, spaces, thousands commas). Commission cells (`"759.00"` or
`"0.00%"`) are parsed so a malformed one is noticed, then **dropped — never stored** (R7).

Lease Status → CoreX: `Active`, `Expiring` → activate; `Draft` → stays draft. Any other value is an error row
("Lease status 'X' is not one CoreX imports (Active, Expiring, Draft)") — prevent, not guess.

## R3. Staging: what is stored on a run and its rows

Run: `source`, `rent_roll_filename` (nullable). `counts_json` gains rent-roll matched/unmatched counts and the
import result counts (created / updated / failed).

Row (`rental_take_on_import_rows`, all new columns nullable except as noted): `external_id` (reOS uuid, indexed),
`external_url`, `existing_lease_id` (the lease already in CoreX with this reOS id, withTrashed), `selected` (bool,
NOT NULL default 0 — the user's tick), `decisions_json` (the user's choices, R4), `blockers_json` + `is_blocked`
(bool, NOT NULL default 0 — computed, R5), `import_error` (text — why the last import attempt of this row failed),
`result_action` (`created` | `updated`). `payload_json` holds the parsed values: reOS id, address text, owner /
tenant name + email + phone, lease type, status, dates, rollover, deposit, rent, agent text, `tenant_wallet_ref`.
The existing columns `property_match_*`, `landlord_match_json` (position 1 = the owner), `tenant_match_json`
(position 1 = the tenant), `target_*`, `errors_json`, `warnings_json` are reused. **The uploaded files are not kept**
(unlike the CoreX-template import): they are read straight from PHP's temporary upload and discarded, so the money
columns they carry (commission, invoices, the Rent Roll's amounts) are genuinely nowhere in CoreX. `source_file_path`
is therefore empty on a reOS run; only the file names are recorded.

## R4. The review screen (the existing preview, extended for reOS runs)

`preview` renders `preview-reos.blade.php` when `run.source = reos_tenancy_schedule`; the existing view is untouched
for `corex_template`. Plain JavaScript, no Alpine (the existing preview's rule).

**Per row it shows**, matched vs not-in-CoreX for property, owner and tenant; and whether the lease already exists
by reOS id ("In CoreX already — will be updated", never created twice; an *archived* lease with that id is a blocker
that says to restore it first, because the database key spans archived rows).

**What the user decides, per row (stored in `decisions_json`, saved the moment it changes):**
- tick the lease to import (`selected`; default: none ticked);
- property not matched → "Create this property" (default ticked) + **suburb** (required to create);
- owner / tenant not matched → "Create this contact" (default ticked), or **link an existing contact** (search);
  a matched contact is linked by default;
- **agent** (default: the name match, R4.2);
- tenant wallet reference (pre-filled from the Rent Roll; editable).

**R4.0b Review-screen corrections after Johan's real-file check (21 Oct 2026, batch 6).**
1. *Sticky header* now has a solid background and `z-index: 50` (above every row control, whose result lists are z-20): rows scroll
   under it.
2. *A company matched to a company is a company match.* A CoreX contact counts as a **company** when it is a company record
   (`contact_kind = entity`) **or its name reads as a company** (`ReosContactNames::isCompanyContact` — CoreX holds 10,706 "natural
   person" records on QA1, many named "… Pty Ltd", e.g. "BEBO Investments (PTY) LTY"). Such a match is never "matched a person" and the
   one-click is never offered for it; an entity-like contact is refused as somebody's "person" (decision API and import). Same for tenants.
3. *Name differs is split in two* (`ReosNameCompare`): **Spelling differs** (muted grey, NOT in the amber total) = same surname once
   squashed ("Mc Neil" = "McNeil"), a middle name on one side only, or an edit distance ≤ 2 on first name or surname (only for words
   of 4+ letters, so Ben/Ken stay different) — "Mkhozi/Mkozi", "Desire/Desiree"; and **Name differs** (amber, counted) = everything
   else, plus "matched a person" for a company. Filter options for both; the header shows the amber count and a separate muted
   "Spelling" count. `decisions_json.name_differs` (amber) and `.spelling_differs`.
4. *The row grid fits the page without a horizontal scroll* (checked at 1523 px): `table-layout: fixed`, six percentage columns
   (3/14/23/19/19/22), controls capped to their column, and the "to fix / to know" notes sit under the agent instead of in a
   seventh column.

**R4.0a Contact matched under a different name (Johan, 21 Oct 2026).** A contact matched on phone / e-mail can be someone
else (a shared number, a re-used e-mail, a company whose phone is its contact person's). When the matched CoreX contact's
name does not match the reOS name — compared normalised: case, spacing, punctuation, "&"/"and" and Pty/Ltd/Limited ignored,
an initial = a full first name ("A Smith" = "Andre Smith"), and every word of the shorter name must be found in the longer so a
joint name matching one spouse or a missing middle name is not noise (`ReosNameCompare`) — the cell shows an amber
**"Name differs — CoreX has “X”, reOS has “Y”. Check it is the same person."** When the reOS name is a company and the CoreX
contact is a person it shows **"Matched a person (likely the company's contact person)"** instead. "Use the contact already in
CoreX" stays the default; the flag is a warning, never a blocker, and it clears when the user creates a new contact, leaves
the person out or links another contact by hand. The review list has a **Contact names → Name differs** filter and the header
a "Name differs: n" count. Stored: `name_flag` / `matched_name` on the owner/tenant match, and `decisions_json.name_differs`.

**R4.0 Does the property exist? — no suburb needed (Johan, 21 Oct 2026).** Step 1 of every row. `ReosPropertyCandidates`
looks across ALL suburbs of the agency's own (non-archived) properties on **street name + street number**, plus **unit /
complex when the reOS address has them**, all normalised: case and punctuation ignored, "Ave" = "Avenue", and **"504/3
Robin Road" = unit 504, no. 3, Robin Road** (whether CoreX holds it as unit 504 + no. 3 or as "504/3" in the number).
Conservative rules: no street number in the reOS address = no lookup (a street alone is not a property); a unit in the reOS
address needs the same unit in CoreX; an address with no unit does not match a CoreX flat in a block; a different complex
rules a property out (a CoreX property that simply holds no complex name is not ruled out). Other agencies' and archived
properties never match.
- **Exactly one** → "In CoreX": the row shows the property with its suburb (read from the property); no suburb picker, nothing
  created; Import puts the lease on THAT property and does not change it.
- **Several** → the row says "Pick the property" and lists each (address + suburb) plus **"None of these — create a new
  property"**; until one is chosen the row is blocked (`property_choice_required`, linked to the control). A pick must be one
  of the listed properties (anything else is refused). "None of these" goes to the suburb-picker route below, and a later
  suburb pick does not undo it.
- **None** → "Not in CoreX" + the suburb picker (R4.1), exactly as before; once a suburb is picked CoreX's general matcher
  looks again (it needs the suburb) and may still find a tracked property.
Stored in `decisions_json.property`: `candidates`, `link_id`, `choice` (`link` | `new`); `property_match_action` is `match`,
`choose` or `create`. The Import path never creates or re-matches a property whose `link_id` is set, so it cannot duplicate.
The same unit/number reading is used when a NEW property is created ("504/3 Robin Road" is created as unit 504, no. 3).

**R4.1 Suburb.** Only for a property CoreX does *not* match. A type-ahead over the existing P24 suburb list
(`p24_suburbs` where `p24_verified_at` is set, the same source as `/api/v1/p24/suburbs`; the existing endpoint needs
a city, so a thin search endpoint over the same tables is added). Picking a suburb fills town (the P24 city) and
province automatically and records `p24_suburb_id` / `p24_city_id` / `p24_province_id`. "Apply suburb to all
selected" sets it on every ticked row that still needs one. A matched property needs no suburb.

**R4.2 Agent.** `agent` text such as `Retha Kelly (0%),Home Finders Coastal (100%)`: split on commas, strip the
`(n%)`, drop entries equal to the agency's own name (agency display name / trade name — never a hard-coded string:
non-negotiable #9), take the **first person** left and match it to an active user of the agency by normalised name
(exact normalised name, else every token present). No match, or only the agency named → the user picks (people on
the existing `LeaseAgentService::selectableAgents` list, minus system-owner accounts, who cannot be a property
agent). "Apply agent to all selected". The chosen agent becomes the lease's owner's agent **and** tenant's agent
(editable afterwards on the lease). The split percentages are not stored.

**R5. Blockers** (computed on every change; stored in `blockers_json`; each carries the id of the control that
fixes it, and the message is a link to it):
1. parse errors (bad/missing rent, start date, Lease ID, unknown status) — fixed in the file, no control;
2. property unmatched, "create" ticked, no suburb → the suburb control;
3. property unmatched and "create" not ticked (a lease needs a property) → the create tick;
4. no agent chosen → the agent control;
5. archived lease with this reOS id → none (restore it from the Leases list).
A row whose lease already exists (the update path) is blocked only by 1 and 5 — an update touches terms only (R6).
`is_blocked` = any blocker. Unmatched owner/tenant with "create" unticked is *not* a blocker (the lease is imported
without that person, and says so).

**Sticky header** — always visible: the run name, live counts (**selected**, **to create** — properties and
contacts, **to update**, **blocked** = selected rows with a blocker), the **next action** in a sentence ("Tick the
leases you want to import" / "Fix 3 blocked rows" / "Ready — import 12 leases"), and the **Import** button (enabled
only when ≥1 row is ticked and none of the ticked rows is blocked). The counts come from the server after each
change, so they are the truth, not a guess.

**List standard (BUILD_STANDARD §1b):**
- *Search* (one box): address text, owner name, tenant name, agent text, reOS lease id.
- *Sort*: sheet row (**default, ascending**), address, owner, tenant, status, rent.
- *Filter*: match (all / property matched / property not in CoreX), readiness (all / blocked / ready / ticked /
  not ticked), lease status (Active / Expiring / Draft), import state (waiting / imported / failed / already in
  CoreX), plus the existing run-level date range on the batch list.
- *Pagination*: 25 per page; choices survive paging (they are saved, not in the page). Bulk actions act on the whole
  run, not just the visible page ("Tick all ready", "Untick all", "Apply suburb/agent to all selected").
- *Empty state*: distinct copy for "this file had no rows" vs "no rows match these filters".

**R4.3 Scoping, per screen.** List, review, JSON endpoints, import, issues download all begin with
`RentalTakeOnImportRun::visibleTo($user)` on the parent run (own / branch / agency by the role's
`rentals_take_on_import` scope) and answer **404** otherwise — by direct URL as well as by menu; rows are also
`BelongsToAgency`. Contact search and suburb search are agency-scoped.

**JSON endpoints** live under `/api/v1/rentals/take-on-import/…`, named `api.v1.rentals.take-on-import.*`, so the
Admin → API catalogue lists them (non-negotiable #7): row decisions (`POST rows/{row}/decisions`), bulk actions
(`POST runs/{run}/bulk`), run summary (`GET runs/{run}/summary`), suburb search, contact search. Registered in
`routes/web.php` next to the existing `/api/v1/p24/*` ones (they need the session `web` group). All need
`rentals_take_on_import.manage`. The Import itself is an ordinary form POST + redirect.

## R6. Import (confirm) — `ReosTenancyConfirmService`

Imports every ticked, unblocked, not-yet-imported row, **each row in its own transaction**; a failure rolls that row
back clean and is written to the row's `import_error` (plain language), the other rows carry on. The request works
in chunks (≈20 rows or ≈25 s) and the page continues itself, so a 300-row book cannot hit a request time limit.
Re-running Import retries failed rows. The result banner reports **created**, **updated** and **failed** separately.

**Contacts.** For every owner/tenant to be created: first re-match **now** with `ContactDuplicateService::
findDuplicatesForIdentifiers` (phones/emails; picks up anything created since the preview, including earlier rows of
this batch), then — only when there is no identifier to match on, or none matched — reuse a contact an earlier row of
this *same batch* created for the same normalised name (and no conflicting email/phone). So an owner on five leases
is **one** contact linked to all five. Contacts the user linked by hand are used as chosen.
- *Company names* — a name containing (whole word, case-insensitive) `Pty`, `(Pty)`, `Ltd`, `Limited`, `CC`, `Trust`,
  `Inc`, `NPC`, `Investments`, `Holdings`, `Church` or `JV` becomes an **entity** contact
  (`contact_kind = entity`, `entity_name` = the name, `first_name` = the name, `last_name` = '' — the exact shape of
  `ComposeSellerService::resolveOrCreateEntitySellerContact`). **Checked: the entity path does not require a
  registration number** (`entity_reg_no` is `nullable` in every validator and in the model), so none is needed.
- *Joint names* — `X & Y Surname`, `X and Y Surname` (not a company) → **one** contact, full name kept: last word =
  surname, the rest = first name(s) ("William & Dorothy" / "Geyser"). Never split on the first space.
- *Everyone else* — first word / rest, as the existing take-on does.
- Email/phone cells holding several values (`a@x.test; b@y.test`) → the first valid value is the contact's own;
  all are used for matching.
- Owner → linked to the property as `landlord` (`ContactPropertyLinker`); tenant → `lease_tenants`.

**R6a. People for a company owner / tenant (Johan, 21 Oct 2026).** A company contact is linked to its natural people through
the **existing** relation `contact_representatives` (`Contact::representatives()` / `representedEntities()`,
`app/Models/Contact.php:942-975`; pivot model `ContactRepresentative`, whose role vocabulary is
`ContactRepresentative::CAPACITIES` = Director, Executor, Trustee, Member, Other; written today by
`ContactRepresentativeController`, `:86-150` and `attachRepresentative` `:235-282`). No new table. *(Correction to the
brief's premise: FICA TFS screening does **not** use this relation — `TfsScreeningService::` `:237-246` screens a company by its
name only, from the FICA form's own data; the directors/beneficial owners there are typed on the FICA form.)* The import reuses
the relation and ONE new write helper, `App\Services\Contacts\ContactRepresentativeLinker`, which applies the same rules as
`attachRepresentative` (restore-a-soft-deleted-link rather than re-insert, one primary / one proxy, new person goes to the end of
the list) so the reOS import does not depend on a private controller method; the controller itself is unchanged.
- **On the review screen**, every owner or tenant that is, or will be, a company contact (reOS name is a company and the choice is
  "create", or the row links an entity contact) gets **"People for this company"**: the people already linked in CoreX (read from
  `Contact::representatives()`), the people added for this import (each with a role selector and *remove*), and **Add person**:
  search existing contacts (a natural person only) or type a new one — first name, last name, phone, e-mail, role (the existing
  capacity list; default Director). The choices are saved as `decisions_json[owner|tenant].people[]`.
- **One click for a company that matched a person** (`Wildevy Properties Pty Ltd` → Arne Nel by shared phone): the cell offers
  **"Create the company and link Arne Nel as its contact person"**. It switches the party to "create a new contact" and adds
  that person (reused, never duplicated) with the role **Other** — CoreX has no "contact person" capacity, and Johan's
  instruction was to use the existing list; "Other" is its free-form entry. The "Name differs" warning is settled by it.
- **Import**, in the same per-row transaction as the lease: create or link the company; for each person, an existing one is used
  as chosen and a new one is looked for first by phone / e-mail (a natural person only), then by the same name already brought
  in by this batch, and only then created (`contact_kind = natural_person`); then each company↔person link is written. So **one
  person on two companies in one batch is one contact with two links**. A company is never "satisfied" at import by a person who
  merely shares its phone / e-mail (the old re-match would have reused Arne as the owner). Nothing is sent to anyone.
- **Guards:** people can only be added to a company party (a person party, a left-out party, or a link to a non-company contact
  refuses it, and changing a party so it is no longer a company drops its people); the role must be in the list; an entity is
  refused as a "person"; at most 10 people per company here. A lease that is being UPDATED (re-import) touches terms only — no people.
- **Archive:** the people this import created are recorded (`decisions_json.created_people`) and archived / restored with the
  batch (if unedited since), like the owners and tenants it creates; people it only reused are left alone.

**Property.** Facts from `AddressParser` over the address text (street number, street name, unit, complex) +
the chosen suburb/town/province → `matchOrCreate` then `promoteToStock(listing_type = rental, branch, agent)`. The
Universal Match-or-Create Rule holds. **Imported properties skip the new-listing automation and activity points**:
a new opt-in switch on `Property` (`Property::withoutNewListingSideEffects(callable)`) makes the `creating` hook set
the existing `skipNewListingAutomation` flag (no `AutoEventService::onPropertyCreated` chase tasks) and a new
`skipActivityPoints` flag that `PropertyObserver::created()` honours (no `PropertyCaptured` event → no points). The
switch is off unless a caller opts in, so every other path is unchanged. `promoteToStock` creates the property
internally, which is why an ambient switch is needed rather than a flag on the instance.

**Lease — create.** `status` draft; `rental_amount`, `deposit_amount`, `start_date`, `end_date`,
`is_month_to_month` (= no end date), `lease_type` = the reOS Lease Type text as given, `rollover`,
`tenant_wallet_ref`, `external_source = reos`, `external_id`, `external_url`, `source = migrated_takeon`
(`migrated_from_*` as the existing import), owner's/tenant's agent = the chosen agent. Status `Active`/`Expiring` →
activated through `LeaseActivationService::activate` (a conflict — another active lease on the property — leaves it
draft with the reason on the row, as today). `Draft` → **never activated**.

**Lease — update (re-import).** Found by `agency_id + external_source + external_id` (archived rows included).
Updates only what reOS owns: rent, deposit, end date, month-to-month, lease type, rollover, tenant wallet ref, the
reOS link, and the agents *only if the user chose one*. It does **not** touch the property, the people, or the
status — except a CoreX *draft* whose reOS status is now Active/Expiring is activated. Every changed field is written
to the lease history (`LeaseEvent::TYPE_LEASE_EDITED`, who/what/old→new). A lease out for signing is skipped with a
message (its terms are governed by the agreement). The row records `result_action = updated`.

**No mail, no notification.** Nothing in the path mails, notifies, or invites anyone; tested with `Mail::fake()` /
`Notification::fake()` + `assertNothingSent`. Known and unchanged: creating a *contact* still fires the existing
`ContactCreated` event, which credits "contact captured" activity points to the importing user — the same as the
existing take-on; reported to the conductor, not changed here (out of scope).

## R7. New lease fields (migration `…_add_reos_and_wallet_fields_to_leases_table`)

`external_source` varchar(30) null, `external_id` varchar(64) null, `external_url` varchar(500) null,
`rollover` boolean null (null = not known — every non-reOS lease), `tenant_wallet_ref` varchar(100) null,
`owner_wallet_ref` varchar(100) null. **Unique** `(agency_id, external_source, external_id)` (MySQL allows many
NULL pairs; archived rows are included by the database, which is why the import looks with trashed).

- **Edit form** (`show.blade.php` edit panel + `LeaseController::update`): all six editable; `external_id` needs a
  source; the reOS id is validated unique per agency+source (plain message, never a SQL error); `external_url` must
  be a URL.
- **Lease view** ("Lease terms" card): an "Imported from reOS" block — "Open in reOS" link when `external_url` is set,
  rollover Yes/No, tenant and owner wallet references; nothing is printed for an empty field.
- **Portals** (`RentalPortalOverviewService::home()` → `pay_reference`; `rentals/portal/_home.blade.php`): the tenant's
  Home shows the **tenant wallet**, the owner's Home shows the **owner wallet**, as
  **"Pay via reOS — your payment reference: <ref>"**. Nothing at all when the field is empty. The owner wallet is
  typed in by hand (no reOS report carries it); the tenant wallet comes from the Rent Roll when supplied, else by hand.

**Not stored — stays in reOS (Johan's money-side ruling):** commission incl/excl VAT, fixed and variable invoices,
lease segment, agent split %. No columns, no fields, no screens. (The Rent Roll's money columns likewise.)

## R8. Rent Roll merge

Matched to a schedule row on **normalised property address + normalised tenant name** (lower-case, punctuation and
spacing removed; tenant words sorted so `A & B Smith` = `B and A Smith`). Exactly one Rent Roll row must match: no
match → the schedule row simply has no wallet; two rows match → ignored with a warning on the schedule row (never
guessed). A wallet found is written into the row's `tenant_wallet_ref` (shown, editable). The run records how many
Rent Roll rows matched and how many did not.

## R9. Archive / restore of a reOS batch

The existing archive service is reused, with one addition: a row whose `result_action` is `updated` is left alone
(the lease existed before this batch; archiving the batch must not archive it). Contacts/properties are archived
only when the row's stored outcome says this batch created them (the confirm writes the real outcome back, as the
existing confirm does for the property).

## R10. Input space (BUILD_STANDARD §2/§3) — prevent or absorb

| Input | Decision |
|---|---|
| wrong sheet name / header row / column order | **prevent** — rejected before a run exists, message names what was found |
| Lease ID: formula (`,` or `;`), bare uuid, empty, garbage | formula + bare uuid accepted; empty/garbage → error row |
| Rent 0 / negative / non-numeric / `R 12 500` | `R 12 500` absorbed; non-numeric, ≤ 0 → error row |
| Start date missing/malformed | error row; End date missing → month-to-month (absorbed) |
| Rollover anything but true/false/1/0/yes/no | absorbed as "not known" with a warning |
| Owner/tenant blank | absorbed — lease imported without that person, flagged |
| Phone `+27815284719`, `+27 632941505`, blank; e-mail with spaces/caps | absorbed (`ContactDuplicateService` normalisers) |
| Address without a parseable street or number | no property lookup; the suburb/create controls still work; a row the parser calls unreadable shows its note |
| Several CoreX properties fit one address | the user picks one or "none of these" (blocker until they do) |
| Agent text: only the agency, empty, unknown person | user picks (blocker until they do) |
| Same reOS id twice in one file | the second row is an error row ("appears twice in this file") |
| Re-upload of the same file | rows show "In CoreX already — will update" |
| Archived lease with the same reOS id | blocker (restore it first) |
| Row fails mid-import | that row rolls back; `import_error` set; others continue; retry works |
| Two users editing one run | last write wins per field (decisions are per-field merges) |

## R11. Tests (`tests/Feature/Rentals/TakeOnImport/Reos/`) — synthetic data only

Names "ZZ TEST …", e-mails `@example.test`, phones in the real SA shapes; **no real client data anywhere**.
Parser (formula cell with `,` and `;`, bare uuid, layout rejection, row numbers, dropped money columns, bad
status); name classifier; agent matcher; Rent Roll merge (match / none / ambiguous); resolver (matched vs unmatched,
existing lease by reOS id, archived-lease blocker, suburb needed only for unmatched); confirm (batch de-dupe of one
owner across rows, company + joint names, Draft stays draft and Active activates, re-import updates and does not
duplicate, failure rolls back, wallet fill); no mail/notification; no chore tasks / activity points for imported
properties; lease edit form fields; portal `pay_reference`; scoping (another agency's / an own-scope colleague's run
→ 404).

## R12. Settings / wizard (non-negotiable #10a)

No new *agency setting* is introduced (the new lease fields are record fields, not configuration), so the Setup
Wizard is not touched — noted here so it is a decision on the record, not an omission. Multi-agency (#9): nothing
here names an agency; the agency's own name is read from the agency record when the agent text is parsed.

## R13. Files

**New:** migrations (import columns; lease columns), `app/Services/Rentals/TakeOnImport/Reos/` (`ReosTenancyScheduleParser`,
`ReosRentRollParser`, `ReosLeaseIdCell`, `ReosRowNormaliser`, `ReosContactNames`, `ReosAgentMatcher`,
`ReosRentRollMerger`, `ReosRowResolver`, `ReosBlockers`, `ReosTenancyConfirmService`), `ReosTakeOnController` (upload,
review, decisions, bulk, import) + `ReosTakeOnApiController` (JSON), `preview-reos.blade.php`, tests.
**Modified:** `RentalTakeOnImportRun`/`Row` models, `RentalTakeOnImportController` (preview switch, source),
`index.blade.php`, `show.blade.php`, `RentalTakeOnArchiveService` (R9), `Property` + `PropertyObserver` (R6 switch),
`Lease` model, `LeaseController::update`, lease `show.blade.php`, `RentalPortalOverviewService`, portal `_home`,
`routes/web.php`, `database/schema/mysql-schema.sql` (regenerated with `scripts/schema-dump.sh`).
