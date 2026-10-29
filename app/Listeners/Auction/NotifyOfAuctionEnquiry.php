<?php

namespace App\Listeners\Auction;

use App\Events\Auction\AuctionEnquiryReceived;
use App\Mail\Auctions\AuctionEnquiryMail;
use App\Models\AgencyAuctionSettings;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails the people the agency chose (Settings -> Auctions: listing agent / branch manager / both) the moment an
 * enquiry arrives. Idempotent per recipient is not needed: the event fires once per stored enquiry.
 */
class NotifyOfAuctionEnquiry
{
    public function handle(AuctionEnquiryReceived $event): void
    {
        $enquiry = $event->enquiry;
        $agencyId = (int) $enquiry->agency_id;
        $mode = AgencyAuctionSettings::notifyEnquiryToFor($agencyId);
        $property = $enquiry->property()->withoutGlobalScopes()->first();

        $recipients = collect();
        if (in_array($mode, ['listing_agent', 'both'], true) && $property?->agent_id) {
            $recipients->push(User::withoutGlobalScopes()->find($property->agent_id));
        }
        if (in_array($mode, ['branch_manager', 'both'], true)) {
            $recipients = $recipients->merge(
                User::withoutGlobalScopes()->where('agency_id', $agencyId)->where('role', 'branch_manager')
                    ->when($property?->branch_id, fn ($q) => $q->where('branch_id', $property->branch_id))->get()
            );
        }

        // Nobody chosen can be reached (e.g. no manager on that branch): fall back to the agency's enquiries address.
        $emails = $recipients->filter()->pluck('email')->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))->unique()->values();
        if ($emails->isEmpty() && filter_var($fallback = AgencyAuctionSettings::contactEmailFor($agencyId), FILTER_VALIDATE_EMAIL)) {
            $emails = collect([$fallback]);
        }

        $url = route('corex.auctions.enquiries.index', ['search' => $enquiry->email ?: $enquiry->name]);
        foreach ($emails as $to) {
            try {
                Mail::to($to)->send(new AuctionEnquiryMail($enquiry, $url));
            } catch (\Throwable $e) {
                Log::warning('Auction enquiry mail failed: ' . $e->getMessage(), ['enquiry' => $enquiry->id, 'to' => $to]);
            }
        }
    }
}
