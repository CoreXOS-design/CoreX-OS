# HF Coastal Nexus — Electronic Signatures & Document Signing Specification

> **Purpose:** Complete specification for the Docuperfect e-signature and document signing system
> **Owner:** Home Finders Coastal (Johan Reichel)
> **Status:** In development
> **Last updated:** 2026-02-27
> **This is the single source of truth. When spec conflicts with codebase, codebase wins. Update this doc when architecture changes.**

---

## 1. SYSTEM OVERVIEW

The Docuperfect signing system handles document signing for a real estate agency. There are two signing methods, and the template type controls which are available:

| Template Type | Allowed Signing Methods | Restriction |
|---|---|---|
| **Rental** | Electronic + Wet-ink + Mixed | Party chooses method on signing page |
| **Sales** | Wet-ink ONLY | **HARD BLOCK — electronic signatures are NEVER allowed on sales documents. SA law prohibits this. No admin override.** |

The template type (rental/sales) is set when the template is created and cannot be changed at document level.

### Access Control
- Users marked as **rental staff** can access both rental and sales functions
- Users marked as **sales staff** CANNOT access the rental menu or rental signing features
- This is controlled by user flags/roles in the system

---

## 2. SIGNING METHODS

### 2.1 Electronic Signature (Rental Only)
Party receives email → verifies identity with ID number → sees document with all previous entries visible (flattened) → completes their fields (signatures, initials, text, dates) electronically on screen → system flattens their entries into page images → next party is notified.

### 2.2 Wet-Ink Signature (Sales + Rental)
Party receives email → verifies identity with ID number → downloads PDF → prints and signs physically → uploads scanned/photographed signed copy (or returns via email/WhatsApp/in-person) → agent reviews uploaded document → accepts (next party notified) or rejects (party asked to re-sign) → loop until all parties signed.

### 2.3 Mixed Signing (Rental Only)
Each party independently chooses their signing method on the signing page. One party may sign electronically while another downloads for wet-ink. The system handles both in the same document flow.

**On the signing page, rental parties see two options:**
1. "Sign Electronically" → proceeds to electronic signing flow
2. "Download, Sign & Upload" → proceeds to wet-ink flow

Sales parties ONLY see the download/upload option. The electronic option is never shown.

---

## 3. PARTY TYPES & SIGNING ORDER

### 3.1 Party Types
| Party | Context | Internal/External |
|---|---|---|
| Agent | Creates and initiates the document | Internal (Nexus user) |
| Full Status Agent / BM | Co-signs when agent is a candidate | Internal (Nexus user) |
| Tenant | Rental documents | External (email link) |
| Landlord | Rental documents | External (email link) |
| Buyer | Sales documents | External (email link) |
| Seller | Sales documents | External (email link) |
| Witness | Linked to a specific party | External or Internal |
| Co-signer | Additional signer for any party role | External or Internal |

### 3.2 Signing Order
The default order is configured during document setup. The standard flow:

**Standard (non-candidate agent):**
```
Agent signs → External Party 1 → External Party 2 → ... → Complete
```

**Candidate agent:**
```
Candidate signs → Full Status/BM signs → External Party 1 → External Party 2 → ... → Complete
```

The candidate + Full Status/BM step is **configurable per document:**
- **Co-sign (one step):** Both sign together, then flow moves to external parties. Used when both are in the same office.
- **Sequential (two steps):** Candidate signs first, then Full Status/BM signs separately. Used when they're in different locations.

Agent marks which mode during document setup.

### 3.3 Candidate Detection
A candidate agent is identified via the **designations** system. Search the `designations` table in the codebase to find how candidate status is flagged. When a candidate creates a document, the system must automatically add the Full Status/BM co-signing step.

### 3.4 Witnesses
Witnesses are linked to a specific party (e.g., "Tenant's Witness"). Timing is **configurable per document during setup:**
- **Same time:** Witness receives their signing link at the same time as their linked party
- **After:** Witness receives their signing link only after their linked party has completed signing

---

## 4. DOCUMENT FLATTENING — CRITICAL RULE

**After EVERY party completes ALL their fields (signatures, initials, text entries, dates — everything), the system MUST flatten all their entries into the page images BEFORE the next party receives their signing link.**

This means:
1. Agent completes their fields → **flatten** → tenant sees document with agent's entries burned in
2. Tenant completes their fields → **flatten** → landlord sees document with agent's AND tenant's entries burned in
3. Landlord completes their fields → **final flatten** → produces the completed PDF with everything burned in

### Flattening Rules
- Flatten ALL field types: signatures (drawn image), initials (drawn image), text fields (rendered as normal-sized black text at document font size ~11-12pt), dates (rendered as text)
- Signature/initial images are composited at their marker positions on the page image
- Text/date values are rendered as plain text at the marker position — NOT as signature-style images
- The flattened page images REPLACE the previous page images for the next signer's view
- Original unflattened page images are preserved separately for audit purposes
- Flattened images stored at: `storage/app/docuperfect/documents/{id}/flattened/page-{n}.png`
- The signing page MUST load flattened images (not originals) when they exist

### Final PDF Generation
After the last party completes and the final flatten runs:
- Generate a single multi-page PDF from the final flattened page images
- Store at: `storage/app/docuperfect/documents/{id}/signed_final.pdf`
- This is the canonical completed document

### What NOT to Flatten
- Do NOT render audit metadata text (e.g., "Signed: Johan Reichel à 26 Feb 2026 21:45") onto the document. Audit trail stays in the database only, never printed on the document.

---

## 5. ELECTRONIC SIGNING FLOW (Rental Only)

### 5.1 Document Setup
1. Agent creates document from a **rental** template
2. Agent fills in document fields (text, dates, selections)
3. Agent clicks "Set Up Signatures" (auto-saves document first, no "leave page" warning)
4. **Step 1 — Parties:** Agent configures signing parties (names, emails, ID numbers, signing order). If agent is candidate, system auto-adds Full Status/BM step.
5. **Step 2 — Markers:** Agent places field markers on the document (drag from toolbar onto document). Marker types: Signature, Initial, Date, Text Field. Each marker is assigned to a party.
6. **Step 3 — Required Documents (optional):** Agent configures which supporting documents each party must upload (defaults loaded from rental settings, customizable per document).
7. Agent clicks "Preview & Continue" → reviews → sends for signing

### 5.2 Marker Placement UX
- **Drag from toolbar:** User drags a marker type from the sidebar toolbar onto the document to place it
- **Click existing marker:** Selects it (shows resize handles, delete button). Does NOT create a new marker.
- **Drag existing marker:** Moves it to new position
- **Resize handles:** Drag corners/edges to resize
- **Delete:** Click X button or press Delete key
- **Click empty space:** Deselects current marker
- **Toolbar stays visible:** Sidebar toolbar uses `position: sticky` — never scrolls off screen

### 5.3 Identity Verification
When a party opens their signing link:
1. Show greeting: "Hi {party_name}"
2. Show document name
3. Ask for **ID / Passport Number only** — single field
4. Compare: `strtolower(trim($submitted)) === strtolower(trim($stored))`
5. If ID was not provided during setup (null/empty), **skip verification entirely** — the email link is sufficient authentication
6. On failure: "The ID number does not match our records. Please try again."
7. On success: mark verified, proceed to signing page
8. Reference POPIA compliance in the verification text

### 5.4 Signing Page
- Party sees document with all previous parties' entries flattened into the page images
- Only their assigned markers are interactive (highlighted, clickable)
- **Signature/Initial markers:** Click opens signature drawing pad
- **Text markers:** Click opens text input modal — saves plain text, NOT a signature image
- **Date markers:** Click opens date picker
- Progress counter: "X of Y completed" (not "signed" — not all fields are signatures)
- After completing all fields → submit → system flattens → next party is notified

### 5.5 Signing Page — Method Choice (Rental)
Before the signing interface, rental parties see:
```
How would you like to sign?
[Sign Electronically]    [Download, Sign & Upload]
```
- "Sign Electronically" → proceeds to electronic signing (markers on screen)
- "Download, Sign & Upload" → proceeds to wet-ink flow (download PDF, upload signed copy)

### 5.6 Emails
| Event | Recipients | Content |
|---|---|---|
| Signing request | Next party in order | "Please sign: {document_name}" + Sign button + Upload Documents button (if required docs configured) |
| Reminder | Party with pending fields | Configurable schedule + template from rental settings |
| Completion | All parties EXCEPT the rental agent | Notification with token download link (ID-verified) |

### 5.7 Rejection / Redo
From the rental signatures dashboard, an authorized user can reject a document:
- Click "Reject" → modal with required reason (min 5 chars)
- Options: "Just Archive" or "Create Revised Version"
- Archive: sets status to rejected, invalidates pending signing requests
- Revised: clones document + pages (not signatures), creates new template linked to old one
- Rejected documents appear in a collapsible "Rejected" section on the dashboard
- Audit log entry created

---

## 6. WET-INK SIGNING FLOW (Sales Always, Rental Optional)

### 6.1 Document Setup
1. Agent creates document from template (sales or rental)
2. Agent fills in document fields
3. Agent sets up signing flow: parties involved, signing order
4. If agent is candidate, system auto-adds Full Status/BM co-signing step
5. Agent initiates the flow

### 6.2 Wet-Ink Signing Cycle (Per Party)
```
System emails party a link
  → Party verifies identity (ID number)
  → Party downloads PDF (flattened with all previous entries)
  → Party prints, signs with wet ink
  → Party returns signed document via:
     a) Upload through the system (scan/photo)
     b) Email to agent
     c) WhatsApp to agent
     d) Drop at office in person
  → Agent uploads the signed copy (if not uploaded by party directly)
  → Agent reviews the uploaded document
  → ACCEPT: Party marked as signed, next party in order gets their email
  → REJECT: Party is notified to re-sign, upload cycle repeats
  → Loop until agent accepts
```

### 6.3 Agent Upload of Documents Received Externally
When a party returns a signed document via email/WhatsApp/in-person:
- Agent goes to the document in the system
- Agent uploads the received signed copy on behalf of the party
- Agent marks which method it was received by (email/WhatsApp/in-person)
- Agent reviews and accepts/rejects as normal

### 6.4 Candidate + BM Co-Signing (Wet-Ink)
When candidate and BM are co-signing (one step):
- Both physically sign the same document at the office
- Agent (candidate) uploads the co-signed document
- Agent marks both candidate and BM as signed
- Flow continues to external parties

When sequential:
- Candidate signs, uploads
- System sends to BM for review/signing
- BM downloads, signs, uploads
- Agent reviews BM's upload
- Flow continues to external parties

### 6.5 Download for Signing
The download button must generate a PDF from the current flattened page images. The page images are stored as PNGs — composite them into a multi-page PDF for download. Use DomPDF or similar (already in the project).

---

## 7. COMPLETION

### 7.1 When All Parties Have Signed
1. Final flatten — all entries burned into page images
2. Generate final signed PDF
3. Store at: `storage/app/docuperfect/documents/{id}/signed_final.pdf`
4. Send completion notification email to ALL parties EXCEPT the agent
5. Email contains a token-based download link (no login required, ID verification to access)
6. Agent downloads final PDF from within Nexus directly

### 7.2 External Party Download
- Party clicks download link in completion email
- Opens verification page: "Enter your ID number to download"
- Same ID verification as signing flow
- On success: PDF downloads
- No Nexus login required

---

## 8. RENTAL SIGNATURES DASHBOARD

Located at `/rental/signatures`. Shows all documents in the rental signing workflow.

### 8.1 Sections
| Section | Content |
|---|---|
| Needs Your Approval | Documents pending approval — with document type + property dropdowns |
| Awaiting Signatures | Documents out for signing — with document type + property dropdowns |
| Properties | Completed documents grouped by property (collapsible) |
| Active Leases | Lease-type completed documents with expiry date picker |
| Rejected | Collapsible section showing rejected documents |

### 8.2 Document Type & Property Assignment
Each document can be assigned:
- **Document Type:** Dropdown populated from `rental_document_types` table (lease, mandate, addendum, etc.)
- **Property:** Dropdown populated from `rental_properties` table
- Both save via AJAX on change (no page reload)
- Can be pre-set in the "Send to Rentals" modal or assigned/changed on the dashboard

### 8.3 Active Leases — Expiry Tracking
- Shows lease-type completed documents
- Expiry date picker per lease (saves via AJAX)
- Color-coded: red ≤30 days, amber ≤90 days, green >90 days, grey = not set
- `lease_expiry_date` stored on `docuperfect_documents` table
- Future phase: automatic renewal reminders based on expiry

---

## 9. REMINDER EMAIL SYSTEM

### 9.1 Configuration (Rental Settings)
- Enable/disable automatic reminders (toggle)
- Reminder interval in days (e.g., every 3 days)
- Maximum number of reminders per signing request
- Editable email template with placeholders: `{signer_name}`, `{document_name}`, `{agent_name}`, `{signing_link}`, `{days_waiting}`

### 9.2 Scheduled Command
`php artisan rental:send-reminders` runs daily via Laravel scheduler:
1. Check if reminders are enabled
2. Find pending signing requests older than the interval
3. Skip requests that have hit the max reminder count
4. Send reminder email using configured template
5. Increment `reminder_count`, update `last_reminded_at` on the signing request

### 9.3 Required Document Reminders
If a signing request has outstanding required document uploads AND the signature itself is complete, the reminder system sends document upload reminders instead of signing reminders. Same schedule applies.

---

## 10. REQUIRED DOCUMENT UPLOADS (Phase 2 — Specced, Not Yet Built)

### 10.1 Overview
Agents can specify supporting documents required from each party (ID copies, bank statements, proof of residence, etc.). Parties upload via a public token-based page. Agent can also manually mark documents as received via other channels.

### 10.2 Settings Defaults
Configurable in Rental Settings — default required document types per category (rental application, lease, mandate). Each has: name, description, applies_to (tenant/landlord/any), category.

### 10.3 Per-Document Customization
During signature setup, agent sees defaults pre-loaded. Can uncheck, add custom requirements, or skip entirely.

### 10.4 Public Upload Page
Token-based (no login). Party sees only their required documents. Upload individually via AJAX. Accept: PDF, JPG, PNG, HEIC. Max 10MB per file.

### 10.5 Agent View
Inline on document card: upload status per party per document. "Mark Received" dropdown for external receipts (email/WhatsApp/in-person/waived). Consolidated view across all active documents.

---

## 11. DOCUMENT VERSIONING & SUPERSEDE

When a document has an error and needs correction after sending:
1. Agent clicks "Edit & Re-send"
2. Old template marked as `superseded`, all signing links invalidated
3. New template created with party config and marker positions carried over
4. Agent fixes document, re-signs
5. New signing links sent to parties
6. Old links show "This document has been updated" page
7. Signatures do NOT carry over — fresh signatures required on corrected document
8. Only non-completed documents can be superseded
9. Full audit trail preserved

---

## 12. FIELD TYPES & RENDERING

### 12.1 Marker Types (Signature Setup)
| Type | Behavior on Signing Page | Rendering on Flattened Image |
|---|---|---|
| Signature | Signature drawing pad | Drawn signature image composited at position |
| Initial | Signature drawing pad (smaller) | Drawn initial image composited at position |
| Text Field | Text input modal — user types | Plain black text, ~11-12pt, at position |
| Date | Date picker | Plain black text, formatted date, at position |

### 12.2 Field Position System
All positions stored as **percentages** of page dimensions:
```
x_pixels = (field.x_percent / 100) * page_width
y_pixels = (field.y_percent / 100) * page_height
width_pixels = (field.width_percent / 100) * page_width
height_pixels = (field.height_percent / 100) * page_height
```

### 12.3 Rendering Rules
- Signatures/initials: composite the drawn image at the marker position
- Text/dates: render as plain text (black, normal document font size 11-12pt)
- **NEVER render audit metadata text onto the document** — no "Signed by X at Y" on the page
- Audit trail is database-only

---

## 13. DATABASE TABLES (E-Signature Related)

### Core Tables
| Table | Purpose |
|---|---|
| `docuperfect_templates` | PDF templates with page images and field definitions. Has `template_type` (rental/sales). |
| `docuperfect_documents` | Filled copies of templates. Has `document_type`, `property_id`, `property_address`, `lease_expiry_date`. |
| `signature_templates` | Signing configuration for a document. Status, parties_json, signing_order_json, rejection fields, supersede chain. |
| `signature_markers` | Individual field markers placed on document pages. Type, position (%), assigned party. |
| `signature_requests` | Per-party signing request. Token, status, signer details, reminder_count, last_reminded_at, verified_at. |
| `signature_audit_log` | Full audit trail of all signing events. |

### Rental Management Tables
| Table | Purpose |
|---|---|
| `rental_properties` | Property records for document assignment. CRUD in rental settings. |
| `rental_document_types` | Document type definitions (lease, mandate, etc.). CRUD in rental settings. |
| `rental_settings` | Key-value settings for reminders, email templates, etc. |

### Future Tables (Required Documents)
| Table | Purpose |
|---|---|
| `rental_required_document_types` | Default required document templates |
| `signature_required_documents` | Per-document required upload instances |
| `signature_uploaded_files` | Actual uploaded files from parties |

---

## 14. SECURITY RULES

1. **Sales documents: HARD BLOCK on electronic signatures.** No exceptions. No admin override. Enforced in code.
2. **Signing links: token-based authentication.** No Nexus login required for external parties.
3. **Identity verification: ID number only.** Case-insensitive, whitespace-trimmed comparison. Skip if no ID was provided during setup.
4. **Completion download: token + ID verification.** External parties access final PDF via email link + ID check.
5. **Files stored in app storage.** Not publicly accessible. Served via authenticated routes.
6. **Candidate documents: must have Full Status/BM co-signature** before going to external parties.
7. **POPIA compliance:** Verification pages reference POPIA. Minimum data collected from external parties.

---

## 15. AUDIT TRAIL

Every significant event is logged to `signature_audit_log`:
- Template created / updated / superseded
- Signing request sent
- Identity verified
- Field completed (per field)
- All fields completed (per party)
- Document flattened (per stage)
- Rejection with reason
- Document completed
- Reminder sent
- Download by party

Audit data stays in the database. **Never rendered onto the document itself.**

---

## 16. KEY FILE LOCATIONS

### Controllers
| File | Purpose |
|---|---|
| `app/Http/Controllers/Docuperfect/SignatureController.php` | Signature setup, marker placement, approval, rejection, supersede |
| `app/Http/Controllers/Docuperfect/SigningController.php` | External signing page, identity verification, field submission, wet-ink upload/download |
| `app/Http/Controllers/Docuperfect/DocumentController.php` | Document CRUD, send to rentals |
| `app/Http/Controllers/Rental/RentalDivisionController.php` | Rental signatures dashboard |
| `app/Http/Controllers/Rental/RentalPropertyController.php` | Property CRUD |
| `app/Http/Controllers/Rental/RentalDocumentTypeController.php` | Document type CRUD |

### Services
| File | Purpose |
|---|---|
| `app/Services/Docuperfect/SignatureService.php` | Dashboard data, status grouping |
| `app/Services/Docuperfect/SignaturePdfService.php` | PDF generation from page images |
| `app/Services/Docuperfect/FlattenService.php` (or similar) | Flattening — compositing fields onto page images |

### Views
| File | Purpose |
|---|---|
| `resources/views/docuperfect/signatures/setup.blade.php` | Marker placement page |
| `resources/views/docuperfect/signatures/external/sign.blade.php` (or similar) | External signing page |
| `resources/views/docuperfect/signatures/external/verify.blade.php` (or similar) | ID verification page |
| `resources/views/rental/signatures.blade.php` | Rental signatures dashboard |
| `resources/views/rental/settings/` | Rental settings pages |

### Storage
| Path | Purpose |
|---|---|
| `storage/app/docuperfect/templates/{id}/page-{n}.png` | Original template page images |
| `storage/app/docuperfect/documents/{id}/flattened/page-{n}.png` | Flattened page images (with fields burned in) |
| `storage/app/docuperfect/documents/{id}/signed_final.pdf` | Final completed PDF |

---

## 17. WHAT'S BUILT vs WHAT'S NOT

### Built and Working
- [x] Document creation from templates
- [x] Signature setup with marker placement (drag from toolbar)
- [x] Electronic signing flow — agent → tenant → landlord
- [x] Identity verification (ID number only)
- [x] Text field, date, initial, signature markers
- [x] Document flattening after each party (needs verification)
- [x] Signing emails with token links
- [x] Rejection / redo with reason
- [x] Rental signatures dashboard with sections
- [x] Document type + property assignment (AJAX)
- [x] Active leases with expiry tracking
- [x] Rental properties CRUD (settings)
- [x] Document types CRUD (settings)
- [x] Reminder email settings + scheduled command
- [x] Wet-ink download (PDF from page images)
- [x] Wet-ink review page (parse error fixed)

### Needs Verification / Bug Fixes
- [ ] Flattening: confirm all field types flatten correctly (text, dates, not just signatures)
- [ ] Flattening: confirm flattened images are loaded by signing page (not originals)
- [ ] Text fields on signing page: must show text input, NOT signature pad
- [ ] Completion email: notification with download link (not PDF attachment)
- [ ] Auto-save when clicking "Set Up Signatures"
- [ ] Sticky toolbars on editor pages (deferred — caused breakage, needs careful fix)
- [ ] Field rendering: no audit metadata text on document, text fields as normal-sized text

### Not Yet Built
- [ ] Sales wet-ink full flow (upload cycle, agent review, accept/reject per party)
- [ ] Mixed signing (party chooses electronic or wet-ink on signing page)
- [ ] Candidate + BM co-signing step (auto-detection from designations)
- [ ] Witness party type with configurable timing
- [ ] Co-signer party type
- [ ] Hard block on electronic signatures for sales templates
- [ ] Completion download page (token + ID verified, for external parties)
- [ ] Required document uploads (Phase 2, fully specced)
- [ ] Document versioning / supersede flow
- [ ] Sales document management section (future phase)

---

*End of CLAUDE_DOCUPERFECT_ESIGN.md — Living document. Update when architecture changes.*