# AT-506 — RMCP v2 for HFC: hand-off to Andre (live)

**Status (22 Oct 2026):** built, on **QA1** and on **Staging**, with the v2 **draft** loaded for HFC on both. **Nothing is on live.** Live work of any kind (code or data) only on Johan's explicit order for that exact action. Johan said Elize approves v2 on live, so on live the importer is run as a **draft only**.

## What it is
`database/data/rmcp/v2.md` is the approved RMCP v2 text (agency-neutral; every per-agency fact is a `{{merge field}}`). `php artisan rmcp:import-version <agency>` loads it into an agency as a NEW **draft** version 2 — dry run unless `--apply`, idempotent (version 2 already there → the version is left exactly as it is), never touches an existing version. Elize approves the draft in RMCP Manager (`RmcpController::approve`: board approval PDF, effective date, next review) — that supersedes v1, marks the old staff acknowledgements superseded and sends active staff an in-app notice. Spec: `.ai/specs/compliance.md` → "RMCP v2 loaded from the approved text (AT-506)".

## Commits
**Staging (`origin/Staging`, deployed 22 Oct 12:15, `0e6ba9aee` → `293c43a7f`):**
| Staging commit | = QA1 commit | What |
|---|---|---|
| `815e3d69d` | `d047b0d48` | all code, the v2 text, the one migration, tests, spec |
| `293c43a7f` | `b8ab1843b` | an agency can state its offices / who is responsible (wins over branch records); stated values can refer to other fields; `--var` also fills variables on an existing draft |
| "AT-506: schema snapshot" (see `git log origin/Staging`) | — | `database/schema/mysql-schema.sql` regenerated with `scripts/schema-dump.sh` on the Staging base (test-only file) |
| "AT-506: hand-off" | — | this file |

**QA1 (`origin/QA1`):** `d047b0d48`, `2e22bb9c9` (schema snapshot), `2e5a474ab` (hand-off + CHAT_STARTER), `b8ab1843b` (offices override), then the final hand-off update.
The same work is on origin as branches `at506-rmcp-v2-load-2026-10-22` (QA1 base) and `at506-staging-promo-2026-10-22` (Staging base).

**For live: take the two CODE commits only** (`815e3d69d` + `293c43a7f` from the Staging line, in that order). Do not take the schema snapshot (regenerate it with `scripts/schema-dump.sh`, Standard −1b — never merge it by hand) or CHAT_STARTER. Rollback points on Staging: tag `staging-pre-at506-2026-10-22` (→ `0e6ba9aee`, pushed), deploy tag `deploy-staging-20261022-121348` (local), DB dump `/mnt/HC_Volume_103099143/corex-backups/pre-deploy/staging/hfc_staging-pre-deploy-20261022-121348.sql.gz`.

## Migration (1, data only — no table or column changes)
`2026_11_28_100000_register_rmcp_version_approved_notification` — adds the `rmcp.version_approved` row to `notification_event_types` (in-app only). Idempotent. Applied on QA1 and Staging. Without it the re-sign notice is silently not sent. `NotificationEventTypeSeeder` carries the same row.

## Files touched (so a conflict is easy to read)
`app/Console/Commands/Compliance/RmcpImportVersion.php` (new) · `app/Services/Compliance/{RmcpVersionImporter,RmcpReSignService}.php` (new) · `RmcpVariableResolver.php` · `RmcpController.php` · `CompliancePdf.php` · `app/Models/Compliance/{RmcpSection,RmcpVersion}.php` · `routes/web.php` (one POST route) · `resources/views/compliance/{pdf/version,rmcp/show,rmcp/variables}.blade.php` · `scripts/html-to-pdf.mjs` (opt-in footer words) · `database/seeders/NotificationEventTypeSeeder.php` · `database/data/rmcp/v2.md` (new) · tests `RmcpVersion2LoadTest` (new, 16) and `CompliancePdfDownloadTest` (footer assertion).

## The importer command for live (HFC = slug `hfc-coastal`)
Run as the web user, never root. **Dry run first** (no `--apply`); read the BLANK warnings; then the same line with `--apply`.
```
cd /corex && su -s /bin/bash www-data -c "HOME=/tmp php8.2 artisan rmcp:import-version hfc-coastal \
  --var='agency.area_of_operation=KZN South Coast and North Coast' \
  --var='agency.branches_statement=one office, Shelly Beach, to which all agents are attached; no branches or subsidiaries in or outside South Africa' \
  --var='agency.branches_and_responsible=Shelly Beach: {{compliance_officer.full_name}} (Compliance Officer)' \
  --var='profile.active_leases=55' \
  --var='profile.avg_monthly_rent=R 9,384'"
```
`--var` sets a variable only when the agency has none — it never overwrites what the agency has set. The two `profile.*` lines are Johan's figures (55 leases; R 9,384 = average of the 44 live rows with a rent in the reOS import) and win over what CoreX counts; drop them if live already holds the real lease figures. Output ends with the draft's view and PDF links.

## Live steps (ONLY on Johan's explicit order, in this order)
1. Code reaches live by the normal route (`git -C /corex merge --ff-only origin/<branch>` — not a fast-forward → STOP; never commit/cherry-pick/rebase inside `/corex`; tag first; migrate; reload php-fpm). One data-only migration.
2. Johan fixes the company profile on live **before Elize approves**: the Compliance Officer's name on the appointment reads **"Elize Reicel"** (prints in section 28 and in the offices/responsible line, which follows the CO on file), and `agencies.trading_name` holds **"Johan and Elize Properties T/A"** (the legal-name half). Reported only; not changed by this work.
3. Dry run, then `--apply` (draft). Check the list below.
4. Elize reviews/prints and approves in RMCP Manager (permission `approve_rmcp`, board approval PDF, effective-from today or later, next review).

## Settled by Johan (22 Oct)
HFC has ONE office, Shelly Beach; the Ballito and Southbroom branch rows are not offices — hence the stated offices sentence above (other agencies keep the "branches with an address" rule). The 55 leases and R 9,384 stand.

## Still true / for information
- The cover shows "FIC registration number … · PPRA FFC …" on every RMCP version (v1 too); the RMCP PDF footer reads "<agency> · RMCP v<n> · page x of y · Initials ____" for every version.
- The staff e-sign step shows Annexure B (with its blank signature lines) as the declaration.
- Section 28 says "(the Company has no branches or subsidiaries outside South Africa)" — the approved HFC wording, not a merge field; another agency edits its own draft.
- Staging shows the same draft (version id 5) at `https://staging.corexos.co.za/corex/compliance/rmcp-manager/5` — a Staging check only; Staging is not live.

## Check list after the draft exists on live
- `/corex/compliance/rmcp-manager/<id>` shows 39 sections, tables in sections 4 and 25, the Approval block with blank approval fields; v1 still active.
- `/corex/compliance/rmcp-manager/<id>/pdf` downloads A4, 14 pages, footer "Home Finders Coastal · RMCP v2 · page x of 14 · Initials ____", no `{{` and no `[INSERT`, the offices line reads "one office, Shelly Beach, to which all agents are attached; no branches or subsidiaries in or outside South Africa".
- `/corex/compliance/rmcp-manager/variables` has an "Add variable" form.
- After Elize approves: v1 reads "superseded", every active user has a "New RMCP version — please acknowledge" notification, the staff register shows everyone "not started" on v2.

## Rollback
Until approved the draft changes nothing anyone sees except in RMCP Manager: leave it unapproved. To remove it (live: only on Johan's order): soft-delete version 2 and its sections (`deleted_at`); the importer can then be run again. After approval there is no undo of the supersede; approve a corrected v3 instead.
