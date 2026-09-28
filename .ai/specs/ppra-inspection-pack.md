# PPRA Inspection Pack — Specification

**Status:** Draft v3 — Phases A–F shipped; G onward re-planned around Johan's item-by-item rulings below
**Author:** Claude (senior engineering), from the read-only PPRA s25 investigation of 2026-09-28
**Date:** 28 September 2026
**Spec location:** `.ai/specs/ppra-inspection-pack.md`
**v2 amendment (28 September 2026), Johan's ruling:** this is a **pure Admin feature** — moved from Compliance to the Admin area, gated admin-only (no branch_manager, no agent). The goal is explicitly stated: *"the best inspection report an inspector has ever seen"* — the generated PDF is a first-class deliverable in its own right, not a manifest tacked onto a ZIP. §6.2 (the Inspection Report) is the spine of this document; everything else exists to feed it.

**v3 amendment (28 September 2026), Johan's item-by-item rulings — these override the rest of this spec wherever they differ, item by item:**
- **a/b/d/h** — confirmed as built: attach the matching agency-vault document. No change.
- **c) Principal's FFC** — find the principal(s) by `users.designation` and attach **their own uploaded FFC certificate from `UserDocument`** (type `ffc_certificate`) — not the agency vault, not a second upload. See §6.6a.
- **e) Trial balance** — confirmed as built: uploaded directly into the vault slot from the Inspection Pack itself. Included in the full pack (Phase J).
- **f) Practitioner list + FFC** — restricted to active users with role `agent`, `branch_manager`, or `admin` **only** — explicitly excluding `assistant`, `office_admin`, `super_admin`, and `viewer` (CoreX's actual distinct `role` values, confirmed against the live database — there is no separate "IT" role). See §6.6.
- **g) Letterhead** — confirmed as built in Phase B, corrected to be a genuinely **plain** letterhead (no "SAMPLE" watermark, no explanatory body copy) — see §6.6b.
- **i) Transformation initiatives** — redesigned: the principal writes it **in CoreX** via a structured form (initiatives, dates, people, spend) with an Ellie "help me draft" assist, **or** uploads their own document instead — either satisfies the item. Still versioned, still archivable. See §6.5 (rewritten).
- **j) Current FY sales/rentals list** — redefined: every property that was **active and advertised** (syndicated to a portal, or live on the agency website) **at any point in the current financial year**, split into sales and rentals. See §6.7 (rewritten) for exactly which columns prove "advertised" and the known limitation in reconstructing multiple on/off cycles within one FY.
- **k/l/m) Sample document packs** — redesigned from a lightweight ZIP button into a proper sampling workflow: the admin picks *N* records (an agency setting, default 5) in a shared modal picker (search/filter), and the pack pulls **everything CoreX holds** for each — not just the headline document. One folder per record in the ZIP, one index page per record in the report. See §6.8a (shared picker) and §6.8b/c/d (k/l/m respectively).
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

### 4.3 New table: `agency_transformation_notes` (v3 — structured form OR uploaded document)

```php
Schema::create('agency_transformation_notes', function (Blueprint $table) {
    $table->id();
    $table->foreignId('agency_id')->constrained();
    $table->enum('entry_type', ['structured', 'document']);
    // 'structured': written in CoreX. Array of
    //   {description, start_date, end_date, people_involved, spend_amount}
    // — the exact shape §6.5's form/Ellie-draft both read and write.
    $table->json('structured_data')->nullable();
    // 'document': the principal uploaded their own statement instead.
    $table->string('document_path')->nullable();
    $table->string('document_original_name')->nullable();
    // Either path also gets a short human-readable summary — for
    // 'structured' this is generated from structured_data at save time
    // (not re-derived at read time, so an edit to the rendering logic
    // later doesn't retroactively rewrite historical versions); for
    // 'document' it's a one-line caption the principal types alongside
    // the upload. Always what the Inspection Report prints.
    $table->text('summary')->nullable();
    $table->foreignId('created_by_user_id')->constrained('users');
    $table->softDeletes();
    $table->timestamps();

    $table->index(['agency_id', 'created_at']);
});
```

Versioned = append-only: every save creates a **new row**, soft-deletes the prior current row (never edits a row in place — this applies to both `entry_type`s, and a new version may freely switch type, e.g. a structured entry superseded by an uploaded document). "Current" = latest non-deleted row for the agency. Full history browsable via `withTrashed()`, restorable (un-deleting an old version makes it current again only via an explicit "restore this version" action, which itself creates a new current row copying that version's data — restore never un-deletes a stale row into ambiguous multi-current state).

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

**Created in Phase F, not Phase J as originally sequenced.** Phase F's own
deliverable (the shared sample picker, §6.8a) persists selected sample ids
onto this table, so the table must exist before Phase F ships. Phase J's
scope is unchanged apart from this: it adds the queued job, download route,
and notification on top of a table that already exists by the time it
starts.

```php
Schema::create('ppra_inspection_packs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('agency_id')->constrained();
    $table->foreignId('requested_by_user_id')->constrained('users');

    $table->enum('status', ['queued', 'generating', 'ready', 'failed'])->default('queued');
    $table->json('sample_deal_ids')->nullable();      // user-picked deal ids for item k
    $table->json('sample_rental_ids')->nullable();    // user-picked rental/application ids for item l
    $table->json('sample_listing_ids')->nullable();   // user-picked property ids for item m

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

### 4.6a New columns on `agencies` — sample-pack sizes (v3, §1a "every threshold is a setting")

```php
Schema::table('agencies', function (Blueprint $table) {
    $table->unsignedTinyInteger('ppra_pack_sales_sample_size')->default(5)->after('financial_year_start_month');
    $table->unsignedTinyInteger('ppra_pack_rental_sample_size')->default(5)->after('ppra_pack_sales_sample_size');
    $table->unsignedTinyInteger('ppra_pack_mandate_sample_size')->default(5)->after('ppra_pack_rental_sample_size');
});
```

Three separate settings, not one shared value — items k/l/m pull from entirely different data (deals, leases, listings) and an agency may reasonably want a different sample depth for each (e.g. a rentals-heavy agency wants a deeper rental sample than sales).

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
| **c) Principal's FFC** *(v3)* | `users.designation` LIKE `%Principal%` for the principal-designated user(s) → their `UserDocument` row, `document_type = 'ffc_certificate'` | red: no principal designated, or the principal's own UserDocument FFC is missing/expired. amber: expiring within `renewal_days` (default 60). green: valid. | "No principal designated (users.designation)" / "{name}'s FFC expired {date}" / "{name}'s FFC on file, valid until {date}" |
| **d) Trust account bank confirmation letter** | slug `bank_confirmation` | same as (b). **HFC's actual current row must render red** ("expired 25 Sep 2026") — this is the concrete case this spec's expiry-logic fix (§4.2) is verified against. | as (b), s54(1)-specific wording |
| **e) Trial balance** | slug `trial_balance` (new) | same as (b), `renewal_days` default 90. | "Latest trial balance uploaded {date} (agency accounting records are not kept in CoreX — this is a manual upload from your accountant)" |
| **f) Practitioner list w/ FFC no.** *(v3)* | Active users with `role` in `agent`, `branch_manager`, `admin` **only** — CoreX's actual distinct `role` values are `admin, agent, assistant, branch_manager, office_admin, super_admin, viewer`; this item explicitly **excludes** `assistant`, `office_admin`, `super_admin`, `viewer` (no separate "IT" role exists). Each row's FFC sourced from `UserDocument` (type `ffc_certificate`), same as (c). | green: 100% have a valid FFC. amber: ≥1 expiring. red: ≥1 missing/expired. | "{n}/{total} practitioners have a valid FFC on file — {names} need attention" + "Export to PDF/CSV" |
| **g) Letterhead** | `Agency.logo_path`, PPRA number cascade, `email_disclaimer`, `popi_url` (the exact fields `BaseSignatureMail::getAgentFooter()` already reads) | red: `logo_path` null. amber: logo set but no PPRA number resolvable. green: both present. | "Agency logo not set — letterhead cannot be generated" / "Agency logo and PPRA number on file — plain letterhead available" |
| **h) BEE certificate or affidavit** | slugs `bee_certificate` **or** `bee_affidavit` (satisfies_group `bee`) | green: either has a valid, non-expired provision. amber/red mirror (b) on whichever is present; red if neither present. | "No BEE certificate or sworn affidavit on file" / "{Certificate\|Affidavit} on file, valid until {date}" |
| **i) Transformation initiatives** *(v3)* | `agency_transformation_notes` current version, either `entry_type` | red: no non-deleted row exists yet. green: a current version exists (structured entries non-empty, or a document is attached). | "No transformation initiatives statement on file" / "Statement last updated {date} by {user} ({N} initiatives listed / document on file)" |
| **j) Sales/rentals list, current FY** *(v3, redefined)* | Properties active-and-advertised at any point in the FY — see §6.7 for the exact derivation and its stated limitation | `info` — links to the derived, FY-bounded, sales/rentals-split list | "View {N} sales and {M} rentals advertised at any point in {FY start}–{FY end}" |
| **k) Sales files sampled** *(v3, redefined)* | `info` row — links to the shared sample picker (§6.8a) for item k (§6.8b) | `info` | "Pick up to {N} deals (setting) and pull every document, communication and pipeline record CoreX holds for each" |
| **l) Rental files sampled** *(v3, redefined)* | `info` row — links to the shared sample picker (§6.8a) for item l (§6.8c) | `info` | "Pick up to {N} rentals (setting) and pull the lease, application, inspections, MDF, FICA and communications for each" |
| **m) Mandates + MDFs, active listings** *(v3, redefined)* | `info` summary row (aggregate mandate/MDF/FICA register, unchanged from v2 — §6.8 "register" sub-page) **plus** a link to the shared sample picker (§6.8a) for item m's deep sample (§6.8d) | green: 0 active-and-advertised listings with a missing mandate/MDF/FICA. amber: 1+ gaps, <10%. red: ≥10%, threshold configurable (§11). | "{n}/{total} active listings have every required document" |

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
   - Principal practitioner name(s) — `users.designation` LIKE `%Principal%` (v3, confirmed against live data: HFC's designation values include "Principal Practitioner", "Property Practitioner", "Candidate Property Practitioner", "CEO" — free text, not an enum; see §6.6a's limitation note).

2. **Table of Contents** — one row per item a–m: letter, short description, live status badge (colour + label), the page number its detail section starts on.

3. **Executive Summary of Gaps** — every item currently amber or red, in one table: **Item | Description | Status | Remediation Due Date | Assigned To | Note**. Pulled from `ppra_inspection_gap_notes`'s current entries; a gap with no note yet still appears with "No remediation date set" rather than being omitted (an unacknowledged gap is not allowed to look, at a glance, like there are no gaps). If every item is green, this section prints "No outstanding items" instead of an empty table.

4. **Per-item detail sections, a through m, in order** — each section:
   - Heading: "a) CIPC Documents" etc.
   - One line stating the PPRA s25 requirement being evidenced (plain-language, from §5's why-line logic, not a legal citation).
   - Status badge.
   - **Evidence included**: for a document-backed item (a, b, d, e, h), the file name and upload date; for the practitioner items (c/f), a reference to the FFC table below; for the letterhead item (g), a note that a sample is attached; for the FY/sampling/register items (j, k, l, m), a summary line ("{N} sales, {M} rentals in FY2026/27" / "{N} deal files sampled" / "{N} active listings, {G} with a gap").
   - If the item is amber/red: the gap description, its remediation due date and assignee (repeated here from the Executive Summary for a reader working section-by-section rather than jumping to page 3), and any note.

5. **Practitioner FFC Table** — embedded inside item (f)'s section (not a separate appendix — that is where a reader expects it): **Name | Role | FFC Number | Status | Expiry Date**, every active `agent`/`branch_manager`/`admin` user (§6.6's role filter — v3), sorted by status (red/amber first), then name. Item (c)'s own section shows the same row filtered to the principal(s) only.

6. **Footer, every page**: agency name, report reference, page X of Y, generation timestamp.

The report is what makes item (m)'s and the Executive Summary's "gaps with remediation dates" real: **before Phase A ships, there must be a way to attach a due date to a gap** (§4.4) — this is why gap-note tracking is pulled forward into Phase A alongside the report itself, not deferred.

### 6.3 Checklist page — `/admin/ppra-inspection-pack`

13 rows (a–m), traffic-light badge, why-line, inline "Set remediation date" action on open rows, and a "Fix it" / "View" action button per row linking to the relevant existing or new screen (§6.4–6.9). "Preview / Download Inspection Report" (§6.2) and "Download full inspection pack" (§6.9) calls-to-action at the top. Permission `ppra_inspection_pack.view`.

### 6.4 Agency documents vault — reuse existing screen

`/compliance/my-portal/agency-documents` (existing `AgencyDocumentsViewerController`) gains the two new document types automatically (they're rows in the existing catalogue — no new screen needed), plus the expiry-badge fix from §4.2 if required. Upload UI: existing `AgencyComplianceSettingsController` — confirm at build time whether its picker needs a small "these two rows satisfy the same requirement" grouping hint in the UI (cosmetic; not a schema requirement beyond `satisfies_group`). This screen's own permission/nav location is unchanged by this spec — it already exists under Compliance's my-portal area and stays there; only the NEW `/admin/ppra-inspection-pack` checklist links into it.

### 6.5 Transformation initiatives — `/admin/ppra-inspection-pack/transformation` (v3 — structured form OR document, + Ellie)

Full CRUD per §1a floor:
- **List** (version history): search — free-text over `summary` (and, for structured entries, over each initiative's `description`); sort — `created_at` (default: newest first); filter — date range (created between), entry type (structured / document); pagination — 25/page; empty state: "No transformation initiatives statement has been written yet."
- **Create/Edit — two paths, either satisfies item (i):**
  1. **Structured form** — a repeatable initiative row: description, start date, end date, people involved (free-text or multi-select of agency users), spend amount (ZAR). An **"Ask Ellie to help me draft"** button sends the agency's existing transformation-adjacent data CoreX already holds (agency name, location, any prior version's content as context) to `App\Services\AI\Ellie\EllieAgentService` (the existing Ellie drafting service — exact prompt shape confirmed at build time) and returns a draft the principal edits before saving, never auto-saved un-reviewed.
  2. **Document upload** — the principal uploads their own statement (PDF/DOCX) instead, with a one-line summary caption. Same file-handling pattern as the agency-documents vault (private `local` disk, anti-tamper download).
  - Either path: save creates a **new current row** (`entry_type` set accordingly), superseding the prior one (§4.3).
- **Archive**: soft-delete the current version (falls back to the next non-deleted row by `created_at`; if none remain, item (i) reads red again).
- **Restore**: from the version-history list; restoring a non-current historical version copies its `entry_type` + data forward as a new current row (§4.3).
- Scoping: **agency-only** by design — one transformation statement per agency, not per branch or per user. Recorded explicitly here as the deliberate exception to the own/branch/agency floor (§1c), the same kind of explicit, justified narrowing as a setting deliberately excluded from the onboarding wizard with a reason on record (§10a).
- Permission: `ppra_inspection_pack.configure` to write; `ppra_inspection_pack.view` to read.

### 6.6 Practitioner FFC register — `/admin/ppra-inspection-pack/practitioners` (v3 — new screen, role-filtered, sourced from UserDocument)

v2 extended the existing `/compliance/agents` dashboard; v3 replaces that plan — the role filter and FFC source are different enough from that screen's own purpose (FFC **and training** compliance, all active non-assistant staff) that bolting v3's narrower, PPRA-specific roster onto it would either change that screen's existing meaning or require two divergent read paths on one page. Instead: a new, purpose-built list on this module's own screen.

- **Roster query**: `User::where('agency_id', $agencyId)->where('is_active', true)->whereIn('role', ['agent', 'branch_manager', 'admin'])->whereNull('deleted_at')`. Explicitly excludes `assistant`, `office_admin`, `super_admin`, `viewer` — CoreX's complete set of distinct `role` values (confirmed against the live database; there is no "IT" role to separately exclude).
- **FFC source**: for each user, their current `UserDocument` row with `document_type = 'ffc_certificate'` and `status = 'verified'` (falling back to `pending`/whatever the latest is, if none verified — same "show what's there, flag what's not" principle as the agency vault) — **not** the legacy `users.ffc_certificate_path`/`ffc_number` columns v2 used. Download via the existing `user-documents.download` route (anti-tamper, own controller checks).
- **Search**: name, designation. **Sort**: status (red/amber first, default), name. **Filter**: status, role. **Pagination**: 25/page. **Columns**: Name, Role, Designation, FFC Number, Status, Expiry.
- **Export to PDF / CSV**: same roster, gated `ppra_inspection_pack.export`.
- **Item (c)** is this exact same screen/service, pre-filtered to `users.designation` LIKE `%Principal%` — not a separate roster or a separate document source.

### 6.6a Known limitation — "principal" is free text

`users.designation` is an unconstrained text field (confirmed values on HFC: "Principal Practitioner", "Property Practitioner", "Candidate Property Practitioner", "CEO"). Matching `LIKE '%Principal%'` finds "Principal Practitioner" reliably today, but is not a strict enum — nothing stops a future user carrying a differently-worded designation from being silently excluded, or "CEO" (who may also legally be the principal) being silently missed. This spec uses the existing field as the best available signal rather than inventing a new `is_principal` boolean without a mandate — flagged for Johan in §12; trivial to formalise later if this proves fragile in practice.

### 6.6b Sample letterhead PDF (item g) — plain, not a demo

A "Generate letterhead" button on the checklist page's item (g) row (and/or the practitioner register), producing a **plain** one-page PDF rendered from the identical data `BaseSignatureMail::getAgentFooter()` uses (logo, PPRA number cascade, disclaimer, POPI URL) — reuses the existing Puppeteer HTML-to-PDF pipeline. **v3 correction:** the Phase B build added a "SAMPLE" diagonal watermark and explanatory body copy ("This page demonstrates the letterhead information CoreX renders on every document…") — that is a demo page, not a letterhead. Phase C removes both: the output is a blank, genuinely usable letterhead template (header branding + footer contact/PPRA/disclosure block, empty body) an agent could actually print or trace a physical letter onto.

### 6.7 Item (j) — Current FY sales/rentals list, "active and advertised" (v3, redefined)

Definition per Johan's ruling: every property that was ACTIVE **and** ADVERTISED (syndicated to a portal, or live on the agency's own website) at any point during the current financial year — not merely "created" or "sold" in that window. Split into Sales and Rentals by `properties.listing_type`/`category`.

**"Advertised" proof — what CoreX actually has:**
- Portal syndication timestamps already on `properties`: `p24_activated_at`, `p24_last_submitted_at`, `p24_syndication_status`, `p24_syndication_enabled`, `pp_activated_at`, `pp_last_submitted_at`, `pp_syndication_status`, `pp_syndication_enabled`.
- Agency's own website: `property_website_syndication` table (`PropertyWebsiteSyndication` model, SoftDeletes, status PENDING/SUBMITTED/ACTIVE/DEACTIVATED/ERROR) — a property counts as advertised for a period while it has an ACTIVE row there.
- General "first went live" signal: `properties.first_marketed_at` / `listed_date`.

**Derivation:** a property is included in FY{Y} if, for ANY of the three channels, its activation timestamp falls within the FY window, OR it was already active/syndicated going into the FY and remained so into it (using each channel's own status + timestamp columns).

**Stated limitation (honest, not hidden):** `p24_activated_at`/`pp_activated_at` and the other single-value timestamp columns are **overwrite-on-reactivation** — they hold only the most recent activation, not a full on/off history. A property advertised, pulled, and re-advertised within the same FY still shows correctly as "advertised at some point in FY{Y}" (all item j requires), but CoreX cannot reconstruct how many separate advertising windows it had within the year from P24/PP columns alone — only `property_website_syndication` carries a real historical trail (soft-deleted rows preserve prior periods). Disclosed as a one-line caveat in the Report's item (j) section, not silently glossed over.

**UI**: extends the existing DR2 deals list (`/deals-dr2`) and rentals list (`/rentals`) — no new screens:
- **Setting**: "Financial year starts in" (month picker, default March) — new "PPRA Inspection Pack" section on `/corex/settings`, admin-only (`ppra_inspection_pack.configure`); Setup Wizard entry per non-negotiable §10a (`explain` + `affects` + saver wired, reading `.ai/specs/agency-onboarding-setup.md` §6.1 first).
- Both lists default-bound to the current FY using the "active and advertised" derivation above (not simple `created_at`/`sold_at` filtering); a manual date-range override ("Custom range" toggle) replaces the FY default for that session's view.
- **Export PDF / CSV** buttons reflecting the currently-applied filter, gated on the EXISTING `deals.view`/rentals-view permission — unchanged from v2, viewing/exporting a list you can already see is not a new privilege.
- Existing search/sort/filter/scoping unchanged — the FY-and-advertised bound is an additional default filter layered on top, never a replacement for own/branch/agency scoping.

### 6.8a Shared sample picker — reusable component for items k/l/m

One Blade/Alpine component (`resources/views/components/ppra-sample-picker.blade.php` + a small backing service), parameterised by "deal" / "rental" / "listing" mode, used by all three of k/l/m rather than three bespoke pickers:

- **Search**: address/property reference, agent/practitioner name, contact name.
- **Filter**: date range (closed date for k/l, listed date for m), status, agent.
- **Selection**: multi-select checkboxes, capped at the agency's configured N for that mode (§11) — a "select N most recent" quick-action pre-ticks the N most recently closed/most recently listed, editable before confirming.
- **Persistence**: confirmed selection is saved onto `ppra_inspection_packs` — `sample_deal_ids` (k), `sample_rental_ids` (l), `sample_listing_ids` (m, §4.5) — so a pack regeneration reuses the same sample unless the admin re-opens the picker and changes it. Selection is per-pack, not a standing setting.
- **Access**: opened from the checklist page's item k/l/m row ("Choose sample" action) and from the "Download full inspection pack" flow — same component, same persistence target.

### 6.8b Item (k) — Sales file samples

Admin picks N closed/active sale deals (agency setting, default 5, §11) via §6.8a's picker. For each selected deal, the pack pulls **everything CoreX holds**:
- The DR2 deal record + its pipeline/stage history.
- Every `Document` attached to the deal (`$deal->documents`) — mandate, OTP/sale agreement, MDF/disclosure, commission/proforma, whatever is actually filed.
- Mandate and MDF sourced from the **property's** own document drive too (in case the property-level mandate wasn't separately re-attached to the deal) — deduplicated if the same file is both.
- FICA for all parties on the deal (buyer, seller, any co-parties), sourced the same way the existing FICA module already resolves per-contact FICA status/documents.
- Communications logged on the deal — CoreX's existing communications log for that deal, exported as a chronological text/PDF log, not raw `.eml` files.
- Commission/proforma documents, if filed.

**Output**: one folder per sampled deal inside the pack ZIP (`k-sales-sample/{deal-reference}/`); the Report's item (k) section includes a **per-file index page** — one row per file per deal, naming what it is and where it sits in the ZIP — so an inspector can cross-reference the bundle against the report without opening every folder blind.

### 6.8c Item (l) — Rental file samples

Same picker (§6.8a), N rentals (agency setting, default 5, §11), sourced from the lease/Rental tab + the property + its contacts:
- Mandate (property-level).
- Rental application.
- Lease agreement.
- Move-in and move-out inspection reports — if only one exists (e.g. an ongoing lease with no move-out yet), the pack includes what exists and the index page states which is missing and why, not a silent gap.
- MDF (property-level).
- FICA for both tenant and landlord.
- Communications logged on the rental/lease record.

**Output**: same shape as (k) — one folder per rental (`l-rental-sample/{property-or-lease-reference}/`), plus a per-file index page in the Report's item (l) section.

### 6.8d Item (m) — Mandate/MDF samples

Same picker (§6.8a), N active advertised listings (agency setting, default 5, §11 — "advertised" reusing item j's §6.7 derivation, so the sample is drawn from genuinely live listings, not merely `status = active` in isolation):
- Mandate.
- MDF.
- FICA of the owner(s).
- Body corporate rules (where sectional title and filed).
- Levy/rates statements.
- Any other document filed on the property's drive that a s25 inspection would reasonably expect — included, not filtered to only the named types, since the instruction is "and other property drive docs."

**Output**: same shape — one folder per listing (`m-mandate-sample/{property-reference}/`), plus a per-file index page in the Report's item (m) section.

**Relationship to the ongoing register (§6.8e):** the register still drives item (m)'s live checklist status (red/amber/green against ALL active advertised listings, not just the sample) — the sample in the pack is evidence for the inspector; the register is how the admin sees and fixes gaps agency-wide before an inspection, and is what the checklist's item (m) "Fix it" action links to.

### 6.8e Mandate/MDF/FICA register — `/admin/ppra-inspection-pack/mandate-register` (gap-monitoring feed for item m's checklist status)

New screen, new lightweight query layer (**no new table** — reads `Property` + a narrow `MarketingReadinessService::documentGateSummaryFor(Property $property): array` limited to the `mandate`/`fica`/`disclosure` slugs, so the register doesn't pay for the photo/details-complete checks `statusFor()` also runs — a register-specific method, not a change to `statusFor()`'s existing contract).

- **Search**: property address, suburb, listing agent name.
- **Sort**: address (default, ascending), mandate/MDF/FICA status, listing date.
- **Filter**: status (`all` / `missing mandate` / `missing MDF` / `missing FICA` / `all clear`), branch, agent, date range (listed between), advertised-only toggle (reuses §6.7's derivation — default ON, since an inspector cares about advertised stock).
- **Pagination**: 25/page. **Empty state**: "No active listings match this filter" vs "This agency has no active listings yet" (§1b).
- **Scoping**: reachable only by `ppra_inspection_pack.view` holders (admin/super_admin) — effectively agency-wide for that admin's own agency; the boundary that must never be crossed (another agency's data) is still enforced via `BelongsToAgency`/`AgencyScope`, the finer own/branch tiering simply has no one below admin tier to apply it to. Recorded as the deliberate scoping decision §1c's floor requires, not an oversight.
- **Columns**: address, agent, mandate (✓/✗), MDF (✓/✗), FICA (✓/✗), advertised (✓/✗), listed date.
- **Action**: "Download ZIP of all mandates + MDFs" — a convenience bulk export of the **current filtered view**, capped at a configurable max-files-per-ZIP (default 200, §11). This is a standalone admin tool, distinct from the pack's sampled item (m) evidence (§6.8d) — the two are not the same artifact and are not conflated in the Report.

### 6.9 Per-deal / per-rental ZIP + the full pack generator (v3 — samples via picker, trial balance folded into vault)

- **Per-deal ZIP**: a "Download as ZIP" button on the DR2 deal detail page (`resources/views/deals-v2/show.blade.php`, next to the existing per-document download links), bundling every `Document` currently attached to `$deal->documents`. Same visibility scope as viewing the deal itself (existing `deals.view`-tier scoping, not this module's admin gate); re-checked on the download route, not inferred from page access alone.
- **Per-rental ZIP**: same shape, on the rental application detail page, bundling everything under the `rental_application_document` pivot.
- **Full pack generation**: `GeneratePpraInspectionPackJob` (queued), dispatched from `POST /admin/ppra-inspection-pack/generate` (permission `ppra_inspection_pack.generate`) that first creates the `ppra_inspection_packs` row (`status = queued`, `agency_id` set explicitly, §4.5) and returns immediately — the checklist page polls/shows "Generating…". The job:
  1. Renders the Inspection Report (§6.2, now including the k/l/m per-file index pages and item (j)'s advertised-derivation caveat) and freezes it as `report_pdf_path`.
  2. Pulls every current agency-vault document — a, b, d, h, **and item (e)'s trial balance**, uploaded directly into the same vault slot (per Johan's ruling, e stays a simple vault upload, no separate sampling).
  3. Pulls each principal's and each practitioner's current FFC certificate from `UserDocument` (c/f, §6.6).
  4. Generates the plain letterhead PDF fresh (g, §6.6b).
  5. Includes the transformation-initiatives entry — the uploaded document if `entry_type = document`, or the rendered structured content in the Report's item (i) section if `entry_type = structured` (i).
  6. Bundles the sampled deals' full file sets using `sample_deal_ids` (k, §6.8b) — reuses the saved picker selection, or prompts to pick if none saved yet.
  7. Bundles the sampled rentals' full file sets using `sample_rental_ids` (l, §6.8c).
  8. Bundles the sampled listings' full file sets using `sample_listing_ids` (m, §6.8d).
  9. Zips the Report + all of the above, writes `zip_path`/`report_pdf_path`/`zip_size_bytes`, sets `status = ready`, `generated_at = now()`.
  10. On any failure: `status = failed`, `error_message` set, **never** left silently stuck at `generating`.
  11. Fires the `ppra_pack.generation_complete` notification (§4.7) via the AT-235 gateway (`NotificationDispatcher::send()`) to the requesting admin — database + email.
- **Download route**: `GET /admin/ppra-inspection-pack/{pack}/download`, agency-scope re-checked (mirrors `AgencyDocumentsViewerController::download()`'s multi-tenant check) AND `ppra_inspection_pack.generate` re-checked, serves `zip_path` from the `local` disk. No public/unauthenticated share link.
- **Regeneration contract**: if `sample_deal_ids`/`sample_rental_ids`/`sample_listing_ids` are already set on the pack (from a prior generation or a picker visit), regenerating reuses them without reopening the picker — an admin can hit "Regenerate" after fixing a gap without re-selecting samples every time.

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

The new practitioner register (§6.6), the sample picker (§6.8a) and its k/l/m outputs, and the mandate register (§6.8e) all ride the same four slugs above — no new slug introduced in v3: `.view` to see, `.export` to export, `.generate`/`.configure` to write/select samples.

---

## 8. Build Sequence (v3 — re-planned around Johan's 28 September 2026 item rulings; each phase independently shippable)

**Phase A — shipped (`24bc286df`, migration-fixed `e822c50f1`)** — Checklist framework, gap remediation tracking, the Inspection Report, items a/b/d/e/h.

**Phase B — shipped (`7534cca66`)** — v2's item c/f (later superseded by v3, see Phase C below) + item g sample letterhead (later corrected by v3, see Phase C) + the agency-documents ↔ pack linking Johan required mid-Phase-A-fixes.

**Phase C (v3, revised) — items c & f: principal/practitioner FFC roster + item g plain-letterhead fix**
- New screen `/admin/ppra-inspection-pack/practitioners` (§6.6): role-filtered roster (`agent`/`branch_manager`/`admin` only), sourced from `UserDocument` (`document_type = 'ffc_certificate'`), full search/sort/filter/pagination, PDF/CSV export.
- Item (c) view: same screen/service, pre-filtered to `users.designation LIKE '%Principal%'` (§6.6a's limitation noted in-spec).
- Checklist rows c/f re-wired off the new roster service (replacing v2's `/compliance/agents` read).
- Item (g) fix: remove the "SAMPLE" watermark and demo body copy from the letterhead PDF (§6.6b) — output becomes a genuinely usable blank letterhead template.

**Phase D — item i: transformation initiatives (v3, structured form OR document + Ellie)**
- Migration `agency_transformation_notes` (v3 schema: `entry_type`, `structured_data` json, `document_path`/`document_original_name`, `summary` — §4.3).
- Structured repeatable-initiative form (description/dates/people/spend) with an "Ask Ellie to help me draft" button wired to `App\Services\AI\Ellie\EllieAgentService`.
- Document-upload alternative path, same vault-style file handling.
- Full CRUD (§6.5): version history, archive, restore (reusing the `withTrashed()` route-binding pattern already proven in the abandoned v2 build).
- Checklist row i + Report section wired.

**Phase E — item j: financial year + "active and advertised" FY lists (v3, redefined)**
- Migration `agencies.financial_year_start_month`.
- Settings UI + **Setup Wizard entry** (non-negotiable §10a, same phase, not deferred).
- FY-and-advertised derivation (§6.7) against `p24_activated_at`/`pp_activated_at`/`property_website_syndication`, replacing v2's simpler date-filter plan.
- FY-bounding + manual override + PDF/CSV export on DR2 deals list and rentals list, split sales/rentals.
- Checklist row j + Report section (including the overwrite-on-reactivation caveat) wired.

**Phase F — shipped (`<PHASE_F_SHA>`) — shared sample-picker component (§6.8a)**
- Reusable Blade/Alpine picker (`resources/views/components/ppra-sample-picker.blade.php`), deal/rental/listing modes, search (address/agent/contact name for deal, address/agent for rental/listing), filter (date range, status, agent), multi-select capped at the agency's configured N, "select N most recent" quick-action. Backed by `PpraSamplePickerService` (`app/Services/Compliance/PpraSamplePickerService.php`) and `PpraSamplePickerController` (`app/Http/Controllers/Admin/PpraSamplePickerController.php`).
- Persists to `ppra_inspection_packs.sample_deal_ids`/`sample_rental_ids`/`sample_listing_ids`, via `PpraInspectionPack::findOrCreateDraftFor()` — the agency's current `status='queued'`/`generated_at=null` row, created on first picker use if none exists yet. This is the resolution to a design question the spec itself left open: §6.8a says "Selection is per-pack, not a standing setting" but no pack exists until Phase J's "Download full inspection pack" is ever clicked. A find-or-create draft pack lets the picker (and, later, the checklist's item k/l/m "Choose sample" action) persist a selection before any generation has been requested; Phase J's `GeneratePpraInspectionPackJob` consumes whichever draft is current at dispatch time.
- **`ppra_inspection_packs` migration (§4.5) moved from Phase J to Phase F.** Phase F's own deliverable requires the table to persist to; Phase J's remaining scope (job/route/notification/regeneration) is unchanged and builds on the table created here — Phase J's build-sequence entry below is updated to reflect this.
- New agency settings: `ppra_pack_sales_sample_size`, `ppra_pack_rental_sample_size`, `ppra_pack_mandate_sample_size` (§4.6a) — `/corex/settings` → PPRA Inspection Pack section (same form/saver as the FY setting) and the Setup Wizard's Compliance step (non-negotiable §10a), all three, same as the FY setting's own precedent.
- No standalone checklist row shipped — pure infrastructure consumed by Phases G/H/I, as specced. A **temporary, unlinked verification harness** was added at `/admin/ppra-inspection-pack/sample-picker/preview` (permission `ppra_inspection_pack.view`, direct-URL only, no sidebar/nav entry, no link from the checklist page) purely so this phase's deliverable is browser-testable ahead of G/H/I wiring the real k/l/m rows. Remove this route once Phase I lands (the real rows supersede it).
- "Rental" mode is the `Rental` model (the lease record, `rentals` table) — agency-scoped via `whereHas('branch', ...)` since `Rental` carries `branch_id` not `agency_id` directly. "Listing" mode reuses `Property::onMarket()` (not the FY-bounded `PpraFinancialYearListService::advertisedListings()` — that reuse is item m's job in Phase I per §6.8d, not Phase F's).

**Phase G — item k: sales file samples**
- Deep per-deal aggregation (§6.8b): deal + pipeline, all deal docs, property-level mandate/MDF, FICA all parties, communications log, commission/proforma.
- Per-deal-folder ZIP structure + per-file index page in the Report.
- Checklist row k wired to the picker (§6.8a).

**Phase H — item l: rental file samples**
- Same shape as G for rentals (§6.8c): mandate, application, lease, in/out inspections, MDF, FICA tenant+landlord, communications.
- Checklist row l wired.

**Phase I — item m: mandate/MDF samples + the ongoing register**
- `MarketingReadinessService::documentGateSummaryFor()`.
- `/admin/ppra-inspection-pack/mandate-register` (§6.8e) — gap-monitoring feed for the checklist's live status.
- Sample deep-aggregation via the picker (§6.8d): mandate, MDF, FICA of owners, body corporate rules, levy/rates statements, other property-drive docs.
- Checklist row m wired to both the register (status) and the picker (evidence).

**Phase J — full inspection pack**
- `ppra_inspection_packs` table already exists (migration moved to Phase F — the table is required there to back the sample picker's persistence). Phase J adds the `notification_event_types` row only.
- `GeneratePpraInspectionPackJob` (bundles source files from Phases A–I around the Phase-A Report renderer, including trial balance from the vault per item e), download route, notification, regeneration contract (§6.9).
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
| Full-pack generation requested while an agency has 0 active listings | Pack still generates (a/b/c/d/e/f/g/h/i sections populate normally); the mandate/MDF section and Report both say "No active advertised listings" rather than silently omitting the section. |
| Sample picker: user picks 0 deals/rentals/listings | Allowed — the pack still generates with everything else; the Report notes "No sales/rental/mandate files sampled for this pack." |
| Two admins trigger full-pack generation simultaneously | Each gets their own `ppra_inspection_packs` row — no dedup/lock. (If this proves wasteful in practice, a "reuse an in-flight pack for the same agency" optimisation is a fast-follow, not blocking Phase J.) |
| Deal/rental/listing sampled has zero documents of some category (e.g. no move-out inspection yet) | The per-file index page states which document is missing and why, never a silent gap in the folder. |
| Mandate-register ZIP exceeds the max-files-per-ZIP cap | Bundles the first N (by the register's current sort order) and the index/response clearly states how many were included vs. how many exist, never silently truncates without saying so (BUILD_STANDARD "no silent caps"). |
| Non-admin user directly hits any `/admin/ppra-inspection-pack/*` URL | 403, not a redirect that leaks whether the resource exists — same pattern as every other admin-gated route. |
| No user matches `designation LIKE '%Principal%'` for an agency | Item (c) reads red with "No principal practitioner identified — check user designations," not a blank/broken section (§6.6a). |
| Transformation-initiatives entry is `entry_type = document` but the Ellie draft button was used earlier and discarded | No trace kept of a discarded Ellie draft — only a saved version (structured or document) exists; drafting is never itself persisted as a version. |
| A property's `p24_activated_at`/`pp_activated_at` predates CoreX's own adoption of that column (legacy data) | Treated as "advertised, exact reactivation history unknown" per the stated §6.7 limitation — included in the FY list if the single timestamp falls in-window, never excluded for lack of full history. |
| Picker's "N most recent" quick-action is used but fewer than N deals/rentals/listings exist | Pre-ticks whatever exists (< N), no error, no padding with unrelated records. |

---

## 10. Acceptance Criteria

Phase J (full module) is complete when:

1. An admin can open `/admin/ppra-inspection-pack` and see all 13 rows with live, correct status against real agency data — verified against HFC's actual QA1 data (bank confirmation letter red/expired, at minimum).
2. Every red/amber row's action link lands the admin on the exact screen needed to fix it, and can carry a remediation date + note.
3. The Inspection Report PDF has a real cover page, an accurate table of contents, an Executive Summary of Gaps with remediation dates, a detail section per item a–m (including k/l/m's per-file index pages and j's advertised-derivation caveat), and a properly-formatted practitioner FFC table — verified visually, not just "the PDF generated without error."
4. The practitioner FFC register (§6.6) correctly excludes `assistant`/`office_admin`/`super_admin`/`viewer`, sources FFC data from `UserDocument`, and item (c)'s principal-only view returns only designations matching `%Principal%`.
5. The transformation-initiatives statement supports BOTH the structured form (with a working Ellie draft-assist) and the document-upload alternative, has full CRUD with version history and archive/restore, and either path independently satisfies item (i).
6. The sample letterhead PDF is genuinely plain — no "SAMPLE" watermark, no demo explanatory copy.
7. The financial year setting bounds both the DR2 and rentals lists using the "active and advertised" derivation (not simple created/sold-date filtering), with a working manual override, and is present in the Setup Wizard.
8. The shared sample picker (§6.8a) works identically across deal/rental/listing modes, enforces each agency's configured N, and its selection persists on the pack for regeneration.
9. Items k/l/m each produce a per-sampled-record folder in the pack ZIP with the correct document set for that domain (§6.8b/c/d), plus a per-file index page in the Report.
10. The mandate/MDF/FICA register (§6.8e) lists every active advertised listing with correct per-document status, full search/sort/filter/pagination, distinct from the pack's item (m) sample evidence.
11. "Download full inspection pack" queues, completes, notifies, and produces a ZIP whose frozen Inspection Report accurately lists both contents and remaining gaps, including the trial balance (e) pulled from the vault.
12. Every route under `/admin/ppra-inspection-pack/*` returns 403 for a non-admin user, verified by direct URL, not just absence from the nav.
13. Every new setting from this spec (financial year start month, document-type requirements, the three sample-size settings, ZIP max-files cap, mandate-register red/amber threshold) is a configurable value, none hardcoded, and financial-year-start-month is live in the Setup Wizard.
14. No agency-1/HFC-specific assumption anywhere — verified against a second agency with different vault configuration, a different financial year start month, and different sample-size settings.

---

## 11. Settings Introduced (every threshold is a setting — §1a floor)

| Setting | Default | Where configured |
|---|---|---|
| `agencies.financial_year_start_month` | March (3) | `/corex/settings` → PPRA Inspection Pack section (admin-only); Setup Wizard |
| Per-type `renewal_days` (incl. new `bee_affidavit`, `trial_balance`) | 365 / 90 respectively | Existing agency-documents vault settings UI |
| Per-type `required` (incl. `bee` group as a whole) | as existing / N for new types | Existing agency-documents vault settings UI |
| FFC-expiring-soon window (item c/f) | 60 days (existing `AgentComplianceController` constant — confirm it is already a setting, not a hardcoded literal, before reusing; make it one if not) | `/corex/settings` → PPRA Inspection Pack section |
| Mandate-register red threshold | 10% of active listings with a gap | `/corex/settings` → PPRA Inspection Pack section |
| `agencies.ppra_pack_sales_sample_size` (item k) | 5 | `/corex/settings` → PPRA Inspection Pack section (§4.6a) |
| `agencies.ppra_pack_rental_sample_size` (item l) | 5 | `/corex/settings` → PPRA Inspection Pack section (§4.6a) |
| `agencies.ppra_pack_mandate_sample_size` (item m) | 5 | `/corex/settings` → PPRA Inspection Pack section (§4.6a) |
| Mandate-register-ZIP / per-list-ZIP max files per bundle | 200 | `/corex/settings` → PPRA Inspection Pack section |

Three separate sample-size settings (not one shared N) because k/l/m draw from different-sized, differently-paced populations per agency (a busy sales desk vs. a small rental book vs. total active stock) — forcing one shared number would either over-sample the smallest or under-sample the largest.

---

## 12. Open Questions for Johan (business calls, not engineering ones)

- Is a 10%-of-active-listings red threshold for the mandate/MDF register the right business line, or should ANY gap be red? (Currently proposed as amber-until-10%, per §5 item m — easy to change before Phase I, since it's a setting either way.)
- Sworn-affidavit wording/template for item h — is a CoreX-generated affidavit template wanted (Phase 2, out of scope here), or is upload-only sufficient indefinitely?
- Item (c)'s principal match is `users.designation LIKE '%Principal%'` — a free-text match, not a strict flag (§6.6a). Confirmed working against HFC's real data today. Worth a dedicated `is_principal` boolean later if this proves fragile across other agencies' designation wording, but not built speculatively without a mandate.
- Should an overdue remediation date (§9) trigger anything (a notification, an escalation), or is it deliberately silent — visible only when someone looks? Currently spec'd as silent by design; easy to add later if wanted.
- Ellie's "help me draft" for item (i) (§6.5): what should the draft actually be seeded with beyond agency name/location/prior version — does Johan want it to pull in any specific CoreX data (e.g. agent demographics, past training records) to make the draft genuinely useful, or is a generic starting point sufficient? Confirmed at Phase D build time either way, flagged here so the scope isn't guessed.
