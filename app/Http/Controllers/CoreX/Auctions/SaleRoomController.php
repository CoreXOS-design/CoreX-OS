<?php

namespace App\Http\Controllers\CoreX\Auctions;

use App\Http\Controllers\Controller;
use App\Models\AgencyAuctionSettings;
use App\Models\Auction;
use App\Models\AuctionBid;
use App\Models\AuctionBidder;
use App\Models\AuctionLot;
use App\Services\Auctions\AuctionLotStatusService;
use App\Services\Auctions\BidService;
use Illuminate\Http\Request;

/**
 * AT-432 Phase 3 — .ai/specs/auctions.md §11.1. The in-room Sale Room
 * console: one lot at a time, one-keystroke bid entry, running history,
 * a deliberate fall-of-hammer action. Online timed bidding, auto-extend,
 * proxy bidding and the offline-tolerant local queue-and-sync (§11.1's own
 * "the sale room is often a marquee with bad signal" requirement, which
 * needs .ai/specs/offline-draft-persistence.md's pattern adapted) are
 * explicitly Phase 4 / a follow-up — this is the online, in-room path only.
 */
class SaleRoomController extends Controller
{
    /**
     * The current lot is whichever one is actively under the hammer, else
     * the next one open for bids, else the next catalogued lot waiting to
     * start — in lot_number order. ?lot= overrides (the clerk may jump).
     */
    public function show(Request $request, Auction $auction)
    {
        $lot = null;
        if ($lotId = $request->query('lot')) {
            $lot = $auction->lots()->where('id', $lotId)->first();
        }
        $lot ??= $auction->lots()->where('status', AuctionLot::STATUS_UNDER_THE_HAMMER)->orderBy('lot_number')->first();
        $lot ??= $auction->lots()->where('status', AuctionLot::STATUS_OPEN_FOR_BIDS)->orderBy('lot_number')->first();
        $lot ??= $auction->lots()->where('status', AuctionLot::STATUS_CATALOGUED)->orderBy('lot_number')->first();

        $bidService = new BidService();
        $agencyId = (int) $auction->agency_id;

        return view('corex.auctions.room', [
            'auction' => $auction,
            'lot' => $lot?->load('property'),
            'lots' => $auction->lots()->orderBy('lot_number')->get(['id', 'lot_number', 'status']),
            'currentHighBid' => $lot ? $bidService->currentHighBid($lot)?->load('bidder.contact') : null,
            'suggestedNextBid' => $lot ? $bidService->suggestedNextBid($lot) : null,
            'bidHistory' => $lot ? AuctionBid::where('auction_lot_id', $lot->id)->with('bidder.contact')->orderByDesc('placed_at')->limit(20)->get() : collect(),
            'bidderCount' => $lot ? $bidService->distinctBidderCount($lot) : 0,
            'canSeeReserve' => AgencyAuctionSettings::reserveVisibilityFor($agencyId) === 'published'
                || auth()->user()->hasPermission('auctions.reserve.view'),
            'canRetract' => AgencyAuctionSettings::bidRetractionAllowedFor($agencyId) && auth()->user()->hasPermission('auctions.bid.retract'),
            'approvedBidders' => AuctionBidder::where('auction_id', $auction->id)->where('status', 'approved')->with('contact')->get(),
        ]);
    }

    public function openForBids(Auction $auction, AuctionLot $lot)
    {
        (new AuctionLotStatusService())->openForBids($lot, auth()->id());

        return redirect()->route('corex.auctions.room.show', [$auction, 'lot' => $lot->id]);
    }

    public function startHammer(Auction $auction, AuctionLot $lot)
    {
        (new AuctionLotStatusService())->startHammer($lot, auth()->id());

        return redirect()->route('corex.auctions.room.show', [$auction, 'lot' => $lot->id]);
    }

    /** One keystroke: paddle number (+ optional off-increment amount). */
    public function placeBid(Request $request, Auction $auction, AuctionLot $lot)
    {
        $data = $request->validate([
            'paddle_number' => 'required|string|max:20',
            'amount' => 'nullable|numeric|min:0.01',
        ]);

        $bidder = AuctionBidder::where('auction_id', $auction->id)->where('paddle_number', $data['paddle_number'])->first();
        if (! $bidder) {
            return back()->withErrors(['bid' => "No approved bidder holds paddle #{$data['paddle_number']} in this auction."]);
        }

        $bidService = new BidService();
        $amount = $data['amount'] ?? $bidService->suggestedNextBid($lot);
        if ($amount === null) {
            return back()->withErrors(['bid' => 'This lot has no opening bid on file — type a starting amount to open the bidding.']);
        }

        try {
            $bidService->placeBid($lot, $bidder, (float) $amount, 'in_room', auth()->id());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['bid' => $e->getMessage()]);
        }

        return redirect()->route('corex.auctions.room.show', [$auction, 'lot' => $lot->id]);
    }

    public function retractBid(Request $request, Auction $auction, AuctionLot $lot)
    {
        try {
            (new BidService())->retractLastBid($lot, auth()->id(), $request->input('reason'));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['bid' => $e->getMessage()]);
        }

        return redirect()->route('corex.auctions.room.show', [$auction, 'lot' => $lot->id]);
    }

    /** §11.1 — "a deliberate, confirmed action — never a single mis-key." The confirm step lives in the view. */
    public function knockDown(Auction $auction, AuctionLot $lot)
    {
        try {
            (new AuctionLotStatusService())->recordHammerFromCurrentBid($lot, now(), auth()->id());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['bid' => $e->getMessage()]);
        }

        $next = $auction->lots()->where('lot_number', '>', $lot->lot_number)->orderBy('lot_number')->first();

        return redirect()->route('corex.auctions.room.show', $next ? [$auction, 'lot' => $next->id] : [$auction]);
    }

    public function passIn(Request $request, Auction $auction, AuctionLot $lot)
    {
        (new AuctionLotStatusService())->markPassedIn($lot, auth()->id(), $request->input('reason'));
        $next = $auction->lots()->where('lot_number', '>', $lot->lot_number)->orderBy('lot_number')->first();

        return redirect()->route('corex.auctions.room.show', $next ? [$auction, 'lot' => $next->id] : [$auction]);
    }
}
