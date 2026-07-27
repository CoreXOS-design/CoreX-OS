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

## Pending
- **QA1 end-to-end proof** (the deliverable Johan asked for before Step 3): deploy to QA1,
  register disclosure 123 (`db:seed --class=SalesMandatoryDisclosureEsignSeeder`), run a real
  mandate + disclosure ceremony proving (1) agent-added frames initialled by all parties &
  printed as adopted ink; (2) a recipient-added frame → agent approves → an already-completed
  party is re-engaged and initials just that frame; (3) screen == PDF per-frame, nothing
  dropped/bled/wrong-party.
- Step 3 (separate): real tick ✓ marks + government-form fidelity.
