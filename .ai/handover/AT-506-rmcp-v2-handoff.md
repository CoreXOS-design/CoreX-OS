# AT-506 — RMCP v2 for HFC: hand-off to Andre (Staging, then live)

**Status (22 Oct 2026):** built and deployed on QA1 at `d047b0d48`; the v2 **draft** for agency 1 is on QA1 (version id 5). Johan checks it in Chrome, then sends the Staging GO. **Nothing is on Staging or live yet.** Live work of any kind only on Johan's explicit order.

## What it is (one paragraph)
`database/data/rmcp/v2.md` is the approved RMCP v2 text (agency-neutral; every per-agency fact is a `{{merge field}}`). `php artisan rmcp:import-version <agency>` loads it into an agency as a NEW **draft** version 2 — dry run unless `--apply`, idempotent (version 2 already there → no change), never touches an existing version. Elize approves the draft in RMCP Manager (`RmcpController::approve`: board PDF, effective date, next review) — that is what supersedes v1, marks the old staff acknowledgements superseded and sends staff an in-app notice. Spec: `.ai/specs/compliance.md` → "RMCP v2 loaded from the approved text (AT-506)".

## Commits
| Commit | What | Staging |
|---|---|---|
| `d047b0d48` AT-506: RMCP v2 loads into CoreX as a draft… | all code, the v2 text, the one migration, tests, spec | **cherry-pick this** |
| follow-up "AT-506: schema snapshot" | `database/schema/mysql-schema.sql` (carries the new reference row) | **do NOT cherry-pick** — regenerate on Staging with `scripts/schema-dump.sh` (Standard −1b: never merge the snapshot by hand) |
| follow-up "AT-506: hand-off + CHAT_STARTER" | this file + a CHAT_STARTER entry | skip (CHAT_STARTER conflicts; docs only) |

The branch is also on origin as `at506-rmcp-v2-load-2026-10-22`.

## Migration (1, data only — no table or column changes)
`2026_11_28_100000_register_rmcp_version_approved_notification` — adds the `rmcp.version_approved` row to `notification_event_types` (in-app only). Idempotent. Without it the re-sign notice is silently not sent (the gateway needs the row). `NotificationEventTypeSeeder` carries the same row.

## Files touched (so a conflict is easy to read)
`app/Console/Commands/Compliance/RmcpImportVersion.php` (new) · `app/Services/Compliance/RmcpVersionImporter.php` (new) · `app/Services/Compliance/RmcpReSignService.php` (new) · `RmcpVariableResolver.php` · `RmcpController.php` · `CompliancePdf.php` · `RmcpSection.php` · `RmcpVersion.php` · `routes/web.php` (one POST route) · `resources/views/compliance/{pdf/version,rmcp/show,rmcp/variables}.blade.php` · `scripts/html-to-pdf.mjs` (opt-in footer words) · `database/data/rmcp/v2.md` (new) · tests `RmcpVersion2LoadTest` (new, 14) and `CompliancePdfDownloadTest` (footer assertion).

## Staging steps (after Johan's GO — one lane at a time)
1. Tag Staging first (`git tag staging-pre-at506-<date>`), then cherry-pick **only `d047b0d48`** onto a branch that tracks `origin/Staging` (worktree, not `/corex-staging` by hand). Conflicts: resolve in the worktree.
2. Regenerate the snapshot: `scripts/schema-dump.sh`; commit it.
3. Deploy Staging the usual way (pull → `php artisan migrate --force` → `deploy:sync-reference-data` → clear caches → reload php-fpm → restart worker). One migration, data only.
4. Dry run, then apply the DRAFT (below) for agency 1. Check `/corex/compliance/rmcp-manager` shows v2 as a draft and v1 still active.

## The importer command (agency 1 = HFC, slug `hfc-coastal`)
Always dry run first (no `--apply`); read the BLANK warnings; then apply. Run as the web user, never root.
```
cd <checkout> && su -s /bin/bash www-data -c "HOME=/tmp php8.2 artisan rmcp:import-version hfc-coastal \
  --var='agency.area_of_operation=KZN South Coast and North Coast' \
  --var='agency.subsidiaries_statement=no subsidiaries in or outside South Africa' \
  --var='profile.active_leases=55' \
  --var='profile.avg_monthly_rent=R 9,384'"            # dry run
# same line + --apply  → creates the draft
```
`--var` sets a variable only when the agency has none; it never overwrites. **The two `profile.*` lines are overrides** — see "Open points" 3 below; on live drop them if live already holds the real lease figures in CoreX, or change the numbers to what Johan confirms.

## Live (ONLY on Johan's explicit order, for that exact action)
Johan said Elize approves on live. On live the importer is run as a **DRAFT only**, then Elize reviews/prints and approves it herself in RMCP Manager (permission `approve_rmcp`, board approval PDF, effective-from today or later, next review). Same command as above against `/corex` once the code is on live through the normal ff-only route (`git -C /corex merge --ff-only origin/<branch>` — if not a fast-forward, STOP). Anything on live must be pushed to origin first.

## Open points (Johan decides — none blocks the deploy)
1. **Company profile fixes (Johan, on live, before Elize approves):** the Compliance Officer's name on the appointment reads **"Elize Reicel"** (prints in section 28 and the CO line); `agencies.trading_name` holds **"Johan and Elize Properties T/A"** (the legal-name half). Reported only; not changed.
2. **Offices.** The approved text says one office (Shelly Beach). CoreX has three HFC branch rows: Shelly Beach (no address), Ballito (address just "Ballito") and Southbroom (full address). The rule is "offices = branches that have an address", so the draft prints **"two offices, Southbroom and Ballito…"** and section 28 lists both with a blank line for each responsible person. If that is not true, either give the Shelly Beach branch its address / remove the address from branches that are not offices, or state the sentence by hand with the variable `agency.branches_statement` (Add variable on the RMCP variables screen).
3. **The lease figures.** Johan's 55 leases matches nothing on QA1: the reOS import runs hold **46** rows (30 Active + 15 Expiring + 1 Draft; **45 live**), the rent roll **57**. The average rent used, **R 9,384**, is the average of the 44 live rows that carry a rent (R 9,384.19, `rental_take_on_import_rows`, run 4/6). QA1 itself has 28 active leases (avg R 9,985). The uploaded reOS files themselves were not kept on disk.
4. **Cover and print:** the cover now shows "FIC registration number … · PPRA FFC …" for every RMCP version (v1 too). The staff e-sign step shows Annexure B (with its blank signature lines) as the declaration.
5. **Section 28 wording** "(the Company has no branches or subsidiaries outside South Africa)" is the approved HFC sentence and is not a merge field; another agency edits its own draft.

## Check list after the draft exists
- `/corex/compliance/rmcp-manager/<id>` shows 39 sections, tables in sections 4 and 25, the Approval block with blank approval fields.
- `/corex/compliance/rmcp-manager/<id>/pdf` downloads A4, 14 pages, footer "Home Finders Coastal · RMCP v2 · page x of 14 · Initials ____", no `{{` and no `[INSERT` anywhere.
- `/corex/compliance/rmcp-manager/variables` has an "Add variable" form.
- After Elize approves: v1 reads "superseded", every active user has a "New RMCP version — please acknowledge" notification, and the staff register shows everyone "not started" on v2.

## Rollback
Until approved the draft changes nothing anyone sees except in RMCP Manager: leave it unapproved. To remove it (live: only on Johan's order): soft-delete version 2 and its sections (`deleted_at`), then the importer can be run again. After approval there is no undo of the supersede; approve a corrected v3 instead.
