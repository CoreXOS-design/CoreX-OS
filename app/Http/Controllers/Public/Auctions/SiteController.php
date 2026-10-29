<?php

namespace App\Http\Controllers\Public\Auctions;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AgencyAuctionSettings;
use App\Models\AuctionWatch;
use App\Services\Auctions\AuctionRegistrationService;
use App\Services\Auctions\BidEngine;
use App\Services\Auctions\BuyerAccountService;
use App\Services\Auctions\PublicAuctionSite;
use Illuminate\Support\Facades\Storage;

/**
 * The public auction site (.ai/specs/auctions.md §8.1): home, an auction, a lot, and the document downloads.
 * Only published material is ever read (see PublicAuctionSite); the agency comes from the URL slug.
 */
class SiteController extends Controller
{
    public function __construct(
        private PublicAuctionSite $site,
        private BuyerAccountService $buyers,
        private BidEngine $engine,
        private AuctionRegistrationService $registrations,
    ) {}

    public function home(string $agencySlug)
    {
        $agency = $this->site->agency($agencySlug);
        $auctions = $this->site->auctions($agency);

        return view('public.auctions.home', $this->shared($agency) + [
            'live' => $auctions->whereIn('status', ['draft', 'published', 'open']),
            'past' => $auctions->whereIn('status', ['closed', 'completed']),
        ]);
    }

    public function auction(string $agencySlug, int $auction)
    {
        $agency = $this->site->agency($agencySlug);
        $auction = $this->site->auction($agency, $auction);
        $signedIn = $this->buyers->current() !== null;

        return view('public.auctions.auction', $this->shared($agency) + [
            'auction' => $auction,
            'lots' => $this->site->lots($auction),
            'documents' => $this->site->auctionDocuments($auction, $signedIn),
            'hiddenDocuments' => $signedIn ? 0 : $auction->documents()->withoutGlobalScopes()->where('visibility', 'registered')->count(),
        ]);
    }

    public function lot(string $agencySlug, int $auction, int $lot)
    {
        $agency = $this->site->agency($agencySlug);
        $auction = $this->site->auction($agency, $auction);
        $lot = $this->site->lot($auction, $lot);
        $buyer = $this->buyers->current();
        $signedIn = $buyer !== null;
        $registration = $buyer ? $this->registrations->forBuyer($auction, $buyer) : null;
        $state = $this->engine->state($lot, $registration && $registration->isApproved() ? $registration : null);

        return view('public.auctions.lot', $this->shared($agency) + [
            'auction' => $auction,
            'lot' => $lot,
            'registration' => $registration,
            'registrationOpen' => $this->registrations->isOpen($auction),
            'state' => $state,
            'watching' => $buyer ? AuctionWatch::withoutGlobalScopes()->whereNull('deleted_at')->where('lot_id', $lot->id)->where('client_user_id', $buyer->id)->exists() : false,
            'photos' => $this->site->photos($lot->property),
            'documents' => $this->site->auctionDocuments($auction, $signedIn),
            'packDocuments' => $this->site->packDocuments($lot, $signedIn),
            'hiddenCount' => $signedIn ? 0 : $this->site->packDocuments($lot, true)->count() - $this->site->packDocuments($lot, false)->count()
                + $auction->documents()->withoutGlobalScopes()->where('visibility', 'registered')->count(),
            'money' => fn ($v) => $this->site->money($v === null ? null : (float) $v, $auction->currency),
        ]);
    }

    public function auctionDocument(string $agencySlug, int $auction, int $document)
    {
        $agency = $this->site->agency($agencySlug);
        $auction = $this->site->auction($agency, $auction);
        $doc = $this->site->auctionDocuments($auction, $this->buyers->current() !== null)->firstWhere('id', $document);
        abort_if($doc === null || ! Storage::disk('local')->exists($doc->path), 404);

        return Storage::disk('local')->download($doc->path, $doc->name . '.' . pathinfo($doc->path, PATHINFO_EXTENSION));
    }

    public function packDocument(string $agencySlug, int $auction, int $lot, int $document)
    {
        $agency = $this->site->agency($agencySlug);
        $auction = $this->site->auction($agency, $auction);
        $lot = $this->site->lot($auction, $lot);
        $doc = $this->site->packDocument($lot, $document, $this->buyers->current() !== null);
        $disk = $doc?->disk ?: 'local';
        abort_if($doc === null || ! Storage::disk($disk)->exists($doc->storage_path), 404);

        return Storage::disk($disk)->download($doc->storage_path, $doc->original_name);
    }

    /** @return array<string, mixed> what every public page needs */
    public static function layoutData(Agency $agency, ?\App\Models\ClientUser $buyer): array
    {
        $branding = Agency::publicBrandingFor((int) $agency->id);

        return [
            'agency' => $agency,
            'branding' => $branding,
            'siteTitle' => AgencyAuctionSettings::publicTitleFor((int) $agency->id),
            'intro' => AgencyAuctionSettings::introTextFor((int) $agency->id),
            'contactEmail' => AgencyAuctionSettings::contactEmailFor((int) $agency->id),
            'contactPhone' => AgencyAuctionSettings::contactPhoneFor((int) $agency->id),
            'accent' => AgencyAuctionSettings::accentColorFor((int) $agency->id) ?? $branding['colors']['default'],
            'heroUrl' => AgencyAuctionSettings::heroImageUrlFor((int) $agency->id),
            'howItWorks' => AgencyAuctionSettings::howItWorksFor((int) $agency->id),
            'footerText' => AgencyAuctionSettings::footerTextFor((int) $agency->id),
            'showPast' => AgencyAuctionSettings::showPastAuctionsFor((int) $agency->id),
            'buyer' => $buyer,
        ];
    }

    private function shared(Agency $agency): array
    {
        return self::layoutData($agency, $this->buyers->current());
    }
}
