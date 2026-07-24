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
 * CMA valuation SANITY GUARDRAIL (STEP 2b).
 *
 * Additive, surfacing-only warning that flags when the median-based CMA
 * headline may be unreliable. Proven against the live presentation set on
 * 2026-07-24: flags the 6 explosion cases + the Harrison under-valuation,
 * clears all 28 "sane" presentations. These tests pin each branch:
 *
 *   - sane           → subject sized like the comps, methods agree → NO flag.
 *   - divergence     → subject much larger than comps (Harrison-class) → flag.
 *   - basis mismatch → comps + subject on different size bases → high-sev flag.
 *   - no cross-check → comps carry no size → guardrail stays SILENT (no false alarm).
 *
 * The guardrail NEVER changes the headline number — it only adds a
 * `valuation_guardrail` block to cma_valuation.
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

    /** Subject sized like the comps → median and size-normalised agree → no flag. */
    public function test_sane_valuation_is_not_flagged(): void
    {
        // 5 comps size 100, median price 1_000_000, median R/m² 10_000.
        // Subject extent 100 → size-normalised = 10_000 × 100 = 1_000_000 == median.
        [$presentation, $agencyId] = $this->seedPresentation(extentM2: 100);
        foreach ([400_000, 800_000, 1_000_000, 1_200_000, 1_400_000] as $price) {
            $this->seedComp($presentation->id, $agencyId, $price, 100);
        }

        $vg = $this->guardrail($presentation);

        $this->assertFalse($vg['flagged']);
        $this->assertNull($vg['severity']);
        $this->assertSame(1_000_000, $vg['median_value']);
        $this->assertSame(1_000_000, $vg['rm2_value']);
        $this->assertEqualsWithDelta(0.0, $vg['divergence_pct'], 0.01);
        $this->assertFalse($vg['basis_mismatch']);
    }

    /** Subject materially larger than the comps (Harrison-class) → review flag, headline untouched. */
    public function test_size_driven_divergence_is_flagged_for_review(): void
    {
        // Comps size 100, median R/m² 10_000; subject extent 180 → 1_800_000
        // vs median 1_000_000 = +80% divergence. Basis ratio 1.8 (same basis),
        // so it is a genuine size difference, not a mismatch artefact.
        [$presentation, $agencyId] = $this->seedPresentation(extentM2: 180);
        foreach ([400_000, 800_000, 1_000_000, 1_200_000, 1_400_000] as $price) {
            $this->seedComp($presentation->id, $agencyId, $price, 100);
        }

        $analysis = (new AnalysisDataService())->compile($presentation->fresh(['property', 'soldComps']));
        $vg = $analysis['cma_valuation']['valuation_guardrail'];

        $this->assertTrue($vg['flagged']);
        $this->assertSame('review', $vg['severity']);
        $this->assertContains('diverges_from_size_normalised', $vg['reasons']);
        $this->assertFalse($vg['basis_mismatch']);
        $this->assertEqualsWithDelta(80.0, $vg['divergence_pct'], 0.1);
        $this->assertNotEmpty($vg['message']);

        // The headline itself is UNCHANGED — guardrail is surfacing only.
        $this->assertSame(1_000_000, $analysis['cma_valuation']['cma_middle']);
    }

    /** Comps (small units) and subject (big erf) on different size bases → high-severity flag. */
    public function test_size_basis_mismatch_is_flagged_high(): void
    {
        // Comps size 60 (unit floor-area), median price 600_000, R/m² ~10_000;
        // subject extent 3_000 (full erf) → 30_000_000. Basis ratio 50× → mismatch.
        [$presentation, $agencyId] = $this->seedPresentation(extentM2: 3_000);
        foreach ([500_000, 550_000, 600_000, 650_000, 700_000] as $price) {
            $this->seedComp($presentation->id, $agencyId, $price, 60);
        }

        $vg = $this->guardrail($presentation);

        $this->assertTrue($vg['flagged']);
        $this->assertSame('high', $vg['severity']);
        $this->assertTrue($vg['basis_mismatch']);
        $this->assertContains('comp_size_basis_mismatch', $vg['reasons']);
        $this->assertGreaterThan(2.5, $vg['basis_ratio']);
        $this->assertNotEmpty($vg['message']);
    }

    /** No comp carries a size → nothing to cross-check → guardrail stays silent. */
    public function test_no_size_data_does_not_false_alarm(): void
    {
        [$presentation, $agencyId] = $this->seedPresentation(extentM2: 100);
        foreach ([800_000, 1_000_000, 1_200_000] as $price) {
            $this->seedComp($presentation->id, $agencyId, $price, null); // no size
        }

        $vg = $this->guardrail($presentation);

        $this->assertFalse($vg['flagged']);
        $this->assertNull($vg['rm2_value']);
        $this->assertNull($vg['divergence_pct']);
        $this->assertNull($vg['basis_ratio']);
    }

    // ── helpers ──────────────────────────────────────────────────────

    private function guardrail(Presentation $presentation): array
    {
        $analysis = (new AnalysisDataService())->compile($presentation->fresh(['property', 'soldComps']));
        return $analysis['cma_valuation']['valuation_guardrail'];
    }

    /** @return array{0:Presentation, 1:int} */
    private function seedPresentation(int $extentM2): array
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
        return [$presentation, $agencyId];
    }

    private function seedComp(int $presentationId, int $agencyId, int $price, ?int $sizeM2): PresentationSoldComp
    {
        return PresentationSoldComp::create([
            'agency_id' => $agencyId, 'presentation_id' => $presentationId, 'property_type' => 'House',
            'sold_date' => now()->subMonths(3)->toDateString(), 'sold_price_inc' => $price,
            'suburb' => 'Testville', 'size_m2' => $sizeM2,
            'raw_row_json' => json_encode(['address' => 'Comp ' . Str::random(4)]),
            'parser_version' => 'test',
        ]);
    }
}
