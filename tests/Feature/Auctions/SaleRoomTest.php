<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Mail\Auctions\AuctionBidderMail;
use App\Models\Agency;
use App\Models\AgencyAuctionSettings;
use App\Models\AuctionBid;
use App\Models\AuctionLot;
use App\Models\Property;
use App\Services\Auctions\AuctionLotService;
use App\Services\Auctions\BidEngine;
use Illuminate\Support\Facades\Mail;

/** The live Sale Room (.ai/specs/auctions.md §5 S6, §8.6): open a lot, take floor bids, knock down or pass in. */
final class SaleRoomTest extends BiddingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->auc->forceFill(['format' => 'live', 'venue_name' => 'Town Hall'])->save();
        $this->lot->forceFill(['opens_at' => null, 'closes_at' => null])->save();
    }

    private function room(string $suffix = ''): string
    {
        return "/corex/auctions/{$this->auc->id}/room{$suffix}";
    }

    private function openLot(?AuctionLot $lot = null): void
    {
        $lot ??= $this->lot;
        $this->post($this->room("/lots/{$lot->id}/open"))->assertSessionHasNoErrors();
    }

    private function floor(int $number, float $amount, ?AuctionLot $lot = null)
    {
        $lot ??= $this->lot;

        return $this->post($this->room("/lots/{$lot->id}/bid"), ['bidder_number' => $number, 'amount' => $amount]);
    }

    private function second(): AuctionLot
    {
        $l = app(AuctionLotService::class)->saveForProperty($this->property('Second house'), ['auction_id' => $this->auc->id, 'opening_bid' => 50000], $this->admin);
        $l->forceFill(['status' => 'published'])->save();

        return $l;
    }

    public function test_the_room_shows_the_lots_and_the_lot_on_the_block(): void
    {
        $this->registration($this->buyer('a@example.com'));

        $this->get($this->room())->assertOk()->assertSee('Sale Room')->assertSee('Lot 1')->assertSee('Bid Cottage')->assertSee('Open Lot 1 for bids')->assertSee('R 150 000');
    }

    public function test_a_timed_auction_gets_a_read_only_monitor(): void
    {
        $this->auc->forceFill(['format' => 'timed'])->save();

        $this->get($this->room())->assertOk()->assertSee('Monitor only')->assertDontSee('Open Lot 1 for bids')->assertDontSee('data-floor-bid', false);
        $this->post($this->room("/lots/{$this->lot->id}/open"))->assertSessionHasErrors('lot');
    }

    public function test_opening_a_lot_puts_it_on_the_block_and_only_one_lot_may_be_open(): void
    {
        $other = $this->second();

        $this->openLot();
        $this->assertSame('open', $this->lot->fresh()->status);
        $this->assertSame('open', $this->auc->fresh()->status);

        $this->post($this->room("/lots/{$other->id}/open"))->assertSessionHasErrors('lot');
        $this->assertSame('published', $other->fresh()->status);
    }

    public function test_a_floor_bid_is_recorded_against_a_bidder_number_and_follows_the_increment_rule(): void
    {
        $a = $this->registration($this->buyer('a@example.com'));
        $b = $this->registration($this->buyer('b@example.com'));
        $this->openLot();

        $this->floor($a->bidder_number, 100000)->assertSessionHasNoErrors();
        $this->floor($b->bidder_number, 104000)->assertSessionHasErrors('amount');
        $this->floor($b->bidder_number, 105000)->assertSessionHasNoErrors();

        $top = $this->lot->fresh()->highestBid();
        $this->assertSame($b->id, (int) $top->registration_id);
        $this->assertSame('floor', $top->source);
        $this->assertSame(2, AuctionBid::count());
    }

    public function test_an_unknown_or_unapproved_bidder_number_is_refused(): void
    {
        $pending = $this->registration($this->buyer('p@example.com'), 'pending');
        $this->registration($this->buyer('a@example.com'));
        $this->openLot();

        $this->floor(99, 100000)->assertSessionHasErrors('bidder_number');
        $this->floor(7, 100000)->assertSessionHasErrors('bidder_number');
        $this->assertSame(0, AuctionBid::count());
    }

    public function test_a_floor_bid_needs_the_lot_to_be_open(): void
    {
        $a = $this->registration($this->buyer('a@example.com'));

        $this->floor($a->bidder_number, 100000)->assertSessionHasErrors('amount');   // not opened yet

        $this->assertSame(0, AuctionBid::count());
    }

    public function test_a_floor_bid_never_runs_the_soft_close_clock_or_sends_outbid_emails(): void
    {
        Mail::fake();
        $a = $this->registration($this->buyer('a@example.com'));
        $b = $this->registration($this->buyer('b@example.com'));
        $this->openLot();

        $this->floor($a->bidder_number, 100000);
        $this->floor($b->bidder_number, 105000);

        $this->assertNull($this->lot->fresh()->closes_at, 'a live lot has no clock to extend');
        Mail::assertNotSent(AuctionBidderMail::class);
    }

    public function test_online_bidders_cannot_bid_on_a_live_auction_from_the_web(): void
    {
        $a = $this->registration($this->buyer('a@example.com'));
        $this->openLot();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(BidEngine::class)->place($this->lot->fresh(), $a, 100000, 'online');
    }

    // ── the hammer ─────────────────────────────────────────────────────────

    public function test_knocking_down_above_the_reserve_sells_the_lot(): void
    {
        $a = $this->registration($this->buyer('a@example.com'));
        $this->openLot();
        $this->floor($a->bidder_number, 160000);

        $this->post($this->room("/lots/{$this->lot->id}/hammer"))->assertSessionHasNoErrors();

        $lot = $this->lot->fresh();
        $this->assertSame('sold', $lot->status);
        $this->assertSame('160000.00', $lot->hammer_price);
        $this->assertNotNull($lot->winning_bid_id);
        $this->assertSame('closed', $this->auc->fresh()->status, 'the only lot has ended');
    }

    public function test_knocking_down_below_the_reserve_sells_subject_to_confirmation_with_a_deadline(): void
    {
        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'confirmation_days' => 5]);
        $a = $this->registration($this->buyer('a@example.com'));
        $this->openLot();
        $this->floor($a->bidder_number, 120000);

        $this->post($this->room("/lots/{$this->lot->id}/hammer"));

        $lot = $this->lot->fresh();
        $this->assertSame('sold_subject_to_confirmation', $lot->status);
        $this->assertTrue($lot->confirmation_deadline->between(now()->addDays(5)->subMinute(), now()->addDays(5)->addMinute()));
        $this->assertNotNull($lot->property->fresh()->currentAuctionLot(), 'held until the seller decides');
    }

    public function test_a_lot_with_no_bids_cannot_be_knocked_down_but_can_be_passed_in(): void
    {
        $property = $this->lot->property;
        $property->forceFill(['status' => Property::STATUS_ON_AUCTION, 'pre_auction_status' => 'active'])->save();
        $this->openLot();

        $this->post($this->room("/lots/{$this->lot->id}/hammer"))->assertSessionHasErrors('lot');
        $this->post($this->room("/lots/{$this->lot->id}/pass-in"), ['note' => 'No interest in the room'])->assertSessionHasNoErrors();

        $lot = $this->lot->fresh();
        $this->assertSame('passed_in', $lot->status);
        $this->assertSame('No interest in the room', $lot->result_note);
        $this->assertSame('active', $property->fresh()->status);
        $this->assertFalse($property->fresh()->isAuction());
    }

    public function test_the_auctioneer_may_pass_a_lot_in_even_when_it_has_bids(): void
    {
        $a = $this->registration($this->buyer('a@example.com'));
        $this->openLot();
        $this->floor($a->bidder_number, 120000);

        $this->post($this->room("/lots/{$this->lot->id}/pass-in"))->assertSessionHasNoErrors();

        $this->assertSame('passed_in', $this->lot->fresh()->status);
    }

    public function test_only_the_open_lot_can_be_hammered_and_a_second_hammer_does_nothing(): void
    {
        $a = $this->registration($this->buyer('a@example.com'));
        $this->openLot();
        $this->floor($a->bidder_number, 160000);
        $this->post($this->room("/lots/{$this->lot->id}/hammer"));

        $this->post($this->room("/lots/{$this->lot->id}/hammer"))->assertSessionHasErrors('lot');
        $this->assertSame(1, \DB::table('domain_event_log')->where('event_name', 'App\\Events\\Auction\\AuctionLotSold')->count());
    }

    public function test_the_auction_stays_open_until_its_last_lot_has_ended(): void
    {
        $other = $this->second();
        $a = $this->registration($this->buyer('a@example.com'));
        $this->openLot();
        $this->floor($a->bidder_number, 160000);
        $this->post($this->room("/lots/{$this->lot->id}/hammer"));
        $this->assertSame('open', $this->auc->fresh()->status);

        $this->openLot($other);
        $this->post($this->room("/lots/{$other->id}/pass-in"));
        $this->assertSame('closed', $this->auc->fresh()->status);
    }

    // ── staff access and the live view ─────────────────────────────────────

    public function test_another_agencys_room_is_unreachable(): void
    {
        $rival = Agency::create(['name' => 'Rival', 'slug' => 'rv-'.uniqid()]);
        $foreign = \App\Models\Auction::withoutAgencyStamping(fn () => \App\Models\Auction::create(['agency_id' => $rival->id, 'reference' => 'AUC-R', 'title' => 'Rival', 'format' => 'live', 'status' => 'published']));

        $this->get("/corex/auctions/{$foreign->id}/room")->assertNotFound();
        $this->post("/corex/auctions/{$foreign->id}/room/lots/1/open")->assertNotFound();
    }

    public function test_the_live_feed_gives_staff_the_bids_with_bidder_numbers_and_the_reserve(): void
    {
        $a = $this->registration($this->buyer('a@example.com'));
        $this->openLot();
        $this->floor($a->bidder_number, 120000);

        $json = $this->getJson("/api/v1/auctions/lots/{$this->lot->id}/feed")->assertOk()
            ->assertJsonPath('state.current_bid', 120000)->assertJsonPath('state.reserve_amount', 150000)
            ->assertJsonPath('bids.0.bidder_number', 1)->assertJsonPath('bids.0.source', 'floor');
        $this->assertSame('open', $json->json('state.phase'));
    }

    public function test_a_lot_of_another_agency_has_no_feed(): void
    {
        $rival = Agency::create(['name' => 'Rival', 'slug' => 'rv2-'.uniqid()]);
        $rivalLot = AuctionLot::withoutAgencyStamping(fn () => AuctionLot::create(['agency_id' => $rival->id, 'auction_id' => $this->auc->id, 'property_id' => $this->property('Rival house')->id, 'lot_number' => 50, 'status' => 'published']));

        $this->getJson("/api/v1/auctions/lots/{$rivalLot->id}/feed")->assertNotFound();
    }

    public function test_the_public_lot_page_of_a_live_lot_follows_the_auctioneer_not_a_clock(): void
    {
        $this->openLot();
        $a = $this->registration($this->buyer('a@example.com'));
        $this->floor($a->bidder_number, 160000);
        \Illuminate\Support\Facades\Auth::guard('web')->forgetUser();
        $this->flushSession();

        $json = $this->getJson("/api/v1/auctions/public/lots/{$this->lot->id}/feed")->assertOk()->assertJsonPath('phase', 'open')->assertJsonPath('current_bid', 160000);
        $this->assertNull($json->json('closes_at'));
    }
}
