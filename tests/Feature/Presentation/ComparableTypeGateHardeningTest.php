<?php

namespace Tests\Feature\Presentation;

use App\Models\Presentation;
use App\Models\PresentationSoldComp;
use App\Models\PresentationVersion;
use App\Models\Property;
use App\Models\User;
use App\Services\Presentations\AnalysisDataService;
use App\Services\Presentations\CompetitorStockMatchService;
use App\Services\Presentations\CompPoolBuilder;
use App\Services\Presentations\MicSnapshotHydrator;
use App\Services\TitleTypeClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Code-gate hardening (2026-08) — the "1 Uvonique" apartment-shows-house-
 * comparables bug. Root cause: properties.title_type is a CACHE that only
 * self-heals on save; three independent selectors (CompetitorStockMatchService,
 * MicSnapshotHydrator, CompPoolBuilder) trusted a non-null stored value
 * unconditionally instead of re-validating it, so a stale/mis-stamped column
 * (96% of the live book, per the root-cause investigation) silently drove
 * every comp-selection gate forever, regardless of how correct the live
 * classifier itself was. This suite proves each gate now re-derives fresh
 * and can no longer be fooled by a stale stored value, on disposable
 * RefreshDatabase data only — no QA1/live property data is touched.
 */
class ComparableTypeGateHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function invokePrivate(object $instance, string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod($instance, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($instance, $args);
    }

    // ── Item 4 — unified keyword taxonomy ───────────────────────────────

    public function test_penthouse_studio_simplex_cluster_maisonette_all_classify_sectional(): void
    {
        $svc = new TitleTypeClassifier();
        foreach (['Penthouse', 'Studio Apartment', 'Simplex', 'Cluster Home', 'Maisonette'] as $type) {
            $this->assertSame(
                TitleTypeClassifier::TITLE_SECTIONAL,
                $svc->fromPropertyType($type),
                "'{$type}' must classify sectional_title — this is the classifier-gap guard from the Uvonique investigation"
            );
        }
    }

    public function test_competitor_stock_kind_and_comp_pool_builder_kind_agree_with_classifier_on_gap_keywords(): void
    {
        $competitorSvc = new CompetitorStockMatchService();
        $poolBuilder   = new CompPoolBuilder();

        foreach (['Penthouse', 'Simplex', 'Maisonette', 'Studio', 'Cluster Home'] as $type) {
            $kind1 = $this->invokePrivate($competitorSvc, 'normalizeTypeKind', [$type]);
            $kind2 = $this->invokePrivate($poolBuilder, 'kind', [$type]);
            $this->assertContains($kind1, ['apartment', 'townhouse'], "normalizeTypeKind('{$type}') must not fall through to house/other");
            $this->assertContains($kind2, ['apartment', 'townhouse'], "CompPoolBuilder::kind('{$type}') must not fall through to house/other");
        }
    }

    // ── Item 1 — the two drifting selectors no longer trust a stale column ─

    public function test_mic_hydrator_resolve_config_ignores_a_stale_wrong_stored_title_type(): void
    {
        $agencyId = $this->seedAgency();
        // The Uvonique shape exactly: property_type is a real apartment, but
        // the STORED title_type column is deliberately wrong (full_title).
        $propertyId = $this->seedProperty($agencyId, 'Apartment / Flat', 'full_title');

        $presentation = Presentation::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'created_by_user_id' => User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId])->id,
            'property_id' => $propertyId, 'title' => 'T',
            'property_address' => '1 Uvonique Test', 'suburb' => 'Manaba Beach',
            'property_type' => 'Apartment / Flat', 'status' => 'draft', 'currency' => 'ZAR',
        ]);
        $presentation->load('property');

        $cfg = $this->invokePrivate(new MicSnapshotHydrator(), 'resolveConfig', [$presentation]);

        $this->assertSame(
            TitleTypeClassifier::TITLE_SECTIONAL,
            $cfg['title_type'],
            'resolveConfig() must re-derive fresh from property_type, not trust the stale stored title_type=full_title'
        );
    }

    public function test_competitor_stock_match_service_ignores_a_stale_wrong_stored_title_type(): void
    {
        $agencyId = $this->seedAgency();
        $propertyId = $this->seedProperty($agencyId, 'Apartment / Flat', 'full_title');
        $property = Property::find($propertyId);

        $criteria = (new CompetitorStockMatchService())->buildCriteria($property);

        $this->assertNotNull($criteria);
        $this->assertSame(
            'sectional',
            $criteria['family'],
            'buildCriteria() must re-derive the family fresh, not trust the stale stored title_type=full_title'
        );
    }

    public function test_comp_pool_builder_category_prefers_fresh_property_type_over_a_stale_passed_in_title_type(): void
    {
        $builder = new CompPoolBuilder();
        $classifier = app(TitleTypeClassifier::class);

        // A caller passes a WRONG title_type (full_title) alongside a
        // property_type that plainly classifies sectional. The gate must
        // trust the fresh classification, not the stale value handed to it.
        $category = $this->invokePrivate($builder, 'category', [$classifier, 'full_title', 'Apartment / Flat']);
        $this->assertSame(TitleTypeClassifier::TITLE_SECTIONAL, $category);

        // Sanity: when property_type truly can't classify, the passed-in
        // value is still honoured as a fallback (not simply ignored).
        $category2 = $this->invokePrivate($builder, 'category', [$classifier, 'vacant_land', null]);
        $this->assertSame('vacant_land', $category2);
    }

    // ── Item 2 — deal-register comps: hard gate before the soft exemption ──

    public function test_deal_register_comps_exclude_a_house_deal_for_a_sectional_subject(): void
    {
        $agencyId = $this->seedAgency();
        $propertyId = $this->seedProperty($agencyId, 'Apartment / Flat', 'sectional_title', 'Manaba Beach');
        $presentation = $this->makePresentation($agencyId, $propertyId, 'Apartment / Flat', 'Manaba Beach');

        $this->seedRegisteredDeal($agencyId, 'House', 'full_title', 'Manaba Beach', 2_400_000);

        [$added, $skipped] = $this->invokePrivate(new MicSnapshotHydrator(), 'collectAndInsertDealComps', [
            $presentation, $this->cfgFor($presentation),
        ]);

        $this->assertSame(0, $added, 'a house deal must never insert as a comp for a sectional subject');
        $this->assertGreaterThanOrEqual(1, $skipped);
        $this->assertSame(0, PresentationSoldComp::where('presentation_id', $presentation->id)->count());
    }

    public function test_deal_register_comps_still_admit_a_same_type_deal(): void
    {
        $agencyId = $this->seedAgency();
        $propertyId = $this->seedProperty($agencyId, 'Apartment / Flat', 'sectional_title', 'Manaba Beach');
        $presentation = $this->makePresentation($agencyId, $propertyId, 'Apartment / Flat', 'Manaba Beach');

        $this->seedRegisteredDeal($agencyId, 'Apartment / Flat', 'sectional_title', 'Manaba Beach', 1_450_000);

        [$added] = $this->invokePrivate(new MicSnapshotHydrator(), 'collectAndInsertDealComps', [
            $presentation, $this->cfgFor($presentation),
        ]);

        $this->assertSame(1, $added, 'a same-type (apartment) deal must still be admitted');
        $this->assertSame(1, PresentationSoldComp::where('presentation_id', $presentation->id)->count());
    }

    // ── Item 3 — AnalysisDataService hard-excludes cross-type by default ──

    public function test_compile_hard_excludes_a_cross_type_comp_with_no_agent_review_yet(): void
    {
        $agencyId = $this->seedAgency();
        $propertyId = $this->seedProperty($agencyId, 'Apartment / Flat', 'sectional_title', 'Manaba Beach');
        $presentation = $this->makePresentation($agencyId, $propertyId, 'Apartment / Flat', 'Manaba Beach');

        $houseComp = PresentationSoldComp::create([
            'agency_id' => $agencyId, 'presentation_id' => $presentation->id,
            'sold_date' => now()->subMonth(), 'sold_price_inc' => 2_400_000,
            'suburb' => 'Manaba Beach', 'property_type' => 'House',
            'raw_row_json' => json_encode(['source' => 'deal_register', 'address' => '19 Manaba Beach Road']),
            'parser_version' => 'deal_register_v1',
        ]);
        $aptComp = PresentationSoldComp::create([
            'agency_id' => $agencyId, 'presentation_id' => $presentation->id,
            'sold_date' => now()->subMonth(), 'sold_price_inc' => 1_450_000,
            'suburb' => 'Manaba Beach', 'property_type' => 'Apartment / Flat',
            'raw_row_json' => json_encode(['source' => 'deal_register', 'address' => 'Unit 2, Uvonique']),
            'parser_version' => 'deal_register_v1',
        ]);

        // No PresentationVersion — null whitelist ("agent has no opinion yet").
        $data = (new AnalysisDataService())->compile($presentation->fresh(['fields', 'property']));
        $allRows = collect($data['comparable_sales'])->flatMap(fn ($g) => $g['rows'])->pluck('address');

        $this->assertFalse($allRows->contains(fn ($a) => str_contains($a, 'Manaba Beach Road')), 'the house comp must be hard-excluded by default');
        $this->assertTrue($allRows->contains(fn ($a) => str_contains($a, 'Uvonique')), 'the same-type apartment comp must still render');
    }

    public function test_compile_admits_a_cross_type_comp_when_the_agent_explicitly_re_included_it(): void
    {
        $agencyId = $this->seedAgency();
        $propertyId = $this->seedProperty($agencyId, 'Apartment / Flat', 'sectional_title', 'Manaba Beach');
        $presentation = $this->makePresentation($agencyId, $propertyId, 'Apartment / Flat', 'Manaba Beach');

        $houseComp = PresentationSoldComp::create([
            'agency_id' => $agencyId, 'presentation_id' => $presentation->id,
            'sold_date' => now()->subMonth(), 'sold_price_inc' => 2_400_000,
            'suburb' => 'Manaba Beach', 'property_type' => 'House',
            'raw_row_json' => json_encode(['source' => 'deal_register', 'address' => '19 Manaba Beach Road']),
            'parser_version' => 'deal_register_v1',
        ]);

        $version = PresentationVersion::create([
            'agency_id' => $agencyId,
            'presentation_id' => $presentation->id,
            'version_number' => 1,
            'data_snapshot_json' => '{}',
            // The agent's OWN explicit tick — deliberately re-including the
            // cross-type comp despite the badge.
            'included_comp_ids_json' => [$houseComp->id],
        ]);

        $data = (new AnalysisDataService())->compile($presentation->fresh(['fields', 'property']), $version);
        $allRows = collect($data['comparable_sales'])->flatMap(fn ($g) => $g['rows'])->pluck('address');

        $this->assertTrue($allRows->contains(fn ($a) => str_contains($a, 'Manaba Beach Road')), 'an explicitly re-included cross-type comp must render');
    }

    // ── End-to-end — the exact reported symptom, on disposable data ────────

    public function test_end_to_end_apartment_subject_excludes_house_and_includes_apartment_deal_comps(): void
    {
        $agencyId   = $this->seedAgency();
        $propertyId = $this->seedProperty($agencyId, 'Apartment / Flat', 'sectional_title', 'Manaba Beach');
        $presentation = $this->makePresentation($agencyId, $propertyId, 'Apartment / Flat', 'Manaba Beach');

        $this->seedRegisteredDeal($agencyId, 'House', 'full_title', 'Manaba Beach', 2_400_000);
        $this->seedRegisteredDeal($agencyId, 'Apartment / Flat', 'sectional_title', 'Manaba Beach', 1_450_000);

        (new MicSnapshotHydrator())->hydrateForPresentation($presentation->fresh(['property']));

        $comps = PresentationSoldComp::where('presentation_id', $presentation->id)->get();

        $this->assertTrue($comps->every(fn ($c) => $c->property_type !== 'House'), 'no House deal comp must have been materialised for this apartment presentation');
        $this->assertTrue($comps->contains(fn ($c) => $c->property_type === 'Apartment / Flat'), 'the apartment deal comp must have been materialised');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function seedAgency(): int
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'CompGate-Test ' . Str::random(6),
            'slug' => 'cgt-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $agencyId;
    }

    private function seedProperty(int $agencyId, string $propertyType, string $titleType, string $suburb = 'Manaba Beach'): int
    {
        $agentId = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId])->id;
        return (int) DB::table('properties')->insertGetId([
            'external_id' => 'TEST-' . Str::random(8),
            'title' => '1 Test', 'address' => '1 Test', 'suburb' => $suburb,
            'latitude' => -30.84, 'longitude' => 30.39,
            'price' => 1_450_000, 'property_type' => $propertyType, 'category' => 'Residential',
            // Deliberately stamped — proving the gates no longer trust it blindly.
            'title_type' => $titleType,
            'status' => 'active', 'is_demo' => false,
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $agentId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makePresentation(int $agencyId, int $propertyId, string $propertyType, string $suburb): Presentation
    {
        $presentation = Presentation::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'created_by_user_id' => User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId])->id,
            'property_id' => $propertyId, 'title' => 'T',
            'property_address' => '1 Test', 'suburb' => $suburb,
            'property_type' => $propertyType, 'status' => 'draft', 'currency' => 'ZAR',
        ]);
        $presentation->load('property');
        return $presentation;
    }

    /** A registered, closed HFC deal — the exact shape collectAndInsertDealComps queries. */
    private function seedRegisteredDeal(int $agencyId, string $propertyType, string $titleType, string $suburb, int $price): int
    {
        $compPropertyId = $this->seedProperty($agencyId, $propertyType, $titleType, $suburb);
        return (int) DB::table('deals')->insertGetId([
            'agency_id' => $agencyId, 'deal_no' => random_int(100000, 999999),
            'property_id' => $compPropertyId,
            'property_address' => $suburb . ' comp',
            'registration_date' => now()->subMonth()->toDateString(),
            'sale_price' => $price, 'property_value' => $price,
            'accepted_status' => 'R', 'is_demo' => 0,
            'period' => now()->format('Y-m'), 'deal_date' => now()->subMonth()->toDateString(),
            'total_commission' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cfgFor(Presentation $presentation): array
    {
        return $this->invokePrivate(new MicSnapshotHydrator(), 'resolveConfig', [$presentation]);
    }
}
