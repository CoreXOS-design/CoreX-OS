# ES-6.7 — AI extraction-fidelity verification + human review gate

**Date:** 2026-06-10
**Branch:** AT-12-E-Sig
**Author:** Claude (build), for Johan Reichel (morning review — NOT pushed)
**Scope:** For PDF imports, an AI vision pass compares the ORIGINAL PDF against the
EXTRACTED CDS structure, flags divergences, and surfaces them in the CDS builder
for a human to ratify before the template can be used in the e-sign wizard.
**Scanned/OCR remains explicitly out of scope** (still rejected by
`ScannedPdfException` from ES-6).

> Pre-reads honoured: CLAUDE.md, STANDARDS.md, BUILD_STANDARD.md, spec §12/§24,
> esign-reconciliation §2 ES-6, es6-import-fix audit. Branch confirmed
> `AT-12-E-Sig` first. dev-check.ps1 skipped (Windows) — Tinker verification used.

---

## 1. Mechanism (root cause it addresses)

**Risk:** a "clean" PDF can extract with scrambled paragraph order, dropped clauses,
merged columns, lost line breaks, headings absorbed into body, mangled numbers, or
`~~~~` markers landing at the wrong spot — none of which a Tinker/text check catches.
A legally-binding template built on a silently-corrupt extraction is the hazard.

**Solution:** AI gives confidence, not a guarantee; a human always ratifies. After
`CdsParserService::parsePdf()` produces `cds_json`, `CdsExtractionVerifier::verify()`
sends BOTH the original PDF (native Anthropic document block, vision) AND the
extracted text to Claude in ONE call, gets back a structured divergence list, and
maps each to a flag a human must clear.

### Key plumbing decision — `generate()` not `generateStructured()`
`AnthropicGateway::generateStructured()` rebuilds the request (`AnthropicGateway.php:187-201`)
and **omits the `documents` field** (it is the last `NarrativeRequest` ctor param,
default `[]`, `NarrativeRequest.php:71`) — so it would silently drop the PDF and the
vision call would see no image. The verifier therefore calls `generate()` directly
(which honours `documents` via `buildUserMessageContent`, `:131/:256-287`) and parses
the JSON defensively itself.

> ⚠ **Finding for Johan (separate bug):** `generateStructured()` dropping `documents`
> also degrades `ImporterAiService::detectFromPdf()` (Path A) — its vision call likely
> sends no PDF. Path A is being retired (es6-import-fix audit), so this is informational,
> not fixed here. A one-line fix (pass `documents:`+`temperature:` in the rebuild) would
> restore it if Path A is kept.

---

## 2. Severity model (config-driven — no hardcoded bands)

`config/docuperfect.php` → `import.fidelity`:
- `enabled` (master switch; false → PDF imports skip verification, state `null`).
- `severity_map` : divergence_type → `high|low`. **Config is authoritative** over the
  AI's own severity suggestion. Unknown type → `default_severity` (fail-safe `high`).
- `blocking_severities` (`['high']`), `max_flags` (runaway cap, 40), `model_alias`.

| Band | Types (default map) | Effect |
|---|---|---|
| HIGH | missing_clause, dropped_content, reordered, scrambled_order, merged_columns, misplaced_marker, mangled_table, mangled_numbers | **BLOCK** — template `extraction_verification='blocked'`, excluded from wizard until cleared |
| LOW | heading_absorbed, lost_linebreaks, whitespace, formatting, minor | **WARN** — `warnings`, allowed |

Run states (`extraction_verification` on `cds_drafts` + `docuperfect_templates`):
`null` (Word/pre-feature, not gated) · `passed` · `warnings` · `blocked` · `cleared`
(had highs, all resolved) · `could_not_run` (fail-open).

---

## 3. The human review gate

- **Builder surfacing:** `TemplateController::cdsBuilder` loads the draft's flags
  (high first) → the builder (`cds-builder.blade.php`, `fidelityReview` Alpine
  component) renders each flag with severity, location, and source-vs-extracted
  snippets, plus a footer stating how many high flags must be cleared.
- **Three resolutions** (`DocumentImporterController::resolveFidelityFlag`, route
  `POST /import/fidelity-flag/{flag}/resolve`): **Accept** (extraction is fine) /
  **Fix** (human edited the content; an optional corrected snippet feeds the existing
  `field_corrections` learning loop) / **Acknowledge** (low-severity only — a HIGH
  flag returns 422 and cannot be dismissed by acknowledge).
- Resolving recomputes the owning draft AND template run state
  (`CdsExtractionVerifier::statusFromLiveFlags`), all in a transaction; resolution is
  audit-trailed (`resolved_by`, `resolved_at`, `resolution_note`); flags soft-delete only.
- **Wizard gate:** `ESignWizardController::create` excludes any template where
  `extraction_verification = 'blocked'` (null and every other state remain selectable).
  Flags carry from draft → template at `cdsGenerate`, so resolution works before OR
  after generate.
- **Navigation:** the gate lives inside the existing CDS builder (reached via the
  import redirect and the template edit path) — no orphan surface.

---

## 4. Robustness (BUILD_STANDARD)

- **Fail-open:** AI disabled / no key / budget-capped / transport error / malformed
  JSON → `could_not_run`, zero flags, import still succeeds, surfaced as a builder
  warning ("verification could not run — review manually"). Never a silent pass.
- **Defensive JSON parse:** strips ```code fences```, tolerates preamble, locates the
  first `{…}`; non-array/`divergences`-missing → could_not_run.
- **NOT-NULL contract:** a divergence with no usable `description` is skipped (the
  `description` column is NOT NULL) — *a bug the verification caught: `cleanStr()`
  returns `null` for empty, and the original skip check `=== ''` missed it, which would
  have inserted a null description. Fixed to `=== null`.*
- **Transactions:** draft+flags creation and flag resolution+recompute are each
  transaction-wrapped — a mid-write failure rolls back cleanly, no half-created draft.
- **Word never sent** to the AI (PDF-only branch) — cost + reliability.
- **No hardcoded thresholds:** bands, default severity, max flags, model, enabled —
  all config, env-overridable.
- **Soft-delete only; PPRA not EAAB; web-only e-sign** — upheld.

---

## 5. Verification (Tinker, corex_dev)

**Deterministic path — 18/18 PASS** (flags simulated to isolate the non-deterministic
AI; test rows force-deleted after):

```
severity map: missing_clause AI-low → config HIGH; whitespace AI-high → config LOW;
              unknown type → default high; empty-description skipped                PASS×4
run status: blocked(any high) / warnings(only low) / passed(none)                  PASS×3
defensive parse: garbage→null; fenced JSON recovered; preamble recovered           PASS×3
persist: draft blocked + 2 flags                                                   PASS
generate-carry: flags → template; template inherits blocked                        PASS×2
WIZARD GATE: blocked template EXCLUDED                                             PASS
resolve high flag → recompute 'cleared' → template NOW selectable                  PASS×2
Word import (null state) NOT gated                                                PASS
high flag cannot be Acknowledged (must Accept/Fix)                                PASS
RESULT: ALL CHECKS PASSED
```

**Live smoke — the real vision pipeline (Anthropic key configured in corex_dev):**
one `verify()` on a generated text PDF returned `status=blocked, flags=1, 3641ms`,
status a valid enum. Confirms end-to-end: request build → `generate()` WITH documents
(PDF reached the API — proves `generate()` does not drop documents) → defensive parse
→ severity map → run status. (It flagged the dompdf-flattened test PDF's lost paragraph
structure — the feature correctly catching a real divergence.)

php -l clean on all changed PHP; migration up clean; `view:cache` compiled all blades
(incl. the new fidelity panel) with no errors; route registered.

### Needs a real sample in morning review (documented limits)
- **Divergence-detection QUALITY** on a genuinely badly-extracting real mandate — the
  pipeline is proven live, but precision/recall of the AI on real-world bad PDFs needs
  a real sample (and ideally a crafted scrambled-extraction case). The gate/flag/resolve
  machinery is fully proven regardless of AI accuracy.
- **Builder UI click-through** (the `fidelityReview` panel + Accept/Fix/Acknowledge
  buttons + the wizard exclusion as seen in the browser) — verified by view-compile +
  the server-side resolve endpoint + the gate query; not by a live browser session
  (no browser harness here).
- **phpunit** not added/run: the import+gate flow needs MySQL + file uploads through
  HTTP and corex_dev-only DB (no isolated test DB on this Linux host; in-memory SQLite
  rejected by a raw MySQL `ALTER…MODIFY` migration). The Tinker run exercises the real
  models/services/queries end-to-end and is the proving evidence; a feature test should
  be added on the Windows schema-snapshot bootstrap.

---

## 6. Files

New: `app/Services/Docuperfect/CdsExtractionVerifier.php`,
`app/Models/Docuperfect/CdsExtractionFlag.php`,
`database/migrations/2026_06_25_000000_create_cds_extraction_flags_table.php`.
Changed: `DocumentImporterController` (verify+persist in import; `resolveFidelityFlag`),
`TemplateController` (`cdsGenerate` flag-carry; `cdsBuilder` flag-load),
`ESignWizardController` (wizard gate), `Template`/`CdsDraft` models
(fillable+relation), `config/docuperfect.php` (`import.fidelity`),
`routes/web.php` (resolve route), `cds-builder.blade.php` (review panel +
`fidelityReview` component), spec §12.8.
