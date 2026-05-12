<?php

namespace App\Observers;

use App\Models\ProspectingListing;
use App\Services\PropertyMatchScoringService;
use App\Services\Prospecting\ProspectingStockMatchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProspectingListingObserver
{
    /**
     * On new capture: score against all agency buyers with preferences.
     * Option A chosen (synchronous V1) — acceptable for single-listing operations.
     * Bulk imports (Chrome ext) bypass observers via insert() not create().
     */
    public function created(ProspectingListing $listing): void
    {
        $this->recomputeAndNotify($listing);
        $this->matchStock($listing);
    }

    /**
     * On update of match-relevant fields: rescore.
     */
    public function updated(ProspectingListing $listing): void
    {
        $dirty = $listing->getDirty();
        $matchFields = ['price', 'bedrooms', 'bathrooms', 'suburb', 'property_type', 'erf_size_m2'];

        if (!empty(array_intersect(array_keys($dirty), $matchFields))) {
            $this->recomputeAndNotify($listing);
        }

        if (isset($dirty['normalized_address']) || isset($dirty['suburb'])) {
            $this->matchStock($listing);
        }
    }

    private function recomputeAndNotify(ProspectingListing $listing): void
    {
        try {
            $svc = app(PropertyMatchScoringService::class);
            $matchCount = $svc->recomputeProspectingMatches($listing->id);

            if ($matchCount > 0) {
                Log::channel('single')->info('Prospecting matches computed', [
                    'listing_id' => $listing->id,
                    'address' => $listing->address,
                    'matches' => $matchCount,
                ]);

                // Notify agents for high-score matches (>=80)
                $this->notifyAgentsForHighMatches($listing);
            }
        } catch (\Throwable $e) {
            Log::warning('Prospecting match recompute failed', [
                'listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create in-app notifications for agents whose buyers match this listing at score >=80.
     */
    private function notifyAgentsForHighMatches(ProspectingListing $listing): void
    {
        $highMatches = DB::table('prospecting_buyer_matches as m')
            ->join('contacts as c', 'c.id', '=', 'm.contact_id')
            ->where('m.prospecting_listing_id', $listing->id)
            ->where('m.score', '>=', 80)
            ->whereNull('m.agent_notified_at')
            ->get(['m.id as match_id', 'm.score', 'm.contact_id', 'c.created_by_user_id', 'c.first_name', 'c.last_name']);

        $agentMatches = $highMatches->groupBy('created_by_user_id');
        $now = now();

        foreach ($agentMatches as $agentId => $matches) {
            if (!$agentId) continue;

            $buyerNames = $matches->map(fn($m) => trim($m->first_name . ' ' . $m->last_name))->take(3)->implode(', ');
            $topScore = $matches->max('score');

            DB::table('notifications')->insert([
                'id' => \Illuminate\Support\Str::uuid(),
                'type' => 'prospecting_match_alert',
                'notifiable_type' => 'App\\Models\\User',
                'notifiable_id' => $agentId,
                'data' => json_encode([
                    'message' => "New listing match ({$topScore}%): {$listing->address} — matches {$buyerNames}",
                    'prospecting_listing_id' => $listing->id,
                    'match_count' => $matches->count(),
                    'top_score' => $topScore,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Mark as notified
            DB::table('prospecting_buyer_matches')
                ->whereIn('id', $matches->pluck('match_id')->toArray())
                ->update(['agent_notified_at' => $now]);
        }
    }

    private function matchStock(ProspectingListing $listing): void
    {
        try {
            app(ProspectingStockMatchService::class)->matchProspect($listing);
        } catch (\Throwable $e) {
            Log::warning('Prospecting stock match failed', [
                'listing_id' => $listing->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
