<?php

namespace App\Listeners\Auction;

use App\Events\Auction\AuctionEnquiryReceived;
use App\Events\Auction\AuctionSellerSubmitted;
use App\Events\Auction\BidPlaced;
use App\Models\AuctionLot;
use App\Services\Auctions\AuctionActivityRecipients;
use App\Services\CommandCenter\NotificationDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * In-app alerts for the agency's own people (.ai/specs/auctions.md §19.4), through the notification gateway so each person's own
 * notification preferences apply. A new ONLINE bid alerts the lot's listing agent; a new enquiry alerts its assigned agent; when nobody
 * owns the item every administrator of the agency is alerted instead. Auto-bids (the system answering a bid) and floor bids (typed in by
 * staff) never alert. A failure is logged and never blocks the bid or the enquiry.
 */
class NotifyStaffOfAuctionActivity
{
    public function handleBid(BidPlaced $event): void
    {
        $bid = $event->bid;
        if ($bid->source !== 'online') {
            return;
        }

        try {
            $agencyId = (int) $bid->agency_id;
            $lot = AuctionLot::withoutGlobalScopes()->withTrashed()->with('property')->find($bid->lot_id);
            $owner = $lot?->property?->agent_id ? (int) $lot->property->agent_id : null;
            $what = $lot ? 'Lot ' . $lot->lot_number . ' — ' . $lot->displayTitle() : 'an auction lot';
            $amount = 'R ' . number_format((float) $bid->amount, 0, '.', ',');

            foreach (app(AuctionActivityRecipients::class)->alertUsers($agencyId, $owner) as $user) {
                app(NotificationDispatcher::class)->fire($user, 'auction.bid_placed', $bid, [
                    'threshold_hit_at' => $bid->placed_at ?? now(),
                    'title' => 'New bid: ' . $amount,
                    'body' => 'A bid of ' . $amount . ' was placed on ' . $what . '.',
                    'subject_label' => $what,
                    'action_url' => route('corex.auctions.bids.index', ['lot_id' => $bid->lot_id], false),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Auction bid alert failed: ' . $e->getMessage(), ['bid' => $bid->id]);
        }
    }

    /** A seller filled in "Sell at auction": nobody owns it yet, so the agency's administrators are alerted. */
    public function handleSeller(AuctionSellerSubmitted $event): void
    {
        $s = $event->submission;

        try {
            foreach (app(AuctionActivityRecipients::class)->alertUsers((int) $s->agency_id, null) as $user) {
                app(NotificationDispatcher::class)->fire($user, 'auction.seller_submission', $s, [
                    'threshold_hit_at' => $s->created_at ?? now(),
                    'title' => 'New seller lead: ' . $s->name,
                    'body' => $s->name . ' wants to sell a property in ' . $s->suburb . ' at auction.',
                    'subject_label' => $s->address . ', ' . $s->suburb,
                    'action_url' => route('corex.auctions.sellers.show', $s->id, false),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Seller submission alert failed: ' . $e->getMessage(), ['submission' => $s->id]);
        }
    }

    public function handleEnquiry(AuctionEnquiryReceived $event): void
    {
        $enquiry = $event->enquiry;

        try {
            $agencyId = (int) $enquiry->agency_id;
            $owner = $enquiry->assigned_user_id ? (int) $enquiry->assigned_user_id : null;
            $lot = $enquiry->lot_id ? AuctionLot::withoutGlobalScopes()->withTrashed()->find($enquiry->lot_id) : null;
            $what = $lot ? 'Lot ' . $lot->lot_number . ' — ' . $lot->displayTitle() : 'your auction page';

            foreach (app(AuctionActivityRecipients::class)->alertUsers($agencyId, $owner) as $user) {
                app(NotificationDispatcher::class)->fire($user, 'auction.enquiry_received', $enquiry, [
                    'threshold_hit_at' => $enquiry->created_at ?? now(),
                    'title' => 'New auction enquiry from ' . $enquiry->name,
                    'body' => $enquiry->name . ' sent an enquiry about ' . $what . '.',
                    'subject_label' => $what,
                    'action_url' => route('corex.auctions.enquiries.index', ['search' => $enquiry->email ?: $enquiry->name], false),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Auction enquiry alert failed: ' . $e->getMessage(), ['enquiry' => $enquiry->id]);
        }
    }
}
