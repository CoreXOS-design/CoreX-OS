# Atlas — Compliance (FICA · POPIA/CPA consent · Whistleblower)

> **Status: DONE** · Last verified: 2026-07-14
> Pillars: **Contact** (consent) × **Agent/User** (FFC, screening) × **Deal/Property** (FICA gating).
> Regulator: **PPRA** (Property Practitioners Regulatory Authority) — never EAAB. Companion specs:
> `.ai/specs/compliance.md`, `.ai/specs/contact-consent.md`, `.ai/specs/contact-communication-status.md`,
> `.ai/specs/whistleblower-compliance-spec.md`. Cited audits: `compliance-audit-2026-06.md`,
> `popia-columns-investigation-2026-05-25.md`. Cited tickets: AT-45→50 (consent), AT-47 (templates),
> **AT-236 (FICA two-station review + Refer-to-CO)**.

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
| FICA (internal — two-station review + Refer-to-CO, AT-236) | `compliance.fica.*` `:1961-1980+` (incl. `refer-to-co` `:1973`, `return-to-referrer` `:1974`) | `FicaController.php` + `Services/Compliance/FicaReferralService.php` |
| FICA (public token form) | `fica.form/submit/confirmation` `:4074+` | `FicaPublicController.php` |
| FICA referral settings | `corex.settings.fica-referral.save` `:2458` | `FicaOfficerAppointmentsController::saveReferralSettings` |
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

## 3. FICA — two-station review + Refer-to-CO (AT-236)

**Model `app/Models/FicaSubmission.php`:** token + `token_expires_at` `:21-23`; tri-actor stamps (agent
`:36-39`, compliance officer `:42-45`, wet-ink `:47-49`); **FICA validity `fica_expires_at` `:51`**;
`isFicaExpired()` `:154`, `scopeExpiringSoon($days=60)` `:159`. Status enum **extended AT-236** (migration
`2026_08_03_000002`): `draft → submitted → under_review → agent_approved → {approved | corrections_requested
| rejected | cancelled}` **plus the new `referred_to_co`** state. Referral provenance columns `referred_by` /
`referred_at` / `referral_note` added in the same migration.

**Two review stations (Johan's model — `FicaController::index:59-107`).** After agent approval a pack no
longer flows to a single "compliance officer review" step; it enters one of two stations:

| Station | Queue tab | Status worked | Who acts |
|---------|-----------|---------------|----------|
| **RO Approvals** (Reviewing-Officer pool) | `ro_queue` | `agent_approved` | ANY active FICA officer — `isComplianceOfficer()` (primary CO *or* MLRO); shared pool, oldest-first by `agent_verified_at` `:78-83` |
| **CO Approvals Needed** (escalation) | `co_queue` | `referred_to_co` | the **primary CO only** — `isPrimaryComplianceOfficer()` (Elize) `:65-66,80-83`; `coQueueStats` = count + oldest_days `:98-106` |

`roQueueCount` populates only for officers (`isCO`); `coQueueCount` only for the primary CO `:64-67`.

**Flow / handoff:**
1. Public token form (no auth) `FicaPublicController::form` `:16` / `uploadDocument` `:198` / `submit` → `submitted` `:172-192`. Wet-ink intake `createWetInk`/`storeWetInk` `:1565-1566`.
2. Agent review `FicaController::agentApprove` → `agent_approved` `:250-281` (lands in **RO Approvals**; stamps risk_rating / verification_method / agent_verified_*).
3. At the RO station an officer takes ONE of three actions from the compliance-review screen (`complianceReview:287-297`):
   - **Approve** `complianceApprove` → `approved` **+ `fica_expires_at = now()->addMonths(24)`** `:303-386`, files docs to the contact, fires `Fica\FicaApproved` domain event.
   - **Reject / return** `complianceReject` → `rejected` or `corrections_requested` `:388+`.
   - **Refer to CO** `referToCo` → `referred_to_co` (Station-2 handoff, below).

**Self-approval separation (the reason two stations exist — `complianceApprove:311-334`):** the same person
may NOT approve their own FICA (they are `requested_by` or did the stage-1 `agent_verified_by`) **unless they
are the primary CO** — secondaries never self-approve; only the primary may (`isSelfApproval` `:971-978`). A
blocked attempt is audit-logged as `self_approval_blocked` and the officer is told to ask another officer or
use **Refer to CO**.

**Refer-to-CO referral mechanism — `app/Services/Compliance/FicaReferralService.php` (single seat of the transition):**
- **Refer** `referToCo` (route `compliance.fica.refer-to-co` `:1973`): a reviewer escalates with a **MANDATORY
  reason** (`referral_note` min 3, max 2000 `:462`). Referable only from `REFERABLE_FROM = [submitted,
  under_review, agent_approved, corrections_requested]` `:34`. `FicaReferralService::refer()` `:60-104` sets
  status → `referred_to_co`, stamps `referred_by/referred_at/referral_note`, writes the audit row, and notifies
  the recipient CO through the **AT-235 gateway** (`NotificationDispatcher->send('fica.referred_to_co', …,
  FicaReferredToCoNotification)`). Notification failure is caught — it never blocks the legally-recorded referral.
- **Recipient resolution** `resolveRecipient()` `:46-58`: the agency's configured
  `fica_referral_recipient_user_id` **if set AND still an active officer**, else
  `FicaOfficerAppointment::currentPrimary()`. **Can return null** (see §9-7).
- **Return path** `returnToReferrer` (route `compliance.fica.return-to-referrer` `:1974`): the CO sends a
  referred pack BACK to its referrer with comments — status → `corrections_requested`, `co_notes` = comments,
  `referred_by` retained as audit (distinct from return-to-agent). Only from `referred_to_co` `:475-488`;
  service `:107-127`; audit action `co_returned_to_referrer`.

**Immutable audit ledger — `fica_status_history` / `app/Models/FicaStatusHistory.php`** (migration
`2026_08_03_000001`): **append-only** (no `updated_at`, no soft-delete), one row per hop via `::record()`,
capturing the actor's `actor_tier` at action time (`primary_compliance_officer` / `mlro` / `admin` / `agent`
/ `system` `:tierFor`) and stamping `agency_id` from the submission (AT-203-safe in queue/console). Records
transitions AND non-transition events: `agent_approved`, `co_approved`, `self_approval_blocked`,
`referred_to_co`, `co_returned_to_referrer`.

**Agency-configurable referral settings** (migration `2026_08_03_000004`): `agencies.fica_referral_enabled`
(default **true** — is Refer-to-CO offered) and `agencies.fica_referral_recipient_user_id` (null = primary CO).
Saved via `FicaOfficerAppointmentsController::saveReferralSettings` (route `corex.settings.fica-referral.save`
`:2458-2459`, perm `manage_compliance_officer`, screen `resources/views/corex/settings.blade.php`).
`FicaReferralService::referralEnabled()` `:38-44` reads defensively — defaults ON before the column exists.

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
7. **FICA referral can orphan when no primary CO is appointed (NEW, real).** `FicaReferralService::resolveRecipient()`
   returns **null** when no active configured recipient exists AND `FicaOfficerAppointment::currentPrimary()` is
   empty (`FicaReferralService.php:46-58`). `refer()` still transitions the pack to `referred_to_co` and writes the
   immutable audit row, but the `if ($recipient)` guard `:81` means **no notification is sent**. At the escalation
   station the `co_queue` tab and `coQueueCount` populate **only for `isPrimaryComplianceOfficer`**
   (`FicaController:66,80-83,99`), so with no primary CO appointed the referred pack is invisible in the CO station
   and no one is alerted — a referral with no owner. Mitigation only: agencies are expected to have a primary CO
   (Elize) and defaults are ON / primary CO. No in-app guard blocks a referral when the recipient resolves to null.
8. **CO-station ownership is queue-only, not action-enforced (NEW, real).** "CO Approvals Needed" is framed in the
   code as the primary CO's station and only the primary CO *sees* the `co_queue` list, but the actions on a
   `referred_to_co` pack — `complianceReview:290`, `complianceApprove:307`, `returnToReferrer:478` — gate on
   `isComplianceOfficer()` (**any** active officer), not `isPrimaryComplianceOfficer()`. A secondary officer who is
   not the referrer can therefore open a referred pack by URL and approve it (the self-approval guard only blocks
   when they are the requester/agent-reviewer AND not primary). Escalation *visibility* is primary-only; escalation
   *authority* is any-officer. May be intentional (any officer can help clear the CO queue), but the enforcement is
   weaker than the "primary CO only" framing.

**RESOLVED (AT-236):** the prior single downstream compliance-officer station (agent → one CO approve) is
superseded by the **two-station RO/CO model + Refer-to-CO escalation** documented in §3. The self-approval hole
(a reviewer approving their own FICA work) is now server-gated with an audit-logged block, and every
approval-workflow hop — including blocked and referral hops — is recorded append-only in `fica_status_history`.

---

## Key file:line index
- `app/Models/FicaSubmission.php` — `:51,154,191-204` (+ AT-236 `referred_to_co` status, `referred_by/at/note`); `FicaPublicController.php:16-192`.
- **FICA two-station + Refer-to-CO (AT-236):** `app/Http/Controllers/Compliance/FicaController.php` — index/two-station `:33-107`, `agentApprove:250-281`, `complianceReview:287-297`, `complianceApprove:303-386` (self-approval guard `:311-334`), `referToCo:450-468`, `returnToReferrer:475-488`, `isSelfApproval:971-978`. Service `app/Services/Compliance/FicaReferralService.php` (`REFERABLE_FROM:34`, `referralEnabled:38-44`, `resolveRecipient:46-58`, `refer:60-104`, `returnToReferrer:107-127`). Audit `app/Models/FicaStatusHistory.php` (append-only ledger, `::record`, `tierFor`). Notification `app/Notifications/FicaReferredToCoNotification.php`.
- **FICA migrations (AT-236):** `database/migrations/2026_08_03_000001_create_fica_status_history_table.php`, `…_000002_add_referred_to_co_to_fica_submissions.php`, `…_000003_register_fica_referred_to_co_notification.php`, `…_000004_add_fica_referral_settings_to_agencies.php`. Settings save `FicaOfficerAppointmentsController::saveReferralSettings` (route `:2458`), view `resources/views/corex/settings.blade.php`. Officer roles `app/Models/Compliance/FicaOfficerAppointment.php` (`ROLE_PRIMARY:18`, `ROLE_MLRO:19`, `currentPrimary:130`); user checks `app/Models/User.php:485-513`.
- `app/Services/SellerOutreach/MarketingConsentService.php` — `:62-117,165-203,216-274`.
- `app/Models/Contact.php` — `:561-633` comm status; `ContactConsentRecord.php:14-51`; `MarketingSuppression.php:21-26`.
- `app/Http/Controllers/SellerOutreach/PublicOptOutController.php:54-150`; `bootstrap/app.php:48-91`.
- `app/Http/Controllers/Compliance/WhistleblowController.php:26-271`; `WhistleblowComplaintService.php:251-283`.
- `app/Models/Compliance/InformationOfficerAppointment.php:24-146`; `FicaOfficerAppointment.php`; `Agency.php:514-541`.
- `app/Console/Commands/PurgeContactRetention.php:26-85`; `AgencyContactSettings.php:31-87`.
- Audits: `.ai/audits/compliance-audit-2026-06.md`, `.ai/audits/popia-columns-investigation-2026-05-25.md`.
