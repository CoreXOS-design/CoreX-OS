# CMA comp-pin "0 within 1km" — GPS axis/sign investigation

> **Status:** read-only investigation artefact. No fixes applied.
> **Date:** 2026-06-17.
> **Trigger:** Property 771 (Pumula / Duke Road, Margate) reports #162 + #163
> parsed 25 comp rows on hfc_staging, but the generate modal mapped **0 comps
> within 1km**. Reported hypothesis: comp coords parsed in the wrong axis
> order and/or wrong sign from the CMA GPS string `30.374293°E 30.857822°S`
> (longitude-first, latitude-second, latitude written positive despite being
> a Southern-hemisphere coordinate), plotting them outside the radius.
>
> Every claim carries a `file:line` citation. Stored-value confirmation for
> the exact rows could NOT be completed — see §0.

---

## §0 — Data-access note (read this first)

The exact rows the report references (Property **771**, reports **#162/#163**)
**do not exist on this host.** This box (`staging.corexos.co.za`) carries a
smaller dataset:

| DB | properties (max id) | market_reports (max id) | comp rows |
|---|---|---|---|
| `corex_dev` | 532 | 151 | 890 |
| `corex_dev2` | 532 | 151 | 890 |

771 / 162 / 163 are on the production/staging host `91.99.130.85`, which I did
not connect to (no DB tunnel configured here; I won't SSH production
uninvited). The side-by-side stored-coordinate table Johan asked for therefore
**must be pulled on that host** — the exact read-only query is in §6.

What I CAN do conclusively: the **code path is identical on every host**, and
the 890 local comp rows + 532 local properties let me test the wrong-sign
hypothesis directly against real stored data.

---

## §1 — Where the CMA GPS string is parsed (the only GPS parse in the system)

There is exactly **one** place a `°E / °S` GPS string becomes lat/lng:
`App\Support\MarketReports\GpsParser::fromString()`.

[`app/Support/MarketReports/GpsParser.php:26-62`](app/Support/MarketReports/GpsParser.php#L26-L62):

```php
$pattern = '/(?<num>-?\d{1,3}(?:\.\d{1,8})?)\s*(?:°|\x{00B0}|\xEF\xBF\xBD|\?|�)?\s*(?<dir>[EWNS])\b/iu';
foreach ($matches as $m) {
    $val = (float) $m['num'];
    $dir = strtoupper($m['dir']);
    if      ($dir === 'E') $lng =  abs($val);
    elseif  ($dir === 'W') $lng = -abs($val);
    elseif  ($dir === 'S') $lat = -abs($val);   // ← S forces NEGATIVE latitude
    elseif  ($dir === 'N') $lat =  abs($val);
}
```

**This parser is correct, and it is the answer to the reported hypothesis:**

- It keys axis off the **direction letter (E/W/N/S), not position** — so
  longitude-first vs latitude-first ordering is irrelevant. `30.374293°E
  30.857822°S` and `30.857822°S 30.374293°E` both resolve identically.
- It **reads the hemisphere letter and applies sign**: `S` → `-abs($val)`,
  `W` → `-abs($val)`. So the latitude written positive in the PDF
  (`30.857822°S`) is stored as **−30.857822**. Exactly the WGS84 value
  expected for Margate.
- Result for the reported string: `lat = −30.857822, lng = +30.374293`.
  **Correct.** Not in the ocean, not axis-swapped.

Callers (subject GPS only — see §2):
- [`CmaInfoPropertyValuationParser.php:261-267`](app/Services/MarketReports/Parsers/CmaInfoPropertyValuationParser.php#L261-L267)
- [`CmaInfoMarketAnalysisParser.php:106-111`](app/Services/MarketReports/Parsers/CmaInfoMarketAnalysisParser.php#L106-L111)

---

## §2 — Axis + sign assignment, and WHO gets coords from the GPS string

**Only the SUBJECT property is parsed from the page-1 GPS string.** The number
→ axis → sign assignment is GpsParser's (§1): direction-letter drives axis;
S/W → negative. The subject's parsed lat/lng flow to:
- `subject_latitude` / `subject_longitude` on `market_reports.subject_meta`
  ([CmaInfoPropertyValuationParser.php:184-185](app/Services/MarketReports/Parsers/CmaInfoPropertyValuationParser.php#L184-L185));
- an extracted-address record → TrackedPropertyMatchOrCreate → the Property
  ([:193-199](app/Services/MarketReports/Parsers/CmaInfoPropertyValuationParser.php#L193-L199)).

**Comp rows are NEVER parsed from a GPS string — because the CMA tables carry
no per-comp GPS column.** The vicinity-sales table is
`Dist | Erf | Address | Erf Usage | Type | Extent | Date | Price | R/m²`
([CmaInfoVicinitySaleParser.php:262-299](app/Services/MarketReports/Parsers/CmaInfoVicinitySaleParser.php#L262-L299));
the sectional-title table is the same shape minus Dist. Neither comp-row
builder sets `latitude`/`longitude`:
- [`CmaInfoVicinitySaleParser::buildRow` :315-329](app/Services/MarketReports/Parsers/CmaInfoVicinitySaleParser.php#L315-L329) — no lat/lng keys.
- [`CmaInfoPropertyValuationParser::buildCompRow` :471-498](app/Services/MarketReports/Parsers/CmaInfoPropertyValuationParser.php#L471-L498) — `'latitude' => $row['latitude'] ?? null` is a **pass-through that is always null for comps** (the per-comp extractor never populates it).

**→ The reported hypothesis ("comp coords parsed in wrong axis order / wrong
sign") is mechanically impossible: there is no comp-GPS parse to get wrong.**

### How comp rows actually get coords

Only by **geocoding the address string** via
`App\Services\Geocoding\AddressResolverService`, in two places, both writing
standard signed WGS84:
- Lazy, at hydration time —
  [`MicSnapshotHydrator::encodeRaw` :813-854](app/Services/Presentations/MicSnapshotHydrator.php#L813-L854).
- Batch — [`GeocodingBackfillCommand` :208-250](app/Console/Commands/GeocodingBackfillCommand.php#L208-L250)
  (`--type=comp_rows`).

The resolver has a **KZN bounding-box guard**
([AddressResolverService.php:59-62, 344-350](app/Services/Geocoding/AddressResolverService.php#L59-L62))
— lat must be −32..−27, lng 28.5..33. A wrong-signed coordinate (e.g. lat
**+30.857**) is **outside the box and returned as null**, never stored. So the
geocode path *cannot* persist an ocean/northern-hemisphere comp either.

---

## §3 — Stored coords on this host (real data, wrong-sign test)

```
market_report_comp_rows (890 rows):
  total   null_geo  pos_lat  neg_lat  pos_lng  neg_lng
  890     764       0        126      126      0

  sample non-null:  -31.050738, 30.222064  (1 ASHTON ROAD)
                    -30.939529, 30.305573  (7 BEACH ROAD)
                    -30.907062, 30.333872  (2117 SHORE DRIVE)

properties (532 rows):
  total   null_lat  pos_lat  neg_lat  pos_lng  neg_lng
  532     201       0        330      330      0
```

- **Zero rows are stored with positive latitude.** Every geocoded comp and
  every geocoded property sits at lat ≈ −30/−31, lng ≈ +30 — correct WGS84
  for the KZN south coast. There is **no wrong-axis / wrong-sign storage bug**
  in real data.
- **764 / 890 comp rows (86%) have NULL geo** — no per-comp GPS in the PDF and
  the address-geocode hasn't run / was rate-limited / failed. This is the
  documented norm: [GeocodingBackfillCommand.php:92](app/Console/Commands/GeocodingBackfillCommand.php#L92)
  notes only ~25% of comp rows carry GPS.

I could not read 771/162/163 themselves (§0). The §6 query confirms whether
they follow this same pattern on production.

---

## §4 — The radius filter (the "within 1km" gate that returned 0)

[`MicSnapshotHydrator::collectMatchedRows` filter :660-727](app/Services/Presentations/MicSnapshotHydrator.php#L660-L727).
A comp is kept if ANY branch passes:

```php
// Branch 1 — same-subject report
if (in_array($row->market_report_id, $subjectReportIds)) return true;
// Branch 2 — suburb match (SuburbMatcher)
if ($suburbNorm !== '' && SuburbMatcher::matches($row->suburb_normalised, $suburbNorm)) return true;
// Branch 3 — Haversine radius
if ($scope === 'radius_all'
    && $lat !== null && $lng !== null
    && $row->latitude !== null && $row->longitude !== null) {   // ← NULL-geo never reaches Haversine
    $d = HaversineDistance::distanceMetres($lat, $lng, (float)$row->latitude, (float)$row->longitude);
    if ($d <= $radius) return true;
}
return false;
```

- Branch 3 expects **standard signed decimal lat/lng** (`HaversineDistance`),
  S = negative — same standard as storage. **Confirmed consistent.**
- **The hard gate:** Branch 3 only runs when *both* sides have non-null geo
  ([:719-721](app/Services/Presentations/MicSnapshotHydrator.php#L719-L721)).
  A comp with NULL lat/lng (86% of them, §3) **can never be matched by
  radius** — it falls through to `return false` unless Branch 1/2 caught it.
- Subject geo is force-resolved just before hydration
  ([PresentationGeneratorService.php:196-211](app/Services/Presentations/PresentationGeneratorService.php#L196-L211))
  — comment: *"the hydrator's radius_all branch is meaningless without GPS for
  the subject."* If that backfill fails, `$lat/$lng` are null and Branch 3 is
  dead for **every** comp → 0 within radius.
- **Complex/sectional extra gate:** Build-1 `title_type` filter
  ([:660-700](app/Services/Presentations/MicSnapshotHydrator.php#L660-L700))
  drops comps whose `property_type` classifies to a different title_type than
  the subject (unless same-subject or trusted-internal). For a complex subject
  this can independently shrink the pool — type-based, not geo.

---

## §5 — Property pins vs comp pins: is there a different coordinate standard?

**Johan's question — answered: No, the standard is identical (signed WGS84,
S/W negative). The divergence is SOURCE, not standard.**

| Pin | Coord source | Sign handling |
|---|---|---|
| **Subject / property** | Page-1 GPS string via `GpsParser` (§1), OR geocoded address via `AddressResolverService`; force-backfilled pre-hydration | S/W → negative (GpsParser) / signed WGS84 (resolver). Correct. |
| **Comp** | **Only** geocoded address via `AddressResolverService` — never a GPS string (no per-comp GPS in the PDF). Frequently **NULL**. | signed WGS84 + KZN bbox guard rejects wrong-sign. Correct when present. |

So there are **two parse PATHS** (GPS-string for subject; address-geocode for
comps) but **one coordinate STANDARD**. Property pins "work" because the
subject GPS string is present on page 1 and force-resolved; comp pins
disproportionately fail because the comp tables contain **no GPS at all** and
depend on address geocoding that is often missing — not because comps use a
different sign/axis convention.

---

## §6 — Root cause + which rows are mis-stored

**SINGLE ROOT CAUSE (code-level, host-independent):**
Comp rows carry **no GPS from the CMA PDF** — the vicinity/sectional tables
have no per-comp GPS column. Comp coordinates exist *only* if the address
geocodes successfully via `AddressResolverService`. When they are NULL
(the 86%-of-rows norm here), the radius filter's Branch-3 null-guard
([MicSnapshotHydrator.php:719-721](app/Services/Presentations/MicSnapshotHydrator.php#L719-L721))
silently excludes them, yielding "0 within 1km" — **even though 25 rows
parsed.** The reported axis/sign/ocean theory is **refuted**: the only GPS
parser (`GpsParser`) is axis-order-independent and sign-correct, applies to
the **subject only**, and the geocoder's KZN bbox guard makes wrong-signed
storage impossible. Local data confirms zero positive-latitude rows.

**No rows are mis-stored by sign/axis.** The defect is **missing (NULL) comp
geo + a radius gate that drops NULL-geo rows**, plus a possible secondary
contributor for this specific complex: the subject's own GPS backfill failing
(killing Branch 3 for all comps) and/or the title_type filter (§4) dropping
type-mismatched comps.

**To confirm on production (91.99.130.85) — read-only, do NOT write:**

```sql
-- Subject property
SELECT id, property_address, suburb, latitude, longitude FROM properties WHERE id = 771;
-- Comp rows for the two reports: how many have geo, and their sign
SELECT market_report_id, row_type,
       COUNT(*) n,
       SUM(latitude IS NULL OR longitude IS NULL) AS null_geo,
       SUM(latitude > 0) AS pos_lat,   -- expect 0
       MIN(latitude) min_lat, MAX(latitude) max_lat,
       MIN(longitude) min_lng, MAX(longitude) max_lng
FROM market_report_comp_rows
WHERE market_report_id IN (162, 163)
GROUP BY market_report_id, row_type;
```

Expected result that proves this root cause: `null_geo` high (≈ all 25),
`pos_lat = 0`, subject 771 either NULL geo or correctly negative. If instead
`pos_lat > 0` appears, that would be a genuine (and new) storage bug — but the
code makes that path impossible, so it should be 0.

---

## §7 — Fix decision (FLAGGED, NOT APPLIED)

The reported fix ("re-parse order + hemisphere sign") **would change nothing**
— there is no comp GPS parse, and the subject parser is already correct.
Re-touching `GpsParser` axis/sign would be fixing a non-bug.

The real fix lives at the **comp-geo coverage + radius-gate** layer, e.g.:
1. Guarantee comp geo before the radius gate runs (synchronous batch
   geocode of the report's comp rows at generate time, not lazy/best-effort),
   and/or
2. Don't silently drop NULL-geo comps from the radius pool — fall back to
   suburb/scheme scoping with an honest "N of M plotted" caption (the
   plotted/unplotted count already exists at
   [AnalysisDataService.php:905-914](app/Services/Presentations/AnalysisDataService.php#L905-L914)).
3. Separately verify the subject-GPS pre-hydration backfill
   ([PresentationGeneratorService.php:196-211](app/Services/Presentations/PresentationGeneratorService.php#L196-L211))
   actually populated 771's lat/lng — a null subject kills Branch 3 wholesale.

Decision deferred to Johan per the investigation-only constraint.
