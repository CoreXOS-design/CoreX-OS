# CoreX Compliance Audit — Pre-Launch Review
Date: 2026-06-04
Auditor: Claude (read-only investigation)
Scope: All compliance-relevant code paths in HFC2402 branch as of `a23e295`

---

## Executive Summary

| Criticality | Count |
|---|---|
| 🔴 Launch Blockers | **3** |
| 🟡 High Priority | **11** |
| 🟢 Nice to Have | **7** |
| ⚪ Out of Scope | **5** |
| **Total findings** | **26** |

CoreX has a **substantial compliance backbone already in place** — much of what would normally take a launch-readiness phase to build is already shipped. The FICA module is comprehensive, the RMCP is fully seeded with 28+5 sections per-agency, document e-signing has cryptographic hash + immutable audit log, and Phase 9a added POPIA-aligned 90-day view retention with granular per-channel consent records.

The blockers cluster around **public-facing POPIA hygiene** (privacy policy not linked from public pages, no DSAR workflow, Information Officer not captured) — i.e. things visible to a regulator visiting a customer-shared URL. The high-priority items cluster around **proactive compliance enforcement** (FFC expiry alerts, cooling-off enforcement, sole-mandate gates before portal syndication) — i.e. things that exist as records but don't drive system behaviour yet.

Total remediation effort estimated at **6 S-fixes, 7 M-fixes, 4 L-fixes, 2 XL phases**. The XL items (first-class Mandate model + first-class OTP model) would normally precede launch but a pragmatic path exists by formalising what already lives in the signature-request + property layer.

---

## 🔴 Mission-Critical Findings (Must Resolve Before Launch)

### 🔴 1. No privacy policy reachable from public-facing pages [POPIA s18]
**Status:** ✗ Missing
**Evidence:** Searched all `resources/views/presentations/public/*.blade.php` and route list — no `/privacy`, `/privacy-policy`, or footer-link to a privacy document. POPIA s18 requires "the purpose of collection" + "consequences of failure to provide" be communicated at point of collection. The teaser, refresh, and full presentation views capture seller PII (name, email, phone) without a discoverable privacy notice.
**Risk:** Information Regulator complaint exposure. Regulator-led audit could halt customer-facing operations.
**Fix:** S — write a privacy policy page (legal review required), link from teaser footer + refresh form + show view footer.

### 🔴 2. No Information Officer registration on agency [POPIA s55]
**Status:** ✗ Missing
**Evidence:** `app/Models/Agency.php` has `ffc_no`, `fic_no`, `whistleblow_tier_recipients`, but no `information_officer_user_id` or POPIA Information Officer field. POPIA s55 mandates every responsible party register an IO with the Regulator and disclose their identity.
**Risk:** s55 is strict-liability — failure to designate is itself an offence.
**Fix:** S — add `information_officer_user_id` to agencies + capture in Settings → Compliance + display in privacy policy.

### 🔴 3. PPRA agency number not captured as a structured field
**Status:** ⚠ Partial (agency has `ffc_no` but no `ppra_number` / `ppra_registration_number`)
**Evidence:** Property Practitioners Act regulations require the agency's PPRA registration number on every consumer-facing marketing document. Agent footer (`resources/views/emails/signatures/partials/agent-footer.blade.php` line 32-33) displays agent's individual FFC but no agency PPRA reference. The mandate templates (CDS template-111 "Exclusive Authority to Sell") would need agency PPRA visible — currently relies on agency name/address text only.
**Risk:** Each piece of marketing without the agency PPRA reference is a separate contravention. Cumulative risk on launch day = volume × penalty.
**Fix:** S — add `ppra_number` + `ppra_registered_at` to agencies + render in document footers + agent email signature.

---

## 🟡 High Priority (Should Resolve Before Live)

### 🟡 4. FFC expiry automated alerts not wired
**Status:** ⚠ Partial — schema exists, manual dashboard surfaces status, no automated emails
**Evidence:** `User.ffc_expiry_date` populated (line 62) + `User.ppra_status` + `User.ppra_last_verified_at` columns present. `AgentComplianceController::calculateFfcStatus()` (lines 104-139) flags amber at ≤60 days, red when expired — but ONLY when the page is opened. No scheduled job in `routes/console.php`; no FFC reminder notification class in `app/Notifications/`.
**Risk:** Agent transacts on expired FFC → contravention + commission clawback exposure.
**Fix:** M — create `FfcExpiryReminderJob` (daily), `FfcExpiringNotification` (queued, mail+database), schedule 60-day / 30-day / 7-day reminders to agent + agency admin.

### 🟡 5. No DSAR (data subject access request) workflow
**Status:** ✗ Missing — POPIA s23 right of access
**Evidence:** Grep for `DSAR`, `data_subject_request`, `export_my_data` returns nothing relevant. A seller asking "give me everything you hold on me" today requires manual database extraction with no SLA.
**Risk:** POPIA s23 requires response within "reasonable time"; Regulator interprets as 30 days. Manual extraction at scale doesn't meet that.
**Fix:** L — add a contact-export endpoint that bundles: contact row + consent records + access log + presentation deliveries + teaser leads + FICA submissions + linked properties — into a single JSON or PDF export. Permission-gated (admin or contact's verified email triggering the export).

### 🟡 6. No data-breach notification process documented or wired
**Status:** ✗ Missing — POPIA s22
**Evidence:** No "data_breach" / "incident_response" file in codebase or `.ai/specs/`. s22 requires breach notification to Regulator + affected data subjects "as soon as reasonably possible".
**Risk:** A breach without a plan = much longer time-to-notification = compounding penalty.
**Fix:** M — write a breach response runbook (`.ai/runbooks/data-breach-response.md`), capture Information Officer contact, build a `breach_incidents` log table for record-keeping, integrate into Compliance Officer dashboard.

### 🟡 7. Cooling-off period (CPA s17 / PPA mandate) not enforced in code
**Status:** ⚠ Partial — text exists in mandate templates (CDS template-111 "Exclusive Authority to Sell" has 5-working-day cooling-off clauses), but no code enforces it
**Evidence:** No `mandate_cooling_off_ends_at` field on Property or any mandate-related model. `SignatureRequest` lifecycle has no cooling-off pause state. Sellers signing a sole mandate today can have the property listed live the same hour.
**Risk:** Seller rescinds within 5 days → mandate listed live → portal embarrassment + potential complaint + claim that listing damaged sale value.
**Fix:** M — add `cooling_off_ends_at` to signature_requests OR a Mandate model when built; gate "go live" actions on `cooling_off_ends_at < now()`; show countdown to agent on the listing show page.

### 🟡 8. No sole-mandate verification gate before portal syndication
**Status:** ✗ Missing
**Evidence:** `Property.mandate_type` column exists but no consumer code checks it. `PrivatePropertySyndicationService` enables P24/PP push without verifying mandate type. P24's terms require sole mandate before syndication; open-mandate listings on P24 are a portal-terms breach.
**Risk:** Portal terms breach → portal pulls all HFC listings → existential operational impact.
**Fix:** S — add a check in `PrivatePropertySyndicationService` (and the P24 sync equivalent) that requires `mandate_type === 'sole'` before allowing syndication enable. Surface clearly on the property page when un-syndicatable.

### 🟡 9. No FICA gate enforced on listing publication (only on document signing)
**Status:** ⚠ Partial — FICA gate works for e-sign documents (`signature_requests.fica_required`) but not for listing-live decisions
**Evidence:** `Property.compliance_snapshot_at` + `compliance_snapshot_data` columns exist (Phase 1 design intent), but no code path enforces "seller FICA approved before listing goes live". `SignatureRequest::fica_required` correctly blocks signing until status='approved' or 'agent_approved' (per the fica-gate.blade.php).
**Risk:** Listing accepts an OTP from an un-FICA-cleared buyer → deal stuck at conveyancer + regulatory exposure on the agency.
**Fix:** M — extend the existing `compliance_snapshot_at` logic: on "publish listing" action, snapshot the seller's current FICA status; refuse publish if !approved. Same gate on OTP-send.

### 🟡 10. No Mandate first-class model — schema-only on Property
**Status:** ⚠ Implicit — `Property.mandate_type` + `Property.expiry_date` + a CDS template-111 ("Exclusive Authority to Sell") signed via SignatureRequest
**Evidence:** No `app/Models/Mandate.php`; no `mandates` table. Mandate type is captured as a single string column on the property; mandate identity, signed document, expiry, cancellation reason are scattered across Property, SignatureRequest, and Docuperfect\Document.
**Risk:** Mandate lifecycle queries (expiring this week / cancelled mandates / re-mandate history) require multi-table joins that don't scale to BM dashboards. Mandate cancellations are not logged with reason.
**Fix:** XL — design a first-class Mandate model that wraps: signed-document reference, mandate_type, signed_at, cooling_off_ends_at, expires_at, cancelled_at, cancelled_reason, FFC-warranty clause snapshot, supersedes_mandate_id. Phase 9c candidate.

### 🟡 11. No first-class Offer to Purchase (OTP) model
**Status:** ✗ Missing
**Evidence:** No `app/Models/OfferToPurchase.php`; no `offers_to_purchase` table; no OTP web-template among 30+ DocuPerfect templates. OTPs today exist only as agent-uploaded PDFs in `deal_documents`.
**Risk:** OTP-specific compliance (suspensive conditions, voetstoots clause, COC dates, FICA on both parties) can't be enforced or queried.
**Fix:** XL — design OTP model with structured fields: buyer_party_id, seller_party_id, offer_price, suspensive_conditions[], coc_required[] (electrical/plumbing/beetle/gas/entomologist), voetstoots_acknowledged, fica_buyer_status, fica_seller_status. Phase 9c or post-launch candidate.

### 🟡 12. Listing publication has no compliance approval gate
**Status:** ✗ Missing — Elize's approval workflow not enforced
**Evidence:** `Property.compliance_snapshot_at` + `compliance_snapshot_data` + `compliance_evidence_flags` columns exist (Phase 1 design intent) but no controller writes them on a "compliance approved" action. No `compliance_approved_by` / `compliance_approved_at` columns. No "approve listing for go-live" route.
**Risk:** Listings go live without Elize's review — defeats the whole compliance-officer role.
**Fix:** M — design a "publish requires compliance approval" workflow (per the Property show page's Marketing tab). Add `compliance_approved_by_user_id` + `compliance_approved_at` columns. Gate go-live actions.

### 🟡 13. No staging-data sanitisation script
**Status:** ✗ Missing
**Evidence:** `CleanSlateForTesting.php` and `DemoCleanup.php` exist but are test-data and demo-data oriented — not staging-data scrubbing. Phase 9a deploy checklist recommended a sanitisation step before copying production data to staging but no command exists.
**Risk:** Staging environment leaks real seller PII to anyone with staging access (devs, demo-watchers, prospective buyers).
**Fix:** M — `php artisan staging:sanitise` command that scrubs: contacts.email → staging-{id}@example.test, contacts.phone → +27000000000, users.email except super_admins, fica_submissions.form_data → SCRUBBED, contact_notes → REDACTED, presentation_teaser_leads.email/phone → similarly scrubbed.

### 🟡 14. PPRA-specific event types not yet emitted in agent_activity_events
**Status:** ⚠ Partial — infrastructure ready (Phase 8's AbstractDomainEvent + LogAgentActivity listener writes to immutable `agent_activity_events`), but no PPRA-specific event classes yet
**Evidence:** 45+ domain event classes exist (presentation.outcome.recorded, claim.created, etc) but no `mandate.signed`, `mandate.cancelled`, `ffc.uploaded`, `ffc.verified`, `compliance.approved`, `compliance.rejected`, `ppra.status_changed`, `agent.suspended`. A PPRA audit would not be able to reconstruct the regulatory timeline of a property/agent.
**Risk:** PPRA-led audit cannot be answered with `agent_activity_events` alone.
**Fix:** M — add 8-10 compliance event classes (`MandateSigned`, `MandateCancelled`, `FfcUploaded`, `FfcVerified`, `ComplianceApproved`, `ComplianceRejected`, `AgentSuspended`, `AgentReinstated`), wire them from existing controllers/services.

---

## 🟢 Nice to Have (Improves Compliance Posture)

### 🟢 15. PPRA audit-dump command
**Status:** ✗ Missing
**Evidence:** No `php artisan compliance:audit-dump` or equivalent.
**Fix:** M — single-command export bundling: all agents + FFCs + PPRA statuses + all mandates + all signed documents + audit logs filtered by date range. Useful but not required pre-launch.

### 🟢 16. Monthly compliance report
**Status:** ✗ Missing
**Evidence:** No `compliance_report` table, no scheduled report job.
**Fix:** M — `MonthlyComplianceReportJob` (1st of month, sends PDF summary to compliance officer per agency).

### 🟢 17. STR (Suspicious Transaction Report) intake
**Status:** ✗ Missing — policy documented in RMCP Section 19, no workflow code
**Evidence:** RMCP Section 24 (line 432, HfcRmcpMasterSeeder.php) points to `goaml.fic.gov.za` — external workflow. No internal `suspicious_transactions` table for record-keeping.
**Fix:** L — STR intake form + audit table + record-of-report-filed + monthly reporting.

### 🟢 18. CPD points tracking
**Status:** ⚠ LMS exists, CPD point ledger does not
**Evidence:** Training tables exist (training_courses, training_completions). No `cpd_points`, `cpd_hours_required` columns on user/training_course.
**Fix:** M — extend training_courses with `cpd_points` award + add `user_cpd_balance` rollup view + alert at year-end if below PPRA minimum.

### 🟢 19. Mandate cancellation workflow not first-class
**Status:** ✗ Missing — covered by 🟡10 Mandate model if it's built
**Fix:** XL (rolled into Mandate model phase).

### 🟢 20. Information Regulator-aligned breach incident table
**Status:** ✗ Missing — covered by 🟡6 if breach runbook is built
**Fix:** M (rolled into the breach response phase).

### 🟢 21. Photo-versioning audit (can't silently swap a photo)
**Status:** ❓ Unknown — `Property.images_json` / `gallery_images_json` exist but no version history table
**Evidence:** No `property_image_versions` table found. Listing photos can be swapped without audit.
**Fix:** L — versioned image table (immutable, append-only) or rely on existing storage backup policy.

---

## ⚪ Out of Scope

### ⚪ 22. PPRA practitioner verification API integration
**Status:** ⚪ OOS — PPRA doesn't expose a public API for verification
**Notes:** Verification is manual via Agency Compliance Officer + PPRA portal lookup. CoreX captures the result via `ppra_status` enum + `ppra_last_verified_at`.

### ⚪ 23. Trust accounting integration (Sage)
**Status:** ⚪ OOS — Trust accounting is HFC's Sage instance, not CoreX. CoreX tracks deposit references only.

### ⚪ 24. SARS IT3(a) integration
**Status:** ⚪ OOS — handled by HFC payroll, not CoreX core.

### ⚪ 25. EAAB legacy certificate handling
**Status:** ⚪ OOS — EAAB was succeeded by PPRA in 2022. Any practitioner still showing EAAB certificate is non-compliant per PPRA's transition rules. CoreX should require PPRA — no need to retain EAAB compatibility.

### ⚪ 26. Information Regulator registration (POPIA)
**Status:** ⚪ OOS for CoreX code — this is an operational/legal action HFC takes once. Code does not automate this.

---

## Detailed Findings by Domain

### A. Regulatory Framework Coverage

#### A1 — PPRA (Property Practitioners Act 22 of 2019)
- ✓ `User.ffc_number`, `User.ffc_expiry_date`, `User.ffc_certificate_path`, `User.ppra_status`, `User.ppra_last_verified_at` all schema-present
- ✓ `Agency.ffc_no` present
- ✗ `Agency.ppra_number` missing → 🔴 #3
- ✓ Candidate Practitioner framework via `User.designation` + `CandidatePractitionerService::canAuthorise()` (full implementation)
- ✓ `User.supervised_by` FK present; ⚠ field defined but not actively used for routing approvals — currently uses shared-eligible-queue model
- ✗ Mandate type capture is schema-only — value not validated against enum, not surfaced in mandate-cancel workflow → 🟡 #10
- ⚠ Marketing material disclosure rule: agent email footer renders FFC but no agency PPRA reference → 🔴 #3
- ✓ Practitioner activity log: `agent_activity_events` is the canonical immutable log; 45+ event classes wire in via `LogAgentActivity` listener
- ✗ No PPRA-specific event types yet → 🟡 #14

#### A2 — POPIA (Protection of Personal Information Act)
- ✗ Privacy policy not reachable from public pages → 🔴 #1
- ✓ Consent capture: `contact_consent_records` (granular per channel — email/SMS/WhatsApp/call) + `Contact::recordConsent()` / `revokeConsent()` (lines 231, 244)
- ✓ Opt-out tracked separately (`Contact.opt_out_email`/`_sms`/`_whatsapp`/`_call` + the consent_records revoked_at timestamps)
- ✗ DSAR workflow → 🟡 #5
- ⚠ Right to erasure: SoftDeletes everywhere (good), but `destroyAll()` is the only hard-delete path and it's destructive bulk. No per-contact erasure with scrub.
- ✗ Information Officer registration → 🔴 #2
- ✗ Data breach notification process → 🟡 #6
- ✓ Retention policy: `PurgeContactRetention` command (per-agency `contact_retention_years` default 5) + Phase 9a `PurgeOldSnapshotViewsJob` (90 days)
- ✓ IP masking confirmed via `PublicPresentationController::ipForStorage()` honouring `agency.snapshot_link_ip_masking` (Phase 4 + Phase 9a verified)
- ⚪ Cross-border data transfer — not currently relevant (Hetzner SA + Anthropic API — Anthropic is cross-border but covered by their DPA; should be documented in privacy policy)

#### A3 — FICA (Financial Intelligence Centre Act)
- ✓ FICA controllers (`FicaController`, `FicaPublicController`)
- ✓ `FicaSubmission` model + public token-based form at `/fica/{token}`
- ✓ Entity types supported: natural / company / trust / partnership
- ✓ FICA gate before signing: `signature_requests.fica_required` + `signature_requests.fica_submission_id` (migration 2026_03_26_300000)
- ✓ FICA officer designation: `FicaOfficerAppointment` model with `ROLE_PRIMARY` (one per agency, auto-ends predecessor) + `ROLE_MLRO`. Routes at `/corex/settings/fica-officers/*`
- ✓ RMCP: 28 sections + 5 schedules per-agency. Models: `RmcpVersion`, `RmcpSection`, `RmcpVariable`, `RmcpAcknowledgement`, `RmcpSectionAcknowledgement`. Acknowledgement workflow at `/compliance/rmcp-ack/{id}/step`. Master seeder: `HfcRmcpMasterSeeder.php` initialises Elize Reichel as primary CO for hfc-coastal.
- ✓ FICA training: `training_courses` includes "FICA Compliance Training" + "RMCP Overview" (both is_required + is_required_for_activation)
- ⚠ TFS (Targeted Financial Sanctions) screening: documented in RMCP Section 17 pointing to tfs.fic.gov.za — no automated screening integration, manual workflow
- ✗ STR (Suspicious Transaction Reporting) workflow → 🟢 #17
- ✓ 5-year record retention: `PurgeContactRetention` command soft-purges deleted contacts after 5 years (configurable per agency)

#### A4 — EAAB legacy → covered by ⚪ #25
#### A5 — Specific PPA sections → covered within other findings (mandate clauses in CDS templates, FFC tracking via User fields)
#### A6 — CPA + Plain Language
- ⚠ Mandate templates (CDS template-111 "Exclusive Authority to Sell" et al) include cooling-off clauses in text — but no system enforcement → 🟡 #7
- ⚪ Plain language: subjective compliance, not auditable from code
#### A7 — Companies Act / B-BBEE → ⚪ outside CoreX scope

---

### B. Document Lifecycle
- ✓ Sales mandate exists: CDS template-111 ("Exclusive Authority to Sell"), template-121, template-122, template-127 — all cover sole mandate clauses including cooling-off text
- ✓ Mandatory Disclosure Form: `sales-mandatory-disclosure.blade.php` (PPA s70 + Regulations s36 compliant)
- ✓ Marketing permission: `marketing-permission-v6.blade.php` (Phase 3e e-sign)
- ✓ Letting mandate + letting MDF: `letting-mandate-v5.blade.php`, `letting-mandatory-disclosure-v7.blade.php`
- ✗ Sales OTP template → 🟡 #11
- ✗ Mandate first-class model → 🟡 #10
- ✗ Cooling-off enforcement → 🟡 #7
- ✗ Sole-mandate gate before portal syndication → 🟡 #8
- ✓ Document hash: `signature_audit_log.document_hash`, `signature_templates.document_hash`, `docuperfect_document_amendments.document_hash_before/after` — tamper detection present
- ✓ Document version history: `presentation_versions` table (SoftDeletes); `signed_document_versions`; `signature_audit_log` is immutable (const UPDATED_AT = null)

---

### C. Agent Lifecycle
- ✓ Agent onboarding fields: id_number, id_document_path, ffc_number, ffc_certificate_path, ffc_expiry_date, ppra_status, ppra_last_verified_at, tax_reference_number, banking details (existing payroll fields)
- ✓ Training records: training_courses + training_completions tables with acknowledgement timestamps + expiries; "FICA Compliance Training" + "RMCP Overview" required
- ⚠ CP-supervisor relationship via `User.supervised_by` (FK present, currently uses shared-eligible-queue rather than dedicated supervisor routing)
- ✗ FFC expiry automated alerts → 🟡 #4
- ✗ Agent suspension/termination audit trail (no `deactivated_at`, `deactivation_reason`, `deactivated_by`) → 🟡 (rolled into compliance event types #14)
- ⚪ CPD points tracking → 🟢 #18

---

### D. Property + Listing Compliance
- ✓ `Property.mandate_type` field present
- ⚠ Compliance snapshot columns exist (`compliance_snapshot_at`, `compliance_snapshot_data`, `compliance_evidence_flags`) — design intent from Phase 1 — not actively populated
- ✗ Cannot list a property without mandate / FICA / MDF — no enforcement → 🟡 #9, #12
- ✓ Photo gallery present (`images_json`, `gallery_images_json`, etc) — photo versioning audit not present → 🟢 #21
- ✗ Sole-mandate verification before portal syndication → 🟡 #8

---

### E. Deal + Transaction Compliance
- ✓ Deal model includes `accepted_status`, `registration_date`, `commission_status`
- ✓ Phase 3i added `sale_price` + `sale_date` canonical fields + `property_id` FK to deals
- ⚠ FICA gate before deal-accepted: not enforced at deal level (only at signature-request level via fica_required)
- ✗ OTP first-class → 🟡 #11
- ⚪ SARS IT3(a) generation → ⚪ #24 (handled by HFC payroll)

---

### F. Compliance Officer Workflow
- ✓ Dashboards exist: `resources/views/compliance/rmcp-dashboard/index.blade.php`, `screening-dashboard/`, `verification-queue/`, `fica/index.blade.php`, `agent-dashboard.blade.php`
- ✓ FICA approval workflow: agent_approved → approved (compliance officer step)
- ✓ FICA officer designation: `FicaOfficerAppointment` ROLE_PRIMARY + ROLE_MLRO
- ✓ Compliance audit trail: agent_activity_events + signature_audit_log both immutable
- ✗ Compliance officer's approval gates on listing publication / OTP send / marketing publish → 🟡 #12
- ✗ Monthly compliance report → 🟢 #16
- ✗ PPRA audit dump → 🟢 #15

---

### G. Data Integrity + Evidence Preservation
- ✓ Soft deletes on all critical compliance models: Property, Contact, FicaSubmission, RmcpVersion/Section/Acknowledgement, SignatureRequest, SignatureTemplate, SignatureAuditLog, Presentation, PresentationVersion, WhistleblowComplaint, EmployeeScreening
- ✓ `forceDelete` usage limited to: `AI\PurgeOldSoftDeletedCacheJob` (AI cache only), `DemoCleanup`, `CleanSlateForTesting` — none in compliance flows
- ✓ agent_activity_events immutable (no updated_at, const UPDATED_AT = null)
- ✓ signature_audit_log immutable (same pattern)
- ✓ contact_access_log immutable (no timestamps at all)
- ✓ Document hash captured on signature_audit_log entries (per-event tamper-detection)
- ⚠ Backup policy documented in `.ai/staging-deploy-checklist-2026-06.md` H4 but not codified in CoreX itself (relies on operations)

---

### H. Demo + Staging Implications
- ✓ Demo isolation: `is_demo` flag on Property, Deal, MarketReportCompRow, MarketDataPoint, SchemeOwner — Phase 3h. Compliance tables (FicaSubmission, RmcpAcknowledgement, contact_consent_records, contact_access_log) NOT polluted with demo data. ✓ clean.
- ✓ Demo seeders live in `database/seeders/Demo/*` separate from production seeders
- ✗ Staging sanitisation script for production-data-copy → 🟡 #13
- ✓ Demo site recommended to be a separate environment per CLAUDE.md non-negotiable #12

---

## Recommended Phase Order

### **Phase 9c — Pre-Launch Blockers (recommend before staging deploy)**
Must-ship before any external customer interaction:
- 🔴 #1: Privacy policy page + footer links on all public views — **S**
- 🔴 #2: Agency Information Officer field + Settings UI — **S**
- 🔴 #3: Agency PPRA number field + render in agent footer + document footers — **S**
- 🟡 #13: Staging sanitisation script (`php artisan staging:sanitise`) — **M**

Total effort: ~1 day of focused work.

### **Phase 9d — Pre-Live Compliance Hardening (after staging, before public launch)**
- 🟡 #4: FFC expiry automated alerts — **M**
- 🟡 #6: Data breach response runbook + breach_incidents table — **M**
- 🟡 #7: Cooling-off enforcement on signed sole mandates — **M**
- 🟡 #8: Sole-mandate gate before portal syndication — **S**
- 🟡 #9: FICA gate on listing publication + OTP send — **M**
- 🟡 #12: Compliance approval gate on listing publication — **M**
- 🟡 #14: PPRA-specific event types (8-10 new domain event classes) — **M**

Total effort: ~3-4 days of focused work.

### **Phase 10 — Post-Launch Iteration**
- 🟡 #5: DSAR workflow (export endpoint) — **L**
- 🟡 #10: First-class Mandate model — **XL**
- 🟡 #11: First-class OTP model — **XL**
- 🟢 #15-#21 nice-to-haves

---

## Items Requiring Human Decision (Policy, not Code)

1. **Privacy policy content** — needs legal review. Suggest: legal drafts it, dev links it.
2. **Information Officer designation** — Elize? Johan? Someone else? Must be named per POPIA s55 and registered with the Information Regulator (HFC operational task).
3. **Mandate cooling-off enforcement**: Phase 9d #7 — strict enforcement vs warning-only? Strict means "publish" button is disabled until day-5; warning means agent can override. Need Elize's policy call.
4. **STR intake** (🟢 #17): keep external (current — goAML portal) vs build internal record-keeping?
5. **PPRA practitioner verification**: how often does Elize manually verify? Drives cadence of the `ppra_last_verified_at` reminder.
6. **Photo-versioning audit** (🟢 #21): is the legal exposure worth the storage cost of immutable image snapshots?
7. **Demo environment URL strategy**: a single agency-distinct demo (e.g. agency_id=99 "Demo Estate Agency") vs a fully separate environment.

---

## Appendix — Files Audited

### Models inspected (compliance-relevant)
- `app/Models/User.php` (FFC, PPRA, supervised_by, is_active)
- `app/Models/Agency.php` (ffc_no, fic_no, whistleblow_tier_recipients)
- `app/Models/Property.php` (mandate_type, compliance_snapshot_*)
- `app/Models/Contact.php` (consent + access log methods)
- `app/Models/FicaSubmission.php`
- `app/Models/UserDocument.php` (DOCUMENT_TYPE_FFC_CERTIFICATE)
- `app/Models/Compliance/FicaOfficerAppointment.php`
- `app/Models/Compliance/RmcpVersion.php` / `RmcpSection.php` / `RmcpVariable.php` / `RmcpAcknowledgement.php` / `RmcpSectionAcknowledgement.php`
- `app/Models/AgentActivityEvent.php` (immutability check)
- `app/Models/Docuperfect/SignatureRequest.php`
- `app/Models/Docuperfect/SignatureAuditLog.php`
- `app/Models/Docuperfect/Document.php`
- `app/Models/CandidatePractitionerService.php`

### Services inspected
- `app/Services/CandidatePractitionerService.php`
- `app/Services/Docuperfect/SignatureService.php`
- `app/Services/PrivateProperty/PrivatePropertySyndicationService.php`

### Migrations cross-referenced
- 2026_04_21_000001 — `add_compliance_fields_to_users_table` (ppra_status, id_number, id_document_path)
- 2026_04_22_090000 — `add_ppra_last_verified_at_to_users`
- 2026_05_05_000017 — `contact_consent_records` + `contact_access_log`
- 2026_05_21_120007 — `create_agent_activity_events_table` (immutable log)
- 2026_03_26_100000 — fica_documents
- 2026_03_26_300000 — `add_fica_gate_to_signature_requests`
- 2026_04_21_100001 through 120002 — RMCP schema (versions, sections, variables, acknowledgements)

### Controllers inspected
- `app/Http/Controllers/Compliance/FicaController.php`
- `app/Http/Controllers/Compliance/FicaPublicController.php`
- `app/Http/Controllers/Compliance/AgentComplianceController.php`
- `app/Http/Controllers/Presentation/PublicPresentationController.php` (POPIA IP masking)

### Views inspected
- `resources/views/compliance/rmcp-dashboard/index.blade.php`
- `resources/views/compliance/screening-dashboard/index.blade.php`
- `resources/views/compliance/verification-queue/index.blade.php`
- `resources/views/compliance/fica/*.blade.php`
- `resources/views/compliance/agent-dashboard.blade.php`
- `resources/views/docuperfect/web-templates/cds/template-111.blade.php` (Exclusive Authority to Sell — sole mandate)
- `resources/views/docuperfect/web-templates/sales-mandatory-disclosure.blade.php`
- `resources/views/docuperfect/web-templates/marketing-permission-v6.blade.php`
- `resources/views/docuperfect/web-templates/letting-mandate-v5.blade.php`
- `resources/views/docuperfect/signatures/external/fica-gate.blade.php`
- `resources/views/emails/signatures/partials/agent-footer.blade.php`
- `resources/views/presentations/public/*.blade.php` (privacy policy absence)
- `resources/views/command-center/settings/contact-governance.blade.php`

### Specs cross-referenced
- `.ai/specs/compliance.md` (51-line stub — system has grown far beyond)
- `.ai/specs/whistleblower-compliance-spec.md` (509 lines — separate compliance domain)

### Seeders cross-referenced
- `database/seeders/HfcRmcpMasterSeeder.php` (RMCP master seed — 700+ lines, Elize Reichel as primary CO)

### Routes inspected
- `routes/web.php` — searched for FICA / RMCP / compliance / privacy / DSAR
- `routes/console.php` — confirmed no FFC reminder schedule

---

*End of audit. Findings are based on a read-only investigation of branch HFC2402 as of commit `a23e295`. Conclusions are limited to what's discoverable in code; operational compliance (HFC's actual day-to-day practice) is out of scope.*
