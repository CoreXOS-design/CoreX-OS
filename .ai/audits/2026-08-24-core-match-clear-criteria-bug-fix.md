# Core Match "edit doesn't persist" — REOPENED, real bug found and fixed on staging

**Supersedes the "closed, not reproduced" verdict in
`2026-08-24-core-match-edit-criteria-loss-investigation.md`.** That investigation was real and
correct as far as it went — it proved the edit form loads correctly and that a full, faithful
payload saves correctly. It missed the actual bug because it was hunting for data being
destroyed, not data refusing to be removed.

## The real bug

**Unchecking every item in a criteria group (must-have / nice-to-have / deal-breaker features,
property types, suburbs) and clicking Update silently fails to clear it.** A browser submits
NOTHING for a checkbox/chip group with every item unchecked — unlike a text input, which still
submits an empty string. `$match->update()` correctly leaves an omitted key untouched (this is
right behaviour for a genuine partial submit — see below) — but the full criteria form always
renders all five of these groups, so an absent one means the agent cleared it, not that they
never saw it. The update reports success (302 to the results page) with nothing written.

Five fields have this shape, enumerated from `_match-form.blade.php` itself, not memory:
`property_types`, `p24_suburb_ids`, `must_have_features`, `nice_to_have_features`,
`deal_breakers`.

**Confirmed mechanism directly (before/after DB rows), then confirmed the fix with a real
browser** (Puppeteer, real button clicks, not simulated events) driving three scenarios against
Staging: clear all features → row shows empty arrays; re-select them → row shows them restored;
change price only → every other field byte-identical. All three passed. Also verified the one
genuine partial-submit caller that already posts to this same route — the "Make Primary"
mini-form on `contacts/show.blade.php` (posts only `is_primary`) — still works correctly and
still leaves every other field untouched after the fix.

## Why match 238 (Johan's own reproduction case) looks the way it does

Its `nice_to_have_features` currently holds all 15 possible feature/pool tokens, and
`deal_breakers` overlaps one of them (`granny_flat` in both). No human deliberately selects
every option at once. This is exactly the shape a genuine "select everything, then later try to
clear most of it" sequence produces under this bug: an earlier save persisted the full 15-item
set, a later edit attempting to reduce it down (e.g. leaving only a deal-breaker) submitted an
empty `nice_to_have_features[]` — which vanished from the payload and left the stale 15-item
array in place.

## Fix (staging only — live deploy is Johan's call)

Both ends, deliberately, because either alone is unsafe:
- `_match-form.blade.php` now renders `<input type="hidden" name="criteria_groups_present" value="1">` — present only on the full criteria form, never on a partial submit.
- `ContactMatchController::validatePayload()` and `BuyerDetailController::validateWishlistPayload()` only default a missing group to an explicit `[]` when that marker is present.

**Checked for other partial-submit callers before writing the fix, not after** — a blanket
server-side default would have turned "won't clear" into "wipes everything on every partial
submit." Found one: `contacts/show.blade.php`'s Make Primary form, on `ContactMatchController`'s
route — protected by the marker. Confirmed none on `BuyerDetailController::updateWishlist()`
(single caller, the full form, checked via grep across every view). `MobileCoreMatchController`
is untouched — separate controller, separate route, genuinely different design (`sometimes`
rules, deliberate partial-PATCH semantics for a native client) — not the same bug, not part of
this fix.

## Blast radius

**10 live matches currently show the bug's unambiguous signature** — a feature token present in
more than one of the three mutually-exclusive buckets, a state the validator has always rejected
for a normal simultaneous save. Earliest: match 117, updated 2026-07-07 — **at least 7 weeks**.
IDs: 117, 143, 206, 238, 342, 346, 364, 365, 382, 390.

This count is a **lower bound for features only** — `property_types`/`p24_suburb_ids` failing to
clear leaves no equivalent detectable signature (a stale value that was never touched looks
identical to a stale value that failed to clear); there is no way to distinguish those from data
alone.

The underlying design gap — the update route never distinguished "full form, group genuinely
emptied" from "partial submit, group never touched" — has existed since the wishlist feature's
original build, `d1ba41387`, 2026-05-13 — over three months, not something a recent change
introduced. The 2026-08-04 single-selector-per-feature refactor (`5137beb63`) changed the UI
mechanism for features from three separate checkbox lists to one segmented control; it did not
introduce the vanishing-when-empty behaviour, which was already true of plain unchecked
checkboxes before it.
