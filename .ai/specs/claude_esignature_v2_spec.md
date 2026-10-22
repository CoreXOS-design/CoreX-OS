# CoreX OS — E-Signature V2 Specification
# State of Reality — March 27, 2026 (Audit-Verified)

> This spec reflects what ACTUALLY EXISTS in the codebase, verified by
> a full code audit on March 27, 2026. Every claim has been traced to
> exact file:line references. This is the single source of truth.
> Every VS Code prompt must read this file before making any changes.

---

## 1. Legal Boundaries

### What CAN be e-signed
- FICA declarations and consent forms
- Mandates (letting mandates, sole mandates, marketing mandates, exclusive authority)
- Mandatory disclosure forms
- POPIA consent forms
- Lease agreements (under 10 years)
- Rental applications
- Inspection reports
- Any internal agency document

### Sale agreements / OTPs (amended 2026-10-08 — no hard block)
- South African law currently does not recognise an e-signed agreement for the alienation of immovable property, and lease agreements over 10 years.
- CoreX does not block them. E-sign eligibility is the template's own setting (`is_esign`). Template types flagged `document_types.esign_warning_required` (`sale_agreement`, `otp`, …) default to wet ink; an admin switches e-signing on in template setup after acknowledging a legal warning, recorded in `template_esign_acknowledgements`. Nothing is shown to an agent. See ESIGN-CANON.md §7.

---

## 2. Architecture Overview

### Core Files
| File | Purpose |
|------|---------|
| `ESignWizardController.php` | 6-step wizard: template → property → recipients → details → fill/review → sign/send |
| `SignatureController.php` | Setup (marker placement), agent signing, review, approve. Also handles candidate practitioner supervisor flow |
| `SigningController.php` | External signer flow (gateway → signing → completion) |
| `SignatureService.php` | Core logic: createSigningRequest, handlePartyCompletion, advanceToNextParty, sendSigningRequest, autoFileSignedDocument, approveAndAdvance |
| `SignaturePdfService.php` | PDF generation via Puppeteer (generateFromHtml for web templates) |
| `WebTemplateDataService.php` | Field resolution for web templates |
| `TemplateController.php` | Template CRUD, CDS generation, field management |

### Key Views
| View | Purpose |
|------|---------|
| `esign/wizard.blade.php` | 6-step e-sign wizard |
| `signatures/setup.blade.php` | Marker placement on document |
| `signatures/sign.blade.php` | Agent signing view |
| `signatures/external/sign.blade.php` | External signer signing view |
| `signatures/external/fica-gate.blade.php` | FICA gate before signing |
| `signatures/review.blade.php` | Agent review after party signs |
| `signatures/external/print.blade.php` | Print view for recipients |
| `signatures/external/amendment-review.blade.php` | Amendment accept/reject |
| `templates/edit.blade.php` | Legacy PDF template editor |
| `templates/cds-builder.blade.php` | CDS web template builder |
| `esign/my-documents.blade.php` | Agent document management |

### Key Database Tables
| Table | Purpose |
|-------|---------|
| `docuperfect_templates` | Template definitions with all settings |
| `docuperfect_documents` | Document instances |
| `signature_templates` | Signing config per document flow |
| `signature_requests` | Per-party signing status and tracking |
| `signature_markers` | Signature/initial positions on document |
| `signature_zones` | Bounding boxes for dynamic signature areas |
| `document_amendments` | Clause flags and amendment records |
| `esign_consent_log` | Immutable consent records |
| `agency_signing_parties` | Agency-level party name definitions |
| `contact_types` | Contact type with esign_role mapping |

---

## 3. Template Configuration

### Template Settings — What Each One Does (Audit-Verified)

| Setting | DB Column | What It Does | Status |
|---------|-----------|--------------|--------|
| Template Name | `name` | Display name | WORKING |
| Category | `category` | Sales/Rentals filter on template list UI. NOT used in wizard for any logic | WORKING (UI only) |
| Document Type | `document_type_id` | FK to document_types. Used for template list filtering AND auto-filing signed docs to contacts/properties | WORKING |
| Template Type | `template_type` | Legal e-sign block for sale_agreement/otp types | WORKING — critical |
| Global | `is_global` | Branch visibility | WORKING |
| Eligible for E-Sign | `is_esign` | Coarse gate: show/hide from wizard template list | WORKING |
| Signing Mode | `party_mode` | Shared (all sign same doc) vs Per Party (separate copy each) | WORKING |
| Allowed Delivery Modes | `allowed_delivery_modes` | Fine-grained: which modes (esign/wet_ink/download) appear in wizard Step 6. Read by getAllowedDeliveryModesArray(), allowsDeliveryMode(), getEffectiveDeliveryModes() | WORKING |
| Security Tier | `security_tier` | Stored but NEVER read by any business logic. Pure pass-through. | DEAD — safe to remove |
| Signing Parties | `signing_parties` | JSON array of roles (owner_party/acquiring_party/agent). Used in 7+ files for sales/rental context detection, signature block rendering, wizard role fallback. Feeds isSalesDocument(). | WORKING — critical |

### is_esign vs allowed_delivery_modes — NOT duplicates
- `is_esign` = "Can this template appear in the wizard at all?" (boolean gate)
- `allowed_delivery_modes` = "Which delivery methods are available?" (fine-grained)
- A template can have `is_esign=true` but `allowed_delivery_modes='wet_ink,download'` (appears in wizard but e-sign mode unavailable)
- Both are needed — independent, complementary gates

### Two "Signing Parties" UI Elements — NOT duplicates

**"Signature Block Parties"** (cds-builder.blade.php:167-237)
- Agency-wide list stored in `agency_signing_parties` table
- Names like: Agent, Seller, Buyer, Lessor, Lessee, Witness
- Used by: signature/initial tag checkboxes ("which parties sign here?")
- Scope: all templates for the agency
- CRUD via DocumentImporterController (store, update, destroy, reorder)

**"Document Signing Roles"** (cds-builder.blade.php:320-346)
- Per-template setting stored in `docuperfect_templates.signing_parties` JSON
- Values: owner_party, acquiring_party, agent
- Used by: sales/rental context detection, signature block rendering, wizard fallback
- Scope: this template only

### Per-Field Settings

**Legacy PDF templates (template editor):**
- `fields_json[].assignedTo` — dropdown: who fills this field (creator/agent/seller/tenant etc)
- Agent fills = editable. Others = read-only with "[Party] will complete" label
- No "editable at signing by" checkboxes exist in legacy editor

**CDS web templates (CDS builder):**
- `field_mappings[].filled_by` — who fills the field initially
- `field_mappings[].editable_by` — array of roles who can EDIT at signing time
- Checkboxes with tooltip: "If none selected, field is locked after agent fills it"
- Role mapping at signing: seller→owner_party, buyer→acquiring_party, agent→agent, witness→witness
- JS converts matching fields to `<input>` elements with amber styling (sign.blade.php:1865-1895)

---

## 4. E-Sign Wizard (6 Steps)

### Step 1: Template Selection
- Shows templates where `is_esign = true`
- Filtered by branch access
- Status: WORKING

### Step 2: Property Selection
- Agent picks a property
- Tracks `_property_source` for context (properties vs rental_properties)
- Status: WORKING

### Step 3: Recipients
- Loads ALL contacts linked to the property via `contact_property` pivot (ESignWizardController:491)
- **BUG #1**: No filtering by template context — all contact types shown regardless of document type
- **BUG #1b**: Auto-populate only works when propertySource === 'properties' — skips rental_properties entirely
- **BUG #1c**: Search API at line 949 uses hardcoded role map instead of esign_role
- **FIX PLANNED**: Use `esign_role` on `contact_types` table to filter contacts based on template's `signing_parties`
- Manual add always available (no filter)
- Status: BROKEN — needs contact filtering + rental property support

### Step 4: Details
- Sales fields or rental fields based on template context
- **BUG #2**: 3-layer context detection has priority flaw. If template has generic roles (owner_party/acquiring_party) AND template name contains 'authority'/'sell'/'mandate', Layer 3 fallback incorrectly returns sales context even for rental properties. Layer 2 (property source) should take precedence but doesn't.
- Status: BROKEN — rental docs show sales fields

### Step 5: Fill & Review
- Agent fills template fields, reviews document preview
- Status: WORKING

### Step 6: Sign & Send
- FICA required toggle (default ON) — stored on signature_request
- Delivery mode selection (filtered by template's allowed_delivery_modes)
- Agent signs
- Send to recipients
- Status: WORKING

---

## 5. Signing Flow

### Agent Signing
- Agent signs first in the wizard
- Signature captured via canvas (draw) or typed name
- All signature/initial markers assigned to agent are rendered
- Status: WORKING

### External Signer Flow
1. Recipient receives email with signing link
2. Opens link → SigningController::show()
3. **FICA Gate**: If `fica_required=true` on the signature_request:
   - Check the shared gate `FicaGate::isOpen()` — a FicaSubmission in (`submitted`, `under_review`, `agent_approved`, `referred_to_co`, `approved`)
   - If NO → show fica-gate.blade.php with "Complete FICA Form" button
   - If YES → proceed to signing (gate lifts on SUBMISSION, not approval)
4. Document renders with signatures applied so far
5. Recipient signs their assigned blocks
6. Completion → returns to agent for review

### Agent Approval Gate

Two distinct checkpoints, by design:

**a) Between-recipient — PASS-THROUGH on a clean accept (Elize Ruling #1).**
A recipient who signs with NO flag and NO strikeout/amendment hands the pen STRAIGHT to
the next recipient — the agent is NOT a checkpoint between every signer (friction with no
decision in it). The agent is pulled back mid-ceremony ONLY when a flag/strikeout raised a
PENDING amendment. `SignatureService::handlePartyCompletion()` clean-accept branch.

**b) FINAL agent-review gate — ALWAYS, at the very end (AT-322, epic AT-322).**
When the **last** recipient completes a **clean electronic** document, it is HELD at
`pending_agent_approval` instead of self-finalising. It lands in the agent's **"Needs Your
Approval"** (My Documents `pending_approval` bucket). The agent reviews the finished document
and clicks **Review & Approve** (`SignatureController::approve()` → `approveAndAdvance()` →
`completeDocument()`), and ONLY THEN does the document:
- generate + **auto-file** the PDF (`autoFileSignedDocument`), and
- **email the completion notice** to the recipients (`sendCompletionEmails`).

Both the file and the recipient completion emails live INSIDE `completeDocument()`, so holding
the finalize holds BOTH — nothing files and no recipient completion email is sent before the
agent approves. This is the single source of truth: a clean fully-signed doc must never
self-complete/self-file/self-email without the agent's final review.

Implementation: `SignatureService::advanceToNextParty()` takes `$gateFinalizeForAgentReview`;
the recipient clean-accept caller passes it (web-only — `signing_method !== 'wet_ink'`), and the
finalize branch routes to `holdForFinalAgentReview()` (status + audit `final_clean_complete` +
in-app agent notification) instead of `completeDocument()`. The AGENT-approval path
(`approveAndAdvance`) is unaffected and completes/files/emails as normal.

**Wet-ink: EXEMPT / unchanged** — a wet-ink completion already passes through the agent's own
upload-review, which serves as the agent approval, so it completes as before (not re-gated).
**OPEN QUESTION (not ruled): should wet-ink also route through this final gate?**

- Agent can return to party with notes
- Also handles candidate practitioner flow (awaiting_supervisor, awaiting_supervisor_final)
- Status: WORKING (final gate re-added under AT-322)

### Delivery Modes
| Mode | How It Works | Status |
|------|-------------|--------|
| E-Sign | Web-based signing flow (as above) | WORKING |
| Wet Ink | Document posted to secure portal, printed, signed physically, scanned | WORKING |
| Download Only | PDF generated, agent downloads for manual handling | WORKING |

---

## 6. Signature/Initial Rendering

### Four Systems (Historical)
The codebase has four separate mechanisms that produce signature elements:

1. **HTML @include sig-lines** — CDS compiler inserts `@include('signature-line')` per party in the blade template
2. **Signature Zones** — bounding boxes drawn in setup, expanded to markers via `expandZone()`
3. **Signature Markers** — precise positioned overlays (x%, y%, page) rendered in signing views
4. **Inline sig-line includes** — signature sections rendered in the HTML document itself

### How They Interact
- CDS compiler (step 1) generates the static signature sections in the document HTML
- Zones (step 2) are drawn by the agent in setup and converted to markers
- Markers (step 3) are rendered as floating overlays on top of the paginated HTML
- All three must align to the same coordinate system (pageContainer locked to 210mm width)

### Coordinate System
- pageContainer locked to `width:210mm; max-width:100%` in all three views:
  - setup.blade.php:300
  - sign.blade.php:190
  - external/sign.blade.php:411
- Markers placed before the 210mm width fix may be slightly mispositioned
- New markers placed after the fix are consistent across all views

### Identity-Scoped Marker Interactivity + Never-Clobber-Signed (ESIGN-WETINK, AT-300)

E-sign replicates a real hard-copy pack: what recipient 1 SUBMITS after signing is
EXACTLY what recipient 2 opens and works on — recipient 1's marks present and
**locked**. The canonical artifact already accumulates every signer's ink
(`CanonicalInkComposer::bakeInk` writes each signer's captures back into
`canonical_html` by `data-name` / `data-recipient-identity`). The recipient signing
view (`resources/views/docuperfect/signatures/external/sign.blade.php`) must render
that canonical faithfully. Two rules govern the client-side marker/initial
interactivity (`_makeWebElementsInteractive`, `_makeWebInitialsInteractive`):

1. **Ownership is decided by signer IDENTITY, never by bare party role.** A marker
   is "mine" iff — in this priority — `data-name === signer_name`, else
   `data-recipient-identity === currentRoleIdentity`, else (only for un-stamped
   single-block templates) the party-role alias fallback (`isMyWebSigBlock`). This
   is the exact JS mirror of `CanonicalInkComposer::markerBelongsToSigner`
   (see `_isMyMarker`). Deciding by bare party role let recipient 2 (seller_2)
   claim recipient 1's (seller_1) same-role positions — offering them as
   "Click to sign" AND mislabelling recipient 2's own suffixed block as
   "Awaiting seller 2". For an N-same-role pack (2 sellers), `data-name` is the
   reliable per-person key — the markers are name-bound, not identity-stamped.

2. **Never clobber a signed marker.** Before making any marker interactive, a
   marker already carrying baked ink — `data-signed="true"` OR an embedded
   `img.web-sig-signed-img` / `img.corex-ink` (see `_isMarkerSigned`) — is left
   EXACTLY as the canonical composed it: no innerHTML overwrite, no dimming, not
   counted as outstanding. This holds for a prior recipient's ink AND the current
   signer's own already-signed blocks (e.g. after refresh). Only the current
   signer's UNSIGNED markers get the "Click to sign / Click to initial" prompt.

Result: recipient 2 opens the pack and sees every completed mark from recipient 1
and the agent baked and locked inline, with ONLY their own outstanding markers
actionable. (Ceremony "Thus done and signed" fields were identity-scoped earlier in
fd9cf176 by the same rule; these two render paths were the remaining bare-role
holdouts — AT-300 closed them.)

**Page-break INITIAL boxes are a special case (`_isMyInitialBox`).** Unlike
signature markers, per-page initial boxes are built CLIENT-SIDE at pagination
(`_buildInitialsRow` in `partials/a4-page-styles.blade.php`) and carry ONLY
`data-marker-party` — no `data-name` / `data-recipient-identity`. Their party is the
`parties_json` role, where a role's FIRST instance is the bare role (`seller`) and
later instances are suffixed (`seller_2`). So `_isMyMarker`'s bare-role fallback
`isMyWebSigBlock` breaks for recipient 2: their box is `seller_2` while their
`signerRole` is `seller` (suffix mismatch) → their own initial is greyed, AND the
bare `seller` box could be claimed by both sellers. `_isMyInitialBox` resolves the
box's party to a role_identity (`{role}_{n}` stays; a bare `{role}` → `{role}_1`) and
matches SOLELY on `currentRoleIdentity` — so each signer's own unsigned box is
"Click to initial", prior signers' stay locked, and no same-role sibling is claimed.
(When a box carries explicit identity/name keys those win; when the viewer has no
identity — single-recipient/pre-identity docs — it falls back to the bare-role check.)

---

## 7. PDF Generation

### Process
1. Document HTML (merged_html with signatures embedded) → temp HTML file
2. Puppeteer/Chromium renders HTML → PDF via generate-pdf.sh
3. Zoom: 0.82, correct margins, 3-page output matching web view

### Two PDF Types
- **Internal PDF**: Full document with audit certificate
- **Client PDF**: Document without internal audit data
- Both generated by SignaturePdfService::generateFromHtml() (line 99-157)

### BUG #5: Initials Missing from Final PDF
- SignaturePdfService.php:298-301 explicitly skips initials: `if (in_array($type, ['signature', 'initial'])) { continue; }`
- Comment says "handled by signature markers" but marker rendering depends on Alpine.js state that doesn't persist through Puppeteer
- Fix: render initial markers into the HTML before PDF generation, same as signatures

---

## 8. Document Filing

### Auto-Filing on Completion
- `autoFileSignedDocument()` in SignatureService.php:1749
- Splits merged HTML at `.corex-document-wrapper` boundaries
- Generates individual PDF per template in a pack
- Creates separate `Document` records with correct `document_type_id`
- Links to contacts and property via `document_contacts` and `document_properties` pivots

### BUG #4: Duplicate Entry on document_contact
- SignatureService.php:1723-1730 uses `DB::table()->exists()` check followed by `->insert()`
- This is a race condition — concurrent requests can pass the exists() check simultaneously
- Fix: replace with `updateOrCreate()` or `syncWithoutDetaching()` (the latter is already used correctly in linkFiledDocumentToContactsAndProperty at line 1988)

---

## 9. FICA Integration

### FICA Gate in E-Sign
- Wizard Step 6: "FICA verification required" checkbox per recipient (default ON)
- Stored on `signature_requests.fica_required`
- At send time: auto-creates FicaSubmission for contacts without approved FICA
- Gate checks status IN (`submitted`, `under_review`, `agent_approved`, `referred_to_co`, `approved`) via `App\Services\Compliance\FicaGate` (2026-10-08: the one shared rule, also used by rentals)
- Gate lifts on SUBMISSION (not approval) — recipient has done their part
- Contact FICA status only updates on final CO approval
- Return-to-signing flow after FICA completion (hidden return_url field)

### FICA Compliance Module (Complete)
- Public token-based form covering all 4 SA FICA schedules (natural, company, trust, partnership)
- Two-stage approval: Agent review → CO review
- TFS screening with embedded FIC iframe + per-field copy buttons
- RMCP Version 4 viewable in-system (all 28 sections + schedules)
- PDF certificate generation
- Compliance dashboard with CO queue, tabs, pipeline indicator
- esign_role on contact_types for future wizard filtering

---

## 10. Amendment/Flag System

### Status: BACKEND COMPLETE, PARTIALLY WIRED

| Component | File | Status |
|-----------|------|--------|
| DocumentAmendment model | DocumentAmendment.php | Complete — types: addition/strikeout/modification, statuses: pending/accepted/rejected, soft deletes |
| Amendment review view | amendment-review.blade.php | Built — accept with initial, reject with reason |
| Accept/reject endpoints | SigningController | acceptAmendment(), rejectAmendment() methods |
| Routes | web.php:1245-1251 | GET amendment-review, POST accept, POST reject |
| Clause flags | SigningController:1061-1065 | Stored in web_template_data['clause_flags'] |
| Section reject | rejectSection() route | Allows rejecting individual document sections |

### What's Wired
- "Other Conditions" text changes auto-create amendments
- Amendment → notification → review → accept/reject with initials works for Other Conditions

### What's NOT Wired
- Clause flags are collected but do NOT create amendments
- No manual amendment trigger for agents
- Per the Act: every added condition requires initials from ALL parties — this needs verification

---

## 11. Editable at Signing

### For CDS Web Templates: BUILT, NO TEMPLATE DATA
- Infrastructure works: `field_mappings[].editable_by` → `getEditableFieldsFromMappings()` (SigningController:1197-1235) → JS converts fields to `<input>` (sign.blade.php:1865-1895)
- Role mapping correct: seller→owner_party, buyer→acquiring_party
- **HOWEVER**: No template currently has `field_mappings.editable_by` populated
- System falls back to `WebTemplateFieldPartyMap` static map
- Status: Infrastructure works but is UNTESTED IN PRODUCTION because no template uses it

### For Legacy PDF Templates: LIMITED
- `fields_json[].assignedTo` only controls who FILLS the field
- No "editable at signing" checkboxes exist in legacy editor
- Non-agent parties see fields as read-only

### "Other Conditions" Field
- Should be editable by recipients to add conditions
- Will work once editable_by is populated on the template's field_mappings
- Currently falls back to static map behaviour

---

## 12. Email Notifications

### Rules
- **Agents receive ZERO emails** — all notifications are in-app (database notifications via NotificationController + bell icon)
- **External signers receive exactly TWO emails**:
  1. Signing request (with "Sign Document" button)
  2. Completed PDF (after all parties signed)

---

## 13. Contact Filtering

### Current Behaviour (BROKEN)
ESignWizardController:491 loads ALL contacts from property via `$prop->contacts`. No filtering by template context, signing_parties, category, or contact type. Additionally, auto-populate only works for sales properties (propertySource === 'properties') — skips rental_properties entirely. Search API at line 949 uses hardcoded role map instead of esign_role.

### Solution: esign_role on contact_types
- New column `contact_types.esign_role` (varchar: seller/buyer/lessor/lessee/null)
- Set via dropdown in Settings → Contact Types
- Migration auto-seeds existing types based on name matching
- **NOT YET WIRED INTO WIZARD** — ContactType::scopeForEsignRole() exists but is never called

### Filter Logic (To Be Built)
```
Template signing_parties contains 'owner_party' →
  Show contacts where contact_type.esign_role IN ('seller', 'lessor')

Template signing_parties contains 'acquiring_party' →
  Show contacts where contact_type.esign_role IN ('buyer', 'lessee')

Template signing_parties is null →
  Show all contacts (legacy fallback)

Must also work for rental_properties, not just properties.

Manual add → always available, no filter
```

---

## 14. Known Bugs (Audit-Verified March 27, 2026)

| # | Bug | Root Cause | Status | Fix |
|---|-----|-----------|--------|-----|
| 1 | All contact types show on all templates | Wizard:491 loads all contacts, no filter. Also skips rental_properties. | OPEN | Wire esign_role filter + add rental property support |
| 2 | Rental documents show sales fields | Context detection Layer 3 fallback overrides Layer 2 (property source). Template name patterns force sales context. | OPEN | Fix layer priority: property source > name pattern |
| 3 | My Documents menu error | Route and controller exist but may be transient/data issue | UNCLEAR | Needs browser testing |
| 4 | Duplicate entry on document_contact | Race condition: exists() + insert() at SignatureService:1723-1730 | OPEN | Replace with updateOrCreate or syncWithoutDetaching |
| 5 | Final PDF missing initials | Explicit skip at SignaturePdfService:298-301 | OPEN | Render initials into HTML before PDF generation |
| 6 | Ellie bubble overlaps Next button | CSS: position:fixed bottom:24px right:24px z-index:9999 | OPEN | Reposition Ellie |

### Resolved
| # | Bug | Resolution |
|---|-----|-----------|
| 7 | Memory allocation on docuperfect_documents | FIXED — DocumentController uses ->paginate(20) |
| 8 | Wrong email (elizesouthbroom) | DATA FIX — Agent's email wrong in users table on staging, not a code bug |
| WP1 | Web pack: Addendum B signature block never presented to signer | Pack merge concatenated independently-rendered template HTML; segments kept inconsistent data-marker-party keys (lessor vs seller); signer's scan skipped non-matching segments | FIXED — pack-merge loop given single-doc parity (party_names, recipients_by_role, resolveSignatureNames per segment); normalizePackMarkerParties() re-keys all segments to canonical recipient roles; SignatureSurfaceNormalizer made per-wrapper. Commit bab6623 |
| WP2 | Web pack page counter showed template count, not real page count | $pageCount = count(template_ids); paginateDocument() never wrote real count back to totalPages | FIXED (pack-wide) — _syncTotalPagesFromPagination() added; SUPERSEDED by §19 which makes numbering per-document. Commit bab6623 |

---

## 15. What's Working Well (Audit-Confirmed)

- Full 6-step wizard flow
- Agent signing with ceremony fields
- Client-side A4 page pagination
- External signer view (gateway → signing)
- Agent approval gate (pending_agent_approval) including candidate practitioner flow
- Signature embedding into merged HTML
- CDS-to-blade compiler with multi-party signature extraction
- Pack splitting into individual documents on filing
- Three delivery modes (e-sign, wet ink, download)
- FICA gate on signing (lifts on submission)
- FICA compliance module (complete: form, two-stage approval, TFS, RMCP, PDF)
- Notification bell system
- Template management with full settings
- Document type management
- Legal e-sign block (triple-enforced)
- 210mm pageContainer consistency across all views

---

## 16. Parked Features

| Feature | Status | Notes |
|---------|--------|-------|
| Upload signature option | Specced | Third capture mode alongside draw/type |
| Staff FICA verification | Specced | Training records, acknowledgement flow, 12-month cycle |
| Amendment/flag full flow | Backend complete | Only Other Conditions wired. Clause flags inert. |
| Editable at signing | Infrastructure built | No template has field_mappings.editable_by populated |
| Signature upload (image) | Specced | In V2 spec as two-layer approach |
| Registered radio/option CDS field type | Parked (post-demo) | Option 1 of the SALES ADDENDUM B fix: a real registered radio/option field type with `field_mappings` entries + CDS builder support. Correct root-cause fix but a larger CDS-compiler change. Demo uses Option 2 (the `.corex-disclosure-checklist` markup + the existing client converter) instead. |

---

## 17. Build Priority (When Resuming)

1. **Bug #4: Duplicate entry** — replace insert()+exists() with updateOrCreate at SignatureService:1723-1730
2. **Bug #5: Initials in PDF** — render initial markers into HTML before Puppeteer
3. **Bug #1: Contact filtering** — wire esign_role into wizard Step 3 + rental property support
4. **Bug #2: Rental fields** — fix context detection layer priority
5. **Bug #6: Ellie position** — CSS reposition
6. **Verify amendment flow** — test Other Conditions end-to-end
7. **Populate editable_by** — set field_mappings on at least one CDS template to test
8. **Staff FICA training** — build when capacity allows

---

## 18. Hard Rules

- Every VS Code prompt starts with: read CLAUDE.md, .ai/STANDARDS.md, and this spec
- Every prompt ends with: php -l, view:clear, dev-check.ps1, Tinker verification
- No quick fixes — investigate first, report findings, get approval, then fix
- When Johan reports a bug, the code is wrong. Never suggest user error.
- No hard deletes anywhere. Soft deletes only.
- Commit HFC2402 to main and push after every session.
- DO NOT remove working code without full dependency trace.

---

## 19. Per-Document Pagination, Initials & Numbering — APPROVED

> Status: **APPROVED** (Johan, March 2026). Build may proceed on a dev branch.
> Size: **L**. Legally significant — initials and page numbering appear on
> every page of signed mandates/disclosures; Document-Fidelity rule applies.
> Supersedes the pack-wide page counter shipped in WP2 (§14): numbering is
> now per-document, not pack-wide.

### 19.1 Governing principle — a pack is an envelope, not a merge

A document pack combines multiple documents into ONE signing session and ONE
send, but it does **not** make them one document. Each document keeps its own
identity: its own page numbering, its own per-page initials, and its own
terminal signature block. The signer's experience must be identical to
receiving each document separately — exactly as with wet-ink documents handed
over as a set. This makes each filed document independently legally defensible.

Worked example (the canonical test case):

- **Doc 1** — 2 pages → page 1 of 2 (initial), page 2 of 2 (signature block, no initial)
- **Doc 2** — 5 pages → pages 1–4 of 5 (initial each), page 5 of 5 (signature block, no initial)
- **Doc 3** — 1 page → page 1 of 1 (signature block, no initial — it is both first and last page)

### 19.2 Per-document pagination & numbering

- Each `.corex-document-wrapper` paginates **within its own boundary**. A page
  never straddles two documents — doc 1 always ends its own last page, doc 2
  always starts its own page 1. There is no shared/pack-wide page index.
- Page numbering is **per document**: "Page X of N" where N is THAT document's
  page count, restarting at 1 for each document in the pack.
- This **revises** the WP2 fix: `_syncTotalPagesFromPagination()` (added in
  external/sign.blade.php and sign.blade.php) currently counts all
  `.corex-a4-page` elements pack-wide. It MUST instead count
  `.corex-a4-page` elements **within the current document-wrapper**, and the
  displayed "Page X of N" / prev-next bounds MUST reflect the document the
  signer is currently viewing. Single (non-pack) documents are unaffected —
  one wrapper, numbering 1..N as before.

### 19.3 Per-page initials placement

- **What:** every page of every document carries one initial slot per required
  signer, **bottom-right**, EXCEPT the document's last page.
- **Last page = signature page:** the last page of each document carries the
  signature block and **no initial slot**. A single-page document carries the
  signature block and no initial slot (it has no preceding page to initial).
- Rule, stated precisely: an initial slot is placed on page P of a document
  when `P < lastPageIndexOfThatDocument`. The signature block sits on page
  `lastPageIndexOfThatDocument`.
- **When:** at client pagination time, immediately after `paginateDocument()`
  builds the `.corex-a4-page` elements (a4-page-styles.blade.php:~87; called
  from external/sign.blade.php:~1382 & :~1959, sign.blade.php:~690).
- **How:** `_buildInitialsRow()` already exists in a4-page-styles.blade.php
  (~:340) and Strategy-2 already calls it (~:318). PRE-BUILD CHECK: confirm
  whether Strategy-2 is already injecting initials rows in current production
  documents — if so, the build must not double-inject. Strategy-1 and the
  external/agent re-pagination paths must call it consistently, and the
  last-page-exclusion rule above must be applied to ALL paths.
- **Party keys:** initial slots use the same canonical recipient role keys
  produced by `normalizePackMarkerParties()` (ESignWizardController, commit
  bab6623) so the initial scan matches the signer exactly as signatures do.
  Single (non-pack) documents must derive initial party keys from the SAME
  canonical recipient source, so a single-doc initial keys identically to a
  pack-segment initial.

### 19.4 Idempotent re-anchor

Re-pagination (content edit, zoom, font reflow) re-runs `paginateDocument()`.
Because each document paginates within its own wrapper, injection is keyed by
**(documentWrapperIndex, pageIndexWithinWrapper, party)**. On re-run it MUST:
- never duplicate an initials row,
- never lose an already-applied initial value (re-attach captured state by the
  key above),
- remove orphaned rows when a document's page count shrinks,
- correctly move the signature block if the last page of a document changes
  (e.g. doc 2 grows 5→6 pages: old page 5 gains an initial, new page 6 gets
  the signature block).

### 19.5 Interactivity (both signing views)

Injected initials become interactive for the current signer via the EXISTING
`[data-marker-type="initial"]` scan in BOTH external/sign.blade.php and
sign.blade.php (agent), including the existing **apply-to-all** affordance.
Apply-to-all fills every page-initial for that signer across all documents in
the pack in one action (intended — low-friction is the point). No new scanning
mechanism — reuse the initial-marker handler.

### 19.6 Completion gating

The "all items complete" gate MUST add, per document,
`(pages − 1) × requiredSigners` initial items plus the signature-block items,
summed across all documents in the pack. The document MUST NOT complete with
any blank page initial. The gate count MUST be derived from the SAME paginated
DOM the signer sees (per-wrapper page count), never a server estimate.

### 19.7 Persistence / PDF (BUG #5 history)

- Per-signer initials MUST be embedded into `merged_html` in the existing
  signature/initial embed step so the Puppeteer-flattened PDF carries them.
- **Page-count integrity (mandatory mechanism):** each document's PDF MUST be
  generated from that document's **exact signed-and-paginated DOM** — i.e. the
  `.corex-a4-page` elements the signer actually saw and signed are serialized
  and handed to Puppeteer. The PDF generator MUST NOT re-paginate or re-flow
  the content server-side. This guarantees the flattened PDF page count and
  per-page initials match what the signer saw and what the gate counted. Since
  `splitMergedHtml()` already splits per `.corex-document-wrapper`, it splits
  the already-paginated DOM — it does not re-flow it.
- History (§6 / BUG #5, SignaturePdfService.php:298-301): initials have been
  explicitly skipped in the PDF before — the build MUST verify per-page
  initials survive flatten and appear in both the internal and client PDFs.

### 19.8 Files in scope (build)

| Concern | File (approx) |
|---|---|
| Per-wrapper pagination + numbering | resources/views/docuperfect/signatures/partials/a4-page-styles.blade.php |
| Per-document counter (revise WP2) | external/sign.blade.php, sign.blade.php (`_syncTotalPagesFromPagination`) |
| Inject + re-anchor initials, last-page exclusion | a4-page-styles.blade.php (`_buildInitialsRow`, Strategy-1 & re-pagination paths) |
| Interactivity + gating (external) | external/sign.blade.php |
| Interactivity + gating (agent) | sign.blade.php |
| Embed into merged_html for PDF | SignatureController.php / SignatureService.php (signature/initial embed step) |
| PDF from exact paginated DOM | SignaturePdfService.php (~:298), generateFromHtml |
| Split keeps per-document pages | SignatureService.php splitMergedHtml (~:1911) |

### 19.9 Acceptance criteria

1. Each document in a pack numbers its own pages "Page X of N" restarting at 1
   per document; prev/next bounds match the current document.
2. Every page of every document shows one initial slot per required signer,
   bottom-right, EXCEPT each document's last (signature) page.
3. A single-page document shows the signature block and no initial slot.
4. Initial slots are interactive for the current signer; apply-to-all works
   across the whole pack.
5. Completion is blocked until every required page initial and every signature
   block, for every signer, across every document, is filled.
6. Re-pagination (add text) re-anchors with no duplicate rows and no lost
   applied initials; if a document's last page changes, the signature block
   moves to the new last page and the former last page gains an initial slot.
7. Completed `merged_html` carries every per-page initial; the flattened PDF
   (internal + client) shows them, and the PDF page count per document matches
   what the signer saw.
8. `splitMergedHtml()` output: each filed document retains exactly its own
   pages, numbering, initials and signature block (none lost, none cross-filed).
9. Single (non-pack) document flow is unchanged except for the new per-page
   initial footer rows.

### 19.10 Risks (must be addressed in build)

- Re-pagination must idempotently re-anchor without duplicating or dropping
  signed initials (state keyed by documentWrapperIndex + pageIndex + party).
- The signature block must follow the document's last page when page count
  changes — a stale signature block on a no-longer-last page is a defect.
- Puppeteer flatten has dropped initials before (§6 BUG #5) — the build MUST
  generate each PDF from the exact paginated DOM and explicitly verify.
- PRE-BUILD: confirm Strategy-2 is not already injecting initials rows in
  production, or the build will double-inject.
- DO NOT assume a setting is "dead" without checking every file that reads it.
---

## 20. Paper copies (download-and-sign, wet-ink) print one party block per party — HOTFIX 2026-10-19

**Rule:** a document with N parties of one role (N sellers, N landlords…) prints N address/contact
(domicilium) blocks on EVERY route — electronic, wet-ink and download-and-sign. Never assume one.

**Symptom (Johan, esign flow 37 / download 572):** electronic output and the step-6 preview showed a
domicilium block per seller; the downloaded PDF from wet-ink and download-and-sign showed ONE block with
the sellers' phones/emails joined ("A and B").

**Cause:** `ESignWizardController::downloadDocumentPdf` prints the stored document. For both paper routes
the stored `merged_html` is the raw template output — the `data-role-block` per-party loop
(`RoleBlockNormalizer::normalize` + `RoleBlockExpansionService::expandWithLooping`) only ever ran on the
step-6 preview and inside `CanonicalDocumentRenderer::compose()` (electronic + wet-ink canonical).

**Fix (two places, nothing else):**
- Download-and-sign (`prepareDownloadOnly`, no SignatureTemplate, no canonical): `merged_html` is now
  expanded when built, through `expandRoleBlocksForPaper()` — the same normalize + expand pair over the same
  recipients as the step-6 preview (`expandEntityRecipients` → `buildTransientSignatureRequestsForPreview`).
- Wet-ink (`prepareWetInk`): `merged_html` MUST stay un-expanded — `compose()` starts from it and expands it
  itself, so pre-expanding double-expands the canonical (proved: 2 sellers → 4 address blocks, 3 → 9).
  `downloadDocumentPdf` therefore prints the stored `canonical_html` when present, with the on-screen
  add-condition controls hidden (`.btn-add-condition`, `.condition-add-guidance`, `[data-screen-only]`,
  `.corex-propose-btn`). Documents without a canonical keep printing `merged_html`.
- Electronic path untouched: `compose()` output for existing e-sign documents is hash-identical before/after.

**Tests:** `tests/Feature/Docuperfect/SigningView/PaperCopyDomiciliumPerSellerTest.php` (2 and 3 sellers,
Exclusive and Open wording, 1 seller, no recipients).

**Known, not changed here:** a download-and-sign copy of the OPEN mandate prints the literal
`~~~~OTHER_CONDITIONS~~~~` marker (InsertableBlockRenderer never runs on that route); a wet-ink document's
confirmation-page preview still shows the un-expanded `merged_html`; documents already created keep their
stored output until re-created.

---

## 21. Paper routes: whole pack, one combined PDF, Fill & Review honoured — HOTFIX 2026-10-21

**Rule:** download-and-sign and wet-ink behave like the electronic route for what the agent configured: every
document of a Web Pack, in pack order, and every value typed or confirmed in Fill & Review. N parties, never one seller.

**Symptoms (Johan, QA1):** (1) a Web Pack on wet-ink / download-only gave only the FIRST document (QA1 flow 105 —
pack [77,74,76] — stored Document 630 with only the Open Authority); (2) District / Complex typed in Fill & Review did
not appear on the finished document.

**Cause:** `prepareDownloadOnly` / `prepareWetInk` rendered `$flow->template` (the pack's first member) and never
walked `step_data.template_ids` as `prepareSigning` does; and neither called `overlayFillReviewValues()`, so
`web_template_data['_fill_review_overlay']` was empty and yesterday's `applyFillReviewAuthoritativeOverlay()` call in
`expandRoleBlocksForPaper()` was a no-op. The paper routes also rendered the generated blade with a raw `view()`
(an untracked per-host file — a missing one is a 500 there while the preview regenerated it).

**Fix (`ESignWizardController` only):**
- `paperRouteTemplates()` — the pack members in pack order (a single document is a list of one); same skip /
  `assertAccessibleBy` rules as the electronic pack loop.
- `buildPaperSegment()` — ONE shared builder for both routes and every member: resolve → `overlayFillReviewValues`
  (scoped to the member via `_pack_template_id`) → render → inject values → role-block expansion for print.
  The generated blade comes from `WebTemplateBladeEnsurer::ensure()` (regenerates when missing; a real render failure
  still throws — a printed legal document never becomes a "preview" fallback).
- **One Document per pack member** (own name, `pack_instance_id`, `web_template_data.paper_pack = {instance_id,
  position, count}`), so an agent can pull e.g. the Mandatory Disclosure alone. Wet-ink: the FIRST document carries the
  pack's single SignatureTemplate / requests / canonical (unchanged machinery); the others are plain paper documents
  expanded for print. `step_data.pack_document_ids` lists them.
- **The agent's download is ONE combined PDF** of the pack in pack order: `downloadDocumentPdf`, the download page
  and the wet-ink confirmation page print `combinedPaperPackHtml()` (each document on its own page) when the
  document belongs to a paper pack; a single document prints exactly as before.
- The agent's legacy "Additional Conditions" block is added once per pack (first document), not once per member.
- Electronic path untouched.

**Tests:** `tests/Feature/Docuperfect/PaperRoutePackAndPropertyFieldsTest.php` (pack of 3 on both routes, combined
print order, 2- and 3-seller domicilium in a pack, stand-alone unchanged, District typed in Fill & Review on both
paper routes single + pack, missing blade regenerated).

**Known, not changed here:** pack members that are not web templates are skipped (as the electronic loop does); a
wet-ink pack still has ONE envelope (one set of recipient requests), not one per member.

---

## 22. Property fields fill from the Property record — HOTFIX 2026-10-21

**Rule:** a property field the wizard snapshot lacks is filled from the linked Property record; a value the snapshot or
Fill & Review holds is never overwritten. This is not a paper-route rule — District was blank on the electronic route
too, which is a bug, not behaviour to preserve.

**Cause:** the wizard saves only address, suburb, erf, complex, type and id for step 2, so `district`, `town`,
`street_name` and `unit_number` never reached `WebTemplateDataService` (QA1 property 6060 has District "Ray Nkonyeni";
its flow snapshot has none, so `property_district` resolved to "").

**Fix:** `WebTemplateDataService::propertyForResolve()` (used by `resolve()`, `resolveCdsTemplate()`, `resolveBase()`)
fills missing suburb / town / city / district / street_name / unit_number / complex_name / erf / property_type (and the
address when both address and title are blank) from `Property::find(property_id)` — `properties` source only, the
model's own agency scope applies. Fill & Review values are applied after `resolve()` and win over both.

**Proof (before/after, 48 real QA1 resolutions, data layer):** 30 differ; every changed field was blank and is now
filled (property_district, district, property_erf_number, erf_no, erf_unit_no, unit_no, complex_name,
property_complex_name, property_type) EXCEPT `property_town`, 8 documents: it was "Shelly Beach" (the suburb used as a
fallback) and is now "Margate" (the property's real town).

**Lease documents (#88, #92):** lessor / lessee address and lease end already resolve from the linked contacts and the
lease on lease flows (verified on flows 28, 32, 34, 35); nothing to fill. Lease agreements are e-sign only.

**Tests:** `tests/Feature/Docuperfect/PropertyFieldsFromRecordTest.php` (sectional record fill on both paper routes,
snapshot never overwritten, freehold blank and no bleed from another property, electronic route typed District + record fill).

---

## 23. Stand-alone Mandatory Disclosure carries the property header — HOTFIX 2026-10-21

**Symptom (Johan):** the sales Mandatory Disclosure (#81) opens with "…the immovable property situated at <address>";
the stand-alone Mandatory Disclosure (#74, a CDS import) had no property address anywhere, on any route.

**Fix:** `php artisan esign:add-property-header {template} [--apply]` (`AddPropertyHeaderToTemplate`) adds that clause
straight after the title as a BOUND Property Address tag — the CDS builder's own shape (a `doc-tag` span, `sf:property`
mapping to the Property Address named field), not typed text — in both `editor_state` and `field_mappings`, then rebuilds
the generated blade through `WebTemplateBladeEnsurer::regenerate()`. A template is data and its id differs per
environment, so this is a command, not a migration: dry-run by default, idempotent (marker
`data-corex-property-header`), refuses anything that is not a CDS "…Disclosure". It resolves like any property field, so
it shows on electronic, download-and-sign and wet-ink, stand-alone and inside a pack, and appears in Fill & Review.

**Run it on each environment** against that environment's stand-alone MDF id (QA1: #74).

**Report only (not changed):** #76 "ADDENDUM B" (CDS import, no document type) and #80 "Addendum B" (sales, reference
Addendum B) both record facts about "the property" (building plans, compliance certificates) and carry no property
address header — they are property documents without one.

**Tests:** `tests/Feature/Docuperfect/MandatoryDisclosurePropertyHeaderTest.php`.

---

## 24. Proxy / signing on behalf of — who signs for whom (AT-498, HOTFIX 2026-10-21)

**Rule in one line:** a person marked **Proxy** signs for the *other parties the agent ticks* — not automatically for everyone.
Parties who are not covered by a proxy stay full signatories. **Never assume one seller; never drop a party who is not covered.**
This section is the single written source of truth for the rule. The code is `App\Support\Docuperfect\ProxyCoverage`
(the only place the question is answered) and every route reads it.

### 24.1 Words used
- **Party / role group** — a recipient and the other recipients of the SAME role on the same document (sellers, buyers, landlords,
  tenants, any role; sales and rental alike). Roles never mix: a proxy among the sellers has no effect on the buyers.
- **Proxy** — a party who also signs on behalf of one or more other parties of their role (the agent ticks **Proxy — signs on behalf
  of other parties in this role** on that person's card in the Recipients step).
- **Represented party** — a party a proxy signs for. **Unrepresented party** — a party of the same role no proxy covers; they sign for themself.
- **Covered set** of a proxy = every other living, non-company party of the same role **except the ones the agent un-ticked**.
- A person can be a proxy **or** be represented, not both (a proxy always signs, so the picker does not offer a proxy, a deceased party
  or a company as someone to be signed for).

### 24.2 How the agent sets it (Recipients step)
1. Tick **Proxy** on a person. A picker **"Signs on behalf of:"** appears listing every other party of that role. **All start ticked**
   — that is exactly what a proxy always meant, so nothing changes unless the agent un-ticks someone.
2. Un-tick the parties the proxy does **not** sign for. **At least one must stay ticked** — a proxy for nobody is refused (24.8).
3. Below the picker the wizard shows the resulting recipient list **live**: *"A — signs for self and for B"*, *"C — signs for self"*.
4. A party added to the role later is covered by default (as a proxy always covered everyone) — un-tick them if they should sign themself.
5. **Step 6 (Signing Order) shows the real recipients before the agent presses Sign Document.** A party a proxy signs for is **not** listed as a
   signer or email recipient there: they collapse to one non-editable line under the proxy — *"Paula Partner — signed for by Peter Proxy (proxy)"* — and
   the proxy's own card is the one that signs. The numbers (1, 2, 3 …) and **Move up / Move down** run across the real signers only (the agent counts as
   the first); a party with no email who is covered by a proxy raises no "no email" warning, because nothing is sent to them. The same rule as 24.3 decides who is covered.
6. A company in the role: its signers are not offered in the picker. A proxy with **nothing un-ticked** covers them as it always did; once the
   agent un-ticks anyone (a partial proxy) the company's own signers keep signing for the company. The picker says so.

**Stored as** `recipients[]._proxy_excludes` — the list of parties the proxy does **not** sign for, each by its stable
`_recipient_local_key`. **Empty / absent = signs for everyone else** (the original behaviour; every existing proxy and every flow with no
proxy needs no change). Frozen on the signature request at send as `signature_requests.proxy_excludes` (JSON; migration
`2026_11_27_100200`) so the rule never depends on live data afterwards. A party with no stable key can never be un-ticked, so a partial proxy never covers it.

### 24.3 Who signs — the exact rule
A party is **covered** (displayed, but not asked to sign) when a proxy of the same role covers them: the proxy has no exclusions, or the
party is not in the proxy's exclusion list. A proxy, and everyone not covered, **signs**. A deceased party never signs (their replacement
mechanism is separate and untouched). Several proxies in one role are allowed; each covers its own people.
The rule exists once (`ProxyCoverage`) and is read by: the wizard's signing-participant filter (signature-block inputs), the role-block
expansion (which signature/initial/attestation blocks print), `SignatureRequest::nonSigningReason()` (who is sent a link), the
preview rows, and the renderer's initial-slot count.

### 24.4 How the document reads
- **Unrepresented party and the proxy:** their own plain name and ID, exactly as for any party.
- **Represented party:** their entry reads **"{name} (ID: …), herein represented by {proxy name} (ID: …) in terms of a proxy"** — in the
  "I/We …" party list, anywhere the document lists the role's parties, and in the Step 6 preview. A clause the party already has
  (a deceased party's "Late Estate of … herein represented by …", or a company's representation clause) is **never overwritten**.
- **The proxy's signature caption:** *"For self and as proxy for B"* (or *"… for B and C"* for several). A proxy who covers only B is never
  described as signing for C.
- **Order in the wording:** the proxy holder first, then the parties they sign for, then everyone else — *"Peter Proxy (ID…); Paula Partner (ID…), herein
  represented by Peter Proxy (ID…); and Sam Separate (ID…)"*. A proxy and the parties it covers form one group, placed where the earliest of its members stood
  (`ProxyCoverage::orderForWording`); everyone else keeps their place; no proxy → no reordering.
- **A saved party list never overrides the derived order.** Fill & Review saves the party-list text the agent saw (the `field_group_display` value). When a saved
  list holds exactly the same words as the freshly derived one — only the order differs, e.g. it was saved before the proxy order rule, or the agent changed who is
  proxy afterwards — the derived list is used on the Step 5 form, the Step 6 preview and every sent / printed document (`ProxyCoverage::preferFreshOrder`,
  `ESignWizardController::freshenStoredPartyLists` and `overlayFillReviewValues`). A list the agent genuinely edited (different words) is kept as typed.
- Partial vs full is just the length of the covered list: full proxy → every other party reads "herein represented by A"; partial → only
  the ticked ones do; the un-ticked party's entry is plain.
- Wording is produced in one place (`ProxyCoverage::withRepresentation`, applied by `WebTemplateDataService` for every route and the preview),
  never typed into a template.

### 24.5 Which blocks appear (per party)
| Block | Proxy (signs for self + covered) | Represented party | Unrepresented party |
|---|---|---|---|
| **Signature block** ("Thus done and signed by the Seller…") | **one**, in the proxy's own name (caption says "for self and as proxy for …") | **none of their own** | **their own** |
| **Initials** (margin / per-condition) | the proxy initials once (initials count = signers) | none | their own |
| **Domicilium / address-and-contact block** | their own details | **their own details still print** | their own details |
| **Attestation block** | the proxy's | not split out for them | their own |
| **Named in the party list / "herein represented by"** | plain name | "…, herein represented by {proxy}" | plain name |
A represented party is therefore still a party to the document (named, with address and contact details) but is not a *signatory*; an unrepresented
party is indistinguishable from a party on a document with no proxy at all.

### 24.6 Recipients, signing order and notifications
- **Every party gets a signature-request row** — nobody is dropped from the record. A covered party's row is created like any other
  (`isSigningParticipant()` = false, reason `proxy_collapsed`); when invitations go out the system skips it, marks it *not required* and logs
  `send_skipped_not_signing_participant` — it is never emailed a link and never blocks completion. Signers are *waiting → pending* as usual.
- In the example (A proxy for B only; C unrepresented) there are **2 people who sign**: A (for A and B) and C (for themself); B is a row
  that does not sign.
- **Wherever recipients are listed, a represented party is not one.** Step 6 (24.2 point 5) and the wet-ink confirmation screen list only the people who
  sign / are sent something; each represented party is one line under their proxy ("… signed for by … (proxy)"). On the wet-ink screen a represented party
  never holds the document in "waiting for recipient" — completion looks only at the people who sign.
- **Signing order** is unchanged (agent → acquiring party → owner party → witness, SA convention; the agent's per-document order within a role).
  A proxy signs at their own position; a covered party has no position of their own. Completion waits for exactly the signers.
- Signing-participant status is frozen on the row (`is_proxy`, `proxy_excludes`, `recipient_local_key`), so a later edit to a contact never changes who was required.

### 24.7 Behaviour on every route (single documents and packs)
| Route | What changes | Where |
|---|---|---|
| **Electronic** | requests built for all parties; covered ones *not required*; signature/initial/attestation blocks only for signers; wording per 24.4 | `prepareSigning` |
| **Wet-ink** | same request rows (one envelope); printed copy carries the signers' blocks, every party's domicilium, and the wording | `prepareWetInk` |
| **Download-and-sign** | no requests; printed document carries the same blocks and wording as wet-ink | `prepareDownloadOnly` |
| **Packs** | the SAME recipients and the SAME rule apply to every document of the pack (each member prints/blocks for the same signers); wet-ink pack = one envelope on the first document | all three, per pack member |
| **Step 6 preview** | shows the same wording and blocks the sent document will carry | `WebTemplateDataService` + preview rows |
No route has its own proxy logic.

### 24.8 Validation
A proxy who signs for nobody (everyone un-ticked, or the only person in their role) is **refused on all three routes** with
*"{name} is marked as a proxy but signs for nobody. Tick at least one other party they sign for, or untick Proxy."* (422 / validation error;
nothing is created). The wizard also shows this beside the picker and the live list.

### 24.9 Proof of authority (power of attorney / resolution) — **PENDING, not built**
**Decision (Johan, 21 Oct 2026): left OUT of this hotfix; Johan is asking whether it is wanted.** Today the proxy flow captures **no** proof
document (the existing POA link is only the company-representative relationship on a contact, `contact_representatives`). If it is wanted,
the intended shape is one proof upload **per represented party** (a `Document` row attached to the represented contact and the property, linked
from that party's entry on the proxy's card), required or optional by agency setting, shown on the proxy's signing screen. Nothing in 24.1–24.8 depends on it.

### 24.10 Existing flows are unchanged (proved)
- **No proxy:** `ProxyCoverage::withRepresentation` and `violation` are no-ops; the signer list is the full list — identical to before.
- **Proxy with no exclusions (every proxy that exists today):** covers everyone else — identical to the old rule. Proved by
  `ProxyCoverageRuleTest::test_every_existing_flow_resolves_identically_to_the_old_rule` (the old function reproduced verbatim and compared over
  more than 1,000 generated recipient sets: up to 5 parties, two roles, plain / proxy / deceased, with and without keys), and against every flow on QA1.
- Company (entity) proxies keep their own mechanism (`_entity_proxy_contact_id`, `EsignRecipientPreset` wording) untouched.

### 24.11 Tests
`tests/Feature/Docuperfect/SigningView/ProxyCoverageRuleTest.php` (the rule: legacy equivalence, 3 parties partial / full / none, 2–6 parties,
sales and rental roles, two proxies in one role, keyless and company rows never dropped, wording, refusal) and
`PartialProxyRoutesTest.php` (electronic, download-and-sign, wet-ink and a pack: who gets a request, whose signature blocks print, one domicilium per
party, wording, exclusions frozen on the row, refusal on every route, existing shapes unchanged).

---

## 25. Template markers never print on a paper copy — HOTFIX 2026-10-21

**Rule:** a `~~~~MARKER~~~~` in a template (clause 2.8 "Other: `~~~~OTHER_CONDITIONS~~~~`" of the Exclusive Authority To Sell, the Open mandate, the
Mandatory Disclosure…) is replaced when the document is prepared. A paper copy **never** prints the tildes: an unfilled marker is **blank**; when the agent
entered conditions in Fill & Review they print there. Electronic output is not touched.

**Symptom (Johan, QA1):** `~~~~OTHER_CONDITIONS~~~~` printed in clause 2.8 — on the download-and-sign copy, and on the wet-ink confirmation screen (which also
showed all sellers merged into one block).

**Cause:** only the signing renderer (`CanonicalDocumentRenderer::compose` → `InsertableBlockRenderer`) fills markers. A download-and-sign document, and the
non-first documents of a wet-ink pack, are never composed, so the marker stayed in `merged_html` and printed. The wet-ink confirmation screen displayed the raw
`merged_html` (un-composed: marker present, sellers merged) instead of what the PDF prints (the composed `canonical_html`). The PDFs of an electronic and of a wet-ink
document were already correct.

**Fix (`ESignWizardController`, paper routes only):**
- `resolvePaperConditionMarkers()` runs when a paper document's body is built (download-and-sign, and wet-ink pack documents that are not composed): `OTHER_CONDITIONS`
  (same tolerance as the signing renderer — tags, case, spacing, a `__DOCKEY` suffix and a typo or two ignored) → the agent's conditions: this pack member's Fill & Review
  frames (`other_condition_frames`; `target_doc_index` picks the document, none = the first), else the legacy `other_conditions_text` on the first document; a single
  condition prints as written, several are numbered "1. … 2. …"; text is escaped (never markup). Every other marker (`INCLUDED_ITEMS`, `EXCLUDED_ITEMS`, `CUSTOM:…`, unknown)
  and an `OTHER_CONDITIONS` with nothing entered → blank.
- The first wet-ink document keeps its marker in the stored raw body (compose starts from it) — unchanged; its canonical is filled by the renderer as before.
- `paperPrintHtml()` — what the PDF, the download page and the wet-ink confirmation screen show — is the composed canonical when there is one; a stored document without a
  canonical has any marker blanked, so no surface can print a literal marker.
- The wet-ink confirmation screen now shows what the PDF prints (per-party domicilium blocks, filled clause 2.8) instead of the raw body.

**Tests:** `tests/Feature/Docuperfect/PaperOtherConditionsMarkerTest.php` (blank when empty; text printed once; escaped; numbered; pack members get only their own frames;
misspelt / included-items / custom / HTML-wrapped markers; wet-ink first document composed + confirmation screen; no-canonical fallback).
