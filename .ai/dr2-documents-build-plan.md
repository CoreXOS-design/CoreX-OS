# DR2 "Deal Documents" — Build Plan (investigation + plan)

> **Status:** Read-only investigation, 2026-07-10. Base branch: `origin/Staging`.
> **Headline finding: the feature the GOAL describes is ALREADY BUILT and on `origin/Staging`.**
> WS3 (document spine, spec §7 / D4) and WS4 (distribution matrix + COC, spec §8) are
> complete — models, migrations, services, controllers, routes, event listener, mail,
> `show.blade.php` UI, nav, permissions, and feature tests all exist and are wired.
> The QA1 clone shows 0 rows only because no agent has exercised it yet, **not** because
> code is missing. This plan is therefore a **verify + close-small-gaps** plan, not a
> greenfield build. Building it "from scratch" would duplicate shipped, tested code and
> violate INVESTIGATE→COPY→ADAPT.

---

## 1. What the spec requires (the contract)

`.ai/specs/deal-register-v2-spec.md` governs. Relevant sections, verbatim intent:

- **§7 OTP + document spine (D4)** — "one upload, auto-linked everywhere. A deal becomes
  reachable from the PDF splitter, property, contact, and deal register." Unified anchor =
  `documents.deal_id` FK. PDF splitter gains a deal target; e-sign auto-file links the deal;
  `document_signed` step auto-completion; `deal_step_documents.document_id` populated;
  upload-onto-deal action. Verification gate: "upload/split/sign an OTP once → it appears on
  the deal, the property, and the buyer+seller contacts; the matching pipeline step
  auto-completes; no orphaned file."
- **§8 Distribution matrix + distribute action (the COC killer)** — `deal_stage_document_rules`
  matrix (`stage × document_type × party_role → {delivery_mode, auto_on_stage_tick}`); two
  delivery modes (secure_link+OTP default, direct_attachment); manual "Distribute documents"
  button + auto-on-stage-tick; auto-generated COC filed as a `Document` and distributed;
  immutable `deal_document_access_log`.
- **§4.2 columns** — `documents.deal_id` (D4 anchor), `deals_v2.legacy_deal_id`,
  `deals.deal_v2_id`. **§4.5/§4.6** — the three distribution tables.
- **§10** — every distribution logged on all three pillars via `OutboundProvisionalLogger`.
- **§14** — nav (Supplier Directory, deal-distribution settings) + permissions
  (`deals_v2.distribute_documents`, `manage_distribution_rules`, `manage_suppliers`).
- **§16** — no hard deletes (SoftDeletes), `BelongsToAgency` on every new table,
  input-space robustness, POPIA (secure-link default, immutable access log).
- **Build sequence §15**: WS3 = document spine, WS4 = distribution. Both marked as the build's
  work-streams. Both are **done** on Staging.

`dr2-twin-backfill.md` is orthogonal (register-history pairing); it made `deals_v2.property_id`
and `pipeline_template_id` nullable and added `backfilled_at`. Pre-pipeline twins have no
property/pipeline, so document auto-linking to property is a graceful no-op for them (the
service guards `if ($deal->property_id)`).

---

## 2. As-built inventory on `origin/Staging` (verified this session)

### 2.1 Data model — ALL migrated (confirmed against live clone `corex_qa1`)
| Table | Key columns | Notes |
|---|---|---|
| `documents` | `deal_id` (bigint NULL, FK→deals_v2, indexed), `source_type`, `source_id`, `document_type_id`, `agency_id`, `branch_id`, `deleted_at` | **D4 anchor present.** Canonical filing spine. |
| `document_contacts` | `document_id`, `contact_id`, `party_role` | doc→contact pivot (party role snapshot) |
| `document_properties` | `document_id`, `property_id` | doc→property pivot |
| `deal_step_documents` | `deal_step_instance_id`, `document_id` (NULL), `agency_id`, `file_path`, `file_name`, `uploaded_by_id` | `document_id` now populated by the engine |
| `deals_v2` | `property_id` (NULL), `legacy_deal_id`, `pipeline_template_id` (NULL), `backfilled_at`, `agency_id`, `deleted_at` | deal→property link |
| `deal_v2_contacts` | `deal_id`, `contact_id` (NULL), `agency_service_provider_id` (NULL), `role` enum (incl. provider roles: transfer_attorney, bond_attorney, electrician_coc, entomologist, originator, service_provider) | **deal→contact/provider link — the "calendar-style" party table** |
| `deal_v2_agents` | `deal_id`, `user_id`, `side` | deal→agent link |
| `deal_document_distributions` | full §4.6 shape incl. `secure_token`, `communication_id`, `status`, SoftDeletes, `agency_id` | send record |
| `deal_document_access_log` | `distribution_id`, `event`, `ip`, `user_agent`, `meta`, `created_at` (no updated_at/deleted_at) | immutable audit |
| `deal_stage_document_rules` | §4.5 matrix shape, SoftDeletes, `agency_id` | distribution matrix |
| `agency_service_providers` | §4.4 supplier directory | reuse layer |

### 2.2 Code — ALL present (files exist identically on `origin/Staging`; `git diff --stat origin/Staging` = clean for these dirs)
| Layer | File | Role / wiring |
|---|---|---|
| Service (spine) | `app/Services/DealV2/DealDocumentService.php` | `createDealDocument()` → files a `Document` (deal_id set) then `linkDocumentToDeal()` mirrors deal's **property + every contact party** onto `document_properties` / `document_contacts` (idempotent `syncWithoutDetaching`). `autoCompleteMatchingStep()` completes the matching `document_upload`/`document_signed` step via the engine (config-driven by `completion_config.document_type_id`, exactly-one-or-null). `attachSignedDocumentToDeal()` = e-sign entry point. `resolveDealForProperty()` refuses to guess (0/>1 → null). |
| Service (distribution) | `app/Services/DealV2/DealDistributionService.php` (17KB), `CocRequestGenerator.php` | plan/send/revoke + `autoDistributeForStep()` + COC generation from deal/property/contact data |
| Models | `DealDocumentDistribution`, `DealDocumentAccessLog` (append-only, `update()`/`delete()` throw), `DealStageDocumentRule`, `DealStepDocument`, `AgencyServiceProvider` — all `BelongsToAgency`+`SoftDeletes` (except the immutable log, agency-stamped for provenance only) | |
| Controllers | `DealV2Controller::storeDocument` (line 418) + `downloadDocument` (465, gated), `DealDistributionController` (plan/send/revoke), `SecureDocumentController` (show/otp/verify/download — the secure-link+OTP recipient flow), `DealStepController::uploadDocument`, `Admin\DealDistributionRuleController` (matrix editor) | |
| Listener | `app/Listeners/DealV2/AutoDistributeStageDocuments.php` — handles `DealStepCompleted`; **confirmed registered** via `php artisan event:list`. Fires `autoDistributeForStep()` on stage tick (the red-button moment). Fully guarded (non-fatal). | |
| Event source | `DealPipelineService.php:315` and `:358` dispatch `event(new DealStepCompleted(...))` on complete + on approve | |
| Mail | `app/Mail/DealV2/DealDocumentDeliveryMail.php` | direct-attachment / link delivery |
| Integration points | `SignatureService.php:2127` (e-sign auto-file → `attachSignedDocumentToDeal`), `PdfSplitterController.php:827` (splitter deal target) | both wired |
| UI | `resources/views/deals-v2/show.blade.php` — **Documents section** (line 98): list + upload form (`deals-v2.documents.store`), document-type + link-step selectors, **Distribute-documents** Alpine modal (line 157), **Sent distributions** list with revoke (line 199). Step-level upload (line 534). | |
| Routes | `routes/web.php:661-717` — `deals-v2.documents.store/download`, `deals-v2.distribute.plan/send`, `deals-v2.distributions.revoke`, `deals-v2.secure-doc.*`, `deals-v2.steps.upload`; `web.php:919-921` — `admin.settings.deal-distribution-rules.*`. All `->name()`d and permission-middleware'd. | |
| Nav | `corex-sidebar.blade.php:1525-1567` — DR2 group gated on `access_deal_register_v2`; Supplier Directory + Pipeline Setup subitems present. Distribution rules live on the doc-types/admin settings surface. | |
| Perms | `config/corex-permissions.php:423-436` — `access_deal_register_v2`, `deals_v2.edit`, `deals_v2.distribute_documents`, `deals_v2.manage_distribution_rules`, `deals_v2.manage_suppliers`, `deals_v2.view_overview`; role defaults at :699-703. | |
| Tests | `tests/Feature/DealV2/DealDocumentSpineTest.php`, `DealDistributionTest.php`, `DealDistributionArchiveTest.php`, plus engine/sync/backfill suites | |

### 2.3 The upload → auto-file → link flow (as-built, verified)
```
show.blade upload form  ─POST─▶  DealV2Controller::storeDocument (perm: deals_v2.edit)
  → store file to deals/{id}/documents
  → DealDocumentService::createDealDocument()
        · Document::create(deal_id=deal, source_type='deal_upload', branch_id, agency_id auto)
        · linkDocumentToDeal():  document_properties ← deal->property_id (guarded)
                                 document_contacts   ← each deal->contacts (party_role snapshot)
  → autoCompleteMatchingStep(): if a single ACTIVE document step's completion_config
        .document_type_id == doc.document_type_id → completeStep() through the engine
        (single writer of status) → dispatches DealStepCompleted
             → AutoDistributeStageDocuments → autoDistributeForStep() (auto rules fire)
```
This is exactly the GOAL ("uploads documents to a deal; each auto-files/links to the deal's
linked PROPERTY and CONTACTS; salvage distribution; no hard deletes; nav entry"). It exists.

---

## 3. Reuse vs build

**Reuse (do NOT rebuild — it is shipped and tested):** the entire filing spine
(`DealDocumentService`), the distribution engine + matrix + secure-link/OTP + COC generator,
the listener, the mail, the routes, the `show.blade` Documents/Distribute/Sent UI, the nav,
the permissions, the three feature tests. `documents.deal_id` is the filing spine — the build
already reuses it, does not invent one.

**Genuine gaps found (small — these are the only real work):**

1. **No seeder for default distribution rules.** Spec §8.1 says "Sensible defaults seeded
   (e.g. COC request → electrician at the Electrical COC stage, secure_link, auto on the
   OTP-granted tick)." No seeder writes `deal_stage_document_rules`; the matrix ships empty and
   an admin must build every rule by hand. **Decide with Johan:** ship a seeded HFC-shaped
   default set (registered in `deploy:sync-reference-data` per AT-162, since it is GLOBAL/agency
   reference data that `migrate` won't carry), OR ratify "empty by default, admin-configured"
   as the intended state. If seeding: it must be idempotent and agency-scoped.

2. **QA/exercise gap, not a code gap.** `deal_document_distributions`, deal-linked `documents`,
   and `deal_stage_document_rules` all hold **0 rows** on the QA1 clone — the feature has never
   been run end-to-end against real data. The remaining value is a **live-parity walkthrough**
   (upload on a real deal, confirm three-pillar appearance, tick a stage with an auto rule,
   confirm the secure link demands OTP, confirm the immutable log row). This is verification
   under the done-checklist, not new code.

3. **PDF-splitter deal-target UI surfacing (verify).** `PdfSplitterController.php:827` writes
   through `DealDocumentService`, but confirm the splitter review screen actually exposes a
   **deal** destination selector beside property/contact (spec §7). If the backend accepts a
   `deal_id` the UI doesn't offer, that is the one UI gap to close.

4. **`deal_v2_contacts.created_at`-only (no `updated_at`/soft-delete)** — the party pivot is
   append/detach, not soft-deleted. Not a document concern, but note it if the build ever needs
   to audit party history; documents themselves are correctly soft-deleted.

**Everything else in the GOAL requires no build.**

---

## 4. If a further increment is wanted (beyond gap-close)

These are spec items adjacent to "deal documents" that are the natural next reuse targets, in
priority order — each is small because the spine exists:

- **Seed + expose default distribution rules** (gap 1) — the highest-value close; it is what
  makes the COC-killer work out-of-the-box instead of only after manual matrix setup.
- **Splitter deal-target selector** (gap 3) — completes the "reachable from the PDF splitter"
  clause of §7.
- **Three-pillar comms assertion** (§10) — confirm `DealDistributionService` stamps
  `owner_user_id`, writes `communication_attachments`, and writes `communication_links` for
  `Contact` + `Property` + `DealV2` (the morph already supports all three). Add a feature test
  asserting one send surfaces on deal + contact + property if not already covered by
  `DealDistributionTest`.

---

## 5. Files touched IF the gaps are closed (no greenfield files)

| Action | File |
|---|---|
| NEW (only if Johan rules "seed defaults") | `database/seeders/DealStageDocumentRuleSeeder.php` — idempotent, agency-scoped default matrix rows |
| MODIFY | `app/Console/Commands/.../DeploySyncReferenceData` (or its registry) — register the new seeder so it travels on `git-pull` deploys (AT-162) |
| MODIFY (verify/maybe) | PDF splitter review view — add the deal destination selector if absent |
| NEW test | `tests/Feature/DealV2/DealStageDocumentRuleSeederTest.php` (idempotency + agency scope) — only if seeding |
| No change | models, services, controllers, routes, listener, mail, `show.blade`, nav, permissions — all shipped |

Re-run `php artisan schema:dump` only if a **migration** is added (none is needed — schema is
complete). A seeder is not a migration.

---

## 6. Nav + permissions status

Both **done** (§2.2). DR2 sidebar group gated on `access_deal_register_v2`
(`corex-sidebar.blade.php:1525`); distribution/supplier/rule permissions exist in
`config/corex-permissions.php:423-436` and are wired to route middleware. If a seeder or new
settings surface is added, run `corex:sync-permissions --merge-defaults` (additive) — but no new
permission key is required for the identified gaps.

---

## 7. Test matrix

**Already covered (green suites on Staging):**
`DealDocumentSpineTest` (upload → deal+property+contacts link, step auto-complete, orphan-free),
`DealDistributionTest` (matrix resolve, send, secure-link OTP gate, immutable log),
`DealDistributionArchiveTest` (soft-delete / revoke), plus `DealPipelineEngineTest`,
`DealSyncTest`, `DealV2TwinBackfillTest`, `ProcessDealRagTest`.

**Add only if gaps closed:**
| Case | Assertion |
|---|---|
| Default-rules seeder idempotency | run twice → one row per (agency, step, doc-type, role); agency-scoped |
| Pre-pipeline twin upload | upload on a `backfilled_at` twin (property_id NULL) → document files against deal, property link is a graceful no-op, no 500 (input-space rule) |
| Splitter deal target | splitting with a deal in context writes `deal_id` + property + contact links in one pass |
| Three-pillar comms (§10) | one distribution → `communication_links` for Contact+Property+DealV2, `has_attachments=true`, `owner_user_id`=sender |

Per non-negotiable #13: run **only the single relevant file** during active work
(`php artisan test tests/Feature/DealV2/DealDocumentSpineTest.php`), never the full suite
without Johan's go-ahead.

---

## 8. Doctrine / robustness confirmation (spec §16)

- **No hard deletes:** ✔ all new models `SoftDeletes` except the deliberately immutable
  `deal_document_access_log` (append-only, throws on update/delete).
- **agency_id NOT-NULL landmine (MEMORY AT-203):** the document/distribution writers go through
  Eloquent `create()` with `BelongsToAgency`, so `agency_id` is auto-stamped — **no raw
  `DB::table()->insert()` in the document path** (the only raw write in DR2 is the
  twin-backfill pointer on `deals.deal_v2_id`, which is DR1-side and unrelated). A seeder added
  for default rules **must** stamp `agency_id` explicitly (seeders bypass the request-time
  agency context). The immutable log stamps `agency_id` from its parent distribution.
- **POPIA:** ✔ secure-link+OTP default via canonical `OtpService`; every access → immutable log.
- **Input-space:** ✔ service guards missing property, deleted relation, 0/>1 candidate deal,
  0/>1 matching step — all safe no-ops, documented in `DealDocumentService` docblock.

---

## 9. Recommendation

Report to Johan: **the DR2 deal-documents feature (WS3+WS4) is already built, wired, and
tested on `origin/Staging`.** Do not open a greenfield build. Open instead a short
**verify-and-close** ticket: (1) rule Johan's call on seeding default distribution rules,
(2) confirm the splitter deal-target UI, (3) run the live-parity walkthrough to move the
feature from "built, 0 rows" to "proven in use." This is the CoreX-Operating-Principle-correct
close: no duplicate code, no "good enough for now" — the gaps are named and small.
</content>
</invoke>
