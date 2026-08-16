# E-sign multi-doc PACK readiness — mandate + Mandatory Disclosure (QA1 audit, 2026-07-24)

AUDIT ONLY, no code changed. Assessing a first test of the Exclusive Authority to Sell
mandate + Mandatory Disclosure signed as one pack through the now-fixed web e-sign chain.

## 1. Pack mechanism — a real single-ceremony web merge EXISTS
`ESignWizardController::prepareSigning()` `$isPackFlow` (app/Http/Controllers/Docuperfect/ESignWizardController.php:1664-1819)
merges several **web** templates into ONE `merged_html`/canonical — one ceremony, each
template a `.corex-document-wrapper` stamped `data-disclosure-doc`; `SignatureService::splitMergedHtml()`
(app/Services/Docuperfect/SignatureService.php:2280) splits it back to file one PDF per template.
Driven by a WebPack (WebPackController + web_pack_items) → wizard `template_ids`. A compose
command exists: `esign:compose-sales-mandate-pack` (HD-3b) builds the "Sales Mandate Pack".
Front door tested (tests/Feature/ESign/WebPackStoreEndpointTest.php); the **merge interior +
a real mandate+disclosure ceremony is NOT covered by tests, and has NEVER been run on QA1**
(0 documents carry `template_ids`).

Two disclosure systems exist — **our fixes are web-path only:**
- OLD pdf-render disclosures: 61 `render_type=pdf` templates (all production disclosures), signed
  via the page-image DomPDF overlay path (docs 344/334/326… signed historically). NOT our fixes.
- NEW web disclosure blade (`web-templates/cds/template-123` a.k.a. sales-mandatory-disclosure),
  seeded by `SalesMandatoryDisclosureEsignSeeder`. Rides the web merge + our fixes.

## 2. Mandatory Disclosure template + field types
The web disclosure exists in CODE (blade + seeder) but is **NOT registered on QA1** (template 123
absent; only 5 web templates exist: 4 Letting Mandates + EATS mandate #67). `web_packs = 0` — no
pack configured. Field types on the web disclosure: signature, initial, ceremony (place/date/time),
and the **disclosure ANSWERS = a YES/NO/N-A table** (`.corex-radio-placeholder`) **plus a date
sub-answer** (`disclosure_..._date_n`). Shared signature block also carries **witness / witness-name**
markers. Answers are keyed PER-FIELD (`disclosure_<docKey>_<ordinal>`), seller-gated (PPA s70),
stored in `web_template_data['disclosure_answers']`, and **NOT baked into canonical** — re-applied
client-side by `restoreStoredDisclosure`.

## 3. Do the fixes cover it?
| Field type | Covered by the fixed chain? |
|---|---|
| Signatures / initials (per-anchor) | YES on web path — but validated ONLY on a SINGLE doc (459), never on a MERGED multi-doc pack |
| Ceremony place/date/time | YES (bakeInk) |
| Disclosure YES/NO/N-A answers | YES — `restoreStoredDisclosure` wired into BOTH review (review.blade.php:335) and PDF (SignaturePdfService.php:310), reads `disclosure_answers`; screen==PDF **provided** the canonical keeps the `data-disclosure-doc` stamp + YES/NO/N-A header text |
| **Disclosure DATE sub-answer** | **NO — captured (`disclosure_..._date_n`) but `restoreStoredDisclosure` does not restore/print it → a typed "if yes, when" date blanks on review/PDF** |
| **Witness / witness-name markers** | **UNVERIFIED — outside the fixed set; unknown whether they render/capture** |
| **Multi-doc per-anchor across 2 segments** | **UNVERIFIED — bakeInk positional index + paginate `data-anchor-seq` run across the WHOLE merged container; if the pack captures signatures per-doc rather than globally, a party's mandate vs disclosure signatures can mis-bind (the exact class we just fixed, in a context we did not test)** |

## 4. Gaps/risks for a first test
1. **Setup missing on QA1:** no web disclosure template, no composed pack, never run.
2. **Merged multi-doc path 100% unexercised** — per-anchor fixes proven single-doc only.
3. Disclosure **date sub-answer** not restored/printed.
4. **Witness** markers unvetted.
5. No automated coverage of the merge/disclosure interior.

## VERDICT: NO-GO for a first live test as-is
Not because the pipeline is broken, but because the pack isn't set up on QA1 AND the merged
multi-doc path (the thing being tested) is entirely unvalidated, with ≥2 concrete field gaps.
Sending Johan in now risks mis-bound signatures across the two docs, or a blank disclosure date —
exactly the embarrassment to avoid.

### Path to GO (all QA1)
1. Register the disclosure + compose the pack: `php artisan db:seed --class=SalesMandatoryDisclosureEsignSeeder`
   then `php artisan esign:compose-sales-mandate-pack --apply`.
2. I smoke-test a merged mandate+disclosure ceremony end-to-end (per-anchor across BOTH docs +
   disclosure YES/NO/N-A + date sub-answer + witness) the same way doc 459 was proven — headless,
   with unique markers.
3. Close whatever the smoke test bites (date sub-answer restore; witness; any multi-doc per-anchor
   mis-bind) — then Johan runs the supervised first test.

---

# ADDENDUM — Johan's 4 disclosure spec criteria (2026-07-24)

The live e-sign disclosure is the CDS web template `cds/template-123.blade.php`
(`SalesMandatoryDisclosureEsignSeeder`): a FIXED YES/NO/N-A checklist + a signature block.
A second, legacy blade `sales-mandatory-disclosure.blade.php` (`SalesMandatoryDisclosureSeeder`)
has one extra "additional information" textarea. Neither is registered on QA1.

**1. System document** — PARTIAL. Not a special "system document" class (no `is_system`); it is a
normal CDS `render_type=web` template with FIXED, server-authored form markup (hardcoded radio grid,
`template-123.blade.php:17`). Signatures/initials flow through the SAME chain as the mandate
(`SignatureSurfaceNormalizer:74` lists it → bakeInk → LetterheadRefresher). BUT the disclosure ANSWERS
bypass bakeInk — captured to `web_template_data['disclosure_answers']` and re-applied read-only by
`restoreStoredDisclosure`. So sigs/initials = canonical ink pipeline (our fixes apply); ticks = a
separate restore side-channel.

**2. Government-form fidelity** — DOES NOT MEET as specified.
 - Selected answer renders as a **filled circle ● (U+25CF), NOT a tick/check ✓** (`disclosure-logic.blade.php:133`;
   `a4-page-styles.blade.php:712,733`). If the requirement is a literal tick in the box, this fails.
 - Layout is a **reflowed web/A4 HTML table** (`corex-disclosure-table`), structurally faithful to the
   gov form but a web approximation, not a pixel-facsimile of the prescribed PDF.
 - Letterhead = OUR company header (correct — `company-header` include + `LetterheadRefresher`).
 - Screen==PDF: ● in both (restore runs in `SignaturePdfService:310` and `review.blade:335`) ✓ — but the
   glyph is ●, and it's an approximation, so "EXACTLY like the government form with ticks" is not met.

**3. Comments / other conditions (multi-seller free text)** — NOT MET.
 - The live e-sign disclosure (template-123) has **NO comments/other-conditions field at all**.
 - The legacy blade has ONE `additional_information` textarea → a **single shared scalar**
   `other_conditions_text` (`external/sign.blade.php:1527,3424-3496,3769`), overwritten by whoever edits;
   no multi-seller accumulation, no per-seller attribution. The disclosure grid itself is editable
   **only for the owner/seller** (`disclosure-logic.blade.php:19-38`). So "fillable by ANY seller,
   multi-seller" is not met.

**4. Dynamic per-condition initials** — NOT MET (two ways).
 - The disclosure has **no add-condition / insertable-block markup**, so "initials against each inserted
   condition" does not exist on the disclosure at all.
 - The GENERIC add-condition system (mandate/OTA — `add-condition-modal.blade.php`, `InsertableBlockRenderer`)
   DOES support it, and — good news for the keying concern — each condition's per-party initials are keyed
   by the **stable DB `condition_id` + `party_key`** (`ConditionInitial`, `InsertableBlockRenderer:385,440`),
   NOT by document order. So dynamic insertion/removal does **not** break the per-anchor binding we fixed
   (they're a disjoint system). HOWEVER: those per-condition initials are **explicitly suppressed in the
   flattened PDF** — `renderInitialSlotsForCondition` returns `''` when `context === CONTEXT_PDF_RENDER`
   (`InsertableBlockRenderer.php:393`). They render only in the interactive recipient view and persist as
   `condition_initials` rows, but are NOT baked into the PDF → **screen ≠ PDF for condition initials**, even
   where the feature exists.

## VERDICT (against the 4 gating criteria): NO-GO
- #1 partial; #2 fails (● not ✓, web approximation); #3 fails (no multi-seller comments); #4 fails on the
  disclosure (feature absent) AND, where it exists, the per-condition initials are dropped from the PDF.

## Gaps to close (all CODE changes, then QA1 proof)
1. **Disclosure content:** add a seller-fillable, multi-seller **other-conditions insertable block** to the
   disclosure template (it currently has none / a single shared textarea).
2. **Per-condition initials in the PDF:** `InsertableBlockRenderer::renderInitialSlotsForCondition` returns
   '' for `CONTEXT_PDF_RENDER` — the captured `ConditionInitial`s must be composed into the baked PDF (as
   ink), or they will never print. This is the single biggest fidelity break.
3. **Ticks:** decide ● vs a literal ✓/facsimile with Johan; if ✓ required, change the restore glyph +
   box styling.
4. Then the pack setup + multi-doc smoke test from the main audit above.
