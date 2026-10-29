<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\AuctionRegistration;
use App\Models\ClientUser;
use App\Services\Auctions\AuctionLotService;

/** Fixture for the bidding tests: a published timed auction with one open lot (opening R100 000, reserve R150 000). */
abstract class BiddingTestCase extends AuctionsV2TestCase
{
    protected Auction $auc;
    protected AuctionLot $lot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auc = $this->auction([
            'status' => 'published', 'published_at' => now(), 'format' => 'timed',
            'starts_at' => now()->subHour(), 'ends_at' => now()->addDays(2), 'registration_closes_at' => now()->addDay(),
        ]);
        $this->lot = app(AuctionLotService::class)->saveForProperty(
            $this->property('Bid Cottage'),
            ['auction_id' => $this->auc->id, 'opening_bid' => 100000, 'reserve_price' => 150000, 'opens_at' => now()->subMinutes(30)->toDateTimeString(), 'closes_at' => now()->addDay()->toDateTimeString()],
            $this->admin
        );
        $this->lot->forceFill(['status' => AuctionLot::STATUS_PUBLISHED])->save();
    }

    protected function buyer(string $email = 'buyer@example.com'): ClientUser
    {
        return ClientUser::firstOrCreate(['email' => $email], ['password' => 'a-strong-pass-1', 'activated_at' => now()]);
    }

    /** A registration for $auction; approved with a bidder number unless told otherwise. */
    protected function registration(?ClientUser $buyer = null, string $status = 'approved', ?Auction $auction = null): AuctionRegistration
    {
        $auction ??= $this->auc;
        $buyer ??= $this->buyer();
        $number = $status === 'approved' ? (int) AuctionRegistration::withoutGlobalScopes()->where('auction_id', $auction->id)->max('bidder_number') + 1 : null;

        return AuctionRegistration::withoutAgencyStamping(fn () => AuctionRegistration::create([
            'agency_id' => $auction->agency_id, 'auction_id' => $auction->id, 'client_user_id' => $buyer->id, 'bidder_type' => 'individual',
            'status' => $status, 'bidder_number' => $number, 'accepted_conditions_at' => now(),
        ]));
    }
}
