# E-Sign V2 Audit Report — April 29, 2026
## Spec vs Reality: March 27 Spec Verified Against Current Codebase

---

## A. SPEC VS REALITY DELTA

### 1. Core Files (Spec Section 2)

All 7 core files confirmed present at expected paths:

| File | Path | Lines | Change Since Spec |
|------|------|-------|-------------------|
| ESignWizardController.php | app/Http/Controllers/Docuperfect/ | 4,352 | No significant change |
| SignatureController.php | app/Http/Controllers/Docuperfect/ | 2,849 | No significant change |
| SigningController.php | app/Http/Controllers/Docuperfect/ | 2,521 | **Modified Apr 22** |
| SignatureService.php | app/Services/Docuperfect/ | 3,223 | Bug #4 FIXED |
| SignaturePdfService.php | app/Services/Docuperfect/ | 432 | No change |
| WebTemplateDataService.php | app/Services/ | 1,135 | No change |
| TemplateController.php | app/Http/Controllers/Docuperfect/ | 1,243 | No change |

**NEW since March 27:**
- `app/Services/Docuperfect/WebTemplatePdfService.php` (535 lines, added Apr 22) — new service for web template PDF generation

### 2. Template Configuration (Spec Section 3)

**Template model methods — ALL CONFIRMED at expected locations:**
- `isEsignBlocked()` — Template.php:149-160 ✓
- `getAllowedDeliveryModesArray()` — Template.php:165-169 ✓
- `allowsDeliveryMode()` — Template.php:174-181 ✓
- `getEffectiveDeliveryModes()` — Template.php:186-196 ✓
- `isSalesDocument()` — Template.php:122-143 ✓ (3-layer on server side)

**Triple-enforced block — CONFIRMED at 3+ locations:**
1. Model: Template.php:149-160 — checks template_type + name patterns ✓
2. Client JS: wizard.blade.php:1642-1649 — removes 'esign' from modes ✓
3. Server: ESignWizardController prepareSigning — lines 1341-1345 ✓
4. **BONUS**: Store method at ESignWizardController:133-142 (additional gate at Step 1)

**field_mappings.editable_by — SPEC SAYS "NONE POPULATED":**
- CDS builder UI checkboxes FULLY IMPLEMENTED (cds-builder.blade.php:472-507)
- 5 roles available: owner_party, acquiring_party, agent, witness, all
- **Status changed**: Infrastructure is more complete than spec states. Whether any template actually has data populated requires DB query.

**DELTA**: Spec line numbers for wizard JS are slightly shifted (spec: 1573-1580, actual: 1642-1649). This is normal drift from code additions.

### 3. Wizard Flow (Spec Section 4)

**6 steps — CONFIRMED** ✓

**Context detection — CHANGED from spec:**
- Spec says "3-layer detection" — actual wizard JS has **4 layers** (wizard.blade.php:1332-1351):
  1. Layer 1: signing_parties explicit roles
  2. Layer 2: **Template category** (NEW — not in March 27 spec)
  3. Layer 3: Property source table
  4. Layer 4: Template name pattern (fallback)
- Server-side `isSalesDocument()` still has 3 layers (unchanged)
- **Layer priority is now correct** — category and property source precede name fallback

**Bug #1 (contact filtering) — PARTIALLY FIXED:**
- esign_role filtering IS NOW wired into Step 3 (ESignWizardController:501-512)
- `buildAllowedEsignRoles($signingParties)` called to filter contacts
- Search API also uses esign_role (ESignWizardController:960-975)
- **STILL BROKEN for rental_properties**: Line 492 condition `if ($propertyId && $propertySource === 'properties')` explicitly excludes rental_properties from auto-populate
- Code comment at line 491: "Load contacts from properties table (rental_properties has no contacts relationship)"

**Bug #2 (rental fields) — PARTIALLY FIXED:**
- 4-layer detection with category inserted before property source improves behaviour
- Category layer can force correct context when set
- **Still relies on template category being SET** — if category is null, falls through to property source and then name pattern

### 4. Signing Flow (Spec Section 5)

**FICA gate — CONFIRMED WORKING:**
- SigningController.php:96-133 — checks `fica_required` on signature_request
- Status check at line 98: `whereIn('status', ['submitted', 'under_review', 'agent_approved', 'approved'])` ✓
- fica-gate.blade.php exists with "Complete FICA Form" button (line 68) ✓
- return_url built at SigningController:110 ✓

**Agent approval gate — CONFIRMED:**
- `review()` at SignatureController:2081 ✓
- `approveAndAdvance()` at SignatureController:2275 ✓
- Calls `$this->signatureService->approveAndAdvance($template)` at line 2294

**FICA auto-submission — CONFIRMED:**
- ESignWizardController:1796-1813 — creates FicaSubmission at send time ✓
- Checks existing submissions first (line 1797)
- Creates with 14-day token expiry (line 1809)

**fica_required default:**
- Migration default: `false` (2026_03_26_300000 migration, line 12)
- Wizard UI default: `true` (wizard.blade.php:1704 — `r.fica_required = saved.fica_required ?? true`)
- **Effective behaviour**: ON by default for users (UI overrides DB default)

### 5. Amendments (Spec Section 10)

**ALL COMPONENTS CONFIRMED PRESENT:**
- DocumentAmendment model: types (addition/strikeout/modification), statuses (pending/accepted/rejected), SoftDeletes ✓
- amendment-review.blade.php: exists with accept/reject UI + signature pad ✓
- Endpoints: acceptAmendment() at SigningController:2475, rejectAmendment() at SigningController:2498 ✓
- Routes: web.php:1889-1891 (shifted from spec's 1245-1251 — normal drift) ✓
- Clause flags: SigningController:1062-1065 — stored in `web_template_data['clause_flags']` ✓
- Other Conditions auto-amendments: SigningController:1114-1149 — creates amendment, triggers halting flow ✓
- AmendmentAcceptance model + table exists ✓

**Clause flags still inert** — collected but do NOT create amendments. Spec accurate.

### 6. FICA Officer Consolidation (April 21 work)

**COMPLETE:**
- `fica_officer_appointments` table: migration 2026_04_21_110001 ✓
- Migration 2026_04_21_110002 migrated old data, renamed old tables with `_deprecated_20260421` suffix
- **No references to old tables in e-sign code** — fully migrated ✓

### 7. New: WebTemplatePdfService.php

**Not in March 27 spec. Added April 22, 2026.**
- 535 lines
- Dedicated service for web template PDF generation
- Augments (doesn't replace) SignaturePdfService

---

## B. CURRENT BUG STATUS

| # | Bug | March 27 Status | Current Status | Evidence |
|---|-----|----------------|----------------|----------|
| 1 | All contact types show on all templates | OPEN | **PARTIALLY FIXED** | esign_role filtering wired (ESignWizardController:501-512). Rental properties auto-populate still broken (line 492 excludes rental_properties). |
| 2 | Rental documents show sales fields | OPEN | **PARTIALLY FIXED** | 4-layer detection added (wizard.blade.php:1332-1351). Category layer helps when set. Still falls through to name pattern if category null. |
| 3 | My Documents menu error | UNCLEAR | **FIXED** | View exists (esign/my-documents.blade.php), route exists at web.php:1632, controller method exists. |
| 4 | Duplicate entry on document_contact | OPEN | **FIXED** | SignatureService.php:1723-1736 now uses `DB::table('document_contact')->updateOrInsert()` — atomic, no race condition. |
| 5 | Final PDF missing initials | OPEN | **STILL OPEN** | SignaturePdfService.php:298-300 still explicitly skips initials: `if (in_array($type, ['signature', 'initial'])) { continue; }` |
| 6 | Ellie bubble overlaps Next button | OPEN | **FIXED** | ellie-widget.blade.php:2 now has `bottom: 90px` (was 24px). |

**Summary: 3 fixed, 2 partially fixed, 1 still open.**

---

## C. MARKETING PERMISSION READINESS

### What Already Exists

A Marketing Permission CDS template **already exists**: `resources/views/docuperfect/web-templates/cds/template-116.blade.php`

**Current state of template-116:**
- Title: "MARKETING PERMISSION"
- Context: Rental (lessor grants marketing permission to rent)
- Signature block: `["Lessor", "Agent"]` (line 53)
- Uses `@include("docuperfect.web-templates.components.signature-line")` (lines 33, 50)
- Uses `@include("docuperfect.web-templates.components.signature-block", ["parties" => ["Lessor", "Agent"]])`
- Fields bound: `contact_full_names`, `contact_address`, `contact_phone`, `deal_amount`, `deal_commission_percent`, `property_district`, `deal_amount_words`
- **Hardcoded company info** in a table at line 34 (HFC address, reg numbers, FFC, VAT)

### Gaps Preventing V11 Drop-In

| # | Gap | Severity | Detail |
|---|-----|----------|--------|
| 1 | **Hardcoded company info** | HIGH | Line 34 has HFC's address, reg no, FFC, VAT hardcoded in HTML. Should use dynamic agency data or a component. Blocks multi-tenancy. |
| 2 | **Many fields not data-bound** | HIGH | Multiple blank fields use inline `style="border-bottom:1px solid #333"` with no `data-field` attribute. These can't be auto-populated from the system. |
| 3 | **Inconsistent field mapping** | MEDIUM | `deal_amount_words` used for Township field (line 20) — clearly wrong mapping. Several fields mapped to wrong data sources. |
| 4 | **No version tracking** | MEDIUM | Template-116 appears to be an older version. No indication whether it's V11 or earlier. V11 content needs to be verified against the physical document. |
| 5 | **Rental-only scope** | LOW | Current template is rental-only (lessor/agent). If V11 needs to support BOTH sales AND lettings, a second template or conditional logic needed. |
| 6 | **Bug #5 (initials in PDF)** | LOW | If Marketing Permission requires initials on each page, the PDF generation bug means initials won't appear in the final PDF. For a single-page document this is less critical. |
| 7 | **Bug #1 partial** | LOW | Rental properties don't auto-populate contacts. Agent must manually add recipients for rental property marketing permissions. Workaround exists (manual add). |

### What WORKS Today for Marketing Permission

- Template-116 IS in the wizard (assuming `is_esign=true` and proper `allowed_delivery_modes`)
- Signing flow: Lessor + Agent parties render correctly
- FICA gate will work (default ON)
- Agent signs first, then lessor receives link
- Amendment system for Other Conditions works
- Auto-filing on completion works
- Agency signing parties include "Lessor" and "Agent" ✓

---

## D. RECOMMENDATIONS

Smallest set of changes to make Marketing Permission V11 work end-to-end:

### Priority 1: Replace template-116 content with V11 (BLOCKER)

1. **Get the V11 physical document** — confirm exact clauses, fields, and layout
2. **Rebuild template-116.blade.php** with:
   - All blank fields properly data-bound with `data-field` attributes
   - Company header via `@include("docuperfect.web-templates.components.company-header")` (already included line 14)
   - Remove hardcoded company footer table (line 34) — replace with component or dynamic data
   - Correct field mappings (fix `deal_amount_words` used as Township)
3. **Update `field_mappings`** in the database for template 116:
   - Map all fields to correct data sources
   - Set `filled_by` for each field
   - Optionally set `editable_by` for fields the lessor should complete at signing

### Priority 2: Verify template DB record

4. **Check `docuperfect_templates` row** for template 116:
   - `is_esign` = true
   - `allowed_delivery_modes` includes 'esign'
   - `signing_parties` = `["owner_party", "agent"]`
   - `template_type` is NOT 'sale_agreement' or 'otp'
   - `category` = 'Rentals' (ensures correct context detection via Layer 2)
   - `party_mode` = 'shared' (single doc, both sign same copy)

### Priority 3: Fix remaining field mapping issues

5. **Ensure WebTemplateDataService resolves all fields** — verify that `contact_full_names`, `contact_address`, `contact_phone`, `deal_amount`, `deal_commission_percent`, `property_district` all resolve correctly for the rental property + linked contact context.

### NOT Required (defer)

- Bug #5 (initials in PDF): Marketing Permission is single-page, initials less critical
- Bug #1 rental auto-populate: Manual add works as workaround
- Bug #2 context detection: Setting category='Rentals' on the template record bypasses the name-pattern fallback
- Editable-at-signing: Not needed unless lessor must fill fields (can be added later via `editable_by`)

### Execution Order

```
1. Obtain V11 physical document from Johan
2. Rebuild template-116.blade.php with correct content + proper field bindings
3. Verify/update docuperfect_templates DB row (category, signing_parties, modes)
4. Test wizard flow: pick template → pick rental property → add lessor → fill fields → sign → send
5. Verify PDF generation produces correct output
6. Verify auto-filing links to correct contact + property
```

---

## Appendix: File Reference Quick-Lookup

| Item | File:Line |
|------|-----------|
| isEsignBlocked() | app/Models/Docuperfect/Template.php:149-160 |
| isSalesDocument() | app/Models/Docuperfect/Template.php:122-143 |
| Triple-block (server) | ESignWizardController.php:1341-1345 |
| Triple-block (JS) | wizard.blade.php:1642-1649 |
| Contact filtering | ESignWizardController.php:501-512 |
| Rental exclusion | ESignWizardController.php:492 |
| 4-layer context (JS) | wizard.blade.php:1332-1351 |
| FICA gate | SigningController.php:96-133 |
| FICA auto-create | ESignWizardController.php:1796-1813 |
| Bug #4 fix | SignatureService.php:1723-1736 |
| Bug #5 (open) | SignaturePdfService.php:298-300 |
| Bug #6 fix | ellie-widget.blade.php:2 |
| Amendment detection | SigningController.php:1114-1149 |
| Clause flags | SigningController.php:1062-1065 |
| Marketing Permission CDS | web-templates/cds/template-116.blade.php |
| WebTemplatePdfService (NEW) | app/Services/Docuperfect/WebTemplatePdfService.php |
| Amendment routes | web.php:1889-1891 |
| My Documents route | web.php:1632 |
