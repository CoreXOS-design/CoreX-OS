# Recipient Loop Engine B3 — Signing Code Audit
**Date:** 2026-05-26  
**Scope:** Pre-code investigation for editable-field scoping at signing time  
**Purpose:** Map current code before implementing per-recipient field availability control

---

## 1. Field-Save Endpoints

### Route Definition
- **File:** routes/web.php
- **Line:** 2665–2666
- **Routes:**
  - POST /sign/{token}/save-fields → SigningController@saveFields (line 2665)
  - POST /sign/{token}/save-web-fields → SigningController@saveWebFields (line 2666)
- **Prefix:** sign (line 2656)
- **Middleware:** None explicit (public, token-based auth via SignatureRequest lookup)

### Controller Methods

#### saveFields()
- **File:** app/Http/Controllers/Docuperfect/SigningController.php
- **Line:** 988–1056
- **Signature:** public function saveFields(Request \, \)
- **Authorization:**
  - No explicit abort_if() or policy check
  - Implicit: Only updates fields where assignedTo matches \->party_role (line 1026)
  - Expiry check: if (\->isExpired()) { return 410; } (line 994–996)
- **Request Validation:**
  - Accepts: \->input('fields', []) — array of field objects
  - Each field object shape: { id, value, selectedValue, active, text } (lines 1020–1038)
  - No explicit \->validate() call; consumed inline
- **Recipient/Template Lookup:**
  - \ = SignatureRequest::where('token', \)->with('template.document')->firstOrFail(); (line 990–992)
  - Resolves via unique token (one token = one recipient instance)
- **Party-Role Authorization:**
  - Line 1004: \ = \->party_role;
  - Lines 1024–1026: Compares \[\]['assignedTo'] against normalized party role
  - Role aliases (line 1008): ['lessor' => 'landlord', 'lessee' => 'tenant']
- **Audit Logging:**
  - Lines 1043–1053: Creates SignatureAuditLog entry with action='fields_saved'

#### saveWebFields()
- **File:** app/Http/Controllers/Docuperfect/SigningController.php
- **Line:** 1063–1112
- **Signature:** public function saveWebFields(Request \, \)
- **Authorization:**
  - Expiry check: line 1069–1071
  - No explicit policy check
- **Request Validation:**
  - Accepts: \->input('fields', []) — field name → value map
  - Lines 1087–1090: Only allows field names in \ (from WebTemplateFieldPartyMap::getEditableFields())
- **Recipient/Template Lookup:**
  - Line 1065–1067: Same SignatureRequest::where('token', \) pattern
- **Party-Role Authorization:**
  - Line 1079: \ = \->party_role;
  - Line 1082: \ = WebTemplateFieldPartyMap::getEditableFields(\);
  - Lines 1088–1090: Whitelist-based — rejects any field not in allowedFields
- **Audit Logging:**
  - Lines 1099–1109: Creates SignatureAuditLog entry with action='web_fields_saved'

**Key Observation:** Both endpoints currently check party_role but NOT instance-specific scope. B3 will need to extend this to reject fields that the recipient's role does NOT have permission for at signing time.

---

## 2. Editable-Field Resolution

### Entry Point in show() Method
- **File:** app/Http/Controllers/Docuperfect/SigningController.php
- **Line:** 274–284

#### For CDS/Field-Mapped Templates
`
\ = \['field_mappings'] ?? [];
if (!empty(\)) {
    \ = \->getEditableFieldsFromMappings(
        \,
        \->party_role
    );
}
`

#### For Traditional Web Templates
`
else {
    \ = WebTemplateFieldPartyMap::getEditableFields(\->party_role);
}
`

### getEditableFieldsFromMappings() (Private Method)
- **File:** app/Http/Controllers/Docuperfect/SigningController.php
- **Line:** 1364–1402
- **Signature:** private function getEditableFieldsFromMappings(array \, string \): array
- **Returns:** Array of field names (e.g., ['lessor_name', 'lessor_email'])
- **Logic:**
  - Lines 1367–1376: Map \ to CDS editable_by role (e.g., 'landlord' → 'owner_party')
  - Lines 1381–1399: Iterate field mappings; include field if 'all' in editable_by OR \ in editable_by
  - Line 1390–1391: Normalize field names: replace . with _, remove non-alphanumeric
- **Consumed By:**
  - Line 434 in show() method: Passed to view as \

### WebTemplateFieldPartyMap::getEditableFields()
- **File:** app/Services/WebTemplateFieldPartyMap.php
- **Line:** 264–267
- **Signature:** public static function getEditableFields(string \): array
- **Returns:** Static list of field names for the party
- **Data Source:** PARTY_MAP constant (lines 16–231)
- **Parties Supported:**
  - 'landlord' (lines 17–54): 54 fields
  - 'tenant' (lines 56–102): 47 fields
  - 'agent' (lines 104–144): 41 fields
  - 'buyer' (lines 146–150): 3 fields
  - 'seller' (lines 152–156): 3 fields
  - 'system' (lines 158–230): 73 computed/auto-filled fields

---

## 3. Items-Remaining Counter

### UI Element
- **File:** resources/views/docuperfect/signatures/external/sign.blade.php
- **Line:** 420
- **Element:** Progress bar showing signedCount / totalRequired

### Alpine Data Source
- **File:** resources/views/docuperfect/signatures/external/sign.blade.php
- **Line:** 233: <div x-data="externalSign()" x-init="init()" ...>
- **Data Initialization (line 1339–1340):** signedCount and totalRequired set from controller

### Server-Side Population
- **File:** app/Http/Controllers/Docuperfect/SigningController.php
- **Line:** 425–426 (passed to view): 'signedCount' => \, 'totalRequired' => \

**Current Behavior:** Counts markers/items assigned to the recipient's party role. B3 must filter this list to respect instance-specific scope.

---

## 4. SignatureAuditLog Model

### Class Definition
- **File:** app/Models/Docuperfect/SignatureAuditLog.php
- **Line:** 8–100
- **Table:** signature_audit_log (line 12)
- **Status:** EXISTS — fully implemented

### API Signature
- **Factory Method (line 73–99):**
  public static function log(SignatureTemplate \, string \, string \, ...)

### Existing Call Sites (Examples)
1. **Field Save (line 1043):** action='fields_saved'
2. **Web Field Save (line 1099):** action='web_fields_saved'
3. **Electronic Consent (line 1146):** action='electronic_consent_given'

---

## 5. Template::roleDisplayLabel Signature

### Method Definition
- **File:** app/Models/Docuperfect/Template.php
- **Line:** 299–325

### Full Signature & Body
`
public static function roleDisplayLabel(
    string \,
    bool \,
    ?int \ = null,
    int \ = 1,
): string {
    // ... implementation
    if (\ > 1 && \ !== null) {
        return \ . ' ' . \;
    }
    return \;
}
`

### Behavior Confirmation
- **When totalInstancesForRole > 1 AND instanceIndex !== null:** returns "{base} {instanceIndex}" (e.g., "Seller 2")
- **Otherwise:** returns "{base}" only (e.g., "Seller")

---

## 6. Recipient Lookup from Token

### Route-to-Controller Chain
- **Route Definition:** routes/web.php line 2657
  Route::get('/{token}', [SigningController::class, 'show'])->name('signatures.external');
- **Prefix:** sign (line 2656)
- **Full URL:** GET /sign/{token}

### Controller Entry Point
- **File:** app/Http/Controllers/Docuperfect/SigningController.php
- **Method:** show(Request \, \) (line 41)
- **Recipient Lookup (line 43–45):**
  \ = SignatureRequest::where('token', \)->with(['template.document', ...])->firstOrFail();
- **Error Handling:** firstOrFail() throws 404 if no matching token found

### No Middleware Interception
- Token-based auth is inline in controller methods
- Each endpoint performs its own SignatureRequest::where('token', \)->firstOrFail() lookup

---

## 7. Current Recipient Identity Shipped to View (B1 Work)

### Location in show() Method
- **File:** app/Http/Controllers/Docuperfect/SigningController.php
- **Line:** 412–443 (view() call)

### B1 Variables Confirmed
- **Line 414:** 'currentRecipient' => \, — Alias for \ in view
- **Line 415:** 'currentRoleIdentity' => \->role_identity, — B1 indexed identity

### View Consumption
- **File:** resources/views/docuperfect/signatures/external/sign.blade.php
- **Status:** Variables passed to view scope but NOT explicitly consumed in markup
- **Purpose:** Available for downstream Alpine components and loop-engine services

---

## 8. Agent / Step 5 View

### File Location
- **File:** resources/views/docuperfect/esign/wizard.blade.php
- **Purpose:** Agent-side eSign workflow (6-step wizard)

### Distinct from Signing View
- **Signing View:** resources/views/docuperfect/signatures/external/sign.blade.php
  - Recipient-facing (token-based, public)
  - Shows document, captures signatures/fields
- **Wizard View:** resources/views/docuperfect/esign/wizard.blade.php
  - Agent-facing (authenticated, internal)
  - 6-step workflow: template → parties → fields → recipients → review → signing

### Confirmation
- The wizard template is DISTINCT from the recipient signing view
- Action: Do NOT render B3 info panel in wizard view (agent doesn't see field scopes at this stage)

---

## Summary of B3 Integration Points

| Component | Location | Current Behavior | B3 Change Required |
|-----------|----------|------------------|--------------------|
| saveFields endpoint | SigningController::saveFields (988) | Validates against party_role only | Check instance-specific editable_by config |
| saveWebFields endpoint | SigningController::saveWebFields (1063) | Validates against static PARTY_MAP | Check instance-specific scope |
| getEditableFields | WebTemplateFieldPartyMap (264) | Returns static field list per party | Merge with instance-specific restrictions |
| Items counter | sign.blade.php (420) | Counts all markers for party | Filter for recipient's scope only |
| Audit log | SignatureAuditLog::create | Records action + party_role | Add field_scope_check or scope_denied action |
| Recipient identity | show() method (414–415) | Passes currentRecipient + currentRoleIdentity | Already available for scope lookups |
| Role display | Template::roleDisplayLabel (299) | Returns "Seller 2" when totalInstances > 1 | Use in UI to show instance-specific scope |

---

**Audit Completed:** 2026-05-26  
**Status:** Ready for B3 implementation planning
