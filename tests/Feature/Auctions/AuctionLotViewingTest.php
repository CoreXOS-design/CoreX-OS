<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\AuctionLotViewing;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\Auctions\AuctionLotStatusService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-432 Phase 5 — .ai/specs/auctions.md §5.6. Staff CRUD for viewing
 * windows on a lot — speced in Phase 1, deferred, and built now because
 * the public lot page and calendar (§16 auction_viewing) both need it.
 */
final class AuctionLotViewingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private AuctionLot $lot;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionService::clearCache();

        $this->agency = Agency::create(['name' => 'Viewing Test Agency', 'slug' => 'viewing-test-'.uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        AgencyFeature::updateOrCreate(['agency_id' => $this->agency->id, 'feature_key' => 'auctions'], ['enabled' => true]);
        config(['features.auctions' => true]);
        app(\App\Services\Features\AgencyFeatureService::class)->forget($this->agency->id);

        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id]);
        $property = Property::create([
            'title' => 'Viewing test lot', 'agency_id' => $this->agency->id, 'agent_id' => $agent->id,
            'branch_id' => $branch->id, 'status' => 'active', 'listing_type' => 'sale',
        ]);
        $auction = Auction::create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'reference' => 'AUC-VIEWING-TEST',
            'title' => 'Viewing Test Auction', 'bidding_mode' => 'in_room', 'auctioneer_kind' => 'internal',
            'starts_at' => now()->addDays(10),
        ]);
        $this->lot = AuctionLot::create([
            'agency_id' => $this->agency->id, 'auction_id' => $auction->id, 'property_id' => $property->id, 'lot_number' => 1,
        ]);
        (new AuctionLotStatusService())->publishCatalogue($auction);

        $this->actingAs($this->user);
    }

    public function test_a_viewing_can_be_added(): void
    {
        $this->post(route('corex.auctions.lots.viewings.store', $this->lot), [
            'starts_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
            'ends_at' => now()->addDays(3)->addHours(2)->format('Y-m-d\TH:i'),
            'is_by_appointment' => '1',
            'notes' => 'Bring photo ID',
        ])->assertRedirect(route('corex.auctions.lots.show', $this->lot));

        $viewing = AuctionLotViewing::where('auction_lot_id', $this->lot->id)->first();
        $this->assertNotNull($viewing);
        $this->assertTrue($viewing->is_by_appointment);
        $this->assertSame('Bring photo ID', $viewing->notes);
    }

    public function test_ends_at_must_be_after_starts_at(): void
    {
        $this->post(route('corex.auctions.lots.viewings.store', $this->lot), [
            'starts_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
            'ends_at' => now()->addDays(3)->subHour()->format('Y-m-d\TH:i'),
        ])->assertSessionHasErrors('ends_at');
    }

    public function test_a_viewing_can_be_removed_and_is_soft_deleted(): void
    {
        $viewing = AuctionLotViewing::create([
            'agency_id' => $this->agency->id, 'auction_lot_id' => $this->lot->id,
            'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHours(2),
        ]);

        $this->delete(route('corex.auctions.lots.viewings.destroy', [$this->lot, $viewing]))
            ->assertRedirect(route('corex.auctions.lots.show', $this->lot));

        $this->assertSoftDeleted($viewing);
    }

    public function test_a_viewing_belonging_to_a_different_lot_404s(): void
    {
        $otherLot = AuctionLot::create([
            'agency_id' => $this->agency->id, 'auction_id' => $this->lot->auction_id,
            'property_id' => $this->lot->property_id, 'lot_number' => 2,
        ]);
        $viewing = AuctionLotViewing::create([
            'agency_id' => $this->agency->id, 'auction_lot_id' => $otherLot->id,
            'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHours(2),
        ]);

        $this->delete(route('corex.auctions.lots.viewings.destroy', [$this->lot, $viewing]))
            ->assertNotFound();
    }
}
