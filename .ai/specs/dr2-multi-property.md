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
- **Fixture/seeder trap (found 2026-09-13):** because the mirror above fires
  automatically off `Deal::created`, a fixture or seeder that sets
  `property_id` on a new `Deal` and then ALSO calls
  `DealProperty::create(['deal_id' => ..., 'property_id' => ..., 'is_primary'
  => true])` explicitly ends up with two `is_primary = true` rows for the
  same property — the automatic one (price/commission mirrored from
  `property_value`/`total_commission`) and a redundant manual one (nulls).
  Nothing validates against it (no hard unique constraint, by design — see
  above), so it fails silently rather than erroring. If you're constructing
  a `Deal` with `property_id` set directly, `syncPrimaryPropertyPivot()` has
  already created its `deal_properties` row — do not create a second one.

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

## 8a. Create-time capture (built, 2026-09-14/16 — corrects §8's original scope)

Johan found this himself, testing DR2 live: loaded a contact with two
properties, linked the first to a new deal, and could not find a way to add
the second — because there wasn't one. §8 below shipped the add/remove/
price mechanism edit-mode only, on the stated reasoning that those actions
"route-model-bind to a real, already-saved `Deal`." That reasoning is
correct as an implementation detail but was never actually put to Johan —
every other ruling in this spec (§1, §2, §3, §6, the two questions in §7)
carries his own verbatim words; §8's edit-only scope carries none. The one
time he was consulted on pricing (§7), the question was already framed
around an already-saved deal, so the timing question underneath it was
never asked.

His correction, verbatim, and the reason it isn't a preference: **"the
build is wrong if I have to save first... 2 properties sold together makes
up 1 selling price, so what do we expect a user to do? save the deal with
figures not balancing, or save with wrong figures, then reopen, change the
deal to get it back to correct figures? That was never the spec."** Traced
before accepting this as a genuine gap (not assumed): a declined-to-edit
detour was never harmless here, because a deal register carrying a
knowingly-wrong intermediate figure — however briefly, however quickly
corrected — is the exact defect this whole feature exists to prevent.
Confirmed by checking sign-off history, not assumed: every documented
verification of this feature (the "Functional proof against the live QA1
database" paragraph, cc1's own browser pass in §10a) exercised the EDIT
path exclusively — real test rows were created via raw SQL and then
edited, never through a genuine create-and-save flow. §10's own closing
line admits the save-button/create flow was "traced by hand... not
exercised end-to-end by a human/browser," and even the pass that WAS done
never attempted building a two-property deal from nothing. Nobody found
this by testing, because the control's *absence* on create isn't a bug you
click into.

**Johan's pricing model for create-time, verbatim:** *"the spec given was
bm or admin - only ones who creates deals - can capture total and on
properties. we need to allow the price per property to be captured which
displays a total, but bm or admin has to verify that the price balances."*
Checked, not assumed, before building: permissions ARE correctly restricted
to BM/admin already (`create_deals`, absent from the `agent` role's default
include list in `config/corex-permissions.php` — read end to end, not
sampled) — this was a real question worth checking, not a rubber stamp.

Mechanism — reuses everything that already existed for edit mode except one
genuinely new piece (client-side staging), never a second implementation of
anything already built:

- **Held entirely client-side** (`dr2/create.blade.php`'s create-mode JS,
  `dr2cp_*` ids — kept fully separate from edit mode's own `dr2mp_*` ids and
  markup, zero shared state, zero risk to the edit path) until the ONE
  "Save Deal" submit. Nothing is persisted, nothing is half-written, until
  a single `store()` call.
- **The main Selling Price/Commission fields change meaning, not mechanism**,
  once a second property is staged: for 0-1 properties they mean exactly
  what they always have (the one property's own price, unchanged UX). The
  moment a second property is added, they become the BM's own
  independently-entered TOTAL (the offer figure) — editable, never
  auto-derived on create, which is the opposite of edit mode's own
  behaviour (`DealPropertyPricingService::recalculateTotals()` force-
  overwrites the total to the sum on every edit-mode add — deliberately
  UNCHANGED for edit; this new independent-total behaviour is create-time
  only). The primary property's own price, whatever was in those fields the
  instant the second property was added, is frozen into its own row in the
  list — "the primary gets its own row like the others," per Johan's
  explicit instruction — editable from there exactly like every other row.
- **Live reconciliation, not a silent recompute**: the sum of every
  property's own row (primary included) is shown against the entered total,
  live, as either figure changes, with the exact difference stated when
  they disagree — "show the difference... do not make them work it out."
- **Blocking, not warning, on mismatch — both client-side (immediate
  feedback, `e.preventDefault()` on submit) and server-side
  (`DealRegisterController::validateAdditionalPropertiesPayload()`, a
  `ValidationException` thrown before ANYTHING is persisted — not even the
  deal itself).** This is enforcement of Johan's own stated principle this
  same week on this same feature — a deal register must never carry figures
  that don't balance — not a new rule invented for this build.
- **The live same-owner check reuses the existing
  `deals-dr2.search.property-contacts` endpoint verbatim** — already
  returns exactly the seller-side contact ids a client-side exact-set
  comparison needs, already permission-gated correctly (`deals.create`/
  `deals.edit`), already used by the primary property picker for the same
  purpose. No new endpoint. This check is a convenience only — a failed or
  skipped client-side check never blocks a legitimate add, because the real
  gate always re-runs server-side regardless (see below).
- **Server-side persistence never trusts anything the browser sent beyond
  raw numbers**: `DealRegisterController::applyCreateTimeMultiProperty()`
  runs, inside the SAME transaction `store()` already wraps everything in,
  the EXACT `DealPropertyOwnerGate::assertCanAddToDeal()` and
  `DealPropertyStatusService` checks `addProperty()` already runs for the
  edit-mode case — never a second, looser gate for this path. A thrown
  `PropertyOwnerMismatchException` here rolls back the ENTIRE transaction,
  deal-number allocation and primary-property creation included — proven,
  not assumed, by a test asserting `Deal::count()` is unchanged after a
  refused owner-mismatch submission. The deal is never created half-right.
- **The primary's own allocation is corrected after the fact, deliberately**:
  `Deal::booted()`'s existing `syncPrimaryPropertyPivot()` mirror still
  fires automatically the moment the Deal is created (unchanged, and
  correctly so — every single-property deal still needs it), guessing the
  primary's price from `property_value`/`total_commission` — which, for a
  genuine multi-property submission, now hold the TOTAL, not the primary's
  own price. `applyCreateTimeMultiProperty()` corrects that one row
  immediately afterward with the real, individually-submitted figure,
  before `recalculateTotals()` runs.

Files: `resources/views/dr2/create.blade.php` (create-mode JS block +
`@elseif` branch on the existing multi-property section's condition — the
edit-mode branch is untouched, byte-for-byte, verified via the full
pre-existing regression suite below), `DealRegisterController::store()`
(the two new private methods above, wired into the existing transaction).

**Tests**: `tests/Feature/Dr2/CreateTimeMultiPropertyTest.php` — 6 new
tests: the create screen actually renders the section (proves the Blade
compiles, not just that it should), the permission check holds (an
ordinary agent still 403s), a balanced two-property submission creates
both properties correctly in one transaction, a mismatched total refuses
the save and writes NOTHING (not even the deal), a second property failing
the owner gate refuses the save and writes NOTHING (proving the whole-
transaction rollback, not just a partial one), and the ordinary
single-property path (no `properties[]` at all) is completely unaffected.
**Full pre-existing regression suite re-run, zero regressions**: all 37
tests across `DealAddRemovePropertyControllerTest`,
`DealPropertyOwnerGateTest`, `DealMultiPropertyBladeTest`,
`Wave2MultiPropertyStatusSyncTest`, and `BackfillAllocatedPriceTest` still
pass unchanged.

**A pre-existing, unrelated defect found while regression-checking, not
introduced here and not fixed here**: `tests/Feature/Dr2/Dr2CaptureTest.php`
has 7 failing tests (`PropertyOwnerMismatchException` — "We can't confirm
who owns X yet") that fail IDENTICALLY with this build's changes fully
reverted (checked via `git stash` before reporting, not assumed) — genuine
pre-existing test debt, unrelated to this feature, reported here per
scope-lock rather than fixed.

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

## 8b. "Add another property" is a plain dropdown of gate-eligible properties, not a search (2026-09-16)

Johan, testing DR2 live, verbatim: **"add another property should only
display the other properties on this seller. why offer a search, it can
be a plain dropdown."** The reason is stronger than convenience: a search
invites the user to pick something the owner gate will then reject,
teaching them the system is broken when it is in fact working correctly.

**What existed before this**, checked before anything was changed:
`DealRegisterController::searchProperties()` — a free-text address search
over the agency's ENTIRE visible stock (`Property::visibleTo($user)
->searchAddress($search)`, on-market only by default), used identically,
via two SEPARATE implementations, for both the create screen's own
"Add another property" box and the edit screen's — neither one already
did this correctly; there was no existing "right way" to reuse.

**The one thing Johan asked to be thought through, not just implemented**:
"properties on this seller" and "properties this deal will accept" are
NOT the same set. `DealPropertyOwnerGate` compares exact OWNER SETS, not
a single seller — a seller who owns one property solely and another
jointly with a spouse has two DIFFERENT owner sets, and the second would
still fail the gate even though it's genuinely "another property on this
seller." **Sized on QA1's own real data before any code was written, per
his explicit instruction not to decide this silently**: 81 sellers have
2+ properties; of those, **31 individual properties fall in exactly this
gap** — sharing a seller with another property, but not that property's
exact owner set (e.g. a seller solely owning one Effingham Parade property
and jointly owning an adjacent one via a different holding-company
combination). Reported to Johan rather than decided.

**Johan's ruling, 2026-09-16: "31 IS MEANINGFUL."** These 31 must NOT be
silently absent. His reasoning, relayed by the conductor: "An agent who
knows their seller owns three houses, opens the dropdown and sees two,
will conclude the system lost one — and then either stops trusting the
dropdown or goes hunting. Silence is the worst of the three options." So
the shipped behaviour is not "excluded by construction" — see the revision
immediately below.

**What's built — ONE shared endpoint, ONE shared dropdown, for create AND
edit (Johan: "same behaviour on create and on edit. One implementation,
not two.")**:

- `DealRegisterController::eligibleProperties()` (`GET
  /deals-dr2/search/eligible-properties`) takes a `reference_property_id`
  (the primary — passed explicitly rather than resolved from a `Deal`,
  since create mode has no `Deal` yet) plus an `exclude[]` list (already-
  staged/already-linked properties) and an optional `accepted_status`/
  `deal_id`. It reuses `DealPropertyOwnerGate::sellerSideContactIds()`/
  `ownerSetsMatch()` **verbatim** — never a second, looser owner-set
  comparison written for this endpoint — after a cheap pre-filter (any
  candidate must share at least one seller-side contact with the reference,
  a necessary condition an exact-set match trivially satisfies) narrows a
  whole-agency scan down to a small pool first. The existing on-market
  default and `visibleTo()` agency/branch scoping are unchanged from
  `searchProperties()`'s own convention. The G/R exclusivity check
  `addProperty()` already runs (`DealPropertyStatusService::
  committedDealOnProperty()`) is reused the same way, only when the
  deal itself is already Granted/Registered — never a second status rule.
  It remains a hard exclusion (never shown, not even disabled): Johan's
  ruling below is scoped to the owner-set gap specifically, which is
  genuinely confusing to an agent; G/R exclusivity, "already on this
  deal", and wrong-status exclusions are not extended the same treatment
  (see the reasoning immediately below).
- **The owner-set gap (the 31) is returned, not excluded — marked
  ineligible, with a plain-language reason, sorted after the real
  choices.** Per Johan's ruling above, `eligibleProperties()` splits
  candidates that pass the pre-filter/on-market/visibility/G-R checks
  into `eligible`/`ineligible` by `ownerSetsMatch()`, and returns eligible
  rows first, then ineligible rows, each carrying `'eligible' => bool`
  and (for ineligible rows) `'reason' => string` — deliberately never the
  gate's own vocabulary ("owner set" means nothing to a working agent):
  *"Can't be added — the owners on this property aren't the same as the
  owners on this deal."* The dropdown renders eligible rows as ordinary
  selectable `<option>`s, then — only if any ineligible rows exist — a
  `<optgroup label="Can't be added — different owners">` of `disabled`
  `<option>`s carrying the reason as a `title` tooltip. A disabled
  `<option>` is a UI hint only: `store()`'s own `DealPropertyOwnerGate::
  assertCanAddToDeal()` call is completely unchanged and still refuses
  any of these ids if posted directly, proven by a dedicated test (see
  Tests below) — the dropdown never became the enforcement point.
  **Reasoning on the other exclusion kinds, given to the conductor for
  confirmation**: only the owner-set gap gets this treatment. "Already on
  this deal" is self-evident to the agent (they just added it) and stays
  hidden; wrong-status and G/R-exclusivity exclusions stay hidden too —
  they are not something an agent looking at THIS seller would expect to
  see offered at all, unlike the owner-set case where the property
  visibly belongs to the same seller and its absence would look like data
  loss.
- **A plain `<select>`, on both screens** (`dr2mp_picker` in edit mode,
  `dr2cp_picker` in create mode) — no search input, no autocomplete,
  populated once via a single shared JS function, `loadEligibleDropdown()`
  (`resources/views/dr2/create.blade.php`). Refreshed whenever the primary
  property changes, and whenever a property is added to or removed from
  the deal (create mode's own client-side staging list, or edit mode's
  real linked-properties list on the next page load).
- **Says so in plain words when there's nothing eligible**, never an
  empty-looking dropdown (Johan: "Johan should never see a control that
  looks broken when it is simply empty") — `#dr2mp_picker_empty`/
  `#dr2cp_picker_empty`, shown instead of the `<select>` when the eligible
  list comes back empty, or when no primary property has been picked yet.
- **What each option shows**: address plus the property's own reference
  number when it has one (`toSearchResult()`'s existing `label`/`ref`
  shape, the same fields `searchProperties()` already surfaces) — enough
  to tell two of the same seller's properties apart without a search.
- The old free-text search JS (`dr2mp_search`/`dr2cp_search` and their
  results-list rendering) is removed entirely on this control — the
  primary property picker elsewhere on the same screen is UNCHANGED and
  still a genuine search, since there is no "eligible set" concept for
  picking the very first property on a deal.

**Tests**: `tests/Feature/Dr2/PropertyEligibilityDropdownTest.php` — 9
tests: an identical-owner-set property is returned; the exact gap Johan
named (same seller, different owner set — one solely owned, one jointly)
is returned but marked `eligible: false` with a plain-language reason and
sorted after the real, pickable choices (updated 2026-09-16 per his
ruling — previously asserted absence, which is now the wrong behaviour);
the reference property itself is never offered; already-excluded/already-
linked properties are never offered again; a reference with no resolvable
owner returns nothing; a seller with no other properties returns an empty
list; the response carries enough to distinguish two properties; the G/R
exclusivity reuse is proven (still a hard exclusion, not shown even
disabled); and the permission gate holds (an ordinary agent 403s).
`CreateTimeMultiPropertyTest.php` updated to assert the create screen
renders a `<select>` (`id="dr2cp_picker"`), never the old search input,
plus a new test,
`test_a_property_shown_disabled_in_the_dropdown_for_a_mismatched_owner_set_is_still_refused_when_posted_directly`,
proving the server-side gate — not the disabled markup — is what actually
refuses one of the 31 when POSTed directly to `store()`. Full DR2
regression suite re-run — zero regressions.

**Verified against real QA1 data, not synthetic fixtures only** — a real
clean multi-property seller (contact #10298, four properties, one
identical owner set) correctly returns zero eligible properties by
default (all four are off-market) and correctly returns both siblings
once `all=1` is set; a real seller sitting exactly in the reported gap
(contact #10157, "Unit 4, Forest Walk") correctly excludes the property
she jointly owns with another contact and correctly includes the one she
owns solely.

## 8c. Focus-loss bug class: a live-recalculating field must never rebuild its own DOM subtree (2026-09-14)

Johan, testing the create-time multi-property build live, verbatim: **"trying
the 2 properties on 1 deal - added 2nd property happy with that. now trying
to update the price on property 1 - as soon as you enter any digit of a
number it loses focus, so you have to click in the field again, and then
type another digit. fix it - commission does the same, same on 2nd property
price and commission."** All four fields — both properties' price and
commission — on the CREATE screen only.

**Root cause, confirmed by reading before anything was changed** (not a
formatting/currency-mask issue, and not a browser quirk): every property
row's price/commission `<input>` (rendered by `dr2cpRenderRow()`) had an
`'input'` listener that called `dr2cpRecompute()` on every keystroke. That
function started with `dr2cpList.innerHTML = ''` and then rebuilt EVERY row
from scratch as fresh HTML — including the very `<input>` the user was
typing into. The new node carried the correct, updated value (the number
itself was never wrong), but it was a brand-new DOM element; the browser's
focus was on the OLD, now-deleted node, so focus dropped to nothing and the
next keystroke needed a click first. `dr2cpRecompute()` had been doing two
unrelated jobs — updating the balance summary/banner/hidden form inputs
(needs to run every keystroke) and rebuilding the visible row list (needs to
run only when a property is actually added or removed) — and a per-keystroke
listener called the combined function directly.

**The fix**: split into `dr2cpRecomputeSummary()` (never touches `dr2cpList`,
called on every price/commission keystroke and on every edit of the TOTAL
fields) and `dr2cpRenderRows()` (rebuilds the visible rows, called ONLY on
add/remove/primary-swap — never from a value-change listener). Verified with
a real headless-browser session against QA1's own data (not a unit test,
which cannot catch a focus bug): typed a full number into all four fields in
one uninterrupted keystroke sequence with no intervening click and confirmed
the whole number landed and focus never dropped; typed a number, moved the
caret to the middle via keyboard navigation, typed one more digit, and
confirmed it inserted in the middle rather than the value resetting or the
digit landing at the end (the tell for a node-replacement regression); and
confirmed the balance-mismatch banner still updates live throughout — the
reconciliation feature itself was never removed, only decoupled from the row
DOM.

**Edit screen checked, not affected**: `dr2mp_edit_price`/`dr2mp_add_price`
carry zero `'input'` listeners at all — they are plain fields set once when
"Edit price" is clicked, submitted via a real `<form>` POST. There is no live
total-balance reconciliation on the edit screen (properties are added one at
a time via a real POST), so this bug class cannot occur there; confirmed by
reading, and a live-browser spot check was attempted but blocked by
unrelated pre-existing data corruption on QA1 (deal #178, the only
multi-property deal that exists there, has BOTH its linked properties
flagged `is_primary=1` — the "Edit price" button only renders for a
non-primary row, so neither row's editor is reachable at all; reported here,
not fixed, out of scope for this fix).

**The bug CLASS, for the next person who adds a live-calculating field**: if
a field's value change needs to update something displayed elsewhere on the
page, the function it calls must update VALUES/TEXT CONTENT, never rebuild
the DOM subtree the field itself lives inside — not `innerHTML = ''` +
rebuild, not replacing a wrapping element, not anything that would destroy
and recreate the input node currently holding focus. If the visible list
genuinely needs to be rebuilt (a row added or removed), that rebuild must be
triggered only by the add/remove action itself, never chained onto a
per-keystroke value-change listener.

## 8d. Financials rebuild — every reconciling figure lives together, labelled by what it feeds (2026-09-19)

> **PARTIALLY SUPERSEDED by §8e, same day.** The LAYOUT and LABELLING rules
> in this section still stand and are unchanged — property selection stays
> at the top, money lives together in Financials, labels match the total
> they feed and follow the basis live. What's superseded is the
> RECONCILIATION MODEL described below: an independently-typed master,
> checked against the sum, with a live balance banner and a
> submit-blocking check. Johan ruled that model wrong on the same day he
> approved it — see §8e for why, and do not reinstate `dr2cpBalanced()`,
> the balance banner, or the sum-vs-total server validation this section
> describes. They were removed deliberately, not left out by omission.

Johan, testing the create-time build live: entered two properties at
100,000 each and a 220,000 selling price — genuinely R20,000 out, correctly
flagged. But the SAME screen also reported "commission off by R17,800.00"
even though his own commission figures (10,000 + 10,000) exactly matched
the 20,000 deal commission. He could not reconcile 17,800 from anything
visible on screen, because it wasn't derived from anything visible —
that was the actual defect.

**Root cause, reproduced exactly in a real browser session against real
QA1 data (properties #15936/#15937), not theorised**: `dr2_total_commission`
is a HIDDEN field. Its value is only ever set programmatically, inside
`recompute()`, whenever the visible Commission %/Commission (Incl VAT)
fields are edited. Setting `.value` in JavaScript never fires that
element's own `'input'` event — so the balance banner's listener on it
could structurally never fire. The banner only ever refreshed when the
Selling Price field or a property row was edited directly; editing the
Financials commission fields updated the real, correct total internally
but left the banner comparing the live sum against whatever stale figure
happened to be sitting in that hidden field the last time something else
triggered a refresh. Reproduced sequence that lands on the exact reported
figure: set price total (refreshes banner, commission side still 0) → type
Commission % = 1 as an early, since-abandoned guess (hidden field silently
becomes 2,200.00) → touch the price field once more (banner refreshes,
now comparing the correct 20,000 sum against the stale 2,200 — "off by
R17,800", exact match) → finish typing the real commission, 20,000
(hidden field correctly becomes 20,000.00, but nothing re-triggers the
banner) → banner stays frozen at "off by R17,800" forever.

**Fix**: `recompute()` now calls `window.dr2cpRecomputeSummary?.()`
directly at the end of every run, for every reason it runs (`source` =
`'pct'`, `'amount'`, or `'mode'`) — the hidden field's own dead listener is
removed entirely rather than relied on. Window-scoped because
`dr2cpRecomputeSummary` is declared inside a block (`if (dr2cpRoot) {...}`)
and function declarations inside a block are not hoisted to the enclosing
scope, and because the function legitimately does not exist at all on the
edit screen, where there is no live balance concept.

**Price and commission are independent, per Johan's own ruling** — "we
don't work with the R240000 at all, we work with the R24000, that's the
agency money": two separate captured figures, neither derived from the
other. The old banner and the old server-side validation
(`validateAdditionalPropertiesPayload()`) both combined price and
commission into ONE verdict/message — meaning a deal that was wrong on
price alone was reported (and would have been rejected) with a message
that also mentioned commission, even when commission was exactly correct.
Both are now two completely separate checks, each with its own sum/total/
diff and its own error key (`property_value` vs `total_commission`) —
never combined into one sentence, client or server side. The commission
check gets the heavier weight in verification: Johan's own words, "the
commission is the number that does the work... a wrong price on a property
is untidy, a wrong commission split pays a real person the wrong amount."
`tests/Feature/Dr2/CreateTimeMultiPropertyCommissionCheckTest.php` proves
the server-side commission reconciliation across equal splits, unequal
splits, one property carrying the whole commission, zero on one property,
a missing/empty field (rejected outright, never silently coerced to zero),
decimal splits to the cent, a one-cent mismatch still caught, and each
combination of price-wrong/commission-wrong/both-wrong reporting
independently. Johan's exact real numbers are a named regression test in
both files.

**Layout — Johan's ruling, verbatim: "you choose the properties at the top
and have the financials together at the financials section."** Property
SELECTION (the primary search box, the eligible-properties dropdown, the
disabled owner-set-mismatch entries, removed-properties restore) stays at
the top exactly where it was — nothing about picking or unlinking a
property moved. Every figure that must RECONCILE — each property's own
selling price and commission, the running totals, and (create mode) the
live balance verdict — now lives together in the Financials section,
immediately below the totals it feeds:

```
Financials
  Selling Price · Commission basis · Commission % · Commission amount
  Incl/Excl/VAT derived display
  ── Properties on this deal ──
  balance banner (create mode only — two independent lines, price and
                  commission, never combined into one verdict)
  property rows (each with its own Selling price / Commission input)
  "adding X" price/commission entry (appears here once picked from the
                                      dropdown up top; the page scrolls it
                                      into view so the user isn't left
                                      hunting for where to type it)
```

The balance banner sits BETWEEN the totals above and the rows below so
neither can be edited with its verdict off screen — Johan: "a warning
that's off screen that can't be seen" was the layout half of the same
underlying defect as the stale-hidden-field bug above; both are now fixed
together, not just the arithmetic.

**Edit screen scope, explicitly not extended**: the edit screen's rows and
add/edit-price forms moved into Financials and got the same live labelling
(below), but it does NOT get a balance banner. Structurally it cannot need
one: once a deal has 2+ properties, its Selling Price/Commission fields are
already `readonly` and server-derived
(`DealPropertyPricingService::recalculateTotals()` force-overwrites
`property_value`/`total_commission` to the sum on every save) — there is no
independently-entered total on the edit screen that could ever diverge
from the sum in the first place, unlike create mode where the BM types the
total by hand. Not a gap; there is nothing to reconcile against.

**Labels — Johan's ruling, verbatim, and a correctness issue, not
cosmetics: "an agent typing a number into a box marked 'Price' has no idea
which price... the rule: the per-property field carries the SAME label as
the total it feeds."**

- Every "Price" label became "Selling price", matching the Financials
  total's own label exactly. Selling price carries no VAT qualifier —
  checked with Johan directly rather than assumed: "selling is the total
  price incl comm, not vat" — there is no VAT dimension on price at all,
  only on commission, so none was invented.
- Every commission label — the Financials total, both create/edit
  add-forms, the edit-price flyout, and every rendered row — now reads
  "Commission (Incl VAT)" or "Commission (Excl VAT)" and FOLLOWS the
  Commission basis selector live, in the same instant it changes. One
  shared function, `dr2SetCommissionLabelText()`, sets every one of these
  elements' text in one place so they can never drift out of sync with
  each other or with the selector — the add-form's commission label was
  previously hardcoded to "Incl VAT" regardless of the actual basis
  selected, a real, separate labelling bug this fixes as the same class of
  defect.

**The VAT-basis-flip trap, decided deliberately (point 5 of the brief)**:
what happens to a commission already typed into a property row when the
basis flips from incl to excl or back? Chosen: CONVERT, never reinterpret,
never leave silently mismatched. Every property's commission is stored
CANONICALLY as Incl VAT internally — `dr2cpPrimary.commissionIncl`,
`p.commissionIncl`, matching `total_commission`'s own established
convention (already documented as "stored Incl-VAT total, DR1 truth" before
this build). What's DISPLAYED in a row or add-form is derived from that
canonical value at render time using the current basis
(`dr2ToDisplay()`/`dr2ToCanonicalIncl()`, the same `vatRate` `recompute()`
already reads from `PerformanceSetting`); what the user TYPES is converted
back to canonical before being stored. A basis flip therefore needs only a
RE-RENDER (`window.dr2cpRerenderRowsForBasisFlip()`), never a value
mutation — the real Rand amount never changes, only which of its two
equivalent representations is shown. Reasoning: this is not "reinterpreting"
(treating the same digits as if they'd always meant something else, which
Johan explicitly ruled out — "quietly changes a financial figure without
anyone touching it") because the real commission amount stays byte-for-byte
identical; only its displayed representation changes, which is the entire
point of an incl/excl toggle. It also had to behave this way for
consistency with point 4 above — labels that match the aggregate exactly
but convert differently on a basis flip would be a worse trap than the
mismatched labels this rebuild fixes. The edit screen's real-form
submission converts the same way, in a `'submit'` listener, immediately
before the browser reads the field — the server has never had a concept of
"basis" and must always receive Incl VAT, same as every other commission
figure.

**A second, pre-existing instance of the exact same defect, found while
re-verifying this in a real browser, not introduced by this build**: the
Financials Commission Amount field ITSELF — the deal-level aggregate, not
a per-property row — had the identical reinterpret bug already. Flipping
`dr2_vat_mode` re-derived incl/excl from whatever raw digits were already
sitting in the amount field, treating them as if they had always been
denominated in the NEW basis: a 20,000 Incl-VAT commission silently became
23,000 Incl-VAT purely from flipping the dropdown, with no digit touched.
This existed before the per-property rows did and was never noticed
because nothing downstream previously compared the aggregate against
anything. Fixed the same way, for consistency: `recompute()` now preserves
a `dr2FinCanonicalIncl` value across a `'mode'`-sourced call and derives
the new basis's displayed amount/percent FROM that preserved value, never
from the stale digits already on screen. Same rule, same fix, applied
everywhere a commission figure exists on this screen — reported here
because leaving the aggregate wrong while fixing only the rows would have
been an inconsistency worse than not fixing either.

**Verified with a real browser session** (not unit tests, which cannot
prove a focus bug or a scroll-visibility bug): typed a full multi-digit
number into every price and commission field on both the create screen
(primary row, second-property row) with no intervening click — every field
landed the full number and kept focus, confirming the original AT-Focus-Fix
result held through the relocation; confirmed the balance banner is visible
in the same viewport as both the totals above and the rows below without
scrolling; flipped the Commission basis selector and confirmed every label
(Financials total, both add-forms, every row) updated in the same instant,
and confirmed a row's displayed commission number changed to its converted
equivalent while the underlying canonical value did not; confirmed
Johan's own numbers (price wrong, commission correct) still correctly
blocks the save (price genuinely doesn't balance) while reporting the
commission line as balanced, never mentioning it in the price error.

## 8e. The master is derived, not reconciled — §8d's balance model superseded (2026-09-19)

Same day as §8d, re-verifying it live, Johan ruled the flow itself wrong —
correctly. His words: **"select property, seller gets assigned, agent
links buyer, auto fills selling price from deal, bm fills comm. Now we add
a 2nd property. so why don't we display the 2 properties — each with their
selling price and blank comms that agents can complete to fill 'master'
selling and comm? ... Picking the 2nd property is the trigger to load
both, show them and show their selling price and comm fields to be
completed."**

**The change that matters**: Selling Price and Commission above are no
longer a second, independently-typed figure checked against the sum of
the rows — they ARE the sum, derived and displayed, read-only the moment a
second property exists. Johan's own correction on his own earlier ruling
(§8a: "captured per property, total DISPLAYED, a human checks it looks
right"): "I got this wrong... it was always meant to be additive." Once
the master can't diverge from the parts by construction, there is nothing
left to check, warn about, or block a save over.

**REMOVED, not left inert** — reinstating any of this reintroduces a
solved problem, it does not restore a safeguard:
- `dr2cpBalanced()`, the two-line verdict banner (`dr2cp_balance_banner`,
  `dr2cp_price_status`, `dr2cp_comm_status`), and the submit-time block on
  `dr2-main-form` — all dead the moment the master can't disagree with the
  sum.
- The separate "Adding X — Price/Commission — Add to deal/Cancel"
  mini-form (`dr2cp_add_form` and its fields) — picking a property IS
  adding it now; there is no confirm step to gate.
- The sum-vs-total comparison half of
  `DealRegisterController::validateAdditionalPropertiesPayload()` (the
  `$errors['property_value']`/`$errors['total_commission']` block) — the
  client no longer submits a competing total to compare against.

**What genuinely still needs to exist, and does, unchanged**: server-side
integrity against a crafted request. Checked, not assumed:
`applyCreateTimeMultiProperty()` calls
`DealPropertyPricingService::recalculateTotals()` unconditionally, inside
the same transaction, immediately after every property row is persisted —
it force-overwrites `deals.property_value`/`total_commission` from the
REAL sum of the `deal_properties` rows regardless of what the request
submitted for the top-level fields. This has been true since the original
split-pricing build (§7) and is exactly how the edit screen has been
correct all along despite never having had a client-side balance check of
its own. A crafted request posting a top-level total that disagrees with
its own `properties[]` array is already harmless — not because it's
rejected, but because it's silently corrected before the transaction
commits. `test_a_mismatched_submitted_total_is_overridden_by_the_true_sum_not_rejected()`
and the commission-side equivalent in
`CreateTimeMultiPropertyCommissionCheckTest.php` prove the override
directly, not just its absence of an error.

**The new flow**:

1. Property SELECTION stays exactly where §8d put it — the primary search,
   the eligible-properties dropdown (`dr2cp_picker` / `dr2mp_picker`), the
   disabled owner-set-mismatch entries, removed-properties restore. None
   of that moved or changed.
2. Picking a property from the dropdown IS adding it — no separate confirm
   step. The moment a second property is picked, BOTH rows render
   immediately: the primary's own row (frozen from whatever was in the
   single-property Selling Price/Commission fields at that instant, same
   as before) and the new property's row.
3. The new row's Selling price prefills from the property's own record
   (the same `data-price` the eligible-properties endpoint already
   returns — `Property::toSearchResult()`'s `price` field, no new data
   source). Its Commission starts BLANK — `placeholder="0.00"`, no
   `value` — for the agent/BM to complete, exactly mirroring how the
   single-property flow already leaves commission for the BM to fill in
   rather than guessing it.
4. Selling Price/Commission above become read-only
   (`propValueEl.readOnly`/`pctEl.readOnly`/`amtEl.readOnly` = true,
   `modeEl.disabled` = true) the instant a second property exists — set
   client-side in `dr2cpSyncMaster()`, mirroring the edit screen's own
   long-standing server-rendered `$dr2MultiPriced ? readonly : ''` pattern
   exactly (that pattern already existed for edit mode; create mode simply
   never had a "multi" state to apply it to before this feature). They
   update live as each row's own fields are completed — computed from
   `dr2cpPrimary`/`dr2cpAdditional`'s current values on every keystroke,
   same `dr2cpSyncMaster()` call the row inputs already trigger for the
   focus-fix's own reasons.
5. Removing back down to one property restores the master fields to
   EDITABLE and repopulates them with the sole remaining property's own
   figures (not blank) — found and fixed live in browser verification: the
   first implementation nulled `dr2cpPrimary` BEFORE the re-render that
   needed to read it to restore the fields, so the revert silently no-oped
   and the master stayed stuck on its last pre-removal (multi) value. Fixed
   by reordering — render first, null the now-unneeded reference after.
6. A second bug found the same way: the single-mode revert branch calls
   `recompute('amount')` to re-derive %/incl/excl/VAT display for the
   restored figures, and `recompute()` itself calls
   `window.dr2cpRecomputeSummary?.()` at its end — straight back into
   `dr2cpSyncMaster()`, which recursed forever ("Maximum call stack size
   exceeded", caught live in the browser walk). Fixed with a re-entrancy
   guard (`dr2cpSyncingMaster`) around the function body, since duplicating
   `recompute()`'s derivation a third time would have been worse than
   guarding the one legitimate call-back into itself.

**Scoping call, made explicitly and reported, not decided silently**: this
"pick is add, no confirm" behaviour applies to CREATE mode only. Edit
mode keeps its existing pick → fill price/commission → confirm → real
`POST` flow, because that confirm step gates an actual server mutation
over the network on an already-persisted deal, not client-side state —
Johan's complaint was specifically about the create-time experience. Edit
mode's rows/forms keep §8d's relocated-into-Financials layout and live
basis-following labels unchanged; only create mode's trigger mechanism
changed.

**The VAT-basis canonical-value mechanism from §8d is unchanged and fully
reused** — every property's commission is still stored canonically as
Incl VAT (`dr2cpPrimary.commissionIncl`/`p.commissionIncl`), still
converted for display via `dr2ToDisplay()`/`dr2ToCanonicalIncl()` using
the shared `vatRate`. It answers an orthogonal question (which basis a
number is CURRENTLY expressed in) to the one this section answers (WHO
computes the total) — both were needed, and removing either would have
been wrong.

**Verified with a real browser session** (not unit tests — none of this
is reachable through an HTTP feature test): single-property mode
confirmed completely unaffected (fields editable, no rows rendered, no
count/hint changes) before and after typing; picking a second property
rendered both rows in the same instant with no separate confirm click,
the new row's price prefilled and its commission genuinely blank; typed a
full multi-digit number into all four price/commission fields (both
rows) with no intervening click — every field kept focus, re-proving
AT-Focus-Fix survived this second rewrite; completed both commissions and
confirmed the master updated live to the exact sum each time; removed the
second property and confirmed both the row list AND the master fields
correctly reverted to the remaining property's own figures, editable
again; re-added the property, flipped the Commission basis, and confirmed
every label switched instantly and the canonically-stored (submitted)
values were untouched by the flip; inspected the actual hidden inputs a
real two-property submission would post and confirmed `property_value`/
`total_commission` and both properties' own `allocated_price`/
`allocated_commission` were exactly correct.

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

## 10a. Defects found by cc1's real browser pass (2026-09-10)

This is the exact category of defect a browser catches and code-tracing
cannot — recorded here rather than glossed over, per BUILD_STANDARD's
"fix the class, not the instance."

**Defect 1 (forced a revert) — nested `<form>` broke the page's own "Update
Deal" submit.** All four of the multi-property list's forms
(`dr2mp_edit_form`, the per-row remove forms, the per-row restore forms,
`dr2mp_add_form`) were declared INSIDE the page's main deal-capture
`<form>`. A `<form>` cannot contain another `<form>` — invalid HTML — and a
browser silently drops the inner ones from the parse tree, which broke the
OUTER form's own submit. Johan would have hit this on the very first deal
he opened, since "Update Deal" is the core action of that screen.

Fixed by moving all four to a `display:none` block declared immediately
after the main form's closing tag (`resources/views/dr2/create.blade.php`,
the "AT-398 standalone forms" block) and associating each visible
input/button back to its real, external form via the HTML5 `form="..."`
attribute — which works regardless of DOM nesting. Fixed the SAME defect
in all four places, not just the one cc1 noticed, per Johan's "fix the
class" instruction. Verified mechanically (not by re-reading the markup):
a temporary diagnostic test rendered the real page through the full HTTP
stack for a deal exercising all four forms at once, and a `DOMDocument`
parser walked every `<form>` on the page confirming zero are nested inside
another (6 total forms on the page — the main one, the four AT-398 ones,
and the layout's own logout form — zero nested). The diagnostic test itself
was deleted after use; it was never meant to be permanent coverage.

**Defect 2 — pre-existing deals showed R0.00 on the new price card.** The
original migration's backfill set `is_primary = true` for every existing
deal's one property but never set `allocated_price`/`allocated_commission`
— so the new per-property price card showed R0.00 against a property that
plainly has a price. The deal's own authoritative `property_value`/
`total_commission` were never affected — this was display staleness on the
new card only, but it reads as broken the instant Johan opens an old deal.

Fixed via a follow-up migration
(`2026_09_10_120000_backfill_allocated_price_for_existing_single_property_deals`)
applying the exact mirroring rule already built for the single-property
case: `allocated_price`/`allocated_commission` = the deal's own
`property_value`/`total_commission`, scoped to `is_primary = 1 AND
allocated_price IS NULL` — precisely the rows the original backfill left
incomplete. Run against the real QA1 database: **corrected 16 rows**,
verified idempotent (re-running immediately after reports "corrected 0"),
and verified it never touches a row that already carries a real price or a
non-primary row (which has no deal-level total to mirror from in the first
place).

## 10. Test coverage

Reporting convention (per Johan, 2026-09-10): the branch's own coverage is
the headline; a pre-existing file re-run as a regression check is named
separately, never folded into one combined figure.

**This branch's own tests: 55 across 6 files, 113 assertions, all passing.**

| File | Count | Covers |
|---|---|---|
| `tests/Feature/Dr2/Wave2MultiPropertyStatusSyncTest.php` | 9 | Pivot mirroring, all six listeners multi-property, both mixed-status cases |
| `tests/Feature/Dr2/DealPropertyOwnerGateTest.php` | 9 | Exact-set gate, Johan's Steve/Dave example both directions, no-owner-refusal, plain-English message |
| `tests/Feature/Property/PropertyOwnershipGuardTest.php` | 18 | Lock/unlock by status, all four assert methods, self-exclusion behavior |
| `tests/Feature/Dr2/DealAddRemovePropertyControllerTest.php` | 11 | HTTP-level: accept, refuse+message, branch co-share, primary-removal block, audit, permission gate, price sum on add/remove/update/restore |
| `tests/Feature/Dr2/DealMultiPropertyBladeTest.php` | 4 | Blade rendering: empty state, multi-property sum + read-only main fields, archive/restore section, plain-language refusal on the page |
| `tests/Feature/Dr2/BackfillAllocatedPriceTest.php` | 4 | Defect 2's fix: corrects stale NULLs from the deal's own totals, idempotent, never overwrites a real price, never touches a non-primary row |

**Pre-existing regression check (not this branch's own coverage):**
`tests/Feature/Dr2/Wave2DealPropertyStatusSyncTest.php` — 15 tests, 39
assertions, all still passing — proves the six-listener rewrite introduced
zero regressions on ordinary single-property deals.

Combined run (all 7 files together, one process): 70 passed, 152
assertions, zero cross-file interference.

**Functional proof against the live QA1 database** (real rows, created and
fully cleaned up afterward via raw SQL — verified zero residue every time):
pivot mirroring, gate refusal, ownership lock, self-exclusion; the additive
price-on-top behavior and sum-recalculation on add/edit/remove/restore
(including that an edited price survives a remove-then-restore round trip);
a full HTTP-stack Blade render of the real edit page; the Defect 1 nesting
fix verified via a real `DOMDocument` parse of the rendered page (not
grep); and the Defect 2 backfill migration run for real — 16 rows
corrected, then confirmed idempotent.

**Still owed — a full browser pass, including the save button specifically**
(per cc1's own instruction on hand-off): the client-side JS itself — the
add-property search dropdown, the Address/Price/Date-added sort buttons,
the filter box, the inline edit-price box opening/closing, AND, most
importantly, that "Update Deal" now saves correctly with the relocated
forms in place — was traced by hand and verified structurally (DOM
nesting), not exercised end-to-end by a human/browser. No browser-
automation tool is available in this environment. Handing back to cc1 for
that pass, as instructed, rather than declaring this fixed on inspection.
