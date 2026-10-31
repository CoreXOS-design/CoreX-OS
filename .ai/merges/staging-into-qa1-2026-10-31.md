# Staging brought down into QA1 — 31 Oct 2026

Base: `origin/QA1` 5919db359 (then cc1's AT-452 S3-S5 merged in at the end, see bottom) + `origin/Staging` b4539b3b8.
Merge-base 54cbfce6d. Staging was 190 commits ahead, QA1 413. 83 files conflicted.
Rule (Johan): Staging is the base truth for live-bound work; QA1's not-yet-promoted work stays; nothing dropped silently.
Method: every conflict was resolved per hunk (git merge-file by index stage), never by taking a whole file, so a
non-conflicting hunk from either side survives. Both sides' migrations are kept; the schema snapshot was regenerated
from scratch with `scripts/schema-dump.sh` (all ~1,900 migrations from zero: clean, no clash).

## Why most of the conflicts were not real

QA1 is where CoreX Desk, the Word-import/CDS work, the letterhead, the RMCP board letter and the FICA document
remove/restore were first built. Staging got them by cherry-pick (new hashes) and then added its own later work on
top (Desk 30-minute inactivity + consent/masking fixes, Word-import security hardening, letterhead VAT/logo hardening,
first-page header/footer, AT-478 guided-tour markers). So the "add/add" files were the same feature in two versions,
and Staging's version is the later one. QA1 had no Desk/import/letterhead/RMCP/FICA code that Staging lacks
(checked by commit subject and by diffing each file Staging-vs-QA1: every QA1-only line was an older version of a
line Staging had improved).

## Conflicts by area (83) and decision

| Area | Files | Decision | Why |
|---|---|---|---|
| CoreX Desk (controllers, service, model, config/desk, config/reverb, resources/js/desk/*, Chrome-extension desk-*, console + sessions views, desk-client + tour-theme partials, 9 Desk tests, spec corex-desk.md, onboarding-copy Desk text) | 36 | Staging's side | Same feature; Staging is later (inactivity timeout, consent/identity/masking audit fixes, AT-510 grants + menu in Tools). QA1's only extra commits were schema-snapshot regenerations (redone below). |
| Word import / CDS / e-sign page look (DocumentImporterController, TemplateController, Template, CdsParser, CdsRenderer, SignatureBlock, a4-page-styles, corex-document.css, paginate-check, print-shell-pdf, 3 SigningView tests, 2 specs) | 12 | Staging's side per hunk | Staging carries the 28 Oct audit hardening (BladeSafe, zip-bomb guard, signature-block default only on first save, first-page header/footer variant). QA1-only hunk kept automatically: TemplateController "reused CDS draft gets the template's signing_parties" (893709982). |
| Letterhead (AgencyLetterhead, component, test, spec) | 3 | Staging's side | VAT only for VAT-registered agencies, logo path jailed to storage/app/public. |
| Compliance — RMCP / FICA (RmcpBoardAuditLog, FicaController, submitted-data view, FicaDocumentRemoveTest, compliance spec) | 4 + 1 | RmcpBoardAuditLog: QA1's side (keeps `StampsOnBehalfOf`, the on-behalf-of audit column QA1's migration added to that table). FicaController, view, test, spec: Staging's side | Staging wraps unlink + audit row in one transaction. NOTE: git auto-merged a second copy of QA1's audit row into `removeDocument` outside Staging's transaction (two ledger rows per remove); caught by FicaDocumentRemoveTest, FicaController is now byte-identical to Staging's. |
| Rental applications (rental-application-field, create, show views) | 3 | Staging's side | Staging adds guided-tour hooks and keeps its broader `data-desk-mask` regex (bank/ID/tax/passport fields masked in a Desk session); QA1's Co-applicant (AT-517) content merged around them. Same call as the 30 Oct promotion. |
| Road to Sold (summary bar, index, settings index, targets, buyer-pipeline-fit-check.mjs) | 5 | QA1's side (the redesign), with 6 of Staging's guided-tour hooks grafted on where the same element still exists | Staging only changed these screens to add `data-tour` hooks (AT-478 tours for the OLD Road to Sold layout). See open item 2. |
| Navigation: sidebar, routes/web, routes/api, routes/console, layouts corex + corex-app, company-settings, bootstrap/app, SidebarFeatureCoverage, RealEstateMenuTabsTest, sidebar-nav-allowlist.json | 11 | Staging's side for routes, Desk links, Deals `@feature('deals')` switch, `rollout:release` schedule, "New and coming" link; QA1's side for the two layout `@endunless` (Road to Sold pop-up embed mode) and the menu-tab test count; BOTH kept in bootstrap/app (QA1 `KeepEmbedOnRedirect` + Staging `RecordRolloutFeatureUse`) and SidebarFeatureCoverage (QA1 `corex.alerts.*` + `corex.desk.*`; Staging `corex.new-and-coming.index`); allow-list JSON merged key by key (QA1 254 keys, Staging 247, union 254, no key removed by either side). | Staging made Deals a switchable feature (AT-478), so the old unconditional `deals-v2.*`/`deals-dr2.*`/`agent.deals.*`/`admin.deals*` coverage lines were dropped by Staging and stay dropped; the Deals menu now sits inside `@feature('deals')`. |
| Market Intelligence claims (`Property.php`) | 1 | QA1's side | `NOT_OWN_STOCK_STATUSES` constant is QA1's MIC-claims work; Staging lacks it. |
| Schema snapshot | 1 | Regenerated from scratch with scripts/schema-dump.sh | Superset of both. |
| Docs: CHAT_STARTER (4 hunks), agency-onboarding-setup, documents-menu-tabs specs | 3 | Both histories kept in CHAT_STARTER (exact duplicates dropped; file is now ~1,095 lines, far over the 350-line target — needs a prune by whoever owns it); onboarding-setup + documents-menu-tabs: QA1's side (carries "How my signers sign" tab and the Road to Sold / property-identity "not in the wizard" ledger) | |
| Specs: cds-reimport-from-word, compliance, agency-letterhead, corex-desk | 4 | Staging's side | Follow the code. |

Files Staging deleted that QA1 had not touched since the base (dead code, merged as deletions): AdminDashboardController,
CompanySummaryController, Admin\AgentPerformanceController and their 5 views (b34cd59de). Their route names are
still answered by redirects in routes/web.php.

## Fixes the merge itself needed (not conflicts)

1. `FicaController::removeDocument` — duplicate audit row (above).
2. `rental-work-orders/show.blade.php` — Staging's `data-tour="rmnt-wod-next"` sat between the Next bar's `class` and
   `data-next-step`, which QA1's WorkOrderScreenStatesTest reads by position (16 tests). Moved the attribute to after
   `data-next`; tours still find it by attribute.
3. `RentalFrontHalfDecisionsTest` — two regexes assumed `name="start_date" value=`; Staging's tour marker now sits between
   them. The page still prints the right date. Regexes now tolerate attributes in between.

## Test results

Run through `scripts/lane-test.sh`, per directory. "Pre-existing" = fails identically on an untouched worktree of
`origin/QA1` (5919db359) and/or `origin/Staging` (b4539b3b8).

| Area | Result on merged tree | Failures |
|---|---|---|
| CoreX Desk | 171 pass | none |
| Rental applications | 777 pass, 4 fail | 2 pre-existing on BOTH (CustomFieldConsumption "empty custom field", ReviewFieldDisplayConfig "hidden empty field"); 1 fixed (fix 3 above; failed on Staging alone); 1 = AT-482 audit-trail test, an order leak: passes alone (7/7) |
| Attorney portal | 66 pass | none |
| Compliance (RMCP, FICA, policy) | 336 pass, 3 fail | 1 pre-existing on both (RMCP "signed letter really prints with the real printer" — needs Chromium/Puppeteer, not available in the test worktrees); 2 were fix 1, now pass |
| Property identity + prospecting + claims | 605 pass, 3 fail | all 3 pre-existing on both (MicComposeMultiSeller x2, ProspectingImportPricelessListing) |
| MIC / Matching / Market Intelligence | 78 pass, 1 fail | pre-existing on both (RentalStatusAndIncompleteDataMatching "sql prefilter agrees with scorer") |
| Fault flow (+ reports, types) | 667 pass, 21 fail | 5 pre-existing on QA1 (AgencyDisplayNameClass, AuthorityCost, OwnerContractorWalkFixes, SupplierDirectoryList x2); 16 WorkOrderScreenStates were fix 2, now pass |
| Maintenance / work orders / job cards | 1,077 pass, 9 fail | all 9 pre-existing on QA1 (CrewParts/CrewStagedList/LineFollowUps/VariationFlow/JobCardNightFixes/JobCardFollowUps) |
| Road to Sold | 817 pass, 9 fail | all 9 pre-existing on QA1 (LaneThreeIntegration x3, OutcomeSettings x2, ParkedAndAssistant, RoadToSoldPage x2, RtsListItem) |
| Documents + e-sign (Docuperfect, SigningView, Documents) | 1,182 pass, 4 fail | all 4 are PropertyFieldsFromRecordTest: fail on Staging alone, pass on QA1. Cause confirmed: the fixture stores raw Blade (`{{ $property_district ?? '' }}`) as the template's editor HTML and Staging's BladeSafe hardening (untrusted text must never be Blade) now makes that inert. Real builder HTML carries spans, not raw Blade, so this is a stale fixture, not a product fault — but it is Staging's behaviour, so it is listed, not changed. |
| Navigation + Features | 292 pass, 6 fail | all fail on QA1 and/or Staging alone with the same lists: SidebarNavMapping (deal-distribution-rules MULTI_MATCH on both; possible-matches NO_ITEM on QA1), TabHubSwitchAudit (the same 20 routes as QA1 alone), FeaturePermissionOwnership (attorney_portal, desk — both sides), FeatureRolloutBoard + FeatureRolloutPlans (Staging alone), SidebarFeatureToggles "reports group" (both) |

`php -l` on all 204 changed PHP files: clean. `view:cache` compiles every Blade view. `route:list` and `route:cache`
(duplicate route names) clean. `scripts/dev-check.ps1` is PowerShell and cannot run on this box.

## Open items / calls for Johan or Andre

1. **Desk menu position, Deals switch** — nothing to decide; both already agreed in Staging.
2. **Guided tours for the OLD Road to Sold screen (AT-478, Andre's QA2 work).** QA1's Road to Sold was redesigned; 6 tour
   hooks were grafted where the same element still exists (banner, summary tiles, list, search button, target-default
   card, calls/week column). The rest do not exist on the new screens (Report/Outcomes buttons moved to the tabs row,
   progress strip, add-to-day form, first-row owner/contact cells, most Settings cards). A tour step pointing at a
   missing element will not anchor — the Road to Sold tours need rewriting for the new screens (Andre).
3. **CHAT_STARTER.md is 1,095 lines** (target 350). It is a log of both lanes; it needs a prune.
4. **PropertyFieldsFromRecordTest** fixture should use builder spans instead of raw Blade (see table).
