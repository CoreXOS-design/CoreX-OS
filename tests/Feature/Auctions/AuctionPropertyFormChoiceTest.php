<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Auction;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\Auctions\AuctionLotAttacher;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-432 — spec §2.1: the listing-type picker on BOTH new-property forms
 * (classic + wizard) gains a third choice, "On Auction", only for an agency
 * with Auctions on.
 */
final class AuctionPropertyFormChoiceTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionService::clearCache();

        $this->agency = Agency::create(['name' => 'Form Choice Agency', 'slug' => 'form-choice-'.uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        config(['features.auctions' => true]);
    }

    private function auctionsOn(bool $on): void
    {
        AgencyFeature::updateOrCreate(['agency_id' => $this->agency->id, 'feature_key' => 'auctions'], ['enabled' => $on]);
        app(\App\Services\Features\AgencyFeatureService::class)->forget($this->agency->id);
    }

    public function test_classic_form_offers_on_auction_when_the_feature_is_on(): void
    {
        $this->auctionsOn(true);
        $this->actingAs($this->admin)->get(route('corex.properties.create'))
            ->assertOk()->assertSee('On Auction')->assertSee('Which auction?');
    }

    public function test_classic_form_hides_on_auction_when_the_feature_is_off(): void
    {
        $this->auctionsOn(false);
        $this->actingAs($this->admin)->get(route('corex.properties.create'))
            ->assertOk()->assertDontSee('Which auction?');
    }

    public function test_wizard_offers_on_auction_tile_when_the_feature_is_on_and_not_when_off(): void
    {
        $this->auctionsOn(true);
        $this->actingAs($this->admin)->get(route('corex.properties.wizard'))->assertOk()->assertSee('Which auction?');
        $this->auctionsOn(false);
        $this->actingAs($this->admin)->get(route('corex.properties.wizard'))->assertOk()->assertDontSee('Which auction?');
    }

    public function test_attacher_makes_a_lot_and_flips_sale_method_together(): void
    {
        $this->auctionsOn(true);
        $this->actingAs($this->admin);
        $auction = Auction::create([
            'agency_id' => $this->agency->id, 'reference' => 'AUC-FORM-'.uniqid(), 'title' => 'Form auction',
            'bidding_mode' => 'in_room', 'auctioneer_kind' => 'internal', 'starts_at' => now()->addDays(5),
        ]);
        $property = Property::create([
            'title' => 'Form lot', 'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id,
            'status' => 'active', 'listing_type' => 'sale',
        ]);

        $this->assertCount(1, AuctionLotAttacher::openAuctions());
        $lot = app(AuctionLotAttacher::class)->attach($auction, $property);

        $this->assertSame(1, $lot->lot_number);
        $this->assertTrue($property->fresh()->isAuction());
    }

    public function test_staff_auction_pages_carry_the_themed_wrapper(): void
    {
        $this->auctionsOn(true);
        $this->actingAs($this->admin)->get(route('corex.auctions.index'))->assertOk()->assertSee('corex-auctions', false);
    }

    public function test_every_restyled_staff_page_still_renders(): void
    {
        $this->auctionsOn(true);
        \App\Models\AgencyAuctionSettings::updateOrCreate(['agency_id' => $this->agency->id], ['advertising_only' => false]);
        $this->actingAs($this->admin);
        $auction = Auction::create([
            'agency_id' => $this->agency->id, 'reference' => 'AUC-SMOKE-'.uniqid(), 'title' => 'Smoke auction',
            'bidding_mode' => 'in_room', 'auctioneer_kind' => 'internal', 'starts_at' => now()->addDays(5),
        ]);
        $property = Property::create([
            'title' => 'Smoke lot', 'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id,
            'status' => 'active', 'listing_type' => 'sale',
        ]);
        $lot = app(AuctionLotAttacher::class)->attach($auction, $property);

        foreach ([
            route('corex.auctions.index'),
            route('corex.auctions.create'),
            route('corex.auctions.edit', $auction),
            route('corex.auctions.show', $auction),
            route('corex.auctions.lots.show', $lot),
            route('corex.auctions.results'),
            route('corex.auctions.bidders.index', $auction),
            route('corex.auctions.bidders.create', $auction),
            route('corex.auctions.room.show', $auction),
            route('corex.settings.auctions.show'),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }
}
