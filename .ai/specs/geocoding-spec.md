# Geocoding — spec

> Phase 11a — rate limiter, persistent cache with TTL, SA sectional-title
> address parser, and operator-triggered backfill for tracked properties.
> Last updated: 2026-05-25.

---

## Why this exists

Geocoding is the bridge between addresses CoreX captures (CMA presentations,
P24/PP alerts, Chrome captures, mandate signings, manual contact entries) and
the GPS coordinates every downstream feature relies on — map tiles, Buyer
Match radius queries, MIC subject-locator, Prospecting suburb scoping. Google
Geocoding API costs $5 per 1,000 requests and is rate-limited. Nominatim is
free but throttled to 1 req/sec by OSM acceptable use policy.

Before Phase 11a:
- Every cache miss went straight to Google with no daily ceiling. A single
  bulk import could burn 10k+ requests in minutes.
- The cache had no TTL, so a row that failed in February (e.g. because the
  normaliser couldn't handle the SS pattern) stayed cached as "failed"
  forever, even after the bug was fixed.
- SA sectional-title addresses ("36 Ss Topanga, 2587 Colin Road") were
  treated as 36-unit-different locations rather than one shared building.

Phase 11a fixes all three. It also adds a `geocode_needs_review` flag on
`tracked_properties` so the Prospecting UI can surface suburb-only matches
to a human for manual correction.

---

## Pillar connections

| Pillar | Reads | Writes |
|--------|-------|--------|
| Property (Tracked) | `tracked_properties.street_*`, `complex_name`, `suburb` | `latitude`, `longitude`, `geo_source`, `geo_confidence`, `geo_resolved_at`, `geocode_needs_review` |
| Property (Stock)   | `properties.address`, `suburb`, `town` | `latitude`, `longitude`, `geo_*` (via existing Phase 3f service) |

No direct Contact / Deal / Agent involvement.

---

## Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│ Caller (Property model save event, importer, manual command)      │
└────────────────────────────┬─────────────────────────────────────┘
                             │
                             ▼
            ┌────────────────────────────────────┐
            │  AddressResolverService::resolve() │  (Phase 3f, unchanged
            │  ── waterfall ──                   │   waterfall)
            └────────────────────────────────────┘
                             │
   1. GeocodingCache (DB)  ──┤
   2. market_reports       ──┤
   3. portal_captures      ──┤
   4. Google ────────────────┤── GeocodeRateLimiter::canGeocode()  ◀── Phase 11a
   5. Nominatim ─────────────┤── GeocodeRateLimiter::canGeocode()  ◀── Phase 11a
   6. cacheAndReturn (failed)┘
```

### Components added in Phase 11a

| File | Purpose |
|------|---------|
| `config/geo.php` | Cap, TTL, override config values |
| `app/Services/Geocoding/GeocodeRateLimiter.php` | Daily counters keyed by SAST day; env + per-user caps |
| `app/Services/Geocoding/GeocodeRateLimitException.php` | Raised by `assertCanGeocode()` for fail-loud callers |
| `app/Services/Geocoding/GeocodeCache.php` | Thin wrapper over `GeocodingCache` model — exposes `get/put/putMiss/purgeExpired` with TTL + hit tracking |
| `app/Support/Geocoding/AddressNormaliser::parse()` | Structured parse of SA addresses including SS pattern |
| `app/Console/Commands/GeoCachePurgeCommand.php` | Daily scheduled hard-delete of expired rows |
| `app/Console/Commands/GeoBackfillTrackedPropertiesCommand.php` | Operator-triggered bulk resolution, bypasses cap |
| `database/migrations/2026_06_07_080001_extend_geocoding_cache_phase11a.php` | Adds `hit_count`, `last_hit_at`, `expires_at`, `google_location_type` to `geocoding_cache`; adds `geocode_needs_review` to `tracked_properties` |

---

## Rate limiter

Two counters, both keyed by SAST calendar day, stored in the Laravel cache
(no new driver, no new package):

```
geocode_counter:{env}:{YYYY-MM-DD}                    (env-wide)
geocode_counter:{env}:{YYYY-MM-DD}:user:{id}          (per-user)
```

Caps come from `config/geo.php`, sourced from `.env`:

```
GEOCODING_ENABLED=true
GEOCODING_ENV_DAILY_CAP=100      # env-wide ceiling
GEOCODING_USER_DAILY_CAP=30      # per logged-in user
GEOCODING_ADMIN_OVERRIDE=false   # operator backfills set this at runtime
```

### Why split env + user

If we had only an env cap, a single runaway agent could starve every other
agent of geocoding for the rest of the day. The per-user cap is the bound;
the env cap is the safety net.

### Cap behaviour

- `canGeocode()` returns `false` when **either** cap is reached.
- `assertCanGeocode()` throws `GeocodeRateLimitException` instead — used at
  Resolver call sites that want to fail loud.
- `recordCall()` is called **after** the upstream HTTP call returns (success
  or failure). Cache hits do NOT consume quota.
- TTL on the counter cache key = seconds-until-end-of-day-SAST, so the
  counter naturally resets at midnight without a cron.

### Admin override

Two routes to override the cap:

1. **Config flag** `GEOCODING_ADMIN_OVERRIDE=true` — permanent for the env.
   Only ever used in dev/staging, never in production.
2. **Runtime override** — `GeocodeRateLimiter::engageRuntimeOverride()` /
   `releaseRuntimeOverride()` engages a static flag for the current PHP
   process. The backfill command wraps its loop in this so an operator can
   resolve thousands of rows in one shot without the cap blocking. Always
   released in `finally`.

Even under override the counter is still incremented — the cap just doesn't
apply. This means the env counter reflects actual API spend, not
"after-cap" spend.

---

## Cache (`GeocodeCache`)

Wraps the existing `geocoding_cache` table (54 production rows preserved).
Schema extensions:

| Column | Purpose |
|--------|---------|
| `hit_count` (unsigned int) | Incremented every read hit |
| `last_hit_at` (timestamp) | Stamp of most recent hit |
| `expires_at` (timestamp, indexed) | TTL boundary; `purgeExpired()` targets rows past this |
| `google_location_type` (string 30) | Raw Google `ROOFTOP` / `RANGE_INTERPOLATED` / `GEOMETRIC_CENTER` / `APPROXIMATE` value (parallel to existing `confidence` enum which uses our normalised `exact` / `street` / `suburb` / `failed`) |

### TTLs

| Result | TTL | Reason |
|--------|-----|--------|
| Success | 90 days (config `cache_success_ttl_days`) | Property addresses rarely move |
| Failure | 7 days (config `cache_failure_ttl_days`) | Lets a fixed normaliser or upstream-data fix retry within a week |

### Contract

```php
$cache = app(GeocodeCache::class);

// Returns null on miss OR expired. Increments hit_count + stamps last_hit_at.
$hit = $cache->get('36 Ss Topanga, 2587 Colin Road, Uvongo');

$cache->put($address, $lat, $lng, 'exact', 'google', 'ROOFTOP', $formatted);
$cache->putMiss($address, 'google:ZERO_RESULTS');
$deleted = $cache->purgeExpired();
```

Hit-record writes use a raw `DB::table()->update()` to avoid touching
`updated_at` (the read is not a state change of the underlying data).

### Daily purge

`geo:cache-purge` is scheduled at 03:00 SAST (see `routes/console.php`).
Runs `purgeExpired()`, logs the deletion count to the `geocoding` log
channel, and exits.

---

## Address parser (`AddressNormaliser::parse()`)

Existing `normalise()` is untouched. New `parse()` returns a structured
breakdown:

```php
[
    'unit_number'        => ?string,  // "36"
    'scheme_name'        => ?string,  // "Topanga"
    'street_address'     => ?string,  // "2587 Colin Road"
    'suburb'             => ?string,  // "Uvongo"
    'is_sectional_title' => bool,     // true when "Ss <Scheme>" matched
    'is_geocodable'      => bool,     // true when a usable street survived
    'geocode_target'     => ?string,  // "2587 Colin Road, Uvongo" — send THIS to Google
]
```

### Why this matters

For sectional title properties the building's GPS is the same for every
unit. Sending `36 Ss Topanga, 2587 Colin Road` to Google returns a result
no better than sending `37 Ss Topanga, 2587 Colin Road` — but they hash to
different cache keys. By geocoding `geocode_target` instead of the raw
input, every unit in one scheme collapses onto one cache entry. This is
worth roughly 50-90% fewer Google calls for typical SA coastal stock.

### Supported patterns

| Input | Match |
|-------|-------|
| `36 Ss Topanga, 2587 Colin Road` | unit=36, scheme=Topanga, street=2587 Colin Road |
| `Ss Madeira Gardens, 4 Tucker Avenue` | unit=null, scheme=Madeira Gardens, street=4 Tucker Avenue |
| `2587 Colin Road, Uvongo` | not sectional; street=2587 Colin Road, suburb=Uvongo |
| `Ss Madeira Gardens` | scheme=Madeira Gardens, street=null, **is_geocodable=false** |

---

## `geocode_needs_review` flag

New boolean column on `tracked_properties`. Set `true` by the backfill
command when the resolver returns `suburb` or `failed` confidence. The
prospecting UI can filter on this to surface bad geocodes for human
correction (the same agent who captured the property usually knows where
it is, even when Google doesn't).

The flag is reset to `false` on the next successful re-resolution at
`street` or `exact` confidence.

---

## Operator runbook

### One-suburb backfill

```bash
# Preview cost — no upstream calls, no writes.
php artisan geo:backfill-tracked-properties --area=Uvongo --dry-run

# Real run — bypasses daily cap (override engaged for the run only).
php artisan geo:backfill-tracked-properties --area=Uvongo --limit=200
```

Per-row trace lands in `storage/logs/geocode-backfill-{date}.log` (separate
from `storage/logs/geocoding.log`). Summary tally and `batch_id` print on
stdout when the run finishes.

### Quota inspection

```php
$limiter = app(\App\Services\Geocoding\GeocodeRateLimiter::class);
$limiter->getRemainingToday();
// [
//   'env_used' => 12, 'env_remaining' => 88, 'env_cap' => 100,
//   'user_used' => 3, 'user_remaining' => 27, 'user_cap' => 30,
//   'environment' => 'local'
// ]
```

### Manual purge

```bash
php artisan geo:cache-purge
```

---

## Acceptance criteria

- [x] Daily caps trip cleanly with a logged warning, never throwing into the
      user-facing flow.
- [x] Cache hits don't consume quota; only upstream calls do.
- [x] Cache entries respect TTL on read (expired = miss).
- [x] Daily scheduled purge command removes expired rows.
- [x] `AddressNormaliser::parse()` handles all SS patterns in test data
      (G1-G3 below).
- [x] `geo:backfill-tracked-properties --dry-run` enumerates candidates
      without calling Google.
- [x] Real backfill engages runtime override; releases it in `finally`.
- [x] `geocode_needs_review` is set on suburb/failed results.
- [x] Spec exists at `.ai/specs/geocoding-spec.md` (this file).
- [x] Tests cover the 10 G1-G10 scenarios in `tests/Feature/Geo/`.

---

## Test plan (G1-G10)

| # | What it proves | File |
|---|----------------|------|
| G1 | `parse()` extracts unit, scheme, street, suburb from canonical SS form | `AddressParserTest` |
| G2 | `parse()` handles scheme-name-only input (is_geocodable=false) | `AddressParserTest` |
| G3 | `parse()` falls through to plain street address when no SS prefix | `AddressParserTest` |
| G4 | Rate limiter blocks at env cap | `GeocodeRateLimiterTest` |
| G5 | Rate limiter blocks at user cap (env still has room) | `GeocodeRateLimiterTest` |
| G6 | Admin override bypasses the cap | `GeocodeRateLimiterTest` |
| G7 | Runtime override engages + releases | `GeocodeRateLimiterTest` |
| G8 | Cache `get()` returns null for expired rows | `GeocodeCacheTest` |
| G9 | Cache `purgeExpired()` deletes only past-expiry rows | `GeocodeCacheTest` |
| G10 | Cache `get()` increments hit_count without touching updated_at | `GeocodeCacheTest` |

---

## Non-goals (deferred)

- Replacing Phase 3f `AddressResolverService` waterfall — kept as-is.
- Building a UI for `geocode_needs_review` — Prospecting Phase 11b.
- Cost dashboard — Phase 11c.
- Caching per-provider (separate Google/Nominatim entries) — current
  collapse-by-key is fine for the use case.
