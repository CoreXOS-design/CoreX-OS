<?php

namespace App\Services\RoadToSold\Selections;

use App\Services\RoadToSold\ConveyorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The day feed's first source (road-to-sold.md 23.24): the agent's OWN claimed properties, still at the Claimed station and not on the day yet. The agent has already
 * decided these are worth the work, so they come before buyers, hot pockets and fill-ups. Late ones first (the next step is past its date or window), then the ones
 * closest to the end of their claim hold, then the oldest claim. It is not one of the agency's reorderable sources: a single agency switch ("Fill my day from my claims
 * first", on by default) turns it on or off, and the builder asks it before the others.
 */
final class MyClaimsSelection implements Selection
{
    public const KEY = 'my_claims';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Your claims';
    }

    public function candidates(SelectionContext $ctx, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        $conveyor = app(ConveyorService::class);
        $claimed = $conveyor->records($ctx->user, $ctx->track, $ctx->date)->where('station', 'claimed')
            ->reject(fn ($r) => in_array($r['tracked_property_id'], $ctx->excludeTrackedIds, true))->values();
        if ($claimed->isEmpty()) {
            return [];
        }
        $ids = $claimed->pluck('id')->all();
        $holds = Schema::hasColumn('prospecting_claims', 'hold_until')
            ? DB::table('prospecting_claims')->whereIn('rts_prospect_id', $ids)->where('agency_id', $ctx->agencyId)->where('is_active', 1)->whereNull('released_at')->whereNull('deleted_at')
                ->groupBy('rts_prospect_id')->selectRaw('rts_prospect_id, MIN(hold_until) as hold_until')->pluck('hold_until', 'rts_prospect_id')
            : collect();
        $links = DB::table('rts_prospects')->whereIn('id', $ids)->get(['id', 'prospecting_listing_id', 'property_id'])->keyBy('id');

        $ordered = $claimed->sort(function ($a, $b) use ($holds) {
            $ha = $holds[$a['id']] ?? null;
            $hb = $holds[$b['id']] ?? null;

            // late first, then the nearest end of the hold (no clock last), then the oldest claim, then id (stable)
            return [(int) ! $a['late'], $ha === null ? 1 : 0, (string) $ha, (string) $a['since'], $a['id']]
                <=> [(int) ! $b['late'], $hb === null ? 1 : 0, (string) $hb, (string) $b['since'], $b['id']];
        })->values();

        $out = [];
        foreach ($ordered as $r) {
            if (count($out) >= $limit) {
                break;
            }
            $link = $links[$r['id']] ?? null;
            $out[] = new Candidate(
                trackedPropertyId: $r['tracked_property_id'],
                prospectingListingId: $link && $link->prospecting_listing_id ? (int) $link->prospecting_listing_id : null,
                propertyId: $link && $link->property_id ? (int) $link->property_id : null,
                selection: self::KEY,
                reason: $conveyor->claimLine($r['id']),
                hasAddress: trim((string) $r['title']) !== '',
                inArea: true,
                facts: ['prospect_id' => $r['id'], 'late' => (bool) $r['late'], 'hold_until' => $holds[$r['id']] ?? null],
            );
        }

        return $out;
    }
}
