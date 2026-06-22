# CoreX System Atlas — Cross-Reference (reverse lookup)

> **Purpose.** "If I change value Y, what breaks?" Pick a table / column / setting below and see
> every documented feature that **READS** it and every feature that **WRITES** it.
>
> This index grows as each feature doc is added. **It is only as complete as the feature docs that
> feed it** — a feature not yet in `ATLAS_INDEX.md` as DONE may also touch these values without
> appearing here yet. Treat absence as "not yet documented", not "nothing else touches it".
>
> Last updated: 2026-06-22 · Seeded from: **Presentations, Properties, Prospecting/Tracked Properties,
> Market Intelligence Centre, CMA Report Import**.

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
| `PresentationGenerated` | Presentations — `PresentationGeneratorService.php:348` | (see `.ai/specs/corex-domain-events-spec.md` — TODO) |
| `PropertySuburbLinked` | Properties — store/update (`PropertyController.php:600-607,941-950`) | `LogContactEvent` / suburb link listeners (TODO) |
| `ContactLinkedToProperty` | Properties / contact-link path | `LogContactEvent` (logging only, `AppServiceProvider.php:380`) |
| `MarketReportParsed` | CMA Import — `ParseMarketReportJob.php:232-240` | GPS backfill + spot-check dispatch (TODO enumerate) |
| `OptOutRecorded` | Compliance/outreach — public link + agent-marked | `RecordOptOutOnContact` → `MarketingConsentService::optOutContact` (`:28-46`) |
| buyer state transitions (`BuyerStateTransition` rows, not an event) | Buyer Pipeline — `BuyerStateService::transitionTo` (`:53`) | append-only audit (reasons: wishlist_created/auto_landed/manual_override/auto_recompute/first_activity) |

---

## Cross-feature write-backs to the Property pillar (the surprising direction — watch these)

| Writer feature | Property columns written | Audited? |
|----------------|--------------------------|----------|
| Presentations — generator sectional backfill | `complex_name`, `unit_number` | **Yes** (AT-78, `PropertyAuditService` `:443-461`) |
| Presentations — `PropertyCmaPropagationService` | `erf_number`, `municipal_valuation`/`_year`, `cma_gps_lat/lng`, `title_deed_number`, `last_cma_at` | **No** — `DB::table()->update()` bypasses observer ⚠ |
| Presentations — GPS backfill | `latitude`, `longitude` | (TODO verify) |
| Match-or-Create — `promoteToStock` | mints new `Property` (`status='draft'`) | — |
| Browser autofill (not CoreX) | `unit_section_block` | No (client-side) ⚠ AT-78 |
