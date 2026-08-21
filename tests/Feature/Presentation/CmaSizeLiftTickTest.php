<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation;

use App\Models\AgentOverride;
use App\Models\Presentation;
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
 * Johan (2026-08-21, size-lift ruling): "CMA has been in the game for a long
 * time. I think they are better at it at this stage than CoreX." The CMA
 * size-normalised lift (median R/m² x subject extent) is no longer automatic
 * — it is a review-screen agent opt-in, defaulting OFF, recorded on the
 * presentation so the valuation stays reproducible.
 *
 * Canonical fixture (shared across most tests here): 5 comps, size 100 m²
 * each, prices [400k, 800k, 1_000k, 1_200k, 1_400k] -> median price
 * R1,000,000, median R/m² R10,000. A subject extent of 160 m² (basis ratio
 * 1.6, uplift +60%) sits exactly at the old auto-lift's full-weight point:
 * lifted_value = R1,600,000 (proven against ValuationGuardrailTest's
 * identical numbers). A subject extent of 100 m² (basis ratio 1.0, rm2 ==
 * median) is the "no material size difference" control — never eligible.
 */
final class CmaSizeLiftTickTest extends TestCase
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

    public function test_default_off_gives_the_comp_median(): void
    {
        [$presentation, $agencyId] = $this->seedSubject(extentM2: 160);
        $this->seedFiveComps($presentation->id, $agencyId);
        $version = $this->seedVersion($presentation);

        $this->assertFalse((bool) $presentation->fresh()->cma_size_lift_applied, 'default is OFF');

        $cma = (new AnalysisDataService())->compile($presentation->fresh(), $version)['cma_valuation'];

        $this->assertSame(1_000_000, $cma['cma_middle'], 'untouched — the plain comp median, not the lift');
        $this->assertSame('median', $cma['compute_method']);
        $this->assertFalse($cma['headline_lifted']);
        $this->assertFalse($cma['size_lift_applied']);
        $this->assertTrue($cma['size_lift_available'], 'evidence supports a lift — the tick should be offered');
    }

    public function test_ticked_reproduces_the_old_lifted_number(): void
    {
        [$presentation, $agencyId] = $this->seedSubject(extentM2: 160);
        $this->seedFiveComps($presentation->id, $agencyId);
        $version = $this->seedVersion($presentation);

        $presentation->forceFill(['cma_size_lift_applied' => true])->save();

        $cma = (new AnalysisDataService())->compile($presentation->fresh(), $version)['cma_valuation'];

        $this->assertSame(1_600_000, $cma['cma_middle'], 'reproduces the old auto-lift number exactly');
        $this->assertSame('size_adjusted', $cma['compute_method']);
        $this->assertTrue($cma['headline_lifted']);
        $this->assertTrue($cma['size_lift_applied']);
        $this->assertEqualsWithDelta(60.0, $cma['size_lift_pct'], 0.1);
        $this->assertEqualsWithDelta(60.0, $cma['size_diff_pct'], 0.1);
    }

    public function test_band_follows_the_headline_in_both_states(): void
    {
        [$presentation, $agencyId] = $this->seedSubject(extentM2: 160);
        $this->seedFiveComps($presentation->id, $agencyId);
        $version = $this->seedVersion($presentation);

        $off = (new AnalysisDataService())->compile($presentation->fresh(), $version)['cma_valuation'];
        $this->assertGreaterThan($off['cma_lower'], $off['cma_middle']);
        $this->assertGreaterThan($off['cma_middle'], $off['cma_upper']);
        // Band derives from the median (1_000_000) when off — nowhere near
        // the inflated 1_600_000 baseline.
        $this->assertLessThan(1_600_000, $off['cma_upper']);

        $presentation->forceFill(['cma_size_lift_applied' => true])->save();
        $on = (new AnalysisDataService())->compile($presentation->fresh(), $version->fresh())['cma_valuation'];
        $this->assertGreaterThan($on['cma_lower'], $on['cma_middle']);
        $this->assertGreaterThan($on['cma_middle'], $on['cma_upper']);
        // Band now derives from the lifted 1_600_000 — both edges moved with it.
        $this->assertGreaterThan(1_600_000, $on['cma_upper']);
        $this->assertGreaterThan($off['cma_upper'], $on['cma_upper'], 'band widened because the headline it follows moved');
    }

    public function test_tick_unavailable_when_no_material_size_difference(): void
    {
        // Subject extent EQUALS the comps' size — basis ratio 1.0, rm2 == median.
        [$presentation, $agencyId] = $this->seedSubject(extentM2: 100);
        $this->seedFiveComps($presentation->id, $agencyId);
        $version = $this->seedVersion($presentation);

        $cma = (new AnalysisDataService())->compile($presentation->fresh(), $version)['cma_valuation'];

        $this->assertFalse($cma['size_lift_available'], 'no material size difference — tick must not appear');
        $this->assertSame(1_000_000, $cma['cma_middle']);
    }

    public function test_tick_available_reflects_real_percentages_not_a_fixed_sentence(): void
    {
        // A DIFFERENT ratio (extent 135 -> basis 1.35, uplift +35%, just past
        // the ramp's LOW threshold of 30% where weight is exactly 0 — not
        // eligible) proves the label numbers come from THIS fixture's real
        // data, not a hardcoded string.
        [$presentation, $agencyId] = $this->seedSubject(extentM2: 135);
        $this->seedFiveComps($presentation->id, $agencyId);
        $version = $this->seedVersion($presentation);

        $cma = (new AnalysisDataService())->compile($presentation->fresh(), $version)['cma_valuation'];

        $this->assertTrue($cma['size_lift_available']);
        $this->assertEqualsWithDelta(35.0, $cma['size_diff_pct'], 0.1);
        // Near the low end of the ramp (weight = (35-30)/30 = 0.167) the
        // lift moves the value by ~5.8%, well under size_diff_pct (35%) --
        // proves the two are computed independently, not the same number
        // relabelled.
        $this->assertEqualsWithDelta(5.83, $cma['size_lift_pct'], 0.1);
        $this->assertLessThan($cma['size_diff_pct'], $cma['size_lift_pct']);
    }

    public function test_units_use_floor_size_not_erf_size_and_say_unit_not_erf(): void
    {
        [$presentation, $agencyId] = $this->seedSubject(extentM2: null, sectional: true, floorAreaM2: 160);
        $this->seedFiveComps($presentation->id, $agencyId);
        $version = $this->seedVersion($presentation);

        $cma = (new AnalysisDataService())->compile($presentation->fresh(), $version)['cma_valuation'];

        $this->assertSame('unit', $cma['size_lift_subject_noun']);
        $this->assertTrue($cma['size_lift_available'], 'floor area 160 vs comp size 100 is the same ratio as the erf case');
        $this->assertEqualsWithDelta(60.0, $cma['size_diff_pct'], 0.1);
    }

    public function test_choice_is_recorded_via_the_review_endpoint(): void
    {
        [$presentation, $agencyId] = $this->seedSubject(extentM2: 160);
        $this->seedFiveComps($presentation->id, $agencyId);
        $version = $this->seedVersion($presentation);
        $user = $this->seedUserForAgency($agencyId);

        $resp = $this->actingAs($user)->postJson(
            route('presentations.review.size-lift', $version->id),
            ['applied' => true],
        );

        $resp->assertOk();
        $resp->assertJson(['ok' => true, 'applied' => true]);
        $this->assertSame(1_600_000, $resp->json('cma.middle'));

        $this->assertTrue((bool) $presentation->fresh()->cma_size_lift_applied, 'recorded on the presentation');
        $this->assertDatabaseHas('agent_overrides', [
            'presentation_version_id' => $version->id,
            'override_type'           => AgentOverride::TYPE_SIZE_LIFT_TOGGLED,
            'target_id'               => (string) $presentation->id,
        ]);

        // Untick — reverts the record and the value.
        $resp2 = $this->actingAs($user)->postJson(
            route('presentations.review.size-lift', $version->id),
            ['applied' => false],
        );
        $resp2->assertOk();
        $this->assertFalse((bool) $presentation->fresh()->cma_size_lift_applied);
        $this->assertSame(1_000_000, $resp2->json('cma.middle'));
    }

    public function test_cannot_apply_when_not_available(): void
    {
        [$presentation, $agencyId] = $this->seedSubject(extentM2: 100); // ratio 1.0 — never eligible
        $this->seedFiveComps($presentation->id, $agencyId);
        $version = $this->seedVersion($presentation);
        $user = $this->seedUserForAgency($agencyId);

        $resp = $this->actingAs($user)->postJson(
            route('presentations.review.size-lift', $version->id),
            ['applied' => true],
        );

        $resp->assertStatus(422);
        $this->assertFalse((bool) $presentation->fresh()->cma_size_lift_applied, 'refused — evidence does not support a lift');
    }

    // ── helpers ────────────────────────────────────────────────────────

    /** @return array{0:Presentation, 1:int} */
    private function seedSubject(?int $extentM2, bool $sectional = false, ?int $floorAreaM2 = null): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'SizeLift ' . Str::random(4),
            'slug' => 'sl-' . Str::random(6),
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
            'property_type' => $sectional ? 'Apartment' : 'House',
            'category'      => 'Residential',
            'title_type'    => $sectional ? 'sectional_title' : 'full_title',
            'suburb'        => 'Testville',
            'price'         => 1_900_000,
            'address'       => '1 Test Lane',
            'status'        => 'active',
            'listing_type'  => 'sale',
            'erf_size_m2'   => $extentM2,
            'size_m2'       => $floorAreaM2,
            'latitude'      => -30.84,
            'longitude'     => 30.39,
        ]);
        $presentation = Presentation::create([
            'agency_id'          => $agencyId,
            'branch_id'          => $agencyId,
            'property_id'        => $property->id,
            'created_by_user_id' => $user->id,
            'title'              => 'SizeLiftTest',
            'property_address'   => '1 Test Lane',
            'suburb'             => 'Testville',
            'property_type'      => $sectional ? 'sectional' : 'other',
            'erf_size_m2'        => $extentM2,
            'floor_area_m2'      => $floorAreaM2,
            'asking_price_inc'   => 1_900_000,
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);
        return [$presentation, $agencyId];
    }

    private function seedUserForAgency(int $agencyId): User
    {
        return User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);
    }

    private function seedFiveComps(int $presentationId, int $agencyId): void
    {
        foreach ([400_000, 800_000, 1_000_000, 1_200_000, 1_400_000] as $price) {
            PresentationSoldComp::create([
                'agency_id'       => $agencyId,
                'presentation_id' => $presentationId,
                'property_type'   => 'House',
                'sold_date'       => now()->subMonths(rand(1, 12))->toDateString(),
                'sold_price_inc'  => $price,
                'suburb'          => 'Testville',
                'size_m2'         => 100,
                'raw_row_json'    => json_encode(['address' => 'Comp ' . Str::random(4)]),
                'parser_version'  => 'test',
            ]);
        }
    }

    private function seedVersion(Presentation $presentation): PresentationVersion
    {
        return PresentationVersion::create([
            'agency_id'              => $presentation->agency_id,
            'presentation_id'        => $presentation->id,
            'blueprint_version'      => 'test',
            'data_snapshot_json'     => json_encode(['note' => 'size-lift-tick-test']),
            'included_comp_ids_json' => null,
            'compiled_at'            => now(),
        ]);
    }
}
