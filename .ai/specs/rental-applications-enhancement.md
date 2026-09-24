# Spec — Rental Applications Enhancement (AT-430)

Status: Part A (one-step approval) BUILT 2026-09-24 — see §2. Part B
(checklist panel) still SPECCED, NOT SCHEDULED at time of Part A's build.

Source: Johan, 23 Sep 2026, after Sherry (Cape Town, single-person agency)
shared her paper application checklist. Two separate asks in one ticket
because they land on the same screen.

---

## 1. Purpose

Today an application is reviewed, then **sent for authorisation** to a second
person, who approves it. That is correct for Home Finders Coastal (agents +
principal). It is wrong for a one-person agency: Sherry is the agent and the
authoriser, so the hand-off is a screen she sends to herself.

Separately, the review screen's right-hand panel shows finances only. Every
agency in practice works off a vetting checklist — documents in, TPN done,
FICA done, lease signed, deposit paid — and today that lives on paper or in
a Word document outside the system.

Both are solved the same way: agency-configurable settings, sensible
default, existing behaviour unchanged for agencies that change nothing.

---

## 2. Part A — One-step approval — BUILT 2026-09-24

Built against the real mechanism, not the placeholder names above — this
module has no `rental_applications.approve` permission key and no
`pending_authorisation` status value. Access to decide an application is
agency-configured **RO/CO tier membership**
(`User::isRentalApplicationRO()`/`isRentalApplicationCO()`, backed by
`agencies.rental_application_ro_user_ids`/`rental_application_co_user_ids` —
see `RentalApplicationSettingsController::updateRO()`/`updateCO()`), and
"awaiting authorisation" is `status === 'under_assessment' &&
submitted_for_approval_at !== null` (`RentalApplication::isPendingAuthorisation()`),
not a status enum value. Both read straight onto this feature: "holds the
`approve` permission" = "is RO or CO for the agency"; "skip the
`pending_authorisation` status" = "never set `submitted_for_approval_at`
for this decision at all."

### 2.1 Setting

New column on the existing per-agency settings model
(`RentalApplicationQualifyingSetting.approval_mode`, migration
`2026_09_24_090000_add_approval_mode_to_rental_application_qualifying_settings.php`,
same one-migration-per-setting convention as every other setting on this
model) — nullable, no DB default, resolved via
`RentalApplicationQualifyingSetting::approvalModeFor($agencyId)` the same
way `returnGateMethodFor()`/`requireFicaBeforeAuthorisationFor()` already
work, so an agency that has never opened the settings screen reads as
`two_step`.

- `approval_mode` — string, `two_step` (default) | `one_step`
  (`RentalApplicationQualifyingSetting::APPROVAL_MODES`)
- On the rental applications settings screen, labelled **Application
  approval**, two radio options with the exact copy in this spec's original
  §2.1 — `RentalApplicationSettingsController::updateApprovalMode()`, route
  `corex.settings.rental-applications.approval-mode`.

Default is `two_step` for every existing and new agency (no row = default).
No agency's behaviour changes on deploy.

### 2.2 Behaviour when `one_step`

- On the AGENT's own review screen (`RentalApplicationReviewController::show()`),
  when the agency is `one_step` AND the acting user is RO or CO for that
  agency AND is not blocked by the self-approval guard
  (`guardNotSelfApproving()` — a self-created application still needs an
  override-tier decider, exactly as in `two_step`), "Submit for approval" is
  replaced, in the same position, by **Approve application** and **Decline
  application**. Computed server-side as `$canApproveDirectly`; a user who
  is not RO/CO sees "Submit for approval" regardless of the agency's
  setting — the mode collapses the flow only for users who could have
  decided anyway.
- Both buttons post to the SAME `corex.rental-applications.authorisation.approve`/
  `.decline` routes and controller methods (`RentalApplicationAuthorisationController`)
  the authoriser's own screen uses — approve()/decline() themselves are
  byte-for-byte unchanged: same status, same timestamp, same `approved_by`,
  same audit/status-history writes, same `RentalApplicationApproved`/
  `RentalApplicationDeclined` events and listeners. The only change is the
  GATE that decides who may call them
  (`RentalApplicationAuthorisationController::guardCanDecide()`): it now
  also accepts a `one_step`-eligible RO/CO user on a pre-decision status,
  not only `isPendingAuthorisation()`.
- Lease creation, rent prefill and the applicant-facing approval email are
  NOT triggered by `approve()` in either mode — they were already a
  separate, manual, agent-triggered step
  (`RentalApplicationController::linkTenantProperty()`,
  `RentalApplicationReviewController::send()`), gated only on
  `status === 'approved'`. Unaffected by this feature either way — this
  spec's original text overstated what `approve()` itself does.
- `submitted_for_approval_at` is never set for a one-step decision — the
  hand-off marker is skipped, not faked. The application's status goes
  straight from its real pre-submit status (`returned`, `under_assessment`
  without the marker, etc.) to `approved`/`declined` in the SAME
  `RentalApplicationStatusHistory` row `approve()`/`decline()` always wrote —
  one action, one row.
- The audit log (`RentalApplicationAuditLog`, written by
  `RentalApplicationAuditService`) now always carries
  `metadata.approval_mode` — the mode read fresh at the moment of decision,
  for BOTH modes — so a later mode change never makes past history
  ambiguous.

### 2.3 Permissions

- One-step approval still requires RO or CO tier membership for the agency
  — the real-world equivalent of "holds the approve permission" in this
  module (§2, above). The setting removes a *step*, not a *check*: an agent
  without RO/CO tier in a `one_step` agency sees "Submit for approval" as
  today.
- A one-person agency works because that person is configured as both RO
  and CO for their own agency (Settings → Rental Applications → Reviewers/
  Override), not because a check was dropped.

### 2.4 Switching mode mid-stream

- Applications already awaiting authorisation
  (`isPendingAuthorisation()` true) when an agency switches to `one_step`
  stay valid and are approved from that queue exactly as today —
  `guardCanDecide()` accepts `isPendingAuthorisation()` unconditionally,
  regardless of the current mode.
- Switching either direction affects new decisions only; nothing is
  backfilled or reinterpreted on existing rows.

---

## 3. Part B — Right-hand panel as sections

### 3.1 Structure

The right-hand panel on the application review screen becomes a set of
collapsible sections (accordion), not one flat block:

1. **Finances** — exactly what is there today. Unchanged content, now inside
   a section that can be collapsed.
2. **Checklist** — new. One or more agency-configured checklist sections.

Open/collapsed state is remembered per user per section. Default on first
load: Finances open, Checklist open, everything else collapsed.

The panel is visible to both the agent and the authoriser, and in `one_step`
mode to the single approving user. Same panel, same data — there is no
separate "authoriser view".

### 3.2 Checklist model

Three levels:

- **Checklist section** — a named group (e.g. "Vetting", "FICA",
  "Lease progress"). Has a free-text **description field the agent types
  into** — this is Johan's explicit requirement: "each section has a desc
  where agents can type in what they find". It is a notes box on the
  application, not on the template.
- **Checklist item** — a named line inside a section (e.g. "Payslip",
  "TPN OK"). Per application it carries:
  - a state: not started / done / not applicable
  - a free-text note (what the agent found — e.g. what the bank statement
    showed, what the reference said)
  - who set it and when
- **Checklist template** — the agency's configured set of sections and
  items. Applications take a snapshot of the template at creation, so
  editing the template later does not rewrite applications already in
  flight.

### 3.3 Settings screen

New settings screen: **Application checklist**.

- Agency admins create, rename, reorder and archive sections and items.
- Items are archived, never hard-deleted (standing rule).
- Each item has: name, optional help text, and a flag for whether a note is
  required before the item can be marked done.
- An agency that configures nothing gets the default template below and can
  edit it. Agencies that want no checklist can archive every section; the
  Checklist panel section then does not render.

### 3.4 Default template

Derived from Sherry's paper checklist. This is the shipped default, fully
editable per agency.

**Section: Application**
- Viewed
- Application received
- Occupants / cars / pets recorded

**Section: Documents**
- ID
- Proof of address
- Payslip
- Employment confirmed
- Tax number
- Bank statement

**Section: Vetting**
- Reference obtained
- TPN fee paid
- TPN check done
- TPN outcome recorded *(note required)*

**Section: FICA**
- FICA sent
- FICA received
- FICA uploaded

**Section: Lease terms**
- Rent agreed
- Deposit agreed
- Occupation date agreed

**Section: Lease progress**
- Lease drafted
- Sent to tenant
- Signed by tenant
- Sent to landlord
- Signed by landlord
- Deposit paid
- First rent paid
- Occupied
- Body corporate rules signed

Note on the last section: several of these items duplicate facts the system
already holds once a lease exists (deposit paid, rent paid, occupation
date). Before building, check whether each should be a tick the agent sets
by hand or a read-only state the system derives. Do not build a second
source of truth for money that the lease already tracks. Ask Johan if it is
not obvious — the default should be: derived where the system knows,
manual tick where it does not.

### 3.5 Progress indicator

Each section header shows `done / total` (excluding not-applicable items),
and the Checklist panel header shows the overall count. This is what makes
the panel useful at a glance to an authoriser who did not do the work.

### 3.6 Checklist and approval

The checklist does **not** block approval by default. A further setting —
`applications.require_checklist_complete`, default off — can make an
incomplete checklist block the approve action. Off by default because
Sherry's checklist is a working aid, not a gate, and turning it into a gate
unasked would stop people approving applications.

---

## 4. Standing constraints that apply

- Every threshold, list and label above is an agency-configurable setting
  with a sensible default.
- OWN / BRANCH / AGENCY scoping enforced at the query layer for checklist
  data, same as the application itself.
- No hard deletes — archive only, for templates, sections and items.
- Existing behaviour must be byte-identical for an agency that changes no
  settings. That is the acceptance test for the whole ticket.

---

## 5. Out of scope

- Any change to the applicant-facing application form.
- Any change to how documents are uploaded or stored.
- Reporting or dashboards over checklist completion.

---

## 6. Open questions for Johan

1. Lease progress items: derived from the lease, or manual ticks? (§3.4)
2. Should the section description be one box per section, or one notes box
   for the whole checklist?

Answered (2026-09-24): declining in `one_step` mode also skips
authorisation — a decline is a single-person action either way, and goes
through the identical `guardCanDecide()` gate as approve (§2.2).

---

## 7. Known defect to fix while in this file

`resources/views/corex/rental-applications/review.blade.php` lines ~1347 and
~1400 carry two Alpine `:style` + static `style` clobber pairs (a docs-panel
width toggle and a document-label font-size toggle). Same bug that cost four
rounds on the inspections screen. Move the statics into CSS classes as part
of this work.
