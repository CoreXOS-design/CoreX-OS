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
 */
class SyncP24LocationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600; // 1h
    public int $tries = 1;

    public function __construct(public readonly ?int $agencyId = null) {}

    public function handle(): void
    {
        $args = [];
        if ($this->agencyId) {
            $args['--agency'] = $this->agencyId;
        }
        Artisan::call('p24:sync-locations', $args);
    }
}
