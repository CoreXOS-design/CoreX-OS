# WAVE 3b — agency_id Backfill Plan

> Generated 2026-05-23. Author: Claude (Opus 4.7). For human (Andre/Johan) review **BEFORE** any migration files are written or executed.
>
> Scope: Add `agency_id` + `BelongsToAgency` trait to every tenant-owned model whose table currently lacks the column.

---

## 1. Universe

- Total tables in `hfc_dash`: **264**
- Tables already carrying `agency_id`: **99**
- Tables WITHOUT `agency_id`: **165**

After exclusions (below), **~95 tables** remain as Category B candidates, planned across **10 batches** of ~10 each.

---

## 2. Exclusions (will NOT be touched)

### 2a. Per user directive

| Table | Reason |
|-------|--------|
| `esign_consent_log`, `esign_signing_parties` | E-sign wizard (user excluded all `Esign*` / `ESign*`) |
| `signature_*` (signatures, signature_audit_log, signature_markers, signature_requests, signature_templates, signature_zones) | E-sign wizard internals |
| `signed_document_versions`, `wet_ink_inspections`, `amendment_acceptances`, `section_acceptances` | E-sign wizard internals |
| `flows` | Docuperfect Flow model — drives e-sign wizard, exclude with the rest |
| `roles`, `role_permissions` | Role model excluded by directive (ambiguous global/per-agency) |
| `agency_access_requests`, `agency_access_request_admins` | Cross-agency by design |
| `agencies` | The Agency model itself |
| `public_holidays`, `payroll_tax_rebates`, `payroll_tax_tables`, `p24_countries`, `p24_provinces`, `p24_cities`, `p24_suburbs`, `dev_settings`, `nexus_permissions`, `device_tokens`, `impersonation_logs`, `contact_types`, `designations`, `document_types`, `document_library_types`, `rental_document_types`, `docuperfect_document_types`, `docuperfect_named_fields`, `notification_event_types`, `finance_definitions`, `splitter_doc_types` (not present) | Pure lookup tables / catalogs |

### 2b. Framework / infrastructure (always global)

| Table | Reason |
|-------|--------|
| `migrations`, `cache`, `cache_locks`, `sessions`, `jobs`, `failed_jobs`, `job_batches`, `password_reset_tokens`, `personal_access_tokens`, `notifications`, `notification_dispatch_log`, `automation_log`, `domain_event_log` | Framework / queue / cache / audit infrastructure |

### 2c. Pivot tables (tenancy already enforced via parent scope, agency_id would be redundant and risk drift)

| Table | Parent(s) |
|-------|-----------|
| `deal_user`, `deal_branches`, `deal_v2_agents`, `deal_v2_contacts` | deals / branches |
| `contact_property`, `contact_tag` (pivot), `document_contact`, `document_contacts`, `document_properties` | contacts / properties / documents |
| `branch_assignments`, `listing_stock_agents`, `rental_agents` | branches / users |
| `docuperfect_clause_branches`, `docuperfect_pack_branches`, `docuperfect_template_branches`, `docuperfect_pack_templates` | docuperfect templates / branches |
| `training_completions`, `training_doc_bookmarks`, `training_doc_chunks`, `training_progress` | training_courses / users (pivot/junction) |
| `feedback_attachments`, `whistleblow_complaint_subjects`, `whistleblow_complaint_evidence` | parent feedback / complaint |
| `agency_access_request_admins` | covered in 2a |
| `application_documents`, `onboarding_checklists` | scoped to agent_applications (already tenant-scoped) — pivot/child of pivot |

> **Rationale for excluding pivots:** Pivot rows have two parents that each carry `agency_id`. Adding a third copy of `agency_id` to the pivot row creates a denormalisation that can drift if either parent is reassigned. The global scope already filters one parent; the join naturally filters the other.

### 2d. User-scoped UI/preference tables (single-user data, scoping by Auth user is stronger than scoping by agency)

These tables store per-user preferences/settings only. Tenancy is enforced by the user_id FK — adding agency_id would be cosmetic redundancy.

| Table | Reason |
|-------|--------|
| `calendar_user_preferences`, `user_dashboard_settings`, `user_notification_preferences`, `user_oversight_preferences` (already has agency_id), `device_tokens` | Single-user prefs |
| `ai_conversations`, `ai_daily_briefings`, `ai_messages`, `ai_feedback` | Per-user AI history (private to user) |

> **Decision needed (human):** if owners switching agencies want their AI/dashboard prefs scoped per-agency-context, we'd add agency_id here. Default: keep per-user-only for now.

### 2e. No-parent-path / orphan

| Table | FK columns | Notes |
|-------|-----------|-------|
| `article_pool` | none | Global content pool? Skipped — needs human call |
| `company_expenses` | none | No FK at all — needs human call (likely tenant) |
| `contact_sources`, `contact_tags` | none | Lookup-ish, but agency-customisable? Skipped — needs human call |
| `property_setting_items` | none | Looks tenant — but no FK; skip |
| `deposit_trust_interest` | none | Skip — needs human call |
| `pp_event_feed_settings`, `pdf_splitter_feedback`, `pdf_splitter_learned_phrases`, `performance_settings` | none | Likely global. Skip. |
| `client_signin_attempts`, `client_users`, `client_otps` | client portal accounts | Client portal — has its own scoping (`current_agency_id`); needs separate decision. **Skip in this wave.** |
| `activity_columns`, `activity_definitions`, `activity_point_goals` | weak FKs | Activity catalog — partly global. Skip. |
| `p24_listings`, `p24_import_log` | none directly | P24 portal-level. Skip — separate triage. |
| `prospecting_price_history` | `prospecting_listing_id` | Could be tenant-scoped via tracked_property, but prospecting_listings sometimes are global pre-claim. Skip — needs human call. |
| `portal_captures`, `portal_listings`, `portal_listing_observations` | mixed | Portal capture is cross-agency intelligence; skip. |

---

## 3. Category B — IN SCOPE (95 tables across 10 batches)

Parent FK column shown is the chosen backfill source. `→` indicates the parent table whose `agency_id` we read.

### Batch 1 — Deal children (10)

| # | Table | Model | Parent FK | Backfill |
|---|-------|-------|-----------|----------|
| 1 | `deal_logs` | `App\Models\DealLog` | `deal_id` | → `deals.agency_id` |
| 2 | `deal_money_lines` | `App\Models\DealMoneyLine` | `deal_id` | → `deals.agency_id` |
| 3 | `deal_settlements` | `App\Models\DealSettlement` | `deal_id` | → `deals.agency_id` |
| 4 | `deals_v2` | `App\Models\DealV2\DealV2` | `branch_id` | → `branches.agency_id` |
| 5 | `deal_activity_log` | `App\Models\DealV2\DealActivityLog` | `deal_id` | → `deals_v2.agency_id` (sequential within batch — applied after #4) |
| 6 | `deal_pipeline_templates` | `App\Models\DealV2\DealPipelineTemplate` | `branch_id` | → `branches.agency_id` |
| 7 | `deal_pipeline_steps` | `App\Models\DealV2\DealPipelineStep` | `pipeline_template_id` | → `deal_pipeline_templates.agency_id` |
| 8 | `deal_step_instances` | `App\Models\DealV2\DealStepInstance` | `deal_id` | → `deals_v2.agency_id` |
| 9 | `deal_step_documents` | `App\Models\DealV2\DealStepDocument` | `deal_step_instance_id` | → `deal_step_instances.agency_id` |
| 10 | `deal_v2_settlements` | `App\Models\DealV2\DealV2Settlement` | `deal_id` | → `deals_v2.agency_id` |

### Batch 2 — Property children (10)

| # | Table | Model | Parent FK | Backfill |
|---|-------|-------|-----------|----------|
| 1 | `property_files` | `App\Models\PropertyFile` | `property_id` | → `properties.agency_id` |
| 2 | `property_notes` | `App\Models\PropertyNote` | `property_id` | → `properties.agency_id` |
| 3 | `property_showdays` | `App\Models\PropertyShowday` | `property_id` | → `properties.agency_id` |
| 4 | `property_marketing_activities` | `App\Models\PropertyMarketingActivity` | `property_id` | → `properties.agency_id` |
| 5 | `property_marketing_posts` | `App\Models\PropertyMarketingPost` | `property_id` | → `properties.agency_id` |
| 6 | `property_presentation_snapshots` | `App\Models\PropertyPresentationSnapshot` | `property_id` | → `properties.agency_id` |
| 7 | `property_seller_links` | `App\Models\PropertySellerLink` | `property_id` | → `properties.agency_id` |
| 8 | `property_seller_link_accesses` | (link audit, no model?) | `link_id` | → `property_seller_links.agency_id` |
| 9 | `property_ad_templates` | `App\Models\PropertyAdTemplate` | `user_id` | → `users.agency_id` |
| 10 | `property_health_scores` | `App\Models\CommandCenter\PropertyHealthScore` | `property_id` | → `properties.agency_id` |

### Batch 3 — Contact children (8)

| # | Table | Model | Parent FK | Backfill |
|---|-------|-------|-----------|----------|
| 1 | `contact_notes` | `App\Models\ContactNote` | `contact_id` | → `contacts.agency_id` |
| 2 | `contact_documents` | `App\Models\ContactDocument` | `contact_id` | → `contacts.agency_id` |
| 3 | `contact_match_feedback` | `App\Models\ContactMatchFeedback` | `contact_match_id` | → `contact_matches.agency_id` |
| 4 | `contact_match_notifications` | `App\Models\ContactMatchNotification` | `contact_match_id` | → `contact_matches.agency_id` |
| 5 | `buyer_property_views` | `App\Models\BuyerPropertyView` | `contact_id` | → `contacts.agency_id` |
| 6 | `buyer_property_responses` | (no model?) | `contact_id` | → `contacts.agency_id` |
| 7 | `buyer_preferences` | (no model?) | `contact_id` | → `contacts.agency_id` |
| 8 | `buyer_state_transitions` | `App\Models\BuyerStateTransition` | `contact_id` | → `contacts.agency_id` |
| 9 | `buyer_lost_risk_scores` | (no model?) | `contact_id` | → `contacts.agency_id` |
| 10 | `buyer_portal_links` | (no model?) | `contact_id` | → `contacts.agency_id` |

### Batch 4 — Presentation children (10)

| # | Table | Model | Parent FK | Backfill |
|---|-------|-------|-----------|----------|
| 1 | `presentation_active_listings` | `App\Models\PresentationActiveListing` | `presentation_id` | → `presentations.agency_id` |
| 2 | `presentation_articles` | `App\Models\PresentationArticle` | `presentation_id` | → `presentations.agency_id` |
| 3 | `presentation_document_library_items` | `App\Models\PresentationDocumentLibraryItem` | `presentation_id` | → `presentations.agency_id` |
| 4 | `presentation_fields` | `App\Models\PresentationField` | `presentation_id` | → `presentations.agency_id` |
| 5 | `presentation_links` | `App\Models\PresentationLink` | `presentation_id` | → `presentations.agency_id` |
| 6 | `presentation_listing_price_history` | `App\Models\PresentationListingPriceHistory` | `presentation_id` | → `presentations.agency_id` |
| 7 | `presentation_sections` | `App\Models\PresentationSection` | `presentation_id` | → `presentations.agency_id` |
| 8 | `presentation_snapshots` | `App\Models\PresentationSnapshot` | `presentation_id` | → `presentations.agency_id` |
| 9 | `presentation_sold_comps` | `App\Models\PresentationSoldComp` | `presentation_id` | → `presentations.agency_id` |
| 10 | `presentation_uploads` | `App\Models\PresentationUpload` | `presentation_id` | → `presentations.agency_id` |

### Batch 5 — Presentation tail + Property audit + Targets (10)

| # | Table | Model | Parent FK | Backfill |
|---|-------|-------|-----------|----------|
| 1 | `presentation_url_snapshots` | `App\Models\PresentationUrlSnapshot` | `presentation_id` | → `presentations.agency_id` |
| 2 | `presentation_versions` | `App\Models\PresentationVersion` | `presentation_id` | → `presentations.agency_id` |
| 3 | `worksheets` | `App\Models\Worksheet` | `user_id` | → `users.agency_id` |
| 4 | `targets` | `App\Models\Target` | `branch_id`/`user_id` | → `branches.agency_id` then `users.agency_id` |
| 5 | `monthly_target_goals` | `App\Models\MonthlyTargetGoal` | `branch_id`/`user_id` | → `branches.agency_id` |
| 6 | `listing_targets` | `App\Models\ListingTarget` | `user_id` | → `users.agency_id` |
| 7 | `tool_history_entries` | `App\Models\ToolHistoryEntry` | `branch_id`/`user_id` | → `branches.agency_id` |
| 8 | `daily_activities` | `App\Models\DailyActivity` | `branch_id`/`user_id` | → `branches.agency_id` |
| 9 | `daily_activity_entries` | (no model?) | `branch_id`/`user_id` | → `branches.agency_id` |
| 10 | `agent_scorecards` | `App\Models\CommandCenter\AgentScorecard` | `user_id` | → `users.agency_id` |

### Batch 6 — Listings / Imports / Snapshots (9)

| # | Table | Model | Parent FK | Backfill |
|---|-------|-------|-----------|----------|
| 1 | `listing_stocks` | `App\Models\ListingStock` | `branch_id`/`user_id` | → `branches.agency_id` |
| 2 | `listing_import_runs` | `App\Models\ListingImportRun` | `branch_id`/`imported_by_user_id` | → `branches.agency_id` |
| 3 | `listing_import_rows` | `App\Models\ListingImportRow` | `run_id` | → `listing_import_runs.agency_id` |
| 4 | `listing_snapshots` | `App\Models\ListingSnapshot` | `branch_id`/`user_id` | → `branches.agency_id` |
| 5 | `market_analytics_runs` | `App\Models\MarketAnalyticsRun` | `created_by` | → `users.agency_id` |
| 6 | `sale_probability_runs` | `App\Models\SaleProbabilityRun` | `created_by` | → `users.agency_id` |
| 7 | `revenue_share_ledger` | `App\Models\RevenueShareLedger` | `commission_ledger_id` | → `commission_ledger.agency_id` |
| 8 | `agent_mentors` | `App\Models\AgentMentor` | `mentee_user_id` | → `users.agency_id` |
| 9 | `agent_sponsorships` | `App\Models\AgentSponsorship` | `agent_user_id` | → `users.agency_id` |
| 10 | `agent_social_accounts` | `App\Models\AgentSocialAccount` | `user_id` | → `users.agency_id` |

### Batch 7 — Commercial evaluations + finance audit (10)

| # | Table | Model | Parent FK | Backfill |
|---|-------|-------|-----------|----------|
| 1 | `commercial_evaluations` | `App\Models\CommercialEvaluation` | `branch_id`/`created_by_user_id` | → `branches.agency_id` |
| 2 | `commercial_evaluation_assets` | `…Asset` | `commercial_evaluation_id` | → `commercial_evaluations.agency_id` |
| 3 | `commercial_evaluation_comparables` | `…Comparable` | … | → `commercial_evaluations.agency_id` |
| 4 | `commercial_evaluation_crops` | `…Crop` | … | → `commercial_evaluations.agency_id` |
| 5 | `commercial_evaluation_financials` | `…Financial` | … | → `commercial_evaluations.agency_id` |
| 6 | `commercial_evaluation_livestock` | `…Livestock` | … | → `commercial_evaluations.agency_id` |
| 7 | `commercial_evaluation_units` | `…Unit` | … | → `commercial_evaluations.agency_id` |
| 8 | `finance_audit_runs` | `App\Models\FinanceAuditRun` | `created_by` | → `users.agency_id` |
| 9 | `finance_audit_items` | `App\Models\FinanceAuditItem` | `audit_run_id` | → `finance_audit_runs.agency_id` |
| 10 | `finance_computed_values` | `App\Models\FinanceComputedValue` | `audit_run_id` | → `finance_audit_runs.agency_id` |

### Batch 8 — Calendar + Knowledge + Notes (10)

| # | Table | Model | Parent FK | Backfill |
|---|-------|-------|-----------|----------|
| 1 | `calendar_event_audit_log` | `App\Models\CommandCenter\CalendarEventAuditEntry` | `calendar_event_id` | → `calendar_events.agency_id` |
| 2 | `calendar_event_invitations` | `App\Models\CommandCenter\CalendarEventInvitation` | `event_id` | → `calendar_events.agency_id` |
| 3 | `calendar_event_links` | `App\Models\CommandCenter\CalendarEventLink` | `calendar_event_id` | → `calendar_events.agency_id` |
| 4 | `calendar_reminders_log` | `App\Models\CommandCenter\CalendarReminderLog` | `calendar_event_id` | → `calendar_events.agency_id` |
| 5 | `knowledge_documents` | `App\Models\KnowledgeDocument` | `uploaded_by` | → `users.agency_id` |
| 6 | `knowledge_chunks` | `App\Models\KnowledgeChunk` | `document_id` | → `knowledge_documents.agency_id` |
| 7 | `knowledge_categories` | `App\Models\KnowledgeCategory` | (no FK) | **SKIP — orphan; needs human call** |
| 8 | `branch_settings` | `App\Models\BranchSetting` | `branch_id` | → `branches.agency_id` |
| 9 | `branch_activity_columns` | (no model?) | `branch_id` | → `branches.agency_id` |
| 10 | `fault_reports` | `App\Models\FaultReport` | `user_id` | → `users.agency_id` |

### Batch 9 — Documents / FICA / RMCP (10)

| # | Table | Model | Parent FK | Backfill |
|---|-------|-------|-----------|----------|
| 1 | `document_filing_register` | `App\Models\DocumentFiling` | `branch_id`/`agent_id` | → `branches.agency_id` |
| 2 | `document_library_items` | `App\Models\DocumentLibraryItem` | `uploaded_by_user_id` | → `users.agency_id` |
| 3 | `document_custom_fields` | `App\Models\Docuperfect\DocumentCustomField` | `template_id` | → `docuperfect_templates.agency_id` (Batch 10) — **defer to Batch 10** |
| 4 | `fica_documents` | `App\Models\FicaDocument` | `fica_submission_id` | → `fica_submissions.agency_id` |
| 5 | `fica_resend_logs` | `App\Models\FicaResendLog` | `fica_submission_id` | → `fica_submissions.agency_id` |
| 6 | `rmcp_sections` | `App\Models\Compliance\RmcpSection` | `rmcp_version_id` | → `rmcp_versions.agency_id` |
| 7 | `rmcp_section_acknowledgements` | `App\Models\Compliance\RmcpSectionAcknowledgement` | `rmcp_acknowledgement_id` | → `rmcp_acknowledgements.agency_id` |
| 8 | `employee_screening_checks` | `App\Models\Compliance\EmployeeScreeningCheck` | `employee_screening_id` | → `employee_screenings.agency_id` |
| 9 | `whistleblow_audit_log` | (no model in scan?) | `complaint_id` | → `whistleblow_complaints.agency_id` |
| 10 | `whistleblow_email_log` | `App\Models\Compliance\WhistleblowEmailLog` | `complaint_id` | → `whistleblow_complaints.agency_id` |

### Batch 10 — Docuperfect (templates/packs/clauses) + Rentals + TV (10)

> **Caution:** Docuperfect templates can be globally-shared (system templates) — confirm with human whether all docuperfect_templates/packs/clauses are agency-owned.

| # | Table | Model | Parent FK | Backfill |
|---|-------|-------|-----------|----------|
| 1 | `docuperfect_templates` | `App\Models\Docuperfect\Template` | `owner_id` | → `users.agency_id` (orphans → first agency) |
| 2 | `docuperfect_packs` | `App\Models\Docuperfect\Pack` | `owner_id` | → `users.agency_id` |
| 3 | `docuperfect_clauses` | `App\Models\Docuperfect\Clause` | `owner_id` | → `users.agency_id` |
| 4 | `docuperfect_documents` | `App\Models\Docuperfect\Document` | `owner_id`/`branch_id` | → `users.agency_id` |
| 5 | `docuperfect_pack_slots` | `App\Models\Docuperfect\PackSlot` | `pack_id` | → `docuperfect_packs.agency_id` |
| 6 | `docuperfect_pack_attachments` | `App\Models\Docuperfect\PackAttachment` | `pack_instance_id` | → docuperfect_documents.agency_id (semantic) — verify |
| 7 | `docuperfect_pack_instance_values` | `App\Models\Docuperfect\PackInstanceValue` | `pack_instance_id` | → docuperfect_documents.agency_id — verify |
| 8 | `docuperfect_template_signature_zones` | `App\Models\Docuperfect\TemplateSignatureZone` | `template_id` | → `docuperfect_templates.agency_id` |
| 9 | `docuperfect_field_corrections` | `App\Models\Docuperfect\FieldCorrection` | `user_id` | → `users.agency_id` |
| 10 | `docuperfect_import_drafts` | `App\Models\Docuperfect\ImportDraft` | `user_id` | → `users.agency_id` |

### Deferred / not in this wave

- `rentals`, `rental_properties`, `rental_amount_versions`, `rental_reminder_settings`, `rental_agents` — rental module is undergoing changes (per ROADMAP?); defer until rental spec confirms tenancy model.
- `tv_access_codes`, `tv_messages` — tenant via `branch_id`; small, low risk, but defer to keep batches at 10.
- `deposit_interest_calculations`, `deposit_trust_interest`, `company_expenses` — finance module needs human call.
- `lease_records` — has `document_id` (e-sign linked); defer.
- `web_pack_items` — has `web_pack_id` (web_packs already has agency_id); could be added; defer.

---

## 4. Ambiguities flagged to human (DECISIONS NEEDED before execution)

1. **AI per-user tables** (`ai_conversations`, `ai_messages`, `ai_daily_briefings`, `ai_feedback`): keep user-scoped only, or add agency_id so owner-switch scopes them?
2. **Knowledge_categories**: agency-owned or global library?
3. **Docuperfect Templates/Packs/Clauses with `owner_id`**: are there any "system templates" (owner_id = null or platform user)? If so, leaving agency_id null lets them remain shared via the trait's "NULL = shared" behaviour — that's likely desired. But the `User::effectiveAgencyId()` auto-fill on `creating` will stamp them with the current user's agency, which would prevent the platform from ever creating a truly shared template again. Need decision.
4. **Lookup-ish tables** (`contact_sources`, `contact_tags`, `property_setting_items`): agency-customisable or global?
5. **P24 portal tables**: cross-agency intelligence vs per-agency syndication?
6. **Client portal tables** (`client_users`, `client_otps`, `client_signin_attempts`): client users belong to one or many agencies; agency_id conflict with `current_agency_id`.
7. **Rentals**: defer to rental spec confirmation.

---

## 5. Execution plan (per batch)

1. Generate ~10 migrations with sequential timestamps.
2. Add `BelongsToAgency` trait + import to each model file (skipped where no model exists; just column added).
3. `composer dump-autoload`.
4. `php artisan migrate --no-interaction` — applies just this batch.
5. SQL probe — for each new column: `SELECT COUNT(*) WHERE agency_id IS NULL` must be 0.
6. `php artisan migrate:fresh --seed --no-interaction` — clean rebuild must succeed.
7. `scripts/dev-check.ps1` — fast mode must pass.
8. If any step fails: STOP, report.

---

## 6. Why the plan is paused for human review

The user directive emphasises **maximum safety over speed** and explicitly says to STOP for ambiguous situations rather than guess. The seven ambiguities in §4 each have a non-trivial wrong answer:

- Wrong answer on docuperfect templates = platform-shared content silently becomes per-agency, breaking other tenants.
- Wrong answer on AI tables = privacy regression (a user's AI history visible to other agency members if they share agency).
- Wrong answer on client portal = client portal lockouts.
- Wrong answer on lookup-ish = agencies see/can't see customisations of others.

These need a single yes/no from Andre or Johan each — none of them require code archaeology, they're product decisions. Once answered, the plan above can be executed mechanically in a follow-up Wave 3b-exec run.

**Recommended next step:** Andre/Johan reviews this plan, marks each §4 ambiguity, and confirms (or amends) the 10 batches. Then a follow-up agent executes batches 1–10 sequentially with the verification gate after each.
