<?php

namespace App\Http\Controllers\Api\V1\Website\Auctions;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\AuctionLot;
use App\Models\ClientUser;
use App\Services\Auctions\AuctionWebsitePresenter;
use App\Services\Auctions\PublicAuctionSite;
use App\Services\Features\AgencyFeatureService;
use Illuminate\Http\Request;

/**
 * Base of the auction website API (.ai/specs/auctions-website-api.md): the agency is the API KEY's agency (never anything the caller sends), and
 * the buyer, when there is one, is the holder of the `X-Buyer-Token`.
 */
abstract class AuctionApiController extends Controller
{
    public function __construct(protected PublicAuctionSite $site, protected AuctionWebsitePresenter $present) {}

    protected function key(Request $request): AgencyApiKey
    {
        $key = \Illuminate\Support\Facades\Auth::guard('agency-api')->user();
        abort_unless($key instanceof AgencyApiKey, 401);

        return $key;
    }

    /** The key's agency, 404 unless it has Auctions switched on. */
    protected function agency(Request $request): Agency
    {
        $agency = Agency::withoutGlobalScopes()->find($this->key($request)->agency_id);
        abort_if($agency === null || ! app(AgencyFeatureService::class)->enabled('auctions', $agency), 404, 'Auctions are not switched on for this agency.');

        return $agency;
    }

    protected function buyer(Request $request): ?ClientUser
    {
        return $request->attributes->get('auction_buyer');
    }

    /** A lot that is on this agency's public site (any agency's id is a 404), the way the page treats it. */
    protected function publicLot(Request $request, int $id): AuctionLot
    {
        $agency = $this->agency($request);
        $lot = AuctionLot::withoutGlobalScopes()->whereNull('deleted_at')->where('agency_id', $agency->id)
            ->with(['auction' => fn ($q) => $q->withoutGlobalScopes()->with('increments'), 'property' => fn ($q) => $q->withoutGlobalScopes()])->findOrFail($id);
        abort_unless($lot->auction && $lot->auction->isPublic() && in_array($lot->status, $this->site->visibleLotStatuses(), true), 404);

        return $lot;
    }
}
