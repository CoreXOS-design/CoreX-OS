<?php

namespace App\Jobs\PrivateProperty;

use App\Services\PrivateProperty\PpLeadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled Private Property buyer-lead pull. Mirror of PullP24LeadsJob.
 *
 * Runs every 5 minutes but is a NO-OP unless an agency has
 * `pp_lead_pull_enabled` = true (the gate lives in PpLeadService so the
 * scheduler tick is cheap and the pull is dormant by default). Every failure
 * is swallowed here so a PP outage can never break the scheduler run or the
 * co-scheduled P24 pull.
 */
class PullPpLeadsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function handle(PpLeadService $service): void
    {
        try {
            $results = $service->pullForAllAgencies();
            Log::channel('private_property')->info('PP leads pull complete', ['results' => $results]);
        } catch (\Throwable $e) {
            Log::channel('private_property')->error('PP leads pull errored', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
