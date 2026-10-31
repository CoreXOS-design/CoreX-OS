<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ProspectingSearch;
use App\Services\Prospecting\CrawlRecorder;
use Illuminate\Support\Facades\Log;
use Throwable;

/** AT-520 4.3: keeps the crawl record up to date on every saved batch. Failure-isolated - the import never fails because of it. */
final class ProspectingSearchCrawlObserver
{
    public function saved(ProspectingSearch $search): void
    {
        try {
            app(CrawlRecorder::class)->sync($search);
        } catch (Throwable $e) {
            Log::warning('MIC crawl record sync failed (swallowed)', ['search_id' => $search->id, 'message' => $e->getMessage()]);
        }
    }
}
