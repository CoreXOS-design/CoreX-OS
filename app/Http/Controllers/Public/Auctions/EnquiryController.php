<?php

namespace App\Http\Controllers\Public\Auctions;

use App\Http\Controllers\Controller;
use App\Services\Auctions\AuctionEnquiryService;
use App\Services\Auctions\BuyerAccountService;
use App\Services\Auctions\PublicAuctionSite;
use Illuminate\Http\Request;

/** POST an enquiry about an auction or one of its lots (.ai/specs/auctions.md §8.1). Guests may enquire. */
class EnquiryController extends Controller
{
    public function __construct(private PublicAuctionSite $site, private AuctionEnquiryService $enquiries, private BuyerAccountService $buyers) {}

    public function store(Request $request, string $agencySlug, int $auction, ?int $lot = null)
    {
        $agency = $this->site->agency($agencySlug);
        $auction = $this->site->auction($agency, $auction);
        $lot = $lot ? $this->site->lot($auction, $lot) : null;

        $data = $request->validate([
            'name' => 'required|string|max:190',
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|string|max:40',
            'message' => 'nullable|string|max:2000',
            'popia_consent' => 'accepted',
        ], [
            'popia_consent.accepted' => 'Please accept the privacy notice so we can contact you.',
        ]);
        if (empty($data['email']) && empty($data['phone'])) {
            return back()->withErrors(['email' => 'Enter an email address or a phone number so we can reach you.'])->withInput();
        }

        $this->enquiries->capture($auction, $lot, $data, $this->buyers->current());

        return back()->with('status', 'Thank you. Your enquiry was sent and the agent will be in touch.');
    }
}
