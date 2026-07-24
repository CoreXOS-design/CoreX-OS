<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation;

use App\Models\Presentation;
use App\Models\PresentationSoldComp;
use App\Models\Property;
use App\Models\User;
use App\Services\Presentations\AnalysisDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CMA headline — size-normalised median-floored blend (STEP 2a) + sanity
 * guardrail (STEP 2b). One feeds the other: the blend lifts the headline toward
 * the size-normalised value only when trustworthy; the guardrail flags whatever
 * the blend could NOT safely fix. Proven against the live set 2026-07-24.
 *
 * Branches pinned here:
 *   - sane            → subject sized like comps → no lift, no flag.
 *   - larger subject  → same-basis, in-band ratio, clear uplift → headline LIFTS
 *                       toward size-normalised (Harrison-class), then reads clean.
 *   - too large       → ratio above the trust band → headline stays median
 *                       (no explosion) and stays flagged.
 *   - basis mismatch  → comps/subject not size-comparable → median kept, high flag.
 *   - no size data    → nothing to cross-check → silent, headline = median.
 */
final class ValuationGuardrailTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $reflection = new \ReflectionClass(\App\Services\PermissionService::class);
        $seeded = $reflection->getProperty('seeded');
        $seeded->setAccessible(true);
        $seeded->setValue(null, null);
        \App\Models\Role::clearCache();
        parent::tearDown();
    }

    /** Subject sized like the comps → median and size-normalised agree → no lift, no flag. */
    public function test_sane_valuation_is_not_lifted_and_not_flagged(): void
    {
        // 5 comps size 100, median price 1_000_000, median R/m² 10_000.
        // Subject extent 100 → size-normalised = 1_000_000 == median.
        [$presentation] = $this->seedWithComps(100, [[400_000, 100], [800_000, 100], [1_000_000, 100], [1_200_000, 100], [1_400_000, 100]]);

        $cma = $this->cma($presentation);

        $this->assertSame(1_000_000, $cma['cma_middle']);
        $this->assertFalse($cma['headline_lifted']);
        $this->assertSame('median', $cma['compute_method']);
        $this->assertFalse($cma['valuation_guardrail']['flagged']);
    }

    /** Larger, same-basis subject (Harrison-class) → headline LIFTS toward size-normalised, then clean. */
    public function test_larger_subject_lifts_headline_and_reads_clean(): void
    {
        // Comps size 100, median R/m² 10_000; subject extent 160 → 1_600_000
        // vs median 1_000_000 = +60% uplift, basis ratio 1.6 (in trust band).
        [$presentation] = $this->seedWithComps(160, [[400_000, 100], [800_000, 100], [1_000_000, 100], [1_200_000, 100], [1_400_000, 100]]);

        $cma = $this->cma($presentation);

        $this->assertTrue($cma['headline_lifted']);
        $this->assertSame('size_adjusted', $cma['compute_method']);
        $this->assertSame(1_000_000, $cma['headline_median_raw']);   // floor
        $this->assertSame(1_600_000, $cma['cma_middle_baseline']);   // lifted to the size-normalised value
        $this->assertSame(1_600_000, $cma['cma_middle']);            // no condition → shown == baseline
        // Once lifted to meet the evidence, the guardrail no longer flags it.
        $this->assertFalse($cma['valuation_guardrail']['flagged']);
    }

    /** Subject far larger than comps (ratio above the trust band) → median kept, flagged (no explosion). */
    public function test_subject_too_large_keeps_median_and_flags(): void
    {
        // Subject extent 180 → ratio 1.8 (> 1.6 trust ceiling). Size-normalised
        // would be 1_800_000 (+80%) but flat R/m² over-values a much larger stand,
        // so the headline stays the median and is flagged instead.
        [$presentation] = $this->seedWithComps(180, [[400_000, 100], [800_000, 100], [1_000_000, 100], [1_200_000, 100], [1_400_000, 100]]);

        $cma = $this->cma($presentation);

        $this->assertFalse($cma['headline_lifted']);
        $this->assertSame(1_000_000, $cma['cma_middle']);                 // NOT exploded
        $this->assertTrue($cma['valuation_guardrail']['flagged']);
        $this->assertFalse($cma['valuation_guardrail']['basis_mismatch']);
        $this->assertEqualsWithDelta(80.0, $cma['valuation_guardrail']['divergence_pct'], 0.1);
    }

    /** Comps (small units) vs subject (big erf) not size-comparable → median kept, high-severity flag. */
    public function test_basis_mismatch_keeps_median_and_flags_high(): void
    {
        // Comps size 60, median 600_000; subject extent 3_000 → ratio 50× → mismatch.
        [$presentation] = $this->seedWithComps(3_000, [[500_000, 60], [550_000, 60], [600_000, 60], [650_000, 60], [700_000, 60]]);

        $cma = $this->cma($presentation);
        $vg  = $cma['valuation_guardrail'];

        $this->assertFalse($cma['headline_lifted']);
        $this->assertSame(600_000, $cma['cma_middle']);      // median kept, no R30m explosion
        $this->assertTrue($vg['flagged']);
        $this->assertSame('high', $vg['severity']);
        $this->assertTrue($vg['basis_mismatch']);
        $this->assertContains('comp_size_basis_mismatch', $vg['reasons']);
    }

    /** No comp carries a size → nothing to cross-check → silent, headline = median. */
    public function test_no_size_data_is_silent(): void
    {
        [$presentation] = $this->seedWithComps(100, [[800_000, null], [1_000_000, null], [1_200_000, null]]);

        $cma = $this->cma($presentation);

        $this->assertFalse($cma['headline_lifted']);
        $this->assertSame(1_000_000, $cma['cma_middle']);
        $this->assertFalse($cma['valuation_guardrail']['flagged']);
        $this->assertNull($cma['valuation_guardrail']['rm2_value']);
    }

    // ── helpers ──────────────────────────────────────────────────────

    private function cma(Presentation $presentation): array
    {
        return (new AnalysisDataService())
            ->compile($presentation->fresh(['property', 'soldComps']))['cma_valuation'];
    }

    /**
     * @param  array<int,array{0:int,1:int|null}>  $comps  [price, size_m2]
     * @return array{0:Presentation, 1:int}
     */
    private function seedWithComps(int $extentM2, array $comps): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Guardrail ' . Str::random(6),
            'slug' => 'grd-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);
        $property = Property::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $user->id,
            'title' => 'Test Property', 'property_type' => 'House', 'category' => 'Residential',
            'suburb' => 'Testville', 'price' => 1_900_000, 'address' => '1 Test Lane',
            'status' => 'active', 'listing_type' => 'sale',
            'erf_size_m2' => $extentM2, 'latitude' => -30.84, 'longitude' => 30.39,
        ]);
        $presentation = Presentation::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'property_id' => $property->id,
            'created_by_user_id' => $user->id, 'title' => 'GuardrailTest',
            'property_address' => '1 Test Lane', 'suburb' => 'Testville', 'property_type' => 'other',
            'erf_size_m2' => $extentM2, 'asking_price_inc' => 1_900_000,
            'status' => 'draft', 'currency' => 'ZAR',
        ]);
        foreach ($comps as [$price, $size]) {
            PresentationSoldComp::create([
                'agency_id' => $agencyId, 'presentation_id' => $presentation->id, 'property_type' => 'House',
                'sold_date' => now()->subMonths(3)->toDateString(), 'sold_price_inc' => $price,
                'suburb' => 'Testville', 'size_m2' => $size,
                'raw_row_json' => json_encode(['address' => 'Comp ' . Str::random(4)]),
                'parser_version' => 'test',
            ]);
        }
        return [$presentation, $agencyId];
    }
}
