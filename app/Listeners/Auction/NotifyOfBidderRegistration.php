<?php

namespace App\Listeners\Auction;

use App\Events\Auction\BidderRegistered;
use App\Mail\Auctions\AuctionRegistrationMail;
use App\Models\AgencyAuctionSettings;
use App\Services\Auctions\AuctionActivityRecipients;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * BidderRegistered -> email the people the agency chose (Settings -> Auctions; blank = the agency's administrators), unless the agency
 * switched these emails off (.ai/specs/auctions.md §19.2). A failed send is logged and never blocks the registration.
 */
class NotifyOfBidderRegistration
{
    public function handle(BidderRegistered $event): void
    {
        $registration = $event->registration;
        $agencyId = (int) $registration->agency_id;
        if (! AgencyAuctionSettings::notifyEmailsEnabledFor($agencyId)) {
            return;
        }

        $registration->loadMissing(['auction', 'contact', 'clientUser']);
        $url = route('corex.auctions.bidders.show', $registration->id);
        foreach (app(AuctionActivityRecipients::class)->emails($agencyId) as $to) {
            try {
                Mail::to($to)->send(new AuctionRegistrationMail($registration, $url));
            } catch (\Throwable $e) {
                Log::warning('Auction registration mail failed: ' . $e->getMessage(), ['registration' => $registration->id, 'to' => $to]);
            }
        }
    }
}
