<?php

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\Properties\OtherAgencyStockFieldMapper;
use App\Services\Properties\OtherAgencyStockImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * .ai/specs/other-agency-stock.md §3/§4 — 2026-09-30 field audit (Johan,
 * property #21098, Norkem Park, https://www.property24.com/for-sale/
 * norkem-park/kempton-park/gauteng/1344/117424272). P24's Property
 * Overview table (.p24_propertyOverviewRow) carries Levies, Rates and
 * Taxes, Listing Date, Pets Allowed, Zoning, Parking, Pool, Kitchen,
 * Garden and Security — all confirmed live via a real fetch of the page,
 * none of which the extension was extracting before this fix. Fixture
 * values below are the REAL row values captured live off that listing,
 * not invented examples.
 */
class OtherAgencyStockFieldAuditTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
    }

    // ── OtherAgencyStockFieldMapper::parseCurrency() ────────────────────

    public function test_parse_currency_strips_the_r_and_space_separated_thousands(): void
    {
        // Real live values, plain ASCII spaces (confirmed via hex dump).
        $this->assertSame(1800, OtherAgencyStockFieldMapper::parseCurrency('R 1 800'));
        $this->assertSame(450, OtherAgencyStockFieldMapper::parseCurrency('R 450'));
    }

    public function test_parse_currency_handles_other_thousands_separator_styles(): void
    {
        $this->assertSame(1800, OtherAgencyStockFieldMapper::parseCurrency('R1,800'));
        $this->assertSame(1800, OtherAgencyStockFieldMapper::parseCurrency('R 1 800.00'));
    }

    public function test_parse_currency_returns_null_not_zero_when_absent(): void
    {
        $this->assertNull(OtherAgencyStockFieldMapper::parseCurrency(null));
        $this->assertNull(OtherAgencyStockFieldMapper::parseCurrency(''));
        $this->assertNull(OtherAgencyStockFieldMapper::parseCurrency('   '));
    }

    public function test_parse_currency_a_genuine_zero_levy_is_zero_not_null(): void
    {
        $this->assertSame(0, OtherAgencyStockFieldMapper::parseCurrency('R 0'));
    }

    // ── OtherAgencyStockFieldMapper::mapZoning() ────────────────────────

    public function test_map_zoning_general_residential_maps_to_the_residential_dropdown_option(): void
    {
        // Real live value — storing it verbatim would show as "-- None --"
        // in the zone_type dropdown forever (Residential/Commercial/
        // Industrial/Agricultural/Mixed Use are the only valid options).
        $this->assertSame('Residential', OtherAgencyStockFieldMapper::mapZoning('General Residential'));
    }

    public function test_map_zoning_exact_option_match_wins_outright(): void
    {
        $this->assertSame('Commercial', OtherAgencyStockFieldMapper::mapZoning('Commercial'));
    }

    public function test_map_zoning_keyword_matches(): void
    {
        $this->assertSame('Commercial', OtherAgencyStockFieldMapper::mapZoning('Business Zone'));
        $this->assertSame('Agricultural', OtherAgencyStockFieldMapper::mapZoning('Agricultural Holding'));
        $this->assertSame('Industrial', OtherAgencyStockFieldMapper::mapZoning('Heavy Industrial'));
    }

    public function test_map_zoning_unrecognised_value_maps_to_null_not_a_guess(): void
    {
        $this->assertNull(OtherAgencyStockFieldMapper::mapZoning('Special Use Overlay Zone 7'));
        $this->assertNull(OtherAgencyStockFieldMapper::mapZoning(null));
    }

    // ── OtherAgencyStockFieldMapper::buildSpacesJson() ──────────────────

    public function test_build_spaces_json_norkem_park_fixture(): void
    {
        $spaces = OtherAgencyStockFieldMapper::buildSpacesJson([
            'parking_count'     => 1,
            'pool'              => true,
            'kitchen_features'  => ['BIC', 'Breakfast nook', 'Double sink', 'Electric stove'],
            'garden_features'   => ['Neat garden', 'Build-in braai', 'Pallisade fence', 'Private entrance'],
            'security_features' => ['Electric entrance gate', 'Electric fence around the complex walls'],
        ]);

        $byType = collect($spaces['spaces'])->keyBy('type');

        $this->assertSame(1, $byType['Parking']['count']);
        $this->assertSame(1, $byType['Pool']['count']);
        $this->assertSame(1, $byType['Kitchen']['count']);
        $this->assertSame(['BIC', 'Breakfast nook', 'Double sink', 'Electric stove'], $byType['Kitchen']['units'][0]['features']);
        $this->assertSame(1, $byType['Garden']['count']);
        $this->assertSame(['Neat garden', 'Build-in braai', 'Pallisade fence', 'Private entrance'], $byType['Garden']['units'][0]['features']);
        $this->assertSame(['Electric entrance gate', 'Electric fence around the complex walls'], $spaces['features']['security']);

        // Bedroom/Bathroom/Garage are deliberately NOT written — those
        // tiles already read the dedicated beds/baths/garages columns.
        $this->assertArrayNotHasKey('Bedroom', $byType);
        $this->assertArrayNotHasKey('Bathroom', $byType);
        $this->assertArrayNotHasKey('Garage', $byType);
    }

    public function test_build_spaces_json_every_signal_absent_returns_empty_shape_not_an_error(): void
    {
        $spaces = OtherAgencyStockFieldMapper::buildSpacesJson([]);

        $this->assertSame([], $spaces['spaces']);
        $this->assertSame([], $spaces['features']['security']);
    }

    // ── Full import — the real Norkem Park overview-row payload ─────────

    public function test_norkem_park_import_sets_every_audited_field(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $payload = [
            'portal' => 'p24', 'listing_ref' => '117424272',
            'listing_url' => 'https://www.property24.com/for-sale/norkem-park/kempton-park/gauteng/1344/117424272',
            'consent' => true,
            'listing_title' => '2 Bedroom Townhouse for sale in Norkem Park',
            'suburb' => 'Norkem Park',
            'price' => 740000,
            'beds' => 2, 'baths' => 1,
            // Real live overview-row values (unparsed — the mapper does that).
            'levy'              => 'R 1 800',
            'rates_taxes'       => 'R 450',
            'date_posted'       => '17 July 2026',
            'pets_allowed'      => true,
            'zone_type_raw'     => 'General Residential',
            'parking_count'     => 1,
            'pool'              => true,
            'kitchen_features'  => ['BIC', 'Breakfast nook', 'Double sink', 'Electric stove'],
            'garden_features'   => ['Neat garden', 'Build-in braai', 'Pallisade fence', 'Private entrance'],
            'security_features' => ['Electric entrance gate', 'Electric fence around the complex walls'],
        ];

        $property = app(OtherAgencyStockImportService::class)->import($payload, $this->agent);

        $this->assertSame(1800, $property->levy);
        $this->assertSame(450, $property->rates_taxes);
        $this->assertSame('2026-07-17', $property->listed_date->toDateString());
        $this->assertTrue((bool) $property->pet_friendly);
        $this->assertSame('Residential', $property->zone_type);

        $spaces = $property->spaces_json;
        $byType = collect($spaces['spaces'])->keyBy('type');
        $this->assertSame(1, $byType['Parking']['count']);
        $this->assertSame(1, $byType['Pool']['count']);
        $this->assertSame(['BIC', 'Breakfast nook', 'Double sink', 'Electric stove'], $byType['Kitchen']['units'][0]['features']);
        $this->assertSame(['Electric entrance gate', 'Electric fence around the complex walls'], $spaces['features']['security']);

        // Core fields already working must stay working — this fix must
        // never regress what already worked.
        $this->assertSame(740000.0, (float) $property->price);
        $this->assertSame(2, $property->beds);
        $this->assertSame(1, (int) $property->baths);
    }

    /**
     * BUILD_STANDARD.md §5 — each optional field omitted individually. A
     * listing missing every one of these overview rows (a lean/incomplete
     * P24 listing) must still import cleanly with them left null, never a
     * partial failure or a 500 over one missing row.
     */
    public function test_import_with_none_of_the_audited_fields_present_still_succeeds(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $payload = [
            'portal' => 'p24', 'listing_ref' => '999999',
            'listing_url' => 'https://www.property24.com/for-sale/x/999999',
            'consent' => true, 'suburb' => 'Testville',
            'price' => 500000, 'beds' => 2, 'baths' => 1,
        ];

        $property = app(OtherAgencyStockImportService::class)->import($payload, $this->agent);

        $this->assertNull($property->levy);
        $this->assertNull($property->rates_taxes);
        $this->assertNull($property->zone_type);
        $this->assertNull($property->pet_friendly);
        $this->assertSame([], $property->spaces_json['spaces'] ?? []);
    }

    public function test_import_with_unmappable_zoning_leaves_zone_type_null_not_a_guess(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $payload = [
            'portal' => 'p24', 'listing_ref' => '999998',
            'listing_url' => 'https://www.property24.com/for-sale/x/999998',
            'consent' => true, 'suburb' => 'Testville',
            'price' => 500000,
            'zone_type_raw' => 'Special Overlay District 9',
        ];

        $property = app(OtherAgencyStockImportService::class)->import($payload, $this->agent);

        $this->assertNull($property->zone_type);
    }

    /**
     * The task's explicit "so Pull and OAS both benefit" requirement — the
     * SAME shared mapper wired into the legacy own-stock pull endpoint.
     */
    public function test_pull_from_portal_also_maps_the_audited_fields(): void
    {
        $token = $this->agent->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/properties/pull-from-portal', [
                'title' => '2 Bedroom Townhouse for sale in Norkem Park',
                'price' => 740000,
                'beds' => 2, 'baths' => 1,
                'levy' => 'R 1 800',
                'rates_taxes' => 'R 450',
                'date_posted' => '17 July 2026',
                'pets_allowed' => true,
                'zone_type_raw' => 'General Residential',
                'parking_count' => 1,
                'pool' => true,
                'kitchen_features' => ['BIC', 'Breakfast nook'],
                'garden_features' => ['Neat garden'],
                'security_features' => ['Electric entrance gate'],
            ])
            ->assertOk();

        $property = Property::withoutGlobalScopes()->findOrFail($response->json('property_id'));

        $this->assertSame(1800, $property->levy);
        $this->assertSame(450, $property->rates_taxes);
        $this->assertSame('2026-07-17', $property->listed_date->toDateString());
        $this->assertTrue((bool) $property->pet_friendly);
        $this->assertSame('Residential', $property->zone_type);
        $byType = collect($property->spaces_json['spaces'])->keyBy('type');
        $this->assertSame(1, $byType['Parking']['count']);
        $this->assertSame(1, $byType['Pool']['count']);
    }

    // ── The "46 photos" badge over-count fix ────────────────────────────

    public function test_property_syndication_images_used_for_display_never_over_counts_like_all_images(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $payload = [
            'portal' => 'p24', 'listing_ref' => '117424272',
            'listing_url' => 'https://www.property24.com/for-sale/norkem-park/kempton-park/gauteng/1344/117424272',
            'consent' => true, 'suburb' => 'Norkem Park', 'price' => 740000,
        ];

        $property = app(OtherAgencyStockImportService::class)->import($payload, $this->agent);
        $property->allowOtherAgencyStockContentWrite = true;
        $property->gallery_images_json = array_map(fn ($i) => "https://example.test/gallery/{$i}.jpg", range(1, 23));
        // images_json (a divergent public/website mirror) is the OTHER half
        // of the over-count allImages() merges in — populate it distinctly
        // so a test that accidentally used allImages() here would fail.
        $property->images_json = array_map(fn ($i) => "https://example.test/legacy/{$i}.jpg", range(1, 23));
        $property->saveQuietly();

        $this->assertCount(23, $property->fresh()->syndicationImages());
        $this->assertGreaterThan(23, count($property->fresh()->allImages()), 'allImages() is expected to over-count here — that IS the bug this fix avoids displaying.');
    }
}
