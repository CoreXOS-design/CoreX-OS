<?php

namespace Tests\Feature\PortalMetrics;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\PropertyPortalMetric;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\PropertyIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Read path for the Property Intelligence Hub "P24 Views (30d)" card:
 * property_portal_metrics rows → summed views via
 * PropertyIntelligenceService::getPortalPerformance. See .ai/specs/portal-metrics.md.
 */
class PortalPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'super_admin',
        ]);
        $this->actingAs($this->user);
    }

    private function makeProperty(): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->user->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing ' . Str::random(4), 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => 'active', 'price' => 1500000, 'published_at' => now(),
        ]);
    }

    private function metric(Property $property, string $date, int $views, int $alerts = 0, int $leads = 0): void
    {
        PropertyPortalMetric::withoutGlobalScopes()->create([
            'agency_id' => $property->agency_id,
            'property_id' => $property->id,
            'portal' => PropertyPortalMetric::PORTAL_P24,
            'portal_listing_number' => '12345678',
            'metric_date' => $date,
            'view_count' => $views,
            'alert_count' => $alerts,
            'total_leads' => $leads,
        ]);
    }

    public function test_sums_p24_views_over_window_and_marks_pp_unsupported(): void
    {
        $property = $this->makeProperty();
        $this->metric($property, now()->subDays(1)->format('Y-m-d'), 100, 3, 2);
        $this->metric($property, now()->subDays(5)->format('Y-m-d'), 50, 1, 1);
        // Outside the 30-day window — must NOT be counted.
        $this->metric($property, now()->subDays(40)->format('Y-m-d'), 999, 9, 9);

        $perf = app(PropertyIntelligenceService::class)->getPortalPerformance($property->id, 30);

        $this->assertSame(150, $perf['views']);
        $this->assertSame(150, $perf['p24_views']);
        $this->assertSame(4, $perf['favourites']);
        $this->assertSame(3, $perf['enquiries']);
        $this->assertFalse($perf['pp_supported']);
        $this->assertTrue($perf['has_data']);
    }

    public function test_returns_zeros_when_no_metrics_exist(): void
    {
        $property = $this->makeProperty();

        $perf = app(PropertyIntelligenceService::class)->getPortalPerformance($property->id, 30);

        $this->assertSame(0, $perf['views']);
        $this->assertFalse($perf['has_data']);
        $this->assertFalse($perf['pp_supported']);
    }
}
