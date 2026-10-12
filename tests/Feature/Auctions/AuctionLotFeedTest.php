<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Auction;
use App\Models\AuctionBidder;
use App\Models\AuctionLot;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use App\Services\Auctions\AuctionLotStatusService;
use App\Services\Auctions\BidService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-432 Phase 4 — .ai/specs/auctions.md §11.2/§11.4/§14.3. The catalogued
 * bid-feed route the hybrid Sale Room polls.
 *
 * Registered in routes/web.php under `api/v1/*`, NOT routes/api.php — found
 * while building the Sale Room's polling script: routes/api.php's `api`
 * middleware group has Sanctum's EnsureFrontendRequestsAreStateful stripped
 * (bootstrap/app.php, deliberately, for the mobile app), so a route
 * registered there is bearer-token-only and a real cookie-authed browser
 * session 401s. Confirmed with a real HTTP round-trip (login via /login,
 * then GET the route, both through an actual `php artisan serve` process
 * with a genuine session cookie — not `actingAs()`, which bypasses the
 * token/cookie check and would not have caught this) before moving the
 * route to the session-authenticated `api/v1` group web.php already runs
 * (the same one `/api/v1/logged-user`'s real, reachable implementation
 * lives in — see MeController vs. the dead inline closure in api.php).
 */
final class AuctionLotFeedTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Auction $auction;
    private AuctionLotStatusService $lotSvc;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionService::clearCache();

        $this->agency = Agency::create(['name' => 'Feed Test Agency', 'slug' => 'feed-test-'.uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        AgencyFeature::updateOrCreate(['agency_id' => $this->agency->id, 'feature_key' => 'auctions'], ['enabled' => true]);
        config(['features.auctions' => true]);
        app(\App\Services\Features\AgencyFeatureService::class)->forget($this->agency->id);

        $this->auction = Auction::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'reference' => 'AUC-FEED-'.uniqid(),
            'title' => 'Feed Test Auction', 'bidding_mode' => 'hybrid', 'auctioneer_kind' => 'internal',
            'starts_at' => now()->subHour(),
        ]);
        $this->lotSvc = new AuctionLotStatusService();

        $this->actingAs($user);
    }

    private function makeLot(): AuctionLot
    {
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id]);
        $property = Property::create([
            'title' => 'Feed test lot', 'agency_id' => $this->agency->id, 'agent_id' => $agent->id,
            'branch_id' => $this->branch->id, 'status' => 'active', 'listing_type' => 'sale',
        ]);
        $lot = AuctionLot::create([
            'agency_id' => $this->agency->id, 'auction_id' => $this->auction->id, 'property_id' => $property->id,
            'lot_number' => 1,
        ]);
        $this->lotSvc->publishCatalogue($this->auction);
        $this->lotSvc->openForBids($lot);

        return $lot->refresh();
    }

    public function test_the_feed_returns_lot_state_with_no_bids(): void
    {
        $lot = $this->makeLot();

        $this->getJson(route('api.v1.auctions.lots.feed', $lot))
            ->assertOk()
            ->assertJson([
                'lot_id' => $lot->id,
                'status' => AuctionLot::STATUS_OPEN_FOR_BIDS,
                'current_bid' => null,
                'bid_count' => 0,
                'distinct_bidder_count' => 0,
                'recent_bids' => [],
            ]);
    }

    public function test_the_feed_reflects_the_current_high_bid_and_channel(): void
    {
        $lot = $this->makeLot();
        $contact = Contact::create(['agency_id' => $this->agency->id, 'first_name' => 'Feed', 'last_name' => 'Bidder']);
        $bidder = AuctionBidder::create([
            'agency_id' => $this->agency->id, 'auction_id' => $this->auction->id, 'contact_id' => $contact->id,
            'status' => AuctionBidder::STATUS_APPROVED, 'paddle_number' => '042', 'fica_status' => 'approved',
            'fica_verified_at' => now(), 'rules_signed_at' => now(), 'deposit_required' => false, 'approved_at' => now(),
        ]);
        (new BidService())->placeBid($lot, $bidder, 100000, 'online');

        $response = $this->getJson(route('api.v1.auctions.lots.feed', $lot))->assertOk();

        $response->assertJsonPath('current_bid.amount', 100000.0);
        $response->assertJsonPath('current_bid.channel', 'online');
        $response->assertJsonPath('current_bid.bidder_paddle_number', '042');
        $response->assertJsonPath('bid_count', 1);
        $response->assertJsonPath('recent_bids.0.channel', 'online');
    }

    /** Never leaks the reserve — the field simply isn't part of the shape. */
    public function test_the_feed_never_exposes_the_reserve_price(): void
    {
        $lot = $this->makeLot();
        $lot->update(['reserve_price' => 900000]);

        $this->getJson(route('api.v1.auctions.lots.feed', $lot))
            ->assertOk()
            ->assertJsonMissing(['reserve_price' => 900000])
            ->assertJsonStructure([
                'lot_id', 'lot_number', 'status', 'online_closes_at', 'current_bid',
                'suggested_next_bid', 'bid_count', 'distinct_bidder_count', 'recent_bids', 'server_time',
            ]);
    }

    public function test_a_lot_from_another_agency_404s_via_route_model_binding_scoping(): void
    {
        $otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'other-'.uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $otherAgency->id, 'name' => 'Main']);
        $otherAgent = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id]);
        $otherProperty = Property::create([
            'title' => 'Other agency lot', 'agency_id' => $otherAgency->id, 'agent_id' => $otherAgent->id,
            'branch_id' => $otherBranch->id, 'status' => 'active', 'listing_type' => 'sale',
        ]);
        $otherAuction = Auction::create([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'reference' => 'AUC-OTHER-'.uniqid(),
            'title' => 'Other Agency Auction', 'bidding_mode' => 'hybrid', 'auctioneer_kind' => 'internal',
            'starts_at' => now()->subHour(),
        ]);
        $otherLot = AuctionLot::create([
            'agency_id' => $otherAgency->id, 'auction_id' => $otherAuction->id, 'property_id' => $otherProperty->id,
            'lot_number' => 1,
        ]);

        $this->getJson(route('api.v1.auctions.lots.feed', $otherLot))->assertNotFound();
    }
}
