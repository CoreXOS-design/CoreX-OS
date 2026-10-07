<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\Agency;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\MarketingCopyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AT-432 Phase 5 — .ai/specs/auctions.md §14.5. The AI ad-copy generator's
 * STRICT grounding contract means auction context must ride the same
 * closed allowlist as every other fact (collectAllowedFacts()) — never a
 * second, separate injection point the grounding rules don't cover.
 * ANTHROPIC_API_KEY is empty locally (noted in the spec itself), so this
 * covers the fact-collection input to generateAdCopy(), not a live call.
 */
final class AuctionAdCopyFactsTest extends TestCase
{
    use RefreshDatabase;

    private function collectFacts(Property $property): string
    {
        $svc = new MarketingCopyService();
        $m = new ReflectionMethod($svc, 'collectAllowedFacts');
        $m->setAccessible(true);

        return $m->invoke($svc, $property)[0];
    }

    public function test_an_auction_listing_gets_date_venue_guide_price_and_deadline(): void
    {
        $agency = Agency::create(['name' => 'Ad Copy Auction Agency', 'slug' => 'adcopy-auction-'.uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id]);
        $property = Property::create([
            'title' => 'Ad copy auction test', 'agency_id' => $agency->id, 'agent_id' => $agent->id,
            'branch_id' => $branch->id, 'status' => 'active', 'listing_type' => 'sale', 'sale_method' => 'auction',
        ]);
        $auction = Auction::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'reference' => 'AUC-'.uniqid(),
            'title' => 'Ad Copy Test', 'bidding_mode' => 'in_room', 'auctioneer_kind' => 'internal',
            'starts_at' => now()->addDays(30), 'venue_name' => 'Beachfront Hotel',
            'registration_closes_at' => now()->addDays(28),
        ]);
        AuctionLot::create([
            'agency_id' => $agency->id, 'auction_id' => $auction->id, 'property_id' => $property->id,
            'lot_number' => 1, 'guide_price_min' => 1500000, 'guide_price_max' => 1800000,
        ]);

        $facts = $this->collectFacts($property->refresh());

        $this->assertStringContainsString('AUCTION listing', $facts);
        $this->assertStringContainsString('Beachfront Hotel', $facts);
        $this->assertStringContainsString('1 500 000', $facts);
        $this->assertStringContainsString('1 800 000', $facts);
        $this->assertStringContainsString('Registration deadline', $facts);
    }

    public function test_a_private_treaty_listing_never_gets_auction_facts(): void
    {
        $agency = Agency::create(['name' => 'Ad Copy Non-Auction Agency', 'slug' => 'adcopy-nonauction-'.uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id]);
        $property = Property::create([
            'title' => 'Ad copy non-auction test', 'agency_id' => $agency->id, 'agent_id' => $agent->id,
            'branch_id' => $branch->id, 'status' => 'active', 'listing_type' => 'sale',
        ]);

        $facts = $this->collectFacts($property->refresh());

        $this->assertStringNotContainsString('AUCTION', $facts);
        $this->assertStringNotContainsString('Guide price', $facts);
    }
}
