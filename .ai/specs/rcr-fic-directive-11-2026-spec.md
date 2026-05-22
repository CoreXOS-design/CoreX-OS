# Spec — FIC Directive 11 of 2026 RCR Workflow
Status: Built (Phase 9d shipped 6c09e67; Phase 9d.1 remediation in progress)
Branch: HFC2402

---

## 1. Mission

CoreX is the **prep tool** for the FIC Directive 11 of 2026 Risk and Compliance Return. Elize works the RCR inside CoreX, auto-populates ~30 of ~168 questions from existing FICA/RMCP/deal/contact data, gives one answer per period where the FIC requires (P1/P2/P3), uses a per-question deep-view UI with big copy-to-goAML buttons, transposes into the FIC's goAML platform, and keeps an immutable snapshot for future RCR cycles + FIC audits.

Reporting period: 1 April 2023 → 31 March 2026.
Sub-periods (where the FIC asks for one answer per period):
- P1: 2023-07-01 → 2024-03-31
- P2: 2024-04-01 → 2025-03-31
- P3: 2025-04-01 → 2026-03-31

Submission deadline: 31 July 2026.

---

## 2. Locked decisions

1. CoreX does NOT submit to goAML directly — Elize transposes manually.
2. Questionnaire content lives in the database (FIC seeder + CSV import). Future FIC revisions = new CSV, no code change.
3. Three answer rows per (question × period) for sections with `has_period_columns = true`; one `'static'` row otherwise.
4. Copy-to-goAML is the workflow — server-side click logging + auto-clear "Pasted" on re-copy.
5. Snapshot is immutable. Set on submit. SHA-256 questionnaire structure hash + full denormalised state.

---

## 3. Schema reference

**Migrations (chronological):**
- `2026_06_05_080001` → `…080007` — Phase 9d base schema (7 tables)
- `2026_06_06_080001_add_period_and_clipboard_tracking_to_rcr_tables` — Phase 9d.1 additive columns

**Phase 9d.1 columns added:**

`rcr_questionnaire_sections`:
- `has_period_columns` (bool default true)
- `applies_when_json` (json nullable)

`rcr_questions`:
- `parent_code` (string nullable, indexed) — sub-question linkage (1.29.1 → 1.29)
- `footnote` (text nullable)
- `evidence_source_codes_json` (json nullable) — array of source codes
- `auto_populate_hint` (text nullable)

`rcr_answers`:
- `period_code` (string 16, default 'static')
- `copied_to_clipboard_at` (timestamp nullable)
- `copied_to_clipboard_count` (uint default 0)
- `transposed_to_goaml_at` (timestamp nullable)
- `final_answer_format` (string 32 nullable)
- Unique constraint: `(submission_id, question_id, period_code)` — replaces 9d's `(submission_id, question_id)`

`rcr_submissions`:
- `transposed_to_goaml_at` (timestamp nullable) — set when every answer transposed.

---

## 4. Source code catalogue (27 sources, rewired to FIC codes)

| Source code | Wired to (FIC) | Status |
|---|---|---|
| agency.profile | inst.legal_name, inst.business_address, inst.contact_number, inst.email, inst.location | ✓ |
| agency.fica_officer.primary | inst.representatives, 1.26, decl.signatory_name | ✓ |
| agency.fica_officer.mlro | inst.representatives | ✓ |
| agency.fica_officer.alternate | — (unwired; FIC has no clean question) | kept |
| rmcp.exists | 1.24 | ✓ |
| rmcp.last_reviewed | (manual hint only) | kept |
| rmcp.sections_count | 1.24 | ✓ |
| rmcp.acknowledgements_complete_pct | 1.27 | ✓ |
| cdd.completed_in_period | 1.28 | ✓ |
| cdd.outstanding | (manual hint) | kept |
| cdd.high_risk_count | (manual hint) | kept |
| edd.pep_screenings | 1.5, 1.8 | ✓ |
| edd.sanctions_screenings | 1.21, 1.22 | ✓ |
| str.filed_count | 1.59, 3.20 | ✓ |
| str.flagged_unfiled | (manual hint) | kept |
| training.completed_pct | 1.27 | ✓ |
| training.modules_available | (manual hint) | kept |
| training.last_session_date | (manual hint) | kept |
| transactions.total_count | 8.4 | ✓ |
| transactions.total_value | — (no clean FIC question) | kept |
| transactions.high_value_count | 8.9 | ✓ |
| transactions.foreign_party_count | (manual hint) | kept |
| mandates.by_type | — (no clean FIC question) | kept |
| mandates.cancelled_count | — | kept |
| governance.last_compliance_committee_meeting | — | kept |
| governance.compliance_reports_generated | — | kept |
| audit.last_independent_review | — | kept |

Auto-populate target period: P3 (most recent) for period-bound sections; `static` for others. CO copies P3 → P1/P2 manually when relevant.

---

## 5. UI navigation

```
Compliance (sidebar)
  └─ RCR · FIC 2026
       ├─ /corex/compliance/rcr                            (list submissions)
       ├─ /corex/compliance/rcr/{submission}               (submission show — section grid)
       └─ /corex/compliance/rcr/{submission}/question/{code}  (deep view — copy-to-goAML)

Admin (sidebar)
  └─ RCR · Questionnaires
       ├─ /corex/admin/rcr/questionnaires                  (list)
       └─ /corex/admin/rcr/questionnaires/{q}              (CSV import)
```

---

## 6. Copy-to-goAML workflow contract

For every period row in the deep view:

1. CO sees the gathered-evidence summary + auto-populated suggestion.
2. CO types/picks the final answer (autosaved every 500ms).
3. CO clicks the big teal **Copy to goAML** button (48px height, 240px+ width).
4. Browser:
   - Formats the value per `final_answer_format` (yes_no → "Yes"/"No", percentage → "42%", etc.)
   - Writes to clipboard via `navigator.clipboard.writeText()`
   - POSTs to `corex.compliance.rcr.answer.copied` to increment count + stamp `copied_to_clipboard_at`
   - If `transposed_to_goaml_at` was set, server clears it (CO must re-confirm after re-copy)
5. CO pastes into goAML in the adjacent tab.
6. CO ticks the **Pasted into goAML** checkbox → POSTs to `…answer.transposed`.
7. When every answer row in the submission has `transposed_to_goaml_at`, the submission's `transposed_to_goaml_at` is stamped.

---

## 7. Snapshot integrity contract

Set on `RcrSubmission::STATUS_SUBMITTED` transition. Implemented in [RcrSnapshotService](../app/Services/Compliance/Rcr/RcrSnapshotService.php). Snapshot:

- Full denormalised state — every question + answer + evidence + source data at submission moment
- SHA-256 hash of the questionnaire structure (sections+question codes) to prove which version was answered
- Append-only — no `updated_at`, no soft-deletes (see [RcrSubmissionSnapshot model](../app/Models/Compliance/Rcr/RcrSubmissionSnapshot.php))

---

## 8. Definition of Done for Phase 9d.1

- ✓ Investigation audit at `.ai/audits/rcr-phase-9d1-investigation-2026-05.md`
- ✓ Single additive migration applied
- ✓ FicDirective11Seeder rewritten with FIC verbatim ~168 questions (target ~156 met; 168 actual)
- ✓ 27 evidence sources rewired (those that map cleanly; 7 kept implemented but unwired)
- ✓ Submission start seeds period-bound answer rows
- ✓ Per-question deep view route + Blade + copy buttons + keyboard shortcuts + flow mode
- ✓ Copy + transposed endpoints with idempotent semantics
- ✓ Tests at tests/Feature/Rcr/Phase9d1Test.php cover I13–I20
- ✓ dev-check green
- ✓ Pushed to HFC2402

Items deferred to Phase 9d.2 (or later):
- Composite + multi-select rendering needs refinement; current renders are correct but minimal
- The `compliance.rcr.manage` permission key — currently gated via in-controller role check
- Readiness widget integration on the existing compliance dashboard — placeholder in CO sidebar count works
- The 7 unwired evidence sources (mandates.*, governance.*, audit.*, transactions.total_value, agency.fica_officer.alternate) — implement when FIC adds matching questions in a future revision
