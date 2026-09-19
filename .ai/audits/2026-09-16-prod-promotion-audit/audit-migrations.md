# Migration audit — Prod promotion 6545f0262 → 57407d5a5

Scope: the 92 migrations added between the previous Prod tip `6545f0262` and the new Prod tip `57407d5a5`
(`git diff --name-status 6545f0262 57407d5a5 -- database/migrations`, all 92 are `A`).
Read-only audit; every migration file was read in full. Verified against the actual model code, the
committed schema snapshot, the old-tip migration history, `config/corex-permissions.php`,
`SyncPermissions`, `SyncReferenceData`, `PermissionService` and `AgencyScope`/`BelongsToAgency`.

Repo: `C:\Users\USER-PC\Documents\Projects\hfc-dash` (branch `Prod`). Laravel `v12.51.0` (composer.lock:2501) —
native `->change()`, no doctrine/dbal needed.

## Verdict

**No BLOCKER. No HIGH.** The set is safe to run with `php artisan migrate --force` on the prod database
as described (real data in `properties`, `contacts`, `contact_matches`, `contact_property`,
`agency_contact_settings`, `role_permissions`, `roles`, `document_types`, `contact_types`, `portal_leads`,
`deals`, `users`, `agencies`; every `rental_application*`, `contact_match_share*`,
`contact_match_reassignments`, `contact_match_link_opens`, `deal_properties` table brand new).

Key reasons, each confirmed by reading code:

- Every operation on a **pre-existing prod table** is additive (ADD COLUMN nullable/defaulted, ADD INDEX,
  INSERT-if-missing, UPDATE of newly-added NULL columns). The only `dropColumn`/`DROP TABLE` calls in any
  `up()` target columns/tables that are themselves created earlier *in this same promotion*
  (`agencies.rental_application_authoriser_user_ids`, `rental_application_qualifying_settings.income_to_rent_multiplier`,
  `rental_application_assessments.monthly_*`, `rental_application_mark_color_settings`), so nothing that
  exists on prod today is dropped.
- No `AgencyScope` leak inside migrations: `AgencyScope::applyInner()` returns immediately when
  `Auth::user()` is null (app/Models/Scopes/AgencyScope.php:57-60), which is the case under
  `php artisan migrate`. `BelongsToAgency::creating` trusts an explicit `agency_id` when there is no auth
  user (app/Models/Concerns/BelongsToAgency.php:70-74). Every model-based migration passes `agency_id`
  explicitly or uses `withoutGlobalScopes()`/`DB::table()`.
- The ordering class fixed in `a0c8ff912` (a migration calling live model code that writes a column a
  later migration adds) has no remaining instance — checked every model call inside every migration
  against the column set that exists at that migration's position (details in §1).
- All 92 migration names are present in `database/schema/mysql-schema.sql`; `DEFINER` count is 0.

Findings below are MEDIUM/LOW: partial-failure re-run hazards on multi-DDL migrations (MySQL DDL is
non-transactional), one silent no-op inside a backfill, two business-visible grant decisions to have Johan
confirm, and a few observations.

---

## Findings ranked by severity

### MEDIUM

**M1 — `2026_09_10_100000_grant_buyer_pipeline_view_alongside_core_matches_view.php:27-43` — writes
`buyer_pipeline.view` with `scope='all'` for every (agency, role) that currently holds `core_matches.view`,
including `agent`/`viewer` roles whose config default (`scope_defaults`, config/corex-permissions.php:1142-1148)
is `own`/`branch`.** CONFIRMED. On prod this means every role that can see Core Matches gets the new
Rentals → Rental Pipeline nav entry and page (routes/web.php:3994 gates on `permission:buyer_pipeline.view`;
sidebar corex-sidebar.blade.php:1126). The `'all'` scope value itself is inert — no runtime consumer calls
`getDataScope($user, 'buyer_pipeline')` (grep of `app/`, `routes/` returned none; the board's breadth is
driven by `agency_contact_settings.buyer_pipeline_default_scope`). Because the migration runs BEFORE
`corex:sync-permissions --merge-defaults` (called by `deploy:sync-reference-data`,
app/Console/Commands/Deploy/SyncReferenceData.php:91), and merge-defaults only inserts *missing* keys
(app/Console/Commands/SyncPermissions.php:355-369), the migration's row wins over the config default. This is
the documented intent ("sales-side board has no gate at all today"), but it is a **business decision Johan
should confirm**: every agent/viewer who can see Core Matches will see the Rental Pipeline board on day one.
Not a data risk.

**M2 — `2026_09_10_040000_grant_contact_rental_history_view_alongside_contacts_view.php:37-56` — same
pattern: `contact_rental_history.view` scope `'all'` for every (agency, role) holding `contacts.view`,
including the NULL-agency template rows and `viewer`/`agent`.** CONFIRMED. This scope IS consumed at runtime:
`PermissionService::contactRentalHistoryScope()` (app/Services/PermissionService.php:353-355, defaults to
`'all'` when no row anyway) → `RentalApplication.php:1021`. So agents see rental-application history on any
contact they can already open. Matches the migration's stated Johan ruling ("any user working with a contact
can see the history"); flagged only so it is a decision on the record for prod, not an oversight. Idempotent
(withTrashed()->firstOrNew()+restore, correct for the `(role, permission_key, agency_id)` unique index at
mysql-schema.sql:12813 which has no deleted_at component).

**M3 — Non-transactional multi-statement migrations with no `hasColumn`/index guards (re-run after a mid-way
failure would fail on "Duplicate column" / "Can't DROP" and leave the DB half-migrated with the migration
unrecorded).** CONFIRMED by reading; PLAUSIBLE as an event (Staging already replayed this exact sequence
from scratch per a0c8ff912's commit message, so the happy path is proven). Worst offenders, in order of
how much state they leave behind:

| File | Statements in up() | What a re-run hits |
|---|---|---|
| `2026_09_11_100000_add_entry_fields_to_rental_application_document_marks.php:47-69` | dropForeign → 3× MODIFY → re-add FK + 4 ADD COLUMN + FK + index | if it dies after line 49, re-run fails at `dropForeign` (constraint gone) |
| `2026_09_15_090000_add_token_and_confirmation_to_contact_match_shares_table.php:30-48` | ADD 2 cols → backfill → raw `MODIFY token NOT NULL` → ADD UNIQUE | re-run fails at ADD COLUMN token |
| `2026_09_08_180000_create_rental_application_income_expense_items_tables.php:27-113` | 2× CREATE → chunked INSERT → dropColumn | re-run fails at CREATE (table exists) |
| `2026_09_08_150000_replace_authoriser_list_with_ro_co_tiers.php:35-43` | dropColumn + 2 ADD on `agencies`, then ADD on `rental_applications` | re-run fails at dropColumn |
| `2026_09_08_170000_replace_income_multiplier_with_gross_income_percentage.php:24-34` | dropColumn, then ADD | re-run fails at dropColumn |
| `2026_09_08_190000_add_strike_out_and_added_by_to_rental_application_items.php:43-53`, `2026_09_08_190100…:31-37`, `2026_09_10_140000…:19-25`, `2026_09_08_120000…:26-32` | 2 tables each | re-run fails on the first table |
| `2026_09_10_100000_create_deal_properties_table.php:37-70` | CREATE → chunked INSERT from `deals` | re-run fails at CREATE; the backfill is the only step that touches real prod data (INSERT into the new table only) |
| `2026_09_14_150100_add_agent_id_to_contact_matches_table.php:30-37` | ADD col+FK → UPDATE | re-run fails at ADD |

Properly guarded (good precedent): `2026_09_08_210100` and `2026_09_12_090000` (hasColumn/SHOW INDEX guards).
Concrete prod consequence if any of the above fails midway: the operator must hand-repair (drop the partial
column/table) before `migrate` can resume. Recommended mitigation is operational, not code: take a
`mysqldump` of the prod DB immediately before `migrate --force` (memory note: prod has no automatic backups).

### LOW

**L1 — `2026_09_10_070000_restore_agency_1_admin_rental_applications_view_scope.php:31-34` hardcodes
`agency_id = 1`.** CONFIRMED safe on prod: `rental_applications.view` did not exist in
`config/corex-permissions.php` at the old tip (`git show 6545f0262:config/corex-permissions.php` → 0 matches
for `rental_applications.`), so no `role_permissions` row for that key can exist when this migration runs;
`merge-defaults` (which creates them) runs afterwards in `deploy:sync-reference-data`. The UPDATE therefore
affects 0 rows. Even if a row existed, `'all'` equals the config default for `admin`
(`scope_defaults.admin = all`), so it cannot over-grant beyond the default. The root cause it patches
(Role Manager save nulling the scope of `type=>'access'` `.view` keys) is fixed in app code —
`RoleManagerController.php:267-282` snapshots existing scopes before the rebuild — so the class will not
recur on prod.

**L2 — `2026_09_12_100000_migrate_rental_application_archive_permission.php:34-50` will copy zero rows on
prod** (no `rental_applications.create` grants exist at migrate time, see L1). Under-grant risk: none for
roles with config defaults — `role_defaults` for admin/branch_manager-style roles include
`rental_applications.archive` (config/corex-permissions.php:844, 970) and `merge-defaults` provisions it
right after. Custom Role-Manager roles (no config defaults) will have neither `.create` nor `.archive` until
an admin ticks them, which is the normal behaviour for any new key. CONFIRMED.

**L3 — `2026_09_10_080100_backfill_rental_application_document_marks_from_json.php:61-62` passes
`created_at`/`updated_at` to `RentalApplicationDocumentMark::create()` but neither key is in the model's
`$fillable` (app/Models/RentalApplicationDocumentMark.php:44-50) and no strict mode is enabled (no
`preventSilentlyDiscardingAttributes` in `app/Providers`).** CONFIRMED. The two values are silently discarded
and Eloquent stamps `now()` instead — the "preserve the row's own timestamps" comment is not honoured. On
prod there are zero `rental_application_document_highlights` rows, so the loop body never executes; cosmetic
on QA1/Staging only.

**L4 — `2026_09_10_220000_backfill_furnished_status_property_settings.php:32` iterates ALL agencies,
including soft-deleted ones**, unlike the sibling seeders (`2026_09_09_060100:47`, `2026_09_15_090100:19`)
which filter `whereNull('deleted_at')`. CONFIRMED. Effect on prod: three `property_setting_items` rows
inserted for any archived agency too. Harmless (FK to `agencies` satisfied; `provisionDefaultsFor()` is
idempotent via its per-group exists check, app/Models/PropertySettingItem.php:196-204).

**L5 — `2026_09_11_000001_seed_tenant_contact_type_if_missing.php:26-43` may insert a second row with
`esign_role='lessee'` on prod.** PLAUSIBLE (depends on prod data). `2026_07_02_000003` (already on prod)
*renames* any existing row with `esign_role='lessee'` to `Lessee`, so prod probably has no `Tenant` row and
this migration will insert `Tenant/lessee`. That is the intended outcome (QA1 already runs with both), and
the model tolerates it: `scopeCanonical()`/`scopeParents()` match e-sign parents on name+role and admit
`Tenant` only via `ADDITIONAL_PARENTS` by name (app/Models/ContactType.php:93-119), so the e-sign 1:1 role
mapping is unaffected. Side effect to expect: a new "Tenant" parent appears in the contact-type picker on
prod. Not destructive; `contact_types` has no unique index on `name`/`esign_role` (mysql-schema.sql:4222-4223).

**L6 — `2026_09_10_100000_create_deal_properties_table.php` creates `deal_properties` with no
`agency_id` and the `DealProperty` model has no `BelongsToAgency` (app/Models/DealProperty.php: `use SoftDeletes;`
only).** CONFIRMED. Scoping is inherited through `deals` (which is `BelongsToAgency`). Matches the migration's
stated design; noted against the project rule "agency_id on every tenant table" for the record — not a
migration defect.

**L7 — `2026_09_09_060100_seed_and_backfill_rental_application_highlighters.php:159` and
`2026_09_15_090100_seed_rental_application_decline_reason_templates.php:28` use `truncate()` in `down()`.**
Hard delete, but only in `down()` (out of the up() scope of this audit); never runs on a `migrate --force`
deploy.

---

## Migrations that touch PRE-EXISTING prod tables

| Table | Migration | Operation | Risk |
|---|---|---|---|
| `agencies` | `2026_09_08_120000` | ADD `rental_application_authoriser_user_ids` JSON NULL after `whistleblow_approver_user_ids` (exists: `2026_05_11_135615`) | none |
| `agencies` | `2026_09_08_150000` | DROP `rental_application_authoriser_user_ids` (added 4 migrations earlier, never populated) + ADD `rental_application_ro_user_ids`, `rental_application_co_user_ids` JSON NULL | none on prod data |
| `contacts` | `2026_09_10_010000` | ADD `rental_application_status` VARCHAR(20) NOT NULL DEFAULT 'none', `rental_application_status_updated_at` NULL, after `buyer_source` (exists: `2026_07_05_000001`) | none; defaulted |
| `contacts` | `2026_09_10_090000` | UPDATE via `Contact::saveQuietly()` for contacts with rental applications | no-op on prod (0 rental applications) |
| `document_types` | `2026_09_04_150003` | INSERT `payslip`, `financial_statements` if slug missing; `label`/`grouping`/`is_active`/`sort_order` columns exist (mysql-schema.sql:6063-6067); `slug` UNIQUE | none; idempotent |
| `role_permissions` | `2026_09_10_040000` | INSERT/restore `contact_rental_history.view` scope `all` per `contacts.view` grant | see M2 |
| `role_permissions` | `2026_09_10_070000` | UPDATE scope=`all` for agency 1 / admin / `rental_applications.view` | 0 rows on prod (L1) |
| `role_permissions` | `2026_09_10_100000` | INSERT/restore `buyer_pipeline.view` scope `all` per `core_matches.view` grant | see M1 |
| `role_permissions` | `2026_09_12_100000` | INSERT/restore `rental_applications.archive` per `rental_applications.create` grant | 0 rows on prod (L2) |
| `deals` | `2026_09_10_100000` | READ (`property_id`, `deleted_at`) — backfills new `deal_properties` | `deals.property_id` is FK `ON DELETE SET NULL` (mysql-schema.sql:5507) so no orphan can violate `deal_properties.property_id` FK; `deals.deleted_at` exists (`2026_03_06_100001_add_soft_deletes_tier1`) |
| `deals` | `2026_09_10_120000` | READ (`property_value`, `total_commission`) in UPDATE JOIN into `deal_properties` | decimal(12,2) → DECIMAL(12,2), lossless |
| `contact_matches` | `2026_09_10_120000` | ADD `move_in_date` DATE NULL, `rental_term_months` NULL after `price_max` (exists) | none |
| `contact_matches` | `2026_09_14_150100` | ADD `agent_id` FK users NULL after `created_by_user_id`; UPDATE `agent_id = created_by_user_id` where NULL | `created_by_user_id` is itself FK→users nullOnDelete, so every copied value satisfies the new FK |
| `contact_matches` | `2026_09_14_150400` | ADD `set_aside_at` NULL after `status` (exists: `2026_04_28_100001`) | none |
| `properties` | `2026_09_10_210000` | ADD `furnished_status` VARCHAR(100) NULL, `water_included`/`electricity_included`/`levies_included` BOOL DEFAULT 0 after `rental_price_type` | none; `properties` audit triggers are AFTER INSERT/UPDATE, not fired by ALTER |
| `properties` | `2026_09_13_130000` | ADD `pre_tenant_link_status` NULL after `pre_deal_offer_status` (exists: `2026_07_11_000003`) | none |
| `properties` | `2026_09_15_100000` | ADD `p24_imported_at` TIMESTAMP NULL + index, after `p24_listing_number` (exists: `2026_04_14_000003`) | none |
| `property_setting_items` | `2026_09_10_220000` | INSERT 3 `furnished_status` rows per agency via `DB::table` | idempotent (L4) |
| `agency_contact_settings` | `2026_09_10_230000` | ADD `buyer_kanban_column_limit` SMALLINT DEFAULT 50 after `buyer_pipeline_default_scope` (exists) | none |
| `agency_contact_settings` | `2026_09_14_150300` | ADD `core_matches_working_window_days` NULL after `buyer_lost_days` (exists) | none |
| `agency_contact_settings` | `2026_09_15_090100` | ADD `core_matches_price_drop_threshold_pct` TINYINT DEFAULT 3 | none |
| `contact_types` | `2026_09_11_000001` | INSERT `Tenant`/`lessee` if missing | L5 |
| `contact_types` | `2026_09_12_000001` | INSERT Seller/Buyer/Lessor/Lessee/Owner/Other if missing | no-op on prod — `2026_07_02_000003` and `2026_07_03_000001` (both on old tip) guarantee these exact (name, esign_role) pairs |
| `portal_leads` | `2026_09_14_150000` | ADD `received_by_user_id` FK users NULL after `existing_contact_agent_id` (exists: `2026_05_20_000001`) | none; no backfill by design |
| `contact_property` | `2026_09_16_100000` | ADD `deleted_at` NULL after `source` (exists: `2026_08_21_000150`) | none; existing UNIQUE(contact_id, property_id) deliberately retained — app code must restore-not-insert on relink (documented in the migration) |
| `users`, `branches`, `documents`, `agencies` | many | FK **targets only**, never altered | none |

Everything else in the set creates or alters tables that are new in this promotion.

---

## Detailed checks

### 1. Ordering / dependency defects

Laravel orders by filename; same-timestamp pairs resolve alphabetically. Every `->after(...)`, FK target and
model call was checked against the columns that exist at that migration's position:

- **Same-timestamp pairs** (alphabetical order in brackets) — all independent or correctly ordered:
  `2026_09_07_150000` [assessments < status_history]; `2026_09_08_190000` [statement_months < strike_out] (both
  depend on `180000`); `2026_09_08_200000` [has_unpaid < marks_version]; `2026_09_10_100000` [create_deal_properties
  < grant_buyer_pipeline]; `2026_09_10_120000` [contact_matches rental term < backfill_allocated_price — the
  latter depends on `110000`, earlier]; `2026_09_10_150000` [statement_period_dates < create_rental_application_document];
  `2026_09_12_100000` [draft_saved_at < migrate_archive_permission]; `2026_09_13_000000` [capture_type <
  document_rate_limit]; `2026_09_13_130000` [pre_tenant_link_status < return_gate_settings — `2026_09_13_140000`
  and `2026_09_15_100000` both `after('return_gate_attempt_window_minutes')`, later]; `2026_09_15_090000`
  [decline_email_draft < contact_match_shares token < create_decline_reason_templates —
  `decline_reason_template_id` is deliberately NOT an FK, so no dependency on the later-sorted create];
  `2026_09_15_090100` [price_drop_threshold (after `core_matches_working_window_days`, `09_14_150300`) <
  seed_decline_reason_templates (table created at `09_15_090000`, earlier)]; `2026_09_15_100000`
  [identity_gate_settings < p24_imported_at].
- **`after()` chains** on `rental_application_qualifying_settings`, `rental_applications`,
  `rental_application_assessments`, `rental_application_highlighters`, `rental_application_document_marks`,
  `rental_application_*_items`, `rental_application_signatures`, `contact_matches`, `agency_contact_settings`,
  `properties`, `contact_match_shares`: every referenced column is created by an earlier-sorted migration.
  (Full chain enumerated during the audit; no gap found.)
- **Model calls inside migrations** (the a0c8ff912 class):
  - `2026_09_09_060100` — uses only `RentalApplicationHighlighter::DEFAULT_SEED` (constant, has `legacy_role`/`legacy_category`,
    app/Models/RentalApplicationHighlighter.php:66-72) and `withTrashed()->exists()`; inline insert omits
    `capture_type` (added `09_13_000000`) and `created_by` (added `09_09_070000`). Fixed correctly.
  - `2026_09_10_080100` — `RentalApplicationDocumentMark::create()` only assigns columns present at
    `09_10_080000`; no `booted()`/observer/`$attributes` defaults on the model (grep: none registered in
    `app/Providers`). Safe.
  - `2026_09_10_090000` — `RentalApplication::withoutGlobalScopes()` + `Contact::withoutGlobalScopes()` +
    `saveQuietly()`; columns `contacts.rental_application_status*` exist from `09_10_010000`. Safe.
  - `2026_09_10_040000/100000`, `09_10_070000`, `09_12_100000` — `RolePermission` (no global scope,
    `$fillable = role, permission_key, scope, agency_id`). Safe.
  - `2026_09_10_220000` — `PropertySettingItem::provisionDefaultsFor()` writes via `DB::table` with columns
    `agency_id, group, name, sort_order, is_default, active` — all present on prod (mysql-schema.sql:11209-11216).
  - `2026_09_15_090100` — `RentalApplicationDeclineReasonTemplate::seedDefaultsFor()` bulk `insert()`
    of `reason, guidance, sort_order` — all created at `09_15_090000`.
- **No remaining instance** of the a0c8ff912 class.

### 2. Data-destructive ops in up() on pre-existing prod tables

None. Every `dropColumn`/`dropIfExists`/`truncate` in an `up()` targets objects created within this same
promotion (`2026_09_08_150000:36`, `2026_09_08_170000:25`, `2026_09_08_180000:112`, `2026_09_09_060200:21`).
The two ENUM `MODIFY` statements (`2026_09_07_130000:17`, `2026_09_08_220000:20`) only widen
`rental_applications.status` (every prior value retained). No NOT-NULL-without-default on a populated table
(`contacts.rental_application_status` has DEFAULT 'none'; `agency_contact_settings` additions have defaults;
`rental_application_signatures.agency_id NOT NULL` is applied to an empty table after a JOIN backfill). No
unique index is added on a pre-existing populated column (`contact_match_shares.token` UNIQUE is on a new,
empty table; `contact_property`'s existing unique is untouched).

### 3. Backfill / seed migrations

All idempotent and safe on a prod DB with no rental-application history:

| Migration | Idempotent? | On prod | Scope-safe? |
|---|---|---|---|
| `09_04_150003` document types | yes (slug exists check) | inserts 2 rows | DB::table |
| `09_08_180000` assessments → items | copy then drop; re-run blocked by CREATE | 0 rows | DB::table |
| `09_09_060100` highlighters | yes (`withTrashed()->exists()` per agency; `highlighter_id` isset guard) | seeds 6 rows per live agency | no auth user → no scope |
| `09_10_080100` marks from JSON | NOT idempotent (would duplicate on re-run; but re-run impossible without the ledger row being removed) | 0 rows | explicit agency_id |
| `09_10_090000` contact status | yes (skips equal values) | 0 rows | withoutGlobalScopes |
| `09_10_100000` deal_properties backfill | guarded by CREATE | 1 row per live deal with property | DB::table |
| `09_10_120000` allocated_price | yes (`IS NULL` predicate) | fills the rows just created | raw SQL |
| `09_10_220000` furnished_status | yes | 3 rows per agency (incl. archived, L4) | DB::table |
| `09_11_000001` Tenant | yes | probably 1 insert (L5) | DB::table |
| `09_11_100100` items → marks | NOT idempotent (copy without marker) | 0 rows | DB::table |
| `09_12_000001` base parents | yes | 0 rows | DB::table |
| `09_13_000000` capture_type | yes (label match) | tags the 4 seeded Income/Expense rows per agency | DB::table |
| `09_14_150100` agent_id | yes (`whereNull`) | updates every contact_match once | DB::table |
| `09_15_090000` share tokens | yes (`whereNull('confirmed_at')`) | 0 rows (new table) | DB::table |
| `09_15_090100` decline templates | yes (`withTrashed()->exists()`) | seeds per live agency | bulk insert |

Hardcoded agency: only `09_10_070000` (L1). No migration assumes a single agency.

### 4. Permission migrations — exactly what they write

- `09_10_040000`: for each live `role_permissions` row with `permission_key='contacts.view'` → upsert
  `(agency_id, role, 'contact_rental_history.view', scope='all')`, restoring a trashed row if present. M2.
- `09_10_070000`: `UPDATE role_permissions SET scope='all' WHERE agency_id=1 AND role='admin' AND
  permission_key='rental_applications.view'` — 0 rows on prod. L1.
- `09_10_100000`: for each live row with `core_matches.view` → upsert `buyer_pipeline.view`, scope `'all'`. M1.
- `09_12_100000`: for each live row with `rental_applications.create` → upsert `rental_applications.archive`,
  scope `NULL` (action key). 0 rows on prod; `merge-defaults` fills it from `role_defaults`. L2.

Over-grant: M1/M2 only, and only relative to per-role config defaults, by explicit ruling. Under-grant: none
(`merge-defaults` runs after migrate for every config-backed role; `PermissionService::getDataScope()`
denies only when the whole table is empty, PermissionService.php:236-240, which is not the prod case).

### 5. MySQL 8 compatibility

- ENUM `MODIFY` via raw `DB::statement` (`09_07_130000`, `09_08_220000`, `09_11_100000` via `->change()`): valid; widening.
- JSON columns: all nullable, no literal defaults (MySQL 8 rejects those) — `rental_application_authoriser/ro/co_user_ids`,
  `marks_json`, `old_values/new_values/metadata`, `snapshot_json`, `points`, `required_field_keys`, `marital_status_options`.
- `deal_properties.allocated_price` BIGINT UNSIGNED → `DECIMAL(12,2) NULL` (`09_10_110000:21`): all rows NULL at that
  point (the `09_10_100000` backfill inserts no price), then `09_10_120000` fills from `deals.property_value` DECIMAL(12,2). Lossless.
- `->change()` (`09_11_100000:52-54`, `09_12_090000:52`): Laravel 12 native; `MODIFY` on an FK column that keeps its
  type (NULL↔NOT NULL) is permitted by MySQL 8. Verified end-state in snapshot (mysql-schema.sql:12153-12163, 12389).
- Index key lengths: longest string keys are VARCHAR(64) utf8mb4 (`token`, `mark_uid`) = 256 bytes ≪ 3072; all fine.
- Identifier lengths: every auto-generated name ≤ 63 chars (longest: `rental_application_document_marks_struck_out_by_user_id_foreign`
  = 63, `rental_application_status_history_rental_application_id_foreign` = 63); over-length cases already use explicit names.
  Proven by the snapshot having been generated from this exact history.
- FK engines/charsets: 0 `MyISAM` tables in the snapshot; all `utf8mb4_unicode_ci`; all FK columns are BIGINT UNSIGNED.

### 6. agency_id + deleted_at vs model traits

Every new table matches its model exactly (checked all 25 models):

| Table | agency_id | deleted_at | Model traits |
|---|---|---|---|
| rental_applications | yes | yes | BelongsToAgency, SoftDeletes |
| rental_application_signatures | yes (added 09_12_090000, NOT NULL) | yes (09_08_210100) | BelongsToAgency, SoftDeletes |
| rental_application_document_requirements | yes | yes | both |
| rental_application_checklist_configs | yes | no | BelongsToAgency |
| rental_application_assessments | yes | no | BelongsToAgency |
| rental_application_status_history | yes | no | BelongsToAgency (append-only) |
| rental_application_qualifying_settings | yes | no | BelongsToAgency |
| rental_application_document_highlights | yes | yes | both |
| rental_application_decline_email_settings | yes | no | BelongsToAgency |
| rental_application_audit_log | yes | yes | both |
| rental_application_income_items / expense_items | yes | yes | both |
| rental_application_generations | yes | no | BelongsToAgency (immutable) |
| rental_application_highlighters | yes | yes | both |
| rental_application_approval_email_settings | yes | no | BelongsToAgency |
| rental_application_document_marks | yes | yes | both |
| rental_application_document (pivot) | no | no | no model (pivot) |
| rental_application_document_validity_windows | yes | no | BelongsToAgency |
| rental_application_decline_reason_templates | yes | yes | both |
| deal_properties | **no** | yes | SoftDeletes only (L6) |
| contact_match_reassignments | yes | yes | both |
| contact_match_shares | yes | yes (09_14_150600) | both |
| contact_match_share_properties | yes | no | BelongsToAgency |
| contact_match_link_opens | yes | yes | both |
| contact_property (pivot, pre-existing) | no | yes (09_16_100000) | ContactProperty: SoftDeletes |
| rental_application_mark_color_settings | dropped | — | no model file (correct) |

No model declares `SoftDeletes` for a table lacking `deleted_at`, and none declares `BelongsToAgency` for a
table lacking `agency_id`.

### 7. down()/re-run correctness

Covered by M3. `down()` methods were not audited beyond noting the two `truncate()` calls (L7).

### 8. Schema snapshot

`database/schema/mysql-schema.sql`: all 92 migration filenames present in the `migrations` INSERT (0 missing);
`DEFINER` occurrences: 0. Confirmed.

---

## Operational notes for the deploy (not defects)

1. Order matters: `git pull` → `php artisan migrate --force` → `php artisan deploy:sync-reference-data`
   (runs `corex:sync-permissions --merge-defaults`, which is what gives prod roles the new
   `rental_applications.*`, `contact_rental_history.view`, `buyer_pipeline.view` keys). Skipping step 3 leaves
   the rental module invisible to every role except the ones the two grant migrations touched.
2. Take a manual `mysqldump` of the prod DB immediately before step 2 (M3; prod has no automatic backups).
3. After deploy, expect on prod: 2 new `document_types`, 6 highlighters + N decline templates +
   3 `furnished_status` items per agency, 1 `deal_properties` row per live deal with a property, a `Tenant`
   contact type if absent, and `contact_matches.agent_id` filled from `created_by_user_id`.
