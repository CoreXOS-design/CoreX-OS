<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealStepInstance;
use App\Models\DealV2\DealV2;
use App\Models\Property;
use App\Models\User;
use App\Services\DealV2\DealPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-158 — complete-with-reason (anti-gaming escape valve). An agent may mark a
 * step complete WITHOUT its normal requirement, but only with a structured
 * reason; met-requirements completion stays frictionless. Scope-gated (own deals
 * for agents; BM/admin any deal in scope).
 */
final class DealStepCompleteWithReasonTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $agentA;
    private DealPipelineTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(6), 'slug' => 'c-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->agentA = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true]);
        $this->template = DealPipelineTemplate::create([
            'name' => 'Bond', 'deal_type' => 'bond', 'agency_id' => $this->agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $this->agentA->id,
        ]);
        // step1 manual_tick (frictionless), step2 document_upload (has a requirement).
        DealPipelineStep::create(['pipeline_template_id' => $this->template->id, 'position' => 1, 'name' => 'Kickoff',
            'is_milestone' => true, 'completion_type' => 'manual_tick', 'trigger_type' => 'on_creation', 'days_offset' => 5, 'rag_amber_days' => 7, 'rag_red_days' => 3]);
        DealPipelineStep::create(['pipeline_template_id' => $this->template->id, 'position' => 2, 'name' => 'FICA Docs',
            'is_milestone' => false, 'completion_type' => 'document_upload', 'trigger_type' => 'on_creation', 'days_offset' => 10, 'rag_amber_days' => 7, 'rag_red_days' => 3]);
    }

    public function test_doc_step_without_document_and_without_reason_is_rejected(): void
    {
        $step = $this->docStep($this->makeDeal($this->agentA));

        $resp = $this->actingAs($this->agentA)->post(route('deals-v2.steps.complete', $step), ['outcome' => 'positive']);

        $resp->assertRedirect();
        $resp->assertSessionHasErrors('reason');
        $this->assertSame('active', $step->fresh()->status, 'step must NOT complete without a reason');
    }

    public function test_doc_step_completes_with_a_reason_and_is_stamped(): void
    {
        $step = $this->docStep($this->makeDeal($this->agentA));

        $resp = $this->actingAs($this->agentA)->post(route('deals-v2.steps.complete', $step), [
            'outcome' => 'positive',
            'reason_category' => 'document_filed_elsewhere',
            'reason' => 'Original FICA already on the buyer contact from a prior deal.',
        ]);

        $resp->assertRedirect();
        $step->refresh();
        $this->assertSame('completed', $step->status);
        $this->assertTrue((bool) data_get($step->completion_data, 'completed_with_reason'));
        $this->assertSame('document_filed_elsewhere', data_get($step->completion_data, 'reason_category'));
        // Audit: the reason is on the deal timeline for BM oversight.
        $this->assertDatabaseHas('deal_activity_log', [
            'deal_step_instance_id' => $step->id, 'action' => 'step_completed',
        ]);
        $log = DB::table('deal_activity_log')->where('deal_step_instance_id', $step->id)->where('action', 'step_completed')->latest('id')->first();
        $this->assertStringContainsString('WITHOUT its requirement', $log->description);
    }

    public function test_met_requirement_completes_frictionless_without_a_reason(): void
    {
        $deal = $this->makeDeal($this->agentA);
        $manual = $deal->stepInstances()->where('completion_type', 'manual_tick')->firstOrFail();

        $resp = $this->actingAs($this->agentA)->post(route('deals-v2.steps.complete', $manual), ['outcome' => 'positive']);

        $resp->assertRedirect();
        $resp->assertSessionHasNoErrors();
        $manual->refresh();
        $this->assertSame('completed', $manual->status);
        $this->assertNull(data_get($manual->completion_data, 'completed_with_reason'));
    }

    public function test_scope_gate_agent_cannot_complete_another_agents_deal_step(): void
    {
        $agentB = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true]);
        $stepOnBsDeal = $this->docStep($this->makeDeal($agentB));

        // Agent A (scope 'own') may not touch agent B's deal step.
        $this->actingAs($this->agentA)
            ->post(route('deals-v2.steps.complete', $stepOnBsDeal), [
                'outcome' => 'positive', 'reason_category' => 'not_applicable', 'reason' => 'x',
            ])
            ->assertForbidden();
        $this->assertSame('active', $stepOnBsDeal->fresh()->status);
    }

    private function docStep(DealV2 $deal): DealStepInstance
    {
        return $deal->stepInstances()->where('completion_type', 'document_upload')->firstOrFail();
    }

    private function makeDeal(User $agent): DealV2
    {
        $property = Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . Str::random(8), 'title' => '12 Marine Dr', 'address' => '12 Marine Dr, Margate',
            'agent_id' => $agent->id, 'branch_id' => $this->agencyId, 'agency_id' => $this->agencyId,
        ]));

        return app(DealPipelineService::class)->createDeal([
            'deal_type' => 'bond', 'property_id' => $property->id, 'listing_agent_id' => $agent->id,
            'pipeline_template_id' => $this->template->id, 'purchase_price' => 1_850_000,
            'commission_amount' => 92_500, 'commission_vat' => 13_875, 'offer_date' => now()->toDateString(),
            'branch_id' => $this->agencyId, 'created_by_id' => $agent->id,
            'agents' => [['side' => 'listing', 'user_id' => $agent->id]],
        ]);
    }
}
