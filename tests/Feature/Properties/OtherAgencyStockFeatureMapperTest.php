<?php

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\P24City;
use App\Models\P24Country;
use App\Models\P24Province;
use App\Models\P24Suburb;
use App\Models\Property;
use App\Models\User;
use App\Services\Properties\OtherAgencyStockFeatureMapper as Mapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * .ai/specs/other-agency-stock.md §5d — 2026-10-07, P24 listing 117580701 (QA1 property 21181):
 * "Furnished | Yes" in P24's Property Overview was never ticked, and the stored title was P24's
 * generic line instead of the advert's own heading.
 *
 * The payloads are GOLDEN — the real extractor's output over SAVED copies of seven real P24 pages
 * (public/chrome-extension/portal-capture/tests/fixtures/p24). No live scraping.
 */
class OtherAgencyStockFeatureMapperTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURES = 'public/chrome-extension/portal-capture/tests/fixtures/p24/';

    private function golden(string $name): array
    {
        return json_decode(file_get_contents(base_path(self::FIXTURES . $name . '.payload.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function mapFixture(string $name): array
    {
        $d = $this->golden($name);

        return Mapper::map($d['feature_rows'] ?? [], $d['strip_tags'] ?? []);
    }

    /** The property page's own picker, parsed out of show.blade.php (`_FEATURE_CATEGORIES`). @return array<string, string[]> */
    private function pickerFromBlade(): array
    {
        $src = file_get_contents(resource_path('views/corex/properties/show.blade.php'));
        $this->assertSame(1, preg_match('/const _FEATURE_CATEGORIES = \{(.*?)\n\};/s', $src, $block), 'picker constant moved — fix this parser');
        $out = [];
        foreach (Mapper::CATEGORIES as $cat) {
            $this->assertSame(1, preg_match('/' . $cat . ':\s*\{[^}]*features:\s*\[(.*?)\]/s', $block[1], $m), "no {$cat} group in the picker");
            preg_match_all("/'([^']+)'/", $m[1], $labels);
            $out[$cat] = $labels[1];
        }

        return $out;
    }

    // ── The listing Johan re-imports ─────────────────────────────────────────────

    public function test_reference_listing_ticks_furnished_and_the_rest_of_what_p24_says(): void
    {
        $m = $this->mapFixture('townhouse-uvongo-117580701');

        $this->assertContains('Furnished', $m['global']['theProperty'], '"Furnished | Yes" is the row Johan found unticked');
        $this->assertContains('Pets Not Allowed', $m['global']['theProperty'], '"Pets Allowed | No"');
        $this->assertNotContains('Pet Friendly', $m['global']['theProperty']);
        $this->assertEqualsCanonicalizing(['Backup Water', 'Water Tank'], $m['global']['sustainability'], '"Backup Water | Water Tank" (a Building row)');
        $this->assertSame([['type' => 'Reception Room', 'count' => 2]], $m['spaces'], '"Reception Rooms | 2" (a Rooms row)');
    }

    public function test_rows_map_by_meaning_across_the_other_kinds_of_listing(): void
    {
        $house = $this->mapFixture('house-umhlali-golf-estate-117621889');
        $this->assertEqualsCanonicalizing(['Single Storey', 'Pet Friendly', 'Balcony'], $house['global']['theProperty']); // Description / Pets Allowed / Special Feature
        $this->assertEqualsCanonicalizing([['type' => 'Office', 'count' => 1], ['type' => 'Study', 'count' => 1]], $house['spaces']);

        $rental = $this->mapFixture('rental-apartment-ballito-116824433');
        $this->assertContains('Unfurnished', $rental['global']['theProperty'], '"Furnished | No"');
        $this->assertContains('Top Floor', $rental['global']['theProperty']);
        $this->assertContains('Communal Braai Area', $rental['global']['theProperty']);
        $this->assertContains('Fibre', $rental['global']['connectivity']);
        // The Security row's P24 wording -> CoreX's own security list.
        foreach (['Totally Fenced', 'CCTV', 'Armed Response', '24 Hour Access', 'Guard House', '24 Hour Guard', 'Electric Fence', 'Boomed Area', 'Safe', 'Security Complex', 'Security Estate'] as $label) {
            $this->assertContains($label, $rental['global']['security'], $label);
        }

        $commercial = $this->mapFixture('commercial-ballito-central-117324987');
        $this->assertContains('Air Conditioned', $commercial['global']['theProperty'], '"Temperature Control | Air Conditioning Unit"');
        $this->assertContains('Wheelchair Friendly', $commercial['global']['theProperty']);
        $this->assertEqualsCanonicalizing(['Totally Fenced', 'Electric Gate', 'Security Gate'], $commercial['global']['security']);

        $townhouse = $this->mapFixture('townhouse-elaleni-117675379');
        $this->assertSame([], $townhouse['global']['sustainability'], '"Generator | No", "Backup Water | No" tick nothing');
        $this->assertNotContains('Wheelchair Friendly', $townhouse['global']['theProperty']);
        $this->assertNotContains('Standalone', $townhouse['global']['theProperty'], '"Standalone Building | No"');
    }

    /** Johan, 2026-10-08: "all P24 features should be in CoreX" — the building section, floors, lifestyle, transfer duty, rental terms. */
    public function test_the_features_that_had_no_corex_equivalent_now_map(): void
    {
        $apartment = $this->mapFixture('apartment-shakas-rock-117608849');
        $this->assertEqualsCanonicalizing(['Wall: Plaster', 'Floor: Tiled'], $apartment['global']['building']);

        $commercial = $this->mapFixture('commercial-ballito-central-117324987');
        $this->assertEqualsCanonicalizing(['Style: Conventional', 'Roof: Zinc', 'Wall: Plaster', 'Window: Aluminium'], $commercial['global']['building']);
        $this->assertContains('Complex', $commercial['global']['theProperty'], '"Lifestyle | Complex"');
        $this->assertContains('Office Building', $commercial['global']['theProperty'], '"Description | Office"');

        $rental = $this->mapFixture('rental-apartment-ballito-116824433');
        $this->assertEqualsCanonicalizing(
            ['Roof: Aluminium', 'Roof: Waterproofing', 'Roof: Insulation', 'Wall: Brick', 'Wall: Concrete', 'Window: Aluminium', 'Floor: Tiled'],
            $rental['global']['building'], 'comma lists split, "Tiled Floors" -> Floor: Tiled'
        );
        $this->assertEquals(
            ['number_of_floors' => 1, 'floor_number' => '3', 'occupation_date' => '2026-10-01', 'lease_period' => '12 Months'],
            $rental['attributes']
        );

        foreach (['townhouse-elaleni-117675379', 'vacant-land-lalela-117674295'] as $name) {
            $this->assertContains('No Transfer Duty', $this->mapFixture($name)['global']['theProperty'], $name);
        }

        $house = $this->mapFixture('house-umhlali-golf-estate-117621889');
        $this->assertSame(['number_of_floors' => 1], $house['attributes'], '"Number of floors | 1"');

        // Nothing the saved pages state in these sections is left over as "no equivalent".
        $all = [];
        foreach (glob(base_path(self::FIXTURES) . '*.payload.json') as $f) {
            $all = array_merge($all, $this->mapFixture(basename($f, '.payload.json'))['unmapped']);
        }
        $all = implode("\n", $all);
        foreach (['Building /', 'Number of floors', 'Floor Number', 'No Transfer Duty', 'Occupation Date', 'Lease Period', 'Lifestyle = Complex', 'Description = Office'] as $gone) {
            $this->assertStringNotContainsString($gone, $all, "{$gone} should be mapped now");
        }
    }

    public function test_a_building_value_corex_does_not_offer_is_reported_never_invented(): void
    {
        $m = Mapper::map([
            ['s' => 'Building', 'k' => 'Wall', 'v' => ['Papier mache']],
            ['s' => 'Building', 'k' => 'Roof', 'v' => ['Zinc, Glass dome']],
            ['s' => 'Property Overview', 'k' => 'Occupation Date', 'v' => ['Immediately']],
            ['s' => 'Building', 'k' => 'Number of floors', 'v' => ['Ground + 2']],
        ]);

        $this->assertSame(['Roof: Zinc'], $m['global']['building']);
        $this->assertSame([], $m['attributes'], 'an unreadable date / count is never guessed');
        $this->assertEqualsCanonicalizing(
            ['Building / Wall = Papier mache', 'Building / Roof = Glass dome', 'Property Overview / Occupation Date = Immediately', 'Building / Number of floors = Ground + 2'],
            $m['unmapped']
        );
    }

    public function test_property24_wording_variants_land_on_the_same_building_labels(): void
    {
        $m = Mapper::map([
            ['s' => 'Building', 'k' => 'Floor', 'v' => ['Tiled Floors, Carpet, Wooden Floors']],
            ['s' => 'Building', 'k' => 'Window', 'v' => ['Aluminum, uPVC, Timber']],
            ['s' => 'Building', 'k' => 'Roof', 'v' => ['Tile']],
            ['s' => 'Building', 'k' => 'Wall', 'v' => ['Brick Wall']],
        ]);

        $this->assertEqualsCanonicalizing(
            ['Floor: Tiled', 'Floor: Carpeted', 'Floor: Wooden', 'Window: Aluminium', 'Window: PVC', 'Window: Wood', 'Roof: Tiles', 'Wall: Brick'],
            $m['global']['building']
        );
    }

    // ── Only CoreX's own features, ever ──────────────────────────────────────────

    public function test_every_label_the_mapper_can_emit_is_one_the_property_page_offers(): void
    {
        $picker = $this->pickerFromBlade();
        $emitted = [];
        foreach (glob(base_path(self::FIXTURES) . '*.payload.json') as $f) {
            foreach ($this->mapFixture(basename($f, '.payload.json'))['global'] as $cat => $labels) {
                foreach ($labels as $l) {
                    $emitted[$cat][$l] = true;
                }
            }
        }
        // Plus every fixed target in the tables (they may not all appear on the saved pages).
        $ref = new \ReflectionClass(Mapper::class);
        foreach (['ALIASES', 'FLAGS'] as $const) {
            foreach ($ref->getConstant($const) as [$cat, $label]) {
                $emitted[$cat][$label] = true;
            }
        }
        foreach ([['theProperty', 'Furnished'], ['theProperty', 'Unfurnished'], ['theProperty', 'Pet Friendly'], ['theProperty', 'Pets Not Allowed'], ['sustainability', 'Backup Water']] as [$cat, $label]) {
            $emitted[$cat][$label] = true;
        }

        foreach ($emitted as $cat => $labels) {
            foreach (array_keys($labels) as $label) {
                $this->assertContains($label, $picker[$cat], "'{$label}' ({$cat}) is not in the property page's picker — it would never show as ticked");
            }
        }
    }

    public function test_picker_and_config_mirror_have_not_drifted_further(): void
    {
        $picker = $this->pickerFromBlade();
        $pickerOnly = (new \ReflectionClass(Mapper::class))->getConstant('PICKER_ONLY');

        foreach (Mapper::CATEGORIES as $cat) {
            $config = (array) config("property-spaces.feature_categories.{$cat}.features");
            // Everything config/property-spaces.php lists is in the picker …
            $this->assertSame([], array_values(array_diff($config, $picker[$cat])), "config lists a {$cat} feature the page does not offer");
            // … and the ONLY picker entries missing from config are the ones named here. If config is ever
            // synced, delete them from PICKER_ONLY (this fails to remind you).
            $this->assertEqualsCanonicalizing($pickerOnly[$cat] ?? [], array_values(array_diff($picker[$cat], $config)), "{$cat}: picker/config drift changed — update OtherAgencyStockFeatureMapper::PICKER_ONLY");
        }
    }

    public function test_odd_input_never_throws(): void
    {
        $m = Mapper::map(['not a row', ['k' => 'Furnished'], ['k' => 'Furnished', 'v' => 'Yes'], ['s' => ['x'], 'k' => ['y'], 'v' => [[1]]], null], ['', 7, 'Furnished']);
        $this->assertContains('Furnished', $m['global']['theProperty']);
        $this->assertSame(['global', 'spaces', 'attributes', 'unmapped'], array_keys($m));
        $this->assertSame(['global' => array_fill_keys(Mapper::CATEGORIES, []), 'spaces' => [], 'attributes' => [], 'unmapped' => []], Mapper::map([], []));
    }

    // ── payload -> stored property, through the real endpoint ────────────────────

    private function agentWithSuburb(): User
    {
        Queue::fake();
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $country = P24Country::create(['p24_id' => 1, 'name' => 'South Africa']);
        $province = P24Province::create(['p24_id' => 2, 'p24_country_id' => $country->id, 'name' => 'KwaZulu Natal']);
        $city = P24City::create(['p24_id' => 361, 'p24_province_id' => $province->id, 'name' => 'Margate']);
        P24Suburb::create(['p24_id' => 6359, 'p24_city_id' => $city->id, 'name' => 'Uvongo', 'slug' => 'uvongo-' . uniqid()]);

        return User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
    }

    private function import(User $agent, array $payload): Property
    {
        unset($payload['_title'], $payload['_expected_photo_count']);
        \Laravel\Sanctum\Sanctum::actingAs($agent);
        $r = $this->postJson('/api/v1/other-agency-stock/import', $payload + ['consent' => true]);
        $r->assertOk()->assertJsonPath('success', true);

        return Property::withoutGlobalScopes()->findOrFail($r->json('property_id'));
    }

    public function test_reference_listing_imports_with_the_advert_heading_and_the_ticks(): void
    {
        $agent = $this->agentWithSuburb();
        $p = $this->import($agent, $this->golden('townhouse-uvongo-117580701'));

        $this->assertSame('Beautifully situated Coastal Property with sea views', $p->title);

        $features = $p->spaces_json['features'];
        $this->assertEqualsCanonicalizing(['Furnished', 'Pets Not Allowed'], $features['theProperty']);
        $this->assertEqualsCanonicalizing(['Backup Water', 'Water Tank'], $features['sustainability']);
        $this->assertSame([], $features['security']);
        $this->assertFalse((bool) $p->pet_friendly);

        $types = collect($p->spaces_json['spaces'])->keyBy('type');
        $this->assertSame(2, $types['Reception Room']['count']);
        foreach (['Bedroom', 'Bathroom', 'Garage', 'Garden', 'Pool'] as $t) {
            $this->assertTrue($types->has($t), "{$t} space survives");
        }

        // The flat mirror the page recomputes on every save is already identical, so a later save is not an "edit".
        $flat = $p->features_json;
        $this->assertEqualsCanonicalizing(Mapper::flatFeatures($p->spaces_json), $flat);
        $this->assertContains('Furnished', $flat);
    }

    public function test_reimport_replaces_the_ticks_and_an_older_extension_payload_leaves_them_alone(): void
    {
        $agent = $this->agentWithSuburb();
        $payload = $this->golden('townhouse-uvongo-117580701');
        $p = $this->import($agent, $payload);

        // The advert changed: now unfurnished, pets welcome.
        foreach ($payload['feature_rows'] as &$row) {
            if ($row['k'] === 'Furnished') $row['v'] = ['No'];
            if ($row['k'] === 'Pets Allowed') $row['v'] = ['Yes'];
        }
        unset($row);
        $payload['strip_tags'] = ['Pool', 'Garden'];
        $again = $this->import($agent, $payload);
        $this->assertSame($p->id, $again->id, 're-import updates the same property');
        $this->assertEqualsCanonicalizing(['Unfurnished', 'Pet Friendly'], $again->spaces_json['features']['theProperty']);

        // An older extension sends no rows: what is ticked stays ticked.
        unset($payload['feature_rows'], $payload['strip_tags']);
        $old = $this->import($agent, $payload);
        $this->assertEqualsCanonicalizing(['Unfurnished', 'Pet Friendly'], $old->spaces_json['features']['theProperty']);
    }

    public function test_a_malformed_optional_block_never_fails_the_import(): void
    {
        $agent = $this->agentWithSuburb();
        $payload = $this->golden('townhouse-uvongo-117580701');
        $payload['feature_rows'] = ['junk', ['k' => 'Furnished', 'v' => ['Yes']], 42];
        $payload['strip_tags'] = [['nested'], 'Furnished'];

        $p = $this->import($agent, $payload);
        $this->assertContains('Furnished', $p->spaces_json['features']['theProperty']);
    }
    public function test_rental_import_fills_the_building_ticks_and_the_columns(): void
    {
        $agent = $this->agentWithSuburb();
        $p = $this->import($agent, $this->golden('rental-apartment-ballito-116824433'));

        $this->assertEqualsCanonicalizing(
            ['Roof: Aluminium', 'Roof: Waterproofing', 'Roof: Insulation', 'Wall: Brick', 'Wall: Concrete', 'Window: Aluminium', 'Floor: Tiled'],
            $p->spaces_json['features']['building']
        );
        $this->assertContains('Roof: Insulation', $p->features_json, 'the flat mirror carries them too');

        $this->assertSame(1, (int) $p->number_of_floors);
        $this->assertSame('3', $p->floor_number);
        $this->assertSame('2026-10-01', $p->occupation_date->format('Y-m-d'));
        $this->assertSame('12 Months', $p->lease_period);
    }

    public function test_the_new_columns_are_locked_advert_content_but_the_units_own_floor_is_not(): void
    {
        $agent = $this->agentWithSuburb();
        $p = $this->import($agent, $this->golden('rental-apartment-ballito-116824433'));

        // PermissionService allows everything while role_permissions is empty; one grant turns real enforcement on.
        \App\Models\RolePermission::create([
            'role' => 'branch_manager', 'permission_key' => \App\Services\Properties\OtherAgencyStockStatusGate::PERMISSION_KEY,
            'agency_id' => $agent->agency_id,
        ]);

        $this->actingAs($agent);
        foreach (['number_of_floors' => 9, 'lease_period' => '6 Months', 'occupation_date' => '2027-01-01'] as $col => $val) {
            try {
                $p->fresh()->update([$col => $val]);
                $this->fail("{$col} must be locked on Other Agency Stock");
            } catch (\Illuminate\Validation\ValidationException) {
            }
        }

        // The unit's own floor is an internal location field, like unit_number.
        $p->fresh()->update(['floor_number' => '5']);
        $this->assertSame('5', $p->fresh()->floor_number);

        // … and a re-import never overwrites what the agent filled in.
        $again = $this->import($agent, $this->golden('rental-apartment-ballito-116824433'));
        $this->assertSame('5', $again->floor_number);
        $this->assertSame(1, (int) $again->number_of_floors);
    }

    public function test_the_edit_form_offers_number_of_floors_and_the_building_group(): void
    {
        $src = file_get_contents(resource_path('views/corex/properties/show.blade.php'));
        $this->assertStringContainsString('name="number_of_floors"', $src);
        $this->assertStringContainsString("building:       { label: 'Building'", $src);
        $this->assertStringContainsString("building:       (initFeatures && Array.isArray(initFeatures.building))", $src);

        // Mobile mirror: the building ticks survive a mobile save.
        $mobile = file_get_contents(app_path('Http/Controllers/Api/MobilePropertyController.php'));
        $this->assertStringContainsString("'features.building'", $mobile);
        $this->assertStringContainsString("'spaces_json.features.building'", $mobile);
    }
}
