# Agency Timeline + Platform Contracts (Dev-side E-Sign) — Spec

> Status: **Draft pending Johan approval** — 2026-10-05
> Jira: AT-447
> Author: Claude (from Johan's brief)
> Area: **System Developer** (owner-only). Not an agency feature. Nothing here is visible to agency users
> except the public timeline page, which is read-only and link-gated.
> Pillars touched: **Agent** (the agency's first Admin is the contract signatory / timeline contact). Does not ingest
> property/contact/deal data (Non-negotiable #10 N/A). Reads `agencies`; writes only its own tables.
> Sister specs: `agency-onboarding-setup.md` (the setup wizard — a DIFFERENT thing, see §3), `agency-billing.md`,
> `ESIGN-CANON.md` (see §6.4), `corex-domain-events-spec.md`.

---

## REVISION 2026-10-05 (Johan) — supersedes §6 "Agency Contracts" and every reference to it

Johan reviewed the first build: the separate contracts module "looks nothing like the e-sign". Direction:
the contract is created, sent and signed in the **real e-sign (DocuPerfect) including its template creator**,
reached from **Dev Settings**, outside every customer agency.

How (one engine, no copy of the e-sign code):
* A dedicated **platform agency** (`agencies.is_platform = 1`, slug `corex-platform`, created lazily on first entry by
  `PlatformAgencyService::ensure()`) owns CoreX's own templates and documents.
* **Dev Settings → Platform E-Sign** (`admin.platform-esign.enter`, owner-only) sets `session('active_agency_id')` to
  that agency and opens `docuperfect.dashboard`. The owner then uses the normal creator, wizard, signing and sealed PDF.
* `Agency::customers()` excludes it; Agency Management, the switcher, Billing and Agency Timeline all use it.
* **Timeline link:** the timeline page picks a platform e-sign document (`agency_timelines.agreement_template_id`).
  `AgencyTimelineService::syncAgreement()` runs whenever the timeline is read (admin + public) and, when the document
  is `completed`, fires `AgencyContractSigned` so the `contract_signed` steps tick. No hook inside the e-sign.
* Removed: the separate contract templates/envelopes/PDF/mail/public-signing module and its four tables.
* Unchanged: Part B (timeline), the defaults, the public link, the setup-wizard auto-tick.

## 1. What this does and why (business requirement)

Onboarding a new agency today is run from a WhatsApp/email message that Johan writes by hand each time
("where we are, what we need from you, plan for the month, finances"). It is not recorded, the dates are
worked out by hand, and the agency has nothing to look at between messages.

Two things ship together under **System Developer**:

1. **Agency Timeline** — a dated, recorded onboarding timeline per agency, from "training done" to "live on CoreX".
   Defaults are set once in Dev Settings (e.g. "+3 days: send us your CRM export"). Starting a timeline for an
   agency stamps every default onto real dates counted from the agency's start date. Johan can add custom
   items per agency. The agency gets **one public link** to share round their office showing the plan, what is
   done, what is next and what is overdue.
2. **Agency Contracts** — CoreX's own contract (the Subscription Agreement and the debit order form) is held
   as a template on the developer side, sent to a new agency for electronic signature, and tracked to signed.
   It lives **outside the agencies** like Dev Settings and Agency Billing: no agency owns it, no agency admin can
   see it. When the agency signs, the matching timeline item ticks itself off.

### Why this makes CoreX *best*, not merely *working*
The onboarding conversation becomes a living, recorded plan the agency can open at any time, and the
contract step is part of the same flow instead of a separate PDF-and-email chase. Integration is the point:
signing the contract moves the timeline; finishing the setup wizard moves the timeline.

---

## 2. Pillar connections

| Pillar | Connection |
|---|---|
| Agent (`User`) | Signatory defaults to the agency's first Admin (the user created with the agency). Public timeline names the agency, never individual users. |
| Property / Contact / Deal | None. |

Cross-feature (domain events, Non-negotiable #9) — see §9.

---

## 3. What this is NOT (so nobody conflates them)

* **Not the Agency Onboarding Setup Wizard** (`agency_onboarding_setups`, `/agency-setup/{token}`). That wizard
  walks an agency Admin through configuring their own CoreX settings, behind a real login. The Agency Timeline is
  a *project plan* for the take-on, shown read-only, no login. Different tables, different link, different
  audience. The only coupling: the wizard's `completed_at` can auto-tick one timeline item (§9).
* **Not Docuperfect e-sign** (agency e-sign for property documents). See §6.4.

---

## 4. Navigation (Non-negotiable #2 — same day)

System Developer section of the sidebar, inside the existing owner-only `@if($isOwner)` block:

* Under the existing **Agency** slide-panel (beside Agency Management / Agency Setup Progress / AI Usage /
  Agency Billing): add **Agency Timeline**.
* New top-level item **Agency Contracts** directly under **Agency** (own page, outside the agency group, per the
  brief: "separately outside of the agencies like the dev settings").
* **Dev Settings** gains a new section **Agency timeline defaults** (the existing `?s=` rail contract in
  `DevSettingsController::SECTIONS`).
* Agency Management → create success message carries a **Start agency timeline** link for the new agency. (The
  Agency Timeline page itself is where a timeline is started for any agency, new or old.)

## 5. Permissions

`owner_only` middleware + `abort_unless($user->isOwnerRole(), 403)` in every action, **deliberately no key in
`config/corex-permissions.php`** — identical reasoning to Agency Billing / Dev Settings / Demo Access: a permission
key is grantable via Role Manager and these pages expose every agency's commercial terms and contract status.
Sidebar entries sit inside the existing owner block. The public timeline route is the only unauthenticated
route (§7.5) and the public signing route is the second (§6.3), both token-gated.

---

## 6. Part A — Agency Contracts (dev-side e-sign)

### 6.1 Scope
Platform owner can: keep contract templates, send one to an agency, see status, resend/void, download the
signed PDF. The signer is an external person (the agency principal) with no CoreX login.

### 6.2 Data model (new tables — none uses `BelongsToAgency`; they are platform-owned, `agency_id` is a plain FK for
"which agency is this contract for", queried explicitly in owner-only controllers)

`platform_contract_templates`
`id, name, kind (subscription_agreement|debit_order_form|other), body_html (merge fields below), version (int,
bumped on each save that changes body), is_active, created_by, timestamps, deleted_at`

`platform_contract_envelopes`
`id, agency_id, template_id, template_version, title, body_html_snapshot (merged + frozen at send — the signer
signs exactly this, later template edits never alter it), signatory_name, signatory_email, signatory_role
(default "Principal"), token (unique, random 48), token_expires_at, status (draft|sent|viewed|signed|declined|
expired|voided), sent_at, first_viewed_at, signed_at, declined_at, decline_reason, signed_typed_name,
signed_ip, signed_user_agent, consent_text_snapshot, document_hash (SHA-256 of the sealed PDF),
sealed_pdf_path, voided_at, voided_by, void_reason, created_by, timestamps, deleted_at`

`platform_contract_events` (append-only audit: created, sent, viewed, signed, declined, resent, voided,
downloaded — who/when/ip)

`platform_contract_attachments` (e.g. the debit order form PDF that rides with the agreement):
`id, envelope_id, original_name, stored_path, sha256`. Stored on the private disk (same disk/encryption path
the app uses for other private uploads — resolved during build; never public).

Merge fields (rendered once at send): `{{agency_name}} {{agency_trading_name}} {{agency_reg_no}} {{agency_vat_no}}
{{agency_address}} {{signatory_name}} {{signatory_email}} {{today}} {{go_live_date}} {{billing_start_date}}`.
Unknown field at send time = send is refused with the field named (never a blank in a contract).

### 6.3 Flow
1. **Templates** screen: list (search name; sort name/updated; filter active/archived; paginate; empty state),
   create/edit (rich-text editor already used for templates elsewhere — reuse, don't add a new one), preview with
   sample merge data, archive (soft delete) + restore.
2. **Send** (from Agency Contracts → "Send contract", or from the agency's timeline item): choose agency,
   template, optional attachment(s), signatory name/email (pre-filled from the agency's first Admin), expiry
   (default 14 days, set in Dev Settings). Preview of merged document before sending. Send emails the signer a
   link `/agency-contract/{token}` via the standard mail path (no raw mail, uses the outbound-mail guard like
   everything else).
3. **Public signing page** (`/agency-contract/{token}`, no login, noindex, throttled): shows the frozen document
   and attachments, the signer types their full name, ticks the consent statement, draws or types a signature,
   submits. Decline with a reason is available. Expired/voided/already-signed links show a plain status page,
   never the document.
4. **On sign:** status `signed`; IP, user agent, timestamp, typed name stored; a **sealed PDF** is generated
   (document + signature block + audit summary), SHA-256 stored; both the platform owner (notification through
   the AT-235 gateway) and the signer (copy of the signed PDF by email) are notified; `AgencyContractSigned` event
   fires (§9).
5. **Contracts list**: columns agency, template, status, sent, last viewed, signed. Search: agency name,
   signatory name/email, title. Sort: sent (default desc), status, agency, signed. Filter: status, agency, date
   range (sent). Pagination. Empty state. Row actions: view, resend (new token + expiry; old link dies), void
   (reason required), download signed PDF, archive/restore. **No hard delete.**

### 6.4 Why not Docuperfect, and the canon question
Docuperfect e-sign is tenant-scoped end to end (`BelongsToAgency` on documents/requests), built around
property/deal documents, FICA gates, the compliance approval gate, role blocks and per-agency letterheads. Forcing
a CoreX-to-agency contract through it would either put the contract inside HFC's agency (visible to HFC staff,
the opposite of "outside the agencies") or require bypassing the tenant scope in a pipeline-gated area. So this is a
**small, separate signing path** with its own tables. It borrows only generic, tenant-free pieces (PDF rendering,
hashing, the mail path). **It is outside ESIGN-CANON** (which governs the property-document e-sign path) —
recorded here as a deliberate divergence for Johan's information; ESIGN-CANON's §0 already distinguishes paths.
Evidence standard kept equivalent to what the product already treats as acceptable: typed name + consent + IP +
timestamp + document hash + append-only audit.

---

## 7. Part B — Agency Timeline

### 7.1 Concept
A timeline is made of two kinds of item, both per agency and both editable:

* **Info blocks** — narrative sections with a heading and body (e.g. "Where we are", "Training going forward",
  "What we need from you", "Finances"). Ordered. No date.
* **Milestones** — dated items (e.g. "Take-on questionnaire completed", "CRM data exported and sent to us",
  "Agency goes live"). Each has a title, optional description, a due date, a status, and exactly one milestone per
  timeline can be flagged **Go live**.

Text in both supports merge fields `{{agency_name}} {{go_live_date}} {{billing_start_date}} {{start_date}}`
so the default text reads "Caprivi live on CoreX" for Caprivi without anyone editing it.

### 7.2 Dev Settings → "Agency timeline defaults"
New section in `admin/dev-settings`. Stored as the default template (own tables below, not a JSON blob in
`dev_settings`, because it is ordered, edited item-by-item and audited):

* **Default info blocks**: title, body, order, shown publicly (yes/no).
* **Default milestones**: title, description, **offset in days from the start date** (0 = start date; negative
  not allowed), order, shown publicly (yes/no), is-go-live flag (exactly one), optional **auto-complete trigger**
  (`none | contract_signed | setup_wizard_completed`).
* Add / edit / reorder / archive / restore (soft delete) each default.
* Seeded on first install with Johan's example (Where we are → Finances; milestones: questionnaire +3d … live
  +27d etc.) as a migration/seeder via `deploy:sync-reference-data`-safe idempotent code, so staging and live
  carry it. Offsets in the seed are Johan's to confirm — see §13.

Changing defaults never rewrites a running timeline (§7.3 snapshots).

### 7.3 Data model

`agency_timeline_default_items`
`id, kind (block|milestone), title, body, sort_order, offset_days (nullable for block), is_public (bool),
is_go_live (bool), auto_complete_trigger (nullable string), timestamps, deleted_at`

`agency_timelines`
`id, agency_id (unique among non-deleted), token (unique, random 48, the public link), start_date (date),
status (running|live|paused), public_link_enabled (bool, default true), started_by, live_at, timestamps, deleted_at`

`agency_timeline_items` — **a snapshot copy** of the defaults taken at start, then freely editable:
`id, timeline_id, kind, title, body, sort_order, due_date (nullable), offset_days (kept for "reset to default"),
is_public, is_go_live, auto_complete_trigger, status (pending|done|skipped), completed_at, completed_by,
completed_source (manual|contract_signed|setup_wizard_completed), is_custom (bool — added for this agency, not
from defaults), source_default_id (nullable), timestamps, deleted_at`

`agency_timeline_events` — **the record** ("the timeline should be recorded"): append-only audit of every
change: started, item added/edited/date-moved (old → new)/completed/reopened/skipped/archived/restored, link
enabled/disabled/regenerated, status changes. Who, when, before/after.

`due_date = start_date + offset_days` at snapshot time. Moving a date manually keeps the offset untouched and
logs the move. Changing the **start date** afterwards offers "shift all not-yet-done dated items by the
difference" (default on) — every shift is logged.

### 7.4 Owner screens

**Agency Timeline (index)** — every agency, one row: agency, status (Not started / Running / Live / Paused),
progress (done/total milestones), next due item, overdue count, start date, go-live date, actions:
**Start timeline** (for agencies without one), Open, Copy public link. Search: agency name. Sort: agency (default),
go-live date, start date, overdue count. Filter: status. Pagination. Empty state. Demo agencies are listed but
marked; archived (soft-deleted) agencies excluded.

**Start timeline** — modal/page: start date (defaults to the agency's **creation date**, editable), preview of the
resulting dates for every default milestone, confirm. One timeline per agency; a second Start is refused with a
message pointing at the existing one.

**Agency timeline detail** — (the owner's working view of one agency)
* Header: agency, start date, go-live date, progress bar, status, public link (copy / open / disable / regenerate).
* The info blocks and the milestone list in order, same as the public page plus owner-only controls:
  mark done / reopen / skip, edit title/description/date, show/hide on public page, reorder, **Add custom
  milestone** and **Add custom info block** (this agency only), archive/restore any item, **Reset to default dates**.
* **Contract panel**: the agency's contract envelopes and status with a **Send contract** shortcut (Part A).
* **History** tab: the `agency_timeline_events` log.
* Mark **Live**: sets timeline status Live and stamps `live_at` (does NOT flip any agency feature or billing —
  it is a record, not a switch).

### 7.5 Public page `/agency-timeline/{token}`
Read-only, no login, `noindex`, `Cache-Control: no-store`, throttled, shows **only**: agency name (and logo if
set), the public info blocks, the public milestones with status — **Done** (with date), **Next**, **Upcoming**,
**Overdue** — a progress indicator, and the go-live date. Never shows: users, emails, contract content,
hidden items, internal notes, other agencies, the token of anything else. Disabled, regenerated (old token
dies) or unknown token → one neutral "This link is no longer active" page (same response for unknown and
disabled, so tokens can't be probed). Mobile-first (it will be opened on phones inside agency offices).
Uses the CoreX UI design system (`UI_DESIGN_SYSTEM.md`); no admin chrome.

---

## 8. API / routes (Non-negotiable #7)
All owner screens are server-rendered web routes (no JSON data endpoints, so nothing hidden outside the catalog).
Any later JSON need goes under `/api/v1/*` with `->name()`.

```
admin/dev-settings?s=timeline_defaults           GET/PUT + item CRUD   (extends DevSettingsController, owner_only)
admin/agency-timelines                            index, start, show, item CRUD, link toggle/regenerate (owner_only)
admin/agency-contracts                            templates + envelopes CRUD, send, resend, void, download (owner_only)
agency-timeline/{token}                           public read-only (throttled)
agency-contract/{token}                           public signing (throttled): show, sign, decline
```

## 9. Domain events (Non-negotiable #9)
Read `.ai/specs/corex-domain-events-spec.md` and register in the catalogue before building:
`AgencyTimelineStarted`, `AgencyTimelineMilestoneCompleted`, `AgencyContractSent`, `AgencyContractSigned`,
`AgencyContractDeclined`.
Listeners: `AgencyContractSigned` → completes every open timeline item for that agency with trigger
`contract_signed`; the setup wizard's completion (existing completion point in `AgencySetupWizardController::finish`)
emits `AgencySetupWizardCompleted` (new, tiny) → completes items with trigger `setup_wizard_completed`. Auto-completions
are logged with their source. No ad-hoc observers.

## 10. Setup Wizard (Non-negotiable #10a)
**Not applicable.** None of this is a setting an agency configures; it is platform-owner tooling. Recorded here so
the omission is on the record, not an oversight.

## 11. Multi-tenancy / scoping (Non-negotiable #8-full-CRUD floor)
* Every screen is owner-only. There is no agency-user surface, so own/branch/agency scoping is "owner sees all
  agencies" by design, enforced by middleware **and** per-action `isOwnerRole()` aborts, never by hidden links.
* Public pages resolve exactly one record by token and expose only whitelisted fields.
* Models do **not** use `BelongsToAgency` (they are platform-owned and an agency-scoped query would hide them
  from the owner when switched into an agency); every owner query is explicit about `agency_id`. A test proves a
  non-owner (agency Admin) gets 403 on every route and cannot reach the public tokens of others.
* No hard deletes anywhere: templates, envelopes, defaults, timelines and items all soft delete with Restore.

## 12. Acceptance criteria
1. Dev Settings has an "Agency timeline defaults" section; defaults can be added, edited, reordered, archived,
   restored; exactly one go-live milestone enforced.
2. Starting a timeline for an agency (start date defaulting to its creation date) creates items with
   `due_date = start + offset`, a unique public link, and a logged `started` event.
3. Changing a default afterwards does not alter an already-started timeline.
4. Owner can add a custom milestone and a custom info block to one agency's timeline; it appears on the public
   page unless hidden; it never appears on another agency's.
5. Public link shows done/next/upcoming/overdue correctly for a fixed "today"; hidden items absent; disabled or
   regenerated link serves the neutral page; no login needed; no agency/user data leaks.
6. Every change to a timeline is in its History log with who/when/before→after.
7. A contract template can be created, previewed, archived, restored; an unknown merge field blocks sending.
8. Sending a contract emails the signer a link; the public signing page shows the frozen document; signing stores
   the evidence, produces a sealed PDF whose hash matches, emails the signer a copy, notifies the owner.
9. Signing ticks the timeline's contract item automatically and logs the source.
10. Resend kills the old link; void kills the link; expired/voided/signed links never show the document.
11. Contracts list and Agency Timeline index have the search/sort/filter/pagination/empty states in §6.3/§7.4.
12. Agency Admin (non-owner) receives 403 on every owner route.
13. Sidebar entries exist under System Developer the same day; no new entry visible to non-owners.

## 13. Open business questions for Johan (everything else is decided above)
1. **Default dates.** Your example has fixed calendar dates (5 Oct, 12 Oct, 15 Oct, 1 Nov). I'm turning them into
   "days after start" in the seed — which day-counts do you want for each step? (I'll seed a first guess from the
   example: questionnaire +3, CRM export +10, import/setup +13, team working +13→+29, live +30, billing starts
   the day after live. Tell me if that's wrong and I'll change the seed.)
2. **Overdue wording on the public page.** Should the agency see an item flagged "Overdue" in red, or just
   "Past due date"? (Overdue is visible to everyone in their office.)
3. **Who gets the contract.** I'm assuming the agency's first Admin (the one created with the agency), editable
   at send time. Correct?

## 14. Files to create / modify
New: migrations for the 8 tables; models (`AgencyTimeline`, `AgencyTimelineItem`, `AgencyTimelineDefaultItem`,
`AgencyTimelineEvent`, `PlatformContractTemplate`, `PlatformContractEnvelope`, `PlatformContractEvent`,
`PlatformContractAttachment`); services (`AgencyTimelineService` — start/snapshot/shift/complete/log;
`PlatformContractService` — merge/send/seal/void); controllers under `Admin\` + `Public\`; events/listeners;
Blade views (owner screens + 2 public pages); mailables (contract invite, signed copy); seeder for defaults;
tests (feature: start/snapshot/shift/public visibility/403s/sign flow/auto-complete; unit: offset math, merge-field
refusal).
Modify: `routes/web.php`, `corex-sidebar.blade.php`, `DevSettingsController` + `dev-settings/index.blade.php`,
`AgencyController@store` success message, `AgencySetupWizardController@finish` (event only),
`.ai/CHAT_STARTER.md`, `.ai/CODEBASE_MAP.md`.

## 15. Build phases (each independently shippable and tested)
1. Timeline defaults (Dev Settings) + start + owner detail + history. 2. Public timeline page + link controls.
3. Contract templates + send + public signing + sealed PDF. 4. Event wiring (contract/wizard → timeline) + sidebar polish.
