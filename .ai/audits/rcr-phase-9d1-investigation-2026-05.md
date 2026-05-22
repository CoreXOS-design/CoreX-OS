# Phase 9d.1 Investigation Audit — RCR Remediation (read-only)
Date: 2026-05-22
Branch: HFC2402 @ commit 6c09e67
Auditor: read-only sweep before any code changes

This audit confirms the actual Phase 9d state on disk and resolves the discrepancies between the Phase 9d.1 brief's path/name assumptions and what was actually shipped. The remediation plan in Parts B-H adapts to reality, NOT to the brief's assumed names.

---

## A.0 — Path / class / column reality check vs the 9d.1 brief

The brief assumed a flatter naming. The actual Phase 9d structure is nested deeper with an `Rcr` prefix throughout. Adopting the brief's names would be a breaking rename. The remediation **keeps the actual nested structure** and adapts code samples in the brief to the real names.

| Brief assumes | Actually shipped |
|---|---|
| `app/Services/Rcr/EvidenceGatheringService.php` | `app/Services/Compliance/Rcr/EvidenceGatheringService.php` |
| `app/Http/Controllers/Compliance/RcrSubmissionController.php` | `app/Http/Controllers/Compliance/Rcr/RcrSubmissionController.php` |
| `App\Models\Compliance\Rcr\Submission` | `App\Models\Compliance\Rcr\RcrSubmission` |
| `App\Models\Compliance\Rcr\Question` | `App\Models\Compliance\Rcr\RcrQuestion` |
| `App\Models\Compliance\Rcr\Questionnaire` | `App\Models\Compliance\Rcr\RcrQuestionnaire` |
| `database/seeders/Rcr/FicDirective11Seeder.php` (`Database\Seeders\Rcr`) | `database/seeders/Compliance/FicDirective11Seeder.php` (`Database\Seeders\Compliance`) |
| Route name `compliance.rcr.*` | Route name `corex.compliance.rcr.*` |
| Permission key `compliance.rcr.manage` | Not yet seeded — add in Part F |
| Table `rcr_sections` | Actually `rcr_questionnaire_sections` |

The brief's `rcr_sections` references throughout (column additions, idempotency, etc.) all map to `rcr_questionnaire_sections` in reality.

---

## A.1 — Currently-seeded sections (invented thematic groupings — to be replaced)

### `fic_2026_composite` (14 sections, all `has_period_columns` absent)

```
A — Institution Identification
B — Risk Management & Compliance Programme (RMCP)
C — Money Laundering Risk Assessment
D — Terrorist Financing Risk Assessment
E — Proliferation Financing Risk Assessment
F — Customer Due Diligence (CDD)
G — Enhanced Due Diligence (EDD) — PEPs, high-risk customers
H — Ongoing Monitoring
I — Suspicious Transaction Reporting (STR / SAR)
J — Sanctions Screening
K — Training & Awareness
L — Record Keeping
M — Governance & Senior Management Oversight
N — Independent Audit / Compliance Review
```

### `fic_2026_estate_agents` (6 sections)

```
1 — Business Profile
2 — Customer Profile
3 — Geographic Risk Exposure
4 — Mandate Types
5 — Trust Account / Conveyancer Relationships
6 — Specific Property Transaction Risks
```

**Verdict:** All 20 section codes are invented thematic groupings. The Phase 9d.1 brief correctly identifies these as wrong vs the FIC's actual Part 1/2/3/8 structure. Part C of the remediation **replaces** these.

---

## A.2 — Currently-seeded questions (71 invented codes — to be replaced)

Composite questionnaire question codes (52 total):
`A.1.1 – A.1.7 · B.1.1 – B.1.7 · C.1.1 – C.1.4 · D.1.1 – D.1.3 · E.1.1 – E.1.3 · F.1.1 – F.1.5 · G.1.1 – G.1.3 · H.1.1 – H.1.2 · I.1.1 – I.1.3 · J.1.1 – J.1.3 · K.1.1 – K.1.3 · L.1.1 – L.1.3 · M.1.1 – M.1.3 · N.1.1 – N.1.3`

Sector questionnaire question codes (19 total):
`1.1 · 1.2 · 1.3 · 1.4 · 2.1 · 2.2 · 2.3 · 3.1 · 3.2 · 4.1 · 4.2 · 4.3 · 5.1 · 5.2 · 5.3 · 6.1 · 6.2 · 6.3 · 6.4`

**Note on sector code collision:** my sector codes `1.1`–`6.4` collide numerically with the FIC's General Risk codes (`1.1`–`1.63`) and TF codes (`3.1`–`3.31`). After Part C re-seed, the FIC's actual codes own these numbers; my old sector codes are gone.

---

## A.3 — The 27 EvidenceGatheringService source codes + current wiring

All 27 sources implemented in `app/Services/Compliance/Rcr/EvidenceGatheringService.php` (Phase 9d). All are PRESERVED in 9d.1 — only the question wiring changes.

```
agency.profile                                 → wired to A.1.1, A.1.2, A.1.3, A.1.4 + sector 1.4 (will move to inst.*)
agency.fica_officer.primary                    → wired to A.1.5            (will move to 1.26)
agency.fica_officer.mlro                       → wired to A.1.6            (will move to inst.representatives or 1.26)
agency.fica_officer.alternate                  → wired to A.1.7
rmcp.exists                                    → wired to B.1.1            (will move to 1.24)
rmcp.last_reviewed                             → wired to B.1.2            (will move to inst.* hint only)
rmcp.sections_count                            → wired to B.1.3            (will move to 1.24)
rmcp.acknowledgements_complete_pct             → wired to B.1.4            (will move to 1.27)
cdd.completed_in_period                        → wired to F.1.1            (will move to 1.28)
cdd.outstanding                                → wired to F.1.2            (manual fallback for 1.28)
cdd.high_risk_count                            → wired to F.1.3            (manual fallback)
edd.pep_screenings                             → wired to G.1.1            (will move to 1.5 / 1.8)
edd.sanctions_screenings                       → wired to J.1.1            (will move to 1.21 / 1.22)
str.filed_count                                → wired to I.1.1            (will move to 1.59)
str.flagged_unfiled                            → wired to I.1.2            (manual)
training.completed_pct                         → wired to K.1.1            (will move to 1.27)
training.modules_available                     → wired to K.1.2            (manual hint)
training.last_session_date                     → wired to K.1.3            (manual hint)
transactions.total_count                       → wired to sector 1.2       (will move to 8.4)
transactions.total_value                       → wired to sector 1.3       (manual hint, no direct FIC question)
transactions.high_value_count                  → wired to sector 2.1       (manual hint)
transactions.foreign_party_count               → wired to sector 2.2       (manual)
mandates.by_type                               → wired to sector 4.1       (manual hint — no direct FIC question)
mandates.cancelled_count                       → wired to sector 4.2       (manual)
governance.last_compliance_committee_meeting   → wired to M.1.1            (manual; no clean FIC question)
governance.compliance_reports_generated        → wired to M.1.2            (manual)
audit.last_independent_review                  → wired to N.1.1            (manual)
```

**Sources that won't have a clean FIC mapping** (kept implemented, unwired — flagged for future Phase 9d.2 if FIC ever adds matching questions):

- `transactions.total_value` (no monetary-aggregate FIC question)
- `mandates.by_type` and `mandates.cancelled_count` (no mandate-specific FIC question)
- `governance.last_compliance_committee_meeting`, `governance.compliance_reports_generated`, `audit.last_independent_review` (no FIC questions match)

---

## A.4 — Actual column inventory (vs the 9d.1 brief assumptions)

### `rcr_questionnaire_sections` (the brief calls it `rcr_sections`)

```
id, questionnaire_id, section_code, title, description, sort_order, created_at, updated_at
```

**Missing per the 9d.1 brief** (add in Part B):
- `has_period_columns` (boolean, default true)
- `applies_when_json` (json, nullable)

### `rcr_questions`

```
id, questionnaire_id, section_id, question_code, question_text, answer_type,
answer_options_json, is_required, auto_population_source, help_text,
sort_order, created_at, updated_at
```

**Brief uses a richer schema. Missing columns to add in Part B:**
- `parent_code` (string nullable) — for sub-questions like 1.29.1
- `footnote` (text nullable) — clarification text on certain questions
- `evidence_source_codes_json` (json nullable) — array of source codes (replaces single `auto_population_source`)
- `auto_populate_hint` (text nullable) — distinct semantic from `help_text`

**Mapping decisions:**
- Brief's `code` = my `question_code` ✓
- Brief's `response_type` = my `answer_type` ✓ (no rename — Phase 9d's term stays)
- Brief's `response_options` = my `answer_options_json` ✓
- Brief's `display_order` = my `sort_order` ✓ (no rename)
- Brief's `evidence_source_codes` (array) → new `evidence_source_codes_json` column. `auto_population_source` (single string) kept for backwards-compat with EvidenceGatheringService::populateOne — populated from `evidence_source_codes_json[0]` at seed time.

### `rcr_answers`

```
id, submission_id, question_id, answer_value, answer_data_json,
is_auto_populated, auto_population_source_data, manually_edited,
last_edited_at, last_edited_by_user_id, notes, status, reviewer_user_id,
reviewed_at, created_at, updated_at
```

**Missing per the brief** (add in Part B):
- `period_code` (string 16, default 'static') — discriminates p1/p2/p3/static
- `copied_to_clipboard_at` (timestamp nullable)
- `copied_to_clipboard_count` (uint default 0)
- `transposed_to_goaml_at` (timestamp nullable)
- `final_answer_format` (string 32 nullable) — yes_no / percentage / etc.

**Current unique constraint:** `rca_sub_quest_uq` on `(submission_id, question_id)`. After Part B, this must be dropped and recreated as `(submission_id, question_id, period_code)`.

### `rcr_submissions`

```
id, agency_id, questionnaire_id, status, reporting_period_from, reporting_period_to,
submission_deadline, submitted_at, submitted_by_user_id, submitted_to_platform_reference,
locked_at, export_document_path, notes, assigned_co_user_id, created_at, updated_at, deleted_at
```

**Missing per the brief workflow** (add in Part B):
- `transposed_to_goaml_at` (timestamp nullable) — set when all answers are transposed

**Status enum currently:** `draft, in_review, approved_for_submission, submitted, locked`. The brief implies a new `transposed` status — adding without breaking the existing enum: rely on the existing `locked` status + the new `transposed_to_goaml_at` timestamp (locked stays as the terminal pre-FIC state; transposed timestamp marks FIC delivery confirmation per-answer rollup). No status enum change needed.

---

## A.5 — Tests referencing invented codes

`grep -rn` on `tests/` for `fic_2026_composite`, `RcrQuestion`, RCR section codes:

**No committed tests reference the invented codes.** The Phase 9d integration verification was done via ad-hoc `scripts/phase9d-integration-test.php` (deleted post-commit). The 19 passing tests claim was a clean run of that ad-hoc script.

Implications:
- No `tests/Feature/Rcr/*` exists. Part G must **create** the test suite (I13-I20 + reproductions of the 19 9d cases that still apply).
- No invented-code references in existing committed tests to update.

---

## A.6 — Existing routes + controllers

```
GET   /corex/compliance/rcr                                          corex.compliance.rcr.index
POST  /corex/compliance/rcr                                          corex.compliance.rcr.store
GET   /corex/compliance/rcr/{rcrSubmission}                          corex.compliance.rcr.show
PATCH /corex/compliance/rcr/{rcrSubmission}/answers/{rcrAnswer}      corex.compliance.rcr.answers.save
POST  /corex/compliance/rcr/{rcrSubmission}/answers/{rcrAnswer}/evidence
POST  /corex/compliance/rcr/{rcrSubmission}/auto-populate-all
POST  /corex/compliance/rcr/{rcrSubmission}/send-for-review
POST  /corex/compliance/rcr/{rcrSubmission}/submit
GET   /corex/compliance/rcr/{rcrSubmission}/export/{format}
```

**To add in Part D/E:**
- `GET  /corex/compliance/rcr/{rcrSubmission}/question/{questionCode}` (deep view)
- `POST /corex/compliance/rcr/answer/copied`
- `POST /corex/compliance/rcr/answer/transposed`

All routes keep the existing `corex.` prefix convention.

---

## A.7 — Existing permission model

Permission middleware on RCR routes: none. Authorisation is via in-controller `assertCompliance()` checking `user.role in [super_admin, admin, branch_manager, principal]`.

The brief's `compliance.rcr.manage` permission key does not exist. Part F will either:
- (a) add the permission key + assign to compliance_officer role + apply `permission:compliance.rcr.manage` middleware OR
- (b) extend the existing role check to include the `compliance_officer` role

**Recommendation:** option (a) — explicit permission. Existing role check stays as fallback.

---

## A.8 — Resolved naming map for the remediation

Throughout Parts B-H, the brief's code samples use names that don't match 9d. The remediation translates as follows (consistent for all subsequent work):

| Brief uses | Real |
|---|---|
| `Submission` model | `RcrSubmission` |
| `Question` model | `RcrQuestion` |
| `Questionnaire` model | `RcrQuestionnaire` |
| `Section` model | `RcrQuestionnaireSection` |
| `Answer` model | `RcrAnswer` |
| `compliance.rcr.*` route | `corex.compliance.rcr.*` |
| `code` column on questions | `question_code` |
| `code` column on sections | `section_code` |
| `display_order` | `sort_order` |
| `response_type` | `answer_type` |
| `response_options` | `answer_options_json` |
| `evidence_source_codes` | new column `evidence_source_codes_json` + legacy `auto_population_source` (string, first entry) |
| `applies_when_json` (on sections) | new column |
| `Database\Seeders\Rcr\FicDirective11Seeder` | `Database\Seeders\Compliance\FicDirective11Seeder` |
| The `rcr_sections` references | actual table is `rcr_questionnaire_sections` |
| Auth gate `@can('compliance.rcr.manage')` | applied via permission middleware (new) OR via existing role check (fallback) |

---

## A.9 — Pre-Part-B sanity checks

- ✓ Migration filename pattern `2026_06_05_*` exists; new Part B migration will be `2026_06_06_*` to land cleanly after.
- ✓ All 27 evidence sources are implemented and idempotent — `EvidenceGatheringService::populateOne` accepts any source code; rewiring is a SEED change, not a code change.
- ✓ Existing seeded answer rows on agency 1 (from Phase 9d's tests) — `RcrAnswer::count()` should be checked before re-seeding. If non-zero, the re-seed must reuse `(submission_id, question_id)` → migrate to `(submission_id, question_id, 'static')` and let Part B's new unique constraint stand.
- ⚠ Caveat: any submission already created in Phase 9d will have answers bound to the OLD question_ids. The Part C re-seed deletes those questions cascading the answers. Mitigation: assume zero production submissions exist (matches reality — only ad-hoc test rows from the 9d integration script). Document in commit notes that pre-9d.1 submissions are wiped.

---

## A.10 — Plan summary (carries into Part B onwards)

1. **Part B** — one new migration adds the 7 missing columns + drops/recreates the answer unique key. Models extended (fillable + casts + scopes).
2. **Part C** — `FicDirective11Seeder` rewritten:
   - Truncate `rcr_questionnaire_sections` + `rcr_questions` rows scoped to the two FIC questionnaires (cascade deletes `rcr_answers` for those questions — acceptable per A.9 caveat).
   - Re-create section skeleton (6 composite + 1 sector with `has_period_columns` flag).
   - Re-seed FIC verbatim questions (~156 total).
   - Wire `evidence_source_codes_json` array per the A.3 mapping.
3. **Part D** — per-question deep view route + controller method + Blade template + copy/transposed JS handlers + keyboard shortcuts + goAML flow mode toggle.
4. **Part E** — two endpoints with idempotent semantics + auto-clear-transposed on re-copy.
5. **Part F** — write spec, seed permission, add readiness widget.
6. **Part G** — `tests/Feature/Rcr/` directory + I13-I20 integration tests + retain coverage of 9d cases.
7. **Part H** — verify, document, commit.

Proceeding to Part B.
