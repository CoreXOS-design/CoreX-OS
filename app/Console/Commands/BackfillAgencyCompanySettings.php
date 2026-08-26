<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\PerformanceSetting;
use Illuminate\Console\Command;

/**
 * 2026-08-15 (Johan, HFC tenant-isolation fix, Wave 1) — one-off backfill,
 * safe to re-run. Seeds company_* PerformanceSetting rows for EVERY
 * EXISTING agency from that agency's own Agency-model fields, mirroring
 * what AgencyObserver::created() now does automatically for brand-new
 * agencies going forward.
 *
 * Without this, PerformanceSetting::get()'s fix (company_* keys never
 * fall back to the global HFC row) is correct in isolation but leaves an
 * existing agency's CMA/commission-calculator output blank wherever that
 * agency ALSO has no value on its own Agency profile (address/phone/ffc/
 * logo) — the fix stops the leak but doesn't invent data an agency never
 * provided; this command only propagates data that genuinely already
 * exists on the Agency record.
 *
 * Never overwrites a row an agency may already have (explicit existence
 * check, not updateOrCreate) — a prior manual save via Admin > Performance
 * Settings always wins. Skips blank Agency fields (no empty-string rows).
 * Idempotent — safe to run more than once, in any environment.
 */
class BackfillAgencyCompanySettings extends Command
{
    protected $signature = 'agency:backfill-company-settings {--dry-run : List what would be created without writing anything}';

    protected $description = 'Seed per-agency company_* PerformanceSetting rows from each agency\'s own profile (HFC tenant-isolation fix, Wave 1)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $created = 0;
        $skippedExisting = 0;
        $skippedBlank = 0;

        foreach (Agency::all() as $agency) {
            $fields = [
                'company_name'     => $agency->name,
                'company_address'  => $agency->address,
                'company_tel'      => $agency->phone,
                'company_ffc'      => $agency->ffc_no,
                'company_logo_url' => $agency->logo_path,
            ];

            foreach ($fields as $key => $value) {
                if ($value === null || $value === '') {
                    $skippedBlank++;
                    continue;
                }

                if (PerformanceSetting::where('agency_id', $agency->id)->where('key', $key)->exists()) {
                    $skippedExisting++;
                    continue;
                }

                $this->line("  agency #{$agency->id} ({$agency->name}): {$key} = {$value}");
                if (!$dryRun) {
                    PerformanceSetting::create(['agency_id' => $agency->id, 'key' => $key, 'value' => $value]);
                }
                $created++;
            }
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Done. Created: {$created}, skipped (agency already had a row): {$skippedExisting}, skipped (blank Agency field, nothing to propagate): {$skippedBlank}.");

        return self::SUCCESS;
    }
}
