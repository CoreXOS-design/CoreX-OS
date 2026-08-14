# Deploy-blocker: `corex:sync-permissions --merge-defaults` 1062 duplicate-key

> Logged 2026-07-30 during the MIC → Staging promotion. **Pre-existing, NOT caused by
> the MIC work.** Report-only per Johan — do NOT fix as part of MIC. Needs its own fix.

## Symptom
`scripts/deploy.sh` step 6 runs `php artisan corex:sync-permissions --merge-defaults`
(line ~405). On staging it aborts the whole deploy with:

```
SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry
  'admin-calendar.tile.my_deals-1'
  for key 'role_permissions.role_perms_role_key_agency_unique'
  (SQL: insert into `role_permissions` (agency_id, created_at, permission_key, role, scope, updated_at)
        values (1, ..., calendar.tile.my_deals, admin, ?, ...))
```

Because `deploy.sh` is `set -euo pipefail`, the non-zero exit **aborts the deploy at
step 6**, leaving the site in **maintenance mode (503)** with build/cache/queue/up
never run. i.e. this bug bricks any deploy that reaches it until an operator finishes
forward manually or rolls back.

## Evidence it is pre-existing / recurring
`/var/log/hfc-deploys.log` on the staging host shows the **identical** error on prior
deploy attempts dated **2026-07-20** (two runs) as well as the 2026-07-30 run. The
duplicate key is always `admin-calendar.tile.my_deals-1` (role=admin,
permission_key=`calendar.tile.my_deals`, agency_id=1).

## Root-cause hypothesis (unconfirmed — for whoever fixes it)
`SyncPermissions::mergeRoleDefaults` (`app/Console/Commands/SyncPermissions.php`) is
meant to be additive: diff the config-expected role→key set against existing
`role_permissions` rows and INSERT only the missing keys. The 1062 means it tried to
INSERT a `(role, permission_key, agency_id)` tuple that **already exists** — so the
"already present" diff is not excluding at least `calendar.tile.my_deals` for
admin/agency 1. Likely one of:
- key normalization / casing mismatch between the config key and the stored
  `permission_key` (the error label `admin-calendar.tile.my_deals-1` is a composite —
  confirm the diff compares the same normalized form the unique index uses);
- the "existing keys" lookup is not scoped by the same `(role, agency_id)` the insert
  uses, so a present row isn't seen as present;
- a config default lists `calendar.tile.my_deals` twice / under multiple roles that
  collapse to the same unique tuple.

## Suggested fix direction (do NOT do as part of MIC)
Make the merge idempotent at the write: `insertOrIgnore` (or `upsert` on
`role_perms_role_key_agency_unique`) instead of a plain `insert`, AND/OR fix the diff
to exclude existing `(role, permission_key, agency_id)` tuples. Add a regression test
that runs the command twice and asserts the second run is a no-op. Until fixed, staging
AND production deploys will keep failing at step 6.

## Interim operator workaround (used for the 2026-07-30 MIC deploy)
Complete the deploy forward, skipping this one command: run steps 7–11 manually
(`npm ci && npm run build` → `optimize:clear` + re-cache → `systemctl reload php8.2-fpm`
→ `queue:restart` / supervisor restart → `php artisan up`). Safe here because the merge
is additive and aborted atomically (existing `role_permissions` untouched; only the one
new role-default key was not merged). Related: [[permission-drift-role-permissions-vs-config]].

## Files
- `scripts/deploy.sh` — step 6, the failing `corex:sync-permissions --merge-defaults` call.
- `app/Console/Commands/SyncPermissions.php` — `mergeRoleDefaults`.
- `role_permissions` table, unique index `role_perms_role_key_agency_unique`.

## RESOLVED — 2026-07-31, commit `695c99f1` (QA1)
Confirmed root cause: `RolePermission` uses **SoftDeletes**, and the unique index is
`(role, permission_key, agency_id)` with **no `deleted_at`** — a soft-deleted row still
holds the slot. The diff queried live rows only, so a trashed key read as "missing" and
the plain `insert()` 1062'd on the trashed row. (NULL-agency tuples don't collide — MySQL
treats NULLs as distinct in a unique index — so only NON-null-agency trashed-only tuples
trigger it; QA1 had 13.)

Fix in `mergeRoleDefaults`: (1) existence diff now uses `RolePermission::withTrashed()`
so trashed keys are seen and excluded from "missing" (neither re-inserted nor resurrected);
(2) write is `RolePermission::insertOrIgnore()` as a defence-in-depth safety net. Purely
additive — no live or trashed row is dropped/altered.

Verified on QA1: command completes cleanly (exit 0, no 1062), second run a clean no-op,
`role_permissions` unchanged (live 4087 / trashed 22). Reproduced+fixed under a rolled-back
txn on `branch_manager/settle_deals/agency 1` (plain insert → 1062; insertOrIgnore → 0 rows,
no error). Not yet promoted to Staging/production — travels with the next promotion.
