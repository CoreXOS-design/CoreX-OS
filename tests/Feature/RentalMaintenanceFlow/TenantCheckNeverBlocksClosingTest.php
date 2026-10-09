<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\RentalFaultReport;
use App\Models\RentalJobCard;
use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\Rentals\RentalCommandCentreService;
use App\Services\Rentals\RentalCompletionService;
use App\Services\Rentals\RentalJobCardService;
use App\Services\Rentals\RentalSecureAccessTokenService;
use App\Services\Rentals\RentalWorkOrderClientViewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsCompletionFlowWorld;
use Tests\TestCase;

/**
 * Johan, 9 Oct 2026 (T1): "agent can close on word and evidence from the crew or contractor" - the tenant's check is an optional record,
 * never a holding stage. rental-work-orders.md §17.37.
 */
final class TenantCheckNeverBlocksClosingTest extends TestCase
{
    use BuildsCompletionFlowWorld;
    use RefreshDatabase;

    private RentalWorkOrder $workOrder;
    private RentalJobCard $card;
    private RentalFaultReport $fault;
    private RentalWorkCompletionRound $round;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFlowWorld();
        $this->app->instance(NotificationDispatcher::class, \Mockery::spy(NotificationDispatcher::class));

        [$this->workOrder, $this->card] = $this->internalJob();
        $this->fault = $this->faultReport(['status' => RentalFaultReport::STATUS_WORK_ORDER_RAISED, 'rental_work_order_id' => $this->workOrder->id]);
        $this->workOrder->forceFill(['reported_fault_report_id' => $this->fault->id])->save();

        $raw = app(RentalSecureAccessTokenService::class)->issueForJobCard($this->card, $this->admin)['raw_token'];
        $this->post("/secure/job-cards/{$raw}/complete", ['full_name' => 'Sipho Dlamini', 'confirm' => '1'])->assertSessionHasNoErrors();
        $this->round = RentalWorkCompletionRound::withoutGlobalScopes()->where('rental_work_order_id', $this->workOrder->id)->orderByDesc('round_no')->firstOrFail();
    }

    private function agentCloses(): void
    {
        $card = $this->card->fresh();
        $card->agentSignOff($this->admin);
        app(RentalJobCardService::class)->complete($card->fresh(), $this->admin);
    }

    private function queueRow(string $type)
    {
        return app(RentalCommandCentreService::class)->queueItems($this->admin, 'all')->firstWhere('type', $type);
    }

    public function test_closing_while_the_tenant_has_not_answered_completes_the_work_order_and_resolves_the_fault(): void
    {
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT, $this->round->fresh()->outcome);

        $this->agentCloses();

        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $this->workOrder->fresh()->status);
        $fault = $this->fault->fresh();
        $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $fault->status);
        $this->assertSame(RentalFaultReport::OUTCOME_REPAIRED, $fault->outcome);
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT, $this->round->fresh()->outcome, 'the tenant check stays on the record, unanswered');
    }

    public function test_no_holding_stage_and_no_answer_due_date_on_the_work_order_or_the_tenant_card(): void
    {
        $view = app(RentalWorkOrderClientViewService::class);
        $this->assertNotSame('check_requested', $view->stageKey($this->workOrder->fresh()));

        $this->agentCloses();
        $this->assertSame('completed', $view->stageKey($this->workOrder->fresh()));
        $this->assertArrayNotHasKey('answer_due', $view->roundPayload($this->round->fresh(), $this->workOrder->fresh()));
    }

    public function test_the_tenant_may_confirm_after_closing_and_it_is_stored(): void
    {
        $this->agentCloses();

        app(RentalCompletionService::class)->respond($this->round->fresh(), true, 'All good', [], ['contact' => $this->tenant, 'via' => 'portal']);

        $this->assertSame(RentalWorkCompletionRound::OUTCOME_CONFIRMED, $this->round->fresh()->outcome);
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $this->workOrder->fresh()->status);
        $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $this->fault->fresh()->status);
    }

    public function test_a_not_fixed_after_closing_is_stored_raises_a_row_and_reopens_nothing(): void
    {
        $this->agentCloses();

        app(RentalCompletionService::class)->respond($this->round->fresh(), false, 'The tap still drips after the repair', [], ['contact' => $this->tenant, 'via' => 'portal']);

        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $this->workOrder->fresh()->status, 'not reopened by itself');
        $this->assertSame(RentalJobCard::STATUS_COMPLETED, $this->card->fresh()->status);
        $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $this->fault->fresh()->status);
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_DISPUTED, $this->round->fresh()->outcome);
        $this->assertSame('The tap still drips after the repair', $this->round->fresh()->response_note);

        $row = $this->queueRow('work_order_disputed');
        $this->assertNotNull($row);
        $this->assertSame('Tenant says not fixed', $row['label']);
    }

    public function test_a_not_fixed_before_closing_does_not_stop_the_agent_closing(): void
    {
        app(RentalCompletionService::class)->respond($this->round->fresh(), false, 'The tap still drips after the repair', [], ['contact' => $this->tenant, 'via' => 'portal']);

        $this->assertNotSame(RentalWorkOrder::STATUS_DISPUTED, $this->workOrder->fresh()->status);
        $this->assertNotNull($this->queueRow('work_order_disputed'));

        $this->agentCloses();

        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $this->workOrder->fresh()->status);
        $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $this->fault->fresh()->status);
    }

    public function test_seen_no_action_takes_the_row_away_and_reopens_nothing(): void
    {
        $this->agentCloses();
        app(RentalCompletionService::class)->respond($this->round->fresh(), false, 'The tap still drips after the repair', [], ['contact' => $this->tenant, 'via' => 'portal']);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.dispute-seen', $this->workOrder))->assertSessionHasNoErrors();

        $this->assertNull($this->queueRow('work_order_disputed'));
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $this->workOrder->fresh()->status);
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_DISPUTED, $this->round->fresh()->outcome, 'the answer stays on the record');
    }

    public function test_send_back_is_the_agents_act_and_is_what_reopens_a_closed_job(): void
    {
        $this->agentCloses();
        app(RentalCompletionService::class)->respond($this->round->fresh(), false, 'The tap still drips after the repair', [], ['contact' => $this->tenant, 'via' => 'portal']);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.send-back', $this->workOrder))->assertSessionHasNoErrors();

        $this->assertSame(RentalWorkOrder::STATUS_DISPUTED, $this->workOrder->fresh()->status);
        $this->assertSame(RentalJobCard::STATUS_DISPUTED, $this->card->fresh()->status);
        $this->assertNull($this->queueRow('work_order_disputed'), 'the agent has acted - the row leaves');
    }

    public function test_the_silence_command_settles_nothing(): void
    {
        $this->round->forceFill(['window_ends_at' => now()->subDays(30)])->save();

        $this->assertSame(0, app(RentalCompletionService::class)->settleSilent());
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT, $this->round->fresh()->outcome);
        $this->assertNull($this->queueRow('wo_tenant_check'));
    }

    public function test_the_office_panel_has_no_answer_due_wording(): void
    {
        $html = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $this->workOrder))->assertOk()->getContent();

        $this->assertStringNotContainsString('answer due', $html);
        $this->assertStringContainsString('optional', $html);
    }

    public function test_the_repair_migration_resolves_a_fault_stuck_behind_a_completed_work_order_quietly(): void
    {
        $this->workOrder->forceFill(['status' => RentalWorkOrder::STATUS_COMPLETED, 'completed_at' => now()->subDay()])->save();
        $this->assertSame(RentalFaultReport::STATUS_WORK_ORDER_RAISED, $this->fault->fresh()->status);

        (include base_path('database/migrations/2026_10_21_100000_resolve_faults_left_waiting_on_tenant_check.php'))->up();

        $fault = $this->fault->fresh();
        $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $fault->status);
        $this->assertSame(RentalFaultReport::OUTCOME_REPAIRED, $fault->outcome);
        $this->assertTrue((bool) $fault->outcome_set_automatically);
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT, $this->round->fresh()->outcome);
    }
}
