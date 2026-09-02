<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\Demo\DemoSpatialSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3h Step 7 — hand-run entry point for the spatial dataset.
 *
 * The actual seeding logic lives in DemoSpatialSeeder so it can ALSO run
 * automatically inside DemoDataSeeder (and therefore every demo:reset) —
 * this command remains for on-demand re-seeding / the --fresh wipe path,
 * delegating to the same seeder so the two never drift apart.
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

        $seeder = new DemoSpatialSeeder();
        $seeder->setCommand($this);
        $totals = $seeder->run($agencyId);

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
