<?php

declare(strict_types=1);

namespace Tests\Feature\CoreX;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\MarketDataSnapshotService;
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

    /**
     * Recommended price must be the profile-gated market anchor, not a raw
     * suburb median. A premium freehold house must not be valued off a pool of
     * cheap sectional flats + a commercial shop (the R804k / "R1.1M trap" bug).
     */
    public function test_recommended_price_gates_off_profile_comps(): void
    {
        $agency = Agency::create(['name' => 'Gated', 'slug' => 'gated-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $agent  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        // Subject: a premium freehold house asking R2.0M.
        $subject = $this->makeProperty($agency, $branch, $agent, 'Gatetown', ['property_type' => 'house', 'price' => 2_000_000]);

        // Profile-matching comps (freehold houses, in band around R2M).
        foreach ([1_900_000, 2_000_000, 2_100_000] as $p) {
            $this->soldRecord($agency, 'Gatetown', $p, 'House');
        }
        // Off-profile noise the OLD ungated median swallowed: cheap sectional
        // flats + a commercial shop. Raw median of ALL 7 rows would be ~650k;
        // the gated anchor must instead reflect the house comps (~R2.0M).
        foreach ([600_000, 650_000, 700_000] as $p) {
            $this->soldRecord($agency, 'Gatetown', $p, 'Apartment');
        }
        $this->soldRecord($agency, 'Gatetown', 300_000, 'Business');

        $recommended = app(MarketDataSnapshotService::class)->calculateRecommendedPrice($subject);

        $this->assertNotNull($recommended);
        $this->assertGreaterThan(1_500_000, $recommended, 'gated anchor must reflect the house comps, not the flats');
        $this->assertEqualsWithDelta(2_000_000, $recommended, 200_000);
    }

    /**
     * A rental must never surface as a comparable LISTING for a sale (the
     * "Restaurant to let" leak).
     */
    public function test_comparable_listings_exclude_rentals(): void
    {
        $agency = Agency::create(['name' => 'Rentless', 'slug' => 'rentless-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $agent  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        $subject = $this->makeProperty($agency, $branch, $agent, 'Rentville', ['property_type' => 'house', 'price' => 1_900_000, 'listing_type' => 'sale']);
        $sale    = $this->makeProperty($agency, $branch, $agent, 'Rentville', ['property_type' => 'house', 'price' => 1_800_000, 'listing_type' => 'sale']);
        $rental  = $this->makeProperty($agency, $branch, $agent, 'Rentville', ['property_type' => 'house', 'price' => 0, 'listing_type' => 'rental']);

        $ids = app(PropertyIntelligenceService::class)->getComparableListings($subject->id)->pluck('id')->all();

        $this->assertContains($sale->id, $ids, 'a same-type sale must be a comparable');
        $this->assertNotContains($rental->id, $ids, 'a rental must NOT be a comparable for a sale');
    }

    private function soldRecord(Agency $agency, string $suburb, int $price, string $type): void
    {
        DB::table('property_sold_records')->insert([
            'agency_id' => $agency->id, 'suburb' => $suburb,
            'sold_price' => $price, 'sold_date' => now()->subMonths(2),
            'property_type' => $type, 'source' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeProperty(Agency $agency, Branch $branch, User $agent, string $suburb, array $overrides = []): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing ' . Str::random(4),
            'suburb' => $suburb, 'property_type' => 'house', 'status' => 'active',
            'price' => 2_000_000, 'published_at' => now()->subDays(20), 'listed_date' => now()->subDays(20),
        ], $overrides));
    }
}
