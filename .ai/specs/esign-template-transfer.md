# E-sign template export / import ("template packages")

> Status: BUILT on QA1 (lane cc2, 2026-10-06). Approved by Johan 2026-10-06 15:11.
> Investigation behind it: `/tmp/qa1-cc6-template-transfer-2026-10-06.md` (build steps T1 export + T2 import; T3 whole document sets is NOT built — see §12).
> Pillars: Documents (templates are the source of every e-sign document). Agency is the outer boundary.
> Related: `esign-v3-complete-spec.md` §5 (ECTA block), `multi-tenancy.md`, `leases.md` §15.12 (lease template guard).

## 1. What it does and why

Documents are set up and tested on one environment (Staging) and must reach another (live) **under a chosen agency**, and the same package must be usable for any further agency (Cape Town, ...). Until now nothing could do that; copying database rows by hand is unsafe because the numbers inside a template (field definitions, field groups, document type, agency, owner) mean something different on each environment.

- **Export** — one self-contained package file (`.cxpkg`) per template: wording, field definitions and positions, signer roles, settings, any embedded files (PDF page images), a format version and a checksum. Identities (agency, users, branches, database ids) are never carried; field definitions, field groups and the document type travel **by name / stable key** and are re-resolved on the target.
- **Import** — owner-level only. Upload → validation → **preview** of exactly what will be created → choose the **target agency** → confirm. Always creates a **new, agency-owned** template. Never overwrites. One transaction; a failed import leaves nothing behind (no row, no page file, no images). Full audit.

## 2. Permission and scoping (BUILD_STANDARD §1c)

- Permission key **`templates.transfer`** ("Export / import e-sign template packages (owner)"), section docuperfect, module templates. Owner-only: routes carry `owner_only` **and** `permission:templates.transfer`; the key is in the `admin` role's `exclude` list so an agency admin never inherits it; the owner role gets it through `'*'`.
- Export is owner-level too (same key). An agency admin sees no Export button and no Import entry.
- **Export scoping:** exports a template only if `Template::assertAccessibleBy($user)` passes; the multi-select re-resolves ids through `Template::visibleTo($user)` so a posted id outside scope is ignored, not trusted. (Owner's data scope is "all".)
- **Import scoping:** the target agency is an explicit, required choice from the agencies list; the created template has `agency_id = target` (never NULL, never "ownerless") and is never visible to a third agency (same `Template::isVisibleToAgency` rules).
- **Transfer log screen:** platform-owner view across agencies by design (owner_only); the staged upload is bound to the uploading user's id and rejected for any other user.

## 3. The package (format version 1)

A zip file, extension `.cxpkg`:

| entry | content |
|---|---|
| `manifest.json` | `format` = `corex-template-package`, `format_version` = 1, `exported_at`, `source_label` (host name of the exporting system — a label only, never used to resolve anything), `template_name`, per-file SHA-256 map, `package_checksum` |
| `template.json` | the template (see below) |
| `files/page-<n>.png` | PDF templates only: the page images |

`package_checksum` = SHA-256 over the sorted `path:sha256` lines of every other entry. It detects corruption and casual tampering; it is **not** a signature (anyone can recompute it), so validation never trusts content because the checksum matches — see §6.

`template.json`:
- `template`: name, render_type, template_type, category, page_count, is_esign, party_mode, header_display, security_tier, allowed_delivery_modes, `document_type` `{slug,label}`, and the content JSON: `signing_parties`, `cds_json`, `editor_state` (`tags`, `mappings`, `tagged_html`), `fields_json`, `field_mappings`, `wizard_config`, `sections`, `insertable_blocks`.
- `named_fields`: package-local key (`nf1`, `nf2` ...) → `{name, field_type, default_options, source_type, source_column, source_contact_type}`. In the content JSON every `namedFieldId` / `named_field_id` is replaced by `"@nfK"`.
- `field_groups`: key (`fg1`...) → `{name, description, layout, sort_order, members:[...]}` with member `named_field_id` → `"@nfK"`. Every `fieldGroupId` / `field_group_id` is replaced by `"@fgK"` and a mapping `typeKey` of `fg:<id>` becomes `fg:@fgK`.
- `signature_zones`: PDF templates only — page, box, type, assigned_parties, label, required, sort_order.
- `removed_identifiers`: JSON paths of any stray agency/user/branch/template ids found inside the content and nulled on export (normally empty). `warnings`: e.g. an image the template uses that is not embedded.
- **Not exported, ever:** `id`, `agency_id`, `owner_id`, `created_by`, branches, `blade_view` (environment path), `compiled_family` / `compiled_serving`, `is_global`, lease/pack links, builder drafts, **anything from sent documents**.

A web template whose wording lives only in a generated page file (no `editor_state.tagged_html` and no `cds_json` sections) **cannot be exported faithfully**; export refuses with a plain-language message ("open it in the builder and save it once"). Importing page-file source is never done (it would execute foreign code on the server).

## 4. Import rules

1. **Validate before anything is written** (§6): format + version, checksum, required parts, shape, safety scan. Failures are logged as `rejected` and shown in plain language.
2. **Preview** (read-only): name, kind, category, document type, signer roles, field definitions that exist / will be created, field groups that exist / will be created, page count and images, whether e-signing will be switched off (ECTA §13(1) alienation rule — computed by the model's own `isEsignBlocked()`, never a copy of the rule), name clash in the chosen agency, warnings.
3. **Target agency** — required; list of all agencies.
4. **Name clash** — if an active or archived template of the same name exists in the target agency the user must choose **new version** (`Name v2`, next free number) or **new copy** (`Name (imported 06 Oct 2026)`) or cancel. Nothing is ever replaced. The old template is left exactly as is; archiving it is a human decision.
5. **Create**, in one DB transaction: new `docuperfect_templates` row (`agency_id` = target, `owner_id` = importer, visibility per setting §8, `archived_at` null, no branches, no compiled_* binding), signature zones, missing named fields (matched by `(source_type, source_column, source_contact_type)`; manual fields by name + type; created only if absent — row ids are never copied), missing field groups in the target agency (matched by name in that agency or a global group of that name), page images under the new id, then the page file is regenerated from the stored data with `WebTemplateBladeEnsurer::regenerate`. Any failure → rollback **and** removal of every file written.
6. `document_type_id` is resolved by slug. Unknown slug: blocking if the slug is one of the ECTA-blocked slugs; otherwise the user must tick "import without a document type" in the preview.
7. A lease agreement link, a web pack or a branch assignment is **never** created implicitly; the agency links the template where it already does (Settings → Rental lease agreements, where the lease guard runs).
8. Soft delete only. Nothing is hard-deleted by this feature.

## 5. Sent documents are unaffected

A sent document freezes its own copy of the content at send (`merged_html`, `canonical_html`, stored `field_mappings`, sealed versions). Export reads the template row and never writes to it; import only ever INSERTs new rows. Proven by a test that records the template and a sent document before/after an export + import and asserts byte-identical rows. "Replace in place" is deliberately not offered — it would be unsafe for documents in flight.

## 6. Security — what a package can never do

A template's HTML becomes a Blade view file on the server, so a package is **code-adjacent input**. Import rejects a package if any string in any content column contains PHP/Blade execution syntax (`<?`, `{{`, `{!!`, `@php`, `@include`, `@extends`, `@component`, `@inject`, `@each`, `@livewire`, ...), script / iframe / `javascript:` / inline event handlers, or if `signing_parties` / party tokens are not simple role words. Zip entries outside the fixed names are refused (no path traversal); per-entry and total uncompressed size are capped (zip bomb). Upload size is capped by a setting.

## 7. Audit and events

New table `template_transfer_log` (insert-only): direction (`export`/`import`), actor user id + name snapshot, outcome (`success`/`rejected`/`failed`), package SHA-256, format version, source label, template name, source agency (export), target agency (import), created template id, warnings JSON, failure reason, timestamp. Failed/rejected imports are written **outside** the rolled-back transaction so they persist. Domain events (non-negotiable #9): `TemplatePackageExported`, `TemplatePackageImported` (agency = target) — fired after commit.

## 8. Settings (defaults, platform level, owner edits them on the import page)

| setting | default | meaning |
|---|---|---|
| `template_transfer.max_package_mb` | 20 | largest package accepted (whole upload) |
| `template_transfer.max_bundle_templates` | 25 | most templates in one multi-select export / bundle import |
| `template_transfer.default_visibility` | `all_branches` | `all_branches` = every branch of the target agency can use the imported template (`is_global` = agency-internal "all branches"); `agency_admins_only` = hidden from agents until the agency assigns branches |
| `template_transfer.version_suffix` | `v{n}` | name pattern for "new version" |
| `template_transfer.copy_suffix` | `(imported {date})` | name pattern for "new copy" |
| `template_transfer.staged_upload_hours` | 24 | how long an uploaded-but-unconfirmed package is kept before it is deleted |

These are platform-owner tool settings, not agency settings; they are **deliberately not in the Agency Onboarding Setup Wizard** (non-negotiable #10a — no agency configures them; recorded here per the rule; Johan's call to change).

## 9. Screens, navigation, list standard

- **Template Management list** (existing, agency-scoped by `visibleTo`): per-card/row **Export package** (owner only), checkbox per template + "Export selected" bar. Search/sort/filter/pagination are the list's existing ones.
- **Template edit screens** (PDF and web): "Export package" button (owner only).
- **Template Packages** page (`/docuperfect/template-transfer`, sidebar entry "Template Packages" under DocuPerfect, owner only): upload box, settings panel, transfer log list. Log list: **search** = template name, actor name, checksum; **sort** = date (default newest first), template name, direction, outcome; **filter** = direction, outcome, date range; pagination 20; empty state ("No transfers yet" vs "No transfers match these filters"). Staged-but-unconfirmed uploads can be cancelled (delete) from the preview.
- Routes: `docuperfect.template-transfer.index|upload|preview|confirm|cancel|settings`, `docuperfect.templates.export`, `docuperfect.templates.exportSelected`. Registered in the web route table (these are server-rendered pages / downloads, not JSON API endpoints).

## 10. Input space (BUILD_STANDARD §2–§4)

No file / wrong extension / empty file / not a zip / zip with missing parts / extra parts / oversize / checksum mismatch / future or old format version / malformed JSON / required name empty / unknown render type / PDF with missing images / unsafe content / unknown agency id posted / name clash with no choice made / stale or foreign staging token / double confirm (second confirm finds the staged file gone and creates nothing) — each is either prevented in the form or answered with a plain message. No raw exception reaches the user.

## 11. Acceptance criteria

Tests (`tests/Feature/Docuperfect/TemplateTransfer/`): export → import round trip gives an identical template in another agency (content columns equal, field and group ids remapped to the same field definitions); a package carries no ids / agency / users; tamper and checksum failure; wrong / newer format version; missing parts; unsafe content refused; permission denied for a non-owner (route + sidebar); name-clash paths (new version, new copy, no choice → refused, cancel); transaction rollback leaves no row / file / image; sent documents byte-identical; imported template invisible to a third agency and never ownerless; second import into a second agency from the same package; PDF template images round trip.

## 12. Not built (follow-ups, reported)

- **T3 — whole document sets (web packs)**: not a thin layer on this — a pack is a set of templates plus slot rows plus a pack-level `agency_id`/visibility model and its own list/CRUD rules; it needs its own spec and the package format gains a second type. Reported as a follow-up.
- Artisan `templates:export` / `templates:import --dry-run` (CLI): not asked for; the service layer is built so a command is a thin wrapper.
- Local images referenced by path inside a template are warned about, not embedded.
