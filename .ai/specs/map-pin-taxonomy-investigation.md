# Map Pin Taxonomy & Data Lineage — Read-Only Investigation

> **Status:** pre-spec investigation. Not a spec yet.
> **Authored:** 2026-05-28 on branch `feature/map-workspace-overhaul`.
> **Audience:** Johan (decision), Andre (visibility). This document IS the input that will write `.ai/specs/map-workspace-overhaul-spec.md`.
> **Scope rule:** every claim quotes the code. Anything not in the code is marked **UNRESOLVED**.

---

## (a) Entry-point map

The map workspace traverses one HTTP request and one JSON fetch.

### Route definitions

[routes/web.php:2014-2028](routes/web.php#L2014-L2028) — under `Route::prefix('corex/market-intelligence')->middleware(['auth', 'permission:access_prospecting'])` and a nested `Route::prefix('map')->middleware(['permission:access_properties','agency.required'])->name('corex.map.')`:

```php
Route::get('/',                       [\App\Http\Controllers\Map\MapController::class, 'index'])->name('index');
Route::get('/pins',                   [\App\Http\Controllers\Map\MapController::class, 'pins'])->name('pins');
Route::get('/sold/{layerId}',         [\App\Http\Controllers\Map\MapController::class, 'soldCard'])->name('sold');
Route::get('/active/{layerId}',       [\App\Http\Controllers\Map\MapController::class, 'activeCard'])->name('active');
Route::get('/mic-subject/{report}',   [\App\Http\Controllers\Map\MapController::class, 'micSubjectCard'])->name('mic-subject');
Route::get('/scheme-owner/{owner}',   [\App\Http\Controllers\Map\MapController::class, 'schemeOwnerCard'])->name('scheme-owner');
Route::post('/activity/log',          [\App\Http\Controllers\Map\MapActivityController::class, 'log'])->name('activity.log');
Route::get('/saved-searches',         [\App\Http\Controllers\Map\MapSavedSearchController::class, 'index'])->name('saved-searches.index');
Route::post('/saved-searches',        [\App\Http\Controllers\Map\MapSavedSearchController::class, 'store'])->name('saved-searches.store');
Route::patch('/saved-searches/{id}',  [\App\Http\Controllers\Map\MapSavedSearchController::class, 'update'])->name('saved-searches.update')->whereNumber('id');
Route::delete('/saved-searches/{id}', [\App\Http\Controllers\Map\MapSavedSearchController::class, 'destroy'])->name('saved-searches.destroy')->whereNumber('id');
```

### Controller hops

[app/Http/Controllers/Map/MapController.php:40-43](app/Http/Controllers/Map/MapController.php#L40-L43) — `index()` renders the Blade:

```php
public function index(): \Illuminate\View\View
{
    return view('corex.map.index');
}
```

[app/Http/Controllers/Map/MapController.php:45-164](app/Http/Controllers/Map/MapController.php#L45-L164) — `pins()` validates the bounds + filter inputs, builds a `MapBoundsRequest` value object, and delegates to `MapPinService::getPinsInBounds()`. Returns JSON `{ locations: [...], layer_counts: {...}, capped_layers: [...] }`.

### Frontend hop

[resources/views/corex/map/index.blade.php:374](resources/views/corex/map/index.blade.php#L374) — JS constant:

```javascript
const PINS_URL = @json(route('corex.map.pins'));
```

The fetch fires from `fetchPins()` (in the same file, around the cache-busting block at lines 1338-1419). Bounds + the filter URL params are appended on every move/zoom and on every filter change.

---

## (b) Per-layer lineage table

Six layers. Three letters are aliases for "the same table queried with a different `row_type`". Per-layer evidence below the table.

### H — `hfc_listings`

| Property | Value |
|---|---|
| Model | `App\Models\Property` ([app/Models/Property.php:17](app/Models/Property.php#L17)) |
| Table | `properties` |
| Global scopes implicit | `SoftDeletes` (model uses the trait), `AgencyScope` would apply via `BelongsToAgency` trait — see (g) for the carve-out |
| Global scopes explicitly stripped | None on the H query (see Finding #1) |
| Status filter | `applyStatusFilter($q, $req, 'status')` — filters by enum `active / available / for_sale / to_let / draft / sold` |
| Date filter | NOT applied |
| Upstream writers | `PropertyController` CRUD, Property24 syndication mapper, Private Property syndication mapper, DealV2 pipeline writes |
| Coordinate source | `properties.latitude` + `properties.longitude` (nullable per schema; populated by `AddressResolver`/geocoder service on save) |

Query — [app/Services/Map/MapPinService.php:198-235](app/Services/Map/MapPinService.php#L198-L235):

```php
$q = DB::table('properties')
    ->whereNull('deleted_at')
    ->whereNotNull('latitude')->whereNotNull('longitude')
    ->whereBetween('latitude',  [$req->south, $req->north])
    ->whereBetween('longitude', [$req->west,  $req->east]);

$this->applyScopeFilter($q, $req, 'agency_id', 'agent_id');   // my / agency / all
$this->applyDemoFilter($q, $req, 'is_demo');
$this->applyPropertyTypeFilter($q, $req, 'property_type');
$this->applyTypeFilter($q, $req, 'property_type');
$this->applyBedroomsFilter($q, $req, 'beds');
$this->applyPriceFilter($q, $req, 'price');
// … rooms / stand / building / status / search filters …
$total = (clone $q)->count();
$rows  = $q->select([...])->orderBy('id')->limit($limit)->get();
```

### S — `sold_comps` (THREE sources unioned in-PHP)

Three branches, all written into one `$combined` array deduped by `dedupeKey(address, sale_date)`.

**Branch S(a): MIC comp rows where `row_type='comp'`** — [app/Services/Map/MapPinService.php:412-485](app/Services/Map/MapPinService.php#L412-L485):

```php
$mrcrQ = DB::table('market_report_comp_rows as mrcr')
    ->join('market_reports as mr', 'mr.id', '=', 'mrcr.market_report_id')
    ->leftJoin('market_reports as mr_scheme', function ($j) use ($req) {
        $j->on(DB::raw('LOWER(mr_scheme.subject_scheme_name)'), '=', DB::raw('LOWER(mrcr.scheme_name)'))
          ->whereNotNull('mr_scheme.subject_latitude');
        if (!$req->includeDemo) { $j->where('mr_scheme.is_demo', false); }
    })
    ->whereNull('mrcr.deleted_at')
    ->where('mrcr.row_type', 'comp')
    ->whereNotNull('mrcr.sale_price')
    ->whereRaw('COALESCE(mrcr.latitude, mr_scheme.subject_latitude) IS NOT NULL')
    ->whereRaw('COALESCE(mrcr.latitude, mr_scheme.subject_latitude) BETWEEN ? AND ?', [$req->south, $req->north])
    ->whereRaw('COALESCE(mrcr.longitude, mr_scheme.subject_longitude) BETWEEN ? AND ?', [$req->west,  $req->east]);
$this->applyScopeFilter($mrcrQ, $req, 'mr.agency_id');
$this->applyDemoFilter ($mrcrQ, $req, 'mrcr.is_demo');
$this->applyDateFilter ($mrcrQ, $req, 'mrcr.sale_date');
$this->applySoldWindowFilter($mrcrQ, $req, 'mrcr.sale_date');
```

**Branch S(b): `presentation_sold_comps`** — same file ~line 488-535. GPS is borrowed from `market_report_comp_rows` via a `mic_comp_row_id` key inside `psc.raw_row_json`. Rows without that link are skipped.

**Branch S(c): HFC's own sold deals** — same file ~line 539-590:

```php
$dealsQ = DB::table('deals as d')
    ->join('properties as p', 'p.id', '=', 'd.property_id')
    ->whereNull('d.deleted_at')
    ->whereNotNull('d.property_id')
    ->whereNotNull('d.registration_date')
    ->where(function ($q) {
        $q->whereNull('d.accepted_status')->orWhere('d.accepted_status', '!=', 'D');
    })
    ->whereNotNull('p.latitude')->whereNotNull('p.longitude')
    ->whereBetween('p.latitude',  [$req->south, $req->north])
    ->whereBetween('p.longitude', [$req->west,  $req->east]);
$this->applyScopeFilter($dealsQ, $req, 'd.agency_id', 'p.agent_id');
```

| Backing tables | `market_report_comp_rows`, `presentation_sold_comps`, `deals` (joined `properties` for GPS) |
| Upstream writers | CMA parsers (`ParseMarketReportJob` → `MarketReportCompRow::create`), presentation upload pipeline, deal lifecycle (`DealV2*` controllers on settle/register) |
| Coordinate source | (a) `mrcr.latitude` else `mr.subject_latitude` via scheme-name COALESCE; (b) `mrcr.latitude` via JSON link; (c) `properties.latitude` |
| Dedup | `dedupeKey($address, $sale_date)` in PHP after the unions — first writer wins |

### P — `active_listings` (TWO sources unioned)

**Branch P(a): MIC comp rows where `row_type='listing'`** — [app/Services/Map/MapPinService.php:597-680](app/Services/Map/MapPinService.php#L597-L680). Identical shape to S(a) but `->where('mrcr.row_type','listing')` and `->whereNotNull('mrcr.list_price')`.

**Branch P(b): `presentation_active_listings`** — same file ~line 685-712. GPS via the same `mic_comp_row_id` join.

| Backing tables | `market_report_comp_rows` (row_type='listing'), `presentation_active_listings` |
| Upstream writers | CMA parser, presentation upload |
| Coordinate source | Same COALESCE-with-scheme-subject pattern as S |
| NOT a portal-scrape source today | See Finding #2 below — the layer's left-rail label is *"Portal Stock — competitor listings captured from Property24 and Private Property"* but no P24 / PP webhook code feeds this layer directly. It feeds via CMA reports / presentation uploads. |

### M — `mic_subjects` (virtual layer over `market_reports`)

[app/Services/Map/MapPinService.php:715-755](app/Services/Map/MapPinService.php#L715-L755):

```php
$q = DB::table('market_reports')
    ->whereNull('deleted_at')
    ->whereNotNull('subject_latitude')->whereNotNull('subject_longitude')
    ->whereBetween('subject_latitude',  [$req->south, $req->north])
    ->whereBetween('subject_longitude', [$req->west,  $req->east])
    ->leftJoin('market_report_types as mrt', 'mrt.id', '=', 'market_reports.report_type_id')
    ->select([
        'market_reports.id', 'market_reports.subject_address',
        'market_reports.subject_latitude  as latitude',
        'market_reports.subject_longitude as longitude',
        'market_reports.created_at',
        'mrt.display_name as report_type_name',
        'mrt.key          as report_type_key',
    ]);
$this->applyScopeFilter($q, $req, 'market_reports.agency_id');
$this->applyDemoFilter ($q, $req, 'market_reports.is_demo');
$this->applySearchFilter($q, $req, ['market_reports.subject_address', 'market_reports.subject_scheme_name']);
```

| Backing tables | `market_reports` only (joined `market_report_types` for label) |
| Upstream writers | CMA upload + `ParseMarketReportJob` |
| Coordinate source | `market_reports.subject_latitude` / `subject_longitude` — populated by parser from the subject-property OCR block |
| Verdict | **NOT its own table.** One pin per uploaded CMA report at the report's subject GPS. See (e) for the full verdict. |

### O — `scheme_owners`

[app/Services/Map/MapPinService.php:758-816](app/Services/Map/MapPinService.php#L758-L816):

```php
$q = DB::table('scheme_owners as so')
    ->join('market_reports as mr', function ($j) {
        $j->on(DB::raw('LOWER(mr.subject_scheme_name)'), '=', DB::raw('LOWER(so.scheme_name)'));
    })
    ->whereNull('so.deleted_at')
    ->whereNull('mr.deleted_at')
    ->whereNotNull('mr.subject_latitude')->whereNotNull('mr.subject_longitude')
    ->whereBetween('mr.subject_latitude',  [$req->south, $req->north])
    ->whereBetween('mr.subject_longitude', [$req->west,  $req->east])
    ->groupBy('so.id', 'so.scheme_name', 'so.section_number', 'so.owner_name')
    ->select([
        'so.id', 'so.scheme_name', 'so.section_number', 'so.owner_name',
        DB::raw('MIN(mr.subject_latitude)  as latitude'),
        DB::raw('MIN(mr.subject_longitude) as longitude'),
    ]);
$this->applyScopeFilter($q, $req, 'so.agency_id');
```

| Backing tables | `scheme_owners` (no GPS columns of its own) joined to `market_reports` by `LOWER(scheme_name)` for inherited GPS |
| Upstream writers | CMA parser when the report is a Sectional Title Scheme Owners List |
| Coordinate source | **Inherited** from `market_reports.subject_latitude/longitude` of any report whose `subject_scheme_name` matches the owner's `scheme_name` (case-insensitive). If no such report exists, the owner has no pin. |
| Dedup | One pin per `(scheme_name, section_number, owner_name)` triple after `groupBy`. |

### T — `tracked_properties`

[app/Services/Map/MapPinService.php:306-396](app/Services/Map/MapPinService.php#L306-L396):

```php
$q = DB::table('tracked_properties')
    ->whereNull('deleted_at')
    ->whereNotNull('latitude')->whereNotNull('longitude')
    ->whereNull('promoted_to_property_id')
    ->where('status', 'active')
    ->whereBetween('latitude',  [$req->south, $req->north])
    ->whereBetween('longitude', [$req->west,  $req->east]);

if (Schema::hasColumn('tracked_properties', 'geocode_needs_review')) {
    $q->where(function ($qq) {
        $qq->where('geocode_needs_review', 0)
           ->orWhereNull('geocode_needs_review');
    });
}
$q->where('agency_id', $req->agencyId);                       // ALWAYS — no my/agency/all variant
if (Schema::hasColumn('tracked_properties', 'is_demo')) { $this->applyDemoFilter($q, $req, 'is_demo'); }
$this->applyPropertyTypeFilter($q, $req, 'property_type');
$this->applyDateFilter($q, $req, 'first_seen_at');
$this->applySearchFilter($q, $req, ['street_name', 'suburb', 'erf_number']);
```

| Backing table | `tracked_properties` |
| Upstream writers | **The universal entry point — `TrackedPropertyMatchOrCreateService::matchOrCreate()`** ([app/Services/Prospecting/TrackedPropertyMatchOrCreateService.php:83](app/Services/Prospecting/TrackedPropertyMatchOrCreateService.php#L83)). Every ingress (CMA parser, P24 webhook, PP webhook, Chrome capture, MIC opportunities manual entry, T-pin contact-capture) routes through this single method per [STANDARDS.md §Universal Match-or-Create](.ai/STANDARDS.md). Also written by the deal pipeline when a mandate is signed (sets `promoted_to_property_id`). |
| Coordinate source | `tracked_properties.latitude` / `longitude` + `cma_gps_lat` / `cma_gps_lng`; the matcher prefers the CMA-sourced pair. Both pairs are nullable; the layer requires `latitude` and `longitude` non-null for a pin to appear. |
| Excludes | `promoted_to_property_id IS NOT NULL` (those moved to the H layer once the mandate is signed) and `status != 'active'`. |

---

## (c) Category map table

The "bucket" column is filled strictly from what the code supports — no intuition.

| layer_key | bucket (code-derived) | backing table(s) | upstream writers | dedup key |
|---|---|---|---|---|
| `hfc_listings` | Own-stock (Agency Stock) | `properties` | `PropertyController`, P24 syndication mapper, PP syndication mapper, DealV2 pipeline writes | None within the layer; cross-layer dedup happens in `LocationGrouper` by GPS (5dp) |
| `sold_comps` | Mixed: Sold-from-CMA + Sold-from-presentation + HFC-deals-sold | `market_report_comp_rows` (row_type='comp'), `presentation_sold_comps`, `deals` ⋈ `properties` | CMA parser, presentation upload, deal pipeline | `dedupeKey(address, sale_date)` per layer |
| `active_listings` | Mixed: Active-from-CMA + Active-from-presentation | `market_report_comp_rows` (row_type='listing'), `presentation_active_listings` | CMA parser, presentation upload | `dedupeKey(address, 'active')` per layer (see code) |
| `mic_subjects` | MIC (virtual — derived from CMA report subjects) | `market_reports` (subject_* columns only) | CMA upload + `ParseMarketReportJob` | One pin per `market_reports.id` |
| `scheme_owners` | Scheme intel (CMA-Info Sectional Title Scheme Owners) | `scheme_owners` (GPS inherited from `market_reports`) | CMA parser (Sectional-Title Scheme Owners List variant) | `(scheme_name, section_number, owner_name)` |
| `tracked_properties` | Tracked (the universal property-intelligence dataset) | `tracked_properties` | `TrackedPropertyMatchOrCreateService` (universal) + deal-mandate promotion | One row per TP id; cross-layer dedup is via `promoted_to_property_id` (moved to H) |

Important: there is no row anywhere in the code that uses the words "Portal", "Tracked", or "MIC" as a bucket label on a model. The buckets are **emergent from how each layer is queried**, not declared anywhere.

---

## (d) Overlap findings — concrete

### Can ONE physical address surface as multiple pins?

**Yes — by design and by accident, both.**

#### By design — cross-layer composite

[app/Services/Map/LocationGrouper.php](app/Services/Map/LocationGrouper.php) groups records that share GPS at 5dp (≈ 1.1 m) into a single "location" with `records[]` carrying one row per layer. Composite pins are intentional. The priority order for the composite's *primary* category — [LocationGrouper.php:49-55](app/Services/Map/LocationGrouper.php#L49-L55):

```php
private const PRIORITY = [
    'hfc_listings'    => 1000,
    'active_listings' => 800,
    'sold_comps'      => 600,
    'mic_subjects'    => 400,
    'scheme_owners'   => 200,
];
```

**`tracked_properties` is absent from this priority list** (defaults to 0). So in a composite location that mixes T with anything else, T always loses the visual primacy slot. **Finding #3 — see (g).**

#### By accident — same address in multiple tables with subtly different GPS

The TPMC service was introduced to prevent this, but it only routes WRITES through one funnel. Layers READ from their own tables. A real address can show:

- A `properties` row at `lat=-30.84000, lng=30.39000` (geocoded by `AddressResolver` on save)
- A `tracked_properties` row at `cma_gps_lat=-30.84012, cma_gps_lng=30.39034` (deeds-office GPS via CMA Info — different precision)
- A `market_report_comp_rows` row at `latitude=-30.84012` (same as the TP, sourced from the same CMA)
- A `market_reports` row at `subject_latitude=-30.84005` (Google geocoder fallback)

If any pair lands in the same 5dp bucket → composite. If they round to different buckets → separate pins for the same address. `LocationGrouper` only protects against GPS-bucket collisions, not against "different layers' geocoders disagreeing about the same building" — see Finding #4.

### Shared normalised-address key

- `market_report_comp_rows.suburb_normalised` ([migration line confirmed](database/migrations/2026_05_23_120002_create_market_report_comp_rows_table.php))
- `tracked_properties.suburb_normalised` — auto-populated on save via the model's `booted()` hook ([app/Models/Prospecting/TrackedProperty.php:89-91](app/Models/Prospecting/TrackedProperty.php#L89-L91))
- `properties` table has **no** `*_normalised` columns. Searching across H + S/P/T by normalised address requires a join that builds it on the fly, OR routing through TPMC.
- There is **no `street_name_normalised` column on any table** — only `suburb_normalised`. So "is this the same street?" relies on the matcher's token-overlap fallback (strategy 5), not on an indexed key.

### Where Portal / Tracked / MIC actually diverge in data vs only in query

| Concept | Where it lives | What's different from the other two |
|---|---|---|
| **Portal** (the left-rail label "Portal Stock — competitor listings captured from Property24 and Private Property") | `market_report_comp_rows` (row_type='listing') + `presentation_active_listings` | The data is in MIC tables. There is no direct portal-scrape table feeding this layer. The label is **misleading today** — see Finding #2. |
| **Tracked** | `tracked_properties` | Universal property registry. Has FK back to `properties` (`promoted_to_property_id`). Every other layer's data CAN be linked to a TP via TPMC, but layers don't READ TP — they read their own tables. |
| **MIC** | `market_reports` (the subject pin) + `market_report_comp_rows` (the rows) + `market_data_points` (extracted facts) | Distinct table family. Both the S and P layers source listing/sold pins from these tables, so MIC isn't a separate visual bucket from S/P — it's the *substrate* most of S/P sits on. |

**Summary:** Portal, Tracked, MIC are NOT three distinct datasets. They are:

1. **Tracked = a unification layer** (1 table, fed by everything)
2. **MIC = a data-extraction layer** (3 tables — reports + comp rows + data points — that feed S, P, M, O)
3. **Portal = a UI label** with no dedicated table today; it's a subset of MIC comp rows

### Concrete ambiguous addresses (extrapolated from code, not from running DB queries — flagged for Johan to verify against live data)

- **A signed-mandate property that was previously also a CMA subject.** Shows H (from `properties`) + M (from `market_reports.subject_*`) + possibly T (the TP still exists with `promoted_to_property_id` set, which excludes it from T layer per line 309 — so T DROPS OUT correctly). Result: H + M composite, T correctly suppressed. ✓
- **A previously-sold HFC property that's now also a comp in a recent CMA.** Shows H (status='sold') + S (deals branch) + S (CMA branch). [Same `properties` row counted twice into S? Check.] **UNRESOLVED** — `dedupeKey` is per layer, so the two S sub-sources can both emit at the same address+sale_date and ONE will win. But would H *also* render as a pin if the property is `status='sold'`? Yes — the H query has no status filter that excludes sold (status filter is a user-controlled `applyStatusFilter`, not a hard exclusion). Result: same address renders as H+S composite. Likely what we want — but worth Johan confirming.
- **A vicinity-sale comp that's also a current portal listing.** A CMA report can produce both an MRCR row_type='comp' (a historical sale) AND, if a future CMA covers the same address as currently for sale, an MRCR row_type='listing'. Different MRCR rows → different layer queries → S + P pins at the same address. Composite by `LocationGrouper`. ✓
- **A sectional-title unit owner.** O pin from `scheme_owners` joined to `market_reports.subject_*`. If a comp from the *same scheme* was sold (S layer via the COALESCE-with-scheme-subject pattern), both pins land at the scheme's subject GPS. Composite. ✓
- **A `tracked_properties` row that has NOT been promoted but whose address matches an HFC property the resolver missed.** This is the data-integrity case — the matcher should have caught it (strategy 4 normalised-address or strategy 3 erf+suburb), but if it didn't, the address shows as **both** T and H pins. The collision gate (`MapProspectStatusService::resolve()`) checks this at click time, but the visual pins can still both render. **UNRESOLVED — would require a live query to find any current cases.**

---

## (e) MIC definition verdict

**MIC is not a single thing. It is a name attached to three different things, and the codebase doesn't disambiguate.**

1. **`mic_subjects` (the layer)** = a derived view over the `market_reports.subject_*` columns. One pin per uploaded CMA report. Code-proof — [MapPinService.php:715-755](app/Services/Map/MapPinService.php#L715-L755) reads only `market_reports` + `market_report_types`. There is **no `mic_subjects` table** in the schema.

2. **"MIC" (the module)** = the Market Intelligence module that owns the upload + parse + spot-check pipeline. Tables: `market_reports`, `market_report_comp_rows`, `market_data_points`, `market_data_discrepancies`, `market_report_types`, `scheme_owners`. The recently-fixed parser dispatch (commit `81d09809`) is in this module.

3. **"MIC subjects" (the conceptual unit)** = the *subject property* of any CMA report — the property the report was *about*, distinguished from the comps the report referenced.

The layer's name conflates #1 and #3. The bucket label in (c) above is "MIC (virtual — derived from CMA report subjects)" because the data source is real (`market_reports`) but the layer is a query, not a row in any "MIC subjects" table.

**Verdict for the spec:** the next-gen taxonomy should treat MIC as a *data source family*, not a layer. Pins extracted from MIC sources should carry a `source = 'mic'` tag, but the layer presented to the agent should be the underlying real-world thing — Subject Property / Comparable Sale / Active Listing / Scheme Owner — not "MIC".

---

## (f) WhatsApp / claim gate — current location and what's blocked

### Server-side gate

[app/Services/Map/MapProspectStatusService.php:51-122](app/Services/Map/MapProspectStatusService.php#L51-L122) — `resolve(array $facts, int $agencyId, int $currentUserId): array`. Returns one of `held / own_draft / other_draft / previously_sold / available`. Excerpt:

```php
$property = null;
$tp = $this->matcher->findExistingMatch($agencyId, $factsForLookup);
if ($tp && $tp->promoted_to_property_id) {
    $property = Property::withoutGlobalScopes()
        ->where('id', $tp->promoted_to_property_id)
        ->where('agency_id', $agencyId)
        ->first();
}
if ($property === null) {
    $property = $this->findHfcPropertyByGps($factsForLookup, $agencyId);
}
if ($property === null) {
    return ['status' => 'available'];
}

$status = (string) ($property->status ?? '');
if (in_array($status, self::HELD_STATUSES, true)) {   // active, available, for_sale, to_let
    return ['status' => 'held', 'property_id' => (int) $property->id, ...];
}
if ($status === 'draft') {
    $isOwn = (int) $property->agent_id === (int) $currentUserId;
    return ['status' => $isOwn ? 'own_draft' : 'other_draft', ...];
}
if ($status === 'sold') {
    return ['status' => 'previously_sold', ...];
}
return ['status' => 'available'];
```

**The gate consults exactly one field: `properties.status`.** Five enum values it cares about:
- `held` ⇐ active / available / for_sale / to_let
- `own_draft` ⇐ draft + property.agent_id == current user
- `other_draft` ⇐ draft + property.agent_id != current user
- `previously_sold` ⇐ sold
- `available` ⇐ no `Property` row resolved for this address (via TPMC or GPS proximity)

### Client-side rendering

[resources/views/corex/map/index.blade.php — `actionsForRecord(record, sourceContext, locationKey, card)` around line 788+](resources/views/corex/map/index.blade.php#L788), switch arm `case 'active_listings':` (line 863+). The function reads `card.prospect_status.status` and renders one of:

- `'held'` → "Open property record →" (new tab; blue info banner)
- `'own_draft'` → "Continue your draft (Nd) →"
- `'other_draft'` → "Coordinate with {agent} (Nd in {state})" + secondary "Override and prospect anyway" (opens reason-required modal)
- `'previously_sold'` → "New prospect anyway →" (orange warn banner)
- `'previously_held'` → (handler exists; service doesn't emit this status today)
- `'available'` (default) → "Prospect Now →"

### What "WhatsApp on ANY address" would actually require to change

Today **none** of the six layers carry a direct "WhatsApp" CTA at the pin level. The closest thing is the T-pin's "WhatsApp / Pitch →" action shipped in commit `e9a68d3e` ([app/Http/Controllers/SellerOutreach/EntryPointController.php fromTrackedProperty()](app/Http/Controllers/SellerOutreach/EntryPointController.php#L114)) — but that's TP-scoped. The active_listings layer routes through `MapProspectStatusService::resolve()` which doesn't issue a WhatsApp CTA; it issues a prospect/coordinate/override CTA.

The gate that would have to change is the **status-arm dispatch in `actionsForRecord()` `case 'active_listings'`**:

```javascript
case 'active_listings': {
    const ps = (card && card.prospect_status) ? card.prospect_status : { status: 'available' };
    // … switch (ps.status) { case 'held' / 'own_draft' / 'other_draft' / 'previously_sold' / default ... }
}
```

Today every branch returns a "navigation" CTA (Open property / Continue draft / Coordinate / Prospect). To enable "WhatsApp on any address" the change would be (in the spec, not now):

- Add a `WhatsApp` CTA to every status arm where it makes sense, with the same contact-capture modal pattern as the T-pin `fromTrackedProperty` flow.
- Decide which statuses BLOCK WhatsApp by design (`own_draft` and `held` probably should not let a competing agent within the same agency cold-call the same owner; `other_draft` already has a coordinate-or-override flow).
- Decide whether `sold_comps`, `mic_subjects`, `scheme_owners` also gain WhatsApp CTAs (the scheme_owners layer already has a `Contact owner →` action that opens `wa.me` directly when the owner has a phone — see `case 'scheme_owners':` around line 1000).

**Currently blocked by the gate:**
- WhatsApp from the H pin (no CTA exists; goes to the property record instead)
- WhatsApp from the S pin (no CTA; opens the source CMA report)
- WhatsApp from the M pin (no CTA; opens the CMA report)
- WhatsApp from the O pin **except when owner_phone is set** (then `wa.me` opens directly)
- WhatsApp from the P pin under any non-`available` status (the available status goes through `prospect_launched` which leads to the contact-capture flow that ultimately reaches WhatsApp via the composer)

The "block" is **not in `MapProspectStatusService::resolve()`** — it's in **the client-side action mapping that decides which CTA to render for each status×layer pair**. The service computes a status; the client decides what to do with it.

---

## (g) Open questions / contradictions for Johan to decide

### Finding #1 — `withoutGlobalScopes()` in request code (CLAUDE.md NN#7 violation)

[app/Services/Map/MapProspectStatusService.php:~58-66](app/Services/Map/MapProspectStatusService.php#L58):

```php
$property = Property::withoutGlobalScopes()
    ->where('id', $tp->promoted_to_property_id)
    ->where('agency_id', $agencyId)
    ->first();
```

CLAUDE.md non-negotiable #7 says: *"Do not use `withoutGlobalScope(AgencyScope::class)` in request code"* — and `withoutGlobalScopes()` (no argument, strips everything) is broader. The explicit `where('agency_id', $agencyId)` underneath compensates for the AgencyScope, but it ALSO strips `SoftDeletingScope` — so a soft-deleted `properties` row could be returned and stamped as the collision target. **Decision needed:** tighten to `withoutGlobalScope(AgencyScope::class)` (surgical) and let SoftDeletingScope keep applying.

### Finding #2 — "Portal Stock" layer label is misleading

The map's left-rail says the P layer is *"competitor listings captured from Property24 and Private Property"*. The actual query reads `market_report_comp_rows` and `presentation_active_listings`. There is no `p24_listings` table feed nor a PP webhook feed into this layer (those exist in the broader system — see `app/Services/Syndication/Property24` and `app/Services/PrivateProperty/` — but they currently write into the `properties` table on the H side OR are out of scope for the P layer). **Decision needed:**
- Should the P layer also surface raw `p24_listings` / `pp_listings` rows?
- Or should the label be changed to "Active listings from CMA reports"?

### Finding #3 — `tracked_properties` missing from `LocationGrouper::PRIORITY`

[app/Services/Map/LocationGrouper.php:49-55](app/Services/Map/LocationGrouper.php#L49-L55) lists H/P/S/M/O but not T. T defaults to priority 0. In a composite location where T mixes with any other layer, T loses visual primacy. Probably intentional (the agent should see "this address is on HFC books" before "this address is tracked"), but undocumented. **Decision needed:** confirm the intent or add T to the priority list at an explicit number.

### Finding #4 — No cross-layer geocoder reconciliation

Different layers' GPS comes from different geocoders / sources:
- H: `AddressResolver` (Google + KZN bbox clamp)
- T: `cma_gps_lat/lng` (deeds-office OCR) or fallback `latitude/longitude` (Google)
- S/P (MRCR branch): the comp row's own GPS, fallback to the report's subject GPS
- M / O: `market_reports.subject_latitude/longitude`

If two geocoders return GPS that round to different 5dp buckets for the same address, `LocationGrouper` will emit two separate pins. The TPMC matcher has GPS-proximity match (~5m) at write time, but the map-render path uses exact 5dp keys. **Decision needed:** widen the GPS bucket to ~10–15m so cross-geocoder noise stops producing duplicate composites, OR add a normalised-address fallback to the grouper's `keyFor()`.

### Finding #5 — `presentation_*` ↔ MRCR linkage is fragile

S(b) and P(b) skip rows whose `raw_row_json` lacks `mic_comp_row_id`. There is no schema constraint that `presentation_sold_comps`/`presentation_active_listings` must carry this key. **Decision needed:** is the presentation pipeline meant to ALWAYS link back to an MRCR row, in which case this should be a NOT-NULL column with an FK and the migration backfilled? Or is the presentation pipeline a separate first-class source and we need a GPS column directly on those tables?

### Finding #6 — `properties` has no `*_normalised` columns

The TPMC matcher's strategy 4 ("normalised address") only works because TPMC normalises at write time and stores `tracked_properties.suburb_normalised`. `properties` has no `suburb_normalised` or `street_name_normalised`. Cross-layer dedup at write time relies on the matcher having a normalised key on BOTH sides, but the H side doesn't store one. **Decision needed:** add `suburb_normalised` (+ optionally `street_name_normalised`) to `properties` and backfill, OR document that the matcher's properties-side lookup uses an on-the-fly normalisation (and accept the cost).

### Finding #7 — MIC's "M" layer competes semantically with the S and P layers

The M layer renders one pin per CMA report at the report's subject GPS. The S and P layers ALSO source their pins from `market_report_comp_rows` of the same reports. So a single CMA report can produce:
- 1 M pin (the subject)
- N S pins (comp rows)
- M P pins (listing rows)

All at GPS that are typically all very close (same suburb, often same street). After `LocationGrouper`, the subject pin's location is unique (the subject is the report's *own* property, not a comp), but visually the user sees a dense cluster of mostly-related dots. **Decision needed:** is M visually useful as a distinct layer, or should it collapse into "the subject IS already represented as whichever real-world layer it is (H if HFC mandate, T if a tracked candidate, P if listed, etc.)"?

### Finding #8 — `sold_comps` includes HFC's own historical deals

Branch S(c) reads from `deals` joined to `properties`. So an HFC property that was previously sold appears as BOTH:
- An H pin (if `properties.status='sold'` — still in `properties`, soft-deleted only on archive)
- An S pin (if the deal is registered)

After `LocationGrouper` they composite at the same GPS. **Decision needed:** intentional (the "this address was previously HFC's" overlay is useful) or a duplication that should be deduped — the S layer should only show *competitor* sales, not HFC's own?

### Finding #9 — No record of where the WhatsApp CTA would write to

If the spec adds "WhatsApp on any address" — and the click currently goes through the `EntryPointController::fromTrackedProperty` flow (TP-keyed) — then a click on an H pin would need a *Property-keyed* entry point. `EntryPointController::fromProperty()` exists ([line 42](app/Http/Controllers/SellerOutreach/EntryPointController.php#L42)) but it routes to a seller-picker, not a WhatsApp composer. **Decision needed:** spec must clarify whether a WhatsApp click from H = "open composer for a seller already linked to this property" or "capture a NEW contact and link them to this property as a fresh contact (mirroring T-pin's flow)".

---

## Closing — read-only verification

The audit produced exactly one new file, this report. `git status --short` output is captured in the chat.
