<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\Agency;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\AuctionLotStatusHistory;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\Auctions\AuctionLotStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-432 Phase 1 — .ai/specs/auctions.md §6.2, §19. Covers the state
 * machine AuctionLotStatusService owns: valid/invalid transitions, the
 * property-status snapshot-and-restore pairing, and the audit trail it
 * writes. Mirrors the exact scenarios manually verified via Tinker while
 * building this service (which caught two real bugs — the table-name
 * mismatch and a stale in-memory `status` right after create() — both
 * fixed in the model/service, not worked around here).
 */
final class AuctionLotStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private AuctionLotStatusService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Auction Test Agency', 'slug' => 'auction-test-'.uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->svc = new AuctionLotStatusService();
    }

    private function makeProperty(array $attrs = []): Property
    {
        $user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id]);

        return Property::create(array_merge([
            'title' => 'Auction test lot',
            'agency_id' => $this->agency->id,
            'agent_id' => $user->id,
            'branch_id' => $this->branch->id,
            'status' => 'active',
            'listing_type' => 'sale',
        ], $attrs));
    }

    private function makeAuction(array $attrs = []): Auction
    {
        return Auction::create(array_merge([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'reference' => 'AUC-'.uniqid(),
            'title' => 'Test Auction',
            'bidding_mode' => 'in_room',
            'auctioneer_kind' => 'internal',
            'starts_at' => now()->addDays(10),
        ], $attrs));
    }

    private function makeLot(Auction $auction, Property $property, array $attrs = []): AuctionLot
    {
        return AuctionLot::create(array_merge([
            'agency_id' => $this->agency->id,
            'auction_id' => $auction->id,
            'property_id' => $property->id,
            'lot_number' => 1,
        ], $attrs));
    }

    public function test_new_auction_and_lot_default_to_draft_without_a_refresh(): void
    {
        $auction = $this->makeAuction();
        $lot = $this->makeLot($auction, $this->makeProperty());

        // Regression: Eloquent does not re-fetch a column's DB-level default
        // after create() — without the model-level $attributes default this
        // read null, not 'draft', on the very object create() just returned.
        $this->assertSame(Auction::STATUS_DRAFT, $auction->status);
        $this->assertSame(AuctionLot::STATUS_DRAFT, $lot->status);
    }

    public function test_publish_catalogue_snapshots_status_and_marks_property_on_auction(): void
    {
        $property = $this->makeProperty(['status' => 'active']);
        $auction = $this->makeAuction();
        $lot = $this->makeLot($auction, $property);

        $this->svc->publishCatalogue($auction);

        $lot->refresh();
        $property->refresh();
        $auction->refresh();

        $this->assertSame(AuctionLot::STATUS_CATALOGUED, $lot->status);
        $this->assertSame(Property::STATUS_ON_AUCTION, $property->status);
        $this->assertSame('active', $property->pre_auction_status);
        $this->assertSame(Auction::STATUS_REGISTRATION_OPEN, $auction->status);
        $this->assertTrue($property->isAuction());
        $this->assertSame('On Auction', $property->statusBadge());
    }

    public function test_hammer_at_or_above_reserve_sells_the_lot_and_the_property(): void
    {
        $property = $this->makeProperty();
        $auction = $this->makeAuction();
        $lot = $this->makeLot($auction, $property, ['reserve_price' => 500000]);

        $this->svc->publishCatalogue($auction);
        $this->svc->openForBids($lot);
        $this->svc->startHammer($lot);
        $this->svc->recordHammer($lot, 750000.00);

        $lot->refresh();
        $property->refresh();

        $this->assertSame(AuctionLot::STATUS_SOLD, $lot->status);
        $this->assertTrue($lot->reserve_met);
        $this->assertEquals(750000.00, (float) $lot->hammer_price);
        $this->assertSame('sold', $property->status);
        $this->assertNull($property->pre_auction_status);
    }

    public function test_hammer_below_reserve_goes_to_confirmation_then_can_be_declined_to_passed_in(): void
    {
        $property = $this->makeProperty(['status' => 'prospecting']);
        $auction = $this->makeAuction();
        $lot = $this->makeLot($auction, $property, ['reserve_price' => 1000000]);

        $this->svc->publishCatalogue($auction);
        $this->svc->openForBids($lot);
        $this->svc->startHammer($lot);
        $this->svc->recordHammer($lot, 950000.00);

        $lot->refresh();
        $property->refresh();
        $this->assertSame(AuctionLot::STATUS_SOLD_SUBJECT_TO_CONFIRMATION, $lot->status);
        $this->assertFalse($lot->reserve_met);
        $this->assertNotNull($lot->confirmation_deadline);
        // Not yet concluded — property stays "On Auction" until the seller decides.
        $this->assertSame(Property::STATUS_ON_AUCTION, $property->status);

        $this->svc->declineSaleBelowReserve($lot);

        $lot->refresh();
        $property->refresh();
        $this->assertSame(AuctionLot::STATUS_PASSED_IN, $lot->status);
        $this->assertSame('prospecting', $property->status);
        $this->assertNull($property->pre_auction_status);
    }

    public function test_confirm_sale_below_reserve_moves_to_sold(): void
    {
        $property = $this->makeProperty();
        $auction = $this->makeAuction();
        $lot = $this->makeLot($auction, $property, ['reserve_price' => 1000000]);

        $this->svc->publishCatalogue($auction);
        $this->svc->openForBids($lot);
        $this->svc->startHammer($lot);
        $this->svc->recordHammer($lot, 900000.00);
        $this->svc->confirmSaleBelowReserve($lot);

        $lot->refresh();
        $property->refresh();
        $this->assertSame(AuctionLot::STATUS_SOLD, $lot->status);
        $this->assertNotNull($lot->confirmed_at);
        $this->assertSame('sold', $property->status);
    }

    public function test_withdraw_before_catalogue_publishes_never_touches_the_property(): void
    {
        $property = $this->makeProperty(['status' => 'active']);
        $auction = $this->makeAuction();
        $lot = $this->makeLot($auction, $property);

        $this->svc->withdraw($lot, null, 'seller changed mind');

        $lot->refresh();
        $property->refresh();
        $this->assertSame(AuctionLot::STATUS_WITHDRAWN, $lot->status);
        // Never catalogued, so pre_auction_status was never set — nothing to restore.
        $this->assertSame('active', $property->status);
        $this->assertNull($property->pre_auction_status);
    }

    public function test_a_terminal_lot_rejects_any_further_transition(): void
    {
        $property = $this->makeProperty();
        $auction = $this->makeAuction();
        $lot = $this->makeLot($auction, $property);

        $this->svc->withdraw($lot);

        $this->expectException(\RuntimeException::class);
        $this->svc->openForBids($lot);
    }

    public function test_every_transition_writes_an_audit_row(): void
    {
        $property = $this->makeProperty();
        $auction = $this->makeAuction();
        $lot = $this->makeLot($auction, $property, ['reserve_price' => 100]);

        $this->svc->publishCatalogue($auction);
        $this->svc->openForBids($lot);
        $this->svc->startHammer($lot);
        $this->svc->recordHammer($lot, 500.00);

        $rows = AuctionLotStatusHistory::where('auction_lot_id', $lot->id)->orderBy('id')->get();

        $this->assertSame(
            ['draft', 'catalogued', 'open_for_bids', 'under_the_hammer'],
            $rows->pluck('from_status')->all(),
        );
        $this->assertSame(
            ['catalogued', 'open_for_bids', 'under_the_hammer', 'sold'],
            $rows->pluck('to_status')->all(),
        );
    }
}
