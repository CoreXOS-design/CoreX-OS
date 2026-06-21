<?php

declare(strict_types=1);

namespace Tests\Feature\Presentations;

use App\Models\Property;
use App\Models\User;
use App\Services\Presentations\CompetitorStockMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-77 — CMA comparable-stock COMPARABILITY scoring replaces the
 * ~97%-everywhere membership score. Proves scores grade by attribute closeness
 * (not band membership), the SS-unit-size vs freehold-erf-size weighting flip,
 * graceful missing-attribute handling, and a real spread.
 */
final class CompetitorComparabilityScoringTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): CompetitorStockMatchService
    {
        return app(CompetitorStockMatchService::class);
    }

    /** @return array{0:int,1:User} */
    private function fixture(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin']);
        return [$agencyId, $agent];
    }

    private function subject(int $agencyId, int $agentId, array $extra): Property
    {
        return Property::create(array_merge([
            'external_id' => (string) Str::uuid(), 'title' => 'Subject ' . Str::random(5),
            'agent_id' => $agentId, 'branch_id' => $agencyId, 'agency_id' => $agencyId,
            'listing_type' => 'sale', 'status' => 'active', 'published_at' => now(),
            'suburb' => 'Uvongo', 'price' => 2_000_000,
        ], $extra));
    }

    /** comp stdClass in the adaptCandidateRow shape scoreComparability reads. */
    private function comp(array $a): object
    {
        return (object) array_merge([
            'price' => 2_000_000, 'beds' => 3, 'bathrooms' => 2, 'garages' => 2,
            'property_type' => 'House', 'property_size_m2' => null, 'erf_size_m2' => 600.0,
        ], $a);
    }

    private function score(Property $subject, object $comp): int
    {
        $criteria = $this->svc()->buildCriteria($subject);
        $this->assertNotNull($criteria, 'subject must be processable');
        return (int) $this->svc()->scoreComparability($comp, $criteria)['score'];
    }

    // ── gradation ─────────────────────────────────────────────────────────

    public function test_exact_comp_scores_top_and_loose_comp_scores_lower(): void
    {
        [$aid, $agent] = $this->fixture();
        $subject = $this->subject($aid, $agent->id, ['property_type' => 'House', 'beds' => 3, 'baths' => 2, 'garages' => 2, 'erf_size_m2' => 600]);

        $exact = $this->score($subject, $this->comp(['price' => 2_000_000, 'beds' => 3, 'bathrooms' => 2, 'garages' => 2, 'property_type' => 'House', 'erf_size_m2' => 600]));
        $loose = $this->score($subject, $this->comp(['price' => 2_300_000, 'beds' => 2, 'bathrooms' => 1, 'garages' => 2, 'property_type' => 'House', 'erf_size_m2' => 900]));

        $this->assertGreaterThanOrEqual(95, $exact, 'identical comp ~top');
        $this->assertLessThan(75, $loose, 'loose comp clearly lower');
        $this->assertGreaterThan(20, $exact - $loose, 'scores spread, not clustered ~97');
    }

    // ── SS unit-size heavy vs freehold erf-size light ─────────────────────

    public function test_sectional_unit_size_is_heavy_freehold_erf_is_light(): void
    {
        [$aid, $agent] = $this->fixture();

        // SECTIONAL subject (Apartment). Size axis = unit floor (size_m2), weight 30.
        $ss = $this->subject($aid, $agent->id, ['property_type' => 'Apartment', 'beds' => 2, 'baths' => 1, 'garages' => 1, 'size_m2' => 60]);
        $ssExact = $this->score($ss, $this->comp(['price' => 2_000_000, 'beds' => 2, 'bathrooms' => 1, 'garages' => 1, 'property_type' => 'Apartment', 'property_size_m2' => 60]));
        $ssBigUnit = $this->score($ss, $this->comp(['price' => 2_000_000, 'beds' => 2, 'bathrooms' => 1, 'garages' => 1, 'property_type' => 'Apartment', 'property_size_m2' => 120])); // double unit size
        $ssDrop = $ssExact - $ssBigUnit;

        // FREEHOLD subject (House). Size axis = erf (erf_size_m2), weight 10.
        $fh = $this->subject($aid, $agent->id, ['property_type' => 'House', 'beds' => 3, 'baths' => 2, 'garages' => 2, 'erf_size_m2' => 600]);
        $fhExact = $this->score($fh, $this->comp(['price' => 2_000_000, 'beds' => 3, 'bathrooms' => 2, 'garages' => 2, 'property_type' => 'House', 'erf_size_m2' => 600]));
        $fhBigErf = $this->score($fh, $this->comp(['price' => 2_000_000, 'beds' => 3, 'bathrooms' => 2, 'garages' => 2, 'property_type' => 'House', 'erf_size_m2' => 1200])); // double erf
        $fhDrop = $fhExact - $fhBigErf;

        $this->assertGreaterThan(15, $ssDrop, 'SS: doubling unit size drops the score a lot (heavy axis)');
        $this->assertLessThanOrEqual(12, $fhDrop, 'FH: doubling erf barely moves the score (light axis)');
        $this->assertGreaterThan($fhDrop, $ssDrop, 'SS unit-size weight > FH erf-size weight');
    }

    public function test_freehold_beds_matter_more_than_erf(): void
    {
        [$aid, $agent] = $this->fixture();
        $fh = $this->subject($aid, $agent->id, ['property_type' => 'House', 'beds' => 3, 'baths' => 2, 'garages' => 2, 'erf_size_m2' => 600]);
        $exact   = $this->score($fh, $this->comp(['property_type' => 'House', 'beds' => 3, 'bathrooms' => 2, 'erf_size_m2' => 600]));
        $bedsOff = $this->score($fh, $this->comp(['property_type' => 'House', 'beds' => 1, 'bathrooms' => 2, 'erf_size_m2' => 600])); // beds far off
        $erfOff  = $this->score($fh, $this->comp(['property_type' => 'House', 'beds' => 3, 'bathrooms' => 2, 'erf_size_m2' => 1200])); // erf far off
        $this->assertGreaterThan($exact - $erfOff, $exact - $bedsOff, 'freehold: beds difference outweighs erf difference (offering proxy)');
    }

    // ── graceful missing attribute ────────────────────────────────────────

    public function test_missing_size_is_graded_not_zero_not_full(): void
    {
        [$aid, $agent] = $this->fixture();
        $fh = $this->subject($aid, $agent->id, ['property_type' => 'House', 'beds' => 3, 'baths' => 2, 'garages' => 2, 'erf_size_m2' => 600]);

        // Exact on everything else, erf unknown → size axis drops out → still top.
        $noSizeExact = $this->score($fh, $this->comp(['price' => 2_000_000, 'beds' => 3, 'bathrooms' => 2, 'garages' => 2, 'property_type' => 'House', 'erf_size_m2' => null]));
        $this->assertGreaterThanOrEqual(95, $noSizeExact, 'missing size on an otherwise-identical comp is not penalised');

        // Missing size AND a price gap → graded between, never 0/100.
        $noSizeLoose = $this->score($fh, $this->comp(['price' => 2_300_000, 'beds' => 3, 'bathrooms' => 2, 'garages' => 2, 'property_type' => 'House', 'erf_size_m2' => null]));
        $this->assertGreaterThan(50, $noSizeLoose);
        $this->assertLessThan(95, $noSizeLoose);
    }

    // ── the headline: scores SPREAD, not all ~97 ──────────────────────────

    public function test_varied_comps_spread_not_all_perfect(): void
    {
        [$aid, $agent] = $this->fixture();
        $fh = $this->subject($aid, $agent->id, ['property_type' => 'House', 'beds' => 3, 'baths' => 2, 'garages' => 2, 'erf_size_m2' => 600]);

        $scores = collect([
            $this->comp(['price' => 2_000_000, 'beds' => 3, 'bathrooms' => 2, 'garages' => 2, 'property_type' => 'House', 'erf_size_m2' => 600]),   // exact
            $this->comp(['price' => 2_100_000, 'beds' => 3, 'bathrooms' => 2, 'garages' => 1, 'property_type' => 'House', 'erf_size_m2' => 650]),   // mild
            $this->comp(['price' => 2_300_000, 'beds' => 4, 'bathrooms' => 3, 'garages' => 2, 'property_type' => 'Townhouse', 'erf_size_m2' => 400]), // moderate
            $this->comp(['price' => 2_380_000, 'beds' => 2, 'bathrooms' => 1, 'garages' => 0, 'property_type' => 'Townhouse', 'erf_size_m2' => 1100]),// loose
        ])->map(fn ($c) => $this->score($fh, $c));

        $this->assertGreaterThan(25, $scores->max() - $scores->min(), 'scores must spread, not cluster ~97');
        $this->assertGreaterThan(1, $scores->unique()->count(), 'not all identical');
        $this->assertLessThan(90, $scores->min(), 'the loosest comp is clearly not "perfect"');
    }
}
