<?php

namespace App\Http\Controllers\Api\V1\Website\Auctions;

use App\Http\Controllers\Public\Auctions\SellerSubmissionController;
use App\Models\AgencyAuctionSettings;
use App\Services\Auctions\AuctionEnquiryService;
use App\Services\Auctions\AuctionSellerSubmissionService;
use App\Services\Auctions\AuctionWebsitePresenter;
use App\Services\Auctions\PublicAuctionSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Enquiries and the "Sell at auction" questionnaire for an external auction website (scope auctions:leads). Same validation, contact + property creation, emails and alerts as the page. */
class LeadsController extends AuctionApiController
{
    public function __construct(PublicAuctionSite $site, AuctionWebsitePresenter $present, private AuctionEnquiryService $enquiries, private AuctionSellerSubmissionService $sellers)
    {
        parent::__construct($site, $present);
    }

    /** An enquiry about an auction, or (with {lot}) one of its lots. A buyer token, when sent, links the enquiry to that buyer. */
    public function enquire(Request $request, int $auction, ?int $lot = null): JsonResponse
    {
        $agency = $this->agency($request);
        $a = $this->site->auction($agency, $auction);
        $l = $lot ? $this->site->lot($a, $lot) : null;

        $data = $request->validate([
            'name' => 'required|string|max:190',
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|string|max:40',
            'message' => 'nullable|string|max:2000',
            'popia_consent' => 'accepted',
        ], ['popia_consent.accepted' => 'Please accept the privacy notice so we can contact you.']);
        if (empty($data['email']) && empty($data['phone'])) {
            return response()->json(['message' => 'Enter an email address or a phone number so we can reach you.', 'errors' => ['email' => ['Enter an email address or a phone number so we can reach you.']]], 422);
        }

        $this->enquiries->capture($a, $l, $data, $this->buyer($request));

        return response()->json(['ok' => true, 'message' => 'Your enquiry was sent and the agent will be in touch.'], 201);
    }

    /** The seller questionnaire: the website posts the same answers the page's form does (field names in the spec; option lists in GET /site). */
    public function sell(Request $request): JsonResponse
    {
        $agency = $this->agency($request);
        abort_unless(AgencyAuctionSettings::sellerFormEnabledFor((int) $agency->id), 404, 'The "Sell at auction" form is switched off for this agency.');

        if (trim((string) $request->input('website', '')) !== '') {
            return response()->json(['message' => 'This submission could not be accepted.'], 422);
        }

        $this->sellers->capture((int) $agency->id, SellerSubmissionController::validated($request), $request->ip());

        return response()->json(['ok' => true, 'message' => 'Questionnaire received.', 'response_text' => AgencyAuctionSettings::sellerResponseTextFor((int) $agency->id)], 201);
    }
}
