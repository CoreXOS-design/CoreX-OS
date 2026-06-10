# E-Sign V3 Complete Spec

**Status:** DRAFT
**Author:** Claude + Johan Reichel
**Date:** 21 May 2026

Single source of truth for: DocuPerfect e-signature module, document management, amendments, FICA gate, supervisor flows, AI Template Import.

**Supersedes:**

- `.ai/specs/esign-v2-state-of-reality.md` (March 27 snapshot)
- `.ai/audits/esign-v2-reaudit-2026-05-21.md` (re-audit findings folded in)

---

## 1. Mission

DocuPerfect is the document and signature pillar of CoreX. It exists to make compliant, legally-sound document creation and signing a one-click experience for South African estate agents.

Three principles:

1. **The agency designs once, agents click forever.** Templates are crafted centrally with declarative metadata. Agents never configure fields, parties, or signatures — they fill in property/seller details and the system does the rest.

2. **Every signature is legally defensible.** Audit trails are immutable. OTP verification is mandatory. Documents that cannot legally be e-signed (sale agreements) are physically blocked from the e-sign path, not just policy-blocked.

3. **The system is the compliance officer's deputy.** FICA gates, mandate approval gates, document packs, and amendment cascades are enforced by the system. A compliance officer reviews flags; the system handles enforcement.

---

## 2. Legal Framework

E-Sign operates within South African law. The system must enforce these constraints, not rely on user discipline.

### 2.1 Alienation of Land Act 68 of 1981

Section 2(1): *No alienation of land shall be of any force or effect unless it is in writing and signed by the parties thereto.*

ECTA Section 13(1) explicitly excludes alienation of immovable property from electronic signature equivalence with wet-ink signatures.

**Implication for CoreX:** Sale agreements, Offers to Purchase (OTP), Deeds of Sale, Deeds of Alienation, and Agreements of Sale **cannot** be e-signed. They must use wet-ink delivery only.

**System enforcement:** `Template::isEsignBlocked()` must return true for any template falling into this category. Four-layer defence (see §5.3).

### 2.2 Electronic Communications and Transactions Act 25 of 2002

ECTA section 13 establishes that an electronic signature carries the same legal weight as a handwritten signature, **except** for documents excluded by section 13(1) — alienation of land, wills, and bills of exchange.

**Implication for CoreX:** Mandate agreements, marketing permissions, FICA forms, addenda (other than OTP-related), and most other estate agency documents can be e-signed under ECTA.

### 2.3 FICA Act 38 of 2001 (as amended)

Estate agents are accountable institutions. Every party to a property transaction must be FICA-verified before mandate execution.

**Implication for CoreX:** FICA gate. No mandate can be sent for e-signature until the contact's FICA record is `STATUS_APPROVED`. System enforced.

### 2.4 POPIA (Protection of Personal Information Act)

Personal information collected during signing (ID number, address, contact details, signed documents) must be:

- Collected lawfully and for a specific purpose
- Retained no longer than necessary
- Protected against unauthorised access
- Deletable on request (right to be forgotten)

**Implication for CoreX:** Soft deletes only; no hard deletes. Encrypted storage for sensitive fields. Audit logs for all access. Retention policy enforced.

### 2.5 PPRA Code of Conduct (Property Practitioners Act 22 of 2019)

Estate agents must:

- Disclose all material facts
- Provide clear written mandates
- Not engage in undue influence
- Maintain proper records

**Implication for CoreX:** Marketing permissions cannot be combined with mandates without explicit acknowledgement. Mandate documents must be human-readable, single A4 where possible, plain language.

### 2.6 Candidate Practitioner Supervision

Section 35 of the Property Practitioners Act requires candidate practitioners to operate under the supervision of a registered principal. All material agreements signed by a candidate practitioner must be co-signed or approved by their supervising principal.

**Implication for CoreX:** Candidate practitioner supervisor flow. Two-signature requirement enforced by the system on every document where the agent is a candidate.

---

## 3. Module Scope

### 3.1 What DocuPerfect IS

- The templating engine (Blade-based, declarative metadata)
- The signing wizard (6-step flow with three delivery modes)
- The signing experience (recipient-facing OTP-verified signing)
- The document amendment system (proposed changes → cascade → re-sign)
- The FICA gate (enforces FICA before mandate e-sign)
- The agent approval gate (compliance officer approval where required)
- Document packs (multi-template bundles signed in sequence)
- Auto-filing (signed documents land in Document Drive)
- The audit trail (every action immutable, retrievable)
- AI Template Import (PDF/Word → AI-suggested template)

### 3.2 What DocuPerfect IS NOT (but adjacent to)

- **FICA module** — DocuPerfect reads FICA status, does not manage FICA submissions
- **Mandates module** — DocuPerfect executes mandates, does not configure mandate types
- **Document Drive** — DocuPerfect files into the drive, drive itself is separate
- **Contact records** — DocuPerfect uses contacts, does not create them
- **Calendar** — DocuPerfect triggers events (appointment for signing), calendar itself separate

### 3.3 Module boundaries — what crosses

| Crosses into | What flows |
|---|---|
| FICA | Read `FicaSubmission` status before allowing mandate e-sign |
| Mandates | A signed mandate template via DocuPerfect creates a mandate record |
| Contacts | Read contact details for party pre-fill; write `document_contact` pivot |
| Document Drive | Auto-file signed documents to contact's drive |
| Calendar | Optionally create appointment for signature day |
| Notifications | Email + SMS + WhatsApp signing invitations and reminders |

---

## 4. Architecture

### 4.1 The Template Engine

DocuPerfect supports two template authoring modes:

**Mode A: Hand-crafted Blade templates** (preferred for legal/compliance docs)

- Crafted by Johan + Claude centrally
- Stored as `.blade.php` files in `resources/views/docuperfect/templates/`
- Declarative metadata in template's PHP companion file or YAML front-matter:
  - `field_mappings`: which Blade variables come from where (contact, property, agency, signed-on)
  - `parties`: array of party roles and order (e.g. `['seller', 'agent', 'witness']`)
  - `signature_positions`: where signatures go on rendered PDF
  - `editable_by`: which fields can be edited at signing time and by whom
  - `delivery_modes`: which modes are allowed (e-sign / wet-ink / download)
  - `template_type`: legal classification (`'mandate'`, `'fica'`, `'marketing_permission'`, `'addendum'`, `'otp'`, `'sale_agreement'`, `'amendment'`)
  - `requires_fica`: true/false
  - `requires_co_approval`: true/false
- Multi-tenancy via `$agency->*` variables — single template renders correctly across agencies

**Mode B: CDS (Custom Document System) builder** (for agency-specific docs)

- Originally intended as the primary path; usage data shows it's been unreliable
- WYSIWYG editor with field placement
- Still mounted but de-prioritised
- Future: replaced/supplemented by AI Template Import (see §12)

**The architectural shift (March–May 2026):** Original spec assumed agencies would design their own templates via CDS. Reality: this is too hard for agencies. New model: Johan/Claude own all template design centrally, with agencies customising via metadata flags (agency name, logo, commission rate) but not template structure. AI Template Import becomes the bridge — agencies upload their existing documents, AI reads and converts them to CoreX templates, Johan/Claude review and finalise.

### 4.2 Template Schema (actual state per CDS audit 2026-05-21)

The canonical table is `docuperfect_templates`. Live columns:

```sql
docuperfect_templates
  id
  name                      string
  template_type             string  -- free-form tag: 'cds', 'sales', 'rental', 'imported', 'general', 'standard', 'mandate', 'otp'
  render_type               enum('pdf','web') default 'pdf'
  blade_view                string nullable  -- dotted view name, e.g. 'docuperfect.web-templates.cds.template-126'
  document_type_id          bigint FK → document_types(id)    -- legal classification (mandate/fica/otp/sale_agreement/...)
  category                  enum('sales','rentals') nullable
  page_count                int default 0
  fields_json               json nullable    -- legacy + PDF-overlay zones
  cds_json                  json nullable    -- sectioned CDS structure
  field_mappings            json nullable    -- per-tag config (typeKey, namedFieldId, party, parties, editable_by, etc.) keyed by tag-id
  allowed_delivery_modes    string(100) default 'esign,wet_ink,download'   -- CSV
  security_tier             string(20) default 'enhanced'                  -- standard | enhanced | high
  editor_state              json nullable    -- lossless re-edit blob (tags, mappings, tagged_html)
  is_global                 bool default false
  is_esign                  bool default true
  party_mode                string(20) default 'shared'                    -- shared | per_party (FICA)
  signing_parties           json nullable    -- generic role tokens: owner_party | acquiring_party | agent
  header_display            string(20) default 'first_page'                -- first_page | all_pages | none
  sections                  json nullable    -- wizard-step section overrides
  wizard_config             json nullable
  owner_id                  bigint FK → users.id
  archived_at               timestamp nullable
  created_at, updated_at, deleted_at
```

**Important distinctions from the original spec:**

- `template_type` is a **free-form string tag**, not an enum. It distinguishes the *editor mode* (`cds` = CDS builder, `sales`/`rental` = older flows) — it is NOT the legal classification.
- **`document_type_id` is the canonical legal classification** — FK to `document_types.slug`. The 23 live slugs at the time of the audit include: `mandate`, `fica`, `ids`, `por`, `condition_report`, `listing_form`, `rates_taxes`, `body_corporate`, `house_rules`, `offer_to_purchase`, `disclosure`, `other`, `addendum`, `rental_agreement`, `lease_agreement`, plus FICA/screening sub-types. **Note:** at audit time `otp`, `sale_agreement`, `deed_of_sale`, `deed_of_alienation` slugs were NOT present — ES-1 migration adds them.
- **`category`** (sales / rentals) is the transaction-type column the wizard reads to swap "Lessor/Lessee" ↔ "Seller/Buyer" labels.
- **`allowed_delivery_modes`** is a CSV string, not a JSON array.
- **`security_tier`** (not `security_level`).
- **`party_mode`** (`shared` or `per_party` — the latter for FICA-style per-signer duplication).
- **`signing_parties`** is a JSON array of generic role tokens (`owner_party`, `acquiring_party`, `agent`) — resolved to concrete labels at render time by `Template::mapSigningPartyKeys()` using `category` / name heuristics.

**Related tables in the CDS pipeline:**

```sql
cds_drafts                    -- pre-save autosave staging for the CDS builder
  id, user_id, agency_id, template_name, cds_json, tags, mappings,
  tagged_html, settings, source_template_id, status, deleted_at

docuperfect_import_drafts     -- pre-CDS-draft staging from the .docx import flow
  id, user_id, filename, html, fields_json, deleted_at

field_corrections             -- learning loop: AI suggestion vs user final mapping
  context, claude_suggested_key, claude_suggested_label,
  user_corrected_key, user_corrected_label, document_type, user_id

docuperfect_named_fields      -- system fields catalogue (source-mapped)
  name, field_type, source_type, source_column, source_contact_type

docuperfect_field_groups      -- agency/global field bundles for one-tag-many-fields

docuperfect_clauses           -- the clause library (21 rows live)
  id, name, text, is_global, owner_id, deleted_at

document_types                -- legal classification catalogue (slug + label)
  id, slug, label, sort_order, is_active, grouping, listing_types
```

**Storage layering of placeholders** (docx markers → builder → blade view):

```
.docx source ......... @@@@ / %%%% / #### / ~~~~ markers
       ↓ CdsParserService::detectMarkers()
cds_drafts.cds_json .. sectioned structure with field_placeholder / signature_placeholder / initial_placeholder items
       ↓ user edits in the CDS builder
cds_drafts.tagged_html  doc-tag spans: <span class="doc-tag doc-tag-input" data-tag-id="...">[Label]</span>
cds_drafts.mappings ... { tag-id: { mappingType, typeKey, namedFieldId, party, parties, editable_by } }
       ↓ TemplateController::cdsGenerate()
docuperfect_templates  cds_json + field_mappings + editor_state (round-trip blob)
       ↓ TemplateController::generateCdsBladeView()
resources/views/docuperfect/web-templates/cds/template-{id}.blade.php
       <span class="corex-field-value" data-field="seller_name">{{ $seller_name ?? '' }}</span>
```

**Agency scoping:** `docuperfect_templates` does NOT carry `agency_id`. Multi-tenancy is enforced via `is_global` + branch joins through `docuperfect_template_branches`. New tables introduced by this spec (e.g. `legal_block_audit_log`) carry `agency_id` derived from the acting user's `effectiveAgencyId()`.

### 4.3 The Signing Wizard (V2)

The 6-step flow agents follow to create and dispatch a document for signing:

**Step 1: Template selection**

- Filter by category
- Search by name
- Templates flagged with `isEsignBlocked()` are visually marked and cannot be selected for e-sign delivery
- Document Packs appear as bundled options (Sales Mandate Pack, etc)

**Step 2: Contact & property selection**

- Pick from existing contacts (or quick-create)
- Pick property (or quick-create from address)
- System pre-fills party records from contact data

**Step 3: Party configuration**

- Each party from `template->signing_parties` (generic role tokens) gets a row.
- **Party-role labels are metadata-driven**, not hard-coded. The wizard reads:
  - `template->signing_parties` → array of `owner_party` / `acquiring_party` / `agent` tokens
  - `template->category` (`sales` | `rentals`) — drives `Template::isSalesDocument()` → `Template::mapSigningPartyKeys()` resolves to display labels:
    - Sales: `owner_party → Seller`, `acquiring_party → Buyer`
    - Rentals: `owner_party → Lessor`, `acquiring_party → Lessee`
- Agent role auto-fills with current user.
- Owner/acquiring roles fill from contact selection.
- Witness role optional (driven by `agency_signing_parties` table per agency).
- Override email / phone if needed.
- Signing order assigned (`signature_templates.signing_order_json` per-document; default order from template's `signing_parties` array sequence, override allowed).

**Resolver contract:** the wizard's contact-resolver MUST read party labels from `Template::mapSigningPartyKeys($template->signing_parties, $template->isSalesDocument())`. No view, controller, or service may hard-code "Seller" / "Buyer" / "Lessor" / "Lessee" — always go through this resolver so a single template renders correctly across sales and rentals.

**Step 4: Field customisation**

- Editable fields surface per template metadata
- Date fields default to today
- Price fields require explicit entry
- Custom fields where template allows
- Preview shows live rendered document

**Step 5: Delivery mode selection**

- Three options:
  - **E-sign** — system orchestrates OTP-verified electronic signing
  - **Wet-ink portal** — system generates document, party signs physically and uploads via portal
  - **Download only** — agent prints, gets wet-ink signature, files manually
- E-sign blocked for OTP / sale agreement templates
- Agent picks mode per recipient (e.g. seller wet-ink, agent e-sign)

**Step 6: Gates & dispatch**

- **FICA gate:** if `requires_fica` and contact's FICA != APPROVED, blocked with prompt to complete FICA first
- **CO approval gate:** if `requires_co_approval`, document enters `APPROVAL_PENDING` state, compliance officer must approve before dispatch
- Once gates pass: document dispatched, party 1 receives signing invitation
- Document state: `STATUS_SENT`

### 4.4 The Signing Experience

What the recipient (seller, buyer, witness) experiences:

1. Invitation email/SMS/WhatsApp with secure tokenised link
2. Landing page at `/sign/{token}`:
   - Party identity check (name shown, "Is this you?" confirmation)
   - FICA gate (if not already complete and required)
   - Document preview (per-document pagination)
3. Per-document pagination (§7.4): if signing a pack, each document shown separately with pagination between them
4. OTP verification before signature commits
5. Signature capture: draw, type, or upload signature
6. Initial pages capture per-page initials where template requires
7. Confirm & submit — signed document created, party marked complete
8. Next party invitation triggered automatically (signing_order)
9. Final completion when all parties signed — document state `STATUS_COMPLETE`, auto-filing kicks in

### 4.5 Document State Machine

| Status | Description | Transitions to |
|---|---|---|
| `STATUS_DRAFT` | Wizard in progress, not yet dispatched | SENT, CANCELLED |
| `STATUS_APPROVAL_PENDING` | Awaiting CO approval before dispatch | SENT, CANCELLED |
| `STATUS_SENT` | Dispatched, awaiting first party signature | PARTIAL, CANCELLED, EXPIRED |
| `STATUS_PARTIAL` | Some parties signed, others outstanding | PARTIAL, AMENDMENT_REVIEW, COMPLETE, CANCELLED, EXPIRED |
| `STATUS_AMENDMENT_REVIEW` | Amendment flagged, agent must approve change | AMENDMENT_INITIALING, REJECTED |
| `STATUS_AMENDMENT_INITIALING` | **NEW (ES-3, revised §8)** — Agent approved change; all parties initial only the changed regions in `signing_order` | COMPLETE, CANCELLED |
| `STATUS_REJECTED` | Agent rejected whole document — negotiation failed | — (terminal) |
| `STATUS_COMPLETE` | All parties signed, auto-filing complete | — (terminal) |
| `STATUS_CANCELLED` | Cancelled mid-flow | — (terminal) |
| `STATUS_EXPIRED` | Time-out without completion | CANCELLED |

---

## 5. The Legal Block (Sale Agreements / OTPs)

This is the most critical compliance feature. Four-layer defence.

### 5.1 Layer 1 — Document-type classification (`document_type_id` → `document_types.slug`)

The canonical legal classification lives in `docuperfect_templates.document_type_id` (FK → `document_types`). Slugs that trigger the legal block:

- `otp`
- `offer_to_purchase` (legacy slug, kept blocked for safety — see §5.6)
- `sale_agreement`
- `deed_of_sale`
- `deed_of_alienation`

ES-1 migration adds the four missing slugs to `document_types` (only `offer_to_purchase` existed at audit time).

The free-form `template_type` string column is consulted as a fallback for templates that pre-date the slug catalogue:

```php
$slug = $this->document_type?->slug ?? $this->template_type ?? '';
```

### 5.2 Layer 2 — Name pattern fallback (the fix from ES-1)

Templates without explicit `template_type` set fall to name-pattern matching. Patterns:

```php
preg_match('/\b(otp|deed of alienation|agreement for sale|sale agreement|agreement of sale|deed of sale|offer to purchase)\b/i', $template->name)
```

Word-boundary enforced — "Photoshop" does not match, "SB 2026 OTP" does.

### 5.3 Layer 3 — Wizard UI block

In the wizard Step 5 (delivery mode), if `Template::isEsignBlocked()` returns true:

- E-sign mode option is disabled (greyed out)
- Tooltip explains: "Sale agreements and offers to purchase cannot be e-signed under South African law. Please use wet-ink delivery."
- Only wet-ink and download options selectable

### 5.4 Layer 4 — Server hard block at dispatch

In `ESignWizardController::prepareSigning()`, regardless of what the wizard JS sent:

```php
if ($template->isEsignBlocked() && $deliveryMode === 'esign') {
    throw new \DomainException('E-sign blocked: this template requires wet-ink signature under SA law.');
}
```

### 5.5 Layer 5 — Audit logging

Every time `isEsignBlocked()` returns true, log to `legal_block_audit_log`:

- `agency_id` (from acting user's `effectiveAgencyId()` — templates do not carry `agency_id` directly)
- `template_id`, `template_name`, `document_type_slug`
- `user_id`, `request_context` (JSON: route, IP, user_agent)
- `block_reason` (`'document_type_match'` or `'name_pattern_match'`)
- `matched_pattern` (what specifically matched)
- `created_at` (immutable — insert-only table, no `updated_at`, no `deleted_at`)

The model overrides `save()` to throw `DomainException` if `$this->exists` — log rows cannot be mutated once written.

This creates a forensic trail proving the system actively prevented illegal e-signs. Defensible in dispute.

### 5.6 Data remediation

Run-once migration `2026_05_21_220002_classify_otp_templates.php`:

1. **Add missing slugs** to `document_types`: `otp`, `sale_agreement`, `deed_of_alienation`, `deed_of_sale` (only `offer_to_purchase` existed at audit time).
2. **Classify templates by name pattern → set `document_type_id`** (only where `document_type_id IS NULL` — never overrides existing classifications):
   - name matches `/\b(otp|offer to purchase)\b/i` → slug `otp`
   - name matches `/\bdeed of alienation\b/i` → slug `deed_of_alienation`
   - name matches `/\b(agreement (for|of) sale|sale agreement)\b/i` → slug `sale_agreement`
   - name matches `/\bdeed of sale\b/i` → slug `deed_of_sale`
3. Future `isEsignBlocked()` calls hit the fast slug `in_array` check before falling through to the regex.

---

## 6. The FICA Gate

### 6.1 When the gate fires

Any template with `metadata.requires_fica = true` (typically mandates, addenda) triggers the gate.

### 6.2 Gate logic

In `SigningController::index()` and `SignWizardController::dispatch()`:

```php
$fica = FicaSubmission::where('contact_id', $contact->id)
    ->orderByDesc('created_at')
    ->orderByDesc('id')  // ES-2 fix
    ->first();

if (!$fica || $fica->status !== 'approved') {
    return redirect()->route('fica.create', [
        'contact' => $contact->id,
        'return_to' => $signingUrl,
    ])->with('info', 'FICA verification required before signing.');
}
```

### 6.3 The ES-2 bug

Currently the orderBy is missing. When a contact has multiple `FicaSubmission` rows (common when wizard auto-creates), MySQL row order is non-deterministic. First request gets row A, refresh gets row B — status appears to "fix itself."

**Fix:** Add `->orderByDesc('created_at')->orderByDesc('id')` to both query sites (`SigningController` line 106-108 + `SignWizardController` equivalent).

### 6.4 FICA states relevant to e-sign

- `pending` → blocked
- `corrections_requested` → blocked, prompt to address corrections
- `approved` → cleared, gate passes
- `expired` → blocked (FICA expires 12 months after approval)
- `rejected` → blocked

---

## 7. Per-Document Pagination

This is the §19 work that shipped May 19 (commit `98e206f`).

### 7.1 Problem solved

Originally, a document pack (e.g. Sales Mandate Pack with 3 templates) presented all pages as one continuous flow. Recipients couldn't tell where one document ended and the next began. Confusion, mis-signing.

### 7.2 Solution

Each template renders independently with its own page-of-X counter. Between documents, an "About to sign the next document" interstitial.

### 7.3 Audit findings on this

Audit confirmed this works end-to-end. **No further work required in this area.**

### 7.5 Other Conditions & Insertable Blocks

#### 7.5.1 Concept

South African real estate contracts (mandates, OTPs, lease agreements) routinely have sections where parties enter transaction-specific clauses that aren't part of the printed template. These sections fall into four functional types:

| Block Type | Purpose | Example | Can Receive Override Inserts? |
|---|---|---|:-:|
| `other_conditions` | The legal catch-all. Strikethrough overrides land here. | "Subject to bond approval within 21 days" | YES |
| `included_items` | What's part of the sale | "Pool pump, garden tools, dishwasher" | NO |
| `excluded_items` | What's explicitly not part of the sale | "Spa bath, study furniture" | NO |
| `custom_named` | Agency-named typed block for specific purpose | "Outstanding Repairs", "Tenant Notice Period" | NO |

A template must have **at most one** `other_conditions` block. It must have **zero or more** of the other types. Templates without an `other_conditions` block cannot accept strikethrough overrides (system enforces).

#### 7.5.2 The `~~~~` Placeholder

The existing marker convention recognised by `CdsParserService::detectMarkers()` covers three types. ES-9 adds a fourth:

| Marker (≥4 repeats) | Existing? | Purpose | Builder UI behaviour |
|---|:-:|---|---|
| `@@@@` | YES | Input field — value filled at preparation or signing | Becomes a `doc-tag-input` span |
| `%%%%` | YES | Signature block — party signs | Becomes a `doc-tag-signature` span |
| `####` | YES | Initial block — party initials | Becomes a `doc-tag-initial` span |
| `~~~~<PURPOSE>~~~~` | **NEW (ES-9)** | Insertable conditions block | Becomes a `doc-tag-block` span with the purpose label |

The new marker carries its purpose **inline** between the tilde fences. Recognised forms:

- `~~~~OTHER_CONDITIONS~~~~` — the Other Conditions block (max 1 per template)
- `~~~~INCLUDED_ITEMS~~~~` — Included Items
- `~~~~EXCLUDED_ITEMS~~~~` — Excluded Items
- `~~~~CUSTOM:<label>~~~~` — custom-named block (e.g. `~~~~CUSTOM:Outstanding Repairs~~~~`)

The parser regex is `~{4,}([A-Z_]+(?::[^~]+)?)~{4,}` — case-sensitive purpose, optional `:label` suffix for custom blocks.

The CDS builder reads the parsed purpose and surfaces block-specific configuration in the right pane (min/max conditions, auto-numbering toggle, locked flag). All settings are stored in `docuperfect_templates.field_mappings` under the same per-tag key shape as input/signature/initial tags, with `mappingType: 'insertable_block'` and a `block_purpose` field.

**Storage at signing time:**

- The text of conditions added at signing time lives in `signature_templates.other_conditions_text` (longText — **column already exists** per CDS audit §1.11) for the `other_conditions` purpose.
- For `included_items` / `excluded_items` / `custom_named`, new rows go to `document_conditions` (defined in §7.5.10) so they can be numbered, soft-deleted, and audited individually.

**Why this storage split:** Other Conditions is a single contiguous block of free-form legal text where the order isn't surgical — `other_conditions_text` is the simplest fit and the column already exists on `signature_templates`. The other block types are list-oriented (numbered items, individual amendments) and benefit from a row-per-condition schema.

#### 7.5.3 The Clause Library

**Per the CDS audit 2026-05-21 §1.6: the data layer already exists.**

Table: `docuperfect_clauses` (21 rows live at audit time).

```sql
docuperfect_clauses
  id, name (label), text (clause body)
  is_global (true = visible to all agencies, false = agency-private)
  owner_id (refs users)
  created_at, updated_at, deleted_at (soft delete already in place)

docuperfect_clause_branches              -- branch-restriction join
  clause_id, branch_id
```

Existing wired-up routes (per audit §2.2):

- `GET /docuperfect/clauses` → list / search (`docuperfect.clauses.index`)
- `POST /docuperfect/clauses` → create
- `PUT /docuperfect/clauses/{id}` → update
- `DELETE /docuperfect/clauses/{id}` → soft delete (`docuperfect.clauses.destroy`)
- `POST /docuperfect/clauses/{id}/copy` → duplicate
- `POST /docuperfect/clauses/{clause}/restore` → un-archive
- `GET /docuperfect/api/clauses` → **JSON endpoint, ready for picker UIs** (`docuperfect.clauses.json` → `ClauseController@listJson`)

Live sample entries: "Subject to Viewing", "Tenant Vacating Notice", "Holiday Letting" (21 total).

**What ES-9 still has to build:**

1. **Builder-side "Insert clause" affordance** — slash-command or right-pane picker in the CDS builder that queries `GET /docuperfect/api/clauses` and inserts the clause text at the cursor as a regular paragraph (NOT as a tag — clause insertion is content, not a placeholder).
2. **Signing-experience clause picker** — modal launched from the "Add condition" affordance on an `other_conditions` block. Same JSON endpoint, same search experience.
3. **System-default seed** — a one-time migration to seed ~20 common SA-real-estate clauses with `is_global = true` and `owner_id = null`. Categories to introduce via a new `category` column (current schema has no category):
   - `bond` ("Sale subject to bond approval within [X] days")
   - `occupation` ("Occupation date shall be [date]")
   - `fittings` ("Voetstoots: the property is sold as-is")
   - `compliance` ("All electrical, water, gas and beetle compliance certificates to be provided by seller")
   - `fees`, `notice`, `general`

The schema additions are minimal (one `category` column, optional `is_system` flag if needed to distinguish CoreX defaults from agency-created). The existing `is_global` already serves to mark globally-visible clauses.

When inserting a new condition into an `other_conditions` block, the agent (or party) sees:

- Search box ("filter clauses…")
- Categorised list of library options
- "Write custom condition" option

Selecting a library entry pre-fills the condition text; user can still edit before committing.

#### 7.5.4 Adding a Condition

Any party with active access to the document can add a condition:

1. Click "Add condition" affordance on the block
2. Choose from library or write custom
3. Save → condition appears in numbered list (1, 2, 3…)
4. Document state → `STATUS_AMENDMENT_REVIEW`
5. Agent notified for review

#### 7.5.5 Strikethrough Override Flow

**This is the most important UX flow.** The strikethrough is how a party negotiates a printed clause without redrafting the document.

Step by step:

1. Recipient clicks on a clause they want to change (e.g. "Commission shall be 7%")
2. Modal opens: "Override this clause?"
   - Shows the original text
   - Offers: "Strike through and replace" OR "Cancel"
3. If "Strike through and replace":
   - Original clause is visually struck through in the document
   - System auto-creates a new condition in the `other_conditions` block:
     - Reference: "Override of clause [X.Y]"
     - Pre-fills: "Refer to other conditions clause [N]" where N = next-available number in the Other Conditions block
   - Recipient writes the replacement text (e.g. "Commission shall be 5%")
   - Optionally selects from clause library
4. System inserts:
   - **In original location:** clause is shown struck through with a small annotation: "See Other Conditions clause [N]"
   - **In Other Conditions block:** numbered condition with the reference back: "As per clause [X.Y], the commission agreed to is 5%."
5. Document state → `STATUS_AMENDMENT_REVIEW`
6. Agent receives notification → review

#### 7.5.6 Agent Review

**This is the gatekeeper step.** Every change passes through here.

Agent sees:

- Diff view: what was struck through, what was added
- Reason field (if recipient provided one)
- Three actions:
  1. **Approve change** → document continues to initialing cascade
  2. **Reject change** → revert to original state, recipient continues to sign original document
  3. **Reject entire document** → state `STATUS_REJECTED` (negotiation failed, agreement cannot be reached)

If the agent approves, the document goes through the initialing cascade (§7.5.7).

If the agent rejects the change (but not the document), the strikethrough is undone, the auto-inserted condition is removed, and the document is sent back to the recipient with a message: "The proposed change was rejected. Please sign the document as originally drafted, or propose a different change."

If the agent rejects the entire document, all parties are notified, document state `STATUS_REJECTED`. Original signatures retained in audit trail.

#### 7.5.7 Initialing Cascade (replaces "Re-Sign Cascade")

After agent approves a change, all parties must **initial only the new content** — not re-sign the entire document. This matches SA wet-ink practice exactly.

Flow:

1. Document state → `STATUS_AMENDMENT_INITIALING`
2. System identifies "changed regions":
   - The struck-through clause(s)
   - The new conditions added
   - Any new pages caused by overflow (each new page needs party initials per the bottom-of-page rule)
3. Party 1 receives notification: "Document was amended. Please review and initial the changes."
4. Party 1 lands on a focused initialing view that shows:
   - Only the changed regions (not the entire document — efficiency)
   - Each change with an initial slot
   - Optional: link to "view full document for context"
5. Party 1 initials each changed region → moves to party 2
6. Repeat through all parties in `signing_order`
7. When final party initials → document state → `STATUS_COMPLETE`

All original signatures from before the amendment are retained. Each new initial is linked to the amendment via `amendment_id`. Full chain of custody preserved.

#### 7.5.8 Pagination & Page-Bottom Initials

**SA wet-ink convention:** every page of a contract must have initials from every party at the bottom.

Insertable conditions can push content past a page break. The pagination engine must:

1. After every condition addition/edit, re-render the document
2. Detect page breaks
3. If pagination changed (page count increased or content shifted across pages):
   - Insert new initial slots at the bottom of any new page(s)
   - Renumber pages throughout
   - Add new pages to the "changed regions" so parties initial them during the cascade

This is computationally expensive — every change triggers a re-render. Acceptable trade-off given the cascade is already a halt-and-review pattern.

#### 7.5.9 Locked Conditions

The template designer can mark specific conditions as **locked** when inserted. Once a locked condition is in the document, it cannot be struck through or amended by signing parties. Useful for:

- Pre-inserted clauses from CoreX defaults that have legal significance (FICA, voetstoots)
- Agency-specific compliance clauses

Implementation: a condition row has `is_locked` bool. Strikethrough flow refuses to start on locked conditions.

#### 7.5.10 Data Model

**Existing columns to use (no new migration needed):**

- `signature_templates.other_conditions_text` (longText) — already exists per audit §1.11. Stores the free-form Other Conditions text per document.
- `signature_templates.sections_json` — already exists. Supports per-section accept/approve.
- `signature_templates.amendment_status` — already exists. Drives the amendment flows.
- `docuperfect_clauses` — already exists per §7.5.3 (clause library).

**New tables (ES-9):**

```sql
document_conditions                  -- numbered items inside non-other-conditions blocks
  id, signature_template_id (FK)
  block_id (refs template metadata insertable_blocks[].id)
  block_purpose ENUM ('included_items', 'excluded_items', 'custom_named')
  -- NOTE: 'other_conditions' deliberately excluded — that purpose uses signature_templates.other_conditions_text
  condition_number INT              -- auto-numbered within block
  content TEXT
  is_locked BOOL
  added_by_user_id, added_by_party_id
  added_via ENUM ('agent_preparation', 'agent_signing', 'recipient_signing', 'system_default')
  source ENUM ('library', 'custom')
  library_clause_id (nullable, refs docuperfect_clauses.id)
  amendment_id (nullable, refs signature_templates.id of the amendment chain head)
  approved_by_agent_at TIMESTAMP
  approved_by_agent_user_id
  created_at, updated_at, deleted_at  -- soft delete

document_clause_strikethroughs       -- the strikethrough → Other Conditions auto-route audit
  id, signature_template_id (FK)
  clause_ref VARCHAR                 -- e.g. "5.2" — the printed clause being struck through
  clause_original_text TEXT          -- snapshot at strikethrough time
  replacement_text TEXT              -- the replacement that lands in other_conditions_text
  proposed_by_user_id, proposed_by_party_id
  amendment_id                       -- refs the amendment chain head
  status ENUM ('proposed', 'approved', 'rejected', 'superseded')
  approved_by_agent_at TIMESTAMP
  rejected_by_agent_at TIMESTAMP
  rejection_reason TEXT
  created_at, updated_at             -- insert-only after creation; status updates are logged via condition_initials chain

condition_initials                   -- per-party initials on amended regions (insert-only)
  id
  signature_template_id (FK)
  condition_id (nullable, refs document_conditions.id)
  strikethrough_id (nullable, refs document_clause_strikethroughs.id)
  amendment_id (refs amendment chain head)
  party_id                           -- refs signing party in signature_templates.parties_json
  initialed_at TIMESTAMP
  initial_image_path                 -- per-party initial image
  ip_address                         -- audit
  user_agent                         -- audit
  created_at                         -- no updated_at, no deleted_at — insert-only
```

`condition_initials` and `document_clause_strikethroughs` are append-only — full historical record. `document_conditions` is soft-deletable so cancelled conditions can be recovered if needed.

**Why not a new `document_conditions` row per `other_conditions` entry:** because `signature_templates.other_conditions_text` already exists as longText, and Other Conditions is rendered as a single contiguous block in the wet-ink/PDF output. Trying to maintain per-row numbering on a free-form legal text block introduces ordering complexity that wet-ink convention doesn't need. The other block types (`included_items` / `excluded_items` / `custom_named`) ARE list-oriented and benefit from row-per-condition.

#### 7.5.11 Wet-Ink Path (OTPs, Sale Agreements)

OTPs cannot be e-signed but **CAN** be created in DocuPerfect with full insertable conditions support:

1. Agent prepares OTP with all insertable blocks filled
2. Delivery mode = wet-ink (forced — system blocks e-sign per §5)
3. Document is rendered as PDF with:
   - Conditions visible in their blocks
   - Two blank lines after the conditions for manual writing (Adobe-edit-compatible spacing)
   - All initial/signature slots clearly marked
4. Recipient downloads, prints, signs physically
5. Optional: recipient uses Adobe to edit conditions before printing
6. Recipient uploads signed copy via wet-ink portal
7. Agent reviews uploaded copy → approves
8. Next recipient receives invitation
9. Repeat

The strikethrough override flow is **not** available in wet-ink mode — the recipient handles changes manually with pen.

---

## 8. Document Amendments — Initialing Cascade

> **§8 supersedes original ES-3 specification.**

### 8.1 What triggers an amendment

An amendment is triggered by any of:

- New condition added to an insertable block
- Strikethrough override on a printed clause
- Edit to an existing (non-locked) condition

Each triggers `STATUS_AMENDMENT_REVIEW`.

### 8.2 Agent review

Agent is the gatekeeper. Per §7.5.6, agent can:

- **Approve change** → initialing cascade
- **Reject change** → revert, document continues with originals signing
- **Reject document** → terminal `STATUS_REJECTED`

### 8.3 Initialing cascade (not re-sign cascade)

Per §7.5.7. Only changed regions need new initials. Original signatures preserved.

### 8.4 State machine update

| State | Triggered by |
|---|---|
| `STATUS_AMENDMENT_REVIEW` | Any change proposed |
| `STATUS_AMENDMENT_INITIALING` | Agent approves change, all parties must initial |
| `STATUS_REJECTED` | Agent rejects whole document |

**Removed from earlier spec:** `STATUS_AMENDMENT_RESIGNING` — no longer needed. Initialing cascade replaces it.

---

## 9. Editable-at-Signing Fields

Some templates allow specific fields to be edited at signing time by specific parties.

### 9.1 Mechanism

Template metadata:

```yaml
editable_by:
  - field_id: "final_price"
    roles: ["agent", "seller"]
    requires_initial: true
  - field_id: "occupation_date"
    roles: ["seller"]
    requires_initial: false
```

### 9.2 Current state (per audit §16)

Audit finding: Spec said §16 was "OBSOLETE." Verification needed. 7 templates have `editable_by` populated. Whether the end-to-end behavior actually works in the signing flow is untested.

> ✅ ES-5 BUILT & VERIFIED 2026-06-10 (branch AT-12-E-Sig) — editable-at-signing
> works end to end, including the multi-recipient (Seller 1 vs Seller 2) case.
> Fix audit: `.ai/audits/es5-editable-at-signing-fix-2026-06-10.md`.
>
> - **Per-recipient surfacing + gating** (shipped earlier, recipient-loop B1–B3):
>   role blocks duplicated per recipient with `data-recipient-identity={role}_{role_index}`
>   and `data-field`→`{name}__r{role_index}` (`RoleBlockExpansionService.php:1652-1655`);
>   editable gate `stampViewerEditability` (`:565-615`); `role_index` column
>   (migration `2026_06_16_120700`), `role_identity` accessor
>   (`SignatureRequest.php:197`).
> - **Value persistence (this fix):** new
>   `App\Services\Docuperfect\SigningFieldValueProjector::project()` re-lays saved
>   values onto `[data-field]` surfaces, keyed by the **rendered `data-field`** —
>   the SAME `{name}__r{role_index}` identity the loop and SigningSurfaceResolver
>   already produce (no parallel keying). Wired in `SigningController::show()`
>   (after expansion) and `::completeWeb()` (into `merged_html` + the filed
>   `signed_paginated_html`, baking editable inputs to text so the flattened PDF
>   carries them).
> - **Collision fixed:** `authoriseWebFieldWrite` keys `accepted` by the rendered
>   field key (`SigningController.php:1259/1277/1287`); edits persist under
>   `web_template_data['signing_field_values']` (`saveWebFields`), so seller_1 and
>   seller_2 never overwrite each other.
> - **Verification:** php -l clean; `view:clear` OK; Tinker on corex_dev 20/20
>   (surfacing both directions, multi-role editable_by, owner-only negative,
>   no-collision, cross-signer visibility, filed-artifact bake, optional-empty
>   absorbed, non-ASCII fidelity, fail-open). phpunit
>   `EditableAtSigningValuePersistenceTest` ships as the pipeline-gate test diff;
>   it runs under the MySQL schema-snapshot bootstrap on Johan's Windows env
>   (no isolated test DB available on the Linux host).
> - **Not built (by design):** `field_mappings` has no per-field `required` flag
>   for editable text fields, so required-empty is not gated for them
>   (optional-empty is absorbed); a builder-level required setting is a separate
>   enhancement, out of ES-5 scope.

### 9.3 ES-5 — Verification & repair

Walk each of the 7 templates that have `editable_by` populated. For each:

- Create test signing flow
- Verify editable fields surface at signing time for correct roles
- Verify they cannot be edited by wrong roles
- Verify initial requirement enforced
- Verify edits persist into signed document
- Verify audit trail captures the edit

Fix any breaks found.

---

## 10. Document Packs

### 10.1 Concept

A pack is a sequence of templates signed in one flow. Most common: **Sales Mandate Pack**.

### 10.2 Sales Mandate Pack composition

- MDF (Mandatory Disclosure Form) — `template-117`
- HFC Marketing Permission v11 — `template-116` (hand-crafted Blade, multi-tenant)
- Addendum B (Marketing & Communication) — `template-119`

All three signed in sequence, by all parties, in one cohesive flow.

### 10.3 Web Packs table

```sql
web_packs
  id, agency_id, name, slug, description
  is_active, version

web_pack_items
  id, web_pack_id, template_id, order_index, is_required
```

### 10.4 Audit finding

Document pack system fully built. **No further work needed.**

---

## 11. Candidate Practitioner Supervisor Flow

### 11.1 Legal context

Per Property Practitioners Act 22 of 2019, candidate practitioners (formerly "intern agents") operate under supervision. Material agreements require principal co-signature.

### 11.2 System implementation (mostly works per audit §6)

When the dispatching agent's role = `candidate_practitioner`:

- Document state → `STATUS_APPROVAL_PENDING` (cannot dispatch immediately)
- Principal receives notification (email + SMS)
- Principal reviews document on supervisor approval surface
- Approve → document dispatches normally
- Reject → document cancelled with reason logged

### 11.3 Audit finding

Working end-to-end. One minor TODO: the supervisor approval notification email template currently has a placeholder. Needs proper copy. Trivial fix.

### 11.4 ES-7 scope

Email template cleanup. Approve/reject buttons work; the email words just need polish.

---

## 12. AI Template Import 🌟

**This is the transformational feature for V3.** The single biggest accelerator for onboarding new agencies.

> **Scope correction from CDS audit (2026-05-21):** This feature is *largely already built*. Two parallel `.docx` import pipelines exist and use Claude (`claude-sonnet-4-6`) primary with OpenAI `gpt-4o-mini` fallback. ES-6 below is reframed as **consolidation + vision-PDF wiring + Other-Conditions detection** — not a build-from-scratch.

### 12.1 The problem it solves

Today, when an agency joins CoreX:

- They have existing documents (mandates, FICA forms, marketing permissions) in Word or PDF
- These documents must become CoreX templates
- Currently: Johan/Claude manually convert each one — read, identify fields, place signatures, write Blade
- A single agency's document set takes hours

This is the friction blocking agency #2, #3, #4 onboarding.

### 12.2 The vision

Agency principal uploads their existing documents. AI reads each one. AI suggests:

- Template name, type, category
- Field placements (full name, ID number, address, signed-on-date)
- Party roles needed
- Signature positions
- Editable-at-signing recommendations

Johan/Claude review the AI suggestion in an editable preview. Adjust where needed. Save as template. Done.

Onboarding accelerator from "weeks of conversion work" to "an afternoon of review."

### 12.3 Architecture (actual state per CDS audit + ES-6 additions)

**Upload surface (exists):** `/docuperfect/import` ([DocumentImporterController](app/Http/Controllers/Docuperfect/DocumentImporterController.php)) — sidebar entry "Import Document".

**Two pipelines exist today:**

| Pipeline | Route | Service | What it does |
|---|---|---|---|
| **Path A** — Mammoth + AI | `POST /docuperfect/import/parse` → `/import/review` → `/import/generate` | [DocxParserService](app/Services/Docuperfect/DocxParserService.php) + [ImporterAiService](app/Services/Docuperfect/ImporterAiService.php) | Mammoth → HTML; ZIP-extract plain text; **Claude `claude-sonnet-4-6` with OpenAI `gpt-4o-mini` fallback** identifies fields; review/tag UI; generate Blade. Stored in `docuperfect_import_drafts` between parse and generate. |
| **Path B** — CDS marker-aware | `POST /docuperfect/import/cds` → CDS builder | [CdsParserService](app/Services/Docuperfect/CdsParserService.php) | Direct XML parse of `word/document.xml`; detects `@@@@`/`%%%%`/`####` markers; ~50 context-aware identification patterns. Lands in `cds_drafts` and opens the CDS builder. |

**Learning loop:** `field_corrections` table logs every user correction of an AI suggestion (`claude_suggested_key` vs `user_corrected_key` + context). ~1124 LOC of DocumentImporterController already wires this in.

**Build needed for ES-6:**

1. **ES-6.1 — Vision-PDF input** through `AnthropicGateway`. PDF currently NOT accepted (validation: `mimes:docx` only). Add a PDF route that splits the PDF to images, sends multipart to Claude Vision via the gateway. `ClaudeVisionParserService` (308 LOC) exists but is **not wired into the import routes** — it's experimental scaffolding.
2. **ES-6.2 — Extend `ImporterAiService` prompt** to detect `other_conditions` / `included_items` / `excluded_items` blocks in source docs. Output a new `insertable_blocks` array alongside the existing `fields` array.
3. **ES-6.3 — Auto-insert detected `~~~~` markers** during import → CDS draft. The parser emits the marker text in the right position; the builder picks them up via the same `detectMarkers()` pipeline (extended in ES-9 to recognise `~~~~`).
4. **ES-6.4 — UI surfacing** on the review screen: show detected blocks for confirmation before save (alongside the existing field-tagging affordance).
5. **ES-6.5 — Path consolidation decision** — Audit found Path A (Mammoth) and Path B (CDS marker) are both functional but address different agent workflows. Spec deliverable: either deprecate Path A and route everything through the CDS marker path, OR keep both and clearly document when each is used (the index page currently presents both as two side-by-side buttons).

#### 12.3.1 Structured AI output (extended for ES-6)

The prompt continues to return a per-blank field map (existing). It is extended to ALSO return `insertable_blocks`:

   - System prompt (existing): "You are a field assignment specialist for South African real estate documents in the CoreX OS system."
   - Returns structured JSON (existing + new fields):

   ```json
   {
     "template_type": "mandate",
     "category": "sole_mandate",
     "parties": [
       {"role": "seller", "signature_position": "page_3_bottom_left"},
       {"role": "agent", "signature_position": "page_3_bottom_right"}
     ],
     "fields": [
       {"id": "seller_full_name", "type": "text", "position": "page_1_line_2"},
       {"id": "property_address", "type": "address", "position": "page_1_line_5"},
       {"id": "mandate_period_months", "type": "number", "position": "page_2_line_8"},
       {"id": "commission_rate_pct", "type": "decimal", "position": "page_2_line_12"}
     ],
     "requires_fica": true,
     "requires_co_approval": false,
     "delivery_modes": ["esign", "wet_ink", "download"],
     "is_e_sign_blocked": false,
     "legal_warnings": []
   }
   ```

4. **Editable preview** — Johan/Claude see the AI's suggestion overlaid on the original document. Drag-and-drop to adjust field positions. Edit field IDs and types. Mark editable-at-signing fields.
5. **Save as template** — generates the Blade template + metadata, stores in `templates` table, marks as `is_locked = true`

### 12.4 Cost projection

Per template imported:

- Text extraction: free (server-side)
- AI structure analysis: ~R2–5 per call (Sonnet, 2–4k input tokens, 800 output tokens)

An agency onboarding 10 templates: **R20–50 in AI costs.** Versus hours of manual conversion work.

### 12.5 Edge cases

- **Sale agreements / OTPs** — AI must recognise these and flag `is_e_sign_blocked = true`. System enforces wet-ink-only.
- **Multi-page complex contracts** — AI sees all pages, suggests pagination boundaries.
- **Handwritten content** — AI flags for human review; suggests OCR pre-pass.
- **Multiple party variants** (single seller / married couple / company) — AI suggests template variants or conditional sections.

### 12.6 Build sequence (REVISED post-audit)

- **ES-6.1** — Vision-PDF route: accept PDF, page-image split, wire `ClaudeVisionParserService` → `AnthropicGateway` multipart image input.
- **ES-6.2** — Extend `ImporterAiService::fieldPrompt()` to detect `other_conditions` / `included_items` / `excluded_items` regions; output `insertable_blocks` array.
- **ES-6.3** — Auto-emit `~~~~<PURPOSE>~~~~` markers in the parsed text at detected positions; tagging UI picks them up automatically.
- **ES-6.4** — Review UI: surface detected insertable blocks alongside detected fields for human confirmation before "Save".
- **ES-6.5** — Path consolidation decision + documentation (either retire Path A or document both with usage criteria).
- **ES-6.6** — End-to-end test with the HFC document set (validates the end-to-end flow including ES-9 strikethrough → Other Conditions integration).

### 12.7 Future enhancement: AI Template Library

Once enough templates exist across multiple agencies, AI can suggest common template patterns ("Most agencies use a 4-month sole mandate — would you like to start from this template?").

---

## 13. Audit Trail & Compliance

### 13.1 What gets logged

Every action affecting a document or signature:

- Document created (`template_id`, `user_id`, `agency_id`, `contact_id`)
- Document dispatched (recipients, channels)
- Party invitation sent (channel, timestamp, message_id)
- Landing page accessed (token, IP, user_agent)
- OTP requested + verified (success/failure)
- Signature captured (party, position, signature_hash)
- Document amended (DocumentAmendment record)
- Amendment approved/rejected (by which parties)
- Signature superseded by amendment (with link to replacing signature)
- Document completed (timestamp, final_pdf_hash)
- Document filed (auto-filing target)
- Legal block triggered (`LegalBlockAuditLog`)

### 13.2 Immutability

Audit log tables have no `updated_at`, no soft delete, no UPDATE statements. Insert-only. If a record is wrong, a correcting record is inserted alongside (never overwritten).

### 13.3 Retention

7 years per Property Practitioners Act record-keeping requirements. POPIA-compliant retention policy:

- Documents retained 7 years
- Signing audit logs retained 7 years
- Legal block audit log retained indefinitely (defensive)
- AI generation logs retained 1 year

### 13.4 Retrieval

`/admin/audit/document/{document_id}` shows full chronological event stream per document. Exportable as PDF for legal discovery.

---

## 14. Notifications

### 14.1 Channels

- Email (default, always)
- SMS (optional, opt-in by recipient)
- WhatsApp (optional, requires recipient on WhatsApp)

### 14.2 Notification types

| Event | Recipients | Channels |
|---|---|---|
| Signing invitation | Next party in order | Email + SMS |
| Reminder (24h, 48h, 7d) | Pending parties | Email + SMS |
| OTP code | Party signing now | SMS only |
| Amendment proposed | Already-signed parties | Email + SMS |
| Amendment approved/rejected | All parties | Email |
| Document complete | All parties + agent | Email |
| Supervisor approval needed | Principal | Email + SMS |
| Document cancelled | All parties | Email |

### 14.3 Templates

Each notification type has a template per agency (with branding) and per language (English / Afrikaans / Zulu).

### 14.4 Audit finding

Notification system works. No changes needed for V3.

---

## 15. Document Drive Auto-Filing

### 15.1 Filing rules

Template metadata specifies `auto_file_to`:

- `contact_drive` — files to primary contact's drive (most common)
- `property_drive` — files to property's drive
- `agency_drive` — files to agency-level drive (e.g. internal memos)

### 15.2 What gets filed

On `STATUS_COMPLETE`:

- The signed final PDF
- A "Document Pack" wrapper if signed as pack
- Audit summary PDF (optional, configurable)

### 15.3 Audit finding

Auto-filing works. No changes for V3.

---

## 16. Permissions Matrix

| Permission | Super Admin | Admin | Manager | Agent | Candidate | Contact |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| `docs.template.create` | ✓ | ✓ | — | — | — | — |
| `docs.template.edit` | ✓ | ✓ | — | — | — | — |
| `docs.template.delete` | ✓ | — | — | — | — | — |
| `docs.template.import` | ✓ | ✓ | — | — | — | — |
| `docs.document.create` | ✓ | ✓ | ✓ | ✓ | ✓† | — |
| `docs.document.dispatch` | ✓ | ✓ | ✓ | ✓ | ✓† | — |
| `docs.document.cancel` | ✓ | ✓ | ✓ | ✓‡ | — | — |
| `docs.amendment.flag` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `docs.amendment.approve` | ✓ | ✓ | — | — | — | — |
| `docs.supervisor.approve` | ✓ | ✓§ | — | — | — | — |
| `docs.audit.view` | ✓ | ✓ | ✓ | own only | own only | own only |
| `docs.legal_block_log.view` | ✓ | — | — | — | — | — |

† Candidates can create + dispatch but only after supervisor approval
‡ Agents can only cancel their own documents
§ Admin = registered principal

---

## 17. Build Sequence — Phase 1 (Critical Hotfixes)

### ES-1 — Legal Block Hotfix (CRITICAL)

**Scope:** §5 — four-layer defence implementation

- Expand `Template::isEsignBlocked()` name pattern (regex word boundaries)
- Create `legal_block_audit_log` table
- Wire audit logging on every block trigger
- Data remediation: classify 7 OTP templates with proper `template_type`
- Verify wizard JS + server hard block still active

**Effort:** 30–45 min
**Branch:** Hotfix branch direct to main, back-merge to Staging
**Risk:** Zero on live (no live e-signs happening) — pure defensive code

### ES-2 — FICA OrderBy Fix (HIGH)

**Scope:** §6.3 — one-line fix

- Add `->orderByDesc('created_at')->orderByDesc('id')` to both query sites
- Verify no other queries on `FicaSubmission` lack ordering

**Effort:** 15 min
**Branch:** HFC2402 normal flow

### ES-3 — Initialing Cascade (replaces "Cascading Re-Sign")

**Scope:** §7.5.7, §8 — initialing cascade not re-signing

- Add `STATUS_AMENDMENT_INITIALING` state (replaces the earlier `STATUS_AMENDMENT_RESIGNING`)
- Build `SignatureService::requeueAllPartiesForInitialing()`
- Focused initialing view (changed regions only)
- Each initial linked to `amendment_id`
- Original signatures preserved (no supersession of full signatures — only struck-through clauses are superseded)
- Full audit chain

**Effort:** ~half day (was 1 day — `signature_templates.amendment_status` column already exists per audit; amendment routes `docuperfect.signatures.amendmentAction` already mounted)
**Branch:** HFC2402

### ES-4 — Flag System Upgrade (MEDIUM)

**Scope:** §8 — promote flags to first-class amendments

- Every flag (agent / CO / signing party) creates a `DocumentAmendment` row
- Document state → `AMENDMENT_REVIEW` on flag
- Notification cascade fires
- Existing JSON flag field becomes legacy / deprecation path

**Effort:** ~half day
**Branch:** HFC2402 normal flow

### ES-5 — Editable-at-Signing Verification (MEDIUM)

**Scope:** §9 — verify the 7 templates with `editable_by` work end-to-end

- Walk each template's intended editable fields
- Fix any breaks
- Document the working state in template metadata comments

**Effort:** ~half day
**Branch:** HFC2402 normal flow

### ES-9 — Insertable Conditions & Clause Library (NEW, REVISED post-audit)

> ✅ ES-9 RESIDUE STATUS 2026-06-10 (branch AT-12-E-Sig) — fix audit:
> `.ai/audits/es9-residue-fix-2026-06-10.md`. The marker pipeline, the 3 tables,
> the JSON API, the builder picker and the agent review surface were already
> built (per `esign-reconciliation-2026-06-10.md` §2). The three documented
> residue gaps are now resolved as follows:
> - **Clause schema (category + is_system): BUILT.** Migration
>   `2026_06_24_000000_add_category_and_is_system_to_docuperfect_clauses.php`
>   (`category` string nullable indexed; `is_system` boolean default false);
>   `Clause::CATEGORIES` + `normaliseCategory()` (`app/Models/Docuperfect/Clause.php`).
>   23 existing rows unaffected (verified up/rollback/re-apply on corex_dev).
> - **System-default seed: BUILT.** `database/seeders/DocuperfectSystemClauseSeeder.php`
>   (22 SA-correct clauses, is_system+is_global, categorised, bracket tokens for
>   variables), registered in `DatabaseSeeder`. Idempotent + soft-delete-aware
>   (matches `(name,is_system)` incl. trashed) — double-run proven: 23 system
>   clauses both runs, 0 duplicates, all 7 categories populated.
>   `ClauseController::listJson` now returns `category`/`category_label`/`is_system`;
>   `store`/`update` accept+validate `category`; builder picker
>   (`cds-builder.blade.php`) and clause manager (`clauses/index.blade.php`) group
>   by category. (Recipient "Add condition" is free-text by design — 1B.6 FIX1 —
>   so grouping is builder/agent-side, the only library-consuming surface.)
> - **9.5 Pagination recalc + new-page initial slots: DEFERRED (legally grounded).**
>   Pagination is browser-pixel-measured (client-only); the sole server page count
>   is a heavy Puppeteer shell-out (`WebTemplatePdfService.php:120,261-287`); the
>   focused initialing view has no page model. A precise synchronous "+N pages" and
>   per-page-initial correctness on a re-paginated *signed mandate* (legally
>   significant) cannot be verified without a real browser PDF-flatten, which is
>   unavailable in this environment. Deferred with a concrete proper-fix proposal
>   (re-trigger the existing `paginateDocument`+§19.4 re-anchor; bring a4-page-styles
>   into the initialing view; source "+N pages" from a real render). Correctness in
>   the normal flow is already preserved by the existing client re-pagination at the
>   next render. Full reasoning: `.ai/audits/es9-residue-fix-2026-06-10.md` §3.

**Scope:** §7.5 — complete implementation. **Schema is mostly already in place** — `signature_templates.other_conditions_text` exists, `docuperfect_clauses` table + CRUD + JSON API exists, marker pipeline already recognises three of four markers.

- **9.1: CDS builder "Insert clause" affordance.** Slash-command or right-pane picker in the CDS builder that queries `GET /docuperfect/api/clauses` and inserts the clause text at the cursor as a regular paragraph (NOT as a tag). The JSON API is ready; this is pure UI work.
- **9.2: Signing-experience clause picker.** Modal launched from the "Add condition" affordance on an `other_conditions` block. Same JSON endpoint. Used by parties who add inline conditions during signing.
- **9.3: Strikethrough override flow.** Click clause → modal → auto-route to Other Conditions block. Writes to `signature_templates.other_conditions_text` (existing column) and creates `document_clause_strikethroughs` row (new table, this prompt).
- **9.4: Other-Conditions detection in import.** Overlaps with ES-6.2 — `ImporterAiService` prompt extended to flag `other_conditions` regions in the source doc and emit `~~~~OTHER_CONDITIONS~~~~` markers.
- **9.5: Pagination recalc + new-page initial slot insertion.** After every condition addition/edit, re-render document, detect page breaks, add new initial slots at the bottom of any new pages, renumber pages.
- **9.6: Agent review surface.** Diff view, approve/reject/reject-document. Builds on existing `signature_templates.amendment_status` column.

Schema work (one migration set):

- `~{4,}([A-Z_]+(?::[^~]+)?)~{4,}` regex added to `CdsParserService::detectMarkers()` → `insertable_block` placeholder type.
- New tables: `document_conditions` (non-other-conditions block items), `document_clause_strikethroughs` (audit), `condition_initials` (append-only initials trail).
- `docuperfect_clauses` schema extension: add `category` column for grouping the picker output; optional `is_system` flag for CoreX-shipped defaults.
- System-default library seed: ~20 common SA-real-estate clauses.

**Effort:** ~1–1.5 days (was 2–3 days — clause library data layer COMPLETE per audit §1.6, `other_conditions_text` column already exists per audit §1.11, marker pipeline architecture already exists per audit §4.2, only adding one regex + new tables + UI affordances)
**Branch:** HFC2402

### Updated build order (revised post CDS audit)

1. **ES-1** (legal block hotfix) — 30 min
2. **ES-2** (FICA orderBy) — 5 min
3. **ES-3** (initialing cascade — replaces re-sign cascade) — half day (was 1 day; `amendment_status` already exists)
4. **ES-9** (Other Conditions / clause library / strikethrough) — 1–1.5 days (was 2–3 days; data layer done, marker pipeline done, `other_conditions_text` exists)
5. **ES-4** (flag system upgrade) — half day
6. **ES-5** (editable-at-signing verification) — half day
7. **ES-6** (AI Template Import consolidation + vision PDF + Other-Conditions detection) — ~1 day (was 2–3 days; two paths already built, AI engine already wired)
8. **ES-7** (supervisor email polish) — 15 min
9. **ES-8** (template library expansion) — ongoing

**Total Phase 1 + 2 build:** ~3–4 days of actual work (was 6–8 days pre-audit).

---

## 18. Build Sequence — Phase 2 (Transformational)

### ES-6 — AI Template Import (CONSOLIDATION + POLISH)

**Scope:** §12 — consolidation of existing two-path import + vision PDF + Other-Conditions detection. Reframed post-audit (was "build from scratch").

- 6 sub-phases per §12.6 (revised)
- Existing services to reference by name: `ImporterAiService`, `DocxParserService`, `CdsParserService`, `ClaudeVisionParserService`, `DocumentTemplateGenerator`. Existing tables: `docuperfect_import_drafts`, `cds_drafts`, `field_corrections`. Existing routes: `/import/parse`, `/import/cds`, `/import/review`, `/import/generate`.

**Effort:** ~1 day (was 2–3 days)
**Branch:** HFC2402 normal flow

### ES-7 — Supervisor Approval Email Polish (TRIVIAL)

**Scope:** §11.3–§11.4 — email copy cleanup

- Write proper email template
- Test rendering

**Effort:** 15 min
**Branch:** HFC2402 normal flow

### ES-8 — Template Library Expansion (ONGOING)

**Scope:** Build out the hand-crafted template set:

- More mandate variants (open, sole, exclusive multi-property, rental)
- More marketing permissions
- More addenda
- Disclosure forms (defects, electrical compliance, water/septic)
- Lease agreements

**Effort:** ongoing, ~half day per template
**Branch:** HFC2402 normal flow

---

## 19. Build Sequence — Phase 3 (Polish)

To be defined post-Phase 2 build. Identified during build:

- UX improvements
- Performance tuning
- Additional notification channels
- Reporting / analytics on document usage

---

## 20. Acceptance Criteria — E-Sign V3 Complete

V3 ships when:

- ✓ ES-1 legal block hotfix deployed to live, audit log capturing blocks
- ✓ ES-2 FICA orderBy fix deployed
- ✓ ES-3 initialing cascade working end-to-end (replaces the earlier re-sign cascade), including audit trail
- ✓ ES-9 insertable conditions + clause library + strikethrough override flow working end-to-end on at least one mandate template
- ✓ ES-4 flag system upgraded, every flag creates an amendment
- ✓ ES-5 editable-at-signing verified on all 7 templates
- ✓ ES-6 AI Template Import functional, used to import at least one full agency document set
- ✓ ES-7 supervisor email polished
- ✓ All audit findings (N1–N5) resolved or explicitly deferred with documented reason
- ✓ Documentation in this spec reflects all built code
- ✓ Smoke test in `tests/Feature/ESign/EsignV3SmokeTest.php` passing

---

## 21. Out of Scope (For Future V4+)

- Multi-language template authoring (currently English; Afrikaans/Zulu later)
- Biometric signature capture (camera-based)
- Blockchain-anchored audit trail (defensive overkill for current threat model)
- Integration with external CRMs (CoreX-native is the model)
- Template versioning with branch/merge (currently linear versions)
- Bulk signing flows (50 leases at once for landlord) — single-document is the V3 model

---

## 22. Reconciliation with CDS Audit (2026-05-21)

This section logs the changes made to the V3 spec on 2026-05-21 after the CDS template-system audit revealed that significant portions of the spec were misaligned with what was already built in code.

**Audit reference:** [.ai/audits/cds-template-system-audit-2026-05-21.md](.ai/audits/cds-template-system-audit-2026-05-21.md)

### 22.1 Column-name corrections applied

| Old (spec invention) | New (actual schema) | Section(s) affected |
|---|---|---|
| `transaction_type` | `category` enum(sales, rentals) | §4.2 |
| `template_type` ENUM | `document_type_id` FK + `template_type` free-form string | §4.2, §5.1 |
| `delivery_modes` JSON | `allowed_delivery_modes` CSV string | §4.2 |
| `security_level` | `security_tier` | §4.2 |
| (none) | `party_mode` (shared / per_party) | §4.2 |
| `parties[]` in metadata JSON | `signing_parties` JSON column on table | §4.2, §4.3 |
| `templates.agency_id` | docuperfect_templates has NO agency_id — scope via `is_global` + `docuperfect_template_branches` | §4.2 |
| `clause_library` (new table) | `docuperfect_clauses` (already exists, 21 rows live) | §7.5.3, §17 ES-9 |
| New `document_conditions(other_conditions)` rows | `signature_templates.other_conditions_text` (longText, already exists) | §7.5.10 |

### 22.2 Marker syntax reconciled (§7.5.2)

- `@@@@` / `%%%%` / `####` — already implemented in `CdsParserService::detectMarkers()` per audit §4.2.
- `~~~~<PURPOSE>~~~~` — NEW, with explicit case-sensitive purpose tokens (`OTHER_CONDITIONS`, `INCLUDED_ITEMS`, `EXCLUDED_ITEMS`, `CUSTOM:<label>`).
- Regex: `~{4,}([A-Z_]+(?::[^~]+)?)~{4,}`.

### 22.3 ES-6 reframed from "build" to "consolidate" (§12, §17, §18)

Audit finding: two AI-assisted import pipelines already exist —
- **Path A** (`/import/parse`): `DocxParserService` + `ImporterAiService` (Claude Sonnet 4.6 primary, OpenAI gpt-4o-mini fallback) with `field_corrections` learning loop.
- **Path B** (`/import/cds`): `CdsParserService` with marker-aware XML parsing.

ES-6 sub-phases revised to: vision-PDF wiring, Other-Conditions detection in the prompt, `~~~~` marker emission, review-UI surfacing, path-consolidation decision, end-to-end test.

**Estimate revised from 2–3 days to ~1 day.**

### 22.4 ES-9 split into smaller sub-tasks (§17)

What's already done (data layer): `docuperfect_clauses` table, full CRUD, JSON API at `/docuperfect/api/clauses`, 21 rows live, soft delete in place.

What's NEW for ES-9:
- 9.1 CDS builder "Insert clause" UI affordance
- 9.2 Signing-experience clause picker modal
- 9.3 Strikethrough override flow (auto-route to `other_conditions_text`)
- 9.4 Other-Conditions detection in import (overlaps with ES-6.2)
- 9.5 Pagination recalc + new-page initial slots
- 9.6 Agent review surface (diff + approve/reject)

Schema additions: `document_conditions` (for non-other-conditions block items), `document_clause_strikethroughs` (audit), `condition_initials` (append-only), plus `category` column on existing `docuperfect_clauses`.

**Estimate revised from 2–3 days to 1–1.5 days.**

### 22.5 ES-3 estimate revised (§17)

Audit revealed `signature_templates.amendment_status` column already exists and amendment routes (`docuperfect.signatures.amendmentAction`) are already mounted.

**Estimate revised from 1 day to half day.**

### 22.6 Resolver pattern formalised (§4.3)

The wizard MUST resolve party labels through `Template::mapSigningPartyKeys($template->signing_parties, $template->isSalesDocument())` — no hard-coded "Seller" / "Buyer" / "Lessor" / "Lessee" anywhere in view, controller, or service code. The resolver chain is:

1. `signing_parties` per template (generic role tokens)
2. `category` field (sales vs rentals)
3. Falls through to name heuristic only as last resort

### 22.7 Total Phase 1+2 estimate

| Phase | Before audit | After audit |
|---|---|---|
| ES-1 + ES-2 (hotfixes) | ~45 min | ~45 min (unchanged) |
| ES-3 (initialing cascade) | 1 day | half day |
| ES-9 (insertables + clause + strikethrough) | 2–3 days | 1–1.5 days |
| ES-4 + ES-5 | 1 day | 1 day (unchanged) |
| ES-6 (AI import) | 2–3 days | ~1 day |
| **Total** | **6–8 days** | **~3–4 days** |

### 22.8 Phase 1B.5 — signing-view embeds shipped

Phase 1B left the recipient-side experience as a deferral: the backend API, the modal partial, and the agent review surface all shipped, but the partial was not yet embedded into the multi-file signing-view architecture.

Phase 1B.5 closes the loop:

- §7.5.4 "Adding a condition" — operational at signing time. Each `~~~~MARKER~~~~` in the document body renders an in-line "+ Add condition" button via `App\Services\Docuperfect\InsertableBlockRenderer`. The button dispatches a `CustomEvent` consumed by the embedded `add-condition-modal` partial, which submits to a new `POST /sign/{token}/conditions` endpoint.
- §7.5.5 "Strikethrough override" — operational. The `override-modal` partial scans the document body on load, wraps numbered clauses with click handlers, and routes through a new `POST /sign/{token}/strikethroughs` endpoint that creates a paired `DocumentCondition` (in the other_conditions block) + `DocumentClauseStrikethrough` row linked by `amendment_id`. Fails fast with a 400 if the template has no `other_conditions` block declared.
- §7.5.7 "Focused initialing view" — operational. `SigningController::show()` now detects `status = STATUS_AMENDMENT_INITIALING` and switches to the new `initialing.blade.php` view, which shows only the changed regions across all approved amendments + per-item initial slots. A new `POST /sign/{token}/initial-amendments` endpoint creates immutable `ConditionInitial` rows, advances party-by-party, and resolves the cascade back to `STATUS_SIGNING` once every party has initialed every accepted amendment.
- §7.5.4 "Legacy textarea bridge" — operational. Wizard Step 5 textarea writes to both the legacy `other_conditions_text` longText column AND structured `document_conditions` rows via `App\Services\Docuperfect\LegacyOtherConditionsBridge`. Idempotent via a per-row `custom_label = '_bridge:<sha1>'` signature; recipient-added rows are never touched. One-shot backfill migration `2026_05_22_120001` scans existing docs with non-empty `other_conditions_text` and zero structured rows.

### 22.9 Phase 1B.6 — recipient flow corrected

Six corrections to the Phase 1B.5 recipient experience:

- **FIX 1 — No clause library on recipient side.** The Add Condition modal is now a single-purpose free-text capture. The `source` field is server-side coerced to `'custom'`, `library_clause_id` to `null` for any recipient call. Agent-side library access remains.
- **FIX 2 — Flag UI replaces strikethrough abstraction.** Phase 1B.5's `override-modal` partial deleted from sign view; `POST /sign/{token}/strikethroughs` now returns `410 Gone` with a pointer to the new flow. New `POST /sign/{token}/flag-clause` endpoint creates a `DocumentAmendment` row with `amendment_type = 'flag_raised'` (existing Phase 2 ES-4 enum) + `flag_origin = 'signing_party'` + `flag_clause_ref` + `flag_reason` (combined suggested change + optional reason). The legacy clause-flag icon UI in `_initClauseFlagging()` now dispatches `open-flag-clause-modal` instead of injecting an inline text input.
- **FIX 3 — Numbering on insertable blocks.** `InsertableBlockRenderer` emits `<ol style="list-style: decimal outside">` for `auto_number = true` blocks (was emitting `<ol class="conditions-list">` with no list-style — document CSS was resetting it). Non-numbered blocks use `<ul style="list-style: none">`.
- **FIX 4 — Optional clause link on Add Condition.** Migration `2026_05_22_140001_add_relates_to_clause_ref_to_conditions` adds `document_conditions.relates_to_clause_ref` (nullable, indexed). `SigningController::extractNumberedClauses($html)` parses the document body for `(\d+(?:\.\d+)*)` clause refs, skipping anything inside `.insertable-block` scopes; results pass to the view as `$numberedClauses`. The Add Condition modal renders a dropdown picker populated from this array; the renderer surfaces a blue "Relates to clause N" badge with a scroll-to-clause click handler for filed conditions.
- **FIX 5 — Signature preservation.** `SigningController::show()` now passes `$partyAlreadySigned` (derived from `SignatureRequest::completed_at` or `STATUS_COMPLETED`) and `$inAmendmentInitialing` (template status check) to the view. Two new banner blocks at the top of the sign view explain the state: an amber "amendment was added — please initial below" banner with a link to the focused initialing view when both flags are true, and a green "signed and locked" indicator when the party has signed but no amendment cycle is in progress. Original signatures continue to render through the existing capture pipeline; the banners explain why affordances no longer pull the recipient toward re-signing.
- **FIX 6 — Flag persistence across refreshes.** `webClauseFlaggedItems` Alpine state is now seeded from `$persistedClauseFlags` (extracted from `web_template_data.clause_flags`) on page render. `_initClauseFlagging()` re-paints the `clause-flagged` class on clauses found in the seeded set. `SigningController::flagClause()` writes through to `web_template_data.clause_flags` immediately on submit (no longer waits for `signComplete`), so a refresh restores both the visible flag treatment and the underlying amendment row reference.

Phase 1B.5 data tables retained as-is: `document_clause_strikethroughs` is no longer written by any recipient code path, but the schema is preserved for future agent-side strikethrough creation.

### 22.10 Phase 1B.8 — Add Condition dropdown removed

Removed the "Does this relate to an existing clause? (optional)" dropdown from the recipient Add Condition modal. Recipients now provide free-text conditions only. The `document_conditions.relates_to_clause_ref` column is retained — existing rows keep their values and the `InsertableBlockRenderer` continues to render the "Relates to clause N" badge for legacy data. Recipient writes no longer populate the column. All other Phase 1B.5/1B.6/1B.7 functionality preserved.

### 22.11 Phase 1B.9 — three critical fixes

**FIX 3 (CRITICAL) — Signature reset on Add Condition.** The `add-condition-modal` partial called `location.reload()` after a successful condition POST; that wiped every captured signature, initial, and field on the page, forcing the recipient to redo all their signing work. Fix: `SigningController::addCondition()` now returns `rendered_row` (server-rendered `<li>` HTML for the new condition) in its 201 JSON response. The modal appends the row into the matching `.insertable-block`'s `<ol>` in place — no reload, no state loss. The original signature data, initials, and field values stay intact because the page is never re-rendered. Also `SigningController::show()` view-switch on `STATUS_AMENDMENT_INITIALING` (Phase 1B.5) routes returning signers to the focused initialing surface even when their `SignatureRequest::status` is `COMPLETED`. Together these mean the user-reported reset bug is eliminated.

**FIX 2 — Apply-to-all is agent-only.** Recipients must initial each page individually for legal informed-consent reasons (each initial = explicit affirmation per page). The "Apply to All Pages?" modal is now gated on `isAgent`, computed in `SigningController::show()` as `auth()->user()->hasPermission('manage_documents') || $signingRequest->party_role === 'agent'`. Both trigger sites (PDF marker path + web template initials path) check `this.isAgent` before flipping `showApplyAll = true` / `showInitialApplyAll = true`. Agent self-signing still gets the productivity affordance.

**FIX 1 — Flag undo with consent-gated removal.**
- **Pre-completion (free undo):** new `DELETE /sign/{token}/flag/{clauseRef}` endpoint allows the recipient to retract their own flag while `SignatureRequest::completed_at` is still NULL. Returns 409 with a pointer to the consent flow once signing has completed. UI: an "✕" affordance appears beside the flag icon on flagged clauses for pre-completion parties.
- **Post-completion (consent-gated):** new `flag_removal_requests` table + `FlagRemovalRequest` model + `FlagRemovalController` with three endpoints — `POST /docuperfect/flags/{amendment}/request-removal` (agent, auth-gated), `GET /flag-removal/{token}` (public, token-authenticated consent screen), `POST /flag-removal/{token}/consent` (recipient submits authorise/reject). Tokens are 64-char, 14-day TTL. Email send via the existing `SigningRequestMail` transport with a composed body. On `consented`: the amendment is soft-deleted + the clause-flag JSON entry is scrubbed. On `rejected`: both stay; agent is notified. Original flag + consent decision both retained in the audit chain forever.
- Agent-side button surfaces on the amendment review surface for flag-type amendments raised by a party with `completed_at IS NOT NULL`.

E-Sign V3 remaining backlog (non-blocking): Phase 2.1 multi-role `editable_by` enforcement, V1.5 deferrals (wet-ink rendering + pagination recalc), and the ES-1 cherry-pick to `main` + Staging back-merge pending Johan's per-push authorisation.

---

## 23. Open Questions

None at draft time. To be added as build surfaces them.

---

*End of E-Sign V3 Complete Spec.*
