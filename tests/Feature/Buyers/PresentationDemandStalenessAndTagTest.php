<?php

declare(strict_types=1);

namespace Tests\Feature\Buyers;

use App\Models\AgencyContactSettings;
use App\Models\BuyerStateTransition;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\User;
use App\Services\BuyerStateService;
use App\Services\PropertyMatchScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-74 — Buyer Pillar Build 4 (final):
 *   Part A — presentations buyer-demand: honest active + historic split (real %),
 *            area demand kept separate and explicitly labelled.
 *   Part B — staleness respects the agent: a recent manual placement survives the
 *            nightly recompute; genuinely stale buyers still decay.
 *   Part C — "No core match" tag: a pipeline buyer with zero countable wishlists.
 */
final class PresentationDemandStalenessAndTagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AgencyContactSettings::clearMinCountableCache();
        Bus::fake(); // neutralise AT-71/72 RegenerateBuyerMatchesJob dispatch
    }

    // ══════════════ PART A — presentation honest split ══════════════

    public function test_active_buyer_shows_with_real_percent_no_historic(): void
    {
        [$agencyId, $agent, $suburbId] = $this->fixture();
        $property = $this->property($agencyId, $agent->id, $suburbId, ['price' => 1_800_000, 'beds' => 3]);
        $buyer = $this->buyer($agencyId, $agent->id, 'warm');
        $this->match($agencyId, $buyer->id, ['price_min' => 1_500_000, 'price_max' => 2_000_000, 'beds_min' => 3, 'p24_suburb_ids' => [$suburbId]]);

        $demand = app(PropertyMatchScoringService::class)->getBuyerDemandForProperty($property->id, $agencyId);

        $this->assertSame(1, $demand['active']['count'], 'one active buyer matched');
        $this->assertSame(100, $demand['active']['anonymised_buyers'][0]['score'], 'real canonical %');
        $this->assertSame('strong', $demand['active']['anonymised_buyers'][0]['tier']);
        $this->assertSame(0, $demand['historic']['count']);
        $this->assertTrue($demand['has_property_demand']);
    }

    public function test_cold_buyer_who_engaged_property_is_historic_not_active(): void
    {
        [$agencyId, $agent, $suburbId] = $this->fixture();
        $property = $this->property($agencyId, $agent->id, $suburbId, ['price' => 1_800_000, 'beds' => 3]);
        // Cold buyer (not active) who viewed THIS property before.
        $buyer = $this->buyer($agencyId, $agent->id, 'cold');
        $this->match($agencyId, $buyer->id, ['price_min' => 1_500_000, 'price_max' => 2_000_000, 'beds_min' => 3, 'p24_suburb_ids' => [$suburbId]]);
        $this->engagedProperty($agencyId, $buyer->id, $property->id);

        $demand = app(PropertyMatchScoringService::class)->getBuyerDemandForProperty($property->id, $agencyId);

        $this->assertSame(0, $demand['active']['count'], 'cold buyer is not active');
        $this->assertSame(1, $demand['historic']['count'], 'cold buyer who engaged this property is historic');
    }

    public function test_both_active_and_historic(): void
    {
        [$agencyId, $agent, $suburbId] = $this->fixture();
        $property = $this->property($agencyId, $agent->id, $suburbId, ['price' => 1_800_000, 'beds' => 3]);

        $active = $this->buyer($agencyId, $agent->id, 'warm');
        $this->match($agencyId, $active->id, ['price_min' => 1_500_000, 'price_max' => 2_000_000, 'beds_min' => 3, 'p24_suburb_ids' => [$suburbId]]);

        $historic = $this->buyer($agencyId, $agent->id, 'lost');
        $this->engagedProperty($agencyId, $historic->id, $property->id);

        $demand = app(PropertyMatchScoringService::class)->getBuyerDemandForProperty($property->id, $agencyId);

        $this->assertSame(1, $demand['active']['count']);
        $this->assertSame(1, $demand['historic']['count']);
    }

    public function test_neither_active_nor_historic_hides_property_demand(): void
    {
        [$agencyId, $agent, $suburbId] = $this->fixture();
        $property = $this->property($agencyId, $agent->id, $suburbId, ['price' => 1_800_000, 'beds' => 3]);

        $demand = app(PropertyMatchScoringService::class)->getBuyerDemandForProperty($property->id, $agencyId);

        $this->assertSame(0, $demand['active']['count']);
        $this->assertSame(0, $demand['historic']['count']);
        $this->assertFalse($demand['has_property_demand']);
    }

    public function test_area_demand_is_separate_from_property_active(): void
    {
        [$agencyId, $agent, $suburbId] = $this->fixture();
        $property = $this->property($agencyId, $agent->id, $suburbId, ['price' => 1_800_000, 'beds' => 3]);
        // Area-only buyer: wants the suburb, but a price band that does NOT cover
        // this property → counts as area demand, NOT as a this-property match.
        $buyer = $this->buyer($agencyId, $agent->id, 'warm');
        $this->match($agencyId, $buyer->id, ['price_min' => 200_000, 'price_max' => 400_000, 'p24_suburb_ids' => [$suburbId]]);

        $demand = app(PropertyMatchScoringService::class)->getBuyerDemandForProperty($property->id, $agencyId);

        $this->assertArrayHasKey('area', $demand);
        $this->assertGreaterThanOrEqual(1, $demand['area']['area_buyers'], 'area buyer counted under area');
        $this->assertSame(0, $demand['active']['count'], 'area-only buyer is NOT a this-property active match');
        $this->assertSame('Uvongo', $demand['area']['suburb']);
    }

    public function test_empty_wishlist_excluded_from_active(): void
    {
        [$agencyId, $agent, $suburbId] = $this->fixture();
        $property = $this->property($agencyId, $agent->id, $suburbId, ['price' => 1_800_000, 'beds' => 3]);
        $buyer = $this->buyer($agencyId, $agent->id, 'warm');
        $this->match($agencyId, $buyer->id, []); // uncountable

        $demand = app(PropertyMatchScoringService::class)->getBuyerDemandForProperty($property->id, $agencyId);

        $this->assertSame(0, $demand['active']['count'], 'empty wishlist is not an active match (AT-71 gate)');
    }

    // ══════════════ PART B — staleness respects the agent ══════════════

    public function test_agent_moved_today_survives_the_recompute(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id, 'cold');
        // last activity is ancient → without protection it would decay to lost.
        $buyer->updateQuietly(['last_activity_at' => now()->subDays(45)]);
        // Agent drags to warm TODAY (manual_override).
        app(BuyerStateService::class)->transitionTo($buyer->fresh(), 'warm', 'manual_override', $agent->id);

        $svc = app(BuyerStateService::class);
        $this->assertTrue($svc->isManualPlacementProtected($buyer->fresh()));

        $svc->recomputeState($buyer->fresh());

        $this->assertSame('warm', $buyer->fresh()->buyer_state, 'agent-placed buyer must NOT be auto-decayed');
    }

    public function test_genuinely_stale_buyer_still_decays(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id, 'warm');
        $buyer->updateQuietly(['last_activity_at' => now()->subDays(45)]); // > cold window, no manual touch

        $svc = app(BuyerStateService::class);
        $this->assertFalse($svc->isManualPlacementProtected($buyer->fresh()));

        $svc->recomputeState($buyer->fresh());

        $this->assertSame('lost', $buyer->fresh()->buyer_state, 'no agent action + stale → decays');
    }

    public function test_old_manual_placement_no_longer_protects(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id, 'warm');
        $buyer->updateQuietly(['last_activity_at' => now()->subDays(45)]);
        // A manual override 90 days ago — older than the protection window.
        BuyerStateTransition::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'contact_id' => $buyer->id,
            'from_state' => 'new', 'to_state' => 'warm', 'reason' => 'manual_override',
            'triggered_by_user_id' => $agent->id, 'occurred_at' => now()->subDays(90),
        ]);

        $svc = app(BuyerStateService::class);
        $this->assertFalse($svc->isManualPlacementProtected($buyer->fresh()), 'old manual placement is past the window');

        $svc->recomputeState($buyer->fresh());
        $this->assertSame('lost', $buyer->fresh()->buyer_state, 'stale buyer with only an OLD manual touch still decays');
    }

    // ══════════════ PART C — "No core match" tag helper ══════════════

    public function test_buyer_with_countable_wishlist_has_core_match(): void
    {
        [$agencyId, $agent, $suburbId] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id, 'new');
        $this->match($agencyId, $buyer->id, ['price_min' => 1_000_000]); // countable

        $this->assertTrue($buyer->fresh()->hasCountableWishlist(), 'countable wishlist → no tag');
    }

    public function test_buyer_with_only_empty_wishlist_lacks_core_match(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id, 'new');
        $this->match($agencyId, $buyer->id, []); // uncountable

        $this->assertFalse($buyer->fresh()->hasCountableWishlist(), 'empty wishlist → tag shows');
    }

    public function test_buyer_with_no_wishlist_lacks_core_match(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id, 'new');

        $this->assertFalse($buyer->fresh()->hasCountableWishlist(), 'no wishlist → tag shows');
    }

    public function test_tag_disappears_when_countable_wishlist_readded(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id, 'new');
        $empty = $this->match($agencyId, $buyer->id, []);
        $this->assertFalse($buyer->fresh()->hasCountableWishlist());

        $this->match($agencyId, $buyer->id, ['beds_min' => 3]); // re-add a countable wishlist
        $this->assertTrue($buyer->fresh()->hasCountableWishlist(), 'adding a countable wishlist hides the tag');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @return array{0:int,1:User,2:int} */
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
        $suburbId = $this->seedP24Suburb();
        return [$agencyId, $agent, $suburbId];
    }

    private function buyer(int $agencyId, int $agentId, string $state = 'new'): Contact
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
            'external_id'   => (string) Str::uuid(),
            'title'         => 'Test Property ' . Str::random(5),
            'agent_id'      => $agentId, 'branch_id' => $agencyId, 'agency_id' => $agencyId,
            'listing_type'  => 'sale', 'status' => 'active', 'published_at' => now(),
            'suburb'        => 'Uvongo', 'p24_suburb_id' => $suburbId,
        ], $extra));
    }

    /** Record per-property engagement (the historic-buyer source). */
    private function engagedProperty(int $agencyId, int $contactId, int $propertyId): void
    {
        DB::table('buyer_property_views')->insert([
            'agency_id' => $agencyId, 'contact_id' => $contactId, 'property_id' => $propertyId,
            'last_viewed_at' => now()->subDays(20), 'view_count' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
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
            'slug' => 'uvongo-' . Str::random(5), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
