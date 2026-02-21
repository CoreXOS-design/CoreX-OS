<?php

namespace Tests\Feature\Presentation;

use App\Models\Branch;
use App\Models\Presentation;
use App\Models\PresentationActiveListing;
use App\Models\PresentationListingPriceHistory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C3: Competitive Threat Ranking acceptance tests.
 */
class CompetitiveThreatTest extends TestCase
{
    use RefreshDatabase;

    private User         $user;
    private Branch       $branch;
    private Presentation $presentation;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.competitive_threat_v1', true);

        $this->branch = Branch::create([
            'name'      => 'Test Branch',
            'code'      => 'TEST',
            'is_active' => true,
        ]);

        $this->user = User::factory()->create([
            'role'      => 'agent',
            'branch_id' => $this->branch->id,
        ]);

        $this->presentation = Presentation::create([
            'branch_id'          => $this->branch->id,
            'created_by_user_id' => $this->user->id,
            'title'              => 'Threat Test',
            'property_address'   => '1 Test Street',
            'suburb'             => 'Claremont',
            'property_type'      => 'house',
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);
    }

    private function createListing(array $overrides = []): PresentationActiveListing
    {
        return PresentationActiveListing::create(array_merge([
            'presentation_id' => $this->presentation->id,
            'list_price_inc'  => 2_000_000,
            'size_m2'         => 120,
            'suburb'          => 'Claremont',
            'property_type'   => 'house',
            'listing_date'    => Carbon::today()->subDays(10)->toDateString(),
            'is_active'       => true,
            'data_quality_score' => 75,
            'raw_row_json'    => '{}',
            'parser_version'  => 'test',
        ], $overrides));
    }

    // ── Contract shape ───────────────────────────────────────────────────

    public function test_returns_threats_array(): void
    {
        $this->actingAs($this->user);
        $this->createListing();

        $response = $this->postJson(
            route('presentations.competitive-threats', $this->presentation),
            ['price' => 2_000_000, 'size_m2' => 120],
        );

        $response->assertOk();
        $this->assertArrayHasKey('threats', $response->json());
    }

    public function test_threat_row_contains_expected_keys(): void
    {
        $this->actingAs($this->user);
        $this->createListing();

        $response = $this->postJson(
            route('presentations.competitive-threats', $this->presentation),
            ['price' => 2_000_000, 'size_m2' => 120],
        );

        $response->assertOk();
        $threat = $response->json('threats.0');

        $this->assertArrayHasKey('listing_id', $threat);
        $this->assertArrayHasKey('threat_score', $threat);
        $this->assertArrayHasKey('price', $threat);
        $this->assertArrayHasKey('dom_bucket', $threat);
        $this->assertArrayHasKey('price_reduction', $threat);
    }

    // ── Scoring tests ────────────────────────────────────────────────────

    public function test_closer_price_scores_higher(): void
    {
        $this->actingAs($this->user);

        $close = $this->createListing(['list_price_inc' => 2_050_000]); // within 5%
        $far   = $this->createListing(['list_price_inc' => 2_500_000]); // well outside 5%

        $response = $this->postJson(
            route('presentations.competitive-threats', $this->presentation),
            ['price' => 2_000_000, 'size_m2' => 120],
        );

        $response->assertOk();
        $threats = $response->json('threats');

        $closeScore = collect($threats)->firstWhere('listing_id', $close->id)['threat_score'];
        $farScore   = collect($threats)->firstWhere('listing_id', $far->id)['threat_score'];

        $this->assertGreaterThan($farScore, $closeScore);
    }

    public function test_fresh_listing_ranks_above_stale(): void
    {
        $this->actingAs($this->user);

        $fresh = $this->createListing([
            'listing_date' => Carbon::today()->subDays(5)->toDateString(),
            'list_price_inc' => 3_000_000, // distant price to isolate DOM effect
            'size_m2' => 500,              // distant size
        ]);
        $stale = $this->createListing([
            'listing_date' => Carbon::today()->subDays(120)->toDateString(),
            'list_price_inc' => 3_000_000,
            'size_m2' => 500,
        ]);

        $response = $this->postJson(
            route('presentations.competitive-threats', $this->presentation),
            ['price' => 2_000_000, 'size_m2' => 120],
        );

        $response->assertOk();
        $threats = $response->json('threats');

        $freshScore = collect($threats)->firstWhere('listing_id', $fresh->id)['threat_score'];
        $staleScore = collect($threats)->firstWhere('listing_id', $stale->id)['threat_score'];

        $this->assertGreaterThan($staleScore, $freshScore);
    }

    public function test_price_reduction_increases_score(): void
    {
        $this->actingAs($this->user);

        // Same price/size/dom to isolate price reduction effect
        $withReduction = $this->createListing([
            'list_price_inc' => 3_000_000,
            'size_m2' => 500,
            'listing_date' => Carbon::today()->subDays(60)->toDateString(),
        ]);
        $withoutReduction = $this->createListing([
            'list_price_inc' => 3_000_000,
            'size_m2' => 500,
            'listing_date' => Carbon::today()->subDays(60)->toDateString(),
        ]);

        // Add price history with a reduction
        PresentationListingPriceHistory::create([
            'presentation_id'    => $this->presentation->id,
            'active_listing_id'  => $withReduction->id,
            'price_inc'          => 3_200_000,
            'captured_at'        => Carbon::today()->subDays(30),
        ]);
        PresentationListingPriceHistory::create([
            'presentation_id'    => $this->presentation->id,
            'active_listing_id'  => $withReduction->id,
            'price_inc'          => 3_000_000,
            'captured_at'        => Carbon::today()->subDays(10),
        ]);

        $response = $this->postJson(
            route('presentations.competitive-threats', $this->presentation),
            ['price' => 2_000_000, 'size_m2' => 120],
        );

        $response->assertOk();
        $threats = $response->json('threats');

        $withScore    = collect($threats)->firstWhere('listing_id', $withReduction->id)['threat_score'];
        $withoutScore = collect($threats)->firstWhere('listing_id', $withoutReduction->id)['threat_score'];

        $this->assertGreaterThan($withoutScore, $withScore);
    }

    // ── Limit ────────────────────────────────────────────────────────────

    public function test_respects_limit(): void
    {
        $this->actingAs($this->user);

        for ($i = 0; $i < 10; $i++) {
            $this->createListing(['list_price_inc' => 2_000_000 + ($i * 10_000)]);
        }

        $response = $this->postJson(
            route('presentations.competitive-threats', $this->presentation),
            ['price' => 2_000_000, 'limit' => 3],
        );

        $response->assertOk();
        $this->assertCount(3, $response->json('threats'));
    }

    // ── Deterministic ────────────────────────────────────────────────────

    public function test_deterministic_ordering(): void
    {
        $this->actingAs($this->user);

        $this->createListing(['list_price_inc' => 2_050_000]);
        $this->createListing(['list_price_inc' => 2_300_000]);

        $payload = ['price' => 2_000_000, 'size_m2' => 120];

        $r1 = $this->postJson(route('presentations.competitive-threats', $this->presentation), $payload);
        $r2 = $this->postJson(route('presentations.competitive-threats', $this->presentation), $payload);

        $r1->assertOk();
        $r2->assertOk();

        $this->assertEquals($r1->json(), $r2->json());
    }

    // ── Feature flag ─────────────────────────────────────────────────────

    public function test_feature_flag_off_returns_404(): void
    {
        config()->set('features.competitive_threat_v1', false);
        $this->actingAs($this->user);

        $response = $this->postJson(
            route('presentations.competitive-threats', $this->presentation),
            ['price' => 2_000_000],
        );

        $response->assertNotFound();
    }

    // ── Auth required ────────────────────────────────────────────────────

    public function test_requires_auth(): void
    {
        $response = $this->postJson(
            route('presentations.competitive-threats', $this->presentation),
            ['price' => 2_000_000],
        );

        $response->assertUnauthorized();
    }

    // ── Empty listings ───────────────────────────────────────────────────

    public function test_empty_when_no_listings(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(
            route('presentations.competitive-threats', $this->presentation),
            ['price' => 2_000_000],
        );

        $response->assertOk();
        $this->assertEmpty($response->json('threats'));
    }
}
