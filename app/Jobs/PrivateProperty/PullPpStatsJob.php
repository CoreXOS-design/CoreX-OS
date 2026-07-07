<?php

namespace App\Jobs\PrivateProperty;

use App\Services\PrivateProperty\PpStatsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Nightly Private Property engagement snapshot (AT-201). Dormant unless an agency
 * has pp_stats_pull_enabled=true (gate in PpStatsService). Every failure is
 * swallowed here so a PP outage can never break the schedule or the P24 stats pull.
 */
class PullPpStatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function handle(PpStatsService $service): void
    {
        try {
            $results = $service->pullForAllAgencies();
            Log::channel('private_property')->info('PP stats snapshot complete', ['results' => $results]);
        } catch (\Throwable $e) {
            Log::channel('private_property')->error('PP stats snapshot errored', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
