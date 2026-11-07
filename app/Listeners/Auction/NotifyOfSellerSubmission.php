<?php

namespace App\Listeners\Auction;

use App\Events\Auction\AuctionSellerSubmitted;
use App\Mail\Auctions\AuctionSellerSubmissionMail;
use App\Models\AgencyAuctionSettings;
use App\Services\Auctions\AuctionActivityRecipients;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** AuctionSellerSubmitted -> email the people the agency chose (the §19.2 switch and recipients), unless it switched these emails off. */
class NotifyOfSellerSubmission
{
    public function handle(AuctionSellerSubmitted $event): void
    {
        $s = $event->submission;
        $agencyId = (int) $s->agency_id;
        if (! AgencyAuctionSettings::notifyEmailsEnabledFor($agencyId)) {
            return;
        }

        $url = route('corex.auctions.sellers.show', $s->id);
        foreach (app(AuctionActivityRecipients::class)->emails($agencyId) as $to) {
            try {
                Mail::to($to)->send(new AuctionSellerSubmissionMail($s, $url));
            } catch (\Throwable $e) {
                Log::warning('Seller submission mail failed: ' . $e->getMessage(), ['submission' => $s->id, 'to' => $to]);
            }
        }
    }
}
