# E-Sign V3 — Reconciliation & Verified Current State

**Date:** 2026-06-10
**Branch:** AT-13-Training-Fixes
**Type:** Verification + spec-reconciliation (READ-ONLY — no application code changed)
**Author:** Claude (verification pass), for Johan Reichel

> Purpose: produce a single evidence-based current-state picture of the e-sign
> module after three spec/audit documents layered over time. Every status claim
> below is traced to `file:line` in actual code on this branch. Spec self-claims
> were **not** trusted — each was independently verified.

---

## 0. Documents reconciled

The prompt referenced three documents by provisional names; the actual files on
this branch are:

| Prompt name | Actual file on branch | Date | Role |
|---|---|---|---|
| `esign-v2-state-of-reality.md` | `.ai/specs/claude_esignature_v2_spec.md` | 27 Mar 2026 | Superseded inventory; source of bugs #1–6 |
| `esign-v3-complete-spec.md` | `.ai/specs/esign-v3-complete-spec.md` | 21 May 2026 | Current master (ES-1..ES-9, §22 reconciliation log) |
| signing-surface seam audit (§20) | `.ai/AUDIT-esign-signing-surface-seam.md` | 19 May 2026 | Proposes §20; §20.1–20.9 NOT APPROVED, §20.10/§20.11 APPROVED+implemented |
| (supporting) | `.ai/specs/esign-v3-phase-1b-build-notes.md` | — | Phase 1B deferral notes (pagination recalc, wet-ink) |

Other supporting audits present and cross-referenced: `esign-reset-investigation-2026-05-27.md`,
`editable-by-runtime-investigation-2026-05-26.md`, `cds-template-system-audit-2026-05-21.md`,
`esign-full-state-audit-2026-05-26.md`.

---

## 1. Code inventory (file:line anchors)

### 1.1 Controllers — `app/Http/Controllers/Docuperfect/`

| Controller | Lines | Key methods (file:line) | Purpose |
|---|---|---|---|
| `ESignWizardController.php` | 4856 | `prepareSigning:1450`, `signingComplete:2393`, `myDocuments:4702`, `searchContacts:1109`, `duplicateFicaPerParty:3950`, `prepareWetInk:4207` | Multi-step send wizard; merged_html snapshot build; legal hard-block; FICA gate site |
| `SignatureController.php` | 2966 | `setup:210`, `embedSignaturesIntoHtml:1504`, `embedInitialsIntoHtml:1636`, `review:2182`, `approveAndAdvance:2392`, `amendmentAction:2946` | Agent-side setup/zones/internal signing/review. **Pipeline-gate file** |
| `SigningController.php` | 3983 | `show:41`, `addCondition:3166`, `flagClause:3287`, `removeOwnFlag:3421`, `proposeStrikethrough:3513`, `initialAmendments:3726`, `extractNumberedClauses:3066` | External recipient signing; conditions/flags/strikethroughs. **Pipeline-gate file** |
| `ConditionsController.php` | 257 | `storeCondition:41`, `storeStrikethrough:119` | Agent-side add/strike conditions |
| `AmendmentController.php` | 189 | `review:39`, `approve:66`, `rejectChange:99`, `rejectDocument:121` | Agent amendment review surface (§9.6) |
| `FlagRemovalController.php` | 312 | `requestRemoval:43`, `showConsent:121`, `submitConsent:156` | Post-completion consent-gated flag removal (1B.9 FIX1) |
| `TemplateController.php` | 1322 | `cdsBuilder:394`, `cdsGenerate:548`, `webPreview:1202` | Template CRUD + CDS builder |
| `DocumentImporterController.php` | 1247 | `parse:100`, `generateCdsTemplate:72`, `review:283`, `generate:459` | AI document import → CDS template |
| `ClauseController.php` | 158 | `index:12`, `store:48`, `listJson:138` | Clause library CRUD + JSON API |

All nine requested controllers exist.

### 1.2 Services — `app/Services/Docuperfect/`

| Service | Lines | Key methods | Notes |
|---|---|---|---|
| `SignatureService.php` | 3510 | `createSigningRequest:812`, `handlePartyCompletion:1073`, `approveAndAdvance:1184`, `requeueAllPartiesForInitialing:3095`, `rejectAmendmentChange:3183`, `rejectAmendmentDocument:3231` | Core signing logic |
| `SignaturePdfService.php` | 457 | `generateFromHtml` | Puppeteer PDF. **Pipeline-gate file** |
| `InsertableBlockRenderer.php` | 605 | `renderInDocument:46`, `applyStrikethroughs:115`, `renderConditionRowPublic:264` | Marker → block partials. **Pipeline-gate file** |
| `LegacyOtherConditionsBridge.php` | 171 | `syncToStructuredRows:40` | Textarea → structured rows |
| `CdsParserService.php` | 1733 | `detectMarkers:742` | Marker detection (incl. `~~~~`) |
| `ImporterAiService.php` | 453 | `detectFromPdf:385` | Claude `claude-sonnet-4-6` + `gpt-4o-mini` fallback |
| `DocxParserService.php` | 891 | — | DOCX parse |
| `ClaudeVisionParserService.php` | 319 | — | **UNWIRED scaffolding — not referenced by any route** |
| `DocumentTemplateGenerator.php` | 990 | — | Blade generation from import |
| `SigningSurfaceResolver.php` | 290 | `resolve:57` | §20 resolver — **BUILT and LIVE-WIRED** (see §4) |
| `SignatureSurfaceNormalizer.php` | 164 | `normalize:37` (static) | Additive normaliser. **Pipeline-gate file** |
| `LetterheadRefresher.php` | 112 | `refresh:30` (static) | **Pipeline-gate file** |
| `RoleBlockDetectionService.php` | 453 | `detectFromHtml:80` | **Pipeline-gate file** |
| `RoleBlockExpansionService.php` | 1844 | — | Per-recipient role-block duplication. **Pipeline-gate file** |
| `MergedHtmlFreshnessGuard.php` | 152 | `isStale:59`, `ensureFresh:81` | **Pipeline-gate file** |
| `WebTemplatePdfService.php` | 535 | — | Web template → PDF |

**Inventory discrepancies (gate / naming):**

- **`SurfaceNormalizer.php` DOES NOT EXIST.** It is still listed as a CLAUDE.md
  pipeline-gate file and in `scripts/dev-check.ps1:153`. The live file is
  `SignatureSurfaceNormalizer.php`. **The pipeline-gate list references a phantom
  file** — a stale gate entry that should be corrected (separate cleanup, not
  e-sign functionality).
- `WebTemplateDataService.php` lives at `app/Services/` (not under `Docuperfect/`).

### 1.3 Models — `app/Models/Docuperfect/`

| Model | Lines | Immutability / notes |
|---|---|---|
| `Template.php` | 542 | `isEsignBlocked():331`, `isSalesDocument():282`. **Pipeline-gate file** |
| `CdsDraft.php` | 37 | **Pipeline-gate file** |
| `DocumentAmendment.php` | 111 | Flag amendment type/origin enums |
| `DocumentCondition.php` | 111 | Soft-deletable |
| `DocumentClauseStrikethrough.php` | 84 | No override (preserved schema, no recipient writes) |
| `ConditionInitial.php` | 75 | **Immutable: `save():65` throws `DomainException` if `$this->exists`** |
| `FlagRemovalRequest.php` | 88 | — |
| `SignatureTemplate.php` | 279 | `signature_templates` model — state machine; `STATUS_AMENDMENT_INITIALING:72`, `AMENDMENT_STATUS_INITIALING:78` |
| `SignatureRequest.php` | 209 | Per-recipient request |
| `SignatureMarker.php` | 100 | — |
| `Clause.php` | 54 | `protected $table='docuperfect_clauses'`. **No `DocuperfectClause` class** |
| `DocumentType.php` | 39 | Legal classification |

Also confirmed immutable: `ESignConsentLog.php` (`delete()`/`update()` throw),
`LegalBlockAuditLog.php` (`save():48` throws on existing row).

### 1.4 Migrations — `database/migrations/`

| Object | Migration file | Verdict |
|---|---|---|
| `document_conditions` + `document_clause_strikethroughs` + `condition_initials` | `2026_05_22_010001_create_document_conditions_tables.php` (:26/:57/:77) | ✓ (all three + `insertable_blocks` JSON column in this one file) |
| `flag_removal_requests` | `2026_05_23_100001_create_flag_removal_requests_table.php` | ✓ |
| `legal_block_audit_log` | `2026_05_21_220001_create_legal_block_audit_log_table.php` | ✓ |
| `relates_to_clause_ref` | `2026_05_22_140001_add_relates_to_clause_ref_to_conditions.php` | ✓ |
| Legacy-conditions backfill | `2026_05_22_120001_backfill_legacy_other_conditions.php` | ✓ |
| OTP classification + 4 slugs | `2026_05_21_220002_classify_otp_templates.php` | ✓ |
| amendment_initialing status | `2026_05_22_010003_add_amendment_initialing_to_signature_template_status.php` | ✓ |
| flags on amendments | `2026_05_22_020001_extend_amendments_for_flags.php` | ✓ |

### 1.5 Signing views

| File | Lines | Notes |
|---|---|---|
| `signatures/external/sign.blade.php` | 3558 | Main external signing page; banners at :244/:272; `isMyWebSigBlock:2788` |
| `signatures/external/initialing.blade.php` | 201 | Focused initialing view (ES-3) |
| `signatures/external/amendment-review.blade.php` | 287 | Recipient amendment review |
| `signatures/external/fica-gate.blade.php` | 86 | FICA gate |
| `signatures/sign.blade.php` | 1979 | Agent/internal sign view |
| `signatures/setup.blade.php` | 1253 | Setup / zone placement |
| `signatures/partials/a4-page-styles.blade.php` | 547 | Pagination + initials rows |
| `signatures/_partials/add-condition-modal.blade.php` | 296 | **Under `_partials/` (underscore), not `partials/`**; `_appendConditionRow` handler |
| `web-templates/components/signature-block.blade.php` | 226 | **Under `web-templates/components/`**, per-recipient loop :45-75 |
| `web-templates/components/signature-line.blade.php` | 36 | Per-recipient loop :21-31 |
| `web-templates/cds/template-117.blade.php` | 46 | §20.11 fix at :38 |
| `web-templates/cds/template-119.blade.php` | 25 | §20.11 fix at :17 |
| `web-templates/cds/template-120.blade.php` | 25 | §20.11 fix at :17 |
| `web-templates/sales-mandatory-disclosure.blade.php` | 71 | Template 123, §20.11 fix at :63 |

**View-path discrepancies vs spec:** `add-condition-modal` is under `_partials/`;
`signature-block`/`signature-line` are under `web-templates/components/`, not
`signatures/partials/`. A `cds/template-123.blade.php` (25 lines) also exists
alongside `sales-mandatory-disclosure.blade.php`.

---

## 2. ES-item verification (ES-1 … ES-9)

| ES-item | Verdict | Evidence (file:line) |
|---|---|---|
| **ES-1** Legal Block | ✓ **BUILT** | `Template::isEsignBlocked():331-389` (slug `in_array` + word-boundary regex :352 + `logBlockTrigger`); `LegalBlockAuditLog::save():48` insert-only; hard block `ESignWizardController.php:1465-1472`; remediation `2026_05_21_220002` adds 4 slugs (:31-36) + name-pattern classify (:66-91). |
| **ES-2** FICA orderBy | ✓ **BUILT** | `SigningController.php:134-135` + `ESignWizardController.php:2098-2099` both `->orderByDesc('created_at')->orderByDesc('id')->first()`. Companion `->exists()` checks need no ordering. |
| **ES-3** Initialing Cascade | ✓ **BUILT** | `SignatureTemplate.php:72` const; `SignatureService.php:3095/3183/3231`; `external/initialing.blade.php`; view-switch `SigningController.php:106-110`; `amendment_id` on `condition_initials` (`2026_05_22_010001:84`). |
| **ES-4** Flag System Upgrade | ✓ **BUILT** | `SigningController::flagClause:3318-3334` creates `DocumentAmendment` `amendment_type=flag_raised` + `flag_origin=signing_party`; route `POST /sign/{token}/flag-clause` (`web.php:2877`). Every flag → amendment row (system of record) + mirror to `clause_flags` for refresh. |
| **ES-5** Editable-at-Signing | ⚠ **PARTIAL — verification found unresolved breaks** | Infra: `getEditableFieldsFromMappings` `SigningController.php:1214-1298`; JS conversion `sign.blade.php:1526-1534`. Audit `editable-by-runtime-investigation-2026-05-26.md`: template 111 has 16+ `editable_by` fields, BUT two runtime breaks remain — (1) Step 5 collapses multi-party arrays to single `assignedTo` (`ESignWizardController:3506-3531`); (2) signing view can't tell Seller 1 vs Seller 2 (both → `owner_party`) (`SigningController:1347-1385` + `sign.blade.php:1942`). Audit ends "ready for fix-design", **no fix applied.** End-to-end NOT verified working. |
| **ES-6** AI Template Import | ⚠ **PARTIAL** | Routes `/import/parse|cds|review|generate` (`web.php:2628-2632`); AI `claude-sonnet-4-6`+`gpt-4o-mini` (`ImporterAiService.php:84/123/149`). PDF accepted on Path A via `ImporterAiService::detectFromPdf:385` (native Anthropic PDF block) routed from `DocumentImporterController:143`. `insertable_blocks`/`other_conditions` in prompt (:304-349); `~~~~` emission (:341-342). **Gaps:** `ClaudeVisionParserService` is unwired scaffolding; Path B `/import/cds` still `mimes:docx` only (`DocumentImporterController:79`); 6.5 path-consolidation decision + 6.6 e2e test not evidenced. |
| **ES-7** Supervisor email | ✓ **BUILT** | `app/Mail/Signatures/SupervisorApprovalMail.php` + `emails/signatures/supervisor-approval.blade.php` — full copy, s.35 PPA reminder, CTA, expiry. Not a placeholder. |
| **ES-9** Insertable Conditions / Clause Library | ⚠ **PARTIAL** | Marker regex `CdsParserService.php:742` → `insertable_block_placeholder` (:803); 3 tables (`2026_05_22_010001`); JSON API `GET /docuperfect/api/clauses` (`web.php:2542`, `ClauseController:138`); builder picker `cds-builder.blade.php:350` + `insertClauseAtCursor:1031`; strikethrough → `410 Gone` (`SigningController:3515-3518`); agent review `AmendmentController`. **Gaps:** `docuperfect_clauses.category` column + `is_system` flag NOT added (no migration); system-default ~20-clause seed NOT built (library uses 21 pre-existing rows); pagination recalc DEFERRED (per build-notes); recipient-side clause picker intentionally replaced by free-text (1B.6 FIX1). |

**ES-8** (template library expansion) is "ongoing" by design — out of scope for a
done/not-done verdict.

**Net:** ES-1, ES-2, ES-3, ES-4, ES-7 fully built. ES-5 partial (unresolved
runtime breaks documented, not fixed). ES-6 and ES-9 substantially built with the
specific documented gaps above.

---

## 3. §22 shipped-claims verification (Phase 1B.5 / 1B.6 / 1B.8 / 1B.9)

All §22.8–§22.11 "shipped" claims verified **SHIPPED** in code, with one
spec-text divergence (FIX2 predicate) flagged.

### §22.8 — Phase 1B.5 (signing-view embeds): **SHIPPED**
- `InsertableBlockRenderer::renderInDocument` threaded into `SigningController::show()` right after `LetterheadRefresher::refresh()` — `:307` then `:316`. Correct ordering.
- Routes present: `/sign/{token}/conditions` (`web.php:2870`), `/initial-amendments` (`:2871`), `/strikethroughs` (`:2881`).
- View-switch to `external/initialing.blade.php` on `STATUS_AMENDMENT_INITIALING` — `SigningController.php:106-110` → `showInitialingView:3152`.
- `LegacyOtherConditionsBridge` invoked from `ESignWizardController.php:2036` & `:4436`; backfill `2026_05_22_120001`.

### §22.9 — Phase 1B.6 (six fixes): **SHIPPED**
- FIX1: `addCondition()` forces `source='custom'`, `library_clause_id=null` — `SigningController.php:3190-3191`.
- FIX2: `proposeStrikethrough()` returns `410 Gone` (`:3515-3518`); `flagClause()` creates flag amendment (`:3322-3323`); route `/flag-clause` (`web.php:2877`).
- FIX3: `InsertableBlockRenderer.php:195-196` emits `<ol style="list-style: decimal outside">`.
- FIX4: migration `2026_05_22_140001`; `extractNumberedClauses` `SigningController.php:3066`.
- FIX5: `show()` passes `partyAlreadySigned` (:443) + `inAmendmentInitialing` (:444); banners `external/sign.blade.php:244,272`.
- FIX6: `persistedClauseFlags` seeded from `web_template_data.clause_flags` (:406/:442); `flagClause()` writes through immediately in txn (:3340-3352).

### §22.10 — Phase 1B.8 (dropdown removed): **SHIPPED**
- "Relates to existing clause" `<select>` gone from `_partials/add-condition-modal.blade.php` (only a stale header comment remains, line 5); `relates_to_clause_ref` column retained.

### §22.11 — Phase 1B.9 (three fixes): **SHIPPED**
- FIX3 (CRITICAL — signature reset on Add Condition): `addCondition()` returns `rendered_row` in 201 JSON (`SigningController.php:3264-3270`) via public `renderConditionRowPublic` (`:3255`); modal appends in place via `_appendConditionRow` (`add-condition-modal.blade.php:203/222/270`); `location.reload()` removed (referenced only in comment).
- FIX2 (apply-to-all agent-only): `isAgent` computed in `show()` and both triggers gated `this.isAgent` (`sign.blade.php:2553-2560`, `:3349-3352`). **DIVERGENCE:** live predicate is `party_role==='agent'` **alone** — NOT the spec's `hasPermission('manage_documents') || party_role==='agent'`. A code comment (`SigningController.php:416-431`) records the OR-permission form as a security bug removed per the 2026-05-27 reset audit Q4. **Code is correct; the spec sentence is stale.**
- FIX1 (flag undo): `DELETE /sign/{token}/flag/{clauseRef}` → `removeOwnFlag:3421` (pre-completion gate :3430); `flag_removal_requests` table (`2026_05_23_100001`); `FlagRemovalController` 3 methods + routes (`web.php:2888/2892/2895`).

---

## 4. §20 resolver — TRUE STATE

> **This is the headline correction. The prompt's premise — "confirm §20.1–20.9
> (the SigningSurfaceResolver core) is NOT implemented" — is FALSE. The resolver
> class IS built AND live-wired.**

### 4.1 The resolver class is BUILT and LIVE-WIRED

`app/Services/Docuperfect/SigningSurfaceResolver.php` (290 lines) implements the
§20.1–20.6 logic: `resolve():57` (1) re-keys every `data-marker-party` to a
canonical recipient key (family-collapse, numeric suffix preserved) and (2)
injects a signature surface for any recipient that has none after re-keying
(`buildCanonicalRecipients`, `findOrCreateSignatureSection`, `buildSignatureBlock`).
Fail-open: any error returns original HTML unchanged.

It is **instantiated and called at TWO sites inside the `prepareSigning` snapshot
build, both before `merged_html` is persisted** (verified directly):

- **Single-doc path** — `ESignWizardController.php:1858-1859`:
  `app(SigningSurfaceResolver::class)->resolve($bodyHtml, $recipients, $user->name, $isSalesContext)`;
  result stored to `webTemplateData['merged_html']` at `:1868`.
- **Pack path** — `ESignWizardController.php:1681-1682`: same call per segment,
  before the no-surface guard (`countSignableSurfaces===0` throw at `:1684`) and
  before concatenation into `$mergedHtml` (`:1692`).

So the resolver output IS the persisted `merged_html`. **NOT dead, NOT unwired.**

### 4.2 What this maps to in the audit's §20 numbering

- The resolver **class itself** (§20.1–20.6 re-key + inject behaviour) is the
  **APPROVED slice** described by **§20.10**, which explicitly says the resolver
  is "DEMOTED — not deleted — to (i) a re-key guard for legacy/mismatched markers,
  and (ii) a fail-safe inject only if a recipient STILL has no surface." That is
  *exactly* the wired behaviour. The class header comment even states the
  compensators "are intentionally NOT removed here — they are retired in a
  separate follow-up once this resolver is verified."
- The **full §20.1–20.9 invariant** — which additionally requires **deleting the
  three compensators** (CDS-compile name heuristic; live-fallback context branch;
  fuzzy `isMyWebSigBlock` alias groups), enforcing **exact-match-only**, and
  making the resolver the **sole placement mechanism** — is **NOT fully realized**:
  - `isMyWebSigBlock` (`external/sign.blade.php:2788`) is still **suffix-gated
    fuzzy alias-group matching** (owner/acquiring/agent term groups at :2804-2809),
    NOT exact-canonical-key-only as §20.5/§20.8.5 require.
  - The live-fallback branch and CDS-compile heuristic are still present (the
    primary placement mechanism is the per-recipient component loop, §20.10, NOT
    the resolver).

**Bottom line:** The §20 **resolver is built and live as the APPROVED §20.10
demoted guard.** The broader §20.1–20.9 "delete the compensators, exact-match
only" framework remains **NOT APPROVED / NOT done** — consistent with the audit's
own status markings. So the correct statement is *not* "the resolver core is not
implemented" but "**the resolver core is implemented and wired in its approved,
demoted form; the full compensator-deletion invariant is not.**"

### 4.3 §20.10 per-recipient loop — IMPLEMENTED

- `web-templates/components/signature-line.blade.php:21-31` loops
  `$recipientsForParty` (one line per recipient, suffix-keyed `_2`, `_3`).
- `web-templates/components/signature-block.blade.php:45-75` builds
  `$expandedParties` over `$recipients_by_role[$roleKey]`, one cell per recipient.
- `recipients_by_role` keyed by **concrete** role (seller/landlord) at the build
  site — `ESignWizardController.php:1776-1798` maps owner→seller/landlord and
  acquiring→buyer/tenant via `isSalesDocument()`, not generic `owner_party`.

### 4.4 §20.11 four template fixes — IMPLEMENTED (none static single-party)

- `cds/template-119.blade.php:17` → `signature-block ["parties"=>["Seller","Agent"]]`.
- `cds/template-120.blade.php:17` → `signature-line ['party'=>'seller']` + `['party'=>'agent']`.
- `cds/template-117.blade.php:38` → `signature-line ['party'=>'seller']` + `['party'=>'agent']`.
- `sales-mandatory-disclosure.blade.php:63` (tpl 123) → `signature-line ['party'=>'seller']` + `['party'=>'agent']`.

All four confirmed seller+agent — none is a static `['party'=>'agent']`-only point.

---

## 5. Bug status (V2 #1–6 + ES-2)

| Bug | Status | Evidence |
|---|---|---|
| **#1** Contact filtering (no esign_role filter; skips rental; hardcoded search map) | **FIXED** | `ESignWizardController.php:502,512-514` (filters by `buildAllowedEsignRoles` from template `signing_parties`); rental auto-populate `:550-577`; search API esign_role map `:1124-1168`; `ContactType::scopeForEsignRole():22`. |
| **#2** Rental docs show sales fields (Layer 3 name-pattern overrode property source) | **FIXED** | `Template::isSalesDocument():282-316` — property source now Layer 3 (:307-309), evaluated **before** name-pattern Layer 4 (:311-315). Priority inverted correctly. |
| **#3** My Documents menu error | **NO DEFECT FOUND** | Route `docuperfect.esign.myDocuments` (`web.php:2598`); controller `:4702`; view returned `:4762`. Spec marked UNCLEAR/transient. |
| **#4** Duplicate `document_contact` race (exists()+insert()) | **FIXED** | `SignatureService.php:1783` now `DB::table('document_contact')->updateOrInsert([keys],[values])`. Atomic; old pattern gone. |
| **#5** Initials missing from final PDF | **FIXED** (live path) | Web path embeds initials: `SignatureController::embedInitialsIntoHtml:1636` (called :1423), signer side `SigningController.php:1446`; PDF from `merged_html`/`signed_paginated_html` (`SignaturePdfService.php:32-42`). Residual `in_array($type,['signature','initial']){continue;}` at `SignaturePdfService.php:323-325` is in the **deprecated DomPDF image-overlay path only**, not the live web flow. Matches §19.7. |
| **#6** Ellie bubble overlaps Next button | **FIXED** (root cause removed) | Floating `ellie-trigger` (`bottom:24px;right:24px`) in `layouts/partials/ellie-widget.blade.php:5` no longer `@include`d in any active layout; external signing view is standalone (no layout); agent view uses sidebar-icon `help-widget` (`corex.blade.php:139`), no fixed floating button. |
| **ES-2** FICA orderBy non-determinism | **FIXED** | `SigningController.php:134-135` + `ESignWizardController.php:2098-2099` ordered. (See §2.) |

Note: the prompt named `SignWizardController` as a second FICA query site; that
class does not exist — the two real sites are `SigningController` and
`ESignWizardController`, both fixed.

---

## 6. Remaining work (verified) — prioritised

See the matching "REMAINING WORK" section appended to
`.ai/specs/esign-v3-complete-spec.md`. In short:

1. **ES-5 editable-at-signing** — fix the two documented runtime breaks
   (multi-party `editable_by` collapse in wizard Step 5; Seller-1-vs-Seller-2
   identity in the signing view). This is the only ES-item with a **known
   functional defect** rather than a deferral. **No §20 dependency.**
2. **ES-9 residue** — `docuperfect_clauses.category` + `is_system` columns;
   system-default ~20-clause seed; pagination recalc (currently deferred —
   final-flatten covers it).
3. **ES-6 residue** — decide Path A vs Path B consolidation (6.5); allow PDF on
   the CDS-marker path or retire `ClaudeVisionParserService` scaffolding; run the
   6.6 end-to-end test on the HFC document set.
4. **§20 full invariant (NEEDS JOHAN APPROVAL — §20.1–20.9 still NOT APPROVED)** —
   only if pursued: retire the three compensators (CDS-compile heuristic,
   live-fallback context branch, fuzzy `isMyWebSigBlock`) and reduce
   `isMyWebSigBlock` to exact-canonical-key match. The resolver guard already
   ships; this is the cleanup the audit gated behind sign-off. **Not required for
   correctness today** — the demoted guard + per-recipient loop already produce
   correct surfaces.
5. **Housekeeping (non-e-sign-functional):** the CLAUDE.md / `dev-check.ps1`
   pipeline-gate list references `SurfaceNormalizer.php`, which does not exist
   (live file is `SignatureSurfaceNormalizer.php`).

---

## 7. Recommended next build item

**ES-5 — Editable-at-Signing repair.** It is the single item with a verified,
documented *functional* break (two runtime defects, fix-design already scoped in
`editable-by-runtime-investigation-2026-05-26.md`), it has **no §20 approval
dependency**, and "infrastructure exists but is broken end-to-end" is exactly the
half-built state the CoreX Operating Principle forbids shipping. Everything else
remaining is either a deferral that degrades gracefully (ES-9 pagination recalc,
ES-6 consolidation) or gated behind Johan's §20.1–20.9 approval.

---

*End of reconciliation — 2026-06-10. No application code, migrations, views, or
tests were modified in producing this document.*
