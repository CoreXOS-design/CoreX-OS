# CDS Template System Audit — 2026-05-21

**Audit purpose:** Snapshot the current state of the Custom Document System (CDS) — schema, controllers, services, views, the import pipeline, the field-tagging surface, the metadata surface and the integration with signing — so that the E-Sign V3 spec (`.ai/specs/esign-v3-complete-spec.md`) can be reconciled against what already exists in code. **Investigation only. No code changes.**

---

## Executive summary

CDS is **substantially more built than the V3 spec assumes**. There is a complete DB-backed draft pipeline (`cds_drafts` → `docuperfect_templates` with `template_type='cds'`), a working `.docx` import flow that already calls Claude (sonnet-4-6) with OpenAI fallback, a marker-based parser that recognises `@@@@` / `%%%%` / `####`, a live Alpine.js + `contenteditable` builder at `/docuperfect/templates/cds/builder/{draft}`, and a 21-row clause library at `docuperfect_clauses` (route-mounted but not yet wired into the builder). The metadata fields (`category`, `document_type_id`, `allowed_delivery_modes`, `security_tier`, `party_mode`, `signing_parties`) all exist on `docuperfect_templates`. **28 CDS templates are already saved** and **69 drafts** are in flight. The spec items that are *genuinely* net-new are: the `~~~~` insertable-block marker, the strikethrough-override → Other Conditions auto-routing flow, and the initialing cascade. The spec items that are misclassified — and need scope revision — are ES-6 (AI Template Import, *largely already built*) and the clause-library treatment (table exists, but builder-side insertion is missing).

---

## Section 1: Database schema

### 1.1 `docuperfect_templates` — main table (live columns)

```
id, name, template_type, render_type, blade_view, document_type_id,
category, page_count, fields_json, cds_json, field_mappings,
allowed_delivery_modes, security_tier, editor_state, is_global,
owner_id, archived_at, created_at, updated_at, deleted_at,
is_esign, party_mode, signing_parties, header_display, sections
```

Inferred purpose per column (from migrations + Template model + TemplateController usage):

| Column | Type | Default | Purpose |
|---|---|---|---|
| `name` | string | — | Display name |
| `template_type` | string | `'sales'` | Free-form text tag. Live values: `sales` (37), `rental` (19), `imported` (22), `general` (12), `cds` (28), `standard` (6), `mandate` (1). `cds` flips edit() to the CDS builder. |
| `render_type` | enum(pdf,web) | `'pdf'` | `pdf` = PDF + overlay zones. `web` = Blade view. 49 pdf, 76 web. |
| `blade_view` | string | null | Dotted view name for `web` templates, e.g. `docuperfect.web-templates.cds.template-126`. |
| `document_type_id` | bigint FK | null | → `document_types.id` (15+ slugs: mandate, fica, otp, etc.). |
| `category` | enum(sales,rentals) | null | UI dropdown. Live: 114 null, 9 sales, 2 rentals. |
| `page_count` | int | 0 | For PDF templates only. |
| `fields_json` | json | null | Legacy + overlay zones for PDF templates. CDS uses `cds_json` + `field_mappings` instead. |
| `cds_json` | json | null | Sectioned document structure produced by `CdsParserService`. |
| `field_mappings` | json | null | Per-tag config: typeKey, namedFieldId, label, party, editable_by. Keyed by `tag-id` string. |
| `allowed_delivery_modes` | string(100) | `esign,wet_ink,download` | Comma-separated CSV. |
| `security_tier` | string(20) | `enhanced` | UI: standard / enhanced / high. |
| `editor_state` | json | null | Round-trip restore — stores `tags`, `mappings`, `tagged_html` so re-edit is lossless. |
| `is_global` | bool | false | Visible to all branches. |
| `is_esign` | bool | true | Whether template can be sent for e-sign. Forced false for sale agreements via `Template::isEsignBlocked()`. |
| `party_mode` | string(20) | `shared` | `shared` (all sign one doc) or `per_party` (FICA-style copy per signer). |
| `signing_parties` | json | null | Array of generic role tokens: `owner_party`, `acquiring_party`, `agent`. Resolved to Seller/Buyer or Lessor/Lessee at render time via `Template::mapSigningPartyKeys()`. |
| `header_display` | string(20) | `first_page` | Letterhead placement: first_page / all_pages / none. |
| `sections` | json | null | Wizard-step sections override (set via wizard-config). |
| `wizard_config` | json | null | Step-builder config (see [wizard-config.blade.php](resources/views/docuperfect/templates/wizard-config.blade.php)). |
| `owner_id` | bigint FK | null | → `users.id` |
| `archived_at` | timestamp | null | "Archive" = setting this column. Soft-delete via `deleted_at` is **also** present. |

Indexes: `id` PK; FK on `owner_id` → users; FK on `document_type_id` → document_types.

### 1.2 `cds_drafts` — pre-save staging

```
id, user_id, agency_id, template_name, cds_json, tags, mappings,
tagged_html, settings, source_template_id, status, created_at,
updated_at, deleted_at
```

- Created when import lands or when an existing `template_type='cds'` is opened for edit.
- Carries the full editor state until `cdsGenerate()` is called.
- `source_template_id` links back to an existing template if this is an edit (so save UPDATES, not creates).
- `status`: `draft` or `saved`. Live counts: 69 rows; samples include "Exclusive Authority to Sell", "SALES ADDENDUM B", "Sales Mandatory Disclosure".
- Soft deletes via `deleted_at`.

### 1.3 `docuperfect_import_drafts` — pre-CDS-draft staging

```
id, user_id, filename, html, fields_json, created_at, updated_at, deleted_at
```

- Holds AI-detected fields between `/import/parse` (POST) and `/import/review` (GET).
- `fields_json` contains: `fields[]`, `tags[]`, `mappings{}`, `tagged_html`, `claude_originals[]`, `source_template_id?`.
- Auto-cleanup: drafts older than 4 hours are force-deleted on next parse.
- Live: 2 rows.

### 1.4 `docuperfect_named_fields` — system fields catalogue

```
id, name, field_type, default_options, sort_order,
source_type, source_column, source_contact_type,
created_at, updated_at, deleted_at
```

- `source_type` enum: `property | contact | agent | static | computed | manual | deal`.
- Drives the right-pane Type dropdown in the CDS builder ("Property", "Contact — Lessor", "Contact — Lessee", "Contact — Seller", "Contact — Buyer", "Agent", "Computed", "Static", "Manual").
- Source-mapped at seed time (see migration `2026_03_07_200004_add_source_mapping_to_named_fields.php`) — e.g. `Lessor Name` → `(contact, first_name+last_name, Lessor)`.

### 1.5 `docuperfect_field_groups` — pre-built field bundles

```
id, agency_id (nullable for globals), created_by, name, description,
fields (JSON array of {named_field_id, label_override}), layout (vertical|horizontal),
sort_order, is_global, created_at, updated_at, deleted_at
```

- Lets the builder insert a *group* of related fields in one tag, e.g. "Seller block (Name + ID + Address)".
- Surfaced under the right-pane Type dropdown as `Field Group → <fg_name>` (typeKey `fg:<id>`).

### 1.6 `docuperfect_clauses` — clause library

```
id, name, text, is_global, owner_id, created_at, updated_at, deleted_at
```

- 21 rows live. Samples: "Subject to Viewing", "Tenant Vacating Notice", "Holiday Letting".
- Routes mounted: `GET/POST/PUT/DELETE /docuperfect/clauses`, `POST /docuperfect/clauses/{id}/copy`, `GET /docuperfect/api/clauses` (JSON), `POST /docuperfect/clauses/{clause}/restore`.
- Branch-restriction join table: `docuperfect_clause_branches (clause_id, branch_id)`.
- **Not yet wired into the CDS builder.** There is no "insert clause" affordance in [cds-builder.blade.php](resources/views/docuperfect/templates/cds-builder.blade.php) — the table sits in isolation, managed via its own list page.

### 1.7 `document_types` — type catalogue (new table, replaces `docuperfect_document_types`)

```
id, slug, label, sort_order, is_active, grouping, listing_types,
created_at, updated_at, deleted_at
```

- 23 rows. Slugs include: `mandate, fica, ids, por, condition_report, listing_form, rates_taxes, body_corporate, house_rules, offer_to_purchase, disclosure, other, addendum, rental_agreement, lease_agreement` (+ 8 more for FICA / screening).
- Migration `2026_03_24_100003_repoint_docuperfect_templates_to_document_types.php` migrated FK from old `docuperfect_document_types` to new `document_types` table.
- `Template::documentType()` belongsTo this table. `DocumentType::$name` is a backward-compat accessor that returns `label`.

### 1.8 Packs (parent of slot-based document bundles)

| Table | Purpose | Cols |
|---|---|---|
| `docuperfect_packs` | Pack header | id, name, description, is_global, creation_mode, owner_id, soft deletes |
| `docuperfect_pack_templates` | Many-to-many template links (legacy) | pack_id, template_id, sort_order |
| `docuperfect_pack_slots` | Slot definitions (required/selectable/attachment) | pack_id, label, slot_type, template_id, document_type_id, knowledge_category_id, allow_multiple, is_optional |
| `docuperfect_pack_instance_values` | Run-time pack-instance field values | pack_instance_id, named_field_id, value |
| `docuperfect_pack_attachments` | Attached files for pack instances | (separate table) |

### 1.9 Web packs (newer flow, agency-scoped)

| Table | Purpose | Cols |
|---|---|---|
| `web_packs` | Agency-scoped pack | id, agency_id, created_by, name, description, soft deletes |
| `web_pack_items` | Templates in pack with slot semantics | web_pack_id, template_id, sort_order, slot_type, slot_group, slot_label |

### 1.10 `agency_signing_parties` — per-agency signature party catalogue

```
id, agency_id, name, sort_order, is_default, created_at, updated_at, deleted_at
```

- Live: 7 rows for agency_id=1. Default parties (agency 1, undeleted): `Agent`, `Witness`, `Seller`.
- Soft-deleted defaults retained as history: `Lessor`, `Lessee`, `Buyer`, an older `Seller`.
- Loaded into the CDS builder under "Signature Block Parties" panel (collapsible, drag-to-reorder, in-place rename, +/− party).

### 1.11 `signature_templates` (signing-side, lives on the document, not the template)

```
id, document_id, document_hash, status, document_version,
amendment_status, parties_json, signing_order_json, cosign_mode,
supersedes_id, superseded_by_id, created_by, is_candidate_flow,
supervisor_user_id, completed_at, rejected_at, rejection_reason,
cancellation_reason, cancelled_by, cancelled_at, rejected_by,
signed_pdf_path, signed_pdf_client_path, flattened_pages_json,
sections_json, other_conditions_text,           ← interesting for V3 §7.5
created_at, updated_at, deleted_at
```

Of note for the V3 spec reconciliation:

- **`other_conditions_text` (longText)** already exists on `signature_templates`. The spec assumes this needs to be added.
- **`sections_json`** also exists, likely supporting the per-section accept/approve flow.
- `amendment_status` is per-document-version — supports the amendment/re-send flows the V3 spec extends.

### 1.12 Signature zones (overlay coordinates for PDF templates)

```
docuperfect_template_signature_zones:
  id, template_id, page_index, x_position, y_position, width, height,
  type (signature|initial), assigned_parties (JSON), label, required,
  sort_order, created_at, updated_at, deleted_at
```

- Used only by `render_type='pdf'` templates. The CDS path embeds signature blocks inline in the generated Blade view instead.

### 1.13 Tables that DO NOT exist (despite naming guesses in the spec)

- `template_fields` — fields live as JSON inside `docuperfect_templates.fields_json` + `field_mappings`, not as relational rows.
- `template_parties` — party assignment is per-tag inside `field_mappings` JSON, not a separate table.
- `cds_field_links` — no Blade-var → CDS-placeholder mapping table; derivation is live in `TemplateController::deriveBladeName()`.
- `cds_clauses` — clauses are in `docuperfect_clauses`, the prefix is `docuperfect_*`, not `cds_*`.

### 1.14 Soft-delete coverage

Every CDS-relevant table has `deleted_at` and uses `SoftDeletes`: `docuperfect_templates`, `cds_drafts`, `docuperfect_import_drafts`, `docuperfect_named_fields`, `docuperfect_field_groups`, `docuperfect_clauses`, `docuperfect_packs`, `web_packs`, `web_pack_items`, `agency_signing_parties`, `signature_templates`. Non-negotiable #1 (no hard deletes) is satisfied.

---

## Section 2: Controllers & routes

### 2.1 Controllers under `app/Http/Controllers/Docuperfect/`

| Controller | LOC | Purpose |
|---|---|---|
| `TemplateController` | 1,253 | List, upload, edit (PDF/web/CDS branching), saveFields, archive/restore/copy/destroy, **cdsBuilder, cdsSaveMappings, cdsSaveDraft, cdsGenerate, cdsDestroyDraft**, webPreview, wizardConfig + saveWizardConfig. The CDS engine pipeline lives in this single file (lines 392–877). |
| `DocumentImporterController` | 1,124 | **Import pipeline.** index (upload form + draft list), parse (DOCX → DocxParserService AI), generateCdsTemplate (DOCX → CdsParserService → CDS draft), review (GET review view), saveMappings, generate, editFromTemplate (re-edit existing template), saveDraft, destroyDraft. Also agency-party CRUD (storeParty, updateParty, destroyParty, reorderParties, getParties). |
| `DocumentController` | — | Document-instance CRUD (not template-CRUD). |
| `ClauseController` | — | index, store, update, destroy, copy, restore + `listJson`. |
| `FieldGroupController` | — | index, store, update, destroy + JSON. |
| `PackController` | — | Pack CRUD + showLaunch / executeLaunch + restore. |
| `WebPackController` | — | Web-pack flavour. |
| `WebTemplateController` | — | (Sits separately from TemplateController; web-template-specific operations.) |
| `ESignWizardController` | — | E-sign creation + step routing + autosave + duplicateFicaPerParty + cancelDocument. |
| `SigningController` | — | Public signing URL handling. |
| `SignatureController` | — | Markers, zones, send-for-signature, etc. |
| `SalesDocumentController` | — | Sales-specific document handling. |
| `LeaseController` | — | Rental-specific. |
| `DashboardController` | — | Docuperfect home + create wizard. |
| `DocumentTypeController` | — | Document-type CRUD. |
| `NamedFieldController` | — | Named-field CRUD. |
| `PageImageController` | — | Serves PDF page images. |
| `PackInstanceValueController` | — | Pack-instance value POST/GET. |
| `SignatureController` | — | (already listed) |

### 2.2 Routes under `/docuperfect/templates/*` and `/docuperfect/import/*` (registered, named)

**Template management** — [routes/web.php:2108-2127](routes/web.php#L2108-L2127):
- `GET  /docuperfect/templates` → `docuperfect.templates.index`
- `POST /docuperfect/templates/upload` → `docuperfect.templates.upload` (PDF template upload)
- `GET  /docuperfect/templates/{id}/edit` → `docuperfect.templates.edit` (branches on template_type/render_type to PDF, web, or CDS)
- `GET  /docuperfect/templates/{id}/web-preview` → `docuperfect.templates.webPreview`
- `POST /docuperfect/templates/{id}/fields` → `docuperfect.templates.saveFields`
- `POST /docuperfect/templates/{id}/pages` → `docuperfect.templates.uploadPages`
- `POST /docuperfect/templates/{id}/archive` / `/restore` / `/copy`
- `DELETE /docuperfect/templates/{id}` (soft delete)
- `GET  /docuperfect/templates/{id}/wizard-config` + `POST` save

**CDS pipeline** — [routes/web.php:2111-2115](routes/web.php#L2111-L2115):
- `GET  /docuperfect/templates/cds/builder/{draft}` → `docuperfect.cds.builder`
- `POST /docuperfect/templates/cds/mappings` → `docuperfect.cds.mappings` (mid-edit incremental save)
- `POST /docuperfect/templates/cds/draft/save` → `docuperfect.cds.draft.save` (autosave every 60s)
- `POST /docuperfect/templates/cds/generate` → `docuperfect.cds.generate` (final Save Template — writes the blade view to disk and persists `template_type='cds'`)
- `DELETE /docuperfect/templates/cds/draft/{draft}` → `docuperfect.cds.draft.destroy`

**Import pipeline** — [routes/web.php:2240-2255](routes/web.php#L2240-L2255):
- `GET  /docuperfect/import` → `docuperfect.import.index` (upload page + drafts list)
- `POST /docuperfect/import/parse` → `docuperfect.import.parse` (DOCX → Mammoth + Claude → import-draft, redirects to review)
- `POST /docuperfect/import/cds` → `docuperfect.import.cds` (DOCX → CdsParserService → cds-draft, redirects to CDS builder)
- `GET  /docuperfect/import/review` → `docuperfect.import.review` (review/tag UI on the import-draft path)
- `POST /docuperfect/import/generate` → `docuperfect.import.generate` (final blade-write from import-draft)
- `POST /docuperfect/import/review/mappings` → `docuperfect.import.review.mappings`
- `POST /docuperfect/import/draft/save` → `docuperfect.import.draft.save`
- `DELETE /docuperfect/import/draft/{id}` → `docuperfect.import.draft.destroy`
- `POST /docuperfect/import/template/{id}/edit` → `docuperfect.import.template.edit` (re-edit existing web template via import-draft round-trip)
- `GET/POST/PUT/DELETE/POST(reorder) /docuperfect/import/parties` (agency signing parties CRUD)

**Permission middleware:** the whole `docuperfect` group is gated by `auth` + `permission:access_docuperfect`. Inside controllers every method additionally checks `$user->hasPermission('manage_templates')` (abort 403 if missing). No granular CDS-only permission.

### 2.3 Method signatures + 3-5 bullet flow per CDS / import method

**`TemplateController::cdsBuilder(CdsDraft $draft)`** — [TemplateController.php:394-503](app/Http/Controllers/Docuperfect/TemplateController.php#L394-L503)
- Auth: `manage_templates`; ownership check on `$draft->user_id`.
- Loads `CdsRendererService` and renders `$draft->cds_json` → initial HTML.
- Extracts field summary from CDS structure, loads `NamedField` grouped by source_type, loads `FieldGroup` (global + agency), loads agency parties.
- Determines if this is a *restore* (`hasSavedState = !empty(tags) && !empty(mappings)`).
- Returns view `docuperfect.templates.cds-builder` with cds, html, fields, grouped fields, field groups, parties, saved state, source template.

**`TemplateController::cdsSaveMappings(Request)`** — [TemplateController.php:505-517](app/Http/Controllers/Docuperfect/TemplateController.php#L505-L517)
- Updates `cds_drafts.tags`, `mappings`, `tagged_html` on the live draft. Returns `{status: 'saved'}`.

**`TemplateController::cdsSaveDraft(Request)`** — [TemplateController.php:519-546](app/Http/Controllers/Docuperfect/TemplateController.php#L519-L546)
- Logs `CDS_SAVE_DRAFT` with telemetry (tag count, mapping keys).
- Updates `template_name`, `tags`, `mappings`, `tagged_html`, `settings` on the draft. Returns `{status: 'saved', saved_at: ISO8601}`.

**`TemplateController::cdsGenerate(Request)`** — [TemplateController.php:548-608](app/Http/Controllers/Docuperfect/TemplateController.php#L548-L608)
- Builds a `$templateData` array with name, render_type=`web`, template_type=`cds`, cds_json, field_mappings, is_esign, party_mode, allowed_delivery_modes, security_tier, signing_parties (JSON-decoded from form), category, document_type_id, is_global=true.
- If `source_template_id` → `update()` else `create()`.
- Calls `generateCdsBladeView()` which writes `resources/views/docuperfect/web-templates/cds/template-{id}.blade.php` to disk.
- Updates `template->blade_view`.
- Runs `Artisan::call('view:clear')`.
- Marks draft as `saved`. Redirects to templates index.

**`DocumentImporterController::parse(Request)`** — [DocumentImporterController.php:100-238](app/Http/Controllers/Docuperfect/DocumentImporterController.php#L100-L238)
- Validates `.docx` upload (accepts `document` OR `docx_file` field).
- Saves to `storage/app/public/imports/temp/import_<uniqid>.docx`.
- Calls `DocxParserService->parse($fullPath)` → `{html, fields, warnings}`.
- Auto-cleanup: deletes `ImportDraft` rows older than 4 hours for current user.
- Persists result to `docuperfect_import_drafts` (filename, html, `fields_json={fields, claude_originals}`).
- Sets `session('import_draft_id')`, deletes temp file, returns `{success, redirect, warnings}`.

**`DocumentImporterController::generateCdsTemplate(Request)`** — [DocumentImporterController.php:72-94](app/Http/Controllers/Docuperfect/DocumentImporterController.php#L72-L94)
- Validates `.docx`.
- Calls `CdsParserService->parse(filePath)` → CDS structured JSON.
- Creates a `CdsDraft` with `cds_json`, `status='draft'`, `user_id`, `agency_id`.
- Redirects to the CDS builder for this draft.

**`DocumentImporterController::generate(Request, DocumentTemplateGenerator)`** — [DocumentImporterController.php:415-491](app/Http/Controllers/Docuperfect/DocumentImporterController.php#L415-L491)
- Loads import-draft, hard-deletes older drafts for user, then calls `$generator->generate($draft, $templateName, $user->id)`.
- On success: logs AI corrections (`FieldCorrection`), deletes draft, redirects to templates.edit.
- On failure: preserves draft, redirects back to review with error.

---

## Section 3: Import flow audit — CRITICAL

**TL;DR:** There are **two parallel import paths**, both AI-assisted, both functional. The V3 spec's ES-6 ("AI Template Import") is therefore *not net-new* — it is at best a *consolidation/polish* of what exists.

### 3.1 Where Import lives

- Route prefix `/docuperfect/import/*` ([routes/web.php:2240-2255](routes/web.php#L2240-L2255))
- Controller: [DocumentImporterController](app/Http/Controllers/Docuperfect/DocumentImporterController.php)
- View: [resources/views/docuperfect/importer/index.blade.php](resources/views/docuperfect/importer/index.blade.php)
- Sidebar entry: yes — surfaced in the docuperfect sidebar group ("Import Document").

### 3.2 Accepted file types

**`.docx` only.** PDF is not currently importable through this path. The validation is explicit: `mimes:docx` on both the Mammoth path (`/import/parse`) and the CDS path (`/import/cds`).

There is a separate **`ClaudeVisionParserService`** (308 LOC) which can take page *images* and pass them to Claude Vision, but it is **not wired into the import routes** — it appears to be experimental / used elsewhere (likely the PDF overlay path or the CMA pipeline).

### 3.3 What happens after upload — two paths

**Path A — Mammoth + Claude (`/import/parse` → review → generate):**

1. **Mammoth** (PHP package, called via `DocxParserService::generateHtmlWithMammoth()`) → HTML body.
2. **Header/signature stripping** — `stripDocumentHeader()` + `stripDocumentSignature()` regex out the company letterhead and bottom sig block from the source doc.
3. **Plain-text extraction** via `ZipArchive` on `word/document.xml` for the AI prompt (truncated to 25,000 chars).
4. **Regex blank detection** — finds underscores `___`, dots `....`, ellipsis runs `…` as candidate fields.
5. **Dual-engine AI mapping** via `ImporterAiService::detectFields()`:
   - **Primary: Claude API** (`claude-sonnet-4-6`, max_tokens 4000, timeout 60s). Auth key from `config('services.anthropic.key')`.
   - **Fallback: OpenAI** (`gpt-4o-mini`).
   - System prompt asks for a JSON-keyed map per blank-number → `{label, key, pillar, assigned_to, confidence}` with known field keys: `contact.full_name`, `contact.id_number`, `contact.address_residential`, `contact.cell`, `contact.email`, `property.address_full`, `property.erf_number`, `deal.rental_amount` etc.
6. AI corrections are logged to `field_corrections` table for learning (compare Claude's `claude_suggested_key` against the user's final mapping on `Generate`).
7. **Field-blank injection** — AI-detected fields are injected back as `<span class="field">{{ ... }}</span>` markers into Mammoth's HTML.
8. **CorexDocumentRenderer** re-styles the HTML.
9. Result stored in `docuperfect_import_drafts.html` + `fields_json`. User redirected to `/import/review` for tagging/linking.

**Path B — CdsParserService (`/import/cds` → CDS builder):**

1. ZIP-extracts `word/document.xml`, `word/styles.xml`, `word/numbering.xml` directly. No Mammoth.
2. Custom DOM/XPath parser builds a structured CDS section list: heading, title, clause (with resolved Word numbering: numId/ilvl → "1.1.2"), paragraph, table (with header detection), disclosure_checklist (YES/NO/N/A table detection).
3. **Marker-based field detection** — `detectMarkers()` splits text runs on `@{4,}` / `%{4,}` / `#{4,}` patterns into typed placeholders (`field_placeholder`, `signature_placeholder`, `initial_placeholder`). **This is the source-of-truth marker convention.**
4. **Context-aware identification** — `identifyFieldsFromContext()` scans the text before/after each marker for patterns like `/^I\s*\/\s*We\s*$/` → `contact.full_names` for "Owner Name(s)", `/id\s*(number|no)/i` → `contact.id_number`, currency `/\bR\s*$/i` → `deal.amount`, etc. (~50 patterns covering Contact/Property/Deal/Banking/Date/Time/VAT/Commission. Confidence: high/medium/low.)
5. **Signature section detection** at end-of-document via keyword scan ("signed", "thus done", "accepted and signed") + party-role extraction.
6. **Company-header table detection** — first table containing "reg no" + "ffc" + "vat" etc. is marked `company_header` so the renderer skips it.
7. Returns `{version: '1.0', title, extracted_at, original_text, sections: [...]}`.
8. Stored in `cds_drafts.cds_json`. User redirected to `/docuperfect/templates/cds/builder/{draft}`.

### 3.4 Where imported content lands

- **Path A:** Intermediate `docuperfect_import_drafts` table; review UI at `/import/review` lets user tag fields. On `Generate`, `DocumentTemplateGenerator` writes the Blade file and creates the template.
- **Path B:** Intermediate `cds_drafts` table; user lands directly in the CDS builder (`/templates/cds/builder/{draft}`) for tagging + settings + generate.

### 3.5 Field auto-tagging vs manual

- **Auto:** AI assigns each marker to a known field key with a confidence dot (green=high, orange=medium, grey=low) shown in the right pane.
- **Manual:** User can override every assignment via the right-pane Type dropdown (Single Field / Field Group), pick a NamedField, set party, set `editable_by` checkboxes (lessor/lessee/agent/witness/all).
- **Validate** button compares current HTML text against `cds_json.original_text` (word-by-word, allowing field replacements) and flags unexpected text drift.

### 3.6 Sample workflow

1. Agent uploads `.docx` at `/docuperfect/import`.
2. Picks one of two buttons:
   - **"Parse Document"** → Path A (Mammoth + Claude regex detect + AI map → review page).
   - **"Import CDS"** → Path B (CdsParser marker + AI label → CDS builder).
3. In the builder/review screen the agent:
   - Inspects the rendered document on the left (contenteditable).
   - Sees a right pane with each tag and its AI-suggested mapping.
   - Optionally clicks "Fix next →" to focus the next incomplete tag.
   - Sets Template Settings (Category, Document Type, Delivery Modes, Security Level, Signing Mode, eSign-eligible, Signing Roles).
   - Manages Signature Block Parties (add/rename/reorder/delete agency-level).
   - Adds extra tags by selecting text and clicking Input/Signature/Initial in the floating toolbar.
   - Clicks **Save Draft** at any time (autosave every 60s also runs).
   - Clicks **Validate** to compare current text to the parser's `original_text` for drift.
   - Clicks **Save Template** when "All N fields linked — ready to save" badge is green.

### 3.7 Classification of ES-6 for spec reconciliation

ES-6 ("AI Template Import") is **(b) An enhancement to existing Import, NOT net-new**. The spec needs revision to:

- Reference the existing dual-path `/import/parse` (Mammoth + Claude) and `/import/cds` (CdsParser + marker convention) routes by name.
- Acknowledge `ImporterAiService` (`claude-sonnet-4-6` primary, `gpt-4o-mini` fallback) as the AI engine — do not propose a new gateway.
- Acknowledge `CdsParserService::detectMarkers()` and `identifyFieldsFromContext()` as the canonical placeholder pipeline.
- Acknowledge that the Validate button + `original_text` drift check already exists.
- Reframe ES-6 as polish: smarter prompt for Other-Conditions detection, vision-import path for PDFs (`ClaudeVisionParserService` wiring), prompt tuning informed by `field_corrections` table, etc.

---

## Section 4: Field tagging / placeholders

### 4.1 Storage

Placeholders live in **three** stores depending on the lifecycle stage:

| Stage | Where | Shape |
|---|---|---|
| Source `.docx` | Markers in the document text | `@@@@` / `%%%%` / `####` |
| Import-draft (Path A) | `docuperfect_import_drafts.fields_json` | `{fields: [...], tags: [...], mappings: {...}, tagged_html, claude_originals: [...]}` |
| CDS draft (Path B) | `cds_drafts.cds_json` + `.tags` + `.mappings` + `.tagged_html` | `cds_json` = sectioned structure; `tags` = array of `{id, type, number, label}`; `mappings` = `{<tag-id>: {mappingType, typeKey, namedFieldId, party, parties, editable_by, ...}}`; `tagged_html` = live editor HTML |
| Saved template | `docuperfect_templates.cds_json` + `.field_mappings` + `.editor_state` | Same three blobs; `editor_state` is the lossless round-trip backup for re-edit |
| Generated blade view | `resources/views/docuperfect/web-templates/cds/template-{id}.blade.php` | `<span class="corex-field-value" data-field="seller_name">{{ $seller_name ?? '' }}</span>` |

### 4.2 Placeholder marker syntax (full)

Agent places markers in the source `.docx`:

| Marker | Meaning | Detected by |
|---|---|---|
| `@@@@` (≥4 @) | Input field — where data is filled in | `CdsParserService::detectMarkers()` |
| `%%%%` (≥4 %) | Signature block — where parties sign | same |
| `####` (≥4 #) | Initial block — where parties initial | same |

These are the **only** markers used. The earlier underscore-based detection (`detectFieldPlaceholders()` matching `_{3,}` / `\.{4,}` / `…{2,}`) is **labelled legacy** in the code (`CdsParserService.php:188-194`) and the new marker pipeline supersedes it.

### 4.3 In the builder UI

The CDS builder UI renders tagged markers as `<span class="doc-tag doc-tag-input" data-tag-id="tag-...">[Seller Name Surname ID]</span>` — the `[...]` shape in the screenshot is a *display label* derived from the mapped field, **not** the raw stored token. Color coding:

| Class | Color | Meaning |
|---|---|---|
| `.doc-tag-input` | red `#dc2626` | Unmapped input |
| `.doc-tag-input-linked` | teal `#0d9488` | Linked to a NamedField |
| `.doc-tag-input-manual` | orange `#ea580c` | Manual custom label |
| `.doc-tag-signature` | amber `#f59e0b` | Unassigned signature |
| `.doc-tag-signature-assigned` | dark amber `#b45309` | Signature with party |
| `.doc-tag-initial` | green `#16a34a` | Unassigned initial |
| `.doc-tag-initial-assigned` | dark green `#15803d` | Initial with party |

### 4.4 Resolver at signing time

[TemplateController::deriveBladeName()](app/Http/Controllers/Docuperfect/TemplateController.php#L1066-L1122) maps each tag's `(source_type, source_column, source_contact_type)` to a Blade variable name. The wizard renders `view($template->blade_view, $viewData)` where `$viewData` is keyed by the same derived names (e.g. `seller_name`, `property_erf_number`, `monthly_rental`). The variable-naming convention:

- `contact + Lessor + first_name+last_name` → `lessor_name`
- `contact + Seller + id_number` → `seller_id_number`
- `property + erf_number` → `property_erf_number`
- `property + address` → `property_street`
- `property + suburb` → `property_township`
- `property + complex_name` → `property_complex_name`
- `property + rental_amount` → `monthly_rental`
- `property + price` → `price`
- `deal + <col>` → `<col>`
- `agent + name` → `agent_name`
- `computed + <col>` → `<col>` (e.g. `price_in_words`)

### 4.5 Field Group concept

Field Groups (`docuperfect_field_groups`) let one placeholder represent a *bundle* of related fields. E.g. "Seller Block" might bundle `Seller Name` + `Seller ID` + `Seller Address` with horizontal layout. The builder right-pane Type dropdown surfaces `Field Group → <name>` (typeKey `fg:<id>`). At render time `DocumentTemplateGenerator::processTagSpans()` expands the group inline using the configured layout.

### 4.6 Validation logic ("All N fields linked — ready to save")

JS getters on the Alpine `cdsEditor()` component ([cds-builder.blade.php:942-994](resources/views/docuperfect/templates/cds-builder.blade.php#L942-L994)):
- `totalTagCount = tags.length`
- `linkedCount = tags.filter(isTagComplete).length`
- A tag is **complete** if:
  - `input` → `mappingType` is set AND `manualLabel` (manual) OR `namedFieldId` (named_field) OR `fieldGroupId` (field_group).
  - `signature` or `initial` → `parties.length > 0`.
- `allMapped = totalTagCount > 0 && outstandingCount === 0`
- The amber "X of Y linked — Z outstanding" badge and emerald "All N linked — ready to save" badge swap based on `allMapped`.
- The **Save Template** button is rendered in all three states (outstanding, all linked, no tags yet) — outstanding does not block save, it just changes the cue colour.

---

## Section 5: Template metadata

### 5.1 Category dropdown

- Values: `sales`, `rentals` only (DB-enum, see migration `2026_03_24_300001_add_category_to_docuperfect_templates.php`).
- "Rentals" **is** an option (V3 spec assumed; confirmed). Stored as `category` on `docuperfect_templates`. Live distribution: 9 sales, 2 rentals, 114 null.
- Drives `Template::isSalesDocument()` and the dynamic re-labelling of "Lessor/Lessee" ↔ "Seller/Buyer" in the builder UI.

### 5.2 Document Type dropdown

- Sourced from `document_types` table (slug + label). 23 rows live.
- Slugs include: `mandate`, `fica`, `ids`, `por`, `condition_report`, `listing_form`, `rates_taxes`, `body_corporate`, `house_rules`, `offer_to_purchase`, `disclosure`, `other`, `addendum`, `rental_agreement`, `lease_agreement`, plus 8 FICA/screening sub-types.
- Stored as `document_type_id` FK on `docuperfect_templates`.

### 5.3 Delivery Modes (multi-checkbox)

- Three checkboxes: **E-Sign**, **Wet Ink**, **Download**.
- Stored as CSV string in `allowed_delivery_modes` (default `esign,wet_ink,download`).
- `Template::isEsignBlocked()` overrides this: sale-agreement / OTP templates *cannot* select esign regardless of checkbox state (Alienation of Land Act).

### 5.4 Security Level dropdown

Three values:
- `standard` — "Standard (ID + DOB)"
- `enhanced` — "Enhanced (+ Email OTP)" (default)
- `high` — "High (+ SMS OTP)"

Stored as `security_tier` (string(20)).

### 5.5 Signing Mode dropdown (the cut-off field)

Two values (stored as `party_mode` string(20)):
- `shared` — "Shared — all parties sign same document" (default)
- `per_party` — "Per Party — one copy per signer (e.g. FICA)"

This is the field truncated in the screenshot.

### 5.6 Other Template Settings below the dropdown

- **E-Sign Eligible** checkbox (`is_esign`, bool, default true).
- **Document Signing Roles** checkbox group — three options:
  - `owner_party` (dynamically labelled "Seller / Owner" or "Lessor / Landlord")
  - `acquiring_party` ("Buyer / Purchaser" or "Lessee / Tenant")
  - `agent`
  - Stored as JSON array in `signing_parties` on `docuperfect_templates`.

The generic-role token approach means a single template can be tagged once with `owner_party` and the render layer resolves to the right human label depending on `category` / name heuristics.

### 5.7 Field source — where dropdown values are defined

| Setting | Defined in |
|---|---|
| Category enum | DB schema (`enum('sales','rentals')`) |
| Document Type list | DB rows in `document_types` (slug + label) |
| Delivery Modes | Hard-coded checkboxes in [cds-builder.blade.php:276-289](resources/views/docuperfect/templates/cds-builder.blade.php#L276-L289) and validated server-side as CSV. |
| Security Level | Hard-coded options in same view, lines 295-300. |
| Signing Mode | Hard-coded options in same view, lines 305-311; server-side validated against `['shared', 'per_party']`. |
| Signing Roles | Hard-coded checkbox triplet `owner_party`/`acquiring_party`/`agent` in same view, lines 324-345. |

---

## Section 6: Parties / signature blocks

### 6.1 Two-tier party model

CDS distinguishes **agency parties** (the *signers* — Agent, Witness, Seller, Lessor, etc.) from **template signing roles** (`owner_party`, `acquiring_party`, `agent` — the *generic role tokens* that get resolved at render time).

### 6.2 `agency_signing_parties` — agency-scoped

- Per-agency catalogue, seeded with defaults via `AgencySigningParty::seedDefaultsForAgency()`.
- The "+ Add Party" button creates new agency-level party types (e.g. "Second Witness", "Spouse").
- Soft delete + sort_order + is_default flag.
- Loaded in the CDS builder under the **Signature Block Parties** collapsible panel, with:
  - Drag handle (visual only — reorder via `POST /import/parties/reorder`).
  - Click-to-rename inline (PUT `/import/parties/{id}`).
  - Per-row delete (DELETE `/import/parties/{id}`, blocked if only 1 remains).
  - "+ Add Party" form.

### 6.3 `signing_parties` on `docuperfect_templates`

- Per-template JSON array of generic role tokens: typically `['owner_party', 'agent']` or `['owner_party', 'acquiring_party', 'agent']`.
- Resolved at render via [Template::mapSigningPartyKeys()](app/Models/Docuperfect/Template.php#L214-L224):
  - Sales: `owner_party → Seller`, `acquiring_party → Buyer`, `agent → Agent`
  - Rental: `owner_party → Lessor`, `acquiring_party → Lessee`, `agent → Agent`
- Used by the generated Blade view's `signature-block` component to render the appropriate party blocks.

### 6.4 Per-tag party assignment

For each individual signature/initial tag, the `field_mappings[tag-id].parties` array stores **which agency parties sign at THIS specific position**. This is independent of `signing_parties`. The mapping is by `name` (e.g. `'Seller'`, `'Witness'`), not by role token, because at this stage the user has resolved to concrete party names.

At blade-generation time, [TemplateController::extractPartiesFromTagSpan()](app/Http/Controllers/Docuperfect/TemplateController.php#L959-L985) maps these back to the resolver tokens:
- `owner_party → seller`, `acquiring_party → buyer`, `lessor → landlord`, `lessee → tenant` (lowercase normalization).
- Emits one `signature-line` blade include per assigned party at the tag position.

### 6.5 Signing order

- Not stored on the template — handled at the **document instance level** via `signature_templates.signing_order_json`. Templates only declare *who* signs, not in what order. The order is set per-document in the e-sign wizard.

### 6.6 Optional vs required

- All template-level signing parties are required. Optionality is per-document (a recipient can decline), not per-template.
- Individual tag-level optionality exists in `docuperfect_template_signature_zones.required` but only for PDF-overlay templates (not CDS).

### 6.7 Wizard integration

The e-sign wizard reads:
1. `template.signing_parties` (the role tokens) → resolves to actual party display names (Seller/Buyer/Lessor/Lessee/Agent) using `Template::isSalesDocument()`.
2. `template.field_mappings[*].parties` (the per-tag agency-party assignments) → determines which party signs at each position.
3. `template.party_mode` — `shared` produces one document; `per_party` (FICA) duplicates per signer.

---

## Section 7: Builder UI

### 7.1 Underlying technology

**Plain `<div contenteditable="true">` + Alpine.js**. No Quill, no TinyMCE, no Tiptap. The editor surface is a single `#docContainer` div with `contenteditable=true`. All tagging, formatting, and undo logic is hand-rolled in the Alpine `cdsEditor()` component (~1,500 LOC of JS embedded in the Blade file).

### 7.2 How tagging happens

Three mechanisms:

1. **Floating toolbar** appears when user selects text. Three primary buttons: **Input** (red), **Signature** (amber), **Initial** (green) — plus standard B/I/U formatting buttons.
2. **Active-tool mode** — clicking a tool button in the right pane (Tag Tools section) puts the editor into "click-to-place" mode (cursor: crosshair); next click in the document inserts a tag at that position.
3. **Marker conversion on init** — fields auto-detected during import (CDS spans with `data-marker-type`) are converted into `doc-tag` spans automatically on page load. Click-handlers attach to each.

A tag becomes a `<span class="doc-tag doc-tag-input" data-tag-id="tag-<uuid>" contenteditable="false">[Label]</span>` — the `contenteditable=false` prevents editing the tag content; the user can drag/select/delete it as an atomic unit.

### 7.3 Validate button

Compares the current visible text (excluding tag contents, via TreeWalker that rejects nodes inside `.doc-tag`) against the parser-stored `cdsJson.original_text`. Normalizes whitespace, splits into words, walks both arrays. Differences within ~20 words are treated as field replacements (OK); larger gaps surface as warnings in a modal with side-by-side red/blue diff snippets. **No server call — purely client-side.**

### 7.4 Save Draft vs Save Template

- **Save Draft** → `POST /docuperfect/templates/cds/draft/save` — persists `tags`, `mappings`, `tagged_html`, `settings` on the `cds_drafts` row. No blade file written. Reversible — user can come back and resume.
- **Save Template** → `POST /docuperfect/templates/cds/generate` — final action. Persists/updates `docuperfect_templates`, writes the blade file to disk, clears the view cache, marks the draft `saved`. Returns to templates index.
- Pre-flight: `Save Template` calls `_doSaveDraft()` first to ensure the latest tags/mappings are persisted before the generate POST is submitted.

### 7.5 Auto-save

`_startAutoSave()` runs `setInterval` every 60s, calls `_doSaveDraft(false)` (silent — no toast). Also updates the "Saved Xs/m/h ago" label every 15s.

### 7.6 Undo

Custom undo stack on the Alpine component, max 20 entries. Each tag insertion/deletion pushes the previous state onto the stack. Visible `Undo (N)` button + keyboard handler (Ctrl/Cmd+Z).

### 7.7 Import vs Edit mode

**Same builder, different entry points + state.** `TemplateController::edit()` branches:
- `template_type='cds'` → creates a `CdsDraft` (or reuses existing one for same user) seeded from `template->cds_json` + `template->editor_state` + settings → redirects to CDS builder. Banner shows "Editing existing template #{id} — Saving will update the existing template instead of creating a new one."
- `render_type='web'` (non-CDS) → `editWeb()` view (Blade-template-specific editor at [edit-web.blade.php](resources/views/docuperfect/templates/edit-web.blade.php)).
- Otherwise → PDF editor at [edit.blade.php](resources/views/docuperfect/templates/edit.blade.php) (overlay-zone editor over PDF page images).

The Import path enters the builder for a *new* (no `source_template_id`) draft. The Edit path enters with `source_template_id` set, which causes `cdsGenerate()` to UPDATE the existing template instead of CREATE.

---

## Section 8: Integration with signing

### 8.1 Path from `cds_structure` → rendered HTML/PDF

1. `cdsGenerate()` writes a Blade file to `resources/views/docuperfect/web-templates/cds/template-{id}.blade.php`.
2. The Blade file contains:
   - `<link href="/css/corex-document.css">`
   - `@include("docuperfect.web-templates.components.company-header")`
   - The document body (corex-h1, corex-clause divs) with `<span class="corex-field-value" data-field="<var>">{{ $<var> ?? '' }}</span>` placeholders.
   - `@include("docuperfect.web-templates.components.signature-block", ["parties" => ["Seller", "Buyer", "Agent"]])` (or "Lessor/Lessee/Agent" or whatever the resolver decided).
3. `template->blade_view` stores the dotted path.
4. At signing time, the e-sign wizard renders `view($template->blade_view, $viewData)->render()` where `$viewData` is keyed by the same derived variable names produced by `deriveBladeName()`.

### 8.2 Is there a CDSRenderService?

Yes: `app/Services/Docuperfect/CdsRendererService.php` (407 LOC). But it only renders `cds_json` → preview HTML for the **builder pane** (the left-pane HTML you see while editing). The final saved Blade is generated by `TemplateController::generateCdsBladeView()` (in the controller, lines 705-877) — it post-processes either user-edited `tagged_html` (preferred, line 716) or falls back to `CdsRendererService` output (legacy path, line 718).

Render functions in `CdsRendererService`:
- `renderHeading`, `renderClause`, `renderParagraph`, `renderTitle`, `renderTable`, `renderLabelValueGroup`, `renderSignatureSection`, `renderInlineSignature`, `renderPageInitials`, `renderDisclosureChecklist`.

### 8.3 How hand-crafted Blade templates coexist with CDS templates

The rendering pipeline branches on `template->render_type`:

| `render_type` | `template_type` | Editor | Render path |
|---|---|---|---|
| `pdf` | sales / rental / standard / etc. | PDF overlay editor (edit.blade.php) | Overlay zones on `<img>` page-images of the PDF |
| `web` | (anything except cds) | Web template editor (edit-web.blade.php) | Hand-written Blade view |
| `web` | `cds` | CDS builder (cds-builder.blade.php) | Auto-generated Blade view at `web-templates/cds/template-{id}.blade.php` |

The wizard simply does `view($template->blade_view, ...)` for any `render_type='web'` template, regardless of whether the blade was hand-written or generated.

### 8.4 What happens when a CDS template is used in a Document Pack

- Packs reference templates by `template_id` via `docuperfect_pack_templates` (legacy) or `web_pack_items` (new) or `docuperfect_pack_slots` (slot-based).
- Pack launching (`PackController::executeLaunch`) creates one `Document` per slot, each linked to its template.
- A CDS template behaves identically to a hand-written web template at this layer — the pack engine doesn't care that the blade was generated.

### 8.5 Note on relevant `signature_templates` columns

The `signature_templates` table (per-document, not per-template) already has:
- `sections_json` — supports per-section approve/accept flow.
- `other_conditions_text` (longText) — **this column already exists**, which means the V3 spec's §7.5 Other Conditions block can store its text here rather than introducing a new table.
- `amendment_status` — already plumbed for the amendment flows the V3 initialing-cascade work extends.

---

## Section 9: Known issues / TODOs

Grep across `app/Services/Docuperfect/`, `app/Http/Controllers/Docuperfect/`, and CDS views surfaced:

1. **[CdsParserService.php:188-194](app/Services/Docuperfect/CdsParserService.php#L188-L194)** — Legacy underscore/dot detection (`detectFieldPlaceholders`, `labelFieldPlaceholders`, `detectLabelValuePairs`, `detectInlineSignatures`, `insertPageInitials`) is commented out as "Legacy: replaced by marker-based detection". The methods are *still in the file* (lines 1009-1147 and following). Dead code that could be removed.
2. **[TemplateController.php:718](app/Http/Controllers/Docuperfect/TemplateController.php#L718)** — `generateCdsBladeView()` has a "Legacy path: render from CDS JSON" branch for when `taggedHtml` is null. Maintained for backward compatibility with very old drafts (pre-`editor_state`) — likely safe to retire once all drafts are migrated.
3. **[SignatureService.php:1577](app/Services/Docuperfect/SignatureService.php#L1577)** — `// TODO: Send email notification to candidate about the return` (relates to e-sign supervisor return flow, not CDS proper).
4. **[SignatureService.php:1780](app/Services/Docuperfect/SignatureService.php#L1780)** — comment notes a fall-back to `merged_html` for "legacy / never-web-signed" documents. Not a TODO.
5. **No TODO/FIXME/HACK markers** in `TemplateController`, `DocumentImporterController`, `CdsParserService`, `CdsRendererService`, `DocumentTemplateGenerator`, `cds-builder.blade.php`, `importer/index.blade.php`, `importer/review.blade.php`. The surface is fairly clean.
6. **Broad exception handling** at [DocumentImporterController.php:478-490](app/Http/Controllers/Docuperfect/DocumentImporterController.php#L478-L490) — generate failure preserves draft + logs + redirects to review with the error message inline. Pragmatic.
7. **Soft-deleted agency parties not detached** — `agency_signing_parties` rows that have been soft-deleted (Lessor, Lessee, old Buyer/Seller for agency_id=1) remain visible to `forAgency()` scope unless the scope adds `whereNull('deleted_at')` consistently — worth a closer look during the V3 build if parties show up unexpectedly.
8. **`docuperfect_pack_slots.knowledge_category_id`** — referenced but no FK migration found; loose reference to an external knowledge module.
9. **Duplicate default 'Seller' rows** in `agency_signing_parties` for agency 1 (id 6 soft-deleted, id 10 active) — historical artifact, doesn't affect correctness but suggests party-management has had churn.
10. **CDS migration 400007** repoints FK from old `docuperfect_document_types` to new `document_types` (slug-based). The old table still exists. Cleanup candidate.

---

## Section 10: Reconciliation with E-Sign V3 spec

| V3 spec item | Already in CDS? | Gap / Action |
|---|---|---|
| `template_type` field | YES — `docuperfect_templates.template_type` string (live values include `cds`, `sales`, `rental`, `mandate`, `otp`, etc.). | Spec MUST use existing `template_type` field, not invent new. Distinguish from `document_type_id` (FK to slug catalogue) — both exist and serve different purposes. |
| `transaction_type` (sale/rental) | YES — `docuperfect_templates.category` enum(sales,rentals), plus the resolver chain in `Template::isSalesDocument()`. "Rentals" IS an option. | Spec should rename its proposed `transaction_type` to `category` to match existing column. |
| `delivery_modes` metadata | YES — `allowed_delivery_modes` CSV column + `Template::getEffectiveDeliveryModes()` enforcing the sale-agreement esign block. | Spec aligns. Reference existing CSV format and the `isEsignBlocked()` override rule for sale agreements. |
| `~~~~` placeholder for insertable blocks | NO — marker pipeline only recognises `@@@@`, `%%%%`, `####`. | TRULY NEW. Add `~{4,}` to `CdsParserService::detectMarkers()` regex + a new `insertable_block` type. Wire UI affordance into [cds-builder.blade.php](resources/views/docuperfect/templates/cds-builder.blade.php) right pane. |
| Clause library | EXISTS as table + routes + list page, but NOT WIRED into CDS builder. 21 rows live; sidebar shows "Clause Library" link. | Builder-side "Insert clause" affordance needs to be built (slash command, toolbar button, or right-pane "Insert from library" picker). The data and APIs are in place — `docuperfect.api.clauses` returns JSON; `Clause` model + agency/branch scoping exists. |
| AI Template Import (ES-6) | EXISTS as TWO parallel paths — Mammoth+Claude path and CdsParser+marker path. `ImporterAiService` uses `claude-sonnet-4-6` + `gpt-4o-mini` fallback. `field_corrections` table logs user corrections for prompt improvement. | RECLASSIFY ES-6 from "build" to "consolidate + polish". Either: (a) deprecate the Mammoth path and standardise on CdsParser, or (b) document why both exist and when each is used. Either way, spec ES-6 must reference existing services by name. |
| Insertable Other Conditions section | PARTIAL — `signature_templates.other_conditions_text` (longText) column exists, but no UI to populate it from the builder. | NEW UI work — spec §7.5 needs to wire builder → `other_conditions_text` storage, plus the strikethrough-routes-to-Other-Conditions flow. |
| Strikethrough overrides | NO — `contenteditable` editor allows formatting via B/I/U but no strikethrough button, and no auto-routing of struck text to a separate field. | TRULY NEW. Add strikethrough toolbar button + detection of strikethrough spans + auto-population of Other Conditions section. |
| Initialing cascade (replaces re-sign) | NO — current amendment flow has `amendment_status` per signature_template but no "only-changed-regions-need-initials" semantics. | TRULY NEW (per the revised §8). Builds on existing `amendment_status` column + amendment routes (`docuperfect.signatures.amendmentAction` already registered). |
| Party roles (Seller / Lessor switching) | YES — `Template::isSalesDocument()` + `mapSigningPartyKeys()` already switch labels dynamically. UI: `templateCategory` + Alpine `isSalesContext` getter in cds-builder. | Spec aligns — should reference the existing resolver chain (signing_parties + category + name heuristic 4-layer fallback) instead of re-inventing. |
| Agency-level signing parties | YES — `agency_signing_parties` per-agency with seedDefaults, soft-delete, sort_order. Wired into builder. | Aligned. |
| Per-tag signature party assignment | YES — `field_mappings[tagId].parties` array. Resolver maps `owner_party→seller/lessor`, `acquiring_party→buyer/lessee`. | Aligned. |
| Signing mode (shared / per_party) | YES — `party_mode` enum, FICA uses per_party via `duplicateFicaPerParty`. | Aligned. |
| Security tier | YES — `security_tier` (standard / enhanced / high). | Aligned. |
| Validate button (drift check) | YES — client-side TreeWalker diff against `cds_json.original_text`. | Aligned. Spec doesn't need to redesign this. |
| Lossless re-edit | YES — `editor_state` JSON on `docuperfect_templates` stores `tags`, `mappings`, `tagged_html` for re-edit round-trip. | Aligned. |
| `CdsDraft` table for autosave | YES — full table exists, autosave runs every 60s. | Aligned. |
| `ImportDraft` table for staged review | YES — `docuperfect_import_drafts` exists with auto-cleanup of older drafts. | Aligned. |
| AI corrections feedback loop | YES — `field_corrections` table logs `(claude_suggested_key, user_corrected_key, context)` per import. Not yet fed back into prompt. | Spec could propose tuning the AI prompt using this table — but the *capture* infrastructure is built. |
| Header display (first_page / all / none) | YES — `header_display` string(20). | Aligned. |
| Soft delete (no hard deletes) | YES — every CDS table has `deleted_at`, model uses `SoftDeletes`. | Aligned. |
| Permission gates | PARTIAL — only `manage_templates` permission gates everything. No granular CDS-vs-PDF split. | If spec wants finer-grained permissions, would need to add new keys to `config/corex-permissions.php`. |

### 10.1 Items in the spec that are TRULY net-new

1. `~~~~` insertable-block marker.
2. Strikethrough overrides + auto-route to Other Conditions.
3. Initialing cascade (changed-regions-only re-initial).
4. Builder-side clause-library insertion UI (data layer exists; UI does not).
5. Other-Conditions builder UI surface (DB column exists; UI to populate it does not).

### 10.2 Items in the spec that are MISCLASSIFIED and need scope revision

1. **ES-6 (AI Template Import)** — reclassify from "new build" to "consolidation + polish + vision-PDF wiring". Reference `ImporterAiService`, `DocxParserService`, `CdsParserService`, `field_corrections` table by name. Decide on Mammoth-vs-CdsParser path consolidation.
2. **Template metadata fields** — the spec implies a need for `template_type`, `transaction_type`, `delivery_modes`, `security_level`, `signing_mode` as new — but ALL FIVE already exist on `docuperfect_templates` with slightly different naming. Spec must adopt: `template_type`, `category`, `allowed_delivery_modes`, `security_tier`, `party_mode`.
3. **Clause library** — spec treats as new build; the *table + routes + list page + JSON API* already exist. Scope is the BUILDER-SIDE INSERT UI only.
4. **`other_conditions_text`** — the spec implies adding this column; it already exists on `signature_templates`. Spec needs to reference it, not duplicate it.

### 10.3 Top-3 spec revisions recommended

1. **Reframe ES-6** as "Import pipeline consolidation + vision PDF + Other-Conditions detection" instead of "build AI import from scratch". The build cost drops by ~70%.
2. **Adopt existing column names** (`template_type`, `category`, `allowed_delivery_modes`, `security_tier`, `party_mode`, `signing_parties`) throughout §4-§5 of the spec, rather than introducing parallel field names. Otherwise migrations will conflict.
3. **Split the clause-library work** into "data layer (done)" vs "builder insertion UI (new)" so the build estimate matches reality. Mention `docuperfect.api.clauses` JSON endpoint as the data source for the picker.

---

*End of audit. Generated 2026-05-21 against branch `HFC2402` at the state of commit `38f57e7`.*
