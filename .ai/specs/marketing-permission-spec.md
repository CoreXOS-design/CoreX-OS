# Marketing Permission & Compliance Gating — Specification

**Status:** Draft v1 — Phase 1 build approved by Johan
**Author:** Johan (CEO HFC) with Claude (senior engineering)
**Date:** 11 May 2026
**Spec location:** `.ai/specs/marketing-permission-spec.md`
**Related specs:** 
- `.ai/specs/claude_esignature_v2_spec.md` (FICA gate is upstream of e-sign)
- FICA compliance module (deployed late April 2026)

---

## 1. Purpose

CoreX OS must enforce that no property is marketed externally unless the agency has captured and verified the legal artifacts that authorise marketing. This is a non-negotiable compliance requirement under the Property Practitioners Act 22 of 2019 (administered by PPRA), FICA, and POPIA.

This spec defines:
- What "marketing-ready" means
- Where the status lives and how it's computed
- Which actions are gated
- How agents see what's outstanding
- The compliance snapshot rule that protects historical compliance

---

## 2. The Real-World Flow
[Agent makes verbal contact with seller]
↓
[Seller verbally agrees agency may market property]
↓
[Agent triggers "Send Marketing Pack" in CoreX]
├─→ Marketing Permission document (mandatory)
├─→ Mandate document — sole OR open (mandatory)
└─→ FICA verification (gated upstream of e-sign by existing flow:
contact receives e-sign link → on open, if not FICA compliant
they MUST complete FICA before viewing docs to sign)
↓
[Two parallel real-world events required]
├─→ Signed paperwork returned
└─→ Agent captures listing (photos uploaded, listing details complete)
↓
[All gates green] → Listing becomes marketing-ready
↓
[Compliance snapshot recorded at moment of go-live]
↓
[Any marketing attempt — portal, social, print, WhatsApp share —
checks the snapshot exists. If yes: allowed + logged. If no: blocked.]

---

## 3. Compliance Snapshot Rule (Critical)

A listing's compliance status is **snapshot at the moment it first goes live for marketing**. From that point forward:

- The listing remains compliant for the duration it is actively marketed
- FICA expiry on the seller does **NOT** retroactively de-list the property
- The agency was compliant at the moment of go-live; that is what matters legally

When the property **sells** or is **re-listed** (i.e. taken off market then put back on):
- Compliance must be re-verified for the new transaction
- FICA must be re-checked at deal stage (already enforced by existing FICA module)
- New mandate and new marketing permission required if re-listing

This snapshot is captured as `compliance_snapshot_at` on the listing when first marketed — a permanent record of the state at go-live time. It includes a JSON snapshot of which contacts FICA'd, which documents were signed, and when.

---

## 4. Data Model

### 4.1 Existing tables (no changes)

- `properties` — listings
- `contacts` — sellers, buyers, etc.
- `documents` — signed paperwork (mandate, marketing permission)
- `fica_submissions` — FICA records per contact
- `property_media` — photos on the listing

### 4.2 New columns on `properties`

```php
$table->timestamp('compliance_snapshot_at')->nullable();
$table->json('compliance_snapshot_data')->nullable();
$table->timestamp('first_marketed_at')->nullable();
```

`compliance_snapshot_data` shape:
```json
{
  "captured_at": "2026-05-11T14:30:00Z",
  "captured_by_user_id": 22,
  "sellers": [
    {"contact_id": 171, "name": "...", "fica_submission_id": 42, 
     "fica_passed_at": "..."}
  ],
  "documents": [
    {"document_id": 88, "type": "marketing_permission", 
     "signed_at": "..."},
    {"document_id": 89, "type": "mandate_sole", "signed_at": "..."}
  ],
  "listing": {
    "photo_count_at_snapshot": 12,
    "details_complete": true
  }
}
```

### 4.3 New table: `marketing_share_log`

```php
Schema::create('marketing_share_log', function (Blueprint $table) {
    $table->id();
    $table->foreignId('property_id')->constrained();
    $table->foreignId('user_id')->constrained();
    $table->foreignId('agency_id')->constrained();
    $table->string('channel');  // 'portal_p24', 'portal_pp', 
                                // 'social_facebook', 'whatsapp', 
                                // 'email', 'print_brochure', 'cma_send'
    $table->string('recipient_context')->nullable();  
        // 'group:Buyers Mtunzini', 'contact:171', 'public_portal'
    $table->json('metadata')->nullable();
    $table->timestamp('created_at');
});
```

No `updated_at` — these are immutable audit records.

### 4.4 Phase 2 (NOT BUILT IN THIS PHASE — captured for design completeness)

`property_contact_proxy` — represents real-world authority of one contact over another (POA, executor, director, trustee, spouse under in-community marital regime).

```php
Schema::create('property_contact_proxy', function (Blueprint $table) {
    $table->id();
    $table->foreignId('property_id')->constrained();
    $table->foreignId('proxy_contact_id')->constrained('contacts');
    $table->foreignId('represented_contact_id')->constrained('contacts');
    $table->enum('authority_type', [
        'power_of_attorney', 'executor', 'director', 
        'trustee', 'spouse_marital_regime'
    ]);
    $table->foreignId('authority_document_id')->constrained('documents');
    $table->timestamp('authority_verified_at')->nullable();
    $table->foreignId('verified_by_user_id')->nullable()
          ->constrained('users');
    $table->timestamps();
});
```

**Phase 1 assumption:** All sellers on a property must personally FICA + sign. Proxy is deferred to Phase 2 and will be specified in a follow-on spec.

---

## 5. Marketing Readiness — Derived Status

Marketing-ready is a **computed** status, not a stored boolean. Computed on demand from existing data sources by a service.

### 5.1 Service contract

```php
namespace App\Services\Compliance;

class MarketingReadinessService
{
    public function statusFor(Property $property): ReadinessReport;
    public function snapshotCompliance(Property $property, User $by): void;
    public function isMarketable(Property $property): bool;
}

class ReadinessReport
{
    public bool $ready;
    public ?Carbon $snapshotAt;       // null if never marketed
    public array $blockedBy;          // list of reason strings
    public array $nextActions;        // list of {label, action_url}
    public array $checklist;          // full state of every gate
}
```

### 5.2 Gates (all must pass for `ready = true`)

> **AMENDED — AT-94 (2026-06-24, Johan final).** The document gates no longer
> query `docuperfect_documents`/`signature_templates` by hardcoded string.
> They read the property's **Drive documents** (`App\Models\Document` on the
> property + linked seller contacts) against each agency's **configurable
> required document-type list** (Settings → Document Types, stored per-agency
> in `agency_document_type_compliance`). A typed, non-soft-deleted Drive
> document of a required type being **present** IS the gate — no approval
> status is checked. See §5.5 (Doctrine) and §6.5.

| Gate | Check | Source |
|------|-------|--------|
| Each agency-required document type present | For every document type the agency flags `is_compliance_required`, at least one non-soft-deleted `Document` of that `document_type_id` is linked to the property OR a seller/owner/landlord/lessor contact. HFC default required set: `mandate`, `fica`, `disclosure` (MDF). Presence = met; no approval status checked. | `documents` + `document_properties` + `document_contacts` + `agency_document_type_compliance` |
| FICA bridge (when `fica` is required) | Satisfied by a `fica`-typed Drive document (above) OR every seller having a `fica_submission` with status `approved` (authoritative FICA workflow preserved — no regression). | `fica_submissions` (OR Drive) |
| Listing has photos | `gallery_images_json` + `images_json` count ≥ minimum (hardcoded 4 in Phase 1) | `properties` JSON columns |
| Listing details complete | Required fields present: address, suburb, town, province, price, property_type, erf_size_m2 | `properties` |

Because e-signed documents auto-file to the Drive with their `document_type_id`
(`SignatureService::autoFileSignedDocument`), an e-signed mandate satisfies the
same gate as a manually-uploaded one — the gate is source-agnostic by design.

### 5.5 Doctrine — instant-unlock on typed upload (AT-94, standing, all agencies)

The two compliance paths apply the **same control at different points**, by
design — they are not inconsistent:

- **Wet-ink / physical** documents are physically signed off by the applicable
  BM in the real world before they are scanned. The approval control is met on
  paper by an authorised person, so a typed Drive upload unlocks compliance
  **instantly** — the system must NOT add a CO/approval step, which would
  duplicate an already-completed control.
- **E-sign** has no physical document and no BM paper sign-off, so the approval
  control lives system-side — which is exactly why the e-sign pipeline has
  CO-approval routing. That pipeline is **untouched** by AT-94.

Instant-unlock on Drive upload is the digital reflection of a completed
physical sign-off, not a lowered bar. The Drive-upload compliance path checks
**presence of a typed document only** — never an approval status.

### 5.3 If a snapshot already exists

If `compliance_snapshot_at IS NOT NULL` → property is marketing-ready regardless of current state of gates. Snapshot rule protects historical compliance.

To "un-snapshot" (rare — e.g. property withdrawn from market entirely and being re-listed), an explicit admin action sets `first_marketed_at = NULL` and `compliance_snapshot_at = NULL`. Then gates are re-checked at next marketing attempt.

### 5.4 Compliance Triggers (Autonomous)

Compliance gates fire automatically when upstream actions complete. No manual sync step is required by the agent.

**E-sign completion chain:**
1. All signing parties complete → `SignatureService::completeDocument()` sets `signature_templates.status = 'completed'`
2. `autoFileSignedDocument()` files the signed PDF to property + contact drives
3. Next readiness check by `MarketingReadinessService::statusFor()` reads the completed status — gate passes

**Wet-ink upload chain:**
1. External signer uploads scanned signed document → `wet_ink_status = 'uploaded_pending_review'`
2. Agent inspects and approves → `submitInspection()` or `approveUploadOnBehalf()` sets request `status = 'completed'`
3. `advanceAfterWetInkApproval()` advances to next party or calls `completeDocument()` if all done
4. Same auto-filing and readiness gate behaviour as e-sign

**FICA approval chain:**
1. Contact submits FICA → `status = 'submitted'`
2. Agent reviews → `status = 'agent_approved'`
3. Compliance Officer approves → `FicaController::complianceApprove()` sets `status = 'approved'`, `fica_expires_at = now() + 24 months`
4. Next readiness check reads `status = 'approved'` — gate passes

**Key design property:** The readiness service reads the **same database columns** that the official approval actions write. No event listeners, no sync jobs, no cache invalidation needed.

---

## 6. Gate Enforcement Points

Every code path that initiates external marketing must call `MarketingReadinessService::isMarketable()` first. If false, the action is refused with a `MarketingBlockedException` that carries the `ReadinessReport`.

### 6.1 Marketing channels in scope

All of the following must be gated:

- **Portal syndication** — Private Property, Property24 (already in CoreX integration code)
- **Social posts** — Facebook, Instagram, LinkedIn (CoreX social hub)
- **Print/brochure generation** — PDF brochure builder, window cards
- **CMA send-out** — when a CMA is sent to a contact via CoreX
- **WhatsApp share** — the "Share to WhatsApp" button on the listing page
- **Email share** — any "Email this listing" action
- **Public listing URL** — if the property has a public-facing CoreX URL, it returns 404 until marketing-ready

### 6.2 Internal use is NOT gated

These are allowed regardless of marketing-ready status:
- Internal CMAs viewed only by agents in CoreX
- BM/Admin viewing the listing
- Sharing the listing internally to another agent in the agency
- The Listing detail page in CoreX itself

The distinction: **anything that exposes the listing to external parties** is gated. Internal workflow continues so the agency can prepare.

### 6.3 Share logging

Every successful share through CoreX writes a row to `marketing_share_log` regardless of channel. This applies even after the snapshot exists. Audit trail.

### 6.4 Wet-Ink Upload Path

Documents signed on paper are uploaded via DocuPerfect's wet-ink path. The flow:

1. E-sign wizard creates document with `delivery_mode = 'wet_ink'`
2. Agent uploads scanned signed pages via `wetInkAgentUpload()` or external signer uploads via the wet-ink portal
3. Agent reviews the upload (inspection checklist) and approves via `wetInkAgentApprove()` / `wetInkDecision()`
4. `advanceAfterWetInkApproval()` advances to next signing party or calls `completeDocument()` when all done
5. `completeDocument()` sets `signature_templates.status = 'completed'` and auto-files to property + contact drives

Same approval routing as e-sign: candidate practitioners route through supervisor steps before completion. Full-status practitioners' documents complete after agent review + all parties signed.

Same readiness gate trigger: the readiness service reads `signature_templates.status = 'completed'` regardless of whether the document was e-signed or wet-ink scanned.

Full CRUD on wet-ink uploads: upload (create), view on property/contact drive (read), re-upload after rejection (update via replace), soft-delete of the parent document (delete).

---

### 6.5 Drive-document compliance path (AT-94)

The gate that decides marketability reads the property's Drive documents — it
does not care how they arrived:

- **Manual / wet-ink upload** → `PropertyFileController::store` /
  `ContactDocumentController::store` create a `Document` with the chosen
  `document_type_id`. If that type is agency-required, the gate is met the
  instant the upload completes. No CO/approval step (see §5.5 Doctrine).
- **E-sign** → on completion `SignatureService::autoFileSignedDocument` files a
  `Document` with the template's `document_type_id`. Same gate, same result.

Which document types gate is **agency-configurable** at Settings → Document
Types (per-agency `is_compliance_required` flag, stored in
`agency_document_type_compliance`). No hardcoded document-type strings remain in
the readiness service. New agencies are seeded the default required set
(`mandate`, `fica`, `disclosure`) via `AgencyComplianceDocTypeService::ensureDefaults`.

### 6.6 Drive-tab compliance checklist (AT-94)

The property's Drive/Files tab shows the agency's required documents as a
checklist, driven by the SAME `MarketingReadinessService` presence evaluation
the gate uses (`complianceChecklistFor()` and `requiredDocTypeGates()` both
consume one private `evaluateRequiredTypes()` — they can never disagree). Each
required type is ticked when present, or shown unticked with an inline
**Upload** control. The Upload posts to the existing
`PropertyFileController::store` with the **document type pre-set** (the agent
can't mistype it); contact-level types (FICA, `grouping='contact'`) also pass
the primary seller's `contact_id` so the document lands on the seller's drive
where the gate's contact-level check reads it. On success the Drive tab
reloads → checklist and readiness panel recompute. When the gate is
short-circuited ready (dev override or an existing snapshot), every row
reflects satisfied so the checklist agrees with a LIVE gate.

## 7. UI Surfaces

Three places agents and managers see marketing-readiness:

### 7.1 Command Centre card

New card on Command Centre: **"Listings Pending Marketing"**
- Shows count of properties where agent is listing agent AND `compliance_snapshot_at IS NULL`
- Click → list view of those properties with reason chips: "Missing: Mandate", "Missing: FICA on John Smith", "Missing: 4 more photos"
- Each reason chip is clickable → jumps to the action that resolves it

### 7.2 Properties index page

New column: **"Marketing Status"**
- Three states with colour:
  - **Live** (green) — `compliance_snapshot_at IS NOT NULL`
  - **Ready** (teal) — gates all pass but not yet marketed (snapshot not taken)
  - **Blocked** (amber) — at least one gate failing
- Hover tooltip: short list of what's missing (for Blocked)
- Sortable, filterable

### 7.3 Individual listing page — Readiness Panel

A collapsible panel on the listing detail page, positioned above the tab bar. Three render modes:

**LIVE state** (collapsed by default, ~40px):
- Single-line header: "✓ COMPLIANCE LIVE — captured {date} by {name}" + green LIVE badge
- Chevron expands to show full historical checklist (preserved from snapshot)

**READY state** (collapsed by default, ~50px):
- Single-line header: "✓ COMPLIANCE READY — all gates passed" + teal READY badge
- **"Go Live & Start Marketing"** button inline in header (calls `snapshotCompliance()`)
- Chevron expands to show full checklist

**BLOCKED state** (expanded by default):
- Full checklist visible — agent needs to see what's failing
- Each gate: ✓ pass (green) or ✗ fail (red) with detail text
- For each ✗: right-aligned action button ("Send Marketing Pack", "Request FICA", "Upload Photos", "Complete Details")
- Footer: "Marketing is blocked until all gates are green."
- Chevron collapses if agent wants to dismiss temporarily

Implementation: Alpine.js `x-data="{ expanded: <isBlocked> }"` with `x-show="expanded"` on checklist, chevron toggles.

---

## 8. Failure Behaviour (Agent Attempts Marketing on Blocked Listing)

Action refused. UI shows the readiness panel inline with the failing gates highlighted. No notifications, no escalation, no override option. Per Johan: "if we are not legally compliant we do not advertise — no override, no nothing."

The backend exception is caught by every marketing entry point and turned into a UI response. The blocked attempt is **not** logged to `marketing_share_log` (only successful shares are logged) but it IS logged to the existing system audit log for diagnostic purposes only.

---

## 9. Build Sequence (Phase 1)

Each prompt is independently verifiable. Browser-verify between prompts.

**Prompt A — Database + service skeleton**
- Migration: add `compliance_snapshot_at`, `compliance_snapshot_data`, `first_marketed_at` to `properties`
- Migration: create `marketing_share_log` table
- Service: `MarketingReadinessService` with `statusFor()`, `isMarketable()`, `snapshotCompliance()`
- `MarketingBlockedException` class
- Tinker verification: call service on three test properties (one with all gates passed, one missing FICA, one with snapshot already taken). Dump output.

**Prompt B — Readiness panel on listing page**
- New blade partial `listings.partials.readiness-panel`
- Include on listing detail page
- Renders checklist from `ReadinessReport`
- "Go Live & Start Marketing" button calls snapshot endpoint
- Curl/grep verification: render the panel for the three test properties, confirm correct state shown

**Prompt C — Properties index column + Command Centre card**
- Add Marketing Status column to properties index
- Add Listings Pending Marketing card to Command Centre
- Reason chips clickable
- Curl/grep verification

**Prompt D — Gate enforcement on every marketing action**
- Audit every controller that initiates external marketing (P24 syndication, PP syndication, social hub, brochure PDF, WhatsApp share, email share, CMA send, public listing URL)
- Each wraps action in `if (!$readiness->isMarketable($property)) throw new MarketingBlockedException(...)`
- Frontend share buttons disabled with tooltip when blocked
- Verify each gate point with one test per channel — confirm blocked listings return the expected refusal, marketed listings proceed and log to `marketing_share_log`

**Prompt E — Share logging on successful actions**
- Every successful marketing action writes to `marketing_share_log` with channel, user, property, recipient_context, metadata
- Tinker dump of log rows after running each test in Prompt D

**Prompt F — Compliance snapshot endpoint + UI wiring**
- POST `/properties/{property}/go-live` calls `snapshotCompliance()`
- Permission: only listing agent OR BM OR admin can trigger
- Records timestamp, user, full snapshot JSON
- Property becomes marketing-ready immediately on success
- Verify: trigger on a property with all gates green, dump `compliance_snapshot_data`, confirm complete

---

## 10. Out of Scope for Phase 1 (Captured for Phase 2)

- **Proxy authority on contacts** — multi-seller properties currently require ALL sellers to personally FICA + sign. Proxy (POA, executor, director, trustee) is a Phase 2 build.
- **WhatsApp share recipient capture** — Phase 1 logs that a WhatsApp share happened but doesn't capture who it was sent to (browser API limits). Phase 2 may add a "log who I sent this to" prompt.
- **Per-agency configurable minimum photos** — Phase 1 hardcodes minimum at 4. Phase 2 makes it configurable per agency.
- **De-snapshot workflow** — admin manually un-marketing a property to re-trigger compliance check. Phase 1 supports it via direct DB action by super_admin only; Phase 2 adds a proper UI.

---

## 11. Acceptance Criteria

The Phase 1 build is complete when:

1. A property with no documents and no FICA cannot be marketed via ANY of the channels in §6.1
2. A property with all gates passed can be put live via "Go Live & Start Marketing" button
3. Once live, marketing channels work and every action logs to `marketing_share_log`
4. FICA expiry on a seller AFTER snapshot does not unmarket the property
5. Re-listing (admin de-snapshots) re-triggers all gates
6. Listing agents can see at a glance from Command Centre what's missing per listing
7. Properties index shows Marketing Status column with correct state
8. PPRA could be shown this in 5 minutes and would understand exactly what it enforces
9. A wet-ink scanned mandate triggers the Authority to Market gate the same way an e-signed mandate does, including the same approval routing and auto-filing

---

## 12. Reference: South African Legal Framework

This spec enforces compliance with:

- **Property Practitioners Act 22 of 2019** (PPRA) — duty of property practitioners to act lawfully, including mandate requirements
- **Financial Intelligence Centre Act 38 of 2001** (FICA) — customer due diligence on parties to property transactions
- **Protection of Personal Information Act 4 of 2013** (POPIA) — lawful basis for processing personal information in marketing materials
- **Consumer Protection Act 68 of 2008** (CPA) — accurate disclosure in advertising

This is not legal advice; the spec is built to support agency compliance and audit-readiness.
