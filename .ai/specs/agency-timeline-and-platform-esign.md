# Agency Timeline + Platform E-Sign — Spec

> Status: **Built on QA2 (AT-447), awaiting Johan's audit/test before Staging.** Rewritten 2026-10-05 to match
> what was actually built (the first draft described a separate contracts module and, later, a dedicated
> platform agency — both were rejected by Johan and removed).
> Jira: AT-447 · Branch: `AT-447-agency-timeline-platform-contracts`
> Area: **System Developer** (owner-only). Nothing here is visible to any agency except the read-only,
> link-gated public timeline page.
> Pillars touched: **Agent** (the agency's principal signs; the agency ticks its own onboarding steps).
> Sister specs: `agency-onboarding-setup.md` (the setup wizard — a different thing), `agency-billing.md`,
> `ESIGN-CANON.md`, `corex-domain-events-spec.md` §5.

## 1. Business requirement (Johan, 2026-10-05, summarised)

1. A **Platform E-Sign** area in the dev side of CoreX, outside every agency, where CoreX keeps and sends its
   own contract (Subscription Agreement, debit-order form) — **the same e-sign agencies use, including the
   template creator, not a lookalike**.
2. An **Agency Timeline** per agency: the onboarding plan from take-on to go-live, with a **public link**
   that can be shared throughout the agency. Defaults are **editable in Dev Settings**. Starting a timeline
   offers dates counted from a start date (which can never be in the past) and every step date is
   customisable. Custom steps per agency. Overdue steps show as overdue and **push the go-live date out**.
3. The agency can **tick its own steps as completed** on the public page so the plan progresses.
4. The public page should be wide, look good, carry the agency name in its link, and have an interactive
   timeline at the top.

## 2. What this is NOT
* (Reversed 2026-10-05 — see §3A: Platform E-Sign IS now a separate copy of the e-sign, cut down to contracts.)
* Not the Agency Setup Wizard (`agency-onboarding-setup.md`); finishing the wizard merely ticks a step.
* No agency can see Platform E-Sign content, ever (§3.3).

## 3. Part A — SUPERSEDED by §3A (kept for history: the agency-less mode of the real e-sign, removed 2026-10-05)

### 3.1 Behaviour
* **Sidebar → System Developer → "Platform E-Sign"** (`admin.platform-esign.enter`, owner-only, no permission
  key by design) switches the owner into *Platform E-Sign mode* and lands on a hub
  (`docuperfect.platform.hub`, `/docuperfect/platform`): Send a contract · Contract templates · Create a
  template from a document (import) · Sent contracts · Recipient presets. An amber banner with the same
  shortcuts and **Exit** is shown on every e-sign page while in the mode.
* In the mode everything is the normal DocuPerfect: template creator/import/builder, send wizard, signing
  screens, sealed PDF, audit. Public signer links (`sign/{token}`) are token-based and untouched.
* A demo contract can be seeded/archived: `php artisan platform-esign:demo [--remove]` (QA use; soft delete).

### 3.2 How the mode works (`App\Support\PlatformEsignMode`)
Active only when **all** hold: owner-role user · session flag set from Dev · request path `docuperfect` or
`docuperfect/*`. Effects, limited to models in `App\Models\Docuperfect\*`:
* `AgencyScope` constrains reads to `agency_id IS NULL`; `BelongsToAgency::creating` stamps `agency_id = NULL`.
* `Template` (which has no BelongsToAgency and where NULL means "shared with every agency") carries
  **`is_platform`**: `PlatformTemplateScope` hides platform templates from every authenticated non-mode
  query and shows only them in the mode; a `saving` hook forces `agency_id NULL`, `is_global false`.
  `TemplateController::cdsGenerate` no longer reassigns a platform template to an agency nor demands one.
* An owner *outside* the mode (cross-agency view) does not see agency-less `Document`/`SignatureTemplate`.
* Branding falls back to "CoreX OS"; the audit certificate names CoreX for agency-less documents.

### 3.3 Isolation guarantee (tested — `PlatformEsignIsolationTest`)
Six actor types (agency admin, agent, another agency's admin, admin with the flag forced on, owner switched
into an agency, owner outside the mode) cannot see the platform contract in 12 e-sign list pages, by direct
id, by starting a flow, or by model query. Only an owner inside the mode can.

### 3.4 Known limits (deliberate, reported to Johan)
* The e-sign's party roles are property roles (Seller/Buyer/Landlord/Tenant/Agent); a CoreX contract uses
  Seller = agency principal, Agent = CoreX. Custom platform role names would require changing the shared e-sign.
* Every signer needs an ID/passport number (the e-sign's own legal rule).
* The wizard's contact/property search still searches all agencies for an owner.

## 3A. Part A v2 — Platform E-Sign as its OWN e-sign (APPROVED by Johan and BUILT 2026-10-05; supersedes §3)

> Johan, 2026-10-05: "make a copy of esign so that it becomes 2 different ones — Platform E-Sign won't use
> properties and stuff, it is used to send out the CoreX contract." Decisions taken the same day: **contracts-only
> copy** (not a full clone) and **replace** the §3 agency-less mode, archiving the contract already created in it.
> This reverses the §2 line "not a copy of the e-sign code" — the shared-engine/mode approach is retired.

### 3A.1 What it is
A separate module (own routes `platform-esign/*`, own tables `platform_esign_*`, own models/services/views under
`App\\*\\PlatformEsign`), copied from DocuPerfect e-sign and cut down to what a CoreX contract needs. It owns
its data outright — no `agency_id`, no scopes, no session "mode". Nothing in it reads or writes properties,
listings, deals or the contact book; DocuPerfect is not touched by it and cannot see it.

### 3A.1a Page layout (restyled 2026-10-06, Johan chose the "Module sidebar" option)
Every Platform E-Sign page shares `platform-esign/_header.blade.php`: the banner (title, sub line, page actions)
followed by a left sidebar that stays in view while the page scrolls. The sidebar replaces the old tab strip and
lists the module in three groups: **Contracts** (Overview, Documents, Templates), **Send** (Subscription
Agreement, Another contract), **Setup** (Agreement wording, Agency Timeline). The current page is marked from the
`$tab` each page passes. The header opens the sidebar and the content column; each page closes them with
`@include('platform-esign._end')` as the last line inside its wrapper. Overview shows four count tiles, then
"Waiting on a signature" (status pill and signing progress per contract; contracts waiting on RR are marked) and
"Recently signed". Below 1024px the sidebar becomes a scrolling row above the content. No route, permission, field
or data query changed.

### 3A.2 Carried over from e-sign (copied, then simplified)
Template builder (upload a PDF/Word -> place signature, initial, date and text fields; or web template),
named signers with order, send by email, signer page (typed/drawn signature, consent, ID/passport), reminders,
decline, sealed PDF with SHA-256, audit certificate + event log, resend/void, signed copy download & email,
status tracking. Same look and feel as e-sign.

### 3A.3 Deliberately removed
Property / listing / deal / contact pickers; Seller-Buyer-Landlord-Tenant-Agent roles (signer roles are free
text: e.g. "Agency Principal", "CoreX"); packs, amendments, wet-ink, conditions, rental/sales flows, branch/agency
branding, agent-facing dashboards. Merge fields come from the agency record (name, reg no, VAT, address,
signatory, go-live/billing dates) as in the retired contracts module.

### 3A.4 Timeline link
`agency_timelines.agreement_template_id` re-points to a `platform_esign_documents` row; `syncAgreement()` ticks
"sign agreement" when it is completed. Start-contract is offered from the agency timeline detail page.

### 3A.5 Migration of the old mode
Archive (soft delete) the agency-less `docuperfect_*` contract(s); remove `PlatformEsignMode`, `is_platform`
scope and the DocuPerfect banner/hub; sidebar entry opens the new module. Rollback = restore the archived rows.

### 3A.6 Build phases (each lands, is verified and reported before the next)
1. Data + models + merge fields + audit/events + sealed-PDF service.
2. Template builder (upload, field placement, web templates) + hub + lists with search/sort/filter.
3. Send flow (agency picker, signer, merge preview) + signer public page + reminders/decline.
4. Timeline link, archive of the old mode, removal of mode code, isolation + signing tests, demo command.


## 4. Part B — Agency Timeline

### 4.1 Navigation
Sidebar → System Developer → Agency → **Agency Timeline** (`admin.agency-timelines.*`).
Dev Settings → **Agency onboarding → Agency timeline defaults** (`admin.timeline-defaults.*`).
After creating an agency, the success message links to *Start agency timeline*.

### 4.2 Data (migrations `2026_10_05_100000`, `120000`, `130000`, `140000`)
* `agency_timeline_default_items` — editable template: `kind` (block|milestone), title, body, `offset_days`,
  `is_public`, `agency_can_complete`, `is_go_live` (exactly one), `auto_complete_trigger`
  (`contract_signed` | `setup_wizard_completed`), sort, soft delete.
* `agency_timelines` — one per agency (enforced in code): `token` (48 chars), `start_date`, status
  (running|live|paused), `public_link_enabled`, `agreement_template_id` (linked Platform E-Sign document), soft delete.
* `agency_timeline_items` — a **snapshot** of the defaults at start (defaults edited later never change a
  running timeline) + custom items; `due_date`, `status` (pending|done|skipped), `completed_source`
  (`manual`|`agency`|`contract_signed`|`setup_wizard_completed`), `agency_can_complete`, soft delete.
* `agency_timeline_events` — full history of every change (who / what / before / after / source).
* `agencies` is **not** changed. `docuperfect_templates.is_platform` added; `docuperfect_documents.agency_id`
  made nullable (FK kept).

### 4.3 Owner screens (all owner-only; every action also `abort_unless(isOwnerRole())`)
* **List** — KPIs; search (agency name); filters: status (incl. Archived), started-from / started-to;
  sort: agency, overdue, start, go-live (default agency A→Z); pagination 25; empty states; Start / Open / Copy link.
* **Start** — start date defaults to today and can never be in the past (server-validated, form clamps old
  dates); every default step shows a date counted from the start date and each is individually editable
  (not earlier than the start date); Reset per step.
* **Detail** — header with status/go-live/slip; public link (copy, open, switch off, new link); start date
  (move open steps or not) and reset dates; **Steps** table (Mark done / Skip / Reopen / Edit / reorder /
  Archive; custom steps; "Agency ticks" flag; auto-tick badges); **Information sections** (blocks) the same;
  archived items with Restore; **History** tab; **Agreement** panel (link a Platform E-Sign document);
  **Archive** the whole timeline (soft delete; public link goes offline) — restorable from the list's Archived
  filter unless the agency already has an active timeline.
* **Defaults (Dev Settings)** — add / edit / reorder / archive / restore steps and sections; days-after-start;
  trigger; go-live; public; agency-can-complete. Running timelines are never changed.

### 4.4 Overdue → go-live
`state()` = done | skipped | overdue (due before today and pending) | upcoming. Expected go-live = planned +
the worst overdue slip among steps due on/before the go-live step (`goLive()`); shown on every screen.

### 4.5 Auto-ticks
`contract_signed`: `syncAgreement()` (run whenever a timeline is read, owner or public) fires
`AgencyContractSigned` when the linked Platform E-Sign document is `completed`; `setup_wizard_completed`:
`AgencySetupWizardController` fires `AgencySetupWizardCompleted`. `CompleteTimelineItemsOnTrigger` ticks only
pending items, once, with a history entry.

### 4.6 Public page (no login)
* URL `/agency-timeline/{agency-name}/{token}` (name cosmetic, never checked); bare `/agency-timeline/{token}` still works.
  Unknown / disabled / archived token → the same neutral 404 page (no agency name). `noindex`, `no-store`, throttled.
* Shows only items marked public, pre-rendered (no models, no emails/names). Hero (progress ring, days to
  go-live, next-up), an **interactive timeline** (steps share the width — no sideways scroll; vertical on
  phones; Today pin; click / prev-next), the plan, information sections; Open Graph tags for link previews.
* **Agency ticks:** `POST /agency-timeline/{token}/steps/{item}` (throttle 20/min, CSRF). Allowed only if the
  timeline is *running*, the step is a public milestone with `agency_can_complete`, and it is pending. Undo is
  allowed only for a tick the agency itself made. Every change is logged "by the agency" and fires
  `AgencyTimelineMilestoneCompleted`. CoreX decides per step which are the agency's.

## 5. Permissions
Owner-only (System Owner), **no permission key by design** (a key is grantable via Role Manager and these
screens expose every agency's commercial/onboarding state). The public routes are token-gated.

## 6. Domain events (Non-negotiable #9) — catalogued in `corex-domain-events-spec.md` §5
`AgencyTimelineStarted`, `AgencyTimelineMilestoneCompleted`, `AgencyContractSigned`, `AgencySetupWizardCompleted`.

## 7. Multi-tenancy / scoping
Timeline tables are platform-owned (no `agency_id` scoping; reached only through owner-only routes or an
unguessable token). Platform E-Sign data is agency-less and isolated as in §3.2–3.3.

## 8. Deployment notes (for the live push — not yet authorised)
* Migrations are additive except `ALTER TABLE docuperfect_documents MODIFY agency_id … NULL` (brief table
  lock on a large table — run off-peak) and the `UPDATE … JOIN` in `140000`.
* Dump the DB to the data volume first; tag; `git merge --ff-only`; only AT-447 commits go to `main`
  (cherry-pick — `QA2` carries other lanes' work).
* `php artisan platform-esign:demo` is QA-only; do not run on live.

## 9. Acceptance criteria
1. Owner starts a timeline: past start date rejected; each step date editable; defaults snapshot.
2. Editing defaults never changes a running timeline; a second timeline for an agency is refused.
3. Overdue steps show and push the go-live date; marking them done pulls it back.
4. Public link works by token only; wrong name still works; wrong/disabled/archived token → neutral 404.
5. Public page hides non-public items and leaks no names/emails; the agency can tick only opened steps while
   running, can undo only its own tick, every change is in History.
6. Linking a Platform E-Sign document and fully signing it ticks the "sign agreement" step; finishing the
   setup wizard ticks its step.
7. Non-owners get 403 on every owner route; platform contracts are invisible to every agency (§3.3).
8. Timelines, steps, sections and defaults can be archived and restored; nothing is hard-deleted.

## 10. Files
Controllers: `Admin/AgencyTimelineController`, `Admin/AgencyTimelineDefaultsController`,
`Admin/PlatformEsignController`, `Public/AgencyTimelinePublicController`. Service: `Platform/AgencyTimelineService`,
`Platform/PlainDocRenderer`. Models: `Platform/AgencyTimeline*`. Mode: `Support/PlatformEsignMode`,
`Scopes/PlatformTemplateScope` (+ small guarded changes to `AgencyScope`, `BelongsToAgency`, `Docuperfect/Template`,
`TemplateController::cdsGenerate`, `SignaturePdfService`). Views: `admin/agency-timelines/*`,
`admin/dev-settings/timeline-defaults` + `_timeline-default-form`, `docuperfect/platform-hub`,
`partials/platform-esign-banner`, `public/agency-timeline/*`. Command: `platform-esign:demo`.
Tests: `tests/Feature/Platform/{AgencyTimelineTest,PlatformEsignModeTest,PlatformEsignIsolationTest}`.


## 10. Platform E-Sign v2 — as built (2026-10-05)
* Routes `corex/platform-esign/*` (owner-only; sidebar System Developer → Platform E-Sign) and public `platform-esign/sign/{token}`.
* Tables `platform_esign_{templates,template_fields,documents,signers,field_values,events,attachments}`; code in
  `App\Models\PlatformEsign`, `App\Services\PlatformEsign` (EsignService, SealService, MergeFields), `App\Http\Controllers\PlatformEsign`.
* Templates: **wording** (typed, with agency merge fields, auto signature blocks) or **PDF** (upload, rasterised with
  pdftoppm, drag-to-place signature / initials / date / text fields per signer). PDF only — Word upload is not supported.
* Signers sign in order (or all at once), each by their own emailed link; ID/passport required; typed or drawn signature;
  decline; resend (new links), void, expiry; sealed PDF (SHA-256) + signing record; signed copy emailed to every signer
  and the sender; full audit trail. List screens have search, sort, filters, pagination, archive/restore (no hard delete).
* Timeline: sending a *Subscription agreement* for an agency whose timeline has no agreement links it automatically;
  when it is fully signed the `contract_signed` step ticks (`AgencyContractSigned`, payload now `documentId`).
* Retired: `PlatformEsignMode`, `PlatformTemplateScope`, the DocuPerfect hub/banner and the agency-scope hooks; the shared
  e-sign files are byte-identical to before AT-447. Migration `160000` archives (soft delete) the old agency-less
  DocuPerfect rows and renames `agency_timelines.agreement_template_id` → `agreement_document_id`. The inert columns
  `docuperfect_documents.agency_id NULLable` and `docuperfect_templates.is_platform` remain.
* Deployment: two additive migrations (`150000` create tables, `160000` retire). `pdftoppm` (poppler-utils) must exist on the
  host (it does on Staging). QA only: `php artisan platform-esign:demo [--remove]` (never on live).
* Not in the setup wizard (non-negotiable #10a): no agency setting was added — this is platform-owner tooling.


## 11. Web documents — CoreX Subscription Agreement (AT-447 follow-up, 2026-10-06, Johan)

> Status: spec written before code. Delivery is in four separable phases (§11.12): **(b)** core build — what Johan sends today;
> **(c)** wet-ink download/upload; **(d)** wording editor, `/legal` page, agency-screen button, reminders/expiry settings.
> (c) and (d) touch disjoint files and can be given to another lane. Source wording: `resources/legal/subscription-agreement/
> agreement-v1.0.md` + `netcash-mandate-v1.0.md` — the ONLY source of legal text; never reworded here.

### 11.1 What it is and why
Johan's ruling: the Subscription Agreement is a **web document**, not a PDF with dragged boxes and not pre-completed by RR. The
**recipient** (the agency) opens a link, fills in the form fields, initials every page, signs; **RR Technologies** reviews and
countersigns; a sealed PDF (letterhead, values, initials, both signatures, audit page) goes to both parties; the "signed platform
contract ticks the agency timeline" hook fires. Alternative on the same link: download, sign by hand, upload (§11.8). One-click send
(§11.9). Everything lives INSIDE the Platform E-Sign module (own tables, tokens, signers, sealing, audit, mail path, owner gate,
timeline hook). DocuPerfect is not touched. Pillars: **Agent** (the agency principal signs). Owner-only on the RR side; the
recipient side is token-gated and public by design (same as `platform-esign/sign/{token}`).

### 11.2 Data model (migration `2026_10_06_100000_create_platform_esign_web_documents`)
* `platform_esign_templates.source` gains the value **`webdoc`** (third source beside `web`/`pdf`). Kind `subscription_agreement`; roles
  `r1` Agency (signs first), `r2` RR Technologies (countersigns). Webdoc templates are excluded from the generic Send screen.
* **`platform_esign_wording_versions`** — `template_id`, `version` (string, e.g. `1.0`), `version_date`, `content_json`
  (intro, part_a, part_b, part_c, part_d, mandate — markdown with field tokens, §11.4), `rates_json` (§11.5), `layout_json`
  (calibrated pagination, §11.6), `is_published`, `published_at`, `created_by`. Unique `(template_id, version)`. **Never deleted,
  never updated once a document pins it** (editing = a new version).
* `platform_esign_documents` gains: `wording_version_id` (the pinned version — a sent document always renders that version),
  `contract_ref` (unique, `CX` + 6-digit document id — shown in Part A and mandate sections A and E), `form_data` and `rr_data`
  (**encrypted** `encrypted:array` casts — recipient entries / RR entries; no key is ever stored in clear), `form_rev` (optimistic
  counter for two-tab saves), `recipient_note`.
* `platform_esign_signers` gains `initials`, `signature2_image` (the mandate signature "as used for operating on the account").
* **`platform_esign_initials`** — one row per signer per page (`page_no`, `initials`, `ip`, `created_at`), unique `(signer_id, page_no)`.
* Statuses added to `Document::STATUSES`: `awaiting_countersign` ("Signed by agency — awaiting RR"), `wetink_received` (phase c).
  Display labels for webdoc: Sent · Opened (sent + recipient viewed) · In progress · Signed by agency — awaiting RR countersign ·
  Completed · Signed copy received (wet ink). No hard deletes anywhere; cancel = existing void; archive = soft delete.
* Phase (c) adds `platform_esign_wetink_files`. Phase (d) adds nothing but `DevSetting` keys.

### 11.3 Sources → stored version (seeding)
`AgreementContent::ensureSeeded()` (idempotent; also `php artisan platform-esign:seed-agreement`) creates the template and version
**1.0, 28 September 2026** from the two source files by **exact-substring replacements** of blanks with tokens (each replacement
must match exactly once or the seeder throws — the source cannot silently drift). Replacements only ever swap a blank (`______`,
`☐`, `……`, an empty table cell) for a token or insert a token next to existing words; no legal word is added, removed, or reordered.
Two intentional exceptions, both inside the mandate: its beneficiary name/address blanks are filled with the fixed RR Technologies
details (name from the source; address from clause B25). A fidelity test renders the stored version with every token blank and
compares it word for word against the source files.

### 11.4 Field tokens and schema
Tokens (written inside the markdown, rendered per mode): `{{f:key}}` recipient input · `{{o:key:value}}` radio/tick option ·
`{{q:key}}` quantity · `{{rate:key}}` rate (from `rates_json`) · `{{amt:key}}` calculated amount · `{{rr:key}}` RR-side field ·
`{{sig:agency|mandate|rr}}` signature · `{{ini:agency|rr}}` initials · `{{ref}}` contract reference · `{{auto:day|monthyear}}`.
`AgreementFields` holds the schema (key, label, side, type, required, max length, sensitive). **Part A**: registered name, trading
name, registration no, VAT no (optional), PPRA FFC no, physical address, email for notices and invoices, principal full name, billing
contact name/email/cell, number of branches · entity type (juristic | natural, required) · plan (team | agency, required) · branches at
start · **number of agents** (one entry, §11.5) · additional branches · start date · initial term (1 month | other: N months) · debit
order (account holder, bank, branch code, account number, account type) · signature block (name, capacity, signature, date, place).
**Mandate**: accountholder, address, bank name, branch name and town, branch number, account number, type of account (Current /
Savings / Transmission), date, contact number, amount, first payment date, day of month, assisted by / capacity, place, signature.
**Pre-fill** (nothing typed twice, all still editable): mandate accountholder ← debit-order account holder; address ← physical
address; bank ← bank; branch number ← branch code; account number/type ← debit-order; contact ← billing cell; amount ← monthly total.
**RR-side** (never editable by the recipient): contract reference (auto), agreed variation text + amount, RR initials, RR
name/capacity/date/place, RR signature. Beneficiary (RR Technologies (Pty) Ltd, address, shortname RR TECHNOL) is fixed text.

### 11.5 Pricing — from the pinned version's `rates_json`, never from a view
**Section 3 completes itself** (Johan, 2026-10-06): the recipient enters TWO numbers — **number of agents (seats)** and **number of branches** — and the
form does the rest. The tiering is graduated (matches the published price list): Team R450/agent flat (up to 10 agents, no base fee, no branch fee);
Agency base R1 495 + seats 1–10 R295, 11–20 R250, 21+ R195 + additional branches R750 each (Agency plan only, the first branch is in the base fee).
- **1–10 agents → CoreX Team** ticked automatically (Team line = agents × R450, Agency lines blank); **11+ → CoreX Agency** ticked automatically
  (base 1 × R1 495, seats split 1–10 / 11–20 / 21+, additional branches = branches − 1). 13 agents / 1 branch = R1 495 + 10×R295 + 3×R250 = **R5 195**;
  25 agents = base + 10×295 + 10×250 + 5×195. Over 40 agents the Agency lines still calculate and a note (recipient form AND RR screens) says a quoted
  rate applies and is recorded under "Agreed variations" — signing is never blocked.
- The plan tick boxes **show** the result and cannot be ticked by the recipient. "Number of branches" (section 1) and "Branches at start" (section 3) are one
  value entered once (the latter is a read-only mirror). The recipient can never write `plan`, `extra_branches` or `branches_start` (stripped server-side).
- **RR sender override (owner-only, optional, audited)** — the send form's "Fix the plan": `rr_data.plan_forced` = `team`|`agency`, logged as a `plan_forced`
  event; the recipient then sees that plan fixed whatever the number of agents (a forced Team with more than 10 agents cannot be signed — validation).
- **ONE calculation**: `AgreementPricing::derive()` (server). The page JS (`agreement/_js.blade.php` `calc()`) mirrors it line for line for live display;
  every other surface — autosave/resume, RR countersign review, wet-ink PDF, sealed PDF, the wet/preview/pdf renderers — reads the server's figures
  (`AgreementService::context()` overlays the derived plan/branches, so an older save with a wrong stored split is recalculated on the next load). On every save/submit
  the server stores the derived `plan`/`extra_branches`/`branches_start` and keeps the mandate **Amount** equal to the monthly total until the recipient types a different amount.
- Total = lines − agreed variation (≥ 0, ≤ lines). Validation: agents ≥ 1, branches ≥ 1, whole numbers. Rate cells in the source text are `{{rate:…}}` tokens that render
  `R450`, `R1 495`, … identically to the source. **The legal wording does not change** — only behaviour.
- **Read-only places are explained (screen only).** The three places the recipient cannot type in — the plan ticks, section 1 "Number of branches", section 3 "Branches at start" — look read-only
  (grey, dashed) and carry a small muted tip with an info icon: "Fills in automatically — enter your number of agents and branches in the *Monthly fee at start* section (section 3)." The link scrolls
  to and focuses the agents box. Recipient web form only (`AgreementRenderer::tip()`): never in the wet-ink or sealed PDF, the RR/preview screens or the wording; a declared addition in the §11.15 proof.
- To change the rule later (e.g. "band rate for all seats"): `AgreementPricing::compute()` (server, one place) + its JS mirror, and a NEW wording version (the contract's
  "Monthly fee at start" paragraph and the fee table text describe the graduated rule).
Tests: `AgreementFeeTableTest` (1, 10, 11, 13, 20, 21, 25, 40, 41 agents × 1, 2, 4 branches; 10↔11 switching; resume; forced plan; surfaces), real-browser proof `scripts/verify-agreement-fees.cjs`.

### 11.6 Pagination — the screen and the PDF are the same pages
`AgreementLayout` renders the document in canonical mode, splits it into top-level blocks, estimates each block's height and packs
blocks into pages (forced break before every Part and before the mandate; headings keep with next; tables are never split), then
**calibrates** against a real DomPDF render of a worst-case filled sample (reduces the page budget until the physical page count
equals the planned page count) and stores the result in `layout_json`. The screen shows exactly those pages as sheets, each with
letterhead, footer and an "Initial this page" control; the PDF uses one page division per planned page. Footer on every PDF page:
`CoreX OS Subscription Agreement · Version <n> — <date> · Page x of y` (y from a two-pass render). If a filled PDF still ends up with
a different physical page count, the seal logs `page_count_mismatch` in the audit trail (initials are also printed in the footer of
every physical page, so no page is ever without them).
Letterhead on every page: the company logo (the one uploaded on the Company page; the built-in CoreX OS wordmark until one is) at a fixed
height with the company block beside it (legal name, address, phones + first website) — **all read from the platform company record and
pinned onto the document at send time** (`documents.company_snapshot`, so a later change on the company page never alters a sent or signed
agreement; new agreements use the then-current record). See `.ai/specs/platform-company-profile.md` §7a–7b. Nothing about RR Technologies is
hard-coded outside the pinned legal wording.

### 11.7 Recipient flow (token link `platform-esign/agreement/{token}`, no login, mobile-friendly)
open (audited) → read → fill (**autosave** debounced, resume from the same link, inline validation, an "outstanding" list that jumps
to each gap; two-tab guard via `form_rev` → "updated in another window" notice) → **initial every page** (initials captured once from
the recipient's name, editable; then one explicit tap per page, persisted per tap) → sign (typed name + capacity + drawn/typed
signature for Part A and, separately, for the mandate; the module's existing identity rule — ID/passport — is kept) → submit
(transaction, row lock; a second submit/tab gets "already submitted"). Expired / voided / completed links show a clear message; the
owner can re-issue (resend = new token, fresh expiry, form data kept). Status: sent → in progress (first save) → awaiting RR.
**RR countersign** (owner screen `…/documents/{id}/countersign`): sees every recipient entry read-only, cannot change them; completes
name/capacity/place/date, initials every page, signs → seal. Order is recipient first, RR second. **Deviation from the brief, reported:**
the agreed-variation text and amount are RR-side fields set **at send** (visible to the recipient before they sign), read-only at
countersign — otherwise the monthly total and the mandate debit amount would change after the agency has signed. To change them after
sending, void and re-send.

### 11.8 Wet-ink option (phase c)
"Download to sign by hand": PDF with the values typed so far and blank initial/signature lines (audited). "Upload signed copy":
pdf/jpg/png, ≤ 10240 KB each, several files allowed, private disk, SHA-256, earlier uploads become *Superseded* (kept). Status
`wetink_received`. RR then **countersigns electronically on a countersignature & attestation page** (a short sealed PDF carrying RR's
name, capacity, signature, date and the SHA-256 of every file received) — the simplest sound option, because merging onto the
recipient's scan needs a PDF-import library the platform does not have. Completion + timeline hook fire as in the e-sign flow.

### 11.9 One-click send
"Send Subscription Agreement" on the hub (and on the agency timeline screen in phase d): recipient full name + email (+ optional
cell), optional agency link (pre-fills the fields the agency record already holds — the recipient can correct them), optional note,
optional agreed variation. Email through the module's `corex` mailer; the owner screen shows a copyable link, status, resend, void.
List screen = the existing Documents list (search, sort, filters, pagination, empty state) with the new statuses. Link expiry
(`platform_esign.agreement_expiry_days`, default 30) and reminder interval (`platform_esign.agreement_reminder_days`, default 3) are
`DevSetting` values (editable in phase d). Not in the agency setup wizard (non-negotiable #10a): platform-owner tooling.

### 11.10 Sensitive data
All form data is stored encrypted (`encrypted:array`). Owner screens mask account numbers (••••1234) and reveal only on an explicit,
audited action (`bank_revealed` event: who/when/IP). Values are never written to logs or audit details and never put in email
bodies. Sealed PDFs and wet-ink files live on the private disk and are served only by owner-gated streams (and the signer's own
completed-copy link). **Superseded 2026-10-06 (Johan): the sealed PDF is NOT emailed — see §11.17.** Completion emails carry a secure link only.

### 11.11 Public terms page (phase d)
`/legal` renders the current published Parts B, C, D (same typesetting); `/legal/v/{version}` older versions. Unauthenticated, throttled.

### 11.12 Phases, routes, files, tests
**(b)** migration; models; `AgreementContent`, `AgreementFields`, `AgreementPricing`, `AgreementRenderer`, `AgreementLayout`,
`AgreementPdf`, `AgreementService`; controllers `PlatformEsign/AgreementController` (owner) and `AgreementSigningController` (public);
views `platform-esign/agreement/*`; routes `platform-esign.agreements.*` (owner), `platform-esign.agreement.*` (public). **(c)**
`WetInk*` + migration `110000`. **(d)** wording editor/versions, `Public/LegalController` additions, timeline button, settings page,
`platform-esign:remind-agreements` (scheduled). Tests: `tests/Feature/Platform/Agreement/*` — wording fidelity, pricing tiers (25
agents), pinned-version rendering, token scoping, signing order, RR cannot edit recipient entries, encryption/masking/reveal audit,
wet-ink supersede, `/legal`.


### 11.13 Phase (c) as built (wet-ink)
`platform_esign_wetink_files` (migration `2026_10_06_110000`) holds each upload (batch, sha256, mime, size, `superseded_at`); nothing is deleted. Recipient routes
`platform-esign.agreement.wet-copy` (PDF in `wet` mode: entries typed so far, blank initials/signatures) and `.upload` (pdf/jpg/png by content type, ≤ 10 240 KB
each, ≤ 12 per upload, ≤ 60 per agreement; a new upload supersedes the active files). Status `wetink_received`; the agency signer is marked signed. RR countersigns on
`…/countersign` (a wet-ink variant of the screen: files with SHA-256, name/capacity/place/date/signature — no page initials) → `AgreementService::countersignWetInk()` →
a countersignature-and-attestation PDF listing every active file's SHA-256 + the signing record is sealed and emailed; the timeline hook fires as usual. Owner files are streamed
only through `platform-esign.agreements.wetink` (owner-gated). Electronic countersign refuses a hand-signed document and vice-versa.

### 11.14 Phase (d) — wording editor, public terms, agency-screen send, expiry + reminders (cc2, 2026-10-06; extends §11.2/§11.9/§11.11)
Business: Johan, 6 Oct — "the terms of the document may change over time and having access to edit it is great"; sending "should be an
easy one click send button and adding the recipient details"; the agreement says Parts B, C, D "are published at
www.corexos.co.za/legal" (was a 404). Owner-only on every RR-side screen, no permission key (same as the rest of the module). No hard
deletes. Nothing here changes what a signing agency sees except that **new** agreements pin the **current published** version.

**Data (migration `2026_10_06_140000_agreement_wording_editor`)** — `platform_esign_wording_versions` gains `change_note`,
`parent_version_id` (the version a draft was copied from), `rev` (edit counter — two-tab guard), `published_by`, `deleted_at`
(a discarded draft is soft-deleted, never published ones). New `platform_esign_wording_audit` (template, version, action, user, detail,
created_at): draft_created · section_saved · rates_saved · previewed-not-logged · published · discarded · restored · settings_changed.
A draft's `version` column holds `draft-<id>` until publish (so discarded drafts never block a real number); `is_published=false`.
**Published rows are immutable** — enforced in the model (`updating` guard: only `layout_json` may change, because pagination is
lazily recalculated when the layout engine's REV moves; `deleting` of a published row throws). "Current" = the published version with the
latest `published_at` (a restoring v1.2 therefore becomes current). `AgreementContent::ensureSeeded()` now returns the current version
(v1.0 is only the seed), so **send always pins the current published version**; a sent document keeps its `wording_version_id` forever.

**Editor (nav tab "Agreement wording", `platform-esign.wording.*`)** — versions list (number, date, status, change note, who/when, sent
count) · "New version" copies the current version (or any published version — "start a new version from this one", which is how an
earlier wording is restored) into ONE draft (a second draft is refused; the owner continues or discards it). A draft is edited **section
by section** (Cover, Part A, B, C, D, Debit-order mandate) at **clause level**: each clause (a top-level Markdown block — paragraph,
heading, numbered clause, list, table) is shown as it will print; click to edit it in a small editor with a toolbar limited to what the
document uses (heading, bold, italic, link, numbered clause, table), a live preview of that clause, add-clause-below, move up/down, remove.
Rates are edited in a form (Team seat, Agency base, seat tiers + their breakpoints, extra branch, the quote-above-agents notice).
**Field markers** (`{{f:reg_no}}`, `{{rate:agency_base}}` …) are shown as grey chips and are guarded: on every save and again at publish,
each marker must be known, and every form field / option / quantity / amount / signature / initials / agents-control marker must occur
exactly as many times as in the version the draft was copied from — wording can be rewritten around a field but a field cannot be
removed, duplicated or invented from this screen (that needs a developer). Free markers (`{{rate:…}}`, `{{ref}}`, `{{auto:…}}`,
`{{co:…}}`) may be added where a known key exists. Clause splitting uses the Markdown parser's own line positions and is verified
lossless against v1.0 (join(split(x)) renders identically).
**Preview** — "Preview as the recipient will see it": the paginated sheets in the real form look (inputs disabled) from the stored
layout, plus a sample PDF (the same renderer, a worst-case filled sample). Calibration runs on preview/publish and is stored on the draft.
**Publish** — version number (`n.n`, suggested next minor, unique), version date (default today), required change note (≥ 5 chars).
Re-validates everything, calibrates layout, flips `is_published`, writes audit. **Discard** (soft) with a confirm; **Restore** a discarded
draft while no other draft exists. **What changed** — pick any two versions: side by side, per section, aligned by clause; removed clauses
red on the left, added green on the right, edited clauses show a word-level diff; rate and date/note changes listed above it.

**Public terms (`GET /legal`, `GET /legal/v/{version}`, no login, throttled)** — current published Parts B, C, D only (never Part A,
the cover, the mandate or any agency data), in the agreement's typesetting with the platform letterhead (company adapter), heading with
version + date + change note, an index of every published version (current marked). `/legal/v/{current}` 301s to `/legal`. HTTP caching
(ETag from version + company record revision, `Cache-Control: public, max-age=300`), print stylesheet (letterhead once, no controls,
clauses not split across pages where the browser allows), indexable on `/legal`; superseded versions are `noindex,follow`.

**Send from the agency screen** — the agency's timeline screen gets a primary "Send Subscription Agreement" button opening the §11.9
send form with the agency pre-selected; recipient name / email / cell are pre-filled from the agency's principal (falls back to the
agency's own email/phone) and stay editable; picking a different agency in the form re-fills them. The screen also shows the agency's
latest Subscription Agreement (status, sent date, link expiry, "Open") and a one-click re-issue when it has expired.

**Expiry + reminders (platform settings, `DevSetting`, editable on the Agreement wording page, owner-only; defaults in brackets)** —
`agreement_expiry_days` (30), `agreement_reminder_days` (3: first reminder after this many days with no progress), `agreement_reminder_repeat_days` (3),
`agreement_reminder_max` (3, 0 = off), `agreement_countersign_reminder_days` (1: RR reminded when an agency-signed agreement is still
un-countersigned; repeats at the same interval, same max). "Progress" = the recipient saved, initialled a page or signed (opening the link is not
progress). `platform-esign:remind-agreements` (hourly, `withoutOverlapping`; `--dry-run` lists without sending): (1) marks unsigned
agreements past their link expiry `expired` (event logged; agreements already signed by the agency never expire); (2) emails the recipient
a reminder (same link) when `max(sent, last progress, last reminder) + interval ≤ now` and fewer than max reminders went; (3) emails the
RR signer likewise for `awaiting_countersign`/`wetink_received`. Every decision re-reads the document's current state, so reminders stop the
instant it is signed, voided, expired, completed or archived. A resend/re-issue resets the reminder counters. Expired links show the recipient a
clear message (nothing they entered is lost); the owner sees a one-click "Re-issue" (= resend: new link, fresh expiry, data kept) on the document and on the agency screen.
No values are ever put in a reminder email. Not in the agency wizard (non-negotiable #10a): platform-owner tooling.

**Files (d)** — migration `…140000`; `Models/PlatformEsign/WordingAudit`, `WordingVersion` (guards, scopes); `Services/PlatformEsign/Agreement/`
`AgreementVersions` (draft/save/publish/discard/restore/audit), `AgreementTokens` (marker parse + guard), `AgreementBlocks` (split/join),
`AgreementDiff`, `AgreementReminders`; `AgreementContent::current()`; `Console/Commands/PlatformEsignRemindAgreements`; `Http/Controllers/PlatformEsign/AgreementWordingController`;
`Http/Controllers/Public/LegalController` (+`agreementTerms`, `agreementTermsVersion`); views `platform-esign/wording/*`, `public/legal/agreement-terms`;
`Mail/PlatformEsign/AgreementCountersignReminderMail`; `routes/web.php`, `routes/console.php`; timeline show view + send view (prefill); header tab.
Touched outside the (d)-only files, minimal: `AgreementRenderer` (+`edit` mode: field markers as labelled chips; /legal uses the existing `text` mode), `AgreementService` (current version on send; expiry applies only
before the agency signs — an agency-signed agreement waiting for RR must never flip to "expired"; resend resets reminders), `AgreementController::create` (prefill),
`platform-esign/_header` (tab), `documents/show` + `_owner-panel` (re-issue prompt). Tests: `tests/Feature/Platform/Agreement/{WordingVersions,LegalTerms,AgencyScreenSend,Reminders}Test.php`.

### 11.15 Sender address + word-for-word proof (cc2, 2026-10-06)

**Platform email sender (1B).** Every Platform E-Sign / Subscription Agreement email (agreement invite + reminder, agency-signed notice, countersign reminder, platform
invite, signed copy) is sent **From the RR Technologies company record** — Platform Company Profile → *Sending address* + *Sender name* (migration `2026_10_10_140000`;
defaults `admin@corexos.co.za` / "CoreX OS — RR Technologies"; audited like every company edit; **not** pinned to a sent document — it is operational, so reminders use today's
mailbox) — with **Reply-To = the owner who sent the agreement**. Never the box-wide `MAIL_FROM_*` (QA1's is an agency address). All five mail classes use the
`SendsFromPlatformCompany` trait (a test fails if a new `PlatformEsign/*Mail` class does not). The invite carries the company email signature. Real delivery from a corexos.co.za
address needs the `corex` mailer's SMTP login (`MAIL_COREX_HOST/PORT/USERNAME/PASSWORD`) for a mailbox allowed to send as the sending address; on QA1 and Staging the mailer points at the local
Mailpit (127.0.0.1:1025, no credentials), so nothing leaves the box.

**Word-for-word proof (Job 2).** `php artisan platform-esign:verify-wording` + `scripts/verify-agreement-wording.sh` (real Chromium via `scripts/verify-agreement-web-text.cjs`):
for a throwaway agreement pinned to the SEEDED v1.0 it compares the recipient page (real browser), the wet-ink download PDF and — after both parties sign — the sealed PDF against the two
source files as ONE continuous word sequence (`AgreementFidelity`: independent source normaliser, no CommonMark; blanks may be filled by a field/value — every fill is listed and must be
explained by the document's own values; tick boxes may be controls or ☐/☒). Allowed differences only: markup/table reading order, whitespace and line wraps (incl. a PDF line
wrapping after a hyphen, extractor spacing beside quotes), "1st" spacing, blank runs → fields/values, letterhead/footer/initial marks (cropped / not part of the sheet body), the company block.
Anything else is a defect. It also checks stored v1.0 == the content built from the source files, the footer label "Version 1.0 — 28 September 2026", and (test) that a fresh database seeds ONLY 1.0.
**Declared additions** (reported, not silently allowed): the contract-reference line under the Part A heading (§11.3); the "Number of agents" / "Number of branches" labels of the two entries on the web form;
a tick box before each of the three account types on the mandate (print/PDF). Also the three screen-only "Fills in automatically" tips (§11.5) — web form only; the proof strips them from the page text, counts them, and fails if one appears in a PDF. Tests: `AgreementFidelityTest` (negative cases prove a changed/missing/extra/reordered word is caught), `AgreementMailSenderTest`.

### 11.17 Completion stays inside CoreX — no attachment, no bank details by email (cc4, 2026-10-06, Johan)
Johan: "the agency is completing the document on a CoreX link so that should be secure. From there it stays inside CoreX." The signed
agreement holds the agency's bank details, so it is never emailed.

* **Completion mails** (`AgreementSignedMail`, replaces `SignedMail` for web documents only; the generic e-sign `SignedMail` is unchanged)
  go to the agency signer and to RR (the RR signer + the sending user) and say the agreement is fully signed. **No attachment, no entered
  value.** Agency link = its own token link (`platform-esign.agreement.show`); RR link = the owner-gated document screen
  (`platform-esign.documents.show`). The wet-ink path is the same: no scan and no attestation PDF is ever attached. Mails checked for
  attachments or bank details with no change needed: invite + reminder (`AgreementInviteMail`), received (`AgreementReceivedMail`),
  countersign reminder (`AgreementCountersignReminderMail`) — links and names only.
* **The agency link after completion** (`platform-esign/agreement/{token}`) opens a read-only **Completed** page: status, the signed
  agreement on screen (bank numbers masked; the PDF carries them), "Download the signed PDF" (`.../download`) and the agency's own
  uploaded hand-signed files (`.../wet-file/{file}`). The same token the agency signed on; RR's token never opens anything public.
* **Access window** — platform setting `platform_esign.agreement_access_months` (default 12, 1–120; Agreement wording page, "Signed
  agreement link valid for (months)"). Written to `documents.expires_at` at completion (`now + months`); after it passes the link shows
  "This link has expired…" and opens/downloads nothing. The owner can **re-issue** at any time (owner panel on the document, or
  `POST …/documents/{id}/resend` on a completed web document → `AgreementService::reissueAccess`): new token (the old link stops working),
  fresh window, the agency emailed the new link (no attachment), `access_reissued` audited. The pre-signing expiry/reminder settings
  (§11.14) apply only before signing; the reminder sweep never touches a completed agreement.
* **Audit** — `completed_viewed` (page opened on the agency link), `signed_copy_downloaded` (agency link or owner), `wetink_downloaded`
  (agency link or owner), `access_reissued`; each with signer/actor and IP. Downloads are streamed through the controller
  (`Storage::download`, `Cache-Control: no-store`), never a public file URL.
* **Not done / reported:** the generic (non-agreement) Platform E-Sign `SignedMail` still attaches the sealed PDF — out of scope here.
  QA1 test agreements that completed before this change have no access window written (`expires_at` is their old signing expiry).

### 11.18 Wording correction — clause D3.6 (cc4, 2026-10-06, Johan)
Johan: "3.2 is correct. 3.6 should refer to same — we do not host but we maintain the site." Version 1.0 itself is corrected (no agreement
had been issued): D3.6 now reads "We maintain the website for as long as this agreement runs. When it ends, we stop maintaining it, and
the domain name remains the Agency’s." Corrected in `resources/legal/subscription-agreement/agreement-v1.0.md` (the seed source, so every
fresh database seeds only "1.0 — 28 September 2026" with the corrected clause) and the conductor's reference copy. Environments that already
hold 1.0 (QA1) get the corrected text as a new published version through §11.14, because published versions are immutable.

### 11.19 Take-on month — the start and first-billing dates are set by RR (cc2, 2026-10-06, Johan)
Rule: the free take-on month is the whole calendar month in which take-on / go-live happens. **Agreement start date = the 1st of the take-on month; billing start / first debit = the 1st of the following month**
(take-on October → starts 1 October, billing 1 November; November → 1 December; December → 1 January of the next year). Derived in ONE place, `AgreementTakeOn` (`derive()` / `values()` / `options()` / `valid()`).
- **Send form (owner):** required "Take-on month" list (this month + the next 17, default this month, past months cannot be chosen — also enforced server-side), with both derived dates shown before sending.
  Stored as `rr_data.take_on_month` (YYYY-MM) and audited as a `take_on_set` event. `AgreementService::send()` also pre-sets `form_data.start_date` / `m_first_payment`.
- **Document:** the two fields that carry these dates — Part A §4 "Start date" and the mandate "first payment instruction … on ___ (date)" — are filled from the take-on month on every rendering
  (recipient form, RR/preview screens, wet-ink and sealed PDF; `AgreementService::context()` overlays them) and are read-only for the recipient: shown as plain read-only boxes with the screen-only tip
  "Set by CoreX as agreed for your take-on month." (never in a PDF; a declared addition of the §11.15 proof). The server strips any recipient attempt to write them. No wording change.
- **Mandate first collection** (Johan, 12:48): with a take-on month the mandate's three collection fields are RR's too and read-only for the recipient — first payment date = the derived billing start, "___ of each month" = 1 (the collection day),
  and "Amount" = the monthly fee calculated from the agents/branches pricing (first payment and recurring amount are the same: the take-on month is free, so no once-off or pro-rata amount exists; the mandate has no separate field for one).
  The Amount follows pricing changes server-side (`withTakeOn`) and live in the page; tip "Fills in automatically from your monthly fee in section 3." Printed on both PDFs.
- **Not mapped (reported, not guessed):** the two signing dates (Part A §6 "Date", mandate "Date") and
  "on this ___ day of ___" are signing dates, not start/billing dates, and are untouched.
- **Already-sent agreements** (no `take_on_month` on the record) keep exactly what they have and stay typeable.
Tests: `AgreementTakeOnTest` (Oct→1 Nov, Nov→1 Dec, Dec→1 Jan rollover, Feb, past/junk months, send form, audit, read-only + server strip, wet-ink PDF, legacy agreement).

### 11.20 Single entry — typed once, mirrored everywhere else (cc2, 2026-10-06, Johan: "option A, ruled")
Section 5 (debit order authority) keeps its wording and layout exactly, but its bank fields are **read-only** on the recipient web form and fill themselves from the Netcash mandate — **the mandate is the one place bank details are typed.**
Every other value that used to be typed in both documents gets the same treatment (typed once, mirrored, screen-only tip with a link that jumps to and focuses where it is typed) — except the mandate address and contact number, which FOLLOW Part A and stay editable (below). `AgreementFields::MIRRORS` (target ⇐ source), applied server-side by `AgreementService::withMirrors()`:

| Mirrored (read-only) | Typed once in | Tip link goes to |
|---|---|---|
| §5 Account holder | Mandate "Given by (name of Accountholder)" | `fld-m_holder` |
| §5 Bank | Mandate "Bank Name" | `fld-m_bank` (tip sits after the branch code, same row) |
| §5 Branch code | Mandate "Branch Number" | `fld-m_bank` |
| §5 Account number | Mandate "Account Number" | `fld-m_account` |
| §5 Account type | Mandate "Type of Account" ticks | first tick box |
| Mandate "Signed ___ on this" place | Part A §6 Place | `fld-sig_place` |
| Mandate Date | Part A §6 Date | `fld-sig_date` |

**Follow, not mirror — mandate address and contact number (Johan, 13:20):** they start from Part A (physical address §1, billing contact cell §1) and follow it while the recipient has not edited the mandate field; once edited the recipient's own value sticks and no longer follows Part A;
clearing the field makes it follow Part A again (a value typed equal to Part A is not an override). Editable, accepted by the server (`AgreementFields::FOLLOW`, `AgreementService::withFollow()` / `normaliseOverrides()`): only an override is stored, the effective value is what the renderers
(review, both PDFs) print, and the signed record stores the effective value. Screen-only tip: "Filled in from your details above — change it here if the debit order needs a different address." (… "number." for the contact number).
Place and date stay mirrored (read-only) as built.

Plus (§11.5, §11.19): branches (typed once beside agents), plan, start date, first collection date, collection day, mandate Amount — set from other entries / by RR.
- **Server-side:** the mirrored keys are stripped from every recipient request, recomputed from their source on every save/submit/render and stored explicitly; validation runs on the source only (`validateRecipient(..., $skip)`). Both PDFs, the RR screen and the preview print the mirrored values (section 5 shows the mandate's bank details).
- **Screen only:** the tips ("Fills in automatically from the debit order mandate." etc., see `AgreementRenderer::MIRROR_TIPS`) exist only on the recipient web form — never in a PDF or the wording; declared additions of the §11.15 proof, which strips and counts them.
- **Agreements sent before this change** (no `rr_data.single_entry`) keep both places typeable exactly as before.
- **Deliberately NOT mirrored (reported):** the mandate "Assisted by / Capacity" line (a different person's capacity, not the signer's Part A capacity); the mandate signature (a separate signature act — the "Use the same signature" button stays); registered name vs account holder (different things: the legal entity vs the bank account holder).
Tests: `AgreementSingleEntryTest`.

### 11.21 Alignment — signature blocks and the mandate grid (cc2, 2026-10-06, Johan: "I hate it if things are placed as scattered")
Layout only — no wording, order of clauses or mandate text changes. One set of rules (`agreement/_css.blade.php`, shared by the screen sheets and the PDF) over markup hooks added in `AgreementRenderer::alignmentHooks()`:
- **Signature blocks (Part A §6):** each row is one fixed label column (`.sr-l`, 24%) followed by one field column (72%) — every input, line and signature box starts on the same left edge and ends on the same right edge, identical in both blocks. The Agency / RR Technologies table gets `class="sigtable"` — two equal-width columns, and each row paragraph `class="sr sr-name|capacity|signature|date|place"` has ONE fixed height per row class, so the same row sits on the same baseline in both columns: same box heights, same label position, same line length, tops and bottoms aligned.
  The signature box is the same size in both columns (the drawing pad, the RR pad on the countersign screen, the static signature image and the blank line all share one box). The signature pad is built from `<span>`s (display:block), not `<div>`s: a block inside a `<p>` makes the browser close the paragraph early, which is what scattered the rows before.
  Applies on the recipient page, owner preview, RR countersign screen, wet-ink PDF and sealed PDF.
- **Netcash mandate:** every "Label: field" line (Given by, Address, Bank Name, Branch Name and Town, Branch Number, Account Number, Type of Account, Date, Contact Number, Amount, To (Name of Beneficiary), Address, Abbreviated Shortname) is a `p.mf` row of one grid: labels in one column, fields on a common left edge with one width, equal row heights (screen),
  in the mandate's own order. The screen-only tips sit in their own third column on the same row; inside a sentence (first payment date, collection day) they move to the end of the paragraph so the printed sentence reads straight through.
- **Initials:** the per-page initials boxes (PDF footer and screen footer chip/button) have identical size and vertical alignment.
- Layout revision 3 (`AgreementLayout::REV`) re-calibrates the pagination. Tests: `AgreementAlignmentTest`; before/after screenshots in the lane report.

### 11.22 "Take On Month" label, month-and-year display, blank picker (cc2, 2026-10-06, Johan 13:26)
- **Relabel (instructed by Johan):** the Part A §4 row label **"Start date" → "Take On Month"** (`| **Start date**||` → `| **Take On Month**||`, the only occurrence of "Start date" in the wording — it is not used in any clause text). The field still carries the take-on month RR set (§11.19) and is shown as **month and year only** ("October 2026") on the recipient page, owner preview, RR countersign screen and both PDFs (`AgreementRenderer::monthYear()`); the mandate's first payment date stays a full date ("1 November 2026").
- **Versioning:** the seed source (`resources/legal/subscription-agreement/agreement-v1.0.md`, so every fresh database seeds the corrected 1.0 — nothing has been issued from a fresh Staging yet) and the conductor's reference copy `/tmp/corex-agreement/agreement.md` carry the new label; environments that already hold published versions (QA1) receive it as a NEW published version through the wording versions service (§11.14), because published versions are immutable. Agreements already sent keep the version (and label) they were sent with and, with no take-on month, keep their typed full start date.
- **Send form:** the Take-on month list starts blank ("Choose the take-on month…", required, no default); the derived dates appear only after a choice. Every editable box on the form is white with normal text and a visible edge, placeholders (and the empty "choose" state) are clearly lighter — the theme's input colour equalled the card colour on this page, which made editable boxes look disabled. Scoped to `.send-agreement-form`.
Tests: `AgreementTakeOnTest`.

