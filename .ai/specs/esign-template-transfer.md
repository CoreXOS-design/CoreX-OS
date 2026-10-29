# E-sign template export / import ("template packages")

> Status: BUILT on QA1 (lane cc2, 2026-10-06). Approved by Johan 2026-10-06 15:11.
> Investigation behind it: `/tmp/qa1-cc6-template-transfer-2026-10-06.md` (build steps T1 export + T2 import; T3 whole document sets is NOT built — see §12).
> Pillars: Documents (templates are the source of every e-sign document). Agency is the outer boundary.
> Related: `esign-v3-complete-spec.md` §5 (e-sign guard), `multi-tenancy.md`, `leases.md` §15.12 (lease template guard).

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
2. **Preview** (read-only): name, kind, category, document type, signer roles, field definitions that exist / will be created, field groups that exist / will be created, page count and images, whether e-signing will be switched off (a template of a document type flagged `esign_warning_required` always arrives on wet ink — an acknowledgement belongs to the admin who gives it in template setup, so a package cannot carry one; computed from the flag, never a copy of a rule), name clash in the chosen agency, warnings.
3. **Target agency** — required; list of all agencies.
4. **Name clash** — if an active or archived template of the same name exists in the target agency the user must choose **new version** (`Name v2`, next free number) or **new copy** (`Name (imported 06 Oct 2026)`) or cancel. Nothing is ever replaced. The old template is left exactly as is; archiving it is a human decision.
5. **Create**, in one DB transaction: new `docuperfect_templates` row (`agency_id` = target, `owner_id` = importer, visibility per setting §8, `archived_at` null, no branches, no compiled_* binding), signature zones, missing named fields (matched by `(source_type, source_column, source_contact_type)`; manual fields by name + type; created only if absent — row ids are never copied), missing field groups in the target agency (matched by name in that agency or a global group of that name), page images under the new id, then the page file is regenerated from the stored data with `WebTemplateBladeEnsurer::regenerate`. Any failure → rollback **and** removal of every file written.
6. `document_type_id` is resolved by slug. Unknown slug: blocking if the slug is one of the flagged sale-document slugs (the warning attaches through the document type); otherwise the user must tick "import without a document type" in the preview.
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

## 13. Releasing a finished template to an agency — states, who approves, release log (28 Oct 2026, e-sign document workshop)

> Status: SPEC, written 28 Oct 2026 before code (builds 2, 4, 5 and 7 of the workshop). Johan's rulings (26–28 Oct, via the conductor): **R1** a released template is NOT live at the agency until the agency approves it; **R2** who may approve is NOT always an administrator — a new agency setting "Who may authorise documents" (users and/or roles, built like the other CoreX authoriser settings) and only those people can approve and make a released template live; **R3** a release log; a re-release lands as a NEW version awaiting approval and documents in flight are untouched.
> Companion specs: `esign-template-readiness.md` (the check), `esign-template-demo-preview.md` (the look). The engine promotion to Staging/live and a named "workshop agency" are out of scope (conductor / not asked).

### 13.1 The path of a document

`workshop` → `verified` → `released (awaiting approval)` → `live (approved)`; side exits `declined`, `withdrawn`, `superseded`.

1. **Workshop** — the template as CoreX builds it (any template the owner is working on; no flag is needed to be "in the workshop"). Nothing about it is special until it is verified.
2. **Verified** — the owner presses *Mark as verified* on the Releases screen. Allowed only when the readiness report has **no `fail`** and the owner ticks "I have looked at the demo view and the document". Stored on the template: `workshop_verified_at`, `workshop_verified_by`, `workshop_verified_sha` (SHA-256 of the template's content columns: `cds_json`, `editor_state`, `field_mappings`, `signing_parties`, `header_display`, `fields_json`). **Any later change to the template content makes the stored hash stale and the template shows "verification out of date" — it can no longer be released until verified again.** (Prevent, not absorb: the release button is disabled with the reason, and the service refuses.)
3. **Released** — the owner chooses the target agency and presses *Release to agency*. The service runs the existing package pipeline in memory (`TemplateAgencyCopier` → exporter → importer: a NEW template owned by the agency, never an overwrite, name clash → "v2"), then in the same transaction marks the copy `release_status = 'awaiting_approval'`, writes the release row and the log event, and tells the agency's authorisers (§13.4). The copy is **not live**: it is invisible to agents, to the e-sign template pickers, the lease agreement picker, the wizard and the lease signing launcher (§13.6). Same-agency release is refused ("choose a client agency").
4. **Approved / live** — a person named in the agency's "Who may authorise documents" opens the awaiting template, may open the demo and the readiness report, ticks "I have looked at the document and the demo", and presses *Approve*. The copy becomes `release_status = 'live'`, the release row `live`. If the agency had an earlier live version of the same document (same source template, same agency), that version becomes `superseded`: hidden from new-document pickers, **never archived or edited**, and every document already sent keeps its own frozen copy (§5). If the package carried a lease link (§13.8) it is applied now.
5. **Declined** — the authoriser presses *Decline* and must give a reason (3 characters minimum after trimming, 1000 maximum). The copy stays hidden (`release_status = 'declined'`), the owner sees the reason on the Releases screen. To try again the owner fixes the template in the workshop, verifies again and releases again: that is a **new version** (v2, v3 …) and a new row; the declined row is kept.
6. **Withdrawn** — before any decision the owner may withdraw (the archive path of a release): the copy is archived (`archived_at`), the release row `withdrawn`. *Release again* creates the next version. (Restore of a withdrawn release = release again; the old copy stays archived and can be restored by the existing template restore.)

`release_status` null means an ordinary template exactly as before this feature (HFC's and every existing template are unaffected: no backfill, nothing changes for them). `live` behaves exactly like null. Only `awaiting_approval`, `declined`, and `superseded` hide a template from use.

**Every route a template reaches an agency by goes through this gate (Johan, 28 Oct 2026: "an agency must approve a template before it is live").** The owner tools **Copy to another agency** (button on the template builder) and **Import package** (Template Packages screen, and `templates:import`) no longer create a live template: each lands exactly like a release — a NEW agency-owned copy with `release_status = 'awaiting_approval'`, a release row, a `released` log entry (`via` = `copy` or `package`), the authorisers told — and goes live only when a person the agency named approves it. **There is no owner override**: the CoreX owner can verify, release, copy and import, never approve (§13.3), so a template cannot reach an agency live by any route. The differences between the routes are only what is checked beforehand: *Release* (workshop) requires a verified, current template with no failing readiness check; *Copy* and *Import* record the readiness result on the release row without blocking (the approval re-runs it, and a copy with a failing check cannot be approved). A copy carries the rental lease link and field map only when the owner ticks "Also copy the rental lease link and field map"; it is captured on the release row and applied at approval (§13.8), never at copy time (a link pointing at a copy nobody may use would break the agency's lease signing). Import screen with several packages: every package is checked first (blocked / needs the missing-document-type tick / name clash with no choice) and one problem refuses the whole bundle before anything is created; a name clash is "new version" or "new copy", nothing is ever replaced. Screens say so in plain words ("Send for approval", "NOT live until the agency approves it").

### 13.2 Data (migrations `2026_10_28_1000nn_…`, idempotent; then `scripts/schema-dump.sh` once, DEFINER strip)

- `docuperfect_templates` + `release_status` varchar(20) null (index), `workshop_verified_at` timestamp null, `workshop_verified_by` bigint unsigned null, `workshop_verified_sha` char(64) null.
- `agencies` + `document_authoriser_user_ids` json null, `document_authoriser_roles` json null (same shape as `rental_application_ro_user_ids`).
- `docuperfect_template_releases` — `id`, `source_template_id`, `template_id` (the agency copy), `agency_id` (target; `BelongsToAgency`), `version` unsigned int (1,2,3 per source template + agency), `state` varchar(20) (`awaiting_approval`, `live`, `declined`, `withdrawn`, `superseded`), `readiness_verdict` varchar(24), `readiness_snapshot` json, `source_content_sha` char(64), `package_sha256` char(64) null, `lease_link` json null, `released_by` bigint, `released_at`, `decided_by` bigint null, `decided_at` null, `decision_note` text null, `previous_release_id` bigint null, `created_at`, `updated_at`, `deleted_at`. No hard delete anywhere.
- `docuperfect_template_release_events` — insert-only log: `id`, `release_id` null, `template_id` null, `agency_id` null, `on_behalf_of_user_id` null (assistant audit, AT-267), `event` varchar(30) (`verified`, `verification_cleared`, `released`, `approved`, `declined`, `withdrawn`, `superseded`, `lease_linked`, `authorisers_changed`), `actor_user_id`, `actor_name` (snapshot), `note` text null, `detail` json null, `created_at`. This is the release log (R3). Domain events (non-negotiable #9, fired after commit): `TemplateReleased`, `TemplateReleaseApproved`, `TemplateReleaseDeclined` (agency = target) — added to `corex-domain-events-spec.md`'s catalogue in this build.

### 13.3 The setting: "Who may authorise documents" (R2)

- Agency setting, two lists: **people** (ticked agency users) and **roles** (ticked roles of that agency). A person may authorise when they are in the people list OR hold a ticked role. Saved by a narrow saver (`DocumentAuthoriserSettingsController::update`, `has()`-guarded per list, so a wizard step that posts only one list never wipes the other — `agency-onboarding-setup.md` §6.1), audited (`authorisers_changed` event with before/after), every posted user id re-resolved inside the agency (a tampered id from another agency is refused with 422, as `resolveAgencyScopedUserIds` does for the rental authorisers).
- Where: **Documents → E-Sign Settings → "Who may authorise documents"** (a tab of the E-Sign Settings hub, permission `esign.settings` like every other e-sign setting; built there, not on the Company Settings page, to reuse the hub pattern) **and** the Setup Wizard, Compliance step (non-negotiable 10a) — control + `explain` + `affects` in `config/agency-onboarding-copy.php`, saver registered in the step's `savers`.
- **Default when nothing is ticked:** until the agency picks, the agency's administrators (role `admin`) may authorise, so a new agency cannot lock itself out; the screen says so ("No one is chosen yet, so for now your administrators can approve. Choose who here."). *Business consequence for Johan:* a brand-new agency's admin can approve until it names its authorisers.
- A ticked person whose role lacks the permission key `templates.approve_release` is flagged on the settings card ("their role cannot open the approval screen yet — grant it in Role Manager").
- The CoreX owner (system owner) **cannot approve for an agency** (approval is the agency's act): the owner sees the Approvals screen read-only with no Approve / Decline buttons.

### 13.4 Screens and navigation (Documents → Templates hub, two new tabs; `documents-menu-tabs.md` table updated in this build)

Hub tabs added to `DocumentTemplatesTabs`: **Releases** (owner only; permission `templates.release`) and **Approvals** (agency authorisers; permission `templates.approve_release`; shown to the owner read-only). Shell = existing hub shell: left menu + top header fixed; no page scrolling on a tab (lists scroll inside their own pane; the page does not).

**Releases tab (owner)** — two views on one page (`?view=releases|log`):
- *Releases list:* columns template (name + version), agency, state, readiness verdict, released by / when, decided by / when. **Search:** template name, agency name, released-by name, decision note. **Sort:** released date (default, newest first), template name, agency, state, version. **Filter:** state, agency, released date range (from–to). **Pagination:** 20. **Empty states:** "Nothing has been released yet" vs "No releases match these filters". Row actions: View (detail + timeline), Demo view, Readiness, Withdraw (only `awaiting_approval`; confirm dialog, in-page, never `confirm()`), Release again (when declined / withdrawn / superseded).
- *Release log:* every event, newest first. **Search:** template name, agency name, actor name, note. **Sort:** date (default newest first), event, agency. **Filter:** event type, agency, date range. Pagination 20; empty states as above.
- Above the lists: "Workshop" panel — owner picks a template (search by name, any agency), sees its readiness verdict, verified / out-of-date state, and the buttons *Check readiness*, *Demo view*, *Mark as verified* (with the "I have looked" tick), *Release to agency* (agency select + confirm).
- Release detail `GET /docuperfect/template-releases/{id}`: state, version, who/when, readiness snapshot (the checks as they were at release), decision note, previous version link, timeline of events.

**Approvals tab (agency)** — one list of the agency's releases (own agency only by the query scope). **Search:** template name, note. **Sort:** released date (default newest first), template name, state. **Filter:** state (default *Waiting for approval*), date range. Pagination 20. Empty states: "Nothing is waiting for approval" vs "No documents match". Each waiting row: *Open demo*, *Readiness*, *Approve*, *Decline* (reason required). A badge with the waiting count sits on the tab. Each decided row shows who decided and when.

Authorisers are told: a domain event `TemplateReleased` with a listener that mails each person who may authorise (the agency's normal outbound mail path; inert on QA like all mail) and the count badge. No push, no WhatsApp.

### 13.4b The agency's side of the workshop (29 Oct 2026, Johan: "the agency sees it in their workshop with demo data")

The agency's screen is the **Approvals** tab / "Approve documents" menu item (§13.4, §13.4a). For an agency user the page is headed **"Documents from CoreX"** (the owner still sees "Approvals", read-only) and carries: the road in three plain lines (CoreX builds and checks it / you look at it with sample data, exactly as your clients will see it / someone you have chosen approves it and it is live for your agents); one chip per state with its count (waiting, live, declined, withdrawn, replaced; each a link to the list filtered to that state); a link to **Who may authorise documents** (shown only to someone holding `esign.settings`); and per row a **Demo view** button (waiting and live documents) next to Review, so the agency sees the document filled with the sample data of its own kind of document (demo-preview §3a) before it approves. Per side: CoreX's Document workshop is where documents are prepared and released; the agency never gets Verify or Release, only Demo view, Approve and Decline. No new route, key or table. The authoriser setting's changes are already in the release log (`authorisers_changed`).

### 13.4a Menu entries: "Document workshop" (owner) and "Approve documents" (agency) — 28 Oct 2026, Johan: "cannot even find the menu"

Two Documents-menu items, each its own link (not a hub tab), shown only to the people who can open them:

- **Document workshop** — CoreX owner who holds `templates.release` (same check as its route: `owner_only` + `permission:templates.release`). Route `GET /docuperfect/workshop` (`docuperfect.workshop.index`), `DocumentWorkshopController`. One page, fixed shell (no page scroll; the list scrolls inside its pane). Contents: (1) the road in three plain lines — build, **Check readiness** and **Demo view** → tick "I have looked" and **Verify** → **Release** to an agency, which is not live until that agency's named authorisers approve; (2) links to Releases, Approvals (with the number waiting across agencies), Release log and Templates; (3) the list of templates.
- **Approve documents** — an agency user who holds `templates.approve_release` AND is one of the agency's named authorisers (admins while none is named), exactly the people who get the Approvals tab; the CoreX owner does not get it (the owner reads Approvals through the hub tab, read-only, and the workshop link). Opens the existing Approvals screen (`docuperfect.template-approvals.index`, default filter *Waiting for approval*); the menu item carries the same waiting count the tab does.

**The workshop list.** One row per ORIGINAL template of any agency that is not archived: copies that exist because of a release, copy or import carry a `release_status` and are followed on Releases instead. Columns: *Document* (name, last changed) · *Belongs to* (agency) · *Where it is* · *Readiness* · *Last release* · *Next step*.
- *Where it is*, in priority order: **Live at an agency** (any release `live`) → **Waiting for approval** (any release `awaiting_approval`) → **Verified** → **Changed since verified** (stored hash no longer matches) → **Being prepared**; the verified date and the live / waiting counts are shown under it.
- *Readiness*: the read-only report's verdict (Ready / Ready, with warnings / Fix first) and the fail / warning counts (`TemplateReadinessReport::forTemplate`, nothing is stored).
- *Last release*: version, agency, state and date of the newest release row of that template, linking to the release detail; "Never released" otherwise.
- *Next step* (actions per row): **Check readiness** and **Demo view** always; **Verify** (with the "I have looked" tick; disabled with the reason when the readiness is "Fix first") while the template is not verified or has changed; **Release** (agency picker, never the template's own agency) once it is verified and current. Verify and Release post to the existing routes, which re-check authority, the tick, and the readiness rules; a refusal comes back as a plain message on this page.
- **Search:** document name, agency name. **Sort:** document name (default A–Z), agency, verified date, last release date. **Filter:** where it is (not verified yet / verified / waiting for approval / live at an agency), agency. **Pagination:** 15 (each row runs the readiness report). **Empty states:** "There are no templates to prepare yet…" vs "No documents match these filters."
- Scoping: owner only (a user without the owner role or the key gets 403; the menu item is not rendered for them). Read-only listing — no new write path, no new setting, no new permission key. The Releases screen's earlier "Workshop" search view now redirects here.

### 13.5 Permissions (Role Manager) and scoping (BUILD_STANDARD §1c)

New keys in `config/corex-permissions.php`, section `docuperfect`, module `templates`: **`templates.release`** — "Verify and release e-sign templates to agencies (owner)" — owner-only: in the `admin` role's `exclude` list like `templates.transfer`; **`templates.approve_release`** — "Approve documents released to your agency (only people named in Company Settings → Who may authorise documents)". Routes: Releases routes carry `owner_only` + `permission:templates.release`; Approvals routes carry `permission:templates.approve_release`; the **controller and the service re-check authority** (named in the setting / admin fallback / owner read-only) — a role grant alone never approves.
Scoping: `docuperfect_template_releases` uses `BelongsToAgency` (agency = target); the owner's data scope is all agencies, an agency user's is their agency only, **at the query layer**; direct URL by id for another agency's release is a 404 (not a hidden link). Own/branch levels: a document release is agency-wide, so own and branch scoping do not apply (stated; nothing in a release is personal or branch-owned). Export / download: none added. API: none added (server-rendered pages, like Template Packages).

### 13.6 "Not live" — enforced at the source, not in the UI

`Template::scopeActive` and `Template::scopeVisibleTo` exclude `release_status IN ('awaiting_approval','declined','superseded')`; `Template::isUsableForSigning()` is false for them; `LeaseAgreementTemplateGuard::refusalsFor` returns a plain refusal for them; every place a flow, pack, lease document or wizard is started from a template id re-checks `isUsableForSigning()` (the sibling-occurrence sweep is part of the build and listed in the build notes). The agency's authorisers and the owner reach an awaiting template only through the release screens (demo, readiness), never through the template pickers.

### 13.7 CRUD, lists, input space

Release = create (Release), read (list, detail, log), update (the decision: approve / decline / note), archive (Withdraw), restore (Release again — a new version). Releases and the log are history and are never deleted. Inputs and their handling: no verified state / stale hash → refused with the reason; target agency missing / same agency / archived agency → refused; template archived → refused; double Release of the same verified content to the same agency while one is already awaiting → refused ("already waiting for approval", prevent); double Approve (second click) → the second finds the state already `live` and does nothing (idempotent); Decline with empty / whitespace reason → 422 with a plain message; Approve by someone not named → 403; Approve of a released template whose readiness now has a `fail` → refused (recomputed at approval; a fail can appear if a named field was deleted meanwhile); Release when the package safety scan refuses → shows the plain refusal, nothing created (transaction rolls back and removes files, as §4); version numbering race → the unique key (`source_template_id`, `agency_id`, `version`) plus a transaction; soft-deleted rows included in the version count (create → soft-delete → recreate rule, BUILD_STANDARD §5a).

### 13.8 The lease link and field map travel with the release and the package (build 5)

**Built in two steps.** At *release* the service captures the source template's active lease-agreement link as plain data on the release row (`docuperfect_template_releases.lease_link`: category, name, default flag, field map) and applies it at approval (built, milestone 2). The package (`.cxpkg`) carrying the same optional section for Import / Copy is milestone 4 (below).

The exporter adds an optional `lease_link` to `template.json` when the source template is linked in Rentals → Lease agreements: `{category, name, is_default, field_map}` where the field map is exactly the link's map (keys are the agreement's field names, which travel with the wording). Format version stays 1 (additive optional section; an importer that does not know it ignores it; the importer validates its shape when present). The importer validates its shape (known category, a name, a field map of at most 200 plain names, the same safety scan as every other part) and returns it in the preview (`lease_link: {category, name, mappings}`) and **creates nothing from it on its own** (§4.7 stands for Import / Copy). On **release approval** (and only then) the link is applied: if the agency has no active link in that category, one is created from the package (`RentalLeaseTemplate`, map validated by `LeaseAgreementTemplateGuard`); if the agency's current link points at the previous live version of the same document, it is repointed to the new version with the same map; otherwise the agency's own link is left alone and the approval screen says "your agency already uses another lease agreement; link this one in Settings → Rental lease agreements if you want it". A guard problem blocks only the link, not the approval, and is stated. Logged as `lease_linked`.

### 13.9 Command line (build 7) — AS BUILT

`php artisan templates:export {template_id} [--out=DIR] [--audit --actor=ID]` writes the `.cxpkg` (no audit row unless `--audit`). `php artisan templates:import {file} --agency=ID [--actor=OWNER_ID] [--dry-run] [--release] [--allow-no-document-type]`:
- **`--dry-run` writes nothing** (no template, release, log row, lease link or page file) and prints exactly what an import would do: the package, signers, pages, document type (found / missing), field definitions already here vs would be created, field groups, whether e-signing would switch off, the **lease link carried**, whether the agency exists on THIS system and any name clash ("would become … v2"), warnings, blockers. A tampered or damaged package, a missing file, an unknown agency are refused in plain words with exit code 1 (never a stack trace).
- Without `--dry-run` it needs `--agency` and `--actor` = a CoreX owner. It **always** calls `TemplateReleaseService::releaseFromPackage` (there is no live import; `--release` is still accepted so older instructions work, and changes nothing): a NEW agency copy lands `awaiting_approval`, a release row (with the package's lease link and its SHA-256) and the log entry `released` (`via: package`), the authorisers are told. That is the way a document moves from QA1 to live: export on QA1, `--dry-run` on live to rehearse, `templates:import` on live, the agency approves. A later package with the same document name in the same agency is the **next version of that lineage** (it replaces the older live copy when approved).
- The package path is not "verified" here (it was verified where it was made): the readiness check is recorded on the release row and re-run at approval, where a failing copy cannot be approved.
This build never runs it against Staging or live.

### 13.10 Settings, wizard, feature switch

The authoriser setting is an agency setting → Setup Wizard (13.3). The release/verify/readiness/demo tools are CoreX-owner tools → not in the wizard (decision on the record). The two hub tabs ride the existing `docuperfect` feature; no new sidebar item, so no `config/corex-features.php` row.

### 13.11 Acceptance criteria

1. Verified is required, content change makes it stale and blocks release. 2. Release creates an agency-owned copy that **no agent, picker, lease launcher or wizard can use** until approved (tests for each consumer). 3. Only people named in the setting (or the admin fallback when empty) can approve; a role grant alone, the owner, another agency's user and a named user of another agency cannot. 4. Approve makes it usable, supersedes the previous live version without touching documents in flight (a flow created from v1 before approval still has v1's frozen content and still completes). 5. Decline needs a reason and keeps it hidden. 6. Withdraw archives; Release again makes v2 awaiting approval. 7. The log shows every step with actor and time; both lists search, sort, filter, paginate and show empty states. 8. The package carries and (on approval) applies the lease link; `templates:import --dry-run` writes nothing. 9. Setup Wizard shows the authoriser control and its saver does not wipe the other list. 10. Mega 82 and 83 go through the whole path on QA1 into a test agency.

### 13.12 Tests

`tests/Feature/Docuperfect/Workshop/TemplateVerificationTest.php`, `TemplateReleaseServiceTest.php` (release, approve, decline, withdraw, release-again versions, supersede, stale hash, same agency, double submit, soft-delete-recreate version), `TemplateNotLiveEverywhereTest.php` (each consumer refuses an awaiting copy), `DocumentAuthoriserSettingTest.php` (saver, subset-post, foreign-agency id refused, admin fallback, owner cannot approve, permission floor), `TemplateReleaseScreensTest.php` (both tabs: search, sort, filter, pagination, empty states, 404 other agency, 403, Approve buttons absent for owner), `TemplateLeaseLinkPackageTest.php`, `TemplateTransferCommandsTest.php`. Paths named in the report: all-fields, each-optional-empty, shortcut (verify → release → approve with defaults), malformed (empty / long reason, foreign user id), deleted-related (archived source, deleted agency copy), idempotency (double approve / double release).

### 13.13 Files

Migrations (3 + schema snapshot), `app/Models/Docuperfect/TemplateRelease.php`, `TemplateReleaseEvent.php`, `app/Services/Docuperfect/Release/{TemplateReleaseService (verify + release + approve + decline + withdraw),AgencyDocumentAuthorisers,TemplateReleaseException}.php`, `app/Http/Controllers/Docuperfect/{TemplateReleaseController,TemplateApprovalController,DocumentAuthoriserSettingsController}.php`, `app/Listeners/Docuperfect/NotifyDocumentAuthorisers.php`, events, `app/Support/Navigation/Hubs/DocumentTemplatesTabs.php`, views under `resources/views/docuperfect/releases/`, `corex/settings` card + wizard config, `config/corex-permissions.php`, `Template.php` scopes, `LeaseAgreementTemplateGuard.php`, exporter/importer lease link, two commands, specs (`documents-menu-tabs.md`, `corex-domain-events-spec.md`, `agency-onboarding-setup.md` §5/§6), routes, tests above.
