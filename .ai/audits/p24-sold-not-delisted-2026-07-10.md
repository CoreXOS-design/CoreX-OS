# P24: "deactivated" listings that were never off the portal

**Date:** 2026-07-10
**Trigger:** Property #2142 (`corexos.co.za/corex/properties/2142`) was marked deactivated in CoreX
so it would come off Property24 and Private Property. It stayed live on P24.
**Class:** local syndication state conflated "P24 accepted a terminal status" with
"the listing left the portal". Fix the class, not the instance (BUILD_STANDARD §6).

---

## What was wrong

`p24_syndication_status = 'deactivated'` is the value every delist path reads as
*"already off the portal — skip"*. Three paths trusted it:

| Path | File | Old guard |
|------|------|-----------|
| Syndication toggle (P24) | `P24SyndicationController::toggle()` | delist only if status ∈ `['submitted','active']` |
| Syndication toggle (PP) | `PrivateProperty\SyndicationController::toggle()` | delist only if status ∈ `['submitted','active']` |
| Off-market safety net | `DesyndicatePropertyFromPortalsJob` | delist only if status ∈ `['active','submitted','pending','error']` |

But `PropertyObserver::saved()` wrote `'deactivated'` for **any** terminal P24 status:

```php
if (Property24ListingMapper::isTerminalStatus($p24Status)) {   // Sold, Rented, Withdrawn, Expired, Cancelled
    $property->updateQuietly(['p24_syndication_status' => 'deactivated']);
}
```

**`Sold` and `Rented` do not remove a listing from Property24.** P24 keeps them on the
portal as sold/rented stock. Only `Withdrawn`, `Expired` and `Cancelled` remove it.

So the moment a property was marked Sold, CoreX recorded it as off the portal while P24
kept showing it — and from then on *nothing could delist it*, because every delist guard
short-circuited on the very value the Sold push had written.

## Timeline for #2142 (from `p24_syndication_logs`, nginx access log, `property_audit_log`)

| When | What |
|------|------|
| 07-06 09:36:36 | Agent marks it **Sold**. Observer pushes `listingStatus=Sold` (HTTP 200) and writes `p24_syndication_status='deactivated'`. Listing stays on P24. |
| 07-06 09:38:14 | PP delisted for real: SOAP `ListingStatusUpdate → Inactive`, PP replies `Successful`; PP's event feed echoes `Deactivated` for `T5539358`. |
| 07-10 11:14:05 | Agent hits `POST /p24-syndication/toggle` to switch P24 off. Status is `'deactivated'`, not in `['submitted','active']` → **delist silently skipped**; only `p24_syndication_enabled = 0` is written. |
| 07-10 11:14:39 | Status → `under_offer`. Observer returns at the `p24_syndication_enabled` guard. Nothing pushed. |
| 07-10 11:29:50 | Status → `withdrawn`. Same guard. Nothing pushed. The desync job isn't even dispatched: `$onPortal` only considered PP + websites, and PP had been toggled off at 11:14:03. |
| 07-10 11:30:01/07 | Agent toggles PP and P24 back on. Flags on, status still `'deactivated'`, listing still live. |

Confirmed at diagnosis time: P24 `GET /listings/116692348/is-on-portal` → `true`.
The only status P24 ever received for this listing was `Sold`.

## Blast radius (measured 2026-07-10)

69 properties had a `p24_ref` and `p24_syndication_status = 'deactivated'`.
Reconciling against P24's `is-on-portal`:

- **65** genuinely off the portal — column was correct.
- **3** sold listings still on the portal (#1501, #1917, #1972) — legitimately listed as
  sold; column was lying, now `'sold'`.
- **1** (#2142) live on the portal but should not have been — withdrawn 2026-07-10 13:51:30
  (HTTP 200); `is-on-portal` now `false`.

The small live count is luck, not design: most of the 69 were delisted by a `Withdrawn`
push that happened *before* any Sold push set the poisoned value.

## The fix

1. `Property24ListingMapper::removesFromPortal()` — new predicate, `['Withdrawn','Expired','Cancelled']`.
   `isTerminalStatus()` keeps its meaning (terminal *market* state) and is no longer used
   to decide portal presence.
2. `PropertyObserver::saved()` — writes `'deactivated'` only for portal-removing statuses.
   Sold/Rented record their own on-portal lifecycle state (`'sold'` / `'rented'`), so the
   row stays delistable and the model scopes that key off `'active'` don't count sold stock
   as advertised.
3. `Property::mayBeLiveOnP24()` / `mayBeLiveOnPp()` — one predicate for "the portal may still
   be showing this": we hold a ref and nothing told us it left. Deliberately **not** gated on
   `*_syndication_enabled`, because a listing toggled off while live is exactly this bug.
4. Both toggle controllers and both desync-job guards now use that predicate instead of a
   status whitelist. This also closes two silent leaks the whitelists had: `'pending'` and
   `'error'` listings were never delisted on toggle-off either.
5. `Property::buildP24Url()` — sold/rented listings have a real portal page, so they keep
   their link.
6. `php artisan p24:reconcile-portal-presence` — repairs drifted rows against P24's
   `is-on-portal`, with `--withdraw` to push the delist that never went out. Idempotent.

## Invariant to hold

> `p24_syndication_status = 'deactivated'` means, and only ever means, **P24 is not showing
> this listing**. Nothing may write it without a delist that P24 acknowledged.

Regression cover: `tests/Feature/Syndication/Property24ObserverStatusSyncTest.php`
(sold push must not mark off-portal; withdrawn push must; a sold-but-listed property still
dispatches the desync job when it later goes off-market).

## Not explained by this audit

The agent also reported #2142 still showing on **Private Property**. CoreX's evidence says PP
was genuinely delisted on 07-06 (PP acknowledged, and PP's own event feed reported
`Deactivated`). If it is still visible on PP, the cause is on PP's side or in the public
listing's cache — not in CoreX's outbound path. Needs a live eyeball on the PP listing.
