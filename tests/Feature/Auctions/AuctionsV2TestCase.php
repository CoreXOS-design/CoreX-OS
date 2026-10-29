<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Auction;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Shared fixture for the Auctions v2 tests (.ai/specs/auctions.md): one agency with the feature on and an admin acting. */
abstract class AuctionsV2TestCase extends TestCase
{
    use RefreshDatabase;

    protected Agency $agency;
    protected Branch $branch;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionService::clearCache();

        $this->agency = Agency::create(['name' => 'Cape Auctions', 'slug' => 'cape-'.uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        AgencyFeature::updateOrCreate(['agency_id' => $this->agency->id, 'feature_key' => 'auctions'], ['enabled' => true]);
        app(\App\Services\Features\AgencyFeatureService::class)->forget($this->agency->id);

        $this->actingAs($this->admin);
    }

    protected function property(string $title = 'Sea View House', array $extra = []): Property
    {
        return Property::create(array_merge([
            'title' => $title, 'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id,
            'branch_id' => $this->branch->id, 'status' => 'active', 'listing_type' => 'sale', 'price' => 1500000,
        ], $extra));
    }

    protected function auction(array $extra = []): Auction
    {
        $a = Auction::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'reference' => 'AUC-T-'.uniqid(), 'title' => 'October Auction', 'format' => 'timed', 'status' => 'draft',
            'starts_at' => now()->addDays(10), 'ends_at' => now()->addDays(12), 'created_by' => $this->admin->id,
        ], $extra));
        $a->syncIncrements([[0, 5000], [1000000, 25000]]);

        return $a;
    }
}
