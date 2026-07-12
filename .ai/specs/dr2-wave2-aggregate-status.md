# DR2 Wave 2 — Deal → Property status: multi-deal aggregate + granted uniqueness

> Spec for the Wave 2 expansion (Johan + Elize ruling). Extends the shipped
> "DR2 Wave 2: Deal → Property → Portal status sync" (single-deal listeners).

## 1. Architecture ruling — ONE chain (no second trigger system)

A pipeline step trigger writes the **DEAL** status only (`Deal.accepted_status`,
via `Dr1PipelineService::applyStatusTrigger` → a loud `save()`). The Wave 2
listeners derive **PROPERTY** status from the resulting deal-status transition
(`DealObserver` → `DealCreated`/`DealStageAdvanced`/`DealClosed` → listeners).
Settings (`agency_deal_sync_settings`) hold the milestone→status mapping.
**No pipeline step writes property status directly.** (Verified: already true —
nothing to remove.)

## 2. Multi-deal aggregate rules

A property may legally carry **multiple concurrent deals** (two offers = two
captured deals). Property status derives from the deal **aggregate**, not a
single deal:

- **Any active (pending `P` or granted `G`) deal on the property → `under_offer`.**
- **The sold milestone (granted `G` or registered `R`, per agency setting) on
  any deal → `sold` (per setting).**
- **Revert fires ONLY when NO other active deal remains.** Deal 1 declined while
  Deal 2 still pending → the property **STAYS `under_offer`** (it does not revert
  to on-market). Revert restores `pre_deal_offer_status` only when the last
  active deal leaves.

"Active" = `accepted_status ∈ {P, G}` (not declined `D`, not registered `R`).
The check crosses branch/agency scopes (`withoutGlobalScopes`) — a property's
deals may span branches.

## 2b. Grant CASCADES (Johan refinement — overrides "block on pending")

A property holds MANY pending deals freely (4 offers = 4 pending deals). When
ONE deal is **Granted**, every OTHER active deal on that property is
**AUTO-DECLINED** — audited (`deal_logs` event `auto_declined`, message
"Auto-declined: deal #X was granted…"), never silent. Only one deal is granted
at a time; only one proceeds to Registered. Implemented as a listener on
`DealStageAdvanced(→G)` (`AutoDeclineSiblingDealsOnGrant`) so it fires for every
loud grant path (capture/edit, quick-update, pipeline trigger).

**Re-grant path:** if the granted deal falls through (→ Declined), the
auto-declined deals remain **RE-GRANTABLE** — `Declined → Granted` is legal while
no other grant exists and the property is not Registered (the pipeline
forward-only rank protects Registered only; `D` has rank 0 so `D→G` advances).

## 3. Granted uniqueness — the block covers exactly ONE case

The cascade covers the normal flow. The **block modal survives for exactly one
case: attempting to grant a deal while ANOTHER deal is already Granted (or
Registered)** on the property. That is a conflict a human must resolve, so it is
blocked (never auto-cascaded over a committed deal).

**At most ONE deal per property may be in the granted-or-registered lane
(`accepted_status ∈ {G, R}`).** The block is enforced at EVERY write site that
can set a deal to Granted:
- DR2 `DealRegisterController::persistDeal` (capture/edit) + `quickUpdate` (register list)
- DR1 `Admin\DealController` equivalents
- pipeline `Dr1PipelineService::applyStatusTrigger` (trigger → `G`)
- DealV2 twin quiet sync `DealSyncService::syncFromV2`

Attempting to grant a second deal on a property that already carries a
granted/registered deal is **blocked** (the deal is not saved as `G`).

### UX (Johan's design) — user-facing paths
A modal: **"This deal may only be set to Declined — deal #XXXX already carries a
Granted status on this property"**, where **#XXXX is a clickable link** opening
THAT deal in a **new tab** (the user resolves it there — e.g. declines the
fallen-through cash deal). The current screen **preserves all entered data**
(`back()->withInput()`), so the user returns and continues without loss.

### Pipeline / sync paths
The same constraint applies. A trigger (or twin-sync) that would create a second
grant is **blocked and surfaced** (an error the user sees), **never silently
swallowed**. The pipeline step completion rolls back; the sync logs + skips the
duplicate grant.

## 3c. Resale / duplicate-address guard (DR2 property search)

Real scenario: a buyer registers a property, renovates, relists months later →
TWO property records at one address (old Sold/archived, new Active). DR2's
property picker steers agents to the LIVE record:
- **(a)** default filter = on-market statuses (`Property::scopeOnMarket`,
  excluding `OFF_MARKET_STATUSES` = sold/archived/etc). A `?all=1` toggle
  ("Show sold/archived too") reveals the rest for edge cases.
- **(b)** result rows carry a **status badge** + **key dates** (listed date; sold
  date on off-market twins, derived from the property's registered `R` deal's
  `registration_date`).
- **(c)** selecting an off-market record fires a hard **WARN** ("this record was
  sold on <date> — deals on it will not update statuses; did you mean the active
  listing?"). Old records never receive status updates from new deals — the
  Wave 2 listeners already skip `OFF_MARKET_STATUSES`, so this is enforced, not
  just advisory.

## 4. Portal vocabulary

Property status (`under_offer` / `sold`) maps to per-portal listing vocabulary
via the existing per-portal mappers (P24 `Property24ListingMapper::getP24Status`
→ `Pending`; PP treats under-offer as still-on-market). No change here — Wave 2
only sets the canonical property status; the mappers own the portal words.
(Coordinated with the P24 trigger + PP under-offer lane.)

## 5. Enforcement mechanism

`App\Services\Deal\DealPropertyStatusService`:
- `otherActiveDealsExist(Deal): bool` — the aggregate-revert gate.
- `existingCommittedDeal(Deal): ?Deal` — the granted/registered deal already on
  the property (excluding self), if any.
- `assertCanGrant(Deal): void` — throws `App\Exceptions\Deal\DuplicateGrantException`
  (carries the conflicting deal) when granting would violate uniqueness.

Application-level (not a DB constraint — MySQL has no partial unique index).
Every grant write path calls the guard; the aggregate-revert listener calls
`otherActiveDealsExist`.

## 6. Acceptance
- Two-offer scenario end-to-end: property under-offer with 2 pending deals;
  decline one → STAYS under-offer; decline the other → reverts on-market.
- Grant deal A → OK; grant deal B on same property → blocked (modal / thrown).
- Pipeline trigger that would 2nd-grant → blocked + surfaced (step rolls back).
- Sold milestone still sells; revert still restores prior status when last
  active deal leaves.
