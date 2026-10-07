# CoreX OS — Viewing Pack Specification

**Spec ID:** AT-107
**Status:** Draft — ready for build
**Author:** Johan (product architect) + Claude (senior engineer)
**Date:** 2026-06-28
**Depends on:** Document-type audit (`.ai/audits/2026-06-28-viewing-pack-doctype-audit.md`, verdict A)
**Reuses:** `PropertyBrochureService` (Staging), `document_types` catalogue, AT-105 PDF Splitter, AgencyScope
**Related fast-follow:** Core Match Intelligence (separate ticket — not in this build)

---

## 1. Purpose & Doctrine

The Viewing Pack is the **buyer-facing mirror of the Presentation**. Where the Presentation
gives a seller evidence to win a mandate, the Viewing Pack gives a buyer a polished, ordered
set of properties to view on a tour the agent arranges.

It is a **container feature**: it assembles existing artifacts (the property brochure, attached
documents) into two coordinated outputs. It does not invent a new property-page layout — it
reuses the proven brochure engine.

**Compliance spine (non-negotiable):**
- The pack produces **two separate PDFs** — a buyer pack and an agent sheet — generated and
  downloaded as **distinct files via distinct buttons**. They are NEVER merged into one file.
  Rationale: the failure mode is an agent printing a merged bundle and the confidential agent
  sheet ending up in the buyer's hands — a POPIA leak. Hard separation removes that failure mode.
- Sensitive documents attached to the buyer pack are **redacted by flatten/rasterize** — hidden
  text is destroyed, not covered. A black box over selectable PDF text is not redaction.
- The agent sheet carries a visible **"CONFIDENTIAL — AGENT EYES ONLY"** band and is governed
  by company policy (agent must not leave it unattended).

**Design principles applied:**
- *We do complicated so the user does simple.* — the agent selects properties and docs; the
  system handles ordering propagation, eligibility filtering, redaction flattening, dual-PDF
  generation, calendar linking, and audit persistence.
- *Integrate everything.* — selection drives the calendar event, the pack, the agent sheet, and
  the audit record from one action.
- *Flexibility everywhere.* — document eligibility is agency-configurable (catalogue default +
  per-agency override).

---

## 2. Entry Point & Flow

**Entry:** Buyer Pipeline → open a buyer → **Build Viewing Pack** (button/modal).

**Flow:**
1. Agent opens a buyer in the buyer pipeline.
2. System shows the buyer's **Core Matches** (matched properties).
3. Agent **selects properties** to show — from Core Matches and/or via **ad-hoc property search**.
4. Agent **sets the viewing order** (drag). This order propagates identically to both PDFs.
5. For each selected property, agent **picks which eligible documents** to include (filtered by
   `buyer_pack_eligible`).
6. Agent **redacts** sensitive areas on attached docs (on-screen, flattened on completion).
7. System **generates two PDFs** — buyer pack + agent sheet — as separate downloads.
8. Selection **creates/links a calendar viewing appointment** for the date/time.
9. The pack is **persisted** (`viewing_packs` record) for audit and regeneration.

---

## 3. Property Selection

**Sources (both, same downstream rules):**
- **Core Matches** — the buyer's matched properties, shown in the build modal.
- **Ad-hoc search** — agent searches any property and attaches it to the viewing.

**Doctrine:** selection source is irrelevant to all downstream rules. A Core-Match property and
an ad-hoc property are treated identically for ordering, eligibility filtering, redaction, and
rendering.

**Ad-hoc capture (silent):** when an ad-hoc (non-Core-Match) property is added, log a
`core_match_miss` event capturing a snapshot of the buyer's criteria and the property's
attributes at that moment. **No prompt, no interruption.** This is capture only — the diagnostic
and correction surface is the separate **Core Match Intelligence** fast-follow ticket and is OUT
OF SCOPE here. This spec only writes the capture event.

---

## 4. Ordering

- Agent sets viewing order by **drag-and-drop** on the selected property list.
- Order is **agent-controlled, manual** — there is NO automatic route/geography optimization.
  Real-world order is driven by access logistics (seller availability, key collection, tenant
  arrangements), which the agent knows and the system does not.
- The chosen sequence is the **page order** in both the buyer pack and the agent sheet —
  identical in both.
- The sequence number shown on each property page = the agent's chosen order.

---

## 5. Document Eligibility & Selection

**Eligibility model (per audit verdict A — clean ground):**
- New catalogue-default column `buyer_pack_eligible` on the canonical **`document_types`** table
  (the one keyed by `slug` — NOT `document_library_types`).
- Nullable per-agency override column on **`agency_document_type_compliance`**
  (NULL = inherit catalogue default; set = agency's stricter/looser choice), resolved through
  `AgencyComplianceDocTypeService` following the existing `save_to_*` / `contact_roles` /
  `fica_slot` pattern.
- Managed on the existing settings page: `/admin/settings/document-types`
  (`SplitterDocTypeController`, `admin/splitter/doc-types.blade.php`). The `bulkSave` loop
  already iterates every type — the flag slots into that loop.

**Selection UX:** when building a pack, for each property the agent sees only the
**buyer-pack-eligible** documents currently attached to that property (or its contact) and ticks
which to include. Ineligible types (e.g. Seller ID, FICA docs) are never shown.

**Eligibility defaults (catalogue) — agency-overridable:**
- Buyer-pack-eligible: rates & taxes statement, levy statement, and similar buyer-relevant docs.
- Never eligible (catalogue default): Seller ID, FICA documents, mandate internals, anything
  identity/compliance-sensitive.
- (Full per-type yes/no to be set on the settings page at build time.)

---

## 6. Redaction

- **Scope:** attached documents ONLY. The generated brochure/property page is built from clean
  fields and has nothing to redact.
- **Mechanism:** on-screen tool. Agent sees the embedded PDF/image, drags black boxes over
  sensitive areas (e.g. on a rates statement: redact account numbers, leave the rates/levy
  figures the buyer should see).
- **Flattening (HARD RULE):** on "Done", the redacted page is **rendered to a flat raster image**
  — the original text/objects underneath are **destroyed**, not covered. Verification standard:
  no selectable text, no recoverable objects, nothing visible when held to light / printed over.
  A covered-but-present text layer is a POPIA breach and is not acceptable.
- The redacted, flattened version is what is stored and embedded in the buyer pack.

---

## 7. Buyer Pack Output (PDF #1)

**Structure (in order):**
1. **Cover page** — reuse the presentation cover engine. Buyer name(s), date, agent block
   (photo/contact), agency branding, property count.
   *The agency chooses the cover style (§14): "Standard" (this layout, the default) or "Classic welcome".*
2. **Per-property pages** — in the agent's chosen order. **Reuse `PropertyBrochureService` /
   `_brochure.blade.php`** per property (price, address, ref, status, hero + photo strip,
   beds/baths/garages, rates & levy, features, agent block, QR). Plus:
   - Any **redacted, eligible attached documents** the agent included for that property.
   - A **buyer notes block** — lined/open space, same position on every page. This is the
     buyer's private scratch space; it leaves with the buyer; the system never captures it.
3. **Comparison page** — closing page; all selected properties side-by-side.

**Output:** single PDF, e.g. `VIEWING-PACK-{BuyerName}.pdf`. Colour, print-ready.

---

## 8. Agent Sheet Output (PDF #2)

**Structure (in order):**
1. **Minimal header** — NOT a full branded cover (working doc, not a presentation piece). Carries
   the **"CONFIDENTIAL — AGENT EYES ONLY"** band, visible at a glance.
2. **Per-property pages** — same properties, **same order**, **same render as the buyer page for
   now** (see note below). Plus:
   - An **agent notes block** per property — where the agent jots buyer reactions live during the
     viewing. The agent later transcribes these into the existing calendar feedback system
     (internal notes + seller notes). We do NOT rebuild feedback.
3. **Comparison page** — same as buyer pack (may carry richer/comp data in future).

**Render decision (this build):** agent sheet = buyer render + confidential treatment + agent
notes block. **No extra intel fields yet.** Agent-specific intel (comps, seller motivation,
mandate terms, commission) is a **named future extension**: extend `PropertyBrochureService::data()`
with an agent-variant flag once agents tell us what they want. The render is built to accept that
extension cleanly.

**Redaction:** NONE. Agent sheet is eyes-only by policy; agent works from full unredacted info.

**Output:** separate PDF, separate button, e.g. `AGENT-SHEET-{BuyerName}.pdf`. Never merged with
the buyer pack.

---

## 9. Calendar Tie-in

- Building/confirming a pack **creates or links a viewing appointment** (the calendar event for
  the date/time of the tour).
- The `viewing_packs` record links to that calendar event.
- The agent's post-viewing feedback (existing internal/seller notes on the calendar event) is the
  capture layer — fed from the agent sheet's handwritten notes.

---

## 10. Settings

- Document-type eligibility (`buyer_pack_eligible`) is exposed on the existing
  `/admin/settings/document-types` page — catalogue default + per-agency override columns added
  to the existing `bulkSave` editing loop.
- **Cover style (§14)** — `agencies.viewing_pack_cover_style` plus cover slogan / website / office phone /
  accent colour, on Company Settings → Branding and in the Setup Wizard `branding` step; each change is audited.
- Any threshold/limit introduced (e.g. photo caps, description caps inherited from the brochure,
  max properties per pack if any) must be **agency-configurable**, never hardcoded.

---

## 11. Data Model & Build Notes

**New persistence:**
- `viewing_packs` — id, buyer/contact_id, agent_id, agency_id, calendar_event_id (nullable),
  status, created/updated. Soft-deletes only (no hard deletes — archive/recover).
- `viewing_pack_properties` — pack_id, property_id, sort_order (the drag order),
  source (`core_match` | `ad_hoc`).
- `viewing_pack_documents` — pack_property_id, document_id, document_type_slug,
  redacted_file_path (nullable), included (bool). Stores the flattened redacted artifact reference.
- Eligibility columns: `buyer_pack_eligible` on `document_types`; nullable override on
  `agency_document_type_compliance`.
- `core_match_miss` event/log table for ad-hoc capture (capture only this build).

**Audit findings baked in (from gate-zero audit):**
1. **Table name:** canonical doc-type table is `document_types` keyed by `slug`. Do NOT touch
   `document_library_types` (the renamed-away presentation table keyed by `key`).
2. **`$fillable` gap:** `DocumentType.php` `$fillable` omits `contact_roles`/`fica_slot`. If
   `buyer_pack_eligible` is mass-assigned via the `DocumentType` model, ADD it to that model's
   `$fillable`.
3. **Per-agency override write pattern:** `AgencyDocumentTypeCompliance` writes happen via raw
   `DB::table()` in `AgencyComplianceDocTypeService` (NOT Eloquent mass-assignment). The
   per-agency eligibility override MUST follow that same raw-write service pattern.
4. **Schema dump:** after any migration, re-run `php artisan schema:dump` (non-negotiable #12a).

**Reuse confirmed (from listing-print check):**
- `PropertyBrochureService::pdf($property)` returns a finished A4 PDF; `data()` builds the field
  set; `_brochure.blade.php` is the shared layout. Reuse directly as the per-property page.
- Brochure is **Staging-only, not on main**. The Viewing Pack rides the same promotion train and
  must not hard-depend on the brochure being on `main`. Graceful handling if a brochure render is
  unavailable for a property.

---

## 12. Nav, CRUD, Robustness, Configurability (hard rules)

- **Nav:** the Build Viewing Pack action must have a navigation entry point in the buyer pipeline
  (button/modal). Any standalone pack list/history view gets a sidebar/menu link.
- **CRUD:** full CRUD on `viewing_packs` is the floor — create, view, edit (re-order, re-select
  docs, re-generate), archive (soft-delete), recover.
- **No hard deletes:** all deletes are soft (archive); admin can recover.
- **Robustness:** handle the whole input space — buyer with zero criteria, property with no
  photos/no brochure, doc with no eligible types, redaction with zero boxes, pack with one
  property. Transactions roll back clean. Blank fields suppressed (no empty rows rendered).
- **Configurability:** every threshold/window/cap is an agency setting.

**Build-prompt requirements (per hard rules):**
- Start by reading CLAUDE.md, .ai/STANDARDS.md, .ai/BUILD_STANDARD.md, and this spec.
- End with: `php -l` on changed PHP, `php artisan view:clear`, `scripts/dev-check.ps1`
  (0 new failures), Tinker functional verification, `php artisan schema:dump` if migrations.
  Report all results.
- Branch discipline: AT-issue branch → Staging → main → live. Staging stays superset.
- Worker restart after any live deploy.

---

## 13. Build Order

1. Eligibility model — `buyer_pack_eligible` catalogue column + per-agency override + settings UI.
2. `viewing_packs` data model + CRUD + buyer-pipeline entry point.
3. Selection (Core Match + ad-hoc) + `core_match_miss` capture.
4. Ordering (drag → sort_order).
5. Document selection (eligibility filter) + redaction tool (flatten/rasterize).
6. Buyer pack PDF (cover + brochure-per-property + notes + comparison).
7. Agent sheet PDF (confidential band + same render + agent notes + comparison).
8. Calendar tie-in.
9. Robustness pass, full CRUD, nav links, configurability sweep.

---

## Out of Scope (this build)

- Core Match Intelligence (three-tier agent/manager/admin correction surface) — separate
  fast-follow ticket. This build only writes the silent `core_match_miss` capture event.
- Agent-specific intel fields on the agent sheet — named future extension pending agent feedback.

## List page filters, frozen header (AT-393 — built 2026-09-14, Andre's instruction, QA1 only)

`/corex/viewing-packs` (route `corex.viewing-packs.index`).

**Why.** The packs list is paged at 25 but had no way to find one pack among many, and
the header scrolled away with the table. Same frozen-top treatment as the other AT-393
list pages, plus a filter card.

**Filters** — one GET form directly under the header; all optional (`''` = no filter);
they compose with each other and with the existing archived toggle (carried as a hidden
`archived=1` when viewing archived packs). Pagination carries them (`withQueryString`).

| Control | Query key | Behaviour |
|---|---|---|
| Search | `q` | `like` on pack `title` OR the buyer's name (person: first / last / "first last"; entity: `entity_name`) |
| Agent | `agent_id` | packs whose `agent_id` is that agent. Rendered only above `own` data scope — for `branch` scope the list is the branch's users, for `all` the agency's |
| Status | `status` | one of `ViewingPack::STATUSES` (`draft`, `ready`) |

"Search" submits; "Clear" (shown only when a filter is active) returns to the unfiltered
list, staying on archived if that was on. A live "N packs" count sits at the right of the
bar. Empty state distinguishes "no packs" from "no match".

**Layout.** Page wrapper is a full-height flex column; header + filter card are
`flex-shrink-0`; flash + table + pagination live in a
`flex-1 min-h-0 overflow-y-auto corex-brand-scroll` scroll region.

**Permissions.** Unchanged (`access_viewing_packs`, `viewing_packs.view`); row visibility
still via `scopeVisibleTo` (AT-112) — filters only ever narrow that set.

**Tests.** `tests/Feature/ViewingPack/ViewingPackIndexFilterTest.php`.

**Files.** `app/Http/Controllers/CommandCenter/ViewingPackController.php` (index),
`resources/views/command-center/viewing-packs/index.blade.php`.

---

## 14. Cover styles — "Classic welcome" (Johan 6 Oct 2026, from Elize at HFC; conductor rulings; BUILT on QA1)

**What and why.** HFC's agents used a specific cover before the CoreX viewing pack existed. The front page of the
buyer pack is now an **agency-selectable style**: `standard` (today's cover — the default for every agency, unchanged) or
`classic_welcome` (the layout below). Nothing is HFC-specific in code: HFC simply selects the style and types its own
slogan / website / phone; any other agency gets the same style with its own data.

**Layout — Classic welcome** (portrait A4, white; one PDF page — exactly one, proven in a test). Nothing else is on the page
(no PPRA line, buyer name, date or property count — a deliberate difference from the Standard cover).
- **Band:** full-height vertical band down the right edge, 60 px of 794 (7.5%), in the *cover primary* navy.
- **Band text:** white bold caps rotated 90° reading bottom-to-top — from the bottom the **slogan**, then the **website**, then at the
  top the **office phone**. A short **light-blue block** sits behind the first part of the slogan (only when there is a slogan).
- **Logo:** top, left of the band, fitted into 646 × 150 px keeping its aspect ratio (explicit pixel size — never stretched).
- **Headline** (centred in the area left of the band): "WELCOME TO" (navy, 32 pt) / "YOUR" (accent red, 115 pt, the dominant element) /
  "VIEWING DAY" (navy, 32 pt) — bold sans, caps. The reference page's point sizes are kept on A4.
- **Portrait:** the agent's **cut-out** photo (transparent PNG), lower right, left of the band, standing on the bottom edge, up to
  380 × 376 px (roughly the lower third of the page).
- **Agent block:** bottom left, stacked, navy bold — name, cell, email.

**Where every input comes from (existing data only, plus the five cover settings):**
| On the cover | Source | Falls back to | When missing |
|---|---|---|---|
| Logo | `agencies.logo_path` | — | the agency name as plain text; no broken image |
| Slogan | `agencies.viewing_pack_cover_slogan` | `agencies.tagline` | line omitted (and the light-blue block with it) |
| Website | `agencies.viewing_pack_cover_website` | `agencies.website_url` (`https://` and trailing `/` stripped for display) | line omitted |
| Office phone | `agencies.viewing_pack_cover_phone` | `agencies.phone` | line omitted |
| Band / "WELCOME TO" / "VIEWING DAY" / agent details (navy) | the agency's own `default_color` role **when it differs from CoreX's platform default #0b2a4a** (CoreX stores #0b2a4a as the platform default and the branding form re-posts it for every agency, so a value equal to it means "not chosen") | **#002060** | — |
| "YOUR" (accent) | `agencies.viewing_pack_cover_accent_color` (hex) | **#C00000** | invalid stored value ignored |
| Light-blue block | the agency's `icon_color`, then `button_color` | #00B4D8 | — |
| Portrait | `User::profilePhotoCutoutUrl()` (background-removed PNG) | the plain `profilePhotoUrl()`, then nothing | no image drawn |
| Agent name / cell / email | `users.name`, `users.cell` (else `phone`), `users.email` | — | that line omitted |
The existing agency tagline, website and phone are **never changed** by this feature (the tagline also prints on every letterhead).

**Settings (Company Settings → Branding → "Viewing pack cover"; Setup Wizard step `branding`).** `viewing_pack_cover_style`
(NOT NULL, default `standard`), `viewing_pack_cover_slogan` (≤120), `viewing_pack_cover_website` (≤120), `viewing_pack_cover_phone` (≤40),
`viewing_pack_cover_accent_color` (`#RRGGBB`, stored upper-case). Saved by the existing canonical `CompanySettingsController::update`
(validated keys only reach the row, so the company/website forms never wipe them; a blank style never nulls the column; blank
text/colour fields store null = use the fallback). Wizard: five rows in the `branding` step's `controls`, each with `explain` and
`affects` (non-negotiable #10a). **Audit:** `viewing_pack_cover_audit` (agency, user, old/new values, time) — one row per save that actually
changed something; none for an identical re-save.

**Cover preview.** On the Branding tab a "Cover preview" panel shows the cover in a frame, rendered from `GET/PUT
admin.company-settings.cover-preview` — the **same `cover.blade.php` + `_cover-classic.blade.php` the PDF renders** (one partial, so the
two cannot drift), for the acting user as the sample agent. "Preview with these values" re-renders it from the form's current,
unsaved values; nothing is written. Same permission and agency scope as the settings page. There is **no separate on-screen pack**
(the PDF is the pack — conductor ruling).

**Implementation map.** `ViewingPackCoverService` (data, fallbacks, colours, image fitting, audit), `ViewingPackBuyerPdfService::coverData()`
(adds the `cover` block), `buyer-pack/cover.blade.php` (dispatches on the style) and `buyer-pack/_cover-classic.blade.php` (the layout;
inline pixel styles, DomPDF-safe, rotated band via CSS `transform`), `CompanySettingsController` (validation, audit, `coverPreview`),
`company-settings/index.blade.php` (the section), `config/agency-onboarding-copy.php` (wizard rows), migration
`2026_10_12_200000_add_viewing_pack_cover_settings`. Tests: `tests/Feature/ViewingPack/ViewingPackCoverStyleTest.php`.

**QA1 data (not code).** On QA1 only, HFC's agency row was set to `classic_welcome` with slogan "WHERE PROFESSIONALISM MEETS REAL ESTATE",
website "www.hfcoastal.co.za", office phone "039 315 0857" (values from Elize's reference page). Live is untouched until Johan orders it.

**Deliberately not done / reported.** `agencies.website_url` still has no settings screen; `viewing_pack_redaction_dpi` and
`viewing_pack_default_duration_minutes` still have no settings UI; the preview frame uses the browser's own sans-serif where the PDF
embeds Inter, so letter widths differ slightly; the agent sheet is unchanged.

## Cover agent follows the buyer's primary agent (2026-10-07, Johan — ruling D)
The buyer-pack cover (name, phone, email, photo) is built from the buyer's
CURRENT primary agent each time the PDF is generated, not the pack's stored
`agent_id` (the agent it was prepared by), which remains the fallback only.
