<?php

declare(strict_types=1);

namespace Tests\Feature\Buyers;

use App\Models\AgencyContactSettings;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\User;
use App\Services\Matching\MatchingService;
use App\Services\PropertyIntelligenceService;
use App\Services\PropertyMatchScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-73 — Buyer Pillar Build 3: canonical engine on the Intelligence tab + MIC
 * alignment (kill fabricated counts).
 *
 * The property Intelligence tab's "Buyer Matches" used to stamp a hardcoded
 * match_score=75 onto the first 10 is_buyer contacts with NO matching. It now
 * delegates to the canonical engine (MatchingService::matchesForProperty —
 * "Path 1"), so it shows the REAL buyers at their REAL % and tier, inheriting
 * the AT-71 countable gate. Engine B's tier vocabulary is aligned so "strong"
 * means score >= 80 everywhere (same as Path 1).
 */
final class CanonicalEngineSurfacesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AgencyContactSettings::clearMinCountableCache();
        // AT-72 auto-land dispatches RegenerateBuyerMatchesJob on wishlist save;
        // neutralise it — these tests drive the cache recompute directly.
        Bus::fake();
    }

    // ── Item 1: fabricated 75 is gone; real % shown ───────────────────────

    public function test_intelligence_tab_shows_real_match_percent_not_fabricated_75(): void
    {
        [$agencyId, $agent, $suburbId] = $this->fixture();
        $property = $this->property($agencyId, $agent->id, $suburbId, [
            'price' => 1_800_000, 'beds' => 3,
        ]);
        $buyer = $this->buyer($agencyId, $agent->id);
        // A fully-fitting countable wishlist → should score 100.
        $this->match($agencyId, $buyer->id, [
            'price_min' => 1_500_000, 'price_max' => 2_000_000,
            'beds_min' => 3, 'p24_suburb_ids' => [$suburbId],
        ]);

        $signals = app(PropertyIntelligenceService::class)->getBuyerInterestSignals($property->id);

        $this->assertCount(1, $signals, 'the matching buyer should surface');
        $row = $signals->first();
        $this->assertSame($buyer->id, $row['id']);
        $this->assertSame(100, $row['match_score'], 'real canonical score, not the fabricated 75');
        $this->assertSame('strong', $row['tier'], 'tier from MatchingService::tierFor (>=80)');
        $this->assertNotSame(75, $row['match_score']);
    }

    public function test_partial_wishlist_shows_real_partial_percent(): void
    {
        [$agencyId, $agent, $suburbId] = $this->fixture();
        // Price/beds/suburb all fit (hard filters pass), but the wishlist wants
        // a nice-to-have the property lacks → the % decays below 100 (anti-Propcon:
        // partial fit shows as a real %, not a fabricated full match).
        $property = $this->property($agencyId, $agent->id, $suburbId, [
            'price' => 1_800_000, 'beds' => 3, 'features_json' => json_encode([]),
        ]);
        $buyer = $this->buyer($agencyId, $agent->id);
        $this->match($agencyId, $buyer->id, [
            'price_min' => 1_500_000, 'price_max' => 2_000_000,
            'beds_min' => 3, 'p24_suburb_ids' => [$suburbId],
            'nice_to_have_features' => ['pool'],
        ]);

        $signals = app(PropertyIntelligenceService::class)->getBuyerInterestSignals($property->id);
        $row = $signals->first();

        $this->assertNotNull($row, 'partial match still surfaces');
        $this->assertGreaterThanOrEqual(MatchingService::MIN_SCORE_TO_DISPLAY, $row['match_score']);
        $this->assertLessThan(100, $row['match_score'], 'price miss should drag the % below 100');
        $this->assertSame(MatchingService::tierFor($row['match_score']), $row['tier']);
    }

    // ── Item 1: empty wishlists excluded (gate working, not fabricated) ────

    public function test_empty_wishlist_buyer_shows_nowhere_on_intelligence_tab(): void
    {
        [$agencyId, $agent, $suburbId] = $this->fixture();
        $property = $this->property($agencyId, $agent->id, $suburbId, ['price' => 1_800_000, 'beds' => 3]);
        $buyer = $this->buyer($agencyId, $agent->id);
        $this->match($agencyId, $buyer->id, []); // empty / uncountable

        $signals = app(PropertyIntelligenceService::class)->getBuyerInterestSignals($property->id);

        $this->assertTrue($signals->isEmpty(), 'empty-wishlist buyer must NOT surface (no fabricated 75)');
    }

    public function test_non_matching_buyer_excluded(): void
    {
        [$agencyId, $agent, $suburbId] = $this->fixture();
        $property = $this->property($agencyId, $agent->id, $suburbId, ['price' => 1_800_000, 'beds' => 3]);
        $buyer = $this->buyer($agencyId, $agent->id);
        // Wishlist for a totally different (much cheaper) bracket → hard-filtered out.
        $this->match($agencyId, $buyer->id, ['price_min' => 100_000, 'price_max' => 400_000]);

        $signals = app(PropertyIntelligenceService::class)->getBuyerInterestSignals($property->id);

        $this->assertTrue($signals->isEmpty(), 'a buyer whose criteria the property cannot satisfy is excluded');
    }

    // ── Item 1: one buyer with multiple wishlists counts once ─────────────

    public function test_buyer_with_two_matching_wishlists_counted_once(): void
    {
        [$agencyId, $agent, $suburbId] = $this->fixture();
        $property = $this->property($agencyId, $agent->id, $suburbId, ['price' => 1_800_000, 'beds' => 3]);
        $buyer = $this->buyer($agencyId, $agent->id);
        $this->match($agencyId, $buyer->id, ['price_min' => 1_500_000, 'price_max' => 2_000_000, 'p24_suburb_ids' => [$suburbId]]);
        $this->match($agencyId, $buyer->id, ['price_min' => 1_700_000, 'price_max' => 1_900_000, 'beds_min' => 3]);

        $signals = app(PropertyIntelligenceService::class)->getBuyerInterestSignals($property->id);

        $this->assertCount(1, $signals, 'a buyer with multiple matching wishlists is one buyer, counted once');
    }

    // ── Item 2: MIC cached path reflects real countable buyers ────────────

    public function test_mic_cached_match_reflects_countable_buyer_and_excludes_empty(): void
    {
        [$agencyId, $agent, $suburbId] = $this->fixture();
        $property = $this->property($agencyId, $agent->id, $suburbId, ['price' => 1_800_000, 'beds' => 3]);

        $countableBuyer = $this->buyer($agencyId, $agent->id);
        $this->match($agencyId, $countableBuyer->id, [
            'price_min' => 1_500_000, 'price_max' => 2_000_000, 'beds_min' => 3, 'p24_suburb_ids' => [$suburbId],
        ]);

        $emptyBuyer = $this->buyer($agencyId, $agent->id);
        $this->match($agencyId, $emptyBuyer->id, []); // uncountable

        $engineB = app(PropertyMatchScoringService::class);
        $engineB->recomputeForBuyer($countableBuyer->id);
        $engineB->recomputeForBuyer($emptyBuyer->id);

        $cached = DB::table('property_buyer_matches')->where('property_id', $property->id)->get();

        $this->assertCount(1, $cached, 'only the countable buyer is cached (MIC reflects real buyers)');
        $this->assertSame($countableBuyer->id, (int) $cached->first()->contact_id);
        $this->assertGreaterThanOrEqual(PropertyMatchScoringService::MIN_SCORE_TO_CACHE, (int) $cached->first()->score);
    }

    // ── Item 3: tier vocabulary consistent with Path 1 (strong >= 80) ─────

    public function test_engine_b_tier_strong_aligned_to_canonical_eighty(): void
    {
        $svc = app(PropertyMatchScoringService::class);
        $ref = new \ReflectionMethod($svc, 'determineTier');
        $ref->setAccessible(true);
        $tier = fn (int $s) => $ref->invoke($svc, $s);

        // Boundary == canonical TIER_STRONG_MIN (80).
        $this->assertSame('strong', $tier(MatchingService::TIER_STRONG_MIN));      // 80
        $this->assertSame('approximate', $tier(MatchingService::TIER_STRONG_MIN - 1)); // 79
        // A 75 was 'strong' before AT-73 — now correctly 'approximate'.
        $this->assertSame('approximate', $tier(75));
        $this->assertSame('strong', $tier(85));
        $this->assertSame('perfect', $tier(95));
        $this->assertSame('none', $tier(40));
    }

    public function test_canonical_tierfor_thresholds(): void
    {
        $this->assertSame('strong', MatchingService::tierFor(80));
        $this->assertSame('good', MatchingService::tierFor(65));
        $this->assertSame('fair', MatchingService::tierFor(50));
        $this->assertNull(MatchingService::tierFor(49), 'below 50 is not a match');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @return array{0:int,1:User,2:int} [agencyId, agent, p24SuburbId] */
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

    private function buyer(int $agencyId, int $agentId): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'created_by_user_id' => $agentId, 'agent_id' => $agentId,
            'is_buyer' => true, 'buyer_state' => 'new',
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
            'slug' => 'uvongo-' . Str::random(5), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
