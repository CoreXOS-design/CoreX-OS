<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Http\Resources\WebsiteApi\ListingResource;
use App\Models\Agency;
use App\Models\AgencyAuctionSettings;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\AuctionLotViewing;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * AT-432 Phase 5 — .ai/specs/auctions.md §14.3. ListingResource's
 * `auction` block — the data an external agency website needs to render
 * an auction listing, and the registration URL for "Register to Bid".
 */
final class WebsiteApiAuctionBlockTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Website API Auction Agency', 'slug' => 'webapi-auction-'.uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id]);
    }

    private function resourceFor(Property $property): array
    {
        return (new ListingResource($property->refresh()))->toArray(Request::create('/'));
    }

    public function test_a_private_treaty_listing_has_no_auction_block(): void
    {
        $property = Property::create([
            'title' => 'Not an auction', 'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id,
            'branch_id' => $this->branch->id, 'status' => 'active', 'listing_type' => 'sale',
        ]);

        $this->assertNull($this->resourceFor($property)['auction']);
    }

    public function test_an_auction_listing_carries_mode_dates_venue_and_guide_price(): void
    {
        $property = Property::create([
            'title' => 'Auction listing', 'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id,
            'branch_id' => $this->branch->id, 'status' => 'active', 'listing_type' => 'sale', 'sale_method' => 'auction',
        ]);
        $auction = Auction::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'reference' => 'AUC-'.uniqid(),
            'title' => 'Test Auction', 'bidding_mode' => 'online', 'auctioneer_kind' => 'internal',
            'starts_at' => now()->addDays(7), 'venue_name' => 'Test Venue',
        ]);
        AuctionLot::create([
            'agency_id' => $this->agency->id, 'auction_id' => $auction->id, 'property_id' => $property->id,
            'lot_number' => 1, 'guide_price_min' => 800000, 'guide_price_max' => 900000,
        ]);

        $block = $this->resourceFor($property)['auction'];

        $this->assertSame('online', $block['mode']);
        $this->assertSame('Test Venue', $block['venue']);
        $this->assertEquals(800000.0, $block['guide_price_min']);
        $this->assertEquals(900000.0, $block['guide_price_max']);
        $this->assertStringContainsString((string) $auction->id, $block['registration_url']);
        $this->assertArrayNotHasKey('reserve_price', $block);
    }

    public function test_the_reserve_is_included_only_when_reserve_visibility_is_published(): void
    {
        $property = Property::create([
            'title' => 'Reserve visibility test', 'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id,
            'branch_id' => $this->branch->id, 'status' => 'active', 'listing_type' => 'sale', 'sale_method' => 'auction',
        ]);
        $auction = Auction::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'reference' => 'AUC-'.uniqid(),
            'title' => 'Reserve Test', 'bidding_mode' => 'online', 'auctioneer_kind' => 'internal', 'starts_at' => now()->addDays(7),
        ]);
        AuctionLot::create([
            'agency_id' => $this->agency->id, 'auction_id' => $auction->id, 'property_id' => $property->id,
            'lot_number' => 1, 'reserve_price' => 750000,
        ]);

        $this->assertArrayNotHasKey('reserve_price', $this->resourceFor($property)['auction']);

        AgencyAuctionSettings::updateOrCreate(['agency_id' => $this->agency->id], ['reserve_visibility' => 'published']);
        $this->assertEquals(750000.0, $this->resourceFor($property)['auction']['reserve_price']);
    }

    public function test_upcoming_viewings_are_listed(): void
    {
        $property = Property::create([
            'title' => 'Viewing test', 'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id,
            'branch_id' => $this->branch->id, 'status' => 'active', 'listing_type' => 'sale', 'sale_method' => 'auction',
        ]);
        $auction = Auction::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'reference' => 'AUC-'.uniqid(),
            'title' => 'Viewing Block Test', 'bidding_mode' => 'in_room', 'auctioneer_kind' => 'internal', 'starts_at' => now()->addDays(7),
        ]);
        $lot = AuctionLot::create([
            'agency_id' => $this->agency->id, 'auction_id' => $auction->id, 'property_id' => $property->id, 'lot_number' => 1,
        ]);
        AuctionLotViewing::create([
            'agency_id' => $this->agency->id, 'auction_lot_id' => $lot->id,
            'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHours(2),
        ]);
        AuctionLotViewing::create([
            'agency_id' => $this->agency->id, 'auction_lot_id' => $lot->id,
            'starts_at' => now()->subDays(2), 'ends_at' => now()->subDays(2)->addHours(2), // already past
        ]);

        $viewings = $this->resourceFor($property)['auction']['viewings'];

        $this->assertCount(1, $viewings);
    }

    public function test_a_concluded_lot_carries_no_auction_block(): void
    {
        $property = Property::create([
            'title' => 'Concluded lot', 'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id,
            'branch_id' => $this->branch->id, 'status' => 'sold', 'listing_type' => 'sale', 'sale_method' => 'auction',
        ]);
        $auction = Auction::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'reference' => 'AUC-'.uniqid(),
            'title' => 'Concluded Test', 'bidding_mode' => 'in_room', 'auctioneer_kind' => 'internal', 'starts_at' => now()->subDays(7),
        ]);
        AuctionLot::create([
            'agency_id' => $this->agency->id, 'auction_id' => $auction->id, 'property_id' => $property->id,
            'lot_number' => 1, 'status' => AuctionLot::STATUS_SOLD,
        ]);

        $this->assertNull($this->resourceFor($property)['auction']);
    }
}
