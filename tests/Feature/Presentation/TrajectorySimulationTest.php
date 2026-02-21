<?php

namespace Tests\Feature\Presentation;

use App\Models\Branch;
use App\Models\MarketAnalyticsRun;
use App\Models\Presentation;
use App\Models\PresentationSnapshot;
use App\Models\SaleProbabilityRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C1: Trajectory Simulation acceptance tests.
 *
 * Validates multi-step price trajectory simulation endpoint.
 * No DB writes should occur.
 */
class TrajectorySimulationTest extends TestCase
{
    use RefreshDatabase;

    private User         $user;
    private Branch       $branch;
    private Presentation $presentation;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.trajectory_simulation_v1', true);

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
            'title'              => 'Trajectory Test',
            'property_address'   => '1 Test Street',
            'suburb'             => 'Claremont',
            'property_type'      => 'house',
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'suburb'        => 'Claremont',
            'type'          => 'house',
            'size_m2'       => 120,
            'bedrooms'      => 3,
            'period_months' => 12,
            'price_steps'   => [1_950_000, 1_890_000, 1_850_000],
        ], $overrides);
    }

    // ── Contract shape tests ─────────────────────────────────────────────

    public function test_returns_correct_contract_shape(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(
            route('presentations.simulate-trajectory', $this->presentation),
            $this->basePayload(),
        );

        $response->assertOk();
        $json = $response->json();

        $this->assertArrayHasKey('stages', $json);
        $this->assertArrayHasKey('final_cumulative_probability', $json);
        $this->assertArrayHasKey('total_holding_cost', $json);
        $this->assertArrayHasKey('total_days', $json);
        $this->assertArrayHasKey('days_per_step', $json);
    }

    public function test_stage_contains_expected_keys(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(
            route('presentations.simulate-trajectory', $this->presentation),
            $this->basePayload(),
        );

        $response->assertOk();
        $stage = $response->json('stages.0');

        $this->assertArrayHasKey('step', $stage);
        $this->assertArrayHasKey('price', $stage);
        $this->assertArrayHasKey('days_start', $stage);
        $this->assertArrayHasKey('days_end', $stage);
        $this->assertArrayHasKey('probability', $stage);
        $this->assertArrayHasKey('confidence', $stage);
        $this->assertArrayHasKey('ppi', $stage);
        $this->assertArrayHasKey('expected_days', $stage);
        $this->assertArrayHasKey('stage_holding_cost', $stage);
        $this->assertArrayHasKey('cumulative_holding_cost', $stage);
        $this->assertArrayHasKey('cumulative_probability', $stage);

        // Probability sub-keys
        $this->assertArrayHasKey('p30', $stage['probability']);
        $this->assertArrayHasKey('p60', $stage['probability']);
        $this->assertArrayHasKey('p90', $stage['probability']);
    }

    // ── Stage count matches price_steps ──────────────────────────────────

    public function test_stages_count_matches_price_steps(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(
            route('presentations.simulate-trajectory', $this->presentation),
            $this->basePayload(['price_steps' => [2_000_000, 1_900_000]]),
        );

        $response->assertOk();
        $this->assertCount(2, $response->json('stages'));
    }

    // ── Single step equals existing simulate ─────────────────────────────

    public function test_single_step_matches_simulate_p30(): void
    {
        $this->actingAs($this->user);

        // Run trajectory with single step
        $trajectoryResponse = $this->postJson(
            route('presentations.simulate-trajectory', $this->presentation),
            $this->basePayload(['price_steps' => [2_500_000]]),
        );

        // Run standard simulate with same price
        $simulateResponse = $this->postJson(
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

        $trajectoryResponse->assertOk();
        $simulateResponse->assertOk();

        $trajectoryP30 = $trajectoryResponse->json('stages.0.probability.p30');
        $simulateP30   = $simulateResponse->json('probability.p30');

        $this->assertSame($simulateP30, $trajectoryP30);
    }

    // ── Cumulative probability formula ───────────────────────────────────

    public function test_cumulative_probability_formula(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(
            route('presentations.simulate-trajectory', $this->presentation),
            $this->basePayload(),
        );

        $response->assertOk();
        $stages = $response->json('stages');

        // Verify: cumulative = 1 - product(1 - Pi)
        $cumulativeNotSold = 1.0;
        foreach ($stages as $stage) {
            $p30 = $stage['probability']['p30'];
            if ($p30 !== null) {
                $cumulativeNotSold *= (1.0 - $p30);
            }
            $expected = round(1.0 - $cumulativeNotSold, 4);
            $this->assertEquals($expected, $stage['cumulative_probability'],
                "Cumulative probability mismatch at step {$stage['step']}");
        }

        // Final cumulative matches last stage
        $lastStage = end($stages);
        $this->assertEquals($lastStage['cumulative_probability'], $response->json('final_cumulative_probability'));
    }

    // ── Holding cost accumulation ────────────────────────────────────────

    public function test_holding_cost_accumulates_correctly(): void
    {
        $this->actingAs($this->user);

        // Set holding costs on the presentation
        $this->presentation->update([
            'monthly_bond'  => 10000,
            'monthly_rates' => 2000,
        ]);

        $response = $this->postJson(
            route('presentations.simulate-trajectory', $this->presentation->fresh()),
            $this->basePayload(),
        );

        $response->assertOk();
        $stages = $response->json('stages');

        $monthlyTotal = 12000.0; // 10000 + 2000
        $daysPerStep  = 30;

        $expectedStageCost = round($monthlyTotal * $daysPerStep / 30, 2);

        // Each stage should have the same stage_holding_cost
        foreach ($stages as $i => $stage) {
            $this->assertEquals($expectedStageCost, $stage['stage_holding_cost'],
                "Stage holding cost mismatch at step {$stage['step']}");
            $this->assertEquals(
                round($expectedStageCost * ($i + 1), 2),
                $stage['cumulative_holding_cost'],
                "Cumulative holding cost mismatch at step {$stage['step']}"
            );
        }

        // Total matches final cumulative
        $this->assertEquals(
            round($expectedStageCost * count($stages), 2),
            $response->json('total_holding_cost')
        );
    }

    public function test_zero_holding_cost_when_none_set(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(
            route('presentations.simulate-trajectory', $this->presentation),
            $this->basePayload(['price_steps' => [2_000_000]]),
        );

        $response->assertOk();

        $this->assertEquals(0, $response->json('stages.0.stage_holding_cost'));
        $this->assertEquals(0, $response->json('total_holding_cost'));
    }

    // ── No DB writes ─────────────────────────────────────────────────────

    public function test_no_ma_runs_persisted(): void
    {
        $this->actingAs($this->user);

        $countBefore = MarketAnalyticsRun::count();

        $this->postJson(
            route('presentations.simulate-trajectory', $this->presentation),
            $this->basePayload(),
        )->assertOk();

        $this->assertSame($countBefore, MarketAnalyticsRun::count());
    }

    public function test_no_sp_runs_persisted(): void
    {
        $this->actingAs($this->user);

        $countBefore = SaleProbabilityRun::count();

        $this->postJson(
            route('presentations.simulate-trajectory', $this->presentation),
            $this->basePayload(),
        )->assertOk();

        $this->assertSame($countBefore, SaleProbabilityRun::count());
    }

    public function test_no_snapshots_created(): void
    {
        $this->actingAs($this->user);

        $countBefore = PresentationSnapshot::count();

        $this->postJson(
            route('presentations.simulate-trajectory', $this->presentation),
            $this->basePayload(),
        )->assertOk();

        $this->assertSame($countBefore, PresentationSnapshot::count());
    }

    // ── Deterministic ────────────────────────────────────────────────────

    public function test_deterministic_output(): void
    {
        $this->actingAs($this->user);
        $payload = $this->basePayload();

        $response1 = $this->postJson(
            route('presentations.simulate-trajectory', $this->presentation),
            $payload,
        );

        $response2 = $this->postJson(
            route('presentations.simulate-trajectory', $this->presentation),
            $payload,
        );

        $response1->assertOk();
        $response2->assertOk();

        $this->assertEquals($response1->json(), $response2->json());
    }

    // ── Feature flag ─────────────────────────────────────────────────────

    public function test_feature_flag_off_returns_404(): void
    {
        config()->set('features.trajectory_simulation_v1', false);
        $this->actingAs($this->user);

        $response = $this->postJson(
            route('presentations.simulate-trajectory', $this->presentation),
            $this->basePayload(),
        );

        $response->assertNotFound();
    }

    // ── Auth required ────────────────────────────────────────────────────

    public function test_requires_auth(): void
    {
        $response = $this->postJson(
            route('presentations.simulate-trajectory', $this->presentation),
            $this->basePayload(),
        );

        $response->assertUnauthorized();
    }

    // ── Validation ───────────────────────────────────────────────────────

    public function test_price_steps_required(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(
            route('presentations.simulate-trajectory', $this->presentation),
            $this->basePayload(['price_steps' => []]),
        );

        $response->assertUnprocessable();
    }

    // ── Custom days_per_step ─────────────────────────────────────────────

    public function test_custom_days_per_step(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(
            route('presentations.simulate-trajectory', $this->presentation),
            $this->basePayload(['days_per_step' => 60]),
        );

        $response->assertOk();
        $this->assertEquals(60, $response->json('days_per_step'));
        $this->assertEquals(0, $response->json('stages.0.days_start'));
        $this->assertEquals(60, $response->json('stages.0.days_end'));
    }

    // ── Total days ───────────────────────────────────────────────────────

    public function test_total_days_matches_steps(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(
            route('presentations.simulate-trajectory', $this->presentation),
            $this->basePayload(['price_steps' => [2_000_000, 1_900_000, 1_800_000]]),
        );

        $response->assertOk();
        $this->assertEquals(90, $response->json('total_days')); // 3 steps × 30 days
    }
}
