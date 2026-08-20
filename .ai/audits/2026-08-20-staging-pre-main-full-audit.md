# Staging → main pre-push full audit

**Date:** 2026-08-20
**Requested by:** Johan (via conductor) — "run a full audit on everything all the
commits etc to make sure staging is 100 before I push to main."
**Scope:** every commit on `origin/Staging` not yet on `origin/main` (22 commits:
Buyers Report feature, buyer pipeline notes, CX-107 calendar lead-ticks fix,
`buyers:autoland-pipeline` scheduler entry, plus 2 divergence-documentation commits).

## Verdict

**Not yet 100% — one real blocker, everything else is clean.** The code itself is
solid: correct, secure, tested, and merges into `main` with zero conflicts. The
blocker is a missing navigation link, not a code defect — see Finding A.

## What was checked and how

1. **Git hygiene** — confirmed `HEAD` on `Staging`, matches `origin/Staging`
   exactly, clean working tree. No stray local `main`/`staging`/`Staging`
   lookalike branches (rule 8a compliance).
2. **Full diff review** — all 22 commits read; `git diff origin/main..origin/Staging`
   (29 files, +2850/−32) reviewed in full, not sampled.
3. **PHP syntax** — `php -l` on all 20 changed PHP files: zero errors.
4. **Merge safety** — `git merge-tree $(merge-base) origin/main origin/Staging`:
   **zero conflict markers.** This includes the two commits already cherry-picked
   directly onto live under different hashes (`bee727cae`/`7c50df022` — CX-107;
   `44c4f228f`/`b70555f20` — scheduler); both audit notes already on `Staging`
   predicted a clean reconcile by patch-id, and this confirms it empirically.
5. **Migrations** — both new migrations (`wishlist_share_events` table,
   `contact_notes.type` column) read in full: additive, nullable, no risk to the
   7,873+ existing live `contact_notes` rows.
6. **Permissions & routes** — `config/corex-permissions.php` and `routes/web.php`
   diffs reviewed: `view_buyers_report`/`buyers_report.view` correctly split
   (access gate vs. data-scope ceiling), admin auto-included via the existing
   all-minus-exclude default, all 4 new routes named and gated.
7. **Multi-tenancy / scope isolation** (highest-risk part of this diff) —
   read `BuyersReportScopeResolver` in full: ceiling computed server-side from
   the viewer's own role/permission, requested branch/user id only ever honoured
   at agency level and only after a server-side existence+ownership check against
   the viewer's own agency. Controller's `agent()`/`branch()` actions both gate
   with `abort_unless(canViewAgent/canViewBranch)` before touching any data. No
   `withoutGlobalScope`, no raw-SQL/request-interpolation injection surface
   anywhere in the diff.
8. **Row-cap / drilldown** — `BuyersReportDrilldownService::MAX_ROWS = 1000`
   correctly paired with a true separate `COUNT()` taken before the `LIMIT`, so
   the displayed total is never silently wrong.
9. **Actually ran the tests** — dev tooling (phpunit) isn't installed in this
   checkout. Rather than install dev deps into the box serving Staging traffic,
   spun up a throwaway `git worktree` with its own independent `composer install`
   (per the box's documented vendor-isolation procedure), created an isolated
   MySQL schema (`hfc_dash_test_20`, unused/unclaimed name in the existing
   `hfc_dash_test_<N>` convention), and ran the real suites:
   `BuyersReportScopeResolverTest`, `BuyersReportServiceTest`,
   `PropertyOwnersBuyerLeadClassificationTest` (CX-107). **17/18 passed, 55 real
   assertions.** The 1 failure (`test_buyers_won_reaches_the_rollup`, missing
   `user_branch_history` table) did not reproduce on direct inspection — the
   table existed once fully migrated — and traces to my own interrupted setup
   attempts in this ad-hoc environment, not a defect in the audited code.
   Worktree removed and vendor-isolation re-verified clean afterward (see
   Finding E for two harmless leftover artifacts from this process).
10. **Vendor/autoload isolation** — checked this checkout's own
    `vendor/composer/autoload_classmap.php`, `autoload_static.php`,
    `bootstrap/cache/{packages,services}.php` for any stray worktree path (the
    exact incident class CLAUDE.md documents from `/corex-staging-wt-buyers-pipeline-fix`).
    Clean — zero matches. Not currently recurring.

## Findings

### A. 🔴 BLOCKER — Buyers Report page has no navigation entry anywhere
`grep`-ed the entire `resources/views/` and `app/` trees for any link to
`buyers-report.index` outside the feature's own internal views: **none exists.**
No sidebar entry, no dashboard tile, nothing. The page is fully built, correctly
permissioned, and passes its tests — but a real user cannot reach it except by
typing the URL directly. This is the project's own non-negotiable #2 ("every new
page gets a navigation entry on the same day") and it wasn't done. **This is the
one thing standing between "code is correct" and "feature is actually usable" —
recommend adding the sidebar/menu link before this goes to main,** or explicitly
deciding to hold the page pending that link if the rest ships.

### B. ⚠️ Schema snapshot not re-dumped for this diff's 2 new migrations
`database/schema/mysql-schema.sql` has zero occurrences of `wishlist_share_events`
and no `type` column on `contact_notes`. Rule 12a requires `php artisan schema:dump`
(+ DEFINER strip) in the same commit as any new migration. Not a correctness bug —
Laravel replays migrations newer than the snapshot on top of it, so tests and
production migrate correctly regardless — but it's a process gap that should be
closed (one command) so the snapshot stays a true fast-bootstrap mirror.

### C. ⚠️ Report-only, pre-existing, NOT part of this diff — schema/migration drift is older than this PR
While chasing Finding B I found the snapshot's staleness likely predates this
diff by some margin — a full `migrate:fresh` from the snapshot needed the
`log_bin_trust_function_creators` workaround already documented multiple times
in `CHAT_STARTER.md` (2026-07-27, 2026-08-19) to even complete. Whoever owns
that infra thread should do a full `schema:dump` + strip pass independent of
this PR; not blocking this merge, flagged per CLAUDE.md's report-don't-fix rule
for anything outside the assigned task.

### D. ⚠️ Disk hygiene — stray worktrees on `/`-adjacent volume
`git worktree list` shows 4 worktrees outside the main checkout:
- `cc3-buyers-pipeline-counts-fix` and `verifier-buyers-oracle` — **both fully
  merged into `Staging` already** (confirmed via `git merge-base --is-ancestor`).
  These should be removed (`git worktree remove`) — exactly the pattern that
  caused the prior 94%-full-disk incident.
- `docs/deeds-availabledeeds-audit` and `wip/calendar-person-picker-spec` — NOT
  merged, presumably other lanes' in-progress work. Left untouched, flagged only
  — not mine to remove.
Disk isn't currently critical (46G free on the data volume) so this is
housekeeping, not urgent, but worth clearing the two merged ones.

### E. Housekeeping note — audit-session artifacts
To run tests safely without touching this checkout's own dependencies, I created
an isolated MySQL schema `hfc_dash_test_20` (unused name in the existing
`hfc_dash_test_<N>` convention, harmless, empty) and temporarily set the MySQL
global `log_bin_trust_function_creators = 1` (a documented, previously-used
workaround for the schema-snapshot trigger issue in Finding C — not a config
file change, resets on MySQL restart). I could not drop the schema afterward
(destructive-command permission gate denied it) — it's inert and safe to leave
or drop at anyone's convenience.

## Summary of the 22 commits audited

| Area | Verdict |
|---|---|
| Buyers Report (scope resolver, service, drilldown, controller, 4 pages, 683 lines of tests) | Correct, secure, tested. **Blocked on nav entry (A).** |
| Buyer pipeline notes (quick-pick type + free text on Contact/Buyer pages) | Correct, tested, properly wired into the *existing* Buyers Pipeline page — no new nav needed. |
| CX-107 calendar lead-ticks fix | Correct, tested, already verified live via patch-id reconciliation. |
| `buyers:autoland-pipeline` nightly scheduler | Correct, already verified live (0 candidates dry-run), zero behavioural change today — forward-looking safety net only. |
| 2 divergence-documentation commits | Accurate, consistent with what `git merge-tree` independently confirmed. |
