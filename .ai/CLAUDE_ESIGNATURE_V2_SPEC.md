# CoreX OS — E-Signature V2 Complete Specification

> Status: APPROVED — Master spec. All development must follow this document.
> Module: E-Signature
> Dependencies: Template Management, Packs, Contacts, User Designations
> Legal references: ECTA s13, POPIA, FICA (FIC Act 38/2001),
>   Alienation of Land Act 68/1981, PPRA Code of Conduct,
>   Property Practitioners Act 22/2019 (candidate practitioner rules)
> Replaces: All previous e-sign spec versions

---

## 1. Legal Boundaries

### What CAN Be E-Signed
- FICA declarations and consent forms
- Mandates (letting, sole, marketing)
- Mandatory disclosure forms
- POPIA consent forms
- Lease agreements (under 10 years)
- Rental applications
- Inspection reports
- Any internal agency document

### What CANNOT Be E-Signed
- Agreement for alienation of immovable property (sale agreement / OTP)
- Lease agreements over 10 years
- Wills and codicils
- Bills of exchange

CoreX must NEVER allow a sale agreement to enter the e-sign pipeline.
Template types flagged as "sale_agreement" are blocked at the wizard level.

---

## 2. Two Document Channels

**Channel 1: Create Document (existing, unchanged)**
Agent creates, fills everything, downloads/prints. No signing flow.

**Channel 2: Create E-Signature Document**
Wizard-based flow. One session from creation to signing.

---

## 3. The E-Signature Wizard

Each e-signature document is a FLOW. The wizard steps:

1. **Template** — select template or pack
2. **Property** — select/search property (auto-fills property fields)
3. **Recipients** — select contacts, assign roles (auto-fills contact fields)
4. **Details** — fill rental/sale details (price, dates, commission)
5. **Fill & Review** — fill remaining fields, review document preview
6. **Sign & Send** — define signing order, agent signs first, send

### Field Groups
Fields can be grouped (e.g. "Seller Name + Surname + ID"). A grouped field
renders once per recipient of that role in the format:
`Johan Reichel (ID: 7805125012083) and Steve Jobs (ID: 6901015800081)`

---

## 4. Signing Chain

### Unlimited, User-Defined Order
Not hardcoded roles. Agent adds signers in any order:

```
Signer 1: Agent (signs now)
Signer 2: Seller 1 — James Van Der Merwe (signs after agent approval)
Signer 3: Seller 2 — Steve Jobs (signs after Seller 1 + agent approval)
Signer 4: Tenant (deferred — sign later when found)
```

- 1 signer or 7 signers — system doesn't care
- Each signer only receives doc AFTER previous completes AND agent approves
- Agent defines the chain during wizard step 6
- Multiple people per role get unique keys: seller, seller_2, seller_3

### Data Model
```json
{
    "signing_chain": [
        { "order": 1, "role": "agent", "name": "Johan Reichel", "email": "...", "status": "completed" },
        { "order": 2, "role": "seller", "name": "James Van Der Merwe", "email": "...", "status": "pending" },
        { "order": 3, "role": "seller_2", "name": "Steve Jobs", "email": "...", "status": "waiting" },
        { "order": 4, "role": "tenant", "name": null, "email": null, "status": "deferred" }
    ]
}
```

### Status Values
- `completed` — signed and approved
- `pending` — link sent, waiting for signature
- `waiting` — in queue, will be sent after previous completes + agent approves
- `deferred` — parked, no details yet, resume later
- `pending_agent_approval` — party has signed, agent must review before advancing

---

## 5. Agent Approval Gate (CRITICAL BUSINESS RULE)

### The Rule
After EVERY external party signs, the document returns to the agent for review
and approval BEFORE going to the next party. No auto-advancing. Ever.

### Full Status Agent Flow
```
1. Agent creates + signs
2. → External Party 1 receives, fills their fields, signs
3. → Returns to Agent (status: pending_agent_approval)
4. → Agent reviews what Party 1 filled/signed
5. → Agent approves → sends to External Party 2
6. → External Party 2 fills their fields, signs
7. → Returns to Agent (status: pending_agent_approval)
8. → Agent reviews
9. → Agent approves → document complete (or next party)
```

### What the Agent Sees on Review
- Full document with all signatures applied so far
- What fields the external party filled in (highlighted)
- "Approve & Send to Next Party" button
- "Return to [Party Name]" button (with notes field)
- "Reject" button (cancels flow, notifies all parties)

### Dashboard Integration
"NEEDS YOUR APPROVAL" section at the top of the dashboard — above everything
else so it cannot be missed. Shows all documents in pending_agent_approval status.

### Agent Notification
When an external party completes signing, the agent receives an email:
"[Party Name] has signed [Document Name]. Review and approve to continue."

---

## 6. Candidate Practitioner Flow (PPRA COMPLIANCE)

### PPRA Requirement
Under the Property Practitioners Act 22 of 2019, candidate practitioners
cannot independently transact. All documentation produced by a candidate
must be reviewed and authorised by a full status property practitioner.

### Detection
CoreX detects candidate designation from the user profile/designations table.
Automatically injects supervisor approval steps. Cannot be disabled or skipped.

### Supervisor Assignment
- User profile field: `supervised_by` (FK to users)
- If not set, agency principal is the default
- Any full status practitioner can authorise (not just the principal)

### Candidate Flow Sequence
```
1. Candidate agent creates + signs
2. → Supervisor (full status) reviews + authorises
3. → External Party 1 signs
4. → Returns to Candidate agent (review)
5. → Candidate approves
6. [Repeat 3-5 for more parties]
7. → Candidate final authorisation
8. → Supervisor final sign-off
9. → Complete
```

### Supervisor Signing View
The supervisor sees:
- Full document pack
- Candidate's signatures already applied
- Banner: "This document was prepared by [Candidate Name], a candidate
  practitioner under your supervision."
- Their own signature blocks (role: supervisor)
- "Authorise" or "Return to Candidate" action

If returned: candidate receives it back with supervisor's notes,
can amend and resubmit.

### Supervisor Signature Zone
Templates include a supervisor zone with `party_role: supervisor`.
Only renders when flow is candidate-originated.
Hidden for full status agents — same template works for both.

---

## 7. Field Assignment

Every field has `assignedTo` property:
- `"creator"` — agent fills during document creation
- `"agent"` — agent fills during signing
- `"landlord"` / `"lessor"` — landlord fills during signing
- `"tenant"` / `"lessee"` — tenant fills during signing
- `"buyer"` / `"seller"` — for sales documents
- Any custom signer role from the signing chain

Role aliases (interchangeable): lessor = landlord, lessee = tenant

### External Party Field Completion
External signers fill fields assigned to them (bank details, addresses, etc.)
BEFORE signing. They cannot sign until all required fields assigned to them
are completed. After filling + signing, the document returns to the agent
for review (per Section 5 approval gate).

---

## 8. Signing Gateway — ID Entry Ceremony

### Purpose
Every external signer passes through the gateway before seeing documents.
Three legal functions:
1. Identity verification
2. Consent capture
3. Audit trail start

### Flow
```
Open link → Landing page (agency branded)
    ↓
Enter ID number → System validates against signing parties
    ↓
    ├── No match → "ID not recognised. Contact your agent."
    ├── Already signed → "You signed on [date]. Here's your summary."
    └── Match found → Show consent declaration
        ↓
    Read consent → Tick checkbox → Proceed
        ↓
    Render personalised document view
```

### Consent Declaration (needs legal review)
"By entering my identity number and proceeding, I confirm:
1. I am [Full Name] (ID: [masked, last 4 digits]).
2. I am acting of my own free will and have not been coerced.
3. I understand I am about to review and electronically sign legal documents.
4. My electronic signature carries the same legal weight as a handwritten
   signature under ECTA s13.
5. I consent to processing of my personal information per POPIA.

I have read and understood the above."

### Consent Data Captured
| Field | Value |
|-------|-------|
| flow_id | FK to flows |
| signing_party_id | FK to esign_signing_parties |
| id_number_entered | Encrypted |
| consent_text | Full declaration shown |
| consent_accepted_at | Timestamp (UTC) |
| ip_address | Request IP |
| user_agent | Browser user agent |
| device_info | Browser, OS, screen resolution |
| document_hash | SHA-256 of document at entry |

Consent records: NEVER deleted, NEVER editable. Retained minimum 5 years.

### Security Tiers
| Tier | Factor 1 | Factor 2 | Use Case |
|------|----------|----------|----------|
| Standard | Last 4 ID + DOB | None | Internal, low-risk |
| Enhanced | Last 4 ID + DOB | Email OTP | Mandates, leases, FICA |
| High | Last 4 ID + DOB | SMS OTP | High-value deals |

Templates set minimum tier. Agents can escalate, never downgrade.

---

## 9. Dynamic Signature Zones

### Bounding Box Model
Agent draws a rectangle on the template — that's the bounding box.
Picks a role (seller, buyer, landlord, tenant, agent, witness, supervisor)
and type (signature or initial). System renders blocks dynamically inside
the box based on how many parties of that role are on the flow.

- 1 seller = 1 block centred in the box
- 2 sellers = 2 blocks side by side
- 4 sellers = 2x2 grid
- System calculates layout from box dimensions and party count

### No Manual Duplication
The zone says "this is where sellers sign." The system handles how many.
No more "Seller 1 signs here" and "Seller 2 signs here" as separate zones.

### Initials
Same pattern — agent places one "Seller Initial" zone per page.
System renders one initial block per seller, stacked vertically.

### Overflow Warning
If the system calculates that N blocks won't fit in the box, it flags
during template setup: "Warning: more than 2 parties at this position
may overflow." Agent can reposition or resize.

### Additional Zone Placement at Signing Setup
After the wizard, on the signing setup screen, the agent can place
additional zones for ad-hoc needs (extra clause initials, witness
signatures, etc.). These use the same bounding box model.

---

## 10. Signature Block Rendering

### Final Signature Section
The template's signature section renders per-party with actual names:

For 2 sellers + 1 agent:
```
Signatures

Thus done and signed by the Seller at _____ on this _____ day of _____ 20__
at _____ am/pm.

┌──────────────────┐  ┌──────────────────┐
│ James Van Der     │  │ Steve Jobs        │
│ Merwe             │  │                   │
│ [sign here]       │  │ [sign here]       │
└──────────────────┘  └──────────────────┘
────────────────────  ────────────────────
Seller                Seller

Thus done and signed by the Agent at _____ on this _____ day of _____ 20__
at _____ am/pm.

┌──────────────────┐
│ Johan Reichel     │
│ [sign here]       │
└──────────────────┘
────────────────────
Agent
```

### Inline Signatures (Between Clauses)
Mid-document signing points where a party acknowledges specific sections.
Same rendering: one block per recipient of that role.

### Initials at Page Breaks
Auto-placed on every page except the last (signature page).
One initial block per signing party per page, bottom-right.

### Witness Rendering
Witness columns only render if witness is in the template's signing_parties.
Controlled by template config, not hardcoded.

---

## 11. Signing UI — Left Panel Flow

### Agent Signing (after setup)
```
┌──────────────────────┬──────────────────────────────────┐
│ SIGNING PANEL        │  DOCUMENT                        │
│                      │                                  │
│ YOUR FIELDS          │  Doc scrolls to show fields      │
│ ─────────────        │  as you complete them             │
│ Bank Name:   [____]  │                                  │
│ Account Nr:  [____]  │                                  │
│                      │                                  │
│ SIGNATURES           │                                  │
│ ─────────────        │                                  │
│ 0 / 4 completed      │                                  │
│                      │                                  │
│ Click markers on     │                                  │
│ document to sign     │                                  │
│                      │                                  │
│ [Complete & Send →]  │                                  │
└──────────────────────┴──────────────────────────────────┘
```

### External Signer View
Same layout. Shows only THEIR fields and THEIR markers.
Cannot see other parties' unfilled fields.

---

## 12. Section-by-Section Signing (Phase 2)

### Concept
Instead of "scroll through and sign at bottom":
1. Document divided into SECTIONS (defined at template level)
2. Each section gets an acceptance step
3. Signer sees: section content → "I accept this section" → initial
4. Progress through all sections
5. Final signature at the end
6. Option to reject a section with reason

### Section Definition
```json
{
    "sections": [
        { "label": "Parties & Property", "startPage": 1, "startY": 0, "endPage": 1, "endY": 50 },
        { "label": "Terms & Conditions", "startPage": 1, "startY": 50, "endPage": 2, "endY": 80 },
        { "label": "Special Conditions", "startPage": 2, "startY": 80, "endPage": 3, "endY": 40 },
        { "label": "Signatures", "startPage": 3, "startY": 40, "endPage": 3, "endY": 100 }
    ]
}
```

---

## 13. Deferred Signing (Sign Later)

For documents where a party isn't known yet (e.g. tenant on mandate):

1. During signing chain setup, agent marks signer as "Sign later"
2. Document is signed by known parties (with approval gates)
3. Status: "partial" — linked to property record
4. Document appears on property dashboard as "Awaiting tenant"
5. When tenant found: agent clicks "Resume signing"
6. Agent enters tenant name/email/cell
7. System picks up where it left off

### Property Dashboard
```
Property: 11/6877 Lot 14 Marburg Settlement
├── Marketing Permission    Agent ✅  Landlord ✅  Complete
├── Mandatory Disclosure    Agent ✅  Landlord ✅  Tenant ⏸ Deferred
└── Lease Agreement         Agent ✅  Landlord ✅  Tenant ⏸ Deferred
```

---

## 14. Other Conditions — Amendment Flow

### Template Setup
Templates include an "Other Conditions" zone — a text input area.
Any signing party can type conditions into it during signing.

### Amendment Detection
The moment a party types into the Other Conditions zone, the system
flags the document as amended. Document version increments (v1 → v2).

### Amendment Flow
```
1. Agent signs
2. → Landlord signs (no amendments)
3. → Tenant adds condition in Other Conditions, signs
4. → SYSTEM DETECTS AMENDMENT
5. → Routes back to Landlord: "Tenant added conditions. Review."
6. → Landlord reviews, accepts (re-signs conditions page), OR rejects
7. → Returns to Agent for final review
8. → Agent signs off
9. → Complete
```

### Per-Condition Initials (LEGAL REQUIREMENT)
Every condition added requires every party to initial individually.
Not one initial for all amendments — one initial per amendment per party.
System renders initial blocks dynamically next to each added condition.

### Amendment Tracking
| Field | Description |
|-------|-------------|
| flow_id | Which flow |
| amended_by_party_id | Who added conditions |
| amendment_text | The text entered |
| document_version | v1, v2, v3... |
| document_hash_before | SHA-256 before |
| document_hash_after | SHA-256 after |

Previous signatures on NON-AMENDED pages remain valid.
Only the conditions section requires re-signing.

---

## 15. Multi-Party FICA Document Rendering

### Core Rule
FICA is per-person. Every individual party gets their own FICA document.

### How It Works
Agent selects a pack containing mandate + FICA + disclosure.
Mandate = shared (all parties sign one document).
FICA = per-party (system duplicates, one per person, pre-filled from contact).

### Merged View
Client sees one continuous document with divider headers between each doc.
System stores individual documents separately for compliance.

---

## 16. Pack Flow (Multi-Document)

When launched from a pack, the wizard chains documents:
1. Property + contacts entered ONCE (step 1-2)
2. Details entered ONCE (step 3)
3. First doc: fill → sign → next doc
4. Second doc: carry forward → fill remaining → sign → next doc
5. All docs: signed, sent, linked to property

"Next: Mandatory Disclosure →" instead of "Done"

---

## 17. Three Delivery Modes

| Mode | When | Output |
|------|------|--------|
| E-Sign | FICA, mandates, leases, disclosures | Web signing pipeline |
| Wet Ink | OTP, sale agreements, ALA-restricted | PDF via secure portal |
| Download Only | Internal docs, reference copies | PDF download |

Templates define `allowed_delivery_modes`.
OTP locked to `['wet_ink', 'download']` — e-sign structurally blocked.

---

## 18. Email Chain

### Sequential with Agent Gates
```
Agent signs → system sends to Party 1
Party 1 signs → system notifies Agent (pending_agent_approval)
Agent reviews + approves → system sends to Party 2
Party 2 signs → system notifies Agent (pending_agent_approval)
Agent reviews + approves → complete (or next party)
```

### Email Content
- Styled HTML with "SIGN NOW" CTA button
- Links to /sign/{token} — public URL, no login required
- Token: 64-character random, 14-day expiry, single-use per party
- Agent notification: "[Party Name] has signed [Document]. Review and approve."

---

## 19. Template Wizard Configuration

Each template defines its wizard steps:
```json
{
    "wizard_steps": [
        { "key": "property", "label": "Property", "type": "property_selector" },
        { "key": "landlord", "label": "Landlord", "type": "contact_selector", "party": "landlord" },
        { "key": "rental_details", "label": "Rental Details", "type": "field_group" },
        { "key": "fill_review", "label": "Review & Fill", "type": "field_entry" },
        { "key": "sign_send", "label": "Sign & Send", "type": "signing" }
    ],
    "signing_parties": ["agent", "landlord"],
    "default_signing_order": ["agent", "landlord"],
    "sections": [...]
}
```

---

## 20. Ellie Integration

```
"New mandate for Hartley at Marburg"
→ Launches mandate wizard, pre-fills property + contact

"Send the lease to the new tenant Sarah Jones"
→ Finds deferred docs on property, resumes with tenant details

"Run the rental pack for unit 14"
→ Launches full pack flow, property pre-filled
```

---

## 21. Build Phases

### Phase 1 — E-Signature Wizard + Basic Signing (MOSTLY DONE)
- [x] Wizard layout, progress bar, step navigation
- [x] Template selection with is_esign filter
- [x] Property selector with auto-fill
- [x] Recipient selector with role assignment
- [x] Details step
- [x] Fill & Review with field groups + multi-recipient display
- [x] Signing chain setup (step 6)
- [x] Prepare-signing + redirect to setup screen
- [x] Individual party display (not grouped by role)
- [x] Dynamic signature block rendering per recipient count
- [ ] Agent signs all markers
- [ ] Agent approval gate after each external party
- [ ] Complete signing cycle end-to-end

### Phase 2 — Signing Gateway
- [ ] Gateway landing page (agency branded)
- [ ] ID validation endpoint (enhanced from current verify)
- [ ] Consent declaration display and capture
- [ ] Audit data capture (IP, UA, device, doc hash)
- [ ] Already-signed detection and summary screen
- [ ] Security tiers (standard/enhanced/high)

### Phase 3 — Dynamic Signature Zones
- [ ] Bounding box zone placement in template setup
- [ ] Party role dropdown (replaces numbered party system)
- [ ] Dynamic block rendering based on party count
- [ ] Layout algorithm (side-by-side, stacked, grid)
- [ ] Overflow detection and warning

### Phase 4 — Section-by-Section Signing
- [ ] Template section definitions
- [ ] Accept/initial per section flow
- [ ] Progress through sections
- [ ] Reject section with reason

### Phase 5 — Other Conditions & Amendments
- [ ] Other Conditions zone type on templates
- [ ] Text input rendering in signing view
- [ ] Amendment detection on submit
- [ ] Document versioning (v1, v2, v3)
- [ ] Per-condition initials (all parties)
- [ ] Re-signing notification to previous parties
- [ ] Accept/reject amendment flow
- [ ] Agent override capability

### Phase 6 — Candidate Practitioner Flow
- [ ] supervised_by field on user profile
- [ ] Auto-detection of candidate designation
- [ ] Auto-injection of supervisor approval steps
- [ ] Supervisor signature zone (hidden for full status)
- [ ] Pre-external and post-completion supervisor gates
- [ ] Return-to-candidate with notes

### Phase 7 — Deferred Signing
- [ ] "Sign later" option in chain
- [ ] Property document dashboard
- [ ] Resume signing flow

### Phase 8 — Pack Chaining
- [ ] Multi-doc flow from packs
- [ ] Carry forward property/contact/rental data
- [ ] One session for all docs
- [ ] Per-party FICA document duplication

### Phase 9 — Wet Ink Hybrid Flow
- [ ] Secure portal with download/upload cycle
- [ ] Version tracking per upload
- [ ] Amendment flagging on scanned changes
- [ ] Same signing gateway as e-sign

### Phase 10 — Template Wizard Config UI
- [ ] "Wizard Setup" tab on template editor
- [ ] Define steps, parties, sections
- [ ] Any template becomes a flow automatically

### Phase 11 — Post-Signing & Compliance
- [ ] Final PDF generation with all signatures
- [ ] Individual document storage linked to contacts
- [ ] FICA compliance tab on contact profile
- [ ] Amendment audit trail on flow timeline

---

## Files Involved

### Existing (modify):
- `ESignWizardController.php` — wizard + prepareSigning
- `SignatureController.php` — setup, sign, signComplete, review, approve
- `SignatureService.php` — handlePartyCompletion, advanceToNextParty, sendSigningRequest
- `SigningController.php` — external signer flow
- `setup.blade.php` — marker placement
- `sign.blade.php` — agent signing
- `external/sign.blade.php` — external signer signing
- `signature-block.blade.php` — signature section rendering
- `signature-line.blade.php` — inline signature rendering
- `WebTemplateDataService.php` — field resolution
- `CdsRendererService.php` — CDS document rendering

### Key Data:
- `docuperfect_documents` — document records
- `signature_templates` — signing config, parties, order
- `signature_requests` — per-party signing status
- `signature_markers` — signature/initial positions
- `esign_consent_log` — immutable consent records
- `esign_flows` — wizard flow state

---

## Excluded from This Spec
- Proxy signing (separate spec — PoA upload/verification)
- Sale agreement e-signing (legally prohibited — hardcoded block)
- Advanced Electronic Signatures / SAAA accreditation
- SMS OTP verification (future enhancement)