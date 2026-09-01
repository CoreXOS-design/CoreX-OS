<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\Demo\DemoDealsSeeder;
use Database\Seeders\Demo\DemoMarketDataSeeder;
use Database\Seeders\Demo\DemoPropertiesSeeder;
use Database\Seeders\Demo\DemoSchemeOwnersSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3h Step 7 — synthetic spatial seeder orchestrator.
 *
 *   php artisan demo:seed-spatial              (agency 1, additive)
 *   php artisan demo:seed-spatial --agency=2   (target agency 2)
 *   php artisan demo:seed-spatial --fresh      (wipes is_demo=true rows first)
 */
final class DemoSeedSpatialCommand extends Command
{
    protected $signature = 'demo:seed-spatial
        {--agency=1 : Target agency_id}
        {--fresh   : Wipe all is_demo=true rows before seeding}';

    protected $description = 'Seed synthetic spatial data (properties + market reports + scheme owners + deals) for an agency.';

    public function handle(): int
    {
        $agencyId = (int) $this->option('agency');
        $fresh    = (bool) $this->option('fresh');

        if (!DB::table('agencies')->where('id', $agencyId)->exists()) {
            $this->error("Agency #{$agencyId} doesn't exist.");
            return self::INVALID;
        }

        if ($fresh) {
            $this->info("Wiping existing is_demo=true rows for agency {$agencyId}…");
            $wiped = $this->wipeDemoRows($agencyId);
            foreach ($wiped as $table => $n) {
                $this->line("  {$table}: {$n} deleted");
            }
            $this->newLine();
        }

        $this->info("Seeding demo spatial data for agency #{$agencyId}…");
        $this->newLine();

        $totals = [];

        $this->info('Step 1/4 — properties');
        $r = (new DemoPropertiesSeeder())->run($agencyId);
        $totals['properties'] = $r['inserted'] ?? 0;
        if (!empty($r['note'])) $this->warn('  ' . $r['note']);
        $this->line('  inserted: ' . $totals['properties']);

        $this->info('Step 1b/4 — tracked properties GPS backfill');
        $totals['tracked_geocoded'] = $this->backfillTrackedPropertyGps($agencyId);
        $this->line('  geocoded: ' . $totals['tracked_geocoded']);

        $this->info('Step 2/4 — market reports + comp rows');
        $r = (new DemoMarketDataSeeder())->run($agencyId);
        $totals['market_reports'] = $r['reports'] ?? 0;
        $totals['comp_rows']      = $r['comp_rows'] ?? 0;
        $totals['listing_rows']   = $r['listing_rows'] ?? 0;
        if (!empty($r['note'])) $this->warn('  ' . $r['note']);
        $this->line('  reports: ' . $totals['market_reports']);
        $this->line('  comp_rows: ' . $totals['comp_rows']);
        $this->line('  listing_rows: ' . $totals['listing_rows']);

        $this->info('Step 3/4 — scheme owners');
        $r = (new DemoSchemeOwnersSeeder())->run($agencyId);
        $totals['owners_reports'] = $r['reports'] ?? 0;
        $totals['scheme_owners']  = $r['owners']  ?? 0;
        if (!empty($r['note'])) $this->warn('  ' . $r['note']);
        $this->line('  scheme-owners reports: ' . $totals['owners_reports']);
        $this->line('  owner records: ' . $totals['scheme_owners']);

        $this->info('Step 4/4 — deals');
        $r = (new DemoDealsSeeder())->run($agencyId);
        $totals['deals'] = $r['inserted'] ?? 0;
        if (!empty($r['note'])) $this->warn('  ' . $r['note']);
        $this->line('  deals: ' . $totals['deals']);

        $this->newLine();
        $this->info('=== Demo seeding complete ===');
        $pinTotal = $totals['properties']
            + $totals['comp_rows']
            + $totals['listing_rows']
            + ($totals['market_reports'] + $totals['owners_reports'])
            + $totals['scheme_owners'];
        $this->line(sprintf(
            "  %d properties\n  %d market_reports (%d comp rows, %d listing rows)\n  %d scheme-owners reports\n  %d owner records\n  %d deals\n  Total pins on map: ~%d",
            $totals['properties'],
            $totals['market_reports'],
            $totals['comp_rows'],
            $totals['listing_rows'],
            $totals['owners_reports'],
            $totals['scheme_owners'],
            $totals['deals'],
            $pinTotal,
        ));
        return self::SUCCESS;
    }

    /**
     * `tracked_properties` normally gets its GPS from the real, live Google
     * geocoding backfill job (see MapPinService::trackedProperties() comment:
     * "Wired post-2026-05-27 Google geocoding backfill") — a real external API
     * call that a seeder must never make. Without it, every demo-agency tracked
     * property sits with latitude/longitude NULL forever and the map's T layer
     * is permanently empty. This backfills a plausible in-suburb point using
     * the same curated KZN South Coast bounds DemoPropertiesSeeder draws from,
     * scoped to rows this command can identify as synthetic-safe: agency-owned,
     * still active (not yet promoted/archived/duplicate), not already geocoded.
     *
     * @return int rows updated
     */
    private function backfillTrackedPropertyGps(int $agencyId): int
    {
        $suburbs = require database_path('seeders/data/kzn_south_coast_suburbs.php');
        // Suburbs the prospecting-stage seeder produces that fall outside the
        // 6-suburb spatial gazetteer file — approximate real-world bounding
        // boxes along the same stretch of coast (Umtentweni/Oslo Beach sit just
        // north of Port Shepstone; Sea Park is a Port Shepstone suburb; Ramsgate
        // and St Michael's-on-Sea sit between Margate and Southbroom).
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
            $key = \Illuminate\Support\Str::slug((string) $row->suburb, '_');
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

    /** @return array<string, int> */
    private function wipeDemoRows(int $agencyId): array
    {
        // Order matters: drop FK-children before parents to avoid constraint
        // errors. comp_rows + scheme_owners reference market_reports.
        $tables = [
            // children first
            'market_report_comp_rows'        => null,    // no agency_id direct, joins through market_reports
            'scheme_owners'                  => 'agency_id',
            // standalone
            'deals'                          => 'agency_id',
            'presentation_sold_comps'        => null,    // no agency_id; rare for demo
            'presentation_active_listings'   => null,
            'tracked_properties'             => 'agency_id',
            'properties'                     => 'agency_id',
            // parent last
            'market_reports'                 => 'agency_id',
        ];

        $deleted = [];
        foreach ($tables as $table => $agencyCol) {
            $q = DB::table($table)->where('is_demo', true);
            if ($agencyCol) $q->where($agencyCol, $agencyId);
            $deleted[$table] = $q->delete();
        }
        return $deleted;
    }
}
