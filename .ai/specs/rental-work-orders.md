# Rental Work Orders (and Fault Reports)

**Status:** BUILT, all five stages, plus Stage 7 (quotes — §3.4c) and Job Cards (§14, AT-442,
2026-10-04) — the fault report record, its lifecycle, the spend threshold (now property-level,
§3.4b), the work orders themselves, the attached out-inspection history, the quote an agent obtains
before work starts (what the approval limit actually rides on), and the internal-maintenance-team
counterpart to an outside supplier (see §11 and §14 for exactly what and when). Two questions remain
genuinely open, not silently resolved: the fault-report-level owner/tenant mail gap and the
mailbox-polling automation question (§3a.1a) — both named where they're discussed, neither blocking
anything else here.
**Date:** 2026-09-14 (amended 2026-09-22, amended 2026-09-24, amended 2026-09-25, amended again
2026-09-26, amended again 2026-09-29, built Stages 1-5 through 2026-09-25/2026-09-20, Stage 7
2026-09-29, Job Cards (§14) 2026-10-04, Pastel-style catalogue type/unit/VAT-pricing enhancement
(§14.3a) 2026-10-05 — see below)
**Author:** cc4 (Stages 1-7); cc6 (§14, Job Cards, AT-442); Johan's build brief direct to this lane (§14.3a)
**Pillar:** Property (`Property`) — every work order and fault report anchors to a property; Contact
(owner, tenant, supplier's own contact person) is who is notified and who reported it; touches Lease
(`.ai/specs/leases.md`) and Rental Inspections (`.ai/specs/rental-inspections.md`, both now landed and
built) as its two structural dependencies.
**Sequencing:** Johan's ruling — core matches/pipeline → inspections → **work orders (this spec)**.
Both dependencies are read, coordinated on directly, and cited below; neither is re-designed here.

**Amendment, 2026-09-22 — Johan has ruled work orders IN SCOPE.** This revision settled what he
settled, added an owner-approval gate, corrected one factual claim (§4, WhatsApp), and named four
questions he had not yet ruled on. See §0a for exactly what changed then.

**Amendment, 2026-09-24 — Johan has settled the first of those four questions, and settled it
differently than either the conductor's proposal or mine.** Fault reports are their own record —
neither an inspection nor a `rental_work_orders` row at `reported_by_type='tenant'`. His reasoning,
verbatim: a geyser bursting in month 7 and a tenant moving out in month 16 means an out-inspection
in month 16 cannot be judged fairly without seeing that history first — "the fault and repair history
is precisely what makes an out-inspection correct." That is context FOR the out-inspection, attached
to it, not merged into it. See §0b for the full settlement, §1a for the corrected argument (my
previous recommendation there was wrong and is struck through, not deleted, so the reasoning that led
to it is still visible), and the new §3a for the `rental_fault_reports` schema this ruling requires.

Three of the four original open questions remain genuinely open — §3.2a (how a tenant reports, still
the front door that doesn't exist), §3.4a (owner-approval evidentiary capture), §3.4b (the spend
threshold) — none decided by this amendment either.

**Amendment, 2026-09-25 — Johan has ruled on all three remaining open questions.** §0c has the full
settlement. In brief: tenant reporting stays the simple thing (the agent captures it) but the record
now carries WHO reported it and THROUGH WHAT CHANNEL, so tenant self-service on Andre's future app
slots in later without a rewrite (§3.2a, §3a). Owner approval is bigger than this spec had it — there
are TWO outcomes of approval, not one (agency appoints a contractor, or the owner sorts it themselves
with no work order at all), and the work order is now explicitly NOT mandatory to reach a resolved
outcome (§3a.1, §3.4a). The essential fact this whole feature exists to capture is "was it repaired,
and when" — a new `repaired_at` field carries that, independent of who did the work (§3a.2). Approval
evidence is always in writing (a WhatsApp reply or an email) and is captured by the agent as an upload
or a pasted record, retained on its own append-only evidence log (§3.4a, new `rental_approvals` table,
shared with work-order-level approvals). The spend threshold is agency-level with a sensible default,
overridable — Johan himself was unsure whether the override belongs on the property or the lease; this
amendment argues it through and recommends the PROPERTY (§3.4b), not the lease he tentatively suggested.
A read-only investigation into whether CoreX's existing mailbox-polling
and message-archive machinery could file an approval email automatically is reported in §3a.1a — not
built, per instruction.

**Amendment, 2026-09-29 — Johan has ruled on the missing piece the approval gate needed to mean
anything: the QUOTE, and settled property-vs-lease the other way from 2026-09-26.** His words: "agents
will obtain quotes and thats the value that approval will ride against. so an upload or attach of the
quote, or punching in the details is whats needed." Until this amendment, `cost_amount` was the only
money figure anywhere on a work order, and it was only ever set inside `complete()` — after the job was
done — so nothing existed to test the spend threshold against before work started. §3.4c is new: a
`rental_work_order_quotes` table, several per work order, exactly one selected at a time, and
`RentalWorkOrder::selectQuote()` — the SELECTED quote's amount is what the threshold gate actually
compares, never `cost_amount`. Separately, asked again directly while looking at the live lease screen,
Johan reversed the 2026-09-26 property-vs-lease call this spec had argued through: "looking at it on
the lease screen now. per property, populated to the leases screen." §3.4b is corrected below — the
override now lives on the PROPERTY (sitting with `deposit_amount`/`admin_fee`), and
`leases.rental_no_approval_spend_threshold` (built Stage 3, never consumed by any gate, no real data
behind it — Standard −1q) is dropped rather than kept as a second, driftable mirror; the lease screen
reads through to the property's value instead of storing its own.

---

## 0a. What the 2026-09-22 amendment settled, added, and left open (historical — see §0b for what changed since)

**Settled by Johan's new ruling, built into this revision:**
- Contractors live in the existing supplier list (`AgencyServiceProvider`) — already this spec's
  design (§2), now confirmed as the ONLY directory; no second one is ever created.
- Owner approval **gates** commissioning work — an agent may not instruct a supplier on an owner's
  property without it. New: `owner_approval_status` on `rental_work_orders` (§3.4a).
- Work orders can arise from inspections — already this spec's design (§3.2, `reported_by_type
  ='inspection'`), confirmed as one of the valid origins alongside a direct tenant/agent report.
- Finances are OUT. `cost_amount`/`paid_by` remain evidence-trail fields only (§5.1) — REOS keeps
  doing the money until rentals is bug-free and there's time to build a real financial layer. This
  spec is designed so that layer can attach to `rental_work_orders` later (a `financial_transaction_id`
  FK slotting in, for instance) without restructuring anything built here — but nothing financial is
  built now.
- Deposits are OUT except advertising deposits — named as a real, current gap (§5.1a), not solved:
  damage found at move-out has no financial home in CoreX today.

**Four things Johan had been asked and had not yet ruled on at the time:**
1. ~~§1a — is a tenant fault report an inspection, or a separate thing?~~ **Settled 2026-09-24 — see
   §0b/§1a. Neither of the two answers argued at the time was right; Johan's own is.**
2. ~~§3.2a — how does a tenant, who has no CoreX login, actually report a fault?~~ **Settled
   2026-09-25 — see §0c/§3.2a. The agent captures it; the record is designed so tenant self-service
   slots in later without a rewrite.**
3. ~~§3.4a — how is the owner's approval captured and retained as evidence, not just obtained?~~
   **Settled 2026-09-25 — see §0c/§3.4a. Bigger than originally specified: there are two distinct
   approval outcomes, not one.**
4. ~~§3.4b — the agency-configurable spend threshold below which no approval is required.~~ **Settled
   in shape 2026-09-25 — see §0c/§3.4b. One sub-question (property vs. lease override) argued and
   recommended, not unilaterally decided.**

**Corrected:** §4's notification section overstated WhatsApp — confirmed directly against the code,
there is no automated WhatsApp send anywhere in CoreX, only a `wa.me` link an agent opens and sends
from their own phone. Said plainly now instead of implying parity with the automated email path.

**Added:** §13 — the mobile-foundation constraint, per the same ruling that now applies to
`rental-inspections.md` §14: an agent raises a work order standing in the property, so the logic
behind every action here lives in a service Andre's app can call, not in a controller or a Blade.

---

## 0b. What the 2026-09-24 amendment settles — fault reports are their own record

Johan, verbatim: *"as you said in and out inspections can create work orders. what I think needs to be
part of the out inspection is a sub reports of reported faults and what was repaired and what not.
this has a massive influence if the out inspection is correct or not - geyser in month 7 and the
tenant moves out on month 16 means an agent can see what damages there were when the geyser burst,
and what was not repaired. so might not need to be part of the actual out inspection, but Im seeing an
attached report of faults and their repairs."*

**Settled, built into this revision:**
- A fault report is its own record (`rental_fault_reports`, §3a) — not an inspection, not a
  `rental_work_orders` row wearing a different `reported_by_type`. It has its own lifecycle: reported
  → owner approval where required → work order raised (optional) → outcome. It can link to a lease, a
  property, and optionally an inspection observation, but needs none of the last two to exist.
- **Outcome, not status.** A fault report's terminal state is one of five real outcomes — `repaired`,
  `repaired_partially`, `not_repaired`, `owner_declined`, `tenant_liable` — argued in §3a.2, because
  "closed" tells an agent at month 16 nothing about what they're actually looking at.
- **Attached to the out-inspection, not merged into it.** The out-inspection records what the agent
  observes NOW; the fault report records what happened DURING the tenancy. Two records, one screen —
  §3a.4/§6.
- **Scoped by lease, not by property**, for the out-inspection's attached view specifically — this
  tenant's context is this tenant's tenancy, argued in §3a.4 against the deliberately property-wide
  carry-forward `rental_inspection_items` already uses for a different purpose.
- Photos on fault reports, same pipeline as everywhere else — `rental_fault_report_photos`, §3a.3.

**Unchanged, still true:** contractors in the existing supplier list, finances out, deposits out except
advertising deposits, no automated WhatsApp. (Owner approval and the spend threshold, both referenced
as "still open" when this section was first written, are now settled — see §0c immediately below.)

---

## 0c. What the 2026-09-25 amendment settles — tenant reporting, approval's two routes, and the spend threshold's shape

Johan ruled on all three questions §0a left open. Three separate quotes, three separate settlements:

**On tenant reporting**, verbatim: *"for now the agent will capture the tenant report - in the future
we hope to get tenants on the corex app and they can report from there."*

**Settled:** the simple thing, built now — the agent captures a tenant's fault report on their behalf
(§3.2a's own previously-costed "option 3"). No tokened link, no public form, no inbound-email parser.
**But the record does not assume this is permanent.** `rental_fault_reports` carries WHO reported it
(`reported_by_contact_id`, already existed) and, new this amendment, THROUGH WHAT CHANNEL and BY WHOM
CAPTURED (`reported_channel`, `captured_by_user_id` — §3a) — so today a row reads "captured by an
agent, reported by the tenant, by phone," and the day Andre's tenant-app ships, a row reads "reported
by the tenant, via the app," with `captured_by_user_id` simply null and nothing else about the schema,
the service, or the out-inspection's attached view changing. §3.2a has the full design; §13 restates
the mobile-foundation consequence.

**On approval**, verbatim: *"all approvals happens in writing - whatsapp response, or email back
stating repairs approved, or owner uses their own contractor / complex care taker to fix - so the
options are agency appoints, or owner takes the repairs and sorts it out. I think the important part is
capturing if and when the repairs were carried out."*

**Settled, and it is bigger than this spec had it.** This spec's owner-approval gate (§3.4/§3.4a,
2026-09-22) was written as a single approved/declined switch guarding whether a work order could be
commissioned — that was wrong in a specific way: **it silently assumed a work order always follows
approval.** Johan's ruling names TWO genuinely different outcomes of "approved":

- **Agency appoints** — the owner approves, the agency assigns a contractor from the existing supplier
  list, a work order is raised, the contractor is notified. This is the path this spec already modeled
  correctly.
- **Owner sorts it themselves** — their own contractor, or the complex caretaker. **No work order. No
  supplier from our list. The agency does not appoint anyone.** This is the path this spec would have
  gotten wrong: nothing before this amendment let a fault report reach a complete, resolved outcome
  without a `rental_work_orders` row attached. §3a.1/§3a.2 now build this path as a fully normal, first-
  class outcome — a fault the owner handled personally is closed and correct with no work order
  anywhere near it, not a degenerate case of one that never got raised.

**And Johan named the field that actually matters, independent of either route**: whether and when the
repair happened. `repaired_at` (§3a) is new, first-class, and is the spine of this record — at
move-out, "geyser, reported month 7, repaired month 7 by the owner's own plumber" answers the question
that matters; who appointed the repair and who paid for it are real facts this spec still keeps, but
they are secondary to that one.

**Approval evidence** is settled as always-in-writing (a WhatsApp reply or an email saying approved) —
§3.4a specifies exactly how that's captured and retained: since CoreX has no automated WhatsApp capture
and (per the read-only investigation in §3a.1a) no automated email-to-record filing either, today this
is an agent-driven upload or pasted record, on its own append-only evidence log (`rental_approvals`),
not a bare status flip.

**On the spend threshold**, verbatim: *"we can build spend threshold in, Id say agency setting, then an
override per lease agreement. agent captures approved no auth amount on property / lease and thats
where the decision lives?"*

**Settled in shape**: an agency-level setting with a sensible default (§3.4b/§8, unchanged from the
2026-09-22 proposal — `rental_work_order_settings.no_approval_spend_threshold`, proposed default R500),
overridable. **Johan himself wrote "property / lease" with a question mark — genuinely undecided which,
and this amendment does not take his lease suggestion at face value.** §3.4b argues it through and
recommends the override lives on the PROPERTY, not the lease, and says why. **Superseded 2026-09-26 —
Johan ruled LEASE when asked directly; see §3.4b's own current text, not this historical paragraph.**

---

## 0. Dependency status — settled, updated from the original draft of this section

`rental-inspections.md`'s required `lease_id` amendment (originally flagged in this section as a
pending, unresolved item between cc3 and cc5) **is now applied and pushed**: branch
`cc5-rental-inspections-lease-amendment`, commit `3b1e57bb7`, a follow-up commit on top of the
original `9e9be1e7f` (history intact, not a rewrite). `rental_inspections` now carries a required
`lease_id`; `property_id` is denormalized-only. `rental_inspection_items` is unchanged — still
property-scoped, exactly as this spec already assumed. The conductor separately ruled the earlier
inspections-assignment confusion was her own error, not cc3's or this spec's, and cc3 is stood down
from a competing inspections draft — cc5's `leases.md`/`rental-inspections.md` are authoritative.

**Confirmed directly with cc5, in writing, after the amendment landed: this spec's two FK shapes on
`rental_work_orders` are unaffected and correct as designed** — nullable `rental_inspection_item_id`
(WHAT/WHERE, stable across every tenancy) and nullable `lease_id` (WHO/WHEN, null for the
vacancy-repair case, §3.1a). Nothing below needed to change once the amendment landed; this section is
updated only so the spec doesn't carry a stale "unresolved" flag now that it is resolved.

---

## 1. Johan's concept and ruling, verbatim, and what they actually mean for this spec

> "on rentals on a property we have a work order button - this opens the notified problem and logs
> it, and notifies the owner and tenant that a work order has been created, and can even email the
> supplier - we have a supplier database already. Then its just a question of having a notification
> system and tracking way to keep track of work orders and if the work has been completed or not."

That's the operational surface. The ruling that actually defines the schema is this one:

> "geyser bursting is an event that will carry an inspection / photos of the damages / work conducted
> at some stage. and that is what is needed on an out inspection when lets say the ceiling is badly
> repaired by a contractor, and all the evidence sits on that event that happened... the law gives us
> in inspection, and out inspection. but having the comprehensive log of what damages were reported
> when and what was actioned is the evidence assisting the out inspection to be more fair."

**2026-09-22 — the ruling that brings this spec into scope**, given directly to the conductor:

> "tenant report a fault that at this stage should be logged under inspections? ... So now we have
> fault reports and the agent now has the option to send to the owner? mr owner this is the issues
> with the in inspection of your property. on owner approval the agent can assign the work to a
> contractor and the receive and email / and or whatsapp? we have the supplier list so adding
> contractors in there can be the same place to keep suppliers, and in rentals we can at whatever
> stage tap into inspections to create work orders?"

What is settled from this and built into the amendment below: contractors live in the existing
supplier list (§2, already this spec's design); owner approval gates the work (§3.4/§3.4a); work
orders can arise from inspections (§3.2, already this spec's design). What was raised but not yet
answered at the time this section was written: whether a fault report IS an inspection record (§1a)
and how the owner's approval is actually captured (§3.4a) — **both now settled, in later amendments;
see §0b/§1a and §0c/§3.4a respectively.** **On "email / and or whatsapp": checked directly against the
code — there is no automated WhatsApp send anywhere in CoreX, only a `wa.me` link an agent opens and
sends from their own phone (`SigningWhatsAppLinkService` is the pattern; nothing resembling a
WhatsApp Business API or equivalent send client exists). §4 below builds the email path as automated
and names WhatsApp as manual-only, plainly, rather than implying the two are equivalent.**

**A work order is not an operational ticket that closes and is forgotten. It is evidence, permanently,
and the out-inspection reads it.** Concretely, this spec must let an out-inspection distinguish four
situations that a bare in/out condition comparison cannot, on its own, tell apart — the same damage,
four different people owing money:

| # | Situation | What must be on record to prove it |
|---|---|---|
| 1 | Tenant broke it, never reported, still broken at move-out | No work order exists for that item covering the damage window — the absence itself is the evidence. |
| 2 | Tenant broke it, reported it, it was repaired, **owner already paid** | A work order exists, linked to the item, `status = completed`, `paid_by = owner`. Without this, the tenant is charged twice. |
| 3 | Tenant reported it, **nobody fixed it** | A work order exists, linked to the item, reported and dated, but never reaches `completed` — the owner's neglect, not the tenant's damage. |
| 4 | A **contractor** repaired it badly | A work order exists, `status = completed`, a specific supplier attached, dated — and a LATER observation on the same item shows it's still/again damaged. The timeline (repair completed, then still broken) points at the contractor, not the tenant. |

**Deliberate design decision, argued: this spec does NOT add a "whose fault" field.** All four
situations above are reconstructed from facts that already have to exist for other reasons — whether
a work order exists at all, who reported it, its status, who paid, which supplier (if any) did the
work, and the dated observation history on the linked item. A single "fault" field would ask an agent
to make a subjective legal judgment at data-entry time; the evidence-reconstruction approach asks only
for objective facts and lets the out-inspection screen (and, ultimately, a human resolving a deposit
dispute) draw the conclusion from the timeline. This is the test the rest of this spec is built to
pass — see §7 for each of the four rows walked through against the actual schema.

---

## 1a. Is a tenant fault report an inspection? SETTLED 2026-09-24 — it is its own record

**Johan's ruling, verbatim, and the reasoning that decided it** (quoted in full in §0b): a geyser
bursting in month 7 and a tenant moving out in month 16 means an out-inspection cannot be judged
fairly without seeing that history first. *"the fault and repair history is precisely what makes an
out-inspection correct."* That history is context FOR the out-inspection, attached to it, not part of
it. **This is a better argument than either of the two below, and it is the one this spec now builds
to.** Full design in §3a.

The original question and the two answers argued at the time — kept, not deleted, so the reasoning
that led to Johan's actual answer is still visible:

Johan's words, as originally put to me: *"tenant report a fault that at this stage should be logged
under inspections?"* The conductor's own view, argued against that: *"An inspection is a scheduled
event with a checklist at a point in time — in and out. A tenant reporting a burst geyser on a Tuesday
is unscheduled and has no checklist. Forcing it into the inspection record means mid-tenancy faults
pollute the in-versus-out comparison, which is the whole evidentiary point of inspections."* Her
proposal at the time: a fault report is its own record that CAN link to an inspection but need not,
and both a fault report and an inspection can raise a work order. **This is the closer of the two
original answers to what Johan actually settled on** — right that it's its own record, though Johan's
own reasoning (an out-inspection needs the history to judge itself correctly) is sharper than the
pollution concern that motivated it here.

**Checked directly against the actual, already-built `rental-inspections.md` schema and the live
Stage 3 code, not argued in the abstract — does `type='ad_hoc'` solve this? No, argued below, on three
separate grounds:**

1. **`ad_hoc` is still an inspection EVENT, and the mismatch is structural, not cosmetic.** Every
   `RentalInspection` — in, out, or ad_hoc — requires an active lease
   (`RentalInspection::start()`, already built) and exists to hold a WALKTHROUGH of items: the
   Stage 3 tab UI renders every active item with a condition-recording row regardless of type. An
   ad_hoc inspection is "the agent walks the property right now and records what they see," the exact
   same shape as in/out, just untied to move-in/move-out. A tenant phoning in one specific complaint
   is not a walkthrough of anything — there is no checklist being worked, no set of items being
   reviewed, often not even an agent on-site (§3.2a). Making a tenant use the same mechanism an agent
   uses to inspect a whole property is the wrong shape wearing the right spec's clothes, exactly as
   the conductor put it.
2. **The "pollution" concern is real but only partly mitigated by what already exists — worth being
   precise about, since it would be dishonest to overstate the risk.** Discrepancy detection
   (`RentalInspectionDiscrepancy::detectFor()`) is already scoped to "same item, same inspection" —
   observations across different inspections never conflict, confirmed by an existing passing test
   (`test_matching_observations_across_different_inspections_do_not_conflict`). So a tenant's fault
   report sitting in its own `ad_hoc` inspection would NOT, mechanically, create a false conflict
   against a later in/out inspection. What IS lost: an item's `fullHistory()` interleaves every
   observation regardless of source, so reading "the story of this item" means filtering by the
   existing `source` column (`in_inspection`/`out_inspection`/`tenant_fault_report`/`ad_hoc`) to tell a
   scheduled finding from a phoned-in complaint — doable, but it means the TABLE doesn't reflect the
   real distinction the conductor is naming; a filter has to reconstruct it every time.
3. **The natural shape of a fault report is much closer to a work order than to an inspection —
   checked against both schemas side by side.** A tenant fault report needs: what's wrong (free text),
   who reported it, when, optionally a photo, optionally which space it concerns. That is not an
   inspection's shape (items + per-item conditions + discrepancies + signatures) — it is almost
   exactly `rental_work_orders`' own `title`/`description`/`reported_by_type`/`reported_by_contact_id`/
   `reported_at`, which THIS spec already has, for other reasons, before this amendment ever raised the
   question.

~~**My argued position at the time, offered as a recommendation:** a tenant fault report does not need
to be a third record type at all. It can simply be the FIRST STATUS of a `rental_work_orders` row —
`reported_by_type='tenant'` already exists in this spec's own schema (§3.2) for exactly this. "Logged
under inspections" and "logged as a work order, optionally bridged to an inspection via
`reported_inspection_observation_id`" describe the same underlying need from two directions — Johan's
instinct that it should connect to inspections is already satisfied by that nullable FK, without
needing the fault report itself to BE an inspection.~~

**This was wrong, and it's worth being honest about exactly why.** Folding a fault report into
`rental_work_orders` at `status='reported'` conflates two things Johan's ruling shows are genuinely
different: a work order is about COMMISSIONING AND TRACKING a repair (supplier, cost, completion
photo); a fault report is about PRESERVING A TENANCY'S HISTORY so a LATER, unrelated event — an
out-inspection, up to nine months later — can be judged correctly against it. A fault that was
reported, approved, and fully repaired still needs to remain visible and retrievable as "this happened
during this tenancy" long after any work order tied to it is done and irrelevant to look at directly.
Collapsing the two into one record's status field would have made "show me this tenancy's fault
history" the same query as "show me this tenancy's active repair jobs" — correct for neither purpose.
`type='ad_hoc'` remains the wrong answer too, for the three reasons already given above — nothing
about Johan's ruling changes that part of the argument.

---

## 2. What already exists — reuse, do not rebuild

Per the task's own briefing (already established by cc5's prior investigation, not re-investigated
here) plus direct code reading for this spec:

- **Nothing of this feature is built.** The "supplier work order" that exists today
  (`app/Models/DealV2/DealPipelineStepWorkOrder.php` / `deal_step_work_orders`,
  `app/Services/DealV2/CocWorkOrderService.php`) is a **different feature** — house-sale compliance
  certificates (COC, Beetle, Gas) on the DR2 pipeline. It is not reused as a table, but its supplier
  patterns are copied deliberately below because they are the closest working precedent.
- **The notification dispatcher** — `app/Services/CommandCenter/NotificationDispatcher.php` —
  `fire(User $user, string $eventKey, Model $subject, array $args)` for a plain internal alert, or
  `send(User $user, string $eventKey, Model $subject, Notification $notification, array $args)` for a
  custom one. `$eventKey` must be pre-registered in `notification_event_types`
  (`app/Models/CommandCenter/NotificationEventType.php`) via an idempotent migration — exact pattern:
  `database/migrations/2026_08_03_000003_register_fica_referred_to_co_notification.php`. Channel
  resolution already handles the intersection of the event's declared channels, the user's own
  `user_notification_preferences` row, and open-hours/dedup — none of that is rebuilt here.
- **DR2's internal/external split, the precedent this spec's notifications copy exactly:** internal
  staff via `NotificationDispatcher::fire()` (`app/Console/Commands/CommandCenter/
  ScanDealNotifications.php:65`); external parties (contact or supplier) via plain `Mail::to(...)->send(...)`
  (`app/Services/DealV2/DealDistributionService.php:236,246`). The COC work-order feature's own
  supplier-email path (`CocWorkOrderService` → `Dr2DistributionSendService`) is the closest existing
  "email a supplier about a work order" precedent and is the one this spec's supplier notification is
  modeled on.
- **The Supplier database** — `app/Models/DealV2/AgencyServiceProvider.php` — agency-scoped,
  soft-deleted, carries `name`/`email`/`phone`/`company`/`is_preferred`/`is_active`, an optional
  `contact_id` link to a full CoreX Contact, and `serviceTypes()` (a `hasMany` to
  `AgencyServiceProviderServiceType`) — **a supplier already carries multiple trade types today.** A
  supplier can also have 1..n named working contacts (`AgencyServiceProviderContact`:
  `contact_person`, `email`, `phone`) — this spec notifies whichever email is on record (contact person
  if one exists, the provider's own `email` otherwise), same as the existing COC picker already does.
- **The trade-type vocabulary** — `app/Models/DealV2/AgencyServiceType.php` — an agency's own
  configurable list (`code`/`label`, seeded with defaults, soft-deletable, never hardcoded). **This
  spec reuses this exact model and table for the work order's own trade-type field**, rather than
  inventing a second, competing trade-type list — one vocabulary, two features drawing from it.
- **The owner link** — `Property::sellerOwnerContact()` (`contact_property` pivot roles
  `owner`/`landlord`/`lessor`) — already built, reused here to resolve who to email, not respecced.
- **Photo storage** — `PropertyImageStorer` (2560px/JPEG 85), the same pipeline the Rental Images tab
  and rental-inspections spec both use. No new image pipeline.
- **The owner-email and tenant-link "gaps"** you may have heard about from an earlier investigation are
  **not real build gaps** — confirmed already, not re-investigated: both mechanisms exist and work; QA1
  is simply full of portal-import stock that never had an owner attached. This spec does not fix a
  problem that does not exist.

---

## 3. Data model

### 3.1 What a work order hangs off — the lease, the item, and always the property

Per Johan's ruling (leases are the spine) and this spec's own investigation of the vacancy edge case:

```
rental_work_orders
  id
  agency_id                    -- BelongsToAgency
  branch_id
  property_id                   -- REQUIRED, always set. Every work order happens on a known
                                --   property regardless of whether a lease or inspection item
                                --   is attached — denormalized convenience column, same pattern
                                --   as rental_inspections.property_id.
  lease_id                      -- NULLABLE FK leases. WHO was living there when it happened.
                                --   NULL means the repair happened during a VACANCY — between
                                --   tenancies, or before the first one. See §3.1a.
  rental_inspection_item_id      -- NULLABLE FK rental_inspection_items. WHAT/WHERE — the specific
                                --   space/meter this concerns (e.g. "Bedroom 2", "Geyser").
                                --   Nullable because not every work order originates from, or
                                --   even concerns, a single identifiable space (e.g. a whole-
                                --   property pest treatment) — confirmed against cc5's own
                                --   "geyser bursting on a random Tuesday" reasoning in
                                --   rental-inspections.md §3.4, Johan-confirmed there already.
                                --   Stable across every tenancy the property has ever had — this
                                --   is what lets an out-inspection pull "every work order ever
                                --   raised against THIS item," not just this property.
  agency_service_provider_id     -- NULLABLE FK agency_service_providers. The supplier assigned.
                                --   Null while status='reported' and no supplier has been
                                --   engaged yet (an agent may fix something themselves, or an
                                --   owner may self-handle it, per situation 2 above — "owner
                                --   already paid" does not require a formal supplier record).
  owner_approval_status          -- enum: 'not_required' | 'pending' | 'approved' | 'declined'.
                                --   NEW, 2026-09-22 amendment — Johan's ruling: owner approval
                                --   GATES commissioning work; an agent may not move a work order
                                --   to 'ordered' while this is 'pending' or 'declined'. Defaults
                                --   to 'not_required' at creation — 'not_required' if the cost is
                                --   at or under the agency's (or property's, §3.4b) spend
                                --   threshold. A work order raised directly (not from a fault
                                --   report) records its own approval via `rental_approvals`
                                --   (§3.4a, settled 2026-09-25) — one raised FROM an already-
                                --   approved fault report inherits this value instead of asking
                                --   twice (§3a.1).
  trade_type                    -- NULLABLE, references agency_service_types.code (§2) — used to
                                --   filter the supplier picker by trade, same mechanism the
                                --   existing COC work-order feature already uses. Nullable
                                --   because the reporting agent may not know the trade needed
                                --   yet (e.g. "something's wrong with the ceiling" before anyone
                                --   has diagnosed it as a roof leak vs a geyser).
  title                          -- short label, e.g. "Geyser burst — upstairs bathroom"
  description                    -- free text, the reported problem
  status                          -- enum: 'reported' | 'ordered' | 'in_progress' | 'completed' |
                                  --   'cancelled'. See §3.4 for what each means and what's
                                  --   required to move between them.
  priority                        -- nullable enum: 'low' | 'normal' | 'urgent' — [cc4 design call,
                                  --   not requested by Johan, flagged]. Useful for the list
                                  --   screen's own sort/filter (§6) but not load-bearing for the
                                  --   evidence purpose of this spec; drop it at build time if
                                  --   unwanted without weakening anything else here.
  reported_by_type                -- enum: 'tenant' | 'agent_noticed' | 'owner_instructed' |
                                  --   'inspection' | 'fault_report' — §3.2. This is the field that
                                  --   answers "tenant-reported, agent-noticed, or owner-instructed
                                  --   carry different weight in a dispute," per the task's own
                                  --   instruction. 'fault_report' is NEW, 2026-09-24 amendment —
                                  --   §3a: a work order raised FROM a fault report (§3a) rather
                                  --   than directly.
  reported_by_contact_id           -- nullable FK contacts — set when reported_by_type is
                                   --   'tenant' or 'owner_instructed'
  reported_by_user_id              -- nullable FK users — set when reported_by_type is
                                   --   'agent_noticed'
  reported_inspection_observation_id  -- nullable FK rental_inspection_observations — set when
                                      --   reported_by_type='inspection' (the fault was raised as
                                      --   part of an in-inspection, an out-inspection, or a
                                      --   tenant's post-move-in fault-window report, per
                                      --   rental-inspections.md §3.2). This is the direct bridge
                                      --   between the two specs Johan named as one evidence chain.
  reported_fault_report_id            -- NULLABLE FK rental_fault_reports. NEW, 2026-09-24
                                      --   amendment — set when reported_by_type='fault_report':
                                      --   this work order was commissioned FROM a fault report
                                      --   (§3a), not raised directly. When set, the fault report's
                                      --   own owner_approval_status (already satisfied before the
                                      --   work order existed, §3a.1) is inherited rather than
                                      --   asking the owner twice — see §3a.1's note.
  reported_at
  ordered_at                        -- nullable, when a supplier was actually engaged/instructed
  completed_at                      -- nullable
  completion_notes                  -- nullable text
  cancelled_at, cancelled_by_user_id, cancel_reason   -- nullable, set only for status='cancelled'
  paid_by                           -- nullable enum: 'owner' | 'tenant' | 'deposit_deduction' |
                                    --   'not_yet_paid'. THE field that settles the money argument
                                    --   (task's own words) — most likely to be left out if not
                                    --   made a first-class column, so it is one here, not a note.
  cost_amount                       -- nullable decimal. Evidence trail only — see §5.1, this is
                                    --   NOT a trust-ledger/invoicing feature.
  created_by_user_id
  created_at, updated_at, deleted_at   -- soft-delete, gated exactly like rental_inspections
                                      --   (§3.3 of that spec, same reasoning): deletable ONLY
                                      --   while nothing has been logged against it yet (no
                                      --   update-log row, no photo). Once any evidence exists,
                                      --   only 'cancelled' — never destroyed. FICA dictates five
                                      --   years' retention after the business relationship ends
                                      --   (non-negotiable #11); this is evidence, so the floor
                                      --   applies at its strictest.

rental_work_order_updates            -- the "comprehensive log" Johan explicitly asked for —
                                     --   append-only, never edited, never deleted
  id
  agency_id
  rental_work_order_id
  update_type                        -- enum: 'status_change' | 'note' | 'supplier_assigned' |
                                     --   'supplier_changed'
  from_status, to_status              -- nullable, populated only for update_type='status_change'
  note                                -- text, required for update_type='note'; optional
                                     --   elaboration on the others
  created_by_user_id
  created_at                          -- the ONLY timestamp. No updated_at, NO deleted_at at all —
                                     --   same reasoning as rental_inspection_observations: this
                                     --   is the record Johan called "the comprehensive log of
                                     --   what damages were reported when and what was actioned,"
                                     --   and it must never be editable after the fact.

rental_work_order_photos             -- evidence photos — reported-state AND completed-state
  id
  agency_id
  rental_work_order_id
  photo_type                          -- enum: 'reported' | 'in_progress' | 'completed' — so a
                                     --   "before" photo of the damage and an "after" photo of
                                     --   the finished work are both on record and distinguishable
  storage_path
  uploaded_by_user_id
  client_idempotency_key               -- uuid, unique — mirrors the existing MobilePropertyController
                                     --   / rental-inspections offline-safety pattern
  file_size_bytes
  created_at                           -- immutable, no deleted_at, same evidence-integrity
                                     --   reasoning as rental_inspection_photos

rental_work_order_settings            -- one row per agency, §8
  id, agency_id (unique)
  completion_requires_photo             -- bool, default false — CORRECTED 2026-09-21, Johan:
                                        --   "some repairs will not carry photo evidence - broken
                                        --   gate motor. nothing to take a pic of that will mean
                                        --   anything, and you cant have a tenant or agent going
                                        --   and fiddling with a gate motor to take a pic of a
                                        --   replaced pc board as example." A mandatory photo
                                        --   pushed people into staging a pointless picture or
                                        --   physically interfering with equipment they should not
                                        --   touch. The capability and the setting both stay — an
                                        --   agency that wants photo evidence on every job can still
                                        --   switch this on — only the shipped default flipped, so a
                                        --   new agency (and agency 1, which had no stored row and
                                        --   so was silently inheriting the old default) is not
                                        --   blocked by a rule Johan has now ruled wrong. This
                                        --   replaces the original default-true ruling below (§3.4,
                                        --   §8) — do not reinstate it as an "oversight fix."
  overdue_reminder_days                 -- nullable int, default 3 — days since 'ordered' with no
                                        --   status change before the internal reminder
                                        --   notification (§4) fires. [cc4 design call — a
                                        --   sensible number, not specified by Johan; agency-
                                        --   configurable exactly because of that.]
  no_approval_spend_threshold            -- nullable decimal, default 500 (§3.4b, current shape settled
                                        --   2026-09-29). Overridden per-PROPERTY by
                                        --   properties.rental_no_approval_spend_threshold — null on
                                        --   THIS row still means "R500", the read-time default; a
                                        --   property with no override falls through to whatever this
                                        --   column resolves to for its agency. Built Stage 3
                                        --   (2026-09-26, as a lease-level override then), the resolver
                                        --   re-pointed at the property and consumed by the real gate in
                                        --   Stage 7 (2026-09-29) — RentalWorkOrder::selectQuote() (§3.4c)
                                        --   against the SELECTED quote's amount, never cost_amount
                                        --   (which is still only ever known after the job is done).
  created_at, updated_at
```

### 3.1a The vacancy edge case, answered directly

A repair during a vacancy — between tenancies, or before the first one ever starts — has **no lease to
attach to**, because none is active. `lease_id` is nullable specifically for this: the work order still
requires `property_id` (always known) and may still carry `rental_inspection_item_id` (the space is
still a real, persistent fact about the property even with nobody living there). What it loses, honestly,
by having no lease: there is no tenant to notify (§4 — the tenant-notification step is simply skipped,
not attempted against nobody), and "who caused it" situation-1-through-4 reasoning (§7) degrades to
"owner's own maintenance," which is usually the correct read for a vacancy-period repair anyway — there
is no tenant in the four-situation table to blame or clear. This is reported here as the direct,
considered answer to the edge case, not left implicit.

### 3.2 Who reported it — recorded, not inferred

`reported_by_type` plus exactly one of `reported_by_contact_id` / `reported_by_user_id` /
`reported_inspection_observation_id`, matching the pattern rental-inspections.md already uses for
`observed_by_user_id`/`observed_by_contact_id`:

- **`tenant`** — the tenant themselves reported it directly to the work order, bypassing a fault
  report. **[cc4 design call, flagged for Johan, 2026-09-24]**: now that fault reports (§3a) exist
  specifically to preserve a tenancy's fault history for a later out-inspection, this spec's
  preference is that a TENANT report always creates a `rental_fault_reports` row first
  (`reported_by_type='fault_report'` below), so it survives as tenancy context regardless of what
  happens to any resulting work order. This value is kept for a case that skips that — e.g. an agent
  judges something trivial enough to fix without formally logging a fault report — but whether that
  should be allowed at all, or every tenant report should be forced through a fault report, is Johan's
  call, not decided here.
- **`agent_noticed`** — the agent spotted it themselves (during a routine visit, a showing, anything not
  a formal inspection). `reported_by_user_id` set.
- **`owner_instructed`** — the owner asked for something to be done (a proactive upgrade, a
  precautionary repair) — distinct from a fault report; recorded because "the owner asked for this" is a
  different weight in a dispute than "the tenant broke it." `reported_by_contact_id` is the owner's
  contact row.
- **`inspection`** — the fault was raised as an observation during an in-inspection, out-inspection, or
  the tenant's post-move-in fault-report window (`rental-inspections.md` §3.2's
  `rental_inspection_observations.source` values `in_inspection`/`tenant_fault_report`/`out_inspection`).
  `reported_inspection_observation_id` links directly to that observation, so the work order and the
  inspection evidence are provably the same event, not two separately-typed accounts of it.
- **`fault_report`** — **new, 2026-09-24 amendment, the preferred path for anything tenant-originated**
  — this work order was commissioned from a `rental_fault_reports` row (§3a), not raised directly.
  `reported_fault_report_id` links to it.

### 3.2a How does the tenant actually report? SETTLED 2026-09-25 — the agent captures it, channel-tracked for the future

**Checked directly against the code, not assumed:** a tenant is a `Contact` row
(`lease_tenants.contact_id` → `contacts.id`), and `Contact extends Model` — not
`Illuminate\Foundation\Auth\User as Authenticatable`, no password column, no auth guard. **A tenant
has no CoreX login today, anywhere in the system.**

Johan's ruling, verbatim: *"for now the agent will capture the tenant report - in the future we hope to
get tenants on the corex app and they can report from there."* This settles the question in favour of
what was previously costed below as "option 3" — built now, plainly, with no new mechanism. What was
open before this ruling was whether that should be a permanent design or a placeholder; Johan's own
"for now" and "in the future" language makes it explicitly the latter, so the record is designed to
absorb that future without a rewrite:

- **`rental_fault_reports.reported_channel`** — new, this amendment — enum:
  `'phone'` | `'whatsapp'` | `'email'` | `'in_person'` | `'app'` | `'other'`. How the report actually
  reached the agency. `'app'` is a placeholder value for Andre's future tenant-app channel — reserved
  now, used later, no schema change needed when that day comes.
- **`rental_fault_reports.captured_by_user_id`** — new, this amendment — nullable FK `users`. The staff
  member who typed the report into CoreX on the tenant's behalf. **Nullable specifically for the
  future**: the day a tenant reports directly through Andre's app, this column is null (nobody captured
  it, the tenant entered it themselves) while `reported_by_contact_id` still names the tenant and
  `reported_channel='app'` names how — nothing else about the row, the service call beneath it, or the
  out-inspection's attached view (§3a.5) changes. Today, every row has this set, because every row is
  agent-captured.
- The service method this becomes — `RentalFaultReportService::report()` (§11/§13) — takes
  `reported_by_contact_id`, `reported_channel`, and an OPTIONAL `captured_by_user_id`, precisely so a
  future mobile/app endpoint can call the identical method with that argument omitted, rather than a
  second reporting path being built when tenants eventually get a login. This is the same discipline
  §13 already commits to for the rest of this spec, applied to the one path that didn't have an
  API-shaped answer yet.

The three options originally costed here are kept, not deleted, because the reasoning behind picking
the cheapest one is still worth seeing:

1. **A tokened link, mailed to the tenant** — the proven pattern already live for rental applications
   (`RentalApplicationSigningController`, its own docblock: *"modelled on the existing `/sign/{token}`
   mechanism... same token shape, same 14-day expiry... the token itself IS the identity here"*), and
   for DR2's external parties (`DealSecureLinkMail`, already cited in §4 below as the supplier
   reply-link precedent). Highest cost of the three, and the only one that gives the tenant their own,
   independently-timestamped record of having reported it — which matters directly to situation 3 in
   §1's table. **Not built — Johan's ruling is that the future answer to this is the CoreX app, not a
   tokened link, so this option is not the one to build toward.**
2. **An email address that files itself** — a dedicated inbound address that creates a fault report
   automatically from an inbound email. This is a NEW capability — nothing in the codebase parses
   inbound mail into a record today (§3a.1a's investigation confirms this again, from a different
   angle). **Not built, not the direction Johan named.**
3. **The agent captures it on the tenant's behalf** — **this is the one Johan settled on.** Zero new
   mechanism, built now. Honest cost, unchanged by this ruling: "the tenant reported it" is the agent's
   word plus a timestamp, weaker than option 1 for situation 3's dispute purpose — but real today, and
   Johan has ruled that trade-off is the right one until the app exists.

**Settled, not merely "not decided" any more.** This spec builds option 3 as the only path, with
`reported_channel`/`captured_by_user_id` as the seam Andre's future tenant-app work attaches to.

### 3.3 Who paid — the field the task explicitly warns is easiest to leave out

`paid_by`: `owner` | `tenant` | `deposit_deduction` | `not_yet_paid`. Nullable only in the sense that a
brand-new, still-`reported` work order genuinely has no payer yet — but **the UI should prompt for this
at completion**, not leave it silently null forever (see §3.4, completion requirements). This single
field is what directly answers situation 2 in §1's table ("owner already paid" prevents a tenant being
charged twice) and situation 3 (an unpaid, never-completed work order is the owner's neglect, not the
tenant's damage) — it is the load-bearing field of this entire spec's evidentiary purpose, more than any
other single column here.

### 3.4 Status, and what completing one actually requires

- **`reported`** — logged, nothing done yet. No supplier required. This is where a work order sits if
  situation 3 (owner never fixed it) applies — permanently, if that's what actually happened; the
  absence of further status changes IS the evidence.
- **`ordered`** — a supplier has been assigned/instructed (`agency_service_provider_id` set,
  `ordered_at` stamped). Not required for every work order (an agent or owner may self-handle a fix with
  no formal supplier) — a work order can move straight from `reported` to `in_progress`/`completed`
  without ever passing through `ordered` if no supplier was engaged. **Gated, 2026-09-22 amendment:**
  cannot move to `ordered` while `owner_approval_status` is `pending` or `declined` — an agent does not
  commission work on an owner's property without their approval. **Note the ordering, settled
  2026-09-25:** a work order can only ever exist on the `agency_appoints` approval route (§3.4a/§3a.1)
  — the `owner_handles` route never produces a work order at all, so this gate is only ever exercised
  by a work order raised directly (proactive owner-instructed work, or straight from an inspection) or
  one already inheriting `approved` from its upstream fault report. See §3.4a for exactly how approval
  is captured and §3.4b for the threshold below which it isn't required at all.
- **`in_progress`** — work has started but isn't finished. Optional stage — a quick fix may skip
  straight to `completed`.
- **`completed`** — a "completed" photo is invited, not required, by default: **gated by
  `rental_work_order_settings.completion_requires_photo`** (default **off**, corrected 2026-09-21 —
  see §3.1's own note; an agency that wants to insist on photo evidence for every job can still turn
  this on). When it is on, at least one `rental_work_order_photos` row with `photo_type='completed'`
  is required before completion. Also requires `paid_by` to be set to something other than the
  implicit "nothing recorded" state — an agency-configurable choice whether this is a hard block or a
  soft warning is left to build time, but the payer requirement itself is not optional in this spec's
  intent.
- **`cancelled`** — logged in error, or the issue turned out not to need action. `cancelled_at`/
  `cancelled_by_user_id`/`cancel_reason` (required text) recorded. A cancelled work order is never
  deleted once anything has been logged against it (§3.1's soft-delete gate) — it stays visible,
  cancelled, exactly like a cancelled lease or a cancelled inspection under the equivalent rule in their
  own specs.

Every status transition writes a `rental_work_order_updates` row (`update_type='status_change'`,
`from_status`/`to_status` populated) — this, together with `reported_at`/`ordered_at`/`completed_at`
timestamps directly on the work order itself, is what lets the out-inspection screen show a full
timeline, not just a final state.

### 3.4a Owner approval — SETTLED 2026-09-25, and bigger than this spec originally had it

Johan's ruling, verbatim: *"all approvals happens in writing - whatsapp response, or email back stating
repairs approved, or owner uses their own contractor / complex care taker to fix - so the options are
agency appoints, or owner takes the repairs and sorts it out. I think the important part is capturing
if and when the repairs were carried out."*

**What this corrects.** The 2026-09-22 gate (§3.4) modeled approval as a single switch guarding whether
a `rental_work_order` could move to `ordered` — implicitly assuming approval always leads toward a work
order. Johan's ruling shows that's wrong: there are **two distinct outcomes of "the owner approved,"**
and only one of them ever touches `rental_work_orders` at all. Both are settled and built at the
FAULT-REPORT stage (§3a.1) — Johan's own lifecycle is "reported → owner approval where required → work
order raised → outcome," and approval is the fault report's decision, not the work order's:

- **`approval_route = 'agency_appoints'`** — the owner approves, the agency assigns a contractor from
  the existing supplier list (§2), a `rental_work_orders` row is raised
  (`reported_by_type='fault_report'`, §3.1), the contractor is notified (§4). This is the path this
  spec already modeled correctly before this amendment.
- **`approval_route = 'owner_handles'`** — the owner approves, but handles it themselves: their own
  contractor, or the complex caretaker, per Johan's own wording. **No work order is ever raised.** No
  supplier from the agency's list is engaged. The fault report reaches `status='resolved'` on its own,
  with `rental_work_order_id` staying null permanently — not "not yet," permanently. §3a.1 makes this
  explicit: a fault the owner handled personally is a complete, correct, closed case, not a degenerate
  one that never got as far as a work order.

**The field that actually matters, independent of either route.** Johan's own words: "the important
part is capturing if and when the repairs were carried out." That is `repaired_at` (§3a, new this
amendment) — who appointed the repair (agency vs. owner) and who paid for it remain real, kept facts,
but they are secondary to whether and when the work was actually done. This is why `repaired_at` lives
directly on `rental_fault_reports`, not buried inside a work order that may not exist.

**Approval evidence — settled as always-in-writing, captured as an upload or a pasted record.**
Same evidentiary standard §3.4 already applies to completion (photo + payer, not a checkbox): an
approval needs proof of what was sent and what came back, not a bare status flip. Since CoreX has no
automated WhatsApp capture (§4's standing finding) and — confirmed directly, see §3a.1a — no automated
email-to-record filing either, the mechanism settled here is the plainest of the three previously-costed
options, built honestly rather than dressed up as more automated than it is:

- **New table `rental_approvals`** — an append-only evidence log (same integrity pattern as
  `rental_work_order_updates`: no `updated_at`, no `deleted_at`), shared between a fault report's own
  approval (§3a.1, the normal path) and a work order's approval (for the case a work order is raised
  WITHOUT an upstream fault report — owner-instructed proactive work, or straight from an inspection
  observation — and still needs its own approval on record). Exactly one of `rental_fault_report_id` /
  `rental_work_order_id` is set per row, matching this spec's own established "exactly one of" pattern
  (§3.2).
- Each row records: `decision` (`'approved'`/`'declined'`), `approval_route` (`'agency_appoints'`/
  `'owner_handles'`, set only when `decision='approved'` and the row is fault-report-level — meaningless
  at the work-order level, since a work order's mere existence already means the agency-appoints route
  was chosen upstream), `evidence_type` (`'whatsapp'`/`'email'`/`'verbal_note'`), `evidence_text`
  (nullable, the pasted content of the WhatsApp/email), `evidence_file_path` (nullable, an uploaded
  screenshot or forwarded email saved as a file — reuses the existing storage pattern, not a second
  pipeline), `decided_at` (when the owner actually decided, which may predate when the agent typed it
  in), and `recorded_by_user_id`.
- `evidence_type='verbal_note'` is kept as an honest fallback for a genuinely undocumented phone
  approval — same sanctioned "the other party didn't formally engage through the system" precedent
  already accepted for `RentalInspectionSignature::SIGNER_AGENT_ON_BEHALF` — but Johan's ruling is that
  approval is **always in writing**, so this value should be rare in practice, not the default path an
  agent reaches for.
- `rental_fault_reports.owner_approval_status` and the new `rental_fault_reports.approval_route` (§3a)
  are denormalized CURRENT-value columns, read from the latest `rental_approvals` row for that fault
  report — the same "current column + append-only log" shape this spec already uses for
  `rental_work_orders.status` / `rental_work_order_updates`, not a new pattern.

### 3.4b The spend threshold — SETTLED, including the override — Johan ruled LEASE 2026-09-26, then PROPERTY 2026-09-29 (current)

Johan's ruling, verbatim: *"we can build spend threshold in, Id say agency setting, then an override per
lease agreement. agent captures approved no auth amount on property / lease and thats where the
decision lives?"*

**Settled: an agency-level setting with a sensible default, overridable.** This matches the
2026-09-22 proposal unchanged — `rental_work_order_settings.no_approval_spend_threshold`, agency-
configurable, **proposed default R500** — so that out of the box every agency requires approval for
anything beyond a trivial expense, and raises the number for its own mandate/comfort level rather than
CoreX guessing at what's appropriate for a given owner relationship. Most mandates let an agent spend
up to a limit without asking; without one, "email the owner about a tap washer" is how the approval
gate (§3.4/§3a.1) stops being respected in practice.

**The one open part — Johan himself wrote "property / lease" with a question mark, then, asked directly
2026-09-26, answered it himself: LEASE, with the agency default behind it.**

~~This spec previously argued and recommended the PROPERTY instead, on the reasoning that the authority
to spend without asking comes from the owner's mandate, which is mediated through the property, not the
tenancy — and that a lease-level override would need re-entering on every renewal and risks silently
carrying a stale value from a departed tenant.~~ **Johan ruled LEASE. That argument was not wrong on its
own terms, but it was answering the wrong question — it reasoned from where the owner's authority
notionally lives in the abstract, not from where the agent actually captures and uses the number.**
Johan's own phrasing is the tell: *"agent captures approved no auth amount on property / lease and
thats where the decision lives"* — a spend threshold override isn't a standing fact about the property
that happens to get looked up; it's a specific approved amount an agent gets from the owner and records
against the specific tenancy that amount was actually discussed for. A new lease is also, in practice,
often exactly the moment an agency would revisit that number with the owner anyway — "needing to
re-enter it on renewal" is not obviously a cost once the override is understood as a decision tied to a
conversation, not a fact that silently persists on its own. Built to his ruling, not re-argued further.

**Settled shape**: a new nullable decimal, `leases.rental_no_approval_spend_threshold` (nullable — null
means "use the agency default," matching this spec's own null-means-inherit pattern used elsewhere).
When set, it overrides `rental_work_order_settings.no_approval_spend_threshold` for that specific lease
only — a new lease (renewal or new tenant) starts with no override, inheriting the agency default until
an agent explicitly sets one for that tenancy.

~~**Built, Stage 3 (2026-09-26):** both the agency-level setting AND the lease-level override, together —
Johan's ruling settled the sub-question this spec previously left as a build-sequencing gate, so there
is no reason left to build them separately.~~

**Superseded 2026-09-29 — Johan ruled PROPERTY when asked again, live, looking at the lease screen: "per
property, populated to the leases screen."** Two live overrides at once (a lease column AND a property
column) is exactly what his own words ruled against elsewhere in the same conversation — "exactly ONE
place a human can change this number, and it is the property." Rather than keep
`leases.rental_no_approval_spend_threshold` as a second, driftable mirror that has to be kept in step by
hand, it is dropped (`2026_09_29_100100_drop_rental_no_approval_spend_threshold_from_leases.php`) — no
real data behind it to migrate (Standard −1q: Stage 3 landed 2026-09-26, this correction is three days
later, on QA1 only, never consumed by any gate in between). The lease detail screen
(`resources/views/corex/leases/show.blade.php`) now reads through to the property's value
(`RentalWorkOrderSetting::thresholdFor($lease->property)`) rather than storing or editing its own.

**Current, settled shape**: a new nullable decimal, `properties.rental_no_approval_spend_threshold`
(nullable — null means "use the agency default"), added
2026-09-29 sitting with the property's other rental-tab money fields (`deposit_amount`, `admin_fee`) —
same route (`PUT /{property}/rental-details` → `PropertyController::updateRentalDetails`), same
`permission:access_properties` + `agency.required` scoping, same two blade panels
(`resources/views/corex/properties/show.blade.php`). `RentalWorkOrderSetting::thresholdFor()` now takes
a `Property`, not a `Lease` — property override → agency default → the `DEFAULT_*` constant. This is
what `RentalWorkOrder::selectQuote()` (§3.4c) actually calls.

**Built, Stage 7 (2026-09-29):** the property-level override, the retirement of the lease-level one, and
the resolver signature change, together — see §11.

**Still genuinely open, not decided here**: whether R500 is the right default, and whether an agent can
override the gate outright with a reason (mirroring how `owner_approval_status='declined'` might still
need an escape hatch for a genuine emergency repair) are real follow-on questions this section surfaces
but does not answer.

---

## 3.4c Quotes — the value approval actually rides on, NEW 2026-09-29

Johan's ruling, verbatim: *"agents will obtain quotes and thats the value that approval will ride
against. so an upload or attach of the quote, or punching in the details is whats needed."* This closes
a real gap: before this amendment, `cost_amount` was the only money figure anywhere on a
`rental_work_order` row, and `RentalWorkOrder::complete()` was the only place it was ever set — after
the job was already done. `rental_work_order_settings.no_approval_spend_threshold` and its property
override (§3.4b) existed and resolved correctly but had nothing to compare against before work started.
Quotes are that missing figure.

```
rental_work_order_quotes
  id
  agency_id                      -- BelongsToAgency
  rental_work_order_id            -- required FK. Several quotes can exist per work order — Johan's own
                                  --   wording, "agents will obtain quotes" (plural) — the agent marks
                                  --   which one is being acted on.
  agency_service_provider_id       -- required FK agency_service_providers. "At minimum: supplier" —
                                  --   the same existing supplier directory every other part of this
                                  --   spec uses (§2), not a second list.
  amount                          -- required decimal(10,2). THE figure the approval gate compares.
  quote_date                      -- required date.
  document_storage_path            -- nullable string. Private disk (Storage::disk('local')), NOT the
                                  --   public-disk PropertyImageStorer pattern rental_work_order_photos
                                  --   uses — a quote is priced evidence, gated the same way
                                  --   PropertyFileController gates a property Drive document. Downloaded
                                  --   only through a route that re-checks the quote belongs to the
                                  --   given work order on every request (RentalWorkOrderQuoteController::
                                  --   download()), never a raw storage URL.
  detail_text                     -- nullable text, the keyed-in alternative to an attachment.
  is_selected                     -- bool, default false. Exactly one true per work order at a time —
                                  --   RentalWorkOrder::selectQuote() is the only place this flips.
  captured_by_user_id              -- nullable FK users — who recorded this quote.
  created_at, updated_at, deleted_at   -- soft-delete only (non-negotiable #1) — a superseded or
                                      --   withdrawn quote is archived, never destroyed.
```

**Neither the document nor the keyed-in detail is mandatory over the other** — Johan's own wording,
"an upload or attach of the quote, or punching in the details is whats needed" (his "or," not "and").
Enforced in `RentalWorkOrderQuoteController::store()`/`update()`: reject with a plain message if BOTH
are absent, accept either alone or both together.

**The gate — `RentalWorkOrder::selectQuote(RentalWorkOrderQuote $quote, User $by)`, sibling to
`assignSupplier()`.** Marks the given quote `is_selected` (unselecting any other on the same work
order), resolves `RentalWorkOrderSetting::thresholdFor($this->property)` (§3.4b), and sets
`owner_approval_status`: at or under the threshold → `not_required` (the agent approves it themselves,
no owner contact needed); over it → `pending`, which gates `assignSupplier()` exactly as it already did
for a directly-raised work order's own `recordApproval()` flow (§3.4/§3.4a) — nothing about that gate's
own mechanics changed, only what feeds it. Editing the SELECTED quote's amount re-runs this same
resolution (`RentalWorkOrderQuoteController::update()`) — an edited amount can never leave a stale
approval decision standing against a number nobody actually approved.

**Archive/restore** (`RentalWorkOrder::archiveQuote()`/`restoreQuote()`) are soft-delete only, sibling
methods on the same model, matching this spec's established "the parent record owns the audit trail"
shape. Archiving the currently-selected quote clears `is_selected` (a hidden quote left marked
"selected" is exactly the invisible-state bug BUILD_STANDARD's prevent-or-absorb rule exists to catch)
but deliberately does NOT reset `owner_approval_status` — that stays whatever it last resolved to; an
agent who archives a quote after approval was already granted has not un-approved anything.

**Audit trail** — folded into the existing `RentalWorkOrder::history()` (§3.4/Johan, 2026-09-22) as four
new `rental_work_order_updates.update_type` values: `quote_captured`, `quote_selected`,
`quote_archived`, `quote_restored` — same table, same "who did what and when" convention as
`supplier_assigned`/`supplier_changed`, not a second log.

**Navigation** — none new. Reachable from the work order's own show screen
(`resources/views/corex/rental-work-orders/show.blade.php`), same as photos/approvals/notes.

**Permission** — `rental_work_orders.manage_quotes` (capture, select, archive, restore, edit), separate
from `.create` for the same reason `.record_approval`/`.complete` already are — a decision that moves
money past the approval gate is a heavier call than logging or editing the work order itself. Read
access (list/download) rides the group's existing `.view`.

**Scoping** — `rental_work_order_quotes` uses `BelongsToAgency`; every controller action additionally
re-checks the quote's `rental_work_order_id` matches the route's own `{rentalWorkOrder}` (same
discipline as `PropertyFileController::download()` checking a document belongs to the given property)
so a quote id from one work order can never be acted on through another work order's URL, even within
the same agency.

**Currency** — matches the existing rental-tab convention exactly, per instruction: a plain
`<input type="number">` with "(R)" in the label, no locale/currency setting invented for this. The
hardcoded "R" itself is a separate, pre-existing pattern across this whole codebase (`deposit_amount`,
`admin_fee`, and every other rental money field) — flagged to Johan once, outside this spec's scope to
change unilaterally.

---

## 3a. Fault reports — their own record, settled 2026-09-24 (§0b/§1a), lifecycle amended 2026-09-25 (§0c)

A fault report is what actually happened during a tenancy that might need repair — a tenant's damp
patch, a burst geyser, an agent noticing a cracked tile on a routine visit. It exists whether or not a
work order is ever raised from it, and it survives long after any work order tied to it is closed,
because its job is to still be there, correct and complete, the day an out-inspection needs to read it
— possibly months or years later.

```
rental_fault_reports
  id
  agency_id                      -- BelongsToAgency
  branch_id
  property_id                     -- REQUIRED, always set — same reasoning as rental_work_orders
                                  --   .property_id (§3.1): every fault happens on a known property
                                  --   regardless of what else is or isn't attached.
  lease_id                        -- NULLABLE FK leases. WHICH TENANCY this happened during. Null
                                  --   only for the rare vacancy-period case (an agent notices
                                  --   something wrong between tenants) — mirrors
                                  --   rental_work_orders.lease_id (§3.1a) exactly. This is the
                                  --   field §3a.4's lease-scoping is built on.
  rental_inspection_item_id        -- NULLABLE FK rental_inspection_items. WHAT/WHERE, same
                                    --   reasoning as rental_work_orders' own column (§3.1) —
                                    --   nullable because not every fault concerns one identifiable
                                    --   space.
  reported_inspection_observation_id  -- NULLABLE FK rental_inspection_observations. Set when a
                                      --   fault surfaces DURING an inspection (an agent notices it
                                      --   while walking the property) rather than being phoned in
                                      --   independently — Johan's own words allow for this: "it can
                                      --   link to... an inspection." Optional, not required — a
                                      --   fault report needs no inspection to exist at all.
  rental_work_order_id              -- NULLABLE FK rental_work_orders. Set once a work order is
                                    --   raised FROM this fault report (the reverse side of
                                    --   rental_work_orders.reported_fault_report_id, §3.1). Null
                                    --   while the fault sits unaddressed, or if it's resolved
                                    --   without ever needing a formal work order (e.g. the tenant
                                    --   fixed it themselves, or it turned out not to need repair).
  reported_by_type                  -- enum: 'tenant' | 'agent_noticed' | 'owner_instructed' — same
                                    --   three human-origin values as rental_work_orders' own field
                                    --   (§3.2), deliberately NOT including 'inspection' or
                                    --   'fault_report' here — a fault report is the ORIGIN record,
                                    --   it doesn't itself arise from a work order or from another
                                    --   fault report.
  reported_by_contact_id             -- nullable FK contacts — set when reported_by_type is
                                     --   'tenant' or 'owner_instructed'
  reported_by_user_id                -- nullable FK users — set when reported_by_type is
                                     --   'agent_noticed'
  reported_channel                   -- NEW, 2026-09-25. enum: 'phone' | 'whatsapp' | 'email' |
                                     --   'in_person' | 'app' | 'other'. HOW the report reached the
                                     --   agency — settled by Johan's ruling (§0c/§3.2a). 'app' is a
                                     --   reserved placeholder for Andre's future tenant-app channel.
  captured_by_user_id                -- NEW, 2026-09-25, nullable FK users. WHO typed this report
                                     --   into CoreX on the reporter's behalf. Set on every row
                                     --   today (every report is agent-captured, §3.2a) — nullable
                                     --   specifically so a future self-service channel (a tenant
                                     --   reporting directly through the app) can leave this null
                                     --   without needing a schema change when that day comes.
  title                              -- short label, e.g. "Damp patch — main bedroom ceiling"
  description                        -- free text, what was reported
  status                             -- enum: 'reported' | 'awaiting_approval' | 'approved' |
                                     --   'declined' | 'work_order_raised' | 'owner_handling' |
                                     --   'resolved' | 'cancelled'. Tracks the PROCESS. See below for
                                     --   how this relates to a linked work order's own status once
                                     --   one exists. 'owner_handling' is NEW, 2026-09-25 (§0c/§3a.1)
                                     --   — the owner approved and is fixing it themselves; no work
                                     --   order will ever be raised for this report.
  owner_approval_status               -- enum: 'not_required' | 'pending' | 'approved' | 'declined'
                                     --   — same shape as rental_work_orders' own field (§3.1),
                                     --   applied HERE first: Johan's lifecycle is "reported → owner
                                     --   approval where required → work order raised → outcome" —
                                     --   approval happens at the FAULT-REPORT stage, before a
                                     --   supplier is ever engaged. Denormalized from the latest
                                     --   `rental_approvals` row (§3.4a) for this report. See §3a.1.
  approval_route                     -- NEW, 2026-09-25, nullable enum: 'agency_appoints' |
                                     --   'owner_handles'. Set only once owner_approval_status
                                     --   reaches 'approved' — Johan's ruling that "approved" is not
                                     --   one outcome but two (§0c/§3.4a). Denormalized from the
                                     --   latest `rental_approvals` row, same as owner_approval_status.
  outcome                            -- nullable enum: 'repaired' | 'repaired_partially' |
                                     --   'not_repaired' | 'owner_declined' | 'tenant_liable'. Set
                                     --   only once status='resolved'. See §3a.2 — this is the field
                                     --   the whole 2026-09-24 ruling is actually about.
  outcome_note                        -- text, required whenever outcome is anything other than
                                     --   'repaired' (mirrors rental_work_orders' own "notes
                                     --   required unless the condition is good" pattern from
                                     --   rental-inspections.md §0.3) — an outcome of
                                     --   'not_repaired' or 'tenant_liable' with no explanation is
                                     --   exactly the kind of bare label this whole spec's evidence
                                     --   philosophy (§1) argues against.
  repaired_at                        -- NEW, 2026-09-25, nullable date. WHEN the repair actually
                                     --   happened, as reported — independent of who did it or who
                                     --   paid. Johan's own words: "the important part is capturing
                                     --   if and when the repairs were carried out." This is that
                                     --   field — the spine of the record (§0c/§3a.1). Meaningful
                                     --   only when outcome is 'repaired' or 'repaired_partially';
                                     --   null for every other outcome. Distinct from resolved_at
                                     --   below: an agent may record a repair days after it actually
                                     --   happened — the date that matters at move-out is when the
                                     --   geyser was actually fixed, not when CoreX found out.
  reported_at
  resolved_at                        -- nullable, when the record itself was marked resolved in
                                     --   CoreX (i.e. when outcome was set) — see repaired_at above
                                     --   for the separate, more important "when did it actually
                                     --   happen" fact.
  cancelled_at, cancelled_by_user_id, cancel_reason  -- nullable, for a report logged in error
  created_by_user_id
  created_at, updated_at, deleted_at   -- soft-delete, gated identically to rental_work_orders
                                      --   (§3.1): deletable only while nothing has been logged
                                      --   against it (no photo, no linked work order); once
                                      --   anything exists, only 'cancelled', never destroyed. FICA
                                      --   five-year retention applies at its strictest, same as
                                      --   every other evidence table in this spec and its siblings.

rental_fault_report_photos           -- evidence at the time of report — "a tenant reporting damp
                                     --   sends a picture," same weight as any other photo in this
                                     --   spec's evidence chain
  id
  agency_id
  rental_fault_report_id
  storage_path
  uploaded_by_user_id
  client_idempotency_key               -- uuid, unique — same offline-safety pattern as
                                       --   rental_work_order_photos/rental_inspection_photos
  file_size_bytes
  created_at                           -- immutable, no deleted_at — same evidence-integrity
                                       --   reasoning as every other photo table in this spec.
                                       --   Deliberately NO photo_type column here (unlike
                                       --   rental_work_order_photos' reported/in_progress/completed
                                       --   split) — a fault report's photos are all "as reported";
                                       --   REPAIR evidence lives on the linked work order's own
                                       --   photos once one exists, keeping the two evidence trails
                                       --   cleanly separated by which record they belong to, not by
                                       --   a type flag on a shared table.

rental_approvals                      -- NEW, 2026-09-25 (§0c/§3.4a) — append-only evidence log for
                                      --   an owner's approval decision, shared between fault reports
                                      --   (the normal path, §3a.1) and work orders raised directly
                                      --   without an upstream fault report. Same evidence-integrity
                                      --   shape as rental_work_order_updates: no updated_at, no
                                      --   deleted_at, never edited after the fact.
  id
  agency_id
  rental_fault_report_id                -- nullable FK. Exactly one of this and the next column is
                                        --   set, matching this spec's own established "exactly one
                                        --   of" pattern (§3.2).
  rental_work_order_id                  -- nullable FK. Set only for a work order raised WITHOUT an
                                        --   upstream fault report (owner-instructed proactive work,
                                        --   or straight from an inspection observation) — a work
                                        --   order raised FROM an already-approved fault report never
                                        --   gets its own row here; it inherits the fault report's
                                        --   decision instead (§3a.1).
  decision                              -- enum: 'approved' | 'declined'
  approval_route                        -- nullable enum: 'agency_appoints' | 'owner_handles'.
                                        --   Required when decision='approved' AND
                                        --   rental_fault_report_id is set; meaningless (always null)
                                        --   at the work-order level, since a work order's mere
                                        --   existence already means the agency-appoints route was
                                        --   chosen — Johan's two-outcomes ruling is a fault-report-
                                        --   stage decision (§0c/§3.4a).
  evidence_type                         -- enum: 'whatsapp' | 'email' | 'verbal_note' — Johan's
                                        --   ruling: approval is always in writing. 'verbal_note' is
                                        --   the honest fallback for an undocumented phone approval,
                                        --   kept rare by design, not the default path (§3.4a).
  evidence_text                         -- nullable text — the pasted content of the WhatsApp
                                        --   message or email, when there's no file to attach.
  evidence_file_path                    -- nullable string — an uploaded screenshot or forwarded
                                        --   email saved as a file. Reuses the existing storage
                                        --   pattern (no second pipeline).
  decided_at                            -- when the owner actually decided — may predate when the
                                        --   agent typed this row in.
  recorded_by_user_id                   -- the agent who captured this evidence.
  created_at                            -- immutable, no updated_at, no deleted_at — the
                                        --   "comprehensive log" evidence-integrity reasoning applied
                                        --   to approval the same way it already applies to
                                        --   rental_work_order_updates and every photo table here.
```

### 3a.1 Owner approval happens here, before a work order exists — and splits into two routes, 2026-09-25

Johan's lifecycle, verbatim: "reported → owner approval where required → work order raised → outcome."
This means `owner_approval_status` on the FAULT REPORT (not only on the work order) is where the gate
actually first applies — an agent cannot move a fault report to `work_order_raised` while approval is
`pending`/`declined`, mirroring exactly the gate already built for `rental_work_orders.status='ordered'`
(§3.4). §3.4a has the full evidence mechanism (the `rental_approvals` table); this section is about
what happens to the fault report itself once a decision is recorded.

**Approval is not one outcome, it is two** (§0c, Johan's ruling): recording `decision='approved'` on a
`rental_approvals` row also requires `approval_route`:

- **`approval_route='agency_appoints'`** — the fault report moves to `status='work_order_raised'` once
  the agency actually raises a `rental_work_orders` row from it (`reported_by_type='fault_report'`,
  §3.1). **When a work order IS raised from an already-approved fault report, its own
  `owner_approval_status` is set to `approved` directly, inherited from the fault report** — the owner
  is not asked twice for one decision.
- **`approval_route='owner_handles'`** — the fault report moves to `status='owner_handling'` instead.
  **No `rental_work_orders` row is ever created for this report.** This is not a waiting state pending
  a work order that might still come — it is the terminal working state for this route, and it stays
  there, correctly, until the agent later records the outcome (§3a.2), at which point `repaired_at`
  captures the one fact that actually matters (§0c): whether and when the owner's own contractor or
  caretaker actually did the work.

A work order raised WITHOUT an upstream fault report (owner-instructed proactive work, or directly from
an inspection observation) still goes through its own approval gate independently, via its own
`rental_approvals` row (`rental_work_order_id` set instead of `rental_fault_report_id`) — since there
was no upstream fault report to have already asked the question. `approval_route` is not meaningful at
that level (a work order's existence already implies the agency-appoints path); only `decision` matters
there.

**Built, Stage 2 (2026-09-26): `awaiting_approval` is agent-set, evidence-free, and optional — `[cc4
design call]`.** An agent can mark a fault report `status='awaiting_approval'` /
`owner_approval_status='pending'` purely to track "I've asked, waiting to hear back" on the list screen —
this records no evidence (Johan's ruling requires evidence only for the actual DECISION). Crucially, it
is **not a required step** before recording a decision: an agent who already has the owner's written
reply in hand records it directly from `reported`, with no pointless intermediate click enforced. If
Johan wants `awaiting_approval` to be a mandatory gate instead, that's a one-line change to
`RentalFaultReport::recordApproval()`'s guard, not a schema change.

**Built, Stage 2: approval evidence file upload is image-only, same as every other upload in this
feature family.** A screenshot of the WhatsApp reply or the email is the natural artifact and reuses
`PropertyImageStorer` with no second pipeline (§3a.3's own reasoning, applied here too). A genuine
document attachment (a forwarded `.eml`/`.pdf`) is a real but separate future need — `evidence_text`
(required regardless of channel) covers the pasted-content case in the meantime, so no evidence is ever
blocked by this restriction, only the file-attachment format is narrower than "anything."

### 3a.1a Investigated, not built: could an owner's approval email file itself?

Per the conductor's explicit instruction, this is a read-only investigation, reported for Johan's
information — nothing here is built by this amendment.

**The question**: CoreX already polls agency mailboxes and archives inbound mail (confirmed:
`app/Console/Commands/Communications/PollMailboxes.php` → `PollMailboxJob` →
`ImapMailboxPoller` — plain IMAP, not Microsoft Graph or Gmail API, nothing else exists). Could an
owner's "approved" reply be filed against a `rental_fault_reports` row through that existing machinery,
instead of the agent uploading a screenshot or pasting the text by hand into `rental_approvals`
(§3.4a)?

**What the archive already does, checked directly against the code**: every polled email is dedup'd,
stored, and matched to a sender `Contact` where possible
(`app/Services/Communications/EmailArchiveIngestor.php`). The linking table,
`communication_links` (`communication_id`, `linkable_type`, `linkable_id`, `link_method`,
`confidence`), is a genuine polymorphic association — it is not schema-restricted to any one model.
Today it only ever points at `Contact::class` (fully automatic, by matching the sender's email address)
or `DealV2::class` (manual, or attorney-correspondence-suggested). Nothing in the pipeline parses email
CONTENT — there is no keyword or NLP step anywhere that would recognise "approved" or "go ahead" in a
message body; matching today is entirely about WHO sent it, never WHAT it says.

**The verdict: this is a small reuse, not a from-scratch build, IF an agent still confirms the link —
true content-based automation is a real, separate build.**

- **What already covers most of the gap**: `communication_links.linkable_type/linkable_id` needs no
  migration to accept a new target — pointing it at `RentalFaultReport::class` (or `RentalApproval`
  directly) is a config/code change, not a schema change. The IMAP polling, dedup, storage, and
  contact-matching machinery is entirely channel-agnostic and needs no changes at all.
  A screen modeled directly on the existing `Dr2CommunicationLinkController` (search the archive, pick
  the email, link it) would let an agent file an already-archived owner email against a fault report's
  `rental_approvals` row in a few clicks, instead of re-uploading or re-typing content CoreX already
  has a copy of. **This alone is a real, worthwhile win over today's plan, and is cheap.**
- **What is genuinely missing for TRUE automation** (the owner's reply files itself with no agent
  action): the archive has no reply-thread or reply-token correlation today — nothing ties an inbound
  reply back to the specific outbound approval-request email it answers. Building that would need a
  unique reply-to address, `In-Reply-To`/thread-key tracking, or a token embedded in the outbound
  request (the same shape §3.2a's tokened-link option already costs for tenant reporting) — and even
  then, still no content parsing exists to distinguish "approved" from "declined" from "what's this
  about?" without either a human confirming the link or a much larger investment in structured reply
  parsing. This is a real build, not a small one, and is not proposed here.

**Recommendation, for Johan, not decided by this spec**: the cheap half (an agent linking an
already-archived email to a fault report's approval, reusing `communication_links`) is a genuine
improvement worth doing at some point — it turns "screenshot and re-upload" into "search and click" —
but full hands-off automation is a separate, larger decision with its own cost, not a natural extension
of this feature. Neither is built by this amendment.

### 3a.2 Outcome, not status — the field this whole amendment is actually about

Johan's own example makes the stakes concrete: an agent standing in a property at month 16, looking at
a stained ceiling, needs to know whether that geyser burst in month 7 was **repaired** (the stain is
old, harmless, cosmetic) or the owner **declined** to fix it (the stain is current, ongoing, the
owner's problem) — "closed" tells that agent nothing. Five outcomes, each chosen because it changes
the conclusion an out-inspection draws differently from every other one:

- **`repaired`** — fully fixed. The fault is resolved and, absent a NEW later observation, the item's
  current condition is trusted. `repaired_at` (§3a) is required with this outcome — Johan's own
  framing of what matters ("if and when the repairs were carried out") is answered by outcome + this
  date, independent of `approval_route`: a `repaired` outcome reached via `owner_handles` (owner's own
  plumber) is exactly as complete and correct a record as one reached via a completed work order.
- **`repaired_partially`** — some of the problem was addressed, not all of it (a supplier fixed the
  burst pipe but the water-damaged ceiling board itself was never replaced). Distinct from
  `repaired` specifically because an out-inspection reading `repaired` and finding damage anyway would
  wrongly conclude the tenant caused NEW damage, when in fact it's the SAME damage, never fully closed
  out. `repaired_at` is required here too — for the part that WAS done.
- **`not_repaired`** — nothing was done. `outcome_note` must say why (no supplier available, ran out
  of time before move-out, genuinely forgotten) — this is the direct evidentiary answer to situation 3
  in §1's table, now anchored on the fault report rather than inferred from a work order that may
  never have existed.
- **`owner_declined`** — the owner was asked and said no. Deliberately separate from `not_repaired`:
  this is an AFFIRMATIVE decision on record (via §3a.1's approval gate, or a direct decline before a
  work order was ever proposed), not mere neglect — a materially different fact for a dispute than
  "nobody got around to it."
- **`tenant_liable`** — the determination is that the tenant caused it and the cost is theirs,
  independent of whether physical repair happened yet. **Argued, not left unexamined**: this mixes a
  liability judgement into what is otherwise a physical-repair-state field, which is not perfectly
  clean — but Johan's own framing lists it as a peer of the other four ("repaired, repaired partially,
  not repaired and why, owner declined, tenant liable"), and a single, plain-language "how did this
  end" value that an agent can read at a glance is more useful at move-out than decomposing repair-
  state and liability-state into two separate fields an agent would have to cross-reference. Kept as
  Johan specified it.

### 3a.3 Photos on fault reports — same pipeline, same thinking

`rental_fault_report_photos` reuses `PropertyImageStorer` exactly like `rental_work_order_photos` and
`rental_inspection_photos` already do — no new upload pipeline, no new sizing/encoding decision. "A
tenant reporting damp sends a picture" carries the same evidentiary weight this whole spec already
gives every other photo: `client_idempotency_key` for offline-safe retries (§13), immutable once
uploaded, no deletion. The one structural choice, argued above (§3a schema block): fault-report photos
are always "as reported," and repair-evidence photos live on the linked work order once one exists,
rather than a shared `photo_type` column trying to serve two different records' worth of meaning.

### 3a.4 Scoped by lease for the out-inspection, not by property

Johan's distinction, direct: *"a fault from the previous tenant's occupancy is not this tenant's
context, though it may still matter to the owner."* This is a deliberate CONTRAST with how
`rental_inspection_items`' own carry-forward already works (`RentalInspection::carryForwardItems()`,
`rental-inspections.md` §0.2/§3.2a) — that query is intentionally PROPERTY-wide, because a physical
space outlives any one tenancy and its full condition history matters regardless of who was living
there. A fault report's relevance to an out-inspection is the opposite shape: **the out-inspection's
attached sub-report queries `rental_fault_reports` filtered to `lease_id = <this lease>` only** — the
current tenant's own tenancy, nothing from before it. The owner-facing, agency-wide list screen (§6)
is NOT lease-scoped the same way — an owner or admin reviewing "every fault ever reported on this
property" legitimately wants the property-wide view across every tenancy; only the specific,
attached-to-an-out-inspection sub-report is deliberately narrowed to the one tenancy it's judging.

### 3a.5 Attached to the out-inspection, not merged into it

Johan, verbatim: *"so might not need to be part of the actual out inspection, but Im seeing an
attached report of faults and their repairs."* Concretely: when the out-inspection screen (the
Rental Images tab's rebuilt Out Inspection section, `rental-inspections.md` §4) is open, it fetches and
displays `rental_fault_reports` for `lease_id = <this lease>` (§3a.4) as its own, clearly separate
block — title, outcome, outcome note, dated — sitting ALONGSIDE the out-inspection's own item/
observation recording, never interleaved into it. Two records, one screen, exactly as instructed: the
out-inspection records what the agent observes NOW; the attached report shows what happened DURING the
tenancy, so the agent can judge the former correctly in light of the latter. No schema change to
`rental_inspections`/`rental_inspection_observations` is needed for this — it is a second query the
out-inspection screen runs and renders next to its own data, not a join or a merge.

---

## 4. Notifications — owner, tenant, supplier, and what each actually says

Per §2's reused infrastructure: **internal staff through `NotificationDispatcher`, external parties by
plain mail** — exactly the DR2 split, no new mechanism invented.

New event keys to register (idempotent migration, matching
`2026_08_03_000003_register_fica_referred_to_co_notification.php`'s pattern):
- `rental_work_order.created` — fires to the property's assigned agent (internal, via `fire()`).
- `rental_work_order.overdue` — fires to the assigned agent (and their branch manager, [cc4 design
  call]) when a work order has sat in `ordered`/`in_progress` past
  `rental_work_order_settings.overdue_reminder_days` with no status change — this is the "tracking way
  to keep track of... if the work has been completed or not" Johan explicitly asked for, not just a
  passive list screen.
- `rental_work_order.completed` — fires to the assigned agent (internal) as confirmation.
- `rental_fault_report.created` — **new, 2026-09-24** — fires to the property's assigned agent
  (internal) the moment a fault report is logged, independent of whether a work order ever follows.
- `rental_fault_report.resolved` — **new, 2026-09-24** — fires to the assigned agent when a fault
  report's `outcome` is set (§3a.2), whatever that outcome is.

External mail, one Mailable class per recipient (plain `Mail::to(...)->send(...)`, modeled on
`DealDistributionService`/`CocWorkOrderService`'s existing pattern):

- **Owner** (resolved via `Property::sellerOwnerContact()`) — on creation: a plain-language notice that
  a work order has been logged against their property, naming the issue and (if known) the trade type.
  On completion: confirmation it's done, who paid (if the owner is the payer, this is their invoice
  trail), and the completion photos attached or linked. **Skipped, not attempted, if the property
  genuinely has no owner attached** (the known, non-bug portal-import-stock state per §2) — logged as a
  no-op, not a failure.
- **Tenant** (resolved via the lease's `lease_tenants`, if `lease_id` is set — see §3.1a for the
  vacancy case where there is no tenant to notify at all) — **the acknowledgement now happens at fault
  report creation (§3a), not work-order creation, 2026-09-24 amendment**: the moment a tenant's fault
  is logged, they get confirmation it's on record — this no longer waits for a work order to exist,
  since §1a/§0b's whole point is that a fault report is real and evidenced on its own, whether or not
  one follows. A work order raised without an upstream fault report (agent/owner-originated) still
  notifies the tenant on creation as before. On resolution: confirmation of the outcome (§3a.2) —
  "repaired," not just "closed," so the tenant sees the same honest conclusion the out-inspection will
  later read. **This is the mechanism that makes a tenant's fault report accountable** — situation 3
  in §1 depends on the tenant being able to show they reported it, which this notification (and the
  fault report's own timestamped record) both corroborate independently.
- **Supplier** (resolved via `agency_service_provider_id`, only once one is assigned — "can even email
  the supplier," an optional step per Johan's own wording) — the job details: property address,
  description, trade type, and who to contact back. Sent to the specific `AgencyServiceProviderContact`
  email if one exists for that provider, the provider's own `email` otherwise — same resolution the
  existing COC-work-order supplier picker already uses. **This email is automated — CoreX sends it.**
  **WhatsApp is NOT automated, said plainly rather than left to imply parity with email:** the only
  WhatsApp capability anywhere in CoreX is a `wa.me` link (`SigningWhatsAppLinkService`'s pattern) that
  opens the AGENT's own WhatsApp with a pre-filled message they send themselves — there is no server-
  side WhatsApp send. If an agent wants to also notify a supplier over WhatsApp, the work-order screen
  can offer the same "open a pre-filled wa.me link" button already proven elsewhere, but that is a
  manual action the agent takes, not a second automated channel. **Not built here, flagged as a future
  enhancement only**: a secure-link reply mechanism (DR2 already has one — `DealSecureLinkMail`,
  `DealDistributionService.php:236` — that lets an external party act without a CoreX login) that would
  let a supplier mark their own job complete without an agent doing it manually. Johan asked only for
  "can even email the supplier," not for a supplier portal — this spec ships the email; the reply-link
  upgrade is named so it isn't rediscovered as if new.

---

## 5. What must be settled (per the task's own checklist) — remaining items

### 5.1 Cost — a field, deliberately not a financial feature

`cost_amount` is a plain nullable decimal, matching `paid_by`'s evidentiary purpose ("the owner paid X
to fix this"). **Settled, 2026-09-22: finances are OUT of this spec, full stop — REOS keeps handling
the money until rentals is bug-free and there is time to build a real financial layer.** This spec does
**not** build, and this amendment does not change that: invoicing, a trust-ledger reconciliation, or
multi-line-item costing. `cost_amount`/`paid_by` record what was authorised and what happened — they
never compute, reconcile, or trigger a payment. Designed so a financial layer can attach later without
rewriting this table (a future `financial_transaction_id` FK, for instance, slots on rather than
replacing anything here) — but nothing financial is built now, and nothing here should be read as a
promise that one is coming on any timeline.

### 5.1a Deposits — out of scope except advertising deposits, named as a real gap

**Settled, 2026-09-22: deposits are OUT of scope except advertising deposits.** This has a direct,
honest consequence for this spec that should be named rather than papered over: **damage found at
move-out has no financial home in CoreX today.** `paid_by='deposit_deduction'` remains in §3's enum as
a description of INTENT — an agent can record "this should come out of the deposit" as a fact about
the decision that was made — but CoreX does not hold a deposit ledger, does not calculate a deduction,
and does not move any money when that value is set. It is a label, not a transaction. This is named
here as a known, current limitation of the whole rentals feature set, not something this spec invents
or is expected to solve — the same "flag it, don't fake it" treatment `leases.md` §3.3 already gives
its own deposit-tracking gap.

---

## 6. The Rental Work Orders screens — CRUD/list-screen floor (BUILD_STANDARD §1a-§1d)

Two surfaces, matching the tab-vs-list-screen duality both sibling specs already establish:

**On the property itself** — a "Work Order" button (Johan's own words: "on rentals on a property we
have a work order button"), placed on the property's Rental tab (`resources/views/corex/properties/
show.blade.php`, the same tab `rental-images.md`/`rental-inspections.md` extend), opening the log/create
form and, below it, that property's own work-order history (filterable by space/item — "an out-inspection
of bedroom 2 must be able to pull bedroom 2's history," per the task's own instruction, satisfied by
filtering this list on `rental_inspection_item_id`).

**A new agency-wide Rental Work Orders list screen** — route group `corex.rental-work-orders.*`, sidebar
entry under the existing Rentals section, alongside Leases and Rental Inspections:

- **Search** (named fields): property address, tenant name, supplier name, title/description.
- **Sort**: reported date, status, priority (if built), property address. **Default: reported date,
  most recent first** — stated explicitly per §1b, matching the inspections list's own default
  reasoning (what needs attention now is what you look at first).
- **Filter**: status (minimum per §1b), trade type, date range (minimum per §1b), property, paid_by (for
  an owner-facing cost review), and a domain-specific one this feature genuinely needs — **"overdue"**
  (an `ordered`/`in_progress` work order past its agency's `overdue_reminder_days` with no update) — the
  same tracking requirement Johan named for both this feature and inspections.
- **Pagination**: standard page size.
- **Empty state**: distinct copy for "no work orders yet on this agency" vs "none matching this filter,"
  per §1b.
- **Scoping**: `rental_work_orders`/`rental_work_order_updates`/`rental_work_order_photos` all `use
  BelongsToAgency` + `AgencyScope`; OWN/BRANCH visibility layered on top via the same role pattern
  already used by leases and rental inspections. Direct-URL access to another agency's work order by ID
  is a 404 via the global scope, not a hidden link.

**2026-10-05 QA1-walk follow-up** (`rentals-foundation-at439.md` §11 — full writeup): the property
filter above is now a real search-as-you-type picker (`corex.rental-work-orders.search-filter-
properties` — deliberately NOT the existing `search-properties` name, which is the CREATE screen's
own unscoped picker, AT-442 fix #2, left untouched), restricted to properties with a work order
visible to this user. Previously `property_id` was reachable only via a link from elsewhere.

### 6a. Fault reports — a third surface, 2026-09-24 amendment

Same floor, own screens — a fault report is its own record (§3a), not a tab within work orders:

**On the property itself** — alongside the Work Order button, a "Report a Fault" button opening the
log form (what's wrong, who reported it, optional photo) and that property's own fault-report history.

**A new agency-wide Rental Fault Reports list screen** — route group `corex.rental-fault-reports.*`,
sidebar entry under Rentals alongside Leases, Rental Inspections, and Rental Work Orders. Same search/
sort/filter/pagination/empty-state/scoping floor as §6's work-order list (property, tenant, title/
description search; reported date default sort; status, outcome, and date-range filters; agency+own/
branch scoping via `BelongsToAgency`+`AgencyScope`).

**2026-10-05 QA1-walk follow-up** (`rentals-foundation-at439.md` §11): the property filter is now a
real search-as-you-type picker (`corex.rental-fault-reports.search-properties` — new, this list had
no endpoint of its own before), restricted to properties with a fault report visible to this user
and respecting the list's own "Show archived" state.

**Attached to the out-inspection screen** — the piece Johan actually asked for (§3a.5): when an
out-inspection is open, a "Fault & Repair History" block queries `rental_fault_reports` scoped to
`lease_id = <this lease>` (§3a.4) and renders each one — title, outcome, **`repaired_at`** (the spine
field, §0c/§3a.1 — shown even when no work order was ever raised), outcome note, dated — alongside,
never inside, the out-inspection's own item/observation recording. This is the "sub report" Johan
described, and it is read-only from the out-inspection screen — a fault report is resolved from its own
screen or the property tab, not edited from inside someone else's inspection.

---

## 7. The four-situation test, walked through against the actual schema

Proving §1's table against §3's fields, concretely, so this isn't asserted without being shown.
**Updated, 2026-09-24**: situations 2 and 3 are tenant-originated, so they now read primarily off
`rental_fault_reports` (§3a) — the record that exists whether or not a work order ever follows —
rather than off `rental_work_orders` alone, matching §3.2's new preference that a tenant report
creates a fault report first.

1. **Tenant broke it, never reported, still broken at move-out.** No `rental_fault_reports` row and no
   `rental_work_orders` row exists linking to that item for the relevant lease period. The
   out-inspection's attached fault-report block (§3a.5), scoped to `lease_id = <this lease>` (§3a.4),
   finds nothing in that window — the absence is itself the record. The current tenant is responsible.
2. **Tenant broke it, reported it, repaired, owner already paid.** A `rental_fault_reports` row exists:
   `reported_by_type='tenant'`, `status='resolved'`, `outcome='repaired'`, `repaired_at` set, linked via
   `lease_id` to the relevant tenancy. **Two equally valid ways this reaches that state, per the
   2026-09-25 ruling (§0c/§3a.1):** `approval_route='agency_appoints'` with a linked `rental_work_orders`
   row (`reported_by_type='fault_report'`, `status='completed'`, `paid_by='owner'`, a `completed` photo
   attached) — the path this spec already modeled — OR `approval_route='owner_handles'` with **no work
   order at all**, the owner's own plumber having done the work, `outcome_note` naming who. Either way,
   the out-inspection's attached block shows `outcome='repaired'` and `repaired_at` against the item
   before the agent even looks at its current condition — the tenant is not charged again for something
   already settled, and "who fixed it" no longer decides whether the record can reach this state.
3. **Tenant reported it, nobody fixed it.** A `rental_fault_reports` row exists: `reported_by_type
   ='tenant'`, `reported_at` set, but `status` never reaches `resolved` (or reaches it with
   `outcome='not_repaired'`, `outcome_note` explaining why). No linked work order needs to exist at all
   — the fault report alone, now that it survives independently of one, is the owner's neglect on
   record, not the tenant's fault, regardless of what the out-inspection observes on that item now.
   (`outcome='owner_declined'` is the sharper version of this same situation — see §3a.2 for why it's
   kept distinct from plain `not_repaired`.)
4. **A contractor repaired it badly.** A `rental_work_orders` row exists (raised from a fault report or
   directly): `status='completed'`, `agency_service_provider_id` set (a specific, named supplier),
   `completed_at` dated. A LATER `rental_inspection_observation` on the same item (via the shared
   `rental_inspection_item_id`) shows damage again, dated after `completed_at`. The timeline — completed
   repair, then a later bad-condition observation — points directly at the contractor's work, not the
   tenant occupying the property at the time of that later observation.

Every one of the four is answered by fields this spec (now across two tables, `rental_fault_reports`
and `rental_work_orders`) already defines for other, independently-justified reasons — who reported it,
its outcome, who paid, the stable item link — no additional bare "fault" field was needed to pass this
test, which is itself evidence the schema is shaped correctly rather than patched to fit afterward.

---

## 8. Agency settings — every threshold configurable, sensible defaults, never hardcoded

`rental_work_order_settings` (§3.1): `completion_requires_photo` (default **false**, corrected
2026-09-21 — Johan: a mandatory photo has no meaningful use for some repairs, e.g. a gate motor,
and pushes people into staging a pointless picture or interfering with equipment they shouldn't
touch. An agency may still switch this on if it wants photo evidence on every job), and
`overdue_reminder_days` (default **3**, a `[cc4 design call]` since Johan didn't specify a number —
flagged for Johan to confirm or adjust at build time, same treatment `lease_settings.expiry_notice_
window_days` gets for its own unconfirmed number in `leases.md` §5.2, though that one is pending legal
confirmation and this one is pending only an operational preference).

**Settled, including the override — current shape 2026-09-29 (§3.4b):**
`rental_work_order_settings.no_approval_spend_threshold`, agency-configurable, default **R500**, plus
`properties.rental_no_approval_spend_threshold` — nullable, null meaning "use the agency default" — as
the override, sitting with the property's other rental-tab money fields (`deposit_amount`, `admin_fee`).
The lease-level override built 2026-09-26 (`leases.rental_no_approval_spend_threshold`) is retired —
Johan reversed his own ruling 2026-09-29, live, looking at the lease screen: "per property, populated to
the leases screen." Setup Wizard entry for the agency-level setting is designed in from the start
(non-negotiable #10a) — the property-level override, being per-property rather than an agency-wide
onboarding choice, belongs on the property record itself, not the wizard.

---

## 9. Raised, not decided — the tenant-link reminder question

`leases.md`/the rental-application flow makes linking a tenant to a property (now: to a lease) a
**deliberate manual press by the agent**, matching Johan's standing rule that consequential actions
require a press, not an automatic side-effect of approval. This is correct and not re-argued here.

**But it has a real consequence for this spec**: if an agent forgets that press, a work order raised
against that lease has no tenant to notify (§4) — not because of anything wrong in this spec, but
because the upstream link was never made. **The question, put to Johan, not decided here**: should
there be a reminder (a dashboard nudge, a notification-dispatcher event, anything short of an automatic
link) that a lease's tenant-link is outstanding, so this failure mode is caught before a work order
silently fails to reach a real tenant? Raised exactly as instructed — a reminder is not the same as an
automatic link, and the choice of whether to build even the reminder is Johan's, not assumed here.

---

## 10. Permissions

**BUILT, Stage 4, 2026-09-28** — all keys below exist in `config/corex-permissions.php`, named for what
they do, not bent around the Role Manager's own generic-CRUD-slot display quirk (a shared rendering
issue across the whole app, cc2's fix, out of this spec's lane — see the third rename listed just
below). Naming convention, following `leases.*`/`rental_inspections.*`:
- `rental_work_orders.view`
- `rental_work_orders.create` (covers logging a fault, assigning a supplier, adding notes/photos)
- `rental_work_orders.complete` — deliberately separate from `.create` **[cc4 design call, flagged for
  Johan]**, mirroring `rental_inspections.resolve_discrepancy`'s own reasoning: marking a work order
  complete is the point where evidence (photo + payer) becomes final, arguably warranting a tighter
  grant than "anyone who can log a fault." Collapsing it into `.create` at build time is a one-line
  change if this distinction is unwanted.
- `rental_work_orders.record_approval` — **new, 2026-09-22**, `[cc4 design call, flagged for Johan]`:
  whoever records an approval decision (writing a `rental_approvals` row, §3.4a) is making the same
  weight of call as resolving a discrepancy or completing a job — separate from `.create` for the same
  reason those two already are. Now, 2026-09-25, this is the ONLY gate on recording an approval at all
  (the mechanism settled toward agent-captured evidence, not a tokened link, §3.4a) — matters more, not
  less, than when this key was first proposed.
- `rental_work_orders.cancel`

**New, 2026-09-24 — fault reports get their own keys**, same naming convention, since they're now
their own record rather than a work-order status:
- `rental_fault_reports.view`
- `rental_fault_reports.create` (covers logging a fault, adding photos)
- `rental_fault_reports.record_approval` — same reasoning as `rental_work_orders.record_approval`
  above, applied one stage earlier per §3a.1.
- `rental_fault_reports.resolve` (setting the outcome, §3a.2) — deliberately separate from `.create`,
  same reasoning as `.complete` on work orders: an outcome becoming final is a heavier call than
  logging what was reported.
- `rental_fault_reports.cancel`
- `rental_fault_reports.raise_work_order` — **new, Stage 4** — raising an actual work order from an
  already-approved fault report (the agency_appoints route, §3a.1) is its own distinct action, separate
  from `.record_approval` itself: approving and then raising are two separate decisions, at two separate
  times, per §0c/§3a.1's own settlement.

---

## 11. Files (ALL FIVE STAGES now BUILT and landed — see inline notes for exactly what and when)

- **BUILT, Stage 1, 2026-09-25 (fault reports §3a — the record itself):**
  - `database/migrations/2026_09_25_100000_create_rental_fault_reports_table.php` — includes the
    `rental_work_order_id` column with NO foreign-key constraint (`rental_work_orders` doesn't exist
    until Stage 4) — a plain indexed column now, the real FK added in Stage 4's own migration. Caught
    by a real test run, not lint (see the Stage 1 commit message).
  - `database/migrations/2026_09_25_100100_create_rental_fault_report_photos_table.php`
  - `database/migrations/2026_09_25_100200_register_rental_fault_report_created_notification.php`
    (idempotent, §4 — `.resolved` deferred to Stage 2, below).
  - `app/Models/RentalFaultReport.php`, `RentalFaultReportPhoto.php` — `use BelongsToAgency`. NO
    `workOrder()` relation yet — a PHP relation method must instantiate its related class the moment
    it's CALLED, not just referenced by `::class`, so it can't be defined against
    `App\Models\RentalWorkOrder` before Stage 4 builds that class (also caught by a real test run).
  - `app/Services/Rentals/RentalFaultReportService.php` — `report()`, `notifyCreated()`.
  - `app/Http/Controllers/CoreX/RentalFaultReportController.php` — thin, calls the service/model.
  - **No Mail classes were built** — notifications for fault reports go through
    `NotificationDispatcher::fire()` (internal, to the assigned agent) per §4's own design; there is no
    external-party (owner/tenant) mail for fault reports specifically in the spec as written, unlike
    work orders' three-recipient mail set. If Johan wants the owner/tenant notified directly on a fault
    report (not just the agent), that's a real, undecided addition — flagged here, not built.
  - `resources/views/corex/rental-fault-reports/index.blade.php`, `create.blade.php`, `show.blade.php`
    (§6a's list screen + the record/detail views).
  - The property-tab "Report a Fault" button + recent-history list — built directly inline in
    `resources/views/corex/properties/show.blade.php` (the existing Rentals tab area), not as a separate
    partial file — matching how the adjacent "Create lease" button is done in that same file, not
    `rental-tab-fault-reports.blade.php` as originally sketched here.
  - **NOT built yet**: the out-inspection's "Fault & Repair History" attached block (§3a.5/§6a) —
    that's Stage 5, deliberately last, once `rental-inspections.md`'s out-inspection screen has this to
    attach to.
- **BUILT, Stage 2, 2026-09-26 (the lifecycle — §3a.1/§3a.2/§3.4a):**
  - `database/migrations/2026_09_26_100000_create_rental_approvals_table.php` — the single shared table
    for both fault-report-level and (future, Stage 4) work-order-level approval evidence.
    `rental_work_order_id` is a plain column, no FK constraint yet, same Stage-1-established pattern.
  - `database/migrations/2026_09_26_100100_register_rental_fault_report_resolved_notification.php`
    (idempotent, §4 — deferred from Stage 1 until this stage's outcome action existed to fire it).
  - `app/Models/RentalApproval.php` — `use BelongsToAgency`. NO `workOrder()` relation, same reasoning
    as `RentalFaultReport`'s own deferred relation.
  - `RentalFaultReport::requestApproval()`, `recordApproval()`, `setOutcome()` — the lifecycle logic
    lives on the model (matching `RentalInspection::start()`/`cancel()`'s own established pattern in
    this codebase), not the service; the service gained only `storeApprovalScreenshot()` and
    `notifyResolved()`.
  - `app/Http/Controllers/CoreX/RentalFaultReportController.php` gained `requestApproval()`,
    `recordApproval()`, `setOutcome()` — thin, validate-then-call, per §13's own discipline.
  - `resources/views/corex/rental-fault-reports/show.blade.php` gained the approval-recording and
    outcome-setting sections — hidden entirely once the report is `resolved`/`cancelled` (screen-space
    rule: no dead controls for a decision that's already final).
- **BUILT, Stage 3, 2026-09-26 (the spend threshold — §3.4b, including the override):**
  - `database/migrations/xxxx_create_rental_work_order_settings_table.php` — the full §3.1 schema
    (`completion_requires_photo`, `overdue_reminder_days`, `no_approval_spend_threshold`) even though
    only the threshold is live before Stage 4 — same "spec-complete from day one" discipline Stage 1
    applied to `rental_fault_reports`.
  - `database/migrations/xxxx_add_rental_no_approval_spend_threshold_to_leases_table.php` — the
    lease-level override, per Johan's ruling.
  - `app/Models/RentalWorkOrderSetting.php` — `use BelongsToAgency`, a
    `thresholdFor(Lease $lease)` resolver: lease override → agency default → the `DEFAULT_*` constant,
    same read-time-default pattern as `RentalInspectionSetting`.
  - `app/Http/Controllers/CoreX/RentalWorkOrderSettingsController.php` — mirrors
    `RentalInspectionSettingsController` exactly; registered as the THIRD saver on the existing
    onboarding "Rentals" step (§8, the slot `agency-onboarding-rentals-step.md`'s own placeholder
    already reserved), alongside `LeaseSettingsController` and `RentalInspectionSettingsController` —
    never merged into either. `resources/views/corex/settings/rental-work-orders.blade.php` +
    routes for the same page's own dedicated settings screen, matching rental-inspections' pattern
    exactly, plus a Settings-hub link (`resources/views/corex/settings.blade.php`).
  - `config/agency-onboarding-copy.php` — the real `no_approval_spend_threshold` control + saver, in
    the slot `agency-onboarding-rentals-step.md` already reserved for it.
  - `resources/views/corex/leases/show.blade.php` / `LeaseController::update()` gained the lease-level
    override field — this spec's one necessary, minimal touch of a `leases.md`-owned file, additive
    only (one nullable field, validated and saved alongside the existing ones, nothing else changed).
  - `tests/Feature/Onboarding/RentalsStepSaverIndependenceTest.php` — extended, not replaced: the
    combined-step test now posts all four fields, plus a new independence proof for the third saver
    (matching the two already there).

  **Superseded 2026-09-29 (Stage 7, §3.4b) — the lease-level override bullet above is historical, not
  current.** Johan reversed the property-vs-lease call live, looking at the lease screen: "per property,
  populated to the leases screen." `leases.rental_no_approval_spend_threshold` is dropped; the override
  now lives on `properties.rental_no_approval_spend_threshold`; `thresholdFor()` takes a `Property`, not
  a `Lease`; the lease show blade lost its edit field and reads through to the property instead. See
  Stage 7's own entry below for the current file list.
- **BUILT, Stage 4, 2026-09-28 (the work orders themselves — §3/§3.4/§6):**
  - `database/migrations/2026_09_28_100000_create_rental_work_orders_table.php`,
    `..._100100_create_rental_work_order_updates_table.php`,
    `..._100200_create_rental_work_order_photos_table.php`.
  - `..._100300_add_work_order_foreign_keys_deferred_from_stage1_2.php` — the two real FK constraints
    (`rental_fault_reports.rental_work_order_id`, `rental_approvals.rental_work_order_id`) deliberately
    deferred since Stage 1/2, added now that the referenced table exists. Safe: both columns hold only
    NULLs until this stage, since nothing before it could ever have set them.
  - `..._100400_register_rental_work_order_notifications.php` (idempotent, §4).
  - `app/Models/RentalWorkOrder.php`, `RentalWorkOrderUpdate.php`, `RentalWorkOrderPhoto.php` — `use
    BelongsToAgency`. `RentalWorkOrderSetting.php` (Stage 3) gained `completionRequiresPhotoFor()`.
    `RentalFaultReport`/`RentalApproval` gained their own deferred `workOrder()` relations (same
    forward-reference reasoning as Stage 1's own note, now resolved).
  - Lifecycle lives on the model, matching `RentalInspection`/`RentalFaultReport`'s own established
    pattern in this codebase: `assignSupplier()`, `recordApproval()`, `startProgress()`, `complete()`,
    `cancel()`, `addNote()`, `scopeOverdue()`.
  - `app/Services/Rentals/RentalWorkOrderService.php` — `report()` (raised directly), `fromFaultReport()`
    (the agency_appoints route, §3a.1 — a DELIBERATE, separate agency action, never automatic on
    approval alone), `storePhoto()`, and every notification (internal `NotificationDispatcher::fire()`
    plus the three external mails).
  - `app/Http/Controllers/CoreX/RentalWorkOrderController.php` — thin. `RentalFaultReportController`
    gained `raiseWorkOrder()`.
  - `app/Mail/Rentals/RentalWorkOrderOwnerMail.php` (created + completed stages, one class),
    `RentalWorkOrderTenantMail.php` (creation only, and only for a work order raised WITHOUT an
    upstream fault report — one raised FROM a fault report never sends this; the tenant was already
    notified at fault-report creation, §3a/§4), `RentalWorkOrderSupplierMail.php` — plus their three
    plain-HTML Blade views under `resources/views/emails/rentals/`.
  - `app/Console/Commands/Rentals/ScanRentalWorkOrderNotifications.php`, scheduled every 30 minutes
    (`routes/console.php`, matching `notifications:scan-deals`'s own cadence) — the "tracking way to
    keep track of work orders" Johan asked for. Keys its notification dedup off the work order's own
    `updated_at` (a stable, persistent-condition key), NOT `now()` — caught before landing: `now()`
    would have re-notified every single scan tick for the same stale work order, the exact
    "persistent condition" mistake `ScanDealNotifications`' own code comments warn against.
  - `resources/views/corex/rental-work-orders/index.blade.php`, `create.blade.php`, `show.blade.php`.
  - The property-tab "Work Order" button + recent-history list — built inline in
    `resources/views/corex/properties/show.blade.php`, same convention as the fault-report button
    beside it, not a separate partial file.
  - `tests/Feature/RentalWorkOrders/RentalWorkOrderListScreenTest.php` (full CRUD/list-screen floor,
    the overdue filter, cross-agency 404) and `RentalWorkOrderLifecycleTest.php` (the spine —
    raising a work order is proven a SEPARATE action from approving one, never automatic; situations 2
    and 4 of §7's four-situation test as real fixtures; the supplier/approval/completion/cancel gates;
    the overdue scope; permission separation). No separate `tests/Feature/RentalApprovals/*` file was
    created — the exactly-one-of and approval_route assertions this section's own earlier draft named
    live inside the fault-report and work-order lifecycle test files instead, alongside the flows they
    actually belong to.
  - **Known, honest gap carried forward, not fixed by this stage**: Stage 1 flagged that fault reports
    have no owner/tenant-facing MAIL at all (only the internal agent notification) — this stage does
    not add one either. A work order raised FROM a fault report never sends its own tenant mail
    (correctly — the tenant was already told at fault-report stage) but that fault-report-stage mail
    still does not exist. Still real, still undecided, still Johan's call.
- **BUILT, Stage 5, 2026-09-20 (§3a.5/§6a — the attached out-inspection history, Johan's own reason for
  the whole feature):**
  - `app/Models/RentalInspection.php`'s `tabPayloadFor()` gained a fourth key,
    `out_inspection_fault_history` — a second query alongside the existing `items`/`in_inspection`/
    `out_inspection` keys, not a join or a merge, exactly as §3a.5 specifies. Scoped to
    `rental_fault_reports.lease_id = <the current out-inspection's own lease_id>` (§3a.4) — empty,
    not an error, until an out-inspection actually exists to attach to. The single-resolver
    architecture this method already had (serving both the initial Blade render and the JSON
    `tabData()` endpoint) meant this needed no separate wiring for the two call sites — both get the
    new key automatically.
  - `resources/views/corex/properties/show.blade.php` — a read-only "Fault & Repair History — this
    tenancy" block rendered via Alpine `x-for`, sitting inside the same Out Inspection section but
    visually AFTER the existing item/observation recording (§3a.5's own "alongside, never inside"),
    showing title, outcome, `repaired_at` (the spine field, shown even when no work order was ever
    raised — the `owner_handles` route's own `repaired` outcome renders identically to one reached via
    a completed work order, exactly as §7 situation 2 argues), outcome note, and reported date. A real
    empty state ("No faults reported during this tenancy") when the history is genuinely clean —
    matching this whole spec's own evidentiary philosophy that an absence is itself informative, not
    something to leave blank and ambiguous.
  - `tests/Feature/RentalWorkOrders/OutInspectionFaultHistoryTest.php` — the two facts this stage exists
    to prove: a PREVIOUS tenancy's fault report does not appear (the lease-scoping test §11's own
    earlier draft explicitly named), and the existing `tabPayloadFor()` keys are completely undisturbed
    by the addition.
  - **This closes the spec.** All five stages are now built: the fault report record, its lifecycle,
    the spend threshold, the work orders themselves, and this attached view. The two questions this
    spec leaves genuinely open — the mailbox-polling automation question (§3a.1a, investigated not
    built) and the fault-report-level owner/tenant mail gap (named above and in Stage 1) — remain
    exactly that: open, not silently resolved by finishing the rest of the build.
  - **Real bug, found and fixed 2026-09-20** (a genuine end-to-end QA1 walk, not a test suite): the
    history block above was scoped to `$outInspection` from `tabPayloadFor()`'s existing
    `currentFor()`-based lookup — which deliberately excludes `completed`/`cancelled` inspections (it
    answers "is one currently open"). That meant the fault-and-repair history went blank the INSTANT
    the out-inspection actually completed — exactly the moment during a deposit dispute an agent needs
    it, per Johan's own framing for why this stage exists at all. Fixed with a second, independent
    lookup, `RentalInspection::mostRecentOutFor()` — same lease-scoping (§3a.4), but includes completed
    (excludes only `cancelled`, since an abandoned attempt never really happened) — feeding the history
    query instead of `currentFor()`'s result. `currentFor()` itself was deliberately left untouched; it
    still correctly guards `start()`'s double-start protection, which is a different question. See
    `RentalInspectionListScreenTest`/`OutInspectionFaultHistoryTest` for the tests proving history now
    survives completion.
- `config/corex-permissions.php` — new permission keys (§10).
- Sidebar entry for the new list screens (same-day, non-negotiable #2) — Rental Work Orders AND Rental
  Fault Reports both, under the existing Rentals section.
- Re-run `php artisan schema:dump`, commit refreshed `database/schema/mysql-schema.sql`
  (non-negotiable #12a).
- **BUILT, Stage 6, 2026-09-22 — Johan's own walk of the two live screens found the design-standard
  floor incomplete and asked for exactly two things named explicitly: "printing, audit tracking about
  whos done what etc." plus the list-screen/navigation gaps a read-only investigation had already
  found and reported.**
  - **Audit trail — "who did what."** `database/migrations/2026_09_22_100000_create_rental_fault_report_updates_table.php`,
    `app/Models/RentalFaultReportUpdate.php` — mirrors `rental_work_order_updates`/`RentalWorkOrderUpdate`
    exactly (same columns: `update_type`, `from_status`/`to_status`, `note`, `created_by_user_id`,
    `created_at`, append-only, no `updated_at`). Checked first, not assumed: CoreX has no single generic
    audit mechanism (no spatie/activitylog, no polymorphic AuditLog table) — only a repeated per-module
    pattern (`ContactAuditLog`, `PropertyAuditLog`, `RentalApplicationAuditLog`, and this same feature's
    own `rental_work_order_updates`). The closest, same-domain, already-proven sibling was reused rather
    than inventing a second convention. `RentalFaultReport::requestApproval()` now takes `User $by` and
    logs; `setOutcome()` now takes `User $by` and logs — this closes the exact gap named: the outcome
    field (this spec's own "spine of the record," §3a.2) previously recorded no actor anywhere.
    `recordApproval()` on both records deliberately does NOT write a duplicate log row — the decision is
    already fully evidenced on `rental_approvals` (actor, decision, evidence, timestamp); both models gained
    a `history()` method that merges the synthetic "Logged" event (from `created_by_user_id`/`created_at`,
    never duplicated into a row), every real update row, and every approval decision into ONE plain
    chronological list — "who, what, when," no helper text, no per-row badge, rendered on both detail
    screens under a "History" heading. `archive()`/`restoreRecord()` (both models) wrap the existing
    soft-delete/restore and log it, replacing the controllers' previous raw `->delete()`/`->restore()`
    calls. Verified against real QA1 data, not fabricated: fault report #2's real history shows "Logged
    (Retha Kelly)" then "Approved (→ Owner handles) — Owner replied via WhatsApp: ..." with the real
    evidence text and actor.
  - **Printing.** `app/Services/Rentals/RentalDocumentPdfService.php` — the existing barryvdh/laravel-
    dompdf pattern (`PropertyBrochureService.php:230`: `Pdf::loadView(...)->setPaper('a4','portrait')`,
    remote/php disabled, dpi 96, shared `storage_path('app/dompdf-fonts')` font-cache dir) applied to two
    new, simple, fixed-length documents — deliberately NOT reusing `PropertyBrochureService`'s own image-
    grid/QR/shrink-to-fit machinery, which solves a property-photo-layout problem neither document has.
    `resources/views/corex/rental-work-orders/pdf.blade.php` (supplier-facing), `.../rental-fault-reports/
    pdf.blade.php` (landlord-facing) — same Inter font-face embed as the brochure, agency/branch logo with
    a neutral agency-name wordmark fallback (never hardcoded to any one agency's own branding, non-
    negotiable #9). `RentalWorkOrderController::pdf()`/`RentalFaultReportController::pdf()`, routed at
    `.../{record}/pdf`, same query-layer scoping as each controller's own `show()` (route-model-binding +
    the global `AgencyScope`) — a user who cannot open the record cannot download it either, by
    construction, since both resolve the identical bound model the identical way. Both models gained a
    `branch()` relation (previously absent) for the logo fallback. Verified for real: both PDFs generated
    over real HTTP against real QA1 records, confirmed valid single-page A4 documents via `pdfinfo`, text
    content extracted via `pdftotext` and confirmed to carry the real property address, tenant name,
    job/fault title, and agency name — not merely a 200 status.
  - **List-screen gaps**, all in `RentalWorkOrderController::index()` and `rental-work-orders/index.blade.php`:
    `trade_type` filter (a `<select>` from `AgencyServiceType`, matching the create form's own picker) and
    `priority` (added to `allowedSorts`, a filter `<select>`, and a table column) — both existed server-
    side with no UI control before this stage. `property_id` (which already existed server-side but had
    no reachable UI at all) and the new `lease_id`/`contact_id` filters (§Navigation below) are surfaced
    as a named, clearable "Filtered to: ..." chip rather than a dropdown of every property/lease/contact
    in the agency — the same reasoning BUILD_STANDARD §1b already applies to date-range filters: a filter
    reached by drilling in from a specific record doesn't need a second, redundant picker on the list
    screen itself. Search/sort/pagination/empty-state on both list screens were already complete per the
    investigation; nothing else needed filling.
  - **Navigation.** `resources/views/corex/leases/show.blade.php` gained a "This tenancy" card with
    permission-gated links to that lease's own fault reports/work orders (`?lease_id=`).
    `resources/views/corex/contacts/_rental-applications-tab-body.blade.php` (the existing Rentals tab
    body) gained the same, via `?contact_id=` — resolved against EITHER `lease_tenants` (a tenant contact)
    OR `contact_property` (a landlord contact) with an `orWhereHas`, since the link doesn't know or need
    to know which the contact is. Both `RentalWorkOrderController::index()` and
    `RentalFaultReportController::index()` gained the `lease_id`/`contact_id` query-parameter handling
    behind these links.
  - **Not touched, per explicit instruction**: `RentalWorkOrderSetting::thresholdFor()` remains
    unconsumed (§8's own note) — wiring spend-threshold approval gating is a real design decision, since
    escalated to Johan as landlord-granted per-property approval authority rather than a flat agency
    number (investigated separately, not built in this stage). Owner/tenant-facing mail at the fault-
    report stage remains the same named, open gap (§4/Stage 1's own note) — still parked.
  - `tests/Feature/RentalFaultReports/RentalFaultReportLifecycleTest.php`,
    `tests/Feature/RentalWorkOrders/RentalWorkOrderLifecycleTest.php` — verified via stash-compare against
    unmodified `origin/QA1` (15 and 12 failures respectively, identical on both sides) that this stage
    introduces zero new test regressions; both files carry pre-existing, unrelated baseline failures
    (`assertSessionHasErrors()`/`assertForbidden()` failing broadly across both files) not touched or
    diagnosed by this stage — reported, not fixed, per non-negotiable #2.
- **BUILT, Stage 7, 2026-09-29 — Johan named exactly what Stage 6's own "not touched" note (above) flagged
  as missing: the quote (§3.4c), and reversed the property-vs-lease call for the spend threshold (§3.4b).**
  - `database/migrations/2026_09_29_100000_add_rental_no_approval_spend_threshold_to_properties.php` —
    the property-level override, sitting `->after('admin_fee')`.
  - `database/migrations/2026_09_29_100100_drop_rental_no_approval_spend_threshold_from_leases.php` —
    retires the Stage 3 lease-level column. No backfill (Standard −1q — no real data behind it).
  - `database/migrations/2026_09_29_100200_create_rental_work_order_quotes_table.php` — explicit short
    FK/index names (`rwoq_work_order_fk`, `rwoq_supplier_fk`, `rwoq_agency_wo_idx`), same discipline as
    Stage 4's own `rwo_item_fk` — MySQL's 64-character identifier limit has bitten three lanes.
  - `app/Models/RentalWorkOrderQuote.php` — `use BelongsToAgency, SoftDeletes`.
  - `app/Models/RentalWorkOrderSetting.php` — `thresholdFor()` re-signatured from `Lease $lease` to
    `Property $property`.
  - `app/Models/RentalWorkOrder.php` — `quotes()` relation; `recordQuote()`, `selectQuote()` (the gate),
    `archiveQuote()`, `restoreQuote()`, sibling to `assignSupplier()`; `history()`'s action-label match
    extended with the four new `quote_*` update types.
  - `app/Models/Property.php` / `app/Models/Lease.php` — `rental_no_approval_spend_threshold` moved from
    the latter's `$fillable`/`$casts` to the former's.
  - `app/Http/Controllers/CoreX/RentalWorkOrderQuoteController.php` — full CRUD + archive/restore +
    select + gated download (private disk, `Storage::disk('local')`, re-checks the quote belongs to the
    given work order on every request — the `PropertyFileController::download()` pattern, deliberately
    NOT the public-disk `PropertyImageStorer` pattern the sibling photo tables use).
  - `app/Http/Controllers/CoreX/PropertyController.php` (`updateRentalDetails()`) — validates and saves
    the new property field, same route/scoping as `deposit_amount`/`admin_fee`.
  - `app/Http/Controllers/CoreX/LeaseController.php` (`update()`) — the lease-level field removed from
    validation and the save.
  - `resources/views/corex/properties/show.blade.php` — the new field added to BOTH rental-tab panels
    (new/pending-type form and the settled-property dedicated form), matching the existing
    `prop-input prop-field-money`/"(R)"-in-the-label convention exactly — no currency setting invented.
  - `resources/views/corex/leases/show.blade.php` — the editable field removed; the display line now
    reads through to `RentalWorkOrderSetting::thresholdFor($lease->property)` with a link to edit it on
    the property.
  - `resources/views/corex/rental-work-orders/show.blade.php` — new "Quotes" section: list (supplier,
    amount, date, selected badge, document link/keyed detail), capture form, select/archive actions —
    all `@permission('rental_work_orders.manage_quotes')`-gated. No sidebar entry, per instruction.
  - `config/corex-permissions.php` — `rental_work_orders.manage_quotes`.
  - `routes/web.php` — six new routes under the existing `rental-work-orders` prefix group.
  - `tests/Feature/RentalWorkOrders/RentalWorkOrderSettingTest.php` — rewritten for property-based
    resolution (was lease-based); `tests/Feature/RentalWorkOrders/RentalWorkOrderQuoteTest.php` — new.

---

## 12. Out of scope (this spec)

- Invoicing, trust-ledger reconciliation, or any real financial/accounting layer (§5.1) — settled OUT,
  not a future-question flag any more. REOS handles the money.
- A deposit ledger or deposit-deduction processing (§5.1a) — settled OUT except advertising deposits;
  named as a real, current gap, not solved here.
- **How a tenant actually reports a fault (§3.2a) — SETTLED 2026-09-25.** The agent captures it; no
  tokened link, no self-filing inbound email. Both of those are out of scope by decision now, not by
  default — see §3.2a for why they're named but not built.
- **The exact mechanism for capturing owner approval (§3.4a) — SETTLED 2026-09-25**, and larger than
  originally specified (two distinct approval routes, not one). What remains genuinely out of scope:
  TRUE hands-off automation of filing an owner's approval email against the record — §3a.1a's
  investigation found the cheap half (an agent linking an already-archived email) is a real, small
  reuse of existing machinery, worth doing at some point, but not built by this amendment; full
  content-based auto-filing is a real, separate, larger build, not proposed here at all.
- ~~The spend-threshold property-vs-lease override — argued and recommended (§3.4b: property), NOT
  unilaterally decided.~~ ~~**SETTLED 2026-09-26 — Johan ruled lease, both built (§3.4b/§11, Stage 3).**~~
  **SUPERSEDED 2026-09-29 — Johan ruled property, live, looking at the lease screen: "per property,
  populated to the leases screen." Current, final shape in §3.4b/§11 Stage 7 — the lease column is
  retired, not kept as a second mirror.**
- A supplier-facing reply/secure-link mechanism to self-report completion (§4) — named as a future
  upgrade path (DR2's `DealSecureLinkMail` is the existing pattern to copy when wanted), not built here.
- Automated WhatsApp notification of anyone (§4) — does not exist in CoreX and is not built here; a
  manual `wa.me` link is the ceiling of what's possible without building a WhatsApp send capability
  from scratch, which is not this spec's job either.
- The tenant-link-outstanding reminder (§9) — raised for Johan's ruling, not decided or built.
- Any change to `leases.md` or `rental-inspections.md` themselves — both are read and depended on,
  neither is edited by this spec.
- Building the mobile app itself — Andre's job, per §13. This spec's job is only to make sure nothing
  built here makes that job harder later — including, now, the `reported_channel`/`captured_by_user_id`
  seam §3.2a adds specifically so that job is easier, not harder, when it starts.

---

## 13. Mobile foundation — the same constraint rental-inspections.md §14 now carries

**Per the same ruling that added this constraint to `rental-inspections.md`**: an agent raising a work
order is very often standing in the property at the moment they notice or are told about the problem —
the mobile case is not an afterthought here either. The three-part discipline that spec's §14 sets out
applies identically:

**Logic in services, not controllers or Blades.** Already this spec's design before the ruling existed
— `app/Services/Rentals/RentalWorkOrderService.php` (§11) is where status transitions, the completion
gate, and the approval gate live; `RentalWorkOrderController` is a thin caller. No audit-fix is needed
here the way `rental-inspections.md` §14.1 needed one for two things built before the constraint
existed — this spec named the service layer from its very first draft.

**The API seam, sketched now rather than retrofitted later** (not built this pass — spec only, same as
everything else here): mirroring `rental-inspections.md` §14.2's shape and reusing its established
conventions (Sanctum bearer auth, the same `{"message": ...}` error convention, `/api/v1/mobile/...`
namespace):

| Method | Route | Calls |
|---|---|---|
| `POST` | `/api/v1/mobile/properties/{property}/work-orders` | `RentalWorkOrderService::report()` |
| `POST` | `/api/v1/mobile/work-orders/{workOrder}/photos` | Reuses `PropertyImageStorer` directly — same reasoning as `rental-inspections.md` §14.5, one photo pipeline, not two. |
| `POST` | `/api/v1/mobile/work-orders/{workOrder}/assign-supplier` | `RentalWorkOrderService::assignSupplier()` — refuses per the approval gate exactly like the web path does, same method, not a second implementation of the gate. |
| `POST` | `/api/v1/mobile/work-orders/{workOrder}/complete` | `RentalWorkOrderService::complete()` — the photo/payer completion gate (§3.4) enforced once, in the service, not duplicated in a controller. |

**Fault reports (2026-09-24 amendment) carry the identical discipline.** §3a's own logic already lives
on `RentalFaultReportService`/`RentalFaultReport` per §11, not a controller, so these extend the same
table rather than starting a second one:

| Method | Route | Calls |
|---|---|---|
| `POST` | `/api/v1/mobile/properties/{property}/fault-reports` | `RentalFaultReportService::report()` — today, an agent calls this standing in the property, with `captured_by_user_id` set to themselves. **Settled 2026-09-25 (§3.2a): this is also the exact route Andre's future tenant-app work lands on** — same method, same endpoint, called with `captured_by_user_id` simply omitted and `reported_channel='app'`. No second endpoint is ever built for tenant self-service; this is the seam. |
| `POST` | `/api/v1/mobile/fault-reports/{faultReport}/photos` | Reuses `PropertyImageStorer` directly — same reasoning as the work-order photo row above and `rental-inspections.md` §14.5. One photo pipeline, not three. |
| `POST` | `/api/v1/mobile/fault-reports/{faultReport}/approval` | **New, 2026-09-25.** `RentalFaultReportService::recordApproval()` — writes a `rental_approvals` row (§3.4a) with `decision`/`approval_route`/evidence. An agent captures this standing with the owner or reading a WhatsApp/email reply, same as the web path; no separate implementation of the two-route logic in a controller. |
| `POST` | `/api/v1/mobile/fault-reports/{faultReport}/outcome` | `RentalFaultReportService::setOutcome()` — the outcome-requires-a-note-unless-`repaired` rule and the `repaired_at`-required-when-repaired rule (§3a.2) enforced once, in the service. |

**Multi-agency, always (non-negotiable #9, 2026-09-19).** Nothing in this table, or anywhere else in
this spec, may assume HFC. `reported_channel`'s options, the approval-route wording, the outcome
values, and every notification template built from §4 must read correctly for the Cape Town rentals
agency starting October 2026 as much as for HFC — no agency ID, no agency-specific copy, anywhere in
the service layer these mobile endpoints call into.

**Offline**: `rental_work_order_photos.client_idempotency_key` (§3.1) and `rental_fault_report_photos`'
own copy of the same column (§3a) already exist for the same reason `rental_inspection_photos`' does
— a retried upload on a bad connection must never create a duplicate. The same four arrival cases
`rental-inspections.md` §14.4 works through (late, out-of-order, a genuine app-side duplicate, and
data that's gone stale by the time it arrives — here, most concretely, a work order or fault report
reported against a lease that's since ended) apply identically and are not re-argued in full here; the
server-side answer is the same: never discard real evidence because something moved on, tell the app
plainly what changed.

---

## 14. Job Cards (AT-442, built 2026-10-04) — the agency's own maintenance team, vs. an outside supplier

**Status: BUILT.** Builds directly on §3.4/§3.4c (quotes) and §11 (`RentalWorkOrderService`) above —
not a fork of the work-order lifecycle, a new branch inside it. This section replaces cc5's earlier
spec-only draft (2026-10-04, approved design before the build prompt) with what was actually built —
Johan's direct build brief to this lane won on every point where it differed from that draft; each
divergence is called out explicitly below rather than silently resolved, per instruction.

**Where the build brief won over the earlier draft:**
- **One catalogue table, not two.** The draft proposed separate `rental_job_card_labour_items` /
  `rental_job_card_parts` tables. The brief's own wording — "two item types — Labour and Part" — reads
  as one catalogue with a `type` column, not two parallel tables; built as `rental_catalogue_items`
  (§14.3 below), same shape as `AgencyServiceType`'s own precedent.
- **One pricing setting, default ON, not two, default OFF.** The brief: *"Whether prices are used at
  all is an agency setting ('Capture prices on job cards', default on)."* Built as the single boolean
  `rental_work_order_settings.capture_prices_on_job_cards`, default `true` — not the draft's two
  default-OFF toggles (`job_card_labour_pricing_enabled`/`job_card_parts_pricing_enabled`).
- **No tenant-signoff-required toggle.** The draft proposed `job_card_requires_tenant_signoff`. The
  brief names tenant confirmation as "recorded by the agent for now" and does not make it a
  completion gate; built exactly as the existing `rental_work_orders.tenant_confirmed_completion_at`
  field already treats tenant confirmation elsewhere in this spec — additional evidence, never a
  block on reaching `completed`. Only worker + agent sign-off gate completion.
- **Seven statuses, not five.** The brief: *"Status: Draft → Quoted → Approved → Scheduled → In
  progress → Completed, plus Cancelled."* Built exactly that (§14.2 below) — more granular than the
  draft's `scheduled/in-progress/awaiting-sign-off/completed/cancelled`.
- **Catalogue has its own permission pair, separate from job cards.** The draft's single
  `rental_job_cards.manage_settings` gated both the catalogue and (by name) general settings; built as
  `rental_catalogue.view`/`.manage` (its own CRUD surface, reusable independently of any one job card)
  plus `rental_job_cards.send_quote`/`.sign_off` as their own heavier-decision keys (§14.7) — same
  `.create`-vs-heavier-action split this whole spec already uses for quotes/approvals elsewhere.
- **API lives under the established `/api/v1/mobile/*` namespace**, not a bare `/api/v1/rental-job-
  cards` — the draft's routes weren't checked against the actual codebase convention
  (`routes/api.php`'s existing `mobile/properties`/`mobile/p24` groups); built to match that real
  convention (§14.8).
- **No special owner-pricing hiding on the worker-facing print** — the draft flagged this as an open
  design point, not decided. Not built: the printable job card (§14.2) shows the same lines/prices as
  everywhere else, gated only by `capture_prices_on_job_cards`, consistent with the rest of this
  spec's "one source of truth, read everywhere" discipline. Flagged here as a real, undecided design
  point carried forward, not silently resolved.
- **The draft's §14.11 open question** (the two pre-existing orphaned settings,
  `completion_requires_photo`/`overdue_reminder_days`, missing from the onboarding wizard) is
  unrelated to this build and not addressed by it — still open, Johan's call, per the original note.

### 14.1 What changes and why
Today every work order implicitly assumes an outside supplier (quote → select → notify, §3.4). That
remains true for outside work — unchanged. **"Who does the work" is now the FIRST choice on every
work order's create form and on the fault-report "Raise work order" action**
(`rental_work_orders.assignment_type`, enum `outside_supplier`/`internal`, default
`outside_supplier` — every row that predates this migration reads as `outside_supplier`, unchanged
behaviour). Choosing `internal` creates a **Job Card** (`rental_job_cards`, 1:1 with the work order
via `rental_work_order_id`, unique) in the SAME action — a job card always builds its own work order;
there is no path that creates one without the other. The job card's lines produce the amount the
existing spend-threshold gate checks (§3.4b/§3.4c), exactly as a supplier's quote amount does — via
the SAME `RentalWorkOrder::recordQuote()`/`selectQuote()` methods, not a second gate.

### 14.2 Job card data model — built

```
rental_job_cards
  id, agency_id, branch_id
  rental_work_order_id          -- UNIQUE FK — the job card BUILDS this work order (§14.1)
  property_id, lease_id         -- denormalized, same reasoning as every sibling table in this spec
  title
  status                        -- draft | quoted | approved | scheduled | in_progress | completed |
                                 --   cancelled — Johan's own exact wording, built as given (NOT the
                                 --   5-state draft this section previously carried)
  assigned_user_id               -- nullable FK users — the crew member
  scheduled_at, due_at           -- nullable datetimes
  access_notes                   -- nullable text
  total_amount                   -- nullable decimal(10,2), cached sum of live lines, recalculated on
                                 --   every line change server-side, never trusted from the client
  worker_signed_off_at/by_user_id, agent_signed_off_at/by_user_id   -- BOTH required to complete()
  tenant_confirmed_at/by_user_id, tenant_confirmation_note          -- recorded BY THE AGENT for now
                                 --   (tenant login is AT-445) — additional evidence, NEVER a
                                 --   completion gate (no "requires tenant signoff" setting was built —
                                 --   see §14's own note on where the brief won over the earlier draft)
  completed_at, cancelled_at/by_user_id/cancel_reason
  created_by_user_id, timestamps, soft deletes          -- deletable only while no task/line/update
                                 --   exists yet, same application-layer gate as rental_work_orders

rental_job_card_tasks            -- the checklist: add/tick/reorder/archive/restore
  id, agency_id, rental_job_card_id, description, is_done, sort_order,
  done_by_user_id, done_at, created_by_user_id, timestamps, soft deletes

rental_job_card_lines            -- parts/labour, from the catalogue or free text
  id, agency_id, rental_job_card_id, rental_catalogue_item_id (nullable)
  type                           -- labour | part — copied from the catalogue item at add-time, or
                                 --   picked directly for a free-text line; never re-derived later
  description, unit (nullable), quantity (decimal 10,2, default 1)
  unit_price, line_total         -- nullable decimal(10,2) — BOTH always null when the agency's
                                 --   capture_prices_on_job_cards setting is off, regardless of what
                                 --   was posted; line_total is quantity*unit_price, server-computed
  sort_order, created_by_user_id, timestamps, soft deletes

rental_job_card_updates          -- append-only history log, same evidence-integrity shape as
                                 --   rental_work_order_updates — no updated_at, no deleted_at
  id, agency_id, rental_job_card_id, update_type, from_status, to_status, note,
  created_by_user_id, created_at
```

Sign-off: worker (done) and agent (checked) are BOTH required before `complete()` — enforced in
`RentalJobCard::complete()` (throws `LogicException` otherwise). Tenant confirmation
("confirmed fixed") is recorded separately and never gates completion, per §14.1's own note above.

### 14.3 Parts & labour catalogue — ONE table, not two (built differently from the earlier draft)

```
rental_catalogue_items
  id, agency_id, rental_catalogue_item_type_id, name, rental_catalogue_unit_id,
  default_price (nullable decimal 10,2, ALWAYS excl-VAT — §14.3a), default_rental_vat_type_id,
  default_custom_vat_rate (nullable decimal 5,2 — only meaningful when the default VAT type is
  rate_mode=custom_per_line),
  is_active (default true), sort_order, created_by_user_id, timestamps, soft deletes
```

Full CRUD (create/edit/archive/restore), search (name), sort (sort_order default; name,
default_price), filter (type id, active/archived), pagination, real empty state — same list-screen
floor as `RentalFaultType`'s own screen, which this reuses as its direct pattern. Reached from the
Settings hub (`corex.rental-catalogue-items.*`), **not** the Rentals nav panel.

A job card line may pick a catalogue item (its `name`/unit/price pre-fill the line, all still
editable) or be free text when nothing in the catalogue fits — the catalogue guides, it does not
constrain. Archiving a catalogue item never changes a historical line's own `type`/`description`
(copied at add-time, §14.2).

### 14.3a Pastel-style enhancement, 2026-10-05 — configurable type/unit lists + VAT-aware pricing

Johan's own words: build this "Pastel-style" — type and unit become agency-configurable lists
instead of a fixed enum/free text, and the default-price field on the catalogue item form becomes
VAT-type-aware, matching how `rental_job_card_lines`' own VAT-per-line already works (§14.2's
`vat_*_snapshot` columns, `RentalJobCardVatService`, built earlier the same day).

**Type** — `rental_catalogue_item_types` (id, agency_id, name, `kind` (labour|part), is_active,
sort_order, created_by_user_id, timestamps, soft deletes). Full CRUD (add/rename/reorder/archive/
restore), seeded per agency with the original two values as defaults
(`RentalCatalogueItemType::seedDefaultsFor()`, same `AgencyCreated`-reaction pattern as
`RentalVatType`). `kind` is the FIXED classification every type maps onto — set once, at creation,
never renamed — so an agency naming its own types ("Subcontractor", "Materials", ...) never breaks
the existing labour-hours/parts-used reporting (`RentalReportService::jobCards()`, which still reads
the job card LINE's own `type` column, itself still just `labour`/`part` — unchanged, because a line
copies the picked type's `kind`, not its agency-chosen `name`, at add-time).

**Unit** — `rental_catalogue_units` (id, agency_id, name, is_active, sort_order,
created_by_user_id, timestamps, soft deletes). Full CRUD, seeded per agency with eleven defaults:
Each, Dozen, Box, Pack, Metre, m², Litre, kg, Hour, Day, Call-out. A job card line's own `unit`
column is unchanged (a string snapshot copied at add-time, same as `type`) — it now reads from the
picked unit's `name` rather than free text, so a line reads "1 dozen screws" vs "1 screw" per
Johan's own example.

Both lists are managed on the **same Company Settings surface as VAT Types**
(`admin.catalogue-item-types.*`/`admin.catalogue-units.*`, gated by the same
`manage_performance_settings` permission), reorder via the same up/down-arrow + hidden-form pattern
`RentalApplicationHighlighter`'s settings screen already uses, and are linked directly from the
catalogue item list/create/edit screens ("Manage types & units").

**Existing data migrated, not re-entered** — `2026_10_05_240300_backfill_rental_catalogue_item_types_
and_units` seeds every existing agency's two types/eleven units, then maps every existing
`rental_catalogue_items` row's old `type`/`unit` strings onto the new FKs (unit matched
case-insensitively against the seeded names; no match creates a new agency-owned unit row from that
exact free text, so nothing is lost or silently renamed). The old `type`/`unit` string columns are
then dropped (`2026_10_05_240400`) — no dead columns left behind.

**Default price — always stored excl-VAT, "store unambiguously"** (Johan's own instruction). The
catalogue item form's price field is driven by the item's own default VAT type and the agency's VAT
capture mode, live via Alpine:
- **Agency not VAT registered** — no VAT picker, a single "Price" field, stored as-is.
- **VAT registered, selected type's resolved rate is 0** (the seeded "No VAT" type, or any other
  zero-rate type) — a single "Price (no VAT)" field, excl === incl, stored as-is.
- **VAT registered, resolved rate > 0** (Standard or Custom) — BOTH "Excl VAT" and "Incl VAT"
  amounts are shown; the one matching the agency's `vat_capture_mode` is the actual submitted
  `default_price` field, the other is a read-only Alpine-computed companion for the agent's
  reassurance, never posted. The server (`RentalCatalogueItemController::validated()`) converts an
  incl-mode submission down to excl before it ever reaches the DB
  (`RentalJobCardVatService::splitAmount()`, the same split used for job card lines).
- The catalogue LIST shows three columns when the agency is VAT registered — **Excl VAT / VAT type
  / Incl VAT** — computed live by `RentalJobCardVatService::catalogueItemPrices()`.

**A job card line picking a catalogue item carries its VAT type, unit, and price across** — unit and
type kind unchanged from before (§14.2); price now goes through
`RentalJobCardVatService::catalogueDefaultPriceForLine()`, which converts the item's always-excl
`default_price` to whatever the agency currently captures ON LINES (unchanged behaviour: a line's
own `unit_price`/VAT snapshot mechanics, §14.2, are untouched by this enhancement) — excl passes
through unchanged, incl-capture multiplies up by the resolved rate. Not VAT registered, or the
agency captures excl: byte-identical to the item's stored `default_price`, same as before this
enhancement existed.

No new agency SETTING was added by this enhancement (the type/unit lists are agency-owned CRUD
lists, same category as VAT Types/`RentalApplicationHighlighter`/`RentalFaultType` — none of which
are onboarding-wizard material, "deliberately not in the wizard" by established precedent,
`agency-onboarding-setup.md` §5.1's own "your property lists" entry) — nothing to add to
`config/agency-onboarding-copy.php`.

### 14.4 Prices on/off — one agency setting, default ON

`rental_work_order_settings.capture_prices_on_job_cards` (boolean, default `true`) —
`RentalWorkOrderSetting::capturePricesOnJobCardsFor($agencyId)`. With it off, `unit_price`/
`line_total` always save `null` regardless of what the client posts (`RentalJobCardService::
addLine()`/`updateLine()`), and the UI (job card show screen, print PDF, quote PDF) renders no price
or total column at all — never a `0.00`, never a blank column, the column itself is absent.
`total_amount` may still cache a `0.00` (MySQL's `SUM()` of an all-NULL set, via Laravel's own
`numericAggregate() ?: 0` coalesce) — harmless, since nothing ever renders it when this setting is
off.

### 14.5 "Send to owner as quote" — reuses the EXISTING quote/approval mechanism, verbatim

`RentalJobCardService::sendToOwnerAsQuote()`: generates a PDF (`RentalDocumentPdfService::
jobCardQuotePdf()`), then calls the SAME `RentalWorkOrder::recordQuote()` (with
`agency_service_provider_id` null, `rental_job_card_id` set) and `RentalWorkOrder::selectQuote()`
(§3.4c) the outside-supplier path already uses — the property's landlord no-approval spend threshold
applies identically: at/under it, `owner_approval_status` resolves to `not_required` and the job
card's own status jumps straight to `approved`; over it, `pending`, and the job card stays `quoted`
until the EXISTING `recordApproval()` action (reachable from the work order's own screen, same
permission) flips it — `RentalJobCard::syncStatusFromWorkOrder()` picks that up and advances the job
card to `approved`. No second approval mechanism exists anywhere in this build.

`rental_work_order_quotes.agency_service_provider_id` was relaxed to **nullable** (migration
`2026_10_04_210600`) for exactly this no-supplier case — every existing/outside-supplier quote still
always sets it; `rental_job_card_id` was added (nullable) so a quote's origin is traceable.
`RentalWorkOrder::describeQuote()` now reads "Our maintenance team" instead of the generic "Unknown
supplier" fallback when a quote has no supplier but does have a job card.

### 14.6 Completion syncs the linked work order — lives in the SERVICE, not a controller

`RentalJobCardService::complete()` — found necessary via a live Tinker end-to-end verification during
this build: calling the MODEL's own `RentalJobCard::complete()` directly (the sign-off gate) never
touched the linked work order at all, because that sync had originally been written only inside the
web controller. Moved into the service so every caller (web, a future mobile "complete" action, a
test or script calling the service) gets the same behaviour: after the job card itself completes, the
service attempts `$workOrder->complete($by, ['paid_by' => 'owner', 'cost_amount' =>
$jobCard->total_amount, ...])` — if the agency's `completion_requires_photo` setting blocks it (no
completed photo yet), the job card still completes (worker+agent signed off is sufficient evidence for
the job card itself) and the work order stays open until a photo is added via its own existing upload
control. Never a parallel completion gate.

### 14.7 Fault → work order → job card, one click, both paths

The EXISTING `raise-work-order` action (`RentalFaultReportController::raiseWorkOrder()`, already
built) now accepts `assignment_type`. `internal` calls `RentalJobCardService::createFromFaultReport()`,
which itself calls the SAME `RentalWorkOrderService::fromFaultReport()` the outside-supplier path
always has (so approval-inheritance from an already-approved fault report works identically for both
paths), then creates the job card against the resulting work order — never a second implementation of
the agency_appoints route.

### 14.8 Job Cards list — CRUD/list-screen floor, built

- **Routes:** `corex.rental-job-cards.{index,create,store,show,update,destroy,restore,print,
  print-list}` plus action routes (`assign-crew`, `schedule`, `start`, `tasks.*`, `lines.*`,
  `send-quote`, `worker-sign-off`, `agent-sign-off`, `tenant-confirm`, `complete`, `cancel`,
  `photos.store`) — its own nav entry ("Job Cards") in the Rentals panel, same standing as Fault
  Reports/Work Orders, not a tab buried inside either.
- **Search fields:** property address, tenant name, crew member name, title.
- **Sort columns:** `due_at` (default, ascending — what's due soonest is looked at first),
  `scheduled_at`, status, property address.
- **Filters:** crew member, status, date range; an "Overdue" status tile (`RentalJobCard::
  scopeOverdue()` — open status + `due_at` in the past).
- **Status tiles** (`pstat-v2` style): Total, Draft, Quoted, Scheduled, In progress, Overdue,
  Completed.
- **Own | Branch | All** scope switch, default widest permitted (`RentalJobCard::scopeVisibleTo()`,
  same `PermissionService::getDataScope()`/`clampScope()` pattern every sibling list in this spec
  uses) — a wider option never renders than the user's own ceiling permits.
- **Full CRUD**: create, read, update, archive (soft delete), restore — deletable only while no
  task/line/update has been logged yet.
- **Print list**: a simple print-friendly page (`print-list.blade.php`, `window.print()` on load),
  respecting the same scope/filters as the list screen.
- **Direct-URL access by ID is blocked, not just absent from the menu** — every show/edit/action route
  resolves through the global `AgencyScope` + `scopeVisibleTo()`; a cross-agency job card id 404s
  (proven by test, §14.11).

**2026-10-05 QA1-walk follow-up** (see `rentals-foundation-at439.md` §11 for the full writeup): the
list had no "New Job Card" button at all — added, same pattern as the other two lists. The list also
gained its own property-filter picker (`corex.rental-job-cards.search-properties`, qualifying
properties only — only a property with a job card visible to this user, never every rental
property) and, with it, a real `property_id` query filter in `index()` (it had none before).

Work order detail screen (`rental-work-orders/show.blade.php`) widened from `max-w-3xl` to
`max-w-7xl` (full-width, per instruction) and now shows a "Job card" block inline when
`assignment_type='internal'` (status/crew/scheduled/total + a link to the job card's own full
screen); the Quotes and Supplier blocks (outside-supplier-only actions: assign supplier, start,
complete via `paid_by`) are hidden for the internal path — those actions happen on the job card
instead. Owner approval stays visible for both paths (it rides the same gate either way).

### 14.9 Permissions — built (differs from the earlier draft's single-key shape)

- `rental_catalogue.view` / `.manage` — the catalogue's own CRUD surface, independent of any one job
  card (same single-key-for-the-whole-surface shape as `rental_fault_types.create`/
  `deals_v2.manage_suppliers`).
- `rental_job_cards.view`, `.create` (covers create/edit/tasks/lines/assign-crew/schedule/start/
  archive/restore/photos), `.send_quote` (moves money past the approval gate — same weight as
  `rental_work_orders.manage_quotes`), `.sign_off` (worker/agent/tenant sign-off and `complete()` —
  same weight as `rental_work_orders.complete`/`.record_approval`), `.cancel`.

### 14.10 API — built under the real `/api/v1/mobile/*` convention, not the draft's bare path

`App\Http\Controllers\Api\MobileRentalJobCardController`, Sanctum bearer auth, inside the existing
`auth:sanctum`+`app_access` `v1` group (matching `mobile/properties`/`mobile/p24`'s own convention —
checked directly against `routes/api.php`, not assumed):

| Method | Route | Calls |
|---|---|---|
| `GET` | `/api/v1/mobile/rental-job-cards/{rentalJobCard}` | Read — same `scopeVisibleTo()` guard as the web screen; 404s outside scope. |
| `PUT` | `/api/v1/mobile/rental-job-cards/{rentalJobCard}` | Update `access_notes`. |
| `POST` | `/api/v1/mobile/rental-job-cards/{rentalJobCard}/tasks/{task}/tick` | `RentalJobCardService::toggleTask()` — same method the web control calls. |
| `POST` | `/api/v1/mobile/rental-job-cards/{rentalJobCard}/photos` | `RentalJobCardService::storePhoto()` — reuses the linked work order's own photo pipeline, one pipeline not two. |

Scoping is enforced in the controller (`RentalJobCard::visibleTo($user)->whereKey(...)->exists()`,
404 otherwise), not just by the web nav being absent — proven by test (§14.11).

### 14.11 Settings — Setup Wizard entry, built

- `capture_prices_on_job_cards` (§14.4) — Setup Wizard toggle (`config/agency-onboarding-copy.php`,
  default on), own narrow saver (`RentalWorkOrderSettingsController::
  updateCapturePricesOnJobCards()`, `has()`-guarded checkbox, same discipline as
  `RentalInspectionSettingsController`'s own toggles) — also exposed on the dedicated Rental Work
  Order Settings page, not only the wizard.
- **Pre-existing orphaned settings unrelated to this build** (`RentalWorkOrderSetting.
  completion_requires_photo`, `.overdue_reminder_days` — missing from the onboarding wizard with no
  recorded exclusion decision) — still open, still Johan's call, not touched by AT-442.

### 14.12 Tests — built

`tests/Feature/RentalJobCards/RentalCatalogueItemTest.php` (6: happy path, optional-price omitted,
required-field rejection, edit, archive/restore, agency isolation + direct-URL-by-ID 404) and
`RentalJobCardLifecycleTest.php` (20: job-card-builds-work-order for both the direct and
fault-report-raised paths, existing rows default to `outside_supplier`, tasks, line totals with
prices on/off, free-text lines, the quote/threshold gate both under and over the limit, sign-off
gating completion, the completion→work-order sync, archive/restore, cross-agency 404,
mobile-API-scope 404, and a full internal-job end-to-end scenario) — 26 tests, 80 assertions, plus a
live Tinker run against throwaway records proving the same end-to-end path on real (non-test)
infrastructure.

### 14.13 Record-scope guard — applied to every new non-index route (AT-442 follow-up, 2026-10-04)

Per the conductor's instruction after AT-439 landed its `AuthorizesRentalRecordScope` trait (§ below):
`guardRentalRecordScope()` was added to the top of every job-card action that receives an existing
bound record — web (`RentalJobCardController`) and the mobile API
(`MobileRentalJobCardController`) alike, same `'rental_job_cards'` permission key, branch resolved via
`$rentalJobCard->property?->branch_id` (matching `RentalFaultReport`/`RentalWorkOrder`'s own
`scopeVisibleTo()` — the property's branch, never the job card's own unused `branch_id` column).
`index()`/`create()`/`store()` are unguarded (no existing record; `index()` already filters via
`scopeVisibleTo()`). The parts & labour catalogue (`rental_catalogue_items`) is agency-level, not
own/branch-scoped — gated by permission only (`rental_catalogue.view`/`.manage`), no record guard,
same as `AgencyServiceType`'s own screen. Proven by test: a same-agency user outside the acting
user's own/branch scope gets a real 403 (not the 404 a cross-agency request gets via the global
`AgencyScope` — two different mechanisms, two different test cases).

### 14.14 Prices on the PRINTED job card — separate agency setting, default OFF (2026-10-04)

Settled by the conductor: the worker's printed copy and the owner's quote PDF are not the same
audience and do not show the same thing by default. **New**,
`rental_work_order_settings.show_prices_on_printed_job_card` (boolean, default **false**) —
`RentalWorkOrderSetting::showPricesOnPrintedJobCardFor($agencyId)`. Gates ONLY
`RentalDocumentPdfService::jobCardPrintPdf()` (the worker-facing print, §14.2's own printable job
card) — when off, that document shows tasks, parts, and quantities with no price/total column at
all, regardless of `capture_prices_on_job_cards`. The owner-facing quote PDF
(`jobCardQuotePdf()`, §14.5, what `sendToOwnerAsQuote()` mails/attaches) is **never** gated by this
setting — it always shows prices whenever `capture_prices_on_job_cards` is on, because the owner is
being asked to approve a cost; hiding it there would defeat the document's own purpose. Setup Wizard
entry (default off) + dedicated settings-page toggle, same `has()`-guarded-checkbox discipline as
`capture_prices_on_job_cards` itself — own narrow saver, never folded into another toggle's.

### 14.15 The one pre-existing, unrelated test failure (reported, not fixed, per non-negotiable #2)

`tests/Feature/RentalWorkOrders/RentalWorkOrderLifecycleTest.php::
test_completion_requires_a_completed_photo_when_setting_is_on` — fails on a file this build never
touched, exercising `RentalWorkOrder::complete()`/`completionRequiresPhotoFor()`, neither of which
this build modified. Failure: `Session is missing expected key [errors]. Failed asserting that false
is true.` at line 242. Confirmed unrelated by direct inspection of the diff, not a stash-compare (see
the build's own report for why) — flagged for whoever owns that file, not resolved here.

### 14.16 QA1-walk follow-up fixes (AT-442, 2026-10-05)

Johan's first browser walk of the §14 build on QA1 surfaced a real cross-property data-integrity bug
plus five smaller gaps. Fixed here, tests in `RentalJobCardAt442FollowUpTest.php`:

- **Lease/property cross-contamination (the class of bug).** `RentalWorkOrderController::create()`/
  `RentalJobCardController::create()` used to resolve `property_id` and `lease_id` from the query
  string INDEPENDENTLY — a `lease_id` left over from one link (or typed directly, e.g.
  `?lease_id=22`) combined with a DIFFERENT property picked from the create form's own dropdown
  produced a job card/work order whose `lease_id` belonged to another property entirely. This is
  exactly how QA1 job card #1 (work order #13) ended up with `property_id=5792` but `lease_id=22`
  (lease 22 belongs to property 21068) instead of property 5792's real active lease (#10, Andre
  Roets). Fixed per Johan's ruling ("faults, work orders and job cards belong to the LEASE first,
  then the property; with no tenant they attach to the property alone"):
  - `create()` on both controllers now resolves `lease_id` FIRST (scoped via
    `Lease::visibleTo($user, null)->find()`, graceful fallback to no pre-selection outside scope —
    same shape as `RentalInspectionController::create()`) and DERIVES the property from the lease;
    `property_id` is only consulted when no lease_id resolved.
  - `store()` on both controllers now re-derives the property from a posted `lease_id` server-side
    whenever it disagrees with the posted `property_id` — belt-and-braces against a direct/stale POST,
    not just a UI-level fix.
  - QA1 job card #1 / work order #13's `lease_id` is a data-only correction (real lease 10, not code)
    — left for Johan to action once the QA1 freeze lifts, per Standard −1q (QA fixture row, not a
    migration-worthy backfill).
- **Property picker (#2).** The work-order create screen's property `<select>` capped at 500 rows,
  unfiltered-by-search — on QA1's 570 rental properties it could never offer every property. Replaced
  with a searchable, debounced picker backed by a new scoped `RentalWorkOrderController::
  searchProperties()` endpoint (`GET .../rental-work-orders/search-properties`), same pattern as
  `RentalApplicationController::searchProperties()` — `Property::visibleTo()` + `searchAddress()`,
  10-result limit, no cap on what CAN be found.
- **Pre-select wiring (#3).** The Lease Hub had no "Work order" action at all (only "Report a fault")
  — added, paired `property_id`/`lease_id` same as the existing fault-report button. The work orders
  list's own "New Work Order" button was completely bare (dropped any active `lease_id`/`property_id`
  filter, including one arrived at via the rental context bar's "Work orders" chip) — now forwards
  `request()->only(['property_id', 'lease_id'])`.
- **Context bar (#4).** `rental-job-cards/show.blade.php` had no `<x-rental-context-bar>` at all —
  added, same props as `rental-work-orders/show.blade.php` (`current="work_orders"`).
- **Free-text line type (#5).** A free-text line always saved as Labour — the add-line form had no
  Type control. Added a Labour/Part `<select>`, disabled (so it never posts) once a catalogue item is
  picked. `RentalJobCardService::addLine()`'s precedence flipped so the CATALOGUE ITEM's own type
  always wins over a posted `type` — only a true free-text line (no catalogue item) uses the posted
  value.
- **No-approval limit next to the total (#6).** `RentalJobCardController::show()` now passes
  `noApprovalThreshold` (`RentalWorkOrderSetting::thresholdFor()`, same figure the work order's own
  show screen already surfaces) and the totals row states whether the current total is within or over
  it. The static explainer paragraph under "Quote to owner" (duplicated what the figure now shows
  concretely) was removed.
- **Duplicate success message (#7).** `rental-job-cards/show.blade.php` rendered its own inline
  `session('success')` banner ON TOP OF the app's standard toast
  (`components.toast-notifications`, which reads the same flash key) — same bug, same fix, as
  `leases/show.blade.php` (AT-444 follow-up 2): banner removed, toast is the only success surface.
- **"Send to owner as quote" mail (#8) — FIXED 2026-10-05, Johan's follow-up ruling.** Confirmed the
  defect: `RentalWorkOrderService::notifyOwner()` (`app/Services/Rentals/RentalWorkOrderService.php:166`,
  prior to this fix) resolved its recipient via `Property::sellerOwnerContact()`
  (`app/Models/Property.php:1169`) — a method whose own documented fallback (AT-105, for PDF Splitter
  filing) returns "the sole linked contact" when none is tagged seller/owner/landlord/lessor. On a
  property with ONLY a tenant linked (QA1 property 5792 — Andre Roets, a real personal email, no
  landlord contact at all), that fallback resolves the TENANT as "the owner." Never actually
  triggered on QA1 (job card #1 has no quote row), but a real defect, not a hypothetical.
  **Fix — narrowest point covering both callers, sales-side untouched:**
  - New `Property::landlordContact()` (`app/Models/Property.php`, right after `sellerOwnerContact()`)
    — the SAME seller-side role match, but NO sole-contact fallback; null when nothing is explicitly
    tagged landlord/owner/seller/lessor. `sellerOwnerContact()` itself is untouched — every other
    caller (PDF Splitter, FICA pre-fill, compliance aggregation, reports, the rental-context-bar's own
    landlord display) keeps its existing, deliberate fallback.
  - `RentalWorkOrderService::notifyOwner()` now calls `landlordContact()` instead — the ONE choke
    point both the job card's "Send to owner as quote" (`RentalJobCardService::sendToOwnerAsQuote()`)
    and every outside-supplier owner notification (creation + completion, §4) route through. No
    landlord → mail is skipped, exactly as it already was for a property with zero contacts; it now
    also correctly skips for a property with a tenant-only contact instead of mis-firing.
  - `RentalJobCardService::sendToOwnerAsQuote()` additionally hard-blocks the WHOLE action (not just
    the mail) when `landlordContact()` is null — throws `LogicException('No landlord linked — link a
    landlord before sending the quote.')` before generating the PDF or recording anything, per
    Johan's ruling that an owner quote must never silently proceed without a real owner to send it to.
    The job card show screen's "Quote to owner" block shows this message plus a "Link landlord" link
    (→ the property's Contacts tab) instead of the send button whenever no landlord is linked.
  - The outside-supplier path (work order creation/completion) is NOT hard-blocked the same way —
    those notifications are automatic side effects of actions a user already took for other reasons
    (reporting/completing a work order), not a standalone "send" the user clicked expecting a mail;
    they keep their existing silent-skip-if-no-recipient behaviour, just now correctly never
    resolving a tenant as that recipient.
  - `RentalJobCardLifecycleTest`'s fixture property had no contacts at all, so its three quote-sending
    tests (relying on the OLD skip-mail-but-still-send behaviour) needed a landlord contact added to
    `setUp()` to keep passing — this is the intended behavioural tightening, not a regression.

### 14.17 VAT on job cards, Pastel-style — agency VAT set-up + per-line VAT type (2026-10-05)

Johan: job cards must show no VAT at all by default; an agency can set whether it is VAT registered,
and when it is, each job card line carries its own VAT type — "work like Pastel."

**Reused, not duplicated:**
- `agencies.vat_registered` / `agencies.vat_no` (already existed, migration `2026_07_25_120005` — had
  **no edit UI anywhere** before this work; Company Settings → Company tab now has the control).
- `PerformanceSetting::get('vat_rate', 15, $agencyId)` (already existed, agency-scoped) — the Standard
  VAT type always reads this live, never stores its own copy.
- The Proforma invoice snapshot pattern (`ProformaInvoice`/`ProformaFinancialResolver`) was the model
  for freezing VAT state at the moment of truth.

**New — Agency VAT set-up** (Company Settings → Company tab, permission `manage_performance_settings`):
- `agencies.vat_capture_mode` (`excl`|`incl`, default `excl`) — whether prices typed in (job card
  lines, the parts & labour catalogue's default price) are excl. or incl. VAT. No conversion of
  existing prices when changed.
- `agencies.vat_settings_updated_at` / `vat_settings_updated_by_user_id` — audit stamp, set only when
  `vat_registered`/`vat_capture_mode`/`vat_no` actually changed.
- Setup Wizard: `vat_registered` (toggle, default off) and `vat_capture_mode` (select, default excl)
  added to the identity step (`config/agency-onboarding-copy.php`), saved via the SAME
  `SettingsController::updateAgency`/`CompanySettingsController::update` actions, guarded on
  `vat_registered`'s own presence (CLAUDE.md §6.1) so sibling forms sharing either action never touch
  it.

**New — `rental_vat_types`** (agency-maintained, full CRUD, no hard delete): `name`, `rate_mode`
(`agency_rate` | `fixed` | `custom_per_line`), `fixed_rate` (only for `fixed`), `is_default`,
`is_active`. Seeded per agency (`RentalVatType::seedDefaultsFor()`, fired on `AgencyCreated` same as
every other per-agency default list in this codebase) with **Standard VAT** (`agency_rate`, default),
**No VAT** (`fixed`, 0%), **Custom** (`custom_per_line` — the agent types a rate on the line itself).
An agency may add further fixed-rate types (e.g. a zero-rated export rate), rename, or archive any of
them; exactly one `is_default` at a time (`RentalVatType::makeDefault()`). Managed inline on the
Company Settings "VAT Types" panel — not a separate full list screen, matching this list's small size
(same reasoning as the testimonials panel on the same page).

**Job card lines** (`rental_job_card_lines`): `rental_vat_type_id` (nullable FK) + `custom_vat_rate`
(only meaningful when the chosen type is `custom_per_line`) — the agent's live choice, defaulting to
the catalogue item's own `default_rental_vat_type_id` when one is picked, else the agency's default
type (`RentalJobCardVatService::defaultVatTypeIdFor()`), always editable per line via
`storeLine`/`updateLine`. `rental_catalogue_items.default_rental_vat_type_id` — a catalogue item's own
default, shown on its create/edit screen only when the agency is VAT registered; the default-price
column label switches "excl VAT"/"incl VAT" per the agency's capture mode, no data conversion.

**Calculation — `RentalJobCardVatService`:** per line, excl/VAT/incl computed from the captured
`line_total` and the line's effective rate, honouring the agency's capture mode (if `incl`, excl is
derived back out: `excl = incl / (1 + rate/100)`); rounded to 2 decimals PER LINE, matching the
existing `line_total` rounding discipline, never re-rounded at the group/total level. The totals block
groups by rate across ALL lines regardless of which VAT type produced it (`VAT @ 15%`, `VAT @ 10%` for
a Custom line at 10%, etc.) — a 0%-rate group is never shown (nothing to add), though its lines still
count toward the subtotal. An agency that is NOT VAT registered, or has `capture_prices_on_job_cards`
off, gets the exact pre-existing behaviour: no VAT type selector anywhere, no VAT in any total — this
method's own first check short-circuits to that.

**Snapshot — frozen once, at the first of "send to owner as quote" or job-card completion**
(`RentalJobCardVatService::snapshot()`, called from both `RentalJobCardService::sendToOwnerAsQuote()`
and `::complete()` — the latter covers an internal job that never goes through a quote at all, e.g.
an under-threshold repair completed without ever being sent to the owner). Freezes, per line, the
type's name/rate and the computed excl/VAT/incl figures (`vat_type_name_snapshot`,
`vat_rate_snapshot`, `vat_excl_snapshot`, `vat_amount_snapshot`, `vat_incl_snapshot`), and on the card
itself the registration flag and capture mode at that moment (`vat_registered_snapshot`,
`vat_capture_mode_snapshot`, `vat_snapshotted_at`). Every read of the breakdown
(`RentalJobCardVatService::breakdown()`) checks `vat_snapshotted_at` first and reads the frozen
columns once set — a later change to the agency's VAT registration, rate, capture mode, or a VAT
type's own rename/rate edit can NEVER alter an issued quote or a closed job card, exactly the
Proforma invoice's own "freeze at generation" discipline.

**The landlord no-approval spend threshold is now compared VAT-INCLUSIVE** (§3.4b/§3.4c unchanged
mechanism, `RentalWorkOrder::selectQuote()` — only the `amount` fed into it changed):
`RentalJobCardService::sendToOwnerAsQuote()` now sets the recorded quote's `amount` to
`RentalJobCardVatService::inclusiveTotal()` (the snapshot's `totalIncl`, or the unchanged
`total_amount` for a non-registered agency) — the landlord pays the VAT-inclusive figure, so that is
both what the quote says and what the threshold gate compares, replacing the excl-only
`$jobCard->total_amount` the pre-VAT build used. Same figure flows into the linked work order's
`cost_amount` at completion.

**Documents and report — same breakdown everywhere, one source:**
- Quote PDF (`jobCardQuotePdf()`) and the printed job card (`jobCardPrintPdf()`, still gated by
  `show_prices_on_printed_job_card` exactly as §14.14 already specifies) both render the Subtotal
  (excl) / VAT-per-rate / Total (incl) block and the agency's VAT number when registered — generated
  from the ALREADY-snapshotted figures for the quote PDF (called after `snapshot()`), or the agency's
  then-current settings for a draft card's print.
- Job cards report (`RentalReportService::jobCards()`) adds `total_excl`/`total_vat`/`total_incl`
  columns (summable) when the acting user's agency is VAT registered; absent entirely otherwise — same
  "never a forced column nobody asked for" discipline as the rest of this report.

**Not in the wizard, deliberately:** the VAT types list itself — it is a full CRUD surface (add/
rename/archive, one default), not a single toggle, same call already made for the fault catalogue
(`rentals-faults-work-orders.md` §10) and the parts & labour catalogue itself (§14.3 above).

**Rentals nav:** "Parts & Labour Catalogue" added directly under "Job Cards" in the Rentals panel
(`corex-sidebar.blade.php`, permission `rental_catalogue.view`) — it already existed under Settings
(`corex.rental-catalogue-items.*`); both entries now point at the same screen.

**Tests:** `tests/Feature/RentalJobCards/RentalJobCardVatTest.php` (not-registered unchanged; excl and
incl capture; mixed Standard/No VAT/Custom card groups by rate; per-line rounding; snapshot freezes
and survives a later rate/capture-mode change; threshold compared incl VAT; VAT types agency-isolated;
default-type resolution) and `tests/Feature/Admin/AgencyVatSetupTest.php` (save + validation + audit
stamp; a sibling form sharing `update()` never flips registration off; VAT type CRUD, default,
archive/restore, agency isolation).

**Finance stage dependency:** this is the single source of truth `.ai/specs/rental-money.md` §6 will
read from when a completed job card's lines become charges — see that spec's own note.

### 14.18 Catalogue code/description split + job-card line picker fix (AT-442 QA1 findings, 2026-10-05 round 3)

Johan's three QA1 findings, each with a root cause and fix:

**A — "picking a catalogue item still required typing the description."** The catalogue item had
only one `name` field (code and description combined into one string), so there was nothing to
pick that was both short enough to scan in a list AND descriptive enough to use as the line's
description. Fixed by splitting `rental_catalogue_items.name` into two required columns: `code`
(string 50, unique per agency among non-archived rows) and `description` (string 500).
`2026_10_05_270000`/`270100`/`270200` add the columns, backfill every existing row (`code` derived
from the old `name` — uppercased, non-alphanumeric collapsed to `-`, truncated to 20 chars, deduped
per agency with a numeric suffix; `description` copied verbatim from the old `name`), then drop
`name`. `RentalCatalogueItem::label()` returns `"{code} — {description}"` — the one display string
used everywhere an item is picked from (catalogue list, job-card picker, print/quote).

**B — "an item marked Parts showed as Labour on the line, and Type couldn't be changed."** Two
separate bugs, both in `RentalJobCardService::addLine()`: (1) the picked catalogue item's `kind()`
always won over an explicitly posted `type`, even when the agent had deliberately changed the
dropdown — reversed so an explicit posted `type` now wins, falling back to the catalogue item's
kind only when none was posted; (2) the OLD add-line row's Type/Unit `<select>` elements were
`disabled` once a catalogue item was picked — a disabled field is never submitted at all, so the
server never even saw a `type` to disagree with, and the Alpine `onchange` handler that was
supposed to apply the picked item's kind never actually wrote a value into the select. The
rebuilt add-line row (`_add-line-row.blade.php`) never disables anything; every field stays
editable after a pick.

**C — "no column headers above the add-line row."** `App\Support\RentalJobCardLineGrid` is the one
source of truth for the row's `grid-template-columns` track list, shared by a new
`_line-columns-header.blade.php` partial, the existing-lines table, and the add-line row, so all
three can never drift apart. Header labels: Item, Description, Type, [Unit, Qty, Unit price, [VAT]
— only when prices/VAT apply], blank (archive/+ action). Rendered once per task block and once for
the General block (`show.blade.php`), immediately above that block's lines table.

**The picker itself — searchable, pre-fills everything, still fully editable.** The add-line row's
Item field is a type-to-search box (`catalogueLinePicker()` Alpine component, one instance per
row) matching on code or description, showing `"CODE — Description"` in the dropdown. Picking an
item sets description, type, unit, unit price, and VAT type on the SAME row — every one of those
fields stays a normal editable control afterward (nothing disabled, per the bug-B fix above); the
hidden hard-fail-safe is "no match → Free text" is always offered as an explicit dropdown option.

**Root-cause bug found only by a real browser click, not the PHPUnit suite:** the picker's `pick()`
method originally read `this.$el.querySelector(...)` to find its row's sibling fields. `pick(it)`
is invoked via `@click="pick(it)"` from inside an `x-for`-rendered dropdown item — Alpine binds
`$el` to the element whose directive triggered the CURRENT evaluation chain, not to the
component's root, so inside that call chain `$el` resolved to the tiny clicked `<div>` (the
dropdown option itself), not the row. `querySelector` on that element found nothing, so
description/type/unit/price silently stayed blank while the plain reactive write
(`this.selectedId = item.id`) still succeeded — which is exactly why the PHPUnit `addLine()` tests
all passed (they call the service directly, never touching Alpine) while the live control did
nothing visible. Fixed by capturing the row's root element once, in `init()`
(`this.rootEl = this.$el`), and reading `this.rootEl.querySelector(...)` from `field()` instead.
Confirmed via a real Puppeteer click (not a synthetic `element.click()` call) reading back the
resulting field values — this is the "a passing server-contract test does not prove a UI control
works for a real click" case `BUILD_STANDARD.md` warns about, caught only by browser verification.

**A second, independent bug found the same way:** `RentalJobCardLineGrid::columns()`'s Description
track was `minmax(0,1fr)` — no floor — while Type/Unit/Qty/Unit price/VAT held a combined ~460px of
fixed-width tracks. At 1366px the row only has ~604px available next to the crew/sign-off right
panel, so the grid's own auto-sizing starved Description down to ~18px (Item also went below its
cap) — present in the DOM, invisible and unusable on screen, which a pure "does it wrap to a
second line" check would not catch. Fixed by giving Description a 70px floor
(`minmax(70px,1fr)`) and trimming the other tracks (Item 130→100px, Type 110→90px, Unit 70→64px,
Qty 56→50px, Unit price 92→84px, VAT 96→84px) to fit the real 1366px budget — confirmed via
`getBoundingClientRect()` computed widths at both 1366 and 1536, not a screenshot alone.

**Saved lines snapshot, unchanged by this round:** `rental_job_card_lines.code` (added
`2026_10_05_270300`) joins the existing `type`/`description`/`unit` snapshot columns (§14.2) — a
line copies the picked item's code at add-time and never re-reads the catalogue item again, so a
later rename/archive/price-change on the catalogue item never retroactively changes an existing
job card. Print/quote (`_pdf-lines-table.blade.php`, shared by `print.blade.php` and
`quote-pdf.blade.php`) shows `"code — description"` when a code is present, description alone for
a free-text line.

**Tests:** `tests/Feature/RentalJobCards/RentalCatalogueItemTest.php` (code required/unique per
agency among non-archived rows, archived codes may be reused, codes are agency-isolated, search
matches code or description) and `tests/Feature/RentalJobCards/RentalJobCardAt442FollowUpTest.php`
(picking a catalogue item pre-fills description/unit/price; an explicit posted description/type
still overrides the catalogue item's own; a free-text line has no code; a line's code survives a
later rename/archive of the catalogue item it came from).

**Files:** `database/migrations/2026_10_05_270000..270300_*`, `app/Models/RentalCatalogueItem.php`,
`app/Models/RentalJobCardLine.php`, `app/Services/Rentals/RentalJobCardService.php`,
`app/Http/Controllers/CoreX/RentalCatalogueItemController.php`,
`app/Http/Controllers/CoreX/RentalJobCardController.php`, `app/Support/RentalJobCardLineGrid.php`
(new), `resources/views/corex/rental-job-cards/_line-columns-header.blade.php` (new),
`resources/views/corex/rental-job-cards/_lines-table.blade.php`,
`resources/views/corex/rental-job-cards/_add-line-row.blade.php`,
`resources/views/corex/rental-job-cards/show.blade.php`,
`resources/views/corex/rental-job-cards/_pdf-lines-table.blade.php`,
`resources/views/corex/rental-catalogue-items/{index,create,edit}.blade.php`.

### 14.19 Catalogue bulk import (2026-10-05) — load a price list in one go

Johan's own instruction: an agency loading a parts/labour price list should not have to add every
item one at a time. Template download -> upload CSV/XLSX -> dry-run preview with per-row errors ->
confirm, duplicate-by-code update-or-skip by the agency's own choice.

**Deliberately NOT the take-on importer's shape.** §9's `RentalTakeOnImportRun`/`Row` pair
persists every batch as a listable, archivable entity because a take-on book is a one-time,
high-stakes migration worth a permanent audit trail. Nobody asked for that here — this is a
repeatable "top up my price list" action on a single, already-fully-CRUD entity
(`RentalCatalogueItem`). The dry-run result is held in `Cache` (database store, 30-minute TTL,
keyed by a UUID token carrying the resolving agency's id) between the upload and confirm requests
instead of new database tables — nothing is written until Confirm, and nothing about the batch
itself needs to outlive that round trip. Building the heavier Run/Row/archive machinery here would
have been exactly the kind of silent extra non-negotiable #6 forbids.

**Template** (`RentalCatalogueImportTemplateService`, PhpSpreadsheet with real dropdown data
validation — same technique as `RentalTakeOnTemplateService`, chosen there because OpenSpout's
writer supports neither a second sheet nor dropdowns). Columns, fixed by position: Code,
Description, Type, Unit, VAT type, Price (excl VAT), Price (incl VAT). The Type/Unit/VAT type
columns' dropdowns are built from the downloading agency's OWN configured lists — never a
hardcoded Labour/Part/Each list (multi-agency always, non-negotiable #9). A second "Instructions"
sheet explains every column and the duplicate-handling choice.

**Parsing** (`RentalCatalogueImportRowParser`) reads CSV/XLSX by column position via OpenSpout,
same streaming-generator pattern as `RentalTakeOnRowParser`/`ContactImportController`. Price cells
pass through as raw values — parsing and "is this even a number" validation live in the resolver
so an unparseable price becomes a named per-row error rather than a silently-dropped null.

**Dry-run resolution** (`RentalCatalogueImportDryRunResolver`), per row, agency-scoped throughout:
- Code and Description required; Type and Unit required and must match one of the agency's own
  active `RentalCatalogueItemType`/`RentalCatalogueUnit` names (case-insensitive) — an unmatched
  name is an error naming the exact bad value and pointing at Settings, never a silent fallback.
- VAT type is optional. Blank means no VAT type on the item (same as leaving the single-item
  form's own VAT type picker unset) — **not** "use the agency's default type"; a provided name
  must match an active `RentalVatType` and must not be `rate_mode=custom_per_line` (the template
  has no column for a per-item custom rate — that combination is a named error directing the
  agent to set it afterwards on the item's own edit screen, rather than silently importing a wrong
  rate).
- Price: excl wins when both excl and incl are filled; incl-only is converted down to excl via
  `RentalJobCardVatService::splitAmount()` — the exact same conversion the single-item create/edit
  form uses, so an agency gets numerically identical results whether it types one item or imports
  a thousand. Not VAT-registered: incl is read as the same plain amount as excl (no conversion
  attempted, matching the single-item form's "a single Price field" behaviour).
- Duplicate-by-code: an existing agency item with the same code resolves to `update` or `skip`
  per the ONE choice made on the upload form (applies to the whole file — not a per-row override,
  since nothing in the brief asked for mixing both within one upload and a blanket choice is what
  "by choice" plainly reads as). A code reused a second time WITHIN the same uploaded file is
  always an error on the second occurrence, regardless of duplicate mode — almost certainly a
  mistake in the source spreadsheet, never silently resolved either way.
- Every row that errors is left exactly alone — confirm only ever creates/updates rows whose dry
  run actually resolved to `create`/`update`.

**Confirm** (`RentalCatalogueImportController::confirm()`) re-reads the cached dry-run rows (never
re-parses the file) and is the only method in this feature that writes — mirrors the per-row
`create()`/`update()` field set the single-item controller already uses, so an imported item is
indistinguishable from a hand-entered one. Flashes a plain-language summary (created/updated/
skipped-as-duplicate/skipped-as-error counts).

**Permission:** reuses `rental_catalogue.manage` (no new permission key) — importing is exactly as
mutating as editing one item by hand. Reached from an "Import" button on the existing Parts &
Labour Catalogue list (`rental-catalogue-items.index`), itself already on the Rentals nav panel —
non-negotiable #2's "nav entry same day" is satisfied via that existing entry point, not a new
standalone sidebar item (a repeatable secondary action off an already-CRUD screen, not a
first-class destination — the "would this get lost without a sidebar link" test the take-on
importer's own standing sidebar entry exists for does not apply the same way here).

**Not in the wizard, deliberately:** this is a workflow/action, not a setting — nothing to add to
`config/agency-onboarding-copy.php`.

**Tests:** `tests/Feature/RentalJobCards/RentalCatalogueImportTest.php` — template downloads;
upload previews without writing anything; confirm creates the previewed rows; duplicate code
updates when "update" chosen and is left untouched when "skip" chosen; an unknown type is a
per-row error and is never created; a code reused twice in one file errors on the second row; an
incl-VAT price converts down to excl identically to the single-item form; a blank VAT type column
means no VAT type, not the agency default; a preview token belonging to another agency cannot be
viewed or confirmed; blank price columns leave `default_price` null.

**Files:** `app/Services/Rentals/CatalogueImport/{RentalCatalogueImportRowParser,
RentalCatalogueImportTemplateService,RentalCatalogueImportDryRunResolver}.php` (new),
`app/Http/Controllers/CoreX/RentalCatalogueImportController.php` (new),
`resources/views/corex/rental-catalogue-items/import/{index,preview}.blade.php` (new),
`resources/views/corex/rental-catalogue-items/index.blade.php` (Import button), `routes/web.php`.

### 14.20 Job card lines — edit in place, stay where you were, readable item list, no page scroll (2026-10-05, Johan QA1 findings)

Four faults Johan hit on the job card screen after §14.18. Root cause first, then the fix.

**1. Saved lines could not be changed.** Root cause: the saved-lines partial rendered static text plus
an archive ×; the `updateLine` route/controller/service existed but nothing on the screen ever called
them, and the service could not change `type` or the catalogue item, and treated a blanked field as
"keep the old value" (`?? $line->x`).
Fix: every saved line (task lines and General) gets an **Edit** pencil (permission
`rental_job_cards.create`, only while the card is open). It swaps the row, in place, for the SAME
fields and grid as the add row (`_add-line-row.blade.php`, new mode `edit` — one partial, so editor and
add row cannot drift): **Item** (the same code/description picker; clearing it makes the line free
text, dropping the catalogue link AND the copied `code`), **Description**, **Type**, **Unit**, **Qty**,
**Unit price**, **VAT type** (+ custom rate where the type needs one) with **Save / Cancel**. Cancel
discards (the editor is created fresh each time it opens). Validation (description required, qty ≥
0.01, price ≥ 0, type labour/part, VAT type and catalogue item must belong to the agency) returns the
agent to the same card with that line's editor reopened, what they typed preserved, and the messages
shown under the row. Saving recalculates the line total, task subtotal, card total and the VAT
breakdown (they are computed from the lines on every render; `recalcTotal()` refreshes the stored
total). A key absent from the request leaves that field alone; a key present but blank clears it
(unit "—" and an emptied price really clear). **Archive (×) is unchanged** — still a soft archive,
restored from "archived line(s)". A **completed or cancelled** card never lets a line be edited (no
pencil rendered; the server refuses too). A card whose VAT is already frozen by "Send to owner as
quote" shows the edit in its totals: only the EDITED line's frozen figures are re-frozen
(`RentalJobCardVatService::refreshLineSnapshot()`); every other line keeps the figures it was issued
with even if the agency's VAT rate moved since. No new permission, route or setting (nothing for the
Setup Wizard — this is an action on an existing record, not a setting).

**2. Adding a line jumped the panel back to the top.** Root cause: add/edit/archive is a plain form
POST + redirect, i.e. a fresh document; the two panels are inner scrollers (`#jc-left-col` /
`#jc-right-col`) whose `scrollTop` lives in the old DOM, and browser scroll restoration only covers the
window — so both started at 0. Fix: every line form carries `data-keep-scroll`; on submit the show
screen stores both panels' scrollTop in `sessionStorage`; the next load restores it, then brings the
changed line (`jc_focus_line`, flashed by the controller on add/edit/restore) into view with the
minimum scroll needed and briefly highlights it. After an archive the position is simply kept. A failed
validation reopens the editor and scrolls it into view. Scrolling is done on the panel by hand — not
`scrollIntoView()`, which would also move `#appScroll`. Stale entries (> 2 min) are ignored; storage
being blocked falls back to the old behaviour without breaking anything.

**3. The item dropdown was 100px wide, wrapped, and clipped.** Root cause: the list was
`position:absolute; width:100%` inside the Item cell, and an `overflow-y:auto` ancestor (the left
panel) clips absolutely-positioned descendants on both axes. Fix: the list is teleported to `<body>`
and placed `position:fixed` from the Item input's own rectangle — at least as wide as Item + Description
(never under 360px, never wider than the window), one line per item ("CODE — Description", ellipsis +
tooltip beyond that), flips above the input when there is little room below, follows the panel while it
scrolls and closes if the input scrolls out of the panel. Keyboard: ↓/↑ move (opening the list on the
first press, wrapping, keeping the highlighted row in view), **Enter** picks the highlighted row —
typing auto-highlights the first match so "type a few letters, Enter" works — **Esc**/Tab close;
hover highlights; `role=combobox/listbox/option` + `aria-activedescendant`. Applies to the create
screen's draft rows as well (same partial).

**4. A page scrollbar appeared on top of the two panel scrollbars.** Root cause: the panel height was a
one-shot JS estimate with a 240px floor while `#appScroll` (the page scroller) stayed scrollable, so any
slop — sub-pixel rounding (exactly 1px over at 1366×600), a window shorter than header + 240px, a late
layout shift — left `#appScroll` overflowing and the browser drew its own scrollbar. Fix: at ≥1024px
`#appScroll` is `overflow-y:hidden` on this screen (the page itself cannot scroll; the two panels are the
only scrollers), floor lowered to 120px, height floored to whole pixels, and the sizing re-runs when
anything above the panels changes size (ResizeObserver) and after fonts load. Below 1024px nothing
changes — stacked page scroll. Verified 1366 and 1920 wide, 560–1080 tall, at 1× / 1.1× / 1.25× / 1.5×.

**Known, not changed (outside this scope — reported; ALL RESOLVED in §14.21 below):** the same "reload
jumps to top" happens on the other forms of this screen (task add/rename/archive, crew assign, schedule,
sign-offs — one `data-keep-scroll` attribute each); adding or archiving a line on a quoted/completed card
is still allowed by the screen and the add path does not freeze the new line's VAT figures.

**Tests:** `tests/Feature/RentalJobCards/RentalJobCardLineEditTest.php` — every editable field persists
and totals/subtotal/VAT breakdown follow; a different item re-copies its code, free text clears it, an
unchanged item keeps the link; blank unit/price clear; validation failures change nothing and keep the
editor's input; another agency's VAT type / catalogue item rejected; completed and cancelled cards
refuse edits; another agency gets 404; a quoted card shows the edit while the other line keeps its
issued figure; the screen renders the pencil only while open; archive/restore/add flash the focus id.

**Files:** `app/Services/Rentals/RentalJobCardService.php` (`updateLine`),
`app/Services/Rentals/RentalJobCardVatService.php` (`refreshLineSnapshot`),
`app/Http/Controllers/CoreX/RentalJobCardController.php`,
`resources/views/corex/rental-job-cards/{show,_lines-table,_add-line-row}.blade.php`.

### 14.21 Job card follow-ups — stay in place everywhere, locked when closed, editable sent quotes with revisions, VAT amount on the printouts (2026-10-05 evening, Johan's rulings)

Four rulings, QA1 only. Root cause / approach first, then what was built.

**1. The rest of the screen still jumped to the top.** Root cause: identical to §14.2 — every other
form on the card is a plain POST + redirect, so the two inner scrollers (`#jc-left-col`/`#jc-right-col`)
restart at 0. §14.20 only marked the line forms. Fix: the same `data-keep-scroll` attribute (and the
same sessionStorage save/restore script) on EVERY form of the saved card — task add / rename /
archive / restore / tick, assign crew, schedule Set, start, worker / agent sign-off, tenant
confirmation, complete, cancel, send quote, photo upload, header Edit. The task forms additionally
flash `jc_focus_task`, so a new / renamed / restored task is brought into view (minimum scroll) and
briefly highlighted, exactly like a changed line. The create (draft) screen is unchanged.

**2. A completed or cancelled card still allowed lines and tasks to be added / edited / archived.**
Root cause: only `updateLine()` had a controller-level guard (§14.20); add / archive / restore line
and every task action had none, and the screen still rendered the controls. Fix, enforced where it
cannot be bypassed: `RentalJobCard::assertContentEditable()` (throws `LogicException` "This job card is
closed — its lines and tasks can no longer be changed.") is called at the top of every service method
that changes a card's lines or tasks (`addTask`, `renameTask`, `toggleTask`, `reorderTasks`,
`archiveTask`, `restoreTask`, `addLine`, `updateLine`, `archiveLine`, `restoreLine`) — so the web
controller, a direct POST, and the mobile tick endpoint all hit the same refusal; controllers turn it
into a clear error and NO change. The screen hides Add task / Rename / Archive / Restore, the task
tick, the add-line row, the line pencil and × and the archived-lines Restore on a closed card (the
card is read-only). Ticking a task counts as editing it. History, photos, printing and viewing are
untouched.

**3. A sent quote could not be changed safely.** Johan's ruling — the same as an accounting invoice:
a card whose quote has been sent stays editable; editing updates the card; the quote can then be
RE-SENT and the re-send REPLACES the old one.
Root cause of the "frozen totals skip the new line" fault: "Send to owner as quote" freezes VAT per
line (`snapshot()`); `breakdown()` then reads ONLY the frozen figures and silently `continue`s past any
line with no snapshot — and `addLine()` / `restoreLine()` never froze the new line. Fix: a line added
or restored after the freeze gets its snapshot straight away (same `refreshLineSnapshot()` an edit
uses, in the card's FROZEN capture mode), and `breakdown()` no longer skips an unfrozen line — it
computes it live in the card's frozen mode, so a total can never silently drop a line.
Revisions (new columns on `rental_work_order_quotes`: `revision`, `superseded_at`,
`content_signature`; existing multi-send cards are back-filled 1..n, all but the last marked
superseded):
- First send = Rev 1. Every later send is the NEXT revision (Rev 2, 3…): the VAT freeze is redone for
  every line, a new quote PDF is stored and recorded, and that revision becomes the work order's
  selected quote (`selectQuote()`), so the owner's portal / amount / the no-approval spend threshold all
  follow the new figure and there is exactly ONE current quote.
- The previous revision is NEVER deleted: `superseded_at` is stamped, it is shown "Superseded" and its
  stored PDF stays viewable from the card (`rental-job-cards/{card}/quotes/{quote}/download`). A
  superseded revision cannot be re-selected (`selectQuote()` refuses it).
- Acceptance does not carry over: `selectQuote()` already drops a recorded owner approval/decline when
  a different quote is selected and re-derives the state against the NEW amount (auto-approved at or
  under the landlord's limit, otherwise pending the owner again), logging "approval superseded". The
  card's own status goes back to Quoted from Approved on re-send (then re-syncs); Scheduled / In
  progress stay where they are — the work is already planned, only the owner approval resets.
- "Changed since sent": each quote stores a `content_signature` — a hash of the card's title, live
  tasks and live lines (everything the PDF shows). The screen compares it with the card's signature
  now, so ANY edit path (line add/edit/archive/restore, task rename/archive…) flips the card to
  "Changed since Rev N was sent — re-send to update the owner", and re-sending clears it. The header
  shows "Quote Rev N" (+ "changed since sent") and the Send box lists every revision (current first,
  "Superseded" greyed, View links).
- The send box is offered on any OPEN card that has a sent quote (the old rule only showed it for
  draft/quoted, but an under-limit quote moves the card to Approved at once, which hid it).
  Completed / cancelled cards cannot be (re)sent.
- Owner email: a re-send emails the landlord a "Revised quote (Rev N)" notice. On QA1 every mail goes
  to the local Mailpit catcher (127.0.0.1:1025) — nothing leaves the box; tests use `Mail::fake()`.

**4. The VAT TYPE column on the printouts showed "—".** Root cause: `breakdown()` writes the
`vat_display_*` attributes onto `$jobCard->lines`, but the PDF partials iterate `$task->lines`, which
are DIFFERENT model instances — the attributes were never there, so the column fell through to "—".
Fix: `breakdown()` now also returns `lineFigures` keyed by line id (excl / VAT / incl / rate / label);
the PDF partial reads that, never instance attributes. On the printed job card AND the owner quote PDF
the VAT TYPE column is removed; for a VAT-registered agency the lines table is Description · Type ·
Qty · Unit price · Excl VAT · VAT (the VAT amount in rand, per line); each task's / General's subtotal
row shows the group's excl and VAT; the totals block shows Subtotal (excl VAT), the VAT, and Total (incl
VAT) (a "Total VAT" line is added when more than one rate is in play). A non-VAT agency shows no VAT
column or VAT line anywhere (unchanged). When the agency captures prices INCLUDING VAT the unit price
header reads "Unit price (incl VAT)" so the row still adds up.

No new permission or setting (actions on an existing record) — nothing for the Setup Wizard.

**Found while click-proving, handled:** the task TICK checkbox cancelled its own click
(`onclick="return false"`), so ticking only worked on the padding around the box. The box now submits
the form itself (`onclick="event.preventDefault(); this.form.requestSubmit();"` — `requestSubmit()` keeps
the `data-keep-scroll` handler); the state shown always comes from the server after the reload.

**Found while click-proving, REPORTED NOT FIXED (outside this scope):** the "Set" button on Schedule
returns a 500 whenever a date is filled in — `RentalJobCardController::schedule()` hands the validated
date STRINGS to `RentalJobCard::schedule(?\DateTimeInterface …)` (`app/Models/RentalJobCard.php`), which
rejects a string with a TypeError. Blank dates work. One-line fix (parse to Carbon) awaiting an explicit go.

**Tests:** `tests/Feature/RentalJobCards/RentalJobCardFollowUpsTest.php` (25 cases) — every card form carries
`data-keep-scroll` and the tick box submits; task actions flash `jc_focus_task`; a completed AND a
cancelled card refuses every add / edit / archive / restore / tick / rename on lines and tasks, changes
nothing, and the service itself refuses (not only the controller); the closed screen renders none of the
controls and the mobile tick is a clean 422; a line added after sending has its VAT snapshot and is in the
totals, and `breakdown()` never skips an unfrozen line; first send = Rev 1, re-send = Rev 2 superseding
Rev 1 (kept, not deleted, one current quote, Rev 2 totals include the post-send line); "changed since
sent" appears after any edit and clears on re-send; a recorded owner approval of Rev 1 does not carry
over (over-limit Rev 2 is pending, card back to Quoted, `approval_superseded` logged); a superseded
revision cannot be re-selected; the revised-quote mail goes to the landlord only; a closed card cannot
send; quote download is scoped to its card and agency; the migration back-fill labels legacy multi-send
quotes 1..n; the printouts drop the VAT type column and show per-line / subtotal / total VAT amounts
(several rates add a Total VAT line; a non-VAT agency shows none; incl-capture labels the unit price);
real dompdf output still renders.

**Files:** `database/migrations/2026_10_08_120000_add_revisions_to_rental_work_order_quotes_table.php`
(new), `app/Models/{RentalJobCard,RentalWorkOrderQuote,RentalWorkOrder}.php`,
`app/Services/Rentals/{RentalJobCardService,RentalJobCardVatService,RentalDocumentPdfService}.php`,
`app/Http/Controllers/CoreX/RentalJobCardController.php` (+ `downloadQuote`, `refuseIfClosed`),
`app/Http/Controllers/Api/MobileRentalJobCardController.php`, `app/Mail/Rentals/RentalWorkOrderOwnerMail.php`,
`resources/views/corex/rental-job-cards/{show,_lines-table,_pdf-lines-table,print,quote-pdf}.blade.php`,
`resources/views/emails/rentals/work-order-owner.blade.php`, `routes/web.php`
(`corex.rental-job-cards.quotes.download`).

### 14.22 Job card — schedule "Set" 500, and the printed card / owner quote running to the paper edge (2026-10-05 night, Johan's QA1 findings)

**Root cause 1 — Set returned a 500 whenever a date was filled in.** `RentalJobCardController::schedule()`
validated the two datetime-local boxes as `date` but handed the raw STRINGS to
`RentalJobCard::schedule(?\DateTimeInterface, ?\DateTimeInterface, User)` → TypeError. Blank worked (null
is allowed). **Fix (the class, not the instance):** the controller now parses both inputs itself
(`parseDateInput()`), and no raw date string reaches a model method any more.
- Accepted: exactly what the date pickers post — `Y-m-d\TH:i`, with seconds, `Y-m-d H:i[:s]`, or a bare
  `Y-m-d` (midnight). Refused with a plain message on the field: words/relative ("tomorrow"), overflow dates
  ("2026-02-31"), month 13 / hour 25, `0000-00-00`, years outside 2000–2100, anything else.
- Read in the **agency timezone** (`Agency::outreachTimezone()`, the one place a per-agency column will
  land) and stored in the application timezone — the wall-clock the agent typed is what the card shows back.
- **Due earlier than Scheduled is refused** ("Due can't be earlier than Scheduled"; equal is fine). Both bad
  fields are reported together. A refused Set changes nothing (saved dates stay) and the typed values come
  back into the two boxes (`old()`).
- **Blank still clears** (either or both). Stay-in-place (`data-keep-scroll`) unchanged.
- **Same pattern elsewhere on this controller:** only the schedule action handed strings to a typed model
  method. The list's `date_from` / `date_to` filter inputs went straight into the query unvalidated and
  `date_to` compared a bare date to a datetime column (= midnight), silently dropping every card due later
  on the "to" day. They now go through the same parser (junk = message on the list screen), `to` = end of
  that day in the agency timezone.

**Root cause 2 — PDFs ran to the paper edge.** Both `print.blade.php` and `quote-pdf.blade.php` declared
`@page { margin: 24px 32px }` **and** `html, body { margin: 0; padding: 0 }`. In dompdf the html/body box
wins over `@page`, so the page had NO margin at all: measured on the deployed PDFs, "Job Card"/"Quote" ended
at x = 595.28pt (the paper edge) and the left text started at x = 0. On top of that the parts table used the
default auto layout, so one long unbroken word (a part number) widened it past the edge and cut the price
columns off ("Excl V…", "R1,43…"). **Fix:** removed the margin/padding reset (the 24px/32px page margins now
apply — verified min x 24.0pt, max x 571.3pt = A4 − 24pt); `_pdf-lines-table` uses `table-layout: fixed` with
column widths (Type 11%, Qty 10%, Unit price 15%, Excl/Line total 14%, VAT 12%; Description takes the rest),
and `p, td, th { word-wrap: break-word }` so a long title/description/part number wraps inside its box.
Long title, long task and line descriptions, long access notes and a 90-character unbroken part reference all
checked.

**Reported, NOT changed (outside this scope):** the same `html, body { margin:0 }` reset sits in
`rental-work-orders/pdf`, `rental-fault-reports/pdf`, `rental-notices/pdf`, `leases/pdf/tenancy-report` (and
the brochure / buyer-pack heads, which set their own `@page` margin 0 on purpose) — those PDFs very likely
also lose their page margins. The schedule boxes are not pre-filled with the saved dates, so setting only one
date blanks the other.

**Tests:** `tests/Feature/RentalJobCards/RentalJobCardScheduleDatesTest.php` (23 cases): both dates saved
and shown, agency-timezone round-trip, seconds / bare date, either date alone, blank clears, 8 bad-input
shapes refused with no change, both errors together, due < scheduled refused (equal fine), typed values
restored, closed card still refuses, list `to` date inclusive of the whole day, junk filter date; the two
PDFs rendered through real dompdf with a long title / long descriptions / long unbroken part reference and
every word's position checked against the A4 margins via `pdftotext -bbox` (fails 0.0 ≥ 23.5 against the old
templates). `RentalJobCardFollowUpsTest`'s three `<th>` assertions relaxed to allow the width attribute.

**Files:** `app/Http/Controllers/CoreX/RentalJobCardController.php` (`schedule`, `index` filter,
`parseDateInput`, `agencyTimezone`), `resources/views/corex/rental-job-cards/{print,quote-pdf,_pdf-lines-table,show}.blade.php`,
the two test files above.

### 14.23 Job card / rental print follow-ups — page margins on every rental PDF, totals box that never splits, pre-filled schedule boxes, quote box on every open card (2026-10-06, Johan: "if they are bugs, fix them")

All four are the follow-ups reported (not changed) at the end of §14.22.

**1. Page margins on the other rental PDFs.** Same root cause as §14.22: dompdf lets an explicit `margin` on
the `html` box win over `@page`, so a template that declares `@page { margin: … }` AND resets the html box
prints with NO page margin. Measured with real dompdf + `pdftotext -bbox` (A4 = 595.28pt wide):

| CSS pattern | min x of text | verdict |
|---|---|---|
| `html, body { margin:0 }` (or `html { margin:0 }`) | 0.0 | **broken** |
| `* { margin:0 }` (the star includes `html`) | 0.0 | **broken** (dompdf only) |
| `body { margin:0 }` only | = the @page margin | fine |
| no reset | = the @page margin | fine |

Every rental PDF checked (all dompdf unless noted): **broken** = `rental-work-orders/pdf`,
`rental-fault-reports/pdf`, `rental-notices/pdf`, `leases/pdf/tenancy-report` (all `html, body {margin:0}`).
**Fine, untouched** = `rental-inspections/report-pdf`, `rental-inventories/report-pdf`,
`rental-signatures/wet-ink-scan-pdf` (body-only reset), the three `rentals/reports/*` (no @page; measured
min x 52pt), `rental-applications/pdf` (no @page), `rental-inspections/form-pdf` (`@page margin:0` on purpose
— a blank scan form with its own layout). `deposit-interest-calculator/pdf` + `pdf-tenant` also carry a
`* { margin:0 }` reset, but those two are rendered by Puppeteer/Chrome (`convertHtmlToPdf`), which applies
@page margins regardless — not this bug, not touched.
**Fix:** the html/body reset is removed from the four templates. Long text: `p, td, th, li, div { word-wrap: break-word }` and, where a
table holds free text, `table-layout: fixed` with the label column kept, so one long unbroken word (a part
number, an e-mail, a pasted URL) wraps inside A4 instead of widening a table past the edge.
The notice PDF's body is agency-written HTML, so it gets the same wrap rules plus `table-layout: fixed` on any
table inside it (measured: an auto-layout table with one long unbroken word ran to x = 595.6pt, a fixed one stays
at 566.6pt; a table that sets its own column widths keeps them, one that sets none now splits equally).

**2. Totals box splitting across pages.** The grand totals ("Subtotal / VAT / Total") sit in one `.box`
`<div>`; with a long card the page break fell inside it (Subtotal+VAT on page 1, Total on page 2).
**Fix:** that box gets `page-break-inside: avoid` (whole box moves to the next page when it does not fit) on the
printed job card and the owner quote; the lines table gets a `<thead>` so its header row **repeats on page 2+**
(dompdf repeats `<thead>` per page) and rows `page-break-inside: avoid` so a line never splits mid-row.

**3. Schedule boxes not pre-filled.** `Set` posts BOTH boxes and blank means "clear", so setting only Due on a
card that already had a Scheduled date silently erased it. **Fix:** both boxes render the saved values
(`RentalJobCard::scheduleInputValue()` → `Y-m-d\TH:i` in the **agency timezone**, the same zone the controller
reads the box in, so Set with nothing touched is an exact no-op round trip); `old()` still wins after a
refused Set so the typed value is not lost. The header line above the boxes is unchanged.

**4. "Send quote to owner" vanishes once the card is Scheduled.** Root cause: `show.blade.php` (quote box,
`#jc-quote-box`) only rendered when `$currentQuote` existed OR `status ∈ {draft, quoted}`; `schedule()` moves a
Draft card straight to Scheduled (and Mark-in-progress to In progress), so a card scheduled before its quote was
ever sent lost the box for good. The service never had a status rule (`sendToOwnerAsQuote()` refuses only a
closed card, an empty card and a card whose property has no landlord) and already keeps Scheduled / In progress
status when it sends. **Rule now:** the quote box (first send and every re-send) is offered on **every open
card — Draft, Quoted, Approved, Scheduled, In progress**; never on **Completed / Cancelled** (locked, §14.21 —
view and service both refuse). Sending from Scheduled / In progress does not move the card's status (work is
already planned); the owner-approval state on the linked work order resets/evaluates exactly as before.

**Tests:** `tests/Feature/RentalJobCards/RentalJobCardPrintFollowUpsTest.php` — margins of the four templates
rendered through real dompdf and every word's x checked against the A4 margins (fails against the old
templates); long unbroken word inside A4; totals box whole on one page and table header repeated on a multi-page
card (page text via `pdftotext -f/-l`); schedule boxes carry the saved values and Set-with-one-box-changed keeps
the other; quote box present on Draft / Quoted / Approved / Scheduled / In progress, absent on Completed /
Cancelled, and a send from Scheduled keeps status Scheduled.

**Files:** `resources/views/corex/{rental-work-orders/pdf,rental-fault-reports/pdf,rental-notices/pdf,leases/pdf/tenancy-report,rental-job-cards/{print,quote-pdf,_pdf-lines-table,show}}.blade.php`,
`app/Models/RentalJobCard.php` (`scheduleInputValue`), the test above.

---

### 14.23 Job Cards LIST — design-standard rebuild (2026-10-06, Johan; supersedes the list parts of §14.8)

`/corex/rental-job-cards` (Rentals → Job Cards). One query object — `App\Services\Rentals\RentalJobCardListQuery`
— builds the list, the status tiles, the scope-switch counts and the Print list. Nothing else builds a job-card
list query, so the numbers on screen, the rows, and the printout can never disagree.

**Scoping (OWN / BRANCH / AGENCY), at the query layer.** The query starts from `RentalJobCard::visibleTo($user,
$requestedScope)` — the global `AgencyScope` (agency isolation) plus `scopeVisibleTo()` (own = cards the user
created, branch = cards on a property in the user's branch, all = the agency), clamped to the user's role ceiling
by `PermissionService::clampScope()`. A requested scope wider than the ceiling silently becomes the ceiling. List,
tiles, scope counts and Print list all start from that one scoped query; every other filter narrows it and can
never widen it. Direct-URL access by id stays guarded by `guardRentalRecordScope()` (unchanged).

**Search (`q`)** — one box, any of: property address, title, tenant name (first, last or full), crew name (plus the
legacy "previously assigned" user on pre-crew cards), job card number (`123`, `#123` or `JC-123` — the number is
the card's id).

**Sort (`sort`, `direction`)** — clickable headers, ▲/▼ on the active one, click again to reverse. Columns:
`property`, `title`, `tenant`, `crew`, `status`, `due_at`, `created_at`, `total` (VAT-inclusive total — the
figure shown). **Default: `due_at` ascending, cards with no due date last** (what is due soonest is looked at
first; a draft with no date never tops the list). Ties break on newest id. Sorting by `total` is done on the
computed inclusive figure (it depends on the agency's VAT registration and each line's VAT type), so that one path
loads the matching set and sorts it in memory — the matching set for one agency's maintenance cards is small and
bounded; every other sort is SQL.

**Filters** — status (via the tiles), Overdue (tile), crew, property, due date From/To, Show archived. Every filter
lives in the URL and survives sort, page and scope changes.

**Status tiles** — Total, Draft, Quoted, Approved, Scheduled, In progress, Overdue, Completed, Cancelled. Each is a
filter link. A tile's number is the row count of the list you land on by clicking it: tiles are computed from the
same scoped query with every filter EXCEPT status/overdue applied (search, crew, property, dates, archived).

**Scope switch** — Own | Branch | All pills, only the ones the role ceiling permits, each showing its own count
under the current non-scope filters. Default is the widest permitted.

**Page size** — 25, standard Laravel pagination; footer shows "from–to of total".

**Columns** — Job card number, Property, Title, Tenant, Crew, Status, Due, Created, Total incl. VAT, Actions
(Open). The number column exists because the number is searchable and a searchable thing must be visible.

**Quote indicator** — in the Status cell, ONLY where it applies: "Rev N" when the quote currently out with the owner
is revision 2 or later, and an amber "Changed since sent" when the card's content no longer matches the sent quote
(`quoteChangedSinceSent()`). Cards with no sent quote, or an unchanged one, show nothing extra.

**Empty state** — no job cards at all: "No job cards" + the New Job Card button (when permitted). Filters hide
everything: "No job cards match" + Clear filters. No helper copy anywhere on the screen.

**Layout** — the header, tiles and filter bar are fixed; the table scrolls inside its own panel with a sticky
header; the pagination footer is fixed below it. No whole-page scroll.

**Print list** — same `RentalJobCardListQuery` with the request's own filters/sort/scope, all matching rows (no
paging), same columns including Created and Total incl. VAT.

**Files** — `RentalJobCardListQuery` (new), `RentalJobCardController::index()`/`printList()`, `rental-job-cards/
index.blade.php`, `print-list.blade.php`, `tests/Feature/RentalJobCards/RentalJobCardListScreenTest.php`.
No migration, no new permission, no new setting (nothing to surface in the Setup Wizard).

## 15. Inspection Follow-up (AT-447, built 2026-10-05) — the marked-item-to-record bridge

**Johan's requirement, verbatim (via the conductor's investigation brief):** "at the end of an
inspection (in, routine, out), where items/rooms were marked as faulty or damaged, the agent must be
able to create fault reports, work orders or job cards straight from those marked items, linked back
to the inspection, the lease and the property." A prior read-only investigation
(`/tmp/rentals-inspection-followup-investigation-2026-10-05.md`) found the schema ALREADY carried the
bridge FKs (`rental_fault_reports.rental_inspection_item_id` /
`.reported_inspection_observation_id`, `rental_work_orders.rental_inspection_item_id` /
`.reported_inspection_observation_id`, and `RentalWorkOrder::REPORTED_BY_INSPECTION` — all present
since Stages 1/4 of this same spec) but no UI anywhere ever set them — this section is the wiring,
not a new data model.

### 15.1 The Follow-up block

`resources/views/corex/rental-inspections/show.blade.php`, rendered on every inspection regardless of
status (a draft being finished can raise follow-up just as well as a completed one) — one row per
observation whose condition is flagged `needs_follow_up` by the agency's OWN existing condition
configuration. **Corrected 2026-10-05** (Johan, QA1 property walk): the first build of this block used
"not the agency's configured baseline" (`baselineConditionKeyFor()`), which wrongly listed N/A and a
stray unmapped condition value ("OK CC1", a value that reached the column through a path bypassing
the agency's own vocabulary, e.g. an OMR scan import) as if they were faults — N/A is explicitly not a
fault, and an unmapped value is not a configured anything. The filter now reuses the EXISTING
condition-severity configuration this codebase already has, deliberately **not** a new flag/column and
**not** a new table (per Johan's own instruction, and per this class's own documented Architectural
Law against a second, independently-configurable "does this need doing" flag —
`RentalInspectionSetting::SEVERITY_COLORS`' own docblock: `needs_attention` was already merged into
`severity` once for exactly this reason). `RentalInspectionSetting::conditionNeedsFollowUpFor($agencyId,
$key)` — new, thin wrapper — asks the identical question the recording screen's own "Needs attention"
filter already asks (`conditionNeedsAttentionFor()`: red/amber = yes), with ONE deliberate difference:
an unmapped/legacy condition key defaults to **false** here, never `conditionSeverityFor()`'s `red`
"flag it, don't hide it" fallback — that fallback is right for a live recording screen alerting an
agent to something unclassified; it is wrong for a list of actions nobody asked the system to propose.
No new settings-page control was added either — an agency that wants a condition to show up in
Follow-up sets its existing Colour to Issue (red) or Caution (amber) on the already-shipped Condition
states settings screen; no second UI to keep in sync.

The observation's existing photo-anchor exclusion, `RentalInspectionObservation::isPending()`, still
applies, so a bare photo with no condition recorded yet never appears here. The header reads
`"Follow-up (N)"`; the block renders **not at all** when nothing on the inspection needs follow-up
(not an empty-state message — Johan's own instruction). Each row shows room/item/condition/note/
photo-count, a checkbox, and either a create action or — idempotent, per Johan's own requirement — the
existing linked record(s) with status, if one has already been raised from that observation. A
"Select all" checkbox above the list checks every row's checkbox (plain `onclick`, no Alpine) — the
three shared-bar actions already operated correctly on however many items were ticked; this just makes
ticking all of them a one-click action.

**Flagged, not silently resolved:** Johan's 2026-10-05 message proposed illustrative defaults
("damaged / poor / fair / missing / broken = yes; good / ok / new / n-a / not applicable = no") that
would flip `fair`'s shipped severity from `blue` to `amber`/`red`. This spec does **not** make that
change — `fair`'s `blue` severity is an earlier, explicit, already-relied-upon ruling (property 5792:
"the conditions that assert something adverse — Damaged, Not working, Missing, Other — require a
note; Good, Fair and N/A do not"), and flipping it would change the recording screen's own "Needs
attention" filter and per-room issue count for every agency on the shipped default, not just this
block. If Johan wants `fair` to read as needing follow-up, that is a one-field change on the existing
Condition states settings screen (flip its Colour to Amber) he can make himself, or a one-line default
change this spec will make on his explicit confirmation — not assumed here.

### 15.2 Three actions, one shared resolution service

`App\Services\Rentals\RentalInspectionFollowUpService` is the one place the resolution/derivation
logic lives; every controller it serves is a thin caller, same discipline as every other service in
this family:

- **`resolveLeaseAndProperty()`** — lease first (the inspection's own `lease_id`, required per
  `rentals-rebuild.md` §0's dependency note), property derived from it; falls back to the
  inspection's own denormalized `property_id` only if the lease can't resolve one. If the two ever
  disagree (should never happen — `property_id` is denormalized FROM the lease at creation,
  `RentalInspection::boot()`), the lease wins — absorbed, never thrown (BUILD_STANDARD §3), matching
  the identical lease-first/absorb discipline `RentalInspectionController::create()`'s own
  lease_id/property_id pre-select already established (AT-439 Part 3).
- **Title** — `"<Room> — <Item>: <Condition>"` (room falls back to `'General'` when the item has no
  room set). **Description** — the observation's own note, falling back to the same title string
  when the note is empty (`description` is NOT NULL on both target tables — BUILD_STANDARD §2).
- **"Create fault report"** — direct, immediate server-side creation (`POST .../follow-up/fault-
  reports`, `RentalInspectionController::storeFollowUpFaultReports()`). No intermediate form: unlike
  a work order, a fault report needs no further human decision before it can exist.
  `reported_by_type` defaults to `tenant` on an in/ad-hoc inspection, `agent_noticed` on an out
  (Johan's own instruction: label the reported-by default as owner/agent, not tenant, on an out
  inspection — a tenant does not usually self-report the damage found at their own move-out).
  `reported_by_contact_id` resolves from the observation's own `observed_by_contact_id` when set,
  else the lease's primary tenant. `reported_channel = 'in_person'` (the agent was physically there).
  Idempotent: an observation that already has a fault report is silently skipped (never duplicated),
  with the skip count folded into the flash message.
- **"Create work order" / "Create job card (our team)"** — BOTH redirect (GET) to the existing
  `corex.rental-work-orders.create` form — "opens the existing work-order create with its 'Who does
  the work?' choice," per Johan's own framing, not a second creation path. The shortcut only
  pre-selects that radio to `internal`; the agent can still change it. `reported_by_type` is forced
  to the existing-but-previously-unused `REPORTED_BY_INSPECTION` value (§3.2's own documented, never-
  wired-up meaning: "the fault was raised as an observation during an in-inspection, out-inspection…
  `reported_inspection_observation_id` links directly to that observation").

### 15.3 One record per ticked item by default, "combine" for several

Per-row mini-actions are always single-item (no ticking required). A shared checkbox + "Combine
ticked items into one" control sits below the list, feeding all three action buttons:

- **Combine OFF (default), several ticked** — one record PER ticked item. For fault reports this
  loops `RentalFaultReportService::report()` once per observation, in the SAME request (no
  intermediate form, since none is needed). For work orders/job cards, the create screen switches to
  a **batch** mode: the item list (titles already derived, nothing left to type per item) plus only
  the fields a human must still decide ONCE for the whole batch — who does the work, trade type —
  and `RentalWorkOrderController::store()`'s new `storeBatch()` path loops the SAME
  `RentalWorkOrderService::report()` / `RentalJobCardService::createForProperty()` calls `store()`'s
  single-item path already used, one call per item.
- **Combine ON, several ticked** — exactly one record, whichever observation sorts lowest-id as the
  primary FK (`rental_inspection_item_id`/`reported_inspection_observation_id`), title
  `"Multiple items (N) — <address>"`, description a bulleted per-item summary. A genuine
  many-to-many "one record covering several items" join table was considered and rejected as
  disproportionate to this request — the single-primary-FK-plus-bulleted-description shape is the
  smallest design that satisfies "combine into one" without a new table.
- **Idempotency under a race/resubmit** — both the direct fault-report path and the batch work-order
  path re-check "does this observation already have one" at submit time, not just at render time;
  an item raised between the form rendering and the batch submitting is skipped, not duplicated.

### 15.4 Photos — linked, not re-uploaded

An observation's existing `rental_inspection_photos` rows are linked onto the new fault report by
creating a new `rental_fault_report_photos` row with the SAME `storage_path` — a second database
reference to the same physical file, zero disk-duplication cost, and the photo genuinely is the same
piece of evidence, now filed against two records. (Work orders/job cards raised from the Follow-up
block do not carry photos across automatically — an agent uploads a fresh "reported" photo on the
work order itself if wanted; the fault-report path is the one Johan's own wording ("carrying photos +
notes across") most directly describes, and is the one built.)

### 15.5 Back-links and the tenancy log

Every fault report / work order / job card raised this way shows "From inspection `<type>`
`<date>`" on its own show page, linking back to the inspection (`reportedInspectionObservation.
inspection`, reached through the work order for a job card — a job card carries no inspection FK of
its own, only its 1:1 `rental_work_order_id`, per §14's existing design). The Lease Hub tenancy log
(`LeaseTimelineService::faultEntries()`/`workOrderEntries()`) needed **no new code at all** — it
already computes its rows live from `lease->faultReports()`/`lease->workOrders()`, so any record
created here that carries a `lease_id` (resolved per §15.2's lease-first rule) appears there
automatically, the same as one raised directly from the property or lease screen.

### 15.6 No clash with AT-442 (Job Cards) or AT-445 (Portal)

A job card raised via the "Create job card" shortcut is still created by the SAME
`RentalJobCardService::createForProperty()` AT-442 built — 1:1 with its own work order, same
lifecycle, same settings. The only change to that service is one new passthrough field
(`reported_inspection_observation_id`, §15.5's own back-link need) — nothing about job-card creation,
sign-off, or quoting changed. The portal's tenant fault-report flow
(`ClientTenantRentalsController::faultReportStore`) and this inspection follow-up flow both ultimately
call the same `RentalFaultReportService::report()` — two callers of one existing service, the exact
pattern §3.2a already designed for ("a future mobile/app endpoint can call the identical method").

### 15.7 Permissions, scoping, files

No new permission keys — reuses `rental_fault_reports.create` (the follow-up fault-report route) and
`rental_work_orders.create` (the existing create/store routes, unchanged gate). The follow-up route
also re-checks `AuthorizesRentalRecordScope::guardRentalRecordScope()` against the INSPECTION itself
(own/branch/all, same as every other action on `RentalInspectionController`) before touching any of
its observations — a user who cannot open this inspection cannot raise a fault report from it either,
direct-POST-by-id included. Cross-agency inspection ids are absorbed by the existing `AgencyScope`
route-model-binding 404, never a raw exception.

**Files:** `app/Services/Rentals/RentalInspectionFollowUpService.php` (new) ·
`app/Http/Controllers/CoreX/RentalInspectionController.php` (show() data, new
`storeFollowUpFaultReports()`) · `app/Http/Controllers/CoreX/RentalWorkOrderController.php`
(create()/store() batch+prefill) · `app/Http/Controllers/CoreX/RentalFaultReportController.php` /
`RentalJobCardController.php` (back-link eager-loads) ·
`app/Services/Rentals/RentalJobCardService.php` (one passthrough field) ·
`resources/views/corex/rental-inspections/show.blade.php` (the Follow-up block) ·
`resources/views/corex/rental-work-orders/create.blade.php` (single/batch prefill) ·
`resources/views/corex/{rental-fault-reports,rental-work-orders,rental-job-cards}/show.blade.php`
(back-links) · `routes/web.php` (one new route) ·
`tests/Feature/RentalInspections/RentalInspectionFollowUpTest.php` (new).

---

## AT-439 (Rentals rebuild 1/7, "Foundation") — Own/Branch/All scope, Fault Reports + Work Orders, 2026-10-04

Built strictly from `/tmp/rentals-stage1-investigation.md` item C. `RentalFaultReportController::
index()` and `RentalWorkOrderController::index()` already called `->visibleTo($user, $request->get
('scope'))` (own/branch/all query-layer scoping existed on both); neither had a UI control to let a
user with a wider ceiling choose a narrower/wider view, and — more seriously — neither `show()`/
`pdf()` (nor any other non-index action taking the bound record) independently re-checked that
scope, so a user whose list was scoped to `own`/`branch` could still open or mutate ANY fault
report/work order in the agency by direct URL/ID. (The `pdf()` docblock on both controllers had
already, honestly, documented this as "same query-layer scoping as show() above" — a known, not
hidden, gap.)

**Fixed as a class** (see `leases.md` §12 for the full rationale — this is the same fix, same new
trait, applied here too): `App\Http\Controllers\Concerns\AuthorizesRentalRecordScope::
guardRentalRecordScope()` is now called at the top of every non-index action on both controllers
that receives a bound record —

- `RentalFaultReportController`: `show`, `pdf`, `update`, `requestApproval`, `recordApproval`,
  `setOutcome`, `raiseWorkOrder`, `cancel`, `destroy`, `restore`, `storePhoto` (11 routes).
- `RentalWorkOrderController`: `show`, `pdf`, `update`, `assignSupplier`, `recordApproval`,
  `startProgress`, `complete`, `addNote`, `cancel`, `destroy`, `restore`, `storePhoto` (12 routes).

For both models the guard's "branch" check resolves via the record's PROPERTY's `branch_id`
(`$record->property?->branch_id`) — matching exactly what `RentalFaultReport::scopeVisibleTo()`/
`RentalWorkOrder::scopeVisibleTo()` already check (`whereHas('property', ...)`), NOT either model's
own `branch_id` column. **Note for a future pass, reported not fixed here (out of scope for AT-439
Part 1):** both `rental_fault_reports` and `rental_work_orders` carry their OWN `branch_id` column,
which their own `scopeVisibleTo()` never reads — an existing inconsistency, not something this build
introduced or corrected.

The out-of-scope sibling controllers that also receive these same bound records — `RentalWorkOrderQuoteController`
(quotes CRUD/select/download), `RentalInspectionRecordingController`/`RentalInspectionComparisonController`/
`RentalInspectionScanController`/`RentalInspectionPhotoNoteController` (see `rental-inspections.md`'s
own AT-439 addendum) — were NOT touched; they carry the identical gap and are reported, not fixed,
per this build's explicit scope lock.

**UI**: the same "Showing: Own | Branch | All" pill control `rental-applications`/`leases` already
use now renders on `corex/rental-fault-reports/index.blade.php` and
`corex/rental-work-orders/index.blade.php`.

### Files changed (AT-439)

- `app/Http/Controllers/CoreX/RentalFaultReportController.php` — scope control + 11 guarded routes
- `resources/views/corex/rental-fault-reports/index.blade.php` — "Showing:" control
- `app/Http/Controllers/CoreX/RentalWorkOrderController.php` — scope control + 12 guarded routes
- `resources/views/corex/rental-work-orders/index.blade.php` — "Showing:" control

---

## 16. Rental Crews (built 2026-10-05) — agents/staff are never maintenance crew

**Johan's ruling, verbatim:** *"Agents and staff are never maintenance crew. The crew dropdown on
job cards must NOT list CoreX users. Crew are people with NO CoreX access, set up by the agency
admin, and pickable on job cards. A crew can be several people or just a named team ('Team 1') —
the admin decides. Reporting on which crew did what comes later; build so that is possible, but do
not build reports now."*

**Investigation finding, before this build:** §14's "Crew & schedule" block (`RentalJobCard
::assignCrew()`, `rental_job_cards.assigned_user_id`) listed every `User` in the agency —
agents and admin staff included — in the job card's Assign dropdown. Read at: the show screen's
Assign form, the list screen's filter/search/column, `print.blade.php`/`print-list.blade.php`,
the embedded job-card summary on `rental-work-orders/show.blade.php`, the mobile API payload, and
`RentalReportService`'s existing crew-productivity report fields. Worker sign-off
(`RentalJobCard::workerSignOff()`) already only ever recorded the AUTHENTICATED (CoreX) user who
clicked the button — never literally "the crew member logs in and signs off" — so no change was
needed there beyond capturing which crew member the agent is confirming did the work.

### 16.1 Data model

```
rental_crews            -- agency-scoped, soft-delete only
  id, agency_id, name, notes, is_active, created_by_user_id, timestamps, deleted_at
  -- "unique per agency among ACTIVE" (Johan) is application-layer only
  -- (RentalCrewController::validated(), Rule::unique()->whereNull('deleted_at'))
  -- — a DB-level composite unique(agency_id,name) would also block
  -- reusing an ARCHIVED crew's name, which Johan's wording allows.

rental_crew_members      -- agency-scoped, soft-delete only
  id, agency_id, rental_crew_id, name, phone, role, created_by_user_id, timestamps, deleted_at
  -- role is free text (e.g. "Plumber") — not a catalogue vocabulary.
  -- A crew with zero members is valid (a plain named team).

rental_job_cards
  + rental_crew_id        -- nullable FK rental_crews, nullOnDelete. The ONLY thing
                           --   RentalJobCard::assignCrew() writes from 2026-10-05 on.
  + worker_sign_off_name   -- nullable string(191) — "record the signing-off name/crew
                           --   member as text/selection" (Johan). Free text; the show
                           --   screen offers the assigned crew's own member names via a
                           --   native <datalist> as a convenience, not a constraint.
  assigned_user_id         -- UNCHANGED, FROZEN. Never dropped, never written to again
                           --   by any new code — same decoupling precedent as
                           --   rental_work_order_id in §14's own 2026-10-05 rebuild.
                           --   Read-only, for "Previously assigned: <name>" on any card
                           --   that predates crews. No migration invents crews from it.
```

### 16.2 Where it's read, and how the legacy column displays

Every screen that showed `assignedUser?->name` now shows, in order: the assigned `RentalCrew`'s
name (with its members listed alongside, where the layout allows) if `rental_crew_id` is set;
else, if the legacy `assigned_user_id` is set, "Previously assigned: &lt;name&gt;" — read-only,
never re-selectable, never touched by `assignCrew()` again; else "—". Updated: job card show/
index/print/print-list, the work-order's own embedded job-card summary, the mobile API payload
(`MobileRentalJobCardController::payload()` — `crew` key added alongside the now-legacy-only
`assigned_user`).

An **archived** crew still displays wherever it's already assigned (`RentalJobCard::crew()` is
`withTrashed()`) but cannot be newly picked — the Assign dropdown and its own server-side
validation both query `is_active=true AND deleted_at IS NULL` (a real bug caught and fixed during
this build: `Rule::exists()` queries the table directly, not through Eloquent, so SoftDeletes
scoping is never automatic — an archived crew has `is_active` still `true`, only `deleted_at` set,
so `whereNull('deleted_at')` had to be explicit).

**Reported, not fixed (explicitly out of scope — "reporting comes later"):**
`RentalReportService`'s existing crew-productivity report (`'crew' => fn ($c) =>
$c->assignedUser?->name`, filters on `assigned_user_id`) still reads the legacy column only — it
was not rewired to `rental_crew_id` in this build. It will keep reporting correctly against
pre-crew historical data but will show nothing for any job card assigned a crew from 2026-10-05
onward, until that report is rebuilt against the new model.

### 16.3 Screens

- **`corex.rental-crews.*`** — full CRUD (index/create/edit, archive/restore), search (name), sort
  (name default, created_at), filter (active/archived), pagination, real empty state, agency
  scoping at the query layer. Members managed inline on the crew's own edit screen (add/archive/
  restore), same "parent owns its children" pattern job card tasks/lines already use.
- **Sidebar**: "Rental Crews", directly under "Parts & Labour Catalogue" (Rentals menu).
- **Company Settings**: a "Rental Crews" panel next to Catalogue Item Types/Catalogue Units, linking
  out to the full screen above (not an inline editor — crews can grow to many rows, unlike the
  small catalogue vocabulary lists Company Settings manages inline).
- **Permission**: `rental_catalogue.view` / `rental_catalogue.manage` — same keys as managing the
  catalogue (Johan's own instruction) — no new permission key introduced.

### 16.4 Worker sign-off

`RentalJobCard::workerSignOff(User $by, ?string $workerName = null)` — `$by` is still always the
AGENT recording the sign-off (a crew member has no CoreX login to click anything themselves,
unchanged from before this build); `$workerName` is who on the crew actually did the work,
optional free text, offered via the assigned crew's own member names as `<datalist>` suggestions.
Both the CoreX user who recorded it (`worker_signed_off_by_user_id`) and the named crew member
(`worker_sign_off_name`) are kept — different facts, both evidence.

### 16.5 Tests

`tests/Feature/RentalCrews/RentalCrewTest.php` — full CRUD, members CRUD, agency isolation,
unique-among-active (not among archived), no hard deletes.
`tests/Feature/RentalCrews/RentalJobCardCrewAssignmentTest.php` — assigning sets `rental_crew_id`
never `assigned_user_id`; the Assign dropdown lists crews only, never users (scoped assertion —
the shared layout's own unrelated "Switch User" admin widget legitimately lists every agency user
elsewhere on the page, so a whole-page text search would false-fail); an archived crew cannot be
newly picked but still displays where already assigned; cross-agency crew rejected; a legacy
`assigned_user_id` card shows "Previously assigned" and is never touched again once a crew is
later assigned; worker sign-off records an optional crew member name.
