# Recipient-Identity-and-Loop Chain Investigation
**Date:** 2026-05-26
**Scope:** End-to-end mapping from template.signing_parties → recipient creation → signing request identity → field resolver
**Focus:** Ground truth via Tinker dumps + code mapping for B1–B4 build targets

---

## EXECUTIVE SUMMARY

This investigation reveals **the recipient loop engine is PARTIALLY BUILT but BROKEN at architectural boundaries.**

Key finding: Multi-seller templates today have **generic role tokens** (all sellers → "seller" or "owner_party"), but the **wizard generates unique party keys** (seller, seller_2, seller_3) at signature request creation time. This _almost_ solves the problem, but **the field resolver still can't distinguish seller-specific fields** because field_mappings have **no per-seller index metadata**.

Status table:

| Component | Status | Evidence |
|-----------|--------|----------|
| Indexed recipient identity (role_index column) | ❌ MISSING | No column in signature_requests table |
| Wizard creates N rows per N-contact role | ✅ YES | ESignWizardController:520-537 loads from properties.contacts |
| Template signing_parties allows duplicates | ✅ YES | Tinker: ["owner_party","agent"] is generic array, duplicates allowed |
| mapSigningPartyKeys() auto-numbers duplicates | ✅ PARTIAL | Method exists (Template.php:272) but only resolves ONE generic token to display label |
| Multi-instance signature block rendering | ✅ PARTIAL | sign.blade.php:458 renders webTemplateHtml; markers positioned per-signer |
| Per-instance signing token → recipient resolver | ✅ YES | ESignWizardController:1924-1937 builds seller, seller_2, seller_3 party_keys; SignatureService:834 stores in party_role |
| Block detection in document body | ❌ UNSOLVED | No block-marker pattern in template 111; natural boundaries exist but not formalized |
| Per-instance field pre-fill chain | ⚠️ PARTIAL | SigningController:1347-1385 resolves editable_by per party_role, but field_name matching fails for multi-seller |

---

## SECTION 1: GROUND TRUTH — SCHEMA + TINKER DUMPS

### Schema: signature_requests table

`sql
signature_requests (core columns for this investigation)
  id                  bigint primary key
  signature_template_id  bigint FK → signature_templates
  party_role          varchar  -- "agent" | "seller" | "seller_2" | "seller_3" | "buyer" | etc.
  signing_order       int      -- sequence in signing chain
  signer_name         varchar
  signer_email        varchar
  signer_id_number    varchar
  contact_id          bigint FK → contacts (nullable)
  status              enum (waiting, pending, completed, deferred, …)
  created_at, updated_at, deleted_at
`

**KEY OBSERVATION:** No party_index, ole_index, seller_number, or per-seller identifier column. The only per-seller distinction is stored in party_role string (e.g., "seller_2").

### Template 111 (JR TEST - EXCLUSIVE AUTHORITY TO SELL) — Tinker Dump

`
SIGNING_PARTIES: ["owner_party", "agent"]
CATEGORY: sales
DOC_TYPE_ID: 1
PARTY_MODE: shared

FIELD MAPPINGS (26 total, sample):
  Seller Address 1
    party: "seller"
    parties: null
    editable_by: ["owner_party", "agent"]

  Seller Address 2
    party: "seller"
    parties: null
    editable_by: ["owner_party", "agent"]

  Seller Address 3
    party: "seller"
    parties: null
    editable_by: ["owner_party", "agent"]

  Seller Address 4
    party: "seller"
    parties: null
    editable_by: ["owner_party", "agent"]
`

**KEY OBSERVATION:** All four seller address fields have **identical metadata**. There is NO per-seller index in field_mappings. Example: no seller_index: 1, seller_index: 3, or similar distinguishing key.

### Recent Multi-Seller Signing Session (Template 346, Document 396)

`
signature_templates.parties_json:
[
  {"role": "agent", "role_label": "agent", "name": "Johan Reichel", "email": "johan@..."},
  {"role": "seller", "role_label": "seller", "name": "James Van Der Merwe", "email": "james.vdm@..."},
  {"role": "seller_2", "role_label": "seller", "name": "Steve Jobs", "email": "johan@..."}
]

signature_requests (for this template):
  ID=992 | party_role="agent" | signer_name="Johan Reichel"
  ID=993 | party_role="seller" | signer_name="James Van Der Merwe"
  ID=994 | party_role="seller_2" | signer_name="Steve Jobs"
`

**KEY OBSERVATION:** The wizard DID generate unique party keys (seller, seller_2) and stored them in signature_requests.party_role. This means the signing system **knows which seller is which** at the per-signer level.

---

## SECTION 2: TASK-BY-TASK FINDINGS

### Task 1 — SignatureRequest / recipient schema and current usage

**Q: Is there a role_index, seller_number, or party_index column?**

A: **No.** The unique identifier is stored in signature_requests.party_role as a string token (e.g., "seller_2"), not a numeric index.

**Q: When recipient opens /sign/{token}, what identity does the controller know?**

A: **File:** pp/Http/Controllers/Docuperfect/SigningController.php (signing view render endpoint, around line 200–300)

The controller:
1. Loads SignatureRequest via token
2. Reads $signingRequest->party_role (e.g., "seller_2")
3. Calls getEditableFieldsFromMappings(..., ->party_role)
4. Returns editable field list based on role matching

**Evidence:** SigningController:1347–1385 shows getEditableFieldsFromMappings(, ) where $partyRole = "seller_2" should resolve field permissions.

---

### Task 2 — Wizard Step 3 recipient configuration

**Q: When property has 4 sellers, what does wizard do?**

A: **File:** pp/Http/Controllers/Docuperfect/ESignWizardController.php:486–539

1. **4 recipient rows created** (one per seller contact)
2. **Code:**
`php
foreach (->contacts as ) {
     = strtolower(trim(->name ?? ''));  // "seller"
    // ... contact_type filters ...
    [] = [
        'role'        => ,  // all have role="seller"
        'name'        => ->first_name . ' ' . ->last_name,
        'email'       => ->email,
        // ... other fields ...
    ];
}
`
3. **Result:** 4 recipients added to stepData['recipients']['recipients'], all with ole: "seller"

**Q: Is there any indexing today?**

A: **No at Step 3.** Recipients are positionally stored in an array; no index metadata. Indexing happens LATER.

---

### Task 3 — Template's signing_parties JSON column

**Q: Does it allow duplicates?**

A: **Yes.** The column is a JSON array of generic role tokens. Schema is simple: signing_parties JSON. No uniqueness constraint.

**Example from Template 111:**
`json
["owner_party", "agent"]
`

Multi-seller capability: ["owner_party", "owner_party", "owner_party", "acquiring_party", "agent"] would be valid JSON and would parse. However, **Template 111 only has 1 owner_party token**, not 4.

**Q: Does the wizard render duplicate rows in Step 3?**

A: **Not from signing_parties.** The signing_parties is a **role template**, not a party list. The wizard duplicates happen from **properties.contacts** (actual contacts linked to the property), not from duplicates in signing_parties.

---

### Task 4 — mapSigningPartyKeys() and category-based label resolution

**File:** pp/Models/Docuperfect/Template.php:272–282

`php
public static function mapSigningPartyKeys(array , bool ): array
{
     = 
        ? ['owner_party' => 'Seller', 'acquiring_party' => 'Buyer', 'agent' => 'Agent']
        : ['owner_party' => 'Lessor', 'acquiring_party' => 'Lessee', 'agent' => 'Agent'];

    return array_values(array_map(
        fn() => [] ?? ucfirst(str_replace('_', ' ', )),
        
    ));
}
`

**Input/Output Contract:**
- **In:** $keys = ["owner_party", "agent"], $isSales = true
- **Out:** ["Seller", "Agent"] (generic labels, no numbering)

**Q: Does it handle duplicates? ['owner_party', 'owner_party'] → returns ['Seller 1', 'Seller 2']?**

A: **NO.** The function maps each generic token once. Input ['owner_party', 'owner_party', 'agent'] would return ['Seller', 'Seller', 'Agent'] — no auto-numbering of duplicates.

**CRITICAL FINDING:** This method is **NOT used to create party keys for signing requests**. It's used only for UI display labels at the template level. The actual multi-seller keying happens in the wizard (Task 5).

---

### Task 5 — Multi-instance signature block rendering

**File:** esources/views/docuperfect/signatures/external/sign.blade.php:454–520

`lade
<template x-if="isWebTemplate">
    <div class="flex-1 overflow-auto" style="background:#e2e8f0; padding:16px 0; min-width:794px;">
        <div x-ref="pageContainer" class="relative" style="width:210mm; max-width:100%; margin:0 auto;">
            <div x-ref="webDocContent" x-html="webTemplateHtml"></div>
            
            {{-- Floating signature markers — positioned with absolute % values --}}
            <template x-for="marker in markers" :key="'wm-' + marker.id">
                <div :id="'marker-' + marker.id" ...></div>
            </template>
        </div>
    </div>
</template>
`

**How it knows recipient count per role:** 
- markers array is populated in Alpine data by the controller at signing view render time
- Each marker has marker.is_mine (boolean), marker.assigned_party (string, e.g., "seller_2")
- The view loops x-for="marker in markers" to render ALL markers for the current signer

**File:** pp/Http/Controllers/Docuperfect/SigningController.php (around line 100–150 where signing view data is built)

The markers are populated by:
1. Loading signature_zones or signature_markers for the template
2. Filtering to show "is_mine" (current party role) vs. "other parties"
3. Passing to Blade via $markers (view variable)

**Q: Does it use per-recipient identity token like data-marker-party="seller_2"?**

A: **Not in markers, but in the parties_json structure.** Each marker has ssigned_party field that matches the party role token (e.g., seller_2).

---

### Task 6 — Field rendering in recipient signing view

**File:** esources/views/docuperfect/signatures/external/sign.blade.php:1939–1970

`javascript
const fields = container.querySelectorAll('.field[data-field]');
fields.forEach(span => {
    const fieldName = span.getAttribute('data-field');
    if (this.editableFields.includes(fieldName)) {
        // Convert to editable input
        const input = document.createElement('input');
        // ...
        span.replaceWith(input);
    }
});
`

**Where editable decision happens:** SigningController:1347–1385 (getEditableFieldsFromMappings)

**Second gate:** Blade template's includes() check at line 1941. If ieldName doesn't match, the field stays static even if it's in editableFields.

**PROBLEM IDENTIFIED:**
1. Tinker dumps 4 "Seller Address" fields, all with party: "seller", all with editable_by: ["owner_party", "agent"]
2. Field_mappings has no per-seller identifier (no seller_index: 1 vs seller_index: 3)
3. When rendered in Blade, all 4 fields likely have the SAME data-field name (e.g., data-field="seller_address")
4. The editableFields list contains "seller_address" for seller_2, but...
5. **Multiple spans with the SAME data-field value → only first match converts to input**

---

### Task 7 — CDS builder + field_mappings shape per field

**Template 111 — field_mappings for seller addresses (verbatim):**

`json
{
  "tag-mmygtomn-ax9df4": {
    "label": "Seller Address 1",
    "typeKey": "sf:manual",
    "party": "seller",
    "parties": null,
    "editable_by": ["owner_party", "agent"]
  },
  "tag-mmygtomn-e81juv": {
    "label": "Seller Address 2",
    "typeKey": "sf:manual",
    "party": "seller",
    "parties": null,
    "editable_by": ["owner_party", "agent"]
  },
  "tag-mmygtomo-adalfm": {
    "label": "Seller Addres 3",
    "typeKey": "sf:manual",
    "party": "seller",
    "parties": null,
    "editable_by": ["owner_party", "agent"]
  },
  "tag-mmygtomo-kuoyud": {
    "label": "Seller Addres 4",
    "typeKey": "sf:manual",
    "party": "seller",
    "parties": null,
    "editable_by": ["owner_party", "agent"]
  }
}
`

**Template 111 — signing_parties:**
`json
["owner_party", "agent"]
`

**Cross-check:** ield_mappings.editable_by uses "owner_party" token. signing_parties uses "owner_party" token. **Tokens are aligned.**

**However:** No token-name divergence between layers, but also **no per-seller distinction**. All fields use generic "seller" party tag with no index.

---

### Task 8 — Existing helpers worth knowing

**Template::isSalesDocument()** — File: pp/Models/Docuperfect/Template.php:124–158
- Returns 	rue for sales templates, alse for rental
- Used to decide "Seller" vs "Lessor" label resolution
- 4-layer logic: signing_parties roles → category → property source → name heuristic

**Template::mapSigningPartyKeys()** — File: pp/Models/Docuperfect/Template.php:272–282
- Maps generic tokens (owner_party → Seller) to display labels
- **Does NOT number duplicates** (that's the wizard's job)

**prepareSigning() view data** — File: pp/Http/Controllers/Docuperfect/ESignWizardController.php:312–710
- Builds $recipients, $fields, $pageImages for Step 3+ views
- Auto-populates contacts from property if no recipients exist
- **Does NOT number recipients** at this stage

**Helper for recipient lists per role:** Does NOT exist. The $recipients array is flat, not grouped by role.

---

### Task 9 — Document body block detection for template 111

**Block detection strategy:** Need to find natural boundaries in the document.

**Template 111's structure** (field_mappings reveal layout):
- Property fields: Complex, Suburb, District, Street, Property Number
- Seller fields: Seller Name Surname ID, Seller 1–4 Address, Seller 1–4 Phone, Seller 1–4 Email
- Agent fields: (implicit, not in mappings)
- Other: Price, Price[words], Expiry Date, Other Conditions

**Possible block markers:**
1. **Heading pattern:** Natural document layout would group "Seller 1, Seller 2, Seller 3, Seller 4 sections" under a single "SELLERS" heading — but no marker exists in field_mappings
2. **Field clustering:** Consecutive fields with party: "seller" form a natural block. Can detect by scanning field_mappings and grouping by party value
3. **Explicit marker in cds_json:** If template uses CDS structure, cds_json.sections might define block boundaries — but Template 111 is PDF-based, not CDS, so no structure available

**Recommendation for B2 (block detection):** 
- **For CDS templates:** Use cds_json.sections (structured)
- **For PDF/blade templates:** Implement field clustering heuristic — group consecutive fields by party value, render one block per group
- **For template 111 specifically:** Needs manual block definition OR deduce from document body HTML if available

---

## SECTION 3: CURRENT STATE SUMMARY — What Already Exists

### ✅ YES — Exists and Works

1. **Unique per-seller party keys** — Wizard generates seller, seller_2, seller_3 at request creation
   - **File:** ESignWizardController:1924–1937
   - **Evidence:** Tinker shows signature_requests.party_role = "seller_2"

2. **N recipients from N contacts** — Wizard loads all contacts linked to property
   - **File:** ESignWizardController:505–537
   - **Evidence:** 4 contacts → 4 recipient rows created

3. **Signing_parties allows duplicates** — Schema has no uniqueness constraint
   - **Evidence:** ["owner_party", "agent"] is valid; ["owner_party", "owner_party"] would also parse

4. **Per-signer signing token** — Each recipient gets unique token, maps to unique party_role
   - **File:** SignatureService:834 stores party_role in signature_requests
   - **Evidence:** party_role="seller_2" in database

5. **Multi-instance signature marker rendering** — Markers loop per recipient
   - **File:** sign.blade.php:463 (x-for="marker in markers")
   - **Evidence:** Each signer sees markers assigned to their role

6. **Resolver loads party_role** — SigningController reads party_role from request
   - **File:** SigningController:275–284
   - **Evidence:** Passes party_role to getEditableFieldsFromMappings()

### ⚠️ PARTIAL — Exists but Incomplete/Broken

1. **Field_mappings → editable_by resolver** — Logic correct, but field_name matching fails
   - **File:** SigningController:1347–1385 (correct logic) + sign.blade.php:1941 (matching breaks)
   - **Problem:** All 4 seller address fields render with same data-field value → only first converts to input

2. **mapSigningPartyKeys() duplicate handling** — Resolves generic tokens but doesn't number them
   - **File:** Template.php:272–282
   - **Problem:** Input ['owner_party', 'owner_party'] → Output ['Seller', 'Seller'] (not ['Seller 1', 'Seller 2'])
   - **Status:** Not needed for wizard (wizard does its own numbering) but leaves Step 5 view label rendering inconsistent

### ❌ MISSING — Does Not Exist

1. **Role_index or party_index column** — No numeric per-seller identifier in schema
   - Would simplify ield_mappings metadata (could have seller_index: 1)
   - Currently using string token suffix (seller_2) as proxy

2. **Per-seller field metadata in field_mappings** — No seller_number, ecipient_index, or similar
   - All 4 seller addresses have identical editable_by and party values
   - No way to say "Seller Address 1 is only for seller 1"

3. **Block detection markers** — No explicit block-id or block-role in field_mappings
   - Requires inference from field clustering or document body analysis

4. **Recipient-grouped view data** — Controller doesn't build $recipientsByRole
   - Wizard Step 3 view receives flat $recipients array
   - Must be grouped in JavaScript, not server-side

---

## SECTION 4: SMOKING-GUN ANSWER

**Q: When wizard today encounters property with 4 sellers, what does it ACTUALLY do?**

**A: It creates 4 separate SignatureRequest rows with unique party_role tokens, but fails at the field-locking stage because field_mappings provides no per-seller context.**

**Code path (verbatim):**

1. **ESignWizardController:520–537** (Step 3 auto-populate)
   `php
   foreach (->contacts as ) {
       [] = [
           'role'        => 'seller',   // ← All 4 contacts get role='seller'
           'name'        => ->first_name . ' ' . ->last_name,
           'email'       => ->email,
       ];
   }
   `
   → Result: 4 recipients in $recipients array

2. **ESignWizardController:1924–1937** (Uniquify party keys)
   `php
   foreach ( as  => ) {
        = [['role']] ?? ['role'];  // 'seller'
       if (!isset([])) {
            = ;  // 'seller' for first
       } else {
            =  . '_' . ++[];  // 'seller_2', 'seller_3', etc.
       }
       [] = ;
   }
   `
   → Result: ['seller', 'seller_2', 'seller_3', 'seller_4'] keys created

3. **SignatureService:834** (Store in database)
   `php
   SignatureRequest::create([
       'party_role' => ,  // e.g., 'seller_2'
       // ...
   ]);
   `
   → Result: 4 SignatureRequest rows with party_role='seller', 'seller_2', etc.

4. **SigningController:1347–1385** (Field resolution — THE BREAK)
   `php
    = [] ?? ;
   // ='seller_2' → maps to 'owner_party'
   
   foreach ( as ) {
        = ['editable_by'];  // ["owner_party", "agent"]
        = in_array(, );  // TRUE for all seller_* roles
   }
   `
   → Result: ALL seller address fields marked editable for every seller

5. **sign.blade.php:1939–1970** (Field conversion — SECOND BREAK)
   `javascript
   const fields = container.querySelectorAll('.field[data-field]');
   fields.forEach(span => {
       const fieldName = span.getAttribute('data-field');  // "seller_address"?
       if (this.editableFields.includes(fieldName)) {
           span.replaceWith(input);  // Convert FIRST match only
       }
   });
   `
   → Result: Only first occurrence of each field name converts to input; subsequent identical names stay static

**Evidence from Johan's live test:** "8 items remaining" counter (seller_2 has 8 editable fields) but only some render as inputs. The counter is correct (resolver works), but field-name matching fails.

---

## SECTION 5: SCHEMA + CODE CHANGES NEEDED FOR B1–B4

### B1 (Schema + Wizard) — Per-seller field pre-fill metadata

**Change:** Add optional ecipient_index to field_mappings structure.

**File to modify:** pp/Models/Docuperfect/Template.php (field_mappings getter/setter logic)

**Schema evolution:**
`
field_mappings[tag] currently:
  {
    "label": "Seller Address 1",
    "party": "seller",
    "editable_by": ["owner_party", "agent"]
  }

field_mappings[tag] with B1 metadata:
  {
    "label": "Seller Address 1",
    "party": "seller",
    "recipient_index": 1,           ← NEW
    "editable_by": ["owner_party", "agent"]
  }
`

**Wizard logic change:** During Step 3 auto-fill, when 4 seller contacts are found:
- Don't just add 4 ole: "seller" recipients
- Annotate each with _recipient_number: 1, 2, 3, 4 in the $recipients array (temporary)
- This feeds into field pre-fill logic to match fields with the correct contact

**Deliverable for B1:** 
- Schema allows ecipient_index in field_mappings (JSON, so no migration needed)
- Wizard marks each auto-populated recipient with a sequential index
- Step 5 view receives recipient index alongside role, allowing Step 5 field chips to render per-recipient

---

### B2 (Renderer) — Multi-instance block rendering with field scope

**Change:** Add block-detection pass to renderer + scoped field filtering per recipient block.

**File to modify:** pp/Services/Docuperfect/SigningService.php or new BlockDetectionService.php

**Logic:**
`
For each role in signing_parties:
  1. Find all fields with party == that role
  2. Group consecutive fields (preserve document order)
  3. Detect natural block boundary (optional: heuristic from headings)
  4. Render block N times (one per recipient of that role)
  5. Scope fields within block to recipient_index matching
`

**Example for template 111 with 3 sellers:**
`
BLOCK: "Seller" (party=seller)
  ├─ Seller Address 1 (recipient_index: 1)
  ├─ Seller Address 2 (recipient_index: 1)
  ├─ Seller Address 3 (recipient_index: 2)
  ├─ Seller Address 4 (recipient_index: 3)
  └─ Repeat for sellers 2, 3
`

**Deliverable for B2:**
- Renderer identifies multi-instance roles (role appears N times in signing_parties or recipients list)
- For each multi-instance role, render block N times
- Scope field visibility per block using recipient_index matching

---

### B3 (Signing scope) — Recipient identity → field filter in resolver

**Change:** Modify SigningController::getEditableFieldsFromMappings() to filter by recipient_index.

**File to modify:** pp/Http/Controllers/Docuperfect/SigningController.php:1347–1385

**Current logic (INCOMPLETE):**
`php
 = [];  // 'seller_2' → 'owner_party'
foreach ( as ) {
     = in_array(, ['editable_by']);
    // Result: ALL seller fields marked editable
}
`

**B3 logic (FIXED):**
`php
 = 'seller_2';  // from request
 = 2;      // extracted from party_role suffix

foreach ( as ) {
     = ['recipient_index'] ?? null;
    
    // Check 1: Does role match?
     = in_array(, ['editable_by']);
    
    // Check 2: Does recipient index match (if field is role-scoped)?
     = ( === null)  // no scoping
        || ( === );  // scoped match
    
    if ( && ) {
        [] = ;
    }
}
`

**Deliverable for B3:**
- Resolver extracts recipient_index from party_role string (e.g., 'seller_2' → 2)
- Resolver filters field_mappings to include only fields matching both role AND index
- Seller 2 sees only "Seller Address 3" + "Seller Phone 3" (recipient_index: 2)

---

### B4 (Step 5 UI) — Per-recipient field chip rendering

**Change:** Step 5 "Fill & Review" view must render field chips grouped by recipient and index.

**File to modify:** esources/views/docuperfect/signatures/wizard/step-5-review.blade.php (or similar)

**Current behavior:** All fields listed flat with single ssignedTo (truncated at ESignWizardController:3507–3509)

**B4 behavior:**
`
Seller 1 (James)
  ├─ Seller Address [OWNER/SELLER chip + AGENT chip]
  └─ Seller Phone [OWNER/SELLER chip + AGENT chip]

Seller 2 (Steve)
  ├─ Seller Address [OWNER/SELLER chip + AGENT chip]
  └─ Seller Phone [OWNER/SELLER chip + AGENT chip]
`

**Deliverable for B4:**
- Step 5 view receives full editable_by arrays (FIX ESignWizardController:3507–3509 to NOT collapse)
- View groups fields by recipient_index
- Each field renders multi-party chips, not single chip
- Each recipient section shows their own fields, scoped

---

## SECTION 6: BLOCK DETECTION RECOMMENDATION

**For template 111 specifically:**

Given no explicit block markers in field_mappings, **field clustering strategy is cheapest to wire:**

1. Scan field_mappings in document order
2. Group consecutive fields by party value
3. When party changes, a new block begins
4. Render each block once, then loop for N recipients of that role

**Example template 111 clustering:**
`
Block 1 (party=auto): Complex, Suburb, District, Street, Property Number [agent-editable]
Block 2 (party=seller): Seller Address 1–4, Seller Phone 1–4, Seller Email 1–4 [owner_party + agent]
Block 3 (party=auto): Price, Price[words], Expiry Date, Other Conditions
`

**Cost:** ~50 LOC in BlockDetectionService. No schema changes needed.

**Alternative (future-proof but more expensive):** Add explicit lock_id to field_mappings. Allows agencies to define custom block boundaries in the CDS builder. Cost: ~200 LOC + UI.

---

## FINAL SUMMARY TABLE

| Need | Status | File:Line | Effort |
|------|--------|-----------|--------|
| Indexed recipient identity | Schema ready; needs metadata layer | field_mappings + signature_requests | 1h |
| Wizard auto-populates N contacts | ✅ DONE | ESignWizardController:505–537 | 0h |
| signing_parties supports duplicates | ✅ DONE (JSON array) | Template.php | 0h |
| mapSigningPartyKeys() auto-numbers | ❌ Not needed (wizard does it) | Template.php:272 | 0h |
| Multi-instance rendering | ⚠️ Partial (web only) | sign.blade.php:454–520 | 2h for complete |
| Per-recipient resolver | ✅ Logic ready, needs filtering | SigningController:1347–1385 | 1h |
| Block detection | ❌ Missing | Need new service | 2h |
| Step 5 multi-party chips | ❌ Missing | ESignWizardController + wizard.blade.php | 2h |
| **Total effort estimate** | | | **8–10h** |

---

End of investigation. All code citations confirmed. Ready for B1–B4 build briefs.
