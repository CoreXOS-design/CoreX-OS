# Atlas — Compliance (FICA · POPIA/CPA consent · Whistleblower)

> **Status: DONE** · Last verified: 2026-06-22
> Pillars: **Contact** (consent) × **Agent/User** (FFC, screening) × **Deal/Property** (FICA gating).
> Regulator: **PPRA** (Property Practitioners Regulatory Authority) — never EAAB. Companion specs:
> `.ai/specs/compliance.md`, `.ai/specs/contact-consent.md`, `.ai/specs/contact-communication-status.md`,
> `.ai/specs/whistleblower-compliance-spec.md`. Cited audits: `compliance-audit-2026-06.md`,
> `popia-columns-investigation-2026-05-25.md`. Cited tickets: AT-45→50 (consent), AT-47 (templates).

---

## 1. WHAT IT DOES

The compliance stack enforces South African legal obligations across CoreX: **FICA** identity
verification (24-month validity), **POPIA/CPA** direct-marketing consent (the opt-in/opt-out engine,
"one opt-out suppressed everywhere"), **RMCP** risk programmes, employee/agent **screening**, **policy**
acknowledgement, the **Whistleblower** (PPRA breach reporting) module, and the Information-Officer /
Compliance-Officer appointment registers. The convergence point is the **three-state derived
communication status** on each Contact.

---

## 2. ENTRY POINTS — sub-modules

All web routes in `routes/web.php`, grouped by prefix/name; controllers under
`app/Http/Controllers/Compliance/`.

| Sub-module | Route group | Controller |
|------------|-------------|------------|
| FICA (internal) | `compliance.fica.*` `:1561-1581` | `FicaController.php` |
| FICA (public token form) | `fica.form/submit/confirmation` | `FicaPublicController.php` |
| RMCP manager / dashboard / staff ack | `compliance.rmcp.*` `:1478-1500`, `.dashboard.*` `:1379-1384`, `rmcp.ack.*` `:1359-1375` | `RmcpController`, `RmcpDashboardController`, `RmcpAcknowledgementController` |
| RCR (FIC Directive 11/2026) | `corex.compliance.rcr.*` `:165-180+` | `Compliance/Rcr/RcrSubmissionController` |
| Screening | `compliance.screenings.*` `:1386-1410` | `EmployeeScreeningController`, `…DashboardController` |
| Policy manager/dashboard/ack | `compliance.policy.*` `:1504-1553` | `PolicyController`, `PolicyDashboardController`, `PolicyAcknowledgementController` |
| Whistleblower | `compliance.whistleblow.*` `:1584-1593` | `WhistleblowController` |
| Seller Info Pack | `compliance.seller-info.*` `:1596-1602` | `SellerInfoController`, `SellerInfoPublicController` |
| Communications Log / Archive / Mailboxes / Flags | `compliance.communications/comm-archive/comm-mailboxes/comm-flags.*` `:1604-1643` | `CommunicationsLogController`, `CommunicationArchiveController`, … |
| Verification Queue / Document Types / Agency Documents | `compliance.verification/document-types/agency-settings.*` `:1662-1682` | `DocumentVerificationController`, `AgencyDocumentTypeConfigController`, `AgencyComplianceSettingsController` |
| Officer registers | `compliance.officer.index` `:1559`, agent compliance `:1471` | `RmcpComplianceOfficerController`, `FicaOfficerAppointmentsController`, `AgentComplianceController` |
| Marketing Suppressions (admin) | `admin.marketing-suppressions.*` `:256-263` | `Admin/MarketingSuppressionController` |

**Public (no-auth) consent routes:** opt-out `:34-41`, opt-in `:46-53`, generic unsubscribe `:58-67`,
public landing `/m/{shortcode}` `:28-31`, public privacy `/legal/privacy/{token}` `:99-104`.
Nav: Compliance sidebar group `corex-sidebar.blade.php:949-1035`.

---

## 3. FICA

**Model `app/Models/FicaSubmission.php`:** token + `token_expires_at` `:21-23`; tri-actor verification
(agent `:36-39`, compliance officer `:42-45`, wet-ink `:47-49`); **FICA validity `fica_expires_at` `:51`**.
Lifecycle `draft → submitted → agent_approved → approved` (also under_review/corrections/rejected/cancelled)
`:191-204`; `isFicaExpired()` `:154`, `scopeExpiringSoon($days=60)` `:159`.

**Flow:** public token form (no auth) `FicaPublicController::form` `:16`, `uploadDocument` `:198`, `submit`
→ `submitted` `:172-192` → agent review `FicaController::agentApprove` → `agent_approved` `:278-292` →
compliance officer `complianceApprove` → `approved` **+ stamps `fica_expires_at = now()->addMonths(24)`**
`:326-347`. Wet-ink intake path `createWetInk`/`storeWetInk` `:1565-1566`.

**FFC (Fidelity Fund Certificate) — per agent, on the User model** (not FICA):
`User.ffc_certificate_path` `:56`, `ffc_number` `:69`, `ffc_expiry_date` `:70`. Agency `Agency.ffc_no` `:57`.
Onboarding gates on FFC (`AgentApplication.php:84` `ffc_valid`, required in compliance_review /
mentor_assignment `:103-104`); expiry reminders in Command Centre `CommandCentreService.php:524-529`.

**What gates on FICA:** the **e-Sign signing gate** — `signature_requests.fica_required` +
`fica_submission_id` (`Docuperfect/SignatureRequest.php:44,59`), enforced
`SigningController.php:124`, set `ESignWizardController.php:2086`. Marketing-readiness snapshot records
each seller's latest FICA status (`MarketingReadinessService.php:115-119`).

---

## 4. POPIA / CPA DIRECT-MARKETING CONSENT — the opt-in/opt-out engine (AT-45→50)

**Convergence service `app/Services/SellerOutreach/MarketingConsentService.php`** ("one opt-out,
suppressed everywhere", `:14-30`). `optOutContact($blockAll)` writes **four stores in one transaction**
(`:62-117`): (1) `contact_consent_records` revoke `:91-95`; (2) `contacts.messaging_opt_out_*` triplet
`:72-78`; (3) per-channel booleans `opt_out_email/sms/whatsapp/call` `:97`; (4) identifier-level
`marketing_suppressions` `:104-115`. `blockAll` = the all-blocked latch `:81-85`. `optInContact()` reverses
all four + lifts suppressions + stamps opt-in marker `:165-203`. Reads: `isContactSuppressed()` `:216-227`.

**Three-state derived communication status (Contact)** — `Contact.php:561-633` (see `contacts.md` §4):
`opted_in` / `transaction_only` (live-sale lock via `TransactionStateService::isInLiveTransaction` `:590-593`)
/ `all_blocked` / `marketing_opted_out`. **Derived, never stored.**

**Consent records — `app/Models/ContactConsentRecord.php`:** tri-state `DECISION_GIVEN/DECLINED` `:14-15`;
`scopeActive` (null revoked_at) `:43`. Consent types (`contact-consent.md`): `fica_processing`,
`marketing_communications`, `data_sharing`, `channel_email/sms/whatsapp/call`. Observer recomputes channel
flags on create (`ContactConsentRecordObserver`). One active record per type, full history retained.

**Public token flow (no TTL, CSRF-exempt):** `PublicOptOutController.php` — GET preview-safe never writes
`:54-61`; POST actions `stop_marketing`/`resume_marketing`/`stop_all` `:67-109`; `stop_all` server-blocked
while in a live sale (No Silent Locks) `:86-96`; idempotent `recordOptOutOnce()` `:116-132`; token regex
`^[A-Za-z0-9]{48}$` off `opt_out_token` `:134-150`. **No TTL** by design — the 48-char token IS the
credential; CSRF exemption `bootstrap/app.php:48-69` (`outreach/opt-out/*`, `opt-in/*`, `unsubscribe/*`),
419 handler `:86-91` (this is the documented CSRF-419 fix — webview cookie-drop case).

**Consent templates (AT-47):** consent-*request message* templates (WhatsApp + email), seeded
`database/seeders/HfcConsentTemplatesSeeder.php:15-37` as `SellerOutreachTemplate` rows, one default per channel.

---

## 5. WHISTLEBLOWER MODULE

**Spec `.ai/specs/whistleblower-compliance-spec.md`:** agent-initiated, evidence-driven, approval-gated
PPRA breach reporting under PPA 22/2019 `:16-20`; one complaint = one property, subjects in
`whistleblow_complaint_subjects` `:84`; status `draft → pending_approval → approved → sent →
acknowledged_by_ppra → closed` `:111-133`; PPRA address `complaints@theppra.org.za` (per-agency
configurable, future) `:320`. **PPRA register scraping explicitly out of scope** `:73`.

**Controller `WhistleblowController.php`:** index scoping (approvers all, agents own) `:26-44`; `store()`
with idempotency token + tiered validation + evidence upload (`local` disk), reference `HFC-WB-{id}`
`:79-177`; `approve()` "submitted to PPRA" `:197-207`; `lawyerReviewPack()` ZIP `:246-252`; approver gate
via `agency.whistleblow_approver_user_ids` fallback admin/BM/super_admin `:257-271`. **Service
`WhistleblowComplaintService::sendToPpra()`** demo flag `config('compliance.whistleblow.ppra_live_send')`
`:260`, recipients default `complaints@theppra.org.za` `:281-283`.

**Anonymity:** **agent-attributed, NOT anonymous** — `reported_by_user_id` recorded + used for scoping
(`:29`); seller-consent checkbox removed (HFC reports what it was told, PPRA investigates independently,
spec `:84`). No anonymous public submission endpoint.

---

## 6. WHO READS/WRITES CONSENT STATE

- **Contact (canonical):** `communicationStatus()` reads `Contact.php:583-601`; `canSendVia()` `:487-510`;
  writes via `setConsent/recordConsent/revokeConsent` `:412-474`, `recordOptIn` `:546-553`.
- **Outreach send gate:** `MarketingConsentService::isContactSuppressed()` `:216-227` — pre-send guard
  (`SellerOutreachComposerService`, `SellerOutreachOptOutService.php:27-42`). Convergence:
  `Events/SellerOutreach/OptOutRecorded` → `Listeners/…/RecordOptOutOnContact.php:28-46` →
  `optOutContact()` (both agent-marked and public-link paths route through this one listener).
- **Public links:** `PublicOptOutController`/`PublicOptInController` write via the service.
- **e-Sign:** `Docuperfect/ESignConsentLog.php` (separate ECT-Act consent log) + FICA gate on `SignatureRequest`.
- **Admin suppressions:** `Admin/MarketingSuppressionController` — `index` (perm
  `marketing_suppressions.view`) `:257-259`, `lift` (= opt-in, perm `…manage`) `:260-262`. Model
  `MarketingSuppression.php` (`TYPE_EMAIL/PHONE` `:21-22`, sources self-service/unsubscribe/agent `:24-26`).

---

## 7. PPRA ROUTING / INFORMATION OFFICER

- **Information Officer (POPIA s55):** `app/Models/Compliance/InformationOfficerAppointment.php` —
  `ROLE_PRIMARY`/`ROLE_DEPUTY` `:24-25`; one active primary per agency enforced in `booted()` (auto-ends
  prior) `:51-86`; `currentPrimary()` `:139`. Agency accessor `currentInformationOfficer()`
  (`Agency.php:529-532`). Admin `InformationOfficerAppointmentsController` (perm `manage_information_officer`).
  **"Elize" as IO is configured at runtime via these appointment rows — no hardcoded reference in code.**
- **Compliance Officer / MLRO (FICA):** `FicaOfficerAppointment.php` (`ROLE_PRIMARY` `:18`, `ROLE_MLRO`);
  Agency `complianceOfficer()` `:514-519`; register `compliance.officer.index` `:1559`.
- **PPRA routing (whistleblower):** `WhistleblowComplaintService::sendToPpra()` (recipients `:281-283`,
  demo flag `:260`).

---

## 8. AGENCY SETTINGS / CONFIG

| Setting | Default | Where | Enforced by |
|---------|---------|-------|-------------|
| `contact_retention_years` | 5 | `AgencyContactSettings.php:31,85` | `PurgeContactRetention` cron `:31` |
| `consent_retention_years` | 5 | `:32,86` | `PurgeContactRetention:67` |
| `access_log_retention_years` | 5 | `:33,87` | `PurgeContactRetention:85` |
| Compliance document provisions | — | `AgencyComplianceSettingsController` (perm `manage_agency_compliance`) | supersede-on-reupload `:66-71`; `AgencyComplianceProvision` types `:21,31` |
| `whistleblow_approver_user_ids` | admin/BM/super_admin fallback | `agency` | `WhistleblowController:257-271` |
| FICA/FFC dashboard reminders | toggle | `AgencyDashboardSetting.php:19,35` | Command Centre |
| `config('compliance.whistleblow.ppra_live_send')` | demo (false) | config | `sendToPpra:260` |

Retention edited via `ContactGovernanceController:51-69` (validation `min:5|max:99`). The
`compliance.agency-settings` screen is **document-only**; retention years live on the separate Contact
Governance surface.

---

## 9. KNOWN FRAGILITIES + FUTURE

1. **CPA Amendment Regs 2026 / NCC registry — NOT built.** No NCC "do-not-contact registry" integration
   exists (grep confirms). The opt-out engine is **agency-scoped suppression only** (`marketing_suppressions`
   keyed `agency_id` + identifier, `MarketingConsentService.php:255-258`) — there is **no cross-agency /
   national registry consultation** before sending. The forward framing lives in the Policy Acknowledgement
   Framework (AT-29, "POPIA/CPA/NCC" generic versioned sign-off, `claude_policy_acknowledgement_spec.md`);
   the actual NCC registry check is unimplemented future work.
2. **`transaction_only` depends on `TransactionStateService`.** `Contact::communicationStatus()` calls
   `isInLiveTransaction()` `:590-593`; the service derives "live" from `deals_v2` rows in an
   agency-configurable status set (`config('corex-outreach.live_deal_statuses', ['active'])` `:217`) +
   live-mandate/advertised properties. **Fragility:** if deal statuses or mandate-expiry data drift, a
   contact can silently fall out of `transaction_only` (transactional comms then blocked) or vice-versa.
   Computed live per render (one extra query, only when opted-out `:580-581`).
3. **Opt-out token no-TTL + CSRF exemption.** Tokens never expire; mitigations = 48-char entropy + strict
   regex (`PublicOptOutController.php:138`) + throttling (`routes/web.php:36,40`). The CSRF-419 fix is the
   documented reason for the exemption (`bootstrap/app.php:48-69`).
4. **Retention enforcement is cron-only** (`PurgeContactRetention`) — relies on the scheduler running; no
   in-app trigger. Backup policy not codified (`compliance-audit-2026-06.md:283`).
5. **PPRA vs FFC mislabel.** `Agency.ffc_no` is rendered as "PPRA" (`corex-document.blade.php:69`); there is
   no structured agency PPRA registration number — `Agency.ppra_number` is fillable (`Agency.php:58`) but
   the audit flags it as effectively the FFC and recommends a true separate column
   (`popia-columns-investigation-2026-05-25.md:113-115`). FFC ≠ PPRA registration.
6. **Whistleblower is not anonymous** (§5) — agent-attributed by design; no anonymous intake path.

---

## Key file:line index
- `app/Models/FicaSubmission.php` — `:51,154,191-204`; `app/Http/Controllers/Compliance/FicaController.php:278-347`, `FicaPublicController.php:16-192`.
- `app/Services/SellerOutreach/MarketingConsentService.php` — `:62-117,165-203,216-274`.
- `app/Models/Contact.php` — `:561-633` comm status; `ContactConsentRecord.php:14-51`; `MarketingSuppression.php:21-26`.
- `app/Http/Controllers/SellerOutreach/PublicOptOutController.php:54-150`; `bootstrap/app.php:48-91`.
- `app/Http/Controllers/Compliance/WhistleblowController.php:26-271`; `WhistleblowComplaintService.php:251-283`.
- `app/Models/Compliance/InformationOfficerAppointment.php:24-146`; `FicaOfficerAppointment.php`; `Agency.php:514-541`.
- `app/Console/Commands/PurgeContactRetention.php:26-85`; `AgencyContactSettings.php:31-87`.
- Audits: `.ai/audits/compliance-audit-2026-06.md`, `.ai/audits/popia-columns-investigation-2026-05-25.md`.
