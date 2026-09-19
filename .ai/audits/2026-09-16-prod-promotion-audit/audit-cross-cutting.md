# Cross-cutting compliance sweep — Prod promotion 6545f0262..57407d5a5

Repo: `C:\Users\USER-PC\Documents\Projects\hfc-dash` (branch `Prod`, clean). Range: 601 files (345 A / 255 M / 1 D), +94,836 / -4,156.
Method: read-only — `git diff`, file reads, `php artisan route:list --json` (2,473 routes). No tests run, no files in the repo touched.
Scope: the nine cross-cutting checks below. Rental-application internals, core matches / DR2 logic, migration bodies and the QA2 lane are other reviewers' deep dives and are only touched here where a cross-cutting rule depends on them.

## Ranked findings

| # | Sev | Status | Finding | Where |
|---|-----|--------|---------|-------|
| F1 | MEDIUM | CONFIRMED | Rule 10a (every new setting reaches the Setup Wizard) is unmet for ~45 new agency-level settings; only `rental_fee_max_amount` was added to the wizard. Nine of them have a written "not in wizard" decision (all flagged *pending Johan*, none ruled); the rest are covered only by a generic "whole module never reached the wizard — reported" note in the spec. Process/compliance, not runtime. | `config/agency-onboarding-copy.php` (+7 lines only); table in §4 |
| F2 | MEDIUM | PLAUSIBLE | New `data_volume` storage disk defaults to `/mnt/HC_Volume_103099143/corex-data-volume`. If that mount is absent or unwritable on the live box (62.238.31.82), the sealed-generation PDF cache silently never hits (`throw => false`, best-effort try/catch) and every open of a signed rental-application PDF re-renders from scratch. No error surfaces — only a `Log::warning` per attempt. Set `DATA_VOLUME_ROOT` in the prod `.env` (or confirm the mount) before promotion. | `config/filesystems.php:73-79`; `app/Services/RentalApplications/RentalApplicationPdfService.php:51,165,199,221` |
| F3 | MEDIUM | CONFIRMED | Imported Stock page (AT-419) ships inert on prod until `php artisan properties:backfill-p24-imported` is run by hand: the migration only adds the nullable `p24_imported_at` column, the spec says the command "is provided for Johan to run". Not a bug — a post-deploy step that is not in the standard deploy order. | `database/migrations/2026_09_15_100000_add_p24_imported_at_to_properties_table.php:17`; `.ai/specs/at419-imported-stock.md:97` |
| F4 | MEDIUM | CONFIRMED | All seven new rental-application mailables (+ `OtpMail` used by the two OTP gates) are synchronous (`Mail::to()->send()`, none implement `ShouldQueue`), sent inside request paths. Each send is try/catch-wrapped so a failing SMTP cannot 500 the request, and this mirrors the pre-existing e-sign `SignatureService` pattern. Risk is latency/timeouts during the known 08:30–09:00 SMTP contention window, not data loss. | `app/Services/RentalApplications/RentalApplicationMailer.php:32,60,86,118,155`; `app/Mail/RentalApplication*.php` |
| F5 | LOW | CONFIRMED | `corex/rental-applications/authorisation/*` (22 routes) carries NO route-level permission middleware — only `auth`+`verified`. Every action is gated in-controller (`guardCanView()` / `guardCanDecide()` abort 403 unless RO/CO tier, then `guardRentalApplication()` for own/branch/agency). Functionally closed; deviates from Non-negotiable #5's "route middleware" wording. | `routes/web.php:2940-2985`; `app/Http/Controllers/CoreX/RentalApplicationAuthorisationController.php:136-150,172,208,345,430,559,636,727,794,814` |
| F6 | LOW | CONFIRMED | Sidebar gate mismatch: the new Rentals → Properties nav item is gated `@permission('properties.view')` while its route is `permission:access_properties` (the existing Real Estate → Properties item uses `access_properties`). A role holding one key but not the other either sees a link that 403s or can reach the URL with no link. Server side is closed. | `resources/views/layouts/corex-sidebar.blade.php:1112-1114` vs `:832`; `routes/web.php:3980-3982` |
| F7 | LOW | CONFIRMED | Six new page-side data endpoints return JSON under `/corex/...` web routes rather than `/api/v1` (Non-negotiable #7): rental-applications `search-properties`, core-matches `share-history`, `highlight-data/first|remaining` (x2 controllers), DR2 `search/eligible-properties`, splitter `contacts/search`. Each is named, auth+permission gated, and sits beside identical pre-existing siblings (`search.properties`, `tools.pdf_splitter.properties.search`). Convention drift, consistent with the codebase. | `routes/web.php:17,40,216,275-276,448` (diff line refs) |
| F8 | LOW | CONFIRMED | Two public, unauthenticated resend-OTP routes have no `throttle:` middleware (`gate/resend-otp`, `verify-identity/resend-otp`). The OTP *issue* is throttled inside `OtpService::throttle()` (per-email cooldown + hourly cap), so mail cannot be spammed; the route itself still costs a token lookup per hit. | `routes/web.php:5091,5100`; `RentalApplicationSigningController.php:129-135,315-326,532-545` |
| F9 | LOW | CONFIRMED | `.ai/CHAT_STARTER.md` is 541 lines (limit 350), last updated 2026-09-15 (AT-419), its LIVE section does not list anything from this range (rental applications, Rentals menu, Imported Stock, branch archive, multi-property deals, core-match reassignment/shares), and its rule 5 still reads "QA1 ONLY … NEVER promote to Staging or live" — stale against CLAUDE.md's amended two-lane rule. | `.ai/CHAT_STARTER.md:13,25,269-326` |
| F10 | LOW | CONFIRMED | New CLI dev scripts (`scripts/mint-session-cookie.php`, `fetch-authenticated-page.php`, `rental-click-through-fixture.php`) mint real session cookies / create+mutate rental-application fixtures against whatever `--app-root` is passed, with no `APP_ENV` refusal. Not web-reachable (`scripts/`, `getopt`/STDERR), so only an operator-discipline concern on the live box. | `scripts/mint-session-cookie.php:21-45`; `scripts/rental-click-through-fixture.php:27-29` |

No BLOCKER or HIGH findings. Checks 1, 2 (route/controller gating), 3, 5, 7 and 9 PASS outright — evidence below.

---

## 1. Route naming + API rule — PASS

- `git diff -U3 6545f0262 57407d5a5 -- routes/web.php`: 550 diff lines, ~150 added route definitions across 9 hunks (DR2 multi-property, PDF-splitter rental intake, rental-application settings x31, authorisation x22, rental-applications x19 + review x24, rentals lens x5, core-matches x4, contact notes quick-view, imported stock, rental-details, rental-fee-ceiling, public rental-application x13). **Every one carries `->name()`** (many on the continuation line, which is why a single-line grep flags them; verified line-by-line).
- `route:list --json`: 2,473 routes; 9 with empty name — `GET /`, `POST agent/daily`, `POST confirm-password`, 4× `deposit-interest-calculator/*` closures, `POST login`, `GET up`. **None appear in the range diff** — all pre-existing.
- Hidden JSON endpoints outside `/api/v1`: see F7. `ContactNoteController::quickView` returns an HTML fragment (`return view(`), not JSON.

## 2. Permissions — PASS (with F5/F6 notes)

New keys in `config/corex-permissions.php` (9):

| Key | Used by route middleware / controller | Sidebar / nav gate | Default role grants |
|---|---|---|---|
| `rental_applications.view` | routes 2986, 3040 (group mw); controller 111-251; `RentalApplication.php:1013`; `PermissionService.php:345` | sidebar 1052 (panel), 1084 (item) | branch_manager, agent (+ owner via `*`) |
| `rental_applications.create` | 12 routes (2991-3033); `FiltersRentalApplicationList.php:104` | action key — buttons inside pages | branch_manager, agent |
| `rental_applications.view_returned` | route 2989; controller 111,122,251 | sidebar 1052 | branch_manager, agent |
| `rental_applications.manage_settings` | 31 routes (2838-2896) | `resources/views/corex/settings.blade.php:104-105` (`$can(...)`) | admin/owner only (deliberate) |
| `rental_applications.archive` | routes 3021, 3023 | action key | branch_manager, agent; migration `2026_09_12_100000` copies existing `.create` grants |
| `contact_rental_history.view` | scope-only key: `PermissionService::contactRentalHistoryScope()` → `RentalApplication.php:1021`; Role Manager row `RoleManagerController.php:117` | n/a (data scope) | migration `2026_09_10_040000` grants scope `all` alongside `contacts.view` |
| `access_imported_stock` | route 3703 (group mw) | sidebar 845 | branch_manager, agent, viewer lists updated |
| `buyer_pipeline.view` | routes 3989-3994 | sidebar 1126 | migration `2026_09_10_100000` grants scope `all` alongside `core_matches.view`; **not** in any role default list |
| `core_matches.reassign` | route 4068; `ContactMatch.php:316-327`; `ReassignmentNotAuthorizedException.php:13` | action key (no nav — correct) | branch_manager only (mirrors `contacts.reassign_agent`) |

New controllers (10) — every public action has a route (script-checked `Controller::class, 'method'` for all 9 web controllers + the authorisation controller), every route is inside `Route::middleware(['auth','verified'])->prefix('corex')` (line 1770) except the public token-based `rental-application/*` group (top level, line 5079, throttle-keyed to the token by design):

| Controller | Gate |
|---|---|
| `CoreX/RentalApplicationController` (18 public) | group `permission:rental_applications.view` + per-action `.create`/`.archive`; 12 `guardRentalApplication()` calls; the 4 without one are `returned` (pure redirect into `index`), `create`, `store`, `quickCreateContact` (no record to guard) |
| `CoreX/RentalApplicationReviewController` (17 public) | group `permission:rental_applications.view`; 19 `guardRentalApplication()` + `guardDocumentMarkAccess` — every action guarded |
| `CoreX/RentalApplicationAuthorisationController` (11 public) | **no route mw** — in-controller RO/CO tier + own/branch/agency on every action (F5) |
| `CoreX/RentalApplicationSettingsController`, `RentalApplicationHighlighterController`, `RentalApplicationDeclineReasonTemplateController` | `permission:rental_applications.manage_settings` on every route |
| `CoreX/ContactMatchReassignmentController` | `permission:core_matches.reassign` + `ContactMatch::reassignTo()` re-check |
| `CoreX/ContactMatchShareController`, `ContactMatchShareHistoryController` | `permission:core_matches.view` |
| `RentalApplicationSigningController` (public) | token lookup + named throttles (`rental-application-*`, registered in `AppServiceProvider::boot()`); two resend routes unthrottled at route level (F8) |

Scope-null incident class (from migration `2026_09_10_070000_restore_agency_1_admin_rental_applications_view_scope.php` docblock): a Role Manager save nulled the scope of `rental_applications.view` (typed `access`, so the UI never submits a scope) and the guard has no NULL branch → 403 everywhere. Verified the **class** is fixed, not just the instance: `RoleManagerController.php:266-283` snapshots existing scopes before the delete+insert; `SyncPermissions.php:133,172-174` writes a scope for *every* `.view` key regardless of type; `deploy:sync-reference-data` (`SyncReferenceData.php:91`) runs `corex:sync-permissions --merge-defaults`. The agency-1 migration is a one-row data repair (`agency_id=1, role=admin`) — harmless on prod (sets the value Johan's ruling wants). Note `PermissionService::getDataScope()` still returns the raw stored value (`:282`) with no `scope_defaults` fallback for a NULL row, so any *custom* role granted `rental_applications.view` by hand without a scope would still see an empty list / 403 — pre-existing behaviour, not introduced here.

## 3. Navigation — PASS

| New page | Entry point |
|---|---|
| `corex/rental-applications/index.blade.php` | sidebar `corex-sidebar.blade.php:1085` |
| `rental-applications/authorisation/index.blade.php` | sidebar `:1089` (`isRentalApplicationAuthoriser()`) |
| `rental-applications/create|show|review|generation-show|view-readonly` | buttons/links from index/show (route-model pages) |
| `corex/settings/rental-applications.blade.php` | Settings hub `settings.blade.php:104-105` |
| `rental-applications/decline-reason-templates/index.blade.php` | link from the settings page `settings/rental-applications.blade.php:890` |
| Imported Stock (`PropertyController::importedStock`, renders `corex.properties.index`) | sidebar `:845-847` |
| Rentals → Contacts / Properties / Pipeline / Core Matches (`/corex/rentals/*`) | sidebar `:1103-1143` |
| `admin/branches/_archive-wizard`, `_archived-panel` | partials of the existing branch admin page |
| Public `rental-applications/public/*` | token link in invite mail (no nav by design) |

Deleted `resources/views/corex/core-matches/all.blade.php`: `ContactMatchController::allView()` now renders `corex.core-matches.index` (`:458`); the only remaining `core-matches.all` strings are **route** names (`:115-124`). Every `view('corex.core-matches.*'|'corex.properties.*')` referenced by the two controllers exists on disk.

## 4. Setup Wizard parity (rule 10a) — F1

Wizard diff: `config/agency-onboarding-copy.php` +7 lines — one control, `rental_fee_max_amount` (PerformanceSetting, AT-402, saver `SettingsController@updateRentalFeeCeiling`). Everything else below is absent from the wizard.

| Table / key | Column(s) added in range | In wizard? | Decision on record? |
|---|---|---|---|
| performance_settings | `rental_fee_max_amount` | **YES** | — |
| agencies | `rental_application_ro_user_ids`, `rental_application_co_user_ids` (`2026_09_08_150000`; `_authoriser_user_ids` added then dropped) | No | Generic only: rental-applications.md:4978 "RO/CO tiers … never reached the wizard — reported to coordinator" |
| agency_contact_settings | `buyer_kanban_column_limit` (`2026_09_10_230000`) | No | Yes — rentals-shared-screens.md:835 "Deliberately NOT added … display/pagination threshold" |
| agency_contact_settings | `core_matches_working_window_days` (`2026_09_14_150300`) | No | Flagged, not decided — core-matches.md:541 "NOT added … Flagged for a ruling" |
| agency_contact_settings | `core_matches_price_drop_threshold_pct` (`2026_09_15_090100`) | No | **None found** (no spec mentions the column; core-matches.md mentions "price drop" only) |
| rental_application_qualifying_settings | `max_rent_percent_of_gross_income` (replaces `income_to_rent_multiplier`) | No | Generic only (:4978 "qualifying formula") |
| " | `reopen_link_expiry_days` | No | Yes — rental-applications.md:4970 "deliberately NOT in the onboarding wizard" |
| " | `lock_property_after_submission` | No | Yes — agency-onboarding-setup.md §5.1 (**pending Johan's confirmation**) |
| " | `tag_contact_as_tenant_on_approval` | No | Yes — §5.1 (**pending Johan**) |
| " | `autosave_debounce_seconds` | No | Flagged — :9201 "systemic gap, not attempted here" |
| " | `autosave_rate_limit_max`, `autosave_rate_limit_window_minutes` | No | Flagged — :10058 "parked … Johan's call" |
| " | `document_rate_limit_max`, `document_rate_limit_window_minutes` | No | **None found** |
| " | `document_uploads_open_after_approval` | No | **None found** (:7405 records the *checkbox pattern* for a sibling toggle, not this key) |
| " | `show_/submit_/pdf_/document_view_/autosave_request_rate_limit_max|_window_minutes` (10 cols) | No | **None found** |
| " | `require_fica_before_authorisation` | No | **None found** |
| " | `return_gate_method`, `return_gate_attempt_max`, `return_gate_attempt_window_minutes` | No | **None found** |
| " | `required_field_keys`, `marital_status_options` | No | **None found** |
| " | `identity_gate_enabled`, `_otp_length`, `_otp_expiry_minutes`, `_attempt_max`, `_attempt_window_minutes`, `_resend_cooldown_seconds` | No | **None found** |
| rental_application_decline_email_settings (new table) | `subject`, `body` | No | Generic only (:4978 "decline-email wording") |
| rental_application_approval_email_settings (new table) | `max_properties_in_email` | No | §5.1 mentions "approval-email matched-property cap" in passing as a sibling gap |
| rental_application_document_validity_windows (new table) | per-type windows | No | Flagged — :7053 "Deliberately NOT … flagged as a question for Johan" |

Reading: the lanes consistently *flagged* the gap for Johan rather than deciding it; nothing was silently omitted, but rule 10a's own text says a setting that exists only on the settings page is "not done" until Johan rules. That ruling has not been recorded for any of the rows above. Business consequence: a new agency onboarding through the wizard will never be told rental applications have a qualifying formula, RO/CO tiers, gates or rate limits — every one of those ships at its code default.

## 5. Event wiring — PASS

| Added | Registered in `AppServiceProvider::boot()`? |
|---|---|
| `Events/Branch/BranchArchived`, `BranchRestored` | Yes — event map `:688-689` → `Listeners\Agent\LogAgentEvent`; dispatched `BranchAssignmentController.php:248,410` |
| `Events/Contact/ContactMarkedLostInBuyerPipeline` → `Listeners\CoreMatches\SetAsideCoreMatchesOnBuyerLost` | Yes (diff hunk lines 78-79); dispatched `BuyerStateService.php:91` |
| `Events/Contact/ContactRestoredFromLostInBuyerPipeline` → `RestoreCoreMatchesOnBuyerRestored` | Yes (82-83); dispatched `BuyerStateService.php:93` |
| `Events/RentalApplication/{Submitted,Approved,Declined,Reopened}` → `Listeners\Contact\RecomputeRentalApplicationStatus` | Yes (`:728`, foreach over the four); dispatched `RentalApplicationSigningController:499`, `AuthorisationController:410,525`, `ReviewController:750` |
| `RentalApplicationApproved` → `Listeners\Contact\AddTenantTypeOnRentalApproval` | Yes (64-65) |
| `Events\AgencyCreated` → `Listeners\Onboarding\SeedDefaultRentalApplicationHighlighters`, `SeedDefaultRentalApplicationDeclineReasonTemplates` | Yes (13-14, 20-21) |
| `Events\Fica\FicaApproved` → `Listeners\RentalApplications\ResolveConditionalApprovalOnFicaVerified` | Yes (32-33) |
| `Observers/ContactNoteObserver` | Yes — `ContactNote::observe(...)` `:252` |

No new listener implements `ShouldQueue` (grep across all 7: none). The only queued class added is a Job (`FileRentalApplicationDecisionPdfJob`), which is the AT-261-correct shape.

## 6. Deploy prerequisites

| Item | Value | Notes |
|---|---|---|
| `env()` keys added (all inside `config/`) | `DATA_VOLUME_ROOT` (`config/filesystems.php:75`, default `/mnt/HC_Volume_103099143`); `RENTAL_APPLICATIONS_PDF_RENDER_WORKERS` (`config/rental_applications.php:9`, default 4) | No `env()` calls outside `config/` in the range. See F2. |
| External binaries | `pdftoppm` (via `config('splitter.pdftoppm_path')`, `RentalApplicationDocumentHighlightService.php:1053`); `pdfinfo` (hard-coded `'pdfinfo'`, `:925`) | Both poppler-utils, both already used by pre-existing code (`PdfSplitterController`, `DocumentFlattener`, `ViewingPackRedactionService`). `magick`/`convert` hits are comments stating Imagick is *not* used. |
| PHP extensions | GD (`imagecreatefrompng`, `imagecreatefromstring`, `imagecreatetruecolor` in the highlight service) | Pre-existing dependency (ViewingPack redaction, gallery repair). No Imagick, intl or zip added. |
| Queues | `FileRentalApplicationDecisionPdfJob` dispatched with **no `onQueue()`** → `default` (served). `tries=2`, `timeout=60`. | No new named queue. Mail is synchronous (F4). |
| Storage disks | `local` (28 refs), `public` (1), `$document->disk ?: 'local'` (2), **`data_volume` (NEW, `RentalApplicationPdfService::CACHE_DISK`)** | F2. |
| Scheduled commands | **None** — `routes/console.php` and `app/Console/Kernel.php` unchanged in range. | |
| New artisan commands | `properties:backfill-p24-imported` (**must be run by hand post-deploy** — F3), `core-matches:backfill-set-aside-lost-buyers {--dry-run}`, `deals:correct-share-percent-defect {--apply}` (dry-run default), `properties:sync-gallery-categories` | The two backfills are data steps the migrations do not perform; whether the set-aside backfill / share-percent correction should run on prod is a business call for the owning reviewers. |
| Permission provisioning | `deploy:sync-reference-data` → `corex:sync-permissions --merge-defaults` seeds the 9 new keys with `scope_defaults`; migrations `2026_09_10_040000` / `_100000` / `2026_09_12_100000` add `contact_rental_history.view`, `buyer_pipeline.view`, `rental_applications.archive` grants. | Standard deploy order (CLAUDE.md step h) covers it; skipping `sync-reference-data` leaves every role without the new keys. |
| Migrations | 89 new files; 5 use `DB::statement`; one hard-codes `agency_id = 1` (one-row scope repair, see §2). | Bodies are the migrations reviewer's scope. |

## 7. Gates — PASS

- E-sign pipeline: none of the 10 listed files changed in the range (`git diff --name-only` on the exact list → empty). `tests/Feature/Docuperfect/SigningView` has +103 lines anyway.
- Portal sync: none of dev-check §7's `$portalSyncFiles` (`Property24SyndicationService`, `Property24ListingMapper`, `Property24ApiClient`, `PrivatePropertySyndicationService`, `SubmitListingToProperty24`) changed. Four P24/PP files did change — `Jobs/ConfirmP24PropertyRowJob`, `Jobs/DownloadP24RowImagesJob` (importer) and `P24LeadService` / `PpLeadService` (leads) — all outside the gate list and off the refresh-cost path. No `tests/Feature/Syndication` diff, and none is required by the gate.

## 8. CHAT_STARTER / docs hygiene — F9

541 lines (>350); LIVE section does not reflect this promotion; header dated 2026-09-15; rule-5 text contradicts the amended two-lane rule.

## 9. Prod hazards in changed PHP — PASS

Grep over every added line of changed `app/`, `routes/`, `resources/` PHP for `dd(`, `dump(`, `ray(`, `var_dump(`, `->toSql()`, `localhost`, `127.0.0.1`, `http://`, `agency_id = 1`, `DB::statement`, `sleep(`, `set_time_limit(`, `ini_set(`: the only matches are SVG `xmlns="http://www.w3.org/2000/svg"` attributes in Blade. Nothing in request paths. (`DB::statement` and the agency-1 literal occur only in migrations — §6.) Dev scripts: F10.

## What was NOT checked here (owned elsewhere)

Rental-application business logic and the public form's data handling; core matches / DR2 multi-property correctness; migration bodies and `schema:dump` state; QA2-lane provenance. Where those overlap with a rule above, the overlap is stated in the finding.
