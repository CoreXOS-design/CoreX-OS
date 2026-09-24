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

**BUILT, 2026-09-24.** Data model: `rental_checklist_template_sections` /
`rental_checklist_template_items` (the agency's own template, archive-only)
and `rental_application_checklist_sections` / `rental_application_checklist_items`
(the per-application snapshot). Settings screen at
`corex.settings.rental-applications.checklist.index`. Panel rebuilt as an
accordion in `resources/views/corex/rental-applications/review.blade.php`
(`rentalChecklistPanel()`, shared by the agent and authoriser Alpine
components). See the build report for file list and verification detail.

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

**RESOLVED, 2026-09-24 — Johan's ruling: derived where the system knows,
manual tick where it does not.** Deposit paid, First rent paid and Occupied
are derived from the lease and rendered read-only; Lease drafted / Sent to
tenant / Signed by tenant / Sent to landlord / Signed by landlord / Body
corporate rules signed stay manual ticks. If no lease exists yet, a derived
item renders as not started and visibly derived, never as something the
agent can click.

**Build-time finding, flagged rather than worked around:** at build time,
`Lease` (`app/Models/Lease.php`) carries `deposit_amount` — the AGREED
figure — but CoreX has no fact anywhere for whether a deposit or first
rent was actually PAID; `.ai/specs/leases.md` §3.3/§627 explicitly defers
"deposit-held tracking / trust-account reconciliation" as a future, unbuilt
feature. Treating `deposit_amount is set` as "paid" would have invented
exactly the false second-source-of-truth signal this ruling exists to
prevent. So today: **Occupied** genuinely derives (lease exists, not
cancelled, `start_date` has passed); **Deposit paid** and **First rent
paid** derive to `not_started` with an honest "not yet trackable" note
until lease payment tracking exists to derive from. Revisit both once that
feature lands — `RentalApplicationChecklistService::deriveState()` is the
one place that needs updating when it does.

### 3.5 Progress indicator

Each section header shows `done / total` (excluding not-applicable items),
and the Checklist panel header shows the overall count. This is what makes
the panel useful at a glance to an authoriser who did not do the work.

### 3.6 Checklist and approval — GATE WIRED 2026-09-24

The checklist does **not** block approval by default. A setting —
`RentalApplicationQualifyingSetting.require_checklist_complete` (default
off, `requireChecklistCompleteFor($agencyId)`) — can make an incomplete
checklist block the approve action. Off by default because Sherry's
checklist is a working aid, not a gate, and turning it into a gate unasked
would stop people approving applications.

Wired into `RentalApplicationAuthorisationController::approve()`, right
after `guardCanDecide()` and before any write: when the setting is on AND
`RentalApplicationChecklistService::isCompleteFor($rentalApplication)` is
false, the action aborts (422) naming every outstanding item by name
(`RentalApplicationChecklistService::outstandingItemNames()`) — never a
bare "checklist incomplete." `syncDerivedStates()` is called explicitly at
this point too, not assumed fresh from a prior page load, so a derived
lease-progress item can never be checked stale.

**Approve only, never decline** — declining isn't a confidence claim the
checklist needs to back up, so `decline()` is untouched.

**Applies identically to one-step and two-step approval** — the check sits
inside `approve()` itself, which both paths call unchanged (§2.2), so it
can never branch on `approval_mode`. Verified directly: incomplete +
required blocks in both modes with the same named-outstanding-items
message; off leaves both modes byte-identical to before this setting
existed.

- Settings screen: "Application Checklist Gate" section, checkbox "Require
  the application checklist to be complete before approving," default
  unchecked — `RentalApplicationSettingsController::updateRequireChecklistComplete()`,
  route `corex.settings.rental-applications.require-checklist-complete`.
- Onboarding Setup Wizard (`config/agency-onboarding-copy.php`, `leases`
  step, CLAUDE.md non-negotiable #10a): `approval_mode` and
  `require_checklist_complete` added as scalar controls (own saver each,
  wired into that step's `savers`); the checklist TEMPLATE itself
  (sections/items CRUD, array-shaped) is a `links` entry to
  `corex.settings.rental-applications.checklist.index`, same carve-out as
  the existing "Rental application settings"/"Rental inspection settings"
  links.

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

1. ~~Lease progress items: derived from the lease, or manual ticks?~~
   **RESOLVED 2026-09-24 — derived where the system knows, manual tick
   where it does not.** See §3.4's own build-time finding for the two
   derived items (Deposit paid, First rent paid) that currently have no
   real signal to derive from and what that means until lease payment
   tracking exists.
2. ~~Should the section description be one box per section, or one notes
   box for the whole checklist?~~ **RESOLVED 2026-09-24 — one box per
   section** (§3.2), Johan's own words: "each section has a desc where
   agents can type in what they find."
3. ~~Should declining in `one_step` mode also skip authorisation, or does a
   decline always stay a single-person action today anyway?~~ **RESOLVED
   2026-09-24 — yes, decline also skips.** A decline is a single-person
   action either way, and goes through the identical `guardCanDecide()`
   gate as approve (§2.2).

---

## 7. Known defect to fix while in this file

**FIXED, 2026-09-24.** `resources/views/corex/rental-applications/review.blade.php`
lines ~1347 and ~1400 carried two Alpine `:style` + static `style` clobber
pairs (a docs-panel width toggle and a document-label font-size toggle).
Same bug that cost four rounds on the inspections screen. Statics moved
into `.rr-docs-panel` / `.rr-cv-doc-label` CSS classes; `:style` now carries
only the genuinely dynamic property on each element.
