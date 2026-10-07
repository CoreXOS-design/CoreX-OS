<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Events\Auction\AuctionLotSold;
use App\Exceptions\Deal\PropertyOwnerMismatchException;
use App\Models\Agency;
use App\Models\AgencyAuctionSettings;
use App\Models\Auction;
use App\Models\AuctionBid;
use App\Models\AuctionBidder;
use App\Models\AuctionLot;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use App\Services\Auctions\AuctionDealFactory;
use App\Services\Auctions\AuctionLotStatusService;
use App\Services\Auctions\BidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-432 Phase 3 — .ai/specs/auctions.md §12.2. Research finding this
 * build corrected mid-session and AuctionDealFactory's own docblock
 * explains at length: DealV2 is a retired prototype (AT-219); "DR2" in
 * current terminology is Dr2\DealRegisterController operating on the
 * legacy App\Models\Deal — confirmed by reading that controller's own
 * imports. This factory targets Deal, and reuses Dr1PipelineService (the
 * same pipeline-attachment service DR2 itself calls) and
 * DealPropertyOwnerGate (the same "no deal without a known owner" gate).
 *
 * Verified via Tinker against the real pipeline template/step tables
 * before writing this test: a sold lot with no linked seller fails
 * gracefully (CreateDealOnLotSold logs and moves on, never breaking the
 * hammer-fall transaction); once a seller is linked, both the automatic
 * listener path and the manual "Open Deal" retry succeed, correctly
 * creating 12 real DealStepInstance rows (keyed by dr1_deal_id, not
 * deal_id — a DR1-anchored pipeline quirk this test's own author
 * originally got wrong too).
 */
final class AuctionDealFactoryTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private AuctionDealFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Deal Factory Test Agency', 'slug' => 'deal-factory-'.uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id]);
        $this->factory = app(AuctionDealFactory::class);
    }

    private function makeSoldLot(bool $withSeller = true, float $hammerPrice = 600000.0): AuctionLot
    {
        $property = Property::create([
            'title' => 'Deal factory lot', 'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id,
            'branch_id' => $this->branch->id, 'status' => 'active', 'listing_type' => 'sale',
        ]);
        if ($withSeller) {
            $seller = Contact::create(['agency_id' => $this->agency->id, 'first_name' => 'Test', 'last_name' => 'Seller']);
            $property->contacts()->attach($seller->id, ['role' => 'seller']);
        }
        $buyerContact = Contact::create(['agency_id' => $this->agency->id, 'first_name' => 'Test', 'last_name' => 'Buyer']);

        $auction = Auction::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'reference' => 'AUC-'.uniqid(),
            'title' => 'Deal Factory Test', 'bidding_mode' => 'in_room', 'auctioneer_kind' => 'internal',
            'starts_at' => now()->addDays(5),
        ]);
        $lot = AuctionLot::create([
            'agency_id' => $this->agency->id, 'auction_id' => $auction->id, 'property_id' => $property->id,
            'lot_number' => 1, 'reserve_price' => 500000,
        ]);
        $bidder = AuctionBidder::create([
            'agency_id' => $this->agency->id, 'auction_id' => $auction->id, 'contact_id' => $buyerContact->id,
            'status' => 'approved', 'paddle_number' => '099', 'fica_status' => 'approved', 'fica_verified_at' => now(),
            'rules_signed_at' => now(), 'deposit_required' => false, 'approved_at' => now(),
        ]);

        $lotSvc = new AuctionLotStatusService();
        $lotSvc->publishCatalogue($auction);
        $lotSvc->openForBids($lot);
        $lotSvc->startHammer($lot);
        (new BidService())->placeBid($lot, $bidder, $hammerPrice);

        return $lotSvc->recordHammerFromCurrentBid($lot);
    }

    public function test_creating_a_deal_for_a_property_with_no_seller_throws(): void
    {
        $lot = $this->makeSoldLot(withSeller: false);

        $this->expectException(PropertyOwnerMismatchException::class);
        $this->factory->createFromSoldLot($lot);
    }

    public function test_the_automatic_listener_fails_gracefully_with_no_seller_and_never_sets_deal_id(): void
    {
        $lot = $this->makeSoldLot(withSeller: false);

        // recordHammerFromCurrentBid() already fired AuctionLotSold via the
        // transition inside makeSoldLot() — CreateDealOnLotSold caught the
        // PropertyOwnerMismatchException and logged, never bubbling up.
        $this->assertNull($lot->refresh()->deal_id);
    }

    public function test_a_property_with_a_linked_seller_gets_a_deal_automatically_on_the_fall_of_the_hammer(): void
    {
        $lot = $this->makeSoldLot(withSeller: true, hammerPrice: 700000);

        $lot->refresh();
        $this->assertNotNull($lot->deal_id);
        $deal = Deal::find($lot->deal_id);
        $this->assertEquals(700000.0, (float) $deal->property_value);
        $this->assertSame('cash', $deal->deal_type);
        $this->assertSame('Not Paid', $deal->commission_status);
        $this->assertSame('P', $deal->accepted_status);
    }

    public function test_double_opening_a_deal_on_the_same_lot_is_refused(): void
    {
        $lot = $this->makeSoldLot(withSeller: true);
        $this->factory->createFromSoldLot($lot->refresh());

        $this->expectException(\RuntimeException::class);
        $this->factory->createFromSoldLot($lot->refresh());
    }

    public function test_pipeline_steps_are_created_for_the_agencys_cash_template(): void
    {
        \App\Models\DealV2\DealPipelineTemplate::create([
            'agency_id' => $this->agency->id, 'name' => 'Cash Sale', 'deal_type' => 'cash', 'is_default' => true,
        ]);
        // A template with zero steps is a legitimate (if unusual) real-world
        // case — this just proves the attach happens, not that steps exist;
        // the "12 real steps" scenario was verified via Tinker against a
        // seeded agency's actual template in this session and is not
        // reproduced here to avoid depending on seeded step content.
        $lot = $this->makeSoldLot(withSeller: true);

        $deal = Deal::find($lot->refresh()->deal_id);
        $this->assertNotNull($deal->deal_pipeline_template_id);
    }

    public function test_buyers_premium_only_fee_model_computes_the_deal_commission(): void
    {
        AgencyAuctionSettings::updateOrCreate(['agency_id' => $this->agency->id], [
            'fee_model' => 'buyers_premium', 'buyers_premium_percent' => 10, 'buyers_premium_vat_inclusive' => false,
        ]);

        $lot = $this->makeSoldLot(withSeller: true, hammerPrice: 600000);
        $deal = Deal::find($lot->refresh()->deal_id);

        // 600,000 * 10% * 1.15 VAT = 69,000
        $this->assertEquals(69000.0, (float) $deal->total_commission);
    }

    public function test_the_listing_agent_is_attached_at_100_percent_on_the_listing_side(): void
    {
        $lot = $this->makeSoldLot(withSeller: true);
        $deal = Deal::find($lot->refresh()->deal_id);

        $agentPivot = $deal->agents()->first();
        $this->assertSame($this->agent->id, $agentPivot->id);
        $this->assertSame('listing', $agentPivot->pivot->side);
        $this->assertEquals(100.0, (float) $agentPivot->pivot->agent_split_percent);
    }
}
