<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation;

use App\Models\Presentation;
use App\Models\PresentationSoldComp;
use App\Models\PresentationVersion;
use App\Models\Property;
use App\Models\User;
use App\Services\TitleTypeClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Build 7 follow-up — five fixes that fall out of the keystone:
 *   F1 — sectional consumers read title_type, not stripos(property_type).
 *   F2 — review + analysis-data-review + PDF size row switches by
 *        title_type: sectional → Floor area + floor_area_m2; else
 *        Erf size + erf_size_m2.
 *   F3 — review-screen cross-flag honours subject_match_used.
 *   F4 — Property::buildDisplayAddress() does not double-concat the
 *        legacy `address` column when structured parts (unit_number,
 *        complex_name, street_name) already supplied content.
 *   F5 — category renders the agency's configured label (proper-case),
 *        not the raw lowercase column.
 */
final class Build7FollowupTest extends TestCase
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

    // ── F1: sectional consumers read title_type ─────────────────────

    public function test_analysis_data_isSectional_reads_title_type(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $property = $this->createSectional($agencyId, $user->id); // title_type=sectional_title
        // Set normalised presentation property_type to 'other' — what
        // happens when the legacy normaliser runs on "Sectional Title".
        $presentation = Presentation::create([
            'agency_id'         => $agencyId,
            'branch_id'         => $agencyId,
            'created_by_user_id'=> $user->id,
            'property_id'       => $property->id,
            'title'             => 'F1 test',
            'property_address'  => '1 Test',
            'suburb'            => 'Test',
            'property_type'     => 'other',
            'asking_price_inc'  => 1_500_000,
            'floor_area_m2'     => 100,
            'status'            => 'draft',
            'currency'          => 'ZAR',
        ]);
        $analysis = (new \App\Services\Presentations\AnalysisDataService())
            ->compile($presentation->fresh(['fields', 'soldComps', 'activeListings', 'property']));
        // Despite property_type='other', isSectional is true because
        // properties.title_type='sectional_title'.
        $this->assertTrue($analysis['is_sectional']);
    }

    // ── F2: size row label + value by title_type ────────────────────

    public function test_review_size_row_renders_floor_area_for_sectional_subject(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $property = $this->createSectional($agencyId, $user->id);
        $version = $this->seedPresentationWithVersion($agencyId, $user->id, $property, [
            'floor_area_m2' => 124,
            'erf_size_m2'   => null,
        ]);

        $resp = $this->actingAs($user)
            ->get(route('presentations.review.show', $version->id));
        $resp->assertOk()
            ->assertSee('Floor area', false)
            ->assertSee('124', false)
            ->assertDontSee('>Erf size<', false);
    }

    public function test_review_size_row_renders_erf_size_for_full_title_subject(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $property = $this->createFullTitle($agencyId, $user->id);
        $version = $this->seedPresentationWithVersion($agencyId, $user->id, $property, [
            'erf_size_m2'   => 800,
            'floor_area_m2' => null,
        ]);

        $resp = $this->actingAs($user)
            ->get(route('presentations.review.show', $version->id));
        $resp->assertOk()
            ->assertSee('Erf size', false)
            ->assertSee('800', false)
            ->assertDontSee('>Floor area<', false);
    }

    // ── F3: review-screen cross-flag exemption ───────────────────────

    public function test_review_cross_flag_exempts_subject_match_used_comps(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $property = $this->createSectional($agencyId, $user->id); // sectional_title
        $version = $this->seedPresentationWithVersion($agencyId, $user->id, $property);

        // Two comps: both full_title type, one with subject_match_used=true
        // (analyst-vetted), one without. Subject is sectional → without
        // the exemption both would cross-flag. With the exemption only
        // the non-vetted one cross-flags.
        $compVetted = PresentationSoldComp::create([
            'agency_id'       => $agencyId,
            'presentation_id' => $version->presentation_id,
            'property_type'   => 'House',  // → full_title (cross-type vs sectional)
            'sold_date'       => now()->subMonths(2)->toDateString(),
            'sold_price_inc'  => 1_500_000,
            'suburb'          => 'Testville',
            'size_m2'         => 200,
            'raw_row_json'    => json_encode([
                'address' => '1 Vetted Lane',
                'subject_match_used' => true,
            ]),
            'parser_version'  => 'test',
        ]);
        $compUnvetted = PresentationSoldComp::create([
            'agency_id'       => $agencyId,
            'presentation_id' => $version->presentation_id,
            'property_type'   => 'House',
            'sold_date'       => now()->subMonths(3)->toDateString(),
            'sold_price_inc'  => 1_400_000,
            'suburb'          => 'Testville',
            'size_m2'         => 180,
            'raw_row_json'    => json_encode([
                'address' => '2 Unvetted Lane',
                'subject_match_used' => false,
            ]),
            'parser_version'  => 'test',
        ]);

        $resp = $this->actingAs($user)
            ->get(route('presentations.review.show', $version->id));
        $resp->assertOk();
        $html = $resp->getContent();

        // Vetted comp must render with data-cross-type="0".
        $this->assertMatchesRegularExpression(
            '/data-comp-id="' . $compVetted->id . '"[^>]+data-cross-type="0"/',
            $html,
            'analyst-vetted comp must NOT be cross-flagged',
        );
        // Unvetted comp must render with data-cross-type="1".
        $this->assertMatchesRegularExpression(
            '/data-comp-id="' . $compUnvetted->id . '"[^>]+data-cross-type="1"/',
            $html,
            'non-vetted cross-type comp must still be flagged',
        );
    }

    // ── F4: buildDisplayAddress dedup ────────────────────────────────

    public function test_build_display_address_no_double_concat_when_complex_and_legacy_overlap(): void
    {
        // Mirrors property 909: complex_name + unit_number set, legacy
        // address column carries a stale concat of the same.
        $prop = $this->makeStubProperty([
            'unit_number'  => '17',
            'complex_name' => 'Brock Manor',
            'address'      => 'Brock Manor, 17',
            'suburb'       => 'Manaba Beach',
        ]);
        $this->assertSame(
            'Unit 17, Brock Manor, Manaba Beach',
            $prop->buildDisplayAddress(),
        );
    }

    public function test_build_display_address_full_title_unchanged(): void
    {
        $prop = $this->makeStubProperty([
            'street_number' => '10',
            'street_name'   => 'Jan Bom Street',
            'suburb'        => 'Manaba Beach',
        ]);
        $this->assertSame(
            '10 Jan Bom Street, Manaba Beach',
            $prop->buildDisplayAddress(),
        );
    }

    public function test_build_display_address_falls_back_to_legacy_when_nothing_structured(): void
    {
        $prop = $this->makeStubProperty([
            'address' => '9 Old Lane',
            'suburb'  => 'Manaba Beach',
        ]);
        $this->assertSame(
            '9 Old Lane, Manaba Beach',
            $prop->buildDisplayAddress(),
        );
    }

    public function test_build_display_address_blank_falls_back_to_title(): void
    {
        $prop = $this->makeStubProperty(['title' => 'Brand New Property']);
        $this->assertSame('Brand New Property', $prop->buildDisplayAddress());
    }

    public function test_build_display_address_dedups_adjacent_duplicates(): void
    {
        // Belt-and-braces — even if some future caller manages to put
        // two identical parts side-by-side, dedup collapses them.
        $prop = $this->makeStubProperty([
            'unit_number' => '5',
            'complex_name'=> 'Same Name',
            'street_name' => 'Same Name', // intentional collision
            'suburb'      => 'Suburb',
        ]);
        $addr = $prop->buildDisplayAddress();
        $this->assertSame('Unit 5, Same Name, Suburb', $addr);
    }

    // ── F5: category casing ──────────────────────────────────────────

    public function test_display_category_label_returns_agency_proper_case_name(): void
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Cat-Test ' . Str::random(6),
            'slug' => 'cat-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
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

        $svc = new TitleTypeClassifier();
        $this->assertSame('Residential', $svc->displayCategoryLabel($agencyId, 'residential'));
        $this->assertSame('Residential', $svc->displayCategoryLabel($agencyId, 'Residential'));
        $this->assertSame('Residential', $svc->displayCategoryLabel($agencyId, 'RESIDENTIAL'));
        // Unmatched falls back to Str::title.
        $this->assertSame('Custom Category', $svc->displayCategoryLabel($agencyId, 'custom category'));
        // Blank input → null.
        $this->assertNull($svc->displayCategoryLabel($agencyId, ''));
        $this->assertNull($svc->displayCategoryLabel($agencyId, null));
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** @return array{0:int,1:User} */
    private function seedAgencyAndUser(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'B7-' . Str::random(6),
            'slug' => 'b7-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
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
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);
        return [$agencyId, $user];
    }

    private function createSectional(int $agencyId, int $userId): Property
    {
        return Property::create([
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $userId,
            'title'         => 'Brock Manor 17',
            'property_type' => 'Sectional Title',
            'category'      => 'Residential',
            'suburb'        => 'Testville',
            'size_m2'       => 124,
            'beds'          => 1,
            'baths'         => 1,
            'price'         => 1_900_000,
            'address'       => 'Brock Manor, 17',
            'complex_name'  => 'Brock Manor',
            'unit_number'   => '17',
            'status'        => 'active',
            'listing_type'  => 'sale',
            'latitude'      => -30.84,
            'longitude'     => 30.39,
        ]);
    }

    private function createFullTitle(int $agencyId, int $userId): Property
    {
        return Property::create([
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $userId,
            'title'         => '10 Jan Bom Street',
            'property_type' => 'House',
            'category'      => 'Residential',
            'suburb'        => 'Testville',
            'erf_size_m2'   => 800,
            'beds'          => 3,
            'baths'         => 2,
            'price'         => 2_400_000,
            'street_number' => '10',
            'street_name'   => 'Jan Bom Street',
            'status'        => 'active',
            'listing_type'  => 'sale',
            'latitude'      => -30.84,
            'longitude'     => 30.39,
        ]);
    }

    private function seedPresentationWithVersion(int $agencyId, int $userId, Property $property, array $overrides = []): PresentationVersion
    {
        $presentation = Presentation::create(array_merge([
            'agency_id'          => $agencyId,
            'branch_id'          => $agencyId,
            'property_id'        => $property->id,
            'created_by_user_id' => $userId,
            'title'              => 'B7 Test',
            'property_address'   => '1 Test',
            'suburb'             => 'Testville',
            'property_type'      => 'other',
            'asking_price_inc'   => 1_900_000,
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ], $overrides));
        // The comps are already hydrated from the current property record (AT-27 C1a
        // freshness marker is later than the property's updated_at), so opening the
        // review screen renders it instead of re-hydrating and redirecting once.
        \App\Models\PresentationSnapshot::create([
            'agency_id'            => $agencyId,
            'presentation_id'      => $presentation->id,
            'generated_by_user_id' => $userId,
            'snapshot_json'        => '{}',
            'generated_at'         => now()->addMinute(),
        ]);
        return PresentationVersion::create([
            'agency_id'         => $agencyId,
            'presentation_id'   => $presentation->id,
            'compiled_by'       => $userId,
            'blueprint_version' => 'v1',
            'data_snapshot_json'=> json_encode(['sections' => []]),
            'compiled_at'       => now(),
            'review_status'     => PresentationVersion::REVIEW_AWAITING,
            'awaiting_review_at'=> now(),
        ]);
    }

    /** Build a Property model without persisting (no observer fires). */
    private function makeStubProperty(array $attrs): Property
    {
        return new Property($attrs);
    }
}
