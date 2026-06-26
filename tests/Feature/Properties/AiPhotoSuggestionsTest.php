<?php

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\PropertyImageAnalysis;
use App\Models\User;
use App\Services\AI\PropertyAiSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AI photo-suggestions modal on the property workspace.
 *
 * Covers the token→web-vocabulary mapping, the review-stamping that stops the
 * modal re-appearing, the feature gate, and that the property page renders the
 * modal markup + suggestion payload.
 *
 * Spec: .ai/specs/property-image-recognition.md
 */
class AiPhotoSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $user;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create([
            'name' => 'Coastal Realty', 'slug' => 'coastal-realty',
            'ai_image_recognition_enabled' => true,
        ]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);

        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent',
        ]);

        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->user->id, 'branch_id' => $branch->id,
            'title' => 'Sea-view 3 bed', 'suburb' => 'Uvongo', 'property_type' => 'house',
            'status' => 'active', 'price' => 2495000,
        ]);
    }

    /** Seed a minimal P24 country→province→city→suburb chain; return the suburb id. */
    private function seedP24Suburb(): array
    {
        $countryId  = \DB::table('p24_countries')->insertGetId(['p24_id' => 1, 'name' => 'South Africa', 'created_at' => now(), 'updated_at' => now()]);
        $provinceId = \DB::table('p24_provinces')->insertGetId(['p24_id' => 10, 'p24_country_id' => $countryId, 'name' => 'KwaZulu-Natal', 'created_at' => now(), 'updated_at' => now()]);
        $cityId     = \DB::table('p24_cities')->insertGetId(['p24_id' => 100, 'p24_province_id' => $provinceId, 'name' => 'Margate', 'created_at' => now(), 'updated_at' => now()]);
        $suburbId   = \DB::table('p24_suburbs')->insertGetId(['p24_id' => 1000, 'p24_city_id' => $cityId, 'name' => 'Uvongo', 'slug' => 'uvongo', 'p24_verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return ['province_id' => $provinceId, 'city_id' => $cityId, 'suburb_id' => $suburbId];
    }

    private function analysis(array $features, array $spaces, string $status = 'complete'): PropertyImageAnalysis
    {
        return PropertyImageAnalysis::create([
            'agency_id' => $this->agency->id,
            'property_id' => $this->property->id,
            'image_path' => 'properties/' . $this->property->id . '/x.jpg',
            'status' => $status,
            'detected_features' => $features,
            'detected_spaces' => $spaces,
        ]);
    }

    public function test_tokens_map_to_web_spaces_and_features_and_unmapped_are_dropped(): void
    {
        $this->analysis(
            features: [
                ['token' => 'pool', 'confidence' => 0.9],              // → space Pool
                ['token' => 'air_conditioning', 'confidence' => 0.8],  // → feature Air Conditioned
                ['token' => 'fibre', 'confidence' => 0.7],             // → connectivity Fibre
                ['token' => 'sea_view', 'confidence' => 0.95],         // dropped (no web equivalent)
                ['token' => 'security', 'confidence' => 0.95],         // dropped
            ],
            spaces: [['token' => 'Bedroom', 'confidence' => 0.92]],
        );

        $out = app(PropertyAiSuggestionService::class)->forProperty($this->property);

        $this->assertTrue($out['hasSuggestions']);
        $spaceTypes = array_column($out['spaces'], 'type');
        sort($spaceTypes);
        $this->assertSame(['Bedroom', 'Pool'], $spaceTypes);

        $featureLabels = array_column($out['features'], 'label');
        sort($featureLabels);
        $this->assertSame(['Air Conditioned', 'Fibre'], $featureLabels);

        // sea_view / security never leak into the web vocabulary.
        $this->assertNotContains('sea_view', $featureLabels);
        $this->assertNotContains('security', $featureLabels);

        $ac = collect($out['features'])->firstWhere('label', 'Air Conditioned');
        $this->assertSame('theProperty', $ac['category']);
    }

    public function test_confidence_is_aggregated_max_across_images(): void
    {
        $this->analysis(features: [['token' => 'furnished', 'confidence' => 0.6]], spaces: []);
        $this->analysis(features: [['token' => 'furnished', 'confidence' => 0.88]], spaces: []);

        $out = app(PropertyAiSuggestionService::class)->forProperty($this->property);
        $furnished = collect($out['features'])->firstWhere('label', 'Furnished');
        $this->assertSame(0.88, $furnished['confidence']);
    }

    public function test_reviewed_analyses_are_excluded(): void
    {
        $this->analysis(features: [['token' => 'furnished', 'confidence' => 0.9]], spaces: []);
        $svc = app(PropertyAiSuggestionService::class);

        $this->assertTrue($svc->forProperty($this->property)['hasSuggestions']);

        $stamped = $svc->markReviewed($this->property);
        $this->assertSame(1, $stamped);
        $this->assertFalse($svc->forProperty($this->property)['hasSuggestions']);
    }

    public function test_queued_analyses_do_not_produce_suggestions(): void
    {
        $this->analysis(features: [['token' => 'furnished', 'confidence' => 0.9]], spaces: [], status: 'queued');
        $this->assertFalse(app(PropertyAiSuggestionService::class)->forProperty($this->property)['hasSuggestions']);
    }

    public function test_property_page_renders_modal_and_suggestion_payload(): void
    {
        $this->analysis(features: [['token' => 'pool', 'confidence' => 0.9]], spaces: [['token' => 'Bedroom', 'confidence' => 0.9]]);

        $res = $this->actingAs($this->user)->get(route('corex.properties.show', $this->property));

        $res->assertOk();
        $res->assertSee('AI scanned your photos');     // modal markup rendered
        $res->assertSee('_aiSuggestions');              // payload blob present
        $res->assertSee('"hasSuggestions":true', false);
    }

    public function test_update_with_ai_review_flag_stamps_reviewed(): void
    {
        $analysis = $this->analysis(features: [['token' => 'pool', 'confidence' => 0.9]], spaces: []);

        // update() refuses to save a property with no linked contact.
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->user->branch_id,
            'first_name' => 'Sam', 'last_name' => 'Seller', 'phone' => '0820000000',
            'created_by_user_id' => $this->user->id,
        ]);
        $this->property->contacts()->attach($contact->id, ['role' => 'seller']);
        $p24 = $this->seedP24Suburb();

        $payload = [
            'title' => 'Sea-view 3 bed', 'price' => 2495000, 'suburb' => 'Uvongo',
            'beds' => 3, 'baths' => 2, 'garages' => 1, 'agent_id' => $this->user->id,
            'p24_province_id' => $p24['province_id'], 'p24_city_id' => $p24['city_id'], 'p24_suburb_id' => $p24['suburb_id'],
            'ai_review' => 1,
        ];

        $this->actingAs($this->user)
            ->put(route('corex.properties.update', $this->property), $payload)
            ->assertRedirect(route('corex.properties.show', $this->property))
            ->assertSessionHasNoErrors();

        $this->assertNotNull($analysis->fresh()->reviewed_at);
    }

    public function test_update_without_ai_review_flag_leaves_suggestions_pending(): void
    {
        $analysis = $this->analysis(features: [['token' => 'pool', 'confidence' => 0.9]], spaces: []);

        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->user->branch_id,
            'first_name' => 'Sam', 'last_name' => 'Seller', 'phone' => '0820000001',
            'created_by_user_id' => $this->user->id,
        ]);
        $this->property->contacts()->attach($contact->id, ['role' => 'seller']);
        $p24 = $this->seedP24Suburb();

        $this->actingAs($this->user)->put(route('corex.properties.update', $this->property), [
            'title' => 'Sea-view 3 bed', 'price' => 2495000, 'suburb' => 'Uvongo',
            'beds' => 3, 'baths' => 2, 'garages' => 1, 'agent_id' => $this->user->id,
            'p24_province_id' => $p24['province_id'], 'p24_city_id' => $p24['city_id'], 'p24_suburb_id' => $p24['suburb_id'],
        ])->assertRedirect(route('corex.properties.show', $this->property))
          ->assertSessionHasNoErrors();

        $this->assertNull($analysis->fresh()->reviewed_at);
    }
}
