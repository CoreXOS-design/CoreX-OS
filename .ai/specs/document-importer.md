# Document Importer — CoreX OS Spec
**File:** .ai/specs/document-importer.md
**Status:** Approved — ready for development
**Last updated:** 2026-03-09

---

## What it does

Accepts a Word docx upload, converts it to a web-rendered
HTML template, detects all fillable fields, maps them to
the four pillars or creates custom fields, assigns a
responsible party per field, and registers the result as
a live docuperfect_templates record.

## What it does NOT do

- Does not guarantee pixel-perfect layout reproduction
- DOES guarantee 100% exact wording, clause text, and
  field count
- Small layout differences are acceptable and expected

---

## Where it lives

Settings → Document Importer
Route: /corex/settings/document-importer
Permission: manage_document_templates

---

## The Four Pillars

Every field maps to one of:
- Property (address, erf_no, rental_amount, etc.)
- Contact (name, id_number, email, cell — with contact_type:
  Lessor/Lessee/Agent/Buyer/Seller)
- Deal (lease_start, lease_end, deposit, commission_percent)
- Agent (agent_name, ffc_no)
- Manual (no auto-fill — party fills at signing time)

---

## Field Detection — Bold Run Method

Word docx XML encodes fillable blanks as bold runs of
underscores. This is the detection mechanism.

Parser rules (per run in each paragraph):
1. Bold + all underscore characters → FIELD BLANK
2. Bold + text content → potential label (check if it
   precedes a field blank on same line)
3. Not bold → clause text, render verbatim

---

## Auto-Label Rules (context-based)

Read up to 60 chars of text immediately before each
detected field blank. Apply these rules in order
(first match wins):

| Left context contains | Auto-label | Pillar | Party |
|----------------------|-----------|--------|-------|
| "Owner/s", "Lessor", "Landlord" | lessor_name | Contact | Lessor |
| "Lessee", "Tenant", "Occupant" | lessee_name | Contact | Lessee |
| "Agent" | agent_name | Agent | Agent |
| "property known as", "property described", "premises" | property_address | Property | — |
| "R" immediately before blank | rental_amount | Deal | — |
| "%" immediately after blank | commission_percent | Deal | Agent |
| "Erf no", "Erf number" | erf_no | Property | — |
| "Unit no" | unit_no | Property | — |
| "Complex" | complex_name | Property | — |
| "ID", "Passport", "Registration No" | id_number | Contact | (nearest party) |
| "Email" | email | Contact | (nearest party) |
| "Cell", "Tel", "Phone" | phone | Contact | (nearest party) |
| "day of" (date context) | signed_day | Deal | — |
| "20" immediately after blank (year) | signed_year | Deal | — |
| month context (between day and year) | signed_month | Deal | — |
| "Account Holder" | bank_account_name | Contact | Lessor |
| "Bank Name" | bank_name | Contact | Lessor |
| "Account Number" | bank_account_number | Contact | Lessor |
| "Branch" + "Code" | bank_branch_code | Contact | Lessor |
| "commission" + "%" | commission_percent | Deal | Agent |
| No match | custom_field_{n} | Manual | (user assigns) |

Confidence scoring:
- Direct label match → HIGH (auto-assign, no review needed)
- Partial match → MEDIUM (pre-fill suggestion, user confirms)
- No match → LOW (user must name and assign)

---

## min-width Rules for Inline Fields

| Context | min-width |
|---------|-----------|
| "day" nearby | 30pt |
| "month" nearby | 80pt |
| "year" / "20__" nearby | 30pt |
| "R" prefix (currency) | 100pt |
| "%" suffix | 40pt |
| Name fields | 150pt |
| Address fields | 200pt |
| Default | 120pt |

---

## Custom Fields (no pillar match)

When a field cannot be mapped to an existing
docuperfect_named_fields record, create a
document_custom_fields record:

Table: document_custom_fields
Columns:
- id
- docuperfect_template_id (FK)
- field_key (auto-slug from label)
- field_label (user-provided or auto-generated)
- field_type (text | date | number | selector)
- selector_options (JSON — for selector type)
- assigned_to (agent | lessor | lessee | buyer | seller)
- sort_order
- created_at, updated_at, deleted_at (SoftDeletes)

---

## Party Assignment

Every field (pillar-mapped or custom) gets an
assigned_to value:
- System auto-fill → no party (fills automatically)
- Agent fills → agent
- Lessor fills → lessor (at their signing step)
- Lessee fills → lessee (at their signing step)
- Buyer/Seller → buyer / seller

Party assignment is set during the field review step
and stored in both fields_json and document_custom_fields.

---

## Re-import / Update Flow

When a document is re-imported (updated Word doc):
1. Parse new version
2. Diff against existing fields_json
3. Fields present in old but not new → flagged as
   "removed" (kept but marked inactive)
4. Fields present in new but not old → flagged as
   "new — needs mapping"
5. Existing mapped fields → preserved, no change needed
6. User reviews only the diff, not the full document

---

## User Flow — Step by Step

Step 1: Upload
- User uploads docx file
- System parses: extracts typography, paragraphs,
  tables, images, bold runs
- System identifies all field blanks
- System auto-labels with confidence scores

Step 2: Preview + Field Review
- Show rendered HTML preview of document
- All detected fields highlighted:
  - GREEN: high confidence auto-label
  - ORANGE: medium confidence — confirm suggested label
  - RED: no match — user must label and assign
- User can click any field to:
  - Change label
  - Change pillar mapping
  - Change field type (text/date/number/selector)
  - Set assigned_to party
  - Mark as system auto-fill

Step 3: Confirm + Generate
- User clicks "Generate Template"
- System creates:
  - Blade web-template in
    resources/views/docuperfect/web-templates/
  - docuperfect_templates record
  - document_custom_fields records for unmapped fields
  - Preview route in web.php
- Template immediately available in DocuPerfect

---

## Template Generation Rules

Generated blade file must:
1. Use exact same structural shell as existing templates
   (DOCTYPE, @page CSS, screen/print media queries)
2. Include company-header component
3. Use exact typography from parsed docx XML:
   - Font family per paragraph
   - Font size per paragraph
   - Bold/italic where present in source
   - Paragraph alignment
4. Render clause text verbatim — not one word changed
5. Replace field blanks with .field spans using
   correct min-width from context rules
6. Include signature-block component at end
7. Register controller method in WebTemplateController
8. Register preview route

---

## Files to Create/Modify

New files:
- app/Services/DocxParserService.php
- app/Services/DocumentTemplateGenerator.php
- app/Http/Controllers/DocumentImporterController.php
- resources/views/settings/document-importer/index.blade.php
- resources/views/settings/document-importer/review.blade.php
- database/migrations/xxx_create_document_custom_fields_table.php

Modified files:
- routes/web.php (new routes)
- app/Http/Controllers/WebTemplateController.php
  (generated methods added dynamically)
- resources/views/corex/settings.blade.php
  (navigation link to importer)
- CoreXPermissionSeeder.php
  (manage_document_templates permission)

---

## Acceptance Criteria

- Upload a docx → template generated in under 10 seconds
- All bold underscore blanks detected (0 missed fields)
- 80%+ of fields auto-labeled without user input
- Generated template wording matches source docx 100%
- Party assignment works — correct party sees correct
  fields at their signing step
- Re-import detects new fields correctly
- 894 tests pass after implementation