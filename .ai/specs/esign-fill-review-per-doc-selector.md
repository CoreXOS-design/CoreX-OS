# E-sign fill-and-review PER-DOCUMENT other-conditions selector — handoff spec

Branch `esign-input-followup`. QA1 only. This is the ONE remaining piece of the
other-conditions work (Johan's "failure 5" residue). Everything else Johan tested is
FIXED, committed, and live on serving QA1 (see "Already done" below). Do NOT re-fix
those — build only the selector described here.

## Goal
In the document-creation wizard's **FILL & REVIEW** stage (the PRIMARY place agents add
other-conditions), when the document is a PACK (≥2 documents), the agent must be able to
**tag each other-condition with the target pack document**. That condition must then render
**only on that document** during signing (never on the other pack documents).

Single-document flow already works end-to-end (agent adds a frame in Step 5 → it renders on
the one document). This task adds the per-document tagging for the PACK case.

## Where it lives + what to build
1. **Wizard Step 5 UI** — `resources/views/docuperfect/esign/wizard.blade.php`.
   - The frames editor already exists: Alpine state `otherConditionFrames` (array of
     `{content, source, library_clause_id, clause_name}`), methods `addConditionFrame()`,
     `removeConditionFrame(i)`, `syncFramesToText()`, `insertClause(clause)`; serialized in
     step 5 as `other_condition_frames` (see the `case 5:` serializer).
   - ADD a per-frame **document selector** shown only in the pack flow. Options = the pack's
     documents (from the pack's `template_ids` + each template's name — available in the
     wizard's pack flow / `serverStepData`). Store the chosen target on each frame, e.g.
     `frame.target_template_id` (or pack position). For a single-doc flow, hide the selector
     (implicit single target).

2. **Merge-time mapping (the chicken-and-egg to solve)** — pack docKeys are minted at MERGE,
   not at Step 5:
   - `ESignWizardController::stampDisclosureDocKeys()` stamps each `.corex-document-wrapper`
     with `data-disclosure-doc="<random10>"`.
   - `ESignWizardController::scopePackOtherConditionsMarkers()` rewrites each segment's marker
     to `~~~~OTHER_CONDITIONS__<docKey>~~~~` (forward pass: each marker takes the nearest
     preceding `data-disclosure-doc`).
   - So the agent tags by `template_id` at Step 5; at CREATE/merge you must map
     **template_id → that segment's docKey → scoped block_id `other_conditions__<docKey>`**.
     Practical approach: the pack merge loop (`ESignWizardController` `$isPackFlow`,
     ~line 1664+) iterates `$templateIds` in order and builds each wrapper; capture, per
     template_id, the docKey assigned to its wrapper (stamp deterministically or record the
     order↔docKey map as you stamp). Then when persisting frames, route each frame to the
     scoped block_id of its `target_template_id`.

3. **Persistence** — `app/Services/Docuperfect/LegacyOtherConditionsBridge.php`.
   - `syncFramesToStructuredRows($sigTemplate, $frames)` currently writes all frames to a
     single `other_conditions` block_id. Extend it (or add a pack-aware variant) so each frame
     is written to its TARGET segment's scoped block_id (`other_conditions__<docKey>`), using
     the template_id→docKey map from step 2. Keep the single-doc path (bare `other_conditions`)
     unchanged/backward-compatible.
   - The two wizard create sites already call the bridge with `other_condition_frames`
     (standard path ~ESignWizardController:2168; wet-ink ~:4643).

## Machinery already built and PROVEN (reuse — do NOT rebuild)
- **Per-segment marker scoping** — `InsertableBlockRenderer::normalisePurposeToken()` +
  `synthBlockFromToken()` recognise `OTHER_CONDITIONS__<docKey>` → block_id
  `other_conditions__<docKey>` (bare token stays `other_conditions`). Regression test:
  `tests/Feature/Docuperfect/SigningView/OtherConditionsFramesTest.php::test_pack_other_conditions_are_scoped_per_document` (9 passed / 41 assertions).
- **Interactive on-document block** — `InsertableBlockRenderer::reRenderBlocksForViewer()`,
  called in `SigningController::show()`: re-renders each `.insertable-block[data-block-id]` in
  the viewer's `CONTEXT_RECIPIENT_SIGNING` context at DISPLAY time so the "+ Add condition"
  button + the current party's clickable initial slots are present (the stored canonical stays
  static for the PDF). This is why the on-document +Add + per-frame initials now work.
- **Per-frame all-party initials as adopted ink** — `renderInitialSlotsForCondition` +
  `resolveAdoptedInitial` (from `web_template_data.signed_initials`); baked into the stored
  canonical for print by `CanonicalDocumentRenderer::refreshInsertableBlocks()`.
- **Frames model** — one "+ Add condition" = one `DocumentCondition` row = one frame, free
  text within; `ConditionInitial` (append-only, party_key + ip + user_agent + timestamp).
- **KICKER re-engagement** (recipient-added condition → agent-review → all completed parties
  re-engaged to initial) via the existing amendment cascade — unchanged.

## Proof bar (SAME as the round that just passed — do NOT regress to DOM/tinker)
Debug BY RENDERING THE REAL PAGE. Use the headless-Chromium harness
(`scratchpad/shot.mjs` pattern: puppeteer at `/corex-qa1/node_modules/puppeteer`,
`executablePath:'/usr/bin/chromium'`, click "Sign Electronically" → consent → screenshot).
Deliver REAL rendered screenshots from https://qatesting1.corexos.co.za showing:
1. The agent, in fill-and-review, PICKING which pack document an other-condition belongs to.
2. That condition then rendering ONLY on the chosen document during signing (and NOT on the
   other pack document).
NOT DOM dumps, NOT tinker, NOT rolled-back transactions.

## Deploy standard (serving QA1)
Deploy to the SERVING tree `/corex-qa1`: fetch + checkout the changed files (EXPLICIT
pathspec — never `git add -A` on the shared tree), `php artisan optimize:clear` +
`view:clear`, `npm run build` if any Vite asset changed, and **`sudo systemctl reload
php8.2-fpm`** (opcache reload — a fix only in the worktree without an fpm reload will NOT
reach the browser; this bit us). Commit the QA1 working-tree changes to `origin/QA1` with an
explicit pathspec so they're durable. Keep all demo docs persistent, nothing rolled back.

## Current persistent testable docs (live on serving QA1) — agent-start /sign links
- MDF (frames Additional Information) — doc **472** — `https://qatesting1.corexos.co.za/sign/7qEYsSWc0k0R5Yi44arYBsEoQ0L2V4XTu4MXjHQF`
- Addendum B (in-document other-conditions) — doc **481** — `https://qatesting1.corexos.co.za/sign/w7VnfpL08BzB8WVzdFEYH4EOwCuPvdG4aJZ4m0MSbXLVdO902hlQEekcJ6jkdrOp`
- Pack (mandate + disclosure, per-document) — doc **477** — `https://qatesting1.corexos.co.za/sign/Oj4tMnH7TpwqkVfQM7tTwHlBk7cENPchs2f5hyIK1lo6EsaDtiAm17O0OToTqX1z`
- Review/PDF (logged-in) for any doc: `/docuperfect/documents/{id}/signatures/review` · `…/signatures/download`.
- Johan = user 46 (super_admin); agency 1; disclosure document_type slug `disclosure`.

## Already done in this line of work (committed, live — do NOT redo)
- Frames engine (Step 2): row-per-condition, all-party per-frame initials, screen-only "one at
  a time" guidance, KICKER re-engagement (agent-review gate kept).
- Step 3: real ✓ tick marks + gov-form disclosure fidelity.
- MDF rebuilt as the exact prescribed government form (10 sections verbatim, letterhead-only);
  ADDITIONAL INFORMATION powered by the frames engine.
- HFC Addendum B standalone template (`template-120`, seeder `HfcAddendumBEsignSeeder`),
  now with in-document frames marker.
- MDF two-phase foundation (seller-signed+filed → deferred purchaser via OTP; immutable seller
  ink; `Document::sellerSignedDisclosure()->forProperty()`). Spec:
  `.ai/specs/mdf-two-phase-signing.md`. OTP itself deliberately NOT built.
- Per-document pack scoping + interactive on-document block + Addendum B in-document render
  (this round) — commits `6b45fb2a`, `47e3fb7c`, `319ab829`, `550a97b4` on the branch;
  `6d96ded0` on origin/QA1; live on serving QA1.
