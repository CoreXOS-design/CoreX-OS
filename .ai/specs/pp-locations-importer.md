# PP Locations Importer — Spec (DRAFT, pending approval)
> Hidden background sync of Private Property's geography hierarchy.
> Used to validate suburbs before listing submission and to send PP `SuburbId`
> (Mode A) instead of fragile name matching (Mode B).
> Drafted: 2026-05-28

---

## 1. Why this exists

PP confirmed listings are delayed because suburbs are sent incorrectly. Our
current `PrivatePropertyListingMapper` sends free-text Suburb + Town + Province
(Mode B) — PP cannot always resolve name variants ("Uvongo Beach" vs "Uvongo"),
so the listing never activates. PP exposes its own suburb IDs via
`GetProvinces → GetCities → GetSuburbs`; once we hold those IDs locally we can
send Mode A (SuburbId only) which is unambiguous, AND block bad suburbs at
validation time with a clean agent-facing error.

The P24 equivalent already exists at `/admin/importer/p24-locations` and is
the design template. PP version is identical in mechanism but the data is
**hidden** from the page — admins see only refresh status, totals, last-synced
timestamp, not the tree.

---

## 2. Pillar Connections

| Pillar    | Read | Write |
|-----------|------|-------|
| Property  | `suburb`, `town`, `province` (resolution input) | `pp_suburb_id` (set automatically when resolved) |
| Contact   | — | — |
| Deal      | — | — |
| Agent     | — | — |

PP locations themselves are a **reference dataset**, not a pillar — same status
as `p24_suburbs`. They sit in the background to enable the Property pillar's
PP syndication.

---

## 3. Data Model

Three new tables, mirroring `p24_provinces / p24_cities / p24_suburbs`:

```
pp_provinces
  id (PK)
  pp_province_enum   string   -- e.g. "KwaZuluNatal" (PP's enum value)
  name               string   -- "KwaZulu-Natal" (display)
  created_at, updated_at

pp_cities
  id (PK)
  pp_city_id         int      -- PP's GetCities Id
  pp_province_id     FK → pp_provinces.id
  name               string
  created_at, updated_at
  UNIQUE (pp_city_id)

pp_suburbs
  id (PK)
  pp_suburb_id       int      -- PP's GetSuburbs Id (this is what goes in the SOAP SuburbId field)
  pp_city_id         FK → pp_cities.id
  name               string
  normalised_name    string   -- lowercased, no spaces/punct, for matching
  created_at, updated_at
  UNIQUE (pp_suburb_id)
  INDEX (normalised_name)
```

Sync state stored on `agencies` table (one row, agency-scoped only nominally
— PP integration is per-branch but the geography is global): two columns
mirror the P24 pattern:

```
agencies.pp_locations_synced_at   timestamp NULL
agencies.pp_locations_last_error  text NULL
```

Progress cache key during running sync (mirrors P24): `pp:sync-locations:progress`.

---

## 4. SOAP Client Additions

Add four methods to `App\Services\PrivateProperty\PrivatePropertySoapClient`:

| Method                      | WSDL op         | Returns |
|-----------------------------|-----------------|---------|
| `getCountries()`            | GetCountries    | list, used only to confirm SA exists |
| `getProvinces($countryId)`  | GetProvinces    | list of ProvinceModel{Id,Name} |
| `getCities($provinceId)`    | GetCities       | list of CityModel{Id,ProvinceId,Name} |
| `getSuburbs($cityId)`       | GetSuburbs      | list of SuburbModel{Id,Name} |

Each reuses the existing token + retry policy in `call()`.

---

## 5. Sync Command

`php artisan pp:sync-locations` — new Console command.

```
1. Cache::put progress { status: 'running', provinces_total: 0, ... }
2. Resolve South Africa countryId via GetCountries (skip if PP only supports SA)
3. GetProvinces(SA) → upsert pp_provinces
   For each province:
     GetCities(province.Id) → upsert pp_cities
     For each city:
       GetSuburbs(city.Id) → upsert pp_suburbs (compute normalised_name)
   Update progress cache after each city.
4. On success: agencies.pp_locations_synced_at = now()
5. On failure: agencies.pp_locations_last_error = msg, progress.status = 'failed'
```

`normalised_name` rule:
```php
$normalised = preg_replace('/[^a-z0-9]/', '', strtolower($suburb->name));
```

PP's sandbox has hours (06:00–19:00 GMT+2). Outside hours the command logs
"sandbox closed" and aborts with `agencies.pp_locations_last_error` set.

---

## 6. UI

New page: `/admin/importer/pp-locations`
Route name: `admin.importer.pp-locations`
Controller: `App\Http\Controllers\Admin\ImporterController@ppLocations` (mirror of `p24Locations`)
View: `resources/views/admin/importer/pp-locations.blade.php`

**What it shows (per your "hidden" requirement):**
- Header card with "Private Property Locations" title + "Refresh from Private Property" button
- Progress bar (same Alpine widget as P24)
- Three count tiles: Provinces / Cities / Suburbs
- Last-synced timestamp
- Last-sync-error banner (if any)

**What it does NOT show:**
- No tree, no province/city/suburb browse, no API endpoints for child lookups.
- The data exists in `pp_suburbs` for the mapper to read; agents and admins
  cannot see it directly. (Open question Q1 below.)

**Sidebar/navigation:**
- Add a sub-link "PP Locations" under the existing Importer sidebar group,
  next to "P24 Locations".

---

## 7. Mapper Integration

After the sync runs at least once, modify `PrivatePropertyListingMapper`:

1. On `map()`: lookup the property's `suburb` (normalised) + `province` against
   `pp_suburbs ⨝ pp_cities ⨝ pp_provinces`. If exactly one match: set
   `$listing['SuburbId']` to the matched `pp_suburb_id`, **unset** Suburb / Town
   / Province (Mode A — strict PP106 compliance).
2. On `validate()`: if no match found AND no manual `pp_suburb_id` set on the
   property, add an error: `"Suburb 'X' not found on Private Property's list
   (last synced …). Closest matches: …"` — block submission.
3. Persist the resolved `pp_suburb_id` back to the Property so subsequent
   submits skip the lookup. Invalidate when the agent edits the suburb.

This is the key payoff — Mode B becomes the fallback only for the (hopefully
rare) case where someone manually overrides.

---

## 8. Permissions

New permission key: `manage_pp_locations` (mirrors `manage_p24_locations`).
Gates the page route, the refresh endpoint, and the sidebar link.

---

## 9. Acceptance Criteria

- [ ] `php artisan pp:sync-locations` populates `pp_provinces / pp_cities / pp_suburbs` and writes `agencies.pp_locations_synced_at`.
- [ ] `/admin/importer/pp-locations` renders with refresh button, progress bar, three counts, last-synced — and NO tree.
- [ ] Clicking refresh triggers the detached command (same pattern as P24).
- [ ] After sync, submitting a real KZN South Coast property routes through Mode A — SOAP envelope contains `<ns1:SuburbId>NNN</ns1:SuburbId>` and NO `<ns1:Suburb>`, `<ns1:Town>`, `<ns1:Province>` elements.
- [ ] A property with a suburb NOT in `pp_suburbs` is blocked at `validate()` with a clear "suburb not found" message; no SOAP call is made.
- [ ] Permission `manage_pp_locations` is enforced.
- [ ] All endpoints under `/admin/importer/pp-locations*` are versioned via the existing admin routing (no `/api/v1` required — this is admin web, not API).

---

## 10. Files to Create / Modify

**New:**
- `database/migrations/2026_05_28_100001_create_pp_locations_tables.php`
- `database/migrations/2026_05_28_100002_add_pp_locations_sync_to_agencies.php`
- `app/Models/PpProvince.php`, `PpCity.php`, `PpSuburb.php`
- `app/Console/Commands/SyncPpLocations.php`
- `resources/views/admin/importer/pp-locations.blade.php`

**Modify:**
- `app/Services/PrivateProperty/PrivatePropertySoapClient.php` (add 4 methods)
- `app/Http/Controllers/Admin/ImporterController.php` (add `ppLocations`, `refreshPpLocations`, `ppLocationsStatus`)
- `app/Services/PrivateProperty/PrivatePropertyListingMapper.php` (Mode A resolution + validate guard)
- `routes/web.php` (3 admin routes)
- `database/seeders/CoreXPermissionSeeder.php` (add `manage_pp_locations`)
- `resources/views/layouts/corex-sidebar.blade.php` (add "PP Locations" link)
- `.ai/specs/private-property.md` (document the new Mode-A path)

---

## 11. Open Questions (need Johan's call before build)

**Q1. "Hidden" — how hidden?**
You said "the suburbs etc get hidden." Two interpretations:
- (a) Just hide the tree UI but keep the API/data inspectable by superadmin (debugging).
- (b) Hard-hide — no UI at all to see what's in the table, even for super_admin.

I recommend **(a)** — same data, just no tree on the importer page. Superadmin
can still query via Tinker if a suburb resolution fails. Confirm or override.

**Q2. What about the existing `pp_suburb_id` column on properties?**
It was added in `2026_03_23_100002_add_pp_suburb_id_and_coordinates_to_properties_table.php`
but is never populated (we never called GetSuburbs). With this importer it
becomes the live storage of the resolved ID. No migration needed, just usage.

**Q3. Province enum vs province row?**
PP's `Province` field on listings is a fixed enum (KwaZuluNatal, etc.) not an
ID. But `GetProvinces` returns Id+Name. We need both: the enum string for any
Mode B fallback, and the Id for the GetCities call. I propose storing
`pp_province_enum` (the listing field value) AND the PP-returned Id on
`pp_provinces`. Confirm.

**Q4. Sandbox vs production credentials.**
The sync runs against whichever WSDL `PP_WSDL` points at. In production we
want the production WSDL; in dev we want sandbox. Current `.env` already
handles this. The location data may differ between sandbox and production —
flag for awareness.

---

## 12. Risk

Medium. Three new tables, mapper behaviour change, four new SOAP methods.
Mitigation: full feature is gated behind the sync having run at least once
— before that, mapper falls back to current Mode B path (no regression).
