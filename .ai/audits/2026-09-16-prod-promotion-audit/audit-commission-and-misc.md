# Prod promotion audit — commission money math (SCOPE A) + misc areas (SCOPE B)

Range: `6545f0262..57407d5a5` (694 non-merge commits), branch `Prod` checked out, read-only review.
Date: 2026-09-16. Method: `git show` / `git diff` of the named commits plus full reads of every
money-path file; no tests run, no repo file touched. Every finding is marked CONFIRMED (proved by
reading the code path end to end) or PLAUSIBLE (mechanism is real, trigger not proved).

---

## 0. Ranked findings

| # | Sev | Area | One line |
|---|-----|------|----------|
| A1 | HIGH | Commission | Code fix alone leaves every defect-shaped deal UNBALANCED on the settlement screen (phantom "External payable" on an internal side) and blocks Mark Paid until `deals:correct-share-percent-defect --apply` runs. The data correction is therefore REQUIRED with the deploy, not an optional later step as runbook §7 implies. CONFIRMED |
| A2 | HIGH | AT-398 money | Changing the primary property on a multi-property deal via the edit form leaves the new primary with NULL price/commission and never re-sums; the next add/remove/price-update collapses `deals.total_commission` to the other properties' sum, shrinking every agent's pool. CONFIRMED |
| A3 | HIGH | AT-398 gate | The same edit-form primary swap bypasses the exact-same-owner gate (`assertCanAddToDeal` never runs on that path; only `assertHasKnownOwner`). A deal can end up spanning properties with different owner sets — the legal rule the feature exists to enforce. CONFIRMED |
| A10 | HIGH | AT-398 money | Multi-property re-sums (`recalculateTotals`) use `saveQuietly()`, so the DR2 twin (`deals_v2`) never learns the new `total_commission`; the twin's next loud save (native V2 edit, V2 "mark Paid", or the correction command's V2 pass) writes its stale commission back onto the V1 deal via `syncFromV2`, collapsing the pools. CONFIRMED mechanism, trigger is a routine V2 action |
| A4 | MEDIUM | Commission | "Our Share %" is still rendered unconditionally on both V1 capture forms and persisted on internal sides; any new value <100 typed there recreates the A1 un-payable shape the day after the correction runs. CONFIRMED |
| A5 | MEDIUM | Correction cmd | `--apply` is global (all agencies), includes soft-deleted deals, and rewrites financial fields on PAID/locked deals (bypassing the controllers' Paid lock), rebuilding their money lines; existing `commission_ledger` rows for those Paid deals are NOT regenerated. Needs a Johan decision on LIST B before apply. CONFIRMED |
| A6 | MEDIUM | Correction cmd | After `--apply`, `finance_computed_values` (Company/Branch performance ENGINE path) stays at the old halved figures for the affected periods — the command never calls `RollupService::refreshPeriod`; only a UI deal save does. CONFIRMED |
| A7 | MEDIUM | AT-398 money | DR2 edit save (`persistDeal`) writes the posted `property_value`/`total_commission` for a multi-property deal without re-summing allocations (spec §8e says the edit screen relies on `recalculateTotals`; it is never called on that path). Stale-tab or crafted POST desyncs deal total from the property rows. CONFIRMED mechanism / PLAUSIBLE trigger |
| A8 | LOW | AT-398 points | `addProperty()` re-fires `DealCreated` + `DealStageAdvanced`; instant-points idempotency is per user x DAY x definition, so adding a property on a later day re-credits `deal.created` / `deal.stage_advanced`. Gamification points only, not money. CONFIRMED |
| A9 | LOW | Legacy readers | Branch/Agent legacy rollup readers join `deals` without `deleted_at IS NULL` (pre-existing, unchanged in range). Note only. |
| B5 | HIGH | contact-property | Client API `ClientSellerInsightsController.php:61,117` reads `contact_property` raw with no `deleted_at` filter. Now that unlink is a soft delete, a seller who was UNLINKED from a property keeps seeing that property's viewings/feedback/compliance/price in the client app. New cross-party exposure created by the promotion. CONFIRMED |
| B6 | HIGH (ordering) | contact-property | `Contact::properties()` / `Property::contacts()` now emit `contact_property.deleted_at IS NULL` on every query; between `git pull` and `migrate --force` every contact/property page, the owner gate and e-sign recipient lookups 500 (unknown column). Run migrate immediately after pull, before fpm reload. CONFIRMED |
| B7 | HIGH / PLAUSIBLE (env) | safety | Mail kill switch treats the box as "sending" ONLY when `APP_ENV=production` AND `APP_URL` host is exactly `corexos.co.za`/`www.corexos.co.za`; any other APP_URL vetoes every outbound mail (OTP, e-sign, client) and paints a red test-site bar. Code is already in base (zero net diff) — if live is at 6545f0262 and mail flows today, already satisfied; verify `/corex/.env` before deploy regardless. |
| B8 | MEDIUM | properties (AT-419) | Imported Stock split moves the agency's OWN sold/let listings that originated from a P24 import off the Properties page and its Sold tile — nil on day one (needs `properties:backfill-p24-imported --apply`), then permanent. Johan's call before the backfill. CONFIRMED |
| B9 | MEDIUM | contact-property | Two more raw reads still unfiltered: `ContactController.php:717-721` (seller-perspective viewing feedback keeps showing for an unlinked contact) and `DeedsCaptureController.php:105` (deed hidden from the suspense screen after its seller link was soft-removed; twin in `DeedsCaptureLinkService.php:439` WAS fixed, so the two lists disagree). CONFIRMED |
| B10 | MEDIUM | safety | `OutboundMailGuard.php:199,204,209` call `env()` outside `config/` (returns null under `config:cache`; fallbacks are correct so no functional harm on live). Rule violation; already in base. CONFIRMED |
| B11 | LOW | several | Sort key `p24_imported_at` not whitelisted (sorts nothing); `PropertySgController::search()/saveAll()` still lack the own/branch check cdf237fc3 added to save/download; mobile/client JSON `price` int→float (PLAUSIBLE client-parse risk); `EmailSetupController::index()` 500s for an owner if opened before migrate; `x-collapse` without the plugin (pre-existing pattern); suburb-report joins missing `deleted_at` on `p24_listings`/`p24_price_changes`/`market_reports` and `ContactMatch::withoutGlobalScopes()` (already in base); PDF-splitter disk hard-code / strict compare / public-disk filing / no tests; `DeedsCaptureController.php:190` badge and two `buyers:backfill-*` commands read soft-removed links; `DevSetting::get()` re-queries on every render when no override row exists. Details in section 4. |

---

## 1. SCOPE A.1 — the arithmetic, before vs after (acaf76b89)

### 1.1 What changed
`CommissionPoolCalculator::internalPool($exVat, $isExternal, $split)` is now the single formula:
`external → 0.0`, else `exVat × clamp(split)/100`. **`our_share_percent` is no longer a factor
anywhere in the internal pool.** Wired into:

- `app/Models/Deal.php:401-413` (`calculateInternalPool`)
- `app/Models/DealV2/DealV2.php:254-263`
- `app/Services/Finance/CommissionCalculator.php:38-55` (`companyIncomeExVatBreakdown`)
- `app/Services/DealMoneyLineRebuilder.php:45-46` (`computeDealPools`) and `:127-130` (`rebuildSingleDeal`)

Everything downstream that used to reimplement the formula now goes through
`CommissionCalculator::companyIncomeExVatForSide()` (FinanceComputeService, all four Legacy
readers, CompanyPerformanceService fallback paths, AgentPerformanceController fallback,
GenerateCommissionLedgerEntries) — verified by grep: no remaining reader multiplies by
`our_share_percent` itself.

### 1.2 Worked numbers (VAT 15%, total_commission R58,650 inc = R51,000 ex)

| Shape | Field values | BEFORE pools (L / S / total) | AFTER pools | Settlement checksum AFTER (code only) |
|---|---|---|---|---|
| (a) one external side — deal #169 shape | L: external=1, split 50, our=0 (form forces 0) · S: external=0, split 50, our=**50** | 0 / **12,750** / 12,750 | 0 / **25,500** / 25,500 | pool 25,500 + externalExVat 38,250 = **63,750 ≠ 51,000 → FAILS** |
| (a') one external side, normal data | S our=100 | 0 / 25,500 / 25,500 | identical | 25,500 + 25,500 = 51,000 OK |
| (b) no external side, normal | both our=100, split 50/50 | 25,500 / 25,500 / 51,000 | identical | OK |
| (b') no external side, defect shape | both our=50 | 12,750 / 12,750 / 25,500 (balanced only because phantom payable 25,500 ex absorbed the gap) | 25,500 / 25,500 / 51,000 | 51,000 + 25,500 phantom = **76,500 → FAILS** |
| (c) both sides external | external=1/1 | 0 / 0 / 0; externalPayable 58,650 inc | identical | OK (unchanged) |
| (d) referral / revenue share | no dedicated field; a referral was only ever expressible as our_share<100 on an internal side | pool reduced, payout shown as external payable | pool 100 % AND payout still shown → double-counted | FAILS as (a)/(b') |

After `--apply` (our_share → 100 on the internal side) row (a) becomes 0 / 25,500 with
listingExternalPayable 29,325 inc (25,500 ex): 25,500 + 25,500 = 51,000 → balanced.

### 1.3 Do V1 and V2 agree? Does the rebuilder rebuild the same numbers?
- V1 `Deal` and V2 `DealV2` call the same static with the same defaults (`split ?? 50`); the only
  divergence is pre-existing: `DealV2::commissionExVat()` returns `commission_amount` when the VAT
  rate is 0 whereas V1 returns the inc total — identical at 15 %.
- `DealMoneyLineRebuilder::rebuildSingleDeal()` uses the same static but (pre-existing) rounds
  `totalEx` to 2 dp and re-normalises splits that don't sum to 100; both controllers enforce 100, so
  the projection matches the model to the cent. `computeDealPools()` does not re-normalise — same as
  the model. Test `CommissionInternalPoolShareDefectTest::test_commission_calculator_deal_money_line_rebuilder_and_model_methods_all_agree`
  covers four shapes.
- `BranchRollupLegacyReader` (`app/Services/Finance/Legacy/BranchRollupLegacyReader.php:33-50`)
  still selects `total_commission, listing/selling_external, listing/selling_our_share_percent,
  listing/selling_split_percent` and hands the row to `CommissionCalculator` — correct columns; the
  AT-420 change swaps `users.branch_id` for `COALESCE(deals.branch_id, users.branch_id)` (deal
  stamp first). Behaviour change by ruling, not a defect.

### 1.4 Finding A1 — HIGH, CONFIRMED: phantom external payable makes defect-shaped deals un-payable until the data correction runs
- `app/Services/DealMoneyLineRebuilder.php:48-54` — for a NON-external side the "external payable"
  is still `sideInc × (1 − our_share/100)` ("left exactly as it was"), while the pool for that same
  side is now the full split. The two halves no longer reconcile.
- `app/Http/Controllers/Admin/DealController.php:795-800` (`saveSettlement`) and `:1121-1122`
  (`buildSettlementSummary`) add `externalExVat` to the checksum and compare to the ex-VAT total;
  `mark_paid` is refused when it doesn't balance. `app/Http/Controllers/Dr2/DealSettlementController.php:65-67,118,144`
  consume the same pools. `resources/views/admin/deals/settle.blade.php:82,199,330` and
  `resources/views/dr2/settle.blade.php:80,204,342` print "External payable: R …" under the internal side.
- The fix's own test asserts this state: `tests/Feature/Commission/CommissionInternalPoolShareDefectTest.php`
  `test_external_payable_calculation_is_completely_unchanged_by_this_fix` — pool 100,000 AND
  external payable 46,000 on a 100,000-ex-VAT deal.
- Scenario: deal 1818/169 on live after code deploy, before `--apply`: settle screen shows selling
  side pool R25,500 (correct) plus "External payable R14,662.50" on the internal side, total External
  Payable R43,987.50, checksum red, "Cannot mark this deal as Paid because it does not balance.
  Checksum is R 63,750.00 but Total Commission (ex VAT) is R 51,000.00."
- Root cause: two definitions of "our share on an internal side" now coexist — "meaningless"
  (pool) vs "referral paid out" (payable). The pre-fix code was at least self-consistent.
- Recommendation for this promotion: treat `deals:correct-share-percent-defect` (dry-run, review,
  `--apply`) as PART of the deploy, immediately after code + cache clears. Follow-up build (not in
  this promotion): either zero `externalPayable` for a non-external side in `computeDealPools()`, or
  stop rendering/persisting `our_share_percent` for a non-external side (A4).

### 1.5 Finding A4 — MEDIUM, CONFIRMED: the field that causes the defect is still open for input
- `resources/views/dr2/create.blade.php:606-609` and `:668-671`, `resources/views/admin/deals/form.blade.php:243,287`
  render "Our Share %" for every side regardless of the external checkbox (no JS toggle — grep for
  `our_share` in the DR2 JS finds nothing besides the inputs).
- `app/Http/Controllers/Dr2/DealRegisterController.php:759,851,857` and `app/Http/Controllers/Admin/DealController.php:501,552,556`
  force it to 0 when external and persist whatever was typed when internal.
- Net effect: the value is dead for the pool in every state, but on an internal side it still
  drives the phantom payable in A1. A user who types 50 there tomorrow creates a deal that cannot be
  marked Paid, and the one-shot correction command will not catch it unless re-run.
- The V2 (`deals_v2`) forms are fine: `resources/views/deals-v2/create-form.blade.php:207-218` and
  `form.blade.php:187-199` only show the field inside the external block, and
  `DealV2SettlementController::buildSettlementSummary()` (`:257-270`) computes payable only for
  external sides — V2 is self-consistent.

---

## 2. SCOPE A.2 — `deals:correct-share-percent-defect`

File: `app/Console/Commands/CorrectCommissionInternalPoolShareDefect.php`.

- **Dry run by default: CONFIRMED** (`:36-40`; nothing is written unless `--apply`).
- **Discovery:** `DB::table('deals')` / `DB::table('deals_v2')` where a side is non-external (0 or
  NULL) and its `our_share_percent` is NOT NULL and != 100 (`:82-96`, `:145-159`). NULL our-share is
  treated as 100 and excluded. External sides are excluded (the forms force 0 there).
- **What `--apply` writes (`:52-66`, `:243-292`):** inside one `DB::transaction`, per affected side:
  a `DealLog` row (V1: `event_type=commission_share_percent_correction`, `from_value`, `to_value=100`,
  message with old/new pool) or a `DealActivityLog` row (V2, with `metadata`), then sets
  `{side}_our_share_percent = 100` and `save()`s the model. After commit, V1 deals get
  `DealMoneyLineRebuilder::rebuildDealId()` (synchronous) and, via `DealObserver::saved`, a queued
  `RebuildDealMoneyLinesJob` (harmless duplicate). Idempotent: a corrected deal no longer matches.
- **Scoping:** GLOBAL — no agency filter, no `--agency` option; AgencyScope is a no-op in console
  (no auth user, `app/Models/Scopes/AgencyScope.php:56-57`). Intended for a one-off historical
  correction, but be aware it touches every agency on the live box.
- **Soft-deleted deals:** the discovery query has no `whereNull('deleted_at')`, and apply uses
  `withoutGlobalScopes()->findOrFail()`, so trashed deals are corrected and logged too. The money-line
  rebuild (`Deal::query()`) skips trashed ones. Harmless but noisy. (A5)
- **Paid / locked deals:** NOT skipped. The controllers refuse financial edits on `commission_status =
  'Paid'` (`Admin/DealController::isLocked`), but the command writes the field regardless and rebuilds
  `deal_money_lines`, so payslip/report figures for deals already paid out change retroactively. The
  command only REPORTS these under "LIST B" — it does not ask. `commission_ledger` rows written by
  `GenerateCommissionLedgerEntries` (idempotent on an existing deal+agent row, `:85`) are NOT
  regenerated, so the cap/revenue-share ledger keeps the old halved amounts for those deals. (A5)
- **Rollups:** no `RollupService::refreshPeriod()` call → `finance_computed_values` for the affected
  (period, agency) stay stale; `CompanyPerformanceService` prefers that ENGINE path when rows exist
  (`app/Services/Admin/CompanyPerformanceService.php:200-210`). Only a UI save of any deal in that
  period (or a Tinker `refreshPeriod`) refreshes it. (A6)
- **Could it "correct" a legitimately non-100 deal?** Yes, by design: any internal side with a value
  other than 100 is treated as the defect. The one legitimate reading of such a value ("we pay a
  referral share out of our own side") is exactly what the new code no longer supports, so the
  command aligns data with code; the before-value is preserved in the audit row. A genuine external
  side "flagged wrongly" (external=0 but an external agency name filled) cannot exist through the
  forms — `persistDeal` forces `external=1` whenever the agency name is non-empty
  (`DealRegisterController.php:749`).
- **Should it run on live after deploy?** YES, and promptly (see A1). Sequence in section 5. The
  runbook's "separate decision" framing (§7) is no longer safe once the code is live, because the
  code fix alone makes the affected deals unbalanced. Johan's decision is only needed for LIST B
  (deals already settled/paid): correct them (changes historical payslip figures, requires manual
  ledger/payout reconciliation) or leave them and accept a permanently red checksum on those deals.

---

## 3. SCOPE A.3 — AT-398 multi-property (18115ffa0 and follow-ups)

### 3.1 The six listeners — events, registration, sync/queued
All six are registered explicitly in `app/Providers/AppServiceProvider.php:403-415` (discovery is
OFF; these registrations pre-date the range) and none implements `ShouldQueue` — they run
synchronously in the request:

| Listener | Event | Loops `deal->properties()`? |
|---|---|---|
| `FlagPropertyUnderOfferOnDealCreated` | `DealCreated` | yes (`:44-66`) |
| `AutoDeclineNewDealOnCommittedProperty` | `DealCreated` | via `existingCommittedDeal($deal)` which loops linked ids — but early-returns when `deals.property_id` is NULL (`:36`) |
| `MarkPropertySoldOnDealMilestone` | `DealStageAdvanced` | yes (`:42-67`); 43bfcab2f stops nulling `pre_deal_offer_status` |
| `RevertPropertyStatusOnDealDeclined` | `DealClosed` (lost/abandoned) | yes, per-property aggregate check (`:49-96`); 43bfcab2f accepts `sold` |
| `AutoDeclineSiblingDealsOnGrant` | `DealStageAdvanced` (to G) | yes, siblings de-duplicated (`:40-52`) |
| `EnsurePropertyUnderOfferOnGrant` | `DealStageAdvanced` (to G) | yes, fresh reads (`:50-80`) |

Also on `DealCreated`: `LogDealEvent` (audit only) and `CreditInstantActionListener::handleDealCreated`
(points). All listeners read only the `deal_properties` pivot; the migration
`2026_09_10_100000_create_deal_properties_table.php` backfills a primary row for every non-deleted
deal with a `property_id` (chunked, 500) — no unique index by design (SoftDeletes).

`28efd3ebe` fixed the create-time gap by re-firing `DealCreated`/`DealStageAdvanced` from
`applyCreateTimeMultiProperty()` (`DealRegisterController.php:585-590`); `addProperty()` does the
same (`:1694-1699`). Same-day re-fires are idempotent for points; see A8 for later days.

### 3.2 Money split across properties
Commission is NOT split per `allocated_price`. Pools are computed from `deals.total_commission`
only; `DealPropertyPricingService::recalculateTotals()` (`app/Services/Deal/DealPropertyPricingService.php:52-66`)
force-writes `deals.property_value/total_commission = Σ allocated_*` via `saveQuietly()`. So the sum
equals the deal total exactly when — and only when — `recalculateTotals()` ran after the last write
to `deal_properties`. It runs in `addProperty`, `removeProperty`, `restoreProperty`,
`updatePropertyPrice`, `applyCreateTimeMultiProperty`. It does NOT run in `persistDeal()` (edit
save) nor in `Deal::syncPrimaryPropertyPivot()` — grep of `recalculateTotals` in
`DealRegisterController.php` returns lines 500(comment),568,1687,1728,1756,1788 only.

### 3.3 Finding A2 — HIGH, CONFIRMED: primary swap on a multi-property deal leaves a NULL allocation and later collapses the deal total
- Path: DR2 edit form → change the property picker (hidden `property_id`,
  `resources/views/dr2/create.blade.php:174`; the picker is NOT locked in multi mode — only the
  price/commission inputs are `readonly` at `:347-378`) → `persistDeal()` fills `property_id` and
  saves (`:824-858`) → `Deal::booted()` `updated` hook (`app/Models/Deal.php:42-62`, property check
  at `:59`) → `syncPrimaryPropertyPivot($deal, $old)` (`:72-113`): soft-deletes the old primary row,
  creates the new primary row with `allocated_price/commission = NULL` (`:92`), and only mirrors the
  deal totals onto it when the active-row count is ≤ 1 (`:107`) — on a 2+-property deal it does
  nothing more.
- No `recalculateTotals()` runs on this path, so `deals.total_commission` keeps the posted value for
  now; the next `recalculateTotals()` (any add/remove/restore/price update on the deal) sums the NULL
  as 0.
- Numbers: A R1,000,000 / R50,000 (primary) + B R500,000 / R25,000 → deal R1,500,000 / R75,000.
  Agent changes primary A → C (same owner). C row: NULL/NULL. Later the agent edits B's price to
  R600,000 (commission unchanged R25,000) → `recalculateTotals` → deal R600,000 / **R25,000**.
  Listing pool (50 % split) drops from 75,000/1.15×0.5 = R32,608.70 to R10,869.57; every listing-side
  agent's allocation and payslip shrinks by two-thirds, silently.
- Test coverage: `Wave2MultiPropertyStatusSyncTest::test_changing_property_id_on_update_replaces_the_primary_and_soft_removes_the_old_one`
  documents the swap; no test asserts the allocation/total after a swap on a 2+-property deal.

### 3.4 Finding A3 — HIGH, CONFIRMED: the same edit-form primary swap bypasses the same-owner gate
- `persistDeal()` only calls `DealPropertyOwnerGate::assertHasKnownOwner($linkCandidate)`
  (`DealRegisterController.php:797-806`); `assertCanAddToDeal()` (exact owner-set equality,
  `app/Services/Deal/DealPropertyOwnerGate.php:89-104`) runs only in `addProperty`, `restoreProperty`
  and `applyCreateTimeMultiProperty`. The `updated` hook that adds the new primary to the pivot has no
  gate at all.
- Scenario (Johan's Steve/Dave example): deal has A (Steve) + B (Steve). Agent edits the deal and
  picks C (owned by Steve AND Dave) as the property. Result: pivot = B (Steve) + C (Steve, Dave) —
  Dave cannot sign for B; the deal must be two deals per the ruling, but the system accepted it.
- `removeProperty()`'s own docblock (just above `:1712`) points users to exactly this path ("reassign a
  different property as primary first — editing property_id already does this"), so it is the
  documented way to change the primary, not an obscure one.

### 3.5 Finding A7 — MEDIUM (mechanism CONFIRMED, trigger PLAUSIBLE): edit save trusts the posted totals on a multi-property deal
- `persistDeal()` validates `property_value`/`total_commission` as `required|numeric` (`:639-640`)
  and writes them (`:824-825`). In multi mode the inputs are `readonly` — readonly fields ARE
  submitted — and the hidden `dr2_total_commission` (`create.blade.php:390`) carries whatever was
  rendered. No server-side re-sum afterwards. Spec `.ai/specs/dr2-multi-property.md` §8e states the
  edit screen relies on `recalculateTotals()` as its sole integrity guarantee; that call does not
  exist on the edit path.
- Trigger: a second tab / a colleague updates a property price (which re-sums) and then the stale
  edit form is saved → deal total reverts to the stale figure while `deal_properties` carries the new
  one; or any crafted POST. Numbers: rows R50,000 + R25,000 (deal R75,000); colleague updates row 2
  to R35,000 (deal → R85,000); stale form saved → deal back to R75,000, rows still sum to R85,000.
  Pools now computed on R75,000; no screen flags the mismatch.

### 3.6 Finding A8 — LOW, CONFIRMED: later-day property add re-credits instant points
- `addProperty()` re-fires `DealCreated` and `DealStageAdvanced($fresh, same, same)`
  (`DealRegisterController.php:1694-1699`). `CreditInstantActionListener::handleDealCreated`
  credits creator + both sides; `handleDealStageAdvanced` credits `deal.stage_advanced`. Idempotency
  is user x date x definition x subject (`app/Services/Activity/InstantPointService.php:99-113`), so a
  property added on a different day from capture/grant awards the points again. Points only.

### 3.8 Finding A10 — HIGH, CONFIRMED: the DR2 twin never sees a multi-property re-sum and later writes its stale total back
- `DealPropertyPricingService::recalculateTotals()` writes the new `property_value/total_commission`
  with `saveQuietly()` (`app/Services/Deal/DealPropertyPricingService.php:63-66`, deliberately, to
  avoid re-entering `Deal::booted()`). Quiet saves suppress `DealObserver::saved()`
  (`app/Observers/DealObserver.php:77-91`), which is the ONLY place `DealSyncService::syncFromV1()`
  runs (callers: that observer and the manual `DealParityCheck` command; nothing scheduled). So after
  `addProperty` / `removeProperty` / `restoreProperty` / `updatePropertyPrice` on an existing deal, the
  twin row in `deals_v2` keeps the OLD `commission_amount + commission_vat`.
- Any later loud save of the twin — `DealV2Controller::update` (`:684`, `:717`), the V2 settlement
  screen's "mark Paid" (`DealV2SettlementController.php:127`), or `deals:correct-share-percent-defect`
  `--apply` on a V2 row — fires `DealV2Observer::saved()` → `DealSyncService::syncFromV2()`
  (`app/Services/DealV2/DealSyncService.php:217-220`): `if ((float)$v1->total_commission !== $incl)
  $dirty['total_commission'] = $incl;` → the V1 deal's total is overwritten with the stale twin value
  via `forceFill()->saveQuietly()`, again silently (no observer, no money-line rebuild until the
  04:45 safety net).
- Numbers: single-property deal R1,000,000 / R50,000 (twin: 43,478.26 + 6,521.74). Agent adds a
  second property R500,000 / R25,000 → V1 R1,500,000 / R75,000, twin still R50,000. Later the
  bookkeeper marks the deal Paid on the V2 settlement screen → V1 `total_commission` becomes R50,000
  while `deal_properties` still sums to R75,000. Listing pool R32,608.70 → R21,739.13; every payslip
  on the deal is short by a third, and the next `recalculateTotals()` would flip it back to R75,000 —
  the figure oscillates depending on which screen was touched last.
- Create-time multi-property is fine (the posted sum is saved loudly first, so the twin gets
  R75,000 before the quiet re-sum). The gap is the edit-time add/remove/price paths only.
- Also affects display: every V2-based screen (distribution, pipeline financials) shows the old
  total for a multi-property deal until something loud-saves the V1 row.

### 3.7 Ownership lock
`OwnershipLockedException` is thrown from the contact_property write sites (PropertyContactController,
ContactPropertyController, MobilePropertyController) while a deal is open — the lock is on changing a
property's owners, not on the deal's property set, so it does not close A3.

---

## 4. SCOPE B — area notes

(Each area: one pass over the listed commits; findings below are CONFIRMED by reading the diff plus
the surrounding HEAD code unless marked PLAUSIBLE. The 2026-09-16 gallery/proforma/command-center/
properties commits assigned to the other reviewer were skipped.)

### 4.1 Properties — AT-419 Imported Stock (ff538dfee, 1e92bdaf1, 465a2ec00, cfa9e04b6)
- **B1 MEDIUM, CONFIRMED (business effect, not a code defect):** the split key is
  `p24_imported_at IS NOT NULL AND status IN importedStockStatuses()` (= OFF_MARKET minus
  draft/prospecting/not_selling: sold, sold_by_3rd_party, transferred, withdrawn, expired, cancelled,
  let_out, archived, unavailable) — `app/Models/Property.php:212-256`,
  `app/Http/Controllers/CoreX/PropertyController.php:154-162`. A listing the agency confirmed from a
  P24 import and later SOLD ITSELF leaves the Properties page, its "Sold" tile and `?status=sold`, and
  reappears under Imported Stock tagged "Imported". Day-one impact on live is nil (historical rows
  have `p24_imported_at = NULL` until `properties:backfill-p24-imported --apply` is run); it applies
  to every import confirmed after deploy and to all history once the backfill runs. Johan should
  decide before the backfill is run.
- **B2 LOW, CONFIRMED:** `resources/views/corex/properties/index.blade.php:1018,1039` renders a
  `?sort=p24_imported_at` header but the controller whitelist (`PropertyController.php:~343`) has no
  such key → falls to default sort; "Imported Date" sorts nothing. Safe (no injection).
- Gates all present: permission `access_imported_stock` (`config/corex-permissions.php:342`, in the
  three role_defaults lists), route `corex.properties.imported-stock` with
  `permission:access_imported_stock, agency.required, deny_assistant_property_write`, registered
  before `/{property}`; sidebar entry gated by `@permission`. Scoping is the shared `index()` code
  (AgencyScope + `applyRoleScope()` own/branch/all at the query layer), search/filter/sort/pagination
  shared; separate session filter keys. Migration `2026_09_15_100000_add_p24_imported_at_to_properties_table.php`
  is additive/indexed/reversible. `status` is NOT NULL, so no row can fall out of both pages; live
  listings are not in the set so cannot vanish from Properties.
- Post-deploy: migrate; `deploy:sync-reference-data` (runs `corex:sync-permissions --merge-defaults`,
  `SyncReferenceData.php:91`) — if skipped every non-owner gets no sidebar entry and 403 on the page;
  backfill only on Johan's word (dry-run default).

### 4.2 Properties — AT-402 Rental tab (864df62b4, de3db9528, c2e7717ab, 057bf8e4e)
No findings. Migrations `2026_09_10_210000_add_furnished_and_utilities_to_properties.php`
(additive, real `down()`) and `2026_09_10_220000_backfill_furnished_status_property_settings.php`
(idempotent seed via `provisionDefaultsFor`). Rental fields validated in the new
`PUT /corex/properties/{property}/rental-details` (`updateRentalDetails`: numeric ≥0, commission 0–100,
admin/marketing fee ≤ `rental_fee_max_amount` default 50,000, dates, strings ≤100) and in
store/update. Server-side gate: `authorizeProperty()` then `abort_if(listing_type !== 'rental' ||
listing_type_pending, 403)`; Blade tab wrapped in `@if` so a sale property ships no rental markup.
No data loss: `update()` applies only present keys; its only forced booleans are `pp_hide_*`/
`p24_hide_address` (`PropertyController.php:1470-1476`); the forced rental booleans live only in
`updateRentalDetails()` whose own form renders them. Commission %/Admin Fee/Marketing Fee are
display/prefill only (deal pickers, mandate defaults, mobile read-back) — no arithmetic consumes
them. New setting `rental_fee_max_amount` is surfaced in `config/agency-onboarding-copy.php`
(rule 10a satisfied).

### 4.3 Properties — other (49baa4529, 5990f8c8f, f7a0773e7, cdf237fc3, 38abcdcd6, a372224c6, 7a819497e, 3452cc4df)
- `effectivePrice()` = `isRental() ? rental_amount : price` (rental/to_let/to-let/lease,
  case-insensitive). Sale listings unchanged (a sale with a stray `rental_amount` still returns
  `price`; POA/0 renders as before; `listing_price` was never a column so `listing_price ?? price`
  was already `price`). Rentals now show `rental_amount` where they showed R 0 / POA (CMA comparables,
  seller-outreach merge fields, deal/filing pickers, Ad Manager, Map, viewing packs, Ellie, mobile /
  client portal). `MapPinService` select gained `rental_amount`; no undefined variable.
- **B3 LOW, PLAUSIBLE:** JSON `price` changed type int → float in the mobile/client-portal
  payloads (`MobilePropertyController.php:94,887,1671`, `Api/V1/ClientPortalController.php:133,320`,
  `ClientSellerInsightsController.php:88,176`, `MobileCoreMatchController.php:72`) because
  `effectivePrice()` returns float where `$p->price` was cast integer. A strictly-typed mobile
  client could fail to parse; app source not in repo, no test asserts the type. Smoke the mobile
  properties list before promotion.
- **B4 LOW, CONFIRMED (pre-existing, only partially closed):** cdf237fc3 added `authorizeProperty()`
  to `PropertySgController::saveDocument()` and `download()` only; `search()` (`:41`, soft-deletes
  unsaved rows, can forceFill `sg_*` defaults) and `saveAll()` (`:168`) still rely on `guardAgency()`
  alone, so an own/branch-scope user can still run them against a colleague's property by ID.
  Cross-agency is blocked (AgencyScope route binding → 404). The AT-392 check is a post-binding
  `abort(403)`, not a query filter, but it does block direct-URL by ID for the two covered methods.
- f7a0773e7: publish gates (missing-fields, publishToggle, wizard finalize, MarketingReadinessService)
  use `effectivePrice()`, so a rental with `rental_amount` is no longer blocked on "Price".
- 38abcdcd6: Blade/JS only; DELETE still goes `PropertyContactController::unlink()` →
  `ContactPropertyLinker::unlink()` → soft delete (`ContactProperty` uses SoftDeletes,
  `app/Models/ContactProperty.php:29`) with audit log.
- a372224c6 / 7a819497e: pure restyle; no links/inputs/routes added or removed (7a819497e's hard-coded
  filter-form route was corrected by 1e92bdaf1). `x-collapse` at `show.blade.php:1058` has no
  `@alpinejs/collapse` plugin imported anywhere — Alpine ignores it, `x-show` still drives the fold,
  eight other views already do this (LOW, pre-existing pattern).
- 3452cc4df: confirmed — the teleported modal root now carries `x-data="{ wbReportOpen: false }"
  @open-wb-report.window`, trigger dispatches `open-wb-report`.
- No dd()/dump()/env()/Mail additions in any of these diffs.

### 4.4 Tasks (6112506ef, 4ef97c740, b96961f82)
No findings. `CommandTask::scopeWithoutPropertyAutomation()` hides a task only when
`source_type = 'automation_rule' AND property_id IS NOT NULL AND deal_id IS NULL`; `automation_rule`
is written solely by `AutoEventService` (property-created chores, daily no-activity flags);
user-created tasks never set `source_type` and deal automation keeps `deal_id`, so no user task can
be hidden. Applied consistently to board columns, header counts (`getBoardSummary`) and Archived.
Columns still go through `CommandTask::visibleTo()` on top of AgencyScope with
`limit(KANBAN_COLUMN_LIMIT=200)` per open column and 20 for Done. `archived()`
(`TaskController.php:194-199`) still does an unbounded `->get()` — pre-existing, LOW. The other two
commits are Blade/JS only (At Risk strip removed, columns always rendered, stale localStorage key
cleared in `init()`).

### 4.5 Today / command-center (992bdea5e, 76ed14c26)
No findings. `DashboardController::today()` unchanged, still passes exactly `user` and `cards`; the
rebuilt `today.blade.php` reads only `$user` and `@json($cards)`; all rendering is Alpine over the
cards payload (`URGENCY_RANK`, `stripCards` defined in the same script).
`CommandCentreService::todayAppointments()` only adds `end_time/all_day/date/colour` (real
`calendar_events` columns); calendar visibility still via `PermissionService::calendarScope` +
`CalendarEventService`; no new query. Recent Activity / empty Strategic Insights are filtered
client-side; the service still assembles the card (mobile API keeps receiving it by design).

### 4.6 Gallery (de4b6ac7d)
No findings. `Property::syncGalleryCategories()` rebuilds `gallery_categories_json` in memory so
every `gallery_images_json` URL is filed exactly once (path-based key, first placement wins, stale
entries dropped); `syncGalleryCategoriesLocked()` re-reads FOR UPDATE and `saveQuietly()`s (no portal
resync from a categories-only change). Wired into `store()`, `update()` (when new files arrived) and
`uploadImages()`. Mobile read side gained a non-writing fallback that surfaces unfiled photos as
unsorted. Backfill `php artisan properties:sync-gallery-categories --dry-run` (also `--property=`,
`--chunk=`) then without the flag; idempotent, cannot double-file (seen-map by path), scans
`withoutGlobalScopes` (includes archived rows — harmless). The mobile upload path already writes both
columns (`MobilePropertyController.php:748-750`), so the stale-entry drop cannot strip real uploads.
No migration.

### 4.7 Mobile / proforma / company-settings — other (a941db0f8)
No findings. `ProformaSettingsController::agencyId()` accepts a posted `agency_id` only when the
login `isOwnerRole()` and the agency exists, else `effectiveAgencyId()`; a login with no resolvable
agency is redirected with a plain message instead of the `agency_id=0` sentinel 500 (Rule 17).
Company Settings' proforma panel reads/posts for the agency being shown. The
`Agency::withoutGlobalScopes()->exists()` check accepts archived agencies — that is what the skipped
337c734d8 closes, so the two must travel together (they do, both in range). Permission unchanged
(`proforma.manage`).

### 4.8 Contact-property no-hard-delete conversion (0ab8e31d8, be36d45a4, fc6afb5c3, 3c4ad4e5f, 235911370, f942ffbfa, 0adfdbdff)
What changed: migration `2026_09_16_100000_add_deleted_at_to_contact_property.php` adds a nullable
`deleted_at` after `source` (single column add, no data rewrite, unique index on
(contact_id, property_id) deliberately kept — on MySQL < 8.0.29 the `AFTER` forces an INPLACE
rebuild; brief metadata lock, PLAUSIBLE, live version not readable from the repo). New pivot model
`App\Models\ContactProperty` (SoftDeletes) and `App\Services\Property\ContactPropertyLinker` as the
single write path: `link()` does `withTrashed()->first()` on the pair, restores a trashed row and/or
updates the role in place, else creates — a re-link never inserts a duplicate and never hits the
unique index (covered by `tests/Feature/Property/ContactPropertyLinkerTest.php`). `unlink()`
soft-deletes and throws `ContactPropertyRoleMismatchException` when an asserted role does not match.
All 18 former attach/sync/detach/updateOrInsert/delete sites go through the linker; no raw
`contact_property` insert remains; the only hard deletes left are the two documented exceptions
(`PropertyObserver::forceDeleted`, `ContactController::destroyAll` forceDelete cascade).
`Contact::properties()` / `Property::contacts()` gain `wherePivotNull('contact_property.deleted_at')`
(+ `withTrashed*()` for history), so every relation-based read, `whereHas`, eager load and Blade
`$x->contacts` access is filtered for free; 0adfdbdff filtered 14 raw sites in Prospecting, Command
Center, Compliance and the Deal owner gate (`DealPropertyOwnerGate` uses `wherePivotNull` — column
present once migrated; schema snapshot already has it).
- **B5 HIGH, CONFIRMED (verified by reading):** `app/Http/Controllers/Api/V1/ClientSellerInsightsController.php:61-64`
  (`index()` seller-property list) and `:117-121` (`show()` role gate) are raw `DB::table('contact_property')`
  reads with no `whereNull('deleted_at')`. The spec's own LIST A names them and its risk note says the
  fix is dangerous "if shipped incompletely". Scenario: agent unlinks a seller (property/contact
  Unlink button, mobile `contactsUnlink`, deed re-select) → previously the pivot row was hard-deleted
  and the client app lost access at once; now the row remains with `deleted_at` set and
  `/api/v1/client/seller-properties` still lists the property and
  `/seller-properties/{id}/insights` still serves viewings, feedback, compliance and price to
  someone who is no longer the seller. Fix is two `->whereNull('deleted_at')` lines; treat as
  blocking for the client-facing API (fix before or in the same deploy).
- **B6 HIGH (deploy ordering), CONFIRMED:** `app/Models/Contact.php:722-729`, `app/Models/Property.php:928-932`
  — with the relation filter in code and the column not yet migrated, every contacts list/detail,
  property detail, `DealPropertyOwnerGate`, e-sign recipient lookup etc. throws SQLSTATE 42S22. Run
  `migrate --force` immediately after `git pull`, before the fpm reload.
- **B9 MEDIUM, CONFIRMED:** `app/Http/Controllers/CoreX/ContactController.php:717-721` (raw
  `contact_property as cp` join filters `p.deleted_at` but not `cp.deleted_at` — unlinked contact
  keeps that property's viewing feedback under "Seller perspective", same-agency only);
  `app/Http/Controllers/CoreX/DeedsCaptureController.php:105` (`whereNotExists ... cp.role='seller'`
  lacks the filter, so a deed whose seller link was soft-removed stays hidden as "consumed" while
  `DeedsCaptureLinkService.php:439` — fixed in 0adfdbdff — shows it).
- LOW, CONFIRMED: `DeedsCaptureController.php:190-192` "seller already linked" badge;
  `BuyersBackfillFlagCommand.php:175` / `BuyersBackfillWonCommand.php:63` (console only).
- LOW, PLAUSIBLE: `ComposeSellerService.php:505` calls `unlink(..., 'seller')` inside a transaction
  without catching the role-mismatch exception (only a concurrent role change on that pair could
  throw; the other two asserted-role callers catch + 409).
- Backfill command `corex:backfill-contact-property-roles` (3c4ad4e5f): pre-existing, dry-run
  default; `--apply` writes only `role` normalisation, `--apply --infer-sole` fills NULL roles on
  sole-contact properties; both scans now exclude soft-deleted rows. Not a required post-deploy step;
  must not run before the migration.

### 4.9 Contacts (97b9094fa, affb31399, 4d669d9c2, 1638275e7, d325446a8, 44d124bfb, 081e1a82e, 8c6d0e0a9, 25897ecc3, 1dc9de703, 274f5f2e4, 544755f81)
No findings. Restyle commits: per-commit before/after counts of `route()` calls, named inputs,
forms/inputs/selects/buttons/links, `@csrf/@method`, `x-data/x-teleport` are identical except the
intended ones — 081e1a82e dropped the wrapper `x-data` and 44d124bfb restores it (present verbatim at
`index.blade.php:6` at HEAD); affb31399 moves Save/Cancel outside the details form bound via
`form="contact-update-form"` (form id `show.blade.php:488`, button `:992`), same route/fields.
Blade balance at HEAD for all seven views checked (the apparent `@if/@else` mismatch is inside a
`{{-- --}}` comment). Rentals → Contacts lens (1dc9de703, 8c6d0e0a9, 274f5f2e4, 25897ecc3): gated on
route name `corex.rentals.contacts.index`; first-load default derives from
`PermissionService::getDataScope($user,'contacts')` — the same ceiling `ContactScope` uses — and a
session pref or `?agent_id` only narrows; sort is a `[$sort] ?? name` whitelist (name/created/updated)
with direction coerced and an id tie-breaker; pagination `appends()` the whole query string.
Tenant type (544755f81): `parent_type_ids.*` validated with `Rule::in(ContactType::parentIds())`
(`ContactController.php:1468`); `applyTypeAssignments()` unions in unoffered types the contact
already holds before the sync, so a save cannot strip them. Post-deploy: `view:clear` only.

### 4.10 Gates (5ed5c436f, 399b84550, 5a70a4713)
Only `scripts/rental-click-through*.mjs`, `scripts/rental-click-through-fixture.php`,
`scripts/rental-smoke.mjs`, `.ai/STANDARDS.md` and `database/schema/mysql-schema.sql` — no runtime
code. Snapshot diff adds/removes 0 `CREATE TABLE`, 0 `DEFINER`; `grep -c DEFINER` at HEAD = 0.
Nothing to run on live.

### 4.11 Map (34b1fea68, 2c4783cb4, 58cad72b8, 6a31c9828, 45c3b1616, 781a7cb8e, 1eb80c366, 8c9296aed)
Framing (CONFIRMED by `git diff 6545f0262 57407d5a5 --stat`): the map view and these fold/badge/
search commits are already in base `6545f0262` (zero net diff on `resources/views/corex/map/index.blade.php`);
the only net-new `MapPinService` change at HEAD is the rental-aware `effectivePrice` filter/subtitle,
which is not one of the listed commits. Reviewed anyway: folds query `properties`/`prospecting_listings`
with `agency_id` + `whereNull('deleted_at')`; all referenced columns/helpers exist; `PORTAL_STOCK_MERGED_KEYS`
declared before first runtime use; `ALL_LAYER_KEYS` still includes `tracked_properties`. No findings.
Post-deploy: `view:clear` + hard refresh.

### 4.12 Suburb report (fa7836338, bd30addef, 7a57cc7b8, 7a2a330ef, 9cc4c0659, d04359fe2)
Models, migrations (`2026_08_25_090001` suburb_municipalities, `2026_08_25_100001` suburb_reports),
seeder, `SaleStageLabel` and the screen are already in base; net-new at HEAD is
`->where('listing_type','sale')` in `SuburbReportDataService::layerB()` and the MIC `work()` stock
injection (8 lines). Gates present: routes `routes/web.php:5412-5416` with `auth` +
`permission:access_prospecting` + `feature:prospecting` (key `config/corex-permissions.php:470`),
sidebar `corex-sidebar.blade.php:1967`, controller derives `agencyId` from `effectiveAgencyId()` and
403s when null; every data query is agency-constrained; suburb resolved by route-model binding
(`whereNumber`) at HEAD, so the earlier `LOWER(name) = ?` ambiguity is superseded. "Granted counts as
sold" (fa7836338) touches only `SuburbReportDataService` + `SaleStageLabel`; no agency-tracker,
earnings or commission code reads it — CONFIRMED. LOW (already in base): joins on
`p24_listings`/`p24_price_changes`/`market_reports` lack `deleted_at`; `layerC()` uses
`ContactMatch::withoutGlobalScopes()` which also drops SoftDeletingScope; NULL `listing_type` rows
drop out of stock with the new filter. Post-deploy: migrate (if not yet applied) then
`deploy:sync-reference-data` (`SuburbMunicipalitySeeder` is auto-discovered; without it every suburb
shows "municipality not confirmed").

### 4.13 PDF splitter (472fc9384, 9d53c2b4d, 99f1b8893, 4c3634072, 819c13bf2) — net-new
Routes `routes/web.php:1305-1306,1316-1317` all carry `permission:access_pdf_splitter`
(`corex-permissions.php:521`) inside the auth group; every referenced route name exists.
`RentalApplication` and `Document` are `BelongsToAgency` (cross-agency IDs 404 at binding);
`guardRentalApplication()` enforces own/branch/all; document must match the application's
source_type/source_id (`:449`); contact search/`Contact::find` under `ContactScope`; original pack
archived via `Document::delete()` (SoftDeletes) with a guard for anchored marks. No raw SQL
interpolation, no dd/dump. LOW, CONFIRMED: `PdfSplitterController.php:~451-462` checks
`Storage::disk($document->disk ?: 'local')` but copies from `Storage::disk('local')` (consistent
today — rental uploads are on `local`); `linkForRentalApplication()` strict-compares
`source_id === id` (holds with emulated prepares off); contact-filed pieces go to the PUBLIC disk
`contacts/{id}/files` (pre-existing convention, compliance documents); no automated tests. Blade/
Alpine read through: balanced, all methods defined. Post-deploy: `route:clear && view:clear`; verify
`storage/app/private/splitter/originals` writable.

### 4.14 Safety (e2d1fcda5, 71426637a, 192be6b45, 81ebe3ffc, 60c762992)
The mail kill switch (`app/Support/OutboundMailGuard.php`, `OutboundMailGuardServiceProvider`,
captures + toggle-audit tables, owner-only toggle with mandatory reason, global banner, daily nag)
is already in base — zero net diff in range. Only `artisan` (60c762992) is net-new. Read anyway:
- **B7 HIGH / PLAUSIBLE (env-dependent), mechanism CONFIRMED:** `OutboundMailGuard.php:84-99`
  `isSendingConfirmed()` returns true only when `config('app.env') === 'production'` AND the
  `APP_URL` host is exactly `corexos.co.za` or `www.corexos.co.za`; otherwise `isActive()` is true and
  the `MessageSending` listener vetoes every message (default/`otp`/`corex` mailers, notifications,
  queued mail at send time in the worker, per-mailbox SMTP, IMAP Sent append) into
  `outbound_mail_guard_captures` and shows the red test-site bar. Older docs still show
  `APP_URL=https://corex.hfcoastal.co.za`. Mandatory pre-deploy check: `grep -E '^APP_(ENV|URL)=' /corex/.env`.
  If live is already at 6545f0262 and mail flows today, this is satisfied.
- CONFIRMED: no new .env key is required; the override is a `dev_settings` row
  (`mail_intercept_forced`), absent → environment default → SEND on a correctly configured live box
  (`tests/Unit/Support/OutboundMailGuardTest.php:140-158`).
- **B10 MEDIUM, CONFIRMED:** `OutboundMailGuard.php:199,204,209` use `env()` outside `config/`
  (null under `config:cache`; fallbacks give the correct "no local sink" answer).
- LOW: capture log is global (no agency_id, full raw MIME, no retention) — owner-only tool by
  design; no sidebar entry for `/corex/admin/outbound-mail-captures` (only the Email Setup card link,
  and only while intercept is active AND overridden); `EmailSetupController::index():41-57` queries the
  two new tables for an owner → 500 if opened before migrate; `DevSetting::get()` re-queries on every
  render when no override row exists (`Cache::remember` does not cache null).
- 60c762992 `artisan:24-58`: guard fires only for migrate*/db:wipe when `DB_DATABASE === 'corex_qa1'`
  and `QA1_DEPLOY_CHECKOUT !== 'true'` — live (`corexos`) unaffected; on the QA1 host `/corex-qa1/.env`
  needs `QA1_DEPLOY_CHECKOUT=true` or its deploy migrations refuse. A shell-exported `DB_DATABASE`
  takes precedence over `.env`.

---

## 5. Post-deploy commands the orchestrator should recommend (live host `/corex`, branch `Prod`)

Order matters. Dry-run first every time.

```bash
cd /corex
git rev-parse HEAD                                       # BEFORE pulling: if this is 6545f0262 the mail kill switch, map folds and suburb report are already live
grep -E '^APP_(ENV|URL)=' /corex/.env                    # must be APP_ENV=production and APP_URL=https://corexos.co.za (or www.) — B7
# Fix B5 (two whereNull('deleted_at') lines in ClientSellerInsightsController) BEFORE or IN this deploy — client-facing exposure otherwise.
git pull --ff-only origin Prod && git rev-parse HEAD && git rev-parse origin/Prod   # must match
php artisan migrate --force                              # IMMEDIATELY after pull, before fpm reload (B6) — 92 migrations in range (section 6)
php artisan deploy:sync-reference-data --dry-run
php artisan deploy:sync-reference-data                   # runs corex:sync-permissions --merge-defaults (access_imported_stock, rental apps, buyer pipeline …) + SuburbMunicipalitySeeder
php artisan view:clear && php artisan route:clear && php artisan config:clear && php artisan cache:clear
# reload the live php-fpm pool; restart the worker groups (trailing colon) — MessageSending guard and the linker run in the worker
# Smoke: load any page as a NON-owner → no red "This is a test site" bar; `SELECT * FROM dev_settings WHERE \`key\`='mail_intercept_forced'` → 0 rows;
#        open a contact detail and a property detail (B6); mobile properties list parses (`price` is now a float, B11).

# Commission defect — data half of acaf76b89 (A1): REQUIRED, not optional
php artisan deals:correct-share-percent-defect           # dry run: prints LIST A (bookkeeping only) + LIST B (already settled/paid)
#   -> paste the report to Johan. LIST A: safe to apply. LIST B: his call per deal (retroactive payslip change).
php artisan deals:correct-share-percent-defect --apply   # after his go; transaction-wrapped, audited, idempotent
php artisan deals:correct-share-percent-defect           # re-run dry: must say "No deals affected"
# Refresh the finance engine cache for each affected (period, agency) printed in the report (A6):
php artisan tinker --execute="(new \App\Services\Finance\RollupService())->refreshPeriod('2026-06', 1);"   # one call per period/agency
# Then open deal 1818's settle screen: checksum must be green, External payable on the internal side must read R 0.00.

# Verification queries (read-only)
#   SELECT id, deal_no, listing_external, listing_our_share_percent, selling_external, selling_our_share_percent FROM deals
#    WHERE (COALESCE(listing_external,0)=0 AND listing_our_share_percent<>100) OR (COALESCE(selling_external,0)=0 AND selling_our_share_percent<>100);   -- expect 0 rows
#   SELECT * FROM deal_logs WHERE event_type='commission_share_percent_correction' ORDER BY id DESC;
```

Do NOT run `deals:recalc-money-lines` without a filter mid-day (full rebuild, all deals); the
04:45 scheduled run covers the rest.

Optional / Johan's call only (each dry-run by default):
- `php artisan properties:backfill-p24-imported` then `--apply` — moves historical P24-origin
  sold/let listings to Imported Stock (B8: they leave the Properties page and its Sold tile).
- `php artisan properties:sync-gallery-categories --dry-run` then without the flag — idempotent.
- `php artisan corex:backfill-contact-property-roles` — report only unless `--apply`; never before
  the migration.

Follow-up builds this audit recommends (NOT part of the promotion): A1/A4 (zero the external payable
on a non-external side or hide/ignore the field), A2/A3/A7/A10 (gate + re-sum + loud twin sync on the
edit-form primary swap and multi-property edit save), B5/B9 (remaining `contact_property` read
sites), B10 (`env()` → config key), B11 items.

---

## 6. Migrations in range (92) — for the orchestrator's checklist
See `git diff --name-only 6545f0262 57407d5a5 -- database/migrations`. Money-relevant ones:
`2026_09_10_100000_create_deal_properties_table.php` (creates + backfills primary rows, chunked),
`2026_09_10_110000_change_allocated_price_to_decimal_on_deal_properties.php` (raw ALTER),
`2026_09_10_120000_backfill_allocated_price_for_existing_single_property_deals.php` (another reviewer),
`2026_09_16_100000_add_deleted_at_to_contact_property.php` (SCOPE B, contact-property).
No migration in range touches `deals.*_our_share_percent` or the commission columns.
