<?php

namespace App\Http\Controllers\CoreX\Auctions;

use App\Http\Controllers\Controller;
use App\Models\AgencyAuctionSettings;
use App\Models\AuctionBid;
use App\Models\AuctionLot;
use App\Models\AuctionLotViewing;
use App\Models\PropertySettingItem;
use App\Services\Auctions\AuctionDealFactory;
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
            'lot' => $lot->load(['auction', 'property', 'confirmedBy', 'statusHistory.changedBy', 'viewings.agent']),
            'canSeeReserve' => AgencyAuctionSettings::reserveVisibilityFor($agencyId) === 'published'
                || auth()->user()->hasPermission('auctions.reserve.view'),
            'statusLabels' => PropertySettingItem::auctionLotStatusLabelsFor($agencyId),
            'advertisingOnly' => AgencyAuctionSettings::advertisingOnlyFor($agencyId),
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

    /**
     * Advertising-only mode (.ai/specs/auctions-advertising-mode.md §5): the
     * sale happened elsewhere, so the agent just records the outcome. The
     * state machine is NOT bypassed — a catalogued lot is walked through
     * open → hammer → result inside one transaction, so the history, the
     * property status and the deal-creation event all behave exactly as they
     * do after a Sale Room sale.
     */
    public function recordResult(Request $request, AuctionLot $lot)
    {
        $data = $request->validate([
            'outcome' => 'required|in:sold,passed_in,withdrawn',
            'hammer_price' => 'required_if:outcome,sold|nullable|numeric|min:0.01',
            'reason' => 'nullable|string|max:500',
        ]);

        return $this->run($lot, function ($svc) use ($lot, $data) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($svc, $lot, $data) {
                if ($data['outcome'] === 'withdrawn') {
                    $svc->withdraw($lot, auth()->id(), $data['reason'] ?? null);
                    return;
                }
                if ($lot->status === AuctionLot::STATUS_CATALOGUED) {
                    $svc->openForBids($lot, auth()->id());
                    $lot->refresh();
                }
                if ($lot->status === AuctionLot::STATUS_OPEN_FOR_BIDS) {
                    $svc->startHammer($lot, auth()->id());
                    $lot->refresh();
                }
                if ($data['outcome'] === 'sold') {
                    $svc->recordHammer($lot, (float) $data['hammer_price'], now(), auth()->id());
                } else {
                    $svc->markPassedIn($lot, auth()->id(), $data['reason'] ?? null);
                }
            });
        }, 'Result recorded.');
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

    /**
     * Manual retry path for CreateDealOnLotSold (§17) — that listener
     * silently logs and gives up on failure (most commonly: no seller
     * contact linked to the property yet, DealPropertyOwnerGate) rather
     * than breaking the fall-of-hammer transaction. This button is how the
     * agent finishes the job once they've fixed whatever blocked it, and is
     * also simply how a Phase-1-recorded sale (no bidding engine, hammer
     * entered manually) gets its deal — CreateDealOnLotSold only fires from
     * a REAL bid's AuctionLotSold; a lot sold via the plain recordHammer()
     * path (Phase 1, still supported) has no bid behind it to trigger from.
     */
    public function openDeal(AuctionLot $lot)
    {
        if ($lot->deal_id) {
            return back()->withErrors(['lot' => "This lot already carries deal #{$lot->deal_id}."]);
        }

        try {
            $deal = app(AuctionDealFactory::class)->createFromSoldLot($lot, auth()->id());
        } catch (\Throwable $e) {
            return back()->withErrors(['lot' => 'Could not open the deal: '.$e->getMessage()]);
        }

        return redirect()->route('corex.auctions.lots.show', $lot)->with('status', "Deal #{$deal->deal_no} opened.");
    }

    /** AT-432 Phase 5 — .ai/specs/auctions.md §5.6. Publish a viewing window ahead of the sale. */
    public function addViewing(Request $request, AuctionLot $lot)
    {
        $data = $request->validate([
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
            'is_by_appointment' => 'nullable|boolean',
            'notes' => 'nullable|string|max:500',
            'agent_id' => 'nullable|integer|exists:users,id',
        ]);

        AuctionLotViewing::create([
            'agency_id' => $lot->agency_id,
            'auction_lot_id' => $lot->id,
            'agent_id' => $data['agent_id'] ?? auth()->id(),
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'is_by_appointment' => (bool) ($data['is_by_appointment'] ?? false),
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('corex.auctions.lots.show', $lot)->with('status', 'Viewing added.');
    }

    public function removeViewing(AuctionLot $lot, AuctionLotViewing $viewing)
    {
        if ((int) $viewing->auction_lot_id !== (int) $lot->id) {
            abort(404);
        }
        $viewing->delete();

        return redirect()->route('corex.auctions.lots.show', $lot)->with('status', 'Viewing removed.');
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
