<?php

declare(strict_types=1);

namespace Tests\Feature\Mic;

use App\Http\Controllers\CoreX\MarketIntelligenceController;
use App\Models\AgencyContactSettings;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\User;
use App\Services\PropertyMatchScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-242 — MIC buyer-led prospecting + AT-239 region filter.
 *
 * Selecting a buyer must narrow the MIC prospecting universe to stock that
 * matches THAT buyer's wishlist, scored by the canonical Core Matches engine
 * (cached in prospecting_buyer_matches) — one truth, no forked matching. The
 * buyer's own score is attached per row and the default sort is strongest
 * first. The region filter narrows by the property spine (towns.region).
 *
 * These invoke the controller's work() directly (reading the returned view
 * data) so the filter/sort/attach logic is asserted without rendering the
 * full Work blade against a sparse test DB — the blades are separately
 * compile-linted.
 */
final class BuyerLedProspectingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AgencyContactSettings::clearMinCountableCache();
    }

    public function test_buyer_selector_narrows_to_that_buyers_matched_stock_and_attaches_score(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id, 'Ann');
        $this->match($agencyId, $buyer->id, [
            'price_min' => 700_000, 'price_max' => 900_000, 'beds_min' => 2, 'property_types' => ['House'],
        ]);

        $inBand  = $this->listing($agencyId, $agent->id, ['price' => 800_000,   'bedrooms' => 2, 'property_type' => 'House']);
        $outBand = $this->listing($agencyId, $agent->id, ['price' => 3_000_000, 'bedrooms' => 2, 'property_type' => 'House']);

        app(PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($buyer->id);

        $ids = $this->workListingIds($agent, ['buyer_id' => $buyer->id]);

        $this->assertContains($inBand, $ids, 'the buyer-matched in-band listing is shown');
        $this->assertNotContains($outBand, $ids, 'a wildly out-of-band listing (no cached match) is excluded in buyer mode');

        // The selected buyer's own cached score is attached to the row.
        $rows = $this->workRows($agent, ['buyer_id' => $buyer->id]);
        $row = $rows->firstWhere('id', $inBand);
        $this->assertNotNull($row->selected_buyer_score ?? null, 'the buyer-specific score is attached');
        $this->assertGreaterThanOrEqual(75, (int) $row->selected_buyer_score);
    }

    public function test_listing_below_agency_match_threshold_is_excluded_from_buyer_mode(): void
    {
        [$agencyId, $agent] = $this->fixture(); // default mic_match_threshold = 75
        $buyer = $this->buyer($agencyId, $agent->id, 'Bea');
        $this->match($agencyId, $buyer->id, ['price_min' => 700_000, 'price_max' => 900_000, 'beds_min' => 2, 'property_types' => ['House']]);

        // A drifted listing scores below the 75 threshold (see MicCanonicalScoringTest).
        $drifted = $this->listing($agencyId, $agent->id, ['price' => 1_200_000, 'bedrooms' => 2, 'property_type' => 'House']);

        app(PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($buyer->id);

        // Confirm the cache holds it below threshold (guards the premise).
        $score = (int) DB::table('prospecting_buyer_matches')
            ->where('contact_id', $buyer->id)->where('prospecting_listing_id', $drifted)->value('score');
        $this->assertLessThan(75, $score, 'premise: this listing scores below the agency threshold');

        $ids = $this->workListingIds($agent, ['buyer_id' => $buyer->id]);
        $this->assertNotContains($drifted, $ids, 'a below-threshold match is not surfaced for the buyer');
    }

    public function test_region_filter_narrows_by_town_region(): void
    {
        [$agencyId, $agent] = $this->fixture();

        // Two towns in different regions; each maps a suburb.
        $southTown = $this->town($agencyId, 'Margate', 'Lower South Coast');
        $this->townSuburb($agencyId, $southTown, 'Uvongo');
        $northTown = $this->town($agencyId, 'Amanzimtoti', 'Deep South Durban');
        $this->townSuburb($agencyId, $northTown, 'Toti');

        $inRegion  = $this->listing($agencyId, $agent->id, ['suburb' => 'Uvongo']);
        $outRegion = $this->listing($agencyId, $agent->id, ['suburb' => 'Toti']);

        $ids = $this->workListingIds($agent, ['region' => 'Lower South Coast']);

        $this->assertContains($inRegion, $ids, 'a listing in the region suburb is shown');
        $this->assertNotContains($outRegion, $ids, 'a listing outside the region is excluded');
    }

    public function test_buyer_and_region_compose_with_and_semantics(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id, 'Cara');
        $this->match($agencyId, $buyer->id, ['price_min' => 700_000, 'price_max' => 900_000, 'beds_min' => 2, 'property_types' => ['House']]);

        $town = $this->town($agencyId, 'Margate', 'Lower South Coast');
        $this->townSuburb($agencyId, $town, 'Uvongo');

        // Matches the buyer AND in-region → shown.
        $both = $this->listing($agencyId, $agent->id, ['price' => 800_000, 'bedrooms' => 2, 'property_type' => 'House', 'suburb' => 'Uvongo']);
        // Matches the buyer but OUT of region → excluded when region also applied.
        $buyerOnly = $this->listing($agencyId, $agent->id, ['price' => 810_000, 'bedrooms' => 2, 'property_type' => 'House', 'suburb' => 'Toti']);

        app(PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($buyer->id);

        $ids = $this->workListingIds($agent, ['buyer_id' => $buyer->id, 'region' => 'Lower South Coast']);

        $this->assertContains($both, $ids, 'buyer-matched AND in-region listing is shown');
        $this->assertNotContains($buyerOnly, $ids, 'buyer-matched but out-of-region listing is excluded (AND semantics)');
    }

    // ── Harness ───────────────────────────────────────────────────────────

    /** Invoke work() with the given query params; return the paginated listing ids. */
    private function workListingIds(User $agent, array $params): array
    {
        return $this->workRows($agent, $params)->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    private function workRows(User $agent, array $params): \Illuminate\Support\Collection
    {
        $request = Request::create(route('market-intelligence.work'), 'GET', $params);
        $request->setUserResolver(fn () => $agent);

        $view = app()->call([app(MarketIntelligenceController::class), 'work'], ['request' => $request]);
        $listings = $view->getData()['listings'];

        return collect($listings->items());
    }

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

    private function buyer(int $agencyId, int $agentId, string $first): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'created_by_user_id' => $agentId,
            'is_buyer' => true, 'buyer_state' => 'new',
            'first_name' => $first, 'last_name' => 'Buyer ' . Str::random(3),
            'phone' => '082' . random_int(1000000, 9999999),
            'email' => strtolower($first) . '-' . Str::random(5) . '@e.co.za',
        ]);
    }

    private function match(int $agencyId, int $contactId, array $extra): ContactMatch
    {
        return ContactMatch::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $agencyId, 'contact_id' => $contactId,
            'status' => ContactMatch::STATUS_ACTIVE, 'listing_type' => 'sale',
        ], $extra));
    }

    private function listing(int $agencyId, int $agentId, array $extra): int
    {
        return (int) DB::table('prospecting_listings')->insertGetId(array_merge([
            'agency_id' => $agencyId, 'captured_by_user_id' => $agentId,
            'portal_source' => 'p24', 'portal_ref' => 'ref-' . Str::random(8),
            'portal_url' => 'https://example.com/' . Str::random(6),
            'address' => Str::random(6) . ' Test Road', 'suburb' => 'Uvongo',
            'price' => 800_000, 'bedrooms' => 2, 'property_type' => 'House',
            'is_active' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function town(int $agencyId, string $name, string $region): int
    {
        return (int) DB::table('towns')->insertGetId([
            'agency_id' => $agencyId, 'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(4),
            'region' => $region,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function townSuburb(int $agencyId, int $townId, string $suburb): void
    {
        DB::table('town_suburbs')->insert([
            'agency_id' => $agencyId, 'town_id' => $townId,
            'suburb_name' => $suburb, 'suburb_normalised' => strtolower(trim($suburb)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
