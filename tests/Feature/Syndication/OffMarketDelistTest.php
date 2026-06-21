<?php

namespace Tests\Feature\Syndication;

use App\Jobs\Syndication\DesyndicatePropertyFromPortalsJob;
use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\Branch;
use App\Models\Property;
use App\Models\PropertyWebsiteSyndication;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\PrivateProperty\PrivatePropertySyndicationService;
use App\Services\Syndication\Property24\Property24SyndicationService;
use App\Services\Syndication\Website\WebsiteSyndicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PP-1 / generalised off-market delist. A property going off-market must come
 * off Private Property (always) and the agency website (for true removals —
 * withdrawn/expired/cancelled — but NOT sold/rented, which agencies showcase).
 *
 * Audit: .ai/audits/syndication-bug-sweep-2026-06-20.md (PP-1)
 */
class OffMarketDelistTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;
    private AgencyApiKey $key;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'sync']);

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal', 'website_enabled' => true]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'super_admin']);

        $minted = AgencyApiKey::mintSecret();
        $this->key = AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'name' => 'Main Website',
            'key_prefix' => $minted['prefix'], 'secret_hash' => $minted['hash'],
            'scopes' => [AgencyApiKey::SCOPE_LISTINGS_READ],
        ]);
    }

    public function test_observer_dispatches_remove_everywhere_for_withdrawn(): void
    {
        Queue::fake();
        $p = $this->ppProperty('active');

        $p->update(['status' => 'withdrawn']);

        Queue::assertPushed(DesyndicatePropertyFromPortalsJob::class, fn ($j) =>
            $j->property->id === $p->id && $j->removeFromWebsite === true);
    }

    public function test_observer_keeps_website_for_sold(): void
    {
        Queue::fake();
        $p = $this->ppProperty('active');

        $p->update(['status' => 'sold']);

        Queue::assertPushed(DesyndicatePropertyFromPortalsJob::class, fn ($j) =>
            $j->property->id === $p->id && $j->removeFromWebsite === false);
    }

    public function test_observer_does_not_dispatch_for_non_syndicated_property(): void
    {
        Queue::fake();
        $p = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->user->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'X', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => 'active', 'price' => 1000000,
        ]);

        $p->update(['status' => 'withdrawn']);

        Queue::assertNotPushed(DesyndicatePropertyFromPortalsJob::class);
    }

    public function test_job_with_remove_false_delists_pp_but_keeps_website(): void
    {
        $p = $this->ppProperty('active');
        app(WebsiteSyndicationService::class)->setEnabled($p, $this->key, true);

        $this->mock(PrivatePropertySyndicationService::class, function ($m) {
            $m->shouldReceive('deactivateListing')->once()->andReturn(['success' => true]);
        });
        $this->mock(Property24SyndicationService::class, function ($m) {
            $m->shouldReceive('deactivateListing')->never();
        });

        (new DesyndicatePropertyFromPortalsJob($p, removeFromWebsite: false))->handle();

        // Website pivot is untouched (sold stock stays on the site).
        $row = PropertyWebsiteSyndication::withoutGlobalScope(AgencyScope::class)
            ->where('property_id', $p->id)->where('agency_api_key_id', $this->key->id)->first();
        $this->assertTrue((bool) $row->enabled);
    }

    private function ppProperty(string $status): Property
    {
        $p = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->user->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => $status, 'price' => 1500000,
        ]);
        $p->forceFill([
            'pp_syndication_enabled' => true,
            'pp_syndication_status'  => 'active',
            'pp_ref'                 => 'T123',
        ])->save();

        return $p;
    }
}
