# PPRA Inspection Pack — Specification

**Status:** Draft v2 — spec-only, no code yet
**Author:** Claude (senior engineering), from the read-only PPRA s25 investigation of 2026-09-28
**Date:** 28 September 2026
**Spec location:** `.ai/specs/ppra-inspection-pack.md`
**v2 amendment (28 September 2026), Johan's ruling:** this is a **pure Admin feature** — moved from Compliance to the Admin area, gated admin-only (no branch_manager, no agent). The goal is explicitly stated: *"the best inspection report an inspector has ever seen"* — the generated PDF is a first-class deliverable in its own right, not a manifest tacked onto a ZIP. §6.2 (the Inspection Report) is the spine of this document; everything else exists to feed it.
**Related specs:**
- `.ai/specs/whistleblower-compliance-spec.md` (pattern source for the `agency_document_type_configs`/`AgencyComplianceProvision` vault reused wholesale here, and — important — its §7 "whistleblow_email_log.agency_id bug" post-mortem: every new log/audit table in this spec sets `agency_id` explicitly at every insert call site, never relies on `BelongsToAgency`'s implicit `Auth::user()` stamp. Do not repeat that bug class. This is now an Admin module, not Compliance, so it does not share that module's nav or permission tree — only its data patterns.)
- `.ai/specs/multi-tenancy.md` (global scope / agency isolation)
- `.ai/specs/corex-domain-events-spec.md` (AT-235 notification gateway, reused for the "pack ready" alert)
- `.ai/specs/agency-onboarding-setup.md` (Setup Wizard surfacing contract — §6.1 subset-post gotcha)
- `.ai/CODEBASE_MAP.md`, `.ai/BUILD_STANDARD.md`

---

## 1. Purpose

A PPRA Property Practitioners Act s25 inspection notice asks an agency to have thirteen categories of documents and records ready (items a–m below). Today that readiness is scattered across at least three independent CoreX systems (the agency-documents vault, the per-agent FFC dashboard, and per-property/per-deal document gates) with no single view of "are we ready, and if not, what's missing" — confirmed by a read-only investigation on 2026-09-28 (see conversation log; no dedicated spec existed for this before now).

This module gives an **admin** one screen under **Admin** — the **PPRA Inspection Pack** — that shows live compliance status against every s25 item, links straight to whatever is missing, tracks a remediation date against every open gap, and produces two deliverables:

1. **The Inspection Report** — a polished, print-ready PDF: cover page, table of contents, a gaps-with-remediation-dates summary, a detailed section per item (a–m), and a full practitioner FFC table. This is the headline artifact — the thing an admin hands an inspector, or reads from, cover to cover. Generated on demand, fast (no file bundling), always reflects live data.
2. **The full Inspection Pack** — a ZIP containing the Inspection Report *plus* every actual source document (agency-vault files, FFC certificates, sampled deal/rental files, mandate/MDF files). Generated as a background job because real-file bundling is slow; the Report inside it is the exact same artifact as (1), frozen at generation time.

This is **not** a new compliance engine. Every item below is built by **reusing** an existing CoreX system wherever one already computes the right answer (the agency-documents vault, `MarketingReadinessService`, the FFC dashboard, DR2/rentals lists, the global `document_types` catalogue) and only adding new, narrowly-scoped pieces where genuinely nothing exists (transformation-initiatives text, gap remediation tracking, the financial-year setting, the mandate/MDF aggregate register, the report/pack generators).

---

## 2. The Real-World Flow

```
[PPRA sends an s25 inspection notice, or agency wants to self-audit]
                        │
                        ▼
         [Admin opens Admin → PPRA Inspection Pack]
                        │
                        ▼
     [13-row checklist a–m, live status, "why" line, "fix it" link per row]
                        │
        ┌───────────────┼──────────────────────────────┐
        ▼               ▼                              ▼
 [Upload missing   [Set a remediation date        [Click "Preview /
  agency docs,      + note on any open gap]         Download Inspection
  set FY, etc.]                                     Report" — instant PDF]
                                                            │
                                                            ▼
                                          [Or: "Download full inspection
                                           pack" — queued job bundles the
                                           SAME report + every source file]
                                                            │
                                                            ▼
                                          [Notification: "Your inspection
                                           pack is ready" → download link]
```

---

## 3. Scope

### 3.1 In scope (all phases, see §8 for the shippable breakdown)

- Live checklist page, 13 rows (a–m), agency-scoped, **admin-only**, under the Admin nav.
- Gap remediation tracking: any open (amber/red) row can carry a due date + note + optional assignee.
- **The Inspection Report PDF** (§6.2) — cover page, contents table, gaps-with-remediation-dates summary, per-item detail sections, practitioner FFC table. Generated synchronously, always current.
- Agency-level document vault reuse + two new document types (BEE affidavit, trial balance) + expiry/expiring-soon warnings on every vault row.
- Transformation-initiatives free-text field, versioned, full CRUD, archive/restore.
- Practitioner (agent) FFC register: PDF + CSV export, principal FFC certificates bundled into the pack.
- Sample letterhead PDF generator.
- Agency setting: financial year start month. FY-bounded DR2 sales list and rentals list, with a manual override date range, PDF/CSV export.
- Per-deal and per-rental "download as ZIP" (every typed document on that record).
- Mandate/MDF/FICA register across all active listings, gaps flagged, search/sort/filter/pagination, ZIP of all mandates + MDFs.
- One "Download full inspection pack" — queued job, the Inspection Report as its index, notification on completion.

### 3.2 Explicitly out of scope for this spec

- Real trust accounting / general ledger / automated trial balance generation. CoreX does not do trust accounting (confirmed in the 2026-09-28 investigation — zero references anywhere in the codebase). Item (e) is a **manual upload from the agency's accountant**, not a computed figure.
- CIPC / PPRA / SARS API integrations (auto-fetching CIPC documents, auto-verifying FFC numbers against the PPRA register, etc.) — every document in the vault is agency-uploaded, exactly like the existing FFC/bank-confirmation/BEE rows it extends.
- A generic "any inspection type" framework. This spec is PPRA s25-shaped; if a second regulator/inspection type is needed later, that is a new spec, not a parameter on this one.
- Per-document-row expiry tracking on `Document` (mandate/MDF/FICA documents attached to properties/deals). Today only the agency-vault documents (a/b/d/e/h) carry expiry; property/deal documents do not. Noted as a known limitation in §11, not built here.
- Branch-manager or agent access of any kind. Johan's ruling: pure admin feature. Nothing in this module is reachable below `admin`/`super_admin`.

---

## 4. Data Model

### 4.1 Extend `agency_document_type_configs` (existing table, reused wholesale)

```php
Schema::table('agency_document_type_configs', function (Blueprint $table) {
    // Nullable grouping key. Two rows sharing the same group are ALTERNATIVES —
    // ANY one of them having a valid (non-expired) provision satisfies the
    // group for checklist purposes. Used for "BEE certificate OR sworn
    // affidavit" (item h). NULL means "stands alone" (the existing behaviour
    // for every current row — no backfill needed).
    $table->string('satisfies_group')->nullable()->after('slug');
});
```

New seeded rows (via a migration backfill per agency that already has the vault initialised — mirrors how `bee_certificate`/`cipc_registration` etc. were originally seeded; **never** a hardcoded agency id, every existing agency with at least one `agency_document_type_configs` row gets both new types):

| slug | name | has_expiry | renewal_days (default) | required (default) | satisfies_group |
|---|---|---|---|---|---|
| `bee_affidavit` | BEE Sworn Affidavit | Y | 365 | N | `bee` |
| `trial_balance` | Trial Balance (latest) | Y | 90 | N | — |

`bee_certificate` (existing row) gets `satisfies_group = 'bee'` set alongside it in the same backfill. Both `required` defaults stay `N` at the type level — the *group* is what item (h)'s checklist row treats as required-or-not (agency-configurable, same picker UI extended to show grouped rows together).

`renewal_days` defaults above are starting points, not hardcoded thresholds — every value is the existing per-type, per-agency configurable field (§1a "every threshold is a setting" floor).

### 4.2 Extend `agency_compliance_provisions` (existing table — read the current migration before writing this one; confirm exact current column set)

No new columns anticipated — `effective_until` (existing) already drives the red/amber/green expiry logic in `AgencyDocumentsViewerController::statusFor()`. Phase A's only vault change is the **status labels**: today `statusFor()` returns `Available` (teal) once a file exists, regardless of expiry, EXCEPT when `effective_until` has passed, which needs verifying against the live logic before build — HFC's own Bank Confirmation Letter is expired today (`2026-09-25`) and this spec requires that to render **red** with an "Expired — renew now" why-line on both the vault screen and the new checklist row. If the current code does not already do this correctly end-to-end (confirm in Phase A, `AgencyDocumentsViewerController::statusFor()` — the amber/red days-left branch was only partially read during investigation), fix it as part of Phase A, not a separate ticket.

### 4.3 New table: `agency_transformation_notes`

```php
Schema::create('agency_transformation_notes', function (Blueprint $table) {
    $table->id();
    $table->foreignId('agency_id')->constrained();
    $table->text('content');
    $table->foreignId('created_by_user_id')->constrained('users');
    $table->softDeletes();
    $table->timestamps();

    $table->index(['agency_id', 'created_at']);
});
```

Versioned = append-only: every save creates a **new row**, soft-deletes the prior current row (never edits a row's `content` in place). "Current" = latest non-deleted row for the agency. Full history browsable via `withTrashed()`, restorable (un-deleting an old version makes it current again only via an explicit "restore this version" action, which itself creates a new current row copying that content — restore never un-deletes a stale row into ambiguous multi-current state).

### 4.4 New table: `ppra_inspection_gap_notes`

Tracks a remediation date + note against any open checklist row — this is what makes the Inspection Report's gaps section a real action list rather than a bare "missing" label.

```php
Schema::create('ppra_inspection_gap_notes', function (Blueprint $table) {
    $table->id();
    $table->foreignId('agency_id')->constrained();
    $table->string('checklist_item_slug');   // 'a'..'m' — matches §5's item keys
    $table->date('remediation_due_date')->nullable();
    $table->text('note')->nullable();
    $table->foreignId('assigned_to_user_id')->nullable()->constrained('users');
    $table->timestamp('resolved_at')->nullable();
    $table->foreignId('created_by_user_id')->constrained('users');
    $table->softDeletes();
    $table->timestamps();

    $table->index(['agency_id', 'checklist_item_slug']);
});
```

"Current" note for an item = latest non-deleted, non-resolved row for that agency + slug. `resolved_at` is set two ways: automatically, the next time the checklist re-computes that item as green (the checklist service resolves any open note for a now-clean item), or manually via a "mark resolved now" action. Full CRUD: create/edit a note (upsert — editing creates a new row exactly like `agency_transformation_notes`' versioning, so the remediation history for an item is itself auditable), archive (soft-delete, e.g. added in error), and a small embedded "Remediation Log" list on the checklist page (search — item, note text; sort — due date / created date, default due date ascending; filter — open vs. resolved, overdue; pagination) satisfying the §1a CRUD-list floor without a standalone nav entry.

### 4.5 New table: `ppra_inspection_packs`

```php
Schema::create('ppra_inspection_packs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('agency_id')->constrained();
    $table->foreignId('requested_by_user_id')->constrained('users');

    $table->enum('status', ['queued', 'generating', 'ready', 'failed'])->default('queued');
    $table->json('sample_deal_ids')->nullable();     // user-picked deal ids for item k
    $table->json('sample_rental_ids')->nullable();   // user-picked rental/application ids for item l
    $table->unsignedInteger('deal_sample_count')->nullable();   // convenience "N most recent" used, if that path was taken
    $table->unsignedInteger('rental_sample_count')->nullable();

    $table->string('zip_path')->nullable();
    $table->string('report_pdf_path')->nullable();   // the Inspection Report, frozen — same renderer as the standalone preview (§6.2)
    $table->unsignedBigInteger('zip_size_bytes')->nullable();
    $table->json('gaps_summary')->nullable();   // frozen a–m checklist + remediation notes at generation time, for audit and for re-rendering the report without re-querying live state
    $table->text('error_message')->nullable();

    $table->timestamp('generated_at')->nullable();
    $table->softDeletes();
    $table->timestamps();

    $table->index(['agency_id', 'created_at']);
});
```

Every `ppra_inspection_packs` row's `agency_id` is set **explicitly** at creation from the requesting user's `effectiveAgencyId()`, resolved once in the controller before dispatch — never left to the `BelongsToAgency` auto-stamp inside a queued job, which runs without an authenticated web session (`Auth::user()` is null in a job) and would hit exactly the class of bug fixed in the whistleblow module on 2026-09-28 (see this file's header).

### 4.6 New column on `agencies`

```php
Schema::table('agencies', function (Blueprint $table) {
    $table->unsignedTinyInteger('financial_year_start_month')->default(3)->after('email');
    // 1–12. Default 3 = March, the common SA small-business FY start —
    // a sensible neutral default, never assumed correct for a given agency.
});
```

### 4.7 New `notification_event_types` row (migration, mirrors the whistleblow pattern exactly)

```php
'key'             => 'ppra_pack.generation_complete',
'pillar'          => 'agency',
'group_label'     => 'Admin',
'label'           => 'PPRA inspection pack ready',
'description'     => 'Your requested PPRA inspection pack has finished generating and is ready to download.',
'supports_in_app' => 1,
'supports_email'  => 1,
'supports_push'   => 0,
```

---

## 5. Checklist Item Definitions (a–m)

Every row is computed **live** on each page load by `PpraInspectionPackChecklistService` (new), scoped to the viewing admin's agency. No status is persisted except the frozen `gaps_summary` snapshot stamped onto a generated pack (§4.5) — the live checklist page and the on-demand Inspection Report always re-compute fresh.

Status vocabulary: `green` (compliant), `amber` (compliant but expiring / partially complete), `red` (non-compliant / missing / expired), `info` (not a pass/fail gate — a tool/link row).

| Item | Backing data | Status logic | Why-line template |
|---|---|---|---|
| **a) CIPC documents** | `agency_document_type_configs` slug `cipc_registration` → `AgencyComplianceProvision::resolveForUser()` | red: no provision. green: provision exists (no expiry tracked on this type). | "No CIPC registration on file" / "CIPC registration on file, uploaded {date}" |
| **b) Company FFC** | slug `ffc_certificate` (agency-level) | red: missing. amber: `effective_until` within `renewal_days`. red: past `effective_until`. green: valid. | "Company FFC expires {date}" / "Company FFC expired {date} — renew now" / "Company FFC on file, valid until {date}" |
| **c) Principal's FFCs** | `User.ffc_number` / `ffc_certificate_path` / `ffc_expiry_date` for every active, non-assistant agent (same roster as `/compliance/agents`) | green: 100% have a file + number, none expired. amber: ≥1 expiring within 60 days (existing threshold in `AgentComplianceController`, kept as-is — configurable, see §11). red: ≥1 missing or expired. | "{n}/{total} practitioners have a valid FFC on file" (names of the gap list, capped, "+N more") |
| **d) Trust account bank confirmation letter** | slug `bank_confirmation` | same as (b). **HFC's actual current row must render red** ("expired 25 Sep 2026") — this is the concrete case this spec's expiry-logic fix (§4.2) is verified against. | as (b), s54(1)-specific wording |
| **e) Trial balance** | slug `trial_balance` (new) | same as (b), `renewal_days` default 90. | "Latest trial balance uploaded {date} (agency accounting records are not kept in CoreX — this is a manual upload from your accountant)" |
| **f) Practitioner list w/ FFC no.** | same roster as (c) | mirrors (c)'s status (this row IS (c) plus "export available" — see §6.5) | Same as (c), plus "Export to PDF/CSV from the practitioner register" |
| **g) Letterhead** | `Agency.logo_path`, PPRA number cascade, `email_disclaimer`, `popi_url` (the exact fields `BaseSignatureMail::getAgentFooter()` already reads) | red: `logo_path` null. amber: logo set but no PPRA number resolvable. green: all present, sample generated at least once. | "Agency logo not set — letterhead cannot be generated" / "Sample letterhead generated {date}" |
| **h) BEE certificate or affidavit** | slugs `bee_certificate` **or** `bee_affidavit` (satisfies_group `bee`) | green: either has a valid, non-expired provision. amber/red mirror (b) on whichever is present; red if neither present. | "No BEE certificate or sworn affidavit on file" / "{Certificate\|Affidavit} on file, valid until {date}" |
| **i) Transformation initiatives** | `agency_transformation_notes` current version | red: no non-deleted row exists yet. green: a current version exists (content non-empty). | "No transformation initiatives statement on file" / "Statement last updated {date} by {user}" |
| **j) Sales/rentals list, current FY** | `info` row — links to the FY-bounded DR2/rentals lists (§6.6) | always `info`/green once `financial_year_start_month` is set (it always is — default 3) | "View {N} sales and {M} rentals in the current financial year ({start}–{end})" |
| **k) Sales files sampled** | `info` row — links to the per-deal ZIP tool (§6.7) inside the pack | `info` | "Bundle any deal's mandate, OTP, MDF, FICA and commission documents into one ZIP" |
| **l) Rental files sampled** | `info` row — links to the per-rental ZIP tool (§6.7) | `info` | "Bundle any rental application's lease and supporting documents into one ZIP" |
| **m) Mandates + MDFs, active listings** | `MarketingReadinessService`-driven mandate/MDF/FICA register (§6.8) | green: 0 active listings with a missing mandate/MDF/FICA document. amber: 1+ gaps, <10% of active listings. red: ≥10% of active listings have a gap, threshold configurable (§11). | "{n}/{total} active listings have every required document" |

Any row currently `amber` or `red` can carry a `ppra_inspection_gap_notes` entry (§4.4) — the checklist page surfaces a small "Set remediation date" action inline on that row when open.

---

## 6. UI Surfaces

### 6.1 Nav entry — under Admin, admin-only

New nav item **"PPRA Inspection Pack"**, a top-level item under the existing `corex-nav-section-label` **"Admin"** region (`resources/views/layouts/corex-sidebar.blade.php`, ~line 2184 onward — sibling to "Knowledge Base" at ~line 2274, not nested inside the "Company" slide-panel group, since this is a substantial standalone tool, not a settings row). Gated on `ppra_inspection_pack.view` (admin/super_admin only — see §7). Lands on `/admin/ppra-inspection-pack`.

### 6.2 The Inspection Report — the primary deliverable

This is the artifact the module exists to produce. It is generated **synchronously** (no queue — it is pure querying + rendering, no large-file bundling) from `PpraInspectionReportPdfService` (new), via the existing Puppeteer HTML-to-PDF pipeline (`scripts/html-to-pdf.mjs`, the same mechanism `WhistleblowComplaintService::generatePdf()` already uses — not a new rendering stack). Available from a **"Preview / Download Inspection Report"** button on the checklist page, always reflecting live data, with no persistence required for the standalone preview. The exact same renderer produces the frozen `report_pdf_path` inside a full pack (§6.9) — same document, two ways to get it.

**Structure, in page order:**

1. **Cover page**
   - Agency logo, trading name, and registered name.
   - Title: "PPRA s25 Inspection Report".
   - Report reference (`PPRA-{agency_id}-{generated timestamp, e.g. 20260928-1032}` — human-readable, not a raw DB id).
   - Generated date/time and generated-by (the admin's name).
   - Agency contact block: address, phone, email, agency FFC number, PPRA registration/branch number cascade (same fields §5 item (g) checks).
   - Principal practitioner name(s) — pulled from the same roster as item (c)/(f), filtered to principal-designated agents if that designation exists on `User`, else every active agent (confirm at build time which `User` field, if any, distinguishes "principal" from "agent" — flagged in §12 if none exists).

2. **Table of Contents** — one row per item a–m: letter, short description, live status badge (colour + label), the page number its detail section starts on.

3. **Executive Summary of Gaps** — every item currently amber or red, in one table: **Item | Description | Status | Remediation Due Date | Assigned To | Note**. Pulled from `ppra_inspection_gap_notes`'s current entries; a gap with no note yet still appears with "No remediation date set" rather than being omitted (an unacknowledged gap is not allowed to look, at a glance, like there are no gaps). If every item is green, this section prints "No outstanding items" instead of an empty table.

4. **Per-item detail sections, a through m, in order** — each section:
   - Heading: "a) CIPC Documents" etc.
   - One line stating the PPRA s25 requirement being evidenced (plain-language, from §5's why-line logic, not a legal citation).
   - Status badge.
   - **Evidence included**: for a document-backed item (a, b, d, e, h), the file name and upload date; for the practitioner items (c/f), a reference to the FFC table below; for the letterhead item (g), a note that a sample is attached; for the FY/sampling/register items (j, k, l, m), a summary line ("{N} sales, {M} rentals in FY2026/27" / "{N} deal files sampled" / "{N} active listings, {G} with a gap").
   - If the item is amber/red: the gap description, its remediation due date and assignee (repeated here from the Executive Summary for a reader working section-by-section rather than jumping to page 3), and any note.

5. **Practitioner FFC Table** — embedded inside item (c)/(f)'s section (not a separate appendix — that is where a reader expects it): **Name | Designation | FFC Number | Status | Expiry Date**, every active non-assistant agent, sorted by status (red/amber first, matching the existing `/compliance/agents` sort), then name.

6. **Footer, every page**: agency name, report reference, page X of Y, generation timestamp.

The report is what makes item (m)'s and the Executive Summary's "gaps with remediation dates" real: **before Phase A ships, there must be a way to attach a due date to a gap** (§4.4) — this is why gap-note tracking is pulled forward into Phase A alongside the report itself, not deferred.

### 6.3 Checklist page — `/admin/ppra-inspection-pack`

13 rows (a–m), traffic-light badge, why-line, inline "Set remediation date" action on open rows, and a "Fix it" / "View" action button per row linking to the relevant existing or new screen (§6.4–6.9). "Preview / Download Inspection Report" (§6.2) and "Download full inspection pack" (§6.9) calls-to-action at the top. Permission `ppra_inspection_pack.view`.

### 6.4 Agency documents vault — reuse existing screen

`/compliance/my-portal/agency-documents` (existing `AgencyDocumentsViewerController`) gains the two new document types automatically (they're rows in the existing catalogue — no new screen needed), plus the expiry-badge fix from §4.2 if required. Upload UI: existing `AgencyComplianceSettingsController` — confirm at build time whether its picker needs a small "these two rows satisfy the same requirement" grouping hint in the UI (cosmetic; not a schema requirement beyond `satisfies_group`). This screen's own permission/nav location is unchanged by this spec — it already exists under Compliance's my-portal area and stays there; only the NEW `/admin/ppra-inspection-pack` checklist links into it.

### 6.5 Transformation initiatives — `/admin/ppra-inspection-pack/transformation`

Full CRUD per §1a floor:
- **List** (version history): search — free-text over `content`; sort — `created_at` (default: newest first); filter — date range (created between); pagination — 25/page; empty state: "No transformation initiatives statement has been written yet."
- **Create/Edit** (edit = create-new-version, per §4.3): rich-enough textarea, save creates new current row.
- **Archive**: soft-delete a version (only ever the CURRENT one triggers "what happens to 'current' now" — falls back to the next non-deleted row by `created_at`; if none remain, item (i) reads red again).
- **Restore**: un-archive from the version-history list; restoring a non-current historical version copies it forward as a new current row (§4.3).
- Scoping: **agency-only** by design — there is one transformation statement per agency, not per branch or per user. Recorded explicitly here as the deliberate exception to the own/branch/agency floor (§1c), the same way a setting can be deliberately excluded from the onboarding wizard with a reason on record (§10a) — this is that same kind of explicit, justified narrowing, not an oversight.
- Permission: `ppra_inspection_pack.configure` to write; `ppra_inspection_pack.view` to read.

### 6.6 Practitioner FFC register — extend `/compliance/agents`

Add **Export to PDF** and **Export to CSV** buttons to the existing `compliance.agent-dashboard` view, gated on `ppra_inspection_pack.export`. Both exports carry the same roster the screen already shows (name, designation, FFC status/number/expiry, training status). `/compliance/agents` itself is already owner/super-admin gated today — unchanged, and now explicitly consistent with this whole module's admin-only ruling (no widening to branch_manager; resolves what was an open question in v1 of this spec).

**Sample letterhead PDF** (item g): a "Generate sample letterhead" button on this same page (or the checklist row's own action), producing a one-page PDF rendered from the identical data `BaseSignatureMail::getAgentFooter()` uses — reuses the existing Puppeteer HTML-to-PDF pipeline.

### 6.7 Financial year setting + FY-bounded lists

- **Setting**: new "PPRA Inspection Pack" section on `/corex/settings` (mirrors the existing "Compliance Reporting" / whistleblow settings section pattern at `resources/views/corex/settings.blade.php`, but this section is only visible/enterable with `ppra_inspection_pack.configure`), one field: "Financial year starts in" (month picker, default March).
- **Setup Wizard**: per CLAUDE.md non-negotiable §10a, `financial_year_start_month` gets a control on the relevant onboarding step in `config/agency-onboarding-copy.php` (`explain` + `affects` + saver wired, reading `.ai/specs/agency-onboarding-setup.md` §6.1 first) — **build-sequence item, not optional.**
- **DR2 sales list** (`/deals-dr2`): default-bounded to the current financial year (computed from `financial_year_start_month`); a manual date-range override (two date inputs, "Custom range" toggle) replaces the FY default for that session's view. Add **Export PDF / CSV** buttons reflecting the currently-applied filter (FY default or custom range) plus whatever search/status/branch filters are already active. These buttons appear for whoever can already see `/deals-dr2` (existing `deals.view` permission, unrelated to this module's admin-only rule) — exporting a list you can already view is not a new privilege.
- **Rentals list** (`/rentals`): same FY-bounding + override + PDF/CSV export treatment.
- Existing search/sort/filter/scoping on both lists (`Deal::visibleTo($user)`, `PermissionService::getDataScope`) are unchanged — the FY bound is an additional default filter, not a replacement for own/branch/agency scoping. (The FY setting itself is admin-configured; *using* the FY-bounded list is not restricted to admins, since it is the existing deal/rental list with an extra default filter, not a new admin surface.)

### 6.8 Mandate/MDF/FICA register — `/admin/ppra-inspection-pack/mandate-register`

New screen, new lightweight query layer (**no new table** — reads `Property` + runs `MarketingReadinessService`'s existing document-gate check per row; add a narrow `MarketingReadinessService::documentGateSummaryFor(Property $property): array` limited to the `mandate`/`fica`/`disclosure` slugs, so the register doesn't pay for the photo/details-complete checks `statusFor()` also runs — a register-specific method, not a change to `statusFor()`'s existing contract).

- **Search**: property address, suburb, listing agent name.
- **Sort**: address (default, ascending), mandate status, MDF status, FICA status, listing date.
- **Filter**: status (`all` / `missing mandate` / `missing MDF` / `missing FICA` / `all clear`), branch, agent, date range (listed between).
- **Pagination**: standard page size (25, matching the rest of CoreX).
- **Empty state**: "No active listings match this filter" vs "This agency has no active listings yet" (distinct messages per §1b).
- **Scoping**: this screen is reachable only by `ppra_inspection_pack.view` holders (admin/super_admin), so the effective visibility is always **AGENCY**-wide for that admin's own agency — the query-layer agency boundary is still enforced and can never be crossed (`BelongsToAgency`/`AgencyScope`), but the OWN/BRANCH/AGENCY three-tier *visibility* distinction collapses to "agency-wide, with branch/agent as optional filters" here, because no user below admin tier can open this screen at all. Recorded explicitly as the deliberate scoping decision this floor requires (§1c) — not an oversight, and not in tension with it: the boundary that must never be crossed (another agency's data) is still enforced; the finer own/branch tiering simply has no one left to apply it to once the screen is admin-only.
- **Columns**: address, agent, mandate (✓/✗), MDF (✓/✗), FICA (✓/✗), listed date.
- **Action**: "Download ZIP of all mandates + MDFs" — bundles the actual `mandate`/`disclosure`-typed `Document` files for every property in the **current filtered view**, capped at a configurable max-files-per-ZIP (default 200, §11) to bound the job; properties with a gap simply contribute fewer files, and the gap is still visible in the register itself (the ZIP is a convenience bundle, not the source of truth for what's missing).

### 6.9 Per-deal / per-rental ZIP + the full pack generator

- **Per-deal ZIP**: a "Download as ZIP" button on the DR2 deal detail page (`resources/views/deals-v2/show.blade.php`, next to the existing per-document download links), bundling every `Document` currently attached to `$deal->documents` (mandate, OTP, MDF/disclosure, FICA, commission-related, whatever's actually filed). Same visibility scope as viewing the deal itself (existing `deals.view`-tier scoping, not this module's admin gate — anyone who can already open the deal can zip its own documents); re-checked on the download route, not inferred from page access alone.
- **Per-rental ZIP**: same shape, on the rental application detail page, bundling everything under the `rental_application_document` pivot.
- **Sampling picker** (feeds item k/l and the full pack, §4.5): on the checklist page's "Download full inspection pack" flow (admin-only), a picker listing recent deals/rentals (search + checkboxes) plus a "select the N most recent" quick-action (N = a plain number input, sane default e.g. 5, capped e.g. 20 — configurable per §11) that pre-ticks the N most recently closed/most recent deals or leases. The user's final selection — however reached — is what's stored on `ppra_inspection_packs.sample_deal_ids` / `sample_rental_ids`.
- **Full pack generation**: `GeneratePpraInspectionPackJob` (queued), dispatched from a `POST /admin/ppra-inspection-pack/generate` action (permission `ppra_inspection_pack.generate`) that first creates the `ppra_inspection_packs` row (`status = queued`, `agency_id` set explicitly from the requester, §4.5) and returns immediately — the checklist page polls/shows "Generating…" for that pack. The job:
  1. Renders the Inspection Report (§6.2's exact same service) and freezes it as `report_pdf_path`.
  2. Pulls every current agency-vault document (a, b, d, e, h — whichever are present).
  3. Pulls every practitioner's current FFC certificate (c/f).
  4. Generates the sample letterhead PDF fresh (g).
  5. Bundles the sampled deals'/rentals' documents (k/l, from `sample_deal_ids`/`sample_rental_ids`).
  6. Bundles every mandate + MDF document for active listings, same cap as §6.8's standalone button (m).
  7. Zips the Report + all of the above, writes `zip_path`/`report_pdf_path`/`zip_size_bytes`, sets `status = ready`, `generated_at = now()`.
  8. On any failure: `status = failed`, `error_message` set, **never** left silently stuck at `generating`.
  9. Fires the `ppra_pack.generation_complete` notification (§4.7) via the AT-235 gateway (`NotificationDispatcher::send()`, mirroring `WhistleblowComplaintService::notifyComplianceOfficerOfSubmission()`'s call shape) to the requesting admin — database + email.
- **Download route**: `GET /admin/ppra-inspection-pack/{pack}/download`, agency-scope re-checked (mirrors `AgencyDocumentsViewerController::download()`'s multi-tenant check) AND `ppra_inspection_pack.generate` re-checked, serves `zip_path` from the `local` disk. No public/unauthenticated share link — this is an internal admin action, not a seller-facing send.

**QA note for the build phase (not this spec):** per `.ai/BUILD_STANDARD.md` "Promotion flow", QA1 runs no queue worker — `GeneratePpraInspectionPackJob`'s first real QA happens on Staging, not QA1. A QA1-only smoke test (e.g. `->onConnection('sync')` for a one-off manual check, or asserting the job class dispatches correctly) is not a substitute for a real queued run before this ships past Staging. The standalone Inspection Report (§6.2), being synchronous, has no such restriction and is fully QA1-testable from Phase A.

---

## 7. Permissions (RMCP)

New RMCP section `ppra_inspection_pack` — **every slug is admin/super_admin only**, per Johan's ruling. No branch_manager, no agent, anywhere in this module.

| Slug | Description | Default roles |
|---|---|---|
| `ppra_inspection_pack.view` | See the checklist, Inspection Report, mandate register, gap remediation log | admin, super_admin |
| `ppra_inspection_pack.export` | PDF/CSV exports (practitioner register, FY-bounded lists), mandate-register ZIP | admin, super_admin |
| `ppra_inspection_pack.generate` | Trigger full-pack generation, download the finished pack | admin, super_admin |
| `ppra_inspection_pack.configure` | Financial year setting, transformation-initiatives writes, document-type config, gap remediation notes | admin, super_admin |

The existing `deals.view` / rentals-view permissions that already gate `/deals-dr2` and `/rentals` are **untouched** — the FY-bounded default filter and its PDF/CSV export ride on those existing permissions (§6.7), not this module's admin gate, since viewing/exporting a list you can already see is not a new privilege. Same for the per-deal/per-rental ZIP buttons (§6.9) — gated on existing deal/rental visibility, not `ppra_inspection_pack.*`.

---

## 8. Build Sequence (each phase independently shippable)

**Phase A — Checklist framework, gap remediation tracking, the Inspection Report, items a/b/d/e/h**
- Migrations: `agency_document_type_configs.satisfies_group`, seed `bee_affidavit` + `trial_balance` rows per existing agency, `bee_certificate.satisfies_group = 'bee'` backfill, `ppra_inspection_gap_notes`.
- Fix/verify the vault's expired-vs-expiring status logic (§4.2) — HFC's bank-confirmation-letter-expired case is the concrete test.
- `PpraInspectionPackChecklistService` (a, b, d, e, h only for this phase; c/f/g/i/j/k/l/m rows render "not yet available").
- Gap remediation date/note UI on open rows + the embedded Remediation Log list (§4.4).
- `PpraInspectionReportPdfService` — cover page, TOC, Executive Summary of Gaps, per-item sections for whichever items are wired so far (this phase: a/b/d/e/h; later phases each extend the same template with their own section, never a rewrite).
- RMCP permissions, Admin sidebar nav, `/admin/ppra-inspection-pack` checklist page.
- Tinker/curl verification: HFC's checklist shows (d) red with the correct expiry date; (a)/(b)/(e)/(h) red (nothing uploaded); uploading a `trial_balance` document flips (e) to green; the Inspection Report PDF renders with a real cover page and the Executive Summary correctly lists the (d) gap.

**Phase B — Item c/f: practitioner FFC checklist row + PDF/CSV export + item g: sample letterhead**
- Checklist row c/f wired to existing `/compliance/agents` data; the Report's per-item section + embedded FFC table (§6.2 item 5) goes live.
- PDF/CSV export buttons on `/compliance/agents`.
- Sample letterhead PDF generator + checklist row g + Report section.

**Phase C — Item i: transformation initiatives**
- Migration `agency_transformation_notes`.
- Full CRUD screen (§6.5), checklist row i wired, Report section added.

**Phase D — Item j: financial year + FY-bounded lists**
- Migration `agencies.financial_year_start_month`.
- Settings UI + **Setup Wizard entry** (non-negotiable, same phase, not deferred).
- FY-bounding + manual override + PDF/CSV export on DR2 deals list and rentals list.
- Checklist row j + Report section wired.

**Phase E — Items k/l: per-deal / per-rental ZIP**
- "Download as ZIP" on deal detail and rental application detail pages.
- Checklist rows k/l wired (info links) + Report section.

**Phase F — Item m: mandate/MDF/FICA register**
- `MarketingReadinessService::documentGateSummaryFor()`.
- `/admin/ppra-inspection-pack/mandate-register` full CRUD-list floor (search/sort/filter/pagination/scoping per §6.8).
- Register-wide "Download ZIP of all mandates + MDFs".
- Checklist row m wired with real red/amber/green threshold + Report section.

**Phase G — Full inspection pack**
- Migration `ppra_inspection_packs`, `notification_event_types` row.
- Sampling picker (reuses E's per-deal/per-rental bundling).
- `GeneratePpraInspectionPackJob` (bundles source files around the already-existing Report renderer from Phase A), download route, notification.
- **First real queued-job QA on Staging**, not QA1 (BUILD_STANDARD constraint, §6.9 note).

---

## 9. Edge Cases

| Scenario | Handling |
|---|---|
| Agency has zero `agency_document_type_configs` rows at all (never initialised the vault) | Checklist rows a/b/d/e/h all show red with "Compliance document types not configured yet — set them up in Settings" rather than a blank/broken row. |
| Trial balance uploaded but agency later disables trust-related requirements | `required` stays a per-agency, per-type toggle exactly like every other vault row — no special case. |
| Transformation-initiatives current version soft-deleted, no prior version exists | Item i reads red, same as never-written. |
| Financial year start month changed mid-cycle | FY-bounded lists recompute from the NEW boundary immediately — no historical "as it was on date X" snapshot; the manual override exists precisely for when this matters for a specific inspection. |
| A remediation date is set, then the underlying gap is fixed (upload lands) before the due date | Checklist service resolves the open `ppra_inspection_gap_notes` row automatically on next computation (`resolved_at` set); the Report's Executive Summary stops listing it. |
| A remediation date passes with the gap still open (overdue) | Not auto-escalated by this spec (no email/notification on overdue — that is a plausible fast-follow, not required here); the Remediation Log filter (`overdue`) and the Report both still show it, now visibly in the past, which is itself the signal. |
| Full-pack generation requested while an agency has 0 active listings | Pack still generates (a/b/c/d/e/f/g/h/i sections populate normally); mandate/MDF section and Report both say "No active listings" rather than silently omitting the section. |
| Sample picker: user picks 0 deals and 0 rentals | Allowed — the pack still generates with everything else; the Report notes "No sales/rental files sampled for this pack." |
| Two admins trigger full-pack generation simultaneously | Each gets their own `ppra_inspection_packs` row — no dedup/lock. (If this proves wasteful in practice, a "reuse an in-flight pack for the same agency" optimisation is a fast-follow, not blocking Phase G.) |
| Deal/rental has zero documents attached | "Download as ZIP" is disabled or produces an empty-but-valid zip with a README noting nothing was filed — never a 500. |
| Mandate-register ZIP exceeds the max-files-per-ZIP cap | Bundles the first N (by the register's current sort order) and the index/response clearly states how many were included vs. how many exist, never silently truncates without saying so (BUILD_STANDARD "no silent caps"). |
| Non-admin user directly hits any `/admin/ppra-inspection-pack/*` URL | 403, not a redirect that leaks whether the resource exists — same pattern as every other admin-gated route. |

---

## 10. Acceptance Criteria

Phase G (full module) is complete when:

1. An admin can open `/admin/ppra-inspection-pack` and see all 13 rows with live, correct status against real agency data — verified against HFC's actual QA1 data (bank confirmation letter red/expired, at minimum).
2. Every red/amber row's action link lands the admin on the exact screen needed to fix it, and can carry a remediation date + note.
3. The Inspection Report PDF has a real cover page, an accurate table of contents, an Executive Summary of Gaps with remediation dates, a detail section per item a–m, and a properly-formatted practitioner FFC table — verified visually, not just "the PDF generated without error."
4. The transformation-initiatives statement has full CRUD with version history and archive/restore.
5. The practitioner FFC register exports to both PDF and CSV with the same data the screen shows.
6. A sample letterhead PDF generates from real agency branding data.
7. The financial year setting bounds both the DR2 and rentals lists correctly, with a working manual override, and is present in the Setup Wizard.
8. Per-deal and per-rental ZIP downloads produce a correct archive of every attached document.
9. The mandate/MDF/FICA register lists every active listing with correct per-document status, full search/sort/filter/pagination.
10. "Download full inspection pack" queues, completes, notifies, and produces a ZIP whose frozen Inspection Report accurately lists both contents and remaining gaps.
11. Every route under `/admin/ppra-inspection-pack/*` returns 403 for a non-admin user, verified by direct URL, not just absence from the nav.
12. Every new setting from this spec (financial year start month, document-type requirements, ZIP sample-size cap, mandate-register red/amber threshold) is a configurable value, none hardcoded, and financial-year-start-month is live in the Setup Wizard.
13. No agency-1/HFC-specific assumption anywhere — verified against a second agency with different vault configuration and a different financial year start month.

---

## 11. Settings Introduced (every threshold is a setting — §1a floor)

| Setting | Default | Where configured |
|---|---|---|
| `agencies.financial_year_start_month` | March (3) | `/corex/settings` → PPRA Inspection Pack section (admin-only); Setup Wizard |
| Per-type `renewal_days` (incl. new `bee_affidavit`, `trial_balance`) | 365 / 90 respectively | Existing agency-documents vault settings UI |
| Per-type `required` (incl. `bee` group as a whole) | as existing / N for new types | Existing agency-documents vault settings UI |
| FFC-expiring-soon window (item c/f) | 60 days (existing `AgentComplianceController` constant — confirm it is already a setting, not a hardcoded literal, before reusing; make it one if not) | `/corex/settings` → PPRA Inspection Pack section |
| Mandate-register red threshold | 10% of active listings with a gap | `/corex/settings` → PPRA Inspection Pack section |
| Sample-picker default N / max N | 5 / 20 | `/corex/settings` → PPRA Inspection Pack section |
| Mandate-register-ZIP / per-list-ZIP max files per bundle | 200 | `/corex/settings` → PPRA Inspection Pack section |

---

## 12. Open Questions for Johan (business calls, not engineering ones)

- Is a 10%-of-active-listings red threshold for the mandate/MDF register the right business line, or should ANY gap be red? (Currently proposed as amber-until-10%, per §5 item m — easy to change before Phase F, since it's a setting either way.)
- Sworn-affidavit wording/template for item h — is a CoreX-generated affidavit template wanted (Phase 2, out of scope here), or is upload-only sufficient indefinitely?
- The cover page lists "principal practitioner name(s)" (§6.2 item 1) — does `User` already carry a "principal" designation to filter on, or should every active agent be listed there? Needs a quick confirm-at-build-time check; flagged here so it isn't guessed.
- Should an overdue remediation date (§9) trigger anything (a notification, an escalation), or is it deliberately silent — visible only when someone looks? Currently spec'd as silent by design; easy to add later if wanted.
