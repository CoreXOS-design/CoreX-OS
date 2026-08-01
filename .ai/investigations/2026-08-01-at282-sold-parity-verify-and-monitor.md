# AT-282 — PP `sold → Sold` parity: how to verify on live + what to monitor

> **Date:** 2026-08-01 · **Ruling (Johan):** go to **full parity — `sold → Sold`** on Private Property
> (NOT `Inactive`). PP now hears **both** under-offer (`PendingOffer`, already built in `bbcc07d5`) **and**
> sold (`Sold`, this change). **QA1 blanks PP outbound**, so the real PP round-trip can only be confirmed on
> **Staging / live** — this note is the go-live verification + monitoring plan.

## What changed (3 coordinated edits)
1. `PrivatePropertyListingMapper::statusFor()` — `SOLD → 'Sold'` (was `Inactive`). Under-offer/others unchanged.
2. `DesyndicatePropertyFromPortalsJob` — new `keepPpForSold` flag + guard: a plain **sold** status change no
   longer PP-delists (else the `Sold` push would be overwritten with `Inactive`). **Not a one-line switch** —
   `Property::OFF_MARKET_STATUSES` includes `sold`, so the off-market delist path *did* fire on sold and would
   have removed the listing. Mandate-expiry / manual delist still pass the default (`false`) → they still
   remove PP even for a sold listing.
3. `PropertyObserver::saved()` off-market dispatch — passes `keepPpForSold: true`. Coordinated inside the same
   block as the AT-282 PP status dispatch (`SyncPpListingStatusJob`) and the P24 fan-out; neither dropped, and
   Andre's AT-271 refresh trigger is untouched.

## The end-to-end sold flow (after this change)
`properties.status = sold` → `PropertyObserver::saved()` fires two queued jobs:
- `SyncPpListingStatusJob` → `statusFor()='Sold'` → `PrivatePropertySoapClient::setListingStatus(..., 'Sold')`
  (SOAP `ListingStatusUpdate`) → **`verifyStatus()` reads PP back and only records success on a match**
  (`pp_syndication_status='active'`, i.e. still ON the portal — `Sold` is not written as `PORTAL_OFF_STATUS`).
- `DesyndicatePropertyFromPortalsJob(keepPpForSold: true)` → PP delist **skipped** for sold (P24 safety-net +
  website showcase logic unchanged).

## HOW TO VERIFY ON LIVE (post go-live)
Pick a **real sold** listing that is `pp_syndication_enabled` with a `pp_ref`, then:
1. **DB truth (read-only):** the property row should show `pp_syndication_status = 'active'` and
   `pp_last_error IS NULL` after the sold transition (an *unverified* push writes `'error'` + `pp_last_error`,
   never a false "done"). Check `pp_listing_last_synced_at` is recent.
   ```sql
   SELECT id, status, status_label, pp_ref, pp_syndication_status, pp_last_error, pp_listing_last_synced_at
   FROM properties WHERE id = :soldPropertyId;   -- expect pp_syndication_status='active', pp_last_error NULL
   ```
2. **PP's own answer (authoritative):** call the read-back the code uses —
   `PrivatePropertySoapClient::getListingStatus(pp_ref)` (or `php artisan pp:manage status <id>` if wired) —
   and confirm PP reports the listing as **Sold** (space-insensitive; PP may read `Sold`). `verifyStatus()`
   already does this compare; a mismatch would have flipped `pp_syndication_status` to `'error'`.
3. **On the PP portal UI:** the listing is **still visible, flagged Sold** (not gone). This is the whole point
   of the ruling vs the old `Inactive` (removed) behaviour.
4. **Under-offer regression (same trip):** flag a live listing under-offer and confirm PP shows `PendingOffer`
   (the pre-existing half) — proves the two-tier resolve still reaches PP.
5. **Logs:** `Log::channel('private_property')` should carry `PP status synced for property #<id>: Sold
   (verified)`; the `property24` channel should carry the parallel `Sold` for the same property.

## WHAT TO MONITOR after go-live
- **`properties.pp_syndication_status = 'error'` + `pp_last_error`** rising for sold/under-offer transitions →
  PP received but did not apply the status (AT-221 class), or rejects `'Sold'` on `ListingStatusUpdate`. This
  is the single most important signal — if PP does **not** accept `Sold`, `verifyStatus()` records `error`
  (no false success) and we revert `statusFor()` to `Inactive` for sold. **Watch this first.**
- **A sold listing that DISAPPEARS from PP** (delisted) → the `keepPpForSold` guard didn't hold, or another
  delist caller (mandate-expiry) fired. Confirm via `pp_syndication_status` (`deactivated` = removed).
- **`private_property` log channel** — repeated `Off-market PP delist … for a sold property`, or
  `did not apply it` on a `Sold` push.
- **Reconcile/parity spot-check** (weekly, first month): sample recent sold + under-offer properties and diff
  CoreX lifecycle vs PP `getListingStatus` — they must agree (`sold↔Sold`, `under_offer↔PendingOffer`,
  withdrawn/expired↔Inactive/removed).
- **P24 parity unchanged** — sold still reads `Sold` on P24 (this change touches PP only; regression-watch P24
  isn't expected but confirm once).

## Rollback (if PP rejects `Sold`)
One-line revert: `statusFor()` `SOLD → 'Inactive'` (back to the cautious de-list). The `keepPpForSold` guard
then becomes inert (a sold listing maps to `Inactive` and the delist proceeds) — safe to leave or revert too.

*(Proven on QA1: `statusFor(sold)='Sold'`, `under_offer='PendingOffer'`, withdrawn/expired/rented='Inactive';
the delist guard skips PP for sold only. Real PP acceptance of `Sold` is unverifiable on QA1 — Staging/live.)*
