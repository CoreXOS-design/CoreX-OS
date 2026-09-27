<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-432 — .ai/specs/auctions.md §9 steps 1-2. Covers the two ways a
 * property becomes an auction lot (AuctionController::attachPropertyAsLot(),
 * shared by addLot() and the inline "Send to Auction" path in store()), and
 * that a draft lot can be removed cleanly without leaving sale_method
 * stranded on 'auction'.
 */
final class AuctionControllerAttachTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionService::clearCache();

        $this->agency = Agency::create(['name' => 'Attach Test Agency', 'slug' => 'attach-test-'.uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        AgencyFeature::updateOrCreate(['agency_id' => $this->agency->id, 'feature_key' => 'auctions'], ['enabled' => true]);
        config(['features.auctions' => true]);
        app(\App\Services\Features\AgencyFeatureService::class)->forget($this->agency->id);

        $this->actingAs($this->user);
    }

    private function makeProperty(): Property
    {
        return Property::create([
            'title' => 'Attach test lot', 'agency_id' => $this->agency->id, 'agent_id' => $this->user->id,
            'branch_id' => $this->branch->id, 'status' => 'active', 'listing_type' => 'sale',
        ]);
    }

    public function test_creating_an_auction_with_a_property_id_attaches_it_as_lot_one(): void
    {
        $property = $this->makeProperty();

        $response = $this->post(route('corex.auctions.store'), [
            'reference' => 'AUC-ATTACH-1', 'title' => 'Attach Test', 'bidding_mode' => 'in_room',
            'auctioneer_kind' => 'internal', 'starts_at' => now()->addDays(10)->format('Y-m-d H:i:s'),
            'property_id' => $property->id, 'reserve_price' => 500000,
        ]);

        $auction = Auction::where('reference', 'AUC-ATTACH-1')->first();
        $response->assertRedirect(route('corex.auctions.show', $auction));

        $lot = $auction->lots()->first();
        $this->assertNotNull($lot);
        $this->assertSame(1, $lot->lot_number);
        $this->assertEquals(500000, (float) $lot->reserve_price);

        $property->refresh();
        $this->assertTrue($property->isAuction());
    }

    public function test_add_lot_to_an_existing_auction_sets_sale_method(): void
    {
        $property = $this->makeProperty();
        $auction = Auction::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'reference' => 'AUC-ATTACH-2',
            'title' => 'Existing Auction', 'bidding_mode' => 'in_room', 'auctioneer_kind' => 'internal',
            'starts_at' => now()->addDays(10),
        ]);

        $this->post(route('corex.auctions.lots.add', $auction), ['property_id' => $property->id])
            ->assertRedirect(route('corex.auctions.show', $auction));

        $property->refresh();
        $this->assertTrue($property->isAuction());
        $this->assertSame(1, $auction->lots()->first()->lot_number);
    }

    public function test_a_second_lot_in_the_same_auction_gets_the_next_lot_number(): void
    {
        $auction = Auction::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'reference' => 'AUC-ATTACH-3',
            'title' => 'Multi-lot Auction', 'bidding_mode' => 'in_room', 'auctioneer_kind' => 'internal',
            'starts_at' => now()->addDays(10),
        ]);
        $this->post(route('corex.auctions.lots.add', $auction), ['property_id' => $this->makeProperty()->id]);
        $this->post(route('corex.auctions.lots.add', $auction), ['property_id' => $this->makeProperty()->id]);

        $this->assertSame([1, 2], $auction->lots()->orderBy('lot_number')->pluck('lot_number')->all());
    }

    public function test_removing_a_draft_lot_reverts_sale_method_when_it_was_the_propertys_only_lot(): void
    {
        $property = $this->makeProperty();
        $auction = Auction::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'reference' => 'AUC-ATTACH-4',
            'title' => 'Removable', 'bidding_mode' => 'in_room', 'auctioneer_kind' => 'internal',
            'starts_at' => now()->addDays(10),
        ]);
        $this->post(route('corex.auctions.lots.add', $auction), ['property_id' => $property->id]);
        $lot = $auction->lots()->first();

        $this->delete(route('corex.auctions.lots.remove', [$auction, $lot]))
            ->assertRedirect(route('corex.auctions.show', $auction));

        $property->refresh();
        $this->assertFalse($property->isAuction());
        $this->assertSoftDeleted($lot);
    }

    public function test_removing_a_catalogued_lot_is_refused_and_withdraw_must_be_used_instead(): void
    {
        $property = $this->makeProperty();
        $auction = Auction::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'reference' => 'AUC-ATTACH-5',
            'title' => 'Published', 'bidding_mode' => 'in_room', 'auctioneer_kind' => 'internal',
            'starts_at' => now()->addDays(10),
        ]);
        $this->post(route('corex.auctions.lots.add', $auction), ['property_id' => $property->id]);
        $lot = $auction->lots()->first();
        (new \App\Services\Auctions\AuctionLotStatusService())->publishCatalogue($auction);

        $this->delete(route('corex.auctions.lots.remove', [$auction, $lot->refresh()]))
            ->assertSessionHasErrors('lot');

        $this->assertNotSoftDeleted($lot->refresh());
    }
}
