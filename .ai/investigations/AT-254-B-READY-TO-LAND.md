# AT-254 phase B — READY TO LAND

_2026-07-14. m3. Branch `AT-254`. MODE: BUILD (Johan-approved scope: split docs through the canonical
DealDocumentService path — one filing truth). Awaiting conductor GO for dual-deploy._

## What shipped (decision B)
Route the PDF splitter's create-and-attach through the canonical document spine, so a split OTP (and
every other split type) files by the **same rules** as a DR2 / e-sign filing of that type.

- **`app/Services/DealV2/DealDocumentService.php`** — new `fileClassifiedDocument(Property, $attrs,
  $destination, $contacts, User, ?DealV2)`. ONE `Document::create` → property/contact attach (explicit
  per-page contacts, party role = `contact_roles`, decision B) → AT-167 no-orphan/unfiled fallback →
  deal-step auto-complete via the engine. Wrapped in a transaction; the deal-side auto-complete is
  guarded (a splitter run never fails on deal wiring). Explicit `agency_id` stamp (AT-203 class).
- **`app/Http/Controllers/Tools/PdfSplitterController.php`** — `fileGroupsToDestinations()` refactored
  to a per-group orchestrator that funnels each group through `fileClassifiedDocument`. Removed the
  inline duplicate `Document::create` + attach + AT-167 fallback + `autoCompleteMatchingStep`. Removed
  the now-unused `App\Models\Document` import. Signature + result-count aggregation unchanged.
- **`tests/Feature/Tools/PdfSplitterDealPipelineTest.php`** (new) — proves splitter output enters the
  same pipeline as a DR2 filing.

## Spec-conformance
- **Req 1 — DR2 upload-docs untouched.** No change to `Dr2\DealDocumentController` or
  `createDealDocument`. ✔
- **Req 4 — a split OTP files by the SAME rules; route through the existing path, don't duplicate.**
  The splitter now calls the spine; create+attach lives in ONE method. ✔
- **Fix-the-class** — FICA/ID/POR and every other pack type flow through the single method; no forked
  create+attach remains in the splitter. ✔
- **Decision B held** — `contact_roles` stays the attach-on-file party truth; `DocumentDistributionMatrix`
  (AT-228 send rules) remains a distinct authority (not touched). ✔
- Deferred (unchanged, as planned): GAP 4 — the OCR classifier's 11-slug list is still hardcoded, not
  DB-derived. Not in scope for B.

## Verify chain (input paths proven, not just "tests pass")
- `php -l` clean: DealDocumentService.php, PdfSplitterController.php, PdfSplitterDealPipelineTest.php.
- `view:clear` + `route:clear` + `cache:clear` — clean.
- **`PdfSplitterDestinationRoutingTest` — 22/22 pass** (regression proof: many-to-many contact filing,
  no-orphan property fallback, **contact-only-no-contact → unfiled** (AT-167), source_id provenance,
  the real HTTP `link()` path with a deal-less split, multi-FICA kickoff + dedupe + permission gate).
- **`PdfSplitterDealPipelineTest` — 4/4 pass** (new):
  - `test_split_document_auto_completes_the_matching_deal_step` — split doc → deal step `completed` +
    `deal_step_documents.document_id` populated (the core proof).
  - `test_split_filing_matches_the_dr2_filing_outcome_for_the_same_type` — splitter vs DR2 path on
    twin deals → identical completed-step + one linked doc each.
  - `test_split_document_of_unmatched_type_completes_nothing_no_false_completion` — no false completion.
  - `test_deal_less_split_still_files_and_never_crashes` — the common no-deal path files + no crash.
- Scope note (CLAUDE.md #13): single-file targeting only; no broad suite run.

## Deploy plan (on conductor GO)
- **B is code-only — NO new migration.** No `migrate --path` for B.
- Decision A's migration (`2026_07_31_000001_consolidate_otp_document_type`, commit `8812f92b`) still
  needs its dual-deploy per the checkpoint: ff `AT-254` → Staging, deploy staging + qa1, surgical
  `migrate --path=.../2026_07_31_000001_consolidate_otp_document_type.php`, then Tinker-verify
  (`offer_to_purchase.is_active=0`, `otp.contact_roles` set, splitter classifies OTP→otp).
- After deploy: `view:clear`+`route:clear`+`config:clear`, reload the target pool, restart the worker.
- Env-parity: no new PHP extension used.

## Decision required from Johan — the FICA tick (restated; full report: `.ai/investigations/AT-254-splitter-fica-tick-default.md`)
1. **Component + lines** — checkbox `resources/views/tools/pdf_splitter_review.blade.php:219` (card
   215–235, posts via hidden `trigger_fica` at :256); logic in Alpine getter `ficaChecked` :511–514;
   kickoff `PdfSplitterController::kickoffMultiFica()` :834–898.
2. **Current default** — no fixed default: starts **TICKED** when the pack has a FICA/ID/POR page
   assigned to a contact who isn't already FICA-complete; **UNTICKED (disabled)** when no such page is
   assigned. Agent's click overrides. Gated by the `access_compliance` permission.
3. **What ticking triggers** — on submit, one wet-ink FICA verification **per distinct assigned
   contact** (reuses any in-flight one; else `FicaWetInkService::create` + attach ID/POR/form slots +
   fire submitted).
4. **Hardcoded vs setting** — the on/off default is a **hardcoded heuristic** (no agency setting, no
   wizard control); only each doc type's `fica_slot` (which pages count as FICA) is agency-configurable.
