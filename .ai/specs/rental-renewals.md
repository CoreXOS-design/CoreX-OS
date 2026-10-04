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
