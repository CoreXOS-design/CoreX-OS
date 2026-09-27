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
 * the hammer, and retraction — this test captures the same scenarios.
 * Also covers §12.3's "top under-bidders" visibility on a passed-in lot.
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
}
