<?php

namespace App\Http\Controllers\Api\V1\Auctions;

use App\Http\Controllers\Controller;
use App\Models\AuctionLot;
use App\Models\AuctionRegistration;
use App\Services\Auctions\BidEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/v1/auctions/lots/{lot}/feed — the Sale Room's live view of one lot (staff session; .ai/specs/auctions.md §11). */
class AuctionLotFeedController extends Controller
{
    public function show(Request $request, BidEngine $engine, int $lot): JsonResponse
    {
        $lot = AuctionLot::visibleTo($request->user())->with('auction')->findOrFail($lot);
        $canReserve = $request->user()->hasPermission('auctions.reserve.view');

        $state = $engine->state($lot);
        $bids = $lot->bids()->orderByDesc('id')->limit(100)->get();
        $regs = AuctionRegistration::withoutGlobalScopes()->with('contact')->whereIn('id', $bids->pluck('registration_id'))->get()->keyBy('id');

        return response()->json([
            'state' => $state + ['reserve_amount' => $canReserve && $lot->reserve_price !== null ? (float) $lot->reserve_price : null],
            'bids' => $bids->map(fn ($b) => [
                'id' => $b->id,
                'amount' => (float) $b->amount,
                'placed_at' => $b->placed_at->toIso8601String(),
                'source' => $b->source,
                'status' => $b->status,
                'bidder_number' => $regs->get($b->registration_id)?->bidder_number,
                'bidder' => $regs->get($b->registration_id)?->displayName(),
            ])->values(),
        ]);
    }
}
