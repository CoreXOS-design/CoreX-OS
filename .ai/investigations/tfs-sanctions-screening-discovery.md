# TFS Sanctions Screening — Discovery (build not started)

_Discovery for Johan, 24 Jul 2026. In-app automatic screening of a FICA contact's Name + ID/Passport
against FIC's official Targeted Financial Sanctions list. DISCOVERY ONLY — no build until greenlit._

## 1. The XML source + reachability

- **Endpoint:** `https://tfs.fic.gov.za/Pages/TFSListDownload?fileType=xml` — **POST, not GET**.
  - GET returns the HTML *download landing page* (buttons only).
  - POST with **empty body + `Content-Length: 0`** returns the file. (A body-less POST → HTTP 411 Length Required; send `--data ""`.)
- **Reachability from the German cc box:** **NOT blocked today.** POST → `HTTP 200`, `Content-Type: application/xml`, `Content-Disposition: attachment; filename="Consolidated United Nations Security Council Sanctions List.xml"`, **858 KB**. (The earlier broken iframe was NOT an IP block — it was `X-Frame-Options: DENY`, confirmed present on every FIC response.)
- **Server:** Microsoft-IIS/10.0 / ASP.NET MVC.

### Schema (`NewDataSet` → repeated `<Table>` / `<Table1>` rows)
- **1002 records: 730 individuals, 272 entities.**
- Individual fields: `IndividualID`, `ReferenceNumber` (listing ref, e.g. `QDi.430`, `KPi.033`), `FullName`, `ListedOn`, `Comments`, `IndividualDateOfBirth`, `IndividualPlaceOfBirth`, `IndividualAlias` (repeats — 564 records have ≥1), `Nationality`, `IndividualDocument`, `IndividualAddress`.
- Entity fields: `EntityID`, `ReferenceNumber`, **`FirstName` (this holds the entity NAME)**, `ListedOn`, `Comments`, `EntityAddress`, `EntityAlias` (repeats), `Title`.
- **Quirks that the parser must handle:**
  - `IndividualDocument` is a **single comma-joined string mixing types**: e.g. `"National Identification Number, 19670704052, Passport, 420985453, Passport, TB162181"` — must be split into typed (type,value) pairs.
  - **Two date formats in the same file:** ISO `2021-11-23` AND `DD-MM-YYYY` `11-09-2017`.
  - `FullName` carries leading/trailing/multiple spaces (`" RI  WON HO  "`) → normalise whitespace.
  - Entity name is in `FirstName`, not `FullName`.
  - **No global list version / publish date in the XML** (root has no attributes) → we version by fetch timestamp + content SHA.
  - `ApplicationStatus` appears on 2 records only — anomaly, ignore.

### Sanitised example records
- **Individual (alias + multi-doc):** ref `QDi.430`, FullName `EMRAAN ALI`, DOB `1967-07-04`, PoB `Rio Claro, Trinidad and Tobago`, Alias `Abu Jihad TNT`, Nationality `Trinidad and Tobago`, Document `National Identification Number, 19670704052, Passport, 420985453, Passport, TB162181`.
- **Individual (passport only):** ref `KPi.033`, FullName `RI WON HO`, DOB `1964-07-17`, Nationality `DPRK`, Document `Passport, 381310014`.
- **Entity:** ref `KPe.053`, FirstName `PROPAGANDA AND AGITATION DEPARTMENT (PAD)`, ListedOn `11-09-2017`, Address `Pyongyang, DPRK`.

Copy of the fetched file: `scratchpad/tfs-real.xml` (858 KB; not committed — it is daily-changing data, the ingest fetches it fresh).

### ⚠ Scope risk (biggest compliance question)
The file is the **UN Security Council Consolidated list** (all refs are UN committee prefixes QDi/KPi/KPe…). SA's TFS regime also includes **domestic designations under POCDATARA** by the SA President. If this XML button is UN-only, screening against it alone could **miss an SA-designated party**. **Must confirm with FIC/Johan** whether SA-domestic designations are in this file or a separate feed.

## 2. Current FICA / TFS model + workflow

- **Model:** `App\Models\FicaSubmission` → table `fica_submissions`. Route `/corex/compliance/fica/{submission}` (`compliance.fica.show`). Modal partial `resources/views/compliance/fica/partials/tfs-panel.blade.php`.
- **Identity being screened:** `form_data` (JSON, cast array) → `personal.full_name`, `personal.id_number`, `personal.date_of_birth`, `personal.nationality`; fallback `contact->full_name`. Contacts also have a canonical `contacts.id_number` (string 20, with `id_number_captured_at`/`id_number_source` audit). Entity name from `form_data.entity.{company_name|trust_name|partnership_name}` + `entity_type` (natural/company/trust/partnership).
- **Is a TFS outcome persisted? NO.** There is no tfs/sanction column, flag, timestamp, or audit row anywhere. `EvidenceGatheringService` states it outright: *"Sanctions list screening not tracked in CoreX as a structured table — manual workflow via tfs.fic.gov.za."* The modal is **pure convenience — nothing is recorded.** (The only "screening" in the codebase is the unrelated **Employee Screening** staff-vetting feature on `User`.)
- **Where the step surfaces today:** the TFS button sits in the agent-review UI (`show.blade.php:351`), immediately after the Alpine **verification checklist** (`identity_docs`, `address_docs`, `authority_docs`, `is_vip`, `suspicious`, `consistent`) and just before the **Agent Approve** form (POST `compliance.fica.agent-approve`, statuses: draft→submitted→under_review→agent_approved→approved…). So "Screened & passed / Review required" naturally becomes a **new checklist item + a gate on agent-approve/compliance-approve**.

## 3. Proposed build plan (for greenlight — NOT started)

### Tables (sanctions list = GLOBAL reference data, not agency-scoped)
1. `sanctions_list_entries` — `list_source` (`FIC_UN_CONSOLIDATED`, room for `FIC_SA_DOMESTIC`), `record_kind` (individual|entity), `reference_number`, `primary_name`, `normalised_name` (idx), `date_of_birth` + `dob_raw`, `place_of_birth`, `nationality`, `designation`, `address`, `comments`, `listed_on`, `import_id` (FK), `raw_fragment`.
2. `sanctions_list_aliases` — one row per alias, `alias`, `normalised_alias` (idx), FK entry. (alias-aware name match)
3. `sanctions_list_identifiers` — parsed from `IndividualDocument`: `type` (passport|national_id), `value`, `normalised_value` (idx), `country?`, FK entry. **The exact-match spine.**
4. `sanctions_list_imports` — freshness/audit: `list_source`, `fetched_at`, `http_status`, `content_sha256`, `record_count`/`individual_count`/`entity_count`, `status` (success|unchanged|failed), `error`, `file_bytes`. One row per daily run; **this is the "list version"** (no version in the XML).
5. `fica_tfs_screenings` — per submission: `fica_submission_id`, `screened_name`, `screened_id_number`, `screened_dob`, `outcome` (passed|review_required|hit), `import_id` (which list version), `candidate_matches` (json), `screened_by`/`screened_at`, `decision`/`decided_by`/`decided_at`/`notes`. **Audit-truth of every screen.**

### Ingest command + schedule
- `php artisan tfs:ingest` — POST (empty body) → parse → upsert entries/aliases/identifiers in a transaction → write a `sanctions_list_imports` row. Idempotent: compute `content_sha256`; if unchanged since last success, skip re-parse but still log an `unchanged` row (proves freshness).
- Also accept `--file=path` (manual/proxy fallback) so a geo-blocked day can be covered by an uploaded file.
- Schedule daily ~03:00 SAST in `Console/Kernel`.
- **German-IP block handling:** works today (200). If it starts 403-ing/returning non-XML → import `status=failed` + **alert** (never silent). Hardening options to decide: SA egress/proxy, or admin manual-upload path (the `--file` hook).
- **Staleness guard (compliance-critical):** if newest successful import older than N days (e.g. 3) → the app **must not auto-pass**; screening degrades to `review_required (list stale)`.

### Matching (favour false-flags over false-passes — never silently pass)
- **Exact ID/passport = HIT.** Normalise contact `id_number`/passport (strip spaces, uppercase) → lookup `sanctions_list_identifiers.normalised_value`. Any exact match → `hit`, hard-block auto-pass, surface the entry.
- **Alias-aware name = FLAG.** Normalise name (uppercase, collapse whitespace, strip diacritics/punctuation) → compare vs `normalised_name` + `normalised_alias`. Exact-normalised or high token-overlap/Levenshtein → `review_required` with candidates surfaced; DOB corroboration escalates confidence.
- **Borderline → `review_required`** with candidate list for the CO.
- **Clean (no ID hit, no name flag) → auto `Screened & passed`**, stamped with the `import_id` it screened against.
- Fuzzy threshold deliberately over-inclusive; auto-pass also requires a fresh list (staleness guard).

### UI
- Replace the manual modal's role with an **automatic result panel** in the FICA review: green *"Screened & passed — {name}/{id} — FIC/UN TFS list {fetched_at}"*; amber *"Review required — N candidates"* (each with Confirm hit / Clear false-positive + note); red *"Sanctions HIT — exact ID match"* hard block.
- Outcome **gates agent-approve/compliance-approve** (cannot approve with an open hit / undecided review).
- Keep the "Open FIC TFS in new tab" link + the current fallback panel (Johan: leave it) as a manual cross-check.

### Audit / freshness
- `sanctions_list_imports` → "list last updated {date}" shown in UI. `fica_tfs_screenings` → per-contact audit (what/when/against which version/who decided). Emit a `fica.screened` domain event; align with the AT-321/327 AuditContext pattern.

### Worries to flag
1. **UN-only vs UN+SA-domestic scope** — the compliance hole; confirm before trusting auto-pass.
2. **No list version in XML** — we version by fetch/SHA; staleness guard mandatory.
3. **German-IP fragility** — open today, could geo-block; must fail loud + degrade, not silent-pass.
4. **Data quirks** — mixed date formats, name whitespace, comma-joined multi-doc string, entity name in `FirstName`.
5. **False-positive load** — common-name clients will flag; CO clear-with-note flow must be fast or it becomes noise.
6. **Liability** — "Screened & passed" is a compliance assertion; the version-stamped audit row is what defends it.
