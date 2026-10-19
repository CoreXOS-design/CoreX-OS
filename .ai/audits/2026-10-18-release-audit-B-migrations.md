# B. Migration audit: release 989f6ffd0..0c7bfebed

Scope: 225 added migrations plus 1 deleted (`--diff-filter=A` / `D`). Read-only review, done in the worktree `/mnt/HC_Volume_103099143/corex-worktrees/audit-release-2026-10-18`.
Live reference: `origin/Prod` (8de0f6e34). It contains 989f6ffd0 and **none** of the 225 added migrations, so live still has to run all 225.
Pre-existing tables were worked out from the base `database/schema/mysql-schema.sql` plus the base migrations dated after the snapshot (2026_10_15..2026_10_25).

---

## HIGH

### H1. The live deploy can abort at migration 4 of 225 on duplicate deal numbers. PLAUSIBLE: depends on live data
`database/migrations/2026_09_29_174606_add_unique_index_to_deals_deal_no.php:28-47`
- `up()` counts `(agency_id, deal_no)` duplicates across **all** `deals` rows, including soft-deleted ones (`DB::table`, so no SoftDeletes scope applies). If it finds any, it `throw`s a RuntimeException.
- Its own docblock (lines 16-20) calls this "EXPECTED to be a live-only risk". The bug it guarded against (#1826/#1827, two deals with the same number) happened **on live**. Andre's kept fix (41d60405d) only changes code. It does not de-duplicate data, and archiving a duplicate does not help because trashed rows are counted.
- What happens: `php artisan migrate --force` on live stops here. 174604 (`sequence_counters`) and both 174605 (`create_token` columns) are already applied, because MySQL DDL is not transactional. The other ~221 migrations never run, but the release code is deployed against a half-migrated DB, so every rentals, inspections, RTS and company-ads screen that reads new columns or tables throws a 500.
- This index is thrown away anyway: `2026_09_29_203059_drop_duplicate_deal_fix_objects.php` drops it, `sequence_counters` and `create_token` a few seconds later. On live the 174604-174606 chain does nothing except carry this risk.
- Fix: make 174604/174605/174606 (add_unique_index + backfill) no-op on any DB that has not run them. Or at minimum, run `SELECT agency_id, deal_no, COUNT(*) FROM deals WHERE deal_no IS NOT NULL GROUP BY 1,2 HAVING COUNT(*)>1` on live before the deploy and resolve any hits first.

---

## MEDIUM

### M1. A migration that already ran on live was deleted, and its per-agency settings are silently dropped. CONFIRMED (file deletion); PLAUSIBLE (value loss)
`database/migrations/2026_10_18_000100_add_fica_request_settings_to_agencies.php` (exists on `origin/Prod` and at 989f6ffd0, missing at 0c7bfebed)
- How it was lost: merge resolution "Merge origin/Staging into QA1 … QA1's versions win" (9730d74da, then 2b3d0c9f3 and 54fec39f6). QA1 never had the file, so the merges dropped it.
- On live this migration has run. `agencies.fica_link_days` (default 14) and `agencies.fica_expiring_after_months` (default 11) exist, and the Quick Links Wave 1 settings screen writes them.
- The release replaces them with **different** columns from `2026_10_18_000700_add_fica_windows_to_agencies_table.php`: `fica_link_expiry_days` and `fica_current_months`, both nullable, NULL meaning the code default. No code in the release reads the old columns, and nothing copies the old values across.
- Effect on live:
  - Any agency that changed its FICA link validity or "expiring after" months goes back to 14 days / 11 months without notice.
  - The two old columns stay behind as orphans.
  - `migrations` keeps a row for a file that no longer exists, and `migrate:rollback` of that batch warns "Migration not found".
- Fix: restore the deleted file unchanged (so the repo matches live's migration history), and in `2026_10_18_000700` copy `fica_link_days` → `fica_link_expiry_days` and `fica_expiring_after_months` → `fica_current_months` wherever they differ from 14 / 11.

---

## LOW

### L1. Hardcoded agency ID 1 in a data backfill. CONFIRMED
`database/migrations/2026_10_14_150000_neutralise_cloned_hfc_outreach_template.php:23` uses `->where('agency_id', '!=', 1)`.
- The two sibling migrations (150100, 150200) find HFC by `slug = 'hfc-coastal'`. This one assumes HFC is id 1.
- On any DB where HFC is not id 1 (the demo instance, re-seeded DBs), HFC's own template gets rewritten, and whatever agency holds id 1 keeps the "KZN South Coast" wording.
- It also only replaces the one phrase from `HfcConsentTemplatesSeeder.php:159`. The other HFC phrases in that seeder ("a PPRA-registered agency on the KZN South Coast", lines 239 and 344) are not neutralised, if any agency holds clones of those templates.
- Fix: look up HFC by slug, as 150100/150200 do, and add the two other phrases.

### L2. 70 migrations are dated in the future (2026-10-19 to 2026-11-15; today is 2026-10-18). CONFIRMED
For example `2026_10_19_100000_add_inspection_rules_settings_and_signing_notices.php` through `2026_11_15_100000_company_ads_e3_marketing_posts.php`.
- Live runs pending migrations whatever their order, so the deploy is not affected.
- But any migration written over the next four weeks with today's real date sorts **before** these. If it alters a table one of them creates (`company_ads`, `rts_*`, `lease_notices`, `library_articles`, …), `migrate:fresh` breaks: on the demo rebuild (`migrate:fresh --database=demo`), on lane test schemas, and on a new install.
- Fix: from now on, name new migrations after the newest existing timestamp (2026_11_15_…), not the wall-clock date, or rename the future-dated ones before they reach live.

### L3. Migrations that call application model and service classes (fragile on a future fresh migrate). CONFIRMED
They work today, but they bind schema history to the current model code (columns, casts, constants, boot hooks):
- `2026_09_29_183000` (RentalFaultType::seedDefaultTypesFor) and `2026_09_29_190200` (RentalFaultType::SAFETY_LINE)
- `2026_10_05_230600` (RentalVatType::seedDefaultsFor) and `2026_10_05_240300` (RentalCatalogueItemType / RentalCatalogueUnit::seedDefaultsFor)
- `2026_10_10_100500`, `2026_10_11_100800`, `2026_10_13_100410`, `2026_11_09_100100`, `2026_11_13_100100` (RolePermission Eloquent)
- `2026_10_21_100000` (RentalWorkOrder / RentalFaultReport / RentalFaultReportUpdate)
- `2026_10_28_100000_rental_who_pays_log` (RentalFaultType, RentalFaultTypeCause::seedForType)
- `2026_10_29_400000` (AgencyServiceProvider::legacySpecialtyForType)
- `2026_11_02_110000` (App\Support\AgencyShortCode)

`2026_09_29_183000` already broke a fresh migrate once and had to be edited (see L7).
Fix: future seed/backfill migrations should write with `DB::table()` and inline constants.

### L4. The fault catch-up also resolves archived faults and work orders. PLAUSIBLE
`database/migrations/2026_10_21_100000_resolve_faults_left_waiting_on_tenant_check.php:20,26`
- `withoutGlobalScopes()` strips the SoftDeletes scope as well as the agency scope.
- Result: work orders that are soft-deleted but still marked completed move their faults to Resolved/Repaired, and a soft-deleted fault can be resolved too. Each one gets a new "outcome set" history line.
- Fix: add `->whereNull('deleted_at')` on both queries (or use `withoutGlobalScope(AgencyScope::class)` only).

### L5. Destructive `down()` methods (hard deletes, or wiping every agency's rows). CONFIRMED
- `2026_10_05_230600_seed_rental_vat_types_for_existing_agencies.php:25`: `truncate()` of `rental_vat_types`. This wipes every agency's VAT types, including ones agencies created themselves, and orphans job-card lines that point at them.
- `2026_10_10_100500_grant_rental_job_cards_share_permission.php:45` and `2026_10_11_100800_grant_maintenance_flow_permissions.php:57`: `forceDelete()` (hard delete) of role_permissions rows, including rows agencies have since re-ticked or edited in Role Manager.
- `2026_10_14_000200_seed_buyer_notes_view_permission.php:95-96`: hard `DB::table()->delete()` of every `buyer_notes.view` row, including agency customisations.
- `2026_10_13_100240_backfill_attendance_from_not_present_signatures.php:56` and `2026_09_29_174606_backfill_sequence_counters_for_deal_numbering.php:85`: hard delete (limited to rows the migration created).

These only matter on a rollback, but they break the no-hard-delete rule. Fix: soft-delete (`update(['deleted_at' => now()])`), scoped to the rows `up()` created, or make `down()` a no-op.

### L6. A migration added and then deleted inside this release (only lane/QA DBs that ran it are affected). PLAUSIBLE
- `2026_10_29_120000_add_builder_limits_to_rts_settings.php` was added in dd32aaca1 and deleted in 35d8fc194 ("lane 1 delivers them").
- `2026_10_29_100300_rts_settings_audit_gaps.php:27` re-adds `add_to_day_max` with no `hasColumn` guard. Any DB that ran the deleted file before 100300 existed fails 100300 with "Duplicate column 'add_to_day_max'", and keeps 6 orphan columns plus a migrations row with no file.
- Live is not affected: it has run neither file.
- Fix: put a `hasColumn` guard on 100300's adds.

### L7. A migration was edited after it had already run on QA1. CONFIRMED, harmless
`2026_09_29_183000_create_rental_fault_types_table.php`: committed in cddbacbff (09-29), then edited in b040479fa (10-13) to call `seedDefaultTypesFor` instead of `seedDefaultsFor`.
- The behaviour is the same on DBs that already ran it, because the causes table did not exist yet. Recorded only because the brief counts any edited migration that ran elsewhere as a finding.

---

## Checked and found clean (counts)

- **All 225 added migrations plus 1 deleted:** scripted grep for drop/delete/truncate/update/insert/`change()`/unique/rename/models/agency-1/HFC. Every hit was read in full.
- **Tables:**
  - 0 release `Schema::create` calls hit a table that already exists on live (checked against the base snapshot and base migrations up to 2026_10_25).
  - 0 of 533 column adds collide with a column already on live.
  - 0 column adds on existing tables are NOT NULL without a default.
  - Every `->after('col')` anchor on a pre-existing table was checked against the base schema (20 checked, all present).
- **Column type changes:** 4 checked, all widen-only or NULL-relax: `rental_fault_reports.outcome` 20→40, `disposition` 10→20 on both signature tables, `rental_work_order_quotes.agency_service_provider_id` → nullable, and the `property_marketing_posts.status` ENUM. The ENUM change is a strict superset of the base definition `('draft','published','failed')`, with matching collation and default.
- **Unique indexes on existing data:**
  - `rts_list_items` duplicates are archived first, by soft delete, and the table is new.
  - `agencies.short_code` values are generated unique before the index matters (the column is nullable).
  - The `rental_insp_prev_insp_unique` drop is safe: it exists on live (base snapshot line 14475) and the plain index is added first.
  - All other unique indexes sit on tables created in this release.
- **Column/table drops:** 6 checked. All are on tables created in this release, or on columns created in this release whose data is copied first: catalogue `type`/`unit`/`name`, `leases.notice_readvertised`, the dup-deal objects.
- **Data backfills on pre-existing live tables:** 22 checked. All are narrowly scoped (agency-correct, only NULL or default rows, idempotent):
  - lease signing state
  - notice outcome
  - fault `sent_to_owner_at`, which pairs with the `visibleToOwner()` scope (decided/landlord faults stay visible)
  - declined quotes
  - quote revisions
  - approval grandfathering
  - inventory signature relabel (sale and no lease only)
  - document_type backfill
  - inspection photo `taken_at`
  - attendance backfill
  - previous-inspection re-pointing
  - supplier kind correction
  - supplier service-type ticks
  - service-type groups
  - property fault-settings carry-over
  - owner queries carried in
  - job-card sign-off flags
  - `portal_leads.response_tracked = false` (intentional, documented)
  - template `human_edited_at`
  - HFC website URL and map freeze (slug-scoped, only blank columns)
  - invite sentence (only agencies that had sent applications)
  - lease-notice named fields
- **Permission grants:** 6 checked. Each copies its source key's holders and scope per agency, plus the global template rows. Company Ads is limited to manager roles and schedule posts to practitioner roles. No grants go to roles that lacked the source permission.
- **Notification event registrations:** 12 checked. All are global catalogue rows, existence-guarded, and soft-deleted on down.
- **Same-timestamp pairs:** 18 checked. No dependency inverted by alphabetical order.
- **HFC/agency-specific wording in seeded defaults:** none found, apart from L1 and the two deliberate slug-scoped HFC freezes (150100, 150200).
