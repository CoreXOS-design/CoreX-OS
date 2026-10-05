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

**Correction, 2026-10-04 (Johan's ruling, same day) — expiry is never automatic.** An earlier
version of the Stage-1 repoint flipped `status` straight to `expired` the moment `end_date` passed;
an unscoped verification run of that version auto-expired three real QA1 leases. `CheckLeaseExpiry`
now only ever FLAGS an overdue lease via the alert — it never writes `status`. The status
transition is this stage's own responsibility: it happens when the agent records one of the three
one-click outcomes above (renewed via a path (a)/(b)/(c) draft, month-to-month, or notice/ended),
never from the reminder command. The command also iterates agencies explicitly rather than one
bulk query spanning all of them — the same incident's root cause.

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
- **§6 e-sign completion → activation — fully wired, 2026-10-04, once AT-439 landed on QA1.**
  `SignatureService::createLeaseFromSignedDocument()`'s draft-promotion path now checks
  `$draftLease->previous_lease_id`: if set (a renewal draft, built by `copyForward()`/
  `draftFromTemplate()`), it calls `LeaseRenewalService::activateRenewalTerm()` instead of a bare
  `LeaseActivationService::activate()` — recording the escalation as part of completion, not left for
  the agent to enter separately. The acting user is resolved from the signed document's own
  `owner_id` (no authenticated session exists in this completion-cascade context); if that resolves to
  nothing, the code falls back to the plain `activate()` call unchanged, rather than failing the
  cascade. See §15 below for the full account of this file edit (it is one of the e-sign recipient
  signing pipeline's gated files) and its test.
- **§9 routes** — `corex.leases.{lease}.renewal.{create,draft,upload,month-to-month,month-to-month.
  reverse,tenant-notice,landlord-notice,notice.reverse}`, all gated by the existing `leases.renew`
  permission (no new key). A single small screen (`corex/leases/renewal.blade.php`) hosts the term
  entry + all one-click outcomes, reached from the Lease Hub next-step card
  (`LeaseHubService::nextStep()` now points "Review renewal"/"Record outcome" here instead of AT-440's
  own lease-edit placeholder).
- **§10 API** — `POST /api/v1/leases/{lease}/renewal/{draft,upload,month-to-month,month-to-month/
  reverse,tenant-notice,landlord-notice,notice/reverse}`, same services, same scope guard, JSON
  responses — `App\Http\Controllers\Api\V1\LeaseRenewalApiController`.

**Items 5 and 7 — both gates were APPROVED by the conductor, 2026-10-04, and are built. See §15.**

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

## 15. GATE 1 (item 5) and GATE 2 (item 7) — approved by the conductor 2026-10-04, built same day

### GATE 1 — agency lease templates + path 5(b), approved exactly as proposed in §14's WAIT-gate report

New `rental_lease_templates` table: `agency_id`, `name`, `docuperfect_template_id` (no FK across the
Docuperfect boundary, same convention as `leases.source_document_id`), `category` (free string —
residential/commercial/renewal_addendum), `is_active`, soft-deletes. **No `field_mapping` column** —
confirmed unnecessary: a `render_type='pdf'` template's own `data-field` attributes already match
CoreX's canonical vocabulary; a `render_type='web'`/CDS template's mapping already lives on
`docuperfect_templates.field_mappings`, built via the existing importer.

- **Settings CRUD** — `App\Http\Controllers\CoreX\RentalLeaseTemplateController`, full list-screen
  floor (search by name, sort name/category/active — default name asc, filter by category + archived,
  pagination, real empty state, archive/restore). Nav link on the main Settings hub
  (`corex.rental-lease-templates.index`), gated by the new `rental_lease_templates.manage_settings`
  permission key. The source-document picker lists only templates this agency can see
  (`Template::applySharedWith()` — the agency's own + genuinely-ownerless platform globals, the exact
  visibility rule every other e-sign path already uses) — nothing HFC-specific hardcoded.
- **Eligibility** — `RenewalDraftService::missingRequiredFields(Lease, RentalLeaseTemplate, array $terms, User)`
  resolves the SAME `step_data` `copyForward()` builds against `WebTemplateDataService::resolve()`
  (the authoritative field-resolution service every e-sign path already uses — both the plain
  `resolve()` and its internal `resolveCdsTemplate()` branch return the same key names:
  `property_address`, `lessor_name`, `lessee_name`, `rental_amount`, `lease_start`), checks five
  required keys, returns a human-readable label per blank one. The Lease Hub renewal screen previews
  this against the lease's CURRENT data (before the agent types new terms) so an agent can see which
  templates are ready without submitting; `draftFromTemplate()` re-checks against the actually-
  submitted terms and throws (blocking "send") if anything is still blank.
- **`RenewalDraftService::draftFromTemplate()`** — same `buildDraftFlow()` as `copyForward()`, pointed
  at the agency's chosen template instead of the lease's prior document; rejects a template belonging
  to another agency or an archived one.
- **Routes**: `corex.leases.{lease}.renewal.draft-from-template` (web) and the API mirror
  `/api/v1/leases/{lease}/renewal/draft-from-template`.

**Tests**: `tests/Feature/Leases/RentalLeaseTemplateTest.php` — 7 cases covering the full CRUD/list
floor (search, category filter, archive, restore, empty state), cross-agency template rejection,
missing-fields detection, successful draft, blocked draft (missing fields), blocked draft (archived
template).

### GATE 2 — property status transitions, approved WITH Johan's change: no new property status

**The change from §14's own proposed design**: notice is a fact about the LEASE
(`leases.notice_date`/`move_out_date`), never a property status — `Property::OFF_MARKET_STATUSES` and
the agency's own `property_status` vocabulary are untouched; no status is added or removed anywhere
in this build.

**New column**: `properties.status_before_letting` (nullable string) — captured ONCE, the moment
`LeaseActivationService::flipPropertyToLeasedOut()` first flips a property to `let_out` (a no-op on a
renewal re-activation, since that method already short-circuits when the property is already
`let_out`), and cleared again once a tenancy truly ends (rows 6/7) so the next lease cycle captures
fresh rather than reusing a stale value. New `leases.notice_readvertised` (nullable boolean) — whether
row 2's tick was applied, so reversing the notice knows whether a property-status change must also be
undone.

**New service**: `App\Services\Rentals\PropertyStatusFollowsLeaseService` — every method goes through
the property's normal `save()` (never a direct `DB::table()` write), so `PropertyObserver`/
`PropertyAuditService`/the portal's own `isOnMarket()` predicate all fire exactly as a manual status
change would. No new portal-sync code anywhere in this gate.

**The full transition table, as built:**

| Row | Event | New status | Advertise? | Availability date | Built |
|---|---|---|---|---|---|
| 1 | Lease activated | `let_out` | No | — | AT-440 (unchanged) |
| 2 | Notice + readvertise ticked | the agency's `default_pre_let_status` setting, **always** — never `status_before_letting` | **Yes** | day after move-out | **This gate** |
| 2 (unticked) | Notice, not readvertised | unchanged (`let_out`) | No | — | **This gate** |
| 3 | Renewal signed | unchanged (`let_out`) | No | — | Already true — test added, no code |
| 4 | Month-to-month | unchanged (`let_out`) | No | — | Already true — test added, no code |
| 5 | End date passed, no outcome | unchanged, agent flagged overdue (Command Centre, cc2) | No | — | Deliberately never automatic |
| 6 | Out-inspection confirms vacant | `status_before_letting` (or default) | Yes | today | **This gate** |
| 7 | Lease cancelled | `status_before_letting` (or default) | Yes | cancellation date | **This gate** |

**Portal consequence of each** (no new portal-sync code anywhere — all read `Property::isOnMarket()`,
which already reads `OFF_MARKET_STATUSES`):
- Row 1/let_out: off-market on P24 and Private Property (unchanged, AT-440).
- Row 2 ticked: back on-market on both portals, with the availability date carried through
  `properties.lease_start_date` — the SAME field `Property24ListingMapper.php:133` already reads as
  `availabilityDate`, reused rather than inventing a new column. **Pre-existing gap, reported not
  fixed (non-negotiable #2)**: that P24 mapper line only fires for commercial-typed properties
  (`str_contains($ptLower, 'commercial')`), and `PrivatePropertyListingMapper.php:82` hardcodes
  `AvailableFrom` to `now()` unconditionally rather than ever reading a stored value — so a
  *residential* rental re-advertised by this gate goes back on-market correctly, but neither portal's
  existing mapper will show the real availability date for it today. Fixing those two mappers is a
  change to files outside this gate's own scope, not attempted here.
- Rows 3/4: stays off-market (`let_out`) — confirmed by test, no portal effect.
- Row 5: no change — by design.
- Rows 6/7: back on-market, but via a DIFFERENT mechanism than row 2 — see the correction below.

**Three agency settings, each independently toggle-able, default ON** (`LeaseSetting`):
`auto_readvertise_on_notice` (row 2 — also the dialog's own checkbox default),
`auto_restore_status_on_lease_ended` (row 6), `auto_restore_status_on_lease_cancelled` (row 7); plus
`default_pre_let_status`, default `'active'` — chosen deliberately: `'active'` is in
`Property::systemStatuses()` (hardcoded always-allowed for every agency, Property.php:1776) and is NOT
in `OFF_MARKET_STATUSES`, so it is safe cross-agency without depending on any agency having configured
a `'to_let'`-style item.

**Correction, found and fixed during testing — row 2 is NOT "the same mechanism" as rows 6/7, and an
earlier draft of this section wrongly described it as one shared expression:**
- **Row 2** (notice + readvertise ticked) ALWAYS uses `default_pre_let_status` directly. It never reads
  `status_before_letting` — the notice dialog's instruction is unconditional ("put this property back
  on the market"), and `status_before_letting` can itself hold an off-market value (e.g. a property let
  directly from `draft`), which would silently violate that instruction.
- **Rows 6/7** (lease ended / cancelled) restore the property's own captured `status_before_letting`,
  falling back to `default_pre_let_status` only when nothing was ever captured (a lease active before
  this feature shipped). This is "restore what it actually was," a genuinely different rule from row
  2's "put it on-market per the agency's setting."
- A regression test (`test_row2_uses_the_on_market_setting_even_when_it_differs_from_status_before_letting`)
  deliberately sets the two to different values so a future conflation of the two mechanisms fails
  loudly instead of coincidentally passing.

All four settings are surfaced in the Setup Wizard's existing 'leases' step and the Lease Settings
page, same `has()`-guarded saver pattern as every other setting on that step.

**Row 6's trigger — a model observer, not a controller edit.** `RentalInspectionController` is cc1's
file (task brief: do not touch). `App\Observers\RentalInspectionCompletionObserver`, registered via
`RentalInspection::observe()` in `AppServiceProvider` (one new line, no existing registration touched),
reacts to the model's own `updated` event (`type===out` AND `status` just became `completed`) —
file-agnostic to whichever controller/service actually performs the write, the same pattern
`PropertyObserver` already uses for `Property` itself. On fire: marks the lease `STATUS_EXPIRED` (it
was `active` with no renewal) and calls `restorePreLetStatus()`.

**Row 7's trigger** — `LeaseController::cancel()` (fair game; not off-limits), guarded on
"was this lease actually `STATUS_ACTIVE` before cancelling" so a draft cancelled pre-activation (which
never flipped the property in the first place) never touches it.

**Tests**: `tests/Feature/Leases/LeasePropertyStatusTest.php` — 16 cases: row 1's capture + flip +
`isOnMarket()`, row 2 ticked/unticked/reversed + its toggle + the row-2-vs-rows-6/7 regression test
above, rows 3/4's "no change" invariant + their `isOnMarket()` assertions, row 5's "untouched"
invariant, row 6 (fires on OUT, ignores IN) + its toggle + clearing `status_before_letting`, row 7
(fires on an active lease, no-ops on a never-activated draft) + its toggle, and the fallback
`default_pre_let_status` path when nothing was ever captured.

### Item 6 — e-sign completion → activation, now fully wired (see §14's entry above for the code)

`SignatureService.php` is one of the e-sign recipient signing pipeline's gated files
(`.ai/CLAUDE.md`'s pipeline gate). **Test**: a new case added to the EXISTING
`tests/Feature/Leases/LeaseFromSignedDocumentPromotionTest.php` (AT-439's own test for this method,
extended rather than duplicated) —
`test_a_renewal_draft_lease_is_activated_via_activate_renewal_term_and_records_escalation` proves a
renewal draft's completion activates via `activateRenewalTerm()` (old term expires, `renewed_lease_id`
chains, AND the escalation is recorded), not the bare `activate()` path the non-renewal case still
uses.

## 16. Follow-up, 2026-10-05 — Lease Hub dialogs + "one definition" model helper

The §7 one-click outcomes (month-to-month, tenant/landlord notice) and §9's "Renew lease" entry point
are unchanged in what they submit; see `leases.md` §12.11 for the full write-up of the Lease Hub's
"Lease actions" menu now opening each of these as a modal dialog instead of a card below "Open items",
and the new `?action=` URL deep-link the Command Centre (`rental-command-centre.md` §3.2/§3.3) uses.

**New model method, `Lease::renewalDrafts()` / `::hasPendingRenewalDraft()`** — the ONE definition of
"this active lease has a renewal term in progress" (a draft lease chained back to it via
`previous_lease_id`), reused by `RentalCommandCentreService`'s "Renewals in progress" tile so the tile
and the model can never drift apart. Deliberately NOT "this lease's own `renewed_lease_id` is set" —
`LeaseActivationService::activate()` only writes that at ACTIVATION time, by which point this lease is
already expired, never active, so that check could never fire for a currently active lease.

`LeaseRenewalController::recordNotice()`'s catch branch now calls `->withInput()` before `->withErrors()`
— previously only the outer `$request->validate()` call (auto-flashed by Laravel's own exception
handler) preserved entered values on failure; a business-rule rejection from the service itself
(caught manually) did not, so the Lease Hub's notice dialogs would reopen EMPTY on that failure path
instead of with what the agent typed.

## 17. Follow-up 2, 2026-10-05 — Lease Hub header/strip state representation, duplicate toast, date-input bound

Found walking a real notice recorded on QA1 lease 22 (move-out 2026-11-30, re-advertise ticked): the
dialog itself worked (saves, tenancy log entry, Command Centre "Notice given" tile incremented), but the
Lease Hub page `/corex/leases/{lease}` did not reflect the outcome anywhere else on the screen.

- **Header state marker** — `resources/views/corex/leases/show.blade.php` now computes a single
  `$leaseStateMarker` next to the status badge: `"Notice given · move-out {date}"` /
  `"Landlord not renewing · move-out {date}"` (from `Lease::hasActiveNotice()` +
  `notice_given_by`/`move_out_date`) / `"Month-to-month"` (`is_month_to_month`) / `"Renewal in progress"`
  (`Lease::hasPendingRenewalDraft()`, §16's own helper) — in that priority order, one badge, never a
  banner. A healthy lease with none of these shows no marker.
- **`LeaseHubService::lifecycle()`** — the `renewal_notice` node now also lights `'current'` when
  `hasActiveNotice()`, `is_month_to_month`, or `hasPendingRenewalDraft()` is true (previously only
  `$withinRenewalWindow`), so it reflects an outcome already on file, not only the reminder window.
- **`LeaseHubService::nextStep()`** — a new branch, checked ahead of the existing "Start in-inspection"
  one: once a lease has an active notice and no completed out-inspection, the Next: banner reads
  "Start out-inspection" and links to `corex.rental-inspections.create` with
  `['lease_id' => $lease->id, 'type' => 'out']` — the same route the in-inspection link already uses.
  Previously an active lease whose in-inspection had never been completed kept showing "Start
  in-inspection" forever, even after notice was recorded, because that branch had no notice-aware guard.
  **Found while wiring this**: `resources/views/corex/rental-inspections/create.blade.php`'s own `type`
  `<select>` only ever read `old('type')` and silently ignored the `?type=` query param both next-step
  links pass — it happened to look correct for the in-inspection link only because `TYPE_IN` is the
  first `<option>` in the DOM. Fixed to fall back to `request()->query('type')` so the out-inspection
  link actually arrives pre-selected; `old()` still wins on a failed resubmit.
- **Duplicate success message** — `show.blade.php` had its own inline `session('success')` banner
  *in addition to* the app's standard toast (`components.toast-notifications`, which already reads the
  same flash key on `DOMContentLoaded`). Removed the inline banner; the toast is the one surface now.
- **Move-out date upper bound** — `move_out_date` on the tenant-notice/landlord-notice dialogs
  (`show.blade.php`) accepted a mistyped 6-digit year typed on a keyboard (e.g. `202611-03-01`) past the
  existing `min` attribute with no `max`, only failing server-side on a bare `'date'` rule. No agency
  setting exists for "how far ahead can a move-out date be" (checked `LeaseSetting` — nothing fits; per
  CLAUDE.md non-negotiable, a window setting is reused if one exists and never invented for a one-off
  bound) — added `max="{{ now()->addYears(2)->toDateString() }}"` to both dialog inputs and
  `before_or_equal:` + the matching server-side date two years out in
  `LeaseRenewalController::recordNotice()`'s validation, as a fixed sanity bound, not a configurable
  setting.
- **Confirmed, not changed** — notice + readvertise ticked (GATE 2 row 2, §15) on QA1 lease 22 set
  `properties.status` to the agency's `default_pre_let_status` (`'active'`, the agency default — the
  QA1 agency has not overridden it) and `properties.lease_start_date` to the day after `move_out_date`
  (`2026-12-01`), logged via `PropertyAuditService`; `status_before_letting` was untouched (confirmed
  `NULL`, as row 2 never reads or writes it — only rows 6/7 do). No portal call was queued by this path
  on QA1 — QA is web-only with no queue worker (BUILD_STANDARD §8), and §15's own write-up already
  tracks the P24/PP mapper gap for a residential property's real availability date as a pre-existing,
  separately-scoped issue.

**Tests**: `tests/Feature/Leases/LeaseHubTest.php` (+8 — out-inspection next-step priority and its
suppression once completed, the `renewal_notice` node lighting for notice/month-to-month, the single
success-flash assertion, and the four header-marker cases), `tests/Feature/Leases/LeaseRenewalTest.php`
(+2 — the HTTP-level move-out-date upper-bound rejection and its in-window acceptance), and
`tests/Feature/RentalInspections/RentalInspectionListScreenTest.php` (+1 — the `?type=` query-param
preselect fix).

## 18. Follow-up 3, 2026-10-05 — auto-drafted renewal at the reminder date, landlord-contact fallback restored

Johan's ruling: the reminder lead time is already an agency setting (§2, live); once a lease enters it,
CoreX drafts the renewal itself when it has enough information to, and surfaces it on the Command
Centre's needs-action queue as "Renewal draft ready" — opening the Lease Hub's "Renew lease" dialog
pre-filled with what was already prepared, never a blank term-entry form inviting a second draft.
CoreX still never sends anything by itself. Confirmed first, per the task brief: `CheckLeaseExpiry`'s
§3 repoint (cc1/AT-439) is done — reads `Lease`/`end_date`, uses
`LeaseSetting::expiryNoticeWindowDaysFor()`, iterates agencies explicitly, never writes `status`;
nothing in this build touches that file.

- **`App\Services\Rentals\RenewalDraftEligibilityService::decide()`** — the ONE decision of which §5
  path a lease qualifies for, shared by the new command and the Command Centre's needs-action row so
  the two can never disagree: (a) `copy_forward` if `source === 'esign_document'` and
  `source_document_id` is set (unconditional, matching §5(a)'s own table — no data-completeness gate on
  this path); else (b) `draft_from_template` for the first of the agency's own active
  `RentalLeaseTemplate`s (ordered by name) whose `RenewalDraftService::missingRequiredFields()` comes
  back empty against this lease's current data; else (c) `insufficient_info`, naming every blank field
  across every template tried (or "No agency lease template configured" if the agency has none at
  all). `::defaultTerms()` — the one definition of the starting term both the command and (implicitly,
  via the same renewal screen) the agent edit from: start date = day after the current end date, end
  date left blank, same rent/deposit — identical to `_renewal-term-fields.blade.php`'s own existing
  default.
- **`App\Console\Commands\PrepareLeaseRenewalDrafts`** (`rentals:prepare-renewal-drafts {--lease=}`),
  scheduled `dailyAt('06:15')` right after `signatures:check-lease-expiry` (`routes/console.php`). Same
  shape as `CheckLeaseExpiry`: iterates agencies explicitly (console commands run with no authenticated
  user, so `Lease`'s `AgencyScope` is a no-op regardless), reads `LeaseSetting::
  expiryNoticeWindowDaysFor()` per agency, and only ever creates a NEW draft lease/flow row — never
  writes `status` on the lease being renewed. Skips (idempotent, one open draft per lease): a lease with
  notice already given (either party), already month-to-month (`Lease::hasPendingRenewalDraft()` is the
  dedup check — the SAME definition `Lease::renewalDrafts()`'s "renewals in progress" tile already
  uses), or with no `createdByUser` to draft as (logged, skipped, never fails the run). For
  `insufficient_info`, nothing is persisted — the Command Centre computes the same decision live and
  names the gap there; storing it here would just be a second place for that text to go stale.
  `--lease=<id>` restricts a run to one lease (still the real window/skip checks, just scoped to one
  row) — the sanctioned way to verify this against real QA1 data without scanning every lease in the
  agency's book.
- **`RentalCommandCentreService::queueItems()`** — rule A's row (every lease in the reminder window)
  now branches: `Lease::hasPendingRenewalDraft()` → type `renewal_draft_ready`, label "Renewal draft
  ready", detail names the drafted rent; else, for a lease that isn't already excluded by notice/
  month-to-month, `decide()` is checked live and an `insufficient_info` result swaps the existing row's
  detail to "Missing: …" (type/label stay `review_renewal`/"Review renewal" — nothing has been drafted
  yet, so the action is still the same one); otherwise the row is exactly what it always was ("Tenant:
  …"). A lease with notice/month-to-month already on file is never run through `decide()` at all here —
  it isn't eligible for a draft either way, and checking would risk mislabelling it "missing info" when
  it simply isn't renewing.
- **Lease Hub "Renew lease…" dialog** (`show.blade.php`) — when `Lease::renewalDrafts()->first()` finds
  a pending draft, the dialog shows its rent/start date and a "Review draft" button straight into
  `docuperfect.esign.step` (the draft's own `renewal_draft_flow_id`, step 2 — already pre-filled by
  `RenewalDraftService::buildDraftFlow()`), alongside "Start a different renewal" (unchanged link to
  the renewal screen, for the rare case the auto-draft isn't what the agent wants). With no pending
  draft, the dialog is exactly what it was — "Continue to renewal" into the blank term-entry screen.
- **Landlord-contact fallback restored, through `Lease::landlordContacts()` only — no second method.**
  The 2026-10-04 QA1 outage fix (`96b4f3ca0`) that resolved a duplicate-declaration 500 by keeping one
  of two merged `landlordContacts()` versions flagged, in its own commit message, that the version it
  dropped carried a single-contact `Property::sellerOwnerContact()` fallback neither remaining caller
  had replaced — an emergency pick to stop a crash, not a design decision against the fallback. Now: if
  the canonical landlord/lessor-pivot query (`contactsForRole('landlord')` + `('lessor')`) comes back
  empty, `landlordContacts()` falls back to the property's `sellerOwnerContact()` (seller/owner tagged,
  or the property's only contact at all) as a single-item collection; any tagged landlord/lessor is
  returned as-is, never merged with the fallback. Fixes all three flagged callers at once, since all
  three only ever called this one method: `LeaseController::show()`'s Lease Terms card,
  `RentalDocumentPdfService::leaseTenancyReportPdf()`, and the shared `rental-context-bar` component.

**Deliberately NOT built this round** (reported, not attempted, per the task brief):
- **Notice letters to tenant/owner** — AT-445 (another lane, Rental Notices) owns this; not touched.
- **P24/Private Property availability-date mapper gap** (§15) — portal feed output, Johan raising
  directly; left exactly as flagged.

**Tests**: `tests/Feature/Leases/LeaseRenewalDraftAutomationTest.php` (new, 9 cases — copy-forward and
template-draft creation, insufficient-info reporting with nothing persisted, idempotency on a second
run, the three skip conditions (notice given, month-to-month, outside window), `--lease` scoping, and
the eligibility service's own path-a-over-path-b priority), `tests/Feature/Rentals/
RentalCommandCentreServiceTest.php` (+2 — the `renewal_draft_ready` row and the missing-info detail
text), `tests/Feature/Leases/LeaseHubTest.php` (+4 — the landlord-contact fallback with and without a
tagged landlord, and the Renew dialog's two states), all passing alongside their full existing files
(no regressions) and `tests/Feature/Leases/RentalContextBarLandlordChipTest.php` (existing, re-verified
unaffected).

## 19. Follow-up 4, 2026-10-05 — three-way notice outcome, "change outcome" later, availability-date mapper gap closed

Johan's ruling, 5 Oct 2026, on top of §15 GATE 2 row 2 and §17/§18's "readvertise" checkbox:

1. **The dialog's pre-ticked "Put this property back on the market" checkbox is gone.** The agent now
   picks exactly ONE of three every time, nothing pre-selected, and the dialog cannot be confirmed
   without a choice (HTML `required` radios client-side, `required|in:` server-side):
   (a) **Readvertise** — put back on the market, available from the day after move-out (row 2's
   existing behaviour, unchanged mechanism);
   (b) **Withdraw** — the property is lost/taken back; uses the EXISTING `withdrawn` status (already
   in `Property::OFF_MARKET_STATUSES`/`systemStatuses()` — no new status anywhere), through the same
   save()-driven mechanism as (a) (`PropertyStatusFollowsLeaseService::withdrawOnNotice()`/
   `reverseWithdraw()`);
   (c) **Leave as is** — no property write at all (identical to the old unticked behaviour).
   `leases.notice_readvertised` (boolean) is replaced outright by `leases.notice_outcome` (string:
   `readvertise`/`withdraw`/`leave`) — a boolean could only ever represent two of the three states.
   The choice is recorded on the lease, in `lease_events` (`TYPE_NOTICE_RECORDED`'s own metadata/
   description), and surfaces under the tenancy log's existing `notice` filter type.
2. **Timing — confirmed, not invented**: Withdraw applies IMMEDIATELY, same as readvertise already
   did (row 2's own pre-existing timing) — no new timing rule. Neither one-click outcome is deferred
   to the move-out date; only rows 6/7 (lease actually ending) are date-driven, unchanged.
3. **"Change the choice later"** — `LeaseRenewalService::changeNoticeOutcome()` (new), reached from
   the Lease Hub's "Lease actions" menu via a new "Change notice outcome…" dialog (menu-only, like
   Reverse notice/Cancel — never `?action=`-triggerable) and its own route
   (`corex.leases.renewal.notice.change-outcome` / API mirror). Reverses whichever property effect the
   CURRENT outcome applied (if any), applies the new one, and logs
   `LeaseEvent::TYPE_NOTICE_OUTCOME_CHANGED` — `move_out_date`/`notice_given_by` are untouched, only
   the outcome changes.
4. **"Show available-from date on portals"** — new `properties.show_available_from_on_portals`
   boolean (default **true** — matches both mappers' own pre-existing, always-on behaviour, so an
   agency only sees a change if it explicitly turns this off). Surfaced in TWO places: (i) a sub-option
   on the readvertise choice in the notice dialog (default ticked), which persists straight onto this
   same property setting in the same `save()`; (ii) a standalone checkbox on the property's Rental
   Details tab (`corex.properties.rental-details.update` → `PropertyController::updateRentalDetails()`),
   same unchecked-checkbox-means-false rule as `has_deposit`/`water_included`/etc. on that same form.
   **This is a single setting governing the field everywhere `lease_start_date` is read as "available
   from" — not notice-flow-specific.**

**Investigation finding, BEFORE any mapper change (per instruction) — the "gap" §15/§18 flagged was
only half right:**
- **Property24** (`Property24ListingMapper.php`) actually has TWO consumers of `lease_start_date` as
  an availability date, not one: `commercialInfo.availabilityDate` (commercial-typed only, the line
  §15/§18 flagged) **and** a listing-type-agnostic top-level `occupationDate` that already fires for
  ANY listing type whenever `lease_start_date` is set (pre-dates this ticket — `bd5204ac0`, 2 Aug
  2026). So P24 was **already correctly receiving a residential rental's availability date** via
  `occupationDate` — §15/§18's claim that "neither portal's mapper will show the real availability
  date for a residential rental" was wrong for P24 specifically; it missed this second field. Both
  consumers are now gated behind the single `shouldSendAvailableFrom()` helper (new private method)
  so the one setting governs both.
- **Private Property** (`PrivatePropertyListingMapper.php`) was the real, confirmed gap: `AvailableFrom`
  (a REQUIRED WSDL struct field — PP's contract has no "omit this field" option) was hardcoded to
  `now()->format(...)` unconditionally, for every listing type, every time. Now
  `PrivatePropertyListingMapper::resolveAvailableFrom()` (new public static method, same convention as
  the existing `resolveListingDate()`) reads `lease_start_date` when the setting allows it and a date is
  actually set; otherwise falls back to `now()` exactly as before. Because the field can never be
  omitted, "when off, send nothing extra" means PP falls back to its pre-existing default, not a blank
  field.

**Nothing is pushed to any real portal from this build or from QA1 while this is tested** — confirmed,
not assumed:
- The build itself never calls either mapper's `map()` or any syndication service — it only changes
  what a LATER, pre-existing push would read.
- `PropertyObserver` DOES auto-fire a real, synchronous (non-queued) `Property24ApiClient` call the
  moment `properties.status` changes on a property with `p24_syndication_enabled` + a real `p24_ref`
  (`app/Observers/PropertyObserver.php` ~L720-804) — this is pre-existing (row 1/2's own mechanism,
  not new here), and it is **not gated by whether a queue worker is running**, since it never goes
  through the queue at all.
- `.ai/BUILD_STANDARD.md` §8 states outbound is "neutralised on QA" and names mail/WAHA/PP/Firebase as
  blanked — **it does not name Property24 explicitly**, and no code-level `APP_ENV`/environment guard
  exists anywhere in `Property24ApiClient`/`PrivatePropertyListingMapper` (checked, not assumed) — the
  neutralisation (if it covers P24) must be a QA1-database-level credential wipe, which this build
  cannot verify from a worktree with no access to the real QA1 database.
- Separately, `scripts/qa-deploy.sh` (L63, L324-325) restarts a real systemd queue worker
  (`corex-qa1-queue`) on every deploy — directly contradicting `BUILD_STANDARD.md` §8's own "QA is
  web-only (no queue worker/scheduler)" claim. Not touched (outside this ticket's scope), reported here
  because it's exactly the kind of fact Johan asked this build to confirm, not assume.
- **Flagged to Johan, not fixed**: before relying on "QA1 can't reach a real portal," confirm directly
  on the QA1 host whether the agencies there actually carry live P24 credentials/`p24_ref`s, since the
  synchronous status-push path means a readvertise/withdraw choice CAN reach a real P24 endpoint on any
  property that does.

**Also flagged, not changed (same reasoning as non-negotiable #2 — report, don't fix outside scope)**:
`LeaseSetting::autoReadvertiseOnNoticeFor()`/`auto_readvertise_on_notice` (§15's own setting) had
exactly one consumer — defaulting the old checkbox's tick — which this ruling removes outright ("nothing
pre-selected, ever"). The setting/column/getter/Setup-Wizard-and-Settings-page controls are left exactly
as they were; removing them is a bigger, separate call than this ticket's explicit scope. It is now a
setting with no behavioural effect on this flow.

**Tests**: `tests/Feature/Leases/LeaseRenewalTest.php` (+6 — missing-outcome-choice HTTP rejection,
unrecognised-outcome HTTP rejection, invalid-outcome service rejection, change-outcome HTTP
accept/reject), `tests/Feature/Leases/LeasePropertyStatusTest.php` (+6 — withdraw sets `withdrawn`
immediately and leaves `lease_start_date` untouched, withdraw reversed by reverse-notice, the
available-from-on-portals tick persisting onto the property, change-outcome reversing the old effect
and applying the new one, change-outcome's active-notice guard, change-outcome's invalid-value guard;
existing boolean-arg call sites updated to the new string outcome, no behaviour change),
`tests/Unit/Services/Rentals/LeaseActionDialogResolverTest.php` (+3 — change-notice-outcome reopens on
error only with an active notice, never `?action=`-triggerable),
`tests/Unit/Syndication/Property24AvailableFromGateTest.php` (new, 4 cases — the exact gate both
`occupationDate`/`commercialInfo.availabilityDate` call),
`tests/Unit/PrivateProperty/PpAvailableFromResolutionTest.php` (new, 4 cases, same convention as the
existing `PpListingDateResolutionTest`), `tests/Feature/Properties/ShowAvailableFromOnPortalsSettingTest.php`
(new, 3 cases — DB column default, tick persists, unchecked-checkbox-means-false), all passing alongside
their full existing files.

**Pre-existing test bug found, NOT fixed (outside this ticket's scope, per non-negotiable #2)** —
`tests/Feature/Leases/LeaseActionsMenuTest.php:87-88` (`test_an_active_notice_swaps_the_two_notice_actions_for_reverse_notice`,
introduced `120658d99`, 2026-10-05, unrelated to this ticket) asserts the Lease Hub page never shows the
literal words "Tenant gave notice"/"Landlord not renewing" once a notice is active — but the tenancy
log panel on that SAME page (`show.blade.php:349`, `{{ $entry['description'] }}`) has always rendered
`LeaseRenewalService::recordNotice()`'s own event description verbatim, and that description has always
been built as `"{$who} — move-out …"` where `$who` is literally `'Tenant gave notice'`/`'Landlord not
renewing'` — unchanged by this ticket. The assertion was wrong from the day it shipped; reproduced on a
clean checkout before touching this file.

**Johan's ruling on the three flags above, 2026-10-05 — all three actioned:**

1. **`auto_readvertise_on_notice`** — confirmed, not assumed: **none of this gate's four settings
   (`auto_readvertise_on_notice`, `auto_restore_status_on_lease_ended`,
   `auto_restore_status_on_lease_cancelled`, `default_pre_let_status`) were ever actually wired into
   any screen.** `resources/views/corex/settings/leases.blade.php` renders exactly four fields
   (`expiry_notice_window_days`, `show_lease_type_field`, `default_deposit_months`,
   `tenant_notice_period_days`) — none of GATE 2's own settings. `config/agency-onboarding-copy.php`
   has zero matches for any of the four either. §15's own closing line ("All four settings are
   surfaced in the Setup Wizard's existing 'leases' step and the Lease Settings page") was never
   true — a documentation error in this spec, not a regression. There is therefore nothing to
   "retire from the settings screen" — no code change made; this correction is the fix. The column
   and getter stay exactly as they are, per the ruling.
2. **`LeaseActionsMenuTest.php` stale-test fix** — landed in a separate commit (not this one), per
   ruling. Scoped the two `assertDontSee()` calls to the menu button's own raw-HTML text (including
   its trailing `&hellip;`), which the tenancy-log description never carries — the log entry still
   renders, correctly, and the test no longer collides with it.
3. **QA1 real-portal-push risk — investigated read-only on the QA1 host itself, 2026-10-05, nothing
   triggered:**
   - **P24 cannot reach production from QA1.** `Property24ApiClient::__construct()` takes its
     `baseUrl` EXCLUSIVELY from the global `config('services.property24_syndication')['api_url']` —
     never per-agency, regardless of whether an agency has its own `p24_username`/`p24_password`
     stored. QA1's `.env` has no `P24_EXDEV_API_URL` override, so the config falls through to its own
     default: `https://api.exdev.property24-test.com` (P24's own test environment) — confirmed via
     `grep -c` against `.env`, no value ever printed. Every P24 call from QA1, from any agency, goes
     to P24's test environment, never production.
   - **Private Property is fully inert on QA1**: `PP_USERNAME`/`PP_PASSWORD` are blank in `.env`
     (confirmed by key presence, not by printing values) and `PP_WSDL` points at
     `services.sandbox.pp.co.za` — PP's own sandbox endpoint.
   - **No outbound P24/PP activity in the last 7+ days**: every `storage/logs/property24-*.log` and
     `private_property-*.log` file on QA1 is either 0 bytes or last written **2026-09-25** (rotation
     of 2026-09-23's content) — nothing from 2026-09-28 onward, and `laravel.log` has zero
     P24/PrivateProperty-related lines in that window either.
   - **Correction to this spec's own earlier claim**: a queue worker IS running on QA1 right now
     (`systemctl status corex-qa1-queue` — active), and `scripts/qa-deploy.sh` restarts it on every
     deploy. `BUILD_STANDARD.md` §8's "QA is web-only (no queue worker/scheduler)" is **not** true
     today. This does not change the outbound-safety conclusion above (the sandboxed destination is
     what protects QA1, not the worker's absence) — but the "no queue worker" reasoning this spec
     leaned on earlier (§17/§18) was never the real safety mechanism and should not be relied on.
   - **Net: QA1 cannot reach a real/production Property24 or Private Property endpoint today.** The
     protection is the sandboxed base URL/WSDL and blank/non-production credentials, not the absence
     of a queue worker.
   - **Credential-handling note**: an early investigation command (`cat .env | grep ...`) printed one
     real secret in full before the redaction pattern was corrected — `P24_IMAP_PASSWORD` (an IMAP
     mailbox credential used for P24's lead-import email parsing, unrelated to the P24 syndication API
     credentials discussed above). Flagged immediately when found; that key should be rotated. No
     other command in this investigation printed a credential value — every check after was
     key-name/`grep -c`/log-metadata only.

## 20. Follow-up 5, 2026-10-05 — "Cancel renewal draft"

A renewal draft (a DRAFT lease chained to its term via `previous_lease_id` — created either by the
agent via "Renew lease" or auto-drafted by `rentals:prepare-renewal-drafts`, §5/§18) previously had no
way out except activation. An agent who no longer wants that draft (terms fell through, owner decided
to sell, tenant withdrew) could only let it sit there, or activate it anyway.

**Built**: "Cancel renewal draft" — requires a reason, soft-cancels the draft (`status` →
`cancelled`, `cancelled_at`/`cancelled_by_user_id`/`cancel_reason` set — never a hard delete, same as
every other lease cancellation), and logs `LeaseEvent::TYPE_RENEWAL_DRAFT_CANCELLED` on **both** leases
— the draft itself, and the lease it was drafted from — so either lease's own tenancy log shows what
happened and why. New service method: `LeaseRenewalService::cancelRenewalDraft()`. New route/action:
`corex.leases.renewal.cancel-draft` → `LeaseRenewalController::cancelDraft()`, gated by `leases.renew`
(the same key every other renewal action uses — no new permission key) and the existing
`guardRentalRecordScope()` agency/branch check.

**Reachable from three places, all pointing at the SAME confirmation dialog on the draft's own Lease
Hub page** (deliberately not duplicated three times):
1. The draft lease's own page — "Lease actions ▾" shows "Cancel renewal draft…" instead of the
   ordinary "Cancel lease…" whenever the lease being viewed is itself a draft chained via
   `previous_lease_id` (`Lease::STATUS_DRAFT` + `previous_lease_id` set). An ordinary draft/active
   lease's menu and modal are unchanged.
2. The CURRENT (active) lease's "Renew this lease" dialog — when CoreX already has a pending draft
   (`$pendingRenewalDraft`), a new "Cancel renewal draft…" link sits alongside the existing "Start a
   different renewal"/"Review draft" links and takes the agent straight to the draft's own page.
3. The Command Centre's "Renewals in progress" row actions — a new "Cancel renewal draft" link,
   shown only when the row's `pending_renewal_draft_count > 0`, resolving the draft's id via a new
   correlated-subquery column (`pending_renewal_draft_lease_id`, same WHERE as the existing
   `pending_renewal_draft_count`) added to `RentalCommandCentreService::derivedPropertyQuery()`.

**Tile movement — no new code needed, by design.** "Renewals in progress" and "Expiring in window" are
each independently derived from the same row (`pending_renewal_draft_count > 0` vs. `active_end_date`
within the agency's reminder window) — they are NOT mutually exclusive buckets a lease is "moved"
between. Cancelling the draft simply makes `Lease::hasPendingRenewalDraft()` false again (its
`renewalDrafts()` relation only counts `status = 'draft'`), so the property drops out of "Renewals in
progress" on the next read; it was already counted in "Expiring in window" the whole time if its end
date was in the window, and stays there, cancellation or not.

**`rentals:prepare-renewal-drafts` never silently re-creates a draft the agent cancelled.** New
`Lease::hasCancelledRenewalDraft()` (mirrors `hasPendingRenewalDraft()` exactly, but checks
`status = 'cancelled'` instead of `'draft'`) is a second skip condition in the command, alongside the
existing "already has an open draft" check. This is permanent, not time-boxed — once an agent has
explicitly cancelled a draft for a term, the automated command never drafts another one for that same
term on any future run. The one way to get a new draft after a cancellation is the agent's own manual
"Renew lease" action (`LeaseRenewalService::createRenewalTerm()`), which does not check this and is
unaffected — multiple draft leases on the same property (one cancelled, one new) were already allowed
to coexist per leases.md §3.5.

**Files touched**: `app/Models/Lease.php` (`cancelledRenewalDrafts()`/`hasCancelledRenewalDraft()`),
`app/Models/LeaseEvent.php` (`TYPE_RENEWAL_DRAFT_CANCELLED`), `app/Services/Rentals/
LeaseRenewalService.php` (`cancelRenewalDraft()`), `app/Http/Controllers/CoreX/
LeaseRenewalController.php` (`cancelDraft()`), `routes/web.php` (new route inside the existing
`{lease}/renewal` + `leases.renew` group), `app/Console/Commands/PrepareLeaseRenewalDrafts.php` (new
skip condition + counter), `app/Services/Rentals/RentalCommandCentreService.php`
(`pending_renewal_draft_lease_id` column), `resources/views/corex/leases/show.blade.php` (menu/modal
branch + renew-dialog link), `resources/views/corex/rentals/command-centre/index.blade.php` (row
action). Tests: `tests/Feature/Leases/LeaseRenewalDraftCancellationTest.php` (new — soft-cancel,
dual-lease logging, rejects a non-renewal-draft lease, HTTP reason-required/success, cross-agency
404, command skip, manual-renew-still-works — the latter two exercising
`rentals:prepare-renewal-drafts` and `createRenewalTerm()` directly rather than duplicating
`LeaseRenewalDraftAutomationTest.php`'s own fixtures) plus one addition to the existing
`RentalCommandCentreServiceTest.php` proving the tile movement.

## 21. Follow-up 6, 2026-10-05 — REVERTED: no automatic renewal drafting; "Renew lease" is the only way in

**Johan's ruling, 2026-10-05, supersedes §18's own ruling of the day before: no automatic process
generates leases. A renewal starts ONLY when a user clicks "Renew lease".** §18's "CoreX drafts the
renewal itself once a lease enters the reminder window" behaviour is retired, same day it shipped.

**What changed:**
- **`rentals:prepare-renewal-drafts` removed from the scheduler** (`routes/console.php`) — the
  `Schedule::command(...)->dailyAt('06:15')` entry is deleted outright, not disabled/commented.
- **`App\Console\Commands\PrepareLeaseRenewalDrafts` retired** — the command class is deleted
  (git history retains it; nothing else in the codebase called it, so there was no shared code path
  to preserve by keeping the class around). The two services it orchestrated stay, because both are
  genuinely shared with the manual path:
  - `RenewalDraftService::copyForward()`/`draftFromTemplate()` — these ARE the same methods
    `LeaseRenewalController::draftCopyForward()`/`draftFromTemplate()` call for the agent's own
    manual "Renew lease" → copy-forward/draft-from-template actions (§9/§14/§15). Unaffected.
  - `RenewalDraftEligibilityService::decide()` — still live, used by
    `RentalCommandCentreService::queueItems()` to tell an agent what's missing on the "Review
    renewal" row before they click "Renew lease", and by the renewal screen's own eligibility
    preview. This was never itself an unattended-drafting mechanism; it's a read-only decision
    the Command Centre and the renewal screen both already needed regardless of §18's command.
- **UI/comment wording describing automatic drafting corrected** (no functional change, text only):
  `show.blade.php`'s Renew dialog no longer says "CoreX already prepared a renewal draft" (now "A
  renewal draft is already in progress"); the cancel-renewal-draft dialog no longer says "CoreX will
  not automatically redraft a renewal for that lease again" (that sentence only made sense when an
  unattended job existed); code comments in `Lease.php`, `RentalCommandCentreService.php`, and
  `RenewalDraftEligibilityService.php` that referenced "the scheduled auto-draft command" are
  updated to describe the agent-initiated flow instead.
- **"Renewals in progress" tile definition is unchanged, and is now exactly true rather than only
  usually true**: `Lease::hasPendingRenewalDraft()` already meant "a draft lease is chained to this
  one via `previous_lease_id`" — with the automatic command gone, that can now only ever be because
  an agent explicitly started one via "Renew lease" (copy-forward, draft-from-template, or manual
  upload). No code change was needed for this — removing the command's own draft-creation path was
  sufficient.
- **"Expiring in window" is unchanged** — it already surfaced every lease in the agency's reminder
  window with the "Renew lease" action prominent (`route_params => ['action' => 'renew']`, opening
  the Lease Hub's renew dialog directly); this was already the entry point regardless of whether a
  draft happened to already exist, and remains so now that it is the ONLY entry point.

**Existing QA1 data, audited before landing (read-only query against `corex_qa1`, no writes) — only
ONE lease row anywhere in the table has `previous_lease_id` set: lease #54 (chained to lease #5),
already `status = cancelled`, created 2026-10-05 11:32:35.** Nothing needs cancelling: it's already
in the terminal "cancelled" state via the existing "Cancel renewal draft" action, soft-cancelled, not
deleted. Separately confirmed: **this QA1 host has no cron entry for `/corex-qa1` at all** (checked
`crontab -l` — only `/corex-demo` and `/corex` have scheduler cron lines; `/corex-qa1` has never run
`artisan schedule:run` unattended), and `laravel.log` has no "Preparing renewal drafts" lines — so
the scheduled job was never actually invoked unattended on QA1 in the first place; lease #54 was
created and cancelled by hand (manual testing of the "Cancel renewal draft" feature itself), not by
the 06:15 job. There is therefore no backlog of auto-created drafts anywhere on QA1 needing review.

**`hasCancelledRenewalDraft()`/`cancelledRenewalDrafts()` (`Lease.php`) are left in place** even
though their one caller (the retired command's "don't silently re-create" skip) is gone — the
relation still correctly reflects real, queryable lease state (a cancelled renewal draft chained to
this term) that the tenancy log and any future lookup can use; it is not itself an auto-drafting
mechanism and removing it was not asked for.

**Tests**:
- `tests/Feature/Leases/LeaseRenewalDraftAutomationTest.php` — **removed entirely** (every case
  exercised the retired `rentals:prepare-renewal-drafts` command directly via `Artisan::call()`).
- `tests/Feature/Leases/RenewalDraftEligibilityServiceTest.php` — **new**, replaces the one surviving
  case from the removed file (`RenewalDraftEligibilityService::decide()` prefers copy-forward over a
  matching template) plus two more covering its other two branches (draft-from-template when
  eligible, insufficient-info when neither path qualifies) — this service has no automatic caller any
  more, but remains live production code via the Command Centre, so its decision logic still needs
  direct coverage independent of any caller.
- `tests/Feature/Leases/LeaseRenewalDraftCancellationTest.php` — `test_command_does_not_recreate_a_
  cancelled_renewal_draft` removed (called the retired command); its docblock and the surviving
  `test_manual_renew_lease_still_works_after_a_cancelled_draft` are otherwise unchanged (that test
  never touched the command).
- `tests/Feature/Leases/LeaseRenewalSchedulerTest.php` — **new**: asserts `routes/console.php`'s
  registered `Schedule` has no entry whose command contains `rentals:prepare-renewal-drafts`, and
  that the `PrepareLeaseRenewalDrafts` class no longer exists — so a future change can't silently
  reintroduce unattended drafting without this test failing.
- `tests/Feature/Rentals/RentalCommandCentreServiceTest.php` — unchanged behaviourally (the
  `renewal_draft_ready` and missing-info rows are tested against a directly-created draft `Lease`
  row, not the command, so both tests remain valid as coverage of agent-initiated drafts); two
  docblock comments referencing the retired command's own wording corrected, no assertion changed.
