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

    public function test_classifier_from_property_type_buckets_correctly(): void
    {
        $svc = new \App\Services\TitleTypeClassifier();

        $this->assertSame('full_title',      $svc->fromPropertyType('house'));
        $this->assertSame('full_title',      $svc->fromPropertyType('House'));
        $this->assertSame('sectional_title', $svc->fromPropertyType('sectional'));
        $this->assertSame('sectional_title', $svc->fromPropertyType('Sectional Title'));
        $this->assertSame('sectional_title', $svc->fromPropertyType('Flat'));
        $this->assertSame('sectional_title', $svc->fromPropertyType('Apartment / Flat'));
        $this->assertSame('sectional_title', $svc->fromPropertyType('townhouse'));
        $this->assertSame('sectional_title', $svc->fromPropertyType('duplex'));
        $this->assertSame('sectional_title', $svc->fromPropertyType('unit'));
        $this->assertSame('vacant_land',     $svc->fromPropertyType('vacant_land'));
        $this->assertSame('vacant_land',     $svc->fromPropertyType('Vacant Land'));
        $this->assertSame('vacant_land',     $svc->fromPropertyType('plot'));
        $this->assertSame('vacant_land',     $svc->fromPropertyType('Stand'));
        // Keystone — blank input now returns null (caller decides). The
        // legacy duplicate bodies returned TITLE_OTHER on blank; we
        // preserve that effective behaviour at the comp-filter call site
        // via a `?? TITLE_OTHER` coercion. See test_blank_comp_type_*.
        $this->assertNull($svc->fromPropertyType(null));
        $this->assertNull($svc->fromPropertyType(''));
    }

    public function test_classifier_from_category_reads_agency_setting_item(): void
    {
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
        $svc = new \App\Services\TitleTypeClassifier();
        $this->assertSame('full_title', $svc->fromCategory($agencyId, 'Residential'));
        $this->assertSame('full_title', $svc->fromCategory($agencyId, 'residential')); // case-insensitive
        $this->assertNull($svc->fromCategory($agencyId, 'NoSuchCategory'));
        $this->assertNull($svc->fromCategory($agencyId, null));
        $this->assertNull($svc->fromCategory($agencyId, ''));
    }

    public function test_classifier_for_property_prefers_property_type_then_category(): void
    {
        $agencyId = $this->seedAgency();
        DB::table('property_setting_items')->insert([
            'agency_id'  => $agencyId,
            'group'      => 'category',
            'name'       => 'Residential',
            'title_type' => 'full_title',  // mismatch with the property_type
            'sort_order' => 0,
            'is_default' => true,
            'active'     => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Property 1: sectional property_type + Residential category.
        // property_type wins — must NOT mis-classify as full_title from
        // category. This is the exact production bug Phase A* identified.
        $propIdSec = $this->seedProperty($agencyId, 'Residential', 'Sectional Title');
        $sectional = \App\Models\Property::find($propIdSec);
        $svc = new \App\Services\TitleTypeClassifier();
        $this->assertSame('sectional_title', $svc->forProperty($sectional));

        // Property 2: blank property_type + Residential category. Falls
        // through to category fallback.
        $propIdFb = $this->seedProperty($agencyId, 'Residential', '');
        $fallback = \App\Models\Property::find($propIdFb);
        $this->assertSame('full_title', $svc->forProperty($fallback));
    }

    public function test_observer_populates_title_type_on_save(): void
    {
        $agencyId = $this->seedAgency();
        // Insert WITHOUT touching the model so the observer fires on save below.
        $rawPropId = $this->seedProperty($agencyId, 'Residential', 'Vacant Land');
        // The seedProperty helper used DB::table — observer didn't fire.
        // The column was set by the migration backfill though, so reset
        // to NULL to prove the observer (not the migration) is doing the work.
        DB::table('properties')->where('id', $rawPropId)->update(['title_type' => null]);

        $p = \App\Models\Property::find($rawPropId);
        $p->touch(); // trigger save → observer
        $this->assertSame('vacant_land', $p->fresh()->title_type);
    }

    public function test_micsnapshot_subject_reads_property_title_type(): void
    {
        $agencyId = $this->seedAgency();
        // No agency category row — so category fallback won't work. The
        // ONLY source of title_type is the property column. Property
        // is a sectional unit (which Build 1's category-only path would
        // have mis-classified as full_title against the empty category).
        $propertyId = $this->seedProperty($agencyId, 'Residential', 'Sectional Title');
        $presentation = Presentation::create([
            'agency_id'         => $agencyId,
            'branch_id'         => $agencyId,
            'created_by_user_id'=> \App\Models\User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId])->id,
            'property_id'       => $propertyId,
            'title'             => 'T',
            'property_address'  => '1 Test',
            'suburb'            => 'Test',
            'property_type'     => 'sectional',
            'status'            => 'draft',
            'currency'          => 'ZAR',
        ]);
        $presentation->load('property');

        $h = new MicSnapshotHydrator();
        $cfg = $this->invokePrivate($h, 'resolveConfig', [$presentation]);
        $this->assertSame('sectional_title', $cfg['title_type']);
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

    /**
     * SS-SECTIONAL-GATE-FIX — the gather-stage type-gate
     * (collectMatchedRows) must classify a comp with the SAME signal-aware
     * derivation the candidate build uses. CMA-Info sectional comps are
     * stamped property_type='Residence' (the PDF usage word) but carry
     * scheme_name/section_number. Before the fix the bare fromPropertyType
     * classified them full_title and dropped every vicinity sectional sale
     * against a sectional subject (the Pumula / property-771 bug). After the
     * fix the section_number signal wins and the comp is retained.
     */
    public function test_gather_retains_sectional_residence_comp_for_sectional_subject(): void
    {
        $agencyId = $this->seedAgency();
        $compId   = $this->seedSectionalResidenceComp($agencyId, 'margate');

        $retained = $this->runGather($agencyId, 'sectional_title', 'margate');

        $this->assertContains($compId, $retained,
            "sectional 'Residence' vicinity comp (section_number set) must survive the "
            . "gather type-gate for a sectional subject — this is the Pumula fix");
    }

    /**
     * Bidirectional half of the same fix (fix-the-class): the SAME sectional
     * 'Residence' comp must now be DROPPED for a FREEHOLD subject. Before the
     * fix it was wrongly classified full_title and admitted into freehold
     * pools — polluting them with sectional sales.
     */
    public function test_gather_drops_sectional_residence_comp_for_freehold_subject(): void
    {
        $agencyId = $this->seedAgency();
        $compId   = $this->seedSectionalResidenceComp($agencyId, 'margate');

        $retained = $this->runGather($agencyId, 'full_title', 'margate');

        $this->assertNotContains($compId, $retained,
            "sectional comp must NOT leak into a freehold subject's pool");
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

    private function seedProperty(int $agencyId, ?string $categoryName, string $propertyType = 'house'): int
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
            'property_type' => $propertyType,
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

    /**
     * A CMA-Info sectional comp as actually stored: property_type='Residence'
     * (the PDF usage word) WITH scheme_name + section_number populated, and
     * market_report_id=null so it is NOT a same-subject (exempt) row — it must
     * pass through the gather type-gate on its own signals.
     */
    private function seedSectionalResidenceComp(int $agencyId, string $suburb): int
    {
        return (int) DB::table('market_report_comp_rows')->insertGetId([
            'agency_id'         => $agencyId,
            'market_report_id'  => null,
            'row_index'         => 1,
            'row_type'          => 'comp',
            'scheme_name'       => 'PUMULA HEIGHTS',
            'section_number'    => '12',
            'ss_number'         => '0345',
            'suburb_normalised' => $suburb,
            'property_type'     => 'Residence',
            'extent_m2'         => 78,
            'sale_date'         => now()->subMonths(2)->toDateString(),
            'sale_price'        => 1_250_000,
            'latitude'          => -30.8468,
            'longitude'         => 30.3764,
            'is_demo'           => 0,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    /**
     * Build a subject presentation of the given title_type + suburb, then run
     * the private gather (collectMatchedRows) with a deterministic cfg and
     * return the ids of the rows it RETAINED. cfg['source_reports'] is empty
     * so no comp is exempt — every row is judged on the type-gate alone.
     *
     * @return array<int, int>
     */
    private function runGather(int $agencyId, string $subjectTitleType, string $suburb): array
    {
        $propertyType = $subjectTitleType === 'sectional_title' ? 'Sectional Title' : 'House';
        $propertyId   = $this->seedProperty($agencyId, 'Residential', $propertyType);
        DB::table('properties')->where('id', $propertyId)->update([
            'suburb' => $suburb, 'title_type' => $subjectTitleType,
            'latitude' => -30.84, 'longitude' => 30.39, 'is_demo' => 0,
        ]);

        $presentation = Presentation::create([
            'agency_id'          => $agencyId,
            'branch_id'          => $agencyId,
            'created_by_user_id' => \App\Models\User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId])->id,
            'property_id'        => $propertyId,
            'title'              => 'Gather',
            'property_address'   => '1 Test',
            'suburb'             => $suburb,
            'property_type'      => $propertyType,
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);
        $presentation->load('property');

        $cfg = [
            'title_type'     => $subjectTitleType,
            'source_reports' => [],
            'suburb_norm'    => $suburb,
            'subject_lat'    => -30.84,
            'subject_lng'    => 30.39,
            'radius_m'       => 1000,
            'scope'          => 'suburb',
            'date_from'      => now()->subYear()->toDateString(),
            'date_to'        => now()->toDateString(),
        ];

        $rows = $this->invokePrivate(new MicSnapshotHydrator(), 'collectMatchedRows', [$presentation, $cfg, 'comp']);

        return $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
