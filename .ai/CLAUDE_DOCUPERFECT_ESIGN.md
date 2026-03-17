# CLAUDE_DOCUPERFECT_ESIGN.md — E-Signature System Spec

> Documents the ACTUAL e-sign system as built and working in code.
> Last verified: 2026-03-17

---

## 1. OVERVIEW

The CoreX e-signature system enables agents to create legally binding electronic documents, sign them in-app, and send them sequentially to external parties (tenants, landlords, buyers, sellers) for signing via token-based email links. It supports two document rendering paths (PDF page images and web templates), two signing methods (electronic draw/type and wet-ink upload), sequential signing order enforcement, agent approval gates between parties, document hash verification, identity verification for external signers, and immutable audit logging of every action. The system creates a `Document` record with field values, a `SignatureTemplate` to manage the signing workflow, `SignatureRequest` records per signer, `SignatureMarker` positions on pages, and `Signature` captures per marker.

---

## 2. TWO TEMPLATE TYPES

### PDF Templates
- Uploaded as PDF files, split into page images stored in `storage/docuperfect/templates/{id}/page-{n}.png`
- `render_type = 'pdf'` (or null — PDF is the default)
- `page_count` reflects actual PDF pages
- Fields defined in `fields_json` with `type` key (placeholder, date, signature, initial, selection, tick, strikethrough, condition, clause)
- Page images displayed directly; signature markers positioned via x/y coordinates on page images
- Field values and signatures are "flattened" (baked) onto page images by `DocumentFlattener` before sending to next party

### Web Templates
- HTML/Blade templates stored as `.blade.php` files in `resources/views/docuperfect/web-templates/`
- `render_type = 'web'`, `blade_view` points to the Blade view path (dot notation)
- `page_count = 1` (but no page image files exist — the template is rendered as HTML)
- Fields defined in `fields_json` with `tag_type` key (input, date, signature, initial, selection, tick) — NOT `type`
- May also have `party` instead of `assignedTo`, and `mapping_type` / `field_name` keys
- Rendered via `view($template->blade_view, $data)->render()` — body HTML extracted, styles prepended
- Can be grouped into **Web Packs** (`WebPack` model) — multiple templates merged into one document with page breaks

### Field Normalisation (Critical)
Web template fields use different keys than what the wizard JS expects. `ESignWizardController::normalizeFieldForWizard()` bridges this gap:

| Web field key | Mapped to | Values |
|---------------|-----------|--------|
| `tag_type` | `type` | input→placeholder, date→date, signature→signature, initial→initial, selection→selection, tick→tick |
| `party` | `assignedTo` | Only when `assignedTo` is empty |

This normalisation runs in both `showStep()` (for step 5 display) and `prepareSigning()` (for document creation).

The wizard's `fieldInputType()` JS function also has a fallback: `f.type || f.tag_type || 'placeholder'`.

---

## 3. E-SIGN WIZARD FLOW (6 Steps)

### Step 1 — Choose Template
- **Route:** `GET /esign/create` → `ESignWizardController@create`
- **View:** `docuperfect.esign.wizard` (all steps share this view, Alpine.js manages step visibility)
- **User does:** Picks a template from the list (filtered by active, visible to user, has pages or is web type). Can also select a Web Pack or resume a saved draft.
- **Data saved:** `store()` creates a `Flow` record with `type='esign'`, copies template's `fields_json` into `step_data.fields`. For packs, merges fields from all templates with `_pack_template_id` prefix.
- **Advances to:** Step 2 via `POST /esign/store` → `ESignWizardController@store`

### Step 2 — Select Property
- **Route:** `GET /esign/{flow}/step/2` → `ESignWizardController@showStep`
- **User does:** Searches and selects a property (searches both `properties` and `rental_properties` tables). Property data (address, rental amount, deposit, commission) is stored.
- **Data saved:** `step_data.property` — property_id, address, suburb, rental_amount, deposit, commission, `_property_source` (properties or rental_properties). Flow's `property_id` linked if source is `properties` table.
- **API:** `GET /esign/api/properties?q=` → `searchProperties()`

### Step 3 — Add Recipients
- **Route:** `GET /esign/{flow}/step/3` → `ESignWizardController@showStep`
- **User does:** Adds signing parties — agent (pre-filled), tenant/lessee, landlord/lessor, buyer, seller, witness. Can search existing contacts for auto-fill (including bank details).
- **Data saved:** `step_data.recipients.recipients[]` — each with role, name, email, id_number, cell, address, bank details, `_contact_id`. Flow's `contact_id` linked to first non-agent recipient.
- **Auto-populate:** If property has linked contacts (via pivot), they are auto-added as recipients.
- **API:** `GET /esign/api/contacts?q=&role=` → `searchContacts()`

### Step 4 — Deal Details
- **Route:** `GET /esign/{flow}/step/4` → `ESignWizardController@showStep`
- **User does:** Fills in deal-specific values: monthly rental, deposit, commission %, marketing fee, lease start/end dates. Also manual named fields (source_type='manual' in docuperfect_named_fields).
- **Data saved:** `step_data.details` — monthly_rental, deposit, commission, marketing_fee, lease dates. Pre-filled from property record if available.

### Step 5 — Fill & Review
- **Route:** `GET /esign/{flow}/step/5` → `ESignWizardController@showStep`
- **User does:** Reviews all document fields, fills any remaining values, can reassign fields to different parties. For web templates, sees a live HTML preview. For PDF templates, sees page images with field overlays.
- **Data processing:**
  - Fields auto-filled via `autoFillFields()` using named field source mappings (property, contact, agent, manual data)
  - Web template values resolved via `WebTemplateDataService::resolve()`
  - Fields split into `creatorFields` (agent/creator) and `signerFields` (tenant/landlord/etc.)
  - System-assigned and signature-type fields are excluded from the form
- **Data saved:** `step_data.fill_review.fieldValues` (field ID → value) and `step_data.fill_review.partyOverrides` (field ID → party)
- **Autosave:** `POST /esign/{flow}/autosave-fields` silently saves field values without full validation
- **API:** `GET /esign/api/template/{id}/pages?flow_id=` → `templatePages()` returns rendered HTML (web) or page image URLs (PDF)

### Step 6 — Sign & Send
- **Route:** `GET /esign/{flow}/step/6` → `ESignWizardController@showStep`
- **User does:** Reviews signing order, can drag-reorder recipients, toggle "skip email" for in-person signing. Clicks "Prepare & Sign" to create the document.
- **Data saved:** `step_data.signing_setup[]` — each entry has role, name, email, signing_order, skipEmail flag.
- **Action:** `POST /esign/{flow}/prepare-signing` → `ESignWizardController@prepareSigning()` which:
  1. Creates a `Document` record with fields_json, web_template_data, property link
  2. Creates a `SignatureTemplate` with parties_json, signing_order_json, document_hash
  3. Creates `SignatureRequest` records — agent first (signing_order=1, status=pending), then recipients (status=waiting)
  4. Converts template signature zones to markers (or creates from fields_json, or creates defaults)
  5. Sets template status to `ready`
  6. Stores `esign_wizard_flow_id` in session
  7. Redirects to `docuperfect.signatures.setup` for marker placement + agent signing

---

## 4. SIGNING FLOW (After Wizard)

### 4.1 Signature Setup (Agent — in-app)
- **Route:** `GET /documents/{document}/signatures/setup` → `SignatureController@setup`
- **View:** `docuperfect.signatures.setup`
- Agent sees the document (page images for PDF, rendered HTML for web templates) with existing markers
- Agent can add/move/remove signature markers (signature, initial, date, text types)
- For wizard flows, parties are pre-configured; for standalone, agent configures parties here
- **Save markers:** `POST /documents/{document}/signatures/markers` → `saveMarkers()`
- **Save parties:** `POST /documents/{document}/signatures/parties` → `saveParties()`

### 4.2 Agent Signs (in-app)
- **Route:** `GET /documents/{document}/sign` → `SignatureController@sign`
- **View:** `docuperfect.signatures.sign`
- Agent sees their assigned markers and fills/signs each one
- Can edit agent-assigned fields inline
- **Capture:** `POST /documents/{document}/sign/{marker}` → `captureSignature()`
- **Save fields:** `POST /documents/{document}/save-agent-fields` or `save-agent-web-fields`
- **Complete:** `POST /documents/{document}/sign-complete` → `signComplete()`
  - Validates all agent markers signed and required agent fields completed
  - Flattens fields + signatures onto page images (immutable from this point for external signers)
  - Marks agent request as completed
  - Sets template status to awaiting next party
  - If wizard flow: auto-sends to next party and redirects to `signingComplete` page
  - If standalone: redirects to `sendConfirmation` page

### 4.3 Send to External Party
- **Route (confirmation page):** `GET /documents/{document}/send-confirmation` → `sendConfirmation()`
- **Route (send action):** `POST /documents/{document}/send-for-signature` → `sendForSignature()`
- Validates field completion and that every non-agent party has at least one marker
- Calls `SignatureService::sendForSigning()` which emails a token-based signing link
- Template status transitions to `awaiting_tenant` / `awaiting_landlord` / `awaiting_buyer` / `awaiting_seller`

### 4.4 External Signer Signs (token-based, no auth)
- **Route:** `GET /sign/{token}` → `SigningController@show`
- **View:** `docuperfect.signatures.external.sign`
- Token looked up from `signature_requests.token`; expires after `token_expires_at`
- **Identity verification gate:** If signer has `signer_id_number`, must verify before proceeding (session-based: `signing_verified_{token}`)
- Signer can choose: electronic signing or wet-ink upload
- **Electronic:** Signs markers via draw pad or typed text → `POST /sign/{token}/capture/{marker}` → `SigningController@capture()`
  - Each signature immediately flattened onto page images
  - Soft document hash check (logs warning on mismatch but doesn't block)
- **Wet-ink:** Downloads annotated PDF for printing → `GET /sign/{token}/download`; uploads scanned copies → `POST /sign/{token}/upload`
- **Save fields:** `POST /sign/{token}/save-fields` (PDF fields) or `POST /sign/{token}/save-web-fields` (web template fields)
  - Only allows updating fields assigned to this signer's party role
  - Role alias mapping: lessor↔landlord, lessee↔tenant
- **Complete:** `POST /sign/{token}/complete` → validates required fields, marks request completed, flattens signer fields
- **Decline:** `POST /sign/{token}/decline` with optional reason

### 4.5 Agent Approval Gate
After each external party completes signing, the template moves to `pending_agent_approval` status. The agent must review and approve before the document advances to the next party.

- **Route:** `GET /documents/{document}/signatures/review` → `SignatureController@review`
- **Route:** `POST /documents/{document}/signatures/approve-and-advance` → `SignatureController@approveAndAdvance`
- `SignatureService::approveAndAdvance()`:
  - Finds next waiting request by signing_order
  - Recalculates document hash
  - Sends signing link to next party
  - If no more waiting requests → calls `completeDocument()`

### 4.6 Wet-Ink Review
For wet-ink uploads, agent reviews the scanned document before approving.

- **Route:** `GET /documents/{document}/signatures/inspect/{signingRequest}` → `wetInkReview()`
- **Route:** `POST /documents/{document}/signatures/inspect/{signingRequest}/decision` → `wetInkDecision()`
- Agent can approve (advances to next party) or reject (signer gets re-upload notification)

### 4.7 Document Completion
When all parties have signed (all requests completed):
- `SignatureService::completeDocument()` is called
- Template status → `completed`
- Signed PDF generated and stored at `signed_pdf_path`
- Email sent to all parties with download link
- `SignatureAuditLog` entry: `document_completed`

### 4.8 Signed Document Download (external, token-based)
- **Route:** `GET /documents/download/{token}` → `SigningController@downloadPage`
- Identity verification required before download
- **Route:** `GET /documents/download/{token}/file` → `downloadSignedFile()`

---

## 5. SIGNING PARTIES

### Configuration
Parties are stored in `SignatureTemplate.parties_json` as an array of objects:
```json
[
  {"role": "agent", "name": "Johan", "email": "...", "id_number": null},
  {"role": "tenant", "name": "Koos", "email": "...", "id_number": "9001015009087"},
  {"role": "landlord", "name": "Pieter", "email": "...", "id_number": "8505025001082"}
]
```

### Party Roles
| Role | Used in | Aliases |
|------|---------|---------|
| `agent` | All documents | creator, user |
| `tenant` | Rental documents | lessee |
| `landlord` | Rental documents | lessor |
| `buyer` | Sales documents | — |
| `seller` | Sales documents | — |
| `witness` | Optional, per-party | tenant_witness, landlord_witness |

### Role Alias Mapping
The system maps between wizard roles and DB/field roles:
- `landlord` ↔ `lessor` (contact type = "Lessor", field assignedTo may be "lessor")
- `tenant` ↔ `lessee` (contact type = "Lessee", field assignedTo may be "lessee")
- Web template fields use `party` key (agent, lessee, lessor); wizard uses `assignedTo`

### Multi-Person Per Role (Co-owners)
Multiple people can share the same role (e.g., 2 landlords). Each gets their own `SignatureRequest` with a unique `signing_order`. Markers can be assigned to a specific co-owner via `assigned_email`. All co-owners for a role must complete before advancing.

### Signing Order
Stored in `SignatureTemplate.signing_order_json` as an ordered array: `["agent", "tenant", "landlord"]`.
- Agent always signs first (signing_order = 1)
- Subsequent parties sign sequentially in the configured order
- `SignatureRequest.signing_order` column tracks per-request ordering
- Drag-reorder on wizard step 6 lets agents customize the order

---

## 6. KEY MODELS AND TABLES

### Document
- **Table:** `docuperfect_documents`
- **Key columns:** `name`, `template_id` (FK to docuperfect_templates), `fields_json` (array — field values for this document instance), `owner_id` (FK to users), `branch_id`, `property_address`, `property_id`, `document_type` (string slug), `web_template_data` (array — resolved template variables + merged_html for web/pack), `pack_instance_id`, `archived_at`, `lease_expiry_date`
- **Relationships:** owner (User), branch, template (Template), signatureTemplate (hasOne), property (RentalProperty)
- **Casts:** fields_json→array, web_template_data→array, archived_at→datetime, lease_expiry_date→date

### SignatureTemplate
- **Table:** `signature_templates`
- **Key columns:** `document_id` (FK), `document_hash` (SHA-256 of fields), `status`, `parties_json` (array), `signing_order_json` (array), `created_by` (FK to users), `completed_at`, `signed_pdf_path`, `signed_pdf_client_path`, `flattened_pages_json` (array — paths to flattened page images), `rejected_at`, `rejection_reason`, `rejected_by`
- **Relationships:** document, creator (User), markers (hasMany), requests (hasMany SignatureRequest), signatures (hasMany), auditLogs (hasMany), leaseRecord (hasOne), rejectedBy (User)
- **Statuses:** `draft`, `ready`, `signing`, `awaiting_tenant`, `awaiting_landlord`, `awaiting_buyer`, `awaiting_seller`, `pending_agent_approval`, `completed`, `expired`, `declined`, `rejected`

### SignatureRequest
- **Table:** `signature_requests`
- **Key columns:** `signature_template_id` (FK), `party_role`, `signing_order` (int), `signer_name`, `signer_email`, `signer_id_number`, `token` (64-char random string), `token_expires_at`, `status`, `sent_at`, `viewed_at`, `completed_at`, `reminder_sent_at`, `reminder_count`, `ip_address`, `user_agent`, `sent_by` (FK to users), `message`, `signing_method` (electronic|wet_ink), `wet_ink_upload_path` (JSON array of file paths), `wet_ink_status`, `wet_ink_rejection_note`, `reviewed_by`, `reviewed_at`, `team_alerted_at`
- **Relationships:** template (SignatureTemplate), sender (User), reviewer (User), inspections (hasMany WetInkInspection), signatures (hasMany)
- **Request statuses:** `waiting`, `pending`, `viewed`, `partially_signed`, `completed`, `expired`, `declined`
- **Wet-ink statuses:** `pending_upload`, `uploaded_pending_review`, `approved`, `rejected`

### SignatureMarker
- **Table:** `signature_markers`
- **Key columns:** `signature_template_id` (FK), `page_number`, `x_position`, `y_position`, `width`, `height`, `type` (signature|initial|date|text), `assigned_party`, `assigned_email` (for co-owner targeting), `label`, `sort_order`, `required` (boolean), `from_template_zone_id`
- **Relationships:** template (SignatureTemplate), signatures (hasMany Signature)

### Signature
- **Table:** `signatures`
- **Key columns:** `signature_template_id` (FK), `signature_marker_id` (FK), `signature_request_id` (FK), `signer_user_id` (nullable FK), `signer_name`, `signer_email`, `signer_ip_address`, `signer_user_agent`, `signature_data` (base64 drawn image), `text_value` (typed signature), `signature_type` (drawn|typed), `signed_at`
- **Relationships:** template, marker, request, signerUser

### SignatureAuditLog
- **Table:** `signature_audit_log`
- **Key columns:** `signature_template_id` (FK), `signature_request_id` (FK), `action`, `actor_type` (system|user|signer), `actor_id`, `actor_name`, `actor_email`, `actor_ip_address`, `actor_user_agent`, `metadata_json` (array), `document_hash`
- **Immutable:** `UPDATED_AT = null` — records are created only, never updated
- **Actions:** created, sent, viewed, signed, completed, declined, expired, cancelled, reminder_sent, wet_ink_uploaded, wet_ink_approved, wet_ink_rejected, team_alert_sent, manual_reminder_sent, document_completed, signed_pdf_emailed, pending_agent_approval, agent_approved_advance, agent_approved_complete, identity_verified, identity_verification_failed, fields_saved, web_fields_saved, download_verified, signed_pdf_downloaded

### Flow
- **Table:** `flows`
- **Key columns:** `type` ('esign'), `template_id` (FK to docuperfect_templates), `user_id`, `property_id`, `contact_id`, `current_step` (1-7), `step_data` (array — all wizard step data), `status` (active|draft|completed|abandoned), `completed_at`
- **Relationships:** template (Template), user, property, contact

---

## 7. KEY FILES

### Controllers
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Docuperfect/ESignWizardController.php` | 6-step wizard: create flow, show/save steps, prepare signing |
| `app/Http/Controllers/Docuperfect/SignatureController.php` | Setup markers, agent signing, send to parties, review, approve, wet-ink, download, rental dashboard |
| `app/Http/Controllers/Docuperfect/SigningController.php` | External signing: token-based show, verify identity, capture signature, save fields, complete, decline, wet-ink upload, download |

### Services
| File | Purpose |
|------|---------|
| `app/Services/Docuperfect/SignatureService.php` | Core signing logic: create requests, send emails, capture signatures, party completion, approval, document completion |
| `app/Services/Docuperfect/SignaturePdfService.php` | PDF generation for signed documents |
| `app/Services/Docuperfect/DocumentFlattener.php` | Bake fields + signatures onto page images (immutable flattening) |
| `app/Services/WebTemplateDataService.php` | Resolve web template blade variables from wizard step data |
| `app/Services/WebTemplateFieldPartyMap.php` | Map web template field names to party roles; determine editable fields per party |

### Models
| File | Purpose |
|------|---------|
| `app/Models/Docuperfect/Document.php` | Document instance with fields_json and web_template_data |
| `app/Models/Docuperfect/SignatureTemplate.php` | Signing workflow: status, parties, markers, requests |
| `app/Models/Docuperfect/SignatureRequest.php` | Per-signer record: token, status, wet-ink state |
| `app/Models/Docuperfect/SignatureMarker.php` | Positioned signing point on a page |
| `app/Models/Docuperfect/Signature.php` | Captured signature (drawn or typed) |
| `app/Models/Docuperfect/SignatureAuditLog.php` | Immutable audit trail |
| `app/Models/Docuperfect/Flow.php` | Wizard flow state (multi-step) |
| `app/Models/Docuperfect/Template.php` | Document template (PDF or web) with fields_json |
| `app/Models/Docuperfect/WebPack.php` | Group of web templates merged into one document |
| `app/Models/Docuperfect/TemplateSignatureZone.php` | Pre-defined signature zones on templates |
| `app/Models/Docuperfect/LeaseRecord.php` | Lease tracking record linked to completed signing |
| `app/Models/Docuperfect/WetInkInspection.php` | Wet-ink review/inspection record |

### Views
| File | Purpose |
|------|---------|
| `resources/views/docuperfect/esign/wizard.blade.php` | Main wizard view (all 6 steps, Alpine.js) |
| `resources/views/docuperfect/esign/signing-complete.blade.php` | Wizard completion page |
| `resources/views/docuperfect/signatures/setup.blade.php` | Marker placement + party configuration |
| `resources/views/docuperfect/signatures/sign.blade.php` | Agent signing interface |
| `resources/views/docuperfect/signatures/send-confirmation.blade.php` | Confirm before sending to next party |
| `resources/views/docuperfect/signatures/external/sign.blade.php` | External signer's signing page |
| `resources/views/docuperfect/signatures/external/verify.blade.php` | Identity verification gate |
| `resources/views/docuperfect/signatures/external/completed.blade.php` | Signing completion confirmation |
| `resources/views/docuperfect/signatures/external/expired.blade.php` | Expired/declined token page |
| `resources/views/docuperfect/signatures/external/upload-received.blade.php` | Wet-ink upload confirmation |
| `resources/views/docuperfect/signatures/external/download.blade.php` | Signed document download page |
| `resources/views/docuperfect/rental/dashboard.blade.php` | Rental documents dashboard |

### Mail Classes
| File | Purpose |
|------|---------|
| `app/Mail/Signatures/SigningRequestMail.php` | Email sent to external signer with signing link |
| `app/Mail/Signatures/SignatureReminderMail.php` | Reminder email to signer |
| `app/Mail/Signatures/SignedDocumentMail.php` | Email with download link after all parties sign |
| `app/Mail/Signatures/WetInkUploadedNotification.php` | Notify agent of wet-ink upload |
| `app/Mail/Signatures/WetInkRejectionMail.php` | Notify signer of wet-ink rejection |

---

## 8. ROUTES

### E-Sign Wizard (auth required, permission: manage_designations context)
| Method | URI | Controller@action | Route name |
|--------|-----|-------------------|------------|
| GET | `/esign/test-render/{templateId}` | ESignWizardController@testRender | docuperfect.esign.testRender |
| GET | `/esign/create` | ESignWizardController@create | docuperfect.esign.create |
| POST | `/esign/store` | ESignWizardController@store | docuperfect.esign.store |
| GET | `/esign/{flow}/step/{step}` | ESignWizardController@showStep | docuperfect.esign.step |
| POST | `/esign/{flow}/step/{step}` | ESignWizardController@saveStep | docuperfect.esign.saveStep |
| POST | `/esign/{flow}/draft` | ESignWizardController@saveDraft | docuperfect.esign.saveDraft |
| DELETE | `/esign/{flow}` | ESignWizardController@destroy | docuperfect.esign.destroy |
| POST | `/esign/{flow}/autosave-fields` | ESignWizardController@autosaveFields | docuperfect.esign.autosaveFields |
| POST | `/esign/{flow}/prepare-signing` | ESignWizardController@prepareSigning | docuperfect.esign.prepareSigning |
| GET | `/esign/{flow}/signing-complete` | ESignWizardController@signingComplete | docuperfect.esign.signingComplete |
| GET | `/esign/api/properties` | ESignWizardController@searchProperties | docuperfect.esign.api.properties |
| GET | `/esign/api/contacts` | ESignWizardController@searchContacts | docuperfect.esign.api.contacts |
| GET | `/esign/api/template/{templateId}/pages` | ESignWizardController@templatePages | docuperfect.esign.api.templatePages |

### Signature Management (auth required)
| Method | URI | Controller@action | Route name |
|--------|-----|-------------------|------------|
| GET | `/documents/{document}/signatures/setup` | SignatureController@setup | docuperfect.signatures.setup |
| POST | `/documents/{document}/signatures/parties` | SignatureController@saveParties | docuperfect.signatures.saveParties |
| POST | `/documents/{document}/signatures/markers` | SignatureController@saveMarkers | docuperfect.signatures.saveMarkers |
| PUT | `/documents/{document}/signatures/markers` | SignatureController@updateMarkers | docuperfect.signatures.updateMarkers |
| POST | `/documents/{document}/signatures/upload-presigned` | SignatureController@uploadPresigned | docuperfect.signatures.uploadPresigned |
| GET | `/documents/{document}/sign` | SignatureController@sign | docuperfect.signatures.sign |
| POST | `/documents/{document}/sign/{marker}` | SignatureController@captureSignature | docuperfect.signatures.capture |
| POST | `/documents/{document}/save-agent-fields` | SignatureController@saveAgentFields | docuperfect.signatures.saveAgentFields |
| POST | `/documents/{document}/save-agent-web-fields` | SignatureController@saveAgentWebFields | docuperfect.signatures.saveAgentWebFields |
| POST | `/documents/{document}/sign-complete` | SignatureController@signComplete | docuperfect.signatures.signComplete |
| GET | `/documents/{document}/send-confirmation` | SignatureController@sendConfirmation | docuperfect.signatures.sendConfirmation |
| POST | `/documents/{document}/send-for-signature` | SignatureController@sendForSignature | docuperfect.signatures.send |
| POST | `/documents/{document}/send-reminder/{signatureRequest}` | SignatureController@sendReminder | docuperfect.signatures.sendReminder |
| GET | `/documents/{document}/signatures/review` | SignatureController@review | docuperfect.signatures.review |
| POST | `/documents/{document}/signatures/approve-and-advance` | SignatureController@approveAndAdvance | docuperfect.signatures.approveAndAdvance |
| GET | `/documents/{document}/signatures/audit` | SignatureController@audit | docuperfect.signatures.audit |
| GET | `/documents/{document}/signatures/download` | SignatureController@download | docuperfect.signatures.download |
| GET | `/documents/{document}/signatures/inspect/{signingRequest}` | SignatureController@wetInkReview | docuperfect.signatures.wetInkReview |
| POST | `/documents/{document}/signatures/inspect/{signingRequest}/decision` | SignatureController@wetInkDecision | docuperfect.signatures.wetInkDecision |
| GET | `/documents/{document}/signatures/inspect/{signingRequest}/file/{fileIndex}` | SignatureController@wetInkFile | docuperfect.signatures.wetInkFile |
| POST | `/documents/{document}/signatures/inspect/{signingRequest}/upload-on-behalf` | SignatureController@uploadOnBehalf | docuperfect.signatures.uploadOnBehalf |
| POST | `/documents/{document}/supersede` | SignatureController@supersede | docuperfect.signatures.supersede |
| POST | `/documents/{document}/reject` | SignatureController@reject | docuperfect.signatures.reject |
| GET | `/signatures/{templateId}/flattened-page/{page}` | SignatureController@flattenedPageImage | docuperfect.signatures.flattenedPage |
| GET | `/leases` | SignatureController@leases | docuperfect.leases.index |

### External Signing (no auth — token-based, prefix: /sign)
| Method | URI | Controller@action | Route name |
|--------|-----|-------------------|------------|
| GET | `/sign/{token}` | SigningController@show | signatures.external |
| POST | `/sign/{token}/verify` | SigningController@verify | signatures.external.verify |
| POST | `/sign/{token}/choose-method` | SigningController@chooseMethod | signatures.external.chooseMethod |
| POST | `/sign/{token}/capture/{marker}` | SigningController@capture | signatures.external.capture |
| POST | `/sign/{token}/save-fields` | SigningController@saveFields | signatures.external.saveFields |
| POST | `/sign/{token}/save-web-fields` | SigningController@saveWebFields | signatures.external.saveWebFields |
| POST | `/sign/{token}/complete` | SigningController@complete | signatures.external.complete |
| GET | `/sign/{token}/completed` | SigningController@completed | signatures.external.completed |
| POST | `/sign/{token}/upload` | SigningController@uploadWetInk | signatures.external.upload |
| GET | `/sign/{token}/download` | SigningController@downloadForSigning | signatures.external.download |
| POST | `/sign/{token}/decline` | SigningController@decline | signatures.external.decline |
| GET | `/sign/{token}/flattened-page/{page}` | SigningController@flattenedPageImage | signatures.external.flattenedPage |

### Signed Document Download (no auth — token-based)
| Method | URI | Controller@action | Route name |
|--------|-----|-------------------|------------|
| GET | `/documents/download/{token}` | SigningController@downloadPage | signatures.download.page |
| POST | `/documents/download/{token}/verify` | SigningController@downloadVerify | signatures.download.verify |
| GET | `/documents/download/{token}/file` | SigningController@downloadSignedFile | signatures.download.file |

---

## 9. STATUS FLOW

### SignatureTemplate Status Progression
```
draft → ready → signing → awaiting_tenant → pending_agent_approval → awaiting_landlord → pending_agent_approval → completed
                                                                                                                   ↘ expired
                                                                                                                   ↘ declined
                                                                                                                   ↘ rejected
```

Detailed flow:
1. **draft** — Template created, no parties/markers configured yet
2. **ready** — Parties and markers configured, ready for agent to sign
3. **signing** — Agent is actively signing (generic status, not always used)
4. **awaiting_tenant** — Agent done, waiting for tenant to sign
5. **awaiting_landlord** — Tenant done + agent approved, waiting for landlord
6. **awaiting_buyer** — Agent done, waiting for buyer (sales flow)
7. **awaiting_seller** — Buyer done + agent approved, waiting for seller
8. **pending_agent_approval** — External party finished signing, agent must review and approve before advancing
9. **completed** — All parties signed, signed PDF generated
10. **expired** — Signing link expired before completion
11. **declined** — Signer declined to sign (with optional reason)
12. **rejected** — Agent rejected the document (superseded or voided)

### SignatureRequest Status Progression
```
waiting → pending → viewed → partially_signed → completed
                                               ↘ expired
                                               ↘ declined
```

1. **waiting** — Not yet this signer's turn
2. **pending** — Email sent, signer hasn't opened link yet
3. **viewed** — Signer opened the signing link (first view recorded)
4. **partially_signed** — Signer has signed some but not all markers
5. **completed** — All required markers signed
6. **expired** — Token expired before completion
7. **declined** — Signer declined to sign

---

## 10. RULES — NEVER BREAK THESE

1. **Sequential signing enforced** — Parties sign in the order defined by `signing_order_json`. A signer cannot access the document until it's their turn (previous party must be completed + agent approved).

2. **Agent approval gate between parties** — After each external party completes, template status goes to `pending_agent_approval`. Agent must explicitly approve (via review page) before the next party receives their signing link. Exception: after agent's own signing, it auto-advances to the first external party.

3. **PDF flattening is immutable** — Once fields and signatures are flattened onto page images (after agent signs), the original page images are replaced. External signers see only flattened images. This prevents tampering. Document hash is recalculated before each party.

4. **No hard deletes** — Documents, templates, requests all use SoftDeletes. Flow deletion sets `status='abandoned'`.

5. **Rental = e-sign OR wet-ink, Sales = wet-ink only** — Template type determines available signing methods. External signers choose their method (electronic or wet-ink) on the signing page.

6. **Field normalisation for web templates** — `normalizeFieldForWizard()` must run in both `showStep()` and `prepareSigning()` to ensure web template fields (tag_type) map correctly to wizard expectations (type). Without this, web template fields won't render in the Fill & Review step.

7. **Identity verification required** — External signers with an ID number on file must verify their identity before accessing the document. Session-based (`signing_verified_{token}`).

8. **Role alias mapping is critical** — The system uses both "landlord/tenant" (wizard, party_role) and "lessor/lessee" (field assignedTo, contact types). Both `SigningController@saveFields` and `SigningController@complete` apply role aliases to match correctly.

9. **Audit everything** — Every significant action is logged to `signature_audit_log` with actor info, IP, user agent. This log is immutable (no updates).

10. **Document hash verification** — SHA-256 hash of fields_json is stored on the template and recalculated before sending to each new party. A mismatch during signing is logged as a warning (soft check — doesn't block).
