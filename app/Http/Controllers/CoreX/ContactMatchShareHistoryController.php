<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\ContactMatch;
use App\Services\Matching\CoreMatchShareHistoryService;
use Illuminate\Http\JsonResponse;

/**
 * AT-Core-Matches, share-history piece. Read-only surface for cc3's screen
 * to consume — this controller owns the QUERY, not the row layout. Same
 * core_matches.view gate as reading the match itself and as
 * ContactMatchShareController::record() (sharing/reading isn't the
 * privileged action here, reassignment is).
 */
class ContactMatchShareHistoryController extends Controller
{
    public function show(ContactMatch $match, CoreMatchShareHistoryService $history): JsonResponse
    {
        $shares = $history->shares($match)->map(fn ($share) => [
            'shared_at' => $share->shared_at->toIso8601String(),
            'shared_by' => $share->sharedBy?->name,
            'channel'   => $share->channel,
        ]);

        return response()->json([
            'shares'              => $shares->values(),
            'opens'               => $history->openSummary($match),
            'new_since_last_share' => $history->neverSentProperties($match)
                ->map(fn ($property) => $property->toSearchResult())
                ->values(),
        ]);
    }
}
