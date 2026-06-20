# Audit — Mandate expiry does not de-advertise the property

**Date:** 2026-06-20
**Author:** Andre (Claude, Opus 4.8)
**Trigger:** Johan flagged a Core-Matches investigation that surfaced an unrelated, more serious issue: when a mandate expires, the property does not reliably come off advertising across Property24, Private Property, and the agency website feed.
**Severity:** HIGH — legal/compliance exposure (advertising a property without a valid mandate). PPRA / Property Practitioners Act 22 of 2019.

**STATUS: RESOLVED (2026-06-20).** Shipped:
- `App\Listeners\Mandate\DesyndicateExpiredMandate` (discovery-wired on `MandateExpired`) → dispatches
- `App\Jobs\Mandate\DesyndicateExpiredMandateJob` (queued, 3 tries + backoff, failure-isolated per portal): delists P24 (`deactivateListing`), Private Property (`deactivateListing`), and disables every enabled website pivot (`WebsiteSyndicationService::setEnabled(false)` → `listing.removed` webhook).
- `ListingsController` index + show now exclude `status = 'expired'` (NULL-safe) — belt-and-braces so an expired listing is never served even if a pivot was missed.
- **Related CRITICAL fixed in the same pass:** `PropertyObserver::saved()` used `getDirty()` for its P24 auto-sync, which is always empty there (a nested `updateQuietly` in `onPropertyUpdated` syncs original first). Changed to `getChanges()` — this had silently killed ALL P24 status/field auto-sync (sold/withdrawn/price/photo edits never reached P24). See the sweep audit below.
- Tests: `tests/Feature/Mandate/MandateExpiryDesyndicationTest.php`, `tests/Feature/Syndication/Property24ObserverStatusSyncTest.php` (all green).
- **Still open (reported, not yet built):** Private Property and the agency website have NO status-driven delist for *manual* off-market transitions (sold/withdrawn) — only mandate-expiry delists them now. P24 manual sold/withdrawn now works via the getChanges fix. See `.ai/audits/syndication-bug-sweep-2026-06-20.md` finding group.

---

## TL;DR

A property's mandate expiry is detected daily by `mandates:expire` (01:00 cron), which sets
`properties.status = 'expired'` and fires `Mandate\MandateExpired`. **That event does
nothing for de-syndication** — its only listener writes a log line. De-listing, where it
happens at all, is an accidental side effect of the `status` column change in
`PropertyObserver::saved()`, not a designed delist flow.

Result per portal:

| Portal | On mandate expiry | Stays advertised? |
|--------|-------------------|-------------------|
| **Property24** | Status flipped to `Expired` via observer side-effect — **only if** `p24_syndication_enabled && p24_ref` are set; up to ~24h lag (daily cron) | Comes off (Expired is terminal on P24), *if it was syndicated* |
| **Private Property** | **Nothing happens at all** | **YES — indefinitely** |
| **Agency website feed** | Fires a `listing.updated` webhook (not `removed`); feed keeps serving it | **YES — indefinitely** |

The two genuinely broken portals are **Private Property** and the **agency website**.

---

## Evidence

### 1. The expiry command — [app/Console/Commands/ExpireMandates.php](../../app/Console/Commands/ExpireMandates.php)

Scheduled daily at 01:00 ([routes/console.php:236](../../routes/console.php#L236)). For each
expired stock property it sets `status = 'expired'` and fires `MandateExpired`. No portal
delist call anywhere in the command.

### 2. The event is inert for delisting — [app/Providers/AppServiceProvider.php:389](../../app/Providers/AppServiceProvider.php#L389)

`MandateExpired` is wired to exactly one listener:

```php
\App\Events\Mandate\MandateExpired::class => \App\Listeners\Mandate\LogMandateEvent::class,
```

`LogMandateEvent` writes a structured log line — nothing else. There is **no delist
listener**. (Confirmed: `grep MandateExpired` returns only the event, the command, the
log-listener wiring, and the spec.)

### 3. Property24 — accidental, partial, lagged — [app/Observers/PropertyObserver.php:348-377](../../app/Observers/PropertyObserver.php#L348-L377)

P24 only reacts because `status` is dirty inside `PropertyObserver::saved()`:

```php
if (!$property->p24_syndication_enabled || !$property->p24_ref) {
    return;                       // ← never syndicated → no action
}
$dirty = $property->getDirty();
if (isset($dirty['status'])) {
    $p24Status = Property24ListingMapper::getP24Status($property->status, $property->p24_ref);
    $client->setListingStatus($property->id, (int) $property->p24_ref, $p24Status);  // 'expired' → 'Expired'
    ...
}
```

`getP24Status()` maps `expired` → `Expired`, and `isTerminalStatus()` treats `Expired` as
off-market ([Property24ListingMapper.php:652,667](../../app/Services/Syndication/Property24/Property24ListingMapper.php#L652)).
So P24 **does** come off — but:
- only when the property was actually syndicated to P24 (`p24_ref` present),
- up to ~24h after the expiry date (cron is daily),
- as `Expired` in place, not `Withdrawn`/removed.

P24 is the **least** broken of the three.

### 4. Private Property — never de-syndicated — completely broken

- `PropertyObserver::saved()` has **no PP branch at all.** PP syndication is not driven by
  the observer, so the `status='expired'` save triggers nothing for PP.
- A working `deactivateListing()` exists
  ([PrivatePropertySyndicationService.php:189](../../app/Services/PrivateProperty/PrivatePropertySyndicationService.php#L189)
  → SOAP `deactivateListing`) but is **only reachable manually** via the PP controller. The
  expiry path never calls it.
- The listing mapper **hardcodes** the live status and never reads `property->status`
  ([PrivatePropertyListingMapper.php:31](../../app/Services/PrivateProperty/PrivatePropertyListingMapper.php#L31)):

  ```php
  $status = $listingType === 'Rental' ? 'ToLet' : 'ForSale';
  ```

  So even a re-submit would re-advertise it as live.

**Net: an expired-mandate property stays live on Private Property forever.**

### 5. Agency website feed — keeps serving it — broken

- The public feed query has **no status filter** — it returns any property whose
  per-website pivot is `enabled=true`, regardless of `status`
  ([ListingsController.php:25-28](../../app/Http/Controllers/Api/V1/Website/ListingsController.php#L25-L28)):

  ```php
  Property::query()
      ->whereHas('websiteSyndication', fn ($q) => $q->where('agency_api_key_id', $keyId)->where('enabled', true))
  ```

  Nothing disables the pivot on expiry, so the expired listing keeps being served.
- The expiry save *does* fire a webhook — but with action `updated`, not `removed`
  ([PropertyObserver.php:314-333](../../app/Observers/PropertyObserver.php#L314-L333)) —
  because `status` is in `$websiteSignals`. That just tells the site to re-pull the
  (still-served, still-"live") listing.
- The resource exposes `status` ([ListingResource.php:40](../../app/Http/Resources/WebsiteApi/ListingResource.php#L40)),
  so a downstream site *could* choose to hide `status == 'expired'` — but CoreX must not
  rely on every consumer to filter. CoreX is still publishing it.

**Net: an expired-mandate property stays live on the agency website(s) indefinitely.**

### 6. No reconciliation job closes the gap

The scheduled PP/P24 jobs (`ProcessPrivatePropertyEventFeed`, `SyncPrivatePropertyActivations`,
`SyncProperty24Activations`, `PullP24LeadsJob`) are all **inbound** (portal → CoreX). None
push a delist based on `property.status`. There is no safety-net sweep.

---

## Root cause

De-syndication on mandate expiry was never built as a first-class flow. It was left to ride
on the `status`-dirty side effect in `PropertyObserver`, which only ever covered P24 — and
only partially. Per CLAUDE.md non-negotiable #9, cross-pillar reactivity (Mandate →
syndication portals) must be driven by a named domain-event listener, not ad-hoc observer
hooks. The event exists (`MandateExpired`); the listener that acts on it is missing.

## Recommended fix (spec-first — needs a spec before code)

Add a single failure-isolated listener on `MandateExpired` — e.g.
`App\Listeners\Mandate\DesyndicateExpiredMandate` — that, for the expired property:

1. **P24** — call `Property24SyndicationService::deactivateListing()` (Withdrawn) when
   `p24_ref` is present, instead of relying on the observer's in-place `Expired` flip. This
   also removes the ~24h-only-if-status-dirty fragility.
2. **Private Property** — call `PrivatePropertySyndicationService::deactivateListing()` when
   the property has a PP ref / `pp_syndication_status` indicating it was live.
3. **Agency website** — for every enabled `property_website_syndication` row, call
   `WebsiteSyndicationService::setEnabled($property, $key, false)` so the pivot flips to
   deactivated AND a `listing.removed` webhook fans out. (Belt-and-braces: also exclude
   `status = 'expired'` from the `ListingsController` feed query so a missed webhook can't
   keep an expired listing live.)

Each step must be try/catch-isolated so one portal failure doesn't block the others, and
each should log to its portal channel. Mirror the existing `PropertyObserver::deleted()` P24
withdrawal pattern. Consider the same listener (or a sibling) for `MandateConverted` /
withdrawal/sold transitions so every off-market reason delists consistently.

**Also worth confirming with Johan:** whether expiry should *withdraw* (removable, can come
back) vs *expire* (terminal) on each portal — affects the P24 status verb and whether the
website pivot is disabled vs deleted.

## Files involved

- [app/Console/Commands/ExpireMandates.php](../../app/Console/Commands/ExpireMandates.php)
- [app/Events/Mandate/MandateExpired.php](../../app/Events/Mandate/MandateExpired.php)
- [app/Providers/AppServiceProvider.php](../../app/Providers/AppServiceProvider.php) (listener wiring ~L389)
- [app/Observers/PropertyObserver.php](../../app/Observers/PropertyObserver.php) (P24 status side-effect L348-377; website webhook L314-333)
- [app/Services/Syndication/Property24/Property24SyndicationService.php](../../app/Services/Syndication/Property24/Property24SyndicationService.php) (`deactivateListing` L185)
- [app/Services/PrivateProperty/PrivatePropertySyndicationService.php](../../app/Services/PrivateProperty/PrivatePropertySyndicationService.php) (`deactivateListing` L189)
- [app/Services/PrivateProperty/PrivatePropertyListingMapper.php](../../app/Services/PrivateProperty/PrivatePropertyListingMapper.php) (hardcoded status L31)
- [app/Services/Syndication/Website/WebsiteSyndicationService.php](../../app/Services/Syndication/Website/WebsiteSyndicationService.php) (`setEnabled` L32)
- [app/Http/Controllers/Api/V1/Website/ListingsController.php](../../app/Http/Controllers/Api/V1/Website/ListingsController.php) (no status filter L25-28)
