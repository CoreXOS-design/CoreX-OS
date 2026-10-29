<?php

namespace App\Listeners\Auction;

use App\Events\Auction\AuctionLotSold;
use App\Events\Auction\AuctionLotSoldSubjectToConfirmation;
use App\Events\Auction\BidderApproved;
use App\Events\Auction\BidderDeclined;
use App\Events\Auction\LotOutbid;
use App\Mail\Auctions\AuctionBidderMail;
use App\Models\Agency;
use App\Models\AgencyAuctionSettings;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\AuctionRegistration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails bidders about their registration and about lots (.ai/specs/auctions.md §10), as the agency. A failed send is
 * logged and never blocks the bid or the approval that triggered it.
 */
class NotifyBidder
{
    public function handleApproved(BidderApproved $e): void
    {
        $this->send($e->registration, 'approved', null, ['bidder_number' => $e->registration->bidder_number]);
    }

    public function handleDeclined(BidderDeclined $e): void
    {
        $this->send($e->registration, 'declined', null, ['reason' => $e->registration->declined_reason]);
    }

    public function handleOutbid(LotOutbid $e): void
    {
        if (! AgencyAuctionSettings::outbidEmailsFor((int) $e->lot->agency_id)) {
            return;
        }
        $this->send($e->outbid, 'outbid', $e->lot, ['amount' => $this->money($e->newAmount)]);
    }

    public function handleSold(AuctionLotSold $e): void
    {
        $this->result($e->lot, 'won');
    }

    public function handleSoldSubject(AuctionLotSoldSubjectToConfirmation $e): void
    {
        $this->result($e->lot, 'subject_to_confirmation');
    }

    /** The winner hears they won (or are highest subject to confirmation); every other bidder is told it ended. */
    private function result(AuctionLot $lot, string $winnerKind): void
    {
        $winning = $lot->winning_bid_id ? \App\Models\AuctionBid::withoutGlobalScopes()->find($lot->winning_bid_id) : null;
        $winnerReg = $winning?->registration_id;
        $regIds = $lot->bids()->valid()->pluck('registration_id')->unique();

        foreach (AuctionRegistration::withoutGlobalScopes()->whereIn('id', $regIds)->get() as $reg) {
            $isWinner = (int) $reg->id === (int) $winnerReg;
            $this->send($reg, $isWinner ? $winnerKind : 'ended', $lot, $isWinner ? ['amount' => $this->money((float) $lot->hammer_price)] : []);
        }
    }

    private function send(AuctionRegistration $reg, string $kind, ?AuctionLot $lot, array $facts): void
    {
        try {
            $auction = Auction::withTrashed()->withoutGlobalScopes()->find($reg->auction_id);
            $user = $reg->clientUser()->withoutGlobalScopes()->first();
            $agency = Agency::withoutGlobalScopes()->find($reg->agency_id);
            if (! $auction || ! $user || ! $agency || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)
                || str_ends_with($user->email, '@' . config('clientauth.fake_email_domain', 'corexclient.co.za'))) {
                return;
            }
            $url = $lot
                ? route('public.auctions.lot', [$agency->slug, $auction->id, $lot->id])
                : route('public.auctions.auction', [$agency->slug, $auction->id]);

            Mail::to($user->email)->send(new AuctionBidderMail($kind, $reg->displayName(), $auction, $lot, $url, $facts));
        } catch (\Throwable $e) {
            Log::warning('Auction bidder mail failed: ' . $e->getMessage(), ['registration' => $reg->id, 'kind' => $kind]);
        }
    }

    private function money(float $v): string
    {
        return 'R ' . number_format($v, 0, '.', ' ');
    }
}
