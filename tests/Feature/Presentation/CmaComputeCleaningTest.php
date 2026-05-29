<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation;

use App\Models\Agency;
use App\Models\Presentation;
use App\Models\PresentationSoldComp;
use App\Models\Property;
use App\Models\User;
use App\Services\Presentations\CmaComputeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Build 8b — pool cleaning (recency + IQR) for CmaComputeService.
 *
 * Verifies:
 *   • Recency cut excludes old comps (sold_date < now − N months).
 *   • IQR lower-fence cut removes sub-market R/m² outliers
 *     (pres-1-style noise) while preserving legitimate low-end comps
 *     (pres-5-style R503/m²).
 *   • Min-n floor (5) triggers fallback ladder:
 *       outlier cut too thin → recency-only
 *       recency cut too thin → full pre-clean pool
 *   • pool_stats exposes exclusion counts + fallback flag.
 *   • Each method's preclean sub-key carries pre-clean numbers for
 *     before/after comparison.
 *   • Both settings read from the agency (defaults when null).
 *   • bcmath determinism on known fixtures.
 */
final class CmaComputeCleaningTest extends TestCase
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

    // ── Recency cut ──────────────────────────────────────────────────

    public function test_recency_cut_removes_comps_older_than_window(): void
    {
        [$presentation, $agencyId] = $this->seedAgencyPropertyPresentation();
        // 36-month default. Seed 6 comps: 4 recent (within 12 months),
        // 2 ancient (> 5 years old).
        $this->seedComp($presentation->id, $agencyId, 1_000_000, 100, now()->subMonths(2));
        $this->seedComp($presentation->id, $agencyId, 1_100_000, 100, now()->subMonths(6));
        $this->seedComp($presentation->id, $agencyId, 1_200_000, 100, now()->subMonths(10));
        $this->seedComp($presentation->id, $agencyId, 1_300_000, 100, now()->subMonths(20));
        $this->seedComp($presentation->id, $agencyId, 300_000, 100, now()->subYears(8));
        $this->seedComp($presentation->id, $agencyId, 350_000, 100, now()->subYears(10));

        $svc = new CmaComputeService();
        $out = $svc->compute(
            $presentation->fresh(['property', 'soldComps', 'agency']),
            $presentation->fresh('soldComps')->soldComps,
            isSectional: false,
            conditionContext: [],
        );

        $ps = $out['pool_stats'];
        $this->assertSame(6, $ps['n_total']);
        $this->assertSame(4, $ps['n_after_recency_cut']);
        $this->assertSame(2, $ps['excluded_by_recency']);
        // No IQR cut needed (median 1.15M on 4 comps, all clustered).
        // But 4 is below MIN_VIABLE_N=5 → fallback to recency-only,
        // then THAT is also < 5 → final fallback to pre-clean (6 comps).
        $this->assertSame('recency_too_thin', $ps['cleaning_fallback']);
        // Methods compute off the full pool (the fallback), so the
        // preclean value matches the main value.
        $this->assertSame($out['method_median']['raw'], $out['method_median']['preclean']['raw']);
    }

    public function test_recency_window_setting_reads_from_agency(): void
    {
        [$presentation, $agencyId] = $this->seedAgencyPropertyPresentation();
        // Tighten the window to 6 months.
        Agency::where('id', $agencyId)->update(['cma_compute_recency_months' => 6]);

        // 6 comps: 5 within 6 months, 1 at 12 months.
        $this->seedComp($presentation->id, $agencyId, 1_000_000, 100, now()->subMonths(1));
        $this->seedComp($presentation->id, $agencyId, 1_050_000, 100, now()->subMonths(2));
        $this->seedComp($presentation->id, $agencyId, 1_100_000, 100, now()->subMonths(3));
        $this->seedComp($presentation->id, $agencyId, 1_150_000, 100, now()->subMonths(4));
        $this->seedComp($presentation->id, $agencyId, 1_200_000, 100, now()->subMonths(5));
        $this->seedComp($presentation->id, $agencyId, 600_000, 100, now()->subMonths(12)); // out

        $svc = new CmaComputeService();
        $out = $svc->compute(
            $presentation->fresh(['property', 'soldComps', 'agency']),
            $presentation->fresh('soldComps')->soldComps,
            isSectional: false,
            conditionContext: [],
        );
        $this->assertSame(6, $out['pool_stats']['recency_months_used']);
        $this->assertSame(5, $out['pool_stats']['n_after_recency_cut']);
        $this->assertSame(1, $out['pool_stats']['excluded_by_recency']);
    }

    // ── IQR outlier cut ──────────────────────────────────────────────

    public function test_iqr_lower_fence_removes_pres_1_style_noise(): void
    {
        [$presentation, $agencyId] = $this->seedAgencyPropertyPresentation();
        // Realistic pool: 12 legitimate + 2 noise. Fence (median-anchored
        // 1.5×IQR) drops the noise.
        //   2× noise:  R 60k/1500m² (40/m²) and R 80k/1700m² (47/m²)
        //   12× legit: R/m² clustered R 600-1000/m² (50% spread, narrow IQR)
        // Median of full pool ≈ R 700/m². IQR ≈ 200. Fence = 700-300=400.
        $this->seedComp($presentation->id, $agencyId, 60_000, 1500, now()->subMonths(6));
        $this->seedComp($presentation->id, $agencyId, 80_000, 1700, now()->subMonths(10));
        $legit = [
            [600_000, 1000], // 600/m²
            [620_000, 1000], // 620
            [650_000, 1000], // 650
            [680_000, 1000], // 680
            [700_000, 1000], // 700
            [720_000, 1000], // 720
            [750_000, 1000], // 750
            [780_000, 1000], // 780
            [800_000, 1000], // 800
            [850_000, 1000], // 850
            [900_000, 1000], // 900
            [1_000_000, 1000], // 1000
        ];
        foreach ($legit as $i => [$p, $s]) {
            $this->seedComp($presentation->id, $agencyId, $p, $s, now()->subMonths(2 + $i));
        }

        $svc = new CmaComputeService();
        $out = $svc->compute(
            $presentation->fresh(['property', 'soldComps', 'agency']),
            $presentation->fresh('soldComps')->soldComps,
            isSectional: false,
            conditionContext: [],
        );
        $ps = $out['pool_stats'];
        $this->assertSame(14, $ps['n_total']);
        $this->assertSame(14, $ps['n_after_recency_cut']);
        $this->assertSame(0, $ps['excluded_by_recency']);
        // IQR should drop the 2 noise rows (R/m² 40, 47 — far below fence ~400).
        $this->assertSame(12, $ps['n_after_outlier_cut']);
        $this->assertSame(2, $ps['excluded_by_outlier']);
        $this->assertNull($ps['cleaning_fallback']);

        // Preclean median = sorted[7] of [60k, 80k, 600k…1M] (14 items, idx 7) = 720k.
        // Cleaned median = sorted[6] of legit (12 items, idx 6) = 750k.
        $this->assertSame(720_000, $out['method_median']['preclean']['raw']);
        $this->assertSame(750_000, $out['method_median']['raw']);
    }

    public function test_iqr_keeps_pres_5_style_legitimate_low_comps(): void
    {
        // Pres-5 had R 503/m² as its lowest legitimate comp.
        // IQR with multiplier 1.5 on a pool of R 500-820/m² should
        // NOT exclude it.
        [$presentation, $agencyId] = $this->seedAgencyPropertyPresentation();
        // 6 comps with R/m² ranging cleanly across legitimate band.
        $this->seedComp($presentation->id, $agencyId, 580_000, 1154, now()->subMonths(20)); // 503
        $this->seedComp($presentation->id, $agencyId, 730_000, 1386, now()->subMonths(3));  // 527
        $this->seedComp($presentation->id, $agencyId, 870_000, 1113, now()->subMonths(15)); // 782
        $this->seedComp($presentation->id, $agencyId, 925_000, 1129, now()->subMonths(24)); // 819
        $this->seedComp($presentation->id, $agencyId, 1_000_000, 1200, now()->subMonths(6));// 833
        $this->seedComp($presentation->id, $agencyId, 1_100_000, 1300, now()->subMonths(8));// 846

        $svc = new CmaComputeService();
        $out = $svc->compute(
            $presentation->fresh(['property', 'soldComps', 'agency']),
            $presentation->fresh('soldComps')->soldComps,
            isSectional: false,
            conditionContext: [],
        );
        $ps = $out['pool_stats'];
        $this->assertSame(6, $ps['n_total']);
        $this->assertSame(6, $ps['n_after_recency_cut']);
        // No outliers — the R 503/m² is the floor but inside the fence.
        $this->assertSame(6, $ps['n_after_outlier_cut']);
        $this->assertSame(0, $ps['excluded_by_outlier']);
    }

    public function test_iqr_multiplier_setting_reads_from_agency(): void
    {
        [$presentation, $agencyId] = $this->seedAgencyPropertyPresentation();
        // Tighten the multiplier so even a modest outlier is excluded.
        Agency::where('id', $agencyId)->update(['cma_compute_iqr_multiplier' => 0.5]);

        // 7 clean comps + 1 modestly low.
        $this->seedComp($presentation->id, $agencyId, 700_000, 1000, now()->subMonths(2));   // 700/m²
        $this->seedComp($presentation->id, $agencyId, 800_000, 1000, now()->subMonths(4));   // 800
        $this->seedComp($presentation->id, $agencyId, 900_000, 1000, now()->subMonths(6));   // 900
        $this->seedComp($presentation->id, $agencyId, 1_000_000, 1000, now()->subMonths(8)); // 1000
        $this->seedComp($presentation->id, $agencyId, 1_100_000, 1000, now()->subMonths(10));// 1100
        $this->seedComp($presentation->id, $agencyId, 1_200_000, 1000, now()->subMonths(12));// 1200
        $this->seedComp($presentation->id, $agencyId, 1_300_000, 1000, now()->subMonths(14));// 1300
        $this->seedComp($presentation->id, $agencyId, 400_000, 1000, now()->subMonths(16));  // 400 — borderline

        $svc = new CmaComputeService();
        $out = $svc->compute(
            $presentation->fresh(['property', 'soldComps', 'agency']),
            $presentation->fresh('soldComps')->soldComps,
            isSectional: false,
            conditionContext: [],
        );
        $this->assertSame(0.5, $out['pool_stats']['iqr_multiplier_used']);
        // 8 comps sorted R/m² = [400, 700, 800, 900, 1000, 1100, 1200, 1300].
        // Median = 1000, Q1 = 800, Q3 = 1200, IQR = 400.
        // Tightened fence (0.5× median-anchored) = 1000 − 200 = 800.
        // Drops R 400/m² + R 700/m² → 6 survive.
        // Demonstrates the multiplier setting takes effect (default 1.5
        // would have left all 8 since fence would have been 400).
        $this->assertSame(6, $out['pool_stats']['n_after_outlier_cut']);
        $this->assertSame(2, $out['pool_stats']['excluded_by_outlier']);
    }

    // ── Min-n floor / fallback ladder ────────────────────────────────

    public function test_outlier_cut_falls_back_to_recency_when_too_thin(): void
    {
        [$presentation, $agencyId] = $this->seedAgencyPropertyPresentation();
        // Force IQR to cut aggressively by using a tight multiplier (0.5).
        // 4 legit @ R 1000-1030/m² + 2 noise @ R 50-55/m². n=6.
        // Sorted R/m² = [50, 55, 1000, 1010, 1020, 1030].
        // Median = idx 3 = 1010. Q1 = idx 1 = 55. Q3 = idx 4 = 1020.
        // IQR = 965. Fence (0.5×) = 1010 − 482 = 528.
        // Drops 50 + 55 → 4 survive (< MIN_VIABLE_N=5) → outlier_too_thin.
        Agency::where('id', $agencyId)->update(['cma_compute_iqr_multiplier' => 0.5]);
        $this->seedComp($presentation->id, $agencyId, 50_000, 1000, now()->subMonths(2));
        $this->seedComp($presentation->id, $agencyId, 55_000, 1000, now()->subMonths(3));
        $this->seedComp($presentation->id, $agencyId, 1_000_000, 1000, now()->subMonths(4));
        $this->seedComp($presentation->id, $agencyId, 1_010_000, 1000, now()->subMonths(5));
        $this->seedComp($presentation->id, $agencyId, 1_020_000, 1000, now()->subMonths(6));
        $this->seedComp($presentation->id, $agencyId, 1_030_000, 1000, now()->subMonths(7));

        $svc = new CmaComputeService();
        $out = $svc->compute(
            $presentation->fresh(['property', 'soldComps', 'agency']),
            $presentation->fresh('soldComps')->soldComps,
            isSectional: false,
            conditionContext: [],
        );
        $ps = $out['pool_stats'];
        $this->assertSame(6, $ps['n_after_recency_cut']);
        $this->assertSame(4, $ps['n_after_outlier_cut']);
        $this->assertSame('outlier_too_thin', $ps['cleaning_fallback']);
        // Method should compute off 6 comps (the recency-only fallback),
        // not the 4-comp outlier set.
        $this->assertSame(6, $out['method_median']['n']);
    }

    public function test_recency_too_thin_falls_back_to_preclean_pool(): void
    {
        [$presentation, $agencyId] = $this->seedAgencyPropertyPresentation();
        // 3 recent + 5 ancient. Recency-only would leave 3 (< 5) →
        // recency_too_thin fallback uses all 8.
        $this->seedComp($presentation->id, $agencyId, 1_000_000, 100, now()->subMonths(2));
        $this->seedComp($presentation->id, $agencyId, 1_100_000, 100, now()->subMonths(4));
        $this->seedComp($presentation->id, $agencyId, 1_200_000, 100, now()->subMonths(6));
        $this->seedComp($presentation->id, $agencyId, 800_000, 100, now()->subYears(5));
        $this->seedComp($presentation->id, $agencyId, 850_000, 100, now()->subYears(6));
        $this->seedComp($presentation->id, $agencyId, 900_000, 100, now()->subYears(7));
        $this->seedComp($presentation->id, $agencyId, 950_000, 100, now()->subYears(8));
        $this->seedComp($presentation->id, $agencyId, 1_000_000, 100, now()->subYears(9));

        $svc = new CmaComputeService();
        $out = $svc->compute(
            $presentation->fresh(['property', 'soldComps', 'agency']),
            $presentation->fresh('soldComps')->soldComps,
            isSectional: false,
            conditionContext: [],
        );
        $ps = $out['pool_stats'];
        $this->assertSame(3, $ps['n_after_recency_cut']);
        $this->assertSame('recency_too_thin', $ps['cleaning_fallback']);
        // Method should compute off the full 8-comp pre-clean pool.
        $this->assertSame(8, $out['method_median']['n']);
    }

    // ── Output shape integrity ───────────────────────────────────────

    public function test_methods_carry_preclean_subkey_for_diff_inspection(): void
    {
        [$presentation, $agencyId] = $this->seedAgencyPropertyPresentation();
        // Seed enough comps for cleaning to actually run + leave > 5.
        // 7 legit + 2 noise — outlier cut survives MIN_VIABLE_N=5.
        $this->seedComp($presentation->id, $agencyId, 80_000, 1500, now()->subMonths(3));   // noise
        $this->seedComp($presentation->id, $agencyId, 100_000, 1800, now()->subMonths(5));  // noise
        foreach ([700_000, 800_000, 900_000, 1_000_000, 1_100_000, 1_200_000, 1_300_000] as $i => $p) {
            $this->seedComp($presentation->id, $agencyId, $p, 1200, now()->subMonths(2 + $i));
        }

        $svc = new CmaComputeService();
        $out = $svc->compute(
            $presentation->fresh(['property', 'soldComps', 'agency']),
            $presentation->fresh('soldComps')->soldComps,
            isSectional: false,
            conditionContext: [],
        );

        $this->assertArrayHasKey('preclean', $out['method_median']);
        $this->assertArrayHasKey('preclean', $out['method_mean']);
        $this->assertArrayHasKey('preclean', $out['method_rm2_extent']);

        // The two cleaned sets should differ from preclean because the
        // outlier cut excluded the two R 50/m² noise rows.
        $this->assertNotSame($out['method_median']['raw'], $out['method_median']['preclean']['raw']);
        $this->assertNotSame($out['method_mean']['raw'], $out['method_mean']['preclean']['raw']);
    }

    // ── Defaults when agency settings null ────────────────────────────

    public function test_defaults_used_when_agency_settings_null(): void
    {
        [$presentation, $agencyId] = $this->seedAgencyPropertyPresentation();
        Agency::where('id', $agencyId)->update([
            'cma_compute_recency_months' => null,
            'cma_compute_iqr_multiplier' => null,
        ]);
        $this->seedComp($presentation->id, $agencyId, 1_000_000, 100, now()->subMonths(2));

        $svc = new CmaComputeService();
        $out = $svc->compute(
            $presentation->fresh(['property', 'soldComps', 'agency']),
            $presentation->fresh('soldComps')->soldComps,
            isSectional: false,
            conditionContext: [],
        );
        $this->assertSame(36, $out['pool_stats']['recency_months_used']);
        $this->assertSame(1.5, $out['pool_stats']['iqr_multiplier_used']);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** @return array{0:Presentation, 1:int} */
    private function seedAgencyPropertyPresentation(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Build8b ' . Str::random(6),
            'slug' => 'b8b-' . Str::random(8),
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
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $user->id,
            'title'         => 'B8b Test Property',
            'property_type' => 'House',
            'category'      => 'Residential',
            'suburb'        => 'Testville',
            'price'         => 1_500_000,
            'address'       => '1 Test Lane',
            'status'        => 'active',
            'listing_type'  => 'sale',
            'erf_size_m2'   => 800,
            'latitude'      => -30.84,
            'longitude'     => 30.39,
        ]);
        $presentation = Presentation::create([
            'agency_id'          => $agencyId,
            'branch_id'          => $agencyId,
            'property_id'        => $property->id,
            'created_by_user_id' => $user->id,
            'title'              => 'B8b cleaning test',
            'property_address'   => '1 Test Lane',
            'suburb'             => 'Testville',
            'property_type'      => 'other',
            'erf_size_m2'        => 800,
            'asking_price_inc'   => 1_500_000,
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);
        return [$presentation, $agencyId];
    }

    private function seedComp(int $presentationId, int $agencyId, int $price, ?int $sizeM2, $soldDate): PresentationSoldComp
    {
        return PresentationSoldComp::create([
            'agency_id'       => $agencyId,
            'presentation_id' => $presentationId,
            'property_type'   => 'House',
            'sold_date'       => $soldDate instanceof \DateTimeInterface
                ? $soldDate->format('Y-m-d')
                : (string) $soldDate,
            'sold_price_inc'  => $price,
            'suburb'          => 'Testville',
            'size_m2'         => $sizeM2,
            'raw_row_json'    => json_encode(['address' => 'Comp ' . Str::random(4)]),
            'parser_version'  => 'test',
        ]);
    }
}
