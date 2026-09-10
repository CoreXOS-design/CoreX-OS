# DR2 Multi-Property Deals (AT-398)

> Spec for linking more than one property to a single DR2 deal. Extends
> "DR2 Wave 2: Deal → Property → Portal status sync"
> ([[dr2-wave2-aggregate-status]]) rather than replacing it — the six Wave 2
> listeners now loop every linked property instead of assuming exactly one.
> Written as part of the build, per Johan's explicit instruction, not after.

## 0. Why (Johan, verbatim)

> "we need to be able to link 2 properties on 1 deal."

The trigger case: one seller, multiple properties, one transaction. DR2 was
built assuming exactly one property per deal (`deals.property_id`, nullable
FK). This spec extends that to many, while keeping every existing
single-property consumer (commission calc, reporting, exports — 15+ call
sites) working unchanged, because `property_id` stays as the deal's
**primary** property.

## 1. The exact-same-owners rule — legal, not cosmetic

Johan's ruling, verbatim:

> "multi property means exact same owners - the problem like steve owns 2
> properties - 1 sole other with dave. for the transfer to happen dave
> cannot sign for the 1 property so it then has to be 2 deals. but if steve
> owned both outright then when the agent selects the first property that
> we know the seller then the multi property allows to add but only where
> steve is the owner as well."

This is a **signing-authority** rule, not a data-quality preference: every
seller captured on a deal must be legally able to sign for every property on
that deal. Steve solely owning property A, and jointly owning property B
with Dave, means Dave — who is not on the deal — cannot sign for A. That is
two deals, not one, no matter how much the seller overlaps.

**The gate is exact-set equality, not overlap.** A shared owner is not
enough; the seller-side contact set on the candidate property must be
*identical* to the seller-side set already on the deal's linked properties.

- The **first property** added to a deal establishes the set — nothing to
  match against yet.
- Every **subsequent** property must match that set exactly, or the add is
  refused.
- **Removing** a property re-evaluates nothing retroactively — the
  remaining properties on the deal already satisfied the same-set rule when
  they were added, so removal can only ever make the deal's history simpler,
  never invalid.
- No hard cap — "more than 2" is supported; the rule scales to any N.

**Ownership bucket:** the seller side pools four `contact_property.role`
values already treated as interchangeable elsewhere in the codebase
(deeds-derived writes `owner`, agent-mandate capture writes
`seller`/`landlord`, e-sign/compliance gates check all four): `owner`,
`seller`, `landlord`, `lessor`. Buyer/tenant/lead roles are irrelevant to
this gate.

**Refusal message is plain English, names the person, never jargon** — e.g.
"Can't add 6 Message B Rd to this deal — Dave Tinker cannot sign for the
transfer of this property, because Dave Tinker also owns it and is not on
this deal. A deal can only cover properties that share the exact same
owners. This property needs its own, separate deal." No "overlap", no "set",
no technical terms — an estate agent reads this, not a developer.

Implementation: `App\Services\Deal\DealPropertyOwnerGate`
(`sellerSideContactIds`, `ownerSetsMatch`, `assertHasKnownOwner`,
`assertCanAddToDeal`). Thrown exception:
`App\Exceptions\Deal\PropertyOwnerMismatchException`, mapped in
`bootstrap/app.php` as a global render safety net.

## 2. No deal without an owner

Johan's ruling, verbatim: **"there cannot be a deal without a owner."**

A property with no resolvable seller-side contact cannot become the first
(or any) property on a deal — refused, not an empty state to design around.
This is a hard precondition on `DealPropertyOwnerGate::assertHasKnownOwner()`,
called before a property is attached anywhere. It applies **only when a
property is actually being linked** — a deal captured with no property at
all yet (a genuinely empty capture-in-progress) is unaffected; the rule
fires the moment a property enters the picture.

## 3. No owner change once a deal exists

Johan's ruling, verbatim: **"we do not allow owner change."**

Once a property is linked (via `deal_properties` — this covers pre-existing
single-property deals too, via the automatic pivot mirror in §5) to any deal
whose `accepted_status` is Pending (`P`) or Granted (`G`), its owner set is
**locked**. The deal was built on who owns the property *now*; every seller
on the deal must be able to sign for it, so the set cannot move underneath
an open deal. The lock lifts automatically when the deal is **Declined**
(dead, nothing left to protect) or **Registered** (the transfer already
happened — an ownership update afterward is the expected next step, not a
violation).

Implementation: `App\Services\Property\PropertyOwnershipGuard`
(`isLocked`, `assertCanLink`, `assertCanUnlink`, `assertCanChangeRole`,
`assertOwnershipMutable`). Thrown exception:
`App\Exceptions\Property\OwnershipLockedException`, also globally mapped.

**Every real mutation path is covered**, not just the screen in front of the
user — verified against the live QA1 schema and wired at the query/service
layer in each of:

| File | Site |
|---|---|
| `PropertyContactController` | 5 link/unlink/role-change sites |
| `ContactPropertyController` | 2 sites |
| `MobilePropertyController` | 2 sites (creation-time attach on `store()` deliberately unguarded — a property has no prior owner to conflict with at creation) |
| `ComposeSellerService` | `linkSellerToProperty`, `unlinkSeller`, `selectDeed`, `unlinkDeed` |
| `OwnerContactResolver` | `linkCurrentOwners` |
| `DeedsCaptureController` | owner-linking loop |
| `SellerOutreach\EntryPointController` | 2 sites |
| `Dr2\DealRegisterController::syncPartyLinks()` | self-excluded — see below |

**Self-exclusion:** `DealRegisterController::syncPartyLinks()` runs on every
DR2 save and re-links the deal's own captured seller onto its own property.
Without an exclusion this would self-block the moment a deal goes Pending.
Every `PropertyOwnershipGuard::assert*` method takes an optional
`?int $excludingDealId` — the deal's own id is passed only from its own
save path; every other call site omits it and is checked with no exception.

## 4. Pipeline status sync — ALL linked properties, not documents

Johan corrected an earlier misreading: the requirement is that **both/all
linked properties get their pipeline status (under-offer/sold/reverted)
updated on grant/decline/milestone**, exactly as DR2 Wave 2 already does for
one property — not a documents/e-sign concern.

The six Wave 2 listeners
([[dr2-wave2-aggregate-status]]) now loop `$deal->properties()` (every
non-trashed `deal_properties` row) instead of the single `$deal->property`:

- `FlagPropertyUnderOfferOnDealCreated`
- `EnsurePropertyUnderOfferOnGrant`
- `MarkPropertySoldOnDealMilestone`
- `RevertPropertyStatusOnDealDeclined`
- `AutoDeclineSiblingDealsOnGrant`
- `AutoDeclineNewDealOnCommittedProperty` (unchanged — already correct via
  the service change below, since it evaluates a single property id)

Each listener's **per-property** business logic is unchanged; only the
number of properties it acts on changed. Every property is evaluated
independently — a withdrawn/off-market property is skipped exactly as
before, now per-property rather than assumed-one.

**`DealPropertyStatusService`** — the exclusivity engine — is now keyed on a
primitive `int $propertyId` (`otherActiveDealsExistForProperty`,
`existingCommittedDealForProperty`), with a new private
`linkedPropertyIds(Deal $deal): array` that every Deal-instance convenience
method (`assertCanGrant`, `otherActiveDealsExist`, `existingCommittedDeal`)
loops across. **Granting a multi-property deal auto-declines a sibling deal
on ANY shared property**, deduped across the whole set
(`AutoDeclineSiblingDealsOnGrant` collects siblings via
`flatMap(...)->unique('id')` over every linked property).

### The mixed-status case (Johan named this explicitly)

*"one property granted while another is still pending"* — proven directly:
a deal linking properties A and B is granted. A separate deal Y, sharing
only property A, is a pending sibling — it gets auto-declined. Property B,
untouched by any other deal, has nothing to decline. **One grant produces
two different, correct outcomes**, because the exclusivity/status logic
runs per property, not per deal. Symmetric case proven for decline/revert:
property A stays `under_offer` (another active deal still on it), property B
reverts to on-market (nothing else holds it).

Tests: `tests/Feature/Dr2/Wave2MultiPropertyStatusSyncTest.php` (9 tests,
including both mixed-status cases by name), zero regressions in the
pre-existing `tests/Feature/Dr2/Wave2DealPropertyStatusSyncTest.php` (15/15).

## 5. Schema — `property_id` stays primary

`deals.property_id` is **unchanged** — it continues to mean "the primary
property" and every existing reporting/analytics/commission consumer reads
it exactly as before. New table `deal_properties` (soft-deletable pivot,
`App\Models\DealProperty extends Pivot`) is the multi-property source of
truth the six listeners and the exclusivity service read from.

- **No hard unique constraint** on `(deal_id, property_id)` — deliberately,
  per BUILD_STANDARD §5a (never combine a hard unique index with
  SoftDeletes). Uniqueness among *active* links is enforced in application
  code (find-or-restore-or-create in `addProperty()`), not the schema.
- **Automatic mirroring**: `Deal::booted()`'s `created`/`updated` hooks call
  `syncPrimaryPropertyPivot()` — every deal saved through the existing
  single-property picker gets its `property_id` mirrored into
  `deal_properties` as `is_primary = true` automatically. Changing
  `property_id` on update soft-removes the old primary row and creates/
  restores the new one. This is what makes the pivot-based listeners safe
  for every pre-existing single-property deal with zero code change on
  their part — mirrors the pre-existing `deal_branches`/`branch_id` sync
  pattern already in the same model.
- Migration backfilled all pre-existing deals on deploy — verified 16/16 on
  QA1 (`deal_properties` row count matched `deals.property_id IS NOT NULL`
  count exactly after migrating).

## 6. Branch sharing

Johan's ruling: share the deal across both branches automatically when a
linked property belongs to a different branch than the deal. Reuses the
existing co-branch pivot — `Deal::attachCoBranch()` /
`deal_branches` (role `co_branch`) — no new mechanism invented. Wired into
`DealRegisterController::addProperty()`: if the added property's
`branch_id` differs from the deal's, `attachCoBranch()` fires. No dedicated
new screen was built for this in AT-398 — the pre-existing
`Admin\DealBranchController` has live routes but no Blade view; that gap
predates this feature and is a separate, flagged item, not part of this
spec's scope.

## 7. Two-way balancing price entry

`deal_properties.allocated_price` (unsignedBigInteger, nullable) and
`allocated_commission` (decimal 12,2, nullable) hold the per-property split.
*(Validation/UI for balancing entry — either per-property→total or
total→split — is tracked as a follow-up item on this same ticket; schema is
in place, business logic is not yet built as of this revision.)*

## 8. UI

Multi-property capture/edit screen and audit trail:

- `POST /deals-dr2/{deal}/properties` (`deals-dr2.properties.add`,
  `permission:create_deals`, controller-checks `deals.create` OR
  `deals.edit`) — validates `property_id`, runs the owner gate, runs the
  Granted/Registered exclusivity check, attaches (or restores a
  soft-deleted link), sets `is_primary` only if it's the first property,
  fires the branch co-share, re-fires `DealCreated` to sync the new
  property into the deal's current status (idempotent — reuses the tested
  Wave 2 listeners rather than duplicating their logic), and audits via
  `DealLog` event `property_added`.
- `DELETE /deals-dr2/{deal}/properties/{property}`
  (`deals-dr2.properties.remove`) — refuses to remove the primary property
  (a plain-English redirect error, not a hidden disabled button), otherwise
  soft-deletes the pivot row and audits `property_removed`.
- Blade capture/edit screen wiring these routes into the existing
  `resources/views/dr2/create.blade.php` picker is a follow-up item on this
  same ticket (schema, service layer, controller actions, and full test
  coverage are complete; the visual add/remove list is not yet built as of
  this revision).

## 9. Scoping

Every mutation above operates through `Deal`/`Property` models that already
carry `BelongsToAgency`/branch scoping; `deal_properties` inherits its
parent rows' scope (no independent global scope needed — a pivot row is only
ever reached through an already-scoped `Deal` or `Property`). No new list
screen was introduced by this feature (properties are added/removed from
the existing deal detail screen), so no new search/sort/filter screen is
owed under Non-negotiable #8/CLAUDE.md's CRUD-floor rule beyond what already
exists on the deal register.

## 10. Test coverage

| File | Count | Covers |
|---|---|---|
| `tests/Feature/Dr2/Wave2DealPropertyStatusSyncTest.php` | 15 (pre-existing) | Zero regressions on single-property behavior |
| `tests/Feature/Dr2/Wave2MultiPropertyStatusSyncTest.php` | 9 | Pivot mirroring, all six listeners multi-property, both mixed-status cases |
| `tests/Feature/Dr2/DealPropertyOwnerGateTest.php` | 9 | Exact-set gate, Johan's Steve/Dave example both directions, no-owner-refusal, plain-English message |
| `tests/Feature/Property/PropertyOwnershipGuardTest.php` | 18 | Lock/unlock by status, all four assert methods, self-exclusion behavior |
| `tests/Feature/Dr2/DealAddRemovePropertyControllerTest.php` | 7 | HTTP-level: accept, refuse+message, branch co-share, primary-removal block, audit, permission gate |

**58 tests, 112 assertions, all passing** — combined run, zero cross-file
interference. Functional proof also run via Tinker against the live QA1
database (post-migration): pivot mirroring, gate refusal, ownership lock,
and self-exclusion all confirmed against real rows, then fully cleaned up.
