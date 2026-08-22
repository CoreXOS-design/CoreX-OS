# Cross-agency isolation fixes — promoted to live

**Date:** 2026-08-20

## What happened

Same-day audit → fix → QA2 → Staging → live promotion of the cross-agency
isolation fix set. Full finding-by-finding record, live re-verification results,
and the diverged-branch recovery detail live in
`.ai/audits/cross-agency-isolation-audit-2026-08-20.md` ("Round 4 — Live
promotion" section). This doc is the short patch-id pointer, matching the
convention of the other `*-live-promotion.md` docs in this folder.

## Patch-id note

`/corex`'s local `main` had diverged from `origin/main` by 4 commits at deploy
time. All 4 were verified via `git patch-id --stable` to be content-identical to
4 commits already on `origin/main` under different hashes (same pattern already
documented today in `2026-08-20-buyers-report-print-pdf-live-promotion.md`) —
`fix(contacts): Seller filter...`, `docs(audit): record CMA freehold...`,
`fix(market-reports): freehold CMA...`, `fix(website-api): scope public
listing...`. Resolved via `checkout` + `branch -f` (never `reset --hard`), run
by Johan directly since that operation is banned inside `/corex` for any
automated tool.

## What's live now

The 10-commit cross-agency isolation fix set (findings C1, C2, C3, H1, H2, M1,
M2, `Docuperfect\Template`, and 3 hygiene items), plus the separately-found
`ActivityDefinition` live-only bug (already on `main`, rode along in this same
sync). Migrations applied cleanly, backfilling 10 real `tv_access_codes` rows.
Reference-data and permission syncs were no-ops (this fix set adds neither).
All 5 spot-checks re-verified against the real production database
post-deploy, wrapped in a rolled-back transaction — zero permanent writes,
confirmed via a residual-data and stray-queued-job check afterward.

## Rollback point

Tag `live-pre-cross-agency-isolation-20260820-205643` (pushed to origin) +
full `mysqldump` of `nexus_os` at
`/mnt/HC_Volume_103099143/corex-backups/nexus_os-pre-deploy-20260820-205643.sql.gz`
(139MB, gzip-verified, 490 tables confirmed) — on the data volume, not `/`,
per the box-wide disk-hygiene rule.
