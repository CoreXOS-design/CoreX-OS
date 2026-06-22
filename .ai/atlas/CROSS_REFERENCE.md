# CoreX System Atlas — Cross-Reference (reverse lookup)

> **Purpose.** "If I change value Y, what breaks?" Pick a table / column / setting below and see
> every documented feature that **READS** it and every feature that **WRITES** it.
>
> This index grows as each feature doc is added. **It is only as complete as the feature docs that
> feed it** — a feature not yet in `ATLAS_INDEX.md` as DONE may also touch these values without
> appearing here yet. Treat absence as "not yet documented", not "nothing else touches it".
>
> Last updated: 2026-06-22 · Covers all 18 DONE feature docs (Property/Contact/Deal/Agent pillars, MIC,
> e-Sign, calendar, comms, HR, rentals, documents, AI ledger, syndication, and the platform foundations).

Legend: **R** = reads · **W** = writes · file:line points to the canonical access site.

---

## Property (`properties`) columns

| Column | READERS | WRITERS |
|--------|---------|---------|
| `complex_name` | Presentations — display address (`AnalysisDataService.php:178-185` → `Property::buildDisplayAddress`); Properties — `buildDisplayAddress` (`Property.php:398-452`) | **Presentations — generator backfill** (`PresentationGeneratorService.php:428`, audited `:443-461`) ⚠; Properties — manual edit (`PropertyController.php:401,404`; address modal `show.blade.php:2833`) |
| `unit_number` | Presentations — display address; Properties — display address + duplicate reset | **Presentations — generator backfill** (`PresentationGeneratorService.php:432`) ⚠; Properties — manual edit / `duplicate()` resets to null (`:997`) |
| `unit_section_block` | Properties — display address | Properties — manual edit / **browser autofill** (AT-78 vector, `show.blade.php:2829`) ⚠ |
| `condition_level_id` | Presentations — live condition uplift (`ConditionAdjustmentService.php:76`) | Properties — store/update validate `exists:property_setting_items` (`PropertyController.php:482,800`) |
| `title_type` | Presentations — sectional grouping / holding cost (`AnalysisDataService.php:64,1054`); CMA comparability | Properties — manual edit (`PropertySettingItem` options) |
| `latitude` / `longitude` | Presentations — comp geocoding/map; MIC/Match-or-Create GPS strategy | Presentations — GPS backfill (`PropertyGeoBackfillService`); Properties — `geocode()` (`PropertyController.php:1501`) |
| `cma_gps_lat` / `cma_gps_lng` | Match-or-Create GPS strategy 2 (`TrackedPropertyMatchOrCreateService.php:169-199`) | **Presentations — `PropertyCmaPropagationService::buildUpdates`** (`:374-424`, bypasses audit) ⚠ |
| `erf_number` | Match-or-Create erf strategy 3 (`:201-215`) | Presentations — `PropertyCmaPropagationService` (`:374-424`) |
| `municipal_valuation` / `_year` | (read by intel surfaces — TODO) | Presentations — `PropertyCmaPropagationService` (`:374-424`); enriched via Match-or-Create `NEWER_WINS` on TP |
| `title_deed_number` | (TODO) | Presentations — `PropertyCmaPropagationService` (`:374-424`) |
| `last_cma_at` | (TODO) | Presentations — `PropertyCmaPropagationService` (`:99`) |
| `features_json` / `features_json_meta` / `spaces_json` | **MIC/Core Matches** feature scoring (`MatchingService.php:500`, `PropertyMatchScoringService.php:708`); Syndication P24 mapper (Andre, doc-only); public website `ListingResource` | Properties — derived from `spaces_json` in `processSpacesJson()` (`PropertyController.php:1344-1380`) |
| `condition` levels / `price`, `beds`, `baths`, `garages`, `erf_size_m2`, `size_m2`, `floor_number` | Presentations (`hydrateFromProperty` `:483-509`); **MIC/Core Matches** (`MatchingService::score`); CMA comparability | Properties — store/update (`PropertyController.php:438,751`) |
| `street_number` / `street_name` (+ `_normalised`) / `suburb` (+ `_normalised`) / legacy `address` | Presentations — `SubjectReportResolver` matching + display; Match-or-Create dedup strategies 0/4/5 | Properties — store/update; `boot()` saving maintains normalised cache (`Property.php:259-266`) |
| `status` (string, default `draft`) | listing visibility, badges, syndication; index filter (`PropertyController.php:32`) | Properties — store/update (never nulls; `:585-590,899-905`); `promoteToStock` mints `draft` |
| `p24_*` / `pp_*` syndication columns | **Syndication (Andre — DOC-ONLY)** P24/PP mappers | Properties — manual edit / syndication (Andre) |

---

## Tracked-property / import tables

| Table / column | READERS | WRITERS |
|----------------|---------|---------|
| `tracked_properties` (canonical pool) | MIC scoring (canvass pool); CMA comparability (`CompetitorStockMatchService` via `prospecting_listings`) | **Match-or-Create** `create()`/`enrich()` (`TrackedPropertyMatchOrCreateService.php:435-515`); `promoteToStock` reads it to mint a Property (`:625-703`) |
| `tracked_property_external_refs` | Match-or-Create source-ref strategy 1 (`:147-167`) | Match-or-Create `writeExternalRef` (`:521,537`) |
| `tracked_property_addresses` | Match-or-Create address-history strategy 0 (`:278-335`) | Match-or-Create `appendIngestedAddressToHistory` (`:294,315,365,400`) |
| `source_chain` (json on TP) | audit trail | Match-or-Create append-only (`enrich`/`create`) |
| `prospecting_listings` | **MIC** (canvass worklist, `MarketIntelligenceController.php:72`); CMA comparability comps (`CompetitorStockMatchService::adaptCandidateRow`) | scraper Path B (`ProspectingApiController.php:158-178`); portal capture link |
| `portal_captures` / `portal_listings` | Presentations parsed data (`AnalysisDataService.php:47-49`); link to TP | scraper Path A (`PortalCaptureController.php`, `PortalListingTrackingService.php:96-103`) |
| `p24_listings` (email ISLAND) | **nothing downstream — invisible to scorers** (AT-81 §1.4) | `P24ImapImportService.php:161` (never Match-or-Create) ⚠ |

---

## Presentation tables

| Table | READERS | WRITERS |
|-------|---------|---------|
| `presentations` | Presentations (lifecycle) | Presentations — `PresentationGeneratorService.php:101-127` |
| `presentation_versions` | Presentations — PDF/public link (frozen `snapshot_payload`) | Presentations — compile `:302`; freeze at confirm (`PresentationController.php:478-483`) |
| `presentation_snapshots` | Presentations — analysis display (`computed_json`) | Presentations — `:288-299` |
| `presentation_sold_comps` | Presentations — CMA compute pool (`AnalysisDataService.php:31`, `CmaComputeService`) | Presentations — `MicSnapshotHydrator.php:74-79,174,439` (delete+recreate per run) |
| `presentation_active_listings` | Presentations — active-listings section (`AnalysisDataService.php:32`) | Presentations — `MicSnapshotHydrator.php:216` |
| `presentation_fields` (incl. `cma.lower/middle/upper_range`) | Presentations — parsed CMA-Info benchmark (`AnalysisDataService.php:516-518`) | **Pipeline A** `MicSnapshotHydrator::hydrateCmaMetrics` (`:996-1002`); **Pipeline B** `UploadExtractionService::propagateFields` (`:356-372`) ⚠ AT-82 |

---

## Market-report / intelligence tables

| Table / column | READERS | WRITERS |
|----------------|---------|---------|
| `market_reports` (`subject_address`, `subject_scheme_name`, `subject_section_number`, `source_suburb`) | Presentations — `SubjectReportResolver` (`:74-121`), generator backfill (`PresentationGeneratorService.php:412-419`) | **CMA Import** — `ParseMarketReportJob.php:98-122` (subject write-back) |
| `market_report_comp_rows` (`address`, `sale_price`, `sale_date`, `extent_m2`, `distance_to_subject_m`, `raw_row_json`) | Presentations — MIC hydrator sold comps (`MicSnapshotHydrator.php:571-608`) | **CMA Import** — `ParseMarketReportJob.php:151-164` |
| `market_data_points` (`cma_value_lower/middle/upper`, `cma_value_average`) | Pipeline A benchmark backfill (`MicSnapshotHydrator.php:971-994`) | **CMA Import** — `ParseMarketReportJob.php:124-148` (parser `CmaInfoVicinitySaleParser.php:202-244`) |
| `PropertySettingItem` (condition levels, title_type, property_status, category, type, mandate_type) | Properties dropdowns (`PropertyController.php:221-228`); Presentations condition labels (`ConditionAdjustmentService.php:67-71`) | Settings UI; seeders (`2026_06_17_120000`, `2026_03_30_100001` rename) |

---

## Buyer-match cache tables

| Table | READERS | WRITERS |
|-------|---------|---------|
| `prospecting_buyer_matches` (tier perfect/strong/approximate) | **MIC tile** `buyers_matched` (`MarketIntelligenceController.php:1778-1784`, canonical); prospecting buyer panels (`BuyerMatchTierService`) | **canonical (Engine A)** `recomputeProspectingMatches[ForBuyer]` (`PropertyMatchScoringService.php:449-579`); cmd `prospecting:recompute-matches` (04:00) |
| `property_buyer_matches` (tier varchar) | Presentations buyer demand (some paths); buyer portal `getMatchesForBuyer` | **legacy (Engine B)** `recomputeForBuyer` (`:384-442`); cmd `matches:recompute` (04:30) ⚠ scored by different engine than prospecting table |
| `contact_matches` (buyer wishlists) | **MIC** both engines (canonical criteria source); Core Matches; **Buyer Pipeline** countable gate (`ContactMatch.php:354-462`) | **Contacts** — `ContactMatchController::store/update`; `ContactMatchObserver::created` triggers auto-land (`:71-101`) + `saved/deleted` trigger `RegenerateBuyerMatchesJob` (`:118,151`) |

---

## Contact (`contacts`) columns

| Column | READERS | WRITERS |
|--------|---------|---------|
| `is_buyer` | **MIC** demand (`PropertyMatchScoringService.php:238,252,276`); **Buyer Pipeline** (`Contact::buyers()`) | **Buyer Pipeline** — `BuyerStateService::markActivity/landOnPipeline` (`:103-109,163`); Contacts edit |
| `buyer_state` | **Buyer Pipeline** board (`BuyerPipelineController.php:87-92`) | **Buyer Pipeline** — `BuyerStateService::transitionTo` (`:51`); cron `RecomputeBuyerStates` |
| `last_activity_at` | **Buyer Pipeline** `resolveState` (`BuyerStateService.php:29`) | `BuyerStateService::markActivity` (`:125`) |
| `buyer_pipeline_entered_at` / `_notes` | Buyer Pipeline display | `landOnPipeline` (`:163-173`); Contacts |
| structured address (`unit_number`,`complex_name`,`street_*`,`suburb`,`p24_*_id`) | **Properties** create prefill (`PropertyController.php:393-412`, one-way) | **Contacts** — `updatePropertyAddress` (`ContactController.php:584`) |
| residential `address` | display only | Contacts edit (independent of structured address) |
| `messaging_opt_out_*` / `messaging_all_blocked` / `messaging_opted_in_*` | **Compliance** comm status (`Contact.php:583-601`) | **Compliance** — `MarketingConsentService::optOut/optInContact` (`:72-85,165-203`) |
| `opt_out_email/sms/whatsapp/call` | `canSendVia` (`Contact.php:487-510`); outreach gate | `MarketingConsentService` (`:97`); `recomputeChannelConsent` (`Contact.php:516`) |
| `preapproval_amount/expires_at/institution` | MIC preapproval gating (`hasValidPreapproval` `Contact.php:79`) | Contacts edit |
| `whatsapp_count` / `email_count` / `last_contacted_at` | comms tiles (AT-59, `outboundCommCount` `:721`) | comms ingest; `touchLastContacted` (`:743`) |
| `client_user_id` | portal login link (`hasClientLogin` `:129`) | `Contacts\ClientLoginController::create` (`:50`) |

---

## Contact / Buyer / Compliance support tables

| Table | READERS | WRITERS |
|-------|---------|---------|
| `buyer_state_transitions` (agency_id NOT NULL post-`2026_05_23_030800`) | `isManualPlacementProtected` (`BuyerStateService.php:222`); audit | `BuyerStateService::transitionTo` (`:53-66`, explicit agency_id `:59`) |
| `buyer_lost_records` | lost reporting | `BuyerStateService.php:70-87` (auto→lost) |
| `BuyerActivityLog` | activity audit | `BuyerStateService::markActivity` (`:112-122`) |
| `contact_consent_records` (tri-state) | `Contact::consentDecision/canSendVia`; `recomputeChannelConsent` (`:516`) | `setConsent/revokeConsent` (`Contact.php:412-474`); `MarketingConsentService` (`:91-95`) |
| `marketing_suppressions` (agency_id + identifier) | outreach gate (`isContactSuppressed` `:216-227`); admin list | `MarketingConsentService::writeSuppression` (`:244-274`); admin `lift` = opt-in |
| `fica_submissions` | e-Sign gate (`SignatureRequest.fica_submission_id`); marketing readiness (`:115-119`) | `FicaController::complianceApprove` (`:326-347`, stamps 24-mo expiry); `FicaPublicController::submit` |
| `whistleblow_complaints` / `_subjects` | `WhistleblowController` index/show; lawyer pack | `WhistleblowController::store` (`:79-177`); `sendToPpra` |
| `contact_property` (pivot, role) | Properties; Contacts; Presentations seller link | Properties store/link; `ContactPropertyController` |
| `client_users` (cross-agency portal credential) | portal auth | `ClientLoginController::create` (`:42`) |

---

## Deal / Commission tables

| Table | READERS | WRITERS |
|-------|---------|---------|
| `deals` (V1) | `Admin/Agent DealController`; `Api\V1\DealsController`; finance audit | **Deals V1** — `DealController::store` (`:231`) / `saveSettlement` (`:622-750`) |
| `deal_user` (pivot: side/splits/PAYE) | V1 settlement allocations (`Deal::agents()` `:155`) | V1 settlement |
| `deal_money_lines` (canonical computed money) | settlement print/payslip; finance | **both** V1 (`DealMoneyLineRebuilder`) AND V2 (`DealV2SettlementController`) ⚠ |
| `deals_v2` + `deal_v2_contacts`/`_agents`/step tables | `DealV2Controller`; pipeline service | **Deals V2** — `DealPipelineService::createDeal` (`:18`) + step lifecycle |
| `commission_ledger` / `revenue_share_ledger` / `agent_cap_periods` | `CommissionController` dashboards (read-only `:29-207`) | **ORPHANED** — `CommissionCalculationService` never invoked ⚠ BACKLOG |
| `presentations.presentation_id` ← `deals` | deal↔presentation link (Phase 3i) | `DealPropertyLinkService` |

---

## E-Sign / Document tables

| Table | READERS | WRITERS |
|-------|---------|---------|
| `docuperfect_templates` (party_mode, allowed_delivery_modes, is_esign, signing_parties) | **E-Sign** wizard + signing | `TemplateController` (CDS builder) |
| `docuperfect_documents` (+`signed_paginated_html`) | **E-Sign** signing/PDF | wizard `store`; signing pipeline |
| `signature_requests` (fica_required, fica_submission_id, role_index) | **E-Sign** signing + FICA gate (`SigningController.php:124`) | `SignatureService`; FICA gate links `fica_submission_id` |
| `signature_markers` / `signature_zones` | signing-view rendering | `SignatureController` setup/saveMarkers |
| `document_amendments` / `document_conditions` | signing P0 inline mutations | `SigningController::addCondition` (`:3166`), `AmendmentController` |
| `esign_consent_log` | compliance/audit | **E-Sign** — **IMMUTABLE** (throws on update `ESignConsentLog.php:61-66`) |
| `documents` + `document_contacts`/`document_properties` (plural unified pivots) | Document Library; Contacts; Properties | **E-Sign** `autoFileSignedDocument` (`:2091`, `syncWithoutDetaching`) ⚠ also a legacy `document_contact` singular pivot exists |
| `agency_signing_parties` | signature-block party names | `DocumentImporterController` |
| `contact_types.esign_role` | **E-Sign** wizard recipient filter (`ESignWizardController.php:509-514`) | Settings → Contact Types |
| `fica_submissions` ← (read by esign gate) | **E-Sign** gate; Compliance | Compliance `FicaController` (see compliance.md) |

---

## Calendar tables

| Table | READERS | WRITERS |
|-------|---------|---------|
| `calendar_events` (`category` = event-class slug, `colour`, morph `source`) | **Calendar** UI; Threshold/Visibility resolvers | `ReconcileCalendarEvents` (sources, upsert `:96-104`); `CalendarController::store` (manual) |
| `calendar_event_links` (morph pivot) | `linkedProperties/Contacts/Deals` | `CalendarController::syncEventLinks` (`:1460`) |
| `calendar_event_feedback` (per-contact/per-property) | viewing-feedback display; buyer arc | **Calendar** `storeFeedback` (`:702`, `updateOrCreate` keyed event+contact+property) |
| `calendar_event_audit_log` (append-only) | audit | `CalendarController` (`CalendarEventAuditEntry::create`) |
| `buyer_property_views` | **MIC** match scoring (`PropertyMatchScoringService.php:231-234`); buyer demand | **Calendar** `storeFeedback` (`:836-845`); `RecomputeBuyerPropertyViews` cmd (source of truth) |
| `calendar_event_class_settings` | the three resolvers (RAG/visibility/notify) | `SettingsController::updateEventClass` (upsert agency_id+event_class) |

Calendar **fed by** 8 sources (`AppServiceProvider.php:155-163`): Deal, Compliance (FFC/PI/tax/FICA),
Payroll, People (birthdays), Property, Rental, Document, Recurring. **Notifies via** two dispatchers
(`CalendarNotificationDispatcher` = RAG transitions, no push; generic `NotificationDispatcher` = feedback
arc, FCM push, open-hours-droppable).

---

## Communications tables (LIVE)

| Table | READERS | WRITERS |
|-------|---------|---------|
| `communications` (external_id unique per agency, provisional_at, text_hash, purged_at) | **AT-59 tiles** (`Contact::outboundCommCount` `:721`); archive viewer; triage | WA ingest (`WaArchiveIngestor`), email ingest (`EmailArchiveIngestor`), provisional log (`OutboundProvisionalLogger`), reconcile (`ProvisionalReconciler`) |
| `communication_links` (morph pivot, link_method/confirmed_at) | `Contact::communications()` (`:700`) | ingestors + provisional logger (Contact only; no Deal links) |
| `communication_wa_devices` (device_token SHA-256, active) | `AuthenticateWaCapture` middleware (`:24-29`) | `WaDeviceController` (register/revoke active=false) |
| `communication_mailboxes` (+credential reveals) | `ImapMailboxPoller` | mailbox config UI (write-only creds; audited reveal) |
| `communication_pending` (grace buffer, expires_at) | reconcile/triage | ingestors when no contact match |
| `communication_flags` / `_flag_alerts` | BM flag register | triage |
| `marketing_suppressions` | comms send gate (`isContactSuppressed`) + Compliance | `MarketingConsentService::writeSuppression` (see compliance.md) |

---

## Payroll / Leave tables

| Table | READERS | WRITERS |
|-------|---------|---------|
| `leave_transactions` (the ledger — append-only, no SoftDeletes) | `LeaveBalanceService::getBalance` (ledger-derived) | `LeaveAccrualService` (accrual/rollover/manual), `LeaveApplicationController::approve` (application_approved), take-on wizard |
| `leave_applications` (SoftDeletes) | balances (pending derived); calendar | `MyPortalLeaveController::store`; approve/reject |
| `leave_entitlements` (cache, rebuildable) | balance display | `LeaveBalanceService::refreshEntitlement`; `corex:leave:recalculate` |
| `leave_types` (per-type BCEA config: cycle_months/accrual_method/rate) | accrual engine; calendar colours | Leave Types CRUD; `LeaveTypeSeeder` |
| `public_holidays` (country-level) | working-days calc (`PublicHolidayService`) | `PublicHolidayService::generateHolidaysForYear` |
| `payroll_payslips.paye_amount` | EMP201/IRP5 (manual); finalise totals | `PayrollCalculator::calculatePaye` (SARS engine) |
| `payroll_runs` | runs UI; calendar `payroll_run` event; SDL obligation calc | `PayrollRunController::store`/`finalise` |
| `payroll_tax_tables` / `payroll_tax_rebates` (reference, no SoftDeletes) | `PayrollCalculator` (`forTaxYear`) | seeders |

> **⚠ PAYE duality:** `payroll_payslips.paye_amount` (SARS engine) and `deal_money_lines.paye_amount`
> (`DealMoneyLineRebuilder:202-210`, flat per-deal) are **two unreconciled PAYE figures** — never summed for
> SARS. See `payroll-leave.md` §5 and `deals-commission.md` §6.

---

## Rental / Lease tables

| Table | READERS | WRITERS |
|-------|---------|---------|
| `rentals` (System A — address string, branch_id only, **no property/contact/agency FK**) | rental commission UI; worksheet; calendar `rent_due`/`rent_escalation` | `RentalsController::store` (`:309`) |
| `rental_amount_versions` | rent/commission history; calendar `rent_escalation` | `RentalsController` |
| `lease_records` (System B — tenant/landlord strings, `property_id` nullable) | Rental Division active/expired views; calendar `lease_expiry` (joins to `properties`) | **E-Sign** `SignatureService::createLeaseRecord` (`:2561`); `LeaseController` renew/terminate |
| `rental_properties` (**standalone island, no FK to `properties`**) | Rental Division settings | `RentalPropertyController` |
| `properties.deposit_amount` / rental columns | documents/CMA | Properties edit (NOT read by Rental models) |

> ⚠ `lease_records.property_id` ID-space collision: joined to real `properties` in `RentalCalendarSource:51`
> but set from `rental_properties.id` in `RentalDivisionController:104-107`. See `rentals-leases.md` §9.

---

## Document / Filing tables

| Table | READERS | WRITERS |
|-------|---------|---------|
| `documents` (unified, `source_type`, `storage_path`) | Document Library; contact drive (`Contact::documents()`); property files tab | **E-Sign** (`SignatureService:1870/1979`), **FICA** (`FicaController:827`), **Payroll** (`PayrollFinaliseService:76`), manual uploads, PDF splitter, leave docs |
| `document_contacts` / `document_properties` (plural, unified pivots) | `Document::contacts()/properties()` | `linkFiledDocumentToContactsAndProperty` (`:2094`, syncWithoutDetaching) |
| `document_contact` (singular, legacy, → `docuperfect_documents`) ⚠ | `Contact::signedDocuments()`/`ficaDocuments()` | `SignatureService::linkDocumentToContacts` (raw `updateOrInsert` `:1783`) |
| `document_filing_register` (metadata-only, no file) | Filing Register UI | **manual entry only** `DocumentFilingController::store` (`:116`) |
| `document_library_items` | Presentation attachment | `DocumentLibraryController` |

> ⚠ Two pivot lineages (singular vs plural) both written on one e-sign completion against different records.
> See `document-library-filing.md` §9.1 and `esign-docuperfect.md` §6.

---

## AI cost ledger

| Table / column | READERS | WRITERS |
|----------------|---------|---------|
| `ai_usage_events` (model, tokens, `cost_zar`, source, agency_id; append-only, no SoftDeletes) | `AiUsageController` dashboard; `AICostAggregator`; `Agency::aiBudgetUsedZar` (budget cap) | `AiUsageRecorder::record` (`:45`) — gateway + 7 direct callers (Anthropic only; **OpenAI/Ellie-chat/Whisper unmetered**) |
| `Agency.ai_monthly_budget_zar` / `ai_budget_*_pct` | budget cap (`AnthropicGateway::loadCappedAgency:552`) | `AiUsageController::updateBudget` (super_admin) |
| `Agency.ai_image_recognition_enabled` / `ai_voice_enabled` | Properties AI photos; Ellie voice gate | Settings UI |

---

## Syndication columns (Andre's domain — reads/writes for cross-reference)

| Column / table | READ BY (syndication) | WRITTEN BY (syndication) |
|----------------|----------------------|--------------------------|
| `properties.features_json` / `spaces_json` | **P24 mapper** (`Property24ListingMapper:130-132`, own inline vocab); PP mapper does NOT read them | — (written by Properties — see Property columns table) |
| `properties.p24_*` (ref/status/enabled/timestamps/suburb_id) | P24 submit/sync | `Property24SyndicationService::submitListing` (`:137-162`) |
| `properties.pp_*` (ref/status/enabled/timestamps/suburb_id/hide) | PP submit/sync | `PrivatePropertySyndicationService::submitListing` (`:86-124`) |
| `p24_suburbs` / `pp_suburbs` | suburb-id resolution (both mappers) | `SyncP24Locations` / `SyncPpLocations` (location import) |
| `p24_syndication_logs` | audit | `Property24ApiClient::logToDb` (`:325`) |

---

## Platform foundations (what every feature depends on)

| Mechanism | Enforced by | Notes |
|-----------|-------------|-------|
| Agency isolation | `AgencyScope` (`WHERE agency_id = effective`, NULL = orphan) + `BelongsToAgency` (auto-stamp on create) | 208 models; `effectiveAgencyId` `User.php:332` |
| Branch isolation | `BranchScope` (gated on `split_branches_enabled` + `branches.view_all`) + `BelongsToBranch` | 20 models; data-scope all/branch/own via `PermissionService::getDataScope:59` |
| Domain-event audit | `domain_event_log` + wildcard `RecordDomainEvent` (`AppServiceProvider:172`) | only catches `AbstractDomainEvent` subclasses ⚠ |
| Soft-delete-everywhere | `SoftDeletes` (238 models) + `SoftDeleteRegistryService` (restore-only) | only hard-delete = console `db:purge-soft-deleted` |
| Audit observer | `PropertyAuditService` via `PropertyObserver` → `property_audit_log` | `DB::table()->update()` bypasses it ⚠ |

---

## Agency settings (`agencies` + `agency_contact_settings` columns)

| Setting | Default | READERS | WRITERS |
|---------|---------|---------|---------|
| `cma_hide_display_outliers` | `true` | Presentations (`AnalysisDataService.php:119`) | Settings UI (`CoreXSettingsController`) |
| `cma_compute_iqr_multiplier` | `1.50` | Presentations (`CmaComputeService.php:98`); CMA Import does NOT use it for parsed scalars | Settings UI |
| `cma_compute_recency_months` | `36` | Presentations (`CmaComputeService.php:97`) | Settings UI |
| `cma_band_lower_pct` / `cma_band_upper_pct` | `7%` | Presentations (`CmaComputeService.php:112-113`) | Settings UI |
| `range_lower_pct` / `range_upper_pct` | textbook quartiles | Presentations (`CmaComputeService.php:103-104`) | Settings UI |
| `competitor_stock_default_display_count` / `_price_tolerance_pct` / `min_score` | `10` / `20` / `50` | Presentations (`AnalysisDataService.php:931`, `CompetitorStockMatchService`) | Settings UI |
| `ss_show_complex_section` | `true` | Presentations (`AnalysisDataService.php:110`) | Settings UI |
| `presentations_default_comp_scope` / `_radius_m` | `radius_all` / `1000` | Presentations (`PresentationGeneratorService.php:136-140`) | Settings UI |
| `presentations_default_rates/insurance/utilities/garden/pool/security_zar` | `800/200/1200/800/600/1500` | Presentations holding cost (`AnalysisDataService.php:1083-1094`) | Settings UI |
| `presentations_default_opportunity_cost_pct` | `8` | Presentations (`AnalysisDataService.php:1083`) | Settings UI |
| `presentations_default_show_*` (9 toggles) | bools | Presentations section gates | Settings UI |
| **`mic_match_threshold`** | **75** | **MIC** tile/KPI (`MarketIntelligenceController.php:1777`), band default `:295` (`AgencyContactSettings::micMatchThreshold` `:93-97`) | Settings UI (migration `2026_06_21_062000`) |
| **`mic_price_band_pct`** | **10** | **MIC** canonical `score()` band (`PropertyMatchScoringService.php:472,541` via `micPriceBandFraction` `:100-104`) | Settings UI |
| `matches_enabled` / `matches_show_on_properties` | `PerformanceSetting` | Properties Core Matches tab (`show.blade.php:1392`) | Settings UI |
| `ai_image_recognition_enabled` | bool | Properties AI photo suggestions (`PropertyController.php:360`) | Settings UI |
| `address_match_mode` | `'standard'` | Properties create-from-contact guard (`ContactAddressPropertyGuard.php:76`) | Settings UI |
| `buyer_warm_days` / `buyer_cold_days` / `buyer_lost_days` | 14 / 30 / 60 | **Buyer Pipeline** `BuyerStateService` (`:31,34`); protection window = cold_days (`:232`); `lost_days` defined but unused in `resolveState` | Settings UI |
| `min_countable_criteria` (AT-71) | `['any']` | **Buyer Pipeline / MIC** countable gate (`ContactMatch.php:380,414`) | Settings UI |
| `buyer_pipeline_default_scope` | `'own'` | **Buyer Pipeline** scope (`BuyerPipelineController.php:135-138`) | Settings UI |
| `contact_retention_years` / `consent_retention_years` / `access_log_retention_years` | 5 / 5 / 5 | **Compliance** `PurgeContactRetention` cron (`:31,67,85`) | Contact Governance UI (`min:5\|max:99`) |
| `whistleblow_approver_user_ids` | admin/BM/super_admin fallback | **Compliance** approver gate (`WhistleblowController.php:257-271`) | agency settings |
| per-report-type `auto_approve` | bool | CMA Import spot-check skip (`ParseMarketReportJob.php:253`) | report-type settings |
| `vat_rate` (PerformanceSetting) | 15 | **Deals/Commission** inc→ex VAT (`Finance/CommissionCalculator.php:11-13`) | Settings UI |

> Note: **no agency setting is keyed `mic_`** in the *Presentations* path. `mic_snapshot_v1` is a parser
> source-tag; `mic.*` are permissions. The `mic_match_threshold`/`mic_price_band_pct` knobs belong to the
> **MIC** feature and live on `agency_contact_settings`, not `agencies`.

---

## config / feature flags

| Flag | READERS | Notes |
|------|---------|-------|
| `config/features.php` `presentations` (`:4`), `presentation_pdf_v1` (`:11`), `presentation_blueprint` (`:5`) | Presentations / PDF (`PresentationPdfController.php:42,81`) | feature gates |
| `config/presentations.php` (`:19-33`) | `PresentationPdfService` (pagination) | pagination tuning only |
| `config/property-spaces.php` (`all_space_types`, `space_features`, `default_space_features`, `half_unit_spaces`) | Properties editor; mobile API; public website `ListingResource`; AI suggestor; hand-synced copies in `show.blade.php` JS + `VisionRecognitionService` | spaces/feature taxonomy (AT-81 §2 — keep shape byte-identical) |
| `AbstractCmaInfoParser::parsePriceBounded` 50k–50M window (`:153-159`) | CMA Import | hard-coded outlier guard on parsed prices |

---

## Domain events

| Event | EMITTED BY | LISTENERS |
|-------|-----------|-----------|
| `PresentationGenerated` | Presentations — `PresentationGeneratorService.php:348` | ⚠ does NOT extend `AbstractDomainEvent` → writes **no** `domain_event_log` row (`platform-multitenancy.md` §7.5) |
| `domain_event_log` (the audit sink) | audit/forensics (e.g. AT-78 used it) | wildcard `RecordDomainEvent` for every `AbstractDomainEvent` subclass (`AppServiceProvider:172`) |
| `PropertySuburbLinked` | Properties — store/update (`PropertyController.php:600-607,941-950`) | `LogContactEvent` / suburb link listeners (TODO) |
| `ContactLinkedToProperty` | Properties / contact-link path | `LogContactEvent` (logging only, `AppServiceProvider.php:380`) |
| `MarketReportParsed` | CMA Import — `ParseMarketReportJob.php:232-240` | GPS backfill + spot-check dispatch (TODO enumerate) |
| `OptOutRecorded` | Compliance/outreach — public link + agent-marked | `RecordOptOutOnContact` → `MarketingConsentService::optOutContact` (`:28-46`) |
| buyer state transitions (`BuyerStateTransition` rows, not an event) | Buyer Pipeline — `BuyerStateService::transitionTo` (`:53`) | append-only audit (reasons: wishlist_created/auto_landed/manual_override/auto_recompute/first_activity) |
| `property.feedback_captured` (notification key) | Calendar feedback arc — `CalendarController::storeFeedback` (`:900-910`) | listing agent notified (cross-agent, skip-if-self `:889-890`); migration `2026_06_30_000002` |
| `EventDueReminderNotification` | Calendar `CalendarNotificationDispatcher::onColourTransition` (`:26`) | role recipients per class config (no push) |
| `PillarEventNotification` (generic, FCM) | `CommandCenter/NotificationDispatcher::fire` (`:28`) | per-user prefs; open-hours-droppable (`:44`) |
| `MarketReportParsed`, `PresentationGenerated`, `OptOutRecorded` | (see rows above) | — |

---

## Cross-feature write-backs to the Property pillar (the surprising direction — watch these)

| Writer feature | Property columns written | Audited? |
|----------------|--------------------------|----------|
| Presentations — generator sectional backfill | `complex_name`, `unit_number` | **Yes** (AT-78, `PropertyAuditService` `:443-461`) |
| Presentations — `PropertyCmaPropagationService` | `erf_number`, `municipal_valuation`/`_year`, `cma_gps_lat/lng`, `title_deed_number`, `last_cma_at` | **No** — `DB::table()->update()` bypasses observer ⚠ |
| Presentations — GPS backfill | `latitude`, `longitude` | (TODO verify) |
| Match-or-Create — `promoteToStock` | mints new `Property` (`status='draft'`) | — |
| Browser autofill (not CoreX) | `unit_section_block` | No (client-side) ⚠ AT-78 |
