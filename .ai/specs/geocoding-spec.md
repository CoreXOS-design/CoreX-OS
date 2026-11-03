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
php artisan geo:cache-purge                          # expired rows (the 03:00 schedule)
php artisan geo:cache-purge --out-of-bbox            # DRY RUN (the default): counts the cached "outside the area" failures
php artisan geo:cache-purge --out-of-bbox --apply    # deletes ONLY those (other failures + good rows stay)
```

### Re-run properties with no location

```bash
php artisan geocoding:backfill --type=properties                           # DRY RUN (the default): counts per agency, no Google calls, no writes
php artisan geocoding:backfill --type=properties --agency=22               # dry run for one agency
php artisan geocoding:backfill --type=properties --agency=22 --apply       # really resolve (costs Google calls)
```

Both commands are dry-run unless `--apply` is given (Johan, 27 Oct 2026); `--apply` with `--dry-run` still only
counts. A single `--id=` re-resolve is an explicit action and applies. The backfill selects properties whose
latitude/longitude is NULL **or** the (0,0) "unset" sentinel; archived properties are skipped. `--agency` also
skips the comp-row and competition passes (they are not agency-scoped). Purge the `out_of_bbox` rows first,
otherwise a cached failure is served again. The addresses thrown away by the old KZN box were test data: the fix
is about going forward, so there is no all-agency clean-up run.

---

## Accepted area (27 Oct 2026 — Gauteng fix)

**What went wrong.** `AddressResolverService` threw away every Google result outside a hard-coded
KwaZulu-Natal rectangle (a `bounds=` hint plus a strict reject), cached it as `google:out_of_bbox`, and served
that failure for good. Staging held 1,778 such rows; a Centurion estate (property 21960, agency 22) never got a
pin. A hard-coded province also broke "multi-agency, never single".

**Now.**
- Google is asked with `components=country:<code>`, `region=<code>`, and a `bounds=` **hint**: the property's
  province box when it has a province, otherwise the whole country. No hard-coded ", South Africa" suffix.
- A result Google places in the agency's country is **always kept**, whichever province. A result outside the
  country (a Windhoek or Maputo address, an NZ "Rocklands") is a definitive failure, cached `google:out_of_bbox`.
  When Google returns no country, a union of rectangles decides (one rectangle round South Africa would also
  contain Windhoek).
- The country is the agency's setting: `agencies.geocode_country` (2-letter, NULL = `geo.geocoding.default_country`
  = `ZA`). Shapes and province hints live in `config/geo.php` (`geocoding.countries`). A country code the config
  does not know falls back to the default. Code: `App\Support\Geocoding\GeocodingArea`.
- Province hints are keyed by the lower-case letters of the name, so "KwaZulu Natal" and "KwaZulu-Natal" (both
  occur in the data) find the same box.
- Setup Wizard (non-negotiable 10a): `geocode_country` has **no screen yet**; it is a deliberate omission, Johan's
  call. Every agency geocodes in South Africa until that changes.

## Report coordinates (27 Oct 2026)

A property with no real location (NULL or 0,0) takes it from the **agency's own** imported market report before any
Google call: `PropertyReportCoordinates` (`geo_source = market_report_subject`, confidence `high`). Strict, because
borrowing a neighbour's pin is never right (GPS-BORROW-FIX): same agency; the report's subject address contains the
property's street number **and** street name as whole words; the property's suburb/estate appears in the report's
address, suburb or scheme name. No street number, no street name or no suburb = no match. It never overwrites a real
pin. It runs inside `PropertyGeoBackfillService::backfillProperty`, so the create/update hook, the Map panel's
geocode button, `geocoding:backfill` and presentation generation all use it; `PresentationGeneratorService` also calls
it before the analytics read the subject's coordinates. `BackfillPropertyGpsFromReportJob` (report-parse time) is
unchanged and still fills the other direction.

**Any layout, one field (Johan, 27 Oct 2026 — not LOOM-only).** The subject's coordinates are a first-class field
of the canonical read: `subject.latitude` / `subject.longitude` (`CanonicalReadMapper::sanitise` keeps them,
-90..90 / -180..180; `toParseResult` hands them on as `subject_latitude` / `subject_longitude`, which
`ParseMarketReportJob` writes to `market_reports` for every parser). The fallback reads only
`market_reports.subject_latitude/longitude`, never a parser-specific path. CMA Info parsers fill it; the AI read is
asked for it (`AiReportReadService` schema); and a **learned layout** now learns it too (`LayoutLearner` learns a
"Latitude:" / "Longitude:" label next to a number that matches the AI read to ~2 m) so a layout read for free keeps
delivering it. `LearnedLayoutReader` no longer eats the minus sign of a number glued to its label (every South
African latitude is negative). A report that prints the pair as one "GPS: -25.9, 28.1" line is not learned
(stated limit). Tests: `ReportSubjectCoordinatesCanonicalTest` (canonical read, learned layout, unknown layout ->
property).

## Reusing a stored coordinate: number + street + suburb (27 Oct 2026, NUMBER-MATCH-FIX)

**What went wrong.** The resolver's "market_reports.subject_address" step (and the portal-capture step) matched a
stored record on the street name alone — its needle list always included the address with the house number
stripped. "14 Manikie St" was pinned exactly on the report subject "11 Manikie St" (report 438, presentation 231 on
QA1, cached as `market_report` / `exact`), and the comparable pool then dropped it as the subject itself.

**Rule (one place: `App\Support\Geocoding\StreetAddressMatch`, used by both steps; the property fallback in "Report
coordinates" is the same rule).** Another record's coordinates are reused only when the street **number**, the street
**name** and the **suburb** all match, as whole words: `11` is not `111` or `11A`; no number on the address being
resolved, no number on the stored subject, or no suburb = no reuse. Anything else goes to Google (or stays unpinned),
never to the neighbour's pin. No street-type canonicalisation (`St` vs `Street`): a miss costs one Google call, a false
hit puts a house on its neighbour. The old number-stripping needle builder was deleted so it cannot be used again.

**Clearing a bad stored pin.** A comp row or cache entry that already holds a neighbour's pin is cleared by nulling the
row's latitude/longitude and deleting its `geocoding_cache` entry; the next presentation generate re-geocodes it.
The clean-up is a command option, safe to run on live:

```bash
php artisan geocoding:backfill --clear-subject-borrowed-pins                  # DRY RUN: counts rows, reports, cached copies
php artisan geocoding:backfill --clear-subject-borrowed-pins --agency=1       # dry run for one agency
php artisan geocoding:backfill --clear-subject-borrowed-pins --apply          # clear
```

It selects comp rows (not `subject` rows) whose pin is exactly their own report's subject pin although both addresses
carry a house number and the numbers differ (a row with no number is left alone: it cannot be told apart from the
subject). It nulls the pin and deletes only the cached copy that is the borrowed answer (`source = market_report`, same
coordinates); a genuine Google answer for the same address stays. It never geocodes, so it costs no Google calls and
does not touch the daily cap — the rows re-pin on the next generate or backfill. Already-generated presentation
versions keep the pin they were built with until regenerated. QA1, 27 Oct 2026: 39 rows.
Tests: `ResolverNumberMatchTest` (same street + different number is not the subject's pin; it goes to Google and gets its
own; same number still reuses; suburb leg; no-number both ways; 11 vs 111 / 11A).

**Per-user daily Google cap.** `GeocodeRateLimiter::getUserCap()` reads `config('geo.geocoding.user_daily_cap')`, i.e. the
`GEOCODING_USER_DAILY_CAP` environment value (code default 30), per environment — not a hard-coded constant, so there is
nothing to turn into a setting. QA1 and Staging run it at 50.

## Failure is recorded (27 Oct 2026)

`PropertyObserver` create/update hooks swallowed a thrown geocode with a log line, leaving `geo_source` NULL
("never attempted"). The catch now calls `PropertyGeoBackfillService::recordFailure()`: the property gets
`geo_source = unresolved`, `geo_confidence = failed`, `geo_resolved_at`, and a `geocoding`-channel warning. It only
touches a property that still has no real pin. NULL `geo_source` now means the hook genuinely never ran.

Tests: `AddressResolverAcceptedAreaTest`, `GeocodingCommandsTest`, `PropertyReportCoordinatesTest`.

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
