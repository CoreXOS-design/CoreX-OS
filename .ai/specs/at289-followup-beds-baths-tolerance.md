# AT-289 follow-up — Buyer-match beds/baths overshoot tolerance (agency-configurable)

> **Status:** SPEC ONLY — awaiting Johan's ruling. NO build until signed off.
> **Parent:** AT-289 (buyer-count suburb honesty). Same "defensible figures" doctrine, beds/baths axis.
> **Pillar:** Contact (buyer wishlist) × Property. Reads matching; no new data written except one agency setting.

---

## 1. Problem (code-traced)

Buyer matching treats beds/baths as a **one-directional minimum** with **no upper bound**:

`MatchingService::applyHardFilters()` — `app/Services/Matching/MatchingService.php:331-336`:
```php
// beds:  beds_min IS NULL OR beds_min <= property.beds
// baths: baths_min IS NULL OR baths_min <= property.baths
```

So a buyer whose wishlist says "at least 3 beds" matches a **7-bed R1.9M house** — and, because price+type+suburb can all be satisfied, scores **100%**. Surfaced live on qa1 (AT-289 verify, property #6060): eleven score-100 buyers for a 7-bed house, several of whom asked for far fewer beds. The count is now suburb-honest (AT-289) but still **overshoots on size** — a 3-bed seeker is not really a buyer for a 7-bed mansion, so a "defensible figure" is still overstated.

Beds/baths are **also scored** (proportional, in `score()` from `:373`), but the scoring rewards "≥ min" as a full hit — it does not decay as the property exceeds what the buyer asked for.

## 2. Proposed change (for Johan's ruling)

Introduce an **agency-configurable overshoot tolerance** on beds (and baths), so a minimum-N wishlist matches N..N+tolerance, not N..∞:

- **New agency settings** (defaults are Johan's call — suggested):
  - `match_beds_overshoot_tolerance` (default **2**) — a buyer wanting ≥N beds matches properties with N..N+2 beds.
  - `match_baths_overshoot_tolerance` (default **2**).
  - `0` = disabled (current behaviour: no upper bound) — so an agency can opt out.
- **Hard gate (upper bound):** `property.beds <= beds_min + tolerance` (NULL-permissive: a wishlist with no `beds_min` is unconstrained, unchanged). Same for baths.
- **Scoring not binary (where sensible):** within the tolerance band the beds/baths axis **decays with distance** from the requested minimum (exact = 1.0, +tolerance ≈ floor), instead of a flat full hit for anything ≥ min. Reuse the existing `closeness()` decay idiom (as `CompetitorStockMatchService::scoreComparability` already does for comps).

### Scope + blast radius (why this needs a ruling, not a lane build)
This changes the **canonical matching engine** (`matchesForProperty` / `applyHardFilters` / `score`), which feeds **every** buyer surface: Core Matches page, buyer pipeline, MIC, the outreach `{matching_buyer_count}`, and the presentation buyer-demand figure. Tightening the upper bound will **reduce match counts** across all of them. That is the intended effect (defensible figures) but it is a system-wide behavioural change — hence spec-first, Johan rules.

### Setting reaches the Setup Wizard (non-negotiable #10a)
Because this adds agency settings, the build MUST also surface them in the Agency Onboarding Setup Wizard (`config/agency-onboarding-copy.php`) with `explain` + `affects`, per non-negotiable #10a — OR Johan explicitly rules them expert-only and out of the wizard (recorded here).

## 3. Open questions for Johan (rule before build)
1. **Defaults:** ±2 beds, ±2 baths? Or tighter (±1)?
2. **Hard gate vs score-only:** enforce the upper bound as a HARD exclusion (a +tolerance overshoot drops out entirely), or keep it a match but let the score decay carry it below the 50 floor naturally? (Hard gate is cleaner for "defensible count"; score-only is softer.)
3. **Global vs claim-only:** apply engine-wide (all surfaces), or only to the per-property CLAIM figures (outreach + presentation demand, like AT-289's suburb gate) and leave the browse engine's soft behaviour? Recommend **engine-wide** for one honest definition of "a match", but it is the bigger change.
4. **Baths tolerance** — same treatment as beds, or leave baths soft?

## 4. Acceptance criteria (once ruled)
- A buyer wanting ≥3 beds does NOT count/rank as a 100% match for a 7-bed property (drops out or scores low, per the ruling).
- A buyer wanting ≥3 beds still matches 3–5 bed properties (with default +2), scored by closeness.
- Tolerance is agency-configurable; `0` restores today's behaviour.
- Reconciliation invariant preserved (outreach count == canonical) if applied to claim figures.
- Setting surfaced in the Setup Wizard (or ruled out on the record).

## 5. Files (once ruled)
`app/Services/Matching/MatchingService.php` (applyHardFilters upper bound + beds/baths score decay); migration for the two agency columns; `config/agency-onboarding-copy.php`; tests under `tests/Feature/Buyers/`.

**No build until Johan rules on §3.**
