---
name: esign-and-documents
description: E-sign and web-document rules - signing-view state preservation (P0), document fidelity, the pipeline files that require SigningView tests, document importer lessons, and the cds template files that must never be stashed. Use when touching DocuPerfect, e-signature, the signing view, document import/rendering, or PDF generation.
---
# E-sign and documents

Read `.ai/specs/docuperfect.md` AND `.ai/specs/esignature.md` first.

## Document fidelity (non-negotiable)
A web document rendered to PDF is character-for-character the intended legal document: no autocorrect, no smart quotes, no rewording, no reformatting. All documents are HTML/Blade rendered by Puppeteer (`scripts/html-to-pdf.mjs`); DomPDF is dead and must not be introduced anywhere new. PDF "garbage" characters are usually Word private-use bullets (normalised in `scripts/lib/pdf-glyph-normalise.mjs`), not encoding.

## Signing-view state is authoritative (P0 invariant)
Recipients spend 5-15 minutes signing; one re-render destroys the work. State lives server-side (`party.signed_at`, `signature_locked_at`, captured signatures, field values) and is hydrated into the page by server-rendered Blade. **FORBIDDEN during a signing session:** `location.reload()` after AJAX; re-fetching the signing view from JS; re-rendering signature/initial/field widgets from JS; resetting Alpine `x-data` on partial updates. **Required pattern for inline mutations** (add condition, flag clause, capture initial): client POSTs -> controller returns JSON with a `rendered_row`/`rendered_html` for ONLY the new/changed element -> client appends/replaces only that node -> nothing else touched. Canonical: `SigningController::addCondition()` + `InsertableBlockRenderer::renderConditionRowPublic()` + `add-condition-modal.blade.php` `_appendConditionRow()` (commit `bb6cc9f`).

## Pipeline gate
Any change to these files must come with a test diff in `tests/Feature/Docuperfect/SigningView/` (or its fixtures/trait): `Models/Docuperfect/Template.php`, `Models/Docuperfect/CdsDraft.php`, `Services/Docuperfect/{SignatureSurfaceNormalizer,LetterheadRefresher,InsertableBlockRenderer,RoleBlockDetectionService,RoleBlockExpansionService,RoleBlockNormalizer,MergedHtmlFreshnessGuard}.php`, `Http/Controllers/Docuperfect/SigningController.php`. (49 unit tests were green while five live bugs shipped; the audit is `.ai/audits/esign-reset-investigation-2026-05-27.md`.) The old `-SkipPipelineGate` bypass is only for a docs follow-up whose test diff landed earlier.

## Document importer lessons
Blank positions in the HTML are FIXED - only assignments shift (shift-assignments, not insert-blank). When AI misses one blank all later fields shift wrong. Send BOTH `context_before` AND `context_after` (SA lease documents have blanks BEFORE their labels). Mammoth for HTML, Claude/OpenAI for field detection only. Claude API errors: run `php artisan config:clear` before assuming the key is wrong.

## Deploy hazard
`resources/views/docuperfect/web-templates/cds/template-*.blade.php` are runtime-generated and load-bearing: never stash/move them to satisfy a dirty-tree check (skill `qa-deploy-and-deploy-dir`).
