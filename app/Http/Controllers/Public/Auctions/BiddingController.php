<?php

namespace App\Http\Controllers\Public\Auctions;

use App\Http\Controllers\Controller;
use App\Models\AuctionLot;
use App\Models\AuctionRegistration;
use App\Models\AuctionWatch;
use App\Models\ClientUser;
use App\Services\Auctions\AuctionRegistrationService;
use App\Services\Auctions\BidEngine;
use App\Services\Auctions\BuyerAccountService;
use App\Services\Auctions\PublicAuctionSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The live bidding API behind the public lot page (.ai/specs/auctions.md §8.4-8.5, §11). JSON under /api/v1/auctions/public.
 * The feed is open to anyone (it carries no personal data); placing a bid, an auto-bid or watching needs a signed-in buyer
 * with an approved registration (watching needs only the account).
 */
class BiddingController extends Controller
{
    public function __construct(
        private BidEngine $engine,
        private BuyerAccountService $buyers,
        private AuctionRegistrationService $registrations,
        private PublicAuctionSite $site,
    ) {}

    public function feed(int $lot): JsonResponse
    {
        $lot = $this->publicLot($lot);
        $buyer = $this->buyers->current();
        $reg = $buyer ? $this->approvedRegistration($lot, $buyer) : null;

        return response()->json($this->payload($lot, $reg, $buyer));
    }

    public function bid(Request $request, int $lot): JsonResponse
    {
        [$lot, $reg] = $this->bidder($lot);
        $data = $request->validate(['amount' => 'required|numeric|min:1|max:99999999999']);

        $this->engine->place($lot, $reg, (float) $data['amount'], 'online', $request->ip());

        return response()->json($this->payload($lot->refresh(), $reg, $this->buyers->current()) + ['ok' => true]);
    }

    public function autoBid(Request $request, int $lot): JsonResponse
    {
        [$lot, $reg] = $this->bidder($lot);
        $data = $request->validate(['max' => 'required|numeric|min:1|max:99999999999']);

        $this->engine->setAutoBid($lot, $reg, (float) $data['max'], $request->ip());

        return response()->json($this->payload($lot->refresh(), $reg, $this->buyers->current()) + ['ok' => true]);
    }

    public function cancelAutoBid(int $lot): JsonResponse
    {
        [$lot, $reg] = $this->bidder($lot);
        $this->engine->cancelAutoBid($lot, $reg);

        return response()->json($this->payload($lot->refresh(), $reg, $this->buyers->current()) + ['ok' => true]);
    }

    /** Add the lot to My account, or take it off again. */
    public function watch(int $lot): JsonResponse
    {
        $lot = $this->publicLot($lot);
        $buyer = $this->buyers->current();
        abort_unless($buyer, 401, 'Please log in to watch a lot.');

        $existing = AuctionWatch::withoutGlobalScopes()->whereNull('deleted_at')->where('lot_id', $lot->id)->where('client_user_id', $buyer->id)->first();
        if ($existing) {
            $existing->delete();
        } else {
            AuctionWatch::withoutAgencyStamping(fn () => AuctionWatch::create(['agency_id' => $lot->agency_id, 'lot_id' => $lot->id, 'client_user_id' => $buyer->id]));
        }

        return response()->json(['ok' => true, 'watching' => $existing === null]);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** @return array{0: AuctionLot, 1: AuctionRegistration} */
    private function bidder(int $lotId): array
    {
        $lot = $this->publicLot($lotId);
        $buyer = $this->buyers->current();
        abort_unless($buyer, 401, 'Please log in to bid.');

        $reg = $this->registrations->forBuyer($lot->auction()->withTrashed()->withoutGlobalScopes()->firstOrFail(), $buyer);
        if (! $reg || ! $reg->isApproved()) {
            abort(403, $reg ? 'Your registration is not approved yet.' : 'Register for this auction before you bid.');
        }

        return [$lot, $reg];
    }

    private function approvedRegistration(AuctionLot $lot, ClientUser $buyer): ?AuctionRegistration
    {
        $reg = AuctionRegistration::withoutGlobalScopes()->where('auction_id', $lot->auction_id)->where('client_user_id', $buyer->id)->first();

        return $reg;
    }

    /** A lot is addressable here only while it is on the public site. */
    private function publicLot(int $id): AuctionLot
    {
        $lot = AuctionLot::withoutGlobalScopes()->whereNull('deleted_at')->with(['auction' => fn ($q) => $q->withoutGlobalScopes()->with('increments')])->findOrFail($id);
        abort_unless($lot->auction && $lot->auction->isPublic() && in_array($lot->status, $this->site->visibleLotStatuses(), true), 404);
        abort_unless(app(\App\Services\Features\AgencyFeatureService::class)->enabled('auctions', \App\Models\Agency::withoutGlobalScopes()->find($lot->agency_id)), 404);

        return $lot;
    }

    private function payload(AuctionLot $lot, ?AuctionRegistration $reg, ?ClientUser $buyer): array
    {
        $out = $this->engine->state($lot, $reg && $reg->isApproved() ? $reg : null);
        $out['registration'] = $reg?->status;
        $out['watching'] = $buyer
            ? AuctionWatch::withoutGlobalScopes()->whereNull('deleted_at')->where('lot_id', $lot->id)->where('client_user_id', $buyer->id)->exists()
            : false;

        return $out;
    }
}
