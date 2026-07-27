# E-sign Other Conditions — Step 2 reusable frame model (2026-07-27)

Branch `esign-input-followup`. QA1-only. Builds on Step 1 (`1c1a306b` — per-condition
initials baked into the print artifact as adopted ink). Design = Johan's clarified spec
(discrete "frame" model, keep the agent-review gate).

## Design (as approved by Johan)
- **One "Add condition" = one frame = one `document_conditions` row**, free text within a
  frame, each frame initialled separately by every party (all-party per-frame initials =
  Step 1 adopted-ink render).
- **Screen-only "one condition at a time" guidance** near the add control — a soft hint,
  NOT hard validation; never in the print-from-approved canonical/PDF.
- **Agent-only clause-library insert**: the agent's other-conditions block offers
  insert-from-clause-library, each inserted clause becoming its OWN frame. Recipients get
  free-text only (no library) — already enforced server-side (`SigningController::addCondition`
  forces `source=custom`, `library_clause_id=null`).
- **KICKER, gate KEPT**: a recipient adds a condition mid-signing → the AGENT reviews/approves
  (AT-322 / ESIGN-CANON legal gate preserved) → `requeueAllPartiesForInitialing` re-engages
  ALL parties incl. already-completed ones to initial that frame (fresh token, focused
  initialing view, signature untouched) → finalize when the last owed initial lands, PDF
  regenerates with every per-frame initial baked in. Gate-less path deliberately NOT built.

## What already existed (reused, not rebuilt)
- `document_conditions` (row-per-condition) + append-only `condition_initials` (party_key +
  ip + user_agent + initialed_at + amendment_id — full audit) + `docuperfect_clauses` clause
  library (ClauseController CRUD + `docuperfect.clauses.json`).
- `InsertableBlockRenderer` renders the block + per-party initial slots; Step 1 renders a
  filled slot as the party's adopted ink (`resolveAdoptedInitial`) and bakes it into the
  canonical via `CanonicalDocumentRenderer::refreshInsertableBlocks`.
- Re-engagement cascade: `SigningController::addCondition` → amendment `pending_review` +
  `STATUS_AMENDMENT_REVIEW` → `AmendmentController::approve` → `requeueAllPartiesForInitialing`
  → `STATUS_AMENDMENT_INITIALING` → completed parties re-engaged (emailed, fresh token) →
  `initialAmendments`/`initialCondition` → finalize.

## Changes this build
1. **Disclosure wired** — `web-templates/cds/template-123.blade.php`: added
   `~~~~OTHER_CONDITIONS~~~~` before the signature section (same unbound-marker pattern as
   the Exclusive mandate template-111). Reusable block now attaches to BOTH.
2. **Screen-only guidance overlay** — `InsertableBlockRenderer::injectAddConditionGuidance()`:
   a display overlay (like `stampConditionSigningToken`) that inserts a `no-print`
   `data-screen-only="1"` guidance div before each `.btn-add-condition`. NOT baked into the
   canonical. Applied on the recipient screen in `SigningController::show` (after the token
   stamp). Idempotent.
3. **PDF never prints chrome** — `SignaturePdfService::injectInitialsPagination` boot JS now
   strips `.btn-add-condition, .condition-add-guidance, [data-screen-only]` from the print DOM
   before pagination. Guarantees the guidance (and the add button) never reach the PDF
   regardless of what the served canonical held. Print-path only; screen untouched.
4. **Agent discrete-frame editor** — wizard Step 5 (`esign/wizard.blade.php`): replaced the
   single free-text textarea with a frames editor — "+ Add condition" (one free-text frame),
   "+ Insert from clause library" (agent-only; each clause = its own frame), per-frame remove,
   screen-only guidance. New Alpine state `otherConditionFrames`, methods `addConditionFrame`
   / `removeConditionFrame` / `syncFramesToText`; `insertClause` now pushes a library frame;
   restore migrates a legacy `other_conditions_text` blob into frames; step-5 serialization
   sends `other_condition_frames`. `other_conditions_text` still derived (frames joined by
   `\n\n`) for backward-compat.
5. **Precise per-frame persistence** — `LegacyOtherConditionsBridge::syncFramesToStructuredRows()`:
   one `document_conditions` row per frame (never a blank-line re-split), preserving
   `source`/`library_clause_id` provenance; idempotent; bridge-owned (never clobbers
   recipient rows). Wired into both wizard create paths (standard + wet-ink); the legacy text
   bridge remains the fallback when no frames are submitted.
6. **Double-render guard** — the 3 legacy body-injection sites in `ESignWizardController`
   (`insertBeforeSignatureSection`) now SKIP when the body carries an `OTHER_CONDITIONS`
   marker (the renderer expands it to rows) — prevents conditions rendering twice on a
   marker template once the agent populates them.
7. **"New condition" email variant** — `SignatureService::requeueAllPartiesForInitialing`
   sends a condition-specific re-engagement message when the approved amendment is a
   `TYPE_ADDITION` ("A new condition was added … please initial the new condition … your
   original signature stays in place").

## Local proof (dev DB, transaction rolled back)
- Frames → 2 rows, one per frame; provenance preserved (custom/null, library/clause-id);
  second identical sync = 0 (idempotent); recipient-added rows untouched; changed set
  replaces prior agent rows.
- Renderer (recipient context): 2 discrete `condition-row`s, per-party initial slots for both
  seller + agent, add button present, guidance NOT in the canonical block render.
- Overlay: guidance injected exactly once, `data-screen-only` + `no-print`.
- Both edited Blades compile clean; all changed PHP `php -l` clean.
- Test: `tests/Feature/Docuperfect/SigningView/OtherConditionsFramesTest.php` (pins guidance
  overlay, frames→rows provenance/idempotency, per-frame slots, PDF screen-only strip).

## Files
- `resources/views/docuperfect/web-templates/cds/template-123.blade.php`
- `app/Services/Docuperfect/InsertableBlockRenderer.php`
- `app/Http/Controllers/Docuperfect/SigningController.php`
- `app/Services/Docuperfect/SignaturePdfService.php`
- `resources/views/docuperfect/esign/wizard.blade.php`
- `app/Services/Docuperfect/LegacyOtherConditionsBridge.php`
- `app/Http/Controllers/Docuperfect/ESignWizardController.php`
- `app/Services/Docuperfect/SignatureService.php`
- `tests/Feature/Docuperfect/SigningView/OtherConditionsFramesTest.php`

## Raw-marker re-bake fix (follow-up, required for the real templates)
`CanonicalDocumentRenderer::refreshInsertableBlocks` (Step 1) bailed early when the template
had no `insertable_blocks` metadata — but the REAL Exclusive mandate / Mandatory Disclosure use
a raw `~~~~OTHER_CONDITIONS~~~~` marker (unbound-marker fallback), so it was a no-op for exactly
those templates → a per-frame initial captured after v1 (the KICKER re-engagement) never reached
the stored canonical/PDF. Fixed: when metadata is absent, synthesise the block list from the
already-rendered `<div class="insertable-block" data-block-id=…>` nodes (id + data-purpose +
data-auto-number) and re-bake those. Regression test:
`OtherConditionsFramesTest::test_refresh_bakes_late_initial_into_raw_marker_canonical`.

## QA1 END-TO-END PROOF — DONE (2026-07-27, corex_qa1)
Deployed to QA1 (origin/QA1), seeded the disclosure (template #71, blade template-123 w/ marker),
added the same one-line marker to QA1's live Exclusive-mandate blade (template-67, clause 2.8) so
the proof runs on QA1's actual mandate. Real persistent proof docs created; `SignaturePdfService`
download route renders live from the baked canonical (no pre-generated PDF needed).

**26/26 assertions passed** (script `scratchpad/qa1_proof.php`), on BOTH templates:
- Scenario A (agent frames): 2 frames → 2 rows; frame 2 carries clause-library provenance;
  SCREEN + PDF both show both frame texts and 4 per-frame initials as ADOPTED INK; guidance not in
  canonical; PDF strips add-button + guidance; exactly 2 seller + 2 agent slots (no drop/bleed/wrong-party).
- Scenario B (KICKER, mandate doc 468): recipient frame → amendment PENDING + AMENDMENT_REVIEW →
  agent approves → template AMENDMENT_INITIALING, completed parties re-issued fresh tokens,
  re-engagement email sent using the "new condition" variant → each re-engaged party initials just
  the new frame → finalized doc has 3 frames, SCREEN + regenerated PDF both carry the new frame with
  6 ink initials, chrome stripped.

**Johan can open (QA1, logged in):**
- Exclusive mandate — review `https://qatesting1.corexos.co.za/docuperfect/documents/468/signatures/review`,
  PDF `…/documents/468/signatures/download`
- Mandatory Disclosure — review `…/documents/469/signatures/review`, PDF `…/documents/469/signatures/download`

# STEP 3 — real tick marks + government-form fidelity + PACK (2026-07-27)

## Changes
- **Ticks:** the disclosure answer now renders a real TICK ✓ in the chosen
  YES/NO/N-A cell (was a filled circle ●). Swapped at every emit site —
  `disclosure-logic.blade.php` (live signing) and `restoreStoredDisclosure`
  in `a4-page-styles.blade.php` (agent-review + PDF). The review screen and the
  PDF embed the SAME restore JS, so the tick is identical on both (screen == PDF
  by construction). On the review/PDF artifact unchosen cells print BLANK (only
  ticks show, like the prescribed form); live signing keeps empty ○ targets.
- **Gov-form layout:** `corex-document.css` — crisp ruled grid (1px dark full
  borders) + bold near-black tick on `[data-selected="true"]`. The CSS is inlined
  into the PDF (`SigningController::wrapHtmlForPdf`) and linked on the review
  screen, so the grid + tick match on both. Letterhead stays ours (company-header).
- **Template cleanup:** `template-123` had a duplicated checklist caption →
  collapsed to one, and the agent-only "completed by recipient at signing" note is
  now `no-print` so the printed form is clean.

## PACK (mandate + disclosure)
- Disclosure registered (`SalesMandatoryDisclosureEsignSeeder` → QA1 template #71).
- `esign:compose-sales-mandate-pack --agency=1 --apply` → **"Sales Mandate Pack
  (CANDIDATE)"** wired with the Exclusive mandate (#67) + Mandatory Disclosure (#71)
  (+ letting mandates as selectable mandate variants). FICA slot legitimately empty
  (FICA is the Compliance module, not a DocuPerfect template — Johan's call).

## QA1 proof — 16/16 assertions (scratchpad/qa1_proof_step3.php)
- Ticks: review/PDF restore emits ✓ (no ● left), live logic ticks ✓, gov-form
  grid + bold tick styling present. Doc 469 given real disclosure answers
  (`disclosure_doc_0..`) → PDF pipeline embeds the tick JS + the answers, and a
  real PDF was generated (Chromium ran the tick JS end-to-end).
- Pack: composed with mandate + disclosure; the merged canonical (doc 470) carries
  BOTH `.corex-document-wrapper` segments (distinctly `data-disclosure-doc`-keyed),
  the mandate heading + the disclosure condition-report table, both
  `~~~~OTHER_CONDITIONS~~~~` markers expanded to insertable blocks, and
  `splitMergedHtml` splits it back into 2 per-template documents (mandate, disclosure).

**Johan can open (QA1, logged in):**
- Disclosure ticks + gov-form — review `…/documents/469/signatures/review`, PDF `…/documents/469/signatures/download`
- PACK merged doc — review `…/documents/470/signatures/review`
- Web Pack config — Documents → Web Packs → "Sales Mandate Pack (CANDIDATE)"

Regression: `DisclosureTickRenderTest` (glyph + gov-form styling) + the Step-2
`OtherConditionsFramesTest` 8/8.

## Still open (future)
- FICA e-sign template / pack slot — Johan's decision.
- Pack conditions share one `block_id` ('other_conditions') across segments — if
  per-segment other-conditions are ever wanted, that's a follow-up (out of scope now).
- Jira ticket for the whole e-sign pack — HELD per Johan (ticket once complete).
