<?php

declare(strict_types=1);

namespace Tests\Feature\Stats;

use App\Models\Agency;
use App\Models\Property;
use App\Models\PropertyPortalMetric;
use App\Services\PrivateProperty\PpStatsService;
use App\Services\PrivateProperty\PrivatePropertySoapClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * PP nightly engagement snapshot (AT-201). Proves: correct field mapping into
 * property_portal_metrics(portal='pp'), ACTIVE-STOCK scoping (off-market listings
 * are not snapshotted), idempotency per (listing,date), toggle dormancy, and
 * failure containment.
 */
final class PpStatsServiceTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create([
            'name' => 'Coastal', 'slug' => 'coastal',
            'pp_stats_pull_enabled' => true,
            'pp_username' => 'u', 'pp_password' => 'p',
            'pp_branch_guid' => '6f0a1b2c-3d4e-5f6a-7b8c-9d0e1f2a3b4c',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function prop(string $ref, string $status): Property
    {
        return Property::forceCreate([
            'agency_id' => $this->agency->id, 'pp_ref' => $ref,
            'pp_syndication_status' => 'active', 'status' => $status, 'title' => "P {$ref}",
        ]);
    }

    private function bindClient(array $rows): void
    {
        $client = Mockery::mock(PrivatePropertySoapClient::class);
        $client->shouldReceive('forAgency')->andReturnSelf();
        $client->shouldReceive('listingPerformanceStats')->andReturnUsing(function ($refs, $date) use ($rows) {
            // Echo only rows whose ref was actually requested (proves scope).
            $out = array_values(array_filter($rows, fn ($r) => in_array($r['PropertyRef'], $refs, true)));
            return ['ListingPerformanceStatsResult' => ['ListingPerformanceStatsOnDate' => $out]];
        });
        $this->app->instance(PrivatePropertySoapClient::class, $client);
    }

    public function test_maps_pp_stats_and_scopes_to_active_stock(): void
    {
        $onMarket = $this->prop('T1000', 'for_sale');   // active stock → pulled
        $sold     = $this->prop('T2000', 'sold');       // off-market → NOT pulled

        $this->bindClient([
            ['PropertyRef' => 'T1000', 'Date' => now()->subDay()->format('Y-m-d'), 'Views' => 42, 'Alerts' => 3, 'TelLeads' => 2, 'Messages' => 5],
            ['PropertyRef' => 'T2000', 'Date' => now()->subDay()->format('Y-m-d'), 'Views' => 99, 'Alerts' => 9, 'TelLeads' => 9, 'Messages' => 9],
        ]);

        $res = app(PpStatsService::class)->pullForAgency($this->agency->fresh(), 1);

        $this->assertSame(1, $res['listings'], 'Only the on-market listing is in scope.');
        $this->assertDatabaseHas('property_portal_metrics', [
            'property_id' => $onMarket->id, 'portal' => 'pp',
            'view_count' => 42, 'alert_count' => 3, 'tel_leads' => 2, 'total_leads' => 5,
        ]);
        // The sold listing is never snapshotted.
        $this->assertDatabaseMissing('property_portal_metrics', [
            'property_id' => $sold->id, 'portal' => 'pp',
        ]);
    }

    public function test_idempotent_per_listing_and_date(): void
    {
        $p = $this->prop('T1000', 'for_sale');
        $this->bindClient([
            ['PropertyRef' => 'T1000', 'Date' => now()->subDay()->format('Y-m-d'), 'Views' => 10, 'Alerts' => 0, 'TelLeads' => 0, 'Messages' => 1],
        ]);
        $svc = app(PpStatsService::class);
        $svc->pullForAgency($this->agency->fresh(), 1);
        $svc->pullForAgency($this->agency->fresh(), 1);

        $this->assertSame(1, PropertyPortalMetric::where('property_id', $p->id)->where('portal', 'pp')->count());
    }

    public function test_toggle_off_is_dormant(): void
    {
        $this->agency->update(['pp_stats_pull_enabled' => false]);
        $this->prop('T1000', 'for_sale');

        $client = Mockery::mock(PrivatePropertySoapClient::class);
        $client->shouldReceive('forAgency')->never();
        $this->app->instance(PrivatePropertySoapClient::class, $client);

        $this->assertSame(['dormant' => true], app(PpStatsService::class)->pullForAllAgencies());
        $this->assertSame(0, PropertyPortalMetric::where('portal', 'pp')->count());
    }

    public function test_soap_fault_is_contained(): void
    {
        $this->prop('T1000', 'for_sale');
        $client = Mockery::mock(PrivatePropertySoapClient::class);
        $client->shouldReceive('forAgency')->andReturnSelf();
        $client->shouldReceive('listingPerformanceStats')->andReturn(['error' => true, 'message' => 'timeout']);
        $this->app->instance(PrivatePropertySoapClient::class, $client);

        $res = app(PpStatsService::class)->pullForAgency($this->agency->fresh(), 1);
        $this->assertGreaterThanOrEqual(1, $res['errors']);
        $this->assertSame(0, PropertyPortalMetric::where('portal', 'pp')->count());
    }
}
