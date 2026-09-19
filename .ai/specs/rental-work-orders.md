# Rental Work Orders

**Status:** Spec — not yet built. NO CODE has been written against this spec.
**Date:** 2026-09-14
**Author:** cc4
**Pillar:** Property (`Property`) — every work order anchors to a property; Contact (owner, tenant,
supplier's own contact person) is who is notified and who reported it; touches Lease (`.ai/specs/
leases.md`, branch `cc5-leases-spec`) and Rental Inspections (`.ai/specs/rental-inspections.md`, base
branch `cc5-rental-inspections-spec` @ `9e9be1e7f`, plus the follow-up lease-id amendment on branch
`cc5-rental-inspections-lease-amendment` @ `3b1e57bb7`) as its two structural dependencies.
**Sequencing:** Johan's ruling — core matches/pipeline → inspections → **work orders (this spec)**.
Both dependencies are read, coordinated on directly, and cited below; neither is re-designed here.

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
  without ever passing through `ordered` if no supplier was engaged.
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
  existing COC-work-order supplier picker already uses. **Not built here, flagged as a future
  enhancement only**: a secure-link reply mechanism (DR2 already has one — `DealSecureLinkMail`,
  `DealDistributionService.php:236` — that lets an external party act without a CoreX login) that would
  let a supplier mark their own job complete without an agent doing it manually. Johan asked only for
  "can even email the supplier," not for a supplier portal — this spec ships the email; the reply-link
  upgrade is named so it isn't rediscovered as if new.

---

## 5. What must be settled (per the task's own checklist) — remaining items

### 5.1 Cost — a field, flagged the moment it would grow

`cost_amount` is a plain nullable decimal, matching `paid_by`'s evidentiary purpose ("the owner paid X
to fix this"). This spec does **not** build: invoicing, a trust-ledger reconciliation against the
deposit, or multi-line-item costing. Same flag, same reasoning, as `leases.md` §3.3's deposit-tracking
flag — if this needs to become a real accounting feature, that is a materially bigger, separate piece of
work, not an extension of this column.

### 5.2 Approval / spending threshold

Not requested by Johan, not built. **Raised, not decided**: does an owner need to approve a work order
above some cost before a supplier is instructed? This spec deliberately does not invent that gate —
flagging it as a plausible future question rather than silently building or silently omitting a decision
point that wasn't asked for.

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
  the update-log writer.
- `app/Http/Controllers/CoreX/RentalWorkOrderController.php` — CRUD, status actions, photo upload.
- `app/Mail/Rentals/RentalWorkOrderOwnerMail.php`, `RentalWorkOrderTenantMail.php`,
  `RentalWorkOrderSupplierMail.php` — the three external notifications (§4).
- `resources/views/corex/rental-work-orders/index.blade.php` — the new list screen (§6).
- `resources/views/corex/properties/partials/rental-tab-work-orders.blade.php` — the property-level
  button + history (§6).
- `config/corex-permissions.php` — new permission keys (§10).
- Sidebar entry for the new list screen (same-day, non-negotiable #2).
- `tests/Feature/RentalWorkOrders/*` — the four-situation test in §7 as real fixtures at minimum, plus
  completion-gate enforcement, agency scoping, and notification dispatch (internal vs external split).
- Re-run `php artisan schema:dump`, commit refreshed `database/schema/mysql-schema.sql`
  (non-negotiable #12a).

---

## 12. Out of scope (this spec)

- Cost/invoicing/trust-ledger reconciliation (§5.1) — flagged as a question, not built.
- An owner-approval/spending-threshold gate before instructing a supplier (§5.2) — raised, not decided.
- A supplier-facing reply/secure-link mechanism to self-report completion (§4) — named as a future
  upgrade path (DR2's `DealSecureLinkMail` is the existing pattern to copy when wanted), not built here.
- The tenant-link-outstanding reminder (§9) — raised for Johan's ruling, not decided or built.
- Any change to `leases.md` or `rental-inspections.md` themselves — both are read and depended on,
  neither is edited by this spec.
- Mobile — per the same convention both sibling specs use, this defines the server-side data model and
  web surface only.
