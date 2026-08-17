<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Services\ActivityDefinitions\ActivityDefinitionDefaultsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off backfill, safe to re-run. Seeds every EXISTING agency's own
 * `scope='agency'` activity_definitions rows from the shared `scope='system'`
 * template, mirroring what ActivityDefinitionDefaultsService::ensureDefaults()
 * now does automatically for brand-new agencies going forward.
 *
 * Root cause: TargetController's daily-activity points Setup screen read AND
 * wrote the single shared `scope='system'` row set directly, for every
 * agency — no agency ever had its own copy. This command gives each existing
 * agency a snapshot of the current shared values as its own independent
 * starting point (preserving what they see today), so the controller fix
 * (agency-scoped read/write) doesn't leave them with an empty list.
 *
 * Idempotent — ActivityDefinitionDefaultsService::ensureDefaults() skips any
 * agency that already has agency-scoped rows.
 */
class BackfillAgencyActivityDefinitions extends Command
{
    protected $signature = 'agency:backfill-activity-definitions {--dry-run : List what would be seeded without writing anything}';

    protected $description = "Seed per-agency activity_definitions rows from the shared system template (each agency's daily-activity points system, isolated from every other agency's)";

    public function handle(ActivityDefinitionDefaultsService $seeder): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $seeded = 0;
        $skipped = 0;

        $templateCount = DB::table('activity_definitions')->where('scope', 'system')->whereNull('agency_id')->count();

        foreach (Agency::all() as $agency) {
            $hasAny = DB::table('activity_definitions')
                ->where('agency_id', $agency->id)
                ->where('scope', 'agency')
                ->exists();

            if ($hasAny) {
                $skipped++;
                continue;
            }

            $this->line("  agency #{$agency->id} ({$agency->name}): seeding {$templateCount} activity definitions");
            if (!$dryRun) {
                $seeder->ensureDefaults($agency->id);
            }
            $seeded++;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Done. Agencies seeded: {$seeded}, skipped (already had their own): {$skipped}.");

        return self::SUCCESS;
    }
}
