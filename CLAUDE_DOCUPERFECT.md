# CLAUDE_DOCUPERFECT.md — Rebuild Specification

> **Purpose:** Rebuild the standalone Docuperfect document system as a native Nexus module.
> **Owner:** Home Finders Coastal (Johan Reichel)
> **Status:** Specification — not yet built
> **Priority:** High — agents use this daily

---

## 1. WHAT DOCUPERFECT DOES

Docuperfect is a PDF template overlay system for real estate documents (OTPs, mandates, condition reports, rental agreements). The workflow is:

1. **Admin uploads a PDF** → system renders each page as an image
2. **Admin places interactive fields** on the page images (text inputs, dates, selections, strikethroughs, conditional clauses, signature/initial blocks)
3. **Template is saved** with field positions (as % of page dimensions)
4. **Agent opens a template** → creates a "document" (a filled copy)
5. **Agent fills in fields**, toggles strikethroughs, picks selections, inserts clause text
6. **Agent downloads** the filled document as a PDF (page images + field values rendered on top)

Think of it as a lightweight DocuSign-style form builder, purpose-built for SA real estate paperwork.

---

## 2. CURRENT SYSTEM (STANDALONE — BEING REPLACED)

### Tech Stack
- **Frontend:** Single 2,000-line React file (App.tsx) — all components in one file
- **Backend:** 734-line Express/Node.js API (api_clean.cjs) with raw SQLite
- **Auth:** Plaintext passwords, no sessions, no hashing, role checks on frontend only
- **Storage:** Page images stored as base64 PNG strings in SQLite (makes DB huge, slow to load)
- **PDF rendering:** Client-side using pdf.js (render) + jsPDF (export)
- **Hosting:** Runs on company server, port 5001, behind Vite dev server

### Current Data Model
```
users         (id, username, password, role, branchId, siteAccess)
branches      (id, name)
conditionalClauses (id, name, text, isGlobal, allowedBranches[], ownerId)
templates     (id, name, pageImages[], fields[], branchId, templateType, isGlobal, allowedBranches[], ownerId, archived)
userDocuments (id, name, templateId, fields[], ownerId, branchId, archived)
documentEnvelopes (id, participants[], status, secureToken, auditTrail[], documentId)
```

### Current Roles
- `admin` — sees everything, manages all templates/users/branches/clauses
- `branch-admin` — manages own branch templates/users/clauses, sees branch docs
- `user` — fills documents from assigned templates, sees own docs only

### Field Types (7 total)
1. **placeholder** — text input field (free text, styled: font, size, bold, underline, solid background)
2. **strikethrough** — clickable area that toggles a strike line (horizontal or diagonal) — used to cross out inapplicable clauses on printed forms
3. **selection** — pick one from comma-separated options (e.g., "is / is not")
4. **initial** — signature-line block for initials
5. **date** — date picker field
6. **condition** — pulls text from a conditional clause library (reusable legal text)
7. **signature** — signature-line block for full signature

### Field Properties
```typescript
{
  id: string;
  type: 'placeholder' | 'strikethrough' | 'selection' | 'initial' | 'date' | 'condition' | 'signature';
  pageIndex: number;           // which page this field is on
  position: { x: number; y: number };  // % of page width/height
  size: { width: number; height: number };  // % of page width/height
  value?: string;              // text content or date value
  options?: string[];          // for selection type
  selectedValue?: string;      // chosen option for selection type
  style?: {
    fontSize: number;
    fontFamily: 'Helvetica' | 'Times' | 'Courier';
    bold: boolean;
    underline: boolean;
    solidBackground: boolean;  // white background to cover underlying PDF text
  };
  active?: boolean;            // for strikethrough (toggled on/off)
  strikethroughType?: 'horizontal' | 'diagonal';
  isUserAdded?: boolean;       // template field vs user-added field
  assigneeRole?: string;       // who should fill this field
  text?: string;               // for condition type (the clause text)
}
```

### Key UX Features
- **Template editor:** Click-and-drag to place fields on page images. Move handle (blue circle), resize handle (bottom-right), delete button (red X). Properties editor for selection options.
- **Document editor:** Same page view but fields are fillable. Template fields are locked (can't move/delete). User can add additional fields.
- **Conditional clauses:** Library of reusable legal text. When placing a condition field, a modal lets you pick from the clause library. The clause text populates the field and is editable per-document.
- **Strikethrough toggle:** Click to activate/deactivate. Visual feedback (dashed border when inactive, solid fill + strike line when active).
- **PDF export:** Client-side. Renders page images + field values using jsPDF. Downloads as PDF.
- **Branch visibility:** Templates and clauses can be global or branch-specific. Admin controls visibility.
- **Archive:** Templates and documents can be archived (soft delete).
- **Copy:** Templates and clauses can be duplicated.

### Document Envelope System (Incomplete)
The current code has a `documentEnvelopes` table with participants, status, secureToken, and auditTrail. This was started but never completed — the UI for signing workflow doesn't exist. **Defer this to Phase 2.**

---

## 3. NEXUS REBUILD — ARCHITECTURE

### 3.1 Why Rebuild vs Bolt-On
- Nexus already has users, branches, roles, authentication (Laravel + Jetstream)
- Nexus already has the sidebar, brand colours, design system
- Current system has security issues (plaintext passwords, frontend-only role checks)
- Current system stores page images as base64 in SQLite (huge DB, slow load)
- One codebase, one login, one sidebar = better UX for agents

### 3.2 What Stays the Same
- The core concept: PDF → page images → overlay fields → fill → export PDF
- All 7 field types with the same properties
- Template/document separation (template = blank form, document = filled copy)
- Clause library with branch visibility
- Branch-based access control
- Archive and copy functionality

### 3.3 What Changes
- **Auth:** Uses Nexus authentication (Laravel Sanctum). No separate login.
- **Roles:** Maps to Nexus roles (admin, BM → branch-admin, agent → user)
- **Storage:** Page images stored as files in `storage/app/docuperfect/templates/{id}/` (not base64 in DB)
- **Backend:** Laravel controllers and models (not Express/Node.js)
- **Frontend:** Blade views with Alpine.js for the document list/management. The editor itself should be a Livewire component or a standalone JS module embedded in a Blade view — the drag-and-drop field placement requires client-side interactivity.
- **PDF rendering:** Server-side using a PHP PDF library (e.g., TCPDF, FPDI) OR keep client-side jsPDF approach in a JS module. Client-side is simpler and proven.
- **UI:** Nexus sidebar, navy/cyan brand colours, design system classes

### 3.4 Data Migration
Need to migrate existing data from the standalone SQLite DB:
- Templates (with page images extracted from base64 to files)
- User documents (field values)
- Conditional clauses
- Map Docuperfect users to Nexus users (by username/name matching)
- Map Docuperfect branches to Nexus branches

---

## 4. DATABASE SCHEMA (LARAVEL MIGRATIONS)

### `docuperfect_templates`
```
id                  bigint PK auto
name                string
template_type       string (sales|rentals|compliance) — categorisation
page_count          integer
fields_json         json — array of field definitions (positions, types, styles)
is_global           boolean default false
owner_id            bigint FK → users (who created it)
archived_at         timestamp nullable
created_at          timestamp
updated_at          timestamp
```
Page images stored at: `storage/app/docuperfect/templates/{id}/page-{n}.png`

### `docuperfect_template_branches` (pivot)
```
template_id         bigint FK → docuperfect_templates
branch_id           bigint FK → branches
```
When `is_global = true`, this table is empty for that template (visible to all).
When `is_global = false`, template is visible only to branches in this pivot.

### `docuperfect_documents`
```
id                  bigint PK auto
name                string
template_id         bigint FK → docuperfect_templates
fields_json         json — array of field values (inherits template positions + user values)
owner_id            bigint FK → users (the agent who filled it)
branch_id           bigint FK → branches (auto-set from owner's branch)
archived_at         timestamp nullable
created_at          timestamp
updated_at          timestamp
```

### `docuperfect_clauses`
```
id                  bigint PK auto
name                string
text                text — the full clause wording
is_global           boolean default false
owner_id            bigint FK → users
created_at          timestamp
updated_at          timestamp
```

### `docuperfect_clause_branches` (pivot)
```
clause_id           bigint FK → docuperfect_clauses
branch_id           bigint FK → branches
```

### Future — `docuperfect_envelopes` (Phase 2)
```
id                  bigint PK auto
document_id         bigint FK → docuperfect_documents
participants_json   json — [{name, email, role}]
status              string (draft|sent|signed|completed)
secure_token        string unique
audit_trail_json    json — [{event, by, at}]
created_at          timestamp
updated_at          timestamp
```

---

## 5. ROLE MAPPING

| Nexus Role | Docuperfect Capability |
|-----------|----------------------|
| admin | Full access: all templates, all documents, all clauses, all branches. Can create/edit/delete/archive templates. Manage users (via Nexus user management). |
| BM (branch_manager) | Branch-admin: sees global + own branch templates/clauses. Can create templates for own branch. Sees all documents from branch agents. |
| agent | User: sees global + own branch templates. Creates documents from templates. Sees own documents only. Can add fields to documents but not edit template fields. |

---

## 6. ROUTES

### Management (Blade views, sidebar-accessible)
```
GET  /docuperfect                          → DashboardController@index (template gallery + my documents)
GET  /docuperfect/templates                → TemplateController@index (admin/BM template list)
POST /docuperfect/templates/upload          → TemplateController@upload (PDF upload, creates template)
GET  /docuperfect/templates/{id}/edit       → TemplateController@edit (template field editor — the interactive canvas)
POST /docuperfect/templates/{id}/save       → TemplateController@save (save fields_json)
POST /docuperfect/templates/{id}/archive    → TemplateController@archive
POST /docuperfect/templates/{id}/copy       → TemplateController@copy
DELETE /docuperfect/templates/{id}          → TemplateController@destroy

GET  /docuperfect/documents                 → DocumentController@index (my documents list)
GET  /docuperfect/documents/create/{templateId} → DocumentController@create (open template to fill)
GET  /docuperfect/documents/{id}/edit       → DocumentController@edit (continue filling)
POST /docuperfect/documents/{id}/save       → DocumentController@save (save fields_json)
GET  /docuperfect/documents/{id}/download   → DocumentController@download (generate + download PDF)
POST /docuperfect/documents/{id}/archive    → DocumentController@archive
DELETE /docuperfect/documents/{id}          → DocumentController@destroy

GET  /docuperfect/clauses                   → ClauseController@index (clause library)
POST /docuperfect/clauses                   → ClauseController@store
PUT  /docuperfect/clauses/{id}              → ClauseController@update
POST /docuperfect/clauses/{id}/copy         → ClauseController@copy
DELETE /docuperfect/clauses/{id}            → ClauseController@destroy
```

### API (JSON endpoints for the interactive editor JS)
```
GET  /api/docuperfect/templates/{id}/pages  → returns page image URLs
POST /api/docuperfect/templates/{id}/fields → save fields (AJAX from editor)
GET  /api/docuperfect/clauses/list          → clause list for selection modal (AJAX)
POST /api/docuperfect/documents/{id}/fields → save document fields (AJAX)
```

---

## 7. UI DESIGN

### 7.1 Sidebar Integration
Add "Documents" expandable group to Nexus sidebar:
```
Documents  ▸
  My Documents          (agent: own docs; BM: branch docs; admin: all)
  Templates             (admin/BM only: template management)
  Clause Library        (admin/BM: manage; agent: view)
```

### 7.2 Dashboard / Gallery View (`/docuperfect`)
The main landing page. Shows:
- **My Documents** section: cards showing document name, template name, last edited, with Edit/Download/Archive actions
- **Available Templates** section: cards showing template name, preview thumbnail (first page), template type badge, with "New Document" button
- Navy header bar: "Documents" with template type filter dropdown
- Search/filter by name
- Design system: ds-card containers, ds-section-header, navy links

### 7.3 Template Editor (`/docuperfect/templates/{id}/edit`)
This is the most complex screen — a full-page canvas editor:

```
┌─────────────────────────────────────────────────────┐
│ Navy header: "Edit Template — {name}"    [Save] [Back] │
├──────────┬──────────────────────────────────────────┤
│ TOOLBAR  │  PAGE CANVAS                              │
│          │  ┌────────────────────────────────┐       │
│ [Text]   │  │                                │       │
│ [Strike] │  │    PDF page image with          │       │
│ [Select] │  │    field overlays               │       │
│ [Initial]│  │                                │       │
│ [Date]   │  │    [field] [field]             │       │
│ [Clause] │  │         [field]                │       │
│ [Sign]   │  │                                │       │
│          │  └────────────────────────────────┘       │
│ PAGES    │  Page 1 of 3  [< >]                       │
│ [1] [2]  │                                           │
│ [3]      │                                           │
│          │  VISIBILITY                               │
│          │  ☑ Global  OR  Branches: [SB] [BB]       │
└──────────┴──────────────────────────────────────────┘
```

**Implementation approach:** This MUST be a JavaScript module (not pure Blade/Alpine). The drag-and-drop field placement, resize handles, and page rendering require proper JS. Options:
- **Option A (Recommended):** Blade wrapper page with embedded React/vanilla JS module for the canvas editor. The editor JS loads page images via API, handles field CRUD, saves via AJAX. The Blade page provides the Nexus layout (sidebar, header, footer).
- **Option B:** Full Livewire component with Alpine.js for drag-drop. More complex, less proven for this type of interaction.

The current App.tsx editor code (~800 lines of rendering + interaction logic) is solid conceptually. The field placement, moving, resizing, and rendering logic can be extracted and reused.

### 7.4 Document Editor (`/docuperfect/documents/{id}/edit`)
Same canvas as template editor but in "filling" mode:
- Template fields are visible but not movable/deletable
- User fills in values (text, dates, selections, strikethrough toggles)
- User CAN add new fields (isUserAdded = true) — these are movable/deletable
- Inline toolbar appears when field is selected (font, size, bold, underline, solid bg)
- "Download PDF" button generates and downloads the filled document
- "Save" button persists current field values

### 7.5 Clause Library (`/docuperfect/clauses`)
Standard CRUD table (like Designations page):
- Navy header bar
- Table: Name, Preview (truncated text), Visibility (Global / branch names), Actions
- Modal for add/edit with name, text (textarea), global toggle, branch checkboxes
- Copy and delete actions

---

## 8. PDF PROCESSING

### Upload Flow (Template Creation)
1. Admin uploads PDF file via standard file input
2. **Server-side:** Use PHP library (Imagick/Ghostscript, or Spatie PDF-to-Image) to render each page as a PNG
3. Store PNGs at `storage/app/docuperfect/templates/{id}/page-{n}.png`
4. Create template record with `page_count`, empty `fields_json`
5. Redirect to template editor

**Alternative (Client-side):** Keep using pdf.js in the browser to render pages, upload page images to server via AJAX. This avoids server-side PDF dependencies but means larger uploads. The current system does this — it works.

**Recommendation:** Client-side pdf.js rendering, upload page images to server. Simpler, no server dependencies, proven approach.

### Download Flow (PDF Export)
1. User clicks "Download PDF"
2. **Client-side:** jsPDF creates a PDF document
3. For each page: add page image as background, render field values on top (text, lines, selections)
4. Browser downloads the generated PDF

This is exactly how the current system works and it's fine. Keep it client-side.

---

## 9. BUILD PHASES

### Phase 1 — Core System (Target: 1-2 days)
- Database migrations
- Models with relationships and scopes
- Template CRUD (upload, list, archive, copy, delete)
- Document CRUD (create from template, list, archive, delete)
- Clause CRUD (list, add, edit, copy, delete, branch visibility)
- Blade views for list/management screens (Nexus layout, design system)
- Sidebar integration
- Page image storage (upload via client-side pdf.js, store in storage/)
- Serve page images via authenticated route

### Phase 2 — Interactive Editor (Target: 2-3 days)
- Template editor JS module (field placement, move, resize, delete)
- Document editor JS module (field filling, strikethrough toggle, selection, dates)
- Inline toolbar (font, size, bold, underline, solid background)
- Clause selection modal (AJAX load clause list, insert into condition field)
- Field properties editor (selection options)
- Save via AJAX (fields_json)
- PDF download (client-side jsPDF)

### Phase 3 — Data Migration (Target: 0.5 day)
- Script to read Docuperfect SQLite DB
- Extract base64 page images → save as PNG files
- Map users/branches to Nexus equivalents
- Import templates with fields
- Import documents with field values
- Import clauses
- Verify migrated data

### Phase 4 — Polish & Cutover (Target: 0.5 day)
- Agent testing
- Fix any field position discrepancies (% calculations should be identical)
- Verify PDF export matches current system quality
- Update Nexus sidebar to show Documents section
- Disable standalone Docuperfect server
- Redirect /docuperfect URLs if needed

### Phase 5 — Envelope/Signing System (Future)
- Send document for signature
- Secure token link for external signers
- Signature capture (draw or type)
- Audit trail (who signed when)
- Status tracking (draft → sent → partially signed → completed)
- Email notifications

---

## 10. NEXUS INTEGRATION POINTS

### Shared Data
- **Users:** Nexus `users` table — no duplicate user management
- **Branches:** Nexus `branches` table — no duplicate branch management
- **Roles:** Nexus role system (admin/BM/agent) maps directly to Docuperfect permissions

### Sidebar Position
Documents group should appear between "Client Portal" and "Agency Tracker" in the sidebar, or as a top-level item. It's used daily by agents, so it needs to be prominent.

### Cross-References
- When viewing a deal in the Deal Register, a "Documents" tab could show documents created for that deal (future: link document to deal via deal_id FK)
- Settlement could link to the OTP document that was filled for the deal
- This is Phase 5+ territory — not needed for initial launch

---

## 11. KEY DIFFERENCES FROM CURRENT SYSTEM

| Aspect | Current (Standalone) | Nexus Rebuild |
|--------|---------------------|---------------|
| Auth | Plaintext passwords, frontend role check | Laravel auth, server-side middleware |
| Users | Separate user table | Nexus users table |
| Branches | Separate branch table | Nexus branches table |
| Page images | Base64 in SQLite | PNG files in storage/ |
| API | Express/Node.js | Laravel controllers |
| Frontend | Single React SPA | Blade + JS editor module |
| Styling | Generic Tailwind | Nexus design system (navy/cyan) |
| Navigation | Separate header bar | Nexus sidebar |
| PDF upload | Client-side only | Client-side pdf.js + server storage |
| PDF export | Client-side jsPDF | Client-side jsPDF (unchanged) |
| DB | SQLite (standalone) | MySQL/MariaDB (Nexus DB) |

---

## 12. FIELD RENDERING SPECIFICATION

All field positions use **percentage coordinates** relative to page dimensions. This ensures fields align correctly regardless of display size.

### Position Calculation
```
x_pixels = (field.position.x / 100) * container_width
y_pixels = (field.position.y / 100) * container_height
width_pixels = (field.size.width / 100) * container_width
height_pixels = (field.size.height / 100) * container_height
```

### Template Mode Rendering
- All fields show as semi-transparent coloured overlays with dashed borders
- Colour coding by type:
  - placeholder: blue (#3b82f6)
  - strikethrough: red (#ef4444)
  - selection: green (#22c55e)
  - initial/signature: amber (#fbbf24)
  - date: purple (#9333ea)
  - condition: teal (#0d9488)
- Selected field shows: move handle (top-left blue circle), delete button (top-right red circle), resize handle (bottom-right blue dot)
- Selection fields show properties editor below when selected

### Document Mode Rendering
- placeholder: transparent text input, fills on click
- strikethrough: dashed border when inactive, red fill + strike line when active. Click to toggle.
- selection: shows all options, click to select one (selected = underlined, unselected = faded)
- initial/signature: shows signature line with label
- date: date input field
- condition: shows editable clause text (pre-filled from clause library)

### PDF Export Rendering (jsPDF)
For each field on each page:
- If `solidBackground`, draw white rectangle first (covers underlying PDF text)
- Set font (family, size, bold)
- **placeholder/date:** Render `field.value` as text with optional underline
- **signature/initial:** Draw signature line + label
- **selection:** Render `field.selectedValue` as text
- **strikethrough:** If `field.active`, draw line (horizontal through centre, or diagonal corner-to-corner)
- **condition:** Render `field.text` as wrapped text

---

## 13. TEMPLATE TYPE CATEGORIES

Templates are categorised for organisation:
- **sales** — OTPs, purchase agreements, condition reports
- **rentals** — lease agreements, rental applications
- **compliance** — FICA documents, mandate letters, disclosure forms

This is just a filter/label — no logic difference between types.

---

## 14. DATA MIGRATION SCRIPT OUTLINE

```php
// 1. Connect to Docuperfect SQLite DB
$sqlite = new PDO('sqlite:/path/to/docuperfect.db');

// 2. Map branches
$dpBranches = $sqlite->query('SELECT * FROM branches');
foreach ($dpBranches as $b) {
    // Find matching Nexus branch by name
    $nexusBranch = Branch::where('name', 'like', "%{$b['name']}%")->first();
    $branchMap[$b['id']] = $nexusBranch?->id;
}

// 3. Map users
$dpUsers = $sqlite->query('SELECT * FROM users');
foreach ($dpUsers as $u) {
    // Find matching Nexus user by username
    $nexusUser = User::where('name', 'like', "%{$u['username']}%")->first();
    $userMap[$u['id']] = $nexusUser?->id;
}

// 4. Import clauses
$dpClauses = $sqlite->query('SELECT * FROM conditionalClauses');
foreach ($dpClauses as $c) {
    DocuperfectClause::create([
        'name' => $c['name'],
        'text' => $c['text'],
        'is_global' => $c['isGlobal'] ?? true,
        'owner_id' => $userMap[$c['ownerId']] ?? 1,
    ]);
    // + branch pivot entries
}

// 5. Import templates (heavy — has page images)
$dpTemplates = $sqlite->query('SELECT * FROM templates');
foreach ($dpTemplates as $t) {
    $template = DocuperfectTemplate::create([...]);
    
    // Extract base64 page images → save as files
    $pageImages = json_decode($t['pageImages'], true);
    foreach ($pageImages as $i => $base64) {
        $imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#', '', $base64));
        Storage::put("docuperfect/templates/{$template->id}/page-{$i}.png", $imageData);
    }
}

// 6. Import documents
$dpDocs = $sqlite->query('SELECT * FROM userDocuments');
foreach ($dpDocs as $d) {
    DocuperfectDocument::create([
        'name' => $d['name'],
        'template_id' => $templateMap[$d['templateId']] ?? null,
        'fields_json' => $d['fields'],
        'owner_id' => $userMap[$d['ownerId']] ?? 1,
        'branch_id' => $branchMap[$d['branchId']] ?? null,
    ]);
}
```

---

## 15. RISKS AND CONSIDERATIONS

1. **Page image quality:** Current system renders at 2x device pixel ratio. Maintain this for crisp text on printouts.

2. **Field position accuracy:** Percentage-based coordinates must produce identical visual placement. If the page container aspect ratio differs between old and new UI, fields may shift. Test with existing templates.

3. **Storage size:** Each template page is ~500KB-1MB as PNG. A 7-page OTP = ~5MB. With 50 templates, that's ~250MB in storage. Manageable, but consider JPEG compression for preview thumbnails.

4. **Concurrent editing:** No locking mechanism. If two people edit the same document simultaneously, last save wins. Acceptable for now — agents work on their own documents.

5. **Template updates after documents exist:** If admin edits template fields after agents have created documents, existing documents retain their field snapshot (fields_json in document table). New documents get the updated template fields. This is correct behaviour.

6. **Large PDFs:** Some compliance documents may be 20+ pages. pdf.js handles this fine, but upload may be slow on poor connections. Show progress indicator.

7. **Mobile:** The field placement editor is desktop-only (drag-and-drop). Document filling could work on tablet. Template creation must be done on desktop.

---

## 16. ACCEPTANCE CRITERIA

Before cutover from standalone to Nexus:

- [ ] Admin can upload PDF → pages render correctly
- [ ] Admin can place all 7 field types on template pages
- [ ] Admin can move, resize, and delete fields
- [ ] Admin can set template visibility (global / specific branches)
- [ ] Agent can create document from template
- [ ] Agent can fill all field types (text, date, selection, strikethrough toggle, clause, initial, signature)
- [ ] Agent can add extra fields to a document
- [ ] Agent can download filled PDF
- [ ] Downloaded PDF matches visual quality of current system
- [ ] Clause library with branch visibility works
- [ ] All existing templates migrated with correct field positions
- [ ] All existing documents migrated with field values intact
- [ ] Nexus sidebar shows Documents section
- [ ] Role-based access enforced server-side
- [ ] Page images served via authenticated route (not publicly accessible)
- [ ] Archive and copy work for templates and documents
- [ ] No performance regression (page load < 3 seconds, PDF export < 5 seconds)
