<?php

declare(strict_types=1);

namespace Tests\Feature\Matching;

use App\Models\AgencyContactSettings;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\User;
use App\Services\Matching\MatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Core Match zero-results regression.
 *
 * A wishlist that ticked a must-have feature (e.g. "Pet friendly") returned
 * ZERO properties agency-wide, because MatchingService::score() treated the
 * must-have as a hard gate but its feature matcher compared the wizard's
 * snake_case token ("pet_friendly") by naive exact string against two storage
 * shapes it never matched:
 *   • P24 imports store Title-Case string arrays  ["Pet Friendly", ...]
 *   • CoreX-native listings store snake_case dicts {"pets_allowed": true}
 * So EVERY property scored 0 and was dropped by the 50% display floor.
 *
 * The fix normalises both sides through canonicalFeature() and only hard-fails
 * a must-have against a listing that actually carries structured feature data.
 *
 * Live repro: contact 16289 / match 21 ("Peter's Wishlist") — 0 → 17 results.
 */
final class MustHaveFeatureNormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AgencyContactSettings::clearMinCountableCache();
        Bus::fake();
    }

    public function test_pet_friendly_must_have_matches_p24_title_case_string_array(): void
    {
        [$agencyId, $agent, $suburbId] = $this->fixture();
        // P24 shape: array of Title-Case labels.
        $p = $this->property($agencyId, $agent->id, $suburbId, [
            'price' => 1_800_000, 'beds' => 3,
            'features_json' => ['Pet Friendly', 'Pool', 'Sea View'],
        ]);
        $match = $this->petFriendlyMatch($agencyId, $suburbId);

        $results = app(MatchingService::class)
            ->propertiesForMatch($match, ['agent_id' => null]);

        $this->assertTrue(
            $results->contains(fn (Property $x) => $x->id === $p->id),
            'a "Pet Friendly" P24 listing must satisfy a pet_friendly must-have'
        );
    }

    public function test_pet_friendly_must_have_matches_native_dict_flag(): void
    {
        [$agencyId, $agent, $suburbId] = $this->fixture();
        // CoreX-native shape: snake_case dict with a synonym key.
        $p = $this->property($agencyId, $agent->id, $suburbId, [
            'price' => 1_800_000, 'beds' => 3,
            'features_json' => ['pool' => true, 'pets_allowed' => true],
        ]);
        $match = $this->petFriendlyMatch($agencyId, $suburbId);

        $results = app(MatchingService::class)
            ->propertiesForMatch($match, ['agent_id' => null]);

        $this->assertTrue(
            $results->contains(fn (Property $x) => $x->id === $p->id),
            'a {"pets_allowed": true} native listing must satisfy a pet_friendly must-have'
        );
    }

    public function test_must_have_still_excludes_a_listing_that_declares_it_absent(): void
    {
        [$agencyId, $agent, $suburbId] = $this->fixture();
        // Structured data present AND says pets are NOT allowed → genuine miss.
        $p = $this->property($agencyId, $agent->id, $suburbId, [
            'price' => 1_800_000, 'beds' => 3,
            'features_json' => ['pool' => true, 'pets_allowed' => false],
        ]);
        $match = $this->petFriendlyMatch($agencyId, $suburbId);

        $results = app(MatchingService::class)
            ->propertiesForMatch($match, ['agent_id' => null]);

        $this->assertFalse(
            $results->contains(fn (Property $x) => $x->id === $p->id),
            'a listing that structurally declares pets_allowed=false must NOT satisfy the must-have'
        );
    }

    public function test_listing_with_no_feature_data_is_treated_as_unknown_not_excluded(): void
    {
        [$agencyId, $agent, $suburbId] = $this->fixture();
        // Empty features_json = unknown, not a mismatch: must not hard-fail to 0.
        $p = $this->property($agencyId, $agent->id, $suburbId, [
            'price' => 1_800_000, 'beds' => 3,
            'features_json' => [],
        ]);
        $match = $this->petFriendlyMatch($agencyId, $suburbId);

        $results = app(MatchingService::class)
            ->propertiesForMatch($match, ['agent_id' => null]);

        $this->assertTrue(
            $results->contains(fn (Property $x) => $x->id === $p->id),
            'a listing with no structured features must survive the must-have gate (unknown != absent)'
        );
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    private function petFriendlyMatch(int $agencyId, int $suburbId): ContactMatch
    {
        $buyer = Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'is_buyer' => true, 'buyer_state' => 'new',
            'first_name' => 'Peter', 'last_name' => 'Buyer ' . Str::random(3),
            'phone' => '082' . random_int(1000000, 9999999),
            'email' => 'peter-' . Str::random(5) . '@example.co.za',
        ]);

        return ContactMatch::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'contact_id' => $buyer->id,
            'status' => ContactMatch::STATUS_ACTIVE, 'listing_type' => 'sale',
            'price_min' => 1_000_000, 'price_max' => 2_700_000,
            'beds_min' => 3, 'p24_suburb_ids' => [$suburbId],
            'must_have_features' => ['pet_friendly'],
        ]);
    }

    private function fixture(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin',
        ]);
        $suburbId = $this->seedP24Suburb();
        return [$agencyId, $agent, $suburbId];
    }

    private function property(int $agencyId, int $agentId, int $suburbId, array $extra = []): Property
    {
        return Property::create(array_merge([
            'external_id'   => (string) Str::uuid(),
            'title'         => 'Test Property ' . Str::random(5),
            'agent_id'      => $agentId,
            'branch_id'     => $agencyId,
            'agency_id'     => $agencyId,
            'listing_type'  => 'sale',
            'status'        => 'active',
            'published_at'  => now(),
            'suburb'        => 'Uvongo',
            'p24_suburb_id' => $suburbId,
        ], $extra));
    }

    private function seedP24Suburb(): int
    {
        $countryId = (int) DB::table('p24_countries')->insertGetId([
            'p24_id' => random_int(1, 999999), 'name' => 'South Africa', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $provinceId = (int) DB::table('p24_provinces')->insertGetId([
            'p24_id' => random_int(1, 999999), 'p24_country_id' => $countryId, 'name' => 'KwaZulu-Natal',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cityId = (int) DB::table('p24_cities')->insertGetId([
            'p24_id' => random_int(1, 999999), 'p24_province_id' => $provinceId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return (int) DB::table('p24_suburbs')->insertGetId([
            'p24_id' => random_int(1, 999999), 'p24_city_id' => $cityId, 'name' => 'Uvongo',
            'slug' => 'uvongo-' . Str::random(5), 'p24_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
