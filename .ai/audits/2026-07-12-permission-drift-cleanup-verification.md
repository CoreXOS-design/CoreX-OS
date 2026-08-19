# Permission-drift cleanup — build + qa1 verification (2026-07-12)

**Status:** BUILT + verified on qa1. **HELD — ships to staging/live only on Johan's explicit "clean it".**
**Branch:** `AT-permission-drift-cleanup` (also fast-forwarded into `QA1` @ `cd2c500d`).
**Bug-class:** [[permission-drift-role-permissions-vs-config]] — runtime perms read the `role_permissions`
DB table, not config; `corex:sync-permissions --merge-defaults` is additive-only, so `agent` froze at the
broadest (manager-shaped) set ever seeded.

## What was built

| File | Purpose |
|------|---------|
| `app/Console/Commands/ReconcileRoleGrants.php` | `corex:reconcile-role-grants` — reversible drift cleanup + rollback |
| `app/Services/Permissions/RoleDefaultsResolver.php` | Single source of truth for config→key-set expansion (shared with sync) |
| `app/Console/Commands/SyncPermissions.php` | `keysForDef()` now delegates to the resolver (no divergence) |
| `tests/Feature/Permissions/ReconcileRoleGrantsTest.php` | 4 tests / 28 assertions — dry-run, apply, rollback, refusal |

### Command contract
```
corex:reconcile-role-grants
  --roles=agent           # closed-include roles to reconcile (comma list; default agent)
  --apply                 # soft-delete over-grants (DEFAULT = dry-run, changes nothing)
  --snapshot=<path>       # rollback manifest (default storage/app/permission-reconcile/reconcile-<ts>.json)
  --rollback=<path>       # one-command undo — restore exactly what an --apply removed
```

**Safety rails:**
- Default is a **dry-run** (reviewed diff) — nothing changes without `--apply`.
- **Soft-delete only** (non-negotiable #1). Rows keep their IDs; rollback is a pure `restore()`.
- Every `--apply` writes a snapshot manifest (exact row IDs) → `--rollback` is deterministic and total.
- Only **closed-include** roles are eligible. Wildcard owner (`*`) and all-minus `admin` (`exclude`) are
  **REFUSED** — their intent isn't exhaustively enumerated, so "outside the list" ≠ drift.
- Clears `PermissionService::clearCache()` after apply/rollback.

## Unit test (single file, isolated test DB — corex-dev lane)
```
PASS  Tests\Feature\Permissions\ReconcileRoleGrantsTest
 ✓ dry run reports but changes nothing
 ✓ apply removes only over grants and clears cache
 ✓ rollback restores exactly what apply removed
 ✓ refuses non closed roles
Tests: 4 passed (28 assertions)
```

## qa1 live-data verification (mirrors live via nightly sync)

### Dry-run plan — `--roles=agent`
```
Role: agent  (config include = 123 keys)
  template (agency NULL): 243 grants — 120 over-grants
  agency 1:               240 grants — 117 over-grants
  agency 7:               243 grants — 120 over-grants
Total: 357 rows (120 distinct keys)
```
Distinct over-grant keys include: `settle_deals`, `create_deals`, `delete_properties`,
`compliance.fica.approve`, `agency.authorize_external_access`, `manage_targets`,
`deals_v2.override_dates`, `manage_p24`, `publish_properties`, `testimonials.publish`, … (manager set).

### As Retha Kelly (user #24, role=agent, agency 1)

| Probe | BEFORE | AFTER `--apply` | AFTER `--rollback` |
|-------|--------|-----------------|--------------------|
| live agent/agency-1 grants | **240** | **123** (= config include) | **240** |
| `settle_deals` | GRANTED | **denied** | GRANTED |
| `create_deals` | GRANTED | **denied** | GRANTED |
| `manage_targets` | GRANTED | **denied** | GRANTED |
| `delete_properties` | GRANTED | **denied** | GRANTED |
| `compliance.fica.approve` | GRANTED | **denied** | GRANTED |
| `deals_v2.override_dates` | GRANTED | **denied** | GRANTED |
| `view_deals` (legit) | GRANTED | **GRANTED** | GRANTED |
| `deals.view` (legit) | GRANTED | **GRANTED** | GRANTED |
| `deals.create` (legit) | GRANTED | **GRANTED** | GRANTED |
| `contacts.view` / `properties.view` / `access_deal_register_v2` / `portal_leads.view` | GRANTED | **GRANTED** | GRANTED |

- **Apply:** 357 rows soft-deleted, snapshot written, cache cleared. Retha loses every over-grant
  (incl. settle_deals), keeps her full 123-key legit set.
- **Rollback:** `357/357 restored`, cache cleared. Retha back to 240 grants, settle_deals GRANTED again.

**qa1 was left in the RESTORED (original) state** — it stays a faithful live mirror until Johan authorizes
the cleanup. When he says "clean it": run `--apply` on staging → verify → live, keeping each snapshot for
rollback.

## Deploy sequence when authorized (per [[deploy-authorization-rule]] / non-negotiable #12)
1. `git pull` the branch on the target checkout.
2. `php artisan corex:reconcile-role-grants --roles=agent` (dry-run — eyeball the count).
3. `php artisan corex:reconcile-role-grants --roles=agent --apply` (snapshot auto-written under
   `storage/app/permission-reconcile/`).
4. `view:clear` + `route:clear` + `config:clear`; reload php-fpm (live=php8.3 pool).
5. Keep the snapshot path — one-command rollback if anything looks off.

**Harden follow-up (separate):** add a `--reconcile-role-defaults` mode to `corex:sync-permissions`, or wire
this command into the deploy path, so closed-include roles never re-drift.
