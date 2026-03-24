# CoreX OS — Document Types, Drive & Filing Spec

> Status: DRAFT — Awaiting Johan's approval
> Module: Document Management (cross-cutting: Templates, E-Sign, PDF Splitter, Contacts, Properties)
> Dependencies: Existing PDF Splitter labels table, E-Sign flow, Contact/Property models

---

## 1. Document Types — Single Source of Truth

### Current State
PDF Splitter has a `manage_labels` page with 12 document types stored in a database table. Each has: order, label, slug, active flag.

### Change
Move the management UI from PDF Splitter to **Settings → Document Settings** (new section, below P24 Suburbs button). Same table, same data, same CRUD — just a new home.

PDF Splitter reads from the same table — no duplication. Templates read from it. E-Sign reads from it. One list, used everywhere.

### Database
Existing table stays (confirm table name via investigation). Add if missing:
- `id`, `label`, `slug`, `sort_order`, `is_active`, `created_at`, `updated_at`
- Soft delete (archive, not hard delete — per CoreX rules)

No migration needed if the table already has these columns.

---

## 2. Template Tagging

### How It Works
Every template (web/CDS or PDF) gets a `document_type_id` field — FK to the document types table.

**On CDS Import / Template Setup:**
- Dropdown: "Document Type" — reads from the document types table (same list as PDF Splitter labels)
- Required field — every template must be tagged
- For packs: each template IN the pack has its own tag. The pack is a container; the individual templates are tagged.

**On E-Sign Wizard:**
- Step 1 (Template Selection): the document type badge shows next to each template name
- The tag carries through the entire flow — it's on the template, not entered per-document

### Database Change
```
ALTER TABLE docuperfect_templates ADD COLUMN document_type_id BIGINT UNSIGNED NULL;
ALTER TABLE docuperfect_templates ADD FOREIGN KEY (document_type_id) REFERENCES [document_types_table](id);
```

---

## 3. Post-Signing: Save to Contact & Property Drive

### When
After a document is **finally accepted** (all parties signed, agent gives final approval), the system files it automatically.

### How — Single Documents
1. Document completes → system reads the template's `document_type_id`
2. Finds the linked property (from the e-sign flow step data)
3. Finds the linked contacts (recipients from the signing chain)
4. Creates ONE file record (the signed PDF or merged_html snapshot)
5. Links the file to:
   - The property (via `document_property` pivot)
   - Each contact who was a party (via `document_contact` pivot)
6. Tags the file with the document type

### How — Packs
A pack contains multiple templates (e.g. Mandate + FICA + Mandatory Disclosure). Each template has its own `document_type_id`.

After final acceptance of a pack:
1. System splits the completed pack into individual documents by template
2. Each document gets its own file record with its own document type tag
3. Each is linked to property and contacts independently
4. All stored once on server, linked via pivots

### Database — Document Filing
New table: `filed_documents`
```
id
file_path          -- single storage location on server
file_name          -- display name
document_type_id   -- FK to document types table
source_type        -- 'esign' | 'upload' | 'pdf_splitter'
source_id          -- polymorphic: signature_template.id or upload record id
file_size
mime_type
filed_by           -- user who completed/uploaded
filed_at           -- timestamp
created_at
updated_at
deleted_at         -- soft delete
```

Pivot: `filed_document_contact`
```
filed_document_id
contact_id
```

Pivot: `filed_document_property`
```
filed_document_id
property_id
```

---

## 4. Contact Drive — Grouped by Property

### Display
Contact → Documents tab (Drive):

```
14 Marine Drive, Shelly Beach
├── Mandate              ✓ Signed  24 Mar 2026
├── Mandatory Disclosure ✓ Signed  24 Mar 2026
├── FICA Declaration     ✓ Signed  24 Mar 2026
└── Rates & Taxes        📎 Uploaded  20 Mar 2026

22 Ocean View, Margate
├── Mandate              ✓ Signed  15 Mar 2026
└── IDs / Identity       📎 Uploaded  10 Mar 2026

Not Property-Linked
├── Proof of Residence   📎 Uploaded  5 Mar 2026
└── IDs / Identity       📎 Uploaded  5 Mar 2026
```

### Rules
- Documents grouped by property
- Within each property group: sorted by document type order (from the settings sort order)
- Documents not linked to a property go under "Not Property-Linked" section at the bottom
- Each document shows: document type label, source indicator (✓ Signed / 📎 Uploaded / ✂ Split), date
- Single list — no separate sections for signed vs uploaded. The source indicator tells the user.
- Click to view/download

---

## 5. Property Drive — Grouped by Contact

### Display
Property → Documents tab (Drive):

```
James Van Der Merwe (Seller)
├── Mandate              ✓ Signed  24 Mar 2026
├── Mandatory Disclosure ✓ Signed  24 Mar 2026
├── FICA Declaration     ✓ Signed  24 Mar 2026
└── IDs / Identity       📎 Uploaded  20 Mar 2026

Steve Jobs (Seller)
├── FICA Declaration     ✓ Signed  24 Mar 2026
└── IDs / Identity       📎 Uploaded  20 Mar 2026

Property Documents
├── Rates & Taxes        📎 Uploaded  20 Mar 2026
├── Body Corporate       📎 Uploaded  18 Mar 2026
└── House Rules          📎 Uploaded  18 Mar 2026
```

### Rules
- Contact-specific documents (FICA, IDs, Proof of Residence) grouped under the contact
- Property-specific documents (Rates, Body Corp, House Rules, Condition Report) grouped under "Property Documents"
- Mandate appears under the contact who signed it (or all contacts if all signed)
- Documents linked to property but no specific contact go under "Property Documents"
- Same source indicators, same sort order

### Smart Grouping Logic
The document type determines grouping:
- **Contact-specific types:** FICA, IDs/Identity, Proof of Residence → grouped under contact
- **Property-specific types:** Rates & Taxes, Body Corporate, House Rules, Condition Report → grouped under "Property Documents"
- **Shared types:** Mandate, Disclosure, Listing Form, Offer to Purchase → grouped under the contact who signed, OR under property if not contact-specific

Add a `grouping` field to the document types table:
```
ALTER TABLE [document_types_table] ADD COLUMN grouping ENUM('contact', 'property', 'shared') DEFAULT 'shared';
```

Pre-set:
- contact: FICA, IDs/Identity, Proof of Residence
- property: Rates & Taxes, Body Corporate, House Rules, Condition Report
- shared: Mandate, Disclosure, Listing Form, Offer to Purchase, Other

---

## 6. PDF Splitter Enhancement

### Current Flow
User uploads PDF → splits pages → tags each section with a label → downloads individual files.

### New Flow
User uploads PDF → splits pages → tags each section with a document type → **NEW: picks contact and/or property** → system files each piece under the Drive.

### The Contact/Property Picker
After splitting and tagging, new step:
1. Search property by address, suburb, or ERF (same as e-sign wizard)
2. On property select: show linked contacts
3. OR search contact first → show linked properties
4. User confirms: "File these documents to [Property] and [Contact(s)]"
5. System creates `filed_documents` records with pivots

### PDF Splitter Still Works Standalone
If user doesn't pick a contact/property, documents download as before — no filing. The picker is optional.

---

## 7. Navigation

### Settings
- Settings → Document Settings (new menu item below P24 Suburbs)
- Links to the existing manage labels page, re-routed under settings

### PDF Splitter
- Keeps existing functionality
- "Manage Labels" link in PDF Splitter now redirects to Settings → Document Settings
- New "File to Contact/Property" step added after split

---

## 8. Build Phases

### Phase 1 — Document Types in Settings
- [ ] Move manage labels UI to Settings → Document Settings
- [ ] Add `grouping` column to document types table
- [ ] Update PDF Splitter to read from the same route/controller
- [ ] Add `document_type_id` to templates table
- [ ] Add document type dropdown to template setup / CDS import

### Phase 2 — Auto-Filing After E-Sign
- [ ] Create `filed_documents` table + pivots
- [ ] On final acceptance: create filed_document record
- [ ] Link to property and contacts from the e-sign flow
- [ ] For packs: split into individual documents by template tag

### Phase 3 — Contact Drive
- [ ] New/updated Documents tab on contact show page
- [ ] Group by property
- [ ] Show document type, source indicator, date
- [ ] Click to view/download

### Phase 4 — Property Drive
- [ ] New/updated Documents tab on property show page
- [ ] Group by contact (contact-specific) and "Property Documents" (property-specific)
- [ ] Smart grouping from document type's `grouping` field

### Phase 5 — PDF Splitter Filing
- [ ] Add contact/property picker step to PDF splitter
- [ ] Create filed_document records on split + file
- [ ] Optional — user can skip filing and just download

---

## 9. Excluded from This Spec
- Calendar integration / smart date extraction
- Automatic reminder system from document dates
- OTP-specific handling
- Document version history (future)