<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class ImportP24AlertsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function handle(): void
    {
        Log::info('ImportP24AlertsJob: starting P24 email import');

        $exitCode = Artisan::call('p24:import');

        if ($exitCode !== 0) {
            Log::error('ImportP24AlertsJob: p24:import exited with code ' . $exitCode);
        } else {
            Log::info('ImportP24AlertsJob: p24:import completed successfully');
        }
    }
}
