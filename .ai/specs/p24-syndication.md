# Spec: Property24 Outbound Syndication (ExDev REST API v53)

**Status:** Built — Phase 1
**Approved by:** Johan (2026-03-26)

## Overview

Push CoreX property listings to Property24 via their ExDev REST API (Listing Service v53). Completely separate from Private Property SOAP syndication. No PP code is touched.

## API Details

- **Base URL:** `https://api.exdev.property24-test.com`
- **Auth:** HTTP Basic Auth (username:password)
- **Listing endpoint:** `POST /listing/v53/listings` (create and update)
- **Status endpoint:** `PUT /listing/v53/listings/{listingNumber}/status`
- **Images:** Sent as base64-encoded bytes inline (not URLs)
- **Suburb:** Requires P24 `suburbId` (integer from `/listing/v53/suburbs`)
- **Property Type:** Requires P24 `propertyTypeId` (integer from `/listing/v53/property-types`)

## Files

### Services
- `app/Services/Syndication/Property24/Property24ApiClient.php`
- `app/Services/Syndication/Property24/Property24ListingMapper.php`
- `app/Services/Syndication/Property24/Property24SyndicationService.php`

### Controller
- `app/Http/Controllers/Property24/P24SyndicationController.php`

### Jobs
- `app/Jobs/SubmitListingToProperty24.php`
- `app/Jobs/SyncProperty24Activations.php`

### Commands
- `app/Console/Commands/P24SyndicateSubmit.php` — `p24:syndicate-submit {property}`
- `app/Console/Commands/P24SyndicateSmokeTest.php` — `p24:syndicate-smoke-test`

### Model
- `app/Models/P24SyndicationLog.php`

### Migration
- `database/migrations/2026_03_26_100001_add_p24_syndication_columns_to_properties_table.php`

## Credentials (.env)

```
P24_EXDEV_API_URL=https://api.exdev.property24-test.com
P24_EXDEV_USERNAME=31357@hfcoastal.co.za
P24_EXDEV_PASSWORD=PrOp3rt13s2o26
P24_EXDEV_AGENCY_ID=31357
P24_EXDEV_SANDBOX=true
P24_EXDEV_IMAGE_BASE_URL=https://corex.hfcoastal.co.za
```

The `.env` `P24_EXDEV_AGENCY_ID` becomes the **fallback only** in Phase 2. Real
routing is resolved per-listing from the agency/branch record.

---

# Phase 2 — Per-Agency / Per-Branch Routing

**Status:** Draft — awaiting approval
**Drafted:** 2026-04-18
**Author:** Andre

## Why this exists

P24 confirmed that a single feed user account (e.g. `31357@hfcoastal.co.za`)
can publish to **multiple P24 agency profiles** by changing the `agencyId`
field on each submitted listing. They demonstrated this by creating two dummy
profiles linked to our existing feed user:

- `31357` → https://www.exdev.property24-test.com/estate-agents/home-finders-coastal-1/31357
- `31358` → https://www.exdev.property24-test.com/estate-agents/home-finders-coastal-2/31358

CoreX is multi-tenant. Each CoreX agency may have its own P24 profile, and
larger agencies may want **separate P24 profiles per branch** (e.g. Shelly
Beach branch publishes under its own P24 ID, Ballito under another) while
smaller agencies use **one P24 profile for all branches**.

Phase 1 hard-codes a single `P24_EXDEV_AGENCY_ID` from `.env` in
[`Property24ListingMapper::map()`](../../app/Services/Syndication/Property24/Property24ListingMapper.php).
That blocks every CoreX agency after the first from syndicating to its own
P24 profile, and blocks per-branch routing entirely.

## Pillars touched

- **Property** — listing payload now carries a resolved P24 agency ID
- **Agent** — agent's branch determines routing when no override is set
- **Multi-tenancy** — Agency model gains a P24 identity; `agency_id` scope
  enforces isolation as normal

## Routing model

```
Property
  └─ Branch          (branches.p24_agency_id, NULL = inherit)
       └─ Agency     (agencies.p24_agency_id, default for all branches)
```

Resolution order at submit time:

```
$p24AgencyId = $property->branch->p24_agency_id
            ?? $property->branch->agency->p24_agency_id
            ?? null;

if ($p24AgencyId === null) {
    throw new Property24ConfigurationException(
        "Property #{$property->id} cannot be syndicated: neither branch "
        . "'{$property->branch->name}' nor agency "
        . "'{$property->branch->agency->name}' has a Property24 agency ID."
    );
}
```

The `.env` value stops being authoritative — it stays only as a smoke-test
default for the `p24:syndicate-smoke-test` command.

## Credentials assumption (current scope)

P24 has confirmed that **one feed user can serve multiple agency profiles**.
For now, `P24_EXDEV_USERNAME` / `P24_EXDEV_PASSWORD` remain in `.env` and are
shared. If we later need per-agency feed users (different P24 contracts per
CoreX tenant), Phase 3 will move credentials onto the `agencies` table. Spec
notes the migration path but does not build it yet.

## Data model

### Migration 1 — agencies
```
Schema::table('agencies', function (Blueprint $table) {
    $table->string('p24_agency_id', 32)->nullable()->after('whatsapp_number');
    $table->string('p24_agency_label', 100)->nullable()->after('p24_agency_id');
    // p24_agency_label = human-readable, e.g. "Home Finders Coastal — HFC1"
});
```

### Migration 2 — branches
```
Schema::table('branches', function (Blueprint $table) {
    $table->string('p24_agency_id', 32)->nullable()->after('phone');
    // NULL = inherit from agency
});
```

No changes to `properties` table — routing is derived at submit time, never
stored on the property.

## Code changes

### Modify
- [`app/Services/Syndication/Property24/Property24ListingMapper.php`](../../app/Services/Syndication/Property24/Property24ListingMapper.php)
  — replace the `config('services.property24_syndication.agency_id')` lookup
  on line 18 with the resolution rule above. Throw a typed exception if no
  ID is resolvable.
- [`app/Services/Syndication/Property24/Property24SyndicationService.php`](../../app/Services/Syndication/Property24/Property24SyndicationService.php)
  — catch the new `Property24ConfigurationException` in the submit/deactivate
  flows and surface a friendly error in the toast + log it to
  `P24SyndicationLog`.
- [`app/Http/Controllers/Property24/P24SyndicationController.php`](../../app/Http/Controllers/Property24/P24SyndicationController.php)
  `readiness()` method — extend the readiness check to verify the property's
  branch/agency has a resolvable P24 agency ID before allowing submission.

### Create
- `app/Exceptions/Property24ConfigurationException.php`
- Admin form fields (no new pages — extend existing agency + branch edit views):
  - `resources/views/admin/agencies/edit.blade.php` — add "Property24 agency ID"
    + label inputs in a "Syndication" panel
  - `resources/views/admin/branches/edit.blade.php` — add "Property24 agency
    ID (override)" input with helper text "Leave blank to use agency default"

### No-touch
- `Property24ApiClient` — credentials still come from `.env`
- Routes — no new endpoints
- Jobs — no signature change

## UI placement

- **Agency edit page** (`/admin/agencies/{id}/edit`): new "Syndication" card
  below the contact details card. Two fields: P24 agency ID, P24 agency label.
- **Branch edit page** (`/admin/branches/{id}/edit`): new "Syndication" card
  with one field: P24 agency ID override + helper text showing the resolved
  default ("Will use agency default: 31357" when blank).
- **Property syndication panel** (existing toggle UI): show a small badge
  `P24: 31358 (HFC-2)` so the user can see which profile this listing is
  about to be sent to **before** they toggle. Pulled live from the resolution
  chain.

No sidebar changes — these are extensions to existing admin pages, not new
modules.

## Permissions

Reuse existing keys:
- `agencies.manage` already gates the agency edit page → covers P24 ID field
- `branches.manage` already gates the branch edit page → covers override field
- `properties.syndicate.p24` (Phase 1) still gates the toggle itself

No new permission keys.

## User flow — adding a new agency to P24

```
1. Admin creates new agency "Demo Agency" in CoreX
2. P24 (manually, via email) creates a profile for that agency on ExDev/live
   and returns the new P24 agency ID (e.g. 31358)
3. Admin opens /admin/agencies/{id}/edit, enters 31358 in "P24 agency ID",
   enters "Demo Agency — P24" in the label, saves
4. (Optional) For each branch under Demo Agency that needs its own P24
   profile, admin opens /admin/branches/{id}/edit and enters the override.
   Otherwise leaves blank → branch inherits 31358.
5. Agent toggles P24 syndication on a property under Demo Agency
6. Property is sent to ExDev with agencyId=31358 → appears on the
   Demo Agency P24 profile
```

## Acceptance criteria

1. A property whose branch has no override and whose agency has
   `p24_agency_id = '31357'` is published to profile 31357.
2. A property whose branch has `p24_agency_id = '31358'` is published to
   profile 31358 even when its agency's default is 31357.
3. A property whose branch and agency both have NULL `p24_agency_id` cannot
   be syndicated — the toggle is disabled with tooltip "Agency has no
   Property24 profile configured", and any direct API/CLI submit returns a
   clear `Property24ConfigurationException`.
4. The agency and branch edit pages accept and persist `p24_agency_id`.
5. The property syndication panel shows the resolved P24 agency ID/label
   before submission.
6. All Phase 1 behaviour continues to work for existing HFC properties
   (regression: HFC's agency must be seeded with `p24_agency_id = '31357'`
   in the same migration so nothing breaks on deploy).
7. `dev-check.ps1` passes with 0 new failures.

## Test plan with the two dummy profiles

P24 has provided two dummy profiles linked to our feed user:
- `31357` (HFC-1)
- `31358` (HFC-2)

End-to-end test once Phase 2 is built:

1. Open the existing HFC agency in admin → set `p24_agency_id = 31357` (this
   is the seeded default).
2. Create a second CoreX agency "Test Agency 2" → set `p24_agency_id = 31358`.
3. Add at least one branch + one property under Test Agency 2.
4. Toggle P24 syndication on a HFC property → verify it appears at the 31357
   dummy URL above.
5. Toggle P24 syndication on a Test Agency 2 property → verify it appears at
   the 31358 dummy URL above.
6. Add a branch under HFC, set its override to `31358`. Add a property to
   that branch and syndicate it → verify it lands on the **31358** profile
   even though HFC's agency default is 31357.
7. Deactivate one of each → verify they disappear from the correct profiles.

## Out of scope (future phases)

- **Phase 3** — per-agency feed credentials (separate P24 contracts per CoreX
  tenant). Move `username` / `password` onto `agencies` table. Triggered
  only when a CoreX agency signs their own P24 deal.
- Bulk re-routing (move N existing listings from agency A's P24 profile to
  agency B's). Manual deactivate + re-submit is acceptable for now.
- P24 profile auto-provisioning. P24 creates profiles manually on request;
  no API for this exists.

---

# Operations — Worker & Deploy Discipline (AT-P24 remediation, 2026-06-26)

## Queue worker model (the stale-worker hazard)
P24 submits run on the queue (`SubmitListingToProperty24`). CoreX uses
`QUEUE_CONNECTION=database` on the **`nexus_os`** DB, so the **only** worker that
can process P24 jobs is one launched as **`/corex/artisan queue:work`**. The
authoritative worker is the supervised program **`corex-worker-live`**
(`/etc/supervisor/conf.d/corex-worker.conf`), which runs
`php /corex/artisan queue:work --queue=default,matching --sleep=3 --tries=3 --max-time=3600`
(`--max-time=3600` self-restarts hourly so it picks up new code).

A long-lived `queue:work` holds all PHP classes in memory for its whole life
(CLI opcache is off, but that only helps *fresh* invocations, not a persistent
worker). **Therefore every deploy that changes P24 code MUST restart the
worker** or queued jobs run stale code:

```
git pull …
php artisan config:clear && php artisan view:clear
php artisan queue:restart
supervisorctl restart corex-worker-live:*
# verify the new worker booted AFTER the deploy:
for p in $(pgrep -f "queue:work"); do echo "$(stat -c %y /proc/$p|cut -d. -f1) $(readlink /proc/$p/cwd) $(tr '\0' ' ' </proc/$p/cmdline)"; done
```
Exactly **one** `/corex/...queue:work` process should be running, supervised,
booted after the deploy. No second/un-supervised CoreX worker may run.

## Correction to the 2026-06-26 audit (HIGH #1 was a misattribution)
The audit flagged PM2 process **`heartwood-queue` (PID 1584906)** as a rogue
CoreX worker "racing the default queue." On inspection its **`cwd=/heartwood`**
and it loads **`/heartwood/artisan`** against the **`heartwood`** database
(also `QUEUE_CONNECTION=database`). Because the database queue driver only reads
the `jobs` table in the worker's *own* DB, **it cannot pull CoreX (`nexus_os`)
jobs** — the two never share a queue. It is a legitimate worker for a separate
application and **was NOT killed**. CoreX already runs exactly one supervised
worker on its own queue; the genuine discipline is the deploy-restart rule above.
