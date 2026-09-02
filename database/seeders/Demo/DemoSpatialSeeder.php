<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Map/spatial dataset — properties, market reports, scheme owners, deals,
 * and the tracked_properties GPS backfill — extracted from
 * DemoSeedSpatialCommand so it runs INSIDE the demo:seed chain (called from
 * DemoDataSeeder) and not only via the hand-run `demo:seed-spatial` command.
 *
 * Without this, the map/GPS data DemoSeedSpatialCommand produces is wiped by
 * every `demo:reset` (migrate:fresh) and never rebuilt by the automated
 * pipeline — the 3am reset job must CREATE data, not leave the demo mapless.
 * DemoSeedSpatialCommand now delegates here so the logic lives in one place.
 */
class DemoSpatialSeeder extends Seeder
{
    /** @return array<string, int> totals, same shape DemoSeedSpatialCommand reports */
    public function run(int $agencyId = 1): array
    {
        $totals = [];

        $r = (new DemoPropertiesSeeder())->run($agencyId);
        $totals['properties'] = $r['inserted'] ?? 0;
        $this->note($r);

        $totals['tracked_geocoded'] = $this->backfillTrackedPropertyGps($agencyId);

        $r = (new DemoMarketDataSeeder())->run($agencyId);
        $totals['market_reports'] = $r['reports'] ?? 0;
        $totals['comp_rows']      = $r['comp_rows'] ?? 0;
        $totals['listing_rows']   = $r['listing_rows'] ?? 0;
        $this->note($r);

        $r = (new DemoSchemeOwnersSeeder())->run($agencyId);
        $totals['owners_reports'] = $r['reports'] ?? 0;
        $totals['scheme_owners']  = $r['owners']  ?? 0;
        $this->note($r);

        $r = (new DemoDealsSeeder())->run($agencyId);
        $totals['deals'] = $r['inserted'] ?? 0;
        $this->note($r);

        if ($this->command) {
            $this->command->info(sprintf(
                '  Spatial: %d properties, %d market reports (%d comp rows, %d listing rows), '
                . '%d scheme-owner reports (%d owners), %d deals, %d tracked_properties geocoded',
                $totals['properties'],
                $totals['market_reports'],
                $totals['comp_rows'],
                $totals['listing_rows'],
                $totals['owners_reports'],
                $totals['scheme_owners'],
                $totals['deals'],
                $totals['tracked_geocoded'],
            ));
        }

        return $totals;
    }

    private function note(array $r): void
    {
        if (!empty($r['note']) && $this->command) {
            $this->command->warn('    ' . $r['note']);
        }
    }

    /**
     * Identical to DemoSeedSpatialCommand::backfillTrackedPropertyGps() —
     * kept in lockstep deliberately; see that method's docblock for why this
     * synthetic backfill exists instead of a real geocoding call.
     *
     * @return int rows updated
     */
    private function backfillTrackedPropertyGps(int $agencyId): int
    {
        $suburbs = require database_path('seeders/data/kzn_south_coast_suburbs.php');
        $suburbs += [
            'umtentweni'          => ['bounds' => ['south' => -30.705, 'north' => -30.685, 'west' => 30.460, 'east' => 30.490]],
            'oslo_beach'          => ['bounds' => ['south' => -30.715, 'north' => -30.700, 'west' => 30.470, 'east' => 30.495]],
            'sea_park'            => ['bounds' => ['south' => -30.750, 'north' => -30.735, 'west' => 30.445, 'east' => 30.465]],
            'ramsgate'            => ['bounds' => ['south' => -30.890, 'north' => -30.875, 'west' => 30.335, 'east' => 30.355]],
            'st_michaels_on_sea'  => ['bounds' => ['south' => -30.900, 'north' => -30.888, 'west' => 30.325, 'east' => 30.345]],
        ];

        $rows = DB::table('tracked_properties')
            ->where('agency_id', $agencyId)
            ->where('status', 'active')
            ->whereNull('latitude')
            ->whereNull('deleted_at')
            ->select('id', 'suburb')
            ->get();

        $updated = 0;
        foreach ($rows as $row) {
            $key = Str::slug((string) $row->suburb, '_');
            $bounds = $suburbs[$key]['bounds'] ?? null;
            if (!$bounds) {
                continue;
            }

            $seed = crc32('tp-gps|' . $row->id);
            mt_srand($seed);
            $lat = $bounds['south'] + (mt_rand() / mt_getrandmax()) * ($bounds['north'] - $bounds['south']);
            $lng = $bounds['west']  + (mt_rand() / mt_getrandmax()) * ($bounds['east']  - $bounds['west']);
            mt_srand();

            DB::table('tracked_properties')->where('id', $row->id)->update([
                'latitude'        => round($lat, 7),
                'longitude'       => round($lng, 7),
                'geo_source'      => 'demo_synthetic',
                'geo_confidence'  => 'high',
                'geo_resolved_at' => now(),
            ]);
            $updated++;
        }

        return $updated;
    }
}
