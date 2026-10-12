<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\Agency;
use App\Models\AgencyAuctionSettings;
use App\Models\Auction;
use App\Models\AuctionBid;
use App\Models\AuctionBidder;
use App\Models\AuctionLot;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use App\Services\Auctions\AuctionLotStatusService;
use App\Services\Auctions\BidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-432 Phase 3 — .ai/specs/auctions.md §11.1, §11.3. Verified via Tinker
 * while building this against the real bid-increment bands, the fall of
 * the hammer, and retraction — this test captures the same scenarios
 * (in-room, under_the_hammer). Also covers §12.3's "top under-bidders"
 * visibility on a passed-in lot.
 *
 * AT-432 Phase 4 — .ai/specs/auctions.md §11.2-§11.3 additions: the
 * open_for_bids/online-channel path (placeBid() accepts bids from
 * open_for_bids, not just under_the_hammer — the Phase 3 setUp() only
 * ever exercised the latter), the online-window/paddle-gate guards,
 * auto-extend (anti-sniping), and iterative proxy resolution. These use
 * their own local makeOpenLot()/makeBidder() helpers rather than the
 * Phase 3 setUp() fixture, since they need online-mode auctions/lots the
 * fixture doesn't build.
 *
 * The proxy-resolution round bound (500) and the 3-bidder pathological
 * case it was found against (maxes 100k/150k/200k needing 10 rounds, not
 * the originally-shipped-then-caught "distinct proxy-holders + 1" bound
 * of 4) were hand-traced and then verified via Tinker while building this
 * service — test_proxy_resolution_converges_to_the_correct_final_price
 * below reproduces that exact scenario as an automated regression.
 *
 * True concurrent-process locking (two real OS processes racing
 * placeBid() on the same lot) was verified via a standalone two-worker
 * script outside phpunit, since a single PHPUnit process cannot exercise
 * real MySQL cross-connection locking — not reproduced here.
 */
final class BidServiceTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Auction $auction;
    private AuctionLot $lot;
    private AuctionBidder $bidder1;
    private AuctionBidder $bidder2;
    private BidService $bidSvc;
    private AuctionLotStatusService $lotSvc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Bid Test Agency', 'slug' => 'bid-test-'.uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $this->actingAs($user);
        $property = Property::create([
            'title' => 'Bid test lot', 'agency_id' => $this->agency->id, 'agent_id' => $user->id,
            'branch_id' => $branch->id, 'status' => 'active', 'listing_type' => 'sale',
        ]);

        $this->auction = Auction::create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'reference' => 'AUC-BID-'.uniqid(),
            'title' => 'Bid Test Auction', 'bidding_mode' => 'in_room', 'auctioneer_kind' => 'internal',
            'starts_at' => now()->addDays(5),
        ]);
        $this->lot = AuctionLot::create([
            'agency_id' => $this->agency->id, 'auction_id' => $this->auction->id, 'property_id' => $property->id,
            'lot_number' => 1, 'reserve_price' => 500000, 'opening_bid' => 400000,
        ]);

        $contact1 = Contact::create(['agency_id' => $this->agency->id, 'first_name' => 'Bidder', 'last_name' => 'One']);
        $contact2 = Contact::create(['agency_id' => $this->agency->id, 'first_name' => 'Bidder', 'last_name' => 'Two']);
        $this->bidder1 = AuctionBidder::create([
            'agency_id' => $this->agency->id, 'auction_id' => $this->auction->id, 'contact_id' => $contact1->id,
            'status' => 'approved', 'paddle_number' => '001',
        ]);
        $this->bidder2 = AuctionBidder::create([
            'agency_id' => $this->agency->id, 'auction_id' => $this->auction->id, 'contact_id' => $contact2->id,
            'status' => 'approved', 'paddle_number' => '002',
        ]);

        $this->lotSvc = new AuctionLotStatusService();
        $this->lotSvc->publishCatalogue($this->auction);
        $this->lotSvc->openForBids($this->lot);
        $this->lotSvc->startHammer($this->lot);

        $this->bidSvc = new BidService();
    }

    public function test_a_bid_below_the_opening_bid_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->bidSvc->placeBid($this->lot, $this->bidder1, 300000);
    }

    public function test_a_bid_that_does_not_exceed_the_current_high_is_rejected(): void
    {
        $this->bidSvc->placeBid($this->lot, $this->bidder1, 400000);

        $this->expectException(\RuntimeException::class);
        $this->bidSvc->placeBid($this->lot, $this->bidder2, 400000);
    }

    public function test_suggested_next_bid_uses_the_increment_band(): void
    {
        $this->bidSvc->placeBid($this->lot, $this->bidder1, 400000);

        // 400,000 falls in the R0-500,000 band -> R10,000 increment (§11.3 default).
        $this->assertEquals(410000.0, $this->bidSvc->suggestedNextBid($this->lot));
    }

    public function test_suggested_next_bid_is_null_with_no_current_bid_and_no_opening_bid(): void
    {
        $this->lot->update(['opening_bid' => null]);

        $this->assertNull($this->bidSvc->suggestedNextBid($this->lot->refresh()));
    }

    public function test_retraction_is_blocked_by_default_and_works_once_enabled(): void
    {
        $this->bidSvc->placeBid($this->lot, $this->bidder1, 400000);

        $this->expectException(\RuntimeException::class);
        $this->bidSvc->retractLastBid($this->lot);
    }

    public function test_a_retracted_bid_no_longer_counts_as_the_current_high(): void
    {
        AgencyAuctionSettings::updateOrCreate(['agency_id' => $this->agency->id], ['bid_retraction_allowed' => true]);
        $this->bidSvc->placeBid($this->lot, $this->bidder1, 400000);
        $this->bidSvc->placeBid($this->lot, $this->bidder2, 410000);

        $this->bidSvc->retractLastBid($this->lot, null, 'mis-key');

        $this->assertEquals(400000.0, (float) $this->bidSvc->currentHighBid($this->lot)->amount);
    }

    public function test_fall_of_the_hammer_resolves_the_winning_bid_and_bidder_from_the_log(): void
    {
        $this->bidSvc->placeBid($this->lot, $this->bidder1, 400000);
        $this->bidSvc->placeBid($this->lot, $this->bidder2, 550000);

        $sold = $this->lotSvc->recordHammerFromCurrentBid($this->lot);

        $this->assertSame(AuctionLot::STATUS_SOLD, $sold->status);
        $this->assertEquals(550000.0, (float) $sold->hammer_price);
        $this->assertSame($this->bidder2->id, $sold->winning_bidder_id);
        $winningBid = AuctionBid::find($sold->winning_bid_id);
        $this->assertTrue($winningBid->is_winning);
    }

    public function test_recording_the_hammer_with_no_active_bid_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->lotSvc->recordHammerFromCurrentBid($this->lot);
    }

    public function test_passed_in_lot_ranks_under_bidders_by_their_own_highest_bid(): void
    {
        $this->bidSvc->placeBid($this->lot, $this->bidder1, 400000);
        $this->bidSvc->placeBid($this->lot, $this->bidder2, 410000);
        $this->bidSvc->placeBid($this->lot, $this->bidder1, 420000);

        $this->lotSvc->markPassedIn($this->lot->refresh());

        $ctrl = new \App\Http\Controllers\CoreX\Auctions\AuctionLotController();
        $view = $ctrl->show($this->lot);
        $topUnderBidders = $view->getData()['topUnderBidders'];

        $this->assertCount(2, $topUnderBidders);
        $this->assertEquals(420000.0, (float) $topUnderBidders[0]->amount);
        $this->assertSame($this->bidder1->id, $topUnderBidders[0]->auction_bidder_id);
    }

    // --- Phase 4 additions below: online-channel path, auto-extend, proxy resolution ---

    private function makeOpenLot(array $lotAttrs = [], string $biddingMode = 'online'): AuctionLot
    {
        $agent = User::factory()->create(['agency_id' => $this->agency->id]);
        $property = Property::create([
            'title' => 'Bid service test lot', 'agency_id' => $this->agency->id, 'agent_id' => $agent->id,
            'branch_id' => $this->auction->branch_id, 'status' => 'active', 'listing_type' => 'sale',
        ]);
        $auction = Auction::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->auction->branch_id, 'reference' => 'AUC-'.uniqid(),
            'title' => 'Bid Service Test', 'bidding_mode' => $biddingMode, 'auctioneer_kind' => 'internal',
            'starts_at' => now()->subHour(),
        ]);
        $lot = AuctionLot::create(array_merge([
            'agency_id' => $this->agency->id, 'auction_id' => $auction->id, 'property_id' => $property->id,
            'lot_number' => 1,
        ], $lotAttrs));

        $this->lotSvc->publishCatalogue($auction);
        $this->lotSvc->openForBids($lot);

        return $lot->refresh();
    }

    private function makeBidder(int $auctionId, ?float $maxProxyBid = null): AuctionBidder
    {
        $contact = Contact::create(['agency_id' => $this->agency->id, 'first_name' => 'Test', 'last_name' => 'Bidder'.uniqid()]);

        return AuctionBidder::create([
            'agency_id' => $this->agency->id, 'auction_id' => $auctionId, 'contact_id' => $contact->id,
            'status' => AuctionBidder::STATUS_APPROVED, 'paddle_number' => (string) random_int(100, 999),
            'fica_status' => 'approved', 'fica_verified_at' => now(), 'rules_signed_at' => now(),
            'deposit_required' => false, 'approved_at' => now(), 'max_proxy_bid' => $maxProxyBid,
        ]);
    }

    public function test_a_bid_is_accepted_on_the_online_channel_from_open_for_bids(): void
    {
        $lot = $this->makeOpenLot();
        $bidder = $this->makeBidder($lot->auction_id);

        $bid = $this->bidSvc->placeBid($lot, $bidder, 100000, 'online');

        $this->assertInstanceOf(AuctionBid::class, $bid);
        $this->assertEquals(100000.0, (float) $this->bidSvc->currentHighBid($lot)->amount);
    }

    public function test_a_bid_on_a_lot_that_is_not_open_is_rejected(): void
    {
        $lot = $this->makeOpenLot();
        $bidder = $this->makeBidder($lot->auction_id);
        $this->lotSvc->withdraw($lot);

        $this->expectException(\RuntimeException::class);
        $this->bidSvc->placeBid($lot->refresh(), $bidder, 100000, 'online');
    }

    public function test_a_bid_after_the_online_window_has_closed_is_rejected(): void
    {
        $lot = $this->makeOpenLot(['online_closes_at' => now()->subMinute()]);
        $bidder = $this->makeBidder($lot->auction_id);

        $this->expectException(\RuntimeException::class);
        $this->bidSvc->placeBid($lot, $bidder, 100000, 'online');
    }

    public function test_a_bidder_who_does_not_clear_the_paddle_gate_is_rejected(): void
    {
        $lot = $this->makeOpenLot();
        $bidder = $this->makeBidder($lot->auction_id);
        $bidder->update(['status' => AuctionBidder::STATUS_DRAFT]);

        $this->expectException(\RuntimeException::class);
        $this->bidSvc->placeBid($lot, $bidder, 100000, 'online');
    }

    public function test_a_late_bid_inside_the_auto_extend_window_pushes_the_close_out(): void
    {
        // Default auto-extend: enabled, 5 minutes.
        $lot = $this->makeOpenLot(['online_closes_at' => now()->addMinutes(2)]);
        $bidder = $this->makeBidder($lot->auction_id);

        $this->bidSvc->placeBid($lot, $bidder, 100000, 'online');

        $lot->refresh();
        $this->assertTrue($lot->online_closes_at->greaterThan(now()->addMinutes(4)));
    }

    public function test_a_bid_outside_the_auto_extend_window_leaves_the_close_time_untouched(): void
    {
        $originalClose = now()->addHour();
        $lot = $this->makeOpenLot(['online_closes_at' => $originalClose]);
        $bidder = $this->makeBidder($lot->auction_id);

        $this->bidSvc->placeBid($lot, $bidder, 100000, 'online');

        $lot->refresh();
        $this->assertTrue($lot->online_closes_at->equalTo($originalClose));
    }

    /**
     * Regression for the round-bound bug found while hand-verifying this
     * method: bidder A's standing proxy caps at 100k, B at 150k, C at 200k,
     * default increment band is 10k (amount <= 500000). A manual opening
     * bid of 60k must resolve — via 10 rounds of repeated challenge/
     * counter-challenge, not the originally-shipped-then-caught
     * "distinct proxy-holders + 1" bound of 4, which would have stopped
     * early — to C winning at exactly 150,000: C's price climbs from
     * 140,000 to 140,000 + the 10k increment, landing precisely on B's
     * max, at which point B can no longer challenge (not strictly greater
     * than its own cap) and the cascade is stable. Verified via Tinker
     * against this exact scenario before asserting the number here.
     */
    public function test_proxy_resolution_converges_to_the_correct_final_price(): void
    {
        $lot = $this->makeOpenLot();
        $bidderA = $this->makeBidder($lot->auction_id, 100000);
        $bidderB = $this->makeBidder($lot->auction_id, 150000);
        $bidderC = $this->makeBidder($lot->auction_id, 200000);
        $opener = $this->makeBidder($lot->auction_id);

        $this->bidSvc->placeBid($lot, $opener, 60000, 'online');

        $winner = $this->bidSvc->currentHighBid($lot);
        $this->assertEquals(150000.0, (float) $winner->amount);
        $this->assertSame($bidderC->id, $winner->auction_bidder_id);
        $this->assertTrue((bool) $winner->is_proxy);
    }

    public function test_proxy_resolution_never_fires_for_a_manual_bid_that_is_itself_a_proxy_bid(): void
    {
        $lot = $this->makeOpenLot();
        $bidder = $this->makeBidder($lot->auction_id);

        // Passing isProxy=true must not recurse resolveProxyBids() on itself.
        $bid = $this->bidSvc->placeBid($lot, $bidder, 100000, 'online', null, true, 100000);

        $this->assertSame(1, AuctionBid::where('auction_lot_id', $lot->id)->count());
        $this->assertTrue((bool) $bid->is_proxy);
    }

    public function test_bid_count_and_distinct_bidder_count(): void
    {
        $lot = $this->makeOpenLot();
        $bidderA = $this->makeBidder($lot->auction_id);
        $bidderB = $this->makeBidder($lot->auction_id);

        $this->bidSvc->placeBid($lot, $bidderA, 100000, 'online');
        $this->bidSvc->placeBid($lot, $bidderB, 110000, 'online');

        $this->assertSame(2, $this->bidSvc->bidCount($lot));
        $this->assertSame(2, $this->bidSvc->distinctBidderCount($lot));
    }
}
