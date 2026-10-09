<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\RentalFaultReport;
use App\Models\RentalWorkCompletionRound;
use App\Services\Rentals\RentalCompletionService;
use App\Services\Rentals\RentalWorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsCompletionFlowWorld;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.37 (T1, Johan 9 Oct 2026) — a fault's "repaired" outcome is NEVER held back by the tenant's check
 * (it used to wait for the tenant, §17.10.6): an open check, a "not fixed" answer, an unreachable tenant - none of them stop the agent
 * recording the repair. Every other outcome and a fault with no work order are unaffected.
 */
final class FaultOutcomeGuardTest extends TestCase
{
    use BuildsCompletionFlowWorld;
    use RefreshDatabase;

    private RentalFaultReport $fault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFlowWorld();
        $this->fault = $this->faultReport();
        $workOrder = app(RentalWorkOrderService::class)->fromFaultReport($this->fault, $this->admin, ['title' => 'Geyser', 'description' => 'x']);
        $workOrder->forceFill(['assignment_type' => 'outside_supplier', 'status' => 'in_progress'])->save();
        $this->fault = $this->fault->fresh();
    }

    private function save(string $outcome, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.outcome.store', $this->fault), array_merge([
            'outcome' => $outcome, 'outcome_note' => 'Plumber confirms', 'repaired_at' => now()->toDateString(),
        ], $extra));
    }

    private function openRound(): RentalWorkCompletionRound
    {
        return app(RentalCompletionService::class)->openRound($this->fault->workOrder, ['reported_by_label' => 'Plumber', 'reported_via' => 'office']);
    }

    public function test_repaired_is_allowed_while_the_tenant_check_is_waiting(): void
    {
        $this->openRound();

        foreach (['repaired', 'repaired_partially'] as $outcome) {
            $this->save($outcome)->assertSessionHasNoErrors();
            $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $this->fault->fresh()->status);
            $this->assertSame($outcome, $this->fault->fresh()->outcome);
            $this->fault->fresh()->forceFill(['status' => RentalFaultReport::STATUS_WORK_ORDER_RAISED, 'outcome' => null, 'resolved_at' => null])->save();
        }
    }

    public function test_repaired_is_allowed_while_the_tenant_says_it_is_not_fixed(): void
    {
        $round = $this->openRound();
        app(RentalCompletionService::class)->respond($round, false, 'Still leaking at the joint', [], ['contact' => $this->tenant, 'via' => 'portal']);

        $this->save('repaired')->assertSessionHasNoErrors();

        $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $this->fault->fresh()->status);
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_DISPUTED, $round->fresh()->outcome, 'the tenant\'s answer stays on the record');
    }

    public function test_other_outcomes_are_not_held_up(): void
    {
        $this->openRound();

        $this->save('not_repaired', ['outcome_note' => 'Owner changed their mind'])->assertSessionHasNoErrors();

        $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $this->fault->fresh()->status);
        $this->assertSame('not_repaired', $this->fault->fresh()->outcome);
    }

    public function test_repaired_is_allowed_once_the_tenant_confirms_or_the_window_settles(): void
    {
        $round = $this->openRound();
        app(RentalCompletionService::class)->respond($round, true, null, [], ['contact' => $this->tenant, 'via' => 'portal']);

        $this->save('repaired')->assertSessionHasNoErrors();
        $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $this->fault->fresh()->status);
    }

    public function test_repaired_is_allowed_for_an_unreachable_tenant(): void
    {
        $this->tenant->forceFill(['email' => null])->save();
        $round = $this->openRound();
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT, $round->outcome);

        $this->save('repaired')->assertSessionHasNoErrors();
        $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $this->fault->fresh()->status);
    }

    public function test_a_fault_with_no_work_order_is_never_held_up(): void
    {
        $plain = $this->faultReport(['title' => 'Never got a work order']);

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.outcome.store', $plain), [
            'outcome' => 'repaired', 'outcome_note' => '', 'repaired_at' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $plain->fresh()->status);
    }

    public function test_the_fault_screen_shows_the_work_orders_stage_and_the_optional_check(): void
    {
        $this->openRound();

        $html = $this->actingAs($this->admin)->get(route('corex.rental-fault-reports.show', $this->fault))->assertOk()->getContent();

        $this->assertStringContainsString('Reported finished', $html);
        $this->assertStringContainsString('Tenant asked to check (optional)', $html);
        $this->assertStringNotContainsString('answer due', $html);
    }
}
