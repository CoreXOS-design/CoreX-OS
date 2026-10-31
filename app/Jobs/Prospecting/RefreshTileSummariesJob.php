<?php

declare(strict_types=1);

namespace App\Jobs\Prospecting;

use App\Services\Prospecting\MicWork\NeighboursPanelProvider;
use App\Services\Prospecting\MicWork\RefreshStatusPanelProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/** AT-520 4.10: (re)calculate and store the slow MIC tile face numbers for ONE agency (tile 10). Never throws into the caller's work. */
final class RefreshTileSummariesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(public readonly int $agencyId)
    {
    }

    public function handle(NeighboursPanelProvider $tile10, RefreshStatusPanelProvider $tile11): void
    {
        try {
            $tile10->refreshSummary($this->agencyId);
            $tile11->refreshSummary($this->agencyId);
        } catch (\Throwable $e) {
            Log::warning('tile summary refresh failed', ['agency_id' => $this->agencyId, 'message' => $e->getMessage()]);
        }
    }
}
