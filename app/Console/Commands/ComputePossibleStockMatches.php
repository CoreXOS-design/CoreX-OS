<?php

namespace App\Console\Commands;

use App\Jobs\Prospecting\ComputePossibleStockMatchJob;
use App\Models\ProspectingListing;
use Illuminate\Console\Command;

/**
 * Backfill (2026-08-22, Johan — 43 Ridge). Covers two real gaps the observer
 * alone can't:
 *   1. Listings that already existed before this feature shipped — the
 *      observer only fires on future create/update.
 *   2. Bulk-inserted listings (Chrome extension capture) — ProspectingListingObserver's
 *      own docblock: "Bulk imports bypass observers via insert() not create()."
 *
 * Dispatches the SAME queued job the observer uses — never computes inline,
 * so a large backfill run is just a burst on the 'matching' queue, not a
 * long-running foreground command.
 */
class ComputePossibleStockMatches extends Command
{
    protected $signature = 'prospecting:compute-possible-matches {--agency= : Agency ID (default: all)}';
    protected $description = 'Queue the possible-stock-match computation for every currently-unmatched, GPS-carrying prospecting listing';

    public function handle(): int
    {
        $query = ProspectingListing::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereNull('matched_property_id')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->where('latitude', '!=', 0);

        if ($agencyId = $this->option('agency')) {
            $query->where('agency_id', (int) $agencyId);
        }

        $count = 0;
        $query->select('id')->chunkById(500, function ($listings) use (&$count) {
            foreach ($listings as $listing) {
                ComputePossibleStockMatchJob::dispatch($listing->id);
                $count++;
            }
        });

        $this->info("Queued {$count} possible-match computations on the 'matching' queue.");

        return self::SUCCESS;
    }
}
