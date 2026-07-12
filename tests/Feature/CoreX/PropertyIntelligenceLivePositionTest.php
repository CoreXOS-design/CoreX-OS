<?php

declare(strict_types=1);

namespace Tests\Feature\CoreX;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\PropertyIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-214 — the property Intelligence "Market Position" figures must read LIVE
 * truth, not a frozen PropertyPresentationSnapshot. A stale snapshot must be
 * ignored: recommended price / area average / comparable count all recompute.
 */
final class PropertyIntelligenceLivePositionTest extends TestCase
{
    use RefreshDatabase;

    public function test_market_position_is_live_and_ignores_a_stale_snapshot(): void
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $agent  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $subject = $this->makeProperty($agency, $branch, $agent, 'Livetown');

        // LIVE market signal: recent sold comps in the suburb feed both the area
        // average and the recommended price.
        $presentationId = (int) DB::table('presentations')->insertGetId([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'created_by_user_id' => $agent->id, 'title' => 'CMA ' . Str::random(4),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([1_800_000, 2_000_000, 2_200_000] as $price) {
            DB::table('presentation_sold_comps')->insert([
                'presentation_id' => $presentationId, 'agency_id' => $agency->id,
                'suburb' => 'Livetown', 'sold_date' => now()->subMonths(2),
                'sold_price_inc' => $price, 'raw_row_json' => '{}', 'parser_version' => 'test',
                'created_at' => now(),
            ]);
        }

        // STALE frozen snapshot the OLD code would have read verbatim.
        DB::table('property_presentation_snapshots')->insert([
            'property_id' => $subject->id, 'agency_id' => $agency->id,
            'generated_at' => now()->subWeek(),
            'recommended_price_at_time' => 777_777,
            'days_on_market_at_time' => 999,
            'market_data_snapshot' => json_encode([
                'area_average_price' => 111,
                'comparable_sales'   => array_fill(0, 10, ['x' => 1]), // frozen count = 10
            ]),
            'created_at' => now()->subWeek(), 'updated_at' => now()->subWeek(),
        ]);

        $pos = app(PropertyIntelligenceService::class)->getLatestMarketPosition($subject->id);

        $this->assertNotNull($pos);
        $this->assertTrue($pos['is_live'] ?? false, 'position must be computed live');
        // Computed today, not the week-old snapshot date.
        $this->assertSame(now()->toDateString(), $pos['snapshot_date']);
        // Frozen values must NOT survive.
        $this->assertNotEquals(777_777, $pos['recommended_price']);
        $this->assertNotSame(10, $pos['comparable_sales_count']);
        $this->assertNotSame(111, $pos['area_avg_price']);
        // The payload carries the live figures (values themselves are exercised
        // in MarketDataSnapshotService / CmaCoverageService tests).
        $this->assertArrayHasKey('recommended_price', $pos);
        $this->assertArrayHasKey('area_avg_price', $pos);
    }

    private function makeProperty(Agency $agency, Branch $branch, User $agent, string $suburb): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing ' . Str::random(4),
            'suburb' => $suburb, 'property_type' => 'house', 'status' => 'active',
            'price' => 2_000_000, 'published_at' => now()->subDays(20), 'listed_date' => now()->subDays(20),
        ]);
    }
}
