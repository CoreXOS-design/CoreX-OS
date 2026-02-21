<?php

namespace Tests\Feature\Presentation;

use App\Models\Branch;
use App\Models\Presentation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests the P10 simulate endpoint contract.
 *
 * The simulate endpoint is tested with minimal inputs.
 * No DB writes should occur (persist: false throughout).
 */
class SimulateContractTest extends TestCase
{
    use RefreshDatabase;

    private User         $user;
    private Branch       $branch;
    private Presentation $presentation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'name'     => 'Test Branch',
            'code'     => 'TEST',
            'is_active'=> true,
        ]);

        $this->user = User::factory()->create([
            'role'      => 'agent',
            'branch_id' => $this->branch->id,
        ]);

        $this->presentation = Presentation::create([
            'branch_id'          => $this->branch->id,
            'created_by_user_id' => $this->user->id,
            'title'              => 'Simulate Contract Test',
            'property_address'   => '1 Test Street',
            'suburb'             => 'Claremont',
            'property_type'      => 'house',
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);
    }

    public function test_simulate_returns_p10_contract_shape(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(
            route('presentations.simulate', $this->presentation),
            [
                'suburb'        => 'Claremont',
                'type'          => 'house',
                'price'         => 2_500_000,
                'size_m2'       => 120,
                'bedrooms'      => 3,
                'period_months' => 12,
            ],
        );

        $response->assertOk();

        $json = $response->json();

        // Required top-level keys
        $this->assertArrayHasKey('price_tested', $json);
        $this->assertArrayHasKey('probability', $json);
        $this->assertArrayHasKey('expected_days', $json);
        $this->assertArrayHasKey('competitive_position', $json);
        $this->assertArrayHasKey('stock_pressure_index', $json);
        $this->assertArrayHasKey('absorption_rate', $json);
        $this->assertArrayHasKey('data_sources', $json);

        // Probability sub-keys
        $this->assertArrayHasKey('p30', $json['probability']);
        $this->assertArrayHasKey('p60', $json['probability']);
        $this->assertArrayHasKey('p90', $json['probability']);

        // Competitive position sub-keys
        $this->assertArrayHasKey('below_count', $json['competitive_position']);
        $this->assertArrayHasKey('above_count', $json['competitive_position']);
        $this->assertArrayHasKey('percentile_position', $json['competitive_position']);
    }

    public function test_simulate_price_tested_matches_input(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(
            route('presentations.simulate', $this->presentation),
            [
                'suburb'        => 'Claremont',
                'type'          => 'house',
                'price'         => 3_000_000,
                'period_months' => 12,
            ],
        );

        $response->assertOk();
        $this->assertEquals(3_000_000, $response->json('price_tested'));
    }

    public function test_simulate_requires_auth(): void
    {
        $response = $this->postJson(
            route('presentations.simulate', $this->presentation),
            ['suburb' => 'Claremont', 'type' => 'house', 'period_months' => 12],
        );

        $response->assertUnauthorized();
    }

    public function test_simulate_does_not_persist_ma_run(): void
    {
        $this->actingAs($this->user);

        $countBefore = \App\Models\MarketAnalyticsRun::count();

        $this->postJson(
            route('presentations.simulate', $this->presentation),
            [
                'suburb'        => 'Claremont',
                'type'          => 'house',
                'price'         => 2_000_000,
                'period_months' => 12,
            ],
        )->assertOk();

        $this->assertSame($countBefore, \App\Models\MarketAnalyticsRun::count());
    }

    public function test_simulate_does_not_persist_sp_run(): void
    {
        $this->actingAs($this->user);

        $countBefore = \App\Models\SaleProbabilityRun::count();

        $this->postJson(
            route('presentations.simulate', $this->presentation),
            [
                'suburb'        => 'Claremont',
                'type'          => 'house',
                'price'         => 2_000_000,
                'period_months' => 12,
            ],
        )->assertOk();

        $this->assertSame($countBefore, \App\Models\SaleProbabilityRun::count());
    }

    public function test_simulate_null_price_gives_null_price_tested(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(
            route('presentations.simulate', $this->presentation),
            [
                'suburb'        => 'Claremont',
                'type'          => 'house',
                'period_months' => 12,
            ],
        );

        $response->assertOk();
        $this->assertNull($response->json('price_tested'));
    }

    // ── P14: snapshot persistence (feature-flagged) ───────────────────────────

    public function test_simulate_flag_off_writes_no_snapshot(): void
    {
        config()->set('features.presentation_simulate_snapshot', false);

        $this->actingAs($this->user);

        $countBefore = \App\Models\PresentationSnapshot::count();

        $this->postJson(
            route('presentations.simulate', $this->presentation),
            [
                'suburb'        => 'Claremont',
                'type'          => 'house',
                'price'         => 2_000_000,
                'period_months' => 12,
            ],
        )->assertOk();

        $this->assertSame($countBefore, \App\Models\PresentationSnapshot::count());
    }

    public function test_simulate_flag_on_creates_exactly_one_snapshot(): void
    {
        config()->set('features.presentation_simulate_snapshot', true);

        $this->actingAs($this->user);

        $countBefore = \App\Models\PresentationSnapshot::count();

        $this->postJson(
            route('presentations.simulate', $this->presentation),
            [
                'suburb'        => 'Claremont',
                'type'          => 'house',
                'price'         => 2_000_000,
                'period_months' => 12,
            ],
        )->assertOk();

        $this->assertSame($countBefore + 1, \App\Models\PresentationSnapshot::count());
    }

    public function test_simulate_snapshot_contains_expected_output_keys(): void
    {
        config()->set('features.presentation_simulate_snapshot', true);

        $this->actingAs($this->user);

        $this->postJson(
            route('presentations.simulate', $this->presentation),
            [
                'suburb'        => 'Claremont',
                'type'          => 'house',
                'price'         => 2_000_000,
                'period_months' => 12,
            ],
        )->assertOk();

        $snapshot = \App\Models\PresentationSnapshot::where('presentation_id', $this->presentation->id)
            ->latest()
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertNull($snapshot->market_analytics_run_id);
        $this->assertNull($snapshot->sale_probability_run_id);

        $outputs = $snapshot->getOutputSummaryArray();
        $this->assertArrayHasKey('p30', $outputs);
        $this->assertArrayHasKey('p60', $outputs);
        $this->assertArrayHasKey('p90', $outputs);
        $this->assertArrayHasKey('confidence', $outputs);
        $this->assertArrayHasKey('explainability', $outputs);
        $this->assertArrayHasKey('ppi', $outputs);
    }

    public function test_simulate_snapshot_flag_on_no_ma_or_sp_runs_created(): void
    {
        config()->set('features.presentation_simulate_snapshot', true);

        $this->actingAs($this->user);

        $maBefore = \App\Models\MarketAnalyticsRun::count();
        $spBefore = \App\Models\SaleProbabilityRun::count();

        $this->postJson(
            route('presentations.simulate', $this->presentation),
            [
                'suburb'        => 'Claremont',
                'type'          => 'house',
                'price'         => 2_000_000,
                'period_months' => 12,
            ],
        )->assertOk();

        $this->assertSame($maBefore, \App\Models\MarketAnalyticsRun::count());
        $this->assertSame($spBefore, \App\Models\SaleProbabilityRun::count());
    }
}
