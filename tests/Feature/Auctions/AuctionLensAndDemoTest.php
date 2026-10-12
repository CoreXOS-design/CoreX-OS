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
use App\Services\Auctions\AuctionLotAttacher;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AT-432 — Auctions → Properties lens filters (spec §8.2) and the demo-data command.
 */
final class AuctionLensAndDemoTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionService::clearCache();
        $this->agency = Agency::create(['name' => 'Lens Agency', 'slug' => 'lens-'.uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->admin = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
            'ffc_expiry_date' => now()->addYear(),
        ]);
        AgencyFeature::updateOrCreate(['agency_id' => $this->agency->id, 'feature_key' => 'auctions'], ['enabled' => true]);
        config(['features.auctions' => true]);
        app(\App\Services\Features\AgencyFeatureService::class)->forget($this->agency->id);
    }

    private function auction(string $ref, int $days): Auction
    {
        return Auction::create([
            'agency_id' => $this->agency->id, 'reference' => $ref, 'title' => 'Auction '.$ref,
            'bidding_mode' => 'in_room', 'auctioneer_kind' => 'internal', 'starts_at' => now()->addDays($days),
        ]);
    }

    private function lotFor(Auction $a, string $title, array $lot = []): AuctionLot
    {
        $p = Property::create([
            'title' => $title, 'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id,
            'branch_id' => $this->branch->id, 'status' => 'on_auction', 'listing_type' => 'sale',
        ]);
        $l = app(AuctionLotAttacher::class)->attach($a, $p);
        if ($lot) {
            $l->update($lot);
        }
        return $l;
    }

    public function test_lens_shows_the_auction_filters_and_only_auction_stock(): void
    {
        $this->actingAs($this->admin);
        $a = $this->auction('A-1', 5);
        $this->lotFor($a, 'Lens Auction Villa');
        Property::create(['title' => 'Lens Plain Sale', 'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id,
            'branch_id' => $this->branch->id, 'status' => 'active', 'listing_type' => 'sale']);

        $this->get(route('corex.auctions.properties.index'))
            ->assertOk()
            ->assertSee('All auctions')->assertSee('Any lot status')->assertSee('Reserve: any')->assertSee('Auctions only')
            ->assertSee('Lens Auction Villa')->assertDontSee('Lens Plain Sale')
            ->assertSee('Lot 1');
    }

    public function test_filter_by_auction_lot_status_and_reserve(): void
    {
        $this->actingAs($this->admin);
        $a = $this->auction('A-2', 5);
        $b = $this->auction('B-2', 9);
        $this->lotFor($a, 'Lens In Auction A', ['status' => 'catalogued', 'reserve_met' => null]);
        $this->lotFor($b, 'Lens In Auction B', ['status' => 'sold', 'reserve_met' => true]);

        $this->get(route('corex.auctions.properties.index', ['auction_id' => $a->id]))
            ->assertSee('Lens In Auction A')->assertDontSee('Lens In Auction B');
        $this->get(route('corex.auctions.properties.index', ['lot_status' => 'sold']))
            ->assertSee('Lens In Auction B')->assertDontSee('Lens In Auction A');
        $this->get(route('corex.auctions.properties.index', ['reserve_met' => '1']))
            ->assertSee('Lens In Auction B')->assertDontSee('Lens In Auction A');
    }

    public function test_sort_by_lot_number_and_auction_date_runs(): void
    {
        $this->actingAs($this->admin);
        $a = $this->auction('A-3', 20);
        $b = $this->auction('B-3', 2);
        $this->lotFor($a, 'Lens Later Auction');
        $this->lotFor($b, 'Lens Sooner Auction');

        $html = $this->get(route('corex.auctions.properties.index', ['sort' => 'auction_date', 'dir' => 'asc']))->assertOk()->getContent();
        $this->assertLessThan(strpos($html, 'Lens Later Auction'), strpos($html, 'Lens Sooner Auction'));
        $this->get(route('corex.auctions.properties.index', ['sort' => 'lot_number', 'dir' => 'asc']))->assertOk();
    }

    public function test_the_lock_cannot_be_escaped_by_the_url(): void
    {
        $this->actingAs($this->admin);
        Property::create(['title' => 'Lens Plain Sale 2', 'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id,
            'branch_id' => $this->branch->id, 'status' => 'active', 'listing_type' => 'sale']);
        $this->get(route('corex.auctions.properties.index', ['sale_method' => 'private_treaty']))->assertDontSee('Lens Plain Sale 2');
    }

    public function test_demo_command_seeds_publishes_serves_pdfs_and_purges_cleanly(): void
    {
        Storage::fake('local');
        $this->artisan('auctions:seed-demo', ['--agency' => $this->agency->id])->assertSuccessful();

        $auctions = Auction::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('reference', 'like', 'DEMO-AUC-%')->get();
        $this->assertCount(4, $auctions);
        $a = $auctions->firstWhere('reference', 'DEMO-AUC-001');
        $draft = $auctions->firstWhere('reference', 'DEMO-AUC-004');

        $this->get(route('public.auctions.show', $a->id))->assertOk()->assertSee('South Coast Property Auction')->assertSee('without reserve', false);
        $this->get(route('public.auctions.document', [$a->id, 'rules']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('public.auctions.show', $draft->id))->assertNotFound();

        // idempotent: a second run changes nothing
        $this->artisan('auctions:seed-demo', ['--agency' => $this->agency->id])->assertSuccessful();
        $this->assertSame(4, Auction::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('reference', 'like', 'DEMO-AUC-%')->count());

        // purge archives (no hard delete) and frees the references so it can be re-seeded
        $this->artisan('auctions:seed-demo', ['--agency' => $this->agency->id, '--purge' => true])->assertSuccessful();
        $this->assertSame(0, Auction::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('reference', 'like', 'DEMO-AUC-%')->where('reference', 'not like', '%~purged-%')->whereNull('deleted_at')->count());
        $this->assertGreaterThan(0, Auction::withTrashed()->withoutGlobalScopes()->where('reference', 'like', '%~purged-%')->count());
        $this->artisan('auctions:seed-demo', ['--agency' => $this->agency->id])->assertSuccessful();
        $this->assertSame(4, Auction::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('reference', 'like', 'DEMO-AUC-%')->where('reference', 'not like', '%~purged-%')->count());
    }
}
