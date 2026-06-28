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
