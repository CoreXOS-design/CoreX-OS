# E-Sign Wizard Audit Report
**Date:** 2026-03-31
**Auditor:** Claude Code
**Status:** INVESTIGATION ONLY — no code changes made

---

## Property Data Issues

### 1. [CRITICAL] Lessor Query Returns Wrong Contact — `orWherePivot` Breaks Property Scope

**File:** `app/Http/Controllers/Docuperfect/ESignWizardController.php`, line ~921
**Code:**
```php
$lessor = $p->contacts()->wherePivot('role', 'lessor')->orWherePivot('role', 'landlord')->first();
```
**Generated SQL:**
```sql
SELECT * FROM contacts
INNER JOIN contact_property ON contacts.id = contact_property.contact_id
WHERE (contact_property.property_id = 21 AND contact_property.role = 'lessor'
       OR contact_property.role = 'landlord')
AND contacts.deleted_at IS NULL
```
**What SHOULD happen:** Return the lessor/landlord contact linked to property 21 (Johannes Kerkorrel, id=11).
**What ACTUALLY happens:** Returns James Van Der Merwe (id=5) who is linked to property 17 as 'landlord' — NOT property 21. The `OR` breaks out of the property scope.
**Root cause:** `orWherePivot()` adds an OR at the top level of the WHERE clause, not nested within the property_id constraint. The correct approach is:
```php
$p->contacts()->where(function($q) {
    $q->wherePivot('role', 'lessor')->orWherePivot('role', 'landlord');
})->first();
```
**However:** Laravel's `orWherePivot` inside a closure also fails with `BadMethodCallException`. The fix must use raw where on the pivot:
```php
$p->contacts()->where(function($q) {
    $q->where('contact_property.role', 'lessor')
      ->orWhere('contact_property.role', 'landlord');
})->first();
```
**Impact:** Every property in the e-sign wizard may show the wrong landlord. The auto-populated lessor name in the search results is unreliable.

**Proof:**
- Property 21 has 1 linked contact: id=11 (Johannes Kerkorrel, type=Lessor, pivot role=NULL)
- Contact 5 (James Van Der Merwe) is linked to property 17 as 'landlord' and property 19 as 'seller'
- The wrong query returns contact 5 instead of contact 11

### 2. [MEDIUM] Property 21 Pivot Role is NULL

**Table:** `contact_property`
**Data:** `property_id=21, contact_id=11, role=NULL`
**What SHOULD happen:** The role should be 'lessor' or 'landlord' to match the lessor query.
**What ACTUALLY happens:** Role is NULL, so even the corrected lessor query would NOT find this contact.
**Root cause:** When the contact was linked to the property, no role was specified.
**Impact:** The lessor query will return NULL for property 21 even after fixing issue #1, because the pivot role is NULL. The system relies on pivot role but many records don't have it set.
**Workaround needed:** Should also match by contact_type (Lessor = type_id 2) when pivot role is NULL.

### 3. [LOW] `resolvePropertyValue` Still Falls Back to Title

**File:** `app/Http/Controllers/Docuperfect/ESignWizardController.php`, line 2261
**Code:** `'address' => $property['address'] ?? $property['title'] ?? ''`
**What SHOULD happen:** Use `buildDisplayAddress()` output.
**What ACTUALLY happens:** If `$stepData['property']['address']` is already set correctly from `searchProperties()` (which now uses `buildDisplayAddress()`), this works. But the fallback to `$property['title']` is still present if the stored address is empty.
**Impact:** Low — since `searchProperties()` now returns `buildDisplayAddress()` which is stored as the address. But documents created before the fix may have title as address.

---

## Contact Data Issues

### 4. [MEDIUM] Contact Role Assignment Uses contact_type Name, Not esign_role

**File:** `resources/views/docuperfect/esign/wizard.blade.php`, line 2750
**Code:** `r.role = contact.contact_type.toLowerCase();`
**What SHOULD happen:** Role should be set from `contact.esign_role` (e.g. 'lessee') which maps to the signing parties system.
**What ACTUALLY happens:** Role is set from the contact_type NAME (e.g. 'lessee', 'lessor', 'prospect', 'witness'). For most types this works because the name IS the role. But for:
- Contact type "Prospect" (id=6, esign_role=NULL) → role becomes `prospect` → doesn't match any signing party
- Contact type "Tenant" (id=7, esign_role=lessee) → role becomes `tenant` → matches via alias map
**Impact:** Contacts with type "Prospect" won't match any signing role even though they may be intended as a tenant/buyer. The `esign_role` field exists specifically to handle this mapping but is not used.

### 5. [INFO] Contact Search Doesn't Filter by esign_role

**File:** `app/Http/Controllers/Docuperfect/ESignWizardController.php`, `searchContacts()` method
**Observation:** The contact search returns ALL contacts matching the name/email query. It does NOT filter by `esign_role` based on the template's `signing_parties`. The filtering happens client-side via `buildAllowedEsignRoles()` → which is used to filter property-linked contacts, but manual search results are unfiltered.
**Impact:** Low — the validation catches mismatched roles before proceeding. But it means users see contacts they can't use.

---

## Field Group / Mapping Issues

### 6. [INFO] Field Group Resolution Appears Correct

**Lessee — Name + ID group (id=4)** contains:
- Named field 4: "Lessee Name" → `source_type=contact, source_column=first_name+last_name, source_contact_type=Lessee`
- Named field 140: "Lessee Last Name" → `source_type=contact, source_column=last_name, source_contact_type=Lessee`
- Named field 6: "Lessee ID" → `source_type=contact, source_column=id_number, source_contact_type=Lessee`

**Resolution chain:**
1. `source_contact_type = 'Lessee'` → looks up `$contactsByRole['Lessee']`
2. `$contactsByRole` is built from recipients with `ucfirst($r['role'])` (line 2046)
3. If recipient role is `lessee` (from contact_type.toLowerCase()), ucfirst gives `Lessee` → matches
4. Role aliases at line 2071 map `Lessee → Lessee` (identity) and `Tenant → Lessee`

**Conclusion:** Field groups should resolve correctly IF the recipient's role is `lessee` or `tenant`. The chain works.

---

## Signing Order / Role Validation Issues

### 7. [FIXED] Template 121 Now Has All Required Parties

**Template 121:** signing_parties = `["owner_party", "acquiring_party", "agent"]` (type: array)
**Status:** Previously had only `["owner_party", "agent"]` — this was fixed (hot-fix in Tinker + the `acquiring_party` was added).
**Current state:** Correct. Both owner and acquiring parties are defined.

### 8. [FIXED] requiredSigningRoles Now Syncs with resolvedPartyRoles

After the recent fix, `requiredSigningRoles` includes both owner and acquiring party roles when the template has owner_party, matching `resolvedPartyRoles`. The alias map handles lessee↔tenant and lessor↔landlord bidirectionally.

### 9. [INFO] Signing Order Sort Applied Correctly

`sortRecipientsBySigningOrder()` is applied at 3 points:
1. `saveStep()` for step 3 (recipients) — line ~700
2. `prepareSigning()` first instance — line ~1287
3. `prepareSigning()` second instance — line ~3678

Priority: agent(1) → tenant/lessee/buyer(10) → landlord/lessor/seller/owner(20) → witness(90)

---

## Type Safety Issues

### 10. [FIXED] buildAllowedEsignRoles Accepts string|null

Method signature is now `array|string|null $signingParties` with JSON decode fallback.
Template model casts `signing_parties` to array. The method handles all input types.

---

## Data Integrity Issues (DB)

### 11. [MEDIUM] Property 21 Contact Has NULL Pivot Role

```sql
SELECT * FROM contact_property WHERE property_id = 21;
-- contact_id=11, role=NULL
```
**Expected:** role = 'lessor' (since contact 11 has contact_type_id=2 which is "Lessor")
**Impact:** Lessor auto-population fails for this property even after fixing the query (issue #1). Many properties may have NULL roles in the pivot.
**Fix needed:** Either:
  a. Backfill pivot roles from contact_type where NULL, OR
  b. Modify the lessor query to also check contact_type when pivot role is NULL

### 12. [LOW] contact_type "Prospect" Has No esign_role

```
id=6, name=Prospect, esign_role=NULL
```
Prospects who need to sign documents can't be automatically mapped to a signing role. They must have their role manually set by the agent.

---

## Summary of Issues by Severity

| # | Severity | Area | Description | Status |
|---|----------|------|-------------|--------|
| 1 | CRITICAL | Property | `orWherePivot` returns wrong lessor — cross-property contamination | UNFIXED |
| 2 | MEDIUM | Property | Property 21 pivot role is NULL — lessor not found | UNFIXED |
| 3 | LOW | Property | `resolvePropertyValue` still has title fallback | Acceptable |
| 4 | MEDIUM | Contact | Role set from contact_type name, not esign_role | UNFIXED |
| 5 | INFO | Contact | Manual contact search not filtered by signing role | Acceptable |
| 6 | INFO | Fields | Field group resolution chain is correct | OK |
| 7 | FIXED | Template | Template 121 now has acquiring_party | Fixed |
| 8 | FIXED | Validation | requiredSigningRoles synced with resolvedPartyRoles | Fixed |
| 9 | INFO | Order | Signing order sort applied at all 3 points | OK |
| 10 | FIXED | Types | buildAllowedEsignRoles handles string/null | Fixed |
| 11 | MEDIUM | DB | NULL pivot roles prevent lessor auto-population | UNFIXED |
| 12 | LOW | DB | Prospect type has no esign_role | Acceptable |

---

## Recommended Fix Priority

1. **Issue #1 (CRITICAL):** Fix the lessor query to properly scope within the property's contacts
2. **Issue #2 + #11 (MEDIUM):** Enhance lessor query to also match by contact_type when pivot role is NULL
3. **Issue #4 (MEDIUM):** Consider using `esign_role` from contact_types instead of/alongside the type name for role assignment
