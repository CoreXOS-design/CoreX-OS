<?php

namespace App\Http\Controllers\CoreX\Auctions;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\AuctionRegistration;
use App\Services\Auctions\BidEngine;
use App\Services\Auctions\LotResultService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Screen S6 — the Sale Room (.ai/specs/auctions.md §5, §8.6). The auctioneer's console for a LIVE auction: put a lot on the
 * block, record bids called from the floor against a bidder number, knock the lot down or pass it in. For a timed auction it
 * is a read-only monitor (the clock runs those).
 */
class SaleRoomController extends Controller
{
    public function __construct(private BidEngine $engine, private LotResultService $results) {}

    public function show(Request $request, int $auction)
    {
        $auction = $this->auction($request, $auction);
        $lots = $auction->lots()->withTrashed(false)->with('property')->get();

        $selected = $lots->firstWhere('id', (int) $request->query('lot'))
            ?? $lots->firstWhere('status', AuctionLot::STATUS_OPEN)
            ?? $lots->first(fn ($l) => $l->status === AuctionLot::STATUS_PUBLISHED)
            ?? $lots->first();

        $bids = $selected ? $selected->bids()->with('registration.contact')->orderByDesc('id')->limit(100)->get() : collect();
        $high = $selected?->highestBid();

        return view('corex.auctions.room', [
            'auction' => $auction,
            'lots' => $lots,
            'lot' => $selected,
            'bids' => $bids,
            'high' => $high,
            'highReg' => $high ? AuctionRegistration::withoutGlobalScopes()->with('contact')->find($high->registration_id) : null,
            'minNext' => $selected && ! $selected->isConcluded() ? $this->engine->minimumNext($selected) : null,
            'approved' => AuctionRegistration::withoutGlobalScopes()->where('auction_id', $auction->id)->where('status', 'approved')->whereNull('deleted_at')->count(),
            'canOperate' => $request->user()->hasPermission('auctions.room.operate') && $auction->format === 'live',
            'canHammer' => $request->user()->hasPermission('auctions.hammer') && $auction->format === 'live',
        ]);
    }

    public function openLot(Request $request, int $auction, int $lot)
    {
        $lot = $this->lot($request, $auction, $lot);
        $this->results->openLot($lot);

        return redirect()->route('corex.auctions.room.show', [$lot->auction_id, 'lot' => $lot->id])->with('status', "Lot {$lot->lot_number} is open for bids.");
    }

    public function floorBid(Request $request, int $auction, int $lot)
    {
        $lot = $this->lot($request, $auction, $lot);
        $data = $request->validate(['bidder_number' => 'required|integer|min:1', 'amount' => 'required|numeric|min:1|max:99999999999']);

        $reg = AuctionRegistration::withoutGlobalScopes()->where('auction_id', $lot->auction_id)->whereNull('deleted_at')
            ->where('bidder_number', (int) $data['bidder_number'])->where('status', AuctionRegistration::STATUS_APPROVED)->first();
        if (! $reg) {
            throw ValidationException::withMessages(['bidder_number' => "No approved bidder has number {$data['bidder_number']} in this auction."]);
        }

        $this->engine->place($lot, $reg, (float) $data['amount'], 'floor', $request->ip());

        return redirect()->route('corex.auctions.room.show', [$lot->auction_id, 'lot' => $lot->id])
            ->with('status', 'Bid recorded: bidder #' . $reg->bidder_number . ' at R ' . number_format((float) $data['amount'], 0, '.', ' ') . '.');
    }

    public function hammer(Request $request, int $auction, int $lot)
    {
        $lot = $this->lot($request, $auction, $lot);
        $outcome = $this->results->hammer($lot);

        return redirect()->route('corex.auctions.room.show', [$lot->auction_id, 'lot' => $lot->id])->with('status', match ($outcome) {
            AuctionLot::STATUS_SOLD => "Lot {$lot->lot_number} sold.",
            AuctionLot::STATUS_SOLD_SUBJECT => "Lot {$lot->lot_number} sold subject to the seller's confirmation: the highest bid is below the reserve.",
            default => "Lot {$lot->lot_number} closed.",
        });
    }

    public function passIn(Request $request, int $auction, int $lot)
    {
        $lot = $this->lot($request, $auction, $lot);
        $request->validate(['note' => 'nullable|string|max:300']);
        $this->results->passIn($lot, $request->input('note'));

        return redirect()->route('corex.auctions.room.show', [$lot->auction_id, 'lot' => $lot->id])->with('status', "Lot {$lot->lot_number} passed in. The property was released.");
    }

    private function auction(Request $request, int $id): Auction
    {
        return Auction::visibleTo($request->user())->findOrFail($id);
    }

    private function lot(Request $request, int $auction, int $lot): AuctionLot
    {
        $auction = $this->auction($request, $auction);

        return $auction->lots()->whereKey($lot)->firstOrFail();
    }
}
