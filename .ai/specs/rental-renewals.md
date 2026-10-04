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
