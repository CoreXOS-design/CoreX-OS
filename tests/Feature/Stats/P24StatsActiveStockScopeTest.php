<?php

declare(strict_types=1);

namespace Tests\Feature\Stats;

use App\Models\Agency;
use App\Models\Property;
use App\Models\PropertyPortalMetric;
use App\Models\User;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use App\Services\Syndication\Property24\P24StatsService;
use App\Services\Syndication\Property24\Property24ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * AT-200 scope ruling: the P24 stats sweep polls ACTIVE STOCK only — on-market
 * listings actively syndicated to P24. A sold/withdrawn/archived listing (even
 * with a stale 'active' syndication flag) is NOT polled; a newly-listed on-market
 * one IS. Also proves the fail-fast read timeout is set on the sweep.
 */
final class P24StatsActiveStockScopeTest extends TestCase
{
    use RefreshDatabase;

    private int $agentId;
    private int $agencyId;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);
        $this->agencyId = $agency->id;
        $this->branchId = \App\Models\Branch::forceCreate(['name' => 'Main', 'agency_id' => $agency->id])->id;
        $this->agentId = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $this->branchId, 'role' => 'agent'])->id;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function prop(string $ref, string $status): Property
    {
        return Property::forceCreate([
            'agency_id' => $this->agencyId, 'agent_id' => $this->agentId, 'branch_id' => $this->branchId,
            'p24_ref' => $ref, 'p24_listing_number' => $ref,
            'p24_syndication_status' => 'active', 'status' => $status, 'title' => "P {$ref}",
        ]);
    }

    public function test_sweep_polls_active_stock_only_and_sets_fail_fast_timeout(): void
    {
        $live     = $this->prop('101', 'for_sale');   // active stock → polled
        $newListed = $this->prop('102', 'under_offer'); // on-market → polled
        $sold     = $this->prop('201', 'sold');       // off-market → skipped
        $withdrawn = $this->prop('202', 'withdrawn');  // off-market → skipped
        $archived = $this->prop('203', 'archived');   // off-market → skipped

        $polled = [];
        $api = Mockery::mock(Property24ApiClient::class);
        $api->shouldReceive('setReadTimeout')->atLeast()->once()->andReturnSelf();
        $api->shouldReceive('getListingStatistics')->andReturnUsing(function ($ln) use (&$polled) {
            $polled[] = (int) $ln;
            return ['success' => true, 'data' => [
                ['date' => now()->subDay()->format('Y-m-d'), 'viewCount' => 7, 'totalLeads' => 1],
            ]];
        });

        $svc = new P24StatsService($api);
        $svc->pullForAgency(null, 10, null); // null agency → uses injected mock; uncapped

        sort($polled);
        $this->assertSame([101, 102], $polled, 'Only on-market active-stock listings are polled.');

        $this->assertDatabaseHas('property_portal_metrics', ['property_id' => $live->id, 'portal' => 'p24', 'view_count' => 7]);
        foreach ([$sold, $withdrawn, $archived] as $off) {
            $this->assertDatabaseMissing('property_portal_metrics', ['property_id' => $off->id, 'portal' => 'p24']);
        }
    }
}
