<?php

namespace App\Jobs\Syndication\Property24;

use App\Services\Syndication\Property24\P24LeadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PullP24LeadsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function handle(P24LeadService $service): void
    {
        try {
            $results = $service->pullForAllAgencies();
            Log::channel('property24')->info('P24 leads pull complete', ['results' => $results]);
        } catch (\Throwable $e) {
            Log::channel('property24')->error('P24 leads pull errored', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
