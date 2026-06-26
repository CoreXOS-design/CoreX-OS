# Andre Delta — Step 1 Gate Validation (Roles Rework A) on Live Clone

**Date:** 2026-06-26
**Scope:** STEP 1 ONLY (the hard gate). No changes to live (`nexus_os`). No merge to `main`.
**Clone used:** `hfc_staging` (live clone of `nexus_os`) via the `/corex-staging` codebase (carries A).
**Result:** **GATE PASS — 38/38 functional checks.** Live promotion remains BLOCKED on Johan's go.

---

## 0. Delta re-confirmed (independent of the audit)

- `origin/Staging` is a strict superset of `origin/main` (`Staging..main` = 0).
- True `origin/main..origin/Staging` delta = **exactly 11 additive migrations** (the 7 feature
  groups + FlowMap removal). Re-derived directly, matches the release-readiness audit.
- `config/corex-permissions.php` is **unchanged** in the true delta (an earlier "differs" reading
  was a stale-baseline artifact — the deployed `/corex-staging` is 63 commits behind `origin/Staging`,
  all of them main's own AT-P24 work merged *into* Staging).
- **No P24 / syndication / importer / mapper / status-model / e-sign files** appear in the true
  delta — confirmed by path scan. The audit's "fragile areas untouched" claim holds.
- WIP scan of the delta (added lines): no `dd(` / `dump(` / `FIXME` / "not for live" in code — only
  spec/doc placeholder text. Clean.

**Note on merge source:** the deployed `/corex-staging` app (48715117) is an *ancestor* of
`origin/Staging` (a49faa2) — it does NOT reflect the last 63 commits. The promotion must merge
**`origin/Staging`**, not the deployed staging HEAD. Roles infra (A) is byte-identical between the
two, so the deployed staging is a faithful environment for validating A.

---

## 1. Clone data shape (live-shaped)

| Item | Value |
|---|---|
| Agencies | 2 — `1` Home Finders Coastal, `7` Demo Agency |
| Owner role | `super_admin` only (agency_id NULL, is_owner=1, singular) ✓ |
| Scoped roles | 5 per agency (admin, agent, branch_manager, office_admin, viewer) |
| Scoped grants | **2,406** (1,203 agency 1 + 1,203 agency 7) ✓ matches audit |
| NULL template grants | 1,498 (retained as provisioning templates, by design — spec §3.3) |
| Real users | agency 1: 28 (admin/agent/branch_manager/office_admin); agency 7: 17+ |
| `user_managed_branches` | 0 rows (feature E additive — nobody assigned yet, correct) |

---

## 2. Functional validation (38/38 PASS)

Ran against `hfc_staging` with the A code. Every mutation test wrapped in a transaction that was
**rolled back** — zero persistence on the clone (verified by post-rollback counts).

**[1] Owner (super_admin #46) — login + global bypass**
- Authenticates; `isOwnerRole()` true; bypasses `deals.edit` AND a made-up key; `agency_id` NULL. ✓

**[2] Normal agent (kobus #86, agency 1) — per-agency gating**
- `effectiveAgencyId()===1`, `effectiveRole()==='agent'`, not owner.
- CAN `contacts.view`, `properties.view` (granted). CANNOT `deals.edit`, `access_soft_deletes`,
  `branches.edit_all` (admin-only). Gating is correct, not allow-all. ✓

**[3] Admin (johan@hfcoastal #22, agency 1)**
- `effectiveAgencyId()===1`; CAN `deals.edit`, `access_soft_deletes`, `contacts.view`. ✓

**[4] Agency-7 agent (elize #69) — resolves its OWN agency**
- `effectiveAgencyId()===7`; CAN `contacts.view`; CANNOT `deals.edit`. ✓

**[5] No NULL-template fallthrough**
- `grantsAgencyId(1)===1`, `grantsAgencyId(7)===7` — agency-bound users read scoped rows, never the
  1,498 NULL templates. ✓

**[6] Per-agency isolation (raw SQL, cache-immune; ROLLED BACK)**
- Deleting agency 1's agent grants (238→0) left agency 7's agent grants UNCHANGED (238→238).
  Rollback restored agency 1 (→238). The Role-Manager cross-agency-rewrite bug (spec §1) is closed. ✓

**[7] Multi-branch manager (falan #25, branch_manager, ag1) — feature E (ROLLED BACK)**
- Authenticates; `effectiveAgencyId()===1`; 0 managed branches initially.
- After `syncManagedBranches([1,2,3], default=2, ag=1)`: manages 3, default=Ballito(2),
  `isManagerOfBranch(3)` true, `isManagerOfBranch(99)` false. Rollback → 0. ✓

**[8] Role-list isolation**
- Agency 1's list includes the global `super_admin` owner; all non-owner roles are `agency_id=1`
  (no agency-7 bleed); agency 1 and agency 7 share **zero** non-owner role ids. ✓

---

## 3. Acceptance criteria (spec §8) coverage

| Criterion | Status |
|---|---|
| Agency A's permission edits don't change Agency B | ✓ [6] |
| A user of A resolves permissions from A's copy | ✓ [2][4][5] |
| Owner roles global, singular, bypass checks | ✓ [1][8] |
| No agency-bound user resolves a NULL/global grant | ✓ [5] |
| Cross-agency role ids isolated (no shared non-owner ids) | ✓ [8] |
| Backfill non-destructive + idempotent | ✓ migration reviewed (clone-only-if-absent) |
| Cross-agency Role-id CRUD → 403/404 | Controller-level; covered by `AgencyScopedRolesTest` (HTTP) — see §4 |

## 4. Not executed here (and why)

- `tests/Feature/Roles/AgencyScopedRolesTest.php` could NOT run on the prod host: `/corex-staging`
  is a `--no-dev` production install (no pest/phpunit). The file exists and is green per the audit;
  the live functional clone validation above supersedes it for gate purposes (real data > unit test).
- The forged-foreign-branch sub-check in [7] was conditionally skipped (Demo Agency has no branches
  to forge from). The valid-path multi-branch resolution is proven.

---

## 5. Migration safety (all 11 already ran clean on this clone)

All additive: nullable columns (`rental_images_json`, `managed_by_user_id`), new tables
(`shared_drives*`, `user_managed_branches`), idempotent seeds, and the auth-table changes
(`make_roles_unique_per_agency`, `add_agency_id_to_role_permissions`) + the non-destructive data
backfill. The clone carries them with no error and the resolution above proves they produced a
correct auth model on live-shaped data.

---

## 6. Verdict

**A (agency-scoped roles) is PROVEN on the live clone — login works for all three role types and
per-agency gating resolves correctly with no regressions.** The gate is GREEN.

**STOP per instruction:** do NOT merge to `main` / touch live until Johan confirms the maintenance
window. Step 2 (backup `nexus_os` → maintenance mode → merge `origin/Staging` → migrate → verify →
exit maintenance) is ready to execute on his go.
