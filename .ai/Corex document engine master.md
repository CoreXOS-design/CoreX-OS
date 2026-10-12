# CoreX Document Engine — Master Specification

> Status: APPROVED by Johan
> Module: DocuPerfect (complete redesign)
> Multi-agency: Designed from day one for any SA real estate agency
> Legal references: ECTA s13, POPIA, FICA (FIC Act 38/2001),
>   Alienation of Land Act 68/1981, PPRA Code of Conduct,
>   Property Practitioners Act 22/2019

---

## 1. Vision

The CoreX Document Engine is a professional legal document
platform purpose-built for South African real estate. Any
agency can upload their existing documents, and the engine
extracts the legal content, renders it through a professional
CoreX template, and delivers it ready for e-sign or PDF
download.

The output looks better than the Word original. Every
document that comes out of CoreX is instantly recognisable
as a CoreX document — professional, consistent, legally
precise.

### Design Principles

1. **Content is sacred.** Every clause, every heading, every
   numbered paragraph imports exactly as written. Not one
   word changes. Not one clause number shifts.

2. **Layout is ours.** CoreX controls typography, spacing,
   page structure, and styling. The document looks like
   CoreX, not like Word.

3. **Once-off setup.** An agency uploads a document, reviews
   the import, places interactive fields, and saves. That
   template is used for years. The setup must be so clean
   that it never needs re-doing.

4. **Multi-agency from day one.** Every agency gets their
   own branding — logo, trading name, contact details,
   colour accent. Documents carry the agency's identity
   while maintaining the CoreX professional standard.

5. **Two delivery modes, one system.** E-sign documents go
   through the signing pipeline. Wet ink documents render
   to PDF for print. Same template, same data, same look.

6. **We do complicated so the user can do simplicity.**
   Every screen must pass the test: would the least
   technical person in the agency understand it without
   being told?

---

## 2. Legal Boundaries

### What CAN be e-signed
- FICA declarations and consent forms
- Mandates (letting, sole, marketing)
- Mandatory disclosure forms
- POPIA consent forms
- Lease agreements (under 10 years)
- Rental applications
- Inspection reports
- Any internal agency document

### What CANNOT be e-signed (ECTA s4(3))
- Agreement for alienation of immovable property (OTP/sale)
- Lease agreements over 10 years
- Wills and codicils
- Bills of exchange

E-sign eligibility is the template's own setting (ESIGN-CANON.md §7, amended 2026-10-08). The only extra step today: templates
flagged as sale agreements / OTPs / deeds default to wet ink. There is no hard block: an admin may switch e-signing on in template setup
after acknowledging a legal warning (shown to the admin there, once, and recorded; never shown to an agent). Which types are flagged is
data (`document_types.esign_warning_required`). See ESIGN-CANON.md §7.

---

## 3. Agency Branding

Each agency has a branding profile:

| Field | Example |
|-------|---------|
| agency_name | Home Finders Coastal |
| trading_name | HFC Coastal |
| logo | Uploaded image (SVG/PNG, min 300px wide) |
| primary_colour | #00d4aa (teal) |
| accent_colour | #0f172a (dark navy) |
| address | 123 Marine Drive, Margate |
| phone | 039 312 1234 |
| email | info@hfcoastal.co.za |
| ppra_number | F12345 |
| fic_number | FIC-67890 |
| vat_number | 4567890123 |

Document headers and footers pull from this automatically.

---

## 4. Document Renderer — The CoreX Look

### Typography

| Element | Font | Size | Weight |
|---------|------|------|--------|
| Document title | Plus Jakarta Sans | 18pt | 700 |
| Section heading | Plus Jakarta Sans | 13pt | 600 |
| Clause number | Plus Jakarta Sans | 10.5pt | 600 |
| Body text | Plus Jakarta Sans | 10.5pt | 400 |
| Small print | Plus Jakarta Sans | 8.5pt | 400 |
| Field labels | Plus Jakarta Sans | 9pt | 500 |
| Table headers | Plus Jakarta Sans | 10pt | 600 |

### Page Structure

```
┌─────────────────────────────────┐
│ HEADER                          │
│ [Logo]  [Document Title]  [Ref] │
│ ─────────────────────────────── │
│                                 │
│  BODY CONTENT                   │
│  Clauses, tables, fields        │
│                                 │
│ ─────────────────────────────── │
│ FOOTER                          │
│ [Agency] [Page X of Y] [Initials]│
└─────────────────────────────────┘
```

### CSS Foundation

```css
@page {
    size: A4 portrait;
    margin: 20mm 18mm 25mm 18mm;
}

.corex-document {
    width: 210mm;
    min-height: 297mm;
    padding: 20mm 18mm 25mm 18mm;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 10.5pt;
    line-height: 1.55;
    color: #1e293b;
    background: white;
}
```

### Colour Usage (functional only)

| Element | Colour |
|---------|--------|
| Body text | #1e293b (slate-800) |
| Headings | #0f172a (slate-900) |
| Agency accent | Per branding profile |
| Interactive fields | #f0fdfa bg, #0d9488 border |
| Signature zones | #eff6ff bg, #3b82f6 border |
| Clause numbers | #64748b (slate-500) |
| Table borders | #e2e8f0 (slate-200) |

### Clause Styling

```
LEVEL 1:  1. DEFINITIONS
          Full caps, 13pt, bold, border-bottom

LEVEL 2:  1.1 "The Seller"
          Normal case, 10.5pt, semibold

LEVEL 3:  1.1.1 In the event that...
          Normal case, 10.5pt, normal, 3mm indent
```

### Interactive Fields

```css
.corex-field {
    display: inline-flex;
    padding: 2px 10px;
    background: #f0fdfa;
    border: 1px solid #0d9488;
    border-radius: 3px;
    font-size: 10pt;
}

.corex-field[data-filled] {
    background: white;
    border-color: #cbd5e1;
}
```

### Conditional Sections

Sections controlled by agent selections at creation time.
Hidden sections are `display: none` — not crossed out,
not initialled. The document simply doesn't contain them.

---

## 5. Import Pipeline — Content Extraction

### Replace Mammoth with Direct .docx XML Parsing

Mammoth strips formatting to produce "clean" HTML. This is
the wrong tool. Direct XML parsing extracts STRUCTURE:

```php
$zip = new ZipArchive();
$zip->open($path);
$xml = $zip->getFromName('word/document.xml');
$styles = $zip->getFromName('word/styles.xml');
$numbering = $zip->getFromName('word/numbering.xml');
```

Extract:
- `<w:p>` → paragraphs
- `<w:pStyle>` → heading levels
- `<w:numPr>` → clause numbering
- `<w:tbl>` → tables
- `<w:r><w:t>` → text runs
- `<w:b/>`, `<w:i/>`, `<w:u/>` → semantic formatting

### CoreX Document Structure (CDS) — JSON

Documents stored as structured JSON, rendered through CSS:

```json
{
    "title": "Letting Mandate",
    "sections": [
        {
            "type": "heading",
            "level": 1,
            "number": "1",
            "text": "DEFINITIONS"
        },
        {
            "type": "clause",
            "number": "1.1",
            "negotiable": false,
            "content": [
                { "type": "text", "value": "\"The Landlord\" means " },
                { "type": "field", "name": "landlord_name",
                  "label": "Landlord Full Name" }
            ]
        },
        {
            "type": "conditional",
            "condition": "is_vat_registered",
            "true_content": [...],
            "false_content": [...]
        },
        {
            "type": "signature_section",
            "party_role": "seller",
            "marker_type": "signature"
        }
    ]
}
```

### Why CDS?

1. **Predictable** — same JSON always produces same output
2. **Version-proof** — update CSS, all documents improve
3. **Searchable** — clauses indexed and searchable
4. **Portable** — renders to HTML (e-sign), PDF (wet ink)
5. **AI-friendly** — structured data for field detection
6. **Multi-agency** — same CDS, different branding

### AI-Assisted Field Detection

After extraction, AI identifies fill-in fields, conditional
sections, and signature locations. Agent reviews suggestions,
accepts/rejects/modifies. High-confidence auto-accepted.

---

## 6. Template Builder UI

### Import Review Screen (split-panel)

```
┌──────────────────┬──────────────────┐
│  ORIGINAL DOC    │  COREX RENDER    │
│  (page images)   │  (live preview)  │
└──────────────────┴──────────────────┘
         ┌──────────────────┐
         │  FIELD PANEL     │
         │  AI suggestions  │
         │  Manual placement│
         └──────────────────┘
```

### Field Types

| Type | Renders As |
|------|-----------|
| text | Inline field |
| textarea | Multi-line block |
| number | Number input |
| date | Date picker |
| currency | R-prefixed input |
| select | Dropdown |
| checkbox | Tick box |
| radio_group | Radio buttons |
| signature | Signing block |
| initial | Initial block |
| condition_toggle | Controls section visibility |

### Conditional Section Builder

Agent selects clauses, marks as conditional, names the
condition ("is_vat_registered"), sets the alternatives.
At creation time, agent toggles. Document renders only
applicable clauses. No crossing out, no initials needed.

### Lockable vs Negotiable Clauses

Every clause has a negotiability setting:

| Setting | Meaning |
|---------|---------|
| locked | Cannot be modified by any recipient |
| amendable | Recipient can strikeout, amend, or add below |
| fill_in | Recipient completes a field |

Locked by default. Agent explicitly unlocks negotiable
clauses. PPRA-required clauses (voetstoots, agency
warranty, jurisdiction) stay locked always.

### Signature Zone Placement

Zones reference document sections, not absolute coordinates:

```json
{
    "type": "signature_section",
    "after_section": "12",
    "party_role": "seller",
    "marker_type": "signature"
}
```

Zones move with content if pages reflow. Dynamic rendering
creates blocks per party count — one seller gets one block,
four sellers get four blocks. No manual duplication needed.

---

## 7. Three Delivery Modes

| Mode | When | Output |
|------|------|--------|
| E-Sign | FICA, mandates, leases, disclosures | Web signing pipeline |
| Wet Ink | OTP, sale agreements, ALA-restricted | PDF via secure portal |
| Download Only | Internal docs, reference copies | PDF download |

Templates define `allowed_delivery_modes`. OTP template
locked to `['wet_ink', 'download']` — e-sign structurally
blocked.

---

## 8. Document Creation Flow (Agent Side)

```
Select template → Select property (auto-fill) →
Select contacts, assign roles (auto-fill) →
Answer condition toggles (sections show/hide) →
Review rendered document →
Fill remaining manual fields →
Choose delivery mode → Send
```

---

## 9. Signing Gateway — Two-Factor Access

### The Gateway

Every person accessing documents passes through the gateway.
Same landing page for both e-sign and wet ink.

### Factor 1 — Identity Confirmation

"Enter your details to access your documents"
- Last 4 digits of ID number
- Date of birth
- Validates against the signing party's contact record

### Factor 2 — One-Time Password (Enhanced + High tiers)

After identity confirmed, OTP sent via separate channel:
- 6-digit code, cryptographically random
- Stored as bcrypt hash (never plain text)
- Valid 5 minutes, single use
- Max 3 attempts before 30-minute lockout
- Sent to REGISTERED contact method (not distribution channel)

### Security Tiers

| Tier | Factor 1 | Factor 2 | Use Case |
|------|----------|----------|----------|
| Standard | Last 4 ID + DOB | None | Internal, low-risk |
| Enhanced | Last 4 ID + DOB | Email OTP | Mandates, leases, FICA |
| High | Last 4 ID + DOB | SMS OTP | OTP, high-value deals |

Templates set minimum tier. Agents can escalate, never
downgrade.

### Consent Declaration (after access granted)

"By proceeding, I confirm:

1. I am [Full Name] (ID: ****[last 4]).
2. I am acting of my own free will.
3. I understand I am about to review and sign legal documents
   electronically.
4. My electronic signature carries the same legal weight as
   a handwritten signature (ECTA s13).
5. I consent to processing of my personal information (POPIA).

I have read and understood the above."

### Consent Data Captured

| Field | Value |
|-------|-------|
| flow_id | FK to flows |
| signing_party_id | FK to esign_signing_parties |
| id_number_entered | Encrypted |
| consent_text | Full declaration shown |
| consent_accepted_at | Timestamp (UTC) |
| ip_address | Request IP |
| user_agent | Browser user agent |
| device_info | Browser, OS, screen resolution |
| document_hash | SHA-256 of document at entry |

Consent records are NEVER deleted, NEVER editable.
Retained minimum 5 years (FICA requirement).

---

## 10. Distribution Channels

### Available Channels

| Channel | How |
|---------|-----|
| Email | CoreX sends branded email with link |
| SMS | Via SA provider (BulkSMS/Clickatell) |
| WhatsApp | Via Business API or agent copies link |
| Copy Link | Agent shares however they choose |

Agent picks channel per party. Multiple channels allowed.
Link is the same regardless of channel — security is at
the gateway, not the delivery.

### Channel Separation

Distribution and OTP use different channels for security:
- Link via WhatsApp → OTP via email
- Link via email → OTP via SMS
- Compromising one channel doesn't compromise access

---

## 11. E-Sign Pipeline

### Signing Party Model

Table: `esign_signing_parties`

| Column | Type | Notes |
|--------|------|-------|
| flow_id | FK → flows | |
| contact_id | FK → contacts | |
| role | string | seller, buyer, landlord, tenant, agent, witness, supervisor |
| display_name | string | From contact record |
| id_number | text encrypted | For gateway validation |
| email, phone | string | For distribution + OTP |
| signing_order | int | Sequential or shared (all = 1) |
| status | string | pending/consented/signing/completed/declined |

### Signing Modes

**Shared session** (same device — e.g. husband/wife):
- Both parties have signing_order = 1
- Same link, ID entry determines who signs
- Party 1 signs, hands device to Party 2

**Sequential** (separate emails):
- Party 1 signing_order = 1, Party 2 = 2
- Party 1 signs first, agent reviews
- Party 2 sees Party 1's signatures (read-only), signs theirs

### Dynamic Signature Sections (Web Documents)

Signature zones in web documents generate per party count.
No bounding boxes, no overflow, no spacing problems.

- 1 seller → 1 signature block
- 3 sellers → 3 signature blocks stacked
- Content flows naturally around them

Each block labeled with party name, tagged with
signing_party_id for activation during signing.

### Per-Party FICA Rendering

Templates with `party_mode = 'per_party'` generate one
copy per signing party at flow creation:

- 2 sellers → 2 FICA documents, each pre-filled from
  the respective contact record
- Merged into one continuous view for signing
- Stored separately after signing

### Multi-Party Pack Example

Pack: Mandate + FICA + Mandatory Disclosure
Parties: 2 landlords

System renders:
```
Pages 1-4:   Mandate (shared — both sign)
Pages 5-9:   FICA — Landlord 1 (pre-filled)
Pages 10-14: FICA — Landlord 2 (pre-filled)
Pages 15-18: Mandatory Disclosure (shared — both sign)
```

One link, one continuous document, four stored documents.

### Recipient Fill-In Fields

Some fields left blank for recipient to complete (e.g.
tenant's banking details). Marked as `fill_in: recipient`
during template setup. Highlighted differently from
agent-filled fields. No re-signing needed — expected blanks.

---

## 12. Amendment Engine

### Five Types of Recipient Modification

| Type | What | Re-signing |
|------|------|------------|
| Recipient fill-in | Expected blank fields | None needed |
| Other conditions | Free text additions | All parties initial each condition |
| Clause strikeout | Reject a clause | All parties initial the strikeout |
| Clause amendment | Change wording | All parties initial the amendment |
| Clause addition | Insert new clause (numbered X.XA) | All parties initial the addition |

### Signing View Toolbar

When recipient taps an amendable clause:
`[Strike out] [Amend] [Add below]`

Locked clauses show no toolbar.

### Amendment Rendering

**Strikeout:** Original text visible with strikethrough.
Party initials next to it. All other parties must review
and initial to accept.

**Amendment:** Original struck through, replacement text
in highlighted block below. Before/after clearly visible.
All parties initial.

**Addition:** New clause inserted between existing clauses,
numbered as "6.3A". All parties initial.

### Amendment Flow

```
Party adds amendment → submits →
SYSTEM DETECTS AMENDMENT →
Document version incremented (v1 → v2) →
All previous signers notified →
Each must review and initial each amendment →
  Accept (initial) → flow continues
  Reject → agent notified, mediates
```

### Amendment Tracking

| Field | Description |
|-------|-------------|
| flow_id | Which flow |
| amended_by_party_id | Who changed it |
| amendment_type | strikeout/amend/addition |
| clause_reference | Which clause |
| original_text | Before (null for additions) |
| new_text | After (null for strikeouts) |
| document_version | v1, v2, v3... |
| document_hash_before | SHA-256 before |
| document_hash_after | SHA-256 after |

Each amendment requires individual initials from every
party. Not one initial for all amendments — one initial
per amendment per party.

---

## 13. Candidate Practitioner Flow

### PPRA Requirement

Under the Property Practitioners Act 22 of 2019, candidates
cannot independently transact. All documentation must be
reviewed and authorised by a full status practitioner or
principal.

### Detection

CoreX detects candidate designation on the user profile.
Automatically injects supervisor approval steps. Cannot
be disabled or skipped.

### Supervisor Assignment

User profile field: `supervised_by` (FK to users).
If not set, agency principal is the default.

### Candidate Flow Sequence

```
1. Candidate creates + signs
2. → Supervisor reviews + authorises
3. → External Party 1 signs
4. → Candidate reviews
5. [Repeat 3-4 for more parties]
6. → Candidate final authorisation
7. → Supervisor final sign-off
8. → Complete
```

### Full Status Flow (comparison)

```
1. Agent creates + signs
2. → External Party 1 signs
3. → Agent reviews
4. [Repeat 2-3 for more parties]
5. → Agent final sign-off
6. → Complete
```

### Supervisor Signature Zone

Templates include a supervisor zone with `party_role: supervisor`.
Only renders when flow is candidate-originated.
Hidden for full status agents — same template works for both.

---

## 14. Wet Ink Hybrid Flow

### Complete Sequence

```
Agent creates document in CoreX →
System generates A4 PDF →
Agent downloads, prints, signs (wet ink) →
Agent uploads signed scan →
Agent sends link to recipient →
Recipient enters gateway (ID + OTP) →
Recipient sees: [View] [Download PDF] [Upload Signed] →
Recipient downloads, prints, signs, uploads →
Agent reviews uploaded scan, approves →
[Repeat for more parties] →
Complete
```

### Version Tracking

Every upload creates an immutable version:

| Version | Content |
|---------|---------|
| v1 | CoreX-generated clean PDF |
| v2 | Agent-signed scan |
| v3 | Party 1-signed scan |
| v4 | Party 2-signed scan |
| v7 | Final all-parties-signed |

### Recipient Portal (Wet Ink)

Same branded landing page and gateway as e-sign.
After access: View, Download PDF, Upload Signed.
Upload accepts PDF, JPG, PNG.
Confirmation checkbox: "I confirm this is the document
I have signed and all pages are included."

---

## 15. OTP-Specific Components

### Money Waterfall

Agent enters purchase price, then adds payment lines:

```
Purchase Price               R 2,500,000
Less: Deposit                R   250,000
Less: Bond                   R 2,000,000
Less: Cash Payment 1         R   125,000
Less: Cash Payment 2         R   125,000
Balance                      R         0 ✓
```

Validation: balance MUST equal zero before send.
Bond approval date auto-calculated (offer + 30 days).
Each payment line has amount + due date.

### Suspensive Conditions

Toggle-based: bond approval, sale of property, custom.
Each with auto-calculated fulfilment dates.
Toggle off = excluded entirely (no strikeout needed).

### Occupation

Conditional: "On transfer" or specific date.
Occupational rental clause auto-included if occupation
before transfer.

### Inclusions / Exclusions

Itemised list builder:

```
Item                    Location
Samsung fridge freezer  Kitchen
Pool pump & equipment   Pool house
[+ Add item]
```

Each item individually listed. No vague "all furniture."

### Date Auto-Calculations

| Field | Calculation |
|-------|-------------|
| Bond approval | Offer date + 30 days |
| Transfer (est.) | Offer date + 90 days |
| Condition deadlines | Per condition |
| Deposit due | On signature or manual |

All auto-calculate, all manually overridable.

---

## 16. Document Chaining — Cross-Flow Attachment

### Core Concept

Documents belong to properties, contacts, and deals — not
to individual signing flows. They move between flows,
collecting signatures at different stages.

### Document Lifecycle (independent of flows)

| Status | Meaning |
|--------|---------|
| draft | Created, not yet sent |
| partial | Some signatures collected, more needed |
| pending_inclusion | Current parties signed, waiting for future flow |
| in_signing | Currently in an active signing flow |
| completed | All required signatures collected |
| superseded | Replaced by amended version |
| expired | Time-based expiry (mandate, lease) |

### Document Anchoring

| Anchor | Example |
|--------|---------|
| Property | Mandatory disclosure (about property condition) |
| Contact | FICA declaration (about the individual) |
| Deal | OTP, lease (about the transaction) |
| Agent | Mandate (about the agency's appointment) |

### Cross-Flow Example — Mandatory Disclosure

**Mandate flow:** Landlord signs mandatory disclosure.
Document stored on property record. Status: partial.

**Lease flow (weeks later):** System finds existing
mandatory disclosure on property, signed by landlord.
Agent includes it. Tenant sees landlord's signature,
signs their section. Status: completed.

**Deal falls through, new tenant:** Same mandatory
disclosure goes to new tenant. Landlord doesn't re-sign.

### FICA Reuse

FICA valid for configurable period (12-24 months).
System checks contact record before generating new FICA.
If valid FICA exists: "Use existing or create new?"

### Document Requirements per Template

Templates define required related documents:

```json
{
    "template": "Lease Agreement",
    "required_documents": [
        {
            "type": "mandatory_disclosure",
            "anchor": "property",
            "must_be_signed_by": ["landlord"],
            "action_if_missing": "warn"
        },
        {
            "type": "fica_declaration",
            "for_each_party_role": ["tenant"],
            "action_if_missing": "auto_generate"
        }
    ]
}
```

System enforces: no lease without signed mandatory disclosure.
Doesn't just warn — blocks.

### Sign Now, Include Later

At flow creation, agent can mark documents as:
- Send now → enters current signing flow
- Sign later → saved as pending_inclusion
- Available for inclusion in future flows

---

## 17. Notification & Reminder Engine

### Signing Chase (automatic)

| Trigger | Recipient | Action |
|---------|-----------|--------|
| +24h unsigned | Party | Reminder email |
| +48h unsigned | Party | Second reminder |
| +72h unsigned | Agent | Escalation notification |
| +7 days | Agent | "Flow stalled" alert |

Configurable per agency. Agent can pause, extend, or
manually nudge.

### Date Reminders

**Bond deadline:** -7, -3, -1 days, on day, +1 expired
**Lease renewal:** -90, -60, -30, -7 days
**Mandate expiry:** -30, -7 days
**Deposit due:** on day, +1 overdue
**Suspensive conditions:** -7, -1 days, on day, +1 lapsed

Lease renewal at -90 days includes one-click "Renew Lease"
action — pre-populates new lease with escalated rental.

### Calendar Integration

Every date reminder creates a CoreX calendar event.
Colour-coded by type. Direct link to document.
Future: Google Calendar / Outlook sync.

### Notification Channels

| Channel | Internal | External |
|---------|----------|----------|
| In-app (bell icon) | Yes | No |
| Email | Yes | Yes |
| Calendar event | Yes | No |
| SMS (future) | Optional | Optional |

### Preference Levels

Agency defaults → Agent overrides → Per-document overrides.
Business hours respected. Quiet hours configurable.

---

## 18. Document Storage & Lookup

### Contact Profile — Documents Tab

All documents where contact is a party. Grouped by
active/completed/expired. FICA compliance badge:
- Green: FICA complete
- Amber: FICA expiring
- Red: FICA required

### Property Profile — Documents Tab

All documents linked to property. Current mandate/lease
status. Timeline view of document history.

### Deal Profile — Documents Tab

Complete document set for the deal. Signing status per
document. Full audit trail.

### Search

Documents searchable by name, party, property, type,
status, date range, agent. CDS JSON makes clause content
searchable across all documents.

---

## 19. Reusable Interactive Components

| Component | Used By |
|-----------|---------|
| Financial Calculator | OTP (waterfall), lease (escalation), mandate (commission) |
| Itemised List | OTP (inclusions), inspection (defects), inventory |
| Conditional Section | Every document type |
| Date Calculator | OTP (bond deadline), lease (start/end), mandate (expiry) |
| Party List | Every document type |
| Negotiation Toolbar | OTP (amendments), lease (special conditions) |

---

## 20. Data Model (Key Tables)

### documents

```
id, uuid, template_id, agency_id, created_by
status (draft/partial/pending_inclusion/in_signing/
        completed/superseded/expired)
property_id  FK nullable
contact_id   FK nullable
deal_id      FK nullable
cds_json     JSON
field_values JSON
required_signatures JSON
version      int
parent_id    FK nullable (amended version)
valid_from   date nullable
valid_until  date nullable
signed_at    timestamp nullable
```

### document_flow_links

```
document_id  FK
flow_id      FK
included_at  timestamp
included_by  FK users
role_in_flow enum (original/attachment/reference)
signing_requirements JSON
```

### document_signatures

```
document_id, signing_party_id, contact_id
role, signed_at, signature_data
flow_id (which flow this signature came from)
ip_address, consent_log_id
```

### esign_signing_parties (already created)

```
flow_id, contact_id, role, display_name
id_number (encrypted), email, phone
signing_order, status
consented_at, completed_at, declined_at
proxy_for_party_id, proxy_poa_reference
```

### esign_consent_log (already created, immutable)

```
flow_id, signing_party_id, contact_id
id_number_entered (encrypted), consent_text
consent_accepted_at, ip_address, user_agent
device_info JSON, document_hash
```

### esign_otp_codes

```
flow_id, signing_party_id
code_hash (bcrypt), channel (email/sms)
sent_to (masked), created_at, expires_at
verified_at, attempts_used, max_attempts
```

---

## 21. Build Phases

### Sprint 1 — Renderer + Extraction
1. CoreX Document Renderer (CSS, A4, typography)
2. Content Extraction Pipeline (replace Mammoth)
3. Template Builder UI (import, review, fields)

### Sprint 2 — Creation + E-Sign
4. Document Creation Flow (data fill, conditions)
5. E-Sign Pipeline + Signing Gateway
6. Distribution Channels (email, SMS, copy link)
7. Tiered Access Security (identity + OTP)

### Sprint 3 — Wet Ink + OTP
8. Wet Ink Hybrid Flow + Recipient Portal
9. Reusable Components (calculator, lists, dates)
10. OTP Template (money waterfall, conditions)

### Sprint 4 — Amendments + Compliance
11. Amendment Engine (strikeout, amend, add, re-sign)
12. Candidate Practitioner Flow
13. Document Chaining + Cross-Flow Attachment

### Sprint 5 — Notifications + Polish
14. Notification Engine (chase + date reminders)
15. Calendar Integration
16. Document Storage + Lookup (contact/property/deal)
17. PDF Output (Puppeteer, @page CSS)

### Sprint 6 — Scale
18. Multi-Agency Branding
19. Lease Template (escalation, renewal)
20. FICA + Mandatory Disclosure Templates
21. First Template: Letting Mandate (e-sign live)

---

## 22. Already Built (Foundation)

- [x] Template is_esign toggle (working)
- [x] Template party_mode field (shared/per_party)
- [x] Pack CRUD — PDF and Web (working)
- [x] PDF pack wired through wizard store()
- [x] esign_signing_parties table + model (encrypted ID)
- [x] esign_consent_log table + model (immutable)
- [x] ESignFlow → signingParties() relationship
- [x] Template → isPerParty() helper

---

> This is the single source of truth for the CoreX
> Document Engine. All development references this spec.
> Place at: /.ai/specs/document-engine.md