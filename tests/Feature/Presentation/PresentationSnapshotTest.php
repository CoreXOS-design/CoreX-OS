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

class PresentationSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(): User
    {
        return User::factory()->create();
    }

    private function createBranch(): Branch
    {
        return Branch::create(['name' => 'Test Branch', 'code' => 'TB']);
    }

    private function createPresentation(Branch $branch, User $user): Presentation
    {
        return Presentation::create([
            'agency_id'          => \App\Models\Agency::firstOrCreate(['slug' => 'test-agency'], ['name' => 'Test Agency'])->id,
            'branch_id'          => $branch->id,
            'created_by_user_id' => $user->id,
            'title'              => 'Test Presentation',
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);
    }

    private function createMaRun(User $user): MarketAnalyticsRun
    {
        return MarketAnalyticsRun::create([
            'model_version'  => 'v1.0.0',
            'inputs_hash'    => sha1('test-ma-inputs'),
            'inputs_json'    => json_encode(['suburb' => 'Ballito', 'type' => 'house']),
            'outputs_json'   => json_encode(['median_sale_price' => 2500000]),
            'breakdown_json' => json_encode([]),
            'created_by'     => $user->id,
        ]);
    }

    private function createSpRun(MarketAnalyticsRun $maRun, User $user): SaleProbabilityRun
    {
        return SaleProbabilityRun::create([
            'market_analytics_run_id'        => $maRun->id,
            'market_analytics_model_version' => 'v1.0.0',
            'market_analytics_inputs_hash'   => sha1('test-ma-inputs'),
            'model_version'                  => 'prob-v1.0.0',
            'inputs_hash'                    => sha1('test-sp-inputs'),
            'inputs_json'                    => json_encode(['market_analytics_run_id' => $maRun->id]),
            'outputs_json'                   => json_encode(['p30' => 0.4, 'p60' => 0.65, 'p90' => 0.85, 'expected_days' => 55]),
            'breakdown_json'                 => json_encode(['signals' => []]),
            'data_sources_json'              => json_encode([]),
            'created_by'                     => $user->id,
        ]);
    }

    /** Test 1: User can save a snapshot */
    public function test_can_save_snapshot(): void
    {
        $user         = $this->createUser();
        $branch       = $this->createBranch();
        $presentation = $this->createPresentation($branch, $user);
        $maRun        = $this->createMaRun($user);
        $spRun        = $this->createSpRun($maRun, $user);

        $inputsJson  = json_encode(['suburb' => 'Ballito', 'type' => 'house', 'period_months' => 12]);
        $outputJson  = json_encode(['p30' => 0.4, 'p60' => 0.65, 'p90' => 0.85, 'expected_days' => 55]);

        $response = $this->actingAs($user)->post(
            route('presentations.snapshots.save', $presentation),
            [
                'market_run_id'       => $maRun->id,
                'prob_run_id'         => $spRun->id,
                'inputs_json'         => $inputsJson,
                'output_summary_json' => $outputJson,
            ]
        );

        $this->assertDatabaseHas('presentation_snapshots', [
            'presentation_id'         => $presentation->id,
            'market_analytics_run_id' => $maRun->id,
            'sale_probability_run_id' => $spRun->id,
            'created_by_user_id'      => $user->id,
        ]);

        // Should redirect to snapshot show route
        $snapshot = PresentationSnapshot::where('presentation_id', $presentation->id)->first();
        $response->assertRedirect(route('presentations.snapshots.show', [$presentation, $snapshot]));
    }

    /** Test 2: Snapshot belongs to its presentation — wrong presentation returns 404 */
    public function test_snapshot_belongs_to_presentation(): void
    {
        $user          = $this->createUser();
        $branch        = $this->createBranch();
        $presentation1 = $this->createPresentation($branch, $user);
        $presentation2 = $this->createPresentation($branch, $user);
        $maRun         = $this->createMaRun($user);
        $spRun         = $this->createSpRun($maRun, $user);

        // Snapshot belongs to presentation1
        $snapshot = PresentationSnapshot::create([
            'presentation_id'         => $presentation1->id,
            'generated_by_user_id'    => $user->id,
            'created_by_user_id'      => $user->id,
            'market_analytics_run_id' => $maRun->id,
            'sale_probability_run_id' => $spRun->id,
            'inputs_json'             => json_encode(['suburb' => 'Ballito']),
            'output_summary_json'     => json_encode(['p60' => 0.65]),
            'snapshot_json'           => '{}',
            'generated_at'            => now(),
        ]);

        // Access via presentation2 should 404
        $response = $this->actingAs($user)->get(
            route('presentations.snapshots.show', [$presentation2, $snapshot])
        );

        $response->assertStatus(404);
    }

    /** Test 3: Snapshot page renders with 200 status */
    public function test_snapshot_renders(): void
    {
        $user         = $this->createUser();
        $branch       = $this->createBranch();
        $presentation = $this->createPresentation($branch, $user);
        $maRun        = $this->createMaRun($user);
        $spRun        = $this->createSpRun($maRun, $user);

        $snapshot = PresentationSnapshot::create([
            'presentation_id'         => $presentation->id,
            'generated_by_user_id'    => $user->id,
            'created_by_user_id'      => $user->id,
            'market_analytics_run_id' => $maRun->id,
            'sale_probability_run_id' => $spRun->id,
            'inputs_json'             => json_encode(['suburb' => 'Ballito', 'type' => 'house', 'period_months' => 12]),
            'output_summary_json'     => json_encode(['p30' => 0.4, 'p60' => 0.65, 'p90' => 0.85, 'expected_days' => 55]),
            'snapshot_json'           => '{}',
            'generated_at'            => now(),
        ]);

        $response = $this->actingAs($user)->get(
            route('presentations.snapshots.show', [$presentation, $snapshot])
        );

        $response->assertStatus(200);
        $response->assertSee('Snapshot #' . $snapshot->id);
        $response->assertSee('Sale Probability at Your Price'); // summary hero rendered
    }
}
