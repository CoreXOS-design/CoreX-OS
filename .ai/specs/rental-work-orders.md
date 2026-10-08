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

**Amendment, 2026-10-06 (cc6) — the maintenance flow, end to end (§17, FINAL, spec only).** Johan's rulings of 6 Oct on cost vs selling, owner work terms, variations, emergency approval, external contractors and tenant dispute are specified in §17 and split into a shared foundation plus three parallel builds (§17.21). §17.0 lists exactly which earlier statements it supersedes (notably §14.21's re-send-after-approval, the inherited approval on fault-raised work orders, the crew/printed price settings, and the job-card-keyed client views).

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

### 14.24 Job card lines — one column grid for header, saved rows and the add row; compact end-of-row controls (2026-10-06, Johan; layout only, no behaviour change)

**What Johan saw (card /corex/rental-job-cards/1):** under a task, the Item / Description / Type / Unit / Qty / Unit
price / VAT of the saved lines did not read as one table with the add-line inputs below them, and the × at the end of a
saved line was "a large dark block".

**Root causes (measured in a real browser, 1366×768, column x-positions of the three rows):**
1. The three rows already share one set of tracks (`App\Support\RentalJobCardLineGrid::columns()`; every track starts
   at the same x: 343 / 433 / 509 / 605 / 675 / 731 / 821 / 911). What did not line up was the TEXT inside the tracks:
   the inputs/selects carry `px-2` + a 1px border, so what an agent types starts 9px into its track, while the header
   labels and the saved values were flush at the track edge — every label sat 9px left of the box below it.
2. The × (and the + in a form-mode add row) is a `<button type="submit">` inside `.hfc-card`, and corex.css paints every
   such button with `!important` background (solid blue/navy), `padding: 10px 18px`, radius 10px — measured 44×36px in a
   36px track (spilling out of the column and making the row 36px tall against 30px inputs). The pencil is a
   `type="button"`, so it escaped the rule and stayed a plain glyph — hence the mismatch.

**The column grid (the one source of truth, unchanged):** Item `minmax(0,100px)` · Description `minmax(70px,1fr)` ·
Type `90px` · [prices on: Unit `64px` · Qty `50px` · Unit price `84px` · [VAT-registered: VAT `84px`]] · Action `36px`;
`gap: 6px`. Header row, saved rows, the add row and an open line editor all use `gridStyle()`.

**Fix:**
- `RentalJobCardLineGrid::cellStyle()` = `padding-left: 9px` (8px input padding + 1px border) applied to every header
  label and every saved value, so label ↔ saved value ↔ input text start on the same x in each column.
- `RentalJobCardLineGrid::iconButtonStyle()` — ONE compact 17×17px icon control, every declaration `!important` so it
  beats the card-wide submit rule: edit ✎ and archive × are plain glyphs (transparent, no border), the add + is the same
  size as a small outlined square, right-aligned (`justify-self:end`) so the saved rows and the add row end in the same
  narrow action column. Row height is back to the text height. The add-line row stays on ONE line (5 Oct ruling), the two
  panels still scroll independently, and no behaviour changes (same forms, same confirm on archive, same routes).
- Not touched: the create (draft) screen's pre-save lines list; the "Remove" control there is a different component.

**On-screen VAT column showed "—" on some lines (cc4 finding, added to this section the same day).** Cause:
`RentalJobCardVatService::breakdown()` writes the `vat_display_*` attributes onto `$jobCard->lines`, but the saved-lines
partial renders `$task->lines` and the separately loaded General lines — different model instances — so those
attributes were always empty and the column fell back to the line's OWN VAT type, which a free-text line, or a line picked
from a catalogue item with no default VAT type, simply does not have. (The printouts were fixed for the same reason in
§14.21 and are untouched.) **Fix:** the column shows the line's EFFECTIVE VAT type — Standard / None / Custom, the add-line
select's own wording (`RentalVatType::shortenName()`, shared with `shortLabel()` so the two cannot drift) — resolved through
the VAT service and keyed by line id: `breakdown()['lineFigures'][$line->id]['type_label']` (the cell shows the label only —
the 84px column cannot hold "Standard (15%)" at 1366 wide — and the tooltip carries the rate, e.g. "Standard (15%)", "Custom (7.5%)"); a line with no VAT type is charged 0% = **None**; a line with no price yet (no
lineFigures entry) falls back to `RentalJobCardVatService::effectiveTypeLabel($line)`; a frozen (quoted) line words the type
name it was issued with the same way. Never a dash.

**Tests:** `tests/Feature/RentalJobCards/RentalJobCardGridAlignmentTest.php` — header, saved row and add row render from
the same grid style string, saved cells and header labels carry the 9px inset, and the ✎ / × / + carry the compact icon
style with `!important` (no `corex-btn` class on the +); VAT column: a free-text line with no VAT type (in a task and in General), a line with no price, Standard/Custom wording + rate, a frozen quote's lines, the shared wording, and the printouts unchanged. Real-browser proof on QA1 card 1: column x per row + action
control sizes + screenshot (`/tmp/qa1-cc3-jobcard-align.png`).

**Files:** `app/Support/RentalJobCardLineGrid.php`, `app/Services/Rentals/RentalJobCardVatService.php` (`type_label`, `effectiveTypeLabel`), `app/Models/RentalVatType.php` (`shortenName`), `resources/views/corex/rental-job-cards/{_line-columns-header,_lines-table,_add-line-row}.blade.php`.

(Numbering resolved 6 Oct: the Job Cards LIST that also carried "§14.23" is now §14.26; §14.25 is parked — see below.)

### 14.25 (number not used as a section)

Code comments written on 6 Oct (`RentalVatType::shortenName()`, `RentalJobCardVatService::effectiveTypeLabel()`, `_lines-table.blade.php`) cite "§14.25" for the on-screen VAT-column fix; that text is in **§14.24** above. The number is parked here so it is not re-used for something else.

### 14.26 Job Cards LIST — design-standard rebuild (2026-10-06, Johan; supersedes the list parts of §14.8)

> **Renumbered 6 Oct** from a second "§14.23" (the print follow-ups above hold §14.23). Code and tests written for the LIST cite "§14.23" — `RentalJobCardListQuery.php`, `resources/views/corex/rental-job-cards/index.blade.php`, `RentalJobCardListQueryTest.php` and `RentalJobCardController::index()` — and mean THIS section. Every other "§14.23" in code (the rental PDF templates, `RentalJobCard::scheduleInputValue()`, `show.blade.php` quote box, `RentalJobCardPrintFollowUpsTest`) means the print follow-ups.

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

### 14.27 Crew links, crew completion and job-card visibility for tenants and landlords — FINAL (conductor's decisions on Johan's requirements, 6 Oct 2026; spec only — no app code yet)

**Johan's requirements (6 Oct), restated:**
- (a) Once a job card exists it can already be downloaded and printed; he also wants a **share link** for it.
- (b) **Crews get an email address and a contact number** (Rental Crews set-up), used to share the job card with the crew.
  Crew are NOT CoreX users and get no login (ruling 5 Oct). Access = a **per-job secure link**, the same doctrine as
  contractors (ruling 29 Sep, rental-portal-access.md §4).
- (c) **Completion, two routes:** (1) the crew signs on the link that the work is completed; (2) wet ink — the crew brings the
  signed job card back to the office and the signed copy is **uploaded** against the job card as the confirmation.
- (d) **Crew photos:** from the link, on their phone, the crew take pictures of the work and upload them; the photos attach to
  the job card and **flow back so the tenant and the landlord can see the work is completed** (tenancy log / lease hub /
  tenant and owner portal views of the fault and work order).
- (e) **Crew general page:** "same like sharing the jobcard" — a simple screen per crew showing the job cards assigned to that
  crew, from which they open the cards, **prep stock** and know their schedule.

**CoreX doctrine applied: build the options, the agency sets it up.** Every behaviour Johan could reasonably want
different for another agency is an **agency setting with a stated default** (§14.27.3), per agency, on the Rental Portal
settings screen next to the existing contractor-link settings, and surfaced in the Setup Wizard (non-negotiable #10a).
Nothing here assumes one agency: wording is neutral, the agency name/logo come from the agency record.

#### 14.27.1 Decisions (the 18 open questions, closed)
All 18: **option (a), as recommended**, with the refinements below. (Numbers match the question list the lane produced.)
1. **One per-job link** serves both "share the card" and "crew acts on it" (no separate view-only link).
2. The link may be emailed to the **crew's address or any address the agent types**.
3. Crew sees the **tenant's name + phone: agency setting `crew_link_show_tenant_contact`, default OFF** (access notes only).
4. The crew **can tick tasks** on the link.
5. Crew "completed" **never closes the card.** The existing **Agent sign-off stays the close** (`complete()` unchanged: needs the
   worker sign-off AND the agent sign-off). **Wet-ink route:** the office uploads the signed job card (pdf / jpg / png,
   **10240 KB** max, an earlier upload is kept and marked **Superseded**, **no hard delete**), which records the crew completion
   **the same way** the link does (§14.27.5).
6. Crew signs with **typed full name + a tick**, stored with **timestamp, IP address and device** (user-agent).
7. Which crew photos the tenant/landlord see: **agency setting `crew_photos_visible_to_clients`, default "all in-progress +
   completed"** (alternative value: completed only).
8. **Per-job link validity: agency setting `crew_job_link_expiry_days`, default 14.** The link **always dies** when the card is
   Completed (i.e. after the agent sign-off), Cancelled or archived — whatever the expiry says.
9. **Prices on crew links: agency setting `crew_link_show_prices`, default OFF.**
10. A **QR code on the printed job card** that opens the link — only when the agent chooses **"Print with link"** (a printed QR
    is a live credential on paper, so it is minted on demand, replaces any earlier link, and is never in the normal print).
11. **Landlord email when the crew marks completed: agency setting `notify_landlord_on_crew_completion`, default ON.** The mail
    goes through **the agency mailbox path** (the sending agent's own communication mailbox, SMTP + Sent-folder append, same as
    every `fromAgent()` send site — `BaseSignatureMail::fromAgent()` + `PerMailboxMailTransportBuilder` +
    `ImapSentFolderAppender`, dispatched as `ComplianceMailDispatcher` does), **never a plain `Mail::to()->send(Mailable)`**.
    The same path carries the crew-link emails (§14.28, §14.29). Sending agent = the user who pressed the button; for the
    automatic landlord mail, the property's responsible agent (fallback: the card's creator; fallback: the shared CoreX mailer,
    exactly as AT-395 already falls back). On QA1 the outbound mail guard catches everything; verification uses
    `@example.invalid` addresses only.
12. **Crew page link stands until revoked**, with an **optional agency-set expiry: `crew_standing_link_expiry_days`**
    (blank = no expiry).
13. **"Recently completed" window on the crew page: `crew_page_recent_completed_days`, default 7** (0 = list hidden).
14. **"Upcoming" window on the crew page: `crew_page_upcoming_days`, default 14.**
15. The materials list covers **today + upcoming** cards only (not unscheduled).
16. **Draft cards do not appear** on the crew page — only booked work: Approved, Scheduled, In progress (§14.27.5).
17. The materials list says **"what to load" only** — CoreX rentals holds no stock-on-hand data, so it never says "short of".
18. **One link per crew** (shared by its members); actions are logged "via crew page/link — {crew}" plus the typed name on a sign-off.

#### 14.27.2 Terms
- **Per-job crew link** = a secure link scoped to ONE job card (Build 1). **Crew page link** = ONE standing link per crew that
  lists that crew's open cards (Build 2). Both are "crew links": no CoreX user, no password, SHA-256-hashed token, same
  "unavailable" page for expired / revoked / unknown / closed.
- **Crew completion** = the worker sign-off on a job card recorded by the crew (link) or on their behalf (signed copy); it is
  NOT card completion.

#### 14.27.3 Agency settings (per agency; defaults stated; all in `rental_portal_settings` / `RentalPortalSetting`)
Shown on **Settings → Rental Portal** (`corex/settings/rental-portal.blade.php`) in a new **"Crew links"** section under the
existing "Access" block, and in the **Setup Wizard** (`config/agency-onboarding-copy.php`, `source => 'rental_portal'`, each with
`explain` + `affects`; savers guard every boolean write with `$request->has()` — onboarding spec §6.1). No setting is
withheld from the wizard.

| Key | Type | Default | Range | Used by | Owner |
|---|---|---|---|---|---|
| `crew_links_enabled` | toggle | **on** | — | master switch: off = every crew link (per-job and crew page) is "unavailable" at once | Build 1 |
| `crew_job_link_expiry_days` | number | **14** | 1–90 | per-job link validity (Q8) | Build 1 |
| `crew_link_show_prices` | toggle | **off** | — | prices on per-job view AND crew page (Q9) | Build 1 |
| `crew_link_show_tenant_contact` | toggle | **off** | — | tenant name + phone on the crew views (Q3) | Build 1 |
| `notify_landlord_on_crew_completion` | toggle | **on** | — | landlord email when crew marks completed (Q11) | Build 1 |
| `crew_photos_visible_to_clients` | select | **`in_progress_and_completed`** | `in_progress_and_completed` \| `completed_only` | which crew photos tenant/landlord see (Q7) | Build 2 |
| `crew_standing_link_expiry_days` | number, nullable | **blank = until revoked** | 1–365 | crew page link (Q12) | Build 2 |
| `crew_page_recent_completed_days` | number | **7** | 0–30 (0 hides) | crew page "recently completed" (Q13) | Build 2 |
| `crew_page_upcoming_days` | number | **14** | 1–60 | crew page "upcoming" window (Q14) | Build 2 |

Accessors follow the existing `RentalPortalSetting::…For(?int $agencyId)` pattern (default when no row). Existing
`contractor_links_enabled` / `contractor_secure_link_expiry_days` are unchanged.

#### 14.27.4 What already exists and is reused (verified 6 Oct on origin/QA1)

| Need | Exists | Where | Reuse |
|---|---|---|---|
| Per-job no-login secure link | Token model, SHA-256 hash only, `expires_at`, `revoked_at`, `last_used_at`, `isLive()` | `app/Models/RentalSecureAccessToken.php:15-79` (`isLive` :49) | Extend the same table/model (§14.28.2) |
| Mint / revoke | `issueFor()` (revokes previous, one live link per target, expiry from agency setting), `revokeAllFor()`, `revoke()` | `app/Services/Rentals/RentalSecureAccessTokenService.php:18-49` | Generalise (§14.27.6) |
| **No UI mints a link today** | `issueFor()` is called only from `tests/Feature/RentalPortalAccess/ContractorSecureLinkTest.php` — there is **no "generate contractor link" button or controller** | grep of `app/ routes/ resources/` | Build 1's panel is the first office-side issue/revoke UI; the contractor side can adopt the same service later (not in scope) |
| Public routes, no session, throttled | `secure/work-orders/{token}` GET + `quote` / `photo` / `mark-done` POST, `throttle:30,1`, same "unavailable" page | `routes/web.php:58-62`; `app/Http/Controllers/ContractorSecureLinkController.php:29-124` (`resolveToken` :29, `storePhoto` :87, `markDone` :105) | New sibling controllers, same shape |
| Phone page | `<input type="file" accept="image/*" capture="environment">`, viewport meta | `resources/views/rentals/secure-link/show.blade.php:13,88-90` | Copy the layout |
| Agency settings + wizard | `contractor_links_enabled`, `contractor_secure_link_expiry_days` | `app/Models/RentalPortalSetting.php:22-23,66-74`; screen `resources/views/corex/settings/rental-portal.blade.php:41-81`; controller `RentalPortalSettingsController.php:45-68`; wizard `config/agency-onboarding-copy.php:579-583` | Add the §14.27.3 rows beside them |
| "Worker — done" | `workerSignOff(User $by, ?string $name)` → `worker_signed_off_at`, `worker_signed_off_by_user_id`, `worker_sign_off_name`; logs `sign_off` | `app/Models/RentalJobCard.php:444` | `recordCrewCompletion()` (§14.28.4) |
| Agent sign-off / tenant confirm / complete | `agentSignOff` :455, `tenantConfirm` :466, `complete(User)` :482 requires worker AND agent sign-off; service `complete()` also completes the linked work order | `RentalJobCard.php`; `RentalJobCardService.php:656` | Unchanged |
| Closed card locked | `isClosed()`, `assertContentEditable()`, `assertOpen()` | `RentalJobCard.php:348-371` | Link + uploads refuse on a closed card |
| Audit trail | `logUpdate(type, ?User by, note, from, to)` → `rental_job_card_updates` (nullable actor) | `RentalJobCard.php:265` | New update types |
| Crew model / screens | `RentalCrew` fillable `agency_id, name, notes, is_active, created_by_user_id` (**no phone/email at crew level**); `RentalCrewMember` `name, phone, role`; routes `routes/web.php:3922-3944`; views `resources/views/corex/rental-crews/{index,create,edit}.blade.php` (form `edit.blade.php:30-37`, member row `:66-68`) | `RentalCrew.php:25`; `RentalCrewMember.php:16` | Add the two fields |
| Job card print / PDF | `jobCardPrintPdf()` :132, `jobCardFilename()` :164; route `corex.rental-job-cards.print` `routes/web.php:3987`; QR already generated for rentals PDFs with `endroid/qr-code` (`composer.json:14`; `RentalInspectionReportPdfService.php:10-12`) | `app/Services/Rentals/RentalDocumentPdfService.php` | "Print with link" QR |
| Photo storage | `RentalJobCardService::storePhoto(card, file, type, ?User, ?clientKey)` → `PropertyImageStorer::store()` (public disk `properties/{id}`, EXIF-normalised, downscaled) → immutable `rental_work_order_photos` row (`rental_job_card_id`, `rental_work_order_id` when linked, `photo_type` reported / in_progress / completed, `uploaded_by_user_id` **nullable**, `client_idempotency_key`) | `RentalJobCardService.php:627`; `app/Services/Images/PropertyImageStorer.php:32`; `RentalWorkOrderPhoto.php`; web `RentalJobCardController.php:805`; mobile `Api/MobileRentalJobCardController.php:64` (also `tickTask` :49) | Crew = one more caller with a `null` actor, as `ContractorSecureLinkController.php:98` already does |
| Office photo display | Card "Photos" block merges card + linked work-order photos | `resources/views/corex/rental-job-cards/show.blade.php:494-496` | Unchanged |
| Signed-copy precedent | Inspection wet-ink scans: "a superseded scan is archived, never removed"; `supersede-wet-ink` flow | `app/Models/RentalInspectionScan.php:18`; `RentalInspectionRecordingController.php:1376` | Mirror for the signed job card |
| Tenant / landlord visibility **today** | Portal API only (no web views in this repo). Tenant `workOrderShow` returns title/status/`completed_at`/confirmation — **no photos, no job card**; fault show — **no photos**; landlord `workOrders` — title/status/approval/amount, **no photos**; **job cards are not exposed at all** | `Api/V1/ClientTenantRentalsController.php:168,266`; `Api/V1/ClientLandlordRentalsController.php:237`; routes `routes/api.php:215-242`; scope `RentalPortalScopeService.php:110,190,198` | §14.29 |
| Tenancy log | Sources: application, lease, inspection, fault, work_order, notice, rental_notice — the docblock names job cards as the planned next source | `app/Services/Rentals/LeaseTimelineService.php:21` (docblock), `:29` (`TYPES`), `:77-78`, `:204` | One more builder |
| Office permissions | `rental_job_cards.view / create / send_quote / sign_off / cancel` | `config/corex-permissions.php:248-257` | Add `rental_job_cards.share` |
| Agency mailbox send path | `BaseSignatureMail::fromAgent()`; `ComplianceMailDispatcher::send(?string $to, BaseSignatureMail $mail)` routes through the agent's resolved mailbox with Sent-folder append and an audited fallback | `app/Mail/Signatures/BaseSignatureMail.php:51,94`; `app/Services/Compliance/ComplianceMailDispatcher.php:27-60`; `app/Services/Communications/ImapSentFolderAppender.php` | A scoped `RentalMailDispatcher` (Build 1) — same reason compliance has its own copy |
| Existing rentals mails (do NOT copy) | Plain `Mail::to()->send(new …Mailable)` | `RentalWorkOrderService.php:178,200,221`; `RentalPortalNotificationService.php:38,56` | Out of scope; new mails use the agency mailbox path |

#### 14.27.5 Rules common to both builds
**Token doctrine.** 64-character random token shown once, only its SHA-256 stored, one live link per target (job card or crew);
issuing again revokes the previous **in the same transaction** (the old link is dead on the very next request); unauthorised,
expired, revoked, closed, archived and forged all render the **identical "unavailable" page**; public routes `throttle:30,1`
(photo POST also `throttle:60,10`); never route-model-bound — the token is resolved and its liveness checked explicitly.

**A per-job link is live only while ALL hold:** not revoked; not expired (`crew_job_link_expiry_days`); `crew_links_enabled`;
the card is not **Completed**, not **Cancelled**, not **archived**, its property not archived. **A crew page link is live while
ALL hold:** not revoked; not expired (`crew_standing_link_expiry_days`, if set); `crew_links_enabled`; the crew is active and not
archived. (`RentalSecureAccessToken::isLive()` today calls `$this->expires_at->isPast()` unguarded —
`app/Models/RentalSecureAccessToken.php:53` — so `expires_at` becomes nullable and null means "no expiry".)

**The crew's per-job view** (Build 1; Build 2 embeds it): title; property address + map link; **access notes**; scheduled / due;
crew name; **tasks** with ticks; the **materials** (part lines: description, quantity, unit) and labour lines **without prices**
unless `crew_link_show_prices`; the **tenant's name + phone only** if `crew_link_show_tenant_contact`; the photos uploaded so far;
the two actions **Add photos** and **Mark work completed**. **Never shown:** landlord/owner name or contact, quote amounts or
approval state, other cards, history, other crews.

**Completion states** (all on the existing card fields; nothing here closes a card):

| State | Who records it | How |
|---|---|---|
| Crew completed (via link) | The crew, no login | "Mark work completed": **typed full name + confirmation tick**; stores `worker_signed_off_at` (timestamp), `worker_sign_off_name`, `worker_sign_off_via = crew_link`, `worker_sign_off_ip`, `worker_sign_off_device` (user-agent); actor null; history line |
| Crew completed (signed copy) | Office user with `rental_job_cards.sign_off` | Uploads the signed job card; in the same transaction records the same worker sign-off with `via = signed_copy`, the "signed by" name typed by the office, IP/device of the uploader |
| Crew completed (manual) | Office user with `sign_off` | Existing "Worker — done" button, now `via = office` |
| Agent checked | Office user with `sign_off` | Existing `agentSignOff()` |
| Completed (the close) | Office user with `sign_off` | Existing `complete()` — needs both sign-offs; this is the moment the link dies |

After the crew completes, the per-job link **stays live** (so late photos can still be added) until the card is Completed,
Cancelled, archived, expired or revoked; the "Mark work completed" button is replaced by "Completed — signed by {name}". There
is no undo on the link; a mistaken crew completion is simply not agent-signed (and may be superseded by a corrected signed copy).

**Signed-copy rules** (`rental_job_card_signed_copies`): pdf / jpg / png only, **max 10240 KB**; stored on the **private**
disk (a signed document), served only through an authenticated route that runs `guardRentalRecordScope`; an earlier upload is
**kept and stamped Superseded** (`superseded_at`, `superseded_by_id`), shown as history, never hard-deleted (non-negotiable #1);
refused on a Cancelled card; allowed on a Completed card (paper often arrives late) without changing completion.

**Photo rules:** multiple files per submit (max 10), each ≤ 50 MB, jpg / png / webp / heic (same as existing photo rules); type
**Work in progress** or **Completed** (`photo_type`), optional caption; through `RentalJobCardService::storePhoto(…, null actor)` →
`PropertyImageStorer`; per-file client UUID as `client_idempotency_key` so a flaky connection cannot double-post; immutable (no
delete from the link; the office cannot hard-delete — no `deleted_at` today).

**Materials list rule (Build 2, also the per-job "materials" block):** only lines whose catalogue type kind is **part**
(`RentalCatalogueItemType::KIND_PART`) — labour is not stock — summed by **catalogue item + unit**; a free-text part line with no
catalogue item is grouped by normalised description (trim, lower-case, collapsed spaces) + unit. Quantities and units only; **no
prices** unless `crew_link_show_prices`; labelled "**What to load**". There is **no stock-on-hand data** in CoreX rentals, so the
list never says "short of".

**Open cards that count for a crew** (`rental_job_cards.rental_crew_id = crew`, same agency, not archived, property not
archived): status **Approved, Scheduled or In progress** — booked work. **Draft** (Q16) and **Quoted** (still waiting for the
owner's approval, so not yet booked work) are not shown. Completed / Cancelled / archived drop off immediately. The statuses that
count are ONE constant, `RentalJobCard::CREW_VISIBLE_STATUSES = [approved, scheduled, in_progress]`, so an agency-wide change
later is a one-line edit.

**Scoping.** Office side: every action runs `guardRentalRecordScope($card, 'rental_job_cards', $property->branch_id)` — own /
branch / agency at the query layer; direct-URL access by id is blocked, not just unlinked. Public side: a crew link resolves
`agency_id` + `rental_crew_id` / `rental_job_card_id` from the token only — nothing in the URL or request can widen it; a crew
link never shows another crew's or another agency's cards (tested with two crews in two agencies).

**Audit** (`rental_job_card_updates`, actor null for the crew; `note` carries name / counts / "via crew link — {crew}"):
`link_issued`, `link_emailed` (address), `link_revoked`, `link_opened` (first open + `last_used_at` on the token),
`crew_photos_added` (count, types), `crew_completed` (name, via, IP, device), `signed_copy_uploaded`, `signed_copy_superseded`,
`landlord_notified`. Crew-level events are in `rental_crew_link_events` (Build 2).

**Domain events** (non-negotiable #9; add to `.ai/specs/corex-domain-events-spec.md` when built): `RentalJobCardLinkIssued`,
`RentalJobCardCrewCompleted`, `RentalJobCardSignedCopyUploaded`, `RentalJobCardCrewPhotosAdded`. The landlord email listens to
`RentalJobCardCrewCompleted`.

**Navigation (non-negotiable #2).** No new top-level page. Build 1: "Share with crew" and "Signed copy" panels in the job card's
right column; email/phone fields on the Rental Crews forms and list; the Print menu gets "Print with link". Build 2: a "Crew
link" panel on the Rental Crews edit page and a link-status column on the list; a "Job card" filter option in the lease hub
tenancy log. The public pages are reached only by link.

**Multi-agency.** Wording neutral; agency name/logo from the agency record; no agency-1 defaults; every default above is sensible
for an agency that is not HFC.

#### 14.27.6 Shared interface — what Build 2 depends on from Build 1
Build 2 may start against this interface before Build 1 merges; Build 2's integration tests need Build 1's migrations on the test
schema (they seed rows using these exact names).

1. **Crew fields:** `rental_crews.email` (nullable string 191), `rental_crews.phone` (nullable string 30); `RentalCrew` fillable +
   casts; crew form validation (`email:rfc` max 191; phone `^[0-9+()\- .]{5,30}$`).
2. **Token table** `rental_secure_access_tokens` (Build 1's single migration): `rental_job_card_id` (nullable FK),
   `rental_crew_id` (nullable FK), `rental_work_order_id` **now nullable**, `expires_at` **now nullable**, `purpose` string(30)
   (`contractor_work_order` | `crew_job_card` | **`crew_standing`**; existing rows back-filled `contractor_work_order`);
   exactly one target set (service-enforced + test).
3. **Token service** `App\Services\Rentals\RentalSecureAccessTokenService`:
   `issue(Model $target, string $purpose, User $by, ?int $expiryDays): array{token: RentalSecureAccessToken, raw_token: string}`
   (revokes any live token for that target first, same transaction); `revokeAllFor(Model $target): void`;
   `revoke(RentalSecureAccessToken $t): void`; `resolveLive(string $rawToken, string $purpose): ?RentalSecureAccessToken`
   (hash lookup + generalised `isLive()`); wrappers `issueForJobCard(RentalJobCard, User): array` (Build 1) and
   `issueForCrew(RentalCrew, User): array` (Build 2 adds this wrapper, additive). `RentalSecureAccessToken::isLive()` switches on
   `purpose` and treats null `expires_at` as no expiry.
4. **Crew view layer** (Build 1, so the per-job link and the crew page share one implementation):
   `App\Services\Rentals\CrewViewContext` (value object: `agencyId`, `crewId`, `tokenId`, `via` = `job_link`|`crew_page`,
   `showPrices`, `showTenantContact`, `ip`, `userAgent`, `actorLabel`) and `App\Services\Rentals\CrewJobService` with
   `payload(RentalJobCard, CrewViewContext): array`, `tick(RentalJobCard, RentalJobCardTask, CrewViewContext): void`,
   `addPhotos(RentalJobCard, UploadedFile[], string $type, ?string $caption, CrewViewContext): int`,
   `markCompleted(RentalJobCard, string $fullName, bool $confirmed, CrewViewContext): void` — each refuses a closed/archived card
   and writes the audit rows. Blade partial `resources/views/rentals/crew-link/_job-body.blade.php` renders `payload()`.
5. **Model:** `RentalJobCard::recordCrewCompletion(string $name, string $via, ?string $ip, ?string $device, ?User $by): void` and
   the columns `worker_sign_off_via`, `worker_sign_off_ip`, `worker_sign_off_device`; `RentalJobCard::CREW_VISIBLE_STATUSES`.
6. **Photos:** `rental_work_order_photos.caption` (nullable string 255) and `uploaded_via` (nullable string 20: `crew_link`,
   `crew_page`, `office`).
7. **Audit vocabulary** (§14.27.5) and the four domain events.
8. **Permission** `rental_job_cards.share` (Build 2's link panel reuses it).
9. **Mail path:** `App\Services\Rentals\RentalMailDispatcher::send(?string $to, BaseSignatureMail $mail)`; Build 2's
   `RentalCrewStandingLinkMail` uses it.
10. **Settings accessors** for Build 1's five keys + `crew_links_enabled` on `RentalPortalSetting`; Build 2 adds its four.

---

### 14.28 BUILD 1 — per-job crew link, crew contact details, signed-copy upload, crew completion

**Goal:** Johan's (a)(b)(c)(d-upload) — crew email + phone on Rental Crews; mint / copy / email / revoke a per-job link from the
job card; the mobile crew job view (tick tasks, see materials, upload photos, mark completed); wet-ink signed-copy upload;
completion states; audit trail; the settings these need. Independent of Build 2 except that Build 2 consumes §14.27.6.

**Migrations** (each with `php artisan schema:dump` + DEFINER strip, non-negotiable #12a; migrations idempotent on Staging data):
1. `…_add_contact_to_rental_crews` — `email`, `phone`.
2. `…_extend_rental_secure_access_tokens_for_crew_links` — `rental_job_card_id`, `rental_crew_id`, `purpose`; make
   `rental_work_order_id` and `expires_at` nullable; back-fill `purpose`.
3. `…_add_crew_completion_columns_to_rental_job_cards` — `worker_sign_off_via`, `worker_sign_off_ip`, `worker_sign_off_device`.
4. `…_create_rental_job_card_signed_copies_table` — `id`, `agency_id`, `rental_job_card_id`, `storage_path`, `original_name`,
   `mime_type`, `size_kb`, `uploaded_by_user_id`, `uploaded_at`, `superseded_at`, `superseded_by_id`, soft deletes, indexes.
5. `…_add_caption_and_uploaded_via_to_rental_work_order_photos`.
6. `…_add_crew_link_settings_to_rental_portal_settings` — `crew_links_enabled`, `crew_job_link_expiry_days`,
   `crew_link_show_prices`, `crew_link_show_tenant_contact`, `notify_landlord_on_crew_completion`.

**Routes**
- Public (`routes/web.php`, new block after the contractor block at :58-62, `throttle:30,1`):
  `GET secure/job-cards/{token}` → `rentals.crew-job.show`; `POST secure/job-cards/{token}/tasks/{task}/tick` →
  `rentals.crew-job.tick`; `POST secure/job-cards/{token}/photos` (+`throttle:60,10`) → `rentals.crew-job.photos`;
  `POST secure/job-cards/{token}/complete` → `rentals.crew-job.complete`.
- Office (in the `rental-job-cards` group near `routes/web.php:3987-4038`): `POST rental-job-cards/{rentalJobCard}/crew-link`
  (issue / re-issue; `rental_job_cards.share`) → `corex.rental-job-cards.crew-link.issue`; `DELETE …/crew-link` →
  `…crew-link.revoke`; `POST …/crew-link/email` → `…crew-link.email`; `POST …/signed-copy` (`rental_job_cards.sign_off`) →
  `…signed-copy.store`; `GET …/signed-copy/{copy}` (view/download, scope-checked) → `…signed-copy.download`;
  the existing `GET …/print` accepts `?with_link=1` (requires `share`; mints a new link and embeds its QR).
- Rental Crews: no new routes (existing store/update take the two new fields).

**Controllers / services / mail**
- New: `App\Http\Controllers\CrewJobLinkController` (public, mirrors `ContractorSecureLinkController`),
  `App\Http\Controllers\CoreX\RentalJobCardCrewLinkController` (issue / revoke / email),
  `App\Http\Controllers\CoreX\RentalJobCardSignedCopyController`, `App\Services\Rentals\CrewJobService`,
  `CrewViewContext`, `RentalMailDispatcher`, `App\Mail\Rentals\RentalJobCardCrewLinkMail` and
  `RentalJobCardCrewCompletedLandlordMail` (both `extends BaseSignatureMail`, `fromAgent()`, neutral agency-branded wording),
  the four domain events + one listener (`SendLandlordCrewCompletionMail`, gated by `notify_landlord_on_crew_completion`; fires on the `RentalJobCardCrewCompleted` event, i.e. for BOTH the link and the signed-copy route — the work is done either way),
  `RentalJobCardSignedCopy` model.
- Changed: `RentalSecureAccessToken` + `RentalSecureAccessTokenService` (§14.27.6 items 2-3), `RentalJobCard`
  (`recordCrewCompletion`, constants, `signedCopies()` relation), `RentalJobCardService` (completion/photo hooks),
  `RentalCrewController` (+ validation), `RentalDocumentPdfService`/`print.blade.php` (QR), `RentalPortalSetting` +
  `RentalPortalSettingsController` + routes for the five settings, `config/corex-permissions.php`, `config/agency-onboarding-copy.php`.

**Views**
- New: `resources/views/rentals/crew-link/{job,unavailable}.blade.php`, `_job-body.blade.php` (shared partial),
  `resources/views/corex/rental-job-cards/{_crew-link-panel,_signed-copy-panel}.blade.php`, mail views.
- Changed: `show.blade.php` (two `@include`s in the right column; header shows "Crew completed — {name}, {via}" chip),
  `print.blade.php` (QR block when `with_link`), `rental-crews/{create,edit,index}.blade.php` (email + phone; list gets a Contact
  column and searches name / email / phone), `corex/settings/rental-portal.blade.php` ("Crew links" section, five settings).
- "Share with crew" panel (open cards only): **Generate link** (URL shown once with **Copy**), **Email to crew** (crew address,
  editable, or any typed address — Q2), **WhatsApp** (`wa.me` prefilled, manual), **Revoke**; once a link exists it shows issued
  by/when, expiry, last opened and "Re-issue (replaces the old link)" — the raw URL can never be shown again.

**Settings keys:** `crew_links_enabled`, `crew_job_link_expiry_days`, `crew_link_show_prices`, `crew_link_show_tenant_contact`,
`notify_landlord_on_crew_completion` (§14.27.3). **Wizard rows:** one per key, inserted directly **after** the
`contractor_secure_link_expiry_days` entry (`config/agency-onboarding-copy.php:583`).

**Permissions:** new `rental_job_cards.share` ("Share Job Cards with Crew", action, section `agency-tracker`) in
`config/corex-permissions.php` next to `rental_job_cards.sign_off`, default-granted to the same roles that hold
`rental_job_cards.create`, enforced by route middleware + controller check; signed-copy upload/download reuse
`rental_job_cards.sign_off` / `.view`; the public link has no permission (the token is the credential). Role Manager picks it up
from the config.

**Tests** (single files, via `scripts/lane-test.sh`): `RentalCrewContactTest` (create/update/validation/list search/archive-restore);
`CrewJobLinkTokenTest` (issue shows raw once, hash only, re-issue kills old on next request, revoke, expiry, closed/cancelled/
archived/agency-off/forged all render the identical unavailable page, completed-after-agent-sign-off kills the link);
`CrewJobLinkViewTest` (no prices by default, prices with the setting, tenant contact only with the setting, never landlord/quote
data, scoping to the card); `CrewJobLinkActionsTest` (tick, photo upload types/limits/idempotent key, mark completed requires name +
tick and records timestamp/IP/device/`via`, does NOT complete the card, refuses closed); `RentalJobCardSignedCopyTest`
(pdf/jpg/png only, 10240 KB limit, private disk, scoped download, supersede keeps the earlier copy, refused when cancelled,
records worker sign-off `via=signed_copy`); `RentalJobCardCrewLinkOfficeTest` (own/branch/agency scoping, permission matrix,
email goes through the agency mailbox path — asserted with a fake dispatcher — and to `@example.invalid` only, audit rows);
`RentalJobCardCrewCompletionMailTest` (landlord email on completion when the setting is on, none when off, via the dispatcher,
never a plain Mailable — asserted by `Mail::fake()` receiving nothing); `RentalCrewLinkSettingsTest` (defaults, ranges, wizard
saver does not wipe unrendered settings); print-with-link QR test. Real-browser mobile-viewport proof on QA1 (link open, tick,
photo upload, mark completed, signed-copy upload) with `@example.invalid` test addresses.

**Acceptance:** (1) crew email/phone save + validate; (2) Generate → URL once; Email → one mail via the agency mailbox path
(QA1: caught); second Generate kills the first; (3) the link opens on a phone with no login and shows exactly §14.27.5's view;
(4) crew "Mark work completed" records name/timestamp/IP/device, the card is NOT completed, the landlord mail follows if on — once per card, on the first crew completion only;
(5) signed copy uploads, supersedes, never deletes, records the same crew completion; (6) crew photos appear on the card;
(7) agent sign-off + complete kills the link; (8) every action is in the card history.

**BUILT 2026-10-06 (cc4) — Build 1, both steps on QA1.** Step 0 = the §14.27.6 shared interface (migrations `2026_10_10_1000xx`:
crew contact, token table, sign-off via/ip/device, photo caption + `uploaded_via`, the five settings, the `rental_job_cards.share`
grant); Step 1 = everything else (migration `2026_10_10_110000` signed copies). Where the build made a call the spec left open:
- **"Email to crew" and the one-time URL.** The raw link is never stored, so the email action either posts back the link just
  generated (the panel carries it in a hidden field and the server only trusts it if it resolves LIVE for THIS card) or, with
  no link in hand, issues a fresh one — replacing the old — and emails that. The old link dies in the same transaction.
- **WhatsApp** uses the canonical `App\Support\WhatsAppNumberFormatter` (crew phone has no dial code; +27 default like contacts).
- **The landlord email** is a synchronous domain-event listener (`SendLandlordCrewCompletionMail`) that dispatches a queued job
  (`SendLandlordCrewCompletionMailJob`) — domain events hold readonly state and cannot be queued themselves. The job checks the
  `notify_landlord_on_crew_completion` setting, sends through `RentalMailDispatcher` AS the property's responsible agent (fallback:
  the card's creator, then the shared mailer) and writes `landlord_notified` to the card; a failure never breaks the crew's sign-off.
- **`RentalMailDispatcher`** extends `ComplianceMailDispatcher` (one implementation of the mailbox routing, own class for rentals).
- **Wizard current values:** `AgencySetupWizardController::currentValues()` gained an explicit `'rental_portal'` arm naming every
  control under that source (it had none, so all six pre-existing portal controls always showed their hardcoded default — §6.2).

**FOLLOW-UP 2026-10-06 (cc4) — landlord email once per job card; wizard gaps.**
- **One landlord email per job card (decision).** The "work completed" email goes out on the FIRST crew completion by ANY route
  (crew link, crew page or signed copy) and never again for that card. A later crew completion — a corrected signed-copy
  re-upload, or the other route arriving second — still records and audits exactly as before (the earlier signed copy is kept and
  marked Superseded; the sign-off details are overwritten; `crew_completed` is logged) but sends no email; the card's history
  gets "Landlord not emailed again — they were already told the crew completed this job". "Once" is enforced by
  `rental_job_cards.landlord_crew_notice_at`, claimed by the listener with ONE conditional `UPDATE ... WHERE landlord_crew_notice_at IS NULL`
  (atomic — two racing completions cannot both win). The claim is taken at the first completion even if no email goes out (setting off,
  no landlord address): the first completion is when the landlord is told or not, and a later completion does not reopen that.
  Migration `2026_10_10_120000` stamps cards whose crew had already completed by link / page / signed copy (they have had their one
  email); a card whose only sign-off was the office "Worker — done" button is left empty. The link route already refuses a second
  completion once a sign-off exists, so "signed copy first, crew link second" is refused at the link and, for any route that does
  reach the event, silent at the listener. Tests: `RentalJobCardCrewCompletionMailTest` (both orders, re-upload, setting-off-then-on, double event).
- **Wizard current values (§6.2).** `currentValues()` named no arm for `capture_prices_on_job_cards`, `show_prices_on_printed_job_card`
  (both `rental_work_orders`) or the seven §43 scheduling settings under `rental_inspections` (`notify_tenant_enabled`,
  `notify_landlord_enabled`, `notify_inspector_enabled`, `notify_via_mail_enabled`, `notify_via_whatsapp_enabled`,
  `minimum_notice_days`, `reminder_days_before`), so the wizard showed the hardcoded default instead of the agency's saved value.
  All nine are named now, and `AgencySetupWizardCurrentValuesTest` guards `rental_portal` as well as the other explicit-per-key sources.
- **Leases-step save test.** A real browser always posts every toggle on the wizard (a hidden `0` is rendered beside every checkbox),
  so the saver's absent-means-refuse guard cannot be hit from the page; the test posted a hand-written payload that had gone stale.
  It now starts from the form the page renders, serialised as a browser would, plus the Alpine-built list rows.
  Build 2 appends its four keys to that arm.
- A signed copy uploaded to a **Completed** card is filed only (no sign-off, no event); a **Cancelled** card refuses it.
- The public job page uses inline CSS and a few lines of vanilla JS (no Vite bundle), so it loads on a weak phone connection.

---

### 14.29 BUILD 2 — crew general page, crew-link management, and tenant / landlord visibility of crew photos and completion

**Goal:** Johan's (e) and the "flow back" half of (d) — one standing link per crew with a mobile page (grouped job list +
combined materials list), link management on Rental Crews, and tenant/landlord visibility of job-card photos + completion
(tenancy log, lease hub, tenant and owner portal API). Consumes §14.27.6; touches none of Build 1's new files.

**The crew page** (`secure/crews/{token}`, mobile-first): header (crew, agency name/logo, today's date); **Today** (by time),
**Upcoming** (next `crew_page_upcoming_days`, by date), **Unscheduled** (assigned, no date) — each row: scheduled time + due,
title, property address, **access notes**, status chip, tap to open; optional collapsed **Recently completed** (last
`crew_page_recent_completed_days`, read-only, 0 hides); **Materials to prep — "What to load"**: the §14.27.5 materials rule over
**today + upcoming** cards, each row item code · description · total quantity + unit · "needed from {first date} ({n} jobs)",
expandable to the per-job breakdown; no prices unless `crew_link_show_prices`. Opening a card shows the **same per-job view** as
Build 1 (`_job-body` partial + `CrewJobService`), authorised by the **crew token**: the card must be one of **that crew's open
cards** or the identical unavailable page is returned. Only cards assigned to **that crew and that agency** appear; completed /
cancelled / archived cards drop off on the next refresh.

**Migrations:** (1) `…_create_rental_crew_link_events_table` — `id`, `agency_id`, `rental_crew_id`, `token_id`, `event`
(`issued` | `emailed` | `opened` | `job_opened` | `revoked` | `regenerated` | `action`), `rental_job_card_id` (nullable), `note`,
`ip`, `user_agent`, `actor_user_id` (nullable, office actions), `created_at`; (2) `…_add_crew_page_settings_to_rental_portal_settings`
— `crew_photos_visible_to_clients`, `crew_standing_link_expiry_days`, `crew_page_recent_completed_days`, `crew_page_upcoming_days`.
(No token migration — Build 1's.)

**Routes**
- Public (`routes/web.php`, own block, `throttle:30,1`): `GET secure/crews/{token}` → `rentals.crew-page.show`;
  `GET secure/crews/{token}/job-cards/{card}` → `rentals.crew-page.job`; `POST …/job-cards/{card}/tasks/{task}/tick`,
  `POST …/job-cards/{card}/photos`, `POST …/job-cards/{card}/complete` — each delegating to `CrewJobService` with a
  `CrewViewContext(via: 'crew_page')`.
- Office (beside the crews block `routes/web.php:3922-3944`, `rental_job_cards.share` + `rental_catalogue.view`):
  `POST corex/rental-crews/{crew}/link` (issue / regenerate) → `corex.rental-crews.link.issue`; `DELETE …/link` →
  `…link.revoke`; `POST …/link/email` → `…link.email`; `GET …/link/events` → `…link.events`.
- API (named, in the Admin → API catalog — non-negotiable #7), added to the existing client groups in `routes/api.php`
  (tenant block :215-226, landlord block :230-242). **SUPERSEDED 8 Oct 2026 (Johan W4, `rentals-faults-work-orders.md` §16):** the
  portal job-card endpoints that were added here (tenant and owner `GET rentals/job-cards`, `GET rentals/job-cards/{jobCard}`,
  `GET rentals/landlord/job-cards`, `GET rentals/landlord/job-cards/{jobCard}`) were REMOVED — the job card is internal; tenants and
  owners read the WORK ORDER (tenant `GET rentals/work-orders`, `work-orders/{id}`, `fault-reports/{id}`; owner `GET rentals/landlord/work-orders`,
  `work-orders/{id}`), which carry the plain stage, who is doing it, the appointment and the same `photos` array (same visibility rule).

**Controllers / services**
- New: `App\Http\Controllers\CrewPageController` (public), `App\Http\Controllers\CoreX\RentalCrewLinkController` (office),
  `App\Services\Rentals\RentalCrewScheduleService` (the ONE query for "this crew's open cards" + grouping + the materials
  summation, so the page, tests and any future API share it), `RentalCrewLinkEvent` model,
  `App\Mail\Rentals\RentalCrewStandingLinkMail` (agency mailbox path via Build 1's `RentalMailDispatcher`).
- Changed: `RentalPortalScopeService` (`tenantJobCards()/tenantJobCard()` = card's lease is the tenant's; `landlordJobCards()/
  landlordJobCard()` = card's property is the landlord's; both `withoutGlobalScopes()` + explicit `agency_id`, so another
  party's / agency's card is a 404), `Api/V1/ClientTenantRentalsController` + `ClientLandlordRentalsController` (the new
  actions + `photos`), `LeaseTimelineService` (`jobCardEntries()`; type `job_card` added to `TYPES` :29 and merged at :77),
  `RentalSecureAccessTokenService` (`issueForCrew()` wrapper only), `RentalPortalSetting` + `RentalPortalSettingsController` +
  routes (four keys).
- **What tenant/landlord receive for a job card:** title, status, scheduled / due, `completed_at`, who signed the crew completion
  and how (name, `via`), and the **photo URLs** allowed by `crew_photos_visible_to_clients` (default in-progress + completed;
  `reported` photos never). **Tenant: no prices. Landlord: the quote amount only as the existing work-order endpoint already
  shows it.** Timeline entries: "Job card opened", "Crew photos added (n)", "Work completed — signed by {name} via link /
  signed copy", "Job card completed" (the lease hub and anything reading `LeaseTimelineService` show them).

**Views**
- New: `resources/views/rentals/crew-link/{crew-page,crew-page-unavailable}.blade.php` (embeds Build 1's `_job-body`),
  `resources/views/corex/rental-crews/_crew-link-panel.blade.php`, mail view.
- Changed (one `@include` / one column each, to keep the diff away from Build 1's edits): `rental-crews/edit.blade.php`
  (panel), `rental-crews/index.blade.php` (link-status column), the lease hub tenancy-log filter (new "Job card" type),
  `corex/settings/rental-portal.blade.php` (four rows appended **after** Build 1's "Crew links" rows, in a sub-group "Crew page &
  client visibility").
- **Crew link panel (Rental Crews edit page):** Generate (URL once + Copy), **Email to crew** (crew address, editable;
  agency mailbox path), **WhatsApp**, **Revoke**, **Regenerate** (the old link dies on the very next request); status line:
  issued by/when, expiry (or "stands until revoked"), last opened, open count; newest-first **event log** (every open is a row,
  throttled to one row per token per 10 minutes — `last_used_at` still updates every time; every action the crew takes
  from the crew page also writes the card's own history line tagged "via crew page — {crew}"). Archived crews: read-only.

**Settings keys:** `crew_photos_visible_to_clients`, `crew_standing_link_expiry_days`, `crew_page_recent_completed_days`,
`crew_page_upcoming_days` (§14.27.3). **Wizard rows:** appended at the end of the `rental_portal` block (after
`notify_tenant_on_status_change`), so they never collide with Build 1's insertion point.

**Permissions:** reuses `rental_job_cards.share` (Build 1) for the link panel; no other new keys. Portal endpoints use the existing
`rental-portal.enabled:{tenant|landlord}` middleware; the public crew pages use the token only.

**Tests:** `RentalCrewScheduleServiceTest` (grouping Today / Upcoming / Unscheduled, windows from settings, Draft excluded,
completed / cancelled / archived drop off, **two crews in two agencies never see each other's cards**, materials summed per
catalogue item + unit incl. free-text grouping, labour excluded, prices only with the setting); `CrewPageLinkTest` (standing
link lives until revoked, optional expiry, regenerate/revoke kill the old link on the next request, archived/inactive crew,
agency switch, forged job id = unavailable); `CrewPageActionsTest` (tick / photo / complete through the crew token, `via =
crew_page`, logged on card + crew log); `RentalCrewLinkPanelTest` (permission, email via the dispatcher, event log throttling);
`TenantLandlordJobCardApiTest` (scoping — another tenant/landlord/agency = 404; photos filtered by `crew_photos_visible_to_clients`;
tenant never sees prices; landlord amount rule; the `photos` array on the existing endpoints); `LeaseTimelineJobCardTest` (entries
appear in the right order, type filter); `CrewPageSettingsTest` (defaults, ranges, wizard saver safety). Real-browser mobile proof
on QA1: open the crew page, open a job from it, see the materials total, regenerate and see the old link die.

**Acceptance:** (1) a crew link lists exactly that crew's open assigned cards, none from another crew or agency; (2) a card
completed / cancelled / archived after the page loaded drops off on refresh and its direct URL is "unavailable"; (3) the
materials total equals the part lines of the listed (today + upcoming) cards summed per catalogue item + unit; (4) regenerate /
revoke kill the old link on the very next request; (5) every open and action is in the crew log and the card's history;
(6) prices only with `crew_link_show_prices`; (7) tenant and landlord see the job card, its completion and the allowed photos —
and a different tenant / landlord gets a 404.

**BUILD NOTE — Part A (tenant / landlord visibility), built 6 Oct 2026 on `cc6-crew-page-2026-10-06`, needs nothing from Build 1:**
- **Migration** `2026_10_09_100000_add_crew_page_settings_to_rental_portal_settings` — all four Build 2 setting columns (nullable; null = default). The `RentalPortalSetting` accessors for all four are in; the **Settings → Rental Portal + Setup Wizard control for `crew_photos_visible_to_clients`** ships with Part A (a setting nobody can change is not done); the other three controls ship with the crew page (Part B).
- **One decision, one place:** `RentalJobCardClientViewService` decides what a client sees (photo rule, completion block, payload); `RentalPortalScopeService::tenantJobCards()/tenantJobCard()/landlordJobCards()/landlordJobCard()` decide which cards a party may open (own lease / own property; `withoutGlobalScopes()` **also strips SoftDeletes**, so `deleted_at IS NULL` is explicit; `agency_id` pinned manually).
- **Draft cards are never shown to a tenant or landlord** (`RentalJobCardClientViewService::CLIENT_HIDDEN_STATUSES = [draft]`) — a draft is the office's unfinished prep. Every other status (quoted, approved, scheduled, in progress, completed, cancelled) is shown truthfully. *(Spec §14.29 was silent; flagged to the conductor — one-constant change either way.)*
- **Photos:** card photos + the linked work order's, filtered by the agency rule; `reported` never; each photo is `{id, url, photo_type, caption, uploaded_at}` (`caption` is null until Build 1's column exists — read via `getAttribute`, no code change needed then). The existing `work-orders.show` / `fault-reports.show` (tenant) and `work-orders.index` (landlord) responses gained `photos`; for a fault report it is the photos of the **work done** (linked job cards / work order), never the reporter's own attachments.
- **API** (named, in the Admin → API catalog): ~~tenant `client.rentals.job-cards.index|show`, landlord `client.rentals.landlord.job-cards.index|show`~~ — **removed 8 Oct 2026 (§16 / `rentals-faults-work-orders.md` §16, W4)**; the work-order endpoints (`client.rentals.work-orders.*`, `client.rentals.landlord.work-orders.*`) are the portal's read path. Tenant payload never carries a price or a quote; landlord payload carries the owner-facing amount only.
- **Web portal** (`rentals/portal/shell.blade.php`): a **Jobs** tab for tenants and for landlords — status, dates, who signed the crew completion, the photo thumbnails.
- **Tenancy log:** `job_card` added to `LeaseTimelineService::TYPES` (the "Job card" filter box appears automatically); entries *Job card opened*, *Crew photos added (n)* / *Photos added (n)* (grouped per card per day; crew = no CoreX user behind the upload), *Work completed — signed by {name} [via link / crew page / signed copy]* (the `via` suffix appears as soon as Build 1's `worker_sign_off_via` column does; absent = no suffix), *Job card completed*. Scoped by `lease_id`. Like the fault / work-order entries beside it, the log is lease-scoped, not filtered by the viewer's own job-card scope.
- **Lease hub:** a **Job cards** panel above the tenancy log (status chip, schedule / completion, crew, photo thumbnails — the office sees every photo type), listing only the cards the viewer's own job-card scope allows; absent when the tenancy has none or the viewer lacks `rental_job_cards.view`.
- **Wizard read-back:** `AgencySetupWizardController::currentValues()` had **no `rental_portal` arm**, so every portal control showed its hardcoded default instead of the saved value (§6.2 of the onboarding spec). An explicit per-key arm now names all seven `rental_portal` controls.
- **Tests:** `RentalPortalAccess/TenantLandlordJobCardApiTest`, `Leases/LeaseTimelineJobCardTest`, `RentalCrewLinks/CrewPageSettingsTest` (fixtures: `tests/Concerns/BuildsRentalPortalFixtures`).

**BUILD NOTE — Part B (the crew page + crew-link management), built 6 Oct 2026 on `cc6-crew-page-2026-10-06`, on top of Build 1's shared interface (§14.27.6):**
- **Migration** `2026_10_09_100100_create_rental_crew_link_events_table` (append-only log: `issued / regenerated / emailed / revoked / opened / job_opened / action`). The four Build 2 setting columns shipped in Part A's migration.
- **The one query:** `RentalCrewScheduleService` — `openCardsQuery()` (agency + crew + not archived + property not archived + `RentalJobCard::CREW_VISIBLE_STATUSES`), `findOpenCard()`, `schedule()` (Today / Upcoming / Unscheduled / Recently completed + the materials), `materials()`, `linkStatusesFor()`, `linkPanelFor()`. Rows are plain arrays — never models.
- **Crew page** `secure/crews/{token}` (`rentals.crew-page.show|job|tick|photos|complete`, `throttle:30,1`, photos also `throttle:60,10`; `{card}`/`{task}` numeric): the token IS the crew; a card must be one of THAT crew's open cards or the identical unavailable page (HTTP 404, same body for every dead-link cause) comes back. Opening a card renders Build 1's `_job-body` through `CrewJobService` with a `crew_page` context, so every action writes the card's own history line ("via crew page — {crew}") plus a row in the crew's log.
- **Decisions where the spec was silent (flagged to the conductor):** (1) an open booked card whose date is BEFORE today stays at the top of **Today**, flagged *Overdue* — dropping open work silently was the worse failure; those cards count towards "what to load". (2) **Recently completed is read-only** (title, address, completed time, no link into the card) — §14.29's acceptance (2) says a completed card's direct URL is "unavailable", so the list cannot be a link. (3) A card booked beyond `crew_page_upcoming_days` is not listed and not in the materials until it is inside the window.
- **Materials ("What to load"):** part lines only, summed by catalogue item + unit (free text: normalised description + unit); archived lines and lines of archived tasks excluded; unscheduled / beyond-window / draft cards excluded; each row item code · description · total + unit · "needed from {first date}" · "{n} jobs", expandable to the per-job breakdown; prices only with `crew_link_show_prices`; never "short of".
- **Link management** (Rental Crews edit page, `rental_job_cards.share` + `rental_catalogue.view`; crews are agency-wide, scoped by the agency): `POST/DELETE corex/rental-crews/{crew}/link`, `POST …/link/email`, `GET …/link/events` (an HTML page — not a hidden JSON endpoint). Only a hash is stored, so the URL is shown **once** (flashed to the page that follows generation); "Email this link" takes that URL back and **re-verifies it server-side** against the crew's live token, so the mail can never carry a link the system did not issue, and nothing raw is kept. Mail = `RentalCrewStandingLinkMail` through `RentalMailDispatcher` (the agency mailbox path), neutral wording, agency name from the record. Regenerate and revoke kill the old link on its very next request; the panel shows issued by / when, expiry or "stands until it is revoked", last used, open count and the newest 15 log rows; the Crews list has a link-status column.
- **Settings** (`crew_standing_link_expiry_days` blank = until revoked, 1–365; `crew_page_recent_completed_days` 0–30, 0 hides the list; `crew_page_upcoming_days` 1–60) are on Settings → Rental Portal and in the Setup Wizard, each with its own `has()`-guarded saver (a blank window leaves the stored value; a blank expiry clears it to "until revoked") and a `currentValues()` read-back entry.
- **Tests:** `RentalCrewScheduleServiceTest`, `CrewPageLinkTest`, `CrewPageActionsTest`, `RentalCrewLinkPanelTest`, `CrewPageSettingsTest` (extended to all four settings).

**BUILD NOTE — archived-record rules, 6 Oct 2026 (`cc4-archived-leaks-2026-10-06`; found by cc6 during Part B, fixed here):**
- **The crew's per-job view leaves out archived work.** `CrewJobService::payload()` strips only `AgencyScope` (a public crew link has no staff user to resolve it), **not** SoftDeletes — an archived task, an archived line, and a live line under an archived task are never shown, and never appear in that card's "What to load". Same rule as the crew page list (`RentalCrewScheduleService::materials()`) and the office card. Tests: `CrewJobServiceTest` (3 added).
- **Archiving a crew revokes every link it holds; restoring brings none back.** `RentalCrew::archive()` calls `RentalSecureAccessTokenService::revokeAllForArchivedCrew()` in the same transaction as the archive: the crew's standing link **and** every still-open per-job link on the crew's cards get `revoked_at` set; each one is audited (a `revoked` row on the crew's link log, note "Crew archived — link revoked", with the actor and — for a per-job link — the card; plus a `link_revoked` line on that card's history). `restoreRecord()` deliberately does nothing to links — `revoked_at` stays set, the old URL stays dead, and the office generates a new link from the crew's edit page. (Before this, liveness only checked "crew not archived *now*", so restore revived the old standing link.) Tests: `CrewArchiveRevokesLinksTest`.
- Observed, not changed: a dead **per-job** link renders the shared "Link unavailable" page with HTTP **200**, whereas a dead **crew-page** link returns **404** with the identical body for every cause. Both leak nothing; the status code differs.

---

### 14.30 Conflict map — what the two builds both touch (all additive, in separate blocks)
`routes/web.php` (Build 1: after :58-62 and in the job-card group; Build 2: own public block and beside the crews block),
`routes/api.php` (Build 2 only), `config/agency-onboarding-copy.php` (Build 1 inserts after `contractor_secure_link_expiry_days`;
Build 2 appends at the end of the rental_portal block), `corex/settings/rental-portal.blade.php` + `RentalPortalSetting.php` +
`RentalPortalSettingsController.php` (Build 1 adds its five; Build 2 adds its four below them), `rental-crews/edit.blade.php`
(Build 1: fields; Build 2: one `@include`), `rental-crews/index.blade.php` (Build 1: Contact column; Build 2: link-status column),
`RentalSecureAccessTokenService.php` (Build 1 owns; Build 2 adds `issueForCrew()` only). Everything else is exclusive to one build.
Build order recommendation: Build 1 merges its migrations first (token table, crew fields, photo/caption columns); Build 2 starts
in parallel against §14.27.6.

### 14.31 Catalogue item EDIT — the price box shows the agency's own basis, so a plain Save never moves the price (2026-10-07, cc2, QA1)

**What was wrong.** For an agency that captures prices INCLUDING VAT, the Parts & Labour Catalogue edit screen showed the stored EXCL-VAT price in the "Incl VAT" box. Typing R115.00 incl saved R100.00 (correct), but re-opening the item showed 100 in the incl box, so every plain Save re-converted that excl figure as if it were incl and quietly lowered the price: 100.00 → 86.96 → 75.62 … An agency that captures excl was never affected.

**Rule (unchanged, now honoured on the way OUT as well as IN).** `default_price` and `default_cost` are ALWAYS stored excl VAT (§14.3a). The editable price/cost box is in the agency's capture basis (incl or excl, `agencies.vat_capture_mode`). Save converts box → stored (`RentalCatalogueItemController::validated()` via `RentalJobCardVatService::splitAmount()`); the edit screen must convert stored → box with the matching inverse, using the item's own saved VAT type / custom rate.

**Fix.** `RentalCatalogueItemController::edit()` now passes `itemPrice` = `RentalJobCardVatService::catalogueDefaultPriceForLine()` (the same stored→capture-basis conversion the job card line prefill already used) and `edit.blade.php` seeds the Alpine `price` from it. The cost box was already converted correctly (`catalogueDefaultCostForLine()`); it is untouched. A validation-bounce still re-shows `old('default_price')`, which is already in the capture basis.

**Why it is stable to the cent.** The stored value has 2 decimals; the shown incl is `round(excl × (1+r), 2)`; the saved excl is `round(shown / (1+r), 2)`. One excl cent is worth (1+r)×0.01 of incl, so a shown incl identifies exactly one excl cent and dividing back lands within 0.005/(1+r) < 0.005 of it — the pair is the identity for every 2-decimal amount at any rate ≥ 0. Proven by test over R0.00–R3,000.00 at 15 %, 7.5 %, 14 %, 0.5 % and 33.33 %.

**Same-class check (every other place a price/cost goes through the capture-basis conversion):**
- Catalogue **default price** edit — WAS faulty, fixed here.
- Catalogue **default cost** edit — no fault (already converted on the way out, same rate as price).
- Catalogue **create** — no fault (empty box; save converts incl→excl).
- Catalogue **list** (Excl / VAT type / Incl columns) — no fault, read-only, computed from stored excl by `catalogueItemPrices()`.
- Catalogue **import** — no fault: separate named `price_excl` / `price_incl` / `cost_excl` columns, a one-way file→stored conversion, nothing re-displayed for re-saving.
- **Job card line** unit price / line total / unit cost — no fault: stored AS TYPED in the capture basis (no conversion on save), the card freezes the mode at quote/completion (§14.21), and the edit guard compares typed vs stored with a 0.004 tolerance.
- **Job card line prefill from a catalogue item** (job-card Add line, `RentalPricingService` step 5, crew-cost prefill) — no fault: a pure read of stored excl converted to the capture basis, never written back.

**Existing data.** Nothing rewritten. On QA1 the only agency on incl capture is the reconcile lane's own test agency; its single catalogue item (`ZZ-INCL`) was archived and sits at 75.62 — exactly the 100 → 86.96 → 75.62 drift from that reproduction. No real agency's catalogue is affected on QA1. (There is no catalogue audit trail, so "edited more than once" can only be read from `updated_at`; Staging / live were not looked at.)

**Tests.** `tests/Feature/RentalMaintenanceFlow/CatalogueVatEditRoundTripTest.php` — incl agency create → edit → plain Save ×10 keeps price and cost; odd-cent prices and a custom-rate VAT type ×10; the every-cent identity sweep; excl agency unaffected; switching the capture basis changes what is shown but never what is stored; job-card prefill does not drift.

**Real-browser check (QA1, 7 Oct, throwaway incl-VAT agency, archived afterwards).** Create typing R115.00 incl with Standard VAT → stored 100.00; the edit screen showed 115 in the Incl VAT box; five plain Saves in a row each left the stored price at 100.00; the catalogue list showed R100.00 / R115.00.

**Reported, not changed.** (a) An item created with NO price is stored as R0.00, not blank (the create form's box starts at 0 and posts it; an edit + plain Save keeps 0.00). `RentalPricingService` step 5 tests `default_price !== null`, so a catalogue item saved this way counts as "has a catalogue price of R0" and a line picking it prices at R0 instead of falling through to the agency markup. Same on excl and incl agencies — not a capture-basis fault. (b) The catalogue LIST's Archive button answers **405**: its form posts a plain POST to `corex.rental-catalogue-items.archive`, which is registered as `DELETE` (`routes/web.php` ~4078), and the form in `rental-catalogue-items/index.blade.php` (~106) has no `@method('DELETE')`. Nothing can be archived from the list screen until that line is added. (c) Changing the item's VAT type on the edit screen re-reads the figure in the box under the NEW rate (incl 115 under Standard becomes excl 115 under No VAT); the create screen behaves the same way.

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

---

## 17. Maintenance flow — cost vs selling, owner work terms, variations, emergency approval, external contractors, tenant dispute (FINAL, 6 Oct 2026; spec only — no app code yet)

**Author:** cc6. **Scope:** one end-to-end maintenance flow (fault → work order → job card / contractor → owner approval → completion →
tenant check), built to Johan's rulings of 6 Oct 2026 (10:15–10:25, after he tested job cards on QA1). **Reading guide:** §17.1 is what
exists; §17.2–§17.11 are the seven rulings (R0–R6) as rules; §17.12 the one status table; §17.13–§17.19 the cross-cutting design; §17.21 the
build split (a shared foundation lands first, then three parallel builds); §17.22 the decisions taken on the five open business questions. Everything in this
section is a decision, not a draft. Where it differs from an earlier section the difference is listed in §17.0.

### 17.0 The rulings as relayed, and what this section changes in the earlier text

**Provenance.** The rulings below reach this spec through the conductor's job brief of 6 Oct 2026, which says Johan ruled them between
10:15 and 10:25 after testing job cards on QA1 and that he "likes the job card barebone". Only the two short phrases in quotation marks
are Johan's words as quoted in that brief — **"Crew works on actual costs, not selling."** (R1) and **"always need owner to agree"** (R3b).
Every other line is the conductor's paraphrase, not a quote.

| Id | Ruling (paraphrase) | Section |
|---|---|---|
| R0 | One "Create work order" action on a fault report: Internal crew or External contractor. Internal creates the work order AND its job card together. The work order is what the owner and tenant see; the job card is the crew's working paper. A work order can still be created without a fault. | §17.3 |
| R1 | Every parts/labour line carries COST and SELLING. Office sets selling from cost: amount on top per line, % per line, % across all parts, or % across the whole job; agency default markup (parts %, labour %). Crew link shows and captures cost only. Owner sees selling only. Office sees cost, selling and margin. | §17.4 |
| R1b | Crew can price a job when the office asks, and add extras during a job with note and photo; crew-added lines never change the owner total until the office accepts and prices them. | §17.5 |
| R2 | Per rental property, agreed with the owner: (i) work up to R X without owner authorisation (the existing no-approval limit), (ii) up to Y % above an approved quote is auto-approved. Editable per property, who/when recorded; every system approval decision cites the term it used. | §17.6 |
| R3 | Variations: within Y % auto-approved and logged; beyond it a variation goes to the owner; work on the extra waits; crew sees approved/declined. | §17.7 |
| R3b | Emergency work: no office override. The office captures the owner's approval (who, how, when, why, optional attachment) with no cost attached; work proceeds; costs settle later and the owner sees the final amount flagged as emergency work. | §17.8 |
| R4 | External contractor: agent captures the contractor's quote and document → owner approves under the same property terms → work order sent to the contractor showing the owner approved → agent captures completion and any photos. No job card. | §17.9 |
| R5 | When work is reported done the tenant is told and may confirm or report "not complete" with photos and a note → work order AND job card become Disputed, office told, reopened to crew/contractor; every round kept; silence after an agency window counts as accepted; close is blocked while a dispute is open. | §17.10 |
| R6 | An agency-editable term printed on every quote/work order sent to an owner (the "estimate" wording). | §17.11 |

**What this section supersedes or restates** (the earlier text stays as history; the build follows §17):
1. **§14.21 "re-send revised quote replaces the old one and drops the approval"** applies only BEFORE the owner has approved. After approval,
   any increase goes through the variation path (§17.7) — raised automatically when lines are accepted or edited, never by a manual button; the "Re-send revised quote" button is hidden on an approved card and the Variation panel (with "Resend request") takes its place.
2. **§3.4a/§3a.1 "a work order raised from an approved fault report inherits `approved`"** is retired (§17.3.3): approval rides the quote and the
   property's work terms, not the fault.
3. **§14.4 / §14.14 / §14.27.3 price settings are restated in cost terms** (§17.4.7): `crew_link_show_prices` → `crew_link_show_costs`;
   `show_prices_on_printed_job_card` → `show_costs_on_printed_job_card`. `capture_prices_on_job_cards` keeps its name and meaning (master switch for
   pricing at all).
4. **§14.29 tenant/landlord "Jobs" keyed on job cards** is re-keyed on the work order (§17.3.5); the job-card API endpoints were first kept for compatibility and were REMOVED on 8 Oct 2026 (§16)
   but the portal shell stops using them.
5. **§14.27 "crew completed never closes the card"** stays; it now also OPENS a completion round (§17.10).
6. **`rentals-faults-work-orders.md` §6.5 / §13 (routing profiles, emergency spend limits that could bypass owner approval)** — that routing
   service was never built (`RentalFaultRoutingService` does not exist). R3b is the standing rule: **no component may bypass the owner's
   agreement for emergency work.** If routing is built later it may choose WHO is contacted, never skip the owner.
7. **`rentals-faults-work-orders.md` §5.3 "tenant confirms completion is evidence, never a gate"** is replaced by §17.10 (round-based, with dispute).
8. **`rentals-faults-work-orders.md` §5.1/§5.2 (contractor accept/decline, invoice upload)** remain unbuilt and out of scope here.

### 17.1 What exists today (verified on origin/QA1 `63576e08c`, 6 Oct 2026)

| Area | Fact | Where |
|---|---|---|
| Fault → work order | Only an outside-supplier work order can be raised from the fault screen: the form has `trade_type`, `title`, `description`; the controller accepts `assignment_type` but no view posts it. Gate: `approval_route === agency_appoints` and status not `work_order_raised/resolved/cancelled`. | `rental-fault-reports/show.blade.php:176-196`; `RentalFaultReportController.php:538-566`; `RentalWorkOrderService.php:57-90` |
| Fault → job card | `createFromFaultReport()` exists in the service but has **no button**; a work order raised from a fault starts `owner_approval_status=approved` (inherited). | `RentalJobCardService.php:188-198`; `RentalWorkOrderService.php:70-82` |
| Work order create | Radio "Who does the work?" (`internal` / `outside_supplier`); `internal` creates the job card; `createForProperty()` sends no notifications; a job card can also be created with no work order (`createStandalone`) and gets its work order lazily at "send quote". | `rental-work-orders/create.blade.php:38-45`; `RentalWorkOrderController.php:376-453`; `RentalJobCardService.php:63-176,472-495` |
| Statuses | Fault: reported, awaiting_approval, approved, declined, work_order_raised, owner_handling, resolved, cancelled. Work order: reported, ordered, in_progress, completed, cancelled. Job card: draft, quoted, approved, scheduled, in_progress, completed, cancelled. All are plain strings (no DB enum). | `RentalFaultReport.php:50-57`; `RentalWorkOrder.php:30-34`; `RentalJobCard.php:30-36` |
| No approval gate on the card | `RentalJobCard::schedule/start/complete` check nothing about owner approval; a draft card can be scheduled and becomes crew-visible. | `RentalJobCard.php:446,483,570` |
| Supplier side | Supplier database = `AgencyServiceProvider` (no rate/cost columns); `assignSupplier()` needs the selected quote's supplier; the supplier email is a plain `Mail::to` with no link and no owner-approval wording; supplier picker is not trade-filtered. | `DealV2/AgencyServiceProvider.php:19`; `RentalWorkOrder.php:588-627`; `RentalWorkOrderService.php:209-222` |
| Contractor link | A per-work-order contractor link (quote / photo / mark-done) exists in code but **nothing mints it** — no button, no controller. | `ContractorSecureLinkController.php`; `RentalSecureAccessTokenService.php:189` (`issueFor` called only from a test) |
| Quotes | `rental_work_order_quotes`: supplier (nullable for job-card quotes), amount, date, document (private disk), detail, selected, revision/superseded. Agent captures via `RentalWorkOrderQuoteController` (`manage_quotes`). | migrations `2026_09_29_100200`, `2026_10_04_210600`, `2026_10_08_120000` |
| Spend limit | `properties.rental_no_approval_spend_threshold` (null = agency default `rental_work_order_settings.no_approval_spend_threshold`, R500); resolved by `RentalWorkOrderSetting::thresholdFor(Property)`; edited on the property Rental tab (`PUT /{property}/rental-details`). A second threshold input at `properties/show.blade.php:4415` posts to a form whose controller does not save it (silently dropped). | `RentalWorkOrderSetting.php:99-106`; `PropertyController.php:2751,2796`; `show.blade.php:4415,4705` |
| Owner approval today | `selectQuote()` sets `not_required` (≤ limit) or `pending` (> limit) and OVERWRITES any earlier decision; the owner decides in the **landlord portal** Decisions tab (`approve`/`decline`, no pending-check) or the agent captures evidence (`rental_approvals`: whatsapp/email/verbal_note/portal). | `RentalWorkOrder.php:318-340,390-459`; `ClientLandlordRentalsController.php:345-422`; `shell.blade.php:236-263` |
| Send quote to owner | `sendToOwnerAsQuote()` builds the quote PDF, records+selects a quote with the VAT-inclusive amount, then mails the owner **a plain `RentalWorkOrderOwnerMail` with no attachment and (revision 1) no amount**. A second plain mail "decision needed" (no link) goes to landlord contacts when over the limit. | `RentalJobCardService.php:518-624`; `RentalWorkOrderOwnerMail.php`; `RentalPortalNotificationService.php:38` |
| Job card lines | One price field: `unit_price`, `line_total` (+ VAT snapshots). **No cost, markup, margin or selling concept anywhere** in rentals code or settings. `total_amount` = sum of live `line_total` (basis = agency capture mode); the amount sent to the owner is `RentalJobCardVatService::inclusiveTotal`. Catalogue item has `default_price` only. | `RentalJobCardLine.php:26-47`; `RentalJobCard.php:383-386`; `RentalJobCardVatService.php:352-357`; `RentalCatalogueItem.php:43-55` |
| Work order amounts | `rental_work_orders.cost_amount` is set at completion — for an internal job it is the card's VAT-inclusive **selling** total (`paid_by=owner`), for external it is what the agent types. The name is misleading: it is the owner-facing amount. | `RentalJobCardService.php:670-698`; `RentalWorkOrderController.php:661-685` |
| Crew link | Crew (no login) can tick tasks, upload photos, "Mark work completed" (typed name + tick; never closes the card). **No parts, no costs, no pricing.** Prices (`crew_link_show_prices`, default off) render `line_total` (selling) — a leak once cost/selling split exists. | `CrewJobService.php:117-210`; `_job-body.blade.php:108-125`; `RentalCrewScheduleService.php:191` |
| Tenant | Portal API can report a fault and `POST work-orders/{id}/confirm` (fixed yes/no + note, **no photos**, only when status is `completed`, no reopen, nobody notified); the portal web shell has **no confirm control**. | `ClientTenantRentalsController.php:289-317`; `RentalWorkOrder.php:702-731` |
| Landlord | Portal Decisions tab: approve/decline work orders (quote amount only — no lines, no PDF), fault decisions; Jobs tab shows job cards. No variation, emergency or dispute concept exists anywhere. | `shell.blade.php:236-306` |
| Mail path | Only crew mails + the landlord crew-completion mail use the agency mailbox path (`RentalMailDispatcher`). Owner, tenant, supplier and "decision needed" work-order mails are plain `Mail::to`. `BaseSignatureMail` has no attachments; a Mailable may override `attachments()` (`RentalNoticeMail.php:50`). | `RentalMailDispatcher.php`; `BaseSignatureMail.php:51-106` |
| Tenancy log | `LeaseTimelineService::TYPES` = application, lease, inspection, fault, work_order, job_card, notice, rental_notice; fault/work-order builders emit one row each (no approval/quote/outcome rows); job-card builder emits opened / crew completed / completed / photos. | `LeaseTimelineService.php:29,190-308` |
| Tenant confirm fields | The columns are `tenant_confirmed_at`, `tenant_confirmed_fixed`, `tenant_confirmed_by_contact_id`, `tenant_confirmation_note` (work order) and the same on the job card. (§14.1's name `tenant_confirmed_completion_at` does not exist.) | `2026_10_07_100500_add_tenant_confirmation_to_rental_work_orders_table.php` |
| Reports | `jobCards` report labels the VAT-aware selling total "Total cost"; `workOrders` and `landlordPropertyActivity` sum selected-quote-or-`cost_amount`. No margin anywhere. | `RentalReportService.php:311,456,546,956` |
| Settings / wizard | Work-order settings: `no_approval_spend_threshold`, `capture_prices_on_job_cards`, `show_prices_on_printed_job_card`, `completion_requires_photo`, `overdue_reminder_days`. Portal settings: crew/contractor keys (§14.27.3). Wizard arms `rental_work_orders` and `rental_portal` name every key. | `RentalWorkOrderSetting.php:17-120`; `RentalPortalSetting.php:20-202`; `AgencySetupWizardController.php:623-653` |

### 17.2 Vocabulary and invariants (every build uses these words the same way)

- **Cost** — what the work actually cost the agency (the crew's receipt / rate). **Selling** — what the owner is charged. A line's selling is
  stored in the EXISTING columns `unit_price` / `line_total`; cost is new (`unit_cost` / `cost_total`). Cost and selling are held on the **same VAT
  basis** (the agency's capture mode, excl or incl); the line's VAT type applies to both; **margin is always computed on the excl-VAT figures**.
  "VAT rules stay as built": no new VAT behaviour.
- **Owner-facing amount** — the VAT-inclusive selling total (`RentalJobCardVatService::inclusiveTotal`, or `total_amount` for a non-VAT agency); for
  external work, the contractor's quote amount as captured. **Every threshold and tolerance in this section is tested against the owner-facing amount.**
- **Approved amount (`approved_amount`)** — the baseline the owner (or the no-approval-limit rule) has covered: the selected quote's owner-facing
  amount when approval was granted, or the new total after an owner-approved variation. Auto-approved variations do NOT move it (§17.7.2).
- **Approval basis (`approval_basis`)** — which term the current approval relied on: `no_approval_limit`, `owner_decision`, `variation_tolerance`,
  `emergency_owner_agreed`. Always paired with a row in `rental_approval_decisions` (§17.6.4).
- **Condition vs stage.** Stages are the stored `status` values. Awaiting-owner-approval, Variation-awaiting-owner and Emergency-approved are
  **conditions** that can coexist with a stage (a job can be In progress and have a variation pending), so they are stored as their own fields and
  shown as chips — see §17.12.
- **Line states (`office_status`)** — `crew_draft` (crew still editing), `awaiting_office` (sent to office), `accepted` (counts), `rejected` (office
  said no), `declined_by_owner` (owner declined the variation). **Only `accepted` lines count anywhere** — totals, quote PDFs, VAT breakdown, content
  signature, "what to load", reports (§17.4.6 lists every reader).
- **Round** — one "work reported done" event and the tenant check that follows it (§17.10).
- **Open dispute** ≡ work order `status = disputed`.

### 17.3 R0 — one "Create work order" action; the work order is the record, the job card is the crew's paper (Build 3)

**17.3.1 The action.** The fault report screen gets ONE button, **"Create work order"** (replacing "Raise work order" in the header and in the owner
approval card; permission stays `rental_fault_reports.raise_work_order`). It opens a short form: **Who does the work?** — **Internal crew**
(default) / **External contractor** — plus title and description (prefilled from the fault) and, for External, trade type. Submit:

| Choice | Creates | Lands on |
|---|---|---|
| Internal crew | the work order AND its draft job card in one transaction (`RentalJobCardService::createFromFaultReport`, as built) | the job card |
| External contractor | the work order only (`assignment_type = outside_supplier`), no job card | the work order |

The same chooser exists on the standalone work-order create form (as built: "Who does the work?"). **"New Job Card"** on the job-card list and the
lease hub keeps working but is an alias: it redirects to the work-order create form with Internal preselected, so there is exactly ONE creation form
for maintenance work. `RentalJobCardService::createStandalone()` stays for service callers but now **always creates (or links) the work order up
front** — no job card ever exists without its work order (§14.1's stated rule, finally enforced); lazy creation at "send quote"
(`ensureWorkOrderForQuote`) remains only as a safety net for pre-existing rows.

**17.3.2 Fault report gate relaxed.** `RentalWorkOrderService::fromFaultReport()` no longer demands `approval_route = agency_appoints`. A work order
may be created from a fault in status `reported`, `awaiting_approval`, or `approved` with route `agency_appoints`; it is refused for `declined`
(the owner said no), `owner_handling` (the owner is doing it), `work_order_raised`, `resolved`, `cancelled` — each with a plain-language message.
Reason: with cost now unknown until the crew prices or the contractor quotes, the owner can only approve a number AFTER the work order exists
(§17.9 step order). The fault-level owner decision (agency appoints / owner handles / decline) stays available and unchanged for the cases it was
built for.

**17.3.3 No inherited approval.** A work order created from a fault no longer starts `owner_approval_status = approved`. It starts `not_required`
with `approved_amount = null`; authorisation is decided by the gate (§17.6) when a quote is selected, when the card is scheduled/started, or when an
emergency approval is captured. (`selectQuote` already overwrote the inherited value whenever the quote exceeded the limit, so no working path
depends on the inheritance.)

**17.3.4 Creation notifications are the same on every path.** `createForProperty()` today sends nothing and never fires
`rental_work_order.created`. After this build every creation path (fault, work-order form, inspection follow-up, job-card alias) calls the same
`RentalWorkOrderService::announceCreated()` which fires `rental_work_order.created` to the property's agent. The owner/tenant creation mails are
replaced (§17.16) — no owner mail is sent at creation any more for an internal job (the owner is told when a quote or variation needs them, or at
completion); the tenant creation acknowledgement for non-fault work orders is left as built.

**17.3.5 The work order is what the owner and tenant see.** The portal shell's **Jobs** tab (tenant and landlord) lists **work orders**, not job
cards. A work order's client payload (`RentalWorkOrderClientViewService`, new, built by extending `RentalJobCardClientViewService`'s rules) carries:
title, plain stage label (§17.12), who is doing it (our team / external contractor — name only for the contractor), scheduled/due when an internal
card exists, approval summary (landlord only), completion rounds and their outcomes, and the permitted photos (the `crew_photos_visible_to_clients`
rule, plus tenant dispute photos). Tenant: never a price. Landlord: owner-facing amount only. New client endpoints:
tenant `GET rentals/work-orders` (index; show already exists) and landlord `GET rentals/landlord/work-orders/{workOrder}`; the job-card endpoints
stay registered and tested but are no longer linked from the shell. The fault report's tenant view shows the linked work order's stage.

### 17.4 R1 — cost and selling (Build 1)

**17.4.1 Principle.** The crew works on actual costs (a receipt, a rate). The office decides what the owner is charged. A line therefore has two
money figures and a record of how selling was arrived at.

**17.4.2 Line columns** (`rental_job_card_lines`, foundation migration F1). New: `unit_cost` decimal(10,2) null, `cost_total` decimal(10,2) null,
`markup_type` string(10) null (`percent` | `amount`), `markup_value` decimal(10,2) null, `selling_basis` string(20) default `manual`
(`manual` | `line_markup` | `job_markup` | `catalogue_price` | `agency_default`), plus the crew/office state columns of §17.5.2. Existing rows:
`selling_basis = manual`, `unit_cost/cost_total = null` (margin shows "—, no cost recorded"; **no cost is ever back-filled or invented**).
`unit_price` / `line_total` keep their meaning and become explicitly **selling**; every existing reader of them (quote PDF, VAT breakdown, owner
payloads, reports) is therefore already selling-only and needs no change except where §17.4.6 says so.
Cached on the card (`rental_job_cards`): `total_cost` decimal(10,2) null (sum of accepted `cost_total`); `total_amount` stays the selling sum.

**17.4.3 How selling is resolved** — one service, `App\Services\Rentals\RentalPricingService`, one ordered rule list (first match wins):

| # | Rule | `selling_basis` | Formula |
|---|---|---|---|
| 1 | The office typed a selling price on this line | `manual` | `unit_price` as typed; `line_total = round(qty × unit_price, 2)` |
| 2 | The line has its own markup | `line_markup` | `percent`: `unit_price = round(unit_cost × (1 + p/100), 2)`, `line_total = round(qty × unit_price, 2)`. `amount`: `line_total = cost_total + amount` (a set amount on top of the LINE, not per unit); `unit_price = round(line_total / qty, 2)` (display only — `line_total` is authoritative for `amount` lines) |
| 3 | The card has a % for this kind of line (`markup_parts_percent` for part lines, `markup_labour_percent` for labour lines) | `job_markup` | as `percent` |
| 4 | The card has a % across the whole job (`markup_all_percent`) | `job_markup` | as `percent` |
| 5 | The catalogue item has a default selling price (`default_price`) | `catalogue_price` | `unit_price` = the catalogue default, in the agency's capture mode (the existing `catalogueDefaultPriceForLine`) |
| 6 | Agency default markup for the line's kind (`default_parts_markup_percent` / `default_labour_markup_percent`) | `agency_default` | as `percent` |

A line with no `unit_cost` and no manual price has no selling yet (blank, not 0) and blocks "Send to owner as quote" with "price every line first".
Rule 6 with a 0 % default makes selling = cost: the default is deliberately neutral (§17.14), and a line priced that way shows the chip
"no markup applied" so the office sees it.

**17.4.4 Office actions** (job card screen, gated by `rental_job_cards.price`): per-line — edit cost, edit selling (→ `manual`), set % or amount
markup (→ `line_markup`), "Back to automatic" (clears markup/manual so rules 3-6 apply again); card-level **Pricing panel** — three optional
percentage boxes **All lines % / Parts % / Labour %** with Apply (writes the card columns, reprices every line that is not `manual`/`line_markup`,
logs one history row "Parts markup set to 20 %"); clear to remove. Catalogue items stay general ("Plumbing parts") with a free **description per line**
("tap", "plumber's tape") — the description is the line's own text, as built; the catalogue item supplies kind, unit and defaults only.
`rental_catalogue_items.default_cost` (new, nullable, same VAT basis note as `default_price`) prefills cost on add; the catalogue form and CSV import
(§14.19) gain an optional Cost column. `RentalPricingService::repriceCard()` is called after any line, markup or setting change; it never touches a
`manual` or `line_markup` line.

**17.4.5 Screens.** Office job-card lines grid (the §14.24 single grid) gains **Cost**, **Selling** and **Margin** columns (and a small basis label under
selling: "set by hand / +20 % line / parts 20 % / job 15 % / catalogue / agency default"); the totals box shows Cost total, Selling total (excl/VAT/incl as
built) and **Margin (R and % of selling, on excl-VAT)** — visible only with `rental_job_cards.view_costs` (§17.15); without it the Cost and Margin
columns and totals are absent (server-side, not CSS). Add-line row: cost field, selling field (auto-filled and greyed until typed), VAT as built.
Per job: a "n lines have no cost recorded" note so a partial margin is never presented as complete.
**Printouts:** the worker-facing printed job card shows COST, never selling, and only when `show_costs_on_printed_job_card` is on (default off); the
owner quote PDF, the variation notice and the final statement show SELLING only and never any cost or margin word; tenant surfaces show no money.

**17.4.6 Every reader of lines must count only `accepted` lines (fix the class).** The build greps and fixes every one of these, with a test per
reader: `RentalJobCard::recalcTotal()` (selling and the new `total_cost`), `RentalJobCardVatService::breakdown()/snapshot()/refreshLineSnapshot()`,
the quote `content_signature`, `RentalDocumentPdfService` quote/print partials (`_pdf-lines-table`), `CrewJobService::payload()` (materials/labour),
`RentalCrewScheduleService::materials()`, `RentalReportService::jobCards()`, `RentalJobCardService::sendToOwnerAsQuote()` (line-count precondition),
`ensureWorkOrderForQuote`, the catalogue-usage counts, and the lines table partial (awaiting lines render in their own block, §17.5.4).

**17.4.7 Settings restated in cost terms.** `crew_link_show_prices` → **`crew_link_show_costs`**: the crew can ALWAYS type a cost on a line they add;
this switch only decides whether the crew also sees the cost figures the OFFICE entered on existing lines (default off). It never shows selling,
markup, margin or a job total. `show_prices_on_printed_job_card` → **`show_costs_on_printed_job_card`** (default off, same meaning). The foundation
migration copies each old value across and the foundation commit also switches `CrewJobService::payload()`, `RentalCrewScheduleService::materials()`
and the printed card from selling columns to cost columns **in the same commit** as the rename, so selling can never appear on a crew surface at any
point between the foundation and Build 1 (existing lines have no cost, so those surfaces show "—" until costs exist).

### 17.5 R1b — the crew prices a job and adds extras from the crew link (Build 1)

**17.5.1 Two uses of one panel.** The per-job crew link (and the same job opened from the crew page) gets a **Parts & labour** panel:
(a) **Price this job** — the office presses "Ask crew to price this job" on the card (permission `rental_job_cards.share`, optional note to the crew);
the crew sees a banner with that note; (b) **Extras during the job** — available on any open card (no request needed). Both use the same entry form:
**type** (Part / Labour), optional **general catalogue item** (names only — no prices shown), **description** (free text), **quantity**, **unit**,
**cost** (required, the crew's actual cost), and for extras a **note** and up to **3 photos** per line (stored through
`RentalJobCardService::storePhoto()` with `uploaded_via = crew_link|crew_page`, linked by the new `rental_work_order_photos.rental_job_card_line_id`).

**17.5.2 Line columns for crew entries** (F1): `origin` string(20) default `office` (`office` | `crew_pricing` | `crew_extra`), `office_status` string(20) default
`accepted`, `crew_note` text null, `crew_added_by_label` string(191) null (crew name; "via crew link/page"), `crew_added_at`,
`office_decided_by_user_id`, `office_decided_at`, `reject_reason` text null, `rental_job_card_price_request_id` null, `rental_work_order_variation_id` null.

**17.5.3 Flow.**
1. Crew adds lines → each is `crew_draft` (visible only to the crew; they may edit or remove their own drafts — removal is a soft archive).
2. Crew presses **Send to office** (confirm tick; no typed name needed — the link is the credential; label/IP/device recorded). All their drafts flip to
   `awaiting_office`; for a price request the request becomes `submitted`. Office is notified **once per send** (`rental_job_card.crew_lines_submitted`,
   in-app to the property's agent; never per line).
3. Office sees an **"Added by crew — awaiting office"** block on the card (not in the totals). Per line: **Accept** (cost editable; selling resolved by the
   §17.4.3 rules, overridable; becomes `accepted`, counts everywhere), **Reject** (reason required; crew sees "not accepted — reason"). "Accept all" applies the
   rules to every line. After a batch of accepts the build calls `RentalApprovalGateService::assessAfterLineChange()` once (§17.7) — before approval
   this just feeds the next "Send quote"; after approval it may raise a variation.
4. **Crew-added lines never change the owner-facing total, quote PDF, owner payload or tenant payload until accepted.** Awaiting/rejected lines are in no
   total, anywhere.
5. Crew sees on its link: its own lines with a state chip (Draft / Sent to office / Accepted / Not accepted — reason) and, for lines that became part of a
   variation, **Approved / Awaiting owner — do not start / Declined by owner** (§17.7). Costs shown are the crew's own entries (plus office-entered cost
   when `crew_link_show_costs` is on). Never selling, markup, margin, owner details or approval amounts.

**17.5.4 Price request** — `rental_job_card_price_requests` (F3): `id, agency_id, rental_job_card_id, requested_by_user_id, requested_at, note,
status (open | submitted | closed | cancelled), submitted_at, submitted_label, submitted_ip, submitted_device, closed_at, closed_by_user_id`. One open
request per card; re-asking after `submitted` opens a new one. The office closes a request implicitly when it has accepted or rejected every submitted line
(or explicitly "Close request"). On the crew page, a card with an OPEN price request appears under a **"To price"** group even while it is Draft/Quoted
(the page otherwise lists only Approved/Scheduled/In progress/Disputed cards, `CREW_VISIBLE_STATUSES` + `disputed`, §17.12); the per-job link is
already live on a Draft card.

**17.5.5 Office screens:** "Ask crew to price this job" button (disabled with a reason if the card has no crew assigned or no live link — it offers to
generate/email the link in the same step, reusing the existing crew-link panel endpoints); the awaiting block; a request status chip in the card header
("Pricing requested" / "Priced by crew — awaiting you"). Card-list tile/filter "Needs pricing" (cards with open request or awaiting lines).
The "what to load" note per line stays as built.

**17.5.6 Audit** (`rental_job_card_updates`): `pricing_requested`, `pricing_submitted`, `crew_lines_sent`, `crew_line_accepted`, `crew_line_rejected`,
`markup_set`, `line_priced`. Crew-originated rows carry actor null and note "{crew} — via crew link/page (IP …)". Domain events:
`RentalCrewLinesSubmitted`, `RentalCrewLinesDecided`.

### 17.6 R2 — owner work terms per rental property, and the one approval gate (Build 2)

**17.6.1 The two terms.** Per rental property, agreed between the agency and the owner:
(i) **No-approval limit** — work whose total owner-facing amount is up to R X may be done without the owner's authorisation. This is the EXISTING
`properties.rental_no_approval_spend_threshold` (null = agency default `rental_work_order_settings.no_approval_spend_threshold`, default R500, resolved by
`RentalWorkOrderSetting::thresholdFor(Property)`) — **reused, not duplicated**. The limit is per JOB (the job's total), never per line.
(ii) **Variation tolerance** — an increase of up to Y % above the approved amount is auto-approved. New: `properties.rental_variation_tolerance_percent`
decimal(5,2) null (null = agency default `rental_work_order_settings.variation_tolerance_percent`, default **0**, i.e. every increase goes to the owner until an
agency or property says otherwise).

**17.6.2 Recording and editing.** One place to edit both: a **"Work terms agreed with the owner"** panel on the property Rental tab, own route
`PUT corex/properties/{property}/rental-work-terms` (`corex.properties.rental-work-terms.update`), own permission `rental_work_orders.manage_work_terms`
(§17.15). Fields: no-approval limit (R), variation tolerance (%), and an optional "agreed how / when" text. A blank field means "use the agency
default", and the panel shows the inherited value ("Agency default: R500"). Every save writes an append-only row to
`rental_property_work_term_changes` (F4: `id, agency_id, property_id, field (no_approval_limit | variation_tolerance), old_value, new_value (null = inherit),
changed_by_user_id, changed_at, agreed_with, note`) and stamps `properties.rental_work_terms_updated_at` / `…_by_user_id`; the panel shows "Last changed by
{name} on {date}" and a History expander. `PropertyController::updateRentalDetails` **stops accepting** the threshold field; the dead duplicate input at
`properties/show.blade.php:4415` is removed; the lease hub keeps its read-through and now shows both terms. Property terms are per-property business
data and are **not** in the Setup Wizard (same precedent as §8: the per-property override lives on the property); the agency defaults are (§17.14).

**17.6.3 `App\Services\Rentals\RentalApprovalGateService` — the only place an approval decision is made by the system.** No other code decides "needs the
owner or not". Methods (signatures are in the foundation):
- `termsFor(Property): WorkTerms` — `{ noApprovalLimit, limitSource (property|agency_default|constant), variationPct, pctSource }`.
- `evaluateQuote(RentalWorkOrder, float $ownerFacingAmount, ?User $by): GateDecision` — the existing `selectQuote()` rule moved here unchanged: amount ≤ limit →
  `auto_approved`, basis `no_approval_limit`, `owner_approval_status = not_required`, `approved_amount = amount`; otherwise `needs_owner`, status `pending`.
  `selectQuote()` calls it; behaviour for a first quote is identical to today.
- `evaluateVariation(RentalWorkOrder, float $newTotal, ?User $by): GateDecision`:
  1. emergency basis → `emergency_covered` (no baseline, no gate, §17.8);
  2. `newTotal ≤ approved_amount` → `no_change` (a decrease never needs approval);
  3. `newTotal ≤ noApprovalLimit` → `auto_approved`, basis `no_approval_limit` (term i);
  4. `variationPct > 0` and `newTotal ≤ approved_amount × (1 + pct/100)` → `auto_approved`, basis `variation_tolerance` (term ii);
  5. otherwise `needs_owner` → an open variation (§17.7).
- `assessAfterLineChange(RentalJobCard, ?User): ?RentalWorkOrderVariation` — called after any change that can raise the accepted total (accepting crew lines,
  adding/editing/restoring a line, repricing). If the WO has no `approved_amount` yet (quote not yet approved) it does nothing — the normal "Send quote /
  re-send revision" path applies. Otherwise it calls `evaluateVariation` and creates/updates the variation.
- `authoriseToProceed(RentalWorkOrder): GateDecision` — see 17.6.5.
- `recordVariationDecision(...)`, `recordEmergency(...)` — the writers for §17.7 and §17.8.
Every call that reaches a decision writes a `rental_approval_decisions` row (17.6.4): auto_approved, needs_owner, emergency_covered, an owner decision, or blocked.
"No change" (a decrease, or an increase on a work order that has no approved amount yet) is not a decision and writes nothing.

**17.6.4 Every decision cites the term it relied on** — `rental_approval_decisions` (F5, append-only: no `updated_at`/`deleted_at`): `id, agency_id,
rental_work_order_id, rental_work_order_variation_id null, rental_work_order_quote_id null, decided_by (system | user | owner | emergency), decided_by_user_id null,
decided_by_contact_id null, decision (auto_approved | needs_owner | approved | declined | blocked | emergency_covered), basis (no_approval_limit |
variation_tolerance | owner_decision | emergency_owner_agreed | legacy_grandfathered), term_key (no_approval_limit | variation_tolerance | owner_decision |
emergency), term_value decimal null, term_source (property | agency_default | constant | owner | emergency) null, amount_tested decimal, baseline_amount decimal
null, limit_amount decimal null (the computed ceiling the amount was tested against), note text, created_at`. The work order screen's **"Why was this
approved?"** line is read from the latest row, in words: "Auto-approved on 6 Oct 14:02 — R620 is within the owner's no-approval limit of R800 (set on this
property)" / "…within the owner's agreed 10 % tolerance (R2,000 + 10 % = R2,200; this property)" / "Approved by the owner in the portal on …" / "Approved as
emergency work on … (owner agreed by phone to {name}, recorded by {user})". The same sentence is stored in `note` so a later change of the term never rewrites
history. These rows also feed the tenancy log (§17.16).

**17.6.5 Work does not start without an authorisation.** New guard `RentalApprovalGateService::authoriseToProceed(RentalWorkOrder)`, enforced in the
models/services (not only controllers) at: `RentalJobCard::schedule()`, `RentalJobCard::start()`, `RentalWorkOrder::startProgress()`,
`RentalWorkOrder::assignSupplier()` (already gated by status), and **crew completion** (`recordCrewCompletion` refuses with "This job has not been approved
by the owner — contact the office"). A work order is authorised when ANY of: an active emergency approval exists; `owner_approval_status = approved`;
`owner_approval_status = not_required` AND (a quote is selected OR the card's current owner-facing amount ≤ the no-approval limit — evaluated now, recorded
as an `auto_approved` decision, and `approved_amount` set to that amount); `approval_basis = legacy_grandfathered`. It is NOT authorised when approval is
`pending`/`declined`, or when nothing is priced yet and no emergency approval exists ("price the job or send the quote first"). Pricing visits do not need
scheduling: the price request goes to the crew link directly (§17.5.4). **In-flight rows:** the foundation migration sets `approval_basis =
legacy_grandfathered` on every work order whose job card is `scheduled`/`in_progress` or whose status is `ordered`/`in_progress` at deploy time, so no
existing job is suddenly blocked; the guard only affects transitions made after deploy.

**17.6.6 Landlord decision endpoints** (`workOrderDecision`, `faultReportDecision`) now check that the record is actually awaiting a decision
(`pending`) and refuse otherwise with a 422 and a plain message (today they accept a decision on any open record).

### 17.7 R3 — variations (Build 2; line side in Build 1)

**17.7.1 When one is raised.** `assessAfterLineChange()` (internal jobs) or a higher quote selected after approval (external jobs, §17.9.4) finds the new
owner-facing total above the approved baseline. The §14.21 "re-send revised quote" behaviour is used only while `approved_amount` is null.

**17.7.2 Rules.** Compared with `approved_amount` only (the amount the owner — or the no-approval limit — actually covered), so small auto-approved steps
cannot creep: 10 % means 10 % above what the owner approved, once. Outcome per §17.6.3 step table:
- **within term** → `rental_work_order_variations` row with `status = auto_approved`, decision row written, `approved_amount` is NOT moved, lines stay
  `accepted`, the crew sees "Approved" on those lines, the landlord gets an information mail when `notify_landlord_on_auto_variation` is on (default on),
  the property's agent gets an in-app note;
- **beyond term** → `status = awaiting_owner`; lines stay `accepted` in the office view but are **flagged awaiting owner**; **work on the extra does not
  proceed** (crew chip "Awaiting owner — do not start"; the card/WO show the chip "Variation awaiting owner"; the main job continues on the approved scope);
  the owner is asked (17.7.4);
- **owner approves** → `status = approved`, `approved_amount = new_total`, basis `owner_decision`, decision row written;
- **owner declines** → `status = declined`; the variation's lines become `declined_by_owner` (out of every total); the crew sees "Declined by owner"; the office
  may reprice/re-add and a new variation is raised;
- further accepted extras while a variation is `awaiting_owner` join the open one and bump `revision`; a decision must carry the revision it saw (a stale
  revision gets a 409 "this request changed — please refresh"); the owner always sees the latest;
- total falls back to ≤ `approved_amount` → the open variation becomes `withdrawn`; cancelling the work order/card withdraws open variations.

**17.7.3 `rental_work_order_variations`** (F5): `id, agency_id, rental_work_order_id, rental_job_card_id null, rental_work_order_quote_id null, revision int default 1,
status (awaiting_owner | auto_approved | approved | declined | withdrawn), origin (crew_lines | office_edit | external_quote), baseline_amount, extra_amount (new_total −
baseline), new_total, price_change_amount (the part of the increase NOT explained by added lines = edits to approved lines), term_basis null, term_value null,
term_source null, note text null, term_text text (the R6 wording snapshot), raised_by_user_id null, raised_at, mail_sent_at null, decided_at null,
decided_by_user_id null, decided_by_contact_id null, decided_via (portal | agent_capture) null, decision_note null, created_at, updated_at`. No deletes
(withdrawn instead). Lines link by `rental_job_card_lines.rental_work_order_variation_id`. `rental_approvals` gains `rental_work_order_variation_id` (nullable; the
existing "exactly one of fault report / work order" rule becomes "exactly one of fault report / work order / variation").

**17.7.4 What the owner receives and does.** Mail (agency mailbox path, §17.16) "Extra work needs your approval — {address}" with an attached **Variation
notice PDF** (`RentalDocumentPdfService::variationNoticePdf`): original approved quote (amount, date, revision), the extra work (selling lines only, the crew's
note and up to 6 crew photos), new total (excl/VAT/incl as the agency's VAT mode), the R6 term, and how to answer. Portal: the landlord **Decisions** tab gains
a variation card (original, extra work with photos and note, new total, **Approve / Decline** + note) backed by
`POST /api/v1/client/rentals/landlord/variations/{variation}/decision` (`client.rentals.landlord.variations.decision`; body `decision`, `revision`, `note`);
`GET …/decisions` lists open variations. The agent can also capture the owner's written reply on the work order (new "Record variation decision" using the
existing evidence form: whatsapp/email/verbal_note + text/screenshot, permission `rental_work_orders.record_approval`; it writes a `rental_approvals` row
tied to the variation). Both routes call `RentalApprovalGateService::recordVariationDecision()`.

**17.7.5 Screens.** Office work order and job card: "Variation" panel (status, original → extra → new total, the lines, owner decision with who/how/when,
the cited term, "Resend request"); crew link: per-line chips (17.5.3 step 5); owner: portal card + mail; tenant: nothing; tenancy log row on raise and on decision.

### 17.8 R3b — emergency work: the owner always agrees; the office captures it (Build 2)

**17.8.1 There is no override.** No role, setting or routing rule lets emergency work start without the owner's agreement. The crew phones the office with
the reason; the office phones/messages the owner; the office **captures the owner's approval against the work with no cost attached**; the work proceeds;
costs are captured and settled later.

**17.8.2 `rental_emergency_approvals`** (F5, append-only except void): `id, agency_id, rental_work_order_id, approved_by_name (who at the owner's end — required),
owner_contact_id null (picker limited to the property's landlord contacts), approved_via (phone | whatsapp | email | in_person | other), approved_at (when the owner
agreed — required, not in the future, may predate the entry), reason text (why it is an emergency — required), reported_by_crew_name null (who phoned the office),
notes text null, attachment_path null (private disk; image/pdf ≤ 10 MB — e.g. a WhatsApp screenshot), recorded_by_user_id, created_at, voided_at null,
voided_by_user_id null, void_reason null`. **No amount column** — by ruling nothing about cost is attached. One active (un-voided) record per work order;
correcting a mistake = void with a reason + record a new one. No hard delete.

**17.8.3 Effect on the work order.** Recording sets `owner_approval_status = approved`, `approval_basis = emergency_owner_agreed`, `approved_amount = null`,
`rental_work_orders.emergency_approval_id` (F5), and a decision row (`decided_by = emergency`). Consequences, all in the gate/services:
- `authoriseToProceed()` is true; the card may be scheduled/started and the crew may complete;
- **no variation and no threshold test applies** to emergency work (there is no baseline); the final amount is simply settled later;
- `selectQuote()` must NOT downgrade an emergency-approved work order to `pending` when a quote/statement is sent — it records the quote and leaves the
  approval alone (guard on `approval_basis`);
- voiding the record re-runs `evaluateQuote` on whatever is selected (else `pending`) and tells the office plainly if work is already under way.
- **The owner sees the final amount flagged.** At close the **final statement** (selling only; §17.16) carries the banner "Approved as emergency work on
  {date}" and the same flag shows on the owner's portal work-order card and on any quote PDF produced for that work order.

**17.8.4 Where it appears.** Work order: an "Emergency approval" panel (button "Record owner's emergency approval", permission
`rental_work_orders.record_emergency_approval`; read-only record once saved; "Void" with reason). Job card: header chip "Emergency — owner agreed by {via} on {date}"
with a link to the work order. Crew link: chip "Approved to proceed (emergency)" only — no owner name, no contact. Quote/statement PDFs: the banner above. Fault
report: read-only line via the linked work order. Tenancy log: "Emergency approval recorded — owner agreed by phone (recorded by {user})". Staff notification:
none (the recorder is the agent); audit row on the work order (`emergency_approved`, `emergency_voided`). Domain event `RentalEmergencyApprovalRecorded`.

### 17.9 R4 — external contractor flow (Build 2; creation in Build 3)

Johan's order, confirmed: **fault or work order created → External selected → agent receives the contractor's quote → agent captures the quote value and uploads
the quote document → owner approves the quote (same property terms) → the work order is sent to the contractor, showing the owner approved → completion
captured by the agent, photos uploaded by the agent.** No job card for external work.

1. **Create** (§17.3): External; status `reported`, `assignment_type = outside_supplier`.
2. **Capture the quote** (existing `RentalWorkOrderQuoteController`, `manage_quotes`): supplier (existing directory, as built), amount (owner-facing — the contractor's own quote), date,
   document and/or detail (as built). The agency's **external-quote fee** (§17.9.1a, Decision 1) is applied at capture: the owner sees the selling amount, the office sees quote, fee and total.
3. **Select it** → `selectQuote()` → gate (`evaluateQuote`): within the no-approval limit → auto-approved citing term (i); else `pending` and the owner is asked
   (mail with the **quote document attached**, amount, R6 term; portal Decisions card as built; agent capture as built). The owner's decision rides the same
   terms and the same `rental_approval_decisions` log.
4. **A higher quote after approval = a variation.** If a quote with a higher amount is captured and selected AFTER approval, `selectQuote()` no longer resets the
   approval (§14.21 behaviour is retired for approved work orders): it opens a variation (`origin = external_quote`, `rental_work_order_quote_id` set,
   document attached to the owner's request) and the §17.7 rules apply. A lower quote changes nothing. At close, a final cost above `approved_amount` is tested
   by `evaluateVariation()` (within tolerance → auto-approved and logged; otherwise the close is refused: "The final cost is above what the owner approved — raise
   a variation").
5. **Send the work order to the contractor** — replaces "Assign supplier" + the plain supplier mail. Button **"Send work order to contractor"** (enabled only once
   `authoriseToProceed()` is true, otherwise disabled with the reason). It runs `assignSupplier()` as built (reported → ordered, supplier must match the selected
   quote's supplier) and sends, through the agency mailbox path, to the supplier contact(s) resolved as today, a mail with a **Work order PDF**
   (`RentalDocumentPdfService::workOrderContractorPdf`): reference, property address and access arrangements ("please contact {agency} to arrange access"), description,
   trade, the approved quote amount and date, and the line **"Owner approval: approved on {date} — {basis in words}"** (owner decision / within the owner's no-approval
   limit / emergency work agreed by the owner). No tenant contact details, no owner contact details. History row `work_order_sent`.
6. **Completion** — new card "Contractor reports done" (permission `rental_work_orders.manage_completion`): date done (default today), how it was reported (phone /
   whatsapp / email / in person / other), note, and **photos** the contractor sent (multi-upload, type `completed`, `uploaded_via = office`). Saving opens a completion
   round (§17.10) and keeps the work order `in_progress`. The existing **Complete** form (paid_by, cost_amount, notes, as built) remains the agent's final close and is
   refused while the work order is `disputed`.
7. **Not built, by design:** the contractor secure link stays unminted (R4 is agent-captured); the wizard copy that says an agent "can regenerate a fresh link from the
   work order" (`agency-onboarding-copy.php:591-599`) is wrong today and is reported (§17.23), not fixed here. Contractor accept/decline and invoice upload (earlier spec
   §5.1/§5.2) stay out of scope.

**17.9.1a External-quote fee (Decision 1, Build 2).** An agency may add its own fee on top of an outside contractor's quote — "build the options, the agency sets it up".
Agency setting `rental_work_order_settings.external_quote_markup_type` (`percent` | `amount`, default `percent`) + `external_quote_markup_value` (decimal, **default 0 = off**).
A work order may override it: `rental_work_orders.external_markup_type` / `external_markup_value` (null = inherit), editable by staff who hold `rental_job_cards.price` (the "can price" key).
At quote capture/edit the quote row snapshots `fee_type`, `fee_value`, `fee_amount` and `selling_amount` (= `amount` + fee); `amount` always stays the contractor's own quote.
`RentalWorkOrderQuote::ownerFacingAmount()` returns `selling_amount ?? amount` and is the ONLY figure the gate, the owner mails/PDFs and the owner/portal payloads use;
office screens show **quote, fee and total** (fee visibility follows `rental_job_cards.view_costs`, since the fee is the agency's margin); the contractor's documents show only the contractor's `amount`.
A `percent` fee is `round(amount × p/100, 2)`; an `amount` fee is added as typed; both on the same VAT basis as the captured quote (no VAT recomputation, as built for quotes).
With the setting at 0 nothing changes. The foundation lands the columns, the setting accessors and the accessor; Build 2 applies it.

### 17.10 R5 — completion check and tenant dispute, internal and external (Build 3)

**17.10.1 A round starts when work is reported done.** `RentalCompletionService::openRound(RentalWorkOrder, array $report)` is called from every reporting route:
crew link (`CrewJobService::markCompleted`), crew page (same), signed copy upload (`RentalJobCardSignedCopyController`), office "Worker — done"
(`RentalJobCardController`), and — for external work — "Contractor reports done" (§17.9.6). It is wired as a listener on the existing `RentalJobCardCrewCompleted`
event plus direct calls from the office routes that do not dispatch it. It creates a `rental_work_completion_rounds` row, then notifies the tenant. The existing
landlord "crew completed" mail (once per card) is unchanged.

**17.10.2 `rental_work_completion_rounds`** (F6): `id, agency_id, rental_work_order_id, rental_job_card_id null, round_no, opened_at, reported_by_label (crew or
contractor name), reported_via (crew_link | crew_page | signed_copy | office | contractor_captured), reported_note null, reported_by_user_id null (office captures),
tenant_notify_status (sent | no_tenant | no_email | disabled | failed), tenant_notified_at null, window_ends_at null, outcome (awaiting_tenant | confirmed | disputed |
accepted_by_silence | no_tenant), responded_at null, responded_via (link | portal | office_on_behalf) null, responded_by_contact_id null, responded_by_user_id null,
response_note text null, dispute_resolved_at null, sign_off_snapshot json null, created_at, updated_at`. Rows are never deleted. Round numbers run 1, 2, 3… per work order.
`window_ends_at = opened_at + completion_response_window_days` is stored at open (a later settings change never moves an open window). If the agency turned the check
off (`tenant_completion_check_enabled` false) → `tenant_notify_status = disabled` and `outcome = no_tenant` (nobody is asked, nothing waits, close is never held up).
If the tenancy has no tenant (vacancy, `lease_id` null) → `tenant_notify_status = no_tenant`, `outcome = no_tenant`. If there is a tenant with no email → `no_email`, the round stays `awaiting_tenant`, and the office
sees "Tenant could not be emailed — record their answer by phone" (they use "Record tenant's answer" below).

**17.10.3 The tenant is told.** Mail through the agency mailbox path (as the property's responsible agent) to the lease's tenant contacts (`TenantContactResolver` /
lease tenants): "Work at {address} is reported complete by {crew or contractor name} on {date}. Please check it and tell us if it's done or still wrong. If we don't
hear from you by {window_ends_at} we will treat it as accepted." It carries a one-click **response link**, and says the same can be done in the tenant portal. The link
is a `RentalSecureAccessToken` with new purpose `tenant_completion`, target `rental_completion_round_id` (F7), 64-char token shown once, SHA-256 stored, expiry =
`window_ends_at + 7 days`, one live link per round, same throttles and "unavailable" doctrine as §14.27.5; after a response the SAME link shows a read-only "You answered
{date}" page (no new data) and refuses a second POST.

**17.10.4 The tenant answers** — `GET secure/completion/{token}` (`rentals.completion.show`), `POST secure/completion/{token}` (`rentals.completion.respond`,
`throttle:30,1`): shows title, address, who reported it done and when, the photos the agency rule allows (`crew_photos_visible_to_clients`), and two buttons —
**"All done, thanks"** (confirm) and **"Not complete / still wrong"** which requires a note (≥ 5 characters) and accepts up to 10 photos (image, ≤ 15 MB each;
`PropertyImageStorer` pipeline; stored as `rental_work_order_photos` with new `photo_type = dispute`, `rental_completion_round_id`, `uploaded_via = tenant`).
Portal: the Jobs tab shows an "Is this finished?" block for a work order with an `awaiting_tenant` round, backed by `POST /api/v1/client/rentals/work-orders/{workOrder}/completion-response`
(`client.rentals.work-orders.completion-response`; body `fixed` boolean, `note`, `photos[]`). The existing `…/confirm` endpoint stays as a compatibility alias that answers
the open round (and, with no open round on a `completed` order, behaves as before). The office can also **"Record tenant's answer"** (phone/WhatsApp; permission
`rental_work_orders.manage_completion`; `responded_via = office_on_behalf`, optional photos).
Both response routes call `RentalCompletionService::respond(round, fixed, note, photos, actor)`. The old `tenant_confirmed_*` columns on the work order and card are kept as
mirrors of the latest round's answer.

**17.10.5 Confirm** → `outcome = confirmed`; history row; staff in-app note to the property's agent; nothing else changes (the agent still does the final sign-off/close).

**17.10.6 Dispute** → `outcome = disputed`, then in one transaction:
- work order `status = disputed` and job card `status = disputed` (a completed card/work order is reopened: `completed_at` cleared, the card's worker and agent sign-offs
  are snapshotted into the round's `sign_off_snapshot` and then reset so they can be given again);
- staff in-app notification `rental_work_order.disputed` to the property's agent (and the branch manager, as overdue notices do) — the first thing they see is the
  tenant's note and photos; the landlord is emailed per `notify_landlord_on_dispute` (default on);
- **the office decides what happens next** — by default nothing is sent to the crew/contractor automatically (§17.22 Decision 3); an agency setting `dispute_notify_crew_immediately` (default off) switches an INTERNAL job to an immediate send of a fresh link plus the tenant's note and photos to the crew's address (an external contractor is always sent back by the office). The work order/card show a **Dispute** panel with the tenant's
  note and photos and two actions: **"Send back to crew"** (internal: issues a fresh per-job link, emails it through the agency mailbox path with the tenant's note and photos,
  logs `dispute_sent_back`) or **"Send back to contractor"** (external: mails the contractor contact the note and photos, logs it). The crew link of a `disputed` card is live and shows a
  **banner with the tenant's note and photos** and "Report fixed" (the existing "Mark work completed", re-labelled while disputed).
- Fault report: the outcome `repaired`/`repaired_partially` cannot be set while the linked work order is `disputed`, **or while its latest round is `awaiting_tenant`** (the fault's
  outcome waits for the tenant check to settle); other outcomes are unaffected.

**17.10.7 Resolution and the next round.** When the crew (link/page/signed copy/office) or the contractor (captured) reports done again, `openRound()` creates round n+1, the
previous round's `dispute_resolved_at` is stamped, and the work order and card return to `in_progress`; the tenant is told again. Every round stays in the history; each answer
and each reopening is a row in the work-order and job-card update logs and a tenancy-log entry (§17.16).

**17.10.8 Silence = accepted.** A scheduled command `rentals:settle-completion-rounds` (daily, registered beside `ScanRentalWorkOrderNotifications`) sets
`outcome = accepted_by_silence` for every `awaiting_tenant` round past `window_ends_at`, logs it ("No response in {n} days — accepted"), and notifies the agent in-app.
It never touches a `disputed` round. After the window the response link and the portal endpoint refuse with "The response period has ended — please report
a new fault" (a late complaint is a new fault report, not a reopening).

**17.10.9 Close rules.** Final sign-off/close — `RentalJobCard::complete()` / `RentalJobCardService::complete()` and `RentalWorkOrder::complete()` — are refused while the work order is
`disputed` ("A tenant has reported this work as not complete — resolve the dispute first"). They are NOT blocked while a round is merely `awaiting_tenant` (the office may close
and invoice during the window; a dispute inside the window reopens it, §17.22 Decision 2). `RentalJobCardService::complete()` stops swallowing the `LogicException` from the linked
work-order completion (`:691-697`) and surfaces it, because a silently half-closed job is incompatible with a reopen rule. Closing a Completed card kills its crew link (as built); a
dispute re-issues one only through "Send back to crew".

**17.10.10 Scoping.** `RentalPortalScopeService` gains `tenantCompletionRound()` (the round's lease is the tenant's) and `landlordWorkOrder()`; a round id from another tenant/agency is a 404.
The public response link resolves agency + round from the token only.

### 17.11 R6 — the estimate term printed on every owner quote and work order (Build 1)

**Setting:** `rental_work_order_settings.quote_estimate_term` text null (null = the built-in default constant `RentalWorkOrderSetting::DEFAULT_QUOTE_ESTIMATE_TERM`):
*"This quote is an estimate. The full extent of the work can only be confirmed once the affected area has been opened up, and the final invoice may differ. Any extra work
will be put to you for approval before it is done, except where your agreed work terms already allow it."* Editable on the Rental Work Orders settings page (textarea,
≤ 2000 characters, **Restore default** button) and as a Setup Wizard control. **Printed on:** the owner quote PDF and the quote mail body, the variation notice and its mail, and the
work-order notice sent to an owner. **Not printed on:** the worker print, the final statement (not an estimate), contractor documents, tenant surfaces. The wording in force when a quote
or variation is sent is **snapshotted** into `rental_work_order_quotes.term_text` / `rental_work_order_variations.term_text` (F8) so a reprint or a later settings edit never changes
what the owner was shown. Neutral wording, no agency name in the constant; the agency can rewrite it entirely.

### 17.12 Statuses and transitions — one table for fault, work order and job card

**Design decision.** Only `disputed` is a new stored **stage** (work order and job card). "Awaiting owner approval", "Variation awaiting owner" and
"Emergency approved" are **conditions**: a job can be In progress and have a variation waiting, or be Scheduled and emergency-approved, so squeezing them into
one status column would lose information and break every list that filters on stage. Each condition has a stored field and a chip (second table).
All status columns are strings; no DB migration is needed for the new value.

**Stages**

| Record | Stage | Moved to by | Guards (all enforced in model/service, not only the controller) |
|---|---|---|---|
| Fault | reported → awaiting_approval | agent "Mark awaiting approval" (optional) | as built |
| Fault | reported / awaiting_approval / approved(agency_appoints) → work_order_raised | **Create work order** (§17.3) | not from declined, owner_handling, work_order_raised, resolved, cancelled |
| Fault | → approved / owner_handling / declined | owner decision (portal or agent evidence) | as built; no longer required before a work order |
| Fault | → resolved (outcome set) | agent "Save outcome" | `repaired`/`repaired_partially` refused while the linked work order is `disputed` or its latest round is `awaiting_tenant` (§17.10.6) |
| Fault | → cancelled | as built | as built |
| Work order | (new) → reported | any creation path | one creation announcement (§17.3.4) |
| Work order | reported → ordered | **Send work order to contractor** (external) | `authoriseToProceed()`; selected quote's supplier matches (§17.9.5) |
| Work order | reported / ordered → in_progress | external: "Mark in progress"; internal: card `start` | `authoriseToProceed()` |
| Work order | in_progress → completed | external: **Complete** form; internal: card `complete()` (syncs) | not `disputed`; approval allows; final cost vs `approved_amount` via `evaluateVariation()` (§17.9.4) |
| Work order | reported / ordered / in_progress / completed → **disputed** | tenant answers "not complete" on an open round (§17.10.6) | round must be `awaiting_tenant` and inside its window |
| Work order | disputed → in_progress | a new round is opened (work reported done again, §17.10.7) | — |
| Work order | → cancelled | as built | refused if completed; withdraws open variations |
| Job card | draft → quoted | **Send to owner as quote** | every accepted line priced; landlord contact present (as built) |
| Job card | quoted → approved | `syncStatusFromWorkOrder` after the gate/owner decision | as built |
| Job card | draft / quoted / approved → scheduled | **Set** schedule | `authoriseToProceed()` |
| Job card | any open → in_progress | **Mark in progress** | `authoriseToProceed()` |
| Job card | in_progress → completed | **Complete job card** | worker AND agent sign-off (as built); not `disputed` |
| Job card | in_progress / completed → **disputed** | same transaction as the work order (§17.10.6); a completed card is reopened | — |
| Job card | disputed → in_progress | new round opened | — |
| Job card | → cancelled | as built | withdraws open variations |

`CREW_VISIBLE_STATUSES` becomes `approved, scheduled, in_progress, disputed` (a disputed job must reach the crew); a card with an open price request is additionally listed under "To price" (§17.5.4).
`isClosed()` is unchanged (completed, cancelled); `disputed` is an open state.

**Conditions (chips)**

| Chip | Stored as | Set / cleared by | Shown to |
|---|---|---|---|
| Awaiting owner approval | `rental_work_orders.owner_approval_status = pending` (card shows `quoted`) | `evaluateQuote` / owner decision | office, landlord ("Needs your decision"); tenant sees "Being arranged" |
| Approved (basis) | `approval_basis` + latest `rental_approval_decisions` row | gate | office (with the cited term), landlord |
| Emergency approved | `approval_basis = emergency_owner_agreed`, `emergency_approval_id` set and not voided | §17.8 | office, crew ("Approved to proceed (emergency)"), landlord; tenant no |
| Variation awaiting owner | an open `rental_work_order_variations` row, `status = awaiting_owner` | §17.7 | office, crew (per line), landlord |
| Variation auto-approved | variation `status = auto_approved` | gate | office, crew (per line), landlord (info mail) |
| Pricing requested / Priced by crew — awaiting you | open `rental_job_card_price_requests` / lines `awaiting_office` | §17.5 | office, crew |
| Tenant check — answer due {date} | latest round `outcome = awaiting_tenant` | §17.10 | office, tenant, landlord |
| Disputed | stage `disputed` | §17.10 | office, crew/contractor (after "send back"), landlord (per setting), tenant |
| Fault chips | derived from the linked work order (Disputed / Awaiting owner approval / Emergency approved) | read-only | office, tenant |

**Plain stage labels for tenant and landlord** (never raw statuses): Reported · Being arranged / Needs your decision · Approved · Scheduled · In progress · Reported complete — please check · Not complete — reopened · Completed · Cancelled.

### 17.13 Data model — everything, in migration order (the foundation lands all of it)

All new tables carry `agency_id` with `BelongsToAgency`; none has a hard delete; evidence tables are append-only as stated. Migration names are `2026_10_11_1000nn_…`
(later than the latest on QA1, `2026_10_10_130000`); each idempotent on Staging data; `php artisan schema:dump` + DEFINER strip after the last one (non-negotiable #12a).

| Mig | Table / change | Columns |
|---|---|---|
| F1 | `rental_job_card_lines` | `unit_cost`, `cost_total`, `markup_type`, `markup_value`, `selling_basis` (default `manual`), `origin` (default `office`), `office_status` (default `accepted`), `crew_note`, `crew_added_by_label`, `crew_added_at`, `office_decided_by_user_id`, `office_decided_at`, `reject_reason`, `rental_job_card_price_request_id`, `rental_work_order_variation_id` |
| F1 | `rental_job_cards` | `markup_all_percent`, `markup_parts_percent`, `markup_labour_percent` (decimal 6,2 null), `total_cost` (decimal 10,2 null) |
| F1 | `rental_catalogue_items` | `default_cost` (decimal 10,2 null) |
| F2 | `rental_work_order_settings` | add `default_parts_markup_percent`, `default_labour_markup_percent`, `variation_tolerance_percent`, `quote_estimate_term`, `completion_response_window_days`, `tenant_completion_check_enabled`, `notify_landlord_on_dispute`, `notify_landlord_on_auto_variation`, `external_quote_markup_type`, `external_quote_markup_value`, `dispute_notify_crew_immediately`; rename `show_prices_on_printed_job_card` → `show_costs_on_printed_job_card` (value copied) |
| F2 | `rental_portal_settings` | rename `crew_link_show_prices` → `crew_link_show_costs` (value copied) |
| F3 | `rental_job_card_price_requests` | §17.5.4 |
| F4 | `properties` | `rental_variation_tolerance_percent`, `rental_work_terms_updated_at`, `rental_work_terms_updated_by_user_id` |
| F4 | `rental_property_work_term_changes` | §17.6.2 |
| F5 | `rental_work_orders` | `approved_amount` (decimal 10,2 null), `approval_basis` (string 30 null), `emergency_approval_id` (unsigned bigint null), `external_markup_type` (string 10 null), `external_markup_value` (decimal 10,2 null); back-fill `approval_basis = legacy_grandfathered` for in-flight rows (§17.6.5) |
| F5 | `rental_approval_decisions` | §17.6.4 |
| F5 | `rental_emergency_approvals` | §17.8.2 |
| F5 | `rental_work_order_variations` | §17.7.3 |
| F5 | `rental_approvals` | `rental_work_order_variation_id` (null) |
| F6 | `rental_work_completion_rounds` | §17.10.2 |
| F6 | `rental_work_order_photos` | `rental_job_card_line_id` (null), `rental_completion_round_id` (null); new `photo_type` value `dispute` (column is a string) |
| F7 | `rental_secure_access_tokens` | `rental_completion_round_id` (null); new `purpose` value `tenant_completion`; the "exactly one target" rule now includes the round |
| F8 | `rental_work_order_quotes` | `term_text` (null; back-filled with nothing — old quotes show no term), `fee_type` (string 10 null), `fee_value` (decimal 10,2 null), `fee_amount` (decimal 10,2 default 0), `selling_amount` (decimal 10,2 null = same as `amount`) |
| F9 | `notification_event_types` | five staff keys (§17.16) — idempotent, pillar `property`, group `Rentals`, like `2026_09_28_100400` |
| F10 | `role_permissions` | grants for the five new keys (§17.15), copying from the stated source key and its scope, idempotent (template `2026_10_10_100500`) |

Strings added to existing string columns (no schema change): work order and job card `status = disputed`; `rental_work_order_updates.update_type` and
`rental_job_card_updates.update_type` values listed in §17.16 (all ≤ 30 characters); `approval_basis` values `no_approval_limit`, `owner_decision`,
`variation_tolerance`, `emergency_owner_agreed`, `legacy_grandfathered`.
**Naming trap, stated once:** `rental_work_orders.cost_amount` is the OWNER-FACING (selling, VAT-inclusive for a VAT agency) final amount for an internal job and the contractor's
final invoice for an external one. It keeps its name (a rename would touch every report and mail) but every build treats it as **selling**; the agency's own cost lives only
on the job card lines (`cost_total`, `rental_job_cards.total_cost`).

### 17.14 Settings — agency level and per-property overrides, with defaults, and the Setup Wizard

Agency settings live on **Settings → Rental Work Orders** (`corex/settings/rental-work-orders.blade.php`, permission `rental_work_orders.manage_settings`) and
**Settings → Rental Portal** for the portal key. **Every one is also a Setup Wizard control** (`config/agency-onboarding-copy.php`, each with `explain` and a concrete `affects`;
its own narrow saver with `has()`-guarded booleans — onboarding spec §6.1; an explicit per-key arm in `AgencySetupWizardController::currentValues()` — a key left to the `default`
fall-through shows a stale default, §14.28 follow-up).

| Key | Table | Type | Default | Range | Meaning | Owner |
|---|---|---|---|---|---|---|
| `default_parts_markup_percent` | work-order settings | decimal | **0** | 0–1000 | selling = cost + this % for part lines with no other rule (§17.4.3 rule 6) | B1 |
| `default_labour_markup_percent` | " | decimal | **0** | 0–1000 | same for labour lines | B1 |
| `quote_estimate_term` | " | text, null = built-in wording | built-in constant (§17.11) | ≤ 2000 chars | printed on owner quotes/variation notices/work-order notices | B1 |
| `show_costs_on_printed_job_card` (renamed) | " | toggle | **off** | — | worker print shows cost figures; never selling | F (rename) |
| `capture_prices_on_job_cards` | " | toggle | on | — | master switch: off = no cost, no selling, no margin anywhere (as built, unchanged) | — |
| `no_approval_spend_threshold` | " | decimal | R500 | ≥ 0 | agency default of term (i) (as built) | — |
| `variation_tolerance_percent` | " | decimal | **0** | 0–100 | agency default of term (ii); a property may override | B2 |
| `notify_landlord_on_auto_variation` | " | toggle | on | — | information email when a variation is auto-approved | B2 |
| `tenant_completion_check_enabled` | " | toggle | on | — | off = nobody is asked to confirm; rounds record `no_tenant` | B3 |
| `completion_response_window_days` | " | int | **5** | 1–30 | days the tenant has before silence counts as accepted | B3 |
| `notify_landlord_on_dispute` | " | toggle | on | — | email the owner when a tenant reports work as not complete | B3 |
| `external_quote_markup_type` | " | `percent` / `amount` | `percent` | — | form of the fee added to an outside contractor's quote (§17.9.1a); a work order may override | B2 |
| `external_quote_markup_value` | " | decimal | **0** (off) | percent 0–1000; amount ≥ 0 | the fee itself; 0 = no fee | B2 |
| `dispute_notify_crew_immediately` | " | toggle | **off** | — | on a dispute, email the crew a fresh link + the tenant's note and photos straight away instead of waiting for the office's "Send back" (internal jobs only) | B3 |
| `crew_link_show_costs` (renamed) | portal settings | toggle | **off** | — | crew also sees office-entered costs (§17.4.7) | F (rename) |

**Per-property overrides** (on the property Rental tab, §17.6.2): `rental_no_approval_spend_threshold` (existing), `rental_variation_tolerance_percent` (new). Blank = inherit.
Deliberately NOT in the wizard (to be added to `agency-onboarding-setup.md` §5.1 "Deliberately NOT in the wizard"): the two per-property overrides — they are per-owner agreements,
not an agency-wide onboarding choice (same ruling as §8).
Defaults are neutral and safe for any agency: no markup, no automatic tolerance, no emergency override. **What Johan needs to enter for his own agency at go-live (values, not
decisions):** HFC's parts % and labour %, whether HFC wants a non-zero tolerance by default, and any rewording of the estimate term.

### 17.15 Permissions (Role Manager) — new keys, defaults, enforcement

New in `config/corex-permissions.php` (section `agency-tracker`, type `action`), each enforced by route middleware, controller check AND (for transitions) the model/service, with a
data migration (F10) granting existing agencies' roles the same scope as the source key (template: `2026_10_10_100500_grant_rental_job_cards_share_permission.php`):

| Key | Label | Gates | Default grant (copied from) |
|---|---|---|---|
| `rental_job_cards.price` | Set Selling Prices & Markup on Job Cards | edit selling, markup, Pricing panel, accept/reject crew lines | holders of `rental_job_cards.send_quote` |
| `rental_job_cards.view_costs` | View Costs & Margin on Job Cards | cost and margin columns/totals, the cost report columns | holders of `rental_job_cards.send_quote` (§17.22 Decision 4) |
| `rental_work_orders.record_emergency_approval` | Record Owner Emergency Approval | §17.8 record/void | holders of `rental_work_orders.record_approval` |
| `rental_work_orders.manage_work_terms` | Manage Owner Work Terms (per property) | §17.6.2 | holders of `rental_work_orders.manage_settings` |
| `rental_work_orders.manage_completion` | Capture Completion, Tenant Answers & Disputes | "Contractor reports done", "Record tenant's answer", "Send back to crew/contractor" | holders of `rental_work_orders.complete` |

Reused as built: `rental_job_cards.share` (Ask crew to price, send back to crew), `rental_job_cards.send_quote`, `rental_job_cards.sign_off`, `rental_work_orders.record_approval` (capture a
variation decision), `rental_work_orders.manage_quotes`, `rental_fault_reports.raise_work_order`. `admin` receives new keys automatically (exclude-list model); `branch_manager`/`agent`
defaults on a fresh install get none of the five unless a build adds them to `role_defaults` deliberately (the foundation does not).

### 17.16 Notifications, mails, domain events, tenancy log, audit

**All new mail goes through the agency mailbox path** — each Mailable extends `BaseSignatureMail`, takes `?User $agent`, calls `fromAgent()`, and is sent with
`RentalMailDispatcher::send($email, $mail)` (call pattern: `RentalJobCardCrewCompletedLandlordMail` / `SendLandlordCrewCompletionMailJob`). Sending agent = the user who pressed the button;
for automatic mails the property's responsible agent, then the card's creator, then the shared mailer (as AT-395 and §14.27.1 Q11 already fall back). Attachments: the Mailable overrides
`attachments()` (as `RentalNoticeMail` does). On QA1 the outbound guard catches everything; verification uses `@example.invalid` only.

| When | To | Mailable (new) | Carries | Setting |
|---|---|---|---|---|
| Quote sent / revised (job card) or an external quote needs a decision | landlord contact | `RentalOwnerQuoteMail` | quote PDF attached, owner-facing amount, R6 term, "decision needed" wording when over the limit, portal pointer | `notify_landlord_on_decision_needed` (as built) |
| Variation needs the owner | landlord contact | `RentalOwnerVariationMail` | variation notice PDF, new total, R6 term | same |
| Variation auto-approved | landlord contact | `RentalOwnerVariationAutoMail` | what was added, new total, "within your agreed {n} %" | `notify_landlord_on_auto_variation` |
| Work order sent to the contractor | contractor contact(s) | `RentalContractorWorkOrderMail` | work-order PDF with "Owner approval" line | — |
| Work reported done (every round) | lease tenant contacts | `RentalTenantCompletionCheckMail` | response link, window date | `tenant_completion_check_enabled` |
| Tenant says not complete | landlord contact | `RentalLandlordDisputeMail` | tenant's note, photos, "the office is arranging a fix" | `notify_landlord_on_dispute` |
| Office sends it back | crew address / contractor contact | `RentalDisputeSentBackMail` | tenant's note + photos; crew: fresh link | — |
| Work order closed (internal and external) | landlord contact | `RentalOwnerFinalStatementMail` | final statement PDF (selling only), emergency flag when applicable | — |

**Retired in this flow:** every use of `RentalWorkOrderOwnerMail` (created / quote revised / completed) and `RentalWorkOrderSupplierMail`, and the plain-`Mail::to`
`RentalPortalNotificationService::notifyLandlordDecisionNeeded` (its content moves into `RentalOwnerQuoteMail`/`RentalOwnerVariationMail`). **Left as built, out of scope:**
`RentalWorkOrderTenantMail`, `RentalTenantStatusChangeMail`, `RentalNoticeMail` (plain sends; reported §17.23). The landlord crew-completed mail (once per card) is unchanged.

**Staff in-app notifications** (`NotificationDispatcher::fire`, to the property's agent; `threshold_hit_at = now()`; event keys registered by F9): `rental_job_card.crew_lines_submitted`,
`rental_work_order.variation_raised`, `rental_work_order.disputed` (also the branch manager), `rental_work_order.completion_confirmed`, `rental_work_order.completion_accepted`.

**Domain events** (non-negotiable #9; added to the catalogue table in `.ai/specs/corex-domain-events-spec.md` §5 in the build that dispatches them; `AbstractDomainEvent`,
readonly constructor, `agencyId()/actorUserId()/subject()/context()`; listeners registered inline in `AppServiceProvider` beside `:298`): `Rentals\RentalCrewLinesSubmitted`,
`RentalCrewLinesDecided`, `RentalVariationRaised`, `RentalVariationDecided`, `RentalEmergencyApprovalRecorded`, `RentalWorkReportedDone`, `RentalCompletionResponded`,
`RentalCompletionSettledBySilence`, `RentalWorkOrderClosed` (dispatched inside `RentalWorkOrder::complete()`; subscribed by the final-statement mail listener, so the owner is told
whichever route closed it). Listeners: `OpenCompletionRound` (on `RentalJobCardCrewCompleted`), `SendOwnerFinalStatement` (on `RentalWorkOrderClosed`).

**Audit trail.** Append-only logs only — no new generic log. `rental_work_order_updates.update_type` adds: `approval_decision`, `emergency_approved`, `emergency_voided`,
`variation_raised`, `variation_decided`, `work_order_sent`, `work_reported_done`, `completion_response`, `dispute_opened`, `dispute_sent_back`, `completion_accepted`.
`rental_job_card_updates.update_type` adds the §17.5.6 list plus `dispute_opened`, `dispute_sent_back`, `reopened`. Plus the decision, emergency, variation and round tables, which are
themselves history. Every action by the crew/tenant (no CoreX user) writes actor null with name/via/IP/device in the note.

**Tenancy log** (`LeaseTimelineService`, scoped by `lease_id` as built — a vacancy work order with no lease does not appear, as today): add types **`emergency_approval`, `variation`,
`completion_check`** to `TYPES` (the filter checkboxes and the PDF label pick them up automatically) and one builder `maintenanceFlowEntries($lease)` merged in `allEntriesFor`:
emergency approval recorded/voided; variation raised / auto-approved / approved / declined / withdrawn; every round — "Work reported done by {name} (round n)", "Tenant confirmed" /
"Tenant reported not complete: {note}" / "Accepted — no response in {n} days", "Dispute sent back to {crew|contractor}", "Reported done again (round n+1)". Approval decisions appear as
`work_order` entries ("Approved — within the owner's no-approval limit"). Every entry links to the work order (`corex.rental-work-orders.show`).

### 17.17 Reports affected (each build owns one method of `RentalReportService`)

- **`jobCards()`** — the column now labelled "Total cost" is selling; relabel **Selling (incl VAT)** and add **Cost**, **Margin (R)**, **Margin %** (excl-VAT basis) — the cost/margin columns and
  their CSV/PDF/print variants are omitted entirely without `rental_job_cards.view_costs`; a "lines without cost" count column; labour-hours and parts-used unchanged (accepted lines only).
- **`workOrders()`** — amount column unchanged (owner-facing); add **Approval basis** (and "Emergency" flag), **Variations (count / extra R)**, **Rounds**, **Disputes**; a **Disputed** status filter
  and tile; margin for internal jobs from the card, permission-gated as above.
- **`landlordPropertyActivity()`** — owner-facing: **selling only, never cost or margin** (asserted by test); shows the emergency flag; unaffected by crew-added lines until accepted.
- **`propertyHistory()`** — no money change; its work-order events include emergency/dispute markers.
- Job-card and work-order **list screens** gain filters/tiles: "Disputed", "Awaiting owner", "Needs pricing" (§17.5.5), "Variation pending".

### 17.18 Screens by audience (all built off the current screens)

| Audience | Screen | Change |
|---|---|---|
| Office | Fault report show | single **Create work order** form (§17.3); chips derived from the work order |
| Office | Work order create | unchanged chooser; job-card alias redirects here |
| Office | Work order show | Approval card shows "Why was this approved?"; Emergency approval panel; Variation panel; Quotes (external) with "Send work order to contractor"; "Contractor reports done"; Dispute panel; completion rounds history; tenant-check status |
| Office | Job card show | Pricing panel; cost/selling/margin grid (permission-gated); "Added by crew — awaiting office" block; "Ask crew to price"; variation/emergency/dispute chips and panels; "Send back to crew"; closed/disputed locks as built |
| Office | Property Rental tab | "Work terms agreed with the owner" panel (§17.6.2) |
| Office | Settings pages + Setup Wizard | §17.14 |
| Crew (link/page) | per-job view | Parts & labour panel, price-request banner, approval chips on lines, "Approved to proceed (emergency)", dispute banner with tenant note/photos; **no selling/markup/margin/owner data** |
| Contractor | email only | work-order PDF showing the owner's approval; no link |
| Tenant | email + `secure/completion/{token}` + portal Jobs tab | check-and-answer page; work-order stage labels; no money |
| Landlord | email + portal Decisions/Jobs | variation card, quote PDF by email, emergency flag, final statement; owner-facing amounts only |

### 17.19 Scoping, multi-agency, no hard deletes, VAT

- **Own / branch / agency:** every new office action resolves through `guardRentalRecordScope($record, <permission module>, $property->branch_id)`; new list filters keep the Own | Branch | All
  switch; direct-URL access by id is blocked (403 inside the agency but outside the user's scope, 404 across agencies) — one test per new route. Public routes resolve agency + record from
  the token only. New portal reads go through `RentalPortalScopeService` (`withoutGlobalScopes()` + explicit `agency_id` + explicit `deleted_at IS NULL`).
- **No hard deletes:** crew drafts, markup, variations, emergency approvals, rounds and approval decisions are archived/voided/withdrawn, never deleted; evidence tables are append-only.
- **Multi-agency:** no HFC wording, branding, default or id anywhere; agency name/logo from the agency record; every default in §17.14 is neutral; the estimate term is agency-editable;
  "what does this look like for the SECOND agency?" is a stated test in each build (an agency with different markups, window, tolerance and wording, and no crews, must still work).
- **VAT:** unchanged mechanics (§14.17); cost shares the line's VAT type and the capture mode; margin on excl figures; the card's frozen-VAT snapshot (`vat_snapshotted_at`) applies to cost the
  same way it applies to selling, and a line added or accepted after the freeze is snapshotted immediately (the §14.21 rule).
- **Mobile foundation (§13):** every action is a service method an app can call; the crew pricing, variation decision and completion response also exist as named `/api/v1/*` routes (portal) or
  public token routes (crew/tenant), registered and visible in Admin → API.

### 17.20 Tests (each build runs its own single files with `scripts/lane-test.sh`; never the full suite — CLAUDE.md #13)

Common to every build: happy path; each optional-empty path; each required-empty path rejected with a clear message; malformed input; deleted/archived related record; the lazy-but-valid shortcut;
own/branch/agency scoping with a direct-URL-by-id test per new route (same-agency out-of-scope → 403, other agency → 404); permission matrix (with/without the key); the "second agency" test
(different markups/window/tolerance/wording, no crews); `Mail::fake()` asserting NOTHING is sent as a plain Mailable and a fake `RentalMailDispatcher` receives each mail, addressed to
`@example.invalid`; a real-browser proof on QA1 (BUILD_STANDARD §0a) at mobile width for every crew/tenant page.

- **Foundation:** `MaintenanceFlowFoundationTest` (every migration up/down on a copy of Staging data; renamed settings keep their values; defaults; `legacy_grandfathered` back-fill; permission grants copy
  scope and are idempotent; stubs are inert — `authoriseToProceed` true, `assessAfterLineChange` null, `openRound` no-op) and **`CrewPayloadNeverCarriesSellingTest`** — a permanent guard that the crew
  payload, crew page materials and the worker print never contain `unit_price`, `line_total`, markup, margin or an owner amount, in any setting combination.
- **Build 1:** `RentalPricingServiceTest` (all six rules in order; percent and amount rounding; incl-VAT capture; reprice leaves `manual`/`line_markup`; legacy lines show no margin; margin on excl-VAT;
  "lines without cost" count); `AcceptedLinesOnlyReadersTest` (one assertion per reader in §17.4.6 — awaiting/rejected/declined lines are in no total, PDF, signature, VAT breakdown, materials or report);
  `JobCardCostSellingScreenTest` (cost/margin absent server-side without `view_costs`; selling edits need `price`); `CrewPartsPanelTest` (add/edit/archive own drafts, send-to-office notifies once, never selling in
  the response body, crew lines never change owner totals/quote/owner payload, photos stored with `uploaded_via`, chips for each state); `CrewPriceRequestTest` (ask, banner, submit once, re-ask, "To price" group on the
  crew page for a Draft card); `CatalogueDefaultCostTest` (form, import, prefill); `QuoteEstimateTermTest` (default, edit, restore, snapshot on send, printed on the right documents and not on the others);
  `PricingSettingsWizardTest` (wizard saver cannot wipe unrendered settings; `currentValues` arms); print tests (worker print cost-only and only when on; owner PDF selling-only and free of the words cost/margin).
- **Build 2:** `RentalApprovalGateServiceTest` (every §17.6.3 branch; no creep across repeated auto-approvals; decision rows cite term/value/source; legacy); `WorkTermsTest` (edit, history, who/when, inherit display,
  permission, the duplicate input is gone, `updateRentalDetails` ignores the threshold); `AuthoriseToProceedTest` (each guard site; grandfathered rows; pricing needs no schedule); `VariationFlowTest` (auto, beyond,
  portal approve/decline, agent-captured decision, revision 409, withdraw, decline removes lines from totals, crew chips, auto-approved info mail on/off); `EmergencyApprovalTest` (record/void, no amount, work
  proceeds, `selectQuote` never downgrades, statement/PDF flag, owner sees flag at close); `ExternalContractorFlowTest` (the six steps; higher quote after approval = variation; contractor mail via dispatcher with PDF
  and the "Owner approval" line; final-cost gate); `LandlordDecisionPendingCheckTest`; `OwnerMailsAgencyMailboxTest`.
- **Build 3:** `CreateWorkOrderActionTest` (internal vs external; relaxed gate and each refused status; no inherited approval; alias redirect; no card without a work order; one creation announcement on every path);
  `CompletionRoundTest` (opened from each of the five reporting routes; `no_tenant`/`no_email`/`disabled`; window stored); `TenantCompletionResponseTest` (token page confirm and dispute with photos; throttle; used-link
  page; expired; portal endpoint; `confirm` alias; office-on-behalf); `DisputeLifecycleTest` (both records go `disputed`; a completed card is reopened and sign-offs snapshotted; close refused; send-back mails via dispatcher;
  new round returns both to `in_progress`; every round in history); `SettleSilentRoundsCommandTest` (accepted only after the window, never a disputed round, late response refused); `FaultOutcomeGuardTest`;
  `ClientWorkOrderViewTest` (tenant never sees money; landlord amount only; other tenant/landlord/agency 404); `LeaseTimelineMaintenanceFlowTest`; `CompletionSettingsWizardTest`.
- **Integration (written by the last build to merge):** `MaintenanceFlowEndToEndTest` — fault → Create work order (internal) → price request → crew submits costs → office accepts and prices → quote sent → owner approves
  (portal) → crew adds an extra → beyond tolerance → variation → owner approves → crew reports done → tenant says not complete (photos) → office sends back → crew reports fixed → tenant confirms → close → owner gets the
  final statement; a second scenario with an emergency approval and no quote; a third for the external flow.

### 17.21 Build split — a shared foundation first, then three parallel builds

**Rule for every build:** read §17.0–§17.2 and §17.12–§17.19 plus its own sections; work only its files (§17.21.5 maps every shared file); append to shared files only inside the marker blocks the foundation adds;
update this section with a dated BUILD NOTE; add its events to the domain-events catalogue and its CHAT_STARTER line; one lane per build, Sonnet, `/clear` before starting.

#### 17.21.1 Foundation (lands first; one lane; changes no behaviour except the renames and the crew-leak stopper)
1. **Migrations F1–F10** (§17.13) + `schema:dump` + DEFINER strip.
2. **Models** (fillable, casts, relations, constants): `RentalJobCardLine` (`scopeAccepted`, state constants), `RentalJobCard` (`STATUS_DISPUTED`, `CREW_VISIBLE_STATUSES` += `disputed`, markup columns),
   `RentalWorkOrder` (`STATUS_DISPUTED`, `APPROVAL_BASIS_*`, `hasOpenDispute()`, `latestDecision()`, `approvalBasisLabel()`), `Property` (fillable), `RentalSecureAccessToken` (purpose `tenant_completion`, round target),
   `RentalWorkOrderQuote` (`ownerFacingAmount()` = `selling_amount ?? amount`; fee columns), and new `RentalApprovalDecision`, `RentalEmergencyApproval`, `RentalWorkOrderVariation`, `RentalWorkCompletionRound`, `RentalJobCardPriceRequest`, `RentalPropertyWorkTermChange`.
3. **Service shells with final signatures** (bodies inert, docblocks carry the contract; the value objects `SellingResolution`, `WorkTerms`, `GateDecision` are real):
   - `RentalPricingService`: `resolveSelling(RentalJobCardLine, RentalJobCard): SellingResolution`; `repriceLine(RentalJobCardLine): void`; `repriceCard(RentalJobCard): void`;
     `applyJobMarkup(RentalJobCard, string $scope /* all|parts|labour */, ?float $percent, ?User $by): void`; `marginFor(RentalJobCard): array{costExcl,sellingExcl,marginExcl,marginPct,linesWithoutCost}`.
   - `RentalApprovalGateService`: `termsFor(Property): WorkTerms`; `evaluateQuote(RentalWorkOrder, float, ?User): GateDecision`; `evaluateVariation(RentalWorkOrder, float, ?User): GateDecision`;
     `assessAfterLineChange(RentalJobCard, ?User): ?RentalWorkOrderVariation`; `authoriseToProceed(RentalWorkOrder): GateDecision`; `recordVariationDecision(RentalWorkOrderVariation, string $decision, array $evidence, array $actor): void`;
     `recordEmergency(RentalWorkOrder, array $data, User $by): RentalEmergencyApproval`; `voidEmergency(RentalEmergencyApproval, string $reason, User $by): void`.
   - `RentalCompletionService`: `openRound(RentalWorkOrder, array $report): RentalWorkCompletionRound`; `respond(RentalWorkCompletionRound, bool $fixed, ?string $note, array $photos, array $actor): void`;
     `sendBack(RentalWorkOrder, User): void`; `settleSilent(): int`.
   - `RentalCloseGuards`: `assertNotDisputed(RentalWorkOrder): void` (Build 3 fills); `assertFinalCostWithinApproval(RentalWorkOrder, ?float, ?User): void` (Build 2 fills) — called from the one close path in
     `RentalWorkOrder::complete()` and `RentalJobCard::complete()`, so the two builds never edit the same lines.
   - Inert defaults until the owning build lands: `authoriseToProceed` → authorised; `assessAfterLineChange` → null; `openRound` → no-op.
4. **Settings accessors** for every §17.14 key (`…For(?int $agencyId)` pattern, null = default constant) and the two **renames** across all callers (`CrewViewContext:61`, `RentalCrewScheduleService:78`, both settings
   controllers/views/routes, wizard keys and `currentValues` arms, `RentalDocumentPdfService`); **the crew-leak stopper** in the same commit: `CrewJobService::payload()`, `RentalCrewScheduleService::materials()` and the worker
   print read cost columns, never `unit_price`/`line_total` (the worker print gets its own `_pdf-cost-lines-table` partial: Description, Type, Qty, Unit cost, Cost total — VAT-on-cost is Build 1's `costBreakdown`; the shared `_pdf-lines-table` stays selling-only and is used by the owner quote only).
5. **Permissions**: the five keys in `config/corex-permissions.php` + the F10 grants migration.
6. **Events and notification keys**: the nine event classes (no dispatch yet, except `RentalWorkOrderClosed` dispatched inside `RentalWorkOrder::complete()`), F9 registration.
7. **Hook points** so builds never edit the same hunk: `CrewJobService::payload()` merges three block providers `CrewPricingBlock` (B1), `CrewApprovalBlock` (B2), `CrewDisputeBlock` (B3) — each `for(RentalJobCard,
   CrewViewContext): array` returning `[]`; `rentals/crew-link/_job-body.blade.php` gets three `@include`s of empty partials `_block-pricing`, `_block-approval`, `_block-dispute`; job card show gets `@include`s of empty
   `rental-job-cards/_pricing-panel` (B1), `_approval-panel` (B2), `_completion-panel` (B3); work order show gets empty `rental-work-orders/_approval-panel` (B2), `_completion-panel` (B3); the work-order settings page gets three empty
   section includes (`_settings-pricing` B1, `_settings-approvals` B2, `_settings-completion` B3); `routes/web.php`, `routes/api.php`, `agency-onboarding-copy.php` and `currentValues()` get `// BUILD n BEGIN/END` marker comments.
8. **Tests:** §17.20 Foundation. **Acceptance:** all migrations run on a copy of Staging data, `php -l` clean, the two renames carry values, no crew surface can show selling, nothing else behaves differently.

#### 17.21.2 Build 1 — Cost and selling, crew parts, estimate term (R1, R1b, R6) → §17.4, §17.5, §17.11, §17.14 rows marked B1
Exclusive files: `RentalPricingService`, `RentalJobCardVatService` (cost breakdown), `RentalJobCardLine`, `RentalJobCardService` line methods (`addLine/updateLine/…`) and the top of `sendToOwnerAsQuote` (priced-lines precondition),
`RentalCatalogue*` controller/views/import, `rental-job-cards/{_pricing-panel,_lines-table,_add-line-row,_pdf-lines-table,quote-pdf,print}.blade.php`, `CrewPricingBlock` + `_block-pricing`, new `CrewJobService` methods
(`addLine`, `editDraft`, `archiveDraft`, `sendToOffice`) and their routes/actions on `CrewJobLinkController` and `CrewPageController`, a new `RentalJobCardPriceRequestController` + `RentalJobCardCrewLineController` (accept/reject),
`_settings-pricing`, `RentalReportService::jobCards()`, wizard rows (parts %, labour %, estimate term). Seams used: calls `RentalApprovalGateService::assessAfterLineChange()` after accepting lines (inert until Build 2).
Acceptance: §17.4–§17.5 and §17.11 end to end; crew never sees selling; owner totals unchanged by crew lines until accepted.

#### 17.21.3 Build 2 — Approvals and the external flow (R2, R3, R3b, R4) → §17.6–§17.9, §17.16 (owner and contractor mails), §17.14 rows marked B2
Exclusive files: `RentalApprovalGateService` (bodies), `RentalCloseGuards::assertFinalCostWithinApproval`, `RentalWorkOrder` (`selectQuote`, `recordApproval`, `startProgress`, `assignSupplier` changes, the approval-basis fields), the guard calls in
`RentalJobCard::schedule/start` and `recordCrewCompletion`, `RentalWorkOrderService` (owner/supplier mail replacement, final statement), `RentalPortalNotificationService`, new `RentalPropertyWorkTermsController`, `RentalEmergencyApprovalController`,
`RentalWorkOrderVariationController`, the property Rental-tab panel and `PropertyController::updateRentalDetails`, the new mails and PDFs (`variationNoticePdf`, `workOrderContractorPdf`, final statement), `ClientLandlordRentalsController`
(variation decision + pending-check) and the landlord Decisions block of `shell.blade.php`, `CrewApprovalBlock` + `_block-approval`, `_approval-panel` partials, the work-order Supplier/Quotes/“Send work order to contractor” cards, `_settings-approvals`,
`RentalReportService::landlordPropertyActivity()`, the `SendOwnerFinalStatement` listener.
Acceptance: §17.6–§17.9; no work starts unauthorised; every decision cites its term; emergency needs the owner's capture and never gets overridden.

#### 17.21.4 Build 3 — The flow, completion check and dispute (R0, R5) → §17.3, §17.10, §17.12, §17.16 (tenant/dispute mails, tenancy log), §17.14 rows marked B3
Exclusive files: fault show view + `RentalFaultReportController::raiseWorkOrder`, `RentalWorkOrderController::create/store`, `RentalJobCardController::create/store` (alias) and `RentalJobCardService::createFromFaultReport/createStandalone/complete`,
`RentalWorkOrderService::fromFaultReport/announceCreated`, `RentalCompletionService`, `RentalCloseGuards::assertNotDisputed`, the `OpenCompletionRound` listener and the office hooks that open rounds, a new public `CompletionResponseController` + views
(`rentals/completion/{show,answered,unavailable}`), tenant API (`work-orders` index, `completion-response`) and the tenant/Jobs regions of `shell.blade.php`, `RentalWorkOrderClientViewService`, `RentalPortalScopeService` additions,
`LeaseTimelineService` (+ lease hub filter), `RentalSettleCompletionRounds` command, `CrewDisputeBlock` + `_block-dispute`, `_completion-panel` partials, “Contractor reports done”/“Record tenant's answer”/“Send back” actions, the three mails,
`RentalReportService::workOrders()`, `_settings-completion`.
Acceptance: §17.3 and §17.10 end to end, including the reopen rule and the close block.

#### 17.21.5 Conflict map (files more than one build touches — all additive, in separate blocks)
| File | B1 | B2 | B3 | How the collision is avoided |
|---|---|---|---|---|
| `routes/web.php` | pricing/crew-line routes | terms/emergency/variation routes | completion/dispute routes, public `secure/completion` | three `// BUILD n` marker blocks added by F |
| `routes/api.php` | — | landlord variation decision + `decisions` list | tenant `completion-response`, `work-orders` index, landlord `work-orders/{id}` | separate marker lines inside the existing client groups |
| `config/agency-onboarding-copy.php` + `AgencySetupWizardController::currentValues` | markup, term | tolerance, auto-variation mail | window, enabled, dispute mail | each appends inside its own marker block of the `rental_work_orders` source |
| `RentalWorkOrderSettingsController` + settings view | pricing section | approvals section | completion section | three partial includes added by F, own saver per key |
| `RentalJobCard.php` | `recalcTotal` accepted-only | `schedule/start` guard, crew-completion guard | `reopenForDispute`, `disputed` handling | disjoint methods; `complete()` guard is the F-inserted `RentalCloseGuards` call |
| `RentalWorkOrder.php` | — | `selectQuote`, approval, `startProgress` | `reopen`, round mirrors | disjoint methods; `complete()` guard via `RentalCloseGuards` |
| `RentalJobCardService.php` | line methods, top of `sendToOwnerAsQuote` | tail of `sendToOwnerAsQuote` (mail) | `createFromFaultReport/createStandalone/complete` | disjoint methods; B1 and B2 coordinate on `sendToOwnerAsQuote` (B1 precondition at the top, B2 mail at the bottom) |
| `CrewJobService.php` | new methods | none | `markCompleted` re-allowed after a dispute | block providers + disjoint methods |
| `rentals/crew-link/_job-body.blade.php` | `_block-pricing` | `_block-approval` | `_block-dispute` | three includes added by F |
| `rental-job-cards/show.blade.php`, `rental-work-orders/show.blade.php` | `_pricing-panel`, `_lines-table` | `_approval-panel`, supplier/quote cards | `_completion-panel` | includes added by F; each build edits only its partial |
| `RentalDocumentPdfService.php` | quote/print partials + term | new methods (variation, contractor, final) | — | B2 adds methods only |
| `RentalReportService.php` | `jobCards()` | `landlordPropertyActivity()` | `workOrders()` | one method per build |
| `rentals/portal/shell.blade.php` | — | landlord Decisions block | tenant tabs and Jobs tab | marker comments around each region |
| `config/corex-permissions.php`, migrations, `schema/mysql-schema.sql` | — | — | — | foundation only |
Integration order: **F → (B1 ∥ B2 ∥ B3 in any order) → the last build to merge adds the end-to-end test.** Cross-build calls go through the foundation signatures only, so no build waits for another's code.

### 17.22 Decisions on the five open questions (the conductor, on Johan's behalf, 6 Oct 2026) — all following "build the options, the agency sets it up"

1. **Contractor fee on external quotes → an agency setting, default off.** Markup on an outside contractor's quote, as a % or a fixed amount, default 0. When set, the owner sees the selling amount and the office sees quote, fee and total; staff who can price may override it per work order. Specified in §17.9.1a; data model F2/F5/F8; Build 2.
2. **Closing during the tenant window → allowed.** A dispute inside the window reopens the job; the fault's "Repaired" result waits for the tenant or the window. The window length is the agency setting `completion_response_window_days` (default 5). §17.10.9; Build 3.
3. **Crew told on a dispute → the office looks first and presses "Send back"**, with an agency setting `dispute_notify_crew_immediately` (default off) to notify the crew straight away instead. §17.10.6; Build 3.
4. **Cost and margin visibility → a Role Manager permission**, `rental_job_cards.view_costs` ("see cost and margin"), with `rental_job_cards.price` ("price and send quotes") alongside; both default-granted to the roles that hold `rental_job_cards.send_quote` today. Everyone else sees selling only; crew links show cost only, never selling. §17.15; Foundation (keys) + Build 1 (enforcement).
5. **Starting numbers → neutral agency defaults.** Variation tolerance 0 %, parts and labour markups 0 %, a per-property override for the owner's work terms, and the owner is emailed on every auto-approved extra (agency setting `notify_landlord_on_auto_variation`, default on). §17.14; Foundation (columns, accessors) + Builds 1–2.

### 17.23 Found while investigating — the six fixed inside a build, and the seven reported only (per the scope-lock rule)

**The six small defects, each assigned to the build that edits that exact code:**
| # | Defect | Where | Build |
|---|---|---|---|
| 1 | `RentalJobCardService::complete()` swallows the linked work-order `LogicException`, leaving a card closed and its work order open | `RentalJobCardService.php:691-697` | Build 3 (§17.10.9) |
| 2 | No landlord mail when a card closes its work order | `RentalJobCardService::complete()` never calls `notifyCompleted` | Build 2 (final statement, §17.16) |
| 3 | The landlord decision endpoints accept a decision on any open record, not only a pending one | `ClientLandlordRentalsController.php:345-422` | Build 2 (§17.6.6) |
| 4 | A second, dead threshold input on the property screen that saves nothing | `properties/show.blade.php:4415` | Build 2 (§17.6.2) |
| 5 | `rental_work_order.created` is not fired on the `createForProperty` path | `RentalJobCardService.php:148-176` | Build 3 (§17.3.4) |
| 6 | The tenant confirm endpoint has no screen in the portal shell | `rentals/portal/shell.blade.php` | Build 3 (§17.10.4) |

**Reported only, not changed (seven):**
1. The contractor secure link is never minted by any UI, and `ContractorSecureLinkController::markDone` tells the contractor "the agency has been notified" without notifying anyone (`:120`).
2. The Setup Wizard copy at `config/agency-onboarding-copy.php:591-599` promises a regenerate-link control that does not exist.
3. The supplier picker is not filtered by trade (`rental-work-orders/show.blade.php:358`).
4. The docblocks claiming the tenant is told at fault-report creation (`RentalWorkOrderTenantMail.php:15-17`, `RentalWorkOrderService.php:54-55`) have no mail behind them.
5. `applyApprovalResult()` is named in a comment (`RentalJobCard.php:442`) and does not exist.
6. The remaining plain-`Mail::to` rentals mails (tenant creation/status, notices) bypass the agency mailbox.
7. A dead per-job crew link renders HTTP 200 where a dead crew-page link renders 404 (already noted at §14.29).
(A documentation slip, not an item: §14.1 names `tenant_confirmed_completion_at`, which was never a column — real names are in §17.1.)

### 17.24 FOUNDATION — BUILT 6 Oct 2026 (cc6, QA1): what the three build lanes must know

The foundation (§17.21.1) landed exactly as specified, plus the decisions of §17.22. It changes **no behaviour** except the two cost-term renames and the crew-leak stopper.

**Migrations** (all `2026_10_11_1000nn_…`, idempotent, DML-only data steps; the schema snapshot was NOT hand-edited — migrations apply on top):
`100000_create_rental_job_card_price_requests_table` (F3) · `100100_create_rental_work_order_variations_table` (F5) · `100200_add_pricing_columns_to_rental_job_cards_lines_and_catalogue` (F1) ·
`100300_add_maintenance_flow_settings_and_rename_show_prices` (F2) · `100400_add_work_terms_to_properties_and_create_term_changes` (F4) · `100500_add_approval_model_to_rental_work_orders` (F5 + F8 + the grandfathering back-fill) ·
`100600_create_rental_work_completion_rounds_table` (F6 + F7) · `100700_register_maintenance_flow_notifications` (F9) · `100800_grant_maintenance_flow_permissions` (F10).

**Settings columns** (all nullable, read-time defaults, accessors on `RentalWorkOrderSetting` / `RentalPortalSetting`; **no settings-page control and no wizard row yet — each build adds its own, with its own `has()`-guarded saver and an explicit `currentValues()` arm, between its `// BUILD n` markers**):
`default_parts_markup_percent`, `default_labour_markup_percent`, `quote_estimate_term` (B1) · `variation_tolerance_percent`, `notify_landlord_on_auto_variation`, `external_quote_markup_type`, `external_quote_markup_value` (B2) ·
`tenant_completion_check_enabled`, `completion_response_window_days`, `notify_landlord_on_dispute`, `dispute_notify_crew_immediately` (B3). **Renamed, with value carried and every caller, route, wizard row and test switched in the same commit:**
`show_prices_on_printed_job_card` → `show_costs_on_printed_job_card` (`RentalWorkOrderSetting::showCostsOnPrintedJobCardFor`, route `corex.settings.rental-work-orders.show-costs-on-printed-job-card`) and
`crew_link_show_prices` → `crew_link_show_costs` (`RentalPortalSetting::crewLinkShowCostsFor`, route `corex.settings.rental-portal.crew-link-show-costs`).

**Permission keys** (config + grants copied from the source key and its scope): `rental_job_cards.price` and `.view_costs` ← `.send_quote`; `rental_work_orders.record_emergency_approval` ← `.record_approval`; `.manage_work_terms` ← `.manage_settings`; `.manage_completion` ← `.complete`. Nothing enforces any of them yet — each build wires its own.

**Plug-in slots (each build edits ONLY its own file):**
| Slot | Build 1 | Build 2 | Build 3 |
|---|---|---|---|
| Crew payload block (class → partial) | `CrewPricingBlock` → `rentals/crew-link/_block-pricing` | `CrewApprovalBlock` → `_block-approval` | `CrewDisputeBlock` → `_block-dispute` |
| Job card show (right column) | `corex/rental-job-cards/_pricing-panel` | `_approval-panel` | `_completion-panel` |
| Work order show | — | `corex/rental-work-orders/_approval-panel` | `_completion-panel` |
| Work-order settings page | `corex/settings/rental-work-orders/_pricing` | `_approvals` | `_completion` |
| Close hook (`RentalCloseGuards`) | — | `assertFinalCostWithinApproval()` | `assertNotDisputed()` |
| Service shell | `RentalPricingService` | `RentalApprovalGateService` | `RentalCompletionService` |
`// BUILD n BEGIN … // BUILD n END` markers exist in `routes/web.php` (after the work-order group), `routes/api.php` (tenant group: B3; landlord group: B2 and B3), `config/agency-onboarding-copy.php` (savers list and controls list) and `AgencySetupWizardController::currentValues()` (the `rental_work_orders` arm).

**Inert until the owning build lands:** `authoriseToProceed()` authorises, `assessAfterLineChange()` returns null, `openRound()` returns null, `settleSilent()` returns 0, both close guards refuse nothing. The decision/write methods (`evaluateQuote`, `evaluateVariation`, `recordVariationDecision`, `recordEmergency`, `voidEmergency`, `respond`, `sendBack`, `applyJobMarkup`) **throw a `LogicException` naming their build** so a caller wired too early fails loudly. `termsFor()` is real. `RentalWorkOrder::complete()` already dispatches `RentalWorkOrderClosed` (no listener yet).

**Things to know when you build:**
1. **Crew payload keys changed** (the stopper): `show_prices`/`total`/`unit_price`/`line_total` are gone; the crew payload now has `show_costs`, `cost_total`, per-line `unit_cost`/`cost_total` (omitted when no cost is recorded — never a 0.00) and `blocks` (`pricing`, `approval`, `dispute`). `CrewViewContext::$showPrices` is now `$showCosts`. `CrewPayloadNeverCarriesSellingTest` guards every crew surface, the crew page "what to load" list and the worker print, in every setting combination — **extend it for your block, never relax it**.
2. **The worker print** has its own `_pdf-cost-lines-table` partial (cost columns only, no VAT until Build 1's cost breakdown); the shared `_pdf-lines-table` is now used by the owner quote alone. `RentalDocumentPdfService::jobCardPrintPdf()` passes `costsOn` (no `pricesOn` / `vat`).
3. **Only `accepted` lines count** — every reader listed in §17.4.6 still reads all lines today (nothing creates a non-accepted line yet). Build 1 must add `->accepted()` to each (`RentalJobCardLine::scopeAccepted`) in the same commit that first creates a crew line.
4. **`RentalWorkOrder::PHOTO_DISPUTE`** and `RentalWorkOrderPhoto::VIA_TENANT` exist. Today `CrewJobService::payload()` shows every photo that is not `reported` — Build 3 must exclude `dispute` photos there and in `RentalJobCardClientViewService`, and show them only inside the dispute panel.
5. `RentalSecureAccessToken::PURPOSE_TENANT_COMPLETION` has an explicit `isLive() => false` arm so a stray token can never fall through to the contractor rule; Build 3 implements its real liveness.
6. `RentalJobCard::CREW_VISIBLE_STATUSES` now includes `disputed`; `STATUS_DISPUTED` is an OPEN state (`isClosed()` false). `RentalJobCard::complete()` and `RentalWorkOrder::complete()` call `RentalCloseGuards` once each — fill the guard method, never edit those two methods.
7. `selectQuote()` is unchanged. Build 2 wires it to `RentalApprovalGateService::evaluateQuote()` and to `RentalWorkOrderQuote::ownerFacingAmount()` (`selling_amount ?? amount`, so the external-quote fee reaches the owner and the gate through ONE accessor).
8. The in-flight grandfathering back-fill (`approval_basis = legacy_grandfathered`) ran in migration `100500`; Build 2's guard must treat that basis as authorised.
9. Nine domain events landed as classes (catalogue rows added in `corex-domain-events-spec.md`); only `RentalWorkOrderClosed` is dispatched. Each build dispatches its own and registers its listeners inline in `AppServiceProvider` beside the existing rentals listener.
10. Tests: `tests/Feature/RentalMaintenanceFlow/MaintenanceFlowFoundationTest.php` and `CrewPayloadNeverCarriesSellingTest.php`; the existing crew/print/wizard tests were updated for the renames and now assert cost-not-selling.

### 17.25 BUILD 1 — BUILT 6 Oct 2026 (cc4, QA1): what Builds 2 and 3 must know

Build 1 (§17.4 cost and selling, §17.5 the crew prices a job and adds extras, §17.11 the estimate wording) is built between its `// BUILD 1` markers, in its own files, plus the small marked changes the conflict map (§17.21.5) allows. §17.23 assigns no defect to Build 1. **Nothing outside Build 1's scope was changed; the findings below are reported, not fixed.**

**What it does, in plain terms.** A line has two money figures: what it **cost** the agency and what the **owner is charged**. The crew types only the cost; the office sets the owner's price (a typed price, a % or R amount on a line, a % across parts / labour / the whole job, the catalogue's price, or the agency's default % — in that order). Cost and margin are shown only to people who hold `rental_job_cards.view_costs`; setting prices needs `rental_job_cards.price`. The crew can be asked to price a job, and can add extras, from their link or crew page; those lines count nowhere until the office accepts them. Every owner quote carries the agency's "estimate" wording, kept with the quote.

**Files (exclusive to Build 1).** `RentalPricingService` (filled), `RentalCrewPricingService` (new: ask / close / accept / reject), `RentalJobCardVatService` (+ `costBreakdown`, `lineCostVat`, `catalogueDefaultCostForLine`), `RentalJobCardLine` / `RentalJobCard` / `RentalJobCardTask` (`acceptedLines()`, `awaitingOfficeLines()`, `scopeNeedsPricing`, accepted-only `recalcTotal` / `quoteContentSignature` / `subtotal`), `CrewPricingBlock` + `_block-pricing`, `CrewJobService` (`addLine`, `editDraft`, `archiveDraft`, `sendToOffice`), `CrewJobLinkController` + `CrewPageController` (4 actions each), `RentalJobCardPriceRequestController`, `RentalJobCardCrewLineController`, `RentalJobCardPricingController`, `RentalWorkOrderPricingSettingsController` (sibling of the work-order settings controller — own savers, so B2/B3 never edit the same class), `_pricing-panel`, `_lines-table`, `_add-line-row`, `_line-columns-header`, `RentalJobCardLineGrid`, `quote-pdf`, `print`, catalogue form + import, `RentalReportService::jobCards()`, `NotifyAgentOfCrewLines` listener, `_settings-pricing`, wizard rows.

**Shared files touched, smallest marked change.** `routes/web.php` (one `// BUILD 1` block in the corex group for the office routes and settings savers; one new `// BUILD 1` block after the crew-page group for the public crew routes — existing groups untouched), `config/agency-onboarding-copy.php` and `AgencySetupWizardController::currentValues()` (three controls + two savers inside the markers; the controls live on the **`leases`** wizard step — that is where the config keeps the rentals / work-order controls), `AppServiceProvider` (one `Event::listen` line placed BEFORE the §14.28 landlord-mail line, so B2/B3 can append after it), `RentalJobCardService` (`addLine`, `updateLine`, the top of `sendToOwnerAsQuote`, `storePhoto(+$lineId)`), `RentalDocumentPdfService` (`jobCardQuotePdf` gains an optional `$estimateTerm`; `jobCardPrintPdf` passes the cost breakdown), `RentalCrewScheduleService` (the "To price" group), `RentalWorkOrderOwnerMail` view (the term paragraph — B2 retires this mail and must carry the term into `RentalOwnerQuoteMail`), `RentalJobCardController` / `RentalJobCardListQuery` / the list view (the "Needs pricing" tile).

**Decisions where the spec left room (each is reversible by editing one place):**
1. **Blank selling price = "automatic".** The Unit-price box on a saved line is pre-filled with the current price; a value that differs from it is "typed by hand" (`manual`); blanking it, or ticking "Back to automatic", hands the line back to the §17.4.3 rules. (A line with a catalogue item whose default price is set therefore returns to that price — one older test's expectation was updated for this.) The catalogue's default price is no longer copied into the box when an item is picked (it would have frozen every catalogue-priced line as "typed by hand", so rule 5 could never apply); the box shows a placeholder instead.
2. **Cost editing needs `price` AND `view_costs`** (nobody edits a figure they cannot see). A user with `price` only can set selling and markup; a user with `view_costs` only sees cost and margin read-only. The catalogue's **Default cost** and the import's **Cost (excl VAT)** column (appended as column 8, so older 7-column files read as before) are likewise shown / read only with `view_costs`; a blank cost cell never wipes a stored cost.
3. **Margin** is taken over the accepted lines that have BOTH a cost and a price, so a line with no cost can never inflate it; the screen says "n lines have no cost recorded — the margin above covers the other lines only". `RentalPricingService::marginFor()` returns three extra keys (`marginableLines`, `partial`) beside the five the foundation declared.
4. **Crew line photos** are stored with `photo_type = 'crew_line'` (`RentalJobCardLine::PHOTO_TYPE`) and `rental_job_card_line_id`: they show against the line (crew panel, office awaiting block) and never in the job gallery, the tenant/landlord views, or the "completion photo" check. B2's variation notice reads them by `rental_job_card_line_id`.
5. **A crew line's origin:** while a price request is open the crew's lines are `crew_pricing` (linked to the request) unless they tick "this is an extra"; with no open request they are `crew_extra`.
6. **Quoting needs every accepted line priced** (`sendToOwnerAsQuote`: "Price every line first — n lines have no price yet"), only when the agency captures money at all.
7. **A change to the agency's default markup does not re-price open cards** — only a card-level markup (Pricing panel) or a line edit re-prices (`repriceCard`). Re-pricing every open job on a settings change would silently move quotes the owner already holds.
8. **"Ask crew to price" is refused** when the card has no crew, a request is already open, pricing is off for the agency, or crew links are off — each with a plain message; it can mint and email the crew's link in the same step.
9. **Reports:** `jobCards()` — the old "Total cost" column was always SELLING; it is now `total_selling` ("Selling"; for a VAT agency the three existing VAT columns are relabelled "Selling (excl VAT) / VAT / Selling (incl VAT)"). `total_cost`, `total_margin`, `margin_pct`, `lines_without_cost` exist only for `view_costs` — absent from the CSV / PDF / print variants too.
10. **Wizard:** `default_parts_markup_percent`, `default_labour_markup_percent`, `quote_estimate_term` are in the Setup Wizard (rentals step, key `leases`) with `explain` + `affects`, own has()-guarded savers (each checks `rental_work_orders.manage_settings` itself — the wizard bypasses route middleware) and explicit `currentValues()` arms. A wording posted back unchanged is stored as NULL so an agency that never changed it keeps following the built-in text. No setting was left out of the wizard.
11. **Grid layout:** Cost and Margin are real columns (Cost before the selling price, Margin after VAT) for `view_costs` holders; at 1366 px the row is wider than the left panel — see the report for what the real-browser check showed.

**For Build 2.** Call `acceptedLines()` (or `->accepted()`) whenever you read a card's money. Your gate hook `assessAfterLineChange()` is called ONCE per accepted batch by `RentalCrewPricingService::accept/acceptAll` (after totals, VAT freeze and `RentalCrewLinesDecided`); accepted lines are priced and, on a frozen card, VAT-snapshotted before it runs. The quote mail carries `rental_work_order_quotes.term_text` (snapshotted at send, `RentalWorkOrderSetting::quoteEstimateTermFor`) — print it in your new owner/variation mails.
**For Build 3.** A disputed card still takes crew pricing and extras (`CrewPricingBlock` hides the panel only for a closed or archived card). Crew line photos use `photo_type = 'crew_line'`; your `dispute` photos are separate.

**Reported, not changed (outside Build 1):**
- `tests/Feature/RentalJobCards/RentalJobCardPrintQuoteContentTest.php` (3 tests) fails on `origin/QA1` already — it renders `print.blade.php` with `pricesOn` but the foundation's template needs `costsOn` ("Undefined variable $costsOn"). Not caused or touched by Build 1.
- `tests/Feature/Onboarding/RentalsStepIndependentReviewTest.php` (2 tests) fails on `origin/QA1` already — its hand-built POST lacks `capture_prices_on_job_cards` / `show_costs_on_printed_job_card`, so an existing has()-guarded saver answers "That did not save". Confirmed by removing Build 1's two savers and re-running: same two failures.
- §17.4.6 lists "the catalogue-usage counts" as a reader; no such count exists in the code today (nothing to change). `ensureWorkOrderForQuote` does not read lines.
- Reading `rental-catalogue-items/edit.blade.php` + `RentalCatalogueItemController::edit()`: for an agency that captures prices INCL VAT, the Alpine `price` starts as the stored EXCL-VAT `default_price` but is bound to the INCL box — if that is how it renders, the incl box would show the excl figure and each re-save would convert it down again. Code reading only, not exercised in a browser; whoever owns the catalogue should check it (not changed; Build 1's new Default cost field does NOT have this problem — it is converted to the capture mode on edit).

### 17.26 BUILD 2 — BUILT 6 Oct 2026 (cc5, branch `cc5-maint-build2-2026-10-06`): approvals, variations, emergency approval, the external flow

Built exactly to §17.6–§17.9, §17.16 (owner and contractor mails), the §17.14 rows marked B2 and the §17.23 defects assigned to Build 2 (2, 3, 4). Nothing outside those sections was changed except the places named under "Shared files touched".

**What it does.**
- **One gate** — `RentalApprovalGateService` (bodies filled): `evaluateQuote`, `evaluateVariation` (the five steps; measured against `approved_amount` only, so there is no creep), `assessAfterLineChange` (creates / revises / withdraws the work order's variation), `assessExternalQuote` (a higher outside quote after approval), `recordVariationDecision` (portal and office capture, revision-checked, stale = `StaleVariationRevision` → 409), `authoriseToProceed` (+ `authoriseCard` for a card with no work order, + a pure-read `$record = false`), `recordEmergency` / `voidEmergency`, `recordOwnerDecision` (the owner's own decision on a quote). Every decision writes a `rental_approval_decisions` row citing the term (key, value, source, amount tested, ceiling) with the sentence frozen in `note`. `basis` and `term_key` are NOT NULL on that table, so a refusal row cites the term it was tested against.
- **Work does not start unauthorised** — enforced in the models: `RentalJobCard::schedule/start/recordCrewCompletion`, `RentalWorkOrder::startProgress/assignSupplier`. In-flight rows are grandfathered (`legacy_grandfathered`, migration `100500`). Pricing off for the agency = nothing to approve = authorised.
- **Owner work terms per property** — the "Work terms agreed with the owner" panel on a settled rental property's Rental tab (`corex.properties.rental-work-terms.update`, permission `rental_work_orders.manage_work_terms`), append-only history, blank = agency default (shown), zero is a value. `PropertyController::updateRentalDetails` no longer accepts the threshold; **both** threshold inputs on `properties/show.blade.php` are gone (the dead duplicate at ~4415 and the working one at ~4705, which the panel replaces). The lease hub shows both terms.
- **Variations** — `rental_work_order_variations`; auto-approved extras are logged and the owner emailed (`notify_landlord_on_auto_variation`), beyond-terms extras wait (the crew sees "Awaiting owner — do not start"); owner decision in the portal (`POST /api/v1/client/rentals/landlord/variations/{variation}/decision`, `client.rentals.landlord.variations.decision`, listed in `GET …/decisions`) or captured by the office; decline takes the lines out (`declined_by_owner`); a total that falls back withdraws the request; cancelling the work order or card withdraws it. The old "Re-send revised quote" is refused (controller) and hidden (view) once `approved_amount` is set.
- **Emergency approval** — `rental_emergency_approvals`: record / void / attachment (private disk), no amount anywhere; `selectQuote()` never downgrades it; no variation or threshold applies; the final statement and any quote PDF carry "Approved as emergency work on {date}".
- **External contractor flow** — the agency's fee on the contractor's quote (`external_quote_markup_*`, per-work-order override `PUT …/external-fee` for `rental_job_cards.price`; quote row snapshots fee and selling; `ownerFacingAmount()` is the only figure the gate, mails and portal use; the fee is shown only with `rental_job_cards.view_costs`); the first quote over the limit mails the owner (document attached); **"Send work order to contractor"** replaces "Assign supplier" + the plain supplier mail (enabled only once authorised) and sends the Work order PDF with "Owner approval: approved on {date} — {basis}"; a higher quote after approval opens a variation (`origin = external_quote`); the final cost is tested at close (`RentalCloseGuards::assertFinalCostWithinApproval`: within tolerance → auto-approved and logged, otherwise refused in plain words; an open variation blocks the close).
- **Mails** (all `RentalMaintenanceMail extends BaseSignatureMail`, sent AS the agent through `RentalMailDispatcher`; none queued): `RentalOwnerQuoteMail`, `RentalOwnerVariationMail`, `RentalOwnerVariationAutoMail`, `RentalContractorWorkOrderMail`, `RentalOwnerFinalStatementMail` (+ `SendOwnerFinalStatement` listener on `RentalWorkOrderClosed` — **defect 2 fixed**: whichever route closes a work order, the owner gets the final statement). `RentalWorkOrderOwnerMail` / `RentalWorkOrderSupplierMail` are no longer sent by anything (the classes stay so a later merge that still names `STAGE_CREATED` cannot break; `RentalWorkOrderService::notifyOwner()` is now an empty method for the same reason). PDFs: `variationNoticePdf`, `workOrderContractorPdf`, `finalStatementPdf` (selling only).
- **Landlord endpoints** now refuse a decision on a record that is not `pending` (422) — **defect 3 fixed**. The two decision rows `approved` / `declined` are written for the owner's own decision on a quote too.
- **Settings and wizard** — "Owner approvals: extra work and contractor fee" on the Rental Work Orders settings page (`RentalWorkOrderSettingsController::updateApprovals`, one narrow saver, every field `has()`-guarded) and the same four controls in the Rentals wizard step with explain + affects and explicit `currentValues()` arms. The two per-property overrides are recorded under "Deliberately NOT in the wizard" in `agency-onboarding-setup.md` §5.1.
- **Crew** — `CrewApprovalBlock` / `_block-approval`: the "Approved to proceed (emergency)" chip and the per-extra state (Approved / Awaiting owner — do not start / Declined by owner); no names, contacts or amounts. `CrewPayloadNeverCarriesSellingTest` is extended for it, not relaxed.
- **Report** — `landlordPropertyActivity()` totals use the owner-facing amount and flag emergency work (owner-facing, never cost or margin).
- **Domain events** `RentalVariationRaised`, `RentalVariationDecided`, `RentalEmergencyApprovalRecorded` are dispatched; catalogue rows updated.

**Decisions taken where the spec was silent or two readings fit (please confirm or overrule).**
1. **"Contractor reports done" (§17.9.6) was NOT built here.** §17.9 heads it Build 2 but §17.21.4 lists the "Contractor reports done" action in Build 3's exclusive files and it opens a completion round (`RentalCompletionService`, Build 3). Left to Build 3 to avoid two builds writing the same card.
2. **"Approved" includes auto-approved.** Once `approved_amount` is set — by the owner OR by the no-approval limit — the §14.21 re-send is retired and extra work becomes a variation. (So a job quoted inside the limit and then added to is measured against what was auto-approved.)
3. **A higher quote on an outside work order after approval is a variation, and the work order cannot go to the contractor until the owner answers** (an outside job IS the quote — there is no "approved scope to carry on with"). An internal job carries on with the approved scope.
4. **The contractor's own quote document is not attached to the owner's mail when the agency's fee is on it** (it would show the agency's margin); the mail states the owner-facing total. With the fee at 0 (the default) the document is attached as §17.9.3 says.
5. **A job card with no work order** (older rows; §17.3 makes new ones always have one) is judged on its own amount against the property's limit; nothing is recorded (no work order to hang it on).
6. **Owner mails are sent synchronously through the dispatcher, best-effort** (QA has no queue worker; a failing mailbox never blocks the office). The old "decision needed" setting `notify_landlord_on_decision_needed` gates the automatic prompt for an OUTSIDE quote; the quote mail for a job card the agent presses "Send to owner as quote" on is always sent (the agent asked for it).
7. **`assessAfterLineChange()` is called from the four line methods of `RentalJobCardService`** (add / update / archive / restore — one marked line each) and from `RentalJobCardPricingController::markup()` (one marked line, after the card-level markup loop), because §17.21.2 only tells Build 1 to call it after *accepting* crew lines (`RentalCrewPricingService::accept/acceptAll`, which Build 1 does) — which would leave "the office adds an extra line, or sets a % across the whole job, on an approved job" unguarded. A change to the agency's DEFAULT markup does not re-price open cards (Build 1's ruling), so it cannot raise an approved total.
8. **A declined variation's lines are `declined_by_owner` and leave every total** (Build 1's accepted-only readers are merged on QA1). `VariationFlowTest::test_the_owner_declines_in_the_portal_and_the_lines_come_out` proves the status flip; the end-to-end test written by the last build to merge should assert the card total too.
9. **`RentalJobCardService::complete()` still swallows the work order's `LogicException`** (defect 1, Build 3). With Build 2 that now includes "a variation is waiting for the owner" and "final cost above approval": the card closes and the work order stays open until Build 3 stops swallowing it.

**Shared files touched (smallest marked change each; merge `origin/QA1` before pushing):** `routes/web.php` / `routes/api.php` (BUILD 2 blocks), `config/agency-onboarding-copy.php` + `AgencySetupWizardController::currentValues` (BUILD 2 blocks), `RentalJobCardService` (tail of `sendToOwnerAsQuote` + the four `assessApproval()` lines + one private helper), `RentalJobCardController::sendQuote` (approved-job refusal), `rental-job-cards/show.blade.php` (the re-send button, one `@elseif`), `rental-job-cards/quote-pdf.blade.php` (emergency banner), `RentalDocumentPdfService` (methods + one view variable), `RentalReportService::landlordPropertyActivity` (one line), `rentals/portal/shell.blade.php` (the variation card + `decideVariation()`), `leases/show.blade.php` (one line), `RentalPortalScopeService` (two methods), `AppServiceProvider` (one listener), the work-order show view's quote/supplier/approval cards.

**Existing tests changed (the spec retires what they asserted):** `RentalWorkOrderQuoteTest` and `RentalWorkOrderAuditFixesTest` (a higher quote after approval = a variation, not a reset), `RentalWorkOrderLifecycleTest` (assigning needs an authorisation), `RentalWorkOrderSettingTest` (the limit moved to the work-terms route), `RentalJobCardFollowUpsTest` / `RentalJobCardAt442FollowUpTest` (mails via the dispatcher; the §14.21 re-send needs an unapproved quote; after approval it is a variation), `RentalJobCardScheduleDatesTest` (the card needs one priced line), `MaintenanceFlowFoundationTest` (the gate is no longer inert), `BuildsCrewLinkFixtures` (the fixture property's no-approval limit is set high so jobs "already under way" are authorised; `BuildsApprovalFixtures` resets it).

**Not built / reported.** The §17.17 list-screen filters "Awaiting owner" and "Variation pending" are not assigned to any build in §17.21 and were not built (job-card "Needs pricing" is Build 1's). The `.ai/CHAT_STARTER.md` line is added. `tests/Feature/Onboarding/RentalsStepRuledInSettingsTest` fails 3 tests with "That did not save" — a hand-written payload gone stale (the same class `RentalsStepSaverIndependenceTest` already fixed with `browserFormFields()`); not touched here. `scripts/verify-alpine-render.mjs` reports `[rentalsPortal].promptNote() ERROR: window.prompt is not a function` on the portal page — a gap in the script's stub, identical on the deployed QA1 page before this build; all 195 expressions on the page compile clean.

### 17.27 BUILD 3 — BUILT 6 Oct 2026 (cc3, QA1): the one "Create work order" action, the tenant completion check and the dispute

Built exactly as §17.3, §17.10 and the Build 3 rows of §17.12/§17.14/§17.16/§17.17 say, between the `// BUILD 3` markers the foundation left, plus the three defects §17.23 assigns to this build. Johan's rulings that shaped it: when work is reported done the **tenant is told and can DISPUTE it with photos**, which puts the job in a **Disputed** state the office must resolve; the crew and a contractor never see selling; every threshold is a setting with a default.

**What now happens (plain words).** A fault report has ONE "Create work order" button (Internal crew by default, or External contractor). Internal makes the work order and its job card together; External makes the work order only. "New Job Card" anywhere is just a doorway to that same form, and no job card can exist without its work order. When the crew (link, crew page, signed paper copy or the office's "Worker — done") or a contractor (captured by the agent) says the work is done, a **round** opens and the tenant gets an email with a one-click page (and the same question in the portal's Jobs tab): "All done, thanks" or "Not complete / still wrong" with a note (at least 5 characters) and up to 10 photos. "Not complete" turns the work order AND the job card **Disputed** (a completed card is reopened, its worker and agent sign-offs are remembered in the round and cleared so they can be given again), tells the agent and the branch manager, emails the owner (setting), and blocks the final close until the work is reported done again — which opens round 2 and asks the tenant again. Silence for the response window (default 5 days) counts as accepted (daily command). The office can record a tenant's phone answer, and "Send back to crew / contractor" emails the tenant's note and photos (crew: with a fresh private link).

**Files (all new unless marked).** Services: `RentalCompletionService` (bodies; was a shell), `RentalWorkOrderClientViewService`, `RentalCloseGuards::assertNotDisputed` (filled), `RentalWorkOrderService` (`fromFaultReport` gate via `RentalFaultReport::workOrderBlockReason()`, `announceCreated`), `RentalJobCardService` (`createStandalone` makes the work order up front; `createForProperty` announces; `complete` no longer swallows the work-order refusal), `RentalPortalScopeService` (+`tenantWorkOrders`, `tenantCompletionRound`), `RentalSecureAccessTokenService` (+`issueForCompletionRound`, `?User` actors), `LeaseTimelineService` (+`maintenanceFlowEntries`, three types), `RentalReportService::workOrders()`, `CrewDisputeBlock`, `CrewJobService` (dispute photos out of the general gallery). Controllers: `CompletionResponseController` (public), `RentalWorkOrderCompletionController` (contractor-done, tenant's answer on their behalf, send-back), `RentalCompletionSettingsController` (own narrow saver), `Api\V1\ClientRentalWorkOrdersController` (tenant list, completion-response, landlord show), plus edits to the fault, work-order, job-card, tenant-portal and landlord-portal controllers. Models: `RentalWorkOrder` (`markDisputed`, `returnFromDispute`, `mirrorCompletionAnswer`, `latestCompletionRound`), `RentalJobCard` (`reopenForDispute`, `returnFromDispute`), `RentalWorkCompletionRound` (labels), `RentalSecureAccessToken` (real `tenant_completion` liveness), `RentalFaultReport` (`workOrderBlockReason`, repaired-outcome guard). Jobs/Mail/Listener/Command: `SendTenantCompletionCheckMailJob`, `SendLandlordDisputeMailJob`, `RentalTenantCompletionCheckMail`, `RentalLandlordDisputeMail`, `RentalDisputeSentBackMail` (+3 email views), `OpenCompletionRound` (listens on `RentalJobCardCrewCompleted`, registered inline in `AppServiceProvider`), `rentals:settle-completion-rounds` (scheduled daily in `routes/console.php`). Views: fault show (one form + chips), `corex/rental-completion/_panel` (shared by the work-order and job-card `_completion-panel` slots), `rentals/completion/{show,answered,unavailable}`, crew `_block-dispute` + the "Report fixed" relabel in `_job-body`, portal shell Jobs tabs, settings `_completion`, list tiles/filters ("Disputed"). Routes: `routes/web.php` and `routes/api.php` between the `BUILD 3` markers (public `secure/completion/{token}` sits beside the crew links, also marked).

**Decisions where the spec left room (each is easy to change):**
1. **One check per job.** A second "reported done" while the tenant check is still open (the crew's link, then the paper copy) does not open a second round and does not email the tenant twice; it is logged on the work order. After a dispute, or once the earlier check is answered/settled, a new report opens the next round.
2. **The tenant email is queued** (like the owner's crew-completed mail), so a crew's phone never waits on SMTP; `tenant_notify_status` stays empty until the job records `sent` / `failed`.
3. **Contractor reports done** is allowed on an ordered, in-progress or disputed outside job ("assign the supplier first" otherwise) and moves `ordered` → `in_progress`; it never closes the work order.
4. **Reopening a COMPLETED card revokes its old crew link** (closing had killed it; reopening must not quietly revive it). The crew gets a link again only through "Send back to crew" (or the agency's immediate-send setting). A card that was merely in progress keeps its live link, which then shows the dispute banner.
5. **Portal Jobs.** The tenant's list is the new `GET rentals/work-orders`; the landlord's list is the existing `GET rentals/landlord/work-orders`, each row now carrying a `client` view; `GET rentals/landlord/work-orders/{id}` is new. The job-card endpoints stay registered and tested but the shell no longer links them.
6. **Margin on the work-order report** appears only for users with `rental_job_cards.view_costs`, only for an internal job whose card has a recorded cost, and is blank otherwise — it fills in as Build 1 records costs (it calls Build 1's `RentalPricingService::marginFor`).
7. **Creation announcement.** `announceCreated()` is the one entry point; `report()` and `fromFaultReport()` keep calling the same `notifyCreated()` they always did (so the event fires once per work order, never twice).

**§17.23 defects fixed here:** #1 `RentalJobCardService::complete()` no longer swallows the work-order refusal — card and work order close together or not at all (one transaction); #5 `createForProperty` now announces `rental_work_order.created`; #6 the tenant's "is this finished?" now has a screen in the portal shell (and on the response link).

**Existing tests that encoded superseded rules and were updated (not loosened):** `RentalJobCardRebuildTest` (a card now gets its work order up front; the create URL is an alias), `RentalWorkOrderLifecycleTest` and `RentalJobCardLifecycleTest` (no inherited approval), `CrewJobServiceTest` and `CrewPageActionsTest` (the close needs the owner's approval for a cost above the limit, since a refusal now surfaces), `MaintenanceFlowFoundationTest` (the completion service and the dispute guard are no longer inert).

**Reported, not changed:** (a) `tests/Feature/Onboarding/RentalsStepIndependentReviewTest.php` — 2 tests fail with or without this build (their hand-written wizard payload lacks `capture_prices_on_job_cards`); (b) `ContractorSecureLinkController::markDone` (an unminted link) would now hit the dispute guard and throw — add to §17.23 item 1; (c) the work-order and fault screens never showed `withErrors` messages (I added a banner to the fault screen and an error line inside the completion panel only); (d) `rental-job-cards/show.blade.php` still carries its old create-mode branch, now unreachable by GET; (e) `.ai/CHAT_STARTER.md` is far over its 350-line cap.

**Tests (all single files via `scripts/lane-test.sh`):** `CreateWorkOrderActionTest` (17), `CompletionRoundTest` (15), `TenantCompletionResponseTest` (21), `DisputeLifecycleTest` (22), `SettleSilentRoundsCommandTest` (8), `FaultOutcomeGuardTest` (8), `ClientWorkOrderViewTest` (12), `LeaseTimelineMaintenanceFlowTest` (9), `CompletionSettingsWizardTest` (12), and `CrewPayloadNeverCarriesSellingTest` extended with the dispute block (4 more data-set runs: the complaint reaches the crew, selling never does, in every setting combination). They prove: every route that reports work done; no tenant / no email / check switched off; the stored window; confirm and dispute on the link, the portal (and its old `confirm` alias) and by the office; required-empty, 4-character note, 11 photos, non-image, repeat answer, forged/revoked/expired/wrong-purpose/archived link (one identical page), a passed window, the throttle; both records Disputed, reopen + snapshot, close refused everywhere and never half-done; send-back for crew and contractor; the crew banner and "Report fixed"; next round and every round kept; silence settled only after the window and never a dispute; the fault outcome guard; tenant never sees money, landlord only the owner-facing amount; own/branch/agency scope and the permission key on every new route; a second agency with its own settings.

**Merged with Builds 1 and 2 (6 Oct 2026, before the push to QA1).** Both had already landed on `origin/QA1`; the conflicts were small and are resolved like this: `CrewJobService` photo gallery keeps out BOTH dispute photos (mine) and crew-line photos (Build 1); `RentalJobCardController::create` stays the Build 3 alias (Build 1's edit to the old create-mode screen no longer has a page to apply to — the create-mode branch of `show.blade.php` is dead code a later clean-up can remove); `AppServiceProvider` keeps both listeners (`SendOwnerFinalStatement`, `OpenCompletionRound`); the domain-events catalogue keeps Build 2's three rows and my three; the foundation test now says the dispute guard is live and the cost guard measures only approved work. The lease tenancy log gained its three types at the END of `LeaseTimelineService::TYPES` (a later lease e-sign build should append after them). Build 2 made `RentalWorkOrderService::notifyOwner()` a no-op, so the owner gets no plain creation mail (§17.3.4 holds); `announceCreated()` still fires `rental_work_order.created` once per work order.

### 17.28 RECONCILIATION — 7 Oct 2026 (cc4, QA1): the three overnight builds made coherent

Nothing here changes a ruling. Each failing test was decided from §17 and Johan's rulings (§17.22): stale test → updated to the ruled behaviour; wrong code → fixed at the cause; unsettled → left failing and listed for Johan.

**Stale tests, updated (and why).**
- `RentalJobCardPrintFollowUpsTest` (4 of 5) — §17.6.5 / §17.12: a card is schedulable only once authorised, and a card with nothing priced is not ("price the job or send the quote first"). The tests now give the card one priced line inside the owner's limit.
- `RentalJobCardListQueryTest::test_quote_indicator…` — §17.0 item 1 / §17.7.1: a revised quote is re-sendable only until an amount is approved; the test uses a limit BELOW the quoted amount (the quote is genuinely waiting on the owner).
- `RentalJobCardPrintQuoteContentTest` (3) — §17.4.5 / §17.4.7: the worker print is the COST copy (`costsOn`, `costVat`); §17.3.1: every card has a work order, so "No source — created directly" is only for older rows.
- Wizard tests — hand-written Rentals-step payloads replaced by the real-browser baseline (`PostsWizardStepLikeABrowser`); step count 16 → 18 (AT-395 `outgoing_mail`, `leases`); the "Public website" switch is not in onboarding (Johan, 2026-08-12); first-login tests opt out of the factory's "already onboarded" default; the compliance step needs the financial-year month and is now followed by `outgoing_mail`; `agencies.store` needs its first branch.
- `RentalJobCardFollowUpsTest::test_quote_download_is_not_reachable_from_another_agency` called `refreshApplication()`, which boots a second application on a second connection OUTSIDE the wrapping transaction: it COMMITTED an "Other" agency, branch and user into the shared lane-test schema (doubling `RentalFaultTypeCatalogueTest`'s count and failing it), and the 404 it asserted was vacuous (the card was invisible on that connection). Now built through the service with the other agency created while nobody is authenticated (`BelongsToAgency` re-homes a model created by a logged-in user to THEIR agency), and it also proves the owning agency CAN reach the URL.

**Gap (a) — close together or not at all.** `RentalJobCardService::complete()` no longer swallows the work order's refusal (Build 3, §17.23 #1). Proven in the browser and by `JobCardCloseTogetherTest` for every refusal — an open tenant dispute, extra work still waiting for the owner, a missing "completed" photo: the message is shown on the card and NEITHER record changes. The reverse hole is closed too: the work-order **Complete** form could close an INTERNAL job's work order and leave its card open; it now refuses and says to use "Complete job card" (a dispute still gets its own, more specific words).

**Gap (b) — list filters (§17.17).** "Awaiting owner" (`owner_approval_status = pending`) and "Variation pending" (an open `awaiting_owner` variation) are tiles with counts and yes/no filters on BOTH the Job Cards and Work Orders lists; the print list and export follow the filter; counts use the same own / branch / agency base as every other tile. A closed (completed / cancelled) job is waiting on nobody and is in neither. Tests: `MaintenanceListFiltersTest`.

**Found in the real-browser walk and fixed (all inside the flow):**
- the quote box said "The owner has approved this job" for a job only COVERED by the owner's no-approval limit — the words now follow the approval basis;
- after the owner approves an amount the card and the list still said "Changed since sent — re-send to update the owner", although re-send is retired (extras are variations) — shown only before approval (`quoteChangedSinceSent()` itself is unchanged);
- after the tenant said "not complete" the card's sign-off area still read "Tenant — confirmed fixed" (the §17.10 mirror sets `tenant_confirmed_at` for ANY answer) — it now says the tenant said the work is NOT complete;
- the tenant's dispute photos were listed in the crew's "Please revisit" mail and the owner's dispute mail as the relative path `/storage/…` — a dead link in an inbox. A public property of a Mailable OVERRIDES a same-named key of `with()`, so the mapped absolute URLs were replaced by the raw list; the mapped list now travels as `photoLinks` (`DisputeMailPhotoLinksTest`; no other Mailable has the pattern).

**Reported, NOT changed (outside the assigned scope):** (1) the office's job-card photo form posts to the JSON endpoint, so a photo upload lands on a raw JSON page (since AT-442; `RentalJobCardController::storePhoto`); (2) Build 1's catalogue observation was REAL — reproduced in a browser at ~08:25 on an incl-VAT agency (typing R115.00 incl stores R100.00; the edit screen then showed 100 in the incl box and every plain Save lowered the price: 100.00 → 86.96 → 75.62) — and was FIXED meanwhile by another lane (`93356a40c`, 08:39: the edit screen shows the price in the agency's capture basis); re-verified in three fresh browser runs afterwards: the box shows R115 and a plain Save leaves R100.00; (3) the work-order screen labels the line "Why was this approved?" while the job is still awaiting the owner; (4) the crew link offers "Mark work completed" on a job nobody has authorised (refused in plain words, but offered); (5) 5 `tests/Feature/Leases` tests fail on QA1 for lease-hub / expiry-rule reasons (AT-440, `66b7fa966`) — not these builds.

**Left failing on purpose — needs Johan:** `RentalJobCardPrintFollowUpsTest::test_scheduling_a_draft_card_no_longer_hides_the_quote_box_and_a_send_keeps_it_scheduled`. A job inside the owner's no-approval limit is auto-approved the moment it is scheduled (§17.6.5), and from then the "Send to owner as quote" button is hidden (§17.7.1). §14.23 (6 Oct) says the quote box is offered on every open card. The spec does not say which wins for a first quote on an already-covered job.

### 17.29 CLEANUP — 7 Oct 2026 (cc2, QA1): the photo form, two wrong-state wordings, and the red tests that had no owner

Nothing here changes a ruling; it clears defects found by the §17.28 browser walk and the Staging-readiness map.

**A. Job-card photo upload (defect since AT-442).** The office's photo form on the card posted to `RentalJobCardController::storePhoto`, which only ever answered JSON, so a browser landed on a page of code. The action now serves both callers: a normal form post saves the photo (same `RentalJobCardService::storePhoto` pipeline, immutable `rental_work_order_photos` row, still cross-referenced onto the linked work order) and **redirects to the job card** with "{Before / In progress / Completed} photo uploaded." and the Photos block already open (`jc_open_photos`); a validation error or a failed store goes back to the card with the message and saves nothing (messages are plain: choose a photo / JPG-PNG-WEBP-HEIC / 50 MB limit / try again). A caller that asks for JSON (`Accept: application/json`) still gets `201` + the photo, or `422`. Nothing is hard-deleted. Who sees an office photo is unchanged and by photo TYPE, not by uploader: the tenant/landlord rule is `crew_photos_visible_to_clients` (§14.27.1 Q7 / §14.29), `reported` never. Proven by `JobCardPhotoUploadAndWrongStateTest`.

**B1. The approval line is worded for the state.** `RentalWorkOrder::approvalReasonLabel()` decides the words in front of the latest decision's note, on the work-order Approval panel and the job-card approval summary: waiting for the owner → "Why is the owner's approval needed?"; declined → "Why was this declined?"; an approval basis exists (owner decision / no-approval limit / tolerance / emergency / under way before approvals) → "Why was this approved?"; nothing decided → "Approval decision:". It never says "approved" about a job that is not.

**B2. The crew link does not offer "Mark work completed" on an unauthorised job.** `CrewJobService::jobPayload()` carries a plain `authorised` boolean (a pure read of `RentalApprovalGateService::authoriseCard($card, false)` — never the gate's note, which can carry amounts). While it is false the page replaces the form with "This job has not been approved yet — contact the office. This button appears once the job is approved." The server refusal in `RentalJobCard::recordCrewCompletion()` is unchanged and still the authority (a hand-made POST is refused).

**C. Red tests with no owner — each decided from the spec.** *Stale test → updated to the ruled behaviour; wrong code → fixed; never loosened.*
- `Leases` ×5 — `CheckLeaseExpiryCommandTest` (2): (a) its "database only" check called `Notification::assertNothingSent(fn…)`, which takes no filter and so failed after ANY send — now asserts the real channel list `['database']`; (b) "legacy LeaseRecord path still fires" — stale: the command was deliberately **repointed** from `lease_records` to `leases` (`leases.md` §E, AT-439), it no longer reads the legacy table — the test now asserts exactly that. `LeaseFromApprovalTest` — stale: the §1.3a "never touch property status" carve-out was closed by Johan's Gate 2 approval of AT-444 item 7 / §12.5 point 1 (an activated lease lets the property out) — the test now asserts `let_out` + `status_before_letting`. `LeaseTypeVisibilityTest` settings test — stale: the real settings form posts a hidden `show_lease_type_field=0` when unticked, and a POST that does not carry the key at all (the wizard saver) must leave the value alone (`agency-onboarding-setup.md` §6.1) — test now posts the hidden 0 and also proves the absent key changes nothing. `LeaseTypeVisibilityTest` "read-only rent on the lease edit screen" — **code was wrong**: Johan's 2026-09-22 ruling (read-only Monthly rental on the edit screen) was lost when the Lease Hub rebuilt the edit panel (AT-440); restored.
- `AgencySetupWizardTest` ×7 and `AgencySetupWizardAtomicSaveTest` ×1 — already green on current QA1 (fixed by the §17.28 reconcile); no change.
- `ViewingPackRedactionEndpointTest` — its fixture inserted `NULL` into the NOT-NULL `viewing_packs.contact_id`; the "external agency, no contact" pack is only a DRAFT spec (`viewing-pack-external-agency.md`) and was never built — fixture now creates a real buyer.
- `ContactCommunicationSendStatusTest` ×4 — stale: AT-323 (`b2e75cfc7`) deliberately removed the "Revert" route and the silent server-side "Resend" route (nothing may reach *sent* without the modal). Tests now cover the modal path (`…/mark-sent`), the service-level linked resend, and that no revert/resend route exists.
- `SidebarNavMappingTest` — **code was wrong**: the Agency Timeline pages (AT-447) did not open the Agency group; `admin.timeline-defaults.*` (a Dev Settings page reached from the Dev Settings index) lit no sidebar item. Both mapped in `corex-sidebar.blade.php`.
- `BuyersReportScopeResolverTest` and `BuyersReportPrintPdfTest` — dropped and re-created core tables (`users`, `agencies`, `contacts`, …) in the lane's persistent test schema; now `RefreshDatabase` with real rows.

### 17.30 Work-order photo upload — 7 Oct 2026 (cc2, QA1): the work-order screen's photo form no longer lands on raw JSON

The same defect §17.29 A fixed on the job card (found in the §17.29 report, §4): the Photos block on the work-order screen posted to `RentalWorkOrderController::storePhoto`, which only ever answered JSON, so an office upload left the person on a page of code. Fixed the same way, nothing else changed.

**Behaviour.** The action serves both callers. A normal form post saves the photo (same `RentalWorkOrderService::storePhoto` pipeline; an immutable `rental_work_order_photos` row, no hard delete) and **redirects to the work order, landing on the Photos block** (`#wo-photos`), where the message "{Before / In progress / Completed} photo uploaded." is shown above the thumbnails and the new thumbnail is already in the grid. The block is always open on this screen, so there is no open flag. A validation error or a failed store goes back to the same place with a plain message and saves nothing: choose a photo / JPG-PNG-WEBP-HEIC / 50 MB limit / "could not be saved — nothing was uploaded, try again" / choose the photo type. Errors travel in a named `photo` error bag and the success in `wo_photo_message`, so they appear inside the Photos block (which is low on the page) and never also in the page-wide banner at the top. A repeat of the same `client_idempotency_key` saves nothing new and says "That photo was already uploaded." A caller that asks for JSON (`Accept: application/json`) is unchanged: `201` + the photo, `200` + the existing photo for a repeated key, `422` on a validation error.

**Unchanged.** Permission (`rental_work_orders.create`) and own/branch/agency scoping (`guardRentalRecordScope` — another agency's work order is a 404 and nothing is saved). Who sees an office photo is by photo TYPE, not uploader (§14.27.1 Q7 / §14.29): `completed` and `in_progress` per the agency rule, `reported` never.

**Proven by** `WorkOrderPhotoUploadTest` (8): save + redirect + message + thumbnail on the page; one confirmation per type; no message on a normal visit; four error cases save nothing and show their message in the block, not the top banner; JSON callers still get JSON incl. the repeated key; a repeated form post saves once; client visibility by type; another agency's work order refused. The four behaviour tests fail against the old controller.

### 17.30 BACK-HALF WALK — 8 Oct 2026 (cc6, QA1): the back half of maintenance walked end to end on all three routes

Walked as an agent, the owner, the tenant and a crew member would, with real requests: `tests/Feature/RentalMaintenanceWalk/BackHalfWalkTest.php` (10 tests, 236 assertions — one per route plus the side paths) and the same sequence replayed on QA1's real Lease 21 data inside a rolled-back transaction (mail faked). After EVERY step it asserts the tenant's and owner's progress line (`RentalFaultProgressService`), the work-order stage label each of them sees, that the tenant payload never carries the job card or a price, and (at the key states) that every office screen still draws.

**Fixed (each with a test in the walk file):**
1. **Starting an internal job card never started its work order** (§17.12: card `start` → work order `in_progress`). The work order stayed "reported" while the crew worked, so the lists, the overdue queue (which only looks at ordered / in progress) and the owner's and tenant's stage all said "not started". `RentalJobCard::start()` now mirrors the stage through `RentalWorkOrder::markStartedByJobCard()` (a mirror only — the card already passed the authorisation gate, including the grandfathered and emergency cases, so it never re-judges approval).
2. **Cancelling one half of a job left the other half live** (§17.10.9 "close together or neither" was only enforced for completion). Cancelling the work order left its job card open with a live crew link; cancelling the card left an open work order. Each cancel now cancels the other (a completed one is never touched; no loop).
3. **A fault was stuck behind its cancelled work order** — `work_order_raised` blocked any new work order, so a repair whose contractor fell through could not be re-arranged. `RentalFaultReport::hasLiveWorkOrder()`: a CANCELLED work order no longer blocks "Create work order"; the new one replaces the link (the old one stays in the history).
4. **Internal close was hard-wired to "paid by owner"** with no way to say the tenant (damage) or the deposit pays. "Complete job card" now asks "Who pays for this job?" (owner by default; tenant; deposit deduction; not yet paid) and passes it to the work order's close. (The outside-contractor Complete form always had this.)
5. **A tenant dispute reached nobody's queue** — the agent got one in-app note and nothing stayed on the rentals command centre. New needs-action item **"Resolve dispute"** (`work_order_disputed`), same own / branch / agency query scoping as the other rules; it leaves when the work is reported done again (status leaves `disputed`).
6. **The progress line said "Sent to contractor" too early on the agency-contractor route** — at work-order creation, before any quote or the owner's authorisation. Step 5 is now reached when the office actually sends the work order (`ordered_at`), or sooner only if a later step (appointment) already happened. Internal crew and the owner's own contractor are engaged from creation as before.

**Walked and working (no change):** work order from an approved fault on all three routes; quote capture / select; owner authorisation in the portal (approve and decline — a declined quote cannot be sent or decided twice, the tenant never sees the word); work order to the contractor (PDF mail to the supplier's address only); appointment set and changed (agent and, for the owner's contractor, the owner — tenant mailed); job card tasks, priced lines, send-quote, schedule, crew link (page, tick, photo, sign complete); work started; reported complete (crew sign, contractor captured, owner "finished") → tenant check; tenant confirm and "not complete" (work order AND card reopen, crew link live again, round 2); agent sign-off and close with the cost recorded against owner / tenant / deposit; emergency approval starting work with nothing priced; extra work after approval going to the owner as a variation and being decided on the work order; fault outcome after close (no second tenant mail).

**Reported, not built (a feature or Johan's call, not a break):** (a) there is no place to upload the **supplier's invoice document** against a work order — the cost is recorded, the document is not (§17.9.7 puts invoice upload out of scope); (b) the property agent, not the lease's owner-side / tenant-side agent, receives the work-order created / completed / overdue in-app notes (only the owner-action notes use the lease agents); (c) a work order waiting on the owner's quote shows no "Awaiting owner" item on the command centre — it is only a filter on the work-order list; (d) QA1 has no scheduler, so "no answer in N days = accepted" (`rentals:settle-completion-rounds`) must be hand-run there.
