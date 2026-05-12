# Whistleblower Compliance Module — Specification

**Status:** Draft v1 — Phase 1 build approved by Johan  
**Author:** Johan (CEO HFC) with Claude (senior engineering)  
**Date:** 11 May 2026  
**Spec location:** `.ai/specs/whistleblower-compliance-spec.md`  
**Related specs:**
- `.ai/specs/marketing-permission-spec.md` (loose link — evidence flags on property record)
- `.ai/specs/claude_esignature_v2_spec.md` (PDF generation pattern)
- FICA compliance module (RMCP permissions pattern)

---

## 1. Purpose

When an HFC agent contacts a seller and discovers that another agency has listed the seller's property without the legal paperwork required under the Property Practitioners Act 22 of 2019 (mandate, FICA, Mandatory Disclosure Form), CoreX provides the agent with a structured, evidence-based tool to report that breach to the Property Practitioners Regulatory Authority (PPRA).

This module is **agent-initiated, evidence-driven, and approval-gated**. It is not a scraping bot. It is not a witch hunt. It is honest reporting with an audit trail.

PPRA pitch: HFC built a tool to help its agents report real, verified breaches when they encounter them in the normal course of business. The agency does the paperwork. When competitors don't, HFC reports it through proper channels.

---

## 2. The Real-World Flow
[HFC agent calls seller about a listing seen on a portal]
↓
[Seller volunteers: "I have it listed with Agency X — no,
I haven't signed any paperwork"]
↓
[Agent files whistleblow report in CoreX]
Tier 1 (default): Seller-confirmed paperwork breach
Tier 2 (optional): No FFC displayed / agency not registered
Tier 3 (optional): Unregistered practitioner
↓
[Submitted to Approval Queue]
↓
[Configured approver(s) — default Admin + BM — review]
↓
[First approver to act wins]
├─→ Approve → triggers auto-send to PPRA
├─→ Request changes → returns to agent with notes
└─→ Reject → closed with reason
↓
[On Approve]
├─→ Generate Tier-specific PPRA complaint PDF
├─→ Auto-email PPRA complaints address with PDF + cover
├─→ CC: agency compliance officer + approver
├─→ Mark property record with evidence flag
└─→ Log every step in audit log
↓
[Track status: Sent → Acknowledged by PPRA → Closed]

---

## 3. Scope

### 3.1 In scope for Phase 1

- Three Tiers of complaint (1 default, 2 + 3 optional)
- Agent-initiated reporting only — no scraping, no audit jobs
- Per-agency configurable approver list (multi-select users)
- Single-complaint flow as canonical path
- Batch mode available but as a rare-use option (multi-select up to 10 at once for the same subject agency)
- Tier-specific PPRA complaint PDF templates
- Auto-send email to PPRA on approval (Mailgun via existing CoreX mail driver)
- Full audit trail
- Evidence flag on property record (loose link to Marketing Permission module)
- Status tracking: Draft → Pending Approval → Approved → Sent → Acknowledged → Closed

### 3.2 Explicitly NOT in scope for Phase 1

- Portal Capture audit job (no automated scraping of P24/PP for breaches)
- PPRA FFC register scraping or API (manual FFC lookup by agent only)
- Bulk Tier 2 firehose mode (kept for Phase 2 if HFC opts in)
- Seller-facing information email (separate Phase 2 build)
- Lawyer-managed escalation workflow (Phase 2)

---

## 4. Data Model

### Design Note — Multi-Subject Complaints (updated 12 May 2026)

A single complaint represents ONE property being marketed without proper paperwork by ONE OR MORE non-compliant agencies. Subject details (agency name, practitioner name, portal URL) live in the `whistleblow_complaint_subjects` table, not on the complaint itself. PPRA receives one PDF listing all subjects. FFC number capture removed (PPRA looks up internally). Seller consent checkbox removed (HFC reports what HFC was told; PPRA investigates independently).

### 4.1 New table: `whistleblow_complaints`

```php
Schema::create('whistleblow_complaints', function (Blueprint $table) {
    $table->id();
    $table->foreignId('agency_id')->constrained();
    $table->foreignId('branch_id')->nullable()->constrained();
    $table->foreignId('reported_by_user_id')->constrained('users');

    $table->enum('tier', ['tier_1', 'tier_2', 'tier_3']);

    // Property reference
    $table->foreignId('property_id')->nullable()->constrained();
    $table->string('property_address');

    // Seller info (Tier 1 only)
    $table->foreignId('seller_contact_id')->nullable()->constrained('contacts');
    $table->text('seller_statement')->nullable();

    // Internal notes
    $table->text('agent_notes')->nullable();

    // Workflow status
    $table->enum('status', [
        'draft',
        'pending_approval',
        'changes_requested',
        'rejected',
        'approved',
        'sent',
        'acknowledged_by_ppra',
        'closed',
    ])->default('draft');

    // Approval
    $table->foreignId('approved_by_user_id')->nullable()
          ->constrained('users');
    $table->timestamp('approved_at')->nullable();
    $table->text('approval_notes')->nullable();
    $table->foreignId('rejected_by_user_id')->nullable()
          ->constrained('users');
    $table->timestamp('rejected_at')->nullable();
    $table->text('rejection_reason')->nullable();

    // PPRA submission
    $table->timestamp('sent_to_ppra_at')->nullable();
    $table->string('ppra_reference_number')->nullable();
    $table->timestamp('ppra_acknowledged_at')->nullable();

    // Generated complaint PDF
    $table->string('complaint_pdf_path')->nullable();

    $table->softDeletes();
    $table->timestamps();
});
```

### 4.2 New table: `whistleblow_complaint_evidence`

```php
Schema::create('whistleblow_complaint_evidence', function (Blueprint $table) {
    $table->id();
    $table->foreignId('complaint_id')
          ->constrained('whistleblow_complaints')
          ->cascadeOnDelete();
    $table->enum('evidence_type', [
        'screenshot', 'portal_html', 'seller_statement_pdf',
        'photo', 'audio_recording', 'document_upload', 'other',
    ]);
    $table->string('file_path');
    $table->string('original_filename')->nullable();
    $table->string('mime_type')->nullable();
    $table->integer('size_bytes')->nullable();
    $table->text('description')->nullable();
    $table->foreignId('uploaded_by_user_id')->constrained('users');
    $table->timestamps();
});
```

### 4.2b New table: `whistleblow_complaint_subjects`

```php
Schema::create('whistleblow_complaint_subjects', function (Blueprint $table) {
    $table->id();
    $table->foreignId('complaint_id')
          ->constrained('whistleblow_complaints')
          ->cascadeOnDelete();
    $table->string('agency_name');
    $table->string('practitioner_name')->nullable();
    $table->string('portal_url');
    $table->enum('portal_source', ['p24', 'pp', 'other']);
    $table->string('portal_listing_ref')->nullable();
    $table->integer('display_order')->default(0);
    $table->timestamps();
});
```

### 4.3 New table: `whistleblow_audit_log`

```php
Schema::create('whistleblow_audit_log', function (Blueprint $table) {
    $table->id();
    $table->foreignId('complaint_id')
          ->constrained('whistleblow_complaints')
          ->cascadeOnDelete();
    $table->foreignId('user_id')->nullable()->constrained();
    $table->string('action');
        // 'created', 'submitted', 'approval_requested',
        // 'approved', 'rejected', 'changes_requested',
        // 'pdf_generated', 'emailed_to_ppra',
        // 'acknowledged_by_ppra', 'reopened', 'closed'
    $table->json('action_data')->nullable();
        // recipient emails, IP, user agent, file paths, etc.
    $table->timestamp('created_at');
});
```

No `updated_at` on audit log — immutable.

### 4.4 New column on `agencies`

```php
$table->json('whistleblow_approver_user_ids')->nullable();
    // array of user_ids authorised to approve complaints
    // null = default to all users with role admin or branch_manager
$table->string('whistleblow_compliance_officer_email')->nullable();
    // CC'd on every PPRA submission for agency audit
```

### 4.5 New column on `properties` (loose link)

```php
$table->json('compliance_evidence_flags')->nullable();
    // array of flags like:
    // [{"type": "third_party_no_mandate", 
    //   "complaint_id": 42, 
    //   "flagged_at": "2026-05-11T..."}]
```

Used by the Marketing Permission module's readiness panel — if a competing agency has been flagged on this property, show that context to the listing agent.

---

## 5. Tier Definitions

### 5.1 Tier 1 — Paperwork breach (seller-confirmed)

**The headline case.** 99% of expected volume.

Trigger: Seller tells HFC agent that another agency is marketing their property without proper paperwork (no mandate, no FICA, no MDF).

Evidence required:
- Seller statement (text field, optional audio recording)
- Subject property URL on portal
- Subject agency name
- Subject practitioner name (if known)
- Seller consent to be named in complaint (checkbox)

PDF template: cites Property Practitioners Act §47 (mandate) + §67 (MDF) + FICA §21A (CDD).

Severity in PDF: HIGH — direct seller affidavit attached.

### 5.2 Tier 2 — Public-data breach (no FFC displayed, etc.)

**Optional, side-of-desk reporting.**

Trigger: Agent notices that an advert on P24/PP doesn't display a valid FFC number, or the agency isn't on the PPRA register.

Evidence required:
- Screenshot of advert
- Portal URL
- Manual confirmation that FFC is missing/invalid

PDF template: cites Property Practitioners Act §61 (FFC display requirement).

Severity: MEDIUM.

### 5.3 Tier 3 — Unregistered practitioner

**Rare. Criminal offence territory.**

Trigger: Agent confirms via PPRA "Find a Property Practitioner" register that the advertising agent has no FFC at all.

Evidence required:
- Screenshot of advert
- Screenshot of PPRA register search showing no result
- Manual confirmation by agent

PDF template: cites Property Practitioners Act §49 (operating without FFC).

Severity: HIGHEST.

---

## 6. Approval Workflow

### 6.1 Configuration

Per-agency setting `whistleblow_approver_user_ids` stores an array of user IDs authorised to approve. Default: empty (falls back to all users with role `admin` OR `branch_manager` in the agency).

Agency admin can edit this list at `/agency/settings/compliance`. Multi-select picker over agency users.

### 6.2 Submission

Agent fills the form → clicks Submit. Status transitions: `draft` → `pending_approval`. Notification fires to all configured approvers (in-app + email).

### 6.3 Approval

First approver to act wins. Three actions:

- **Approve** → status → `approved` → triggers auto-send pipeline (§7)
- **Request changes** → status → `changes_requested`, returns to agent with notes
- **Reject** → status → `rejected`, closed, reason logged

Once one approver acts, the others see "Already handled by [name]" badge on the complaint — same pattern as agency-access-authorization spec.

### 6.4 Batch mode

Available but de-emphasised. Agent ticks 2–10 complaints in queue for the **same subject agency** and submits as a batch. Approver sees the batch as one approval action covering all. Each complaint still gets its own PDF, its own email, its own audit row. The batching is purely the approval UX — no shared evidence pack.

---

## 7. Auto-Send Pipeline

On `approved` → `sent`:

1. Generate Tier-specific PPRA complaint PDF via existing DocuPerfect engine (reuse the PDF rendering already in production).
   - Cover page: complaint summary, severity, agency lodging the complaint, date.
   - Body: subject practitioner / agency / property / portal URL / Tier-specific narrative.
   - Evidence appendix: every file in `whistleblow_complaint_evidence` inline (images embedded, documents listed with filenames).
   - Audit trail: timeline of complaint events.
   - Footer: HFC contact for follow-up.

2. Send email to PPRA via existing Mailgun mail driver.
   - To: `complaints@theppra.org.za` (or whichever PPRA address — must be a per-agency configurable field for future flexibility; default to that)
   - CC: agency `whistleblow_compliance_officer_email` + the user who approved
   - From: agency's compliance officer email (uses BaseSignatureMail `fromAgent` pattern)
   - Subject: `[HFC Compliance] PPRA Complaint — Tier {N} — {subject_agency}`
   - Body: short cover (3 paragraphs max) + reference to attached PDF
   - Attachment: the generated PDF

3. Status → `sent`. Record `sent_to_ppra_at` timestamp. Log audit row with recipient emails + message-id.

4. Mark `compliance_evidence_flags` on the property record (if `property_id` was set).

5. In-app notification to reporting agent: "Complaint #{id} sent to PPRA."

---

## 8. UI Surfaces

### 8.1 New top-level sidebar entry: "Compliance Reporting"

Visible to anyone with permission `compliance.whistleblow.view`.

Lands on `/compliance/whistleblow` — list view of all complaints in scope:
- Agent: only their own
- Approver: all in agency, prioritised by pending_approval at top
- Admin: all in agency, filterable

Filters: Status, Tier, date range, subject agency.
Columns: Subject property, subject agency, Tier, status, agent who reported, days in current status.

### 8.2 New complaint form: `/compliance/whistleblow/new`

Step 1 — Select Tier (radio buttons with explanation under each)
Step 2 — Subject info (agency, practitioner, FFC if known)
Step 3 — Property + portal URL
Step 4 — Tier-specific evidence section
  - Tier 1: seller statement, seller consent
  - Tier 2: screenshot upload
  - Tier 3: screenshot + PPRA register screenshot
Step 5 — Notes + review
Step 6 — Submit (status → `pending_approval`)

### 8.3 Approval queue view

For configured approvers: a dedicated sub-page `/compliance/whistleblow/approve` showing all `pending_approval` complaints. Click row → full review modal with all evidence visible inline + three buttons: Approve / Request Changes / Reject.

### 8.4 Complaint detail view

`/compliance/whistleblow/{id}` — full timeline, evidence, audit log, generated PDF link, status. Read-only after `sent`.

### 8.5 Property record integration

On any property's detail page, if `compliance_evidence_flags` is non-empty, render a small panel: "Compliance flags on this property" listing each (linked to the complaint detail).

### 8.6 Agency settings — Compliance section

New section on `/agency/settings/compliance`:
- Multi-select picker: who can approve complaints
- Text field: compliance officer email (CC'd on PPRA submissions)
- Text field: PPRA complaints email (default `complaints@theppra.org.za`, editable)

---

## 9. Permissions (RMCP)

Add to RMCP seeder, new section: `compliance_whistleblow`

| Slug | Description | Default roles |
|------|-------------|---------------|
| `compliance.whistleblow.view` | See whistleblow module | agent, bm, admin, super_admin |
| `compliance.whistleblow.create` | File new complaints | agent, bm, admin, super_admin |
| `compliance.whistleblow.approve` | Approve / reject complaints | bm, admin, super_admin (also restricted by per-agency approver list) |
| `compliance.whistleblow.view_all_agency` | See all complaints in agency (not just own) | bm, admin, super_admin |
| `compliance.whistleblow.configure` | Manage approver list + PPRA email | admin, super_admin |

---

## 10. Build Sequence

**Prompt A — Database + models + RMCP**
- 3 migrations (complaints, evidence, audit_log)
- Migration: agencies new columns (approver_list, compliance_officer_email)
- Migration: properties new column (compliance_evidence_flags)
- Models with relationships, casts, BelongsToAgency trait
- RMCP seeder update with new section + 5 permissions
- Tinker verification: create a draft complaint, attach a fake evidence row, query relationships

**Prompt B — Service layer + PDF generation**
- `WhistleblowComplaintService` (create, submit, approve, reject, send_to_ppra)
- Three Tier-specific PDF templates (resources/views/compliance/whistleblow/pdf/{tier1,tier2,tier3}.blade.php)
- PDF generation via existing DocuPerfect engine
- Tinker verification: programmatically create + approve a complaint, confirm PDF generated, dump file path

**Prompt C — Email + audit + auto-send pipeline**
- `WhistleblowComplaintMail` Mailable extending BaseSignatureMail
- Auto-send triggered on approval via observer or service method
- Audit log writer for every action
- Tinker verification: approve a test complaint, confirm email fires to MAIL_MAILER=log, check audit rows

**Prompt D — Filing form (agent UI)**
- Routes + controller
- Multi-step form (steps 1–6)
- Evidence upload component (reuse existing file uploader pattern)
- Save draft + submit
- Curl/grep verification: render the form, confirm Tier selection + all field groups present

**Prompt E — Queue + approval UI**
- List view at `/compliance/whistleblow`
- Approval queue at `/compliance/whistleblow/approve`
- Detail view with timeline, evidence inline, audit log
- Approve / Reject / Request Changes actions
- Curl/grep verification: render queue as approver, confirm pending items visible; approve via curl, confirm status transition + auto-send fires

**Prompt F — Property integration + settings**
- Property detail page: compliance flags panel
- Agency settings: compliance section with approver picker + email fields
- Curl/grep verification: render property detail with a flag, confirm panel visible; render settings, confirm picker present

**Prompt G — Sandbox demo data**
- Seed 5–10 realistic sample complaints across all three Tiers
- Cover all statuses (draft, pending, approved, sent, acknowledged)
- Include realistic Tier 1 narratives (seller statements)
- This makes the PPRA demo immediately tangible
- Tinker dump showing the seeded data

**Prompt H — Lawyer-review hooks**
- Wire the three PDF templates and the cover email so the lawyer can review and mark up
- Output the three templates as HTML + PDF preview to `/tmp/whistleblow-templates-preview/`
- This is what gets sent to the lawyer for review

---

## 11. Sandbox / Demo Mode for PPRA Meeting

For the PPRA Joburg meeting (Wed next week):

- The module is fully functional in production but **PPRA email sending is gated by an env var** `WHISTLEBLOW_PPRA_LIVE_SEND=false` until lawyer signs off.
- When `false`: emails route to `johan@hfcoastal.co.za` (or configurable address) with subject prefix `[DEMO]`. Audit log still records everything as if sent to PPRA.
- When `true`: emails route to the real PPRA address.
- Demo path: walk PPRA through a Tier 1 complaint creation → approval → "would-have-been-sent" PDF + email shown side by side.
- Easy switch when lawyer signs off post-Joburg.

---

## 12. Edge Cases

| Scenario | Handling |
|---|---|
| Subject practitioner is actually compliant (mistake) | Reject path. Reason logged. Agent notified. |
| Seller withdraws statement after submission | Complaint can be marked `closed` with reason. PDF and audit retained for record-keeping. |
| Subject agency emails HFC to dispute | Out of scope for v1 — handled outside CoreX manually. |
| Approver leaves agency before approving | Re-routes to remaining approvers in list. If list empty, falls back to all admins. |
| Two approvers approve simultaneously | First wins, second gets "Already handled" badge. |
| Evidence file too large | Same upload limits as existing FICA / document upload. |
| Agent submits for property already flagged | Allow — separate complaint, separate audit. Property record shows multiple flags. |
| Same subject agency, many complaints | All audited individually. Optional batch approval per §6.4. |

---

## 13. Acceptance Criteria

Phase 1 build is complete when:

1. An HFC agent can file a Tier 1 complaint end-to-end with seller statement + screenshot + URL evidence in under 3 minutes.
2. A configured approver receives in-app + email notification, opens the complaint, sees all evidence inline, and can approve with one click.
3. On approval, a Tier 1-specific PDF is generated with all evidence inline, sent to the configured PPRA address (or demo address when gated), CC'd to the agency compliance officer + approver.
4. Audit log shows every action with user, timestamp, recipient list.
5. Property record shows the evidence flag.
6. Agency admin can configure the approver list and compliance officer email.
7. Tier 2 and Tier 3 templates work identically with their own evidence requirements.
8. Demo mode (env-gated) lets the PPRA meeting see the full flow without actually emailing PPRA.
9. PPRA could walk away from the meeting saying "Yes, this is exactly the kind of tool we want practitioners using."

---

## 14. Legal Framework

This module supports compliance with and reporting under:

- **Property Practitioners Act 22 of 2019** — primary regulatory framework, §47 (mandates), §61 (FFC display), §67 (MDF), §49 (operating without FFC)
- **Financial Intelligence Centre Act 38 of 2001** (FICA) — CDD on parties to property transactions
- **Protection of Personal Information Act 4 of 2013** (POPIA) — lawful basis: agent has reasonable belief of regulatory breach; seller has consented (Tier 1); reporting to designated regulatory authority is a lawful purpose under §11(1)(c) of POPIA
- **Consumer Protection Act 68 of 2008** (CPA) — fair dealing in property transactions

Lawyer review (already lined up) covers:
- Three Tier-specific PDF templates
- Cover email
- Seller consent wording (Tier 1)
- POPIA disclosures within the complaint pack

This is not legal advice; the module is built to support compliant reporting under the South African regulatory framework.
