# DealMoneyLineRebuilder crashes on an agency-less deal (grant → sibling-decline → recalc)

> **Status: HANDOFF — documented, not fixed.** Discovered 2026-07-30 while landing the DR2
> composable deal-status lifecycle (`Dr1PipelineService::syncDealStatus`, commit `7977c1bc`).
> This is a **commission-pillar** issue, deliberately left untouched — the fix is a 2-line
> guard, described below. Author: lane-6 (Deal Structure / date-engine).

---

## Symptom

Marking all suspensive conditions complete on a composable deal (deal 190) failed to flip the
deal to **Granted**: the status write rolled back with

```
SQLSTATE[HY000]: General error: 1364 Field 'agency_id' doesn't have a default value
(SQL: insert into `deal_money_lines` (... ) values (181, 44, 2026-07, 2, selling, ...))
```

The grant **logic was correct** (it set `accepted_status = 'G'`); the rollback came from a
downstream commission side-effect on a **sibling** deal (181), not from the granted deal (190).

---

## Exact repro chain

1. A deal is **granted** — `accepted_status` → `G` (via the new
   `Dr1PipelineService::syncDealStatus`, or the legacy `applyStatusTrigger`; **any** grant path).
   The deal is `save()`d.
2. That fires **`App\Listeners\Deal\AutoDeclineSiblingDealsOnGrant`** — every OTHER active
   (`P`/`G`) deal on the **same property** is auto-declined (Wave-2: at most one granted per
   property). It crosses branch/agency scopes and calls `$sibling->save()` on each.
   (`app/Listeners/Deal/AutoDeclineSiblingDealsOnGrant.php:41-53`)
3. Each `$sibling->save()` fires **`App\Observers\DealObserver::saved`**, which runs
   **`Artisan::call('deals:recalc-money-lines')`**. (`app/Observers/DealObserver.php:79`)
4. `deals:recalc-money-lines` → **`DealMoneyLineRebuilder::rebuild`** rebuilds
   `deal_money_lines` for the deals in scope, including the just-declined sibling.
5. If that sibling has **`agency_id = NULL`**, the guard at
   **`app/Services/DealMoneyLineRebuilder.php:253`** leaves `agency_id` **out of the payload**:

   ```php
   if ($deal->agency_id) {                       // ← falsy for an agency-less deal
       $payload['agency_id'] = (int) $deal->agency_id;
   }
   // ...
   DealMoneyLine::create($payload);              // ← INSERT with no agency_id → 1364
   ```

   `deal_money_lines.agency_id` is NOT NULL with no default → **SQLSTATE 1364**. The exception
   propagates up and **rolls back the entire grant transaction** (`completeStep` /
   `syncDealStatus` wrap everything in one `DB::transaction`), so the grant never persists.

### Concrete instance
- Deal **190** (property 4731, agency 1) granted.
- Sibling deal **181** (same property 4731) had **`agency_id = NULL`** (orphan test data).
- Declining 181 → recalc → rebuild 181's money lines → line 253 skipped agency_id → 1364 → 190's
  grant rolled back → 190 stayed **Pending**.

---

## Root cause

`DealMoneyLineRebuilder` (line 253) only stamps `agency_id` when the deal already carries one.
For an **agency-less deal** it silently omits the column and then `DealMoneyLine::create()`
violates the NOT-NULL constraint. The rebuilder has **no fallback and no skip** for a deal that
cannot resolve an agency, so it crashes instead of degrading.

(The line-253 comment already documents the multi-agency NOT-NULL trap for the *unscoped-owner*
case — this is the same class, one rung further out: a deal whose `agency_id` is itself NULL.)

---

## Blast radius

- **Trigger:** any deal grant (composable OR legacy template model) on a property that has an
  **agency-less** sibling deal — because the grant auto-declines the sibling, whose save
  recalcs money lines.
- **Broader:** *any* `Deal::save()` at all triggers `deals:recalc-money-lines`
  (`DealObserver::saved`), so an agency-less deal anywhere in the recalc scope can crash a recalc
  invoked from an unrelated save. It is **latent** — it only manifests once agency-less deal data
  exists (orphan deals created without an agency context; see the "agency_id NOT-NULL landmine"
  class in the memory index).
- **Effect:** the crashing transaction rolls back — a status flip (grant/decline), a settlement
  write, or a manual save can silently fail. No data corruption, but the intended write is lost.

---

## Precise fix (for the commission owner — 2 lines, NOT applied here)

Make the rebuilder **degrade, not crash**, when a deal has no resolvable agency. Either:

**Option A — skip agency-less deals (safest; an agency-less deal should not have money lines):**
```php
if (! $deal->agency_id) {
    continue;   // or `return;` — do not build money lines for a deal with no agency
}
$payload['agency_id'] = (int) $deal->agency_id;
```

**Option B — resolve a fallback agency from the deal's own relations, then skip if still none:**
```php
$agencyId = $deal->agency_id ?: optional($deal->user)->agency_id ?: optional($deal->branch)->agency_id;
if (! $agencyId) { continue; }
$payload['agency_id'] = (int) $agencyId;
```

Option A is the minimal, correct guard (matches "an agency-less deal is an orphan"). Option B is
kinder if some legitimate deals legitimately lack a direct `agency_id` but can inherit one.

Either way, add a `\Log::warning(...)` when skipping so the orphan is surfaced, and pair it with a
one-off data sweep to backfill `agency_id` on any existing agency-less deals.

---

## Interim workaround already applied (QA1 only, authorized)

Deal **181**'s orphan `agency_id` was corrected `NULL → 1` (operating agency) so deal 190 could be
reconciled to Granted. That un-stuck the one test deal; it does **not** fix the class — any future
agency-less sibling will hit the same crash until the rebuilder is guarded.

## Not changed here
No commission / money-line code was modified. The DR2 grant lifecycle fix lives in
`app/Services/Deal/Dr1PipelineService.php` (`7977c1bc`) and is unrelated to this crash — it merely
surfaced it by making composable deals actually reach the granted state.
