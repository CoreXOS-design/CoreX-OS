<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Property;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 3h Step 3 — synthetic agency listings (properties table).
 *
 * Per suburb: 40 properties (24 house / 12 sectional / 4 vacant) → 240 total
 * across 6 suburbs.
 *
 * Deterministic seeding: same street name → same neighbourhood pocket
 * (hash(street) seeds the sub-area pick within suburb bounds). Same scheme
 * → same building GPS. Re-running the seeder produces the same rows for the
 * same gazetteer + agency. Wiping is_demo=true clears them cleanly.
 *
 * Architectural call-out (not pre-decided in spec):
 *   - Properties table requires non-null agent_id + branch_id. We pick the
 *     first agency-scoped agent / branch we find and round-robin. If neither
 *     exists for the target agency, the seeder skips with a warning instead
 *     of failing (e.g. a fresh demo agency with no users yet).
 *   - We populate 'price' with the asking band midpoint; the map service
 *     reads this for the subtitle pill. Listed status comes from 'status'
 *     (active / sold / draft) chosen by weighted distribution.
 *   - geo_source='demo_seed' so it can be told apart from real backfills
 *     done by Phase 3f.
 */
final class DemoPropertiesSeeder
{
    public function run(int $agencyId): array
    {
        $gazetteer = require database_path('seeders/data/kzn_south_coast_suburbs.php');

        // Resolve agents + a branch for the target agency. Round-robin so
        // the listings spread across them.
        $agentIds = DB::table('users')
            ->where('agency_id', $agencyId)
            ->where(function ($q) {
                $q->where('role', 'agent')
                  ->orWhere('role', 'branch_manager')
                  ->orWhere('role', 'admin');
            })
            ->pluck('id')
            ->all();
        $branchIds = DB::table('branches')
            ->where('agency_id', $agencyId)
            ->pluck('id')
            ->all();

        if (empty($agentIds) || empty($branchIds)) {
            return [
                'inserted' => 0,
                'note' => "Skipped — agency {$agencyId} has no agents or no branches.",
            ];
        }

        $inserted = 0;
        $agentCursor  = 0;
        $branchCursor = 0;

        foreach ($gazetteer as $suburbKey => $suburb) {
            // Per suburb: 24 / 12 / 4 by type.
            $plan = array_merge(
                array_fill(0, 24, 'house'),
                array_fill(0, 12, 'sectional'),
                array_fill(0, 4,  'vacant'),
            );

            foreach ($plan as $idx => $type) {
                $agentId  = $agentIds[$agentCursor++  % count($agentIds)];
                $branchId = $branchIds[$branchCursor++ % count($branchIds)];

                $row = $this->buildPropertyRow($suburbKey, $suburb, $type, $idx, $agencyId, $agentId, $branchId);
                // 2026-09-02 — the docblock above always claimed this was safe to
                // re-run ("produces the same rows"), but that was only true if
                // something wiped is_demo=true first; DemoSpatialSeeder (the actual
                // wired-in caller) never did. A second call therefore blind-inserted
                // a full duplicate set. Three real bugs found and fixed:
                //   1. house/vacant house numbers were random_int(1,200) — genuinely
                //      different every run, so most addresses never matched a prior
                //      run at all (see buildPropertyRow). Now deterministic.
                //   2. The key here was (agency_id, address) only — two different
                //      suburbs sharing a street name could collide and wrongly
                //      merge. Widened to include suburb, per the stable identity
                //      (address + suburb + agency).
                //   3. (root cause of the sectional-title duplication that survived
                //      fix #1) PropertyObserver::saving() (AT-266) treats `address`
                //      as a DERIVED display string and rewrites it from the
                //      structured columns whenever unit_number/complex_name are set
                //      on insert — composing "Unit {unit_number}, {complex_name}".
                //      This seeder built the sectional address as
                //      "{scheme}, {sectionNumber}" and matched firstOrCreate on
                //      THAT string, but the row actually persisted with the
                //      observer's rewritten "Unit {sectionNumber}, {scheme}" —  a
                //      different string the very next run's lookup could never
                //      match, so every sectional row duplicated on every re-run.
                //      house/vacant never hit this because the seeder leaves
                //      unit_number/complex_name/street_number/street_name null for
                //      them, so composeAddressFromParts() returns '' and the
                //      observer leaves `address` untouched (confirmed via isolated
                //      repro — see .ai/demo-known-issues/2026-09-02-property-
                //      seeder-idempotency.md). Fix: match sectional rows on the
                //      structured identity the observer actually keys its rewrite
                //      from (agency_id, suburb, complex_name, unit_number), never
                //      on the derived `address` column — matching AT-266's own
                //      rule that `address` "is not an independent fact."
                $matchKey = $type === 'sectional'
                    ? [
                        'agency_id'    => $agencyId,
                        'suburb'       => $row['suburb'],
                        'complex_name' => $row['complex_name'],
                        'unit_number'  => $row['unit_number'],
                    ]
                    : ['agency_id' => $agencyId, 'address' => $row['address'], 'suburb' => $row['suburb']];

                $property = Property::firstOrCreate($matchKey, $row);
                if ($property->wasRecentlyCreated) {
                    $inserted++;
                }
            }
        }

        return ['inserted' => $inserted];
    }

    private function buildPropertyRow(
        string $suburbKey,
        array $suburb,
        string $type,
        int $idx,
        int $agencyId,
        int $agentId,
        int $branchId,
    ): array {
        $bounds = $suburb['bounds'];
        $price  = $this->randomPrice($suburb, $type);

        // For sectional title: pick a scheme, all units in the scheme share
        // one GPS (the building location). For house/vacant: pick a street,
        // GPS seeded from the street name so all listings on the same street
        // cluster naturally.
        if ($type === 'sectional') {
            $scheme        = $suburb['schemes'][$idx % count($suburb['schemes'])];
            $sectionNumber = (string) (($idx % 18) + 1);
            $gps           = $this->seededGps($scheme . '|scheme', $bounds);
            $address       = $scheme . ', ' . $sectionNumber;
            $unitNumber    = $sectionNumber;
            $extent        = random_int(60, 180);
            $beds          = (int) (random_int(0, 9) < 3 ? 1 : random_int(2, 3));
            $baths         = (int) (random_int(0, 9) < 6 ? 1 : 2);
            $propertyType  = 'Sectional Title';
            $complexName   = $scheme;
        } elseif ($type === 'vacant') {
            $street        = $suburb['streets'][$idx % count($suburb['streets'])];
            $gps           = $this->seededGps($street . '|vacant|' . $idx, $bounds);
            // 2026-09-02 — was random_int(1, 200): a genuinely different number
            // every run, so re-running never reliably matched a prior run's
            // address and duplicated instead. Deterministic per (suburb, type,
            // idx), same pattern as seededGps() just above.
            $houseNumber   = (string) (1 + (crc32($suburbKey . '|vacant|' . $idx) % 200));
            $address       = $houseNumber . ' ' . $street;
            $unitNumber    = null;
            $extent        = random_int(400, 1200);
            $beds          = 0;
            $baths         = 0;
            $propertyType  = 'Vacant Land';
            $complexName   = null;
            $sectionNumber = null;
        } else { // house
            $street        = $suburb['streets'][$idx % count($suburb['streets'])];
            $gps           = $this->seededGps($street . '|' . $idx, $bounds);
            // Same fix as 'vacant' above — deterministic per (suburb, type, idx).
            $houseNumber   = (string) (1 + (crc32($suburbKey . '|house|' . $idx) % 200));
            $address       = $houseNumber . ' ' . $street;
            $unitNumber    = null;
            $extent        = random_int(200, 800);
            $beds          = random_int(2, 4);
            $baths         = max(1, min(3, $beds - random_int(0, 1)));
            $propertyType  = 'House';
            $complexName   = null;
            $sectionNumber = null;
        }

        $status = $this->pickStatus();
        $publishedAt = in_array($status, ['active', 'for_sale', 'sold'], true)
            ? now()->subDays(random_int(7, 540))
            : null;

        return [
            'external_id'    => (string) Str::uuid(),
            'agency_id'      => $agencyId,
            'branch_id'      => $branchId,
            'agent_id'       => $agentId,
            'title'          => $address . ', ' . $suburb['name'],
            'address'        => $address,
            'suburb'         => $suburb['name'],
            'town'           => $suburb['town'],
            'city'           => $suburb['town'],
            'region'         => $suburb['municipality'],
            'province'       => 'KwaZulu-Natal',
            'complex_name'   => $complexName,
            'unit_number'    => $unitNumber,
            'property_type'  => $propertyType,
            'listing_type'   => $type === 'vacant' ? 'sale' : 'sale',
            'category'       => 'residential',
            'mandate_type'   => 'sole',
            'status'         => $status,
            'beds'           => $beds,
            'baths'          => $baths,
            'garages'        => $type === 'vacant' ? 0 : random_int(0, 2),
            'size_m2'        => $type === 'sectional' ? $extent : null,
            'erf_size_m2'    => $type !== 'sectional' ? $extent : null,
            'price'          => $price,
            'gross_price'    => $price,
            'net_price'      => $price,
            'primary_price_display' => 'gross',
            'latitude'       => $gps['lat'],
            'longitude'      => $gps['lng'],
            'geo_source'     => 'demo_seed',
            'geo_confidence' => 'exact',
            'geo_resolved_at'=> now(),
            'published_at'   => $publishedAt,
            'listed_date'    => $publishedAt?->copy()->subDays(random_int(0, 30)),
            'is_demo'        => true,
        ];
    }

    private function randomPrice(array $suburb, string $type): int
    {
        $band = match ($type) {
            'house'     => $suburb['price_band_house'],
            'sectional' => $suburb['price_band_sectional'],
            'vacant'    => $suburb['price_band_vacant'],
            default     => [500_000, 1_500_000],
        };
        // Round to nearest R 10 000 so prices look like genuine asking prices.
        $raw = random_int($band[0], $band[1]);
        return (int) (round($raw / 10_000) * 10_000);
    }

    /**
     * Deterministic GPS within bounds — same seed = same point.
     * The hash splits the suburb into a 4×4 grid then jitters within the cell
     * so the streets cluster instead of scattering randomly.
     */
    private function seededGps(string $seed, array $bounds): array
    {
        $hash = crc32($seed);
        // Pick a 4x4 grid cell deterministically from the hash.
        $cellX = $hash % 4;
        $cellY = intdiv($hash, 4) % 4;

        $cellWidth  = ($bounds['east']  - $bounds['west'])  / 4;
        $cellHeight = ($bounds['north'] - $bounds['south']) / 4;

        // Deterministic jitter within the cell (max ±40% of cell, away from edges).
        $jitterX = (($hash >> 8)  & 0xFF) / 0xFF; // 0..1
        $jitterY = (($hash >> 16) & 0xFF) / 0xFF;

        $lng = $bounds['west']  + ($cellX * $cellWidth)  + ($jitterX * $cellWidth);
        $lat = $bounds['south'] + ($cellY * $cellHeight) + ($jitterY * $cellHeight);

        return [
            'lat' => round($lat, 7),
            'lng' => round($lng, 7),
        ];
    }

    private function pickStatus(): string
    {
        // 60% active / 30% sold / 10% draft (spec §3 last bullet).
        $r = random_int(1, 100);
        if ($r <= 60) return 'active';
        if ($r <= 90) return 'sold';
        return 'draft';
    }
}
