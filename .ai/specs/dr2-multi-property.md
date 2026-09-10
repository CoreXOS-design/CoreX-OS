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

## 7. Split-pricing entry (built)

Both business calls below were put to Johan directly (via AskUserQuestion,
not decided silently) and answered — both his Recommended options:

> "An agent already captured a single-property deal with a price... and now
> adds a second property... what should happen?" → **"Keep it, add the new
> one on top."** The existing price is never redistributed; the new
> property gets its own price entered separately; the deal total grows to
> the sum.
>
> "How should an agent enter prices when a deal covers more than one
> property?" → **"Price each property, total adds up automatically."** No
> separate total field exists to disagree with the parts — a "doesn't
> balance" state is structurally impossible, not merely blocked.

**Direction of truth, precisely:**

- While a deal has **0 or 1** linked properties: `deals.property_value` /
  `total_commission` are exactly what they always were — the manually
  entered fields on the main capture form, completely unchanged UX.
  `Deal::syncPrimaryPropertyPivot()` mirrors them onto that one property's
  `allocated_price`/`allocated_commission` automatically, invisibly, on
  every deal save (both create and update).
- The moment a **second** property is linked, the direction reverses: each
  property's own allocation becomes the source of truth (entered via
  `addProperty()`, corrected via `updatePropertyPrice()`), and
  `App\Services\Deal\DealPropertyPricingService::recalculateTotals()` keeps
  `deals.property_value`/`total_commission` as their **sum** — recalculated
  after every add, remove, restore, or price edit. `saveQuietly()` is used
  deliberately so this derived write never re-triggers `Deal::booted()`'s
  own mirror-back hook (which would otherwise try to write the aggregate
  back onto one property and loop).
- The main capture form's Selling Price / Commission fields become
  `readonly`/`disabled` once a deal has 2+ properties, with an inline note
  pointing at the per-property list — the value shown (the correct sum)
  round-trips unchanged on an ordinary "Save", so there is no dual-write
  path and no way to accidentally clobber the derived total.

**Schema correction (this revision):** `deal_properties.allocated_price` was
originally created as `unsignedBigInteger` — a real bug, since
`deals.property_value` (the field it must sum to) is `decimal(12,2)` and an
integer column would silently round or reject a price with cents. Fixed via
a follow-up migration (`ALTER TABLE ... MODIFY allocated_price
DECIMAL(12,2)`, raw SQL — no `doctrine/dbal` on this box) before any real
data existed in the column (verified: all 16 rows were NULL at the time).

## 8. UI (built)

Full CRUD, on the deal edit screen (`resources/views/dr2/create.blade.php`,
edit mode only — these actions all route-model-bind to a real, already-saved
`Deal`):

- **Create** — `POST /deals-dr2/{deal}/properties` (`.properties.add`):
  validates `property_id` (+ `allocated_price`/`allocated_commission`,
  required once the deal already has a property), runs the same-owner gate,
  runs the Granted/Registered exclusivity check, attaches (or restores a
  soft-deleted link), fires the branch co-share, re-fires `DealCreated` to
  sync status, recalculates deal totals, audits `property_added`.
- **Read** — the "Properties on this deal (N)" list: address, Primary
  badge, price + commission per row. Real empty state ("No properties
  linked yet — search below to add the first one") when a deal genuinely
  has none. A client-side filter box and Address/Price/Date-added sort
  toggle appear once there are 2+ rows (mirrors the existing
  `dr2/pipeline-list.blade.php` sort-bar precedent on this same screen —
  client-side, no pagination, because the count per deal is always small;
  server-side search/pagination doesn't apply to a bounded per-parent list,
  per Non-negotiable #8's own floor being about *screens*, and this is an
  embedded sub-list on an already-scoped parent record, not an independent
  screen).
- **Update** — `PATCH /deals-dr2/{deal}/properties/{property}`
  (`.properties.updatePrice`): edits one linked property's own price;
  refuses with a plain message on a single-property deal ("edit its price
  on the main deal form above" — there is deliberately only ever one place
  to edit a given figure at a time); recalculates deal totals; audits
  `property_price_updated`.
- **Archive (soft delete)** — `DELETE /deals-dr2/{deal}/properties/{property}`
  (`.properties.remove`): refuses to remove the primary property (plain
  text explaining why, not a disabled button standing in for a hidden
  action — see [[feedback_actionable-affordance]]), otherwise soft-deletes,
  recalculates totals, audits `property_removed`.
- **Restore** — `POST /deals-dr2/{deal}/properties/{property}/restore`
  (`.properties.restore`, new this revision): a collapsible "Removed
  properties (N)" section — renders nothing at all when empty, mirroring
  `dr2/_removed-steps.blade.php`'s established archive/restore pattern on
  this exact screen — with a direct one-click Restore button per row
  (re-runs the owner gate too, in case ownership changed while it sat
  removed), recalculates totals, audits `property_restored`.
- **Same-owner refusal surfaces in plain language, at the point of use**:
  errors from the gate flash via `withErrors(['property_id' => ...])` and
  render inline in the multi-property section itself (`@error('property_id')`)
  in addition to the page's existing generic top-of-page error banner — an
  agent is never left with a control that silently did nothing.
- Branch-sharing screen: **not built** — this remains the pre-existing,
  separately-flagged gap (`Admin\DealBranchController` has live routes but
  no Blade view), unchanged from the prior revision of this spec, and still
  out of AT-398's scope.

## 9. Scoping

Every mutation above operates through `Deal`/`Property` models that already
carry `BelongsToAgency`/branch scoping; `deal_properties` inherits its
parent rows' scope (no independent global scope needed — a pivot row is only
ever reached through an already-scoped `Deal` or `Property`, and every
controller action here route-model-binds to the same `$deal`). No new
top-level list screen was introduced by this feature — properties are
added/removed/edited from the existing deal edit screen — so no new
independent search/sort/filter/pagination screen is owed beyond what the
embedded list itself now provides (§8).

## 10. Test coverage

Reporting convention (per Johan, 2026-09-10): the branch's own coverage is
the headline; a pre-existing file re-run as a regression check is named
separately, never folded into one combined figure.

**This branch's own tests: 51 across 5 files, 107 assertions, all passing**
(verified via a dedicated run of exactly these 5 files, excluding the
pre-existing regression file below).

| File | Count | Covers |
|---|---|---|
| `tests/Feature/Dr2/Wave2MultiPropertyStatusSyncTest.php` | 9 | Pivot mirroring, all six listeners multi-property, both mixed-status cases |
| `tests/Feature/Dr2/DealPropertyOwnerGateTest.php` | 9 | Exact-set gate, Johan's Steve/Dave example both directions, no-owner-refusal, plain-English message |
| `tests/Feature/Property/PropertyOwnershipGuardTest.php` | 18 | Lock/unlock by status, all four assert methods, self-exclusion behavior |
| `tests/Feature/Dr2/DealAddRemovePropertyControllerTest.php` | 11 | HTTP-level: accept, refuse+message, branch co-share, primary-removal block, audit, permission gate, price sum on add/remove/update/restore |
| `tests/Feature/Dr2/DealMultiPropertyBladeTest.php` | 4 | Blade rendering: empty state, multi-property sum + read-only main fields, archive/restore section, plain-language refusal on the page |

**Pre-existing regression check (not this branch's own coverage):**
`tests/Feature/Dr2/Wave2DealPropertyStatusSyncTest.php` — 15 tests, 39
assertions, all still passing — proves the six-listener rewrite introduced
zero regressions on ordinary single-property deals.

Combined run (both together, one process): 66 passed, 146 assertions, zero
cross-file interference.

**Functional proof against the live QA1 database** (real rows, created and
fully cleaned up afterward via raw SQL — verified zero residue both times):
pivot mirroring, gate refusal, ownership lock, self-exclusion (first pass);
and, this revision — the additive price-on-top behavior, sum-recalculation
on add/edit/remove/restore (including that an edited price survives a
remove-then-restore round trip), and a full HTTP-stack Blade render of the
real edit page for a genuine multi-property deal.

**Not exercised by any of the above — stated plainly, not glossed over:**
no interactive browser was available in this environment (no browser-
automation tool, and Vite assets aren't built in this scratch worktree), so
the client-side JS itself — the add-property search dropdown, the
Address/Price/Date-added sort buttons, the filter box, and the inline
edit-price box opening/closing — was traced by hand against the rendered
markup and IDs, not exercised at runtime. Confirming those actually fire in
a live browser is still owed once this lands somewhere with built assets.
