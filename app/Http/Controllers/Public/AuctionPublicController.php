<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\AgencyAuctionSettings;
use App\Models\AgencyApiKey;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Services\Website\WebsiteLeadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * AT-432 addendum — .ai/specs/auctions-advertising-mode.md §4. The public,
 * unauthenticated advert for an auction and for each lot in it: date, venue,
 * who runs the sale, guide price, reserve disclosure, viewings, the Rules of
 * Auction / Conditions of Sale, the result once the sale is done, and an
 * "I'm interested" enquiry that lands in the agent's normal lead pipeline.
 *
 * Only a PUBLISHED catalogue is reachable, only lots that have left draft are
 * listed, and the reserve AMOUNT is shown only when the agency's Settings →
 * Auctions reserve visibility is "published" (§4.5). Nothing bidder-related is
 * exposed here — in advertising mode CoreX does not run the sale.
 */
class AuctionPublicController extends Controller
{
    private function auction(int $id): Auction
    {
        $auction = Auction::queryWithoutAgencyScope()->with('agency:id,name,slug,logo_path')->find($id);
        abort_if($auction === null || ! $auction->isCataloguePublished(), 404);

        return $auction;
    }

    private function lot(Auction $auction, int $lotId): AuctionLot
    {
        $lot = AuctionLot::queryWithoutAgencyScope()
            ->where('auction_id', $auction->id)
            ->where('status', '!=', AuctionLot::STATUS_DRAFT)
            ->with(['property.agent', 'upcomingViewings'])
            ->find($lotId);
        abort_if($lot === null, 404);

        return $lot;
    }

    public function show(int $auction): View
    {
        $auction = $this->auction($auction);
        $lots = AuctionLot::queryWithoutAgencyScope()
            ->where('auction_id', $auction->id)
            ->where('status', '!=', AuctionLot::STATUS_DRAFT)
            ->with('property')
            ->orderBy('lot_number')
            ->get();

        return view('public.auctions.show', $this->shared($auction) + ['lots' => $lots]);
    }

    public function lotPage(int $auction, int $lot): View
    {
        $auction = $this->auction($auction);

        return view('public.auctions.lot', $this->shared($auction) + ['lot' => $this->lot($auction, $lot)]);
    }

    public function document(int $auction, string $kind)
    {
        $auction = $this->auction($auction);
        abort_unless(in_array($kind, ['rules', 'conditions'], true), 404);

        $path = $auction->{$kind.'_file_path'};
        abort_if(blank($path) || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, $auction->{$kind.'_file_name'} ?: ($kind.'.pdf'), [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function enquire(Request $request, int $auction, int $lot): RedirectResponse
    {
        $auction = $this->auction($auction);
        $lot = $this->lot($auction, $lot);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',
            'message' => 'nullable|string|max:2000',
            'popia_consent' => 'accepted',
        ], [
            'popia_consent.accepted' => 'Please accept the privacy notice so we can contact you.',
        ]);

        if (empty($data['email']) && empty($data['phone'])) {
            return back()->withErrors(['email' => 'Enter an email address or a phone number so we can reach you.'])->withInput();
        }

        // The same capture path website enquiries use: match-or-create the
        // Contact, write the PortalLead, seed the buyer pipeline, notify the
        // listing agent. The key is a transient carrier for agency + label —
        // never persisted, never authenticates anything.
        $carrier = (new AgencyApiKey())->forceFill(['agency_id' => $auction->agency_id, 'name' => 'Auction page']);

        app(WebsiteLeadService::class)->capture($carrier, [
            'listing_id' => $lot->property_id,
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'message' => trim('[Auction: '.$auction->title.' — Lot '.$lot->lot_number.'] '.($data['message'] ?? '')),
            'source' => 'auction_page:'.$auction->id.':'.$lot->id,
        ]);

        return back()->with('status', 'Thank you — the agent will be in touch.');
    }

    private function shared(Auction $auction): array
    {
        $agencyId = (int) $auction->agency_id;

        return [
            'auction' => $auction,
            'agency' => $auction->agency,
            'showReserveAmount' => AgencyAuctionSettings::reserveVisibilityFor($agencyId) === 'published',
            'guideEnabled' => AgencyAuctionSettings::guidePriceEnabledFor($agencyId),
            'registerUrl' => $this->registerUrl($auction),
        ];
    }

    /** Where "Register to bid" points: the external auctioneer's page, or CoreX's own form when CoreX runs the sale. */
    private function registerUrl(Auction $auction): ?string
    {
        if ($auction->external_registration_url) {
            return $auction->external_registration_url;
        }
        if (! AgencyAuctionSettings::advertisingOnlyFor((int) $auction->agency_id) && $auction->isRegistrationOpen()) {
            return route('public.auctions.register.show', $auction->id);
        }

        return null;
    }
}
