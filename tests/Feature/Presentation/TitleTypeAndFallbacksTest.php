<?php

namespace Tests\Feature\Presentation;

use App\Models\Presentation;
use App\Models\PresentationField;
use App\Models\PropertySettingItem;
use App\Services\Presentations\AnalysisDataService;
use App\Services\Presentations\MicSnapshotHydrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Build 1 — title_type discipline on comp selection + the four foundational
 * bug fixes shipped in the same commit.
 *
 * Tests:
 *  - MicSnapshotHydrator::classifyCompTitleType bucketing rules
 *  - MicSnapshotHydrator::resolveSubjectTitleType reads from the right
 *    PropertySettingItem row
 *  - AnalysisDataService::compileCmaValuation middle-band fallback when
 *    the source PDF didn't carry the "Middle Range:" label
 *  - Str::humanType macro on the BUG-4 inputs
 */
class TitleTypeAndFallbacksTest extends TestCase
{
    use RefreshDatabase;

    private function invokePrivate(object $instance, string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod($instance, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($instance, $args);
    }

    public function test_classify_comp_title_type_buckets_correctly(): void
    {
        $h = new MicSnapshotHydrator();

        $this->assertSame('full_title',      $this->invokePrivate($h, 'classifyCompTitleType', ['house']));
        $this->assertSame('full_title',      $this->invokePrivate($h, 'classifyCompTitleType', ['House']));
        $this->assertSame('sectional_title', $this->invokePrivate($h, 'classifyCompTitleType', ['sectional']));
        $this->assertSame('sectional_title', $this->invokePrivate($h, 'classifyCompTitleType', ['sectional_title']));
        $this->assertSame('sectional_title', $this->invokePrivate($h, 'classifyCompTitleType', ['Flat']));
        $this->assertSame('sectional_title', $this->invokePrivate($h, 'classifyCompTitleType', ['apartment']));
        $this->assertSame('sectional_title', $this->invokePrivate($h, 'classifyCompTitleType', ['townhouse']));
        $this->assertSame('sectional_title', $this->invokePrivate($h, 'classifyCompTitleType', ['duplex']));
        $this->assertSame('sectional_title', $this->invokePrivate($h, 'classifyCompTitleType', ['unit']));
        $this->assertSame('vacant_land',     $this->invokePrivate($h, 'classifyCompTitleType', ['vacant_land']));
        $this->assertSame('vacant_land',     $this->invokePrivate($h, 'classifyCompTitleType', ['plot']));
        $this->assertSame('vacant_land',     $this->invokePrivate($h, 'classifyCompTitleType', ['Stand']));
        $this->assertSame('other',           $this->invokePrivate($h, 'classifyCompTitleType', [null]));
        $this->assertSame('other',           $this->invokePrivate($h, 'classifyCompTitleType', ['']));
    }

    public function test_resolve_subject_title_type_reads_from_property_setting_item(): void
    {
        // Seed an agency-scoped category row.
        $agencyId = $this->seedAgency();
        DB::table('property_setting_items')->insert([
            'agency_id'  => $agencyId,
            'group'      => 'category',
            'name'       => 'Residential',
            'title_type' => 'full_title',
            'sort_order' => 0,
            'is_default' => true,
            'active'     => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Property + presentation pointing at it.
        $propertyId = $this->seedProperty($agencyId, 'Residential');
        $presentation = Presentation::create([
            'agency_id'         => $agencyId,
            'branch_id'         => $agencyId,
            'created_by_user_id'=> \App\Models\User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId])->id,
            'property_id'       => $propertyId,
            'title'             => 'T',
            'property_address'  => '1 Test',
            'suburb'            => 'Test',
            'property_type'     => 'house',
            'status'            => 'draft',
            'currency'          => 'ZAR',
        ]);
        $presentation->load('property');

        $h = new MicSnapshotHydrator();
        $titleType = $this->invokePrivate($h, 'resolveSubjectTitleType', [$presentation, $agencyId]);
        $this->assertSame('full_title', $titleType);
    }

    public function test_resolve_subject_title_type_returns_null_when_subject_has_no_category(): void
    {
        $agencyId = $this->seedAgency();
        $propertyId = $this->seedProperty($agencyId, null);   // null category
        $presentation = Presentation::create([
            'agency_id'         => $agencyId,
            'branch_id'         => $agencyId,
            'created_by_user_id'=> \App\Models\User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId])->id,
            'property_id'       => $propertyId,
            'title'             => 'T',
            'property_address'  => '1 Test',
            'suburb'            => 'Test',
            'property_type'     => 'house',
            'status'            => 'draft',
            'currency'          => 'ZAR',
        ]);
        $presentation->load('property');

        $h = new MicSnapshotHydrator();
        $this->assertNull($this->invokePrivate($h, 'resolveSubjectTitleType', [$presentation, $agencyId]));
    }

    /**
     * Hotfix regression — Build 1 shipped a call inside resolveConfig()
     * that referenced an undefined $agencyId variable. The bug never
     * surfaced in Build 1–6 tests because every test created
     * PresentationVersion rows directly, bypassing the live generator →
     * MicSnapshotHydrator path. The actual symptom on the property page
     * was a 500 with "Undefined variable $agencyId" — blocking ALL
     * generate clicks. This test invokes the real path and would have
     * thrown the same error before the fix.
     */
    public function test_resolve_config_runs_without_undefined_agency_id_variable(): void
    {
        $agencyId = $this->seedAgency();
        // Subject category needs a matching property_setting_items row
        // so the title_type lookup is exercised, not short-circuited.
        DB::table('property_setting_items')->insert([
            'agency_id'  => $agencyId,
            'group'      => 'category',
            'name'       => 'Residential',
            'title_type' => 'full_title',
            'sort_order' => 0,
            'is_default' => true,
            'active'     => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $propertyId = $this->seedProperty($agencyId, 'Residential');
        $presentation = Presentation::create([
            'agency_id'         => $agencyId,
            'branch_id'         => $agencyId,
            'created_by_user_id'=> \App\Models\User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId])->id,
            'property_id'       => $propertyId,
            'title'             => 'Generate Regression',
            'property_address'  => '1 Test',
            'suburb'            => 'Test',
            'property_type'     => 'house',
            'status'            => 'draft',
            'currency'          => 'ZAR',
        ]);
        $presentation->load('property');

        $h = new MicSnapshotHydrator();
        // Before the fix this threw ErrorException: Undefined variable $agencyId.
        $config = $this->invokePrivate($h, 'resolveConfig', [$presentation]);

        $this->assertIsArray($config);
        $this->assertArrayHasKey('title_type', $config);
        $this->assertSame('full_title', $config['title_type']);
    }

    public function test_cma_middle_fallback_synthesises_midpoint_when_extraction_missed(): void
    {
        // Seed a presentation with cma.lower_range + cma.upper_range only —
        // simulating the BUG-1 scenario where the PDF didn't carry the
        // "Middle Range:" label.
        $agencyId = $this->seedAgency();
        $presentation = Presentation::create([
            'agency_id'         => $agencyId,
            'branch_id'         => $agencyId,
            'created_by_user_id'=> \App\Models\User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId])->id,
            'title'             => 'T',
            'property_address'  => '1 Test',
            'suburb'            => 'Test',
            'property_type'     => 'house',
            'status'            => 'draft',
            'currency'          => 'ZAR',
        ]);

        PresentationField::create([
            'presentation_id' => $presentation->id,
            'field_key'       => 'cma.lower_range',
            'final_value'     => '1580000',
            'source'          => 'extracted',
        ]);
        PresentationField::create([
            'presentation_id' => $presentation->id,
            'field_key'       => 'cma.upper_range',
            'final_value'     => '2220000',
            'source'          => 'extracted',
        ]);
        // NO cma.middle_range row.

        $data = (new AnalysisDataService())->compile($presentation->fresh(['fields']));
        $cma  = $data['cma_valuation'] ?? [];

        $this->assertSame(1_580_000, $cma['cma_lower'],   'lower extracted unchanged');
        $this->assertSame(2_220_000, $cma['cma_upper'],   'upper extracted unchanged');
        $this->assertSame(1_900_000, $cma['cma_middle'],
            'middle synthesised as (lower+upper)/2 — BUG-1 fix');
        $this->assertTrue($cma['cma_middle_from_fallback'] ?? false,
            'cma_middle_from_fallback flag exposed for downstream display');
    }

    public function test_cma_middle_fallback_DOES_NOT_overwrite_extracted_middle(): void
    {
        $agencyId = $this->seedAgency();
        $presentation = Presentation::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'created_by_user_id' => \App\Models\User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId])->id,
            'title' => 'T',
            'property_address' => '1 Test', 'suburb' => 'Test',
            'property_type' => 'house', 'status' => 'draft', 'currency' => 'ZAR',
        ]);

        // Extracted middle is NOT the midpoint of lower+upper — the source
        // CMA author entered a different value, and the fallback must
        // honour that.
        PresentationField::create(['presentation_id' => $presentation->id, 'field_key' => 'cma.lower_range', 'final_value' => '1000000', 'source' => 'extracted']);
        PresentationField::create(['presentation_id' => $presentation->id, 'field_key' => 'cma.middle_range', 'final_value' => '1300000', 'source' => 'extracted']);
        PresentationField::create(['presentation_id' => $presentation->id, 'field_key' => 'cma.upper_range', 'final_value' => '2000000', 'source' => 'extracted']);

        $data = (new AnalysisDataService())->compile($presentation->fresh(['fields']));
        $cma  = $data['cma_valuation'] ?? [];

        $this->assertSame(1_300_000, $cma['cma_middle'],
            'extracted middle is the source of truth — fallback must not run');
        $this->assertFalse($cma['cma_middle_from_fallback'] ?? false);
    }

    public function test_str_human_type_macro_humanises_property_type_strings(): void
    {
        $this->assertSame('Vacant Land',     Str::humanType('vacant_land'));
        $this->assertSame('Sectional Title', Str::humanType('sectional_title'));
        $this->assertSame('House',           Str::humanType('house'));
        $this->assertSame('Mid Range',       Str::humanType('mid-range'));
        $this->assertSame('—',               Str::humanType(''));
        $this->assertSame('—',               Str::humanType(null));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function seedAgency(): int
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'TitleType-Test ' . Str::random(6),
            'slug' => 'tt-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $agencyId;
    }

    private function seedProperty(int $agencyId, ?string $categoryName): int
    {
        $agentId = \App\Models\User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
        ])->id;
        return (int) DB::table('properties')->insertGetId([
            'external_id'   => 'TEST-' . Str::random(8),
            'title'         => '1 Test',
            'address'       => '1 Test',
            'suburb'        => 'Test',
            'latitude'      => -30.84,
            'longitude'     => 30.39,
            'price'         => 1_200_000,
            'property_type' => 'house',
            'category'      => $categoryName,
            'status'        => 'active',
            'is_demo'       => false,
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $agentId,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }
}
