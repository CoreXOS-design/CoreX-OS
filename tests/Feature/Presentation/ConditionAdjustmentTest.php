<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation;

use App\Models\AgentOverride;
use App\Models\Presentation;
use App\Models\PresentationField;
use App\Models\PresentationVersion;
use App\Models\Property;
use App\Models\PropertySettingItem;
use App\Models\User;
use App\Services\Presentations\AnalysisDataService;
use App\Services\Presentations\ConditionAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Build 3 — condition-driven valuation tests.
 *
 * Covers the 8 proofs from the Build 3 prompt:
 *   1. Seeded 7 defaults visible on freshly-migrated agency.
 *   2. Edit a condition level → adjustment_pct updates persist.
 *   3. Property edit form / model accepts condition_level_id.
 *   4. Generated review screen → band is the RAW comp distribution; the
 *      property's condition is recorded but NOT applied to the band
 *      (PRES-CMA-REALFIX — re-scaling double-counted condition).
 *   5. Override on review screen → condition recorded + override row logged;
 *      the band stays raw (condition no longer scales it).
 *   6. Publish → version snapshot frozen (condition_adjustment_pct).
 *   7. Property pointing at deleted condition → graceful fallback.
 *   8. Multi-tenancy: agency A's condition levels invisible to agency B.
 */
final class ConditionAdjustmentTest extends TestCase
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

    // ── 1 — defaults seeded ──────────────────────────────────────────

    public function test_migration_seeds_seven_default_condition_levels_per_agency(): void
    {
        [$agencyId] = $this->seedAgencyAndUser();

        $levels = PropertySettingItem::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('group', 'condition_level')
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(7, $levels);
        $this->assertSame(['To Remodel','To Renovate','Average','Good','Very Good','Excellent','Exceptional'], $levels->pluck('name')->all());
        $this->assertEqualsWithDelta(-30.0, (float) $levels[0]->adjustment_pct, 0.01);
        $this->assertEqualsWithDelta(0.0,   (float) $levels[2]->adjustment_pct, 0.01);
        $this->assertEqualsWithDelta(12.0,  (float) $levels[4]->adjustment_pct, 0.01);
        $this->assertEqualsWithDelta(38.0,  (float) $levels[6]->adjustment_pct, 0.01);
    }

    // ── 2 — settings controller persists adjustment_pct ───────────────

    public function test_settings_controller_persists_adjustment_pct_change(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $good = PropertySettingItem::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->where('name', 'Good')->first();

        $this->actingAs($user)
            ->put(route('corex.settings.property-items.update', $good->id), [
                'name'           => 'Good',
                'sort_order'     => $good->sort_order,
                'adjustment_pct' => 5.00,
            ])
            ->assertRedirect();

        $this->assertEqualsWithDelta(5.0, (float) $good->fresh()->adjustment_pct, 0.01);
    }

    public function test_baseline_average_pct_is_locked_at_zero_on_update(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $average = PropertySettingItem::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->where('name', 'Average')->first();

        $this->actingAs($user)
            ->put(route('corex.settings.property-items.update', $average->id), [
                'name'           => 'Average',
                'sort_order'     => $average->sort_order,
                'adjustment_pct' => 50.00, // controller MUST force this back to 0
            ])
            ->assertRedirect();

        $this->assertEqualsWithDelta(0.0, (float) $average->fresh()->adjustment_pct, 0.01);
    }

    public function test_baseline_average_cannot_be_deleted(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $average = PropertySettingItem::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->where('name', 'Average')->first();
        // Force-flip to non-default so the existing "defaults cannot be
        // deleted" guard doesn't pre-empt the Build 3 baseline guard.
        $average->update(['is_default' => false]);

        $this->actingAs($user)
            ->delete(route('corex.settings.property-items.destroy', $average->id))
            ->assertRedirect();

        $this->assertNotNull(PropertySettingItem::withoutGlobalScopes()->find($average->id));
    }

    // ── 3 — property condition_level_id ──────────────────────────────

    public function test_property_accepts_condition_level_id(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $veryGood = PropertySettingItem::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->where('name', 'Very Good')->first();

        $property = $this->createProperty($agencyId, $user->id, [
            'price'              => 1_800_000,
            'condition_level_id' => $veryGood->id,
        ]);

        $this->assertSame($veryGood->id, $property->fresh()->condition_level_id);
        $this->assertSame('Very Good', $property->fresh()->conditionLevel->name);
    }

    // ── 4 — AnalysisDataService: band is the RAW comp distribution ─────
    //
    // PRES-CMA-REALFIX (Johan, 2026-06-15): the §4/§5/§6 band is the RAW
    // comparable-sales p25/median/p75 — condition is NEVER applied to the
    // band by the code (the agent's condition assessment is already embodied
    // in the comp selection / asking). Re-scaling double-counted it and
    // inflated good properties (Grindewald: raw ~R2.5M printed as ~R3.0M at
    // +20%). The condition picker survives as an informational record only.

    public function test_analysis_data_keeps_raw_comp_band_and_does_not_scale_by_condition(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $veryGood = PropertySettingItem::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->where('name', 'Very Good')->first(); // +12%

        $property = $this->createProperty($agencyId, $user->id, [
            'price'              => 1_830_000,
            'condition_level_id' => $veryGood->id,
        ]);

        $version = $this->seedPresentationWithVersion($agencyId, $user->id, $property);
        // asking_price drives the key_insights comparisons (without it
        // compileKeyInsights returns no rows). Set 1_900_000 so the
        // "vs CMA Evaluation (middle)" benchmark renders.
        $version->presentation()->update(['asking_price_inc' => 1_900_000]);
        // Phase B reads tile lower/middle/upper from the compute pool's
        // p25/median/p75. Seed 5 sorted comp prices so the type-7
        // percentile lands exactly: sorted[1]=p25, sorted[2]=median,
        // sorted[3]=p75. Recent sold_dates keep them inside the default
        // 36-month recency window; IQR fences accept the full set.
        $this->seedSoldCompsForPercentiles($version->presentation_id, $agencyId, [
            1_500_000, 1_500_000, 1_830_000, 2_160_000, 2_160_000,
        ]);

        $analysis = (new AnalysisDataService())->compile(
            $version->presentation()->with('property')->first(),
            $version,
        );
        $cma = $analysis['cma_valuation'];

        // The band is the RAW comp distribution — NO ×1.12 condition factor
        // on ANY tile. A scaled band would read 1_680_000 / 2_049_600 /
        // 2_419_200; that double-count is exactly what this fix removes.
        $this->assertSame(1_500_000, $cma['cma_lower']);   // raw p25
        $this->assertSame(1_830_000, $cma['cma_middle']);  // raw median
        $this->assertSame(2_160_000, $cma['cma_upper']);   // raw p75
        // Ordered invariant holds naturally on a sorted distribution.
        $this->assertLessThan($cma['cma_middle'], $cma['cma_lower']);
        $this->assertLessThan($cma['cma_upper'],  $cma['cma_middle']);
        $this->assertSame(1_830_000, $cma['cma_middle_baseline']);
        // condition_applied is ALWAYS false now — the band is never scaled.
        $this->assertFalse($cma['condition_applied']);
        // pct/label/source still surface as informational metadata.
        $this->assertEqualsWithDelta(12.0, $cma['condition_pct'], 0.01);
        $this->assertSame('Very Good', $cma['condition_label']);
        $this->assertSame('property_default', $cma['condition_source']);

        // Price Position "vs CMA Evaluation (middle)" reads the SAME middle
        // the tiles render — now the raw median, not a scaled value.
        $cmaComparison = collect($analysis['key_insights']['comparisons'])
            ->firstWhere('label', 'vs CMA Evaluation (middle)');
        $this->assertNotNull($cmaComparison);
        $this->assertSame(1_830_000, $cmaComparison['benchmark']);
    }

    public function test_negative_condition_does_not_deduct_the_band(): void
    {
        // Bad-condition direction: a "To Renovate" (-15%) property must NOT
        // have its band deducted. The band stays the raw comp distribution.
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $toRenovate = PropertySettingItem::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->where('name', 'To Renovate')->first(); // -15%

        $property = $this->createProperty($agencyId, $user->id, [
            'price'              => 1_830_000,
            'condition_level_id' => $toRenovate->id,
        ]);
        $version = $this->seedPresentationWithVersion($agencyId, $user->id, $property);
        $this->seedSoldCompsForPercentiles($version->presentation_id, $agencyId, [
            1_500_000, 1_500_000, 1_830_000, 2_160_000, 2_160_000,
        ]);

        $cma = (new AnalysisDataService())->compile(
            $version->presentation()->with('property')->first(),
            $version,
        )['cma_valuation'];

        // A -15% deduction would read 1_275_000 / 1_555_500 / 1_836_000.
        // The band must stay RAW — not deducted once, not twice.
        $this->assertSame(1_500_000, $cma['cma_lower']);
        $this->assertSame(1_830_000, $cma['cma_middle']);
        $this->assertSame(2_160_000, $cma['cma_upper']);
        $this->assertFalse($cma['condition_applied']);
        $this->assertEqualsWithDelta(-15.0, $cma['condition_pct'], 0.01);
    }

    public function test_baseline_applies_when_no_condition_set(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $property = $this->createProperty($agencyId, $user->id, ['price' => 1_000_000]);
        $version = $this->seedPresentationWithVersion($agencyId, $user->id, $property);
        // Phase B reads the band from the comp pool. p25=900k, median=1M,
        // p75=1.1M with this 5-comp set.
        $this->seedSoldCompsForPercentiles($version->presentation_id, $agencyId, [
            900_000, 900_000, 1_000_000, 1_100_000, 1_100_000,
        ]);

        $analysis = (new AnalysisDataService())->compile(
            $version->presentation()->with('property')->first(),
            $version,
        );
        $cma = $analysis['cma_valuation'];

        $this->assertFalse($cma['condition_applied']);
        $this->assertSame(1_000_000, $cma['cma_middle']); // raw median
        $this->assertSame('none', $cma['condition_source']);
    }

    // ── 5 — review screen override + override log ─────────────────────

    public function test_set_condition_writes_override_and_records_condition_without_scaling_band(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $excellent = PropertySettingItem::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->where('name', 'Excellent')->first(); // +20%

        $property = $this->createProperty($agencyId, $user->id, ['price' => 1_830_000]);
        $version = $this->seedPresentationWithVersion($agencyId, $user->id, $property);
        // p25=1.5M, median=1.83M, p75=2.16M from this 5-comp pool.
        $this->seedSoldCompsForPercentiles($version->presentation_id, $agencyId, [
            1_500_000, 1_500_000, 1_830_000, 2_160_000, 2_160_000,
        ]);

        $resp = $this->actingAs($user)
            ->post(route('presentations.review.condition', $version->id), [
                'condition_level_id' => $excellent->id,
            ]);

        $resp->assertOk();
        $json = $resp->json();
        $this->assertTrue($json['ok']);
        $this->assertSame($excellent->id, $json['condition']['level_id']);
        // The recorded condition pct is surfaced for the picker display…
        $this->assertEqualsWithDelta(20.0, $json['condition']['pct'], 0.01);
        // …but it is NOT applied to the band, and the recompute says so.
        $this->assertFalse($json['condition']['applied']);
        // The band is the RAW median — a +20% scale would read 2_196_000.
        $this->assertSame(1_830_000, $json['cma']['middle']);
        $this->assertSame(1_500_000, $json['cma']['lower']);
        $this->assertSame(2_160_000, $json['cma']['upper']);

        $this->assertSame($excellent->id, $version->fresh()->condition_level_id);
        $this->assertDatabaseHas('agent_overrides', [
            'presentation_version_id' => $version->id,
            'override_type'           => AgentOverride::TYPE_CONDITION_CHANGED,
            'target_id'               => 'condition_level_id',
        ]);
    }

    public function test_set_condition_to_null_clears_override(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $excellent = PropertySettingItem::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->where('name', 'Excellent')->first();
        $property = $this->createProperty($agencyId, $user->id, ['price' => 1_000_000]);
        $version = $this->seedPresentationWithVersion($agencyId, $user->id, $property, [
            'condition_level_id' => $excellent->id,
        ]);
        $this->seedCmaFields($version->presentation_id, $agencyId, 900_000, 1_000_000, 1_100_000);

        $this->actingAs($user)
            ->post(route('presentations.review.condition', $version->id), [])
            ->assertOk();

        $this->assertNull($version->fresh()->condition_level_id);
    }

    // ── 6 — publish snapshots the condition ──────────────────────────

    public function test_publish_snapshots_resolved_condition_onto_version(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $good = PropertySettingItem::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->where('name', 'Good')->first(); // +3%
        $property = $this->createProperty($agencyId, $user->id, [
            'price' => 1_000_000, 'condition_level_id' => $good->id,
        ]);
        $version = $this->seedPresentationWithVersion($agencyId, $user->id, $property);

        $this->actingAs($user)
            ->post(route('presentations.analysis.confirm', $version->presentation_id))
            ->assertRedirect(route('presentations.show', $version->presentation_id));

        $fresh = $version->fresh();
        $this->assertSame($good->id, $fresh->condition_level_id);
        $this->assertEqualsWithDelta(3.0, (float) $fresh->condition_adjustment_pct, 0.01);
        $this->assertSame('Good', $fresh->condition_label);
    }

    public function test_published_snapshot_freezes_informational_pct_and_band_stays_raw(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $good = PropertySettingItem::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->where('name', 'Good')->first();
        $property = $this->createProperty($agencyId, $user->id, [
            'price' => 1_000_000, 'condition_level_id' => $good->id,
        ]);
        $version = $this->seedPresentationWithVersion($agencyId, $user->id, $property);
        // p25=900k, median=1M, p75=1.1M from this 5-comp pool.
        $this->seedSoldCompsForPercentiles($version->presentation_id, $agencyId, [
            900_000, 900_000, 1_000_000, 1_100_000, 1_100_000,
        ]);

        // Publish snapshots Good @ 3%.
        $this->actingAs($user)
            ->post(route('presentations.analysis.confirm', $version->presentation_id))
            ->assertRedirect(route('presentations.show', $version->presentation_id));

        // Agency later changes Good to 50%.
        $good->update(['adjustment_pct' => 50.0]);

        // PDF compile (= published path) must honour the SNAPSHOT pct for the
        // informational display (3.0, not the new 50.0) — the snapshot still
        // protects historic PDFs from settings drift.
        $analysis = (new AnalysisDataService())->compile(
            $version->presentation()->with('property')->first(),
            $version->fresh(),
        );
        $cma = $analysis['cma_valuation'];

        $this->assertEqualsWithDelta(3.0, $cma['condition_pct'], 0.01);
        $this->assertSame('version_snapshot', $cma['condition_source']);
        // …but the band itself is the RAW median — unaffected by the pct in
        // EITHER direction (neither 3% nor 50% scales it).
        $this->assertFalse($cma['condition_applied']);
        $this->assertSame(1_000_000, $cma['cma_middle']);
    }

    // ── 7 — graceful fallback for deleted condition ───────────────────

    public function test_resolver_falls_through_when_property_condition_was_deleted(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $custom = PropertySettingItem::create([
            'agency_id' => $agencyId, 'group' => 'condition_level', 'name' => 'Custom Slick',
            'sort_order' => 100, 'is_default' => false, 'active' => true,
            'adjustment_pct' => 25.0,
        ]);
        $property = $this->createProperty($agencyId, $user->id, [
            'price' => 1_000_000, 'condition_level_id' => $custom->id,
        ]);
        $version = $this->seedPresentationWithVersion($agencyId, $user->id, $property);

        // Soft-delete the condition row.
        $custom->delete();

        $resolver = app(ConditionAdjustmentService::class);
        // Resolver loads presentation via the version's relationship,
        // which in turn loads the property fresh from DB — soft-deleted
        // condition_level row gets filtered.
        $resolved = $resolver->resolveLive($version->fresh());
        // Soft-deleted level falls through to 'none' (no PDF surprise).
        $this->assertNull($resolved['level']);
        $this->assertSame('none', $resolved['source']);
    }

    // ── 8 — multi-tenancy ────────────────────────────────────────────

    public function test_set_condition_rejects_foreign_agency_level_id(): void
    {
        [$agencyA, $userA] = $this->seedAgencyAndUser();
        [$agencyB]         = $this->seedAgencyAndUser();
        $foreignLevel = PropertySettingItem::withoutGlobalScopes()
            ->where('agency_id', $agencyB)->where('name', 'Excellent')->first();

        $property = $this->createProperty($agencyA, $userA->id, ['price' => 1_000_000]);
        $version = $this->seedPresentationWithVersion($agencyA, $userA->id, $property);

        $this->actingAs($userA)
            ->post(route('presentations.review.condition', $version->id), [
                'condition_level_id' => $foreignLevel->id,
            ])
            ->assertStatus(422);

        $this->assertNull($version->fresh()->condition_level_id);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** @return array{0:int,1:User} */
    private function seedAgencyAndUser(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Build 3 seeder runs on real agencies only; RefreshDatabase
        // rebuilds from the schema snapshot which doesn't replay the
        // condition-level seed. Insert defaults inline.
        $defaults = [
            ['name' => 'To Remodel',  'pct' => -30.00, 'sort' => 0],
            ['name' => 'To Renovate', 'pct' => -15.00, 'sort' => 1],
            ['name' => 'Average',     'pct' =>   0.00, 'sort' => 2],
            ['name' => 'Good',        'pct' =>   3.00, 'sort' => 3],
            ['name' => 'Very Good',   'pct' =>  12.00, 'sort' => 4],
            ['name' => 'Excellent',   'pct' =>  20.00, 'sort' => 5],
            ['name' => 'Exceptional', 'pct' =>  38.00, 'sort' => 6],
        ];
        foreach ($defaults as $row) {
            DB::table('property_setting_items')->insert([
                'agency_id'      => $agencyId,
                'group'          => 'condition_level',
                'name'           => $row['name'],
                'sort_order'     => $row['sort'],
                'is_default'     => 1,
                'active'         => 1,
                'adjustment_pct' => $row['pct'],
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);
        return [$agencyId, $user];
    }

    private function seedPresentationWithVersion(int $agencyId, int $userId, Property $property, array $versionOverrides = []): PresentationVersion
    {
        $presentation = Presentation::create([
            'agency_id'          => $agencyId,
            'branch_id'          => $agencyId,
            'property_id'        => $property->id,
            'created_by_user_id' => $userId,
            'title'              => 'Condition Test',
            'property_address'   => '1 Test Avenue',
            'suburb'             => 'Testville',
            'property_type'      => 'house',
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);
        return PresentationVersion::create(array_merge([
            'agency_id'         => $agencyId,
            'presentation_id'   => $presentation->id,
            'compiled_by'       => $userId,
            'blueprint_version' => 'v1',
            'data_snapshot_json'=> json_encode(['sections' => []]),
            'compiled_at'       => now(),
            'review_status'     => PresentationVersion::REVIEW_AWAITING,
            'awaiting_review_at'=> now(),
        ], $versionOverrides));
    }

    private function createProperty(int $agencyId, int $userId, array $overrides = []): Property
    {
        return Property::create(array_merge([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'agent_id'  => $userId,
            'title'     => 'Test Property',
        ], $overrides));
    }

    private function seedCmaFields(int $presentationId, int $agencyId, int $lower, int $middle, int $upper): void
    {
        foreach ([
            'cma.lower_range'  => $lower,
            'cma.middle_range' => $middle,
            'cma.upper_range'  => $upper,
        ] as $key => $value) {
            PresentationField::create([
                'agency_id'       => $agencyId,
                'presentation_id' => $presentationId,
                'field_key'       => $key,
                'final_value'     => (string) $value,
            ]);
        }
    }

    /**
     * Build 8 — seed PresentationSoldComp rows so the CMA compute
     * pool produces deterministic percentiles. Prices are sorted in
     * place; with N=5 the type-7 percentile formula maps:
     *   p25 → sorted[1], median → sorted[2], p75 → sorted[3].
     * All comps get a recent sold_date so the default 36-month
     * recency cut in CmaComputeService accepts the full set.
     */
    private function seedSoldCompsForPercentiles(int $presentationId, int $agencyId, array $prices): void
    {
        sort($prices);
        foreach ($prices as $i => $price) {
            \App\Models\PresentationSoldComp::create([
                'agency_id'       => $agencyId,
                'presentation_id' => $presentationId,
                'sold_date'       => now()->subMonths(1)->subDays($i)->toDateString(),
                'sold_price_inc'  => $price,
                'suburb'          => 'Testville',
                'property_type'   => 'house',
                'beds'            => 3,
                'size_m2'         => 150,
                'raw_row_json'    => json_encode(['address' => 'Test ' . ($i + 1)]),
                'parser_version'  => 'test',
            ]);
        }
    }
}
