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
* Not a copy of the e-sign code. One engine, run in a special mode (§3).
* Not the Agency Setup Wizard (`agency-onboarding-setup.md`); finishing the wizard merely ticks a step.
* No agency can see Platform E-Sign content, ever (§3.3).

## 3. Part A — Platform E-Sign (agency-less mode of the real e-sign)

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
