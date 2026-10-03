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

    private function makeAuction(string $reference): Auction
    {
        return Auction::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'reference' => $reference,
            'title' => 'Auction '.$reference, 'bidding_mode' => 'in_room', 'auctioneer_kind' => 'internal',
            'starts_at' => now()->addDays(10),
        ]);
    }

    /** QA2 2026-10-03 — the next lot number ignored removed lots while the unique index still counted them: a 500. */
    public function test_a_property_can_be_attached_again_after_its_lot_was_removed(): void
    {
        $property = $this->makeProperty();
        $auction = $this->makeAuction('AUC-REATTACH');

        $this->post(route('corex.auctions.lots.add', $auction), ['property_id' => $property->id]);
        $this->delete(route('corex.auctions.lots.remove', [$auction, $auction->lots()->first()]));

        $this->post(route('corex.auctions.lots.add', $auction), ['property_id' => $property->id, 'reserve_price' => 0])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('corex.auctions.show', $auction));

        $lot = $auction->lots()->sole();
        $this->assertSame(2, $lot->lot_number);
        $this->assertTrue($property->refresh()->isAuction());
    }

    public function test_a_property_open_in_another_auction_cannot_be_attached_until_it_is_released_there(): void
    {
        $property = $this->makeProperty();
        $first = $this->makeAuction('AUC-FIRST');
        $second = $this->makeAuction('AUC-SECOND');

        $this->post(route('corex.auctions.lots.add', $first), ['property_id' => $property->id]);

        $this->post(route('corex.auctions.lots.add', $second), ['property_id' => $property->id])
            ->assertSessionHasErrors('property_id');
        $this->assertSame(0, $second->lots()->count());
        $this->assertSame([], $this->getJson(route('api.v1.auctions.property-search', $second).'?q=Attach')->assertOk()->json());

        $this->delete(route('corex.auctions.lots.remove', [$first, $first->lots()->first()]));

        $this->post(route('corex.auctions.lots.add', $second), ['property_id' => $property->id])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $second->lots()->count());
    }

    public function test_rentals_and_concluded_listings_are_neither_offered_nor_accepted(): void
    {
        $auction = $this->makeAuction('AUC-ELIGIBLE');
        $sale = $this->makeProperty();
        $rental = $this->makeProperty();
        $rental->forceFill(['listing_type' => 'rental'])->save();
        $sold = $this->makeProperty();
        $sold->forceFill(['status' => 'sold'])->save();

        $offered = collect($this->getJson(route('api.v1.auctions.property-search', $auction).'?q=Attach')->assertOk()->json())->pluck('id')->all();
        $this->assertSame([$sale->id], $offered);

        foreach ([$rental, $sold] as $refused) {
            $this->post(route('corex.auctions.lots.add', $auction), ['property_id' => $refused->id])
                ->assertSessionHasErrors('property_id');
        }
        $this->assertSame(0, $auction->lots()->count());
    }

    public function test_lot_prices_can_be_corrected_until_bidding_opens(): void
    {
        $auction = $this->makeAuction('AUC-PRICES');
        $this->post(route('corex.auctions.lots.add', $auction), ['property_id' => $this->makeProperty()->id]);
        $lot = $auction->lots()->first();

        $this->put(route('corex.auctions.lots.prices.update', $lot), [
            'reserve_price' => 0, 'opening_bid' => 900000, 'guide_price_min' => 1200000, 'guide_price_max' => 1600000,
        ])->assertSessionHasNoErrors()->assertRedirect(route('corex.auctions.lots.show', $lot));

        $lot->refresh();
        $this->assertNotNull($lot->reserve_price);
        $this->assertEquals(0, (float) $lot->reserve_price);
        $this->assertEquals(900000, (float) $lot->opening_bid);
        $this->assertEquals(1600000, (float) $lot->guide_price_max);

        $this->put(route('corex.auctions.lots.prices.update', $lot), ['guide_price_min' => 2000000, 'guide_price_max' => 1000000])
            ->assertSessionHasErrors('guide_price_max');
        $this->assertEquals(1200000, (float) $lot->refresh()->guide_price_min);

        $lot->forceFill(['status' => AuctionLot::STATUS_SOLD])->save();
        $this->put(route('corex.auctions.lots.prices.update', $lot), ['opening_bid' => 1])
            ->assertSessionHasErrors('lot');
        $this->assertEquals(900000, (float) $lot->refresh()->opening_bid);
    }

    /** Unnamed, the enquiry limit shared one counter with the public page views and refused buyers who had browsed. */
    public function test_the_public_enquiry_limit_has_its_own_counter(): void
    {
        $middleware = app('router')->getRoutes()->getByName('public.auctions.enquire')->gatherMiddleware();

        $this->assertContains('throttle:10,1,auction-enquiry', $middleware);
    }
}
