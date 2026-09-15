<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\ContactMatch;
use App\Services\Matching\CoreMatchReasonClassifier;
use App\Services\Matching\CoreMatchShareHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * AT-Core-Matches, share-history piece. Read-only surface for cc3's screen
 * to consume — this controller owns the QUERY, not the row layout. Same
 * core_matches.view gate as reading the match itself and as
 * ContactMatchShareController::confirm() (sharing/reading isn't the
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

    /**
     * The board's "Send N new" popup — same fetch-an-HTML-fragment-into-a-
     * shared-modal pattern as cc3's notes quick view, not a second
     * mechanism. Every reason here is agent-facing wording (see
     * CoreMatchReasonClassifier), including "Widened criteria", which the
     * buyer-facing page must never render — that filter lives on THAT
     * controller/view, not here, since this endpoint is agent-only by route
     * gate already.
     */
    public function newSinceQuickView(ContactMatch $match, CoreMatchShareHistoryService $history, CoreMatchReasonClassifier $classifier): View
    {
        $unseen = $history->neverSentProperties($match);
        $classified = $classifier->classify($match, $unseen);

        return view('corex.core-matches._new-since-share-quick-view', ['match' => $match, 'classified' => $classified]);
    }
}
