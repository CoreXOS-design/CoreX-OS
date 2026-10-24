## 10. Files to create (none yet written — spec only)

- `database/migrations/xxxx_create_leases_table.php`
- `database/migrations/xxxx_create_lease_tenants_table.php`
- `database/migrations/xxxx_create_lease_escalations_table.php`
- `database/migrations/xxxx_create_lease_settings_table.php`
- `database/migrations/xxxx_migrate_rentals_and_lease_records_into_leases.php` (data migration, §6 —
  expect a manual-review queue for unresolved address matches, not a silent one-shot script)
- `app/Models/Lease.php`, `LeaseTenant.php`, `LeaseEscalation.php`, `LeaseSetting.php` — all
  `use BelongsToAgency`.
- `app/Services/Rentals/LeaseActivationService.php` — the overlap-prevention guard (§3.5).
- `app/Http/Controllers/CoreX/LeaseController.php` — CRUD, activate, cancel, escalate.
- `resources/views/corex/leases/index.blade.php` — the new list screen (§7).
- `resources/views/corex/properties/partials/rental-tab-active-lease.blade.php` — the read-only active-
  lease summary added to the existing Rental tab.
- `config/corex-permissions.php` — new permission keys (§8).
- Sidebar entry for the new list screen (same-day, non-negotiable #2).
- `tests/Feature/Leases/*` — overlap prevention, renewal chain integrity, joint-tenant CRUD, agency
  scoping, migration-script correctness against a fixture mirroring the real `rentals` shape, at
  minimum.
- Re-run `php artisan schema:dump`, commit refreshed `database/schema/mysql-schema.sql`
  (non-negotiable #12a).
- **Separately, as its own follow-up prompt once this spec is approved**: the §9 amendment to
  `.ai/specs/rental-inspections.md`.

---
