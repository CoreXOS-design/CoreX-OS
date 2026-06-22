# Atlas — E-Sign / DocuPerfect

> **Status: DONE** · Last verified: 2026-06-22
> Pillars: **Property** (document subject) × **Contact** (recipients/parties) × Compliance (FICA gate).
> **Source of truth:** `.ai/specs/claude_esignature_v2_spec.md` (V2, audit-verified Mar 2026) +
> `.ai/specs/esign-v3-complete-spec.md` (V3, Jun 2026). **Current code has FIXED several V2 "BROKEN"
> items** — flagged inline. Cited audit: `esign-reset-investigation-2026-05-27.md` (the pipeline-gate
> rationale). The CLAUDE.md "E-sign integration moat — pipeline gate" lists the protected files.

---

## 1. WHAT IT DOES

DocuPerfect builds, fills, and e-signs agency documents (mandates, FICA/POPIA consents, disclosures,
leases <10yr, inspection reports). A 6-step wizard turns a template + property + recipients into a signing
session; external signers sign via token links; the agent approves between parties; the completed pack is
split into individually-filed, legally-defensible PDFs. **Sale agreements / OTPs are e-sign BLOCKED** (now
5-layer enforced) — they route to wet-ink. The system is the densest cross-feature consumer: it reads
Contacts as parties, gates on Compliance FICA state, and files signed documents back against properties.

---

## 2. ENTRY POINTS

### Routes (`routes/web.php`)
- **DocuPerfect group** `:2730` (`prefix('docuperfect')`, `permission:access_docuperfect`): dashboard/create
  `:2731-2732`, templates+CDS `:2735-2754`, documents `:2756-2773`, clauses `:2776-2782`, document-types
  `:2791-2796`, field-groups `:2807-2811`, packs `:2814-2823`, web-packs `:2826-2831`.
- **E-Sign wizard** `:2838-2864`: `myDocuments` `:2838`, `create` `:2840`, `store` `:2841`,
  `showStep`/`saveStep` `:2842-2843`, `prepareSigning` `:2847`, `prepareDownload`/`prepareWetInk`
  `:2848-2849`, `searchContacts` `:2857`.
- **Inline mutations (P0 invariant):** `ConditionsController` `:2886-2887`; **Amendments**
  `AmendmentController` `:2890-2893`.
- **Signatures (agent/internal):** `:2942-3011` — setup `:2957`, saveMarkers `:2959`, zones `:2963-2967`,
  sign `:2970`, review `:2945`, approveAndAdvance `:2946`, send `:2979`.
- **External signing (no auth, token):** `prefix('sign')` `:3077-3101` — `show` `:3078`, gateway/verify
  `:3079-3080`, consent `:3081-3082`, capture `:3085`, saveWebFields `:3087`, completeWeb `:3088`,
  section accept/reject `:3100-3101`.
- **Wet-ink / upload return** (e-sign-blocked docs): `:3022-3034`, public return `:3071-3074`.

### Controllers (`app/Http/Controllers/Docuperfect/`)
`ESignWizardController.php` (6-step wizard; `prepareSigning` `:1450`, legal block `:138,1465`),
`SignatureController.php` (agent signing/setup/markers/review/approve; `embedSignaturesIntoHtml` `:1504`,
`embedInitialsIntoHtml` `:1636`), `SigningController.php` (external flow; `show` `:41`, FICA gate `:124`,
pipeline `:302-343`, `addCondition` `:3166`), `TemplateController`, `DocumentController`,
`ConditionsController`, `AmendmentController`.

### Blade + Nav
Views (`resources/views/docuperfect/`): `esign/wizard.blade.php`, `signatures/sign.blade.php` (agent),
**`signatures/external/sign.blade.php`** (the P0 signing view), `signatures/external/fica-gate.blade.php`,
`signatures/partials/a4-page-styles.blade.php` (pagination/initials). Nav
(`layouts/corex-sidebar.blade.php`): Create Document `:874`, **E-Sign Document `:875`**, My E-Sign Docs
`:876`, Packs/Web Packs `:885-886`, Clause Library `:889`, Template Management `:892`.

---

## 3. THE FLOW

1. **6-step wizard** (`ESignWizardController`): template → property → recipients → details → fill →
   sign/send (`showStep`/`saveStep` `:2842-2843`; step gating `:488+`).
2. **Recipients consume Contacts via `contact_property`** — `ESignWizardController.php:493-540` (sales) /
   `:550-562` (rental landlord synth). `searchContacts` filters by `esign_role` `:1124-1130`.
3. **Signing** — external `SigningController::show` `:41`; web-template render `:250-344`.
4. **The P0 SIGNING-VIEW INVARIANT** (STANDARDS.md §"E-Sign — Signing-view state preservation":
   no `location.reload()`, no JS re-render; inline mutations return a server-rendered `rendered_row`).
   `addCondition` `:3166` → returns `rendered_row` `:3268` via
   `InsertableBlockRenderer::renderConditionRowPublic` (`InsertableBlockRenderer.php:264`); canonical-impl
   comment `:3250-3262` (commit `bb6cc9f`). `flagClause` `:3287`, `proposeStrikethrough` `:3513`,
   `initialCondition` `:3652` follow the same append-only pattern. **This is a standing P0 invariant** —
   a single inadvertent re-render wipes 5–15 min of a signer's captured work.
5. **Disclosure gate** — answers in `web_template_data.disclosure_answers`: capture `:1362-1368`, restore
   `:465`; completion gating per V3 §19.6 (counts all per-page initials + signature blocks).
6. **Pack filing** — `SignatureService::autoFileSignedDocument` `:1804` → `filePackDocuments` `:1897` →
   `splitMergedHtml` `:2009` (splits from `signed_paginated_html` = exact signed DOM, V3 §19.7). Single-doc
   `fileSingleDocument` `:1839`.
7. **Signed-PDF storage** — individual PDFs under `docuperfect/signed-documents/{id}/individual`
   (`SignatureService.php:1923`); `Document` records `:1870` (`source_type='esign'`) linked via
   `linkFiledDocumentToContactsAndProperty` `:2091`.

---

## 4. THE FICA GATE (consumes Compliance state)

Columns: `signature_requests.fica_required`, `contact_id`, `fica_submission_id` (migration
`2026_03_26_300000`, FK nullOnDelete). Gate in `SigningController::show` `:124-127` — fires when
`fica_required && contact_id`; **lifts on SUBMISSION** via
`whereIn('status', ['submitted','under_review','agent_approved','approved'])` `:126`. Tokenless-submission
defence mints a token so the page never 500s `:145-149`; FICA URL carries `return_url` `:151-153`. Gate
view `fica-gate.blade.php` (`ficaStatus` pending_review/needs_form/none). See `compliance.md` §3 for the
FICA model side.

**⚠ Spec divergence:** V3 spec §6.2 says the gate should clear on `status === 'approved'`; **current code
clears on SUBMISSION** (V2 §9 semantics). Doc and code disagree — confirm intended behaviour.

---

## 5. PIPELINE FILES (the CLAUDE.md moat — `CLAUDE.md:252-261`)

| File | What | Key lines |
|------|------|-----------|
| `app/Models/Docuperfect/Template.php` | template model; `isEsignBlocked()` `:331`, `isSalesDocument()` `:282`, delivery-mode resolution `:396-419` | SoftDeletes `:12` |
| `app/Models/Docuperfect/CdsDraft.php` | CDS builder draft state (tags/mappings/tagged_html) — the "six sources of truth" hazard (§9) | SoftDeletes |
| `app/Services/Docuperfect/SignatureSurfaceNormalizer.php` | promotes inline sig blocks to `[data-marker-party][data-marker-type]` so the engine finds signable surfaces | invoked `SigningController.php:302` |
| `app/Services/Docuperfect/LetterheadRefresher.php` | re-resolves company header in stored `merged_html` at serve time (no stale letterhead) | invoked `:307` |
| `app/Services/Docuperfect/InsertableBlockRenderer.php` | replaces `~~~~MARKER~~~~` tokens with styled partials; `renderConditionRowPublic` `:264` (the P0 rendered_row) | invoked `:316` |
| `app/Services/Docuperfect/RoleBlockDetectionService.php` | parses `data-field` attrs → role-base + instance index | — |
| `app/Services/Docuperfect/RoleBlockExpansionService.php` | `expandWithLooping` `:262` duplicates blocks per recipient; `resolveContact` (`Contact::find`) `:1751-1756` | invoked `:336` |
| `app/Services/Docuperfect/MergedHtmlFreshnessGuard.php` | `ensureFresh()` re-renders stale snapshots | invoked `:258` |
| `app/Http/Controllers/Docuperfect/SigningController.php` | external signer entry; pipeline orchestration `:250-344` | — |

**⚠ Moat-gate phantom file:** `scripts/dev-check.ps1:153` lists a non-existent
`app/Services/Docuperfect/SurfaceNormalizer.php` (only `SignatureSurfaceNormalizer.php` exists); the
real `RoleBlockNormalizer.php` is NOT in the gate list — the gate is partially mis-targeted (BACKLOG).

---

## 6. DATA READ / WRITTEN

Tables: `docuperfect_templates`, `docuperfect_documents` (+`signed_paginated_html`
`2026_05_19_140000`), `signature_templates`, `signature_requests` (+role_index, +fica gate),
`signature_markers`, `signature_zones`, `document_amendments`, `document_conditions`, `esign_consent_log`,
`agency_signing_parties`, `contact_types.esign_role`. Filed `documents` link via the **plural unified
pivots** `document_contacts`/`document_properties` (`Document::contacts()`/`properties()`
`Document.php:42,49`, `syncWithoutDetaching`). **⚠ Two pivot lineages coexist:** the older esign-era
`document_contact` (singular) and the active unified `document_contacts` (plural) — latent confusion.

**Audited:** `esign_consent_log` is **immutable** — throws on update (`ESignConsentLog.php:61-66`);
`SignatureAuditLog` records every action (VIEWED `SigningController.php:201`). **SoftDeletes** on Template,
SignatureRequest, SignatureTemplate, DocumentAmendment, DocumentCondition, markers/zones, Document,
AgencySigningParty, CdsDraft. The consent log is intentionally NOT soft-deletable (immutable).

---

## 7. RECIPIENTS CONSUME CONTACTS

`RoleBlockExpansionService::resolveContact` `:1751-1756` → `Contact::find($recipient->contact_id)`.
Per-recipient **multi-seller surfaces** (RecipientLoop): `expandWithLooping` `:262` clones single-block
templates per recipient, stamping `data-recipient-identity="{role}_{index}"` + `data-viewer-editable`.
Party→contact mapping at the wizard: sales per-linked-contact with role from `contact_types`
(`ESignWizardController.php:505-537`), rental landlord synth `:550-576`; `role_identity` =
`{party_role}_{role_index}` (`SigningController.php:436`). See `contacts.md` §6.

---

## 8. AGENCY SETTINGS / CONFIG

| Setting | Default | Notes |
|---------|---------|-------|
| `agency_signing_parties` | Agent/Seller/Buyer/Lessor/Lessee/Witness | agency-wide signature-block party names; CRUD via `DocumentImporterController` |
| `contact_types.esign_role` | seller/buyer/lessor/lessee/null | wizard recipient filter (`ESignWizardController.php:509-514`); migration `2026_03_27_100000` |
| `docuperfect_templates.party_mode` | `shared` | shared (all sign same doc) vs `per_party` (separate copy each) |
| `docuperfect_templates.allowed_delivery_modes` | `esign,wet_ink,download` | which modes appear in wizard Step 6 (`Template.php:396`) |
| `docuperfect_templates.is_esign` | bool | coarse gate: show/hide from wizard list |
| `docuperfect_templates.signing_parties` (JSON) | owner_party/acquiring_party/agent | drives `isSalesDocument`, signature-block rendering |

---

## 9. KNOWN FRAGILITIES

1. **The pipeline-gate rationale (the reason the moat exists).** The audit
   `esign-reset-investigation-2026-05-27.md` found **5 live signing bugs shipped to the browser while 49
   RecipientLoop unit tests were green** — the tests exercised synthetic fixtures, never the live
   `Template → CdsDraft → blade → SigningController → sign.blade.php` chain. CLAUDE.md's pipeline gate
   (`:263-267`) requires any change to the §5 files to ship with an integration-test diff. **Any work on
   these files must add a `tests/Feature/Docuperfect/SigningView/` test.**
2. **P0 signing-view invariant (§3.4).** No `location.reload()`, no JS re-render during signing — a single
   inadvertent re-render wipes captured signatures/initials. Inline mutations MUST return `rendered_row`
   and append only the changed node.
3. **FICA gate spec divergence (§4)** — code lifts on submission, V3 spec says approval.
4. **Multi-cluster role detection (audit Q2, P0 — STILL OPEN).** `RoleBlockExpansionService.php:524-542`
   bails when `totalClusters > 1`, stamping only `seller_1` — seller 2/3 get no editable block when a
   role has disjoint clusters (a name in the opening paragraph plus a block lower down). Fix not yet applied.
5. **Two filed-document pivot lineages** (`document_contact` singular vs `document_contacts` plural) — the
   active relations use the plural unified pivots; the singular is residual.
6. **CDS "six sources of truth" (audit Q1).** `cds_json`, `editor_state.{tags,mappings,tagged_html}`,
   `field_mappings`, `fields_json`, generated blade, and the live `CdsDraft` can disagree — the
   template-revert symptom. Canonical-field consolidation not yet landed.
7. **Email distribution (by design).** **Agents receive ZERO emails** — all in-app DB notifications
   (`SignatureActivityNotification`). **External signers receive exactly TWO emails:** signing request
   (`SignatureService.php:2652`) + completed PDF (`:2872`). The full per-recipient email distribution
   matrix is V3 backlog.
8. **Legal e-sign block (now 5 layers, was 3 in V2).** `sale_agreement`/`otp` blocked at: model
   `Template.php:331,418`, wizard JS `wizard.blade.php:1699`, server `ESignWizardController.php:138,1465`,
   signing-view redirect `SigningController.php:184-190`, + audit logging (V3 §5.5). Blocked docs route to
   wet-ink/upload-return (`routes/web.php:3022-3034`).
9. **V2 backlog still open:** editable-at-signing infra built but no template populates
   `field_mappings.editable_by` (V2 §11); clause flags collected but full amendment auto-creation only
   wired for "Other Conditions" (V2 §10); signature-image upload + staff FICA training parked (V2 §16).
   **V2 BUGs now FIXED in current code:** #1 contact filtering (`esign_role` wired `:511-514` + rental
   support), #2 rental-shows-sales-fields (`isSalesDocument` reworked `Template.php:284-313`), #5 initials
   in PDF (`embedInitialsIntoHtml` + per-document-DOM PDF, V3 §19.7), #7 documents-list memory (paginate).

---

## Key file:line index
- `app/Http/Controllers/Docuperfect/SigningController.php` — `:41` show, `:124-153` FICA gate, `:250-344` pipeline, `:3166-3268` addCondition (P0 rendered_row).
- `app/Http/Controllers/Docuperfect/ESignWizardController.php` — `:138,1465` legal block, `:493-562` recipients, `:1124-1130` esign_role filter.
- `app/Services/Docuperfect/SignatureService.php` — `:1804` autoFileSignedDocument, `:2009` splitMergedHtml, `:2652/2872` the two signer emails.
- `app/Models/Docuperfect/Template.php` — `:282,331,396-419`; `ESignConsentLog.php:61-66` (immutable).
- Specs: `.ai/specs/claude_esignature_v2_spec.md`, `.ai/specs/esign-v3-complete-spec.md`. Audit: `.ai/audits/esign-reset-investigation-2026-05-27.md`.
