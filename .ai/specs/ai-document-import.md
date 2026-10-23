# AI Document Import — the agency uploads its own document and gets back a template that is ready for e-sign

> **Status: SPEC — draft for review (23 Oct 2026). No code.** Written by the spec lane at the conductor's instruction (Johan's direction, 23 Oct 2026).
> **Pillars:** Agent (who uploads, who confirms), Property / Contact / Deal (what the fields bind to), Document (the output). **Module:** DocuPerfect / CDS / E-sign.
> **Governing docs:** `ESIGN-CANON.md` (the output must conform to it), `esign-field-intelligence.md`, `cds-document-look.md`, `esign-signing-modes.md`, `at456-ai-tools-scheduler-and-spend-safety.md`, `ai-cost-ledger.md`, `mic-complete-spec.md` §8.9 (report recogniser).

---

## 0. Johan's rulings (23 Oct 2026) — these are decisions, not open questions

1. **Target outcome.** The agency uploads its document and gets it back **100% ready to use with e-sign**. No human builder work is the normal path. It may take a while ("it takes a while as it sorts out the document"), so it runs as a **background job with visible progress and a notification when it is ready**. Built code and AI work together.
2. **Model.** Use the **strongest AI model available** for this import, not the cheap one. Quality over cost here.
3. **Cost logic.** It is a **one-off per document**. Once a template is ready it stays as it is until someone edits or replaces it, so the AI cost per agency is minimal. The AI **re-runs only on edit or replace**. The **cost of each import is shown in the audit**.
4. **"Ready" means proven.** Before a template is offered as ready, the system **self-checks**: every field bound or deliberately manual; signer roles complete for the document type; grids detected; a dry-run render; a test fill. It flags to a human **only what it could not resolve**.
5. **Two sides, one puzzle** (Johan's direction): (a) the AI reads the agency's raw Word/PDF and knows what gets filled in where and who fills what; (b) a prepared, always-current **data dictionary** of how CoreX stores things. Linking is a puzzle: *what the document needs → where it lives in the database → bind.*
6. **Markers always win.** A marker the author put in the document (`~~~~NAME~~~~`, `@@@@`, `%%%%`, `####`) is a fact; the AI proposes around it and never overrides it. **AI proposes, a human confirms the flagged items, markers win.** (The conductor cited this as "AT-249". That ticket is not in the repo — no spec, code or commit — so this section is the written record of the rule.)

---

## 1. What this feature is, in plain words

Today an agency that wants its own lease, mandate or disclosure form in CoreX has to mark up the Word file by hand with special symbols, open a builder, tick every field, choose who fills it and who signs, and fix what the importer got wrong. That is expert work, it is slow, and it fails quietly: on Mega's lease (23 Oct) the importer turned fields into headings, bound a lease's sale-commission clause to the property's commission, and read a year as a clause number.

After this feature the agency uploads the raw document — no markers needed — and walks away. CoreX reads the whole document, works out the structure, which blanks are fields, who fills each one and when, which database value each should take, where the signatures and initials go, and builds the template. Then it **proves** the template works before it says "ready". The agency is told when it is done. If something could not be resolved, it shows only those items.

**Not in scope:** changing how documents are filled, signed or finalised; the CDS v2 / compiler subsystem (see §2.2); bulk re-import of existing templates (a later decision, §10).

---

## 2. What exists today, and what to reuse

### 2.1 Corrections to the brief (so nobody builds on a wrong assumption)
- "AI template builder learns every building block" (`0c63a353f`) and "AI template creator: richer, layered designs" (`5770fa2d4`) are the **Ad Manager canvas AI** (`app/Services/AdTemplates/AiTemplateBuilderService.php`, `AdLayoutValidator.php`). They are **not** the document importer. They are useful only as a precedent for "teach the AI every building block, validate its output".
- "PDF import now sends the PDF to the AI" (`485f1d639`, 17 Oct) **is** the document importer. Before it, `AnthropicGateway::generateStructured()` dropped the PDF silently.
- `importer.md`, `importer-async-parse.md`, `importer-onboarding-portal.md` are the Property24 listing importer. Not related, except as the progress/polling precedent (§2.4).

### 2.2 The three pipelines that exist (Compile Studio is out of scope)
| # | Pipeline | Entry | What it does | Verdict |
|---|---|---|---|---|
| 1 | **Standard import** (docx + pdf) | `routes/web.php:7189-7204`; `DocumentImporterController::parse` (:122), `review` (:360), `generate` (:536); `DocumentTemplateGenerator::generate` (:31) | docx → mammoth HTML (`DocxParserService.php:491`, `resources/js/mammoth-convert.mjs`) → blanks found by pattern (`scanBlanks` :74, `BLANK_PATTERN` :33) → text cut at 25,000 chars (:305-340) → blanks + context sent to Claude (`parseFieldsWithAi` :354, `ImporterAiService::detectFields`) → merged **by numeric index** (:379-410). PDF: whole PDF to Claude (`ImporterAiService::detectFromPdf`, model alias `quality`, 6000 tokens, 120s) — but **returns no document body**; `buildPdfStubHtml` (:1306) renders only a list of fields, so the PDF path cannot produce a usable template. | Replace the AI step; keep spend guard + ledger |
| 2 | **CDS import** (docx only, no AI) | `generateCdsTemplate` (:72); `CdsParserService::parse` (:96); stores `CdsDraft`; builder at `TemplateController::cdsBuilder` (:570), `cdsGenerate` (:778) | Pure-PHP read of `word/document.xml`; headings, clauses, tables, markers, signature sections → `Template.cds_json` | **This is the structural base and the live e-sign path.** Extend |
| 3 | **Compile Studio** (internal, `esign.compiler.view`) | `routes/web.php:6944`; `Compiler/Ingest/*`, `DeterministicSegmenter`, `HeuristicBindingSuggester` | Produces CDS **v2** (`compiled_templates.structure`) | **Not live** (`ESIGN-CANON.md` §0 and its "Held CDS compiler (not live)" note: the live documents take the legacy path). Out of scope; reuse concepts only |

`ParseDocumentJob` exists but nothing dispatches it. `ClaudeVisionParserService` and `AiFieldMapperService` are unreferenced.

**Decision:** the import produces the **legacy web/CDS** output (`Template.cds_json`, `fields_json`, `editor_state`, `field_mappings`, `signing_parties`, `insertable_blocks`), because that is the e-sign path real documents use. It also records a **crosswalk to the v2 dictionary keys** where one exists (§3.5), so a later move to v2 does not need a re-import. The output must conform to `ESIGN-CANON.md` (role blocks, proxy-first order, e-sign eligibility being the template's own setting, §7).

### 2.3 Reuse table
| Capability | Where | Decision |
|---|---|---|
| Marker syntax — single source of truth | `CdsParserService::ACCEPTED_MARKERS` (:30-72), `detectMarkers` (:1269), near-miss detection (:1404), zero-field warning (`DocumentImporterController.php:297-325`) | **Reuse unchanged.** Markers are the "markers win" layer |
| Deterministic party-token binding (`~~~~Seller - Physical address~~~~`) | `CdsBindingSuggester::resolveToken` (:115) | **Reuse.** Extend the party prefix map (it only knows seller, buyer, purchaser, lessor, landlord, lessee, tenant, property, document; agent, witness, spouse, director return null) |
| Role detection / normalisers / projector | `RoleBlockDetectionService::ROLE_BASES`, `RoleBlockNormalizer`, `MarkerBlockLevelNormalizer`, `CdsBindingProjector` | **Reuse** to turn the AI plan into a template |
| Document look (alignment, shading) | `cds-document-look.md`, `CdsParserService::extractParagraphLook` (:679) | **Reuse** |
| Renderer (for the dry run) | `CdsRendererService` | **Reuse** |
| Fill-time resolver (for the test fill) | `WebTemplateDataService::resolveCdsTemplate` (:502), `resolveBase` (:1502) | **Reuse as the judge**: if the real resolver cannot fill a binding, the binding is wrong |
| Learned corrections | `FieldCorrection`, `DocxParserService.php:430` | **Extend** as per-agency few-shot examples (§4.8) |
| AI gateway + spend guard + ledger | `AnthropicGateway`, `AiSpendGuard` (`assertAllowed` :119, `reserveBatch` :209), `AiUsageRecorder::record` (:50), `ai_usage_events`, `AiUsageEvent::SOURCE_DOCUPERFECT_IMPORT` | **Reuse** |
| "AI read once, pay once" pipeline | `AiReportReadService` (L72-193), `market_report_ai_reads` unique `(file_hash, prompt_version)`, caps in `ReportReadSettings` + `market_report_ai_usage` atomic reserve/release | **Copy the pattern** (§6) |
| "Save only if a self-test passes" | `LayoutLearner.php` L106, L134-170, `score()` L868 | **Copy the pattern** — the import self-check (§4.6) |
| Long AI job | `ParseMarketReportJob.php` ($timeout 330 L57, `retryUntil`, `failed()` L572, self re-dispatch L492) | **Copy the pattern** |
| Run row + progress + polling | `p24_import_runs` (`status`, `counts_json`), `OnboardingPortalController::status()` / `parseProgress()` (L627), 2.5 s polling | **Copy the pattern** |
| Notification | `PillarEventNotification` (`eventKey`, `actionUrl`, `severity`, `channels`), `NotificationDispatcher`, alert bell (`AlertFeedController`) | **Reuse** |
| Confidence / "unsure" convention | `RentalInspectionScanMark.php` L73-115 (never guess; ambiguous → explicit human choice), `property-image-recognition.md` (AI badge, `confirmed_by_user_id`) | **Follow** |

### 2.4 Known-fragile heuristics (the reason imports fail today)
These are the code locations the new reader must replace or guard. Section 5 turns each into a required behaviour and a test.
- Bold = `w:b` present, ignoring `w:val="0"`/`"false"`; style-inherited bold missed — `CdsParserService.php:773` (`i`/`u` same, :774-775).
- Typed clause number `^\d+(\.\d+)*\s+` — a year or quantity becomes a clause number and the digits are stripped — :971.
- All-caps and bold "heading" rules — :1008, :1045.
- Only direct-child `w:r` of `w:p` read — text in hyperlinks, tracked insertions (`w:ins`), smart tags, fields, `w:sdt`, text boxes lost — :751.
- Tables flattened to plain text, no cell paragraph separator, nested tables dropped, markers inside cells dead, bold/italic lost — :792, `CdsRendererService.php:149`.
- Letterhead detected by keyword count in the first two sections only; headers/footers (`header*.xml`, `footer*.xml`) never read — :1082, :104.
- Signature detection by keyword and a 15-section window — :2280, :2259 (documented failure on the EATS).
- Commission: `detectCommissionField` (:338, regex :356) tokenises the **first** "commission/professional fee … %" in the document and `CdsBindingSuggester.php:160-165` binds it to `property.commission_percent` with no document-type guard; `identifyField` (:1658) maps any `@@@@` in a clause containing "commission" to `deal.commission_percent`; the AI prompt uses `property.commission_percent` for sale (`ImporterAiService.php:243`) and `deal.commission_percent` for lease (:249); the legacy label table uses `deal.commission` (:2040). **Three keys for one concept.**
- Blank patterns catch ordinary ellipsis runs; label-value layouts in tables never trigger the context heuristics — `DocxParserService.php:33`, `CdsParserService.php:1580`.
- 25,000-character cut: everything after it is "Unassigned" — `DocxParserService.php:305,379-410`.
- Sale-vs-lease decision lives in prompt text only (`ImporterAiService.php` STEP 0), no code check.
- Server-bound items default to `high` confidence even when wrong, so the builder's confidence dot says nothing about deterministic-but-wrong bindings — `cds-builder.blade.php:1759`.

---

## 3. The data dictionary

### 3.1 What it is
A **generated, versioned catalogue of every value a document field can be bound to**, with its plain-language meaning. It is the "where it lives in the database" half of the puzzle. The AI receives it (compacted) with every import; the validator uses it to reject any key the AI invents; the fill-time self-check uses it to prove each binding resolves.

It contains **no client data** — only keys, meanings, types and where the value comes from.

### 3.2 What exists and why it is not enough
Binding sources live in at least five places and none knows about the others:
1. `docuperfect_named_fields` (`NamedField`; `source_type` ∈ property|contact|agent|deal|static|computed|manual; `source_column`; `source_contact_type`) — **global, no `agency_id`**. Seeded by `php artisan docuperfect:sync-fields` (introspects `Schema::getColumnListing` on contacts, properties, users, deals) plus data migrations; **not part of the deploy path**.
2. The hard-coded `match` arms in `WebTemplateDataService` — `resolvePropertyFromKey` (:931), `resolveContactFromKey` (:1197), `resolveDealFromKey` (:1221), `resolveAgentFromKey` (:1248, name/email/cell only), `resolveComputedFromKey` (:1267).
3. The flat base variables `resolveBase` (:1502-1660) — party attributes, indexed seller keys, property, deal, agent, signature names.
4. `config/lease-agreement-fields.php` — the clearest registry (label, type, group, side, column, comparison, indexed) + `rental_lease_templates.field_map` per agency; values from `LeaseAgreementDocumentValues`.
5. `DataDictionaryEntry` / `data_dictionary_entries` (AT-177) — about 32 typed keys (`purchase_price`, `seller_id_number`, `agent_ppra_no` …) with validation/format, **but no link to a column or resolver**; deployed by `SyncableReferenceSeeder`s.

Also: field groups (`docuperfect_field_groups`, agency + global), insertable blocks, disclosure grids, signing roles, document types (`DocumentType`, global, ~37 slugs, categories only sales/rentals/null).

### 3.3 Format
One entry per bindable thing. Stored as rows (table `import_dictionary_entries`, §3.6) and exposed as JSON. Fields:

| Field | Meaning |
|---|---|
| `key` | The canonical binding key, e.g. `property.erf_number`, `contact.lessee.id_number`, `deal.monthly_rental`, `lease.notice.expiry_due_by`, `agent.ffc_number`, `agency.name`, `computed.price_in_words`, `field_group.<slug>`, `block.other_conditions`, `grid.mandatory_disclosure` |
| `family` | property · contact(party) · deal · lease · agent · agency · computed · field_group · insertable_block · disclosure_grid · signer_role · static · manual |
| `meaning` | One plain sentence an agent would understand: "The ERF number of the property being sold or let." |
| `type` | text · longtext · money · percent · integer · date · month · email · phone · id_number · checkbox · select · signature · initials (the type set the importer may emit; the existing `DataType` enum values map onto it) |
| `source` | Where the value comes from at fill time: model + column, or resolver + computation. Machine-readable, used by the parity test |
| `party` | For party attributes: which roles it applies to (seller/buyer/lessor/lessee/agent/witness/spouse/director …) and cardinality (indexed 1..n) |
| `applies_to` | Document categories/types it is valid for (sale, rental, mandate, disclosure …). Drives rules like "sale commission is not offered for a lease" |
| `who_fills_default` | `auto` (CoreX fills from the record) · `agent_prep` · `party_signing` — the default only; the plan may override |
| `aliases` | Synonyms the document is likely to use ("erf", "stand no", "erf/stand number") — generated from code aliases (`resolveCdsTemplate` aliasing Lessor/Landlord, Lessee/Tenant, `erf`/`erf_number`/`property_number`) and labels |
| `gate` | Preconditions: e.g. `price_in_words` only for sales (`Template::isSalesDocument`) |
| `agency_scope` | `global` or `agency:<id>` (§3.4) |
| `v2_key` | Crosswalk to a `data_dictionary_entries.key` if one exists, else null |
| `version` / `hash` | Dictionary version it belongs to |

### 3.4 Per-agency, multi-agency by construction
A dictionary is **built per agency** = global entries + that agency's own: field groups (`agency_id`/`is_global`), `rental_lease_templates.field_map`, recipient wording (`RecipientTemplate`, `EsignRecipientPreset`), `AgencySigningParty`, custom/named fields it has added, and which features/modules it has switched on (`hasFeature`). The AI for agency B never sees agency A's custom entries. Nothing in the dictionary, the prompts or the defaults may name HFC or assume agency 1 (`CLAUDE.md` #9). *The test: what does the dictionary look like for the second agency (Cape Town, rentals)? It must offer lease/rental keys first and never HFC wording.*

### 3.5 How it is generated (not hand-kept)
A **BindingRegistry** is the single source of truth, and every consumer reads it:
- **Providers** (one small class per family) emit entries from the thing that already defines them: schema introspection for property/contact/deal/agent columns (as `docuperfect:sync-fields` does, but writing entries, not named fields); `config/lease-agreement-fields.php`; `NamedField`; `FieldGroup`; `RoleBlockDetectionService::ROLE_BASES`; `CdsParserService::acceptedMarkers()`; `DocumentType`; `DataDictionaryEntry` (for the v2 crosswalk); the computed-field list.
- **The resolver and the dictionary must agree.** Build step 1 refactors the `match` arms in `WebTemplateDataService` to look up the registry (no behaviour change), so a key cannot exist in the dictionary and be unresolvable (the current `default => $property[$attr] ?? ''` fallbacks hide exactly this). A **parity test** fills every dictionary key against a fixture record and fails on any key that resolves to nothing for no declared reason.
- **Meanings.** Where a source already carries a label (lease registry, dictionary seeders, named fields) it is used. Where it does not (raw columns), the meaning comes from `config/dictionary-meanings.php`, keyed by `table.column`. A **completeness gate** (a test in CI and a check at regeneration) fails if any bindable key has no meaning — so a new column cannot silently enter the dictionary unexplained. No runtime AI is used to write meanings.
- **Regeneration.** An idempotent `SyncableReferenceSeeder` (so `deploy:sync-reference-data` runs it automatically from `scripts/qa-deploy.sh` step after `migrate`, and from `scripts/deploy.sh`) rebuilds the global entries and bumps `version` when the content `hash` changes. Per-agency overlays are rebuilt lazily at import time (and on change to an agency's field groups/field maps) and cached by hash. `docuperfect:sync-fields` is folded into the same step.
- **Gaps to close first** (found in the scout; each is a build item): agent/agency sources are thin (agent resolver returns only name/email/cell — PPRA/FFC and agency details exist as dictionary keys but have no resolver arm; letterhead is a partial); named-field types are loose (text/date/selection only — no money, checkbox, signature, initials at field level); computed fields have no formula/dependency metadata; MDF grid items have no stable keys (addressed by index only); `editable_by`/`party` are free-form strings.

### 3.6 Storage
`import_dictionary_entries` (global rows `agency_id` NULL; agency overlay rows `agency_id` set; `BelongsToAgency` for the overlay), `import_dictionary_versions` (version, hash, generated_at, entry count). Read API for the Admin catalogue (§8). Soft-delete only (retired entries stay for audit; a template bound to a retired key is flagged, never silently broken).

---

## 4. The import flow

```
upload → [run row created, job queued] → 1 read → 2 plan (AI) → 3 validate → 4 build → 5 prove → 6 ready / ready-with-flags / needs-help → notification
```
Everything after the upload runs in a queued job with a visible stage and progress.

### 4.1 Upload
Word (.docx) or PDF; the existing 10 MB limit unless raised by decision (§10). One front door: the two cards on the current import screen ("standard" and "CoreX Document Engine") become **one** upload. The agency chooses nothing technical. Optional hints only: document name, and whether it is for sales or rentals if the AI is unsure (it asks only then). **Markers are never required**; if present they are honoured (§0.6).

A deterministic privacy scan runs before anything leaves the server (§6.3).

### 4.2 Step 1 — Read (no AI): a faithful document model
A new reader replaces the fragile parts of `CdsParserService`/`DocxParserService` and emits a **document model** — an ordered tree of blocks with provenance:
- paragraphs with **true** run formatting (bold/italic/underline resolved through direct formatting, character style and paragraph style, honouring `w:val="0|false|off"` and toggle semantics);
- **tables with cell-level content** (paragraphs, runs, markers, nested tables), merged cells and borders, so everything inside a Word table is kept;
- numbering with **provenance**: `auto` (numPr/numbering.xml) vs `typed` vs `none`, so mixed documents are numbered consistently (§5.6);
- text from hyperlinks, `w:ins`/`w:del`, smart tags, fields, `w:sdt`, text boxes;
- **headers and footers** (`header*.xml`, `footer*.xml`) read and classified (§5.7);
- markers found with exact positions (reusing `ACCEPTED_MARKERS`);
- blank candidates (underscore/dot/ellipsis runs, `[brackets]`, empty table cells next to a label), each with surrounding label text;
- document look (alignment, shading) as today.
For **PDF**: page images + the text layer (`pdftotext` with coordinates) + detected form fields/lines. The model then marks the PDF's reading order; the output template is still built as web/CDS from the recovered text (the current PDF path produces no body and is replaced).
The model is saved with the run (it is the evidence for every later decision and the input of the self-check).

### 4.3 Step 2 — Plan (AI, strongest model)
One structured call (more only if the document is too long for a single pass — then by chapter with a shared context). Input: the document model (not raw files), the agency's **compact dictionary**, the document-type/category/role catalogues, the **locked facts** (markers and their positions), the rules of §5 in the prompt, and a handful of the agency's own accepted corrections as examples. Output is strict JSON, the **ImportPlan**:

| Part | Content |
|---|---|
| `document` | title; category (sales/rentals); document type slug (from `DocumentType`); is it a sale of land / lease / mandate / disclosure / FICA …; party mode; suggested delivery modes; one-line reason |
| `structure` | sections and clauses with **clean numbering** (value + whether the source number was auto or typed), heading levels, tables kept as tables, letterhead/footer decisions (keep as the template's header/footer, or drop because CoreX supplies the frame) |
| `fields[]` | `id`, anchor (block + offset/cell), label, `type` (§3.3 set), `who_fills`: `{phase: auto\|agent_prep\|party_signing, party_role?}`, `required`, `binding: {key, confidence 0..1, rationale}` or `manual` with a reason, alternatives considered |
| `grids[]` | disclosure/checklist grids: header, statements with stable ids, YES/NO/N-A columns, conditional date columns |
| `blocks[]` | insertable blocks (other conditions, included/excluded items, custom named) with purpose, min/max conditions, position |
| `signing` | roles in signing order (owner/acquiring/agent/witness/spouse…), signature and initials surfaces with per-role rules (e.g. initials on every page; per-row initials rows), proxy/representative needs, wording tokens |
| `flags[]` | everything the model itself is unsure about, with the reason in plain language |

**Markers win:** the plan builder is given each marker as an immutable field/block/signature/initial; the AI may add its binding and who-fills for it only where the marker does not already say (a `~~~~Seller - Physical address~~~~` token is bound by `CdsBindingSuggester` deterministically, and that binding wins over the AI's — as the builder already does at `cds-builder.blade.php:1397-1410`).

### 4.4 Step 3 — Validate and normalise (code, no AI)
The plan is untrusted until code accepts it:
- JSON schema check; every `binding.key` must exist in **this agency's** dictionary and be valid for the document's category (`applies_to`, `gate`) — otherwise the field is demoted to *unbound* and flagged, never accepted;
- type compatibility (money field bound to a text column etc.);
- **commission rule by document type** (§5.4);
- numbering consistency, unique ids, no field inside a heading, no empty clauses;
- confidence is **recomputed**, not trusted: a deterministic binding with no document evidence is not "high" (fixes `cds-builder.blade.php:1759`);
- duplicate/overlapping fields merged; "year read as clause number" and similar mismatches caught by comparing the plan's text with the source text (§4.6 fidelity check).

### 4.5 Step 4 — Build
The validated plan is compiled into the existing structures through the existing projector/normalisers: `cds_json` (sections, `field_placeholder`/`signature_placeholder`/`initial_placeholder`/`insertable_block_placeholder` items, `disclosure_checklist`), `fields_json`, `editor_state.tags/mappings`, `field_mappings` (`mappingType`, `typeKey`, `namedFieldId`, `party`, `editable_by`, `confidence`), `signing_parties`, `insertable_blocks`, header/footer. Result is a **draft** `Template` + `CdsDraft` owned by the agency (`agency_id` set, never shared), linked to the run.

### 4.6 Step 5 — Prove it (the self-check, Ruling 4)
A template is offered as **Ready** only if every check passes. Checks are code, not AI opinion; each produces a pass/fail with evidence stored on the run.

| # | Check | How |
|---|---|---|
| 1 | **Every field is bound or deliberately manual** | No field is "Unassigned"; each manual field has a reason (e.g. "free-text, only the agent knows") |
| 2 | **Every binding resolves** | Test fill: `WebTemplateDataService::resolveCdsTemplate` is run against synthetic fixture records for the document's category (a sale: property + seller(s) + buyer(s) + agent; a lease: property + lessor + lessee + agent + lease terms; with 1 and 2+ parties to cover indexed fields). Every bound field must yield a value (or a declared-empty reason) |
| 3 | **Signer roles complete for the document type** | Rules table per document type (sale: owner party + acquiring party + agent [+ witness]; lease: lessor + lessee + agent; mandate: owner + agent; disclosure: owner (+agent)); every role has at least one signature and the initials surfaces the plan declared; proxy-first order per `ESIGN-CANON` |
| 4 | **Grids detected** | Count of YES/NO/N-A tables in the source = count of grids in the template; item count per grid equals the source's row count |
| 5 | **Dry-run render** | `CdsRendererService` renders the template to HTML (and a PDF through `scripts/html-to-pdf.mjs`) with the fixture data and also blank; no PHP/Blade error, no leftover marker text, no raw `____` blanks left unaccounted, no empty headings |
| 6 | **Fidelity** | Normalised text of the rendered blank template is compared to the source text; every source paragraph is present in order (differences listed). This is what catches a field eaten by a false heading or a clause number stripped from a year |
| 7 | **Rules** | Commission rule (§5.4); no sale-only key in a lease; no HFC/agency-specific literals; letterhead/footer decision recorded |
| 8 | **E-sign readiness** | `Template` passes the same publish checks the builder applies today (`docuperfect:verify-template`-style), and the e-sign eligibility flag is set per the template's own setting (`ESIGN-CANON` §7) |

Outcome states: **Ready** (all pass, no flags), **Ready — N items to confirm** (all hard checks pass but the AI or the validator raised soft flags: low-confidence bindings, ambiguous who-fills, a grid the model was unsure about), **Needs help** (a hard check failed after one automatic repair attempt — the failing evidence is shown). One **repair pass** is allowed: the failing checks and the document model go back to the AI once; the result re-runs steps 3–5. The repair costs are in the audit (§6).

### 4.7 Step 6 — Hand over
- A notification (`PillarEventNotification`, type `docuperfect.import.ready`, severity info; `needs_help` severity warning) goes to the uploader and the agency's document managers, with a link to the run.
- **Ready:** the run page shows the dry-run preview and a single **Approve** action (§10, Q1). No builder visit needed.
- **Ready — N items to confirm / Needs help:** the CDS builder opens pre-filled with a **flagged-items queue** (new): the list of items to confirm, each with the document excerpt, the AI's proposal, its reason, the alternatives, and Accept / Change / Mark manual. Only flagged items are asked. Unflagged items are not shown as needing attention (the builder's confidence dots are corrected to the recomputed confidence).
- Every human decision is stored (accepted AI, changed to X, made manual).

### 4.8 Learning (cheap, optional on each import)
Human changes are written as per-agency `FieldCorrection` rows and offered back to the AI as examples on later imports for that agency (the existing mechanism at `DocxParserService.php:430` matches by text similarity ≥75%). Corrections are never shared across agencies.

### 4.9 Re-run rules (Ruling 3)
- The AI runs again **only** when the template is edited via "re-read" by the user, or the source document is replaced.
- Same file + same `prompt_version` + same dictionary `hash` → **cached plan reused for free** (cache key `(agency_id, file_sha256, prompt_version, dictionary_hash)`; per agency, never across agencies).
- Editing the template in the builder never calls the import AI.

---

## 5. The traps it must handle (all met on Mega's lease, 23 Oct)

Each trap lists: what goes wrong today (root cause) → required behaviour → test. Fixtures are real documents (Mega's lease, the EATS, an OTP, a disclosure, a FICA form), anonymised, stored under `tests/Fixtures/Docuperfect/import/` with the agency's consent (§10, Q4) — plus small synthetic .docx files built per trap.

1. **Everything inside Word tables.** *Today:* `parseTable` (:792) flattens cells to plain text, drops nesting, and `detectMarkers` never walks cells, so a marker in a cell is dead text. *Required:* tables are first-class in the document model; fields/markers/blanks inside cells are detected; label-value layouts (label cell + empty cell) are recognised as a field; nested tables kept; the template renders the table with cell content (bold, lists, fields). *Test:* a lease whose clauses sit entirely in a 2-column table imports with the same fields as its non-table version; a marker in a cell becomes a field.
2. **`<w:b w:val="0"/>` read as bold.** *Today:* :773 treats presence of `w:b` as bold, so unbolded runs make false headings that eat fields. *Required:* resolve bold/italic/underline through run → character style → paragraph style → defaults, honouring `val="0|false|off"`. A heading is a paragraph whose style or *resolved* formatting says so **and** whose text reads like a heading (no field inside, not a sentence). *Test:* a paragraph of explicit `val="0"` runs is never a heading; a style-bold paragraph is.
3. **All-caps marker lines read as headings.** *Today:* :1008 turns `~~~~OTHER_CONDITIONS~~~~`, `%%%%`, `####` lines into headings. *Required:* a line that is, or contains, a marker is never a heading; marker detection runs before heading detection. *Test:* every `ACCEPTED_MARKERS` form on its own line stays a marker.
4. **Commission binding.** *Today:* see §2.4 — first "commission %" in the document is bound to `property.commission_percent`; three keys exist for one concept. *Required:* one canonical commission key per document category in the dictionary (sale mandate → the mandate commission; lease → the letting fee/commission clause; a sale-commission clause *inside a lease* is text, or a manual field, never bound to the property's sale commission); binding chosen by document type **and** clause context (who pays whom, % of what); the validator rejects a commission key whose `applies_to` does not include the document category; the existing `detectCommissionField` is removed from the AI path. *Test:* the lease fixture's sale-commission clause is not bound to `property.commission_percent`; a sale mandate's commission is; the two keys unify in the dictionary.
5. **A year read as a clause number ("2026 Terms…").** *Today:* :971 regex strips the digits. *Required:* a leading number is a clause number only if it fits the numbering sequence of its neighbours (monotonic, consistent depth/format) or the source uses auto-numbering for that list; 4-digit values 1900–2100, quantities followed by units ("30 days", "14 Main Road") and dates are text. The fidelity check (§4.6 #6) catches any remaining case. *Test:* "2026 Terms and conditions apply" keeps "2026"; "14 Main Road, Uvongo" stays an address; a real "14. Deposit" is clause 14.
6. **Mixed auto/typed numbering.** *Today:* two routes (`resolveNumbering` :841 for auto, `detectClauseNumbering` :971 for typed) that do not reconcile. *Required:* numbering is resolved per list with provenance; the output template uses one scheme and keeps the source's visible numbers; cross-references ("see clause 7.2") are checked against the final numbers and flagged when they no longer resolve. *Test:* a document with clauses 1–9 auto-numbered and 10–12 typed produces 1–12 with correct sub-clauses.
7. **Letterhead, headers and footers.** *Today:* headers/footers never read; letterhead is a keyword guess in the first two sections. *Required:* read `header*.xml`/`footer*.xml`; classify each as agency letterhead (company name/logo/registration/FFC lines) → replaced by CoreX's agency letterhead frame (`CdsRendererService.php:51`), page number/footer text → kept as the template's footer, or document-specific text → kept; the decision is recorded in the plan and shown, never silent. Neutral for any agency (no HFC literals). *Test:* a document with a logo header and "Page x of y" footer imports with the frame and footer, no stray header text in the body.
8. **Per-row "initials" rows.** *Today:* automatic page initials are commented out (:280) and initials come only from `####`. *Required:* detect "initials" rows/columns (e.g. a table where each clause row ends with an "Initial" cell, or a footer "Initials: ____ ____") and emit initials surfaces per page or per row, with the roles that initial; verified in the signer-role check. *Test:* a per-row initials table gives one initials surface per row per required role.
9. **Text hidden from the reader.** Hyperlinks, tracked insertions, smart tags, fields, content controls and text boxes (:751) must be read; deletions (`w:del`) are excluded; the 25,000-character cut is removed (long documents are planned in chapters). *Test:* text in each construct appears exactly once in the model.
10. **Signature detection.** Replace the keyword fallback and 15-section window (:2259-:2280) with: signature blocks found by structure (a signature marker, a line of underscores followed by a role label, a signing table), tied to roles by the plan, verified by check #3. *Test:* the EATS fixture's signature blocks are found without the window.
11. **Party prefix map.** Extend `splitPartyAttribute` to agent, witness, spouse, director, trustee, representative, etc.; unknown prefixes become *flagged*, not silently null. *Test:* `~~~~Witness - Full name~~~~` binds.
12. **Sale vs lease** is decided in code from the plan + dictionary categories, not only prompt text (`ImporterAiService` STEP 0), and shown to the user when ambiguous.

---

## 6. Cost, guardrails, audit

### 6.1 Model (Ruling 2)
Add a model alias `import_strong` in `config/services.php` (`services.anthropic.models`), default = the strongest model available at build time, overridable from `.env` and a `DevSetting` without a deploy (as `ellie_model` is). The gateway's alias lookup already accepts any key; the error text that lists only `fast`/`quality` must be updated. Pricing for that model must be present in `services.anthropic.pricing` before use (`AiCostCalculator` prices an unknown model at the most expensive rate rather than R0 — acceptable as a guard, wrong as a plan). Before switching to any newer model, test the known gateway issues noted in `config/services.php` (adaptive thinking is on by default on some newer models so `max_tokens` caps thinking + answer together; the gateway always sends `temperature`).

### 6.2 Cost per import (estimate; to be measured in build step 8)
Assumptions: 20-page lease; document model ≈ 12–18k input tokens; compact dictionary for the agency ≈ 6–10k; prompt/schema/examples ≈ 4k; plan output ≈ 15–30k tokens; optional repair pass ≈ ×1.0. At the configured $5/$25 per million tokens (`claude-opus-4-7` tier) and R16.50/$: one pass ≈ $0.7–1.0 ≈ **R12–R17**; with one repair pass ≈ **R25–R35**; very long document (60 pages, chapter planning) ≈ **R60–R90**. A PDF adds page images (more input). These numbers are planning figures, not promises; the real figure is written to the audit for every import, so the first month of real imports replaces the estimate. For one-off, per-document use this is small against a lease template that is then used for years.

### 6.3 Guardrails
- **Only the document goes out.** The AI receives the uploaded document's content and the dictionary (schema + meanings, no values). No contact, property, deal or agent *records* are ever sent. The test fill uses synthetic fixtures generated in-process, never real records.
- **Privacy scan before sending.** Deterministic detectors (SA ID numbers with Luhn/date check, emails, phone numbers, bank account/branch patterns, long digit runs next to name-like text) run on the document model. If the document looks like a **filled-in example** rather than a blank form, the values are redacted before the call (replaced by typed placeholders, which also tells the AI "this was a filled value → a field") and the user is told. Whether to block instead is Q3.
- **Agency-owned result.** Template, run, plan, flags, corrections, cache are `BelongsToAgency`; nothing is shared, cached or learned across agencies.
- **Spend safety.** `AiSpendGuard::assertAllowed` before every call; `reserveBatch`/`releaseBatch` for the multi-call plan; the 24h R30 alert / R50 cut-out applies (an `AiPausedException` shows "AI is paused — an administrator has been alerted", the run waits; it is never silently dropped). A **per-import hard ceiling** (default set by Johan, Q2) stops a runaway; a **per-agency monthly import cap** (copying `ReportReadSettings`: `import_ai_monthly_cap`, platform daily cap, max pages, min confidence) uses the atomic reserve/release counters; `import_ai_enabled` per agency plus the platform `ANTHROPIC_ENABLED` switch. All caps are settings, not constants, and **reach the Setup Wizard** in the same build (`CLAUDE.md` 10a; `config/agency-onboarding-copy.php`, savers guarded with `$request->has()`).
- **No hard deletes.** Runs, drafts, plans archive and restore.

### 6.4 Audit (Ruling 3: cost per import shown)
- `document_import_runs` stores: agency, uploader, original file name + sha256 + size, document type/category, status/stage, `prompt_version`, `dictionary_version/hash`, model id, `input_tokens`, `output_tokens`, **`cost_zar`** (sum of every call), call count, repair used, outcome state, self-check results (JSON, per check, with evidence), counts (fields, bound, manual, flagged, grids, blocks), cache hit, timings, error text. `document_import_events` is the append-only stage log (who/what/when; every human decision with before/after).
- `ai_usage_events` rows are written for each call (new `SOURCE_DOCUPERFECT_IMPORT_PLAN` / `_REPAIR` constants) with `surface_ref = doc_import:{run_id}`; the run's `cost_zar` is the sum and is also shown on the Admin → AI usage agency page (source breakdown) so cost per import is visible there too.
- Actor attribution through `AuditContext` (never blank).

---

## 7. Screens, permissions, navigation (design-standard floor)

- **Entity:** Document Import Run — full CRUD floor: Create (upload), Read (detail), Update (re-read / replace file), **Archive** (soft delete), **Restore**; no hard delete.
- **Navigation:** the existing *Documents → Templates → Import Document* tab (`DocumentTemplatesTabs` key `import`, route `docuperfect.import.index`) becomes the Imports screen. A tab, not a new sidebar item, per `documents-menu-tabs.md`. The in-progress count shows as the tab badge.
- **List screen:** search — file name, template title, uploader name, document type; sort — started (default newest first), status, outcome, cost, uploader; filter — status (queued/reading/planning/checking/ready/ready-with-flags/needs-help/failed/archived), outcome, document category, uploader, date range; pagination; empty state ("No documents imported yet. Upload your first lease, mandate or disclosure form."). Columns: file, document type, stage with progress, outcome, flags to confirm, cost, uploader, date, actions.
- **Scoping:** OWN / BRANCH / AGENCY at the query layer (`BelongsToAgency`/`AgencyScope`); an agent sees own uploads, a branch manager their branch, a principal/admin the agency; direct URL by ID is blocked, not just unlinked.
- **Detail screen:** stage list with live progress (polling 2.5 s like the P24 importer), check results with evidence, flagged-items queue, dry-run preview, cost and tokens, timeline, Approve / Re-read / Replace / Archive.
- **Permissions** (`config/corex-permissions.php`, middleware + controller checks + sidebar/tab gating): `docuperfect.import.view`, `.create`, `.confirm` (approve/resolve flags), `.manage_all` (agency scope), `.archive`, `.restore`, `.view_cost`. Default grants follow the existing `manage_templates`/`access_docuperfect` holders.
- **API (non-negotiable 7):** `/api/v1/docuperfect/imports` (index, show, status, store, confirm, archive, restore) with named routes, appearing in the Admin → API catalogue; the dictionary read endpoint `/api/v1/docuperfect/dictionary` (agency-scoped).
- **Domain events (non-negotiable 9):** `DocumentImportCompleted`, `DocumentImportNeedsAttention`, `DocumentImportApproved` added to `corex-domain-events-spec.md`; the notification listens to them rather than being called ad hoc.
- **Setup Wizard (10a):** the agency-level settings (`import_ai_enabled`, monthly cap, who is notified, who may approve) are added to the wizard step that covers documents/e-sign (the step is chosen at build time from `config/agency-onboarding-copy.php`) with `explain` and `affects` copy, and the spec's "Deliberately NOT in the wizard" list is updated if any is left out.
- **Queue:** a dedicated queue (`ai-import`) with its own worker unit (QA1: `scripts/qa1/systemd/…`; live/staging supervisor config) and a job `$timeout` below the database queue `retry_after` (3900 s). No `ai` queue worker exists today. Queue workers are not subject to the 300 s web limit, but no import step may run in a web request.

---

## 8. Build steps (≈ one lane-day each; Sonnet lanes; each ends with tests, spec update, CHAT_STARTER)

| # | Build | Tests (single files, via `scripts/lane-test.sh`) |
|---|---|---|
| 1 | **BindingRegistry + dictionary generator.** Providers, `import_dictionary_entries/versions`, `config/dictionary-meanings.php`, resolver refactor to read the registry (no behaviour change), `SyncableReferenceSeeder` regeneration + deploy hook, per-agency overlay, read API + Admin catalogue page. Closes the §3.5 gaps (agent/agency resolver arms, MDF item keys, field types) | meanings completeness gate; parity test (every key resolves on a fixture); per-agency isolation (agency B never sees A's overlay); idempotent regeneration; dictionary hash stable |
| 2 | **Faithful document reader.** New docx reader emitting the document model (§4.2): resolved formatting, cell-level tables, numbering provenance, hidden-text constructs, headers/footers, marker positions, blank candidates. Fixes traps 1, 2, 3 (partly), 5, 6, 7 (read side), 9 | one synthetic .docx per trap + the lease fixture; model snapshot tests; marker-in-cell |
| 3 | **Run, job, progress, cost.** `document_import_runs/events`, `ai-import` queue + worker unit, `ImportDocumentJob` with stages, progress endpoint, `import_strong` alias + pricing, `AiSpendGuard` integration, caps + atomic counters, ledger source constants, run cost, `AiPausedException` handling, failure marking | job stage tests with a faked gateway; cap/reserve/release; cost summed to ledger; pause/resume; timeout → failed state |
| 4 | **AI plan + validator.** Prompt (versioned), ImportPlan JSON schema, markers-win merge, dictionary compaction, validator/normaliser (§4.4), commission rule by document type (trap 4), party prefix map (trap 11), sale-vs-lease in code (trap 12), plan cache | schema accept/reject; invented key demoted; marker wins over AI; commission rule fixtures (sale vs lease); cache hit costs 0 |
| 5 | **Build + prove.** Compiler from plan to `Template/CdsDraft` via existing projector; self-check engine (the eight checks), synthetic fixtures for test fill, dry-run render + PDF, fidelity diff, one repair pass; trap 8 (per-row initials) and trap 10 (signature detection) | each check passes/fails on crafted templates; fidelity catches an eaten field and a stripped year; role-completeness per document type; render gate (`STANDARDS` Standard −1) |
| 6 | **UI + notification.** Imports list (search/sort/filter/pagination/empty state), detail, flagged-items queue in the builder, corrected confidence dots, Approve flow, notification + events, permissions, nav badge, API routes | list/scoping (own/branch/agency, direct URL blocked); permission matrix; notification fired once; browser proof of progress → ready (`verify-alpine-render`) |
| 7 | **PDF path + learning.** Page images + text-layer reader, PDF → model → plan; `FieldCorrection` capture from human decisions and few-shot return per agency; replace the dead `buildPdfStubHtml` path | PDF fixtures (digital and scanned); corrections isolated per agency |
| 8 | **Evaluation + hardening.** Golden corpus of real agency documents (anonymised) with expected plans; metrics (field recall/precision, binding accuracy, flags per document, % Ready without flags); measured cost per import replacing §6.2; Setup Wizard settings; runbook; remove the old two-card import UI and dead code (`ParseDocumentJob`, `ClaudeVisionParserService`, `AiFieldMapperService`) only after Johan's go | corpus run report committed under `.ai/audits/`; wizard saver does not wipe unposted settings; second-agency walk (neutral defaults, no HFC text) |

Tests follow `BUILD_STANDARD` §5: vary document type × structure (tables/no tables) × numbering × party count, never only the example document; no test performs the behaviour it checks. A change to the signing pipeline files requires a test diff in `tests/Feature/Docuperfect/SigningView/` (the e-sign moat gate).

---

## 9. Acceptance criteria — how we know it is done

1. A raw Word lease with **no markers**, all clauses inside tables, mixed numbering, a letterhead and per-row initials imports to **Ready** with no human builder work, and its self-check evidence is on the run page.
2. The sale-commission clause in a lease is **not** bound to the property's commission; a sale mandate's commission **is** bound, to the single canonical key.
3. A year, an address number and a quantity at the start of a sentence are never clause numbers; `val="0"` bold never makes a heading; marker lines are never headings.
4. A document with markers imports exactly as marked; the AI only fills in what the markers leave open; a marker is never changed.
5. The same file re-uploaded costs R0 (cache); editing the template in the builder costs R0; replacing the file re-runs the AI.
6. Every import shows its cost, tokens, model, dictionary version and prompt version in the run audit and on the Admin → AI usage page.
7. An agency with the AI switched off, over its cap, or while AI is paused gets a clear message and no silent failure; nothing is sent to the AI without passing the privacy scan.
8. Agency B (rentals) sees only its own dictionary overlay, its own runs and templates; no HFC wording anywhere in prompts, defaults or generated documents.
9. The dictionary regenerates on deploy, and CI fails if a bindable key has no meaning or does not resolve on the fixture.

---

## 10. Open questions — real decisions for Johan (everything else is decided above)

- **Q1 — Does "ready" publish by itself, or does one person press Approve?** *Recommendation: one Approve click by a person with `docuperfect.import.confirm` (a principal/compliance holder), after seeing the dry-run preview.* It is not builder work (seconds, not hours), and a lease or mandate that goes to clients is the agency's legal document; this keeps one named person accountable. The alternative is automatic publishing on a clean self-check, with the Approve step optional per agency.
- **Q2 — What is the most one import may cost?** A hard ceiling per import and a default monthly cap per agency. *Suggested starting point: R100 per import, 20 imports per agency per month, adjustable per agency in settings.* The estimate (§6.2) is R12–R35 for a normal lease; the ceiling exists only to stop a fault.
- **Q3 — If an uploaded document contains real personal details (a filled-in example), do we redact and continue, or refuse and ask for a blank copy?** *Recommendation: redact and continue, tell the user, and keep the redaction list in the audit* — most agencies upload a signed copy they have to hand.
- **Q4 — May we use Mega's lease and the other real documents (anonymised) as permanent test fixtures?** Without real documents the traps in §5 cannot be locked in by tests.

(Not asked because it is an engineering call: which CDS model is produced — legacy web/CDS, §2.2; the queue and worker layout; the dictionary table design; the order of build steps.)
