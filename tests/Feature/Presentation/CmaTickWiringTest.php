<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation;

use App\Models\Presentation;
use App\Models\PresentationField;
use App\Models\PresentationSoldComp;
use App\Models\PresentationVersion;
use App\Models\Property;
use App\Models\User;
use App\Services\Presentations\AnalysisDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tick-wire build — three fixes:
 *   1. AnalysisDataService filters comps by version.included_comp_ids_json
 *      before handing them to CmaComputeService.
 *   2. cma_valuation tile values (cma_lower/middle/upper) read from
 *      cma_computed (median + p25/p75), NOT from presentation_fields
 *      cma.*_range. CMA Info bands surface under cma_info_benchmark
 *      for the review-screen internal reference only.
 *   3. No comps at all yields null tile values, not a crash. (A stored []
 *      selection is read as "all comps" since 2026-10-07 — see AlwaysHasPriceTest.)
 */
final class CmaTickWiringTest extends TestCase
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

    public function test_tiles_read_from_cma_computed_not_cma_info_fields(): void
    {
        [$presentation, $agencyId] = $this->seedAgencyPropertyPresentation(extentM2: 800);
        // Five-comp fixture — median 1_000_000, p25 800_000, p75 1_200_000.
        foreach ([400_000, 800_000, 1_000_000, 1_200_000, 1_400_000] as $price) {
            $this->seedComp($presentation->id, $agencyId, $price, 100);
        }
        // Plant CMA Info stated bands that DIFFER from the computed median,
        // so we can prove the tiles read from cma_computed.
        $this->seedCmaInfoFields($presentation->id, lower: 500_000, middle: 700_000, upper: 900_000);

        $version  = $this->seedVersion($presentation, includedCompIds: null);
        $analysis = (new AnalysisDataService())->compile($presentation->fresh(), $version);
        $cma      = $analysis['cma_valuation'];

        // Tile values = CoreX-computed (NOT 500/700/900 from CMA Info): the
        // comp median is the middle; lower/upper are the middle ∓ the agency
        // band % (PRES-CMA-REALFIX — default 10 / 13), not the raw p25/p75.
        $this->assertSame(1_000_000, $cma['cma_middle']);
        $this->assertSame((int) round(1_000_000 * (1 - \App\Services\Presentations\CompPoolBuilder::DEF_BAND_LOWER_PCT / 100)), $cma['cma_lower']);
        $this->assertSame((int) round(1_000_000 * (1 + \App\Services\Presentations\CompPoolBuilder::DEF_BAND_UPPER_PCT / 100)), $cma['cma_upper']);

        // CMA Info benchmark preserved under cma_info_benchmark.
        $this->assertSame(500_000, $cma['cma_info_benchmark']['lower']);
        $this->assertSame(700_000, $cma['cma_info_benchmark']['middle']);
        $this->assertSame(900_000, $cma['cma_info_benchmark']['upper']);

        // Pool count surfaced.
        $this->assertSame(5, $cma['compute_pool_n']);
    }

    public function test_unticking_a_comp_changes_the_tile_values(): void
    {
        [$presentation, $agencyId] = $this->seedAgencyPropertyPresentation(extentM2: 800);
        $comps = [];
        foreach ([400_000, 800_000, 1_000_000, 1_200_000, 1_400_000] as $price) {
            $comps[] = $this->seedComp($presentation->id, $agencyId, $price, 100);
        }

        // All 5 included — median = 1_000_000.
        $version  = $this->seedVersion($presentation, includedCompIds: null);
        $analysis = (new AnalysisDataService())->compile($presentation->fresh(), $version);
        $this->assertSame(1_000_000, $analysis['cma_valuation']['cma_middle']);

        // Untick the highest-price comp (1_400_000); median shifts down.
        // Remaining: [400k, 800k, 1m, 1.2m] → median at floor(4*0.5)=idx 2 → 1_000_000.
        // Untick TWO highest (1_200_000 + 1_400_000) → [400k, 800k, 1m] → median = 800_000.
        $survivingIds = collect($comps)
            ->reject(fn ($c) => in_array($c->sold_price_inc, [1_200_000, 1_400_000], true))
            ->pluck('id')->all();
        $version->forceFill(['included_comp_ids_json' => $survivingIds])->save();

        $analysis2 = (new AnalysisDataService())->compile($presentation->fresh(), $version->fresh());
        $this->assertSame(800_000, $analysis2['cma_valuation']['cma_middle']);
        $this->assertSame(3, $analysis2['cma_valuation']['compute_pool_n']);
    }

    /** [] used to mean "agent unticked everything" and blanked the tiles. It is no longer a valid
     *  selection (a presentation must always have a price — Review refuses it, AlwaysHasPriceTest):
     *  a stored [] is read as "all comps", so the tiles are numbers, never null. */
    public function test_a_stored_empty_selection_is_read_as_all_comps_not_null_tiles(): void
    {
        [$presentation, $agencyId] = $this->seedAgencyPropertyPresentation(extentM2: 800);
        foreach ([400_000, 800_000, 1_000_000, 1_200_000, 1_400_000] as $price) {
            $this->seedComp($presentation->id, $agencyId, $price, 100);
        }

        $version = $this->seedVersion($presentation, includedCompIds: []);

        $analysis = (new AnalysisDataService())->compile($presentation->fresh(), $version);
        $cma      = $analysis['cma_valuation'];

        $this->assertSame(1_000_000, $cma['cma_middle']);
        $this->assertNotNull($cma['cma_lower']);
        $this->assertNotNull($cma['cma_upper']);
        $this->assertSame(5, $cma['compute_pool_n']);
    }

    public function test_cma_info_benchmark_preserved_when_no_computed_pool(): void
    {
        [$presentation, $agencyId] = $this->seedAgencyPropertyPresentation(extentM2: 800);
        // No comps — empty pool.
        $this->seedCmaInfoFields($presentation->id, lower: 500_000, middle: 700_000, upper: 900_000);
        $version = $this->seedVersion($presentation, includedCompIds: null);

        $analysis = (new AnalysisDataService())->compile($presentation->fresh(), $version);
        $cma      = $analysis['cma_valuation'];

        // Tiles null (no compute pool), benchmark still surfaced.
        $this->assertNull($cma['cma_middle']);
        $this->assertSame(700_000, $cma['cma_info_benchmark']['middle']);
    }

    public function test_compute_method_label_is_median(): void
    {
        [$presentation, $agencyId] = $this->seedAgencyPropertyPresentation();
        $this->seedComp($presentation->id, $agencyId, 1_000_000, 100);
        $version  = $this->seedVersion($presentation, includedCompIds: null);
        $analysis = (new AnalysisDataService())->compile($presentation->fresh(), $version);
        $this->assertSame('median', $analysis['cma_valuation']['compute_method']);
    }

    // ── helpers ────────────────────────────────────────────────────────

    /** @return array{0:Presentation, 1:int} */
    private function seedAgencyPropertyPresentation(?int $extentM2 = 800): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'TickWire ' . Str::random(4),
            'slug' => 'tw-' . Str::random(6),
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
            'title'         => 'Subject',
            'property_type' => 'House',
            'category'      => 'Residential',
            'suburb'        => 'Testville',
            'price'         => 1_900_000,
            'address'       => '1 Test Lane',
            'status'        => 'active',
            'listing_type'  => 'sale',
            'erf_size_m2'   => $extentM2,
            'latitude'      => -30.84,
            'longitude'     => 30.39,
        ]);
        $presentation = Presentation::create([
            'agency_id'          => $agencyId,
            'branch_id'          => $agencyId,
            'property_id'        => $property->id,
            'created_by_user_id' => $user->id,
            'title'              => 'TickWireTest',
            'property_address'   => '1 Test Lane',
            'suburb'             => 'Testville',
            'property_type'      => 'other',
            'erf_size_m2'        => $extentM2,
            'asking_price_inc'   => 1_900_000,
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);
        return [$presentation, $agencyId];
    }

    private function seedComp(int $presentationId, int $agencyId, int $price, ?int $sizeM2): PresentationSoldComp
    {
        return PresentationSoldComp::create([
            'agency_id'       => $agencyId,
            'presentation_id' => $presentationId,
            'property_type'   => 'House',
            'sold_date'       => now()->subMonths(rand(1, 12))->toDateString(),
            'sold_price_inc'  => $price,
            'suburb'          => 'Testville',
            'size_m2'         => $sizeM2,
            'raw_row_json'    => json_encode(['address' => 'Comp ' . Str::random(4)]),
            'parser_version'  => 'test',
        ]);
    }

    private function seedCmaInfoFields(int $presentationId, int $lower, int $middle, int $upper): void
    {
        foreach ([
            'cma.lower_range'  => $lower,
            'cma.middle_range' => $middle,
            'cma.upper_range'  => $upper,
        ] as $key => $value) {
            PresentationField::create([
                'presentation_id' => $presentationId,
                'field_key'       => $key,
                'extracted_value' => (string) $value,
                'override_value'  => null,
                'final_value'     => (string) $value,
                'confidence'      => 0.95,
            ]);
        }
    }

    private function seedVersion(Presentation $presentation, ?array $includedCompIds): PresentationVersion
    {
        return PresentationVersion::create([
            'agency_id'             => $presentation->agency_id,
            'presentation_id'       => $presentation->id,
            'blueprint_version'     => 'test',
            'data_snapshot_json'    => json_encode(['note' => 'tick-wire-test']),
            'included_comp_ids_json' => $includedCompIds,
            'compiled_at'           => now(),
        ]);
    }
}
