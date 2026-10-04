# Rental Renewals (AT-444)

**Status:** SPEC ONLY. Approved design, Johan, 4 Oct 2026.
**Ticket:** AT-444 (instruction states "Ties to AT-428" — **no spec file for AT-428 was found in
`.ai/specs/` by either investigation pass**; see Open Questions §9 — this spec proceeds on AT-444's
own instruction and does not block on resolving that reference).
**Date:** 2026-10-04. **Pillar:** Property, Contact (tenant, landlord), Agent (`User`), and the
e-sign system (Docuperfect) as an attachment point, not a new pillar.
**Master spec:** `.ai/specs/rentals-rebuild.md`. **Builds on:** `leases.md` (lease model, escalations,
`LeaseSetting`), `leases.md` §12 (Lease Hub lifecycle strip's "Renewal/notice" node), the existing
e-sign importer (unnamed in the task brief by file path — see §2).

---

## 1. What this stage does and why
Today `CheckLeaseExpiry` fires tiered alerts off the wrong table (audit Part 1 item E / Part 5
top-gap #2) and no renewal draft is ever prepared automatically. This stage fixes the wrong-table
bug as a side effect of building the real renewal flow, and gives an agent a renewal draft ready to
review at the reminder date instead of a blank page.

## 2. Settings
- **Reminder lead time** — reuses the existing, already-live, already-wired `LeaseSetting::expiryNoticeWindowDaysFor()`
  (`app/Models/LeaseSetting.php:58-67`, default 60 days, already in the onboarding wizard per
  `AgencySetupWizardController.php:554`) — **no new setting for this**, it already exists and is
  already correct; this stage only repoints the command that reads it.
- **Tenant notice period** — new agency setting, `LeaseSetting::tenantNoticePeriodDaysFor()`
  (default 30 days, SA residential-lease convention — not a legal minimum CoreX enforces, just a
  sensible default an agency can change). Surfaced in the Setup Wizard alongside the existing
  reminder-lead-time control.

## 3. Fix the wrong-table bug (repoint, don't rebuild)
`CheckLeaseExpiry.php` (`signatures:check-lease-expiry`, scheduled daily 06:00) is repointed from
`Docuperfect\LeaseRecord` to `Lease`/`end_date`/`Lease::STATUS_*`, and its hardcoded 90/60/30-day
tier boundaries are replaced with `LeaseSetting::expiryNoticeWindowDaysFor($lease->agency_id)` — the
exact "smallest correct fix" the audit already identified (audit Part 1 item E). Recipient also
changes: today's `sendLeaseAlert()` resolves the owning **agent** via the e-sign document's
`owner_id` (audit item E) — once repointed at `Lease` directly (no `document` relation required),
the recipient becomes the lease's own responsible agent (`leases.agent_id` / the property's listing
agent, whichever `leases.md` already treats as canonical — not re-decided here). This stage does not
newly email tenants/landlords about expiry; that is Stage 7's notices work, kept separate.

**Sequencing guard carried over from Stage 1** (`rentals-foundation-at439.md` §2.2): this repoint
must not run while `lease_records` is still a live write path, or two independently-scheduled
expiry commands could both fire on one real lease (audit's own stated risk). Since Stage 1 retires
`Docuperfect\LeaseController::renew()`'s and `SignatureService::createLeaseRecord()`'s writes into
`lease_records` before this stage ships, that risk is closed by sequencing, not by new code here.

## 4. The e-sign importer mapping (agency-level, one-time setup, multiple templates)
Each agency loads its own lease template(s) through the **existing e-sign importer** (the same
mechanism `.ai/specs/rental-documents-spec.md` already documents the templates for — this spec does
not build a new importer) and maps the blanks **once** to CoreX fields: property, landlord(s),
tenant(s), rent, deposit, dates, escalation. **Multiple templates are allowed** per agency
(residential, commercial, renewal addendum) — each mapped independently, stored per-agency (new
table, §6).

## 5. At the reminder date — three paths, CoreX prepares what it can
When a lease enters the reminder window (§2) with no notice/non-renewal already recorded, the
Command Centre (`rental-command-centre.md` §3.2, "Renew" action) and the Lease Hub next-step card
(`leases.md` §12.2) surface a renewal draft per this decision tree:

| Condition | Path | What happens |
|---|---|---|
| (a) Current lease was e-signed through CoreX | Copy forward | New draft `Lease` row (`previous_lease_id` points at the current one) with the SAME terms, new `start_date`/`end_date` the agent edits before sending — not sent automatically (§7). |
| (b) Agency has a mapped lease template (§4) and the data needed to fill it is complete | Draft fresh | A new e-sign document is drafted from the mapped template, pre-filled from the current lease's own data; anything missing is listed explicitly to the agent, not silently blank. |
| (c) Neither (a) nor (b) | Manual | Agent uploads the signed renewal document directly and captures dates/rent/escalation by hand — no draft is attempted. |

**None of these three paths ever sends anything by itself.** The agent enters the new rent/escalation
and term (editable in all three paths, even (a)'s copy-forward) and explicitly sends — per
instruction, "CoreX never sends a lease by itself."

## 6. Data model (new)
- `rental_lease_templates` (agency_id, name, docuperfect_template_id, field_mapping JSON, is_active,
  soft-deleted) — the per-agency template mapping from §4. Full CRUD under Rentals → Settings.
- `leases.previous_lease_id` (new nullable self-referencing FK) — links a renewal term to the lease
  it replaces (§5(a), §7).
- `leases.notice_date` / `leases.notice_given_by` (nullable) — referenced by `leases.md` §12.5.3's
  transition table; this is the first stage that actually writes them (Lease Hub §12 only reads/
  displays them).

## 7. On signing — outcomes
- **Renewal signed:** new lease term row created (or the draft from §5(a)/(b) activated), linked via
  `previous_lease_id`; escalation recorded on the new row (reusing `LeaseEscalation`, already built);
  the old term's `status` set to `ended`/`renewed` and it stays on the property's occupancy history
  (`leases.md` §12.6) — never deleted, per CLAUDE.md non-negotiable #1.
- **One-click outcomes** (no e-sign cycle required for these three):
  - **Month-to-month** — lease's `end_date` cleared, a flag set marking it month-to-month (reuses
    the existing month-to-month concept named in `leases.md` §12.5.3's transition table).
  - **Tenant gave notice** — sets `notice_date`/`notice_given_by='tenant'`, triggering the
    "notice given" property-status transition (`leases.md` §12.5.3) and re-advertising with the
    availability-from date.
  - **Landlord not renewing** — same mechanism as tenant notice, `notice_given_by='landlord'`; the
    distinction matters for the tenancy log (§8) and for any future landlord-communication content,
    not for the property-status transition itself (identical outcome either way).

## 8. Lease Hub / Tenancy log integration
Every event in §5-§7 writes a tenancy-log entry (`leases.md` §12.2/§12.3) — renewal draft prepared,
renewal sent, renewal signed, month-to-month set, notice recorded (by whom) — so the Lease Hub's
lifecycle strip and tenancy log reflect renewal activity without any separate "renewals" view needed
on that screen.

## 9. Routes, nav, CRUD, permissions
- **No new top-level nav entry** — renewals are actions reached from the Lease Hub and the Command
  Centre, not a separate list screen of their own (a "renewals in progress" tile/filter already
  exists on the Command Centre, `rental-command-centre.md` §3.1).
- **New routes:** `corex.leases.{lease}.renewal.{draft,send,sign,one-click}` (action endpoints off
  the existing Lease resource, not a new resource).
- **Template settings CRUD:** `corex.rental-lease-templates.{index,create,store,edit,update,archive,restore}`
  under Rentals → Settings — full list-screen floor (search by name, sort by name/active, filter by
  active/archived, pagination, empty state) per BUILD_STANDARD §1b, same as every other settings list
  in this rebuild.
- **Permissions (new):** `leases.renew` already exists (`config/corex-permissions.php:138`) and gates
  every action in §5-§7 — no new action key needed. New: `rental_lease_templates.manage_settings`
  (gates §6's template-mapping CRUD).

## 10. API
`POST /api/v1/leases/{lease}/renewal/draft`, `/send`, `/sign`, `/one-click` (body: outcome type) —
same scope guard as every lease action, named/versioned/cataloged per CLAUDE.md non-negotiable #7.

## 11. Multi-agency
No template, no default rent/escalation wording, no renewal-notice copy may assume HFC's own lease
template or clause language — every template is agency-uploaded and agency-mapped (§4); the
one-click outcomes and the reminder settings have agency-neutral defaults (§2).

## 12. Acceptance criteria
- [ ] `CheckLeaseExpiry` reads `Lease`/`end_date`, uses `LeaseSetting::expiryNoticeWindowDaysFor()`,
      no hardcoded day boundaries remain.
- [ ] A lease e-signed through CoreX produces path (a)'s copy-forward draft correctly.
- [ ] An agency with a mapped template and complete data produces path (b)'s fresh draft, with any
      missing field listed explicitly.
- [ ] An agency with neither produces path (c)'s manual-upload flow, no broken draft attempt.
- [ ] None of the three paths sends anything without an explicit agent action.
- [ ] Signing links the new term to the old via `previous_lease_id`; the old term survives on
      occupancy history, never deleted.
- [ ] All three one-click outcomes (month-to-month, tenant notice, landlord not-renewing) correctly
      trigger the property-status transition from `leases.md` §12.5.3.
- [ ] Every renewal event appears in the Lease Hub's tenancy log.
- [ ] Template-mapping CRUD meets the full list-screen floor.

## 13. Open questions for Johan
- **AT-428 reference**: no spec file in `.ai/specs/` currently covers AT-428 — confirm whether this
  is a ticket number only (no prior spec expected) or whether an existing document under a different
  name/location should be linked here.
- **Responsible-agent resolution for the repointed expiry alert (§3)**: confirm whether `leases.agent_id`
  (if that column exists on the built `Lease` model) or the property's listing agent is the correct
  recipient — this spec assumes "whichever `leases.md` already treats as canonical" rather than
  introducing a new resolution rule; flagging in case the built model doesn't yet have an obvious
  single answer.

## 14. Built, 2026-10-04 (AT-444) — what landed, what was scoped down, two open WAIT gates

**Status correction: §3 (the `CheckLeaseExpiry` repoint) is NOT built here.** The task brief for this
build explicitly assigns that repoint to cc1/AT-439 ("the reminder lead-time setting ALREADY EXISTS and
cc1 is wiring the command to it — do not add a second one") — a direct conflict with this spec's own §3,
which the brief itself resolves in its own favour ("where it conflicts with this brief, this brief
wins"). `CheckLeaseExpiry.php` is untouched by this build. Confirm with cc1 that the repoint has
actually landed before relying on it.

**Built, verified in PHPUnit + Tinker against throwaway data:**

- **§1 term chain** — `leases.previous_lease_id`/`renewed_lease_id`/`source_document_id` already
  existed on the table (2026_09_17_090000, pre-dating this ticket — the "new column" framing in the
  original §6 below was wrong, corrected here) and `LeaseActivationService::activate()` already
  atomically expires the previous term and chains both pointers the moment a lease naming it as
  `previous_lease_id` activates. This ticket adds `App\Services\Rentals\LeaseRenewalService::
  createRenewalTerm()` (builds the new draft term, copies tenants) and `::activateRenewalTerm()`
  (records the escalation on the NEW row via the existing `LeaseEscalation`, then calls the existing
  `LeaseActivationService::activate()` — no duplicate activation logic).
- **§2 one-click outcomes + reversal** — new `leases.notice_date`/`notice_given_by`/`notice_note`/
  `move_out_date` columns (migration `2026_10_04_220000`). Month-to-month reuses the existing
  `is_month_to_month` boolean. A new append-only `lease_events` table (+ `App\Models\LeaseEvent`)
  records every outcome and its reversal — NOT derived live from the lease's own columns, because a
  reversal clears those columns and the tenancy log must still show the event happened (unlike
  escalation/cancellation, which are safe to derive live since nothing un-sets them). Property-status
  side effects are deliberately NOT wired here — that's item 7's own WAIT gate (§15 below); these
  outcomes only ever touch the Lease, never the Property.
- **§3 tenant notice period setting** — `LeaseSetting::tenantNoticePeriodDaysFor()`, default 30 days,
  surfaced in both the dedicated settings page and the Setup Wizard's existing 'leases' step (same
  saver, `has()`-guarded per §6.1).
- **§4/§5(a) copy-forward** — `App\Services\Rentals\RenewalDraftService::copyForward()`. Deliberately
  does NOT drive `ESignWizardController`'s own property/recipient auto-fill (which is property-pivot-
  centric and risks surfacing a PREVIOUS tenant on a property with lease history) — it writes a `flows`
  row directly, in the exact `step_data` shape `saveStep()` itself writes, sourced explicitly from the
  CURRENT LEASE's own tenants/landlord/terms. Verified the shape against the real `showStep()`/
  `saveStep()`/`WebTemplateDataService::resolve()` code before writing it, not guessed. No
  pipeline-gated file touched.
- **§5(c)/manual upload + §7 completion→activation** — `LeaseRenewalController::uploadRenewal()`:
  creates the new term, stores the file (reusing the existing `ValidatesDocumentUploads` allow-list),
  files it via the generic `App\Models\Document` (`source_type='lease'`, `source_id=<new term>`, plus
  the `properties()` pivot — NOT `leases.source_document_id`, which is reserved for
  `docuperfect_documents.id`, a different ID space), then activates immediately via
  `activateRenewalTerm()`. No e-sign cycle for this path, so "completion" is the upload itself.
- **§6 e-sign completion → activation — only HALF wired, flagged explicitly.** `activateRenewalTerm()`
  exists and is the correct, single call site for "a renewal term goes live" — but nothing in the
  e-sign pipeline calls it yet for the copy-forward/template-draft paths. `SignatureService::
  createLeaseFromSignedDocument()` (the method that would need to call it on a renewal document's
  completion) is the exact file cc1's "e-sign draft-promotion" work is in, per the task brief's own
  lane boundary — this build does not touch it. **Cross-lane dependency, not resolved by this
  ticket:** once cc1's draft-promotion fix lands (the "promote an existing draft Lease on this property
  instead of creating a new one" fix, Stage-1 investigation item F), it will find the draft term this
  build's `copyForward()` creates (status=draft, `previous_lease_id` already set) and should call
  `LeaseRenewalService::activateRenewalTerm()` on it rather than its own ad-hoc activation — satisfying
  "reused, not duplicated" structurally, but only once that call is actually wired on cc1's side. Until
  then, a copy-forward renewal that completes e-sign will create/promote the Lease row but NOT
  auto-activate it or record its escalation; the agent can still activate it manually via the existing
  `corex.leases.activate` action (escalation will need to be entered via the existing `escalate()`
  action in that interim case, not automatically).
- **§9 routes** — `corex.leases.{lease}.renewal.{create,draft,upload,month-to-month,month-to-month.
  reverse,tenant-notice,landlord-notice,notice.reverse}`, all gated by the existing `leases.renew`
  permission (no new key). A single small screen (`corex/leases/renewal.blade.php`) hosts the term
  entry + all one-click outcomes, reached from the Lease Hub next-step card
  (`LeaseHubService::nextStep()` now points "Review renewal"/"Record outcome" here instead of AT-440's
  own lease-edit placeholder).
- **§10 API** — `POST /api/v1/leases/{lease}/renewal/{draft,upload,month-to-month,month-to-month/
  reverse,tenant-notice,landlord-notice,notice/reverse}`, same services, same scope guard, JSON
  responses — `App\Http\Controllers\Api\V1\LeaseRenewalApiController`.

**Not built — two WAIT gates, per the task brief, pending Johan's go-ahead:**
- **Item 5 — agency lease templates + path (b)** (draft-from-template). Investigation and a narrowed
  proposed design (no `field_mapping JSON` column — unnecessary for how the real templates work) were
  reported to the conductor; nothing was built.
- **Item 7 — property status transitions** (§12.5.3's remaining rows, §12.5.4's new settings). The
  exact transition table and portal-syndication effect of each row were reported to the conductor,
  including a genuine architectural finding (`Property::OFF_MARKET_STATUSES` is a hardcoded,
  non-agency-aware PHP constant, which changes what "agency-configurable which status value" can
  actually mean for the new "notice given" status); nothing was built.

**Correction to §6 of this spec (now superseded by the paragraph above):** `leases.previous_lease_id`
was NOT a new column this ticket introduced — it already existed. Readers should treat §6's original
wording as historical/aspirational, not as the as-built schema.

**Tests**: `tests/Feature/Leases/LeaseRenewalTest.php` — 14 cases: term-chain creation + its
non-active-lease rejection, activation closing the previous term + recording/skipping escalation,
activation's own guard against a lease with no previous term, month-to-month set/reverse (history
survives reversal), tenant/landlord notice set/reverse with distinct tenancy-log wording, an invalid
`notice_given_by` rejection, notice events appearing under the tenancy log's new `notice` filter type,
copy-forward's eligibility rejection for a non-e-signed lease, copy-forward's pre-filled `Flow` shape
(recipients/details), the manual-upload path's full create→file→activate chain, cross-agency 404 on
every renewal action, and the renewal screen rendering for an authorised user.
