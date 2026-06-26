<?php

declare(strict_types=1);

namespace Tests\Feature\Buyers;

use App\Jobs\RegenerateBuyerMatchesJob;
use App\Models\AgencyContactSettings;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\User;
use App\Services\Matching\MatchingService;
use App\Services\PropertyMatchScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-71 — Buyer Pillar Build 1: countable-buyer gate.
 *
 * Rule: a wishlist (ContactMatch) is COUNTABLE if it has at least one non-empty
 * canonical criteria field (default 'any' bar). Only a completely empty wishlist
 * is uncountable — and an uncountable wishlist must be excluded from every match
 * count/list and must never inflate to a full match (the empty→100 / no-signal→85
 * bug). Bar is agency-configurable (default = any-1).
 */
final class CountableBuyerGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AgencyContactSettings::clearMinCountableCache();
    }

    // ── isCountable(): each single field counts; empty does not ───────────

    public function test_each_single_criteria_field_makes_a_wishlist_countable(): void
    {
        [$agencyId, $agent] = $this->fixture();
        [$pid] = $this->seedP24();
        $c = $this->buyer($agencyId, $agent->id);

        $cases = [
            'price-min only' => ['price_min' => 1_000_000],
            'price-max only' => ['price_max' => 2_000_000],
            'area only'      => ['p24_suburb_ids' => [$pid]],
            'beds only'      => ['beds_min' => 3],
            'baths only'     => ['baths_min' => 2],
            'type only'      => ['property_types' => ['house']],
            'must-have only' => ['must_have_features' => ['pool']],
        ];

        foreach ($cases as $label => $extra) {
            $m = $this->match($agencyId, $c->id, $extra);
            $this->assertTrue($m->isCountable(), "$label should be countable");
        }
    }

    public function test_completely_empty_wishlist_is_uncountable(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $c = $this->buyer($agencyId, $agent->id);
        $m = $this->match($agencyId, $c->id, []); // no criteria at all

        $this->assertFalse($m->isCountable(), 'empty wishlist must be uncountable');
        $this->assertSame([], $m->presentCriteriaGroups());
    }

    public function test_legacy_shadow_only_does_not_count_as_area(): void
    {
        // suburbs (derived shadow) populated but no canonical p24_suburb_ids,
        // and no other field → still uncountable. Gate reads canonical only.
        [$agencyId, $agent] = $this->fixture();
        $c = $this->buyer($agencyId, $agent->id);
        $m = $this->match($agencyId, $c->id, []);
        // Force the derived shadow without canonical ids (bypassing the sync hook).
        DB::table('contact_matches')->where('id', $m->id)->update(['suburbs' => json_encode(['Uvongo'])]);

        $this->assertFalse($m->fresh()->isCountable(), 'derived suburbs shadow must not satisfy area');
    }

    // ── Agency-configurable bar ───────────────────────────────────────────

    public function test_agency_can_raise_the_bar_to_area_plus_price(): void
    {
        [$agencyId, $agent] = $this->fixture();
        [$pid] = $this->seedP24();
        AgencyContactSettings::forAgency($agencyId)->update(['min_countable_criteria' => ['area', 'price_band']]);
        AgencyContactSettings::clearMinCountableCache();

        $c = $this->buyer($agencyId, $agent->id);

        $priceOnly = $this->match($agencyId, $c->id, ['price_min' => 1_000_000]);
        $this->assertFalse($priceOnly->isCountable(), 'price-only fails the area+price bar');

        $both = $this->match($agencyId, $c->id, ['price_min' => 1_000_000, 'p24_suburb_ids' => [$pid]]);
        $this->assertTrue($both->isCountable(), 'area+price satisfies the raised bar');
    }

    // ── Engine A (MatchingService, live) ──────────────────────────────────

    public function test_empty_wishlist_excluded_from_matches_for_property(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $property = $this->property($agencyId, $agent->id);
        $c = $this->buyer($agencyId, $agent->id);
        $this->match($agencyId, $c->id, []); // empty → uncountable

        $matches = app(MatchingService::class)->matchesForProperty($property);
        $this->assertCount(0, $matches, 'uncountable wishlist must not appear as a property match');
    }

    public function test_one_field_wishlist_is_included_in_matches_for_property(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $property = $this->property($agencyId, $agent->id, ['price' => 1_500_000]);
        $c = $this->buyer($agencyId, $agent->id);
        $this->match($agencyId, $c->id, ['price_min' => 1_000_000, 'price_max' => 2_000_000]);

        $matches = app(MatchingService::class)->matchesForProperty($property);
        $this->assertCount(1, $matches, 'a countable price-band wishlist must surface');
    }

    public function test_properties_for_match_returns_empty_for_uncountable_wishlist(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $this->property($agencyId, $agent->id, ['price' => 1_500_000]);
        $c = $this->buyer($agencyId, $agent->id);
        $empty = $this->match($agencyId, $c->id, []);

        $result = app(MatchingService::class)->propertiesForMatch($empty, ['agent_id' => null]);
        $this->assertCount(0, $result, 'uncountable wishlist matches no properties');
    }

    public function test_score_no_longer_inflates_empty_wishlist_to_100(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $property = $this->property($agencyId, $agent->id, ['price' => 1_500_000]);
        $c = $this->buyer($agencyId, $agent->id);
        $empty = $this->match($agencyId, $c->id, []);

        $this->assertSame(0, app(MatchingService::class)->score($property, $empty), 'empty wishlist must score 0, not 100');
    }

    // ── Engine B (PropertyMatchScoringService, cached) ────────────────────

    public function test_calculate_score_zero_for_empty_wishlist_not_strong(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $property = $this->property($agencyId, $agent->id, ['price' => 1_500_000]);
        $c = $this->buyer($agencyId, $agent->id);
        $empty = $this->match($agencyId, $c->id, []);

        $result = app(PropertyMatchScoringService::class)->calculateScore($empty->fresh(), $property);
        $this->assertSame(0, $result['score'], 'empty wishlist must not score ~85 "strong" on no-signal defaults');
        $this->assertSame('none', $result['tier']);
    }

    public function test_recompute_excludes_empty_and_caches_countable(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $this->property($agencyId, $agent->id, ['price' => 1_500_000]);

        $emptyBuyer = $this->buyer($agencyId, $agent->id);
        $this->match($agencyId, $emptyBuyer->id, []);

        $realBuyer = $this->buyer($agencyId, $agent->id);
        $this->match($agencyId, $realBuyer->id, ['price_min' => 1_000_000, 'price_max' => 2_000_000]);

        app(PropertyMatchScoringService::class)->recomputeForBuyer($emptyBuyer->id);
        app(PropertyMatchScoringService::class)->recomputeForBuyer($realBuyer->id);

        $this->assertSame(0, DB::table('property_buyer_matches')->where('contact_id', $emptyBuyer->id)->count(), 'empty buyer not cached');
        $this->assertGreaterThanOrEqual(1, DB::table('property_buyer_matches')->where('contact_id', $realBuyer->id)->count(), 'countable buyer cached');
    }

    // ── cm.suburb dropped-column bug fix ──────────────────────────────────

    public function test_get_buyer_demand_runs_and_counts_area_buyers_via_canonical(): void
    {
        [$agencyId, $agent] = $this->fixture();
        [$pid] = $this->seedP24();
        $property = $this->property($agencyId, $agent->id, ['price' => 1_500_000, 'p24_suburb_id' => $pid, 'suburb' => 'Uvongo']);
        $c = $this->buyer($agencyId, $agent->id);
        $this->match($agencyId, $c->id, ['p24_suburb_ids' => [$pid]]);

        // Would throw "Unknown column 'cm.suburb'" before the fix.
        $demand = app(PropertyMatchScoringService::class)->getBuyerDemandForProperty($property->id, $agencyId);

        $this->assertIsArray($demand);
        // AT-74 — area demand now lives under the explicitly-labelled 'area' key.
        $this->assertGreaterThanOrEqual(1, $demand['area']['area_buyers'], 'canonical p24 area buyer counted');
    }

    public function test_get_buyer_demand_excludes_empty_wishlist_from_area_buyers(): void
    {
        [$agencyId, $agent] = $this->fixture();
        [$pid] = $this->seedP24();
        $property = $this->property($agencyId, $agent->id, ['price' => 1_500_000, 'p24_suburb_id' => $pid, 'suburb' => 'Uvongo']);
        $c = $this->buyer($agencyId, $agent->id);
        $this->match($agencyId, $c->id, []); // empty wishlist in the same suburb name space

        $demand = app(PropertyMatchScoringService::class)->getBuyerDemandForProperty($property->id, $agencyId);
        $this->assertSame(0, $demand['area']['area_buyers'], 'empty wishlist is not an area buyer');
    }

    // ── Freshness — recompute dispatched on wishlist save/delete ──────────

    public function test_saving_a_wishlist_dispatches_recompute(): void
    {
        Bus::fake();
        [$agencyId, $agent] = $this->fixture();
        $c = $this->buyer($agencyId, $agent->id);
        $this->match($agencyId, $c->id, ['price_min' => 1_000_000]);

        Bus::assertDispatched(RegenerateBuyerMatchesJob::class, function (RegenerateBuyerMatchesJob $job) use ($c) {
            return $job->contactId === $c->id;
        });
    }

    public function test_deleting_a_wishlist_dispatches_recompute(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $c = $this->buyer($agencyId, $agent->id);
        $m = $this->match($agencyId, $c->id, ['price_min' => 1_000_000]);

        Bus::fake();
        $m->delete();
        Bus::assertDispatched(RegenerateBuyerMatchesJob::class, fn (RegenerateBuyerMatchesJob $job) => $job->contactId === $c->id);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

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
        $agent = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin',
        ]);
        return [$agencyId, $agent];
    }

    private function buyer(int $agencyId, int $agentId): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'created_by_user_id' => $agentId, 'agent_id' => $agentId,
            'is_buyer' => true, 'buyer_state' => 'new',
            'first_name' => 'Bea', 'last_name' => 'Buyer',
            'phone' => '082' . random_int(1000000, 9999999),
            'email' => 'bea-' . Str::random(5) . '@example.com',
        ]);
    }

    private function match(int $agencyId, int $contactId, array $extra): ContactMatch
    {
        return ContactMatch::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $agencyId, 'contact_id' => $contactId,
            'status' => ContactMatch::STATUS_ACTIVE, 'listing_type' => 'sale',
        ], $extra));
    }

    private function property(int $agencyId, int $agentId, array $extra = []): Property
    {
        return Property::create(array_merge([
            'external_id'  => (string) Str::uuid(),
            'title'        => 'Test Property ' . Str::random(5),
            'agent_id'     => $agentId,
            'branch_id'    => $agencyId,
            'agency_id'    => $agencyId,
            'listing_type' => 'sale',
            'status'       => 'active',
            'published_at' => now(),
            'beds'         => 3,
            'suburb'       => 'Uvongo',
        ], $extra));
    }

    /** @return array{0:int} [suburbId] */
    private function seedP24(): array
    {
        $countryId = (int) DB::table('p24_countries')->insertGetId([
            'p24_id' => 1, 'name' => 'South Africa', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $provinceId = (int) DB::table('p24_provinces')->insertGetId([
            'p24_id' => 101, 'p24_country_id' => $countryId, 'name' => 'KwaZulu-Natal',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cityId = (int) DB::table('p24_cities')->insertGetId([
            'p24_id' => 201, 'p24_province_id' => $provinceId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $suburbId = (int) DB::table('p24_suburbs')->insertGetId([
            'p24_id' => 301, 'p24_city_id' => $cityId, 'name' => 'Uvongo', 'slug' => 'uvongo-' . Str::random(5),
            'p24_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$suburbId];
    }
}
