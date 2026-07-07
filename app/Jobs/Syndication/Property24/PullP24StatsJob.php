<?php

namespace App\Jobs\Syndication\Property24;

use App\Services\Syndication\Property24\P24StatsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled daily pull of Property24 per-listing statistics (views, alerts,
 * lead breakdown) into property_portal_metrics. Mirrors PullP24LeadsJob. See
 * .ai/specs/portal-metrics.md.
 */
class PullP24StatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    // Stale-first sweep polls up to NIGHTLY_MAX_LISTINGS (1500) with a 20s fail-fast
    // per call; 1h ceiling covers a bad-handshake night without ever hanging (AT-200).
    public int $timeout = 3600;

    public function handle(P24StatsService $service): void
    {
        try {
            $results = $service->pullForAllAgencies();
            Log::channel('property24')->info('P24 stats pull complete', ['results' => $results]);
        } catch (\Throwable $e) {
            Log::channel('property24')->error('P24 stats pull errored', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
