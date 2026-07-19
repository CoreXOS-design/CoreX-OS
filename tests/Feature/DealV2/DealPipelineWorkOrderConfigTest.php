<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-229 — per-step work-order config (Q1: set in pipeline setup, no hard setting).
 * A step can be ticked to send a supplier work order at a trigger point; the config
 * round-trips through the pipeline-step controller and reaches the builder editForm.
 */
final class DealPipelineWorkOrderConfigTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $admin;
    private DealPipelineTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(6), 'slug' => 'c-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Main',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin', 'is_active' => true,
        ]);
        $this->template = DealPipelineTemplate::create([
            'name' => 'Transfer', 'deal_type' => 'transfer', 'agency_id' => $this->agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $this->admin->id,
        ]);
    }

    public function test_step_persists_work_order_config(): void
    {
        $resp = $this->actingAs($this->admin)->postJson(
            route('deals-v2.pipeline.steps.store', $this->template),
            [
                'name' => 'Electrical COC',
                'completion_type' => 'document_upload',
                'trigger_type' => 'on_creation',
                'days_offset' => 14,
                'rag_amber_days' => 7,
                'rag_red_days' => 3,
                'sends_work_order' => true,
                'work_order_service_type' => 'COC',
                'work_order_trigger_point' => 'activated',
            ]
        );

        $resp->assertOk();
        $step = DealPipelineStep::where('pipeline_template_id', $this->template->id)->firstOrFail();

        $this->assertTrue((bool) $step->sends_work_order);
        $this->assertSame('COC', $step->work_order_service_type);
        $this->assertSame('activated', $step->work_order_trigger_point);

        // The config must round-trip back to the builder (editForm reads formatStep).
        $resp->assertJsonPath('step.sends_work_order', true);
        $resp->assertJsonPath('step.work_order_service_type', 'COC');
        $resp->assertJsonPath('step.work_order_trigger_point', 'activated');
    }

    public function test_step_defaults_to_no_work_order(): void
    {
        $resp = $this->actingAs($this->admin)->postJson(
            route('deals-v2.pipeline.steps.store', $this->template),
            [
                'name' => 'FICA', 'completion_type' => 'manual_tick', 'trigger_type' => 'on_creation',
                'days_offset' => 0, 'rag_amber_days' => 5, 'rag_red_days' => 2,
            ]
        );
        $resp->assertOk();
        $step = DealPipelineStep::where('pipeline_template_id', $this->template->id)->firstOrFail();
        $this->assertFalse((bool) $step->sends_work_order, 'a step offers no work order unless ticked');
    }

    public function test_trigger_point_rejects_unknown_value(): void
    {
        $resp = $this->actingAs($this->admin)->postJson(
            route('deals-v2.pipeline.steps.store', $this->template),
            [
                'name' => 'Bad', 'completion_type' => 'manual_tick', 'trigger_type' => 'on_creation',
                'days_offset' => 0, 'rag_amber_days' => 5, 'rag_red_days' => 2,
                'sends_work_order' => true, 'work_order_trigger_point' => 'whenever',
            ]
        );
        $resp->assertStatus(422);
    }
}
