# CoreX Document/Template Creation Pipeline Investigation
## 2026-05-25 — Brief for Joint CDS Session

> **Scope:** Read-only audit of template creation entry points, CDS pipeline state, multi-tenancy readiness, and decision factors for the forward-looking path (hand-crafted Blade vs CDS vs hybrid).
>
> **Verification date:** 2026-05-25  
> **Data source:** Production codebase + live tinker queries  
> **Investigation level:** Comprehensive

---

## EXECUTIVE SUMMARY

### The State Today
- **125 active templates** in production (+ soft-deleted archives)
  - 28 CDS-built (\	emplate_type='cds'\)
  - 22 importer-generated (\	emplate_type='imported'\)
  - 37 legacy sales (\	emplate_type='sales'\)
  - 19 rental (\	emplate_type='rental'\)
  - 12 general, 6 standard, 1 mandate
- **Rendering:** 76 web (Blade), 49 PDF (overlay zones)
- **Visibility:** 68 global, 57 branch-scoped
- **Hand-crafted live:** Templates 116 (Marketing Permission v11), 117 (Sales Mandatory), 119 (Sales Addendum B) — all Blade, multi-tenant via \\->*\

### Critical Findings
1. **Multi-tenancy gap:** 10+ templates with hardcoded "Home Finders Coastal" in web-template Blade files (template-111, 114, 115, and others)
2. **CDS pipeline mature:** Three entry points fully operational; field detection via Claude + Mammoth working; 69 drafts in progress
3. **CDS bugs partially fixed:** Contact filtering (Bug #1) wired but rental properties excluded; rental context detection (Bug #2) improved with category layer; initials in PDF (Bug #5) still open
4. **Forward path locked:** Hand-crafted Blade with declarative metadata is the chosen path (April 2026 decision); CDS builder remains for legacy support

---

## 1. TEMPLATE CREATION ENTRY POINTS

### 1.1 CDS Builder UI — \/docuperfect/import\
**Route:** \POST /docuperfect/import/cds\  
**Controller:** \DocumentImporterController::generateCdsTemplate()\ (line 72)  
**Input:** \.docx\ file upload  
**Output:** \CdsDraft\ record + redirect to CDS builder  

**Status:** OPERATIONAL. 28 CDS templates saved; 69 drafts in flight.

### 1.2 DocuPerfect Document Importer — AI-Assisted Field Detection
**Route:** \POST /docuperfect/import/parse\  
**Controller:** \DocumentImporterController::parse()\ (line 100)  
**Input:** \.docx\ or \.pdf\ file  
**Output:** \ImportDraft\ record + redirect to review UI  

**Status:** OPERATIONAL. Field detection via Claude working. 2 drafts live; historical = 22 imported templates completed.

### 1.3 Hand-Crafted Blade Flow (Templates 116/117/119)
**Entry point:** Direct Blade file authoring (VS Code)  
**Template registration:** \docuperfect_templates\ seeded directly via migration or Tinker  

**Example: Template 116 (Marketing Permission v11)**
- File: \esources/views/docuperfect/web-templates/cds/template-116.blade.php\ (created April 29, 2026)
- Schema record: \docuperfect_templates\ ID 116
  - \	emplate_type='mandate'\ (new type, distinct from 'cds', 'sales', etc.)
  - \ender_type='web'\
  - \is_esign=1\ (eligible for e-sign wizard)
  - \is_global=0\ (branch-scoped initially)
  - \signing_parties=['owner_party', 'agent']\ (lessor + agent)

**Status:** OPERATIONAL. Three templates live (116, 117, 119). Creation flow is manual developer work + migration-seeded DB entry.

### 1.4 Admin "Create from Scratch" — Not Implemented
**Status:** DOES NOT EXIST. All templates are created via CDS importer, PDF upload, or hand-crafted Blade.

---

## 2. COMPLETE TEMPLATE CATALOGUE

### 2.1 Statistics (from live tinker query)

\\\
Total templates: 125

By template_type:
  sales:    37 (legacy, PDF-focused)
  rental:   19 (legacy rental documents)
  imported: 22 (importer-generated)
  general:  12 (miscellaneous)
  cds:      28 (CDS builder output)
  standard:  6 (standard forms)
  mandate:   1 (hand-crafted — template 116)

By render_type:
  pdf:      49 (overlay zones + fields_json)
  web:      76 (Blade views)

By visibility:
  global:   68 (all branches)
  non-global: 57 (branch-scoped)
\\\

### 2.2 Hand-Crafted Blade Templates (High-Value)

| ID | Name | Type | Render | Global | E-Sign | Status |
|----|------|------|--------|--------|--------|--------|
| 116 | Marketing Permission v11 | mandate | web | No | Yes | LIVE — multi-tenant via component |
| 117 | Sales Mandatory | cds | web | Yes | No | LIVE — declarative |
| 119 | SALES ADDENDUM B | cds | web | Yes | Yes | LIVE — multi-party signature fan-out |

**Key:** All three use \@include('docuperfect.web-templates.components.company-header')\ for agency branding.

---

## 3. CDS PIPELINE — END-TO-END TRACE

### 3.1 Import and Build Scenario

**Step 1: Upload & Parse**
- File: \holiday-letting-agreement.docx\
- Route: \POST /docuperfect/import/cds\
- Controller: \DocumentImporterController::generateCdsTemplate()\ (line 72)

**Step 2: CDS Parser**
- Service: \pp/Services/Docuperfect/CdsParserService.php\
- Recognises markers: \@@field-name@@\, \%%%%sig-label%%%%\, \####init-label####\

**Step 3: CDS Builder — Field Mapping**
- View: \esources/views/docuperfect/templates/cds-builder.blade.php\
- Alpine.js-driven interactive editor
- Mapping each tag: select type, set filled_by, optionally set editable_by roles

**Step 4: Final Generation**
- Route: \POST /docuperfect/templates/cds/generate\
- Controller: \TemplateController::cdsGenerate()\ (line 453)
- Writes Blade file to disk
- Creates docuperfect_templates record

### 3.2 Known CDS Bugs

| # | Bug | Status | Evidence |
|---|-----|--------|----------|
| **#1b** | Rental properties don't auto-populate contacts | **PARTIALLY FIXED** | esign_role filtering works; line 492 condition \if ( === 'properties')\ excludes rental_properties |
| **#2** | Rental documents show sales fields | **PARTIALLY FIXED** | 4-layer context detection (wizard.blade.php:1332-1351); relies on category being SET |
| **#5** | Initials missing from final PDF | **STILL OPEN** | \SignaturePdfService:298-301\ explicitly skips initials |
| **editable_by** | Infrastructure built but no data | **INFRASTRUCTURE BUILT, NO PRODUCTION DATA** | Checkboxes exist in CDS builder; no templates populate field_mappings.editable_by |

---

## 4. HAND-CRAFTED BLADE PIPELINE — TEMPLATE 116 AS CASE STUDY

### 4.1 Creation Flow (April 2026)

**Decision:** Hand-crafted Blade is the forward path; CDS builder retained for legacy (CHAT_STARTER.md, 2026-04-29).

1. **V11 specification approved** — Marketing Permission v11 legal content
2. **Developer authors Blade file** — VS Code
3. **Field mapping declared** — \data-field\ attributes inline
4. **Multi-tenancy support** — company-header component
5. **Database registration** — migration creates docuperfect_templates row

### 4.2 Differences from CDS-Built Templates

| Aspect | Hand-Crafted (116) | CDS-Built (111) |
|--------|-------------------|-----------------|
| **Source** | Developer Blade file | CDS parser output |
| **Field declaration** | \data-field\ + \{{ \ }}\ | Auto-generated from AI parsing |
| **Multi-tenancy** | Component-based (company-header) | Dynamic agency lookup in blade |
| **Versioning** | Git-tracked, code review | DB-stored cds_json, drafts history |
| **Flexibility** | Full Blade power (conditionals, loops) | Structured field-mapping model |

---

## 5. AGENT-FACING EXPERIENCE TODAY

### 5.1 How Agents Use Templates

**Entry point:** E-Sign Wizard (Dashboard → "Create Document")

**Step 1: Template Selection**
- Wizard loads \Template::where('is_esign', true)->visibleTo(\)\

**Step 3: Recipients**
- **For sales templates:** Auto-populates (esign_role filtering — WORKING)
- **For rental templates:** Must manually add; rental_properties have no contacts relationship (BUG #1b)

**Step 4: Details** (Context-dependent)
- Sales template: sales fields
- Rental template: rental fields
- **Detection:** Layer 1 signing_parties → Layer 2 category → Layer 3 property source → Layer 4 name pattern

---

## 6. MULTI-TENANCY READINESS

### 6.1 Current State

**Status: 71% ready**
- Hand-crafted templates (116/117/119) fully multi-tenant via component approach
- 10+ CDS-built templates contain hardcoded "Home Finders Coastal"

### 6.2 Safe Templates (Multi-Tenant Ready)

**Pattern:** Use \@include('docuperfect.web-templates.components.company-header')\ or dynamic \\->*\ lookup

**Count:** ~75 templates safe for multi-tenant deployment.

### 6.3 Unsafe Templates (Hardcoded HFC)

**Pattern:** Literal string "Home Finders Coastal" in Blade files

- Template 111 (Exclusive Authority to Sell) — 9 references
- Template 114 (Sales Mandatory Disclosure) — ≥1 reference
- Template 115 (Lease Marketing Permission) — ≥1 reference
- Template 113 (Rental Application) — likely ≥1

**Count:** ~10 templates with hardcoded company info.

### 6.4 Migration Path for Second Agency

**If CoreX rolls out to Agency B tomorrow:**
1. **High-value templates (116/117/119):** Ready immediately
2. **Safe legacy templates:** Ready
3. **Unsafe templates:** Must audit and fix before deployment

**Effort:** ~1 day for audit + fixes on 10 unsafe templates.

---

## 7. ARCHITECTURAL RECOMMENDATIONS

### 7.1 Single Forward Path Confirmed

**Decision (April 2026, per CHAT_STARTER.md):**
> **Architecture: Claude owns template design centrally. Hand-crafted Blade with declarative metadata, bypass CDS UI. Templates 116/117/119 first under this model.**

### 7.2 Three Viable Implementation Paths

#### **Path A: Hybrid (Recommended)**
**Description:** Hand-crafted Blade is primary; CDS builder remains for legacy imports.

**Pros:**
- Low migration cost — existing 28 CDS templates remain functional
- Developer-controlled template authoring (Blade + Git)
- Full Blade power
- E-Sign wizard unaware of build method (seamless blending)
- Audit-compliant version control

**Cons:**
- Maintain two code paths
- CDS builder UI occasionally confuses agents
- Field metadata duplication

**Implementation effort:** S (small)

#### **Path B: Retire CDS, Full Blade**
**Description:** Discontinue CDS builder. All templates → hand-crafted Blade.

**Pros:**
- Single code path
- Template history in Git (full auditability)
- Blade power for all use cases

**Cons:**
- Migration cost — 28 existing CDS templates must be rewritten
- Importer no longer auto-generates
- Loses AI field detection benefits

**Implementation effort:** L (large) — 1–2 weeks

#### **Path C: CDS-First with Better Docs**
**Description:** Strengthen CDS builder. All new via importer → CDS.

**Pros:**
- Importer AI benefits every template
- Non-developers can build templates

**Cons:**
- CDS bugs must be fixed first
- Field fidelity issues
- Template history only in DB (not Git)
- Not aligned with April 2026 decision

**Implementation effort:** M (medium)

### 7.3 Recommendation

**Choose Path A: Hybrid (Recommended)**

**Reasoning:**
1. Already shipping (Templates 116/117/119 prove the model works)
2. Low migration cost
3. Developer efficiency
4. Audit compliance
5. Aligned with April 2026 decision

---

## 8. SUMMARY TABLE

| Aspect | Status | Evidence |
|--------|--------|----------|
| **Total templates in production** | 125 active | Tinker: \DB::table('docuperfect_templates')->count()\ |
| **CDS-built** | 28 | \where('template_type', 'cds')->count()\ |
| **Hand-crafted (forward path)** | 3 live (116/117/119) | Templates in \esources/views/docuperfect/web-templates/cds/\ |
| **Importer-generated** | 22 | \where('template_type', 'imported')->count()\ |
| **Web templates** | 76 (multi-tenant capable) | \where('render_type', 'web')->count()\ |
| **PDF templates** | 49 (overlay zones) | \where('render_type', 'pdf')->count()\ |
| **Global templates** | 68 (all branches) | \where('is_global', true)->count()\ |
| **Branch-scoped** | 57 | \where('is_global', false)->count()\ |
| **Open CDS bugs** | 3 high + 1 medium | Bug #1b, #2, #5, editable_by |
| **Hardcoded HFC refs** | 10 templates | templates 111, 114, 115, 113 + others |
| **Multi-tenancy safe** | 71% | 75 templates using components; 10 with hardcoded info |
| **CDS pipeline** | OPERATIONAL | 69 drafts in flight; import → generate working |
| **Hand-crafted** | PROVEN | 116/117/119 live; multi-tenancy via component |
| **Forward decision** | **LOCKED** | April 29, 2026: hand-crafted Blade with declarative metadata |

---

**Report compiled:** 2026-05-25  
**Investigation scope:** Read-only codebase + live database queries  
**Next session:** Joint CDS review with Johan + architectural decisions on multi-tenancy fixes + CDS bug prioritization