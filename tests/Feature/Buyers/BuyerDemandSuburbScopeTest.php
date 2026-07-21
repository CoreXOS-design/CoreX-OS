<?php

namespace Tests\Feature\Buyers;

use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\User;
use App\Services\Matching\MatchingService;
use App\Services\PropertyMatchScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-289 — the per-property buyer-DEMAND CLAIM count must be suburb-honest. A
 * buyer whose wishlist explicitly targets a DIFFERENT suburb is not "demand for
 * properties like yours in {property_suburb}", even if price+beds+type carry them
 * over the 50 score floor. Open (no suburb) or includes-this-suburb only.
 * (The global browse engine keeps its soft suburb score — not tested here.)
 */
class BuyerDemandSuburbScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_other_suburb_buyer_is_excluded_from_the_demand_count(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $suburbA = $this->seedSuburb('Shelly Beach');
        $suburbB = $this->seedSuburb('Margate');

        $property = $this->property($agencyId, $agent->id, $suburbA, [
            'price' => 1_800_000, 'beds' => 3, 'property_type' => 'Townhouse',
        ]);

        $wish = ['price_min' => 1_500_000, 'price_max' => 2_000_000, 'beds_min' => 3, 'property_type' => 'Townhouse'];

        $inSuburb = $this->buyer($agencyId, $agent->id, 'warm');
        $this->match($agencyId, $inSuburb->id, $wish + ['p24_suburb_ids' => [$suburbA]]);   // wants THIS suburb → counts

        $openAny = $this->buyer($agencyId, $agent->id, 'new');
        $this->match($agencyId, $openAny->id, $wish);                                        // open (no suburb) → counts

        $otherSuburb = $this->buyer($agencyId, $agent->id, 'warm');
        $leak = $this->match($agencyId, $otherSuburb->id, $wish + ['p24_suburb_ids' => [$suburbB]]); // wants OTHER suburb → excluded

        $matcher = app(MatchingService::class);

        // The leak buyer clears the 50 floor (so it's the GATE, not the score, that drops it).
        $this->assertGreaterThanOrEqual(
            MatchingService::MIN_SCORE_TO_DISPLAY,
            $matcher->score($property, $leak),
            'the other-suburb buyer scores >= 50 — it would leak into the count without the hard suburb gate'
        );

        // suburbCompatible — the one rule.
        $this->assertTrue($matcher->suburbCompatible($property, ContactMatch::withoutGlobalScopes()->where('contact_id', $inSuburb->id)->first()));
        $this->assertTrue($matcher->suburbCompatible($property, ContactMatch::withoutGlobalScopes()->where('contact_id', $openAny->id)->first()));
        $this->assertFalse($matcher->suburbCompatible($property, $leak));

        // The demand claim: open + in-suburb count; the explicit other-suburb buyer does NOT.
        $demand = app(PropertyMatchScoringService::class)->getBuyerDemandForProperty($property->id, $agencyId);
        $this->assertSame(2, $demand['active']['count'], 'only the open + in-suburb buyers are demand for this property');

        $basis = app(PropertyMatchScoringService::class)->countableActiveBuyerBasisForProperty($property);
        $this->assertSame(2, $basis['count']);
        $this->assertContains($inSuburb->id, $basis['contact_ids']);
        $this->assertContains($openAny->id, $basis['contact_ids']);
        $this->assertNotContains($otherSuburb->id, $basis['contact_ids'], 'explicit other-suburb buyer excluded from the auditable basis');
    }

    // ── helpers (mirror PresentationDemandStalenessAndTagTest) ────────────────

    /** @return array{0:int,1:User} */
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
        $agent = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin']);
        return [$agencyId, $agent];
    }

    private function buyer(int $agencyId, int $agentId, string $state): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'created_by_user_id' => $agentId, 'agent_id' => $agentId,
            'is_buyer' => true, 'buyer_state' => $state,
            'first_name' => 'Bea', 'last_name' => 'Buyer ' . Str::random(3),
            'phone' => '082' . random_int(1000000, 9999999),
            'email' => 'bea-' . Str::random(5) . '@example.co.za',
        ]);
    }

    private function match(int $agencyId, int $contactId, array $extra): ContactMatch
    {
        return ContactMatch::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $agencyId, 'contact_id' => $contactId,
            'status' => ContactMatch::STATUS_ACTIVE, 'listing_type' => 'sale',
        ], $extra));
    }

    private function property(int $agencyId, int $agentId, int $suburbId, array $extra = []): Property
    {
        return Property::create(array_merge([
            'external_id'  => (string) Str::uuid(),
            'title'        => 'Test Property ' . Str::random(5),
            'agent_id'     => $agentId, 'branch_id' => $agencyId, 'agency_id' => $agencyId,
            'listing_type' => 'sale', 'status' => 'active', 'published_at' => now(),
            'suburb'       => 'Shelly Beach', 'p24_suburb_id' => $suburbId,
        ], $extra));
    }

    private function seedSuburb(string $name): int
    {
        $countryId = (int) DB::table('p24_countries')->insertGetId([
            'p24_id' => random_int(1, 9999999), 'name' => 'South Africa ' . Str::random(3), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $provinceId = (int) DB::table('p24_provinces')->insertGetId([
            'p24_id' => random_int(1, 9999999), 'p24_country_id' => $countryId, 'name' => 'KZN ' . Str::random(3),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cityId = (int) DB::table('p24_cities')->insertGetId([
            'p24_id' => random_int(1, 9999999), 'p24_province_id' => $provinceId, 'name' => 'City ' . Str::random(3),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return (int) DB::table('p24_suburbs')->insertGetId([
            'p24_id' => random_int(1, 9999999), 'p24_city_id' => $cityId, 'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(5), 'p24_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
