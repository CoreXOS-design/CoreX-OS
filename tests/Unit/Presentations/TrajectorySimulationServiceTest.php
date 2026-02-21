<?php

namespace Tests\Unit\Presentations;

use App\Models\Branch;
use App\Models\Presentation;
use App\Models\User;
use App\Services\Presentations\TrajectorySimulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C1: Unit tests for TrajectorySimulationService.
 */
class TrajectorySimulationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Presentation $presentation;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::create([
            'name'      => 'Unit Branch',
            'code'      => 'UNIT',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role'      => 'agent',
            'branch_id' => $branch->id,
        ]);

        $this->presentation = Presentation::create([
            'branch_id'          => $branch->id,
            'created_by_user_id' => $user->id,
            'title'              => 'Trajectory Unit Test',
            'property_address'   => '1 Unit Street',
            'suburb'             => 'Claremont',
            'property_type'      => 'house',
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);
    }

    private function baseInputs(): array
    {
        return [
            'suburb'        => 'Claremont',
            'type'          => 'house',
            'size_m2'       => 120,
            'bedrooms'      => 3,
            'period_months' => 12,
            'branch_id'     => $this->presentation->branch_id,
        ];
    }

    public function test_returns_stages_array(): void
    {
        $service = new TrajectorySimulationService();

        $result = $service->simulateTrajectory(
            $this->presentation,
            $this->baseInputs(),
            [2_000_000, 1_900_000],
        );

        $this->assertArrayHasKey('stages', $result);
        $this->assertCount(2, $result['stages']);
    }

    public function test_single_step_returns_one_stage(): void
    {
        $service = new TrajectorySimulationService();

        $result = $service->simulateTrajectory(
            $this->presentation,
            $this->baseInputs(),
            [2_000_000],
        );

        $this->assertCount(1, $result['stages']);
        $this->assertEquals(2_000_000, $result['stages'][0]['price']);
    }

    public function test_cumulative_probability_monotonically_increases(): void
    {
        $service = new TrajectorySimulationService();

        $result = $service->simulateTrajectory(
            $this->presentation,
            $this->baseInputs(),
            [2_000_000, 1_900_000, 1_800_000],
        );

        $prev = 0;
        foreach ($result['stages'] as $stage) {
            $this->assertGreaterThanOrEqual($prev, $stage['cumulative_probability']);
            $prev = $stage['cumulative_probability'];
        }
    }

    public function test_holding_cost_with_presentation_fields(): void
    {
        $this->presentation->update([
            'monthly_bond'  => 15000,
            'monthly_rates' => 3000,
        ]);

        $service = new TrajectorySimulationService();

        $result = $service->simulateTrajectory(
            $this->presentation->fresh(),
            $this->baseInputs(),
            [2_000_000],
            30,
        );

        // Monthly total = 18000, 30 days → 18000
        $this->assertEquals(18000.0, $result['stages'][0]['stage_holding_cost']);
        $this->assertEquals(18000.0, $result['total_holding_cost']);
    }

    public function test_custom_days_per_step(): void
    {
        $service = new TrajectorySimulationService();

        $result = $service->simulateTrajectory(
            $this->presentation,
            $this->baseInputs(),
            [2_000_000, 1_900_000],
            60,
        );

        $this->assertEquals(60, $result['days_per_step']);
        $this->assertEquals(120, $result['total_days']);
        $this->assertEquals(0, $result['stages'][0]['days_start']);
        $this->assertEquals(60, $result['stages'][0]['days_end']);
        $this->assertEquals(60, $result['stages'][1]['days_start']);
        $this->assertEquals(120, $result['stages'][1]['days_end']);
    }

    public function test_deterministic(): void
    {
        $service = new TrajectorySimulationService();
        $inputs  = $this->baseInputs();
        $prices  = [2_000_000, 1_900_000];

        $result1 = $service->simulateTrajectory($this->presentation, $inputs, $prices);
        $result2 = $service->simulateTrajectory($this->presentation, $inputs, $prices);

        $this->assertEquals($result1, $result2);
    }

    public function test_empty_price_steps_returns_empty_stages(): void
    {
        $service = new TrajectorySimulationService();

        $result = $service->simulateTrajectory(
            $this->presentation,
            $this->baseInputs(),
            [],
        );

        $this->assertEmpty($result['stages']);
        $this->assertEquals(0, $result['final_cumulative_probability']);
        $this->assertEquals(0, $result['total_holding_cost']);
    }
}
