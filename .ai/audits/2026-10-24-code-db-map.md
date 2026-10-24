# CoreX code and database map — first pass (READ-ONLY AUDIT 2)

- Date: 2026-10-24
- Scope: `/corex-qa1` code at `cebc9f960` (= `origin/QA1`) and the `corex_qa1` schema/data, read-only. No live. Nothing changed, nothing deployed.
- Johan's question: "are we overcomplicating the code or DB structures, does it all come together right, and would sorting it out speed the site up?"
- This is a MAP to walk section by section later, not a deep review. Findings marked VERIFIED were read in code or queried; SUSPECTED were inferred; ESTIMATE are counts from grep that may include false positives.
- **Caveat on every row count below:** QA1 is a test copy. "0 rows" means "unused on QA1", not "unused on live". Nothing here proves a table is dead on live; that needs a live row-count query (read-only, needs Johan's go for anything touching live).

## 1. Inventory

Totals (cloc code lines; raw `wc -l` in brackets): `app/` 3,349 PHP files, 434,708 (653,679). `resources/views` 1,627 files, 284,965 (331,657). `routes/` 5 files, 6,222 (10,194; `web.php` alone is 8,269 raw). `tests/` 1,887 files, 334,808. `database/migrations` 1,842 files, 61,715. 593 controllers, 1,042 services, 686 models, 1,647 views, 3,614 routes (90 are closures), 744 tables, 0 DB views, 3,107 MB, 302 spec files (294 in `.ai/specs` + 8 top-level `.ai/*`).

Section assignment is by directory + keyword rules (expect ~5% noise). Spec mapping is by filename only. Full TSV (adds test lines, row counts, spec lists) was produced in the audit scratchpad `inv/sections.tsv`; the table below is the same data.

Columns: C controllers, S services, M models, V views, JLE jobs/listeners/events, Oth other app files, KLines = thousand raw lines (app PHP + views), Rts routes, Tst test files, Tbl tables, MB table size, Sp spec files.

```
Code  Section                          C   S   M    V JLE Oth  KLines  Rts  Tst Tbl     MB Sp
PROP  Properties & Listings            19  32  13   57  16  19   56.7  129   70  15  188.9 24
CONT  Contacts, Buyers & Matches       23  22  32   66  24  24   32.3  124  118  42   45.3 19
RENT  Rentals: Leases/Notices/Core     39  89  30  104  12  45   51.8  250  131  30    2.0 19
RAPP  Rental Applications              12  12  26   31   8  11   33.3  141   86  27    1.8  3
RINS  Rental Inspections & Inventory   20  24  36   33   0  22   36.8  158   72  39    3.3  4
RMNT  Rental Maintenance/Faults/WO     41  41  38  114  12  24   43.0  253  161  40    8.2  2
DOCP  DocuPerfect & Documents          38 107  63  216  20  52  136.8  378  186  69   73.7 13
ESGN  E-Sign & Signatures               6  26  13   55   1  32   14.9   63   13  13    1.2 15
DEAL  Deals, DR2 & Agency Tracker      32  35  32   57  17  27   43.5  187   94  40   44.6 15
COMM  Commission, Finance & Proforma   13  27  17   29   5  10   15.7   48   13  18   44.2  6
RTS   Road to Sold, Activities, Perf  30  98  31   60   8  12   38.1  123   61  35  208.6  3
CMPL  Compliance, FICA, Title Deeds    44  60  61  126  10  29   53.9  299   54  63   20.0  9
HR    HR: Leave & Payroll              10   8  18   39   0   6   11.9   72    4  18    1.4  4
CMUN  Communications (WA/Mail/Push)    22  56  25   18  14  39   23.3   84   77  27  971.6 16
RPT   Market Analytics & Reports       10  76  13    8  11  10   22.6   44   46  13   11.7  8
SETT  Settings, Setup & Onboarding     13   8   9   36   7  31   22.3   82   58   9    7.2 14
AI    Ellie & AI                        7  21  11    9   8  13   11.0   28   15  11   52.2 10
PRTL  Client Portals & Client Auth     10   4   6   15   0  11   10.2   56    8   8    1.8 13
PROS  Prospecting, MIC & Outreach      34  67  33  117  35  34   55.2  163   86  36  210.8 22
PRES  Presentations, CMA, Evaluations  18  71  32  102  10  32   64.9  131   86  32   57.7  6
SYND  Syndication (P24/PP/Website)     25  36  28   18  30  40   34.2   98   70  29  365.8 11
TVTR  TV, Training & Webinars          13   1  12   27   3  24   16.3   74   11  12    3.5  4
ADMN  Admin/Platform/Demo/Ops          28  16  24  144  19  40   39.9  137   78  32  656.9  9
AUTH  Auth, Users, Agents, Branches    30   5  21   46  15  26   22.0  107   73  21    2.6 22
CMDC  Command Center, Calendar, Viewings 15 40 26   53   4  20   38.9  177   59  28   26.9 13
MKTG  Marketing & Tools                10  20  13   22   5   9   16.2   57   30  13    1.2  2
AUCT  Auctions                         10   9   7   21   8   5    6.5   56   17   7    0.7  2
GEO   Maps, Geocoding & Address         5  20   8    4  11   9   16.2   24   36   9   31.0  5
IMPT  Importer & Take-on                6   6   2   17   1  15    7.8   45   11   2    0.2  4
API   Mobile/Public API (residual)      9   5   6    1   5   0    5.1   19   19   6   62.7  5
UNASG Unassigned/shared                 1   0   0    2   0  38    4.0    7   16   0    0.0  0
TOTAL                                 593 1042 686 1647 319 709  985.3 3614 1859 744 3107.7 302
```

Observations (VERIFIED counts, ESTIMATE where noted):
1. DocuPerfect & Documents is the biggest section: 137k raw lines, 107 services, 216 views, 378 routes. The four rental sections together are ~165k raw lines, 802 routes, 450 test files (~17% of code, ~24% of tests).
2. Spec thin against code size: Road to Sold (3 specs, 98 services, 38k lines), Rental Maintenance (2), Rental Applications (3), Marketing & Tools (2), Auctions (2). Partly a filename artefact (most `rentals-*`/`lease-*` specs land in RENT).
3. Test thin against code size: HR (4 test files for 11.9k lines, 18 tables), Commission/Finance (13 for 15.7k), E-Sign (13 for 15k), Client Portals (8 for 10k).
4. Table size is concentrated: `domain_event_log` 652 MB, `notification_dispatch_log` 617 MB (1.9M rows), `notifications` 315 MB (485k), `p24_import_rows` 272 MB, `property_audit_log` 132 MB, `oversight_nudges` 114 MB (301k).

## 2. Database shape (QA1)

### 2.1 Headline numbers (VERIFIED, queried from information_schema)
- 744 tables, 0 DB views, 3,107 MB. 536 tables carry `agency_id`; 419 carry `deleted_at`. 414 JSON columns, 48 longtext.
- 75 tables are audit/log/history-shaped and hold ~1,500 MB of the 3,107 MB (about half). The four biggest alone (`domain_event_log`, `notification_dispatch_log`, `notifications`, `oversight_nudges`) are ~1,700 MB. No scheduled prune/retention command referencing them was found in `app/Console` (SUSPECTED: confirm in the scheduler before acting).
- Widest tables: `agencies` 250 columns, `properties` 206, `users` 105, `contacts` 82, `tracked_properties` 72, `rental_applications` 68, `leases` 66, `rental_fault_reports` 64, `rental_work_orders` 64, `rental_inspection_settings` 60, `signature_requests` 57, `deals` 55.

### 2.2 Empty tables / likely dead
- 270 tables have exactly 0 rows on QA1 (a further 33 had an estimated 0 but hold 1-4 rows).
- Of the 270: 243 have an Eloquent model (built, just unused on QA1 — mostly the new Rentals tables: 123 `rental_*` tables, most empty; also `rts_*`, `platform_esign_*`, `training_*`). 24 have no model but are referenced in code via `DB::table` etc. Only **3 have no model and no code reference at all** (likely dead): `deal_link_review_queue`, `rental_inspection_settings_omr_threshold_backup` (a one-off backup), `seller_mandate_lost_records`.
- No-model tables with code references worth a glance: `deal_v2_agents`, `deal_v2_contacts`, `deal_v2_remarks`, `deal_v2_settlements` (the retired Deal V2 prototype, all 0 rows), `activity_columns`, `branch_activity_columns`, `activity_point_goals`, `buyer_lost_risk_scores`, `buyer_property_responses`, `wishlist_migration_log`, `docuperfect_pack_templates`, `docuperfect_clause_branches`.
- `rmcp_compliance_officers_deprecated_20260421` and `fica_compliance_officers_deprecated_20260421` are renamed leftovers. **Bug (VERIFIED):** model `app/Models/Compliance/RmcpComplianceOfficer.php` has no `$table` override and points at `rmcp_compliance_officers`, which does not exist on QA1.
- 72 tables have no detected model at all (list: scratchpad `db/nomodel.txt`); many are pivots, Laravel system tables (`jobs`, `cache`, `sessions`, `migrations`) or `DB::table` users.
- Decision needed before any table drop: a live row-count for the 270 (read-only SELECT COUNT).

### 2.3 Several tables storing the same concept (VERIFIED names; "same concept" is my reading)
- **Deals, three generations:** `deals` (178 rows) and `deals_v2` (178 rows) hold the same 178 deals; `deal_v2_*` four empty children; plus DR2 live code. Old DR1 controller `Admin\DealController` is unreachable.
- **E-sign, four families:** `signature_*` (templates 102, requests 215, signatures 606), `docuperfect_documents/templates` (572 / 64), `platform_esign_*` (14 tables, nearly empty), `esign_*`; plus `compiled_templates`, `recipient_templates`. Different code paths read each.
- **Activities, two generations:** `daily_activity_entries` (24,471 rows) vs `rts_tasks`/`rts_task_completions` (0 rows). The cut-over is per agency (`rts_cutovers` has 1 row) so both must be maintained.
- **Settings, 37 tables named `*setting*`** (+ preference tables): `performance_settings` (55 rows, key/value, 146 call sites), `dev_settings` (key/value), `commission_settings`, `lease_settings`, `rental_*_settings` (12 of them, all empty on QA1), `rts_settings`, `title_deed_settings`, `agency_*_settings`, `branch_settings`, `user_*_settings`; plus ~231 column additions to `agencies` (250 columns) and 408 JSON casts in code.
- **Audit/log:** 22 tables with "audit" in the name (e.g. `calendar_event_audit_log`, `deal_activity_log`, `deal_logs`, `property_audit_log`, `signature_audit_log`, `rental_inspection_audit_log`, `finance_audit_items`, `domain_event_log`) — each feature built its own.
- **Contact-ish columns repeated:** `email` in 21 tables, `phone` in 14, `id_number` in 14, `suburb` in 14; `first_name` in 4. Mostly legitimate (applicants, tenants, signers) but each is a place a changed contact does not follow.
- **Property data in four places:** `properties` (7,008), `tracked_properties` (45,851), `prospecting_listings` (43,564), `portal_listings` (8,424). This is by design (Non-negotiable #10 two-tier rule) but is the biggest candidate for confusion.

### 2.4 Foreign keys and indexes (VERIFIED)
- 2,313 integer `*_id` columns; 1,728 have a foreign key, **585 do not** (in 247 tables). 1,834 FK constraints in total.
- 536 `agency_id` tables: 426 have an FK, 529 have `agency_id` as the leading index column (7 do not). No big (>10k row) table lacks it.
- Unindexed `*_id` columns on tables over 20k rows (15): `notification_dispatch_log.subject_id` (1.9M rows), `notifications.notifiable_id` (485k), `oversight_nudges.subject_id` (301k), `domain_event_log.subject_id` (236k), `agent_activity_events.subject_id` (121k), `geocoding_runs.entity_id`, `tracked_properties.{duplicate_of_tracked_property_id,p24_suburb_id,p24_city_id}`, `prospecting_listings.{linked_deed_by_user_id,possible_property_id}`, `p24_suburbs.p24_id`, `daily_activity_entries.subject_id`, `p24_import_rows.target_id`, `command_tasks.source_id`. Whether a query filters on them was not checked (SUSPECTED benefit only where a screen looks up by subject).

### 2.5 How agency scoping is enforced (VERIFIED by reading code)
- One trait `app/Models/Concerns/BelongsToAgency.php` + one global scope `app/Models/Scopes/AgencyScope.php`. 466 of 678 model classes use it. Agency comes from `Auth::user()->effectiveAgencyId()` (honours the session switcher `active_agency_id`, falls back to `users.agency_id`). Writes are force-stamped for ordinary users by a `creating` hook.
- **Fail-open cases (AgencyScope.php ~line 82-86):** the scope does nothing when (a) nobody is logged in (console, jobs, webhooks, public signing pages), (b) the user is an owner-role with no switcher, (c) the resolved agency is null — a non-owner with no agency would see every agency's rows. Impact of (c) depends on whether such users exist (not checked).
- **Bypass volume in `app/`:** 1,581 `DB::table(` calls (Services 831, Http 410, Console 280), 268 other raw `DB::` calls, 2,014 `withoutGlobalScopes()` (612 files), 320 `withoutGlobalScope(AgencyScope::class)`, 58 `queryWithoutAgencyScope`. 2,158 hand-written `where('agency_id', …)` lines in 658 files (many redundant on scoped models, the rest pin the agency after a bypass).
- **34 models have an `agency_id` table but no scope trait** (e.g. `PerformanceSetting`, `Role`/`RolePermission` (hand-filtered), `RentalSettingAuditEntry`, `DealDocumentAccessLog`, `AgentSignature`, `MarketDataPoint`, `AiUsageEvent`, `PlatformEsign\Document`). A few are global by design; each needs a look.
- **208 tables have no `agency_id`;** ~95 hold agency-relevant data through a parent with no model scope: e-sign children (`signature_requests`, `signatures`, `signature_markers`, `signature_audit_log` — signer names/emails/IPs), `tracked_property_owners` (3,463 rows, names + ID numbers), pivots (`contact_property`, `deal_user`, `document_contacts`…), `ai_messages`, `login_histories`, `notifications` (polymorphic), `p24_syndication_logs`. Safety depends on the controller loading the parent through a scoped model first. Any `DB::table` on them is unscoped.
- **Own/branch/agency visibility is not one mechanism:** one shared level resolver `PermissionService::getDataScope()` (182 call sites) but the SQL is re-written ~6 ways: 51 per-model `scopeVisibleTo`, 4 global scopes (`ContactScope`, `DealBranchScope`, `BranchScope` on 75 models, `LivePropertyScope`), 8 per-record guard traits in `Controllers/Concerns`, inline `$scope === 'own'` branches in 52 controllers, private helpers (`PropertyController::applyRoleScope`, `MapPinService::applyScopeFilter`…), and 24 direct `where('branch_id', …)` in controllers.
- **Multi-agency (Non-negotiable #9) hits in shipped code:** `app/Models/Property.php:2968` `isOnHfcWebsite()` is `status==='active' && agency_id===1` (marked PLACEHOLDER); `app/Http/Controllers/CoreX/MarketIntelligenceController.php:3769` `… ?? 1`; `app/Console/Commands/NormaliseContactTypes.php:193` `?? 1`; `app/Http/Controllers/CoreX/PropertyController.php:3604` and `config/corex-images.php:37` host allow-lists include `corex.hfcoastal.co.za`; `app/Support/Docuperfect/Cds/Reference/ReferencePackCds.php:27-30,222` HFC address/company name; `SalesMandatoryDisclosureEsignSeeder.php:162` and `HfcAddendumBEsignSeeder.php:58` look up the agency by the name 'Home Finders Coastal'; `P24ImportVettingAgents.php:32,39` `@hfcoastal.co.za`. ~12 seeders hard-code `AGENCY_ID = 1` (seeders are expected to). 128 views mention "Home Finders" (52 are HFC's own legal web-templates, left as HFC wording on purpose per `routes/web.php:7275-7285`). Raw lists: scratchpad `scope/hfc.txt`, `hard_ids.txt`.

## 3. Code shape hotspots

### 3.1 Largest files (raw lines)
| Lines | File |
|---|---|
| 13,431 | `resources/views/corex/properties/show.blade.php` |
| 8,835 | `app/Http/Controllers/Docuperfect/ESignWizardController.php` (33 public / 112 methods) |
| 8,269 | `routes/web.php` (≈3,379 `Route::` calls) |
| 7,240 | `app/Services/Docuperfect/SignatureService.php` |
| 5,647 | `resources/views/command-center/calendar/index.blade.php` |
| 5,506 | `app/Http/Controllers/Docuperfect/SigningController.php` |
| 5,105 | `resources/views/docuperfect/esign/wizard.blade.php` |
| 4,530 | `resources/views/corex/settings.blade.php` |
| 4,488 | `app/Http/Controllers/Docuperfect/SignatureController.php` |
| 4,400 | `resources/views/docuperfect/signatures/external/sign.blade.php` |
| 4,326 | `resources/views/corex/rental-applications/review.blade.php` |
| 4,283 | `resources/views/corex/map/index.blade.php` |
| 4,050 | `app/Models/Property.php` |
| 4,032 | `app/Http/Controllers/CoreX/PropertyController.php` |
| 3,979 | `app/Http/Controllers/CoreX/MarketIntelligenceController.php` |
| 3,824 | `database/seeders/DemoDataSeeder.php` |
| 3,665 | `app/Services/Presentations/PresentationPdfService.php` |
| 3,579 | `resources/views/layouts/corex-sidebar.blade.php` |
| 3,279 | `app/Http/Controllers/CommandCenter/CalendarController.php` |
| 2,988 | `resources/views/corex/properties/ad-builder.blade.php` |

20 controllers exceed 1,500 lines (the above plus `Tools/PdfSplitterController` 2,609, `ContactController` 2,343, `RentalInspectionRecordingController` 2,122, `RentalApplicationReviewController` 2,094, `Api/MobilePropertyController` 2,066, `RentalApplicationController` 2,042, `Dr2/DealRegisterController` 1,928, `SellerOutreach/EntryPointController` 1,890, `Docuperfect/TemplateController` 1,885, `RentalApplicationSigningController` 1,876, `Presentation/PresentationController` 1,841, `Admin/UserManagementController` 1,689, `CoreX/SettingsController` 1,682, `Compliance/FicaController` 1,595). 41 Blade files exceed 1,000 lines.

### 3.2 Duplicate logic (VERIFIED by file:line)
1. E-sign field-name map (`$attrMap` in `deriveBladeName`), 3 identical copies: `Docuperfect/TemplateController.php:1570`, `Services/WebTemplateDataService.php:1689` (comment says it "mirrors" the first), `Docuperfect/ESignWizardController.php:7545`; related table `Console/Commands/SyncNamedFields.php:50`.
2. Rental "scope options" matrix (`'all' => ['own','branch','all']`), 9 copies: `Services/Rentals/RentalReportService.php:131`, `RentalCommandCentreService.php:116`, `RentalJobCardListQuery.php:72`, `Controllers/CoreX/RentalFaultReportController.php:69`, `RentalInspectionController.php:318`, `RentalInspectionDueController.php:382`, `RentalWorkOrderController.php:66`, `LeaseController.php:76`, `RentalApplicationController.php:85`.
3. Money formatter, 6+ private copies (`'R'.number_format($n,2,'.',',')`): `RentalWorkOrderScreen.php:19`, `RentalJobCardScreen.php:40`, `RentalApprovalGateService.php:981`, `RentalAuthorisationService.php:65`, `RentalGoAheadService.php:37`, `RentalNoticeService.php:119` (different style), plus `AdListingFacts.php:68`, `AgencyServiceProvider.php:147`, `CommercialEvaluation.php:116`, `AuditLabelHelper.php:74`, `AiSummaryService.php:707`. An official `App\Support\Money\Zar` exists with 38 uses in 7 files.
4. JS money/format helpers redefined per Blade page: `dr2/create.blade.php:1040,1247`, `presentations/pricing-simulator.blade.php:253`, `presentations/brain.blade.php:383`, `presentations/review.blade.php:1133`, `shared/match.blade.php:362`, `platform-esign/agreement/_js.blade.php:35`, `platform-esign/agreement/send.blade.php:145`, `admin/deals/settle.blade.php:405`, `dr2/settle.blade.php:417`.
5. Dompdf setup + writable font-cache block, ~11 copies: `DealV2/CocRequestGenerator.php:108-124` and `WorkAuthorisationGenerator.php:213-229` (line-for-line identical), `PropertyBrochureService.php:245,269`, `RentalApplicationDocumentHighlightService.php:1227`, `RentalInspectionFormPdfService.php:736`, `RentalDocumentPdfService.php:344`, `ViewingPackAgentPdfService.php:157`, `ViewingPackPdfSupport.php:134,169`, `ViewingPackRedactionService.php:269`, `RentalReportController.php:288`, `ContactController.php:427`.
6. Activity slug→label tables (`GROUP_ORDER`, `SLUG_LABEL`, `CALENDAR_LABEL`) in `Support/Activity/ActivityLabelResolver.php:27,61` (intended source), `Admin/ActivityCalendarMappingController.php:48,67,101`, `Admin/DailyActivitySetupController.php:34,47,77`.
7. Province name maps, 3 copies with different values: `SyncPpLocations.php:264`, `PrivatePropertyListingMapper.php:999`, `SgSearchService.php:57`.
8. Role-name lists inline in 37 `in_array(role, [...])` checks (`['admin','super_admin']` ×12, `['admin','super_admin','owner']` ×8, 4-role list copied in `RcrQuestionnaireAdminController.php:178`, `RcrSubmissionController.php:501`, `ContactController.php:745`, `PropertyController.php:3717`).
9. Role-based record-scoping blocks: `PropertyController.php:819`, `ContactController.php:448`, `BuyerPipelineController.php:422`, `MapPinService.php:1478` (twins of `PermissionService::getDataScope` consumers).
10. Policy vs RMCP compliance are copy-paste twins (~2,100 lines): `Compliance/PolicyVersion` ↔ `RmcpVersion`, `PolicyAcknowledgement` ↔ `RmcpAcknowledgement`, `PolicyDashboardController` ↔ `RmcpDashboardController`, `PolicyAcknowledgementController` ↔ `RmcpAcknowledgementController`.
11. Deal pipeline services, old and new: `Services/Deal/Dr1PipelineService.php` (943) and `Services/DealV2/DealPipelineService.php` (831) write the same step records.
12. Smaller: `CalendarEventCreator.php:70` vs `CalendarEventService.php:446`; TV message tokens in `TV/BranchTvController.php:109`, `TV/TvController.php:134,299`; `loadHTML('<?xml encoding…')` boilerplate in 69 places under `app/Services`.

### 3.3 Dead code (VERIFIED by route dump + grep)
- 593 controller files; 554 distinct classes in routes. 15 real controllers unreachable, 14 with no other reference: `Admin/DealController` (1,148, old DR1; `/admin/deals*` now redirect to DR2), `TvMessageController` (241), `Admin/TargetsManageController` (224), `Agent/AgentDailyController` (138; its view `agent/daily/index` also orphaned), `Compliance/RmcpComplianceOfficerController` (129), `CoreX/DashboardController` (113), `Admin/ListingSnapshotController` (113), `Admin/TvCodeController` (106), `Presentation/PresentationArticleController` (92), `Admin/AgentCommissionController` (88), `BM/MyDashboardController` (71), `BM/TvCodeController` (64), `Rental/RentalReminderSettingsController` (49), `AiBuddyController` (17). `Auth/RegisteredUserController` is deliberately disabled (`routes/auth.php:48`).
- One route points at a missing method: `docuperfect.signatures.supersede` → `SignatureController@supersede` (`routes/web.php:7431`), already a known break in `tests/Unit/Routing/RouteReferenceIntegrityTest.php:122`.
- Non-controller classes with zero references (of 2,461 checked): services `P24/P24MarketDataService` (171), `DealV2/DealStepPhaseGrouper` (134), `Admin/MonthlyGoalService` (102), `TvaApiClient` (69), `Performance/Providers/AbstractDr2DealMetricProvider` (66); `Jobs/ParseDocumentJob` (90); `Notifications/Compliance/RcrSubmissionCompletedNotification`; `Events/Auction/AuctionScheduled`; `Mail/Signatures/SupersededNotificationMail`; `Events/_Smoke/SmokeTestEvent`; `Observers/DealUserObserver` (not registered); `Domain/Presentation/*` (2 × 16 lines); models `Docuperfect/FlagRemovalRequest`, `AiDailyBriefing`, `AiFeedback`, `Docuperfect/DocumentCustomField`. Test-only: `LaunchPositioningService`, `CallbackGoldenRenderProbe`, `ReferenceProofRunner`, `CallbackRenderParityVerifier`.
- Dead layouts: `layouts/navigation.blade.php` (Breeze leftover, still links to `agent.daily`), `layouts/ellie.blade.php`, `layouts/corex-header.blade.php`.
- ~3,000 lines of dated backup files inside `resources/views`: `layouts/sidebar.blade.php.pre_impersonate_fix.20260212_041152`, `bm/performance.blade.php.pre_restore.20260202_132223`, `tv/branch.blade.php.pre_*` ×6, `admin/deals/index.blade.php.pre_restore.*` ×3.
- `git status` in the Staging checkout also shows an untracked `resources/views/__audit_old_sidebar.blade.php.tmp` (not in this QA1 branch).

### 3.4 Legacy modules still loaded
- **Daily Activities vs Road to Sold:** RTS supersedes it, but cut-over is per agency (`rts_cutovers`, `DailyActivityMigrationService`, `LegacyEntryBridge`). Sidebar links hidden by `@unless($dailyMoved)` (`corex-sidebar.blade.php:1418,1526,1585`) but routes stay live (`agent/daily*`, `bm/daily/summary*`, `admin/daily*`). ~1,750 controller lines + ~1,500 view lines.
- **Deals, three generations:** DR2 (`Dr2\*`, live) / DR1 (`Admin\DealController` unreachable; views `admin/deals/*` ~2,650 lines still present) / DealV2 (`DealV2Controller@index` returns `dr2RetiredRedirect()` with the old body left below; nav block `@if(false && …)` at `corex-sidebar.blade.php:2631`; DealV2 controllers 4,532 lines, views 8,194 lines; some pieces — Pipeline Setup, Supplier Directory, settlement — are still used by DR2).
- **Old performance pages:** `Admin/BM PerformanceController`, `AgentPerformanceController`, `BranchPerformanceController` (~1,100 controller + ~1,100 view lines) still live and in the sidebar alongside the Performance tab hub.
- `prospecting/index_legacy_body.blade.php` (911 lines) is NOT dead (included by `prospecting/index.blade.php:12`) — only badly named. `app/Services/Finance/Legacy/*` (4 readers) and `Docuperfect/LegacyOtherConditionsBridge` not checked.

### 3.5 Work done on every request (speed) — ranked, VERIFIED unless marked
1. **No route/config cache on QA, and none on live or Staging either.** `bootstrap/cache` holds `packages.php`+`services.php` (QA1) and `events.php`+`packages.php`+`services.php` (live `/corex` and Staging) — no `config.php` or routes cache anywhere. `scripts/deploy.sh:759-766` runs `config:cache`/`route:cache`/`view:cache`/`event:cache`, and `scripts/qa-deploy.sh:316-318` only clears. Why live has none despite the deploy script: SUSPECTED — the route table has 90 closure routes (VERIFIED count), and Laravel refuses `route:cache` while any closure route exists; or a later deploy/lane cleared the cache. Not confirmed. `routes/web.php` is 8,269 lines / ~3,379 route calls parsed per request when uncached.
2. `Agency::find($user->agency_id)` runs 6-7 times per page with no memo: `Http/Middleware/Authenticate.php`, `AgencyMaintenanceGate.php:58`, `layouts/corex-app.blade.php:54,142`, `layouts/corex-sidebar.blade.php:29`, `Services/Features/AgencyFeatureService.php:367`, `Services/CommandCenter/NotificationPreferenceService.php:516`.
3. `PerformanceSetting::get` (`Models/PerformanceSetting.php:69-96`) does one SELECT per call, 146 call sites; `AgencyFeatureService::switchboardStates` calls it 4× every request (`:360`).
4. Agent deals list: ~2 uncached queries per row via `Deal::totalOurCommission()` → `commissionExVat()` → `PerformanceSetting::get('vat_rate')` (`Models/Deal.php:420`) — ~40 queries per 20-row page. Fixed by item 3.
5. Contacts list queries phones and emails per row (`resources/views/corex/contacts/index.blade.php:937,940`, inside the inline-edit include rendered for every row) — 2 queries/row, 50 at the default 25 rows, up to 400 at 200 rows.
6. Sidebar runs its own uncached queries each page (`corex-sidebar.blade.php:7,13-24,37-41,69-100,435,449,862,1474,1668`, plus 3 `cache()->remember` at :903,:955,:2461); ordinary agent ≈ 8-15 queries, owner more.
7. Tab-hub badges query per applicable tab (`Support/Navigation/TabHub.php` → `CalendarTabs.php:34`, `PresentationsTabs.php:45`, `ComplianceAgentsTabs.php:48,50`, `ComplianceReportsTabs.php:38-43`), ~3-5 for admins.
8. Alerts-off bar + alert popups: ~2-3 queries per page (`layouts/partials/_alerts-off-bar.blade.php:7` → `NotificationPreferenceService::context()` with `firstOrCreate`).
9. Polling: alert feed every 30 s, reminders every 60 s, portal-lead toast every 10 s (`portal-lead-toast.blade.php:84`); each poll is a full authenticated request with session read/write.
10. `GET /api/v1/logged-user` fires on every page load (`resources/js/corex-api.js:66` → `MeController::show`, which loops every permission key in `config/corex-permissions.php`).
11. Environment: `CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION` are all `database` on live, Staging and QA1, so every cache hit and every session read/write is a DB query. `LOG_LEVEL=debug` on all three. `APP_DEBUG=true` on QA1 and Staging (false on live).
12. Permission checks themselves cost almost no queries (static memo in `PermissionService.php:13-19`); about 5-6 queries once on first check. N+1 on the main list pages is mostly clean (properties, leases, tasks eager-load properly); SUSPECTED single leaks: `Property::externalSource` on the properties list (`index.blade.php:1075`), 7 separate `count()` tiles in `LeaseController.php:~138-150`, an unbounded `->get()` of all paid deals in `Dr2/DealRegisterController.php:163`.

## 4. Consistency — dominant pattern vs outliers (VERIFIED counts, grep scope stated)

| Concern | Dominant | Outliers / cost |
|---|---|---|
| List screens & pagination | `->paginate(N)` 169 hits/141 files + `->links()` | 26 hand-built `LengthAwarePaginator`, 33 manual slices; per-page 25/20/50/30/15/24/100/10 chosen per screen; search/sort copy-pasted in ~160 places; only 7 models have `scopeSearch`; no shared list component (only `x-sort-header` ×48) |
| Permission checks | `$user->hasPermission('key')` 988 hits/245 files; `permission:` middleware on 1,262 route lines; 515 keys in `config/corex-permissions.php` | `isOwnerRole()` 232 hits (27 in views), role-name literals ~44-124, `in_array(role,…)` ~46, assistant branches 76, 8 per-controller authorize traits; `@can`/Gates/Policies 0. 1 route key (`access_importer`, `routes/web.php:942`) is not in config |
| Settings storage | One table + model per feature, each with its own reader | ~8 mechanisms: per-feature tables (37), key/value `PerformanceSetting` (146 uses) and `DevSetting`, columns on `agencies` (~231 added), JSON casts (408), 70 config files, 16 direct `env()` outside config, cache |
| Money format | Inline `R {{ number_format($x,0\|2) }}` — `number_format(` 2,177 hits/445 views + 354 in `app` | ≥9 visible styles (`R `, `R`, space/empty/comma thousands, 0/1/2+ decimals, `ZAR`); `Zar::format` used only 38× in 7 files; 17 private per-class helpers |
| Date format | `->format('d M Y')` 316 + `'j M Y'` 122 in views | 65 distinct format strings in views, 72 in `app`; `Carbon::parse` 171 in views (ignores per-agency timezone — correctness risk); `AppDate` used 63× |
| PDF generation | DomPDF `Pdf::loadView/loadHTML` ~30 files | Puppeteer via `scripts/html-to-pdf.mjs` 16 files + `GeneratesPdfViaPuppeteer` trait; `SigningController`/`SignaturePdfService` use both; Ghostscript, Imagick; 21 files spawn `Process`, each with own paths/timeouts |
| Mail | `Mail::to()->send(new …)` 87 hits/54 files; 111 Mailables | `->queue()` only 6 (30 Mailables are `ShouldQueue`); `Mail::mailer('corex')` 19; 6 custom dispatchers (`RentalMailDispatcher`, `ComplianceMailDispatcher`, …); 39 Notifications; `StaffFacingMail` has 1 implementer; `OutboundMailGuard` referenced in only 3 of 54 `Mail::to` files (transport-level coverage unconfirmed) |
| WhatsApp | `wa.me` click-to-chat 99 hits/59 files (outbound) | WAHA self-hosted inbound ingest; no Twilio/SMS |
| File uploads/storage | `Storage::disk('local')` 269, `public` 124, dynamic 84 | `file_put_contents` 50, `storage_path(` 105, `->move(` 9 (bypass the disk layer); `public` disk files are permanent guessable URLs; `PrivateImageService` (signed URLs) only on newer portal uploads; `s3` disk defined, unused |
| Validation / JSON | Inline `$request->validate` 1,212 hits/407 of 593 controllers | Only 4 FormRequests; `response()->json` 1,767 hits with envelopes `ok` (326), `message` (250), `error` (103), `success` (51), `data` (7); failure signalled by `ok=>false` (210) or `success=>false` (33); API Resources only on the 6 website-API classes |

## 5. Walk plan

Order = highest risk × most used first. Lane-days are my estimates for a cleared Sonnet lane, walk + fix list (not the fixes themselves). "Speed" marks findings that would also make the site faster.

| # | Section(s) | What to check | Lane-days | Speed? |
|---|---|---|---|---|
| 0 | Platform speed pass (cross-cutting; not a section) | Cache the config/routes on every box (why live has none), per-request memo for `Agency` and `PerformanceSetting`, sidebar/tab-badge/alerts consolidation, polling, `logged-user`, cache/session drivers | 3 | YES — all of it |
| 1 | AUTH + agency scoping (Auth, Users, Branches, scope traits) | Fail-open null-agency path; 34 unscoped models; ~95 unscoped child tables; 1,581 `DB::table` + 2,014 `withoutGlobalScopes` spot-sample on the highest-risk files; `Property::isOnHfcWebsite` and the other agency-1 spots; second agency (Cape Town) is starting now | 4 | no |
| 2 | DOCP + ESGN (DocuPerfect, e-sign, signatures) | 3 duplicated field maps; 4 e-sign table families; 8.8k/5.5k/4.5k-line controllers; 13 spec/13 test files for 15k lines of e-sign; pipeline gate files | 5 | some (large views) |
| 3 | PROP + SYND (Properties, P24/PP syndication) | 13.4k-line `show.blade.php`; list eager loads; refresh-cost contract; `p24_import_rows` 272 MB / `p24_syndication_logs` 209k rows retention | 3 | YES (page weight) |
| 4 | CONT (Contacts, Buyers, Matches) | List N+1 (phones/emails); ContactScope; duplicate-contact fields in 21 tables | 2 | YES |
| 5 | DEAL + COMM (Deals DR2, tracker, commission, proforma) | DR1/V2 retirement and `deals_v2` data; `Deal` money helpers hitting settings per row; JS money copies; 3 test-thin areas | 3 | YES |
| 6 | RENT + RAPP + RINS + RMNT (all rentals) | 9 copies of the scope matrix; 123 tables mostly empty on QA1; 12 settings tables; 6 money helpers; 11 Dompdf copies; 17% of code, 24% of tests | 6 | some |
| 7 | RTS (Road to Sold, Daily Activities, Performance) | Legacy retirement per agency cut-over; old performance pages; `daily_activity_entries` vs `rts_*`; thin spec (3 for 98 services) | 2 | some |
| 8 | CMUN + logs/retention | `notification_dispatch_log` 1.9M rows, `notifications`, `domain_event_log`, `oversight_nudges` (≈1.7 GB, no prune found); unindexed subject columns; mail send paths | 2 | YES (DB size, backups) |
| 9 | CMPL + HR (compliance, FICA, title deeds, payroll/leave) | Policy/RMCP twins; broken `RmcpComplianceOfficer` model; HR thinly tested (4 files / 18 tables) | 4 | no |
| 10 | PROS + PRES + GEO + RPT (Prospecting/MIC, presentations/CMA, maps, reports) | 4k-line MIC controller, 3.7k-line presentation PDF service, tracked/prospecting/portal listing overlap | 4 | some |
| 11 | SETT + AI + PRTL + CMDC + TV/MKTG/AUCT/IMPT/API | Settings sprawl policy, wizard drift (#10a), calendar 5.6k-line view, remaining small sections | 4 | some |

Total ≈ 42 lane-days if every section is walked; items 0, 1, 3, 4, 8 hold nearly all of the speed benefit and the multi-agency risk (≈ 14 lane-days).

## 6. Top 10 simplification candidates (benefit × safety)

| # | Candidate | Plain benefit | Risk | Effort | Johan decision? |
|---|---|---|---|---|---|
| 1 | Turn on route + config cache on QA/Staging/live and find out why live has none | Every page stops re-reading ~3,400 route definitions and the config files; the single biggest cheap speed-up | Low-medium: the 90 closure routes must be converted first; `env()` outside config (16 places) breaks when config is cached | 1-2 days | No (technical); the live deploy needs his go as always |
| 2 | Remember the agency and setting values within a request instead of re-reading them (6-7 agency reads/page, 146 setting call sites, ~40 extra queries on the deals list) | Pages do noticeably fewer database trips; deals list and every page header | Low | 1 day | No |
| 3 | Fix the contacts list reading phones/emails per row, plus the other single N+1 spots | Contacts list stops doing up to 400 extra queries | Very low | 0.5 day | No |
| 4 | One cached "sidebar and badges state" instead of 8-15 separate lookups per page | Menu loads faster on every page; badge numbers may be up to a minute old | Low-medium | 2 days | Yes — is a badge count up to ~60 s old acceptable? |
| 5 | Slim the background chatter: poll intervals, skip hidden tabs, fetch `logged-user` only when a page needs it | Fewer hidden requests per user per minute; lighter server | Low | 1 day | Yes — how quickly must the portal-lead pop-up appear (10 s today)? |
| 6 | Delete provably dead code: 14 unreachable controllers, ~3,000 lines of dated backup files, 3 dead layouts, unused services/jobs/models | Less to read, test and search; fewer wrong turns for lanes | Low (git keeps history; each item grep-verified) | 1 day | No |
| 7 | One money format and one date format used everywhere (existing `Zar` and `AppDate`) | Same number reads the same on every screen and document; one place to change | Low per file but touches ~600+ files; do it per section during the walk | 3-4 days spread | Yes — the house style (R 1,250,000, cents or not, date style) |
| 8 | Keep the big log tables from growing forever (domain events, notification log, notifications, oversight nudges ≈ 1.7 GB of 3.1 GB) | Smaller backups and deploy dumps, faster restores, disk headroom (the 2026-08-20 disk alert) | Medium: audit/compliance value of old rows | 1-2 days | Yes — how long to keep each kind |
| 9 | Retire the old generations: Deal register DR1/V2 (data + code + 4 empty tables), Daily Activities and old performance pages once agencies have cut over | One way to do each thing; ~10k lines less to maintain | Medium: old data and per-agency cut-over timing | 3-4 days | Yes — can the old deals/activities history be archived, and when |
| 10 | Collapse the copy-paste twins into one each: e-sign field map (3), Dompdf set-up (11), rental scope matrix (9), role lists (37), Policy/RMCP (~2,100 lines) | A fix lands once instead of in 3-11 places; stops the drift that already caused bugs | Medium (e-sign is behind the pipeline test gate; scope matrix is security-relevant) | 4-6 days | No |

Not in the top 10 because it is a risk item rather than a simplification: the agency-scoping review (section 2.5 / walk item 1).

## 7. Method and limits
- Counts: `cloc`/`wc`, `information_schema` + `SELECT COUNT(*)` (read-only, QA1 `corex_qa1`), the route dump from `php artisan route:list --json` run as `www-data` in `/corex-qa1` (no files written there), and grep. Dead-table and dead-class checks are by name reference: a table or class reached only by a computed name would be a false "dead".
- Not done: no live data, no query timing (counts of queries per request are estimated from code, not measured), no per-screen browser check, no review of Blade views other than the sidebar/layout and the contacts list.
- Raw scratch outputs from the run (not committed): schema extracts, `dead.txt`, `shape.txt`, `scope/*.txt`, `inv/sections.tsv`.
