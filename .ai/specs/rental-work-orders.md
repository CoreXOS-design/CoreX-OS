# Rental Work Orders

**Status:** Spec — not yet built. NO CODE has been written against this spec.
**Date:** 2026-09-14 (amended 2026-09-22 — see below)
**Author:** cc4
**Pillar:** Property (`Property`) — every work order anchors to a property; Contact (owner, tenant,
supplier's own contact person) is who is notified and who reported it; touches Lease (`.ai/specs/
leases.md`) and Rental Inspections (`.ai/specs/rental-inspections.md`, both now landed and built) as
its two structural dependencies.
**Sequencing:** Johan's ruling — core matches/pipeline → inspections → **work orders (this spec)**.
Both dependencies are read, coordinated on directly, and cited below; neither is re-designed here.

**Amendment, 2026-09-22 — Johan has ruled work orders IN SCOPE.** This revision settles what he
settled, adds an owner-approval gate his new ruling requires, corrects one factual claim (§4, the
WhatsApp send capability), and names four questions he has been asked but has not yet ruled on —
these are argued honestly and left open, not decided here (§1a, §3.2a, §3.4a, §3.4b). See §0a for
exactly what changed and why, and §13 for the mobile-foundation constraint this amendment also adds.

---

## 0a. What this amendment settles, adds, and leaves open

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

**Four things Johan has been asked and has not yet ruled on — argued honestly, left open:**
1. §1a — is a tenant fault report an inspection, or a separate thing? (The conductor's own live
   argument with Johan; ad_hoc is examined and, argued here, does not solve it.)
2. §3.2a — how does a tenant, who has no CoreX login, actually report a fault?
3. §3.4a — how is the owner's approval captured and retained as evidence, not just obtained?
4. §3.4b — the agency-configurable spend threshold below which no approval is required.

**Corrected:** §4's notification section overstated WhatsApp — confirmed directly against the code,
there is no automated WhatsApp send anywhere in CoreX, only a `wa.me` link an agent opens and sends
from their own phone. Said plainly now instead of implying parity with the automated email path.

**New:** §13 — the mobile-foundation constraint, per the same ruling that now applies to
`rental-inspections.md` §14: an agent raises a work order standing in the property, so the logic
behind every action here lives in a service Andre's app can call, not in a controller or a Blade.

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
orders can arise from inspections (§3.2, already this spec's design). What is raised but not yet
answered: whether a fault report IS an inspection record (§1a) and how the owner's approval is
actually captured (§3.4a) are both open. **On "email / and or whatsapp": checked directly against the
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

## 1a. Is a tenant fault report an inspection? (open — the conductor's live argument with Johan)

Johan's words, as put to me: *"tenant report a fault that at this stage should be logged under
inspections?"* The conductor's own view, argued against that: *"An inspection is a scheduled event
with a checklist at a point in time — in and out. A tenant reporting a burst geyser on a Tuesday is
unscheduled and has no checklist. Forcing it into the inspection record means mid-tenancy faults
pollute the in-versus-out comparison, which is the whole evidentiary point of inspections."* Her
proposal: a fault report is its own record that CAN link to an inspection but need not, and both a
fault report and an inspection can raise a work order.

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

**My argued position, offered as a recommendation, not decided here:** a tenant fault report does not
need to be a third record type at all. It can simply be the FIRST STATUS of a `rental_work_orders`
row — `reported_by_type='tenant'` already exists in this spec's own schema (§3.2) for exactly this.
"Logged under inspections" and "logged as a work order, optionally bridged to an inspection via
`reported_inspection_observation_id`" describe the same underlying need from two directions — Johan's
instinct that it should connect to inspections is already satisfied by that nullable FK, without
needing the fault report itself to BE an inspection. This is a different resolution shape than the
conductor's own proposal (hers: two linked record types; mine: one record, the work order, already
capable of standing alone via `reported_by_type='tenant'` or bridging via
`reported_inspection_observation_id`) — both are laid out here honestly because this is Johan's
decision, not mine. What is NOT in question, on either resolution: `type='ad_hoc'` is not the answer,
for the three reasons above.

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
                                --   to 'not_required' at creation — becomes 'pending' the moment
                                --   an agent requests approval, per whichever mechanism §3.4a
                                --   settles on, or stays 'not_required' if the cost is under the
                                --   agency's approval threshold (§3.4b, also not yet settled). The
                                --   GATE is settled; the fields recording HOW an approval was
                                --   actually captured are deliberately NOT added to this table yet
                                --   — see §3.4a, open.
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
                                  --   'inspection' — §3.2. This is the field that answers
                                  --   "tenant-reported, agent-noticed, or owner-instructed carry
                                  --   different weight in a dispute," per the task's own
                                  --   instruction.
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
  completion_requires_photo             -- bool, default true — Johan's ruling: "photos of the
                                        --   work conducted." An agency CAN turn this off (every
                                        --   threshold is agency-configurable per the task's own
                                        --   instruction), but the sensible default matches the
                                        --   ruling, not a weaker posture.
  overdue_reminder_days                 -- nullable int, default 3 — days since 'ordered' with no
                                        --   status change before the internal reminder
                                        --   notification (§4) fires. [cc4 design call — a
                                        --   sensible number, not specified by Johan; agency-
                                        --   configurable exactly because of that.]
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

- **`tenant`** — the tenant themselves reported it (a call, a message, a portal report if one exists).
  `reported_by_contact_id` is the tenant's own contact row (ideally one of the lease's `lease_tenants`,
  though this spec does not hard-enforce that match — a former tenant or a neighbour could plausibly
  report something too).
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

### 3.2a How does the tenant actually report? (open — a real front door does not exist yet)

**Checked directly against the code, not assumed:** a tenant is a `Contact` row
(`lease_tenants.contact_id` → `contacts.id`), and `Contact extends Model` — not
`Illuminate\Foundation\Auth\User as Authenticatable`, no password column, no auth guard. **A tenant
has no CoreX login today, anywhere in the system.** `reported_by_type='tenant'`'s own wording above —
"a portal report if one exists" — was already an honest placeholder; there is no such portal. Without
one of the options below, `reported_by_type='tenant'` can only ever mean "the agent typed this in on
the tenant's behalf" — which may be exactly right for now, but should be a decision, not a default
nobody noticed.

Three real options, each costed against what already exists in this codebase rather than invented
fresh:

1. **A tokened link, mailed to the tenant** — the proven pattern already live for rental applications
   (`RentalApplicationSigningController`, its own docblock: *"modelled on the existing `/sign/{token}`
   mechanism... same token shape, same 14-day expiry... the token itself IS the identity here"*), and
   for DR2's external parties (`DealSecureLinkMail`, already cited in §4 below as the supplier
   reply-link precedent). Cost: a new token column + expiry on whatever record the tenant lands on
   (either directly on a new fault-report entry point, or — if §1a resolves toward "a fault report IS
   a work order" — a public, token-gated `POST` onto `rental_work_orders`), a public route, and a
   minimal form (what's wrong, optional photo, optional space). Highest cost of the three, but the
   only one that gives the tenant their own, independently-timestamped record of having reported it —
   which matters directly to situation 3 in §1's table (the tenant needs to be ABLE to prove they
   reported it, not just trust the agent's word for it).
2. **An email address that files itself** — a dedicated inbound address per agency (or one CoreX-wide
   address with agency/property resolution from the sender or a reply-to token) that creates a
   `reported_by_type='tenant'` work order automatically from an inbound email. Cost: this is a NEW
   capability — nothing in the codebase today parses inbound mail into a record (the WhatsApp capture
   pipeline referenced in §4 does something structurally similar for WA messages, but there is no
   inbound-email equivalent to copy). Real build cost, not a reuse.
3. **The agent captures it on the tenant's behalf** — a call or message comes in, the agent opens the
   work-order form and fills it in as `reported_by_type='tenant'`, `reported_by_contact_id` set to the
   tenant's own contact row. Zero new mechanism — this is what §3.2's existing wording already
   describes and is what this spec builds by default absent a ruling otherwise. Honest cost: the
   "evidence the tenant reported it" is only ever the agent's own word plus whatever internal
   timestamp CoreX puts on it — weaker than option 1 for situation 3's dispute purpose, but real today.

**Not decided here.** Option 3 is what the rest of this spec assumes is available on day one, since it
requires nothing new; options 1 and 2 are named with their real cost so a future decision to build
either is informed, not a surprise.

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
  commission work on an owner's property without their approval. See §3.4a/§3.4b for what is and isn't
  settled about how that approval is captured and when it's required at all.
- **`in_progress`** — work has started but isn't finished. Optional stage — a quick fix may skip
  straight to `completed`.
- **`completed`** — **requires, per Johan's ruling ("photos of the work conducted"): at least one
  `rental_work_order_photos` row with `photo_type='completed'`, gated by
  `rental_work_order_settings.completion_requires_photo`** (default on). Also requires `paid_by` to be
  set to something other than the implicit "nothing recorded" state — an agency-configurable choice
  whether this is a hard block or a soft warning is left to build time, but the requirement itself (photo
  + payer, before completion) is not optional in this spec's intent.
- **`cancelled`** — logged in error, or the issue turned out not to need action. `cancelled_at`/
  `cancelled_by_user_id`/`cancel_reason` (required text) recorded. A cancelled work order is never
  deleted once anything has been logged against it (§3.1's soft-delete gate) — it stays visible,
  cancelled, exactly like a cancelled lease or a cancelled inspection under the equivalent rule in their
  own specs.

Every status transition writes a `rental_work_order_updates` row (`update_type='status_change'`,
`from_status`/`to_status` populated) — this, together with `reported_at`/`ordered_at`/`completed_at`
timestamps directly on the work order itself, is what lets the out-inspection screen show a full
timeline, not just a final state.

### 3.4a Owner approval — captured as evidence, not just obtained (open)

**Settled:** the gate exists (§3.4). **Open:** how an approval is actually captured and retained, so
that if an owner later disputes a bill, "the agent said they approved" is worth something more than
that. Same evidentiary thinking §3.4 already applies to completion (photo + payer, not a checkbox) —
an approval needs the same treatment: what was sent, what came back, when.

Three real options, each grounded in a mechanism already proven in this codebase, not invented fresh:

1. **A secure tokened link the owner clicks — Approve / Decline.** The same shape as
   `RentalInspectionSignature::capture()` (already built, this codebase, this session): a lightweight,
   evidentiary capture — signer identity, a timestamp, and (for a decline or an agent-side override) a
   required note — without the full DocuPerfect e-sign ceremony. Concretely: a
   `rental_work_order_approvals` row per request, `token`/`expires_at` matching the rental-application
   pattern (§3.2a), `decision` (`approved`/`declined`), `decided_at`, and the request/response mail
   content retained (or at minimum referenced) the way `DealSecureLinkMail`'s pattern already
   preserves what was sent. This is the strongest evidence of the three, and the most build cost.
2. **An internal record only — the agent marks it, with a note.** Mirrors
   `RentalInspectionSignature::SIGNER_AGENT_ON_BEHALF` — a sanctioned "the other party didn't formally
   engage through the system" path, already accepted elsewhere in this codebase as real evidence
   (Johan's own required phrase for that case: *"tenant refused to sign out inspection"*). Here it
   would be an agent recording "spoke to the owner on [date], they approved by phone/WhatsApp — note:
   [free text]." Cheapest to build (no new mail, no token), weakest evidence of the three — it's the
   agent's word, timestamped, nothing more.
3. **Email reply, read by a human, recorded manually.** The owner replies to the creation notice
   (§4) saying "go ahead" — an agent reads that reply and marks the work order approved, same weight
   as option 2 since CoreX does not parse inbound email into a structured decision (no such capability
   exists anywhere in this codebase, confirmed — see §3.2a's identical finding for tenant reports).
   Functionally option 2 with an email as the paper trail sitting outside CoreX rather than a note
   inside it.

**Not decided here.** Whichever is chosen governs what `rental_work_order_approvals` (or the
equivalent structure) actually needs to store — deliberately not added to §3.1's settled schema yet,
since committing to option 1's shape before Johan rules would foreclose options 2/3 for no reason.

### 3.4b The spend threshold below which no approval is needed (open)

Not requested by Johan as a specific number — raised by the conductor, argued for here, not decided.
Most mandates let an agent spend up to a limit without asking; without one, "email the owner about a
tap washer" is how a system gets ignored and the gate in §3.4 stops being respected in practice.

**Proposed shape, matching this spec's own `rental_work_order_settings` pattern (§3.1) and Johan's
standing rule that nothing is hardcoded:** a new nullable decimal,
`rental_work_order_settings.no_approval_spend_threshold`, agency-configurable, defaulting to a
genuinely low, conservative number — **proposed default R500** — so that out of the box every agency
requires approval for anything beyond a trivial expense, and can raise the number for their own
mandate/comfort level rather than CoreX guessing at what's appropriate for a given owner relationship.
When an agent creates or estimates a work order at or under the threshold,
`owner_approval_status` defaults to `not_required` and the `ordered` gate (§3.4) does not block; above
it, the gate applies and §3.4a's (also open) capture mechanism is needed before `ordered`.

**Not decided here**: whether R500 is the right default, whether the threshold should vary by trade
type rather than being a single agency-wide number, and whether an agent can override the gate with a
reason (mirroring how `owner_approval_status='declined'` might still need an escape hatch for an
emergency repair) are all real follow-on questions this proposal surfaces but does not answer.

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

External mail, one Mailable class per recipient (plain `Mail::to(...)->send(...)`, modeled on
`DealDistributionService`/`CocWorkOrderService`'s existing pattern):

- **Owner** (resolved via `Property::sellerOwnerContact()`) — on creation: a plain-language notice that
  a work order has been logged against their property, naming the issue and (if known) the trade type.
  On completion: confirmation it's done, who paid (if the owner is the payer, this is their invoice
  trail), and the completion photos attached or linked. **Skipped, not attempted, if the property
  genuinely has no owner attached** (the known, non-bug portal-import-stock state per §2) — logged as a
  no-op, not a failure.
- **Tenant** (resolved via the lease's `lease_tenants`, if `lease_id` is set — see §3.1a for the
  vacancy case where there is no tenant to notify at all) — on creation: acknowledgement that their
  reported problem has been logged (if `reported_by_type='tenant'`) or notice that a work order affecting
  their home has been raised (if reported by someone else). On completion: confirmation the repair is
  done. **This is the mechanism that makes a tenant's fault report accountable** — situation 3 in §1
  depends on the tenant being able to show they reported it, which this notification (and the
  `rental_work_order_updates` log) both corroborate independently.
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

---

## 7. The four-situation test, walked through against the actual schema

Proving §1's table against §3's fields, concretely, so this isn't asserted without being shown:

1. **Tenant broke it, never reported, still broken at move-out.** No `rental_work_orders` row exists
   linking to that item for the relevant lease period. The out-inspection screen, pulling "every work
   order ever raised against this item" (a join on `rental_inspection_item_id`), finds nothing in that
   window — the absence is itself the record. The current tenant is responsible.
2. **Tenant broke it, reported it, repaired, owner already paid.** A row exists: `reported_by_type
   ='tenant'`, `status='completed'`, `paid_by='owner'`, a `completed` photo attached, dated inside the
   relevant lease's date range (via `lease_id`). The out-inspection screen shows this against the item
   before showing any new damage — the tenant is not charged again for something already settled.
3. **Tenant reported it, nobody fixed it.** A row exists: `reported_by_type='tenant'`, `reported_at`
   set, but `status` never reaches `completed` (stuck at `reported`, possibly `ordered` with no
   `completed_at`). The `rental_work_order_updates` log shows exactly when it was reported and that
   nothing closed it. This is the owner's neglect on record, not the tenant's fault, regardless of what
   the out-inspection observes on that item now.
4. **A contractor repaired it badly.** A row exists: `status='completed'`, `agency_service_provider_id`
   set (a specific, named supplier), `completed_at` dated. A LATER `rental_inspection_observation` on
   the same item (via the shared `rental_inspection_item_id`) shows damage again, dated after
   `completed_at`. The timeline — completed repair, then a later bad-condition observation — points
   directly at the contractor's work, not the tenant occupying the property at the time of that later
   observation.

Every one of the four is answered by fields this spec already defines for other, independently-justified
reasons (who reported it, who paid, the append-only log, the stable item link) — no additional "fault"
field was needed to pass this test, which is itself evidence the schema is shaped correctly rather than
patched to fit afterward.

---

## 8. Agency settings — every threshold configurable, sensible defaults, never hardcoded

`rental_work_order_settings` (§3.1): `completion_requires_photo` (default **true**, matching Johan's
"photos of the work conducted" ruling — an agency may weaken this, the default does not), and
`overdue_reminder_days` (default **3**, a `[cc4 design call]` since Johan didn't specify a number —
flagged for Johan to confirm or adjust at build time, same treatment `lease_settings.expiry_notice_
window_days` gets for its own unconfirmed number in `leases.md` §5.2, though that one is pending legal
confirmation and this one is pending only an operational preference).

**Proposed, not yet settled (§3.4b):** `no_approval_spend_threshold`, agency-configurable, proposed
default **R500**. Not added to the table above because the whole mechanism is still open — this row
exists here only so the settings screen this spec eventually ships (§6) is designed with a slot for it
from the start, per the standing "every new setting reaches the wizard, designed in, not requested
later" rule, rather than bolting it on after Johan rules.

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

New keys in `config/corex-permissions.php`, following the `leases.*`/`rental_inspections.*` naming
convention already established by the two sibling specs:
- `rental_work_orders.view`
- `rental_work_orders.create` (covers logging a fault, assigning a supplier, adding notes/photos)
- `rental_work_orders.complete` — deliberately separate from `.create` **[cc4 design call, flagged for
  Johan]**, mirroring `rental_inspections.resolve_discrepancy`'s own reasoning: marking a work order
  complete is the point where evidence (photo + payer) becomes final, arguably warranting a tighter
  grant than "anyone who can log a fault." Collapsing it into `.create` at build time is a one-line
  change if this distinction is unwanted.
- `rental_work_orders.record_approval` — **new, 2026-09-22**, `[cc4 design call, flagged for Johan]`:
  whoever records that an owner approved (whichever mechanism §3.4a settles on) is making the same
  weight of call as resolving a discrepancy or completing a job — separate from `.create` for the same
  reason those two already are. If §3.4a resolves toward the tokened-link option, this permission
  governs who may manually override/record a decision on the owner's behalf (e.g. a phoned-in
  approval); if it resolves toward the internal-note-only option, this is the ONLY gate on recording
  an approval at all, and matters more, not less.
- `rental_work_orders.cancel`

---

## 11. Files to create (none yet written — spec only)

- `database/migrations/xxxx_create_rental_work_orders_table.php`
- `database/migrations/xxxx_create_rental_work_order_updates_table.php`
- `database/migrations/xxxx_create_rental_work_order_photos_table.php`
- `database/migrations/xxxx_create_rental_work_order_settings_table.php`
- `database/migrations/xxxx_register_rental_work_order_notification_events.php` (idempotent, §4)
- `app/Models/RentalWorkOrder.php`, `RentalWorkOrderUpdate.php`, `RentalWorkOrderPhoto.php`,
  `RentalWorkOrderSetting.php` — all `use BelongsToAgency`.
- `app/Services/Rentals/RentalWorkOrderService.php` — status transitions, the completion gate (§3.4),
  the approval gate (§3.4/§3.4a), the update-log writer. **Already named this way since the original
  draft, before the mobile-foundation ruling existed for this spec — the discipline that ruling asks
  for (§13: logic in a service, not a controller or a Blade) was already this spec's design from day
  one, not something added in this amendment.**
- `app/Http/Controllers/CoreX/RentalWorkOrderController.php` — thin: validates the request shape, calls
  `RentalWorkOrderService`. CRUD, status actions, photo upload.
- `app/Mail/Rentals/RentalWorkOrderOwnerMail.php`, `RentalWorkOrderTenantMail.php`,
  `RentalWorkOrderSupplierMail.php` — the three external notifications (§4).
- **Conditional on §3.4a's resolution, not written now**: if the tokened-link option is chosen,
  `database/migrations/xxxx_create_rental_work_order_approvals_table.php`,
  `app/Models/RentalWorkOrderApproval.php`, `app/Mail/Rentals/RentalWorkOrderOwnerApprovalMail.php`,
  and the public token-gated route/controller pair (mirroring
  `RentalApplicationSigningController`'s shape). If the internal-note option is chosen instead, no new
  files — `owner_approval_status` plus a `rental_work_order_updates` note-type entry already covers it.
- `resources/views/corex/rental-work-orders/index.blade.php` — the new list screen (§6).
- `resources/views/corex/properties/partials/rental-tab-work-orders.blade.php` — the property-level
  button + history (§6).
- `config/corex-permissions.php` — new permission keys (§10).
- Sidebar entry for the new list screen (same-day, non-negotiable #2).
- Setup Wizard entry for `no_approval_spend_threshold` (§3.4b) if and once that setting is built —
  same "designed in, not requested later" rule as every other agency setting (non-negotiable #10a).
- `tests/Feature/RentalWorkOrders/*` — the four-situation test in §7 as real fixtures at minimum, plus
  completion-gate enforcement, the approval gate refusing `ordered` while pending/declined, agency
  scoping, and notification dispatch (internal vs external split).
- Re-run `php artisan schema:dump`, commit refreshed `database/schema/mysql-schema.sql`
  (non-negotiable #12a).

---

## 12. Out of scope (this spec)

- Invoicing, trust-ledger reconciliation, or any real financial/accounting layer (§5.1) — settled OUT,
  not a future-question flag any more. REOS handles the money.
- A deposit ledger or deposit-deduction processing (§5.1a) — settled OUT except advertising deposits;
  named as a real, current gap, not solved here.
- The EXACT mechanism for capturing owner approval (§3.4a) and the spend threshold below which it
  isn't required (§3.4b) — the GATE is settled and built into §3's schema; how it's satisfied is not.
- Whether a tenant fault report is its own record type or simply a `rental_work_orders` row at
  `reported_by_type='tenant'` (§1a) — argued, not decided; this spec builds to the latter by default
  since it requires nothing new, but does not foreclose the former.
- A supplier-facing reply/secure-link mechanism to self-report completion (§4) — named as a future
  upgrade path (DR2's `DealSecureLinkMail` is the existing pattern to copy when wanted), not built here.
- Automated WhatsApp notification of anyone (§4) — does not exist in CoreX and is not built here; a
  manual `wa.me` link is the ceiling of what's possible without building a WhatsApp send capability
  from scratch, which is not this spec's job either.
- The tenant-link-outstanding reminder (§9) — raised for Johan's ruling, not decided or built.
- Any change to `leases.md` or `rental-inspections.md` themselves — both are read and depended on,
  neither is edited by this spec.
- Building the mobile app itself — Andre's job, per §13. This spec's job is only to make sure nothing
  built here makes that job harder later.

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

**Offline**: `rental_work_order_photos.client_idempotency_key` (§3.1) already exists for the same
reason `rental_inspection_photos`' own column does — a retried upload on a bad connection must never
create a duplicate. The same four arrival cases `rental-inspections.md` §14.4 works through (late,
out-of-order, a genuine app-side duplicate, and data that's gone stale by the time it arrives — here,
most concretely, a work order reported against a lease that's since ended) apply identically and are
not re-argued in full here; the server-side answer is the same: never discard real evidence because
something moved on, tell the app plainly what changed.
