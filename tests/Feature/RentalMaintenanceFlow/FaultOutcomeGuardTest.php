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
 * .ai/specs/rental-work-orders.md §17.10.6 / §17.12 — a fault's "repaired" outcome waits for the tenant check: it cannot be
 * saved while the linked work order is DISPUTED, or while its latest round is still waiting for the tenant. Every other
 * outcome, a fault with no work order, and a settled check are unaffected.
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

    public function test_repaired_is_refused_while_the_tenant_check_is_waiting_and_says_when_it_is_due(): void
    {
        $round = $this->openRound();

        foreach (['repaired', 'repaired_partially'] as $outcome) {
            $this->save($outcome)->assertSessionHasErrors('rental_fault_report');
            $this->assertStringContainsString('Waiting for the tenant to check the finished work', session('errors')->first('rental_fault_report'));
            $this->assertStringContainsString($round->window_ends_at->format('j M Y'), session('errors')->first('rental_fault_report'));
        }

        $this->assertNotSame(RentalFaultReport::STATUS_RESOLVED, $this->fault->fresh()->status);
        $this->assertNull($this->fault->fresh()->outcome);
    }

    public function test_repaired_is_refused_while_the_work_order_is_disputed(): void
    {
        $round = $this->openRound();
        app(RentalCompletionService::class)->respond($round, false, 'Still leaking at the joint', [], ['contact' => $this->tenant, 'via' => 'portal']);

        $this->save('repaired')->assertSessionHasErrors('rental_fault_report');

        $this->assertStringContainsString('not complete', session('errors')->first('rental_fault_report'));
        $this->assertNotSame(RentalFaultReport::STATUS_RESOLVED, $this->fault->fresh()->status);
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

    public function test_repaired_is_allowed_after_silence_is_accepted(): void
    {
        $round = $this->openRound();
        $round->forceFill(['window_ends_at' => now()->subHour()])->save();
        app(RentalCompletionService::class)->settleSilent();

        $this->save('repaired')->assertSessionHasNoErrors();
        $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $this->fault->fresh()->status);
    }

    public function test_repaired_is_allowed_when_no_tenant_check_was_asked_for(): void
    {
        $this->tenant->forceFill(['email' => null])->save();
        $round = $this->openRound();
        // an unreachable tenant still waits (the office records their answer) — only when nobody lives there is nothing to wait for
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT, $round->outcome);
        $this->save('repaired')->assertSessionHasErrors('rental_fault_report');

        $round->forceFill(['outcome' => RentalWorkCompletionRound::OUTCOME_NO_TENANT])->save();
        $this->save('repaired')->assertSessionHasNoErrors();
    }

    public function test_a_fault_with_no_work_order_is_never_held_up(): void
    {
        $plain = $this->faultReport(['title' => 'Never got a work order']);

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.outcome.store', $plain), [
            'outcome' => 'repaired', 'outcome_note' => '', 'repaired_at' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $plain->fresh()->status);
    }

    public function test_the_fault_screen_shows_the_work_orders_stage_and_the_waiting_check(): void
    {
        $this->openRound();

        $html = $this->actingAs($this->admin)->get(route('corex.rental-fault-reports.show', $this->fault))->assertOk()->getContent();

        $this->assertStringContainsString('In progress', $html);
        $this->assertStringContainsString('Tenant check — answer due', $html);
    }
}
