# AT-400 — Rental price still wrong in Presentations/CMA subsystem — FIX PLAN (not yet built)

Status: **PLAN ONLY. No code changed.** Awaiting Johan's go-ahead.

## Background

The 2026-09-10 rental-matching fix (`fix/rental-matching-effective-price-2026-09-10`,
landed on QA1 as `577211f46`) routed ~30 price-display consumers through
`Property::effectivePrice()`. Johan found a live miss: `/corex/properties/2517`
(a real rental, `price=0`, `rental_amount=28125`) shows an amber "MODERATE DATA"
panel claiming the property is "missing price" and warning that comparable-sales
accuracy will suffer.

Investigation (read-only, no code changed) traced this to the **Presentations/CMA
subsystem**, which the original sweep never opened — the sweep grepped for `->price`
used in *display* output, not for *presence/emptiness gates* (`isSet`, `empty`,
`!== null`, `> 0` used as a boolean condition). That is a category the original
search method could not have found; it is not a one-off miss on this file.

Seven confirmed-wrong locations below, plus two settled loose ends, plus the two
judgment calls Johan asked for.

---

## The seven fixes

### 1. `app/Support/Presentations/SubjectFieldCompleteness.php:37`

**Current:**
```php
if (!self::isSet((int) ($subject->price ?? 0))) $missing[] = 'price';
```

**Proposed:**
```php
if (!self::isSet((int) $subject->effectivePrice())) $missing[] = 'price';
```

**What changes for a rental:** `effectivePrice()` returns `rental_amount` for a
rental. Property 2517 (rental_amount=28125) now passes `isSet()` — the "missing
price" badge/warning disappears for any rental with a real rent, exactly the case
Johan hit.

**Proof nothing changes for a sale:** `Property::effectivePrice()` is defined as
`isRental() ? (float)(rental_amount ?? 0) : (float)(price ?? 0)`. For a sale,
`isRental()` is false, so this returns exactly `(float)($subject->price ?? 0)`.
`isSet()` internally does `(float) $value > 0` regardless of the caller's cast, so
casting the SAME underlying number as `(int)` first (old) vs. going through
`effectivePrice()`'s `(float)` cast first (new) produces an identical boolean
result for every real value `properties.price` can hold (int column, no
sub-integer precision to lose). No sale-side behaviour change.

---

### 2. `app/Services/Presentations/CompetitorStockMatchService.php:373-376`

**Current:**
```php
$priceSet = $this->isSet((int) ($subject->price ?? 0));
$price    = $priceSet ? (int) $subject->price : null;
$priceMin = $priceSet ? (int) round($price * (1 - $pricePct / 100)) : null;
$priceMax = $priceSet ? (int) round($price * (1 + $pricePct / 100)) : null;
```

**Proposed:**
```php
$priceSet = $this->isSet((int) $subject->effectivePrice());
$price    = $priceSet ? (int) $subject->effectivePrice() : null;
$priceMin = $priceSet ? (int) round($price * (1 - $pricePct / 100)) : null;
$priceMax = $priceSet ? (int) round($price * (1 + $pricePct / 100)) : null;
```

**What changes for a rental:** the shared `ComparableStockCriteria` object (used by
BOTH `findComparableStock()` and `findCompetitors()` — see the interaction note
under fix #4 below) now carries a real rent-based price band instead of nulling it
out. This is what makes fix #3's own-stock comp filtering possible.

**Proof nothing changes for a sale:** identical reasoning to fix #1 —
`effectivePrice()` returns exactly `$subject->price` for a sale. `$priceMin`/
`$priceMax` are computed from the same number via the same formula either way.

---

### 3. `app/Services/Presentations/CompetitorStockMatchService.php:445-446`

**Current:**
```php
->when($criteria->priceMin !== null, fn ($q) => $q->whereBetween('price', [$criteria->priceMin, $criteria->priceMax]))
```

**Proposed:**
```php
->when($criteria->priceMin !== null, fn ($q) => $q->whereRaw(
    '(' . \App\Models\Property::effectivePriceSql('properties') . ') BETWEEN ? AND ?',
    [$criteria->priceMin, $criteria->priceMax]
))
```

**Context:** this is `findComparableStock()`'s own-agency-stock query — candidates
come from `properties` (has `listing_type`/`rental_amount`), already hard-filtered
to `listing_type = $subjectType` a few lines above (line 439-444, "a rental is
never a comp for a sale"). So every candidate reaching this line is already the
same listing type as the subject.

**What changes for a rental:** once fix #2 makes `$criteria->priceMin/Max` a real
rent-based band, this line currently compares that band against candidates' raw
`price` column — 0 on every rental candidate, so **every candidate would be wrongly
excluded** if only fix #2 were applied without this one. Using
`effectivePriceSql()` compares the band against each candidate's own rent instead.

**Proof nothing changes for a sale:** for a `listing_type = 'sale'` (or `NULL`,
per line 442's `orWhereNull`) candidate, `effectivePriceSql()`'s CASE expression
falls to the `ELSE` branch (`properties.price`) — byte-identical to the current
column reference. Verify with a real sale subject + real sale comps before/after
(test plan below) to confirm row-for-row identical results.

---

### 4. `app/Services/Presentations/CompetitorStockMatchService.php:1098-1101` — **NOT a plain effectivePrice() swap**

**Current:**
```php
if (isset($criteria['price_min']) && $criteria['price_min'] !== null) {
    $query->whereBetween('price', [$criteria['price_min'], $criteria['price_max']]);
}
```

This is inside `loadCandidates()`, called from `findCompetitors()` — and `$query`
here is `DB::table('prospecting_listings')` (line ~1063), **not** `properties`.
`prospecting_listings` is the confirmed sales-only scrape schema (no
`listing_type`/rental column at all — same schema already excluded correctly
elsewhere in the original sweep).

**The interaction that matters:** `$criteria` here is the SAME
`ComparableStockCriteria::toArray()` object fixed in #2 above — it is explicitly
documented (its own docblock) as **shared** between `findComparableStock()`
(→ `properties`, can be rental) and `findCompetitors()` (→ `prospecting_listings`,
sales-only, cannot be rental). Fixing #2 alone means a rental subject now produces
a real, non-null rent-based `price_min`/`price_max` — which would then leak into
THIS query and filter `prospecting_listings.price` (real sale prices, e.g.
R1M-R5M) against a rent-sized band (e.g. R22,500-R33,750), silently zeroing every
competitor result for a rental presentation. **That would be a new regression
introduced by fix #2, not fixed by a naive effectivePrice() swap here** — there is
no "effective price" of a prospecting_listings row that makes this comparison
meaningful for a rental subject, because that table has no rental concept to
compare against.

**Proposed:**
```php
$subjectIsRental = ($criteria['subject'] ?? null)?->isRental() ?? false;
if (!$subjectIsRental && isset($criteria['price_min']) && $criteria['price_min'] !== null) {
    $query->whereBetween('price', [$criteria['price_min'], $criteria['price_max']]);
}
```

`$criteria['subject']` is confirmed present (a full `Property` object — see
`ComparableStockCriteria::toArray()` line 64) so this needs no new plumbing.

**What changes for a rental:** the price-band filter is skipped entirely for a
rental subject (consistent with the "soft criterion, absent → skipped, never
filtered on zero" rule this same file's `resolveCriteria()` docblock already
states) — competitor prospecting stock is matched on suburb/family/beds only,
never on price, for a rental. This is a widening (fewer false exclusions), not a
narrowing.

**Proof nothing changes for a sale:** `$subjectIsRental` is `false` for every sale
subject → the `if` condition is identical to the current one, byte for byte.

---

### 5. `app/Services/Presentations/PresentationGeneratorService.php:515-522` — **also not a plain swap**

**Current:**
```php
private function resolveAskingPrice(Property $property, array $options): ?int
{
    if (array_key_exists('asking_price', $options)) {
        $supplied = $options['asking_price'];
        if ($supplied === null || $supplied === '') return null;
        return (int) round((float) $supplied);
    }
    return $property->price !== null ? (int) $property->price : null;
}
```

Note the existing code distinguishes **null** from **0** (`price=0` returns `0`,
not `null`) — `effectivePrice()` collapses both to `0.0` internally, which would
silently change sale behaviour for the edge case of a sale genuinely stored with
`price = 0` (an incomplete/draft listing). To keep the "proof nothing changes for
a sale" airtight, this fix does NOT call `effectivePrice()` — it mirrors the exact
existing null-check shape, branched on `isRental()`:

**Proposed:**
```php
private function resolveAskingPrice(Property $property, array $options): ?int
{
    if (array_key_exists('asking_price', $options)) {
        $supplied = $options['asking_price'];
        if ($supplied === null || $supplied === '') return null;
        return (int) round((float) $supplied);
    }
    if ($property->isRental()) {
        return $property->rental_amount !== null ? (int) $property->rental_amount : null;
    }
    return $property->price !== null ? (int) $property->price : null;
}
```

**What changes for a rental:** `asking_price_inc` on a newly-generated presentation
is now the real rent, not 0/null. This is client-facing — it's what the Pricing
Simulator reads back out for a landlord walkthrough.

**Proof nothing changes for a sale:** the sale branch (`return $property->price
!== null ? ...`) is the ORIGINAL line, completely untouched, reached only when
`isRental()` is false — not a behaviourally-equivalent rewrite, the literal
existing code, unreachable-different for a sale.

---

### 6. `app/Services/Presentations/MicSnapshotHydrator.php:829-838`

**Current:**
```php
private function resolveSubjectAnchorPrice(Presentation $presentation): ?int
{
    $asking = (int) ($presentation->asking_price_inc ?? 0);
    if ($asking > 0) {
        return $asking;
    }

    $listed = (int) ($presentation->property?->price ?? 0);
    return $listed > 0 ? $listed : null;
}
```

**Proposed:**
```php
private function resolveSubjectAnchorPrice(Presentation $presentation): ?int
{
    $asking = (int) ($presentation->asking_price_inc ?? 0);
    if ($asking > 0) {
        return $asking;
    }

    $listed = (int) ($presentation->property?->effectivePrice() ?? 0);
    return $listed > 0 ? $listed : null;
}
```

This one IS a safe direct swap — both branches already collapse to a `> 0` check
(no null-vs-0 distinction to preserve, unlike #5), matching `effectivePrice()`'s
own semantics exactly.

**What changes for a rental:** if `asking_price_inc` is still 0 (an
already-generated bad record — see the count below), the MIC snapshot anchor
falls back to the property's real rent instead of 0, which currently sends
`CompPoolBuilder` to a comp-pool-median fallback (the exact failure mode the
surrounding docblock, lines 810-817, describes for sales — same failure mode
independently confirmed for rentals now).

**Proof nothing changes for a sale:** `effectivePrice()` ≡ `->price` for a sale;
identical `(int)` cast either way.

---

### 7. `app/Http/Resources/WebsiteApi/ListingResource.php:42-43` — **judgment call, see below**

**Current:**
```php
'price'                => $this->price,
'price_display'        => $this->price_on_application ? 'POA' : ($this->price !== null ? 'R ' . number_format((int) $this->price, 0, '.', ',') : null),
'price_on_application' => (bool) $this->price_on_application,
```

`$isRental` is already computed one line above (line 22) for the separate
`rental` block — reused here rather than calling `effectivePrice()`, for a type
reason explained below.

**Proposed:**
```php
$effectivePrice = $isRental ? ($this->rental_amount !== null ? (int) $this->rental_amount : null) : $this->price;

// ... in the returned array:
'price'                => $effectivePrice,
'price_display'        => $this->price_on_application ? 'POA' : ($effectivePrice !== null ? 'R ' . number_format((int) $effectivePrice, 0, '.', ',') : null),
'price_on_application' => (bool) $this->price_on_application,
```

**Why not `effectivePrice()`:** `Property::$casts` has `price` as `integer` and
`rental_amount` as `float`. Blind `effectivePrice()` returns a `float` always —
calling it here would silently change the JSON type of `price` from an integer to
a float for every rental (e.g. `28125` → `28125.0`), a wire-format change a strict
JS consumer could notice even though the underlying value is now correct. The
explicit `(int)` cast on `rental_amount` keeps `price` an int-or-null in both
branches, exactly matching the field's existing contract.

**Proof nothing changes for a sale:** `$effectivePrice = $this->price` verbatim
when `$isRental` is false — the sale branch is the untouched original expression.

---

## Judgment call #1 — is this a public API *shape* or *value* change?

**Shape: unchanged.** No keys added, removed, or renamed. `price` stays
int-or-null, `price_display` stays string-or-null, `price_on_application` stays
bool. The separate `rental.rental_amount` block (line 58) is untouched.

**Value: changes for rentals, and this needs a website-side check I cannot do
from this repo.** Today, every rental on the agency's public website is emitting
`price: 0` (or `null`) and (depending on `price_on_application`) either "POA" or
`null` for `price_display`. After this fix, `price` will carry the real rent and
`price_display` will read e.g. "R 12,500". **Flagging loudly, per your ask:** if
the live public website's frontend currently has ANY special-case logic keyed off
a rental's `price` being falsy (e.g. "always show POA for rentals", "hide price
badge when price is 0"), that logic will start rendering differently the moment
this ships — because the value it was branching on was always wrong, and will now
be right. This is not a case this repo can verify; it needs a check against
whatever renders `themandatecompany.co.za` (or the live agency site) before this
one ships, or at minimum a heads-up to whoever owns that frontend.

## Judgment call #2 — already-stored bad presentation records

**Checked QA1, read-only, right now: zero.**
```
total presentations: 159
presentations with property_id set: 73
presentations whose property listing_type is rental (raw SQL join, bypassing any
Eloquent relation ambiguity): 0
```
No rental has ever had a presentation generated on QA1 to date, so there is
nothing to backfill there. **This says nothing about Staging or live** — I have
no visibility into those databases from this session, and cannot claim the same
is true there without checking.

If a live/Staging presentation is ever found with `property.listing_type` in
rental/to_let/to-let/lease AND `asking_price_inc <= 0`: my proposed companion (not
built, your call) would be a one-off, idempotent backfill command —
`UPDATE presentations SET asking_price_inc = (SELECT rental_amount FROM
properties WHERE properties.id = presentations.property_id) WHERE asking_price_inc
<= 0 AND property is a rental` — run once, logged, never automatic, and only
after fix #5 is live so no new bad record can be created in the same window. I
would NOT touch this without your explicit go, and would recheck the count on
whichever environment is in play before writing it.

---

## Loose ends — settled, not open questions

### `PresentationReviewController.php:944` — wording, not a gate

```php
$criteria = $service->buildCriteria($subject);
if ($criteria === null) {
    return response()->json([
        'error'   => 'subject_not_pickable',
        'message' => 'Subject has no price/suburb or is not residential — manual picker unavailable.',
    ], 422);
}
```

Traced `buildCriteria()` → `resolveCriteria()` (the same function fixed in #2).
Its ONLY null-return conditions (lines 358-365) are: `!$subject->agency_id`,
`!$subject->suburb`, or an unresolvable property-type family. **Price is never
part of this gate** — per the function's own docblock, price is explicitly a SOFT
criterion that gets skipped when absent, never a hard blocker. The message is
stale/inaccurate wording (mentions "no price" for a gate that doesn't check
price), not a functional bug, and not something this fix's scope touches. Worth a
one-line copy fix separately if you want it, but it does not affect correctness —
the manual picker already works correctly for a rental with no price, contrary to
what this message implies.

### `resources/views/presentations/brain.blade.php:705,781` — confirmed dead

The only route to this view (`GET /{presentation}/brain` →
`PresentationController::brain()`) unconditionally redirects to
`presentations.pricing-simulator` without ever calling `view('presentations.brain',
...)`. Grepped the entire `resources/views/` tree and `app/` for any `@include`,
`@extends`, or `view()` call naming this file — none found. The file is
unreachable by any code path. Confirmed dead, not part of this fix.

---

## Test plan

New file: `tests/Feature/Presentations/RentalEffectivePricePresentationsTest.php`
(exact same fixture pattern as the already-landed
`RentalEffectivePriceMatchingTest.php` — a throwaway Agency/Branch/Agent/Contact +
a rental Property with `price=0, rental_amount=<real number>` + a sale Property
with `price=<real number>` for the parity guard).

Proposed cases:

1. **`SubjectFieldCompleteness::missingSoftInputs()` no longer flags a priced
   rental.** Assert `'price'` is NOT in the returned array for a rental with
   `rental_amount` set; still IS in the array for a rental with `rental_amount`
   null/0 (the check must still catch a genuinely incomplete rental — this isn't
   about disabling the warning, only about reading the right field).

2. **`CompetitorStockMatchService::buildCriteria()` produces a rent-based band for
   a rental subject.** Assert `price_min`/`price_max` bracket the rental's
   `rental_amount`, not `price`.

3. **`findComparableStock()` returns a same-agency rental comp within the rent
   band, excludes one outside it.** Two rental Properties in the same
   suburb/family, one within ±tolerance of the subject's rent, one outside — assert
   the in-band one is returned, the out-of-band one is not. This is the test that
   would have caught fix #3 being missing (comps wrongly zeroed) or fix #2 being
   applied without #3 (comps wrongly matched on raw price=0).

4. **`findCompetitors()` does not zero out on a rental subject.** A rental
   subject with a `prospecting_listings` row present in the same suburb/family
   (sale-priced, as that schema always is) — assert the prospecting row is still
   returned (proves fix #4's skip-the-filter-for-rentals branch works; without
   it, this test fails because every prospecting row gets wrongly excluded by a
   rent-sized band).

5. **`PresentationGeneratorService::resolveAskingPrice()` (via the public
   `generateFor()`/`store()` path, whichever is the real entry point — confirm
   exact method name before writing) returns the rental's rent when no explicit
   `asking_price` option is supplied.** Generate a presentation for a rental
   property with no override; assert the stored `asking_price_inc` equals
   `rental_amount`.

6. **`resolveAskingPrice()` sale-parity, including the `price=0` edge case.**
   Explicit case: a sale Property with `price = 0` (not null) — assert
   `resolveAskingPrice()` still returns `0`, not `null`. This is the exact edge
   case the plan above identifies as the reason NOT to use `effectivePrice()`
   directly in fix #5; a test that would fail if that shortcut were taken instead.

7. **`MicSnapshotHydrator::resolveSubjectAnchorPrice()` falls back to rent when
   `asking_price_inc` is 0.** Presentation with `asking_price_inc = 0` on a
   rental property with `rental_amount` set — assert the returned anchor equals
   the rental amount, not null and not a comp-pool median.

8. **`ListingResource` public API — type and value.** Assert, for a rental:
   `price` is an `int` (not float) equal to `rental_amount`; `price_display` is
   `"R " . number_format(rental_amount)`. For a sale with `price = 0`: assert
   `price` is still `int` `0` (not null), proving the type-shape guard in fix #7
   holds for the sale edge case too.

**Sale-parity, specifically, across all seven:** every test above pairs a rental
assertion with a sale assertion on the SAME test method or an adjacent one in the
same file, using a sale Property fixture with a realistic non-zero price AND
(cases 6 and 8) an explicit `price = 0` edge-case fixture — because "sale
unaffected" has to be proven at the boundary condition, not just the common case.
Additionally, before merging: re-run the exact before/after real-data comparison
already used for the matching fix — a real sale-subject presentation's
`buildCriteria()`/`findComparableStock()` output compared byte-for-byte against
the current QA1 code, using Tinker against the same real property/presentation
IDs, both before and after. If they differ at all for a sale, stop, per the same
rule that governed the matching fix.

---

## What this plan does NOT cover (explicitly out of scope, flagging rather than fixing)

- `PresentationReviewController.php:944`'s stale wording — cosmetic, not a
  correctness bug, not touched.
- `resources/views/presentations/brain.blade.php` — dead code, not touched.
- `CompetitorStockMatchService`'s own private `isSet()` (line 55) duplicates
  `SubjectFieldCompleteness::isSet()` rather than delegating to it — a pre-existing
  duplication risk (two copies of the same rule, the exact class of problem
  `SubjectFieldCompleteness`'s own docblock says it was created to prevent) that
  is not part of this bug and not proposed for consolidation here without your
  separate say-so.
