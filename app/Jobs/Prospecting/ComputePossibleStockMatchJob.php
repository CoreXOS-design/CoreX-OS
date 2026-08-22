<?php

namespace App\Jobs\Prospecting;

use App\Models\ProspectingListing;
use App\Services\Prospecting\ProspectingStockMatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Computes the POSSIBLE-tier stock match (GPS + complex-coherence + unit-
 * number gate — ProspectingStockMatchService::findPossibleMatch()) for a
 * single prospecting listing, off the request thread.
 *
 * 2026-08-22 (Johan — the 43 Ridge investigation). Deliberately queued, not
 * synchronous, even though it runs from the SAME trigger points as the
 * existing (synchronous) matchProspect() Pass 1/2 check in
 * ProspectingListingObserver — this check is genuinely more expensive (a GPS
 * bounding-box query plus in-PHP grouping across potentially dozens of
 * candidates), and MatchPropertyProspectingJob's own docblock already
 * documents exactly this lesson from the reverse direction: synchronous
 * matching "made every property save slow... made bulk creation... exceed
 * the request time limit." A bulk portal-scrape ingest creating thousands of
 * listings must not pay this cost on the request thread either.
 *
 * Runs on the SAME 'matching' queue as MatchPropertyProspectingJob — one
 * dedicated queue for both directions of this work, draining after
 * 'default' so it can never starve time-sensitive sync/push/confirm jobs.
 *
 * Never touches the MIC list page's read path — findPossibleMatch()'s
 * result is written to prospecting_listings.possible_* columns, which the
 * list page (ProspectingListingResolver) reads for free as part of the row
 * it already fetches. No new query on that path.
 */
class ComputePossibleStockMatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE_NAME = 'matching';

    public function __construct(public int $listingId)
    {
        $this->onQueue(self::QUEUE_NAME);
    }

    public function handle(ProspectingStockMatchService $service): void
    {
        $listing = ProspectingListing::withoutGlobalScopes()->find($this->listingId);
        if (!$listing) {
            return;
        }

        try {
            $result = $service->findPossibleMatch($listing);
            $service->setPossibleMatch($listing, $result);
        } catch (\Throwable $e) {
            Log::warning("ComputePossibleStockMatchJob failed for listing #{$this->listingId}: {$e->getMessage()}");
        }
    }
}
