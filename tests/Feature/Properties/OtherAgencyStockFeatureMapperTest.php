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

    public function test_what_has_no_corex_equivalent_is_reported_never_invented(): void
    {
        $all = [];
        foreach (glob(base_path(self::FIXTURES) . '*.payload.json') as $f) {
            $all = array_merge($all, $this->mapFixture(basename($f, '.payload.json'))['unmapped']);
        }
        $all = implode("\n", $all);

        foreach (['Building / Wall', 'Building / Floor', 'Building / Roof', 'Building / Window', 'Building / Style', 'Number of floors', 'No Transfer Duty', 'Lifestyle = Complex'] as $expected) {
            $this->assertStringContainsString($expected, $all, "{$expected} should be listed as having no CoreX equivalent");
        }
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
        $this->assertSame(['global', 'spaces', 'unmapped'], array_keys($m));
        $this->assertSame(['global' => array_fill_keys(Mapper::CATEGORIES, []), 'spaces' => [], 'unmapped' => []], Mapper::map([], []));
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
}
