<?php

namespace App\Http\Controllers\Api\V1\Website\Auctions;

use App\Models\AuctionLot;
use App\Models\AuctionRegistration;
use App\Services\Auctions\AuctionRegistrationService;
use App\Services\Auctions\AuctionWebsitePresenter;
use App\Services\Auctions\BidEngine;
use App\Services\Auctions\PublicAuctionSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Bidding for an external auction website (scope auctions:buyers, buyer token required): the SAME engine, rules and locks as the page - an approved registration, the minimum bid, soft close, auto-bids. */
class BiddingController extends AuctionApiController
{
    public function __construct(PublicAuctionSite $site, AuctionWebsitePresenter $present, private BidEngine $engine, private AuctionRegistrationService $registrations)
    {
        parent::__construct($site, $present);
    }

    public function bid(Request $request, int $lot): JsonResponse
    {
        [$l, $reg] = $this->bidder($request, $lot);
        $data = $request->validate(['amount' => 'required|numeric|min:1|max:99999999999']);

        $this->engine->place($l, $reg, (float) $data['amount'], 'online', $request->ip());

        return $this->respond($request, $l, $reg);
    }

    public function autoBid(Request $request, int $lot): JsonResponse
    {
        [$l, $reg] = $this->bidder($request, $lot);
        $data = $request->validate(['max' => 'required|numeric|min:1|max:99999999999']);

        $this->engine->setAutoBid($l, $reg, (float) $data['max'], $request->ip());

        return $this->respond($request, $l, $reg);
    }

    public function cancelAutoBid(Request $request, int $lot): JsonResponse
    {
        [$l, $reg] = $this->bidder($request, $lot);
        $this->engine->cancelAutoBid($l, $reg);

        return $this->respond($request, $l, $reg);
    }

    /** @return array{0: AuctionLot, 1: AuctionRegistration} */
    private function bidder(Request $request, int $lotId): array
    {
        $lot = $this->publicLot($request, $lotId);
        $reg = $this->registrations->forBuyer($lot->auction, $this->buyer($request));
        if (! $reg || ! $reg->isApproved()) {
            abort(403, $reg ? 'Your registration is not approved yet.' : 'Register for this auction before you bid.');
        }

        return [$lot, $reg];
    }

    private function respond(Request $request, AuctionLot $lot, AuctionRegistration $reg): JsonResponse
    {
        $lot->refresh();

        return response()->json(['ok' => true, 'data' => $this->present->feed($lot, $lot->auction, $reg, $this->buyer($request))]);
    }
}
