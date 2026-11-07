<?php

namespace App\Services\Auctions;

use App\Http\Controllers\Public\Auctions\SellerSubmissionController;
use App\Models\Agency;
use App\Models\AgencyAuctionSettings;
use App\Models\Auction;
use App\Models\AuctionBid;
use App\Models\AuctionInvoice;
use App\Models\AuctionLot;
use App\Models\AuctionRegistration;
use App\Models\AuctionSellerSubmission;
use App\Models\AuctionWatch;
use App\Models\ClientUser;
use App\Support\ColorContrast;
use Illuminate\Support\Collection;

/**
 * Turns what the public auction page shows into the JSON an external auction website gets (.ai/specs/auctions-website-api.md). One place, so the
 * shapes stay the same on every endpoint. Nothing here ever contains the reserve price, another buyer's identity, or a street address (the page
 * shows suburb and city only).
 */
class AuctionWebsitePresenter
{
    public function __construct(private PublicAuctionSite $site, private AuctionFeeCalculator $fees, private AuctionRegistrationService $registrations, private BidEngine $engine) {}

    public function site(Agency $agency): array
    {
        $id = (int) $agency->id;
        $branding = Agency::publicBrandingFor($id);
        $brand = AgencyAuctionSettings::accentColorFor($id) ?? $branding['colors']['default'];
        $sellerOn = AgencyAuctionSettings::sellerFormEnabledFor($id);

        return [
            'agency' => ['slug' => $agency->slug, 'name' => $branding['name'], 'logo_url' => $branding['logoUrl'], 'email' => $agency->email, 'phone' => $agency->phone],
            'site' => [
                'title' => AgencyAuctionSettings::publicTitleFor($id),
                'intro' => AgencyAuctionSettings::introTextFor($id),
                'hero_image_url' => AgencyAuctionSettings::heroImageUrlFor($id),
                'how_it_works' => AgencyAuctionSettings::howItWorksFor($id),
                'footer_text' => AgencyAuctionSettings::footerTextFor($id),
                'contact_email' => AgencyAuctionSettings::contactEmailFor($id),
                'contact_phone' => AgencyAuctionSettings::contactPhoneFor($id),
                'show_past_auctions' => AgencyAuctionSettings::showPastAuctionsFor($id),
                'currency' => 'ZAR',
                'public_page_url' => route('public.auctions.home', $agency->slug),
            ],
            'colors' => [
                'brand' => $brand, 'on_brand' => ColorContrast::textOn($brand),
                'button' => $branding['colors']['button'], 'on_button' => ColorContrast::textOn($branding['colors']['button']),
            ],
            'features' => [
                'seller_questionnaire' => $sellerOn,
                'bidding_activity' => AgencyAuctionSettings::showBidHistoryFor($id),
                'bidder_numbers_visible' => AgencyAuctionSettings::showBidderNumbersFor($id),
            ],
            'seller_questionnaire' => $sellerOn ? $this->sellerOptions($id) : null,
        ];
    }

    /** What the website needs to draw the "Sell at auction" form: every option list, and the agency's own reply promise. */
    public function sellerOptions(int $agencyId): array
    {
        $S = AuctionSellerSubmission::class;

        return [
            'endpoint' => '/api/v1/website/auctions/sell',
            'response_text' => AgencyAuctionSettings::sellerResponseTextFor($agencyId),
            'seller_types' => $S::SELLER_TYPES, 'property_types' => $S::PROPERTY_TYPES, 'auction_formats' => $S::FORMATS, 'contact_methods' => $S::CONTACT_METHODS,
            'features' => ['pool' => 'Pool', 'borehole' => 'Borehole', 'solar' => 'Solar power', 'garden_flat' => 'Garden flat', 'electric_fence' => 'Electric fence', 'fibre' => 'Fibre ready', 'staff_quarters' => 'Staff quarters', 'double_garage' => 'Double garage'],
            'occupancy' => ['owner' => 'Owner occupied', 'tenant' => 'Tenanted', 'vacant' => 'Vacant'],
            'conditions' => ['excellent' => 'Excellent - move-in ready', 'good' => 'Good - minor wear', 'fair' => 'Fair - some repairs needed', 'needs_work' => 'Needs significant work'],
            'reserve' => ['confidential' => 'Yes, protect my minimum', 'none' => 'No reserve'],
            'timing' => ['asap' => 'As soon as possible', '1_3_months' => 'Within 1-3 months', '3_6_months' => 'Within 3-6 months', 'exploring' => 'Just exploring my options'],
            'viewing' => ['most_days' => 'Available most days', 'appointment' => 'By appointment', 'restricted' => 'Restricted (tenant or security)'],
            'documents' => ['title_deed' => 'Title deed copy', 'rates_account' => 'Latest rates account', 'building_plans' => 'Approved building plans', 'levy_statement' => 'Levy statement', 'lease_agreement' => 'Lease agreement', 'bond_statement' => 'Bond statement', 'id_copies' => 'ID copies'],
        ];
    }

    public function auctionCard(Auction $a): array
    {
        return [
            'id' => $a->id, 'reference' => $a->reference, 'title' => $a->title,
            'phase' => $a->publicPhase(), 'phase_label' => $a->publicPhaseLabel(), 'status' => $a->status,
            'format' => $a->format, 'starts_at' => $a->starts_at?->toIso8601String(), 'ends_at' => $a->ends_at?->toIso8601String(),
            'venue_name' => $a->venue_name, 'lots_count' => (int) ($a->public_lots_count ?? $a->lots()->withoutGlobalScopes()->whereIn('status', $this->site->visibleLotStatuses())->count()),
            'image_url' => $a->card_image ?? $this->site->cardImage($a),
            'public_page_url' => route('public.auctions.auction', [Agency::withoutGlobalScopes()->whereKey($a->agency_id)->value('slug'), $a->id]),
        ];
    }

    public function auction(Agency $agency, Auction $a, ?ClientUser $buyer): array
    {
        $signedIn = $buyer !== null;
        $docs = $this->site->auctionDocuments($a, $signedIn);
        $reg = $buyer ? $this->registrations->forBuyer($a, $buyer) : null;

        return $this->auctionCard($a) + [
            'description' => $a->description,
            'type' => $a->type, 'currency' => $a->currency,
            'venue_address' => $a->venue_address, 'stream_url' => $a->stream_url,
            'registration' => [
                'open' => $this->registrations->isOpen($a),
                'closes_at' => $this->registrations->deadline($a)?->toIso8601String(),
                'mine' => $reg ? $this->registration($reg) : null,
            ],
            'fee_notice' => $this->fees->notice($a) ?: null,
            'documents' => $docs->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'download' => "/api/v1/website/auctions/{$a->id}/documents/{$d->id}"])->values()->all(),
            'hidden_documents' => $signedIn ? 0 : $a->documents()->withoutGlobalScopes()->where('visibility', 'registered')->count(),
            'lots' => $this->site->lots($a)->map(fn ($l) => $this->lotCard($l))->values()->all(),
        ];
    }

    public function lotCard(AuctionLot $lot): array
    {
        $p = $lot->property;

        return [
            'id' => $lot->id, 'auction_id' => $lot->auction_id, 'lot_number' => $lot->lot_number, 'title' => $lot->displayTitle(), 'status' => $lot->status,
            'suburb' => $p?->suburb, 'city' => $p?->city,
            'opening_bid' => $lot->opening_bid !== null ? (float) $lot->opening_bid : null,
            'guide_price_min' => $lot->guide_price_min !== null ? (float) $lot->guide_price_min : null,
            'guide_price_max' => $lot->guide_price_max !== null ? (float) $lot->guide_price_max : null,
            'image_url' => ($p ? ($this->site->photos($p, 1)[0] ?? null) : null),
            'bedrooms' => $p && (int) $p->beds ? (int) $p->beds : null, 'bathrooms' => $p && (int) $p->baths ? (int) $p->baths : null,
            'garages' => $p && (int) $p->garages ? (int) $p->garages : null,
        ];
    }

    public function lot(Agency $agency, Auction $a, AuctionLot $lot, ?ClientUser $buyer): array
    {
        $p = $lot->property;
        $signedIn = $buyer !== null;
        $reg = $buyer ? $this->registrations->forBuyer($a, $buyer) : null;
        $pack = $this->site->packDocuments($lot, $signedIn);
        $hidden = $signedIn ? 0 : $this->site->packDocuments($lot, true)->count() - $this->site->packDocuments($lot, false)->count() + $a->documents()->withoutGlobalScopes()->where('visibility', 'registered')->count();

        return $this->lotCard($lot) + [
            'description' => $lot->description ?: ($p?->description ?: null),
            'floor_size_m2' => $p && (float) $p->size_m2 > 0 ? (float) $p->size_m2 : null,
            'photos' => $p ? $this->site->photos($p, 24) : [],
            'documents' => $this->site->auctionDocuments($a, $signedIn)->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'scope' => 'auction', 'download' => "/api/v1/website/auctions/{$a->id}/documents/{$d->id}"])
                ->concat($pack->map(fn ($d) => ['id' => $d->id, 'name' => $d->original_name, 'scope' => 'lot', 'download' => "/api/v1/website/auctions/{$a->id}/lots/{$lot->id}/documents/{$d->id}"]))->values()->all(),
            'hidden_documents' => $hidden,
            'auction' => ['id' => $a->id, 'title' => $a->title, 'reference' => $a->reference, 'format' => $a->format, 'phase' => $a->publicPhase(), 'registration_open' => $this->registrations->isOpen($a)],
            'fee_notice' => $this->fees->notice($a) ?: null,
        ] + $this->feed($lot, $a, $reg, $buyer);
    }

    /** The live part of a lot: bidding state (no reserve amount, no names), the activity list, and what is true for THIS buyer. */
    public function feed(AuctionLot $lot, Auction $a, ?AuctionRegistration $reg, ?ClientUser $buyer): array
    {
        $state = $this->engine->state($lot, $reg && $reg->isApproved() ? $reg : null);

        return [
            'bidding' => $state,
            'my_registration' => $reg ? $this->registration($reg) : null,
            'watching' => $buyer ? AuctionWatch::withoutGlobalScopes()->whereNull('deleted_at')->where('lot_id', $lot->id)->where('client_user_id', $buyer->id)->exists() : false,
        ];
    }

    public function registration(AuctionRegistration $r): array
    {
        return [
            'id' => $r->id, 'auction_id' => $r->auction_id, 'status' => $r->status, 'bidder_type' => $r->bidder_type, 'company_name' => $r->company_name,
            'bidder_number' => $r->bidder_number, 'declined_reason' => $r->declined_reason, 'submitted_at' => $r->created_at?->toIso8601String(),
        ];
    }

    public function buyer(ClientUser $buyer, Agency $agency): array
    {
        $contact = app(BuyerAccountService::class)->contactFor($buyer, $agency);

        return ['email' => $buyer->email, 'name' => trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: null, 'phone' => $contact->phone];
    }

    /** "My account": details, registrations, watched lots, lots won and invoices - the same as the page's account screen. */
    public function account(Agency $agency, ClientUser $buyer): array
    {
        $id = (int) $agency->id;
        $regIds = AuctionRegistration::withoutGlobalScopes()->where('agency_id', $id)->where('client_user_id', $buyer->id);

        $regs = AuctionRegistration::withoutGlobalScopes()->whereNull('deleted_at')->with(['auction' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('agency_id', $id)->where('client_user_id', $buyer->id)->latest('id')->get();
        $won = AuctionLot::withoutGlobalScopes()->whereNull('deleted_at')->with(['auction' => fn ($q) => $q->withoutGlobalScopes(), 'property' => fn ($q) => $q->withoutGlobalScopes()])
            ->whereIn('status', ['sold', 'sold_subject_to_confirmation'])
            ->whereIn('winning_bid_id', AuctionBid::withoutGlobalScopes()->select('id')->whereIn('registration_id', (clone $regIds)->select('id')))
            ->latest('result_at')->get();
        $watching = AuctionWatch::withoutGlobalScopes()->whereNull('deleted_at')->with(['lot' => fn ($q) => $q->withoutGlobalScopes()->with(['property' => fn ($p) => $p->withoutGlobalScopes()])])
            ->where('agency_id', $id)->where('client_user_id', $buyer->id)->latest('id')->get()->filter(fn ($w) => $w->lot);
        $invoices = $this->invoices($id, $buyer->id);

        return [
            'buyer' => $this->buyer($buyer, $agency),
            'registrations' => $regs->map(fn ($r) => $this->registration($r) + ['auction' => $r->auction ? ['id' => $r->auction->id, 'title' => $r->auction->title, 'reference' => $r->auction->reference] : null])->values()->all(),
            'lots_won' => $won->map(fn ($l) => $this->lotCard($l) + ['hammer_price' => $l->hammer_price !== null ? (float) $l->hammer_price : null, 'sold_subject_to_confirmation' => $l->status === 'sold_subject_to_confirmation', 'result_at' => $l->result_at?->toIso8601String()])->values()->all(),
            'watching' => $watching->map(fn ($w) => $this->lotCard($w->lot))->values()->all(),
            'invoices' => $invoices->map(fn ($i) => $this->invoice($i))->values()->all(),
        ];
    }

    public function invoices(int $agencyId, int $clientUserId): Collection
    {
        return AuctionInvoice::withoutGlobalScopes()->whereNull('deleted_at')->where('agency_id', $agencyId)
            ->whereIn('status', AuctionInvoice::BUYER_VISIBLE)
            ->whereIn('registration_id', AuctionRegistration::withoutGlobalScopes()->select('id')->where('agency_id', $agencyId)->where('client_user_id', $clientUserId))
            ->with(['lot' => fn ($q) => $q->withoutGlobalScopes(), 'auction' => fn ($q) => $q->withoutGlobalScopes()])->latest('id')->get();
    }

    public function invoice(AuctionInvoice $i): array
    {
        return [
            'id' => $i->id, 'number' => $i->number, 'status' => $i->status, 'hammer_price' => (float) $i->hammer_price, 'total_amount' => (float) $i->total_amount,
            'deposit_amount' => (float) $i->deposit_amount, 'deposit_due_at' => $i->deposit_due_at?->toIso8601String(), 'balance_amount' => (float) $i->balance_amount,
            'balance_due_at' => $i->balance_due_at?->toIso8601String(), 'issued_at' => $i->issued_at?->toIso8601String(), 'paid_at' => $i->paid_at?->toIso8601String(),
            'lot' => $i->lot ? ['id' => $i->lot->id, 'lot_number' => $i->lot->lot_number, 'title' => $i->lot->displayTitle()] : null,
            'auction' => $i->auction ? ['id' => $i->auction->id, 'title' => $i->auction->title] : null,
            'pdf' => "/api/v1/website/auctions/buyers/me/invoices/{$i->id}/pdf",
        ];
    }
}
