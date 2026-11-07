<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Mail\Auctions\AuctionBidderMail;
use App\Models\AuctionLot;
use App\Models\Property;
use App\Services\Auctions\BidEngine;
use App\Services\Auctions\LotResultService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

/** Opening and closing timed lots on schedule (.ai/specs/auctions.md §8.6). */
final class LotClosingTest extends BiddingTestCase
{
    private function end(): void
    {
        $this->lot->forceFill(['closes_at' => now()->subMinute()])->save();
    }

    public function test_a_lot_above_its_reserve_is_sold_to_the_highest_bidder(): void
    {
        $a = $this->registration($this->buyer('a@example.com'));
        $b = $this->registration($this->buyer('b@example.com'));
        app(BidEngine::class)->place($this->lot, $a, 100000);
        app(BidEngine::class)->place($this->lot, $b, 160000);
        $winning = $this->lot->fresh()->highestBid();
        $this->end();

        $this->assertSame('sold', app(LotResultService::class)->conclude($this->lot->id));

        $lot = $this->lot->fresh();
        $this->assertSame($winning->id, (int) $lot->winning_bid_id);
        $this->assertSame('160000.00', $lot->hammer_price);
        $this->assertNotNull($lot->result_at);
        $this->assertDatabaseHas('domain_event_log', ['event_name' => 'App\\Events\\Auction\\AuctionLotSold']);
    }

    public function test_a_lot_with_no_reserve_sells_at_any_bid(): void
    {
        $this->lot->forceFill(['reserve_price' => null])->save();
        app(BidEngine::class)->place($this->lot, $this->registration(), 100000);
        $this->end();

        $this->assertSame('sold', app(LotResultService::class)->conclude($this->lot->id));
    }

    public function test_a_lot_below_its_reserve_is_sold_subject_to_confirmation_and_keeps_its_property(): void
    {
        app(BidEngine::class)->place($this->lot, $this->registration(), 120000);
        $this->end();

        $this->assertSame('sold_subject_to_confirmation', app(LotResultService::class)->conclude($this->lot->id));

        $lot = $this->lot->fresh();
        $this->assertSame('120000.00', $lot->hammer_price, 'the highest bid, awaiting the seller');
        $this->assertNotNull($lot->property->fresh()->currentAuctionLot(), 'still held until the seller decides');
        $this->assertDatabaseHas('domain_event_log', ['event_name' => 'App\\Events\\Auction\\AuctionLotSoldSubjectToConfirmation']);
    }

    public function test_a_lot_with_no_bids_is_passed_in_and_its_property_released(): void
    {
        $property = $this->lot->property;
        $property->forceFill(['status' => Property::STATUS_ON_AUCTION, 'pre_auction_status' => 'active'])->save();
        $this->end();

        $this->assertSame('passed_in', app(LotResultService::class)->conclude($this->lot->id));

        $this->assertNull($property->fresh()->currentAuctionLot());
        $this->assertSame('active', $property->fresh()->status);
        $this->assertFalse($property->fresh()->isAuction());
        $this->assertDatabaseHas('domain_event_log', ['event_name' => 'App\\Events\\Auction\\AuctionLotPassedIn']);
    }

    public function test_closing_is_idempotent_and_waits_for_the_closing_time(): void
    {
        app(BidEngine::class)->place($this->lot, $this->registration(), 160000);

        $this->assertNull(app(LotResultService::class)->conclude($this->lot->id), 'a day to go: nothing happens');

        $this->end();
        $this->assertSame('sold', app(LotResultService::class)->conclude($this->lot->id));
        $this->assertNull(app(LotResultService::class)->conclude($this->lot->id), 'already concluded');
        $this->assertSame(1, \DB::table('domain_event_log')->where('event_name', 'App\\Events\\Auction\\AuctionLotSold')->count());
    }

    public function test_a_retracted_bid_does_not_win(): void
    {
        $a = $this->registration($this->buyer('a@example.com'));
        $b = $this->registration($this->buyer('b@example.com'));
        app(BidEngine::class)->place($this->lot, $a, 100000);
        $top = app(BidEngine::class)->place($this->lot, $b, 170000);
        app(BidEngine::class)->retract($top, $this->admin, 'Fat finger');
        $this->end();

        $this->assertSame('sold_subject_to_confirmation', app(LotResultService::class)->conclude($this->lot->id), 'only the R100 000 remains, below the reserve');
        $this->assertSame('100000.00', $this->lot->fresh()->hammer_price);
    }

    public function test_the_auction_is_closed_once_its_last_lot_ends(): void
    {
        $this->end();
        app(LotResultService::class)->conclude($this->lot->id);

        $this->assertSame('closed', $this->auc->fresh()->status);
    }

    public function test_the_winner_and_the_other_bidders_are_emailed(): void
    {
        Mail::fake();
        $a = $this->registration($this->buyer('a@example.com'));
        $b = $this->registration($this->buyer('b@example.com'));
        app(BidEngine::class)->place($this->lot, $a, 100000);
        app(BidEngine::class)->place($this->lot, $b, 160000);
        $this->end();

        app(LotResultService::class)->conclude($this->lot->id);

        Mail::assertSent(AuctionBidderMail::class, fn ($m) => $m->kind === 'won' && $m->hasTo('b@example.com') && $m->facts['amount'] === 'R 160 000');
        Mail::assertSent(AuctionBidderMail::class, fn ($m) => $m->kind === 'ended' && $m->hasTo('a@example.com'));
    }

    public function test_below_reserve_the_highest_bidder_is_told_it_is_subject_to_confirmation(): void
    {
        Mail::fake();
        app(BidEngine::class)->place($this->lot, $this->registration($this->buyer('a@example.com')), 120000);
        $this->end();

        app(LotResultService::class)->conclude($this->lot->id);

        Mail::assertSent(AuctionBidderMail::class, fn ($m) => $m->kind === 'subject_to_confirmation' && $m->hasTo('a@example.com'));
    }

    public function test_the_scheduled_command_opens_due_lots_and_closes_ended_ones(): void
    {
        $other = app(\App\Services\Auctions\AuctionLotService::class)->saveForProperty(
            $this->property('Second lot'),
            ['auction_id' => $this->auc->id, 'opening_bid' => 50000, 'opens_at' => now()->subMinute()->toDateTimeString(), 'closes_at' => now()->addDay()->toDateTimeString()],
            $this->admin
        );
        $other->forceFill(['status' => AuctionLot::STATUS_PUBLISHED])->save();
        $this->end();   // lot 1 has ended with no bids

        Artisan::call('auctions:run');

        $this->assertSame('passed_in', $this->lot->fresh()->status);
        $this->assertSame('open', $other->fresh()->status, 'its bidding window has started');
        $this->assertSame('open', $this->auc->fresh()->status);
    }

    public function test_a_lot_whose_window_has_not_started_stays_published(): void
    {
        $this->lot->forceFill(['opens_at' => now()->addHour(), 'closes_at' => now()->addDay()])->save();

        Artisan::call('auctions:run');

        $this->assertSame('published', $this->lot->fresh()->status);
    }

    public function test_live_auctions_are_never_closed_by_the_clock(): void
    {
        $this->auc->forceFill(['format' => 'live'])->save();
        $this->end();

        Artisan::call('auctions:run');

        $this->assertSame('published', $this->lot->fresh()->status, 'the auctioneer hammers a live lot');
    }
}
