<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

/**
 * Background runner for the P24 location sync. Pulls the full SA tree
 * (~27k suburbs over ~15–30 minutes of API calls) without blocking the
 * web request. Progress is written to cache by the underlying command
 * (see SyncP24Locations::PROGRESS_KEY) and polled by the UI.
 *
 * Runs on the dedicated `p24` queue (not `default`) so workers on
 * unrelated codebases sharing the same queue table can't accidentally
 * dequeue and fail this job due to a missing class.
 *
 * Worker invocation required (supervisor or systemd):
 *   php artisan queue:work --queue=p24 --timeout=3600 --tries=1
 */
class SyncP24LocationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE_NAME = 'p24';

    public int $timeout = 3600; // 1h
    public int $tries = 1;

    public function __construct(public readonly ?int $agencyId = null)
    {
        $this->onQueue(self::QUEUE_NAME);
    }

    public function handle(): void
    {
        $args = [];
        if ($this->agencyId) {
            // Agency-scoped run: refresh/stamp only. Pruning the GLOBAL location
            // tree on one agency's P24 view is left to the authoritative daily
            // run (routes/console.php). The command's sanity floor backstops this.
            $args['--agency']   = $this->agencyId;
            $args['--no-prune'] = true;
        }
        Artisan::call('p24:sync-locations', $args);
    }
}
