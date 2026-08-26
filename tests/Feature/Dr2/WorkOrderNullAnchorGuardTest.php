<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Deal;
use App\Models\DealV2\AgencyServiceType;
use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealStepWorkOrder;
use App\Models\User;
use App\Services\Deal\Dr1PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Demo-seed defect: cocConfigSave inserted a deal_step_work_orders row with a NULL
 * deal_step_instance_id (SQLSTATE 1048) when a ticked COC had neither a matching pipeline step nor a
 * granting-trigger step to anchor to — crashing the whole save. The guard skips such a COC (reports it
 * as `unanchored`) instead of crashing; a COC WITH a granting trigger still anchors + is created.
 */
final class WorkOrderNullAnchorGuardTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(6), 'slug' => 'coastal-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin', 'is_active' => true,
        ]);
        AgencyServiceType::seedDefaultsFor($this->agencyId); // COC, Beetle, Gas, Electric Fence, ...
        $this->actingAs($this->admin);
    }

    public function test_ticking_a_coc_with_no_anchorable_step_is_skipped_not_crashed(): void
    {
        // Pipeline has an Electrical COC step but NO Gas step and NO granting-trigger step.
        $deal = $this->deal(withGrantTrigger: false);

        $resp = $this->postJson(route('deals-dr2.pipeline.coc-config.save', $deal), [
            'items' => [['code' => 'Gas', 'applies' => true, 'responsible_party' => 'supplier', 'service_provider_id' => 6]],
        ]);

        $resp->assertOk()->assertJsonPath('ok', true);
        $this->assertNotEmpty($resp->json('unanchored'), 'the unanchorable Gas COC is reported');
        $this->assertSame(0, DealStepWorkOrder::where('dr1_deal_id', $deal->id)->where('service_type', 'Gas')->count(),
            'no work order is created, and — crucially — no null deal_step_instance_id insert (1048)');
    }

    public function test_a_coc_anchors_to_the_granting_trigger_step_when_present(): void
    {
        $deal = $this->deal(withGrantTrigger: true);

        $resp = $this->postJson(route('deals-dr2.pipeline.coc-config.save', $deal), [
            'items' => [['code' => 'Gas', 'applies' => true, 'responsible_party' => 'supplier', 'service_provider_id' => 6]],
        ]);

        $resp->assertOk();
        $this->assertEmpty($resp->json('unanchored'));
        $wo = DealStepWorkOrder::where('dr1_deal_id', $deal->id)->where('service_type', 'Gas')->firstOrFail();
        $this->assertNotNull($wo->deal_step_instance_id, 'anchored to the granting trigger step');
    }

    private function deal(bool $withGrantTrigger): Deal
    {
        $deal = Deal::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'period' => '2026-03',
            'deal_date' => '2026-03-01', 'property_value' => 2_150_000, 'total_commission' => 107_500,
            'buyer_name' => 'Thandi Mkhize', 'accepted_status' => 'P',
        ]);

        $template = DealPipelineTemplate::create([
            'name' => 'Bond', 'deal_type' => 'bond', 'agency_id' => $this->agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $this->admin->id,
        ]);
        // A granting milestone (only when asked) + a live COC step that is NOT Gas.
        $stepDefs = [];
        if ($withGrantTrigger) {
            $stepDefs[] = ['pos' => 1, 'name' => 'Bond Approved', 'trigger' => 'on_creation', 'status_trigger' => 'granted'];
        }
        $stepDefs[] = ['pos' => 2, 'name' => 'Electrical COC', 'trigger' => 'on_creation', 'status_trigger' => null];

        foreach ($stepDefs as $d) {
            DealPipelineStep::create([
                'pipeline_template_id' => $template->id, 'agency_id' => $this->agencyId,
                'position' => $d['pos'], 'name' => $d['name'], 'is_locked' => false, 'is_milestone' => false,
                'completion_type' => 'date_input', 'trigger_type' => $d['trigger'], 'days_offset' => 0,
                'rag_amber_days' => 7, 'rag_red_days' => 3, 'status_trigger' => $d['status_trigger'],
                'notify_agent' => false, 'notify_bm' => false, 'notify_admin' => false,
            ]);
        }

        app(Dr1PipelineService::class)->createPipeline($deal, $template->id, ['from_date' => '2026-03-01']);
        return $deal->fresh('pipelineSteps');
    }
}
