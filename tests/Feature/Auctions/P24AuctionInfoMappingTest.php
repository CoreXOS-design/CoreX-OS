<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\Agency;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\Syndication\Property24\Property24ListingMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-432 Phase 5 — .ai/specs/auctions.md §14.1a. Confirmed live (against a
 * real Property24 listing) that AUCTION and POA are two independent tags
 * driven by separate fields — `auctionInfo` is what actually drives the
 * badge; `status` has no Auction member at all and correctly stays
 * 'Active'. This needs a DB-backed property (Property::currentAuctionLot()
 * queries the real relationship), unlike the rest of the mapper's pure
 * unit tests in tests/Unit/Syndication/.
 */
final class P24AuctionInfoMappingTest extends TestCase
{
    use RefreshDatabase;

    private function makeProperty(Agency $agency, Branch $branch, User $agent, array $attrs = []): Property
    {
        return Property::create(array_merge([
            'title' => 'P24 auction mapping test', 'agency_id' => $agency->id, 'agent_id' => $agent->id,
            'branch_id' => $branch->id, 'status' => 'active', 'listing_type' => 'sale',
        ], $attrs));
    }

    public function test_an_auction_property_gets_auction_info_with_date_and_venue(): void
    {
        $agency = Agency::create(['name' => 'P24 Auction Mapping Agency', 'slug' => 'p24-auction-'.uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id]);
        $property = $this->makeProperty($agency, $branch, $agent, ['sale_method' => 'auction']);

        $auction = Auction::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'reference' => 'AUC-P24TEST',
            'title' => 'P24 Mapping Test', 'bidding_mode' => 'in_room', 'auctioneer_kind' => 'internal',
            'starts_at' => \Carbon\Carbon::parse('2026-11-15 15:00:00'), 'venue_name' => 'The Boardwalk, Shelly Beach',
        ]);
        AuctionLot::create(['agency_id' => $agency->id, 'auction_id' => $auction->id, 'property_id' => $property->id, 'lot_number' => 1]);

        $listing = (new Property24ListingMapper())->map($property->refresh(), false);

        $this->assertSame('2026-11-15T15:00:00', $listing['auctionInfo']['date']);
        $this->assertSame('The Boardwalk, Shelly Beach', $listing['auctionInfo']['venue']);
        // §14.1a: status has no Auction member on P24 — must stay 'Active', never invented.
        $this->assertSame('Active', $listing['status']);
    }

    public function test_a_private_treaty_property_never_gets_auction_info(): void
    {
        $agency = Agency::create(['name' => 'P24 Non-Auction Agency', 'slug' => 'p24-nonauction-'.uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id]);
        $property = $this->makeProperty($agency, $branch, $agent, ['sale_method' => null]);

        $listing = (new Property24ListingMapper())->map($property->refresh(), false);

        $this->assertArrayNotHasKey('auctionInfo', $listing);
    }

    public function test_auction_info_omits_venue_when_none_is_set(): void
    {
        $agency = Agency::create(['name' => 'P24 No-Venue Agency', 'slug' => 'p24-novenue-'.uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id]);
        $property = $this->makeProperty($agency, $branch, $agent, ['sale_method' => 'auction']);

        $auction = Auction::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'reference' => 'AUC-P24NOVENUE',
            'title' => 'No Venue Test', 'bidding_mode' => 'online', 'auctioneer_kind' => 'internal',
            'starts_at' => \Carbon\Carbon::parse('2026-12-01 10:00:00'),
        ]);
        AuctionLot::create(['agency_id' => $agency->id, 'auction_id' => $auction->id, 'property_id' => $property->id, 'lot_number' => 1]);

        $listing = (new Property24ListingMapper())->map($property->refresh(), false);

        $this->assertSame('2026-12-01T10:00:00', $listing['auctionInfo']['date']);
        $this->assertArrayNotHasKey('venue', $listing['auctionInfo']);
    }
}
