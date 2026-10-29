<?php

namespace App\Http\Controllers\Public\Auctions;

use App\Http\Controllers\Controller;
use App\Models\AgencyAuctionSettings;
use App\Services\Auctions\AuctionRegistrationService;
use App\Services\Auctions\BuyerAccountService;
use App\Services\Auctions\PublicAuctionSite;
use Illuminate\Http\Request;

/** "Register to bid" for one auction (.ai/specs/auctions.md §8.3). A signed-in buyer only. */
class RegistrationController extends Controller
{
    public function __construct(
        private PublicAuctionSite $site,
        private BuyerAccountService $buyers,
        private AuctionRegistrationService $registrations,
    ) {}

    public function form(Request $request, string $agencySlug, int $auction)
    {
        $agency = $this->site->agency($agencySlug);
        $auction = $this->site->auction($agency, $auction);
        $buyer = $this->buyers->current();
        if (! $buyer) {
            return redirect()->to(route('public.auctions.login', $agencySlug) . '?back=' . urlencode(url()->current()))
                ->with('status', 'Log in or create a free account to register for this auction.');
        }

        $type = old('bidder_type', $this->registrations->forBuyer($auction, $buyer)?->bidder_type ?? 'individual');

        return view('public.auctions.register', SiteController::layoutData($agency, $buyer) + [
            'auction' => $auction,
            'registration' => $this->registrations->forBuyer($auction, $buyer),
            'isOpen' => $this->registrations->isOpen($auction),
            'deadline' => $this->registrations->deadline($auction),
            'onFile' => $this->registrations->onFile($buyer, (int) $agency->id),
            'requiredIndividual' => $this->registrations->requiredKinds($auction, 'individual'),
            'requiredCompany' => $this->registrations->requiredKinds($auction, 'company'),
            'kinds' => AgencyAuctionSettings::DOCUMENT_KINDS,
            'terms' => AgencyAuctionSettings::termsTextFor((int) $agency->id),
            'documents' => $this->site->auctionDocuments($auction, true),
            'type' => $type,
        ]);
    }

    public function submit(Request $request, string $agencySlug, int $auction)
    {
        $agency = $this->site->agency($agencySlug);
        $auction = $this->site->auction($agency, $auction);
        $buyer = $this->buyers->current();
        abort_unless($buyer, 403);

        $data = $request->validate([
            'bidder_type' => 'required|in:individual,company',
            'company_name' => 'nullable|string|max:190',
            'company_reg_no' => 'nullable|string|max:60',
            'accept_conditions' => 'accepted',
            'documents' => 'nullable|array',
            'documents.*' => 'nullable|file|max:10240|mimes:pdf,jpg,jpeg,png',
            'existing' => 'nullable|array',
            'existing.*' => 'nullable|integer',
        ], ['accept_conditions.accepted' => 'Please accept the conditions of the auction to register.']);

        $contact = $this->buyers->contactFor($buyer, $agency);
        $this->registrations->submit(
            $auction, $buyer, $contact, $data,
            array_filter((array) $request->file('documents', [])),
            array_filter((array) $request->input('existing', [])),
            $request->ip(),
        );

        return redirect()->route('public.auctions.auction', [$agencySlug, $auction->id])
            ->with('status', 'Thank you. Your registration was received and is waiting for approval. We will email you.');
    }
}
