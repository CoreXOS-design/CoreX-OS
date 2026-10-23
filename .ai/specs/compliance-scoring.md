# Spec: Compliance Score (score, warn, inform — never block)

**Status:** SPEC ONLY — drafted 2026-10-23 for Johan's review. No code has been written. Not buildable until Johan approves (Spec-First rule).
**Branch:** `spec-compliance-scoring-2026-10-23` (cut from `origin/QA1` at `1f03acac7`).
**Input:** `.ai/audits/2026-10-22-legal-compliance-map.md` on `origin/audit-legal-compliance-2026-10-22` (93 rows: 16 Covered / 54 Partial / 19 Missing / 4 Not CoreX's job). Acts library source: `/mnt/HC_Volume_103099143/corex-knowledge/acts/INDEX.md` (70 files, 78 MB, downloaded 22 Oct, nothing loaded into any database yet).
**Related specs:** `compliance.md`, `ESIGN-CANON.md` §7, `ppra-inspection-pack.md`, `agency-onboarding-setup.md` §6.1, `corex-domain-events-spec.md`.
**Not legal advice.** Section numbers come from three places and are marked accordingly: (a) the audit and Johan's brief, (b) checked in this spec against the Act PDFs in the acts folder (PPA 22/2019, Rental Housing Amendment Act 35/2014, ECTA 25/2002), and (c) marked **†** = from general knowledge, to be verified against the Act text in the legal library before that rule goes live (a rule cannot be switched on while its section reference is unverified — §9.5).

---

## 0. What this is, in plain words

Every agency gets a **Compliance Score** (0–100%) and a list of the things pulling it down. Each item says what is wrong, which law it comes from (Act and section), and has a button that goes straight to the place to fix it. When an admin changes a setting in a way that carries legal risk (for example switching on e-signing for an offer to purchase), CoreX shows a warning with the Act and section, records who accepted it, lowers the score — and then **lets them do it**. The agency decides; CoreX informs and keeps the record.

The Acts themselves are stored **once**, by CoreX, in a read-only Legal Library every agency can read. Ellie reads from the same library. Rules and warnings link to the exact section.

## 1. Design law — Johan's ruling (relayed by the conductor, 23 Oct 2026; paraphrase, not a verbatim quote)

CoreX is a compliant system, but agencies set it up their own way. So **CoreX never blocks on compliance. It scores, warns and informs.** Johan's examples:
- (a) an admin turns on e-sign for a sales document that may not legally be e-signed (OTP / sale agreement — ECTA s4(4) with Schedule 2, and Alienation of Land Act s2(1)): CoreX warns at the moment of switching it on, shows the Act and section, **records who accepted the warning**, lowers the score — but allows it;
- (b) the same for FFC (for example commission captured while an FFC has lapsed): warn and score, don't block;
- the same pattern for every item.

Consequences built into this spec:
1. The only thing the server ever refuses is a save **without the recorded acknowledgement** (so the record cannot be skipped by a direct post). An acknowledged save always succeeds. That is an audit requirement, not a compliance block, and it is exactly how the existing e-sign warning already works (`422 esign_ack_required`).
2. An accepted warning **does not remove the item from the list and does not restore the points**. The risk is still there; the item shows "Accepted by <name> on <date>" and stays failing until the setting is put right.
3. CoreX never changes an agency's settings by itself.
4. Agencies cannot edit weights. Thresholds that are agency settings are bounded by CoreX floors so a lenient threshold cannot hide a failing rule.

**Existing hard stops that pre-date this ruling are NOT changed by this spec** (see §3 and open question Q1).

---

## 2. Current state (investigation, 23 Oct 2026, worktree at `1f03acac7`)

Refs marked † were taken from the 22 Oct audit at the same commit and not re-opened here; all others were read for this spec.

### 2.1 Where agency settings that carry compliance risk live

| Area | Setting | Stored | Changed in | Notes |
|---|---|---|---|---|
| E-sign on sale documents | `templates.is_esign`, `allowed_delivery_modes` | `app/Models/Docuperfect/Template.php:43,57` | Template setup — `TemplateController` (`saveFields`, `cdsGenerate`; ack handling `:340-375`) | Which types warn is **data**: `document_types.esign_warning_required` (`app/Models/DocumentType.php:14,22`; toggled by CoreX owner role at `DocumentTypeController.php:88-94`). A flagged template with no recorded acknowledgement is stored `is_esign=false` (`Template.php:543-551`). |
| E-sign warning wording | `config/esign-acknowledgement.php` (`version` 1) | config | — | Cites ECTA generally; must be re-worded to ECTA s4(4)/Sch 2 + AoLA s2(1) and bumped to version 2 (§8). |
| FICA gate | `FicaGate::OPEN_STATUSES` (lifts on SUBMITTED) | `app/Services/Compliance/FicaGate.php` (`compliance.md` "The FICA gate") | code constant (Johan ruling 8 Oct) | Not a setting. |
| FICA before authoriser (rentals) | `require_fica_before_authorisation` | `RentalApplicationQualifyingSetting.php:373,399,706` | `RentalApplicationSettingsController::updateRequireFicaBeforeAuthorisation` `:710-725` (`has()`-guard `:713`) | Opt-in hard stop, OFF by default. |
| FICA on the signing page | `signing_requests.fica_required` per recipient | `SigningController.php:157` | e-sign wizard (agent can untick per recipient) | A waiver by the agent; today leaves no compliance trace. |
| FICA time windows | `fica_link_expiry_days`, `fica_current_months`, `fica_validity_months`, `fica_expiring_soon_days` | `agencies` columns via `App\Services\Compliance\FicaWindows` (`compliance.md` "FICA time windows are agency settings") | `FicaOfficerAppointmentsController::saveWindowSettings` (`routes/web.php:5240`) | Already in Setup Wizard. |
| FICA referral | `fica_referral_enabled`, `fica_referral_recipient_user_id` | `Agency.php:187-188` | `saveReferralSettings` (`routes/web.php:5238`) | Not risk-bearing. |
| Officers | FICA CO / MLRO; Information Officer + deputies | `FicaOfficerAppointment`, `InformationOfficerAppointment` | `FicaOfficerAppointmentsController` (`routes/web.php:5230-5235`), `InformationOfficerAppointmentsController` (`:5243-5248`; permission `manage_information_officer`) | Ending an appointment with no successor leaves the agency with no CO / IO. |
| Marketing-readiness required documents | agency's list of required document types | `AgencyComplianceDocTypeService` (`:23,462`†), default `config/corex-compliance.php:31-35` (`mandate`, `fica`, `disclosure`), rental exemption `:49` | Compliance → Agency Documents (`compliance.agency-settings.index`, perm `manage_agency_compliance`, `config/corex-permissions.php:414`) | An agency can untick mandate / disclosure. |
| Compliance bypass (platform) | `DevSetting::bool('compliance_checks_disabled')` | `MarketingReadinessService.php:82,216,420` | Dev Settings (CoreX owner) | Platform-level, not an agency setting; must show as a banner on the score screen if ever on. |
| Go-live grandfathering | `mark_compliant_on_confirm`, `mark_fica_approved` (default **checked**) | `p24_import_runs.mark_compliant_on_confirm`; `fica_submissions.verification_method.source = go_live_migration` | Admin → Importer, Contacts import (`compliance.md` "Go-Live Migration Mode") | Stamps whole books as already compliant. |
| Outreach send window | `agencies` outreach window JSON (default Mon–Fri 08:00–20:00) | `Agency.php:132-140` | `CompanySettingsController.php:172`† | Editable beyond the legal envelope, no floor. |
| Consent-request lapse | `outreach_no_response_days` (1–365) | `AgencyContactSettings` (`app/Models/AgencyContactSettings.php:9`) | `CommandCenter/ContactGovernanceController.php:~70` (`command-center.settings.contact-governance`) | |
| Retention | `contact_retention_years`, `consent_retention_years`, `access_log_retention_years` (`min:5`) | `AgencyContactSettings` | `ContactGovernanceController.php:~72-74` | **Existing hard floor of 5** (see §3 / Q1). |
| Unsubscribe footer / privacy URL | `marketing_unsubscribe_footer`, `popi_url` | `Agency.php:235`, `:864` (`effectivePopiUrl`), `:875-889` | Company Settings (`admin.company-settings`) | The footer is **not appended to any outgoing mail** today (audit POPIA-7e). |
| Lease / CPA | early-cancel business days (default 20, editable down to 1), "early cancellation not allowed", expiry-notice send day (clamped 40–80) | `LeaseSetting.php:51,76-77,81`† | `LeaseSettingsController.php:116,127`† (`corex.settings.leases.edit`) | |
| FFC | no setting. FFC amber window is a code constant: `PractitionerFfcRosterService::AMBER_WINDOW_DAYS = 60` (`:48`). Three FFC expiry sources can disagree (audit PPA-1). | `users.ffc_number / ffc_expiry_date / ffc_certificate_path` (`User.php:153,168,169,354`†) | practitioner profile | Only enforcement anywhere: `AuctionPublishGate.php:61-88`† (auction publish). Commission code has **no FFC reference**. |
| Mandate expiry | `MandateExpiryPolicy` (warn + optional lock) | `app/Services/Properties/MandateExpiryPolicy.php:31-32`† | property settings | |

### 2.2 Existing compliance screens — is there a home for the score?

- **Compliance group** in the sidebar: `resources/views/layouts/corex-sidebar.blade.php:2119-2200` (gate `@permission('access_compliance')` + `@feature('compliance')`; items FICA, RMCP, RMCP Dashboard, Policies, Policy Register, Staff Screening, Agent Compliance, Verification Queue, Document Types, Agency Documents, Whistleblow…). Active-group matcher `routeIs('compliance.*')` at `:166`. **Natural home: first item in this group.** (Johan wrote "Admin > Compliance > Compliance Score"; there is no such nesting today — the Compliance group sits beside Admin. If he wants it in the Admin menu instead it is a one-line move; see §10.6.)
- **PPRA Inspection Pack** is a flat Company/Admin item (`corex-sidebar.blade.php:2835-2845`; feature `ppra-inspection-pack`, `config/corex-features.php:802-811`, `sidebar_section` `company`) — Johan's 28 Sep ruling: "never nested under Compliance". Routes `routes/web.php:1453-1512`, controller `Admin/PpraInspectionPackController`, view `admin/ppra-inspection-pack/index.blade.php` (banner header pattern). Its **checklist service** (`PpraInspectionPackChecklistService.php:44-90`†, items a–m, remediation dates) already answers several rules (PPA-3, PPA-7, PPA-11) — **reuse, don't duplicate**.
- **"Compliance Reporting":** no screen with that name exists. Nearest: the permission `view_compliance_reports` (`config/corex-permissions.php:1211`), the RCR (FIC Risk & Compliance Return) screens `corex.compliance.rcr.*` (sidebar `:1836-1856`; evidence gathering `Rcr/EvidenceGatheringService.php`† already derives many answers from CoreX data — **reuse its queries**), and Agent Compliance `compliance.agents`.
- **RMCP Manager / Dashboard:** `compliance.rmcp.*` (`routes/web.php:2890`), `compliance.rmcp.dashboard.*` (`:2781`, perm `access_compliance_dashboard`, `corex-permissions.php:359`).
- **Reported, not changed:** the RCR sidebar link hard-codes a role list (`corex-sidebar.blade.php:1836`: `admin, super_admin, branch_manager, principal`) — a hardcoded-roles pattern the new screen must not copy.
- **Blade components to reuse** (`resources/views/components/`): `corex-kpi-card` (props `title`, `value`, `trend`, `trendUp` — for overall + area scores, trend = change since previous run), `list-header` (sticky header with search, filters, actions slot, paginator), `sticky-action-bar` (left/center/right slots), `sort-header` (sortable `<th>`), `pill` (status/label pill with state dot), `page-header`, `tile` (Command Centre card shell — **not** used for the screen; only if a Command Centre tile is added later), `row-actions-popup`. Layout `layouts.corex` / `@section('corex-content')`.

### 2.3 Knowledge base / Ellie — can it hold the Acts?

| Question | Finding |
|---|---|
| Tables | `knowledge_categories`, `knowledge_documents`, `knowledge_chunks` (`database/migrations/2026_02_25_000001_create_knowledge_base_tables.php`); Ellie also reads global `ellie_reference_sources` / `ellie_reference_chunks` (`2026_08_10_000003/4`: URL allow-list, **global**, no `agency_id`) and training doc chunks. |
| Global (all-agency, read-only) vs per-agency | `knowledge_documents` was made **per-agency** on 20 Aug 2026 (`2026_08_20_000001_…`, `BelongsToAgency` + `SoftDeletes`, `KnowledgeDocument.php:26`). `AgencyScope` is strict: `agency_id = <agency>`; a NULL `agency_id` is an **orphan, invisible to every agency** (`app/Models/Scopes/AgencyScope.php` — the comment in the `where` block; `BelongsToAgency.php:20-23`). So a "global" KB row would be invisible to agencies and to their Ellie. Making KB global would mean changing the tenant-isolation scope on a security-critical table. **Not acceptable.** |
| Who manages | Any user with `access_knowledge_base` in any agency can upload, toggle, archive (`routes/web.php:1435-1448`). Wrong for CoreX-owned law. |
| Versioning / effective dates | Single-row columns `version` (string), `effective_date`, `expiry_date`. No "supersedes" link, no way to keep a prior version addressable. |
| Section addressing | Chunks carry `section_title`, `page_number` only — a rule cannot link to "s4(4)". |
| PDF storage | `Storage::disk('local')`; `KnowledgeController::destroy` **deletes the stored file** while "archiving" the row (`KnowledgeController.php:113-127`) — unrecoverable, contradicts soft-delete-only for legal text. |
| How Ellie retrieves | Tool `search_knowledge` (`EllieToolkit.php:54,71-73`) → `KnowledgeSearchService::search()` (hybrid cosine on embeddings, keyword fallback; `:24-68`). It **already merges a second, un-agency-filtered source** — `TrainingDocChunk::where('has_embedding', true)` (`:64-67`) — so a third global source is an established pattern. Embeddings: `EmbeddingService` (self-hosted BGE, 384 dims, JSON-encoded floats). Chunking/extraction: `DocumentProcessingService` (PDF/DOCX → sections → chunks; methods `extractText`, `chunkText` are private). |

**Verdict: EXTEND minimally — new global Legal Library tables; do not reuse `knowledge_documents`.** Reasons in §9.1.

### 2.4 Existing audit-log mechanisms

There is **no generic audit table**. Three single-purpose patterns exist, all insert-only:
- `template_esign_acknowledgements` (`2026_10_16_200000_…`): `agency_id, template_id, template_name, document_type_slug, action (enabled|disabled), user_id, user_name, on_behalf_of_user_id, wording_version, wording_snapshot, request_context (route, ip, user_agent), created_at`. Written by `EsignAcknowledgementService::record()` (`:133-155`). **This is exactly "warning accepted by X at Y" for e-sign and is the model for the new generic ledger.**
- `rental_setting_audit` (`RentalSettingAuditEntry`): who changed which setting, old → new, source (settings / wizard); no-op writes leave no row.
- `legal_block_audit_log` (`LegalBlockAuditLog`): `save()` throws when `$this->exists` — enforced immutability; copy this guard.
- Also: `FicaStatusHistory`, `ContactAuditLog`, `PropertyAuditLog`, `CommissionSettingAuditEntry`†, `App\Support\Audit\AuditContext` (`app/Support/Audit/AuditContext.php:28`), trait `StampsOnBehalfOf`.

---

## 3. Scope

**IN:** the score; the rule catalogue; the warn-at-switch-on and action-time warning framework with its ledger; the Legal Library (global, read-only to agencies); the Compliance Score screen; recalculation; notifications; the agency settings the feature itself needs (and their Setup Wizard entry).

**OUT (explicitly):**
- **Blocking anything.** Nothing in this spec adds a stop.
- **Changing agency settings automatically.** CoreX only points at the fix.
- Building any of the 22 missing features in §7.3 (each gets its own spec; a rule is "not yet scored" until its feature ships).
- Re-wording any agency-owned document (leases, mandates, OTPs are the agency's text).
- **Existing hard stops are not touched by this spec** — they predate the ruling and were set by Johan's earlier rulings: the external signer's FICA page (`SigningController.php:157`), the opt-in `require_fica_before_authorisation`, marketing readiness (`MarketingReadinessService`), exact-sanctions-hit lock (`FicaController.php:457-468`†), auction publish FFC gate (`AuctionPublishGate.php:61-88`†), outreach send-window and opt-out blocks, the retention floor of 5 years (`ContactGovernanceController.php:~72-74`), the expiry-notice clamp 40–80 (`LeaseSettingsController.php:127`†). They show on the screen as scored rules; whether any should become warn-only is **Q1**.

## 4. Pillars, events, multi-agency

- **Pillars:** reads all four — Agent (FFC, PI, candidates, officers), Contact (FICA, consent, suppression), Property (mandate, MDF, sectional title), Deal (OTP, certificates, FICA gate, leases). Writes only its own tables (results, ledger) — the pillars are never modified.
- **Domain events** (per Non-negotiable #9; register in `corex-domain-events-spec.md` when built): emits `ComplianceScoreRecalculated`, `ComplianceScoreDropped`, `ComplianceWarningAccepted`; **subscribes** to a new `ComplianceSettingChanged` fired by each registered saver (§8.3). No per-record listeners in v1 — evaluation is batch (nightly / on demand / on setting change), deliberately.
- **Multi-agency (Non-negotiable #9):** every message, default and link is agency-neutral; no HFC wording, ids or branding. Thresholds are per-agency settings with neutral defaults. Rules that don't apply to an agency (no rentals feature → RHA/PIE; no sales → sales-law; feature `compliance` off) are **not applicable** and excluded from the denominator. The test of the second agency: a Cape Town rentals agency with zero sales must be scored only on what it does.
- **Critical evaluator gotcha:** nightly/queued evaluation has **no authenticated user**, and `AgencyScope` does nothing without one (`AgencyScope.php` `applyInner`: `if (!$user) return;`). Every evaluator query therefore **must filter `agency_id` explicitly**; a two-agency test is mandatory for every evaluator group.

---

## 5. Data model (all new; migrations + `database/schema/mysql-schema.sql` snapshot in the same commit; strip `DEFINER`s)

| Table | Scope | Key columns |
|---|---|---|
| `compliance_rules` | **GLOBAL** (no `agency_id`, no `BelongsToAgency`) | `code` (unique, e.g. `PPA-1`), `area` (ppa/fica/popia/cpa/rha_pie/sales), `title`, `act_id`, `section_ref`, `section_verified_at`, `severity` (C/H/M/L), `weight` (int, default from severity), `evaluator` (class), `status` (`live` / `feature_first`), `unlocked_by_feature` (F1…F23), `message_template`, `fix_route`, `fix_params` json, `applies_when` (feature-flag keys), `rules_version`, `deleted_at`. Seeded from `config/compliance-rules.php`; registered in `deploy:sync-reference-data` (CLAUDE.md step h). Editable only by CoreX owner role; every edit bumps `rules_version`. |
| `compliance_score_runs` | agency (`BelongsToAgency`) | `agency_id`, `calculated_at`, `trigger` (nightly/manual/setting_change/first_visit), `triggered_by_user_id`, `overall_pct` (1 dp), `area_pcts` json, `rules_scored`, `rules_not_applicable`, `rules_not_yet_scored`, `rules_version`. Append-only. |
| `compliance_rule_results` | agency | `run_id`, `agency_id`, `rule_code`, `state` (`pass`/`partial`/`fail`/`not_applicable`/`not_yet_scored`), `fraction` (0–1), `points_lost` (2 dp), `detail` json (counts, ≤20 affected record ids with labels + links), `state_changed_at`, `accepted_acceptance_id` nullable. Latest run is "current". |
| `compliance_warning_acceptances` | agency | modelled on `template_esign_acknowledgements`: `agency_id`, `kind` (`switch`/`action`/`waiver`/`changed`/`reverted`), `warning_key`, `setting_key`, `old_value`, `new_value`, `rule_codes` json, `subject_type`/`subject_id` (e.g. a template or a commission entry), `user_id`, `user_name`, `on_behalf_of_user_id` (`StampsOnBehalfOf`), `legal_act_version_id`, `section_ref`, `wording_version`, `wording_snapshot`, `request_context` json, `created_at`, `reverted_at`. **Insert-only** (throws on update; `reverted_at` is set by a separate `reverted` row, not an update). |
| `agency_compliance_settings` | agency, one row | the thresholds and toggles in §13. |
| `legal_acts` | **GLOBAL** | `short_code` (PPA, FICA, POPIA, PAIA, CPA, RHA, PIE, AOLA, MPA, ECTA, STSMA…), `title`, `act_number`, `area`, `kind` (act / regulations / directive / guidance), `deleted_at`. |
| `legal_act_versions` | **GLOBAL** | `act_id`, `version_label`, `effective_from`, `effective_to` nullable, `is_current`, `is_consolidated`, `supersedes_version_id`, `source_url`, `retrieved_on`, `consolidation_date`, `file_path` (private disk), `file_sha256`, `status` (`current`/`superseded`/`outdated`), `notes`, `deleted_at`. Older versions are kept forever. |
| `legal_act_sections` | **GLOBAL** | `version_id`, `section_ref` (`4(4)`), `heading`, `text`, `sort`, `verified_by_user_id`, `verified_at`. Only sections cited by a rule are curated (§9.4). |
| `legal_act_chunks` | **GLOBAL** | mirrors `ellie_reference_chunks`: `version_id`, `section_id` nullable, `chunk_index`, `content`, `embedding` (JSON), `has_embedding`, `deleted_at`. |

Soft delete only everywhere (Non-negotiable #1). Nothing here hard-deletes; old results older than 24 months are soft-archived by a scheduled prune, never purged.

---

## 6. Scoring model

1. **Rule outcome** per agency: `pass` (f=1), `partial` (0<f<1, e.g. 9 of 12 practitioners valid → 0.75), `fail` (f=0), `not_applicable` (feature not used by this agency — excluded), `not_yet_scored` (rule is `feature_first` — excluded, **never penalises the agency for something CoreX cannot yet do or the agency cannot fix**).
2. **Weights — CoreX-level, not editable by agencies:** Critical **10**, High **6**, Medium **3**, Low **1** (defaults; CoreX owner can adjust per rule in `compliance_rules.weight`, audited, bumps `rules_version`). Critical = criminal / fund-loss exposure (FFC, sanctions, STR/CTR/TPR). Severity is taken from the audit's risk column (HIGH→H, MED→M, LOW→L; Covered rows by obligation importance), with the five Critical rules marked below.
3. **Area score** = 100 × Σ(weight × f) ÷ Σ(weight) over that area's *scored* rules. Areas: **PPA**, **FICA**, **POPIA/PAIA**, **CPA**, **RHA/PIE**, **Sales-law**. An area with no scored rule shows "—" and is left out of the overall.
4. **Overall** = the same formula pooled over all scored rules (not an average of areas, so a thin area cannot dominate).
5. **Points lost** per rule = 100 × weight × (1 − f) ÷ Σ(weight of all scored rules). The screen sorts by it.
6. **Accepted warning:** state stays `fail`/`partial`; the result row links `accepted_acceptance_id` and the screen shows who accepted and when. It does not change `fraction`.
7. **Trend:** change in overall and area points versus the previous run **with the same `rules_version`**; when the rule set changed, the screen shows "rule set updated" instead of a trend and no drop notification fires.
8. **Rounding:** stored 1 dp, displayed whole numbers.
9. **Platform banners (not scored):** "Sanctions list out of date" (nightly FIC list stale — CoreX job, the agency can't fix); `compliance_checks_disabled` dev bypass on.

---

## 7. Rule catalogue

91 rules = **89 from the audit** (93 rows − 4 "Not CoreX's job": POPIA-7f, POPIA-7g, POPIA-7h, CPA-5) **+ 2 added** (ESIGN-1: Johan's example (a), which the audit excluded; GOLIVE-1: the go-live grandfathering toggles). **49 are live now. 42 need a feature built first** (§7.3).

Columns: **Sev** C/H/M/L (§6). **Evaluated by** gives the exact data/setting; "**Live**" scores today; "**Live, +later**" scores the part that exists and adds the rest when the named feature ships; "**FF·Fn**" = feature-first, unlocked by feature Fn (not scored until then). Messages use `{n}` / `{total}`; every message ends with the Act and section chip and the fix button. **Fix** = route name (verified to exist) or `R:` = screen to be resolved in the build (the P3 test asserts every fix link resolves; an unresolved link fails the build). `lookback` = `agency_compliance_settings.lookback_months` (default 12).

### 7.1 Property Practitioners Act — area PPA (11)

| Code | Rule — message when failing | Act & section | Evaluated by | Sev | Fix |
|---|---|---|---|---|---|
| PPA-1 | **Every practitioner holds a valid FFC; no commission on a lapsed one.** "{n} of {total} practitioners have no valid FFC. {m} commission entries were made after an FFC lapsed." | PPA s48, s52, s56 | **Live, +later (agency-level FFC expiry).** Active practitioners of the agency: `users.ffc_number` present and `ffc_expiry_date ≥ today` (the roster's single definition — `PractitionerFfcRosterService` — becomes the one source; the three-source disagreement in the audit is reported, not fixed here). f = valid ÷ total. Commission entries (CommissionCalculationService records) dated after that agent's FFC lapsed count as failures: f × (1 − min(1, m ÷ entries in lookback)). Amber = expiring within `ffc_warning_days`. | C | `compliance.agents` |
| PPA-2 | **FFC statement and FFC-validity clause present.** "Agency FFC/PPRA number missing" / "{n} mandate templates lack the FFC-validity clause." | PPA s53 + Regs 2022† | **Live, +later (F12 adverts).** (a) `agencies.ffc_no`/`ppra_number` set; (b) every active mandate-type docuperfect template contains the FFC clause block. f = checks passed ÷ 2. | H | `admin.company-settings`; `docuperfect.templates.index` |
| PPA-3 | **Trust account evidence current.** "Bank confirmation letter / trial balance missing or expired." | PPA s54, s55 | **Live, +later (trust-account + auditor fields).** Vault slots bank-confirmation + trial-balance present and not expired (`PpraInspectionPackChecklistService` items). | M | `admin.ppra-inspection-pack.index` |
| PPA-4 | **Disclosure form signed before the mandate and before marketing.** "{n} of {total} sale listings have no signed disclosure form, or it was signed after the mandate." | PPA s67 (Mandatory Disclosure Form) | **Live, +later (buyer-receipt step).** Active sale listings marketed: signed disclosure document exists (`MarketingReadinessService` presence) **and** its signed date ≤ mandate signed date where both dates exist; listings without dates are listed as "cannot tell", not penalised. | H | property Compliance Status panel — `R:properties.show#compliance` |
| PPA-5 | **Records kept five years; adverts evidenced.** | PPA s55 + Regs† | **FF·F4, F12** (social images deleted at 30 days, portal payload at 45; contact purge reaches FICA) | M | — |
| PPA-6 | **Free choice of conveyancer recorded; no incentive.** | PPA s58 | **FF·F13** | M | — |
| PPA-7 | **BEE certificate/affidavit and transformation statement current.** "BEE document missing or expired." | PPA transformation provisions† | **Live.** Vault BEE certificate **or** affidavit present and unexpired; `AgencyTransformationNote` saved within lookback. | L | `admin.ppra-inspection-pack.index` |
| PPA-8 | **Professional indemnity cover current.** "{n} of {total} practitioners have no current indemnity cover." | PPA s57 | **Live, +later (agency-level policy).** `users` PI path + expiry (`User.php:155,172,355`†) ≥ today. | M | `compliance.agents` |
| PPA-9 | **Candidate practitioners supervised and tracked.** | PPA s64 | **FF·F14** | M | — |
| PPA-10 | **Mandates in force before marketing.** "{n} of {total} marketed listings have no signed, unexpired mandate." | PPA s61, s63 | **Live.** Active marketed listings: signed mandate document and `mandate_expiry ≥ today` (`MandateExpiryPolicy`). | M | `R:properties.show#compliance` |
| PPA-11 | **Inspection pack ready.** "{n} inspection checklist items open; {m} past their promised fix date." | PPA — PPRA inspection powers† | **Live.** Pack checklist items a–m satisfied ÷ applicable; remediation-log items past their date reduce f. | M | `admin.ppra-inspection-pack.index` |

### 7.2 FICA — area FICA (19: 18 audit + GOLIVE-1)

| Code | Rule — message | Act & section | Evaluated by | Sev | Fix |
|---|---|---|---|---|---|
| FICA-1 | **FIC registration number on file.** | FICA s43B | **Live, +later (date/proof).** `agencies.fic_no` non-empty. | M | `admin.company-settings` |
| FICA-2 | **RMCP approved, current and reviewed on time.** "No approved RMCP" / "RMCP review date passed." | FICA s42 | **Live.** Active `RmcpVersion` with `approved_at`; review date ≥ today. | H | `compliance.rmcp.index` |
| FICA-3 | **Staff have signed the current RMCP.** "{n} of {total} staff have not signed." | FICA s42 | **Live.** Active users with an acknowledgement of the active version; grace `rmcp_signoff_grace_days`. | M | `compliance.rmcp.dashboard.index` |
| FICA-4 | **Compliance Officer appointed.** "No active Compliance Officer." | FICA s42A† | **Live.** Exactly one active primary `FicaOfficerAppointment`. | H | `corex.settings.compliance-officers` |
| FICA-5 | **Client due diligence complete.** "{n} of {total} approved FICA files lack an ID or proof-of-address document." | FICA s21 | **Live.** Approved submissions in lookback with both document types linked. | M | `compliance.fica.index` |
| FICA-6 | **Beneficial owners recorded for companies and trusts.** | FICA s21B | **Live.** Approved company/trust/partnership submissions with ≥1 beneficial owner / trustee / partner with an ID number. | M | `compliance.fica.index` |
| FICA-7 | **PEP question answered on every approval.** | FICA s21F–21H† | **Live (self-declaration only; real list screening needs a PEP source).** Approved submissions where the reviewer PEP answer is set. | H | `compliance.fica.index` |
| FICA-8 | **Sanctions screening done and hits resolved.** "{n} files unscreened; {m} unresolved sanctions hits." | FICA ss26A–26C, s28A† | **Live, +later (beneficial owners, re-screen on list change).** Latest `FicaTfsScreening` per submitted/approved file not `error`; any unresolved exact hit sets f=0. Stale FIC list = platform banner, not a penalty. | C | `compliance.fica.index` |
| FICA-9 | **Staff screened on time.** "{n} staff screenings overdue." | FICA / RMCP† | **Live.** Active staff with `EmployeeScreening.next_due ≥ today`. | M | `compliance.screening.dashboard.index` |
| FICA-10 | **FICA refreshed before it expires.** "{n} clients with live deals have an expired FICA." | FICA s21 (ongoing due diligence) | **Live.** Contacts on active deals/leases whose latest approved submission is not past `fica_expires_at`. | M | `compliance.fica.index` |
| FICA-11 | **FICA records kept five years.** | FICA ss22–24 | **FF·F4** | M | — |
| FICA-12 | **Suspicious transaction reports registered and filed.** | FICA s29 | **FF·F1** | C | — |
| FICA-13 | **Cash threshold reports registered and filed.** | FICA s28 | **FF·F1** | C | — |
| FICA-14 | **Terrorist-property reports and freezing.** | FICA s28A | **FF·F1** | C | — |
| FICA-15 | **FICA/AML training recorded.** | FICA s42 + Directive† | **FF·F3** | M | — |
| FICA-16 | **Risk and Compliance Return filed on time.** "2026 return not submitted." | FIC Directive 11 of 2026 | **Live.** `RcrSubmission` for the current window submitted / marked transposed on or before the deadline. | H | `corex.compliance.rcr.index` |
| FICA-17 | **RMCP submitted to the FIC.** | FIC Directive 12 of 2026 | **FF·F2** (AT-505) | M | — |
| FICA-18 | **Parties on live deals have passed the FICA gate.** "{n} parties on live deals have no submitted FICA." | FICA s21 | **Live.** Parties of active sale deals and active/pending leases: `FicaGate` open ÷ total. Warn-not-block remains the design (8 Oct ruling). | M | `R:deals-dr2.index`; `compliance.fica.index` |
| GOLIVE-1 | **Records marked compliant by the go-live import are re-verified.** "{n} records are still marked compliant only because of the import." | FICA s21; PPA s67 | **Live (added).** FICA submissions with `verification_method.source = go_live_migration` and properties with `compliance_snapshot_data.source = p24_go_live_migration` still in use and not since superseded by a normal approved record; f = 1 − n ÷ total active. | L | `admin.importer.*` |

### 7.3 POPIA / PAIA — area POPIA (23)

| Code | Rule — message | Act & section | Evaluated by | Sev | Fix |
|---|---|---|---|---|---|
| POPIA-1a | **Information Officer appointed.** | POPIA s55 | **Live.** One active primary `InformationOfficerAppointment`. | M | `corex.settings` (Information Officer, `settings.blade.php:1650`†) |
| POPIA-1b | **IO registered with the Information Regulator.** | POPIA s55 + Regs | **FF·F5** | M | — |
| POPIA-2 | **PAIA manual prepared and available.** | PAIA s51 | **FF·F5** | M | — |
| POPIA-3 | **Privacy policy published and names the IO.** | POPIA s18, s11 | **Live.** `Agency::effectivePopiUrl()` resolves to a published agency policy and an IO is appointed. | M | `admin.company-settings` |
| POPIA-4 | **Consent recorded for contacts we market to.** "{n} of {total} contacts messaged have no consent record." | POPIA s11, s69 | **Live, +later (wording version).** Contacts sent Outreach in lookback with a `ContactConsentRecord` `marketing_communications` = given. | M | `R:contacts` (filtered) |
| POPIA-5 | **Marketing only to people we may market to.** "{n} messages went to people with no consent or existing relationship." | POPIA s69 | **Live.** Outreach sends in lookback where `canMarketTo` was false, or no consent and no deal/lease relationship. | H | `R:outreach-queue` |
| POPIA-6 | **One consent request, then stop.** | POPIA s69(2)–(3) | **Live.** `outreach_no_response_days` set; zero sends to contacts in "pending" state in lookback. | M | `command-center.settings.contact-governance` |
| POPIA-7a | **Every outreach template carries an opt-out.** | POPIA s69 | **Live.** All active SellerOutreach templates pass `SellerOutreachTemplateValidator` (`{opt_out_link}` + STOP sentence). | M | `R:seller-outreach.templates` |
| POPIA-7b | **Nothing sent to opted-out people.** | POPIA s69 | **Live.** Outreach sends in lookback to suppressed/opted-out contacts = 0. | M | `admin.marketing-suppressions.index` |
| POPIA-7c | **Presentation / CMA e-mails respect opt-out and carry an unsubscribe.** | POPIA s69 | **FF·F6** (`PresentationDeliveryService` skips the check) | H | — |
| POPIA-7d | **Agent one-to-one sends warn on opted-out contacts.** | POPIA s69 | **FF·F6** | M | — |
| POPIA-7e | **Unsubscribe footer actually appended to outgoing mail.** | POPIA s69; CPA s11 | **FF·F6** (footer is configured but appended nowhere) | M | — |
| POPIA-7i | **Inbound "STOP" honoured automatically.** | POPIA s69 | **FF·F6** | M | — |
| POPIA-8 | **Suppression list honoured.** | POPIA s69 | **Live.** No message sent after the suppression date to a suppressed identifier (Outreach, queue). | M | `admin.marketing-suppressions.index` |
| POPIA-9 | **Data-subject requests handled.** | POPIA ss23–25 | **FF·F7** | H | — |
| POPIA-10 | **Operator agreements with service providers tracked.** | POPIA s21 | **FF·F8** | M | — |
| POPIA-11 | **Security-compromise process and register.** | POPIA s22 | **FF·F9** | H | — |
| POPIA-12 | **Retention schedule set and running.** | POPIA s14 | **Live, +later (legal hold, F4).** Contact/consent/access-log retention years set (≥5) and the retention purge ran within 48 h (scheduler heartbeat; a box without the scheduler = platform banner). | M | `command-center.settings.contact-governance` |
| POPIA-13 | **Cross-border transfers recorded and the privacy page accurate.** | POPIA s72 | **FF·F8** (the privacy page says only Ellie voice text goes to Anthropic; images and documents also go) | H | — |
| POPIA-14 | **Special personal information handled with authorisation.** | POPIA ss26–27, ss34–35 | **FF·F10** | M | — |
| POPIA-15 | **ID numbers and documents encrypted at rest.** | POPIA s19 | **FF·F11** (platform) | M | — |
| POPIA-16 | **Access to personal information logged.** | POPIA s19 | **Live.** `contact_access_log` has rows in lookback and `access_log_retention_years` set. | L | `command-center.settings.contact-governance` |
| POPIA-17 | **WhatsApp / mailbox capture has the agent's and contact's consent.** | POPIA s11 | **Live.** Capture-enabled agents/mailboxes with an `AgentCaptureConsent` given ÷ total. | M | `R:compliance.comm-mailboxes` |

### 7.4 Consumer Protection Act — area CPA (7)

| Code | Rule — message | Act & section | Evaluated by | Sev | Fix |
|---|---|---|---|---|---|
| CPA-1 | **24-month term check on leases and mandates.** | CPA s14 | **FF·F15** | M | — |
| CPA-2 | **Early-cancellation notice matches the Act.** "Early-cancellation notice is {x} business days (Act: 20) / early cancellation disabled." | CPA s14 | **Live.** `LeaseSetting` early-cancel business days ≥ 20 and cancellation allowed. | L | `corex.settings.leases.edit` |
| CPA-3 | **Lease-expiry notices sent in the 80–40 business-day window.** "{n} leases passed 40 days without notice." | CPA s14 | **Live.** Active fixed-term leases inside/after the window with no notice sent, `LeaseExpiryNoticeService` state `overdue`; template configured. | M | `corex.settings.leases.edit`; `R:rentals.command-centre` |
| CPA-4 | **Cooling-off for direct-marketing deals tracked.** | CPA s16 | **FF·F16** | M | — |
| CPA-6 | **Marketing opt-out honoured everywhere.** | CPA s11 | **Live.** Shares POPIA-8's evaluator result (counted under CPA for the CPA area; weight reflects that it is one control). | M | `admin.marketing-suppressions.index` |
| CPA-7 | **Marketing sent only in permitted hours.** "Send window runs outside the legal hours." | CPA Regs reg 4† (weekdays 08:00–20:00, Saturday 09:00–13:00†) | **Live.** Agency send window inside the legal envelope; Outreach sends in lookback outside it = 0. | M | `admin.company-settings` |
| POPIA-CPA-1 | **NCC national opt-out registry cleansed before marketing.** | CPA s11; CPA Amendment Regs 2026 (GN R.7380) | **FF·F23** | H | — |

### 7.5 Rental Housing Act and PIE — area RHA/PIE (11)

| Code | Rule — message | Act & section | Evaluated by | Sev | Fix |
|---|---|---|---|---|---|
| RHA-1 | **Written lease on file.** "{n} active leases have no signed agreement." | RHA s5† | **Live.** Active leases with a signed agreement matched by `LeaseAgreementCheck`. | L | `R:leases` |
| RHA-2 | **Deposits held in interest-bearing accounts.** | RHA s4B(1)(b) | **Live, +later (deposit received/held-in).** Leases with `deposit_amount > 0` and a `DepositTrustInterest` entry within lookback. | M | `deposit-interest-calculator.index` |
| RHA-3 | **Interest statement on request.** | RHA s4A(2), s4B(2) | **FF·F17** | L | — |
| RHA-4 | **Joint move-in inspection signed.** "{n} new leases have no signed move-in inspection." | RHA s4B(4) | **Live.** Leases started in lookback with a completed incoming inspection (tenant + agent signature or recorded refusal). | M | `R:rental-inspections` |
| RHA-5 | **Move-out inspection offered and recorded.** | RHA s4B(5) | **Live, +later (statutory pre-vacating window).** Leases ended in lookback with an outgoing inspection completed or "did not attend" recorded. | M | `R:rental-inspections` |
| RHA-6 | **Deposit refunded within 7 / 14 / 21 days.** | RHA s4B(3), (6)† | **FF·F17** | H | — |
| RHA-7 | **Written receipts for tenant payments.** | RHA s4A(1) | **FF·F17** | M | — |
| RHA-8 | **No unfair practices (lock-out, utility cut-off, entry without notice).** | RHA s4A(5)–(6); unfair-practices regs† | **FF·F18** | L | — |
| RHA-9 | **Tenant told of the Rental Housing Tribunal route.** | RHA ss7, 13† | **FF·F17** | M | — |
| PIE-1 | **No self-help eviction.** | PIE s4, s8† | **FF·F18** | M | — |
| PIE-2 | **Holdover occupiers only removed by court order.** | PIE s4† | **FF·F18** | M | — |

### 7.6 Sales-law (Alienation of Land, Matrimonial Property, sectional title, certificates, e-sign) — area Sales (20: 19 audit + ESIGN-1)

| Code | Rule — message | Act & section | Evaluated by | Sev | Fix |
|---|---|---|---|---|---|
| ESIGN-1 | **E-signing is on for sale documents.** "E-signing is switched on for {n} sale document template(s) — a sale of land signed electronically may not be valid." | ECTA s4(4) read with Sch 2 item 1; AoLA s2(1) | **Live (added).** Templates with `is_esign` whose document type has `esign_warning_required`; f = 0 if any. Accepted-by shown from `template_esign_acknowledgements`. | H | `docuperfect.templates.index` (template setup) |
| AoLA-1 | **Signed sale agreement actually on file.** "{n} deals show 'OTP signed' with no signed copy." | AoLA s2(1) | **Live.** Deals with the "OTP Signed" step done and no signed document attached, plus "Documents signed" ticked without a file. | M | `R:deals-dr2.index` |
| AoLA-2 | **Written mandate before a deal/commission.** "{n} deals started on properties with no signed mandate." | AoLA s2(1); PPA | **Live.** Deals/commission entries in lookback whose property had no signed mandate on file at creation. | M | `R:deals-dr2.index` |
| AoLA-3 | **Agent signs for others only on written authority.** | AoLA s2(1) | **FF·F19** | M | — |
| AoLA-4 | **Cooling-off period on sales tracked.** | AoLA s29A† (scope to confirm) | **FF·F16** | M | — |
| MPA-1 | **Marital regime recorded.** | MPA s15 | **FF·F20** | H | — |
| MPA-2 | **Spousal consent where required.** | MPA s15 | **FF·F20** | H | — |
| ST-1a | **Levy clearance for sectional-title sales.** "{n} sectional-title sales lodged without a levy clearance on file." | STSMA† | **Live.** Sectional-title sale deals (`properties.title_type`) past lodgement with the levy-clearance step done **with a file**. | M | `R:deals-dr2.index` |
| ST-1b | **Body corporate / managing agent tied to the property.** | STSMA† | **FF·F21** | L | — |
| ST-1c | **Levies and scheme rules on file for sectional-title listings.** | STSMA†; PPA s67 | **Live.** Active sectional-title sale listings with levy statement + house/scheme rules documents. | M | `R:properties.show#drive` |
| ST-2 | **Scheme registration / CSOS obligations.** | CSOS Act† | **FF·F21** | L | — |
| CC-1 | **Electrical certificate on file.** "{n} sales past instruction have no electrical certificate." | Electrical Installation Regs (OHS Act)† | **Live.** Sale deals past "attorneys instructed" with the certificate step done with a file. | M | `R:deals-dr2.index` |
| CC-2 | **Beetle certificate on file.** | Contractual / municipal† | **Live.** As CC-1. | M | `R:deals-dr2.index` |
| CC-3 | **Gas certificate where the property has gas.** | Pressure Equipment / OHS Regs† | **FF·F22** | L | — |
| CC-4 | **Electric-fence certificate where there is a fence.** | Electrical Installation Regs† | **FF·F22** | L | — |
| CC-5 | **Plumbing / water certificate where the municipality requires it.** | Municipal by-laws† | **FF·F22** | M | — |
| CC-6 | **Occupancy certificate.** | National Building Regs Act† | **FF·F22** | L | — |
| CC-7 | **Rates clearance certificate on file.** | Municipal Systems Act s118† | **Live.** Sale deals past "attorneys instructed" with the rates-clearance step done with a file. | M | `R:deals-dr2.index` |
| CC-8 | **Certificate validity tracked.** | — | **FF·F22** | L | — |
| MDF-1 | **Condition report answered in full.** "{n} disclosure forms have unanswered rows." | PPA s67; Regs 2022 s36† | **Live.** Sale listings marketed whose disclosure was completed on a CoreX disclosure template with every Yes/No/N-A row answered. | M | `R:properties.show#compliance` |

### 7.7 Features that unlock the 42 feature-first rules

Each is its own spec and lane; shipping one flips its rules from `feature_first` to `live` by adding an evaluator and a `compliance_rules` update (no change to the engine).

| Feature | Unlocks | Feature | Unlocks |
|---|---|---|---|
| F1 STR / CTR / TPR registers | FICA-12, 13, 14 | F13 Conveyancer free-choice record | PPA-6 |
| F2 Directive 12 RMCP submission record (AT-505) | FICA-17 | F14 Candidate practitioner tracking | PPA-9 |
| F3 FICA/AML training tag | FICA-15 | F15 24-month term check | CPA-1 |
| F4 Record-retention guard + legal hold | FICA-11, PPA-5 (+POPIA-12) | F16 Cooling-off tracking (sales + direct marketing) | CPA-4, AoLA-4 |
| F5 Regulator registration + PAIA manual | POPIA-1b, POPIA-2 | F17 Deposit refund clock, receipts, tribunal info, interest statement | RHA-3, 6, 7, 9 |
| F6 Marketing-send guards (presentation e-mails, agent sends, footer, STOP) | POPIA-7c, 7d, 7e, 7i | F18 Unlawful-acts guidance + holdover/court-order step | RHA-8, PIE-1, PIE-2 |
| F7 Data-subject request register | POPIA-9 | F19 Power-of-attorney record | AoLA-3 |
| F8 Operator + cross-border provider register | POPIA-10, POPIA-13 | F20 Marital regime + spousal consent | MPA-1, MPA-2 |
| F9 Security-compromise register | POPIA-11 | F21 Sectional-title scheme data | ST-1b, ST-2 |
| F10 Special-information authorisation record | POPIA-14 | F22 Certificate tracking (gas, fence, plumbing, occupancy, validity) | CC-3, 4, 5, 6, 8 |
| F11 ID-number encryption (platform) | POPIA-15 | F23 NCC opt-out registry cleansing | POPIA-CPA-1 |
| F12 Advert evidence kept + FFC statement on adverts | adds to PPA-2, PPA-5 | | |

Tally: 3+1+1+2+2+4+1+2+1+1+1+1+1+1+2+4+3+1+2+2+5+1 = **42**.

---

## 8. Warn-at-switch-on (and at the moment of the act)

### 8.1 The pattern (one framework, generalised from the existing e-sign one)

1. A **warning registry** `config/compliance-warnings.php`: `warning_key → { setting_key, trigger (on | off | below | above | cleared | ended_without_successor), rule_codes[], legal_refs[{act, section}], risk_line, confirm_label, version }`. Wording is agency-neutral and carries a `version`; bumping it re-prompts. Which settings warn is **data in the registry**, so when the law changes it is an edit, not recoding (same principle as `document_types.esign_warning_required`).
2. A shared modal `public/js/compliance-warning-modal.js` + Blade partial `components/compliance-warning`, generalising `public/js/esign-acknowledgement-modal.js` (89 lines) and `config/esign-acknowledgement.php`. Shown **only to the admin changing the setting, on that settings screen** — never to agents, never in the agent's flow (keeps the ESIGN-CANON §7 rule: "nothing about it is ever shown to an agent").
3. **Modal content:** title "Before you switch X on/off"; the **Act name and section** (link to the section in the Legal Library, opens in a new tab); the plain risk line (≤ 2 sentences); "This will lower your Compliance Score by about N points" (computed from the rule's weight — the pre-change preview); a confirm tick ("I understand. This is my agency's decision."); buttons **Switch on anyway** / **Cancel — keep it as it was**. The section text is shown only when the section is verified (§9.5); otherwise Act + section reference + link to the PDF.
4. **Server contract:** the saver calls `ComplianceWarningService::needsAcknowledgement($key, $old, $new)`. No acknowledgement posted → `422 compliance_ack_required` carrying the modal payload (same shape as `esign_ack_required`); acknowledgement posted → save proceeds **always**. No acknowledgement is ever "denied".
5. **Record** (`compliance_warning_acceptances`, insert-only): agency, user, on-behalf-of, warning key, setting, old → new, rule codes, `legal_act_version_id` + section **as at that moment** (a later amendment never rewrites history), wording version and exact wording snapshot, route / IP / user agent, time. Setting it back to the safe value writes a `reverted` row; the next recalculation recovers the points.
6. **Recalculate** for that agency right after the save (§11) so the admin sees the new score immediately.

### 8.2 Registered warnings (v1)

| Key | Trigger (what the admin does) | Where (existing screen / saver) | Rules lowered | Act & section |
|---|---|---|---|---|
| W1 `esign_sale_documents` | Switch e-signing **on** for a template whose type is flagged | Template setup — **already built** (`EsignAcknowledgementService`, modal, `template_esign_acknowledgements`). Changes: re-word to ECTA s4(4)/Sch 2 + AoLA s2(1) (wording **version 2**, Johan to approve), and also write one generic ledger row so the log is a single list. | ESIGN-1 | ECTA s4(4), Sch 2; AoLA s2(1) |
| W2 `required_docs_removed` | Untick **mandate**, **disclosure** or **fica** from the marketing-readiness required list | Compliance → Agency Documents (`compliance.agency-settings.index`, `manage_agency_compliance`) | PPA-4, PPA-10, AoLA-2 | PPA s67, s61; AoLA s2(1) |
| W3 `send_window_widened` | Save an outreach send window outside the legal envelope | Company Settings (`CompanySettingsController.php:172`†) | CPA-7 | CPA Regs reg 4† |
| W4 `early_cancel_weakened` | Early-cancel business days below 20, or early cancellation disabled | Lease settings (`LeaseSettingsController.php:116`†) | CPA-2 | CPA s14 |
| W5 `privacy_or_footer_cleared` | Clear the privacy-policy URL or the unsubscribe footer | Company Settings (`Agency.php:235,864`) | POPIA-3 (+ POPIA-7e when F6 exists) | POPIA s18; s69 |
| W6 `golive_grandfathering` | Leave **mark compliant** / **mark FICA approved** ticked on a go-live import (default checked → the prompt appears on every run) | Admin → Importer; Contacts import | GOLIVE-1 | FICA s21; PPA s67 |
| W7 `officer_ended_no_successor` | End the Information Officer or Compliance Officer appointment with no active successor | `InformationOfficerAppointmentsController::endAppointment`, `FicaOfficerAppointmentsController::endAppointment` | POPIA-1a, FICA-4 | POPIA s55; FICA s42A† |

### 8.3 Setting changes trigger recalculation

Each registered saver fires `ComplianceSettingChanged(agencyId, settingKey)` after saving; a listener queues a unique-per-agency `RecalculateComplianceScore` job (60 s debounce). Savers for non-warning settings that feed rules (FICA windows, lease settings, contact governance, officers) fire it too.

### 8.4 Action-time warnings (same ledger, `kind = action`)

| Key | The act | Behaviour |
|---|---|---|
| B1 `commission_lapsed_ffc` | Commission is calculated / captured / paid for an agent whose FFC is missing or expired (PPA s48, s56) | Inline amber notice on the commission screen showing Act + section and a link to fix the FFC; **Continue is always available**; one ledger row per acknowledged entry; PPA-1 counts it. The exact screens are named in Phase 8 (the commission code has no FFC reference today — `CommissionCalculationService`, `Finance/*`, `Payroll/*`, `Proforma/*`, `DealV2/*` searched in the audit). Shown to the finance/admin user capturing the entry, never to agents who only view. |
| B2 (arrives with F6) | An agent sends one-to-one to an opted-out contact | Warn + record, allow. |
| S1 `fica_waived_on_signing` | An agent unticks "FICA required before signing" for a recipient in the e-sign wizard (`SigningController.php:157`) | **No modal** (would add friction in the agent flow; the ESIGN-CANON rule keeps warnings out of agents' hands). Recorded silently as `kind = waiver`; counted in FICA-18's detail and visible in the Accepted-warnings tab. |

---

## 9. Legal Library

### 9.1 Verdict: **extend** — new global tables; do not reuse `knowledge_documents`

Reasons: (1) the KB is tenant-scoped and a NULL `agency_id` is an orphan nobody can read — a global entry is impossible without weakening isolation on a security-critical table; (2) any agency's KB admin can upload/delete — law must be CoreX-owned and read-only to agencies; (3) KB has one version string, no supersede chain, no section addressing; (4) KB "archive" deletes the stored file (`KnowledgeController.php:119-124`); (5) the retrieval pattern already tolerates a global source (`TrainingDocChunk`, `KnowledgeSearchService.php:64-67`). What **is** reused: `DocumentProcessingService` extraction + chunking (a thin public wrapper is added — no behaviour change), `EmbeddingService`, the `ellie_reference_chunks` table shape, `KnowledgeSearchService`'s hybrid scoring, `isOwnerRole()` as the management gate (same as the `esign_warning_required` flag, `DocumentTypeController.php:88`).

### 9.2 Behaviour

- **One copy, all agencies read it.** Tables in §5 (`legal_acts`, `legal_act_versions`, `legal_act_sections`, `legal_act_chunks`), no `agency_id`, **no `BelongsToAgency`**. PDFs on a private disk, streamed through a permission-checked route (`legal_library.view`), never public URLs; `file_sha256` stored. Disk: the files total 78 MB — fine on `/`, but the storage path is set by config so it can sit on the data volume.
- **Version + effective date per Act.** `effective_from` / `effective_to`, `is_current`, `supersedes_version_id`. Adding a new version marks the old `superseded` — **never deleted**; both stay downloadable. "Original gazette — amendments not applied" is shown as a badge (`is_consolidated = false`; the INDEX flags several as original gazette / outdated).
- **Soft delete only**; restore supported; CoreX-owner CRUD screen (create Act, add version, edit metadata, supersede, archive, restore) at `/corex/admin/legal-library/manage`, owner role only, **not a grantable agency permission**.
- **Agency screen** (read-only) at `/corex/compliance/legal-library`: list of Acts with search (title, short code, number, section heading, section text), sort (default: area then title), filters (area, kind, current/superseded), pagination, empty state; Act page with versions, PDF download and the curated sections with anchors (`#s4-4`). Sidebar item in the Compliance group; permission `legal_library.view` (default-granted wherever `access_compliance` is).
- **Rules and warnings link to Act + section**: `compliance_rules.act_id + section_ref` resolved to the version current *now*; acceptances store the version id at the time.
- **Ellie** reads the library: `KnowledgeSearchService` gains a third source (`LegalActChunk`, current versions only unless the question names a date) and answers cite "Act, section". No change to agency KB behaviour.

### 9.3 Seeding

`php artisan legal-library:import <INDEX.md> [--dry-run]` reads `/mnt/HC_Volume_103099143/corex-knowledge/acts/INDEX.md` (columns: title, number/year, what it governs, source URL, consolidation date, file name, size, status), creates Act + version rows, copies the PDFs, extracts text and embeds chunks. Idempotent (by `file_sha256`); dry run is the default; never overwrites a curated section. Registered in `deploy:sync-reference-data`.

### 9.4 Section curation

Only sections cited by a rule or a warning are curated into `legal_act_sections` (≈ 60–70 rows) — typed from the PDF text, with `verified_by_user_id/verified_at`. Everything else is reachable through the PDF and through Ellie's chunks.

### 9.5 Verification gate

A rule's `section_ref` is **unverified** until the matching `legal_act_sections` row is marked verified. An unverified rule is scored but its message shows "Act, section (to be confirmed)" and the warning modal does not quote section text; a † reference in §7 must be verified before its rule is set `live` in production. Counsel confirms wording (the audit's standing caveat).

### 9.6 Findings to hand to Johan (reported, not changed)

- The shipped sale disclosure form cites "Property Practitioners Act 22 of 2019, Section 70" (`resources/views/docuperfect/web-templates/sales-mandatory-disclosure.blade.php:6,33`). In the gazetted Act the **Mandatory disclosure form is section 67**; section 70 is "Regulations". Counsel should check whether the form's citation is wrong.

---

## 10. The screen — Compliance → **Compliance Score**

### 10.1 Route, nav, permission
- `GET /corex/compliance/score` → `compliance.score.index`; `POST /corex/compliance/score/recalculate` → `compliance.score.recalculate`. Middleware `['auth', 'agency.required', 'feature:compliance', 'permission:compliance_score.view']`; recalculate also `permission:compliance_score.recalculate`.
- Sidebar: **first item** in the Compliance group (`corex-sidebar.blade.php:2132-2138`), gated by `@permission('compliance_score.view')`, active on `routeIs('compliance.score.*')`.
- Permission keys in `config/corex-permissions.php` (section `compliance`): `compliance_score.view`, `compliance_score.recalculate`, `compliance_score.configure` (thresholds / notifications). Default grants: `admin` role only. **No hardcoded roles** anywhere — Role Manager decides; the CO is an *appointment*, not a role, so a Compliance Officer sees the screen only if their role holds the permission.
- **Visibility: AGENCY level only.** No own/branch variants (a branch manager sees nothing here unless the role is granted `compliance_score.view`, and then sees the whole agency). Every query goes through `BelongsToAgency` on runs/results/acceptances; direct-URL access to another agency's run/result id returns 404.

### 10.2 Layout (returned + next action first; no helper text, no decoration)
1. **Sticky top header** (`<x-list-header>`): title "Compliance Score"; right side: "Last calculated 23 Oct 2026 02:31" and the **Recalculate** button. No other chrome.
2. **Score row** (`<x-corex-kpi-card>` × 7): Overall, PPA, FICA, POPIA/PAIA, CPA, RHA/PIE, Sales-law. `value` = whole-number %; `trend` = points change vs the previous run of the same rule set; an unscored area shows "—".
3. **Items list** — default view = **needs attention** (fail + partial), each row: severity pill · rule title · area · Act + section (link to the library) · what's wrong (the message with the counts) · points lost · status (an "Accepted by <name>, <date>" pill when applicable) · **Fix** button (deep link to the exact spot). Rows expand (Alpine) to show up to 20 affected records with links; "view all" goes to the filtered list.
4. Tabs under the list: **Items** (default) · **Accepted warnings** (who, when, setting, Act + section, wording version; read-only ledger list with the same list standard) · **History** (last 12 runs: date, trigger, overall, change).

### 10.3 List standard (BUILD_STANDARD §1a–1c)
- **Search** (named fields): rule title, message text, Act title / short code, section reference, rule code.
- **Sort** (every sensible column; default **severity desc, then points lost desc**): severity, points lost, area, title, Act, status, state-changed date.
- **Filter:** area; status (**Needs attention** [default] / Passing / Accepted warning / Not applicable / Not yet scored); severity; date range on *state changed* (last 7 / 30 / 90 days / custom).
- **Pagination:** 25 per page.
- **Empty states (three, distinct):** nothing needs attention ("Nothing needs attention. Last calculated …" with the next-action link to the History tab); no results for the filters ("No items match — Clear filters"); never calculated ("No score yet" with the Recalculate button, and a first-visit calculation is queued automatically).
- **CRUD floor:** results are system-computed (no create/edit/delete by users); acceptances are insert-only by law-of-the-ledger; the Legal Library has full CRUD (owner screen). Archive/restore applies to library records; results are append-only.

### 10.4 "Not yet scored" rules
They are listed under the status filter "Not yet scored" with the feature name and **never count**. Whether agencies see that list at all is **Q2**; until Johan decides the screen shows only the count ("42 checks not yet active") on the Items tab footer and hides the rows.

### 10.5 Reuse
`x-list-header`, `x-corex-kpi-card`, `x-sort-header`, `x-pill`, `x-sticky-action-bar`, `layouts.corex`. Nothing new in the component library.

### 10.6 Placement note
Johan said "Admin > Compliance > Compliance Score". The existing sidebar has a top-level **Compliance** group (and PPRA Inspection Pack was deliberately kept out of it). This spec places the screen first in **Compliance**; moving it under the Admin/Company section is a nav-only change.

---

## 11. Recalculation

- **Nightly** at 02:30 (`Schedule::command('compliance:score-recalculate')->dailyAt('02:30')->onOneServer()->withoutOverlapping()`, agencies chunked, queue job per agency; follows the pattern at `routes/console.php:83`). **On demand** (Recalculate button; one run per agency at a time; the button shows progress and the header time updates). **On relevant setting change** (§8.3). **First visit** with no run.
- A run is read-only against the pillars, uses aggregate SQL (no per-row loops), caps `detail` at 20 records, and fails soft per rule (an evaluator that throws records `not_applicable` with the error logged and a platform banner — it never fails the whole run or the page).
- **Query-count test:** one agency run stays under a fixed query budget (asserted), so a large agency cannot make the nightly job slow.
- **Scheduler caveat:** the scheduler runs on a different box set than QA1 (see memory "where cron really runs"); QA1 proofs call the command by hand.

## 12. Notifications

- Two new gateway event types, registered by migration + `NotificationEventTypeSeeder` exactly like `rmcp.version_approved` (`NotificationEventTypeSeeder.php:120`, migration `2026_11_28_100000_…`): `compliance.score_dropped`, `compliance.warning_accepted`. In-app on, e-mail off by default (per-user preferences apply).
- **Recipients:** active users of the agency who hold `compliance_score.view` (permission-resolved, no hardcoded roles). The acceptor is not notified of their own acceptance.
- **Score dropped:** fires after a run when overall falls by at least `score_drop_alert_points` (default 1) versus the previous run **of the same rules version**; one notification per run; text = old → new, the top contributing rule, link to the screen.
- **Warning accepted:** immediately, one per acceptance (who, what, Act + section, points lost, link).
- Agency settings (default **on**): `notify_on_score_drop`, `notify_on_warning_accepted`.

## 13. Agency settings (and the Setup Wizard — Non-negotiable #10a)

`agency_compliance_settings` (one row per agency; `NULL` = default; read through one resolver with CoreX bounds):

| Setting | Default | Bounds | Used by |
|---|---|---|---|
| `lookback_months` | 12 | 3–36 | sampling window for "recent" rules |
| `ffc_warning_days` | 60 | 14–180 | PPA-1 amber (replaces the code constant `PractitionerFfcRosterService::AMBER_WINDOW_DAYS`) |
| `pi_warning_days` | 60 | 14–180 | PPA-8 |
| `rmcp_signoff_grace_days` | 30 | 0–90 | FICA-3 |
| `score_drop_alert_points` | 1 | 1–20 | notification threshold |
| `notify_on_score_drop` | on | — | §12 |
| `notify_on_warning_accepted` | on | — | §12 |

Weights are **not** settings. Surfaces: Settings → Compliance (saver `has()`-guarded per field, permission `compliance_score.configure`, changes written to the ledger as `kind = changed`) **and** the Setup Wizard's Compliance step in `config/agency-onboarding-copy.php` with `explain` / `affects` for each control, wired into that step's `savers` (read `agency-onboarding-setup.md` §6.1 first — guard every boolean with `$request->has()` so an absent checkbox cannot wipe a setting). If Johan decides any of these should *not* be in the wizard it goes in the spec's "Deliberately NOT in the wizard" list (§5.1) — his call, not the lane's.

---

## 14. Build order (lane-sized phases; one cleared Sonnet lane each; one test file per phase, run via `scripts/lane-test.sh`)

| # | Phase | Delivers | Depends on | Single test file |
|---|---|---|---|---|
| P1 | **Legal Library foundation** | 4 global tables + models; `legal-library:import` (dry-run default); PDF stream route; owner CRUD screen; agency read-only list/Act page; permission + nav; `deploy:sync-reference-data` registration | — | `LegalLibraryTest` |
| P2 | **Library → Ellie** | `LegalActChunk` third source in `KnowledgeSearchService`; citation of Act + section; `DocumentProcessingService` public chunk wrapper | P1 | `LegalLibraryEllieSearchTest` |
| P3 | **Score engine core** | `compliance_rules` (+ `config/compliance-rules.php` with all 91 rules, 42 `feature_first`), runs/results tables, `RuleEvaluator` contract, `ComplianceScoreService`, `compliance:score-recalculate`, scoring maths (§6), N/A handling, link-resolves test | P1 | `ComplianceScoreEngineTest` (incl. two-agency scoping, query budget) |
| P4 | **The screen** | route, nav, permissions, header, KPI row, Items list (search/sort/filter/paginate/empty states/expand), History tab, first-visit calc | P3 | `ComplianceScoreScreenTest` |
| P5 | **Evaluators A — PPA + FICA (8 + 13 live)** | PPA-1/2/3/4/7/8/10/11; FICA-1…10, 16, 18, GOLIVE-1 | P3 | `ComplianceEvaluatorsPpaFicaTest` |
| P6 | **Evaluators B — POPIA + CPA (11 + 4 live)** | POPIA-1a/3/4/5/6/7a/7b/8/12/16/17; CPA-2/3/6/7 | P3 | `ComplianceEvaluatorsPopiaCpaTest` |
| P7 | **Evaluators C — RHA + Sales (4 + 9 live)** | RHA-1/2/4/5; ESIGN-1, AoLA-1/2, ST-1a/1c, CC-1/2/7, MDF-1 | P3 | `ComplianceEvaluatorsRentalsSalesTest` |
| P8 | **Warn-at-switch-on framework + ledger** | registry, modal, `ComplianceWarningService`, `compliance_warning_acceptances` (insert-only), `ComplianceSettingChanged` + recalc listener, **W1 (e-sign re-word v2 + ledger row)** and W2–W7, S1 | P3 (P4 for the Accepted tab) | `ComplianceWarningsTest` |
| P9 | **Action-time warning B1** | investigate and name the commission screens; inline notice; ledger row; PPA-1 commission count | P8, P5 | `CommissionFfcWarningTest` |
| P10 | **Notifications + settings + wizard** | two event types, threshold/toggle settings, Settings → Compliance saver, Setup Wizard step, audit rows | P4, P8 | `ComplianceSettingsNotificationsTest` |

P1–P2 and P3–P4 can run in parallel lanes (two working lanes at a time). Feature-first rules (§7.7) are **not** in this build; each later feature flips its own rules.

## 15. Acceptance criteria (the whole feature)

1. A new agency sees a score computed only from rules that apply to it; N/A and not-yet-scored rules change nothing; a rentals-only agency has no Sales-law score.
2. Switching on e-sign for an OTP template shows the Act + section modal, records who accepted (wording snapshot + library version), **allows the save**, lowers the score on the immediate recalculation, and shows "Accepted by …" on the item — which stays failing. Switching it back restores the points.
3. Commission captured with a lapsed FFC shows the inline notice, always lets the user continue, records it, and PPA-1 reflects it.
4. Every item's Fix button lands on the exact screen/setting; a test fails if any `fix_route` doesn't resolve.
5. Nothing in this feature adds a block; the only refusal is a save without the recorded acknowledgement.
6. Agency A never sees agency B's results, acceptances or runs (404 by id); the nightly job (no user) is proved to scope by `agency_id` with a two-agency test.
7. The Legal Library has one copy, readable by every agency, writable only by CoreX owner role; superseded versions stay downloadable; nothing hard-deletes.
8. Ellie answers a legal question citing Act + section from the library.
9. Weights cannot be edited from any agency screen; thresholds clamp to CoreX bounds.
10. All new settings appear in the Setup Wizard with `explain` / `affects`.
11. Navigation entry, permissions (Role Manager), route middleware and controller checks are all in the same phase as each screen (Non-negotiables #2, #5).
12. Definition of Done per BUILD_STANDARD §8 for each phase (`php -l`, view/route/cache clear, the one test file, Blade render gate + browser smoke on the screen phase, deploy sequence incl. `deploy:sync-reference-data`).

## 16. Files (create / modify)

**Create:** migrations + schema snapshot for §5; models `ComplianceRule`, `ComplianceScoreRun`, `ComplianceRuleResult`, `ComplianceWarningAcceptance`, `AgencyComplianceSettings`, `LegalAct`, `LegalActVersion`, `LegalActSection`, `LegalActChunk`; `app/Services/Compliance/Scoring/` (`ComplianceScoreService`, `RuleEvaluator`, evaluator groups, `ComplianceWarningService`); `config/compliance-rules.php`, `config/compliance-warnings.php`; `app/Console/Commands/Compliance/ScoreRecalculate.php`, `LegalLibraryImport.php`; controllers `Compliance/ComplianceScoreController`, `Compliance/LegalLibraryController`, `Admin/LegalLibraryManageController`; views `compliance/score/*`, `compliance/legal-library/*`, `admin/legal-library/*`; `public/js/compliance-warning-modal.js`; Blade partial `components/compliance-warning.blade.php`; listeners + jobs; tests per §14.
**Modify:** `routes/web.php`, `routes/console.php`, `config/corex-permissions.php`, `config/corex-features.php` (library + score flags if wanted), `resources/views/layouts/corex-sidebar.blade.php`, `app/Services/AI/KnowledgeSearchService.php`, `app/Services/AI/DocumentProcessingService.php` (public wrapper only), `config/esign-acknowledgement.php` (wording v2), `app/Services/Docuperfect/EsignAcknowledgementService.php` (one extra ledger write), the W2–W7 savers listed in §8.2, `PractitionerFfcRosterService` (read `ffc_warning_days`), `config/agency-onboarding-copy.php` + its spec §5.1, `NotificationEventTypeSeeder` + migration, `.ai/specs/corex-domain-events-spec.md` (new events), `.ai/CHAT_STARTER.md`.

## 17. Reported, not changed (found while investigating)

1. **Existing hard stops conflict with "never blocks"** — listed in §3; decision is Q1.
2. **`KnowledgeController::destroy` deletes the stored file on "archive"** (`:119-124`) — contradicts soft-delete-only for recoverability.
3. **Disclosure form cites PPA "Section 70"; the Act's disclosure-form section is 67** (§9.6).
4. **RCR sidebar link hard-codes roles** (`corex-sidebar.blade.php:1836`).
5. **Three FFC expiry sources can disagree** (`users.ffc_expiry_date`, the uploaded certificate, `AgentApplication.ffc_expiry`) — PPA-1 will use the roster's definition; unifying the sources is outside this spec.
6. **QA1 database looks incomplete** (audit note): RMCP, sanctions, training, RCR, `properties`, `signature_*` tables returned "doesn't exist" on `corex_qa1` although migrations are recorded as run. Evaluators for those areas cannot be proved on QA1 until that is checked by whoever owns the QA1 database.
7. The audit's FICA-8 comment drift (`config/tfs.php` says a stale list degrades to review-required; code writes `error`) — scoring treats a stale list as a platform banner.

## 18. Open questions for Johan (business only)

- **Q1 — Existing stops.** CoreX already stops in a few places (an outside signer who hasn't done FICA, marketing a property with no mandate, an exact sanctions match, auction publishing with a lapsed FFC, retention below 5 years). Under "never blocks", should any of these become warn-and-score too, or stay as they are? *Recommendation: leave them — you set them deliberately (including the 8 Oct FICA ruling) and they are the only places the system itself refuses; this spec adds none.*
- **Q2 — Do agencies see what CoreX cannot yet check?** 42 of the 91 checks cannot be scored yet (for example the suspicious-transaction register). Listing them shows a paying agency — including the Cape Town agency — where CoreX has gaps. *Recommendation: show only the count ("42 checks not yet active") until each one is built, rather than naming them.*
- **Q3 — One legal line on the screen?** The score is guidance, not legal advice. A single fixed footer line would protect CoreX but is the one piece of text on a screen you want free of text. *Recommendation: include it in the Legal Library pages, not on the score screen.*
- Wording of the plain risk lines and the e-sign v2 warning is draft and needs your approval (and counsel's) before build; weights (Critical 10 / High 6 / Medium 3 / Low 1) are a starting point you can change later without a release.
