# AT-283 — Deal Register & Settlement scoping (evidence pack)

**Johan's ruling (verbatim, 17 Jul 2026):** "agents should never have had access power to deal register. they have access to my deals on their agency tracker. not on the deal register. thats scoped for bms and admins only. also the settlement is for admin only. not even bm can access settlements."

## Target state
| Class | Deal Register (`access_deal_register` DR1 + `access_deal_register_v2` DR2) | Settlement (`settle_deals`) | My deals on agency tracker |
|-------|---------------------------------------------------------------------------|-----------------------------|----------------------------|
| **agent** | ❌ NONE | ❌ NONE | ✅ (via `access_agency_tracker` + `access_daily_activity` + `view_deals` own-scope — untouched) |
| **branch_manager** | ✅ YES | ❌ NONE | ✅ |
| **admin** | ✅ full | ✅ full | ✅ |

## Live grants found (nexus_os.role_permissions, non-trashed, per role × agency)
| role | access_deal_register | access_deal_register_v2 | settle_deals |
|------|:---:|:---:|:---:|
| admin | 3 | 3 | 3 |
| **agent** | **3** | **3** | **3** |
| **branch_manager** | 3 (keep) | 3 (keep) | **3** |
| office_admin | 3 | 3 | 3 |
| super_admin | 1 | 1 | 1 |

## Reconcile set (ARMED — NOT yet applied; waits for Johan's "clean it")
Config `role_defaults` updated so these become off-config over-grants, then the surgical `--keys` filter strips only them:

- **agent** — strip `access_deal_register` (3) + `access_deal_register_v2` (3) + `settle_deals` (3) = **9 rows**
- **branch_manager** — strip `settle_deals` (3) = **3 rows**
- **TOTAL = 12 rows** (soft-delete, reversible via snapshot)

**Command (run on LIVE only on Johan's word):**
```
php artisan corex:reconcile-role-grants \
  --roles=agent,branch_manager \
  --keys=access_deal_register,access_deal_register_v2,settle_deals \
  --apply
```
Dry-run (no `--apply`) reports first; `--apply` writes a snapshot JSON; `--rollback=<snapshot>` is one-command undo.

## ⚠️ OPEN — office_admin (needs Johan's ruling, NOT armed)
`office_admin` currently holds all three (3 each = **9 rows**). Johan's ruling named agent/BM/admin only. Settlement is often a finance/back-office function → office_admin may legitimately need `settle_deals`. **Not included in the reconcile set until Johan rules** whether office_admin counts as "admin" (keep) or a restricted role (strip). Config `role_defaults['office_admin']` currently excludes all three, so a default reconcile would strip them — deliberately held.

## Verification
- Route gates confirmed: settlement = `permission:settle_deals` (DR1 `web.php:573-583`, DR2 `:715-719`); DR2 register = `access_deal_register_v2` (`:825`).
- Agent "my deals" path (`access_agency_tracker`/`access_daily_activity`/`view_deals`) is independent of the stripped keys — verified agents retain it.
- Command `--keys` filter validated on dev-2 (correctly flags only the named keys as over-grants after the config change).
