<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\Lease;
use App\Models\RentalFaultReport;
use App\Models\RentalWorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.6.6 / §17.23 defect 3 — the landlord decision endpoints (work order and fault report) used to accept a
 * decision on ANY open record. They now check the record is actually waiting for a decision (`pending`) and refuse otherwise with a 422
 * and a plain message — and still 404 for a record that is not the caller's, archived, or in another agency.
 */
final class LandlordDecisionPendingCheckTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    private const L = '/api/v1/client/rentals/landlord';

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('Pending');
        Sanctum::actingAs($this->clientFor($this->landlord), ['client']);
    }

    private function fault(string $approval = RentalFaultReport::APPROVAL_NOT_REQUIRED): RentalFaultReport
    {
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now()->subDays(10), 'created_by_user_id' => $this->admin->id,
        ]);
        $fault = RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $lease->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED, 'reported_channel' => RentalFaultReport::CHANNEL_APP,
            'title' => 'Roof leak', 'description' => 'Leaking', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => $approval, 'reported_at' => now(),
        ]);
        if ($approval === RentalFaultReport::APPROVAL_PENDING) {
            $fault->forceFill(['owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED])->save();
            $fault->requestApproval($this->admin);
        }

        return $fault->fresh();
    }

    // ── work orders ──────────────────────────────────────────────────

    public function test_a_work_order_waiting_for_the_owner_can_be_decided_once(): void
    {
        $wo = $this->externalWorkOrder(['owner_approval_status' => RentalWorkOrder::APPROVAL_PENDING]);

        $this->postJson(self::L . '/work-orders/' . $wo->id . '/decision', ['decision' => 'approve', 'note' => 'Go ahead'])->assertOk()->assertJsonPath('work_order.owner_approval_status', 'approved');

        $this->postJson(self::L . '/work-orders/' . $wo->id . '/decision', ['decision' => 'decline'])->assertStatus(422)->assertJsonPath('message', 'This is not waiting for your decision any more.');
        $this->assertSame(RentalWorkOrder::APPROVAL_APPROVED, $wo->fresh()->owner_approval_status, 'the second answer changed nothing');
    }

    public function test_a_work_order_that_never_needed_a_decision_refuses_one(): void
    {
        foreach ([RentalWorkOrder::APPROVAL_NOT_REQUIRED, RentalWorkOrder::APPROVAL_APPROVED, RentalWorkOrder::APPROVAL_DECLINED] as $state) {
            $wo = $this->externalWorkOrder(['owner_approval_status' => $state, 'title' => "Job {$state}"]);

            $this->postJson(self::L . '/work-orders/' . $wo->id . '/decision', ['decision' => 'approve'])->assertStatus(422);
            $this->assertSame($state, $wo->fresh()->owner_approval_status);
        }
        $this->assertSame(0, \App\Models\RentalApproval::withoutGlobalScopes()->count(), 'no evidence row was written for a refused decision');
    }

    public function test_an_owner_approval_becomes_the_baseline_and_a_decline_is_recorded_too(): void
    {
        $wo = $this->externalWorkOrder();
        $quote = $wo->recordQuote(['agency_service_provider_id' => $this->supplier()->id, 'amount' => 3000, 'quote_date' => now(), 'detail_text' => 'q'], $this->admin);
        $wo->selectQuote($quote, $this->admin);
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo->fresh()->owner_approval_status);

        $this->postJson(self::L . '/work-orders/' . $wo->id . '/decision', ['decision' => 'approve'])->assertOk();

        $this->assertSame('3000.00', $wo->fresh()->approved_amount);
        $this->assertSame('owner_decision', $wo->fresh()->approval_basis);
        $this->assertDatabaseHas('rental_approval_decisions', ['rental_work_order_id' => $wo->id, 'decision' => 'approved', 'decided_by' => 'owner', 'term_key' => 'owner_decision']);

        $other = $this->externalWorkOrder(['owner_approval_status' => RentalWorkOrder::APPROVAL_PENDING, 'title' => 'Declined job']);
        $this->postJson(self::L . '/work-orders/' . $other->id . '/decision', ['decision' => 'decline'])->assertOk();
        $this->assertDatabaseHas('rental_approval_decisions', ['rental_work_order_id' => $other->id, 'decision' => 'declined', 'decided_by' => 'owner']);
    }

    public function test_a_malformed_decision_is_a_422_not_a_500(): void
    {
        $wo = $this->externalWorkOrder(['owner_approval_status' => RentalWorkOrder::APPROVAL_PENDING]);

        $this->postJson(self::L . '/work-orders/' . $wo->id . '/decision', [])->assertStatus(422);
        $this->postJson(self::L . '/work-orders/' . $wo->id . '/decision', ['decision' => 'maybe'])->assertStatus(422);
        $this->postJson(self::L . '/work-orders/' . $wo->id . '/decision', ['decision' => 'approve', 'note' => str_repeat('x', 2001)])->assertStatus(422);
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo->fresh()->owner_approval_status);
    }

    public function test_an_archived_or_unknown_work_order_is_still_a_404_before_any_status_talk(): void
    {
        $archived = $this->externalWorkOrder(['owner_approval_status' => RentalWorkOrder::APPROVAL_PENDING]);
        $archived->delete();

        $this->postJson(self::L . '/work-orders/' . $archived->id . '/decision', ['decision' => 'approve'])->assertStatus(404);
        $this->postJson(self::L . '/work-orders/99999999/decision', ['decision' => 'approve'])->assertStatus(404);
    }

    // ── fault reports ────────────────────────────────────────────────

    public function test_a_fault_report_waiting_for_the_owner_can_be_decided_once(): void
    {
        $fault = $this->fault(RentalFaultReport::APPROVAL_PENDING);

        $this->postJson(self::L . '/fault-reports/' . $fault->id . '/decision', ['decision' => 'approve_agency_appoints'])->assertOk();
        $this->postJson(self::L . '/fault-reports/' . $fault->id . '/decision', ['decision' => 'decline'])->assertStatus(422)->assertJsonPath('message', 'This is not waiting for your decision any more.');

        $this->assertSame(RentalFaultReport::APPROVAL_APPROVED, $fault->fresh()->owner_approval_status);
    }

    public function test_a_fault_report_that_never_asked_the_owner_refuses_a_decision(): void
    {
        $fault = $this->fault(RentalFaultReport::APPROVAL_NOT_REQUIRED);

        // Fault flow F2 (8 Oct 2026): a fault the agent never sent is invisible to the owner, so the answer is 404.
        $this->postJson(self::L . '/fault-reports/' . $fault->id . '/decision', ['decision' => 'approve_agency_appoints'])->assertStatus(404);

        $this->assertSame(RentalFaultReport::APPROVAL_NOT_REQUIRED, $fault->fresh()->owner_approval_status);
        $this->assertSame(RentalFaultReport::STATUS_REPORTED, $fault->fresh()->status);
    }
}
