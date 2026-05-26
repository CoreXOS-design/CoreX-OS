<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\ProspectingListing;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single-call data source for the F.4 detail slide-over panel.
 *
 * Pulls everything the slide-over needs in one bounded set of queries:
 *   - header bundle (display address, source badges, action-bar state)
 *   - overview bundle (summary text, top-5 buyers, market position, latest activity)
 *   - buyers bundle (all matched buyers + tier counts)
 *   - activity bundle (pitch history + parsed claim notes timeline)
 *   - source bundle (TP source chain + external refs)
 *   - market bundle (placeholder for F.6)
 *
 * Stays inside the budgeted query count (≤ 12) by reusing already-loaded
 * relations on the listing model and delegating to existing services for
 * buyer-tier counts.
 *
 * Spec: build-f-market-intelligence-redesign-spec.md §8.5.
 */
final class PropertyIntelligencePanelService
{
    public function __construct(
        private readonly BuyerMatchTierService $tierService,
    ) {}

    public function load(ProspectingListing $listing, int $agencyId, ?User $viewer): array
    {
        $viewerId = $viewer?->id !== null ? (int) $viewer->id : null;

        // Header — derived purely from the listing row + small joins.
        $header = $this->buildHeader($listing);

        // Buyers (used in Overview top-5 AND Buyers tab full list).
        $allBuyers = $this->tierService->buyersForListing((int) $listing->id, $agencyId, 1000);
        $topBuyers = $allBuyers->take(5);
        $tierCounts = $this->tierService->tiersForListings([(int) $listing->id], $agencyId)[(int) $listing->id]
            ?? ['strong' => 0, 'mid' => 0, 'weak' => 0, 'total' => 0, 'top_score' => null];

        // Activity (pitches + claim notes timeline).
        $pitches = $this->loadPitchHistory($listing, $agencyId);
        $claimNotes = $this->loadClaimNotesTimeline($listing, $agencyId);

        // Source (TP chain + external refs).
        $trackedProperty = null;
        $sourceChain = [];
        $externalRefs = collect();
        if ($listing->tracked_property_id) {
            $trackedProperty = DB::table('tracked_properties')
                ->where('id', $listing->tracked_property_id)
                ->whereNull('deleted_at')
                ->first();
            if ($trackedProperty && $trackedProperty->source_chain) {
                $decoded = json_decode($trackedProperty->source_chain, true);
                $sourceChain = is_array($decoded) ? $decoded : [];
            }
            $externalRefs = collect(DB::table('tracked_property_external_refs')
                ->where('tracked_property_id', $listing->tracked_property_id)
                ->whereNull('deleted_at')
                ->orderBy('first_seen_at')
                ->get());
        }

        // Market position (suburb median context for the summary band).
        $marketPosition = $this->buildMarketPosition($listing, $agencyId);

        // Latest activity merges 3-5 recent events for the Overview band.
        $latestActivity = $this->buildLatestActivity($listing, $pitches, $claimNotes);

        // Templated summary (F.6 replaces with Ellie output).
        $summary = $this->buildSummary($listing, $pitches->first(), $tierCounts, $marketPosition);

        return [
            'header'   => $header,
            'overview' => [
                'summary'         => $summary,
                'top_buyers'      => $topBuyers,
                'market_position' => $marketPosition,
                'latest_activity' => $latestActivity,
            ],
            'buyers' => [
                'all'            => $allBuyers,
                'tier_breakdown' => $tierCounts,
            ],
            'activity' => [
                'pitches' => $pitches,
                'claims'  => $claimNotes,
                'calls'   => collect(), // E.5 deliverable
            ],
            'source' => [
                'tracked_property' => $trackedProperty,
                'chain'            => $sourceChain,
                'external_refs'    => $externalRefs,
            ],
            'market'  => null, // F.6 fills this
            'viewer'  => [
                'id'         => $viewerId,
                'is_manager' => $viewer && method_exists($viewer, 'hasPermission')
                    ? (bool) $viewer->hasPermission('prospecting_setup.manage')
                    : false,
                'can_pitch'  => $viewer && method_exists($viewer, 'hasPermission')
                    ? (bool) $viewer->hasPermission('outreach.compose')
                    : false,
            ],
        ];
    }

    protected function buildHeader(ProspectingListing $listing): array
    {
        $primaryPhoto = $listing->thumbnail_path
            ? route('market-intelligence.thumbnail', $listing)
            : null;

        return [
            'photo_url'    => $primaryPhoto,
            'address'      => $listing->address ?: 'Address not available',
            'suburb'       => $listing->suburb,
            'beds'         => $listing->bedrooms,
            'baths'        => $listing->bathrooms,
            'garages'      => $listing->garages,
            'property_type'=> $listing->property_type,
            'agency'       => $listing->agency_name,
            'portal_ref'   => $listing->portal_ref,
            'portal_url'   => $listing->portal_url,
            'price'        => $listing->price,
            'in_stock'     => $listing->matched_property_id !== null,
            'matched_property_id' => $listing->matched_property_id,
            'tracked_property_id' => $listing->tracked_property_id,
            'is_active'    => $listing->is_active,
            'first_seen_at'=> $listing->first_seen_at,
        ];
    }

    protected function buildSummary(
        ProspectingListing $listing,
        ?object $latestPitch,
        array $tierCounts,
        array $marketPosition,
    ): string {
        $parts = [];

        if ($latestPitch) {
            $when = Carbon::parse($latestPitch->sent_at)->diffForHumans();
            $channel = $latestPitch->channel ?? 'message';
            $outcome = $latestPitch->outcome ?? 'sent';
            $outcomeNote = ($outcome === 'sent' || $outcome === null)
                ? 'seller hasn\'t responded'
                : 'outcome: ' . str_replace('_', ' ', (string) $outcome);
            $parts[] = "Pitched {$when} via {$channel} — {$outcomeNote}.";
        }

        if (($tierCounts['strong'] ?? 0) > 0) {
            $top = $tierCounts['top_score'] !== null ? " (top match {$tierCounts['top_score']}%)" : '';
            $parts[] = "{$tierCounts['strong']} strong-tier buyer"
                . ($tierCounts['strong'] === 1 ? '' : 's') . $top . '.';
        }

        if (!empty($marketPosition['this_vs_median'])) {
            $delta = $marketPosition['this_vs_median'];
            $direction = $delta > 0 ? 'above' : 'below';
            $magnitude = abs(round($delta));
            $bed = $listing->bedrooms ? $listing->bedrooms . '-bed' : '';
            $suburb = $listing->suburb ?? 'the area';
            $parts[] = "Priced {$magnitude}% {$direction} {$suburb} {$bed} median.";
        }

        return $parts ? implode(' ', $parts) : 'New canvassing opportunity — no pitch history yet.';
    }

    protected function buildMarketPosition(ProspectingListing $listing, int $agencyId): array
    {
        $suburb = $listing->suburb;
        $beds = $listing->bedrooms;
        if (!$suburb || !$beds) {
            return ['suburb_median' => null, 'this_vs_median' => null, 'yoy_trend' => null];
        }

        // Suburb median asking — last 180 days, same suburb + same bedroom count.
        // MySQL returns avg() as a numeric string — cast to float before any math.
        $median = DB::table('prospecting_listings')
            ->where('agency_id', $agencyId)
            ->where('suburb', $suburb)
            ->where('bedrooms', $beds)
            ->where('is_active', true)
            ->whereNotNull('price')
            ->where('first_seen_at', '>=', now()->subDays(180))
            ->avg('price');
        $median = $median !== null ? (float) $median : null;

        $yoyMedian = DB::table('prospecting_listings')
            ->where('agency_id', $agencyId)
            ->where('suburb', $suburb)
            ->where('bedrooms', $beds)
            ->whereNotNull('price')
            ->whereBetween('first_seen_at', [now()->subDays(545), now()->subDays(365)])
            ->avg('price');
        $yoyMedian = $yoyMedian !== null ? (float) $yoyMedian : null;

        $thisPrice = $listing->price !== null ? (float) $listing->price : null;

        $thisVsMedian = ($median && $thisPrice)
            ? (($thisPrice - $median) / $median) * 100
            : null;
        $yoyTrend = ($median && $yoyMedian)
            ? (($median - $yoyMedian) / $yoyMedian) * 100
            : null;

        return [
            'suburb_median'  => $median ? (int) round($median) : null,
            'this_vs_median' => $thisVsMedian !== null ? round($thisVsMedian, 1) : null,
            'yoy_trend'      => $yoyTrend !== null ? round($yoyTrend, 1) : null,
        ];
    }

    protected function buildLatestActivity(
        ProspectingListing $listing,
        Collection $pitches,
        Collection $claimNotes,
    ): Collection {
        $events = collect();

        // First seen
        if ($listing->first_seen_at) {
            $events->push([
                'kind'     => 'first_seen',
                'at'       => $listing->first_seen_at,
                'actor'    => 'system',
                'summary'  => 'Listing first captured from ' . strtoupper($listing->portal_source ?? 'portal'),
            ]);
        }

        // Pitches
        foreach ($pitches as $p) {
            $events->push([
                'kind'    => 'pitch',
                'at'      => $p->sent_at ? Carbon::parse($p->sent_at) : null,
                'actor'   => $p->agent_name ?? 'agent',
                'summary' => 'Sent ' . ($p->channel ?? 'message') . ' pitch'
                            . ($p->template_name ? ' (template: ' . $p->template_name . ')' : ''),
                'outcome' => $p->outcome,
            ]);
        }

        // Claim notes
        foreach ($claimNotes as $note) {
            $events->push([
                'kind'    => 'claim_note',
                'at'      => $note['at'],
                'actor'   => $note['actor'] ?? 'agent',
                'summary' => $note['text'],
            ]);
        }

        return $events->filter(fn ($e) => $e['at'] !== null)
            ->sortByDesc(fn ($e) => $e['at'])
            ->take(6)
            ->values();
    }

    protected function loadPitchHistory(ProspectingListing $listing, int $agencyId): Collection
    {
        // Pitches join via property_id (= matched_property_id). For non-stock
        // listings (matched_property_id IS NULL) there is no pitch history yet.
        if (!$listing->matched_property_id) {
            return collect();
        }

        return collect(DB::table('seller_outreach_sends as s')
            ->leftJoin('seller_outreach_templates as t', 't.id', '=', 's.template_id')
            ->leftJoin('users as u', 'u.id', '=', 's.agent_id')
            ->where('s.agency_id', $agencyId)
            ->where('s.property_id', $listing->matched_property_id)
            ->whereNull('s.deleted_at')
            ->select(
                's.id',
                's.contact_id',
                's.agent_id',
                's.sent_at',
                's.channel',
                's.outcome',
                's.outcome_note',
                's.recipient_phone_snapshot as recipient_phone',
                't.name as template_name',
                'u.name as agent_name',
            )
            ->orderByDesc('s.sent_at')
            ->get());
    }

    /**
     * Parses the canonical `[YYYY-MM-DD HH:MM] message` newline-prepended
     * format that ProspectingClaimService::recordActionOnClaim writes.
     * Returns ordered (most recent first) collection of ['at', 'actor', 'text'].
     */
    protected function loadClaimNotesTimeline(ProspectingListing $listing, int $agencyId): Collection
    {
        $claims = DB::table('prospecting_claims as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.prospecting_listing_id', $listing->id)
            ->where('c.agency_id', $agencyId)
            ->whereNull('c.deleted_at')
            ->select(
                'c.id',
                'c.user_id',
                'c.status',
                'c.notes',
                'c.claimed_at',
                'c.feedback_at',
                'c.released_at',
                'u.name as user_name',
            )
            ->orderByDesc('c.claimed_at')
            ->get();

        $entries = collect();
        foreach ($claims as $claim) {
            // Claim-creation event
            if ($claim->claimed_at) {
                $entries->push([
                    'at'    => Carbon::parse($claim->claimed_at),
                    'actor' => $claim->user_name ?? 'agent',
                    'text'  => 'Claimed (status: ' . $claim->status . ')',
                ]);
            }
            // Released event
            if ($claim->released_at) {
                $entries->push([
                    'at'    => Carbon::parse($claim->released_at),
                    'actor' => $claim->user_name ?? 'agent',
                    'text'  => 'Released claim',
                ]);
            }
            // Notes entries — parse the `[YYYY-MM-DD HH:MM] message` format.
            if ($claim->notes) {
                $lines = preg_split('/\n+/', (string) $claim->notes);
                foreach ($lines as $line) {
                    if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2})\]\s*(.+)$/', trim($line), $m)) {
                        $entries->push([
                            'at'    => Carbon::parse($m[1]),
                            'actor' => $claim->user_name ?? 'agent',
                            'text'  => $m[2],
                        ]);
                    }
                }
            }
        }

        return $entries->sortByDesc(fn ($e) => $e['at'])->values();
    }
}
