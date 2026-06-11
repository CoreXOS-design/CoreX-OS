<?php

namespace App\Jobs\Prospecting;

use App\Models\Property;
use App\Services\Prospecting\ProspectingStockMatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Match prospecting listings against a freshly created/updated property.
 *
 * Runs the (potentially expensive) ProspectingStockMatchService off the request
 * thread. Previously this ran synchronously inside PropertyObserver::saved(),
 * which made every property save slow and made bulk creation (e.g. the sold
 * properties import) exceed the request time limit. Queued — like the adjacent
 * MatchPropertyJob (Core Matches) — so saves stay fast and bulk inserts scale.
 *
 * No explicit queue is set, so it lands on `default`, the only queue the
 * corex workers drain.
 */
class MatchPropertyProspectingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $propertyId) {}

    public function handle(ProspectingStockMatchService $service): void
    {
        $property = Property::find($this->propertyId);
        if (!$property) {
            return;
        }

        try {
            $service->matchAllForProperty($property);
        } catch (\Throwable $e) {
            Log::warning("MatchPropertyProspectingJob failed for property #{$this->propertyId}: {$e->getMessage()}");
        }
    }
}
