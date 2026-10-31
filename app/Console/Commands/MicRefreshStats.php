<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MarketIntelligence\Pockets\MicStatsBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MIC 5.5 (AT-519): rebuild the stored per-file counts and today's pocket stats. Nightly; safe to repeat (only caches are written).
 *   php artisan mic:refresh-stats [--agency=1]
 */
final class MicRefreshStats extends Command
{
    protected $signature = 'mic:refresh-stats {--agency= : Limit to one agency_id}';

    protected $description = 'MIC 5.5: rebuild mic_file_stats (per-file counts) and mic_pocket_stats (today) for every agency with MIC data.';

    public function handle(MicStatsBuilder $builder): int
    {
        $agencies = $this->option('agency') !== null && $this->option('agency') !== ''
            ? [(int) $this->option('agency')]
            : DB::table('prospecting_listings')->distinct()->pluck('agency_id')->map(fn ($v) => (int) $v)->all();

        foreach ($agencies as $agencyId) {
            $t = microtime(true);
            $r = $builder->refreshAgency($agencyId);
            $this->line(sprintf('agency %d: %d file rows, %d stale removed, %d pocket rows, %d scored (%.1fs)', $agencyId, $r['files'], $r['stale_removed'], $r['pockets'], $r['scored'], microtime(true) - $t));
        }

        return self::SUCCESS;
    }
}
