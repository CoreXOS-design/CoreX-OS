# CLAUDE_DOCUPERFECT.md
# CoreX OS — DocuPerfect Module Specification
# Last updated: 2026-03-06

---

## 1. WHAT DOCUPERFECT IS

DocuPerfect is CoreX OS's integrated document system. It does two separate but connected things:

1. **Template Editor** — build PDF overlay templates from any PDF. Place fields on top of pages. Fields auto-fill from CoreX data (property, contact, deal, agent). Agent completes only what CoreX doesn't know.
2. **E-Signature Engine** — send completed documents for electronic or wet-ink signing. Sequential workflow. Flattening burns each stage into the PDF permanently.

The core workflow:
1. Admin uploads a PDF → system renders each page as an image
2. Admin places interactive fields on the page images
3. Template is saved with field positions (as % of page dimensions)
4. Agent opens a template → creates a "document" (a filled copy)
5. Agent fills in fields, toggles strikethroughs, picks selections, inserts clause text
6. All known fields auto-fill from linked CoreX records (property / contact / deal / agent)
7. Agent completes only what CoreX doesn't yet know
8. New data entered is written back to the linked record on save/send
9. Agent downloads the filled document as a PDF, or sends for signing

Every document that moves through DocuPerfect is linked to CoreX records. Every piece of data collected is written back to those records.

---

## 2. THE TWO ENTRY FLOWS

### Flow 1 — Document First
1. Agent opens DocuPerfect → selects a template
2. System asks: "Link to a Property / Contact / Deal?"
3. Agent selects the relevant records
4. All matching linked fields auto-fill from the database
5. Agent completes only fields CoreX does not yet know
6. New data entered is written back to the linked record on save/send

### Flow 2 — Record First
1. Agent is on a Property, Contact, or Deal record in CoreX OS
2. Clicks "Create Document"
3. Selects template from the library
4. Opens DocuPerfect already populated with record data
5. Same completion and write-back as Flow 1

Both flows end the same way: richer database, less agent effort, compliance captured automatically.

---

## 3. THE FOUR LINKED FIELD BUCKETS

Every template field can be tagged to one of four data buckets. This allows any external PDF to be templated and auto-populated without custom development per document.

### Bucket 1 — Property Fields
| Field Name | Source | Notes |
|---|---|---|
| property.address_full | CoreX / TVA | Full street address |
| property.erf_number | TVA / SG | Surveyor General erf ref |
| property.suburb | CoreX | |
| property.town | CoreX | |
| property.province | CoreX | |
| property.erf_size_m2 | TVA / SG | Land extent |
| property.floor_size_m2 | CoreX | |
| property.zoning | TVA / SG | |
| property.rates_monthly | CoreX | |
| property.levy_monthly | CoreX | Sectional title only |
| property.mandate_price | Deal | From signed mandate |
| property.municipal_valuation | TVA | |
| property.title_deed_number | TVA / SG | |
| property.sectional_title_unit | CoreX | |

### Bucket 2 — Contact Fields
| Field Name | Source | Notes |
|---|---|---|
| contact.full_name | TVA / CoreX | |
| contact.id_number | TVA / CoreX | Required for FICA |
| contact.email | TVA / CoreX | |
| contact.cell | TVA / CoreX | |
| contact.address_residential | CoreX | For FICA / POPIA |
| contact.marital_status | CoreX | |
| contact.spouse_name | CoreX | For OTP / mandate |
| contact.spouse_id_number | CoreX | |
| contact.entity_name | CoreX | If company / trust |
| contact.entity_reg_number | CoreX | |
| contact.fica_status | CoreX | Verified / Pending |
| contact.proof_of_residence | CoreX | Doc reference |
| contact.bank_name | CoreX | For OTP |
| contact.bank_account | CoreX | For OTP |

### Bucket 3 — Deal Fields
| Field Name | Source | Notes |
|---|---|---|
| deal.mandate_type | CoreX | Exclusive / Open |
| deal.mandate_price | CoreX | |
| deal.mandate_date | CoreX | |
| deal.mandate_expiry | CoreX | |
| deal.commission_rate | CoreX | |
| deal.commission_amount | CoreX | Calculated |
| deal.commission_vat | CoreX | Calculated |
| deal.otp_price | CoreX | Offer to purchase |
| deal.otp_date | CoreX | |
| deal.deposit_amount | CoreX | |
| deal.deposit_due_date | CoreX | |
| deal.bond_amount | CoreX | |
| deal.suspensive_date | CoreX | Bond approval deadline |
| deal.occupation_date | CoreX | |
| deal.attorney_name | CoreX | |
| deal.attorney_reference | CoreX | |
| deal.bond_originator | CoreX | |
| deal.registration_date | CoreX | |

### Bucket 4 — Agent Fields
| Field Name | Source | Notes |
|---|---|---|
| agent.full_name | CoreX User | |
| agent.ppra_number | CoreX User | PPRA registration |
| agent.cell | CoreX User | |
| agent.email | CoreX User | |
| agency.name | CoreX Config | |
| agency.registration_number | CoreX Config | |
| agency.ffc_number | CoreX Config | Fidelity fund certificate |
| agency.address | CoreX Config | |
| agency.principal_name | CoreX Config | |
| agency.principal_ppra | CoreX Config | |

### Field Resolution Rules
- Each template stores its field map as JSON listing which bucket fields it uses
- At document generation, CoreX resolves each field from the linked record(s)
- Unresolved fields (data not yet in DB) are left blank and flagged for agent completion
- On save/send, newly completed fields are pushed back to the parent record
- Template builder must show which bucket each field maps to and whether the value is currently resolvable for the selected records

### The Write-Back Rule
Every field the agent completes that is NOT already in the database must be written back to the linked record on document save or send. No data entered in a DocuPerfect session is ever lost. Every document completion is a data enrichment event.

---

## 4. DATABASE SCHEMA

### `docuperfect_templates`
```
id                  bigint PK auto
name                string
template_type       string (sales|rentals|compliance|general)
page_count          integer
fields_json         json — array of field definitions (positions, types, styles, bucket links)
is_global           boolean default false
owner_id            bigint FK → users
archived_at         timestamp nullable
created_at / updated_at
```
Page images stored at: `storage/app/docuperfect/templates/{id}/page-{n}.png`

### `docuperfect_template_branches` (pivot)
```
template_id         bigint FK → docuperfect_templates
branch_id           bigint FK → branches
```
When `is_global = true`, this table is empty for that template (visible to all).
When `is_global = false`, visible only to branches in this pivot.

### `docuperfect_documents`
```
id                  bigint PK auto
name                string
template_id         bigint FK → docuperfect_templates
fields_json         json — field values (inherits template positions + user values)
owner_id            bigint FK → users
branch_id           bigint FK → branches (auto-set from owner's branch)
linked_property_id  bigint FK → properties nullable
linked_contact_id   bigint FK → contacts nullable
linked_deal_id      bigint FK → deals nullable
archived_at         timestamp nullable
created_at / updated_at
```

### `docuperfect_clauses`
```
id                  bigint PK auto
name                string
text                text
is_global           boolean default false
owner_id            bigint FK → users
created_at / updated_at
```

### `docuperfect_clause_branches` (pivot)
```
clause_id           bigint FK → docuperfect_clauses
branch_id           bigint FK → branches
```

### Future — `docuperfect_envelopes` (Phase 2 — E-Signatures)
```
id                  bigint PK auto
document_id         bigint FK → docuperfect_documents
participants_json   json — [{name, email, role, signing_order}]
status              string (draft|sent|partially_signed|completed)
secure_token        string unique
audit_trail_json    json — [{event, by, at, ip}]
created_at / updated_at
```

---

## 5. FIELD TYPES

### 7 Field Types
| Type | Description | Toolbar |
|---|---|---|
| `text` | Free text input. Single or multi-line. Font, size, bold, underline, solid background. | T |
| `strikethrough` | Clickable area toggling a strike line (horizontal or diagonal). Used to cross out inapplicable clauses. | — |
| `selection` | Pick one from comma-separated options (e.g. "is / is not"). Column-zone positioned text. Solid background option. | ≡ |
| `tick` | Checkbox-style. Bold X character. Default options: Yes/No/N/A. Column-zone auto-positioned. **Separate type from selection.** | ☑ |
| `date` | Date picker. Auto-fills on signing if linked to a signing event. | 📅 |
| `condition` | Pulls text from conditional clause library. Editable per document. | § |
| `signature` | Full signature capture area. Linked to a signing party. | ✍ |
| `initials` | Initials capture block. Linked to a signing party. | |

### Field Properties (Full Schema)
```json
{
  "id": "string",
  "type": "text|strikethrough|selection|tick|date|condition|signature|initials",
  "pageIndex": 0,
  "position": { "x": 0.0, "y": 0.0 },
  "size": { "width": 0.0, "height": 0.0 },
  "value": "string",
  "options": ["string"],
  "selectedValue": "string",
  "style": {
    "fontSize": 12,
    "fontFamily": "Helvetica|Times|Courier",
    "bold": false,
    "underline": false,
    "solidBackground": false
  },
  "active": false,
  "strikethroughType": "horizontal|diagonal",
  "isUserAdded": false,
  "assigneeRole": "string",
  "text": "string",
  "bucketField": "property.erf_number"
}
```

### Tick Field Rules
- Tick is a separate type from selection (split March 2026)
- Default options: Yes / No / N/A
- Auto-positions tick mark in correct column zone (left/center/right) based on option count
- Renders bold X character, consistent on screen and in flattened PDF
- Old fields with `renderMode:'tick'` auto-migrate to `type:'tick'` for backward compatibility

### Field Position Calculation
All positions use percentage coordinates relative to page dimensions:
```
x_pixels = (field.position.x / 100) * container_width
y_pixels = (field.position.y / 100) * container_height
width_pixels = (field.size.width / 100) * container_width
height_pixels = (field.size.height / 100) * container_height
```

---

## 6. TEMPLATE EDITOR

### Architecture
The editor is a JavaScript module embedded in a Blade wrapper page. The Blade page provides the CoreX layout (sidebar, header). The JS module handles all canvas interaction.

- Page images loaded via authenticated API endpoint
- Field CRUD handled in JS, saved to server via AJAX
- No full page reloads during editing

### Toolbar Behaviour
- Fixed position (JS-pinned below page header)
- Does not scroll away on multi-page documents
- Toolbar pins correctly on MacBook (high DPI) displays

### Field Interactions
- Click toolbar button → click on page to place field
- Move handle: blue circle top-left
- Resize handle: blue dot bottom-right
- Delete button: red circle top-right
- Selected field shows inline properties panel

### Copy-Paste Fields
- Ctrl+C / Ctrl+V to copy/paste selected field
- Duplicate button (⧉) on each selected field
- All properties copied except position and ID
- New ID auto-generated on paste/duplicate

### Template Mode Rendering (Colour Coding)
| Type | Colour |
|---|---|
| text / placeholder | Blue #3b82f6 |
| strikethrough | Red #ef4444 |
| selection | Green #22c55e |
| tick | Green #22c55e |
| initials / signature | Amber #fbbf24 |
| date | Purple #9333ea |
| condition | Teal #0d9488 |

### Document Mode Rendering
- **text:** Transparent text input, fills on click
- **strikethrough:** Dashed border when inactive; red fill + strike line when active; click to toggle
- **selection:** All options visible; click to select (selected = underlined, unselected = faded)
- **tick:** Options displayed in column zones; click to place bold X
- **date:** Date input field
- **condition:** Editable clause text pre-filled from clause library
- **signature / initials:** Signature line with label

### PDF Export (jsPDF — Client-Side)
For each field on each page:
- If `solidBackground`, draw white rectangle first (covers underlying PDF text)
- Set font (family, size, bold)
- **text / date:** Render `field.value` as text with optional underline
- **signature / initials:** Draw signature line + label
- **selection:** Render `field.selectedValue`
- **tick:** Render bold X in correct column zone if active
- **strikethrough:** If `field.active`, draw line (horizontal through centre or diagonal corner-to-corner)
- **condition:** Render `field.text` as wrapped text

---

## 7. DOCUMENT LIBRARY & TEMPLATES

### Template Types
| Type | Use |
|---|---|
| `sales` | OTPs, mandates, purchase agreements, condition reports |
| `rentals` | Lease agreements, rental applications, inspection reports |
| `compliance` | FICA docs, PPRA-required forms, disclosure forms |
| `general` | Letters, general use |

Type is a filter/label only — no logic difference between types.

### Global vs Agency Templates
- `is_global = true` — available to all agencies on the platform
- `is_global = false` — scoped to the creating agency's branches only
- Agency-specific templates are not visible to other agencies

### The "Give Me Your PDF" Onboarding Proposition
Any external PDF (from attorneys, banks, or other agencies) can be templated by mapping its fields to the four buckets. Once mapped, that document auto-populates for any linked property, contact, deal, and agent from day one. Zero switching friction for agency onboarding.

---

## 8. E-SIGNATURE ENGINE

### Two Signing Modes

**Electronic Signing (Rental)**
- Alpine.js canvas-based signature capture in browser
- Signatures drawn directly on screen
- Used for: rental agreements, lease renewals

**Wet-Ink Signing (Sales + Rental)**
- Download → print → sign → scan → upload cycle
- Agent uploads the signed document back into CoreX
- System validates upload before proceeding to next stage
- Used for: sales mandates, OTPs, compliance forms requiring original wet-ink

### Sequential Signing Workflow
Signing parties must complete in sequence. Cannot skip a stage. Order is configurable per document type.

Standard rental sequence: **Agent → Tenant → Landlord**
Standard sales sequence: **Agent → Seller → Buyer** (configurable)

### Flattening System
- After each party signs, the PDF is flattened
- Flattening composites all completed field values onto page images as a permanent layer
- The next party receives a PDF showing all previous entries as burned-in content
- Final flattened PDF is the legally complete signed document
- **Never modify a flattened stage — it is immutable**

### ID Verification Gate
- Before a party can download the final signed document, identity must be verified
- ID number is checked against the contact record
- If FICA is not complete, download is blocked with a prompt to complete compliance
- **This gate is not optional. Do not build workarounds.**

### Email Notifications
- Each signing stage triggers an email to the next party
- Emails use the CoreX branded HTML email template
- Links in emails are signed URLs with expiry

### Upload on Behalf
- Agent can upload a signed document on behalf of a party (wet-ink flow)
- Flagged in the audit trail as "uploaded by agent"
- Does not bypass ID verification

### Signing Audit Trail
- Every action logged: viewed, signed, uploaded, rejected, downloaded
- Timestamps and IP addresses stored
- Full trail visible to agent and admin

### Reset Commands (Development Use Only)
```bash
php artisan docuperfect:reset-signing {document_id} --to=setup
php artisan docuperfect:reset-signing {document_id} --to=agent-signed
php artisan docuperfect:reset-signing {document_id} --to=tenant-signed
```

---

## 9. DOCUMENT PACK SYSTEM

### Complete Pack
Bundles multiple signed documents into a single ZIP download:
- All completed signed PDFs
- Supporting documents (uploaded by agent)
- FICA documents for all parties

### Role-Based Packs
Packs compiled for specific recipients contain only what that recipient needs:

| Recipient | Contents |
|---|---|
| Bond originator | OTP, FICA docs (buyer + seller), property details |
| Transferring attorney | OTP, mandate, FICA docs (all parties), title deed info |
| Client | Their signed copies only |

System selects and assembles the correct documents automatically based on recipient type selected.

---

## 10. ROUTES

### Management (Blade views)
```
GET  /docuperfect                              → dashboard (template gallery + my documents)
GET  /docuperfect/templates                    → template list (admin/BM)
POST /docuperfect/templates/upload             → PDF upload, creates template
GET  /docuperfect/templates/{id}/edit          → template field editor
POST /docuperfect/templates/{id}/save          → save fields_json
POST /docuperfect/templates/{id}/archive       → archive template
POST /docuperfect/templates/{id}/copy          → duplicate template

GET  /docuperfect/documents                    → my documents list
GET  /docuperfect/documents/create/{templateId} → open template to fill
GET  /docuperfect/documents/{id}/edit          → continue filling
POST /docuperfect/documents/{id}/save          → save fields_json + write-back to linked records
GET  /docuperfect/documents/{id}/download      → generate + download PDF
POST /docuperfect/documents/{id}/archive       → archive document

GET  /docuperfect/clauses                      → clause library
POST /docuperfect/clauses                      → create clause
PUT  /docuperfect/clauses/{id}                 → update clause
POST /docuperfect/clauses/{id}/copy            → duplicate clause
POST /docuperfect/clauses/{id}/archive         → archive clause
```

### API (JSON — used by editor JS)
```
GET  /api/docuperfect/templates/{id}/pages     → page image URLs
POST /api/docuperfect/templates/{id}/fields    → save fields (AJAX)
GET  /api/docuperfect/clauses/list             → clause list for modal (AJAX)
POST /api/docuperfect/documents/{id}/fields    → save document fields (AJAX)
GET  /api/docuperfect/documents/{id}/resolve   → resolve linked field values from CoreX records
```

---

## 11. UI LAYOUT

### Sidebar Entry
```
Documents  ▸
  My Documents        (agent: own; BM: branch; admin: all)
  Templates           (admin/BM only)
  Clause Library      (admin/BM: manage; agent: view)
```

### Template Editor Layout
```
┌──────────────────────────────────────────────────────┐
│ Fixed header: "Edit Template — {name}"  [Save] [Back] │
├──────────┬───────────────────────────────────────────┤
│ TOOLBAR  │  PAGE CANVAS                              │
│ [Text]   │  ┌─────────────────────────────────────┐  │
│ [Strike] │  │  PDF page image                     │  │
│ [Select] │  │  with field overlays                │  │
│ [Tick]   │  │                                     │  │
│ [Initial]│  │  [field]  [field]                   │  │
│ [Date]   │  │       [field]                       │  │
│ [Clause] │  └─────────────────────────────────────┘  │
│ [Sign]   │  Page 1 of 3  [< >]                       │
│          │                                           │
│ PAGES    │  VISIBILITY                               │
│ [1][2][3]│  ☑ Global  OR  Branches: [SB][BB]        │
└──────────┴───────────────────────────────────────────┘
```

---

## 12. ROLE-BASED ACCESS

| Role | DocuPerfect Capability |
|---|---|
| super_admin / admin | Full access: all templates, all documents, all clauses, all branches. Create/edit/archive templates. |
| branch_manager | Sees global + own branch templates/clauses. Creates templates for own branch. Sees all documents from branch agents. |
| agent | Sees global + own branch templates. Creates documents from templates. Sees own documents only. Can add fields to documents but not edit template fields. |

### Permission Keys
| Key | Controls |
|---|---|
| `docuperfect.view` | View document library |
| `docuperfect.create` | Create document from template |
| `docuperfect.edit` | Edit draft documents |
| `docuperfect.archive` | Archive documents |
| `docuperfect.template.create` | Create / edit templates |
| `docuperfect.template.archive` | Archive templates |

Data scope (own/branch/all) applies to document visibility. All routes use `permission:` middleware.

---

## 13. PDF PROCESSING

### Upload (Template Creation)
1. Client-side: pdf.js renders each page in browser
2. Page images uploaded to server via AJAX
3. Stored at `storage/app/docuperfect/templates/{id}/page-{n}.png`
4. Template record created with `page_count`, empty `fields_json`
5. Redirect to template editor

Page images served via authenticated route only (not publicly accessible).

### Export (Document Download)
- Client-side jsPDF
- For each page: add page image as background, render field values on top
- Browser downloads the generated PDF
- Target: < 5 seconds for a standard 7-page document

### Quality Notes
- Render at 2x device pixel ratio for crisp text on printouts
- Each template page ~500KB–1MB as PNG
- Consider JPEG compression for preview thumbnails

---

## 14. BUILD PHASES

### Phase 1 — Core System
- Database migrations + models
- Template CRUD (upload, list, archive, copy)
- Document CRUD (create from template, list, archive)
- Clause CRUD with branch visibility
- Blade views (CoreX layout + design system)
- Sidebar integration
- Page image storage + authenticated serving

### Phase 2 — Interactive Editor
- Template editor JS module (field placement, move, resize, delete, bucket tagging)
- Document editor JS module (filling, strikethrough toggle, selection, dates)
- Linked field resolution (auto-fill from CoreX records)
- Inline toolbar (font, size, bold, underline, solid background)
- Clause selection modal
- Save via AJAX + write-back to linked records
- PDF download (client-side jsPDF)

### Phase 3 — E-Signatures
- Electronic signing (Alpine.js canvas, rental)
- Wet-ink signing (download/upload cycle)
- Sequential signing workflow
- Flattening system
- ID verification gate
- Email notifications (branded HTML)
- Signing audit trail
- Upload on behalf

### Phase 4 — Document Packs
- Complete pack ZIP download
- Role-based pack assembly (bond originator / attorney / client)
- Deal tracker integration (stage-based pipeline)

### Phase 5 — TVA Integration
- Property and contact bucket fields resolve from live TVA API where CoreX doesn't have the value
- Property lookup from DocuPerfect record creation screen

---

## 15. KEY FILES

| File | Purpose |
|---|---|
| `app/Http/Controllers/DocuperfectController.php` | Main document controller |
| `app/Http/Controllers/DocuperfectTemplateController.php` | Template management |
| `app/Models/DocuperfectDocument.php` | Document model (SoftDeletes) |
| `app/Models/DocuperfectTemplate.php` | Template model |
| `public/js/docuperfect-editor.js` | Template + document editor (Alpine.js + canvas) |
| `resources/views/docuperfect/` | All DocuPerfect Blade views |

---

## 16. RULES — NEVER BREAK THESE

1. **Never hard delete** a document or template. Always soft delete (archive).
2. **Never modify a flattened PDF stage.** It is immutable once flattened.
3. **Never skip a signing stage.** Sequence is enforced. No exceptions.
4. **Never use `forceDelete()`** on documents or templates.
5. **Write-back always happens** — no data entered in DocuPerfect is discarded.
6. **Linked fields use dot-notation** (e.g. `property.erf_number`). No exceptions.
7. **Templates belong to an agency** unless `is_global = true`.
8. **ID verification gate is not optional** — do not build workarounds.
9. **Permissions gate everything** — all routes use `permission:` middleware.
10. **Every new view ships with navigation** — sidebar link or access button required.
11. **Page images served via authenticated route only** — never publicly accessible.
12. **No legacy role checks** — use `hasPermission()` and `getDataScope()`. Never `isAdmin()` or `isAgent()`.
13. **SQLite vs MySQL** — dev uses SQLite, production uses MySQL. No `GLOB`, `julianday()`, `CAST(... AS INTEGER)`. Use `REGEXP`, `DATEDIFF()`, `CAST(... AS UNSIGNED)`.

---

## 17. ACCEPTANCE CRITERIA

- [ ] Admin can upload PDF → pages render correctly
- [ ] Admin can place all field types on template pages
- [ ] Admin can move, resize, delete, and copy fields
- [ ] Admin can set template visibility (global / specific branches)
- [ ] Agent can create document from template (both Flow 1 and Flow 2)
- [ ] Linked fields auto-fill from linked CoreX records
- [ ] Agent can fill all field types
- [ ] New data written back to linked records on save
- [ ] Agent can download filled PDF (< 5 seconds)
- [ ] Downloaded PDF matches visual quality of current system
- [ ] Clause library with branch visibility works
- [ ] Tick type renders correctly and is backward-compatible with `renderMode:'tick'`
- [ ] Fixed toolbar does not scroll away on multi-page documents
- [ ] Copy-paste fields work (Ctrl+C/Ctrl+V + duplicate button)
- [ ] Role-based access enforced server-side
- [ ] Archive and copy work for templates and documents
- [ ] All routes use `permission:` middleware
- [ ] Page images served via authenticated route only