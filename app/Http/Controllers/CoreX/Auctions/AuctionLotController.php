<?php

namespace App\Http\Controllers\CoreX\Auctions;

use App\Http\Controllers\Controller;
use App\Models\AgencyAuctionSettings;
use App\Models\AuctionBid;
use App\Models\AuctionLot;
use App\Models\PropertySettingItem;
use App\Services\Auctions\AuctionLotStatusService;
use Illuminate\Http\Request;

/**
 * AT-432 Phase 1 — .ai/specs/auctions.md §7 screen 4 (Lot detail) + the
 * manual result-capture actions Phase 1 needs in place of a bidding engine
 * (§21 Phase 1: "record the result, open the deal" minus the engine itself
 * — deal-opening is Phase 3, see AuctionLotSold's docblock).
 *
 * Every action below is a thin wrapper: AuctionLotStatusService owns the
 * transition, the audit trail, and the property-status sync (§6.2). This
 * controller only validates input and translates a service exception into
 * a user-facing error.
 */
class AuctionLotController extends Controller
{
    public function show(AuctionLot $lot)
    {
        $agencyId = (int) $lot->agency_id;

        return view('corex.auctions.lots.show', [
            'lot' => $lot->load(['auction', 'property', 'confirmedBy', 'statusHistory.changedBy']),
            'canSeeReserve' => AgencyAuctionSettings::reserveVisibilityFor($agencyId) === 'published'
                || auth()->user()->hasPermission('auctions.reserve.view'),
            'statusLabels' => PropertySettingItem::auctionLotStatusLabelsFor($agencyId),
            // §12.3 — "surfaces the top under-bidder with their number so the
            // agent can negotiate from a known position." Task auto-creation
            // for every registered bidder is a follow-up (needs a generic
            // task/reminder mechanism this build did not need to touch
            // elsewhere yet); this is the safely-buildable half — visibility.
            'topUnderBidders' => $lot->status === AuctionLot::STATUS_PASSED_IN
                ? AuctionBid::where('auction_lot_id', $lot->id)->whereNull('retracted_at')
                    ->with('bidder.contact')->orderByDesc('amount')->limit(5)->get()
                    ->unique('auction_bidder_id')->values()
                : collect(),
        ]);
    }

    public function openForBids(AuctionLot $lot)
    {
        return $this->run($lot, fn ($svc) => $svc->openForBids($lot, auth()->id()), 'Lot opened for bids.');
    }

    public function startHammer(AuctionLot $lot)
    {
        return $this->run($lot, fn ($svc) => $svc->startHammer($lot, auth()->id()), 'Lot is now under the hammer.');
    }

    public function recordHammer(Request $request, AuctionLot $lot)
    {
        $data = $request->validate(['hammer_price' => 'required|numeric|min:0.01']);

        return $this->run(
            $lot,
            fn ($svc) => $svc->recordHammer($lot, (float) $data['hammer_price'], now(), auth()->id()),
            'Hammer recorded.',
        );
    }

    public function confirm(AuctionLot $lot)
    {
        return $this->run($lot, fn ($svc) => $svc->confirmSaleBelowReserve($lot, auth()->id()), 'Sale confirmed by the seller.');
    }

    public function decline(Request $request, AuctionLot $lot)
    {
        $reason = $request->input('reason');

        return $this->run($lot, fn ($svc) => $svc->declineSaleBelowReserve($lot, auth()->id(), $reason), 'Sale declined — lot passed in.');
    }

    public function passedIn(Request $request, AuctionLot $lot)
    {
        $reason = $request->input('reason');

        return $this->run($lot, fn ($svc) => $svc->markPassedIn($lot, auth()->id(), $reason), 'Lot marked passed in.');
    }

    public function withdraw(Request $request, AuctionLot $lot)
    {
        $reason = $request->input('reason');

        return $this->run($lot, fn ($svc) => $svc->withdraw($lot, auth()->id(), $reason), 'Lot withdrawn.');
    }

    private function run(AuctionLot $lot, \Closure $action, string $message)
    {
        try {
            $action(new AuctionLotStatusService());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['lot' => $e->getMessage()]);
        }

        return redirect()->route('corex.auctions.lots.show', $lot)->with('status', $message);
    }
}
