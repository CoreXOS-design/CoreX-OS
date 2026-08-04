<?php

namespace App\Console\Commands\Prospecting;

use App\Models\ProspectingListing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BUG 2 (MIC) — the ONLY signal CoreX has that a prospecting listing is still
 * live is re-sighting it (Chrome capture bumps last_seen_at + is_active=true
 * on every sighting; ImportP24Alerts writes a different table entirely).
 * Nothing anywhere ever flips is_active back to false, so a listing that was
 * sold/withdrawn/delisted keeps its old cached buyer-match score and sits at
 * the top of MIC forever.
 *
 * Without a live P24 status API to poll, re-sighting absence is the most
 * reliable signal buildable today: a listing not re-confirmed by ANY capture
 * within the window is presumed off-market. Self-healing — the next capture
 * that re-surfaces it resets is_active=true (ProspectingApiController::import).
 *
 * Default window: 30 days. Chosen because re-capture cadence is agent-driven
 * (Chrome extension, not a scheduled crawl) so a shorter window would false-
 * positive a genuinely-live listing an agent simply hasn't re-searched
 * recently; 30 days is long enough to absorb irregular capture cadence while
 * short enough that a delisted property doesn't rank at the top of MIC for
 * months. Configurable via --days for a manual override.
 */
class FlagStaleProspectingListings extends Command
{
    protected $signature = 'prospecting:flag-stale-listings
                            {--days=30 : Days since last_seen_at (or first_seen_at if never re-sighted) before a listing is presumed off-market}
                            {--listing=* : Restrict to specific prospecting_listings ids (targeted run)}
                            {--dry-run : Report what would be flagged without writing}';

    protected $description = 'Flag prospecting listings not re-confirmed within the window as inactive, and purge their stale buyer-match cache rows';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $listingIds = array_map('intval', $this->option('listing'));

        $query = ProspectingListing::withoutGlobalScopes()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($cutoff) {
                $q->where('last_seen_at', '<', $cutoff)
                    ->orWhere(function ($q2) use ($cutoff) {
                        $q2->whereNull('last_seen_at')->where('first_seen_at', '<', $cutoff);
                    });
            });

        if (!empty($listingIds)) {
            $query->whereIn('id', $listingIds);
        }

        $stale = $query->get(['id', 'address', 'suburb', 'last_seen_at', 'agency_id']);

        if ($stale->isEmpty()) {
            $this->info('No stale listings found.');
            return self::SUCCESS;
        }

        $this->info("Found {$stale->count()} listing(s) not re-confirmed in {$days}+ days:");
        foreach ($stale as $l) {
            $this->line("  #{$l->id} {$l->address}, {$l->suburb} — last seen: " . ($l->last_seen_at ?? 'never'));
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no changes written.');
            return self::SUCCESS;
        }

        $ids = $stale->pluck('id')->all();

        DB::transaction(function () use ($ids) {
            ProspectingListing::withoutGlobalScopes()->whereIn('id', $ids)->update(['is_active' => false]);

            // The cached score is now for an off-market listing — purge it
            // immediately rather than waiting for the next per-buyer recompute.
            DB::table('prospecting_buyer_matches')->whereIn('prospecting_listing_id', $ids)->delete();
        });

        $this->info("Flagged {$stale->count()} listing(s) inactive and purged their cached buyer matches.");

        Log::info('Flagged stale prospecting listings inactive', [
            'count' => $stale->count(),
            'days'  => $days,
            'ids'   => $ids,
        ]);

        return self::SUCCESS;
    }
}
