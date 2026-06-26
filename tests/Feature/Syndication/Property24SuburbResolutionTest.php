<?php

namespace Tests\Feature\Syndication;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\P24City;
use App\Models\P24Province;
use App\Models\P24Suburb;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Syndication\Property24\Property24ListingMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AT-104 — P24 suburb ID resolution must never land a collision suburb in the
 * wrong city. "Glenmore" exists in P24 in both Durban (p24_id 10787) and on the
 * South Coast / Port Edward (p24_id 10790). The resolver must:
 *   1. trust the stored, chain-verified p24_suburb_id FK above everything,
 *   2. otherwise disambiguate a name match by the property's city/province and
 *      only ever return when it resolves to exactly ONE suburb,
 *   3. return null (→ skip + flag for manual mapping) when it cannot — never a
 *      guessed/fallback suburb ID,
 *   4. still resolve ordinary non-collision suburbs without regression.
 */
class Property24SuburbResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Property24ListingMapper $mapper;
    private ReflectionMethod $resolve;
    private Agency $agency;
    private Branch $branch;
    private User $agent;

    /** P24 hierarchy row ids captured during seeding. */
    private int $kznId;
    private int $durbanCityId;
    private int $portEdwardCityId;
    private int $glenmoreDurbanRowId;
    private int $glenmorePortEdwardRowId;
    private int $margateRowId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new Property24ListingMapper();
        $this->resolve = new ReflectionMethod(Property24ListingMapper::class, 'resolveSuburbId');
        $this->resolve->setAccessible(true);

        $this->seedP24Hierarchy();

        $this->agency = Agency::create([
            'name' => 'Coastal', 'slug' => 'coastal',
            'p24_username' => 'u', 'p24_password' => 'p', 'p24_agency_id' => '123',
        ]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
    }

    private function seedP24Hierarchy(): void
    {
        // KwaZulu Natal — note P24 stores it WITHOUT a hyphen (real data shape).
        $kzn = P24Province::create(['p24_id' => 4, 'p24_country_id' => 1, 'name' => 'KwaZulu Natal']);
        $this->kznId = $kzn->id;

        $durban = P24City::create(['p24_id' => 169, 'p24_province_id' => $kzn->id, 'name' => 'Durban']);
        $portEdward = P24City::create(['p24_id' => 822, 'p24_province_id' => $kzn->id, 'name' => 'Port Edward']);
        $this->durbanCityId = $durban->id;
        $this->portEdwardCityId = $portEdward->id;

        // The collision: two "Glenmore" suburbs in different cities.
        $this->glenmoreDurbanRowId = P24Suburb::create([
            'name' => 'Glenmore', 'slug' => 'glenmore', 'p24_id' => 10787,
            'p24_city_id' => $durban->id, 'region' => 'durban',
        ])->id;
        $this->glenmorePortEdwardRowId = P24Suburb::create([
            'name' => 'Glenmore', 'slug' => 'glenmore', 'p24_id' => 10790,
            'p24_city_id' => $portEdward->id, 'region' => 'kzn-south-coast',
        ])->id;

        // A non-collision suburb for the regression check.
        $this->margateRowId = P24Suburb::create([
            'name' => 'Margate', 'slug' => 'margate', 'p24_id' => 6360,
            'p24_city_id' => $portEdward->id, 'region' => 'kzn-south-coast',
        ])->id;
    }

    private function makeProperty(array $attrs): Property
    {
        $p = Property::withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'L',
            'property_type' => 'house', 'status' => 'active', 'price' => 1000000,
        ], $attrs));

        return $p->fresh();
    }

    private function resolveFor(Property $p): ?int
    {
        return $this->resolve->invoke($this->mapper, $p);
    }

    /** TIER 1 — a stored p24_suburb_id is authoritative and never overridden by name. */
    public function test_stored_p24_suburb_id_wins_and_returns_port_edward_not_durban(): void
    {
        $p = $this->makeProperty([
            'suburb' => 'Glenmore', 'city' => 'Port Edward', 'province' => 'KwaZulu-Natal',
            'p24_suburb_id' => $this->glenmorePortEdwardRowId,
            'p24_city_id' => $this->portEdwardCityId, 'p24_province_id' => $this->kznId,
        ]);

        $this->assertSame(10790, $this->resolveFor($p), 'Must send Port Edward Glenmore (10790), never Durban (10787).');
    }

    /** TIER 2 — no FK, but city text disambiguates the collision to the right suburb. */
    public function test_name_plus_city_disambiguates_collision_to_port_edward(): void
    {
        $p = $this->makeProperty([
            'suburb' => 'Glenmore', 'city' => 'Port Edward', 'province' => 'KwaZulu Natal',
            // No p24_* FKs — must resolve purely from the suburb + city text.
        ]);

        $this->assertSame(10790, $this->resolveFor($p), 'name + city must pick the Port Edward Glenmore.');
    }

    /** A property genuinely in the Durban Glenmore still resolves there. */
    public function test_name_plus_city_resolves_durban_glenmore_when_city_is_durban(): void
    {
        $p = $this->makeProperty([
            'suburb' => 'Glenmore', 'city' => 'Durban', 'province' => 'KwaZulu Natal',
        ]);

        $this->assertSame(10787, $this->resolveFor($p), 'A Durban Glenmore must resolve to 10787.');
    }

    /** TIER 4 — collision with NO city signal must NOT guess: null → skip + flag. */
    public function test_collision_without_city_signal_returns_null_not_a_guess(): void
    {
        $p = $this->makeProperty([
            'suburb' => 'Glenmore', 'city' => null, 'town' => null, 'province' => null,
        ]);

        $this->assertNull($this->resolveFor($p), 'Ambiguous collision with no city must skip+flag, never guess.');
    }

    /** Regression — an ordinary non-collision suburb still resolves from the name alone. */
    public function test_non_collision_suburb_resolves_by_name_without_regression(): void
    {
        $p = $this->makeProperty([
            'suburb' => 'Margate', 'city' => 'Margate', 'province' => 'KwaZulu Natal',
        ]);

        $this->assertSame(6360, $this->resolveFor($p), 'Margate (unique) must still resolve.');
    }

    /** A totally unknown suburb returns null so it is flagged, not sent blind. */
    public function test_unknown_suburb_returns_null(): void
    {
        $p = $this->makeProperty([
            'suburb' => 'Nowhereville', 'city' => 'Port Edward', 'province' => 'KwaZulu Natal',
        ]);

        $this->assertNull($this->resolveFor($p));
    }
}
