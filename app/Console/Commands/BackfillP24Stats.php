<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Services\Syndication\Property24\P24StatsService;
use Illuminate\Console\Command;

/**
 * One-time / on-demand deep backfill of Property24 per-listing statistics
 * (views + historical lead counts) into property_portal_metrics. The nightly
 * PullP24StatsJob only refreshes a short recent window; this pulls the full
 * retained history (P24 serves ~up to 6 months) so a listing that has been live
 * for a while shows its whole engagement curve immediately.
 *
 *   php artisan p24:backfill-stats                 # all agencies, 180 days
 *   php artisan p24:backfill-stats --days=90
 *   php artisan p24:backfill-stats --agency=1
 *   php artisan p24:backfill-stats --property=5942
 *
 * Safe to re-run — upserts are idempotent on (property, portal, day). Long
 * running: pace it in the background (calls are throttled + retried in the
 * service). See .ai/specs/portal-metrics.md.
 */
class BackfillP24Stats extends Command
{
    protected $signature = 'p24:backfill-stats
        {--days=180 : How many days back to pull (capped at 180 by the API)}
        {--agency= : Restrict to a single agency id}
        {--property= : Backfill just one property id}
        {--pause=800 : Milliseconds between P24 calls — raise for a gentler sweep}';

    protected $description = 'Backfill Property24 listing statistics (views + lead counts) into property_portal_metrics.';

    public function handle(P24StatsService $service): int
    {
        $days = (int) $this->option('days');
        $service->setPacing((int) $this->option('pause'));

        // Single-property fast path.
        if ($propertyId = $this->option('property')) {
            $property = Property::withoutGlobalScope(AgencyScope::class)->find($propertyId);
            if (! $property) {
                $this->error("Property {$propertyId} not found.");
                return self::FAILURE;
            }
            $this->info("Backfilling property {$propertyId} ({$days}d)…");
            $res = $service->pullForProperty($property, $days);
            $this->line('  ' . json_encode($res));
            return self::SUCCESS;
        }

        // Agency scope: one, or every agency with P24 credentials.
        if ($agencyId = $this->option('agency')) {
            $agencies = Agency::where('id', $agencyId)->get();
        } else {
            $agencies = Agency::whereNotNull('p24_username')->where('p24_username', '!=', '')->get();
        }

        if ($agencies->isEmpty()) {
            $this->warn('No matching agencies with P24 credentials.');
            return self::SUCCESS;
        }

        $grand = ['listings' => 0, 'upserted' => 0, 'skipped' => 0, 'errors' => 0];
        foreach ($agencies as $agency) {
            $this->info("Agency {$agency->id} ({$agency->name}) — backfilling {$days}d…");
            $res = $service->pullForAgency($agency, $days);
            foreach ($grand as $k => $_) {
                $grand[$k] += (int) ($res[$k] ?? 0);
            }
            $this->line("  listings={$res['listings']} upserted={$res['upserted']} skipped={$res['skipped']} errors={$res['errors']}");
        }

        $this->info("Done. Totals: " . json_encode($grand));
        return self::SUCCESS;
    }
}
