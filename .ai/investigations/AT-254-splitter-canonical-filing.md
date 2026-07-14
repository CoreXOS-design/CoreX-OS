# AT-254 — PDF splitter: OTP + route split docs through canonical filing
_Investigation (investigate → report → build). 2026-07-13. Reporter: Johan (Elize walkthrough). No code changed yet._

## The ask (AT-254)
Buyers/sellers send multi-doc packs; the agent splits + files each. (1) DR2 upload-docs **stays**. (2) Splitter type list must include **OTP**. (3) **One filing truth** — splitter's types from the same canonical registry as pipeline/DR2 (no forked list). (4) A **split OTP files by the SAME rules** as a pipeline/DR2 OTP (deal + property + parties, states/triggers, confidence-based) — route through the existing path, don't duplicate. (5) **Fix the class** — every split type enters its canonical filing pipeline (FICA too).

## What's ALREADY true (good news)
- **One type registry, not forked.** `document_types` is the single vocabulary; the splitter's `SplitterDocType` and `DocumentType` are two models over that **same table** (`SplitterDocType.php:13`); DR2 confirms it ("SHARED truth with the PDF splitter — no parallel type system", `Dr2\DealDocumentController.php:17,63-68`); e-sign templates FK to it too. → **Req 3 (selectable list) is essentially met.**
- **OTP already exists** as a seeded type — so "add OTP" is mostly done at the vocabulary level (`2026_03_03_000002...:32`; classifier bucket `PdfSplitterController.php:1181`, priority `:1230`).
- The splitter already reuses the deal **step-completion** engine: `DealDocumentService::autoCompleteMatchingStep()` (`PdfSplitterController.php:827`).

## The real gaps (the AT-254 work)

### GAP 1 — 🔴 TWO OTP slugs; distribution keyed to the wrong one
There are **two** OTP types in `document_types`:
- **`offer_to_purchase`** — what the splitter classifies + files as; `contact_roles=[seller_owner, buyer]` (`2026_06_27_120000...:46`).
- **`otp`** — added later for e-sign; **the slug the party-distribution matrix seeds the 4-party OTP default against** (`otp → [seller, buyer, bond_originator, transfer_attorney]`, `DocumentDistributionMatrixSeeder.php:25`).

→ A splitter-filed OTP carries `offer_to_purchase`, but the canonical 4-party distribution rule is keyed to `otp` — **they never match**. Routing the splitter "through canonical rules" silently no-ops on party distribution until the slugs are reconciled. **Decision needed (Johan): consolidate to ONE OTP slug (which one is canonical — `otp`?), or map `offer_to_purchase`→`otp` at the registry.**

### GAP 2 — 🟠 Two create-and-attach paths (the heart of Req 4)
- **Canonical:** `DealDocumentService::fileDealDocumentFromDeal()` (`DealDocumentService.php:50`) — the intended one reuse point (its docblock literally names "PDF-splitter deal target" as a funnel-through ingress). Files Document + property (`syncWithoutDetaching`) + the property's linked contacts + optional pipeline step; bridges DR1 twin → `deal_v2_id`.
- **Splitter:** duplicates its own `Document::create` (`source_type='pdf_splitter'`) + property attach + **per-page explicit contact** attach + **Save-To** (property vs contact) destination logic, inline in `PdfSplitterController::fileGroupsToDestinations()` (`:743-820`).

→ Two divergent paths. The splitter's has capabilities the canonical lacks (**explicit per-page contacts**, **Save-To switch**); the canonical has the deal-twin/contact bridge the splitter bypasses. **No single method** takes `(file, doc-type, deal + property + explicit contacts)`. Req 4 = unify: **extend `DealDocumentService` to accept explicit contact ids + Save-To, then have the splitter call it** instead of re-implementing create+attach.

### GAP 3 — 🟠 Party-destination rules are forked (`contact_roles` vs the distribution matrix)
The splitter routes parties by `document_types.contact_roles` (OTP → seller_owner+buyer) + `AgencyComplianceDocTypeService` Save-To. DR2/AT-228 distribution routes by the `DocumentDistributionMatrix` (`deal_stage_document_rules`, OTP → 4 parties). These are **two different party-rule stores that disagree** for OTP. Req 4's "parties per the distribution/filing rules" needs one canonical source. **Decision needed (Johan): is `DocumentDistributionMatrix` the canonical party-destination authority the splitter must use (superseding `contact_roles` for filing), or do they serve different purposes (matrix = who to SEND to via AT-228; contact_roles = who to ATTACH to on file)?**

### GAP 4 — 🟡 Splitter OCR classifier is a hardcoded list (internal drift)
`classifyPage()`/`resolveLabel()` hardcode 11 doc-type buckets (`PdfSplitterController.php:1143-1189, 1227-1231`), NOT derived from `document_types`. A new type added in Settings is selectable but never auto-detected; a renamed/removed seeded slug breaks the classifier. Not a blocker for OTP (it's in the 11), but the "fix the class / no drift" spirit says derive the classifier's known-slug set from the DB.

### Confidence-based filing (already correct — don't rebuild)
"File where confidence exists" = refuse-to-guess: `resolveDealForProperty` files to property/contacts only when exactly 1 active deal (else null, no mis-anchor, `:175-187`); `findMatchingStep` 1-or-null (`:306`); splitter requires an explicit property and blocks contact-only types with no contact (Misfiled register fallback, `:563-579, 807-820`). Reuse as-is.

## Proposed build plan (on approval + Johan's two decisions)
1. **Reconcile the OTP slug** (GAP 1) — consolidate `offer_to_purchase`↔`otp` to one canonical slug across registry + classifier + distribution seed + splitter (migration + data-fix; travels via deploy:sync-reference-data).
2. **One create-and-attach path** (GAP 2) — extend `DealDocumentService` to accept explicit contact ids + Save-To destination; refactor `fileGroupsToDestinations` to call it. Both DR2 and splitter funnel through one method (fix-the-class).
3. **Unify party destinations** (GAP 3) — per Johan's decision, have the splitter resolve parties from the canonical authority.
4. **Fix the class for all pack types** — verify FICA (`FicaWetInkService`), ID/POR, disclosure route canonically; add a test that splitter output for each type enters the same pipeline as a DR2/pipeline filing of that type.
5. **DR2 upload-docs untouched** (Req 1).
6. Optional: derive the OCR classifier's known-slug set from `document_types` (GAP 4).
Verify chain → dual-deploy (staging + qa1, surgical `migrate --path`) → m4 certifies.

## Two decisions I need from Johan before building
- **A. OTP slug:** consolidate to `otp` (the distribution-matrix + e-sign slug) and retire/alias `offer_to_purchase`? (My recommendation: yes — `otp` is the one the canonical distribution + e-sign already use.)
- **B. Canonical party-destination authority for filing:** is `DocumentDistributionMatrix` the source the splitter must file parties by, or do `contact_roles` (attach-on-file) and the matrix (send-to via AT-228) stay distinct concerns? (This determines whether GAP 3 is a merge or a clarified separation.)

---

## BUILD STATE (2026-07-14) — for the fresh-head session

**Decisions locked (Johan):** A = consolidate to `otp`. B = `contact_roles` (attach-on-file party truth) and `DocumentDistributionMatrix` (AT-228 send rules) stay DISTINCT authorities; splitter files parties via `contact_roles` through `DealDocumentService`.

**Decision A — BUILT, on branch `AT-254` (`8812f92b`), NOT yet deployed.**
- Migration `database/migrations/2026_07_31_000001_consolidate_otp_document_type.php` (slug-lookup, idempotent): carries `offer_to_purchase`'s contact_roles `[seller_owner,buyer]` onto canonical `otp`, repoints `documents` + `docuperfect_templates` FKs, retires `offer_to_purchase` (soft-delete + is_active=false).
- `PdfSplitterController` classifier now emits `otp` (lines ~1181 bucket + ~1228 priority).
- Env data (qa1): otp id=23 (20 distribution rules, contact_roles WAS null), offer_to_purchase id=10 (contact_roles seller_owner+buyer, 4 docuperfect templates, 0 documents). ids differ per env → migration uses slug lookup.
- Template.php:333-341 already blocks BOTH slugs — no change needed.
- **NEXT for A:** dual-deploy (ff AT-254→Staging, deploy staging+qa1, surgical `migrate --path=.../2026_07_31_000001_consolidate_otp_document_type.php`), then Tinker-verify: offer_to_purchase is_active=0, otp.contact_roles set, splitter classifies OTP→otp.

**Decision B — NOT built (the fresh-head build):**
- Entry: `DealDocumentService::fileDealDocumentFromDeal(Deal,$attrs,User)` (`app/Services/DealV2/DealDocumentService.php:50`) is the intended one reuse point (docblock names "PDF-splitter deal target"). It files deal(via deal_v2_id)+property+property-contacts+step.
- Gap: the splitter's `PdfSplitterController::fileGroupsToDestinations()` (~:743-820) re-implements Document::create + property/contact attach with capabilities the service LACKS: explicit per-page contact ids + Save-To (property-vs-contact) destination.
- **Build:** extend `DealDocumentService` with a method (or params) accepting explicit contact ids + Save-To destination (keep contact_roles as the party source, decision B), then refactor `fileGroupsToDestinations` to call it — one create+attach path. Fix-the-class: verify FICA (`FicaWetInkService`) + ID/POR route canonically too. Keep DR2 upload untouched (Req 1). Add a test that splitter output for a type enters the same pipeline as a DR2 filing of that type. Verify chain → dual-deploy → m4 certifies.
- Deferred/known: splitter OCR classifier is a hardcoded 11-slug list (GAP 4) — derive from DB if fixing the class fully.
