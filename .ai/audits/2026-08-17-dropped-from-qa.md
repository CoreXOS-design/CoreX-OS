# Dropped-from-QA — whole-tree delta (reconcile promotion audit)

**Date:** 2026-08-17 · **Author:** cc2 (lane-2) · **Mode:** READ-ONLY (blob-hash + `git diff/log`; nothing deployed or modified)

## Source-of-truth states (SHAs)
- **QA (should-be-live upper bound):** `c61ca0385` (branch `feat/esign-recipient-enrichment`, QA tip, incl. the 25-commit "month of work")
- **live-before (pre-promotion):** `6b41166ce` (Aug 16 21:48 — commit just before reconcile merge `2af470791`)
- **live-now:** `0415307d4` (branch `main`)
- **staging:** `7faf3c7a9` (branch `reconcile`)
- **common ancestor** of QA/live/staging: `331b31a1c` (Aug 15 11:40)
- Hosts (all on `ubuntu-4gb-nbg1-2`): live=`/corex`, staging=`/corex-staging`, QA=`/corex-qa1`
- ⚠️ cc3 to pin the exact qa→staging / staging→live trigger commits; SHAs above are best-estimate upper bounds.

## Headline
QA's 25-commit stack touched **118 files**. Compared to live-now (exact blob hash, 3-way vs base `331b31a1c`):
- **LANDED** (live == QA): **62**
- **DROPPED** (live == pre-QA base — QA work absent): **26** (9 are intended-hold esign/entity-rep → **17 genuine regressions**)
- **DIVERGED** (live has *reconcile's* version, not QA's): **41** (13 hold → **28 non-hold**)
- **RECONCILE-ONLY** (new work on live, not from QA): **46**
- **Total files differing QA vs live: 113**

**Live did NOT go backward.** For every DROPPED file, pre-promotion live (`6b41166ce`) already held the same old version → the QA work simply **never reached live** (the reconcile merge resolved conflicts by taking the old/live side). The two already-fixed bugs (`$matchEmailSubject`, navy badge) are a separate class (defects in promoted code, not dropped work).

**Every feature below is HALF-DEPLOYED:** its new files/migrations landed (DIVERGED/RECONLY) while the logic changes to *existing* files were DROPPED — so the feature is broken on live, not merely absent.

---

## (A) DROPPED QA WORK — REGRESSIONS (live has the pre-QA version) — 17 files
| Feature (Jira) | Dropped files |
|---|---|
| **P24 IMAP per-agency #3** | `app/Console/Commands/ImportP24Alerts.php` · `app/Services/P24/P24ImapImportService.php` · `config/corex-permissions.php` · `resources/views/corex/settings.blade.php` |
| **Knowledge-base ownership #9** | `app/Http/Controllers/Admin/KnowledgeController.php` · `app/Services/AI/KnowledgeSearchService.php` · `app/Services/AI/DocumentProcessingService.php` · `app/Services/AI/Ellie/EllieToolkit.php` · `resources/views/admin/knowledge/index.blade.php` · `resources/views/admin/knowledge/category.blade.php` · `tests/Feature/AI/EllieRetrievalRepairTest.php` |
| **Payroll onboarding #20** | `app/Models/Payroll/PayrollEarningType.php` · `app/Models/Payroll/PayrollDeductionType.php` · `database/seeders/PayrollEarningTypeSeeder.php` · `database/seeders/PayrollDeductionTypeSeeder.php` |
| **chrome-ext v3.3.7 deeds** (= the 1 commit staging is ahead, `7faf3c7a9`) | `public/chrome-extension/portal-capture/content-cmainfo.js` · `public/chrome-extension/portal-capture/manifest.json` |

## (B) INTENDED HOLD — esign / entity-rep (Johan deliberately kept out) — 23 files
Half-present on live (migrations/presets landed, logic dropped) — needs Johan's ruling: fully land or fully back out.
- **DROPPED (9):** `.ai/specs/contact-entity-type.md` · `app/Http/Controllers/Api/TvaCompanyDirectorsController.php` · `app/Http/Controllers/CoreX/ContactRepresentativeController.php` · `app/Http/Controllers/Docuperfect/ESignWizardController.php` · `app/Models/Contact.php` · `app/Models/ContactRepresentative.php` · `app/Models/Docuperfect/SignatureRequest.php` · `app/Services/Docuperfect/CanonicalInkComposer.php` · `database/schema/mysql-schema.sql`
- **DIVERGED (13):** `app/Http/Controllers/Docuperfect/EsignRecipientPresetController.php` · `app/Models/Docuperfect/EsignRecipientPreset.php` · `app/Services/Docuperfect/SignatureService.php` · `resources/views/corex/contacts/show.blade.php` · `resources/views/docuperfect/esign/wizard.blade.php` · `resources/views/docuperfect/esign/settings/recipient-presets/{form,index}.blade.php` · migrations `…add_capacity_and_proxy_to_contact_representatives` · `…create_esign_recipient_presets_table` · `…add_signer_caption_to_signature_requests` · `…add_proxy_wording_to_esign_recipient_presets` · tests `ContactRepresentativeCapacityProxyTest`, `EsignEntityRecipientTest`
- **RECONLY(1):** `app/Models/Docuperfect/SignatureTemplate.php` (reconcile tenant-isolation)

## (C) DIVERGED — QA work OVERWRITTEN by reconcile's version (not held) — 28 files
Live runs reconcile's version of these, not QA's. Notable — the visible-surface ones:
`resources/views/layouts/corex-sidebar.blade.php` (nav — why Retha's PDF-Splitter entry differs) · `routes/web.php` · `app/Http/Controllers/CoreX/MarketIntelligenceController.php` · `app/Services/Presentations/PresentationPdfService.php` · `app/Models/KnowledgeDocument.php`
P24 #3 partial-landing: `app/Http/Controllers/Admin/AgencyP24ImapSettingsController.php` · `app/Models/AgencyP24ImapSetting.php` · `app/Console/Commands/BackfillP24ImapFromEnv.php` · `resources/views/admin/p24-imap-settings/edit.blade.php` · migration `…create_agency_p24_imap_settings_table` · `tests/Feature/Admin/AgencyP24ImapSettingsTest.php`
Payroll #20 partial: `app/Console/Commands/PayrollSeedDefaultTypes.php` · `app/Observers/AgencyObserver.php`
KB #9 partial: migration `…add_is_global_to_knowledge_documents`
AT-81 (reconcile's version): `app/Console/Commands/ReverseFalseNoResponse.php` · `.ai/audits/2026-08-17-outreach-no-response-false-optout.md` · migration `…create_outreach_no_response_reversal_backups_table`
Other: `app/Console/Commands/BackfillAgencyCompanySettings.php` · `app/Services/P24/P24LocationResolver.php` · `resources/views/corex/_partials/copy-id-btn.blade.php` · migrations `…add_agency_id_to_docuperfect_document_tables`, `…add_agency_id_to_deposit_trust_interest`, `…add_completed_steps_to_user_tour_progress` · tests `DepositTrustInterestAgencyIsolationTest`, `ContactForeignNationalIdTest`, `DocumentAgencyIsolationTest`, `TourStepPersistenceTest`, `PerformanceSettingAgencyOnlyTest`

## (D) RECONCILE-ONLY — new work on live, not from QA — 46 files
Genuine promotion additions (keep): AT-366 report suite, map #4, tenant-isolation/white-label, deeds/TVA, buyers-pipeline, users-whatsapp, tour-engine, privacy/legal, FICA inline-email, MIC copy-id, deposit-trust-interest, P24Controller, and my 3 fixes (match-card, match-results, DeedsCaptureController). Full list in `scratchpad/classified.txt`; examples:
`app/Http/Controllers/Performance/AgencyPerformanceReportController.php` · `app/Services/Performance/*` · `app/Services/Map/MapPinService.php` · `app/Http/Controllers/CommandCenter/ViewingPackController.php` · `app/Http/Controllers/Public/LegalController.php` · `resources/views/components/match-card.blade.php` · `resources/views/corex/contacts/match-results.blade.php` · `resources/views/public/legal/privacy.blade.php` · `app/Http/Controllers/Compliance/FicaController.php` · `app/Http/Controllers/SellerOutreach/EntryPointController.php` · `app/Models/Docuperfect/Template.php` …

---

## Restore plan
**Surgical (recommended)** — restore QA's version of the confirmed-regression files, keeps the promotion's legit new work:
```bash
git -C /corex checkout c61ca0385 -- <path>     # per DROPPED/regression file in (A)
git -C /corex diff --cached                     # review, then commit + deploy
```
Do it per feature: **P24 IMAP #3, Knowledge-base #9, Payroll #20, chrome-ext v3.3.7** (files in §A + their §C partial-landing siblings). Esign/entity-rep (§B) — await Johan's land-or-backout ruling.

**Full rollback (⚠️ DO NOT RUN unless Johan chooses it)** — reverts live to pre-promotion:
```bash
git -C /corex reset --hard 6b41166ce
gunzip -c /mnt/HC_Volume_103099143/db-backups/nexus_os-prelive-promotion-20260816-225913.sql.gz | mysql nexus_os
```
**Discards ALL 26 post-`6b41166ce` commits** = the entire promotion + every hotfix/feature since:
daily-activities Setup · tenant-isolation/white-label · map #4 · AT-366 commission tiles · buyers-pipeline filter · MIC Copy-ID · deeds/TVA ingest · users WhatsApp · ROI report · outreach AT-81 · P24-import hotfixes · viewing-pack logging · **calendar hotfixes (415d5e7ad, 6dcbe826c)** · **FICA inline-email + screening mojibake (ec1e3b85a)** · agent-invite link fix · **my 3 live fixes (deeds 0-date, match-results 500, Good-badge)**.
→ **Surgical strongly preferred** (adds dropped QA work; doesn't erase ~20 validated fixes).

## ⛔ RESTORE EXECUTION — HALTED 2026-08-17 (ground truth corrected against live `origin/main`)

Attempted the surgical restore. Verified each candidate against **current** `origin/main`
(moving fast tonight: `0415307d4`→`8ed4e908`→`354c81dbf` within minutes — cc1/cc3/cc5/cc6
actively promoting). **classified.txt mislabelled the feature infra as DIVERGED; it is in
fact ABSENT from live.** None of the three features is a safe file-only restore:

| Feature | Schema dep on live | Dropped-only restore → | Verdict |
|---|---|---|---|
| **P24 IMAP #3** | `create_agency_p24_imap_settings_table` **ABSENT**; model + controller + edit view + backfill cmd all **ABSENT** | QA import logic calls a model + DB **table that don't exist on live → hard 500** on the P24 import path (actively used — cmainfo capture just fixed) | **HOLD** — needs schema migration + full feature re-land |
| **Knowledge-base #9** | `add_is_global_to_knowledge_documents` **ABSENT** → `is_global` column missing | QA controller/model query `is_global` via `scopeVisibleTo()` → **SQL error on the Knowledge pages** | **HOLD** — needs schema migration + `KnowledgeDocument` model (QA version is a clean additive superset of live's Wave-3 model) |
| **Payroll onboarding #20** | none — type tables pre-exist (`2026_04_23`) | models gain `seedDefaultsFor()` but **nothing calls it**: live `AgencyObserver` has no payroll wiring (and carries a **reconcile-only** `company_*` PerformanceSetting block QA lacks — wholesale restore would drop it); `PayrollSeedDefaultTypes` cmd **ABSENT** | **HOLD** — dropped-only = inert; functional re-land needs an observer 3-way merge (keep company_* block) + the absent command + a live data-seed run |

**Why halted (compliant with the task's own guardrail — "if a file conflicts/looks
interdependent with the promotion's new work, HOLD and flag, don't force"):**
1. Two of three require a **schema migration absent from live** — restoring code-only would
   500 live pages, not fix them. Schema change on live needs Johan's explicit order.
2. All three are **full features** (migration + model + controller + wiring), not isolated
   view/logic bugs. Surgical file-injection to live was right for the three fixes already
   shipped (match-card colour, match-results 500, cmainfo 0-date) — it is the **wrong tool**
   for feature-scale work that carries schema + cross-file wiring.
3. `origin/main` is a moving target tonight — restoring onto it compounds risk.

**Recommendation:** re-land these three as **coherent feature sets via the normal
QA1 → Johan → Staging → live flow** (they already exist intact on QA `c61ca0385`), NOT as a
live surgical restore. The chrome-ext v3.3.7 deeds files remain excluded (cc5). Esign/entity-rep
(§B) still awaits Johan's land-or-backout ruling. **Nothing was deployed in this attempt.**

## 🧭 MIGRATION-AWARE RESTORE PLAN (2026-08-17, careful pass — NOT executed)

Source of every file below: QA `c61ca0385` (intact). File tags:
**[CO]** clean `git checkout c61ca0385 -- <file>` (live currently == base for it — additive);
**[MERGE]** shared/diverged on live — hand-merge QA's hunk in, NEVER wholesale checkout (would clobber other work);
**[NEW]** absent on live — checkout creates it;
**[MIG]** schema migration — must run before the code that reads the new column/table.

⚠️ **`origin/main` is moving tonight** (cc1/cc3/cc5/Johan pushing every few minutes;
live HEAD `ce895e968` at time of writing). Re-verify each **[CO]** blob against the THEN-current
`origin/main` at execution time (`git rev-parse origin/main:<file>` == base?); if it advanced,
demote it to **[MERGE]**. Do all restores in a fresh worktree off the THEN-current `origin/main`.

### ✅ SAFE-NOW — no schema dependency — Payroll onboarding #20
Type tables pre-exist (`2026_04_23_100006/100007`). No migration. Dropped-only is inert, so a
coherent (not blind) restore is:
1. **[CO]** `app/Models/Payroll/PayrollEarningType.php` — adds `seedDefaultsFor()` + DEFAULTS
2. **[CO]** `app/Models/Payroll/PayrollDeductionType.php` — same
3. **[CO]** `database/seeders/PayrollEarningTypeSeeder.php`
4. **[CO]** `database/seeders/PayrollDeductionTypeSeeder.php`
5. **[NEW]** `app/Console/Commands/PayrollSeedDefaultTypes.php` — `payroll:seed-default-types {agency?} {--all}`
6. **[MERGE]** `app/Observers/AgencyObserver.php` — insert ONLY the 2 `PayrollEarningType/DeductionType::seedDefaultsFor((int)$agency->id)` lines after `ContactIdentifierLabel::seedDefaultsFor(...)`. **Keep the reconcile-only `company_*` PerformanceSetting block** — QA's version lacks it; a wholesale checkout would delete a live tenant-isolation fix.
- **Run order:** deploy code → `php -l` the 3 PHP + command → `view/route/config:clear` (www-data) → new agencies auto-seed via observer.
- **Backfill existing agencies = a live DATA change** → `php artisan payroll:seed-default-types --all` (idempotent, `firstOrCreate`). **Johan-gated** — do not run without his explicit order.
- Code-restore itself is regression-free (additive; nothing else calls these models today).

### ⛔ NEEDS-MIGRATION-FIRST — Knowledge-base ownership #9
Live lacks the `is_global` column. Order is strict — **[MIG] before code**, else the Knowledge pages SQL-error.
1. **[MIG][CO]** `database/migrations/2026_08_21_000001_add_is_global_to_knowledge_documents.php` → **run `php artisan migrate --force` on target first**
2. **[MERGE]** `app/Models/KnowledgeDocument.php` — QA version is a clean **additive superset** of live's Wave-3 model (keeps `BelongsToAgency`, adds `is_global` fillable+cast + `scopeVisibleTo()`). Confirmed by diff: only additions. Safe to take QA's whole file **iff** live's current `KnowledgeDocument` still == the Wave-3 version at execution (re-diff first).
3. **[CO]** `app/Http/Controllers/Admin/KnowledgeController.php`
4. **[CO]** `app/Services/AI/KnowledgeSearchService.php`
5. **[CO]** `app/Services/AI/DocumentProcessingService.php`
6. **[CO]** `app/Services/AI/Ellie/EllieToolkit.php`
7. **[CO]** `resources/views/admin/knowledge/index.blade.php`
8. **[CO]** `resources/views/admin/knowledge/category.blade.php`
9. **[CO]** `tests/Feature/AI/EllieRetrievalRepairTest.php`
- **Run order:** migrate → deploy code → `php -l` → clears (www-data) → load `/admin/knowledge` + a category + trigger Ellie RAG → confirm no SQL error.
- Self-contained schema (one additive nullable column) — low risk once migration runs.

### ⛔ NEEDS-MIGRATION-FIRST — P24 IMAP per-agency #3 (HIGHEST RISK — live pipeline)
Live lacks the whole per-agency layer (table + model + controller + UI). Restoring import logic
without the table = hard 500. It also shares the live P24-import pipeline with **reconcile-only
hotfixes that must NOT be reverted**.
1. **[MIG][CO]** `database/migrations/2026_08_27_000002_create_agency_p24_imap_settings_table.php` → **migrate first**
2. **[NEW]** `app/Models/AgencyP24ImapSetting.php`
3. **[NEW]** `app/Http/Controllers/Admin/AgencyP24ImapSettingsController.php`
4. **[NEW]** `app/Console/Commands/BackfillP24ImapFromEnv.php`
5. **[NEW]** `resources/views/admin/p24-imap-settings/edit.blade.php`
6. **[CO]** `app/Services/P24/P24ImapImportService.php`
7. **[CO]** `app/Console/Commands/ImportP24Alerts.php` — ⚠️ **verify against reconcile-only hotfixes** `app/Http/Concerns/AppliesP24Location.php`, `app/Services/P24/P24LocationResolver.php`, `app/Jobs/ConfirmP24PropertyRowJob.php` (suburb/city resolution, `mandate_type` default). QA's import predates them — reconcile that QA's import path still routes through the hotfixed location/mandate logic; do NOT overwrite the hotfix files.
8. **[MERGE]** `config/corex-permissions.php` — add ONLY the P24-IMAP permission keys
9. **[MERGE]** `resources/views/corex/settings.blade.php` — add ONLY the P24-IMAP settings block
10. **[MERGE]** `routes/web.php` — add ONLY the `p24-imap-settings` routes
11. **[MERGE]** `app/Http/Controllers/CoreX/MarketIntelligenceController.php` — port ONLY the #3 hunk
12. **[CO]** `tests/Feature/Admin/AgencyP24ImapSettingsTest.php`
- **Run order:** migrate → deploy model/controller/view/cmd → merge shared files → deploy import logic LAST → `php -l` → clears → **restart the P24 import worker** → smoke-test one import end-to-end before trusting it.
- **Do not batch with the other two.** This one wants its own QA1 → Johan → Staging → live pass.

### Recommended sequencing
Route all three through **QA1 → Johan → Staging → live** (normal flow) as coherent features —
they exist intact on QA `c61ca0385`. If Johan wants a live hotfix path instead: **Payroll #20 code
first** (safest, no schema), then **KB #9** (one additive column), then **P24 IMAP #3** last and
alone. Migrations (`[MIG]`) and the Payroll backfill run are **live schema/data changes → each
needs Johan's explicit per-action order.**

## Baselines (proven intact, gzip -t OK)
- Code: tags `pre-reconcile-qa1-20260814` (`c754bba78`), `pre-reconcile-staging-20260814` (`ddd0ed0eb`), `pre-reconcile-live-20260814` (`712f937b2`); Saturday-good QA `fe8236dcf`; QA tip `c61ca0385` — all on `origin`.
- DB: `nexus_os-prelive-promotion-20260816-225913.sql.gz` (pre-promotion live) · `reconcile-backups-20260814-194457/{live-nexus_os,staging-hfc_staging}.sql.gz` · nightlies `nexus_os_nightly_2026081{4,5,6}` — all readable.
